#!/usr/bin/env python3
"""
build_index.py - Reads BinktermPHP documentation from the local repo, chunks it,
embeds it, and stores it in a sqlite-vec database for RAG-based support queries.

Usage: python3 build_index.py
"""

import re
import struct
import sys
from pathlib import Path

try:
    import sqlite3
    import sqlite_vec
except ImportError:
    print("Error: sqlite-vec not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

try:
    from fastembed import TextEmbedding
except ImportError:
    print("Error: fastembed not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)


# Repo root is two directories above this script (tools/support-bot/ → repo root).
REPO_ROOT = Path(__file__).resolve().parent.parent.parent

# Root-level files always included.
ROOT_DOCS = ["README.md", "FAQ.md"]


def discover_local_docs() -> list[tuple[str, Path]]:
    """
    Read docs/index.md from the local repo and extract every linked .md file.
    Returns a list of (label, path) pairs: root docs first, then docs/ files
    in index order.
    """
    entries: list[tuple[str, Path]] = []

    for name in ROOT_DOCS:
        p = REPO_ROOT / name
        if p.exists():
            entries.append((name, p))
        else:
            print(f"  Warning: {name} not found at {p}", file=sys.stderr)

    index_path = REPO_ROOT / "docs" / "index.md"
    print(f"  Discovering docs from {index_path}...")
    if not index_path.exists():
        print(f"  Warning: docs/index.md not found — skipping docs/ discovery.", file=sys.stderr)
        return entries

    index_text = index_path.read_text(encoding="utf-8")
    seen: set[str] = set(ROOT_DOCS)
    for m in re.finditer(r'\[.*?\]\(([^)]+\.md)(?:#[^)]*)?\)', index_text):
        filename = m.group(1)
        if filename in seen:
            continue
        seen.add(filename)
        p = REPO_ROOT / "docs" / filename
        if p.exists():
            entries.append((f"docs/{filename}", p))
        else:
            print(f"  Warning: docs/{filename} linked in index but not found on disk.", file=sys.stderr)

    return entries

DB_PATH        = "binkterm_knowledge.db"
MODEL_NAME     = "sentence-transformers/all-MiniLM-L6-v2"
EMBEDDING_DIM  = 384
CHUNK_TOKENS   = 500
OVERLAP_TOKENS = 100
# Rough approximation: 1 token ≈ 4 characters
CHUNK_CHARS    = CHUNK_TOKENS * 4
OVERLAP_CHARS  = OVERLAP_TOKENS * 4


def read_markdown(label: str, path: Path) -> str:
    print(f"  Reading {label}...", flush=True)
    return path.read_text(encoding="utf-8")


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
        non_empty_lines = [line.strip() for line in block_text.splitlines() if line.strip()]
        if non_empty_lines and all(re.match(r"^#{1,6}\s+", line) for line in non_empty_lines):
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
    # 1. Discover and read local documents
    # ------------------------------------------------------------------
    print(f"Discovering documentation sources (repo root: {REPO_ROOT})...")
    local_docs = discover_local_docs()
    print(f"  Found {len(local_docs)} documents to index.\n")

    print("Reading documentation...")
    all_chunks: list[dict] = []

    for label, path in local_docs:
        try:
            text = read_markdown(label, path)
            doc_chunks = chunk_markdown(text, label)
            all_chunks.extend(doc_chunks)
            print(f"    {label}: {len(doc_chunks)} chunks")
        except Exception as exc:
            print(f"  Warning: could not read {path}: {exc}", file=sys.stderr)

    if not all_chunks:
        print("Error: no chunks produced — check that docs/ files exist under the repo root.", file=sys.stderr)
        sys.exit(1)

    print(f"\nTotal chunks: {len(all_chunks)}")

    # ------------------------------------------------------------------
    # 2. Embed all chunks
    # ------------------------------------------------------------------
    print(f"Loading embedding model ({MODEL_NAME})...")
    model = TextEmbedding(MODEL_NAME)

    print("Generating embeddings (this may take a minute)...")
    texts      = [c["full_text"] for c in all_chunks]
    embeddings = list(model.embed(texts))

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
