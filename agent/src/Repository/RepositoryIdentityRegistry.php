<?php
namespace WPrism;

// UUID ownership and natural-key validation are a pure compiler boundary.
// Normal direct loads close the collaborators this file names; focused CLI
// fixtures may preload Canon, Policy, or Snapshot doubles, so preserve that
// established boundary instead of redeclaring them.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}

/**
 * Collects immutable repository identities and reports their duplicate
 * contracts through RepositoryCompiler's single aggregate diagnostic sink.
 *
 * This registry deliberately does not read canonical files, touch WordPress,
 * or throw a partial failure: traversal remains in RepositoryCompiler, which
 * supplies rows in deterministic order and performs the one final sort.  The
 * same first-wins UUID map serves duplicate reporting, live/tombstone
 * conflicts, and later reference-kind validation.
 */
final class RepositoryIdentityRegistry {
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private Policy $policy;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;
    /** @var array<string,array{kind:string,path:string}> */
    private array $identities = [];

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, \Closure $add) {
        $this->policy = $policy;
        $this->add = $add;
    }

    public static function is_uuid(string $uuid): bool {
        return preg_match(self::UUID_RE, $uuid) === 1;
    }

    /**
     * Records one addressable canonical identity. Invalid UUIDs are reported
     * by the caller's schema-specific path; this method preserves the prior
     * compiler behavior by simply declining to register them.
     */
    public function register(string $uuid, string $kind, string $path): bool {
        if (!self::is_uuid($uuid)) {
            return false;
        }
        if (isset($this->identities[$uuid])) {
            $first = $this->identities[$uuid];
            $this->add('duplicate_uuid', $path, 'uuid', "uuid $uuid is already used by {$first['path']}", $first['path']);
            return false;
        }
        $this->identities[$uuid] = ['kind' => $kind, 'path' => $path];
        return true;
    }

    /** @return ?array{kind:string,path:string} */
    public function find(string $uuid): ?array {
        return $this->identities[$uuid] ?? null;
    }

    /** @param array<string,array<string,mixed>> $tree */
    public function validate_natural_identities(array $tree): void {
        $seen = [];
        $rows = Snapshot::row_tables($this->policy);
        foreach ($tree as $entity) {
            if ($entity['type'] === 'options') {
                continue;
            }
            $data = $entity['data'];
            $key = null;
            if ($entity['type'] === 'post') {
                $key = 'post|' . ($data['type'] ?? '') . '|' . ($data['slug'] ?? '')
                    . '|' . (is_scalar($data['parent'] ?? null) ? (string) $data['parent'] : '');
            } elseif ($entity['type'] === 'term') {
                $key = 'term|' . ($data['taxonomy'] ?? '') . '|' . ($data['slug'] ?? '');
            } elseif ($entity['type'] === 'menu') {
                $key = 'term|nav_menu|' . ($data['slug'] ?? '');
            } elseif (isset($rows[$entity['type']])
                && ($identityColumns = Policy::natural_key_columns($rows[$entity['type']])) !== []) {
                // The whole declared tuple, in declared order: a parent-scoped
                // key is unique only within its parent, never by one component.
                $components = [];
                foreach ($identityColumns as $column) {
                    $components[] = $data['columns'][$column] ?? null;
                }
                $key = 'table|' . $entity['type'] . '|' . Canon::encode($components);
            }
            if ($key === null) {
                continue;
            }
            if (isset($seen[$key])) {
                $this->add(
                    'duplicate_natural_identity',
                    $entity['path'],
                    'slug',
                    "natural identity collides with {$seen[$key]}",
                    $seen[$key]
                );
            } else {
                $seen[$key] = $entity['path'];
            }
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
