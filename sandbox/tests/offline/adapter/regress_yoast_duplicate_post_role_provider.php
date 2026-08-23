<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';

    $GLOBALS['ydp_multisite'] = false;
    $GLOBALS['ydp_option'] = ['editor', 'duo_reviewer'];
    $GLOBALS['ydp_registry'] = [
        'administrator' => 'Administrator',
        'duo_reviewer' => 'Duo Reviewer',
        'editor' => 'Editor',
        'subscriber' => 'Subscriber',
    ];
    $GLOBALS['ydp_caps'] = [
        'administrator' => ['copy_posts' => true],
        'duo_reviewer' => [],
        'editor' => [],
        'subscriber' => ['copy_posts' => true],
    ];
    $GLOBALS['ydp_writes'] = [];
    $GLOBALS['ydp_drop_writes'] = false;
    $GLOBALS['ydp_inaccessible_role'] = null;

    function is_multisite(): bool {
        return $GLOBALS['ydp_multisite'];
    }

    function get_option(string $name, mixed $default = false): mixed {
        return $name === 'duplicate_post_roles' ? $GLOBALS['ydp_option'] : $default;
    }

    function get_role(string $name): ?WP_Role {
        if (($GLOBALS['ydp_inaccessible_role'] ?? null) === $name
            || !array_key_exists($name, $GLOBALS['ydp_caps'])) {
            return null;
        }
        return new WP_Role($name);
    }

    final class WP_Role {
        public function __construct(private string $name) {}

        public function has_cap(string $capability): bool {
            return !empty($GLOBALS['ydp_caps'][$this->name][$capability]);
        }

        public function add_cap(string $capability): void {
            $GLOBALS['ydp_writes'][] = "add:{$this->name}:$capability";
            if (!$GLOBALS['ydp_drop_writes']) {
                $GLOBALS['ydp_caps'][$this->name][$capability] = true;
            }
        }

        public function remove_cap(string $capability): void {
            $GLOBALS['ydp_writes'][] = "remove:{$this->name}:$capability";
            if (!$GLOBALS['ydp_drop_writes']) {
                unset($GLOBALS['ydp_caps'][$this->name][$capability]);
            }
        }
    }
}

namespace Duo {
    final class Policy {}

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }
}

namespace Yoast\WP\Duplicate_Post {
    final class Permissions_Helper {}

    final class Utils {
        public static function get_roles(): mixed {
            return $GLOBALS['ydp_registry'];
        }
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/manifests/providers/yoast-duplicate-post-role-capabilities.php';

    use Duo\Providers\YoastDuplicatePostRoleCapabilities;

    /** @param callable():void $callback */
    function ydp_refuses(callable $callback, string $needle, string $message): void {
        try {
            $callback();
            duo_check(false, "$message (expected refusal containing '$needle')");
        } catch (\RuntimeException $e) {
            duo_check(
                str_contains($e->getMessage(), $needle),
                "$message ({$e->getMessage()})"
            );
        }
    }

    /** Restore one hostile but valid role projection. */
    function ydp_reset(): void {
        $GLOBALS['ydp_multisite'] = false;
        $GLOBALS['ydp_option'] = ['editor', 'duo_reviewer'];
        $GLOBALS['ydp_registry'] = [
            'administrator' => 'Administrator',
            'duo_reviewer' => 'Duo Reviewer',
            'editor' => 'Editor',
            'subscriber' => 'Subscriber',
        ];
        $GLOBALS['ydp_caps'] = [
            'administrator' => ['copy_posts' => true],
            'duo_reviewer' => [],
            'editor' => [],
            'subscriber' => ['copy_posts' => true],
        ];
        $GLOBALS['ydp_writes'] = [];
        $GLOBALS['ydp_drop_writes'] = false;
        $GLOBALS['ydp_inaccessible_role'] = null;
    }

    $provider = new YoastDuplicatePostRoleCapabilities(new \Duo\Policy());
    duo_check_same(
        [
            'id' => 'yoast-duplicate-post-role-capabilities',
            'plugin' => 'duplicate-post/duplicate-post.php',
            'version' => '1.0.0',
        ],
        $provider->identity(),
        'provider identity is exact and manifest-negotiable'
    );
    $declaration = $provider->capabilities()['reconcile_role_capabilities'] ?? null;
    duo_check_same('site', $declaration['scope'] ?? null, 'role repair is site-scoped, never entity-ambiguous');
    duo_check_same(
        ['option:duplicate_post_roles'],
        $declaration['reads'] ?? null,
        'capability names the one authored input it reads'
    );
    duo_check_same(
        ['entity:yoast-duplicate-post-role-capabilities'],
        $declaration['writes'] ?? null,
        'capability bounds its write summary to the plugin-owned role projection'
    );
    duo_check(($declaration['idempotent'] ?? false) === true, 'capability explicitly permits safe retry');
    duo_check_same(
        \Duo\Providers::SCOPED_OPERATION_FORMAT,
        $declaration['scoped']['operation_envelope'] ?? null,
        'capability advertises scoped recovery reconciliation'
    );

    $receipt = $provider->invoke('reconcile_role_capabilities', []);
    duo_check_same(
        ['remove:administrator:copy_posts', 'add:duo_reviewer:copy_posts', 'add:editor:copy_posts', 'remove:subscriber:copy_posts'],
        $GLOBALS['ydp_writes'],
        'provider ports the plugin exact add/remove loop across every registered role'
    );
    duo_check(
        !empty($GLOBALS['ydp_caps']['editor']['copy_posts'])
            && !empty($GLOBALS['ydp_caps']['duo_reviewer']['copy_posts'])
            && empty($GLOBALS['ydp_caps']['administrator']['copy_posts'])
            && empty($GLOBALS['ydp_caps']['subscriber']['copy_posts']),
        'hostile target capabilities converge exactly on duplicate_post_roles'
    );
    duo_check(($receipt['verified'] ?? false) === true, 'provider reports success only after value-level readback');
    duo_check_same(2, $receipt['after']['desired_role_count'] ?? null, 'receipt counts the desired role set');
    duo_check_same(2, $receipt['after']['capability_role_count'] ?? null, 'receipt counts the verified capability set');
    duo_check_same(
        $receipt['after']['desired_roles_hash'] ?? null,
        $receipt['after']['capability_roles_hash'] ?? null,
        'receipt proves desired and observed role sets have the same digest'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($published)
            && !str_contains($published, 'duo_reviewer')
            && !str_contains($published, 'administrator'),
        'provider receipt publishes counts and hashes without role names'
    );

    $GLOBALS['ydp_writes'] = [];
    $again = $provider->invoke('reconcile_role_capabilities', []);
    duo_check_same([], $GLOBALS['ydp_writes'], 'second invocation is a mutation-free idempotent verification');
    duo_check_same($receipt['after'], $again['after'] ?? null, 'idempotent receipt is byte-stable');

    $operation = [
        'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
        'authority_hash' => str_repeat('a', 64),
        'lease_session_id' => 'fixture-session',
        'operation_id' => 'fixture-operation',
        'input_hash' => str_repeat('b', 64),
        'effect_hash' => str_repeat('c', 64),
    ];
    $scoped = $provider->invoke_scoped('reconcile_role_capabilities', [], $operation);
    duo_check_same($operation, $scoped['operation'] ?? null, 'scoped receipt echoes the exact recovery authority envelope');
    $reconciled = $provider->reconcile_scoped('reconcile_role_capabilities', [], $operation);
    duo_check(
        ($reconciled['verified'] ?? false) === true
            && ($reconciled['after']['desired_roles_hash'] ?? null) === ($reconciled['after']['capability_roles_hash'] ?? null),
        'recovery reconciliation independently re-verifies the durable role projection'
    );

    ydp_reset();
    $GLOBALS['ydp_option'] = [];
    $provider->invoke('reconcile_role_capabilities', []);
    duo_check_same(
        ['remove:administrator:copy_posts', 'remove:subscriber:copy_posts'],
        $GLOBALS['ydp_writes'],
        'the plugin-defined empty role list removes copy_posts from every registered role'
    );

    foreach ([false, null, '', '0', 0] as $empty) {
        ydp_reset();
        $GLOBALS['ydp_option'] = $empty;
        $provider->invoke('reconcile_role_capabilities', []);
        duo_check(
            empty(array_filter($GLOBALS['ydp_caps'], static fn(array $caps): bool => !empty($caps['copy_posts']))),
            'every plugin-empty duplicate_post_roles representation converges on no capability roles ('
                . get_debug_type($empty) . ')'
        );
    }

    foreach ([
        'scalar' => 'editor',
        'associative' => ['editor' => true],
        'duplicate' => ['editor', 'editor'],
        'empty-slug' => [''],
        'non-string' => [7],
    ] as $shape => $value) {
        ydp_reset();
        $GLOBALS['ydp_option'] = $value;
        $before = $GLOBALS['ydp_caps'];
        ydp_refuses(
            static fn() => $provider->invoke('reconcile_role_capabilities', []),
            $shape === 'duplicate' ? 'repeats role' : ($shape === 'empty-slug' || $shape === 'non-string' ? 'invalid role slug' : 'ordered list'),
            "malformed $shape role policy refuses before mutation"
        );
        duo_check_same($before, $GLOBALS['ydp_caps'], "malformed $shape role policy leaves capabilities untouched");
    }

    ydp_reset();
    $GLOBALS['ydp_option'] = ['source_only_role'];
    ydp_refuses(
        static fn() => $provider->invoke('reconcile_role_capabilities', []),
        'is not registered on this target',
        'a selected source-only role refuses instead of silently weakening permissions'
    );
    duo_check_same([], $GLOBALS['ydp_writes'], 'missing selected role refusal occurs before the first capability mutation');

    ydp_reset();
    $GLOBALS['ydp_inaccessible_role'] = 'subscriber';
    ydp_refuses(
        static fn() => $provider->invoke('reconcile_role_capabilities', []),
        "role 'subscriber' cannot expose or persist",
        'an inconsistent WordPress role registry refuses before projection'
    );
    duo_check_same([], $GLOBALS['ydp_writes'], 'inaccessible role refuses before the first capability mutation');

    ydp_reset();
    $GLOBALS['ydp_drop_writes'] = true;
    ydp_refuses(
        static fn() => $provider->invoke('reconcile_role_capabilities', []),
        'did not converge',
        'silently dropped role writes fail the fresh postcondition'
    );
    duo_check(count($GLOBALS['ydp_writes']) === 4, 'failed postcondition follows a real attempted projection for recovery coverage');

    ydp_reset();
    $GLOBALS['ydp_multisite'] = true;
    ydp_refuses(
        static fn() => $provider->invoke('reconcile_role_capabilities', []),
        'single-site role state only',
        'multisite role state remains an explicit provider refusal'
    );
    duo_check_same([], $GLOBALS['ydp_writes'], 'multisite refusal occurs before mutation');

    ydp_reset();
    ydp_refuses(
        static fn() => $provider->invoke('unknown', []),
        'does not implement capability',
        'closed capability vocabulary refuses unknown invocations'
    );

    duo_check_summary('Yoast Duplicate Post role provider');
}
