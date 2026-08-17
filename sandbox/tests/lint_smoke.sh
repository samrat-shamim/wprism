#!/usr/bin/env bash
# Lint smoke test (task #11's linter half): asserts the generalized
# suspicious-ref linter (agent/src/Review/Lint.php, `wp duo lint`) is silent on
# real, already-captured state, then that it flags EXACTLY the four
# detection classes (bare_id, escaped_home, unregistered_block_attr,
# serialized_desc_ids) when each is deliberately planted.
#
# Runs against env e1 (:8804, profile spikee) — READ-ONLY against e1's real
# site repo (sandbox/siterepo/e1): the baseline check lints the tree spike E
# already captured and committed there (this script does not reseed it —
# prerequisite: `make spike-e` has been run at least once). The
# planted-fixture check works against a throwaway copy of state/ under a
# gitignored .tmp*-prefixed subdirectory of that same site repo (so `wp duo
# lint --repo=` can reach it through the existing docker bind mount without
# a new one — same trick spike_f/conformance use for their own .tmp-state*
# scratch dirs), never the real state/ tree. Lint is read-only by
# construction (SELECTs against wp_posts/wp_terms only, no ledger/state
# writes), so even the baseline check against the real repo is safe.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
COMPOSE="docker compose -f docker-compose.yml --profile spikee"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

wp_env() { # wp_env <e1> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_e1() { wp_env e1 "$@"; }

wait_for() { # wait_for <e1>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

FIXDIR=siterepo/e1/.tmp-lint-fixture
PLANT_SCRIPT=tmp/lint_smoke_plant.php
cleanup() { rm -rf "$FIXDIR" "$PLANT_SCRIPT"; }
trap cleanup EXIT

say "boot env e1 (:8804) — never reset/reseeded here, must already carry spike E's ACF fixture"
mkdir -p siterepo/e1 tmp
$COMPOSE up -d db-e1 wp-e1
wait_for e1
[ -d siterepo/e1/state ] && [ -n "$(ls -A siterepo/e1/state 2>/dev/null)" ] \
  || fail "siterepo/e1/state is empty — run 'make spike-e' first (this script consumes spike E's fixture, it does not seed it)"
pass "env e1 ready, site repo has captured state"

say "(baseline) wp duo lint against e1's REAL captured state must be clean (false-positive baseline)"
BASELINE=$(wp_e1 duo lint --repo=/siterepo --format=json | tail -1)
echo "$BASELINE" | jq . 2>/dev/null || echo "$BASELINE"
[ "$(echo "$BASELINE" | jq 'length')" = "0" ] \
  || fail "baseline is NOT clean: $(echo "$BASELINE" | jq -c .) — a genuine finding in real state is a discovery (report it), a false positive is a bug (fix it) — either way, do not proceed until understood"
pass "baseline: zero findings on the real, already-captured spike-E tree"

say "(baseline) human-readable mode on a clean tree: success message, exit 0"
wp_e1 duo lint --repo=/siterepo
pass "human-readable clean output, exit 0"

say "look up e1's real ids to plant genuine (non-coincidental) references"
TARGET_ONE_ID=$(wp_e1 post list --post_type=post --name=duo-related-target-one --field=ID)
TARGET_TWO_ID=$(wp_e1 post list --post_type=post --name=duo-related-target-two --field=ID)
CONTENT_ID=$(wp_e1 post list --post_type=post --name=duo-acf-content --field=ID)
HOME_URL=$(wp_e1 option get home)
[ -n "$TARGET_ONE_ID" ] && [ -n "$TARGET_TWO_ID" ] && [ -n "$CONTENT_ID" ] && [ -n "$HOME_URL" ] \
  || fail "could not resolve e1's real ids/home (target-one=$TARGET_ONE_ID target-two=$TARGET_TWO_ID content=$CONTENT_ID home=$HOME_URL)"
pass "target-one=#$TARGET_ONE_ID target-two=#$TARGET_TWO_ID content=#$CONTENT_ID home=$HOME_URL"

say "throwaway copy of state/ (gitignored .tmp*, never the real tree)"
rm -rf "$FIXDIR"
mkdir -p "$FIXDIR"
cp siterepo/e1/site.duo.json "$FIXDIR/site.duo.json"
cp -r siterepo/e1/state "$FIXDIR/state"
pass "copied siterepo/e1/{site.duo.json,state} -> $FIXDIR"

CONTENT_FILE=$(find "$FIXDIR/state/posts/post" -name '*duo-acf-content*.md')
TARGET_ONE_FILE=$(find "$FIXDIR/state/posts/post" -name '*duo-related-target-one*.md')
TARGET_TWO_FILE=$(find "$FIXDIR/state/posts/post" -name '*duo-related-target-two*.md')
TERM_FILE=$(find "$FIXDIR/state/terms/category" -name '*uncategorized*.json')
for f in "$CONTENT_FILE" "$TARGET_ONE_FILE" "$TARGET_TWO_FILE" "$TERM_FILE"; do
  [ -n "$f" ] && [ -f "$f" ] || fail "could not locate an expected fixture file under $FIXDIR (got '$f')"
done
pass "located: $(basename "$CONTENT_FILE"), $(basename "$TARGET_ONE_FILE"), $(basename "$TARGET_TWO_FILE"), $(basename "$TERM_FILE")"

say "plant all four detection classes into the throwaway copy (Canon, the same class the agent uses, for a byte-correct round trip)"
cat > "$PLANT_SCRIPT" <<'PHP'
<?php
// argv: 1=Canon.php 2=content-file 3=target-one-file 4=target-two-file
//       5=term-file 6=target-one-id 7=target-two-id 8=content-id 9=home-url
require $argv[1];
use Duo\Canon;

// (a) bare_id: a real post id, into a brand-new key with no policy rule at
// all (so it has no ref declared either) — added to the ACF-carrying post's
// existing meta object.
[$front, $body] = Canon::parse_post_file(Canon::read_file($argv[2]));
$front['meta']['duo_lint_smoke_bare_id'] = (int) $argv[6];
Canon::write_file($argv[2], Canon::post_file($front, $body));

// (b) escaped_home: this environment's home URL in JSON-escaped form,
// appended straight into a body — the tokenizer only ever matches the
// plain (unescaped) form.
$homeEscaped = str_replace('/', '\/', rtrim($argv[9], '/'));
[$front, $body] = Canon::parse_post_file(Canon::read_file($argv[3]));
$body .= "\n\nLeaked (escaped): " . $homeEscaped . "\/leaked-path";
Canon::write_file($argv[3], Canon::post_file($front, $body));

// (c) unregistered_block_attr: a block name that will never have a
// block_attrs registry rule, self-closing (core/navigation-link's shape
// before FSE work gave it one), holding a numeric "id" attr.
[$front, $body] = Canon::parse_post_file(Canon::read_file($argv[4]));
$body .= "\n<!-- wp:fake/widget {\"id\":" . (int) $argv[7] . "} /-->";
Canon::write_file($argv[4], Canon::post_file($front, $body));

// (d) serialized_desc_ids: a PHP-serialized id map into a term description
// — Polylang's post_translations/term_translations shape.
$front = Canon::decode(Canon::read_file($argv[5]));
$front['description'] = 'a:1:{s:2:"en";i:' . (int) $argv[8] . ';}';
Canon::write_file($argv[5], Canon::encode($front));

fwrite(STDOUT, "planted 4 fixtures\n");
PHP
php "$PLANT_SCRIPT" ../agent/src/Kernel/Canon.php "$CONTENT_FILE" "$TARGET_ONE_FILE" "$TARGET_TWO_FILE" "$TERM_FILE" \
  "$TARGET_ONE_ID" "$TARGET_TWO_ID" "$CONTENT_ID" "$HOME_URL"
pass "planted: bare_id(->#$TARGET_ONE_ID) in $(basename "$CONTENT_FILE"), escaped_home in $(basename "$TARGET_ONE_FILE"), fake/widget(id=$TARGET_TWO_ID) in $(basename "$TARGET_TWO_FILE"), serialized desc(->#$CONTENT_ID) in $(basename "$TERM_FILE")"

say "wp duo lint against the throwaway fixture: must flag EXACTLY the four planted findings and exit 1"
if FINDINGS=$(wp_e1 duo lint --repo=/siterepo/.tmp-lint-fixture --format=json | tail -1); then
  fail "wp duo lint exited 0 against the planted fixture — expected exit 1 (findings present)"
fi
echo "$FINDINGS" | jq . 2>/dev/null || echo "$FINDINGS"
pass "wp duo lint exited non-zero (the \`if\` above only reaches here on failure) — gate behavior confirmed"

check() { echo "$FINDINGS" | jq -e "$1" >/dev/null 2>&1 || fail "$2"; }

check '[.[].class] | sort == ["bare_id","escaped_home","serialized_desc_ids","unregistered_block_attr"]' \
  "expected exactly these 4 classes (one each), got: $(echo "$FINDINGS" | jq -c '[.[].class]')"

check "[.[] | select(.class==\"bare_id\" and (.path | contains(\"duo-acf-content\")) and .value==$TARGET_ONE_ID and .matches.kind==\"post\" and .matches.id==$TARGET_ONE_ID)] | length == 1" \
  "bare_id finding missing/wrong (want: path~duo-acf-content, value=matches.id=$TARGET_ONE_ID, matches.kind=post)"

check "[.[] | select(.class==\"escaped_home\" and (.path | contains(\"duo-related-target-one\")) and .locator==\"body\")] | length == 1" \
  "escaped_home finding missing/wrong (want: path~duo-related-target-one, locator=body)"

check "[.[] | select(.class==\"unregistered_block_attr\" and (.path | contains(\"duo-related-target-two\")) and .value==$TARGET_TWO_ID and .matches.id==$TARGET_TWO_ID and (.locator | contains(\"fake/widget\")))] | length == 1" \
  "unregistered_block_attr finding missing/wrong (want: path~duo-related-target-two, value=matches.id=$TARGET_TWO_ID, locator~fake/widget)"

check "[.[] | select(.class==\"serialized_desc_ids\" and (.path | contains(\"uncategorized\")) and .value==$CONTENT_ID and .matches.id==$CONTENT_ID)] | length == 1" \
  "serialized_desc_ids finding missing/wrong (want: path~uncategorized, value=matches.id=$CONTENT_ID)"

pass "all 4 planted findings correctly flagged: right class, right file, right value, right match"

say "cleanup: remove the throwaway fixture + plant script, confirm e1's real site repo is untouched"
rm -rf "$FIXDIR" "$PLANT_SCRIPT"
STATUS=$(git -C siterepo/e1 status --porcelain)
[ -z "$STATUS" ] || fail "siterepo/e1 is not clean after the smoke test:\n$STATUS"
pass "siterepo/e1 working tree clean — no writes to e1's real site repo"

printf '\n\033[1;32m✔ LINT SMOKE PASSED\033[0m\n'
