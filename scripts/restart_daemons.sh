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
SSHD_PID="${SSHD_PID:-${RUN_DIR}/sshd.pid}"
MULTIPLEX_PID="${MULTIPLEX_PID:-${RUN_DIR}/multiplexing-server.pid}"
GEMINI_PID="${GEMINI_PID:-${RUN_DIR}/gemini_daemon.pid}"
MCP_PID="${MCP_PID:-${RUN_DIR}/mcp-server.pid}"
REALTIME_PID="${REALTIME_PID:-${RUN_DIR}/realtime_server.pid}"
FTPD_PID="${FTPD_PID:-${RUN_DIR}/ftpd.pid}"

mkdir -p "$RUN_DIR"

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
    (cd "$ROOT_DIR" && setsid ${cmd} < /dev/null > /dev/null 2>&1 &)
}

stop_service() {
    local svc="$1"
    case "$svc" in
        admin_daemon)        stop_process "$ADMIN_PID"     "admin_daemon"        || true ;;
        binkp_scheduler)     stop_process "$SCHEDULER_PID" "binkp_scheduler"     || true ;;
        binkp_server)        stop_process "$SERVER_PID"    "binkp_server"        || true ;;
        telnetd)             stop_process "$TELNETD_PID"   "telnetd"             || true ;;
        mrc_daemon)          stop_process "$MRC_PID"       "mrc_daemon"          || true ;;
        multiplexing-server) stop_process "$MULTIPLEX_PID" "multiplexing-server" || true ;;
        gemini_daemon)       stop_process "$GEMINI_PID"    "gemini_daemon"       || true ;;
        mcp_server)          stop_process "$MCP_PID"       "mcp_server"          || true ;;
        realtime_daemon|realtime_server)
                             stop_process "$REALTIME_PID"  "realtime_daemon"     || true ;;
        ftp_daemon|ftpd)      stop_process "$FTPD_PID"      "ftp_daemon"          || true ;;
        ssh_daemon|sshd)     stop_process "$SSHD_PID"      "ssh_daemon"          || true ;;
        termserver)
            stop_service telnetd
            stop_service ssh_daemon
            ;;
        *)
            echo "Unknown service: ${svc}"
            echo "Available services: admin_daemon, binkp_scheduler, binkp_server, realtime_daemon, ftp_daemon, telnetd, mrc_daemon, multiplexing-server, gemini_daemon, mcp_server, ssh_daemon, termserver"
            exit 1
            ;;
    esac
}

start_service() {
    local svc="$1"
    case "$svc" in
        admin_daemon)
            start_process "${PHP_BIN} scripts/admin_daemon.php --daemon --pid-file=${ADMIN_PID}" "admin_daemon"
            ;;
        binkp_scheduler)
            start_process "${PHP_BIN} scripts/binkp_scheduler.php --daemon --pid-file=${SCHEDULER_PID}" "binkp_scheduler"
            ;;
        binkp_server)
            start_process "${PHP_BIN} scripts/binkp_server.php --daemon --pid-file=${SERVER_PID}" "binkp_server"
            ;;
        telnetd)
            start_process "${PHP_BIN} telnet/telnet_daemon.php --daemon --pid-file=${TELNETD_PID}" "telnetd"
            ;;
        mrc_daemon)
            start_process "${PHP_BIN} scripts/mrc_daemon.php --daemon --pid-file=${MRC_PID}" "mrc_daemon"
            ;;
        multiplexing-server)
            start_process "${NODE_BIN} scripts/dosbox-bridge/multiplexing-server.js --daemon" "multiplexing-server"
            ;;
        gemini_daemon)
            start_process "${PHP_BIN} scripts/gemini_daemon.php --daemon --pid-file=${GEMINI_PID}" "gemini_daemon"
            ;;
        mcp_server)
            start_process "${NODE_BIN} mcp-server/server.js --pid-file=${MCP_PID}" "mcp_server"
            ;;
        realtime_daemon|realtime_server)
            start_process "${PHP_BIN} scripts/realtime_server.php --daemon --pid-file=${REALTIME_PID}" "realtime_daemon"
            ;;
        ftp_daemon|ftpd)
            start_process "${PHP_BIN} scripts/ftp_daemon.php --daemon --pid-file=${FTPD_PID}" "ftp_daemon"
            ;;
        ssh_daemon|sshd)
            start_process "${PHP_BIN} ssh/ssh_daemon.php --daemon --pid-file=${SSHD_PID}" "ssh_daemon"
            ;;
        termserver)
            start_service telnetd
            start_service ssh_daemon
            ;;
        *)
            echo "Unknown service: ${svc}"
            echo "Available services: admin_daemon, binkp_scheduler, binkp_server, realtime_daemon, ftp_daemon, telnetd, mrc_daemon, multiplexing-server, gemini_daemon, mcp_server, ssh_daemon, termserver"
            exit 1
            ;;
    esac
}

# Restart one service by name.
# Services marked "must_be_running" are only started if they were running before.
restart_service() {
    local svc="$1"
    case "$svc" in
        admin_daemon)
            stop_process "$ADMIN_PID" "admin_daemon" || true
            start_process "${PHP_BIN} scripts/admin_daemon.php --daemon --pid-file=${ADMIN_PID}" "admin_daemon"
            ;;
        binkp_scheduler)
            stop_process "$SCHEDULER_PID" "binkp_scheduler" || true
            start_process "${PHP_BIN} scripts/binkp_scheduler.php --daemon --pid-file=${SCHEDULER_PID}" "binkp_scheduler"
            ;;
        binkp_server)
            stop_process "$SERVER_PID" "binkp_server" || true
            start_process "${PHP_BIN} scripts/binkp_server.php --daemon --pid-file=${SERVER_PID}" "binkp_server"
            ;;
        telnetd)
            if stop_process "$TELNETD_PID" "telnetd"; then
                start_process "${PHP_BIN} telnet/telnet_daemon.php --daemon --pid-file=${TELNETD_PID}" "telnetd"
            fi
            ;;
        mrc_daemon)
            if stop_process "$MRC_PID" "mrc_daemon"; then
                start_process "${PHP_BIN} scripts/mrc_daemon.php --daemon --pid-file=${MRC_PID}" "mrc_daemon"
            fi
            ;;
        multiplexing-server)
            if stop_process "$MULTIPLEX_PID" "multiplexing-server"; then
                start_process "${NODE_BIN} scripts/dosbox-bridge/multiplexing-server.js --daemon" "multiplexing-server"
            fi
            ;;
        gemini_daemon)
            if stop_process "$GEMINI_PID" "gemini_daemon"; then
                start_process "${PHP_BIN} scripts/gemini_daemon.php --daemon --pid-file=${GEMINI_PID}" "gemini_daemon"
            fi
            ;;
        mcp_server)
            if stop_process "$MCP_PID" "mcp_server"; then
                start_process "${NODE_BIN} mcp-server/server.js --pid-file=${MCP_PID}" "mcp_server"
            fi
            ;;
        realtime_daemon|realtime_server)
            stop_process "$REALTIME_PID" "realtime_daemon" || true
            start_process "${PHP_BIN} scripts/realtime_server.php --daemon --pid-file=${REALTIME_PID}" "realtime_daemon"
            ;;
        ftp_daemon|ftpd)
            if stop_process "$FTPD_PID" "ftp_daemon"; then
                start_process "${PHP_BIN} scripts/ftp_daemon.php --daemon --pid-file=${FTPD_PID}" "ftp_daemon"
            fi
            ;;
        ssh_daemon|sshd)
            if stop_process "$SSHD_PID" "ssh_daemon"; then
                start_process "${PHP_BIN} ssh/ssh_daemon.php --daemon --pid-file=${SSHD_PID}" "ssh_daemon"
            fi
            ;;
        termserver)
            restart_service telnetd
            restart_service ssh_daemon
            ;;
        *)
            echo "Unknown service: ${svc}"
            echo "Available services: admin_daemon, binkp_scheduler, binkp_server, realtime_daemon, ftp_daemon, telnetd, mrc_daemon, multiplexing-server, gemini_daemon, mcp_server, ssh_daemon, termserver"
            exit 1
            ;;
    esac
}

if [[ $# -gt 0 && "$1" == "--help" ]]; then
    echo "Usage: restart_daemons.sh [--help] [--list] [--start <service>] [--stop <service>] [service]"
    echo ""
    echo "  (no arguments)    Restart all services"
    echo "  <service>         Restart a single service"
    echo "  --start <service> Start a single service"
    echo "  --stop <service>  Stop a single service without restarting"
    echo "  --list            List available services"
    echo "  --help            Show this help"
    exit 0
elif [[ $# -gt 0 && "$1" == "--list" ]]; then
    echo "admin_daemon        (always restarted)"
    echo "binkp_scheduler     (always restarted)"
    echo "binkp_server        (always restarted)"
    echo "realtime_daemon     (always restarted)"
    echo "ftp_daemon          (only if running)"
    echo "telnetd             (only if running)"
    echo "mrc_daemon          (only if running)"
    echo "multiplexing-server (only if running)"
    echo "gemini_daemon       (only if running)"
    echo "mcp_server          (only if running)"
    echo "ssh_daemon          (only if running)"
    echo "termserver          (alias: restarts/stops telnetd + ssh_daemon)"
    exit 0
elif [[ $# -gt 1 && "$1" == "--start" ]]; then
    start_service "$2"
elif [[ $# -gt 1 && "$1" == "--stop" ]]; then
    stop_service "$2"
elif [[ $# -gt 0 ]]; then
    restart_service "$1"
else
    # Restart all services. Always-on services restart unconditionally;
    # optional services only restart if they were already running.
    restart_service admin_daemon
    restart_service binkp_scheduler
    restart_service binkp_server
    restart_service realtime_daemon
    restart_service ftp_daemon
    restart_service telnetd
    restart_service mrc_daemon
    restart_service multiplexing-server
    restart_service gemini_daemon
    restart_service mcp_server
    restart_service ssh_daemon
fi

echo "Done."
