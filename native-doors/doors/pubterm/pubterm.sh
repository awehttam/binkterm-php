#!/bin/bash
# PubTerm - Public Terminal: connection to the BinktermPHP terminal server.
# Launched as a native door via the BinktermPHP multiplexing bridge.
#
# Origin IP forwarding:
#   The multiplexing bridge exports DOOR_CLIENT_IP (the real browser-side IP,
#   resolved from X-Forwarded-For). When it is set, PubTerm opens a raw TCP
#   connection to the terminal server and prepends a PROXY protocol v1 header
#   plus a "@BINKTERM" capability hint line, so the terminal server sees the
#   originating address instead of 127.0.0.1. That is what lets new-user
#   screening, rate limiting and audit logs work for browser visitors. The
#   terminal server only honours the header from source addresses listed in
#   TELNET_TRUSTED_PROXIES (loopback by default).
#
#   If DOOR_CLIENT_IP is unset, or /dev/tcp is unavailable in this bash build,
#   PubTerm falls back to the system telnet client with no header.

TELNET_HOST="${PUBTERM_HOST:-127.0.0.1}"
TELNET_PORT="${PUBTERM_PORT:-2323}"
CLIENT_IP="${DOOR_CLIENT_IP:-}"

# Raw connection to the terminal server, prefixed with a PROXY v1 header so the
# server can attribute the session to the real browser-side IP.
raw_proxy_connect() {
    local ip="$1" fam src size rows cols

    case "$ip" in
        *:*) fam="TCP6"; src="::1" ;;
        *)   fam="TCP4"; src="127.0.0.1" ;;
    esac

    # Current PTY dimensions (set by the bridge from the admin terminal_size).
    size="$(stty size 2>/dev/null)"
    rows="${size% *}"
    cols="${size#* }"
    [ -n "$rows" ] && [ "$rows" -gt 0 ] 2>/dev/null || rows=25
    [ -n "$cols" ] && [ "$cols" -gt 0 ] 2>/dev/null || cols=80

    exec 3<>"/dev/tcp/${TELNET_HOST}/${TELNET_PORT}" || return 1

    # PROXY protocol v1 header — must be the very first bytes on the stream.
    printf 'PROXY %s %s %s 0 %s\r\n' "$fam" "$ip" "$src" "$TELNET_PORT" >&3 || { exec 3>&- 3<&-; return 1; }
    # Capability hint: a raw pipe does no TELNET option negotiation, so advertise
    # the browser terminal's type and size here instead.
    printf '@BINKTERM term=%s cols=%s rows=%s\r\n' "${TERM:-xterm-256color}" "$cols" "$rows" >&3

    stty raw -echo 2>/dev/null

    # Full-duplex relay: tear everything down as soon as either direction ends
    # (browser closes stdin, or the terminal server closes the socket).
    cat <&3 &
    local reader=$!
    cat >&3 &
    local writer=$!
    wait -n "$reader" "$writer" 2>/dev/null
    kill "$reader" "$writer" 2>/dev/null
    exec 3>&- 3<&-
    wait 2>/dev/null
    return 0
}

if [ -n "$CLIENT_IP" ]; then
    if raw_proxy_connect "$CLIENT_IP"; then
        exit 0
    fi
    echo "PubTerm: raw connection failed, falling back to telnet client..." >&2
fi

# Fallback: system telnet client (no PROXY header — server sees 127.0.0.1).
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
