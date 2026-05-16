#!/bin/bash
# PubTerm - Public Terminal: telnet connection to the BBS
# Launched as a native door via the BinktermPHP multiplexing bridge.
#
# Flags:
#   -E  Disable the escape character (prevents user from breaking to telnet prompt)
#   -K  No automatic login (disables .netrc / TELNET_USER authentication)

TELNET_HOST="${PUBTERM_HOST:-127.0.0.1}"
TELNET_PORT="${PUBTERM_PORT:-2323}"

# Check that telnet is installed
if ! command -v telnet &>/dev/null; then
    printf '\033[1;31m'
    echo ""
    echo "  *** PUBTERM CONFIGURATION ERROR ***"
    echo ""
    printf '\033[0m'
    echo "  The 'telnet' command is not installed on this system."
    echo "  PubTerm requires the telnet client to connect to the BBS."
    echo ""
    echo "  To install it:"
    echo ""
    echo "    Debian/Ubuntu:  sudo apt install telnet"
    echo "    RHEL/Rocky:     sudo dnf install telnet"
    echo "    Alpine:         apk add busybox-extras"
    echo ""
    echo "  If telnet is installed at a non-standard path, set:"
    echo "    PUBTERM_TELNET_BIN=/path/to/telnet  in your .env file"
    echo ""
    echo "  Contact your sysop to resolve this issue."
    echo ""
    echo "  Press any key to exit."
    read -r -n 1 -s
    exit 1
fi

TELNET_BIN="${PUBTERM_TELNET_BIN:-telnet}"

exec "$TELNET_BIN" -E -K "$TELNET_HOST" "$TELNET_PORT"
