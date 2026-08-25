<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\Policy;

/**
 * The two flag-day migration verbs (WP-4.12, spec/repo-format.md § v3.12):
 * `duo adapter recertify <site-repo>` and `duo release --spec-v3 <site-repo>`.
 *
 * WHAT THEY ARE FOR
 * -----------------
 * The v3 flip is digest-neutral by construction — no shipped manifest and no
 * repository is re-stamped, so every adapter digest, every `manifest_hash` and
 * every content pin is the number it was. Two things are NOT neutral, and each
 * has one verb here:
 *
 *   1. CERTIFICATES. `spec_version` sits inside every signed
 *      `statement.platform`, so `assertPlatformBinding()` raises
 *      `StalePlatformSiteAdapterCertificate` against every certificate minted
 *      before the bump and the adapter degrades to `uncertified` (WP-1.1's
 *      routing: a named degradation, never a refused source). `recertify`
 *      re-establishes the claim.
 *   2. `artifact_hash`. Every resolved adapter row carries its capability
 *      claim and that claim embeds the platform boundary, so the compiled
 *      document moves on EVERY site — including one under full digest
 *      neutrality. `release --spec-v3` is what tells a site which of its
 *      surfaces that reaches, and journals what it held first.
 *
 * WHAT THEY DELIBERATELY DO NOT DO
 * --------------------------------
 * Neither verb re-stamps anything. `site.duo.json`'s own `spec_version` is
 * judged against the acceptance window (§ v3.1), so a repository declaring N-1
 * compiles unchanged; re-stamping it to N would move `site_hash` and
 * `revision_hash` for no capability gained AND would strand a rollback,
 * because the N-1 agent's window is {N-2, N-1}. That act is one of the three
 * gate G3 forbids (docs/guides/flag-day.md), and no verb performs it.
 *
 * `release --spec-v3` likewise does not WRITE pins. It emits the objects and
 * journals the prior ones; `duo adapter pin` is the verb that mutates
 * `site.duo.json`, and updating a pin stays an explicit review act exactly as
 * the spec's `site.duo.json` section has always said.
 */
final class SpecMigration {
    /** The sub-verb `duo adapter` routes here. */
    public const VERBS = ['recertify'];

    /** The flag that turns `duo release` into the repository-local migration verb. */
    public const RELEASE_FLAG = '--spec-v3';

    public const RECERTIFY_FORMAT = 'duo-adapter-recertify/v1';
    public const RELEASE_FORMAT = 'duo-spec-migration/v1';
    public const JOURNAL_FORMAT = 'duo-spec-migration-journal/v1';

    /**
     * Where a site's PRIOR pin objects are journaled, beside the frozen
     * authorization plans `.duo/releases/` already holds.
     *
     * A separate directory rather than a row inside `.duo/releases/`: an
     * authorization plan is content-addressed by `AuthorizationPlan::DIRECTORY`
     * and read back by `duo verify --plan=<digest>`, and dropping a document of
     * another shape in there would put bytes that gate cannot parse in the
     * directory it globs.
     */
    public const JOURNAL_RELATIVE = '.duo/migrations';

    /**
     * @param list<string> $args everything after `duo adapter`, including the sub-verb
     */
    public static function run(array $args): int {
        $verb = $args[0] ?? '';
        try {
            return match ($verb) {
                'recertify' => self::recertify(array_slice($args, 1)),
                default => self::fail("unknown subcommand '$verb'"),
            };
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // duo adapter recertify
    // -----------------------------------------------------------------

    /**
     * `duo adapter recertify <site-repo> --secret-key-file=<f> [--format=json]`
     *
     * Re-sign every certified site adapter in one invocation, under the key
     * each certificate already names.
     *
     * IDEMPOTENT BY REUSE, NOT BY A FLAG. `sign_site()` verifies any existing
     * certificate through the LIVE verifier and, only if a deterministic
     * re-sign of every current input is byte-identical to it, returns those
     * exact bytes with their original `created_at` (#555,
     * `AdapterCertification.php:1096-1126`). So a second run after a successful
     * one writes nothing and reports `unchanged` — `created_at` records when a
     * claim changed, not how often this command was invoked. That is what makes
     * the runbook's recertify step safe to re-run over a cohort whose members
     * are in different states.
     *
     * ALL-OR-NOTHING. A partial re-signing is the worst available outcome for
     * an operator: some adapters certified against the new boundary, some
     * against the old, and no single command that reports which. So the whole
     * invocation is staged — the site trust root's bytes and every certificate
     * file's bytes are captured before the first signature, and ANY failure
     * restores all of them before reporting. The trust root specifically,
     * because `AdapterCertify::certify()` already records what a stale record
     * costs: it can invalidate every OTHER certificate under the key.
     *
     * ONE KEY PER INVOCATION. The secret must match the key a certificate
     * names; a certificate under a different key is reported as a blocked row
     * naming that key id and makes the run non-green, rather than being skipped
     * silently. A repository holding two authority keys is re-certified by two
     * invocations, which is visible in an operator's shell history.
     *
     * @param list<string> $args
     */
    private static function recertify(array $args): int {
        $repoArg = null;
        $secretFile = null;
        $json = false;
        $seen = [];
        foreach ($args as $arg) {
            $flag = str_starts_with($arg, '--') ? explode('=', $arg, 2)[0] : null;
            if ($flag !== null) {
                if (isset($seen[$flag])) {
                    return self::fail("duplicate flag '$flag'");
                }
                $seen[$flag] = true;
            }
            if ($arg === '--format=json') {
                $json = true;
                continue;
            }
            if (str_starts_with($arg, '--secret-key-file=')) {
                $secretFile = trim(substr($arg, strlen('--secret-key-file=')));
                continue;
            }
            if (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            }
            if ($repoArg !== null) {
                return self::fail('recertify takes exactly one site repository');
            }
            $repoArg = $arg;
        }
        if ($repoArg === null || $repoArg === '') {
            return self::fail('recertify needs the site it is about: duo adapter recertify <site-repo> --secret-key-file=<f>');
        }
        if ($secretFile === null || $secretFile === '') {
            return self::fail('--secret-key-file=<path> is required: re-certification is an Ed25519 signature');
        }
        $repo = is_dir($repoArg) ? realpath($repoArg) : false;
        if ($repo === false) {
            return self::fail("'$repoArg' is not a directory");
        }
        if (!is_file($repo . '/site.duo.json')) {
            return self::fail("'$repo' has no site.duo.json — recertify takes the duo SITE REPO");
        }

        AdapterCertify::bootPublic();
        $manifestDir = Policy::manifests_dir();

        $certificateDir = $repo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR;
        $certificates = self::certificateFiles($certificateDir);
        if ($certificates === []) {
            // Not an error and not a green either-way shrug: a site with no
            // certificates has nothing the flag day withdrew, and saying so is
            // the answer a cohort runner needs.
            $report = [
                'format' => self::RECERTIFY_FORMAT,
                'repo' => $repo,
                'rows' => [],
                'status' => 'nothing_to_do',
                'summary' => ['blocked' => 0, 'certificates' => 0, 'resigned' => 0, 'unchanged' => 0],
                'target' => self::target($manifestDir),
            ];
            return self::emit($report, $json, 0);
        }

        $secret = AdapterCertify::readSecretKey($secretFile);
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);

        $authoritiesFile = $repo . '/' . AdapterCertify::AUTHORITIES_RELATIVE;
        $staged = ['authorities' => is_file($authoritiesFile) ? (string) file_get_contents($authoritiesFile) : null];
        foreach ($certificates as $name => $path) {
            $staged['cert:' . $name] = (string) file_get_contents($path);
        }

        $rows = [];
        $blocked = 0;
        $resigned = 0;
        $unchanged = 0;
        try {
            foreach ($certificates as $name => $path) {
                $prior = self::priorStatement((string) $staged['cert:' . $name]);
                if ($prior === null) {
                    $blocked++;
                    $rows[] = [
                        'adapter' => $name,
                        'key_id' => null,
                        'outcome' => 'blocked',
                        'detail' => 'the installed certificate is not a readable ' . AdapterCertification::FORMAT
                            . ' document, so the key and reason it was signed under cannot be re-used; '
                            . 're-sign it explicitly with `duo adapter certify`',
                    ];
                    continue;
                }
                if (!hash_equals($prior['public_key'], $public)) {
                    $blocked++;
                    $rows[] = [
                        'adapter' => $name,
                        'key_id' => $prior['key_id'],
                        'outcome' => 'blocked',
                        'detail' => "the certificate names authority key '{$prior['key_id']}', which the supplied "
                            . 'secret does not match — re-run recertify with that key, one invocation per key',
                    ];
                    continue;
                }
                $certificate = AdapterCertification::sign_site(
                    $manifestDir,
                    $repo,
                    $name,
                    $prior['key_id'],
                    base64_encode($secret),
                    $prior['reason']
                );
                $moved = !hash_equals((string) $staged['cert:' . $name], $certificate);
                if ($moved) {
                    AdapterCertify::writeCertificate($repo, $name, $certificate);
                    $resigned++;
                } else {
                    $unchanged++;
                }
                // Verify what is on disk through the live verifier before
                // claiming anything, exactly as `certify` does: a producer that
                // trusted its own bytes would leave the one certificate nobody
                // checked in the repository.
                $manifest = Canon::decode(Canon::read_file(
                    $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json'
                ));
                $verified = AdapterCertification::verifyFile($manifestDir, $repo, $name, $manifest, $path);
                $summary = AdapterCertification::certificateSummary($verified);
                $rows[] = [
                    'adapter' => $name,
                    'certification' => (string) ($summary['status'] ?? 'certified'),
                    'key_id' => $prior['key_id'],
                    'outcome' => $moved ? 'resigned' : 'unchanged',
                    'trust_tier' => (string) ($summary['trust_tier'] ?? ''),
                ];
            }
        } catch (\Throwable $t) {
            self::restore($authoritiesFile, $certificateDir, $staged);
            return self::fail(
                'recertify failed and every file it staged was restored to its prior bytes ('
                . AdapterCertify::AUTHORITIES_RELATIVE . ' and ' . count($certificates)
                . " certificate(s)); nothing in this repository moved: {$t->getMessage()}"
            );
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['adapter'], (string) $b['adapter']));
        $report = [
            'format' => self::RECERTIFY_FORMAT,
            'repo' => $repo,
            'rows' => $rows,
            'status' => $blocked === 0 ? 'ok' : 'blocked',
            'summary' => [
                'blocked' => $blocked,
                'certificates' => count($certificates),
                'resigned' => $resigned,
                'unchanged' => $unchanged,
            ],
            'target' => self::target($manifestDir),
        ];
        return self::emit($report, $json, $blocked === 0 ? 0 : 1);
    }

    // -----------------------------------------------------------------
    // duo release --spec-v3
    // -----------------------------------------------------------------

    /**
     * `duo release --spec-v3 <site-repo> [--artifact=<f>] [--scope-contract=<f>]
     *  [--snapshot=<f>] [--format=json]`
     *
     * The per-repository migration act: enumerate what the bump reaches on THIS
     * site, journal what the site holds NOW, and emit the pin objects the
     * post-flip library resolves.
     *
     * ORDER IS THE CONTRACT. The prior pin objects are written to the journal
     * BEFORE any new object is emitted, and that ordering is the whole reason
     * the journal exists: a site that re-pins and then rolls back holds pins
     * whose provenance is otherwise only in a shell scrollback. The record is
     * content-addressed, so re-running the verb on an unchanged site rewrites
     * the same path with the same bytes rather than accumulating one file per
     * invocation.
     *
     * NO ENVIRONMENT, NO TRANSPORT. Like `manifest-validate`, `merge-check` and
     * `adapter doctor`, this reads files on this machine. The operator most
     * likely to run it is mid-migration, which is exactly when an
     * environment-bound verb is least reachable.
     *
     * @param list<string> $args everything after `duo release`, `--spec-v3` included
     */
    public static function runRelease(array $args): int {
        $repoArg = null;
        $json = false;
        $held = ['artifact' => null, 'scope-contract' => null, 'snapshot' => null];
        $seen = [];
        foreach ($args as $arg) {
            $flag = str_starts_with($arg, '--') ? explode('=', $arg, 2)[0] : null;
            if ($flag !== null) {
                if (isset($seen[$flag])) {
                    return self::fail("duplicate flag '$flag'");
                }
                $seen[$flag] = true;
            }
            if ($arg === self::RELEASE_FLAG || $arg === '--format=json') {
                $json = $json || $arg === '--format=json';
                continue;
            }
            $matched = false;
            foreach (array_keys($held) as $name) {
                if (str_starts_with($arg, "--$name=")) {
                    $held[$name] = trim(substr($arg, strlen("--$name=")));
                    $matched = true;
                }
            }
            if ($matched) {
                continue;
            }
            if (str_starts_with($arg, '-')) {
                return self::fail("unsupported flag '$arg'");
            }
            if ($repoArg !== null) {
                return self::fail(self::RELEASE_FLAG . ' takes exactly one site repository');
            }
            $repoArg = $arg;
        }
        if ($repoArg === null || $repoArg === '') {
            return self::fail(
                self::RELEASE_FLAG . ' needs the site it is about: duo release ' . self::RELEASE_FLAG
                . ' <site-repo> (the directory holding site.duo.json)'
            );
        }
        $repo = is_dir($repoArg) ? realpath($repoArg) : false;
        if ($repo === false) {
            return self::fail("'$repoArg' is not a directory");
        }
        if (!is_file($repo . '/site.duo.json')) {
            return self::fail("'$repo' has no site.duo.json — " . self::RELEASE_FLAG . ' takes the duo SITE REPO');
        }
        foreach ($held as $name => $path) {
            if ($path === null) {
                continue;
            }
            $resolved = is_file($path) ? realpath($path) : false;
            if ($resolved === false) {
                return self::fail("--$name '$path' is not a file");
            }
            $held[$name] = $resolved;
        }

        try {
            AdapterCertify::bootPublic();
            $manifestDir = Policy::manifests_dir();
            // The preflight, CALLED and not restated (WP-1.5). Every movement
            // row below is its verdict; deriving a second answer here is the
            // drift that turns a controlled bump into an incident.
            $preflight = MigrationPreflight::enumerate($repo, $manifestDir, $held);
            $site = Canon::decode(Canon::read_file($repo . '/site.duo.json'));
            $priorPins = self::priorPins($site);
            $proposedPins = self::proposedPins($repo);
        } catch (\Throwable $t) {
            return self::fail($t->getMessage());
        }

        $record = [
            'format' => self::JOURNAL_FORMAT,
            'prior' => [
                'pins' => $priorPins,
                'site_sha256' => hash('sha256', Canon::read_file($repo . '/site.duo.json')),
                'spec_version' => $site['spec_version'] ?? null,
            ],
            'proposed' => ['pins' => $proposedPins],
            'repo' => $repo,
            'target' => $preflight['target'] ?? self::target($manifestDir),
        ];
        try {
            $journalPath = self::journal($repo, $record);
        } catch (\Throwable $t) {
            // The journal write is the FIRST mutation and the only one, so a
            // failure here means nothing was emitted as though it had been
            // recorded. Refusing is the whole point of doing it first.
            return self::fail(
                'the prior pin objects could not be journaled, so no new pin object is emitted: '
                . $t->getMessage()
            );
        }

        $report = [
            'format' => self::RELEASE_FORMAT,
            'journal' => substr($journalPath, strlen($repo) + 1),
            'movements' => $preflight['movements'] ?? [],
            'prior_pins' => $priorPins,
            'proposed_pins' => $proposedPins,
            'repo' => $repo,
            'status' => (string) ($preflight['status'] ?? 'unknown'),
            'target' => $record['target'],
            'unclassified' => $preflight['unclassified'] ?? [],
        ];
        // The exit code follows the PREFLIGHT's verdict, not this verb's own
        // success: a site with an unclassified gate must not report green
        // merely because its pins were journaled.
        return self::emit($report, $json, ($preflight['status'] ?? 'ok') === 'ok' ? 0 : 1);
    }

    // -----------------------------------------------------------------
    // shared
    // -----------------------------------------------------------------

    /** @return array<string,string> adapter name => certificate path, sorted */
    private static function certificateFiles(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $out[basename($path, '.json')] = $path;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The key id, key material and stated reason an installed certificate was
     * signed under — the three inputs a re-sign must REUSE rather than invent.
     *
     * Read out of the statement the signature covers, never out of the
     * envelope: the envelope's members sit outside every signature, which is
     * the note `SupersededWireSiteAdapterCertificate` already records.
     *
     * @return null|array{key_id:string,public_key:string,reason:string}
     */
    private static function priorStatement(string $raw): ?array {
        try {
            $decoded = Canon::decode($raw);
        } catch (\Throwable) {
            return null;
        }
        $statement = $decoded['statement'] ?? null;
        if (!is_array($statement)) {
            return null;
        }
        $keyId = $statement['authority']['key_id'] ?? null;
        $encoded = $statement['authority']['record']['public_key'] ?? null;
        $reason = $statement['bundle']['evidence']['reason'] ?? null;
        if (!is_string($keyId) || $keyId === '' || !is_string($encoded) || !is_string($reason) || $reason === '') {
            return null;
        }
        $public = base64_decode($encoded, true);
        if ($public === false) {
            return null;
        }
        return ['key_id' => $keyId, 'public_key' => $public, 'reason' => $reason];
    }

    /**
     * @param array<string,string|null> $staged
     */
    private static function restore(string $authoritiesFile, string $certificateDir, array $staged): void {
        foreach ($staged as $key => $bytes) {
            if ($key === 'authorities') {
                if ($bytes === null) {
                    @unlink($authoritiesFile);
                } else {
                    file_put_contents($authoritiesFile, $bytes, LOCK_EX);
                }
                continue;
            }
            $name = substr($key, strlen('cert:'));
            file_put_contents($certificateDir . '/' . $name . '.json', (string) $bytes, LOCK_EX);
        }
    }

    /**
     * The pin objects exactly as `site.duo.json` holds them today.
     *
     * The legacy string form is kept as a string rather than normalised into an
     * object: the journal's job is to record what the site HAD, and rewriting
     * a bare name into `{name: …}` would journal a document the site never
     * held. `PinResolver` treats the two as equivalent; a rollback restoring
     * these bytes must restore the spelling too.
     *
     * @param array<string,mixed> $site
     * @return list<mixed>
     */
    private static function priorPins(array $site): array {
        $pins = $site['manifests'] ?? [];
        return is_array($pins) ? array_values($pins) : [];
    }

    /**
     * The `{name, source, digest}` objects the post-flip library resolves.
     *
     * Taken from `ArtifactPolicyIdentity::resolved_adapters()` — the same call
     * `duo adapter pin` and `wp duo manifest-pin` make — so the digest is the
     * one the engine will check and not a second hash of the same file. Under a
     * digest-neutral flip these are expected to EQUAL the prior objects for
     * every shipped adapter; a site holding a certified site adapter is where
     * they can differ, because the withdrawal moves that adapter's row.
     *
     * @return list<array{digest:string,name:string,source:string}>
     */
    private static function proposedPins(string $repo): array {
        $policy = Policy::load($repo);
        $out = [];
        foreach (ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
            $out[] = [
                'digest' => (string) $row['digest'],
                'name' => (string) $row['name'],
                'source' => (string) $row['source'],
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Write the record content-addressed under `.duo/migrations/`.
     *
     * Content-addressed rather than timestamped so the verb is idempotent: an
     * operator who runs it twice on an unchanged site rewrites one path with
     * identical bytes instead of accumulating a directory of near-duplicates
     * that no reader can tell apart. The record therefore carries no clock
     * value at all — every member of it is an identity.
     *
     * @param array<string,mixed> $record
     */
    private static function journal(string $repo, array $record): string {
        $dir = $repo . '/' . self::JOURNAL_RELATIVE;
        // `@mkdir` and a separate existence test rather than a bare mkdir:
        // a PHP warning on stdout is not a refusal, and this verb's whole
        // ordering contract is that its OUTPUT is empty when the journal
        // cannot be written. A leaked "File exists" line would be output.
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new \RuntimeException(
                    "$dir exists and is not a directory, so the prior pin objects have nowhere to go"
                );
            }
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create $dir");
            }
        }
        $encoded = Canon::encode($record);
        $path = $dir . '/' . hash('sha256', $encoded) . '.json';
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("cannot write $path");
        }
        return $path;
    }

    /** @return array<string,mixed> */
    private static function target(string $manifestDir): array {
        return [
            'agent_version' => DUO_AGENT_VERSION,
            'manifests_dir' => $manifestDir,
            'spec_version' => DUO_SPEC_VERSION,
        ];
    }

    /**
     * @param array<string,mixed> $report
     */
    private static function emit(array $report, bool $json, int $exit): int {
        if ($json) {
            echo rtrim(Canon::encode($report)) . "\n";
            return $exit;
        }
        $format = (string) ($report['format'] ?? '');
        echo "repo:   {$report['repo']}\n";
        echo 'target: agent ' . DUO_AGENT_VERSION . ' / spec ' . DUO_SPEC_VERSION . "\n";
        if ($format === self::RECERTIFY_FORMAT) {
            $summary = (array) $report['summary'];
            echo "\n";
            foreach ((array) $report['rows'] as $row) {
                $row = (array) $row;
                echo '  [' . $row['outcome'] . '] ' . $row['adapter'];
                if (($row['key_id'] ?? null) !== null) {
                    echo ' (key ' . $row['key_id'] . ')';
                }
                echo "\n";
                if (isset($row['detail'])) {
                    echo '      ' . $row['detail'] . "\n";
                }
            }
            echo "\n{$summary['certificates']} certificate(s): {$summary['resigned']} re-signed, "
                . "{$summary['unchanged']} unchanged, {$summary['blocked']} blocked\n";
            if ((int) $summary['resigned'] > 0) {
                echo "\nA re-signed certificate binds spec_version " . DUO_SPEC_VERSION . ", which the previous\n"
                    . "agent does not verify. This site can no longer be rolled back without running\n"
                    . "recertify again against the restored boundary (gate G3 — docs/guides/flag-day.md).\n";
            }
            return $exit;
        }
        echo 'journal: ' . $report['journal'] . " (prior pin objects, written before any new one)\n";
        echo "\nproposed pin objects for site.duo.json manifests[]:\n";
        echo rtrim(Canon::encode($report['proposed_pins'])) . "\n";
        $movements = (array) $report['movements'];
        echo "\n" . count($movements) . " surface(s) this bump reaches on this site:\n";
        foreach ($movements as $movement) {
            $movement = (array) $movement;
            echo '  [' . ($movement['class'] ?? 'movement') . '] ' . ($movement['subject'] ?? ($movement['id'] ?? '?')) . "\n";
        }
        foreach ((array) $report['unclassified'] as $row) {
            $row = (array) $row;
            echo '  [unclassified] ' . ($row['subject'] ?? ($row['id'] ?? '?')) . "\n";
        }
        echo "\nNothing was written to site.duo.json. Updating a pin is an explicit review act:\n"
            . "  duo adapter pin <site-repo> --name=<n> [--source=site]\n";
        return $exit;
    }

    private static function fail(string $message): int {
        fwrite(STDERR, "duo adapter: $message\n");
        return 2;
    }
}
