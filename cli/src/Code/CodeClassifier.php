<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/WpOrgReleases.php';
require_once __DIR__ . '/ImportedArchives.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';

use WPrism\CodeSourceLock;

/**
 * The host's per-component sourcing decision: `wprism init` and
 * `wprism code-classify` share it, the agent verifies it, and the lock records it.
 *
 * Three outcomes, and the default is the blocking one:
 *
 * - `locked` — Git does not carry the component. Either its separately
 *   published wp.org release, the theme nested in the verified WordPress core
 *   release, or an archive the operator imported on this host unpacks to
 *   exactly the installed bytes. Every origin is verified by tree digest,
 *   never by name or version: a wp.org version can be re-packaged and a ZIP
 *   digest is not a tree digest, which is why the lock carries both.
 * - `first-party` — Git carries the component because the operator declared
 *   it the site's own code (`--first-party=<root>/<slug>`). Nothing is
 *   verified against anything; the declaration IS the decision, and it is
 *   recorded in the lock so the compile gate can tell it from omission.
 * - `unsourced` — neither. This is not a third shape Git could take: the
 *   agent turns it into a blocking `code_component_unsourced` row at init,
 *   `wprism code-classify` refuses on it, and the row's reason says what was
 *   tried so the operator can pick between importing an archive and
 *   declaring first-party.
 *
 * Order of lookup: a declared first-party identity short-circuits; otherwise
 * the separately published wp.org release is tried first, then an exact theme
 * nested in the target's WordPress core release, and the imported store last
 * (public provenance beats a private copy of the same bytes). `--offline`
 * changes only the wp.org legs, which answer from the host cache or not at all.
 */
final class CodeClassifier {
    public const LOCKED = 'locked';
    public const FIRST_PARTY = 'first-party';
    public const UNSOURCED = 'unsourced';

    /** @var list<string> */
    public const CLASSIFICATIONS = [self::LOCKED, self::FIRST_PARTY, self::UNSOURCED];

    public const FIRST_PARTY_FLAG = '--first-party=';

    public function __construct(private WpOrgReleases $releases, private ImportedArchives $imported) {
    }

    public static function make(?string $cacheDir, bool $offline): self {
        $releases = new WpOrgReleases($cacheDir ?? WpOrgReleases::defaultCacheDir(), $offline);
        return new self($releases, ImportedArchives::forReleases($releases));
    }

    /**
     * Parse every `--first-party=<root>/<slug>[,<root>/<slug>…]` occurrence
     * into one sorted identity list, refusing anything that is not a lockable
     * identity. Repeatable and comma-separable, because a site with four
     * in-house plugins should not need four shells.
     *
     * @param list<string> $values the raw values after the flag
     * @return list<string>
     */
    public static function parseFirstParty(array $values): array {
        $identities = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $identity) {
                $identity = trim($identity);
                if ($identity === '') {
                    continue;
                }
                if (!CodeSourceLock::is_identity($identity)) {
                    throw new \RuntimeException(
                        "--first-party value '$identity' must be <root>/<slug> with root "
                        . implode('|', CodeSourceLock::ROOTS) . ' and one safe component segment'
                    );
                }
                $identities[$identity] = true;
            }
        }
        return CodeSourceLock::sort_first_party(array_keys($identities));
    }

    /**
     * Every first-party identity must name a component this classification
     * is about, or the declaration is a typo that would silently declare
     * nothing — and the component the operator meant would block as
     * unsourced with a remedy that looks already applied.
     *
     * @param list<array{root:string,component:string,version:string,tree_sha256:string}> $components
     * @param list<string> $firstParty
     */
    public static function assertFirstPartyKnown(array $components, array $firstParty): void {
        $known = [];
        foreach ($components as $row) {
            $known[(string) $row['root'] . '/' . (string) $row['component']] = true;
        }
        $unknown = array_values(array_filter($firstParty, static fn(string $identity): bool => !isset($known[$identity])));
        if ($unknown !== []) {
            throw new \RuntimeException(
                '--first-party names ' . implode(', ', $unknown) . ', which is not a component this site has; '
                . 'first-party declarations are spelled <root>/<slug> exactly as the proposal lists them'
            );
        }
    }

    /**
     * @param list<array{root:string,component:string,version:string,tree_sha256:string}> $components
     * @param list<string> $firstParty sorted `{root}/{component}` identities
     * @param ?string $wordpressVersion exact target core release used only for bundled-theme comparison
     * @return list<array<string,mixed>> the classification the agent verifies, sorted by root then component
     */
    public function classify(array $components, array $firstParty = [], ?string $wordpressVersion = null): array {
        if ($wordpressVersion !== null && !WpOrgReleases::safeVersion($wordpressVersion)) {
            throw new \RuntimeException('WordPress core version cannot name a verified release archive');
        }
        $declared = array_fill_keys($firstParty, true);
        $plan = [];
        foreach ($components as $candidate) {
            $root = (string) $candidate['root'];
            $slug = (string) $candidate['component'];
            $version = (string) $candidate['version'];
            $tree = (string) $candidate['tree_sha256'];
            $row = [
                'classification' => self::UNSOURCED,
                'component' => $slug,
                'reason' => '',
                'root' => $root,
                'tree_sha256' => $tree,
                'version' => $version,
            ];
            if (isset($declared[$root . '/' . $slug])) {
                $row['classification'] = self::FIRST_PARTY;
                $row['reason'] = 'declared first-party by the operator; Git carries it as the site\'s own code';
                $plan[] = $row;
                continue;
            }
            $release = $this->releases->verifiedRelease($root, $slug, $version, $tree);
            if (isset($release['origin'])) {
                $row['classification'] = self::LOCKED;
                $row['reason'] = (string) $release['reason'];
                $row['origin'] = $release['origin'];
                $plan[] = $row;
                continue;
            }
            if ($wordpressVersion !== null && $root === 'themes') {
                $coreRelease = $this->releases->verifiedCoreBundledComponent(
                    $root,
                    $slug,
                    $wordpressVersion,
                    $tree
                );
                if (isset($coreRelease['origin'])) {
                    $row['classification'] = self::LOCKED;
                    $row['reason'] = (string) $coreRelease['reason'];
                    $row['origin'] = $coreRelease['origin'];
                    $plan[] = $row;
                    continue;
                }
                $release['reason'] .= '; ' . $coreRelease['reason'];
            }
            $imported = $this->imported->originForTree($tree, $root, $slug);
            if ($imported !== null) {
                $row['classification'] = self::LOCKED;
                $row['reason'] = 'imported archive ' . substr((string) $imported['archive_sha256'], 0, 12)
                    . '… unpacks to exactly these bytes';
                $row['origin'] = $imported;
                $plan[] = $row;
                continue;
            }
            $row['reason'] = (string) $release['reason'] . '; no imported archive on this host unpacks to this tree';
            $plan[] = $row;
        }
        usort($plan, static fn(array $a, array $b): int =>
            [(string) $a['root'], (string) $a['component']] <=> [(string) $b['root'], (string) $b['component']]);
        return $plan;
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return list<array<string,mixed>> the rows that are blocking
     */
    public static function unsourced(array $plan): array {
        return array_values(array_filter(
            $plan,
            static fn(array $row): bool => ($row['classification'] ?? null) === self::UNSOURCED
        ));
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return list<array<string,mixed>> lock entries for the locked rows
     */
    public static function lockRows(array $plan): array {
        $rows = [];
        foreach ($plan as $row) {
            if (($row['classification'] ?? null) !== self::LOCKED) {
                continue;
            }
            $rows[] = [
                'root' => $row['root'],
                'component' => $row['component'],
                'version' => $row['version'],
                'origin' => $row['origin'],
                'tree_sha256' => $row['tree_sha256'],
            ];
        }
        return CodeSourceLock::sort_components($rows);
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return list<string> sorted first-party identities
     */
    public static function firstPartyIdentities(array $plan): array {
        $identities = [];
        foreach ($plan as $row) {
            if (($row['classification'] ?? null) === self::FIRST_PARTY) {
                $identities[] = (string) $row['root'] . '/' . (string) $row['component'];
            }
        }
        return CodeSourceLock::sort_first_party($identities);
    }

    /** One rendered plan line, shared by init's proposal and code-classify's report. */
    public static function renderRow(array $row): string {
        return strtoupper((string) ($row['classification'] ?? self::UNSOURCED)) . ' '
            . ($row['root'] ?? '?') . '/' . ($row['component'] ?? '?') . ' '
            . ((string) ($row['version'] ?? '') !== '' ? $row['version'] : '(no version header)')
            . ' — ' . ($row['reason'] ?? '');
    }
}
