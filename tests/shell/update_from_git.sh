#!/bin/bash
# Tests for bin/update_from_git.sh against throwaway local git repositories.
# No network, no database, no real update.sh: a stand-in logs how it was called.
# Prints "ALL OK" and exits 0, or the failures and exits 1.

SRC=$(cd "$(dirname "$0")/../.." && pwd)
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null

fails=0
ok()   { echo "  ok   $1"; }
bad()  { echo "  FAIL $1"; fails=$((fails+1)); }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

commit() { git -C "$1" add -A && git -C "$1" commit -q -m "$2"; }

# ---- fixtures: remote repos "app" and "fw", each with main and dev ---------
git init -q --bare -b main "$T/app.git"; git init -q --bare -b main "$T/fw.git"
W=$T/work-app; F=$T/work-fw
git init -q -b main "$W"; git -C "$W" remote add origin "$T/app.git"
mkdir -p "$W/bin"; cp "$SRC/bin/update_from_git.sh" "$W/bin/"; echo 1 > "$W/f.txt"
cat > "$W/bin/import_dictionary.php" <<'E'
<?php file_put_contents(getenv('TEST_LOG'), "dictionary imported\n", FILE_APPEND);
E
commit "$W" "app 1"; git -C "$W" push -q origin main
git -C "$W" checkout -q -b dev; echo dev > "$W/dev.txt"; commit "$W" "app dev"; git -C "$W" push -q origin dev
git -C "$W" checkout -q main

git init -q -b main "$F"; git -C "$F" remote add origin "$T/fw.git"
mkdir -p "$F/bin"
cat > "$F/bin/update.sh" <<'E'
#!/bin/sh
echo "update.sh $*" >> "$TEST_LOG"
E
chmod +x "$F/bin/update.sh"
echo 1 > "$F/f.txt"; commit "$F" "fw 1"; git -C "$F" push -q origin main
git -C "$F" checkout -q -b dev; echo d > "$F/d.txt"; commit "$F" "fw dev"; git -C "$F" push -q origin dev
git -C "$F" checkout -q main

export TEST_LOG=$T/log.txt
APP=$T/app
fresh() {   # fresh clones of both repos, config written
    rm -rf "$APP"; git clone -q "$T/app.git" "$APP"; git clone -q "$T/fw.git" "$APP/fw"
    chmod +x "$APP/bin/update_from_git.sh"; echo fw/ >> "$APP/.git/info/exclude"
    : > "$TEST_LOG"; rm -f "$APP/logs/update_status.json"
    cat > "$T/update.conf" <<E
LOCK_FILE=$T/lock
BACKUP_CMD='echo backup >> "$TEST_LOG"'
ALLOWED_BRANCHES='^(main|dev)\$'
LOG_FILE=$T/update.log
STATUS_FILE=$T/status.json
UPDATE_ARGS="--non-interactive --yes"
E
}
upd() { "$APP/bin/update_from_git.sh" --config "$T/update.conf" "$@" </dev/null >"$T/out.txt" 2>&1; echo $?; }
appsha() { git -C "$APP" rev-parse HEAD; }
remote_push() {   # push a new commit to <repo> <branch>
    local clone=$T/pusher-$1; rm -rf "$clone"; git clone -q "$T/$1.git" "$clone"
    git -C "$clone" checkout -q "$2"; echo "$RANDOM" >> "$clone/new.txt"; commit "$clone" "new $RANDOM"; git -C "$clone" push -q origin "$2"
}

echo "update_from_git.sh"

# 1. list / status
fresh
out=$("$APP/bin/update_from_git.sh" --config "$T/update.conf" --list-branches </dev/null)
check "list-branches prints valid JSON with both branches" "echo '$out' | python3 -c 'import json,sys; d=json.load(sys.stdin); assert set(d[\"app\"][\"branches\"])=={\"main\",\"dev\"}; assert d[\"fw\"][\"available\"]'"
out=$("$APP/bin/update_from_git.sh" --config "$T/update.conf" --status </dev/null)
check "status reports current branch and clean tree" "echo '$out' | python3 -c 'import json,sys; d=json.load(sys.stdin); assert d[\"app\"][\"current\"]==\"main\" and d[\"app\"][\"dirty\"] is False'"

# 2. dry run changes nothing but lists the incoming commit
fresh; before=$(appsha); remote_push app main
rc=$(upd --branch main --dry-run)
check "dry-run exits 0" "[ $rc = 0 ]"
check "dry-run lists the incoming commit" "grep -q 'Incoming commits: 1' $T/out.txt"
check "dry-run leaves the checkout alone" "[ $(appsha) = $before ]"
check "dry-run runs no steps" "[ ! -s $TEST_LOG ]"

# 3. a normal update
fresh; remote_push app main
rc=$(upd --branch main)
check "update exits 0" "[ $rc = 0 ]"
check "update fast-forwards to the remote" "[ $(appsha) = \$(git -C $T/app.git rev-parse main) ]"
check "update backs up first, then runs update.sh, then the dictionary" "[ \"\$(tr '\n' '|' < $TEST_LOG)\" = 'backup|update.sh --non-interactive --yes|dictionary imported|' ]"
check "update writes the status file" "python3 -c 'import json; d=json.load(open(\"$T/status.json\")); assert d[\"result\"]==\"success\" and d[\"app\"][\"branch\"]==\"main\"'"
check "update does not touch fw unless asked" "[ \$(git -C $APP/fw rev-parse HEAD) = \$(git -C $APP/fw rev-parse origin/main) ] && ! grep -q 'fw:' $T/out.txt"

# 4. switching branch, schema flag, optional steps
fresh
rc=$(upd --branch dev --no-backup --apply-schema --no-dictionary)
check "switching to dev works" "[ $rc = 0 ] && [ \"\$(git -C $APP branch --show-current)\" = dev ]"
check "--apply-schema adds the diff flags" "grep -q 'update.sh --non-interactive --yes --core-diff=yes --web-diff=yes' $TEST_LOG"
check "--no-backup and --no-dictionary skip those steps" "! grep -q backup $TEST_LOG && ! grep -q dictionary $TEST_LOG"

# 5. framework too
fresh; remote_push fw dev
rc=$(upd --branch main --fw-branch dev)
check "fw is updated to its branch" "[ $rc = 0 ] && [ \"\$(git -C $APP/fw branch --show-current)\" = dev ] && [ \$(git -C $APP/fw rev-parse HEAD) = \$(git -C $T/fw.git rev-parse dev) ]"

# 6. refusals leave the checkout alone
fresh; before=$(appsha)
for b in 'evil' '--upload-pack=x' 'a;b' 'main..dev' 'nonexistent' ''; do
    if [ -z "$b" ]; then continue; fi
    rc=$(upd --branch "$b")
    check "branch '$b' is refused" "[ $rc = 1 ] && [ $(appsha) = $before ] && [ ! -s $TEST_LOG ]"
done
echo changed >> "$APP/f.txt"
rc=$(upd --branch main)
check "modified files are refused" "[ $rc = 1 ] && grep -q 'modified files' $T/out.txt"

# 7. diverged history: ff-only fails with a rollback hint, update.sh not run
fresh; remote_push app main
echo local > "$APP/local.txt"; commit "$APP" "local commit"
rc=$(upd --branch main)
check "diverged branch fails (exit 2) with a rollback hint" "[ $rc = 2 ] && grep -q 'go back to the previous version' $T/out.txt && ! grep -q update.sh $TEST_LOG"

# 8. backup failure stops everything before the pull
fresh; remote_push app main; before=$(appsha)
sed -i "s/^BACKUP_CMD=.*/BACKUP_CMD=false/" "$T/update.conf"
rc=$(upd --branch main)
check "a failed backup aborts before anything is pulled" "[ $rc = 2 ] && [ $(appsha) = $before ] && ! grep -q update.sh $TEST_LOG"

# 9. only one run at a time
fresh
( flock 9; sleep 3 ) 9>"$T/lock" &
sleep 0.5
rc=$(upd --branch main)
check "a second run is refused while one is running" "[ $rc = 1 ] && grep -q 'already running' $T/out.txt"
wait

echo
if [ $fails -eq 0 ]; then echo "ALL OK"; exit 0; fi
echo "$fails FAILED"; exit 1
