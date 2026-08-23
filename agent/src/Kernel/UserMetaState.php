<?php
namespace Duo;

/** Canonical login-keyed user-meta sidecar identity and document helpers. */
final class UserMetaState {
    /**
     * Internal canonical-state key. It is deliberately not a UUID and is
     * never written to duo_map. A full SHA-256 fits duo_state's existing
     * VARCHAR(64) key and makes exact-login case differences filesystem-safe.
     */
    public static function key(string $login): string {
        return hash('sha256', "duo-user-meta\0" . $login);
    }

    public static function path(string $login): string {
        return 'user-meta/' . self::key($login) . '.json';
    }

    public static function document(string $login, array $meta): array {
        ksort($meta, SORT_STRING);
        return ['login' => $login, 'meta' => (object) $meta];
    }

    public static function assert_login(string $login): void {
        $characters = strlen($login) <= 240 ? preg_match_all('/./us', $login) : false;
        if ($login === ''
            || strlen($login) > 240
            || !is_int($characters)
            || $characters > 60
            || preg_match('/[\x00-\x1f\x7f]/', $login)) {
            throw new \RuntimeException(
                'duo: user-meta login must be a non-empty, control-free WordPress user_login of at most 60 characters'
            );
        }
    }
}
