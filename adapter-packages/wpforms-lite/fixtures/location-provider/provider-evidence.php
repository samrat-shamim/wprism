<?php
declare(strict_types=1);

/** Host-owned admission of concrete native output, identity and preservation. */
final class WPFormsLocationProviderEvidence {
    private const FORMS = ['embeds', 'no_locations', 'form_page', 'conversation', 'precedence', 'null_defaults', 'unicode'];
    private const POSTS = ['post_publish', 'post_pending', 'post_draft', 'post_future', 'post_private',
        'page_root', 'page_child', 'cpt_both', 'cpt_public', 'cpt_query', 'template', 'template_part',
        'duplicates', 'cross_shortcode', 'excluded_attachment', 'excluded_trash', 'excluded_hidden',
        'malformed', 'wrong_case', 'unquoted', 'reusable_only'];

    public static function verify(array $seed, array $invoke, array $observe, array $repeat, array $stable): void {
        foreach ([[$seed, 'seed', 'positive'], [$invoke, 'invoke', 'positive'], [$observe, 'observe', 'positive'],
            [$repeat, 'invoke', 'repeat'], [$stable, 'observe', 'repeat']] as [$record, $phase, $case]) {
            $fields = match ($phase) {
                'seed' => ['template_standalone', 'native', 'widgets', 'posts', 'physical', 'boot'],
                'invoke' => ['artifact', 'before', 'receipt', 'failure', 'after', 'children', 'boot'],
                'observe' => ['physical', 'native', 'boot'],
            };
            self::check(array_keys($record) === array_merge(['format', 'phase', 'case', 'version', 'home'], $fields), 'closed native record fields');
            self::check(($record['format'] ?? null) === 'wprism-wpforms-native-provider/v1'
                && ($record['version'] ?? null) === '2.0.1.1' && ($record['phase'] ?? null) === $phase
                && ($record['case'] ?? null) === $case && ($record['home'] ?? null) === $seed['home'], 'native record identity');
            self::check(is_string($record['home']) && preg_match('#^http://[a-z][a-z0-9]{2,23}1\.invalid$#D', $record['home']) === 1,
                'owned native current home');
            self::boot($record['boot'], false);
        }
        foreach ([$invoke, $repeat] as $record) {
            self::check($record['failure'] === null && is_array($record['receipt']) && ($record['receipt']['verified'] ?? null) === true,
                'actual artifact-bound provider and fresh observer succeeded');
            $keys = array_keys($record['receipt']);
            sort($keys, SORT_STRING);
            $duration = $record['receipt']['duration_seconds'] ?? null;
            self::check($keys === ['after', 'before', 'duration_seconds', 'verified']
                && (is_int($duration) || is_float($duration)) && is_finite((float) $duration) && $duration >= 0 && $duration <= 600,
                'exact normal invoker receipt and bounded duration');
        }
        self::check(array_keys($seed['native']) === self::FORMS && array_keys($seed['posts']) === self::POSTS,
            'complete native fixture roster');
        $form = $seed['native']['embeds']['id'];
        self::check(is_int($form) && $form > 0 && $seed['template_standalone'] === [], 'native template standalone exclusion');
        $expected = [];
        foreach (self::FORMS as $name) $expected[$name] = [];
        foreach ($seed['posts'] as $name => $post) {
            self::check(array_keys($post) === ['id', 'type', 'status', 'writer_status', 'title', 'url', 'parser']
                && is_int($post['id']) && $post['id'] > 0 && $post['title'] === 'WPrism ' . $name, 'native placement shape');
            $type = match ($name) {
                'post_publish', 'post_pending', 'post_draft', 'post_future', 'post_private' => 'post',
                'cpt_both' => 'wpf_both', 'cpt_public' => 'wpf_public', 'cpt_query' => 'wpf_query',
                'template' => 'wp_template', 'template_part' => 'wp_template_part',
                'excluded_attachment' => 'attachment', 'excluded_hidden' => 'wpf_hidden', default => 'page',
            };
            $status = match ($name) {
                'post_pending' => 'pending', 'post_draft' => 'draft', 'post_future' => 'future',
                'post_private' => 'private', 'excluded_trash' => 'trash', default => 'publish',
            };
            self::check($post['type'] === $type && $post['status'] === $status, 'native type/status discriminator: ' . $name);
            self::check($post['writer_status'] === ($name === 'excluded_attachment' ? 'inherit' : $status),
                'native writer status and explicit dirty attachment provenance: ' . $name);
            $physicalPosts = array_values(array_filter($seed['physical']['inputs']['posts'],
                static fn(array $row): bool => (int) $row['ID'] === $post['id']));
            self::check(count($physicalPosts) === 1 && $physicalPosts[0]['post_type'] === $type
                && $physicalPosts[0]['post_status'] === $status && $physicalPosts[0]['post_title'] === $post['title'],
                'type/status discriminator bound to actual physical row: ' . $name);
            $unparsed = in_array($name, ['malformed', 'wrong_case', 'unquoted', 'reusable_only'], true);
            $excluded = str_starts_with($name, 'excluded_') || $unparsed;
            $parsed = array_values($post['parser']);
            self::check($parsed === ($unparsed ? []
                : ($name === 'duplicates' ? [$form, $form] : [$form])), 'native parser discriminator: ' . $name);
            if ($excluded) continue;
            self::check($post['url'] === false || (is_string($post['url']) && str_starts_with($post['url'], $seed['home'])), 'native current-home URL');
            $expected['embeds'][] = ['type' => $post['type'], 'title' => $post['title'], 'form_id' => $form,
                'id' => $post['id'], 'status' => $post['status'],
                'url' => $post['url'] === false ? '' : substr($post['url'], strlen($seed['home']))];
        }
        self::check(array_column($seed['widgets'], 'id') === ['wpforms-widget-2', 'text-2', 'text-3', 'text-4', 'block-2'], 'all native widget families and orphan');
        foreach ($seed['widgets'] as $widget) {
            self::check(array_keys($widget) === ['type', 'title', 'form_id', 'id'] && $widget['type'] === 'widget'
                && $widget['form_id'] === ($widget['id'] === 'wpforms-widget-2' ? (string) $form : $form)
                && is_string($widget['title']), 'native widget value and ID type');
            $expected['embeds'][] = $widget;
        }
        foreach (['form_page' => ['form_pages', 'Standalone page', '/standalone-page/'],
            'conversation' => ['conversational_forms', 'Conversation', '/conversation/'],
            'precedence' => ['form_pages', 'First enabled', '/%41%7a/'], 'null_defaults' => ['form_pages', '', '//'],
            'unicode' => ['form_pages', '東京', '/%E6%9D%B1%E4%BA%AC/']] as $name => [$type, $title, $url]) {
            $id = $seed['native'][$name]['id'];
            self::check(is_int($id) && $id > 0 && $id !== $form, 'standalone native form identity');
            $expected[$name][] = ['type' => $type, 'title' => $title, 'form_id' => $id, 'id' => $id, 'status' => 'publish', 'url' => $url];
        }
        foreach ([$seed, $observe, $stable] as $record) {
            self::check(array_keys($record['native']) === self::FORMS, 'complete native consumer roster');
            foreach ($expected as $name => $locations) {
                $native = $record['native'][$name];
                $fields = $record['phase'] === 'seed' ? ['id', 'locations'] : ['id', 'locations', 'html', 'passthrough'];
                self::check(array_keys($native) === $fields && $native['id'] === $seed['native'][$name]['id'], 'native writer/consumer identity');
                self::check(self::ordered($locations) === self::ordered($native['locations'] === '' ? [] : $native['locations']),
                    'complete native writer/provider values: ' . $name);
                if ($record['phase'] === 'seed') continue;
                self::check($native['passthrough'] === 'untouched', 'unrelated native column passthrough');
                self::check(is_string($native['html']) && strlen($native['html']) < 65536, 'bounded native UI');
                if ($locations !== []) {
                    self::check(substr_count($native['html'], 'class="wpforms-locations-list-item"') === count($locations), 'native UI complete rows');
                } else self::check($native['html'] === '—', 'initial native absent-location column state');
            }
            if ($record['phase'] === 'seed') continue;
            foreach (['WPrism locator sidebar: WPForms Widget', 'WPrism locator sidebar: Text Widget', 'Inactive widgets: Inactive', '(no title)', 'Site editor template: WPrism template',
                'Site editor template: WPrism template_part'] as $text) {
                self::check(str_contains($record['native']['embeds']['html'], $text), 'native UI discriminator: ' . $text);
            }
            $privateUrl = $seed['posts']['post_private']['url'];
            self::check(is_string($privateUrl) && !str_contains($record['native']['embeds']['html'],
                'href="' . htmlspecialchars($privateUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" target="_blank"'), 'anonymous private placement has no public link');
            foreach (['precedence' => '/Az/', 'unicode' => '/東京/'] as $name => $suffix) {
                self::check(str_contains($record['native'][$name]['html'], 'href="' . $seed['home'] . $suffix . '" target="_blank"'),
                    'native whole-markup renderer target: ' . $name);
            }
        }
        self::check($observe['native'] === $stable['native'], 'fresh native consumer values and markup survive retry');
        self::check($seed['physical'] === $invoke['before'], 'first provider boot sees the seeded complete physical state');
        self::check($invoke['after'] === $observe['physical'] && $observe['physical'] === $repeat['before']
            && $repeat['before'] === $repeat['after'] && $repeat['after'] === $stable['physical'], 'independent boots and retry retain exact physical fixed point');
        self::check($invoke['before']['owned'] !== $invoke['after']['owned'], 'stale duplicate/orphan rows were actually repaired');
        foreach ([$invoke, $repeat] as $record) {
            self::check(array_keys($record['before']) === ['inputs', 'remainder', 'owned']
                && array_keys($record['before']['inputs']) === ['posts', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta'],
                'complete independent physical witness');
            self::check($record['before']['inputs'] === $record['after']['inputs']
                && $record['before']['remainder'] === $record['after']['remainder'], 'all input and nonowned rows preserved');
            self::check($record['failure'] === null && is_array($record['receipt']) && $record['receipt']['verified'] === true,
                'actual artifact-bound provider and fresh observer succeeded');
            self::check(count($record['children']) === 2, 'exactly two engine child boots');
            foreach ($record['children'] as $child) {
                self::boot($child, true);
                self::check($child['pid'] !== $record['boot']['pid'], 'child is not the invoking process');
            }
            self::check($record['children'][0]['pid'] !== $record['children'][1]['pid']
                && $record['children'][0]['boot'] !== $record['children'][1]['boot'], 'independent mutation and observer processes');
            $artifact = $record['artifact'];
            $keys = array_keys($artifact);
            sort($keys, SORT_STRING);
            self::check($keys === ['artifact_hash', 'manifest_hash', 'resolved_adapters_sha256', 'site_hash'], 'real compiled authority shape');
            foreach ($artifact as $digest) self::check(is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1, 'compiled authority digest');
        }
        self::check($invoke['artifact'] === $repeat['artifact'], 'unchanged private inputs recompile to the same authority');
        $owned = $invoke['after']['owned'];
        self::check(count($owned) === 6 && count(array_unique(array_column($owned, 'post_id'))) === 6, 'one row per actually located form');
        foreach ($expected as $name => $values) {
            $id = $seed['native'][$name]['id'];
            $rows = array_values(array_filter($owned, static fn(array $row): bool => (int) $row['post_id'] === $id));
            self::check($values === [] ? $rows === [] : (count($rows) === 1 && $rows[0]['meta_value'] === serialize($values)),
                'physical raw postimage equals complete expected native values: ' . $name);
        }
        foreach ([$invoke, $repeat] as $record) {
            foreach (['before', 'after'] as $side) {
                $projection = $record['receipt'][$side];
                $keys = array_keys($projection);
                sort($keys, SORT_STRING);
                self::check($keys === ['inputs_sha256', 'locations_rows', 'locations_sha256', 'remainder_sha256']
                    && $projection['locations_rows'] === count($record[$side]['owned'])
                    && $projection['locations_sha256'] === hash('sha256', serialize($record[$side]['owned']))
                    && $projection['remainder_sha256'] === hash('sha256', serialize($record[$side]['remainder'])),
                    'provider owned/remainder receipt is bound to independent physical rows');
                self::check(is_string($projection['inputs_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $projection['inputs_sha256']) === 1,
                    'bounded provider input projection digest');
            }
            self::check($record['receipt']['before']['inputs_sha256'] === $record['receipt']['after']['inputs_sha256'],
                'provider projection independently claims unchanged inputs');
        }
        $old = array_values(array_filter($invoke['before']['owned'], static fn(array $row): bool => (int) $row['post_id'] === $form));
        $new = array_values(array_filter($owned, static fn(array $row): bool => (int) $row['post_id'] === $form));
        self::check(count($old) === 2 && count($new) === 1 && $new[0]['meta_id'] === $old[0]['meta_id'], 'oldest owned identity survives duplicate reconciliation');
    }

    private static function ordered(array $rows): array {
        usort($rows, static fn(array $a, array $b): int => strcmp(serialize($a), serialize($b)));
        return $rows;
    }

    /** The positive baseline is re-admitted, not a caller-supplied success flag. */
    public static function verifyRefusal(string $case, array $positive, array $prepared, array $invoke, array $observed, array $restored): void {
        self::check(array_is_list($positive) && count($positive) === 5, 'complete positive baseline for refusal evidence');
        self::verify(...$positive);
        [$seed, $accepted, , , $stable] = $positive;
        $message = match ($case) {
            'missing-embed', 'nonform-embed', 'template-embed' => 'wprism: WPForms locations a native placement references a missing or non-form post',
            'malformed-body' => 'wprism: WPForms location source has malformed form JSON',
            'null-widget-title' => 'wprism: WPForms locations native location text is malformed or over the bounded frontier',
            'unsafe-standalone-uri' => 'wprism: WPForms locations native URL is outside the current-home renderer frontier',
            default => throw new RuntimeException('WPForms native provider evidence refused: undeclared refusal case'),
        };
        foreach ([[$prepared, 'refusal-seed', ['before', 'mutation', 'parser', 'physical']],
            [$invoke, 'invoke', ['artifact', 'before', 'receipt', 'failure', 'after', 'children']],
            [$observed, 'physical', ['physical']], [$restored, 'refusal-restore', ['before', 'physical']]] as [$record, $phase, $fields]) {
            self::check(array_keys($record) === array_merge(['format', 'phase', 'case', 'version', 'home'], $fields, ['boot'])
                && $record['format'] === $seed['format'] && $record['version'] === $seed['version']
                && $record['home'] === $seed['home'] && $record['phase'] === $phase && $record['case'] === $case,
                'closed source-scoped refusal phase');
            self::boot($record['boot'], false);
        }
        $boots = array_column(array_column([$prepared, $invoke, $observed, $restored], 'boot'), 'boot');
        self::check(count(array_unique($boots)) === 4, 'independent refusal preparation, invocation, readback and restoration boots');
        $baseline = $stable['physical'];
        self::check($prepared['before'] === $baseline, 'refusal begins at the proven complete fixed point');
        $property = $case === 'null-widget-title' ? 'options' : 'posts';
        $identity = $property === 'posts' ? 'ID' : 'option_id';
        $column = $property === 'posts' ? 'post_content' : 'option_value';
        $target = match ($case) {
            'malformed-body' => $seed['native']['no_locations']['id'],
            'unsafe-standalone-uri' => $seed['native']['form_page']['id'],
            default => $seed['posts']['post_publish']['id'],
        };
        $indexes = [];
        foreach ($baseline['inputs'][$property] as $index => $row) {
            if ($property === 'posts' ? (int) $row['ID'] === $target : $row['option_name'] === 'widget_wpforms-widget') $indexes[] = $index;
        }
        self::check(count($indexes) === 1, 'one independently selected dirty input');
        $index = $indexes[0];
        $row = $baseline['inputs'][$property][$index];
        $parsed = null;
        if (str_ends_with($case, '-embed')) {
            $reference = $case === 'missing-embed' ? 2147483646 : $target;
            if ($case === 'template-embed') {
                $templates = array_values(array_filter($baseline['inputs']['posts'], static fn(array $post): bool =>
                    $post['post_type'] === 'wpforms-template' && $post['post_title'] === 'WPrism template exclusion'));
                self::check(count($templates) === 1, 'one real native form template discriminator');
                $reference = (int) $templates[0]['ID'];
            } elseif ($case === 'missing-embed') {
                self::check(array_filter($baseline['inputs']['posts'], static fn(array $post): bool => (int) $post['ID'] === $reference) === [],
                    'missing form discriminator really has no physical post');
            }
            $new = '[wpforms id="' . $reference . '"]';
            $parsed = [$reference];
        } elseif ($case === 'null-widget-title') {
            $widget = [2 => ['form_id' => (string) $seed['native']['embeds']['id'], 'title' => ''], '_multiwidget' => 1];
            self::check($row[$column] === serialize($widget), 'null title is the only changed widget coordinate');
            $widget[2]['title'] = null;
            $new = serialize($widget);
        } else {
            $data = json_decode($row[$column], true, 64, JSON_THROW_ON_ERROR);
            self::check(is_array($data) && ($data['id'] ?? null) === $target, 'hostile body starts as the native form identified by this case');
            $new = '{';
            if ($case === 'unsafe-standalone-uri') {
                self::check(($data['settings']['form_pages_enable'] ?? null) === true
                    && ($data['settings']['form_pages_page_slug'] ?? null) === 'standalone-page', 'standalone slug is the isolated enabled coordinate');
                $data['settings']['form_pages_page_slug'] = 'a%3Fb';
                $new = json_encode($data, JSON_THROW_ON_ERROR);
            }
        }
        self::check(is_string($row[$column]) && $row[$column] !== $new && $prepared['parser'] === $parsed,
            'actual changed input and independent native parser discriminator');
        $mutation = ['table' => $property, 'identity' => [$identity => $row[$identity]], 'column' => $column,
            'before' => $row[$column], 'after' => $new];
        self::check($prepared['mutation'] === $mutation, 'one exact case-owned mutation, never a reset or unrelated failure');
        $dirty = $baseline;
        $dirty['inputs'][$property][$index][$column] = $new;
        self::check($prepared['physical'] === $dirty && $invoke['before'] === $dirty && $invoke['after'] === $dirty
            && $observed['physical'] === $dirty && $restored['before'] === $dirty && $restored['physical'] === $baseline,
            'every input, nonowned and owned row survives refusal, fresh readback and exact one-cell restoration');
        self::check($invoke['artifact'] === $accepted['artifact'] && $invoke['receipt'] === null && is_array($invoke['failure'])
            && is_array($invoke['children']) && array_is_list($invoke['children']) && count($invoke['children']) === 1,
            'real unchanged compiled authority refused in exactly one mutation child without a success observer');
        self::boot($invoke['children'][0], true);
        self::check($invoke['children'][0]['pid'] !== $invoke['boot']['pid']
            && !in_array($invoke['children'][0]['boot'], $boots, true), 'failed child is not any fixture parent');
        $outer = $invoke['failure'];
        self::check(is_array($outer['throwable'] ?? null) && array_is_list($outer['throwable']) && count($outer['throwable']) === 7,
            'complete normal provider failure transport topology');
        $reports = [];
        foreach ($outer['throwable'] as $node) {
            if (($node['message_encoding'] ?? null) !== 'utf-8' || ($node['message_truncated'] ?? null) !== false
                || !is_string($node['message'] ?? null)) continue;
            $report = json_decode($node['message'], true, 32);
            if (is_array($report) && ($report['format'] ?? null) === 'wprism-provider-operation-failure/v1') {
                $reports[] = ['bytes' => $node['message'], 'report' => $report];
            }
        }
        self::check(count($reports) === 1, 'one complete private child failure report, not a public wrapper match');
        ['bytes' => $bytes, 'report' => $report] = $reports[0];
        $keys = array_keys($report);
        sort($keys, SORT_STRING);
        self::check($keys === ['evidence', 'format', 'request_sha256'] && is_array($report['evidence'])
            && is_string($report['request_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $report['request_sha256']) === 1,
            'closed bounded private child failure envelope');
        require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
        $inner = [['parent_index' => null, 'relation' => 'root', 'class' => RuntimeException::class, 'message' => $message]];
        if ($case === 'malformed-body') $inner[] = ['parent_index' => 0, 'relation' => 'previous', 'class' => JsonException::class, 'message' => 'Syntax error'];
        WPrismTest\PrivateRefusalReceipt::assertGraph($report['evidence'], $inner);
        $node = static fn(?int $parent, string $class, string $message): array => ['parent_index' => $parent,
            'relation' => $parent === null ? 'root' : 'private_evidence', 'class' => $class, 'message' => $message];
        WPrismTest\PrivateRefusalReceipt::assertGraph($outer, [
            $node(null, 'WPrism\\PrivateEvidenceException', "wprism: provider 'wpforms-form-locations' capability 'rebuild_form_locations' failed"),
            $node(0, 'WPrism\\PrivateEvidenceException', 'wprism: manifest-provider fresh process did not complete cleanly; recovery_required'),
            $node(1, RuntimeException::class, 'wprism: child process return_code=1'),
            $node(1, 'WPrism\\PrivateEvidenceException', 'wprism: child process stdout'),
            $node(1, 'WPrism\\PrivateEvidenceException', 'wprism: child process stderr'),
            $node(3, RuntimeException::class, $bytes),
            $node(4, RuntimeException::class, "wprism-provider-operation-failed\n"),
        ]);
    }

    private static function boot(mixed $boot, bool $child): void {
        self::check(is_array($boot) && array_keys($boot) === ['pid', 'boot', 'child'] && is_int($boot['pid']) && $boot['pid'] > 0
            && is_string($boot['boot']) && preg_match('/^[a-f0-9]{32}$/D', $boot['boot']) === 1 && $boot['child'] === $child, 'observed native process identity');
    }

    private static function check(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException('WPForms native provider evidence refused: ' . $label);
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $refusal = ($argv[1] ?? '') === '--admit-refusal';
    if ($refusal ? $argc !== 12 : ($argc !== 7 || $argv[1] !== '--admit')) {
        throw new RuntimeException('expected --admit five positive stems, or --admit-refusal case five positive and four refusal private stems');
    }
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
    $records = [];
    foreach (array_slice($argv, $refusal ? 3 : 2) as $stem) {
        $records[] = json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
            '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    }
    if ($refusal) {
        WPFormsLocationProviderEvidence::verifyRefusal($argv[2], array_slice($records, 0, 5), ...array_slice($records, 5));
        echo "WPForms native provider refusal and complete physical preservation admitted: " . $argv[2] . "\n";
    } else {
        WPFormsLocationProviderEvidence::verify(...$records);
        echo "WPForms Policy-loaded native provider, two-child observation, UI and fixed-point evidence admitted\n";
    }
}
