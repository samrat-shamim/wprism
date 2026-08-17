<?php
namespace Duo;

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
if (!$canonWasPreloaded && !class_exists(CapabilityRegistry::class, false)) {
    require_once __DIR__ . '/../Adapter/CapabilityRegistry.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
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
     */
    public static function state_site_hash(Policy $policy): string {
        $site = $policy->site;
        unset($site['code']);
        return hash('sha256', Canon::encode($site));
    }

    /**
     * The per-manifest content row manifest_hash()/resolved_adapters() both
     * bind. CapabilityRegistry::adapter_digest() deliberately mirrors this
     * row because it must hash a manifest without loading a compiler.
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
                $file = Policy::manifests_dir() . '/interpreters/' . basename($interpreter) . '.php';
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
                $file = Policy::manifests_dir() . '/providers/' . basename($id) . '.php';
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
                $file = Policy::manifests_dir() . '/regenerators/' . basename($regenerator) . '.php';
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
                'digest' => class_exists(CapabilityRegistry::class)
                    ? CapabilityRegistry::adapter_digest($manifest, $row['disposition'])
                    : hash('sha256', Canon::encode($row)),
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
