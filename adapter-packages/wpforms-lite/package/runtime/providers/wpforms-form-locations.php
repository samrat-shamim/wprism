<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;

/** Experimental native derivation; the capsule disposition grants no production readiness. */
final class WpformsFormLocations extends ManifestProviderRuntime {
    private const META_KEY = 'wpforms_form_locations';
    private const MAX_FORMS = 128;
    private const MAX_PLACEMENTS = 128;
    private const MAX_LOCATIONS = 2048;
    private const MAX_VALUE_BYTES = 262144;
    private const MAX_OUTPUT_BYTES = 2097152;
    private const MAX_MUTATIONS = 256;
    private const WIDGET_INPUTS = [
        'widget_wpforms-widget' => 'form_id',
        'widget_text' => 'text',
        'widget_block' => 'content',
    ];
    private const POST_COLUMNS = [
        'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
        'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password',
        'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt',
        'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
        'post_mime_type', 'comment_count',
    ];
    private const DEPENDENCIES = [
        'options' => [['option_id'], ['option_id', 'option_name', 'option_value', 'autoload']],
        'terms' => [['term_id'], ['term_id', 'name', 'slug', 'term_group']],
        'term_taxonomy' => [['term_taxonomy_id'], ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count']],
        'term_relationships' => [['object_id', 'term_taxonomy_id'], ['object_id', 'term_taxonomy_id', 'term_order']],
        'termmeta' => [['meta_id'], ['meta_id', 'term_id', 'meta_key', 'meta_value']],
        'users' => [['ID'], ['ID', 'user_login', 'user_pass', 'user_nicename', 'user_email', 'user_url',
            'user_registered', 'user_activation_key', 'user_status', 'display_name']],
        'usermeta' => [['umeta_id'], ['umeta_id', 'user_id', 'meta_key', 'meta_value']],
    ];

    protected function invoke_rebuild_form_locations(array $args): array {
        self::assert_arguments($args);
        $first = $this->rebuild_pass();
        $committed = $this->durable_snapshot();
        if ($committed !== $first['after']) {
            self::refuse('committed rows disagree with the complete reconstruction postimage; recovery_required');
        }
        $second = $this->rebuild_pass();
        if ($first['after'] !== $second['before'] || $second['before'] !== $second['after']
            || $first['native_sha256'] !== $second['native_sha256']) {
            self::refuse('native reconstruction did not reach a physical fixed point; recovery_required');
        }
        if ($this->durable_snapshot() !== $second['after']) {
            self::refuse('fixed-point rows changed after commit; recovery_required');
        }
        return ['before' => $first['before'], 'after' => $second['after'], 'verified' => true];
    }

    protected function reconcile_rebuild_form_locations(array $args): array {
        self::assert_arguments($args);
        return $this->durable_snapshot();
    }

    protected function project_rebuild_form_locations(array $value): array {
        $value = $this->project_fresh_postimage_rebuild_form_locations($value);
        return ['locations_rows' => $value['locations_rows'], 'locations_sha256' => $value['locations_sha256']];
    }

    protected function observe_fresh_postimage_rebuild_form_locations(array $args): array {
        self::assert_arguments($args);
        // The fresh-process runtime already owns this read-only snapshot.
        return self::projection($this->physical_state());
    }

    protected function project_fresh_postimage_rebuild_form_locations(array $value): array {
        if (array_keys($value) !== ['inputs_sha256', 'remainder_sha256', 'locations_rows', 'locations_sha256']
            || !is_int($value['locations_rows']) || $value['locations_rows'] < 0) {
            self::refuse('physical location projection is malformed; recovery_required');
        }
        foreach (['inputs_sha256', 'remainder_sha256', 'locations_sha256'] as $key) {
            if (!is_string($value[$key]) || preg_match('/^[a-f0-9]{64}$/D', $value[$key]) !== 1) {
                self::refuse('physical location projection is malformed; recovery_required');
            }
        }
        return $value;
    }

    private function durable_snapshot(): array {
        return ProviderSdk::database_read_contract_snapshot('WPForms location durable evidence',
            fn(): array => self::projection($this->physical_state()));
    }

    private function rebuild_pass(): array {
        return ProviderSdk::database_write_contract_transaction('WPForms location reconstruction', function (): array {
            $state = $this->physical_state();
            $before = self::projection($state);
            $desired = $this->native_locations($state['posts']['rows']);
            // Native computation may warm request caches, never change any
            // durable input or derived row before its complete output is admitted.
            if (self::projection($this->physical_state()) !== $before) {
                self::refuse('native computation changed durable rows before reconstruction');
            }
            $this->reconcile_rows($state['owned'], $desired);
            $afterState = $this->physical_state();
            $after = self::projection($afterState);
            if ($before['inputs_sha256'] !== $after['inputs_sha256']
                || $before['remainder_sha256'] !== $after['remainder_sha256']) {
                self::refuse('reconstruction changed a native input or nonowned metadata row');
            }
            self::assert_owned_values($afterState['owned'], $desired);
            return ['before' => $before, 'after' => $after,
                'native_sha256' => hash('sha256', serialize($desired))];
        }, function (array $result): string {
            $actual = self::projection($this->physical_state());
            if ($actual === $result['after']) return ProviderSdk::DATABASE_POSTIMAGE_APPLIED;
            if ($actual === $result['before']) return ProviderSdk::DATABASE_POSTIMAGE_NOT_APPLIED;
            return ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
        });
    }

    private function physical_state(): array {
        global $wpdb;
        $posts = self::rows($wpdb->posts, ['ID'], self::POST_COLUMNS, true);
        $metadata = self::rows($wpdb->postmeta, ['meta_id'], ['meta_id', 'post_id', 'meta_key', 'meta_value'], true);
        $dependencies = [];
        foreach (self::DEPENDENCIES as $property => [$identity, $columns]) {
            $dependencies[$property] = self::rows($wpdb->{$property}, $identity, $columns, false);
        }
        $owned = $remainder = [];
        foreach ($metadata['rows'] as $row) {
            if ($row['meta_key'] === self::META_KEY) $owned[] = $row;
            else $remainder[] = $row;
        }
        return ['posts' => $posts, 'dependencies' => $dependencies, 'owned' => $owned, 'remainder' => $remainder];
    }

    private static function rows(string $table, array $identity, array $columns, bool $payload): array {
        return ProviderSdk::physical_table_rows(['table' => $table, 'columns' => $columns,
            'identity' => $identity, 'max_rows' => 8192, 'max_raw_bytes' => 16777216,
            'mode' => $payload ? 'rows' : 'digest'], 'WPForms complete native dependency rows');
    }

    private static function projection(array $state): array {
        $posts = $state['posts'];
        unset($posts['rows']);
        return ['inputs_sha256' => hash('sha256', serialize([$posts, $state['dependencies']])),
            'remainder_sha256' => hash('sha256', serialize($state['remainder'])),
            'locations_rows' => count($state['owned']),
            'locations_sha256' => hash('sha256', serialize($state['owned']))];
    }

    /** @return array<int,string> exact serialized native locations, never old decoded metadata */
    private function native_locations(array $posts): array {
        $locator = wpforms()->obj('locator');
        if (!is_object($locator) || get_class($locator) !== 'WPForms\\Forms\\Locator') {
            self::refuse('the initialized public Locator service is unavailable');
        }
        $types = $locator->get_post_types();
        $statuses = $locator->get_post_statuses();
        if (!is_array($types) || count($types) > 256 || !is_array($statuses) || count($statuses) > 32) {
            self::refuse('native post eligibility exceeds the bounded frontier');
        }
        foreach (array_merge($types, $statuses) as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 32) {
                self::refuse('native post eligibility is malformed');
            }
        }
        $forms = [];
        foreach ($posts as $post) {
            if ($post['post_type'] === 'wpforms') {
                $forms[self::positive_id($post['ID'])] = $post;
            }
        }
        if (count($forms) > self::MAX_FORMS) self::refuse('form population exceeds the bounded frontier');
        $locations = [];
        $total = 0;
        $placements = [];
        foreach ($posts as $post) {
            if (!in_array($post['post_type'], $types, true) || !in_array($post['post_status'], $statuses, true)) continue;
            $ids = $locator->get_form_ids($post['post_content']);
            if (!is_array($ids) || count($ids) > self::MAX_LOCATIONS) {
                self::refuse('native post form references are malformed or over the bounded frontier');
            }
            $targets = [];
            foreach ($ids as $id) $targets[self::form_id($id, $forms)] = true;
            if ($targets === []) continue;
            // The SDK's 128-ID frontier runs after selection. This capsule
            // owns scanner work and must stop at the first actual overflow,
            // not scan the remaining 8,192-row physical roster before refusing.
            if (count($placements) >= self::MAX_PLACEMENTS) self::refuse('native placement population exceeds the bounded frontier');
            $placements[] = ['post' => $post, 'targets' => $targets];
        }
        $links = ProviderSdk::checked_native_permalinks(array_map(
            static fn(array $placement): int => self::positive_id($placement['post']['ID']), $placements),
            'WPForms complete native placement permalinks');
        $home = $links['home'];
        foreach ($placements as $index => $placement) {
            $post = $placement['post'];
            $url = $links['permalinks'][$index];
            $url = $url === false ? '' : $url;
            self::native_string($url);
            $url = self::relative_location_url($home, $url);
            foreach ($placement['targets'] as $formId => $_present) {
                self::add_location($locations, $total, $formId, [
                    'type' => $post['post_type'], 'title' => $post['post_title'], 'form_id' => $formId,
                    'id' => self::positive_id($post['ID']), 'status' => $post['post_status'], 'url' => $url,
                ]);
            }
        }
        $inputs = [];
        foreach (self::WIDGET_INPUTS as $name => $content) {
            $widgets = ProviderSdk::checked_durable_option($name, [], 'WPForms native widget shape');
            self::assert_widget_inputs($widgets, $content);
            $inputs[] = ['name' => $name, 'default' => [], 'passed_default' => true, 'reads' => 1];
        }
        // Locator.php:177-186 freezes translated display titles at normal
        // service initialization. Public scanning retains that target-process
        // meaning; option witnesses do not claim physical title provenance or
        // identical recomputation on a later boot. The observer reads only the
        // committed postimage, while both native passes reuse this service.
        $widgets = ProviderSdk::native_option_inputs($inputs, static fn(): array => $locator->search_in_widgets(),
            'WPForms public widget scanner');
        if (!array_is_list($widgets) || count($widgets) > self::MAX_LOCATIONS) {
            self::refuse('native widget locations exceed the bounded frontier');
        }
        foreach ($widgets as $location) {
            if (!is_array($location) || array_keys($location) !== ['type', 'title', 'form_id', 'id']
                || $location['type'] !== 'widget' || !is_string($location['id'])
                || preg_match('/^(?:wpforms-widget|text|block)-[1-9][0-9]*$/D', $location['id']) !== 1) {
                self::refuse('native widget location is malformed');
            }
            $formId = self::form_id($location['form_id'], $forms);
            // WPForms widgets retain a string form_id; text/block use ints.
            self::add_location($locations, $total, $formId, $location);
        }
        $standalone = [];
        foreach ($forms as $id => $post) {
            if (!in_array($post['post_status'], $statuses, true)) continue;
            try { $data = json_decode($post['post_content'], true, 64, JSON_THROW_ON_ERROR); }
            catch (\Throwable $failure) { throw new \RuntimeException('wprism: WPForms location source has malformed form JSON', 0, $failure); }
            if (!is_array($data) || array_is_list($data) || !is_array($data['settings'] ?? null)
                || (array_key_exists('id', $data) && self::native_target_id($data['id']) !== $id)) {
                self::refuse('standalone form input or self identity is malformed');
            }
            foreach (\WPForms\Forms\Locator::STANDALONE_LOCATION_TYPES as $type) {
                if (empty($data['settings'][$type . '_enable'])) continue;
                if (!array_key_exists('id', $data)) {
                    self::refuse('enabled standalone form has no native self identity');
                }
                // Locator.php:1327-1341 concatenates the slug before returning.
                // Bound consumed source fields before that native allocation;
                // its first-enabled precedence leaves later settings unread.
                self::native_string($data['settings'][$type . '_title'] ?? '');
                self::native_string($data['settings'][$type . '_page_slug'] ?? '');
                break;
            }
            $standalone[$id] = [$data, $post['post_status']];
        }
        if ($standalone !== []) {
            $nativeTypes = ProviderSdk::checked_native_post_types(array_keys($standalone), 'WPForms standalone post types');
            if ($nativeTypes !== array_fill(0, count($standalone), 'wpforms')) {
                self::refuse('standalone native post types disagree with the form population');
            }
            // The audited public builder reaches core get_post_type before
            // any callback. No other native work may intervene after admission.
            foreach ($standalone as $id => [$data, $status]) {
                $location = $locator->build_standalone_location($id, $data, $status);
                if ($location === []) continue;
                if (array_keys($location) !== ['type', 'title', 'form_id', 'id', 'status', 'url']
                    || !in_array($location['type'], \WPForms\Forms\Locator::STANDALONE_LOCATION_TYPES, true)
                    || self::form_id($location['form_id'], $forms) !== $id || $location['id'] !== $id
                    || $location['status'] !== $status) {
                    self::refuse('native standalone location is malformed');
                }
                self::native_string($location['url']);
                $location['url'] = self::relative_location_url($home, $home . $location['url']);
                self::add_location($locations, $total, $id, $location);
            }
        }
        $desired = [];
        $bytes = 0;
        ksort($locations, SORT_NUMERIC);
        foreach ($locations as $id => $values) {
            $raw = self::location_bytes($values);
            if (strlen($raw) > self::MAX_VALUE_BYTES || ($bytes += strlen($raw)) > self::MAX_OUTPUT_BYTES) {
                self::refuse('native location output exceeds the pre-write byte frontier');
            }
            $desired[$id] = $raw;
        }
        return $desired;
    }

    private static function location_bytes(array $values): string {
        // Locator's public writers use update_post_meta(), whose core
        // update_metadata() unslashes recursively before serialization.
        return serialize(wp_unslash($values));
    }

    /** Canonical current-home policy, not replay of Locator's historical private home snapshot. */
    private static function relative_location_url(string $home, string $url): string {
        self::absolute_location_url($home, true);
        if ($url === '') return ''; // The public writer preserves an unavailable permalink as no link.
        self::absolute_location_url($url, false);
        if (!str_starts_with($url, $home)) self::refuse('native permalink is outside the admitted current home');
        $relative = substr($url, strlen($home));
        if ($relative !== '' && !in_array($relative[0], ['/', '?', '#'], true)) {
            self::refuse('native permalink crosses the current-home path or authority boundary');
        }
        // Locator.php:620-630 renders home + stored URL. Remove only one
        // leading home, never a matching string inside a slug/query/fragment.
        return $relative;
    }

    private static function absolute_location_url(string $url, bool $home): void {
        self::native_string($url);
        if (preg_match('/[\x00-\x20\x7f<>"\\\\]/', $url) === 1
            || preg_match('/%(?![a-fA-F0-9]{2})/', $url) === 1) {
            self::refuse('native home or permalink is outside the canonical URL frontier');
        }
        // Locator.php:647 decodes the entire escaped link before KSES. Native
        // evidence turns /a%3Fb into /a?b and truncates an encoded quote. Only
        // unreserved ASCII and valid UTF-8 escapes survive that renderer with
        // equivalent targets; never double-encode storage to conceal the gap.
        if (str_contains($url, '+') || preg_match('//u', rawurldecode($url)) !== 1) {
            self::refuse('native URL is outside the current-home renderer frontier');
        }
        for ($offset = 0; ($offset = strpos($url, '%', $offset)) !== false; $offset += 3) {
            $byte = chr(hexdec(substr($url, $offset + 1, 2)));
            if (ord($byte) < 128 && preg_match('/^[A-Za-z0-9._~-]$/D', $byte) !== 1) {
                self::refuse('native URL is outside the current-home renderer frontier');
            }
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))
            || str_contains($parts['host'], '%')
            || ($home && (isset($parts['query']) || isset($parts['fragment']) || str_ends_with($url, '/')))) {
            self::refuse('native home or permalink is outside the canonical URL frontier');
        }
        // Browsers resolve encoded dot segments before navigation. A lexical
        // home prefix must not admit /base/../outside or encoded separators.
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            $decoded = rawurldecode($segment);
            if (in_array($decoded, ['.', '..'], true) || str_contains($decoded, '/') || str_contains($decoded, '\\')
                || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
                self::refuse('native URL path escapes the canonical current-home frontier');
            }
        }
    }

    private static function assert_widget_inputs(mixed $widgets, string $content): void {
        if (!is_array($widgets) || count($widgets) > self::MAX_LOCATIONS) {
            self::refuse('native widget storage is malformed or over the bounded frontier');
        }
        foreach ($widgets as $id => $widget) {
            if (!is_array($widget) || !isset($widget[$content])) continue;
            self::positive_id($id);
            if ($content === 'form_id') self::native_target_id($widget[$content]);
            else self::native_string($widget[$content]);
            if ($content !== 'content') self::native_string($widget['title'] ?? null);
        }
    }

    private static function add_location(array &$locations, int &$total, int $id, array $location): void {
        foreach ($location as $value) {
            if (is_string($value)) self::native_string($value);
            elseif (!is_int($value) || $value <= 0) self::refuse('native location contains a non-plain field');
        }
        foreach ($locations[$id] ?? [] as $prior) {
            if ($prior === $location) return;
        }
        if (++$total > self::MAX_LOCATIONS) self::refuse('native placement count exceeds the bounded frontier');
        $locations[$id][] = $location;
    }

    /** The ordered physical roster chooses one stable identity per form. */
    private function reconcile_rows(array $owned, array $desired): void {
        global $wpdb;
        $plan = self::reconciliation_plan($owned, $desired);
        foreach ($plan as [$operation, $id, $raw]) {
            if ($operation === 'update') {
                ProviderSdk::database_update($wpdb->postmeta, ['meta_value' => $raw],
                    ['meta_id' => $id], 'WPForms exact location row update');
            } elseif ($operation === 'delete') {
                ProviderSdk::database_delete($wpdb->postmeta, ['meta_id' => $id], 'WPForms obsolete location row removal');
            } else {
                ProviderSdk::database_insert($wpdb->postmeta,
                    ['post_id' => $id, 'meta_key' => self::META_KEY, 'meta_value' => $raw],
                    'WPForms missing location row insertion');
            }
        }
    }

    /** Complete bounded mutation intent precedes the first DML statement. */
    private static function reconciliation_plan(array $owned, array $desired): array {
        $kept = [];
        $plan = [];
        foreach ($owned as $row) {
            $metaId = self::positive_id($row['meta_id']);
            // Zero is an orphan coordinate in wp_postmeta's unsigned column,
            // not a native get_post() argument and never a global-post fallback.
            $formId = in_array($row['post_id'], ['0', 0], true) ? 0 : self::positive_id($row['post_id']);
            if (isset($desired[$formId]) && !isset($kept[$formId])) {
                $kept[$formId] = true;
                if ($row['meta_value'] !== $desired[$formId]) {
                    $plan[] = ['update', $metaId, $desired[$formId]];
                }
            } else {
                $plan[] = ['delete', $metaId, null];
            }
            if (count($plan) > self::MAX_MUTATIONS) self::refuse('location changes exceed the pre-write mutation frontier');
        }
        foreach ($desired as $formId => $raw) {
            if (!isset($kept[$formId])) $plan[] = ['insert', $formId, $raw];
            if (count($plan) > self::MAX_MUTATIONS) self::refuse('location changes exceed the pre-write mutation frontier');
        }
        return $plan;
    }

    private static function assert_owned_values(array $rows, array $desired): void {
        $actual = [];
        foreach ($rows as $row) {
            $id = self::positive_id($row['post_id']);
            if (isset($actual[$id]) || !is_string($row['meta_value'])) {
                self::refuse('location postimage has duplicate or non-string values');
            }
            $actual[$id] = $row['meta_value'];
        }
        ksort($actual, SORT_NUMERIC);
        if ($actual !== $desired) self::refuse('stored locations disagree with the complete native expectation');
    }

    private static function form_id(mixed $value, array $forms): int {
        $id = self::native_target_id($value);
        if (!isset($forms[$id])) self::refuse('a native placement references a missing or non-form post');
        return $id;
    }

    /** Native widget/form IDs permit leading zeros; physical row identities do not. */
    private static function native_target_id(mixed $value): int {
        if (is_string($value) && strlen($value) <= 32 && preg_match('/^[0-9]+$/D', $value) === 1) {
            $value = ltrim($value, '0');
        }
        return self::positive_id($value);
    }

    private static function positive_id(mixed $value): int {
        if (is_int($value) && $value > 0) return $value;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($id)) return $id;
        }
        self::refuse('a location identity is not a canonical positive native integer');
    }

    private static function native_string(mixed $value): void {
        if (!is_string($value) || strlen($value) > self::MAX_VALUE_BYTES || preg_match('//u', $value) !== 1) {
            self::refuse('native location text is malformed or over the bounded frontier');
        }
    }

    private static function assert_arguments(array $args): void {
        if ($args !== []) self::refuse('reconstruction accepts no arguments');
    }

    private static function refuse(string $reason): never {
        throw new \RuntimeException('wprism: WPForms locations ' . $reason);
    }
}
