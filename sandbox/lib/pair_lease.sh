#!/usr/bin/env bash
# Complete pair-namespace leases for multi-process evidence runners: logical
# name, Compose resources, bound/listening ports, site roots/install markers,
# and both persistent database schemas. Callers hold pair_budget_lock.sh's
# shared lock while invoking acquisition/release functions.

pair_lease_dir() {
  printf '%s/sandbox/siterepo/.pair-leases\n' "${PAIR_CANONICAL_ROOT:?pair canonical root is unset}"
}

pair_lease_site_root() {
  [ -d siterepo ] || fail "pair lease site root is absent from the current sandbox: $(pwd -P)/siterepo"
  (cd siterepo && pwd -P) \
    || fail "could not resolve the current sandbox's physical pair site root"
}

pair_lease_assert_record_context() { # pair_lease_assert_record_context <lease-file> <operation>
  local file="$1" operation="$2" current_engine current_container current_site
  local recorded_engine recorded_container recorded_site
  current_engine="${WPRISM_DB_ENGINE:-mariadb}"
  current_container="${DB_CONTAINER:-}"
  current_site="$(pair_lease_site_root)"
  [ -n "$current_container" ] \
    || fail "pair lease $operation has no selected database container"
  recorded_engine="$(jq -r '.db_engine' "$file")"
  recorded_container="$(jq -r '.db_container' "$file")"
  recorded_site="$(jq -r '.site_root' "$file")"
  [ "$recorded_engine" = "$current_engine" ] && [ "$recorded_container" = "$current_container" ] \
    || fail "pair lease $operation selected database ${current_engine}/${current_container}, but the lease is bound to ${recorded_engine}/${recorded_container}; refusing before mutation"
  [ "$recorded_site" = "$current_site" ] \
    || fail "pair lease $operation came from site root $current_site, but the lease is bound to $recorded_site; refusing before mutation"
}

pair_lease_owner_start() { # pair_lease_owner_start <pid>; 0=observed, 1=dead, 2=unobservable
  local pid="$1" observed status
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 2
  if observed="$(LC_ALL=C TZ=UTC ps -o lstart= -p "$pid" 2>/dev/null)"; then
    observed="$(printf '%s\n' "$observed" | LC_ALL=C sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    [ -n "$observed" ] || return 2
    printf '%s\n' "$observed"
    return 0
  else
    status=$?
  fi
  # procps and BSD ps both use status 1 with no selected row for a PID that is
  # positively absent. Any other observation failure is not evidence of
  # death: keep its lease instead of turning a permissions/tooling failure
  # into destructive namespace authority.
  [ "$status" -eq 1 ] && [ -z "$observed" ] && return 1
  return 2
}

pair_lease_token() { # pair_lease_token <pid> <exact-process-start>
  local pid="$1" owner_start="$2"
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || fail "pair lease token owner PID is malformed"
  [ -n "$owner_start" ] || fail "pair lease token owner start identity is empty"
  command -v php >/dev/null 2>&1 || fail "pair lease token generation requires php"
  php -r '
    [$script, $pid, $start] = $argv;
    try {
        $entropy = random_bytes(32);
    } catch (Throwable $e) {
        fwrite(STDERR, "pair lease token entropy unavailable\n");
        exit(1);
    }
    echo substr(hash("sha256", $pid . "\0" . $start . "\0" . $entropy), 0, 32);
  ' "$pid" "$owner_start" || fail "could not generate collision-resistant pair lease token"
}

pair_lease_prune_stale() {
  local dir file pid recorded_start actual_start owner_status
  dir="$(pair_lease_dir)"
  mkdir -p -- "$dir" || fail "could not create pair-lease directory: $dir"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    if ! jq -e '
      type == "object" and (keys | sort) == ["created_at","db_container","db_engine","owner_pid","owner_start","ports","site_root","token"]
      and (.created_at | type == "string" and length > 0)
      and (.db_engine | type == "string" and test("^(mariadb|mysql)$"))
      and (.db_container | type == "string" and test("^[a-z0-9][a-z0-9.-]*$"))
      and (.owner_pid | type == "number" and floor == . and . > 0)
      and (.owner_start | type == "string" and length > 0)
      and (.site_root | type == "string" and startswith("/"))
      and (.token | type == "string" and test("^[a-f0-9]{32}$"))
      and (.ports | type == "array" and length == 2 and all(.[]; type == "number" and floor == .))
    ' "$file" >/dev/null 2>&1; then
      fail "pair lease is malformed; refusing to guess ownership: $file"
    fi
    pid="$(jq -r '.owner_pid' "$file")"
    recorded_start="$(jq -r '.owner_start' "$file")"
    if actual_start="$(pair_lease_owner_start "$pid")"; then
      owner_status=0
    else
      owner_status=$?
    fi
    if [ "$owner_status" -eq 1 ] || { [ "$owner_status" -eq 0 ] && [ "$actual_start" != "$recorded_start" ]; }; then
      rm -f -- "$file" || fail "could not remove stale pair lease: $file"
    elif [ "$owner_status" -ne 0 ]; then
      fail "could not observe pair lease owner PID $pid safely; refusing to prune $file"
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
      pair_lease_assert_record_context "$file" "access for pair '$name'"
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
  local db_engine db_container existing_token stage_dir staged_file publication_failed=0
  local rollback_failed=0 retained_authority=''
  local -a requests=("$@")
  local -a request_names=()
  local -a published_files=()
  [[ "$token" =~ ^[a-f0-9]{32}$ ]] || fail "pair lease token must be 32 lowercase hex characters"
  [[ "$owner_pid" =~ ^[1-9][0-9]*$ ]] || fail "pair lease owner PID is malformed"
  if ! current_start="$(pair_lease_owner_start "$owner_pid")"; then
    fail "pair lease owner process is absent or its start identity cannot be observed safely"
  fi
  [ "$current_start" = "$owner_start" ] \
    || fail "pair lease owner process has a different start identity"
  [ "$#" -gt 0 ] && [ $(( $# % 3 )) -eq 0 ] || fail "pair lease request must contain name/port pairs"
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  site_root="$(pair_lease_site_root)"
  db_engine="${WPRISM_DB_ENGINE:-mariadb}"
  db_container="${DB_CONTAINER:-}"
  [ -n "$db_container" ] || fail "pair lease acquisition has no selected database container"
  existing="$(pair_compose_all_pairs)" || fail "could not enumerate live/stopped pair names for lease preflight"
  bindings="$(pair_compose_all_bound_ports)" \
    || fail "could not enumerate live/stopped pair port bindings for lease preflight"

  shopt -s nullglob
  for file in "$dir"/*.json; do
    existing_token="$(jq -r '.token' "$file")"
    [ "$existing_token" != "$token" ] \
      || fail "pair lease token $token is already active; tokens cannot be reused across acquisitions"
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
    declare -F pair_compose_assert_project_resources_absent >/dev/null 2>&1 \
      || fail "pair lease raw Docker-resource boundary is unavailable"
    pair_compose_assert_project_resources_absent "$name"
    request_names+=("$name")
    used_names+="$name"$'\n'
  done

  # A lease is authority to let `up` CREATE IF NOT EXISTS and to let cleanup
  # DROP both schemas. The shared server uses a persistent named volume, so
  # Compose/site absence alone cannot prove that authority. Bring the selected
  # fleet server to a queryable state and census both exact schemas while the
  # caller still holds pair_budget_lock; only the empty result may cross into
  # lease publication.
  declare -F pair_db_ensure_up >/dev/null 2>&1 \
    && declare -F pair_db_assert_pair_schemas_absent >/dev/null 2>&1 \
    || fail "pair lease database absence boundary is unavailable"
  pair_db_ensure_up
  pair_db_assert_pair_schemas_absent "${request_names[@]}"

  stage_dir="$(mktemp -d "$dir/.lease-${token}.XXXXXX")" \
    || fail "could not allocate private staging for pair lease batch"
  umask 077
  set -- "${requests[@]}"
  while [ "$#" -gt 0 ]; do
    name="$1"; port1="$2"; port2="$3"; shift 3
    staged_file="$stage_dir/$name.json"
    jq -n --arg token "$token" --argjson owner_pid "$owner_pid" --arg owner_start "$owner_start" \
      --arg db_engine "$db_engine" --arg db_container "$db_container" --arg site_root "$site_root" \
      --arg created_at "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" --argjson port1 "$port1" --argjson port2 "$port2" \
      '{created_at:$created_at,db_container:$db_container,db_engine:$db_engine,owner_pid:$owner_pid,owner_start:$owner_start,ports:[$port1,$port2],site_root:$site_root,token:$token}' \
      >"$staged_file" || {
        find "$stage_dir" -depth -delete >/dev/null 2>&1 || true
        fail "could not stage complete pair lease batch; no lease was published"
      }
  done

  # Every record is complete before any becomes visible. Hard-link publication
  # is same-filesystem and refuses an already-created target; if any link
  # fails, remove only names this invocation linked and leave pre-existing
  # records untouched. The shared budget lock keeps compliant readers from
  # observing the rollback window.
  set -- "${requests[@]}"
  while [ "$#" -gt 0 ]; do
    name="$1"; shift 3
    staged_file="$stage_dir/$name.json"
    file="$dir/$name.json"
    if ln "$staged_file" "$file"; then
      published_files+=("$file")
    else
      publication_failed=1
      break
    fi
  done
  if [ "$publication_failed" -eq 1 ]; then
    for file in "${published_files[@]}"; do
      if ! rm -f -- "$file"; then
        rollback_failed=1
      fi
      if [ -e "$file" ] || [ -L "$file" ]; then
        rollback_failed=1
        retained_authority+="$file"$'\n'
      fi
    done
    find "$stage_dir" -depth -delete >/dev/null 2>&1 || true
    if [ "$rollback_failed" -eq 1 ]; then
      fail "could not publish complete pair lease batch; rollback retained cleanup authority for token $token at: ${retained_authority%$'\n'}; retain this token and resolve the named lease before any namespace mutation"
    fi
    fail "could not publish complete pair lease batch; this acquisition retained no lease"
  fi
  find "$stage_dir" -depth -delete \
    || printf 'warning: pair lease batch is active but private staging cleanup failed: %s\n' "$stage_dir" >&2
}

pair_lease_release_token() { # pair_lease_release_token <token>
  local token="$1" dir file
  local -a owned_files=()
  local -a owned_names=()
  [[ "$token" =~ ^[a-f0-9]{32}$ ]] || fail "pair lease token must be 32 lowercase hex characters"
  pair_lease_prune_stale
  dir="$(pair_lease_dir)"
  shopt -s nullglob
  for file in "$dir"/*.json; do
    if [ "$(jq -r '.token' "$file")" = "$token" ]; then
      owned_files+=("$file")
      owned_names+=("$(basename "$file" .json)")
    fi
  done
  shopt -u nullglob
  [ "${#owned_files[@]}" -gt 0 ] \
    || fail "no active pair lease exists for token $token"

  # Validate every record before touching Docker, a selected shared database,
  # site state, or the lease files themselves. A token copied into a process
  # running from a different worktree or database lane is not cleanup
  # authority for this physical namespace.
  for file in "${owned_files[@]}"; do
    pair_lease_assert_record_context "$file" "release"
  done

  # Release is the last cleanup step. Re-census under the same shared lock so
  # a successful command proves destroy removed both exact persistent schemas
  # before the lease record disappears; a failed destroy keeps the lease and
  # cannot publish a false cleanup success.
  declare -F pair_db_ensure_up >/dev/null 2>&1 \
    && declare -F pair_db_assert_pair_schemas_absent >/dev/null 2>&1 \
    || fail "pair lease database absence boundary is unavailable"
  declare -F pair_compose_assert_project_resources_absent >/dev/null 2>&1 \
    || fail "pair lease raw Docker-resource boundary is unavailable"
  local site_root name spec
  site_root="$(pair_lease_site_root)"
  for name in "${owned_names[@]}"; do
    pair_compose_assert_project_resources_absent "$name"
    for spec in \
      "$site_root/${name}1" \
      "$site_root/${name}2" \
      "$site_root/origin-${name}.git" \
      "$site_root/.${name}1.needs-install" \
      "$site_root/.${name}2.needs-install"; do
      [ ! -e "$spec" ] && [ ! -L "$spec" ] \
        || fail "pair '$name' still has site state at $spec; refusing to release cleanup authority"
    done
  done
  pair_db_ensure_up
  pair_db_assert_pair_schemas_absent "${owned_names[@]}"
  for file in "${owned_files[@]}"; do
    rm -f -- "$file" || fail "could not release pair lease: $file"
  done
}
