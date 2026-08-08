#!/usr/bin/env bash
# Spike G — environments consume code/ from the site repo (docs/proposals/
# code-half.md §5, task #40; the engine half — activation invariant, `wp duo
# deploy`, version_range — is task #39/#41/#42, agent/src/{Deploy,Apply,
# Capture,Policy,Cli}.php + manifests/core.json).
#
# The one structural difference from every other env pair in this sandbox:
# each env's wp-content/plugins/duo-loop-demo is bind-mounted from THAT
# environment's OWN site-repo checkout's code/ tree (./siterepo/g1/code/...
# and ./siterepo/g2/code/... respectively — see docker-compose.yml's
# `spikeg` profile), not a shared static fixture. The site repo IS the code
# source: a `git pull` on the host changes what an already-running
# container sees, live, with zero docker rebuild/restart. Phase 1's engine
# (Deploy.php) only ever checks "does this plugin exist under THIS
# ENVIRONMENT'S OWN wp-content/plugins/" — no separate code/ materialization
# transport exists yet (Deploy.php's own docblock: "the sandbox spike (task
# #40) is what makes those [the environment's wp-content/plugins/ and the
# site repo's code/ tree] the same directory"). This spike is that.
#
# Narrative (each step asserted, not just "the command ran"):
#   (a) both envs installed, duo-loop-demo present in code/ but NOT active;
#       initial capture on g1 records the inactive baseline.
#   (b) g1 activates duo-loop-demo for real (activate_plugin(), hooks fire),
#       captures, pushes; g2 pulls the pending activation.
#   (c) g2: plan shows the pending activation with code_mismatch empty (the
#       plugin's code already arrived via g1's earlier commits); deploy
#       activates it for real (REST route responds — proves hooks fired,
#       not just an option flip); apply converges; canonical(g2) ==
#       canonical(g1) byte for byte.
#   (d) invariant demo: on a branch, duo-loop-demo is removed from code/
#       (git rm -r) while canonical still says active. g2 pulls; the
#       ALREADY-RUNNING container's bind-mounted plugin directory goes
#       missing live. plan surfaces missing_in_code loudly; deploy AND
#       apply both refuse. Fix: g1 deactivates for real (matching the
#       already-missing code), captures, pushes; g2 pulls; deploy succeeds;
#       plugin gone cleanly.
#   (e) version_range demo: a dedicated fixture manifest
#       (manifests/duo-loop-demo-versioned.json) pins a version_range for
#       duo-loop-demo. Control case first (still-compliant version deploys
#       clean), then a branch bumps the plugin's own Version header past
#       the range — g2 pulls the FILE CONTENT change live, plan surfaces
#       outside_version_range with the exact plugin/manifest/version, and
#       deploy/apply both refuse until --force-code-mismatch.
#   (f) idempotency: a second deploy and a second apply against the same
#       (still-forced) branch are genuine no-ops.
#
# Own dedicated env pair (g1 :8812 / g2 :8813, profile "spikeg", no
# journal — this spike is about code/, not the provenance journal). This
# script boots, installs, and seeds them itself — it never touches envs
# a/b/c/conf*/e*/f*/fx* or their site repos.
#
# Re-run safety: envs g1/g2 are never torn down (docker compose down/clean
# is off-limits — other agents share this stack), so every run resets
# g1/g2's WP content, the duo ledger tables, and the site-repo git state
# (including the bind-mounted code/wp-content/plugins/duo-loop-demo
# directory itself) from scratch, mirroring spike_f_core_loop.sh's exact
# approach — already relied on there for its own live-bind-mounted
# /siterepo, which this script leans on identically for a second,
# NESTED bind-mount target. WordPress core/theme install is the only thing
# skipped on repeat runs (guarded by `core is-installed`, same as spike
# A/E/F); duo-loop-demo's activation state is reset defensively every run
# instead, since "plugin NOT active initially" (step a) must hold every
# time, not just on a cold start.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml --profile spikeg"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
G1=http://localhost:8812
G2=http://localhost:8813
BASE_VERSION=9.5.0     # duo-loop-demo's committed baseline — inside the fixture manifest's range
RANGE_MIN=9.0.0
RANGE_MAX=99.0.0        # max-exclusive, matching docs/proposals/code-half.md §4.3
BUMPED_VERSION=100.0.0  # >= RANGE_MAX -> outside_version_range

wp_env() { # wp_env <g1|g2> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_g1() { wp_env g1 "$@"; }
wp_g2() { wp_env g2 "$@"; }

wait_for() { # wait_for <g1|g2>
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
# pretty permalinks (REST routes 404 under Plain permalinks) — same two
# steps sandbox/setup.sh and spike_f_core_loop.sh perform on this identical
# docker image, needed here too since step (c)/(e) probe a REST route.
write_htaccess() { # write_htaccess <g1|g2>
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

# Deliberately does NOT activate duo-loop-demo (contrast spike_f's
# install_env, which does) — step (a) requires the plugin present in code/
# but inactive on both envs at the start.
install_env() { # install_env <g1|g2> <port> <title>
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
}

# Envs persist across runs, so make re-running this script safe: wipe WP
# content, the duo ledger tables, and defensively force duo-loop-demo
# inactive (a previous run may have left it active) every time.
reset_env_state() { # reset_env_state <g1|g2>
  local env="$1"
  wp_env "$env" site empty --yes >/dev/null
  wp_env "$env" plugin deactivate duo-loop-demo >/dev/null 2>&1 || true
  wp_env "$env" option delete duo_loop_color >/dev/null 2>&1 || true
  wp_env "$env" option delete duo_loop_api_key >/dev/null 2>&1 || true
  wp_env "$env" option delete duo_loop_hits >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
}

# BSD sed (macOS): -i '' with an explicit empty backup-suffix arg. Matches
# only the version NUMBER via a capture group so the leading " * Version: "
# docblock decoration is preserved untouched.
set_plugin_version() { # set_plugin_version <plugin-php-file> <version>
  local file="$1" ver="$2"
  sed -i '' -E "s/^( \\* Version: )[0-9.]+/\\1${ver}/" "$file"
}

say "boot: db-g1/db-g2 only for now — wp-g1/wp-g2 must not start until code/ exists on disk (see next step)"
mkdir -p siterepo
$COMPOSE up -d db-g1 db-g2

say "fresh site repo, code/ authored BEFORE wp-g1/wp-g2 ever start — bind-mount bootstrap order"
# Docker auto-creates a missing bind-mount source dir as an empty,
# root-owned directory rather than failing — so the source must already
# exist, host-owned, with real content, before a container that mounts it
# is ever CREATED. This isn't just a one-time concern: `docker inspect`
# confirms this bind mount uses "rprivate" propagation (docker's default),
# which pins whatever directory (inode) existed at the host path AT
# CONTAINER-CREATE TIME — it does not re-resolve if that exact directory is
# later deleted-and-recreated as a unit (confirmed empirically: after
# rm-rf+recreate while wp-g2 was already running from an EARLIER buggy
# version of this script that started wp-g1/wp-g2 too early, `wp-g2`'s own
# view of the directory stayed pinned to the original empty/root-owned
# stat, while a freshly-created `cli-g2` one-off container — run fresh per
# wp-cli call, never long-lived — saw the real content immediately; same
# host, same path, different container lifetimes). This is NOT the same
# case as spike_f_core_loop.sh's rm-rf-then-recreate of its own /siterepo
# mount: that mount is a data directory, only ever consumed via wp-cli
# (always through fresh `cli-*` one-off containers per this file's own
# wp_env()), never through the long-lived wp-* Apache container — so
# spike_f never actually exercises whether ITS long-lived container's own
# bind-mount view stays fresh, and empirically here, it would not either.
# Fix, in two parts: (1) below, do all git/host authoring BEFORE wp-g1/
# wp-g2 are created at all, so their FIRST-ever mount attaches to real
# content, never an auto-created empty stand-in; (2) further down, once
# db-g1/db-g2 are healthy and code/ exists, force-recreate wp-g1/wp-g2
# specifically (their own container only — the mariadb data volume and the
# wpg1/wpg2 WordPress-core named volume are untouched by recreating the
# container that mounts them) so a RE-RUN's fresh git checkout is picked up
# too, not just a cold start's. Both fixes are needed only up front: every
# later git operation in this script that changes code/'s CONTENTS (file
# add/remove/edit within an already-mounted, never-again-recreated
# directory) is exactly the case that DOES propagate live with zero
# restart — which is what steps (d)/(e) actually demonstrate, and why this
# script only ever queries the plugin's live file state via wp_g2 (backed
# by fresh cli-g2 one-off containers) rather than the wp-g2 Apache
# container directly, except for the one deliberate HTTP/REST check in
# step (c), which is exactly why THAT specific check is what surfaced this.
rm -rf siterepo/origin-g.git siterepo/g1 siterepo/g2
git init --bare -b main siterepo/origin-g.git >/dev/null

mkdir -p siterepo/g1/code/wp-content/plugins/duo-loop-demo
cp fixtures/duo-loop-demo/duo-loop-demo.php siterepo/g1/code/wp-content/plugins/duo-loop-demo/duo-loop-demo.php
set_plugin_version siterepo/g1/code/wp-content/plugins/duo-loop-demo/duo-loop-demo.php "$BASE_VERSION"
cat > siterepo/g1/site.duo.json <<EOF
{
  "manifests": ["core", "duo-loop-demo"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/g1/.gitignore
git -C siterepo/g1 init -q -b main
git -C siterepo/g1 remote add origin ../origin-g.git
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "init site repo: code/wp-content/plugins/duo-loop-demo (v$BASE_VERSION, inactive) + site.duo.json"
git -C siterepo/g1 push -qu origin main
git clone -q siterepo/origin-g.git siterepo/g2
pass "g1 authored + pushed commit 1 (plugin present in code/, inactive); g2 cloned the SAME commit — both envs' bind-mount sources exist, host-owned, with real content, before wp-g1/wp-g2 are created"

say "start wp-g1/wp-g2 now that code/ is real — --force-recreate so a RE-RUN's freshly re-authored code/ (rm-rf'd and recreated above) is what the bind mount attaches to, not a prior run's now-stale mount (the mariadb data volume and the wpg1/wpg2 WordPress-core named volume are untouched by recreating the container that mounts them; only the container's own mount table is refreshed)"
$COMPOSE up -d --force-recreate wp-g1 wp-g2
pass "wp-g1/wp-g2 up with a fresh bind-mount attach against this run's code/"

install_env g1 8812 "Duo G1"
install_env g2 8813 "Duo G2"
reset_env_state g1
reset_env_state g2
pass "both envs installed; duo-loop-demo present in code/ (bind-mounted from each env's own checkout) but inactive on both; ledger tables clean"

say "(a) plugin confirmed NOT active on either env; initial capture on g1 records the inactive baseline"
wp_g1 plugin list --status=active --field=name | grep -qx duo-loop-demo && fail "duo-loop-demo is already active on g1 before step (a) — reset_env_state did not take"
wp_g2 plugin list --status=active --field=name | grep -qx duo-loop-demo && fail "duo-loop-demo is already active on g2 before step (a) — reset_env_state did not take"
wp_g1 duo capture --repo=/siterepo
jq -e '.records.active_plugins.value == []' siterepo/g1/state/options/core.json >/dev/null \
  || fail "g1's captured active_plugins is not [] (got: $(jq -c .records.active_plugins.value siterepo/g1/state/options/core.json))"
[ "$(jq -r '.records.template.value' siterepo/g1/state/options/core.json)" = "twentytwentyone" ] || fail "g1's captured template is not twentytwentyone"
[ "$(jq -r '.records.stylesheet.value' siterepo/g1/state/options/core.json)" = "twentytwentyone" ] || fail "g1's captured stylesheet is not twentytwentyone"
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: initial state, duo-loop-demo inactive"
git -C siterepo/g1 push -q origin main
pass "(a) code facts captured: active_plugins=[], template=stylesheet=twentytwentyone, duo-loop-demo inactive — committed + pushed"

say "(b) on g1: activate duo-loop-demo — a REAL admin action (activate_plugin(), hooks fire)"
wp_g1 plugin activate duo-loop-demo
wp_g1 plugin list --status=active --field=name | grep -qx duo-loop-demo || fail "duo-loop-demo did not actually activate on g1"
wp_g1 duo capture --repo=/siterepo
jq -e '.records.active_plugins.value | any(. == "duo-loop-demo/duo-loop-demo.php")' siterepo/g1/state/options/core.json >/dev/null \
  || fail "g1's captured active_plugins does not include duo-loop-demo/duo-loop-demo.php after activating (got: $(jq -c .records.active_plugins.value siterepo/g1/state/options/core.json))"
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: activate duo-loop-demo on g1"
git -C siterepo/g1 push -q origin main
git -C siterepo/g2 pull -q origin main
pass "(b) g1 activated duo-loop-demo for real; capture recorded it; g2 pulled the pending activation"

say "(c) on g2: plan shows the pending activation with code_mismatch EMPTY — the plugin's code already arrived (present since before docker ever started, via g1's earlier commits)"
PLAN_JSON=$(wp_g2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_JSON" | jq . 2>/dev/null || echo "$PLAN_JSON"
echo "$PLAN_JSON" | jq -e '.code_mismatch == []' >/dev/null \
  || fail "g2's plan shows code_mismatch even though duo-loop-demo's code is present via the bind mount (got: $(echo "$PLAN_JSON" | jq -c .code_mismatch))"
pass "(c) plan: pending activation visible, code_mismatch empty"

say "(c) wp duo deploy on g2: real activation"
DEPLOY_JSON=$(wp_g2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq . 2>/dev/null || echo "$DEPLOY_JSON"
echo "$DEPLOY_JSON" | jq -e '.activated | any(. == "duo-loop-demo/duo-loop-demo.php")' >/dev/null \
  || fail "deploy's summary does not list duo-loop-demo/duo-loop-demo.php as activated (got: $DEPLOY_JSON)"
wp_g2 plugin list --status=active --field=name | grep -qx duo-loop-demo || fail "duo-loop-demo is not active on g2 after deploy"
pass "(c) g2: duo-loop-demo activated for real via wp duo deploy"

say "(c) acceptance: the plugin's REST route actually responds — proves rest_api_init fired for real, not just an options-table flip"
REST_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$G2/wp-json/duo-loop/v1/settings")
[ "$REST_CODE" != "404" ] || fail "POST duo-loop/v1/settings returned 404 on g2 — the route was never registered, activation hooks did not fire"
pass "(c) POST $G2/wp-json/duo-loop/v1/settings -> HTTP $REST_CODE (not 404 => the route IS registered; 401/403 is the expected unauthenticated permission_callback response, not 'route does not exist')"

say "(c) wp duo apply on g2: converges remaining state (canary must stay clean; deploy already handled the managed options, apply's generic options loop skips them entirely)"
# --adopt-by-slug=terms: g1 and g2 each independently minted their OWN
# default "Uncategorized" category term during their own `core install` —
# unrelated to anything this spike is testing, but "category" is a
# declared taxonomy in site.duo.json, so it's in scope, and without this
# flag Apply::apply()'s existing, unrelated collision guard (checked BEFORE
# this task's new code_mismatch precondition — confirmed empirically: an
# unresolved collision here masked every code_mismatch assertion below
# until this flag was added) refuses first with its own, different error.
# Same fix spike_f_core_loop.sh/regress_collision.sh already apply for the
# identical reason.
REV=$(git -C siterepo/g2 rev-parse HEAD)
APPLY_JSON=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" --format=json | tail -1)
echo "$APPLY_JSON" | jq . 2>/dev/null || echo "$APPLY_JSON"
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "apply's canary was not clean on g2 (got: $APPLY_JSON)"
pass "(c) apply succeeded, canary clean"

say "(c) acceptance: canonical(g2) == canonical(g1), byte for byte"
wp_g2 duo capture --repo=/siterepo --out=/siterepo/.tmp-g2state >/dev/null
diff -r siterepo/g1/state siterepo/g2/.tmp-g2state || fail "g2's re-captured state does not match g1's byte for byte"
rm -rf siterepo/g2/.tmp-g2state
pass "(c) canonical state identical across g1/g2 after deploy+apply"

say "(d) invariant demo: on a branch, remove duo-loop-demo from code/ (git rm -r) while canonical still records it active"
git -C siterepo/g1 checkout -q -b remove-plugin-demo
git -C siterepo/g1 rm -rq code/wp-content/plugins/duo-loop-demo
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "branch: remove duo-loop-demo from code/ (canonical still says active — invariant violation)"
git -C siterepo/g1 push -qu origin remove-plugin-demo
pass "(d) g1: duo-loop-demo removed from code/ on branch remove-plugin-demo; canonical (still on main's content, unpushed-to there) still says active"

git -C siterepo/g2 fetch -q origin
git -C siterepo/g2 checkout -q -B remove-plugin-demo origin/remove-plugin-demo
GONE=$(wp_g2 eval 'echo file_exists(WP_PLUGIN_DIR . "/duo-loop-demo/duo-loop-demo.php") ? "present" : "gone";')
[ "$GONE" = "gone" ] || fail "duo-loop-demo.php still exists on g2's live filesystem after the branch removed it and g2 pulled — bind mount did not reflect the host removal"
pass "(d) g2 pulled the removal branch; the ALREADY-RUNNING container's own bind-mounted plugin directory is immediately gone (no restart) — this is the raw material code_mismatch detects"

say "(d) plan loudly names the mismatch"
PLAN_JSON=$(wp_g2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_JSON" | jq -e '[.code_mismatch[] | select(.issue=="missing_in_code" and .plugin=="duo-loop-demo/duo-loop-demo.php")] | length == 1' >/dev/null \
  || fail "g2's plan does not show exactly one missing_in_code row for duo-loop-demo/duo-loop-demo.php (got: $PLAN_JSON)"
pass "(d) plan.code_mismatch: missing_in_code, duo-loop-demo/duo-loop-demo.php"

say "(d) deploy refuses (non-zero exit, names the plugin)"
if OUT=$(wp_g2 duo deploy --repo=/siterepo 2>&1); then
  fail "duo deploy succeeded on g2 despite duo-loop-demo being missing_in_code — the invariant did not block"
fi
grep -q 'does not exist in this environment' <<<"$OUT" || fail "deploy's refusal did not contain the expected missing_in_code wording (got: $OUT)"
grep -q 'duo-loop-demo/duo-loop-demo.php' <<<"$OUT" || fail "deploy's refusal did not name duo-loop-demo/duo-loop-demo.php (got: $OUT)"
pass "(d) deploy refused loudly: $(tail -1 <<<"$OUT")"

say "(d) apply also refuses (same invariant, same code_mismatch bucket, Apply::apply()'s own precondition)"
REV=$(git -C siterepo/g2 rev-parse HEAD)
if OUT=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" 2>&1); then
  fail "duo apply succeeded on g2 despite missing_in_code"
fi
grep -q 'apply refused' <<<"$OUT" || fail "apply's refusal did not read 'apply refused' (got: $OUT)"
pass "(d) apply refused too: $(tail -1 <<<"$OUT")"

say "(d) fix: deactivate via canonical (g1 deactivates for real + captures + pushes; g2 pulls)"
# NOT `wp plugin deactivate` here: g1 is on the SAME remove-plugin-demo
# branch that already `git rm -r`'d the plugin from g1's OWN code/ tree
# too (this git checkout, this bind mount — g1 and g2 are symmetric, both
# consuming code/ from their own checkout). Confirmed empirically: `wp
# plugin deactivate duo-loop-demo` here fails ("the 'duo-loop-demo' plugin
# could not be found" / "No plugins deactivated"). Root cause, confirmed by
# code-engine reading the wp-cli source directly: the wp-cli COMMAND
# pre-resolves its argument against get_plugins()'s disk scan before ever
# calling into WordPress core — a wp-cli UX layer quirk, not a limitation
# of WordPress's own deactivate_plugins(), which Deploy.php calls directly
# with a basename already in hand from the active_plugins option and no
# disk-resolution step at all (confirmed live against a ghost plugin: `wp
# duo deploy` deactivates cleanly where `wp plugin deactivate` refuses).
# So this calls that exact same core function directly, same as Deploy.php
# does, rather than through the command layer that won't take it here —
# the realistic fix an operator would reach for once the plugins-list UI
# (which resolves the same way the command does) won't offer a deactivate
# action for a missing plugin either. register_deactivation_hook's own
# callback lives INSIDE the now-absent plugin file, so there is no hook
# this skips that a real deactivation would otherwise have fired.
wp_g1 eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; deactivate_plugins("duo-loop-demo/duo-loop-demo.php");'
wp_g1 plugin list --status=active --field=name | grep -qx duo-loop-demo && fail "duo-loop-demo still active on g1 after deactivation"
wp_g1 duo capture --repo=/siterepo
jq -e '.records.active_plugins.value | index("duo-loop-demo/duo-loop-demo.php") == null' siterepo/g1/state/options/core.json >/dev/null \
  || fail "g1's captured active_plugins still lists duo-loop-demo after deactivating"
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "branch: deactivate duo-loop-demo to match its removal from code/"
git -C siterepo/g1 push -q origin remove-plugin-demo
git -C siterepo/g2 pull -q origin remove-plugin-demo
PLAN_JSON=$(wp_g2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_JSON" | jq -e '.code_mismatch == []' >/dev/null || fail "g2's plan still shows code_mismatch after the deactivation fix landed (got: $PLAN_JSON)"
pass "(d) g1 deactivated for real + captured + pushed; g2 pulled — code_mismatch now empty"

say "(d) deploy now succeeds; plugin gone cleanly"
DEPLOY_JSON=$(wp_g2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.deactivated | any(. == "duo-loop-demo/duo-loop-demo.php")' >/dev/null \
  || fail "deploy's summary does not list duo-loop-demo/duo-loop-demo.php as deactivated (got: $DEPLOY_JSON)"
wp_g2 plugin list --status=active --field=name | grep -qx duo-loop-demo && fail "duo-loop-demo still shows active on g2 after deploy deactivated it"
STILL_GONE=$(wp_g2 eval 'echo file_exists(WP_PLUGIN_DIR . "/duo-loop-demo/duo-loop-demo.php") ? "present" : "gone";')
[ "$STILL_GONE" = "gone" ] || fail "duo-loop-demo.php reappeared on g2 unexpectedly"
pass "(d) deploy succeeded: duo-loop-demo deactivated, absent from code/ — invariant satisfied, plugin gone cleanly"

say "(e) return to a consistent baseline: g1/g2 back on main (plugin present + active again per canonical, ready to re-deploy)"
git -C siterepo/g1 checkout -q main
git -C siterepo/g2 fetch -q origin
git -C siterepo/g2 checkout -q main

say "(e) version_range demo: branch off main, pin the duo-loop-demo-versioned fixture manifest (range $RANGE_MIN-$RANGE_MAX, plugin key duo-loop-demo/duo-loop-demo.php); plugin is still at $BASE_VERSION, compliant"
git -C siterepo/g1 checkout -q -b version-range-demo
jq '.manifests += ["duo-loop-demo-versioned"]' siterepo/g1/site.duo.json > siterepo/g1/.tmp-site.json
mv siterepo/g1/.tmp-site.json siterepo/g1/site.duo.json
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "branch: pin duo-loop-demo-versioned (version_range $RANGE_MIN-$RANGE_MAX); plugin still $BASE_VERSION, compliant"
git -C siterepo/g1 push -qu origin version-range-demo
git -C siterepo/g2 fetch -q origin
git -C siterepo/g2 checkout -q -B version-range-demo origin/version-range-demo

say "(e) control: g2 re-deploys (re-activating after (d)'s real deactivation) — must be clean, code_mismatch empty, version in range"
DEPLOY_JSON=$(wp_g2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.activated | any(. == "duo-loop-demo/duo-loop-demo.php")' >/dev/null \
  || fail "deploy did not re-activate duo-loop-demo on g2 for the version_range control case (got: $DEPLOY_JSON)"
echo "$DEPLOY_JSON" | jq -e '.code_mismatch == []' >/dev/null \
  || fail "deploy reported code_mismatch while duo-loop-demo ($BASE_VERSION) is still inside range $RANGE_MIN-$RANGE_MAX (got: $DEPLOY_JSON)"
pass "(e) control: version $BASE_VERSION is in range, deploy clean, code_mismatch empty — the check is quiet when compliant, not a permanent siren"

say "(e) bump duo-loop-demo's OWN version header to $BUMPED_VERSION — now outside the pinned range"
set_plugin_version siterepo/g1/code/wp-content/plugins/duo-loop-demo/duo-loop-demo.php "$BUMPED_VERSION"
git -C siterepo/g1 add -A
git -C siterepo/g1 -c user.name=duo -c user.email=duo@example.test commit -qm "branch: bump duo-loop-demo to $BUMPED_VERSION (outside $RANGE_MIN-$RANGE_MAX)"
git -C siterepo/g1 push -q origin version-range-demo
git -C siterepo/g2 pull -q origin version-range-demo
LIVE_VER=$(wp_g2 eval 'echo get_file_data(WP_PLUGIN_DIR . "/duo-loop-demo/duo-loop-demo.php", array("Version"=>"Version"))["Version"];')
[ "$LIVE_VER" = "$BUMPED_VERSION" ] || fail "g2's live plugin file still reads version '$LIVE_VER' after pulling the bump — bind mount did not reflect the host file edit"
pass "(e) g2's ALREADY-RUNNING container reads the new version ($LIVE_VER) immediately via the bind mount — no docker rebuild/restart; the file-content analogue of (d)'s directory-removal-via-pull"

say "(e) plan surfaces outside_version_range, naming the exact plugin/manifest/installed_version"
PLAN_JSON=$(wp_g2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_JSON" | jq -e --arg ver "$BUMPED_VERSION" \
  '[.code_mismatch[] | select(.issue=="outside_version_range" and .plugin=="duo-loop-demo/duo-loop-demo.php" and .manifest=="duo-loop-demo-versioned" and .installed_version==$ver)] | length == 1' >/dev/null \
  || fail "plan.code_mismatch does not show outside_version_range for duo-loop-demo at $BUMPED_VERSION against duo-loop-demo-versioned (got: $PLAN_JSON)"
MSG=$(echo "$PLAN_JSON" | jq -r '.code_mismatch[] | select(.issue=="outside_version_range") | .message')
grep -q 'declared version_range' <<<"$MSG" || fail "outside_version_range message missing 'declared version_range' wording (got: $MSG)"
grep -q -- '--force-code-mismatch' <<<"$MSG" || fail "outside_version_range message does not mention --force-code-mismatch (got: $MSG)"
pass "(e) plan.code_mismatch: outside_version_range — $MSG"

say "(e) deploy and apply both refuse (outside_version_range blocks exactly like missing_in_code); --force-code-mismatch overrides both"
if OUT=$(wp_g2 duo deploy --repo=/siterepo 2>&1); then
  fail "duo deploy succeeded on g2 despite outside_version_range"
fi
grep -q -- '--force-code-mismatch' <<<"$OUT" || fail "deploy's refusal does not mention --force-code-mismatch (got: $OUT)"
pass "(e) deploy refused: $(tail -1 <<<"$OUT")"

REV=$(git -C siterepo/g2 rev-parse HEAD)
if OUT=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" 2>&1); then
  fail "duo apply succeeded on g2 despite outside_version_range"
fi
grep -q 'apply refused' <<<"$OUT" || fail "apply's refusal did not read 'apply refused' (got: $OUT)"
pass "(e) apply refused too: $(tail -1 <<<"$OUT")"

# --force-code-drift is ALSO required on this specific call, pre-existing
# and unrelated to DUO-3240: this spike predates code_drift (DUO-3231) and
# was never re-run against it until now. The version-bump fixture above
# (editing duo-loop-demo's file directly, no deploy in between) is exactly
# the "code changed outside Duo's own reconciliation" shape code_drift
# exists to catch — g2's last deploy (the "(e) control" one above)
# baselined $BASE_VERSION as last-known-good, so it now ALSO drifts, on
# top of being outside_version_range. Needed on THIS call only: deploy
# unconditionally re-baselines to the CURRENT (bumped) version at the end
# of every successful run, so from here on code_drift is naturally quiet
# again (installed == recorded) while code_mismatch's outside_version_range
# persists (it compares against the manifest's declared range, never a
# baseline) — asserted below, not just assumed.
DEPLOY_JSON=$(wp_g2 duo deploy --repo=/siterepo --force-code-mismatch --force-code-drift --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.code_mismatch | length == 1' >/dev/null \
  || fail "--force-code-mismatch deploy did not report the (overridden) finding in its own summary (got: $DEPLOY_JSON)"
echo "$DEPLOY_JSON" | jq -e '.code_drift | length == 1' >/dev/null \
  || fail "--force-code-drift deploy did not report the (overridden) drift finding in its own summary (got: $DEPLOY_JSON)"
pass "(e) deploy --force-code-mismatch --force-code-drift: proceeds, still reports BOTH overridden findings in its summary (forced through, not hidden) — and re-baselines to $BUMPED_VERSION"

say "(e) confirm the re-baseline: a plain plan (no force flags at all) now shows code_drift EMPTY — only outside_version_range remains, exactly as this fixture's own remaining calls below assume"
PLAN_POST_DEPLOY=$(wp_g2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_POST_DEPLOY" | jq -e '.code_drift == []' >/dev/null \
  || fail "expected code_drift to be cleared by the forced deploy's unconditional re-baseline, got: $(echo "$PLAN_POST_DEPLOY" | jq -c .code_drift)"
echo "$PLAN_POST_DEPLOY" | jq -e '.code_mismatch | length == 1' >/dev/null \
  || fail "expected outside_version_range to still be present (it never clears via re-baseline), got: $(echo "$PLAN_POST_DEPLOY" | jq -c .code_mismatch)"
pass "(e) confirmed: code_drift cleared by re-baseline, code_mismatch's outside_version_range persists — every call below needs only --force-code-mismatch"

REV=$(git -C siterepo/g2 rev-parse HEAD)
APPLY_JSON=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" --force-code-mismatch --format=json | tail -1)
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "apply --force-code-mismatch's canary was not clean (got: $APPLY_JSON)"
pass "(e) apply --force-code-mismatch: succeeds, canary clean"

# DUO-3240: the two calls above proved the overridden finding survives in
# --format=json output; the outside_version_range condition is untouched by
# either (it checks the plugin's installed version against the manifest's
# declared version_range, not a re-baselined "last known good" the way
# code_drift is — see part (f) below, "still out-of-range" persists across
# repeated deploys), so re-running both WITHOUT --format=json here proves
# the exact gap DUO-3240 closed: human-mode output must ALSO carry the
# override, not just the machine-readable summary.
say "(e) deploy/apply --force-code-mismatch also report the overridden finding in HUMAN-mode output, not just --format=json (DUO-3240 — the same gap code_drift's own 'FORCED past code_drift' warning was built to avoid, now closed for code_mismatch too)"
DEPLOY_FORCED_HUMAN=$(wp_g2 duo deploy --repo=/siterepo --force-code-mismatch 2>&1)
grep -q "FORCED past code_mismatch" <<<"$DEPLOY_FORCED_HUMAN" \
  || fail "forced deploy did not report the overridden outside_version_range finding in human-mode output (got: $DEPLOY_FORCED_HUMAN)"
pass "(e) deploy --force-code-mismatch: human-mode output reports the overridden finding"

REV=$(git -C siterepo/g2 rev-parse HEAD)
APPLY_FORCED_HUMAN=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" --force-code-mismatch 2>&1)
grep -q "FORCED past code_mismatch" <<<"$APPLY_FORCED_HUMAN" \
  || fail "forced apply did not report the overridden outside_version_range finding in human-mode output (got: $APPLY_FORCED_HUMAN)"
pass "(e) apply --force-code-mismatch: human-mode output reports the overridden finding"

say "(f) idempotency: a second deploy and a second apply on the same (still out-of-range) branch are genuine no-ops"
DEPLOY_JSON2=$(wp_g2 duo deploy --repo=/siterepo --force-code-mismatch --format=json | tail -1)
echo "$DEPLOY_JSON2" | jq -e '.activated == [] and .deactivated == [] and .theme_switched == null' >/dev/null \
  || fail "second deploy was not a no-op (got: $DEPLOY_JSON2)"
pass "(f) second deploy: zero activations/deactivations/theme switches — matches Deploy::run()'s documented idempotency (no WP APIs called, not just no visible effect)"

REV=$(git -C siterepo/g2 rev-parse HEAD)
APPLY_JSON2=$(wp_g2 duo apply --repo=/siterepo --adopt-by-slug=terms --revision="$REV" --force-code-mismatch --format=json | tail -1)
[ "$(echo "$APPLY_JSON2" | jq -r '.applied')" = "0" ] || fail "second apply applied $(echo "$APPLY_JSON2" | jq -r '.applied') entities, expected 0 (got: $APPLY_JSON2)"
[ "$(echo "$APPLY_JSON2" | jq -r '.canary')" = "clean" ] || fail "second apply's canary was not clean"
pass "(f) second apply: 0 entities applied, canary clean — genuine no-op"

printf '\n\033[1;32m✔ SPIKE G PASSED\033[0m\n'
