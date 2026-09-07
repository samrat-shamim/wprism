<?php
namespace WPrism;

/**
 * Pure cross-source grammar for the ref, token, and ledger kind vocabularies.
 *
 * These three vocabularies share one extension path: a pinned or site policy
 * may declare an authored snapshot table and its id_kind. The validator owns
 * the shared id_kind set, its recursive claim walker, and the engine-owned
 * bases. It accepts decoded arrays rather than a Policy object so the grammar
 * remains independent of runtime resolution and orchestration.
 */
final class ReferenceKindGrammar {
    /** One pure width contract for declarations and wprism_map.id_kind. */
    public const LEDGER_KIND_WIDTH = 64;

    private const ENGINE_TOKEN_KINDS = ['post', 'term', 'tt'];
    /** @see ENGINE_TOKEN_KINDS */
    private const ENGINE_REF_KINDS = ['post', 'term', 'tt', 'user'];
    /** @see ENGINE_TOKEN_KINDS — wprism_map's own long spellings, not the token short ones. */
    private const ENGINE_LEDGER_KINDS = ['post', 'term', 'term_taxonomy'];

    /** @return list<string> Policy::closed_vocabularies()'s engine ref base. */
    public static function engineRefKinds(): array {
        return self::ENGINE_REF_KINDS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s engine token base. */
    public static function engineTokenKinds(): array {
        return self::ENGINE_TOKEN_KINDS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s engine ledger base. */
    public static function engineLedgerKinds(): array {
        return self::ENGINE_LEDGER_KINDS;
    }

    /**
     * The two remaining surfaces that name a wprism_map keyspace directly
     * (issue #3318 review, S6), closed the same way the ref/token vocabularies
     * above are.
     *
     * These are LEDGER kinds, a third vocabulary rather than a restatement of
     * the token one, because both reach Ledger::id_for() with the declared
     * string verbatim (Apply::count_guard_refs(), and the option-name-ref
     * resolution path) instead of going through Tokens. So the engine-owned
     * base here is the ledger's own LONG spellings — `term_taxonomy`, never
     * the `tt` a manifest writes in a token kind — and `user` is absent
     * because wprism_map has no user keyspace at all (a user reference is
     * serialized as a login, never an id).
     *
     * `option_name_refs[].id_kind` is narrower still: its own contract is
     * that the captured id names a row of a declared table (that is what
     * makes the id in an option NAME portable), so post/term/term_taxonomy
     * are not legal there — only an id_kind some pinned manifest declared for
     * a table it owns. Both were previously validated for SHAPE only
     * (`^[a-z][a-z0-9_]*$`), so a typo produced a keyspace with no rows:
     * a guard whose identity mapping is "absent" blocks every delete it
     * guards with a message about missing identity, and an option-name ref
     * that resolves to nothing drops the option from canonical state.
     *
     * @param list<array> $manifests
     * @param list<string> $declaredIdKinds every pinned/site-declared table's id_kind
     */
    private static function validate_ledger_kind_claims(array $manifests, array $declaredIdKinds): void {
        // ENGINE_LEDGER_KINDS repeats Ledger::KIND_POST/KIND_TERM/KIND_TT
        // rather than referencing them, for the same reason
        // ManifestGrammar::TABLE_CLASSES repeats Snapshot's two class names:
        // this file must stay loadable with no other engine class present, and
        // these spellings are wire format a manifest already carries, not an
        // internal name either side may change.
        $ledgerKinds = array_merge(self::ENGINE_LEDGER_KINDS, $declaredIdKinds);
        sort($ledgerKinds, SORT_STRING);
        sort($declaredIdKinds, SORT_STRING);
        foreach ($manifests as $manifest) {
            $label = "manifest '" . (string) ($manifest['name'] ?? '?') . "'";
            $claims = [];
            foreach ((array) ($manifest['deletions'] ?? []) as $selector => $decl) {
                foreach ((array) (is_array($decl) ? ($decl['guards'] ?? []) : []) as $i => $guard) {
                    foreach (['id_kind', 'source_id_kind'] as $key) {
                        if (is_array($guard) && array_key_exists($key, $guard)) {
                            $claims[] = ["deletions.$selector.guards[$i].$key", $guard[$key], $ledgerKinds];
                        }
                    }
                }
            }
            foreach ((array) ($manifest['option_name_refs'] ?? []) as $i => $rule) {
                if (is_array($rule) && array_key_exists('id_kind', $rule)) {
                    $claims[] = ["option_name_refs[$i].id_kind", $rule['id_kind'], $declaredIdKinds];
                }
            }
            foreach ($claims as [$path, $value, $legal]) {
                if (is_string($value) && in_array($value, $legal, true)) {
                    continue;
                }
                throw new \RuntimeException(
                    "wprism: $label declares $path=" . var_export($value, true) . ' but the ledger kind vocabulary '
                    . 'is closed here (' . ($legal === [] ? '<no table id_kind is declared by any pinned manifest>'
                        : implode(', ', $legal)) . '). This value is looked up in wprism_map verbatim, so an '
                    . 'unrecognized one names a keyspace with no rows rather than failing — '
                    . ($legal === $declaredIdKinds
                        ? 'an option-name reference resolves against a declared TABLE, so name the id_kind of a '
                          . 'table some pinned manifest declares'
                        : 'post/term/term_taxonomy are the engine\'s own (the ledger\'s LONG spellings, not the '
                          . '"tt" a token kind uses), and every other legal value is an id_kind a pinned manifest '
                          . 'declared for a table it owns')
                );
            }
        }
    }

    /**
     * The ref-kind vocabulary, closed across every pinned manifest and the
     * site's own policy (issue #3318).
     *
     * A ref kind is the engine's typed-identity keyspace name: three are the
     * engine's own (`post`, `term`, `tt` — Tokens::KIND_MAP's historical
     * short spellings), `user` is the login-serialized form a classification
     * rule may name, and every other legal value is an `id_kind` some pinned
     * manifest declared for a table it owns. That last clause is the whole
     * extension path, and it is deliberately the only one: an adapter widens
     * this vocabulary by declaring a TABLE it owns, never by naming a kind
     * out of thin air.
     *
     * Why closed at all: Tokens::id_to_token() passes an unrecognized kind
     * straight through as a ledger lookup key (KIND_MAP is a rename table,
     * not a gate — deliberately, so a declared id_kind needs no engine-side
     * registration). A typo therefore resolved to a keyspace with no rows,
     * returned null, and took the ordinary dangling-reference path: the value
     * was DROPPED from canonical state with a warning that named the id but
     * not the misspelled kind. `"ref": "psot"` silently deleted authored
     * references — the loudest possible symptom being a warning that looks
     * exactly like an ordinary unmapped id.
     *
     * Two vocabularies, not one, because they answer different questions. A
     * classification rule's `ref` may name `user` and may carry the `[]`
     * plural suffix. A TOKEN kind — a table's `refs[]`, a `block_attrs`/
     * `shortcode_attrs` rule, a `json_refs`/`key_refs` entry — is normally
     * resolved through wprism_map, which has no user keyspace, and carries its
     * plurality in a separate `type`/`cast` field rather than in the kind
     * name. `block_attrs` alone may name `user`: Blocks owns the explicit
     * user-id <-> user:<login> codec used by core/avatar, while none of the
     * other token-shaped channels implements that login form.
     *
     * The three ENGINE_* consts declared above are the engine-owned BASE of
     * each vocabulary — the part that is a fixed fact about this engine
     * rather than a function of which manifests happen to be pinned. Each
     * validator still unions its base with the declared id_kinds; splitting
     * the base out as a const is what lets closed_vocabularies() publish
     * "what the engine owns" without a second copy of these spellings
     * existing anywhere.
     *
     * @param list<array> $manifests
     * @param array<string,mixed> $sitePolicy site.wprism.json's `policy` object
     */
    public static function validate_ref_kinds(array $manifests, array $sitePolicy): void {
        $idKinds = [];
        foreach (array_merge($manifests, [['tables' => $sitePolicy['tables'] ?? []]]) as $source) {
            foreach ((array) ($source['tables'] ?? []) as $decl) {
                $kind = is_array($decl) ? ($decl['id_kind'] ?? null) : null;
                if (is_string($kind) && $kind !== '') {
                    $idKinds[$kind] = true;
                }
            }
        }
        $tokenKinds = array_merge(self::ENGINE_TOKEN_KINDS, array_keys($idKinds));
        $refKinds = array_merge(self::ENGINE_REF_KINDS, array_keys($idKinds));
        sort($tokenKinds, SORT_STRING);
        sort($refKinds, SORT_STRING);
        // The third vocabulary built on the same declared-id_kind set, called
        // from here rather than from load() so that set is computed once and
        // the three can never be derived from different inputs.
        self::validate_ledger_kind_claims($manifests, array_keys($idKinds));

        $sources = [];
        foreach ($manifests as $manifest) {
            $sources["manifest '" . (string) ($manifest['name'] ?? '?') . "'"] = $manifest;
        }
        $sources['site.wprism.json policy'] = $sitePolicy;
        foreach ($sources as $label => $source) {
            $claims = [];
            self::collect_ref_kind_claims($source, '', $claims);
            self::validate_attr_kind_claims($source, $claims);
            foreach ($claims as [$path, $value, $token, $blockUser]) {
                $legal = $token ? $tokenKinds : $refKinds;
                if ($blockUser) {
                    $legal[] = 'user';
                    sort($legal, SORT_STRING);
                }
                $bare = (!$token && is_string($value) && str_ends_with($value, '[]'))
                    ? substr($value, 0, -2)
                    : $value;
                if (is_string($bare) && in_array($bare, $legal, true)) {
                    continue;
                }
                throw new \RuntimeException(
                    "wprism: $label declares $path=" . var_export($value, true) . ' but the '
                    . ($token ? 'token' : 'reference') . ' kind vocabulary is closed ('
                    . implode(', ', $legal) . ($token ? '' : ', each optionally suffixed with [] for a list')
                    . '). post/term/tt' . (!$token || $blockUser ? '/user' : '')
                    . ' are engine-owned; every other kind is an '
                    . 'id_kind a pinned manifest declared for a table it owns, which is the only way to extend '
                    . 'this vocabulary — declare the table, then name its id_kind'
                );
            }
        }
    }

    /**
     * Every ref-kind claim reachable from a manifest's classification
     * sections, as [path, value, isTokenKind, blockUserCodec] tuples.
     *
     * Four top-level channels are skipped rather than walked: `notes` is
     * free-form human prose keyed by arbitrary strings, `actions`/`providers`
     * carry structured arguments whose key names belong to the declaring
     * plugin's own capability schema (a provider argument may legitimately be
     * called `ref` and mean something entirely unrelated), and
     * `lifecycle_effects` is the reversibility grammar, whose `kind` is a
     * category rather than a keyspace. Everywhere else in a manifest, `ref`
     * has exactly one meaning, which is what makes a blind walk correct rather
     * than fragile — and why a bare `kind` is NOT walked blindly, but read at
     * its four exact declared locations instead.
     *
     * Unbounded recursion by construction, deliberately: the input is an
     * already-decoded manifest, so its depth is bounded by json_decode()'s own
     * 512-level default (Canon::decode() takes it) long before PHP's stack is,
     * and the manifests directory is operator-controlled — the same trust
     * decision as running the agent at all (see manifests_dir()). Every other
     * structural walker in this engine — Canon::normalize(), JsonRefs::walk(),
     * SidebarState::rewrite_strings() — recurses on the same terms; adding a
     * depth counter to this one alone would claim a threat model the rest of
     * the engine does not share.
     *
     * @param list<array{0:string,1:mixed,2:bool,3:bool}> $out
     */
    private static function collect_ref_kind_claims(mixed $node, string $path, array &$out): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path . '.' . $key;
            if ($path === '' && in_array($key, ['notes', 'actions', 'providers', 'lifecycle_effects'], true)) {
                continue;
            }
            if ($key === 'ref') {
                $out[] = [$childPath, $value, false, false];
                continue;
            }
            if ($key === 'refs' && is_array($value) && array_is_list($value)) {
                foreach ($value as $i => $entry) {
                    if (is_array($entry) && array_key_exists('kind', $entry)) {
                        $out[] = [$childPath . "[$i].kind", $entry['kind'], true, false];
                    }
                }
                continue;
            }
            if ($key === 'json_refs' && is_array($value)) {
                foreach ($value as $i => $entry) {
                    if (is_array($entry) && array_key_exists('kind', $entry)) {
                        $out[] = [$childPath . "[$i].kind", $entry['kind'], true, false];
                    }
                }
                continue;
            }
            if ($key === 'key_refs' && is_array($value) && array_key_exists('kind', $value)) {
                $out[] = ["$childPath.kind", $value['kind'], true, false];
                continue;
            }
            self::collect_ref_kind_claims($value, $childPath, $out);
        }
    }

    /**
     * The two attribute registries' own kind claims, added here rather than
     * in the blind walk because a bare `kind` key means different things in
     * different channels (an effect's `kind` is a reversibility category, not
     * a keyspace) — so these two are read by their exact declared location.
     *
     * @param list<array{0:string,1:mixed,2:bool,3:bool}> $out
     */
    private static function validate_attr_kind_claims(array $source, array &$out): void {
        foreach (['block_attrs', 'shortcode_attrs'] as $section) {
            foreach ((array) ($source[$section] ?? []) as $subject => $rules) {
                foreach ((array) $rules as $i => $rule) {
                    if (!is_array($rule)) {
                        continue;
                    }
                    $at = $section . '.' . $subject . '[' . $i . ']';
                    if (array_key_exists('kind', $rule)) {
                        $out[] = ["$at.kind", $rule['kind'], true, $section === 'block_attrs'];
                    }
                    $from = $rule['kind_from'] ?? null;
                    if (!is_array($from)) {
                        continue;
                    }
                    foreach ((array) ($from['map'] ?? []) as $attrValue => $kind) {
                        $out[] = [
                            "$at.kind_from.map.$attrValue",
                            $kind,
                            true,
                            $section === 'block_attrs',
                        ];
                    }
                    if (array_key_exists('default', $from)) {
                        $out[] = [
                            "$at.kind_from.default",
                            $from['default'],
                            true,
                            $section === 'block_attrs',
                        ];
                    }
                }
            }
        }
    }
}
