#!/usr/bin/env bash
# Shared-MariaDB bring-up, readiness, admin SQL, and per-pair database
# lifecycle -- the one-server-many-databases design pair.sh's own header
# describes (wp_<name>1/wp_<name>2 on the single duo-db server, never a
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

pair_db_select_engine() { # pair_db_select_engine — set DB_CONTAINER/DB_CLIENT/DB_COMPOSE/DB_LABEL from DUO_DB_ENGINE
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
  # exists to rule out (see sandbox/pair.yml's DUO_DB_HOST paragraph).
  case "${DUO_DB_ENGINE:-mariadb}" in
    mariadb)
      DB_CONTAINER=duo-shared-db
      DB_CLIENT=mariadb
      DB_COMPOSE=(docker compose -p duo-db -f db.yml)
      DB_LABEL='MariaDB (duo-db)'
      ;;
    mysql)
      DB_CONTAINER=duo-shared-mysql
      DB_CLIENT=mysql
      DB_COMPOSE=(docker compose -p duo-db-mysql -f db.mysql.yml)
      DB_LABEL='MySQL (duo-db-mysql)'
      ;;
    *)
      fail "unknown DUO_DB_ENGINE '${DUO_DB_ENGINE:-}' -- supported engines are 'mariadb' (default) and 'mysql'"
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
  # Deliberately NOT engine-conditional. mysql:8.4 defaults new accounts to
  # caching_sha2_password and ships mysql_native_password disabled, so this
  # CREATE USER may or may not produce an account the wordpress:*-php8.3
  # image's mysqlnd can authenticate against over TCP. Adding an untested
  # `IDENTIFIED WITH ...` clause now would be a speculative fallback for a
  # failure nobody has measured (AGENTS.md rule 9); the MySQL lane's first
  # live probe decides, and if it fails the clause lands here with the
  # measured error quoted beside it. Until then these bytes stay identical
  # for both engines.
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
