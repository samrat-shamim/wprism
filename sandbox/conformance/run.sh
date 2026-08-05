#!/usr/bin/env bash
# Conformance gate (DESIGN.md §6 / adversarial-review finding #20 — the
# manifest-treadmill answer): a generalized capture -> apply -> re-capture
# round-trip harness, run per manifest against a FRESH, disposable env pair.
# This is what CI runs; it knows nothing manifest-specific beyond what's
# declared in conformance/manifests.json and a seed script per manifest —
# all manifest-specific authoring happens in conformance/seeds/<name>.sh.
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

COMPOSE="docker compose -f docker-compose.yml --profile conf"
wp_env() { local env="$1"; shift; $COMPOSE run --rm -T "cli-$env" wp "$@"; }
wp_conf1() { wp_env conf1 "$@"; }
wp_conf2() { wp_env conf2 "$@"; }
export COMPOSE
export -f wp_env wp_conf1 wp_conf2 say pass fail

say "clean-room: removing any existing conf1/conf2 containers + volumes"
# Conformance never trusts leftover state from a previous manifest's run —
# unlike the spikes (persistent envs, run once against a fresh boot), this
# gate re-installs WordPress from scratch every invocation.
$COMPOSE rm -sf db-conf1 wp-conf1 cli-conf1 db-conf2 wp-conf2 cli-conf2 >/dev/null 2>&1 || true
docker volume rm -f duo-sandbox_dbconf1 duo-sandbox_wpconf1 duo-sandbox_dbconf2 duo-sandbox_wpconf2 >/dev/null 2>&1 || true
rm -rf siterepo/conf1 siterepo/conf2 siterepo/origin-conf.git
mkdir -p siterepo/conf1 siterepo/conf2

say "boot conf1 (:8806) / conf2 (:8807)"
$COMPOSE up -d db-conf1 wp-conf1 db-conf2 wp-conf2

wait_for() { # wait_for <conf1|conf2>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

# wp-cli can't write .htaccess without extra config; apache needs it for
# pretty permalinks — same two steps sandbox/setup.sh performs for envs A/B.
write_htaccess() { # write_htaccess <conf1|conf2>
  $COMPOSE exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}

install_env() { # install_env <conf1|conf2> <port> <title>
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    wp_env "$env" theme install twentytwentyone --activate
    wp_env "$env" option update permalink_structure '/%postname%/'
    wp_env "$env" rewrite flush
    write_htaccess "$env"
    wp_env "$env" site empty --yes
  fi
  if [ "${#PLUGINS[@]}" -gt 0 ]; then
    for plugin in "${PLUGINS[@]}"; do
      wp_env "$env" plugin is-installed "$plugin" >/dev/null 2>&1 \
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
install_env conf1 8806 "Duo Conf1 ($MANIFEST)"
install_env conf2 8807 "Duo Conf2 ($MANIFEST)"
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
