<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';

final class VisualPortfolioSettingsEvidence {
    public static function check(bool $ok, string $reason): void {
        if (!$ok) throw new RuntimeException('Visual Portfolio settings admission: ' . $reason);
    }

    public static function command(string $root, string $stem, string $pair, string $verb = '', int $expectedExit = 0): void {
        self::check(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'exact owned pair');
        $transport = ' ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *';
        if ($verb === '') {
            WPrismTest\PrivateCommandOutput::readBytes($stem, '/^' . $transport . '$/D', expectedExit: $expectedExit);
            return;
        }
        self::check(in_array($verb, ['capture', 'apply'], true), 'known wrapped native command');
        $pointer = 'private command diagnostics (unverified): ';
        $prefix = $root . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.';
        $pattern = '/^(?:' . $transport . '|' . preg_quote($pointer . $prefix, '/') . '[A-Za-z0-9]{6})$/D';
        $stdout = WPrismTest\PrivateCommandOutput::readBytes($stem, $pattern, expectedExit: $expectedExit);
        $stderr = (string) file_get_contents($stem . '.stderr');
        $notice = explode("\n", $stderr, 2)[0];
        self::check(str_starts_with($notice, $pointer . $prefix), 'one leading wrapper diagnostic pointer');
        $directory = substr($notice, strlen($pointer));
        $original = WPrismTest\PrivateCommandOutput::readBytes($directory . '/command', '/^' . $transport . '$/D', expectedExit: $expectedExit);
        self::check($stdout === $original && $stderr === $notice . "\n" . file_get_contents($directory . '/command.stderr'),
            'wrapper streams exactly reproduce the admitted original command');
    }

    public static function revisit(array $priorContract, array $request, array $plan, array $refusal, array $before, array $after): void {
        self::check(WPrism\Canon::encode($request) === WPrism\Canon::encode($priorContract), 'reenabling revisits the exact earlier immutable artifact');
        self::check($plan['format'] === 'wprism-scoped-plan/v1' && count($plan['update']) === 1
            && $plan['update'][0]['rebuild_option_names'] === ['vp_general'], 'reenabling requires an authored settings update');
        self::check($refusal === [
            'format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
            'error' => 'apply_refused', 'reason_code' => 'apply_refused',
            'message' => 'terminal scoped receipt no longer describes the live bounded target; no mutation or replay attempted',
            'remediation' => 'inspect the changed selected/protected target state and reconcile it before retrying this scoped authority',
        ], 'exact engine refusal for a historical terminal authority');
        self::check($before === $after && $after['enabled'] === false, 'refused revisit preserves all observed native and engine state');
    }

    public static function phase(string $phase, array $before, array $source, array $contract, array $plan,
        array $apply, array $after, array $repeat, array $stable): void {
        self::check($contract['format'] === 'wprism-scope-contract/v1' && $contract['selectors'] === ['option:vp_general'], 'exact settings scope');
        $selected = array_column(array_merge($contract['live']['roots'], $contract['live']['closure']), 'entity');
        if ($before['archive'] > 0) {
            $role = $before['archive'] === $before['ids']['old'] ? 'old' : 'new';
            self::check(!in_array($before['uuids'][$role], $selected, true), 'previous target archive is outside authored scope');
        }
        self::check(count($contract['potential_actions']) === 1, 'one global settings projection');
        $action = $contract['potential_actions'][0];
        self::check($action['declaration']['provider'] === 'visual-portfolio-settings'
            && $action['declaration']['capability'] === 'reconcile_settings'
            && !array_key_exists('triggers', $action['declaration']), 'complete native dependency closure');
        $indexed = $action['declaration'] + ['manifest' => $action['manifest'], 'index' => $action['index']];
        self::check(WPrism\Canon::encode($plan['selected_actions']) === WPrism\Canon::encode([
            ['declaration_hash' => hash('sha256', WPrism\Canon::encode($indexed)),
                'index' => $action['index'], 'manifest' => $action['manifest']],
        ]), 'public plan binds the immutable action');
        self::check($apply['format'] === 'wprism-scoped-apply-result/v1' && $apply['applied'] === 1
            && $apply['canary'] === 'clean' && $apply['drift'] === [] && $apply['scoped_receipt']['phase'] === 'complete'
            && $apply['verification']['result'] === 'pass'
            && $apply['verification']['source_artifact_hash'] === $contract['source']['artifact_hash'], 'public native Apply completes under exact source authority');
        self::check($repeat['replayed'] === true && $repeat['applied'] === 0 && $repeat['actions'] === []
            && $repeat['verification'] === null && $repeat['scoped_receipt'] === $apply['scoped_receipt'], 'terminal replay executes no native mutation');
        self::check($after === $stable, 'terminal replay preserves every native observation byte');
        self::check($after['ids'] === $before['ids'] && $after['uuids'] === $before['uuids'], 'all native page identities survive');
        self::check($source['enabled'] === $after['enabled'], 'native portfolio enabled state agrees');
        self::check($after['archive'] === ($phase === 'move' ? $after['ids']['new'] : 0), 'archive is rebound or cleared as authored');
        self::check($source['ids']['new'] !== $after['ids']['new'], 'native proof uses divergent source and target IDs');
        foreach ($before['tables'] as $table => $rows) {
            if ($table === 'options') continue;
            if ($table === 'postmeta') {
                $unowned = static fn(array $row): bool => $row['meta_key'] !== '_vp_post_type_mapped';
                self::check(array_values(array_filter($rows, $unowned)) === array_values(array_filter($after['tables'][$table], $unowned)), 'all non-marker metadata bytes survive');
            } else self::check($rows === $after['tables'][$table], 'complete native input table survives: ' . $table);
        }
        $oldOptions = array_column($before['tables']['options'], null, 'option_name');
        $newOptions = array_column($after['tables']['options'], null, 'option_name');
        $allowed = ['vp_general', 'wp_user_roles', 'visual_portfolio_updated_caps', 'rewrite_rules'];
        $newIntent = 0;
        foreach ($newOptions as $name => $row) {
            if (isset($oldOptions[$name]) || in_array($name, $allowed, true)) continue;
            self::check(preg_match('/^wprism_scoped_effect_[a-f0-9]{64}$/D', $name) === 1, 'only a scoped engine receipt may be added');
            $receipt = json_decode($row['option_value'], true, 32, JSON_THROW_ON_ERROR);
            self::check($receipt['owner'] === 'visual-portfolio-settings' && $receipt['operation_name'] === 'reconcile_settings'
                && $receipt['state'] === 'verified', 'added option is the completed native operation receipt');
            $payload = $receipt;
            unset($payload['receipt_hash']);
            $key = ['owner' => $receipt['owner'], 'operation_name' => $receipt['operation_name'],
                'operation_id' => $receipt['operation']['operation_id']];
            self::check($row['option_value'] === WPrism\Canon::encode($receipt)
                && $receipt['format'] === 'wprism-scoped-provider-operation-receipt/v1'
                && $receipt['receipt_hash'] === hash('sha256', WPrism\Canon::encode($payload))
                && $name === 'wprism_scoped_effect_' . hash('sha256', WPrism\Canon::encode($key))
                && $receipt['operation']['authority_hash'] === $apply['scoped_receipt']['authority_hash']
                && $receipt['operation']['lease_session_id'] === $apply['scoped_receipt']['session_id'],
                'canonical operation receipt binds the exact completed authority and lease');
            $allowed[] = $name;
            $newIntent++;
        }
        self::check($newIntent === 1, 'exactly one operation receipt is retained');
        foreach ($allowed as $name) { unset($oldOptions[$name], $newOptions[$name]); }
        self::check($oldOptions === $newOptions, 'every unrelated option and existing engine receipt survives exactly');
        $notices = ['provider capability fired: visual-portfolio-settings@1.0.0 reconcile_settings (scoped, verified)'];
        if ($phase === 'clear') array_unshift($notices,
            'option vp_general.portfolio_archive_page: removed from the live blob — capture no longer reports this declared authored sub-key (its own source value is gone on the captured environment, e.g. a referenced attachment was deleted)');
        self::check($apply['warnings'] === $notices && $repeat['warnings'] === [], 'only exact authored-removal and verified-action notices');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__, 3);
    require_once "$root/sandbox/tests/lib/PrivateCommandOutput.php";
    require_once "$root/agent/src/Kernel/Canon.php";
    if (($argv[1] ?? '') === 'admit-command') {
        VisualPortfolioSettingsEvidence::command($root, $argv[2], $argv[3], $argv[4] ?? '', (int) ($argv[5] ?? 0));
        exit(0);
    }
    $sink = $argv[1] ?? '';
    $pair = $argv[2] ?? '';
    // command() already admits the exact wrapper/original stream pair. Read
    // the original host Apply stream here rather than treating its pointer as evidence.
    $read = static function (string $name, int $exit = 0) use ($sink, $pair, $root): array {
        $stem = "$sink/$name";
        $verb = preg_match('/-(apply|repeat)$/D', $name) === 1 ? 'apply' : '';
        VisualPortfolioSettingsEvidence::command($root, $stem, $pair, $verb, $exit);
        $stderr = (string) file_get_contents($stem . '.stderr');
        if (str_starts_with($stderr, 'private command diagnostics (unverified): ')) {
            $stem = substr(explode("\n", $stderr, 2)[0], strlen('private command diagnostics (unverified): ')) . '/command';
        }
        return json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
            '/^ ?Container wprism-[a-z0-9]+-cli[12]-run-[a-z0-9]+ (Creating|Created) *$/D', expectedExit: $exit), true, 64, JSON_THROW_ON_ERROR);
    };
    $before = $read('baseline-target');
    foreach (['move', 'clear', 'disable'] as $phase) {
        VisualPortfolioSettingsEvidence::phase($phase, $before, $read("$phase-source"), $read("$phase-scope"),
            $read("$phase-plan"), $read("$phase-apply"), $read("$phase-target"), $read("$phase-repeat"), $read("$phase-stable"));
        $before = $read("$phase-stable");
    }
    VisualPortfolioSettingsEvidence::revisit($read('clear-scope'), $read('enable-scope'), $read('enable-plan'),
        $read('enable-apply', 1), $before, $read('enable-target'));
    echo "PASS: native settings repair and replay; historical-artifact revisit explicitly refused\n";
}
