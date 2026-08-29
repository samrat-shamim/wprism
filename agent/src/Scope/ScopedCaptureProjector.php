<?php
namespace WPrism;

require_once __DIR__ . '/../Repository/CompiledArtifact.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Policy/ScopeContract.php';
require_once __DIR__ . '/ScopedStateOverlay.php';

/** Resolves a scoped capture request against one frozen repository revision. */
final class ScopedCaptureProjector {
    /** @return array<string,mixed> */
    public static function contractForRequest(
        array $request,
        CompiledRepository $compiled,
        Policy $policy
    ): array {
        if (($request['format'] ?? null) === ScopeContract::FORMAT) {
            $contract = ScopeContract::from_array($request);
            ScopeContract::assert_associated($contract, $compiled, $policy);
            return $contract;
        }
        $keys = array_keys($request);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'scope_hash', 'selectors']
            || ($request['format'] ?? null) !== 'wprism-scope-request/v1'
            || !is_array($request['selectors'] ?? null)
            || !array_is_list($request['selectors'])) {
            throw new \RuntimeException('wprism: scoped capture request has an unexpected schema');
        }
        $selectors = ScopeContract::normalize_selectors($request['selectors']);
        return ScopedStateOverlay::resolve_request(
            $compiled,
            $policy,
            $selectors,
            (string) ($request['scope_hash'] ?? '')
        );
    }
}
