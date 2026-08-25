<?php
declare(strict_types=1);

namespace Duo;

/**
 * The namespace grammar for the three flat identity spaces, and the CLOSED
 * grandfather list that keeps the unprefixed half of them honest
 * (spec/repo-format.md § v3.9, WP-4.10).
 *
 * WHAT IS FLAT TODAY, AND WHY THAT IS A PROBLEM WORTH A RULE
 * ----------------------------------------------------------
 * Three spaces have exactly one grammar between them and no namespace at all:
 * adapter names, `tables.<t>.id_kind`, and `providers[].id`. The grammar is
 * `AdapterSources::assert_name()` — a lowercase ASCII slug — and it decides
 * shape, never ownership. Two independently-authored adapters that both pick
 * `cache` are two adapters answering to one name, and `id_kind` collision is
 * worse than ambiguous: `duo_map` is keyed by `(id_kind, local_id)`
 * (`agent/src/Policy/CrossManifestGuards.php:429-453`), so two tables sharing a
 * kind resolve each other's rows the moment both hold the same local id. The
 * entire remediation for that today is prose advice inside the refusal — "pick
 * a distinct one, typically a short prefix of the owning plugin".
 *
 * At `spec_version` 3 the reserved form is `<vendor>-<name>`, and it is BOUND
 * to the certifying authority's namespace scope rather than to registration
 * order: WP-4.8 already ships that half, so an authority whose
 * `adapter_names` carries `acme-*` certifies `acme-cache` and refuses
 * `zeta-foo` (`AdapterCertification::assertScopeEntry()` /
 * `scopeCoversName()`). Squatting therefore requires HOLDING A KEY, which is
 * the same property fingerprint-derived key ids give (§ v3.7); this file adds
 * the other end of that binding — the requirement that an out-of-tree name be
 * inside a vendor namespace at all, so there is something for a scope to bind.
 *
 * WHY THE LIST ENUMERATES INSTEAD OF TESTING SHAPE
 * ------------------------------------------------
 * Measured against the shipped library by
 * `sandbox/tests/offline/policy/regress_spec_v3_dry_run.php` (rule V3-NS):
 * 16 adapter names + 18 `id_kind`s + 10 provider ids = 44 identities, every one
 * of which already passes the one shared grammar. A bare `<vendor>-<name>`
 * SHAPE test would refuse 24 of them — the 6 names carrying no hyphen at all
 * (`acf`, `core`, `elementor`, `polylang`, `woocommerce`, `yoast`) and all 18
 * `id_kind`s, every one of which is underscore-separated. Worse in the other
 * direction: the remaining 10 names ARE hyphen-shaped without being
 * vendor-prefixed — `the-events-calendar` is not vendor `the` — so a shape test
 * admits exactly the rows a reviewer would want to look at. Shape cannot
 * separate the shipped library from a stranger's adapter, so the list below
 * ENUMERATES it, name for name and kind for kind.
 *
 * WHY THE LIST LIVES HERE AND NOT UNDER `manifests/`
 * --------------------------------------------------
 * AGENTS.md rule 2: `ArtifactPolicyIdentity::manifest_rows()` folds every
 * manifest's JSON bytes into that adapter's `digest`, which every
 * `site.duo.json` content pin and every certificate's
 * `adapter.canonical_sha256` binds. A reserved-name list under `manifests/`
 * would therefore be an identity input on every adapter row: adding the 17th
 * shipped adapter would move the digest of the other 16 and invalidate every
 * pin and certificate in the fleet. Here it is ordinary agent code that moves
 * no digest at all. `php tools/wire-surface.php --check` — a `make
 * release-gate` step — asserts BOTH halves of that: the list's defining file is
 * under `agent/src/`, and its membership equals the shipped library exactly, so
 * a seventeenth unprefixed name cannot be added without editing a reviewed list
 * (register row R-27).
 *
 * WHY `id_kind` PREFIXING IS A CONVENTION AND NEVER A RULE
 * --------------------------------------------------------
 * The irreversibility register rules on it at R-17: captured state and
 * `duo_map` rows embed the BARE kind, so a prefix rule introduced later would
 * have to rewrite every token in every branch of every site — the one migration
 * this product cannot perform, because the branches are the customer's data.
 * So `GRANDFATHERED_ID_KINDS` is a permanent FLOOR that release-gate keeps
 * honest, not a break list, and nothing in this file refuses an unprefixed
 * `id_kind`. The uniqueness refusal in `CrossManifestGuards` is unchanged and
 * remains the only thing with teeth in that space.
 */
final class IdentityNamespaces {
    /**
     * The 16 shipped adapter names, enumerated because shape cannot recognise
     * them (see the header). `duo-agency-cpt` is on the list for the same
     * reason as the other 15 — it is a name the shipped library declares — and
     * its `excluded` disposition is a claim about capability, not about
     * identity.
     *
     * Sorted and unique; `tools/wire-surface.php` refuses the release if this
     * is not exactly `basename()` over `manifests/*.json`.
     *
     * @var list<string>
     */
    public const GRANDFATHERED_ADAPTER_NAMES = [
        'acf',
        'advanced-editor-tools',
        'classic-editor',
        'code-snippets',
        'contact-form-7',
        'core',
        'duo-agency-cpt',
        'elementor',
        'ninja-forms',
        'paid-memberships-pro',
        'polylang',
        'the-events-calendar',
        'woocommerce',
        'wps-hide-login',
        'yoast',
        'yoast-duplicate-post',
    ];

    /**
     * The 18 shipped `tables.<t>.id_kind` values — the permanent floor R-17
     * describes, recorded so that a nineteenth shipped kind is a reviewed edit
     * here rather than a value that appeared in a manifest. Every one is
     * underscore-separated, which is precisely why the hyphen form can never
     * be imposed on this space retroactively.
     *
     * @var list<string>
     */
    public const GRANDFATHERED_ID_KINDS = [
        'attr_taxonomy',
        'code_snippet',
        'nf3_action',
        'nf3_field',
        'nf3_form',
        'pmpro_category_restrict',
        'pmpro_discount',
        'pmpro_discount_level',
        'pmpro_group',
        'pmpro_level',
        'pmpro_level_group',
        'pmpro_restrict',
        'wc_tax_class',
        'wc_tax_loc',
        'wc_tax_rate',
        'wc_zone',
        'wc_zone_loc',
        'wc_zone_method',
    ];

    /** The first `spec_version` at which the reserved form is a RULE. */
    public const NAMESPACED_SINCE = 3;

    /**
     * The vendor half of `<vendor>-<name>`, or null when the identity is not in
     * that form at all.
     *
     * The vendor half is `[a-z0-9]+` and the separator is the FIRST hyphen, so
     * `acme-cache` is vendor `acme` and `the-events-calendar` is vendor `the`.
     * That second answer is the whole reason the grandfather list exists: this
     * function reports SHAPE and shape is not ownership, so every caller below
     * consults the list first.
     *
     * ONE HYPHEN DEEP, AND THAT IS THE LIMIT OF WHAT THE BINDING BINDS (G2-FIXES
     * M3). A sub-vendor delegated `acme-forms-*` may name its adapter
     * `acme-forms-widget`, whose vendor half is `acme` — so the provider rule
     * below admits every `acme-<id>` it declares, across the PARENT's whole
     * space and not merely inside the scope its own certificate was checked
     * against. The rule therefore binds a provider id to the FIRST SEGMENT of
     * the declaring adapter's name, which is the top of the namespace its scope
     * lies within; it does not bind it to the narrowest scope entry that
     * certified the adapter. Stated here, in § v3.9 and in register row R-27
     * rather than closed, because closing it means carrying the certificate's
     * matched scope entry into a loader that runs with no certificate in hand
     * (`assert_out_of_tree_contract()` judges identity for every out-of-tree
     * manifest, certified or not) — a plumbing change with its own permanent
     * wire consequences, which is a decision and not a fix.
     */
    public static function vendor(string $identity): ?string {
        return preg_match('/^([a-z0-9]+)-(.+)$/D', $identity, $m) === 1 ? $m[1] : null;
    }

    /** True when this exact name is one the shipped library already answers to. */
    public static function is_grandfathered_name(string $name): bool {
        return in_array($name, self::GRANDFATHERED_ADAPTER_NAMES, true);
    }

    /** True when this exact `id_kind` is one the shipped library already declares. */
    public static function is_grandfathered_id_kind(string $kind): bool {
        return in_array($kind, self::GRANDFATHERED_ID_KINDS, true);
    }

    /**
     * The § v3.9 rule, applied at the one out-of-tree boundary every source
     * passes through (`AdapterSources::assert_out_of_tree_contract()`).
     *
     * INERT BELOW `spec_version` 3, and that is the flag-day invariant rather
     * than an oversight: `DUO_SPEC_VERSION` is 2 and stays 2 (the flip is
     * WP-4.12), so no manifest in the field declares 3 and this function
     * returns before reading a single member for every one of them. The gate is
     * the same one § v3.5's environment narrowing uses
     * (`ManifestDispositions::narrowed_environment()`), deliberately: a v3-only
     * rule that fired at v2 would be refusing a manifest the acceptance window
     * (§ v3.1) has not even judged yet.
     *
     * Two refusals, in the order a reader meets them:
     *
     *   1. the NAME. A grandfathered name is exempt from THIS refusal — out of
     *      tree that is reachable only as a reviewed `{name, source: "site"}`
     *      override of a shipped adapter (T6 §3.3), which is exactly the case
     *      the closed list must not break. Everything else must be
     *      `<vendor>-<name>`;
     *   2. the PROVIDER IDS, which must sit in the adapter's OWN vendor
     *      namespace. Without that half the binding is decorative: an adapter
     *      certified under an authority scoped `acme-*` could still mint
     *      provider id `zeta-thing` and squat a space no key of its holder's
     *      covers. Binding provider ids to the declaring adapter's vendor binds
     *      the whole identity set the adapter contributes to the VENDOR half of
     *      the name its certificate was checked against — one hyphen deep, and
     *      no deeper; `vendor()` states what that does and does not reach.
     *
     * THE GRANDFATHER EXEMPTION IS THE NAME'S ALONE (G2-FIXES M2). Until then
     * a grandfathered name returned BEFORE the provider loop, so the 10 shipped
     * names that do have a vendor half — `ninja-forms`, `yoast-duplicate-post`,
     * `code-snippets` and the rest — could be answered out of tree by a manifest
     * declaring provider ids in ANY vendor's namespace: the one door left open
     * in the binding this section exists to make transitive. The exemption now
     * covers exactly what it was argued for, the name, and the provider loop
     * runs for every out-of-tree manifest that HAS a vendor half to judge
     * against.
     *
     * A name with NO vendor half skips the loop, and that is a statement of
     * fact rather than a concession: `core`, `acf`, `woocommerce`, `elementor`,
     * `polylang` and `yoast` have no `<vendor>-` to bind a provider id to, so
     * there is no rule to apply — and their shipped provider ids (`core-*`,
     * `elementor-css-*`, `woocommerce-*`) are prefixed with the adapter NAME,
     * which this grammar cannot see as a vendor. `regress_identity_namespaces.php`
     * measures both arms against the shipped library on every run, so a
     * seventeenth adapter whose providers sit outside its own vendor namespace
     * is a reviewed edit rather than a discovery.
     *
     * `id_kind` is deliberately absent from both: R-17 rules the RULE out
     * permanently, and this function may not overrule the register.
     *
     * @param array<string,mixed> $manifest the decoded out-of-tree manifest
     * @param string $name its resolved adapter name
     * @param string $label the noun the message uses ('site adapter', …)
     * @param string $shown the path as the caller renders it, quoted already
     */
    public static function assert_out_of_tree_identity(
        array $manifest,
        string $name,
        string $label,
        string $shown
    ): void {
        $spec = $manifest['spec_version'] ?? null;
        if (!is_int($spec) || $spec < self::NAMESPACED_SINCE) {
            return;
        }
        $vendor = self::vendor($name);
        if ($vendor === null && !self::is_grandfathered_name($name)) {
            throw new \RuntimeException(
                "duo: $label $shown declares spec_version $spec and the unprefixed name '$name' — at "
                . 'spec_version ' . self::NAMESPACED_SINCE . ' an out-of-tree adapter name is '
                . "'<vendor>-<name>', and the unprefixed space is the shipped library's closed reserved list of "
                . count(self::GRANDFATHERED_ADAPTER_NAMES) . ' names (agent/src/Adapter/IdentityNamespaces.php, '
                . 'spec/repo-format.md § v3.9). Rename it under a vendor prefix your certifying authority is '
                . "scoped to — an authority whose adapter_names carries '<vendor>-*' certifies every name in "
                . 'that namespace and no name outside it'
            );
        }
        if ($vendor === null) {
            // A grandfathered name with no vendor half: there is no namespace
            // to bind provider ids to, so this rule has nothing to say about
            // them (see the header). Every other name reached here HAS a vendor
            // half — the refusal above is the only other way out.
            return;
        }
        foreach ((array) ($manifest['providers'] ?? []) as $i => $declaration) {
            if (!is_array($declaration)) {
                continue;
            }
            $id = $declaration['id'] ?? null;
            if (!is_string($id)) {
                // Shape and presence are the manifest grammar's refusal, and a
                // second opinion here would be a second copy of that rule.
                continue;
            }
            if (self::vendor($id) === $vendor) {
                continue;
            }
            throw new \RuntimeException(
                "duo: $label $shown providers[$i].id '$id' is outside the '$vendor-' namespace of adapter "
                . "'$name' — at spec_version " . self::NAMESPACED_SINCE . ' a provider id is namespaced by '
                . 'the adapter that declares it, so every identity one adapter contributes is bound to the one '
                . 'authority scope its name was certified against (spec/repo-format.md § v3.9). Rename it '
                . "'$vendor-<id>'"
            );
        }
    }
}
