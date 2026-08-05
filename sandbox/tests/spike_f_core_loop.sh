#!/usr/bin/env bash
# Spike F — the core loop end-to-end (DESIGN.md 3.1.5's loud-and-blocking gate
# plus the pending/classify/secret-guard/policy-to-manifest verbs from task
# #12): a tiny fixture plugin writes an admin-authored REST setting (an
# option + post meta), a secret-shaped option value, and anonymous front-end
# runtime traffic. Exercises, against real environments: unclassified writes
# block capture loudly; `wp duo pending` turns journal evidence into
# classification proposals; `wp duo classify` accepts them (and refuses a
# hard secret without --allow-secret); a clean capture never leaks the secret
# or runtime data; apply round-trips byte-for-byte to a second env without
# disturbing its own local runtime state; `wp duo policy-to-manifest` exports
# the accepted classifications and, pinned in place of the inline policy,
# reproduces identical captured state.
#
# Own dedicated env pair (f1 :8808 / f2 :8809, profile "spikef", journal on);
# this script boots, installs, and seeds them itself — it never touches envs
# a/b/c/conf/e1/e2/fx or their site repos.
#
# Re-run safety: envs f1/f2 are never torn down (docker compose down/clean is
# off-limits here — other agents share this stack), so every run resets f1/f2's
# WP content, the duo ledger tables, and the site-repo git state from scratch.
# WordPress core/theme/plugin install is the only thing skipped on repeat runs
# (guarded by `core is-installed`, same as spike A/E).
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml --profile spikef"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
F1=http://localhost:8808
F2=http://localhost:8809

wp_env() { # wp_env <f1|f2> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_f1() { wp_env f1 "$@"; }
wp_f2() { wp_env f2 "$@"; }

wait_for() { # wait_for <f1|f2>
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
# pretty permalinks (REST routes 404 under Plain permalinks) — same two steps
# sandbox/setup.sh performs for envs A/B on this identical docker image.
write_htaccess() { # write_htaccess <f1|f2>
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

install_env() { # install_env <f1|f2> <port> <title>
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
    echo "env $env installed"
  else
    echo "env $env already installed"
  fi
  wp_env "$env" plugin activate duo-loop-demo
}

# Envs persist across runs (never torn down), so make re-running this script
# safe: wipe WP content, the duo ledger tables, and the fixture's own options
# every time. Without this, a second run would trip "term/post already
# exists" errors and — worse — silently pass step (a)'s loud-blocking-gate
# check for the wrong reason (a stale classification left over in a
# previously-mutated site.duo.json, not a real unclassified-key abort).
reset_env_state() { # reset_env_state <f1|f2>
  local env="$1"
  wp_env "$env" site empty --yes >/dev/null
  wp_env "$env" option delete duo_loop_color >/dev/null 2>&1 || true
  wp_env "$env" option delete duo_loop_api_key >/dev/null 2>&1 || true
  wp_env "$env" option delete duo_loop_hits >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  wp_env "$env" duo journal-reset >/dev/null 2>&1 || true
}

say "boot env pair f1 (:8808) / f2 (:8809)"
mkdir -p siterepo/f1 siterepo/f2
$COMPOSE up -d db-f1 wp-f1 db-f2 wp-f2
install_env f1 8808 "Duo F1"
install_env f2 8809 "Duo F2"
pass "both envs installed, duo-loop-demo active, journal on (DUO_JOURNAL)"

say "reset f1/f2 content + ledger for a clean run"
reset_env_state f1
reset_env_state f2
pass "WP content, duo_map/duo_state/duo_kv, and the journal are all clean on both envs"

say "fresh site repo (own origin, own clones — never touches siterepo/a|b|c|conf*|e*|fx*)"
rm -rf siterepo/origin-f.git
git init --bare -b main siterepo/origin-f.git >/dev/null
rm -rf siterepo/f1 && mkdir -p siterepo/f1
cat > siterepo/f1/site.duo.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 0
}
EOF
printf '.tmp*\n' > siterepo/f1/.gitignore
git -C siterepo/f1 init -q -b main
git -C siterepo/f1 remote add origin ../origin-f.git
pass "site repo initialized (manifests: [core], no duo-loop-demo policy yet)"

say "seed: a post to carry the badge meta"
BADGE_POST_ID=$(wp_f1 post create --post_type=post --post_title='Duo Loop Post' --post_name=duo-loop-post \
  --post_status=publish --post_content='<!-- wp:paragraph --><p>Carries the duo-loop-demo badge.</p><!-- /wp:paragraph -->' \
  --porcelain)
pass "post #$BADGE_POST_ID created"

say "scenario 1: admin-authored REST settings write (option + secret-shaped option + post meta)"
APP_PASS=$(wp_f1 user application-password create admin duo-spike-f --porcelain)
SECRET='sk_live_DUOFAKE1234567890TEST'
REST_BODY=$(printf '{"color":"teal","api_key":"%s","badge_post":%d,"badge":"featured"}' "$SECRET" "$BADGE_POST_ID")
RESP=$(curl -fsu "admin:$APP_PASS" -X POST "$F1/wp-json/duo-loop/v1/settings" \
  -H 'Content-Type: application/json' -d "$REST_BODY")
[ "$(jq -r '.color' <<<"$RESP")" = "teal" ] || fail "REST settings write did not echo back color=teal (got: $RESP)"
pass "admin REST write done (duo_loop_color=teal, duo_loop_api_key=sk_live_..., post #$BADGE_POST_ID badge=featured)"

say "scenario 2: anonymous front-end traffic (nobody's authored content)"
for _ in $(seq 1 10); do curl -fso /dev/null "$F1/"; done
pass "10 anonymous front-page GETs done (duo_loop_hits should read 10)"
[ "$(wp_f1 option get duo_loop_hits)" = "10" ] || fail "duo_loop_hits is not 10 after 10 anonymous GETs"

say "(a) duo capture FAILS loudly, naming the unclassified post meta (the loud-and-blocking gate)"
if OUT=$(wp_f1 duo capture --repo=/siterepo 2>&1); then
  fail "duo capture succeeded despite unclassified _duo_loop_badge meta — the loud-and-blocking gate did not fire"
fi
grep -q '_duo_loop_badge' <<<"$OUT" || fail "capture's failure output did not name _duo_loop_badge (got: $OUT)"
pass "capture blocked loudly, naming _duo_loop_badge — options (duo_loop_color/api_key/hits) did NOT block capture (unlisted options never do, per Capture::build_options())"

say "(b) wp duo pending: journal-informed classification proposals (human-readable)"
wp_f1 duo pending --repo=/siterepo || true

say "(b) wp duo pending --format=json: assertions"
# Contract confirmed directly against agent/src/Pending.php + Secrets.php
# (task #12 landed its engine classes mid-authoring, ahead of wiring the CLI
# verbs): Pending::scan() returns a bare JSON array of
# {section, key, proposal, evidence:{entities?, post_types?, journal?:{n,
# surfaces, caps, proposal}}, ref_hint?, secret?} items — matches the
# mission's provisional contract exactly. secret is "hard:<label>" (e.g.
# "hard:stripe key" for our sk_live_ value, per Secrets::HARD_PATTERNS) or
# "suspicious". PENDING_ROOT is kept as a one-line adaptation point in case
# the still-unwritten CLI verb wraps this array differently.
PENDING=$(wp_f1 duo pending --repo=/siterepo --format=json | tail -1)
echo "$PENDING" | jq . 2>/dev/null || echo "$PENDING"
PENDING_ROOT='.'
check_pending() { # check_pending <jq-bool-filter> <description>
  echo "$PENDING" | jq -e "$PENDING_ROOT | $1" >/dev/null 2>&1 || fail "$2"
}
check_pending '[.[] | select(.section=="post_meta" and .key=="_duo_loop_badge" and .proposal=="authored")] | length >= 1' \
  "_duo_loop_badge not proposed authored in pending"
# post_meta evidence is gate-scan-sourced by default (Capture::gate_scan());
# journal evidence only joins on if the SAME key was also observed as a
# write — confirming it here is the real "journal-informed" claim, unlike
# the options items below where journal evidence is the only possible source
# by construction (options are whitelist-invisible otherwise, per
# Pending.php's own docblock) and thus not a distinguishing check.
check_pending '[.[] | select(.section=="post_meta" and .key=="_duo_loop_badge")][0].evidence.journal.n >= 1' \
  "_duo_loop_badge pending item has no journal evidence (expected the admin REST write to have been observed)"
check_pending '[.[] | select(.section=="options" and .key=="duo_loop_color" and .proposal=="authored")] | length >= 1' \
  "duo_loop_color not proposed authored in pending"
check_pending '[.[] | select(.section=="options" and .key=="duo_loop_hits" and .proposal=="runtime")] | length >= 1' \
  "duo_loop_hits not proposed runtime in pending"
check_pending '[.[] | select(.section=="options" and .key=="duo_loop_api_key" and ((.secret // "") | startswith("hard")))] | length >= 1' \
  "duo_loop_api_key not flagged as a hard secret in pending"
pass "pending reflects journal evidence: badge+color authored (badge journal-backed), hits runtime, api_key hard-secret-flagged"

say "(c) classify: badge + color authored, hits runtime (one call, semicolon-separated)"
# MUST be the --set='...' (equals) form, not --set '...' (space): loop-engine
# confirmed by instrumented testing that wp-cli parses the space form as a
# bare boolean flag ($assoc['set'] becomes true) with the value silently
# falling through to positional $args instead — breaks even for a single
# spec, not just repeated flags. (Repetition itself is also broken — wp-cli
# keeps only the LAST occurrence of a repeated assoc flag — so multiple rules
# in one call must be one --set value, semicolon-separated, which is also
# exercised here.)
wp_f1 duo classify --repo=/siterepo \
  --set='post_meta:_duo_loop_badge=authored;options:duo_loop_color=authored;options:duo_loop_hits=runtime'
pass "classified: _duo_loop_badge=authored, duo_loop_color=authored, duo_loop_hits=runtime"

say "(c) classify: a hard secret must refuse authored without --allow-secret"
if OUT=$(wp_f1 duo classify --repo=/siterepo --set='options:duo_loop_api_key=authored' 2>&1); then
  fail "classify accepted duo_loop_api_key=authored despite it being a hard secret (no --allow-secret given)"
fi
pass "classify refused authored on the hard secret: $(tail -1 <<<"$OUT")"
wp_f1 duo classify --repo=/siterepo --set='options:duo_loop_api_key=env'
pass "duo_loop_api_key classified env (secrets are forced env-bound per DESIGN.md 3.1.5)"

say "(d) capture succeeds now that every in-scope key is classified"
wp_f1 duo capture --repo=/siterepo
pass "capture succeeded"

say "(d) acceptance: no secret material anywhere in captured state"
if grep -rq 'sk_live_' siterepo/f1/state; then
  fail "sk_live_ secret leaked into captured state"
fi
pass "no sk_live_ material anywhere under state/"

say "(d) acceptance: duo_loop_color captured; duo_loop_hits + duo_loop_api_key excluded"
grep -q '"duo_loop_color"' siterepo/f1/state/options/core.json || fail "duo_loop_color missing from captured options"
if grep -q '"duo_loop_hits"' siterepo/f1/state/options/core.json; then fail "duo_loop_hits (runtime) leaked into captured options"; fi
if grep -q '"duo_loop_api_key"' siterepo/f1/state/options/core.json; then fail "duo_loop_api_key (env-bound secret) leaked into captured options"; fi
grep -rq '"_duo_loop_badge": "featured"' siterepo/f1/state/posts/post/*.md || fail "_duo_loop_badge missing/incorrect in captured post state"
pass "options/core.json holds exactly duo_loop_color=teal; badge meta captured on the post; hits/api_key excluded"

say "(e) round-trip: commit + push f1's captured state"
git -C siterepo/f1 add -A
git -C siterepo/f1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: seeded duo-loop-demo content on f1"
git -C siterepo/f1 push -qu origin main
pass "pushed to origin-f.git"

say "(e) clone the repo for env f2"
rm -rf siterepo/f2 && git clone -q siterepo/origin-f.git siterepo/f2
pass "f2 cloned"

say "(e) apply f1's captured state onto f2"
REV=$(git -C siterepo/f2 rev-parse HEAD)
APPLY_JSON=$(wp_f2 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --json | tail -1)
echo "$APPLY_JSON" | jq . 2>/dev/null || echo "$APPLY_JSON"
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "side-effect canary was not clean during apply"
pass "apply succeeded, side-effect canary clean"

say "(e) f2's OWN later anonymous traffic (must remain untouched local runtime state)"
for _ in $(seq 1 4); do curl -fso /dev/null "$F2/"; done
F2_HITS_PRE=$(wp_f2 option get duo_loop_hits)
[ "$F2_HITS_PRE" = "4" ] || fail "f2's own duo_loop_hits is '$F2_HITS_PRE' after 4 anonymous GETs, expected 4"

say "(e) acceptance: canonical(f2) == canonical(f1), byte for byte"
wp_f2 duo capture --repo=/siterepo --out=/siterepo/.tmp-f2state >/dev/null
diff -r siterepo/f1/state siterepo/f2/.tmp-f2state || fail "round-trip mismatch between f1 and f2"
rm -rf siterepo/f2/.tmp-f2state
pass "canonical state identical across environments"

say "(e) acceptance: f2's local hits counter untouched by capture (runtime data stays local)"
F2_HITS_POST=$(wp_f2 option get duo_loop_hits)
[ "$F2_HITS_PRE" = "$F2_HITS_POST" ] || fail "f2's local hits counter changed due to capture ($F2_HITS_PRE -> $F2_HITS_POST)"
pass "f2 runtime hits counter ($F2_HITS_POST) untouched by capture"

say "(e) acceptance: applied values resolve correctly on f2"
[ "$(wp_f2 option get duo_loop_color)" = "teal" ] || fail "duo_loop_color on f2 is not 'teal' after apply"
F2_BADGE_POST_ID=$(wp_f2 post list --post_type=post --name=duo-loop-post --field=ID)
[ -n "$F2_BADGE_POST_ID" ] || fail "duo-loop-post not found on f2 after apply"
[ "$(wp_f2 post meta get "$F2_BADGE_POST_ID" _duo_loop_badge)" = "featured" ] || fail "_duo_loop_badge on f2's post is not 'featured' after apply"
[ "$(wp_f2 option get duo_loop_api_key 2>/dev/null || echo '')" = "" ] || fail "duo_loop_api_key (env-bound) should never have been applied onto f2"
pass "f2: duo_loop_color=teal, post #$F2_BADGE_POST_ID badge=featured, no api_key applied"

say "(f) export a manifest from the accepted classifications"
capture_manifest_json() { # capture_manifest_json <f1|f2> <match> <name> <outfile>
  local env="$1" match="$2" name="$3" outfile="$4"
  local raw
  raw=$($COMPOSE run --rm -T "cli-$env" wp duo policy-to-manifest --repo=/siterepo --match="$match" --name="$name")
  # Defensive: WP_CLI::line() output is exact, but strip any stray line
  # before the opening brace in case a docker/compose preamble reaches
  # stdout (seen intermittently across docker versions) — the single-line
  # analogue of this same defense is why spike A/E pipe --json through
  # `tail -1`; policy-to-manifest's payload is pretty-printed canonical
  # JSON (multi-line), so tail -1 would truncate it instead of cleaning it.
  printf '%s\n' "$raw" | awk '/^\{/{f=1} f' > "$outfile"
  jq -e . "$outfile" >/dev/null 2>&1 || fail "exported manifest $outfile is not valid JSON"
}
capture_manifest_json f1 '^_?duo_loop_' duo-loop-demo ../manifests/duo-loop-demo.json
pass "exported manifests/duo-loop-demo.json ($(wc -l < ../manifests/duo-loop-demo.json | tr -d ' ') lines)"

say "(f) swap: drop the inline duo_loop_* policy entries on f1, pin the exported manifest instead"
rm -rf siterepo/f1/.tmp-state-preswap
cp -r siterepo/f1/state siterepo/f1/.tmp-state-preswap
jq '
  .manifests += ["duo-loop-demo"] |
  .policy.options |= with_entries(select(.key | test("^_?duo_loop_") | not)) |
  .policy.post_meta |= with_entries(select(.key | test("^_?duo_loop_") | not))
' siterepo/f1/site.duo.json > siterepo/f1/.tmp-site-swapped.json
mv siterepo/f1/.tmp-site-swapped.json siterepo/f1/site.duo.json
pass "site.duo.json now pins manifest 'duo-loop-demo' instead of inline policy for these keys"

say "(f) acceptance: re-capture f1 with the manifest pinned -> state byte-identical to before the swap"
wp_f1 duo capture --repo=/siterepo
diff -r siterepo/f1/.tmp-state-preswap siterepo/f1/state || fail "captured state changed after swapping inline policy for the exported manifest (policy != exported manifest)"
rm -rf siterepo/f1/.tmp-state-preswap
pass "policy == exported manifest: identical captured state either way"

printf '\n\033[1;32m✔ SPIKE F PASSED\033[0m\n'
