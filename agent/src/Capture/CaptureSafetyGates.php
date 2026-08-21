<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/PersonalData.php';
require_once __DIR__ . '/../Kernel/Secrets.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/**
 * Owns capture's reviewed-state, reference-scope, secret, and PII refusals.
 *
 * Capturers collect evidence while walking one coherent database snapshot.
 * This boundary turns that evidence into the stable public refusal payloads
 * and operator messages shared by full and narrow capture paths.
 */
final class CaptureSafetyGates {
    public function __construct(private string $repo) {
        $this->repo = rtrim($repo, '/');
    }

    public function assertOptions(
        array $unclassified,
        array $unscopedRefs,
        array $unscopedOptionNameRefs,
        Tokens $tokens
    ): void {
        if ($unclassified) {
            $keys = array_unique($unclassified);
            sort($keys);
            $operatorMessage = "duo: incomplete state discovery on manifest-owned or in-scope surfaces (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $keys)
                . "\nClassify them in site.duo.json policy.options / policy.post_meta / policy.term_meta or a manifest."
                . " Run: wp duo pending --repo={$this->repo} for evidence + proposals, then wp duo classify --repo={$this->repo} --set='<section>:<key>=<class>'.";
            $diagnostics = array_map(static fn(string $surface): array => [
                'code' => 'unclassified_state',
                'surface' => $surface,
                'message' => 'state surface has no reviewed classification',
                'remediation' => 'review it with duo pending, then classify or exclude it explicitly',
            ], $keys);
            throw new CommandRefusalException(
                'incomplete_state_discovery',
                'capture found state that has no reviewed classification',
                'review the diagnostics with duo pending, then classify or exclude every named surface before another capture',
                $diagnostics,
                $operatorMessage
            );
        }
        if ($unscopedRefs) {
            $lines = [];
            $diagnostics = [];
            foreach ($unscopedRefs as $reference) {
                $scopeKey = $reference['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "option '{$reference['option']}' references {$reference['kind']} id {$reference['id']}, which is a real "
                    . "'{$reference['target_type']}' — but '{$reference['target_type']}' is not in $scopeKey, so its identity was "
                    . 'never tracked and the reference cannot resolve';
                $diagnostics[] = [
                    'code' => 'unresolved_option_reference_scope',
                    'surface' => 'options:' . $reference['option'],
                    'reference_kind' => $reference['kind'],
                    'target_type' => $reference['target_type'],
                    'message' => 'a real referenced entity is outside reviewed policy scope',
                    'remediation' => "add the target type to $scopeKey, reclassify the option, or explicitly use --force-unresolved-refs to drop the reference",
                ];
            }
            $operatorMessage = "duo: unresolvable ref-typed option(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a scope gap, not permanent data loss.\n"
                . 'Add the missing post type/taxonomy to policy scope above and re-run capture, or reclassify the '
                . 'option, or pass --force-unresolved-refs to drop it anyway (same as a dangling reference).';
            throw new CommandRefusalException(
                'unresolved_reference_scope',
                'capture found reference-bearing option state outside reviewed scope',
                'follow each diagnostic to expand policy scope or deliberately drop the unresolved reference',
                $diagnostics,
                $operatorMessage
            );
        }
        if ($unscopedOptionNameRefs) {
            $lines = [];
            $diagnostics = [];
            foreach ($unscopedOptionNameRefs as $reference) {
                $lines[] = "option '{$reference['option']}' embeds {$reference['id_kind']} id {$reference['id']}, which is a real row in "
                    . "its declared table — but that table's rows were never minted a uuid (not pinned as "
                    . 'authored_snapshot in a currently-loaded manifest?), so the reference cannot resolve';
                $diagnostics[] = [
                    'code' => 'unminted_table_reference_scope',
                    'surface' => 'option_name_refs:' . $reference['option'],
                    'reference_kind' => $reference['id_kind'],
                    'message' => 'a real referenced table row has no manifest-owned portable identity',
                    'remediation' => 'pin the owning table as authored_snapshot or explicitly use --force-unresolved-refs to drop the reference',
                ];
            }
            $operatorMessage = "duo: option_name_refs option(s) point at real, unminted table rows (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (no such row anywhere — dropped with a warning, "
                . 'unchanged): the row genuinely exists right now, so this is a manifest/table-pinning gap, not '
                . "permanent data loss.\nPin the owning table as authored_snapshot and re-run capture, or pass "
                . '--force-unresolved-refs to drop it anyway (same as a dangling reference).';
            throw new CommandRefusalException(
                'unresolved_reference_scope',
                'capture found option-name references without manifest-owned identities',
                'follow each diagnostic to pin the owning table or deliberately drop the unresolved reference',
                $diagnostics,
                $operatorMessage
            );
        }
        if ($tokens->unscopedUrlQueryRefs) {
            $lines = [];
            $diagnostics = [];
            foreach ($tokens->unscopedUrlQueryRefs as $reference) {
                $where = $reference['context'] !== '' ? "{$reference['context']}: " : '';
                $lines[] = "{$where}url query ref '{$reference['param']}' references post id {$reference['id']}, which is a real "
                    . "'{$reference['target_type']}' — but '{$reference['target_type']}' is not in policy.post_types, so its "
                    . 'identity was never tracked and the reference cannot resolve';
                $diagnostics[] = [
                    'code' => 'unresolved_url_query_reference_scope',
                    'surface' => 'url_query:' . $reference['param'],
                    'reference_kind' => 'post',
                    'target_type' => $reference['target_type'],
                    'message' => 'a real URL-query target is outside reviewed post-type scope',
                    'remediation' => 'add the target type to policy.post_types or explicitly use --force-unresolved-refs to drop the reference',
                ];
            }
            $operatorMessage = "duo: unresolvable url-query-typed reference(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . 'Add the missing post type to policy scope above and re-run capture, or pass '
                . '--force-unresolved-refs to drop it anyway (same as a dangling reference).';
            throw new CommandRefusalException(
                'unresolved_reference_scope',
                'capture found URL-query references outside reviewed post-type scope',
                'follow each diagnostic to expand post-type scope or deliberately drop the unresolved reference',
                $diagnostics,
                $operatorMessage
            );
        }
    }

    /** @param array<string,array{entities:int}> $scopeGaps */
    public function assertScopeGaps(array $scopeGaps): void {
        if ($scopeGaps === []) {
            return;
        }
        $lines = [];
        $diagnostics = [];
        foreach ($scopeGaps as $key => $evidence) {
            [$kind, $name] = explode(':', $key, 2);
            $policyKey = $kind === 'post_type' ? 'policy.post_types' : 'policy.taxonomies';
            $noun = $evidence['entities'] === 1 ? 'entity' : 'entities';
            $lines[] = "$kind '$name' has {$evidence['entities']} capturable $noun but is absent from $policyKey; "
                . "include it there, or record a deliberate exclusion with scope:$kind:$name=runtime|derived|env";
            $diagnostics[] = [
                'code' => 'policy_scope_gap',
                'surface' => 'scope:' . $kind . ':' . $name,
                'entity_count' => $evidence['entities'],
                'message' => 'capturable authored state exists outside reviewed policy scope',
                'remediation' => "add it to $policyKey or classify the exact scope as runtime, derived, or environment-owned",
            ];
        }
        $operatorMessage = "duo: registered or adapter-declared authored state exists outside policy scope (loud-and-blocking gate):\n  - "
            . implode("\n  - ", $lines)
            . "\nRun: wp duo pending --repo={$this->repo} for evidence, then either add the type/taxonomy "
            . "to policy scope or run wp duo classify --repo={$this->repo} --set='scope:<kind>:<name>=<class>'.";
        throw new CommandRefusalException(
            'incomplete_policy_scope',
            'capture found authored state outside reviewed policy scope',
            'follow each diagnostic to expand policy scope or record an explicit non-authored classification',
            $diagnostics,
            $operatorMessage
        );
    }

    public function assertContentReferences(Tokens $tokens): void {
        if ($tokens->unscopedBlockRefs) {
            $lines = [];
            $diagnostics = [];
            foreach ($tokens->unscopedBlockRefs as $reference) {
                $scopeKey = $reference['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "{$reference['post']} block '{$reference['block']}' attribute '{$reference['attr']}' references {$reference['kind']} id "
                    . "{$reference['id']}, which is a real '{$reference['target_type']}' — but '{$reference['target_type']}' is not in "
                    . "$scopeKey, so its identity was never tracked and the reference cannot resolve";
                $diagnostics[] = [
                    'code' => 'unresolved_block_reference_scope',
                    'surface' => 'block:' . $reference['block'] . ':' . $reference['attr'],
                    'reference_kind' => $reference['kind'],
                    'target_type' => $reference['target_type'],
                    'message' => 'a real block-attribute target is outside reviewed policy scope',
                    'remediation' => "add the target type to $scopeKey or explicitly use --force-unresolved-refs to drop the reference",
                ];
            }
            $operatorMessage = "duo: unresolvable ref-typed block attribute(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . 'Add the missing post type/taxonomy to policy scope above and re-run capture, or pass '
                . '--force-unresolved-refs to drop it anyway (same as a dangling reference).';
            throw new CommandRefusalException(
                'unresolved_reference_scope',
                'capture found block references outside reviewed policy scope',
                'follow each diagnostic to expand policy scope or deliberately drop the unresolved reference',
                $diagnostics,
                $operatorMessage
            );
        }
        if ($tokens->unscopedShortcodeRefs) {
            $lines = [];
            $diagnostics = [];
            foreach ($tokens->unscopedShortcodeRefs as $reference) {
                $scopeKey = $reference['kind'] === 'term' ? 'policy.taxonomies' : 'policy.post_types';
                $lines[] = "{$reference['post']} shortcode '{$reference['shortcode']}' attribute '{$reference['attr']}' references {$reference['kind']} id "
                    . "{$reference['id']}, which is a real '{$reference['target_type']}' — but '{$reference['target_type']}' is not in "
                    . "$scopeKey, so its identity was never tracked and the reference cannot resolve";
                $diagnostics[] = [
                    'code' => 'unresolved_shortcode_reference_scope',
                    'surface' => 'shortcode:' . $reference['shortcode'] . ':' . $reference['attr'],
                    'reference_kind' => $reference['kind'],
                    'target_type' => $reference['target_type'],
                    'message' => 'a real shortcode-attribute target is outside reviewed policy scope',
                    'remediation' => "add the target type to $scopeKey or explicitly use --force-unresolved-refs to drop the reference",
                ];
            }
            $operatorMessage = "duo: unresolvable ref-typed shortcode attribute(s) point at real, out-of-scope entities (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
                . "\nThis differs from a dangling reference (deleted target — dropped with a warning, unchanged): the "
                . "target genuinely exists right now, so this is a policy scope gap, not permanent data loss.\n"
                . 'Add the missing post type/taxonomy to policy scope above and re-run capture, or pass '
                . '--force-unresolved-refs to drop it anyway (same as a dangling reference).';
            throw new CommandRefusalException(
                'unresolved_reference_scope',
                'capture found shortcode references outside reviewed policy scope',
                'follow each diagnostic to expand policy scope or deliberately drop the unresolved reference',
                $diagnostics,
                $operatorMessage
            );
        }
    }

    public function guardSecret(string $section, string $key, $value, array $rule, string $context = ''): void {
        if (!empty($rule['allow_secret'])) {
            return;
        }
        $label = Secrets::hard_match_deep($value);
        if ($label === null) {
            return;
        }
        // The `=` form is required, not stylistic: wp-cli parses a
        // space-separated `--set` value as a bare boolean flag and the
        // intended value lands in positional $args instead, silently
        // (measured against this exact command, Cli.php:2335-2343; restated
        // normatively at docs/guides/adapter-authoring.md:631-636 and
        // spec/repo-format.md:621). An `options` row additionally needs
        // autoload=preserve or OptionGrammar::validate_option_storage()
        // refuses it (OptionGrammar.php:89-93) — `preserve` replays the
        // source row's own autoload flag (OptionGrammar.php:59-68), the same
        // shape ClassifyCommand's batched --set already emits
        // (regress_classify_command.php:370) — so the pasted remedy works
        // unmodified instead of trading one refusal for another.
        $setSpec = "$section:$key=authored" . ($section === 'options' ? ',autoload=preserve' : '');
        $operatorMessage = "duo: secret guard tripped — $section '$key'$context looks like a $label but is classified authored; "
            . "refusing to capture it into state/.\n"
            . "If this is really a secret, reclassify it env-bound or runtime instead of authored.\n"
            . "If this is a false positive, allow it explicitly:\n"
            . "  wp duo classify --repo={$this->repo} --set='$setSpec' --allow-secret";
        throw new CommandRefusalException(
            'secret_state_refused',
            'capture found secret-shaped data on an authored surface',
            'reclassify the named surface as environment/runtime state, or explicitly review and allow the false positive',
            [[
                'code' => 'secret_state_refused',
                'surface' => $section,
                'key' => $key,
                'secret_shape' => $label,
                'message' => 'authored state matched a secret signature',
                'remediation' => 'reclassify it or record an explicit reviewed allow-secret decision',
            ]],
            $operatorMessage
        );
    }

    public function guardPersonalData(string $key, $value, array $rule, string $login): void {
        if (!empty($rule['allow_pii'])) {
            return;
        }
        $label = PersonalData::match_deep($key, $value);
        if ($label === null) {
            return;
        }
        $operatorMessage = "duo: PII guard tripped — user_meta '$key' on exact login '$login' looks like $label but is "
            . "classified authored; refusing to capture it into state/.\n"
            . 'Keep it runtime/env, or declare "allow_pii": true on this exact user_meta rule after review.';
        throw new CommandRefusalException(
            'personal_data_refused',
            'capture found personal data on an authored user-meta surface',
            'keep the named field environment-local, or record an explicit reviewed allow-pii decision',
            [[
                'code' => 'personal_data_refused',
                'surface' => 'user_meta',
                'key' => $key,
                'personal_data_shape' => $label,
                'message' => 'authored user meta matched a personal-data signature',
                'remediation' => 'keep it environment-local or explicitly review allow-pii for this field',
            ]],
            $operatorMessage
        );
    }
}
