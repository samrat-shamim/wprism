<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/WpCliChildProcess.php';
require_once __DIR__ . '/../Kernel/BoundedChildProcess.php';
require_once __DIR__ . '/../Kernel/PrivateEvidenceException.php';
require_once __DIR__ . '/NativeRewriteEffects.php';

/**
 * The closed native-action vocabulary: engine-implemented operations whose
 * semantics belong to WordPress core rather than to any one plugin.
 *
 * The boundary doctrine bounds this file precisely
 * (docs/adapter-boundary.md, "Structured native action"): a native action is
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
    private const REWRITE_FRESH_FORMAT = 'wprism-rewrite-flush-fresh/v1';
    private const REWRITE_FRESH_COMMAND = 'eval \'define("WPRISM_REWRITE_FLUSH_FRESH_PROCESS", true); $receipt = \\WPrism\\NativeActions::execute("rewrite.flush", []); echo json_encode(["format" => "wprism-rewrite-flush-fresh/v1", "after" => $receipt["after"]], JSON_THROW_ON_ERROR);\'';
    private const REWRITE_PARENT_CACHE_KEYS = [
        'rewrite_rules',
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
        'alloptions',
        'notoptions',
    ];
    /**
     * action name => argument schema (key => {type, required, pattern?}).
     *
     * v1 has two actions. `transient.delete` earns native status because
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
        // Apply writes authored options through direct SQL, so changing core's
        // URL grammar cannot rely on Settings -> Permalinks to rebuild the
        // derived rewrite_rules row (WordPress 7.0.3
        // WP_Rewrite::refresh_rewrite_rules()). The operation has no manifest
        // input: it always reads the just-applied core option and performs a
        // soft database-only flush through the loaded WP_Rewrite instance.
        'rewrite.flush' => [],
    ];

    /** @return list<string> */
    public static function vocabulary(): array {
        return array_keys(self::ACTIONS);
    }

    /**
     * The per-action argument schemas validate() checks against (issue #3327).
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
                "wprism: $where names unknown native action '$action' — the engine vocabulary is closed ("
                . implode(', ', self::vocabulary()) . '); a plugin-specific operation belongs in a provider'
            );
        }
        if (array_is_list($args) && $args !== []) {
            throw new \RuntimeException("wprism: $where.args must be an object");
        }
        $unknown = array_diff(array_keys($args), array_keys($schema));
        if ($unknown !== []) {
            throw new \RuntimeException(
                "wprism: $where.args contains unknown key(s) for native action '$action': "
                . implode(', ', $unknown)
            );
        }
        foreach ($schema as $key => $rule) {
            if (!array_key_exists($key, $args)) {
                if ($rule['required']) {
                    throw new \RuntimeException(
                        "wprism: $where.args is missing required key '$key' for native action '$action'"
                    );
                }
                continue;
            }
            $value = $args[$key];
            if ($rule['type'] === 'string'
                && (!is_string($value) || preg_match($rule['pattern'], $value) !== 1)) {
                throw new \RuntimeException(
                    "wprism: $where.args.$key must be a bounded string matching {$rule['pattern']}"
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
            'rewrite.flush' => self::flush_rewrite_action(),
        };
    }

    /**
     * Strict read-only evidence for a previously completed rewrite flush.
     * Unlike rewrite_state(true), this accessor never calls
     * WP_Rewrite::wp_rewrite_rules(): that method regenerates and persists
     * rules on a cache miss. The effective runtime projection is the ordinary
     * option-filtered read which the parent process already uses to verify the
     * fresh child, while raw durable storage remains an independent witness.
     *
     * @return array{permalink_present:bool,permalink_hash:string,runtime_permalink_matches:true,rules_present:true,rules_type:'array'|'string',rules_count:int,rules_hash:string,runtime_rules_type:'array'|'string',runtime_rules_count:int,runtime_rules_hash:string}
     */
    public static function rewrite_evidence(): array {
        global $wp_rewrite;
        if (!function_exists('maybe_unserialize')
            || !function_exists('get_option')
            || !is_object($wp_rewrite)) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' read-only evidence requires a loaded WordPress rewrite runtime"
            );
        }
        $structure = self::permalink_structure_state();
        $rules = self::raw_option_state('rewrite_rules');
        $runtimeRules = get_option('rewrite_rules');
        $evidence = self::rewrite_evidence_from_values($structure, $rules, $runtimeRules);
        if (!$evidence['runtime_permalink_matches']
            || !$evidence['rules_present']
            || !self::valid_rewrite_rules_value($rules['value'], $structure)
            || !self::valid_rewrite_rules_value($runtimeRules, $structure)) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' read-only evidence found an invalid postcondition; recovery_required"
            );
        }
        return self::validated_rewrite_evidence($evidence);
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
                "wprism: scoped native action '$action' operation input_hash does not bind the exact typed invocation"
            );
        }
        Providers::begin_scoped_operation(self::SCOPED_OWNER, $action, $operation);
        try {
            $receipt = self::execute($action, $args);
        } catch (\Throwable $t) {
            throw new \RuntimeException("wprism: scoped native action '$action' invocation failed");
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
                "wprism: scoped native action '$action' operation input_hash does not bind the exact typed invocation"
            );
        }
        $stored = Providers::scoped_operation_state(self::SCOPED_OWNER, $action, $operation);
        if ($stored['status'] === 'not_started') {
            return self::reviewed_scoped_result($action, $operation, $stored, false);
        }
        $after = match ($action) {
            'transient.delete' => self::reconcile_deleted_transient((string) $args['name']),
            'rewrite.flush' => self::rewrite_state(true),
        };
        if (!hash_equals($stored['after_hash'], Providers::scoped_evidence_digest($after))) {
            throw new \RuntimeException(
                "wprism: scoped native action '$action' effect readback does not match its durable operation receipt; recovery_required"
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
                throw new \RuntimeException('wprism: scoped native action receipt has an invalid not_started state');
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
            throw new \RuntimeException('wprism: scoped native action receipt has an invalid verified state');
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
                "wprism: scoped native action 'transient.delete' left '$name' present during read-only reconciliation ("
                . implode(', ', $survivors) . ') — recovery_required'
            );
        }
        return $after;
    }

    /** Load scoped-only helpers lazily so Policy's offline native vocabulary
     * remains loadable under its long-standing include order. */
    private static function ensure_scoped_support_loaded(): void {
        if (!class_exists(Canon::class, false)) {
            require_once __DIR__ . '/../Kernel/Canon.php';
        }
        if (!class_exists(Providers::class, false)) {
            require_once __DIR__ . '/../Adapter/Providers.php';
        }
    }

    /**
     * Rebuild WordPress core's persisted rewrite grammar after Apply changed
     * permalink_structure without hooks. Rewrite registrations are assembled
     * while WordPress boots: calling WP_Rewrite::init() late clears some
     * extension rules while retaining taxonomy/post-type permastructs built
     * under the old grammar. A fixed engine-owned WP-CLI child therefore boots
     * against the applied row, performs the soft flush, and returns hash-only
     * evidence which this process checks against raw durable storage.
     *
     * The command is constant engine code, never manifest input. `false` is
     * passed to flush_rules(), so `.htaccess`/web.config remain target-owned.
     * An absent permalink_structure remains absent: the fresh runtime reads
     * WordPress's semantic false/empty default but this action writes only the
     * derived rewrite_rules row.
     *
     * @return array{action:string,args:array{},before:array,after:array,verified:true}
     */
    private static function flush_rewrite_action(): array {
        global $wp_rewrite;
        if (defined('WPRISM_REWRITE_FLUSH_FRESH_PROCESS')
            && constant('WPRISM_REWRITE_FLUSH_FRESH_PROCESS') === true) {
            return self::flush_rewrite_in_fresh_process();
        }
        if (!class_exists('\WP_CLI')
            || !function_exists('maybe_unserialize')
            || !function_exists('get_option')
            || !function_exists('wp_cache_delete')
            || !is_object($wp_rewrite)) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' requires a loaded WordPress/WP-CLI rewrite runtime; "
                . 'run it through the ordinary apply path'
            );
        }

        $before = self::rewrite_state(false);
        $structure = self::permalink_structure_state();
        try {
            $result = WpCliChildProcess::capture(self::REWRITE_FRESH_COMMAND, 120, 262144, 131072);
        } catch (\Throwable $failure) {
            throw new PrivateEvidenceException(
                "wprism: native action 'rewrite.flush' could not launch its fresh WordPress process; recovery_required",
                $failure
            );
        }

        // Receipt parsing owns acceptance, but not a second diagnostics stack.
        // Retain the untrimmed capture through the same bounded private graph
        // as provider children; public messages and known native errors stay
        // fixed. A rejected receipt cannot imply rollback of the child effect.
        $stdout = trim($result['stdout']);
        $stderr = trim($result['stderr']);
        if ($result['return_code'] !== 0) {
            self::throw_known_rewrite_child_failure($stdout . "\n" . $stderr);
            throw BoundedChildProcess::failure_evidence(
                "wprism: native action 'rewrite.flush' fresh WordPress process exited "
                . $result['return_code'] . '; recovery_required',
                $result
            );
        }
        if ($stderr !== '') {
            throw BoundedChildProcess::failure_evidence(
                "wprism: native action 'rewrite.flush' fresh WordPress process emitted a warning; recovery_required",
                $result
            );
        }
        $lines = preg_split('/\R/', $stdout) ?: [];
        $json = (string) end($lines);
        try {
            $fresh = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw BoundedChildProcess::failure_evidence(
                "wprism: native action 'rewrite.flush' fresh WordPress process returned malformed evidence; "
                . 'recovery_required',
                $result,
                $failure
            );
        }
        if (!is_array($fresh)
            || ($fresh['format'] ?? null) !== self::REWRITE_FRESH_FORMAT
            || !is_array($fresh['after'] ?? null)) {
            throw BoundedChildProcess::failure_evidence(
                "wprism: native action 'rewrite.flush' fresh WordPress process returned the wrong evidence envelope; "
                . 'recovery_required',
                $result
            );
        }
        try {
            $after = self::validated_rewrite_evidence($fresh['after']);
        } catch (\RuntimeException $failure) {
            throw BoundedChildProcess::failure_evidence($failure->getMessage(), $result, $failure);
        }
        $desiredHash = hash('sha256', $structure['present'] ? $structure['value'] : '');
        $storedStructure = self::permalink_structure_state();
        $storedRules = self::raw_option_state('rewrite_rules');
        // The child invalidated its own option cache after persisting the new
        // rules, not this already-booted process's cache. Earlier actions in a
        // multi-adapter batch can have populated a stale named/alloptions/
        // notoptions entry here; discard the complete child-written roster
        // before effective readback so durable storage remains the witness.
        foreach (self::REWRITE_PARENT_CACHE_KEYS as $cacheKey) {
            wp_cache_delete($cacheKey, 'options');
        }
        $effectiveRules = get_option('rewrite_rules');
        if (!hash_equals($desiredHash, $after['permalink_hash'])
            || $storedStructure['present'] !== $after['permalink_present']
            || !hash_equals(
                hash('sha256', $storedStructure['present'] ? $storedStructure['value'] : ''),
                $after['permalink_hash']
            )
            || !$storedRules['present']
            || !self::valid_rewrite_rules_value($storedRules['value'], $storedStructure)
            || get_debug_type($storedRules['value']) !== $after['rules_type']
            || self::rewrite_rules_count($storedRules['value']) !== $after['rules_count']
            || !hash_equals(
                (string) self::rewrite_rules_hash($storedRules['value']),
                $after['rules_hash']
            )
            || !self::valid_rewrite_rules_value($effectiveRules, $storedStructure)
            || get_debug_type($effectiveRules) !== $after['runtime_rules_type']
            || self::rewrite_rules_count($effectiveRules) !== $after['runtime_rules_count']
            || !hash_equals(
                (string) self::rewrite_rules_hash($effectiveRules),
                $after['runtime_rules_hash']
            )) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' fresh-process evidence disagrees with checked durable storage; "
                . 'recovery_required'
            );
        }

        // This process was booted under the old grammar and must never be used
        // to regenerate rules, but keeping its two directly-read surfaces in
        // sync prevents later read-only consumers from serving the stale row.
        $wp_rewrite->permalink_structure = $storedStructure['present'] ? $storedStructure['value'] : false;
        $wp_rewrite->rules = $effectiveRules;
        return [
            'action' => 'rewrite.flush',
            'args' => [],
            'before' => $before,
            'after' => $after,
            'verified' => true,
        ];
    }

    /** @return array{action:string,args:array{},before:array,after:array,verified:true} */
    private static function flush_rewrite_in_fresh_process(): array {
        global $wp_rewrite;
        if (!function_exists('did_action')
            || !function_exists('maybe_unserialize')
            || !function_exists('sanitize_option')
            || !is_object($wp_rewrite)
            || !method_exists($wp_rewrite, 'flush_rules')
            || !method_exists($wp_rewrite, 'wp_rewrite_rules')) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' requires a loaded WordPress rewrite runtime in its fresh process"
            );
        }
        if ((int) did_action('wp_loaded') < 1) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' refused before wp_loaded; WordPress would defer "
                . 'the rewrite mutation beyond the verified apply boundary'
            );
        }

        $rewriteEffects = NativeRewriteEffects::prepare();
        $result = null;
        $primary = null;
        try {
            $before = self::rewrite_state(false);
            $structure = self::permalink_structure_state();
            if (!self::runtime_structure_matches($wp_rewrite->permalink_structure ?? null, $structure)) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' fresh WordPress process loaded a permalink runtime "
                    . 'which disagrees with the checked database row; recovery_required'
                );
            }
            $wp_rewrite->flush_rules(false);
            $generatedRules = $wp_rewrite->rules ?? null;
            if (!self::valid_rewrite_rules_value($generatedRules, $structure)) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' did not generate a valid rewrite runtime; "
                    . 'recovery_required'
                );
            }
            $expectedEffectiveRules = $rewriteEffects->expected_effective_rules($generatedRules);
            $after = self::rewrite_state(true, $generatedRules, $expectedEffectiveRules);
            $desired = $structure['present'] ? $structure['value'] : '';
            if (!hash_equals(hash('sha256', $desired), $after['permalink_hash'])) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' permalink readback changed during regeneration; "
                    . 'recovery_required'
                );
            }
            $result = [
                'action' => 'rewrite.flush',
                'args' => [],
                'before' => $before,
                'after' => $after,
                'verified' => true,
            ];
        } catch (\Throwable $failure) {
            $primary = $failure;
        }
        $restoreFailure = null;
        try {
            $rewriteEffects->restore();
        } catch (\Throwable $failure) {
            $restoreFailure = $failure;
        }
        if ($restoreFailure !== null) {
            $primaryFingerprint = $primary === null
                ? 'none'
                : get_class($primary) . ':' . substr(hash('sha256', $primary->getMessage()), 0, 16);
            throw new \RuntimeException(
                'wprism: native rewrite could not restore the proven shipped-plugin runtime; primary='
                . $primaryFingerprint . '; restore=' . get_class($restoreFailure) . ':'
                . substr(hash('sha256', $restoreFailure->getMessage()), 0, 16)
                . '; recovery_required',
                0,
                $primary ?? $restoreFailure
            );
        }
        if ($primary !== null) {
            throw $primary;
        }
        if (!is_array($result)) {
            throw new \LogicException('wprism: native rewrite completed without a result');
        }
        return $result;
    }

    /** @param array<string,mixed> $after @return array<string,mixed> */
    private static function validated_rewrite_evidence(array $after): array {
        $keys = [
            'permalink_present',
            'permalink_hash',
            'runtime_permalink_matches',
            'rules_present',
            'rules_type',
            'rules_count',
            'rules_hash',
            'runtime_rules_type',
            'runtime_rules_count',
            'runtime_rules_hash',
        ];
        if (array_keys($after) !== $keys
            || !is_bool($after['permalink_present'] ?? null)
            || !is_string($after['permalink_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $after['permalink_hash']) !== 1
            || ($after['runtime_permalink_matches'] ?? null) !== true
            || ($after['rules_present'] ?? null) !== true
            || !in_array($after['rules_type'] ?? null, ['array', 'string'], true)
            || !is_int($after['rules_count'] ?? null)
            || $after['rules_count'] < 0
            || ($after['rules_type'] === 'string' && $after['rules_count'] !== 0)
            || !is_string($after['rules_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $after['rules_hash']) !== 1
            || !in_array($after['runtime_rules_type'] ?? null, ['array', 'string'], true)
            || !is_int($after['runtime_rules_count'] ?? null)
            || $after['runtime_rules_count'] < 0
            || ($after['runtime_rules_type'] === 'string' && $after['runtime_rules_count'] !== 0)
            || !is_string($after['runtime_rules_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $after['runtime_rules_hash']) !== 1) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' fresh WordPress process returned invalid hash/count evidence; "
                . 'recovery_required'
            );
        }
        return $after;
    }

    /** Re-emit only reviewed child diagnostics; arbitrary stderr stays private. */
    private static function throw_known_rewrite_child_failure(string $output): void {
        $known = [
            "wprism: native action 'rewrite.flush' requires a loaded WordPress rewrite runtime in its fresh process",
            "wprism: native action 'rewrite.flush' refused before wp_loaded; WordPress would defer the rewrite mutation beyond the verified apply boundary",
            "wprism: native action 'rewrite.flush' fresh WordPress process loaded a permalink runtime which disagrees with the checked database row; recovery_required",
            "wprism: native action 'rewrite.flush' did not generate a valid rewrite runtime; recovery_required",
            "wprism: native action 'rewrite.flush' generated rewrite rules disagree with the checked database row; recovery_required",
            "wprism: native action 'rewrite.flush' did not persist a valid rewrite_rules postcondition; recovery_required",
            "wprism: native action 'rewrite.flush' generated rewrite rules disagree with the loaded effective rules; recovery_required",
            "wprism: native action 'rewrite.flush' loaded permalink structure disagrees with the checked database row; recovery_required",
            "wprism: native action 'rewrite.flush' permalink readback changed during regeneration; recovery_required",
        ];
        foreach ($known as $message) {
            if (str_contains($output, $message)) {
                throw new \RuntimeException($message);
            }
        }
    }

    /**
     * Hash-only rewrite evidence. The permalink grammar and regular-expression
     * rules can contain site paths supplied by extensions, so receipts expose
     * only presence, types, counts, and SHA-256 digests.
     *
     * @return array{permalink_present:bool,permalink_hash:string,runtime_permalink_matches:bool,rules_present:bool,rules_type:string,rules_count:?int,rules_hash:?string,runtime_rules_type:string,runtime_rules_count:?int,runtime_rules_hash:?string}
     */
    private static function rewrite_state(
        bool $strict,
        $expectedRules = null,
        $expectedEffectiveRules = null
    ): array {
        global $wp_rewrite;
        $structure = self::permalink_structure_state();
        $rules = self::raw_option_state('rewrite_rules');
        $runtimeRules = $wp_rewrite->rules ?? null;
        if ($strict) {
            $expectedStoredRules = $expectedRules === null
                ? null
                : sanitize_option('rewrite_rules', $expectedRules);
            if ($expectedStoredRules !== null
                && !self::same_rewrite_rules_value($expectedStoredRules, $rules['value'])) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' generated rewrite rules disagree with the checked "
                    . 'database row; recovery_required'
                );
            }
            $runtimeRules = $wp_rewrite->wp_rewrite_rules();
            if (!$rules['present']
                || !self::valid_rewrite_rules_value($rules['value'], $structure)
                || !self::valid_rewrite_rules_value($runtimeRules, $structure)) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' did not persist a valid rewrite_rules "
                    . 'postcondition; recovery_required'
                );
            }
            $effectiveExpectation = $expectedEffectiveRules ?? $expectedRules;
            if ($effectiveExpectation !== null
                && !self::same_rewrite_rules_value($effectiveExpectation, $runtimeRules)) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' generated rewrite rules disagree with the loaded "
                    . 'effective rules; recovery_required'
                );
            }
            if (!self::runtime_structure_matches($wp_rewrite->permalink_structure ?? null, $structure)) {
                throw new \RuntimeException(
                    "wprism: native action 'rewrite.flush' loaded permalink structure disagrees with the "
                    . 'checked database row; recovery_required'
                );
            }
        }
        return self::rewrite_evidence_from_values($structure, $rules, $runtimeRules);
    }

    /**
     * @param array{present:bool,value:string} $structure
     * @param array{present:bool,value:mixed} $rules
     * @return array{permalink_present:bool,permalink_hash:string,runtime_permalink_matches:bool,rules_present:bool,rules_type:string,rules_count:?int,rules_hash:?string,runtime_rules_type:string,runtime_rules_count:?int,runtime_rules_hash:?string}
     */
    private static function rewrite_evidence_from_values(array $structure, array $rules, mixed $runtimeRules): array {
        global $wp_rewrite;
        $ruleValue = $rules['value'];
        return [
            'permalink_present' => $structure['present'],
            'permalink_hash' => hash('sha256', $structure['present'] ? $structure['value'] : ''),
            'runtime_permalink_matches' => self::runtime_structure_matches(
                $wp_rewrite->permalink_structure ?? null,
                $structure
            ),
            'rules_present' => $rules['present'],
            'rules_type' => get_debug_type($ruleValue),
            'rules_count' => self::rewrite_rules_count($ruleValue),
            'rules_hash' => self::rewrite_rules_hash($ruleValue),
            'runtime_rules_type' => get_debug_type($runtimeRules),
            'runtime_rules_count' => self::rewrite_rules_count($runtimeRules),
            'runtime_rules_hash' => self::rewrite_rules_hash($runtimeRules),
        ];
    }

    /** @return array{present:bool,value:string} */
    private static function permalink_structure_state(): array {
        $state = self::raw_option_state('permalink_structure');
        if ($state['present'] && !is_string($state['value'])) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' requires permalink_structure to be a plain string; "
                . 'the target row is malformed'
            );
        }
        return [
            'present' => $state['present'],
            'value' => $state['present'] ? $state['value'] : '',
        ];
    }

    /** @param array{present:bool,value:string} $structure */
    private static function runtime_structure_matches($runtime, array $structure): bool {
        if ($structure['present']) {
            return is_string($runtime) && hash_equals($structure['value'], $runtime);
        }
        return $runtime === false || $runtime === '';
    }

    /** @return array{present:bool,value:mixed} */
    private static function raw_option_state(string $name): array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $name
        ), ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' checked option read failed for '$name'"
            );
        }
        if ($row === null) {
            return ['present' => false, 'value' => null];
        }
        $raw = $row['option_value'] ?? null;
        if (!is_string($raw)) {
            throw new \RuntimeException(
                "wprism: native action 'rewrite.flush' checked option read returned a non-string value for '$name'"
            );
        }
        return ['present' => true, 'value' => maybe_unserialize($raw)];
    }

    /** WordPress stores plain-permalink rewrite state as the exact empty string. */
    private static function valid_rewrite_rules_value($rules, array $structure): bool {
        return is_array($rules) || ($structure['value'] === '' && $rules === '');
    }

    private static function same_rewrite_rules_value($left, $right): bool {
        $leftHash = self::rewrite_rules_hash($left);
        $rightHash = self::rewrite_rules_hash($right);
        return get_debug_type($left) === get_debug_type($right)
            && $leftHash !== null
            && $rightHash !== null
            && hash_equals($leftHash, $rightHash);
    }

    private static function rewrite_rules_count($rules): ?int {
        if (is_array($rules)) {
            return count($rules);
        }
        return $rules === '' ? 0 : null;
    }

    private static function rewrite_rules_hash($rules): ?string {
        return is_array($rules) || $rules === '' ? hash('sha256', serialize($rules)) : null;
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
                "wprism: native action 'transient.delete' requires a loaded WordPress runtime; "
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
                "wprism: native action 'transient.delete' left '$name' present after deletion ("
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
                "wprism: native action 'transient.delete' object-cache presence read failed for transient '$name'; "
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
                "wprism: native action 'transient.delete' option-row read failed for transient '$transient'"
            );
        }
        return $found !== null;
    }
}
