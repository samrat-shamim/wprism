<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/Canon.php';
require_once dirname(__DIR__) . '/ScopedCertificationBundle.php';

/**
 * Exact dependency-enumeration and content-hashing seam for one subject.
 * Membership remains owned by ScopedCertificationBundle; this class prevents
 * callers from reimplementing path expansion or digest basis independently.
 */
final class EvidenceInputClosure {
    public const AUTHORITY = ScopedCertificationBundle::FORMAT;

    /** @return list<string> */
    public static function paths(
        string $root,
        string $kind,
        string $name,
        array $manifest,
        array $tests
    ): array {
        return ScopedCertificationBundle::subjectInputPaths($root, $kind, $name, $manifest, $tests);
    }

    /** @return list<array{path:string,sha256:string,size:int}> */
    public static function current(
        string $root,
        string $kind,
        string $name,
        array $manifest,
        array $tests
    ): array {
        return ScopedCertificationBundle::currentInputsForPaths(
            $root,
            self::paths($root, $kind, $name, $manifest, $tests)
        );
    }

    /** @param list<array{path:string,sha256:string,size:int}> $inputs */
    public static function digest(array $inputs): string {
        return ScopedCertificationBundle::closureDigest($inputs);
    }

    /** @param list<string> $changed @param list<string> $closure @return list<string> */
    public static function changedMembers(array $changed, array $closure): array {
        $matched = array_values(array_intersect($changed, $closure));
        sort($matched, SORT_STRING);
        return $matched;
    }
}
