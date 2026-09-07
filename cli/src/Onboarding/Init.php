<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
// The engine's OWN wire-version window (`agent/src/Kernel/SpecVersionWindow.php:47`),
// required here for the same reason Canon is: the host judges a document the
// agent wrote, so it has to judge it by the agent's rule rather than a second
// copy of it. WP-4.12's flip proved the copy is the defect — see
// acceptedSpecVersions() below.
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/SpecVersionWindow.php';

/**
 * An init phase the target answered with the common v1 refusal envelope.
 *
 * issue #3421: the envelope is a STRUCTURED public answer on stdout, and the host
 * renders it through render_command_refusal_human() exactly as cmd_status()
 * and fetch_pending() do (issue #3399). Carrying it on a typed exception rather
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
     * The one advisory code that names a whole body of state WPrism will not
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
     * calls for the same reason ALLOW_UNMANAGED_PLUGINS is (issue #3499).
     *
     * The classification is a decision — it changes what the repository
     * declares and what Git carries — so it lives inside the proposal digest.
     * A confirmation that omitted it would ask the target to re-plan an
     * unclassified repository and fail the digest bind, which is the right
     * failure but a confusing one to read.
     */
    public const CODE_LOCK_ARGUMENT = '--code-lock-b64';

    /** The two code declarations a proposal may carry. */
    private const DECLARATION_FULL = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
    private const DECLARATION_SPLIT = [
        'format' => 2,
        'layout' => 'wp-content',
        'lock' => 'code/wprism-code.lock.json',
        'source' => 'code/wp-content',
    ];

    /** @param ?list<array<string,mixed>> $lockPlan @return array<string,mixed> */
    public static function proposal(
        EnvironmentDriver $transport,
        bool $allowUnmanagedPlugins = false,
        ?array $lockPlan = null
    ): array {
        $args = ['wprism', 'init', '--repo=' . $transport->repoPath(), '--format=json'];
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
            throw new \RuntimeException('wprism init confirmation requires the exact 64-hex proposal digest');
        }
        $args = [
            'wprism', 'init', '--repo=' . $transport->repoPath(),
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

    /** @return array<string,mixed> */
    public static function archiveInterrupted(EnvironmentDriver $transport, string $archive): array {
        $result = self::request($transport, [
            'wprism', 'init', '--repo=' . $transport->repoPath(),
            '--archive-interrupted-to=' . $archive, '--format=json',
        ], 'interrupted archive');
        $keys = array_keys($result);
        sort($keys, SORT_STRING);
        if ($keys !== ['archive', 'attempt_sha256', 'format', 'receipt_sha256', 'repository', 'resumed']
            || ($result['format'] ?? null) !== 'wprism-init-interrupted-archive/v1'
            || ($result['archive'] ?? null) !== $archive
            || ($result['repository'] ?? null) !== $transport->repoPath()
            || !is_bool($result['resumed'] ?? null)) {
            throw new \RuntimeException('wprism init interrupted archive returned an unbound receipt');
        }
        foreach (['attempt_sha256', 'receipt_sha256'] as $field) {
            if (!is_string($result[$field] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $result[$field]) !== 1) {
                throw new \RuntimeException("wprism init interrupted archive returned a malformed $field");
            }
        }

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
        return self::CODE_LOCK_ARGUMENT . '=' . base64_encode(\WPrism\Canon::encode($lockPlan));
    }

    /** @return list<string> */
    public static function render(array $proposal): array {
        $lines = [];
        $state = $proposal['state'] ?? [];
        $code = $proposal['code'] ?? [];
        $env = $proposal['environment'] ?? [];
        $media = $state['media'] ?? [];
        $lines[] = 'WPrism initialization proposal ' . ($proposal['digest'] ?? '(missing digest)');
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
        $lines[] = '  state: ' . ($state['repository'] ?? '?') . ' (site.wprism.json + canonical capture baseline)';
        $lines[] = '  Git: ' . ($state['git']['mode'] ?? 'unknown') . ' (' . ($state['git']['version'] ?? 'unknown') . ')';
        $lines[] = '  Git LFS: ' . (($state['git_lfs']['required'] ?? false) ? 'required' : 'prepared')
            . ' for media/** (' . ($state['git_lfs']['version'] ?? 'unknown') . ')';
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
            $lines[] = '  advisories (init proceeds; each is a stated, accepted limit on what WPrism versions):';
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
     * The per-component code classification (issue #3499, then the
     * no-third-party-bytes invariant).
     *
     * Every component is named with its classification and the REASON for it,
     * because the interesting case is the one that did not lock: a plugin
     * whose installed tree does not hash-match its own published release and
     * was not imported is a fact about this site the operator has to act on —
     * it is the row that blocks the proposal — not a silent omission from a
     * shorter list.
     *
     * @param array<string,mixed> $code
     * @return list<string>
     */
    private static function renderSplit(array $code): array {
        $split = (array) ($code['split'] ?? []);
        if ($split === []) {
            return [];
        }
        $counts = ['locked' => 0, 'first-party' => 0, 'unsourced' => 0];
        foreach ($split as $row) {
            $classification = is_array($row) ? (string) ($row['classification'] ?? 'unsourced') : 'unsourced';
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
        }
        $lines = [
            '    code classification: ' . $counts['locked'] . ' locked, ' . $counts['first-party'] . ' first-party, '
                . $counts['unsourced'] . ' unsourced (locked components are declared in '
                . self::DECLARATION_SPLIT['lock'] . ' and are not carried in Git; first-party ones are carried by '
                . 'declaration; an unsourced one blocks)',
        ];
        foreach ($split as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = '      ' . strtoupper((string) ($row['classification'] ?? 'unsourced')) . ' '
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
        if ($code === 'unmanaged_widget_left_local') {
            return 'UNMANAGED WIDGET ' . $extension . ' [' . $code . ']: '
                . ($row['reason'] ?? '') . '. ' . ($row['remediation'] ?? '');
        }

        return 'ADVISORY ' . strtoupper((string) ($row['kind'] ?? 'coverage')) . ' '
            . $extension . ' [' . $code . ']: '
            . ($row['reason'] ?? 'coverage is incomplete') . '. ' . ($row['remediation'] ?? '');
    }

    /**
     * @param bool $hasLock the repository published a code lock, so its locked
     *        components are on disk but deliberately NOT in Git (issue #3499)
     * @return list<string>
     */
    public static function nextSteps(string $env, string $repo, bool $hasLock = false): array {
        $gitRepo = escapeshellarg($repo);
        $envArg = escapeshellarg($env);
        $addStep = $hasLock
            // Not a bare `git add code`: with locked components, that command
            // adds only the first-party half, and an operator reading the old
            // line would reasonably believe the clone in step 5 is complete.
            // It is not -- it needs `wprism code-resolve` before it can compile.
            ? "  1. git -C $gitRepo add .gitattributes .gitignore site.wprism.json code state media && git -C $gitRepo commit -m \"wprism: initial code and state baselines\""
                . ' # the locked components are ignored by design; code/wprism-code.lock.json declares them'
            : "  1. git -C $gitRepo add .gitattributes .gitignore site.wprism.json code state media && git -C $gitRepo commit -m \"wprism: initial code and state baselines\"";
        $steps = [
            'Managed state scope is clean. Coverage outside the selected adapters remains advisory, not a whole-site guarantee.',
            "The Git worktree is ready at target path $repo.",
            'The repository routes media/** through Git LFS; install Git LFS in every developer clone before checkout or pull.',
            'Publish it, then make an ordinary developer checkout. Confirm the target worktree is on the intended named branch (not detached), and replace the quoted YOUR_* values before running these commands:',
            $addStep,
            "  2. git -C $gitRepo remote add origin 'YOUR_GIT_URL' # skip if origin already exists",
            "  3. TARGET_BRANCH=\$(git -C $gitRepo symbolic-ref --quiet --short HEAD) && test -n \"\$TARGET_BRANCH\" || { echo 'target worktree is detached; switch to the intended branch first' >&2; exit 1; }",
            "  4. git -C $gitRepo push -u origin \"HEAD:refs/heads/\$TARGET_BRANCH\"",
            "  5. git clone --branch \"\$TARGET_BRANCH\" 'YOUR_GIT_URL' 'YOUR_WORKSPACE' # exact initialized baseline",
            "  6. export WPRISM_CLI='YOUR_WPRISM_CLI'                   # absolute path to an installed WPrism cli/wprism; it is not in the site repo",
            "  7. git -C 'YOUR_WORKSPACE' switch -c 'YOUR_BRANCH' # feature branch",
            "Do not assume that checkout is the live environment: WPrism commands for $envArg always operate on its configured repo_path ($repo), never on 'YOUR_WORKSPACE'.",
            "In 'YOUR_WORKSPACE', recreate the complete machine-local connection for $envArg in the untracked .wprism-envs.json overlay, then run `\"\$WPRISM_CLI\" envs` and verify that it names the intended target and repo_path.",
            "Point or materialize that target environment to 'YOUR_BRANCH' before capturing WordPress-authored changes. Then, from 'YOUR_WORKSPACE':",
            "  \"\$WPRISM_CLI\" capture $envArg                   # capture authored state from the configured target",
            "  \"\$WPRISM_CLI\" plan $envArg                      # preview",
            "  \"\$WPRISM_CLI\" promote $envArg                   # promote with a DB checkpoint",
            '  follow the exact checkpoint receipt on failure     # rollback',
            'Executable code remains a separate content-addressed half under code/wp-content; review its descriptor and ownership boundary independently from state.',
        ];
        if ($hasLock) {
            $steps[] = 'This repository locks third-party components (code/wprism-code.lock.json): they are on disk '
                . 'at the target but are NOT in Git, so a fresh clone carries neither their bytes nor a way to compile. '
                . 'Run `wprism code-resolve` (documented in docs/guides/code-updates.md) in \'YOUR_WORKSPACE\' before '
                . 'the first compile; until then WPrism refuses with code_component_unresolved and names the component. '
                . 'An imported-archive component resolves only on a host where `wprism code-import` stored its archive.';
        }
        return $steps;
    }

    /** @return array<string,mixed> */
    private static function request(EnvironmentDriver $transport, array $args, string $phase): array {
        $result = $transport->captureWp($args);
        if ($result['exit'] !== 0) {
            $label = "wprism init $phase failed for '{$transport->name()}' (exit {$result['exit']})";
            // issue #3421: the agent answers a refusal with the common envelope on
            // STDOUT. The stderr-first rule below is right for a transport
            // whose stderr carries the target's own words, but a docker
            // transport's stderr is never empty — `docker compose run` writes
            // "Container ... Creating/Created" progress there on every single
            // invocation — so the operator was handed compose progress noise
            // and the refusal's reason code, remediation, and redaction
            // witness were dropped on the floor. Observed live on the issue #3421
            // init evidence run: an adapter-boundary refusal surfaced as
            // "(exit 1): Container wprism-...-cli1-run-... Creating". Decode
            // first, exactly as cmd_status()/fetch_pending() do (issue #3399);
            // anything that is not a v1 envelope still takes the original
            // stderr-else-stdout path unchanged.
            $refusal = json_decode(trim($result['stdout']), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'wprism-command-refusal/v1') {
                throw new InitRefusalException($label, $refusal);
            }
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new \RuntimeException($label . ($detail !== '' ? ": $detail" : ''));
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("wprism init $phase returned invalid JSON for '{$transport->name()}'");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $proposal */
    private static function assertProposal(EnvironmentDriver $transport, array $proposal): void {
        $field = self::proposalFailure($transport, $proposal);
        if ($field !== null) {
            // The refusal NAMES the offender. It used to be one ~45-clause
            // boolean that said only that something in the contract was wrong,
            // so the operator's only route to the answer was diffing the
            // agent's JSON against this predicate by hand — which is literally
            // what the WPForms recon author had to do to find
            // `state.config.spec_version` below. Every other refusal in this
            // tree names its blocker; this one now does too.
            throw new \RuntimeException(
                "wprism init proposal returned an incompatible or incomplete contract for '{$transport->name()}': "
                    . $field
            );
        }
    }

    /**
     * The proposal contract as ORDERED, NAMED field checks; the first field
     * that fails, or null when the whole contract holds.
     *
     * The clauses and their order are the boolean chain this replaced. Each
     * one is a closure so the chain still short-circuits: a later
     * `$database['access']` read is reached only when its own `is_array()`
     * answered true, exactly as `&&` guaranteed before.
     *
     * @param array<string,mixed> $proposal
     */
    private static function proposalFailure(EnvironmentDriver $transport, array $proposal): ?string {
        $environment = $proposal['environment'] ?? null;
        $code = $proposal['code'] ?? null;
        $state = $proposal['state'] ?? null;
        $unsupported = $proposal['unsupported'] ?? null;
        $ready = $proposal['ready'] ?? null;
        $expectedRepo = self::expectedRepository($transport);
        $field = self::firstFailure([
            'format' => static fn(): bool => ($proposal['format'] ?? null) === 'wprism-init-plan/v1',
            'ready' => static fn(): bool => is_bool($ready),
            'digest' => static fn(): bool => is_string($proposal['digest'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', (string) $proposal['digest']) === 1,
            'environment' => static fn(): bool => is_array($environment),
            'code' => static fn(): bool => is_array($code),
            'state' => static fn(): bool => is_array($state),
            'state.repository' => static fn(): bool => ($state['repository'] ?? null) === $expectedRepo,
            'unsupported' => static fn(): bool => is_array($unsupported) && array_is_list($unsupported),
            'advisories' => static fn(): bool => is_array($proposal['advisories'] ?? null)
                && array_is_list($proposal['advisories']),
            'ready/unsupported' => static fn(): bool => $ready === ($unsupported === []),
        ]);
        if ($field !== null || $ready !== true) {
            return $field;
        }

        $database = $environment['database'] ?? null;
        $media = $state['media'] ?? null;
        $git = $state['git'] ?? null;
        $gitLfs = $state['git_lfs'] ?? null;
        $ledger = $state['ledger'] ?? null;
        $config = $state['config'] ?? null;
        $components = $code['components'] ?? null;
        $declaration = $code['declaration'] ?? null;
        $accepted = self::acceptedSpecVersions();

        return self::firstFailure([
            'environment.wordpress' => static fn(): bool => is_string($environment['wordpress'] ?? null)
                && trim((string) $environment['wordpress']) !== '',
            'environment.php' => static fn(): bool => is_string($environment['php'] ?? null)
                && trim((string) $environment['php']) !== '',
            'environment.home' => static fn(): bool => is_string($environment['home'] ?? null),
            'environment.database' => static fn(): bool => is_array($database),
            'environment.database.access' => static fn(): bool => ($database['access'] ?? null) === 'verified-read',
            'environment.database.server' => static fn(): bool => is_string($database['server'] ?? null)
                && trim((string) $database['server']) !== '',
            'code.management' => static fn(): bool => ($code['management'] ?? null) === 'managed-baseline-proposed',
            'code.files' => static fn(): bool => is_int($code['files'] ?? null) && $code['files'] >= 0,
            'code.bytes' => static fn(): bool => is_int($code['bytes'] ?? null) && $code['bytes'] >= 0,
            'code.source_revision' => static fn(): bool => is_string($code['source_revision'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/', (string) $code['source_revision']) === 1,
            'code.roots' => static fn(): bool => self::validCodeRoots($code['roots'] ?? null),
            'code.components' => static fn(): bool => self::validComponents($components),
            // Exactly one of the two legal declarations, and it follows
            // from the classification: format 2 (the lock, even with zero
            // locked components, because its first_party list is what
            // the compile gate reads) whenever the host classified
            // anything; format 1 only for a site with no lockable
            // component, where there is nothing to declare.
            'code.declaration' => static fn(): bool => is_array($declaration)
                && in_array($declaration, [self::DECLARATION_FULL, self::DECLARATION_SPLIT], true),
            'code.split' => static fn(): bool => self::validSplit(
                $code['split'] ?? null,
                $declaration === self::DECLARATION_SPLIT
            ),
            'code.component_inventory' => static fn(): bool => self::validComponentInventory(
                $code['component_inventory'] ?? null
            ),
            'code.active_plugins' => static fn(): bool => self::listOfArrays($code['active_plugins'] ?? null),
            'code.active_theme' => static fn(): bool => is_array($code['active_theme'] ?? null),
            'code.active_theme.stylesheet' => static fn(): bool => is_string($code['active_theme']['stylesheet'] ?? null),
            'code.active_theme.template' => static fn(): bool => is_string($code['active_theme']['template'] ?? null),
            'state.baseline' => static fn(): bool => ($state['baseline'] ?? null) === 'capture-consistent-snapshot',
            'state.existing_config' => static fn(): bool => in_array(
                $state['existing_config'] ?? null,
                ['absent', 'adoption-seed'],
                true
            ),
            'state.config_identity' => static fn(): bool => is_string($state['config_identity'] ?? null)
                && ((($state['existing_config'] ?? null) === 'absent' && $state['config_identity'] === 'absent')
                    || (($state['existing_config'] ?? null) === 'adoption-seed'
                        && preg_match('/^sha256:[a-f0-9]{64}$/', $state['config_identity']) === 1)),
            'state.repository_identity' => static fn(): bool => is_string($state['repository_identity'] ?? null)
                && preg_match('/^sha256:[a-f0-9]{64}$/', (string) $state['repository_identity']) === 1,
            'state.config' => static fn(): bool => is_array($config),
            'state.config.code' => static fn(): bool => ($config['code'] ?? null) === $declaration,
            'state.config.manifests' => static fn(): bool => self::listOfArrays($config['manifests'] ?? null),
            'state.config.policy' => static fn(): bool => is_array($config['policy'] ?? null),
            'state.config.spec_version' => static fn(): bool => in_array(
                $config['spec_version'] ?? null,
                $accepted,
                true
            ),
            'state.adapters' => static fn(): bool => self::listOfArrays($state['adapters'] ?? null),
            'state.media' => static fn(): bool => is_array($media),
            'state.media.strategy' => static fn(): bool => is_string($media['strategy'] ?? null),
            'state.media.attachments' => static fn(): bool => is_int($media['attachments'] ?? null)
                && $media['attachments'] >= 0,
            'state.media.unavailable' => static fn(): bool => is_int($media['unavailable'] ?? null)
                && $media['unavailable'] >= 0,
            'state.git' => static fn(): bool => is_array($git),
            'state.git.mode' => static fn(): bool => in_array(
                $git['mode'] ?? null,
                ['initialize-on-confirm', 'existing-worktree'],
                true
            ),
            'state.git.version' => static fn(): bool => is_string($git['version'] ?? null)
                && trim((string) $git['version']) !== '',
            'state.git_lfs' => static fn(): bool => is_array($gitLfs),
            'state.git_lfs.required' => static fn(): bool => is_bool($gitLfs['required'] ?? null)
                && $gitLfs['required'] === (($media['attachments'] ?? 0) > 0),
            'state.git_lfs.version' => static fn(): bool => is_string($gitLfs['version'] ?? null)
                && trim((string) $gitLfs['version']) !== '',
            'state.git_lfs.config_identity' => static fn(): bool => is_string($gitLfs['config_identity'] ?? null)
                && (($gitLfs['config_identity'] ?? null) === 'initialize-on-confirm'
                    || preg_match('/^sha256:[a-f0-9]{64}$/', (string) $gitLfs['config_identity']) === 1),
            'state.gitattributes_identity' => static fn(): bool => is_string(
                $state['gitattributes_identity'] ?? null
            ) && (($state['gitattributes_identity'] ?? null) === 'absent'
                || preg_match('/^sha256:[a-f0-9]{64}$/', (string) $state['gitattributes_identity']) === 1),
            'state.gitignore_identity' => static fn(): bool => is_string($state['gitignore_identity'] ?? null)
                && (($state['gitignore_identity'] ?? null) === 'absent'
                    || preg_match('/^sha256:[a-f0-9]{64}$/', (string) $state['gitignore_identity']) === 1),
            'state.ledger' => static fn(): bool => is_array($ledger),
            'state.ledger.rows' => static fn(): bool => ($ledger['rows'] ?? null) === 0,
            'state.ledger.tables' => static fn(): bool => is_int($ledger['tables'] ?? null) && $ledger['tables'] >= 0,
            'state.risk_surfaces' => static fn(): bool => self::validRiskEnvelope($state['risk_surfaces'] ?? null),
        ]);
    }

    /**
     * @param array<string,callable():bool> $checks
     * @return ?string the first field whose check answered false
     */
    private static function firstFailure(array $checks): ?string {
        foreach ($checks as $field => $check) {
            if (!$check()) {
                return (string) $field;
            }
        }
        return null;
    }

    /**
     * The `spec_version` integers to accept in a proposal — the AGENT's own
     * acceptance window, computed by the agent's own definition of it.
     *
     * This clause used to be the literal `=== 2`, written when
     * `WPRISM_SPEC_VERSION` had never moved. WP-4.12 moved it to 3 and stamped
     * the new value into `InitPlanner::plan()`'s proposed config
     * (`agent/src/Init/InitPlanner.php:357`) and into `Adopt::SEED`
     * (`cli/src/Onboarding/Adopt.php:35`) — but not here, so from that commit
     * every `wprism init <env>` that reached a READY proposal died on this line
     * (measured against the shipped engine on a live pair, three ways: bare,
     * `--offline` and `--first-party=`). A BLOCKED proposal never reaches it,
     * which is why the corpus stayed green: `wprism init` appeared to work right
     * up to the moment it would have done something.
     *
     * The remedy is not a literal `3`. A host talks to whatever agent the
     * target has installed, and the engine itself judges a repository's
     * `spec_version` against the {N-1, N} window rather than by equality
     * (`SpecVersionWindow`, and `RepositoryCompiler` through it) — so the host
     * accepts exactly what the agent would, computed from the agent's own
     * source, and the next flag day has nothing to remember here.
     *
     * @return list<int>
     */
    private static function acceptedSpecVersions(): array {
        if (!defined('WPRISM_SPEC_VERSION')) {
            // The same resolution `AdapterDraft::boot()` (`:482-487`) and
            // `AdapterCatalog` already do: the host has the agent tree it
            // adopts from, and its defines are the engine's own statement of
            // the version. Parsed rather than required, because `agent/wprism.php`
            // is a WordPress drop-in that bootstraps 99 files on load.
            $agent = dirname(__DIR__, 3) . '/agent/wprism.php';
            $source = is_file($agent) ? (string) file_get_contents($agent) : '';
            if (preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new \RuntimeException('wprism init could not resolve WPRISM_SPEC_VERSION from the agent source');
            }
            define('WPRISM_SPEC_VERSION', (int) $m[1]);
        }
        return \WPrism\SpecVersionWindow::accepted(WPRISM_SPEC_VERSION);
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
        $valid = ($result['format'] ?? null) === 'wprism-init-result/v1'
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
            && ($state['site_config'] ?? null) === $expectedRepo . '/site.wprism.json'
            && ($state['git'] ?? null) === 'existing-worktree'
            && is_array($unsupported)
            && array_is_list($unsupported)
            && $unsupported === [];
        if (!$valid) {
            throw new \RuntimeException(
                "wprism init confirmation returned an incompatible or incomplete result for '{$transport->name()}'"
            );
        }
    }

    private static function expectedRepository(EnvironmentDriver $transport): string {
        $repo = rtrim($transport->repoPath(), '/');
        if ($repo === '' || !str_starts_with($repo, '/')) {
            throw new \RuntimeException(
                "wprism init requires an absolute, non-root repository path for '{$transport->name()}'"
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
     * The classification the operator is being asked to confirm (issue #3499).
     *
     * Total over the components it names, single-line reasons, one of the
     * three classifications, and a locked entry that actually carries an
     * origin: the host validates this even though the target already did,
     * because the host is what renders it for review and must not render a
     * shape it does not understand. The split declaration requires a
     * non-empty classification, and the full declaration an empty one — the
     * two are the same fact stated twice, and disagreeing is a protocol error.
     */
    private static function validSplit(mixed $split, bool $requiresSplit): bool {
        if (!is_array($split) || !array_is_list($split)) {
            return false;
        }
        $seen = [];
        foreach ($split as $row) {
            if (!is_array($row)
                || !in_array($row['classification'] ?? null, ['locked', 'first-party', 'unsourced'], true)
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
                if (!is_array($row['origin'] ?? null)) {
                    return false;
                }
            } elseif (array_key_exists('origin', $row)) {
                return false;
            }
        }
        return $requiresSplit ? $split !== [] : $split === [];
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
