<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;

/**
 * Yoast Duplicate Post 4.7 role-capability reconciliation.
 *
 * Options_Page::register_capabilities() is the plugin's only settings-save
 * projection: it maps duplicate_post_roles onto the copy_posts capability of
 * every registered role. WPrism writes the authored option through direct SQL,
 * so that nonce-gated settings-page callback is unreachable during apply.
 * This provider ports that exact add/remove loop and requires a fresh,
 * value-level readback before the action can report success.
 */
final class YoastDuplicatePostRoleCapabilities extends ManifestProviderRuntime {
    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_reconcile_role_capabilities(array $args): array {
        $desired = $this->desired_roles();
        $roles = $this->roles();
        $before = $this->observation($roles, $desired);

        foreach ($roles as $name => $role) {
            $wanted = in_array($name, $desired, true);
            $present = $role->has_cap('copy_posts');
            if ($wanted && !$present) {
                $role->add_cap('copy_posts');
            } elseif (!$wanted && $present) {
                $role->remove_cap('copy_posts');
            }
        }

        return [
            'before' => $before,
            'after' => $this->postcondition(true),
            'verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    protected function reconcile_reconcile_role_capabilities(array $args): array {
        return $this->postcondition(true);
    }

    /** @return array<string,mixed> */
    private function postcondition(bool $verify): array {
        $desired = $this->desired_roles();
        $roles = $this->roles();
        $observation = $this->observation($roles, $desired);
        $actual = $this->capability_roles($roles);

        if ($verify && $actual !== $desired) {
            throw new \RuntimeException(
                'wprism: Yoast Duplicate Post copy_posts role capabilities did not converge on '
                . 'duplicate_post_roles; recovery_required'
            );
        }
        return $observation;
    }

    /** @return list<string> */
    private function desired_roles(): array {
        $raw = get_option('duplicate_post_roles', null);
        // This is the plugin's exact get_duplicate_post_roles() empty-value
        // behavior. A non-empty scalar is refused below because its own
        // in_array(..., $roles, true) consumer cannot safely execute it.
        if (empty($raw)) {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \RuntimeException(
                'wprism: Yoast Duplicate Post duplicate_post_roles must be an ordered list of role slugs'
            );
        }

        $desired = [];
        foreach ($raw as $role) {
            if (!is_string($role) || $role === '') {
                throw new \RuntimeException(
                    'wprism: Yoast Duplicate Post duplicate_post_roles contains an invalid role slug'
                );
            }
            if (isset($desired[$role])) {
                throw new \RuntimeException(
                    "wprism: Yoast Duplicate Post duplicate_post_roles repeats role '$role'"
                );
            }
            $desired[$role] = true;
        }

        $available = $this->roles();
        foreach (array_keys($desired) as $role) {
            if (!isset($available[$role])) {
                throw new \RuntimeException(
                    "wprism: Yoast Duplicate Post selected role '$role' is not registered on this target"
                );
            }
        }
        $names = array_keys($desired);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,object> */
    private function roles(): array {
        if (is_multisite()) {
            throw new \RuntimeException(
                'wprism: Yoast Duplicate Post role provider is certified for single-site role state only'
            );
        }
        if (!is_callable([\Yoast\WP\Duplicate_Post\Utils::class, 'get_roles'])) {
            throw new \RuntimeException(
                'wprism: Yoast Duplicate Post 4.7 role registry API is unavailable'
            );
        }
        $registered = \Yoast\WP\Duplicate_Post\Utils::get_roles();
        if (!is_array($registered)) {
            throw new \RuntimeException(
                'wprism: Yoast Duplicate Post 4.7 role registry did not return a role map'
            );
        }

        $roles = [];
        foreach ($registered as $name => $_displayName) {
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException(
                    'wprism: Yoast Duplicate Post 4.7 role registry contains an invalid role slug'
                );
            }
            $role = get_role($name);
            if (!is_object($role)
                || !is_callable([$role, 'has_cap'])
                || !is_callable([$role, 'add_cap'])
                || !is_callable([$role, 'remove_cap'])) {
                throw new \RuntimeException(
                    "wprism: Yoast Duplicate Post role '$name' cannot expose or persist copy_posts"
                );
            }
            $roles[$name] = $role;
        }
        ksort($roles, SORT_STRING);
        return $roles;
    }

    /** @param array<string,object> $roles @return list<string> */
    private function capability_roles(array $roles): array {
        $actual = [];
        foreach ($roles as $name => $role) {
            if ($role->has_cap('copy_posts')) {
                $actual[] = $name;
            }
        }
        sort($actual, SORT_STRING);
        return $actual;
    }

    /** @param array<string,object> $roles @param list<string> $desired @return array<string,mixed> */
    private function observation(array $roles, array $desired): array {
        $actual = $this->capability_roles($roles);
        return [
            'available_role_count' => count($roles),
            'desired_role_count' => count($desired),
            'desired_roles_hash' => $this->digest($desired),
            'capability_role_count' => count($actual),
            'capability_roles_hash' => $this->digest($actual),
        ];
    }

    /** @param list<string> $roles */
    private function digest(array $roles): string {
        return hash('sha256', json_encode(
            array_values($roles),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }
}
