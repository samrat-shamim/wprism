<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 4) . '/agent/src/Policy/ScopeContract.php';

/** Independent native semantics; shared transport/compiler own their contracts. */
final class WPFormsApplyEvidence {
    public static function source(array $contract, WPrism\CompiledRepository $repository, WPrism\Policy $policy): void {
        self::check($policy->code_config() === null && $repository->code_descriptor() === null, 'code descriptor is outside this lane');
        // Semantic recapture equality cannot bind a run to these executable
        // bytes. The product guard rechecks the exact artifact and full closure.
        WPrism\ScopeContract::assert_associated($contract, $repository, $policy);
    }

    public static function stderrPattern(string $pair, string $root, string $name): string {
        self::check(preg_match('/\A[a-z][a-z0-9]+\z/', $pair) === 1 && str_starts_with($root, '/'), 'exact diagnostic ownership');
        $pattern = ' ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-z0-9]+ (?:Creating|Created) *';
        // Only public candidate calls emit the shared lifecycle's pointer.
        // This is a retained diagnostic location, never a success certificate.
        if (preg_match('/\A(?:baseline|embeds|widgets|routing)-(capture|plan|apply|repeat|recapture|source-repeat)\z/', $name, $match) === 1) {
            $verb = match ($match[1]) {
                'repeat' => 'apply',
                'recapture', 'source-repeat' => 'capture',
                default => $match[1],
            };
            $prefix = 'private command diagnostics (unverified): ' . $root . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.';
            $pattern .= '|' . preg_quote($prefix, '/') . '[A-Za-z0-9]{6}';
        }
        return '/\A(?:' . $pattern . ')\z/';
    }

    public static function selection(array $contract, array $plan, array $apply, array $repeat): void {
        self::check(($contract['format'] ?? null) === 'wprism-scope-contract/v1'
            && array_key_exists('code_diagnostic', $contract) && $contract['code_diagnostic'] === null, 'real content-only scope contract');
        $potential = array_values(array_filter($contract['potential_actions'] ?? [],
            static fn(array $row): bool => ($row['manifest'] ?? null) === 'wpforms-lite'));
        self::check(count($potential) === 1, 'one candidate in the actual scope closure');
        $row = $potential[0];
        $declaration = $row['declaration'] ?? [];
        self::check(($declaration['provider'] ?? null) === 'wpforms-form-locations'
            && ($declaration['capability'] ?? null) === 'rebuild_form_locations'
            && !array_key_exists('triggers', $declaration), 'complete global provider declaration');
        $identity = ['manifest' => $row['manifest'], 'index' => $row['index'],
            'declaration_hash' => hash('sha256', WPrism\Canon::encode($declaration))];
        self::check(WPrism\Canon::encode($plan['selected_actions'] ?? null) === WPrism\Canon::encode([$identity]),
            'actual public target plan selects precisely the contract declaration');
        foreach (['conflict', 'collision', 'drift', 'delete', 'delete_conflict', 'code_mismatch', 'code_drift',
            'incomplete_apply', 'incomplete_lifecycle', 'missing_user'] as $bucket) {
            self::check(($plan[$bucket] ?? null) === [], 'target plan has no ' . $bucket);
        }
        $blockers = $plan['adapter_dispositions'] ?? [];
        self::check(count($blockers) === 2, 'only the two expected promotion blockers remain');
        $codes = [];
        foreach ($blockers as $blocker) {
            self::check(($blocker['name'] ?? null) === 'wpforms-lite' && ($blocker['status'] ?? null) === 'blocked',
                'experimental package does not claim promotion readiness');
            $codes[] = $blocker['code'] ?? null;
        }
        sort($codes, SORT_STRING);
        self::check($codes === ['authored_state_not_certified', 'operation_not_certified'], 'exact non-readiness reasons, no hidden runtime blocker');
        foreach ([$apply, $repeat] as $receipt) {
            self::check(($receipt['canary'] ?? null) === 'clean' && ($receipt['verification']['result'] ?? null) === 'pass'
                && ($receipt['drift'] ?? null) === [], 'normal Apply passes canary and full canonical verification');
        }
        self::check(is_int($apply['applied'] ?? null) && $apply['applied'] > 0 && count($apply['actions'] ?? []) === 1,
            'Apply actually mutates authored state and runs one provider');
        self::check(is_string($contract['source']['artifact_hash'] ?? null)
            && ($plan['artifact_hash'] ?? null) === $contract['source']['artifact_hash']
            && ($apply['artifact']['hash'] ?? null) === $contract['source']['artifact_hash']
            && ($repeat['artifact']['hash'] ?? null) === $contract['source']['artifact_hash'], 'Apply and repeat consume the scope contract artifact');
        $action = $apply['actions'][0];
        self::check(($action['source'] ?? null) === $row['source'] && ($action['kind'] ?? null) === 'provider'
            && ($action['manifest'] ?? null) === 'wpforms-lite' && ($action['verified'] ?? null) === true,
            'Apply dispatch receipt belongs to the selected provider');
        $expectedEvents = [];
        self::check(is_array($plan['adopt'] ?? null) && array_is_list($plan['adopt']), 'complete adoption plan');
        foreach ($plan['adopt'] as $adoption) {
            self::check(in_array($adoption['type'] ?? null, ['post', 'term'], true)
                && is_int($adoption['env_id'] ?? null) && $adoption['env_id'] > 0
                && is_string($adoption['uuid'] ?? null) && is_string($adoption['path'] ?? null), 'exact planned native adoption');
            $expectedEvents[] = 'adopted env ' . $adoption['type'] . ' ' . $adoption['env_id']
                . ' as ' . $adoption['uuid'] . ' (' . $adoption['path'] . ')';
        }
        $events = $apply['warnings'] ?? [];
        self::check(is_array($events) && array_is_list($events)
            && count(array_filter($events, 'is_string')) === count($events), 'complete informational event list');
        $providerEvents = array_values(array_filter($events, static fn(string $event): bool =>
            preg_match('/^provider capability fired: wpforms-form-locations@1\.0\.0 rebuild_form_locations \([0-9.eE+-]+s, verified\)$/D', $event) === 1));
        self::check(count($providerEvents) === 1, 'exactly one verified provider event');
        $expectedEvents[] = $providerEvents[0];
        sort($expectedEvents, SORT_STRING);
        sort($events, SORT_STRING);
        self::check($events === $expectedEvents, 'only exact planned adoption and verified provider events, no diagnostic warning');
        self::check(($repeat['applied'] ?? null) === 0 && ($repeat['actions'] ?? null) === []
            && ($repeat['warnings'] ?? null) === [], 'repeat Apply has zero authored writes and zero provider actions');
    }

    public static function native(string $case, array $source, array $before, array $after, array $stable): void {
        foreach ([$source, $before, $after, $stable] as $record) {
            self::check(($record['format'] ?? null) === 'wprism-wpforms-apply-observation/v1'
                && ($record['version'] ?? null) === '2.0.1.1', 'exact native observation');
            self::check(array_keys($record['posts'] ?? []) === ['integer', 'string', 'template', 'destination', 'embed'], 'complete native topology');
        }
        self::check($source['home'] !== $after['home'], 'independent native home bindings');
        foreach ($source['posts'] as $role => $post) {
            $target = $after['posts'][$role];
            self::check(is_int($post['id']) && $post['id'] > 0 && is_int($target['id']) && $target['id'] > 0
                && $post['id'] !== $target['id'] && $post['uuid'] === $target['uuid'] && is_string($post['uuid']),
                'native IDs diverge while Apply maps the canonical identity: ' . $role);
        }
        self::check(count($before['padding']) === 7 && $before['padding'] === $after['padding']
            && $after['padding'] === $stable['padding'], 'complete target-only native trash survives');
        $orphan = $before['widget_options']['wpforms-widget'][99] ?? null;
        self::check(is_array($orphan) && ($orphan['title'] ?? null) === 'Local orphan'
            && $orphan === ($after['widget_options']['wpforms-widget'][99] ?? null)
            && $orphan === ($stable['widget_options']['wpforms-widget'][99] ?? null), 'unmanaged native widget survives allocation and retry');
        $coreOrphan = $before['widget_options']['block'][99] ?? null;
        self::check($coreOrphan === ['content' => '<!-- wp:paragraph --><p>Unrelated core widget</p><!-- /wp:paragraph -->']
            && $coreOrphan === ($after['widget_options']['block'][99] ?? null)
            && $coreOrphan === ($stable['widget_options']['block'][99] ?? null), 'unmanaged core block survives allocation and retry');
        self::check($after['owned'] === $stable['owned']
            && $after['posts'] === $stable['posts'] && $after['widget_options'] === $stable['widget_options'],
            'fresh native consumers and complete owned rows reach a physical fixed point');
        foreach (['integer', 'string'] as $role) {
            $initial = $after['native'][$role];
            $repeated = $stable['native'][$role];
            // Locked Token.php:169 emits time() on every native render. This
            // single validated attribute is request-time data, not stored
            // convergence state; every other native/rendered byte must agree.
            $initial['rendered'] = self::renderedInvariant($initial['rendered']);
            $repeated['rendered'] = self::renderedInvariant($repeated['rendered']);
            self::check($initial === $repeated, 'complete native consumers agree apart from their explicit request timestamp');
        }
        if ($case !== 'baseline') {
            foreach (['integer', 'string', 'template'] as $role) {
                self::check($before['posts'][$role]['body'] === $after['posts'][$role]['body'], 'non-form Apply leaves complete form bytes unchanged: ' . $role);
            }
        }
        $embedding = $after['posts']['embed'];
        $integer = $after['posts']['integer']['id'];
        $string = $after['posts']['string']['id'];
        $block = '<!-- wp:wpforms/form-selector {"formId":"' . $string . '","displayTitle":true} /-->';
        self::check($embedding['body'] === ($case === 'baseline' ? '[wpforms id="' . $integer . '" title="true"]' . "\n" : '') . $block,
            'native embed bytes contain target IDs and the exact case mutation');
        self::check($embedding['slug'] === ($case === 'routing' ? 'wprism-wpf-embed-renamed' : 'wprism-wpf-embed')
            && $embedding['title'] === ($case === 'routing' ? 'Renamed placement Ω' : 'WPrism WPForms embed'), 'native routing mutation is discriminating');
        $expectedWidgets = [];
        foreach ($after['widget_options']['wpforms-widget'] as $number => $settings) {
            if ($number === '_multiwidget') continue;
            self::check(is_array($settings) && in_array($settings['form_id'] ?? null, [$integer, (string) $integer], true)
                && in_array($settings['title'] ?? null, ['Local orphan', 'Apply widget'], true), 'native widget reference and owned/local title');
            $expectedWidgets[] = ['type' => 'widget', 'title' => $settings['title'],
                'form_id' => $settings['form_id'], 'id' => 'wpforms-widget-' . $number];
        }
        $blockNumbers = array_values(array_filter(array_keys($after['widget_options']['block']), static fn($number): bool => $number !== '_multiwidget'));
        self::check(is_array($after['block_form_ids'] ?? null)
            && array_keys($after['block_form_ids']) === $blockNumbers
            && $after['block_form_ids'] === ($stable['block_form_ids'] ?? null), 'complete stable native block-form roster');
        foreach ($after['widget_options']['block'] as $number => $settings) {
            if ($number === '_multiwidget') continue;
            $ids = $after['block_form_ids'][$number];
            if ($ids === []) {
                self::check(($before['block_form_ids'][$number] ?? null) === []
                    && ($before['widget_options']['block'][$number] ?? null) === $settings,
                    'unrelated native block widget retains its complete bytes and empty form roster');
                continue;
            }
            self::check($ids === [$integer], 'native WPForms block has precisely the target integer form reference');
            self::check(($settings['content'] ?? null) === '<!-- wp:wpforms/form-selector {"formId":"' . $integer . '"} /-->', 'native block widget targets the remapped form');
            // Locked Locator::init()/search_in_block_widgets() supplies this
            // native English label even when the widget has no title field.
            $expectedWidgets[] = ['type' => 'widget', 'title' => 'Block Widget', 'form_id' => $integer, 'id' => 'block-' . $number];
        }
        self::check(count($expectedWidgets) === (in_array($case, ['widgets', 'routing'], true) ? 3 : 1)
            && self::ordered($expectedWidgets) === self::ordered($after['widgets']), 'all native widget locations independently match stored settings');
        foreach (['integer', 'string'] as $role) {
            $form = $after['posts'][$role]['id'];
            $expected = [];
            if ($case === 'baseline' || $role === 'string') {
                self::check(is_string($embedding['url']) && str_starts_with($embedding['url'], $after['home']), 'target-derived placement permalink');
                $expected[] = ['type' => 'page', 'title' => $embedding['title'], 'form_id' => $form,
                    'id' => $embedding['id'], 'status' => 'publish', 'url' => substr($embedding['url'], strlen($after['home']))];
            }
            if ($role === 'integer') $expected = array_merge($expected, $expectedWidgets);
            $native = $after['native'][$role];
            self::check(self::ordered($expected) === self::ordered($native['locations']), 'complete target-native location values: ' . $role);
            self::check(substr_count($native['column'], 'class="wpforms-locations-list-item"') === count($expected)
                && str_contains($native['rendered'], 'id="wpforms-form-' . $form . '"')
                && str_contains($native['rendered'], 'name="wpforms[fields][1]"')
                && str_contains($native['rendered'], 'name="wpforms[fields][2]"'), 'fresh location and form UI consumers: ' . $role);
            $rows = array_values(array_filter($after['owned'], static fn(array $row): bool => (int) $row['post_id'] === $form));
            // Native storage order is posts then widgets, already pinned by
            // the lower-level native lane. No untrusted unserialize is needed.
            self::check(count($rows) === 1 && ($rows[0]['meta_key'] ?? null) === 'wpforms_form_locations'
                && is_string($rows[0]['meta_value'] ?? null) && $rows[0]['meta_value'] === serialize($expected),
                'complete physical location row agrees with native consumers: ' . $role);
        }
        self::check(count($after['owned']) === 2, 'no unproved duplicate or orphan location row');
    }

    private static function ordered(array $rows): array {
        $encoded = array_map(static fn(array $row): string => WPrism\Canon::encode($row), $rows);
        sort($encoded, SORT_STRING);
        return $encoded;
    }

    private static function renderedInvariant(string $html): string {
        self::check(substr_count($html, 'data-token-time') === 1
            && preg_match('/ data-token-time="[0-9]+"/', $html) === 1, 'exact single native render timestamp');
        return (string) preg_replace('/ data-token-time="[0-9]+"/', ' data-token-time="<request-time>"', $html, 1);
    }

    private static function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('WPForms Apply evidence: ' . $message);
    }
}

if (($argv[1] ?? null) === '--admit') {
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/RepositoryConvergence.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Repository/RepositoryCompiler.php';
    require_once dirname(__DIR__) . '/capture-plan/probe.php';
    wprism_test_define_agent_versions();
    $sink = $argv[2] ?? '';
    $pair = $argv[3] ?? '';
    if (preg_match('/^[a-z][a-z0-9]+$/D', $pair) !== 1) throw new RuntimeException('WPForms Apply evidence: exact pair required');
    // Reviewers run this verifier from their own checkout; the retained
    // pointer belongs to the producer's sibling sink, not the review checkout.
    $root = dirname($sink, 3);
    if (dirname($sink) !== $root . '/sandbox/tmp' || preg_match('/\Awpforms-apply-native\.[A-Za-z0-9]{6}\z/', basename($sink)) !== 1) {
        throw new RuntimeException('WPForms Apply evidence: exact retained sink required');
    }
    $read = static fn(string $name): array => json_decode(WPrismTest\PrivateCommandOutput::readObject($sink . '/' . $name,
        WPFormsApplyEvidence::stderrPattern($pair, $root, $name)), true, 32, JSON_THROW_ON_ERROR);
    foreach ([1, 2] as $side) {
        WPrismTest\PrivateCommandOutput::readBytes($sink . '/binding' . $side,
            WPFormsApplyEvidence::stderrPattern($pair, $root, 'binding' . $side));
        $home = 'http://' . $pair . $side . '.invalid';
        $setup = $read('setup' . $side);
        if (($setup['home'] ?? null) !== $home) throw new RuntimeException('WPForms Apply evidence: native setup home differs from headless bootstrap premise');
        WPFormsCaptureProbe::admit($read('seed' . $side), 'seed', $home);
    }
    $library = WPrism\AdapterLibrary::fromSourcePackage(dirname(__DIR__, 4), 'wpforms-lite');
    $before = $read('before2');
    $priorSource = null;
    foreach (['baseline', 'embeds', 'widgets', 'routing'] as $case) {
        $source = $read($case . '-source');
        $target = $read($case . '-target');
        $stable = $read($case . '-stable');
        WPFormsApplyEvidence::selection($read($case . '-contract'), $read($case . '-plan'), $read($case . '-apply'), $read($case . '-repeat'));
        WPFormsApplyEvidence::native($case, $source, $before, $target, $stable);
        if ($case !== 'baseline') {
            $plan = $read($case . '-plan');
            $expected = $case === 'widgets' ? 'sidebar/sidebar-1' : $source['posts']['embed']['uuid'];
            if (($plan['create'] ?? null) !== [] || ($plan['adopt'] ?? null) !== []
                || array_column($plan['update'] ?? [], 'uuid') !== [$expected]) {
                throw new RuntimeException('WPForms Apply evidence: non-form case does not select exactly its native authored entity');
            }
        }
        if ($priorSource !== null) foreach (['integer', 'string', 'template'] as $role) {
            if ($source['posts'][$role]['body'] !== $priorSource['posts'][$role]['body']) throw new RuntimeException('WPForms Apply evidence: source changed a form during non-form case');
        }
        $repositories = [];
        foreach (['source', 'target', 'source-repeat'] as $side) {
            $repo = $sink . '/' . $case . '.' . $side;
            $policy = WPrism\Policy::load($repo, adapterLibrary: $library);
            $compiled = WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
            if ($policy->code_config() !== null || $compiled->code_descriptor() !== null) throw new RuntimeException('WPForms Apply evidence: code descriptor is outside this lane');
            if ($side === 'source') WPFormsApplyEvidence::source($read($case . '-contract'), $compiled, $policy);
            $repositories[$side] = $compiled;
        }
        WPrismTest\RepositoryConvergence::assertSame($repositories['source'], $repositories['target']);
        WPrismTest\RepositoryConvergence::assertSame($repositories['source'], $repositories['source-repeat']);
        $before = $stable;
        $priorSource = $source;
        echo 'Admitted native content-only Apply, non-form selection and full compiler convergence: ' . $case . "\n";
    }
}
