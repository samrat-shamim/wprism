<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../Command/HostProcess.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';

use WPrism\Canon;

final class DockerDatabaseSetupException extends \RuntimeException {
    public function __construct(string $message, private bool $grantMayHaveSucceeded = false) {
        parent::__construct($message);
    }

    public function grantMayHaveSucceeded(): bool {
        return $this->grantMayHaveSucceeded;
    }
}

/** Explicit, digest-bound provisioning of the one global database prerequisite. */
final class DockerDatabaseSetup {
    public const FORMAT = 'wprism-docker-database-process-plan/v1';
    public const CONFIGURE_FLAG = '--configure-database';
    public const SERVICE_FLAG = '--database-service=';
    public const BLOCKER = 'initial_baseline_database_boundary_unavailable';
    private const TIMEOUT_MILLISECONDS = 30000;
    private const OUTPUT_LIMIT_BYTES = 65536;

    public static function assertServiceName(string $service): void {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $service) !== 1) {
            throw new \RuntimeException('--database-service requires one explicit Compose service name');
        }
    }

    private const WORDPRESS_PROBE = <<<'PHP'
global $wpdb;
$engine = stripos((string) $wpdb->db_server_info(), 'mariadb') !== false ? 'mariadb' : 'mysql';
$wpdb->last_error = '';
$row = $wpdb->get_row('SELECT CURRENT_USER() AS principal, DATABASE() AS schema_name, VERSION() AS version, '
    . '@@hostname AS hostname, @@port AS port', ARRAY_A);
if (!is_array($row) || trim((string) $wpdb->last_error) !== '') { fwrite(STDERR, 'database identity probe failed'); exit(70); }
$version = $row['version'] ?? null;
if (!is_string($version) || preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $parts) !== 1) {
    fwrite(STDERR, 'database version probe failed'); exit(71);
}
if ($engine === 'mariadb') {
    $major = (int) $parts[1]; $minor = (int) $parts[2]; $patch = (int) $parts[3];
    $minimum = [1 => 6, 2 => 5, 4 => 3, 5 => 2][$minor] ?? null;
    if ($major !== 11 || ($minor === 6 && $patch < 1) || ($minor < 6 && ($minimum === null || $patch < $minimum))) {
        fwrite(STDERR, 'MariaDB database setup requires server_uid (11.1.6, 11.2.5, 11.4.3, 11.5.2, or 11.6.1+)'); exit(72);
    }
}
$identity = $engine === 'mariadb' ? '@@server_uid' : '@@server_uuid';
$wpdb->last_error = '';
$serverIdentity = $wpdb->get_var('SELECT ' . $identity);
if (!is_string($serverIdentity) || trim((string) $wpdb->last_error) !== '') {
    fwrite(STDERR, 'database immutable identity probe failed'); exit(73);
}
$principal = $row['principal'] ?? null;
$at = is_string($principal) ? strrpos($principal, '@') : false;
if (!is_int($at) || $at < 1 || $at === strlen($principal) - 1) { fwrite(STDERR, 'database principal probe failed'); exit(74); }
$grantee = "'" . substr($principal, 0, $at) . "'@'" . substr($principal, $at + 1) . "'";
$wpdb->last_error = '';
$count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES\n"
    . "WHERE BINARY GRANTEE = BINARY %s AND PRIVILEGE_TYPE = 'PROCESS'", $grantee));
if (trim((string) $wpdb->last_error) !== '' || !in_array((string) $count, ['0', '1'], true)) {
    fwrite(STDERR, 'database PROCESS authority probe failed'); exit(75);
}
echo json_encode([
    'engine' => $engine, 'hostname' => $row['hostname'] ?? null, 'port' => $row['port'] ?? null,
    'principal' => $principal, 'process' => (string) $count === '1',
    'schema' => $row['schema_name'] ?? null, 'server_identity' => $serverIdentity,
    'version' => $version,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
PHP;

    private const ADMIN_SCRIPT = <<<'SH'
set -eu
engine=$1 action=$2 user=$3 host=$4 expected_server=$5 expected_hostname=$6 token=$7
case "$token" in *[!a-f0-9]*|'') echo 'database admin credential token is malformed' >&2; exit 63 ;; esac
[ "${#token}" -eq 24 ] || { echo 'database admin credential token is malformed' >&2; exit 63; }
case "$engine" in mysql) client=mysql; password_name=MYSQL_ROOT_PASSWORD; file_name=MYSQL_ROOT_PASSWORD_FILE; identity=server_uuid ;;
  mariadb) client=mariadb; password_name=MARIADB_ROOT_PASSWORD; file_name=MARIADB_ROOT_PASSWORD_FILE; identity=server_uid ;;
  *) echo 'unsupported database engine' >&2; exit 64 ;; esac
command -v "$client" >/dev/null 2>&1 || { echo 'official database client is unavailable' >&2; exit 65; }
eval "password=\${$password_name-}"; eval "password_file=\${$file_name-}"
[ -z "$password" ] || [ -z "$password_file" ] || { echo 'database root credential source is ambiguous' >&2; exit 66; }
if [ -n "$password_file" ]; then
  [ -f "$password_file" ] && [ ! -L "$password_file" ] || { echo 'database root credential file is unsafe' >&2; exit 67; }
  password=$(cat -- "$password_file")
fi
[ -n "$password" ] || { echo 'selected database service provides no supported root credential' >&2; exit 68; }
[ "$(printf '%s' "$password" | tr -d '\r\n')" = "$password" ] \
  || { echo 'database root credential cannot be represented safely' >&2; exit 69; }
defaults=/tmp/.wprism-db-admin-$token
cleanup() { rm -f -- "$defaults"; }
trap cleanup EXIT HUP INT TERM
umask 077
escaped=$(printf '%s' "$password" | sed 's/\\/\\\\/g; s/"/\\"/g')
(set -C; : >"$defaults") 2>/dev/null || { echo 'database admin credential path already exists' >&2; exit 78; }
printf '[client]\nuser=root\npassword="%s"\nprotocol=socket\n' "$escaped" >"$defaults"
chmod 0600 "$defaults"
facts=$($client --defaults-extra-file="$defaults" --batch --raw --skip-column-names \
  --execute="SELECT @@${identity}, @@hostname, VERSION();") || exit $?
actual_server=$(printf '%s\n' "$facts" | awk 'NR==1 { print $1 }')
actual_hostname=$(printf '%s\n' "$facts" | awk 'NR==1 { print $2 }')
[ "$actual_server" = "$expected_server" ] || { echo 'database server identity changed before grant' >&2; exit 73; }
[ "$actual_hostname" = "$expected_hostname" ] || { echo 'database server hostname changed before grant' >&2; exit 77; }
account_count=$($client --defaults-extra-file="$defaults" --batch --raw --skip-column-names \
  --execute="SELECT COUNT(*) FROM mysql.user WHERE BINARY User = BINARY '$user' AND BINARY Host = BINARY '$host';") || exit $?
[ "$account_count" = 1 ] || { echo 'WordPress database principal is absent or ambiguous on selected service' >&2; exit 74; }
process_count=$($client --defaults-extra-file="$defaults" --batch --raw --skip-column-names \
  --execute="SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE BINARY GRANTEE = BINARY CONCAT(CHAR(39), '$user', CHAR(39), '@', CHAR(39), '$host', CHAR(39)) AND PRIVILEGE_TYPE = 'PROCESS';") || exit $?
case "$process_count" in 0|1) ;; *) echo 'database PROCESS grant evidence is malformed' >&2; exit 75 ;; esac
if [ "$action" = grant ] && [ "$process_count" = 0 ]; then
  printf 'WPRISM_GRANT_ATTEMPT\n'
  $client --defaults-extra-file="$defaults" --batch --raw --skip-column-names \
    --execute="GRANT PROCESS ON *.* TO '$user'@'$host';" || exit $?
  printf 'WPRISM_GRANT_APPLIED\n'
  process_count=$($client --defaults-extra-file="$defaults" --batch --raw --skip-column-names \
    --execute="SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE BINARY GRANTEE = BINARY CONCAT(CHAR(39), '$user', CHAR(39), '@', CHAR(39), '$host', CHAR(39)) AND PRIVILEGE_TYPE = 'PROCESS';") || exit $?
  [ "$process_count" = 1 ] || { echo 'PROCESS grant returned without direct authority evidence' >&2; exit 76; }
fi
printf '%s\t%s\t%s\t%s\n' "$actual_server" "$actual_hostname" "$account_count" "$process_count"
SH;

    private const ADMIN_CLEANUP_SCRIPT = <<<'SH'
set -eu
token=$1
case "$token" in *[!a-f0-9]*|'') echo 'database admin cleanup token is malformed' >&2; exit 63 ;; esac
[ "${#token}" -eq 24 ] || { echo 'database admin cleanup token is malformed' >&2; exit 63; }
defaults=/tmp/.wprism-db-admin-$token
[ -e "$defaults" ] || [ -L "$defaults" ] || exit 0
[ -f "$defaults" ] && [ ! -L "$defaults" ] && [ "$(stat -c %u -- "$defaults")" = 0 ] \
  || { echo 'database admin credential cleanup ownership changed' >&2; exit 79; }
rm -f -- "$defaults"
SH;

    /**
     * @param array<string,mixed> $proposal
     * @param null|callable(string,array<string,mixed>):array<string,mixed> $runner
     * @return array<string,mixed>
     */
    public static function plan(
        DockerTransport $driver,
        string $service,
        array $proposal,
        ?callable $runner = null
    ): array {
        self::assertServiceName($service);
        $run = $runner ?? self::runner($driver);
        $binding = self::binding($run, $service);
        $wordpress = self::wordpressFacts($run);
        if (($wordpress['engine'] ?? null) !== $binding['engine']) {
            throw new \RuntimeException('WordPress and the selected Docker database service use different database engines');
        }
        if (!hash_equals((string) $wordpress['hostname'], (string) $binding['container_hostname'])) {
            throw new \RuntimeException('WordPress is connected to a different database hostname than the selected Compose service');
        }
        self::assertSupportedProposal($proposal);
        [$user, $host] = self::principal((string) ($wordpress['principal'] ?? ''));
        $admin = self::admin(
            $run,
            $binding,
            'probe',
            $user,
            $host,
            (string) $wordpress['server_identity'],
            (string) $binding['container_hostname']
        );
        if (!hash_equals((string) $wordpress['server_identity'], $admin['server_identity'])) {
            throw new \RuntimeException('WordPress is connected to a different database server than the selected Compose service');
        }
        $blocked = in_array(self::BLOCKER, array_map(
            static fn(mixed $row): mixed => is_array($row) ? ($row['code'] ?? null) : null,
            (array) ($proposal['unsupported'] ?? [])
        ), true);
        if ((bool) $wordpress['process'] !== ($admin['process_count'] === 1)) {
            throw new \RuntimeException('WordPress and database-admin PROCESS authority evidence disagree');
        }
        if (!$blocked && !$wordpress['process']) {
            throw new \RuntimeException('init readiness and direct PROCESS evidence disagree');
        }
        $plan = [
            'format' => self::FORMAT,
            'account' => ['host' => $host, 'user' => $user],
            'compose_project' => $binding['compose_project'],
            'config_hash' => $binding['config_hash'],
            'container_id' => $binding['container_id'],
            'container_hostname' => $binding['container_hostname'],
            'database_service' => $binding['database_service'],
            'docker_context' => $binding['docker_context'],
            'docker_endpoint' => $binding['docker_endpoint'],
            'engine' => $binding['engine'],
            'image_digest' => $binding['image_digest'],
            'image_id' => $binding['image_id'],
            'required' => !$wordpress['process'],
            'schema' => $wordpress['schema'],
            'server_identity' => $wordpress['server_identity'],
            'server_wide_privilege' => 'PROCESS',
        ];
        $plan['digest'] = hash('sha256', Canon::encode($plan));
        return $plan;
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $proposal
     * @param null|callable(string,array<string,mixed>):array<string,mixed> $runner
     * @return array{changed:bool,plan_digest:string}
     */
    public static function apply(
        DockerTransport $driver,
        array $plan,
        array $proposal,
        ?callable $runner = null
    ): array {
        $service = is_string($plan['database_service'] ?? null) ? $plan['database_service'] : '';
        $fresh = self::plan($driver, $service, $proposal, $runner);
        if (!is_string($plan['digest'] ?? null) || !hash_equals((string) $plan['digest'], (string) $fresh['digest'])) {
            throw new \RuntimeException('Docker database setup proof changed before confirmation; review a fresh setup plan');
        }
        if ($fresh['required'] !== true) {
            return ['changed' => false, 'plan_digest' => (string) $fresh['digest']];
        }
        $run = $runner ?? self::runner($driver);
        $binding = self::binding($run, $service);
        foreach (['compose_project', 'database_service', 'container_id', 'container_hostname', 'config_hash', 'image_id', 'image_digest', 'docker_context', 'docker_endpoint', 'engine'] as $field) {
            if (!hash_equals((string) $fresh[$field], (string) $binding[$field])) {
                throw new \RuntimeException('selected Docker database service changed immediately before the PROCESS grant');
            }
        }
        $account = $fresh['account'];
        $admin = self::admin(
            $run,
            $binding,
            'grant',
            (string) $account['user'],
            (string) $account['host'],
            (string) $fresh['server_identity'],
            (string) $fresh['container_hostname']
        );
        if ($admin['process_count'] !== 1) {
            throw new \RuntimeException('database admin did not prove the direct PROCESS grant after mutation');
        }
        return ['changed' => true, 'plan_digest' => (string) $fresh['digest']];
    }

    /** @return callable(string,array<string,mixed>):array<string,mixed> */
    private static function runner(DockerTransport $driver): callable {
        return static function (string $operation, array $request) use ($driver): array {
            if ($operation === 'binding' && is_string($request['service'] ?? null)) {
                return $driver->localDatabaseServiceBinding($request['service']);
            }
            if ($operation === 'wordpress') {
                return $driver->captureWpBounded(
                    ['eval', self::WORDPRESS_PROBE],
                    self::TIMEOUT_MILLISECONDS,
                    self::OUTPUT_LIMIT_BYTES,
                    self::OUTPUT_LIMIT_BYTES
                );
            }
            if (!in_array($operation, ['admin', 'cleanup'], true) || !is_array($request['argv'] ?? null)) {
                throw new \RuntimeException('Docker database setup runner received an unknown operation');
            }
            return HostProcess::run(
                $request['argv'],
                null,
                [],
                false,
                self::TIMEOUT_MILLISECONDS,
                self::OUTPUT_LIMIT_BYTES
            );
        };
    }

    /** @param callable(string,array<string,mixed>):array $run @return array<string,mixed> */
    private static function binding(callable $run, string $service): array {
        $binding = $run('binding', ['service' => $service]);
        $expected = [
            'compose_project', 'config_hash', 'container_hostname', 'container_id', 'database_service',
            'docker_context', 'docker_endpoint', 'docker_tokens', 'engine', 'image_digest', 'image_id',
        ];
        $keys = is_array($binding) ? array_keys($binding) : [];
        sort($keys, SORT_STRING);
        if ($keys !== $expected || ($binding['database_service'] ?? null) !== $service
            || !is_array($binding['docker_tokens']) || !array_is_list($binding['docker_tokens'])
            || count($binding['docker_tokens']) !== count(array_filter($binding['docker_tokens'], 'is_string'))
            || $binding['docker_tokens'] === []) {
            throw new \RuntimeException('selected Docker database service binding is malformed');
        }
        return $binding;
    }

    /** @param callable(string,array<string,mixed>):array $run @return array<string,mixed> */
    private static function wordpressFacts(callable $run): array {
        $result = $run('wordpress', []);
        if (($result['exit'] ?? null) !== 0 || !is_string($result['stdout'] ?? null)) {
            throw new \RuntimeException('WordPress database identity probe failed: ' . trim((string) ($result['stderr'] ?? '')));
        }
        $facts = json_decode(trim($result['stdout']), true);
        $keys = is_array($facts) ? array_keys($facts) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['engine', 'hostname', 'port', 'principal', 'process', 'schema', 'server_identity', 'version']
            || !in_array($facts['engine'], ['mysql', 'mariadb'], true)
            || !is_string($facts['hostname']) || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $facts['hostname']) !== 1
            || (!is_string($facts['port']) && !is_int($facts['port']))
            || (int) $facts['port'] < 1 || (int) $facts['port'] > 65535
            || !is_string($facts['schema']) || $facts['schema'] === ''
            || !is_string($facts['server_identity']) || !self::serverIdentity($facts['engine'], $facts['server_identity'])
            || !is_bool($facts['process'])
            || !is_string($facts['version']) || preg_match('/^\d+\.\d+\.\d+/', $facts['version']) !== 1) {
            throw new \RuntimeException('WordPress database identity probe returned malformed structured evidence');
        }
        if ($facts['engine'] === 'mariadb' && !self::mariaDbHasServerUid($facts['version'])) {
            throw new \RuntimeException(
                'MariaDB Docker database setup requires immutable server_uid identity '
                    . '(11.1.6, 11.2.5, 11.4.3, 11.5.2, or 11.6.1+)'
            );
        }
        return $facts;
    }

    /** @param array<string,mixed> $proposal */
    private static function assertSupportedProposal(array $proposal): void {
        foreach ((array) ($proposal['unsupported'] ?? []) as $row) {
            $code = is_array($row) ? ($row['code'] ?? null) : null;
            if (in_array($code, ['platform_database_engine_unsupported', 'platform_database_version_unsupported'], true)) {
                throw new \RuntimeException('Docker database setup refuses a database runtime outside the exercised platform contract');
            }
        }
    }

    /** @return array{0:string,1:string} */
    private static function principal(string $principal): array {
        $at = strrpos($principal, '@');
        $user = is_int($at) ? substr($principal, 0, $at) : '';
        $host = is_int($at) ? substr($principal, $at + 1) : '';
        if (preg_match('/^[A-Za-z0-9_.%-]{1,32}$/D', $user) !== 1
            || preg_match('/^[A-Za-z0-9_.:%-]{1,255}$/D', $host) !== 1) {
            throw new \RuntimeException('WordPress database principal cannot be represented by the bounded grant grammar');
        }
        return [$user, $host];
    }

    /**
     * @param callable(string,array<string,mixed>):array $run
     * @param array<string,mixed> $binding
     * @return array{server_identity:string,account_count:int,process_count:int}
     */
    private static function admin(
        callable $run,
        array $binding,
        string $action,
        string $user,
        string $host,
        string $serverIdentity,
        string $hostname
    ): array {
        $token = bin2hex(random_bytes(12));
        $argv = array_merge((array) $binding['docker_tokens'], [
            'exec', (string) $binding['container_id'], 'sh', '-c', self::ADMIN_SCRIPT, 'wprism-db-admin',
            (string) $binding['engine'], $action, $user, $host, $serverIdentity, $hostname, $token,
        ]);
        $result = null;
        $runFailure = null;
        try {
            $result = $run('admin', ['argv' => $argv]);
        } catch (\Throwable $failure) {
            $runFailure = $failure;
        }
        try {
            $cleanup = $run('cleanup', ['argv' => array_merge((array) $binding['docker_tokens'], [
                'exec', (string) $binding['container_id'], 'sh', '-c', self::ADMIN_CLEANUP_SCRIPT,
                'wprism-db-admin-cleanup', $token,
            ])]);
        } catch (\Throwable $failure) {
            throw new DockerDatabaseSetupException(
                'selected database service credential cleanup failed',
                $action === 'grant'
            );
        }
        if ($runFailure !== null) {
            throw new DockerDatabaseSetupException(
                'selected database service admin execution failed',
                $action === 'grant'
            );
        }
        /** @var array<string,mixed> $result */
        $grantAttempted = $action === 'grant'
            && str_contains((string) ($result['stdout'] ?? ''), "WPRISM_GRANT_ATTEMPT\n");
        if (!is_array($cleanup) || ($cleanup['exit'] ?? null) !== 0) {
            throw new DockerDatabaseSetupException(
                'selected database service credential cleanup failed',
                $grantAttempted
            );
        }
        if (($result['exit'] ?? null) !== 0 || !is_string($result['stdout'] ?? null)) {
            throw new DockerDatabaseSetupException(
                'selected database service admin probe failed: ' . trim((string) ($result['stderr'] ?? '')),
                $grantAttempted
            );
        }
        $lines = preg_split('/\r?\n/', trim($result['stdout']));
        $facts = is_array($lines) ? (string) end($lines) : '';
        $fields = explode("\t", $facts);
        if (count($fields) !== 4 || !self::serverIdentity((string) $binding['engine'], $fields[0])
            || !hash_equals($hostname, $fields[1])
            || $fields[2] !== '1' || !in_array($fields[3], ['0', '1'], true)) {
            throw new DockerDatabaseSetupException(
                'selected database service admin probe returned malformed structured evidence',
                $grantAttempted
            );
        }
        return ['server_identity' => $fields[0], 'account_count' => (int) $fields[2], 'process_count' => (int) $fields[3]];
    }

    private static function serverIdentity(string $engine, string $identity): bool {
        return $engine === 'mysql'
            ? preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/DiD', $identity) === 1
            : preg_match('#^[A-Za-z0-9+/]{27}=$#D', $identity) === 1;
    }

    private static function mariaDbHasServerUid(string $version): bool {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $parts) !== 1 || (int) $parts[1] !== 11) {
            return false;
        }
        $minor = (int) $parts[2];
        $patch = (int) $parts[3];
        if ($minor > 6 || ($minor === 6 && $patch >= 1)) {
            return true;
        }
        $minimum = [1 => 6, 2 => 5, 4 => 3, 5 => 2][$minor] ?? null;
        return $minimum !== null && $patch >= $minimum;
    }
}
