<?php
namespace WPrism;

// The compiler has established stubbed refusal loads for Canon/Policy. A
// normal direct load must be complete, but the stubbed path cannot load the
// registry because its transitive file unconditionally defines real Canon.
// Remember that pre-existing Canon boundary before completing the normal load.
$canonWasPreloaded = class_exists(Canon::class, false);
if (!$canonWasPreloaded) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!$canonWasPreloaded && !class_exists(AdapterSources::class, false)) {
    require_once __DIR__ . '/../Adapter/AdapterSources.php';
}
if (!$canonWasPreloaded && !class_exists(ManifestDispositions::class, false)) {
    require_once __DIR__ . '/ManifestDispositions.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}
if (!class_exists(ManifestExecutableLoader::class, false)) {
    require_once __DIR__ . '/../Kernel/ManifestExecutableLoader.php';
}
// WP-2.8: state_site_hash() names this grammar's site key directly. Guarded
// the same way its neighbours are, because the stubbed refusal path above can
// leave a Policy stub in place that never loaded the real grammar; the file
// itself requires nothing, so loading it here costs no graph.
if (!class_exists(VersionEvidenceGrammar::class, false)) {
    require_once __DIR__ . '/VersionEvidenceGrammar.php';
}
unset($canonWasPreloaded);

/**
 * Immutable identity projection for a validated Policy.
 *
 * Compilation consumes these values, but does not own their derivation: the
 * site policy and each pinned manifest are the entire authority for the
 * resulting artifact identity. Keeping that byte projection separate means
 * callers that need to bind or compare an artifact can do so without a state
 * tree walk, builder state, Tokens, Ledger, or any target access.
 */
final class ArtifactPolicyIdentity {
    public static function site_hash(Policy $policy): string {
        return hash('sha256', Canon::encode($policy->site));
    }

    /**
     * Identity of the site policy that can affect canonical state.
     *
     * The top-level code declaration selects the independent code payload
     * compiler/materializer. The complete declaration remains bound by
     * site_hash() and therefore by the outer compiled artifact, but it must
     * not move revision_hash: enabling or disabling identical code bytes is
     * not a canonical database-state change.
     *
     * WP-2.8's recorded probe evidence is unset for exactly that reason and no
     * other. It decides whether a deploy REFUSES an installed plugin version
     * (VersionEvidenceGrammar), so site_hash() still binds it and an artifact
     * compiled before the evidence landed is correctly rejected; but it names
     * no option, meta key, post type or table, so it cannot move one byte of
     * canonical state. Both unsets are no-ops on a site that declares neither
     * key, which is why no existing revision_hash moves.
     */
    public static function state_site_hash(Policy $policy): string {
        $site = $policy->site;
        unset($site['code'], $site[VersionEvidenceGrammar::SITE_KEY]);
        return hash('sha256', Canon::encode($site));
    }

    /**
     * The per-manifest content row manifest_hash()/resolved_adapters() both
     * bind, and the sole definition of adapter identity: a repository pin's
     * `adapter_digest`, `wprism assess`'s reported digest, and the contract that
     * pins it are all this one row hashed.
     *
     * @return list<array{name:string, manifest:array, disposition:?array, interpreter?:array{name:string,sha256:?string}, providers?:list<array{id:string,sha256:?string}>, regenerators?:list<array{name:string,sha256:?string}>}>
     */
    public static function manifest_rows(Policy $policy): array {
        $rows = [];
        foreach ($policy->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            $row = [
                'name' => $name,
                'manifest' => $manifest,
                'disposition' => $policy->manifest_disposition($name),
            ];
            $interpreter = $manifest['interpreter'] ?? null;
            if (is_string($interpreter) && $interpreter !== '') {
                $file = $policy->adapter_runtime_path($name, 'interpreters', basename($interpreter));
                $row['interpreter'] = [
                    'name' => $interpreter,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            // Manifest-sourced providers are executable code whose asserted
            // bytes are part of the adapter identity. Plugin providers remain
            // anchored to their installed plugin/version contract instead.
            $providerHashes = [];
            foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
                if (!is_array($declaration) || ($declaration['source'] ?? null) !== 'manifest') {
                    continue;
                }
                $id = (string) ($declaration['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $file = $policy->adapter_runtime_path($name, 'providers', basename($id));
                $providerHashes[] = [
                    'id' => $id,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            if ($providerHashes !== []) {
                $row['providers'] = $providerHashes;
            }
            // post_types{} is a map, so its discovery order is not identity.
            // De-duplicate and sort declared regenerators exactly as Policy's
            // runtime loader does before binding their shipped bytes.
            $regeneratorHashes = [];
            $seenRegenerators = [];
            foreach ((array) ($manifest['post_types'] ?? []) as $declaration) {
                $regenerator = is_array($declaration)
                    ? ($declaration['regen_dependency']['regenerator'] ?? null)
                    : null;
                if (!is_string($regenerator) || $regenerator === ''
                    || isset($seenRegenerators[$regenerator])) {
                    continue;
                }
                $seenRegenerators[$regenerator] = true;
                $file = $policy->adapter_runtime_path($name, 'regenerators', basename($regenerator));
                $regeneratorHashes[] = [
                    'name' => $regenerator,
                    'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
                ];
            }
            usort($regeneratorHashes, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            if ($regeneratorHashes !== []) {
                $row['regenerators'] = $regeneratorHashes;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function manifest_hash(Policy $policy): string {
        return hash('sha256', Canon::encode(self::manifest_rows($policy)));
    }

    /**
     * Resolve one manifest-shipped PHP component from the exact identity row
     * compilation and pinning already consume. Runtime loaders must use this
     * descriptor rather than independently rediscovering a path or digest.
     *
     * @return array{
     *     adapter:string,
     *     adapter_sha256:string,
     *     class:string,
     *     file:string,
     *     id:string,
     *     kind:'interpreters'|'providers'|'regenerators',
     *     sha256:?string
     * }
     */
    public static function runtime_component_descriptor(
        Policy $policy,
        string $adapter,
        string $kind,
        string $id
    ): array {
        if (!in_array($kind, ['interpreters', 'providers', 'regenerators'], true)) {
            throw new \RuntimeException("wprism: unknown adapter runtime kind '$kind'");
        }
        $class = ManifestExecutableLoader::className($kind, $id);
        foreach (self::manifest_rows($policy) as $row) {
            if (($row['name'] ?? null) !== $adapter) {
                continue;
            }
            $sha256 = null;
            $found = false;
            if ($kind === 'interpreters') {
                $entry = $row['interpreter'] ?? null;
                if (is_array($entry) && ($entry['name'] ?? null) === $id) {
                    $sha256 = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;
                    $found = true;
                }
            } else {
                $entries = $kind === 'providers'
                    ? (array) ($row['providers'] ?? [])
                    : (array) ($row['regenerators'] ?? []);
                $nameKey = $kind === 'providers' ? 'id' : 'name';
                foreach ($entries as $entry) {
                    if (!is_array($entry) || ($entry[$nameKey] ?? null) !== $id) {
                        continue;
                    }
                    $sha256 = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : null;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \RuntimeException(
                    "wprism: adapter '$adapter' identity declares no $kind component '$id'"
                );
            }
            return [
                'adapter' => $adapter,
                'adapter_sha256' => hash('sha256', Canon::encode($row)),
                'class' => $class,
                'file' => $policy->adapter_runtime_path($adapter, $kind, $id),
                'id' => $id,
                'kind' => $kind,
                'sha256' => $sha256,
            ];
        }
        throw new \RuntimeException("wprism: adapter identity has no manifest '$adapter'");
    }

    /**
     * @return list<array{name:string, digest:string, source:string, trust_tier:string, spec_version:?int, plugin:?string, version_range:?array, theme:?string, theme_version_range:?array, disposition:?array, capability:?array}>
     */
    public static function resolved_adapters(Policy $policy): array {
        $sources = $policy->adapter_sources();
        $out = [];
        foreach (self::manifest_rows($policy) as $row) {
            $manifest = $row['manifest'];
            $out[] = [
                'name' => $row['name'],
                'source' => $sources->source((string) $row['name']),
                'trust_tier' => AdapterSources::trust_tier($manifest),
                // manifest_rows() above already folded the interpreter,
                // provider, and regenerator bytes into $row in the exact shape
                // and order the digest binds, so hashing the row IS the
                // adapter digest — there is no second derivation to agree with.
                'digest' => hash('sha256', Canon::encode($row)),
                'spec_version' => isset($manifest['spec_version']) ? (int) $manifest['spec_version'] : null,
                'plugin' => isset($manifest['plugin']) ? (string) $manifest['plugin'] : null,
                'version_range' => is_array($manifest['version_range'] ?? null) ? $manifest['version_range'] : null,
                'theme' => isset($manifest['theme']) ? (string) $manifest['theme'] : null,
                'theme_version_range' => is_array($manifest['theme_version_range'] ?? null)
                    ? $manifest['theme_version_range'] : null,
                'disposition' => $row['disposition'],
                'capability' => $policy->capability_claim((string) $row['name']),
            ];
        }
        return $out;
    }
}
