<?php
namespace Duo;

require_once __DIR__ . '/AdapterSources.php';
// Circular with Policy.php's require_once of this file: safe because
// require_once records the currently included path before the nested require
// is reached, while these methods only resolve Policy at call time.
require_once __DIR__ . '/Policy.php';

/**
 * Pure adapter compatibility contract grammar.
 *
 * This collaborator validates per-manifest plugin/theme/spec/interpreter
 * claims and the cross-manifest no-conflicting-ownership guard. It never
 * reads the live environment or runs provider code. Policy retains the
 * shared range predicate and loader/runtime/reporting orchestration.
 */
final class AdapterContractGrammar {
    /**
     * Validate one manifest's adapter compatibility contract.
     */
    public static function validate_adapter_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $spec = $manifest['spec_version'] ?? null;
        $supported = defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 0;
        if (!is_int($spec) || $spec !== $supported) {
            $declared = $spec === null ? 'no spec_version' : ('spec_version ' . var_export($spec, true));
            throw new \RuntimeException(
                "duo: manifest '$name' declares $declared"
                . " but this engine requires spec_version $supported — pin a compatible manifest or update it"
            );
        }
        // Validate the interpreter name at load rather than waiting for the
        // lazy interpreters() lookup to hand a non-string to preg_match().
        if (array_key_exists('interpreter', $manifest) && $manifest['interpreter'] !== null) {
            $interpreter = $manifest['interpreter'];
            if (!is_string($interpreter) || preg_match('/^[a-z0-9_-]+$/D', $interpreter) !== 1) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares interpreter " . var_export($interpreter, true)
                    . ' — an interpreter name must be a non-empty string matching ^[a-z0-9_-]+$, since it resolves '
                    . 'to <manifests_dir>/interpreters/<name>.php'
                );
            }
        }
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $id = $manifest[$idKey] ?? null;
            if ($id === null) {
                continue;
            }
            if (!is_string($id) || $id === '') {
                throw new \RuntimeException("duo: manifest '$name' declares a non-string or empty '$idKey'");
            }
            if ($idKey === 'plugin') {
                AdapterSources::assert_plugin_basename($id, "manifest '$name' declares 'plugin'");
            }
            // The identifier is later concatenated into filesystem paths by
            // code-version consumers, so reject traversing or absolute theme
            // identities before any consumer sees them.
            $segments = explode('/', $id);
            $depthOk = $idKey === 'plugin' ? count($segments) <= 2 : count($segments) === 1;
            if ($idKey !== 'plugin' && (!$depthOk || $id[0] === '/' || str_contains($id, '\\')
                || in_array('..', $segments, true) || in_array('.', $segments, true)
                || in_array('', $segments, true))) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' " . var_export($id, true)
                    . ' — a ' . $idKey . ' identifier is '
                    . ($idKey === 'plugin' ? "'<directory>/<file>.php' or '<file>.php'" : 'a bare directory slug')
                    . ', never an absolute path and never one containing a ".." segment; it is concatenated into '
                    . 'filesystem paths by the code-half version checks'
                );
            }
            $range = $manifest[$rangeKey] ?? null;
            if (!is_array($range)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares '$idKey' ('$id') but no '$rangeKey' — an adapter naming a "
                    . "$idKey with no exact version range is unbounded support, which this project's contract "
                    . 'forbids (DUO-3222). Declare {"min":..,"max":..} or drop the ' . "$idKey claim."
                );
            }
            Policy::assert_min_max_range($range, "manifest '$name' declares '$rangeKey'");
        }
    }

    /**
     * Reject load-order-dependent ownership when two pinned manifests name
     * the same plugin or theme with different compatibility ranges. Identical
     * ranges remain redundant but deterministic and are intentionally allowed.
     *
     * @param list<array<string,mixed>> $manifests
     */
    public static function validate_no_conflicting_adapter_claims(array $manifests): void {
        foreach ([['plugin', 'version_range'], ['theme', 'theme_version_range']] as [$idKey, $rangeKey]) {
            $seen = [];
            foreach ($manifests as $m) {
                $id = $m[$idKey] ?? null;
                if (!is_string($id) || $id === '') {
                    continue;
                }
                $range = $m[$rangeKey] ?? [];
                $name = (string) ($m['name'] ?? '?');
                if (isset($seen[$id])) {
                    $prev = $seen[$id];
                    if ($prev['range'] != $range) {
                        throw new \RuntimeException(
                            "duo: manifests '{$prev['name']}' and '$name' both declare $idKey '$id' with "
                            . "different $rangeKey values (" . json_encode($prev['range']) . ' vs '
                            . json_encode($range) . ') — conflicting ownership with no v2 composition rule; '
                            . 'pin only one, or narrow one range to a disjoint window'
                        );
                    }
                    continue;
                }
                $seen[$id] = ['name' => $name, 'range' => $range];
            }
        }
    }
}
