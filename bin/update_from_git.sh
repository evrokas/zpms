#!/bin/bash
#
# Update this installation from git: pick a branch, pull, run the framework's
# update.sh, import the translations.  Optionally also update the ZeusFW
# checkout (fw/) and back up the database first.
#
# Everything is configurable: command line > config file > built-in defaults.
# Reference config: deploy/update.conf.example (install as /etc/zpms/update.conf).
#
# Run it as the user that owns the checkout (see deploy/setup_update_user.sh),
# normally through deploy/zpms-git-update (sudo wrapper) from the web page.

set -u
set -o pipefail

usage() {
cat <<'EOF'
Usage: bin/update_from_git.sh [options]

Branches
  --branch NAME        branch of this application to switch to and pull
  --fw-branch NAME     branch of the ZeusFW checkout (fw/); implies --fw
  --fw / --no-fw       also update the ZeusFW checkout (default: no)
  (without --branch, at a terminal you get a menu; unattended it uses
   DEFAULT_BRANCH / DEFAULT_FW_BRANCH from the config, else the current branch)

Steps
  --backup / --no-backup   back up the database first (default: yes)
  --no-update-sh           skip fw/bin/update.sh
  --apply-schema           let update.sh apply the database changes (diff:sql);
                           without it they are shown but NOT applied
  --no-dictionary          skip the translations import
  --no-post                skip POST_STEPS and RESTART_CMD

Information (change nothing)
  --dry-run                fetch and list incoming commits only
  --list-branches          fetch, then print the allowed branches as JSON
  --status                 print current branches/commits as JSON (no fetch)

Other
  --yes                    do not ask for confirmation
  --config FILE            config file (default /etc/zpms/update.conf if present)
  -h, --help

Exit status: 0 ok, 1 refused (bad branch, modified files, already running),
2 a step failed.
EOF
}

# ---- defaults (override in the config file) -------------------------------

SELF=$(readlink -f "$0")
APP_ROOT=$(cd "$(dirname "$SELF")/.." && pwd)
FW_DIR=""                      # default: ./fw, else the checkout web/core points into
REMOTE=origin
ALLOWED_BRANCHES='^(main|master|prod|production|stable|release/[A-Za-z0-9._-]+)$'
ALLOWED_FW_BRANCHES=""         # default: same as ALLOWED_BRANCHES
DEFAULT_BRANCH=""              # default: the current branch
DEFAULT_FW_BRANCH=""
PULL_MODE=ff-only              # ff-only | merge | rebase
ALLOW_DIRTY=no
UPDATE_FW=no
RUN_UPDATE_SH=yes
UPDATE_ARGS="--non-interactive --yes"
RUN_IMPORT_DICTIONARY=yes
DICTIONARY_CMD="php bin/import_dictionary.php"
POST_STEPS=()                  # extra commands, run from the app root
RESTART_CMD=""
BACKUP_BEFORE=yes
BACKUP_CMD=""                  # default: built-in mysqldump of the database in config/db.php
BACKUP_DIR=/var/backups/zpms
BACKUP_KEEP=14
LOG_FILE=""                    # default: <app>/logs/update.log
STATUS_FILE=""                 # default: <app>/logs/update_status.json
LOCK_FILE=/tmp/zpms-update.lock
GIT_TIMEOUT=180

# ---- options ---------------------------------------------------------------

ORIG_ARGS=("$@")
CONFIG_FILE=""
OPT_BRANCH=""; OPT_FW_BRANCH=""; OPT_FW=""; OPT_BACKUP=""; OPT_UPDATE_SH=""
OPT_APPLY_SCHEMA=0; OPT_DICT=""; OPT_POST=""; DRYRUN=0; ASSUME_YES=0
MODE=update

die() { echo "update_from_git: $*" >&2; exit "${2:-1}"; }

while [[ $# -gt 0 ]]; do
    case $1 in
        --branch)        [[ $# -ge 2 ]] || die "--branch needs a value"; OPT_BRANCH=$2; shift ;;
        --branch=*)      OPT_BRANCH=${1#*=} ;;
        --fw-branch)     [[ $# -ge 2 ]] || die "--fw-branch needs a value"; OPT_FW_BRANCH=$2; OPT_FW=yes; shift ;;
        --fw-branch=*)   OPT_FW_BRANCH=${1#*=}; OPT_FW=yes ;;
        --fw)            OPT_FW=yes ;;
        --no-fw)         OPT_FW=no ;;
        --backup)        OPT_BACKUP=yes ;;
        --no-backup)     OPT_BACKUP=no ;;
        --no-update-sh)  OPT_UPDATE_SH=no ;;
        --apply-schema)  OPT_APPLY_SCHEMA=1 ;;
        --no-dictionary) OPT_DICT=no ;;
        --no-post)       OPT_POST=no ;;
        --dry-run)       DRYRUN=1 ;;
        --list-branches) MODE=list ;;
        --status)        MODE=status ;;
        --yes|-y)        ASSUME_YES=1 ;;
        --config)        [[ $# -ge 2 ]] || die "--config needs a value"; CONFIG_FILE=$2; shift ;;
        --config=*)      CONFIG_FILE=${1#*=} ;;
        -h|--help)       usage; exit 0 ;;
        *) echo "update_from_git: unknown option: $1" >&2; usage >&2; exit 1 ;;
    esac
    shift
done

if [[ -z $CONFIG_FILE && -r /etc/zpms/update.conf ]]; then CONFIG_FILE=/etc/zpms/update.conf; fi
if [[ -n $CONFIG_FILE ]]; then
    [[ -r $CONFIG_FILE ]] || die "cannot read config file $CONFIG_FILE"
    # shellcheck disable=SC1090
    source "$CONFIG_FILE" || die "error in config file $CONFIG_FILE"
fi

# command line wins over the config file
[[ -n $OPT_FW ]]        && UPDATE_FW=$OPT_FW
[[ -n $OPT_BACKUP ]]    && BACKUP_BEFORE=$OPT_BACKUP
[[ -n $OPT_UPDATE_SH ]] && RUN_UPDATE_SH=$OPT_UPDATE_SH
[[ -n $OPT_DICT ]]      && RUN_IMPORT_DICTIONARY=$OPT_DICT
[[ -n $ALLOWED_FW_BRANCHES ]] || ALLOWED_FW_BRANCHES=$ALLOWED_BRANCHES
[[ -n $LOG_FILE ]]    || LOG_FILE="$APP_ROOT/logs/update.log"
[[ -n $STATUS_FILE ]] || STATUS_FILE="$APP_ROOT/logs/update_status.json"

case $PULL_MODE in ff-only|merge|rebase) ;; *) die "PULL_MODE must be ff-only, merge or rebase" ;; esac

if [[ -z $FW_DIR ]]; then
    if [[ -e $APP_ROOT/fw/.git ]]; then FW_DIR=$APP_ROOT/fw
    elif [[ -e $APP_ROOT/web/core ]]; then FW_DIR=$(dirname "$(readlink -f "$APP_ROOT/web/core")")
    else FW_DIR=$APP_ROOT/fw; fi
fi

export GIT_TERMINAL_PROMPT=0
export GIT_SSH_COMMAND=${GIT_SSH_COMMAND:-"ssh -o BatchMode=yes"}

# ---- git helpers -----------------------------------------------------------

# A branch name we are willing to pass to git at all.
valid_branch_name() {
    local b=$1
    [[ $b =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]] || return 1
    [[ $b == *..* || $b == */ || $b == *.lock || $b == *//* ]] && return 1
    return 0
}

g() { local dir=$1; shift; git -C "$dir" "$@"; }
gt() { local dir=$1; shift; timeout "$GIT_TIMEOUT" git -C "$dir" "$@"; }

is_repo() { [[ -e $1/.git ]] && g "$1" rev-parse --git-dir >/dev/null 2>&1; }

current_branch() { g "$1" symbolic-ref --short -q HEAD || echo "(detached)"; }

is_dirty() { [[ -n $(g "$1" status --porcelain --untracked-files=no 2>/dev/null) ]]; }

# allowed_branches <dir> <regex>: remote branches that pass the allow-list
allowed_branches() {
    local dir=$1 regex=$2 b
    g "$dir" for-each-ref --format='%(refname:short)' "refs/remotes/$REMOTE" 2>/dev/null \
      | sed "s#^$REMOTE/##" | grep -v '^HEAD$' | sort \
      | while read -r b; do
            valid_branch_name "$b" && [[ $b =~ $regex ]] && echo "$b"
        done
}

json_repo() {  # <label> <dir> <regex>
    local label=$1 dir=$2 regex=$3 first=1 b
    printf '"%s":{' "$label"
    if ! is_repo "$dir"; then printf '"available":false}'; return; fi
    printf '"available":true,"current":"%s","commit":"%s","dirty":%s,"branches":[' \
        "$(current_branch "$dir")" "$(g "$dir" rev-parse --short HEAD)" \
        "$(is_dirty "$dir" && echo true || echo false)"
    while read -r b; do
        [[ -n $b ]] || continue
        [[ $first == 1 ]] || printf ','
        printf '"%s"' "$b"; first=0
    done < <(allowed_branches "$dir" "$regex")
    printf ']}'
}

if [[ $MODE == list || $MODE == status ]]; then
    if [[ $MODE == list ]]; then
        for d in "$APP_ROOT" "$FW_DIR"; do is_repo "$d" && gt "$d" fetch --prune "$REMOTE" >/dev/null 2>&1; done
    fi
    printf '{'
    json_repo app "$APP_ROOT" "$ALLOWED_BRANCHES"; printf ','
    json_repo fw "$FW_DIR" "$ALLOWED_FW_BRANCHES"
    printf '}\n'
    exit 0
fi

# ---- one run at a time, and a log ------------------------------------------

{ exec 9>"$LOCK_FILE"; } 2>/dev/null || die "cannot open lock file $LOCK_FILE"
flock -n 9 || die "another update is already running"

# Keep a log: run ourselves again with the output copied to LOG_FILE.  A pipe
# to tee, not a redirect into a background process, so the last lines (the
# result!) are never lost when we exit, and prompts stay visible.
if [[ $DRYRUN == 0 && ${ZPMS_UPDATE_INNER:-} != 1 ]]; then
    if mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null && touch "$LOG_FILE" 2>/dev/null; then
        flock -u 9
        printf '\n[%s] ==== update started by %s ====\n' "$(date -u +%FT%TZ)" "$(id -un)" >> "$LOG_FILE"
        ZPMS_UPDATE_INNER=1 "$SELF" "${ORIG_ARGS[@]}" 2>&1 | tee -a "$LOG_FILE"
        exit "${PIPESTATUS[0]}"
    fi
    echo "Warning: cannot write the log file $LOG_FILE; continuing without it"
fi

RESULT=failed
STATUS_START=$(date -u +%FT%TZ)
declare -A OLD NEW BR
write_status() {
    [[ $DRYRUN == 1 ]] && return
    local tmp="$STATUS_FILE.tmp.$$"
    {
        printf '{"started":"%s","finished":"%s","result":"%s",' "$STATUS_START" "$(date -u +%FT%TZ)" "$RESULT"
        printf '"app":{"branch":"%s","old":"%s","new":"%s"},' "${BR[app]:-}" "${OLD[app]:-}" "${NEW[app]:-}"
        printf '"fw":{"branch":"%s","old":"%s","new":"%s"}}\n' "${BR[fw]:-}" "${OLD[fw]:-}" "${NEW[fw]:-}"
    } > "$tmp" 2>/dev/null && mv -f "$tmp" "$STATUS_FILE" 2>/dev/null || rm -f "$tmp" 2>/dev/null
}
finish() {
    local code=$?
    write_status
    echo "== RESULT: $RESULT =="
    exit $code
}
trap finish EXIT

fail() { echo "ERROR: $*"; RESULT=failed; exit "${2:-2}"; }
refuse() { echo "REFUSED: $*"; RESULT=refused; exit 1; }

confirm() {
    [[ $ASSUME_YES == 1 || $DRYRUN == 1 ]] && return 0
    [[ -t 0 ]] || return 0
    local a; read -r -p "$1 [y/N] " a
    [[ $a == [yY] ]]
}

# choose_branch <label> <dir> <regex> <default> -> prints the chosen branch
choose_branch() {
    local label=$1 dir=$2 regex=$3 def=$4 cur i=0 pick
    cur=$(current_branch "$dir")
    [[ -n $def ]] || def=$cur
    mapfile -t list < <(allowed_branches "$dir" "$regex")
    [[ ${#list[@]} -gt 0 ]] || refuse "no allowed remote branches found for $label (fetch failed, or none match ALLOWED_BRANCHES)"
    if [[ ! -t 0 ]]; then
        printf '%s\n' "$def"; return
    fi
    echo "Branches for $label (current: $cur):" >&2
    for b in "${list[@]}"; do
        i=$((i+1)); printf '  %2d) %s%s\n' "$i" "$b" "$([[ $b == "$cur" ]] && echo '  <- current')" >&2
    done
    read -r -p "Number or name [$def]: " pick
    [[ -n $pick ]] || pick=$def
    if [[ $pick =~ ^[0-9]+$ && $pick -ge 1 && $pick -le ${#list[@]} ]]; then pick=${list[$((pick-1))]}; fi
    printf '%s\n' "$pick"
}

# check_branch <label> <dir> <regex> <branch>: must be valid, allowed and exist on the remote
check_branch() {
    local label=$1 dir=$2 regex=$3 b=$4
    valid_branch_name "$b" || refuse "'$b' is not a valid branch name"
    [[ $b =~ $regex ]] || refuse "branch '$b' is not allowed for $label (ALLOWED_BRANCHES)"
    g "$dir" rev-parse --verify -q "refs/remotes/$REMOTE/$b" >/dev/null || refuse "branch '$b' does not exist on $REMOTE for $label"
}

# ---- plan ------------------------------------------------------------------

DO_FW=no; [[ $UPDATE_FW == yes ]] && DO_FW=yes
if [[ $DO_FW == yes ]] && ! is_repo "$FW_DIR"; then refuse "the ZeusFW checkout $FW_DIR is not a git repository"; fi
is_repo "$APP_ROOT" || refuse "$APP_ROOT is not a git repository"

echo "Update from git: application $APP_ROOT$([[ $DO_FW == yes ]] && echo ", framework $FW_DIR")"
[[ $DRYRUN == 1 ]] && echo "(dry run: nothing is changed)"

REPOS=(app); [[ $DO_FW == yes ]] && REPOS=(fw app)
declare -A DIR REGEX
DIR[app]=$APP_ROOT; DIR[fw]=$FW_DIR
REGEX[app]=$ALLOWED_BRANCHES; REGEX[fw]=$ALLOWED_FW_BRANCHES

# 1. fetch everywhere first, so a failure leaves everything untouched
for r in "${REPOS[@]}"; do
    echo "Fetching $r ..."
    gt "${DIR[$r]}" fetch --prune "$REMOTE" || fail "git fetch failed for $r"
done

# 2. choose and validate the branches
for r in "${REPOS[@]}"; do
    if [[ $r == app ]]; then want=$OPT_BRANCH; def=$DEFAULT_BRANCH; else want=$OPT_FW_BRANCH; def=$DEFAULT_FW_BRANCH; fi
    [[ -n $want ]] || want=$(choose_branch "$r" "${DIR[$r]}" "${REGEX[$r]}" "$def")
    check_branch "$r" "${DIR[$r]}" "${REGEX[$r]}" "$want"
    BR[$r]=$want
    OLD[$r]=$(g "${DIR[$r]}" rev-parse HEAD)
    if is_dirty "${DIR[$r]}" && [[ $ALLOW_DIRTY != yes ]]; then
        refuse "$r has modified files (git status); commit, stash or discard them first (or set ALLOW_DIRTY=yes)"
    fi
done

# 3. show what would come in
for r in "${REPOS[@]}"; do
    echo
    echo "== $r: ${BR[$r]} (now on $(current_branch "${DIR[$r]}") @ $(g "${DIR[$r]}" rev-parse --short HEAD)) =="
    n=$(g "${DIR[$r]}" rev-list --count "HEAD..$REMOTE/${BR[$r]}" 2>/dev/null || echo "?")
    echo "Incoming commits: $n"
    g "${DIR[$r]}" log --oneline --no-decorate -n 30 "HEAD..$REMOTE/${BR[$r]}" 2>/dev/null | sed 's/^/  /'
done
echo

if [[ $DRYRUN == 1 ]]; then
    echo "Steps that would run: backup=$BACKUP_BEFORE update.sh=$RUN_UPDATE_SH (args: $UPDATE_ARGS$([[ $OPT_APPLY_SCHEMA == 1 ]] && echo ' --core-diff=yes --web-diff=yes')) dictionary=$RUN_IMPORT_DICTIONARY"
    RESULT=dry-run
    exit 0
fi

confirm "Continue with the update?" || { RESULT=cancelled; echo "Cancelled."; exit 1; }

# ---- backup ----------------------------------------------------------------

db_backup() {
    local creds host user pass name cnf out
    if [[ -n $BACKUP_CMD ]]; then
        ( cd "$APP_ROOT" && eval "$BACKUP_CMD" ); return
    fi
    command -v mysqldump >/dev/null || { echo "mysqldump is not installed"; return 1; }
    [[ -r $APP_ROOT/config/db.php ]] || { echo "cannot read $APP_ROOT/config/db.php"; return 1; }
    creds=$(php -r 'require $argv[1]; echo DB_HOST,"\n",DB_USER,"\n",DB_PASS,"\n",DB_NAME,"\n";' "$APP_ROOT/config/db.php") || return 1
    { read -r host; read -r user; read -r pass; read -r name; } <<< "$creds"
    mkdir -p "$BACKUP_DIR" || return 1
    cnf=$(mktemp) || return 1
    chmod 600 "$cnf"
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$user" "$pass" "$host" > "$cnf"
    out="$BACKUP_DIR/zpms-$(date +%Y%m%d-%H%M%S).sql.gz"
    ( umask 077; mysqldump --defaults-extra-file="$cnf" --single-transaction --no-tablespaces \
        --default-character-set=utf8mb4 --routines "$name" | gzip > "$out" )
    local rc=$?
    rm -f "$cnf"
    if [[ $rc -ne 0 ]] || ! gzip -t "$out" 2>/dev/null || [[ ! -s $out ]]; then
        rm -f "$out"; echo "the dump failed or is empty"; return 1
    fi
    echo "Database backup: $out ($(du -h "$out" | cut -f1))"
    # keep the newest BACKUP_KEEP dumps
    ls -1t "$BACKUP_DIR"/zpms-*.sql.gz 2>/dev/null | tail -n +"$((BACKUP_KEEP+1))" | xargs -r rm -f --
}

if [[ $BACKUP_BEFORE == yes ]]; then
    echo "Backing up the database ..."
    db_backup || fail "the database backup failed; nothing was updated"
else
    echo "Database backup skipped."
fi

# ---- pull ------------------------------------------------------------------

rollback_hint() {
    echo "To go back to the previous version:"
    for r in "${REPOS[@]}"; do
        echo "  git -C ${DIR[$r]} checkout ${OLD[$r]}    # was $(current_branch "${DIR[$r]}")"
    done
}

for r in "${REPOS[@]}"; do
    d=${DIR[$r]}; b=${BR[$r]}
    echo
    echo "Updating $r to $b ..."
    if g "$d" show-ref --verify -q "refs/heads/$b"; then
        g "$d" checkout -q "$b" || { rollback_hint; fail "git checkout $b failed for $r"; }
    else
        g "$d" checkout -q -b "$b" --track "$REMOTE/$b" || { rollback_hint; fail "git checkout $b failed for $r"; }
    fi
    case $PULL_MODE in
        ff-only) g "$d" merge --ff-only "$REMOTE/$b" ;;
        merge)   g "$d" merge --no-edit "$REMOTE/$b" ;;
        rebase)  g "$d" rebase "$REMOTE/$b" ;;
    esac || { rollback_hint; fail "could not bring $r up to date with $REMOTE/$b ($PULL_MODE); it may have diverged"; }
    NEW[$r]=$(g "$d" rev-parse HEAD)
    echo "$r: ${OLD[$r]:0:9} -> ${NEW[$r]:0:9}"
done

# ---- steps after the pull --------------------------------------------------

if [[ $RUN_UPDATE_SH == yes ]]; then
    echo
    echo "Running update.sh ..."
    args=($UPDATE_ARGS)
    [[ $OPT_APPLY_SCHEMA == 1 ]] && args+=(--core-diff=yes --web-diff=yes)
    if [[ -x $FW_DIR/bin/update.sh ]]; then
        ( cd "$APP_ROOT" && "$FW_DIR/bin/update.sh" "${args[@]}" ) || { rollback_hint; fail "update.sh failed"; }
    else
        fail "$FW_DIR/bin/update.sh not found or not executable"
    fi
fi

if [[ $RUN_IMPORT_DICTIONARY == yes ]]; then
    echo
    echo "Importing translations ..."
    ( cd "$APP_ROOT" && eval "$DICTIONARY_CMD" ) || fail "the translations import failed"
fi

if [[ $OPT_POST != no ]]; then
    for step in "${POST_STEPS[@]:-}"; do
        [[ -n $step ]] || continue
        echo
        echo "Running: $step"
        ( cd "$APP_ROOT" && eval "$step" ) || fail "post step failed: $step"
    done
    if [[ -n $RESTART_CMD ]]; then
        echo
        echo "Running: $RESTART_CMD"
        eval "$RESTART_CMD" || echo "Warning: the restart command failed (the update itself is complete)"
    fi
fi

RESULT=success
echo
echo "Update complete."
exit 0
