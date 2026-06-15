# BinktermPHP RAG Support Tools

These scripts build and query a local retrieval index for BinktermPHP
documentation. The current active pieces are the Python index builder,
embedding helper, retrieval helper, and optional long-running embedding server.

## Current scripts

- `build_index.py`
  Reads documentation from the local BinktermPHP repo, chunks it, embeds it
  with `sentence-transformers/all-MiniLM-L6-v2` via `fastembed`, and rebuilds
  `binkterm_knowledge.db` using `sqlite-vec`.
- `query_retrieve.py`
  Embeds a question, runs a vector search against the SQLite index, reranks the
  candidate chunks, and returns the best matches as JSON.
- `query_embed.py`
  Small helper that returns the raw embedding vector for a single input string.
  It uses the embedding daemon if available and falls back to local model loading
  otherwise.
- `embed_server.py`
  Optional local HTTP service that keeps the embedding model loaded in memory so
  `query_embed.py` and `query_retrieve.py` avoid repeated cold starts.
- `bot_query.php`
  Old - for reference. This legacy script wraps retrieval and then calls the
  Anthropic API to produce an answer, but it is no longer the active path.

## How the index is built

`build_index.py` works from the local repository, not from remote GitHub fetches.

It looks for:

- `README.md`
- `FAQ.md`
- `docs/index.md`

It then parses markdown links from `docs/index.md`, loads each linked `.md`
file under `docs/`, and indexes them in that order.

Chunking behavior:

- Target chunk size is about 500 tokens.
- Overlap between chunks is about 100 tokens.
- Heading breadcrumbs are prepended to each chunk before embedding so retrieval
  keeps section context.
- Heading-only blocks are skipped.

The database is rebuilt from scratch on every run.

## Requirements

- Python 3.10+
- Dependencies from `requirements.txt`

Install them with:

```bash
pip install -r requirements.txt
```

## Build the index

```bash
python3 build_index.py
```

Output:

- `binkterm_knowledge.db`

Re-run this whenever the local documentation changes.

## Query helpers

Embed a single string:

```bash
python3 query_embed.py "How do I configure echomail?"
```

Retrieve the most relevant chunks:

```bash
python3 query_retrieve.py "How do I configure echomail?"
python3 query_retrieve.py "What changed in 2.0.15?" 6
python3 query_retrieve.py "How do I configure the binkp mailer?" 4 ./binkterm_knowledge.db
```

`query_retrieve.py` returns a JSON array with:

- `source`
- `heading_context`
- `content`
- `distance`

Retrieval details:

- Uses the same `all-MiniLM-L6-v2` embedding model as `build_index.py`.
- Searches the vector index for a larger candidate set first, then reranks it.
- Applies extra bias for substantive chunks and exact version matches.
- Penalizes short or table-of-contents-like chunks.

## Optional embedding server

Without the daemon, `query_embed.py` and `query_retrieve.py` may need to load
the embedding model in-process, which can add roughly 15 seconds on a cold start.

`embed_server.py` keeps the model resident and listens on `http://127.0.0.1:5001`.
Both query scripts automatically try the daemon first and fall back to local
embedding if it is unavailable.

Start it manually:

```bash
python3 embed_server.py
```

Available endpoints:

- `GET /health` returns `{"status":"ok"}`
- `POST /embed` with `{"text":"..."}` returns the 384-dimensional embedding array

## systemd user service

`embed_server.service` is included as a template for running the embedding server
persistently.

Before enabling it, update:

- `WorkingDirectory`
- `ExecStart`

Then install it:

```bash
mkdir -p ~/.config/systemd/user
cp embed_server.service ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now embed_server
```

Verify:

```bash
systemctl --user status embed_server
curl http://127.0.0.1:5001/health
```

## Legacy script

`bot_query.php` is kept only as old reference code. It requires PHP, `curl`,
`sqlite3`, and an `ANTHROPIC_API_KEY`, but that script is no longer part of the
current workflow documented here.

## Troubleshooting

**`Error: sqlite-vec not installed`**
Run `pip install -r requirements.txt`.

**`Error: database not found`**
Build the index first with `python3 build_index.py`.

**`embed_server not reachable, loading model locally`**
This is a fallback path, not a hard failure. Start `embed_server.py` if you want
faster repeated queries.

**Retrieval results seem off-topic**
Rebuild the index and confirm the relevant docs exist in the local repo being
indexed.
