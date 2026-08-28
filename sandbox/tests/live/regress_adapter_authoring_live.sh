#!/usr/bin/env bash
# Regression — DUO-3340: the smallest honest live adapter-authoring exercise.
#
# This is intentionally a disposable, parameterized pair rather than a second
# copy of the broad R1-C grind.  It follows one plugin-owned key from an
# authenticated REST write, alongside an anonymous runtime write, through the
# journal/pending/classify boundary, a value-free offline draft, graduation to
# the shipped duo-agency-cpt manifest, and a target plan/apply.  The fixture
# plugin is authored into this run's own site-repository code/ tree before
# pair.sh creates any container, then travels to side two through git + deploy.
#
# The script is rerun-safe by refusing to reuse any of its own site-repo roots
# or origin.  A failed run leaves a live pair and scratch evidence in place for
# inspection; only a completely green run destroys the pair and its roots.
# No deletion flag is passed here.  Deletion authority, generic/scoped
# rollback, and plugin/theme upgrade/downgrade/removal are explicit deferred
# outcomes, never green assertions.
#
# Candidate safety is deliberate: DUO_EXPECTED_SOURCE_SHA is mandatory and is
# passed through to pair.sh, whose canonical agent/adapter-packages/platform source gate runs
# before database/container/repository mutation.  Run this from a checkout
# whose canonical root is the candidate commit (or from a standalone clone),
# not from a linked worktree whose canonical primary checkout is another SHA.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> sandbox/

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq required"
command -v curl >/dev/null || fail "curl required"
command -v php >/dev/null || fail "php required"
command -v git >/dev/null || fail "git required"
command -v docker >/dev/null || fail "docker required"

PAIR="${ADAPTER_AUTHORING_PAIR:-}"
PORT1="${ADAPTER_AUTHORING_PORT1:-}"
PORT2="${ADAPTER_AUTHORING_PORT2:-}"
EXPECTED_SHA="${DUO_EXPECTED_SOURCE_SHA:-}"
PLUGIN_DIR=duo-agency-cpt
PLUGIN_BASENAME="$PLUGIN_DIR/$PLUGIN_DIR.php"
PLUGIN_FILE="code/wp-content/plugins/$PLUGIN_DIR/$PLUGIN_DIR.php"
SITE1="siterepo/${PAIR}1"
SITE2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-${PAIR}.git"

[ -n "$PAIR" ] || fail "ADAPTER_AUTHORING_PAIR is required; choose an unused lowercase pair name"
[ -n "$PORT1" ] || fail "ADAPTER_AUTHORING_PORT1 is required; choose an unused even port >= 8900"
[ -n "$PORT2" ] || fail "ADAPTER_AUTHORING_PORT2 is required; set it to PORT1 + 1"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "ADAPTER_AUTHORING_PAIR must start with a lowercase letter and contain only lowercase letters/digits"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail "ADAPTER_AUTHORING_PORT1/PORT2 must be decimal ports"
PORT1_NUM=$((10#$PORT1))
PORT2_NUM=$((10#$PORT2))
[ "$PORT1_NUM" -ge 8900 ] || fail "ADAPTER_AUTHORING_PORT1 must be >= 8900"
[ "$PORT1_NUM" -le 65534 ] || fail "ADAPTER_AUTHORING_PORT1 must be <= 65534"
[ "$((PORT1_NUM % 2))" -eq 0 ] || fail "ADAPTER_AUTHORING_PORT1 must be even so PORT2 is its +1"
[ "$PORT2_NUM" -eq "$((PORT1_NUM + 1))" ] || fail "ADAPTER_AUTHORING_PORT2 must equal PORT1 + 1"
PORT1="$PORT1_NUM"
PORT2="$PORT2_NUM"
[ -n "$EXPECTED_SHA" ] || fail "DUO_EXPECTED_SOURCE_SHA is required; refuse to run without an exact candidate gate"
[[ "$EXPECTED_SHA" =~ ^[0-9A-Fa-f]{40}$ ]] || fail "DUO_EXPECTED_SOURCE_SHA must be the exact 40-character candidate SHA"

ACTUAL_SHA=$(git rev-parse HEAD)
[ "$EXPECTED_SHA" = "$ACTUAL_SHA" ] || fail "DUO_EXPECTED_SOURCE_SHA=$EXPECTED_SHA does not equal this checkout HEAD=$ACTUAL_SHA"
export DUO_EXPECTED_SOURCE_SHA="$EXPECTED_SHA"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN="$PLUGIN_DIR"

# The pair is disposable, but failed evidence is valuable.  Never silently
# destroy a previous failed run or clobber its source/target repositories.
[ ! -e "$SITE1" ] || fail "$SITE1 already exists; inspect it or destroy it manually before rerunning"
[ ! -e "$SITE2" ] || fail "$SITE2 already exists; inspect it or destroy it manually before rerunning"
[ ! -e "$ORIGIN" ] || fail "$ORIGIN already exists; inspect it or destroy it manually before rerunning"

COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml -f pair.http.yml -f pair.journal.yml -f pair.codebind.yml)
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
host() { php ../cli/duo "$@"; }
last_line() { awk 'NF { line = $0 } END { print line }'; }
ledger_snapshot() {
  {
    printf 'journal count='; wp1 db query 'SELECT COUNT(*) FROM wp_duo_journal' --skip-column-names
    wp1 db query 'SELECT id,t,op,tbl,item,surface,actor,caps,hook,proposal FROM wp_duo_journal ORDER BY id' --skip-column-names
    printf 'map count='; wp1 db query 'SELECT COUNT(*) FROM wp_duo_map' --skip-column-names
    wp1 db query 'SELECT uuid,entity_type,id_kind,local_id FROM wp_duo_map ORDER BY uuid,id_kind' --skip-column-names
    printf 'state count='; wp1 db query 'SELECT COUNT(*) FROM wp_duo_state' --skip-column-names
    wp1 db query 'SELECT uuid,entity_type,content_hash FROM wp_duo_state ORDER BY uuid' --skip-column-names
    printf 'kv count='; wp1 db query 'SELECT COUNT(*) FROM wp_duo_kv' --skip-column-names
    wp1 db query 'SELECT k,v FROM wp_duo_kv ORDER BY k' --skip-column-names
  } | sha256sum | awk '{print $1}'
}
target_ledger_snapshot() {
  {
    printf 'journal count='; wp2 db query 'SELECT COUNT(*) FROM wp_duo_journal' --skip-column-names
    wp2 db query 'SELECT id,t,op,tbl,item,surface,actor,caps,hook,proposal FROM wp_duo_journal ORDER BY id' --skip-column-names
    printf 'map count='; wp2 db query 'SELECT COUNT(*) FROM wp_duo_map' --skip-column-names
    wp2 db query 'SELECT uuid,entity_type,id_kind,local_id FROM wp_duo_map ORDER BY uuid,id_kind' --skip-column-names
    printf 'state count='; wp2 db query 'SELECT COUNT(*) FROM wp_duo_state' --skip-column-names
    wp2 db query 'SELECT uuid,entity_type,content_hash FROM wp_duo_state ORDER BY uuid' --skip-column-names
    printf 'kv count='; wp2 db query 'SELECT COUNT(*) FROM wp_duo_kv' --skip-column-names
    wp2 db query 'SELECT k,v FROM wp_duo_kv ORDER BY k' --skip-column-names
  } | sha256sum | awk '{print $1}'
}
GIT1=(git -C "$SITE1" -c user.name=duo-3340-a -c user.email=duo-3340-a@example.test)
GIT2=(git -C "$SITE2" -c user.name=duo-3340-b -c user.email=duo-3340-b@example.test)

SCRATCH_ROOT=""
GREEN=0
PAIR_UP=0
cleanup() {
  local rc=$?
  if [ "$GREEN" = 1 ] && [ "$PAIR_UP" = 1 ]; then
    # Teardown is intentionally reachable only after every assertion passed.
    if ! bash bin/pair.sh destroy "$PAIR"; then
      printf '\033[1;31m(pair %s teardown failed; repositories, codebind roots, and scratch evidence were retained)\033[0m\n' "$PAIR" >&2
      # EXIT traps preserve the incoming status unless they explicitly exit.
      # A green test with failed teardown is not green: leave all evidence and
      # make the harness itself fail so live containers cannot be forgotten.
      exit 1
    fi
    PAIR_UP=0
    rm -rf -- "$SITE1" "$SITE2" "$ORIGIN"
    [ -z "$SCRATCH_ROOT" ] || rm -rf -- "$SCRATCH_ROOT"
  elif [ "$PAIR_UP" = 1 ]; then
    printf '\033[1;33m(pair %s, repositories, and scratch evidence are left up for inspection after a failed run)\033[0m\n' "$PAIR" >&2
    [ -z "$SCRATCH_ROOT" ] || printf '\033[1;33mscratch evidence: %s\033[0m\n' "$SCRATCH_ROOT" >&2
  fi
  return "$rc"
}
trap cleanup EXIT

# ============================================================ scaffold

say "author the controlled source plugin and a minimal pinned site repository"
git init --bare -b main "$ORIGIN" >/dev/null
mkdir -p "$SITE1/code/wp-content/plugins/$PLUGIN_DIR"
cp "fixtures/$PLUGIN_DIR/$PLUGIN_DIR.php" "$SITE1/$PLUGIN_FILE"
cp site-repo.gitignore.template "$SITE1/.gitignore"
cat > "$SITE1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "project"],
    "taxonomies": ["category", "project_type"]
  },
  "spec_version": 2
}
EOF
git -C "$SITE1" init -q -b main
git -C "$SITE1" remote add origin "../origin-${PAIR}.git"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "init: controlled duo-agency-cpt plugin and core-only pin"
git -C "$SITE1" push -qu origin main
git clone -q "$ORIGIN" "$SITE2"
# The source policy is authored by the host but classification deliberately
# exercises the target-side product command as www-data. Grant that one
# reviewed policy file cross-boundary write access; the repository directory
# itself is already the pair harness's shared 0777 bind-mount boundary.
chmod a+rw "$SITE1/site.duo.json"
pass "source plugin authored before pair creation; side two cloned the same source revision"

say "create the exact candidate-bound, journaled HTTP pair"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --journal --codebind "$PLUGIN_DIR" --http
PAIR_UP=1
F1="http://localhost:$PORT1"
SCRATCH_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/duo3340-authoring.XXXXXX")
OBSERVE_ENV="${PAIR}source"
ENV_FILE="$SCRATCH_ROOT/${PAIR}-envs.json"
OBSERVE_COMPOSE="$SCRATCH_ROOT/${PAIR}-compose.yml"
"${COMPOSE[@]}" config > "$OBSERVE_COMPOSE"
jq -cn --arg env "$OBSERVE_ENV" --arg compose "$OBSERVE_COMPOSE" \
  '{envs:{($env):{transport:"docker",compose_file:$compose,service:"cli1",repo_path:"/siterepo"}}}' > "$ENV_FILE"
# The refusal proof hashes the entire Duo journal as well as map/state/kv.
# Disable WordPress's unrelated asynchronous visitor-triggered cron spawn on
# this disposable pair so a late `_transient_doing_cron` runtime write cannot
# race that before/after observation and impersonate an apply mutation.
wp1 config set DISABLE_WP_CRON true --raw >/dev/null
wp2 config set DISABLE_WP_CRON true --raw >/dev/null
wp1 site empty --yes >/dev/null
wp2 site empty --yes >/dev/null
wp1 plugin activate "$PLUGIN_DIR" >/dev/null
wp1 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" || fail "$PLUGIN_DIR did not activate on source side"
# pair.sh flushes rewrites before the fixture plugin is active. Its project
# route therefore does not exist yet even though post creation succeeds; a
# `?p=<id>` request merely canonical-redirects and never reaches the plugin's
# singular-project runtime hook. Publish the active CPT rules before driving
# the public HTTP observation below.
wp1 rewrite flush >/dev/null
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" && fail "$PLUGIN_DIR is active on target before deploy"
pass "source active; target inactive and will receive activation through duo deploy"

# ============================================================ observation loop

say "exercise admin REST authoring, anonymous runtime traffic, and the blocking review queue"
PROJECT_ID=$(wp1 post create --post_type=project --post_status=publish \
  --post_title="DUO-3340 Authoring Exercise" \
  --post_name=duo-3340-authoring-exercise \
  --post_content='<!-- wp:core/image {"id":123} /-->' --porcelain | tr -d '\r')
[ "$PROJECT_ID" -gt 0 ] || fail "source project was not created"

# This is deliberately safe, generated-looking data: large enough for the
# offline generated-surface proposer, but not credential-shaped and never
# echoed.  The hard secret is passed only to the REST endpoint and tested by
# redaction/refusal assertions; no command below prints the response.
SAFE_MARKER="DUO3340_SAFE_GENERATED_SHAPE"
SAFE_PAYLOAD=$(jq -cn --arg marker "$SAFE_MARKER" \
  '{generated:$marker,version:"2026.08",parts:[$marker,$marker,$marker,$marker,$marker,$marker,$marker,$marker]}')
[ "${#SAFE_PAYLOAD}" -ge 120 ] || fail "safe generated-looking payload is not large enough for the draft proposer"
SECRET='sk_live_DUO3340AUTHORINGONLY1234567890'
php -r 'require $argv[1]; exit(\Duo\Secrets::hard_match($argv[2]) === "stripe key" ? 0 : 1);' \
  '../agent/src/Kernel/Secrets.php' "$SECRET" \
  || fail "the controlled secret fixture no longer matches the engine hard-secret boundary"
APP_PASS=$(wp1 user application-password create admin duo-3340-authoring --porcelain | tr -d '\r')
REST_BODY=$(jq -cn --arg notes "$SAFE_PAYLOAD" --arg api_key "$SECRET" \
  '{notes:$notes,api_key:$api_key}')
REST_RESPONSE=$(curl -fsSu "admin:$APP_PASS" -X POST \
  "$F1/wp-json/duo-agency/v1/projects/$PROJECT_ID/notes" \
  -H 'Content-Type: application/json' -d "$REST_BODY")
echo "$REST_RESPONSE" | jq -e --argjson id "$PROJECT_ID" '.project_id == $id' >/dev/null \
  || fail "authenticated admin REST write did not return the expected project id"
unset REST_RESPONSE REST_BODY APP_PASS

PROJECT_URL=$(wp1 post url "$PROJECT_ID" | tr -d '\r\n')
[[ "$PROJECT_URL" == "$F1/"* ]] || fail "source project permalink is not bound to the disposable source origin"
for _ in $(seq 1 5); do curl -fso /dev/null "$PROJECT_URL"; done
[ "$(wp1 post meta get "$PROJECT_ID" _duo_project_views | tr -d '\r')" = 5 ] \
  || fail "anonymous runtime traffic did not produce exactly five _duo_project_views writes"
pass "admin REST write and five anonymous runtime writes reached distinct journal surfaces"

if OUT=$(wp1 duo capture --repo=/siterepo 2>&1); then
  echo "$OUT"
  fail "capture accepted unclassified authored/runtime plugin state"
fi
grep -Fq '_duo_project_internal_notes' <<<"$OUT" || fail "capture refusal omitted the authored note key"
grep -Fq '_duo_project_views' <<<"$OUT" || fail "capture refusal omitted the runtime view key"
JOURNAL=$(wp1 duo journal-report --manifests=duo-agency-cpt --format=json | last_line)
echo "$JOURNAL" | jq -e '.rows | any(.item == "_duo_project_internal_notes" and .n >= 1)' >/dev/null \
  || fail "journal-report did not retain the REST note observation"
echo "$JOURNAL" | jq -e '.rows | any(.item == "_duo_project_views" and .n >= 1)' >/dev/null \
  || fail "journal-report did not retain anonymous runtime observations"
PENDING=$(wp1 duo pending --repo=/siterepo --format=json | last_line)
echo "$PENDING" | jq -e '[.[] | select(.section == "post_meta" and .key == "_duo_project_internal_notes" and .proposal == "authored")] | length >= 1' >/dev/null \
  || fail "pending did not propose the REST note as authored"
echo "$PENDING" | jq -e '[.[] | select(.section == "post_meta" and .key == "_duo_project_internal_notes")][0].evidence.journal.n >= 1' >/dev/null \
  || fail "pending note lacks journal evidence"
echo "$PENDING" | jq -e '[.[] | select(.section == "post_meta" and .key == "_duo_project_views" and .proposal == "runtime")] | length >= 1' >/dev/null \
  || fail "pending did not propose anonymous views as runtime"
echo "$PENDING" | jq -e '[.[] | select(.section == "options" and .key == "duo_agency_client_api_key" and ((.secret // "") | startswith("hard")))] | length >= 1' >/dev/null \
  || fail "pending did not flag the hard secret"
echo "$PENDING" | jq -e '[.[] | select(.key == "_duo_project_internal_notes")][0].secret == null' >/dev/null \
  || fail "safe generated-looking note was incorrectly treated as a hard secret"
pass "capture is loud; journal and pending preserve author/runtime distinctions without guessing a secret into authored state"

say "observer is saved, redacted, and non-authorizing"
OBSERVE_FILE="$SCRATCH_ROOT/adapter-observe.json"
SITE_BEFORE=$(sha256sum "$SITE1/site.duo.json" | awk '{print $1}')
LEDGER_BEFORE=$(ledger_snapshot)
if ! OBSERVE_RAW=$(php ../cli/duo --envs-file="$ENV_FILE" adapter-observe "$OBSERVE_ENV" \
  --out="$OBSERVE_FILE" 2>&1); then
  OBSERVE_ERROR=$(sed -E 's/sk_live_[A-Za-z0-9_]+/<redacted-secret>/g' <<<"$OBSERVE_RAW")
  fail "DUO-3340 requires host duo adapter-observe $OBSERVE_ENV --out=<scratch-file> to derive its configured repo and invoke wp duo adapter-observe --repo=/siterepo --format=json; command unavailable or refused: $OBSERVE_ERROR"
fi
[ -s "$OBSERVE_FILE" ] || fail "adapter-observe --out returned success without creating a nonempty evidence file"
jq -e '
  .format == "duo-adapter-observation/v1"
  and .authority == false
  and .redaction == "values_omitted"
  and (.observation_hash | type == "string" and test("^sha256:[0-9a-f]{64}$"))
  and (.deferred | type == "array")
  and (all(.deferred[]; .status == "deferred"
    and (.subject | type == "string")
    and (.statement | type == "string" and length > 0)))
  and (["proposal_evidence", "table_semantics", "apply", "provider_invocation",
        "rollback", "version_lifecycle", "publication", "certification",
        "bootstrap_effects"] - [.deferred[].subject] | length == 0)
' "$OBSERVE_FILE" >/dev/null || fail "adapter-observe did not emit the exact redacted, non-authorizing observation contract"
SITE_POLICY_HASH="sha256:$SITE_BEFORE"
jq -e --arg site_hash "$SITE_POLICY_HASH" '
  .repository.site_policy_sha256 == $site_hash
  and (.journal.summary.observations >= 6)
  and (.journal.rows | any(.table_class == "post_meta" and .surface == "rest" and .proposal == "authored" and .count >= 1))
  and (.journal.rows | any(.table_class == "post_meta" and .surface == "front" and .proposal == "runtime" and .count >= 5))
  and (.pending.items | any(.section == "post_meta" and .key == {encoding:"literal",label:"_duo_project_internal_notes"} and .proposal == "authored" and .counts.journal >= 1))
  and (.pending.items | any(.section == "post_meta" and .key == {encoding:"literal",label:"_duo_project_views"} and .proposal == "runtime" and .counts.journal >= 5))
  and (.pending.items | any(.section == "options" and .key == {encoding:"literal",label:"duo_agency_client_api_key"} and (.secret | type == "string" and startswith("hard:"))))
  and (.catalog.installed | any(.name == "duo-agency-cpt" and .source == "shipped"))
  and (.catalog.summary.installed >= 1)
  and (.policy.summary.capabilities >= 1)
  and (.policy.readiness == "ready" or .policy.readiness == "blocked")
' "$OBSERVE_FILE" >/dev/null || fail "adapter-observe did not project the target journal, pending, catalog, policy, and repository facts exercised above"
OBSERVATION_HASH=$(jq -r '.observation_hash' "$OBSERVE_FILE")
OBSERVATION_HASH_RECOMPUTED=$(php -r '
require "../agent/src/Kernel/Canon.php";
$document = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
unset($document["observation_hash"]);
echo "sha256:" . hash("sha256", \Duo\Canon::encode($document));
' "$OBSERVE_FILE")
[ "$OBSERVATION_HASH" = "$OBSERVATION_HASH_RECOMPUTED" ] \
  || fail "adapter-observe observation_hash is not the canonical document hash"
OBSERVE_FILE_HASH=$(sha256sum "$OBSERVE_FILE" | awk '{print $1}')
if host --envs-file="$ENV_FILE" adapter-observe "$OBSERVE_ENV" --out="$OBSERVE_FILE" >/dev/null 2>&1; then
  fail "adapter-observe overwrote a pre-existing evidence path"
fi
[ "$OBSERVE_FILE_HASH" = "$(sha256sum "$OBSERVE_FILE" | awk '{print $1}')" ] \
  || fail "adapter-observe changed a pre-existing output after refusing it"
! grep -Fq "$SECRET" "$OBSERVE_FILE" || fail "adapter-observe leaked the hard secret"
! grep -Fq 'sk_live_' "$OBSERVE_FILE" || fail "adapter-observe leaked a secret-shaped value"
! grep -Fq "$SAFE_MARKER" "$OBSERVE_FILE" || fail "adapter-observe leaked the safe generated-looking payload value"
! grep -Fq '/siterepo' "$OBSERVE_FILE" || fail "adapter-observe leaked the target repository path"
! grep -Fq 'duo-3340-authoring-exercise' "$OBSERVE_FILE" || fail "adapter-observe leaked a target title or slug"
SITE_AFTER=$(sha256sum "$SITE1/site.duo.json" | awk '{print $1}')
[ "$SITE_BEFORE" = "$SITE_AFTER" ] || fail "adapter-observe mutated site.duo.json; observation is display-only"
LEDGER_AFTER=$(ledger_snapshot)
[ "$LEDGER_BEFORE" = "$LEDGER_AFTER" ] || fail "adapter-observe changed Duo-owned journal/map/state/kv rows; observer must be read-only"
if OUT_AFTER_OBSERVE=$(wp1 duo capture --repo=/siterepo 2>&1); then
  echo "$OUT_AFTER_OBSERVE"
  fail "adapter-observe silently authorized unclassified state"
fi
grep -Fq '_duo_project_internal_notes' <<<"$OUT_AFTER_OBSERVE" || fail "post-observe capture no longer blocks the note"
grep -Fq '_duo_project_views' <<<"$OUT_AFTER_OBSERVE" || fail "post-observe capture no longer blocks runtime views"
pass "observer artifact is saved and redacted; site policy and the blocking capture gate are unchanged"

say "make every classification explicit, with hard-secret authored refusal"
if OUT_SECRET=$(wp1 duo classify --repo=/siterepo --set='options:duo_agency_client_api_key=authored' 2>&1); then
  echo "$OUT_SECRET"
  fail "classify accepted authored for the hard-secret option without --allow-secret"
fi
! grep -Fq "$SECRET" <<<"$OUT_SECRET" || fail "secret appeared in classify refusal output"
wp1 duo classify --repo=/siterepo \
  --set='post_meta:_duo_project_internal_notes=authored;post_meta:_duo_project_views=runtime;options:duo_agency_client_api_key=env;options:duo_agency_project_index=derived'
# env requires an explicit boolean in site policy; this option is optional in
# the fixture and its value is never required on the target.
jq '.policy.options.duo_agency_client_api_key.required = false' "$SITE1/site.duo.json" > "$SITE1/.tmp-site.duo.json"
mv "$SITE1/.tmp-site.duo.json" "$SITE1/site.duo.json"
jq -e '.policy.post_meta._duo_project_internal_notes.class == "authored" and .policy.post_meta._duo_project_views.class == "runtime" and .policy.options.duo_agency_client_api_key.class == "env" and .policy.options.duo_agency_client_api_key.required == false and .policy.options.duo_agency_project_index.class == "derived"' "$SITE1/site.duo.json" >/dev/null \
  || fail "site policy does not show exactly the explicit reviewed classifications"
wp1 duo capture --repo=/siterepo >/dev/null
! grep -R -Fq 'sk_live_' "$SITE1/state" || fail "hard secret leaked into captured state"
! grep -R -Fq '"_duo_project_views"' "$SITE1/state" || fail "runtime views leaked into captured state"
grep -R -Fq 'DUO3340_SAFE_GENERATED_SHAPE' "$SITE1/state" || fail "authored note did not reach captured state"
pass "explicit test-reviewed classifications capture authored notes only; runtime and secret values stay out"

# ============================================================ draft / graduation

say "generate and inspect the inert host adapter draft"
DRAFT_FILE="$SCRATCH_ROOT/adapter-draft.json"
DRAFT_RAW=$(host adapter-draft "$SITE1" --name=duo-agency-cpt --match='^_?duo_(agency|project)' --format=json)
printf '%s\n' "$DRAFT_RAW" > "$DRAFT_FILE"
jq -e '._draft.format == "duo-adapter-draft/v1"' "$DRAFT_FILE" >/dev/null || fail "adapter-draft nested envelope is not versioned"
jq -e '.post_meta._duo_project_internal_notes.class == "authored" and .post_meta._duo_project_views.class == "runtime" and .options.duo_agency_client_api_key.class == "env" and .options.duo_agency_project_index.class == "derived"' "$DRAFT_FILE" >/dev/null \
  || fail "adapter-draft facts do not reflect the reviewed classifications"
jq -e '([._draft.proposals[]?] | length) > 0' "$DRAFT_FILE" >/dev/null || fail "adapter-draft emitted no inert reference/deletion proposal"
jq -e '([._draft.unsupported[]?] | length) > 0' "$DRAFT_FILE" >/dev/null || fail "adapter-draft did not classify the safe generated-looking payload as unsupported"
jq -e '[._draft.proposals[]?[] | .questions[]?] | length > 0' "$DRAFT_FILE" >/dev/null || fail "adapter-draft proposal has no unanswered live question"
! grep -Fq "$SAFE_MARKER" "$DRAFT_FILE" || fail "adapter-draft leaked generated payload bytes instead of shape-only evidence"
! grep -Fq "$SECRET" "$DRAFT_FILE" || fail "adapter-draft leaked the hard secret"
! grep -Fq '<?php' "$DRAFT_FILE" || fail "adapter-draft emitted PHP rather than an inert declaration"
! jq -e 'has("actions") or has("providers") or has("notes")' "$DRAFT_FILE" >/dev/null || fail "adapter-draft sidecar pretended to authorize actions/providers/notes"
pass "facts, inert proposals, unsupported generated surface, questions, redaction, and no-PHP guard all hold"

say "feed the redacted observer artifact through adapter-draft as a non-authorizing evidence seam"
DRAFT_EVIDENCE_FILE="$SCRATCH_ROOT/adapter-draft-with-evidence.json"
DRAFT_EVIDENCE_RAW=$(host adapter-draft "$SITE1" --name=duo-agency-cpt \
  --match='^_?duo_(agency|project)' --evidence="$OBSERVE_FILE" --format=json)
printf '%s\n' "$DRAFT_EVIDENCE_RAW" > "$DRAFT_EVIDENCE_FILE"
jq -e '._draft.format == "duo-adapter-draft/v1" and (._draft.evidence_seam | contains("accepted but NOT consumed"))' "$DRAFT_EVIDENCE_FILE" >/dev/null \
  || fail "adapter-draft did not record the bounded, non-consumed evidence seam"
jq -S 'del(._draft.evidence_seam, ._draft._meta)' "$DRAFT_FILE" > "$SCRATCH_ROOT/draft-core.json"
jq -S 'del(._draft.evidence_seam, ._draft._meta)' "$DRAFT_EVIDENCE_FILE" > "$SCRATCH_ROOT/draft-evidence-core.json"
cmp -s "$SCRATCH_ROOT/draft-core.json" "$SCRATCH_ROOT/draft-evidence-core.json" \
  || fail "feeding live evidence changed draft facts/proposals/questions; evidence may only add its bounded seam note"
! jq -e 'has("actions") or has("providers") or has("notes")' "$DRAFT_EVIDENCE_FILE" >/dev/null \
  || fail "adapter-draft --evidence introduced executable or rationale authority"
pass "the strict observer artifact reaches --evidence, while facts/proposals/questions remain identical and inert"

DRAFT_SITE_HASH=$(sha256sum "$SITE1/site.duo.json" | awk '{print $1}')
CHECK_RAW=$(host adapter-draft "$SITE1" --name=duo-agency-cpt --match='^_?duo_(agency|project)' --check-proposals --format=json)
echo "$CHECK_RAW" | jq -e '.format == "duo-adapter-draft-check/v1" and .summary.checked > 0 and (.summary.liftable + .summary.refused == .summary.checked)' >/dev/null \
  || fail "adapter-draft --check-proposals did not produce a complete throwaway report"
[ "$DRAFT_SITE_HASH" = "$(sha256sum "$SITE1/site.duo.json" | awk '{print $1}')" ] || fail "--check-proposals wrote live site policy"
pass "--check-proposals validates only a throwaway lift and leaves site policy untouched"

# The shipped adapter's hand-authored executable and rationale blocks must
# survive graduation.  The generated policy projection is compared separately;
# no command writes to the package/ payload and no generated file replaces it.
SHIPPED_MANIFEST="../adapter-packages/duo-agency-cpt/package/manifest.json"
MANIFEST_GUARD=$(jq -cS '{actions,providers,notes}' "$SHIPPED_MANIFEST")
MANIFEST_HASH=$(sha256sum "$SHIPPED_MANIFEST" | awk '{print $1}')
POLICY_EXPORT_RAW=$(wp1 duo policy-to-manifest --repo=/siterepo --match='^_?duo_(agency|project)' --name=duo-agency-cpt)
# policy-to-manifest is pretty-printed canonical JSON. Preserve the complete
# document while discarding only a possible Docker/WP preamble; last_line()
# would retain the closing brace alone.
POLICY_EXPORT=$(printf '%s\n' "$POLICY_EXPORT_RAW" | awk '/^\{/{found=1} found')
echo "$POLICY_EXPORT" | jq -e '.name == "duo-agency-cpt"' >/dev/null || fail "policy-to-manifest did not emit the expected adapter"
jq -e --argjson export "$POLICY_EXPORT" \
  '($export | keys) as $keys | (to_entries | map(select(.key as $k | $keys | index($k))) | from_entries) == $export' \
  "$SHIPPED_MANIFEST" >/dev/null || fail "reviewed policy projection does not match the shipped adapter classification sections"

STATE_BEFORE_GRADUATION="$SCRATCH_ROOT/state-before-graduation"
rm -rf -- "$STATE_BEFORE_GRADUATION"
cp -a "$SITE1/state" "$STATE_BEFORE_GRADUATION"
jq '.manifests = ((.manifests + ["duo-agency-cpt"]) | unique) | .policy.options = {} | .policy.post_meta = {}' "$SITE1/site.duo.json" > "$SITE1/.tmp-site.duo.json"
mv "$SITE1/.tmp-site.duo.json" "$SITE1/site.duo.json"
wp1 duo capture --repo=/siterepo >/dev/null
diff -r "$STATE_BEFORE_GRADUATION" "$SITE1/state" >/dev/null || fail "manifest graduation changed captured state"
[ "$MANIFEST_HASH" = "$(sha256sum "$SHIPPED_MANIFEST" | awk '{print $1}')" ] || fail "graduation changed shipped manifest bytes"
[ "$MANIFEST_GUARD" = "$(jq -cS '{actions,providers,notes}' "$SHIPPED_MANIFEST")" ] || fail "graduation overwrote shipped actions/providers/notes"
pass "inline classifications graduated to the existing shipped adapter; actions/providers/notes stayed byte-stable"

say "validate shipped pins and compare host catalog after graduation"
MANIFEST_VALIDATE=$(host manifest-validate .. --manifest=duo-agency-cpt \
  --pins=core,duo-agency-cpt --site="$SITE1" --format=json)
echo "$MANIFEST_VALIDATE" | jq -e '.status == "ok" and .pinned_set.status == "ok" and ([.manifests[] | select(.status != "ok")] | length == 0)' >/dev/null \
  || fail "source-tree manifest-validate --manifest --site --pins did not pass"
CATALOG=$(host adapter list --repo="$SITE1" --format=json)
echo "$CATALOG" | jq -e '.format == "duo-adapter-catalog/v2" and ([.adapters[] | select(.name == "duo-agency-cpt")] | length == 1)' >/dev/null \
  || fail "host adapter catalog did not report duo-agency-cpt"
if INSPECT=$(host adapter inspect duo-agency-cpt --repo="$SITE1" --format=json); then
  INSPECT_RC=0
else
  INSPECT_RC=$?
fi
[ "$INSPECT_RC" -eq 1 ] || fail "excluded fixture inspect returned unexpected exit $INSPECT_RC"
echo "$INSPECT" | jq -e '
  .format == "duo-adapter-catalog/v2"
  and .adapter.name == "duo-agency-cpt"
  and .adapter.disposition_status == "excluded"
  and .adapter.trust_tier == "plugin_provider"
  and .adapter.certification == "registry"
' >/dev/null || fail "host adapter inspect did not retain the fixture's existing trust/disposition facts"
pass "manifest validator and host catalog/inspect agree on the graduated adapter identity"

# ============================================================ target plan/apply

say "commit the graduated source, pull the target, and deploy the existing plugin"
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: reviewed adapter authoring and manifest graduation"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
REV=$(git -C "$SITE2" rev-parse HEAD)
DEPLOY=$(wp2 duo deploy --repo=/siterepo --format=json | last_line)
echo "$DEPLOY" | jq -e '[.activated[]? | select(. == "duo-agency-cpt/duo-agency-cpt.php")] | length == 1' >/dev/null \
  || fail "duo deploy did not activate the shipped plugin provider"
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" || fail "target plugin is not active after deploy"
CAPABILITIES=$(wp2 duo capabilities --repo=/siterepo --format=json | last_line)
echo "$CAPABILITIES" | jq -e '[.manifests[] | select(.name == "duo-agency-cpt")] | length == 1' >/dev/null \
  || fail "target capability report did not include the deployed adapter"
pass "target capability report sees the deployed shipped adapter"

# Warm a target-local transient so the native action has a real before/after
# receipt.  The provider independently rebuilds the generated project index.
wp2 eval 'set_transient("duo_agency_project_cache", ["stale"], DAY_IN_SECONDS);' >/dev/null
PLAN=$(wp2 duo plan --repo=/siterepo --format=json | last_line)
echo "$PLAN" | jq -e '(.create | length) >= 1 and (.delete | length) == 0' >/dev/null || fail "target plan did not contain the source project without deletion authority"
APPLY=$(wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --revision="$REV" --format=json | last_line)
echo "$APPLY" | jq -e '.canary == "clean" and ([.actions[]? | select(.kind == "provider" and .source == "provider:duo-agency-index/rebuild_project_index" and .verified == true)] | length == 1) and ([.actions[]? | select(.kind == "native" and .source == "native:transient.delete" and .verified == true)] | length == 1)' >/dev/null \
  || fail "target apply did not return verified provider and native receipts"
INDEX_TARGET=$(wp2 option get duo_agency_project_index --format=json | last_line)
echo "$INDEX_TARGET" | jq -e '.ids | length >= 1' >/dev/null || fail "provider did not rebuild the target-local project index"
[ "$(wp2 eval 'var_export(get_transient("duo_agency_project_cache"));' | last_line)" = false ] || fail "native transient.delete receipt did not close the target transient"
pass "target plan/apply converged with verified plugin provider and closed native transient receipts"

TARGET_PROJECT_ID=$(wp2 post list --post_type=project --post_status=publish \
  --name=duo-3340-authoring-exercise --field=ID | tr -d '\r')
[ "$TARGET_PROJECT_ID" -gt 0 ] || fail "target project slug did not resolve after apply"

say "fresh target recapture is byte-identical to source"
TARGET_RECAPTURE="$SITE2/.tmp-fresh-target-state"
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-fresh-target-state >/dev/null
diff -r "$SITE1/state" "$TARGET_RECAPTURE" >/dev/null || fail "fresh target recapture differs byte-for-byte from source state"
pass "fresh target recapture matches the source state byte-for-byte"

# ============================================================ refusal/retry

say "deactivate the owning provider and prove refusal before mutation, then deploy/retry"
wp1 post update "$PROJECT_ID" --post_title="DUO-3340 Authoring Exercise (revised)" >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
"${GIT1[@]}" add -A
"${GIT1[@]}" commit -qm "capture: revise the authored project"
"${GIT1[@]}" push -q origin main
"${GIT2[@]}" pull -q origin main
REV2=$(git -C "$SITE2" rev-parse HEAD)
wp2 plugin deactivate "$PLUGIN_DIR" >/dev/null
TARGET_TITLE_BEFORE=$(wp2 db query "SELECT post_title FROM wp_posts WHERE ID = $TARGET_PROJECT_ID" --skip-column-names | tr -d '\r')
TARGET_INDEX_BEFORE=$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='duo_agency_project_index'" --skip-column-names | tr -d '\r')
TARGET_LEDGER_BEFORE=$(target_ledger_snapshot)
[ "$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k='apply_in_progress'" --skip-column-names | tr -d '\r')" = 0 ] \
  || fail "target already carried an apply_in_progress marker before the refusal proof"
if OUT_REFUSAL=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --force-code-mismatch 2>&1); then
  echo "$OUT_REFUSAL"
  fail "apply succeeded with the owning provider inactive"
fi
grep -Fq 'refused before target mutation' <<<"$OUT_REFUSAL" || fail "inactive-provider refusal omitted its pre-mutation boundary"
grep -Fq 'duo-agency-index' <<<"$OUT_REFUSAL" || fail "inactive-provider refusal omitted provider identity"
grep -Fq "$PLUGIN_BASENAME" <<<"$OUT_REFUSAL" || fail "inactive-provider refusal omitted owning plugin"
grep -Fq 'duo-agency-cpt' <<<"$OUT_REFUSAL" || fail "inactive-provider refusal omitted declaring manifest"
grep -Fq 'duo deploy' <<<"$OUT_REFUSAL" || fail "inactive-provider refusal omitted deploy remediation"
[ "$TARGET_TITLE_BEFORE" = "$(wp2 db query "SELECT post_title FROM wp_posts WHERE ID = $TARGET_PROJECT_ID" --skip-column-names | tr -d '\r')" ] || fail "refused apply mutated target title"
[ "$TARGET_INDEX_BEFORE" = "$(wp2 db query "SELECT option_value FROM wp_options WHERE option_name='duo_agency_project_index'" --skip-column-names | tr -d '\r')" ] || fail "refused apply mutated target generated index"
[ "$TARGET_LEDGER_BEFORE" = "$(target_ledger_snapshot)" ] || fail "refused apply mutated target Duo journal/map/state/kv rows"
[ "$(wp2 db query "SELECT COUNT(*) FROM wp_duo_kv WHERE k='apply_in_progress'" --skip-column-names | tr -d '\r')" = 0 ] \
  || fail "refused apply left an apply_in_progress marker"
pass "inactive provider refused before target mutation with identity/remediation evidence"

wp2 duo deploy --repo=/siterepo --format=json >/dev/null
wp2 plugin list --status=active --field=name | grep -qx "$PLUGIN_DIR" || fail "retry deploy did not reactivate the provider plugin"
RETRY=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | last_line)
echo "$RETRY" | jq -e '.canary == "clean" and ([.actions[]? | select(.kind == "provider" and .verified == true)] | length == 1) and ([.actions[]? | select(.kind == "native" and .verified == true)] | length == 1)' >/dev/null \
  || fail "retry apply did not converge with verified provider/native receipts"
TARGET_RECAPTURE_RETRY="$SITE2/.tmp-fresh-target-state-retry"
wp2 duo capture --repo=/siterepo --out=/siterepo/.tmp-fresh-target-state-retry >/dev/null
diff -r "$SITE1/state" "$TARGET_RECAPTURE_RETRY" >/dev/null || fail "retry target recapture differs byte-for-byte from revised source state"
pass "deploy/retry converged after the pre-mutation provider refusal"

say "explicit deferred boundaries"
pass "verified rollback is deferred unless the existing driver profile advertises it; this exercise makes no rollback claim"
pass "generic/scoped rollback and plugin/theme upgrade/downgrade/removal are deferred and never passed; no deletion authority was exercised"

# Capture files are written by www-data inside bind-mounted disposable repos.
# Hand ownership-independent delete permission back before the green cleanup;
# teardown still runs first and any teardown failure retains these roots.
"${COMPOSE[@]}" run --rm -T --user root cli1 sh -c 'chmod -R a+rwX /siterepo' >/dev/null
"${COMPOSE[@]}" run --rm -T --user root cli2 sh -c 'chmod -R a+rwX /siterepo' >/dev/null
GREEN=1
printf '\n\033[1;32m✔ REGRESS_ADAPTER_AUTHORING_LIVE PASSED\033[0m\n'
