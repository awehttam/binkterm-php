#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT/tools/binktermphp-pm"
go mod tidy
go build -o "$ROOT/binktermphp-pm" ./cmd/pm
go build -o "$ROOT/binktermphp-ctl" ./cmd/ctl
echo "Done. binktermphp-pm and binktermphp-ctl written to $ROOT"
