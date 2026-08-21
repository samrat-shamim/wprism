<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';

/**
 * An init phase the target answered with the common v1 refusal envelope.
 *
 * DUO-3421: the envelope is a STRUCTURED public answer on stdout, and the host
 * renders it through render_command_refusal_human() exactly as cmd_status()
 * and fetch_pending() do (DUO-3399). Carrying it on a typed exception rather
 * than flattening it into the message keeps that rendering possible at the
 * command layer and keeps this class free of any host output convention.
 */
final class InitRefusalException extends \RuntimeException {
    /** @var array<string,mixed> */
    public array $refusal;

    /** @param array<string,mixed> $refusal */
    public function __construct(string $message, array $refusal) {
        parent::__construct($message);
        $this->refusal = $refusal;
    }
}

/** Host wrapper for the target agent's digest-bound initialization protocol. */
final class Init {
    /**
     * T6 §3.4. The operator's statement that active plugins with no owning
     * adapter are a KNOWN, ACCEPTED condition of this site rather than a
     * defect to fix before starting.
     *
     * It is forwarded to BOTH target calls and never remembered on the host.
     * The proposal and the confirmation are two separate agent invocations
     * bound by a digest, and the digest covers the plan the flag produced —
     * so confirming without the flag would ask the target to re-plan under
     * different rules and fail the digest bind, which is the right failure
     * but a confusing one to read. Passing it twice keeps the two calls
     * describing the same site.
     */
    public const ALLOW_UNMANAGED_PLUGINS = '--allow-unmanaged-plugins';

    /**
     * The one advisory code that names a whole body of state Duo will not
     * version, rather than a gap inside something it does.
     *
     * T6 §3.4 gives it its own line shape for that reason. `ADVISORY PLUGIN
     * wpforms-lite/wpforms.php [active_plugin_without_adapter]: no installed
     * manifest declares…` reads as one more caveat in a list of caveats;
     * `UNMANAGED PLUGIN wpforms-lite/wpforms.php` reads as the sentence it
     * is. The reason and remediation follow on their own indented line
     * instead of being run onto the end, so the identity stays greppable.
     */
    public const UNMANAGED_PLUGIN_CODE = 'active_plugin_without_adapter';

    /**
     * The host's per-component code classification, forwarded to BOTH target
     * calls for the same reason ALLOW_UNMANAGED_PLUGINS is (DUO-3499).
     *
     * The classification is a decision — it changes what the repository
     * declares and what Git carries — so it lives inside the proposal digest.
     * A confirmation that omitted it would ask the target to re-plan a fully
     * vendored repository and fail the digest bind, which is the right failure
     * but a confusing one to read.
     */
    public const CODE_LOCK_ARGUMENT = '--code-lock-b64';

    /** The two code declarations a proposal may carry. */
    private const DECLARATION_FULL = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
    private const DECLARATION_SPLIT = [
        'format' => 2,
        'layout' => 'wp-content',
        'lock' => 'code/duo-code.lock.json',
        'source' => 'code/wp-content',
    ];

    /** @param ?list<array<string,mixed>> $lockPlan @return array<string,mixed> */
    public static function proposal(
        EnvironmentDriver $transport,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        $args = ['duo', 'init', '--repo=' . $transport->repoPath(), '--format=json'];
        if ($allowUnmanagedPlugins) {
            $args[] = self::ALLOW_UNMANAGED_PLUGINS;
        }
        if ($lockPlan !== null) {
            $args[] = self::lockArgument($lockPlan);
        }
        $proposal = self::request($transport, $args, 'proposal');
        self::assertProposal($transport, $proposal);
        return $proposal;
    }

    /** @param ?list<array<string,mixed>> $lockPlan @return array<string,mixed> */
    public static function confirm(
        EnvironmentDriver $transport,
        string $digest,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1) {
            throw new \RuntimeException('duo init confirmation requires the exact 64-hex proposal digest');
        }
        $args = [
            'duo', 'init', '--repo=' . $transport->repoPath(),
            '--confirm=' . $digest, '--format=json',
        ];
        if ($allowUnmanagedPlugins) {
            $args[] = self::ALLOW_UNMANAGED_PLUGINS;
        }
        if ($lockPlan !== null) {
            $args[] = self::lockArgument($lockPlan);
        }
        $result = self::request($transport, $args, 'confirmation');
        self::assertResult($transport, $result, $digest);
        return $result;
    }

    /**
     * Canonical JSON, base64-encoded — the same wire shape `--scope-request-b64`
     * uses, so the classification survives every transport's argv quoting
     * unchanged and the target verifies exactly the bytes the host decided on.
     *
     * @param list<array<string,mixed>> $lockPlan
     */
    private static function lockArgument(array $lockPlan): string {
        return self::CODE_LOCK_ARGUMENT . '=' . base64_encode(\Duo\Canon::encode($lockPlan));
    }

    /** @return list<string> */
    public static function render(array $proposal): array {
        $lines = [];
        $state = $proposal['state'] ?? [];
        $code = $proposal['code'] ?? [];
        $env = $proposal['environment'] ?? [];
        $media = $state['media'] ?? [];
        $lines[] = 'Duo initialization proposal ' . ($proposal['digest'] ?? '(missing digest)');
        $lines[] = '  WordPress ' . ($env['wordpress'] ?? '?') . ' / PHP ' . ($env['php'] ?? '?')
            . ' / database ' . ($env['database']['server'] ?? '?');
        $lines[] = '  URL: ' . ($env['home'] ?? '(unknown)');
        $lines[] = '  code: ' . ($code['management'] ?? 'unknown')
            . ' (separate ' . (int) ($code['files'] ?? 0) . '-file / ' . (int) ($code['bytes'] ?? 0)
            . '-byte payload; source ' . ($code['source_revision'] ?? '?') . ')';
        foreach (($code['roots'] ?? []) as $kind => $path) {
            $lines[] = "    $kind root: " . ($path ?? '(unavailable)');
        }
        foreach (($code['active_plugins'] ?? []) as $plugin) {
            $lines[] = '    active plugin: ' . ($plugin['basename'] ?? '?') . ' ' . ($plugin['version'] ?? '(unknown version)');
        }
        $theme = $code['active_theme'] ?? [];
        $lines[] = '    active theme: stylesheet=' . ($theme['stylesheet'] ?? '?') . ', template=' . ($theme['template'] ?? '?');
        foreach (self::renderSplit($code) as $line) {
            $lines[] = $line;
        }
        $lines[] = '  state: ' . ($state['repository'] ?? '?') . ' (site.duo.json + canonical capture baseline)';
        $lines[] = '  Git: ' . ($state['git']['mode'] ?? 'unknown') . ' (' . ($state['git']['version'] ?? 'unknown') . ')';
        $adapterNames = array_map(static fn(array $row): string => (string) ($row['name'] ?? '?'), $state['adapters'] ?? []);
        $lines[] = '  adapters: ' . ($adapterNames ? implode(', ', $adapterNames) : '(none)');
        $lines[] = '  media: ' . ($media['strategy'] ?? 'unknown') . ' (' . (int) ($media['attachments'] ?? 0)
            . ' attachment(s), ' . (int) ($media['unavailable'] ?? 0) . ' unavailable)';
        $risk = $state['risk_surfaces'] ?? [];
        $lines[] = '  redacted risk surfaces: ' . array_sum((array) ($risk['options'] ?? []))
            . ' secret-shaped option value(s), ' . array_sum((array) ($risk['user_meta'] ?? []))
            . ' PII-shaped user-meta value(s); values are never included';
        $oversized = (array) ($risk['oversized'] ?? []);
        if (array_sum($oversized) > 0) {
            $lines[] = '    ' . (int) ($oversized['options'] ?? 0) . ' oversized option value(s) and '
                . (int) ($oversized['user_meta'] ?? 0)
                . ' oversized user-meta value(s) were not scanned; values are never included';
        }
        if (!empty($risk['truncated'])) {
            $scanned = (array) ($risk['scanned'] ?? []);
            $limits = (array) ($risk['limits'] ?? []);
            $lines[] = '    risk discovery reached its bounded scan limit after scanning '
                . (int) ($scanned['options'] ?? 0) . ' option value(s) and '
                . (int) ($scanned['user_meta'] ?? 0) . ' user-meta value(s)'
                . ' (maximum ' . (int) ($limits['rows_per_surface'] ?? 0) . ' rows / '
                . (int) ($limits['bytes_per_surface'] ?? 0) . ' bytes per surface); '
                . 'these redacted counts are incomplete';
        }
        foreach (($proposal['unsupported'] ?? []) as $row) {
            $lines[] = '  UNSUPPORTED ' . strtoupper((string) ($row['kind'] ?? 'capability')) . ' '
                . ($row['extension'] ?? '?') . ' [' . ($row['code'] ?? 'unsupported') . ']: '
                . ($row['reason'] ?? 'unsupported') . '. ' . ($row['remediation'] ?? '');
        }
        $advisories = (array) ($proposal['advisories'] ?? []);
        if ($advisories !== []) {
            // A heading, because T6 §3.4 moves a row that used to BLOCK into
            // this block. Without it an operator who passed
            // --allow-unmanaged-plugins reads their unmanaged plugins in the
            // same undifferentiated run of lines as everything else and
            // cannot tell that the flag did anything.
            $lines[] = '  advisories (init proceeds; each is a stated, accepted limit on what Duo versions):';
        }
        foreach ($advisories as $row) {
            $lines[] = '  ' . self::advisoryLine($row);
            $detail = trim((string) ($row['reason'] ?? '') . ' ' . (string) ($row['remediation'] ?? ''));
            if ($detail !== '' && ($row['code'] ?? null) === self::UNMANAGED_PLUGIN_CODE) {
                $lines[] = '    ' . $detail;
            }
        }
        $lines[] = !empty($proposal['ready'])
            ? '  result: ready for explicit confirmation'
            : '  result: blocked; no configuration, state, identity, or ledger mutation was made';
        return $lines;
    }

    /**
     * The per-component code split (DUO-3499).
     *
     * Every component is named with its classification and the REASON for it,
     * because the interesting case is the one that did not lock: a plugin
     * whose installed tree does not hash-match its own published release is a
     * fact about this site the operator should read before confirming, not a
     * silent omission from a shorter list.
     *
     * @param array<string,mixed> $code
     * @return list<string>
     */
    private static function renderSplit(array $code): array {
        $split = (array) ($code['split'] ?? []);
        if ($split === []) {
            return [];
        }
        $locked = array_values(array_filter(
            $split,
            static fn($row): bool => is_array($row) && ($row['classification'] ?? null) === 'locked'
        ));
        $lines = [
            '    code split: ' . count($locked) . ' locked, ' . (count($split) - count($locked)) . ' vendored'
                . ($locked === []
                    ? ' (no component could be verified against a release; the whole payload stays in Git)'
                    : ' (locked components are declared in ' . self::DECLARATION_SPLIT['lock']
                        . ' and are not carried in Git)'),
        ];
        foreach ($split as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '      ' . strtoupper((string) ($row['classification'] ?? 'vendored')) . ' '
                . ($row['root'] ?? '?') . '/' . ($row['component'] ?? '?') . ' '
                . (($row['version'] ?? '') !== '' ? $row['version'] : '(no version header)')
                . ' — ' . ($row['reason'] ?? '');
        }
        return $lines;
    }

    /**
     * One advisory row's headline.
     *
     * @param array<string,mixed> $row
     */
    private static function advisoryLine(array $row): string {
        $code = (string) ($row['code'] ?? 'advisory');
        $extension = (string) ($row['extension'] ?? '?');
        if ($code === self::UNMANAGED_PLUGIN_CODE) {
            return 'UNMANAGED PLUGIN ' . $extension . ' [' . $code . ']';
        }
        if ($code === 'unmanaged_scope_left_local') {
            // The unmanaged-plugin decision carried through to a registered
            // type with rows: `UNMANAGED SCOPE post_type:wpforms` reads as the
            // sentence it is, beside its plugin's own line.
            return 'UNMANAGED SCOPE ' . $extension . ' [' . $code . ']: '
                . ($row['reason'] ?? '') . '. ' . ($row['remediation'] ?? '');
        }

        return 'ADVISORY ' . strtoupper((string) ($row['kind'] ?? 'coverage')) . ' '
            . $extension . ' [' . $code . ']: '
            . ($row['reason'] ?? 'coverage is incomplete') . '. ' . ($row['remediation'] ?? '');
    }

    /**
     * @param bool $hasLock the repository published a code lock, so its locked
     *        components are on disk but deliberately NOT in Git (DUO-3499)
     * @return list<string>
     */
    public static function nextSteps(string $env, string $repo, bool $hasLock = false): array {
        $gitRepo = escapeshellarg($repo);
        $envArg = escapeshellarg($env);
        $addStep = $hasLock
            // Not a bare `git add code`: with a lock, that command adds only
            // the vendored half, and an operator reading the old line would
            // reasonably believe the clone in step 5 is complete. It is not --
            // it needs the materialization step before it can compile.
            ? "  1. git -C $gitRepo add .gitignore site.duo.json code state media && git -C $gitRepo commit -m \"duo: initial code and state baselines\""
                . ' # the locked components are ignored by design; code/duo-code.lock.json declares them'
            : "  1. git -C $gitRepo add .gitignore site.duo.json code state media && git -C $gitRepo commit -m \"duo: initial code and state baselines\"";
        $steps = [
            'Managed state scope is clean. Coverage outside the selected adapters remains advisory, not a whole-site guarantee.',
            "The Git worktree is ready at target path $repo.",
            'Publish it, then make an ordinary developer checkout. Confirm the target worktree is on the intended named branch (not detached), and replace the quoted YOUR_* values before running these commands:',
            $addStep,
            "  2. git -C $gitRepo remote add origin 'YOUR_GIT_URL' # skip if origin already exists",
            "  3. TARGET_BRANCH=\$(git -C $gitRepo symbolic-ref --quiet --short HEAD) && test -n \"\$TARGET_BRANCH\" || { echo 'target worktree is detached; switch to the intended branch first' >&2; exit 1; }",
            "  4. git -C $gitRepo push -u origin \"HEAD:refs/heads/\$TARGET_BRANCH\"",
            "  5. git clone --branch \"\$TARGET_BRANCH\" 'YOUR_GIT_URL' 'YOUR_WORKSPACE' # exact initialized baseline",
            "  6. export DUO_CLI='YOUR_DUO_CLI'                   # absolute path to an installed Duo cli/duo; it is not in the site repo",
            "  7. git -C 'YOUR_WORKSPACE' switch -c 'YOUR_BRANCH' # feature branch",
            "Do not assume that checkout is the live environment: Duo commands for $envArg always operate on its configured repo_path ($repo), never on 'YOUR_WORKSPACE'.",
            "In 'YOUR_WORKSPACE', recreate the complete machine-local connection for $envArg in the untracked .duo-envs.json overlay, then run `\"\$DUO_CLI\" envs` and verify that it names the intended target and repo_path.",
            "Point or materialize that target environment to 'YOUR_BRANCH' before capturing WordPress-authored changes. Then, from 'YOUR_WORKSPACE':",
            "  \"\$DUO_CLI\" capture $envArg                   # capture authored state from the configured target",
            "  \"\$DUO_CLI\" plan $envArg                      # preview",
            "  \"\$DUO_CLI\" promote $envArg                   # promote with a DB checkpoint",
            '  follow the exact checkpoint receipt on failure     # rollback',
            'Executable code remains a separate content-addressed half under code/wp-content; review its descriptor and ownership boundary independently from state.',
        ];
        if ($hasLock) {
            $steps[] = 'This repository declares a code lock (code/duo-code.lock.json): the components it names are on disk '
                . 'at the target but are NOT in Git, so a fresh clone carries neither their bytes nor a way to compile. '
                . 'Run the materialization step documented in docs/guides/code-updates.md in \'YOUR_WORKSPACE\' before '
                . 'the first compile; until then Duo refuses with code_component_unresolved and names the component.';
        }
        return $steps;
    }

    /** @return array<string,mixed> */
    private static function request(EnvironmentDriver $transport, array $args, string $phase): array {
        $result = $transport->captureWp($args);
        if ($result['exit'] !== 0) {
            $label = "duo init $phase failed for '{$transport->name()}' (exit {$result['exit']})";
            // DUO-3421: the agent answers a refusal with the common envelope on
            // STDOUT. The stderr-first rule below is right for a transport
            // whose stderr carries the target's own words, but a docker
            // transport's stderr is never empty — `docker compose run` writes
            // "Container ... Creating/Created" progress there on every single
            // invocation — so the operator was handed compose progress noise
            // and the refusal's reason code, remediation, and redaction
            // witness were dropped on the floor. Observed live on the DUO-3421
            // init evidence run: an adapter-boundary refusal surfaced as
            // "(exit 1): Container duo-...-cli1-run-... Creating". Decode
            // first, exactly as cmd_status()/fetch_pending() do (DUO-3399);
            // anything that is not a v1 envelope still takes the original
            // stderr-else-stdout path unchanged.
            $refusal = json_decode(trim($result['stdout']), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'duo-command-refusal/v1') {
                throw new InitRefusalException($label, $refusal);
            }
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new \RuntimeException($label . ($detail !== '' ? ": $detail" : ''));
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("duo init $phase returned invalid JSON for '{$transport->name()}'");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $proposal */
    private static function assertProposal(EnvironmentDriver $transport, array $proposal): void {
        $environment = $proposal['environment'] ?? null;
        $code = $proposal['code'] ?? null;
        $state = $proposal['state'] ?? null;
        $unsupported = $proposal['unsupported'] ?? null;
        $ready = $proposal['ready'] ?? null;
        $expectedRepo = self::expectedRepository($transport);
        $valid = ($proposal['format'] ?? null) === 'duo-init-plan/v1'
            && is_bool($ready)
            && is_string($proposal['digest'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $proposal['digest']) === 1
            && is_array($environment)
            && is_array($code)
            && is_array($state)
            && ($state['repository'] ?? null) === $expectedRepo
            && is_array($unsupported)
            && array_is_list($unsupported)
            && is_array($proposal['advisories'] ?? null)
            && array_is_list($proposal['advisories']);
        if ($valid) {
            $valid = $ready === ($unsupported === []);
        }
        if ($valid && $ready === true) {
            $database = $environment['database'] ?? null;
            $media = $state['media'] ?? null;
            $git = $state['git'] ?? null;
            $ledger = $state['ledger'] ?? null;
            $config = $state['config'] ?? null;
            $components = $code['components'] ?? null;
            $declaration = $code['declaration'] ?? null;
            $valid = is_string($environment['wordpress'] ?? null)
                && trim((string) $environment['wordpress']) !== ''
                && is_string($environment['php'] ?? null)
                && trim((string) $environment['php']) !== ''
                && is_string($environment['home'] ?? null)
                && is_array($database)
                && ($database['access'] ?? null) === 'verified-read'
                && is_string($database['server'] ?? null)
                && trim((string) $database['server']) !== ''
                && ($code['management'] ?? null) === 'managed-baseline-proposed'
                && is_int($code['files'] ?? null) && $code['files'] >= 0
                && is_int($code['bytes'] ?? null) && $code['bytes'] >= 0
                && is_string($code['source_revision'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', (string) $code['source_revision']) === 1
                && self::validCodeRoots($code['roots'] ?? null)
                && self::validComponents($components)
                && is_array($declaration)
                // DUO-3499: exactly one of the two legal declarations. Format 2
                // is accepted only when the split actually locked something --
                // a repository that declares a lock and locks nothing would
                // publish an empty lock file and gain a compile gate for no
                // reason.
                && in_array($declaration, [self::DECLARATION_FULL, self::DECLARATION_SPLIT], true)
                && self::validSplit($code['split'] ?? null, $declaration === self::DECLARATION_SPLIT)
                && self::validComponentInventory($code['component_inventory'] ?? null)
                && self::listOfArrays($code['active_plugins'] ?? null)
                && is_array($code['active_theme'] ?? null)
                && is_string($code['active_theme']['stylesheet'] ?? null)
                && is_string($code['active_theme']['template'] ?? null)
                && ($state['baseline'] ?? null) === 'capture-consistent-snapshot'
                && in_array($state['existing_config'] ?? null, ['absent', 'adoption-seed'], true)
                && is_string($state['config_identity'] ?? null)
                && (($state['existing_config'] === 'absent' && $state['config_identity'] === 'absent')
                    || ($state['existing_config'] === 'adoption-seed'
                        && preg_match('/^sha256:[a-f0-9]{64}$/', $state['config_identity']) === 1))
                && is_string($state['repository_identity'] ?? null)
                && preg_match('/^sha256:[a-f0-9]{64}$/', (string) $state['repository_identity']) === 1
                && is_array($config)
                && ($config['code'] ?? null) === $declaration
                && self::listOfArrays($config['manifests'] ?? null)
                && is_array($config['policy'] ?? null)
                && ($config['spec_version'] ?? null) === 2
                && self::listOfArrays($state['adapters'] ?? null)
                && is_array($media)
                && is_string($media['strategy'] ?? null)
                && is_int($media['attachments'] ?? null) && $media['attachments'] >= 0
                && is_int($media['unavailable'] ?? null) && $media['unavailable'] >= 0
                && is_array($git)
                && in_array($git['mode'] ?? null, ['initialize-on-confirm', 'existing-worktree'], true)
                && is_string($git['version'] ?? null) && trim((string) $git['version']) !== ''
                && is_string($state['gitignore_identity'] ?? null)
                && (($state['gitignore_identity'] ?? null) === 'absent'
                    || preg_match('/^sha256:[a-f0-9]{64}$/', (string) $state['gitignore_identity']) === 1)
                && is_array($ledger)
                && ($ledger['rows'] ?? null) === 0
                && is_int($ledger['tables'] ?? null) && $ledger['tables'] >= 0
                && self::validRiskEnvelope($state['risk_surfaces'] ?? null);
        }
        if (!$valid) {
            throw new \RuntimeException(
                "duo init proposal returned an incompatible or incomplete contract for '{$transport->name()}'"
            );
        }
    }

    /** @param array<string,mixed> $result */
    private static function assertResult(EnvironmentDriver $transport, array $result, string $digest): void {
        $baseline = $result['baseline'] ?? null;
        $capture = $result['capture'] ?? null;
        $code = $result['code'] ?? null;
        $descriptor = is_array($code) ? ($code['descriptor'] ?? null) : null;
        $lifecycle = is_array($code) ? ($code['lifecycle'] ?? null) : null;
        $state = $result['state'] ?? null;
        $unsupported = $result['unsupported'] ?? null;
        $expectedRepo = self::expectedRepository($transport);
        $valid = ($result['format'] ?? null) === 'duo-init-result/v1'
            && ($result['proposal_digest'] ?? null) === $digest
            && is_array($baseline)
            && ($baseline['kind'] ?? null) === 'state-capture'
            && is_string($baseline['revision_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $baseline['revision_hash']) === 1
            && is_array($capture)
            && ($capture['revision_hash'] ?? null) === $baseline['revision_hash']
            && ($capture['initial_publication_cleanup'] ?? null) === 'clean'
            && is_array($code)
            && ($code['management'] ?? null) === 'managed-baseline'
            && ($code['source'] ?? null) === 'code/wp-content'
            && is_string($code['revision_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $code['revision_hash']) === 1
            && is_array($descriptor)
            && ($descriptor['code_revision'] ?? null) === $code['revision_hash']
            && is_array($lifecycle)
            && ($lifecycle['enabled'] ?? null) === true
            && ($lifecycle['completed'] ?? null) === true
            && ($lifecycle['code_revision'] ?? null) === $code['revision_hash']
            && ($capture['initial_code_baseline'] ?? null) === $lifecycle
            && is_array($state)
            && ($state['repository'] ?? null) === $expectedRepo
            && ($state['site_config'] ?? null) === $expectedRepo . '/site.duo.json'
            && ($state['git'] ?? null) === 'existing-worktree'
            && is_array($unsupported)
            && array_is_list($unsupported)
            && $unsupported === [];
        if (!$valid) {
            throw new \RuntimeException(
                "duo init confirmation returned an incompatible or incomplete result for '{$transport->name()}'"
            );
        }
    }

    private static function expectedRepository(EnvironmentDriver $transport): string {
        $repo = rtrim($transport->repoPath(), '/');
        if ($repo === '' || !str_starts_with($repo, '/')) {
            throw new \RuntimeException(
                "duo init requires an absolute, non-root repository path for '{$transport->name()}'"
            );
        }
        return $repo;
    }

    private static function validCodeRoots(mixed $roots): bool {
        if (!is_array($roots)) return false;
        foreach (['content', 'mu_plugins', 'plugins', 'themes'] as $name) {
            if (!is_string($roots[$name] ?? null) || trim((string) $roots[$name]) === '') return false;
        }
        return true;
    }

    private static function validComponents(mixed $components): bool {
        if (!is_array($components)) return false;
        foreach (['plugins', 'themes'] as $name) {
            if (!is_array($components[$name] ?? null) || !array_is_list($components[$name])) return false;
            foreach ($components[$name] as $component) {
                if (!is_string($component) || $component === '') return false;
            }
        }
        return true;
    }

    /**
     * The classification the operator is being asked to confirm (DUO-3499).
     *
     * Total over the components it names, single-line reasons, and a locked
     * entry that actually carries an origin: the host validates this even
     * though the target already did, because the host is what renders it for
     * review and must not render a shape it does not understand.
     */
    private static function validSplit(mixed $split, bool $requiresLocked): bool {
        if (!is_array($split) || !array_is_list($split)) {
            return false;
        }
        $locked = 0;
        $seen = [];
        foreach ($split as $row) {
            if (!is_array($row)
                || !in_array($row['classification'] ?? null, ['locked', 'vendored'], true)
                || !is_string($row['root'] ?? null)
                || !is_string($row['component'] ?? null)
                || !is_string($row['version'] ?? null)
                || !is_string($row['tree_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', (string) $row['tree_sha256']) !== 1
                || !is_string($row['reason'] ?? null)
                || trim((string) $row['reason']) === '') {
                return false;
            }
            $key = $row['root'] . '/' . $row['component'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            if ($row['classification'] === 'locked') {
                $locked++;
                if (!is_array($row['origin'] ?? null)) {
                    return false;
                }
            } elseif (array_key_exists('origin', $row)) {
                return false;
            }
        }
        return $requiresLocked ? $locked > 0 : true;
    }

    /** The read-only per-component identity the host classifies against. */
    private static function validComponentInventory(mixed $rows): bool {
        if (!is_array($rows) || !array_is_list($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['root'] ?? null)
                || !is_string($row['component'] ?? null)
                || !is_string($row['version'] ?? null)
                || !is_string($row['tree_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', (string) $row['tree_sha256']) !== 1
                || !is_int($row['files'] ?? null) || $row['files'] < 0
                || !is_int($row['bytes'] ?? null) || $row['bytes'] < 0) {
                return false;
            }
        }
        return true;
    }

    private static function listOfArrays(mixed $rows): bool {
        if (!is_array($rows) || !array_is_list($rows)) return false;
        foreach ($rows as $row) {
            if (!is_array($row)) return false;
        }
        return true;
    }

    private static function validRiskEnvelope(mixed $risk): bool {
        if (!is_array($risk)
            || !self::countMap($risk['options'] ?? null)
            || !self::countMap($risk['user_meta'] ?? null)
            || !is_bool($risk['truncated'] ?? null)) {
            return false;
        }
        foreach ([
            'oversized' => ['options', 'user_meta'],
            'scanned' => ['options', 'user_meta'],
            'limits' => ['rows_per_surface', 'bytes_per_surface'],
        ] as $bucket => $keys) {
            $values = $risk[$bucket] ?? null;
            if (!is_array($values)) return false;
            foreach ($keys as $key) {
                if (!is_int($values[$key] ?? null) || $values[$key] < 0) return false;
            }
        }
        return true;
    }

    private static function countMap(mixed $counts): bool {
        if (!is_array($counts)) return false;
        foreach ($counts as $label => $count) {
            if (!is_string($label) || !is_int($count) || $count < 0) return false;
        }
        return true;
    }
}
