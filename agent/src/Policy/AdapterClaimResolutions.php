<?php
declare(strict_types=1);

namespace WPrism;

/**
 * `site.wprism.json` `policy.adapter_claims` — the operator's explicit resolution
 * of a plugin or theme claim that two pinned manifests both make (WP-5.5,
 * spec/repo-format.md § v3.13).
 *
 * WHY THIS IS A SITE-POLICY SECTION AND NOT A MANIFEST ONE. The collision is
 * a property of a PIN SET, not of either manifest — each is individually
 * legal, and neither may be granted authority to displace the other by
 * declaring something about it (the "extension must not grant one adapter
 * authority over another adapter's state" ruling that
 * CrossManifestGuards.php:22-24 states for five other surfaces). The only
 * party who can decide is the one who pinned both, and the file they already
 * own is `site.wprism.json`. That is exactly the precedent
 * `validate_no_conflicting_option_rules()` set for two manifests contradicting
 * each other about one option name: "a site policy rule for the colliding name
 * is the explicit resolution path" (CrossManifestGuards.php:214-217), and its
 * own refusal already ends "Add an explicit site.wprism.json policy.options.<name>
 * override to resolve this option" (`:265`).
 *
 * RESOLUTION, NEVER COMPOSITION. Exactly one manifest's claim is in force for
 * one plugin or theme; every other claimant's is DISPLACED and REPORTED
 * (`Policy::displaced_adapter_claims()`, rendered as a plan warning by
 * `ApplyPlanBuilder`). No range is merged, intersected or unioned, and no
 * surface of the displaced manifest is touched — a displaced manifest is still
 * pinned, still loaded, and still owns everything else it declares. The
 * refused thing was never "two adapters about one plugin"; it was pin ORDER
 * choosing which range bounds it (`AdapterContractGrammar::
 * validate_no_conflicting_adapter_claims()`), and naming the winner is what
 * removes the ordering, not what merges the claims.
 *
 * WITHOUT A RESOLUTION THE REFUSAL STANDS, BYTE FOR BYTE. A repository that
 * declares no `adapter_claims` reaches the identical exception with the
 * identical message it always did — this section admits nothing by existing,
 * which is why every refusal below is about a resolution an operator actually
 * wrote down. `regress_plugin_claim_resolution.php` asserts the unresolved
 * message against a literal rather than against a regenerated one.
 *
 * A RESOLUTION THAT RESOLVES NOTHING REFUSES. `assert_binds()` demands that
 * each declared row name a `<kind>`/`<id>` at least two pinned manifests
 * actually claim, and that `in_force` name one of those claimants. The posture
 * is `PinResolver`'s: "a pin whose author believed it constrained something is
 * the failure this whole record exists to prevent" (PinResolver.php:57-59) —
 * a resolution left behind after one of its manifests was unpinned must say so
 * rather than sit there looking like a decision that is still being made.
 *
 * This file is in the Policy module and references no Adapter class on
 * purpose: `AdapterContractGrammar` (layer `adapter`) reads it downward, which
 * is an ordinary reference, while the reverse would add a tenth
 * `src/Policy/... -> src/Adapter/...` row to `tools/layers-exceptions.json`'s
 * upward ratchet.
 */
final class AdapterClaimResolutions {
    /**
     * The two resolvable claim arms: the manifest key that NAMES the subject,
     * and the manifest key that BOUNDS it.
     *
     * One definition, read by both the guard that refuses an unresolved
     * collision (`AdapterContractGrammar::validate_no_conflicting_adapter_
     * claims()`) and the resolution that answers it, because a resolution
     * grammar that knew a third arm the guard did not — or the reverse —
     * would be a section an operator can declare and nothing enforces.
     *
     * @var array<string,string> claim kind => the manifest range key that bounds it
     */
    public const CLAIM_ARMS = [
        'plugin' => 'version_range',
        'theme' => 'theme_version_range',
    ];

    /** The keys one resolution row admits. `note` is non-semantic, exactly as an option rule's is. */
    private const ROW_KEYS = ['in_force', 'note'];

    /**
     * The `not_installed` vocabulary's word family, one step over: the shipped
     * catalog reports a shipped adapter an explicit `{name, source:"site"}`
     * pin displaced as `shadowed_by_site` with its winner named
     * (`AdapterSources::CERTIFICATION_SHADOWED_BY_SITE`), "so the operator can
     * see WHICH definition is in force rather than inferring it from a
     * silence". This is the same sentence about a CLAIM instead of about an
     * adapter — which is why it is a distinct code rather than a reuse: a
     * displaced claimant is still installed and still loaded, so reporting it
     * as not installed would be false.
     */
    public const DISPLACED_BY_RESOLUTION = 'displaced_by_resolution';

    /**
     * Validate the section's SHAPE, with no manifest in hand.
     *
     * Called from `SitePolicyValidator::validate()`, so a malformed resolution
     * refuses at the same point every other site-policy grammar error does —
     * before a pin is resolved and long before a target is contacted. The
     * separation from `assert_binds()` below is the same one the site policy
     * already draws everywhere: shape is a fact about the file, binding is a
     * fact about the file AND the pin set.
     *
     * @param array<string,mixed> $sitePolicy the decoded `policy` object
     */
    public static function validate(array $sitePolicy, string $label): void {
        if (!array_key_exists('adapter_claims', $sitePolicy)) {
            return;
        }
        $declared = $sitePolicy['adapter_claims'];
        if (!is_array($declared) || (array_is_list($declared) && $declared !== [])) {
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims must be a JSON object keyed by claim kind ("
                . implode(', ', array_map(static fn(string $k): string => '"' . $k . '"', array_keys(self::CLAIM_ARMS)))
                . ')'
            );
        }
        foreach ($declared as $kind => $arm) {
            $kind = (string) $kind;
            if (!array_key_exists($kind, self::CLAIM_ARMS)) {
                throw new \RuntimeException(
                    "wprism: $label policy.adapter_claims declares claim kind '$kind' — the claims a manifest can "
                    . 'make about installed code, and therefore the only ones a resolution can be about, are '
                    . implode(' and ', array_map(
                        static fn(string $k): string => '"' . $k . '"',
                        array_keys(self::CLAIM_ARMS)
                    ))
                );
            }
            if (!is_array($arm) || (array_is_list($arm) && $arm !== [])) {
                throw new \RuntimeException(
                    "wprism: $label policy.adapter_claims.$kind must be a JSON object keyed by the claimed $kind"
                );
            }
            foreach ($arm as $id => $row) {
                self::validate_row($label, $kind, (string) $id, $row);
            }
        }
    }

    /** @param mixed $row */
    private static function validate_row(string $label, string $kind, string $id, $row): void {
        if ($id === '') {
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims.$kind declares an empty $kind identity — a resolution names "
                . "the claimed $kind exactly as the manifests claiming it name it"
            );
        }
        if (!is_array($row) || array_is_list($row)) {
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims.$kind." . $id . ' must be an object with a non-empty string '
                . 'in_force naming the manifest whose claim is in force, and an optional note'
            );
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($row)), self::ROW_KEYS));
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims.$kind.$id declares unknown key(s) "
                . implode(', ', $unknown) . ' — a claim resolution accepts exactly '
                . implode(' and ', self::ROW_KEYS) . '. It names which claim is IN FORCE; it never states a '
                . 'range of its own, because a resolution that could restate a range would be a second, '
                . 'uncertified place a version bound is authored'
            );
        }
        if (!isset($row['in_force']) || !is_string($row['in_force']) || $row['in_force'] === '') {
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims.$kind.$id must declare a non-empty string in_force naming "
                . "the manifest whose $kind claim is in force"
            );
        }
        if (array_key_exists('note', $row) && !is_string($row['note'])) {
            throw new \RuntimeException(
                "wprism: $label policy.adapter_claims.$kind.$id declares a non-string note"
            );
        }
    }

    /**
     * The declared resolutions, normalized, after `validate()` has passed.
     *
     * @param array<string,mixed> $sitePolicy
     * @return array<string,array<string,array{in_force:string,note:?string}>> kind => id => row
     */
    public static function declared(array $sitePolicy): array {
        $out = [];
        foreach ((array) ($sitePolicy['adapter_claims'] ?? []) as $kind => $arm) {
            $kind = (string) $kind;
            if (!array_key_exists($kind, self::CLAIM_ARMS) || !is_array($arm)) {
                continue;
            }
            foreach ($arm as $id => $row) {
                if (!is_array($row) || !is_string($row['in_force'] ?? null)) {
                    continue;
                }
                $out[$kind][(string) $id] = [
                    'in_force' => (string) $row['in_force'],
                    'note' => isset($row['note']) ? (string) $row['note'] : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Which manifest is in force for each resolved claim — the one thing
     * `Policy::version_ranges()` and `Policy::theme_ranges()` need in order to
     * stop resolving by pin order.
     *
     * @param array<string,mixed> $sitePolicy
     * @return array<string,string> claimed id => manifest name
     */
    public static function in_force(array $sitePolicy, string $kind): array {
        $out = [];
        foreach (self::declared($sitePolicy)[$kind] ?? [] as $id => $row) {
            $out[$id] = $row['in_force'];
        }
        return $out;
    }

    /**
     * Every manifest that claims each `<kind>` id, in pin order.
     *
     * @param list<array<string,mixed>> $manifests
     * @return array<string,array<string,list<string>>> kind => id => manifest names
     */
    public static function claimants(array $manifests): array {
        $out = [];
        foreach (self::CLAIM_ARMS as $kind => $_rangeKey) {
            $out[$kind] = [];
            foreach ($manifests as $manifest) {
                $id = $manifest[$kind] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $out[$kind][$id][] = (string) ($manifest['name'] ?? '?');
            }
        }
        return $out;
    }

    /**
     * Refuse a resolution that decides nothing, or that names a manifest which
     * makes no such claim.
     *
     * Both are the operator having written down a constraint that does not
     * constrain what they believed it did, which is the failure the whole pin
     * record exists to prevent — and both are much more likely to arise by
     * DRIFT than by typo: unpin one of two colliding manifests and the
     * resolution left behind is a decision about a collision that no longer
     * exists. Refusing is what stops it silently going on looking like the
     * reason the surviving adapter is the one in force.
     *
     * Runs BEFORE the collision guard, so an operator holding a stale
     * resolution is told about the resolution rather than about a conflict
     * their file already tried to answer. Nothing moves for a repository that
     * declares no resolutions: this method returns without a comparison.
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,mixed> $sitePolicy
     */
    public static function assert_binds(array $manifests, array $sitePolicy, string $label): void {
        $declared = self::declared($sitePolicy);
        if ($declared === []) {
            return;
        }
        $claimants = self::claimants($manifests);
        foreach ($declared as $kind => $arm) {
            foreach ($arm as $id => $row) {
                $names = $claimants[$kind][$id] ?? [];
                if (count($names) < 2) {
                    throw new \RuntimeException(
                        "wprism: $label policy.adapter_claims.$kind.$id resolves nothing — " . count($names)
                        . " pinned manifest(s) claim $kind '$id', and a resolution decides which of SEVERAL "
                        . 'claims is in force. Remedy: remove the resolution, or pin the manifests it was '
                        . 'written for'
                    );
                }
                if (!in_array($row['in_force'], $names, true)) {
                    throw new \RuntimeException(
                        "wprism: $label policy.adapter_claims.$kind.$id puts manifest '{$row['in_force']}' in "
                        . "force, but the pinned manifests claiming $kind '$id' are "
                        . implode(', ', array_map(static fn(string $n): string => "'" . $n . "'", $names))
                        . ' — a resolution may only choose among the claims that were made, never install one'
                    );
                }
            }
        }
    }

    /**
     * Is this `<kind>`/`<id>` collision answered by an explicit resolution?
     *
     * Read by the collision guard, which is the only caller that needs the
     * question rather than the answer. `assert_binds()` has already proved the
     * named manifest is one of the claimants by the time this returns true,
     * because the finalizer runs it first.
     *
     * @param array<string,array<string,array{in_force:string,note:?string}>> $declared
     */
    public static function resolves(array $declared, string $kind, string $id): bool {
        return isset($declared[$kind][$id]);
    }

    /**
     * One row per DISPLACED claim — the reporting half, and the reason this is
     * a resolution rather than a silent override.
     *
     * Every claimant that is not the one in force appears here with the range
     * it declared and the range that actually bounds the subject, so the
     * operator reads the decision they made rather than inferring it from the
     * absence of a refusal. A claim two manifests make IDENTICALLY is still
     * reported when a resolution names one of them: redundancy is why the
     * pair never refused, not evidence that the displacement is uninteresting
     * — the displaced manifest's name is what stops appearing in
     * `version_ranges()`, and a reader comparing that map against the pin list
     * has to be able to find out why.
     *
     * @param list<array<string,mixed>> $manifests
     * @param array<string,mixed> $sitePolicy
     * @return list<array{kind:string,id:string,reason_code:string,in_force:string,in_force_range:?array<string,string>,displaced:string,displaced_range:?array<string,string>,note:?string}>
     */
    public static function displaced(array $manifests, array $sitePolicy): array {
        $declared = self::declared($sitePolicy);
        if ($declared === []) {
            return [];
        }
        $out = [];
        foreach (self::CLAIM_ARMS as $kind => $rangeKey) {
            foreach ($declared[$kind] ?? [] as $id => $row) {
                $ranges = [];
                foreach ($manifests as $manifest) {
                    if (($manifest[$kind] ?? null) !== $id) {
                        continue;
                    }
                    $name = (string) ($manifest['name'] ?? '?');
                    $ranges[$name] = self::range_of($manifest, $rangeKey);
                }
                if (!array_key_exists($row['in_force'], $ranges)) {
                    continue; // assert_binds() refuses this; displaced() is a reporter and never a second refusal.
                }
                foreach ($ranges as $name => $range) {
                    if ($name === $row['in_force']) {
                        continue;
                    }
                    $out[] = [
                        'kind' => $kind,
                        'id' => (string) $id,
                        'reason_code' => self::DISPLACED_BY_RESOLUTION,
                        'in_force' => $row['in_force'],
                        'in_force_range' => $ranges[$row['in_force']],
                        'displaced' => $name,
                        'displaced_range' => $range,
                        'note' => $row['note'],
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return ?array<string,string>
     */
    private static function range_of(array $manifest, string $rangeKey): ?array {
        $range = $manifest[$rangeKey] ?? null;
        if (!is_array($range) || !isset($range['min'], $range['max'])) {
            return null;
        }
        return ['min' => (string) $range['min'], 'max' => (string) $range['max']];
    }
}
