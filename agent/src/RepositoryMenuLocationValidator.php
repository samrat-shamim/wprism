<?php
namespace Duo;

// This is a pure canonical-tree topology check. Normal direct loads close
// Policy, while focused fixtures may preload a narrow double; retain the
// conditional boundary used by sibling compiler validators.
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}

/**
 * Validates exclusive ownership of active theme menu locations.
 *
 * The validator receives the already-parsed canonical tree and reports via
 * RepositoryCompiler's aggregate callback. It neither discovers files nor
 * applies menu assignments, so a malformed full revision and a scoped
 * candidate that combines a selected menu with a protected holder both fail
 * before any authority/session or target mutation can be created.
 */
final class RepositoryMenuLocationValidator {
    private Policy $policy;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, \Closure $add) {
        $this->policy = $policy;
        $this->add = $add;
    }

    /** @param array<string,array<string,mixed>> $tree */
    public function validate(array $tree): void {
        // A manifest that classifies locations as derived owns that whole
        // surface and is deliberately exempt from the authored invariant.
        if ($this->policy->menu_field_class('locations') === 'derived') {
            return;
        }
        $holders = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== 'menu') {
                continue;
            }
            $path = (string) ($entity['path'] ?? '');
            foreach ((array) ($entity['data']['locations'] ?? []) as $i => $location) {
                if (!is_string($location) || $location === '') {
                    continue; // RepositoryEntityParser owns the shape diagnostic.
                }
                if (isset($holders[$location])) {
                    $this->add(
                        'duplicate_menu_location',
                        $path,
                        "locations[$i]",
                        "menu location '$location' is already assigned by {$holders[$location]}",
                        $holders[$location]
                    );
                    continue;
                }
                $holders[$location] = $path;
            }
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
