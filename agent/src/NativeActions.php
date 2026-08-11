<?php
namespace Duo;

/**
 * The closed native-action vocabulary: engine-implemented operations whose
 * semantics belong to WordPress core rather than to any one plugin.
 *
 * The boundary doctrine bounds this file precisely (docs/proposals/
 * engine-adapter-boundary.md, "Structured native action"): a native action is
 * appropriate only when the operation means the same thing no matter which
 * plugin declared it. Anything whose behavior is a plugin's own belongs in a
 * provider, so this vocabulary stays deliberately small and never grows an
 * argument shaped like one plugin's data model.
 *
 * A manifest selects an action by name and cannot mint one: an unknown name,
 * an unknown argument key, or a mistyped argument is refused at manifest load
 * time, before any target contact. That closure is what keeps executable text
 * out of the channel entirely — arguments are typed scalars checked against a
 * per-action schema, never command strings handed to a shell, eval, or WP-CLI.
 *
 * validate() is called from Policy::load(), which runs in pure-PHP contexts
 * with no WordPress bootstrap (RepositoryCompiler validates a revision offline
 * before Tokens/Ledger/Capture can exist). No WordPress function may therefore
 * be reached outside execute().
 */
final class NativeActions {
    private const SCOPED_OWNER = 'native-actions';
    /**
     * action name => argument schema (key => {type, required, pattern?}).
     *
     * v1 is exactly one action. `transient.delete` earns native status because
     * a WordPress transient's storage contract — the `_transient_<name>` and
     * `_transient_timeout_<name>` option rows, or the `transient` cache group
     * under an external object cache — is core's, identical for every plugin
     * that keeps a blanket cache there.
     *
     * The name charset is WordPress's own transient-name bound (172 bytes,
     * so `_transient_timeout_` + name still fits option_name's 191); the
     * leading-character restriction keeps a manifest from naming a row whose
     * own name is already prefixed, which would delete a different key than
     * the one it appears to name.
     */
    private const ACTIONS = [
        'transient.delete' => [
            'name' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,170}$/D',
            ],
        ],
    ];

    /** @return list<string> */
    public static function vocabulary(): array {
        return array_keys(self::ACTIONS);
    }

    /**
     * The per-action argument schemas validate() checks against (DUO-3327).
     *
     * Additive and read-only. vocabulary() answers "which names exist"; an
     * offline authoring aid also has to answer "which arguments does this one
     * take, which are required, and what shape must each be" — and the only
     * honest answer is the schema the refusal itself consults. A published
     * schema assembled from a second list would let an editor offer an argument
     * key this class rejects, which is the drift a closed vocabulary exists to
     * make impossible.
     *
     * PROJECTED, not returned whole. Today ACTIONS maps an action name to
     * exactly its argument schema, so `return self::ACTIONS` would be
     * byte-identical — and that is precisely the reason not to write it: this
     * accessor's contract is "the argument schemas", and the const's contract is
     * "everything the engine knows about an action". The moment those diverge
     * (a per-action `since`, a receipt shape, a lifecycle note) the unprojected
     * version publishes the new field as though it were an ARGUMENT KEY an
     * author may write, and an editor built on this document would offer it.
     * The projection below is the whole of the difference, and it costs one
     * loop to make the two contracts independent instead of coincidentally
     * equal. validate() reads the const directly and is unaffected: this is a
     * publication surface, never the refusal path.
     *
     * @return array<string, array<string, array{type:string, required:bool, pattern?:string}>>
     */
    public static function arg_schemas(): array {
        $out = [];
        foreach (self::ACTIONS as $action => $args) {
            $out[$action] = [];
            foreach ($args as $key => $rule) {
                // Field-level projection too, and for the same reason: an
                // internal annotation on one argument rule would otherwise be
                // published as part of that argument's declared shape.
                $out[$action][$key] = array_intersect_key(
                    $rule,
                    ['type' => true, 'required' => true, 'pattern' => true]
                );
            }
        }
        return $out;
    }

    /**
     * Load-time gate for one manifest action entry. Unknown names and unknown
     * argument keys are refused rather than ignored: an action a manifest
     * believes it declared, silently dropped, is a derived-state repair that
     * never happens and never reports itself.
     *
     * @param array<string,mixed> $args
     * @param string $where caller-supplied manifest coordinate for the message
     */
    public static function validate(string $action, array $args, string $where): void {
        $schema = self::ACTIONS[$action] ?? null;
        if ($schema === null) {
            throw new \RuntimeException(
                "duo: $where names unknown native action '$action' — the engine vocabulary is closed ("
                . implode(', ', self::vocabulary()) . '); a plugin-specific operation belongs in a provider'
            );
        }
        if (array_is_list($args) && $args !== []) {
            throw new \RuntimeException("duo: $where.args must be an object");
        }
        $unknown = array_diff(array_keys($args), array_keys($schema));
        if ($unknown !== []) {
            throw new \RuntimeException(
                "duo: $where.args contains unknown key(s) for native action '$action': "
                . implode(', ', $unknown)
            );
        }
        foreach ($schema as $key => $rule) {
            if (!array_key_exists($key, $args)) {
                if ($rule['required']) {
                    throw new \RuntimeException(
                        "duo: $where.args is missing required key '$key' for native action '$action'"
                    );
                }
                continue;
            }
            $value = $args[$key];
            if ($rule['type'] === 'string'
                && (!is_string($value) || preg_match($rule['pattern'], $value) !== 1)) {
                throw new \RuntimeException(
                    "duo: $where.args.$key must be a bounded string matching {$rule['pattern']}"
                );
            }
        }
    }

    /**
     * Run one validated action and return its receipt.
     *
     * All-or-throw, matching the regenerator/interpreter idiom: there is no
     * success/failure return protocol, so a caller that gets an array back has
     * value-level proof the effect landed. `verified` is unconditionally true
     * in a returned receipt precisely because a false one is unreachable — the
     * failure path throws.
     *
     * @param array<string,mixed> $args already validated by validate()
     * @return array{action:string, args:array<string,mixed>, before:array, after:array, verified:true}
     */
    public static function execute(string $action, array $args): array {
        self::validate($action, $args, "native action '$action'");
        return match ($action) {
            'transient.delete' => self::delete_transient_action($args),
        };
    }

    /**
     * Hash the exact closed native action input a scoped operation authorizes.
     * This is deliberately separate from the action digest: input_hash binds
     * this invocation's typed arguments; scoped_action_digest() binds the
     * implementation vocabulary/argument schema Apply may seal in authority.
     *
     * @param array<string,mixed> $args
     */
    public static function scoped_input_hash(string $action, array $args): string {
        self::ensure_scoped_support_loaded();
        self::validate($action, $args, "native action '$action'");
        return hash('sha256', Canon::encode([
            'kind' => 'native',
            'action' => $action,
            'args' => $args,
        ]));
    }

    /** Bind the closed native action semantics for scoped authority. */
    public static function scoped_action_digest(string $action): string {
        self::ensure_scoped_support_loaded();
        $schema = self::ACTIONS[$action] ?? null;
        if ($schema === null) {
            self::validate($action, [], "native action '$action'");
        }
        return hash('sha256', Canon::encode([
            'format' => Providers::SCOPED_OPERATION_FORMAT,
            'kind' => 'native',
            'action' => $action,
            'args' => $schema,
        ]));
    }

    /**
     * Invoke one native effect exactly once under a durable intent/receipt.
     * Returned evidence is hash-only; raw transient state stays on the stack
     * long enough to be screened and written as hashes by Providers.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $operation
     * @return array{format:string,operation:array<string,string>,capability_digest:string,before_hash:string,after_hash:string,verified:true,status:'verified'}
     */
    public static function invoke_scoped(string $action, array $args, array $operation): array {
        self::ensure_scoped_support_loaded();
        self::validate($action, $args, "native action '$action'");
        $operation = Providers::validate_scoped_operation($operation);
        $inputHash = self::scoped_input_hash($action, $args);
        if (!hash_equals($inputHash, $operation['input_hash'])) {
            throw new \RuntimeException(
                "duo: scoped native action '$action' operation input_hash does not bind the exact typed invocation"
            );
        }
        Providers::begin_scoped_operation(self::SCOPED_OWNER, $action, $operation);
        try {
            $receipt = self::execute($action, $args);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: scoped native action '$action' invocation failed");
        }
        $stored = Providers::complete_scoped_operation(
            self::SCOPED_OWNER,
            $action,
            $operation,
            $receipt['before'],
            $receipt['after']
        );
        return self::reviewed_scoped_result($action, $operation, $stored, true);
    }

    /**
     * Reconcile a native operation without executing it again.  An absent
     * transient cannot prove this operation ran — it may have pre-existed — so
     * only an exact verified durable receipt returns verified. An intent-only
     * record is rejected by Providers::scoped_operation_state() as unknown.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $operation
     * @return array{format:string,operation:array<string,string>,capability_digest:string,before_hash:?string,after_hash:?string,verified:bool,status:'verified'|'not_started'}
     */
    public static function reconcile_scoped(string $action, array $args, array $operation): array {
        self::ensure_scoped_support_loaded();
        self::validate($action, $args, "native action '$action'");
        $operation = Providers::validate_scoped_operation($operation);
        $inputHash = self::scoped_input_hash($action, $args);
        if (!hash_equals($inputHash, $operation['input_hash'])) {
            throw new \RuntimeException(
                "duo: scoped native action '$action' operation input_hash does not bind the exact typed invocation"
            );
        }
        $stored = Providers::scoped_operation_state(self::SCOPED_OWNER, $action, $operation);
        if ($stored['status'] === 'not_started') {
            return self::reviewed_scoped_result($action, $operation, $stored, false);
        }
        $after = match ($action) {
            'transient.delete' => self::reconcile_deleted_transient((string) $args['name']),
        };
        if (!hash_equals($stored['after_hash'], Providers::scoped_evidence_digest($after))) {
            throw new \RuntimeException(
                "duo: scoped native action '$action' effect readback does not match its durable operation receipt; recovery_required"
            );
        }
        return self::reviewed_scoped_result($action, $operation, $stored, true);
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $state */
    private static function reviewed_scoped_result(
        string $action,
        array $operation,
        array $state,
        bool $verified
    ): array {
        $digest = self::scoped_action_digest($action);
        if (!$verified) {
            if (($state['status'] ?? null) !== 'not_started') {
                throw new \RuntimeException('duo: scoped native action receipt has an invalid not_started state');
            }
            return [
                'format' => Providers::SCOPED_RECEIPT_FORMAT,
                'operation' => $operation,
                'capability_digest' => $digest,
                'before_hash' => null,
                'after_hash' => null,
                'verified' => false,
                'status' => 'not_started',
            ];
        }
        if (($state['status'] ?? null) !== 'verified'
            || !is_string($state['before_hash'] ?? null)
            || !is_string($state['after_hash'] ?? null)) {
            throw new \RuntimeException('duo: scoped native action receipt has an invalid verified state');
        }
        return [
            'format' => Providers::SCOPED_RECEIPT_FORMAT,
            'operation' => $operation,
            'capability_digest' => $digest,
            'before_hash' => $state['before_hash'],
            'after_hash' => $state['after_hash'],
            'verified' => true,
            'status' => 'verified',
        ];
    }

    /** @return array{value_row:bool, timeout_row:bool, cached:bool} */
    private static function reconcile_deleted_transient(string $name): array {
        $after = self::transient_state($name);
        $survivors = [];
        if ($after['value_row']) {
            $survivors[] = "option row _transient_$name";
        }
        if ($after['timeout_row']) {
            $survivors[] = "option row _transient_timeout_$name";
        }
        if ($after['cached']) {
            $survivors[] = "object cache entry transient/$name";
        }
        if ($survivors !== []) {
            throw new \RuntimeException(
                "duo: scoped native action 'transient.delete' left '$name' present during read-only reconciliation ("
                . implode(', ', $survivors) . ') — recovery_required'
            );
        }
        return $after;
    }

    /** Load scoped-only helpers lazily so Policy's offline native vocabulary
     * remains loadable under its long-standing include order. */
    private static function ensure_scoped_support_loaded(): void {
        if (!class_exists(Canon::class, false)) {
            require_once __DIR__ . '/Canon.php';
        }
        if (!class_exists(Providers::class, false)) {
            require_once __DIR__ . '/Providers.php';
        }
    }

    /**
     * delete_transient() covers both storage paths (object-cache group and
     * option rows) and fires the same hooks a plugin's own invalidation would,
     * which is why the engine calls it rather than deleting rows directly.
     *
     * Its bool return is deliberately not the success signal: WordPress
     * returns false both when the row was absent to begin with (already
     * converged) and when the delete failed, and WP-CLI's own `transient
     * delete` treats the absent case as success for the same reason. The
     * verification is therefore value-level — a fresh, checked read proving
     * both option rows are gone and the cache group no longer answers — so a
     * surviving row fails the apply instead of passing as "command exited 0".
     *
     * @param array<string,mixed> $args
     * @return array{action:string, args:array<string,mixed>, before:array, after:array, verified:true}
     */
    private static function delete_transient_action(array $args): array {
        $name = (string) $args['name'];
        if (!function_exists('delete_transient') || !function_exists('wp_cache_get')) {
            throw new \RuntimeException(
                "duo: native action 'transient.delete' requires a loaded WordPress runtime; "
                . 'run it through the ordinary apply path'
            );
        }
        $before = self::transient_state($name);
        delete_transient($name);
        $after = self::transient_state($name);
        $survivors = [];
        if ($after['value_row']) {
            $survivors[] = "option row _transient_$name";
        }
        if ($after['timeout_row']) {
            $survivors[] = "option row _transient_timeout_$name";
        }
        if ($after['cached']) {
            $survivors[] = "object cache entry transient/$name";
        }
        if ($survivors !== []) {
            throw new \RuntimeException(
                "duo: native action 'transient.delete' left '$name' present after deletion ("
                . implode(', ', $survivors) . ') — the target still serves the stale value; '
                . 'check for a persistent object cache that refused the delete, then retry the apply'
            );
        }
        return [
            'action' => 'transient.delete',
            'args' => ['name' => $name],
            'before' => $before,
            'after' => $after,
            'verified' => true,
        ];
    }

    /** @return array{value_row:bool, timeout_row:bool, cached:bool} */
    private static function transient_state(string $name): array {
        // Keep option reads first: they are checked reads, and an error there
        // must still abort before this action makes any cache observation.
        $valueRow = self::option_row_present('_transient_' . $name, $name);
        $timeoutRow = self::option_row_present('_transient_timeout_' . $name, $name);
        // `false` is a storable object-cache value, so wp_cache_get()'s
        // return value cannot establish absence. Core's fourth, by-reference
        // argument is the separate presence bit. Leave it null initially so
        // a nonconforming cache wrapper cannot turn an unknown result into a
        // false absence claim.
        $found = null;
        wp_cache_get($name, 'transient', false, $found);
        if (!is_bool($found)) {
            throw new \RuntimeException(
                "duo: native action 'transient.delete' object-cache presence read failed for transient '$name'; "
                . 'wp_cache_get() did not provide its required found flag'
            );
        }
        return [
            'value_row' => $valueRow,
            'timeout_row' => $timeoutRow,
            'cached' => $found,
        ];
    }

    /**
     * WordPress database reads return empty-looking values on SQL failure, so
     * a receipt may only clear once a real empty result has been told apart
     * from a failed query (the same checked-read discipline every adapter's
     * decision reads use).
     */
    private static function option_row_present(string $option, string $transient): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        if ($found === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                "duo: native action 'transient.delete' option-row read failed for transient '$transient'"
            );
        }
        return $found !== null;
    }
}
