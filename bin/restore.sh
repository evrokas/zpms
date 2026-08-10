#!/usr/bin/env bash
#
# Fetches a backup generation produced by bin/backup.sh, verifies it loads
# cleanly into a scratch database, and reconstructs a working web/files/
# directory -- either to recover the primary server, or to stand up a
# second server from scratch. Reuses the same /etc/zpms/backup.conf as
# backup.sh to know where generations live.
#
# Usage:
#   bin/restore.sh --list
#   bin/restore.sh <daily|weekly|monthly>/<generation> [--target DIR] [--force]
#
# Example:
#   bin/restore.sh daily/2026-08-08T020000Z
#   bin/restore.sh monthly/2026-07 --target /tmp/restore-test --force
#
# Deliberately does NOT auto-import the fetched dump into the live
# database, even with --force -- overwriting a live production MySQL
# database automatically is a much higher-consequence action than
# restoring SQLite files, so the exact `mysql ... < zpms.sql` command is
# printed for you to run manually against whichever database you choose.
# --force only governs whether existing web/files/ content gets
# overwritten.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

CONF_FILE="${ZPMS_BACKUP_CONF:-/etc/zpms/backup.conf}"
TARGET="$PROJECT_ROOT/web/files"
DB_PHP_CONFIG="$PROJECT_ROOT/config/db.php"
FORCE=0
GENERATION=""

while [ $# -gt 0 ]; do
    case "$1" in
        --list) ACTION="list" ;;
        --target) TARGET="$2"; shift ;;
        --force) FORCE=1 ;;
        --conf) CONF_FILE="$2"; shift ;;
        -h|--help)
            sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) GENERATION="$1" ;;
    esac
    shift
done

if [ ! -f "$CONF_FILE" ]; then
    echo "Config file not found: $CONF_FILE -- copy deploy/backup.conf.example there and edit it first." >&2
    exit 1
fi
# shellcheck source=/dev/null
source "$CONF_FILE"
BACKUP_REMOTE_PATH="${BACKUP_REMOTE_PATH%/}"
BACKUP_SSH_PORT="${BACKUP_SSH_PORT:-22}"
BACKUP_SSH_KEY="${BACKUP_SSH_KEY:-}"
REMOTE="$BACKUP_REMOTE_USER@$BACKUP_REMOTE_HOST"

SSH_BASE=(ssh -p "$BACKUP_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=10)
RSYNC_RSH="ssh -p $BACKUP_SSH_PORT -o BatchMode=yes -o ConnectTimeout=10"
if [ -n "$BACKUP_SSH_KEY" ]; then
    SSH_BASE+=(-i "$BACKUP_SSH_KEY")
    RSYNC_RSH="$RSYNC_RSH -i $BACKUP_SSH_KEY"
fi
zpms_ssh() { "${SSH_BASE[@]}" "$REMOTE" "$@"; }

if [ "${ACTION:-}" = "list" ]; then
    echo "Available generations under $BACKUP_REMOTE_PATH/raw/:"
    for tier in daily weekly monthly; do
        echo "  $tier:"
        zpms_ssh "ls -1 '$BACKUP_REMOTE_PATH/raw/$tier' 2>/dev/null | grep -v '^\.tmp-' | sort" | sed 's/^/    /'
    done
    exit 0
fi

if [ -z "$GENERATION" ]; then
    echo "Usage: bin/restore.sh <daily|weekly|monthly>/<generation> [--target DIR] [--force]" >&2
    echo "       bin/restore.sh --list" >&2
    exit 1
fi

if [ "$FORCE" != "1" ] && [ -d "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null | grep -v -e '^\.backup_stage$' -e '^logs$')" ]; then
    echo "Refusing to overwrite existing data at $TARGET without --force." >&2
    exit 1
fi

echo "Fetching raw/$GENERATION from $REMOTE ..."
FETCH_DIR="$(mktemp -d)"
trap 'rm -rf "$FETCH_DIR"' EXIT
rsync -az -e "$RSYNC_RSH" "$REMOTE:$BACKUP_REMOTE_PATH/raw/$GENERATION/" "$FETCH_DIR/"

if [ ! -f "$FETCH_DIR/zpms.sql.gz" ]; then
    echo "No zpms.sql.gz found in raw/$GENERATION -- is that a valid generation? (see --list)" >&2
    exit 1
fi

echo "Decompressing dump..."
gunzip -k "$FETCH_DIR/zpms.sql.gz"

if [ ! -f "$DB_PHP_CONFIG" ]; then
    echo "$DB_PHP_CONFIG not found -- cannot verify the dump without DB credentials." >&2
    exit 1
fi
zpms_db_config() {
    php -r '
        require "'"$DB_PHP_CONFIG"'";
        echo DB_HOST . "\n" . DB_USER . "\n" . DB_PASS . "\n" . DB_NAME . "\n";
    '
}
{
    IFS= read -r DB_HOST
    IFS= read -r DB_USER
    IFS= read -r DB_PASS
    IFS= read -r DB_NAME
} < <(zpms_db_config)

MYSQL_DEFAULTS_FILE="$(mktemp)"
chmod 600 "$MYSQL_DEFAULTS_FILE"
trap 'rm -f "$MYSQL_DEFAULTS_FILE"; rm -rf "$FETCH_DIR"' EXIT
{
    printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_USER" "$DB_PASS"
} > "$MYSQL_DEFAULTS_FILE"

echo "Verifying the dump loads cleanly into a scratch database..."
SCRATCH_DB="${DB_NAME}_restore_check_$$"
mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "CREATE DATABASE \`$SCRATCH_DB\`"
if ! mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" "$SCRATCH_DB" < "$FETCH_DIR/zpms.sql"; then
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`" 2>/dev/null || true
    echo "Dump did not load cleanly -- refusing to proceed." >&2
    exit 1
fi
mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "DROP DATABASE \`$SCRATCH_DB\`"
echo "Dump loads cleanly."

mkdir -p "$TARGET"
rm -rf "${TARGET:?}"/*
[ -d "$FETCH_DIR/files" ] && cp -a "$FETCH_DIR/files/." "$TARGET/"

# Best-effort ownership fix -- duplicates DocArc's detect_apache_user()
# logic rather than sharing a lib, to keep this script self-contained.
detect_apache_user() {
    if [ -f /etc/apache2/envvars ]; then
        u="$(bash -c '. /etc/apache2/envvars 2>/dev/null; echo "${APACHE_RUN_USER:-}"' 2>/dev/null)"
        [ -n "$u" ] && id "$u" >/dev/null 2>&1 && { echo "$u"; return; }
    fi
    [ -f /etc/httpd/conf/httpd.conf ] && id apache >/dev/null 2>&1 && { echo "apache"; return; }
    u="$(ps -eo user,comm 2>/dev/null | awk '$2 ~ /^(apache2|httpd|php-fpm)/ && $1 != "root" {print $1; exit}')"
    [ -n "$u" ] && { echo "$u"; return; }
    id www-data >/dev/null 2>&1 && { echo "www-data"; return; }
    echo ""
}
APACHE_USER="$(detect_apache_user)"
if [ -n "$APACHE_USER" ] && [ "$(id -u)" = "0" ]; then
    chown -R "$APACHE_USER:$APACHE_USER" "$TARGET"
    echo "Set ownership of $TARGET to $APACHE_USER."
elif [ -n "$APACHE_USER" ]; then
    echo "Not running as root -- remember to: sudo chown -R $APACHE_USER:$APACHE_USER '$TARGET'"
fi

echo
echo "Restore complete: raw/$GENERATION -> $TARGET (web/files/ only)"
echo
echo "The verified dump is at: $FETCH_DIR/zpms.sql (will be removed on exit -- copy it now if you need it)"
echo "cp \"$FETCH_DIR/zpms.sql\" /somewhere/safe/zpms-restore.sql"
echo
echo "Remaining manual steps:"
echo "  1. Copy the dump above somewhere safe before this script exits."
echo "  2. Import it into your chosen target database yourself:"
echo "       mysql --defaults-extra-file=<your credentials file> <target_db> < /somewhere/safe/zpms-restore.sql"
echo "     This is deliberately not automated -- confirm the target database"
echo "     before overwriting it."
echo "  3. Point an Apache vhost at this checkout if standing up a new server."
echo "  4. config/db.php (gitignored, per-install) is not part of this backup"
echo "     -- restore it separately or reconfigure it for the target."
