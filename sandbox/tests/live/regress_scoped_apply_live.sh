#!/usr/bin/env bash
# DUO-3344 slice 4 — exact-source live public-CLI scoped apply regression.
#
# This is intentionally a live-only companion to the compiler/session/wire
# suites.  It owns one disposable pair and drives the normal host `cli/duo`
# interface through DockerTransport.  The fixture plugin owns its provider
# capability; the engine sees only the generic manifest/provider contract.
#
# Required caller inputs are deliberately explicit so this cannot consume a
# shared pair accidentally:
#
#   make regress-scoped-apply-live \
#     SCOPED_APPLY_LIVE_PAIR=codexmacb3344 \
#     SCOPED_APPLY_LIVE_PORT1=<free-even-port> \
#     SCOPED_APPLY_LIVE_PORT2=<next-odd-port> \
#     DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD)
#
# It proves, in one clean pair:
#   * host scope -> scoped target plan is target-bound and read-only;
#   * a selected tombstone refuses before a scoped session/mutation without
#     --with-deletes, then applies and verifies through the public CLI;
#   * provider/native actions are real changed-surface effects with public
#     hash-only receipts, while an unrelated target project stays untouched;
#   * scoped work never advances generic applied_revision/debt;
#   * selected sidebar/menu nested identities are allowed to allocate and
#     retire, including exact all-status physical menu children, while a
#     separate unselected sidebar/menu stays byte-exact;
#   * a selected menu cannot take a live location from an unselected holder,
#     and a selected menu tombstone releases only its own location; and
#   * active and archived terminal replay return byte-stable receipt evidence,
#     stale terminal and stale source authority refuse without a follow-on
#     mutation, and a tampered local contract is rejected by the host before
#     target work.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PAIR="${SCOPED_APPLY_LIVE_PAIR:-}"
PORT1="${SCOPED_APPLY_LIVE_PORT1:-}"
PORT2="${SCOPED_APPLY_LIVE_PORT2:-}"
EXPECTED_SOURCE_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
PLUGIN_DIR=duo-agency-cpt
PLUGIN_FILE="code/wp-content/plugins/${PLUGIN_DIR}/${PLUGIN_DIR}.php"
SITE1="$ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$ROOT/sandbox/siterepo/${PAIR}2"
ORIGIN="$ROOT/sandbox/siterepo/origin-${PAIR}.git"
DRIVER_COMPOSE="$ROOT/sandbox/tests/fixtures/duo3344-scoped-live-driver.yml"
DUO="$ROOT/cli/duo"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo3344-scoped-live.XXXXXX")"
ENVS="$TMP/envs.json"
PAIR_OWNED=0
PAIR_ATTEMPTED=0
BODY_COMPLETE=0

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

source_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T source wp "$@"; }
target_wp() { docker compose -f "$DRIVER_COMPOSE" run --rm -T target wp "$@"; }

# pair.sh list deliberately emits bare pair names.  Do not accept Docker's
# project prefix or a name prefix: either would make cleanup unsafe.
pair_list_has_exact() { # <bare-pair>; reads list on stdin
  local pair=$1
  grep -Eq "^[[:space:]]*-[[:space:]]*${pair}[[:space:]]*$"
}

assert_pair_list_parser() {
  local sample near
  sample="== live sandbox pairs =="$'\n'"  - ${PAIR}"$'\n'"== stopped pairs =="$'\n'"  - anotherpair"
  near="  - duo-${PAIR}"$'\n'"  - ${PAIR}0"
  pair_list_has_exact "$PAIR" <<<"$sample" || fail "exact pair-list parser missed its owned name"
  if pair_list_has_exact "$PAIR" <<<"$near"; then
    fail "pair-list parser accepted a project/name prefix lookalike"
  fi
}

print_cleanup_excerpt() { # <label> <path>
  local label=$1 path=$2
  printf '%s\n' "--- ${label} (first 240 lines; ${path}) ---" >&2
  if [ -f "$path" ]; then
    sed -n '1,240p' "$path" >&2
  else
    printf '%s\n' "<cleanup transcript was not created>" >&2
  fi
}

cleanup() {
  local incoming_status=$?
  local status=$incoming_status
  local destroy_log="$TMP/pair-destroy.log"
  local list_log="$TMP/pair-list-after-destroy.log"
  local cleanup_failed=0 body_incomplete=0 destroy_failed=0 list_failed=0 pair_still_present=0 pair_absent=0
  trap - EXIT INT TERM
  set +e
  if [ "$incoming_status" -ne 0 ] || [ "$BODY_COMPLETE" -ne 1 ]; then
    body_incomplete=1
  fi
  if [ "$PAIR_OWNED" -eq 1 ]; then
    if [ "$PAIR_ATTEMPTED" -eq 1 ]; then
      if ! bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >"$destroy_log" 2>&1; then
        destroy_failed=1
        cleanup_failed=1
      fi
    fi
  fi

  # The post-destroy list is a durable cleanup witness, not merely progress
  # chatter. It runs even when destroy failed so the retained artifacts say
  # whether a pair remains visible and an operator never has to reconstruct
  # that answer from a later manual teardown.
  if ! bash "$ROOT/sandbox/bin/pair.sh" list >"$list_log" 2>&1; then
    list_failed=1
    cleanup_failed=1
  elif pair_list_has_exact "$PAIR" <"$list_log"; then
    pair_still_present=1
    cleanup_failed=1
  else
    pair_absent=1
  fi

  # A failed handback can be the very condition that prevents destroy from
  # reaching compose down. Deleting the bind roots after that failure would
  # both erase its evidence and make a later retry take a different (missing
  # root) path. Only a successful destroy plus exact absence authorizes any
  # root or scratch removal. A failed or incomplete body is evidence too:
  # even after a clean pair teardown it must remain inspectable rather than
  # being misreported as a green run with no retained contract/receipt data.
  if [ "$cleanup_failed" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then
    if [ "$PAIR_OWNED" -eq 1 ]; then
      rm -rf -- "$SITE1" "$SITE2" "$ORIGIN" || cleanup_failed=1
      if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
        printf 'FAIL: cleanup left owned site/origin resource(s): %s %s %s\n' "$SITE1" "$SITE2" "$ORIGIN" >&2
        cleanup_failed=1
      fi
    fi
    if [ "$cleanup_failed" -eq 0 ]; then
      rm -rf -- "$TMP" || cleanup_failed=1
      if [ -e "$TMP" ]; then
        printf 'FAIL: cleanup left its mktemp allocation: %s\n' "$TMP" >&2
        cleanup_failed=1
      fi
    fi
  fi

  if [ "$cleanup_failed" -ne 0 ] || [ "$body_incomplete" -eq 1 ]; then
    if [ "$cleanup_failed" -ne 0 ]; then
      printf 'FAIL: scoped live cleanup did not complete; preserving owned roots and cleanup artifacts:\n' >&2
    else
      printf 'FAIL: scoped live body did not complete cleanly; preserving owned roots and cleanup artifacts:\n' >&2
    fi
    printf '  pair: %s\n  roots: %s %s %s\n  destroy transcript: %s\n  post-destroy pair list: %s\n' \
      "$PAIR" "$SITE1" "$SITE2" "$ORIGIN" "$destroy_log" "$list_log" >&2
    if [ "$destroy_failed" -eq 1 ]; then
      print_cleanup_excerpt "pair destroy failed for ${PAIR}" "$destroy_log"
    fi
    if [ "$list_failed" -eq 1 ]; then
      print_cleanup_excerpt "post-destroy pair list failed for ${PAIR}" "$list_log"
    elif [ "$pair_still_present" -eq 1 ]; then
      print_cleanup_excerpt "exact pair ${PAIR} remains in post-destroy list" "$list_log"
    fi
    status=1
  fi

  if [ "$cleanup_failed" -eq 0 ] && [ "$body_incomplete" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then
    printf '\n✔ REGRESS_SCOPED_APPLY_LIVE PASSED (pair %s destroyed and cleanup verified)\n' "$PAIR"
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

extract_final_json() { # <mixed-output-file> <json-file>
  php -r '
    $raw=file_get_contents($argv[1]);
    $final=null; $bestEnd=-1; $n=strlen($raw);
    // Scope --contract deliberately prints canonical pretty JSON, while the
    // normal command/refusal path is one line.  Locate complete objects with
    // a tiny string-aware brace scanner instead of assuming either shape.
    for ($start=0; $start<$n; $start++) {
      if ($raw[$start] !== "{") continue;
      $depth=0; $quoted=false; $escaped=false;
      for ($end=$start; $end<$n; $end++) {
        $ch=$raw[$end];
        if ($quoted) {
          if ($escaped) { $escaped=false; continue; }
          if ($ch === "\\") { $escaped=true; continue; }
          if ($ch === "\"") $quoted=false;
          continue;
        }
        if ($ch === "\"") { $quoted=true; continue; }
        if ($ch === "{") { $depth++; continue; }
        if ($ch !== "}") continue;
        $depth--;
        if ($depth !== 0) continue;
        $candidate=json_decode(substr($raw,$start,$end-$start+1), true);
        if (is_array($candidate) && !array_is_list($candidate) && $end >= $bestEnd) {
          $final=$candidate; $bestEnd=$end;
        }
        break;
      }
    }
    if (!is_array($final)) { fwrite(STDERR, "no final JSON object in product output\n"); exit(1); }
    file_put_contents($argv[2], json_encode($final, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n");
  ' "$1" "$2" || fail "could not extract the product JSON response from $(basename "$1")"
}

run_duo_json() { # <label> <receipt-file> <public cli args...>
  local label=$1 receipt=$2
  local output="$TMP/${label}.out"
  shift 2
  if ! "$DUO" --envs-file="$ENVS" "$@" >"$output" 2>&1; then
    sed -n '1,240p' "$output" >&2
    fail "$label unexpectedly failed"
  fi
  extract_final_json "$output" "$receipt"
}

run_duo_refusal_json() { # <label> <receipt-file> <public cli args...>
  local label=$1 receipt=$2
  local output="$TMP/${label}.out"
  shift 2
  if "$DUO" --envs-file="$ENVS" "$@" >"$output" 2>&1; then
    sed -n '1,240p' "$output" >&2
    fail "$label unexpectedly succeeded"
  fi
  extract_final_json "$output" "$receipt"
  jq -e '.format == "duo-command-refusal/v1" and .ok == false' "$receipt" >/dev/null \
    || fail "$label did not produce a typed public refusal"
}

write_site_policy() { # <path>
  php -r '
    $policy=[
      "manifests"=>["core","duo-agency-cpt"],
      "policy"=>[
        "options"=>(object)[], "post_meta"=>(object)[],
        "post_types"=>["post","page","attachment","project"],
        "taxonomies"=>["category"],
      ],
      "spec_version"=>2,
    ];
    $bytes=json_encode($policy, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($argv[1], $bytes, LOCK_EX) !== strlen($bytes)) exit(1);
  ' "$1" || fail "could not write isolated site policy"
}

write_envs() {
  php -r '
    $compose=$argv[2];
    $cfg=["envs"=>[
      "source"=>["transport"=>"docker","compose_file"=>$compose,"service"=>"source","repo_path"=>"/siterepo"],
      "target"=>["transport"=>"docker","compose_file"=>$compose,"service"=>"target","repo_path"=>"/siterepo"],
    ]];
    $bytes=json_encode($cfg, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if (file_put_contents($argv[1], $bytes, LOCK_EX) !== strlen($bytes)) exit(1);
  ' "$ENVS" "$DRIVER_COMPOSE" || fail "could not write private public-CLI environment config"
}

assert_driver_registry() {
  local listing report env
  if ! listing="$("$DUO" --envs-file="$ENVS" envs 2>&1)"; then
    printf '%s\n' "$listing" >&2
    fail "explicit public-CLI environment registry could not be loaded"
  fi
  grep -Eq '^source[[:space:]]+docker .* service=source repo_path=/siterepo$' <<<"$listing" \
    || fail "explicit environment registry did not resolve the source Docker driver"
  grep -Eq '^target[[:space:]]+docker .* service=target repo_path=/siterepo$' <<<"$listing" \
    || fail "explicit environment registry did not resolve the target Docker driver"
  for env in source target; do
    report="$TMP/driver-${env}.json"
    if ! "$DUO" --envs-file="$ENVS" driver-capabilities "$env" --operation=capture --format=json >"$report" 2>&1; then
      sed -n '1,160p' "$report" >&2
      fail "$env driver capability preflight failed"
    fi
    jq -e '.format == "duo-environment-driver-capabilities/v1" and .operation == "capture" and .ready == true' \
      "$report" >/dev/null || fail "$env driver did not attest the capture requirements"
  done
}

source_uuid() { source_wp post meta get "$1" _duo_uuid | tr -d '\r\n'; }
target_project_id() {
  target_wp post list --post_type=project --post_status=any --meta_key=_duo_uuid --meta_value="$1" --format=ids \
    | tr -d '[:space:]'
}
target_post_id() {
  target_wp post list --post_status=any --meta_key=_duo_uuid --meta_value="$1" --format=ids \
    | tr -d '[:space:]'
}
target_title() { target_wp post get "$1" --field=post_title | tr -d '\r\n'; }
target_kv() {
  target_wp eval "echo \\Duo\\Ledger::kv_get('$1') ?? '__DUO_NULL__';" \
    | tr -d '\r\n'
}

assert_uuid() { # <label> <uuid>
  local label=$1 uuid=$2
  [[ "$uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$ ]] \
    || fail "$label is not a canonical UUID (got '${uuid:-empty}')"
}

target_map_count() { # <uuid>
  local uuid=$1
  assert_uuid "target map UUID" "$uuid"
  target_wp db query "SELECT COUNT(*) FROM wp_duo_map WHERE uuid = '$uuid'" --skip-column-names \
    | tr -d '[:space:]'
}

target_state_count() { # <uuid>
  local uuid=$1
  assert_uuid "target state UUID" "$uuid"
  target_wp db query "SELECT COUNT(*) FROM wp_duo_state WHERE uuid = '$uuid'" --skip-column-names \
    | tr -d '[:space:]'
}

target_map_bytes() { # <uuid>
  local uuid=$1
  assert_uuid "target map UUID" "$uuid"
  target_wp db query "SELECT uuid, entity_type, id_kind, local_id FROM wp_duo_map WHERE uuid = '$uuid' ORDER BY entity_type, id_kind, local_id" --skip-column-names \
    | tr -d '\r'
}

# Read the stored option bytes directly.  The protected sidebar family is
# deliberately asserted as serialized bytes, so an accidental full-tree
# cache/write/reorder cannot hide behind WordPress's normal unserialization.
target_option_bytes() { # <known-safe-option-name>
  local name=$1
  [[ "$name" =~ ^[a-z0-9_]+$ ]] || fail "internal fixture error: unsafe option name '$name'"
  target_wp eval "
global \$wpdb;
\$raw = \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT option_value FROM {\$wpdb->options} WHERE option_name = %s LIMIT 1\", '$name'
));
echo \$raw === null ? '__DUO_NULL__' : base64_encode(\$raw);
" | tr -d '\r\n'
}

target_sidebar_assignment_bytes() { # <known-safe-sidebar-id>
  local sidebar=$1
  [[ "$sidebar" =~ ^[a-z0-9-]+$ ]] || fail "internal fixture error: unsafe sidebar id '$sidebar'"
  target_wp eval "
\$all = get_option('sidebars_widgets', null);
if (!is_array(\$all) || !array_key_exists('$sidebar', \$all)) {
    echo '__DUO_ABSENT__';
} else {
    echo base64_encode(serialize(\$all['$sidebar']));
}
" | tr -d '\r\n'
}

target_widget_local_id() { # <uuid> <known-safe-widget-type>
  local uuid=$1 type=$2
  assert_uuid "target widget UUID" "$uuid"
  [[ "$type" =~ ^[a-z0-9_]+$ ]] || fail "internal fixture error: unsafe widget type '$type'"
  target_wp db query "SELECT local_id FROM wp_duo_map WHERE uuid = '$uuid' AND id_kind = 'widget_$type' ORDER BY local_id LIMIT 1" --skip-column-names \
    | tr -d '[:space:]'
}

target_sidebar_widget_keys() { # <known-safe-sidebar-id>
  local sidebar=$1
  [[ "$sidebar" =~ ^[a-z0-9-]+$ ]] || fail "internal fixture error: unsafe sidebar id '$sidebar'"
  target_wp eval "
\$all = get_option('sidebars_widgets', []);
echo implode(',', is_array(\$all) ? (array) (\$all['$sidebar'] ?? []) : []);
" | tr -d '\r\n'
}

target_menu_term_id() { # <uuid>
  local uuid=$1
  assert_uuid "target menu UUID" "$uuid"
  target_wp eval "
global \$wpdb;
echo (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT tm.term_id FROM {\$wpdb->termmeta} tm JOIN {\$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = '_duo_uuid' AND tm.meta_value = %s AND tt.taxonomy = 'nav_menu' ORDER BY tm.meta_id ASC LIMIT 1\", '$uuid'
));
" | tr -d '[:space:]'
}

target_menu_item_id() { # <uuid>
  local uuid=$1
  assert_uuid "target menu-item UUID" "$uuid"
  target_wp eval "
global \$wpdb;
echo (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT p.ID FROM {\$wpdb->posts} p JOIN {\$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'nav_menu_item' AND pm.meta_key = '_duo_uuid' AND pm.meta_value = %s ORDER BY pm.meta_id ASC LIMIT 1\", '$uuid'
));
" | tr -d '[:space:]'
}

target_menu_location_id() { # <known-safe-location>
  local location=$1
  [[ "$location" =~ ^[a-z0-9_-]+$ ]] || fail "internal fixture error: unsafe menu location '$location'"
  target_wp eval "
\$locations = get_theme_mod('nav_menu_locations', []);
echo (int) (is_array(\$locations) ? (\$locations['$location'] ?? 0) : 0);
" | tr -d '[:space:]'
}

# The digest covers the existing selected core rows plus sidebar/menu rows,
# their protected raw option surfaces, durable mapping/state, generated
# provider/native surfaces, and generic apply marker vocabulary.
# The promotion lock is included too: it must be absent once each command
# returns, even where the product briefly acquires it internally.
target_boundary_digest() {
  local file="$TMP/boundary-$RANDOM.txt"
  target_wp db query "
    SELECT 'post', ID, post_type, post_status, post_title FROM wp_posts WHERE post_type IN ('post','project') ORDER BY ID;
    SELECT 'menu-term', t.term_id, t.name, t.slug, tt.term_taxonomy_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id, tt.term_taxonomy_id;
    SELECT 'menu-term-meta', tm.term_id, tm.meta_key, tm.meta_value FROM wp_termmeta tm JOIN wp_term_taxonomy tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY tm.term_id, tm.meta_id;
    SELECT 'menu-item', p.ID, p.post_status, p.post_title, p.menu_order FROM wp_posts p WHERE p.post_type = 'nav_menu_item' ORDER BY p.ID;
    SELECT 'menu-item-meta', pm.post_id, pm.meta_key, pm.meta_value FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id WHERE p.post_type = 'nav_menu_item' ORDER BY pm.post_id, pm.meta_id;
    SELECT 'menu-item-rel', tr.object_id, tr.term_taxonomy_id, tr.term_order FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'nav_menu' ORDER BY tr.object_id, tr.term_taxonomy_id;
    SELECT 'option', option_name, option_value FROM wp_options WHERE option_name IN ('duo_agency_project_index','_transient_duo_agency_project_cache','_transient_timeout_duo_agency_project_cache','sidebars_widgets','widget_text','widget_nav_menu') OR option_name = CONCAT('theme_mods_', (SELECT option_value FROM wp_options WHERE option_name = 'stylesheet' LIMIT 1)) ORDER BY option_name;
    SELECT 'map', uuid, entity_type, id_kind, local_id FROM wp_duo_map ORDER BY uuid, id_kind;
    SELECT 'state', uuid, entity_type, content_hash FROM wp_duo_state ORDER BY uuid;
    SELECT 'kv', k, v FROM wp_duo_kv WHERE k IN ('applied_revision','apply_in_progress','promotion_lock','scoped_apply_session') OR k LIKE 'regen_pending:%' ORDER BY k;
  " --skip-column-names >"$file"
  shasum -a 256 "$file" | awk '{print $1}'
}

# A terminal rotation is allowed to replace the active session, retain the old
# one under its immutable archive key, and advance promotion-lock audit
# generation. Authored/plugin state, identity/base maps, and generic apply debt
# must remain byte-stable across a clean rotation/replay.
target_non_terminal_digest() {
  local file="$TMP/non-terminal-$RANDOM.txt"
  target_wp db query "
    SELECT 'post', ID, post_type, post_status, post_title FROM wp_posts WHERE post_type IN ('post','project') ORDER BY ID;
    SELECT 'menu-term', t.term_id, t.name, t.slug, tt.term_taxonomy_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id, tt.term_taxonomy_id;
    SELECT 'menu-term-meta', tm.term_id, tm.meta_key, tm.meta_value FROM wp_termmeta tm JOIN wp_term_taxonomy tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY tm.term_id, tm.meta_id;
    SELECT 'menu-item', p.ID, p.post_status, p.post_title, p.menu_order FROM wp_posts p WHERE p.post_type = 'nav_menu_item' ORDER BY p.ID;
    SELECT 'menu-item-meta', pm.post_id, pm.meta_key, pm.meta_value FROM wp_postmeta pm JOIN wp_posts p ON p.ID = pm.post_id WHERE p.post_type = 'nav_menu_item' ORDER BY pm.post_id, pm.meta_id;
    SELECT 'menu-item-rel', tr.object_id, tr.term_taxonomy_id, tr.term_order FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'nav_menu' ORDER BY tr.object_id, tr.term_taxonomy_id;
    SELECT 'option', option_name, option_value FROM wp_options WHERE option_name IN ('duo_agency_project_index','_transient_duo_agency_project_cache','_transient_timeout_duo_agency_project_cache','sidebars_widgets','widget_text','widget_nav_menu') OR option_name = CONCAT('theme_mods_', (SELECT option_value FROM wp_options WHERE option_name = 'stylesheet' LIMIT 1)) ORDER BY option_name;
    SELECT 'map', uuid, entity_type, id_kind, local_id FROM wp_duo_map ORDER BY uuid, id_kind;
    SELECT 'state', uuid, entity_type, content_hash FROM wp_duo_state ORDER BY uuid;
    SELECT 'kv', k, v FROM wp_duo_kv WHERE k IN ('applied_revision','apply_in_progress') OR k LIKE 'regen_pending:%' ORDER BY k;
  " --skip-column-names >"$file"
  shasum -a 256 "$file" | awk '{print $1}'
}

generic_debt_snapshot() {
  local file="$TMP/debt-$RANDOM.txt"
  target_wp db query "SELECT k, v FROM wp_duo_kv WHERE k = 'apply_in_progress' OR k LIKE 'regen_pending:%' ORDER BY k" --skip-column-names >"$file"
  shasum -a 256 "$file" | awk '{print $1}'
}

assert_generic_scoped_boundary() { # <where>
  local where=$1
  [ "$(target_kv applied_revision)" = "$APPLIED_REVISION_BEFORE" ] \
    || fail "$where advanced generic applied_revision during scoped work"
  [ "$(target_kv apply_in_progress)" = '__DUO_NULL__' ] \
    || fail "$where authored generic apply_in_progress debt"
  [ "$(target_kv promotion_lock)" = '__DUO_NULL__' ] \
    || fail "$where left a promotion lease behind"
  [ "$(generic_debt_snapshot)" = "$GENERIC_DEBT_BEFORE" ] \
    || fail "$where changed generic recovery-debt vocabulary"
}

assert_outside_preserved() {
  local where=$1 outside_id
  outside_id="$(target_project_id "$OUTSIDE_UUID")"
  [ -n "$outside_id" ] || fail "$where removed the out-of-scope target project"
  [ "$(target_title "$outside_id")" = "$OUTSIDE_TARGET_TITLE" ] \
    || fail "$where overwrote the out-of-scope target title"
}

assert_stale_protected_preserved() {
  local where=$1 stale_id
  stale_id="$(target_project_id "$STALE_PROTECTED_UUID")"
  [ -n "$stale_id" ] || fail "$where removed the distinct protected stale-terminal project"
  [ "$(target_title "$stale_id")" = "$STALE_PROTECTED_TARGET_TITLE" ] \
    || fail "$where overwrote the distinct protected stale-terminal project"
}

extract_receipt_bytes() { # <summary-json> <output>
  php -r '
    $r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($r["scoped_receipt"] ?? null)) exit(1);
    echo json_encode($r["scoped_receipt"], JSON_UNESCAPED_SLASHES), "\n";
  ' "$1" >"$2" || fail "could not extract terminal receipt bytes"
}

assert_hash_only_actions() { # <apply-summary>
  jq -e '
    (.actions | type == "array" and length == 2)
    and ([.actions[].kind] | sort == ["native","provider"])
    and all(.actions[];
      (keys | sort) == ["capability_digest","format","kind","operation_hash","receipt_hash","source_hash","status","verified"]
      and .format == "duo-scoped-effect-receipt/v1"
      and .status == "verified" and .verified == true
      and ([.capability_digest,.operation_hash,.receipt_hash,.source_hash] | all(.[]; test("^[a-f0-9]{64}$")))
    )
  ' "$1" >/dev/null || fail "changed-surface action receipts were not the exact public hash-only provider/native shape"
}

assert_scoped_plan() { # <plan> <contract> <selector> <surface> <action-count>
  local plan=$1 contract=$2 selector=$3 surface=$4 action_count=$5 scope_hash
  scope_hash="$(jq -r '.scope_hash' "$contract")"
  jq -e --arg scope_hash "$scope_hash" --arg selector "$selector" --arg surface "$surface" --argjson action_count "$action_count" '
    .format == "duo-scoped-plan/v1"
    and .scope.format == "duo-scope-contract/v1"
    and .scope.scope_hash == $scope_hash
    and (.target | keys | sort == ["ledger_map_root","protected_ledger_map_root","protected_out_of_scope_root","selected_before_root","selected_ledger_map_root","target_observation_hash"])
    and (.target | all(.[]; test("^[a-f0-9]{64}$")))
    and (.selected_surfaces | index($surface) != null)
    and (.selected_actions | length == $action_count)
    and ($action_count == 0 or all(.selected_actions[]; .manifest == "duo-agency-cpt" and (.declaration_hash | test("^[a-f0-9]{64}$"))))
  ' "$plan" >/dev/null || fail "scoped plan lacked target-bound roots/selected action evidence for $selector"
}

publish_source_capture() { # <label> <commit-subject>
  local label=$1 subject=$2
  run_duo_json "${label}-capture" "$TMP/${label}-capture.json" capture source --format=json
  git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
  git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm "$subject"
  git -C "$SITE1" push -q origin main
  git -C "$SITE2" pull -q --ff-only origin main
}

if ! [[ "$PAIR" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
  fail "SCOPED_APPLY_LIVE_PAIR must be a safe lowercase disposable pair name"
fi
if ! [[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]]; then
  fail "SCOPED_APPLY_LIVE_PORT1/2 must be decimal ports"
fi
PORT1_NUM=$((10#$PORT1))
PORT2_NUM=$((10#$PORT2))
if [ "$PORT1_NUM" -lt 8900 ] || [ $((PORT1_NUM % 2)) -ne 0 ] || [ "$PORT2_NUM" -ne $((PORT1_NUM + 1)) ]; then
  fail "the live pair needs a free even port >= 8900 and its immediately following odd port"
fi
if ! [[ "$EXPECTED_SOURCE_SHA" =~ ^[0-9a-fA-F]{7,40}$ ]]; then
  fail "DUO_EXPECTED_SOURCE_SHA must bind this live run to the committed source SHA"
fi
EXPECTED_SOURCE_SHA="$(git rev-parse --verify "${EXPECTED_SOURCE_SHA}^{commit}" 2>/dev/null)" \
  || fail "DUO_EXPECTED_SOURCE_SHA does not resolve to a commit in this standalone clone"
export DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SOURCE_SHA"
export DUO3344_PAIR="$PAIR"
export DUO3344_AGENT_SRC="$ROOT/agent"
export DUO3344_ADAPTER_PACKAGES_SRC="$ROOT/adapter-packages"
export DUO3344_PLATFORM_SRC="$ROOT/platform"
export DUO3344_SITE1="$SITE1"
export DUO3344_SITE2="$SITE2"
export DUO3344_PLUGIN_DIR="$PLUGIN_DIR"
command -v jq >/dev/null || fail "jq is required"
command -v shasum >/dev/null || fail "shasum is required"
command -v lsof >/dev/null || fail "lsof is required for the no-collision port preflight"

say "static/exact-source preflight before allocating pair resources"
bash -n "$0" || fail "live harness shell syntax failed"
bash -n "$ROOT/sandbox/bin/pair.sh" || fail "pair lifecycle shell syntax failed"
git diff --check || fail "working tree has whitespace errors"
! grep -Fq 'DUO_''MANIFESTS_DIR' "$DRIVER_COMPOSE" \
  || fail "public CLI driver reintroduced process-global adapter-library selection"
grep -Fq '${DUO3344_ADAPTER_PACKAGES_SRC}:/var/www/html/wp-content/mu-plugins/adapter-packages:ro' "$DRIVER_COMPOSE" \
  || fail "public CLI driver does not mount packaged adapters beside the agent"
grep -Fq '${DUO3344_PLATFORM_SRC}:/var/www/html/wp-content/mu-plugins/platform:ro' "$DRIVER_COMPOSE" \
  || fail "public CLI driver does not mount the platform contract beside the agent"
[ "$(git rev-parse HEAD)" = "$DUO_EXPECTED_SOURCE_SHA" ] \
  || fail "current source HEAD does not equal DUO_EXPECTED_SOURCE_SHA"
[ -z "$(git status --porcelain)" ] || fail "exact-source live harness requires a clean standalone clone"
assert_pair_list_parser
docker compose -f "$DRIVER_COMPOSE" config >/dev/null || fail "public CLI driver compose config is invalid"
if lsof -nP -iTCP:"$PORT1" -sTCP:LISTEN >/dev/null 2>&1 || lsof -nP -iTCP:"$PORT2" -sTCP:LISTEN >/dev/null 2>&1; then
  fail "requested live ports are already listening; refuse before pair allocation"
fi
pass "static syntax, clean exact source, driver topology, and port shape are safe"

# The explicit overlay is an operator-selected trust input.  Resolve it and
# negotiate both built-in drivers before pair ownership so a malformed or
# stale host registry can never consume a Docker/database namespace.
write_envs
assert_driver_registry
pass "explicit source/target registry resolves trusted capture-capable Docker drivers"

# List before any pair mutation.  pair.sh itself enforces the shared four-pair
# budget; these local namespace checks prevent us from deleting somebody
# else's failed run on the way out.
PAIR_LIST="$(bash "$ROOT/sandbox/bin/pair.sh" list 2>&1)" || fail "could not inspect pair budget/state"
if pair_list_has_exact "$PAIR" <<<"$PAIR_LIST"; then
  fail "pair '$PAIR' already exists; refusing to take ownership"
fi
if [ -e "$SITE1" ] || [ -e "$SITE2" ] || [ -e "$ORIGIN" ]; then
  fail "isolated site-repository namespace for '$PAIR' already exists; refusing destructive reset"
fi

say "create isolated repositories and codebind source before pair creation"
PAIR_OWNED=1
mkdir -p "$SITE1/code/wp-content/plugins/$PLUGIN_DIR"
cp "$ROOT/sandbox/fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "$SITE1/$PLUGIN_FILE"
write_site_policy "$SITE1/site.duo.json"
cp "$ROOT/sandbox/site-repo.gitignore.template" "$SITE1/.gitignore"
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$SITE1" init -q -b main
git -C "$SITE1" remote add origin "../origin-${PAIR}.git"
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'init: DUO-3344 scoped live fixture'
git -C "$SITE1" push -qu origin main
git clone -q "$ORIGIN" "$SITE2"

say "bring up exactly the authorized headless codebound pair"
PAIR_ATTEMPTED=1
bash "$ROOT/sandbox/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --codebind "$PLUGIN_DIR" --headless
source_wp core is-installed >/dev/null || fail "source public-driver endpoint is unavailable"
target_wp core is-installed >/dev/null || fail "target public-driver endpoint is unavailable"
source_wp site empty --yes >/dev/null
target_wp site empty --yes >/dev/null
source_wp plugin activate "$PLUGIN_DIR" >/dev/null
target_wp plugin activate "$PLUGIN_DIR" >/dev/null
source_wp plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "source did not activate the codebound plugin"
target_wp plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" \
  || fail "target did not activate the codebound plugin"
assert_driver_registry
pass "pair is exact-source, plugin codebound, and reachable only through the public CLI driver"

say "seed baseline project state and establish ordinary full-sync metadata"
UPDATE_TITLE_BEFORE='DUO-3344 update before'
UPDATE_TITLE_AFTER='DUO-3344 update after'
UPDATE_TITLE_STALE='DUO-3344 update stale source'
DELETE_TITLE='DUO-3344 delete me'
OUTSIDE_SOURCE_TITLE='DUO-3344 outside source'
OUTSIDE_TARGET_TITLE='DUO-3344 outside target survives'
STALE_PROTECTED_SOURCE_TITLE='DUO-3344 stale protected source'
STALE_PROTECTED_TARGET_TITLE='DUO-3344 stale protected target edit'
UPDATE_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$UPDATE_TITLE_BEFORE" --porcelain)"
DELETE_SOURCE_ID="$(source_wp post create --post_type=post --post_status=publish --post_title="$DELETE_TITLE" --porcelain)"
OUTSIDE_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$OUTSIDE_SOURCE_TITLE" --porcelain)"
STALE_PROTECTED_SOURCE_ID="$(source_wp post create --post_type=project --post_status=publish --post_title="$STALE_PROTECTED_SOURCE_TITLE" --porcelain)"
run_duo_json baseline-capture "$TMP/baseline-capture.json" capture source --format=json
UPDATE_UUID="$(source_uuid "$UPDATE_SOURCE_ID")"
DELETE_UUID="$(source_uuid "$DELETE_SOURCE_ID")"
OUTSIDE_UUID="$(source_uuid "$OUTSIDE_SOURCE_ID")"
STALE_PROTECTED_UUID="$(source_uuid "$STALE_PROTECTED_SOURCE_ID")"
for uuid in "$UPDATE_UUID" "$DELETE_UUID" "$OUTSIDE_UUID" "$STALE_PROTECTED_UUID"; do
  [[ "$uuid" =~ ^[a-f0-9-]{36}$ ]] || fail "capture did not mint a durable project UUID"
done
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: scoped baseline projects'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
run_duo_json baseline-apply "$TMP/baseline-apply.json" apply target --adopt-by-slug=terms,posts --default-author=admin --format=json
jq -e '.canary == "clean"' "$TMP/baseline-apply.json" >/dev/null || fail "ordinary baseline apply did not converge"
APPLIED_REVISION_BEFORE="$(target_kv applied_revision)"
[[ "$APPLIED_REVISION_BEFORE" =~ ^[a-f0-9]{64}$ ]] || fail "ordinary baseline did not establish applied_revision"
GENERIC_DEBT_BEFORE="$(generic_debt_snapshot)"
[ "$(target_kv apply_in_progress)" = '__DUO_NULL__' ] || fail "ordinary baseline left generic apply debt"
pass "baseline is converged; generic revision/debt witnesses are captured for scoped-boundary checks"

say "apply a clean scoped no-op without opening an empty authored transaction"
NOOP_CONTRACT="$TMP/noop.scope.json"
run_duo_json noop-scope "$NOOP_CONTRACT" scope source "--roots=post:${UPDATE_UUID}" --contract --format=json
run_duo_json noop-apply "$TMP/noop-apply.json" apply target "--scope-contract=$NOOP_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .applied == 0
  and (.actions | length == 0)
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .scoped_receipt.phase == "complete"
' "$TMP/noop-apply.json" >/dev/null || fail "clean scoped no-op did not converge as a terminal no-mutation result"
UPDATE_TARGET_ID="$(target_project_id "$UPDATE_UUID")"
[ -n "$UPDATE_TARGET_ID" ] || fail "baseline target lacks the selected no-op project"
[ "$(target_title "$UPDATE_TARGET_ID")" = "$UPDATE_TITLE_BEFORE" ] \
  || fail "clean scoped no-op changed the selected authored state"
assert_generic_scoped_boundary "clean scoped no-op"
NOOP_TERMINAL_SESSION="$(target_kv scoped_apply_session)"
[ "$NOOP_TERMINAL_SESSION" != '__DUO_NULL__' ] \
  || fail "clean scoped no-op did not persist its terminal lost-response receipt"
pass "clean scoped no-op terminalized without authored/provider/native work or global revision debt"

say "rotate the UPDATE terminal through a second clean baseline scope, then publicly replay its archive"
extract_receipt_bytes "$TMP/noop-apply.json" "$TMP/noop-terminal-first.bytes"
NOOP_AUTHORITY_HASH="$(jq -r '.scoped_receipt.authority_hash' "$TMP/noop-apply.json")"
[[ "$NOOP_AUTHORITY_HASH" =~ ^[a-f0-9]{64}$ ]] \
  || fail "first clean no-op did not publish an authority hash"
[ "$OUTSIDE_UUID" != "$UPDATE_UUID" ] \
  || fail "archive replay fixture did not select a distinct second baseline project"
ARCHIVE_NOOP_CONTRACT="$TMP/archive-noop.scope.json"
ARCHIVE_ROTATION_NON_TERMINAL_BEFORE="$(target_non_terminal_digest)"
run_duo_json archive-noop-scope "$ARCHIVE_NOOP_CONTRACT" scope source "--roots=post:${OUTSIDE_UUID}" --contract --format=json
jq -e --arg uuid "$OUTSIDE_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["post:" + $uuid]
  and (.live.roots | length == 1 and .[0].entity == $uuid)
  and (.tombstones | length == 0)
' "$ARCHIVE_NOOP_CONTRACT" >/dev/null || fail "second clean no-op did not bind its distinct baseline project"
run_duo_json archive-noop-apply "$TMP/archive-noop-apply.json" apply target "--scope-contract=$ARCHIVE_NOOP_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .applied == 0
  and (.actions | length == 0)
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .scoped_receipt.phase == "complete"
' "$TMP/archive-noop-apply.json" >/dev/null || fail "second baseline scope did not terminalize as a clean no-op"
[ "$(target_non_terminal_digest)" = "$ARCHIVE_ROTATION_NON_TERMINAL_BEFORE" ] \
  || fail "second clean no-op changed target bytes beyond its terminal-session rotation"
assert_generic_scoped_boundary "second clean no-op terminal rotation"
SECOND_NOOP_TERMINAL_SESSION="$(target_kv scoped_apply_session)"
[ "$SECOND_NOOP_TERMINAL_SESSION" != '__DUO_NULL__' ] \
  || fail "second clean no-op did not leave an active terminal slot"
[ "$SECOND_NOOP_TERMINAL_SESSION" != "$NOOP_TERMINAL_SESSION" ] \
  || fail "different clean scope did not rotate the first terminal session"
NOOP_ARCHIVED_SESSION="$(target_kv "scoped_apply_terminal:${NOOP_AUTHORITY_HASH}")"
[ "$NOOP_ARCHIVED_SESSION" = "$NOOP_TERMINAL_SESSION" ] \
  || fail "terminal rotation did not retain the first terminal's exact canonical session bytes"

ARCHIVED_REPLAY_BOUNDARY_BEFORE="$(target_boundary_digest)"
ARCHIVED_REPLAY_NON_TERMINAL_BEFORE="$(target_non_terminal_digest)"
run_duo_json archived-noop-replay "$TMP/archived-noop-replay.json" apply target "--scope-contract=$NOOP_CONTRACT" --format=json
jq -e '.format == "duo-scoped-apply-result/v1" and .replayed == true and .applied == 0 and (.actions | length == 0) and .verification == null' \
  "$TMP/archived-noop-replay.json" >/dev/null || fail "archived UPDATE terminal did not take the public no-mutation replay path"
extract_receipt_bytes "$TMP/archived-noop-replay.json" "$TMP/noop-terminal-archived-replay.bytes"
cmp -s "$TMP/noop-terminal-first.bytes" "$TMP/noop-terminal-archived-replay.bytes" \
  || fail "archived UPDATE replay changed the original terminal receipt bytes"
[ "$(target_boundary_digest)" = "$ARCHIVED_REPLAY_BOUNDARY_BEFORE" ] \
  || fail "archived UPDATE replay mutated target/session evidence"
[ "$(target_non_terminal_digest)" = "$ARCHIVED_REPLAY_NON_TERMINAL_BEFORE" ] \
  || fail "archived UPDATE replay mutated non-terminal target bytes"
[ "$(target_kv "scoped_apply_terminal:${NOOP_AUTHORITY_HASH}")" = "$NOOP_ARCHIVED_SESSION" ] \
  || fail "archived UPDATE replay changed its retained terminal bytes"
[ "$(target_kv scoped_apply_session)" = "$SECOND_NOOP_TERMINAL_SESSION" ] \
  || fail "archived UPDATE replay changed the second active terminal slot"
assert_generic_scoped_boundary "archived clean no-op replay"
# Later plan/refusal checks protect whichever terminal is currently active;
# the UPDATE terminal above is intentionally archived by this point.
NOOP_TERMINAL_SESSION="$SECOND_NOOP_TERMINAL_SESSION"
pass "public archived UPDATE replay is byte-stable and preserves the rotated active terminal without target/debt mutation"

say "publish one update plus one tombstone, then preserve a target-only out-of-scope edit"
source_wp post update "$UPDATE_SOURCE_ID" --post_title="$UPDATE_TITLE_AFTER" >/dev/null
source_wp post delete "$DELETE_SOURCE_ID" --force >/dev/null
run_duo_json changed-capture "$TMP/changed-capture.json" capture source --format=json
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: scoped update and tombstone'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
OUTSIDE_TARGET_ID="$(target_project_id "$OUTSIDE_UUID")"
[ -n "$OUTSIDE_TARGET_ID" ] || fail "baseline target lacks the intended out-of-scope project"
target_wp post update "$OUTSIDE_TARGET_ID" --post_title="$OUTSIDE_TARGET_TITLE" >/dev/null
pass "source has independent update/tombstone intent; the target-only project edit is protected evidence"

say "scope/plan the selected tombstone through the public host CLI"
TOMB_CONTRACT="$TMP/tombstone.scope.json"
run_duo_json tombstone-scope "$TOMB_CONTRACT" scope source "--roots=tombstone:${DELETE_UUID}" --contract --format=json
jq -e --arg uuid "$DELETE_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["tombstone:" + $uuid]
  and (.tombstones | length == 1 and .[0].uuid == $uuid)
  and (.potential_actions | length == 0)
  and (.potential_providers | length == 0)
' "$TOMB_CONTRACT" >/dev/null || fail "core-post tombstone contract widened into unrelated plugin effects"
run_duo_json tombstone-plan "$TMP/tombstone-plan.json" plan target "--scope-contract=$TOMB_CONTRACT" --format=json
assert_scoped_plan "$TMP/tombstone-plan.json" "$TOMB_CONTRACT" "tombstone:${DELETE_UUID}" post:post 0
[ "$(target_kv scoped_apply_session)" = "$NOOP_TERMINAL_SESSION" ] \
  || fail "read-only scoped plan changed the prior terminal session evidence"
assert_generic_scoped_boundary "read-only scoped plan"
pass "public scoped plan reports fresh target-bound roots without creating or changing session/debt"

say "prove --with-deletes is an early scoped refusal with no session or authored mutation"
EARLY_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json tombstone-without-deletes "$TMP/tombstone-without-deletes.json" apply target "--scope-contract=$TOMB_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused" and (.message | contains("--with-deletes"))' \
  "$TMP/tombstone-without-deletes.json" >/dev/null \
  || fail "missing --with-deletes did not surface the public scoped delete refusal"
[ "$(target_kv scoped_apply_session)" = "$NOOP_TERMINAL_SESSION" ] \
  || fail "missing --with-deletes changed the prior terminal session evidence"
[ "$(target_boundary_digest)" = "$EARLY_BOUNDARY_BEFORE" ] \
  || fail "missing --with-deletes changed selected/protected/ledger/action state"
assert_generic_scoped_boundary "early delete refusal"
assert_outside_preserved "early delete refusal"
DELETE_TARGET_ID="$(target_post_id "$DELETE_UUID")"
[ -n "$DELETE_TARGET_ID" ] || fail "missing --with-deletes removed the selected target project"
pass "tombstone refusal preserved prior terminal evidence before new session, authored, provider/native, or generic mutation"

say "apply selected tombstone with explicit deletion authority and verify changed surfaces"
run_duo_json tombstone-apply "$TMP/tombstone-apply.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .verification.selected_deletions == 1
  and (.verification.protected_out_of_scope_root | test("^[a-f0-9]{64}$"))
  and (.verification.protected_ledger_map_root | test("^[a-f0-9]{64}$"))
  and .scoped_receipt.phase == "complete"
  and (.actions | length == 0)
  and ([.scoped_receipt.authority_hash,.scoped_receipt.selected_ledger_map_hash,.scoped_receipt.protected_ledger_map_hash,.scoped_receipt.terminal_hash] | all(.[]; test("^[a-f0-9]{64}$")))
' "$TMP/tombstone-apply.json" >/dev/null || fail "scoped tombstone apply lacked terminal target-bound convergence evidence"
[ -z "$(target_post_id "$DELETE_UUID")" ] || fail "scoped tombstone apply left the selected core post live"
assert_outside_preserved "scoped tombstone apply"
assert_generic_scoped_boundary "scoped tombstone apply"
pass "adapter-certified core deletion converged without widening into unrelated plugin effects"

say "replay the terminal tombstone authority and require byte-stable receipt evidence"
run_duo_json tombstone-replay "$TMP/tombstone-replay.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '.format == "duo-scoped-apply-result/v1" and .replayed == true and .applied == 0 and (.actions | length == 0) and .verification == null' \
  "$TMP/tombstone-replay.json" >/dev/null || fail "terminal replay did not take the no-mutation replay path"
extract_receipt_bytes "$TMP/tombstone-apply.json" "$TMP/tombstone-terminal-first.bytes"
extract_receipt_bytes "$TMP/tombstone-replay.json" "$TMP/tombstone-terminal-replay.bytes"
cmp -s "$TMP/tombstone-terminal-first.bytes" "$TMP/tombstone-terminal-replay.bytes" \
  || fail "terminal replay changed durable terminal receipt bytes"
assert_outside_preserved "terminal replay"
assert_generic_scoped_boundary "terminal replay"
pass "terminal replay returned exactly the original durable receipt and no fresh action work"

say "make the completed tombstone authority stale and require replay refusal without engine mutation"
STALE_PROTECTED_TARGET_ID="$(target_project_id "$STALE_PROTECTED_UUID")"
[ -n "$STALE_PROTECTED_TARGET_ID" ] || fail "baseline target lacks distinct stale-terminal protected project"
target_wp post update "$STALE_PROTECTED_TARGET_ID" --post_title="$STALE_PROTECTED_TARGET_TITLE" >/dev/null
STALE_TERMINAL_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json stale-terminal "$TMP/stale-terminal.json" apply target "--scope-contract=$TOMB_CONTRACT" --with-deletes --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused" and (.message | contains("no mutation or replay attempted"))' \
  "$TMP/stale-terminal.json" >/dev/null || fail "stale terminal authority did not refuse its replay boundary"
[ "$(target_boundary_digest)" = "$STALE_TERMINAL_BOUNDARY_BEFORE" ] \
  || fail "stale terminal refusal mutated target evidence"
assert_outside_preserved "stale terminal refusal"
assert_stale_protected_preserved "stale terminal refusal"
assert_generic_scoped_boundary "stale terminal refusal"
pass "completed authority refuses stale protected-target replay before follow-on engine mutation"

say "scope/plan/apply the independent selected update while preserving the outside target edit"
UPDATE_CONTRACT="$TMP/update.scope.json"
run_duo_json update-scope "$UPDATE_CONTRACT" scope source "--roots=post:${UPDATE_UUID}" --contract --format=json
jq -e --arg uuid "$UPDATE_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["post:" + $uuid]
  and (.live.roots | length == 1 and .[0].entity == $uuid)
  and (.tombstones | length == 0)
' "$UPDATE_CONTRACT" >/dev/null || fail "update contract did not bind exactly the selected live project"
run_duo_json update-plan "$TMP/update-plan.json" plan target "--scope-contract=$UPDATE_CONTRACT" --format=json
assert_scoped_plan "$TMP/update-plan.json" "$UPDATE_CONTRACT" "post:${UPDATE_UUID}" post:project 2
target_wp transient set duo_agency_project_cache stale-before-scoped-update 600 >/dev/null
[ "$(target_wp transient get duo_agency_project_cache | tr -d '\r\n')" = stale-before-scoped-update ] \
  || fail "could not establish the selected update's native-action changed surface"
run_duo_json update-apply "$TMP/update-apply.json" apply target "--scope-contract=$UPDATE_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.format == "duo-scoped-convergence/v1"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/update-apply.json" >/dev/null || fail "scoped update did not return target-bound convergence evidence"
assert_hash_only_actions "$TMP/update-apply.json"
UPDATE_TARGET_ID="$(target_project_id "$UPDATE_UUID")"
[ "$(target_title "$UPDATE_TARGET_ID")" = "$UPDATE_TITLE_AFTER" ] || fail "scoped update did not apply selected source title"
CACHE_ROWS="$(target_wp db query "SELECT COUNT(*) FROM wp_options WHERE option_name IN ('_transient_duo_agency_project_cache','_transient_timeout_duo_agency_project_cache')" --skip-column-names | awk 'NF {last=$0} END {print last}')"
[ "$CACHE_ROWS" = 0 ] || fail "native transient.delete did not clear the stale cache"
TARGET_PROJECT_IDS="$(target_wp post list --post_type=project --post_status=publish --orderby=ID --order=ASC --format=ids | tr -d '\r\n')"
TARGET_PROJECT_IDS_JSON="$(jq -cn '$ARGS.positional | map(tonumber)' --args $TARGET_PROJECT_IDS)"
target_wp option get duo_agency_project_index --format=json >"$TMP/index-after-update.json"
jq -e --argjson ids "$TARGET_PROJECT_IDS_JSON" '.ids == $ids and (.titles | length == ($ids | length))' "$TMP/index-after-update.json" >/dev/null \
  || fail "plugin-owned provider did not rebuild the target-local project index"
assert_outside_preserved "scoped update apply"
assert_stale_protected_preserved "scoped update apply"
assert_generic_scoped_boundary "scoped update apply"
pass "new scoped authority updates only its selected project; plugin/native effects are hash-only and generic/outside state stays preserved"

say "tamper the host-local contract and prove public host refusal before target mutation"
TAMPERED_CONTRACT="$TMP/tampered-update.scope.json"
php -r '
  $c=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
  $c["scope_hash"]=str_repeat("0",64);
  file_put_contents($argv[2],json_encode($c,JSON_UNESCAPED_SLASHES)."\n");
' "$UPDATE_CONTRACT" "$TAMPERED_CONTRACT" || fail "could not create controlled tampered contract"
TAMPERED_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json tampered-contract "$TMP/tampered-contract.json" plan target "--scope-contract=$TAMPERED_CONTRACT" --format=json
jq -e '.command == "plan" and .reason_code == "scope_contract_invalid"' "$TMP/tampered-contract.json" >/dev/null \
  || fail "tampered local scope contract was not refused at the host boundary"
[ "$(target_boundary_digest)" = "$TAMPERED_BOUNDARY_BEFORE" ] \
  || fail "tampered host contract caused a target mutation"
assert_outside_preserved "tampered contract refusal"
assert_stale_protected_preserved "tampered contract refusal"
assert_generic_scoped_boundary "tampered contract refusal"
pass "tampered local evidence is rejected by public CLI before target work or mutation"

say "advance source again and require stale compact authority refusal without mutation"
source_wp post update "$UPDATE_SOURCE_ID" --post_title="$UPDATE_TITLE_STALE" >/dev/null
run_duo_json stale-source-capture "$TMP/stale-source-capture.json" capture source --format=json
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test add -A
git -C "$SITE1" -c user.name=duo3344-source -c user.email=duo3344-source@example.test commit -qm 'capture: stale scoped authority source advance'
git -C "$SITE1" push -q origin main
git -C "$SITE2" pull -q --ff-only origin main
STALE_SOURCE_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json stale-source-contract "$TMP/stale-source-contract.json" apply target "--scope-contract=$UPDATE_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "apply_refused"' "$TMP/stale-source-contract.json" >/dev/null \
  || fail "stale source-bound compact authority did not refuse"
[ "$(target_boundary_digest)" = "$STALE_SOURCE_BOUNDARY_BEFORE" ] \
  || fail "stale source authority refusal changed target state"
assert_outside_preserved "stale source authority refusal"
assert_stale_protected_preserved "stale source authority refusal"
assert_generic_scoped_boundary "stale source authority refusal"
pass "stale source artifact cannot reuse old scoped authority and leaves target untouched"

say "seed scoped sidebar/menu fixtures with a protected target-only owner"
MENU_A_TITLE='DUO-3344 selected menu A'
MENU_B_TITLE='DUO-3344 protected menu B'
MENU_A_ITEM_TITLE='DUO-3344 selected menu item'
MENU_B_ITEM_TITLE='DUO-3344 protected menu item'
MENU_A_SOURCE_ID="$(source_wp menu create "$MENU_A_TITLE" --porcelain)"
MENU_B_SOURCE_ID="$(source_wp menu create "$MENU_B_TITLE" --porcelain)"
MENU_A_SOURCE_ITEM_ID="$(source_wp menu item add-custom "$MENU_A_SOURCE_ID" "$MENU_A_ITEM_TITLE" 'https://example.test/duo3344-a' --porcelain)"
MENU_B_SOURCE_ITEM_ID="$(source_wp menu item add-custom "$MENU_B_SOURCE_ID" "$MENU_B_ITEM_TITLE" 'https://example.test/duo3344-b' --porcelain)"
[[ "$MENU_A_SOURCE_ID" =~ ^[1-9][0-9]*$ && "$MENU_B_SOURCE_ID" =~ ^[1-9][0-9]*$ \
  && "$MENU_A_SOURCE_ITEM_ID" =~ ^[1-9][0-9]*$ && "$MENU_B_SOURCE_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture source menu/sidebar fixture ids"
source_wp eval "
\$menuB = (int) $MENU_B_SOURCE_ID;
update_option('widget_text', [
    31 => ['title' => 'DUO-3344 selected sidebar A', 'text' => 'Selected sidebar body', 'filter' => false, 'visual' => true],
    '_multiwidget' => 1,
]);
update_option('widget_nav_menu', [
    32 => ['title' => 'DUO-3344 protected sidebar B desired', 'nav_menu' => \$menuB],
    '_multiwidget' => 1,
]);
update_option('sidebars_widgets', [
    'sidebar-a' => ['text-31'],
    'sidebar-b' => ['nav_menu-32'],
    'wp_inactive_widgets' => [],
    'array_version' => 3,
]);
" >/dev/null
publish_source_capture nested-initial 'capture: DUO-3344 scoped sidebar/menu fixture'
MENU_A_SLUG="$(source_wp term get nav_menu "$MENU_A_SOURCE_ID" --field=slug | tr -d '\r\n')"
[[ "$MENU_A_SLUG" =~ ^[a-z0-9-]+$ ]] || fail "source menu A did not receive a safe slug"
MENU_A_UUID="$(source_wp eval "echo (string) get_term_meta($MENU_A_SOURCE_ID, '_duo_uuid', true);" | tr -d '\r\n')"
MENU_B_UUID="$(source_wp eval "echo (string) get_term_meta($MENU_B_SOURCE_ID, '_duo_uuid', true);" | tr -d '\r\n')"
MENU_A_ITEM_UUID="$(source_uuid "$MENU_A_SOURCE_ITEM_ID")"
MENU_B_ITEM_UUID="$(source_uuid "$MENU_B_SOURCE_ITEM_ID")"
for pair in \
  "source selected menu:$MENU_A_UUID" \
  "source protected menu:$MENU_B_UUID" \
  "source selected menu item:$MENU_A_ITEM_UUID" \
  "source protected menu item:$MENU_B_ITEM_UUID"; do
  assert_uuid "${pair%%:*}" "${pair#*:}"
done
SIDEBAR_A_FILE="$SITE1/state/sidebars/sidebar-a.json"
SIDEBAR_B_FILE="$SITE1/state/sidebars/sidebar-b.json"
[ -f "$SIDEBAR_A_FILE" ] && [ -f "$SIDEBAR_B_FILE" ] \
  || fail "source capture did not materialize both scoped sidebar files"
SIDEBAR_A_WIDGET_UUID="$(jq -r '[.widgets[] | select(.type == "text") | .uuid] | if length == 1 then .[0] else empty end' "$SIDEBAR_A_FILE")"
SIDEBAR_B_WIDGET_UUID="$(jq -r '[.widgets[] | select(.type == "nav_menu") | .uuid] | if length == 1 then .[0] else empty end' "$SIDEBAR_B_FILE")"
assert_uuid "source selected sidebar widget" "$SIDEBAR_A_WIDGET_UUID"
assert_uuid "source protected sidebar widget" "$SIDEBAR_B_WIDGET_UUID"

TARGET_B_MENU_ID="$(target_wp menu create "$MENU_B_TITLE" --porcelain)"
TARGET_B_ITEM_ID="$(target_wp menu item add-custom "$TARGET_B_MENU_ID" 'DUO-3344 target-only protected item' 'https://example.test/duo3344-target-b' --porcelain)"
[[ "$TARGET_B_MENU_ID" =~ ^[1-9][0-9]*$ && "$TARGET_B_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture protected target menu fixture ids"
target_wp eval "
global \$wpdb;
\$menu = (int) $TARGET_B_MENU_ID;
\$item = (int) $TARGET_B_ITEM_ID;
\$tt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", \$menu
));
if (\$tt < 1) { fwrite(STDERR, 'missing target nav_menu taxonomy'); exit(1); }
\$menuUuid = wp_generate_uuid4();
\$itemUuid = wp_generate_uuid4();
update_term_meta(\$menu, '_duo_uuid', \$menuUuid);
update_post_meta(\$item, '_duo_uuid', \$itemUuid);
\\Duo\\Ledger::set(\$menuUuid, 'menu', \\Duo\\Ledger::KIND_TERM, \$menu);
\\Duo\\Ledger::set(\$menuUuid, 'menu', \\Duo\\Ledger::KIND_TT, \$tt);
\\Duo\\Ledger::set(\$itemUuid, 'menu_item', \\Duo\\Ledger::KIND_POST, \$item);
echo wp_json_encode(['menu_uuid' => \$menuUuid, 'item_uuid' => \$itemUuid]);
" >"$TMP/target-menu-b-identities.json"
jq -e '
  (.menu_uuid | test("^[0-9a-f-]{36}$"))
  and (.item_uuid | test("^[0-9a-f-]{36}$"))
' "$TMP/target-menu-b-identities.json" >/dev/null \
  || fail "could not establish durable protected target menu identities"
TARGET_B_MENU_UUID="$(jq -r '.menu_uuid' "$TMP/target-menu-b-identities.json")"
TARGET_B_ITEM_UUID="$(jq -r '.item_uuid' "$TMP/target-menu-b-identities.json")"
assert_uuid "target protected menu" "$TARGET_B_MENU_UUID"
assert_uuid "target protected menu item" "$TARGET_B_ITEM_UUID"
target_wp eval "
\$menu = (int) $TARGET_B_MENU_ID;
update_option('widget_text', ['_multiwidget' => 1]);
update_option('widget_nav_menu', [
    7 => ['title' => 'DUO-3344 target B family must remain raw', 'nav_menu' => \$menu],
    '_multiwidget' => 1,
]);
update_option('sidebars_widgets', [
    'sidebar-a' => [],
    'sidebar-b' => [],
    'wp_inactive_widgets' => [],
    'array_version' => 3,
]);
" >/dev/null
TARGET_B_MENU_MAP_BEFORE="$(target_map_bytes "$TARGET_B_MENU_UUID")"
TARGET_B_ITEM_MAP_BEFORE="$(target_map_bytes "$TARGET_B_ITEM_UUID")"
TARGET_B_WIDGET_FAMILY_BEFORE="$(target_option_bytes widget_nav_menu)"
TARGET_B_SIDEBAR_BEFORE="$(target_sidebar_assignment_bytes sidebar-b)"
[ -n "$TARGET_B_MENU_MAP_BEFORE" ] && [ -n "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "protected target menu/map fixture was not durable"
[ "$(target_map_count "$SIDEBAR_B_WIDGET_UUID")" = 0 ] \
  || fail "unselected source sidebar B widget unexpectedly has a target map before scoped apply"
pass "nested source owners and distinct protected target menu/sidebar evidence are ready"

say "refuse a stale selected menu-item mapping before session or target mutation"
MENU_A_CONTRACT="$TMP/menu-a.scope.json"
run_duo_json menu-a-scope "$MENU_A_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
jq -e --arg selector "menu:${MENU_A_SLUG}" '
  .format == "duo-scope-contract/v1"
  and .selectors == [$selector]
  and (.live.roots | length == 1)
' "$MENU_A_CONTRACT" >/dev/null || fail "selected menu scope did not resolve exactly one live menu root"
target_wp db query "INSERT INTO wp_duo_map (uuid, entity_type, id_kind, local_id) VALUES ('$MENU_A_ITEM_UUID', 'menu_item', 'post', 999999)" >/dev/null
[ "$(target_map_count "$MENU_A_ITEM_UUID")" = 1 ] \
  || fail "could not manufacture stale selected menu-item map fixture"
STALE_MENU_MAP_SESSION_BEFORE="$(target_kv scoped_apply_session)"
STALE_MENU_MAP_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json stale-selected-menu-item-map "$TMP/stale-selected-menu-item-map.json" apply target "--scope-contract=$MENU_A_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "scoped_identity_recovery_required"' "$TMP/stale-selected-menu-item-map.json" >/dev/null \
  || fail "stale selected menu-item map did not surface a public scoped apply refusal"
[ "$(target_kv scoped_apply_session)" = "$STALE_MENU_MAP_SESSION_BEFORE" ] \
  || fail "stale selected menu-item map opened or changed a scoped session"
[ "$(target_boundary_digest)" = "$STALE_MENU_MAP_BOUNDARY_BEFORE" ] \
  || fail "stale selected menu-item map refusal changed maps/options/protected target state"
assert_generic_scoped_boundary "stale selected menu-item map refusal"
target_wp db query "DELETE FROM wp_duo_map WHERE uuid = '$MENU_A_ITEM_UUID' AND id_kind = 'post'" >/dev/null
[ "$(target_map_count "$MENU_A_ITEM_UUID")" = 0 ] \
  || fail "stale selected menu-item map fixture cleanup left a live map"
pass "stale selected nested map refuses before session/mutation and is cleaned from the fixture"

say "apply a selected menu nested-item create without widening into target menu B"
MENU_CREATE_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_json menu-create-plan "$TMP/menu-create-plan.json" plan target "--scope-contract=$MENU_A_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-plan/v1"
  and (.target | keys | sort == ["ledger_map_root","protected_ledger_map_root","protected_out_of_scope_root","selected_before_root","selected_ledger_map_root","target_observation_hash"])
' "$TMP/menu-create-plan.json" >/dev/null || fail "selected menu plan did not publish bounded map/protected roots"
[ "$(target_boundary_digest)" = "$MENU_CREATE_BOUNDARY_BEFORE" ] \
  || fail "read-only selected menu plan changed target sidebar/menu/map evidence"
run_duo_json menu-create-apply "$TMP/menu-create-apply.json" apply target "--scope-contract=$MENU_A_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/menu-create-apply.json" >/dev/null || fail "selected menu nested-item create did not converge"
TARGET_A_MENU_ID="$(target_menu_term_id "$MENU_A_UUID")"
TARGET_A_ITEM_ID="$(target_menu_item_id "$MENU_A_ITEM_UUID")"
[[ "$TARGET_A_MENU_ID" =~ ^[1-9][0-9]*$ && "$TARGET_A_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "selected menu create did not materialize its menu and nested item"
[ "$(target_map_count "$MENU_A_ITEM_UUID")" = 1 ] \
  || fail "selected menu nested item did not receive a selected ledger map"
[ "$(target_map_bytes "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_MAP_BEFORE" ] \
  || fail "selected menu create changed protected menu B map bytes"
[ "$(target_map_bytes "$TARGET_B_ITEM_UUID")" = "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "selected menu create changed protected B nested-item map bytes"
assert_generic_scoped_boundary "selected menu nested-item create"
pass "selected menu nested map is writable while protected menu B maps remain exact"

say "retire an exact draft target-old menu item while selected menu A has canonical work"
MENU_A_SOURCE_WORK_ITEM_ID="$(source_wp menu item add-custom "$MENU_A_SOURCE_ID" 'DUO-3344 selected menu canonical work' 'https://example.test/duo3344-a-work' --porcelain)"
[[ "$MENU_A_SOURCE_WORK_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture selected menu A canonical work item"
publish_source_capture menu-target-old-source-work 'capture: DUO-3344 selected menu canonical work'
MENU_A_SOURCE_WORK_ITEM_UUID="$(source_uuid "$MENU_A_SOURCE_WORK_ITEM_ID")"
assert_uuid "source selected menu canonical work item" "$MENU_A_SOURCE_WORK_ITEM_UUID"

TARGET_A_OLD_ITEM_UUID="$(target_wp eval 'echo wp_generate_uuid4();' | tr -d '\r\n')"
assert_uuid "target selected draft target-old menu item" "$TARGET_A_OLD_ITEM_UUID"
TARGET_A_OLD_ITEM_ID="$(target_wp post create --post_type=nav_menu_item --post_status=draft --post_title='DUO-3344 selected draft target-old item' --porcelain)"
[[ "$TARGET_A_OLD_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture selected draft target-old menu item"
target_wp eval "
global \$wpdb;
\$menu = (int) $TARGET_A_MENU_ID;
\$item = (int) $TARGET_A_OLD_ITEM_ID;
\$uuid = '$TARGET_A_OLD_ITEM_UUID';
\$tt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", \$menu
));
if (\$tt < 1
    || \$wpdb->insert(\$wpdb->term_relationships, ['object_id' => \$item, 'term_taxonomy_id' => \$tt, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
    fwrite(STDERR, 'could not attach exact draft target-old item to selected menu'); exit(1);
}
update_post_meta(\$item, '_duo_uuid', \$uuid);
\\Duo\\Ledger::set(\$uuid, 'menu_item', \\Duo\\Ledger::KIND_POST, \$item);
\\Duo\\Ledger::set_state_hash(\$uuid, 'menu_item', hash('sha256', 'DUO-3344 selected draft target-old state'));
\$rels = array_map('intval', \$wpdb->get_col(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id\", \$item
)));
\$maps = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT COUNT(*) FROM {\$wpdb->prefix}duo_map WHERE uuid = %s AND entity_type = 'menu_item' AND id_kind = 'post' AND local_id = %d\", \$uuid, \$item
));
\$states = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT COUNT(*) FROM {\$wpdb->prefix}duo_state WHERE uuid = %s AND entity_type = 'menu_item'\", \$uuid
));
if (\$rels !== [\$tt] || \$maps !== 1 || \$states !== 1) {
    fwrite(STDERR, 'draft target-old fixture is not an exact selected physical identity'); exit(1);
}
echo wp_json_encode(['item_id' => \$item, 'uuid' => \$uuid, 'term_taxonomy_id' => \$tt]);
" >"$TMP/target-menu-a-draft-old.json"
jq -e --arg uuid "$TARGET_A_OLD_ITEM_UUID" --argjson item "$TARGET_A_OLD_ITEM_ID" '
  .uuid == $uuid and .item_id == $item and (.term_taxonomy_id | type == "number" and . > 0)
' "$TMP/target-menu-a-draft-old.json" >/dev/null \
  || fail "selected draft target-old fixture did not retain its exact sidecar/map/sole-menu shape"
[ "$(target_map_count "$TARGET_A_OLD_ITEM_UUID")" = 1 ] \
  && [ "$(target_state_count "$TARGET_A_OLD_ITEM_UUID")" = 1 ] \
  || fail "selected draft target-old fixture did not retain map/state rows"

MENU_TARGET_OLD_CONTRACT="$TMP/menu-a-target-old.scope.json"
run_duo_json menu-target-old-scope "$MENU_TARGET_OLD_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
MENU_TARGET_OLD_PLAN_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_json menu-target-old-plan "$TMP/menu-target-old-plan.json" plan target "--scope-contract=$MENU_TARGET_OLD_CONTRACT" --format=json
[ "$(target_boundary_digest)" = "$MENU_TARGET_OLD_PLAN_BOUNDARY_BEFORE" ] \
  || fail "selected draft target-old plan changed target state before authoring"
run_duo_json menu-target-old-apply "$TMP/menu-target-old-apply.json" apply target "--scope-contract=$MENU_TARGET_OLD_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/menu-target-old-apply.json" >/dev/null \
  || fail "exact draft target-old menu item did not converge through selected menu apply"
TARGET_A_SOURCE_WORK_ITEM_ID="$(target_menu_item_id "$MENU_A_SOURCE_WORK_ITEM_UUID")"
[[ "$TARGET_A_SOURCE_WORK_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "selected menu target-old case did not author its real canonical source work"
[ "$(target_map_count "$MENU_A_SOURCE_WORK_ITEM_UUID")" = 1 ] \
  || fail "selected menu canonical work did not receive a nested map"
[ "$(target_wp db query "SELECT COUNT(*) FROM wp_posts WHERE ID = $TARGET_A_OLD_ITEM_ID" --skip-column-names | tr -d '[:space:]')" = 0 ] \
  || fail "selected draft target-old menu post survived selected finalization"
[ "$(target_menu_item_id "$TARGET_A_OLD_ITEM_UUID")" = 0 ] \
  || fail "selected draft target-old sidecar survived selected finalization"
[ "$(target_map_count "$TARGET_A_OLD_ITEM_UUID")" = 0 ] \
  || fail "selected draft target-old map survived selected finalization"
[ "$(target_state_count "$TARGET_A_OLD_ITEM_UUID")" = 0 ] \
  || fail "selected draft target-old state survived selected finalization"
[ "$(target_map_bytes "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_MAP_BEFORE" ] \
  || fail "draft target-old selected apply changed protected menu B map bytes"
[ "$(target_map_bytes "$TARGET_B_ITEM_UUID")" = "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "draft target-old selected apply changed protected B nested-item map bytes"
[ "$(target_option_bytes widget_nav_menu)" = "$TARGET_B_WIDGET_FAMILY_BEFORE" ] \
  || fail "draft target-old selected apply rewrote protected sidebar B widget-family bytes"
[ "$(target_sidebar_assignment_bytes sidebar-b)" = "$TARGET_B_SIDEBAR_BEFORE" ] \
  || fail "draft target-old selected apply changed protected sidebar B assignment bytes"
assert_outside_preserved "selected draft target-old menu item apply"
assert_generic_scoped_boundary "selected draft target-old menu item apply"
pass "exact draft target-old child is retired with its selected map/state while canonical work and protected B remain bounded"

say "refuse a dual-menu target-old child before session or target mutation"
TARGET_A_DUAL_ITEM_UUID="$(target_wp eval 'echo wp_generate_uuid4();' | tr -d '\r\n')"
assert_uuid "target dual-menu target-old item" "$TARGET_A_DUAL_ITEM_UUID"
TARGET_A_DUAL_ITEM_ID="$(target_wp post create --post_type=nav_menu_item --post_status=trash --post_title='DUO-3344 selected dual-menu target-old item' --porcelain)"
[[ "$TARGET_A_DUAL_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture dual-menu target-old item"
target_wp eval "
global \$wpdb;
\$a = (int) $TARGET_A_MENU_ID;
\$b = (int) $TARGET_B_MENU_ID;
\$item = (int) $TARGET_A_DUAL_ITEM_ID;
\$uuid = '$TARGET_A_DUAL_ITEM_UUID';
\$aTt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", \$a
));
\$bTt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", \$b
));
if (\$aTt < 1 || \$bTt < 1
    || \$wpdb->insert(\$wpdb->term_relationships, ['object_id' => \$item, 'term_taxonomy_id' => \$aTt, 'term_order' => 0], ['%d', '%d', '%d']) !== 1
    || \$wpdb->insert(\$wpdb->term_relationships, ['object_id' => \$item, 'term_taxonomy_id' => \$bTt, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
    fwrite(STDERR, 'could not attach dual-menu target-old item'); exit(1);
}
update_post_meta(\$item, '_duo_uuid', \$uuid);
\\Duo\\Ledger::set(\$uuid, 'menu_item', \\Duo\\Ledger::KIND_POST, \$item);
\\Duo\\Ledger::set_state_hash(\$uuid, 'menu_item', hash('sha256', 'DUO-3344 dual-menu target-old state'));
\$rels = array_map('intval', \$wpdb->get_col(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id\", \$item
)));
sort(\$rels, SORT_NUMERIC);
\$expected = [\$aTt, \$bTt]; sort(\$expected, SORT_NUMERIC);
if (\$rels !== \$expected) { fwrite(STDERR, 'dual-menu fixture did not retain both nav-menu owners'); exit(1); }
echo wp_json_encode(['item_id' => \$item, 'uuid' => \$uuid, 'a_tt' => \$aTt, 'b_tt' => \$bTt]);
" >"$TMP/target-menu-a-dual-old.json"
jq -e --arg uuid "$TARGET_A_DUAL_ITEM_UUID" --argjson item "$TARGET_A_DUAL_ITEM_ID" '
  .uuid == $uuid and .item_id == $item and (.a_tt | type == "number" and . > 0) and (.b_tt | type == "number" and . > 0) and .a_tt != .b_tt
' "$TMP/target-menu-a-dual-old.json" >/dev/null \
  || fail "dual-menu target-old fixture did not retain both selected/protected owners"
[ "$(target_map_count "$TARGET_A_DUAL_ITEM_UUID")" = 1 ] \
  && [ "$(target_state_count "$TARGET_A_DUAL_ITEM_UUID")" = 1 ] \
  || fail "dual-menu target-old fixture did not retain map/state rows"

MENU_DUAL_OLD_CONTRACT="$TMP/menu-a-dual-old.scope.json"
run_duo_json menu-dual-old-scope "$MENU_DUAL_OLD_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
MENU_DUAL_OLD_SESSION_BEFORE="$(target_kv scoped_apply_session)"
MENU_DUAL_OLD_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json menu-dual-old-refusal "$TMP/menu-dual-old-refusal.json" apply target "--scope-contract=$MENU_DUAL_OLD_CONTRACT" --format=json
jq -e '.command == "apply" and .reason_code == "scoped_identity_recovery_required"' "$TMP/menu-dual-old-refusal.json" >/dev/null \
  || fail "dual-menu target-old child did not return the stable scoped identity refusal"
[ "$(target_kv scoped_apply_session)" = "$MENU_DUAL_OLD_SESSION_BEFORE" ] \
  || fail "dual-menu target-old refusal opened or changed a scoped session"
[ "$(target_boundary_digest)" = "$MENU_DUAL_OLD_BOUNDARY_BEFORE" ] \
  || fail "dual-menu target-old refusal changed target maps/state/menu rows"
[ "$(target_map_bytes "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_MAP_BEFORE" ] \
  || fail "dual-menu target-old refusal changed protected menu B map bytes"
[ "$(target_map_bytes "$TARGET_B_ITEM_UUID")" = "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "dual-menu target-old refusal changed protected B nested-item map bytes"
assert_outside_preserved "dual-menu target-old refusal"
assert_generic_scoped_boundary "dual-menu target-old refusal"
target_wp eval "
\$uuid = '$TARGET_A_DUAL_ITEM_UUID';
\$item = (int) $TARGET_A_DUAL_ITEM_ID;
\\Duo\\Ledger::forget(\$uuid);
if (!wp_delete_post(\$item, true)) { fwrite(STDERR, 'could not clean dual-menu target-old fixture'); exit(1); }
" >/dev/null
[ "$(target_wp db query "SELECT COUNT(*) FROM wp_posts WHERE ID = $TARGET_A_DUAL_ITEM_ID" --skip-column-names | tr -d '[:space:]')" = 0 ] \
  && [ "$(target_map_count "$TARGET_A_DUAL_ITEM_UUID")" = 0 ] \
  && [ "$(target_state_count "$TARGET_A_DUAL_ITEM_UUID")" = 0 ] \
  || fail "dual-menu target-old fixture cleanup left a post, map, or state row"
pass "dual-menu target-old child refuses before session/mutation and its disposable fixture is removed exactly"

say "remove the selected menu nested item through a fresh target-bound scope"
source_wp post delete "$MENU_A_SOURCE_ITEM_ID" --force >/dev/null
publish_source_capture menu-item-remove 'capture: DUO-3344 selected menu item removal'
MENU_REMOVE_CONTRACT="$TMP/menu-a-remove.scope.json"
run_duo_json menu-remove-scope "$MENU_REMOVE_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
run_duo_json menu-remove-apply "$TMP/menu-remove-apply.json" apply target "--scope-contract=$MENU_REMOVE_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/menu-remove-apply.json" >/dev/null || fail "selected menu nested-item removal did not converge"
[ "$(target_menu_item_id "$MENU_A_ITEM_UUID")" = 0 ] \
  || fail "selected menu nested item survived its scoped removal"
[ "$(target_map_count "$MENU_A_ITEM_UUID")" = 0 ] \
  || fail "selected menu nested-item map survived its scoped removal"
[ "$(target_map_bytes "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_MAP_BEFORE" ] \
  || fail "selected menu removal changed protected menu B map bytes"
[ "$(target_map_bytes "$TARGET_B_ITEM_UUID")" = "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "selected menu removal changed protected B nested-item map bytes"
assert_generic_scoped_boundary "selected menu nested-item removal"
pass "fresh scope seals the target-only selected nested item for removal without unprotecting menu B"

say "create and remove selected sidebar A widgets without allocating sidebar B"
SIDEBAR_CREATE_CONTRACT="$TMP/sidebar-a.scope.json"
run_duo_json sidebar-create-scope "$SIDEBAR_CREATE_CONTRACT" scope source --roots=sidebar:sidebar-a --contract --format=json
SIDEBAR_B_WIDGET_MAP_BEFORE="$(target_map_count "$SIDEBAR_B_WIDGET_UUID")"
SIDEBAR_CREATE_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_json sidebar-create-plan "$TMP/sidebar-create-plan.json" plan target "--scope-contract=$SIDEBAR_CREATE_CONTRACT" --format=json
[ "$(target_boundary_digest)" = "$SIDEBAR_CREATE_BOUNDARY_BEFORE" ] \
  || fail "read-only selected sidebar plan changed protected sidebar/menu/map evidence"
run_duo_json sidebar-create-apply "$TMP/sidebar-create-apply.json" apply target "--scope-contract=$SIDEBAR_CREATE_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/sidebar-create-apply.json" >/dev/null || fail "selected sidebar widget create did not converge"
SIDEBAR_A_WIDGET_LOCAL_ID="$(target_widget_local_id "$SIDEBAR_A_WIDGET_UUID" text)"
[[ "$SIDEBAR_A_WIDGET_LOCAL_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "selected sidebar widget create did not allocate a text-widget mapping"
[ "$(target_sidebar_widget_keys sidebar-a)" = "text-${SIDEBAR_A_WIDGET_LOCAL_ID}" ] \
  || fail "selected sidebar A did not receive its mapped text widget assignment"
[ "$(target_map_count "$SIDEBAR_B_WIDGET_UUID")" = "$SIDEBAR_B_WIDGET_MAP_BEFORE" ] \
  || fail "selected sidebar A create allocated unselected sidebar B's desired widget map"
[ "$(target_option_bytes widget_nav_menu)" = "$TARGET_B_WIDGET_FAMILY_BEFORE" ] \
  || fail "selected sidebar A create rewrote protected sidebar B widget-family bytes"
[ "$(target_sidebar_assignment_bytes sidebar-b)" = "$TARGET_B_SIDEBAR_BEFORE" ] \
  || fail "selected sidebar A create changed protected sidebar B assignment bytes"
assert_generic_scoped_boundary "selected sidebar widget create"

source_wp eval "
\$all = get_option('sidebars_widgets', []);
\$all['sidebar-a'] = [];
update_option('sidebars_widgets', \$all);
update_option('widget_text', ['_multiwidget' => 1]);
" >/dev/null
publish_source_capture sidebar-widget-remove 'capture: DUO-3344 selected sidebar widget removal'
SIDEBAR_REMOVE_CONTRACT="$TMP/sidebar-a-remove.scope.json"
run_duo_json sidebar-remove-scope "$SIDEBAR_REMOVE_CONTRACT" scope source --roots=sidebar:sidebar-a --contract --format=json
run_duo_json sidebar-remove-apply "$TMP/sidebar-remove-apply.json" apply target "--scope-contract=$SIDEBAR_REMOVE_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
  and .scoped_receipt.phase == "complete"
' "$TMP/sidebar-remove-apply.json" >/dev/null || fail "selected sidebar widget removal did not converge"
[ "$(target_map_count "$SIDEBAR_A_WIDGET_UUID")" = 0 ] \
  || fail "selected sidebar A widget map survived its scoped removal"
[ -z "$(target_sidebar_widget_keys sidebar-a)" ] \
  || fail "selected sidebar A retained a widget assignment after removal"
[ "$(target_map_count "$SIDEBAR_B_WIDGET_UUID")" = "$SIDEBAR_B_WIDGET_MAP_BEFORE" ] \
  || fail "selected sidebar A removal allocated/protected sidebar B desired widget map"
[ "$(target_option_bytes widget_nav_menu)" = "$TARGET_B_WIDGET_FAMILY_BEFORE" ] \
  || fail "selected sidebar A removal rewrote protected sidebar B widget-family bytes"
[ "$(target_sidebar_assignment_bytes sidebar-b)" = "$TARGET_B_SIDEBAR_BEFORE" ] \
  || fail "selected sidebar A removal changed protected sidebar B assignment bytes"
assert_generic_scoped_boundary "selected sidebar widget removal"
pass "sidebar A create/remove keeps B's desired map absent and B's family/assignment byte-exact"

say "refuse selected menu location takeover before session or protected target mutation"
source_wp eval "set_theme_mod('nav_menu_locations', ['duo3344_primary' => (int) $MENU_A_SOURCE_ID]);" >/dev/null
publish_source_capture menu-location-source 'capture: DUO-3344 selected menu location'
target_wp eval "set_theme_mod('nav_menu_locations', ['duo3344_primary' => (int) $TARGET_B_MENU_ID]);" >/dev/null
MENU_LOCATION_TAKEOVER_CONTRACT="$TMP/menu-a-location-takeover.scope.json"
run_duo_json menu-location-takeover-scope "$MENU_LOCATION_TAKEOVER_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
MENU_LOCATION_TAKEOVER_SESSION_BEFORE="$(target_kv scoped_apply_session)"
MENU_LOCATION_TAKEOVER_BOUNDARY_BEFORE="$(target_boundary_digest)"
run_duo_refusal_json menu-location-takeover "$TMP/menu-location-takeover.json" apply target "--scope-contract=$MENU_LOCATION_TAKEOVER_CONTRACT" --format=json
jq -e '.command == "apply" and (.reason_code | type == "string" and length > 0)' "$TMP/menu-location-takeover.json" >/dev/null \
  || fail "selected menu location takeover did not return a public scoped refusal"
[ "$(target_kv scoped_apply_session)" = "$MENU_LOCATION_TAKEOVER_SESSION_BEFORE" ] \
  || fail "selected menu location takeover opened or changed a scoped session"
[ "$(target_boundary_digest)" = "$MENU_LOCATION_TAKEOVER_BOUNDARY_BEFORE" ] \
  || fail "selected menu location takeover changed protected maps/options/menu rows"
[ "$(target_menu_location_id duo3344_primary)" = "$TARGET_B_MENU_ID" ] \
  || fail "selected menu location takeover displaced the protected target menu B holder"
assert_generic_scoped_boundary "selected menu location takeover refusal"
pass "compiler-bounded selected menu location takeover refuses before session or target mutation"

say "author selected menu A location, then tombstone it without releasing menu B's location"
target_wp eval "set_theme_mod('nav_menu_locations', []);" >/dev/null
MENU_LOCATION_CONTRACT="$TMP/menu-a-location.scope.json"
run_duo_json menu-location-scope "$MENU_LOCATION_CONTRACT" scope source "--roots=menu:${MENU_A_SLUG}" --contract --format=json
run_duo_json menu-location-apply "$TMP/menu-location-apply.json" apply target "--scope-contract=$MENU_LOCATION_CONTRACT" --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and .verification.selected_live == 1
' "$TMP/menu-location-apply.json" >/dev/null || fail "selected menu A location authoring did not converge"
[ "$(target_menu_location_id duo3344_primary)" = "$TARGET_A_MENU_ID" ] \
  || fail "selected menu A did not author its own primary location"
target_wp eval "
\$locations = get_theme_mod('nav_menu_locations', []);
if (!is_array(\$locations)) { \$locations = []; }
\$locations['duo3344_secondary'] = (int) $TARGET_B_MENU_ID;
set_theme_mod('nav_menu_locations', \$locations);
" >/dev/null
[ "$(target_menu_location_id duo3344_secondary)" = "$TARGET_B_MENU_ID" ] \
  || fail "could not manufacture protected target menu B secondary location"
TARGET_A_TOMBSTONE_SIDECARLESS_ITEM_ID="$(target_wp post create --post_type=nav_menu_item --post_status=trash --post_title='DUO-3344 selected menu sidecarless tombstone child' --porcelain)"
[[ "$TARGET_A_TOMBSTONE_SIDECARLESS_ITEM_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "could not manufacture selected menu sidecarless tombstone child"
target_wp eval "
global \$wpdb;
\$menu = (int) $TARGET_A_MENU_ID;
\$item = (int) $TARGET_A_TOMBSTONE_SIDECARLESS_ITEM_ID;
\$tt = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = 'nav_menu' LIMIT 1\", \$menu
));
if (\$tt < 1
    || \$wpdb->insert(\$wpdb->term_relationships, ['object_id' => \$item, 'term_taxonomy_id' => \$tt, 'term_order' => 0], ['%d', '%d', '%d']) !== 1) {
    fwrite(STDERR, 'could not attach sidecarless tombstone child to selected menu'); exit(1);
}
\$rels = array_map('intval', \$wpdb->get_col(\$wpdb->prepare(
    \"SELECT term_taxonomy_id FROM {\$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id\", \$item
)));
\$maps = (int) \$wpdb->get_var(\$wpdb->prepare(
    \"SELECT COUNT(*) FROM {\$wpdb->prefix}duo_map WHERE id_kind = 'post' AND local_id = %d\", \$item
));
if (get_post_meta(\$item, '_duo_uuid', true) !== '' || \$rels !== [\$tt] || \$maps !== 0) {
    fwrite(STDERR, 'sidecarless tombstone child is not an unmapped selected-only physical row'); exit(1);
}
echo wp_json_encode(['item_id' => \$item, 'term_taxonomy_id' => \$tt]);
" >"$TMP/target-menu-a-sidecarless-tombstone-child.json"
jq -e --argjson item "$TARGET_A_TOMBSTONE_SIDECARLESS_ITEM_ID" '
  .item_id == $item and (.term_taxonomy_id | type == "number" and . > 0)
' "$TMP/target-menu-a-sidecarless-tombstone-child.json" >/dev/null \
  || fail "sidecarless tombstone child did not retain its selected-only unmapped shape"
source_wp menu delete "$MENU_A_SOURCE_ID" >/dev/null
publish_source_capture menu-tombstone 'capture: DUO-3344 selected menu tombstone'
MENU_TOMBSTONE_CONTRACT="$TMP/menu-a-tombstone.scope.json"
run_duo_json menu-tombstone-scope "$MENU_TOMBSTONE_CONTRACT" scope source "--roots=tombstone:${MENU_A_UUID}" --contract --format=json
jq -e --arg uuid "$MENU_A_UUID" '
  .format == "duo-scope-contract/v1"
  and .selectors == ["tombstone:" + $uuid]
  and (.tombstones | length == 1 and .[0].uuid == $uuid)
' "$MENU_TOMBSTONE_CONTRACT" >/dev/null || fail "selected menu tombstone scope did not bind only menu A"
run_duo_json menu-tombstone-apply "$TMP/menu-tombstone-apply.json" apply target "--scope-contract=$MENU_TOMBSTONE_CONTRACT" --with-deletes --format=json
jq -e '
  .format == "duo-scoped-apply-result/v1"
  and .canary == "clean"
  and .verification.result == "pass"
  and (.verification.selected_deletions >= 1)
  and .scoped_receipt.phase == "complete"
' "$TMP/menu-tombstone-apply.json" >/dev/null || fail "selected menu tombstone did not converge through the public delete path"
[ "$(target_menu_term_id "$MENU_A_UUID")" = 0 ] \
  || fail "selected menu A survived its scoped tombstone"
[ "$(target_map_count "$MENU_A_UUID")" = 0 ] \
  || fail "selected menu A ledger maps survived its scoped tombstone"
[ "$(target_menu_location_id duo3344_primary)" = 0 ] \
  || fail "selected menu tombstone did not release its own primary location"
[ "$(target_menu_location_id duo3344_secondary)" = "$TARGET_B_MENU_ID" ] \
  || fail "selected menu tombstone removed protected menu B's secondary location"
[ "$(target_wp db query "SELECT COUNT(*) FROM wp_posts WHERE ID = $TARGET_A_TOMBSTONE_SIDECARLESS_ITEM_ID" --skip-column-names | tr -d '[:space:]')" = 0 ] \
  || fail "selected menu tombstone left its sidecarless/unmapped physical child live"
[ "$(target_menu_term_id "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_ID" ] \
  || fail "selected menu tombstone removed protected menu B itself"
[ "$(target_map_bytes "$TARGET_B_MENU_UUID")" = "$TARGET_B_MENU_MAP_BEFORE" ] \
  || fail "selected menu tombstone changed protected menu B map bytes"
[ "$(target_map_bytes "$TARGET_B_ITEM_UUID")" = "$TARGET_B_ITEM_MAP_BEFORE" ] \
  || fail "selected menu tombstone changed protected B nested-item map bytes"
[ "$(target_map_count "$SIDEBAR_B_WIDGET_UUID")" = "$SIDEBAR_B_WIDGET_MAP_BEFORE" ] \
  || fail "selected menu tombstone allocated the protected sidebar B desired widget map"
[ "$(target_option_bytes widget_nav_menu)" = "$TARGET_B_WIDGET_FAMILY_BEFORE" ] \
  || fail "selected menu tombstone rewrote protected sidebar B widget-family bytes"
[ "$(target_sidebar_assignment_bytes sidebar-b)" = "$TARGET_B_SIDEBAR_BEFORE" ] \
  || fail "selected menu tombstone changed protected sidebar B assignment bytes"
assert_generic_scoped_boundary "selected menu tombstone"
pass "selected menu tombstone removes its sidecarless child and releases only A's location while preserving B's owner/map/sidebar evidence"

# The final PASS is emitted by cleanup only after the pair is absent and every
# owned filesystem resource has been removed successfully.
BODY_COMPLETE=1
