#!/usr/bin/env bash
# Pair readiness primitives shared by pair orchestration.
#
# This library owns only bounded observation waits after the caller has made a
# lifecycle transition: Compose project visibility after resume, the nested
# Duo MU bind mountpoints before CLI containers are created, and a real
# database query through each side's CLI container. Compose invocation
# construction/state discovery remains in pair_compose.sh; shared-MariaDB
# startup remains in pair_db.sh; WordPress installation stays in pair.sh.
#
# The caller supplies PAIR_COMPOSE and fail(), and must source
# pair_compose.sh first because pair_readiness_wait_pair_visible() consumes
# pair_compose_live_pairs(). This is the inherited-environment convention
# already used by the sibling pair libraries, kept explicit so this library
# cannot accidentally become an alternate lifecycle entry point.

pair_readiness_wait_pair_visible() { # pair_readiness_wait_pair_visible <name>
  local name="$1" live
  for _ in $(seq 1 60); do
    if ! live="$(pair_compose_live_pairs)"; then
      fail "could not verify pair '$name' became live after compose start"
    fi
    if printf '%s\n' "$live" | grep -Fqx -- "$name"; then
      return 0
    fi
    sleep 1
  done
  fail "pair '$name' did not become visible in Compose after start"
}

pair_readiness_wait_db() { # pair_readiness_wait_db <name> <side (1|2)>
  # THE readiness fix task #74 called for: a check that actually requires
  # the database to be reachable, run through this pair's own cli
  # container (so it also proves the network path and WORDPRESS_DB_* creds
  # are right) — not `wp core version`, which is a static-file read that
  # would happily report "ready" even if the database were unreachable.
  # `wp db query "SELECT 1"` works against a database that exists but has
  # zero tables yet (our exact state right after CREATE DATABASE, before
  # `core install` has run), unlike `wp db check` (mysqlcheck), which
  # checks tables and would have nothing to check yet.
  local name="$1" side="$2"
  for _ in $(seq 1 90); do
    if "${PAIR_COMPOSE[@]}" run --rm -T "cli${side}" wp db query "SELECT 1" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  fail "env ${name}${side} never reached its database"
}

pair_readiness_wait_web_mountpoints() { # pair_readiness_wait_web_mountpoints <name>
  # pair.yml mounts the shared wp-content tree and then mounts the Duo MU
  # directory/file underneath that named volume. Starting cli1/cli2 in the
  # same compose transaction races Docker's volume initialization: a CLI
  # container can try to create the nested file mountpoint while the first
  # web container is still populating the volume. The web services own that
  # initialization; wait until both can see the nested mounts before asking
  # Compose to create the CLI services.
  local name="$1" side all_ready mount_check
  mount_check='test -d /var/www/html/wp-content/mu-plugins/duo && test -f /var/www/html/wp-content/mu-plugins/duo-loader.php'
  for _ in $(seq 1 60); do
    all_ready=1
    for side in 1 2; do
      if ! "${PAIR_COMPOSE[@]}" exec -T "wp$side" sh -c "$mount_check" >/dev/null 2>&1; then
        all_ready=0
      fi
    done
    if [ "$all_ready" = 1 ]; then
      return 0
    fi
    sleep 1
  done
  fail "env ${name} web containers never exposed the nested Duo MU mountpoints"
}
