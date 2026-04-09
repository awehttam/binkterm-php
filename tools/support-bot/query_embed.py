#!/usr/bin/env python3
"""
query_embed.py - Embeds a question using the all-MiniLM-L6-v2 model and
prints the 384-dimensional embedding vector as a JSON array to stdout.

Called by bot_query.php to embed the user's question without requiring a
PHP machine-learning library.

Usage: python3 query_embed.py "your question here"
"""

import json
import sys

try:
    from sentence_transformers import SentenceTransformer
except ImportError:
    print("Error: sentence-transformers not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) < 2 or not sys.argv[1].strip():
        print("Usage: query_embed.py <question>", file=sys.stderr)
        sys.exit(1)

    question  = sys.argv[1]
    model     = SentenceTransformer("all-MiniLM-L6-v2")
    embedding = model.encode(question, convert_to_numpy=True)
    print(json.dumps(embedding.tolist()))
