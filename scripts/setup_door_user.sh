#!/usr/bin/env bash
#
# setup_door_user.sh - prepare the operating system to run native doors as
# dedicated unprivileged users (BinktermPHP door launch modes "sudo" and
# "socket"). See docs/NativeDoors.md, "Launch Modes".
#
# Usage:
#   setup_door_user.sh <sudo|systemd|supervisor> --door ID [--user NAME] [options]
#
# Modes:
#   sudo         Install a sudoers rule so the bridge can run `sudo -n -u <user>`.
#   systemd      Create a socket-activated launcher (.socket and .service unit)
#                per door user, for the "socket" launch mode on a systemd host.
#   supervisor   Write one supervisor program per door user, for the "socket"
#                launch mode in Docker or under any supervisord.
#
# Options:
#   --door ID            A door directory under native-doors/doors/. May be repeated.
#                        ID=USER is shorthand for --door ID --user USER.
#   --user NAME          Door user for the most recent --door. Default: door_<id>.
#   --app-user USER      The user the door bridge runs as. Default: owner of .env.
#   --web-user USER      The web server user. Added to each door group so the
#                        admin interface can still read and edit door files.
#   --doors-group NAME   Group whose members may be used as door users. Default: doors.
#   --install-dir DIR    BinktermPHP directory. Default: the directory above scripts/.
#   --socket-dir DIR     Launcher socket directory. Default: /run/binkterm-doors.
#   --node PATH          node binary the launchers run. Default: the one on PATH.
#   --supervisor-dir DIR supervisor mode: where to write the programs.
#                        Default: /etc/supervisor/conf.d/enabled.
#   --dry-run            Print what would be done without changing anything.
#   -h, --help           Show this help.
#
# Safe to run repeatedly. It never deletes users, groups or files.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

MODE=""
DRY_RUN=0
APP_USER=""
WEB_USER=""
DOORS_GROUP="doors"
INSTALL_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SOCKET_DIR="/run/binkterm-doors"
NODE_BIN=""
SUPERVISOR_DIR="/etc/supervisor/conf.d/enabled"

DOOR_IDS=()
DOOR_USERS=()

NAME_RE='^[a-z_][a-z0-9_-]{0,31}$'
DOOR_ID_RE='^[A-Za-z0-9][A-Za-z0-9_-]*$'

log()  { printf '%s\n' "$*"; }
warn() { printf 'WARNING: %s\n' "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
    sed -n '3,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

# Run a command, or only print it under --dry-run.
run() {
    if [ "$DRY_RUN" = 1 ]; then
        printf '+'
        printf ' %q' "$@"
        printf '\n'
        return 0
    fi
    "$@"
}

# write_file PATH MODE  (content on stdin)
write_file() {
    local path="$1" mode="$2" tmp
    if [ "$DRY_RUN" = 1 ]; then
        printf '+ write %s (mode %s):\n' "$path" "$mode"
        sed 's/^/    | /'
        return 0
    fi
    tmp="$(mktemp)"
    cat > "$tmp"
    install -m "$mode" "$tmp" "$path"
    rm -f "$tmp"
}

# Default door user name for a door id: door_<id>, lower case, safe characters.
default_user_for() {
    local id="$1" name
    name="door_$(printf '%s' "$id" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_\n' '_')"
    printf '%s' "${name:0:32}"
}

parse_args() {
    [ $# -gt 0 ] || { usage; exit 1; }

    case "$1" in
        -h|--help) usage; exit 0 ;;
        sudo|systemd|supervisor) MODE="$1"; shift ;;
        *) die "first argument must be sudo, systemd or supervisor (see --help)" ;;
    esac

    while [ $# -gt 0 ]; do
        case "$1" in
            --door)
                [ $# -ge 2 ] || die "--door needs a value"
                local id="${2%%=*}" user=""
                [[ "$2" == *=* ]] && user="${2#*=}"
                DOOR_IDS+=("$id")
                DOOR_USERS+=("$user")
                shift 2 ;;
            --user)
                [ $# -ge 2 ] || die "--user needs a value"
                [ ${#DOOR_IDS[@]} -gt 0 ] || die "--user must follow a --door"
                DOOR_USERS[$((${#DOOR_USERS[@]} - 1))]="$2"
                shift 2 ;;
            --app-user)       APP_USER="${2:?--app-user needs a value}"; shift 2 ;;
            --web-user)       WEB_USER="${2:?--web-user needs a value}"; shift 2 ;;
            --doors-group)    DOORS_GROUP="${2:?--doors-group needs a value}"; shift 2 ;;
            --install-dir)    INSTALL_DIR="$(cd "${2:?--install-dir needs a value}" && pwd)"; shift 2 ;;
            --socket-dir)     SOCKET_DIR="${2:?--socket-dir needs a value}"; shift 2 ;;
            --node)           NODE_BIN="${2:?--node needs a value}"; shift 2 ;;
            --supervisor-dir) SUPERVISOR_DIR="${2:?--supervisor-dir needs a value}"; shift 2 ;;
            --dry-run)        DRY_RUN=1; shift ;;
            -h|--help)        usage; exit 0 ;;
            *) die "unknown option: $1" ;;
        esac
    done

    [ ${#DOOR_IDS[@]} -gt 0 ] || die "give at least one --door"

    local i
    for i in "${!DOOR_IDS[@]}"; do
        [[ "${DOOR_IDS[$i]}" =~ $DOOR_ID_RE ]] || die "invalid door id '${DOOR_IDS[$i]}'"
        [ -n "${DOOR_USERS[$i]}" ] || DOOR_USERS[$i]="$(default_user_for "${DOOR_IDS[$i]}")"
        [[ "${DOOR_USERS[$i]}" =~ $NAME_RE ]] || die "invalid user name '${DOOR_USERS[$i]}' (lower case letters, digits, _ and -; at most 32)"
        [ "${DOOR_USERS[$i]}" != "root" ] || die "refusing to use root as a door user"
    done
    [[ "$DOORS_GROUP" =~ $NAME_RE ]] || die "invalid group name '$DOORS_GROUP'"
}

preflight() {
    if [ "$DRY_RUN" != 1 ]; then
        [ "$(uname -s)" = "Linux" ] || die "door users are only supported on Linux"
        [ "$(id -u)" -eq 0 ] || die "run as root (or use --dry-run to preview)"
    fi

    [ -d "$INSTALL_DIR/native-doors/doors" ] || die "$INSTALL_DIR/native-doors/doors not found; use --install-dir"

    if [ -z "$APP_USER" ] && [ -e "$INSTALL_DIR/.env" ]; then
        APP_USER="$(stat -c %U "$INSTALL_DIR/.env" 2>/dev/null || true)"
    fi
    if [ -z "$APP_USER" ]; then
        if [ "$DRY_RUN" = 1 ]; then
            APP_USER="appuser"
            warn "bridge user unknown; using placeholder '$APP_USER' (pass --app-user)"
        else
            die "cannot tell which user runs the door bridge; pass --app-user"
        fi
    fi
    [ "$APP_USER" != "root" ] || die "the bridge must not run as root; pass --app-user"

    if [ "$DRY_RUN" != 1 ]; then
        id -u "$APP_USER" >/dev/null 2>&1 || die "user '$APP_USER' does not exist"
        [ -z "$WEB_USER" ] || id -u "$WEB_USER" >/dev/null 2>&1 || die "user '$WEB_USER' does not exist"
    fi

    APP_GROUP="$(id -gn "$APP_USER" 2>/dev/null || printf '%s' "$APP_USER")"

    local i
    for i in "${!DOOR_IDS[@]}"; do
        if [ ! -d "$INSTALL_DIR/native-doors/doors/${DOOR_IDS[$i]}" ]; then
            if [ "$DRY_RUN" = 1 ]; then
                warn "door directory native-doors/doors/${DOOR_IDS[$i]} not found"
            else
                die "door directory native-doors/doors/${DOOR_IDS[$i]} not found"
            fi
        fi
        if [ "${DOOR_USERS[$i]}" = "$APP_USER" ]; then
            die "'${DOOR_USERS[$i]}' is the bridge user; a door user must be a different account"
        fi
    done

    if [ "$MODE" != "sudo" ]; then
        if [ -z "$NODE_BIN" ]; then
            NODE_BIN="$(command -v node || true)"
        fi
        if [ -z "$NODE_BIN" ]; then
            if [ "$DRY_RUN" = 1 ]; then
                NODE_BIN="/usr/bin/node"
                warn "node not found; using placeholder $NODE_BIN (pass --node)"
            else
                die "node not found; pass --node"
            fi
        fi
    fi
}

# Unique door user names, in the order given.
unique_users() {
    local u seen=" "
    for u in "${DOOR_USERS[@]}"; do
        case "$seen" in *" $u "*) continue ;; esac
        seen+="$u "
        printf '%s\n' "$u"
    done
}

# Ids of the doors assigned to one user.
doors_of() {
    local user="$1" i
    for i in "${!DOOR_USERS[@]}"; do
        [ "${DOOR_USERS[$i]}" = "$user" ] && printf '%s\n' "${DOOR_IDS[$i]}"
    done
    return 0
}

group_exists() { getent group "$1" >/dev/null 2>&1; }
user_exists()  { id -u "$1" >/dev/null 2>&1; }

nologin_path() {
    command -v nologin 2>/dev/null || { [ -x /usr/sbin/nologin ] && printf '/usr/sbin/nologin'; } || printf '/bin/false'
}

ensure_accounts() {
    log "== Accounts and groups"

    if [ "$DRY_RUN" = 1 ] || ! group_exists "$DOORS_GROUP"; then
        run groupadd --system "$DOORS_GROUP"
    fi

    local user
    while IFS= read -r user; do
        if [ "$DRY_RUN" = 1 ] || ! group_exists "$user"; then
            run groupadd --system "$user"
        fi
        if [ "$DRY_RUN" = 1 ] || ! user_exists "$user"; then
            # No home directory: a door's HOME is its per-user door directory.
            run useradd --system --gid "$user" --groups "$DOORS_GROUP" \
                --no-create-home --home-dir /nonexistent --shell "$(nologin_path)" "$user"
        else
            run usermod --append --groups "$DOORS_GROUP" "$user"
        fi

        # The bridge sets the group of the directories it creates for the door, so it
        # must be a member of every door group. The web user needs the same to keep
        # reading and editing door manifests once the group owns the door directory.
        run usermod --append --groups "$user" "$APP_USER"
        if [ -n "$WEB_USER" ]; then
            run usermod --append --groups "$user" "$WEB_USER"
        fi
    done < <(unique_users)
}

# Make door users unable to read application secrets, while still letting them
# reach their own directories.
apply_layout() {
    log "== Directory permissions"
    local d="$INSTALL_DIR"

    # Remove world access from everything that can hold secrets.
    [ ! -e "$d/.env" ]    || run chmod o-rwx "$d/.env"
    [ ! -d "$d/config" ]  || run chmod -R o-rwx "$d/config"
    # data/users is handled separately below: its directories must stay traversable.
    [ ! -d "$d/data" ]    || run find "$d/data" -path "$d/data/users" -prune -o -exec chmod o-rwx {} +

    # data/, data/users/ and data/users/<id>/ are traverse-only for door users, so they can
    # reach their own data/users/<id>/<door> directory without being able to list or read
    # anything else.
    run mkdir -p "$d/data/users"
    run chown "$APP_USER:$APP_GROUP" "$d/data/users"
    run chmod 771 "$d/data" "$d/data/users"
    run find "$d/data/users" -mindepth 1 -maxdepth 1 -type d -exec chmod 711 {} +

    # native-doors/doors/ is traverse-only; drops/ is readable but not writable by door users.
    run chmod 771 "$d/native-doors/doors"
    [ ! -d "$d/native-doors/drops" ] || run chmod 755 "$d/native-doors/drops"

    local i id user dir
    for i in "${!DOOR_IDS[@]}"; do
        id="${DOOR_IDS[$i]}"
        user="${DOOR_USERS[$i]}"
        dir="$d/native-doors/doors/$id"
        [ -d "$dir" ] || [ "$DRY_RUN" = 1 ] || continue

        # Owned by the bridge user, group-owned by the door's group, setgid and sticky (3770):
        # the door can create files but cannot delete or replace the ones shipped with it.
        run chown -R "$APP_USER:$user" "$dir"
        run chmod -R g+rX,o-rwx "$dir"
        run find "$dir" -type d -exec chmod g+s {} +
        run chmod 3770 "$dir"

        apply_writable_paths "$id" "$user" "$dir"

        # Saves written before the door had its own user belong to the bridge's group. Hand them
        # to the door's group so the door can still read and update them.
        local leaf
        for leaf in "$d"/data/users/*/"$id"; do
            [ -d "$leaf" ] || continue
            run chown -R "$APP_USER:$user" "$leaf"
            run chmod -R g+rwX,o-rwx "$leaf"
            run find "$leaf" -type d -exec chmod g+s {} +
        done
    done
}

# Files a door edits in place are declared in door.writable_paths. Give just those to the door user.
apply_writable_paths() {
    local id="$1" user="$2" dir="$3" rel target real

    [ -f "$dir/nativedoor.json" ] || return 0
    if ! command -v node >/dev/null 2>&1; then
        warn "node not found; skipping writable_paths for $id"
        return 0
    fi

    while IFS= read -r rel; do
        [ -n "$rel" ] || continue
        case "$rel" in
            /*|*..*) warn "$id: ignoring unsafe writable path '$rel'"; continue ;;
        esac
        target="$dir/$rel"
        if [ ! -e "$target" ]; then
            warn "$id: writable path '$rel' does not exist; skipping"
            continue
        fi
        real="$(cd "$(dirname "$target")" && pwd -P)/$(basename "$target")"
        case "$real" in
            "$dir"/*) run chown "$user:$user" "$target" ;;
            *) warn "$id: writable path '$rel' resolves outside the door directory; skipping" ;;
        esac
    done < <(node -e '
        try {
            const m = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
            const list = (m.door && m.door.writable_paths) || [];
            if (Array.isArray(list)) list.forEach(p => typeof p === "string" && console.log(p));
        } catch (e) {}
    ' "$dir/nativedoor.json")
}

install_sudo() {
    log "== sudo rule"
    local file="/etc/sudoers.d/binkterm-doors" tmp
    local content
    content="# Managed by scripts/setup_door_user.sh. Lets the BinktermPHP door bridge run
# native doors as members of the '$DOORS_GROUP' group.
# !use_pty is required: sudo's own pty would break raw output, resize and teardown.
Defaults:$APP_USER !use_pty
$APP_USER ALL=(%$DOORS_GROUP) NOPASSWD: ALL"

    if [ "$DRY_RUN" = 1 ]; then
        printf '+ validate with visudo -c, then install %s (mode 0440):\n' "$file"
        printf '%s\n' "$content" | sed 's/^/    | /'
        return 0
    fi

    tmp="$(mktemp)"
    printf '%s\n' "$content" > "$tmp"
    if ! visudo -c -f "$tmp" >/dev/null; then
        rm -f "$tmp"
        die "the generated sudoers rule failed visudo validation; nothing installed"
    fi
    install -m 0440 "$tmp" "$file"
    rm -f "$tmp"
    log "installed $file"
}

# Directory for each launcher socket: owned by the door user, traversable by the bridge's group
# only, so one door's launcher cannot be reached from another door user.
socket_dirs() {
    log "== Launcher socket directories"
    run mkdir -p "$SOCKET_DIR"
    run chown root:root "$SOCKET_DIR"
    run chmod 711 "$SOCKET_DIR"
    local user
    while IFS= read -r user; do
        run mkdir -p "$SOCKET_DIR/$user"
        run chown "$user:$APP_GROUP" "$SOCKET_DIR/$user"
        run chmod 750 "$SOCKET_DIR/$user"
    done < <(unique_users)
}

install_supervisor() {
    socket_dirs
    log "== supervisor programs"
    run mkdir -p "$SUPERVISOR_DIR"
    local user
    while IFS= read -r user; do
        # env -i: supervisor children inherit its environment, which in Docker holds the
        # compose-injected secrets, and a door running as the same uid can read the
        # launcher's /proc/<pid>/environ.
        write_file "$SUPERVISOR_DIR/door_launcher_$user.conf" 644 <<EOF
[program:door_launcher_$user]
command=/usr/bin/env -i PATH=/usr/local/bin:/usr/bin:/bin NATIVEDOOR_LAUNCHER_SOCKET=$SOCKET_DIR/$user/launcher.sock NATIVEDOOR_LAUNCHER_EXEC_ROOTS=$INSTALL_DIR/native-doors/doors $NODE_BIN $INSTALL_DIR/scripts/dosbox-bridge/door-launcher.js
user=$user
autostart=true
autorestart=true
stdout_logfile=$INSTALL_DIR/data/logs/door_launcher_$user.log
stdout_logfile_maxbytes=10485760
stderr_logfile=$INSTALL_DIR/data/logs/door_launcher_${user}_error.log
stderr_logfile_maxbytes=10485760
EOF
    done < <(unique_users)
    log "Run 'supervisorctl update' (or restart the container) to start the launchers."
}

install_systemd() {
    log "== systemd units"
    run mkdir -p "$SOCKET_DIR"

    # Socket directories are recreated at every boot (/run is a tmpfs).
    {
        printf 'd %s 0711 root root -\n' "$SOCKET_DIR"
        local u
        while IFS= read -r u; do
            printf 'd %s/%s 0750 %s %s -\n' "$SOCKET_DIR" "$u" "$u" "$APP_GROUP"
        done < <(unique_users)
    } | write_file /etc/tmpfiles.d/binkterm-doors.conf 644
    run systemd-tmpfiles --create /etc/tmpfiles.d/binkterm-doors.conf

    local user id rw
    while IFS= read -r user; do
        rw="-$INSTALL_DIR/data/users"
        while IFS= read -r id; do
            rw+=" -$INSTALL_DIR/native-doors/doors/$id"
        done < <(doors_of "$user")

        write_file "/etc/systemd/system/binkterm-door-$user.socket" 644 <<EOF
[Unit]
Description=BinktermPHP door launcher socket ($user)

[Socket]
ListenStream=$SOCKET_DIR/$user/launcher.sock
SocketUser=$user
SocketGroup=$user
SocketMode=0660

[Install]
WantedBy=sockets.target
EOF

        write_file "/etc/systemd/system/binkterm-door-$user.service" 644 <<EOF
[Unit]
Description=BinktermPHP door launcher ($user)

[Service]
User=$user
Group=$user
Environment=NATIVEDOOR_LAUNCHER_EXEC_ROOTS=$INSTALL_DIR/native-doors/doors
ExecStart=$NODE_BIN $INSTALL_DIR/scripts/dosbox-bridge/door-launcher.js
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=read-only
ReadWritePaths=$rw
InaccessiblePaths=-$INSTALL_DIR/.env -$INSTALL_DIR/config
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
TasksMax=512
EOF
    done < <(unique_users)

    run systemctl daemon-reload
    while IFS= read -r user; do
        run systemctl enable "binkterm-door-$user.socket"
        run systemctl restart "binkterm-door-$user.socket"
    done < <(unique_users)
}

# Confirm each door user cannot read application secrets. Fail loudly: the whole
# point of a separate user is lost if it can.
negative_probes() {
    log "== Checking that door users cannot read application secrets"
    if [ "$DRY_RUN" = 1 ]; then
        log "(skipped under --dry-run)"
        return 0
    fi

    local failed=0 user secret
    while IFS= read -r user; do
        for secret in "$INSTALL_DIR/.env" "$INSTALL_DIR/config/binkp.json" "$INSTALL_DIR/data"; do
            [ -e "$secret" ] || continue
            if runuser -u "$user" -- test -r "$secret" 2>/dev/null; then
                warn "door user '$user' can still read $secret"
                failed=1
            fi
        done
        if [ "$MODE" != "sudo" ] && ! runuser -u "$user" -- test -x "$NODE_BIN" 2>/dev/null; then
            warn "door user '$user' cannot execute $NODE_BIN; pass --node with a path every user can run"
            failed=1
        fi
    done < <(unique_users)

    [ "$failed" -eq 0 ] || die "permission checks failed; see the warnings above"
    log "ok"
}

summary() {
    log ""
    log "Done."
    log "Next: in Admin > Native Doors, edit each door and set its Launch Mode."
    case "$MODE" in
        sudo) log "  Choose 'Sudo' and the door user (members of '$DOORS_GROUP')." ;;
        *)    log "  Choose 'Socket' and the launcher socket (named after the door user)." ;;
    esac
    local i
    for i in "${!DOOR_IDS[@]}"; do
        log "  ${DOOR_IDS[$i]}  ->  ${DOOR_USERS[$i]}"
    done
    log "Then enable the Door Probe door and check its output in that mode."
    log "The door bridge may need a restart (or SIGHUP) to pick up the new group memberships."
}

parse_args "$@"
preflight
ensure_accounts
apply_layout
case "$MODE" in
    sudo)       install_sudo ;;
    systemd)    socket_dirs; install_systemd ;;
    supervisor) install_supervisor ;;
esac
negative_probes
summary
