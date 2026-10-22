#!/bin/bash
# Tests for the options of the framework's update.sh (fw/bin/update.sh) against a
# fake application whose maker.php and msql.sh just record what they are asked.
# Prints "ALL OK" and exits 0, or the failures and exits 1.

SRC=$(cd "$(dirname "$0")/../.." && pwd)
if [ -z "${UPDATE_SH:-}" ]; then
    if [ -f "$SRC/fw/bin/update.sh" ]; then UPDATE_SH=$SRC/fw/bin/update.sh
    else UPDATE_SH=$(dirname "$(readlink -f "$SRC/web/core")")/bin/update.sh; fi
fi
[ -f "$UPDATE_SH" ] || { echo "update.sh not found ($UPDATE_SH); set UPDATE_SH"; exit 1; }

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
A=$T/app; export FAKE_LOG=$T/log
mkdir -p "$A/web/core/maker" "$A/web/core/classes/sql" "$A/web/classes/sql" "$A/web/classes/yaml" "$A/web/content" "$A/sql"
touch "$A/web/core/bootstrap.php" "$A/web/classes/yaml/s.yaml"
cat > "$A/web/core/maker/maker.php" <<'P'
<?php
file_put_contents(getenv('FAKE_LOG'), implode(' ', array_slice($argv, 1)) . "\n", FILE_APPEND);
$cmd = end($argv);
if ($cmd === 'tables:new:fw') echo "t1 t2\n";
if ($cmd === 'tables:new:web') echo "w1\n";
if ($cmd === 'diff:sql:all') echo "ALTER TABLE x ADD y;\n";
P
cat > "$A/sql/msql.sh" <<'P'
#!/bin/bash
echo "msql: $(head -c 40 | tr '\n' ' ')" >> "$FAKE_LOG"
P
chmod +x "$A/sql/msql.sh"
echo t1 > "$A/web/core/classes/sql/t1.sql"; echo t2 > "$A/web/core/classes/sql/t2.sql"; echo w > "$A/web/classes/sql/w1.sql"
printf 'form:\n  x: 1\n' > "$A/web/classes/yaml/webform.yaml"
printf 'schema: s.yaml\n' > "$A/web/content/a.feeder.yaml"

fails=0
check() { if eval "$2"; then echo "  ok   $1"; else echo "  FAIL $1"; fails=$((fails+1)); fi; }
run() { : > "$FAKE_LOG"; ( cd "$A" && bash "$UPDATE_SH" "$@" </dev/null >"$T/out" 2>&1 ); RC=$?; }
has() { grep -q -- "$1" "$FAKE_LOG"; }

echo "update.sh options"
run --dry-run
check "dry-run exits 0 and writes nothing" "[ $RC = 0 ] && ! has msql && ! has 'spill:' && ! has feed: && ! has form:load"
check "dry-run still reports the missing tables" "grep -q 'missing from the database' $T/out"

run --non-interactive
check "non-interactive regenerates but imports/applies/loads nothing" "[ $RC = 0 ] && has 'spill:sql:all' && ! has msql && ! has feed: && ! has form:load"

run --yes
check "--yes imports tables, loads feeders and forms" "[ $RC = 0 ] && has 'msql: t1' && has 'msql: w' && has 'feed:load' && has 'form:load'"
check "--yes does NOT apply the database diffs" "! has 'ALTER TABLE'"
check "--yes does NOT clean the feeds" "! has 'feed:clean'"

run --yes --core-diff=yes --web-diff=yes --clean-feeds=yes
check "diffs and clean-feeds only when given explicitly" "[ $RC = 0 ] && [ \$(grep -c 'msql: ALTER' $FAKE_LOG) = 2 ] && has 'feed:clean'"

run --core-tables=no --web-tables=all --content=yes --generate-feeders=no --forms=no
check "each option acts on its own step" "[ $RC = 0 ] && ! has 'msql: t1' && has 'msql: w' && has feed:load && ! has feed:gen:yaml && ! has form:load"

run --bogus
check "unknown option exits 2" "[ $RC = 2 ]"
run --core-diff=maybe
check "bad value exits 2" "[ $RC = 2 ]"

echo
if [ $fails -eq 0 ]; then echo "ALL OK"; exit 0; fi
echo "$fails FAILED"; exit 1
