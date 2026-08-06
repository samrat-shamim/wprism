#!/usr/bin/env bash
# Conformance gate (DESIGN.md §6 / adversarial-review finding #20 — the
# manifest-treadmill answer): a generalized capture -> apply -> re-capture
# round-trip harness, run per manifest against a FRESH, disposable env pair.
# This is what CI runs; it knows nothing manifest-specific beyond what's
# declared in conformance/manifests.json and a seed script per manifest —
# all manifest-specific authoring happens in conformance/seeds/<name>.sh.
#
# Env provider: sandbox/bin/pair.sh (task #74's sandbox redesign), not a
# per-manifest docker-compose profile. `pair.sh reset conf` + `pair.sh up
# conf ...` gives a genuinely fresh WordPress install on both sides every
# run — DROP/CREATE against the one shared MariaDB server instead of the
# old per-pair volume-rm-and-reinit cycle, and DB-level readiness instead of
# the `wp core version` check every other script in this sandbox still
# uses. See docs/sandbox.md for the full model. Everything from "init the
# site repo" onward is unchanged from before this migration — only env
# provisioning (this file's first ~60 lines) moved.
#
# Usage: bash sandbox/conformance/run.sh <manifest-name>
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
MANIFEST="${1:-}"
REG=conformance/manifests.json
[ -n "$MANIFEST" ] || { echo "usage: run.sh <manifest-name> (see $REG for known names)" >&2; exit 1; }

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

ENTRY=$(jq -e --arg m "$MANIFEST" '.[$m]' "$REG") \
  || fail "unknown manifest '$MANIFEST' (see $REG)"
mapfile -t PLUGINS < <(echo "$ENTRY" | jq -r '.plugins[]?')
SETUP=$(echo "$ENTRY" | jq -r '.setup // ""')

# Prefer the legacy docker-compose.yml conf1/conf2 ports (8806/8807) so
# conformance/checks/*.sh and seeds/elementor.sh — which read CONF1_PORT/
# CONF2_PORT with those exact values as their DEFAULT, so they're unchanged
# in the common case — need no override. Fall back only if the legacy
# conf1/conf2 containers are actually still running (this migration runs
# once tasks #72/#73/#75 are complete, which says nothing about whether
# anyone has torn down their own containers since — see docs/sandbox.md's
# note that "leave it running" was the old norm this whole redesign
# responds to): a real port collision there would otherwise surface as an
# opaque `docker compose up` failure instead of this explicit, named cause.
if docker ps --format '{{.Names}}' | grep -qE 'duo-sandbox-wp-conf[12]-1'; then
  CONF1_PORT=8840
  CONF2_PORT=8841
  echo "note: legacy conf1/conf2 containers are still running — using fallback ports $CONF1_PORT/$CONF2_PORT instead of 8806/8807" >&2
else
  CONF1_PORT=8806
  CONF2_PORT=8807
fi
COMPOSE="docker compose -p duo-conf -f pair.yml -f pair.http.yml"
wp_env() { # wp_env <conf1|conf2> <wp args...>
  local env="$1"; shift
  local side="${env#conf}"   # conf1 -> 1, conf2 -> 2 (pair.sh's generic side numbering)
  $COMPOSE run --rm -T "cli${side}" wp "$@"
}
wp_conf1() { wp_env conf1 "$@"; }
wp_conf2() { wp_env conf2 "$@"; }
# pair.sh set these for ITS OWN compose invocations while bringing the pair
# up, but that was a separate process — its exports die with it. Every one
# of run.sh's own $COMPOSE calls below creates a fresh --rm container
# (never a persistent one), so pair.yml's ${DUO_PAIR}/${DUO_PORT1}/
# ${DUO_PORT2} interpolation (WORDPRESS_DB_NAME among them) needs these set
# in THIS shell too, every time — confirmed the hard way: without this,
# WORDPRESS_DB_NAME silently resolved to "wp_1" (DUO_PAIR defaulting to an
# empty string) instead of "wp_conf1", surfacing only as a generic "Error
# establishing a database connection" from wp-cli, not a missing-variable
# warning that would have pointed straight at the cause.
export DUO_PAIR=conf DUO_PORT1="$CONF1_PORT" DUO_PORT2="$CONF2_PORT"
export COMPOSE CONF1_PORT CONF2_PORT
export -f wp_env wp_conf1 wp_conf2 say pass fail

say "clean-room via pair.sh (DROP/CREATE beats volume rm + InnoDB re-init — conformance never trusts leftover state from a previous manifest's run)"
bash bin/pair.sh reset conf

say "pair.sh up: boot conf1 (:$CONF1_PORT) / conf2 (:$CONF2_PORT), DB-level readiness, generic WordPress bootstrap"
bash bin/pair.sh up conf "$CONF1_PORT" "$CONF2_PORT" --http

install_env() { # install_env <conf1|conf2> — pair.sh's `up` already fully
  # installed WordPress (core install, theme, permalinks, .htaccess) on a
  # freshly reset (empty) database and waited for real DB-level readiness;
  # this only does what's specific to conformance: the manifest-decorated
  # title (cosmetic parity with the pre-migration title), stripping the
  # default seed content, then this manifest's own plugins + setup hook.
  local env="$1"
  wp_env "$env" option update blogname "Duo ${env} (${MANIFEST})"
  wp_env "$env" site empty --yes
  if [ "${#PLUGINS[@]}" -gt 0 ]; then
    for plugin in "${PLUGINS[@]}"; do
      # is-active, not is-installed: pair.sh's reset deliberately leaves the
      # webroot volume alone (only the database is DROP/CREATE'd — that's
      # the whole reset-speed win), so a plugin's FILES can persist from an
      # earlier manifest's run on this same pair while the freshly-reset
      # database has no record of it being active. is-installed (files on
      # disk) would short-circuit past `install --activate` entirely in
      # that case — confirmed the hard way: `install --activate` DOES
      # activate an already-present-but-inactive plugin fine when actually
      # invoked (it's not a no-op), the bug was this guard never calling it.
      wp_env "$env" plugin is-active "$plugin" >/dev/null 2>&1 \
        || wp_env "$env" plugin install "$plugin" --activate
    done
  fi
  case "$SETUP" in
    "") ;;
    hpos) wp_env "$env" wc hpos enable || fail "could not enable HPOS on $env" ;;
    block-theme) wp_env "$env" theme activate twentytwentyfive || fail "could not activate twentytwentyfive on $env" ;;
    *) fail "unknown setup hook '$SETUP' for manifest '$MANIFEST'" ;;
  esac
  echo "env $env installed ($MANIFEST: ${PLUGINS[*]:-no plugins}${SETUP:+, setup=$SETUP})"
}
install_env conf1
install_env conf2
pass "both envs installed"

say "init the site repo (own origin, own clones — pins: $(echo "$ENTRY" | jq -c '.pin'))"
git init --bare -b main siterepo/origin-conf.git >/dev/null
echo "$ENTRY" | jq '{
  manifests: .pin,
  policy: {options: {}, post_meta: {}, post_types: .post_types, taxonomies: .taxonomies},
  spec_version: 0
}' > siterepo/conf1/site.duo.json
printf '.tmp*\n' > siterepo/conf1/.gitignore
git -C siterepo/conf1 init -q -b main
git -C siterepo/conf1 remote add origin ../origin-conf.git

say "seed representative authored content on conf1 (conformance/seeds/$MANIFEST.sh)"
SEED="conformance/seeds/$MANIFEST.sh"
[ -f "$SEED" ] || fail "no seed script for '$MANIFEST' (expected $SEED)"
bash "$SEED"

say "capture conf1 into the site repo"
wp_conf1 duo capture --repo=/siterepo
git -C siterepo/conf1 add -A
git -C siterepo/conf1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: seeded $MANIFEST content on conf1"
git -C siterepo/conf1 push -qu origin main

# --- suspicious-ref lint gate ------------------------------------------------
# Generalized suspicious-ref linter (agent/src/Lint.php / `wp duo lint`):
# flags ref-shaped values that reached canonical state without a declared
# rewrite path — exactly the blind spot the byte-diff acceptance checks
# below cannot see (docs/frontier/{fse,polylang,elementor}.md). HARD GATE:
# all in-tree manifests run clean against it; a finding here means either a
# manifest gap or a genuinely dangling/unrewritten ref — both are failures.
say "lint conf1's captured state (hard gate)"
LINT_JSON=$(wp_conf1 duo lint --repo=/siterepo --format=json | tail -1 || true)
LINT_N=$(echo "$LINT_JSON" | jq 'length' 2>/dev/null || echo 0)
if [ "${LINT_N:-0}" != "0" ]; then
  echo "$LINT_JSON" | jq . 2>/dev/null || echo "$LINT_JSON"
  fail "wp duo lint found $LINT_N suspicious ref(s) in captured state (manifest: $MANIFEST)"
fi
echo "lint: clean, 0 findings"
# --- end lint gate -----------------------------------------------------------

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/conf1/state siterepo/conf1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/conf1/.tmp-state2
pass "capture-twice diff is empty"

say "clone the repo for conf2, apply"
git clone -q siterepo/origin-conf.git siterepo/conf2
REV=$(git -C siterepo/conf2 rev-parse HEAD)
# adopt both terms (the default "Uncategorized" category every fresh install
# has) and posts (plugins like WooCommerce auto-create their own default
# pages — Shop/Cart/Checkout/... — on activation, independently on conf1 and
# conf2, so first apply always meets an unmanaged same-slug row for those).
APPLY_JSON=$(wp_conf2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --json | tail -1)
echo "$APPLY_JSON" | jq .
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "side-effect canary was not clean during apply"
pass "apply succeeded, side-effect canary clean"

say "acceptance: canonical(conf2) == canonical(conf1), byte for byte"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-conf2state >/dev/null
diff -r siterepo/conf1/state siterepo/conf2/.tmp-conf2state || fail "round-trip mismatch between conf1 and conf2 for manifest '$MANIFEST'"
rm -rf siterepo/conf2/.tmp-conf2state
pass "canonical state identical across environments"

# Manifest-specific render-level acceptance (conformance/checks/<name>.sh,
# optional): byte-identical canonical state is necessary but not sufficient
# once a ref-shaped value is invisible to the tokenizer — source and target
# would then simply encode the same wrong bytes (docs/frontier/fse.md's
# core methodological finding). Checks curl the live conf2 site and grep
# rendered output, not state/, so they catch what a byte-diff cannot.
CHECK="conformance/checks/$MANIFEST.sh"
if [ -f "$CHECK" ]; then
  say "manifest-specific render acceptance (conformance/checks/$MANIFEST.sh)"
  bash "$CHECK"
fi

printf '\n\033[1;32m✔ CONFORMANCE PASSED (%s)\033[0m\n' "$MANIFEST"
