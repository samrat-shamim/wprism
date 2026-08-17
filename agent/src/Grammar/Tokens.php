<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/IdentityTokenCodec.php';
require_once __DIR__ . '/../Kernel/StructuredReferenceCodec.php';
require_once __DIR__ . '/../Kernel/TextTokenizer.php';
require_once __DIR__ . '/../Kernel/UrlQueryReferenceCodec.php';
require_once __DIR__ . '/../Kernel/ReferenceScopeClassifier.php';

/**
 * Environment-bound values are tokenized at capture and re-bound at apply:
 *   {{home}}, {{uploads}}, {{post:<uuid>}}, {{term:<uuid>}}, {{tt:<uuid>}}
 * Numeric positions carry the quoted token in canonical form; the applier
 * restores the declared numeric type. All rewriting is structure-aware or a
 * declared textual pattern — never a blind regex over content.
 */
final class Tokens {
    private string $home;
    private string $uploadsUrl;
    /** The pure {{home}}/{{uploads}} URL-prefix substitution lives in
     *  TextTokenizer (DUO-3354); the distinct query-reference protocol is
     *  delegated separately to UrlQueryReferenceCodec below. */
    private TextTokenizer $textTokenizer;
    /** @var string[] capture-time warnings (unmapped ids etc.) */
    public array $warnings = [];
    /** @var string[] capture-time informational observations */
    public array $notes = [];
    /**
     * @var list<array{post:string,block:string,attr:string,kind:string,id:int,target_type:string}>
     * Block-ref violations of the SAME shape task #73 gives ref-typed
     * options: a "kind"/"kind_from" block_attrs
     * ref whose id names a REAL row genuinely outside policy scope, as
     * opposed to a merely dangling one (Blocks::walk() itself resolves the
     * dangling-vs-unscoped question via ReferenceScopeClassifier + the
     * live Policy, since Blocks.php has no persistent instance state of its
     * own to hold this across a recursive innerBlocks walk — this array,
     * like $warnings above, is the side-channel). Populated during
     * Blocks::capture_rewrite(); CaptureCandidateBuilder reads it after the whole
     * post loop completes (accumulates across every post in one build, the
     * same way $warnings does) and throws its own batched abort if
     * non-empty — see build()'s gate for the exact posture and message.
     */
    public array $unscopedBlockRefs = [];
    /**
     * @var list<array{post:string,shortcode:string,attr:string,kind:string,id:int,target_type:string}>
     * Shortcode-attribute-ref violations, the SAME shape as
     * $unscopedBlockRefs above (task #73's triage, ported a second time
     * this session — DUO-3259 — for Shortcodes.php): a declared
     * shortcode_attrs ref whose id names a REAL row genuinely outside
     * policy scope, as opposed to a merely dangling one. Shortcodes.php
     * resolves the dangling-vs-unscoped question via the shared
     * ReferenceScopeClassifier helper (itself just the extracted
     * core of the same decision Blocks::queue_unscoped() makes inline),
     * since Shortcodes.php — like Blocks.php — has no persistent instance
     * state of its own to hold this across a walk over one post's content,
     * let alone across every post in one build; this array, like
     * $warnings/$unscopedBlockRefs above, is the side-channel. Populated
     * during Shortcodes::capture_rewrite_text(); CaptureCandidateBuilder reads it
     * after the whole post loop completes and throws its own batched abort
     * if non-empty — see build()'s gate for the exact posture and message.
     */
    public array $unscopedShortcodeRefs = [];
    /**
     * @var list<array{context:string,param:string,id:int,target_type:string}>
     * URL-query-ref violations (DUO-3260, task #73's triage ported a
     * third time): a `?p=`/`?page_id=`/`?attachment_id=` value whose id
     * names a REAL row genuinely outside policy scope. Unlike $unscoped
     * BlockRefs/$unscopedShortcodeRefs above, tokenize_text() is called
     * from many contexts that aren't "one post's own content" (option
     * values, term descriptions, menu item urls -- see its own call
     * sites) so there's no single natural "postLabel" the way Blocks.php/
     * Shortcodes.php have one; `context` is best-effort, populated only
     * where a caller has a cheap label handy (empty string otherwise,
     * never fabricated). Populated by tokenize_text() itself via $this->
     * policy/$this->forceUnresolvedRefs (see those properties' own
     * docblocks for why they're stored on the instance instead of
     * threaded as call parameters through every one of tokenize_text()'s
     * many call sites) rather than passed in per call. CaptureCandidateBuilder
     * reads it after the whole build completes and throws its own
     * batched abort if non-empty — see build()'s gate for the exact
     * posture and message.
     */
    public array $unscopedUrlQueryRefs = [];
    /** @var array<int,string> user id -> login (capture direction) */
    private array $userLogins = [];
    /** @var array<string,int> login -> user id (apply direction) */
    private array $userIds = [];
    /** Canonical alternate identifiers indexed by positional shortcode lookup. */
    private array $shortcodeAlternates = [];
    /** Reverse witness index: one alternate value may identify only one entity
     * within a declared (post-meta, post-type) domain. */
    private array $shortcodeAlternateValues = [];
    /** Apply has finished registering the immutable canonical alternate map. */
    private bool $shortcodeAlternatesSealed = false;
    /** Fallback for unresolvable user tokens on apply (set by Apply). */
    public ?int $defaultUserId = null;
    /**
     * DUO-3260: set once by Capture (constructor — Capture always has a
     * Policy from its own construction) rather than threaded as a
     * parameter through tokenize_text()'s many call sites (block_attrs'
     * "tokenize":"text" rule, the main content rewrite closure,
     * tokenize_leaves()'s own recursive JSON-leaf walk, six more direct
     * call sites in Capture.php itself for excerpts/descriptions/menu-
     * item urls/plain option values) — an optional per-call parameter
     * that's easy to forget would silently SKIP the unscoped-ref safety
     * check at whichever call site omitted it; a required instance
     * property set exactly once cannot be silently forgotten the same
     * way. Nullable only so a Tokens instance can theoretically exist
     * before Capture finishes constructing it — or, per Apply.php's own
     * instance (constructed at Apply.php:38, never given a Policy),
     * exist for its ENTIRE life without ever needing one. That claim is
     * traced exhaustively, not assumed: every one of Apply's 20+
     * `$this->tokens->...` call sites is apply-direction
     * (detokenize_text/token_to_id/struct_apply/tokens_to_value/
     * meta_tokens_to_value) or a read-only accessor (home/warnings/
     * defaultUserId); struct_apply() itself only ever calls
     * tokenize_leaves($value, false) — detokenize; and Apply's one call
     * into Blocks::apply_rewrite() pins $capture=false at that call site
     * (threaded unchanged through every recursive walk()), which is the
     * same flag that gates tokenize_text() vs. detokenize_text() inside
     * $rewriteString AND the same flag Shortcodes::capture_rewrite_text()
     * vs. apply_rewrite_text() is chosen on — so tokenize_text() is
     * structurally unreachable through Apply's Tokens instance on every
     * path (full call-site enumeration in the DUO-3260 PR body).
     * Because that is a proof about the CURRENT call graph and not a
     * language-level guarantee against a future caller constructing its
     * own Tokens for capture-direction work and forgetting this, null is
     * NOT silently tolerated at the point where it would actually matter:
     * queue_unscoped_url_query_ref() below throws rather than skipping
     * the classification it can't perform without a Policy. (A blanket
     * guard at the top of tokenize_text() itself was considered and
     * rejected — most call sites use it only for the home/uploads
     * substitution and have no reason to ever populate $policy; two
     * already-shipped suites, regress_block_refs.php and
     * regress_shortcode_refs.php, construct bare Tokens instances with
     * no $policy for exactly that reason, and both call tokenize_text()
     * legitimately. The guard is scoped to the one mechanism that
     * actually consumes $policy, not the shared entry point.)
     */
    public ?Policy $policy = null;
    /** DUO-3260: mirrors $policy above — set once per build (Capture::
     *  build()'s own $forceUnresolvedRefs parameter), not threaded
     *  per call, for the identical "can't be silently forgotten"
     *  reason. */
    public bool $forceUnresolvedRefs = false;

    public function __construct() {
        $this->home = untrailingslashit((string) get_option('home'));
        $up = wp_upload_dir(null, false);
        $this->uploadsUrl = untrailingslashit((string) $up['baseurl']);
        $this->textTokenizer = new TextTokenizer($this->home, $this->uploadsUrl);
    }

    public function home(): string {
        return $this->home;
    }

    // ---- text (URLs) ----

    /**
     * The public text facade combines TextTokenizer's environment URL-prefix
     * substitution with UrlQueryReferenceCodec's declared post-id query-ref
     * rewrite. Tokens supplies the Ledger lookup and its policy-aware capture
     * observation sink; neither collaborator owns those stateful concerns.
     */
    public function tokenize_text(string $s, string $contextLabel = ''): string {
        if ($s === '') {
            return $s;
        }
        return UrlQueryReferenceCodec::capture(
            $this->textTokenizer->tokenize($s),
            $contextLabel,
            fn(int $id): ?string => $this->id_to_token($id, 'post'),
            function (string $warning): void { $this->warnings[] = $warning; },
            function (string $context, string $param, int $id): void {
                $this->queue_unscoped_url_query_ref($context, $param, $id);
            }
        );
    }

    /**
     * Mirrors Blocks::queue_unscoped()/Shortcodes::queue_unscoped() but
     * delegates the whole three-way decision to ReferenceScopeClassifier
     * directly (the extraction DUO-3259 added specifically
     * so a third caller wouldn't need a third hand-copy) rather than
     * re-deriving it. Called by UrlQueryReferenceCodec's unresolved sink for EVERY id
     * that id_to_token() fails to resolve, before classification —
     * dangling vs. unscoped is exactly what $policy is needed to tell
     * apart, so both sub-cases hit the guard below identically when
     * $policy is unset. $this->policy being null used to be a deliberate
     * no-op ("this call site can't judge scope, never assume it's fine");
     * it is now a throw for the identical reason $policy's own docblock
     * gives — see it for the full call-graph proof that no CURRENT
     * caller can ever trigger this.
     */
    private function queue_unscoped_url_query_ref(string $contextLabel, string $param, int $id): void {
        if ($this->policy === null) {
            // Was a silent `return` (no unscoped-classification possible
            // without a Policy). Changed to a throw: silently skipping the
            // task #73 loud-and-blocking gate at exactly the point that
            // gate is supposed to fire is the precise defect class
            // $policy's own docblock explains this instance-property
            // design exists to prevent — an unconfigured instance must
            // fail loudly and immediately the first time it actually
            // needs the check, not degrade into a quiet no-op that only
            // a byte-for-byte capture diff would ever reveal. Every
            // CURRENT capture-direction caller sets $policy unconditionally
            // (Capture's own constructor requires a non-nullable Policy
            // parameter, so every Capture-owned Tokens instance has one
            // from the moment it exists); this only fires for a future
            // caller that constructs its own Tokens for capture-direction
            // work and forgets.
            throw new \RuntimeException(
                "duo: tokenize_text() found an unmapped url-query ref ('$param=$id') that needs the "
                . 'dangling-vs-unscoped triage (task #73\'s loud-and-blocking gate), but this Tokens '
                . "instance's \$policy was never set, so the triage cannot run. Refusing rather than "
                . 'silently treating it as dangling: set Tokens::$policy (and $forceUnresolvedRefs) before '
                . 'calling tokenize_text() on any content that may carry a query-string reference — every '
                . "Capture-owned Tokens instance already does this unconditionally in Capture's own "
                . 'constructor.'
            );
        }
        $targetType = ReferenceScopeClassifier::classify(
            $id,
            'post',
            $this->forceUnresolvedRefs,
            $this->policy
        );
        if ($targetType === null) {
            return;
        }
        $this->unscopedUrlQueryRefs[] = [
            'context' => $contextLabel,
            'param' => $param,
            'id' => $id,
            'target_type' => $targetType,
        ];
    }

    /** Restore environment URL tokens and the exact query-ref tokens capture emits. */
    public function detokenize_text(string $s): string {
        if ($s === '') {
            return $s;
        }
        return UrlQueryReferenceCodec::apply(
            $this->textTokenizer->detokenize($s),
            fn(string $token): int => $this->token_to_id($token)
        );
    }

    // ---- typed id refs ----

    /**
     * The three core keyspaces keep their short, historical token spelling
     * ({{post:...}} not {{Duo\Ledger::KIND_POST:...}}) for every canonical
     * file already shipped; anything else is a manifest-declared id_kind
     * STRING used verbatim as both the token's kind-name and the ledger
     * lookup kind — Ledger::id_for()/uuid_for()/set() have taken an
     * arbitrary id_kind string since day one (duo_map has no enum
     * constraint on the column, only PHP's own call sites were narrowed to
     * these three), so Snapshot.php's typed-snapshot tables (id_kind values
     * like "nf3_form", "attr_taxonomy" — see its docblock for the VARCHAR(16)
     * budget this engine has always implicitly kept within, "term_taxonomy"
     * being the prior high-water mark) need no engine-side registration
     * beyond what's already declared in their own manifest.
     */
    /**
     * A manifest-written ref KIND translated to the duo_map `id_kind` it is
     * stored under (DUO-3318).
     *
     * The rename is invisible for every declared table id_kind and for
     * post/term, and load-bearing for exactly one value: `tt` is written in
     * manifests and stored as `term_taxonomy`. Any code path that resolves a
     * manifest kind against the ledger ITSELF — rather than through
     * id_to_token()/token_to_id() above, which already apply the map — must
     * go through this, or a `tt` ref silently looks up a keyspace that has no
     * rows and answers "not here" for every entity in it. Exposed as the one
     * public spelling of the codec's kind map so a second call site cannot
     * grow a second, quietly divergent copy of the same three-entry table.
     */
    public static function ledger_kind(string $refKind): string {
        return IdentityTokenCodec::ledger_kind($refKind);
    }

    /**
     * id -> "{{<kind>:uuid}}" (capture direction). Returns null when unmapped.
     *
     * DUO-3212: deliberately silent on failure — this method has no opinion
     * on disposition, because there isn't one universal disposition. Its
     * callers currently do at least three different things with a null
     * return: drop-with-warning (options, meta, block attrs, key_refs),
     * throw (post_parent, menu item object refs, Snapshot.php's structural
     * table refs), or queue as a policy-scope violation for a batched abort
     * (OptionsCapture's option-ref path). A single message
     * emitted HERE used to claim the value was "left as-is" — true for
     * none of those outcomes (every drop path actually drops the value;
     * every throw path aborts the whole capture) — and every caller that
     * already builds its own precise, context-rich message (naming the
     * option/post/block/attribute) got that generic string prepended to
     * its own, a double warning for one event. Callers that want a warning
     * emit their own; two call sites (Tokens::struct_capture()'s json_refs
     * path, Snapshot::authored_snapshot_meta_value()'s ref handling) had no
     * warning of their own and gained one where this method's internal
     * warning used to be their only coverage — see those methods.
     * (Tokens::struct_capture()'s json_refs handling and Snapshot.php's
     * capture_meta_rows() ref handling, respectively.)
     */
    public function id_to_token(int $id, string $refKind): ?string {
        $kind = self::ledger_kind($refKind);
        if ($kind === '' || $id <= 0) {
            return null;
        }
        $uuid = Ledger::uuid_for($id, $kind);
        return IdentityTokenCodec::encode($refKind, $uuid);
    }

    /** "{{<kind>:uuid}}" -> id (apply direction). Throws when unresolvable. */
    public function token_to_id(string $token): int {
        $decoded = IdentityTokenCodec::decode($token);
        $kind = self::ledger_kind($decoded['kind']);
        $id = Ledger::id_for($decoded['uuid'], $kind);
        if ($id === null) {
            throw new \RuntimeException("duo: unresolvable ref $token (entity not in this environment)");
        }
        return $id;
    }

    public function register_shortcode_alternate(string $token, string $metaKey, string $postType, string $value): void {
        if (!preg_match('/^\{\{post:[0-9a-f-]{36}\}\}$/D', $token)
            || $metaKey === '' || $postType === '' || !self::is_positive_decimal_alternate($value)) {
            throw new \RuntimeException('duo: malformed positional shortcode alternate witness');
        }
        $domain = $metaKey . "\0" . $postType;
        $tokenKey = $domain . "\0" . $token;
        $valueKey = $domain . "\0" . $value;
        $existingToken = $this->shortcodeAlternateValues[$valueKey] ?? null;
        if ($existingToken !== null && $existingToken !== $token) {
            throw new \RuntimeException(
                "duo: positional shortcode alternate '$value' is ambiguous in $postType.$metaKey"
            );
        }
        $existingValue = $this->shortcodeAlternates[$tokenKey] ?? null;
        if ($existingValue !== null && $existingValue !== $value) {
            throw new \RuntimeException(
                "duo: positional shortcode token has conflicting $postType.$metaKey alternates"
            );
        }
        $this->shortcodeAlternates[$tokenKey] = $value;
        $this->shortcodeAlternateValues[$valueKey] = $token;
    }

    private static function is_positive_decimal_alternate(string $value): bool {
        if (!preg_match('/^[1-9][0-9]*$/D', $value)) {
            return false;
        }
        // Match CF7's bare DECIMAL meta-query domain as well as PHP's int.
        $max = PHP_INT_SIZE >= 8 ? '9999999999' : (string) PHP_INT_MAX;
        $length = strlen($value);
        return $length < strlen($max) || ($length === strlen($max) && strcmp($value, $max) <= 0);
    }

    public function shortcode_alternate(string $token, string $metaKey, string $postType): ?string {
        return $this->shortcodeAlternates[$metaKey . "\0" . $postType . "\0" . $token] ?? null;
    }

    public function seal_shortcode_alternates(): void {
        $this->shortcodeAlternatesSealed = true;
    }

    public function shortcode_alternates_sealed(): bool {
        return $this->shortcodeAlternatesSealed;
    }

    // ---- user refs (users are env-local; tokens are logins, never ids) ----

    /** id -> "user:<login>" (capture). Unresolvable users stay numeric, warned. */
    public function user_id_to_token(int $id): ?string {
        global $wpdb;
        if ($id <= 0) {
            return null;
        }
        if (!isset($this->userLogins[$id])) {
            $login = $wpdb->get_var($wpdb->prepare(
                "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $id
            ));
            $this->userLogins[$id] = $login ?: '';
        }
        if ($this->userLogins[$id] === '') {
            $this->warnings[] = "user id $id not found (env-local user gone)";
            return null;
        }
        return 'user:' . $this->userLogins[$id];
    }

    /** "user:<login>" -> id (apply), falling back to the configured default. */
    public function user_token_to_id(string $token): int {
        $login = substr($token, 5);
        if (!isset($this->userIds[$login])) {
            global $wpdb;
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login
            ));
            $this->userIds[$login] = $id ? (int) $id : 0;
        }
        if ($this->userIds[$login] > 0) {
            return $this->userIds[$login];
        }
        $fallback = $this->defaultUserId ?? 1;
        $this->warnings[] = "user '$login' not in this environment; fell back to user #$fallback";
        return $fallback;
    }

    // ---- rule-aware meta values (ref + optional cast) ----

    /**
     * Capture direction for a meta value per manifest/interpreter rule:
     * ['ref' => 'post'|'term'|'user'|'post[]'|..., 'cast' => null|'string'|'csv'].
     * 'string' preserves ids-as-strings inside serialized arrays byte-exactly
     * (ACF stores them that way); 'csv' canonicalizes a "1,2,3" string into a
     * token list re-joined on apply.
     *
     * Unmapped ids are DROPPED with a warning, never kept numeric: a raw
     * env-local id in canonical state is indistinguishable on another
     * environment from a valid id — which may resolve to an unrelated live
     * entity after auto-increment reuse (silently wrong, not just dangling).
     * Dropping converges: the corrected canonical value applies everywhere.
     * Array/csv values drop the element; a scalar ref returns null and the
     * caller skips the key (same semantics options have always had).
     */
    public function meta_value_to_tokens($value, array $rule) {
        $ref = $rule['ref'];
        $cast = $rule['cast'] ?? null;
        $one = function ($v, string $kind): ?string {
            $tok = $kind === 'user'
                ? $this->user_id_to_token((int) $v)
                : $this->id_to_token((int) $v, $kind);
            if ($tok === null) {
                $this->warnings[] = "unmapped $kind id " . (int) $v . ' dropped from ref-typed meta (dangling reference)';
            }
            return $tok;
        };
        if ($cast === 'csv') {
            $kind = rtrim($ref, '[]');
            $parts = array_values(array_filter(
                array_map('trim', explode(',', (string) $value)),
                fn($s) => $s !== ''
            ));
            return array_values(array_filter(array_map(fn($v) => $one($v, $kind), $parts)));
        }
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $out = [];
            foreach ((array) $value as $v) {
                $tok = $one($v, $kind);
                if ($tok !== null) {
                    $out[] = $tok;
                }
            }
            return $out;
        }
        return $one($value, $ref);
    }

    /** Apply direction for meta values; restores the declared storage shape. */
    public function meta_tokens_to_value($value, array $rule) {
        $cast = $rule['cast'] ?? null;
        $toId = function ($v): int {
            if (is_string($v) && str_starts_with($v, '{{')) {
                return $this->token_to_id($v);
            }
            if (is_string($v) && str_starts_with($v, 'user:')) {
                return $this->user_token_to_id($v);
            }
            return (int) $v;
        };
        if ($cast === 'csv') {
            return implode(',', array_map($toId, (array) $value));
        }
        if (str_ends_with($rule['ref'], '[]')) {
            $ids = array_map($toId, (array) $value);
            return $cast === 'string' ? array_map('strval', $ids) : $ids;
        }
        $id = $toId($value);
        return $cast === 'string' ? (string) $id : $id;
    }

    // ---- structured (JSON-path) refs: json_refs + key_refs ----
    // (task #11 wave 2 / docs/frontier/elementor.md, docs/frontier/
    // polylang.md's `polylang` option finding, design-review-v0 finding #9)
    //
    // Operate on an already-DECODED native structure — Capture/Apply own
    // deciding HOW to decode/re-encode the raw stored value (a JSON-text
    // string, e.g. Elementor's _elementor_data, vs. an already-native PHP
    // array from maybe_unserialize(), e.g. any ordinary WP option/meta) —
    // this class only ever sees the resulting array/scalar, the same split
    // of responsibility the rest of Tokens already has with Capture.

    /**
     * Capture direction: rewrite every position $jsonRefs declares (a list
     * of {path, kind, cast?}) from a raw id to a token, rewrite the map
     * $keyRefs declares (nullable {path?, kind}) from raw-id keys to
     * token keys, and tokenize every remaining string leaf NOT consumed by
     * either (catches incidental URLs the id-paths don't name — e.g.
     * Elementor's "url" sibling of "id" — without needing them declared;
     * see Blocks.php's block_attrs for the analogous "tokenize":"text"
     * idea, generalized here to "every leaf, by default").
     *
     * Unmapped ids: json_refs nulls the scalar (task #21 semantics — a raw
     * env-local id must never reach canonical state); key_refs drops the
     * WHOLE entry under that key (mission wording: "unmapped keys drop-
     * with-warning (whole entry)" — there is no safe partial value to keep
     * once its own identity doesn't resolve). Both warn.
     *
     * $value=0/''/null at a json_refs path is left untouched, never warned:
     * WordPress's/plugins' own "unset" convention for an id field (fresh
     * installs, unconfigured optional fields like Yoast's per-term
     * og-image) — same treatment OptionsCapture's option-ref path already
     * gives whole-option id 0.
     */
    public function struct_capture($value, array $jsonRefs, ?array $keyRefs) {
        $value = StructuredReferenceCodec::capture(
            $value,
            $jsonRefs,
            $keyRefs,
            fn(int $id, string $kind): ?string => $this->id_to_token($id, $kind),
            function (string $warning): void { $this->warnings[] = $warning; }
        );
        $this->tokenize_leaves($value, true);
        return $value;
    }

    /**
     * Apply direction mirror of struct_capture(): token -> id at each
     * json_refs path (restoring the declared numeric/string type),
     * token-keys -> id-keys for key_refs, detokenize every remaining
     * string leaf. A null left by a capture-time drop stays null (there
     * was never a valid id to restore — see struct_capture()'s docblock).
     */
    public function struct_apply($value, array $jsonRefs, ?array $keyRefs) {
        $this->tokenize_leaves($value, false);
        return StructuredReferenceCodec::apply(
            $value,
            $jsonRefs,
            $keyRefs,
            fn(string $token): int => $this->token_to_id($token)
        );
    }

    /** Recursively tokenize_text()/detokenize_text() every string leaf of
     *  an arbitrarily nested array/scalar — the whole-blob URL pass that
     *  makes a per-path "url" declaration unnecessary (see
     *  struct_capture()'s docblock). Never touches array KEYS. */
    private function tokenize_leaves(&$value, bool $capture): void {
        if (is_string($value)) {
            if ($this->policy !== null) {
                // Capture/Apply entry points normally load Shortcodes with the
                // rest of the agent, but several WordPress-free snapshot
                // paths load Tokens directly. Resolve the optional codec at
                // the first structured string leaf rather than making every
                // standalone Tokens consumer know the bootstrap order.
                if (!class_exists(Shortcodes::class, false)) {
                    require_once __DIR__ . '/Shortcodes.php';
                }
                $value = $capture
                    ? Shortcodes::capture_rewrite_text($value, $this->policy, $this, $this->forceUnresolvedRefs, '')
                    : Shortcodes::apply_rewrite_text($value, $this->policy, $this);
            }
            $value = $capture ? $this->tokenize_text($value) : $this->detokenize_text($value);
            return;
        }
        if (is_array($value)) {
            foreach ($value as &$v) {
                $this->tokenize_leaves($v, $capture);
            }
            unset($v);
        }
    }


    /**
     * Capture direction for a ref-typed value per manifest rule
     * ('post' | 'term' | 'post[]'). Unmapped ids stay numeric (warned).
     */
    public function value_to_tokens($value, string $ref) {
        if (str_ends_with($ref, '[]')) {
            $kind = substr($ref, 0, -2);
            $out = [];
            foreach ((array) $value as $v) {
                $out[] = $this->id_to_token((int) $v, $kind) ?? (int) $v;
            }
            return $out;
        }
        return $this->id_to_token((int) $value, $ref) ?? (int) $value;
    }

    /** Apply direction: restore ids (numeric) from a tokenized option/meta value. */
    public function tokens_to_value($value, string $ref) {
        if (str_ends_with($ref, '[]')) {
            $out = [];
            foreach ((array) $value as $v) {
                $out[] = is_string($v) && str_starts_with($v, '{{') ? $this->token_to_id($v) : (int) $v;
            }
            return $out;
        }
        return is_string($value) && str_starts_with($value, '{{') ? $this->token_to_id($value) : (int) $value;
    }
}
