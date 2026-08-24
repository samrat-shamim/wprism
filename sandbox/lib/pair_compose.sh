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
  PAIR_COMPOSE=(docker compose -p "duo-${name}" -f pair.yml)
  local f
  for f in "$@"; do PAIR_COMPOSE+=(-f "$f"); done
  # DUO-3277: every PAIR_COMPOSE invocation needs DUO_AGENT_SRC/
  # DUO_MANIFESTS_SRC in the environment now, not just `up` -- pair.yml
  # references them unconditionally, so `stop`/`start`/`destroy` (which
  # never went through cmd_up's own export) would otherwise hand compose
  # an EMPTY bind-mount source (":/var/www/html/...:ro", invalid spec) the
  # moment it re-parses pair.yml at all, which compose does for every
  # subcommand regardless of whether it ends up creating anything.
  # Exported HERE, the one place every subcommand already funnels through,
  # rather than duplicated at each call site (caught live: the first
  # version of this fix only set them in cmd_up and `stop` broke instantly).
  local root="${PAIR_SOURCE_ROOT:-}"
  if [ -z "$root" ]; then
    if ! root="$(pair_identity_source_root)"; then
      fail "could not resolve a safe source checkout via git -- DUO_SOURCE_ROOT must be an exact physical worktree of this repository"
    fi
    PAIR_SOURCE_ROOT="$root"
  fi
  export DUO_AGENT_SRC="$root/agent" DUO_MANIFESTS_SRC="$root/manifests"
  # Which shared database server pair.yml:94 renders into WORDPRESS_DB_HOST.
  # pair.sh exports it once at load from DB_CONTAINER (pair_db_select_engine()),
  # so it is already set for EVERY subcommand by the time they funnel through
  # here -- the same reason the two source paths above are exported here rather
  # than in cmd_up, and for the same measured failure: this rewrites .env on
  # every call, so a cmd_up-only export let `stop` on a mysql-lane pair put the
  # MariaDB host back in the file the next subprocess compose call reads.
  # The duo-shared-db default remains for a caller that sources this library
  # without pair.sh at all; it matches pair.yml's own `${DUO_DB_HOST:-...}` so
  # such a caller renders byte-identically to before the MySQL lane existed.
  export DUO_DB_HOST="${DUO_DB_HOST:-duo-shared-db}"

  # DUO-3277 (CI caught this the first version above missed): that export
  # only reaches pair.sh's OWN "${PAIR_COMPOSE[@]}" calls -- it dies with
  # this process and never reaches the many OTHER scripts (sandbox/
  # conformance/run.sh, every regress_*.sh/grind_*.sh) that invoke `pair.sh
  # up` once as a subprocess and then make their own separate, direct
  # `docker compose -f pair.yml ...` calls afterward (confirmed: that's how
  # essentially every one of them actually works, not a hypothetical edge
  # case -- see run.sh's own $COMPOSE + its wp_env() helper). Those scripts
  # already re-export DUO_PAIR/DUO_PORT1/DUO_PORT2 themselves for the same
  # process-boundary reason (see run.sh's comment by its own export line),
  # but making every caller duplicate canonical_root()'s git logic too
  # would be fragile -- easy to add a new call site and forget it, with no
  # loud failure until that exact path runs.
  #
  # Persist the same three values to sandbox/.env instead, in addition to the
  # export above: docker compose auto-loads a file by that exact name from
  # the CWD (verified live with `env -i` stripping every inherited
  # variable -- compose still resolved both mounts correctly from .env
  # alone), and every caller in this codebase already `cd`s into sandbox/
  # before making its own compose calls (this script's own line 45 above;
  # run.sh's equivalent). One write here, in the single choke point every
  # subcommand already funnels through, covers every current AND future
  # caller with zero changes to any of them. Overwritten (never appended)
  # so a stale value can never survive a worktree/checkout change; safe
  # under concurrent pair.sh invocations against the same checkout too,
  # Each worktree has its own sandbox/.env, so concurrent worktree writers do
  # not share this file. Callers in one worktree always agree on its selected
  # source root.
  #
  # DUO_DB_HOST rides this same channel for the same process-boundary reason,
  # with a sharper failure mode than a broken mount: a pair brought up on the
  # MySQL evidence lane whose conformance/regress subprocesses then re-rendered
  # pair.yml's duo-shared-db default would run GREEN against MariaDB while the
  # operator recorded it as MySQL evidence -- wrong-engine evidence is worse
  # than no evidence.
  printf 'DUO_AGENT_SRC=%s\nDUO_MANIFESTS_SRC=%s\nDUO_DB_HOST=%s\n' "$DUO_AGENT_SRC" "$DUO_MANIFESTS_SRC" "$DUO_DB_HOST" > .env

  # The db-client lane config pair.yml mounts at /etc/mysql/my.cnf in the wp
  # and cli containers, written beside .env at the same choke point for the
  # same process-boundary reason. MariaDB lane: comment-only, so the default
  # path's client behavior does not move by a byte. MySQL lane: skip-ssl,
  # because the image's MariaDB 11.8 client verifies server TLS by default
  # (11.4+ change) and MySQL 8.4's auto-generated self-signed cert fails every
  # shell-client call (`wp db query` -> ERROR 2026, measured 2026-08-24)
  # while WordPress's own mysqlnd path connects; MySQL 8.4 accepts cleartext
  # unless require_secure_transport=ON, which the lane's server does not set.
  # Recorded product implication (reported, not fixed here): `wp db
  # export/import` -- the checkpoint path -- shells out to the same client
  # family and hits the same refusal on TLS-verifying clients against
  # self-signed servers.
  if [ "$DUO_DB_HOST" = "duo-shared-mysql" ]; then
    printf '# duo mysql evidence lane (pair_compose_configure)\n[client]\nskip-ssl\n' > .duo-db-client.cnf
  else
    printf '# duo db-client lane config: comment-only on the MariaDB lane\n' > .duo-db-client.cnf
  fi
}

pair_compose_live_pairs() { # pair_compose_live_pairs — one live pair name per line
  # Filtered by ConfigFiles (must include this sandbox's pair.yml), not by
  # project-name pattern: the legacy sandbox/docker-compose.yml's own
  # project is literally named "duo-sandbox", which — being lowercase
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
      | select((.Name // "") | startswith("duo-"))
      | .Name[4:]
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
      | select((.Name // "") | startswith("duo-"))
      | .Name[4:]
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
      | select((.Name // "") | startswith("duo-"))
      | .Name[4:]
    end
  '
}

pair_compose_pair_bound_ports() { # pair_compose_pair_bound_ports <name> — persisted host ports, including stopped containers
  local name="$1" container containers observed port ports=''
  containers="$(docker ps -a \
    --filter "label=com.docker.compose.project=duo-${name}" \
    --format '{{.Names}}' 2>/dev/null)" || return 1
  for container in "duo-${name}-wp1-1" "duo-${name}-wp2-1"; do
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
