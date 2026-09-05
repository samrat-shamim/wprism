#!/usr/bin/env bash
# Compose invocation plumbing and compose-state discovery for one pair --
# building the `docker compose` argv a pair's own subcommands run against,
# and querying `docker compose ls` for which pairs are currently live or
# stopped. Bounded observations that wait for a condition to become true live
# in pair_readiness.sh; this library only builds invocations and answers
# point-in-time discovery queries. Identity/naming, host budget, database
# lifecycle, and WordPress installation stay in pair.sh or their own
# libraries; they call into this narrow boundary.
#
# Expects its caller to have already defined a fail() function and to have
# sourced lib/pair_identity.sh first (pair_compose_configure() calls
# pair_identity_source_root() directly) -- the same inherited-environment
# convention pair_budget_lock.sh/pair_db.sh already established for this
# file family.

# Sets the global array PAIR_COMPOSE to the full `docker compose` argv for
# pair <name>, given whichever overlay files this call wants layered in.
pair_compose_configure() { # pair_compose_configure <name> [overlay-file ...]
  local name="$1"; shift
  PAIR_COMPOSE=(docker compose -p "wprism-${name}" -f pair.yml)
  local f
  for f in "$@"; do PAIR_COMPOSE+=(-f "$f"); done
  # issue #3277: every PAIR_COMPOSE invocation needs all three source roots in
  # the environment now, not just `up` -- pair.yml
  # references them unconditionally, so `stop`/`start`/`destroy` (which
  # never went through cmd_up's own export) would otherwise hand compose
  # an EMPTY bind-mount source (":/var/www/html/...:ro", invalid spec) the
  # moment it re-parses pair.yml at all, which compose does for every
  # subcommand regardless of whether it ends up creating anything.
  # Exported HERE, the one place every subcommand already funnels through,
  # rather than duplicated at each call site (caught live: the first
  # version of this fix only set them in cmd_up and `stop` broke instantly).
  pair_identity_export_source_mounts \
    || fail "could not resolve a safe source checkout via git -- WPRISM_SOURCE_ROOT must be an exact physical worktree of this repository"
  # Database authority stays process-local. pair_db_select_engine() exports
  # the exact tuple before pair.sh reaches here; standalone default callers
  # keep pair.yml's MariaDB default. Never publish this host into shared .env:
  # a MySQL lifecycle command otherwise reroutes concurrent default-MariaDB
  # harnesses, including the legacy parents that do not select an engine.
  export WPRISM_DB_HOST="${WPRISM_DB_HOST:-wprism-shared-db}"

  # Source roots retain the existing convenience fallback for manual Compose
  # calls. Candidate-bound evidence must pin them in its parent via
  # pair_identity_export_source_mounts(); environment values outrank .env.
  # Replacing rather than appending also removes a pre-fix persisted DB host.
  printf 'WPRISM_AGENT_SRC=%s\nWPRISM_ADAPTER_PACKAGES_SRC=%s\nWPRISM_PLATFORM_SRC=%s\n' \
    "$WPRISM_AGENT_SRC" "$WPRISM_ADAPTER_PACKAGES_SRC" "$WPRISM_PLATFORM_SRC" > .env

}

pair_compose_live_pairs() { # pair_compose_live_pairs — one live pair name per line
  # Filtered by ConfigFiles (must include this sandbox's pair.yml), not by
  # project-name pattern: the legacy sandbox/docker-compose.yml's own
  # project is literally named "wprism-sandbox", which — being lowercase
  # letters only — would otherwise pass right through a naming-convention
  # filter and get miscounted as one of this redesign's own pairs.  Every
  # command in this query is checked: unavailable Docker, malformed JSON, or
  # unavailable jq is a refusal condition, never an empty list.
  local json
  json="$(docker compose ls --format json 2>/dev/null)" || return 1
  [ -n "$json" ] || return 1
  printf '%s\n' "$json" | jq -r '
    if type != "array" then error("compose ls did not return an array")
    else .[]
      | select((.ConfigFiles // "") | type == "string")
      | select((.ConfigFiles // "") | test("/pair\\.yml(,|$)"))
      | select((.Name // "") | type == "string")
      | select((.Name // "") | startswith("wprism-"))
      | .Name[7:]
    end
  '
}

pair_compose_stopped_pairs() { # pair_compose_stopped_pairs — one stopped pair name per line
  local json
  json="$(docker compose ls -a --format json 2>/dev/null)" || return 1
  [ -n "$json" ] || return 1
  printf '%s\n' "$json" | jq -r '
    if type != "array" then error("compose ls did not return an array")
    else .[]
      | select((.ConfigFiles // "") | type == "string")
      | select((.ConfigFiles // "") | test("/pair\\.yml(,|$)"))
      | select((.Status // "") | type == "string")
      | select((.Status // "") | contains("running") | not)
      | select((.Name // "") | type == "string")
      | select((.Name // "") | startswith("wprism-"))
      | .Name[7:]
    end
  '
}

pair_compose_all_pairs() { # pair_compose_all_pairs — one live/stopped pair name per line
  local json
  json="$(docker compose ls -a --format json 2>/dev/null)" || return 1
  [ -n "$json" ] || return 1
  printf '%s\n' "$json" | jq -r '
    if type != "array" then error("compose ls did not return an array")
    else .[]
      | select((.ConfigFiles // "") | type == "string")
      | select((.ConfigFiles // "") | test("/pair\\.yml(,|$)"))
      | select((.Name // "") | type == "string")
      | select((.Name // "") | startswith("wprism-"))
      | .Name[7:]
    end
  '
}

pair_compose_project_resources() { # pair_compose_project_resources <name> — raw exact-label census
  local name="$1" label containers volumes networks value
  pair_identity_validate_name "$name"
  label="com.docker.compose.project=wprism-${name}"

  # `docker compose ls` is a convenience projection, not an ownership
  # boundary: an interrupted `down` can leave only a labeled volume/network,
  # at which point the project disappears from that projection while its
  # persistent bytes remain. Query every Docker resource class directly by
  # Compose's exact project label and check every command independently.
  containers="$(docker ps -aq --filter "label=$label" 2>/dev/null)" || return 1
  volumes="$(docker volume ls -q --filter "label=$label" 2>/dev/null)" || return 1
  networks="$(docker network ls -q --filter "label=$label" 2>/dev/null)" || return 1
  for value in $containers; do printf 'container\t%s\n' "$value"; done
  for value in $volumes; do printf 'volume\t%s\n' "$value"; done
  for value in $networks; do printf 'network\t%s\n' "$value"; done
}

pair_compose_assert_project_resources_absent() { # pair_compose_assert_project_resources_absent <name>
  local name="$1" resources
  resources="$(pair_compose_project_resources "$name")" \
    || fail "could not census raw Docker resources for pair '$name'; refusing without exact project-label evidence"
  [ -z "$resources" ] \
    || fail "pair '$name' has existing raw Docker resources labeled com.docker.compose.project=wprism-${name}: $(printf '%s' "$resources" | tr '\n' ' ')"
}

pair_compose_pair_bound_ports() { # pair_compose_pair_bound_ports <name> — persisted host ports, including stopped containers
  local name="$1" container containers observed port ports=''
  containers="$(docker ps -a \
    --filter "label=com.docker.compose.project=wprism-${name}" \
    --format '{{.Names}}' 2>/dev/null)" || return 1
  for container in "wprism-${name}-wp1-1" "wprism-${name}-wp2-1"; do
    # A partially-created or headless pair may have no web container or no
    # bindings. A present container's persisted HostConfig remains readable
    # while stopped, unlike a listener-only probe.
    printf '%s\n' "$containers" | grep -Fqx -- "$container" || continue
    observed="$(docker inspect --format \
      '{{range $port, $bindings := .HostConfig.PortBindings}}{{range $bindings}}{{println .HostPort}}{{end}}{{end}}' \
      "$container" 2>/dev/null)" || return 1
    while IFS= read -r port; do
      [ -n "$port" ] || continue
      [[ "$port" =~ ^[0-9]+$ ]] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] \
        || return 1
      ports+="$port"$'\n'
    done <<<"$observed"
  done
  printf '%s' "$ports" | sort -nu
}

pair_compose_all_bound_ports() { # pair_compose_all_bound_ports — <pair><tab><port>, live or stopped
  local pairs pair ports port
  pairs="$(pair_compose_all_pairs)" || return 1
  while IFS= read -r pair; do
    [ -n "$pair" ] || continue
    ports="$(pair_compose_pair_bound_ports "$pair")" || return 1
    while IFS= read -r port; do
      [ -n "$port" ] && printf '%s\t%s\n' "$pair" "$port"
    done <<<"$ports"
  done <<<"$pairs"
  return 0
}
