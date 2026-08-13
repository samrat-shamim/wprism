#!/usr/bin/env bash
# Shared-MariaDB bring-up, readiness, admin SQL, and per-pair database
# lifecycle -- the one-server-many-databases design pair.sh's own header
# describes (wp_<name>1/wp_<name>2 on the single duo-db server, never a
# per-pair server).
#
# This library owns only the SQL/container primitives. Pair discovery, host
# budget, compose plumbing, and WordPress installation stay in pair.sh; they
# call into this narrow db-lifecycle boundary. It expects its caller to have
# already defined DB_CONTAINER, DB_ROOT_USER, DB_ROOT_PASS, and DB_COMPOSE
# (the same globals pair.sh's own "shared db" section declared before this
# extraction) and a fail() function -- the identical inherited-environment
# convention pair_budget_lock.sh already established for this file family.

pair_db_sql() { # pair_db_sql — run SQL read from stdin as root against the shared server
  # mariadb:11's image only ships the `mariadb` client binary (no `mysql`
  # symlink — confirmed empirically while authoring this: MariaDB has been
  # renaming its client tools, mysql -> mariadb, mysqldump -> mariadb-dump,
  # etc., and this image has already dropped the old names entirely).
  docker exec -i -e MYSQL_PWD="$DB_ROOT_PASS" "$DB_CONTAINER" mariadb -u"$DB_ROOT_USER"
}

pair_db_ensure_up() {
  "${DB_COMPOSE[@]}" up -d >/dev/null
  for _ in $(seq 1 60); do
    if [ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER" 2>/dev/null || true)" = "healthy" ]; then
      return 0
    fi
    sleep 2
  done
  fail "shared db ($DB_CONTAINER) never became healthy"
}

pair_db_ensure_app_user() {
  # Wildcard grant, not a per-pair user: `wp\_%` matches every wp_<name>{1,2}
  # database this or any other pair will ever create. Quoted heredoc (no
  # variable interpolation needed) so the backticks and backslash reach
  # mysql literally instead of bash trying to parse them.
  pair_db_sql <<'SQL'
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON `wp\_%`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
}

pair_db_create() { # pair_db_create <name>
  local name="$1"
  pair_db_sql <<SQL
CREATE DATABASE IF NOT EXISTS wp_${name}1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS wp_${name}2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
}

pair_db_drop() { # pair_db_drop <name>
  local name="$1"
  pair_db_sql <<SQL
DROP DATABASE IF EXISTS wp_${name}1;
DROP DATABASE IF EXISTS wp_${name}2;
SQL
}
