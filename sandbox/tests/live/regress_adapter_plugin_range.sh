#!/usr/bin/env bash
# Regression — DUO-3487: Deploy::code_mismatch()'s PLUGIN version_range leg,
# live. This is the plugin twin of sandbox/tests/live/regress_adapter_theme_range.sh
# (DUO-3222's theme leg) and it exists because the plugin half lost its live
# proof in #478: it was carried by spike_g_code.sh (e), built on the
# duo-loop-demo-versioned demo manifest, and both were deleted together.
#
# WHAT IS AND IS NOT COVERED ELSEWHERE — read before assuming this file is a
# duplicate. Deploy::in_range()'s min-inclusive/max-exclusive arithmetic is
# exercised offline by reflection in
# sandbox/tests/offline/adapter/regress_adapter_contract.php; that is pure
# arithmetic over two strings and proves nothing about where the installed
# version comes from. The PRODUCT-PATH refusal half — `wp duo deploy` exiting
# non-zero, naming the plugin and its installed version — is proven live by
# sandbox/tests/certify/certify_version_matrix.sh's per-subject negative
# controls (acf 5.12.6, contact-form-7 5.9.8, elementor 3.35.9, …), which
# install a real digest-verified wp.org artifact genuinely below a SHIPPED
# manifest's declared min. Those are certify-class, one subject per
# invocation, and they need network or a warm artifact cache.
#
# What no other suite covers, and what this one is for: the ROW that
# code_mismatch() actually returns for the plugin slot, asserted field by
# field, in BOTH directions, against a REAL installed plugin whose version
# WordPress itself parsed — and the boundary semantics of that live read at
# the exact declared endpoints. certify_version_matrix greps a refusal
# string; nothing checked that `issue`/`kind`/`plugin`/`installed_version`/
# `version_range`/`manifest` are populated, or that an in-range version is
# genuinely quiet rather than merely non-fatal.
#
# Technique is regress_adapter_theme_range.sh's, applied to the plugin slot:
# a direct `wp eval` call to Deploy::code_mismatch(), which is a plain static
# function of (Policy, array $desired) with no site-repo dependency, so this
# needs neither a site repository nor the code-half git machinery — only a
# live plugin to read. Policy's own $manifests array is populated in the eval
# snippet (public property, no manifests-dir file I/O): a committed
# manifests/*.json fixture is deliberately NOT used, both because no shipped
# manifest may change (AGENTS.md #2 — manifest bytes are adapter identity)
# and for the reason the theme leg gives, which applies identically here — a
# bundled artifact's version moves whenever the sandbox base image rolls
# forward, so a hardcoded committed range would eventually go stale. Every
# range endpoint below is DERIVED from the version this run actually read.
#
# $desired['active_plugins'] is always the environment's own live active list
# (Deploy::current_active_plugins()), so DUO-3216's lifecycle rows
# (inactive_in_environment / unexpected_active_plugin) are structurally empty
# and cannot mask or pad the version-range assertion — the theme leg's
# "activate the stylesheet it declares" discipline, in the shape the plugin
# loop needs it.
#
# Uses BUNDLED-by-default WordPress plugins (akismet, hello.php — zero
# network installs), and both plugin basename shapes on purpose: akismet is
# `slug/slug.php`, hello.php is a legacy single-file plugin. Policy::
# version_ranges() is plugin-basename-keyed, so the legacy shape is a
# genuinely different key and validate_plugin() is the primitive that has to
# accept it.
#
# Own sandbox/bin/pair.sh pair (a3487pr 8988/8989 by default), headless,
# destroyed on a green run; a failed run leaves it up for inspection, the
# estate's standing convention (docs/sandbox.md).
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

PAIR="${PLUGIN_RANGE_PAIR:-a3487pr}"
P1="${PLUGIN_RANGE_PORT1:-8988}"
P2="${PLUGIN_RANGE_PORT2:-8989}"
export DUO_PAIR="$PAIR"
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
CONTAINER="duo-${PAIR}-wp1-1"
AKISMET_FILE=/var/www/html/wp-content/plugins/akismet/akismet.php
AKISMET_BASENAME=akismet/akismet.php
HELLO_BASENAME=hello.php

GREEN=0
cleanup() {
  if [ "$GREEN" = 1 ]; then
    bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
    # destroy leaves the two site-repo roots on disk by design; this suite
    # never puts a repository in them (it needs no site repo at all), so
    # the empty shells pair.sh created are pure litter.
    rm -rf "siterepo/${PAIR}1" "siterepo/${PAIR}2"
    printf '\033[1;32mok: pair %s destroyed\033[0m\n' "$PAIR"
  else
    printf '\033[1;33m(pair %s left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
  fi
}
trap cleanup EXIT

# One eval snippet shape for every case: declare ONE pinned manifest naming
# one plugin basename and one range, then ask code_mismatch() about the
# environment's own live active list.
probe() { # probe <manifest-name> <plugin-basename> <min> <max>
  wp1 eval "
\$p = new \\Duo\\Policy();
\$p->manifests = [[
    'name' => '$1',
    'plugin' => '$2',
    'version_range' => ['min' => '$3', 'max' => '$4'],
]];
echo json_encode(\\Duo\\Deploy::code_mismatch(\$p, ['active_plugins' => \\Duo\\Deploy::current_active_plugins()]));
" | tail -1
}

set_akismet_version() { # set_akismet_version <version-or-empty>
  local value="$1" replacement
  # An EMPTY value leaves the header key present with nothing after the
  # colon, which is what makes WordPress's own get_file_data() report ''
  # for Version — the `$installed === ''` branch of the range check. Deleting
  # the line would not be reversible with the same one-line edit.
  if [ -n "$value" ]; then replacement="Version: $value"; else replacement="Version:"; fi
  docker exec "$CONTAINER" sed -i "s/^Version:.*/$replacement/" "$AKISMET_FILE" \
    || fail "could not rewrite akismet's Version header to '$value'"
}

assert_rows() { # assert_rows <label> <json> <python-assertions>
  printf '%s' "$2" | python3 -c "
import json, sys
rows = json.load(sys.stdin)
$3
" || fail "$1 — unexpected code_mismatch rows: $2"
}

say "reset + bring up own pair ($PAIR, $P1/$P2, headless)"
bash bin/pair.sh reset "$PAIR" >/dev/null 2>&1 || true
bash bin/pair.sh up "$PAIR" "$P1" "$P2" --headless >/dev/null || fail "pair up failed"

say "activate both bundled plugins for real, so every case below reads an ACTIVE plugin's live version"
wp1 plugin activate akismet >/dev/null || fail "could not activate the bundled akismet plugin"
wp1 plugin activate hello >/dev/null || fail "could not activate the bundled hello.php plugin"
ACTIVE=$(wp1 plugin list --status=active --field=name | tr -d '\r' | sort | tr '\n' ' ')
echo "active plugins: $ACTIVE"

say "read akismet's REAL installed version through WordPress's own header parser (bundled, zero network install)"
REAL_VERSION=$(wp1 plugin get akismet --field=version | tr -d '\r')
[ -n "$REAL_VERSION" ] || fail "could not read akismet's installed version"
echo "akismet: $REAL_VERSION (live, not assumed)"

# Both endpoints are DERIVED from the version this run actually read, so the
# installed artifact is inside the declared range by construction and the two
# endpoint cases below can set the header to the exact endpoint string.
# Appending a component to a version always compares strictly greater under
# version_compare(), which is what makes RANGE_MAX a real upper bound for
# whatever version the base image happens to ship.
RANGE_MIN="$REAL_VERSION"
RANGE_MAX="$REAL_VERSION.9999"
INSIDE_VERSION="$REAL_VERSION.1"
BELOW_MIN_VERSION="0.0.0"
echo "declared range for akismet: >=$RANGE_MIN <$RANGE_MAX"

say "(a) control / min INCLUSIVE: the untouched real version IS the declared min -> zero findings"
CONTROL_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(a) control" "$CONTROL_JSON" "
assert rows == [], f'expected zero findings for the installed version at the declared min, got {rows}'
"
pass "(a) installed version $REAL_VERSION == declared min is INSIDE the range — the check is quiet when compliant, and min is inclusive against a real live read"

say "(b) strictly inside: bump the live Version header to $INSIDE_VERSION -> still zero findings"
set_akismet_version "$INSIDE_VERSION"
READ_BACK=$(wp1 plugin get akismet --field=version | tr -d '\r')
[ "$READ_BACK" = "$INSIDE_VERSION" ] \
  || fail "the akismet.php header edit did not take (get_plugins() still reports '$READ_BACK', expected $INSIDE_VERSION)"
INSIDE_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(b) strictly inside" "$INSIDE_JSON" "
assert rows == [], f'expected zero findings strictly inside the range, got {rows}'
"
pass "(b) a live header edit is visible to get_plugins() immediately, and $INSIDE_VERSION inside (>=$RANGE_MIN <$RANGE_MAX) stays quiet"

say "(c) BELOW min: set the live Version header to $BELOW_MIN_VERSION -> outside_version_range naming the plugin leg"
set_akismet_version "$BELOW_MIN_VERSION"
BELOW_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(c) below min" "$BELOW_JSON" "
assert len(rows) == 1, f'expected exactly one finding, got {rows}'
r = rows[0]
assert r['issue'] == 'outside_version_range', r
assert r['kind'] == 'plugin', r
assert r['plugin'] == '$AKISMET_BASENAME', r
assert r['installed_version'] == '$BELOW_MIN_VERSION', r
assert r['version_range'] == {'min': '$RANGE_MIN', 'max': '$RANGE_MAX'}, r
assert r['manifest'] == 'plugin-range-test', r
"
MSG=$(printf '%s' "$BELOW_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin)[0]['message'])")
grep -q "version_range" <<<"$MSG" || fail "message does not name version_range (got: $MSG)"
grep -q -- "--force-code-mismatch" <<<"$MSG" || fail "message does not offer --force-code-mismatch (got: $MSG)"
grep -q "$BELOW_MIN_VERSION" <<<"$MSG" || fail "message does not name the actually-installed version (got: $MSG)"
pass "(c) outside_version_range fires for the PLUGIN slot with every field populated — $MSG"

say "(d) max EXCLUSIVE: set the live Version header to the declared max ($RANGE_MAX) exactly -> still outside"
# The one case that separates `<` from `<=`. It is asserted against a live
# get_plugins() read rather than only against in_range()'s two strings,
# because the version the comparison receives is exactly what this leg is
# about.
set_akismet_version "$RANGE_MAX"
MAX_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(d) max exclusive" "$MAX_JSON" "
assert len(rows) == 1, f'expected exactly one finding at the exclusive max, got {rows}'
r = rows[0]
assert r['issue'] == 'outside_version_range', r
assert r['kind'] == 'plugin', r
assert r['installed_version'] == '$RANGE_MAX', r
"
pass "(d) the declared max is EXCLUSIVE against a live version read, not merely in in_range()'s own arithmetic"

say "(e) an unreadable version (header present, value empty) is outside the range, named as such"
set_akismet_version ""
EMPTY_READ=$(wp1 plugin get akismet --field=version | tr -d '\r')
[ -z "$EMPTY_READ" ] || fail "expected WordPress to report an empty version, got '$EMPTY_READ'"
EMPTY_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(e) unknown version" "$EMPTY_JSON" "
assert len(rows) == 1, f'expected exactly one finding for an unreadable version, got {rows}'
r = rows[0]
assert r['issue'] == 'outside_version_range', r
assert r['installed_version'] == '', r
assert '(unknown version)' in r['message'], r
"
pass "(e) a plugin whose version WordPress cannot read is refused, not silently treated as compliant — the branch only a live header can reach"

say "(f) restore akismet's real header -> quiet again, so every finding above was caused by the edit and not by accumulated state"
set_akismet_version "$REAL_VERSION"
RESTORED=$(wp1 plugin get akismet --field=version | tr -d '\r')
[ "$RESTORED" = "$REAL_VERSION" ] || fail "restore did not take (got $RESTORED, expected $REAL_VERSION)"
RESTORED_JSON=$(probe plugin-range-test "$AKISMET_BASENAME" "$RANGE_MIN" "$RANGE_MAX")
assert_rows "(f) restored" "$RESTORED_JSON" "
assert rows == [], f'expected zero findings after restoring the real version, got {rows}'
"
pass "(f) restored cleanly and the finding cleared — both directions are the same environment, one variable apart"

say "(g) a genuinely ABSENT plugin with a declared range reports missing_in_code ONLY — the existence check short-circuits the version read"
MISSING_JSON=$(wp1 eval "
\$p = new \\Duo\\Policy();
\$p->manifests = [[
    'name' => 'plugin-range-test',
    'plugin' => 'duo-3487-absent/duo-3487-absent.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
]];
\$desired = array_merge(\\Duo\\Deploy::current_active_plugins(), ['duo-3487-absent/duo-3487-absent.php']);
echo json_encode(\\Duo\\Deploy::code_mismatch(\$p, ['active_plugins' => \$desired]));
" | tail -1)
assert_rows "(g) missing plugin" "$MISSING_JSON" "
assert len(rows) == 1, f'expected exactly one finding, got {rows}'
r = rows[0]
assert r['issue'] == 'missing_in_code', r
assert r['kind'] == 'plugin', r
assert r['plugin'] == 'duo-3487-absent/duo-3487-absent.php', r
assert not any(x['issue'] == 'outside_version_range' for x in rows), rows
"
pass "(g) a plugin that isn't there has no version to read, and none is invented — missing_in_code alone, never a bogus empty-version range finding"

say "(h) the LEGACY single-file plugin shape (hello.php) keys the range identically — out of range"
HELLO_VERSION=$(wp1 plugin get hello --field=version | tr -d '\r')
[ -n "$HELLO_VERSION" ] || fail "could not read hello.php's installed version"
echo "hello.php: $HELLO_VERSION (live)"
# Nothing is edited for this pair of cases: the two declared ranges are
# computed around the untouched real version, so both directions are proven
# on a pristine artifact.
HELLO_OUT_JSON=$(probe legacy-single-file-test "$HELLO_BASENAME" "0.0.1" "$HELLO_VERSION")
assert_rows "(h) legacy shape, out of range" "$HELLO_OUT_JSON" "
assert len(rows) == 1, f'expected exactly one finding, got {rows}'
r = rows[0]
assert r['issue'] == 'outside_version_range', r
assert r['kind'] == 'plugin', r
assert r['plugin'] == '$HELLO_BASENAME', r
assert r['installed_version'] == '$HELLO_VERSION', r
assert r['manifest'] == 'legacy-single-file-test', r
"
pass "(h) a legacy single-file basename is a first-class version_ranges() key — $HELLO_VERSION is outside [0.0.1, $HELLO_VERSION)"

say "(i) the same legacy plugin, one endpoint wider -> in range, so (h) was the range and not the basename shape"
HELLO_IN_JSON=$(probe legacy-single-file-test "$HELLO_BASENAME" "0.0.1" "$HELLO_VERSION.9999")
assert_rows "(i) legacy shape, in range" "$HELLO_IN_JSON" "
assert rows == [], f'expected zero findings, got {rows}'
"
pass "(i) validate_plugin() accepts the legacy shape and the range check reads its real version — quiet when compliant"

GREEN=1
printf '\n\033[1;32m✔ REGRESS_ADAPTER_PLUGIN_RANGE PASSED\033[0m\n'
