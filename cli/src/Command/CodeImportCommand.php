<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Code/WpOrgReleases.php';
require_once __DIR__ . '/../Code/ImportedArchives.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\CommandRefusalException;

/**
 * `duo code-import <archive.zip>` — put a release archive the operator holds
 * into this host's code-artifact cache, so a component that has no wp.org
 * release (a premium plugin, a private theme, a vendor build) can be LOCKED
 * rather than carried in Git.
 *
 * No `<env>`, no transport, no registry: the cache is a property of the host,
 * not of a site, and the import has to be possible before the `duo init` that
 * classifies the component — the same shape `manifest-validate` and
 * `adapter-draft` take in cli/duo. It reads exactly one local file the
 * operator named and writes only under the cache directory.
 *
 * What it prints is what the lock will record (`archive_sha256`, and
 * `archive_root` when the archive's directory is not named after the
 * component) plus the tree digest a site's installed component must hash to
 * for the classifier to lock against this archive. Nothing about where the
 * archive came from is recorded anywhere: a vendor's download link is
 * license-keyed, and the repository must carry neither it nor the bytes.
 */
final class CodeImportCommand {
    /** @param list<string> $args */
    public static function run(array $args): int {
        $archive = null;
        $component = null;
        $root = 'plugins';
        $cacheDir = null;
        $json = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--component=')) {
                $component = substr($arg, strlen('--component='));
                if ($component === '') {
                    fwrite(STDERR, "duo: code-import --component requires a slug\n");
                    return 1;
                }
                continue;
            }
            if (str_starts_with($arg, '--root=')) {
                $root = substr($arg, strlen('--root='));
                continue;
            }
            if (str_starts_with($arg, '--cache-dir=')) {
                $cacheDir = substr($arg, strlen('--cache-dir='));
                if ($cacheDir === '' || !str_starts_with($cacheDir, '/')) {
                    fwrite(STDERR, "duo: code-import --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            if ($arg === '--format=json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--')) {
                fwrite(
                    STDERR,
                    'duo: code-import accepts one <archive.zip> and only --component=<slug>, --root=plugins|themes, '
                    . "--cache-dir=<path> and --format=json; unsupported argument '$arg'\n"
                );
                return 1;
            }
            if ($archive !== null) {
                fwrite(STDERR, "duo: code-import imports exactly one archive per run\n");
                return 1;
            }
            $archive = $arg;
        }
        if ($archive === null) {
            fwrite(STDERR, "duo: code-import requires the path of one release archive (.zip)\n");
            return 1;
        }

        try {
            $releases = new WpOrgReleases($cacheDir ?? WpOrgReleases::defaultCacheDir(), true);
            $result = ImportedArchives::forReleases($releases)->import($archive, $component, $root);
        } catch (CommandRefusalException $refusal) {
            fwrite(STDERR, 'duo: code-import: ' . $refusal->getMessage() . "\n");
            fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
            fwrite(STDERR, 'remedy: ' . $refusal->remediation . "\n");
            return 1;
        } catch (\Throwable $error) {
            fwrite(STDERR, 'duo: code-import: ' . $error->getMessage() . "\n");
            return 1;
        }

        if ($json) {
            echo json_encode(['format' => 'duo-code-import/v1'] + $result, JSON_UNESCAPED_SLASHES) . "\n";
            return 0;
        }
        $identity = $result['root'] . '/' . $result['component'];
        echo 'duo: code-import: ' . ($result['state'] === 'imported' ? 'imported' : 'already imported') . ' '
            . $identity . ' ' . ($result['version'] !== '' ? $result['version'] : '(no version header)') . "\n";
        echo '  archive_sha256: ' . $result['archive_sha256'] . "\n";
        echo '  tree_sha256:    ' . $result['tree_sha256'] . "\n";
        if ($result['archive_root'] !== $result['component']) {
            echo '  archive_root:   ' . $result['archive_root'] . "\n";
        }
        echo '  stored at:      ' . $result['path'] . "\n";
        echo "duo: a site whose installed $identity hashes to this tree now locks against this archive: run "
            . '`duo init <env>` (or `duo code-classify <env>` for an initialized repository). The lock records the '
            . "digests only; move the archive to every host that resolves, with this same command.\n";
        return 0;
    }
}
