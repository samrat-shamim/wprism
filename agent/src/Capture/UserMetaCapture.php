<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/OrderPreserved.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/UserMetaState.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';

/**
 * Read-only capture of login-keyed authored user-meta sidecars.
 *
 * Users remain environment-local: this boundary reads the complete live
 * user/usermeta roster but never mints a user UUID or writes wprism_map. Policy
 * and token codecs are injected as collaborators, while Capture retains the
 * two security decisions that need repository/operator context -- secret and
 * personal-data refusal -- as explicit callbacks. The resulting entities are
 * canonical bytes ready for Capture's ordinary publication pipeline.
 */
final class UserMetaCapture {
    private const MAX_USERS = 1000000;
    private const USER_CHUNK_SIZE = 500;
    private const MAX_USER_LOGIN_CHARACTERS = 60;
    private const MAX_USER_LOGIN_BYTES = 240;
    private const MAX_CHUNK_META_ROWS = 250000;
    private const MAX_CHUNK_META_BYTES = 134217728;
    private object $policy;
    private object $tokens;
    private \Closure $guardSecret;
    private \Closure $guardPersonalData;
    private \Closure $checkTransientDbError;

    public function __construct(
        object $policy,
        object $tokens,
        \Closure $guardSecret,
        \Closure $guardPersonalData,
        \Closure $checkTransientDbError
    ) {
        $this->policy = $policy;
        $this->tokens = $tokens;
        $this->guardSecret = $guardSecret;
        $this->guardPersonalData = $guardPersonalData;
        $this->checkTransientDbError = $checkTransientDbError;
    }

    /**
     * Capture every authored user-meta document plus repository-carried empty
     * documents that express removal of the final owned key.
     *
     * @param string[] $carriedLogins
     * @return array<int,array{uuid:string,type:string,path:string,content:string}>
     */
    public function capture(array $carriedLogins, ?DatabaseWorkAuthority $workAuthority = null): array {
        $carry = array_fill_keys(array_filter(array_map('strval', $carriedLogins)), true);
        $outByLogin = [];
        foreach ($this->userMetaMaps($workAuthority) as $user) {
            DatabaseQueryIsolation::work_unit($workAuthority, function () use ($user, $carry, &$outByLogin): void {
                UserMetaState::assert_login($user['login']);
                $login = $user['login'];
                $authored = [];
                foreach ($user['values'] as $key => $values) {
                    [$store, $value] = $this->classifyValue(
                        (string) $key,
                        $values,
                        $user['meta'],
                        $login
                    );
                    if ($store) {
                        $authored[(string) $key] = $value;
                    }
                }
                if (!$authored && !isset($carry[$login])) {
                    return;
                }
                $document = UserMetaState::document($login, $authored);
                $outByLogin[$login] = [
                    // Canonical-state key only: not a UUID, never wprism_map.
                    'uuid' => UserMetaState::key($login),
                    'type' => 'user-meta',
                    'path' => UserMetaState::path($login),
                    'content' => Canon::encode($document),
                ];
            });
        }
        // Only canonical outputs survive a chunk. A sparse million-user site
        // with no authored sidecars therefore retains zero raw user/meta maps
        // instead of accumulating the complete roster before classification.
        ksort($outByLogin, SORT_STRING);
        return array_values($outByLogin);
    }

    /**
     * Whole-user meta context for the optional interpreter hook. The join
     * excludes orphaned usermeta rows, which have no exact login owner.
     *
     * @return \Generator<int, array{login:string,meta:array<string,mixed>,values:array<string,string[]>}>
     */
    private function userMetaMaps(?DatabaseWorkAuthority $workAuthority = null): \Generator {
        global $wpdb;
        $this->assertNoOrphanMeta();
        $this->assertNoCollationEqualLogins();

        $lastUserId = 0;
        $seenUsers = 0;
        while (true) {
            // The four matched reads belong to the existing 500-user and
            // byte-bounded chunk. They execute before yield, so a unit around
            // the consumer alone would still charge all chunks as one call.
            $chunk = DatabaseQueryIsolation::work_unit($workAuthority, function () use ($wpdb, &$lastUserId, &$seenUsers): ?array {
                $preflight = $this->checkedRows(
                    $wpdb->prepare(
                        'SELECT ID AS user_id, OCTET_LENGTH(user_login) AS user_login_bytes, '
                        . 'SHA2(user_login, 256) AS user_login_sha256 '
                        . "FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT "
                        . (self::USER_CHUNK_SIZE + 1),
                        $lastUserId
                    ),
                    'Capture::user_meta_users_size_preflight()'
                );
                if ($preflight === []) {
                    return null;
                }
                $hasMore = count($preflight) > self::USER_CHUNK_SIZE;
                if ($hasMore) {
                    $preflight = array_slice($preflight, 0, self::USER_CHUNK_SIZE);
                }
                $expectedUsers = [];
                foreach ($preflight as $position => $row) {
                    $id = is_array($row) ? MetaRows::positive_id($row['user_id'] ?? null) : null;
                    $bytes = is_array($row) ? self::nonnegativeSize($row['user_login_bytes'] ?? null) : null;
                    $loginHash = is_array($row) ? self::sha256($row['user_login_sha256'] ?? null) : null;
                    if (!is_array($row)
                        || array_keys($row) !== ['user_id', 'user_login_bytes', 'user_login_sha256']
                        || $id === null
                        || $id <= $lastUserId
                        || $bytes === null
                        || $loginHash === null
                        || $bytes === 0
                        || $bytes > self::MAX_USER_LOGIN_BYTES) {
                        throw new \RuntimeException(
                            "wprism: user-meta user size preflight returned a malformed row at bounded position $position"
                        );
                    }
                    $expectedUsers[$id] = [
                        'id' => $row['user_id'],
                        'login_bytes' => $bytes,
                        'login_sha256' => $loginHash,
                    ];
                    $lastUserId = $id;
                    ++$seenUsers;
                    if ($seenUsers > self::MAX_USERS) {
                        throw new \RuntimeException('wprism: user-meta capture exceeds the bounded user limit');
                    }
                }
                $ids = array_keys($expectedUsers);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $userRows = $this->checkedRows(
                    $wpdb->prepare(
                        "SELECT ID AS user_id, user_login FROM {$wpdb->users} "
                        . "WHERE ID IN ($placeholders) ORDER BY ID ASC LIMIT " . (count($ids) + 1),
                        ...$ids
                    ),
                    'Capture::user_meta_users_value_read()'
                );
                if (count($userRows) !== count($expectedUsers)) {
                    throw new \RuntimeException('wprism: user-meta users changed after the bounded size preflight');
                }
                $users = [];
                $loginHashes = [];
                foreach ($userRows as $position => $row) {
                    $id = is_array($row) ? MetaRows::positive_id($row['user_id'] ?? null) : null;
                    $login = is_array($row) ? ($row['user_login'] ?? null) : null;
                    $witness = $id === null ? null : ($expectedUsers[$id] ?? null);
                    $characters = is_string($login) && strlen($login) <= self::MAX_USER_LOGIN_BYTES
                        ? preg_match_all('/./us', $login)
                        : false;
                    if (!is_array($row)
                        || array_keys($row) !== ['user_id', 'user_login']
                        || $id === null
                        || $witness === null
                        || !is_string($login)
                        || strlen($login) !== $witness['login_bytes']
                        || !hash_equals($witness['login_sha256'], hash('sha256', $login))
                        || !is_int($characters)
                        || $characters === 0
                        || $characters > self::MAX_USER_LOGIN_CHARACTERS) {
                        throw new \RuntimeException(
                            "wprism: user-meta user value read returned a malformed row at bounded position $position"
                        );
                    }
                    UserMetaState::assert_login($login);
                    $loginHash = hash('sha256', $login);
                    if (isset($loginHashes[$loginHash])) {
                        throw new \RuntimeException('wprism: user-meta capture found duplicate exact login identities');
                    }
                    $loginHashes[$loginHash] = true;
                    $users[$id] = ['login' => $login, 'meta' => [], 'values' => []];
                }

                $this->fillUserMetaChunk($users, $ids, $placeholders);
                return ['users' => $users, 'has_more' => $hasMore];
            });
            if ($chunk === null) {
                return;
            }
            foreach ($chunk['users'] as $user) {
                yield $user;
            }
            if (!$chunk['has_more']) {
                return;
            }
        }
    }

    /** @param array<int,array{login:string,meta:array,values:array}> $users @param list<int> $ids */
    private function fillUserMetaChunk(array &$users, array $ids, string $placeholders): void {
        global $wpdb;
        $preflight = $this->checkedRows(
            $wpdb->prepare(
                'SELECT umeta_id AS meta_id, user_id, OCTET_LENGTH(meta_key) AS meta_key_bytes, '
                . 'OCTET_LENGTH(meta_value) AS meta_value_bytes, '
                . 'SHA2(meta_key, 256) AS meta_key_sha256, '
                . "SHA2(meta_value, 256) AS meta_value_sha256 FROM {$wpdb->usermeta} "
                . "WHERE user_id IN ($placeholders) ORDER BY user_id ASC, umeta_id ASC LIMIT "
                . (self::MAX_CHUNK_META_ROWS + 1),
                ...$ids
            ),
            'Capture::user_meta_size_preflight()'
        );
        if (count($preflight) > self::MAX_CHUNK_META_ROWS) {
            throw new \RuntimeException('wprism: user-meta capture exceeds the bounded chunk row limit');
        }
        $expected = [];
        $ownerRows = [];
        $ownerBytes = [];
        $chunkBytes = 0;
        $previousOwner = 0;
        $previousMetaId = 0;
        foreach ($preflight as $position => $row) {
            $metaId = is_array($row) ? MetaRows::positive_id($row['meta_id'] ?? null) : null;
            $userId = is_array($row) ? MetaRows::positive_id($row['user_id'] ?? null) : null;
            $keyBytes = is_array($row) ? self::nonnegativeSize($row['meta_key_bytes'] ?? null) : null;
            $valueBytes = is_array($row) && ($row['meta_value_bytes'] ?? null) === null
                ? null
                : (is_array($row) ? self::nonnegativeSize($row['meta_value_bytes'] ?? null) : null);
            $keyHash = is_array($row) ? self::sha256($row['meta_key_sha256'] ?? null) : null;
            $valueHash = is_array($row) && ($row['meta_value_sha256'] ?? null) === null
                ? null
                : (is_array($row) ? self::sha256($row['meta_value_sha256'] ?? null) : null);
            if (!is_array($row)
                || array_keys($row) !== [
                    'meta_id', 'user_id', 'meta_key_bytes', 'meta_value_bytes',
                    'meta_key_sha256', 'meta_value_sha256',
                ]
                || $metaId === null
                || $userId === null
                || !isset($users[$userId])
                || $keyBytes === null
                || $keyHash === null
                || $keyBytes === 0
                || $keyBytes > MetaRows::MAX_META_KEY_BYTES
                || ($row['meta_value_bytes'] !== null && $valueBytes === null)
                || (($row['meta_value_sha256'] === null) !== ($row['meta_value_bytes'] === null))
                || ($row['meta_value_sha256'] !== null && $valueHash === null)
                || ($valueBytes !== null && $valueBytes > MetaRows::MAX_META_VALUE_BYTES)
                || $userId < $previousOwner
                || ($userId === $previousOwner && $metaId <= $previousMetaId)) {
                throw new \RuntimeException(
                    "wprism: user-meta size preflight returned a malformed/oversized row at bounded position $position"
                );
            }
            $rowBytes = strlen($row['meta_id']) + strlen($row['user_id']) + $keyBytes + ($valueBytes ?? 0);
            $ownerRows[$userId] = ($ownerRows[$userId] ?? 0) + 1;
            $ownerBytes[$userId] = ($ownerBytes[$userId] ?? 0) + $rowBytes;
            if ($ownerRows[$userId] > MetaRows::MAX_OWNER_ROWS
                || $ownerBytes[$userId] > MetaRows::MAX_OWNER_BYTES
                || $rowBytes > self::MAX_CHUNK_META_BYTES - $chunkBytes) {
                throw new \RuntimeException('wprism: user-meta capture exceeds a bounded owner/chunk frontier');
            }
            $chunkBytes += $rowBytes;
            $previousOwner = $userId;
            $previousMetaId = $metaId;
            $expected[] = [
                'meta_id' => $row['meta_id'],
                'user_id' => $row['user_id'],
                'key_bytes' => $keyBytes,
                'value_bytes' => $valueBytes,
                'key_sha256' => $keyHash,
                'value_sha256' => $valueHash,
            ];
        }

        $rows = $this->checkedRows(
            $wpdb->prepare(
                "SELECT umeta_id AS meta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} "
                . "WHERE user_id IN ($placeholders) ORDER BY user_id ASC, umeta_id ASC LIMIT "
                . (count($expected) + 1),
                ...$ids
            ),
            'Capture::user_meta_value_read()'
        );
        if (count($rows) !== count($expected)) {
            throw new \RuntimeException('wprism: user-meta rows changed after the bounded size preflight');
        }
        foreach ($rows as $position => $row) {
            $metaId = is_array($row) ? MetaRows::positive_id($row['meta_id'] ?? null) : null;
            $userId = is_array($row) ? MetaRows::positive_id($row['user_id'] ?? null) : null;
            $key = is_array($row) ? ($row['meta_key'] ?? null) : null;
            $value = is_array($row) ? ($row['meta_value'] ?? null) : null;
            $keyCharacters = is_string($key) && strlen($key) <= MetaRows::MAX_META_KEY_BYTES
                ? preg_match_all('/./us', $key)
                : false;
            $witness = $expected[$position] ?? null;
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'user_id', 'meta_key', 'meta_value']
                || $metaId === null
                || $userId === null
                || !isset($users[$userId])
                || !is_string($key)
                || $key === ''
                || !is_int($keyCharacters)
                || $keyCharacters > MetaRows::MAX_META_KEY_CHARACTERS
                || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
                || !(is_string($value) || $value === null)
                || !is_array($witness)
                || !hash_equals($witness['meta_id'], $row['meta_id'])
                || !hash_equals($witness['user_id'], $row['user_id'])
                || $witness['key_bytes'] !== strlen($key)
                || $witness['value_bytes'] !== ($value === null ? null : strlen($value))
                || !hash_equals($witness['key_sha256'], hash('sha256', $key))
                || ($value === null
                    ? $witness['value_sha256'] !== null
                    : ($witness['value_sha256'] === null
                        || !hash_equals($witness['value_sha256'], hash('sha256', $value))))) {
                throw new \RuntimeException(
                    "wprism: user-meta value read disagrees with its bounded preflight at position $position"
                );
            }
            // wp_usermeta.meta_value is nullable. WordPress's historical
            // capture behavior string-casts SQL NULL to the empty string.
            $stored = $value ?? '';
            $users[$userId]['values'][$key][] = $stored;
            if (!array_key_exists($key, $users[$userId]['meta'])) {
                $users[$userId]['meta'][$key] = $stored;
            }
        }
    }

    private function assertNoOrphanMeta(): void {
        global $wpdb;
        $rows = $this->checkedRows(
            "SELECT um.umeta_id AS meta_id FROM {$wpdb->usermeta} um "
            . "LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL "
            . 'ORDER BY um.umeta_id ASC LIMIT 1',
            'Capture::user_meta_orphan_check()'
        );
        if ($rows !== []) {
            throw new \RuntimeException('wprism: user-meta capture found metadata without an exact user owner');
        }
    }

    private function assertNoCollationEqualLogins(): void {
        global $wpdb;
        $rows = $this->checkedRows(
            "SELECT a.ID AS left_id, b.ID AS right_id FROM {$wpdb->users} a "
            . "INNER JOIN {$wpdb->users} b ON b.user_login = a.user_login AND b.ID > a.ID "
            . 'ORDER BY a.ID ASC, b.ID ASC LIMIT 1',
            'Capture::user_meta_login_ambiguity_check()'
        );
        if ($rows !== []) {
            throw new \RuntimeException(
                'wprism: user-meta capture found collation-equal duplicate login identities'
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function checkedRows(mixed $sql, string $context): array {
        global $wpdb;
        if (!is_string($sql) || $sql === '') {
            throw new \RuntimeException("wprism: $context could not prepare its bounded read");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        ($this->checkTransientDbError)($context);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $context failed or returned a malformed row list");
        }
        return $rows;
    }

    private static function nonnegativeSize(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
    }

    private static function sha256(mixed $value): ?string {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1
            ? $value
            : null;
    }

    /** @return array{0:bool,1:mixed} */
    private function classifyValue(string $key, array $values, array $flatMeta, string $login): array {
        $rule = $this->policy->meta_rule_for_user($key, $flatMeta);
        if (($rule['class'] ?? '') !== 'authored') {
            return [false, null];
        }
        if (count($values) !== 1) {
            throw new \RuntimeException(
                "wprism: multi-value authored user meta '$key' on exact login '$login' is unsupported; "
                . 'refusing to choose one row'
            );
        }
        $value = PlainData::decode($values[0], "user '$login' meta $key");
        PlainData::assert($value, "user '$login' meta $key");
        NativeValueValidation::assert_native($value, $rule, "user '$login' meta $key");
        ($this->guardSecret)('user_meta', $key, $value, $rule, " on exact login '$login'");
        ($this->guardPersonalData)($key, $value, $rule, $login);
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = StructuredValue::decode($value, $rule, "user '$login' meta $key");
            $value = $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            );
        } elseif (!empty($rule['plain_data'])) {
            $value = $this->tokens->plain_data_capture($value);
        } elseif (!empty($rule['ref'])) {
            $value = $this->tokens->meta_value_to_tokens($value, $rule);
            if ($value === null) {
                return [false, null];
            }
        } elseif (is_string($value)) {
            $value = $this->tokens->tokenize_text($value);
        }
        if (!empty($rule['order_preserving'])) {
            $value = new OrderPreserved($value);
        }
        return [true, $value];
    }
}
