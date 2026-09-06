#!/usr/bin/env bash
# Shared host boundary for pair-backed product tests. Phase-owning adapters
# must enter deploy through cli/wprism; invoking `wp wprism deploy` inside the
# target would let a plugin process impersonate the orchestrator.

wprism_host_install_recovery_runtime() { # <source-root> <site-repository>
  local source_root="$1" repo_root="$2" expected="${WPRISM_EXPECTED_SOURCE_SHA:-}"
  local source_top repo_top source_head source_dirty
  local state control runtime stage source_file invalid

  source_root="$(cd "$source_root" 2>/dev/null && pwd -P)" \
    || { printf 'wprism test runtime: source root is unreadable\n' >&2; return 1; }
  repo_root="$(cd "$repo_root" 2>/dev/null && pwd -P)" \
    || { printf 'wprism test runtime: site repository is unreadable\n' >&2; return 1; }
  source_top="$(git -C "$source_root" rev-parse --show-toplevel 2>/dev/null)" \
    || { printf 'wprism test runtime: source Git top level is unreadable\n' >&2; return 1; }
  [ "$source_top" = "$source_root" ] \
    || { printf 'wprism test runtime: source root is not a Git top level\n' >&2; return 1; }
  repo_top="$(git -C "$repo_root" rev-parse --show-toplevel 2>/dev/null)" \
    || { printf 'wprism test runtime: site Git top level is unreadable\n' >&2; return 1; }
  [ "$repo_top" = "$repo_root" ] \
    || { printf 'wprism test runtime: site repository is not a Git top level\n' >&2; return 1; }
  source_head="$(git -C "$source_root" rev-parse HEAD 2>/dev/null)" \
    || { printf 'wprism test runtime: source HEAD is unreadable\n' >&2; return 1; }
  expected="$(printf '%s' "$expected" | tr '[:upper:]' '[:lower:]')" \
    || { printf 'wprism test runtime: expected source SHA is unreadable\n' >&2; return 1; }
  if [ -n "$expected" ] && [ "${source_head:0:${#expected}}" != "$expected" ]; then
    printf 'wprism test runtime: source HEAD does not match WPRISM_EXPECTED_SOURCE_SHA\n' >&2
    return 1
  fi
  source_dirty="$(git --no-optional-locks -C "$source_root" status --porcelain=v1 --untracked-files=all -- \
    recovery agent/src/Recovery/DatabaseTargetIdentity.php \
    agent/src/Recovery/RetainedCheckpointCipher.php)" \
    || { printf 'wprism test runtime: recovery source status is unreadable\n' >&2; return 1; }
  [ -z "$source_dirty" ] \
    || { printf 'wprism test runtime: recovery source is dirty\n' >&2; return 1; }
  [ -d "$source_root/recovery" ] && [ ! -L "$source_root/recovery" ] \
    || { printf 'wprism test runtime: recovery source is absent or unsafe\n' >&2; return 1; }
  invalid="$(find "$source_root/recovery" -mindepth 1 -maxdepth 1 \
    \( ! -type f -o ! -name '*.php' \) -print -quit 2>/dev/null)" \
    || { printf 'wprism test runtime: recovery source inventory is unreadable\n' >&2; return 1; }
  [ -z "$invalid" ] \
    || { printf 'wprism test runtime: recovery source contains an unsupported node\n' >&2; return 1; }
  [ -f "$source_root/recovery/rollback-control.php" ] \
    || { printf 'wprism test runtime: recovery entry point is absent\n' >&2; return 1; }

  state="$repo_root/.wprism"
  control="$state/control"
  runtime="$control/recovery-runtime"
  for invalid in "$state" "$control" "$runtime"; do
    [ ! -L "$invalid" ] \
      || { printf 'wprism test runtime: destination contains a symlink\n' >&2; return 1; }
    [ ! -e "$invalid" ] || [ -d "$invalid" ] \
      || { printf 'wprism test runtime: destination has an unsafe shape\n' >&2; return 1; }
  done
  mkdir -p "$control" \
    || { printf 'wprism test runtime: could not create the control root\n' >&2; return 1; }
  # Pair repositories are deliberately shared by the host runner and uid 33
  # (docs/sandbox.md:371-379). Sticky shared parents preserve that test-only
  # write access without granting either participant authority to replace the
  # other's private child; production adoption publishes `.wprism` as 0700.
  chmod 1777 "$state" "$control" \
    || { printf 'wprism test runtime: could not share the control root\n' >&2; return 1; }
  stage="$(mktemp -d "$control/.recovery-runtime-stage.XXXXXX")" \
    || { printf 'wprism test runtime: could not allocate runtime staging\n' >&2; return 1; }
  for source_file in "$source_root"/recovery/*.php; do
    cp "$source_file" "$stage/${source_file##*/}" \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: recovery copy failed\n' >&2; return 1; }
  done
  for source_file in DatabaseTargetIdentity.php RetainedCheckpointCipher.php; do
    [ -f "$source_root/agent/src/Recovery/$source_file" ] \
      && [ ! -L "$source_root/agent/src/Recovery/$source_file" ] \
      && cp "$source_root/agent/src/Recovery/$source_file" "$stage/$source_file" \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: agent recovery copy failed\n' >&2; return 1; }
  done
  chmod 0755 "$stage" \
    || { rm -rf -- "$stage"; printf 'wprism test runtime: runtime directory mode failed\n' >&2; return 1; }
  chmod 0644 "$stage"/*.php \
    || { rm -rf -- "$stage"; printf 'wprism test runtime: runtime file mode failed\n' >&2; return 1; }
  if [ -d "$runtime" ]; then
    invalid="$(find "$runtime" -mindepth 1 ! -type f -print -quit 2>/dev/null)" \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: installed runtime inventory is unreadable\n' >&2; return 1; }
    [ -z "$invalid" ] \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: installed runtime contains an unsafe node\n' >&2; return 1; }
    diff -qr "$stage" "$runtime" >/dev/null \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: installed runtime differs from candidate bytes\n' >&2; return 1; }
    rm -rf -- "$stage" \
      || { printf 'wprism test runtime: could not remove runtime staging\n' >&2; return 1; }
  else
    mv "$stage" "$runtime" \
      || { rm -rf -- "$stage"; printf 'wprism test runtime: runtime publication failed\n' >&2; return 1; }
  fi
  php "$runtime/rollback-control.php" init --root="$control" >/dev/null \
    || { printf 'wprism test runtime: control initialization failed\n' >&2; return 1; }
  # Initialization restores the production 0700 control-root invariant. The
  # pair harness must then restore its sticky cross-uid boundary: native Linux
  # preserves the host owner's inode, while cli services run as uid 33.
  chmod 1777 "$control" \
    || { printf 'wprism test runtime: could not share the initialized control root\n' >&2; return 1; }
  chmod 0777 "$state/rollback" "$control/public-keys" \
    || { printf 'wprism test runtime: could not share recovery directories\n' >&2; return 1; }
  chmod 0666 "$control/target.lock" "$control/target.json" \
    || { printf 'wprism test runtime: could not share recovery authority files\n' >&2; return 1; }
}

wprism_host_registry_create() ( # <file> <compose-file> <pair> [repo-path]
  local file="$1" compose_file="$2" pair="$3" repo_path="${4:-/siterepo}"
  # This registry is host-private even when the caller's next repository
  # fixture must be readable by uid 33. Protect fresh and reused files before
  # writing target coordinates, without exporting this mask into the caller.
  umask 077
  : > "$file" && chmod 0600 "$file" || return 1
  jq -n \
    --arg compose_file "$compose_file" \
    --arg pair "$pair" \
    --arg repo_path "$repo_path" \
    '{envs: {
      (($pair + "1")): {
        transport: "docker", compose_file: $compose_file,
        service: "cli1", repo_path: $repo_path
      },
      (($pair + "2")): {
        transport: "docker", compose_file: $compose_file,
        service: "cli2", repo_path: $repo_path
      }
    }}' > "$file"
)

wprism_host_call() { # <cli> <registry> <project> <env> <verb> [args...]
  local cli="$1" registry="$2" project="$3" environment="$4" verb="$5"
  shift 5
  COMPOSE_PROJECT_NAME="$project" php "$cli" --envs-file="$registry" \
    "$verb" "$environment" "$@"
}

# Shared role-based ABI for package evidence sourced by both the conformance
# and exact-version drivers. The package owns the assertion; the harness owns
# the concrete pair name, registry and host CLI transport.
host_wprism() { # <conf1|conf2> <verb> [args...]
  local role="${1:-}" side
  [ "$#" -ge 2 ] \
    || { printf 'wprism test host: expected <conf1|conf2> <verb>\n' >&2; return 64; }
  case "$role" in
    conf1) side=1 ;;
    conf2) side=2 ;;
    *)
      printf "wprism test host: unknown role '%s' (expected conf1|conf2)\n" "$role" >&2
      return 64
      ;;
  esac
  [[ "${WPRISM_PAIR:-}" =~ ^[a-z][a-z0-9]*$ ]] \
    || { printf 'wprism test host: WPRISM_PAIR is not a canonical pair name\n' >&2; return 64; }
  [ -n "${WPRISM_HOST_CLI:-}" ] && [ -n "${WPRISM_HOST_REGISTRY:-}" ] \
    || { printf 'wprism test host: host CLI and registry are not initialized\n' >&2; return 64; }
  shift
  wprism_host_call \
    "$WPRISM_HOST_CLI" "$WPRISM_HOST_REGISTRY" "wprism-$WPRISM_PAIR" \
    "${WPRISM_PAIR}${side}" "$@"
}
