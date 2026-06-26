#!/usr/bin/env python3
"""
query_retrieve.py - Embeds a question and retrieves the top-K most relevant
chunks from binkterm_knowledge.db using sqlite-vec cosine similarity.

Returns a JSON array of chunk objects to stdout.

Usage: python3 query_retrieve.py <question> [top_k] [db_path]
"""

import json
import os
import re
import sqlite3
import struct
import sys
import urllib.request
import urllib.error

try:
    import sqlite_vec
except ImportError:
    print("Error: sqlite_vec not installed. Run: pip install sqlite-vec", file=sys.stderr)
    sys.exit(1)

DEFAULT_DB   = os.path.join(os.path.dirname(__file__), "binkterm_knowledge.db")
MODEL_NAME   = "sentence-transformers/all-MiniLM-L6-v2"
EMBED_SERVER = "http://127.0.0.1:4010"
MIN_CONTENT_CHARS = 80
MIN_CANDIDATES    = 100
VERSION_RE        = re.compile(r"\b\d+\.\d+\.\d+\b")


def pack_vector(vec: list[float]) -> bytes:
    return struct.pack(f"{len(vec)}f", *vec)


def embed_via_server(text: str) -> list | None:
    """Return embedding from the daemon, or None if unreachable."""
    try:
        urllib.request.urlopen(f"{EMBED_SERVER}/health", timeout=1).close()
    except (urllib.error.URLError, OSError):
        return None
    payload = json.dumps({"text": text}).encode("utf-8")
    req = urllib.request.Request(
        f"{EMBED_SERVER}/embed",
        data=payload,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return json.loads(resp.read())
    except (urllib.error.URLError, OSError, json.JSONDecodeError):
        return None


def embed_local(text: str) -> list:
    """Load the same local model used at index-build time and return the embedding."""
    try:
        from fastembed import TextEmbedding
    except ImportError:
        print("Error: fastembed not installed. Run: pip install -r requirements.txt", file=sys.stderr)
        sys.exit(1)
    model = TextEmbedding(MODEL_NAME)
    return next(iter(model.embed([text]))).tolist()


def rerank_chunks(question: str, rows: list[tuple]) -> list[dict]:
    """
    Rerank vector-search candidates so contentful chunks from an exact
    version-matched upgrade document outrank empty section-heading chunks.
    """
    versions = VERSION_RE.findall(question)
    scored_rows: list[tuple[float, tuple]] = []

    for row in rows:
        source, heading_context, content, distance = row
        heading = heading_context or ""
        stripped_content = content.strip()
        score = float(distance)

        if len(stripped_content) < MIN_CONTENT_CHARS:
            score += 0.35
        if "table of contents" in heading.lower():
            score += 0.35
        if "upgrade instructions" in heading.lower():
            score += 0.25
        if "summary of changes >" in heading.lower() and len(stripped_content) >= MIN_CONTENT_CHARS:
            score -= 0.15

        for version in versions:
            if version in source:
                score -= 0.60
            if version in heading:
                score -= 0.20

        scored_rows.append((score, row))

    scored_rows.sort(key=lambda item: (item[0], item[1][3]))

    ranked_chunks = [
        {
            "source":          row[0],
            "heading_context": row[1] or "",
            "content":         row[2],
            "distance":        row[3],
        }
        for _, row in scored_rows
    ]

    def is_exact_version_match(chunk: dict) -> bool:
        return any(
            version in chunk["source"] or version in chunk["heading_context"]
            for version in versions
        )

    def is_substantive(chunk: dict) -> bool:
        heading = chunk["heading_context"].lower()
        return (
            len(chunk["content"].strip()) >= MIN_CONTENT_CHARS
            and "table of contents" not in heading
        )

    def is_summary_detail(chunk: dict) -> bool:
        return "summary of changes >" in chunk["heading_context"].lower()

    exact_version_chunks = [chunk for chunk in ranked_chunks if is_exact_version_match(chunk)]
    other_chunks = [chunk for chunk in ranked_chunks if chunk not in exact_version_chunks]

    ordered_chunks = (
        [chunk for chunk in exact_version_chunks if is_substantive(chunk) and is_summary_detail(chunk)]
        + [chunk for chunk in exact_version_chunks if is_substantive(chunk) and not is_summary_detail(chunk)]
        + [chunk for chunk in exact_version_chunks if not is_substantive(chunk)]
        + [chunk for chunk in other_chunks if is_substantive(chunk) and is_summary_detail(chunk)]
        + [chunk for chunk in other_chunks if is_substantive(chunk) and not is_summary_detail(chunk)]
        + [chunk for chunk in other_chunks if not is_substantive(chunk)]
    )

    return ordered_chunks


if __name__ == "__main__":
    if len(sys.argv) < 2 or not sys.argv[1].strip():
        print("Usage: query_retrieve.py <question> [top_k] [db_path]", file=sys.stderr)
        sys.exit(1)

    question = sys.argv[1]
    top_k    = int(sys.argv[2]) if len(sys.argv) > 2 else 4
    db_path  = sys.argv[3] if len(sys.argv) > 3 else DEFAULT_DB

    if not os.path.exists(db_path):
        print(f"Error: database not found: {db_path}", file=sys.stderr)
        sys.exit(1)

    vec = embed_via_server(question)
    if vec is None:
        print("embed_server not reachable, loading model locally (this takes ~15s)", file=sys.stderr)
        vec = embed_local(question)
    else:
        print("embed_server hit OK", file=sys.stderr)

    blob = pack_vector(vec)

    con = sqlite3.connect(db_path)
    con.enable_load_extension(True)
    sqlite_vec.load(con)
    con.enable_load_extension(False)

    candidate_k = max(top_k * 25, MIN_CANDIDATES)

    rows = con.execute("""
        SELECT c.source,
               c.heading_context,
               c.content,
               v.distance
        FROM   chunks_vec v
        JOIN   chunks c ON c.id = v.rowid
        WHERE  v.embedding MATCH ?
          AND  k = ?
        ORDER  BY v.distance
    """, [blob, candidate_k]).fetchall()

    con.close()

    chunks = rerank_chunks(question, rows)[:top_k]

    print(json.dumps(chunks))
