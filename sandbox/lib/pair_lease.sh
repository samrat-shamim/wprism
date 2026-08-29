#!/usr/bin/env bash
# Logical pair/port leases for multi-process evidence runners. Callers hold
# pair_budget_lock.sh's shared lock while invoking these functions.

pair_lease_dir() {
  printf '%s/sandbox/siterepo/.pair-leases\n' "${PAIR_CANONICAL_ROOT:?pair canonical root is unset}"
}

pair_lease_owner_start() { # pair_lease_owner_start <pid>
  ps -o lstart= -p "$1" 2>/dev/null | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//'
}

pair_lease_prune_stale() {
  local dir file pid recorded_start actual_start
  dir="$(pair_lease_dir)"
  mkdir -p -- "$dir" || fail "could not create pair-lease directory: $dir"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    if ! jq -e '
      type == "object" and (keys | sort) == ["created_at","owner_pid","owner_start","ports","token"]
      and (.owner_pid | type == "number" and floor == . and . > 0)
      and (.owner_start | type == "string" and length > 0)
      and (.token | type == "string" and test("^[a-f0-9]{32}$"))
      and (.ports | type == "array" and length == 2 and all(.[]; type == "number" and floor == .))
    ' "$file" >/dev/null 2>&1; then
      fail "pair lease is malformed; refusing to guess ownership: $file"
    fi
    pid="$(jq -r '.owner_pid' "$file")"
    recorded_start="$(jq -r '.owner_start' "$file")"
    actual_start="$(pair_lease_owner_start "$pid")"
    if [ -z "$actual_start" ] || [ "$actual_start" != "$recorded_start" ]; then
      rm -f -- "$file" || fail "could not remove stale pair lease: $file"
    fi
  done
  shopt -u nullglob
}

pair_lease_reserved_pairs() {
  local dir file
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    basename "$file" .json
  done
  shopt -u nullglob
}

pair_lease_assert_access() { # pair_lease_assert_access <name> [persisted-or-requested-port...]
  local name="$1"; shift
  local dir file leased_name token port leased_port own_file='' requested_json
  local -a requested_ports=("$@")
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  token="${WPRISM_PAIR_LEASE_TOKEN:-}"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    leased_name="$(basename "$file" .json)"
    if [ "$leased_name" = "$name" ]; then
      own_file="$file"
      [ -n "$token" ] && [ "$(jq -r '.token' "$file")" = "$token" ] \
        || fail "pair '$name' is reserved by another evidence batch; refusing before mutation"
      continue
    fi
    for port in "${requested_ports[@]}"; do
      while IFS= read -r leased_port; do
        [ "$leased_port" != "$port" ] \
          || fail "pair '$name' port $port is reserved by evidence pair '$leased_name'; refusing before mutation"
      done < <(jq -r '.ports[]' "$file")
    done
  done
  shopt -u nullglob
  if [ -n "$own_file" ] && [ "${#requested_ports[@]}" -gt 0 ]; then
    requested_json="$(printf '%s\n' "${requested_ports[@]}" | jq -R 'tonumber' | jq -s '.')" \
      || fail "pair '$name' requested ports are malformed"
    jq -e --argjson ports "$requested_json" '.ports == $ports' "$own_file" >/dev/null \
      || fail "pair '$name' lease does not authorize requested ports ${requested_ports[*]}"
  fi
}

pair_lease_acquire_batch() { # token owner-pid owner-start (name port1 port2)...
  local token="$1" owner_pid="$2" owner_start="$3"; shift 3
  local current_start existing bindings leases='' dir site_root name port1 port2 file used_names='' used_ports='' spec
  local -a requests=("$@")
  [[ "$token" =~ ^[a-f0-9]{32}$ ]] || fail "pair lease token must be 32 lowercase hex characters"
  [[ "$owner_pid" =~ ^[1-9][0-9]*$ ]] || fail "pair lease owner PID is malformed"
  current_start="$(pair_lease_owner_start "$owner_pid")"
  [ -n "$current_start" ] && [ "$current_start" = "$owner_start" ] \
    || fail "pair lease owner process is absent or has a different start identity"
  [ "$#" -gt 0 ] && [ $(( $# % 3 )) -eq 0 ] || fail "pair lease request must contain name/port pairs"
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  site_root="$(pwd -P)/siterepo"
  existing="$(pair_compose_all_pairs)" || fail "could not enumerate live/stopped pair names for lease preflight"
  bindings="$(pair_compose_all_bound_ports)" \
    || fail "could not enumerate live/stopped pair port bindings for lease preflight"

  shopt -s nullglob
  for file in "$dir"/*.json; do
    leases+="$(jq -r '.ports[]' "$file")"$'\n'
  done
  shopt -u nullglob

  while [ "$#" -gt 0 ]; do
    name="$1"; port1="$2"; port2="$3"; shift 3
    pair_identity_validate_name "$name"
    [[ "$port1" =~ ^[0-9]+$ && "$port2" =~ ^[0-9]+$ ]] \
      && [ "$port1" -ge 8900 ] && [ "$port2" -eq $((port1 + 1)) ] && [ $((port1 % 2)) -eq 0 ] \
      || fail "pair '$name' lease ports must be an even port >= 8900 and its successor"
    [ "$port2" -le 65535 ] || fail "pair '$name' lease ports exceed 65535"
    ! printf '%s\n' "$used_names" | grep -Fqx -- "$name" \
      || fail "pair lease request duplicates name '$name'"
    for spec in "$port1" "$port2"; do
      ! printf '%s\n%s\n' "$used_ports" "$leases" | grep -Fqx -- "$spec" \
        || fail "pair lease request collides on port $spec"
      ! awk -F '\t' -v port="$spec" '$2 == port { found=1 } END { exit(found ? 0 : 1) }' <<<"$bindings" \
        || fail "pair lease port $spec is retained by a live or stopped pair"
      command -v lsof >/dev/null 2>&1 || fail "pair lease preflight requires lsof"
      ! lsof -nP -iTCP:"$spec" -sTCP:LISTEN >/dev/null 2>&1 \
        || fail "pair lease port $spec is already listening"
      used_ports+="$spec"$'\n'
    done
    ! printf '%s\n' "$existing" | grep -Fqx -- "$name" \
      || fail "pair '$name' already exists as a live or stopped Compose project"
    [ ! -e "$dir/$name.json" ] || fail "pair '$name' is already leased"
    for spec in \
      "$site_root/${name}1" \
      "$site_root/${name}2" \
      "$site_root/origin-${name}.git" \
      "$site_root/.${name}1.needs-install" \
      "$site_root/.${name}2.needs-install"; do
      [ ! -e "$spec" ] && [ ! -L "$spec" ] \
        || fail "pair '$name' has existing site state at $spec"
    done
    used_names+="$name"$'\n'
  done

  set -- "${requests[@]}"
  umask 077
  while [ "$#" -gt 0 ]; do
    name="$1"; port1="$2"; port2="$3"; shift 3
    file="$dir/$name.json"
    jq -n --arg token "$token" --argjson owner_pid "$owner_pid" --arg owner_start "$owner_start" \
      --arg created_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" --argjson port1 "$port1" --argjson port2 "$port2" \
      '{created_at:$created_at,owner_pid:$owner_pid,owner_start:$owner_start,ports:[$port1,$port2],token:$token}' \
      >"$file.tmp"
    mv -- "$file.tmp" "$file"
  done
}

pair_lease_release_token() { # pair_lease_release_token <token>
  local token="$1" dir file
  [[ "$token" =~ ^[a-f0-9]{32}$ ]] || fail "pair lease token must be 32 lowercase hex characters"
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    if [ "$(jq -r '.token' "$file")" = "$token" ]; then
      rm -f -- "$file" || fail "could not release pair lease: $file"
    fi
  done
  shopt -u nullglob
}
