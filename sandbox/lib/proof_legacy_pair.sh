#!/usr/bin/env bash
# Shared legacy-profile pair primitives for the R1 proof harnesses.
#
# The legacy R1 scenarios use sandbox/docker-compose.yml profiles rather than
# pair.sh-managed projects.  This library deliberately owns only the
# byte-identical transport, readiness, and rewrite-file helpers those
# scenarios share.  Scenario setup, reset policy, seeds, assertions, and
# lifecycle ownership stay in each proof script, so sourcing this is not a
# second harness entry point.
#
# The callers set PROOF_LEGACY_COMPOSE to the already-established scalar
# docker-compose command (for example, "docker compose -f docker-compose.yml
# --profile r1a").  Keeping it scalar preserves the historical invocation
# form used by these scripts; it is static scenario configuration, never user
# input.

proof_legacy_pair_compose() { # proof_legacy_pair_compose <compose args...>
  [ -n "${PROOF_LEGACY_COMPOSE:-}" ] || {
    echo "legacy proof pair compose command is not configured (set PROOF_LEGACY_COMPOSE before calling proof helpers)" >&2
    return 1
  }
  # Intentional unquoted expansion: the legacy proof scenarios have always
  # kept their fixed compose command as a scalar, so this preserves its exact
  # command/argument boundary without introducing eval or a parallel API.
  $PROOF_LEGACY_COMPOSE "$@"
}

proof_legacy_pair_wp_env() { # proof_legacy_pair_wp_env <r1a1|r1a2|...> <wp args...>
  local env="$1"
  shift
  proof_legacy_pair_compose run --rm -T "cli-$env" wp "$@"
}

proof_legacy_pair_wait_for() { # proof_legacy_pair_wait_for <environment>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    proof_legacy_pair_wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

proof_legacy_pair_write_htaccess() { # proof_legacy_pair_write_htaccess <environment>
  proof_legacy_pair_compose exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}
