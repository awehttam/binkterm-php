#!/usr/bin/env python3
"""
build_index.py - Fetches BinktermPHP documentation, chunks it, embeds it,
and stores it in a sqlite-vec database for RAG-based support queries.

Usage: python3 build_index.py
"""

import re
import struct
import sys

try:
    import requests
except ImportError:
    print("Error: requests not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

try:
    import sqlite3
    import sqlite_vec
except ImportError:
    print("Error: sqlite-vec not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

try:
    from sentence_transformers import SentenceTransformer
except ImportError:
    print("Error: sentence-transformers not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)


GITHUB_RAW_BASE = "https://raw.githubusercontent.com/awehttam/binkterm-php/main"

# Root-level files always included.
ROOT_DOCS = ["README.md", "FAQ.md"]


def discover_docs_urls() -> list[tuple[str, str]]:
    """
    Fetch docs/index.md from GitHub and extract every linked .md file.
    Returns a list of (label, raw_url) pairs, starting with the root docs
    then the docs/ directory files in index order.
    """
    urls: list[tuple[str, str]] = []

    # Root docs first.
    for name in ROOT_DOCS:
        urls.append((name, f"{GITHUB_RAW_BASE}/{name}"))

    # Parse docs/index.md for linked filenames.
    index_url = f"{GITHUB_RAW_BASE}/docs/index.md"
    print(f"  Discovering docs from {index_url}...")
    resp = requests.get(index_url, timeout=30)
    resp.raise_for_status()

    seen: set[str] = set(ROOT_DOCS)
    # Match markdown links: [text](Filename.md) or [text](Filename.md#anchor)
    for m in re.finditer(r'\[.*?\]\(([^)]+\.md)(?:#[^)]*)?\)', resp.text):
        filename = m.group(1)
        if filename in seen:
            continue
        seen.add(filename)
        label = f"docs/{filename}"
        urls.append((label, f"{GITHUB_RAW_BASE}/docs/{filename}"))

    return urls

DB_PATH        = "binkterm_knowledge.db"
MODEL_NAME     = "all-MiniLM-L6-v2"
EMBEDDING_DIM  = 384
CHUNK_TOKENS   = 500
OVERLAP_TOKENS = 100
# Rough approximation: 1 token ≈ 4 characters
CHUNK_CHARS    = CHUNK_TOKENS * 4
OVERLAP_CHARS  = OVERLAP_TOKENS * 4


def fetch_markdown(label: str, url: str) -> str:
    print(f"  Fetching {label}...", flush=True)
    resp = requests.get(url, timeout=30)
    resp.raise_for_status()
    return resp.text


def heading_breadcrumb(heading_stack: dict) -> str:
    """Build a breadcrumb string from the current heading stack."""
    if not heading_stack:
        return ""
    return " > ".join(heading_stack[level] for level in sorted(heading_stack))


def chunk_markdown(text: str, source: str) -> list[dict]:
    """
    Split markdown into overlapping chunks of approximately CHUNK_TOKENS tokens,
    with OVERLAP_TOKENS overlap between consecutive chunks.

    Each chunk records the heading breadcrumb at the position where it starts so
    the embedding carries topical context even when headings are not in the window.
    """
    heading_stack: dict[int, str] = {}
    chunks: list[dict] = []

    # Accumulate lines between heading boundaries, then chunk those blocks.
    block_lines: list[str] = []
    block_heading: str = ""

    def flush_block(lines: list[str], heading_ctx: str) -> None:
        block_text = "\n".join(lines).strip()
        if not block_text:
            return

        i = 0
        while i < len(block_text):
            end = min(i + CHUNK_CHARS, len(block_text))
            # Walk back to a word boundary so we don't cut mid-word.
            if end < len(block_text):
                while end > i and block_text[end] not in " \n\t":
                    end -= 1

            content = block_text[i:end].strip()
            if content:
                full_text = f"[{heading_ctx}]\n\n{content}" if heading_ctx else content
                chunks.append({
                    "source":          source,
                    "heading_context": heading_ctx,
                    "content":         content,
                    "full_text":       full_text,
                })

            if end >= len(block_text):
                break
            i += CHUNK_CHARS - OVERLAP_CHARS

    for line in text.split("\n"):
        m = re.match(r"^(#{1,6})\s+(.+)$", line)
        if m:
            # Flush the accumulated block before moving into this new heading.
            flush_block(block_lines, block_heading)
            block_lines = []

            level = len(m.group(1))
            title = m.group(2).strip()

            # Drop any headings at the same or deeper level from the stack.
            for lvl in [l for l in list(heading_stack) if l >= level]:
                del heading_stack[lvl]
            heading_stack[level] = title
            block_heading = heading_breadcrumb(heading_stack)

            # Include the heading line itself in the next block.
            block_lines.append(line)
        else:
            block_lines.append(line)

    flush_block(block_lines, block_heading)
    return chunks


def pack_vector(vec: list[float]) -> bytes:
    """Pack a float list as little-endian float32 bytes for sqlite-vec."""
    return struct.pack(f"{len(vec)}f", *vec)


def build_index() -> None:
    # ------------------------------------------------------------------
    # 1. Discover and fetch documents
    # ------------------------------------------------------------------
    print("Discovering documentation sources...")
    docs_urls = discover_docs_urls()
    print(f"  Found {len(docs_urls)} documents to index.\n")

    print("Fetching documentation...")
    all_chunks: list[dict] = []

    for label, url in docs_urls:
        try:
            text = fetch_markdown(label, url)
            doc_chunks = chunk_markdown(text, label)
            all_chunks.extend(doc_chunks)
            print(f"    {label}: {len(doc_chunks)} chunks")
        except Exception as exc:
            print(f"  Warning: could not fetch {url}: {exc}", file=sys.stderr)

    if not all_chunks:
        print("Error: no chunks produced — check network access and URLs.", file=sys.stderr)
        sys.exit(1)

    print(f"\nTotal chunks: {len(all_chunks)}")

    # ------------------------------------------------------------------
    # 2. Embed all chunks
    # ------------------------------------------------------------------
    print(f"Loading embedding model ({MODEL_NAME})...")
    model = SentenceTransformer(MODEL_NAME)

    print("Generating embeddings (this may take a minute)...")
    texts      = [c["full_text"] for c in all_chunks]
    embeddings = model.encode(texts, show_progress_bar=True, convert_to_numpy=True)

    # ------------------------------------------------------------------
    # 3. Write SQLite database with sqlite-vec virtual table
    # ------------------------------------------------------------------
    print(f"\nWriting database: {DB_PATH}")

    con = sqlite3.connect(DB_PATH)
    con.enable_load_extension(True)
    sqlite_vec.load(con)
    con.enable_load_extension(False)

    con.executescript(f"""
        DROP TABLE IF EXISTS chunks;
        DROP TABLE IF EXISTS chunks_vec;

        CREATE TABLE chunks (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            source           TEXT    NOT NULL,
            heading_context  TEXT,
            content          TEXT    NOT NULL
        );

        CREATE VIRTUAL TABLE chunks_vec USING vec0(
            embedding FLOAT[{EMBEDDING_DIM}]
        );
    """)

    for chunk, embedding in zip(all_chunks, embeddings):
        cur = con.execute(
            "INSERT INTO chunks (source, heading_context, content) VALUES (?, ?, ?)",
            (chunk["source"], chunk["heading_context"], chunk["content"]),
        )
        row_id = cur.lastrowid
        con.execute(
            "INSERT INTO chunks_vec (rowid, embedding) VALUES (?, ?)",
            (row_id, pack_vector(embedding.tolist())),
        )

    con.commit()
    con.close()

    print(f"\nDone. {DB_PATH} contains {len(all_chunks)} indexed chunks.")
    print("Run bot_query.php (or test with query_embed.py) to query the index.")


if __name__ == "__main__":
    build_index()
