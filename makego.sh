#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT/tools/binktermphp-pm"

go mod tidy

build() {
    local os=$1 arch=$2 ext=${3:-}
    local out="$ROOT/dist/${os}-${arch}"
    mkdir -p "$out"
    echo "Building ${os}/${arch}..."
    GOOS=$os GOARCH=$arch go build -o "$out/binktermphp-pm${ext}" ./cmd/pm
    GOOS=$os GOARCH=$arch go build -o "$out/binktermphp-ctl${ext}" ./cmd/ctl
}

build windows amd64 .exe
build linux   amd64
build darwin  amd64
build darwin  arm64

echo
echo "Done. Binaries written to dist/:"
echo "  dist/windows-amd64/  binktermphp-pm.exe  binktermphp-ctl.exe"
echo "  dist/linux-amd64/    binktermphp-pm      binktermphp-ctl"
echo "  dist/darwin-amd64/   binktermphp-pm      binktermphp-ctl"
echo "  dist/darwin-arm64/   binktermphp-pm      binktermphp-ctl"
