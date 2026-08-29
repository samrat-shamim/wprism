#!/bin/sh
# Lane shim for the MariaDB shell client family inside the pair's wp/cli
# containers, mounted by pair.yml at /usr/local/bin/{mysql,mysqldump,mysqlcheck}
# (PATH-earlier than the image's /usr/bin binaries).
#
# Why it exists (measured 2026-08-24, MySQL evidence lane): the wordpress:cli
# image ships a MariaDB 11.8 client whose 11.4+ defaults REQUIRE TLS and VERIFY
# the server certificate. Against MariaDB 11.8 that works zero-config (the
# server cert is validated through the authentication exchange). Against the
# lane's MySQL 8.4 server every combination fails: with the server's
# auto-generated cert, "ERROR 2026 ... self-signed certificate in certificate
# chain"; with server TLS disabled, "ERROR 2026 ... SSL is required, but the
# server does not support it". No config file can opt out because WP-CLI's db
# commands run the client with --no-defaults, and its internal SQL-modes
# preamble ignores per-command pass-through flags entirely.
#
# The shim inserts --skip-ssl ONLY when this container's own WORDPRESS_DB_HOST
# names the MySQL evidence server, so the MariaDB lane's invocations reach the
# real client byte-identically. --no-defaults must stay the first argument when
# present (the client refuses it elsewhere).
#
# Recorded PRODUCT finding this shim deliberately does NOT hide: the checkpoint
# path (`wp db export` / `wp db import` in promote, deploy and recover) shells
# out to this same client family, so a real MySQL target reached from a host
# with a MariaDB 11.4+ client hits the same refusal. That belongs to the
# platform/adoption work, not to this estate file.
base="$(basename "$0")"
real="/usr/bin/$base"
if [ "${WORDPRESS_DB_HOST:-}" = "wprism-shared-mysql" ]; then
  if [ "${1:-}" = "--no-defaults" ]; then
    shift
    exec "$real" --no-defaults --skip-ssl "$@"
  fi
  exec "$real" --skip-ssl "$@"
fi
exec "$real" "$@"
