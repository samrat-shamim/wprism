<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/OrderPreserved.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/UserMetaState.php';

/**
 * Read-only capture of login-keyed authored user-meta sidecars.
 *
 * Users remain environment-local: this boundary reads the complete live
 * user/usermeta roster but never mints a user UUID or writes duo_map. Policy
 * and token codecs are injected as collaborators, while Capture retains the
 * two security decisions that need repository/operator context -- secret and
 * personal-data refusal -- as explicit callbacks. The resulting entities are
 * canonical bytes ready for Capture's ordinary publication pipeline.
 */
final class UserMetaCapture {
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
    public function capture(array $carriedLogins): array {
        $carry = array_fill_keys(array_filter(array_map('strval', $carriedLogins)), true);
        $users = [];
        foreach ($this->userMetaMaps() as $user) {
            UserMetaState::assert_login($user['login']);
            $users[$user['login']] = $user;
        }
        ksort($users, SORT_STRING);

        $out = [];
        foreach ($users as $login => $user) {
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
                continue;
            }
            $document = UserMetaState::document($login, $authored);
            $out[] = [
                // Canonical-state key only: not a UUID, never duo_map.
                'uuid' => UserMetaState::key($login),
                'type' => 'user-meta',
                'path' => UserMetaState::path($login),
                'content' => Canon::encode($document),
            ];
        }
        return $out;
    }

    /**
     * Whole-user meta context for the optional interpreter hook. The join
     * excludes orphaned usermeta rows, which have no exact login owner.
     *
     * @return array<int, array{login:string,meta:array<string,mixed>,values:array<string,string[]>}>
     */
    private function userMetaMaps(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT u.ID AS user_id, u.user_login, um.meta_key, um.meta_value
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
             ORDER BY u.ID ASC, um.umeta_id ASC",
            ARRAY_A
        ) ?: [];
        ($this->checkTransientDbError)('Capture::user_meta_maps()');
        $out = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $out[$userId]['login'] = (string) $row['user_login'];
            $out[$userId]['meta'] ??= [];
            $out[$userId]['values'] ??= [];
            if ($row['meta_key'] === null) {
                continue;
            }
            $key = (string) $row['meta_key'];
            $out[$userId]['values'][$key][] = (string) $row['meta_value'];
            if (!array_key_exists($key, $out[$userId]['meta'])) {
                $out[$userId]['meta'][$key] = (string) $row['meta_value'];
            }
        }
        return $out;
    }

    /** @return array{0:bool,1:mixed} */
    private function classifyValue(string $key, array $values, array $flatMeta, string $login): array {
        $rule = $this->policy->meta_rule_for_user($key, $flatMeta);
        if (($rule['class'] ?? '') !== 'authored') {
            return [false, null];
        }
        if (count($values) !== 1) {
            throw new \RuntimeException(
                "duo: multi-value authored user meta '$key' on exact login '$login' is unsupported; "
                . 'refusing to choose one row'
            );
        }
        $value = PlainData::decode($values[0], "user '$login' meta $key");
        PlainData::assert($value, "user '$login' meta $key");
        ($this->guardSecret)('user_meta', $key, $value, $rule, " on exact login '$login'");
        ($this->guardPersonalData)($key, $value, $rule, $login);
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $decoded = StructuredValue::decode($value, $rule, "user '$login' meta $key");
            $value = $this->tokens->struct_capture(
                $decoded,
                $rule['json_refs'] ?? [],
                $rule['key_refs'] ?? null
            );
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
