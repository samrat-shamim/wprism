#!/usr/bin/env bash

# Shared extension machinery for regress_ssh_adopt.sh. The parent suite owns
# its target, registry, diagnostics, and signed checkpoint provider; callers
# select certified plugin artifacts plus narrowly validated fixture state.

# shellcheck source=../../bin/artifact-library.sh
. "$ROOT/sandbox/bin/artifact-library.sh"

wprism_ssh_install_certified_plugin() { # <artifact-slug> <version>
  [ "$#" -eq 2 ] || fail 'certified plugin install requires an artifact slug and version'
  local slug="$1" version="$2" entry url sha256 archive partial actual remote

  [[ "$slug" =~ ^[a-z0-9][a-z0-9._-]*[a-z0-9]$ ]] \
    || fail "certified plugin artifact slug '$slug' is malformed"
  [[ "$version" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]] \
    || fail "certified plugin artifact version '$version' is malformed"
  command -v curl >/dev/null 2>&1 || fail 'curl is required for certified SSH plugin artifacts'
  entry="$(artifact_library_jq -ce --arg slug "$slug" --arg version "$version" '
    .plugins[$slug][$version]
    | if type == "object" and .role == "certified-boundary" then .
      else error("requested plugin artifact is not a certified boundary") end
  ')" || fail "no certified artifact-library boundary exists for $slug $version"
  url="$(jq -er '.url' <<<"$entry")" || fail "certified artifact $slug $version has no URL"
  sha256="$(jq -er '.sha256' <<<"$entry")" || fail "certified artifact $slug $version has no digest"
  [[ "$url" == https://* && "$url" != *"'"* && "$url" != *[[:space:]]* ]] \
    || fail "certified artifact $slug $version has a malformed HTTPS URL"
  [[ "$sha256" =~ ^[a-f0-9]{64}$ ]] \
    || fail "certified artifact $slug $version has a malformed SHA-256"

  archive="$TMP/plugin-${slug}-${version}-${sha256}.zip"
  partial="$archive.partial"
  [ ! -e "$archive" ] && [ ! -L "$archive" ] && [ ! -e "$partial" ] && [ ! -L "$partial" ] \
    || fail "certified artifact scratch path already exists for $slug $version"
  ( umask 077; curl --fail --location --silent --show-error \
      --proto '=https' --proto-redir '=https' --connect-timeout 20 --max-time 180 \
      --max-filesize 268435456 --output "$partial" "$url" ) \
    || fail "certified artifact download failed for $slug $version"
  [ -f "$partial" ] && [ ! -L "$partial" ] \
    || fail "certified artifact download was not an ordinary file for $slug $version"
  [ "$(wc -c <"$partial" | tr -d '[:space:]')" -le 268435456 ] \
    || fail "certified artifact exceeded the 256 MiB fixture bound for $slug $version"
  actual="$(php -r 'echo hash_file("sha256", $argv[1]);' "$partial")" \
    || fail "certified artifact digest could not be read for $slug $version"
  [ "$actual" = "$sha256" ] \
    || fail "certified artifact digest mismatch for $slug $version"
  mv "$partial" "$archive"

  remote="/home/wprism/recovery-fixture/plugin-${slug}-${version}-${sha256}.zip"
  ssh_fixture "test ! -e '$remote' && test ! -L '$remote'" \
    || fail "certified artifact target path already exists for $slug $version"
  scp -F "$TMP/ssh_config" "$archive" "wprism-adopt-fixture:$remote" >/dev/null \
    || fail "certified artifact upload failed for $slug $version"
  ssh_fixture "
    set -eu
    trap 'rm -f -- $remote' EXIT
    actual=\$(php -r 'echo hash_file(\"sha256\", \$argv[1]);' '$remote')
    test \"\$actual\" = '$sha256'
    cd /var/www/html
    ! wp plugin is-installed '$slug' >/dev/null 2>&1
    wp plugin install '$remote' --activate --quiet
    test \"\$(wp plugin get '$slug' --field=version)\" = '$version'
  " || fail "certified artifact installation failed for $slug $version"
}

wprism_ssh_stage_code_inventory() { # <active-plugin-directory>...
  [ "$#" -le 16 ] \
    || fail 'SSH code inventory accepts at most 16 active plugin directories'
  local plugin requested_json active_json joined=''
  local seen=' '
  for plugin in "$@"; do
    [[ "$plugin" =~ ^[a-z0-9][a-z0-9._-]{0,127}$ ]] \
      || fail "SSH code inventory plugin directory '$plugin' is malformed"
    case "$seen" in
      *" $plugin "*) fail "SSH code inventory repeats plugin directory '$plugin'" ;;
    esac
    seen+="$plugin "
    joined="${joined:+$joined }$plugin"
  done
  requested_json="$(jq -nc --args '$ARGS.positional | sort' -- "$@")" \
    || fail 'SSH code inventory could not encode its requested plugin set'
  active_json="$(ssh_fixture 'cd /var/www/html && wp option get active_plugins --format=json')" \
    || fail 'SSH code inventory could not observe the active plugin set'
  jq -e --argjson expected "$requested_json" '
    type == "array"
    and all(.[];
      type == "string"
      and test("^[a-z0-9][a-z0-9._-]{0,127}/[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\\.php$")
      and (split("/") | all(. != "." and . != ".."))
    )
    and ([.[] | split("/")[0]] | sort | unique) == $expected
  ' <<<"$active_json" >/dev/null \
    || fail 'SSH code inventory arguments do not equal the exact active plugin-directory set'

  ssh_fixture "
    set -eu
    plugin_source_root=\$(cd -P /var/www/html/wp-content/plugins && pwd -P)
    theme_source_root=\$(cd -P /var/www/html/wp-content/themes && pwd -P)
    test \"\$plugin_source_root\" = /var/www/html/wp-content/plugins
    test \"\$theme_source_root\" = /var/www/html/wp-content/themes
    for plugin in $joined; do
      plugin_source=\$(cd -P \"\$plugin_source_root/\$plugin\" && pwd -P)
      test \"\$plugin_source\" = \"\$plugin_source_root/\$plugin\"
      test ! -L \"\$plugin_source\"
    done
    stylesheet=\$(cd /var/www/html && wp option get stylesheet)
    template=\$(cd /var/www/html && wp option get template)
    expected_theme_count=1
    test \"\$stylesheet\" = \"\$template\" || expected_theme_count=2
    for theme in \"\$stylesheet\" \"\$template\"; do
      case \"\$theme\" in
        ''|.|..|*[!A-Za-z0-9._-]*) exit 41 ;;
      esac
      theme_source=\$(cd -P \"\$theme_source_root/\$theme\" && pwd -P)
      test \"\$theme_source\" = \"\$theme_source_root/\$theme\"
      test ! -L \"\$theme_source\"
    done
    test ! -e /home/wprism/site/code
    test ! -L /home/wprism/site/code
    mkdir -p /home/wprism/site/code/wp-content/plugins /home/wprism/site/code/wp-content/themes
    plugin_destination_root=\$(cd -P /home/wprism/site/code/wp-content/plugins && pwd -P)
    theme_destination_root=\$(cd -P /home/wprism/site/code/wp-content/themes && pwd -P)
    test \"\$plugin_destination_root\" = /home/wprism/site/code/wp-content/plugins
    test \"\$theme_destination_root\" = /home/wprism/site/code/wp-content/themes
    for plugin in $joined; do
      plugin_source=\$(cd -P \"\$plugin_source_root/\$plugin\" && pwd -P)
      test \"\$plugin_source\" = \"\$plugin_source_root/\$plugin\"
      test ! -L \"\$plugin_source\"
      cp -a \"\$plugin_source\" \"\$plugin_destination_root/\"
      plugin_destination=\$(cd -P \"\$plugin_destination_root/\$plugin\" && pwd -P)
      test \"\$plugin_destination\" = \"\$plugin_destination_root/\$plugin\"
      test ! -L \"\$plugin_destination\"
    done
    for theme in \"\$stylesheet\" \"\$template\"; do
      theme_source=\$(cd -P \"\$theme_source_root/\$theme\" && pwd -P)
      test \"\$theme_source\" = \"\$theme_source_root/\$theme\"
      test ! -L \"\$theme_source\"
      if [ ! -e \"\$theme_destination_root/\$theme\" ] && [ ! -L \"\$theme_destination_root/\$theme\" ]; then
        cp -a \"\$theme_source\" \"\$theme_destination_root/\"
      fi
      theme_destination=\$(cd -P \"\$theme_destination_root/\$theme\" && pwd -P)
      test \"\$theme_destination\" = \"\$theme_destination_root/\$theme\"
      test ! -L \"\$theme_destination\"
    done
    test \"\$(find \"\$plugin_destination_root\" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')\" -eq $#
    test \"\$(find \"\$theme_destination_root\" -mindepth 1 -maxdepth 1 -print | wc -l | tr -d ' ')\" -eq \"\$expected_theme_count\"
  " || fail 'SSH code inventory could not stage the exact active plugin/theme roots'
}

wprism_ssh_stage_generation_releases() { # <desired-count: 1|2|3>
  [ "$#" -eq 1 ] || fail 'SSH release staging requires one desired-generation count'
  local desired_count="$1" authority generation next_generation retry_generation offset
  case "$desired_count" in
    1|2|3) ;;
    *) fail "SSH release staging desired count '$desired_count' must be 1, 2 or 3" ;;
  esac
  authority="$(ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php authority-status --root=/home/wprism/site/.wprism/control')" \
    || fail 'SSH release staging could not read signed generation authority'
  generation="$(jq -er '.generation | select(type == "number" and floor == . and . >= 0) | tostring' <<<"$authority")" \
    || fail 'SSH release staging found malformed generation authority'
  [[ "$generation" =~ ^(0|[1-9][0-9]{0,17})$ ]] \
    || fail "SSH release staging generation '$generation' is not a bounded canonical integer"
  generation=$((10#$generation))
  [ "$generation" -le $((9223372036854775807 - desired_count)) ] \
    || fail 'SSH release staging generation cannot be incremented safely'
  next_generation=$((generation + 1))

  ssh_fixture "
    set -eu
    test -d /home/wprism/site/code/wp-content
    test ! -L /home/wprism/site/code
    test ! -e /home/wprism/code-releases
    test ! -L /home/wprism/code-releases
    test ! -e /home/wprism/code-current
    test ! -L /home/wprism/code-current
    mkdir /home/wprism/code-releases
    mkdir /home/wprism/code-releases/release-prior
    mkdir /home/wprism/code-releases/release-desired-$next_generation
    cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-prior/wp-content
    cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-desired-$next_generation/wp-content
    current_pending=/home/wprism/code-releases/.code-current.pending
    printf '%s\\n' release-prior > \"\$current_pending\"
    chmod 600 \"\$current_pending\"
    if ! ln \"\$current_pending\" /home/wprism/code-current; then
      rm -f \"\$current_pending\"
      exit 42
    fi
    rm -f \"\$current_pending\"
    test \"\$(cat /home/wprism/code-current)\" = release-prior
    test \"\$(stat -c '%a' /home/wprism/code-current)\" = 600
  " || fail 'SSH release staging could not publish prior/desired immutable generations'
  for ((offset = 2; offset <= desired_count; offset++)); do
    retry_generation=$((generation + offset))
    ssh_fixture "
      set -eu
      test ! -e /home/wprism/code-releases/release-desired-$retry_generation
      test ! -L /home/wprism/code-releases/release-desired-$retry_generation
      mkdir /home/wprism/code-releases/release-desired-$retry_generation
      cp -a /home/wprism/site/code/wp-content /home/wprism/code-releases/release-desired-$retry_generation/wp-content
      test -d /home/wprism/code-releases/release-desired-$retry_generation/wp-content
    " || fail 'SSH release staging could not publish the retry immutable generation'
  done
}

wprism_ssh_publish_post_tombstone() { # <post-type> <ascii-post-slug>
  [ "$#" -eq 2 ] || fail 'SSH tombstone publication requires a post type and captured slug'
  local post_type="$1" post_slug="$2" fixture uuid publish_status=0 cleanup_status=0
  [[ "$post_type" =~ ^[a-z0-9][a-z0-9_-]{0,19}$ ]] \
    || fail "SSH tombstone post type '$post_type' is malformed"
  [[ "$post_slug" =~ ^[a-z0-9][a-z0-9-]{0,199}$ ]] \
    || fail "SSH tombstone post slug '$post_slug' is malformed"

  fixture="$TMP/wprism-ssh-publish-post-tombstone.php"
  [ ! -e "$fixture" ] && [ ! -L "$fixture" ] \
    || fail 'SSH tombstone fixture path already exists'
  ( umask 077; cat >"$fixture" <<'PHP'
<?php

$root = '/home/wprism/site';
$postType = (string) getenv('WPRISM_TOMBSTONE_POST_TYPE');
$postSlug = (string) getenv('WPRISM_TOMBSTONE_POST_SLUG');
if (preg_match('/^[a-z0-9][a-z0-9_-]{0,19}$/D', $postType) !== 1
    || preg_match('/^[a-z0-9][a-z0-9-]{0,199}$/D', $postSlug) !== 1) {
    throw new RuntimeException('SSH tombstone fixture arguments are malformed');
}
$postDirectory = "$root/state/posts/$postType";
if (!is_dir($postDirectory) || is_link($postDirectory)) {
    throw new RuntimeException('SSH tombstone post directory is not an ordinary directory');
}
$matches = [];
foreach (scandir($postDirectory) ?: [] as $entry) {
    if (preg_match(
        '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})--'
            . preg_quote($postSlug, '/') . '\\.md$/D',
        $entry,
        $match
    ) !== 1) {
        continue;
    }
    $candidate = "$postDirectory/$entry";
    if (!is_file($candidate) || is_link($candidate)) {
        throw new RuntimeException('SSH tombstone source is not an ordinary file');
    }
    $matches[] = ['uuid' => $match[1], 'base' => $entry, 'path' => $candidate];
}
if (count($matches) !== 1) {
    throw new RuntimeException('SSH tombstone fixture did not resolve exactly one captured post');
}
$match = $matches[0];
$uuid = $match['uuid'];
$sourcePath = "posts/$postType/{$match['base']}";
$policy = \WPrism\Policy::load($root);
$compiled = \WPrism\RepositoryCompiler::compile($root, $policy);
$rows = \WPrism\Deletion::capture_tombstones($compiled, [], $policy, [$uuid]);
if (count($rows) !== 1) {
    throw new RuntimeException('engine tombstone capture did not return exactly one authorized row');
}
$row = $rows[0];
$data = \WPrism\Canon::decode((string) ($row['content'] ?? ''));
$entity = $compiled->tree()[$uuid] ?? null;
if (!is_array($data)
    || array_keys($data) !== [
        'expected_hash', 'expected_revision', 'format', 'kind', 'source_path', 'type', 'uuid',
    ]
    || ($row['uuid'] ?? null) !== $uuid
    || ($row['type'] ?? null) !== 'deletion'
    || ($row['path'] ?? null) !== "deletions/$uuid.json"
    || ($data['format'] ?? null) !== 'wprism-deletion/v1'
    || ($data['kind'] ?? null) !== 'post'
    || ($data['type'] ?? null) !== $postType
    || ($data['uuid'] ?? null) !== $uuid
    || ($data['source_path'] ?? null) !== $sourcePath
    || !is_array($entity)
    || ($data['expected_hash'] ?? null) !== ($entity['hash'] ?? null)
    || ($data['expected_revision'] ?? null) !== $compiled->revision_hash()
    || preg_match('/^[a-f0-9]{64}$/D', (string) ($data['expected_hash'] ?? '')) !== 1
    || preg_match('/^[a-f0-9]{64}$/D', (string) ($data['expected_revision'] ?? '')) !== 1) {
    throw new RuntimeException('engine tombstone capture returned a widened or malformed record');
}

$deletionDirectory = "$root/state/deletions";
if (!is_dir($deletionDirectory)
    && !mkdir($deletionDirectory, 0755, true)
    && !is_dir($deletionDirectory)) {
    throw new RuntimeException('SSH tombstone deletion directory could not be created');
}
if (is_link($deletionDirectory)) {
    throw new RuntimeException('SSH tombstone deletion directory is linked');
}
$final = "$deletionDirectory/$uuid.json";
$pending = "$deletionDirectory/.$uuid.pending-" . bin2hex(random_bytes(8));
$present = "/home/wprism/recovery-fixture/{$match['base']}.present";
if (file_exists($final) || is_link($final) || file_exists($present) || is_link($present)) {
    throw new RuntimeException('SSH tombstone destination already exists');
}
$handle = fopen($pending, 'x+b');
if ($handle === false) {
    throw new RuntimeException('SSH tombstone pending file could not be created');
}
try {
    $content = (string) $row['content'];
    $written = fwrite($handle, $content);
    if ($written !== strlen($content) || !fflush($handle)) {
        throw new RuntimeException('SSH tombstone pending file could not be written completely');
    }
    if (function_exists('fsync') && !fsync($handle)) {
        throw new RuntimeException('SSH tombstone pending file could not be synchronized');
    }
} finally {
    fclose($handle);
}
if (!chmod($pending, 0644)) {
    @unlink($pending);
    throw new RuntimeException('SSH tombstone pending file mode could not be fixed');
}
if (!@link($pending, $final)) {
    @unlink($pending);
    throw new RuntimeException('SSH tombstone could not be published without replacement');
}
if (!@unlink($pending)) {
    throw new RuntimeException('SSH tombstone pending file could not be removed after publication');
}
// Publish first: interruption before source retirement leaves a loud
// live/deletion conflict rather than a silent authoring-side disappearance.
if (!@link($match['path'], $present)) {
    throw new RuntimeException('SSH tombstone source could not be retired without replacement');
}
if (!@unlink($match['path'])) {
    throw new RuntimeException('SSH tombstone source could not be removed after retirement');
}
echo $uuid;
PHP
  )
  if ! scp -F "$TMP/ssh_config" "$fixture" \
      wprism-adopt-fixture:/home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php >/dev/null; then
    rm -f -- "$fixture" || fail 'SSH tombstone local fixture cleanup failed after upload refusal'
    fail 'SSH tombstone fixture upload failed'
  fi
  if uuid="$(ssh_fixture "cd /var/www/html && WPRISM_TOMBSTONE_POST_TYPE='$post_type' WPRISM_TOMBSTONE_POST_SLUG='$post_slug' wp eval-file /home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php")"; then
    publish_status=0
  else
    publish_status=$?
  fi
  if ssh_fixture 'rm -f /home/wprism/recovery-fixture/wprism-ssh-publish-post-tombstone.php'; then
    cleanup_status=0
  else
    cleanup_status=$?
  fi
  # This helper is intentionally reusable for separate captured identities.
  # Retaining its owned local executable made the second tombstone refuse
  # before reaching the engine; pre-existing local collisions remain untouched.
  rm -f -- "$fixture" || fail 'SSH tombstone local fixture cleanup failed'
  if [ "$publish_status" -ne 0 ]; then
    [ "$cleanup_status" -eq 0 ] \
      || fail "engine tombstone publication and fixture cleanup failed for post:$post_type/$post_slug"
    fail "engine tombstone publication failed for post:$post_type/$post_slug"
  fi
  [ "$cleanup_status" -eq 0 ] || fail 'SSH tombstone fixture cleanup failed'
  [[ "$uuid" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]] \
    || fail "engine tombstone publication returned malformed UUID '$uuid'"
  printf '%s\n' "$uuid"
}

wprism_ssh_enroll_full_recovery() { # <state-namespace>
  [ "$#" -eq 1 ] || fail 'full-recovery enrollment requires one state namespace'
  local state_namespace="$1"
  local upload_key="$TMP/${state_namespace}-upload.key"
  local updated_registry="$TMP/envs.full-recovery.json"

  [[ "$state_namespace" =~ ^[a-z][a-z0-9-]{0,31}$ ]] \
    || fail "full-recovery state namespace '$state_namespace' is malformed"

  [ ! -e "$upload_key" ] && [ ! -L "$upload_key" ] \
    || fail 'full-recovery upload key path already exists'
  # Callers retain a nonzero status, so their OR-list disables Bash errexit
  # inside this function. Each owned setup boundary must refuse explicitly.
  ( umask 077; set -o noclobber; openssl rand 32 >"$upload_key" ) \
    || fail 'full-recovery upload key allocation failed'
  chmod 0600 "$upload_key" || fail 'full-recovery upload key mode failed'
  scp -F "$TMP/ssh_config" \
    "$ROOT/sandbox/tests/fixtures/upload-provider.php" \
    "$ROOT/sandbox/tests/fixtures/effect-provider.php" \
    "$ROOT/sandbox/tests/fixtures/plan-bound-code-release-provider.php" \
    "$upload_key" \
    wprism-adopt-fixture:/home/wprism/recovery-fixture/ >/dev/null \
    || fail 'full-recovery provider transport failed'
  ssh_fixture "
    set -eu
    chmod 700 /home/wprism/recovery-fixture/upload-provider.php
    chmod 700 /home/wprism/recovery-fixture/effect-provider.php
    chmod 700 /home/wprism/recovery-fixture/plan-bound-code-release-provider.php
    chmod 600 /home/wprism/recovery-fixture/${state_namespace}-upload.key
    mkdir -p /home/wprism/recovery-fixture/${state_namespace}-offload
    chmod 700 /home/wprism/recovery-fixture/${state_namespace}-offload
  " || fail 'full-recovery target provider boundary could not be prepared'
  [ ! -e "$updated_registry" ] && [ ! -L "$updated_registry" ] \
    || fail 'full-recovery registry staging path already exists'
  ( umask 077; set -o noclobber; jq --arg namespace "$state_namespace" '
    .envs.target.rollback_recovery.upload_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/upload-provider.php",
      "/home/wprism/recovery-fixture/" + $namespace + "-upload-provider-state",
      "/var/www/html/wp-content/uploads",
      "/home/wprism/recovery-fixture/" + $namespace + "-offload",
      "/home/wprism/site/media",
      "/home/wprism/recovery-fixture/" + $namespace + "-upload.key"
    ]
    | .envs.target.rollback_recovery.effect_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/effect-provider.php",
      "/home/wprism/recovery-fixture/" + $namespace + "-effect-provider-state",
      "/var/www/html"
    ]
    | .envs.target.rollback_recovery.code_release_provider = [
      "/usr/local/bin/php",
      "/home/wprism/recovery-fixture/plan-bound-code-release-provider.php",
      "/home/wprism/recovery-fixture/" + $namespace + "-code-release-state",
      "/home/wprism/code-releases",
      "/home/wprism/code-current"
    ]
  ' "$TMP/envs.json" >"$updated_registry" ) \
    || fail 'full-recovery registry could not be staged privately'
  mv "$updated_registry" "$TMP/envs.json" || fail 'full-recovery registry publication failed'
  "$WPRISM" --envs-file="$TMP/envs.json" adopt target >/dev/null \
    || fail "full-recovery provider enrollment failed for '$state_namespace'"
}
