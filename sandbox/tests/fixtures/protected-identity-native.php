<?php
declare(strict_types=1);

/** Native capture/env-set evidence only; no direct helper call or concurrent-gap claim. */
final class ProtectedIdentityNativeEvidence {
    public const INITIAL = 'owned-initial-password';
    public const POSITIVE = 'owned-positive-password';
    public const ORPHAN = 'owned-orphan-password';
    public const REFUSED = 'must-not-publish-this-password';
    public const SLUG = 'wprism-native-protected-identity';

    public static function columns(): array {
        return [
            'posts' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
                'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
                'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
                'post_type', 'post_mime_type', 'comment_count'],
            'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
            'terms' => ['term_id', 'name', 'slug', 'term_group'],
            'termmeta' => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
            'map' => ['uuid', 'entity_type', 'id_kind', 'local_id'],
            'state' => ['uuid', 'entity_type', 'content_hash'],
        ];
    }

    public static function need(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException('protected identity native evidence: ' . $label);
    }

    /** Raw, bounded facts; equality below covers every returned column, not a digest selected by the producer. */
    public static function observation(array $record, string $pair): void {
        self::need(array_keys($record) === ['format', 'home', 'wordpress', 'php', 'database', 'active_plugins', 'runtime',
            'post_id', 'uuid', 'rows', 'canonical', 'intended'], 'complete observation envelope');
        self::need($record['format'] === 'wprism-native-protected-identity/v1'
            && $record['home'] === 'http://' . $pair . '1.invalid' && $record['wordpress'] === '7.1'
            && is_string($record['php']) && str_starts_with($record['php'], '8.3.')
            && is_string($record['database']) && preg_match('/^11\.[0-9.]+-MariaDB/', $record['database']) === 1
            && $record['active_plugins'] === [], 'native core-manifest context without ordinary plugins');
        self::need(is_array($record['runtime']) && array_keys($record['runtime']) === ['theme', 'mu_plugins', 'dropins']
            && is_array($record['runtime']['theme']) && array_keys($record['runtime']['theme']) === ['stylesheet', 'version']
            && $record['runtime']['theme']['stylesheet'] === 'twentytwentyone'
            && is_string($record['runtime']['theme']['version'])
            && preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $record['runtime']['theme']['version']) === 1
            && $record['runtime']['mu_plugins'] === ['wprism-loader.php'] && $record['runtime']['dropins'] === [], 'declared native runtime inventory');
        self::need(is_int($record['post_id']) && $record['post_id'] > 0 && is_string($record['uuid'])
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $record['uuid']) === 1,
            'captured exact identity');
        self::need(is_array($record['rows']) && array_keys($record['rows']) === ['posts', 'postmeta', 'terms', 'termmeta', 'map', 'state'],
            'complete declared consumer roster');
        foreach ($record['rows'] as $name => $rows) {
            self::need(is_array($rows) && array_is_list($rows) && count($rows) <= 1024, 'bounded row list ' . $name);
            $previous = null;
            $localKeys = [];
            foreach ($rows as $row) {
                self::need(is_array($row) && array_keys($row) === self::columns()[$name], 'complete physical row');
                foreach ($row as $value) self::need(is_string($value) || $value === null, 'native text-protocol cell');
                foreach (match ($name) {
                    'posts' => ['ID'], 'postmeta' => ['meta_id', 'post_id'], 'terms' => ['term_id'],
                    'termmeta' => ['meta_id', 'term_id'], 'map' => ['local_id'], default => [],
                } as $column) {
                    self::need(is_string($row[$column]) && preg_match('/^[1-9][0-9]{0,17}$/D', $row[$column]) === 1,
                        'canonical bounded positive physical identity');
                }
                if ($name === 'map') {
                    self::need(is_string($row['uuid']) && is_string($row['id_kind'])
                        && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $row['uuid']) === 1
                        && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $row['id_kind']) === 1, 'canonical map identity');
                    $localKey = $row['id_kind'] . ':' . $row['local_id'];
                    self::need(!isset($localKeys[$localKey]), 'unique physical map owner');
                    $localKeys[$localKey] = true;
                }
                if ($name === 'state') {
                    self::need(is_string($row['uuid']) && is_string($row['content_hash'])
                        && preg_match('/^[a-z0-9_\/:.-]{1,64}$/D', $row['uuid']) === 1
                        && preg_match('/^[a-f0-9]{64}$/D', $row['content_hash']) === 1, 'bounded exact state identity');
                }
                $key = match ($name) {
                    'posts' => (int) $row['ID'], 'postmeta', 'termmeta' => (int) $row['meta_id'], 'terms' => (int) $row['term_id'],
                    'map' => $row['uuid'] . ':' . $row['id_kind'], default => $row['uuid'],
                };
                self::need($previous === null || (is_int($key) ? $key > $previous : strcmp($key, $previous) > 0), 'ordered unique physical identities');
                $previous = $key;
            }
        }
        $post = self::post($record);
        self::need(($post['post_type'] ?? null) === 'post' && ($post['post_status'] ?? null) === 'publish'
            && ($post['post_name'] ?? null) === self::SLUG, 'selected real protected post');
        $sidecars = array_values(array_filter($record['rows']['postmeta'], static fn(array $row): bool =>
            ($row['post_id'] ?? null) === (string) $record['post_id'] && ($row['meta_key'] ?? null) === '_wprism_uuid'));
        self::need(count($sidecars) === 1 && ($sidecars[0]['meta_value'] ?? null) === $record['uuid'], 'one exact original sidecar');
        $maps = array_values(array_filter($record['rows']['map'], static fn(array $row): bool => ($row['uuid'] ?? null) === $record['uuid']));
        self::need(count($maps) === 1 && ($maps[0]['local_id'] ?? null) === (string) $record['post_id']
            && ($maps[0]['id_kind'] ?? null) === 'post' && ($maps[0]['entity_type'] ?? null) === 'post', 'ordinary Capture map');
        $states = array_values(array_filter($record['rows']['state'], static fn(array $row): bool => ($row['uuid'] ?? null) === $record['uuid']));
        self::need(count($states) === 1 && $states[0]['entity_type'] === 'post'
            && preg_match('/^[a-f0-9]{64}$/D', $states[0]['content_hash']) === 1, 'ordinary Capture state');
        self::need(is_string($record['canonical']) && strlen($record['canonical']) > 32 && strlen($record['canonical']) <= 65536
            && preg_match('/\A---\n(.*?)\n---\n/s', $record['canonical'], $matches) === 1, 'bounded canonical front matter');
        $front = json_decode($matches[1], true, 32, JSON_THROW_ON_ERROR);
        self::need(($front['uuid'] ?? null) === $record['uuid'] && ($front['type'] ?? null) === 'post'
            && ($front['password_binding'] ?? null) === 'post_password:' . $record['uuid'], 'canonical password binding');
        foreach ([self::INITIAL, self::POSITIVE, self::ORPHAN, self::REFUSED] as $password) {
            self::need(!str_contains($record['canonical'], $password), 'no password in canonical state');
        }
        self::need($record['intended'] === null || (is_array($record['intended'])
            && array_keys($record['intended']) === ['mode', 'bytes'] && $record['intended']['mode'] === 0600
            && is_string($record['intended']['bytes']) && strlen($record['intended']['bytes']) <= 65536), 'private intended file');
    }

    public static function post(array $record): array {
        $rows = array_values(array_filter($record['rows']['posts'], static fn(array $row): bool =>
            ($row['ID'] ?? null) === (string) $record['post_id']));
        self::need(count($rows) === 1, 'one live selected owner');
        return $rows[0];
    }

    public static function passwordChange(array $before, array $after, string $password): void {
        self::need(self::post($after)['post_password'] === $password, 'exact live password postimage');
        $expected = $before;
        foreach ($expected['rows']['posts'] as &$row) {
            if ($row['ID'] === (string) $before['post_id']) $row['post_password'] = $password;
        }
        unset($row);
        $intended = $after['intended'];
        self::need(is_array($intended) && json_decode($intended['bytes'], true, 32, JSON_THROW_ON_ERROR)
            === ['post_password:' . $before['uuid'] => $password], 'exact single intended binding');
        $expected['intended'] = $intended;
        self::need($expected === $after, 'only selected password and intended value changed');
    }

    /** Prove manufacture before testing tolerance: exactly one absent post and term sidecar, no other changes. */
    public static function orphans(array $before, array $after): void {
        $expected = $before;
        foreach (['postmeta' => ['post_id', 'posts', 'ID'], 'termmeta' => ['term_id', 'terms', 'term_id']] as $table => [$owner, $owners, $pk]) {
            $rows = $after['rows'][$table];
            self::need(count($rows) === count($before['rows'][$table]) + 1, 'exact orphan addition');
            $added = array_pop($rows);
            self::need($rows === $before['rows'][$table] && array_keys($added) === ['meta_id', $owner, 'meta_key', 'meta_value']
                && ctype_digit($added[$owner]) && (int) $added[$owner] > 0
                && $added['meta_key'] === '_wprism_uuid' && $added['meta_value'] === $before['uuid'], 'native appended orphan sidecar');
            self::need(array_filter($after['rows'][$owners], static fn(array $row): bool => $row[$pk] === $added[$owner]) === [],
                'orphan owner is physically absent');
            $expected['rows'][$table] = $after['rows'][$table];
        }
        self::need($expected === $after, 'orphan setup changed only two declared rows');
    }

    public static function duplicate(array $before, array $after): void {
        $expected = $before;
        $rows = $after['rows']['termmeta'];
        self::need(count($rows) === count($before['rows']['termmeta']) + 1, 'exact live-term sidecar addition');
        $added = array_pop($rows);
        self::need($rows === $before['rows']['termmeta'] && array_keys($added) === ['meta_id', 'term_id', 'meta_key', 'meta_value']
            && $added['meta_key'] === '_wprism_uuid' && $added['meta_value'] === $before['uuid'], 'native duplicate sidecar');
        self::need(count(array_filter($before['rows']['terms'], static fn(array $row): bool => $row['term_id'] === $added['term_id'])) === 1,
            'duplicate has a real live term owner');
        $expected['rows']['termmeta'] = $after['rows']['termmeta'];
        self::need($expected === $after, 'duplicate setup changed only its declared sidecar');
    }

    public static function readObservation(string $stem, string $pair): array {
        require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
        $record = json_decode(WPrismTest\PrivateCommandOutput::readObject($stem, self::prelude($pair)), true, 32, JSON_THROW_ON_ERROR);
        self::observation($record, $pair);
        return $record;
    }

    public static function prelude(string $pair): string {
        self::need(preg_match('/^[a-z][a-z0-9]{2,23}$/D', $pair) === 1, 'owned pair name');
        return '/^ Container wprism-' . $pair . '-cli1-run-[a-f0-9]{12} (Creating|Created) $/D';
    }

    public static function refusal(): array {
        $message = 'env-set refused at an unclassified safety gate';
        $remediation = 'inspect the loaded policy env declarations, then declare the option class "env" without sub_keys, supply a non-empty value, and set it again';
        return ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'env-set', 'error' => 'env_set_failed',
            'reason_code' => 'env_set_failed', 'message' => $message, 'remediation' => $remediation, 'details_redacted' => true,
            'diagnostics' => [['code' => 'env_set_failed', 'message' => $message, 'remediation' => $remediation]]];
    }

    public static function source(string $sha, string $pair): array {
        self::prelude($pair);
        self::need(preg_match('/^[a-f0-9]{40}$/D', $sha) === 1, 'explicit source revision');
        return ['format' => 'wprism-native-protected-identity-source/v1', 'source_sha' => $sha, 'pair' => $pair,
            'fixture_sha256' => hash_file('sha256', __FILE__),
            'driver_sha256' => hash_file('sha256', __DIR__ . '/../live/regress_protected_identity_native.sh')];
    }

    public static function admit(string $sink, string $pair, string $expectedSha): void {
        require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
        require_once __DIR__ . '/../lib/PrivateRefusalReceipt.php';
        $prelude = self::prelude($pair);
        $source = json_decode(WPrismTest\PrivateCommandOutput::readObject($sink . '/source'), true, 32, JSON_THROW_ON_ERROR);
        self::need($source === self::source($expectedSha, $pair), 'retained source and producer bytes match caller intent');
        $object = static fn(string $stage, int $exit = 0): array => json_decode(
            WPrismTest\PrivateCommandOutput::readObject($sink . '/' . $stage, $prelude, expectedExit: $exit), true, 32, JSON_THROW_ON_ERROR);
        $before = self::readObservation($sink . '/before', $pair);
        self::need(self::post($before)['post_password'] === self::INITIAL && $before['intended'] === null, 'initial unprovisioned password');
        self::need($object('seed') === ['seeded_post' => $before['post_id']], 'seed is the captured post');
        $capture = $object('capture');
        self::need(is_array($capture['counts'] ?? null) && ($capture['counts']['post'] ?? 0) >= 1
            && ($capture['warnings'] ?? null) === [] && !isset($capture['error']), 'normal nonempty clean Capture');
        $positive = self::readObservation($sink . '/after-positive', $pair);
        self::passwordChange($before, $positive, self::POSITIVE);
        self::need($object('positive') === ['name' => 'post_password:' . $before['uuid'], 'previously_set' => false], 'first env-set response');
        $orphans = self::readObservation($sink . '/orphans', $pair);
        self::orphans($positive, $orphans);
        $afterOrphans = self::readObservation($sink . '/after-orphans', $pair);
        self::passwordChange($orphans, $afterOrphans, self::ORPHAN);
        self::need($object('orphan-update') === ['name' => 'post_password:' . $before['uuid'], 'previously_set' => true], 'orphan env-set response');
        $duplicate = self::readObservation($sink . '/duplicate', $pair);
        self::duplicate($afterOrphans, $duplicate);
        self::need(self::readObservation($sink . '/after-refusal', $pair) === $duplicate,
            'refusal preserves all six consumer tables, canonical bytes and exact intended file');

        // The generic collector preserves raw diagnostics but cannot select the
        // expected cause. This fixture binds one env-set invocation and profile.
        // Replaying the same retained sink from an independent checkout must
        // not redirect its private cause to that reviewer's working tree.
        // Both native sinks were allocated beside each other by the driver.
        $prefix = dirname($sink) . '/wprism-conformance-env-set.' . $pair . '.';
        $pointer = 'private command diagnostics (unverified): ';
        $stderrPattern = '/^(?: Container wprism-' . $pair . '-cli1-run-[a-f0-9]{12} (Creating|Created) '
            . '|' . preg_quote($pointer . $prefix, '/') . '[A-Za-z0-9]{6})$/D';
        $refusalBytes = WPrismTest\PrivateCommandOutput::readObject($sink . '/refusal', $stderrPattern, expectedExit: 1);
        $refusal = json_decode($refusalBytes, true, 32, JSON_THROW_ON_ERROR);
        self::need($refusal === self::refusal(), 'exact redacted refusal');
        $stderr = file_get_contents($sink . '/refusal.stderr');
        self::need(is_string($stderr) && preg_match_all('/^' . preg_quote($pointer . $prefix, '/') . '([A-Za-z0-9]{6})$/m', $stderr, $matches) === 1,
            'one retained diagnostic pointer');
        $diagnosticRoot = $prefix . $matches[1][0];
        $baseline = json_decode(WPrismTest\PrivateCommandOutput::readObject($diagnosticRoot . '/baseline', $prelude), true, 32, JSON_THROW_ON_ERROR);
        self::need(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'env-set', 'bound diagnostic baseline');
        WPrismTest\PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'env-set');
        foreach (['baseline-check', 'private-check'] as $stage) {
            self::need(WPrismTest\PrivateCommandOutput::readBytes($diagnosticRoot . '/' . $stage) === '', 'silent diagnostic admission');
        }
        $rawCommand = WPrismTest\PrivateCommandOutput::readObject($diagnosticRoot . '/command', $prelude, expectedExit: 1);
        self::need($rawCommand === $refusalBytes, 'retained exact command response');
        $diagnostic = json_decode(WPrismTest\PrivateCommandOutput::readObject($diagnosticRoot . '/private', $prelude,
            WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        $oldNames = json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($diagnostic['records'] ?? [] as $record) {
            self::need(!in_array($record['name'] ?? null, $oldNames, true), 'retained cause is absent from its baseline');
        }
        WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, ['command' => 'env-set', 'reason_code' => 'env_set_failed',
            'nodes' => [['parent_index' => null, 'relation' => 'root', 'class' => 'RuntimeException',
                'message' => 'wprism: protected post binding identity does not match its unique exact live backing row']]]);
    }
}

if (($argv[1] ?? '') === '--admit') {
    ProtectedIdentityNativeEvidence::admit($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? '');
    exit(0);
}
if (($argv[1] ?? '') === '--source') {
    echo json_encode(ProtectedIdentityNativeEvidence::source($argv[2] ?? '', $argv[3] ?? ''), JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if (($argv[1] ?? '') === '--binding') {
    $record = ProtectedIdentityNativeEvidence::readObservation($argv[2] ?? '', $argv[3] ?? '');
    echo 'post_password:', $record['uuid'], "\n";
    exit(0);
}
if (($argv[1] ?? '') === '--policy') {
    $root = $argv[2] ?? '';
    ProtectedIdentityNativeEvidence::need(is_dir($root) && !is_link($root), 'owned site fixture directory');
    $bytes = json_encode(['manifests' => ['core'], 'policy' => ['post_types' => ['post', 'page'],
        'taxonomies' => ['category', 'post_tag']], 'spec_version' => 2], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    $handle = fopen($root . '/site.wprism.json', 'xb');
    ProtectedIdentityNativeEvidence::need(is_resource($handle) && fwrite($handle, $bytes) === strlen($bytes) && fclose($handle), 'create-only owned policy');
    exit(0);
}

// WP-CLI eval-file supplies $args in its include scope. The host includes only
// the admission class; native setup never runs during its offline contract.
if (isset($args) && defined('ABSPATH')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $mode = $args[0] ?? '';
    ProtectedIdentityNativeEvidence::need(in_array($mode, ['seed', 'observe', 'orphans', 'duplicate'], true)
        && current_user_can('manage_options') && get_option('active_plugins') === [], 'owned native mode and admin');
    global $wpdb;
    if ($mode === 'seed') {
        ProtectedIdentityNativeEvidence::need(get_page_by_path(ProtectedIdentityNativeEvidence::SLUG, OBJECT, 'post') === null, 'seed slug absent');
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Native protected identity',
            'post_name' => ProtectedIdentityNativeEvidence::SLUG, 'post_content' => 'Owned native identity fixture.',
            'post_password' => ProtectedIdentityNativeEvidence::INITIAL], true);
        ProtectedIdentityNativeEvidence::need(is_int($id) && $id > 0 && get_post_meta($id, '_wprism_uuid', false) === [],
            'ordinary WordPress post awaits Capture identity');
        echo json_encode(['seeded_post' => $id], JSON_THROW_ON_ERROR), "\n";
        return;
    }
    $post = get_page_by_path(ProtectedIdentityNativeEvidence::SLUG, OBJECT, 'post');
    ProtectedIdentityNativeEvidence::need($post instanceof WP_Post, 'seeded post exists');
    $uuids = get_post_meta($post->ID, '_wprism_uuid', false);
    ProtectedIdentityNativeEvidence::need(count($uuids) === 1 && is_string($uuids[0])
        && preg_match('/^[a-f0-9-]{36}$/D', $uuids[0]) === 1, 'Capture minted identity');
    $uuid = $uuids[0];
    if ($mode === 'orphans') {
        foreach (['postmeta' => ['post_id', 'posts', 'ID'], 'termmeta' => ['term_id', 'terms', 'term_id']] as $table => [$owner, $owners, $pk]) {
            $absent = (int) $wpdb->get_var("SELECT COALESCE(MAX($pk), 0) + 1000000 FROM {$wpdb->$owners}");
            ProtectedIdentityNativeEvidence::need($wpdb->last_error === '' && $absent > 0
                && $wpdb->get_var($wpdb->prepare("SELECT $pk FROM {$wpdb->$owners} WHERE $pk = %d", $absent)) === null,
                'bounded absent fixture owner');
            ProtectedIdentityNativeEvidence::need($wpdb->insert($wpdb->$table,
                [$owner => $absent, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid], ['%d', '%s', '%s']) === 1, 'insert orphan fixture');
        }
    } elseif ($mode === 'duplicate') {
        $term = (int) $wpdb->get_var("SELECT MIN(term_id) FROM {$wpdb->terms}");
        ProtectedIdentityNativeEvidence::need($wpdb->last_error === '' && $term > 0
            && $wpdb->insert($wpdb->termmeta, ['term_id' => $term, 'meta_key' => '_wprism_uuid', 'meta_value' => $uuid], ['%d', '%s', '%s']) === 1,
            'insert live term duplicate fixture');
    }
    $rows = [];
    foreach (['posts' => [$wpdb->posts, 'ID'], 'postmeta' => [$wpdb->postmeta, 'meta_id'], 'terms' => [$wpdb->terms, 'term_id'],
        'termmeta' => [$wpdb->termmeta, 'meta_id'], 'map' => [$wpdb->prefix . 'wprism_map', 'uuid'],
        'state' => [$wpdb->prefix . 'wprism_state', 'uuid']] as $name => [$table, $key]) {
        $order = $name === 'map' ? '`uuid` ASC, `id_kind` ASC' : "`$key` ASC";
        $rows[$name] = $wpdb->get_results("SELECT * FROM `$table` ORDER BY $order LIMIT 1025", ARRAY_A);
        ProtectedIdentityNativeEvidence::need(is_array($rows[$name]) && count($rows[$name]) <= 1024 && $wpdb->last_error === '',
            'bounded complete consumer read');
    }
    $read = static function (string $path, bool $private): ?array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false) return null;
        ProtectedIdentityNativeEvidence::need(($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1
            && $stat['size'] > 0 && $stat['size'] <= 65536 && (!$private || ($stat['mode'] & 0777) === 0600), 'bounded owned file');
        $bytes = file_get_contents($path, false, null, 0, 65537);
        ProtectedIdentityNativeEvidence::need(is_string($bytes) && strlen($bytes) === $stat['size'], 'complete owned file read');
        return ['mode' => $stat['mode'] & 0777, 'bytes' => $bytes];
    };
    $canonical = $read('/siterepo/state/posts/post/' . $uuid . '--' . ProtectedIdentityNativeEvidence::SLUG . '.md', false);
    ProtectedIdentityNativeEvidence::need(is_array($canonical), 'captured canonical file exists');
    $record = ['format' => 'wprism-native-protected-identity/v1', 'home' => home_url(), 'wordpress' => get_bloginfo('version'),
        'php' => PHP_VERSION, 'database' => $wpdb->get_var('SELECT VERSION()'), 'active_plugins' => get_option('active_plugins'),
        'runtime' => ['theme' => ['stylesheet' => get_stylesheet(), 'version' => wp_get_theme()->get('Version')],
            'mu_plugins' => array_keys(get_mu_plugins()), 'dropins' => array_keys(get_dropins())],
        'post_id' => (int) $post->ID, 'uuid' => $uuid, 'rows' => $rows, 'canonical' => $canonical['bytes'],
        'intended' => $read('/siterepo/.wprism-env-values.json', true)];
    echo json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
}
