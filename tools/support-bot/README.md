# BinktermPHP RAG Support Bot

Answers sysop questions about BinktermPHP by retrieving relevant passages from the
official documentation and passing them as context to Claude Haiku.

## How it works

1. **`build_index.py`** fetches README.md, FAQ.md, and docs/index.md from GitHub,
   splits them into overlapping ~500-token chunks (100-token overlap), embeds each
   chunk with `all-MiniLM-L6-v2` (runs locally, no API key needed), and stores
   everything in `binkterm_knowledge.db` using the sqlite-vec extension.

2. **`bot_query.php`** receives a question, shells out to `query_embed.py` to embed
   it with the same model, performs a KNN cosine-similarity search against the
   database to retrieve the 4 most relevant chunks, injects them into a system
   prompt, and calls the Anthropic API (Claude Haiku) to generate a grounded answer.

3. **`query_embed.py`** is a small helper that wraps the sentence-transformers model
   so PHP doesn't need a native ML library.

## Requirements

- Python 3.10+
- PHP 8.1+ with the `sqlite3` and `curl` extensions enabled
- PHP's SQLite3 extension must allow `loadExtension()` — see note below
- An Anthropic API key

## Setup

```bash
# 1. Install Python dependencies
pip install -r requirements.txt

# 2. Build the knowledge base (downloads ~90 MB model on first run)
python3 build_index.py
# Produces: binkterm_knowledge.db

# 3. Set your Anthropic API key
export ANTHROPIC_API_KEY=sk-ant-...
```

## Usage

**CLI:**
```bash
php bot_query.php "How do I set up echomail with a hub?"
php bot_query.php "What are the requirements for running BinktermPHP?"
php bot_query.php "How do I install DOSBox for door games?"
```

**HTTP POST** (when served by a web server):
```bash
curl -X POST -H 'Content-Type: application/json' \
     -d '{"question":"How do I configure the binkp mailer?"}' \
     https://your-bbs/tools/support-bot/bot_query.php
```

## Rebuilding the index

Re-run `build_index.py` any time the upstream documentation changes. It drops and
recreates the database from scratch on each run.

## Enabling `loadExtension()` in PHP

By default PHP disables SQLite extension loading. To enable it, add the following
to your `php.ini`:

```ini
[sqlite3]
sqlite3.extension_dir = /path/to/sqlite-extensions
```

The sqlite-vec shared library path is located automatically at runtime via:

```bash
python3 -c "import sqlite_vec; print(sqlite_vec.loadable_path())"
```

The directory containing that file is what `sqlite3.extension_dir` should point to.

On some systems you may also need to build PHP with `--with-sqlite3` and ensure
`SQLITE_ENABLE_LOAD_EXTENSION` is set. If this is not feasible on your host,
consider wrapping the entire retrieval step in a second Python helper script.

## Optional: persistent embedding daemon

By default `query_embed.py` loads the model in-process on every call, which takes
roughly 15 seconds on a cold start. Running `embed_server.py` as a background
daemon eliminates this delay: the model is loaded once at startup and subsequent
calls return in milliseconds.

`query_embed.py` detects the daemon automatically — no flags or config required.
If the daemon is unreachable it silently falls back to the in-process path.

### Starting the daemon manually

```bash
python3 embed_server.py &
# Listens on http://127.0.0.1:5001 (loopback only)
```

### Installing as a systemd user service

A ready-made unit file is provided at `embed_server.service`.

1. **Edit the paths** in the unit file to match your setup:
   - `WorkingDirectory` — absolute path to this directory
   - `ExecStart` — absolute path to the Python interpreter in your virtualenv
     (find it with `which python3` after activating the venv, or adjust to use
     the system Python if you installed dependencies globally)

2. **Install and enable:**

   ```bash
   mkdir -p ~/.config/systemd/user
   cp embed_server.service ~/.config/systemd/user/
   systemctl --user daemon-reload
   systemctl --user enable --now embed_server
   ```

3. **Verify:**

   ```bash
   systemctl --user status embed_server
   curl http://127.0.0.1:5001/health   # should return {"status":"ok"}
   ```

The service restarts automatically on failure. It runs as your user account, not
root, so it can access the same virtualenv and model cache that you use
interactively.

## Troubleshooting

**`Error: could not locate the sqlite-vec shared library`**
: Run `pip install sqlite-vec` and verify that
  `python3 -c "import sqlite_vec; print(sqlite_vec.loadable_path())"` prints a path.

**`Error: query_embed.py produced no output`**
: Confirm sentence-transformers is installed: `pip show sentence-transformers`

**`Anthropic API returned HTTP 401`**
: Check that `ANTHROPIC_API_KEY` is exported in the environment PHP runs under.

**Answers seem off-topic or hallucinated**
: Rebuild the index (`python3 build_index.py`) to pick up the latest docs, and
  confirm the question is something the BinktermPHP documentation actually covers.
