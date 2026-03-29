#!/usr/bin/env bash
# ram_usage.sh - Report RAM usage of all BinktermPHP-related processes
# Usage: scripts/ram_usage.sh [--json]

set -euo pipefail

JSON_MODE=false
[[ "${1:-}" == "--json" ]] && JSON_MODE=true

# Collect RSS (resident set size) in KB for all PIDs matching a pattern.
# Prints: <total_kb> <pid_count>
# Uses /proc directly to avoid ps portability issues.
sum_rss_by_pattern() {
    local pattern="$1"
    local total=0
    local count=0

    while IFS= read -r pid; do
        # Read VmRSS from /proc/<pid>/status (in kB)
        local rss
        rss=$(awk '/^VmRSS:/{print $2; exit}' "/proc/${pid}/status" 2>/dev/null || true)
        if [[ -n "$rss" ]]; then
            total=$(( total + rss ))
            (( count++ )) || true
        fi
    done < <(pgrep -f "$pattern" 2>/dev/null || true)

    echo "$total $count"
}

# Collect RSS for all PIDs matching a process name (comm, not full cmdline).
sum_rss_by_name() {
    local name="$1"
    local total=0
    local count=0

    while IFS= read -r pid; do
        local rss
        rss=$(awk '/^VmRSS:/{print $2; exit}' "/proc/${pid}/status" 2>/dev/null || true)
        if [[ -n "$rss" ]]; then
            total=$(( total + rss ))
            (( count++ )) || true
        fi
    done < <(pgrep -x "$name" 2>/dev/null || true)

    echo "$total $count"
}

kb_to_mb() { awk "BEGIN{printf \"%.1f\", $1/1024}"; }

# ─────────────────────────────────────────────
# Service definitions: label | match_type | pattern
# match_type: 'name' uses pgrep -x (exact comm)
#             'cmdline' uses pgrep -f (substring of full cmdline)
# ─────────────────────────────────────────────
declare -a SERVICE_LABELS
declare -a SERVICE_TYPES
declare -a SERVICE_PATTERNS

add_service() {
    SERVICE_LABELS+=("$1")
    SERVICE_TYPES+=("$2")
    SERVICE_PATTERNS+=("$3")
}

# Web servers
add_service "Apache (httpd)"      name    "httpd"
add_service "Apache (apache2)"    name    "apache2"
add_service "Caddy"               name    "caddy"
add_service "php-fpm"             name    "php-fpm"
add_service "php-fpm (versioned)" cmdline "php-fpm:"

# Database
add_service "PostgreSQL (postgres)" name  "postgres"

# BinktermPHP daemons
add_service "admin_daemon"        cmdline "admin_daemon.php"
add_service "binkp_server"        cmdline "binkp_server.php"
add_service "binkp_scheduler"     cmdline "binkp_scheduler.php"
add_service "binkp_poll"          cmdline "binkp_poll.php"
add_service "mrc_daemon"          cmdline "mrc_daemon.php"
add_service "gemini_daemon"       cmdline "gemini_daemon.php"
add_service "ftp_daemon"          cmdline "ftp_daemon.php"
add_service "telnetd"             cmdline "telnet_daemon.php"
add_service "ssh_daemon"          cmdline "ssh_daemon.php"
add_service "multiplexing-server" cmdline "multiplexing-server.js"

GRAND_TOTAL_KB=0

declare -a OUT_LABELS
declare -a OUT_KB
declare -a OUT_COUNTS

for i in "${!SERVICE_LABELS[@]}"; do
    label="${SERVICE_LABELS[$i]}"
    mtype="${SERVICE_TYPES[$i]}"
    pat="${SERVICE_PATTERNS[$i]}"

    if [[ "$mtype" == "name" ]]; then
        read -r kb count <<< "$(sum_rss_by_name "$pat")"
    else
        read -r kb count <<< "$(sum_rss_by_pattern "$pat")"
    fi

    OUT_LABELS+=("$label")
    OUT_KB+=("$kb")
    OUT_COUNTS+=("$count")
    GRAND_TOTAL_KB=$(( GRAND_TOTAL_KB + kb ))
done

# ─────────────────────────────────────────────
# Output
# ─────────────────────────────────────────────

if $JSON_MODE; then
    echo "{"
    echo '  "services": ['
    for i in "${!OUT_LABELS[@]}"; do
        comma=","
        [[ $i -eq $(( ${#OUT_LABELS[@]} - 1 )) ]] && comma=""
        printf '    {"label": "%s", "rss_kb": %d, "rss_mb": %s, "processes": %d}%s\n' \
            "${OUT_LABELS[$i]}" "${OUT_KB[$i]}" "$(kb_to_mb "${OUT_KB[$i]}")" "${OUT_COUNTS[$i]}" "$comma"
    done
    echo "  ],"
    printf '  "total_rss_kb": %d,\n' "$GRAND_TOTAL_KB"
    printf '  "total_rss_mb": %s\n' "$(kb_to_mb "$GRAND_TOTAL_KB")"
    echo "}"
else
    printf "\n| %-30s | %6s | %8s | %8s |\n" "Service" "Procs" "RSS KB" "RSS MB"
    printf "| %-30s | %6s | %8s | %8s |\n" "------------------------------" "------" "--------" "--------"

    for i in "${!OUT_LABELS[@]}"; do
        kb="${OUT_KB[$i]}"
        count="${OUT_COUNTS[$i]}"
        if [[ "$count" -gt 0 ]]; then
            printf "| %-30s | %6d | %8d | %8s |\n" \
                "${OUT_LABELS[$i]}" "$count" "$kb" "$(kb_to_mb "$kb")"
        else
            printf "| %-30s | %6s | %8s | %8s |\n" \
                "${OUT_LABELS[$i]}" "-" "-" "-"
        fi
    done

    printf "| %-30s | %6s | %8d | %8s |\n" "**TOTAL**" "" "$GRAND_TOTAL_KB" "$(kb_to_mb "$GRAND_TOTAL_KB")"
    echo ""
fi
