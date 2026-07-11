#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

OS=$(uname -s | tr '[:upper:]' '[:lower:]')
ARCH=$(uname -m)
case "$ARCH" in
    x86_64)          ARCH=amd64 ;;
    arm64|aarch64)   ARCH=arm64 ;;
    *) echo "binktermphp-ctl: unsupported architecture: $ARCH" >&2; exit 1 ;;
esac

BIN="$ROOT/dist/${OS}-${ARCH}/binktermphp-ctl"
if [ ! -x "$BIN" ]; then
    echo "binktermphp-ctl: binary not found: $BIN" >&2
    echo "Run makego.sh to build binaries first." >&2
    exit 1
fi

exec "$BIN" "$@"
