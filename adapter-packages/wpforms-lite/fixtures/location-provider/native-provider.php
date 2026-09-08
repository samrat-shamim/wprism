<?php
declare(strict_types=1);

// Actual native scanner/writer/consumer + Policy/compiler/provider evidence.
// No candidate include, reflection binding, replacement scanner or launcher.
require_once __DIR__ . '/provider-library.php';
$phase = $args[0] ?? '';
$case = $args[1] ?? 'positive';
$refusalCases = ['missing-embed', 'nonform-embed', 'template-embed', 'malformed-body', 'null-widget-title', 'unsafe-standalone-uri'];
$proof = WP_CONTENT_DIR . '/wprism-wpforms-provider-proof';
$check = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('WPForms provider native fixture: ' . $label);
};
$check(defined('WPFORMS_VERSION') && WPFORMS_VERSION === '2.0.1.1', 'wrong exact native plugin');
$locator = wpforms()->obj('locator');
$check(is_object($locator) && get_class($locator) === 'WPForms\\Forms\\Locator', 'initialized native Locator required');
$save = static function (string $name, array $value) use ($proof, $check): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    $check(file_put_contents($proof . '/' . $name . '.json', $bytes) === strlen($bytes)
        && chmod($proof . '/' . $name . '.json', 0600), 'private fixture record');
};
$load = static fn(string $name): array => json_decode((string) file_get_contents($proof . '/' . $name . '.json'), true, 64, JSON_THROW_ON_ERROR);
$physical = static function () use ($check): array {
    global $wpdb;
    $tables = [];
    foreach (['posts' => 'ID', 'options' => 'option_id', 'terms' => 'term_id', 'term_taxonomy' => 'term_taxonomy_id',
        'term_relationships' => 'object_id,term_taxonomy_id', 'termmeta' => 'meta_id', 'users' => 'ID',
        'usermeta' => 'umeta_id', 'postmeta' => 'meta_id'] as $property => $order) {
        $rows = $wpdb->get_results("SELECT * FROM `{$wpdb->{$property}}` ORDER BY $order", ARRAY_A);
        $check(is_array($rows) && $wpdb->last_error === '' && count($rows) <= 8192, 'complete independent physical read');
        $tables[$property] = $rows;
    }
    $owned = $remainder = [];
    foreach ($tables['postmeta'] as $row) {
        if ($row['meta_key'] === 'wpforms_form_locations') $owned[] = $row;
        else $remainder[] = $row;
    }
    unset($tables['postmeta']);
    return ['inputs' => $tables, 'remainder' => $remainder, 'owned' => $owned];
};
$locations = static function (array $forms, bool $render) use ($locator): array {
    $out = [];
    foreach ($forms as $name => $id) {
        $out[$name] = ['id' => $id, 'locations' => get_post_meta($id, 'wpforms_form_locations', true)];
        if ($render) {
            $out[$name]['html'] = $locator->column_value('', get_post($id), WPForms\Forms\Locator::COLUMN_NAME);
            $out[$name]['passthrough'] = $locator->column_value('untouched', get_post($id), 'not-locations');
        }
    }
    return $out;
};
$record = ['format' => 'wprism-wpforms-native-provider/v1', 'phase' => $phase, 'case' => $case,
    'version' => WPFORMS_VERSION, 'home' => home_url()];
if ($phase === 'setup') {
    $check(current_user_can('manage_options'), 'setup administrator required');
    $check(!file_exists($proof) && !is_link($proof) && mkdir($proof, 0700), 'unoccupied private proof root');
    // The sandbox owns a non-writable MU mount root. Install this disposable
    // fixture through ordinary plugin activation, never relax that boundary
    // or borrow root privileges merely to arrange a passing WordPress boot.
    $plugin = 'wprism-wpforms-provider-fixture/wprism-wpforms-provider-fixture.php';
    $bootstrap = WP_PLUGIN_DIR . '/' . $plugin;
    $check(!file_exists(dirname($bootstrap)) && !is_link(dirname($bootstrap)) && mkdir(dirname($bootstrap), 0700), 'unoccupied fixture plugin');
    $bytes = "<?php\n/* Plugin Name: WPrism WPForms provider fixture\nVersion: 1.0.0\n*/\nrequire_once WPMU_PLUGIN_DIR . '/adapter-packages/wpforms-lite/fixtures/location-provider/native-provider-boot.php';\n";
    $check(file_put_contents($bootstrap, $bytes) === strlen($bytes) && chmod($bootstrap, 0600), 'owned fixture bootstrap');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    // Native installation invalidates discovery before activation; a plugin
    // list cached earlier in this setup boot cannot know the new fixture file.
    wp_clean_plugins_cache(false);
    $activated = activate_plugin($plugin, '', false, true);
    $check(!is_wp_error($activated) && is_plugin_active($plugin), 'ordinary fixture plugin activation');
    WPFormsLocationProviderLibrary::create(dirname(__DIR__, 4), $proof . '/library');
    $check(mkdir($proof . '/repo/state', 0700, true), 'private empty repository');
    WPrism\Canon::write_file($proof . '/repo/site.wprism.json', WPrism\Canon::encode([
        'manifests' => ['wpforms-lite'], 'spec_version' => 3,
        'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    ]));
    $settings = [
        'embeds' => [], 'no_locations' => [],
        'form_page' => ['form_pages_enable' => true, 'form_pages_title' => 'Standalone page', 'form_pages_page_slug' => 'standalone-page'],
        'conversation' => ['conversational_forms_enable' => 1, 'conversational_forms_title' => 'Conversation', 'conversational_forms_page_slug' => 'conversation'],
        'precedence' => ['form_pages_enable' => ' ', 'form_pages_title' => 'First enabled', 'form_pages_page_slug' => '%41%7a',
            'conversational_forms_enable' => true, 'conversational_forms_title' => ['not consumed'], 'conversational_forms_page_slug' => ['not consumed']],
        'null_defaults' => ['form_pages_enable' => true, 'form_pages_title' => null, 'form_pages_page_slug' => null],
        'unicode' => ['form_pages_enable' => true, 'form_pages_title' => '東京', 'form_pages_page_slug' => '%E6%9D%B1%E4%BA%AC'],
    ];
    $forms = [];
    foreach ($settings as $name => $values) {
        $id = wpforms()->obj('form')->add('WPrism ' . $name, ['post_name' => 'wprism-' . str_replace('_', '-', $name)], ['builder' => false]);
        $check(is_int($id) && $id > 0, 'native form creation: ' . $name);
        $data = json_decode(get_post($id)->post_content, true, 64, JSON_THROW_ON_ERROR);
        $data['id'] = $id;
        $data['settings'] = array_merge($data['settings'], $values);
        $check(wp_update_post(['ID' => $id, 'post_content' => wp_slash(wp_json_encode($data))], true) === $id, 'native form content fixture');
        $forms[$name] = $id;
    }
    $template = wp_insert_post(['post_type' => 'wpforms-template', 'post_status' => 'publish',
        'post_title' => 'WPrism template exclusion', 'post_name' => 'wprism-template-exclusion',
        'post_content' => wp_slash(wp_json_encode(['settings' => ['form_pages_enable' => true, 'form_pages_page_slug' => 'excluded']]))], true);
    $check(is_int($template) && $template > 0, 'native form template');
    $save('fixture', ['forms' => $forms, 'template' => $template]);
    $record['forms'] = $forms;
} else {
    $check(get_current_user_id() === 0, 'provider and observation boots must be ordinary anonymous WordPress');
    $fixture = $load('fixture');
    $forms = $fixture['forms'];
    if ($phase === 'seed') {
        $id = $forms['embeds'];
        $shortcode = '[wpforms id="' . $id . '"]';
        $block = '<!-- wp:wpforms/form-selector {"formId":"' . $id . '"} /-->';
        $vectors = [
            'post_publish' => ['post', 'publish', $shortcode], 'post_pending' => ['post', 'pending', $shortcode],
            'post_draft' => ['post', 'draft', $shortcode], 'post_future' => ['post', 'future', $shortcode],
            'post_private' => ['post', 'private', $shortcode], 'page_root' => ['page', 'publish', $block],
            'page_child' => ['page', 'publish', $shortcode], 'cpt_both' => ['wpf_both', 'publish', $shortcode],
            'cpt_public' => ['wpf_public', 'publish', $shortcode], 'cpt_query' => ['wpf_query', 'publish', $shortcode],
            'template' => ['wp_template', 'publish', $block], 'template_part' => ['wp_template_part', 'publish', $shortcode],
            'duplicates' => ['page', 'publish', $shortcode . $shortcode . '[wpforms id="0' . $id . '"]' . $block],
            // Locator's greedy lexical parser crosses adjacent bracket pairs.
            // Individually malformed controls below are not equivalent to
            // concatenating them into a string its public parser recognizes.
            'cross_shortcode' => ['page', 'publish', "[wpforms id='$id'] [WPFORMS id=\"$id\"] [wpforms id=$id]"],
            'excluded_attachment' => ['attachment', 'publish', $shortcode], 'excluded_trash' => ['page', 'trash', $shortcode],
            'excluded_hidden' => ['wpf_hidden', 'publish', $shortcode],
            'malformed' => ['page', 'publish', "[wpforms id='$id']"],
            'wrong_case' => ['page', 'publish', '[WPFORMS id="' . $id . '"]'],
            'unquoted' => ['page', 'publish', '[wpforms id=' . $id . ']'],
            'reusable_only' => ['page', 'publish', '<!-- wp:block {"ref":' . $id . '} /-->'],
        ];
        $posts = [];
        foreach ($vectors as $name => [$type, $status, $content]) {
            $data = ['post_type' => $type, 'post_status' => $status, 'post_title' => 'WPrism ' . $name,
                'post_name' => 'wprism-' . str_replace('_', '-', $name), 'post_content' => $content,
                'post_date' => $status === 'future' ? '2099-01-02 03:04:05' : '2026-09-01 03:04:05'];
            if ($name === 'page_child') $data['post_parent'] = $posts['page_root']['id'];
            $post = wp_insert_post(wp_slash($data), true);
            $check(is_int($post) && $post > 0, 'native placement creation: ' . $name);
            $writerStatus = get_post($post)->post_status;
            if ($name === 'excluded_attachment') {
                // WP 7.1 coerces native attachment creation to inherit. An
                // explicitly dirty published row isolates the candidate's
                // type exclusion from its status exclusion; record both.
                global $wpdb;
                $check($writerStatus === 'inherit' && $wpdb->update($wpdb->posts,
                    ['post_status' => 'publish'], ['ID' => $post]) === 1, 'dirty published attachment fixture');
                clean_post_cache($post);
            }
            $posts[$name] = ['id' => $post, 'type' => $type, 'status' => get_post($post)->post_status, 'writer_status' => $writerStatus,
                'title' => get_post($post)->post_title, 'url' => get_permalink($post), 'parser' => $locator->get_form_ids($content)];
        }
        update_option('sidebars_widgets', ['wprism-locator' => ['wpforms-widget-2', 'text-2', 'block-2'],
            'wp_inactive_widgets' => ['text-3'], 'array_version' => 3]);
        foreach (['widget_wpforms-widget' => [2 => ['form_id' => (string) $id, 'title' => '']],
            'widget_text' => [2 => ['text' => $shortcode, 'title' => ''], 3 => ['text' => $shortcode, 'title' => 'Inactive'],
                4 => ['text' => $shortcode, 'title' => 'Orphan']],
            'widget_block' => [2 => ['content' => $block]]] as $option => $value) {
            // The public writer listens to update_option, not add_option.
            update_option($option, []);
            update_option($option, $value + ['_multiwidget' => 1]);
        }
        foreach ($forms as $form) {
            $data = json_decode(get_post($form)->post_content, true, 64, JSON_THROW_ON_ERROR);
            $locator->add_standalone_location_to_locations_meta($form, $data);
        }
        $record['template_standalone'] = $locator->build_standalone_location($fixture['template'],
            ['id' => $fixture['template'], 'settings' => ['form_pages_enable' => true, 'form_pages_page_slug' => 'excluded']]);
        // update_option does not refresh WP's warm sidebar globals. Compare
        // native writer values here; complete UI belongs to fresh observers,
        // not a fixture-only cache reset disguised as a normal consumer boot.
        $record['native'] = $locations($forms, false);
        $record['widgets'] = $locator->search_in_widgets();
        $record['posts'] = $posts;
        $fixture['posts'] = $posts;
        $save('fixture', $fixture);
        foreach ($forms as $form) delete_post_meta($form, 'wpforms_form_locations');
        foreach ([$id, $id, $forms['no_locations']] as $form) add_post_meta($form, 'wpforms_form_locations', ['stale']);
        global $wpdb;
        foreach ([0, 2147483646] as $orphan) {
            $check($wpdb->insert($wpdb->postmeta, ['post_id' => $orphan, 'meta_key' => 'wpforms_form_locations', 'meta_value' => 'stale-orphan']) === 1, 'orphan fixture row');
        }
        add_post_meta($id, 'wprism_location_unrelated', wp_slash("unrelated \\ exact ' bytes"));
        $record['physical'] = $physical();
    } elseif ($phase === 'refusal-seed') {
        $check(in_array($case, $refusalCases, true) && !file_exists($proof . '/refusal-restore.json'), 'isolated declared refusal case');
        $before = $physical();
        $property = $case === 'null-widget-title' ? 'options' : 'posts';
        $identity = $property === 'posts' ? 'ID' : 'option_id';
        $column = $property === 'posts' ? 'post_content' : 'option_value';
        $target = match ($case) {
            'malformed-body' => $forms['no_locations'], 'unsafe-standalone-uri' => $forms['form_page'],
            default => $fixture['posts']['post_publish']['id'],
        };
        $rows = array_values(array_filter($before['inputs'][$property], static fn(array $row): bool => $property === 'posts'
            ? (int) $row['ID'] === $target : $row['option_name'] === 'widget_wpforms-widget'));
        $check(count($rows) === 1, 'one exact physical mutation target');
        $row = $rows[0];
        $new = match ($case) {
            'missing-embed' => '[wpforms id="2147483646"]',
            'nonform-embed' => '[wpforms id="' . $target . '"]',
            'template-embed' => '[wpforms id="' . $fixture['template'] . '"]',
            'malformed-body' => '{',
            'null-widget-title' => serialize([2 => ['form_id' => (string) $forms['embeds'], 'title' => null], '_multiwidget' => 1]),
            default => '',
        };
        if ($case === 'unsafe-standalone-uri') {
            $data = json_decode($row[$column], true, 64, JSON_THROW_ON_ERROR);
            $data['settings']['form_pages_page_slug'] = 'a%3Fb';
            $new = json_encode($data, JSON_THROW_ON_ERROR);
        }
        $check($new !== $row[$column], 'hostile input actually differs');
        $mutation = ['table' => $property, 'identity' => [$identity => $row[$identity]],
            'column' => $column, 'before' => $row[$column], 'after' => $new];
        $record['before'] = $before;
        $record['mutation'] = $mutation;
        $record['parser'] = str_ends_with($case, '-embed') ? $locator->get_form_ids($new) : null;
        $save('refusal-restore', ['case' => $case, 'mutation' => $mutation, 'baseline_sha256' => hash('sha256', serialize($before))]);
        // These malformed bytes are deliberate dirty-state fixtures, not
        // claimed public-writer output. Fresh invocations need no fixture
        // cache warm-up; restoration is one exact cell, never a DB reset.
        global $wpdb;
        $check($wpdb->update($wpdb->{$property}, [$column => $new], $mutation['identity']) === 1, 'one dirty input cell');
        $record['physical'] = $physical();
    } elseif ($phase === 'refusal-restore') {
        $restore = $load('refusal-restore');
        $check(in_array($case, $refusalCases, true) && $restore['case'] === $case, 'restore the active case only');
        $record['before'] = $physical();
        $mutation = $restore['mutation'];
        $property = $mutation['table'];
        $column = $mutation['column'];
        $identity = array_key_first($mutation['identity']);
        $rows = array_values(array_filter($record['before']['inputs'][$property],
            static fn(array $row): bool => $row[$identity] === $mutation['identity'][$identity]));
        $check(count($rows) === 1 && $rows[0][$column] === $mutation['after'], 'do not overwrite a divergent fixture input');
        global $wpdb;
        $check($wpdb->update($wpdb->{$property}, [$column => $mutation['before']], $mutation['identity']) === 1, 'restore the one dirty input cell');
        $record['physical'] = $physical();
        $check(hash('sha256', serialize($record['physical'])) === $restore['baseline_sha256'], 'complete physical state restored');
        $check(unlink($proof . '/refusal-restore.json'), 'consume the exact private restore record');
    } elseif ($phase === 'invoke') {
        $check($case === 'positive' || $case === 'repeat' || in_array($case, $refusalCases, true), 'declared invocation case');
        $library = WPrism\AdapterLibrary::fromSourcePackage($proof . '/library', 'wpforms-lite');
        [$policy, $compiled] = WPFormsLocationProviderLibrary::compile_and_load($proof . '/repo', $library);
        $actions = $policy->actions_for(['post:wpforms']);
        $negotiated = WPrism\Providers::negotiate($policy, $actions);
        $check($negotiated['problems'] === [] && count($actions) === 1, 'normal native provider negotiation: ' . wp_json_encode($negotiated['problems']));
        $record['artifact'] = $policy->execution_artifact_identity();
        $record['before'] = $physical();
        $bootOffset = count(file($proof . '/boots.jsonl'));
        try {
            $record['receipt'] = WPrism\Providers::invoke($negotiated['providers'][WPFormsLocationProviderLibrary::PROVIDER],
                $actions[0], $negotiated['capabilities'][WPFormsLocationProviderLibrary::PROVIDER][WPFormsLocationProviderLibrary::CAPABILITY], []);
            $record['failure'] = null;
        } catch (Throwable $failure) {
            $record['receipt'] = null;
            $record['failure'] = WPrism\PrivateRefusalEvidence::graph($failure);
        }
        $record['after'] = $physical();
        $record['children'] = array_map(static fn(string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR),
            array_slice(file($proof . '/boots.jsonl'), $bootOffset));
    } elseif ($phase === 'observe') {
        $record['physical'] = $physical();
        $record['native'] = $locations($forms, true);
    } elseif ($phase === 'physical') {
        $check(in_array($case, $refusalCases, true), 'declared independent refusal observation');
        $record['physical'] = $physical();
    } else throw new RuntimeException('unknown native provider fixture phase');
}
$record['boot'] = $GLOBALS['wpforms_location_fixture_boot'];
echo wp_json_encode($record, JSON_THROW_ON_ERROR);
