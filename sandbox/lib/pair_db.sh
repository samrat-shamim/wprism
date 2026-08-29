#!/usr/bin/env bash
# Shared-MariaDB bring-up, readiness, admin SQL, and per-pair database
# lifecycle -- the one-server-many-databases design pair.sh's own header
# describes (wp_<name>1/wp_<name>2 on the single wprism-db server, never a
# per-pair server).
#
# This library owns only the SQL/container primitives. Pair discovery, host
# budget, compose plumbing, and WordPress installation stay in pair.sh; they
# call into this narrow db-lifecycle boundary. It expects its caller to have
# already defined DB_CONTAINER, DB_CLIENT, DB_ROOT_USER, DB_ROOT_PASS, and
# DB_COMPOSE (the same globals pair.sh's own "shared db" section declared
# before this extraction; DB_CONTAINER/DB_CLIENT/DB_COMPOSE/DB_LABEL now come
# from pair_db_select_engine() below) and a fail() function -- the identical
# inherited-environment convention pair_budget_lock.sh already established for
# this file family.

pair_db_select_engine() { # pair_db_select_engine — set DB_CONTAINER/DB_CLIENT/DB_COMPOSE/DB_LABEL from WPRISM_DB_ENGINE
  # The MySQL 8.x evidence lane (sandbox/db.mysql.yml) is a SECOND shared
  # server in its own compose project, so selecting it is a matter of which
  # container/client/project a pair talks to -- never an edit to db.yml, which
  # would move every other agent's in-flight pairs onto MySQL mid-run.
  #
  # `mariadb` is the default and reproduces the exact four values pair.sh
  # hard-coded before this function existed (pair.sh's shared-db constants),
  # including DB_LABEL, whose value is the literal string cmd_up already
  # printed -- so nothing on the default path moves a byte (AGENTS.md rule 8).
  #
  # An unrecognised value REFUSES rather than falling back to mariadb: a typo'd
  # engine that silently produced MariaDB evidence while the operator believed
  # they were measuring MySQL is precisely the wrong-engine hazard this lane
  # exists to rule out (see sandbox/pair.yml's WPRISM_DB_HOST paragraph).
  case "${WPRISM_DB_ENGINE:-mariadb}" in
    mariadb)
      DB_CONTAINER=wprism-shared-db
      DB_CLIENT=mariadb
      DB_COMPOSE=(docker compose -p wprism-db -f db.yml)
      DB_LABEL='MariaDB (wprism-db)'
      ;;
    mysql)
      DB_CONTAINER=wprism-shared-mysql
      DB_CLIENT=mysql
      DB_COMPOSE=(docker compose -p wprism-db-mysql -f db.mysql.yml)
      DB_LABEL='MySQL (wprism-db-mysql)'
      ;;
    *)
      fail "unknown WPRISM_DB_ENGINE '${WPRISM_DB_ENGINE:-}' -- supported engines are 'mariadb' (default) and 'mysql'"
      ;;
  esac
}

pair_db_sql() { # pair_db_sql — run SQL read from stdin as root against the shared server
  # mariadb:11's image only ships the `mariadb` client binary (no `mysql`
  # symlink — confirmed empirically while authoring this: MariaDB has been
  # renaming its client tools, mysql -> mariadb, mysqldump -> mariadb-dump,
  # etc., and this image has already dropped the old names entirely). The
  # converse holds for the MySQL evidence lane's server: mysql:8.4 ships
  # `mysql` and has no `mariadb` binary at all, so the client name is the one
  # thing that cannot be shared between the two images. DB_CLIENT is set by
  # pair_db_select_engine(); the `:-mariadb` default keeps this argv exactly
  # what it was for any caller that sources this library without selecting an
  # engine.
  docker exec -i -e MYSQL_PWD="$DB_ROOT_PASS" "$DB_CONTAINER" "${DB_CLIENT:-mariadb}" -u"$DB_ROOT_USER"
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
  #
  # Engine-conditional SINCE the MySQL lane's first live probe ran and
  # decided (2026-08-24), exactly as the earlier note here said it would.
  # Measured: mysqlnd (WordPress itself) authenticates against a
  # caching_sha2_password account fine, but the wordpress:cli image's MariaDB
  # 11.8 shell client — every `wp db query/check/export/import` — cannot:
  # "ERROR 1045 ... Plugin caching_sha2_password could not be loaded: Error
  # loading shared library /usr/lib/mariadb/plugin/caching_sha2_password.so:
  # No such file or directory" (after the TLS-verify default was dealt with:
  # "ERROR 2026 ... self-signed certificate in certificate chain" with server
  # TLS on, "SSL is required, but the server does not support it" with it
  # off). The lane therefore pins the app account to mysql_native_password,
  # which db.mysql.yml enables server-side (--mysql-native-password=ON) and
  # the MariaDB client speaks; the ALTER converges an account a pre-fix run
  # already created under the default plugin. The MariaDB arm is the
  # pre-existing bytes, untouched.
  if [ "${DB_CONTAINER:-wprism-shared-db}" = "wprism-shared-mysql" ]; then
    pair_db_sql <<'SQL'
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED WITH mysql_native_password BY 'wordpress';
ALTER USER 'wordpress'@'%' IDENTIFIED WITH mysql_native_password BY 'wordpress';
GRANT ALL PRIVILEGES ON `wp\_%`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
  else
    pair_db_sql <<'SQL'
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT ALL PRIVILEGES ON `wp\_%`.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
SQL
  fi
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
