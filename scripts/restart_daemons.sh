#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
RUN_DIR="${RUN_DIR:-${ROOT_DIR}/data/run}"
ADMIN_PID="${ADMIN_PID:-${RUN_DIR}/admin_daemon.pid}"
SCHEDULER_PID="${SCHEDULER_PID:-${RUN_DIR}/binkp_scheduler.pid}"
SERVER_PID="${SERVER_PID:-${RUN_DIR}/binkp_server.pid}"

stop_process() {
    local pid_file="$1"
    local name="$2"

    if [[ ! -f "$pid_file" ]]; then
        echo "${name} not running (missing pid file)."
        return
    fi

    local pid
    pid="$(cat "$pid_file" 2>/dev/null || true)"
    if [[ -z "$pid" ]]; then
        echo "${name} pid file empty."
        return
    fi

    if kill -0 "$pid" 2>/dev/null; then
        echo "Stopping ${name} (pid ${pid})..."
        kill "$pid"
        sleep 1
        if kill -0 "$pid" 2>/dev/null; then
            echo "Force stopping ${name} (pid ${pid})..."
            kill -9 "$pid"
        fi
    else
        echo "${name} not running (stale pid ${pid})."
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

start_process "${PHP_BIN} scripts/admin_daemon.php --pid-file=${ADMIN_PID}" "admin_daemon"
start_process "${PHP_BIN} scripts/binkp_scheduler.php --daemon --pid-file=${SCHEDULER_PID}" "binkp_scheduler"
start_process "${PHP_BIN} scripts/binkp_server.php --daemon --pid-file=${SERVER_PID}" "binkp_server"

echo "Done."
