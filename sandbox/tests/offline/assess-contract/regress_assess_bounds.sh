#!/usr/bin/env bash
# Regression — round-3 MUP §4.6: no new renderer prints an unbounded list.
#
# `wprism assess`'s human view bounds every section at 50 rows by default,
# accepts a canonical `--limit=1..200` (the same closed grammar `wprism status`
# already parses, so an operator learns one rule), prints an
# `N more (use --format=json)` tail whenever it cut a section, and refuses a
# malformed bound instead of silently falling back to the default.
#
# The last of those is the one worth stating: a bound that silently defaults
# is worse than no bound, because the operator who typed `--limit=20` reads
# the absence of a tail line as "there are no more rows".
#
# The counts printed beside a truncated list are always the TRUE totals.
# A truncated sample is honest; a truncated count is a lie about the site.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-assess-bounds.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

# The fixture carries 8 real surface groups plus 60 padding post types, and
# 70 pending rows — both comfortably past the 50-row default, so a renderer
# that lost its bound would be visible rather than merely unproven.
php "$ROOT/sandbox/tests/fixtures/assess/make-fixture.php" "$TMP/site" --surfaces=60 --pending=70 >/dev/null \
  || { echo "FAIL: could not build the assess fixture" >&2; exit 1; }

export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$TMP/site/repo"
export WPRISM_CALLS="$TMP/calls.txt"
PATH="$TMP/site/bin:$PATH"
export PATH

# run <stdout-file> [args...] -> exit code
run() {
  local out="$1"; shift
  ( cd "$TMP/site/repo" && php "$ROOT/cli/wprism" --envs-file="$TMP/site/envs.json" "$@" ) \
    > "$out" 2> "$out.err"
}

# surface_rows <file> -> how many table rows the human view printed.
# A surface id is `<kind>:<name>` and `<name>` may itself contain a colon
# (`option_group:<declarant>:<class>`), so the character class has to admit
# one — an id-shaped regex that quietly missed two rows would make every
# count in this suite two short and none of the assertions would say so.
surface_rows() { grep -cE '^[a-z_]+:[A-Za-z0-9_:.-]+ +' "$1" || true; }

php "$ROOT/cli/wprism" --help > "$TMP/help.txt" 2> "$TMP/help.txt.err"
STATUS=$?
if [ "$STATUS" = 0 ] \
  && grep -Fq 'wprism assess <env> [--operation=<ops>] [--limit=<1..200>] [--cursor=<token>] [--format=json]' "$TMP/help.txt" \
  && grep -Fq '[--cursor=<token>]    unknown queue.' "$TMP/help.txt"; then
  pass 'built-in help advertises the assess cursor in both synopsis and detail'
else
  fail 'built-in help omitted the assess --cursor option'
fi

# --------------------------------------------------------------- true totals
say 'the true totals'
run "$TMP/full.json" assess fixture --format=json
TOTAL=$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["surfaces"]);' "$TMP/full.json")
PENDING=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["unknown"]["pending_count"];' "$TMP/full.json")
if [ "$TOTAL" -gt 50 ] && [ "$PENDING" -gt 50 ]; then
  pass "the fixture is genuinely past the bound ($TOTAL surfaces, $PENDING pending)"
else
  fail "the fixture ($TOTAL surfaces, $PENDING pending) does not exercise the bound"
fi

# --------------------------------------------------------------- the default
say 'the default bound'
run "$TMP/default.txt" assess fixture
STATUS=$?
[ "$STATUS" = 3 ] && pass 'the bounded human view preserves the complete-with-gaps exit' || fail "human assess exited $STATUS"
SHOWN=$(surface_rows "$TMP/default.txt")
[ "$SHOWN" = 50 ] && pass 'the surface table shows exactly 50 rows by default' \
  || fail "the surface table showed $SHOWN rows, expected the 50-row default"

EXPECT_MORE=$((TOTAL - 50))
if grep -Fq "$EXPECT_MORE more (use --format=json)" "$TMP/default.txt"; then
  pass "the cut section ends in '$EXPECT_MORE more (use --format=json)'"
else
  fail "the surface table did not print its '$EXPECT_MORE more' tail"
fi

# The unknown section is bounded independently and its counts stay true.
if grep -Fq "$PENDING pending classification(s)" "$TMP/default.txt"; then
  pass 'the unknown section prints the TRUE pending count beside its truncated sample'
else
  fail 'the unknown section did not print the true pending count'
fi
if grep -Fq '41 option name(s) invisible' "$TMP/default.txt"; then
  pass 'the invisible-option count is the true total, not the sample size'
else
  fail 'the invisible-option count was not the true total'
fi
UNKNOWN_SAMPLE=$(grep -cE '^ +- ' "$TMP/default.txt" || true)
[ "$UNKNOWN_SAMPLE" -le 50 ] && pass "the unknown sample is bounded ($UNKNOWN_SAMPLE listed)" \
  || fail "the unknown sample printed $UNKNOWN_SAMPLE names, past the bound"

# --------------------------------------------------------------- --limit
say 'the --limit grammar'
run "$TMP/limit5.txt" assess fixture --limit=5
STATUS=$?
[ "$STATUS" = 3 ] && pass '--limit=5 is accepted without hiding the complete-with-gaps exit' || fail "--limit=5 exited $STATUS"
SHOWN=$(surface_rows "$TMP/limit5.txt")
[ "$SHOWN" = 5 ] && pass '--limit=5 shows exactly 5 surface rows' \
  || fail "--limit=5 showed $SHOWN rows"
if grep -Fq "$((TOTAL - 5)) more (use --format=json)" "$TMP/limit5.txt"; then
  pass '--limit=5 still names how many rows it hid'
else
  fail '--limit=5 did not print an accurate tail'
fi

run "$TMP/limit1.txt" assess fixture --limit=1
[ "$(surface_rows "$TMP/limit1.txt")" = 1 ] && pass '--limit=1 is the floor and is accepted' \
  || fail '--limit=1 was not honoured'

run "$TMP/limit200.txt" assess fixture --limit=200
SHOWN=$(surface_rows "$TMP/limit200.txt")
[ "$SHOWN" = "$TOTAL" ] && pass "--limit=200 is the ceiling and shows every row ($SHOWN)" \
  || fail "--limit=200 showed $SHOWN of $TOTAL rows"
grep -Fq 'more (use --format=json)' "$TMP/limit200.txt" \
  && fail 'a section that was not cut still printed a tail line' \
  || pass 'an uncut section prints no tail line'

# --------------------------------------------------- refusals, never defaults
say 'a malformed bound refuses'
for bad in 0 201 abc '' 007 -1 '1 '; do
  run "$TMP/bad.txt" assess fixture "--limit=$bad"
  STATUS=$?
  if [ "$STATUS" = 1 ]; then
    pass "--limit='$bad' refuses instead of falling back to the default"
  else
    fail "--limit='$bad' exited $STATUS; a mistyped bound must never silently default"
  fi
done

run "$TMP/badspaced.txt" assess fixture --limit 5
STATUS=$?
[ "$STATUS" = 1 ] && pass 'the spaced --limit form refuses; the grammar is closed' \
  || fail "--limit 5 (spaced) exited $STATUS"

run "$TMP/badtwice.txt" assess fixture --limit=5 --limit=6
STATUS=$?
[ "$STATUS" = 1 ] && pass 'a repeated --limit refuses rather than last-wins' \
  || fail "a repeated --limit exited $STATUS"

run "$TMP/badjson.json" assess fixture --limit=0 --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"reason_code":"invalid_arguments"' "$TMP/badjson.json"; then
  pass 'the bound refusal is the common machine envelope under --format=json'
else
  fail 'a malformed bound did not produce a typed JSON refusal'
fi

# ---------------------------------------------------- output selector grammar
say 'the closed output selector grammar'
PROPOSAL="$WPRISM_SITE_REPO/.wprism/contract/fixture/proposed.json"
check_invalid_selector() {
  local label="$1"; shift
  local out="$TMP/selector-$label.out"
  local before after
  before=$(shasum -a 256 "$PROPOSAL" | awk '{print $1}')
  : > "$WPRISM_CALLS"
  run "$out" assess fixture "$@"
  STATUS=$?
  after=$(shasum -a 256 "$PROPOSAL" | awk '{print $1}')
  if [ "$STATUS" = 1 ] \
    && { grep -Fq '"reason_code":"invalid_arguments"' "$out" \
      || grep -Fq '[invalid_arguments]' "$out.err"; } \
    && [ ! -s "$WPRISM_CALLS" ] \
    && [ "$before" = "$after" ]; then
    pass "$label typed-refuses before target reads or proposal publication"
  else
    fail "$label was not a pre-assessment invalid_arguments refusal"
  fi
}

check_invalid_selector duplicate-format --format=json --format=json
check_invalid_selector mixed-json-format --json --format=json
check_invalid_selector format-non-json --format=xml
check_invalid_selector format-missing-value --format
check_invalid_selector json-with-value --json=compact
check_invalid_selector detached-json json
check_invalid_selector hostile-format --format=json=hostile

run "$TMP/spaced-format.json" assess fixture --format json
STATUS=$?
if [ "$STATUS" = 3 ] && php -r '
$d = json_decode(file_get_contents($argv[1]), true);
exit(($d["format"] ?? null) === "wprism-assess-report/v1" ? 0 : 1);
' "$TMP/spaced-format.json"; then
  pass 'the exact spaced --format json selector remains accepted'
else
  fail 'the exact spaced --format json selector was not accepted'
fi

run "$TMP/json-alias.json" assess fixture --json
STATUS=$?
if [ "$STATUS" = 3 ] && php -r '
$d = json_decode(file_get_contents($argv[1]), true);
exit(($d["format"] ?? null) === "wprism-assess-report/v1" ? 0 : 1);
' "$TMP/json-alias.json"; then
  pass 'the exact --json selector remains accepted'
else
  fail 'the exact --json selector was not accepted'
fi

# -------------------------------------- complete report and bounded JSON view
say 'the machine report and its bounded view'
MACHINE=$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true)["surfaces"]);' "$TMP/full.json")
[ "$MACHINE" = "$TOTAL" ] && pass "bare --format=json carries every surface ($MACHINE) for contract automation" \
  || fail "--format=json carried $MACHINE of $TOTAL surfaces"
run "$TMP/full5.json" assess fixture --limit=5 --format=json
STATUS=$?
if [ "$STATUS" = 3 ] && php -r '
$d = json_decode(file_get_contents($argv[1]), true);
exit(($d["format"] ?? null) === "wprism-assess-view/v1"
    && ($d["authoritative"] ?? null) === false
    && ($d["summary"]["counts"]["surfaces"] ?? null) === (int) $argv[2]
    && ($d["page"]["shown"] ?? null) === 5
    && count($d["rows"] ?? []) === 5 ? 0 : 1);
' "$TMP/full5.json" "$TOTAL"; then
  pass '--limit=5 selects a five-row non-authoritative JSON view with exact full-report counts'
else
  fail '--limit=5 did not produce the bounded wprism-assess-view/v1 page'
fi

FULL_BYTES=$(wc -c < "$TMP/full.json" | tr -d ' ')
VIEW_BYTES=$(wc -c < "$TMP/full5.json" | tr -d ' ')
[ "$VIEW_BYTES" -lt "$FULL_BYTES" ] \
  && pass "the five-row view is smaller than the complete report ($VIEW_BYTES < $FULL_BYTES bytes)" \
  || fail "the five-row view did not bound output bytes ($VIEW_BYTES >= $FULL_BYTES)"

CURSOR=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["page"]["next_cursor"] ?? "";' "$TMP/full5.json")
if php -r '
$raw = base64_decode(strtr($argv[1], "-_", "+/"), true);
exit(is_string($raw) && strlen($raw) === 37 && ord($raw[0]) === 2 ? 0 : 1);
' "$CURSOR"; then
  pass 'an emitted cursor uses the explicit v2 version/offset/authenticator wire'
else
  fail 'the emitted cursor does not use the canonical v2 wire'
fi
run "$TMP/next.json" assess fixture --cursor="$CURSOR" --limit=200 --format=json
STATUS=$?
if [ "$STATUS" = 3 ] && php -r '
$first = json_decode(file_get_contents($argv[1]), true);
$next = json_decode(file_get_contents($argv[2]), true);
$total = $first["summary"]["counts"]["rows"];
$seen = [];
foreach (array_merge($first["rows"], $next["rows"]) as $row) {
    $key = ($row["kind"] ?? "") === "surface"
        ? "surface:" . ($row["surface"]["id"] ?? "")
        : "unknown:" . ($row["name"] ?? "");
    $seen[$key] = true;
}
exit(($next["page"]["offset"] ?? null) === 5
    && count($first["rows"]) + count($next["rows"]) === $total
    && count($seen) === $total
    && ($next["page"]["has_more"] ?? true) === false ? 0 : 1);
' "$TMP/full5.json" "$TMP/next.json"; then
  pass 'the emitted cursor enumerates every remaining surface/unknown row exactly once'
else
  fail 'the emitted cursor did not enumerate the bounded view without gaps or duplicates'
fi

run "$TMP/cursor-human.txt" assess fixture --cursor="$CURSOR"
STATUS=$?
[ "$STATUS" = 1 ] && pass '--cursor refuses outside the JSON view instead of being ignored' \
  || fail "a human --cursor request exited $STATUS"

run "$TMP/cursor-malformed.json" assess fixture --cursor=not-a-cursor --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"reason_code":"invalid_arguments"' "$TMP/cursor-malformed.json"; then
  pass 'a malformed cursor produces the typed JSON refusal'
else
  fail 'a malformed cursor was not refused by the JSON view grammar'
fi

TAMPERED_CURSOR=$(php -r '
$raw = base64_decode(strtr($argv[1], "-_", "+/"), true);
if (!is_string($raw) || strlen($raw) !== 37) { exit(1); }
$raw[4] = chr((ord($raw[4]) + 1) % 256);
echo rtrim(strtr(base64_encode($raw), "+/", "-_"), "=");
' "$CURSOR")
run "$TMP/cursor-offset-tampered.json" assess fixture --cursor="$TAMPERED_CURSOR" --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"reason_code":"assess_view_cursor_stale"' "$TMP/cursor-offset-tampered.json"; then
  pass 'changing only the cursor offset breaks its authenticator and refuses'
else
  fail 'an unauthenticated cursor offset change was accepted'
fi

LEGACY_CURSOR=$(php -r '
$raw = str_repeat("\0", 32) . pack("N", 5);
echo rtrim(strtr(base64_encode($raw), "+/", "-_"), "=");
')
run "$TMP/cursor-v1.json" assess fixture --cursor="$LEGACY_CURSOR" --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"reason_code":"assess_view_cursor_version_unsupported"' "$TMP/cursor-v1.json"; then
  pass 'a canonical v1 cursor refuses loudly instead of being reinterpreted'
else
  fail 'a canonical v1 cursor did not produce the version refusal'
fi

php -r '
$file = $argv[1];
$d = json_decode(file_get_contents($file), true);
$d["target"]["wordpress"] = "7.0.4";
file_put_contents($file, json_encode($d, JSON_UNESCAPED_SLASHES));
' "$WPRISM_FIXTURES/inventory.json"
run "$TMP/cursor-stale.json" assess fixture --cursor="$CURSOR" --format=json
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq '"reason_code":"assess_view_cursor_stale"' "$TMP/cursor-stale.json"; then
  pass 'a changed assessment refuses an old cursor instead of paging a different snapshot'
else
  fail 'an old cursor did not refuse after the assessed stack changed'
fi

# MUP §4.6 also binds the generated document: the names sample is capped
# while the counts beside it stay exact.
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
$sample = $d["unknown"]["names_sample"];
if (count($sample) > 200) { $fail("the generated names sample is unbounded"); }
// The sample mixes three origins — the pending queue, the option prefixes
// Coverage found invisible, and the undeclared tables — so each entry is
// tagged with the one it came from. Without the tag an operator would have
// to guess which of the three counts above a given name belongs to.
foreach ($sample as $name) {
    if (!preg_match("/^(pending|option-prefix|table):/", (string) $name)) {
        $fail("an unknown-section name is not origin-tagged: " . (string) $name);
    }
}
if ($d["unknown"]["pending_count"] !== 70) { $fail("the pending count is not the true total"); }
echo "ok: the generated unknown block is bounded, origin-tagged, and its counts are exact\n";
' "$TMP/full.json" || fail 'the generated unknown block is not bounded'

# ------------------------------------------------------- no values, ever
say 'names and counts only'
if grep -Fq 'sample_queued_000' "$TMP/default.txt"; then
  pass 'the unknown section names what it found'
else
  fail 'the unknown section printed no names at all'
fi
for secret in 'option_value' 'INSERT INTO' 'sha256:'; do
  if grep -Fq "$secret" "$TMP/default.txt"; then
    fail "the human view leaked '$secret'"
  else
    pass "the human view carries no '$secret'"
  fi
done

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_ASSESS_BOUNDS FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_ASSESS_BOUNDS PASSED\n'
