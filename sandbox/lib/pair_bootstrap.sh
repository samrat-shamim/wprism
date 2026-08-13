#!/usr/bin/env bash
# WordPress bootstrap state and per-side installation for one pair.
#
# This boundary owns the reset->up fact that a side's database was deliberately
# emptied, plus the idempotent core/theme/permalink/.htaccess installation it
# makes authoritative. Pair lifecycle keeps DB DROP/CREATE, container start,
# URL selection, repository roots, and teardown ordering; this library only
# turns an already-ready cli side into a verified WordPress installation.
#
# Expects the caller to define fail(), PAIR_COMPOSE, PAIR_BOOTSTRAP_ARTIFACTS,
# and (when artifact mode is selected) fetch_artifact(). `fetch_artifact` is
# deliberately loaded only by `up`: ordinary pair commands retain their
# self-contained source boundary. The inherited-environment convention is the
# same narrow one used by pair_db.sh and pair_readiness.sh.

# DUO-3412: the "this side's database was dropped out from under it" record.
# Reset writes it after a successful DROP/CREATE; bootstrap consumes it before
# asking `wp core is-installed`, because a probe against a database the pair
# itself just emptied is not authority to skip core install. The marker lives
# beside pair-owned sandbox state rather than inside a captured site repository.
pair_bootstrap_needs_install_marker() { # <name> <side (1|2)> -> marker path
  printf 'siterepo/.%s%s.needs-install\n' "$1" "$2"
}

pair_bootstrap_mark_sides_need_install() { # <name>
  local name="$1" side
  mkdir -p siterepo
  for side in 1 2; do
    : > "$(pair_bootstrap_needs_install_marker "$name" "$side")"
  done
}

pair_bootstrap_clear_needs_install_markers() { # <name>
  local name="$1" side
  for side in 1 2; do
    rm -f -- "$(pair_bootstrap_needs_install_marker "$name" "$side")"
  done
}

pair_bootstrap_write_htaccess() { # <side (1|2)>
  # wp-cli cannot write .htaccess without extra configuration; Apache needs
  # it for pretty permalinks and the authorization forwarding rule is required
  # for basic-auth REST, exactly as in the other sandbox launchers.
  "${PAIR_COMPOSE[@]}" exec -T -u www-data "wp$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
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

pair_bootstrap_install_and_activate_theme() { # <cli service> <theme slug>
  local cli="$1" theme="$2" attempt active installed_version
  if [ "${PAIR_BOOTSTRAP_ARTIFACTS:-0}" = 1 ]; then
    local artifact
    artifact=$(fetch_artifact "$theme" "$PAIR_BOOTSTRAP_THEME_VERSION" "$cli" theme) \
      || fail "theme '$theme' exact pinned artifact is unavailable"
    "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme install "$artifact" --activate --force \
      || fail "theme '$theme' exact pinned artifact could not be installed and activated"
    active=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme list --status=active --field=name) \
      || fail "theme '$theme' active-theme readback failed after pinned install"
    [ "$active" = "$theme" ] \
      || fail "theme '$theme' pinned install completed but active theme was '${active:-none}'"
    installed_version=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme get "$theme" --field=version) \
      || fail "theme '$theme' version readback failed after pinned install"
    [ "$installed_version" = "$PAIR_BOOTSTRAP_THEME_VERSION" ] \
      || fail "theme '$theme' pinned install reported version '$installed_version', expected '$PAIR_BOOTSTRAP_THEME_VERSION'"
    return 0
  fi

  # A fresh pair is an evidence boundary, but WordPress.org is not: retain the
  # bounded retry and independently prove the requested theme is active before
  # bootstrap continues. `theme activate` makes a partial install retry-safe.
  for attempt in 1 2 3; do
    if "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme install "$theme" --activate; then
      :
    elif "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme activate "$theme"; then
      printf 'Warning: theme %s was already installed after install attempt %s; activated the existing exact slug\n' \
        "$theme" "$attempt" >&2
    else
      if [ "$attempt" = 3 ]; then
        fail "theme '$theme' could not be installed and activated after 3 attempts"
      fi
      printf 'Warning: theme %s install/activation attempt %s/3 failed; retrying the exact slug\n' \
        "$theme" "$attempt" >&2
      sleep "$attempt"
      continue
    fi

    if ! active=$("${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp theme list --status=active --field=name); then
      active=""
    fi
    if [ "$active" = "$theme" ]; then
      return 0
    fi
    if [ "$attempt" = 3 ]; then
      fail "theme '$theme' install completed but active theme was '${active:-none}' after 3 attempts"
    fi
    printf "Warning: theme %s attempt %s/3 did not leave the exact slug active (got '%s'); retrying\n" \
      "$theme" "$attempt" "${active:-none}" >&2
    sleep "$attempt"
  done
}

pair_bootstrap_install_side() { # <name> <side (1|2)> <url> <title>
  local name="$1" side="$2" url="$3" title="$4" cli="cli$2" marker forced=0
  marker="$(pair_bootstrap_needs_install_marker "$name" "$side")"

  # The marker beats the probe: after reset, core install is definitionally
  # correct. Without it this retains the idempotent probe-and-skip path.
  if [ -e "$marker" ]; then
    forced=1
    echo "  side $side: $marker present — reset emptied wp_${name}${side}, so installing unconditionally (is-installed is not consulted across our own DROP)"
  elif "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core is-installed >/dev/null 2>&1; then
    echo "  side $side already installed"
    return 0
  fi

  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core install \
    --url="$url" --title="$title" \
    --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.test --skip-email
  # Clear immediately after successful install: later configuration is
  # idempotent, while retaining it after (for example) a theme-fetch failure
  # would turn the obvious retry into an already-installed refusal.
  if [ "$forced" = 1 ]; then
    rm -f -- "$marker"
  fi
  pair_bootstrap_install_and_activate_theme "$cli" twentytwentyone
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp option update permalink_structure '/%postname%/'
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp rewrite flush
  pair_bootstrap_write_htaccess "$side"
  echo "  side $side installed ($url)"

  # Assert bootstrap's premise where it is established, rather than letting a
  # later seed produce bare wp-cli "not installed" output. Keep the historic
  # diagnostic spelling for existing operators and regressions.
  "${PAIR_COMPOSE[@]}" run --rm -T "$cli" wp core is-installed >/dev/null 2>&1 \
    || fail "pair bootstrap premise failed: side $side (wp_${name}${side}) is not installed after install_side — the sweep would die at its first seed call with wp-cli's bare 'Error: The site you have requested is not installed'"
}
