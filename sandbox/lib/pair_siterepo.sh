#!/usr/bin/env bash
# Exact pair-owned site-repository roots and cleanup transitions.
#
# This boundary owns disposable root preparation, host handback after uid 33
# capture work, inode-preserving content clearing, and the codebind refusal
# that prevents a reset from leaving a container attached to stale nested
# plugin code. Pair lifecycle keeps command ordering, database mutation,
# Compose teardown, and marker state; this library only proves and transitions
# the two closed `siterepo/<pair>{1,2}` roots.
#
# Expects the caller to define fail() and validate_name(). The latter remains
# caller-owned because the public repo-host command validates its argv before
# deriving either exact root; the inherited-caller convention matches the
# narrow shell libraries already sourced by pair.sh.

pair_siterepo_prepare_roots() { # <name>
  local name="$1"
  mkdir -p "siterepo/${name}1" "siterepo/${name}2"

  # These are disposable sandbox bind-mount roots, shared by two different
  # users: the host process creates/commits the repository, while wp-cli runs
  # as uid 33 and creates capture locks plus atomic staging directories at the
  # repository root. Linux CI preserves host ownership on bind mounts (unlike
  # some desktop Docker filesystems), so mkdir's ordinary 0755 would leave a
  # fresh checkout host-only and every capture would fail before it
  # could acquire state.capture.lock. Keep this deliberately scoped to the two
  # throwaway sandbox roots; it is not a production permission recommendation.
  chmod 0777 "siterepo/${name}1" "siterepo/${name}2"
}

# DUO-3420: return one pair-owned bind root to the host user after uid 33 has
# created capture/state trees in it. The path is never accepted from argv: it
# is derived only from an already validated pair name and a closed side value.
# A one-shot root container bind-mounts precisely that resolved directory at
# /siterepo, crossing the uid boundary without sudo or granting host cleanup
# authority over another pair or the canonical checkout. Direct `docker run`
# also keeps reset's established non-Git-copy behavior: no agent/manifests
# source resolution or pair service/volume creation is needed for a handback.
#
# The root inode is preserved. Missing roots are a no-op (destroy of a pair
# that never reached repository creation stays a no-op); a symlink or other
# non-directory refuses instead of following/replacing it. chown/chmod and the
# host-side ownership readback are all checked so reset/destroy fail before
# database/container mutation when the handback cannot be proved.
pair_siterepo_stat_owner() { # <ordinary path>
  local path="$1" owner
  if owner="$(stat -c '%u:%g' "$path" 2>/dev/null)"; then
    : # GNU stat.
  elif owner="$(stat -f '%u:%g' "$path" 2>/dev/null)"; then
    : # BSD stat.
  else
    return 1
  fi
  printf '%s\n' "$owner"
}

pair_siterepo_stat_inode() { # <ordinary path>
  local path="$1" inode
  if inode="$(stat -c '%i' "$path" 2>/dev/null)"; then
    : # GNU stat.
  elif inode="$(stat -f '%i' "$path" 2>/dev/null)"; then
    : # BSD stat.
  else
    return 1
  fi
  printf '%s\n' "$inode"
}

pair_siterepo_revalidate_root() { # <root> <expected-inode>
  local root="$1" expected_inode="$2" actual_inode
  if [ -L "$root" ] || [ ! -d "$root" ]; then
    fail "exact pair repository root changed from an ordinary directory during ownership handback: $root"
  fi
  actual_inode="$(pair_siterepo_stat_inode "$root")" \
    || fail "could not read exact pair repository inode after ownership handback: $root"
  [ "$actual_inode" = "$expected_inode" ] \
    || fail "exact pair repository inode changed during ownership handback: $root"
}

pair_siterepo_host_one() { # <name> <side (1|2)>
  local name="$1" side="$2" root root_abs host_uid host_gid owner owner_uid root_inode_before cli_image
  root="siterepo/${name}${side}"
  case "$side" in
    1|2) ;;
    *) fail "repository side '$side' invalid — expected 1 or 2" ;;
  esac
  if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
    fail "pair '$name' repository root is not an ordinary directory: $root — refusing ownership handback"
  fi
  [ -d "$root" ] || return 0

  host_uid="$(id -u)" || fail "could not resolve the host uid for repository handback"
  host_gid="$(id -g)" || fail "could not resolve the host gid for repository handback"
  [[ "$host_uid" =~ ^[0-9]+$ ]] && [[ "$host_gid" =~ ^[0-9]+$ ]] \
    || fail "host uid/gid must be decimal integers for repository handback (got ${host_uid}:${host_gid})"

  root_abs="$(cd "$(dirname "$root")" && pwd -P)/$(basename "$root")" \
    || fail "could not resolve exact pair repository path for ownership handback: $root"
  if [ -L "$root_abs" ] || [ ! -d "$root_abs" ]; then
    fail "pair '$name' repository root is not an ordinary physical directory: $root_abs — refusing ownership handback"
  fi
  root_inode_before="$(pair_siterepo_stat_inode "$root_abs")" \
    || fail "could not read exact pair repository inode before ownership handback: $root"
  [[ "$root_inode_before" =~ ^[0-9]+$ ]] \
    || fail "exact pair repository inode is malformed before ownership handback: $root"

  cli_image="${DUO_CLI_IMAGE:-wordpress:cli-php8.3}"
  if ! docker run --rm -u root \
    --mount "type=bind,src=${root_abs},dst=/siterepo" \
    --entrypoint sh "$cli_image" -ceu '
uid="$1"; gid="$2"
chown -R "$uid:$gid" /siterepo
chmod -R ugo+rwX /siterepo
chmod 0777 /siterepo
' sh "$host_uid" "$host_gid"; then
    fail "could not return exact pair repository $root from container uid 33 to host ${host_uid}:${host_gid}"
  fi

  # Docker Desktop may return an exact host UID but a translated GID for this
  # bind root. Revalidate its closed path/inode before every host mutation;
  # a foreign UID is never normalized, and a final literal uid:gid readback is
  # still mandatory after the narrowly scoped host-side repair.
  pair_siterepo_revalidate_root "$root_abs" "$root_inode_before"
  owner="$(pair_siterepo_stat_owner "$root_abs")" || {
    fail "could not verify host ownership of exact pair repository $root after handback"
  }
  if ! [[ "$owner" =~ ^[0-9]+:[0-9]+$ ]]; then
    fail "exact pair repository ownership readback is malformed after handback: $owner"
  fi
  owner_uid="${owner%%:*}"
  if [ "$owner_uid" != "$host_uid" ]; then
    fail "exact pair repository $root has foreign uid after handback (got ${owner}; expected uid ${host_uid})"
  fi
  if [ "$owner" != "${host_uid}:${host_gid}" ]; then
    # -h prevents a root-path symlink race from following a target; no -R is
    # intentional. Docker already chowns the tree recursively, while this
    # host repair changes only the prevalidated exact bind-root directory.
    if ! chgrp -h "$host_gid" "$root_abs"; then
      pair_siterepo_revalidate_root "$root_abs" "$root_inode_before"
      fail "could not normalize exact pair repository $root to host group ${host_gid} after handback"
    fi
    pair_siterepo_revalidate_root "$root_abs" "$root_inode_before"
    owner="$(pair_siterepo_stat_owner "$root_abs")" || {
      fail "could not verify host ownership of exact pair repository $root after host-side normalization"
    }
  fi
  [ "$owner" = "${host_uid}:${host_gid}" ] \
    || fail "exact pair repository $root still has owner $owner after handback (expected ${host_uid}:${host_gid})"
}

pair_siterepo_host() { # <name> [1|2|both]
  local name="$1" selector="${2:-both}"
  validate_name "$name"
  case "$selector" in
    1|2) pair_siterepo_host_one "$name" "$selector" ;;
    both)
      # Validate both exact roots before mutating either one, so a malformed
      # peer path cannot leave a half-transition behind.
      local root
      for root in "siterepo/${name}1" "siterepo/${name}2"; do
        if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
          fail "pair '$name' repository root is not an ordinary directory: $root — refusing ownership handback"
        fi
      done
      pair_siterepo_host_one "$name" 1
      pair_siterepo_host_one "$name" 2
      ;;
    *) fail "repository side '$selector' invalid — expected 1, 2, or both" ;;
  esac
}

pair_siterepo_clear_root() { # <path>
  local root="$1"

  # Preserve the bind-root inode. Docker's default rprivate bind propagation
  # pins the directory that existed when a container was created; deleting
  # and recreating that directory makes a running ordinary web/site container
  # see stale content forever. Codebind pairs are refused separately because
  # their nested plugin inode would still be replaced. Remove only children
  # in place instead. A pre-existing symlink/non-directory is not a valid
  # disposable root and is removed before the real directory is made.
  if [ -L "$root" ] || { [ -e "$root" ] && [ ! -d "$root" ]; }; then
    rm -rf -- "$root"
  fi
  mkdir -p -- "$root"
  chmod -R ugo+rwX "$root"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
  chmod 0777 "$root"
}

pair_siterepo_refuse_codebind_reset() { # <name>
  local name="$1" container mounts source destination existing

  # pair.codebind.yml adds a nested plugin bind source whose inode is pinned
  # independently of /siterepo/<name>. Clearing that nested directory in place
  # would still leave a running/stopped container attached to stale code, so
  # reset has an explicit fail-closed contract for codebind pairs. Destroy the
  # pair and bring it back with --codebind after the clean-room reset instead.
  if ! existing="$(docker ps -a --format '{{.Names}}' 2>/dev/null)"; then
    fail "could not enumerate pair containers before reset; refusing without a verified codebind check"
  fi
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1" \
                   "duo-${name}-cli1-1" "duo-${name}-cli2-1"; do
    if ! printf '%s\n' "$existing" | grep -Fqx -- "$container"; then
      continue
    fi
    if ! mounts="$(docker inspect "$container" \
      --format '{{range .Mounts}}{{.Source}}{{"\t"}}{{.Destination}}{{"\n"}}{{end}}' \
      2>/dev/null)"; then
      fail "could not inspect existing pair container $container before reset; refusing without a verified codebind check"
    fi
    while IFS=$'\t' read -r source destination; do
      [ -n "${destination:-}" ] || continue
      case "$destination" in
        /var/www/html/wp-content/plugins/*)
          fail "pair '$name' has a codebind mount on $container ($source -> $destination); reset is refused because Docker pins that nested inode. Run 'pair.sh destroy $name' then 'pair.sh up $name <port1> <port2> --codebind <plugin-dir>'"
          ;;
      esac
    done <<< "$mounts"
  done
}
