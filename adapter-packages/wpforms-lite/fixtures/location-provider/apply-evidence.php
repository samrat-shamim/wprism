<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 4) . '/agent/src/Policy/ScopeContract.php';

/** Independent native semantics; shared transport/compiler own their contracts. */
final class WPFormsApplyEvidence {
    public static function largeRecord(string $name): bool {
        return preg_match('/^(?:settings-(?:general|validation)[12]|tags-author-(?:first1|initial[12]|local2|clear2|change1))$/D', $name) === 1;
    }
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
        if (preg_match('/\A(?:(?:baseline|embeds|widgets|routing|tags)-(capture|plan|apply|repeat|recapture|source-repeat)|refusal-(apply))\z/', $name, $match) === 1) {
            if ($name === 'refusal-apply') $match[1] = 'apply';
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

    public static function emptyBefore(array $before): void {
        self::check(($before['format'] ?? null) === 'wprism-wpforms-apply-observation/v1'
            && ($before['version'] ?? null) === '2.0.1.1' && ($before['case'] ?? null) === 'before', 'independent empty-content preimage');
        foreach (['posts', 'native', 'content_roster', 'owned', 'widgets'] as $field) {
            self::check(($before[$field] ?? null) === [], 'empty target has no ' . $field);
        }
        $widgets = $before['widget_options']['wpforms-widget'] ?? null;
        self::check(is_array($widgets) && array_diff_key($widgets, ['_multiwidget' => true]) === [], 'empty target has no native WPForms widget slot');
        $blocks = $before['widget_options']['block'] ?? null;
        self::check(is_array($blocks) && ($blocks[99] ?? null) === [
            'content' => '<!-- wp:paragraph --><p>Unrelated core widget</p><!-- /wp:paragraph -->'], 'empty target has the exact unrelated core witness');
        self::check(array_keys($before['block_form_ids'] ?? []) === array_values(array_filter(array_keys($blocks),
            static fn($number): bool => $number !== '_multiwidget')), 'complete empty-target block roster');
        foreach ($blocks as $number => $settings) {
            if ($number === '_multiwidget') continue;
            self::check(is_array($settings) && is_string($settings['content'] ?? null)
                && !str_contains($settings['content'], 'wpforms') && $before['block_form_ids'][$number] === [],
                'empty target has no hidden WPForms block placement');
        }
        self::check(is_array($before['sidebars'] ?? null) && $before['sidebars'] !== [], 'complete initialized native sidebars');
        foreach ($before['sidebars'] as $name => $slots) {
            if ($name === 'array_version') continue;
            self::check(is_array($slots) && array_is_list($slots), 'native sidebar slot list');
            foreach ($slots as $slot) self::check(is_string($slot) && !str_starts_with($slot, 'wpforms-widget-'), 'empty target has no assigned WPForms widget');
        }
        self::check(is_array($before['padding'] ?? null) && count($before['padding']) === 7
            && count(array_unique(array_column($before['padding'], 'ID'))) === 7, 'seven distinct native target-local trash rows');
        foreach ($before['padding'] as $row) self::check(($row['post_type'] ?? null) === 'post'
            && ($row['post_status'] ?? null) === 'trash', 'target-local padding is outside authored capture');
    }

    public static function refused(array $before, array $after, array $receipt, array $diagnostics): void {
        self::emptyBefore($before);
        self::emptyBefore($after);
        self::check($before === $after, 'refused Apply preserves the complete empty-content and target-local native witnesses');
        $keys = array_keys($receipt);
        sort($keys, SORT_STRING);
        self::check($keys === ['command', 'diagnostics', 'error', 'format', 'message', 'ok', 'reason_code', 'remediation']
            && $receipt['format'] === 'wprism-command-refusal/v1' && $receipt['ok'] === false && $receipt['command'] === 'apply'
            && $receipt['error'] === 'repository_compilation_failed' && $receipt['reason_code'] === 'repository_compilation_failed'
            && $receipt['message'] === 'repository compilation refused this command'
            && is_string($receipt['remediation']) && $receipt['remediation'] !== '', 'ordinary Apply refuses at the compiler before dispatch');
        self::check(count($diagnostics) === 1 && ($diagnostics[0]['code'] ?? null) === 'semantic_delete_reference'
            && ($diagnostics[0]['message'] ?? null) === 'reference target 10000000-0000-4000-8000-000000000099 is absent from the compiled revision'
            && $receipt['diagnostics'] === $diagnostics, 'complete native refusal diagnostics match independent source compilation');
    }

    /** Shared tree confinement/retention; this fixture owns the one deliberately broken shortcode. */
    public static function refusalInput(string $sourceRepo, string $refusalRepo, array $prepared, WPrism\AdapterLibrary $library): array {
        require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
        require_once dirname(__DIR__, 4) . '/agent/src/Repository/RepositoryCompiler.php';
        $policy = WPrism\Policy::load($sourceRepo, adapterLibrary: $library);
        $compiled = WPrism\RepositoryCompiler::compile_staged($sourceRepo . '/state', $sourceRepo, $policy);
        $entities = [];
        foreach (['embed' => 'page', 'integer' => 'wpforms'] as $role => $type) {
            $matches = array_filter($compiled->tree(), static fn(array $row): bool => $row['type'] === 'post'
                && ($row['data']['type'] ?? null) === $type && ($row['data']['slug'] ?? null) === 'wprism-wpf-' . $role);
            self::check(count($matches) === 1, 'refusal source contains its exact controlled entity');
            $entities[$role] = ['uuid' => array_key_first($matches), 'entity' => reset($matches)];
        }
        $source = WPrismTest\FilesystemTreeEvidence::capture($sourceRepo, 'state');
        $bad = WPrismTest\FilesystemTreeEvidence::capture($refusalRepo, 'state');
        self::check($source['directories'] === $bad['directories'] && WPrism\Canon::read_file($sourceRepo . '/site.wprism.json')
            === WPrism\Canon::read_file($refusalRepo . '/site.wprism.json'), 'refusal retains the full source topology and policy bytes');
        // Authored file mtimes do not participate in repository semantics;
        // compare every retained payload and path, never a selected file glob.
        $expectedFiles = array_column($source['files'], 'contents_base64', 'path');
        $path = $entities['embed']['entity']['path'];
        $original = base64_decode($expectedFiles[$path], true);
        $needle = '[wpforms id="{{post:' . $entities['integer']['uuid'] . '}}" title="true"]';
        self::check(is_string($original) && substr_count($original, $needle) === 1, 'one complete source shortcode to break');
        $missing = '10000000-0000-4000-8000-000000000099';
        self::check(!isset($compiled->tree()[$missing]), 'refusal target is absent from the source compiler');
        $changed = str_replace($needle, '[wpforms id="{{post:' . $missing . '}}" title="true"]', $original);
        self::check(WPrism\Canon::encode($prepared) === WPrism\Canon::encode(['format' => 'wprism-wpforms-apply-refusal-input/v1', 'path' => $path,
            'artifact_hash' => $compiled->artifact_hash(), 'missing_uuid' => $missing,
            'original_sha256' => hash('sha256', $original), 'changed_sha256' => hash('sha256', $changed)]), 'refusal input is exactly the source-derived single reference mutation');
        $expectedFiles[$path] = base64_encode($changed);
        self::check($expectedFiles === array_column($bad['files'], 'contents_base64', 'path'), 'no unrelated source file changed in the negative repository');
        $badPolicy = WPrism\Policy::load($refusalRepo, adapterLibrary: $library);
        try { WPrism\RepositoryCompiler::compile_staged($refusalRepo . '/state', $refusalRepo, $badPolicy); }
        catch (WPrism\RepositoryCompilationException $failure) { return $failure->diagnostics; }
        throw new RuntimeException('WPForms Apply evidence: negative repository did not refuse compilation');
    }

    /** Source compilation, never target observations or a magic plan count, owns the CREATE inventory. */
    public static function created(WPrism\CompiledRepository $compiled, array $source, array $plan, array $after): void {
        self::check(($plan['artifact_hash'] ?? null) === $compiled->artifact_hash(), 'creation plan consumes the retained source artifact');
        $expected = [];
        foreach (['integer' => 'wpforms', 'string' => 'wpforms', 'template' => 'wpforms-template',
            'destination' => 'page', 'embed' => 'page'] as $role => $type) {
            $matches = array_filter($compiled->tree(), static fn(array $entity): bool => $entity['type'] === 'post'
                && ($entity['data']['type'] ?? null) === $type && ($entity['data']['slug'] ?? null) === 'wprism-wpf-' . $role);
            self::check(count($matches) === 1, 'source compiler contains exactly one controlled entity: ' . $role);
            $uuid = array_key_first($matches);
            $entity = $matches[$uuid];
            self::check(($entity['data']['parent'] ?? null) === null && $entity['path'] === 'posts/' . $type . '/' . $uuid . '--wprism-wpf-' . $role . '.md',
                'controlled source root and compiler path: ' . $role);
            foreach ([$source, $after] as $record) {
                $post = $record['posts'][$role] ?? [];
                self::check(($post['uuid'] ?? null) === $uuid && ($post['type'] ?? null) === $type
                    && ($post['slug'] ?? null) === 'wprism-wpf-' . $role, 'native identity is independently bound to the compiled entity: ' . $role);
            }
            $expected[] = ['uuid' => $uuid, 'type' => $entity['type'], 'path' => $entity['path']];
        }
        $ids = array_column($expected, 'uuid');
        $paths = array_column($expected, 'path');
        $actual = [];
        $tree = $compiled->tree();
        // Core defaults can be adopted on an otherwise empty content target.
        // No fixture coordinate may hide in that global bucket (or unchanged).
        foreach (['create', 'update', 'unchanged', 'adopt', 'conflict', 'collision', 'drift', 'delete', 'delete_conflict'] as $bucket) {
            self::check(is_array($plan[$bucket] ?? null) && array_is_list($plan[$bucket]), 'complete creation plan bucket: ' . $bucket);
            foreach ($plan[$bucket] as $row) {
                self::check(is_array($row), 'typed creation plan row');
                $entity = $tree[$row['uuid'] ?? ''] ?? null;
                self::check(is_array($entity) && ($row['type'] ?? null) === $entity['type']
                    && ($row['path'] ?? null) === $entity['path'], 'every creation-lane plan row belongs to the actual compiled source');
                if ($bucket !== 'create' && !in_array($row['uuid'] ?? null, $ids, true) && !in_array($row['path'] ?? null, $paths, true)) continue;
                self::check($bucket === 'create' && !array_key_exists('env_id', $row), 'controlled entity must be created, never pre-adopted');
                $actual[] = ['uuid' => $row['uuid'] ?? null, 'type' => $row['type'] ?? null, 'path' => $row['path'] ?? null];
            }
        }
        self::check(self::ordered($actual) === self::ordered($expected), 'exact source-derived fixture CREATE inventory with no missing or duplicate row');
        self::physicalPosts($after);
        foreach (['integer', 'string'] as $role) {
            $post = $after['posts'][$role];
            $doc = json_decode($post['body'], true, 32, JSON_THROW_ON_ERROR);
            $destination = $after['posts']['destination']['id'];
            self::check(($doc['id'] ?? null) === ($role === 'integer' ? $post['id'] : (string) $post['id']), 'created native self-ID retains its declared scalar type');
            self::check(($doc['settings']['confirmations'][2]['page'] ?? null) === (string) $destination
                && ($doc['settings']['confirmations'][3]['page'] ?? null) === 'previous_page'
                && ($doc['settings']['confirmations'][4]['redirect'] ?? null) === $after['home'] . '/wprism-wpf-destination/?page_id=' . $destination,
                'created confirmation graph uses target IDs and home, preserving its native sentinel');
        }
        $template = json_decode($after['posts']['template']['body'], true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($template) && !array_key_exists('id', $template), 'created template retains its native absent-self-ID shape');
    }

    private static function physicalPosts(array $record): void {
        self::check(array_keys($record['posts'] ?? []) === ['integer', 'string', 'template', 'destination', 'embed']
            && is_array($record['content_roster'] ?? null) && count($record['content_roster']) === count($record['posts']), 'complete native controlled-post census');
        $nativeIds = array_column($record['posts'], 'id');
        self::check(count(array_unique($nativeIds)) === count($record['posts']), 'controlled native IDs are distinct');
        $physicalIds = array_column($record['content_roster'], 'ID');
        $expectedIds = array_map('strval', $nativeIds);
        sort($physicalIds, SORT_STRING);
        sort($expectedIds, SORT_STRING);
        self::check($physicalIds === $expectedIds, 'physical census contains every created native identity exactly once');
        foreach ($record['content_roster'] as $row) {
            $roles = array_keys(array_filter($record['posts'], static fn(array $post): bool => $post['id'] === (int) ($row['ID'] ?? 0)));
            self::check(count($roles) === 1, 'physical census row has exactly one mapped native role');
            $post = $record['posts'][$roles[0]];
            self::check(($row['post_type'] ?? null) === $post['type'] && ($row['post_name'] ?? null) === $post['slug']
                && ($row['post_title'] ?? null) === $post['title'] && ($row['post_content'] ?? null) === $post['body']
                && ($row['post_parent'] ?? null) === '0' && ($row['post_status'] ?? null) === 'publish' && ($post['status'] ?? null) === 'publish',
                'physical/native root identity, status, title and body agree');
        }
    }

    public static function native(string $case, array $source, array $before, array $after, array $stable, string $targetKind = 'seeded'): void {
        self::check(in_array($targetKind, ['seeded', 'empty'], true), 'declared native target premise');
        if ($targetKind === 'empty' && $case === 'baseline') self::emptyBefore($before);
        $records = [$source, $after, $stable];
        if ($targetKind !== 'empty' || $case !== 'baseline') $records[] = $before;
        foreach ($records as $record) {
            self::check(($record['format'] ?? null) === 'wprism-wpforms-apply-observation/v1'
                && ($record['version'] ?? null) === '2.0.1.1', 'exact native observation');
            self::check(array_keys($record['posts'] ?? []) === ['integer', 'string', 'template', 'destination', 'embed'], 'complete native topology');
            foreach ($record['posts'] as $role => $post) self::check(($post['status'] ?? null) === 'publish'
                && ($post['type'] ?? null) === match ($role) { 'integer', 'string' => 'wpforms', 'template' => 'wpforms-template', default => 'page' },
                'exact native post type and published status for the controlled role');
        }
        if ($targetKind === 'empty') {
            self::physicalPosts($after);
            self::physicalPosts($stable);
            if ($case !== 'baseline') self::physicalPosts($before);
            self::check($after['content_roster'] === $stable['content_roster'] && is_array($after['sidebars'] ?? null)
                && $after['sidebars'] === ($stable['sidebars'] ?? null), 'complete physical post census and native sidebar option reach a fixed point');
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
        if ($targetKind === 'seeded') {
            $orphan = $before['widget_options']['wpforms-widget'][99] ?? null;
            self::check(is_array($orphan) && ($orphan['title'] ?? null) === 'Local orphan'
                && $orphan === ($after['widget_options']['wpforms-widget'][99] ?? null)
                && $orphan === ($stable['widget_options']['wpforms-widget'][99] ?? null), 'unmanaged native widget survives allocation and retry');
        }
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
                if ($case === 'tags' && $role === 'integer') continue;
                self::check($before['posts'][$role]['body'] === $after['posts'][$role]['body'], 'non-form Apply leaves complete form bytes unchanged: ' . $role);
            }
        }
        $embedding = $after['posts']['embed'];
        $integer = $after['posts']['integer']['id'];
        $string = $after['posts']['string']['id'];
        $block = '<!-- wp:wpforms/form-selector {"formId":"' . $string . '","displayTitle":true} /-->';
        self::check($embedding['body'] === ($case === 'baseline' ? '[wpforms id="' . $integer . '" title="true"]' . "\n" : '') . $block,
            'native embed bytes contain target IDs and the exact case mutation');
        self::check($embedding['slug'] === (in_array($case, ['routing', 'tags'], true) ? 'wprism-wpf-embed-renamed' : 'wprism-wpf-embed')
            && $embedding['title'] === (in_array($case, ['routing', 'tags'], true) ? 'Renamed placement Ω' : 'WPrism WPForms embed'), 'native routing mutation is discriminating');
        $expectedWidgets = [];
        foreach ($after['widget_options']['wpforms-widget'] as $number => $settings) {
            if ($number === '_multiwidget') continue;
            self::check(is_array($settings) && in_array($settings['form_id'] ?? null, [$integer, (string) $integer], true)
                && in_array($settings['title'] ?? null, $targetKind === 'seeded' ? ['Local orphan', 'Apply widget'] : ['Apply widget'], true), 'native widget reference and owned/local title');
            $expectedWidgets[] = ['type' => 'widget', 'title' => $settings['title'],
                'form_id' => $settings['form_id'], 'id' => 'wpforms-widget-' . $number];
        }
        foreach ([$source, $after, $stable] as $record) {
            $blockNumbers = array_values(array_filter(array_keys($record['widget_options']['block']), static fn($number): bool => $number !== '_multiwidget'));
            self::check(is_array($record['block_form_ids'] ?? null)
                && array_keys($record['block_form_ids']) === $blockNumbers, 'complete native block-form roster');
        }
        self::check($after['block_form_ids'] === $stable['block_form_ids'], 'stable native block-form roster');
        $expectedUnrelated = [$coreOrphan];
        $sourceInteger = $source['posts']['integer']['id'];
        $sourceBlock = ['content' => '<!-- wp:wpforms/form-selector {"formId":"' . $sourceInteger . '"} /-->'];
        $sourceFormBlocks = 0;
        foreach ($source['widget_options']['block'] as $number => $settings) {
            if ($number === '_multiwidget') continue;
            self::check(is_array($settings) && is_string($settings['content'] ?? null), 'complete source block settings');
            $ids = $source['block_form_ids'][$number];
            // This controlled source authors one exact selector, only in the
            // sidebar cases. Bind its native roster in both directions before
            // zero-form rows enter the complete-settings conservation proof.
            if ($ids === []) {
                self::check(!str_contains($settings['content'], '<!-- wp:wpforms/form-selector'),
                    'source WPForms selector cannot masquerade as an unrelated block');
                $expectedUnrelated[] = $settings;
                continue;
            }
            self::check($ids === [$sourceInteger] && $settings === $sourceBlock, 'source native roster matches the exact controlled WPForms block');
            ++$sourceFormBlocks;
        }
        self::check($sourceFormBlocks === (in_array($case, ['widgets', 'routing', 'tags'], true) ? 1 : 0), 'exact source WPForms block count for the native case');
        $actualUnrelated = [];
        foreach ($after['widget_options']['block'] as $number => $settings) {
            if ($number === '_multiwidget') continue;
            $ids = $after['block_form_ids'][$number];
            if ($ids === []) {
                $actualUnrelated[] = $settings;
                continue;
            }
            self::check($ids === [$integer], 'native WPForms block has precisely the target integer form reference');
            self::check(($settings['content'] ?? null) === '<!-- wp:wpforms/form-selector {"formId":"' . $integer . '"} /-->', 'native block widget targets the remapped form');
            // Locked Locator::init()/search_in_block_widgets() supplies this
            // native English label even when the widget has no title field.
            $expectedWidgets[] = ['type' => 'widget', 'title' => 'Block Widget', 'form_id' => $integer, 'id' => 'block-' . $number];
        }
        // SidebarState allocates target-local slots for managed core widgets.
        // Their complete source values (with duplicate multiplicity), plus
        // the explicit unmapped local sentinel, are the expected target set.
        // Recapture convergence separately binds managed sidebar identities.
        self::check(self::ordered($actualUnrelated) === self::ordered($expectedUnrelated),
            'complete source core-widget settings plus the exact target-local sentinel, independent of allocator slots');
        self::check(count($expectedWidgets) === (in_array($case, ['widgets', 'routing', 'tags'], true) ? 2 : 0) + ($targetKind === 'seeded' ? 1 : 0)
            && self::ordered($expectedWidgets) === self::ordered($after['widgets']), 'all native widget locations independently match stored settings');
        $expectedOwned = 0;
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
            self::check($expected === [] ? ($native['locations'] === '' && $native['column'] === '—')
                : (is_array($native['locations']) && self::ordered($expected) === self::ordered($native['locations'])), 'complete target-native location values: ' . $role);
            self::check(substr_count($native['column'], 'class="wpforms-locations-list-item"') === count($expected)
                && str_contains($native['rendered'], 'id="wpforms-form-' . $form . '"')
                && str_contains($native['rendered'], 'name="wpforms[fields][1]"')
                && str_contains($native['rendered'], 'name="wpforms[fields][2]"'), 'fresh location and form UI consumers: ' . $role);
            $rows = array_values(array_filter($after['owned'], static fn(array $row): bool => (int) $row['post_id'] === $form));
            // Native storage order is posts then widgets, already pinned by
            // the lower-level native lane. No untrusted unserialize is needed.
            if ($expected === []) self::check($rows === [], 'unlocated native form has no stored location row');
            else {
                ++$expectedOwned;
                self::check(count($rows) === 1 && ($rows[0]['meta_key'] ?? null) === 'wpforms_form_locations'
                    && is_string($rows[0]['meta_value'] ?? null) && $rows[0]['meta_value'] === serialize($expected),
                    'complete physical location row agrees with native consumers: ' . $role);
            }
        }
        self::check(count($after['owned']) === $expectedOwned, 'no unproved duplicate or orphan location row');
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
    $targetKind = $argv[4] ?? 'seeded';
    $settingsProfile = $argv[5] ?? '0';
    $tagsProfile = $argv[6] ?? '0';
    if (!in_array($tagsProfile, ['0', '1'], true) || ($tagsProfile === '1' && $targetKind !== 'seeded')) {
        throw new RuntimeException('WPForms Apply evidence: explicit seeded-target tags profile required');
    }
    if ($tagsProfile === '1') require_once __DIR__ . '/tag-evidence.php';
    if (!in_array($settingsProfile, ['0', '1'], true) || ($settingsProfile === '1' && $targetKind !== 'seeded')) {
        throw new RuntimeException('WPForms Apply evidence: explicit seeded-target settings profile required');
    }
    if ($settingsProfile === '1') require_once __DIR__ . '/settings-evidence.php';
    if (!in_array($targetKind, ['seeded', 'empty'], true)) throw new RuntimeException('WPForms Apply evidence: declared target kind required');
    if (preg_match('/^[a-z][a-z0-9]+$/D', $pair) !== 1) throw new RuntimeException('WPForms Apply evidence: exact pair required');
    // Reviewers run this verifier from their own checkout; the retained
    // pointer belongs to the producer's sibling sink, not the review checkout.
    $root = dirname($sink, 3);
    if (dirname($sink) !== $root . '/sandbox/tmp' || preg_match('/\Awpforms-apply-native\.[A-Za-z0-9]{6}\z/', basename($sink)) !== 1) {
        throw new RuntimeException('WPForms Apply evidence: exact retained sink required');
    }
    $read = static fn(string $name): array => json_decode(WPrismTest\PrivateCommandOutput::readObject($sink . '/' . $name,
        WPFormsApplyEvidence::stderrPattern($pair, $root, $name),
        profile: WPFormsApplyEvidence::largeRecord($name)
            ? WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE : WPrismTest\EvidenceSizeProfile::COMPACT), true, 32, JSON_THROW_ON_ERROR);
    foreach ([1, 2] as $side) {
        WPrismTest\PrivateCommandOutput::readBytes($sink . '/binding' . $side,
            WPFormsApplyEvidence::stderrPattern($pair, $root, 'binding' . $side));
        $home = 'http://' . $pair . $side . '.invalid';
        $setup = $read('setup' . $side);
        if (($setup['home'] ?? null) !== $home) throw new RuntimeException('WPForms Apply evidence: native setup home differs from headless bootstrap premise');
        if ($targetKind === 'empty' && (($setup['target_kind'] ?? null) !== 'empty' || ($setup['side'] ?? null) !== ($side === 1 ? 'source' : 'target')
            || ($setup['version'] ?? null) !== '2.0.1.1' || ($setup['code_baseline_created'] ?? null) !== false)) {
            throw new RuntimeException('WPForms Apply evidence: setup does not bind the declared empty-content lane');
        }
        if ($targetKind === 'empty' && $side === 2) {
            if ($read('seed2') !== ['format' => 'wprism-wpforms-apply-empty-seed/v1', 'version' => '2.0.1.1', 'home' => $home, 'posts' => []]) {
                throw new RuntimeException('WPForms Apply evidence: target content was pre-seeded');
            }
        } else WPFormsCaptureProbe::admit($read('seed' . $side), 'seed', $home);
        if ($settingsProfile === '1') {
            foreach (['general', 'validation'] as $view) {
                WPFormsSettingsEvidence::author($read('settings-' . $view . $side), $pair, $side === 1 ? 'source' : 'target', $view);
            }
            if ($read('settings-general' . $side)['after'] !== $read('settings-validation' . $side)['before']) {
                throw new RuntimeException('WPForms settings evidence: native settings changed between the two registered view saves');
            }
        }
        if ($tagsProfile === '1') {
            $previous = null;
            foreach ($side === 1 ? ['first', 'initial'] : ['local', 'clear', 'initial'] as $mode) {
                $author = $read('tags-author-' . $mode . $side);
                WPFormsTagEvidence::author($author, $pair, $side === 1 ? 'source' : 'target', $mode);
                if ($previous !== null && $previous['after'] !== $author['before']) throw new RuntimeException('WPForms tag evidence: author sequence has an unproved intervening change');
                $previous = $author;
            }
        }
    }
    $library = WPrism\AdapterLibrary::fromSourcePackage(dirname(__DIR__, 4), 'wpforms-lite');
    $before = $read('before2');
    if ($targetKind === 'empty') {
        $prepared = $read('refusal-prepare');
        if (WPrism\Canon::encode($prepared) !== WPrism\Canon::encode($read('refusal-restore')) || ($prepared['artifact_hash'] ?? null) !== ($read('baseline-contract')['source']['artifact_hash'] ?? null)
            || ($prepared['missing_uuid'] ?? null) !== '10000000-0000-4000-8000-000000000099') {
            throw new RuntimeException('WPForms Apply evidence: refusal restoration differs from the real baseline artifact');
        }
        $diagnostics = WPFormsApplyEvidence::refusalInput($sink . '/baseline.source', $sink . '/refusal.source', $prepared, $library);
        $refusal = json_decode(WPrismTest\PrivateCommandOutput::readObject($sink . '/refusal-apply',
            WPFormsApplyEvidence::stderrPattern($pair, $root, 'refusal-apply'), expectedExit: 1), true, 32, JSON_THROW_ON_ERROR);
        WPFormsApplyEvidence::refused($read('refusal-before'), $read('refusal-after'), $refusal, $diagnostics);
        if ($before !== $read('refusal-before')) throw new RuntimeException('WPForms Apply evidence: empty target changed before the refusal premise');
    }
    $priorSource = null;
    foreach ($tagsProfile === '1' ? ['baseline', 'embeds', 'widgets', 'routing', 'tags'] : ['baseline', 'embeds', 'widgets', 'routing'] as $case) {
        $source = $read($case . '-source');
        $target = $read($case . '-target');
        $stable = $read($case . '-stable');
        if (($source['home'] ?? null) !== 'http://' . $pair . '1.invalid' || ($target['home'] ?? null) !== 'http://' . $pair . '2.invalid'
            || ($before['home'] ?? null) !== $target['home'] || ($stable['home'] ?? null) !== $target['home']) {
            throw new RuntimeException('WPForms Apply evidence: native observations do not belong to their exact setup homes');
        }
        WPFormsApplyEvidence::selection($read($case . '-contract'), $read($case . '-plan'), $read($case . '-apply'), $read($case . '-repeat'));
        WPFormsApplyEvidence::native($case, $source, $before, $target, $stable, $targetKind);
        if ($case !== 'baseline') {
            $plan = $read($case . '-plan');
            $expected = $case === 'widgets' ? 'sidebar/sidebar-1' : $source['posts'][$case === 'tags' ? 'integer' : 'embed']['uuid'];
            if (($plan['create'] ?? null) !== [] || ($plan['adopt'] ?? null) !== []
                || array_column($plan['update'] ?? [], 'uuid') !== [$expected]) {
                throw new RuntimeException('WPForms Apply evidence: non-form case does not select exactly its native authored entity');
            }
        }
        if ($priorSource !== null) foreach (['integer', 'string', 'template'] as $role) {
            if ($case === 'tags' && $role === 'integer') continue;
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
        $targetOnly = [];
        if ($tagsProfile === '1') {
            if ($case === 'baseline') {
                WPFormsTagEvidence::authoredToObserved($read('tags-author-initial1'), $source['tags']);
                WPFormsTagEvidence::authoredToObserved($read('tags-author-initial2'), $before['tags']);
                WPFormsTagEvidence::observation($before['tags'], $before['posts'], WPFormsTagEvidence::labels('initial'));
            }
            if ($case === 'tags') {
                $author = $read('tags-author-change1');
                WPFormsTagEvidence::author($author, $pair, 'source', 'change');
                WPFormsTagEvidence::authoredToObserved(['form_id' => $author['form_id'], 'after' => $author['before']], $priorSource['tags']);
                WPFormsTagEvidence::authoredToObserved($author, $source['tags']);
            }
            $targetOnly = WPFormsTagEvidence::native($case, $source, $before, $target, $stable, $read($case . '-recaptured'),
                $repositories['source'], $repositories['target']);
        }
        WPrismTest\RepositoryConvergence::assertSame($repositories['source'], $repositories['target'], $targetOnly);
        WPrismTest\RepositoryConvergence::assertSame($repositories['source'], $repositories['source-repeat']);
        if ($settingsProfile === '1') {
            if ($case === 'baseline') WPFormsSettingsEvidence::initial($read('settings-validation1'), $read('settings-validation2'),
                $read('settings-local2'), $source, $before);
            WPFormsSettingsEvidence::native($source, $before, $target, $stable, $repositories['source']->tree()['options/core']['data']);
        }
        if ($targetKind === 'empty' && $case === 'baseline') WPFormsApplyEvidence::created($repositories['source'], $source, $read('baseline-plan'), $target);
        $before = $tagsProfile === '1' ? $read($case . '-recaptured') : $stable;
        $priorSource = $source;
        echo 'Admitted native content-only Apply, ' . ($case === 'tags' ? 'tag selection' : 'non-form selection') . ' and full compiler convergence: ' . $case . ' (' . $targetKind . " target)\n";
    }
}
