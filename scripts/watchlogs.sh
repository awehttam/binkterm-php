#!/usr/bin/env bash
set -euo pipefail

set_title() {
    printf '\033]0;%s\007' "$1"
}

cleanup() {
    set_title ""
}

trap cleanup EXIT

set_title "BinktermPHP - Live Logs"
tail -F data/logs/*.log
