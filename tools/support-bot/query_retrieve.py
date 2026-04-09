#!/usr/bin/env python3
"""
query_retrieve.py - Embeds a question and retrieves the top-K most relevant
chunks from binkterm_knowledge.db using sqlite-vec cosine similarity.

Returns a JSON array of chunk objects to stdout.

Usage: python3 query_retrieve.py <question> [top_k] [db_path]
"""

import json
import os
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
MODEL_NAME   = "all-MiniLM-L6-v2"
EMBED_SERVER = "http://127.0.0.1:5001"


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
    """Load the model in-process and return the embedding."""
    try:
        from sentence_transformers import SentenceTransformer
    except ImportError:
        print("Error: sentence-transformers not installed. Run: pip install -r requirements.txt", file=sys.stderr)
        sys.exit(1)
    model = SentenceTransformer(MODEL_NAME)
    return model.encode(text, convert_to_numpy=True).tolist()


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
    """, [blob, top_k]).fetchall()

    con.close()

    chunks = [
        {
            "source":          row[0],
            "heading_context": row[1] or "",
            "content":         row[2],
            "distance":        row[3],
        }
        for row in rows
    ]

    print(json.dumps(chunks))
