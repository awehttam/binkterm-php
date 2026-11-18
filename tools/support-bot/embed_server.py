#!/usr/bin/env python3
"""
embed_server.py - Persistent embedding server that loads all-MiniLM-L6-v2 once
and serves embeddings over HTTP, eliminating the ~15-second cold-load penalty
that query_embed.py incurs on every PHP call.

Listens on 127.0.0.1:4010 (loopback only).

Usage:
  embed_server.py [--daemon] [--pid-file PATH] [--log-file PATH]

  --daemon     Detach and run in the background (POSIX only), e.g. from a
               crontab @reboot entry. Output is appended to the log file.
  --pid-file   Default: <repo>/data/run/embed_server.pid
  --log-file   Default: <repo>/data/logs/embed_server.log (used with --daemon)

Endpoints:
  POST /embed   {"text": "..."}  -> [0.123, -0.456, ...]  (384-dim float array)
  GET  /health                   -> {"status": "ok"}
"""

import argparse
import atexit
import json
import os
import signal
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

try:
    from fastembed import TextEmbedding
except ImportError:
    print("Error: fastembed not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

HOST = "127.0.0.1"
PORT = 4010

_REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
DEFAULT_PID_FILE = os.path.join(_REPO_ROOT, "data", "run", "embed_server.pid")
DEFAULT_LOG_FILE = os.path.join(_REPO_ROOT, "data", "logs", "embed_server.log")

_model = None


def _pid_is_running(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def _check_not_running(pid_file: str) -> None:
    """Exit if the PID file points at a live process; remove it if stale."""
    try:
        with open(pid_file, "r", encoding="utf-8") as fh:
            pid = int(fh.read().strip())
    except (OSError, ValueError):
        return
    if _pid_is_running(pid):
        print(f"embed_server already running (PID {pid})", file=sys.stderr)
        sys.exit(1)
    try:
        os.unlink(pid_file)
    except OSError:
        pass


def _daemonize(log_file: str) -> None:
    """Detach from the terminal with the classic double fork."""
    if not hasattr(os, "fork"):
        print("Error: --daemon is not supported on this platform", file=sys.stderr)
        sys.exit(1)

    os.makedirs(os.path.dirname(log_file), exist_ok=True)

    if os.fork() > 0:
        os._exit(0)
    os.setsid()
    if os.fork() > 0:
        os._exit(0)

    os.chdir("/")
    os.umask(0o022)

    sys.stdout.flush()
    sys.stderr.flush()
    devnull = os.open(os.devnull, os.O_RDONLY)
    log_fd = os.open(log_file, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o644)
    os.dup2(devnull, 0)
    os.dup2(log_fd, 1)
    os.dup2(log_fd, 2)
    os.close(devnull)
    os.close(log_fd)


def _write_pid_file(pid_file: str) -> None:
    os.makedirs(os.path.dirname(pid_file), exist_ok=True)
    with open(pid_file, "w", encoding="utf-8") as fh:
        fh.write(str(os.getpid()))

    def _remove() -> None:
        try:
            with open(pid_file, "r", encoding="utf-8") as fh:
                if int(fh.read().strip()) != os.getpid():
                    return
            os.unlink(pid_file)
        except (OSError, ValueError):
            pass

    atexit.register(_remove)


class EmbedHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        print(f"[embed_server] {self.address_string()} {format % args}", file=sys.stderr, flush=True)

    def _send_json(self, status: int, payload: object) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self._send_json(200, {"status": "ok"})
        else:
            self._send_json(404, {"error": "not found"})

    def do_POST(self):
        if self.path != "/embed":
            self._send_json(404, {"error": "not found"})
            return

        length = int(self.headers.get("Content-Length", 0))
        if length == 0:
            self._send_json(400, {"error": "empty body"})
            return

        try:
            body = json.loads(self.rfile.read(length))
        except json.JSONDecodeError:
            self._send_json(400, {"error": "invalid JSON"})
            return

        text = body.get("text", "")
        if not isinstance(text, str) or not text.strip():
            self._send_json(400, {"error": "missing or empty 'text' field"})
            return

        embedding = next(iter(_model.embed([text])))
        self._send_json(200, embedding.tolist())


def main() -> None:
    global _model

    parser = argparse.ArgumentParser(description="Persistent embedding server")
    parser.add_argument("--daemon", action="store_true", help="run in the background")
    parser.add_argument("--pid-file", default=DEFAULT_PID_FILE)
    parser.add_argument("--log-file", default=DEFAULT_LOG_FILE)
    args = parser.parse_args()

    pid_file = os.path.abspath(args.pid_file)
    log_file = os.path.abspath(args.log_file)

    _check_not_running(pid_file)

    if args.daemon:
        _daemonize(log_file)
        _write_pid_file(pid_file)
    elif args.pid_file != DEFAULT_PID_FILE:
        _write_pid_file(pid_file)

    # Turn SIGTERM into a normal shutdown so the PID file is cleaned up.
    signal.signal(signal.SIGTERM, lambda *_: sys.exit(0))

    print("Loading all-MiniLM-L6-v2...", flush=True)
    _model = TextEmbedding("sentence-transformers/all-MiniLM-L6-v2")
    print(f"Model ready. Listening on http://{HOST}:{PORT}", flush=True)

    server = HTTPServer((HOST, PORT), EmbedHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("Shutting down.", flush=True)
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
