#!/usr/bin/env bash
#
# Nightly backup orchestrator: takes a consistent MySQL dump (--single-
# transaction, so InnoDB doesn't need locking for a consistent snapshot),
# hardlinks web/files/ alongside it, and ships both off-site via rsync+SSH
# using rotating --link-dest generations (daily, promoted into
# weekly/monthly). Modeled directly on DocArc's bin/backup.sh -- see
# README.md's "Backups" section for the full design rationale, including
# the disclosed GDPR-erasure-propagation bound (an old backup generation
# can outlive a deleted record for a while -- see README for the concrete
# number with your configured retention).
#
# Usage: bin/backup.sh
# Config: /etc/zpms/backup.conf (see deploy/backup.conf.example), or set
#         ZPMS_BACKUP_CONF to point elsewhere.
#
# Deliberately set -euo pipefail -- this is a sequential pipeline where a
# partial/corrupt backup is worse than no backup, so it must abort
# immediately on the first real failure.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# ZPMS has no data/ equivalent -- patient data lives in MySQL, not files --
# so these anchor to web/files/, the app's existing per-install library
# root (already gitignored, auto-created on demand by the app itself).
FILES_DIR="$PROJECT_ROOT/web/files"
STAGE_DIR="$FILES_DIR/.backup_stage"
STAGE_RAW="$STAGE_DIR/raw"
BACKUP_LOG="$FILES_DIR/logs/backup.log"
STATUS_FILE="$FILES_DIR/logs/backup_status.json"
GENERATIONS_FILE="$FILES_DIR/logs/backup_generations.json"
DB_PHP_CONFIG="$PROJECT_ROOT/config/db.php"

# Overridable purely for deterministic tests, never in production use.
TS="${ZPMS_BACKUP_NOW:-$(date -u +%Y-%m-%dT%H%M%SZ)}"

zpms_backup_log() {
    mkdir -p "$(dirname "$BACKUP_LOG")"
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" | tee -a "$BACKUP_LOG"
}

zpms_write_status() {
    mkdir -p "$(dirname "$STATUS_FILE")"
    printf '{"last_run_ts":"%s","status":"%s"}\n' "$TS" "$1" > "$STATUS_FILE"
}

zpms_backup_fatal() {
    zpms_backup_log "FATAL: $1"
    zpms_write_status "failed"
    exit 1
}

# --- Load config ------------------------------------------------------
CONF_FILE="${ZPMS_BACKUP_CONF:-/etc/zpms/backup.conf}"
if [ ! -f "$CONF_FILE" ]; then
    zpms_backup_fatal "Config file not found: $CONF_FILE -- copy deploy/backup.conf.example there and edit it first."
fi
# shellcheck source=/dev/null
source "$CONF_FILE"

for var in BACKUP_REMOTE_USER BACKUP_REMOTE_HOST BACKUP_REMOTE_PATH; do
    if [ -z "${!var:-}" ]; then
        zpms_backup_fatal "$var is not set in $CONF_FILE"
    fi
done
BACKUP_REMOTE_PATH="${BACKUP_REMOTE_PATH%/}"
BACKUP_SSH_PORT="${BACKUP_SSH_PORT:-22}"
BACKUP_SSH_KEY="${BACKUP_SSH_KEY:-}"
BACKUP_KEEP_DAILY="${BACKUP_KEEP_DAILY:-14}"
BACKUP_KEEP_WEEKLY="${BACKUP_KEEP_WEEKLY:-8}"
BACKUP_KEEP_MONTHLY="${BACKUP_KEEP_MONTHLY:-12}"
# Off by default -- loads the dump into a throwaway scratch DB and runs
# CHECK TABLE on every table, a much stronger but much more expensive
# guarantee than the cheap file-level checks below. A --single-transaction
# dump of a live, healthy InnoDB server essentially can't itself be
# structurally corrupt the way a raw file-level copy of a different DB
# engine theoretically could, so the default leans on the cheap checks.
BACKUP_VERIFY_LOAD="${BACKUP_VERIFY_LOAD:-false}"

REMOTE="$BACKUP_REMOTE_USER@$BACKUP_REMOTE_HOST"
SSH_BASE=(ssh -p "$BACKUP_SSH_PORT" -o BatchMode=yes -o ConnectTimeout=10)
RSYNC_RSH="ssh -p $BACKUP_SSH_PORT -o BatchMode=yes -o ConnectTimeout=10"
if [ -n "$BACKUP_SSH_KEY" ]; then
    SSH_BASE+=(-i "$BACKUP_SSH_KEY")
    RSYNC_RSH="$RSYNC_RSH -i $BACKUP_SSH_KEY"
fi

zpms_ssh() {
    "${SSH_BASE[@]}" "$REMOTE" "$@"
}

# --- Preflight ----------------------------------------------------------
zpms_backup_log "Starting backup run $TS"

for bin in mysqldump mysql rsync ssh php; do
    command -v "$bin" >/dev/null 2>&1 || zpms_backup_fatal "$bin not found on PATH"
done

[ -f "$DB_PHP_CONFIG" ] || zpms_backup_fatal "$DB_PHP_CONFIG not found -- app not configured yet."

zpms_ssh true || zpms_backup_fatal "Could not reach $REMOTE via SSH -- check BACKUP_REMOTE_HOST/USER/SSH_KEY/PORT in $CONF_FILE"

# --- Stage (same filesystem as web/files/, so hardlinking is instant) ---
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_RAW/files"

# --- Credentials: read from config/db.php, hand to mysql tools via a ----
# --- mode-600 --defaults-extra-file, never on the command line or in an -
# --- env var -- see README.md's "Backups" section for why (avoids both  -
# --- ps/shell-history exposure and the /proc/<pid>/environ exposure     -
# --- class that MYSQL_PWD carries).                                     -
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
# Always clean up staging and the credentials file, success or failure --
# staging is transient by design, and the credentials file must never
# outlive this run.
trap 'rm -f "$MYSQL_DEFAULTS_FILE"; rm -rf "$STAGE_DIR"' EXIT
{
    printf '[client]\n'
    printf 'host=%s\n' "$DB_HOST"
    printf 'user=%s\n' "$DB_USER"
    printf 'password=%s\n' "$DB_PASS"
} > "$MYSQL_DEFAULTS_FILE"

# --- Hot DB backup + verification ----------------------------------------
# --single-transaction gives an InnoDB-consistent, non-locking snapshot in
# one step -- this dump *is* the entire DB backup (unlike DocArc's SQLite
# setup, which separately verifies a raw binary copy before optionally
# also producing a portable dump; MySQL has no direct binary-snapshot
# equivalent, and a live, constraint-enforcing DB can't itself contain FK
# violations the way a raw file copy of a different engine theoretically
# could, so one mysqldump step covers what DocArc needed two for).
zpms_backup_log "Dumping $DB_NAME"
mysqldump --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
    --single-transaction --routines --triggers --events \
    --hex-blob --add-drop-table \
    "$DB_NAME" > "$STAGE_RAW/zpms.sql"

zpms_backup_log "Verifying dump"
[ -s "$STAGE_RAW/zpms.sql" ] || zpms_backup_fatal "Dump file is empty"
tail -c 64 "$STAGE_RAW/zpms.sql" | grep -q "Dump completed on" \
    || zpms_backup_fatal "Dump file missing trailer -- looks truncated"

if [ "$BACKUP_VERIFY_LOAD" = "true" ]; then
    zpms_backup_log "Verifying dump loads cleanly (BACKUP_VERIFY_LOAD=true)"
    SCRATCH_DB="zpms_backup_verify_$$"
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "CREATE DATABASE \`$SCRATCH_DB\`"
    if ! mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" "$SCRATCH_DB" < "$STAGE_RAW/zpms.sql"; then
        mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "DROP DATABASE IF EXISTS \`$SCRATCH_DB\`" 2>/dev/null || true
        zpms_backup_fatal "Dump did not load cleanly into scratch DB $SCRATCH_DB"
    fi
    CHECK_OUTPUT="$(mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -N -e \
        "SELECT table_name FROM information_schema.tables WHERE table_schema='$SCRATCH_DB'" \
        | while read -r t; do mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" "$SCRATCH_DB" -e "CHECK TABLE \`$t\`" 2>&1; done \
        | grep -v -e '	OK$' -e '^Table	Op	Msg_type	Msg_text$' || true)"
    mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" -e "DROP DATABASE \`$SCRATCH_DB\`"
    [ -z "$CHECK_OUTPUT" ] || zpms_backup_fatal "CHECK TABLE found issues: $CHECK_OUTPUT"
fi

gzip "$STAGE_RAW/zpms.sql"
zpms_backup_log "Dump verified and compressed"

# --- Files (hardlink -- instant, zero extra disk) ------------------------
if [ -d "$FILES_DIR" ]; then
    for entry in "$FILES_DIR"/*; do
        [ -e "$entry" ] || continue
        base="$(basename "$entry")"
        case "$base" in .backup_stage|logs) continue ;; esac
        cp -al "$entry" "$STAGE_RAW/files/$base" 2>/dev/null \
            || cp -a "$entry" "$STAGE_RAW/files/$base"
    done
fi

# --- Ship + rotate --------------------------------------------------------
zpms_backup_latest_daily() {
    zpms_ssh "ls -1 '$BACKUP_REMOTE_PATH/raw/daily' 2>/dev/null | grep -v '^\.tmp-' | sort | tail -n1" || true
}

zpms_backup_ship() {
    local latest link_dest=()

    zpms_ssh "mkdir -p '$BACKUP_REMOTE_PATH/raw/daily' '$BACKUP_REMOTE_PATH/raw/weekly' '$BACKUP_REMOTE_PATH/raw/monthly'"

    latest="$(zpms_backup_latest_daily)"
    if [ -n "$latest" ]; then
        link_dest=(--link-dest="$BACKUP_REMOTE_PATH/raw/daily/$latest")
    fi

    zpms_backup_log "Shipping raw/$TS (link-dest: ${latest:-none})"
    rsync -az --delete "${link_dest[@]}" --stats -e "$RSYNC_RSH" \
        "$STAGE_RAW/" "$REMOTE:$BACKUP_REMOTE_PATH/raw/daily/.tmp-$TS/" \
        >> "$BACKUP_LOG" 2>&1

    zpms_ssh "mv '$BACKUP_REMOTE_PATH/raw/daily/.tmp-$TS' '$BACKUP_REMOTE_PATH/raw/daily/$TS'"
    zpms_backup_log "Published raw/daily/$TS"
}

zpms_backup_promote_and_prune() {
    local week month
    week="$(date -u -d "${TS:0:10} ${TS:11:2}:${TS:13:2}:${TS:15:2}" +%G-W%V)"
    month="${TS:0:7}"

    zpms_ssh bash -s -- "$BACKUP_REMOTE_PATH/raw" "$TS" "$week" "$month" \
        "$BACKUP_KEEP_DAILY" "$BACKUP_KEEP_WEEKLY" "$BACKUP_KEEP_MONTHLY" <<'REMOTE_SCRIPT'
set -euo pipefail
base="$1"; ts="$2"; week="$3"; month="$4"
keep_daily="$5"; keep_weekly="$6"; keep_monthly="$7"

# Existence-based promotion, not day-of-week/day-of-month-based: promoting
# only when this week's/month's bucket doesn't exist yet survives a missed
# cron night without leaving a permanent gap in that tier.
[ -d "$base/weekly/$week" ] || cp -al "$base/daily/$ts" "$base/weekly/$week"
[ -d "$base/monthly/$month" ] || cp -al "$base/daily/$ts" "$base/monthly/$month"

prune() {
    local dir="$1" keep="$2" old
    ls -1 "$dir" 2>/dev/null | grep -v '^\.tmp-' | sort | head -n -"$keep" 2>/dev/null | while read -r old; do
        [ -n "$old" ] && rm -rf "${dir:?}/${old:?}"
    done
}
prune "$base/daily" "$keep_daily"
prune "$base/weekly" "$keep_weekly"
prune "$base/monthly" "$keep_monthly"
REMOTE_SCRIPT
    zpms_backup_log "Promoted/pruned raw (week $week, month $month; keeping ${BACKUP_KEEP_DAILY}d/${BACKUP_KEEP_WEEKLY}w/${BACKUP_KEEP_MONTHLY}m)"
}

zpms_backup_ship
zpms_backup_promote_and_prune

# --- Manifest of available generations, for the web backup-status page ---
# The web app itself never talks SSH to the backup destination (that would
# mean the SSH key -- or worse, an SSH round-trip on every page load --
# would need to be reachable from the web server user, a much bigger
# credential/latency exposure than this script already has). Instead, this
# script -- which already has that access, once a night -- writes a small
# JSON manifest listing what's actually on the destination in each tier,
# right next to the existing backup_status.json, for backup.php to read.
zpms_write_generations() {
    mkdir -p "$(dirname "$GENERATIONS_FILE")"
    local daily weekly monthly
    daily="$(zpms_ssh "ls -1 '$BACKUP_REMOTE_PATH/raw/daily' 2>/dev/null | grep -v '^\.tmp-' | sort" || true)"
    weekly="$(zpms_ssh "ls -1 '$BACKUP_REMOTE_PATH/raw/weekly' 2>/dev/null | grep -v '^\.tmp-' | sort" || true)"
    monthly="$(zpms_ssh "ls -1 '$BACKUP_REMOTE_PATH/raw/monthly' 2>/dev/null | grep -v '^\.tmp-' | sort" || true)"

    php -r '
        $generatedAt = $argv[1];
        $out = $argv[2];
        $toList = function (string $s): array {
            $s = trim($s);
            return $s === "" ? [] : explode("\n", $s);
        };
        $data = [
            "generated_at" => $generatedAt,
            "tiers" => [
                "daily" => $toList($argv[3]),
                "weekly" => $toList($argv[4]),
                "monthly" => $toList($argv[5]),
            ],
        ];
        file_put_contents($out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ' "$TS" "$GENERATIONS_FILE" "$daily" "$weekly" "$monthly"
}
zpms_write_generations
zpms_backup_log "Wrote generation manifest to $GENERATIONS_FILE"

zpms_write_status "success"
zpms_backup_log "Backup run $TS completed successfully"
