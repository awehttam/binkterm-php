#!/usr/bin/env bash
set -euo pipefail

set_title() {
    printf '\033]0;%s\007' "$1"
}

cleanup() {
    set_title ""
}

trap cleanup EXIT

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

set_title "BinktermPHP - Live Logs"
tail -F "${PROJECT_ROOT}"/data/logs/*.log
