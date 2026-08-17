<?php
namespace Duo;

// Circular with Policy.php's own require_once of this file: safe for the
// same reason SubKeyGrammar.php's and ActionProviderGrammar.php's identical
// circular requires are (DUO-3348 slices 6, 8) -- require_once marks
// Policy.php's path included the moment Policy.php's own require statement
// for this file runs, before Policy.php's body finishes executing, so this
// resolves to a no-op rather than a re-include.
require_once __DIR__ . '/../Policy/Policy.php';

/**
 * The pure manifest grammar for option names that embed a portable table-row
 * reference, extracted from `agent/src/Policy/Policy.php` (DUO-3348 slice 11).
 *
 * `validate_option_name_refs()` owns only the declaration shape: class, table
 * id_kind, the positive-name regex, its required named id capture, and the
 * optional malformed-name regex. `validate_no_overlapping_option_name_refs()`
 * owns the manifest-set guard that rejects identical patterns before pin
 * order can choose a winner. Runtime name matching, discovery, tokenization,
 * and value classification remain on Policy/Capture/OptionsMaterializer;
 * this class never reaches WordPress, a plugin, or a live option row.
 *
 * `Policy::CLASSES` remains the single classification vocabulary because it is
 * used at many other Policy call sites. The collaborator reads that public
 * constant rather than copying a second list that could drift.
 */
final class OptionReferenceGrammar {
    /** Validate one manifest's option-name reference declarations. */
    public static function validate_option_name_refs(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $rules = $manifest['option_name_refs'] ?? [];
        if (!is_array($rules) || !array_is_list($rules)) {
            throw new \RuntimeException("duo: manifest '$name' option_name_refs must be a list");
        }
        foreach ($rules as $i => $rule) {
            if (!is_array($rule)
                || !in_array($rule['class'] ?? null, Policy::CLASSES, true)
                || !is_string($rule['id_kind'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_]*$/', (string) $rule['id_kind'])
                || !is_string($rule['match'] ?? null)
                || (string) $rule['match'] === ''
                || @preg_match('/' . $rule['match'] . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i] must declare class, id_kind, and a valid match regex"
                );
            }
            if (substr_count((string) $rule['match'], '(?<id>') !== 1) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i].match must contain exactly one named (?<id>...) capture"
                );
            }
            if (array_key_exists('malformed_match', $rule)
                && (!is_string($rule['malformed_match'])
                    || $rule['malformed_match'] === ''
                    || @preg_match('/' . $rule['malformed_match'] . '/', '') === false)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_name_refs[$i].malformed_match must be a non-empty valid regex"
                );
            }
        }
    }

    /**
     * Identical option-name-ref regexes are unconditionally ambiguous. The
     * full regex-intersection problem is not decidable in this grammar, so
     * runtime consumers also use option_name_ref_match_details() and reject
     * every concrete live/canonical name matched by multiple declarations.
     */
    public static function validate_no_overlapping_option_name_refs(array $manifests): void {
        $seen = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest['option_name_refs'] ?? [] as $index => $rule) {
                $pattern = (string) ($rule['match'] ?? '');
                if ($pattern === '') {
                    continue;
                }
                if (isset($seen[$pattern])) {
                    $prior = $seen[$pattern];
                    throw new \RuntimeException(
                        "duo: option_name_refs rules '{$prior['manifest']}[{$prior['index']}]' and "
                        . "'" . (string) ($manifest['name'] ?? '?') . "[$index]' have identical overlapping match regexes"
                    );
                }
                $seen[$pattern] = [
                    'manifest' => (string) ($manifest['name'] ?? '?'),
                    'index' => (int) $index,
                ];
            }
        }
    }
}
