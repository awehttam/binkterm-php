#!/usr/bin/env python3
"""
embed_server.py - Persistent embedding server that loads all-MiniLM-L6-v2 once
and serves embeddings over HTTP, eliminating the ~15-second cold-load penalty
that query_embed.py incurs on every PHP call.

Listens on 127.0.0.1:5001 (loopback only).

Endpoints:
  POST /embed   {"text": "..."}  -> [0.123, -0.456, ...]  (384-dim float array)
  GET  /health                   -> {"status": "ok"}
"""

import json
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

try:
    from fastembed import TextEmbedding
except ImportError:
    print("Error: fastembed not installed. Run: pip install -r requirements.txt", file=sys.stderr)
    sys.exit(1)

HOST = "127.0.0.1"
PORT = 5001

print(f"Loading all-MiniLM-L6-v2...", flush=True)
_model = TextEmbedding("sentence-transformers/all-MiniLM-L6-v2")
print(f"Model ready. Listening on http://{HOST}:{PORT}", flush=True)


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


if __name__ == "__main__":
    server = HTTPServer((HOST, PORT), EmbedHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("Shutting down.", flush=True)
        server.server_close()
