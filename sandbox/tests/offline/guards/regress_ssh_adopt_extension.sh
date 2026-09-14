#!/usr/bin/env bash
# Offline regression for the shared SSH-adoption extension helpers.
#
# The live SSH suites source ssh_adopt_extension.sh inside an already-owned
# fixture. This suite replaces only ssh_fixture/scp/curl and translates the
# helper's absolute remote paths into one private tree; the helper's actual
# shell command bodies still run. That keeps traversal, exact-inventory,
# generation and cleanup failures reproducible without Docker, SSH, WordPress,
# or a network download.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd -P)"
SCRATCH="$(mktemp -d "${TMPDIR:-/tmp}/wprism-ssh-adopt-extension.XXXXXX")"
SCRATCH="$(cd "$SCRATCH" && pwd -P)"
trap 'rm -rf -- "$SCRATCH"' EXIT INT TERM

ORIGINAL_PATH="$PATH"
PHP_BIN="$(command -v php)"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

command -v jq >/dev/null 2>&1 || fail 'jq is required'
[ -x "$PHP_BIN" ] || fail 'php is required'

TMP="$SCRATCH/bootstrap"
mkdir -p "$TMP"
# shellcheck source=../../lib/ssh_adopt_extension.sh
source "$ROOT/sandbox/tests/lib/ssh_adopt_extension.sh"

case_core_archive() ( # Execute the real install body with a controlled native CLI.
  local mode="$1" code=0 output
  DIAG_DIR="$SCRATCH/core-$mode/diagnostics"
  local core_bin="$SCRATCH/core-$mode/bin" core_site="$SCRATCH/core-$mode/site"
  mkdir -p "$DIAG_DIR" "$core_bin" "$core_site"
  chmod 0700 "$DIAG_DIR"
  export CORE_PROBE_MODE="$mode" CORE_PROBE_LOG="$SCRATCH/core-$mode/calls.jsonl"
  export CORE_PROBE_CHOWN="$SCRATCH/core-$mode/chown"
  NET=owned-core-net VOLUME=owned-core-volume
  cat >"$core_bin/wp" <<'PHP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
file_put_contents(getenv('CORE_PROBE_LOG'), json_encode($args) . "\n", FILE_APPEND);
$mode = getenv('CORE_PROBE_MODE');
$operation = $args[1] ?? '';
if ($operation === 'download') {
    if (($args[2] ?? '') !== 'https://wordpress.org/wordpress-7.1.zip') exit(41);
    if ($mode === 'download-failure') exit(7);
    if ($mode === 'download-warning') fwrite(STDERR, "Warning: private-operator-value\n");
} elseif ($operation === 'verify-checksums') {
    if (!in_array('--version=7.1', $args, true) || !in_array('--include-root', $args, true)) exit(42);
    if ($mode === 'checksum-failure') exit(8);
    if ($mode === 'checksum-warning') fwrite(STDERR, "Warning: private-operator-value\n");
    if ($mode === 'checksum-stdout') echo "Notice: private-operator-value\n";
} elseif ($operation === 'version') {
    if ($mode === 'version-failure') exit(9);
    echo $mode === 'wrong-version' ? "7.0.3\n" : "7.1\n";
} else exit(43);
PHP
  cat >"$core_bin/chown" <<'SH'
#!/bin/sh
printf 'complete\n' >"$CORE_PROBE_CHOWN"
SH
  chmod +x "$core_bin/wp" "$core_bin/chown"
  docker() {
    [ "$#" -eq 14 ] && [ "$1" = run ] && [ "$2" = --rm ] && [ "$3" = --user ] && [ "$4" = root ] \
      && [ "$5" = --network ] && [ "$6" = "$NET" ] && [ "$7" = -v ] \
      && [ "$8" = "$VOLUME:/var/www/html" ] && [ "$9" = wordpress:cli-php8.3 ] \
      && [ "${10}" = sh ] && [ "${11}" = -lc ] && [ "${13}" = sh ] && [ "${14}" = 7.1 ] || return 44
    local body="${12}"
    body="${body//\/usr\/local\/bin\/wp/$core_bin/wp}"
    body="${body//\/var\/www\/html/$core_site}"
    PATH="$core_bin:$ORIGINAL_PATH" /bin/sh -c "$body" sh "${14}"
  }
  output="$(wprism_ssh_install_core 7.1 2>&1)" || code=$?
  if [ "$mode" = ready ]; then
    [ "$code" -eq 0 ] && [ -z "$output" ] && [ -f "$CORE_PROBE_CHOWN" ] || fail 'exact core installation did not complete'
    [ "$(wc -l <"$CORE_PROBE_LOG" | tr -d ' ')" -eq 3 ] || fail 'core installation skipped a native verification'
    [ "$(cat "$DIAG_DIR/core-install.exit")" = 0 ] || fail 'core installation lost its exact transport status'
  else
    [ "$code" -ne 0 ] && [[ "$output" != *private-operator-value* ]] || fail "core installation accepted or exposed $mode"
    case "$mode" in
      download-failure|checksum-failure|version-failure|wrong-version)
        [ ! -e "$CORE_PROBE_CHOWN" ] || fail 'failed core verification reached final publication ownership' ;;
    esac
  fi
  pass "actual core installation checks archive, native verification and private streams: $mode"
)
for core_mode in ready download-failure checksum-failure version-failure wrong-version download-warning checksum-warning checksum-stdout; do
  case_core_archive "$core_mode"
done

expect_refusal() { # <label> <diagnostic-fragment> <function> [args...]
  local label="$1" needle="$2" output status
  shift 2
  set +e
  output="$("$@" 2>&1)"
  status=$?
  set -e
  [ "$status" -ne 0 ] || fail "$label was accepted"
  [[ "$output" == *"$needle"* ]] \
    || fail "$label returned the wrong diagnostic: $output"
  pass "$label refuses by name"
}

prepare_remote() { # <case-label>
  local label="$1"
  TMP="$SCRATCH/$label/local"
  REMOTE="$SCRATCH/$label/remote"
  FAKE_BIN="$SCRATCH/$label/bin"
  SSH_LOG="$SCRATCH/$label/ssh.log"
  mkdir -p "$TMP" "$REMOTE/var/www/html/wp-content/plugins" \
    "$REMOTE/var/www/html/wp-content/themes" \
    "$REMOTE/home/wprism/site" "$REMOTE/home/wprism/recovery-fixture" \
    "$FAKE_BIN"
  REMOTE="$(cd "$REMOTE" && pwd -P)"
  : >"$SSH_LOG"
  : >"$TMP/ssh_config"
  export TMP REMOTE FAKE_BIN SSH_LOG
  export FAKE_ACTIVE_JSON='["alpha/alpha.php"]'
  export FAKE_STYLESHEET='theme-a' FAKE_TEMPLATE='theme-a'
  export FAKE_AUTHORITY_GENERATION='7'
  unset FAKE_SSH_MODE FAKE_CP_MODE WPRISM_ESCAPE_THEME FAKE_CLEANUP_FAILURE FAKE_SCP_FAILURE \
    WPRISM_TEST_TOMBSTONE_COLLISION 2>/dev/null || true

  mkdir -p "$REMOTE/var/www/html/wp-content/plugins/alpha" \
    "$REMOTE/var/www/html/wp-content/plugins/beta/sub" \
    "$REMOTE/var/www/html/wp-content/themes/theme-a" \
    "$REMOTE/var/www/html/wp-content/themes/theme-b"
  printf '<?php\n' >"$REMOTE/var/www/html/wp-content/plugins/alpha/alpha.php"
  printf '<?php\n' >"$REMOTE/var/www/html/wp-content/plugins/beta/sub/main.php"
  printf 'theme a\n' >"$REMOTE/var/www/html/wp-content/themes/theme-a/style.css"
  printf 'theme b\n' >"$REMOTE/var/www/html/wp-content/themes/theme-b/style.css"

  cat >"$FAKE_BIN/wp" <<'FAKE_WP'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  'option get stylesheet') printf '%s\n' "${FAKE_STYLESHEET:?}" ;;
  'option get template') printf '%s\n' "${FAKE_TEMPLATE:?}" ;;
  *) printf 'unexpected fake wp invocation: %s\n' "$*" >&2; exit 91 ;;
esac
FAKE_WP
  cat >"$FAKE_BIN/stat" <<'FAKE_STAT'
#!/usr/bin/env bash
set -euo pipefail
[ "$#" -eq 3 ] && [ "$1" = -c ] && [ "$2" = %a ] || exit 94
if [ "$(/usr/bin/uname -s)" = Darwin ]; then
  /usr/bin/stat -f '%Lp' "$3"
else
  /usr/bin/stat -c '%a' "$3"
fi
FAKE_STAT
  chmod +x "$FAKE_BIN/wp" "$FAKE_BIN/stat"
}

translated_remote_command() { # <command>
  local command="$1"
  command="${command//\/home\/wprism/$REMOTE/home/wprism}"
  command="${command//\/var\/www\/html/$REMOTE/var/www/html}"
  printf '%s' "$command"
}

ssh_fixture() { # deterministic replacement for the live harness transport
  local command="$1" translated status
  case "$command" in
    'cd /var/www/html && wp option get active_plugins --format=json')
      printf 'ACTIVE\n' >>"$SSH_LOG"
      printf '%s\n' "$FAKE_ACTIVE_JSON"
      return 0
      ;;
    *'rollback-control.php authority-status'*)
      printf 'AUTHORITY\n' >>"$SSH_LOG"
      printf '{"generation":%s}\n' "$FAKE_AUTHORITY_GENERATION"
      return 0
      ;;
    *'wp eval-file /home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php'*)
      printf 'EVAL\n' >>"$SSH_LOG"
      WPRISM_TEST_REMOTE_HOME="$REMOTE/home/wprism" \
        WPRISM_TEST_TOMBSTONE_UUID="${WPRISM_TEST_TOMBSTONE_UUID:?}" \
        WPRISM_TEST_TOMBSTONE_SLUG="${WPRISM_TEST_TOMBSTONE_SLUG:?}" \
        WPRISM_TEST_TOMBSTONE_COLLISION="${WPRISM_TEST_TOMBSTONE_COLLISION:-}" \
        WPRISM_TOMBSTONE_POST_TYPE=post \
        WPRISM_TOMBSTONE_POST_SLUG="$WPRISM_TEST_TOMBSTONE_SLUG" \
        "$PHP_BIN" "$TOMBSTONE_RUNNER" \
          "$REMOTE/home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php"
      return $?
      ;;
    'rm -f /home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php')
      printf 'CLEANUP\n' >>"$SSH_LOG"
      [ "${FAKE_CLEANUP_FAILURE:-}" != 1 ] || return 93
      rm -f -- "$REMOTE/home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php"
      return 0
      ;;
  esac

  printf 'REMOTE\n' >>"$SSH_LOG"
  translated="$(translated_remote_command "$command")"
  if PATH="$FAKE_BIN:$ORIGINAL_PATH" bash -c "$translated"; then
    status=0
  else
    status=$?
  fi
  return "$status"
}

scp() { # the helper's upload surface; ordinary copy only for the PHP fixture
  printf 'SCP\n' >>"$SSH_LOG"
  [ "${FAKE_SCP_FAILURE:-}" != 1 ] || return 94
  if [ "${FAKE_SSH_MODE:-}" = tombstone ]; then
    [ "$#" -eq 4 ] || return 92
    /bin/cp "$3" \
      "$REMOTE/home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php"
  elif [ "${FAKE_SSH_MODE:-}" = artifact ]; then
    [ "$#" -eq 4 ] || return 92
    local destination="${4#wprism-adopt-fixture:}"
    [[ "$destination" == /home/wprism/recovery-fixture/plugin-fixture-1.0-*.zip ]] || return 92
    /bin/cp "$3" "$REMOTE$destination"
  fi
}

install_adversarial_cp() { # <symlink|extra>
  FAKE_CP_MODE="$1"
  WPRISM_ESCAPE_THEME="$REMOTE/escaped-theme"
  export FAKE_CP_MODE WPRISM_ESCAPE_THEME
  mkdir -p "$WPRISM_ESCAPE_THEME"
  cat >"$FAKE_BIN/cp" <<'FAKE_CP'
#!/usr/bin/env bash
set -euo pipefail
/bin/cp "$@"
if [ "$#" -eq 3 ] && [ "$1" = -a ]; then
  case "$2" in
    */wp-content/themes/theme-a)
      case "${FAKE_CP_MODE:-}" in
        symlink)
          rm -rf -- "$3/theme-a"
          ln -s "$WPRISM_ESCAPE_THEME" "$3/theme-a"
          ;;
        extra)
          mkdir -p "$3/unexpected-theme"
          ;;
      esac
      ;;
  esac
fi
FAKE_CP
  chmod +x "$FAKE_BIN/cp"
}

install_adversarial_mkdir() {
  WPRISM_THEME_DESTINATION_LITERAL="$REMOTE/home/wprism/site/code/wp-content/themes"
  WPRISM_ESCAPE_THEME_ROOT="$REMOTE/escaped-theme-root"
  export WPRISM_THEME_DESTINATION_LITERAL WPRISM_ESCAPE_THEME_ROOT
  cat >"$FAKE_BIN/mkdir" <<'FAKE_MKDIR'
#!/usr/bin/env bash
set -euo pipefail
/bin/mkdir "$@"
for path in "$@"; do
  [ "$path" = "$WPRISM_THEME_DESTINATION_LITERAL" ] || continue
  /bin/mv "$path" "$WPRISM_ESCAPE_THEME_ROOT"
  /bin/ln -s "$WPRISM_ESCAPE_THEME_ROOT" "$path"
done
FAKE_MKDIR
  chmod +x "$FAKE_BIN/mkdir"
}

install_generation_mkdir_collision() { # <translated-directory>
  WPRISM_MKDIR_COLLISION_TARGET="$1"
  export WPRISM_MKDIR_COLLISION_TARGET
  cat >"$FAKE_BIN/mkdir" <<'FAKE_MKDIR_COLLISION'
#!/usr/bin/env bash
set -euo pipefail
for path in "$@"; do
  [ "$path" = "$WPRISM_MKDIR_COLLISION_TARGET" ] || continue
  /bin/mkdir -p -- "$path"
  printf 'occupied-after-check\n' >"$path/sentinel"
done
exec /bin/mkdir "$@"
FAKE_MKDIR_COLLISION
  chmod +x "$FAKE_BIN/mkdir"
}

install_generation_current_collision() { # <translated-code-current>
  WPRISM_CURRENT_COLLISION_TARGET="$1"
  export WPRISM_CURRENT_COLLISION_TARGET
  cat >"$FAKE_BIN/ln" <<'FAKE_LN_COLLISION'
#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -eq 2 ] && [ "$2" = "$WPRISM_CURRENT_COLLISION_TARGET" ]; then
  printf 'occupied-after-check\n' >"$2"
fi
exec /bin/ln "$@"
FAKE_LN_COLLISION
  chmod +x "$FAKE_BIN/ln"
}

case_inventory_distinct() {
  prepare_remote inventory-distinct
  export FAKE_ACTIVE_JSON='["beta/sub/main.php","alpha/alpha.php"]'
  export FAKE_STYLESHEET='theme-a' FAKE_TEMPLATE='theme-b'
  wprism_ssh_stage_code_inventory alpha beta
}

case_inventory_distinct
[ "$(find "$SCRATCH/inventory-distinct/remote/home/wprism/site/code/wp-content/plugins" \
    -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')" -eq 2 ] \
  || fail 'two exact plugin roots were not staged'
[ "$(find "$SCRATCH/inventory-distinct/remote/home/wprism/site/code/wp-content/themes" \
    -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')" -eq 2 ] \
  || fail 'two distinct exact theme roots were not staged'
pass 'two active plugins and two distinct themes stage as exact bounded roots'

case_inventory_shared_theme() {
  prepare_remote inventory-shared-theme
  export FAKE_STYLESHEET='theme-a' FAKE_TEMPLATE='theme-a'
  wprism_ssh_stage_code_inventory alpha
}

case_inventory_shared_theme
[ "$(find "$SCRATCH/inventory-shared-theme/remote/home/wprism/site/code/wp-content/themes" \
    -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')" -eq 1 ] \
  || fail 'one stylesheet/template identity did not stage exactly one theme root'
pass 'a shared stylesheet/template identity is counted once'

prepare_remote inventory-core-empty
FAKE_ACTIVE_JSON='[]'
wprism_ssh_stage_code_inventory
[ "$(find "$REMOTE/home/wprism/site/code/wp-content/plugins" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')" -eq 0 ] \
  || fail 'the core-only inventory staged a plugin root'
[ -f "$REMOTE/home/wprism/site/code/wp-content/themes/theme-a/style.css" ] \
  || fail 'the core-only inventory omitted its active theme'
pass 'an exact empty active-plugin roster stages zero plugin roots and its real active theme'

case_inventory_unexpected_active() {
  prepare_remote inventory-core-unexpected
  wprism_ssh_stage_code_inventory
}
expect_refusal 'core inventory with an unexpected active plugin' \
  'SSH code inventory arguments do not equal the exact active plugin-directory set' \
  case_inventory_unexpected_active
[ ! -e "$SCRATCH/inventory-core-unexpected/remote/home/wprism/site/code" ] \
  || fail 'an unexpected active plugin reached code staging'

case_bad_theme() { # <label> <theme>
  prepare_remote "$1"
  export FAKE_STYLESHEET="$2" FAKE_TEMPLATE='theme-a'
  wprism_ssh_stage_code_inventory alpha
}

for theme_case in 'theme-dot|.' 'theme-dotdot|..' 'theme-malformed|bad/theme'; do
  label="${theme_case%%|*}"
  theme="${theme_case#*|}"
  expect_refusal "$label theme identity" \
    'SSH code inventory could not stage the exact active plugin/theme roots' \
    case_bad_theme "$label" "$theme"
  [ ! -e "$SCRATCH/$label/remote/home/wprism/site/code" ] \
    || fail "$label mutated the staged code root before refusing"
done

case_linked_theme_source() {
  prepare_remote theme-linked-source
  mkdir -p "$REMOTE/outside-theme"
  ln -s "$REMOTE/outside-theme" "$REMOTE/var/www/html/wp-content/themes/linked-theme"
  export FAKE_STYLESHEET='linked-theme' FAKE_TEMPLATE='theme-a'
  wprism_ssh_stage_code_inventory alpha
}

expect_refusal 'linked theme source' \
  'SSH code inventory could not stage the exact active plugin/theme roots' \
  case_linked_theme_source
[ ! -e "$SCRATCH/theme-linked-source/remote/home/wprism/site/code" ] \
  || fail 'a theme source outside the exact theme root mutated the destination'

case_linked_theme_source_root() {
  prepare_remote theme-linked-source-root
  mv "$REMOTE/var/www/html/wp-content/themes" "$REMOTE/theme-source-root"
  ln -s "$REMOTE/theme-source-root" "$REMOTE/var/www/html/wp-content/themes"
  wprism_ssh_stage_code_inventory alpha
}

expect_refusal 'linked theme source root' \
  'SSH code inventory could not stage the exact active plugin/theme roots' \
  case_linked_theme_source_root
[ ! -e "$SCRATCH/theme-linked-source-root/remote/home/wprism/site/code" ] \
  || fail 'a noncanonical theme source root mutated the destination'

case_partial_active_set() {
  prepare_remote inventory-partial-active
  export FAKE_ACTIVE_JSON='["alpha/alpha.php"]'
  wprism_ssh_stage_code_inventory alpha beta
}

expect_refusal 'partial active-plugin set' \
  'SSH code inventory arguments do not equal the exact active plugin-directory set' \
  case_partial_active_set
[ ! -e "$SCRATCH/inventory-partial-active/remote/home/wprism/site/code" ] \
  || fail 'a partial active-plugin set mutated the staged code root'
[ "$(grep -c '^ACTIVE$' "$SCRATCH/inventory-partial-active/ssh.log")" -eq 1 ] \
  || fail 'partial active-set refusal crossed the preflight transport boundary'

case_linked_theme_destination() {
  prepare_remote theme-linked-destination
  install_adversarial_cp symlink
  wprism_ssh_stage_code_inventory alpha
}

expect_refusal 'theme destination escaping its exact root' \
  'SSH code inventory could not stage the exact active plugin/theme roots' \
  case_linked_theme_destination

case_linked_theme_destination_root() {
  prepare_remote theme-linked-destination-root
  install_adversarial_mkdir
  wprism_ssh_stage_code_inventory alpha
}

expect_refusal 'linked theme destination root' \
  'SSH code inventory could not stage the exact active plugin/theme roots' \
  case_linked_theme_destination_root

case_extra_staged_theme() {
  prepare_remote theme-extra-destination
  install_adversarial_cp extra
  wprism_ssh_stage_code_inventory alpha
}

expect_refusal 'unexpected extra staged theme' \
  'SSH code inventory could not stage the exact active plugin/theme roots' \
  case_extra_staged_theme
pass 'physical destination containment and the exact unique-theme count are enforced after copy'

artifact_library_jq() {
  local document
  document=$(jq -nc --arg role "${FAKE_ARTIFACT_ROLE:-certified-boundary}" \
    --arg digest "${FAKE_ARTIFACT_DIGEST:-$(printf '%064d' 0)}" \
    '{plugins:{fixture:{"1.0":{url:"https://fixture.invalid/plugin.zip",sha256:$digest,role:$role}}}}')
  jq "$@" <<<"$document"
}

curl() {
  local output=''
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --output) output="$2"; shift 2 ;;
      *) shift ;;
    esac
  done
  [ -n "$output" ] || return 93
  printf 'DOWNLOAD\n' >>"$SSH_LOG"
  printf '%s\n' "${FAKE_ARTIFACT_BYTES:-bytes that do not match the locked digest}" >"$output"
}

case_digest_mismatch() {
  prepare_remote artifact-digest-mismatch
  wprism_ssh_install_locked_plugin fixture 1.0 certified-boundary activate
}

expect_refusal 'locked artifact digest mismatch' \
  'locked artifact digest mismatch for fixture 1.0' \
  case_digest_mismatch
! grep -q '^SCP$' "$SCRATCH/artifact-digest-mismatch/ssh.log" \
  || fail 'a digest-mismatched artifact crossed the upload boundary'
pass 'digest verification precedes upload and remote plugin mutation'

case_artifact_role_mismatch() {
  prepare_remote artifact-role-mismatch
  wprism_ssh_install_locked_plugin fixture 1.0 exercise-fixture inactive
}
expect_refusal 'artifact role mismatch' \
  'no locked artifact-library entry with role exercise-fixture exists for fixture 1.0' \
  case_artifact_role_mismatch
[ ! -s "$SCRATCH/artifact-role-mismatch/ssh.log" ] || fail 'role mismatch crossed the download boundary'

prepare_remote artifact-invalid-arguments
expect_refusal 'unknown artifact role' "locked plugin evidence role 'invented' is unknown" \
  wprism_ssh_install_locked_plugin fixture 1.0 invented inactive
expect_refusal 'unknown activation choice' "locked plugin activation mode 'automatic' is unknown" \
  wprism_ssh_install_locked_plugin fixture 1.0 exercise-fixture automatic
expect_refusal 'missing artifact intent' 'locked plugin install requires artifact slug, version, evidence role and activation mode' \
  wprism_ssh_install_locked_plugin fixture 1.0
[ ! -s "$SSH_LOG" ] || fail 'invalid arguments crossed the download boundary'

prepare_artifact_install() { # <case-label> <evidence-role>
  prepare_remote "$1"
  export FAKE_SSH_MODE=artifact FAKE_ARTIFACT_ROLE="$2" FAKE_ARTIFACT_BYTES='locked plugin fixture'
  FAKE_ARTIFACT_DIGEST=$(printf '%s\n' "$FAKE_ARTIFACT_BYTES" | "$PHP_BIN" -r 'echo hash_file("sha256", "php://stdin");')
  export FAKE_ARTIFACT_DIGEST
  unset FAKE_WRONG_ACTIVATION FAKE_OBSERVED_VERSION
  cat >"$FAKE_BIN/wp" <<'ARTIFACT_WP'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  'plugin is-installed fixture') test -f "$REMOTE/installed" ;;
  'plugin get fixture --field=version') test -f "$REMOTE/installed"; printf '%s\n' "${FAKE_OBSERVED_VERSION:-1.0}" ;;
  'plugin get fixture --field=status')
    if [ "${FAKE_STATUS_FAILURE:-}" = 1 ]; then exit 2; fi
    if [ -f "$REMOTE/active" ]; then printf 'active\n'; else printf 'inactive\n'; fi ;;
  *)
    [ "$#" -ge 4 ] && [ "$1" = plugin ] && [ "$2" = install ] && [ -f "$3" ] || exit 91
    printf 'INSTALL:%s\n' "$*" >>"$SSH_LOG"
    touch "$REMOTE/installed"
    if [ "${4:-}" = --activate ] || [ "${FAKE_WRONG_ACTIVATION:-}" = 1 ]; then touch "$REMOTE/active"; fi
    ;;
esac
ARTIFACT_WP
  chmod +x "$FAKE_BIN/wp"
}

for artifact_role in certified-boundary exercise-fixture refusal-fixture; do
  for activation in activate inactive; do
    prepare_artifact_install "artifact-$artifact_role-$activation" "$artifact_role"
    wprism_ssh_install_locked_plugin fixture 1.0 "$artifact_role" "$activation"
    [ -f "$REMOTE/installed" ] || fail 'valid artifact never reached native installation'
    if [ "$activation" = activate ]; then
      [ -f "$REMOTE/active" ] || fail 'explicit activation was lost'
    else
      [ ! -e "$REMOTE/active" ] || fail 'inactive installation ran activation'
    fi
    [ ! -e "$REMOTE/home/wprism/recovery-fixture/plugin-fixture-1.0-$FAKE_ARTIFACT_DIGEST.zip" ] \
      || fail 'remote install left its uploaded archive behind'
    [ "$(grep -c '^DOWNLOAD$' "$SSH_LOG")" -eq 1 ] && [ "$(grep -c '^SCP$' "$SSH_LOG")" -eq 1 ] \
      || fail 'valid install did not traverse both download and upload checks exactly once'
    pass "locked $artifact_role artifact honors explicit $activation and removes its remote archive"
  done
done

case_artifact_wrong_activation() {
  prepare_artifact_install artifact-wrong-activation exercise-fixture
  export FAKE_WRONG_ACTIVATION=1
  wprism_ssh_install_locked_plugin fixture 1.0 exercise-fixture inactive
}
expect_refusal 'native activation contradicting inactive intent' 'locked artifact installation failed for fixture 1.0' \
  case_artifact_wrong_activation
case_artifact_wrong_version() {
  prepare_artifact_install artifact-wrong-version refusal-fixture
  export FAKE_OBSERVED_VERSION=2.0
  wprism_ssh_install_locked_plugin fixture 1.0 refusal-fixture inactive
}
expect_refusal 'native version contradicting the artifact request' 'locked artifact installation failed for fixture 1.0' \
  case_artifact_wrong_version
case_artifact_status_failure() {
  prepare_artifact_install artifact-status-failure exercise-fixture
  export FAKE_STATUS_FAILURE=1
  wprism_ssh_install_locked_plugin fixture 1.0 exercise-fixture inactive
}
expect_refusal 'failed status observation is not inactive evidence' 'locked artifact installation failed for fixture 1.0' \
  case_artifact_status_failure
unset FAKE_SSH_MODE FAKE_ARTIFACT_ROLE FAKE_ARTIFACT_BYTES FAKE_ARTIFACT_DIGEST

case_generation_overflow() {
  prepare_remote generation-overflow
  export FAKE_AUTHORITY_GENERATION='9223372036854775806'
  mkdir -p "$REMOTE/home/wprism/site/code/wp-content"
  wprism_ssh_stage_generation_releases 2
}

expect_refusal 'generation overflow' \
  "SSH release staging generation '9223372036854775806' is not a bounded canonical integer" \
  case_generation_overflow
[ "$(grep -c '^AUTHORITY$' "$SCRATCH/generation-overflow/ssh.log")" -eq 1 ] \
  || fail 'overflow did not stop immediately after authority observation'
! grep -q '^REMOTE$' "$SCRATCH/generation-overflow/ssh.log" \
  || fail 'overflow reached release filesystem mutation'

case_generation_root_collision() {
  prepare_remote generation-root-collision
  export FAKE_AUTHORITY_GENERATION='10'
  install_generation_mkdir_collision "$REMOTE/home/wprism/code-releases"
  mkdir -p "$REMOTE/home/wprism/site/code/wp-content"
  printf 'code\n' >"$REMOTE/home/wprism/site/code/wp-content/index.php"
  wprism_ssh_stage_generation_releases 1
}

expect_refusal 'release-root collision after absence observation' \
  'SSH release staging could not publish prior/desired immutable generations' \
  case_generation_root_collision
[ "$(cat "$SCRATCH/generation-root-collision/remote/home/wprism/code-releases/sentinel")" = occupied-after-check ] \
  || fail 'release-root collision fixture was not preserved'
[ ! -e "$SCRATCH/generation-root-collision/remote/home/wprism/code-releases/release-prior" ] \
  || fail 'release-root collision was entered after the exclusive create failed'

case_generation_current_collision() {
  prepare_remote generation-current-collision
  export FAKE_AUTHORITY_GENERATION='10'
  install_generation_current_collision "$REMOTE/home/wprism/code-current"
  mkdir -p "$REMOTE/home/wprism/site/code/wp-content"
  printf 'code\n' >"$REMOTE/home/wprism/site/code/wp-content/index.php"
  wprism_ssh_stage_generation_releases 1
}

expect_refusal 'code-current collision after absence observation' \
  'SSH release staging could not publish prior/desired immutable generations' \
  case_generation_current_collision
[ "$(cat "$SCRATCH/generation-current-collision/remote/home/wprism/code-current")" = occupied-after-check ] \
  || fail 'code-current collision was replaced'
[ ! -e "$SCRATCH/generation-current-collision/remote/home/wprism/code-releases/.code-current.pending" ] \
  || fail 'failed code-current publication left its private pending link behind'

case_generation_retry_collision() {
  prepare_remote generation-retry-collision
  export FAKE_AUTHORITY_GENERATION='10'
  install_generation_mkdir_collision \
    "$REMOTE/home/wprism/code-releases/release-desired-12"
  mkdir -p "$REMOTE/home/wprism/site/code/wp-content"
  printf 'code\n' >"$REMOTE/home/wprism/site/code/wp-content/index.php"
  wprism_ssh_stage_generation_releases 2
}

expect_refusal 'computed retry-generation collision after absence observation' \
  'SSH release staging could not publish the retry immutable generation' \
  case_generation_retry_collision
[ -f "$SCRATCH/generation-retry-collision/remote/home/wprism/code-releases/release-desired-12/sentinel" ] \
  || fail 'retry-generation collision fixture was not present at the refusal boundary'
[ ! -e "$SCRATCH/generation-retry-collision/remote/home/wprism/code-releases/release-desired-12/wp-content" ] \
  || fail 'retry-generation collision was overwritten'
pass 'generation roots and pointer files use exclusive publication at every observed-absent boundary'

prepare_remote generation-three
mkdir -p "$REMOTE/home/wprism/site/code/wp-content/themes/theme-a"
printf 'immutable theme\n' >"$REMOTE/home/wprism/site/code/wp-content/themes/theme-a/style.css"
wprism_ssh_stage_generation_releases 3
for generation in 8 9 10; do
  cmp "$REMOTE/home/wprism/site/code/wp-content/themes/theme-a/style.css" \
    "$REMOTE/home/wprism/code-releases/release-desired-$generation/wp-content/themes/theme-a/style.css" \
    || fail 'three-generation release staging did not preserve exact immutable code bytes'
done
case_generation_third_collision() {
  prepare_remote generation-third-collision
  mkdir -p "$REMOTE/home/wprism/site/code/wp-content"
  install_generation_mkdir_collision "$REMOTE/home/wprism/code-releases/release-desired-10"
  wprism_ssh_stage_generation_releases 3
}
expect_refusal 'third desired-generation collision' \
  'SSH release staging could not publish the retry immutable generation' case_generation_third_collision
[ "$(cat "$SCRATCH/generation-third-collision/remote/home/wprism/code-releases/release-desired-10/sentinel")" = occupied-after-check ] \
  || fail 'third desired-generation staging replaced a collision'
[ ! -e "$SCRATCH/generation-third-collision/remote/home/wprism/code-releases/release-desired-10/wp-content" ] \
  || fail 'third desired-generation staging entered the occupied directory'
case_generation_third_overflow() {
  prepare_remote generation-third-overflow
  FAKE_AUTHORITY_GENERATION='9223372036854775805'
  wprism_ssh_stage_generation_releases 3
}
expect_refusal 'three-generation authority overflow' \
  'is not a bounded canonical integer' case_generation_third_overflow
! grep -q '^REMOTE$' "$SCRATCH/generation-third-overflow/ssh.log" \
  || fail 'three-generation overflow reached filesystem mutation'
pass 'three intended attempts receive consecutive immutable code releases with exclusive third-generation publication'

TOMBSTONE_RUNNER="$SCRATCH/tombstone-runner.php"
cat >"$TOMBSTONE_RUNNER" <<'PHP'
<?php
namespace WPrism {

final class Policy
{
    public static function load(string $root): self
    {
        return new self();
    }
}

final class TombstoneCompiledFixture
{
    public function tree(): array
    {
        return [(string) getenv('WPRISM_TEST_TOMBSTONE_UUID') => ['hash' => str_repeat('a', 64)]];
    }

    public function revision_hash(): string
    {
        return str_repeat('b', 64);
    }
}

final class RepositoryCompiler
{
    public static function compile(string $root, Policy $policy): TombstoneCompiledFixture
    {
        return new TombstoneCompiledFixture();
    }
}

final class Deletion
{
    public static function capture_tombstones(
        TombstoneCompiledFixture $compiled,
        array $existing,
        Policy $policy,
        array $uuids
    ): array {
        $uuid = (string) $uuids[0];
        $slug = (string) getenv('WPRISM_TEST_TOMBSTONE_SLUG');
        return [[
            'uuid' => $uuid,
            'type' => 'deletion',
            'path' => "deletions/$uuid.json",
            'content' => json_encode([
                'expected_hash' => str_repeat('a', 64),
                'expected_revision' => str_repeat('b', 64),
                'format' => 'wprism-deletion/v1',
                'kind' => 'post',
                'source_path' => "posts/post/$uuid--$slug.md",
                'type' => 'post',
                'uuid' => $uuid,
            ], JSON_THROW_ON_ERROR),
        ]];
    }
}

final class Canon
{
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
}

namespace {
    $fixture = (string) file_get_contents($argv[1]);
    $remoteHome = (string) getenv('WPRISM_TEST_REMOTE_HOME');
    $translated = str_replace('/home/wprism', $remoteHome, $fixture);
    $collision = (string) getenv('WPRISM_TEST_TOMBSTONE_COLLISION');
    if ($collision === 'final') {
        $needle = 'if (!@link($pending, $final)) {';
        $replacement = 'file_put_contents($final, "occupied-final-after-check\\n");'
            . "\n" . $needle;
    } elseif ($collision === 'present') {
        $needle = 'if (!@link($match[\'path\'], $present)) {';
        $replacement = 'file_put_contents($present, "occupied-present-after-check\\n");'
            . "\n" . $needle;
    } else {
        $needle = '';
        $replacement = '';
    }
    if ($needle !== '') {
        $translated = str_replace($needle, $replacement, $translated, $replacementCount);
        if ($replacementCount !== 1) {
            throw new RuntimeException('tombstone collision injection boundary was not found exactly once');
        }
    }
    $translatedPath = $remoteHome . '/recovery-fixture/tombstone-translated.php';
    file_put_contents($translatedPath, $translated);
    require $translatedPath;
}
PHP

prepare_tombstone_post() { # <case-label>
  prepare_remote "$1"
  FAKE_SSH_MODE=tombstone
  WPRISM_TEST_TOMBSTONE_UUID='12345678-1234-1234-1234-123456789abc'
  WPRISM_TEST_TOMBSTONE_SLUG='collision-proof'
  export FAKE_SSH_MODE WPRISM_TEST_TOMBSTONE_UUID WPRISM_TEST_TOMBSTONE_SLUG
  mkdir -p "$REMOTE/home/wprism/site/state/posts/post" \
    "$REMOTE/home/wprism/site/state/deletions"
  printf 'captured post\n' \
    >"$REMOTE/home/wprism/site/state/posts/post/$WPRISM_TEST_TOMBSTONE_UUID--$WPRISM_TEST_TOMBSTONE_SLUG.md"
}

case_tombstone_destination_collision() {
  prepare_tombstone_post tombstone-destination-collision
  printf 'occupied\n' \
    >"$REMOTE/home/wprism/site/state/deletions/$WPRISM_TEST_TOMBSTONE_UUID.json"
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}

expect_refusal 'tombstone destination collision' \
  'SSH tombstone destination already exists' \
  case_tombstone_destination_collision
TOMBSTONE_LOG="$SCRATCH/tombstone-destination-collision/ssh.log"
eval_line="$(grep -n '^EVAL$' "$TOMBSTONE_LOG" | cut -d: -f1)"
cleanup_line="$(grep -n '^CLEANUP$' "$TOMBSTONE_LOG" | cut -d: -f1)"
[ -n "$eval_line" ] && [ -n "$cleanup_line" ] && [ "$eval_line" -lt "$cleanup_line" ] \
  || fail 'remote tombstone fixture cleanup did not follow the failed publication attempt'
[ ! -e "$SCRATCH/tombstone-destination-collision/remote/home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'failed tombstone publication left its executable fixture installed'
[ "$(cat "$SCRATCH/tombstone-destination-collision/remote/home/wprism/site/state/deletions/12345678-1234-1234-1234-123456789abc.json")" = occupied ] \
  || fail 'tombstone destination collision changed the occupied destination'
[ -f "$SCRATCH/tombstone-destination-collision/remote/home/wprism/site/state/posts/post/12345678-1234-1234-1234-123456789abc--collision-proof.md" ] \
  || fail 'tombstone destination collision retired the live source'
pass 'destination collision preserves both sides and failed publication still removes its remote executable'
[ ! -e "$SCRATCH/tombstone-destination-collision/local/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'refused publication retained its owned local executable'

case_tombstone_success() {
  prepare_tombstone_post tombstone-success
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}

TOMBSTONE_SUCCESS_UUID="$(case_tombstone_success)"
[ "$TOMBSTONE_SUCCESS_UUID" = '12345678-1234-1234-1234-123456789abc' ] \
  || fail 'successful tombstone publication did not return the captured UUID'
TOMBSTONE_SUCCESS_ROOT="$SCRATCH/tombstone-success/remote/home/wprism"
[ "$(jq -r '.format' "$TOMBSTONE_SUCCESS_ROOT/site/state/deletions/$TOMBSTONE_SUCCESS_UUID.json")" = wprism-deletion/v1 ] \
  || fail 'successful tombstone publication did not preserve the engine record'
[ ! -e "$TOMBSTONE_SUCCESS_ROOT/site/state/posts/post/$TOMBSTONE_SUCCESS_UUID--collision-proof.md" ] \
  || fail 'successful tombstone publication left the live source in place'
[ "$(cat "$TOMBSTONE_SUCCESS_ROOT/recovery-fixture/$TOMBSTONE_SUCCESS_UUID--collision-proof.md.present")" = 'captured post' ] \
  || fail 'successful tombstone publication did not retire the exact source bytes'
[ "$(find "$TOMBSTONE_SUCCESS_ROOT/site/state/deletions" -name '.*.pending-*' -print | wc -l | tr -d ' ')" -eq 0 ] \
  || fail 'successful tombstone publication left a pending file'
[ ! -e "$TOMBSTONE_SUCCESS_ROOT/recovery-fixture/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'successful tombstone publication left its remote executable installed'
pass 'successful tombstone publication atomically links each destination before removing its source name'
[ ! -e "$SCRATCH/tombstone-success/local/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'successful publication retained its owned local executable'

prepare_tombstone_post tombstone-repeated
wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG" >/dev/null
WPRISM_TEST_TOMBSTONE_UUID='12345678-1234-1234-1234-123456789abd'
WPRISM_TEST_TOMBSTONE_SLUG='second-proof'
printf 'second captured post\n' \
  >"$REMOTE/home/wprism/site/state/posts/post/$WPRISM_TEST_TOMBSTONE_UUID--$WPRISM_TEST_TOMBSTONE_SLUG.md"
[ "$(wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG")" = "$WPRISM_TEST_TOMBSTONE_UUID" ] \
  || fail 'the second independent tombstone could not reuse its helper'
[ "$(find "$REMOTE/home/wprism/site/state/deletions" -name '*.json' -print | wc -l | tr -d ' ')" -eq 2 ] \
  || fail 'repeated tombstones did not retain two independent engine records'
pass 'repeated engine tombstone publications release their owned local and remote executables'

case_tombstone_local_collision() {
  prepare_tombstone_post tombstone-local-collision
  printf 'not owned\n' >"$TMP/wprism-ssh-publish-post-tombstone.php"
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}
expect_refusal 'pre-existing local tombstone executable' \
  'SSH tombstone fixture path already exists' case_tombstone_local_collision
[ "$(cat "$SCRATCH/tombstone-local-collision/local/wprism-ssh-publish-post-tombstone.php")" = 'not owned' ] \
  || fail 'the helper removed an unowned local collision'

case_tombstone_transport_failure() {
  prepare_tombstone_post "tombstone-$1-failure"
  case "$1" in
    upload) FAKE_SCP_FAILURE=1 ;;
    cleanup) FAKE_CLEANUP_FAILURE=1 ;;
  esac
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}
expect_refusal 'tombstone upload failure' 'SSH tombstone fixture upload failed' \
  case_tombstone_transport_failure upload
expect_refusal 'tombstone remote cleanup failure' 'SSH tombstone fixture cleanup failed' \
  case_tombstone_transport_failure cleanup
for label in upload cleanup; do
  [ ! -e "$SCRATCH/tombstone-$label-failure/local/wprism-ssh-publish-post-tombstone.php" ] \
    || fail "$label failure retained the helper-owned local executable"
done
pass 'transport failures release only owned local scratch and never turn failed remote cleanup green'

case_tombstone_final_race_collision() {
  prepare_tombstone_post tombstone-final-race-collision
  WPRISM_TEST_TOMBSTONE_COLLISION=final
  export WPRISM_TEST_TOMBSTONE_COLLISION
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}

expect_refusal 'tombstone collision after final absence observation' \
  'SSH tombstone could not be published without replacement' \
  case_tombstone_final_race_collision
TOMBSTONE_FINAL_RACE_ROOT="$SCRATCH/tombstone-final-race-collision/remote/home/wprism"
[ "$(cat "$TOMBSTONE_FINAL_RACE_ROOT/site/state/deletions/12345678-1234-1234-1234-123456789abc.json")" = occupied-final-after-check ] \
  || fail 'tombstone publication replaced a destination created after its absence check'
[ -f "$TOMBSTONE_FINAL_RACE_ROOT/site/state/posts/post/12345678-1234-1234-1234-123456789abc--collision-proof.md" ] \
  || fail 'failed no-replace tombstone publication retired the live source'
[ "$(find "$TOMBSTONE_FINAL_RACE_ROOT/site/state/deletions" -name '.*.pending-*' -print | wc -l | tr -d ' ')" -eq 0 ] \
  || fail 'failed no-replace tombstone publication left a pending file'
[ ! -e "$TOMBSTONE_FINAL_RACE_ROOT/recovery-fixture/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'final-race refusal left its remote executable installed'

case_tombstone_present_race_collision() {
  prepare_tombstone_post tombstone-present-race-collision
  WPRISM_TEST_TOMBSTONE_COLLISION=present
  export WPRISM_TEST_TOMBSTONE_COLLISION
  wprism_ssh_publish_post_tombstone post "$WPRISM_TEST_TOMBSTONE_SLUG"
}

expect_refusal 'tombstone retirement collision after present absence observation' \
  'SSH tombstone source could not be retired without replacement' \
  case_tombstone_present_race_collision
TOMBSTONE_PRESENT_RACE_ROOT="$SCRATCH/tombstone-present-race-collision/remote/home/wprism"
[ "$(cat "$TOMBSTONE_PRESENT_RACE_ROOT/recovery-fixture/12345678-1234-1234-1234-123456789abc--collision-proof.md.present")" = occupied-present-after-check ] \
  || fail 'tombstone retirement replaced a destination created after its absence check'
[ -f "$TOMBSTONE_PRESENT_RACE_ROOT/site/state/posts/post/12345678-1234-1234-1234-123456789abc--collision-proof.md" ] \
  || fail 'failed no-replace tombstone retirement removed the live source'
[ "$(jq -r '.format' "$TOMBSTONE_PRESENT_RACE_ROOT/site/state/deletions/12345678-1234-1234-1234-123456789abc.json")" = wprism-deletion/v1 ] \
  || fail 'retirement collision lost the already-published engine tombstone'
[ ! -e "$TOMBSTONE_PRESENT_RACE_ROOT/recovery-fixture/wprism-ssh-publish-post-tombstone.php" ] \
  || fail 'retirement-race refusal left its remote executable installed'
pass 'tombstone publication and source retirement are no-replace at both post-observation races'

case_full_recovery_registry() {
  (
    prepare_remote "full-recovery-$1"
    umask 022
    printf '{"envs":{"target":{"rollback_recovery":{}}}}\n' >"$TMP/envs.json"
    chmod 0600 "$TMP/envs.json"
    WPRISM=fake_full_recovery_adopt
    fake_full_recovery_adopt() {
      [ "$*" = "--envs-file=$TMP/envs.json adopt target" ] || exit 97
      "$PHP_BIN" -r 'exit((fileperms($argv[1]) & 0777) === 0600 ? 0 : 1);' "$TMP/envs.json" \
        || fail 'provider enrollment consumed a nonprivate registry'
      printf 'ADOPT\n' >>"$SSH_LOG"
    }
    scp() {
      [ "${full_recovery_transport_failure:-}" != 1 ] || return 93
      shift 2
      while [ "$#" -gt 1 ]; do
        /bin/cp "$1" "$REMOTE/home/wprism/recovery-fixture/"
        shift
      done
    }
    if [ "$1" = collision ]; then
      printf 'not owned\n' >"$TMP/envs.full-recovery.json"
    elif [ "$1" = transport ]; then
      full_recovery_transport_failure=1
    fi
    # The real positive capture collects status in an OR-list. Exercise that
    # exact errexit-disabled function context, not just its top-level form.
    wprism_ssh_enroll_full_recovery core-delete || fail 'full-recovery helper returned a failed status'
    [ "$(umask)" = 0022 ] || fail 'full-recovery enrollment changed its caller umask'
    "$PHP_BIN" -r 'foreach (array_slice($argv,1) as $p) { if ((fileperms($p) & 0777) !== 0600) exit(1); }' \
      "$TMP/envs.json" "$TMP/core-delete-upload.key" \
      "$REMOTE/home/wprism/recovery-fixture/core-delete-upload.key" \
      || fail 'full-recovery enrollment weakened a private registry/key mode'
  )
}
case_full_recovery_registry normal
expect_refusal 'occupied full-recovery registry staging leaf' \
  'full-recovery registry staging path already exists' case_full_recovery_registry collision
expect_refusal 'full-recovery provider transport under captured status' \
  'full-recovery provider transport failed' case_full_recovery_registry transport
! grep -q '^ADOPT$' "$SCRATCH/full-recovery-transport/ssh.log" \
  || fail 'failed provider transport reached registry enrollment'
[ "$(cat "$SCRATCH/full-recovery-collision/local/envs.full-recovery.json")" = 'not owned' ] \
  || fail 'full-recovery enrollment replaced an unowned staging leaf'
! grep -q '^ADOPT$' "$SCRATCH/full-recovery-collision/ssh.log" \
  || fail 'occupied registry staging leaf reached enrollment'
pass 'full-recovery registry publication retains 0600 without leaking its private mask into the caller'

# Execute the driver's own POSIX SSH argv boundary, provisioning transition,
# and scoped success block. A copied readiness predicate would remain green
# if the actual driver forgot to call it or discarded the warning stream.
SSH_ADOPT_DRIVER="$ROOT/sandbox/tests/live/regress_ssh_adopt.sh"
source "$ROOT/sandbox/conformance/asserts.sh"
SSH_INITIAL_REFUSAL_BLOCK="$(sed -n '/^say "refuse an unsafe durable-control destination/,/^pass "unsafe durable-control topology/p' "$SSH_ADOPT_DRIVER")"
[[ "$SSH_INITIAL_REFUSAL_BLOCK" == *'adopt target'* ]] \
  || fail 'the SSH driver omitted its initial durable-control authority refusal'
case_ssh_initial_refusal() {
  (
    local mutation="$1" WPRISM=fake_initial_adopt
    prepare_remote "initial-refusal-$mutation"
    rmdir "$REMOTE/home/wprism/site"
    say() { :; }
    ssh_fixture() {
      local translated
      translated="$(translated_remote_command "$1")"
      /bin/sh -c "$translated"
    }
    fake_initial_adopt() {
      local mu="$REMOTE/var/www/html/wp-content/mu-plugins"
      case "$mutation" in
        wrong-refusal) printf 'wprism adopt: refusing symlink destination: /var/www/html/wp-content/mu-plugins/wprism-control\n'; return 1 ;;
        dead) return 255 ;;
        sentinel) printf 'changed\n' >"$mu/wprism-control-real/sentinel" ;;
        link) rm "$mu/wprism-control"; mkdir "$mu/wprism-control" ;;
        loader) printf 'changed\n' >"$mu/wprism-loader.php" ;;
        agent) mkdir "$mu/wprism" ;;
        repo) mkdir "$REMOTE/home/wprism/site" ;;
        lock) mkdir "$mu/.wprism-adopt-lock" ;;
        pending) printf 'changed\n' >"$mu/.wprism-generation-writer-pending" ;;
      esac
      printf 'wprism: the database-external recovery fence could not be read safely; repair the adopted control directory boundary, then rerun wprism doctor\n'
      [ "$mutation" = zero-exit ] || return 1
    }
    eval "$SSH_INITIAL_REFUSAL_BLOCK"
    printf 'INITIAL_REFUSAL_READY\n'
  )
}
INITIAL_REFUSAL_OUT=$(case_ssh_initial_refusal normal)
[[ "$INITIAL_REFUSAL_OUT" == *INITIAL_REFUSAL_READY* ]] \
  || fail 'the actual initial-adoption block rejected its unchanged unsafe recovery authority'
for initial_case in wrong-refusal zero-exit dead sentinel link loader agent repo lock pending; do
  initial_status=0
  initial_output=$(case_ssh_initial_refusal "$initial_case" 2>&1) || initial_status=$?
  [ "$initial_status" -ne 0 ] && [[ "$initial_output" != *INITIAL_REFUSAL_READY* ]] \
    || fail "the actual initial-adoption block accepted $initial_case: $initial_output"
done
pass 'the actual initial-adoption refusal requires its public recovery category, nonzero exit, and unchanged control/loader/repository/transaction boundaries'

# Execute the standalone database owner's readiness and grant block: adoption
# must not acquire server authority, and a ping alone cannot prove mutation
# readiness (fd8's first env-set refused for missing direct global PROCESS).
SSH_DATABASE_READY_BLOCK="$(sed -n '/^DATABASE_OWNED=1$/,/^wprism_ssh_install_core "\$WP_CORE_VERSION"$/p' "$SSH_ADOPT_DRIVER" | sed '$d')"
case_ssh_database_ready() {
  (
    local mutation="$1" DB=fixture-database DATABASE_OWNED=0 sql
    local trace="$SCRATCH/database-$1.trace" ownership="$SCRATCH/database-$1.ownership"
    : >"$trace"
    trap 'printf "%s\n" "$DATABASE_OWNED" >"$ownership"' EXIT
    seq() { printf '1\n'; }
    sleep() { :; }
    docker() {
      [ "$DATABASE_OWNED" -eq 1 ] || return 90
      case "$*" in
        'exec fixture-database mariadb-admin ping -h 127.0.0.1 -uroot -proot-pass --silent')
          printf 'PING\n' >>"$trace"
          [ "$mutation" != unavailable ]
          ;;
        'exec -i fixture-database mariadb -uroot -proot-pass')
          sql=$(cat)
          [ "$sql" = "GRANT PROCESS ON *.* TO 'wordpress'@'%';" ] || return 91
          [ "$(cat "$trace")" = $'PING\nPING' ] || return 92
          printf 'GRANT\n' >>"$trace"
          [ "$mutation" != grant-refused ]
          ;;
        *) return 93 ;;
      esac
    }
    eval "$SSH_DATABASE_READY_BLOCK"
    [ "$(cat "$trace")" = $'PING\nPING\nGRANT' ] \
      || fail 'standalone database never established the exact application-account metadata grant'
    printf 'DATABASE_READY\n'
  )
}
DATABASE_READY_OUT=$(case_ssh_database_ready normal)
[[ "$DATABASE_READY_OUT" == *DATABASE_READY* ]] \
  || fail 'the actual standalone database block rejected its ready/granted control'
for database_case in unavailable grant-refused; do
  database_status=0
  database_output=$(case_ssh_database_ready "$database_case" 2>&1) || database_status=$?
  [ "$database_status" -ne 0 ] && [[ "$database_output" != *DATABASE_READY* ]] \
    || fail "the actual standalone database block accepted $database_case"
  [ "$(cat "$SCRATCH/database-$database_case.ownership")" = 1 ] \
    || fail 'failed database initialization lost ownership needed by cleanup'
done
[ "$(cat "$SCRATCH/database-unavailable.trace")" = $'PING\nPING' ] \
  || fail 'an unavailable standalone database reached privilege provisioning'
pass 'the actual standalone database setup requires readiness and the one exact PROCESS grant while retaining cleanup ownership on refusal'

eval "$(sed -n '/^wp_ssh_fixture() {/,/^}/p' "$SSH_ADOPT_DRIVER")"
eval "$(sed -n '/^assert_ssh_fixture_positive_diagnostics() {/,/^}/p' "$SSH_ADOPT_DRIVER")"
declare -F wp_ssh_fixture >/dev/null \
  && declare -F assert_ssh_fixture_positive_diagnostics >/dev/null \
  || fail 'the SSH driver omitted its tested argument/diagnostic boundaries'
SSH_CORE_BINDING_BLOCK="$(sed -n '/^ssh_fixture '\''test ! -e .*wprism-env-values.json/,/^pass "public stdin provisioning/p' "$SSH_ADOPT_DRIVER")"
[[ "$SSH_CORE_BINDING_BLOCK" == *'establish_core_environment_bindings wp_ssh_fixture'* ]] \
  || fail 'the SSH driver omitted its explicit post-adoption provisioning transition'
ADOPT_LAST_VERIFY=$(grep -n '^pass "target carries the shipped platform boundary' "$SSH_ADOPT_DRIVER" | cut -d: -f1)
ADOPT_CORE_BIND=$(grep -n '^establish_core_environment_bindings wp_ssh_fixture ' "$SSH_ADOPT_DRIVER" | cut -d: -f1)
ADOPT_SCOPED_START=$(grep -n '^say "exercise a real checkpointed SSH scoped promotion' "$SSH_ADOPT_DRIVER" | cut -d: -f1)
[ "$ADOPT_LAST_VERIFY" -lt "$ADOPT_CORE_BIND" ] && [ "$ADOPT_CORE_BIND" -lt "$ADOPT_SCOPED_START" ] \
  || fail 'core intent must be chosen after adoption refusal/rollback witnesses and before scoped capture'
SSH_DIAGNOSTIC_INIT_BLOCK="$(sed -n '/^DIAG_DIR="$(mktemp /,/^done$/p' "$SSH_ADOPT_DRIVER")"

ssh_fixture() {
  local translated
  translated="$(translated_remote_command "$1")"
  PATH="$FAKE_BIN:$ORIGINAL_PATH" /bin/sh -c "$translated"
}

prepare_ssh_binding_case() {
  prepare_remote "$1"
  export SSH_EVIDENCE_MODE="${2:-normal}"
  export SSH_EVIDENCE_TRACE="$TMP/env-bindings.jsonl"
  : >"$SSH_EVIDENCE_TRACE"
  cat >"$FAKE_BIN/wp" <<'SSH_EVIDENCE_WP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
$mode = getenv('SSH_EVIDENCE_MODE');
if ($mode === 'argv') {
    echo json_encode(['argv' => $args, 'stdin' => stream_get_contents(STDIN)]), "\n";
    exit(0);
}
if (($args[0] ?? null) === 'eval' && count($args) === 2) {
    $values = ['admin_email' => 'admin@example.test', 'home' => 'http://adopt.example.test', 'siteurl' => 'http://adopt.example.test'];
    if ($mode === 'drift') {
        $values['home'] = 'https://unexpected.invalid';
    }
    echo json_encode($values), "\n";
    exit(0);
}
$name = substr((string) ($args[3] ?? ''), strlen('--name='));
if ($args !== ['wprism', 'env-set', '--repo=' . getenv('REMOTE') . '/home/wprism/site', '--name=' . $name, '--stdin', '--format=json']
    || !in_array($name, ['admin_email', 'home', 'siteurl'], true)) {
    exit(91);
}
$value = stream_get_contents(STDIN);
file_put_contents(getenv('SSH_EVIDENCE_TRACE'), json_encode(['name' => $name, 'stdin' => $value]) . "\n", FILE_APPEND);
if ($mode === 'write-failure') {
    echo '{"format":"wprism-command-refusal/v1","ok":false}', "\n";
    exit(7);
}
echo json_encode(['name' => $name, 'previously_set' => true]), "\n";
SSH_EVIDENCE_WP
  chmod +x "$FAKE_BIN/wp"
}

prepare_ssh_binding_case ssh-argv argv
SSH_QUOTE_ARGS=(eval $'line one\nline two' "apostrophe's" '"double quotes"' \
  "\$(touch '$REMOTE/escaped')" "\`touch '$REMOTE/escaped'\`" '' '--name=x y')
SSH_QUOTE_EXPECTED=$(jq -nc --args '$ARGS.positional' -- "${SSH_QUOTE_ARGS[@]}")
SSH_QUOTE_ACTUAL=$(wp_ssh_fixture "${SSH_QUOTE_ARGS[@]}" <<<'one stdin value')
jq -en --argjson expected "$SSH_QUOTE_EXPECTED" --argjson actual "$SSH_QUOTE_ACTUAL" \
  '$actual.argv == $expected and $actual.stdin == "one stdin value\n"' >/dev/null \
  || fail 'the actual SSH WP runner changed quoted arguments or consumed provisioning stdin'
[ ! -e "$REMOTE/escaped" ] || fail 'the actual SSH WP runner executed an argument as shell code'
pass 'the actual SSH WP boundary preserves empty/quoted/multiline/metacharacter arguments and stdin through /bin/sh'

case_ssh_binding() {
  local WPRISM=fake_binding_doctor
  prepare_ssh_binding_case "ssh-binding-$1" "$1"
  local TMPDIR="$TMP" PREFIX=offline-mutation previous_umask
  previous_umask=$(umask)
  eval "$SSH_DIAGNOSTIC_INIT_BLOCK"
  [ "$(umask)" = "$previous_umask" ] || fail 'private diagnostics changed the caller umask'
  "$PHP_BIN" -r '
    foreach (array_slice($argv, 1) as $index => $path) {
        $expected = $index === 0 ? 0700 : 0600;
        if (!file_exists($path) || is_link($path) || (fileperms($path) & 0777) !== $expected) {
            exit(1);
        }
    }
  ' "$DIAG_DIR" "$DATABASE_MUTATION_STDOUT" "$DATABASE_MUTATION_STDERR" "$DATABASE_MUTATION_EXIT" \
    || fail 'the actual mutation diagnostic owner did not create private files'
  fake_binding_doctor() {
    [ "$#" -eq 3 ] && [ "$1" = "--envs-file=$TMP/envs.json" ] && [ "$2" = doctor ] && [ "$3" = target ] \
      || return 91
    case "$SSH_EVIDENCE_MODE" in
      database-empty) return 0 ;;
      database-warning) printf '[WARN] transactional database mutation (mariadb) — private-operator-value\n'; return 0 ;;
      database-wrong-family) printf '[PASS] transactional database mutation (mysql)\n'; return 0 ;;
      database-stderr-warning) printf '[WARN] transactional database mutation (mariadb) — private-operator-value\n' >&2 ;;
      database-duplicate) printf '[PASS] transactional database mutation (mariadb)\n' ;;
      database-php) printf 'PHP Warning: private-operator-value in /fixture.php on line 12\n' >&2 ;;
    esac
    printf '[PASS] transactional database mutation (mariadb)\n'
    [ "$SSH_EVIDENCE_MODE" != database-exit ] || return 13
  }
  if [ "$1" = already-bound ]; then
    printf '{}\n' >"$REMOTE/home/wprism/site/.wprism-env-values.json"
  fi
  eval "$SSH_CORE_BINDING_BLOCK"
  printf 'ENV_BINDINGS_READY\n'
}
case_ssh_binding normal
jq -es '. == [
  {name:"admin_email",stdin:"admin@example.test\n"},
  {name:"home",stdin:"http://adopt.example.test\n"},
  {name:"siteurl",stdin:"http://adopt.example.test\n"}
]' "$SCRATCH/ssh-binding-normal/local/env-bindings.jsonl" >/dev/null \
  || fail 'the actual SSH fixture did not choose exactly the three installer-owned stdin values'
expect_refusal 'SSH fixture ambient core drift' 'refusing to adopt drift as intent' case_ssh_binding drift
expect_refusal 'adoption unexpectedly binding intent' 'adoption unexpectedly provisioned' case_ssh_binding already-bound
for binding_case in drift already-bound; do
  [ ! -s "$SCRATCH/ssh-binding-$binding_case/local/env-bindings.jsonl" ] \
    || fail 'the SSH fixture wrote intent before its unprovisioned/exact-value premise passed'
done
expect_refusal 'SSH fixture env-set command failure' 'failed with exit 7' case_ssh_binding write-failure
[ "$(wc -l <"$SCRATCH/ssh-binding-write-failure/local/env-bindings.jsonl" | tr -d ' ')" -eq 1 ] \
  || fail 'the SSH fixture continued provisioning after an env-set refusal'
pass 'the actual SSH transition preserves unprovisioned adoption and refuses drift or partial provisioning'
for binding_case in database-empty database-warning database-wrong-family database-stderr-warning database-duplicate database-php database-exit; do
  binding_status=0
  binding_output=$(case_ssh_binding "$binding_case" 2>&1) || binding_status=$?
  [ "$binding_status" -ne 0 ] && [[ "$binding_output" != *ENV_BINDINGS_READY* ]] \
    || fail "the actual SSH provisioning block accepted $binding_case"
  [[ "$binding_output" != *private-operator-value* ]] \
    || fail 'the SSH mutation readiness gate exposed private operator material'
  [ ! -s "$SCRATCH/ssh-binding-$binding_case/local/env-bindings.jsonl" ] \
    || fail 'the SSH fixture wrote intent before its transactional mutation premise passed'
  expected_exit=0
  [ "$binding_case" != database-exit ] || expected_exit=13
  [ "$(cat "$SCRATCH/ssh-binding-$binding_case/local/"offline-mutation-ssh-adopt-diagnostics.*/database-mutation.exit)" = "$expected_exit" ] \
    || fail 'the SSH mutation premise lost the actual Doctor exit status'
done
pass 'the actual SSH mutation premise requires one passed MariaDB check with matching exit and clean complete diagnostics before env-set; private capture and caller umask are preserved'

SSH_SCOPED_SUCCESS_BLOCK="$(sed -n '/^if "\$WPRISM".*promote target.*scoped-apply-success-scope.json/,/^SUCCESS_STATUS=/p' "$SSH_ADOPT_DRIVER" | sed '$d')"
[[ "$SSH_SCOPED_SUCCESS_BLOCK" == *'SCOPED_SUCCESS_PROMOTE_STDOUT'* ]] \
  || fail 'the SSH driver has no executable scoped-promotion success block'
case_ssh_scoped_success() {
  local mutation="$1"
  local WPRISM=fake_scoped_promote SUCCESS_SCOPE_HASH=scope-fixture
  local SCOPED_SUCCESS_PROMOTE_STDOUT="$SCRATCH/scoped-$mutation.stdout"
  local SCOPED_SUCCESS_PROMOTE_STDERR="$SCRATCH/scoped-$mutation.stderr"
  local SCOPED_SUCCESS_PROMOTE_EXIT="$SCRATCH/scoped-$mutation.exit"
  local AUTHORITY_STATUS_STDOUT="$SCRATCH/scoped-prior.json"
  printf '{"generation":7}\n' >"$AUTHORITY_STATUS_STDOUT"
  fake_scoped_promote() {
    local answer
    answer='{"format":"wprism-scoped-promotion-result/v1","state":"committed","generation":8,"scope_hash":"scope-fixture","rollback":{"format":"wprism-scoped-promotion-receipt/v1","automatic_window_closed":true,"later_rollback_supported":false},"scoped_apply":{"format":"wprism-scoped-apply-result/v1","canary":"clean","verification":{"result":"pass"},"scoped_receipt":{"phase":"complete"},"plan":{"env_missing":1},"warnings":["provider capability fired: fixture (verified)"]}}'
    case "$mutation" in
      required) answer=$(jq -c '.scoped_apply.warnings += ["env_missing: option home is required"]' <<<"$answer") ;;
      verification) answer=$(jq -c '.scoped_apply.verification.result="fail"' <<<"$answer") ;;
      canary) answer=$(jq -c '.scoped_apply.canary="dirty"' <<<"$answer") ;;
      no-apply) answer=$(jq -c 'del(.scoped_apply)' <<<"$answer") ;;
      php-stderr) printf 'PHP Warning: private-operator-value in /fixture.php on line 12\n' >&2 ;;
      php-startup) printf 'PHP Warning: PHP Startup: Unable to load dynamic library private-operator-value in Unknown on line 0\n' >&2 ;;
      php-parse) printf 'PHP Parse error: private-operator-value\n' >&2 ;;
      required-stderr) printf 'Warning: env_missing: private-operator-value\n' >&2 ;;
      php-stdout) printf 'PHP Notice: private-operator-value in /fixture.php on line 12\n' ;;
    esac
    printf '%s\n' "$answer"
    [ "$mutation" != exit-failure ] || return 7
  }
  eval "$SSH_SCOPED_SUCCESS_BLOCK"
}
case_ssh_scoped_success normal
for scoped_case in required verification canary no-apply php-stderr php-startup php-parse required-stderr php-stdout exit-failure; do
  scoped_status=0
  scoped_output=$(case_ssh_scoped_success "$scoped_case" 2>&1) || scoped_status=$?
  [ "$scoped_status" -ne 0 ] || fail "the actual scoped success block accepted $scoped_case"
  [[ "$scoped_output" != *private-operator-value* ]] \
    || fail 'the scoped success diagnostic gate exposed private operator material'
done
pass 'the actual scoped success block rejects nested required-env/verification failures and both host-visible diagnostic streams without exposing private bytes'

printf 'PASS: shared SSH adoption extension helper\n'
