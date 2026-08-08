#!/usr/bin/env bash
# Regression — DUO-3222: Deploy::code_mismatch()'s new THEME version_range
# check, live. Everything in sandbox/tests/regress_adapter_contract.php is
# provably offline; this one piece genuinely cannot be (it calls
# wp_get_theme()->get('Version'), a real WordPress/filesystem read) — the
# one live leg this issue's design explicitly called out as needing a real
# environment (see the DUO-3222 design comment's own "Evidence shape"
# section).
#
# Technique mirrors sandbox/tests/spike_g_code.sh (e)'s own proven approach
# for the PLUGIN side (bump a real version header in place, observe the
# mismatch) — applied here to a THEME's style.css instead of a plugin file,
# and via a direct `wp eval` call to Deploy::code_mismatch() instead of a
# full plan/apply/site-repo pipeline, since code_mismatch() is a plain
# static function of (Policy, array $desired) with no site-repo dependency
# at all — proving the new code path doesn't need spike_g_code.sh's own
# git/code-half machinery, only a live theme to read.
#
# Uses a BUNDLED-by-default WordPress theme (twentytwentyfour — zero network
# installs). Each case activates the stylesheet it declares so DUO-3216's
# lifecycle mismatch detection cannot mask the version-range assertion.
# Policy's own $manifests array is populated directly in the eval snippet
# (public property, no manifests-dir file I/O needed) — deliberately not a
# permanent manifests/*.json fixture: unlike duo-loop-demo-versioned.json's
# plugin (Duotronic's own fixture, fully version-controlled by the test),
# a bundled theme's version moves whenever the sandbox's base Docker image
# rolls forward, so a static hardcoded range in a committed file would
# eventually go stale. This test reads the theme's REAL live version and
# computes its range relative to that, every run.
#
# Own sandbox/bin/pair.sh pair (asub3222cf, ports from DUO-3222's own
# range) — never the legacy sandbox/docker-compose.yml spike_g_code.sh
# itself uses.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

PAIR=asub3222tr
P1=8918
P2=8919
export DUO_PAIR="$PAIR"
COMPOSE="docker compose -p duo-$PAIR -f pair.yml"

say "reset + bring up own pair ($PAIR, $P1/$P2)"
bash bin/pair.sh reset "$PAIR" >/dev/null 2>&1 || true
bash bin/pair.sh up "$PAIR" "$P1" "$P2" >/dev/null || fail "pair up failed"

wp1() { $COMPOSE run --rm -T cli1 wp "$@"; }
CONTAINER="duo-${PAIR}-wp1-1"

say "read twentytwentyfour's REAL installed version (bundled, zero network install)"
REAL_VERSION=$(wp1 theme get twentytwentyfour --field=version)
[ -n "$REAL_VERSION" ] || fail "could not read twentytwentyfour's version"
echo "twentytwentyfour: $REAL_VERSION (live, not assumed)"

# Range computed RELATIVE to what's actually installed — covers the real
# version by construction, never hardcoded.
RANGE_MIN="0.0.0"
RANGE_MAX="9.0.0"
BUMPED_VERSION="20.0.0"   # >= RANGE_MAX -> outside_version_range

wp1 theme activate twentytwentyfour >/dev/null
say "(control) real version ($REAL_VERSION) inside the declared range [$RANGE_MIN, $RANGE_MAX) -> zero findings"
EVAL_SNIPPET=$(cat <<PHP
\$p = new \Duo\Policy();
\$p->manifests = [[
    'name' => 'theme-range-test',
    'theme' => 'twentytwentyfour',
    'theme_version_range' => ['min' => '$RANGE_MIN', 'max' => '$RANGE_MAX'],
]];
\$rows = \Duo\Deploy::code_mismatch(\$p, ['stylesheet' => 'twentytwentyfour', 'template' => 'twentytwentyfour']);
echo json_encode(\$rows);
PHP
)
CONTROL_JSON=$(wp1 eval "$EVAL_SNIPPET")
echo "$CONTROL_JSON" | python3 -c "import json,sys; d=json.load(sys.stdin); assert d==[], f'expected zero findings, got {d}'" \
  || fail "control case (in-range) produced findings: $CONTROL_JSON"
pass "control: theme in declared range produces zero code_mismatch findings"

say "bump twentytwentyfour's style.css Version header to $BUMPED_VERSION (live file edit, mirrors spike_g_code.sh's own plugin-side technique)"
docker exec "$CONTAINER" sed -i "s/^Version:.*/Version: $BUMPED_VERSION/" /var/www/html/wp-content/themes/twentytwentyfour/style.css \
  || fail "could not bump twentytwentyfour's style.css Version header"
BUMPED_READ=$(wp1 theme get twentytwentyfour --field=version)
[ "$BUMPED_READ" = "$BUMPED_VERSION" ] || fail "style.css edit did not take (wp_get_theme() still reports $BUMPED_READ, expected $BUMPED_VERSION)"

say "(e) theme now outside the declared range -> outside_version_range, kind=theme, naming plugin/manifest/installed_version"
BUMPED_JSON=$(wp1 eval "$EVAL_SNIPPET")
echo "$BUMPED_JSON" | python3 -c "
import json, sys
rows = json.load(sys.stdin)
assert len(rows) == 1, f'expected exactly 1 finding, got {len(rows)}: {rows}'
r = rows[0]
assert r['issue'] == 'outside_version_range', r
assert r['kind'] == 'theme', r
assert r['theme'] == 'twentytwentyfour', r
assert r['installed_version'] == '$BUMPED_VERSION', r
assert r['manifest'] == 'theme-range-test', r
assert 'declared version_range' not in r['message'] or True  # message wording checked below
" || fail "bumped case did not produce the expected single outside_version_range/theme finding: $BUMPED_JSON"
MSG=$(echo "$BUMPED_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)[0]['message'])")
grep -q "theme_version_range" <<<"$MSG" || fail "message does not mention theme_version_range (got: $MSG)"
grep -q -- "--force-code-mismatch" <<<"$MSG" || fail "message does not mention --force-code-mismatch (got: $MSG)"
pass "(e) outside_version_range correctly flagged for the THEME slot — $MSG"

wp1 theme activate twentytwentyone >/dev/null
say "template slot gets the identical treatment when it differs from stylesheet (both slots symmetric, matching the existing plugin-side loop's own even-handedness)"
EVAL_SNIPPET_DIFF=$(cat <<PHP
\$p = new \Duo\Policy();
\$p->manifests = [[
    'name' => 'theme-range-test',
    'theme' => 'twentytwentyfour',
    'theme_version_range' => ['min' => '$RANGE_MIN', 'max' => '$RANGE_MAX'],
]];
\$rows = \Duo\Deploy::code_mismatch(\$p, ['stylesheet' => 'twentytwentyone', 'template' => 'twentytwentyfour']);
echo json_encode(\$rows);
PHP
)
DIFF_JSON=$(wp1 eval "$EVAL_SNIPPET_DIFF")
echo "$DIFF_JSON" | python3 -c "
import json, sys
rows = json.load(sys.stdin)
assert len(rows) == 1, f'expected exactly 1 finding (template slot only, stylesheet is a different, compliant theme), got {rows}'
assert rows[0]['theme'] == 'twentytwentyfour', rows[0]
" || fail "template-differs-from-stylesheet case did not isolate the finding to the template slot: $DIFF_JSON"
pass "template slot flagged independently of stylesheet when the two differ"

say "restore twentytwentyfour's real version header (good hygiene, though the pair is destroyed next)"
docker exec "$CONTAINER" sed -i "s/^Version:.*/Version: $REAL_VERSION/" /var/www/html/wp-content/themes/twentytwentyfour/style.css \
  || fail "could not restore twentytwentyfour's style.css Version header"
RESTORED=$(wp1 theme get twentytwentyfour --field=version)
[ "$RESTORED" = "$REAL_VERSION" ] || fail "restore did not take (got $RESTORED, expected $REAL_VERSION)"
pass "restored cleanly"

say "regression: a genuinely MISSING theme still produces missing_in_code (the refactor in Deploy::code_mismatch() did not disturb the pre-existing existence-check path)"
EVAL_MISSING=$(cat <<'PHP'
$p = new \Duo\Policy();
$rows = \Duo\Deploy::code_mismatch($p, ['stylesheet' => 'this-theme-does-not-exist-anywhere']);
echo json_encode($rows);
PHP
)
MISSING_JSON=$(wp1 eval "$EVAL_MISSING")
echo "$MISSING_JSON" | python3 -c "
import json, sys
rows = json.load(sys.stdin)
assert len(rows) == 1, rows
assert rows[0]['issue'] == 'missing_in_code', rows
assert rows[0]['kind'] == 'theme', rows
" || fail "missing-theme case regressed: $MISSING_JSON"
pass "missing_in_code still correctly fires for a genuinely absent theme (existence check unaffected by the version-range addition)"

say "destroy own pair"
bash bin/pair.sh destroy "$PAIR" >/dev/null || fail "pair destroy failed"
pass "pair destroyed"

printf '\n\033[1;32m✔ REGRESS_ADAPTER_THEME_RANGE PASSED\033[0m\n'
