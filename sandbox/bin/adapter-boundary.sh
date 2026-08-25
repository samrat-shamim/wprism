#!/usr/bin/env bash
# adapter-boundary.sh — the live probe loop behind `duo adapter boundary`.
#
# The planner owns the SEARCH and this script owns the PROBE. `duo adapter
# boundary` reads a recorded release list plus the outcomes observed so far and
# either names the one release to run next (exit 3) or emits the finished
# document (exit 0); this loop runs that one release through a real pair and
# appends what happened. Nothing here decides a boundary, and nothing here
# writes a manifest — see cli/src/Adapter/AdapterBoundary.php's header for why
# the manifest range and its dispositions restatement stay one reviewed human
# edit.
#
# ONE PROBE IS ONE CERTIFY CASE. The body below is
# tests/certify/certify_version_matrix.sh's per-boundary loop with the version
# list replaced by the planner's answer: reset both environments, install ONLY
# from a digest-verified artifact, seed real plugin content through that
# plugin's own API using the SAME matrix.d/<slug>.sh hook the certify matrix
# uses, capture, round-trip deploy/apply, and require a byte-identical
# recapture. The seed hooks are shared rather than copied precisely so a
# bisection and a certification cannot disagree about what "green" means.
#
# WHY THE PIN COMES FROM THE RELEASE LIST. `fetch_artifact()` resolves against
# conformance/artifacts.lock.json and refuses a miss rather than falling
# through to a bare catalog install (bin/fetch-artifact.sh:44-47). A bisector
# probes versions that are BY DEFINITION not in that lock yet — finding the
# ones that belong in it is the whole job — so its pin source is the recorded
# release list. Same discipline enforced here before the runner is called
# (https URL, 64-hex digest, exact-version assertion after install), a
# different reviewed document, and the same container-side runner
# (/duo-harness/artifact-cache-fetch.sh) doing the locked, verified,
# atomically-published download.
#
# THE FOUR OUTCOMES, and which failures map to which:
#   artifact-unresolved  the runner could not fetch or verify the pinned bytes
#   boot-fatal           install/activate failed, or the exact version did not
#                        land, or WordPress fataled before content could be seeded
#   round-trip-diverges  it ran, and capture/deploy/apply/recapture did not
#                        come back byte-identical (or the apply canary was dirty)
#   green                everything above succeeded
# `artifact-unresolved` is kept distinct on purpose: it is a fact about the
# download, and folding it into boot-fatal would let a mirror outage move a
# certified boundary.
#
# WHAT THE FIRST LIVE RUN ESTABLISHED (WP-2.2, allocation order 3 in
# docs/agents/live-pair-budget.md, ~0.4 pair-hours at source 4818618f). The loop
# ran end to end against a real pair on `advanced-custom-fields`: release-list
# pin resolved and digest-verified for a version the artifact lock does not
# carry, exact 6.0.0 installed and asserted, seeded through
# matrix.d/acf.sh's own seed_acf_content, captured, deployed, applied,
# classified, recorded, replanned. The probe returned `round-trip-diverges` and
# the planner refused `anchor_not_green` rather than proposing anything — which
# is correct, because `VMATRIX_MANIFEST=acf bash
# tests/certify/certify_version_matrix.sh` fails at the SAME acf 6.0.0 apply on
# the same source, with the same refusal ("duo: authored post meta
# '_duo_related' disagrees with the locked target context"). So the probe is
# faithful to the certify case down to its failure, and the ACF round-trip was
# broken independently of this script.
#
# That refusal has since been root-caused and fixed (ACF-MATRIX-FIX): main #556
# (18f32d13) rechecked every authored key against the target's PRE-WRITE rows
# under the owner range lock, which rejects each sibling-classified interpreter
# key on an owner the apply is about to create — an ACF value meta and its
# '_<field>' pointer classify each other, and a new post has neither yet. Both
# materializers now classify against the map the reconciliation establishes,
# and `VMATRIX_MANIFEST=acf bash tests/certify/certify_version_matrix.sh` passes
# end to end (both 6.0.0 and 6.8.7 boundaries, ~0.11 pair-hours). The
# `round-trip-diverges` outcome recorded above therefore predates the fix:
# re-run this loop before trusting it, because the anchor it refused is the
# thing that changed.
#
# The remaining reset difference from that script is deliberate: its per-plugin
# option/table teardown covers the fourteen subjects it certifies, and a
# single-subject bisector reaches the same clean start by deleting its own
# subject.
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"

MANIFEST=""
SLUG=""
RELEASES=""
OUTCOMES=""
ANCHOR=""
SITE_POLICY=""
SEED_FILE=""
SEED_FN=""
FROM=""
TO=""
PAIR="${BOUNDARY_PAIR:-boundary}"
PORT1="${BOUNDARY_PORT1:-8880}"
PORT2="${BOUNDARY_PORT2:-8881}"
MAX_PROBES=32

for arg in "$@"; do
  case "$arg" in
    --manifest=*)    MANIFEST="${arg#*=}" ;;
    --slug=*)        SLUG="${arg#*=}" ;;
    --releases=*)    RELEASES="${arg#*=}" ;;
    --outcomes=*)    OUTCOMES="${arg#*=}" ;;
    --anchor=*)      ANCHOR="${arg#*=}" ;;
    --site-policy=*) SITE_POLICY="${arg#*=}" ;;
    --seed-file=*)   SEED_FILE="${arg#*=}" ;;
    --seed-fn=*)     SEED_FN="${arg#*=}" ;;
    --from=*)        FROM="${arg#*=}" ;;
    --to=*)          TO="${arg#*=}" ;;
    --pair=*)        PAIR="${arg#*=}" ;;
    --port1=*)       PORT1="${arg#*=}" ;;
    --port2=*)       PORT2="${arg#*=}" ;;
    --max-probes=*)  MAX_PROBES="${arg#*=}" ;;
    *) fail "unsupported argument '$arg'" ;;
  esac
done

[ -n "$MANIFEST" ] || fail "--manifest=<name> is required (the canonical manifest whose range is under review)"
[ -n "$SLUG" ]     || fail "--slug=<artifact-slug> is required (the wp.org/plugin directory slug)"
[ -n "$RELEASES" ] || fail "--releases=<release-list.json> is required"
[ -n "$OUTCOMES" ] || fail "--outcomes=<outcomes.json> is required (created if absent, appended to as probes run)"
[ -n "$ANCHOR" ]   || fail "--anchor=<version> is required (a release believed green to search outward from)"
[ -n "$SITE_POLICY" ] || fail "--site-policy=<site.duo.json> is required — the policy under which 'green' is
  being claimed is a reviewed input, never something a search invents"
[ -f "$RELEASES" ] || fail "release list '$RELEASES' does not exist"
[ -f "$SITE_POLICY" ] || fail "site policy '$SITE_POLICY' does not exist"
[[ "$MAX_PROBES" =~ ^[1-9][0-9]*$ ]] || fail "--max-probes must be a positive integer"

# Default to the certify matrix's own hook, by the convention that file already
# follows: one file per plugin under matrix.d/, named after the manifest slug.
[ -n "$SEED_FILE" ] || SEED_FILE="tests/certify/matrix.d/${MANIFEST}.sh"
[ -f "$SEED_FILE" ] || fail "seed hook '$SEED_FILE' does not exist — pass --seed-file=<path>"

export DUO_PAIR="$PAIR"
PAIR_COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.artifacts.yml)
PAIR_COMPOSE_STRING="${PAIR_COMPOSE[*]}"
wp1() { "${PAIR_COMPOSE[@]}" run --rm -T cli1 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
wp2() { "${PAIR_COMPOSE[@]}" run --rm -T cli2 sh -c 'umask 000; exec wp "$@"' sh "$@"; }
GIT1=(git -C "siterepo/${PAIR}1" -c user.name=duo-boundary1 -c user.email=boundary1@example.test)

. conformance/asserts.sh
. bin/fetch-artifact.sh
# shellcheck source=/dev/null
. "$SEED_FILE"
if [ -z "$SEED_FN" ]; then
  # matrix.d names its hooks seed_<underscored manifest>_content, with the two
  # historical abbreviations the certify driver already carries.
  case "$MANIFEST" in
    contact-form-7) SEED_FN=seed_cf7_content ;;
    paid-memberships-pro) SEED_FN=seed_pmpro_content ;;
    *) SEED_FN="seed_${MANIFEST//-/_}_content" ;;
  esac
fi
declare -F "$SEED_FN" >/dev/null \
  || fail "seed hook function '$SEED_FN' is not defined by $SEED_FILE — pass --seed-fn=<name>"

RUN_TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-adapter-boundary.XXXXXX")"
trap 'rm -rf -- "$RUN_TMP"' EXIT INT TERM

if [ ! -f "$OUTCOMES" ]; then
  jq -n --arg slug "$SLUG" \
    '{format:"duo-adapter-boundary-outcomes/v1",slug:$slug,outcomes:[]}' > "$OUTCOMES"
  pass "created an empty outcome record at $OUTCOMES"
fi

clear_case_repository() {
  local root="$1"
  case "$root" in
    "siterepo/${PAIR}1"|"siterepo/${PAIR}2") ;;
    *) fail "refusing unsafe boundary repository cleanup: $root" ;;
  esac
  mkdir -p "$root"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}

reset_case_repositories() {
  rm -rf "siterepo/origin-$PAIR.git"
  clear_case_repository "siterepo/${PAIR}1"
  clear_case_repository "siterepo/${PAIR}2"
  git init --bare -b main "siterepo/origin-$PAIR.git" >/dev/null
}

# Content + identity only, keeping WordPress core installed: the same reason
# certify_version_matrix.sh's reset_env() exists rather than `pair.sh reset`,
# which drops the database and leaves the site UNINSTALLED mid-run ("Error: The
# site you have requested is not installed", that script's own first live
# attempt). The three cleanups below are the generic half of its body — the
# per-plugin option-ownership teardown it also carries is specific to the
# fourteen subjects it certifies, and a single-subject bisector reaches the
# same clean start by deleting its own subject.
reset_env() {
  local cli="$1"
  "$cli" site empty --yes >/dev/null
  # `site empty` can leave default_category pointing at a term it deleted, and
  # a later plugin installer may reuse that numeric id for another taxonomy —
  # harmless residue turning into a real out-of-scope reference.
  "$cli" eval '
    $category = get_term_by("slug", "uncategorized", "category");
    if (!$category) {
      $created = wp_insert_term("Uncategorized", "category", ["slug" => "uncategorized"]);
      if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); }
      $category = get_term((int) $created["term_id"], "category");
    }
    if (!$category || is_wp_error($category)) { throw new RuntimeException("boundary reset could not restore default category"); }
    update_option("default_category", (int) $category->term_id);
    $survivors = get_terms(["taxonomy" => "category", "hide_empty" => false]);
    if (is_wp_error($survivors)) { throw new RuntimeException($survivors->get_error_message()); }
    foreach ($survivors as $term) {
      if ((int) $term->term_id === (int) $category->term_id) { continue; }
      $deleted = wp_delete_term((int) $term->term_id, "category");
      if (is_wp_error($deleted) || $deleted === false) {
        throw new RuntimeException("boundary reset could not remove residual category " . $term->slug);
      }
    }
  ' >/dev/null
  # `wp site empty` intentionally retains users. A version boundary owns no
  # prior fixture principals, and a seed that short-circuits on an existing
  # user would score the release green without exercising it.
  "$cli" eval '
    require_once ABSPATH . "wp-admin/includes/user.php";
    $admin = get_user_by("login", "admin");
    if (!$admin) { throw new RuntimeException("boundary reset could not resolve admin"); }
    foreach (get_users(["fields" => "ids", "exclude" => [(int) $admin->ID]]) as $user_id) {
      if (!wp_delete_user((int) $user_id, (int) $admin->ID)) {
        throw new RuntimeException("boundary reset could not delete user " . (int) $user_id);
      }
    }
  ' >/dev/null
  # The subject itself: uninstall through WordPress so the plugin's own
  # deactivation/uninstall hooks run before the next exact artifact lands.
  "$cli" plugin deactivate "$SLUG" >/dev/null 2>&1 || true
  "$cli" plugin delete "$SLUG" >/dev/null 2>&1 || true
  # Duo's own identity/state tables, exactly as certify_version_matrix.sh's
  # reset_env() ends (:356-359). `wp site empty` TRUNCATEs wp_posts, so the
  # next probe's first post is id 1 again while wp_duo_map still binds id 1 to
  # the PREVIOUS probe's uuid — capture then refuses "identity contradiction:
  # local post id 1 is already bound to ...", which this script's own first
  # live run produced. A stale binding is cross-probe contamination, and a
  # bisector that inherited one would score a release on another release's
  # identities.
  "$cli" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  "$cli" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
}

# The release list is the pin source, held to the same grammar the artifact
# lock is held to before the runner is ever reached.
fetch_release_artifact() { # <version> <cli-service> ; prints the container path
  local version="$1" service="$2" entry url sha256 cache_path source
  entry=$(jq -c --arg v "$version" '.releases[] | select(.version == $v)' "$RELEASES")
  [ -n "$entry" ] || { echo "release '$version' is absent from $RELEASES" >&2; return 1; }
  url=$(echo "$entry" | jq -r '.url')
  sha256=$(echo "$entry" | jq -r '.sha256')
  [[ "$sha256" =~ ^[0-9a-f]{64}$ ]] \
    || { echo "release '$version' carries a malformed digest pin" >&2; return 1; }
  # The same URL grammar validate_artifact_lock()'s jq program applies to a
  # lock row (fetch-artifact.sh:26): https, and no whitespace or quote anywhere
  # in it — an anchored match, not a prefix test, because a space in the middle
  # is exactly what a prefix test would wave through into a shell argument.
  [[ "$url" =~ ^https://[^[:space:]\'\"]+$ ]] \
    || { echo "release '$version' carries a malformed HTTPS URL pin" >&2; return 1; }
  cache_path="/artifacts-cache/plugin-${SLUG}-${version}-${sha256}.zip"
  if ! source=$("${PAIR_COMPOSE[@]}" run --rm -T -u root "$service" \
      sh /duo-harness/artifact-cache-fetch.sh "$url" "$sha256" "$cache_path" 0 "$SLUG" "$version" plugin); then
    return 1
  fi
  case "$source" in
    cache-hit|network-fetch) ;;
    *) echo "artifact runner returned an invalid source record for '$version'" >&2; return 1 ;;
  esac
  echo "$cache_path"
}

record_outcome() { # <version> <outcome> <signature>
  local version="$1" outcome="$2" signature="$3" next
  next="$RUN_TMP/outcomes.next.json"
  jq --arg v "$version" --arg o "$outcome" --arg s "$signature" \
    '.outcomes += [{version:$v,outcome:$o,signature:$s}]' "$OUTCOMES" > "$next"
  mv "$next" "$OUTCOMES"
  pass "recorded $version -> $outcome"
}

# One probe. Prints nothing; records exactly one outcome row.
probe_release() { # <version>
  local version="$1" artifact1 artifact2 installed rev log signature
  log="$RUN_TMP/probe.log"

  say "probe: $SLUG $version"
  reset_env wp1
  reset_env wp2
  reset_case_repositories

  if ! artifact1=$(fetch_release_artifact "$version" cli1 2>"$log"); then
    record_outcome "$version" artifact-unresolved "$(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  if ! artifact2=$(fetch_release_artifact "$version" cli2 2>"$log"); then
    record_outcome "$version" artifact-unresolved "$(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi

  if ! wp1 plugin install "$artifact1" --activate >"$log" 2>&1; then
    record_outcome "$version" boot-fatal "install/activate failed: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  if ! installed=$(wp1 plugin get "$SLUG" --field=version 2>"$log"); then
    record_outcome "$version" boot-fatal "plugin not readable after activation: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  if [ "$installed" != "$version" ]; then
    record_outcome "$version" boot-fatal "installed version '$installed' is not the pinned '$version'"
    return 0
  fi

  cp "$SITE_POLICY" "siterepo/${PAIR}1/site.duo.json"
  cp site-repo.gitignore.template "siterepo/${PAIR}1/.gitignore"
  "${GIT1[@]}" init -q -b main
  "${GIT1[@]}" remote add origin "../origin-$PAIR.git"
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "policy: $SLUG $version boundary probe"
  "${GIT1[@]}" push -qu origin main

  # A seed hook that dies is the plugin failing to run under these exact bytes,
  # which is boot-fatal — the state never existed to round-trip.
  if ! "$SEED_FN" wp1 >"$log" 2>&1; then
    record_outcome "$version" boot-fatal "seed hook $SEED_FN failed: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi

  if ! wp1 duo capture --repo=/siterepo >"$log" 2>&1; then
    record_outcome "$version" round-trip-diverges "capture refused: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  "${GIT1[@]}" add -A
  "${GIT1[@]}" commit -qm "capture: $SLUG $version"
  "${GIT1[@]}" push -q origin main

  git clone -q "siterepo/origin-$PAIR.git" "siterepo/${PAIR}2"
  chmod 0777 "siterepo/${PAIR}2"
  if ! wp2 plugin install "$artifact2" >"$log" 2>&1; then
    record_outcome "$version" boot-fatal "target install failed: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  if ! wp2 duo deploy --repo=/siterepo >"$log" 2>&1; then
    record_outcome "$version" round-trip-diverges "deploy refused: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  rev=$(git -C "siterepo/${PAIR}2" rev-parse HEAD)
  if ! wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin \
      --revision="$rev" >"$log" 2>&1; then
    record_outcome "$version" round-trip-diverges "apply refused: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  if ! grep -q 'canary clean' "$log"; then
    record_outcome "$version" round-trip-diverges "apply canary was not clean"
    return 0
  fi
  if ! wp2 duo capture --repo=/siterepo --out="/siterepo/.tmp-final" >"$log" 2>&1; then
    record_outcome "$version" round-trip-diverges "recapture refused: $(tail -n 3 "$log" | tr '\n' ' ')"
    return 0
  fi
  signature=$(diff -rq "siterepo/${PAIR}1/state" "siterepo/${PAIR}2/.tmp-final" 2>&1 || true)
  rm -rf "siterepo/${PAIR}2/.tmp-final"
  if [ -n "$signature" ]; then
    record_outcome "$version" round-trip-diverges "recapture differed: $(echo "$signature" | head -n 3 | tr '\n' ' ')"
    return 0
  fi
  record_outcome "$version" green "installed exact $version, seeded via $SEED_FN, applied canary-clean, recaptured byte-identically"
}

say "boot pair $PAIR (${PAIR}1 :$PORT1 / ${PAIR}2 :$PORT2), idempotent"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --artifacts
pass "pair up"

PLAN_ARGS=(--releases="$RELEASES" --outcomes="$OUTCOMES" --anchor="$ANCHOR" --manifest="$MANIFEST" --format=json)
[ -z "$FROM" ] || PLAN_ARGS+=(--from="$FROM")
[ -z "$TO" ] || PLAN_ARGS+=(--to="$TO")

PROBES=0
while :; do
  set +e
  php ../cli/duo adapter boundary "${PLAN_ARGS[@]}" > "$RUN_TMP/plan.json"
  PLAN_STATUS=$?
  set -e
  case "$PLAN_STATUS" in
    0)
      say "search complete"
      cat "$RUN_TMP/plan.json"
      pass "$PROBES probe(s) consumed; the document above is EVIDENCE — the manifest range and its
  dispositions restatement remain a reviewed human edit"
      exit 0
      ;;
    3) ;;
    *)
      cat "$RUN_TMP/plan.json" >&2
      fail "planner refused (exit $PLAN_STATUS); no boundary is proposed from this record"
      ;;
  esac
  NEXT=$(jq -r '.next_probe.version' "$RUN_TMP/plan.json")
  [ -n "$NEXT" ] && [ "$NEXT" != null ] || fail "planner asked for another probe but named no release"
  PROBES=$((PROBES + 1))
  [ "$PROBES" -le "$MAX_PROBES" ] \
    || fail "probe budget of $MAX_PROBES exhausted — a search needing more than that is not bisecting"
  probe_release "$NEXT"
done
