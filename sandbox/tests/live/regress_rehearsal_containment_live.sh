#!/usr/bin/env bash
# Three distinct environments: pair side 1 is the production-derived source,
# the standalone internal Compose project is rehearsal preview, and pair side
# 2 is an independent release target that must remain byte/state unchanged.
# Not an offline target: Docker topology, route denial and real PHP mail/DB
# behavior are the evidence this lane exists to measure.
set -euo pipefail

TOPOLOGY_ONLY=0
if [ "${1:-}" = "--topology-only" ]; then
  TOPOLOGY_ONLY=1
  shift
fi
[ "$#" -eq 0 ] || { printf 'usage: %s [--topology-only]\n' "$0" >&2; exit 2; }

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
SANDBOX="$ROOT/sandbox"
PAIR=$([ "$TOPOLOGY_ONLY" -eq 1 ] && printf containprobe || printf containlive)
PORT1=9340
PORT2=9341
PREVIEW_PORT=9342
SHARED_DB_PORT="${WPRISM_CONTAINMENT_DB_PORT:-3317}"
HOST_PROBE_PORT=
SOURCE_ENV="${PAIR}1"
PREVIEW_ENV="${PAIR}2"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-containment-live.XXXXXX")"
STATE="$TMP/provider-state"
CONFIG="$TMP/provider.json"
RECEIPT="$TMP/receipt.json"
COMPOSE_ROOT="$TMP/compose"
PAIR_OWNED=0
PREVIEW_OWNED=0
HOST_PROBE_PID=
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
export WPRISM_CLI_IMAGE="${WPRISM_CLI_IMAGE:-wordpress:cli-php8.3}"
export WPRISM_SHARED_DB_PORT="$SHARED_DB_PORT"

pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pair_wp() {
  local side=$1
  shift
  (cd "$SANDBOX" && WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2" \
    WPRISM_CLI_IMAGE="$WPRISM_CLI_IMAGE" docker compose -p "wprism-$PAIR" -f pair.yml run --rm -T "cli$side" wp "$@")
}
preview_compose() {
  (cd "$SANDBOX" && docker compose --env-file "$STATE/contained-preview.env" \
    -p "wprism-$PAIR-preview" -f contained-preview.yml --profile cli "$@")
}
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  if [ -n "$HOST_PROBE_PID" ]; then
    kill "$HOST_PROBE_PID" >/dev/null 2>&1 || true
    wait "$HOST_PROBE_PID" >/dev/null 2>&1 || true
  fi
  if [ "$status" -ne 0 ] && [ -s "$STATE/provider-errors.log" ]; then
    printf '%s\n' 'provider diagnostics:' >&2
    sed 's/^/  /' "$STATE/provider-errors.log" >&2
  fi
  if [ "$status" -ne 0 ] && docker inspect "wprism-$PAIR-preview-proxy-1" >/dev/null 2>&1; then
    docker logs "wprism-$PAIR-preview-proxy-1" >&2 || true
  fi
  if [ "$PREVIEW_OWNED" -eq 1 ] && [ -f "$RECEIPT" ]; then
    php "$ROOT/sandbox/tests/fixtures/rehearse/contained-provider-live-cycle.php" reap "$CONFIG" "$RECEIPT" >/dev/null 2>&1 || status=1
  fi
  if [ -f "$STATE/contained-preview.env" ]; then
    preview_compose down --volumes --remove-orphans >/dev/null 2>&1 || true
  fi
  if [ "$PAIR_OWNED" -eq 1 ]; then
    bash "$SANDBOX/bin/pair.sh" destroy "$PAIR" >/dev/null 2>&1 || status=1
    rm -rf -- "$SANDBOX/siterepo/${PAIR}1" "$SANDBOX/siterepo/${PAIR}2" || status=1
  fi
  [ ! -d "$TMP" ] || chmod -R u+w "$TMP" >/dev/null 2>&1 || true
  rm -rf -- "$TMP"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

command -v docker >/dev/null || fail 'docker is required'
docker info >/dev/null 2>&1 || fail 'the Docker daemon is unavailable'
php "$ROOT/sandbox/tests/fixtures/rehearse/known-open-host-listener.php" "$TMP/host-probe.port" \
  > "$TMP/host-probe.log" 2>&1 &
HOST_PROBE_PID=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do
  [ -s "$TMP/host-probe.port" ] && break
  sleep 0.1
done
HOST_PROBE_PORT="$(tr -d '\r\n' < "$TMP/host-probe.port" 2>/dev/null || true)"
[[ "$HOST_PROBE_PORT" =~ ^[1-9][0-9]{0,4}$ ]] || fail 'known-open host probe listener published no port'
php -r '$s=@fsockopen("127.0.0.1",(int)$argv[1],$e,$m,1);if(!is_resource($s))exit(1);fclose($s);' "$HOST_PROBE_PORT" \
  || fail 'known-open host probe listener is not reachable from the host'
if docker ps -a --filter "label=com.docker.compose.project=wprism-$PAIR-preview" -q | grep -q .; then
  fail "contained preview project wprism-$PAIR-preview already exists"
fi
mkdir -p "$STATE"
chmod 0700 "$STATE"
mkdir -p "$COMPOSE_ROOT/siterepo/${PAIR}1" "$COMPOSE_ROOT/siterepo/${PAIR}2"
printf '# provider replaces this placeholder during create\n' > "$STATE/contained-preview.env"
chmod 0600 "$STATE/contained-preview.env"

if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  WPRISM_SOURCE_ROOT="$ROOT" WPRISM_EXPECTED_SOURCE_SHA="$(git -C "$ROOT" rev-parse HEAD)" \
    bash "$SANDBOX/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --http
  PAIR_OWNED=1
  pair_wp 1 option update wprism_containment_marker source-canary --quiet
  pair_wp 2 option update wprism_containment_marker release-target-canary --quiet
  pair_wp 1 option update wprism_payment_token source-payment-secret-live --quiet
  pair_wp 1 option update wprism_mail_token source-mail-secret-live --quiet
  pair_wp 2 option update wprism_payment_token independent-target-payment-secret-live --quiet
  pair_wp 2 option update wprism_mail_token independent-target-mail-secret-live --quiet
  SOURCE_AUTH_USER_ID="$(pair_wp 1 user create containment-source-user source-auth@example.invalid --user_pass=source-auth-password-live --porcelain)"
  pair_wp 1 user meta update "$SOURCE_AUTH_USER_ID" session_tokens source-session-token-live >/dev/null
  pair_wp 1 user meta update "$SOURCE_AUTH_USER_ID" _application_passwords source-application-password-live >/dev/null
  pair_wp 1 db query "UPDATE wp_users SET user_activation_key='source-activation-key-live' WHERE ID=$SOURCE_AUTH_USER_ID" --quiet
  pair_wp 2 user create independent-target-user target-auth@example.invalid --user_pass=independent-target-password-live --porcelain >/dev/null
  pair_wp 1 eval 'wp_mkdir_p(WP_CONTENT_DIR."/uploads/private");file_put_contents(WP_CONTENT_DIR."/uploads/private/source-media-secret.txt","source-media-secret-live\n");'
  SOURCE_BEFORE="$(pair_wp 1 option get wprism_containment_marker)"
  TARGET_BEFORE="$(pair_wp 2 option get wprism_containment_marker)"
  [ "$SOURCE_BEFORE" = source-canary ] || fail 'source marker was not seeded'
  [ "$TARGET_BEFORE" = release-target-canary ] || fail 'release-target marker was not seeded'
  pass 'source and independent release target carry distinct canaries and credentials'
fi

POLICY="$TMP/sanitization-policy.json"
php -r '
$policy=[
 "format"=>"wprism-reference-snapshot-sanitization-policy/v1",
 "source_environment"=>$argv[2],
 "assertion"=>["credential_inventory"=>"exhaustive","review_id"=>"contained-live-review-0001","revision"=>1],
 "database"=>["table_prefix"=>"wp_","options"=>[
  ["action"=>"replace","name"=>"wprism_payment_token","replacement"=>"sandbox-payment-disabled"],
  ["action"=>"replace","name"=>"wprism_mail_token","replacement"=>"sandbox-mail-disabled"],
 ]],
 "media"=>[["action"=>"remove","path"=>"private/source-media-secret.txt"]],
 "wordpress_auth"=>[
  "activation_key_replacement"=>"","password_replacement"=>"!wprism-sandbox-disabled!",
  "remove_usermeta_keys"=>["_application_passwords","session_tokens"],
 ],
];file_put_contents($argv[1],json_encode($policy,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");chmod($argv[1],0600);
' "$POLICY" "$SOURCE_ENV"

php -r '
$root=$argv[1];$sandbox=$root."/sandbox";$pair=$argv[2];$state=$argv[3];$port1=(int)$argv[4];$preview=(int)$argv[5];$compose=$argv[8];
$config=[
 "format"=>"wprism-reference-env-provider-config/v1","pair"=>$pair,
 "pair_script"=>$sandbox."/bin/pair.sh","compose_dir"=>$compose,
 "compose_files"=>[$sandbox."/pair.yml",$sandbox."/pair.http.yml"],
 "controller_repo"=>$argv[6],"db_container"=>"wprism-shared-db","state_root"=>$state,
 "source_environment"=>$pair."1","destroy_scope"=>"side","withheld_capabilities"=>[],
 "environments"=>[
  $pair."1"=>["role"=>"source","side"=>1,"port"=>$port1,"container"=>"wprism-$pair-wp1-1","service"=>"cli1","database"=>"wp_{$pair}1","repo"=>$compose."/siterepo/{$pair}1"],
  $pair."2"=>["role"=>"target","side"=>2,"port"=>$preview,"container"=>"wprism-$pair-preview-wp-1","service"=>"cli","database"=>"wprism_preview","repo"=>$compose."/siterepo/{$pair}2"],
 ],
 "contained_preview"=>[
  "format"=>"wprism-reference-contained-preview/v1","compose_file"=>$sandbox."/contained-preview.yml",
  "project"=>"wprism-$pair-preview","network"=>"wprism-$pair-preview-internal",
  "ingress_network"=>"wprism-$pair-preview-ingress",
  "database_container"=>"wprism-$pair-preview-db-1","database_service"=>"db",
  "proxy_container"=>"wprism-$pair-preview-proxy-1","proxy_service"=>"proxy",
  "wordpress_service"=>"wp","cli_service"=>"cli","database"=>"wprism_preview",
  "wordpress_image"=>"wordpress:7.1-php8.3-apache","cli_image"=>"wordpress:cli-php8.3","database_image"=>"mariadb:11","proxy_image"=>"nginx:1.29-alpine",
  "cron_guard"=>$sandbox."/containment/block-cron.php","mail_shim"=>$sandbox."/containment/refuse-sendmail.sh","php_ini"=>$sandbox."/containment/php.ini",
  "proxy_config"=>$sandbox."/containment/nginx.conf",
  "sanitization_policy"=>$argv[9],"sanitization_policy_sha256"=>hash_file("sha256",$argv[9]),
  "runtime_sources"=>["adapter_packages"=>$root."/adapter-packages","agent"=>$root."/agent","platform"=>$root."/platform"],
 ],
];file_put_contents($argv[7],json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
' "$ROOT" "$PAIR" "$STATE" "$PORT1" "$PREVIEW_PORT" "$TMP/origin.git" "$CONFIG" "$COMPOSE_ROOT" "$POLICY"

LIVE_MODE=materialize
[ "$TOPOLOGY_ONLY" -eq 0 ] || LIVE_MODE=probe
php "$ROOT/sandbox/tests/fixtures/rehearse/contained-provider-live-cycle.php" "$LIVE_MODE" "$CONFIG" "$RECEIPT" > "$TMP/materialize.json"
PREVIEW_OWNED=1
php -r '
$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
foreach(["credential_isolation","http_egress_default_denied","mail_default_denied","payment_default_denied","queue_default_denied","webhook_default_denied"] as $k){if(($d["containment"][$k]??null)!==true)exit(1);}
if(($d["containment"]["profile"]??null)!=="agency-rehearsal-v1")exit(1);
' "$RECEIPT" || fail 'materialization returned no complete containment proof'
if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  pass 'source snapshot restored only after a target/lease/fence-bound containment proof'
else
  pass 'standalone target/lease/fence-bound containment proof passed against the real Docker daemon'
fi

if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  PREVIEW_MARKER="$(preview_compose run --rm --no-deps -T cli wp option get wprism_containment_marker)"
  [ "$PREVIEW_MARKER" = source-canary ] || fail 'preview did not receive the source snapshot'
  PREVIEW_PAYMENT="$(preview_compose run --rm --no-deps -T cli wp option get wprism_payment_token)"
  PREVIEW_MAIL="$(preview_compose run --rm --no-deps -T cli wp option get wprism_mail_token)"
  [ "$PREVIEW_PAYMENT" = sandbox-payment-disabled ] || fail 'preview payment credential was not rebound to the reviewed sandbox handle'
  [ "$PREVIEW_MAIL" = sandbox-mail-disabled ] || fail 'preview mail credential was not rebound to the reviewed sandbox handle'
  [ "$PREVIEW_PAYMENT" != source-payment-secret-live ] && [ "$PREVIEW_PAYMENT" != independent-target-payment-secret-live ] \
    || fail 'preview can read a source or independent-target payment credential'
  [ "$PREVIEW_MAIL" != source-mail-secret-live ] && [ "$PREVIEW_MAIL" != independent-target-mail-secret-live ] \
    || fail 'preview can read a source or independent-target mail credential'
  docker exec "wprism-$PAIR-preview-wp-1" test ! -e /var/www/html/wp-content/uploads/private/source-media-secret.txt \
    || fail 'preview can read the reviewed source media credential'
  preview_compose run --rm --no-deps -T cli wp eval '
$u=wp_authenticate("containment-source-user","source-auth-password-live");if(!is_wp_error($u))exit(51);
$u=wp_authenticate("containment-source-user","independent-target-password-live");if(!is_wp_error($u))exit(52);
$u=get_user_by("login","containment-source-user");if(!$u||$u->user_pass!=="!wprism-sandbox-disabled!"||$u->user_activation_key!=="")exit(53);
' || fail 'a source/target password authenticates in preview or WordPress auth fields were not disabled'
  if preview_compose run --rm --no-deps -T cli wp user meta get containment-source-user session_tokens >/dev/null 2>&1; then
    fail 'preview retained a source WordPress session token'
  fi
  if preview_compose run --rm --no-deps -T cli wp user meta get containment-source-user _application_passwords >/dev/null 2>&1; then
    fail 'preview retained a source WordPress application password'
  fi
  if preview_compose run --rm --no-deps -T cli wp user get independent-target-user >/dev/null 2>&1; then
    fail 'preview contains the independent-target user authority'
  fi
  pass 'reviewed app/media credentials and WordPress auth/session authority are absent or disabled; independent-target credentials never enter preview'
fi
INTERNAL_GATEWAY="$(docker network inspect "wprism-$PAIR-preview-internal" --format '{{(index .IPAM.Config 0).Gateway}}')"
docker exec "wprism-$PAIR-preview-wp-1" php -r '
foreach([["1.1.1.1",443],["host.docker.internal",(int)$argv[1]],[$argv[2],(int)$argv[1]]] as [$h,$p]){$s=@fsockopen($h,$p,$e,$m,1);if(is_resource($s)){fclose($s);exit(40);}}
' -- "$HOST_PROBE_PORT" "$INTERNAL_GATEWAY" || fail 'WordPress reached external, host, or internal-bridge-gateway ingress'
preview_compose run --rm --no-deps -T cli php -r '
foreach([["1.1.1.1",443],["host.docker.internal",(int)$argv[1]],[$argv[2],(int)$argv[1]]] as [$h,$p]){$s=@fsockopen($h,$p,$e,$m,1);if(is_resource($s)){fclose($s);exit(40);}}
mysqli_report(MYSQLI_REPORT_OFF);$db=@mysqli_connect("wprism-shared-db","wordpress","wordpress","wp_containlive1");if($db){mysqli_close($db);exit(41);}exit(@mail("sink@example.invalid","live containment","refuse")?42:0);
' -- "$HOST_PROBE_PORT" "$INTERNAL_GATEWAY" || fail 'preview reached an external, host, bridge-gateway, mail, or source-DB destination'
docker exec "wprism-$PAIR-preview-wp-1" test -s /tmp/wprism-mail-capture.ndjson \
  || fail 'WordPress mail refusal left no local capture witness'
pass 'WP and CLI deny Internet, known-open host and bridge-gateway access plus source DB; mail is locally captured/refused'

NETWORK_JSON="$(docker network inspect "wprism-$PAIR-preview-internal")"
php -r '
$n=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR)[0];if(($n["Internal"]??null)!==true)exit(1);
$names=array_column($n["Containers"]??[],"Name");sort($names);$want=[$argv[2],$argv[3],$argv[4]];sort($want);if($names!==$want)exit(1);
' "$NETWORK_JSON" "wprism-$PAIR-preview-db-1" "wprism-$PAIR-preview-wp-1" "wprism-$PAIR-preview-proxy-1" \
  || fail 'preview internal network has a source, release-target, or foreign attachment'
INGRESS_JSON="$(docker network inspect "wprism-$PAIR-preview-ingress")"
php -r '
$n=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR)[0];if(($n["Internal"]??null)!==false)exit(1);if(($n["Options"]["com.docker.network.bridge.enable_ip_masquerade"]??null)!=="false")exit(1);
$names=array_column($n["Containers"]??[],"Name");if($names!==[$argv[2]])exit(1);
' "$INGRESS_JSON" "wprism-$PAIR-preview-proxy-1" || fail 'credential-free proxy is not the sole ingress attachment'
pass 'WP/CLI/DB stay internal; only the credential-free proxy attaches to dedicated ingress'

if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  preview_compose run --rm --no-deps -T cli wp cron event schedule wprism_containment_cron_canary now >/dev/null
fi
CRON_PROXY_STATUS="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PREVIEW_PORT/wp-cron.php")"
[ "$CRON_PROXY_STATUS" = 404 ] || fail 'proxy did not block direct wp-cron.php'
if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  CRON_DIRECT="$(docker exec "wprism-$PAIR-preview-proxy-1" wget -S -O /dev/null http://wp/wp-cron.php 2>&1 || true)"
  printf '%s' "$CRON_DIRECT" | grep -q '404' || fail 'hash-pinned MU guard did not block direct internal wp-cron.php'
  CRON_PENDING="$(preview_compose run --rm --no-deps -T cli wp cron event list --hook=wprism_containment_cron_canary --field=hook)"
  [ "$CRON_PENDING" = wprism_containment_cron_canary ] || fail 'direct cron request ran the scheduled canary hook'
  pass 'nginx and the hash-pinned MU guard both return 404; the due cron canary remains unexecuted'
else
  pass 'nginx returns 404 before a site is restored; topology proof hash-checks the staged MU guard'
fi

if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  [ "$(pair_wp 1 option get wprism_containment_marker)" = "$SOURCE_BEFORE" ] || fail 'source changed during rehearsal'
  [ "$(pair_wp 2 option get wprism_containment_marker)" = "$TARGET_BEFORE" ] || fail 'release target changed during rehearsal'
  [ "$(pair_wp 1 option get wprism_payment_token)" = source-payment-secret-live ] || fail 'source payment credential changed during rehearsal'
  [ "$(pair_wp 2 option get wprism_payment_token)" = independent-target-payment-secret-live ] || fail 'independent-target credential changed during rehearsal'
  pair_wp 1 eval '$u=wp_authenticate("containment-source-user","source-auth-password-live");if(is_wp_error($u))exit(61);' \
    || fail 'source WordPress credential changed during rehearsal'
  pair_wp 2 eval '$u=wp_authenticate("independent-target-user","independent-target-password-live");if(is_wp_error($u))exit(62);' \
    || fail 'independent-target WordPress credential changed during rehearsal'
  pass 'source and independent release target remained unchanged while preview ran'
fi

php "$ROOT/sandbox/tests/fixtures/rehearse/contained-provider-live-cycle.php" reap "$CONFIG" "$RECEIPT" > "$TMP/reap.json"
PREVIEW_OWNED=0
[ -z "$(docker ps -a --filter "label=com.docker.compose.project=wprism-$PAIR-preview" -q)" ] || fail 'preview containers remain after reap'
docker network inspect "wprism-$PAIR-preview-internal" >/dev/null 2>&1 && fail 'preview network remains after reap'
docker network inspect "wprism-$PAIR-preview-ingress" >/dev/null 2>&1 && fail 'preview ingress network remains after reap'
if [ "$TOPOLOGY_ONLY" -eq 0 ]; then
  [ "$(pair_wp 1 option get wprism_containment_marker)" = "$SOURCE_BEFORE" ] || fail 'source changed after preview reap'
  [ "$(pair_wp 2 option get wprism_containment_marker)" = "$TARGET_BEFORE" ] || fail 'release target changed after preview reap'
  pass 'reap removed preview containers/network/volumes and preserved source plus release target'
else
  pass 'reap removed standalone preview containers, network, and volumes'
fi
