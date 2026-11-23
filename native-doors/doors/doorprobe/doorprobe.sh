#!/bin/bash
# doorprobe - security test door for the native door launch strategies.
#
# Prints one JSON object per line so a test harness can assert on the results
# without parsing a screen. Disabled and admin-only by default.
#
# Checks:
#   identity     uid, gid, supplementary groups, session and process group
#   environment  names of the variables received (never values) and which match a secret pattern
#   denials      files the door must NOT be able to read
#   allowances   places the door must (or must not) be able to write
#   terminal     COLUMNS/LINES and the pty size
#
# A harness can write a mode name into $DOOR_HOME/probe_mode:
#   teardown   ignore SIGHUP/SIGTERM and start a setsid grandchild, to test forceKill()
#   terminal   report the pty size once a second for ten seconds, to test resize

json_escape() {
    local s=${1//\/\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/\n}
    printf '%s' "$s"
}

str() { printf '"%s"' "$(json_escape "$1")"; }

# Exit status to JSON boolean: success means true.
bool() { if [ "$1" -eq 0 ]; then printf 'true'; else printf 'false'; fi; }

# emit <check> <key> <json value> [<key> <json value> ...]
emit() {
    local out
    out="{\"check\":\"$(json_escape "$1")\""
    shift
    while [ $# -ge 2 ]; do
        out+=",\"$(json_escape "$1")\":$2"
        shift 2
    done
    printf '%s}\r\n' "$out"
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="$(cd "$SCRIPT_DIR/../../.." && pwd)"

SECRET_PATTERN='^(DB_|SMTP_|ADMIN_DAEMON_)|SECRET|PASS(WORD|WD)?(_|$)|TOKEN|(^|_)KEY(_|$)|CREDENTIAL|^AWS_'

# identity
emit identity \
    user "$(str "$(id -un 2>/dev/null)")" \
    uid "$(id -u)" \
    gid "$(id -g)" \
    groups "$(str "$(id -G)")" \
    sid "$(str "$(ps -o sid= -p $$ 2>/dev/null | tr -d ' ')")" \
    pgid "$(str "$(ps -o pgid= -p $$ 2>/dev/null | tr -d ' ')")"

# environment: names only, never values
names="$(compgen -e | sort | tr '\n' ' ')"
secret_names=""
for n in $(compgen -e); do
    if [[ "$n" =~ $SECRET_PATTERN ]]; then
        secret_names+="$n "
    fi
done
emit environment names "$(str "$names")" secret_names "$(str "$secret_names")" has_secret "$(bool "$([ -z "$secret_names" ] && echo 1 || echo 0)")"

# expected denials: each "readable" should be false once the door runs as a separate user
for rel in .env config/binkp.json data; do
    [ -r "$BASE/$rel" ]; r=$?
    emit denial path "$(str "$rel")" readable "$(bool $r)"
done
[ -r "/proc/$PPID/environ" ]; r=$?
emit denial path "$(str "/proc/<ppid>/environ")" readable "$(bool $r)"

# expected allowances
if [ -n "$DOOR_HOME" ] && touch "$DOOR_HOME/.doorprobe.$$" 2>/dev/null; then
    rm -f "$DOOR_HOME/.doorprobe.$$"
    home_writable=true
else
    home_writable=false
fi
[ -n "$DOOR_DROPFILE" ] && [ -r "$DOOR_DROPFILE" ]; r=$?
emit allowance what '"DOOR_HOME writable"' ok "$home_writable"
emit allowance what '"DOOR_DROPFILE readable"' ok "$(bool $r)"
[ -w "$BASE/native-doors/drops" ]; r=$?
emit denial path '"native-doors/drops"' writable "$(bool $r)"
[ -w "$BASE/native-doors/doors" ]; r=$?
emit denial path '"native-doors/doors"' writable "$(bool $r)"

# terminal
emit terminal columns "$(str "$COLUMNS")" lines "$(str "$LINES")" stty "$(str "$(stty size 2>/dev/null)")"

mode=""
[ -n "$DOOR_HOME" ] && [ -r "$DOOR_HOME/probe_mode" ] && mode="$(head -n 1 "$DOOR_HOME/probe_mode" | tr -d '\r\n ')"

case "$mode" in
    teardown)
        trap '' HUP TERM
        setsid sleep 600 &
        emit teardown pid $$ grandchild $!
        while true; do sleep 1; done
        ;;
    terminal)
        for _ in 1 2 3 4 5 6 7 8 9 10; do
            emit terminal stty "$(str "$(stty size 2>/dev/null)")"
            sleep 1
        done
        ;;
esac

emit done mode "$(str "$mode")"
