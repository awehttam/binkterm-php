#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
RUN_DIR="${RUN_DIR:-${ROOT_DIR}/data/run}"
ADMIN_PID="${ADMIN_PID:-${RUN_DIR}/admin_daemon.pid}"
SCHEDULER_PID="${SCHEDULER_PID:-${RUN_DIR}/binkp_scheduler.pid}"
SERVER_PID="${SERVER_PID:-${RUN_DIR}/binkp_server.pid}"
TELNETD_PID="${TELNETD_PID:-${RUN_DIR}/telnetd.pid}"
MRC_PID="${MRC_PID:-${RUN_DIR}/mrc_daemon.pid}"

# Track which processes were running before restart
TELNETD_WAS_RUNNING=false
MRC_WAS_RUNNING=false
MULTIPLEX_PID="${MULTIPLEX_PID:-${RUN_DIR}/multiplexing-server.pid}"
GEMINI_PID="${GEMINI_PID:-${RUN_DIR}/gemini_daemon.pid}"

# Track which processes were running before restart
TELNETD_WAS_RUNNING=false
MULTIPLEX_WAS_RUNNING=false
GEMINI_WAS_RUNNING=false

stop_process() {
    local pid_file="$1"
    local name="$2"

    if [[ ! -f "$pid_file" ]]; then
        echo "${name} not running (missing pid file)."
        return 1
    fi

    local pid
    pid="$(cat "$pid_file" 2>/dev/null || true)"
    if [[ -z "$pid" ]]; then
        echo "${name} pid file empty."
        return 1
    fi

    if kill -0 "$pid" 2>/dev/null; then
        echo "Stopping ${name} (pid ${pid})..."
        kill "$pid"
        sleep 1
        if kill -0 "$pid" 2>/dev/null; then
            echo "Force stopping ${name} (pid ${pid})..."
            kill -9 "$pid"
        fi
        return 0
    else
        echo "${name} not running (stale pid ${pid})."
        return 1
    fi
}

start_process() {
    local cmd="$1"
    local name="$2"

    echo "Starting ${name}..."
    (cd "$ROOT_DIR" && nohup ${cmd} > /dev/null 2>&1 &)
}

mkdir -p "$RUN_DIR"

stop_process "$ADMIN_PID" "admin_daemon"
stop_process "$SCHEDULER_PID" "binkp_scheduler"
stop_process "$SERVER_PID" "binkp_server"

# Check if telnetd was running before stopping it
if stop_process "$TELNETD_PID" "telnetd"; then
    TELNETD_WAS_RUNNING=true
fi

# Check if MRC daemon was running before stopping it
if stop_process "$MRC_PID" "mrc_daemon"; then
    MRC_WAS_RUNNING=true
# Check if multiplexing server was running before stopping it
if stop_process "$MULTIPLEX_PID" "multiplexing-server"; then
    MULTIPLEX_WAS_RUNNING=true
fi

# Check if Gemini daemon was running before stopping it
if stop_process "$GEMINI_PID" "gemini_daemon"; then
    GEMINI_WAS_RUNNING=true
fi

start_process "${PHP_BIN} scripts/admin_daemon.php --pid-file=${ADMIN_PID}" "admin_daemon"
start_process "${PHP_BIN} scripts/binkp_scheduler.php --daemon --pid-file=${SCHEDULER_PID}" "binkp_scheduler"
start_process "${PHP_BIN} scripts/binkp_server.php --daemon --pid-file=${SERVER_PID}" "binkp_server"

# Restart telnetd only if it was running
if [[ "$TELNETD_WAS_RUNNING" == "true" ]]; then
    start_process "${PHP_BIN} telnet/telnet_daemon.php --daemon --pid-file=${TELNETD_PID}" "telnetd"
fi

# Restart MRC daemon only if it was running
if [[ "$MRC_WAS_RUNNING" == "true" ]]; then
    start_process "${PHP_BIN} scripts/mrc_daemon.php --daemon --pid-file=${MRC_PID}" "mrc_daemon"
# Restart multiplexing server only if it was running
if [[ "$MULTIPLEX_WAS_RUNNING" == "true" ]]; then
    start_process "${NODE_BIN} scripts/dosbox-bridge/multiplexing-server.js --daemon" "multiplexing-server"
fi

# Restart Gemini daemon only if it was running
if [[ "$GEMINI_WAS_RUNNING" == "true" ]]; then
    start_process "${PHP_BIN} scripts/gemini_daemon.php --daemon --pid-file=${GEMINI_PID}" "gemini_daemon"
fi

echo "Done."
