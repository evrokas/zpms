#!/bin/bash
# Tests for deploy/zpms-git-update (the sudo wrapper): which commands and options
# it lets through, and that it hands a branch name over only after checking it.
# bin/update_from_git.sh is replaced by a stub that records its arguments.
# The wrapper reads /etc/zpms/update.conf, so this is skipped unless it can write
# there (root) and nothing is installed already.

SRC=$(cd "$(dirname "$0")/../.." && pwd)
CONF=/etc/zpms/update.conf
if [ "$(id -u)" != 0 ] || [ -e "$CONF" ]; then echo "skipped (needs root and no existing $CONF)"; echo "ALL OK"; exit 0; fi

T=$(mktemp -d)
cleanup() { rm -f "$CONF"; rmdir /etc/zpms 2>/dev/null; rm -rf "$T"; }
trap cleanup EXIT
mkdir -p /etc/zpms "$T/app/bin" "$T/app/logs"
cat > "$T/app/bin/update_from_git.sh" <<'S'
#!/bin/bash
echo "$*" > "$STUB_OUT"
case " $* " in *" --list-branches "*) echo '{"app":{}}' ;; *" --status "*) echo '{"app":{"current":"main"}}' ;; esac
exit 0
S
chmod +x "$T/app/bin/update_from_git.sh"
printf 'DEPLOY_APP_ROOT=%s\nLOCK_FILE=%s\n' "$T/app" "$T/lock" > "$CONF"
export STUB_OUT=$T/out
W=$SRC/deploy/zpms-git-update

fails=0
check() { if eval "$2"; then echo "  ok   $1"; else echo "  FAIL $1"; fails=$((fails+1)); fi; }
w() { rm -f "$STUB_OUT"; bash "$W" "$@" >"$T/stdout" 2>"$T/stderr"; RC=$?; }

echo "zpms-git-update"
w branches;  check "branches runs --list-branches" "[ $RC = 0 ] && grep -q -- '--list-branches' $STUB_OUT && grep -q app $T/stdout"
w status;    check "status wraps repos, last run and running flag as JSON" "[ $RC = 0 ] && python3 -c 'import json; d=json.load(open(\"$T/stdout\")); assert d[\"repos\"][\"app\"][\"current\"]==\"main\" and d[\"running\"] is False and d[\"last\"] is None'"
w preview --branch main --fw --fw-branch dev --no-backup
check "preview passes validated options and --dry-run" "[ $RC = 0 ] && grep -q -- '--dry-run --branch main --fw --fw-branch dev --no-backup' $STUB_OUT"
w run --branch release/1.2 --backup --apply-schema
for i in 1 2 3 4 5 6 7 8 9 10; do [ -s "$STUB_OUT" ] && break; sleep 0.3; done
check "run starts the update detached with --yes" "[ $RC = 0 ] && grep -q started $T/stdout && grep -q -- '--yes --branch release/1.2 --backup --apply-schema' $STUB_OUT"

for bad in 'evil;rm' '--upload-pack=x' 'a b' 'x..y' '$(id)' '`id`' '-main' 'main/' 'a.lock'; do
    w preview --branch "$bad"
    check "branch '$bad' is rejected" "[ $RC = 1 ] && [ ! -e $STUB_OUT ]"
done
w preview --config /tmp/x;       check "--config is not allowed" "[ $RC = 1 ] && [ ! -e $STUB_OUT ]"
w preview --yes;                 check "--yes is not allowed on preview" "[ $RC = 1 ]"
w run --branch;                  check "a missing branch value is rejected" "[ $RC = 1 ]"
w rm -rf /;                      check "unknown commands are rejected" "[ $RC = 1 ] && [ ! -e $STUB_OUT ]"
w;                               check "no command prints usage and fails" "[ $RC = 1 ]"

echo "line1
line2
line3" > "$T/app/logs/update.log"
w log 2; check "log prints the last lines" "[ $RC = 0 ] && [ \"\$(cat $T/stdout)\" = \"line2
line3\" ]"
w log abc; check "log rejects a non-number" "[ $RC = 1 ]"

( flock 8; sleep 3 ) 8>"$T/lock" & sleep 0.5
w run --branch main; check "run refuses while an update is running" "[ $RC = 1 ] && grep -q 'already running' $T/stderr"
w status; check "status reports running=true during a run" "python3 -c 'import json; assert json.load(open(\"$T/stdout\"))[\"running\"] is True'"
wait

echo
if [ $fails -eq 0 ]; then echo "ALL OK"; exit 0; fi
echo "$fails FAILED"; exit 1
