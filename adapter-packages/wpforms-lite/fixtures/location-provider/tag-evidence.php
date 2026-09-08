<?php
declare(strict_types=1);

/** Pure WPForms native-tag assertions; host compiler dependencies belong only at the host comparison. */
final class WPFormsTagEvidence {
    public const TAXONOMY = 'wpforms_form_tag';
    public const LOCAL = 'Unrelated local tag';

    public static function labels(string $mode): array {
        return match ($mode) {
            'first' => ['Intake Ω'], 'initial' => ['Intake Ω', '701'],
            'local' => [self::LOCAL], 'clear' => [], 'change' => ['701'],
            default => throw new RuntimeException('WPForms tag evidence: unknown native author mode'),
        };
    }

    public static function overview(string $html, int $formId): array {
        self::check($formId > 0 && $html !== '' && strlen($html) <= 1048576
            && str_contains($html, '</html>') && preg_match('//u', $html) === 1, 'complete bounded native overview HTML');
        $dom = new DOMDocument();
        self::check($dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'native overview parses');
        $xpath = new DOMXPath($dom);
        $boxes = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " wpforms-column-tags-links ") and @data-form-id="' . $formId . '"]');
        self::check($boxes !== false && $boxes->length === 1 && $boxes->item(0)->getAttribute('data-is-editable') === '1', 'one editable native form tag row');
        self::check($xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " wpforms-column-tags-edit ")]', $boxes->item(0))->length === 1,
            'native tag edit control is available');
        $records = [];
        foreach ($dom->getElementsByTagName('script') as $script) {
            if (preg_match_all('/\bvar wpforms_admin_forms_overview = (\{[^\r\n]+\});/', $script->textContent, $matches)) {
                foreach ($matches[1] as $bytes) $records[] = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            }
        }
        self::check(count($records) === 1, 'exactly one native localized overview record');
        $record = $records[0];
        self::keys($record, ['choicesjs_config', 'edit_tags_form', 'all_tags_choices', 'strings']);
        $nonce = $record['strings']['nonce'] ?? null;
        self::check(is_string($nonce) && preg_match('/^[a-f0-9]{10}$/D', $nonce) === 1, 'actual overview nonce');
        self::choices($record['all_tags_choices']);
        return ['nonce' => $nonce, 'choices' => $record['all_tags_choices']];
    }

    public static function post(array $overview, int $formId, string $mode): array {
        $tags = [];
        foreach (self::labels($mode) as $label) {
            $matches = array_values(array_filter($overview['choices'], static fn(array $choice): bool => $choice['label'] === $label));
            self::check(count($matches) <= 1 && ($mode !== 'change' || count($matches) === 1), 'unambiguous native existing/new choice');
            $tags[] = ['value' => $matches === [] ? $label : $matches[0]['value'], 'label' => $label];
        }
        return ['action' => 'wpforms_admin_forms_overview_save_tags', 'nonce' => $overview['nonce'],
            'forms' => [(string) $formId], 'tags' => $tags];
    }

    public static function choices(array $choices): void {
        self::check(array_is_list($choices) && count($choices) <= 32, 'complete bounded native tag choices');
        $ids = $labels = [];
        foreach ($choices as $choice) {
            self::keys($choice, ['value', 'slug', 'label', 'count']);
            self::check(self::id($choice['value']) !== 701 && !isset($ids[$choice['value']])
                && is_string($choice['slug']) && $choice['slug'] !== '' && is_string($choice['label'])
                && !in_array($choice['label'], $labels, true) && is_int($choice['count']) && $choice['count'] >= 0,
                'native choices retain distinct non-701 identities and text labels');
            $ids[$choice['value']] = true;
            $labels[] = $choice['label'];
        }
    }

    /** Complete physical core term tables; no native cache, taxonomy or metadata-key filter. */
    public static function physical(array $record): array {
        self::keys($record, ['terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'forms']);
        $columns = [
            'terms' => ['term_id', 'name', 'slug', 'term_group'],
            'term_taxonomy' => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
            'term_relationships' => ['object_id', 'term_taxonomy_id', 'term_order'],
            'termmeta' => ['meta_id', 'term_id', 'meta_key', 'meta_value'],
        ];
        foreach ($columns as $table => $keys) {
            self::check(is_array($record[$table]) && array_is_list($record[$table]) && count($record[$table]) <= 512, 'bounded complete native ' . $table);
            foreach ($record[$table] as $row) {
                self::keys($row, $keys);
                foreach ($row as $value) self::check(is_string($value), 'physical core term columns preserve native string types');
            }
        }
        $terms = [];
        foreach ($record['terms'] as $row) {
            self::check(self::id($row['term_id']) !== 701, 'numeric label cannot collide with any native term identity');
            self::check(!isset($terms[$row['term_id']]), 'unique native term identity');
            $terms[$row['term_id']] = $row;
        }
        $tags = [];
        $tts = [];
        foreach ($record['term_taxonomy'] as $row) {
            self::check(self::id($row['term_taxonomy_id']) !== 701, 'numeric label cannot collide with any native TT identity');
            self::check(isset($terms[$row['term_id']]) && !isset($tts[$row['term_taxonomy_id']]), 'complete valid TT coordinate');
            $tts[$row['term_taxonomy_id']] = $row;
            if ($row['taxonomy'] !== self::TAXONOMY) continue;
            $term = $terms[$row['term_id']];
            self::check(!isset($tags[$term['name']]) && self::id($term['term_id']) !== 701
                && self::id($row['term_taxonomy_id']) !== 701 && $row['parent'] === '0'
                && $row['description'] === '' && $term['term_group'] === '0', 'ordinary native label and independent term/TT coordinates');
            $tags[$term['name']] = ['term' => $term, 'tt' => $row];
        }
        $seen = [];
        foreach ($record['term_relationships'] as $row) {
            self::id($row['object_id']);
            $key = $row['object_id'] . ':' . $row['term_taxonomy_id'];
            self::check(isset($tts[$row['term_taxonomy_id']]) && !isset($seen[$key]), 'complete nonorphan relationship roster');
            $seen[$key] = true;
        }
        $seen = [];
        foreach ($record['termmeta'] as $row) {
            self::id($row['meta_id']);
            self::check(isset($terms[$row['term_id']]) && !isset($seen[$row['meta_id']]), 'complete nonorphan term metadata');
            $seen[$row['meta_id']] = true;
        }
        self::check(is_array($record['forms']) && array_is_list($record['forms']) && count($record['forms']) >= 1 && count($record['forms']) <= 2,
            'complete declared form row roster');
        foreach ($record['forms'] as $form) {
            self::keys($form, ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
                'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
                'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
                'post_type', 'post_mime_type', 'comment_count']);
            foreach ($form as $value) self::check(is_string($value), 'complete physical form columns preserve native string types');
            self::check(self::id($form['ID']) > 0 && $form['post_type'] === 'wpforms'
                && $form['post_status'] === 'publish', 'native published form row');
        }
        return $tags;
    }

    public static function author(array $record, string $pair, string $side, string $mode): void {
        self::keys($record, ['format', 'version', 'side', 'mode', 'form_id', 'home', 'actor', 'capabilities',
            'debug_config', 'diagnostics_before', 'before', 'requests', 'session_retired', 'after', 'diagnostics_after']);
        $number = $side === 'source' ? 1 : 2;
        self::check(preg_match('/^[a-z][a-z0-9]+$/D', $pair) === 1
            && in_array($side . ':' . $mode, ['source:first', 'source:initial', 'source:change',
                'target:local', 'target:clear', 'target:initial'], true), 'declared native author sequence');
        self::check($record['format'] === 'wprism-wpforms-native-tag-author/v1' && $record['version'] === '2.0.1.1'
            && $record['side'] === $side && $record['mode'] === $mode && $record['home'] === 'http://' . $pair . $number . '.invalid'
            && $record['actor'] === 'admin' && $record['capabilities'] === ['edit_others_forms' => true, 'edit_form_single' => true]
            && $record['debug_config'] === [true, true, false] && $record['session_retired'] === true
            && is_int($record['form_id']) && $record['form_id'] > 0, 'exact native administrative author and retired session');
        self::diagnostics($record['diagnostics_before']);
        self::diagnostics($record['diagnostics_after']);
        self::check(array_is_list($record['requests']) && count($record['requests']) === 2, 'complete native GET/POST exchange');
        foreach ($record['requests'] as $index => $request) {
            self::keys($request, ['url', 'host', 'method', 'post', 'status', 'headers', 'body']);
            $route = $index === 0 ? '/wp-admin/admin.php?page=wpforms-overview' : '/wp-admin/admin-ajax.php';
            self::check($request['url'] === 'http://wprism-' . $pair . '-wp' . $number . '-1' . $route
                && $request['host'] === $pair . $number . '.invalid' && $request['method'] === ($index === 0 ? 'GET' : 'POST')
                && $request['status'] === 200 && is_array($request['headers']) && is_string($request['body'])
                && strlen($request['body']) <= 1048576 && preg_match('//u', $request['body']) === 1
                && preg_match('/(?:PHP\s+)?(?:Warning|Notice|Deprecated|Fatal error|Parse error)(?:<\/b>)?\s*:/i', $request['body']) !== 1,
                'complete bounded diagnostic-free native HTTP reply');
        }
        [$get, $post] = $record['requests'];
        $overview = self::overview($get['body'], $record['form_id']);
        self::check($get['post'] === [] && $post['post'] === self::post($overview, $record['form_id'], $mode), 'exact native emitted nonce and legal choice payload');
        $before = self::physical($record['before']);
        $after = self::physical($record['after']);
        self::choiceRows($overview['choices'], $before);
        $new = array_values(array_diff(self::labels($mode), array_keys($before)));
        $expectedNew = match ($side . ':' . $mode) {
            'source:first' => ['Intake Ω'], 'source:initial' => ['701'], 'target:local' => [self::LOCAL],
            'target:initial' => ['Intake Ω', '701'], default => [],
        };
        self::check($new === $expectedNew && self::ordered(array_map('strval', array_keys($after))) === self::ordered([...array_map('strval', array_keys($before)), ...$new]),
            'new/existing tag creation sequence is discriminating');
        foreach (['terms' => 'term_id', 'term_taxonomy' => 'term_taxonomy_id'] as $table => $key) {
            $prior = array_column($record['before'][$table], null, $key);
            $next = array_column($record['after'][$table], null, $key);
            foreach ($prior as $identity => $row) {
                self::check(isset($next[$identity]), 'native save preserves every prior core term row');
                if ($table === 'term_taxonomy' && $row['taxonomy'] === self::TAXONOMY) $row['count'] = $next[$identity]['count'];
                self::check($row === $next[$identity], 'native save changes no unrelated physical term column');
            }
            $added = array_diff_key($next, $prior);
            $expected = [];
            foreach ($new as $label) $expected[] = $after[$label][$table === 'terms' ? 'term' : 'tt'];
            self::check(self::ordered(array_values($added)) === self::ordered($expected), 'only declared native tag rows are created');
        }
        $expectedRelationships = self::relationships($record['before'], $record['form_id'], self::labels($mode), $after);
        self::check(self::ordered($record['after']['term_relationships']) === self::ordered($expectedRelationships)
            && $record['before']['termmeta'] === $record['after']['termmeta'], 'native save replaces only selected tag relationships and preserves complete metadata');
        self::counts($record['after'], $after);
        self::check(count($record['before']['forms']) === 1 && count($record['after']['forms']) === 1
            && $record['before']['forms'][0]['ID'] === (string) $record['form_id'], 'native author owns exactly its selected form');
        $form = $record['before']['forms'][0];
        $doc = json_decode($form['post_content'], true, 32, JSON_THROW_ON_ERROR);
        self::check(is_array($doc) && is_array($doc['settings'] ?? null), 'complete prior native form body');
        $doc['settings']['form_tags'] = self::labels($mode);
        // Locked wpforms_encode() uses ordinary wp_json_encode; wp_insert_post
        // removes its wp_slash layer. Retain all other bytes and scalar types.
        $form['post_content'] = json_encode($doc, JSON_THROW_ON_ERROR);
        foreach (['post_modified', 'post_modified_gmt'] as $key) {
            $value = $record['after']['forms'][0][$key];
            self::check(preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value) === 1 && $value >= $form[$key], 'native modified time is monotonic');
            $form[$key] = $value;
        }
        self::check($record['after']['forms'] === [$form], 'only exact literal form_tags and native modification times changed');
        $response = json_decode($post['body'], true, 32, JSON_THROW_ON_ERROR);
        self::keys($response, ['success', 'data']);
        self::check($response['success'] === true && is_array($response['data']), 'native AJAX success envelope');
        self::keys($response['data'], $new === [] ? ['tags_links', 'tags_ids', 'tags_options']
            : ['tags_links', 'tags_ids', 'tags_options', 'all_tags_choices']);
        self::tagMarkup($response['data'], self::labels($mode), $after, $record['home']);
        if ($new !== []) self::choiceRows($response['data']['all_tags_choices'], $after);
    }

    private static function tagMarkup(array $data, array $labels, array $tags, string $home): void {
        foreach (['tags_links', 'tags_ids', 'tags_options'] as $field) self::check(is_string($data[$field]), 'complete native tag response bytes');
        if ($labels === []) {
            self::check($data['tags_ids'] === '' && $data['tags_options'] === ''
                && preg_match('/^<span aria-hidden="true">&#8212;<\/span><span class="screen-reader-text">[^<]+<\/span>$/D', $data['tags_links']) === 1,
                'native empty tag UI');
            return;
        }
        $ids = explode(',', $data['tags_ids']);
        self::check(count($ids) === count($labels) && count(array_unique($ids)) === count($ids), 'exact native response tag identities');
        $byId = [];
        foreach ($labels as $label) $byId[$tags[$label]['term']['term_id']] = $tags[$label]['term'];
        self::check(self::ordered($ids) === self::ordered(array_map('strval', array_keys($byId))), 'response uses native term IDs, never TT or labels');
        $links = $options = [];
        foreach ($ids as $id) {
            $term = $byId[$id];
            $name = htmlspecialchars($term['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $links[] = '<a href="' . $home . '/wp-admin/admin.php?page=wpforms-overview&#038;tags=' . rawurlencode($term['slug']) . '">' . $name . '</a>';
            $options[] = '<option value="' . $id . '" selected>' . $name . '</option>';
        }
        self::check($data['tags_links'] === implode(', ', $links) && $data['tags_options'] === implode('', $options), 'complete native response links and selected options');
    }

    public static function choiceRows(array $choices, array $tags): void {
        self::choices($choices);
        $expected = [];
        foreach ($tags as $tag) $expected[] = ['value' => $tag['term']['term_id'], 'slug' => $tag['term']['slug'],
            'label' => $tag['term']['name'], 'count' => (int) $tag['tt']['count']];
        self::check(self::ordered($choices) === self::ordered($expected), 'complete native choices agree with independent physical rows');
    }

    public static function relationships(array $before, int $formId, array $labels, array $tags): array {
        $owned = array_column(array_filter($before['term_taxonomy'], static fn(array $tt): bool => $tt['taxonomy'] === self::TAXONOMY), 'term_taxonomy_id');
        $out = array_values(array_filter($before['term_relationships'], static fn(array $row): bool =>
            $row['object_id'] !== (string) $formId || !in_array($row['term_taxonomy_id'], $owned, true)));
        foreach ($labels as $label) $out[] = ['object_id' => (string) $formId, 'term_taxonomy_id' => $tags[$label]['tt']['term_taxonomy_id'], 'term_order' => '0'];
        return $out;
    }

    public static function counts(array $physical, array $tags): void {
        foreach ($tags as $tag) {
            $count = count(array_filter($physical['term_relationships'], static fn(array $row): bool => $row['term_taxonomy_id'] === $tag['tt']['term_taxonomy_id']));
            self::check($tag['tt']['count'] === (string) $count, 'native tag count agrees with complete relationships');
        }
    }

    public static function observation(array $record, array $posts, array $labels): array {
        self::keys($record, ['physical', 'consumers', 'choices', 'identities', 'diagnostics']);
        $tags = self::physical($record['physical']);
        self::counts($record['physical'], $tags);
        self::choiceRows($record['choices'], $tags);
        self::diagnostics($record['diagnostics']);
        $ids = array_map(static fn(string $role): string => (string) $posts[$role]['id'], ['integer', 'string']);
        self::check(array_map('strval', array_keys($record['consumers'])) === $ids
            && array_column($record['physical']['forms'], 'ID') === $ids, 'complete fresh controlled form consumers');
        foreach (['integer', 'string'] as $role) {
            $post = $posts[$role];
            $consumer = $record['consumers'][(string) $post['id']];
            self::keys($consumer, ['terms', 'body']);
            $form = $record['physical']['forms'][array_search((string) $post['id'], $ids, true)];
            $expectedLabels = $role === 'integer' ? $labels : [];
            self::check($form['post_content'] === $post['body'] && $form['post_name'] === $post['slug']
                && $form['post_title'] === $post['title'] && $consumer['body'] === json_decode($post['body'], true, 32, JSON_THROW_ON_ERROR)
                && ($consumer['body']['settings']['form_tags'] ?? []) === $expectedLabels, 'fresh native body retains exact literal labels independently of tag IDs');
            $expected = [];
            foreach ($expectedLabels as $label) {
                self::check(isset($tags[$label]), 'every expected native label exists physically');
                $tag = $tags[$label];
                $expected[] = ['term_id' => (int) $tag['term']['term_id'], 'name' => $tag['term']['name'], 'slug' => $tag['term']['slug'],
                    'term_group' => (int) $tag['term']['term_group'], 'term_taxonomy_id' => (int) $tag['tt']['term_taxonomy_id'],
                    'taxonomy' => self::TAXONOMY, 'description' => '', 'parent' => 0, 'count' => (int) $tag['tt']['count'], 'filter' => 'raw'];
            }
            self::check(self::ordered($consumer['terms']) === self::ordered($expected), 'complete fresh get_the_terms agrees with independent physical coordinates');
            self::check(self::ordered($record['physical']['term_relationships']) === self::ordered(self::relationships($record['physical'], $post['id'], $expectedLabels, $tags)),
                'exact assigned relationships and explicit native zero order');
        }
        $mapped = array_map('strval', array_keys($record['identities']));
        $termIds = array_map(static fn(array $tag): string => $tag['term']['term_id'], array_values($tags));
        self::check($record['identities'] === [] || self::ordered($mapped) === self::ordered($termIds), 'complete observed plugin identity roster');
        foreach ($record['identities'] as $termId => $mapping) {
            self::keys($mapping, ['term', 'term_taxonomy']);
            self::check($mapping['term'] === $mapping['term_taxonomy'] && ($mapping['term'] === null || self::uuid($mapping['term'])), 'term and TT bind one canonical tag identity');
            $meta = array_values(array_filter($record['physical']['termmeta'], static fn(array $row): bool => $row['term_id'] === (string) $termId && $row['meta_key'] === '_wprism_uuid'));
            self::check($mapping['term'] === null ? $meta === [] : count($meta) === 1 && $meta[0]['meta_value'] === $mapping['term'], 'complete durable metadata agrees with tag ledger');
        }
        return $tags;
    }

    /** Capture/initial adoption may add durable identities, never rewrite existing metadata. */
    public static function identityDelta(array $before, array $after): void {
        $prior = array_column($before['termmeta'], null, 'meta_id');
        $next = array_column($after['termmeta'], null, 'meta_id');
        foreach ($prior as $key => $row) self::check(($next[$key] ?? null) === $row, 'preexisting term metadata is byte-identical');
        $owners = [];
        $uuids = [];
        foreach ($before['termmeta'] as $row) if ($row['meta_key'] === '_wprism_uuid') {
            $owners[] = $row['term_id'];
            $uuids[] = $row['meta_value'];
        }
        foreach (array_diff_key($next, $prior) as $row) {
            self::check($row['meta_key'] === '_wprism_uuid' && self::uuid($row['meta_value'])
                && in_array($row['term_id'], array_column($before['terms'], 'term_id'), true)
                && !in_array($row['term_id'], $owners, true) && !in_array($row['meta_value'], $uuids, true), 'only first durable identities are added to existing native terms');
            $owners[] = $row['term_id'];
            $uuids[] = $row['meta_value'];
        }
    }

    public static function authoredToObserved(array $author, array $observed): void {
        $after = $author['after'];
        $physical = $observed['physical'];
        foreach (['terms', 'term_taxonomy', 'term_relationships'] as $table) self::check($after[$table] === $physical[$table], 'native authored term roster survives until observation');
        self::identityDelta($after, $physical);
        $forms = array_values(array_filter($physical['forms'], static fn(array $form): bool => $form['ID'] === (string) $author['form_id']));
        self::check($forms === $after['forms'], 'native author is bound to the observed complete selected form');
    }

    /** Prove target-local preservation before admitting its one exact compiled signature. */
    public static function native(string $case, array $source, array $before, array $target, array $stable, array $recaptured,
        WPrism\CompiledRepository $sourceRepo, WPrism\CompiledRepository $targetRepo): array {
        $labels = self::labels($case === 'tags' ? 'change' : 'initial');
        $sourceTags = self::observation($source['tags'], $source['posts'], $labels);
        $targetTags = self::observation($target['tags'], $target['posts'], $labels);
        self::observation($stable['tags'], $stable['posts'], $labels);
        self::observation($recaptured['tags'], $recaptured['posts'], $labels);
        self::check(self::ordered(array_map('strval', array_keys($sourceTags))) === self::ordered(self::labels('initial'))
            && self::ordered(array_map('strval', array_keys($targetTags))) === self::ordered([...self::labels('initial'), self::LOCAL]), 'complete source/target native tag inventories');
        self::check($target['tags'] === $stable['tags'], 'tag Apply replay is a physical and native fixed point');
        $prior = $before['tags']['physical'];
        $next = $target['tags']['physical'];
        $beforeTags = self::physical($prior);
        self::check(($beforeTags[self::LOCAL] ?? null) === $targetTags[self::LOCAL]
            && $targetTags[self::LOCAL]['tt']['count'] === '0', 'complete unassigned target-only term and TT rows survive Apply');
        self::check($prior['terms'] === $next['terms'], 'Apply preserves every physical native term row');
        $expectedTT = $prior['term_taxonomy'];
        foreach ($expectedTT as &$tt) if ($tt['taxonomy'] === self::TAXONOMY) {
            $tt['count'] = (string) count(array_filter($next['term_relationships'], static fn(array $row): bool => $row['term_taxonomy_id'] === $tt['term_taxonomy_id']));
        }
        unset($tt);
        self::check($next['term_taxonomy'] === $expectedTT
            && self::ordered($next['term_relationships']) === self::ordered(self::relationships($prior, $target['posts']['integer']['id'], $labels, $targetTags)),
            'Apply preserves complete unrelated term/TT/relationship rows while changing only selected assignments and derived counts');
        self::identityDelta($prior, $next);
        $recapture = $recaptured['tags']['physical'];
        foreach (['terms', 'term_taxonomy', 'term_relationships', 'forms'] as $table) self::check($next[$table] === $recapture[$table], 'Capture changes no native content or term rows');
        self::identityDelta($next, $recapture);
        foreach (self::labels('initial') as $label) {
            $from = $sourceTags[$label];
            $to = $targetTags[$label];
            self::check($from['term']['term_id'] !== $to['term']['term_id'] && $from['tt']['term_taxonomy_id'] !== $to['tt']['term_taxonomy_id']
                && $from['term']['slug'] === $to['term']['slug'], 'native source and target term/TT coordinates independently diverge');
            $uuid = $source['tags']['identities'][$from['term']['term_id']]['term'] ?? null;
            self::check(self::uuid($uuid) && ($target['tags']['identities'][$to['term']['term_id']]['term'] ?? null) === $uuid, 'Apply maps actual source tag identity');
            self::compiledTerm($sourceRepo, $uuid, $from);
            self::compiledTerm($targetRepo, $uuid, $to);
        }
        foreach ([[$sourceRepo, $source, $sourceTags], [$targetRepo, $target, $targetTags]] as [$repository, $observation, $tags]) {
            $row = $repository->tree()[$observation['posts']['integer']['uuid']] ?? null;
            $assigned = [];
            foreach ($labels as $label) $assigned[] = $observation['tags']['identities'][$tags[$label]['term']['term_id']]['term'];
            sort($assigned, SORT_STRING);
            $orders = array_fill_keys($assigned, 0);
            self::check(is_array($row) && $row['type'] === 'post' && $row['data']['type'] === 'wpforms'
                && ($row['data']['terms'] ?? null) === [self::TAXONOMY => $assigned]
                && ($row['data']['term_orders'] ?? null) === [self::TAXONOMY => $orders]
                && (json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR)['settings']['form_tags'] ?? null) === $labels,
                'real compiler binds selected form relationships and literal label values/types/order');
        }
        $local = $targetTags[self::LOCAL];
        $uuid = $recaptured['tags']['identities'][$local['term']['term_id']]['term'] ?? null;
        self::check(self::uuid($uuid) && !isset($sourceRepo->tree()[$uuid]), 'local identity is captured independently and absent from source');
        self::compiledTerm($targetRepo, $uuid, $local);
        return [$uuid => WPrismTest\RepositoryConvergence::signatures($targetRepo)[$uuid]];
    }

    private static function compiledTerm(WPrism\CompiledRepository $repository, string $uuid, array $tag): void {
        $row = $repository->tree()[$uuid] ?? null;
        $expected = ['uuid' => $uuid, 'taxonomy' => self::TAXONOMY, 'name' => $tag['term']['name'], 'slug' => $tag['term']['slug'],
            'description' => '', 'parent' => null, 'meta' => (object) [], 'relationships' => (object) []];
        self::check(is_array($row) && $row['type'] === 'term' && $row['path'] === 'terms/' . self::TAXONOMY . '/' . $uuid . '--' . $tag['term']['slug'] . '.json'
            && $row['content'] === WPrism\Canon::encode($expected), 'entire compiled tag intent is independently bound to native preservation proof');
    }

    public static function uuid(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }

    public static function ordered(array $rows): array {
        $encoded = array_map(static fn($row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        return $encoded;
    }

    public static function id(mixed $id): int {
        self::check(is_string($id) && preg_match('/^[1-9][0-9]{0,8}$/D', $id) === 1, 'bounded native decimal identity');
        return (int) $id;
    }

    public static function keys(array $record, array $expected): void {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        self::check($keys === $expected, 'complete closed native record');
    }

    public static function diagnostics(array $record): void {
        self::keys($record, ['present', 'bytes']);
        self::check(is_bool($record['present']) && $record['bytes'] === '', 'native server has no diagnostic bytes');
    }

    public static function check(bool $condition, string $message): void {
        if (!$condition) throw new RuntimeException('WPForms tag evidence: ' . $message);
    }
}
