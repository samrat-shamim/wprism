#!/usr/bin/env bash
# Exact-source preflight and freeze helpers for the reference certification
# runner.
#
# Source-only library. The caller owns strict-shell mode, `fail()`, and
# `REPO_ROOT`; this boundary proves that the checkout is a clean standalone
# source root, freezes one commit for every child, and re-reads that identity
# before evidence is published. Pair lifecycle and bundle publication remain
# in certify_reference_bundle.sh.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  printf 'FAIL: certbundle_source.sh is a source-only library; source it from the certification runner or its offline regression\n' >&2
  exit 1
fi

certbundle_source_assert_exact_checkout() {
  local checkout_root git_dir common_dir common_root env_file
  local expected_agent expected_manifests mounted_agent mounted_manifests
  checkout_root="$(cd "$REPO_ROOT" && pwd -P)"
  git_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-dir for checkout $checkout_root"
  common_dir="$(git -C "$REPO_ROOT" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" \
    || fail "refusing certification: cannot resolve git-common-dir for checkout $checkout_root"
  common_root="$(dirname "$common_dir")"
  if [ ! -d "$git_dir" ] || [ "$git_dir" != "$common_dir" ] \
      || [ "$common_root" != "$checkout_root" ] || [ -f "$REPO_ROOT/.git" ]; then
    fail "refusing certification from a linked worktree or stale canonical mount; use a clean primary or standalone exact-HEAD clone"
  fi
  if [ -n "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ]; then
    fail "refusing certification from a dirty checkout; use a clean primary or standalone exact-HEAD clone"
  fi
  expected_agent="$checkout_root/agent"
  expected_manifests="$checkout_root/manifests"
  env_file="$checkout_root/sandbox/.env"
  if [ -e "$env_file" ]; then
    mounted_agent="$(sed -n 's/^DUO_AGENT_SRC=//p' "$env_file" | head -1)"
    mounted_manifests="$(sed -n 's/^DUO_MANIFESTS_SRC=//p' "$env_file" | head -1)"
    if [ "$mounted_agent" != "$expected_agent" ] || [ "$mounted_manifests" != "$expected_manifests" ]; then
      fail "refusing certification with stale canonical mount registry $env_file; remove it or refresh pair.sh from the clean checkout"
    fi
  fi
  [ -d "$expected_agent" ] || fail "refusing certification: canonical agent mount source is absent: $expected_agent"
  [ -d "$expected_manifests" ] || fail "refusing certification: canonical manifest mount source is absent: $expected_manifests"
}

certbundle_source_freeze_sha() {
  git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}' \
    || fail "refusing certification: cannot resolve the exact source commit"
}

certbundle_source_assert_expected_sha() { # <source-sha> <name> <value>
  local source_sha="$1" name="$2" value="$3"
  [ -z "$value" ] && return 0
  [[ "$value" =~ ^[0-9a-f]{40}$ ]] \
    || fail "refusing certification: $name must be one full lowercase Git commit SHA"
  [ "$value" = "$source_sha" ] \
    || fail "refusing certification: $name names $value but the clean checkout is $source_sha"
}

certbundle_source_assert_unchanged() { # <source-sha>
  local source_sha="$1" current
  current="$(git -C "$REPO_ROOT" rev-parse --verify 'HEAD^{commit}' 2>/dev/null)" \
    || fail "refusing certification: source HEAD became unreadable during the run"
  [ "$current" = "$source_sha" ] \
    || fail "refusing certification: source HEAD moved from $source_sha to $current during the run"
  [ -z "$(git -C "$REPO_ROOT" status --porcelain=v1 --untracked-files=all)" ] \
    || fail "refusing certification: source checkout changed after the exact-SHA preflight"
}
