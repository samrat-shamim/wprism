#!/usr/bin/env bash
# Live public-path regression: automatic verified rollback on a `local` target.
#
# The local mirror of regress_ssh_adopt.sh's rollback-authority evidence, on
# the estate shape issue #3365 established for the local transport: one disposable
# pair supplies an installed WordPress database and webroot volume, its own
# containers are stopped, and a controller container mounts that webroot. The
# host CLI and the target then share one filesystem, which is what makes the
# `local` transport real rather than simulated.
#
# What it proves, through the product commands and nothing else:
#
#   1. `wprism adopt` on a `local` environment whose machine-local .wprism-envs.json
#      entry carries rollback_key_id/rollback_signing_key/rollback_recovery
#      provisions the public key and recovery-config, not just the runtime
#      files. Before RecoveryTransport that gate was `instanceof SshTransport`,
#      so a local adopt installed a runtime it could never authorize against.
#   2. `wprism promote` prints `promote profile: automatic verified rollback` and
#      its phase sequence, instead of the WARN + operator-directed checkpoint
#      path.
#   3. A failure injected at `lifecycle-activate` reaches
#      `wprism: promote: lifecycle-activate failed; entering signed verified
#      rollback` and converges to `prior world verified; rollback generation
#      <n> is rolled_back and exclusion is released`.
#   4. `wprism recover <env> --list` reads the signed catalog rather than refusing
#      with `recovery_authority_unavailable`.
#
# What it does NOT prove, deliberately: this is evidence, not a certificate.
# docs/ssh-rollback-certification.md records a 198-case crash matrix on a
# four-container two-sshd estate whose crash classes (SSH loss, remote-command
# kill) have no local analogue. A local certificate is a separate document with
# its own matrix, and nothing here may be described as certified.
#
# The checkpoint, upload, effect, exclusion and recovery-adapter providers are
# the file-backed fixtures the offline recovery suites drive
# (sandbox/tests/fixtures/), not the MariaDB-backed
# ssh-rollback-checkpoint-provider.php, which is specific to the certification
# estate's own `wprism_cert_state` schema. The subject here is the transport and
# the product path, not the provider.
#
# The CODE-RELEASE provider is the one exception, and it is authored here
# rather than shared, because the shipped fixture cannot describe a real
# compiled plan. sandbox/tests/fixtures/code-release-provider.php hard-codes a
# one-plugin release (`release_descriptor()`:24-38 returns owned_roots
# ['wp-content/plugins/acme'] and two rows), which is exactly the shape
# certify_ssh_rollback.sh hand-authors its plans in — that estate never runs
# `wprism promote`, so nothing there has to agree with a compiled artifact. A real
# WordPress code half can never be that shape: code-stage refuses unless the
# canonical `stylesheet`/`template` in state/options/core.json name a theme
# directory inside code/wp-content/themes, and all three lifecycle records must
# be present (agent/src/Code/CodeStateContract.php:79-91 and :161-173), so a
# code-enabled repository always owns at least a theme root beside its plugin
# roots. Against that plan the shipped fixture refuses with "wprism code release:
# desired release roots disagree with compiled plan"
# (recovery/CodeRelease.php:527). The provider written below is therefore
# PLAN-BOUND rather than payload-bound: it derives both descriptors by walking
# the immutable release it was asked to resolve, so the descriptor it publishes
# is whatever the compiled payload actually is, and CodeRelease's own
# assertDesiredInventory() stays the thing that decides whether that agrees
# with the plan. It keeps the shipped fixture's response contract verbatim and
# drops only the crash-injection hooks, which belong to the certification
# driver and have no caller here.
#
# Minimal reasonably-safe scope: one disposable pair, zero sweeps, no manifest
# matrix.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${LOCAL_VERIFIED_PAIR:-codexlocalverified}"
PORT1="${LOCAL_VERIFIED_PORT1:-9184}"
PORT2="${LOCAL_VERIFIED_PORT2:-9185}"
EXPECTED_SHA="${WPRISM_EXPECTED_SOURCE_SHA:-}"

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. sandbox/lib/pair_db.sh
pair_db_select_engine

for command in git docker php jq sha256sum; do
  command -v "$command" >/dev/null || fail "$command is required"
done
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] || fail "LOCAL_VERIFIED_PAIR must contain lowercase letters/digits and start with a letter"
[[ "$PORT1" =~ ^[0-9]+$ && "$PORT2" =~ ^[0-9]+$ ]] || fail "local-verified ports must be decimal integers"
PORT1=$((10#$PORT1)); PORT2=$((10#$PORT2))
(( PORT1 >= 8900 && PORT1 <= 65534 && PORT1 % 2 == 0 && PORT2 == PORT1 + 1 )) \
  || fail "LOCAL_VERIFIED_PORT1 must be an even port >=8900 and PORT2 its successor"
[[ "$EXPECTED_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "WPRISM_EXPECTED_SOURCE_SHA must be the exact candidate SHA"

ACTUAL_SHA="$(git rev-parse --verify 'HEAD^{commit}')"
[ "$EXPECTED_SHA" = "$ACTUAL_SHA" ] || fail "WPRISM_EXPECTED_SOURCE_SHA does not equal this checkout HEAD"
[ -d "$REPO_ROOT/.git" ] || fail "live verified-rollback evidence must run from a standalone clone"
[ -z "$(git status --porcelain --untracked-files=all)" ] || fail "live verified-rollback evidence requires a clean checkout"
export WPRISM_EXPECTED_SOURCE_SHA="$ACTUAL_SHA"

HOST_REPO1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
HOST_REPO2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"
HOST_ORIGIN="$REPO_ROOT/sandbox/siterepo/origin-${PAIR}.git"
WP_VOLUME="wprism-${PAIR}_wp1"
REPO_VOLUME="wprism-${PAIR}-verified-repo"
IMAGE="wprism-local-verified-cli:${PAIR}"
SCRATCH_ROOT=""
ENVS_FILE=""
SIGNING_KEY=""
SUITE_FIXTURES=""
EVIDENCE_LOG=""
THEME_SLUG="wprism-verified-fixture"
PROBE_COMPONENT="plugins/wprism-promotion-probe"
THEME_COMPONENT="themes/$THEME_SLUG"
PAIR_OWNED=0
REPO_VOLUME_OWNED=0
IMAGE_OWNED=0
GREEN=0

if [ -e "$HOST_REPO1" ] || [ -e "$HOST_REPO2" ] || [ -e "$HOST_ORIGIN" ]; then
  fail "pair repository roots already exist; choose an unused LOCAL_VERIFIED_PAIR"
fi
if [ -n "$(docker ps -a --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}')" ]; then
  fail "compose project wprism-${PAIR} already has containers; choose an unused pair"
fi
if docker volume inspect "$REPO_VOLUME" >/dev/null 2>&1; then
  fail "evidence volume $REPO_VOLUME already exists; inspect it or choose an unused pair"
fi
if docker image inspect "$IMAGE" >/dev/null 2>&1; then
  fail "evidence image $IMAGE already exists; inspect it or choose an unused pair"
fi

SCRATCH_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/${PAIR}-local-verified.XXXXXX")"
ENVS_FILE="$SCRATCH_ROOT/envs.json"
# The controller runs as uid 33 with HOME=/ inside wordpress:cli-php8.3, and
# `wprism init` builds its code-artifact cache before classifying any component --
# even one this estate declares first-party (cli/src/Code/WpOrgReleases.php:117-127
# resolves $XDG_CACHE_HOME, else ~/.cache -- "/.cache" here, which uid 33 cannot
# create; measured on the first run of this suite, 2026-08-24: "could not create
# the code artifact cache at /.cache/wprism/code-artifacts"). A real operator's host
# has a cache; give the controller one on scratch, world-writable because the
# bind mount is owned by the host user and the container writes as uid 33.
CACHE_DIR="$SCRATCH_ROOT/cache"
mkdir -p "$CACHE_DIR" && chmod 0777 "$CACHE_DIR"
# Create the file BEFORE any container bind-mounts it: every helper below
# mounts $ENVS_FILE at /controller/envs.json, and a bind mount whose host path
# does not exist yet is created by Docker as a DIRECTORY, after which the later
# write fails with "Is a directory" (measured on the first run of this suite,
# 2026-08-24). The real machine-local registry is written in place later.
: > "$ENVS_FILE"
SIGNING_KEY="$SCRATCH_ROOT/signing.key"
# Suite-owned fixture bytes the controller image does not carry: the plan-bound
# code-release provider (see the header), the site's own theme, and the broken
# build of the probe plugin. They reach the estate as ONE read-only bind mount
# for the same reason ENVS_FILE is created above before any container sees it.
SUITE_FIXTURES="$SCRATCH_ROOT/fixtures"
mkdir -p "$SUITE_FIXTURES/theme"
EVIDENCE_LOG="$SCRATCH_ROOT/evidence.log"

cleanup_on_exit() {
  local incoming=$? cleanup_failed=0 remaining=""
  trap - EXIT
  if [ "$PAIR_OWNED" -eq 1 ]; then
    if ! bash "$REPO_ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >>"$EVIDENCE_LOG" 2>&1; then
      printf 'FAIL: pair destroy failed; preserving all evidence and live resources for %s\n' "$PAIR" >&2
      cleanup_failed=1
    elif ! remaining=$(docker ps -a --filter "label=com.docker.compose.project=wprism-${PAIR}" --format '{{.ID}}'); then
      printf 'FAIL: could not verify pair teardown; preserving evidence for %s\n' "$PAIR" >&2
      cleanup_failed=1
    elif [ -n "$remaining" ]; then
      printf 'FAIL: pair %s still has containers; preserving all evidence\n' "$PAIR" >&2
      cleanup_failed=1
    else
      rm -rf -- "$HOST_REPO1" "$HOST_REPO2" "$HOST_ORIGIN"
    fi
  fi
  if [ "$cleanup_failed" -eq 0 ] && [ "$GREEN" -eq 1 ] && [ "$incoming" -eq 0 ]; then
    if [ "$REPO_VOLUME_OWNED" -eq 1 ]; then
      docker volume rm "$REPO_VOLUME" >/dev/null || cleanup_failed=1
    fi
    if [ "$IMAGE_OWNED" -eq 1 ]; then
      docker image rm "$IMAGE" >/dev/null || cleanup_failed=1
    fi
    [ "$cleanup_failed" -ne 0 ] || rm -rf -- "$SCRATCH_ROOT"
  else
    printf 'preserved local-verified evidence: %s\n' "$SCRATCH_ROOT" >&2
    [ "$REPO_VOLUME_OWNED" -eq 0 ] || printf 'preserved repository volume: %s\n' "$REPO_VOLUME" >&2
    [ "$IMAGE_OWNED" -eq 0 ] || printf 'preserved controller image: %s\n' "$IMAGE" >&2
  fi
  if [ "$cleanup_failed" -ne 0 ]; then
    exit 1
  fi
  exit "$incoming"
}
trap cleanup_on_exit EXIT

say "mint the controller signing key"
# The Ed25519 secret is the controller's. On a `local` target the controller IS
# the target, which is exactly the reduced tamper-evidence property
# docs/recovery-runtime.md documents; the key still never enters the repository
# or the target's control root, only the controller's own scratch.
php -r '
  $pair = sodium_crypto_sign_keypair();
  file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
' "$SIGNING_KEY" || fail "could not mint the controller signing key"
chmod 0600 "$SIGNING_KEY"
pass "controller signing key minted outside the repository"

say "author the suite-owned fixture bytes"
# The plan-bound code-release provider. Contract-identical to
# sandbox/tests/fixtures/code-release-provider.php -- same actions, same
# response key sets, same canonical descriptor bytes -- but its descriptors are
# DERIVED by walking the immutable release it is asked to resolve instead of
# being hard-coded to one plugin, which is what lets them describe a real
# compiled payload (see this file's header).
cat > "$SUITE_FIXTURES/code-release-provider.php" <<'FIXTURE_PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);

// argv: <state> <release-root> <pointer>. <state> is accepted and unused: the
// certification driver's crash-injection files (`.kill-before-pointer` and
// friends) belong to sandbox/tests/fixtures/ssh-rollback-certify-driver.php and
// have no caller on this estate, so this provider carries no fault hooks.

function release_canonical(array $value): string {
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = array_is_list($item)
                ? array_map(static fn($v) => is_array($v) ? json_decode(release_canonical($v), true) : $v, $item)
                : json_decode(release_canonical($item), true);
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function release_output(array $value): never { echo release_canonical($value) . "\n"; exit(0); }
function release_fail(string $message, int $code = 42): never { fwrite(STDERR, $message . "\n"); exit($code); }
function release_pointer_hash(string $release): string { return hash('sha256', $release); }
function release_atomic_write(string $path, string $bytes): void {
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    file_put_contents($tmp, $bytes); chmod($tmp, 0600); rename($tmp, $path);
}

/**
 * The component roots one release owns, release-relative, in compiled order.
 * The compiled inventory sorts '<root>/<component>' (CodeDescriptorCompiler::
 * descriptor_from_source), and the constant 'wp-content/' prefix CodeRelease
 * expects (assertDesiredInventory, recovery/CodeRelease.php:527) preserves that
 * order, so one sort here answers for both.
 *
 * @return list<string>
 */
function release_owned_roots(string $base): array {
    $owned = [];
    foreach (['mu-plugins', 'plugins', 'themes'] as $root) {
        $dir = $base . '/wp-content/' . $root;
        if (!is_dir($dir)) continue;
        $entries = scandir($dir);
        if ($entries === false) release_fail('release component root cannot be read');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $owned[] = 'wp-content/' . $root . '/' . $entry;
        }
    }
    sort($owned, SORT_STRING);
    return $owned;
}

/**
 * Descriptor rows for one owned root: the root itself, every directory beneath
 * it, and every regular file with its hash. Empty directories are recorded the
 * same way, which is why the release is staged without any -- the compiled
 * inventory records regular files only, so an empty directory would be a row
 * the plan cannot contain.
 *
 * @param array<string,array{path:string,sha256:string,type:string}> $rows
 */
function release_rows(string $base, string $owned, array &$rows): void {
    $path = $base . '/' . $owned;
    if (is_link($path)) release_fail('symlink refused');
    if (is_file($path)) {
        $rows[$owned] = ['path' => $owned, 'sha256' => (string) hash_file('sha256', $path), 'type' => 'file'];
        return;
    }
    if (!is_dir($path)) release_fail('owned root is missing from the release');
    $rows[$owned] = ['path' => $owned, 'sha256' => hash('sha256', ''), 'type' => 'directory'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($base) + 1);
        if ($entry->isLink()) release_fail('symlink refused');
        if ($entry->isDir()) {
            $rows[$relative] = ['path' => $relative, 'sha256' => hash('sha256', ''), 'type' => 'directory'];
            continue;
        }
        if (!$entry->isFile()) release_fail('release entries must be regular files or directories');
        $rows[$relative] = ['path' => $relative, 'sha256' => (string) hash_file('sha256', $entry->getPathname()), 'type' => 'file'];
    }
}

/** @return array<string,mixed> */
function release_descriptor(string $role, string $releaseRoot, string $release, string $artifact, string $revision, int $generation): array {
    $base = $releaseRoot . '/' . $release;
    if (is_link($base) || !is_dir($base)) release_fail('release is missing or unsafe');
    $owned = release_owned_roots($base);
    if ($owned === []) release_fail('release carries no component root');
    $rows = [];
    foreach ($owned as $root) release_rows($base, $root, $rows);
    ksort($rows, SORT_STRING);
    return [
        'artifact_hash' => $artifact,
        'code_revision' => $revision,
        'files' => array_values($rows),
        'format' => 'wprism-code-release-descriptor/v1',
        'generation' => $generation,
        'owned_roots' => $owned,
        'release_id' => $release,
        'role' => $role,
    ];
}

/** Verify every descriptor file/type/hash and absence of unrecorded owned paths. */
function release_verify(string $releaseRoot, array $descriptor): void {
    $base = $releaseRoot . '/' . $descriptor['release_id'];
    if (is_link($base) || !is_dir($base)) release_fail('release is missing or unsafe');
    $recorded = [];
    foreach ($descriptor['files'] as $entry) {
        $path = $base . '/' . $entry['path']; $recorded[$entry['path']] = true;
        if (is_link($path)) release_fail('symlink refused');
        if ($entry['type'] === 'directory') {
            if (!is_dir($path)) release_fail('descriptor directory missing');
        } elseif (!is_file($path) || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) {
            release_fail('descriptor file hash mismatch');
        }
    }
    foreach ($descriptor['owned_roots'] as $owned) {
        if (!isset($recorded[$owned])) release_fail('owned root is not recorded');
        $root = $base . '/' . $owned;
        if (!is_dir($root)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if ($file->isLink()) release_fail('symlink refused');
            if (!isset($recorded[$relative])) release_fail('unrecorded owned path refused');
        }
    }
}

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$releaseRoot = $argv[2];
$pointer = $argv[3];
$priorRelease = is_file($pointer) ? trim((string) file_get_contents($pointer)) : 'release-prior';
$desiredRelease = 'release-desired-' . (string) ($request['generation'] ?? 0);
$action = (string) ($request['action'] ?? '');
$base = ['format' => 'wprism-code-release-provider-response/v1'];

if ($action === 'probe') {
    release_output($base + [
        'atomic_pointer' => true, 'available' => true, 'build_resolution_off_target' => true,
        'immutable_releases' => true, 'mutable_resolution' => false,
        'plan_bound_code_inventory' => true,
        'provider_id' => 'local-release-fixture', 'provider_version' => '2.0.0',
        'state' => 'ready', 'target_generation_fenced' => true,
        'target_git_history' => false, 'target_registry_credentials' => false,
        'verified_descriptors' => true,
    ]);
}

if ($action === 'prepare') {
    // Only the v2 (plan-bound) request is answered. The v1 contract states the
    // desired descriptor's hash up front, which a provider can only satisfy by
    // already knowing the descriptor bytes -- that is the controller-knows-the-
    // release shape the certification driver drives, not the product path.
    if (($request['format'] ?? '') !== 'wprism-code-release-provider-request/v2'
        || !is_array($request['desired_code_inventory'] ?? null)) {
        release_fail('unsupported prepare request format');
    }
    $selected = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    if ($selected !== $priorRelease || !is_dir($releaseRoot . '/' . $priorRelease . '/wp-content')) release_fail('missing exact prior release');
    if (!is_dir($releaseRoot . '/' . $desiredRelease . '/wp-content')) release_fail('off-target desired release unavailable');
    $prior = release_descriptor('prior', $releaseRoot, $priorRelease, hash('sha256', 'prior-artifact'), hash('sha256', 'prior-code'), max(0, (int) $request['generation'] - 1));
    $desired = release_descriptor('desired', $releaseRoot, $desiredRelease, (string) $request['artifact_hash'], (string) $request['desired_code_revision'], (int) $request['generation']);
    $desiredBytes = release_canonical($desired) . "\n";
    release_atomic_write((string) $request['prior_descriptor_path'], release_canonical($prior) . "\n");
    release_atomic_write((string) $request['desired_descriptor_path'], $desiredBytes);
    release_verify($releaseRoot, $prior); release_verify($releaseRoot, $desired);
    release_output($base + [
        'atomic_pointer' => true, 'available' => true, 'build_resolution_off_target' => true,
        'desired_descriptor_path' => $request['desired_descriptor_path'], 'desired_descriptor_sha256' => hash('sha256', $desiredBytes),
        'desired_pointer_sha256' => release_pointer_hash($desiredRelease), 'desired_release_id' => $desiredRelease,
        'immutable_releases' => true, 'mutable_resolution' => false,
        'prior_descriptor_path' => $request['prior_descriptor_path'], 'prior_descriptor_sha256' => hash('sha256', release_canonical($prior) . "\n"),
        'prior_pointer_sha256' => release_pointer_hash($priorRelease), 'prior_release_id' => $priorRelease,
        'plan_bound_code_inventory' => true,
        'provider_id' => 'local-release-fixture', 'provider_version' => '2.0.0', 'state' => 'prepared',
        'target_generation' => (int) $request['generation'], 'target_generation_fenced' => true,
        'target_git_history' => false, 'target_registry_credentials' => false, 'verified_descriptors' => true,
    ]);
}

if (in_array($action, ['select_desired', 'restore_prior', 'verify_desired', 'verify_prior'], true)) {
    $role = str_contains($action, 'desired') ? 'desired' : 'prior';
    $descriptorPath = (string) $request[$role . '_descriptor_path'];
    $descriptorBytes = (string) file_get_contents($descriptorPath);
    $descriptor = json_decode($descriptorBytes, true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals((string) $request[$role . '_descriptor_sha256'], hash('sha256', $descriptorBytes))) release_fail('descriptor changed');
    $target = (string) $descriptor['release_id'];
    $current = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
    $isVerify = str_starts_with($action, 'verify_');
    // Refuse changed/unrecorded release contents before pointer mutation. The
    // second verification below proves the selected world independently.
    release_verify($releaseRoot, $descriptor);
    if (!$isVerify) {
        $otherRole = $role === 'desired' ? 'prior' : 'desired';
        $other = json_decode((string) file_get_contents((string) $request[$otherRole . '_descriptor_path']), true, 512, JSON_THROW_ON_ERROR);
        $from = (string) $other['release_id'];
        if ($current !== $from && $current !== $target) release_fail('concurrent pointer writer refused');
        if ($current !== $target) release_atomic_write($pointer, $target . "\n");
    } elseif ($current !== $target) {
        release_fail('selected pointer changed before verification');
    }
    release_verify($releaseRoot, $descriptor);
    $result = hash('sha256', release_canonical(['descriptor_sha256' => hash('sha256', $descriptorBytes), 'generation' => $request['generation'], 'pointer_sha256' => release_pointer_hash($target), 'release_id' => $target, 'target_id' => $request['target_id']]));
    release_output($base + [
        'action' => $action, 'atomic_pointer' => true, 'available' => true,
        'descriptor_sha256' => hash('sha256', $descriptorBytes), 'generation' => (int) $request['generation'],
        'no_unrecorded_owned_paths' => true, 'pointer_sha256' => release_pointer_hash($target),
        'provider_id' => 'local-release-fixture', 'provider_version' => '2.0.0', 'release_id' => $target,
        'result_sha256' => $result, 'state' => $isVerify ? 'verified' : 'selected',
        'symlinks_absent' => true, 'target_id' => $request['target_id'], 'verified_file_inventory' => true,
    ]);
}

if ($action === 'delete_prior') {
    $deleteRelease = (string) $request['release_id'];
    if (trim((string) file_get_contents($pointer)) === $deleteRelease) release_fail('cannot delete selected prior release');
    $dir = $releaseRoot . '/' . $deleteRelease;
    if (is_dir($dir) && !is_link($dir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { @rmdir($entry->getPathname()); continue; }
            @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
    release_output($base + ['action' => 'delete_prior', 'available' => true, 'prior_release_absent' => !is_dir($dir), 'provider_id' => 'local-release-fixture', 'provider_version' => '2.0.0', 'state' => 'deleted']);
}

release_fail('unsupported fixture action');
FIXTURE_PHP

# The site's own theme. A repository that opts into code materialization must
# carry the canonical stylesheet/template as a real theme directory
# (agent/src/Code/CodeStateContract.php:79-91), so the estate needs a theme it
# owns; a first-party one keeps the whole code half in Git and off wp.org, which
# is what makes the compiled payload deterministic run to run.
cat > "$SUITE_FIXTURES/theme/style.css" <<'FIXTURE_CSS'
/*
Theme Name: WPrism Local Verified Fixture
Version: 1.0.0
Description: Regression-only theme for the local verified-rollback estate.
*/
FIXTURE_CSS
cat > "$SUITE_FIXTURES/theme/index.php" <<'FIXTURE_INDEX'
<?php
// This estate is headless and never renders, but WordPress refuses to activate
// a theme with no index.php template, so the file has to exist.
FIXTURE_INDEX

# The broken build of the probe plugin: same plugin identity and the same
# Version header, so the only thing that moves between the two generations is
# the bytes. A moved version would be observed as code_drift and refuse the
# promotion before lifecycle-activate could fail, which is not the failure this
# suite injects.
cat > "$SUITE_FIXTURES/failing-probe.php" <<'FIXTURE_PROBE'
<?php
/**
 * Plugin Name: WPrism Promotion Probe
 * Version: 1.0.0
 */

register_activation_hook(__FILE__, static function (): void {
    throw new RuntimeException('injected lifecycle-activate failure');
});
FIXTURE_PROBE
chmod -R a+rX "$SUITE_FIXTURES"
pass "plan-bound code-release provider, first-party theme, and broken probe build are authored"

say "build a Git-capable controller and create one headless disposable pair"
docker build -q -f sandbox/init-cli.Dockerfile -t "$IMAGE" sandbox >/dev/null
IMAGE_OWNED=1
PAIR_OWNED=1
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >>"$EVIDENCE_LOG" 2>&1

pair_compose() {
  (cd "$REPO_ROOT/sandbox" && \
    WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" \
    docker compose -p "wprism-${PAIR}" -f pair.yml "$@")
}
pair_compose stop wp1 wp2 cli1 cli2 >>"$EVIDENCE_LOG" 2>&1
docker volume inspect "$WP_VOLUME" >/dev/null 2>&1 || fail "pair webroot volume $WP_VOLUME is missing"
docker volume create --label "wprism.live-regression=local-verified-rollback" "$REPO_VOLUME" >/dev/null
REPO_VOLUME_OWNED=1

# The pair's bind destinations exist underneath its named volume. With every
# pair container stopped, remove only those test-owned mountpoint bytes so the
# controller sees the required pre-WPrism WordPress target.
docker run --rm --user 0 \
  -v "$WP_VOLUME:/var/www/html" -v "$REPO_VOLUME:/siterepo" \
  --entrypoint sh "$IMAGE" -eu -c '
    mkdir -p /siterepo
    chown 33:33 /siterepo
    mkdir -p /var/www/html/wp-content/mu-plugins
    chown 33:33 /var/www/html/wp-content/mu-plugins
    rm -rf /var/www/html/wp-content/mu-plugins/wprism \
      /var/www/html/wp-content/mu-plugins/wprism-loader.php \
      /var/www/html/wp-content/mu-plugins/manifests
  '

DOCKER_COMMON=(
  --rm --network wprism-shared --user 33:33 --workdir /wprism-source
  -e "WORDPRESS_DB_HOST=$WPRISM_DB_HOST"
  -e WORDPRESS_DB_USER=wordpress
  -e WORDPRESS_DB_PASSWORD=wordpress
  -e "WORDPRESS_DB_NAME=wp_${PAIR}1"
  -e 'WORDPRESS_CONFIG_EXTRA=define("WP_ENVIRONMENT_TYPE", "local");'
  -v "$WP_VOLUME:/var/www/html"
  -v "$REPO_VOLUME:/siterepo"
  -v "$REPO_ROOT:/wprism-source:ro"
  -v "$ENVS_FILE:/controller/envs.json:ro"
  -v "$SIGNING_KEY:/controller/signing.key:ro"
  -v "$SUITE_FIXTURES:/controller/fixtures:ro"
  -e XDG_CACHE_HOME=/controller-cache
  -v "$CACHE_DIR:/controller-cache"
)

controller() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint php "$IMAGE" \
    /wprism-source/cli/wprism --envs-file=/controller/envs.json "$@"
}
target_wp() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint wp "$IMAGE" --path=/var/www/html "$@"
}
target_sh() {
  docker run "${DOCKER_COMMON[@]}" --entrypoint sh "$IMAGE" -eu -c "$1"
}
run_controller() {
  local label="$1"; shift
  set +e
  OUT="$(controller "$@" 2>&1)"
  CODE=$?
  set -e
  {
    printf '\n[%s] exit=%s\n' "$label" "$CODE"
    printf '%s\n' "$OUT"
  } >>"$EVIDENCE_LOG"
  printf '%s\n' "$OUT"
}

say "stage the target-owned recovery providers and their state"
# Providers are TARGET-owned by contract: absolute argv, invoked target-side,
# never a shell command. On a local target that is the same filesystem, so they
# are staged into the repository volume rather than scp'd.
target_sh '
  set -eu
  mkdir -p /siterepo/providers /siterepo/provider-state /siterepo/releases /siterepo/uploads/2026/08 /siterepo/offload /siterepo/media
  for file in offline-checkpoint-provider.php upload-provider.php \
              effect-provider.php recovery-adapter.php recovery-exclusion-provider.php; do
    cp "/wprism-source/sandbox/tests/fixtures/$file" "/siterepo/providers/$file"
    chmod 0700 "/siterepo/providers/$file"
  done
  cp /controller/fixtures/code-release-provider.php /siterepo/providers/code-release-provider.php
  chmod 0700 /siterepo/providers/code-release-provider.php
  printf "CREATE TABLE prior_state (id INT);\n" > /siterepo/provider-state/database-export.sql
  php -r "file_put_contents(\"/siterepo/provider-state/kms.key\", base64_encode(random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES)) . \"\n\");"
  php -r "file_put_contents(\"/siterepo/provider-state/upload.key\", random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));"
  chmod 0600 /siterepo/provider-state/kms.key /siterepo/provider-state/upload.key
  printf "release-prior\n" > /siterepo/provider-state/code-pointer
  printf "prior-upload" > /siterepo/uploads/2026/08/photo.jpg
' || fail "could not stage the target-owned recovery providers"
# The releases themselves cannot be staged yet: an immutable release is the
# compiled code payload, and that payload does not exist until init has built
# the repository's code half. They are staged in their own step below.
pass "target-owned providers and their state are staged"

say "write the machine-local environment that arms the rollback authority"
php -r '
$body = ["envs" => [
    "local" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
        "bootstrap" => ["format" => "wprism-local-control-plane/v1"],
        "rollback_key_id" => "local-verified-live",
        "rollback_signing_key" => "/controller/signing.key",
        "verified_rollback" => [
            "claim_ttl_seconds" => 300,
            "encryption_key_id" => "local-kms-fixture",
            "retention_seconds" => 86400,
        ],
        "rollback_recovery" => [
            "adapters" => [
                "code_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "database_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "prior_verify" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
                "storage_restore" => ["/usr/local/bin/php", "/siterepo/providers/recovery-adapter.php"],
            ],
            "checkpoint_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/offline-checkpoint-provider.php",
                "/siterepo/provider-state/checkpoint", "/siterepo/provider-state/database-export.sql",
                "/siterepo/provider-state/kms.key",
            ],
            "code_release_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/code-release-provider.php",
                "/siterepo/provider-state/code", "/siterepo/releases", "/siterepo/provider-state/code-pointer",
            ],
            "effect_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/effect-provider.php",
                "/siterepo/provider-state/effects", "/siterepo/site",
            ],
            "exclusion_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/recovery-exclusion-provider.php",
                "/siterepo/provider-state/exclusion.json",
            ],
            "timeout_seconds" => 30,
            "upload_provider" => [
                "/usr/local/bin/php", "/siterepo/providers/upload-provider.php",
                "/siterepo/provider-state/uploads", "/siterepo/uploads", "/siterepo/offload",
                "/siterepo/media", "/siterepo/provider-state/upload.key",
            ],
        ],
    ],
    // The same target with the authority NOT configured: its refusals must be
    // exactly what they were before this transport could carry an authority.
    "unarmed" => [
        "transport" => "local",
        "wp_path" => "/var/www/html",
        "repo_path" => "/siterepo/site",
        "bootstrap" => ["format" => "wprism-local-control-plane/v1"],
    ],
]];
file_put_contents($argv[1], json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' "$ENVS_FILE" || fail "could not write the machine-local environment file"
chmod 0644 "$ENVS_FILE"
pass "machine-local environment names its rollback authority"

say "wprism envs is unchanged for the environment that did not opt in"
run_controller "envs" envs
[ "$CODE" -eq 0 ] || fail "wprism envs failed"
grep -Fq 'rollback_key_id=local-verified-live' <<<"$OUT" \
  || fail "wprism envs did not name the configured local rollback authority"
grep -Eq '^unarmed[[:space:]]+local  wp_path=/var/www/html repo_path=/siterepo/site bootstrap=authorized$' <<<"$OUT" \
  || grep -Fq 'local  wp_path=/var/www/html repo_path=/siterepo/site bootstrap=authorized' <<<"$OUT" \
  || fail "the un-armed local environment's describe line moved"
pass "opting in is visible; not opting in changes nothing"

say "adopt provisions the public key and recovery-config, not just the runtime"
run_controller "adopt" adopt local
[ "$CODE" -eq 0 ] || fail "local adopt failed"
grep -Fq '+ rollback authority' <<<"$OUT" || fail "adopt did not report installing the rollback authority"
target_sh '
  test -f /siterepo/site/.wprism/control/recovery-runtime/rollback-control.php
  test -f /siterepo/site/.wprism/control/recovery-config.json
  test -f /siterepo/site/.wprism/control/public-keys/local-verified-live.pub
  test -f /siterepo/site/.wprism/control/target.json
  test ! -e /siterepo/site/.wprism/control/rollback-signing.key
  test ! -e /siterepo/site/.wprism/control/private-keys
' || fail "local adopt did not provision the public-key-only rollback authority"
[ "$(target_sh 'stat -c %a /siterepo/site/.wprism/control')" = "700" ] \
  || fail "rollback control root is not protected mode 0700"
pass "the local control plane carries the public key and recovery-config, and no private material"

say "status verifies the authority the same way an SSH target's does"
run_controller "status" status local
grep -Fq '[PASS] rollback authority: ready (no active generation)' <<<"$OUT" \
  || fail "status did not verify and render the local rollback authority"
run_controller "unarmed status" status unarmed
grep -Fq 'rollback authority' <<<"$OUT" \
  && fail "the un-armed local environment printed a rollback authority line it never had before" \
  || pass "status reports the authority for the armed environment and stays silent for the un-armed one"

say "initialize the canonical baseline over a wholly first-party code half"
# The probe plugin and the site's own theme are on the target BEFORE init and
# both declared first-party, so the canonical code half carries exactly the two
# of them: a component that appears on the target after init has no source under
# code/wp-content and capture refuses it with code_state_mismatch (measured on
# this suite's first run, 2026-08-24) -- the product working, not a fixture the
# suite may bypass.
#
# The theme is not decoration. A repository that opts into code materialization
# must carry the canonical stylesheet/template as a real theme directory
# (agent/src/Code/CodeStateContract.php:79-91), so every compiled payload owns a
# theme root beside its plugin roots. Declaring THIS theme first-party keeps the
# whole code half in Git: nothing is locked, so no component is resolved from
# wp.org at promote time and the compiled payload is byte-identical run to run.
target_sh '
  set -eu
  mkdir -p /var/www/html/wp-content/plugins/wprism-promotion-probe
  cp /wprism-source/sandbox/tests/fixtures/wprism-promotion-probe.php \
     /var/www/html/wp-content/plugins/wprism-promotion-probe/wprism-promotion-probe.php
  mkdir -p "/var/www/html/wp-content/themes/'"$THEME_SLUG"'"
  cp /controller/fixtures/theme/style.css /controller/fixtures/theme/index.php \
     "/var/www/html/wp-content/themes/'"$THEME_SLUG"'/"
' || fail "could not install the first-party probe plugin and theme"
# init inventories ACTIVE components only (cli/src/Code/CodeClassifier.php
# assertFirstPartyKnown: "--first-party names …, which is not a component this
# site has" on an installed-but-inactive probe, measured 2026-08-24), so both
# are activated before the proposal is built.
target_wp plugin activate wprism-promotion-probe >/dev/null || fail "could not activate the promotion probe before init"
target_wp theme activate "$THEME_SLUG" >/dev/null || fail "could not activate the first-party theme before init"
# --allow-unmanaged-plugins: the probe has no adapter, so init otherwise blocks on
# active_plugin_without_adapter before evaluating --first-party (init readiness
# is all-or-nothing; the flag leaves the probe unmanaged, which is exactly what
# a regression-only plugin is).
run_controller "init" init local --yes --allow-unmanaged-plugins \
  "--first-party=$PROBE_COMPONENT,$THEME_COMPONENT"
[ "$CODE" -eq 0 ] || fail "init failed after local adoption"
grep -Fq 'Initialized canonical state baseline' <<<"$OUT" || fail "init omitted its canonical baseline result"
# Publish the baseline the way init's own next-steps block instructs. init does
# not commit -- it prints the four commands an operator runs -- and this suite
# needs the repository to have a history, because the failing build below is
# authored as a COMMITTED change to the site's own code rather than an
# untracked edit sitting beside it.
target_sh '
  set -eu
  git -C /siterepo/site add -A
  git -C /siterepo/site -c user.name=wprism -c user.email=wprism@example.test \
    commit -qm "wprism: initial code and state baselines"
' || fail "could not publish the initialized baseline"
pass "canonical baseline established and published"

say "stage the immutable releases off-target, from the compiled code half"
# An immutable release IS the compiled payload: CodeRelease compares the
# descriptor the provider publishes against the plan's own code inventory and
# refuses any disagreement (assertDesiredInventory, recovery/CodeRelease.php:521-573).
# So a release is built here by copying the repository's code half -- off-target
# work standing in for the build system the code_release_provider probe
# attestation promises ("build_resolution_off_target"), never a target-side
# build. Empty directories are pruned because the compiled inventory records
# regular files only, so one would be an owned path no descriptor row can name.
stage_release() {
  local release="$1"
  target_sh "
    set -eu
    test ! -e /siterepo/releases/$release
    mkdir -p /siterepo/releases/$release
    cp -a /siterepo/site/code/wp-content /siterepo/releases/$release/wp-content
    find /siterepo/releases/$release/wp-content -depth -type d -exec rmdir {} \; 2>/dev/null || true
    test -d /siterepo/releases/$release/wp-content
  " || fail "could not stage the off-target release $release"
}
# The release the next promotion will resolve. The provider names it
# `release-desired-<generation>`, and the generation a claim takes is the one
# after the authority's current terminal generation, which target.json states.
next_release() {
  local generation
  generation="$(target_sh 'php -r "echo (int) (json_decode(file_get_contents(\"/siterepo/site/.wprism/control/target.json\"), true)[\"generation\"] ?? -1);"')" \
    || fail "could not read the rollback authority generation"
  [[ "$generation" =~ ^[0-9]+$ ]] || fail "the rollback authority reported no usable generation"
  printf 'release-desired-%s\n' "$((generation + 1))"
}
stage_release release-prior
DESIRED_RELEASE="$(next_release)"
[ "$DESIRED_RELEASE" = release-desired-1 ] \
  || fail "a freshly adopted authority did not start at generation 0"
stage_release "$DESIRED_RELEASE"
pass "prior and desired releases carry the compiled payload the plan describes"

say "promote selects the automatic verified profile off SSH"
# The probe plugin's activation hook is a real lifecycle boundary: promote must
# reach lifecycle-activate through the signed generation, not through the
# operator-directed checkpoint path. (Its bytes are already in the code half --
# see init above.)
target_wp plugin activate wprism-promotion-probe >/dev/null || fail "could not activate the promotion probe"
run_controller "capture" capture local --yes
[ "$CODE" -eq 0 ] || fail "capture failed"
target_wp plugin deactivate wprism-promotion-probe >/dev/null || fail "could not deactivate the promotion probe"

run_controller "promote" promote local
[ "$CODE" -eq 0 ] || fail "verified promote failed"
grep -Fq 'promote profile: automatic verified rollback' <<<"$OUT" \
  || fail "promote did not select the automatic verified profile on a local target"
grep -Fq 'promote phase: rollback-claim' <<<"$OUT" || fail "promote did not publish the signed claim"
grep -Fq 'promote phase: promotion-begin' <<<"$OUT" || fail "promote did not enter promotion-begin"
grep -Fq 'promote phase: lifecycle-activate' <<<"$OUT" || fail "promote did not enter lifecycle-activate"
grep -Fq 'promote phase: apply' <<<"$OUT" || fail "promote did not reach apply"
grep -Fq 'WARN automatic verified rollback unavailable' <<<"$OUT" \
  && fail "promote still warned that the verified profile is unavailable" \
  || pass "promote ran the signed verified profile end to end on a local target"
[ "$(target_wp option get wprism_promotion_probe_activated 2>/dev/null || true)" = yes ] \
  || fail "the lifecycle activation hook did not run for real"
pass "generation 1 committed through the signed verified profile"

say "recover reads the signed catalog rather than refusing"
run_controller "recover list" recover local --list
[ "$CODE" -eq 0 ] || fail "recover --list failed on the armed local environment"
grep -Fq 'recovery_authority_unavailable' <<<"$OUT" \
  && fail "an armed local environment still refused with recovery_authority_unavailable" \
  || pass "the signed catalog is readable on a local target"
run_controller "unarmed recover list" recover unarmed --list
grep -Fq 'this transport carries no rollback authority runtime' <<<"$OUT" \
  || fail "the un-armed local environment lost its honest no-authority disclosure"
pass "the refusal is about a configured authority, not about SSH"

say "a lifecycle-activate failure converges through the signed rollback"
# The injected failure is a real activation fatal, produced by the probe
# plugin's own hook, not by patching the orchestrator. It is authored where a
# first-party component's bytes live -- the site repository's code half -- and
# committed, so promote's own code-stage materializes the broken hook onto the
# target before lifecycle-activate fires it. Editing the target's copy directly
# would be overwritten by that same stage.
target_sh '
  set -eu
  cp /controller/fixtures/failing-probe.php \
     /siterepo/site/code/wp-content/plugins/wprism-promotion-probe/wprism-promotion-probe.php
  git -C /siterepo/site -c user.name=wprism -c user.email=wprism@example.test commit -qam "inject lifecycle-activate failure"
' || fail "could not author the failing build in the code half"
FAILING_RELEASE="$(next_release)"
[ "$FAILING_RELEASE" = release-desired-2 ] \
  || fail "the committed generation did not advance the authority to 1"
stage_release "$FAILING_RELEASE"
target_wp plugin deactivate wprism-promotion-probe >/dev/null 2>&1 || true
run_controller "failing promote" promote local
[ "$CODE" -ne 0 ] || fail "promote succeeded despite an injected lifecycle-activate failure"
grep -Fq 'wprism: promote: lifecycle-activate failed; entering signed verified rollback' <<<"$OUT" \
  || fail "the injected failure did not enter the signed verified rollback"
grep -Eq 'wprism: promote: prior world verified; rollback generation [0-9]+ is rolled_back and exclusion is released' <<<"$OUT" \
  || fail "the signed rollback did not converge to a verified prior world with the exclusion released"
grep -Fq 'operator-directed' <<<"$OUT" \
  && fail "a verified promotion fell through to the operator-directed dump path" \
  || pass "a failure after promoting converged through signed operations only"

STATUS_JSON="$(target_sh 'php /siterepo/site/.wprism/control/recovery-runtime/rollback-control.php status --root=/siterepo/site/.wprism/control')"
jq -e '.state == "rolled_back" and .terminal == true' <<<"$STATUS_JSON" >/dev/null \
  || fail "the target authority did not finish rolled_back and terminal"
EXCLUSION_STATE="$(target_sh 'php -r "\$s=json_decode(file_get_contents(\"/siterepo/provider-state/exclusion.json\"),true);echo \$s[\"state\"];"')"
[ "$EXCLUSION_STATE" = released ] || fail "the maintenance exclusion remains held after a terminal rollback"
pass "the target's own runtime and exclusion provider agree with the controller's report"

GREEN=1
printf '\nREGRESS_LOCAL_VERIFIED_ROLLBACK_LIVE PASSED\n'
