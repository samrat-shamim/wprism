<?php
namespace Duo;

// Circular with Policy.php's require_once of this file: safe because
// require_once marks Policy.php as included before its class body executes.
// Policy::assert_min_max_range() is the shared range grammar used by other
// manifest validators, so this collaborator calls the one existing owner
// rather than copying that refusal contract.
require_once __DIR__ . '/Policy.php';

/**
 * The pure discovery-contract grammar extracted from Policy.php
 * (DUO-3348): option namespace matchers and version-pinned authored-meta
 * keyspaces.
 *
 * Discovery uses these declarations to decide which live option names and
 * EAV keys an adapter may own. This class validates only manifest bytes;
 * discovery against a live target remains on Capture and its consumers.
 */
final class DiscoveryGrammar {
    /** Validate the journal-independent discovery vocabulary of one manifest. */
    public static function validate_discovery_contract(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        $namespaces = $manifest['option_namespaces'] ?? [];
        if (!is_array($namespaces)) {
            throw new \RuntimeException("duo: manifest '$name' option_namespaces must be an array");
        }
        foreach ($namespaces as $i => $decl) {
            $match = is_array($decl) ? ($decl['match'] ?? null) : null;
            if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' option_namespaces[$i].match must be a non-empty valid regex"
                );
            }
        }

        foreach ($manifest['tables'] ?? [] as $table => $decl) {
            if (($decl['class'] ?? '') !== 'authored_snapshot_meta' || !isset($decl['keyspace'])) {
                continue;
            }
            $keyspace = $decl['keyspace'];
            $range = is_array($keyspace) ? ($keyspace['version_range'] ?? null) : null;
            Policy::assert_min_max_range(
                is_array($range) ? $range : [],
                "manifest '$name' table '$table' keyspace version_range"
            );
            $keys = $keyspace['keys'] ?? [];
            $patterns = $keyspace['patterns'] ?? [];
            if (!is_array($keys) || !is_array($patterns)) {
                throw new \RuntimeException(
                    "duo: manifest '$name' table '$table' keyspace keys/patterns must be arrays"
                );
            }
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    throw new \RuntimeException(
                        "duo: manifest '$name' table '$table' keyspace.keys must contain non-empty strings"
                    );
                }
            }
            foreach ($patterns as $i => $pattern) {
                $match = is_array($pattern) ? ($pattern['match'] ?? null) : null;
                if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' table '$table' keyspace.patterns[$i].match must be a valid regex"
                    );
                }
            }
        }
    }
}
