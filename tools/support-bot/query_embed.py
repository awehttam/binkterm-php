#!/usr/bin/env python3
"""
query_embed.py - Embeds a question using the all-MiniLM-L6-v2 model and
prints the 384-dimensional embedding vector as a JSON array to stdout.

Called by bot_query.php to embed the user's question without requiring a
PHP machine-learning library.

If embed_server.py is running on 127.0.0.1:5001 the embedding is fetched
from the daemon (no model load time). Otherwise the model is loaded locally,
which takes ~15 seconds on first run.

Usage: python3 query_embed.py "your question here"
"""

import json
import sys
import urllib.request
import urllib.error

EMBED_SERVER = "http://127.0.0.1:5001"


def _embed_via_server(text: str) -> list | None:
    """Return the embedding from the running daemon, or None if unreachable."""
    try:
        req = urllib.request.Request(
            f"{EMBED_SERVER}/health",
            method="GET",
        )
        with urllib.request.urlopen(req, timeout=1) as resp:
            if resp.status != 200:
                return None
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


def _embed_local(text: str) -> list:
    """Load the model in-process and return the embedding."""
    try:
        from sentence_transformers import SentenceTransformer
    except ImportError:
        print("Error: sentence-transformers not installed. Run: pip install -r requirements.txt", file=sys.stderr)
        sys.exit(1)

    model = SentenceTransformer("all-MiniLM-L6-v2")
    return model.encode(text, convert_to_numpy=True).tolist()


if __name__ == "__main__":
    if len(sys.argv) < 2 or not sys.argv[1].strip():
        print("Usage: query_embed.py <question>", file=sys.stderr)
        sys.exit(1)

    question  = sys.argv[1]
    embedding = _embed_via_server(question)
    if embedding is None:
        print("embed_server not reachable, loading model locally (this takes ~15s)", file=sys.stderr)
        embedding = _embed_local(question)
    else:
        print("embed_server hit OK", file=sys.stderr)

    print(json.dumps(embedding))
