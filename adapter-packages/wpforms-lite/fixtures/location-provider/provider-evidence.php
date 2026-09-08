<?php
declare(strict_types=1);

/** Host-owned admission of concrete native output, identity and preservation. */
final class WPFormsLocationProviderEvidence {
    private const FORMS = ['embeds', 'no_locations', 'form_page', 'conversation', 'precedence', 'null_defaults', 'unicode'];
    private const POSTS = ['post_publish', 'post_pending', 'post_draft', 'post_future', 'post_private',
        'page_root', 'page_child', 'cpt_both', 'cpt_public', 'cpt_query', 'template', 'template_part',
        'duplicates', 'excluded_attachment', 'excluded_trash', 'excluded_hidden', 'malformed', 'reusable_only'];

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
            self::check(array_keys($post) === ['id', 'type', 'status', 'title', 'url', 'parser']
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
            $excluded = str_starts_with($name, 'excluded_') || in_array($name, ['malformed', 'reusable_only'], true);
            $parsed = array_values($post['parser']);
            self::check($parsed === (in_array($name, ['malformed', 'reusable_only'], true) ? []
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
                self::check(array_keys($native) === ['id', 'locations', 'html', 'passthrough']
                    && $native['id'] === $seed['native'][$name]['id'] && $native['passthrough'] === 'untouched', 'native consumer identity');
                self::check(self::ordered($locations) === self::ordered($native['locations'] === '' ? [] : $native['locations']),
                    'complete native writer/provider values: ' . $name);
                self::check(is_string($native['html']) && strlen($native['html']) < 65536, 'bounded native UI');
                if ($locations !== []) {
                    self::check(substr_count($native['html'], 'class="wpforms-locations-list-item"') === count($locations), 'native UI complete rows');
                } else self::check($native['html'] === '—', 'initial native absent-location column state');
            }
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
                self::check(array_keys($projection) === ['inputs_sha256', 'remainder_sha256', 'locations_rows', 'locations_sha256']
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

    private static function boot(mixed $boot, bool $child): void {
        self::check(is_array($boot) && array_keys($boot) === ['pid', 'boot', 'child'] && is_int($boot['pid']) && $boot['pid'] > 0
            && is_string($boot['boot']) && preg_match('/^[a-f0-9]{32}$/D', $boot['boot']) === 1 && $boot['child'] === $child, 'observed native process identity');
    }

    private static function check(bool $condition, string $label): void {
        if (!$condition) throw new RuntimeException('WPForms native provider evidence refused: ' . $label);
    }
}

if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc !== 7 || $argv[1] !== '--admit') throw new RuntimeException('expected --admit seed invoke observe repeat stable private stems');
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
    $records = [];
    foreach (array_slice($argv, 2) as $stem) {
        $records[] = json_decode(WPrismTest\PrivateCommandOutput::readObject($stem,
            '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    }
    WPFormsLocationProviderEvidence::verify(...$records);
    echo "WPForms Policy-loaded native provider, two-child observation, UI and fixed-point evidence admitted\n";
}
