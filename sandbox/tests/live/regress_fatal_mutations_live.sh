#!/usr/bin/env bash
# Live regression — DUO-3206 + DUO-3220. Exercises deterministic failures at every
# product mutation class and apply boundary, then proves that required
# rebuild and post-apply verification failures leave applied_revision/base
# state unadvanced and retry.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

# Pair/name/ports are overridable so a co-hosted agent can run this suite in
# its own namespace instead of the original author's (docs/agents/
# linear-loop.md's field notes record a live cross-agent pair-reset incident,
# DUO-3252, caused by exactly this kind of hardcoded stanza). Defaults are
# byte-identical to the historical values.
PAIR="${FATAL_MUTATIONS_PAIR:-codexmaca3206}"
PORT1="${FATAL_MUTATIONS_PORT1:-9210}"
PORT2="${FATAL_MUTATIONS_PORT2:-9211}"
SITEREPO="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
TEST_LIBRARY_ROOT="$SITEREPO/.tmp-duo-3338-library"
COMPOSE=(docker compose -p "duo-$PAIR" -f sandbox/pair.yml)
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp1_fail() {
  local contexts="$1"; shift
  "${COMPOSE[@]}" run --rm -T \
    -e DUO_TEST_MODE=1 -e DUO_TEST_FAIL_DB_CONTEXT="$contexts" \
    cli1 wp "$@"
}
wp1_test_adapter() {
  local slug="$1" assoc='{}' arg key value assoc_b64
  shift
  [ "${1:-}" = duo ] && [ "${2:-}" = apply ] \
    || fail 'test adapter runner accepts only wp duo apply'
  shift 2
  for arg in "$@"; do
    case "$arg" in
      --*=*)
        key="${arg%%=*}"; key="${key#--}"; value="${arg#*=}"
        assoc=$(jq -cn --argjson obj "$assoc" --arg key "$key" --arg value "$value" '$obj + {($key): $value}')
        ;;
      --*)
        key="${arg#--}"
        assoc=$(jq -cn --argjson obj "$assoc" --arg key "$key" '$obj + {($key): true}')
        ;;
      *) fail "test adapter runner refuses positional argument: $arg" ;;
    esac
  done
  assoc_b64=$(printf '%s' "$assoc" | base64 | tr -d '\n')
  "${COMPOSE[@]}" run --rm -T \
    -e DUO_TEST_ADAPTER_LIBRARY=/siterepo/.tmp-duo-3338-library \
    -e DUO_TEST_ADAPTER_SLUG="$slug" -e DUO_TEST_ASSOC_B64="$assoc_b64" \
    cli1 wp eval '
      $assoc = json_decode(base64_decode((string) getenv("DUO_TEST_ASSOC_B64"), true), true, 512, JSON_THROW_ON_ERROR);
      $assoc["adapter_library"] = \Duo\AdapterLibrary::fromSourcePackage(
          (string) getenv("DUO_TEST_ADAPTER_LIBRARY"),
          (string) getenv("DUO_TEST_ADAPTER_SLUG")
      );
      (new \Duo\Cli())->apply([], $assoc);
    '
}
write_test_disposition() {
  cat > "$1" <<'JSON'
{
  "capabilities": {
    "deletion_semantics": {"supported": [], "unsupported": ["all"]},
    "entity_sections": [],
    "field_sections": [],
    "lifecycle_phases": [],
    "operations": ["test-only"]
  },
  "default_authored_keyspaces": [],
  "reason": "Duo-authored live failure fixture, not a third-party adapter or product support claim.",
  "status": "excluded",
  "supported_versions": {"fixture": true},
  "unsupported": [
    {"operation": "promote", "reason": "Excluded fixture adapters are never production-ready.", "surface": "production"}
  ]
}
JSON
}
ledger_value() {
  wp1 eval "echo \\Duo\\Ledger::kv_get('$1') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1
}
# DUO-3489: apply_in_progress no longer holds the constant '1'. Its value is a
# canonical duo-apply-in-progress/v1 record naming the environment drift the
# interrupted apply preserved, so a multi-line value defeats ledger_value's
# tail -1. Every assertion here is about RETENTION, which is what this asks.
retry_marker() {
  wp1 eval "echo \\Duo\\Ledger::kv_get('apply_in_progress') === null ? 'NULL' : 'RETAINED';" 2>/dev/null | tr -d '\r' | tail -1
}
expect_failure() {
  local contexts="$1" expected="$2"; shift 2
  if OUT=$(wp1_fail "$contexts" "$@" 2>&1); then
    echo "$OUT"
    fail "expected failure for context '$contexts'"
  fi
  echo "$OUT"
  grep -Fq "$expected" <<<"$OUT" || fail "failure did not name '$expected'"
}
reset_baseline() {
  wp1 option update blogname "$ORIGINAL_BLOGNAME" >/dev/null
  wp1 duo capture --repo=/siterepo >/dev/null
  wp1 eval "\\Duo\\Ledger::kv_delete('apply_in_progress'); \\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
}
edit_blogname() {
  jq --arg v "$1" '.records.blogname.value = $v' "$SITEREPO/state/options/core.json" > "$SITEREPO/state/options/core.json.tmp"
  mv "$SITEREPO/state/options/core.json.tmp" "$SITEREPO/state/options/core.json"
}

cleanup() {
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "sandbox/siterepo/${PAIR}1" "sandbox/siterepo/${PAIR}2"
}
trap cleanup EXIT

say "boot isolated headless pair $PAIR"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless
mkdir -p "$SITEREPO"
cat > "$SITEREPO/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
wp1 duo capture --repo=/siterepo >/dev/null
ORIGINAL_BLOGNAME=$(wp1 option get blogname | tr -d '\r')
wp1 eval "\\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
pass "baseline captured"

say "transaction START failure is fatal before target mutation"
edit_blogname "DUO 3206 start failure"
expect_failure "apply transaction start" "apply transaction start" \
  duo apply --repo=/siterepo --revision=bad-start
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "start failure mutated blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "start failure advanced applied_revision"
reset_baseline
pass "transaction start failure left target and revision unchanged"

say "INSERT failure never records local id zero or identity"
SRC_POST=$(find "$SITEREPO/state/posts/post" -type f | head -1)
[ -n "$SRC_POST" ] || fail "captured tree has no post fixture"
NEW_UUID=01999999-3206-7000-8000-000000000001
NEW_POST="$SITEREPO/state/posts/post/${NEW_UUID}--duo-3206-insert.md"
php -r '
require $argv[1];
[$front, $body] = \Duo\Canon::parse_post_file(file_get_contents($argv[2]));
$front["uuid"] = $argv[4]; $front["slug"] = "duo-3206-insert"; $front["title"] = "DUO 3206 Insert";
file_put_contents($argv[3], \Duo\Canon::post_file($front, $body));
' "$REPO_ROOT/agent/src/Kernel/Canon.php" "$SRC_POST" "$NEW_POST" "$NEW_UUID"
expect_failure "apply insert post" "apply insert post" \
  duo apply --repo=/siterepo --revision=bad-insert
MAP=$(wp1 eval "echo \\Duo\\Ledger::id_for('$NEW_UUID', \\Duo\\Ledger::KIND_POST) ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$MAP" = NULL ] || fail "failed insert recorded identity $MAP"
[ "$(ledger_value applied_revision)" = baseline ] || fail "insert failure advanced applied_revision"
rm -f "$NEW_POST"
reset_baseline
pass "failed insert left no row identity or revision advance"

say "UPDATE failure rolls back and preserves base revision"
edit_blogname "DUO 3206 update failure"
expect_failure "apply update authored option" "apply update authored option" \
  duo apply --repo=/siterepo --revision=bad-update
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "update failure did not roll back blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "update failure advanced applied_revision"
reset_baseline
pass "failed update rolled back"

say "DELETE failure rolls back prior cleanup writes and keeps ledger state"
DELETE_POST=$(find "$SITEREPO/state/posts/post" -type f | head -1)
DELETE_UUID=$(php -r 'require $argv[1]; [$f] = \Duo\Canon::parse_post_file(file_get_contents($argv[2])); echo $f["uuid"];' "$REPO_ROOT/agent/src/Kernel/Canon.php" "$DELETE_POST")
DELETE_ID=$(wp1 eval "echo \\Duo\\Ledger::id_for('$DELETE_UUID', \\Duo\\Ledger::KIND_POST);" 2>/dev/null | tr -d '\r' | tail -1)
DELETE_SAVED="$SITEREPO/.duo-3206-deleted-post.md"
cp "$DELETE_POST" "$DELETE_SAVED"
wp1 eval "
\$compiled = \\Duo\\RepositoryCompiler::compile('/siterepo', \\Duo\\Policy::load('/siterepo'));
\$entity = \$compiled->tree()['$DELETE_UUID'];
if (!is_dir('/siterepo/state/deletions') && !wp_mkdir_p('/siterepo/state/deletions')) {
    throw new \\RuntimeException('could not create deletion fixture directory');
}
\$written = file_put_contents('/siterepo/state/deletions/$DELETE_UUID.json', \\Duo\\Canon::encode([
    'expected_hash' => \$entity['hash'],
    'expected_revision' => \$compiled->revision_hash(),
    'format' => \\Duo\\Deletion::FORMAT,
    'kind' => 'post',
    'source_path' => \$entity['path'],
    'type' => \$entity['data']['type'],
    'uuid' => '$DELETE_UUID',
]));
if (\$written === false || !unlink('/siterepo/state/' . \$entity['path'])) {
    throw new \\RuntimeException('could not publish explicit deletion fixture');
}
" >/dev/null
expect_failure "apply delete post" "apply delete post" \
  duo apply --repo=/siterepo --with-deletes --force-delete-referenced --revision=bad-delete
[ "$(wp1 post get "$DELETE_ID" --field=ID 2>/dev/null | tr -d '\r')" = "$DELETE_ID" ] || fail "delete failure did not restore post"
STATE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('$DELETE_UUID') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$STATE_HASH" != NULL ] || fail "delete failure removed base state"
rm -f "$SITEREPO/state/deletions/$DELETE_UUID.json"
mv "$DELETE_SAVED" "$DELETE_POST"
reset_baseline
pass "failed delete rolled back row/meta cleanup and kept base state"

say "COMMIT failure is fatal and rolls the transaction back"
edit_blogname "DUO 3206 commit failure"
expect_failure "apply transaction commit" "apply transaction commit" \
  duo apply --repo=/siterepo --revision=bad-commit
[ "$(wp1 option get blogname | tr -d '\r')" = "$ORIGINAL_BLOGNAME" ] || fail "commit failure did not roll back blogname"
[ "$(ledger_value applied_revision)" = baseline ] || fail "commit failure advanced applied_revision"
reset_baseline
pass "failed commit rolled back"

say "ROLLBACK failure is itself typed and non-zero"
edit_blogname "DUO 3206 rollback failure"
expect_failure "apply update authored option,apply transaction rollback" "apply transaction rollback" \
  duo apply --repo=/siterepo --revision=bad-rollback
[ "$(ledger_value applied_revision)" = baseline ] || fail "rollback failure advanced applied_revision"
[ "$(wp1 eval 'echo \Duo\PromotionLock::current() === null ? "none" : "held";' 2>/dev/null | tr -d '\r' | tail -1)" = none ] \
  || fail "rollback failure stranded the promotion lock"
# The failed process closed its transaction before independently releasing the
# lease. Re-capture establishes the next case from a fresh process.
reset_baseline
pass "rollback boundary failure stayed fatal and released its lease"

say "term recount failure occurs after commit but before convergence metadata"
BASE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
edit_blogname "DUO 3206 recount failure"
expect_failure "rebuild term counts" "rebuild term counts" \
  duo apply --repo=/siterepo --revision=bad-recount
[ "$(wp1 option get blogname | tr -d '\r')" = "DUO 3206 recount failure" ] || fail "recount test did not reach post-commit phase"
[ "$(ledger_value applied_revision)" = baseline ] || fail "recount failure advanced applied_revision"
[ "$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)" = "$BASE_HASH" ] || fail "recount failure advanced base hash"
[ "$(retry_marker)" = RETAINED ] || fail "recount failure did not retain retry marker"
PENDING_PLAN=$(wp1 duo plan --repo=/siterepo --json 2>/dev/null | tail -1)
echo "$PENDING_PLAN" | jq -e '.warnings[] | contains("previous apply did not complete")' >/dev/null \
  || fail "status plan did not surface the incomplete apply marker as a correctness warning"
echo "$PENDING_PLAN" | jq -e '.incomplete_apply | length == 1' >/dev/null \
  || fail "plan did not expose the structured incomplete-apply condition"
STATUS_ENVS="$SITEREPO/duo-3206-envs.json"
jq -n --arg compose "$REPO_ROOT/sandbox/pair.yml" '{
  envs: {probe: {transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"}}
}' > "$STATUS_ENVS"
if STATUS_OUT=$("$REPO_ROOT/cli/duo" --envs-file="$STATUS_ENVS" status probe 2>&1); then
  echo "$STATUS_OUT"
  fail "duo status returned zero for an incomplete apply"
fi
grep -Fq 'INCOMPLETE_APPLY' <<<"$STATUS_OUT" \
  || fail "duo status did not name the incomplete apply condition"
wp1 duo apply --repo=/siterepo --revision=recount-recovered >/dev/null
[ "$(ledger_value applied_revision)" = recount-recovered ] || fail "recount retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "recount retry did not clear marker"
reset_baseline
pass "required recount failure stayed unapplied and retried successfully"

say "object-cache rebuild failure is fatal before ledger advancement"
edit_blogname "DUO 3206 cache rebuild failure"
expect_failure "rebuild object cache" "rebuild object cache" \
  duo apply --repo=/siterepo --revision=bad-cache
[ "$(ledger_value applied_revision)" = baseline ] || fail "cache rebuild failure advanced applied_revision"
[ "$(retry_marker)" = RETAINED ] || fail "cache rebuild failure did not retain retry marker"
wp1 duo apply --repo=/siterepo --revision=cache-recovered >/dev/null
[ "$(ledger_value applied_revision)" = cache-recovered ] || fail "cache rebuild retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "cache rebuild retry did not clear marker"
reset_baseline
pass "required object-cache failure stayed unapplied and retried successfully"

say "ledger COMMIT failure atomically preserves every base hash and revision"
BASE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
edit_blogname "DUO 3206 ledger commit failure"
expect_failure "ledger transaction commit" "ledger transaction commit" \
  duo apply --repo=/siterepo --revision=bad-ledger-commit
[ "$(wp1 option get blogname | tr -d '\r')" = "DUO 3206 ledger commit failure" ] || fail "ledger commit test did not reach committed authored state"
[ "$(ledger_value applied_revision)" = baseline ] || fail "ledger commit failure advanced applied_revision"
[ "$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)" = "$BASE_HASH" ] || fail "ledger commit failure partially advanced base hash"
[ "$(retry_marker)" = RETAINED ] || fail "ledger commit failure did not retain retry marker"
wp1 duo apply --repo=/siterepo --revision=ledger-recovered >/dev/null
[ "$(ledger_value applied_revision)" = ledger-recovered ] || fail "ledger commit retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "ledger commit retry did not clear marker"
reset_baseline
pass "ledger metadata transition rolled back atomically and retried"

# DUO-3338 gave the manifest rebuild channel a gate DUO-3206 could not have:
# an unavailable capability is refused BEFORE the first target mutation, so
# nothing is attempted and the retry marker is deliberately absent. That is an
# ADDITIONAL property, not a replacement -- DUO-3206's own guarantee (a rebuild
# that fails AFTER the authored commit leaves applied_revision unadvanced, the
# retry marker set, and a retry that recovers) is exercised by the case
# immediately after this one, through the same new channel.
say "manifest-declared provider capability that is unavailable refuses before any target mutation"
mkdir -p "$SITEREPO/adapters"
cat > "$SITEREPO/adapters/duo-3338-missing-provider.json" <<'EOF'
{
  "name": "duo-3338-missing-provider",
  "spec_version": 2,
  "providers": [
    {"id": "duo-3338-absent", "version": "1.0.0", "source": "plugin", "plugin": "duo-3338-absent/duo-3338-absent.php", "capabilities": ["rebuild"]}
  ],
  "actions": [
    {"kind": "provider", "provider": "duo-3338-absent", "capability": "rebuild", "args": {}}
  ]
}
EOF
jq '.manifests = ["core", "duo-3338-missing-provider"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
BLOGNAME_BEFORE=$(wp1 option get blogname | tr -d '\r')
edit_blogname "DUO 3338 unavailable provider capability"
if OUT=$(wp1 duo apply --repo=/siterepo --revision=missing-provider 2>&1); then
  echo "$OUT"
  fail "apply with an unavailable provider capability unexpectedly succeeded"
fi
echo "$OUT"
grep -Fq "refused before target mutation" <<<"$OUT" || fail "refusal did not name the pre-mutation gate"
grep -Fq "duo-3338-absent" <<<"$OUT" || fail "refusal did not name the responsible provider"
grep -Fq "duo-3338-absent/duo-3338-absent.php" <<<"$OUT" || fail "refusal did not name the owning plugin"
grep -Eq "install and activate|duo deploy" <<<"$OUT" || fail "refusal carried no remediation path"
[ "$(ledger_value applied_revision)" = baseline ] || fail "provider refusal advanced applied_revision"
[ "$(retry_marker)" = NULL ] || fail "provider refusal wrote the retry marker despite mutating nothing"
[ "$(wp1 option get blogname | tr -d '\r')" = "$BLOGNAME_BEFORE" ] \
  || fail "provider refusal mutated the target before negotiating"
jq '.manifests = ["core"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
rm -f "$SITEREPO/adapters/duo-3338-missing-provider.json"
reset_baseline
pass "unavailable provider capability refused with remediation, before any target mutation"

# Both cases below need a provider that NEGOTIATES CLEANLY and then misbehaves
# at invocation time -- otherwise the pre-mutation gate above catches them and
# the post-commit boundary DUO-3206/DUO-3220 are about never gets exercised.
# Negotiation requires the declared owning plugin to be installed and active,
# so this probe plugin exists solely to be that owner. It registers nothing and
# runs no code of its own.
wp1 eval '
$dir = WP_PLUGIN_DIR . "/duo-3338-probe";
if (!is_dir($dir) && !wp_mkdir_p($dir)) {
    throw new RuntimeException("could not create the probe plugin directory");
}
if (file_put_contents($dir . "/duo-3338-probe.php", "<?php\n/**\n * Plugin Name: DUO 3338 provider probe\n * Version: 1.0.0\n */\n") === false) {
    throw new RuntimeException("could not write the probe plugin");
}
' >/dev/null
wp1 plugin activate duo-3338-probe >/dev/null
# active_plugins is a `managed` core option: apply never writes it, but capture
# DOES record it, so the baseline is retaken here. Without this, every later
# post-apply recapture would diverge on this script's own probe activation
# instead of on the corruption the DUO-3220 case deliberately injects.
reset_baseline

# The two invocation-failure cases exercise manifest-owned executable provider
# bytes. Site adapters deliberately cannot carry runtime code, so each case is
# a closed source package selected through Apply's object-only evidence seam.
# Copying the platform contract keeps the disposable library bound to the same
# agent/spec versions as the ordinary product path.
rm -rf "$TEST_LIBRARY_ROOT"
mkdir -p "$TEST_LIBRARY_ROOT/platform"
cp -R "$REPO_ROOT/platform/adapter-library" "$TEST_LIBRARY_ROOT/platform/adapter-library"

say "manifest-declared action failure is fatal before ledger advancement"
FATAL_PACKAGE="$TEST_LIBRARY_ROOT/adapter-packages/duo-3338-fatal-action/package"
mkdir -p "$FATAL_PACKAGE/runtime/providers"
write_test_disposition "$FATAL_PACKAGE/disposition.json"
cat > "$FATAL_PACKAGE/runtime/providers/duo-3338-fatal.php" <<'EOF'
<?php
namespace Duo\Providers;

/**
 * Scratch provider whose one capability always throws -- the structured-channel
 * equivalent of the nonexistent wp-cli command this case declared before
 * DUO-3338. Everything negotiable about it is deliberately correct (file
 * present, identity matching its declaration, owning plugin active, capability
 * advertised and idempotent) so the failure lands in the rebuild pass, after
 * the authored transaction has committed: that is the boundary DUO-3206 pins.
 */
final class Duo3338Fatal {
    public function __construct(\Duo\Policy $policy) {}

    public function identity(): array {
        return [
            'id' => 'duo-3338-fatal',
            'plugin' => 'duo-3338-probe/duo-3338-probe.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        return [
            'rebuild_probe_state' => [
                'args' => [],
                'reads' => [],
                'writes' => ['option:duo_3338_probe_state'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        throw new \RuntimeException("duo-3338 probe capability '$capability' is deliberately unavailable");
    }
}
EOF
cat > "$FATAL_PACKAGE/manifest.json" <<'EOF'
{
  "actions": [
    {"kind": "provider", "provider": "duo-3338-fatal", "capability": "rebuild_probe_state", "args": {}}
  ],
  "name": "duo-3338-fatal-action",
  "providers": [
    {"id": "duo-3338-fatal", "version": "1.0.0", "source": "manifest", "plugin": "duo-3338-probe/duo-3338-probe.php", "capabilities": ["rebuild_probe_state"]}
  ],
  "spec_version": 2
}
EOF
jq '.manifests = ["core", "duo-3338-fatal-action"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
edit_blogname "DUO 3206 manifest action failure"
if OUT=$(wp1_test_adapter duo-3338-fatal-action duo apply --repo=/siterepo --revision=bad-manifest 2>&1); then
  echo "$OUT"
  fail "a throwing required manifest action unexpectedly succeeded"
fi
echo "$OUT"
grep -Fq "required manifest action 'provider:duo-3338-fatal/rebuild_probe_state'" <<<"$OUT" \
  || fail "manifest failure did not name the exact failing action"
[ "$(ledger_value applied_revision)" = baseline ] || fail "manifest action failure advanced applied_revision"
[ "$(retry_marker)" = RETAINED ] || fail "manifest failure did not retain retry marker"
jq '.manifests = ["core"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
wp1 duo apply --repo=/siterepo --revision=manifest-action-recovered >/dev/null
[ "$(ledger_value applied_revision)" = manifest-action-recovered ] || fail "manifest action retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "manifest action retry did not clear marker"
reset_baseline
pass "required manifest action failure stayed fatal and unapplied, then retried successfully"

say "successful provider capability that corrupts authored state is caught by post-apply recapture"
CORRUPTING_PACKAGE="$TEST_LIBRARY_ROOT/adapter-packages/duo-3220-corrupting-action/package"
mkdir -p "$CORRUPTING_PACKAGE/runtime/providers"
write_test_disposition "$CORRUPTING_PACKAGE/disposition.json"
cat > "$CORRUPTING_PACKAGE/runtime/providers/duo-3338-corrupting.php" <<'EOF'
<?php
namespace Duo\Providers;

/**
 * Scratch provider that succeeds on its OWN terms -- it writes a value, reads it
 * back, and returns a well-formed receipt with verified true -- while corrupting
 * authored state the repository owns. Provider self-verification is deliberately
 * not the whole gate: DUO-3220's post-apply canonical recapture is what catches
 * a repair that proved its own write and still left the target divergent.
 */
final class Duo3338Corrupting {
    public function __construct(\Duo\Policy $policy) {}

    public function identity(): array {
        return [
            'id' => 'duo-3338-corrupting',
            'plugin' => 'duo-3338-probe/duo-3338-probe.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        return [
            'corrupt_blogname' => [
                'args' => [],
                'reads' => ['option:blogname'],
                'writes' => ['option:blogname'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        $before = (string) get_option('blogname');
        update_option('blogname', 'duo-3220-corrupted-after-apply');
        $after = (string) get_option('blogname');
        if ($after !== 'duo-3220-corrupted-after-apply') {
            throw new \RuntimeException('duo-3338 corrupting probe could not write blogname');
        }
        return [
            'before' => ['blogname' => $before],
            'after' => ['blogname' => $after],
            'verified' => true,
        ];
    }
}
EOF
cat > "$CORRUPTING_PACKAGE/manifest.json" <<'EOF'
{
  "actions": [
    {"kind": "provider", "provider": "duo-3338-corrupting", "capability": "corrupt_blogname", "args": {}}
  ],
  "name": "duo-3220-corrupting-action",
  "providers": [
    {"id": "duo-3338-corrupting", "version": "1.0.0", "source": "manifest", "plugin": "duo-3338-probe/duo-3338-probe.php", "capabilities": ["corrupt_blogname"]}
  ],
  "spec_version": 2
}
EOF
jq '.manifests = ["core", "duo-3220-corrupting-action"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
edit_blogname "DUO 3220 expected authored state"
BASE_HASH=$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
if OUT=$(wp1_test_adapter duo-3220-corrupting-action duo apply --repo=/siterepo --revision=bad-post-apply-verification 2>&1); then
  echo "$OUT"
  fail "authored corruption after a self-verified provider capability unexpectedly passed verification"
fi
echo "$OUT"
grep -Fq "post-apply convergence verification failed" <<<"$OUT" \
  || fail "verification failure did not name the post-apply gate"
grep -Fq "options/core" <<<"$OUT" \
  || fail "verification failure did not identify the divergent canonical entity"
[ "$(wp1 option get blogname | tr -d '\r')" = duo-3220-corrupted-after-apply ] \
  || fail "corrupting provider capability did not execute successfully before verification"
[ "$(ledger_value applied_revision)" = baseline ] || fail "verification failure advanced applied_revision"
[ "$(wp1 eval "echo \\Duo\\Ledger::state_hash('options/core') ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)" = "$BASE_HASH" ] \
  || fail "verification failure advanced the canonical base hash"
[ "$(retry_marker)" = RETAINED ] || fail "verification failure did not retain retry marker"
jq '.manifests = ["core"]' "$SITEREPO/site.duo.json" > "$SITEREPO/site.duo.json.tmp"
mv "$SITEREPO/site.duo.json.tmp" "$SITEREPO/site.duo.json"
wp1 option update blogname "$ORIGINAL_BLOGNAME" >/dev/null
wp1 duo capture --repo=/siterepo >/dev/null
wp1 eval "\\Duo\\Ledger::kv_delete('apply_in_progress'); \\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
edit_blogname "DUO 3220 verified recovery"
VERIFY_JSON=$(wp1 duo apply --repo=/siterepo --revision=verification-recovered --format=json 2>/dev/null | tail -1)
echo "$VERIFY_JSON" | jq -e \
  '.verification.verifier == "canonical-recapture/v1"
   and .verification.result == "pass"
   and .verification.live_entities > 0
   and .verification.deletions == 0' >/dev/null \
  || fail "successful retry did not report canonical recapture evidence: $VERIFY_JSON"
[ "$(ledger_value applied_revision)" = verification-recovered ] || fail "verified retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "verified retry did not clear marker"
reset_baseline
pass "post-apply recapture blocked a false-green ledger advance and a clean retry verified"

say "attachment metadata failure is retained and retried on the same canonical attachment"
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' \
  | base64 -d > "$SITEREPO/duo-3206-probe.png"
ATTACH_ID=$(wp1 media import /siterepo/duo-3206-probe.png --porcelain | tr -d '\r' | tail -1)
[ -n "$ATTACH_ID" ] || fail "could not import attachment fixture"
wp1 duo capture --repo=/siterepo >/dev/null
ATTACH_UUID=$(wp1 post meta get "$ATTACH_ID" _duo_uuid | tr -d '\r')
[ -n "$ATTACH_UUID" ] || fail "capture did not mint attachment uuid"
wp1 eval "\\Duo\\Ledger::kv_set('applied_revision', 'baseline');" >/dev/null
wp1 db query "DELETE FROM wp_postmeta WHERE post_id=$ATTACH_ID; DELETE FROM wp_posts WHERE ID=$ATTACH_ID; DELETE FROM wp_duo_map WHERE uuid='$ATTACH_UUID';" >/dev/null
expect_failure "rebuild attachment metadata" "rebuild attachment metadata" \
  duo apply --repo=/siterepo --revision=bad-attachment
[ "$(ledger_value applied_revision)" = baseline ] || fail "attachment rebuild failure advanced applied_revision"
[ "$(retry_marker)" = RETAINED ] || fail "attachment failure did not retain retry marker"
NEW_ATTACH_ID=$(wp1 eval "echo \\Duo\\Ledger::id_for('$ATTACH_UUID', \\Duo\\Ledger::KIND_POST) ?? 'NULL';" 2>/dev/null | tr -d '\r' | tail -1)
[ "$NEW_ATTACH_ID" != NULL ] || fail "attachment test did not reach post-commit rebuild phase"
META_BEFORE=$(wp1 post meta get "$NEW_ATTACH_ID" _wp_attachment_metadata 2>/dev/null || true)
[ -z "$META_BEFORE" ] || fail "failed attachment rebuild unexpectedly wrote metadata"
wp1 duo apply --repo=/siterepo --revision=attachment-recovered >/dev/null
[ "$(ledger_value applied_revision)" = attachment-recovered ] || fail "attachment retry did not advance revision"
[ "$(retry_marker)" = NULL ] || fail "attachment retry did not clear marker"
META_AFTER=$(wp1 post meta get "$NEW_ATTACH_ID" _wp_attachment_metadata 2>/dev/null || true)
[ -n "$META_AFTER" ] || fail "attachment retry did not regenerate metadata"
pass "attachment metadata failure remained retryable and recovered"

printf '\n\033[1;32m✔ REGRESS_FATAL_MUTATIONS PASSED\033[0m\n'
