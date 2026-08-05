#!/usr/bin/env bash
# Boot envs A (:8801) and B (:8802), install WordPress, init the site repo.
set -euo pipefail
cd "$(dirname "$0")"
COMPOSE="docker compose -f docker-compose.yml"

mkdir -p siterepo/a siterepo/b siterepo/c tmp
$COMPOSE up -d db-a wp-a db-b wp-b

wp_env() { # wp_env <a|b> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}

wait_for() { # wait_for <a|b>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    if wp_env "$env" core version >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

# wp-cli can't write .htaccess without extra config; apache needs it for pretty
# permalinks, and the HTTP_AUTHORIZATION line is required for basic-auth REST.
write_htaccess() { # write_htaccess <env>
  $COMPOSE exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
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

install_env() { # install_env <a|b> <port> <title>
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    wp_env "$env" theme install twentytwentyone --activate
    wp_env "$env" option update permalink_structure '/%postname%/'
    wp_env "$env" rewrite flush
    write_htaccess "$env"
    wp_env "$env" site empty --yes
    echo "env $env installed"
  else
    echo "env $env already installed"
  fi
}

install_env a 8801 "Duo A"
install_env b 8802 "Duo B"

if [ ! -d siterepo/origin.git ]; then
  git init --bare -b main siterepo/origin.git >/dev/null
fi
if [ ! -d siterepo/a/.git ]; then
  git clone -q siterepo/origin.git siterepo/a 2>/dev/null || true
  cat > siterepo/a/site.duo.json <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 0
}
EOF
  printf '.tmp*\n' > siterepo/a/.gitignore
  git -C siterepo/a add -A
  git -C siterepo/a -c user.name=duo -c user.email=duo@example.test commit -qm "init site repo"
  git -C siterepo/a push -qu origin main
fi

echo
echo "sandbox ready: A=http://localhost:8801  B=http://localhost:8802 (both admin/admin)"
