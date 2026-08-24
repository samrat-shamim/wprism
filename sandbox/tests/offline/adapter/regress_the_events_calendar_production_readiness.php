<?php
declare(strict_types=1);

namespace TEC\Events\Category_Colors\CSS {
    final class Controller {
        public function generate_css(): void {
            $GLOBALS['tec_readiness_category_color_generator']->generate_and_save_css();
            $GLOBALS['tec_readiness_category_color_dropdown']->bust_dropdown_categories_cache();
        }
    }

    final class Generator {
        public function generate_and_save_css(): string {
            ++$GLOBALS['tec_readiness_color_controller_calls'];
            \tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
            $mode = $GLOBALS['tec_readiness_color_controller_mode'] ?? '';
            if ($mode === 'throw_after_css') {
                $GLOBALS['tec_readiness_color_controller_mode'] = '';
                throw new \RuntimeException('injected native Category Colors failure after CSS write');
            }
            return $GLOBALS['tec_readiness_generated_css'];
        }
    }
}

namespace TEC\Events\Category_Colors\Repositories {
    final class Category_Color_Dropdown_Provider {
        public const CACHE_KEY = 'tec_category_colors_dropdown_categories';

        /** @return mixed */
        public function get_dropdown_categories(): mixed {
            ++$GLOBALS['tec_readiness_dropdown_get_calls'];
            if ($GLOBALS['tec_readiness_dropdown_rows'] === false) {
                $GLOBALS['tec_readiness_dropdown_rows'] = $GLOBALS['tec_readiness_generated_dropdown_rows'];
                ++$GLOBALS['tec_readiness_cache_sets'];
            }
            return $GLOBALS['tec_readiness_dropdown_rows'];
        }

        public function bust_dropdown_categories_cache(): void {
            ++$GLOBALS['tec_readiness_cache_busts'];
            if (($GLOBALS['tec_readiness_color_controller_mode'] ?? '') === 'cache_delete_noop') {
                $GLOBALS['tec_readiness_color_controller_mode'] = '';
                return;
            }
            $GLOBALS['tec_readiness_dropdown_rows'] = false;
            if (($GLOBALS['tec_readiness_color_controller_mode'] ?? '') === 'throw_after_cache_bust') {
                $GLOBALS['tec_readiness_color_controller_mode'] = '';
                throw new \RuntimeException('injected native Category Colors failure after cache bust');
            }
        }
    }
}

namespace TEC\Common\Configuration {
    final class Configuration {
        public function has(string $key): bool {
            return array_key_exists($key, $GLOBALS['tec_readiness_configuration'] ?? []);
        }

        public function get(string $key): mixed {
            return $GLOBALS['tec_readiness_configuration'][$key] ?? null;
        }
    }
}

namespace TEC\Common\Integrations\Harbor {
    final class PUE {
        public function filter_pre_get_option(mixed $value): mixed {
            return $value;
        }
    }
}

namespace Tribe\Events\Views\V2 {
    final class Kitchen_Sink {
        public function generate_rules(object $rewrite): void {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'kitchen-sink'];
            $rewrite->add(
                ['tribe', 'events', 'kitchen-sink'],
                ['post_type' => 'tribe_events', 'tribe_events_views_kitchen_sink' => 'page']
            );
        }
    }

    final class View_Register {
        public function __construct(public readonly string $slug) {
            \add_action('tribe_events_pre_rewrite', [$this, 'filter_add_routes'], 5, 1);
        }

        public function filter_add_routes(object $rewrite): void {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, $this->slug];
        }
    }

    final class Manager {
        /** @var array<string,View_Register> */
        private array $view_registration = [];

        public function register_view(string $slug): View_Register {
            return $this->view_registration[$slug] = new View_Register($slug);
        }

        public function unregister_view(string $slug): void {
            unset($this->view_registration[$slug]);
        }

        /** @return array<string,View_Register> */
        public function get_view_registration_objects(): array {
            return $this->view_registration;
        }
    }

    final class Hooks {
        public function __construct() {
            \add_action('tribe_events_pre_rewrite', [$this, 'on_tribe_events_pre_rewrite'], 10, 1);
            \add_action('updated_option', [$this, 'action_save_wplang'], 10, 3);
        }

        public function on_tribe_events_pre_rewrite(object $rewrite): void {
            \tribe(Kitchen_Sink::class)->generate_rules($rewrite);
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'views'];
        }

        public function action_save_wplang(string $option, mixed $old, mixed $value): void {}
    }
}

namespace TEC\Events\QR {
    final class Routes {
        private ?string $route_base = null;
        private ?string $route_prefix = null;

        public function __construct() {
            \add_action('tribe_events_pre_rewrite', [$this, 'add_qr_rules'], 10, 1);
        }

        public function add_qr_rules(object $rewrite): void {
            $this->route_base ??= (string) \apply_filters('tec_events_qr_route_base', 'events');
            $this->route_prefix ??= (string) \apply_filters('tec_events_qr_route_prefix', 'qr');
            $rewrite->add(
                [$this->route_base, $this->route_prefix, '([^/]+)'],
                ['tec_qr_hash' => '%1']
            );
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'qr'];
        }
    }
}

namespace Duo\Interpreters {
    function microtime(bool $asFloat = false): float|string {
        $plan = &$GLOBALS['tec_readiness_interpreter_time_plan'];
        $value = is_array($plan) && $plan !== [] ? array_shift($plan) : \microtime(true);
        return $asFloat ? (float) $value : (string) $value;
    }
}

namespace TEC\Events\Custom_Tables\V1\Events\Occurrences {
    final class Occurrences_Generator {}
}

namespace Tribe\Log {
    final class Service_Provider {
        public function dispatch_log(mixed $level = 'debug', mixed $message = '', array $context = []): void {
            ++$GLOBALS['tec_readiness_log_dispatches'];
        }
    }
}

namespace {
final class WP_Hook {
    /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
    public array $callbacks = [];
}

final class Tribe__Container {
    public function isBound(string $service): mixed {
        $overrides = $GLOBALS['tec_readiness_container_binding_signals'] ?? [];
        if (array_key_exists($service, $overrides)) {
            return $overrides[$service];
        }
        return array_key_exists($service, $GLOBALS['tec_readiness_container_bindings'] ?? []);
    }

    public function make(string $service): object {
        $bindings = $GLOBALS['tec_readiness_container_bindings'] ?? [];
        if (array_key_exists($service, $bindings)) {
            $binding = $bindings[$service];
            return is_callable($binding) ? $binding() : $binding;
        }
        if (!class_exists($service)) {
            throw new RuntimeException('offline native service class is unavailable');
        }
        return new $service();
    }
}

final class Tribe__Settings_Manager {
    private static ?self $instance = null;

    public function __construct() {
        add_action('updated_option', [$this, 'update_options_cache'], 10, 3);
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function update_options_cache(string $option, mixed $old, mixed $value): void {
        // Exact Common 6.17.2/6.17.3 returns before reading object state for
        // every option except tribe_events_calendar_options.
        if ($option === 'tribe_events_calendar_options') {
            tribe_set_var('Tribe__Settings_Manager:option_cache', $value);
        }
    }
}

final class Tribe__Events__Aggregator {
    private static ?self $instance = null;

    private function __construct() {
        add_action('tribe_events_pre_rewrite', [$this, 'action_endpoint_configuration'], 10, 1);
        add_action('updated_option', [$this, 'action_purge_transients'], 10, 1);
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function action_endpoint_configuration(object $rewrite): void {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'aggregator'];
    }

    public function action_purge_transients(string $option): void {}
}

final class Tribe__Deprecation {
    private static ?self $instance = null;

    private function __construct() {
        add_action('tribe_pre_rewrite', [$this, 'deprecated_action_message'], 10, 1);
        add_action('tribe_events_pre_rewrite', [$this, 'deprecated_action_message'], 10, 1);
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function deprecated_action_message(mixed $value = null): mixed {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'deprecation'];
        return $value;
    }
}

final class Tribe__Events__Rewrite {
    private static ?self $instance = null;

    private function __construct() {
        add_filter('generate_rewrite_rules', [$this, 'filter_generate'], 10, 1);
        add_filter('rewrite_rules_array', [$this, 'filter_rewrite_rules_array'], 25, 1);
        add_action('tribe_events_pre_rewrite', [$this, 'generate_core_rules'], 10, 1);
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function filter_generate(object $wpRewrite): void {
        do_action('tribe_pre_rewrite', $this);
        do_action('tribe_events_pre_rewrite', $this);
        apply_filters('tribe_events_rewrite_rules_custom', [], $this, $wpRewrite);
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'generate'];
    }

    public function filter_rewrite_rules_array(mixed $rules): mixed {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'array'];
        return $rules;
    }

    public function generate_core_rules(object $rewrite): void {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'core'];
    }

    /** @param list<string> $regex @param array<string,mixed> $args */
    public function add(array $regex, array $args): self {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, $regex, $args];
        return $this;
    }
}

final class Tribe__Customizer {
    public string $ID = 'tribe_customizer';

    public function __construct(bool $registerFallback = true) {
        if ($registerFallback) {
            add_filter('default_option_tribe_customizer', [$this, 'maybe_fallback_get_option']);
        }
    }

    public function maybe_fallback_get_option(mixed $sections): mixed {
        return !empty($sections)
            ? $sections
            : get_option('tribe_events_pro_customizer', []);
    }
}

final class Tribe__Cache {
    public const NON_PERSISTENT = -1;
    public const SCHEDULED_EVENT_DELETE_TRANSIENT = 'tribe_schedule_transient_purge';

    /** @var array<string,string> */
    protected array $non_persistent_keys = [];

    public function get(
        string $id,
        string|array $expirationTrigger = '',
        mixed $default = false,
        int $expiration = 0,
        array $args = []
    ): mixed {
        if ($id === \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::CACHE_KEY
            && $expirationTrigger === '') {
            ++$GLOBALS['tec_readiness_cache_reads'];
            if (($GLOBALS['tec_readiness_cache_read_mode'] ?? '') === 'throw') {
                $GLOBALS['tec_readiness_cache_read_mode'] = '';
                throw new \RuntimeException('hostile cache read failure AKIAABCDEFGHIJKLMNOP');
            }
            return $GLOBALS['tec_readiness_dropdown_rows'];
        }
        $group = isset($this->non_persistent_keys[$id])
            ? 'tribe-events-non-persistent'
            : 'tribe-events';
        $value = wp_cache_get($this->get_id($id, $expirationTrigger), $group, false, $found);
        if ($found) {
            return $value;
        }
        $value = is_callable($default) ? $default(...$args) : $default;
        if ($value !== false) {
            $this->set($id, $value, $expiration, $expirationTrigger);
        }
        return $value;
    }

    public function set(
        string $id,
        mixed $value,
        int $expiration = 0,
        string|array $expirationTrigger = ''
    ): bool {
        $expiration = (int) apply_filters(
            'tribe_cache_expiration',
            $expiration,
            $id,
            $value,
            $expirationTrigger,
            $this->get_id($id, $expirationTrigger)
        );
        if ($expiration === self::NON_PERSISTENT) {
            $this->non_persistent_keys[$id] = $id;
            $group = 'tribe-events-non-persistent';
            $expiration = 1;
        } else {
            $group = 'tribe-events';
        }
        return wp_cache_set($this->get_id($id, $expirationTrigger), $value, $group, $expiration);
    }

    public function delete(string $id, string|array $expirationTrigger = ''): bool {
        $group = isset($this->non_persistent_keys[$id])
            ? 'tribe-events-non-persistent'
            : 'tribe-events';
        unset($this->non_persistent_keys[$id]);
        return wp_cache_delete($this->get_id($id, $expirationTrigger), $group);
    }

    public function set_last_occurrence(string $action, int|float $timestamp = 0): bool {
        $timestamp = $timestamp !== 0 ? $timestamp : microtime(true);
        $updated = update_option('tribe_last_' . $action, (float) $timestamp);
        if ($updated) {
            tribe_set_var('should_delete_expired_transients', true);
        }
        return $updated;
    }

    public function get_last_occurrence(string $action): float {
        $value = (float) get_option('tribe_last_' . $action, 0);
        if ($value === 0.0) {
            $value = microtime(true);
            $this->set_last_occurrence($action, $value);
        }
        return $value;
    }

    public function get_id(string $id, string|array $expirationTrigger = ''): string {
        $triggers = is_array($expirationTrigger)
            ? $expirationTrigger
            : array_filter(explode('|', $expirationTrigger));
        $last = 0.0;
        foreach ($triggers as $trigger) {
            $last = max($last, $this->get_last_occurrence((string) $trigger));
        }
        $key = $id . ($last === 0.0 ? '' : (string) $last);
        return strlen($key) > 80 ? 'tribe_' . md5($key) : $key;
    }

    public function delete_expired_transients(): void {
        ++$GLOBALS['tec_readiness_expired_transient_deletes'];
    }

    public function maybe_delete_expired_transients(): void {
        if (tribe_get_var('should_delete_expired_transients', false)) {
            $this->delete_expired_transients();
        }
    }
}

final class Tribe__Main {
    /** @return list<string> */
    public static function get_post_types(): array {
        return ['tribe_events', 'tribe_venue', 'tribe_organizer'];
    }
}

final class Tribe__Cache_Listener {
    private static ?self $instance = null;
    private Tribe__Cache $cache;

    private function __construct() {
        // Exact free TEC 6.17.2/6.17.3 Cache_Listener.php:42-44 constructs a
        // dedicated cache instance; the regenerator must track both mutable
        // non-persistent registries rather than assume the global singleton.
        $this->cache = new Tribe__Cache();
        add_action('save_post', [$this, 'save_post'], 0, 2);
        add_action('updated_option', [$this, 'update_last_updated_option'], 10, 3);
        add_action('updated_option', [$this, 'update_last_save_post'], 10, 3);
        add_action('generate_rewrite_rules', [$this, 'generate_rewrite_rules']);
        add_action('clean_post_cache', [$this, 'save_post'], 0, 2);
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function save_post(int $postId, WP_Post $post): void {
        $types = apply_filters('tec_cache_listener_save_post_types', Tribe__Main::get_post_types());
        if (in_array($post->post_type, (array) $types, true)) {
            $this->cache->set_last_occurrence('save_post');
        }
    }

    public function update_last_updated_option(string $option, mixed $old, mixed $new): void {
        $triggers = apply_filters(
            'tribe_cache_last_occurrence_option_triggers',
            [
                'active_plugins' => true,
                'tribe_events_calendar_options' => true,
                'permalink_structure' => true,
                'rewrite_rules' => true,
                'start_of_week' => true,
                'sidebars_widgets' => true,
                'stylesheet' => true,
                'template' => true,
                'WPLANG' => true,
            ],
            'updated_option',
            func_get_args()
        );
        $triggers = apply_filters(
            'tribe_cache_last_occurrence_option_triggers:updated_option',
            $triggers,
            'updated_option',
            func_get_args()
        );
        if (!empty($triggers[$option])) {
            $this->cache->set_last_occurrence('updated_option');
        }
    }

    public function update_last_save_post(string $option, mixed $old, mixed $new): void {
        $triggers = apply_filters(
            'tribe_cache_last_occurrence_option_triggers',
            [
                'tribe_events_calendar_options' => true,
                'permalink_structure' => true,
                'rewrite_rules' => true,
                'start_of_week' => true,
            ],
            'save_post',
            func_get_args()
        );
        $triggers = apply_filters(
            'tribe_cache_last_occurrence_option_triggers:save_post',
            $triggers,
            'save_post',
            func_get_args()
        );
        if (!empty($triggers[$option])) {
            $this->cache->set_last_occurrence('save_post');
        }
    }

    public function generate_rewrite_rules(): void {
        $this->cache->set_last_occurrence('generate_rewrite_rules');
    }
}

final class WP_Post {
    public int $ID;
    public string $post_type;

    /** @param array<string,mixed> $row */
    public function __construct(array $row) {
        $this->ID = (int) ($row['ID'] ?? 0);
        $this->post_type = (string) ($row['post_type'] ?? '');
    }
}

final class TecReadinessRewriteRuntime {
    public mixed $permalink_structure = '/events-source/%postname%/';
    public mixed $rules = null;
    public int $flushCalls = 0;
    public bool $malformedAfterGenerate = false;
    /** @var array<string,array{}> */
    public array $extra_permastructs = ['product' => [], 'product_cat' => []];

    public function flush_rules(bool $hard = true): void {
        if ($hard) {
            throw new RuntimeException('the TEC rewrite fixture received a hard flush');
        }
        $this->flushCalls++;
        do_action('generate_rewrite_rules', $this);
        $generated = $this->malformedAfterGenerate
            ? 'malformed-after-generate'
            : ['^events/?$' => 'index.php?post_type=tribe_events'];
        $this->rules = is_array($generated)
            ? apply_filters('rewrite_rules_array', $generated)
            : $generated;
        update_option('rewrite_rules', $this->rules);
    }

    public function wp_rewrite_rules(): mixed {
        $this->rules = get_option('rewrite_rules');
        return $this->rules;
    }
}

function tec_readiness_native_boundary(string $boundary): void {
    $disruption = $GLOBALS['tec_readiness_native_disruption'] ?? null;
    if (!is_array($disruption) || ($disruption['boundary'] ?? null) !== $boundary) {
        return;
    }
    $GLOBALS['tec_readiness_native_disruption'] = null;
    /** @var FakeWpdb $wpdb */
    $wpdb = $GLOBALS['wpdb'];
    $action = $disruption['action'] ?? null;
    if ($action === 'commit' || $action === 'rollback') {
        $wpdb->query(strtoupper($action));
        return;
    }
    if ($action === 'reconnect') {
        $wpdb->setConnectionId(77);
        return;
    }
    if ($action === 'external_cache') {
        $GLOBALS['tec_readiness_external_object_cache'] = true;
        return;
    }
    if ($action === 'cache_delete_throw') {
        $GLOBALS['tec_readiness_wp_cache_delete_mode'] = 'one_throw';
        return;
    }
    if ($action === 'listener_cache_substitute') {
        $property = new ReflectionProperty(Tribe__Cache_Listener::class, 'cache');
        $property->setValue(Tribe__Cache_Listener::instance(), new Tribe__Cache());
        return;
    }
    if ($action === 'cache_registry_mutate') {
        $property = new ReflectionProperty(Tribe__Cache::class, 'non_persistent_keys');
        $globalCache = tribe_cache();
        $listenerProperty = new ReflectionProperty(Tribe__Cache_Listener::class, 'cache');
        $listenerCache = $listenerProperty->getValue(Tribe__Cache_Listener::instance());
        $globalKeys = $property->getValue($globalCache);
        $listenerKeys = $property->getValue($listenerCache);
        $globalKeys['injected-global-drift'] = 'injected-global-drift';
        $listenerKeys['injected-listener-drift'] = 'injected-listener-drift';
        $property->setValue($globalCache, $globalKeys);
        $property->setValue($listenerCache, $listenerKeys);
        return;
    }
    if ($action === 'generator_binding') {
        $GLOBALS['tec_readiness_container_bindings'][
            \TEC\Events\Custom_Tables\V1\Events\Occurrences\Occurrences_Generator::class
        ] = new stdClass();
        return;
    }
    if ($action === 'state_probe_error') {
        $wpdb->failNextQuery(
            'hostile transaction-state error AKIAABCDEFGHIJKLMNOP',
            'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction'
        );
        return;
    }
    if ($action === 'source_same_length') {
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => '2026-11-02 15:16:00'],
            ['post_id' => 6100000001, 'meta_key' => '_EventEndDateUTC']
        );
        return;
    }
    if ($action === 'source_insert') {
        $wpdb->insert($wpdb->postmeta, [
            'meta_id' => 9199999999,
            'post_id' => 6100000001,
            'meta_key' => '_HostileConcurrentKey',
            'meta_value' => 'credential AKIAABCDEFGHIJKLMNOP',
        ]);
        return;
    }
    if ($action === 'source_delete') {
        $wpdb->delete($wpdb->postmeta, [
            'post_id' => 6100000001,
            'meta_key' => '_EventTimezone',
        ]);
        return;
    }
    if ($action === 'source_id_swap') {
        $rows = $wpdb->rows($wpdb->postmeta);
        $positions = [];
        foreach ($rows as $position => $row) {
            if ((int) ($row['post_id'] ?? 0) === 6100000001) {
                $positions[] = $position;
            }
        }
        if (count($positions) >= 2) {
            $first = $positions[0];
            $second = $positions[1];
            [$rows[$first]['meta_id'], $rows[$second]['meta_id']] = [
                $rows[$second]['meta_id'],
                $rows[$first]['meta_id'],
            ];
            $wpdb->seedTable($wpdb->postmeta, $rows);
        }
        return;
    }
    throw new RuntimeException('unknown TEC native disruption fixture');
}

/** Exact TEC 6.17.2/6.17.3 schema, identity, and refusal boundary. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/LockingFakeWpdb.php';
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Blocks.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';
require_once __DIR__ . '/../../../../agent/src/Repository/SidebarState.php';
require_once __DIR__ . '/../../../../agent/src/Promotion/Deploy.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/Providers.php';
require_once __DIR__ . '/../../../../agent/src/Capture/EntityMetaCapture.php';
require_once __DIR__ . '/../../../../agent/src/Capture/OptionsCapture.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Apply/OptionsMaterializer.php';
require_once __DIR__ . '/../../../../manifests/interpreters/the-events-calendar.php';
require_once __DIR__ . '/../../../../manifests/providers/the-events-calendar-category-colors.php';
require_once __DIR__ . '/../../../../manifests/regenerators/the-events-calendar.php';

use Duo\Interpreters\TheEventsCalendar;
use Duo\Blocks;
use Duo\Deploy;
use Duo\EntityMetaCapture;
use Duo\Policy;
use Duo\Tokens;
use Duo\Providers\TheEventsCalendarCategoryColors;
use Duo\Regenerators\TheEventsCalendar as TheEventsCalendarRegenerator;
use DuoTest\FakeWpdb;
use DuoTest\LockingFakeWpdb;

const TEC_EVENT_UUID = '11111111-1111-4111-8111-111111111111';
const TEC_VENUE_UUID = '22222222-2222-4222-8222-222222222222';
const TEC_ORGANIZER_UUID = '33333333-3333-4333-8333-333333333333';
const TEC_ORGANIZER_TWO_UUID = '55555555-5555-4555-8555-555555555555';
const TEC_CATEGORY_UUID = '44444444-4444-4444-8444-444444444444';
const TEC_LIST_WIDGET_UUID = '66666666-6666-4666-8666-666666666666';
const TEC_QR_WIDGET_UUID = '77777777-7777-4777-8777-777777777777';

final class TecReadinessWidgetWakeupProbe {
    public function __wakeup(): void {
        ++$GLOBALS['tec_readiness_widget_wakeups'];
    }
}

final class TecReadinessNativeColor {
    public function __construct(private string $value) {
        if (preg_match('/^#[0-9a-f]{6}$/iD', $value) !== 1) {
            throw new InvalidArgumentException('invalid native test color');
        }
    }

    public function get_hex_with_hash(): string {
        return strtolower($this->value);
    }
}

final class TecReadinessEventModel {
    public mixed $event_id;
    public int $post_id;
    public string $start_date;
    public string $end_date;
    public string $start_date_utc;
    public string $end_date_utc;
    public string $timezone;
    public string $duration;

    /** @param array<string,mixed> $row */
    public function __construct(array $row) {
        $this->event_id = $row['event_id'] ?? null;
        $this->post_id = (int) ($row['post_id'] ?? 0);
        $this->start_date = (string) ($row['start_date'] ?? '');
        $this->end_date = (string) ($row['end_date'] ?? '');
        $this->start_date_utc = (string) ($row['start_date_utc'] ?? '');
        $this->end_date_utc = (string) ($row['end_date_utc'] ?? '');
        $this->timezone = (string) ($row['timezone'] ?? '');
        $this->duration = (string) ($row['duration'] ?? '');
    }

    /** @return mixed */
    public static function data_from_post(int $postId): mixed {
        $GLOBALS['tec_readiness_event_data_calls'][] = $postId;
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'non_array_data') {
            return 'credential-shaped-AKIAABCDEFGHIJKLMNOP';
        }
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_type !== 'tribe_events') {
            return [];
        }
        $postId = $post->ID;
        $data = [
            'post_id' => $postId,
            'start_date' => get_post_meta($postId, '_EventStartDate', true),
            'end_date' => get_post_meta($postId, '_EventEndDate', true),
            'timezone' => get_post_meta($postId, '_EventTimezone', true),
            'duration' => get_post_meta($postId, '_EventDuration', true),
            'start_date_utc' => get_post_meta($postId, '_EventStartDateUTC', true),
            'end_date_utc' => get_post_meta($postId, '_EventEndDateUTC', true),
            'hash' => '',
        ];
        if ($data['duration'] === '' || $data['duration'] === '0') {
            $start = new DateTimeImmutable((string) $data['start_date_utc'], new DateTimeZone('UTC'));
            $end = new DateTimeImmutable((string) $data['end_date_utc'], new DateTimeZone('UTC'));
            $data['duration'] = (string) ($end->getTimestamp() - $start->getTimestamp());
        }
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'malformed_event_timezone') {
            $data['timezone'] = 'not/a-native-timezone';
        }
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'unexpected_event_field') {
            $data['credential-shaped-AKIAABCDEFGHIJKLMNOP'] = 'hidden';
        }
        tec_readiness_native_boundary('data_from_post');
        return $data;
    }

    /** @param list<string> $uniqueBy @param array<string,mixed>|null $data */
    public static function upsert(array $uniqueBy, ?array $data = null): int|false {
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'upsert_false') {
            return false;
        }
        if ($uniqueBy !== ['post_id'] || $data === null) {
            throw new RuntimeException('unexpected fake TEC upsert contract');
        }
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'tec_events';
        $eventId = 7000000001;
        foreach ($wpdb->rows($table) as $row) {
            if ((int) ($row['post_id'] ?? 0) === (int) $data['post_id']) {
                $eventId = (int) $row['event_id'];
                break;
            }
        }
        $wpdb->delete($table, ['post_id' => (int) $data['post_id']]);
        $row = ['event_id' => $eventId] + $data + ['updated_at' => '2026-08-24 00:00:00'];
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'invalid_event_updated_at') {
            $row['updated_at'] = '2026-02-31 25:99:99';
        }
        if ($wpdb->insert($table, $row) === false) {
            return false;
        }
        $GLOBALS['tec_readiness_regen_calls'][] = (int) $data['post_id'];
        $cache = tribe_cache();
        $cache->delete('tec-event-model-' . (string) $data['post_id'], 'save_post');
        $cache->set_last_occurrence('save_post');
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'upsert_throw_after_write') {
            throw new RuntimeException('injected native event upsert failure after derived write');
        }
        tec_readiness_native_boundary('upsert');
        return 1;
    }

    /** @return list<string> */
    public static function last_errors(): array {
        tec_readiness_native_boundary('last_errors');
        return [
            'hostile model error AKIAABCDEFGHIJKLMNOP',
            str_repeat('x', 4096),
        ];
    }

    public static function find(int $postId, string $column): ?self {
        if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'find_missing') {
            return null;
        }
        if ($column !== 'post_id') {
            throw new RuntimeException('unexpected fake TEC find contract');
        }
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        foreach ($wpdb->rows($wpdb->prefix . 'tec_events') as $row) {
            if ((int) ($row['post_id'] ?? 0) === $postId) {
                if (($GLOBALS['tec_readiness_regen_mode'] ?? '') === 'invalid_event_id') {
                    $row['event_id'] = '7000000001';
                }
                tribe_cache()->set(
                    'tec-event-find-' . (string) $postId,
                    $row,
                    Tribe__Cache::NON_PERSISTENT,
                    'save_post'
                );
                tec_readiness_native_boundary('find');
                return new self($row);
            }
        }
        return null;
    }

    public function occurrences(): TecReadinessOccurrenceSaver {
        tec_readiness_native_boundary('occurrences');
        return new TecReadinessOccurrenceSaver($this);
    }
}

final class TecReadinessOccurrenceSaver {
    public function __construct(private TecReadinessEventModel $event) {}

    public function save_occurrences(): void {
        /** @var FakeWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $table = $wpdb->prefix . 'tec_occurrences';
        wp_cache_delete($this->event->post_id, 'tec_occurrence_matches');
        $existingId = 8000000001;
        foreach ($wpdb->rows($table) as $row) {
            if ((int) ($row['post_id'] ?? 0) === $this->event->post_id) {
                $existingId = (int) $row['occurrence_id'];
                break;
            }
        }
        // Free TEC updates the first physical occurrence for this post and
        // does not silently erase hostile extra rows. Verification below is
        // what refuses that unsupported dirty shape.
        $wpdb->delete($table, ['occurrence_id' => $existingId]);
        $row = [
            'occurrence_id' => $existingId,
            'event_id' => $this->event->event_id,
            'post_id' => $this->event->post_id,
            'start_date' => $this->event->start_date,
            'end_date' => $this->event->end_date,
            'start_date_utc' => $this->event->start_date_utc,
            'end_date_utc' => $this->event->end_date_utc,
            'duration' => $this->event->duration,
            'updated_at' => '2026-08-24 00:00:01',
        ];
        $row['hash'] = sha1(implode(':', [
            $row['post_id'],
            $row['start_date'],
            $row['end_date'],
            $row['start_date_utc'],
            $row['end_date_utc'],
            $row['duration'],
        ]));
        $mode = $GLOBALS['tec_readiness_regen_mode'] ?? '';
        if ($mode === 'partial_occurrence') {
            $row['end_date_utc'] = '2001-01-01 00:00:00';
        } elseif ($mode === 'wrong_event_link') {
            $row['event_id'] = (int) $row['event_id'] + 1;
        } elseif ($mode === 'invalid_occurrence_id') {
            $row['occurrence_id'] = 0;
        } elseif ($mode === 'oversized_occurrence_id') {
            $row['occurrence_id'] = '18446744073709551616';
        } elseif ($mode === 'invalid_occurrence_updated_at') {
            $row['updated_at'] = '2026-02-31 25:99:99';
        } elseif ($mode === 'save_throw') {
            $row['end_date_utc'] = '2001-01-01 00:00:00';
        }
        $wpdb->insert($table, $row);
        if ($mode === 'orphan_extra') {
            $orphan = $row;
            $orphan['occurrence_id'] = 8000000002;
            $orphan['post_id'] = $this->event->post_id + 999;
            $wpdb->insert($table, $orphan);
        }
        if ($mode === 'save_throw') {
            throw new RuntimeException('injected native occurrence save failure');
        }
        if ($mode === 'stale_driver_error') {
            $wpdb->last_error = 'handled stale error AKIAABCDEFGHIJKLMNOP';
        }
        tribe_cache()->set(
            'tec-occurrence-model-' . (string) $this->event->post_id,
            $row,
            Tribe__Cache::NON_PERSISTENT,
            'save_post'
        );
        $post = get_post($this->event->post_id);
        if ($post instanceof WP_Post) {
            Tribe__Cache_Listener::instance()->save_post($this->event->post_id, $post);
        }
        tec_readiness_native_boundary('save_occurrences');
    }
}

class_alias(TecReadinessNativeColor::class, 'Tribe__Utils__Color');
class_alias(TecReadinessEventModel::class, 'TEC\Events\Custom_Tables\V1\Models\Event');

function untrailingslashit(string $value): string {
    return rtrim($value, '/\\');
}

function wp_upload_dir(mixed $time = null, bool $create = true): array {
    return ['baseurl' => 'https://source.example/uploads'];
}

function wp_hash(string $data, string $scheme = 'auth'): string {
    return hash_hmac('md5', $data, (string) ($GLOBALS['tec_readiness_widget_salt'] ?? 'source-widget-salt'));
}

function wp_strip_all_tags(string $value, bool $removeBreaks = false): string {
    $value = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $value) ?? '';
    $value = strip_tags($value);
    return $removeBreaks ? (string) preg_replace('/[\r\n\t ]+/', ' ', $value) : $value;
}

function did_action(string $name): int {
    return $name === 'wp_loaded' ? 1 : 0;
}

function maybe_unserialize(mixed $value): mixed {
    if (!is_string($value) || preg_match('/^(?:a|O|s|b|i|d|N):/', $value) !== 1) {
        return $value;
    }
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

function sanitize_option(string $name, mixed $value): mixed {
    return apply_filters("sanitize_option_$name", $value, $name, $value);
}

/** @return mixed */
function get_option(string $name, mixed $default = false): mixed {
    if (in_array($name, [
        'permalink_structure',
        'rewrite_rules',
        'tribe_last_generate_rewrite_rules',
        'tribe_last_save_post',
        'tribe_last_updated_option',
    ], true)) {
        $pre = apply_filters("pre_option_$name", false, $name, $default);
        $pre = apply_filters('pre_option', $pre, $name, $default);
        if ($pre !== false) {
            return $pre;
        }
        /** @var FakeWpdb|null $wpdb */
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ($wpdb instanceof FakeWpdb && $wpdb->hasTable($wpdb->options)) {
            foreach ($wpdb->rows($wpdb->options) as $row) {
                if (($row['option_name'] ?? null) === $name) {
                    return apply_filters("option_$name", maybe_unserialize($row['option_value']), $name);
                }
            }
        }
        return apply_filters("default_option_$name", $default, $name, false);
    }
    return $GLOBALS['tec_readiness_options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool {
    $value = sanitize_option($name, $value);
    $old = get_option($name, false);
    $value = apply_filters("pre_update_option_$name", $value, $old, $name);
    $value = apply_filters('pre_update_option', $value, $name, $old);
    if ($old === $value) {
        return false;
    }
    /** @var FakeWpdb $wpdb */
    $wpdb = $GLOBALS['wpdb'];
    $stored = is_array($value) || is_object($value)
        ? serialize($value)
        : (is_bool($value) ? ($value ? '1' : '') : (string) $value);
    $existingAutoload = null;
    foreach ($wpdb->rows($wpdb->options) as $row) {
        if (($row['option_name'] ?? null) === $name) {
            $existingAutoload = $row['autoload'] ?? null;
            break;
        }
    }
    if ($existingAutoload !== null) {
        do_action('update_option', $name, $old, $value);
        $updatedRow = ['option_value' => $stored];
        if ($autoload === null && in_array($existingAutoload, ['auto', 'auto-on', 'auto-off'], true)) {
            apply_filters('wp_default_autoload_value', null, $name, $stored);
            $updatedRow['autoload'] = 'auto-on';
        }
        $changed = $wpdb->update($wpdb->options, $updatedRow, ['option_name' => $name]);
        if ($changed === false) {
            return false;
        }
        wp_cache_set($name, $stored, 'options');
        do_action("update_option_$name", $old, $value, $name);
        do_action('updated_option', $name, $old, $value);
        return true;
    }
    do_action('add_option', $name, $value);
    $autoloadValue = apply_filters(
        'wp_autoload_values_to_autoload',
        $autoload === false ? ['off'] : ['on']
    );
    if ($autoload === null) {
        apply_filters('wp_default_autoload_value', null, $name, $stored);
        $rowAutoload = 'auto-on';
    } else {
        $rowAutoload = $autoload === false || !in_array('on', (array) $autoloadValue, true)
            ? 'off'
            : 'on';
    }
    if (!$wpdb->insert($wpdb->options, [
        'option_name' => $name,
        'option_value' => $stored,
        'autoload' => $rowAutoload,
    ])) {
        return false;
    }
    wp_cache_set($name, $stored, 'options');
    do_action("add_option_$name", $name, $value);
    do_action('added_option', $name, $value);
    return true;
}

/** @return mixed */
function get_term_meta(int $termId, string $key, bool $single = false): mixed {
    return $GLOBALS['tec_readiness_term_meta'][$termId][$key] ?? ($single ? '' : []);
}

/** @return mixed */
function get_post_meta(int $postId, string $key, bool $single = false): mixed {
    if (($GLOBALS['tec_readiness_regen_native_db_reads'] ?? false) === true) {
        $cached = wp_cache_get($postId, 'post_meta', false, $found);
        if (!$found) {
            /** @var FakeWpdb $wpdb */
            $wpdb = $GLOBALS['wpdb'];
            $cached = [];
            foreach ($wpdb->rows($wpdb->postmeta) as $row) {
                if ((int) ($row['post_id'] ?? 0) !== $postId) {
                    continue;
                }
                $metaKey = $row['meta_key'] ?? null;
                if (!is_string($metaKey)) {
                    continue;
                }
                $cached[$metaKey][] = $row['meta_value'] ?? null;
            }
            wp_cache_set($postId, $cached, 'post_meta');
        }
        $values = is_array($cached) && isset($cached[$key]) && is_array($cached[$key])
            ? $cached[$key]
            : [];
        return $single ? ($values[0] ?? '') : $values;
    }
    return $GLOBALS['tec_readiness_post_meta'][$postId][$key] ?? ($single ? '' : []);
}

function get_post(int $postId): ?WP_Post {
    $cached = wp_cache_get($postId, 'posts', false, $found);
    if ($found) {
        return $cached instanceof WP_Post ? $cached : null;
    }
    /** @var FakeWpdb $wpdb */
    $wpdb = $GLOBALS['wpdb'];
    foreach ($wpdb->rows($wpdb->posts) as $row) {
        if ((int) ($row['ID'] ?? 0) !== $postId) {
            continue;
        }
        $post = new WP_Post($row);
        wp_cache_set($postId, $post, 'posts');
        return $post;
    }
    return null;
}

/** @return list<object> */
function get_terms(array $args = []): array {
    return $GLOBALS['tec_readiness_terms'];
}

function tec_readiness_hook_id(callable $callback): string {
    if (is_string($callback)) {
        return $callback;
    }
    if ($callback instanceof Closure) {
        return 'closure:' . spl_object_id($callback);
    }
    if (is_array($callback)) {
        $owner = is_object($callback[0])
            ? get_class($callback[0]) . ':' . spl_object_id($callback[0])
            : (string) $callback[0];
        return $owner . '::' . (string) $callback[1];
    }
    return get_class($callback) . ':' . spl_object_id($callback);
}

function add_filter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
    $hook = $GLOBALS['wp_filter'][$hookName] ??= new WP_Hook();
    $hook->callbacks[$priority][tec_readiness_hook_id($callback)] = [
        'function' => $callback,
        'accepted_args' => $acceptedArgs,
    ];
    ksort($hook->callbacks, SORT_NUMERIC);
    return true;
}

function add_action(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
    return add_filter($hookName, $callback, $priority, $acceptedArgs);
}

function remove_filter(string $hookName, callable $callback, int $priority = 10): bool {
    $hook = $GLOBALS['wp_filter'][$hookName] ?? null;
    if (!$hook instanceof WP_Hook) {
        return false;
    }
    $id = tec_readiness_hook_id($callback);
    if (!isset($hook->callbacks[$priority][$id])) {
        return false;
    }
    unset($hook->callbacks[$priority][$id]);
    if ($hook->callbacks[$priority] === []) {
        unset($hook->callbacks[$priority]);
    }
    if ($hook->callbacks === []) {
        unset($GLOBALS['wp_filter'][$hookName]);
    }
    return true;
}

function remove_action(string $hookName, callable $callback, int $priority = 10): bool {
    return remove_filter($hookName, $callback, $priority);
}

function has_filter(string $hookName, callable|false $callback = false): bool|int {
    $GLOBALS['tec_readiness_has_filter_calls'][] = $hookName;
    if (in_array($hookName, $GLOBALS['tec_readiness_filters'] ?? [], true)) {
        return 10;
    }
    $hook = $GLOBALS['wp_filter'][$hookName] ?? null;
    if (!$hook instanceof WP_Hook) {
        return false;
    }
    if ($callback === false) {
        return $hook->callbacks === [] ? false : true;
    }
    $id = tec_readiness_hook_id($callback);
    foreach ($hook->callbacks as $priority => $callbacks) {
        if (isset($callbacks[$id])) {
            return $priority;
        }
    }
    return false;
}

function apply_filters(string $hookName, mixed $value, mixed ...$args): mixed {
    $hook = $GLOBALS['wp_filter'][$hookName] ?? null;
    if (!$hook instanceof WP_Hook) {
        return $value;
    }
    foreach ($hook->callbacks as $callbacks) {
        foreach ($callbacks as $record) {
            $accepted = max(1, (int) $record['accepted_args']);
            $value = ($record['function'])(...array_slice([$value, ...$args], 0, $accepted));
        }
    }
    return $value;
}

function do_action(string $hookName, mixed ...$args): void {
    $hook = $GLOBALS['wp_filter'][$hookName] ?? null;
    if (!$hook instanceof WP_Hook) {
        return;
    }
    foreach ($hook->callbacks as $callbacks) {
        foreach ($callbacks as $record) {
            ($record['function'])(...array_slice($args, 0, (int) $record['accepted_args']));
        }
    }
}

function is_wp_error(mixed $value): bool {
    return false;
}

function validate_plugin(string $plugin): null {
    return null;
}

/** @return array<string,array{Version:string}> */
function get_plugins(): array {
    return $GLOBALS['tec_readiness_plugins'] ?? [];
}

function sanitize_html_class(string $class): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $class) ?? '';
}

function sanitize_title(string $title): string {
    return strtolower($title);
}

function tribe(?string $class = null): object {
    if ($class === null) {
        return $GLOBALS['tec_readiness_native_container'];
    }
    if (in_array($class, [
        \TEC\Common\Configuration\Configuration::class,
        \TEC\Events\Custom_Tables\V1\Events\Occurrences\Occurrences_Generator::class,
    ], true)) {
        return $GLOBALS['tec_readiness_native_container']->make($class);
    }
    return match ($class) {
        'customizer' => $GLOBALS['tec_readiness_customizer'],
        'cache' => $GLOBALS['tec_readiness_container_cache'],
        'Tribe\\Events\\Views\\V2\\Hooks' => $GLOBALS['tec_readiness_views_hooks'],
        'Tribe\\Events\\Views\\V2\\Kitchen_Sink' => $GLOBALS['tec_readiness_kitchen_sink'],
        'Tribe\\Events\\Views\\V2\\Manager' => $GLOBALS['tec_readiness_views_manager'],
        'TEC\\Events\\QR\\Routes' => $GLOBALS['tec_readiness_qr_routes'],
        'TEC\\Common\\Integrations\\Harbor\\PUE' => $GLOBALS['tec_readiness_harbor_pue'],
        'TEC\\Events\\Category_Colors\\CSS\\Generator' =>
            $GLOBALS['tec_readiness_category_color_generator'],
        'TEC\\Events\\Category_Colors\\Repositories\\Category_Color_Dropdown_Provider' =>
            $GLOBALS['tec_readiness_category_color_dropdown'],
        default => $GLOBALS['tec_readiness_category_color_controller'],
    };
}

function tribe_cache(): object {
    return $GLOBALS['tec_readiness_category_color_cache'];
}

function tec_readiness_maybe_fail_tribe_var(string $operation, string $key): void {
    $configured = $GLOBALS['tec_readiness_tribe_var_failure'] ?? null;
    if (!is_array($configured)) {
        return;
    }
    $plans = array_is_list($configured) ? $configured : [$configured];
    foreach ($plans as $position => $plan) {
        if (!is_array($plan)
            || ($plan['operation'] ?? null) !== $operation
            || ($plan['key'] ?? null) !== $key) {
            continue;
        }
        if (($plan['skip'] ?? 0) > 0) {
            --$plan['skip'];
            $plans[$position] = $plan;
            $GLOBALS['tec_readiness_tribe_var_failure'] = array_is_list($configured)
                ? $plans
                : $plan;
            return;
        }
        if (($plan['once'] ?? true) === true) {
            unset($plans[$position]);
            $plans = array_values($plans);
            $GLOBALS['tec_readiness_tribe_var_failure'] = array_is_list($configured)
                ? $plans
                : null;
        }
        throw new RuntimeException("injected tribe_$operation" . '_var failure');
    }
}

function tribe_set_var(string $key, mixed $value): void {
    $GLOBALS['tec_readiness_tribe_var_writes'][] = ['set', $key, $value];
    tec_readiness_maybe_fail_tribe_var('set', $key);
    $GLOBALS['tec_readiness_tribe_vars'][$key] = $value;
}

function tribe_get_var(string $key, mixed $default = null): mixed {
    return $GLOBALS['tec_readiness_tribe_vars'][$key] ?? $default;
}

function tribe_unset_var(string $key): void {
    $GLOBALS['tec_readiness_tribe_var_writes'][] = ['unset', $key, null];
    tec_readiness_maybe_fail_tribe_var('unset', $key);
    unset($GLOBALS['tec_readiness_tribe_vars'][$key]);
}

function tribe_isset_var(string $key): bool {
    return array_key_exists($key, $GLOBALS['tec_readiness_tribe_vars'] ?? []);
}

function tribe_get_option(string $name, mixed $default = false): mixed {
    return $GLOBALS['tec_readiness_tribe_options'][$name] ?? $default;
}

function wp_using_ext_object_cache(): mixed {
    return $GLOBALS['tec_readiness_external_object_cache'] ?? false;
}

/** @return mixed */
function wp_cache_get(int|string $key, string $group = '', bool $force = false, mixed &$found = null): mixed {
    ++$GLOBALS['tec_readiness_wp_cache_gets'];
    $mode = $GLOBALS['tec_readiness_wp_cache_get_mode'] ?? '';
    if ($mode === 'throw') {
        throw new RuntimeException('injected local object-cache read failure');
    }
    $groupCache = $GLOBALS['tec_readiness_wp_cache'][$group] ?? [];
    $exists = is_array($groupCache) && array_key_exists($key, $groupCache);
    $found = $exists;
    if ($mode === 'nonbool_found') {
        $found = 'malformed';
    }
    return $exists ? $groupCache[$key] : false;
}

function wp_cache_set(int|string $key, mixed $value, string $group = '', int $expire = 0): bool {
    ++$GLOBALS['tec_readiness_wp_cache_sets'];
    $GLOBALS['tec_readiness_wp_cache'][$group][$key] = $value;
    return true;
}

function wp_cache_delete(int|string $key, string $group = ''): mixed {
    ++$GLOBALS['tec_readiness_wp_cache_deletes'];
    $mode = $GLOBALS['tec_readiness_wp_cache_delete_mode'] ?? '';
    if ($mode === 'throw') {
        throw new RuntimeException('injected local object-cache delete failure');
    }
    if ($mode === 'one_throw') {
        $GLOBALS['tec_readiness_wp_cache_delete_mode'] = '';
        throw new RuntimeException('injected local object-cache delete failure');
    }
    if ($mode === 'persistent_failure') {
        return false;
    }
    if ($mode === 'one_failure') {
        $GLOBALS['tec_readiness_wp_cache_delete_mode'] = '';
        return false;
    }
    $existed = isset($GLOBALS['tec_readiness_wp_cache'][$group])
        && array_key_exists($key, $GLOBALS['tec_readiness_wp_cache'][$group]);
    unset($GLOBALS['tec_readiness_wp_cache'][$group][$key]);
    if ($mode === 'nonbool') {
        return 'malformed';
    }
    return $existed;
}

function wp_cache_supports(string $feature): mixed {
    if ($feature !== 'flush_group') {
        return false;
    }
    return $GLOBALS['tec_readiness_wp_cache_group_support'] ?? true;
}

function wp_cache_flush_group(string $group): mixed {
    ++$GLOBALS['tec_readiness_wp_cache_group_flushes'];
    $mode = $GLOBALS['tec_readiness_wp_cache_group_flush_mode'] ?? '';
    if ($mode === 'throw') {
        throw new RuntimeException('injected local object-cache group-flush failure');
    }
    if ($mode === 'persistent_failure') {
        return false;
    }
    if ($mode === 'one_failure') {
        $GLOBALS['tec_readiness_wp_cache_group_flush_mode'] = '';
        return false;
    }
    unset($GLOBALS['tec_readiness_wp_cache'][$group]);
    if ($mode === 'nonbool') {
        return 'malformed';
    }
    return true;
}

function tec_readiness_set_css(string $css): void {
    $GLOBALS['tec_readiness_options']['tec_events_category_color_css'] = $css;
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if ($wpdb instanceof FakeWpdb && $wpdb->hasTable($wpdb->options)) {
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => $css],
            ['option_name' => 'tec_events_category_color_css']
        );
        if ($updated === false) {
            throw new RuntimeException('offline Category Colors CSS row update failed');
        }
    }
}

/** @param list<array<string,mixed>> $extraRows */
function tec_readiness_seed_color_options(array $extraRows = []): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if (!$wpdb instanceof FakeWpdb) {
        throw new RuntimeException('offline Category Colors database is unavailable');
    }
    $wpdb->seedTable($wpdb->options, array_merge([[
        'option_id' => 1,
        'option_name' => 'tec_events_category_color_css',
        'option_value' => (string) ($GLOBALS['tec_readiness_options']['tec_events_category_color_css'] ?? ''),
        'autoload' => 'on',
    ]], $extraRows));
}

function tec_readiness_sync_color_db(): void {
    $wpdb = $GLOBALS['wpdb'] ?? null;
    if (!$wpdb instanceof FakeWpdb) {
        throw new RuntimeException('offline Category Colors database is unavailable');
    }
    $terms = [];
    $taxonomy = [];
    $meta = [];
    $metaId = 1;
    foreach ($GLOBALS['tec_readiness_terms'] ?? [] as $term) {
        $termId = (int) ($term->term_id ?? 0);
        $terms[] = [
            'term_id' => $termId,
            'slug' => (string) ($term->slug ?? ''),
            'name' => (string) ($term->name ?? ''),
        ];
        $taxonomy[] = [
            'term_taxonomy_id' => $termId,
            'term_id' => $termId,
            'taxonomy' => 'tribe_events_cat',
        ];
        foreach (($GLOBALS['tec_readiness_term_meta'][$termId] ?? []) as $key => $value) {
            $meta[] = [
                'meta_id' => $metaId++,
                'term_id' => $termId,
                'meta_key' => (string) $key,
                'meta_value' => (string) $value,
            ];
        }
    }
    $wpdb->seedTable($wpdb->terms, $terms);
    $wpdb->seedTable($wpdb->term_taxonomy, $taxonomy);
    $wpdb->seedTable($wpdb->termmeta, $meta);
    tec_readiness_seed_color_options();
}

/** @return array<string,mixed> */
function tec_readiness_meta(): array {
    return [
        '_EventCostDescription' => 'Admission details 東京 — bring ID',
        '_EventDateTimeSeparator' => ' · at · ',
        '_EventDuration' => '10800',
        '_EventEndDate' => '2026-09-05 20:00:00',
        '_EventEndDateUTC' => '2026-09-05 14:15:00',
        '_EventOrganizerID' => [
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
            '{{post:' . TEC_ORGANIZER_TWO_UUID . '}}',
        ],
        '_EventOrigin' => 'events-calendar',
        '_EventShowMap' => '1',
        '_EventShowMapLink' => '1',
        '_EventStartDate' => '2026-09-05 17:00:00',
        '_EventStartDateUTC' => '2026-09-05 11:15:00',
        '_EventTimezone' => 'Asia/Kathmandu',
        '_EventTimezoneAbbr' => '+0545',
        '_EventTimeRangeSeparator' => ' · until · ',
        '_EventVenueID' => '{{post:' . TEC_VENUE_UUID . '}}',
    ];
}

/** @return array<string,mixed> */
function tec_readiness_post(
    string $uuid,
    string $type,
    array $meta = [],
    string $slug = '',
    ?string $body = null
): array {
    return [
        'type' => 'post',
        'path' => "state/posts/$type/$uuid--" . ($slug !== '' ? $slug : $type) . '.md',
        'data' => [
            'type' => $type,
            'uuid' => $uuid,
            'slug' => $slug !== '' ? $slug : $type,
            'meta' => $meta,
        ],
        'body' => $body ?? str_repeat('Long UTF-8 event boundary — বাংলা — こんにちは. ', 600),
    ];
}

/** @return array<string,mixed> */
function tec_readiness_term(array $meta): array {
    return [
        'type' => 'term',
        'path' => 'state/terms/tribe_events_cat/' . TEC_CATEGORY_UUID . '--readiness.json',
        'data' => [
            'taxonomy' => 'tribe_events_cat',
            'uuid' => TEC_CATEGORY_UUID,
            'slug' => 'readiness',
            'name' => 'Readiness',
            'meta' => $meta,
        ],
    ];
}

/** @return list<array<string,mixed>> */
function tec_readiness_tree(
    ?array $meta = null,
    ?array $termMeta = null,
    ?array $venueMeta = null,
    ?string $eventBody = null
): array {
    return [
        tec_readiness_post(
            TEC_EVENT_UUID,
            'tribe_events',
            $meta ?? tec_readiness_meta(),
            'production-readiness-event',
            $eventBody
        ),
        tec_readiness_post(TEC_VENUE_UUID, 'tribe_venue', $venueMeta ?? [
            '_EventShowMap' => 'false',
            '_EventShowMapLink' => 'false',
            '_VenueShowMap' => 'false',
            '_VenueShowMapLink' => 'false',
        ], 'readiness-hall'),
        tec_readiness_post(TEC_ORGANIZER_UUID, 'tribe_organizer', [], 'readiness-team'),
        tec_readiness_post(TEC_ORGANIZER_TWO_UUID, 'tribe_organizer', [], 'readiness-team-two'),
        tec_readiness_term($termMeta ?? [
            'tec-events-cat-colors-primary' => '#123abc',
            'tec-events-cat-colors-secondary' => '#abcdef',
            'tec-events-cat-colors-text' => '#ffffff',
            'tec-events-cat-colors-priority' => '17',
            'tec-events-cat-colors-hidden' => '0',
        ]),
    ];
}

/** @param list<string> $tokens */
function tec_readiness_organizer_blocks(array $tokens, bool $prependEmpty = false): string {
    $blocks = $prependEmpty ? ['<!-- wp:tribe/event-organizer /-->'] : [];
    foreach ($tokens as $token) {
        $blocks[] = '<!-- wp:tribe/event-organizer '
            . json_encode(['organizer' => $token], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ' /-->';
    }
    return implode("\n", $blocks);
}

function tec_readiness_legacy_widget_block(array $attrs): string {
    return '<!-- wp:legacy-widget '
        . json_encode($attrs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        . ' /-->';
}

function tec_readiness_embedded_widget(array $settings): array {
    $serialized = serialize($settings);
    return [
        'encoded' => base64_encode($serialized),
        'hash' => wp_hash($serialized),
    ];
}

/** @return list<string> */
function tec_readiness_messages(TheEventsCalendar $interpreter, array $tree): array {
    return array_map(
        static fn(array $diagnostic): string => (string) ($diagnostic['message'] ?? ''),
        $interpreter->repository_diagnostics($tree)
    );
}

function tec_readiness_refuses(TheEventsCalendar $interpreter, array $tree, string $needle, string $message): void {
    duo_check(
        str_contains(implode(' | ', tec_readiness_messages($interpreter, $tree)), $needle),
        $message
    );
}

$root = dirname(__DIR__, 4);
$manifest = json_decode(
    (string) file_get_contents($root . '/manifests/the-events-calendar.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$artifacts = json_decode(
    (string) file_get_contents($root . '/sandbox/conformance/artifacts.lock.json'),
    true,
    flags: JSON_THROW_ON_ERROR
)['plugins']['the-events-calendar'];
$disposition = json_decode(
    (string) file_get_contents($root . '/manifests/dispositions.json'),
    true,
    flags: JSON_THROW_ON_ERROR
)['manifests']['the-events-calendar'];

duo_check_same(
    ['min' => '6.17.2', 'max' => '6.17.4'],
    $manifest['version_range'],
    'the exclusive range admits the reviewed 6.17.2/6.17.3 artifact family without admitting 6.17.4'
);
duo_check_same(
    ['6.17.1', '6.17.2', '6.17.3'],
    array_keys($artifacts),
    'the artifact lock carries one real adjacent refusal and both exact boundaries'
);
duo_check_same('refusal-fixture', $artifacts['6.17.1']['role'], '6.17.1 is an adjacent refusal artifact');
duo_check_same('exercise-fixture', $artifacts['6.17.2']['role'], '6.17.2 remains a candidate exercise artifact until final evidence is green');
duo_check_same('exercise-fixture', $artifacts['6.17.3']['role'], '6.17.3 remains a candidate exercise artifact until final evidence is green');
duo_check_same(
    '2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687',
    $artifacts['6.17.3']['sha256'],
    'the upper-bound official ZIP digest is immutable review input'
);
duo_check_same(
    ['post:tribe_events', 'post:tribe_organizer', 'post:tribe_venue', 'term:tribe_events_cat'],
    $disposition['capabilities']['deletion_semantics']['unsupported'] ?? null,
    'the reviewed claim names every free TEC entity whose native deletion effects remain unsupported'
);

$policy = Policy::load(null, ['the-events-calendar']);
$interpreter = $policy->interpreters()['the-events-calendar'];
duo_check($interpreter instanceof TheEventsCalendar, 'the manifest resolves its digest-bound TEC interpreter');
$savedVersionPlugins = $GLOBALS['tec_readiness_plugins'] ?? null;
$savedVersionOptions = $GLOBALS['tec_readiness_options'] ?? null;
$tecPlugin = 'the-events-calendar/the-events-calendar.php';
$GLOBALS['tec_readiness_options']['active_plugins'] = [$tecPlugin];
foreach (['6.17.2', '6.17.3'] as $inRangeVersion) {
    $GLOBALS['tec_readiness_plugins'] = [$tecPlugin => ['Version' => $inRangeVersion]];
    duo_check_same(
        [],
        Deploy::code_mismatch($policy, ['active_plugins' => [$tecPlugin]]),
        "the real lifecycle planner admits exact TEC $inRangeVersion"
    );
}
foreach (['6.17.1', '6.17.4'] as $outOfRangeVersion) {
    $GLOBALS['tec_readiness_plugins'] = [$tecPlugin => ['Version' => $outOfRangeVersion]];
    $beforeVersionRefusal = serialize([
        $GLOBALS['tec_readiness_plugins'],
        $GLOBALS['tec_readiness_options'],
    ]);
    $versionRows = Deploy::code_mismatch($policy, ['active_plugins' => [$tecPlugin]]);
    duo_check_same(1, count($versionRows), "TEC $outOfRangeVersion produces one lifecycle refusal");
    duo_check_same(
        [
            'issue' => 'outside_version_range',
            'kind' => 'plugin',
            'plugin' => $tecPlugin,
            'installed_version' => $outOfRangeVersion,
            'version_range' => ['min' => '6.17.2', 'max' => '6.17.4'],
            'manifest' => 'the-events-calendar',
        ],
        array_intersect_key($versionRows[0] ?? [], array_flip([
            'issue',
            'kind',
            'plugin',
            'installed_version',
            'version_range',
            'manifest',
        ])),
        "TEC $outOfRangeVersion refusal binds the exact basename, installed header and exclusive range"
    );
    duo_check_same(
        $beforeVersionRefusal,
        serialize([$GLOBALS['tec_readiness_plugins'], $GLOBALS['tec_readiness_options']]),
        "TEC $outOfRangeVersion range diagnosis is read-only before lifecycle mutation"
    );
}
if ($savedVersionPlugins === null) {
    unset($GLOBALS['tec_readiness_plugins']);
} else {
    $GLOBALS['tec_readiness_plugins'] = $savedVersionPlugins;
}
if ($savedVersionOptions === null) {
    unset($GLOBALS['tec_readiness_options']);
} else {
    $GLOBALS['tec_readiness_options'] = $savedVersionOptions;
}
duo_check_same(
    [['kind' => 'post', 'path' => 'organizer', 'type' => 'int']],
    $policy->block_attr_rules()['tribe/event-organizer'] ?? null,
    'the shipped TEC policy declares the registered scalar organizer block reference exactly'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree()),
    'a classic-editor long UTF-8 timed event graph without organizer blocks remains schema-clean'
);

$unlinked = tec_readiness_meta();
unset($unlinked['_EventVenueID'], $unlinked['_EventOrganizerID']);
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($unlinked)), 'legitimately absent venue and organizer references stay clean');

$singleOrganizer = tec_readiness_meta();
$singleOrganizer['_EventOrganizerID'] = ['{{post:' . TEC_ORGANIZER_UUID . '}}'];
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $singleOrganizer,
        null,
        null,
        tec_readiness_organizer_blocks($singleOrganizer['_EventOrganizerID'])
    )),
    'one Gutenberg organizer block matches its one-row canonical metadata list'
);
$reorderedOrganizers = tec_readiness_meta();
$reorderedOrganizers['_EventOrganizerID'] = array_reverse($reorderedOrganizers['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $reorderedOrganizers,
        null,
        null,
        tec_readiness_organizer_blocks($reorderedOrganizers['_EventOrganizerID'], true)
    )),
    'multiple unique organizer blocks preserve native physical order while an empty editor placeholder is harmless'
);
$emptyOrganizerMeta = tec_readiness_meta();
unset($emptyOrganizerMeta['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $emptyOrganizerMeta,
        null,
        null,
        '<!-- wp:tribe/event-organizer /-->'
    )),
    'the registered empty Gutenberg organizer placeholder matches absent organizer metadata'
);
foreach ([
    'A literal wp:tribe/event-organizer marker is ordinary classic/freeform text.',
    'A literal <!-- wp:tribe/event-organizer text prefix is not a complete block comment.',
    '<!-- wp:code --><pre class="wp-block-code"><code>&lt;!-- wp:tribe/event-organizer /--&gt;</code></pre><!-- /wp:code -->',
    '<!-- wp:tribe/event-organizer-preview {"organizer":7000000001} /-->',
    '<!-- wp:block {"ref":"{{post:' . TEC_ORGANIZER_UUID . '}}"} /-->',
] as $nonOrganizerBody) {
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree(null, null, null, $nonOrganizerBody)),
        'literal/code, prefix-named, and reusable blocks are not mistaken for exact TEC organizer blocks'
    );
}
$nestedOrganizerBody = '<!-- wp:group --><div class="wp-block-group">'
    . tec_readiness_organizer_blocks(tec_readiness_meta()['_EventOrganizerID'])
    . '</div><!-- /wp:group -->';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, null, $nestedOrganizerBody)),
    'nested organizer blocks remain coherent because their attributes and authoritative repeated rows agree despite the native top-level-only supplemental reorder scan'
);
$nestedReorderedMeta = tec_readiness_meta();
$nestedReorderedMeta['_EventOrganizerID'] = array_reverse($nestedReorderedMeta['_EventOrganizerID']);
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree($nestedReorderedMeta, null, null, $nestedOrganizerBody),
    'must exactly match _EventOrganizerID row order',
    'nested organizer blocks are inspected recursively and refuse when physical metadata order diverges'
);
$markerCounter = new ReflectionMethod($interpreter, 'organizer_block_marker_count');
$markerFloodCount = 2048;
$markerFlood = str_repeat('<!-- wp:tribe/event-organizer /-->', $markerFloodCount)
    . '<!-- wp:tribe/event-organizer literal incomplete tail '
    . str_repeat('x', 1024 * 1024);
$oldBacktrackLimit = ini_set('pcre.backtrack_limit', '1');
try {
    duo_check_same(
        $markerFloodCount,
        $markerCounter->invoke($interpreter, $markerFlood),
        'the exact linear marker counter handles many complete comments and a huge incomplete tail independently of PCRE limits'
    );
} finally {
    if ($oldBacktrackLimit !== false) {
        ini_set('pcre.backtrack_limit', $oldBacktrackLimit);
    }
}
$manyEmptyMeta = tec_readiness_meta();
unset($manyEmptyMeta['_EventOrganizerID']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(
        $manyEmptyMeta,
        null,
        null,
        str_repeat('<!-- wp:tribe/event-organizer /-->', 512)
    )),
    'very many complete empty organizer placeholders traverse the real parser without false mismatch or diagnostics'
);
$manyMalformed = $interpreter->repository_diagnostics(tec_readiness_tree(
    null,
    null,
    null,
    str_repeat('<!-- wp:tribe/event-organizer ??? -->', 512)
));
duo_check(
    count($manyMalformed) === 1
        && strlen((string) ($manyMalformed[0]['message'] ?? '')) < 160,
    'a hostile flood of malformed exact comments produces one bounded schema diagnostic'
);
$hostileParent = 'hostile/' . str_repeat('p', 8192);
$hostileNestedBody = '<!-- wp:' . $hostileParent . ' -->'
    . '<!-- wp:tribe/event-organizer {"organizer":0} /-->'
    . '<!-- /wp:' . $hostileParent . ' -->'
    . "\xc3\x28";
$hostileNestedDiagnostics = $interpreter->repository_diagnostics(tec_readiness_tree(
    null,
    null,
    null,
    $hostileNestedBody
));
duo_check(
    $hostileNestedDiagnostics !== []
        && !str_contains(json_encode($hostileNestedDiagnostics, JSON_THROW_ON_ERROR), $hostileParent)
        && strlen((string) ($hostileNestedDiagnostics[0]['locator'] ?? '')) < 128,
    'nested diagnostics use a fixed locator and never expose a huge authored parent name or invalid UTF-8 body bytes'
);

$emptyBlockWithMeta = '<!-- wp:tribe/event-organizer /-->';
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(null, null, null, $emptyBlockWithMeta),
    'must exactly match _EventOrganizerID row order',
    'an empty-only organizer placeholder refuses when populated metadata would render differently'
);
$missingOrganizerUuid = '66666666-6666-4666-8666-666666666666';
$missingOrganizerMeta = tec_readiness_meta();
$missingOrganizerMeta['_EventOrganizerID'] = ['{{post:' . $missingOrganizerUuid . '}}'];
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        $missingOrganizerMeta,
        null,
        null,
        tec_readiness_organizer_blocks($missingOrganizerMeta['_EventOrganizerID'])
    ),
    'UUID must resolve to one captured tribe_organizer post',
    'an organizer block UUID absent from the captured graph refuses'
);
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        null,
        null,
        null,
        '<!-- wp:tribe/event-organizer ??? -->'
    ),
    'markup must parse as exact registered blocks',
    'a malformed exact organizer comment dropped by parse_blocks refuses rather than becoming freeform content'
);

$blockBoundaryCases = [
    [
        '<!-- wp:tribe/event-organizer {"organizer":0} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a raw zero organizer default refuses because canonical capture must remove the attribute',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":7000000001} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a huge raw local organizer block ID refuses after structural capture should have tokenized it',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":{"id":"{{post:' . TEC_ORGANIZER_UUID . '}}"}} /-->',
        tec_readiness_meta(),
        'canonical post UUID token',
        'a nested organizer attribute shape refuses rather than being cast to a local ID',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizers":["{{post:' . TEC_ORGANIZER_UUID . '}}"]} /-->',
        tec_readiness_meta(),
        'meta-sourced and must not be serialized',
        'the registered meta-sourced organizers list cannot leak into block content',
    ],
    [
        tec_readiness_organizer_blocks([
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
            '{{post:' . TEC_ORGANIZER_UUID . '}}',
        ]),
        tec_readiness_meta(),
        'must be unique in editor order',
        'duplicate populated organizer blocks refuse before native array_unique can hide the defect',
    ],
    [
        tec_readiness_organizer_blocks(array_reverse(tec_readiness_meta()['_EventOrganizerID'])),
        tec_readiness_meta(),
        'must exactly match _EventOrganizerID row order',
        'reordered organizer blocks refuse when the repeated metadata order disagrees',
    ],
    [
        '<!-- wp:tribe/event-organizer {"organizer":"{{post:' . TEC_ORGANIZER_UUID . '}}"} -->'
            . '<!-- wp:paragraph --><p>not native organizer content</p><!-- /wp:paragraph -->'
            . '<!-- /wp:tribe/event-organizer -->',
        tec_readiness_meta(),
        'must not carry nested blocks',
        'nested content in the dynamic organizer block refuses',
    ],
];
foreach ($blockBoundaryCases as [$body, $meta, $needle, $message]) {
    tec_readiness_refuses($interpreter, tec_readiness_tree($meta, null, null, $body), $needle, $message);
}
$wrongBlockOwner = tec_readiness_meta();
$wrongBlockOwner['_EventOrganizerID'] = ['{{post:' . TEC_VENUE_UUID . '}}'];
tec_readiness_refuses(
    $interpreter,
    tec_readiness_tree(
        $wrongBlockOwner,
        null,
        null,
        tec_readiness_organizer_blocks($wrongBlockOwner['_EventOrganizerID'])
    ),
    'organizer block must resolve to post type tribe_organizer',
    'an organizer block resolving to a venue refuses independently of scalar token validity'
);

foreach ([
    ['canceled', 'Doors closed because of <strong>weather</strong>.'],
    ['postponed', ''],
] as [$status, $reason]) {
    $statusMeta = tec_readiness_meta();
    $statusMeta['_tribe_events_status'] = $status;
    $statusMeta['_tribe_events_status_reason'] = $reason;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($statusMeta)),
        "$status status accepts its native paired arbitrary-string reason shape"
    );
}
$statusDeleted = tec_readiness_meta();
unset($statusDeleted['_tribe_events_status'], $statusDeleted['_tribe_events_status_reason']);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($statusDeleted)),
    'scheduled status and explicit status deletion both use native absence of both rows'
);

$optionalEditorMetaAbsent = tec_readiness_meta();
unset(
    $optionalEditorMetaAbsent['_EventCostDescription'],
    $optionalEditorMetaAbsent['_EventDateTimeSeparator'],
    $optionalEditorMetaAbsent['_EventTimeRangeSeparator']
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($optionalEditorMetaAbsent)),
    'absent or deleted optional Gutenberg-authored event metadata stays clean'
);
$optionalEditorMetaUpdated = tec_readiness_meta();
$optionalEditorMetaUpdated['_EventCostDescription'] = 'Updated plain description বাংলা';
$optionalEditorMetaUpdated['_EventDateTimeSeparator'] = "\nthrough\t";
$optionalEditorMetaUpdated['_EventTimeRangeSeparator'] = ' & through & ';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($optionalEditorMetaUpdated)),
    'native-sanitized Gutenberg metadata accepts updates and separator whitespace'
);
$nativeFalseEvent = tec_readiness_meta();
$nativeFalseEvent['_EventShowMap'] = '';
$nativeFalseEvent['_EventShowMapLink'] = '';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($nativeFalseEvent)),
    'event repository false uses exact empty postmeta values'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, [
        '_VenueShowMap' => '',
        '_VenueShowMapLink' => '1',
    ])),
    'venue repository empty/1 map values and absent legacy mirrors stay clean'
);
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree(null, null, [])),
    'repository venue map rows may be absent when the owning API does not write them'
);

$allDay = tec_readiness_meta();
$allDay['_EventAllDay'] = 'yes';
$allDay['_EventStartDate'] = '2026-09-05 00:00:00';
$allDay['_EventEndDate'] = '2026-09-05 23:59:59';
$allDay['_EventStartDateUTC'] = '2026-09-04 18:15:00';
$allDay['_EventEndDateUTC'] = '2026-09-05 18:14:59';
$allDay['_EventDuration'] = '86399';
duo_check_same([], $interpreter->repository_diagnostics(tec_readiness_tree($allDay)), 'the exact all-day yes wire shape and day bounds are clean');

$registeredAllDay = $allDay;
$registeredAllDay['_EventAllDay'] = '1';
duo_check_same(
    [],
    $interpreter->repository_diagnostics(tec_readiness_tree($registeredAllDay)),
    'the registered Gutenberg all-day true wire is clean'
);
foreach (['', 'no'] as $falseWire) {
    $timed = tec_readiness_meta();
    $timed['_EventAllDay'] = $falseWire;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($timed)),
        "the native all-day false wire '$falseWire' is clean"
    );
}
foreach (['', '1', 'yes'] as $hideWire) {
    $hidden = tec_readiness_meta();
    $hidden['_EventHideFromUpcoming'] = $hideWire;
    duo_check_same(
        [],
        $interpreter->repository_diagnostics(tec_readiness_tree($hidden)),
        "the native hide-from-upcoming wire '$hideWire' is clean"
    );
}

foreach (['_EventStartDate', '_EventEndDate', '_EventStartDateUTC', '_EventEndDateUTC', '_EventDuration', '_EventTimezone'] as $key) {
    $bad = tec_readiness_meta();
    unset($bad[$key]);
    tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'requires a non-empty string', "missing required $key refuses");
}

$bad = tec_readiness_meta();
$bad['_EventStartDate'] = '2026-02-30 17:00:00';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact real Y-m-d H:i:s', 'an impossible local date refuses');
$bad = tec_readiness_meta();
$bad['_EventTimezone'] = 'Asia/Not_A_Real_Zone';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'timezone identifier', 'an unknown event timezone refuses');
$bad = tec_readiness_meta();
$bad['_EventStartDateUTC'] = '2026-09-05 11:16:00';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'UTC start instants disagree', 'contradictory local/UTC start values refuse');
$bad = tec_readiness_meta();
$bad['_EventEndDate'] = '2026-09-05 16:00:00';
$bad['_EventEndDateUTC'] = '2026-09-05 10:15:00';
$bad['_EventDuration'] = '0';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'end must not precede', 'negative event intervals refuse');
foreach (['010800', '-1', '1.5', 'not-seconds'] as $duration) {
    $bad = tec_readiness_meta();
    $bad['_EventDuration'] = $duration;
    tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'canonical non-negative decimal', "malformed duration '$duration' refuses");
}
$bad = tec_readiness_meta();
$bad['_EventDuration'] = '10801';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'disagrees with the authored UTC interval', 'duration/date inconsistency refuses');

$bad = tec_readiness_meta();
$bad['_EventVenueID'] = '7000000001';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'canonical post UUID token', 'a huge raw local venue ID refuses');
$bad = tec_readiness_meta();
$bad['_EventVenueID'] = '{{post:' . TEC_ORGANIZER_UUID . '}}';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'post type tribe_venue', 'a venue reference resolving to an organizer refuses');
$bad = tec_readiness_meta();
$bad['_EventOrganizerID'] = ['{{post:' . TEC_VENUE_UUID . '}}'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'post type tribe_organizer', 'an organizer reference resolving to a venue refuses');
foreach ([
    ['{{post:' . TEC_ORGANIZER_UUID . '}}', 'ordered list'],
    [[], 'ordered list'],
    [['7000000001'], 'canonical post UUID token'],
    [[
        '{{post:' . TEC_ORGANIZER_UUID . '}}',
        '{{post:' . TEC_ORGANIZER_UUID . '}}',
    ], 'must be unique'],
] as [$badOrganizerRows, $needle]) {
    $bad = tec_readiness_meta();
    $bad['_EventOrganizerID'] = $badOrganizerRows;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        $needle,
        'malformed scalar, empty, raw-id, and duplicate organizer row shapes refuse before deploy'
    );
}

foreach (['scheduled', 'cancelled', '', 7] as $badStatus) {
    $bad = tec_readiness_meta();
    $bad['_tribe_events_status'] = $badStatus;
    $bad['_tribe_events_status_reason'] = 'reason';
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'scheduled is represented by absence',
        'unknown, scheduled-as-row, empty, and non-string event statuses refuse'
    );
}
$bad = tec_readiness_meta();
$bad['_tribe_events_status'] = 'canceled';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'present or absent together', 'status without a reason row refuses');
$bad = tec_readiness_meta();
$bad['_tribe_events_status_reason'] = 'orphan reason';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'present or absent together', 'reason without a status row refuses');
$bad = tec_readiness_meta();
$bad['_tribe_events_status'] = 'postponed';
$bad['_tribe_events_status_reason'] = ['not' => 'a string'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'reason must remain one scalar string', 'structured status reason refuses');

foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
    foreach (['0', 'false', 'yes'] as $value) {
        $bad = tec_readiness_meta();
        $bad[$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            tec_readiness_tree($bad),
            'exact empty/1 wire value',
            "$key rejects non-native event repository spelling '$value'"
        );
    }
}
foreach (['_EventShowMap', '_EventShowMapLink', '_VenueShowMap', '_VenueShowMapLink'] as $key) {
    foreach (['0', 'true', 'yes'] as $value) {
        $bad = tec_readiness_tree();
        $bad[1]['data']['meta'][$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            $bad,
            'native empty/1/false wire value',
            "$key rejects non-native venue spelling '$value'"
        );
    }
}
foreach (['0', 'false', 'true', 1, false, true] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventAllDay'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'exact current empty/1/no/yes wire value',
        'all-day rejects non-native persisted boolean spellings and non-string values'
    );
}
foreach (['0', 'false', 'no', 'true', 1, false, true] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventHideFromUpcoming'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'exact current empty/1/yes wire value',
        'hide-from-upcoming rejects non-native persisted spellings and non-string values'
    );
}
$bad = tec_readiness_meta();
$bad['_tribe_featured'] = 'yes';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'exact 1 wire value', 'featured rejects a non-native boolean spelling');
$bad = tec_readiness_meta();
$bad['_EventCurrencyPosition'] = 'suffix';
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'prefix or postfix', 'an unsupported currency position refuses');
$bad = tec_readiness_meta();
$bad['_EventRecurrence'] = ['rules' => [['type' => 'Every Week']]];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'Pro recurrence state', 'free-adapter recurrence state refuses before deploy');
foreach (['_EventRecurrenceRRULE', '_tribe_aggregator_global_id', '_tribe_legacy_ignored_event'] as $key) {
    $bad = tec_readiness_meta();
    $bad[$key] = 'outside-free-contract';
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        str_starts_with($key, '_EventRecurrence') ? 'Pro recurrence state' : 'Event Aggregator/import state',
        "$key remains a loud licensed/import boundary"
    );
}
$bad = tec_readiness_meta();
$bad['_EventCost'] = ['serialized' => 'future schema'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'one scalar string', 'structured event cost refuses instead of being serialized into native meta');
$bad = tec_readiness_meta();
$bad['_EventCostDescription'] = ['serialized' => 'future schema'];
tec_readiness_refuses($interpreter, tec_readiness_tree($bad), 'one scalar string', 'structured cost description refuses before registered-meta consumption');
foreach ([' leading', 'trailing ', 'two  spaces', "line\nbreak", '<b>markup</b>', 'pay%20now'] as $value) {
    $bad = tec_readiness_meta();
    $bad['_EventCostDescription'] = $value;
    tec_readiness_refuses(
        $interpreter,
        tec_readiness_tree($bad),
        'native sanitize_text_field shape',
        'cost description refuses values the native registered-meta sanitizer would rewrite'
    );
}
foreach (['_EventDateTimeSeparator', '_EventTimeRangeSeparator'] as $key) {
    foreach (['<em>until</em>', '&amp;'] as $value) {
        $bad = tec_readiness_meta();
        $bad[$key] = $value;
        tec_readiness_refuses(
            $interpreter,
            tec_readiness_tree($bad),
            'native separator sanitizer shape',
            "$key refuses values the plugin's entity/tag sanitizer would rewrite"
        );
    }
}
$linkedTree = tec_readiness_tree();
$linkedTree[1]['data']['meta']['_VenueURL'] = (object) ['url' => 'https://invalid.example.test'];
tec_readiness_refuses($interpreter, $linkedTree, 'one scalar string', 'structured venue metadata refuses before native code consumes it');
$wrongOwner = tec_readiness_tree();
$wrongOwner[0]['data']['meta']['_VenueShowMap'] = '1';
tec_readiness_refuses($interpreter, $wrongOwner, 'belongs only to a tribe_venue', 'venue map flags refuse on event posts');
$wrongOwner = tec_readiness_tree();
$wrongOwner[2]['data']['meta']['_EventShowMap'] = '1';
tec_readiness_refuses($interpreter, $wrongOwner, 'does not belong to a tribe_organizer', 'map flags refuse on organizer posts');
foreach (['_VenueLat', '_VenueLng'] as $key) {
    $coordinateTree = tec_readiness_tree();
    $coordinateTree[1]['data']['meta'][$key] = $key === '_VenueLat' ? '27.7172' : '85.3240';
    tec_readiness_refuses(
        $interpreter,
        $coordinateTree,
        'Pro/Event Aggregator coordinate state',
        "$key refuses loudly because free TEC has no coordinate writer or reader"
    );
}

foreach ([
    ['tec-events-cat-colors-primary', 'red', 'six-digit hex color'],
    ['tec-events-cat-colors-priority', '-1', 'canonical non-negative decimal'],
    ['tec-events-cat-colors-hidden', 'yes', 'empty/0/1 wire value'],
] as [$key, $value, $needle]) {
    $termMeta = [
        'tec-events-cat-colors-primary' => '#123abc',
        'tec-events-cat-colors-priority' => '7',
        'tec-events-cat-colors-hidden' => '0',
    ];
    $termMeta[$key] = $value;
    tec_readiness_refuses($interpreter, tec_readiness_tree(null, $termMeta), $needle, "malformed category color field $key refuses");
}

$eventMetaKeys = [
    '_EventAllDay', '_EventCost', '_EventCostDescription', '_EventCostMax', '_EventCostMin',
    '_EventCurrencyCode', '_EventCurrencyPosition', '_EventCurrencySymbol', '_EventDateTimeSeparator',
    '_EventDuration', '_EventEndDate', '_EventEndDateUTC', '_EventHideFromUpcoming',
    '_EventOrganizerID', '_EventOrigin', '_EventPhone', '_EventShowMap', '_EventShowMapLink',
    '_EventStartDate', '_EventStartDateUTC', '_EventTimezone', '_EventTimezoneAbbr',
    '_EventTimeRangeSeparator', '_EventURL', '_EventVenueID', '_tribe_events_status',
    '_tribe_events_status_reason', '_tribe_featured',
];
foreach ($eventMetaKeys as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key is reviewed authored TEC state");
}
foreach (['_preview_organizers', '_preview_venues', '_tribe_events_errors', '_tribe_modified_fields'] as $key) {
    duo_check_same('runtime', $policy->post_meta_rule($key)['class'] ?? null, "$key remains target-runtime state");
}
duo_check_same(
    [
        'cardinality' => 'one_or_more',
        'duplicates' => 'forbid',
        'order' => 'preserve',
    ],
    $policy->post_meta_rule('_EventOrganizerID')['repeated_rows'] ?? null,
    'organizer storage declares the exact reviewed ordered unique physical-row grammar'
);
duo_check_same(
    'string',
    $policy->post_meta_rule('_EventOrganizerID')['cast'] ?? null,
    'organizer row refs restore TEC native digit-string postmeta bytes'
);

$savedOrganizerDb = $GLOBALS['wpdb'] ?? null;
$organizerSourceId = 6100000001;
$sourceOrganizerIds = [700000001, 800000003];
$targetOrganizerIds = [900000001, 37];
$organizerSourceDb = new FakeWpdb();
$organizerSourceDb->seedTable($organizerSourceDb->postmeta, [
    [
        'meta_id' => 11,
        'post_id' => $organizerSourceId,
        'meta_key' => '_EventOrganizerID',
        'meta_value' => (string) $sourceOrganizerIds[0],
    ],
    [
        'meta_id' => 12,
        'post_id' => $organizerSourceId,
        'meta_key' => '_EventOrganizerID',
        'meta_value' => (string) $sourceOrganizerIds[1],
    ],
    [
        'meta_id' => 13,
        'post_id' => $organizerSourceId,
        'meta_key' => '_preview_organizers',
        'meta_value' => serialize($sourceOrganizerIds),
    ],
]);
$organizerSourceDb->seedTable($organizerSourceDb->prefix . 'duo_map', [
    [
        'uuid' => TEC_ORGANIZER_UUID,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $sourceOrganizerIds[0],
    ],
    [
        'uuid' => TEC_ORGANIZER_TWO_UUID,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $sourceOrganizerIds[1],
    ],
]);
$GLOBALS['wpdb'] = $organizerSourceDb;
$organizerCaptureCheckpoints = 0;
$organizerTokens = new Tokens('https://source.example', 'https://source.example/uploads');
$organizerCapture = new EntityMetaCapture(
    $policy,
    $organizerTokens,
    static function (mixed ...$_unused): void {},
    static function () use (&$organizerCaptureCheckpoints): void {
        ++$organizerCaptureCheckpoints;
    },
    static function (mixed ...$_unused): void {}
);
$sourceOrganizerByKey = $organizerCapture->postMetaByKey($organizerSourceId);
$sourceOrganizerFlat = array_map(
    static fn(array $values): mixed => $values[0] ?? null,
    $sourceOrganizerByKey
);
[$storeOrganizerRows, $capturedOrganizerRows] = $organizerCapture->classifyValue(
    '_EventOrganizerID',
    $sourceOrganizerByKey['_EventOrganizerID'] ?? [],
    $sourceOrganizerFlat,
    'TEC source event',
    'post_meta'
);
duo_check_same(true, $storeOrganizerRows, 'TEC capture stores the declared repeated organizer row set');
duo_check_same(
    [
        '{{post:' . TEC_ORGANIZER_UUID . '}}',
        '{{post:' . TEC_ORGANIZER_TWO_UUID . '}}',
    ],
    $capturedOrganizerRows,
    'TEC capture tokenizes divergent large organizer IDs in exact physical order'
);
duo_check_same(
    3,
    $organizerCaptureCheckpoints,
    'TEC repeated-row capture checkpoints bounded size, hash and value reads independently'
);

$organizerTargetInner = new FakeWpdb();
$organizerTargetDb = new LockingFakeWpdb($organizerTargetInner);
$organizerTargetDb
    ->addInnoDbTable($organizerTargetDb->postmeta)
    ->addIndex($organizerTargetDb->postmeta, 'post_id', 'post_id');
$organizerTargetInner->seedTable($organizerTargetInner->postmeta, [
    [
        'meta_id' => 21,
        'post_id' => $organizerSourceId,
        'meta_key' => '_EventOrganizerID',
        'meta_value' => (string) $targetOrganizerIds[1],
    ],
    [
        'meta_id' => 22,
        'post_id' => $organizerSourceId,
        'meta_key' => '_EventOrganizerID',
        'meta_value' => 'stale-extra',
    ],
    [
        'meta_id' => 23,
        'post_id' => $organizerSourceId,
        'meta_key' => '_EventOrganizerID',
        'meta_value' => (string) $targetOrganizerIds[0],
    ],
    [
        'meta_id' => 24,
        'post_id' => $organizerSourceId,
        'meta_key' => '_preview_organizers',
        'meta_value' => "runtime\0preview",
    ],
]);
$organizerTargetInner->seedTable($organizerTargetInner->prefix . 'duo_map', [
    [
        'uuid' => TEC_ORGANIZER_UUID,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $targetOrganizerIds[0],
    ],
    [
        'uuid' => TEC_ORGANIZER_TWO_UUID,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => $targetOrganizerIds[1],
    ],
]);

/** @return ?Throwable */
$applyOrganizerRows = static function (
    LockingFakeWpdb $database,
    Policy $policy,
    int $eventId,
    array $desired
): ?Throwable {
    $savedDb = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = $database;
    $materializer = new \Duo\ApplyFieldMaterializer(
        $policy,
        new Tokens('https://target.example', 'https://target.example/uploads')
    );
    $transactionStarted = false;
    $cacheStarted = false;
    $failure = null;
    try {
        \Duo\Db::start_repeatable_read('TEC organizer repeated-row product fixture');
        $transactionStarted = true;
        $materializer->begin_authored_transaction();
        \Duo\CacheInvalidationTransaction::begin();
        $cacheStarted = true;
        $materializer->reconcile_authored_meta($eventId, $desired, "TEC event $eventId");
        \Duo\Db::commit('TEC organizer repeated-row product fixture commit');
        $transactionStarted = false;
        \Duo\CacheInvalidationTransaction::finish();
    } catch (Throwable $caught) {
        $failure = $caught;
        if ($transactionStarted) {
            \Duo\Db::rollback('TEC organizer repeated-row product fixture rollback');
            $transactionStarted = false;
        }
        if ($cacheStarted) {
            try {
                \Duo\CacheInvalidationTransaction::finish();
            } catch (Throwable $cacheFailure) {
                $failure = $cacheFailure;
            }
        }
    } finally {
        $materializer->end_authored_transaction();
        if ($cacheStarted) {
            \Duo\CacheInvalidationTransaction::end();
        }
        $GLOBALS['wpdb'] = $savedDb;
    }
    return $failure;
};
$organizerPhysicalValues = static function (FakeWpdb $database, int $eventId): array {
    return array_values(array_map(
        static fn(array $row): string => (string) $row['meta_value'],
        array_filter(
            $database->rows($database->postmeta),
            static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === $eventId
                && ($row['meta_key'] ?? null) === '_EventOrganizerID'
        )
    ));
};

$GLOBALS['tec_readiness_wp_cache']['post_meta'][$organizerSourceId] = 'stale-organizer-cache';
$organizerApplyFailure = $applyOrganizerRows(
    $organizerTargetDb,
    $policy,
    $organizerSourceId,
    ['_EventOrganizerID' => $capturedOrganizerRows]
);
duo_check_same(null, $organizerApplyFailure, 'TEC repeated organizer rows apply through the shared locked product path');
duo_check_same(
    array_map('strval', $targetOrganizerIds),
    $organizerPhysicalValues($organizerTargetInner, $organizerSourceId),
    'TEC apply replaces stale organizer rows with rebased target IDs in exact canonical order'
);
duo_check_same(
    "runtime\0preview",
    array_values(array_filter(
        $organizerTargetInner->rows($organizerTargetInner->postmeta),
        static fn(array $row): bool => ($row['meta_key'] ?? null) === '_preview_organizers'
    ))[0]['meta_value'] ?? null,
    'TEC repeated organizer replacement preserves byte-exact editor-runtime metadata'
);
duo_check(
    !array_key_exists($organizerSourceId, $GLOBALS['tec_readiness_wp_cache']['post_meta'] ?? []),
    'TEC repeated organizer materialization purges stale same-process post-meta cache bytes'
);

$rowsAfterFirstOrganizerApply = $organizerTargetInner->rows($organizerTargetInner->postmeta);
duo_check_same(
    null,
    $applyOrganizerRows(
        $organizerTargetDb,
        $policy,
        $organizerSourceId,
        ['_EventOrganizerID' => $capturedOrganizerRows]
    ),
    'an exact TEC organizer retry succeeds idempotently'
);
duo_check_same(
    $rowsAfterFirstOrganizerApply,
    $organizerTargetInner->rows($organizerTargetInner->postmeta),
    'an exact TEC organizer retry performs no physical row churn'
);

$GLOBALS['wpdb'] = $organizerTargetDb;
$targetOrganizerCapture = new EntityMetaCapture(
    $policy,
    new Tokens('https://target.example', 'https://target.example/uploads'),
    static function (mixed ...$_unused): void {},
    static function (): void {},
    static function (mixed ...$_unused): void {}
);
$targetOrganizerByKey = $targetOrganizerCapture->postMetaByKey($organizerSourceId);
$targetOrganizerFlat = array_map(
    static fn(array $values): mixed => $values[0] ?? null,
    $targetOrganizerByKey
);
[$recaptureOrganizerRows, $recapturedOrganizerTokens] = $targetOrganizerCapture->classifyValue(
    '_EventOrganizerID',
    $targetOrganizerByKey['_EventOrganizerID'] ?? [],
    $targetOrganizerFlat,
    'TEC target event',
    'post_meta'
);
$GLOBALS['wpdb'] = $savedOrganizerDb;
duo_check_same(true, $recaptureOrganizerRows, 'TEC target recapture retains its repeated organizer set');
duo_check_same(
    $capturedOrganizerRows,
    $recapturedOrganizerTokens,
    'TEC target recapture is canonical-byte coherent across divergent physical organizer IDs'
);

foreach ([
    'empty list' => [],
    'duplicate list' => [$capturedOrganizerRows[0], $capturedOrganizerRows[0]],
] as $label => $invalidOrganizerRows) {
    $beforeInvalidOrganizerRows = $organizerTargetInner->rows($organizerTargetInner->postmeta);
    $invalidOrganizerFailure = $applyOrganizerRows(
        $organizerTargetDb,
        $policy,
        $organizerSourceId,
        ['_EventOrganizerID' => $invalidOrganizerRows]
    );
    duo_check(
        $invalidOrganizerFailure instanceof RuntimeException,
        "a TEC organizer $label refuses through the shared repeated-row product grammar"
    );
    duo_check_same(
        $beforeInvalidOrganizerRows,
        $organizerTargetInner->rows($organizerTargetInner->postmeta),
        "the refused TEC organizer $label preserves exact target physical rows"
    );
}

$beforeOrganizerInsertFailure = $organizerTargetInner->rows($organizerTargetInner->postmeta);
$organizerTargetInner->failNextQuery(
    'injected organizer insert failure',
    'INSERT INTO `wp_postmeta`',
    1
);
$reversedOrganizerTokens = array_reverse($capturedOrganizerRows);
$organizerInsertFailure = $applyOrganizerRows(
    $organizerTargetDb,
    $policy,
    $organizerSourceId,
    ['_EventOrganizerID' => $reversedOrganizerTokens]
);
duo_check(
    $organizerInsertFailure instanceof \Duo\DatabaseMutationException,
    'an injected TEC organizer row insertion failure is loud and bounded'
);
duo_check_same(
    $beforeOrganizerInsertFailure,
    $organizerTargetInner->rows($organizerTargetInner->postmeta),
    'TEC organizer insertion failure rolls every physical row back atomically'
);
duo_check_same(
    null,
    $applyOrganizerRows(
        $organizerTargetDb,
        $policy,
        $organizerSourceId,
        ['_EventOrganizerID' => $reversedOrganizerTokens]
    ),
    'same-process retry converges after the organizer insertion failure'
);
duo_check_same(
    array_map('strval', array_reverse($targetOrganizerIds)),
    $organizerPhysicalValues($organizerTargetInner, $organizerSourceId),
    'the organizer retry materializes a reorder-only change exactly'
);

$organizerSizeReads = 0;
$organizerTargetInner->onQuery(static function (
    string $sql,
    string $method,
    FakeWpdb $database
) use (&$organizerSizeReads, $organizerSourceId): null {
    if ($method !== 'get_results'
        || !str_contains($sql, 'OCTET_LENGTH(meta_key)')
        || !str_contains($sql, "`post_id` = $organizerSourceId")) {
        return null;
    }
    ++$organizerSizeReads;
    if ($organizerSizeReads !== 2) {
        return null;
    }
    $rows = $database->rows($database->postmeta);
    $positions = [];
    foreach ($rows as $position => $row) {
        if ((int) ($row['post_id'] ?? 0) === $organizerSourceId
            && ($row['meta_key'] ?? null) === '_EventOrganizerID') {
            $positions[] = $position;
        }
    }
    if (count($positions) === 2) {
        [$first, $second] = $positions;
        [$rows[$first]['meta_value'], $rows[$second]['meta_value']] = [
            $rows[$second]['meta_value'],
            $rows[$first]['meta_value'],
        ];
        $database->seedTable($database->postmeta, $rows);
    }
    $database->onQuery(null);
    return null;
});
$beforeOrganizerDrift = $organizerTargetInner->rows($organizerTargetInner->postmeta);
$organizerDriftFailure = $applyOrganizerRows(
    $organizerTargetDb,
    $policy,
    $organizerSourceId,
    ['_EventOrganizerID' => $capturedOrganizerRows]
);
duo_check(
    $organizerDriftFailure instanceof RuntimeException
        && str_contains($organizerDriftFailure->getMessage(), 'failed exact locked readback'),
    'same-count same-length organizer drift after replacement refuses from terminal physical readback'
);
duo_check_same(
    $beforeOrganizerDrift,
    $organizerTargetInner->rows($organizerTargetInner->postmeta),
    'terminal organizer drift rolls the complete owner-range mutation back'
);
duo_check_same(
    null,
    $applyOrganizerRows(
        $organizerTargetDb,
        $policy,
        $organizerSourceId,
        ['_EventOrganizerID' => $capturedOrganizerRows]
    ),
    'same-process retry converges after terminal organizer drift stops'
);

duo_check_same(
    null,
    $applyOrganizerRows($organizerTargetDb, $policy, $organizerSourceId, []),
    'omitting TEC organizer metadata deletes every owned physical organizer row'
);
duo_check_same(
    [],
    $organizerPhysicalValues($organizerTargetInner, $organizerSourceId),
    'TEC organizer omission has an exact zero-row postcondition'
);
duo_check_same(
    null,
    $applyOrganizerRows(
        $organizerTargetDb,
        $policy,
        $organizerSourceId,
        ['_EventOrganizerID' => [$capturedOrganizerRows[0]]]
    ),
    'a one-organizer TEC event materializes after the zero-row state'
);
duo_check_same(
    [(string) $targetOrganizerIds[0]],
    $organizerPhysicalValues($organizerTargetInner, $organizerSourceId),
    'the one-organizer product path writes exactly one rebased physical row'
);
$GLOBALS['wpdb'] = $savedOrganizerDb;

$runtimeCapture = new EntityMetaCapture(
    $policy,
    new stdClass(),
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
foreach (['_preview_organizers', '_preview_venues'] as $key) {
    [$storePreview] = $runtimeCapture->classifyValue(
        $key,
        ['a:2:{i:0;i:700000001;i:1;i:800000003;}'],
        [$key => 'fixture'],
        'post preview draft',
        'post_meta'
    );
    duo_check_same(false, $storePreview, "$key is excluded before canonical state can retain local preview ids");
}
foreach (['_VenueURL', '_VenueProvince', '_VenueShowMap', '_VenueShowMapLink', '_OrganizerWebsite'] as $key) {
    duo_check_same('authored', $policy->post_meta_rule($key)['class'] ?? null, "$key closes the free venue/organizer API surface");
}
foreach ([
    '_EventAllDay',
    '_EventHideFromUpcoming',
    '_EventShowMap',
    '_EventShowMapLink',
    '_tribe_featured',
    '_VenueShowMap',
    '_VenueShowMapLink',
] as $key) {
    duo_check_same(
        true,
        $policy->post_meta_rule($key)['lint_ok'] ?? null,
        "$key is an explicitly reviewed boolean rather than a coincidental local post reference"
    );
}
foreach (['_VenueLat', '_VenueLng'] as $key) {
    duo_check_same(null, $policy->post_meta_rule($key), "$key remains outside the free-plugin manifest contract");
}

$options = $policy->option_rule('tribe_events_calendar_options');
duo_check_same('env', $options['class'] ?? null, 'the mixed TEC option remains target-owned as a whole');
duo_check_same('preserve', $options['autoload'] ?? null, 'the mixed TEC option preserves live autoload semantics');
duo_check_same(true, $options['closed_sub_keys'] ?? null, 'the exact main settings sibling registry is closed');
foreach (['eventsSlug', 'tribeEnableViews', 'category-color-enable-frontend', 'tec_seo_out_of_range_behavior'] as $key) {
    duo_check_same('authored', $options['sub_keys'][$key]['class'] ?? null, "$key is one reviewed portable setting sub-key");
}
$expectedMainOptionClasses = [
    'authored' => [
        'category-color-custom-css',
        'category-color-enable-frontend',
        'category-color-legend-show',
        'category-color-legend-superpowers',
        'category-color-reset-button',
        'category-color-show-hidden-categories',
        'dateTimeSeparator',
        'dateWithYearFormat',
        'dateWithoutYearFormat',
        'datepickerFormat',
        'defaultCurrencyCode',
        'defaultCurrencySymbol',
        'disable_metabox_custom_fields',
        'donate-link',
        'embedGoogleMaps',
        'embedGoogleMapsZoom',
        'eventsSlug',
        'monthAndYearFormat',
        'monthEventAmount',
        'multiDayCutoff',
        'postsPerPage',
        'posts_per_page',
        'remove_event_end_time',
        'reverseCurrencyPosition',
        'showComments',
        'showEventsInMainLoop',
        'singleEventSlug',
        'stylesheetOption',
        'stylesheet_mode',
        'tec_seo_disabled_view_404',
        'tec_seo_noindex_dated_list_urls',
        'tec_seo_out_of_range_behavior',
        'timeRangeSeparator',
        'toggle_blocks_editor',
        'tribeDisableTribeBar',
        'tribeEnableViews',
        'tribeEventsAfterHTML',
        'tribeEventsBeforeHTML',
        'tribeEventsTemplate',
        'tribe_events_timezone_mode',
        'tribe_events_timezones_show_zone',
        'viewOption',
    ],
    'derived' => [
        'earliest_date',
        'earliest_date_markers',
        'latest_date',
        'latest_date_markers',
    ],
    'env' => [
        'allow_duplicate_venues',
        'custom-fields',
        'debugEvents',
        'delete-past-events',
        'did_init',
        'eb_security_key',
        'enable_month_view_cache',
        'event-automator-schema-version',
        'eventsDefaultOrganizerID',
        'eventsDefaultVenueID',
        'fb_auto_frequency',
        'fb_auto_import',
        'fb_enable_GoogleMaps',
        'fb_token',
        'fb_token_expires',
        'fb_token_scopes',
        'fb_uids',
        'google_maps_js_api_key',
        'geoloc_default_unit',
        'ian-notifications-opt-in',
        'imported_post_status',
        'latest_ecp_version',
        'logging_class',
        'logging_engine',
        'logging_level',
        'liveFiltersUpdate',
        'meetup_api_key',
        'meetup_security_key',
        'opt-in-status',
        'opt_in_status',
        'previous_ecp_versions',
        'recurrenceMaxMonthsAfter',
        'rest-v1-disabled',
        'schema-version',
        'tec-schema-version',
        'tec-tickets-emails-rsvp-add-event-ics',
        'tec-tickets-emails-rsvp-add-event-links',
        'tec-tickets-emails-ticket-add-event-ics',
        'tec-tickets-emails-ticket-add-event-links',
        'tec_events_rcp_hide_on_views',
        'trash-past-events',
        'tribe_aggregator_default_category',
        'tribe_aggregator_default_import_limit_number',
        'tribe_aggregator_default_import_limit_range',
        'tribe_aggregator_default_import_limit_type',
        'tribe_aggregator_default_post_status',
        'tribe_aggregator_default_show_map',
        'tribe_aggregator_default_update_authority',
        'tribe_aggregator_default_url_import_event',
        'tribe_aggregator_default_url_import_range',
        'tribe_aggregator_disable',
        'tribe_aggregator_import_process_system',
        'tribe_ext_tec_tweaks_remove_event_end_time',
        'tribeEventsCountries',
    ],
    'runtime' => [
        'front_page_event_archive',
        'imported_encoding_status',
        'last-update-message-the-events-calendar',
        'mobile_default_view',
        'skip_welcome',
        'spEventsAfterHTML',
        'spEventsBeforeHTML',
        'spEventsTemplate',
        'tec_admin_page_dismissed',
        'tec_events_onboarding_page_dismissed',
        'tec_onboarding_wizard_visited_guided_setup',
        'tribe_events_enable_timezones',
        'tribe_onboarding_views',
        'tribe_queue_sync',
        'views_v2_enabled',
    ],
];
$nativeAggregatorOrigins = ['csv', 'eventbrite', 'gcal', 'ical', 'ics', 'meetup', 'url'];
foreach ($nativeAggregatorOrigins as $origin) {
    foreach (['category', 'import_event_settings', 'post_status', 'show_map', 'update_authority'] as $suffix) {
        $expectedMainOptionClasses['env'][] = "tribe_aggregator_default_{$origin}_{$suffix}";
    }
}
$actualMainOptionClasses = [];
foreach ((array) ($options['sub_keys'] ?? []) as $key => $rule) {
    $actualMainOptionClasses[(string) ($rule['class'] ?? '')][] = (string) $key;
}
foreach ($expectedMainOptionClasses as $class => &$keys) {
    sort($keys, SORT_STRING);
    sort($actualMainOptionClasses[$class], SORT_STRING);
    duo_check_same(
        $keys,
        $actualMainOptionClasses[$class],
        "the exact 6.17.2/6.17.3 main-option $class inventory is closed and source-auditable"
    );
}
unset($keys);
duo_check_same(
    null,
    $options['sub_keys']['tribe_aggregator_default_webcal_post_status'] ?? null,
    'a filter-added Event Aggregator origin is not silently blessed as one of the seven free-core origins'
);
foreach ([
    'debugEvents',
    'delete-past-events',
    'eb_security_key',
    'enable_month_view_cache',
    'fb_token',
    'google_maps_js_api_key',
    'meetup_api_key',
    'recurrenceMaxMonthsAfter',
    'rest-v1-disabled',
    'schema-version',
    'trash-past-events',
] as $key) {
    duo_check_same(
        'env',
        $options['sub_keys'][$key]['class'] ?? null,
        "$key is explicitly classified and preserved as target operational/integration state"
    );
}
foreach (['earliest_date', 'latest_date', 'earliest_date_markers', 'latest_date_markers'] as $key) {
    duo_check_same('derived', $options['sub_keys'][$key]['class'] ?? null, "$key is exact target-derived date state");
}
foreach (['posts_per_page', 'stylesheetOption'] as $key) {
    duo_check_same('authored', $options['sub_keys'][$key]['class'] ?? null, "$key preserves upgraded alias precedence");
}
foreach (['allow_duplicate_venues', 'custom-fields', 'eventsDefaultOrganizerID', 'eventsDefaultVenueID', 'geoloc_default_unit', 'liveFiltersUpdate', 'tribeEventsCountries'] as $key) {
    duo_check_same(
        'env',
        $options['sub_keys'][$key]['class'] ?? null,
        "$key is a target-owned extension or legacy input with no exact free-plugin authoring path"
    );
    duo_check_same(null, $options['sub_keys'][$key]['ref'] ?? null, "$key does not claim a free-plugin reference grammar");
    duo_check_same(null, $options['sub_keys'][$key]['plain_data'] ?? null, "$key does not claim an unbounded portable data grammar");
}
duo_check_same(
    'runtime',
    $options['sub_keys']['front_page_event_archive']['class'] ?? null,
    'the TEC homepage flag remains the target-owned runtime half of the core page_on_front pair'
);

$expectedTopLevelOptionClasses = [
    'derived' => ['tec_events_category_color_css'],
    'env' => [
        'external_updates-event-aggregator',
        'pue_install_key_event_aggregator',
        'stellar_schema_version_stellarwp-shepherd-tec-tasks',
        'stellar_schema_version_tec-kv-cache',
        'stellarwp_telemetry_user_info',
        'tec_automator_power_automate_secret_key',
        'tec_automator_zapier_secret_key',
        'tec_ct1_events_table_schema_version',
        'tec_ct1_migration_state',
        'tec_ct1_occurrences_table_schema_version',
        'tec_custom_tables_v1_active',
        'tec_freemius_accounts_archive',
        'tec_freemius_accounts_data_archive',
        'tec_freemius_plugins_archive',
        'tec_power_automate_connections',
        'tec_timed_tec_custom_tables_v1_initialized',
        'tec_timed_tribe_supports_async_process',
        'tec_zapier_api_keys',
        'tribe_customizer',
        'tribe_events_calendar_options',
        'tribe_promoter_auth_key',
        'tribe_systeminfo_optin',
        'wpml_tec_did_set_defaults',
    ],
    'runtime' => [
        'sp_events_calendar_options',
        'stellarwp_telemetry',
        'stellarwp_telemetry_last_send',
        'stellarwp_telemetry_the-events-calendar_show_optin',
        'teccc_options',
        'tec_category_colors_migration_data',
        'tec_category_colors_migration_processing',
        'tec_events_category_colors_migration_batch',
        'tec_events_category_colors_migration_status',
        'tec_onboarding_wizard_data',
        'tec_timed_events_hide_from_upcoming_ids',
        'tec_timed_events_is_rest_api_blocked',
        'tec_timed_events_timezone_update_needed',
        'tribe-aggregator-legacy-ical-migrated',
        'tribe-events-importexport-ical-importer-saved-imports',
        'tribe_events_import_column_mapping',
        'tribe_events_import_column_mapping_events',
        'tribe_events_import_column_mapping_organizers',
        'tribe_events_import_column_mapping_venues',
        'tribe_events_import_encoded_rows',
        'tribe_events_import_failed_rows',
        'tribe_events_import_log',
        'tribe_events_import_type',
        'tribe_events_importer_offset',
        'tribe_events_pro_customizer',
        'tribe_last_generate_rewrite_rules',
        'tribe_last_save_post',
        'tribe_last_updated_option',
        'tribe_pue_key_notices',
        'tribe_settings_errors',
        'tribe_settings_major_error',
        'tribe_settings_sent_data',
        'tribe_skip_welcome',
    ],
];
$manifest = json_decode((string) file_get_contents($root . '/manifests/the-events-calendar.json'), true, 512, JSON_THROW_ON_ERROR);
$actualTopLevelOptionClasses = [];
foreach ((array) ($manifest['options'] ?? []) as $key => $rule) {
    $actualTopLevelOptionClasses[(string) ($rule['class'] ?? '')][] = (string) $key;
}
foreach ($expectedTopLevelOptionClasses as $class => $keys) {
    sort($keys, SORT_STRING);
    sort($actualTopLevelOptionClasses[$class], SORT_STRING);
    duo_check_same(
        $keys,
        $actualTopLevelOptionClasses[$class],
        "the exact free/Common top-level $class option inventory is explicit without claiming shared extension state"
    );
}
duo_check_same(
    [
        '^_tec_power_automate_endpoint_details_',
        '^_tec_zapier_endpoint_details_',
        '^tec_power_automate_connection_',
        '^tec_zapier_api_key_',
        '^tribe_events_import_column_mapping(?:_|$)',
    ],
    array_column((array) ($manifest['option_namespaces'] ?? []), 'match'),
    'only exact computed option families receive namespace ownership and hostile suffixes remain visible'
);
duo_check_same(
    [
        '^_tec_power_automate_endpoint_details_(?:attendees|canceled_events|checkin|create_events|new_events|orders|refunded_orders|updated_attendees|updated_events)$',
        '^_tec_zapier_endpoint_details_(?:attendees|authorize|canceled_events|checkin|create_events|find_attendees|find_events|find_tickets|new_events|orders|refunded_orders|update_events|updated_attendees|updated_events)$',
        '^tec_power_automate_connection_[a-f0-9]{64}$',
        '^tec_zapier_api_key_[a-f0-9]{64}$',
    ],
    array_column((array) ($manifest['option_patterns'] ?? []), 'match'),
    'computed Event Automator rows are bounded to the exact 6.17.x native IDs and hash grammar'
);
foreach ([
    'tribe_events_import_column_mapping' => 'runtime',
    'tribe_events_import_column_mapping_events' => 'runtime',
    '_tec_power_automate_endpoint_details_updated_events' => 'runtime',
    '_tec_zapier_endpoint_details_find_tickets' => 'runtime',
    'tec_power_automate_connection_' . str_repeat('a', 64) => 'env',
    'tec_zapier_api_key_' . str_repeat('f', 64) => 'env',
] as $name => $class) {
    duo_check_same(
        $class,
        $interpreter->option_rule($name, [])['class'] ?? null,
        "$name matches one exact source-derived computed option classification"
    );
}
foreach ([
    'tribe_events_import_column_mapping_event-tickets',
    '_tec_power_automate_endpoint_details_find_tickets',
    '_tec_zapier_endpoint_details_find_events_extension',
    'tec_power_automate_connection_' . str_repeat('a', 63),
    'tec_zapier_api_key_' . str_repeat('A', 64),
    'tec_zapier_api_key_' . str_repeat('x', 4096) . "\0AKIAABCDEFGHIJKLMNOP",
    "_tec_zapier_endpoint_details_\xff\xfecredential-secret",
] as $hostileName) {
    $computedRefusal = '';
    try {
        $interpreter->option_rule($hostileName, []);
    } catch (RuntimeException $e) {
        $computedRefusal = $e->getMessage();
    }
    duo_check(
        str_contains($computedRefusal, 'computed-name registry is closed')
            && str_contains($computedRefusal, 'string:' . strlen($hostileName) . ':')
            && strlen($computedRefusal) < 300
            && preg_match('//u', $computedRefusal) === 1
            && !str_contains($computedRefusal, 'AKIA')
            && !str_contains($computedRefusal, 'credential-secret'),
        'unknown computed option names refuse with one bounded UTF-8-safe fingerprint and no authored/secret bytes'
    );
}
duo_check_same(
    'derived',
    $policy->option_rule('tec_events_category_color_css')['class'] ?? null,
    'native Category Colors CSS is regenerated rather than captured as authored state'
);
$rewriteActions = $policy->actions_for(['option:tribe_events_calendar_options']);
duo_check_same(2, count($rewriteActions), 'changing portable TEC settings selects rewrite and dropdown-cache repairs');
$nativeRewriteActions = array_values(array_filter(
    $rewriteActions,
    static fn(array $action): bool => ($action['kind'] ?? null) === 'native'
));
$optionColorActions = array_values(array_filter(
    $rewriteActions,
    static fn(array $action): bool => ($action['kind'] ?? null) === 'provider'
));
duo_check_same(1, count($nativeRewriteActions), 'portable TEC settings select exactly one native rewrite action');
duo_check_same('rewrite.flush', $nativeRewriteActions[0]['action'] ?? null, 'TEC uses the closed engine-owned soft rewrite flush');
duo_check_same(
    [
        'tec-rewrite-rules',
        'tec-last-generate-rewrite-rules',
        'tec-last-updated-option',
        'tec-last-save-post',
        'tec-rewrite-rules-cache',
        'tec-last-generate-rewrite-rules-cache',
        'tec-last-updated-option-cache',
        'tec-last-save-post-cache',
        'tec-rewrite-listener-runtime',
        'tec-permalink-pre-option-filter',
        'tec-permalink-pre-option-generic-filter',
        'tec-permalink-option-filter',
        'tec-permalink-default-option-filter',
        'tec-rewrite-rules-array-filter',
        'tec-rewrite-generate-hook',
        'tec-rewrite-generation-runtime',
        'tec-rewrite-pre-option-filter',
        'tec-rewrite-preload-filter',
        'tec-rewrite-cache-preload-filter',
        'tec-rewrite-alloptions-filter',
        'tec-rewrite-default-option-filter',
        'tec-rewrite-sanitize-filter',
        'tec-rewrite-option-filter',
        'tec-rewrite-pre-update-filter',
        'tec-rewrite-pre-update-generic-filter',
        'tec-rewrite-update-option-hook',
        'tec-rewrite-autoload-values-filter',
        'tec-rewrite-default-autoload-filter',
        'tec-rewrite-autoload-size-filter',
        'tec-rewrite-update-specific-hook',
        'tec-rewrite-updated-option-hook',
        'tec-rewrite-add-option-hook',
        'tec-rewrite-add-specific-hook',
        'tec-rewrite-added-option-hook',
    ],
    array_column($nativeRewriteActions[0]['effects'] ?? [], 'id'),
    'TEC checkpoints every rewrite and CacheListener option/cache effect before the fresh child'
);
duo_check_same(1, count($optionColorActions), 'portable TEC settings select exactly one Category Colors cache repair');
duo_check_same(
    'the-events-calendar-category-colors',
    $optionColorActions[0]['provider'] ?? null,
    'the portable show-hidden setting cannot leave the native dropdown cache stale'
);
duo_check_same([], $policy->actions_for(['post:tribe_events']), 'event-only writes do not trigger an unrelated global rewrite flush');
$colorActions = $policy->actions_for(['term:tribe_events_cat']);
duo_check_same(1, count($colorActions), 'an event-category write selects one bounded native CSS repair');
duo_check_same('provider', $colorActions[0]['kind'] ?? null, 'Category Colors repair uses a structured provider action');
duo_check_same(
    'the-events-calendar-category-colors',
    $colorActions[0]['provider'] ?? null,
    'the Category Colors action binds the digest-owned provider identity'
);
duo_check_same('regenerate_css', $colorActions[0]['capability'] ?? null, 'the action selects only native CSS regeneration');
duo_check_same(
    [
        ['id' => 'tec-category-colors-css', 'kind' => 'database', 'mode' => 'restorable', 'selector' => [
            'scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'tec_events_category_color_css',
        ]],
        ['id' => 'tec-category-colors-dropdown-cache', 'kind' => 'cache', 'mode' => 'irreversible', 'selector' => [
            'scope' => 'external', 'type' => 'provider_resource',
            'value' => 'the-events-calendar-category-colors:v1:dropdown-cache',
        ]],
    ],
    $colorActions[0]['effects'] ?? null,
    'the generated option is rollback-restorable while TEC owns its external dropdown cache'
);

$providerDeclarations = $policy->provider_declarations();
$colorDeclaration = $providerDeclarations['the-events-calendar-category-colors'] ?? null;
duo_check_same(
    [
        'functions' => [
            'get_option',
            'get_term_meta',
            'get_terms',
            'has_filter',
            'is_wp_error',
            'sanitize_html_class',
            'sanitize_title',
            'tribe',
            'tribe_cache',
            'tribe_get_option',
            'wp_using_ext_object_cache',
        ],
        'classes' => [
            'TEC\\Events\\Category_Colors\\CSS\\Controller',
            'TEC\\Events\\Category_Colors\\CSS\\Generator',
            'TEC\\Events\\Category_Colors\\Repositories\\Category_Color_Dropdown_Provider',
            'Tribe__Cache',
            'Tribe__Utils__Color',
        ],
    ],
    $colorDeclaration['requires'] ?? null,
    'provider negotiation refuses before mutation when the exact 6.17.x native CSS path disappears'
);
$colorProviderSource = (string) file_get_contents(
    $root . '/manifests/providers/the-events-calendar-category-colors.php'
);
$nativeGenerateOffset = strpos($colorProviderSource, '$generator->generate_and_save_css();');
$nativeBustOffset = strpos($colorProviderSource, '$dropdown->bust_dropdown_categories_cache();');
duo_check(
    is_int($nativeGenerateOffset)
        && is_int($nativeBustOffset)
        && $nativeGenerateOffset < $nativeBustOffset
        && !str_contains($colorProviderSource, '$controller->generate_css();'),
    'the shipped provider honestly declares the direct exact Generator-then-dropdown contract instead of claiming controller dispatch'
);

$widgetDb = FakeWpdb::install();
$widgetMapTable = $widgetDb->prefix . 'duo_map';
$sourceWidgetRows = [
    [
        'uuid' => TEC_LIST_WIDGET_UUID,
        'entity_type' => 'widget',
        'id_kind' => 'widget_tribe-widget-events-list',
        'local_id' => 7000000001,
    ],
    [
        'uuid' => TEC_QR_WIDGET_UUID,
        'entity_type' => 'widget',
        'id_kind' => 'widget_tribe-widget-events-qr-code',
        'local_id' => 7000000002,
    ],
    [
        'uuid' => TEC_EVENT_UUID,
        'entity_type' => 'post',
        'id_kind' => 'post',
        'local_id' => 7100000001,
    ],
];
$widgetDb->seedTable($widgetMapTable, $sourceWidgetRows);
$GLOBALS['tec_readiness_widget_salt'] = 'source-widget-salt';
$sourceWidgetTokens = new Tokens('https://source.example', 'https://source.example/uploads');
$sourceWidgetTokens->policy = $policy;

$sourceStoredList = tec_readiness_legacy_widget_block([
    'id' => 'tribe-widget-events-list-7000000001',
]);
$sourceStoredQr = tec_readiness_legacy_widget_block([
    'id' => 'tribe-widget-events-qr-code-7000000002',
]);
$sourceEmbeddedListSettings = [
    'title' => 'Calendar https://source.example/events and https://source.example/uploads/banner.png',
    'limit' => '10',
    'no_upcoming_events' => false,
    'featured_events_only' => true,
    'jsonld_enable' => true,
    'tribe_is_list_widget' => true,
];
$sourceEmbeddedQrSettings = [
    'widget_title' => 'Event QR',
    'qr_code_size' => '28',
    'redirection' => 'specific',
    'event_id' => 7100000001,
    'series_id' => 0,
];
$sourceEmbeddedList = tec_readiness_legacy_widget_block([
    'idBase' => 'tribe-widget-events-list',
    'instance' => tec_readiness_embedded_widget($sourceEmbeddedListSettings),
]);
$sourceEmbeddedQr = tec_readiness_legacy_widget_block([
    'idBase' => 'tribe-widget-events-qr-code',
    'instance' => tec_readiness_embedded_widget($sourceEmbeddedQrSettings),
]);
$canonicalStoredList = Blocks::capture_rewrite(
    $sourceStoredList,
    $policy,
    $sourceWidgetTokens,
    false,
    'page with a TEC stored list widget'
);
$canonicalStoredQr = Blocks::capture_rewrite(
    $sourceStoredQr,
    $policy,
    $sourceWidgetTokens,
    false,
    'page with a TEC stored QR widget'
);
$canonicalEmbeddedList = Blocks::capture_rewrite(
    $sourceEmbeddedList,
    $policy,
    $sourceWidgetTokens,
    false,
    'page with an embedded TEC list widget'
);
$canonicalEmbeddedQr = Blocks::capture_rewrite(
    $sourceEmbeddedQr,
    $policy,
    $sourceWidgetTokens,
    false,
    'page with an embedded TEC QR widget'
);
$storedListAttrs = parse_blocks($canonicalStoredList)[0]['attrs'] ?? [];
$storedQrAttrs = parse_blocks($canonicalStoredQr)[0]['attrs'] ?? [];
$embeddedListAttrs = parse_blocks($canonicalEmbeddedList)[0]['attrs'] ?? [];
$embeddedQrAttrs = parse_blocks($canonicalEmbeddedQr)[0]['attrs'] ?? [];
duo_check_same(
    [
        'id' => '{{widget:' . TEC_LIST_WIDGET_UUID . '}}',
        'idBase' => 'tribe-widget-events-list',
    ],
    $storedListAttrs,
    'stored TEC list blocks capture through SidebarState identity instead of retaining a local widget counter'
);
duo_check_same(
    [
        'id' => '{{widget:' . TEC_QR_WIDGET_UUID . '}}',
        'idBase' => 'tribe-widget-events-qr-code',
    ],
    $storedQrAttrs,
    'stored TEC QR blocks bind their longer exact widget id_kind without truncation'
);
duo_check_same(
    'the-events-calendar/v1',
    $embeddedListAttrs['instance']['duo'] ?? null,
    'embedded TEC widget capture replaces raw encoded bytes with the manifest-bound codec marker'
);
duo_check_same(
    [
        'title' => 'Calendar {{home}}/events and {{uploads}}/banner.png',
        'limit' => '10',
        'no_upcoming_events' => false,
        'featured_events_only' => true,
        'jsonld_enable' => true,
        'tribe_is_list_widget' => true,
    ],
    $embeddedListAttrs['instance']['settings'] ?? null,
    'embedded list settings use closed native types and portable URL tokens without retaining source payload bytes'
);
duo_check_same(
    '{{post:' . TEC_EVENT_UUID . '}}',
    $embeddedQrAttrs['instance']['settings']['event_id'] ?? null,
    'embedded QR event selection becomes one portable post token'
);
duo_check(
    array_key_exists('series_id', $embeddedQrAttrs['instance']['settings'] ?? [])
        && $embeddedQrAttrs['instance']['settings']['series_id'] === null,
    'the free QR widget represents its licensed series surface only as exact absence'
);
duo_check(
    !str_contains($canonicalEmbeddedList, base64_encode(serialize($sourceEmbeddedListSettings)))
        && !str_contains($canonicalEmbeddedList, wp_hash(serialize($sourceEmbeddedListSettings))),
    'canonical embedded widgets retain neither source serialized payload nor source salt receipt'
);

$targetWidgetRows = $sourceWidgetRows;
$targetWidgetRows[0]['local_id'] = 8000000001;
$targetWidgetRows[1]['local_id'] = 8000000002;
$targetWidgetRows[2]['local_id'] = 8100000001;
$widgetDb->seedTable($widgetMapTable, $targetWidgetRows);
$GLOBALS['tec_readiness_widget_salt'] = 'target-widget-salt';
$targetWidgetTokens = new Tokens('https://target.example', 'https://target.example/media');
$targetStoredList = Blocks::apply_rewrite($canonicalStoredList, $policy, $targetWidgetTokens);
$targetStoredQr = Blocks::apply_rewrite($canonicalStoredQr, $policy, $targetWidgetTokens);
$targetEmbeddedList = Blocks::apply_rewrite($canonicalEmbeddedList, $policy, $targetWidgetTokens);
$targetEmbeddedQr = Blocks::apply_rewrite($canonicalEmbeddedQr, $policy, $targetWidgetTokens);
duo_check_same(
    ['id' => 'tribe-widget-events-list-8000000001'],
    parse_blocks($targetStoredList)[0]['attrs'] ?? null,
    'stored list apply resolves the exact target-local widget counter and emits no authored codec metadata'
);
duo_check_same(
    ['id' => 'tribe-widget-events-qr-code-8000000002'],
    parse_blocks($targetStoredQr)[0]['attrs'] ?? null,
    'stored QR apply resolves a divergent large target-local widget counter'
);
$targetListPhysical = parse_blocks($targetEmbeddedList)[0]['attrs']['instance'] ?? [];
$targetQrPhysical = parse_blocks($targetEmbeddedQr)[0]['attrs']['instance'] ?? [];
$targetListSerialized = base64_decode((string) ($targetListPhysical['encoded'] ?? ''), true);
$targetQrSerialized = base64_decode((string) ($targetQrPhysical['encoded'] ?? ''), true);
duo_check(
    is_string($targetListSerialized)
        && hash_equals(wp_hash($targetListSerialized), (string) ($targetListPhysical['hash'] ?? '')),
    'embedded list apply emits one target-salted WordPress receipt over exact generated bytes'
);
duo_check_same(
    [
        'title' => 'Calendar https://target.example/events and https://target.example/media/banner.png',
        'limit' => '10',
        'no_upcoming_events' => false,
        'featured_events_only' => true,
        'jsonld_enable' => true,
        'tribe_is_list_widget' => true,
    ],
    is_string($targetListSerialized) ? unserialize($targetListSerialized, ['allowed_classes' => false]) : null,
    'embedded list apply reconstructs only the reviewed native settings in fixed order for the target'
);
duo_check(
    is_string($targetQrSerialized)
        && hash_equals(wp_hash($targetQrSerialized), (string) ($targetQrPhysical['hash'] ?? '')),
    'embedded QR apply discards the source receipt and re-signs against the target salt'
);
duo_check_same(
    [
        'widget_title' => 'Event QR',
        'qr_code_size' => '28',
        'redirection' => 'specific',
        'event_id' => 8100000001,
        'series_id' => 0,
    ],
    is_string($targetQrSerialized) ? unserialize($targetQrSerialized, ['allowed_classes' => false]) : null,
    'embedded QR apply resolves its event to a divergent large target post ID and preserves native scalar wires'
);
$targetWidgetTokens->policy = $policy;
duo_check_same(
    $canonicalStoredList,
    Blocks::capture_rewrite($targetStoredList, $policy, $targetWidgetTokens),
    'stored widget capture-apply-recapture is a byte-exact canonical fixed point'
);
duo_check_same(
    $canonicalEmbeddedList,
    Blocks::capture_rewrite($targetEmbeddedList, $policy, $targetWidgetTokens),
    'embedded list capture-apply-recapture is a byte-exact canonical fixed point across salts and URLs'
);
duo_check_same(
    $canonicalEmbeddedQr,
    Blocks::capture_rewrite($targetEmbeddedQr, $policy, $targetWidgetTokens),
    'embedded QR capture-apply-recapture is a byte-exact canonical fixed point across divergent event IDs'
);
$widgetDb->seedTable($widgetDb->options, [
    [
        'option_id' => 1,
        'option_name' => 'sidebars_widgets',
        'option_value' => serialize([
            'primary' => [
                'tribe-widget-events-list-8000000001',
                'tribe-widget-events-qr-code-8000000002',
            ],
            'array_version' => 3,
        ]),
        'autoload' => 'yes',
    ],
    [
        'option_id' => 2,
        'option_name' => 'widget_tribe-widget-events-list',
        'option_value' => serialize([
            8000000001 => [
                'title' => 'Calendar https://target.example/events',
                'limit' => '10',
                'no_upcoming_events' => false,
                'featured_events_only' => true,
                'jsonld_enable' => true,
                'tribe_is_list_widget' => true,
            ],
            '_multiwidget' => 1,
        ]),
        'autoload' => 'yes',
    ],
    [
        'option_id' => 3,
        'option_name' => 'widget_tribe-widget-events-qr-code',
        'option_value' => serialize([
            8000000002 => [
                'widget_title' => 'Event QR',
                'qr_code_size' => '28',
                'redirection' => 'specific',
                'event_id' => 8100000001,
                'series_id' => 0,
            ],
            '_multiwidget' => 1,
        ]),
        'autoload' => 'yes',
    ],
]);
$capturedTecSidebars = \Duo\SidebarState::capture($policy, $targetWidgetTokens, false);
$capturedTecWidgets = $capturedTecSidebars['entities'][0]['content'] ?? '';
$capturedTecSidebarData = json_decode((string) $capturedTecWidgets, true, 32, JSON_THROW_ON_ERROR);
duo_check_same(
    [TEC_LIST_WIDGET_UUID, TEC_QR_WIDGET_UUID],
    array_column($capturedTecSidebarData['widgets'] ?? [], 'uuid'),
    'SidebarState captures both exact TEC physical widget kinds with durable ledger identities'
);
duo_check_same(
    '{{post:' . TEC_EVENT_UUID . '}}',
    $capturedTecSidebarData['widgets'][1]['settings']['event_id'] ?? null,
    'SidebarState captures the QR event selection through the declared post-reference grammar'
);
duo_check_same(
    'Calendar {{home}}/events',
    $capturedTecSidebarData['widgets'][0]['settings']['title'] ?? null,
    'SidebarState tokenizes TEC widget authored text independently of the embedded block codec'
);
duo_check_same(
    '<!-- wp:legacy-widget /-->',
    Blocks::capture_rewrite('<!-- wp:legacy-widget /-->', $policy, $targetWidgetTokens),
    'the registered completely empty legacy-widget placeholder remains a byte-exact no-op'
);
duo_check_same(
    ['idBase' => 'tribe-widget-events-list'],
    parse_blocks(Blocks::capture_rewrite(
        tec_readiness_legacy_widget_block(['idBase' => 'tribe-widget-events-list']),
        $policy,
        $targetWidgetTokens
    ))[0]['attrs'] ?? null,
    'an embedded widget with no instance uses the exact registered idBase-only form'
);
duo_check_same(
    ['idBase' => 'tribe-widget-events-list'],
    parse_blocks(Blocks::capture_rewrite(
        tec_readiness_legacy_widget_block(['idBase' => 'tribe-widget-events-list', 'instance' => null]),
        $policy,
        $targetWidgetTokens
    ))[0]['attrs'] ?? null,
    'an explicit registered null instance canonicalizes to the same idBase-only form'
);
duo_check_same(
    [],
    parse_blocks(Blocks::capture_rewrite(
        tec_readiness_legacy_widget_block([
            'idBase' => 'tribe-widget-events-list',
            'instance' => tec_readiness_embedded_widget([]),
        ]),
        $policy,
        $targetWidgetTokens
    ))[0]['attrs']['instance']['settings'] ?? null,
    'a native empty serialized settings object remains supported without inferred defaults'
);

$capturePhysicalWidget = static function (array $attrs) use ($policy, $targetWidgetTokens): string {
    return Blocks::capture_rewrite(
        tec_readiness_legacy_widget_block($attrs),
        $policy,
        $targetWidgetTokens,
        false,
        'hostile TEC legacy widget fixture'
    );
};
$rawEmbeddedWidget = static function (string $idBase, string $serialized, ?string $hash = null): array {
    return [
        'idBase' => $idBase,
        'instance' => [
            'encoded' => base64_encode($serialized),
            'hash' => $hash ?? wp_hash($serialized),
        ],
    ];
};
foreach ([
    [['id' => 'text-1'], 'supported idBase', 'a non-TEC stored legacy widget remains outside the TEC codec'],
    [['idBase' => 'text'], 'free-plugin widget type', 'a non-TEC embedded legacy widget remains outside the TEC codec'],
    [['id' => 'tribe-widget-events-list-0'], 'canonical positive instance', 'stored widget zero is not normalized into an identity'],
    [['id' => 'tribe-widget-events-list-01'], 'canonical positive instance', 'stored widget leading-zero counters refuse'],
    [['id' => 'tribe-widget-events-list-999999999999999999999999'], 'canonical positive instance', 'stored widget overflow refuses before an integer cast'],
    [[
        'id' => 'tribe-widget-events-list-8000000001',
        'idBase' => 'tribe-widget-events-list',
    ], 'unknown or missing field', 'mixed stored and embedded identity forms refuse'],
    [['idBase' => 'tribe-widget-events-list', 'instance' => []], 'closed attribute object', 'an embedded instance cannot omit encoded/hash receipts'],
    [['idBase' => 'tribe-widget-events-list', 'instance' => ['encoded' => '***', 'hash' => str_repeat('0', 32)]], 'canonical base64', 'invalid base64 refuses before native decode'],
] as [$attrs, $needle, $message]) {
    duo_check_throws(
        static fn(): string => $capturePhysicalWidget($attrs),
        RuntimeException::class,
        $message,
        $needle
    );
}

$validListSerialized = serialize(['title' => 'Safe', 'limit' => 5]);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        $validListSerialized,
        str_repeat('0', 32)
    )),
    RuntimeException::class,
    'a stale or foreign source salt refuses before decoded settings can be trusted',
    'source hash is missing or invalid'
);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        $validListSerialized,
        strtoupper(wp_hash($validListSerialized))
    )),
    RuntimeException::class,
    'uppercase hash aliases refuse instead of weakening the exact receipt grammar',
    'source hash is missing or invalid'
);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        $validListSerialized . 'trailing'
    )),
    RuntimeException::class,
    'a valid serialized prefix with trailing payload refuses',
    'trailing or noncanonical'
);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        serialize('scalar')
    )),
    RuntimeException::class,
    'a serialized scalar cannot masquerade as widget settings',
    'decode to one plain settings object'
);
$GLOBALS['tec_readiness_widget_wakeups'] = 0;
$objectSerialized = serialize(['title' => new TecReadinessWidgetWakeupProbe()]);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        $objectSerialized
    )),
    RuntimeException::class,
    'nested objects refuse under both TEC artifact contracts',
    'PHP object'
);
duo_check_same(
    0,
    $GLOBALS['tec_readiness_widget_wakeups'],
    'the codec disables classes before inspecting a 6.17.2-compatible hostile object payload'
);
$referencedTitle = 'shared';
$referencedSettings = ['title' => &$referencedTitle, 'limit' => &$referencedTitle];
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        serialize($referencedSettings)
    )),
    RuntimeException::class,
    'PHP reference aliases refuse before settings normalization',
    'PHP reference'
);
$recursiveSettings = [];
$recursiveSettings['title'] = &$recursiveSettings;
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        serialize($recursiveSettings)
    )),
    RuntimeException::class,
    'recursive serialized settings refuse without recursive traversal',
    'PHP reference or recursive array'
);
$deepWidgetValue = 'leaf';
for ($widgetDepth = 0; $widgetDepth < 8; ++$widgetDepth) {
    $deepWidgetValue = ['nested' => $deepWidgetValue];
}
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        serialize(['title' => $deepWidgetValue])
    )),
    RuntimeException::class,
    'deep plain arrays refuse at the codec frontier even without an object',
    'depth or node budget'
);
$oversizedWidgetSerialized = serialize(['title' => str_repeat('x', 17000)]);
duo_check_throws(
    static fn(): string => $capturePhysicalWidget($rawEmbeddedWidget(
        'tribe-widget-events-list',
        $oversizedWidgetSerialized
    )),
    RuntimeException::class,
    'oversized encoded widget bytes refuse before unserialize',
    'payload is not bounded canonical base64'
);

foreach ([
    [['title' => '<b>markup</b>', 'limit' => 5], 'plain-text value', 'list titles must already match native stripping'],
    [['title' => 'a:1:{s:1:"x";s:1:"y";}', 'limit' => 5], 'plain-text value', 'serialized-looking nested text refuses'],
    [['title' => 'x', 'limit' => 0], 'integer range 1..10', 'list limit below the native UI frontier refuses'],
    [['title' => 'x', 'limit' => 11], 'integer range 1..10', 'list limit above the native UI frontier refuses'],
    [['title' => 'x', 'limit' => '05'], 'integer range 1..10', 'list limit aliases refuse'],
    [['title' => 'x', 'limit' => 5, 'jsonld_enable' => 1], 'native boolean', 'list boolean aliases refuse'],
    [['title' => 'x', 'limit' => 5, 'filter_added' => true], 'undeclared setting', 'filter-added list settings remain a loud extension boundary'],
] as [$settings, $needle, $message]) {
    duo_check_throws(
        static fn(): string => $capturePhysicalWidget([
            'idBase' => 'tribe-widget-events-list',
            'instance' => tec_readiness_embedded_widget($settings),
        ]),
        RuntimeException::class,
        $message,
        $needle
    );
}
foreach ([
    [['widget_title' => 'x', 'qr_code_size' => '5'], 'exact native menu', 'QR size outside the exact menu refuses'],
    [['widget_title' => 'x', 'redirection' => 'filter-added'], 'exact native menu', 'filter-added QR redirection choices remain outside contract'],
    [['widget_title' => 'x', 'redirection' => 'specific', 'event_id' => 0], 'requires one managed event', 'specific QR redirection cannot silently fall back without an event'],
    [['widget_title' => 'x', 'redirection' => 'current', 'series_id' => 44], 'licensed recurrence surface', 'positive QR series IDs refuse as licensed state'],
    [['widget_title' => 'x', 'redirection' => 'current', 'event_id' => '071'], 'exact positive id', 'QR event ID aliases refuse before tokenization'],
    [['widget_title' => 'x', 'redirection' => 'current', 'event_id' => 9999999999], 'not managed', 'QR event references outside the repository refuse'],
    [['widget_title' => 'x', 'redirection' => 'current', 'foreign' => 'value'], 'undeclared setting', 'filter-added QR settings remain a loud extension boundary'],
] as [$settings, $needle, $message]) {
    duo_check_throws(
        static fn(): string => $capturePhysicalWidget([
            'idBase' => 'tribe-widget-events-qr-code',
            'instance' => tec_readiness_embedded_widget($settings),
        ]),
        RuntimeException::class,
        $message,
        $needle
    );
}
foreach ([
    'https://user:credential@source.example/private',
    'javascript:alert(1)',
    'data:text/html;base64,PHNjcmlwdD4=',
    'ghp_abcdefghijklmnopqrstuvwxyz123456',
] as $hostileWidgetTitle) {
    try {
        $capturePhysicalWidget([
            'idBase' => 'tribe-widget-events-list',
            'instance' => tec_readiness_embedded_widget(['title' => $hostileWidgetTitle, 'limit' => 5]),
        ]);
        $hostileWidgetRefusal = '';
    } catch (Throwable $failure) {
        $hostileWidgetRefusal = $failure->getMessage();
    }
    duo_check(
        $hostileWidgetRefusal !== ''
            && strlen($hostileWidgetRefusal) < 220
            && !str_contains($hostileWidgetRefusal, $hostileWidgetTitle)
            && !str_contains($hostileWidgetRefusal, 'ghp_'),
        'URL, credential, scheme, and secret refusals are bounded and never echo authored widget payloads'
    );
}

$malformedCanonicalWidget = tec_readiness_legacy_widget_block([
    'idBase' => 'tribe-widget-events-list',
    'instance' => tec_readiness_embedded_widget(['title' => 'raw physical bytes', 'limit' => 5]),
]);
duo_check_throws(
    static fn(): string => Blocks::apply_rewrite($malformedCanonicalWidget, $policy, $targetWidgetTokens),
    RuntimeException::class,
    'apply refuses hand-authored physical encoded/hash bytes in canonical state',
    'unknown or missing field'
);
$wrongCodecWidget = tec_readiness_legacy_widget_block([
    'idBase' => 'tribe-widget-events-list',
    'instance' => ['duo' => 'foreign/v1', 'settings' => []],
]);
duo_check_throws(
    static fn(): string => Blocks::apply_rewrite($wrongCodecWidget, $policy, $targetWidgetTokens),
    RuntimeException::class,
    'apply refuses a foreign canonical widget codec marker',
    'codec marker'
);
$unboundWidgetRows = array_values(array_filter(
    $targetWidgetRows,
    static fn(array $row): bool => $row['uuid'] !== TEC_LIST_WIDGET_UUID
));
$widgetDb->seedTable($widgetMapTable, $unboundWidgetRows);
duo_check_throws(
    static fn(): string => Blocks::apply_rewrite($canonicalStoredList, $policy, $targetWidgetTokens),
    RuntimeException::class,
    'stored widget apply refuses when the target SidebarState identity disappeared',
    'not bound on the target'
);
$widgetDb->seedTable($widgetMapTable, $targetWidgetRows);
$canonicalWidgetBody = implode("\n", [
    $canonicalStoredList,
    $canonicalStoredQr,
    $canonicalEmbeddedList,
    $canonicalEmbeddedQr,
]);
$widgetRepositoryTree = tec_readiness_tree(eventBody: $canonicalWidgetBody);
$widgetRepositoryTree[] = [
    'type' => 'sidebar',
    'path' => 'sidebars/primary.json',
    'data' => [
        'widgets' => [
            [
                'uuid' => TEC_LIST_WIDGET_UUID,
                'type' => 'tribe-widget-events-list',
                'settings' => [
                    'title' => 'Calendar {{home}}/events',
                    'limit' => '10',
                    'no_upcoming_events' => false,
                    'featured_events_only' => true,
                    'jsonld_enable' => true,
                    'tribe_is_list_widget' => true,
                ],
            ],
            [
                'uuid' => TEC_QR_WIDGET_UUID,
                'type' => 'tribe-widget-events-qr-code',
                'settings' => [
                    'widget_title' => 'Event QR',
                    'qr_code_size' => '28',
                    'redirection' => 'specific',
                    'event_id' => '{{post:' . TEC_EVENT_UUID . '}}',
                    'series_id' => null,
                ],
            ],
        ],
    ],
];
duo_check_same(
    [],
    $interpreter->repository_diagnostics($widgetRepositoryTree),
    'repository compilation binds stored widget tokens to exact SidebarState owners and QR tokens to tribe_events'
);
$wrongWidgetOwnerTree = $widgetRepositoryTree;
$wrongWidgetOwnerTree[array_key_last($wrongWidgetOwnerTree)]['data']['widgets'][0]['type'] = 'tribe-widget-events-qr-code';
tec_readiness_refuses(
    $interpreter,
    $wrongWidgetOwnerTree,
    'identity and idBase disagree',
    'a stored widget token cannot be laundered through a different TEC widget idBase'
);
$wrongWidgetEventTree = $widgetRepositoryTree;
$wrongWidgetEventTree[0]['data']['type'] = 'tribe_venue';
tec_readiness_refuses(
    $interpreter,
    $wrongWidgetEventTree,
    'must resolve to one captured tribe_events post',
    'a QR event token resolving to the wrong post type refuses at repository compilation'
);
$orphanWidgetTree = array_values(array_filter(
    $widgetRepositoryTree,
    static fn(array $entity): bool => ($entity['type'] ?? '') !== 'sidebar'
));
tec_readiness_refuses(
    $interpreter,
    $orphanWidgetTree,
    'must resolve to one captured sidebar widget',
    'a stored widget block without a selected SidebarState owner refuses'
);

$GLOBALS['tec_readiness_options'] = ['tec_events_category_color_css' => '.tribe_events_cat-readiness{--tec-color-category-primary:#000000}'];
$GLOBALS['tec_readiness_terms'] = [
    (object) ['term_id' => 71, 'slug' => 'readiness', 'name' => 'Readiness'],
    (object) ['term_id' => 72, 'slug' => 'plain-category', 'name' => 'Plain Category'],
];
$GLOBALS['tec_readiness_term_meta'] = [
    71 => [
        'tec-events-cat-colors-primary' => '#123ABC',
        'tec-events-cat-colors-secondary' => '#fedcba',
        'tec-events-cat-colors-text' => '#ffffff',
        'tec-events-cat-colors-priority' => '17',
        'tec-events-cat-colors-hidden' => '0',
    ],
    72 => [],
];
$GLOBALS['tec_readiness_generated_css'] = '.tribe_events_cat-readiness{'
    . '--tec-color-category-primary:#123abc;'
    . '--tec-color-category-secondary:#fedcba;'
    . '--tec-color-category-text:#ffffff}';
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 17,
    'primary' => '#123ABC',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_tribe_options'] = ['category-color-show-hidden-categories' => false];
$GLOBALS['tec_readiness_filters'] = [];
$GLOBALS['tec_readiness_has_filter_calls'] = [];
$GLOBALS['tec_readiness_external_object_cache'] = false;
$GLOBALS['tec_readiness_cache_busts'] = 0;
$GLOBALS['tec_readiness_cache_reads'] = 0;
$GLOBALS['tec_readiness_cache_sets'] = 0;
$GLOBALS['tec_readiness_cache_read_mode'] = '';
$GLOBALS['tec_readiness_dropdown_get_calls'] = 0;
$GLOBALS['tec_readiness_color_controller_calls'] = 0;
$GLOBALS['tec_readiness_color_controller_mode'] = '';
$GLOBALS['wp_filter'] = [];
$GLOBALS['tec_readiness_tribe_vars'] = [];
$GLOBALS['tec_readiness_log_dispatches'] = 0;
$GLOBALS['tec_readiness_expired_transient_deletes'] = 0;
$GLOBALS['tec_readiness_category_color_controller'] = new \TEC\Events\Category_Colors\CSS\Controller();
$GLOBALS['tec_readiness_category_color_generator'] = new \TEC\Events\Category_Colors\CSS\Generator();
$GLOBALS['tec_readiness_category_color_dropdown'] = new \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider();
$GLOBALS['tec_readiness_native_container'] = new Tribe__Container();
$GLOBALS['tec_readiness_container_bindings'] = [];
$GLOBALS['tec_readiness_container_binding_signals'] = [];
$GLOBALS['tec_readiness_configuration'] = [];
$GLOBALS['tec_readiness_category_color_cache'] = new Tribe__Cache();
$GLOBALS['tec_readiness_container_cache'] = $GLOBALS['tec_readiness_category_color_cache'];
$GLOBALS['tec_readiness_log_provider'] = new \Tribe\Log\Service_Provider();
add_action(
    Tribe__Cache::SCHEDULED_EVENT_DELETE_TRANSIENT,
    [$GLOBALS['tec_readiness_category_color_cache'], 'delete_expired_transients']
);
add_action('shutdown', [$GLOBALS['tec_readiness_category_color_cache'], 'maybe_delete_expired_transients']);
add_action('tribe_log', [$GLOBALS['tec_readiness_log_provider'], 'dispatch_log'], 10, 3);
$colorDb = FakeWpdb::install();
tec_readiness_sync_color_db();

$colorProvider = new TheEventsCalendarCategoryColors($policy);
duo_check_same(
    [
        'id' => 'the-events-calendar-category-colors',
        'plugin' => 'the-events-calendar/the-events-calendar.php',
        'version' => '1.0.0',
    ],
    $colorProvider->identity(),
    'the executable provider identity matches the manifest declaration exactly'
);
$colorCapability = $colorProvider->capabilities()['regenerate_css'] ?? null;
duo_check_same('site', $colorCapability['scope'] ?? null, 'native CSS regeneration is honestly site-scoped');
duo_check_same(true, $colorCapability['idempotent'] ?? null, 'native CSS regeneration declares idempotence');
duo_check_same(120, $colorCapability['timeout_seconds'] ?? null, 'the native CSS/category scan has a bounded large-taxonomy timeout claim');
duo_check_same(
    ['option:tec_events_category_color_css', 'entity:tec-category-colors-dropdown-cache'],
    $colorCapability['writes'] ?? null,
    'capability negotiation declares both the durable CSS row and exact native object-cache entity'
);
duo_check_same(
    [
        'term:tribe_events_cat',
        'option:tec_events_category_color_css',
        'option:tribe_events_calendar_options',
        'entity:tec-category-colors-dropdown-cache',
    ],
    $colorCapability['reads'] ?? null,
    'capability negotiation declares the transient cache observation as well as every durable input'
);
$colorCapabilityDigest = \Duo\Providers::scoped_capability_digest(
    'the-events-calendar-category-colors',
    'regenerate_css',
    $colorCapability
);
duo_check_same(
    'c9520f2c4f79439396c46b152c2ee385407d9d3e02948b05ccd9025c48248622',
    $colorCapabilityDigest,
    'the provider capability digest binds both declared native effects'
);
$cssOnlyCapability = $colorCapability;
$cssOnlyCapability['writes'] = ['option:tec_events_category_color_css'];
duo_check(
    preg_match('/^[a-f0-9]{64}$/D', $colorCapabilityDigest) === 1
        && !hash_equals(
            $colorCapabilityDigest,
            \Duo\Providers::scoped_capability_digest(
                'the-events-calendar-category-colors',
                'regenerate_css',
                $cssOnlyCapability
            )
        ),
    'the scoped capability digest changes if the dropdown-cache write is omitted'
);
$exactGenerator = $GLOBALS['tec_readiness_category_color_generator'];
$exactDropdown = $GLOBALS['tec_readiness_category_color_dropdown'];
$exactCache = $GLOBALS['tec_readiness_category_color_cache'];
$serviceRefusalControllerCalls = $GLOBALS['tec_readiness_color_controller_calls'];
$serviceRefusalCacheBusts = $GLOBALS['tec_readiness_cache_busts'];
$GLOBALS['tec_readiness_category_color_generator'] = new stdClass();
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a controller-container override cannot substitute an unreviewed native CSS generator',
    'identity is unavailable or overridden'
);
$GLOBALS['tec_readiness_category_color_generator'] = $exactGenerator;
$GLOBALS['tec_readiness_category_color_dropdown'] = new stdClass();
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a container override cannot substitute an unreviewed callable for the exact native dropdown provider',
    'identity is unavailable or overridden'
);
$GLOBALS['tec_readiness_category_color_dropdown'] = $exactDropdown;
$GLOBALS['tec_readiness_category_color_cache'] = new stdClass();
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'an overridden TEC cache service refuses before native CSS or cache mutation',
    'cache identity is unavailable or overridden'
);
$GLOBALS['tec_readiness_category_color_cache'] = $exactCache;
$GLOBALS['tec_readiness_external_object_cache'] = true;
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'an external object-cache topology refuses before an unfenced irreversible cache mutation',
    'does not admit an external object-cache topology'
);
$GLOBALS['tec_readiness_external_object_cache'] = false;
duo_check_same(
    $serviceRefusalControllerCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'all native service and cache-topology refusals happen before generator execution'
);
duo_check_same(
    $serviceRefusalCacheBusts,
    $GLOBALS['tec_readiness_cache_busts'],
    'all native service and cache-topology refusals happen before dropdown-cache mutation'
);
$firstColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $firstColorReceipt['verified'] ?? null, 'native CSS regeneration returns a verified structured receipt');
duo_check_same(1, $GLOBALS['tec_readiness_cache_busts'], 'the provider invokes TEC native controller semantics including cache busting');
$optionHookFixture = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/the-events-calendar-wordpress-option-hooks.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$wooRewriteTopology = json_decode(
    (string) file_get_contents(
        $root . '/sandbox/tests/fixtures/woocommerce-rewrite-coinstall-topology.json'
    ),
    true,
    512,
    JSON_THROW_ON_ERROR
);
duo_check_same(
    '11.0.1',
    $wooRewriteTopology['artifacts']['woocommerce']['version'] ?? null,
    'the TEC rewrite co-install contract is pinned to exact WooCommerce 11.0.1'
);
$wooSourceHashes = [];
foreach (($wooRewriteTopology['source_files'] ?? []) as $sourceFile) {
    if (($sourceFile['plugin'] ?? null) === 'woocommerce') {
        $wooSourceHashes[(string) ($sourceFile['path'] ?? '')] = $sourceFile['sha256'] ?? null;
    }
}
duo_check_same(
    [
        'includes/class-woocommerce.php' =>
            '2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7',
        'src/Internal/Features/FeaturesController.php' =>
            'c39f44ebd0928be1c3f3a5066422defa5623705dc44f440f4572595def5866b2',
        'src/Internal/DataStores/Orders/DataSynchronizer.php' =>
            'a10ff8e2e5820deeb5a032cccfc2ffca09a5134e3e87e388e0262a89a8805234',
        'src/Internal/DataStores/Orders/CustomOrdersTableController.php' =>
            'b4d1a6772b064de9be6a80750074b0a9e371514f58131a1701cad6cd52ccb8bf',
    ],
    array_intersect_key($wooSourceHashes, array_fill_keys([
        'includes/class-woocommerce.php',
        'src/Internal/Features/FeaturesController.php',
        'src/Internal/DataStores/Orders/DataSynchronizer.php',
        'src/Internal/DataStores/Orders/CustomOrdersTableController.php',
    ], true)),
    'the exact Woo container and three no-op option services are source-hash bound'
);
$coinstallSourceHashes = [];
foreach (($wooRewriteTopology['source_files'] ?? []) as $sourceFile) {
    $plugin = (string) ($sourceFile['plugin'] ?? '');
    $path = (string) ($sourceFile['path'] ?? '');
    $coinstallSourceHashes[$plugin][$path] = $sourceFile['sha256'] ?? null;
}
duo_check_same(
    [
        'inc/class-yoast-dynamic-rewrites.php' =>
            '3b07ec0af1f94269b2a5a98bba078edbee73e1697aeeed119ae12ff4a3ca7553',
        'wp-seo-main.php' =>
            '5ecb2632b7997782e7efda714ab11e4a1ca479a8f3277c8e3137600bcb575ff1',
    ],
    $coinstallSourceHashes['wordpress-seo'] ?? null,
    'the exact Yoast singleton registration and bounded dynamic-rule maps are source-hash bound'
);
duo_check_same(
    [
        'src/links-directory.php' =>
            '5cadce6a89e87278bdd021d8f049d9c4e511acecc6c6366808740f04027d2dc0',
        'src/links-permalinks.php' =>
            'cc15a8ffa92ffb045cd5c5ef350688c7b2e36c6b43ceb9c68bdf6f8c5ed68f98',
        'src/base.php' =>
            '23c6fad9a329966eb841ac86f468f347c4bf9cc4bb382e2f9a777c1b2f450762',
        'src/api.php' =>
            '4ff84b4c80783cefaa497009812b492d816ad6be8f5f5c79613f18906a462793',
    ],
    $coinstallSourceHashes['polylang'] ?? null,
    'the exact Polylang runtime root, directory model, and dynamic type roster are source-hash bound'
);
$tecRewriteSourceHashes = array_intersect_key(
    $coinstallSourceHashes['the-events-calendar'] ?? [],
    array_fill_keys([
        'common/src/Tribe/Rewrite.php',
        'common/src/Tribe/Deprecation.php',
        'src/Tribe/Rewrite.php',
        'src/Tribe/Views/V2/Manager.php',
        'src/Tribe/Views/V2/View_Register.php',
        'src/Tribe/Views/V2/Kitchen_Sink.php',
        'src/Tribe/Views/V2/Service_Provider.php',
        'src/Events/QR/Routes.php',
    ], true)
);
ksort($tecRewriteSourceHashes);
duo_check_same(
    [
        'common/src/Tribe/Deprecation.php' =>
            '71050d6b3644f5570df03b4f9c1584c4d8ba08775e958f9fb8bb62f0a3bf8d2b',
        'common/src/Tribe/Rewrite.php' =>
            '0e198faca151aeca66680e916a038eab5c264f7d0ee6472d8f07d1845d0a7b9a',
        'src/Events/QR/Routes.php' =>
            '13970bae6bc23da3db24a44c14194568c7baf25f46b6b62166972c63b3e89acf',
        'src/Tribe/Rewrite.php' =>
            '2f447a4120a349d5f596c834192b17a5b911c6c94e8a62cfaee58af89cc86aab',
        'src/Tribe/Views/V2/Kitchen_Sink.php' =>
            '9f26d8aed55135352eb89107b5851517a6955b761831db264275e325505e578f',
        'src/Tribe/Views/V2/Manager.php' =>
            'c7138bf36ebd78bf2c749ed6b6548255064710559e31a9716f4fc8af86dde353',
        'src/Tribe/Views/V2/Service_Provider.php' =>
            '29e613ac58ae57ece7206f9db697749c41a5370d491088f5833a45d8f0f593b1',
        'src/Tribe/Views/V2/View_Register.php' =>
            '1a6d490cb4627fb282fd8fb1c9312c06308af87c3fa99be268b50db53cf3ac9f',
    ],
    $tecRewriteSourceHashes,
    'both exact TEC pins bind the complete outer and inner rewrite service graph'
);
$exactTecInnerHooks = [
    'tribe_cache_expiration',
    'tribe_events_category_slug',
    'tribe_events_tag_slug',
    'tribe_events_rewrite_i18n_domains',
    'tribe_events_rewrite_base_slugs',
    'tribe_events_rewrite_i18n_languages',
    'tribe_events_rewrite_i18n_slugs_raw',
    'tribe_events_rewrite_i18n_slugs',
    'tec_events_qr_route_base',
    'tec_events_qr_route_prefix',
    'deprecated_function_run',
    'deprecated_function_trigger_error',
];
duo_check_same(
    $exactTecInnerHooks,
    $wooRewriteTopology['tec_exact_empty_inner_hooks'] ?? null,
    'the source-bound TEC inner rewrite hook frontier is closed and order-stable'
);
$staticRewriteCallbacks = [];
foreach (($wooRewriteTopology['static_callbacks'] ?? []) as $callback) {
    $staticRewriteCallbacks[(string) ($callback['hook'] ?? '')][] = $callback['callback'] ?? null;
}
foreach ([
    'generate_rewrite_rules' => [
        'Tribe__Cache_Listener::generate_rewrite_rules',
        'Tribe__Events__Rewrite::filter_generate',
    ],
    'rewrite_rules_array' => [
        'wc_fix_rewrite_rules',
        'Tribe__Events__Rewrite::filter_rewrite_rules_array',
    ],
    'option_rewrite_rules' => [
        'Yoast_Dynamic_Rewrites::filter_rewrite_rules_option',
    ],
    'sanitize_option_rewrite_rules' => [
        'Yoast_Dynamic_Rewrites::sanitize_rewrite_rules_option',
    ],
] as $hookName => $callbacks) {
    foreach ($callbacks as $callback) {
        duo_check(
            in_array($callback, $staticRewriteCallbacks[$hookName] ?? [], true),
            "the exact co-install fixture pins $hookName callback $callback"
        );
    }
}
duo_check_same(
    ['rewrite_rules_array', '{type}_rewrite_rules', 'pll_modify_rewrite_rule'],
    array_column($wooRewriteTopology['dynamic_callback_containers'] ?? [], 'hook'),
    'the Polylang fixture distinguishes its exact dynamic callbacks from the refused open filter chain'
);
duo_check_same(
    [
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_updated_option',
            'priority' => 999,
            'accepted_args' => 3,
        ],
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_updated_option',
            'priority' => 999,
            'accepted_args' => 3,
        ],
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option',
            'priority' => 999,
            'accepted_args' => 3,
        ],
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_updated_option_fts_index',
            'priority' => 999,
            'accepted_args' => 3,
        ],
    ],
    $wooRewriteTopology['woocommerce_normal_option_topology']['updated_option'] ?? null,
    'the source fixture pins the exact four normal Woo updated-option callbacks'
);
duo_check_same(
    [
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController::process_pre_update_option',
            'priority' => 999,
            'accepted_args' => 3,
        ],
    ],
    $wooRewriteTopology['woocommerce_normal_option_topology']['pre_update_option'] ?? null,
    'the source fixture pins the exact normal Woo pre-update callback'
);
duo_check_same(
    [
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController::process_added_option',
            'priority' => 999,
            'accepted_args' => 3,
        ],
        [
            'callback' => 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer::process_added_option',
            'priority' => 999,
            'accepted_args' => 2,
        ],
    ],
    $wooRewriteTopology['woocommerce_normal_option_topology']['added_option'] ?? null,
    'the source fixture pins the exact normal Woo add-option callbacks'
);
duo_check_same(
    ['6.9.2', '7.0.3', '7.1'],
    array_keys($optionHookFixture['source_files'] ?? []),
    'the CSS option topology is source-bound to every exact admitted WordPress core artifact'
);
duo_check_same(
    'php sandbox/tests/support/verify-tec-wordpress-option-hooks.php '
        . '--wordpress-root=/usr/src/wordpress --version=<version>',
    $optionHookFixture['reproduce'] ?? null,
    'the reviewed WordPress option topology carries its deterministic exact-source verifier command'
);
duo_check_same(
    'php sandbox/tests/support/verify-tec-wordpress-option-hooks.php '
        . '--wordpress-root=/usr/src/wordpress --version=<wp-version> '
        . '--tec-root=/path/to/the-events-calendar --tec-version=<tec-version>',
    $optionHookFixture['reproduce_tec'] ?? null,
    'the native derived-state services carry one deterministic exact-source verifier command'
);
$optionHookVerifier = (string) file_get_contents(
    $root . '/sandbox/tests/support/verify-tec-wordpress-option-hooks.php'
);
foreach (['get_option', 'wp_load_alloptions', 'update_option', 'add_option', 'sanitize_option'] as $function) {
    duo_check(
        str_contains($optionHookVerifier, "tec_option_function_body(\$option, '$function')")
            || str_contains($optionHookVerifier, "tec_option_function_body(\$formatting, '$function')"),
        "the exact-source verifier derives hook topology from WordPress function $function"
    );
}
$tecServiceSources172 = $optionHookFixture['tec_service_sources']['6.17.2'] ?? [];
$tecServiceSources173 = $optionHookFixture['tec_service_sources']['6.17.3'] ?? [];
foreach ([
    'the-events-calendar.php' => 'plugin header',
    'src/Tribe/Main.php' => 'native version constant',
    'src/Tribe/Views/V2/Widgets/Service_Provider.php' => 'state-bearing widget provider',
] as $versionSpecificPath => $versionSpecificLabel) {
    duo_check(
        is_array($tecServiceSources172)
            && is_array($tecServiceSources173)
            && ($tecServiceSources172[$versionSpecificPath] ?? null)
                !== ($tecServiceSources173[$versionSpecificPath] ?? null),
        "the exact 6.17.3 delta changes the $versionSpecificLabel bytes"
    );
    unset($tecServiceSources172[$versionSpecificPath], $tecServiceSources173[$versionSpecificPath]);
}
duo_check_same(
    $tecServiceSources172,
    $tecServiceSources173,
    'every other source in the reviewed TEC state-service and lifecycle union is byte-identical across both pins'
);
duo_check_same(
    [
        'the-events-calendar.php',
        'uninstall.php',
        'build/js/customizer-views-v2-controls.js',
        'build/js/customizer-views-v2-live-preview.js',
        'common/src/Tribe/Abstract_Deactivation.php',
        'common/src/Tribe/Cache.php',
        'common/src/Tribe/Cache_Listener.php',
        'common/src/Tribe/Rewrite.php',
        'common/src/Tribe/Deprecation.php',
        'common/src/Tribe/Container.php',
        'common/src/Tribe/Customizer.php',
        'common/src/Tribe/Customizer/Section.php',
        'common/src/Tribe/Widget/Manager.php',
        'common/src/Tribe/Widget/Widget_Abstract.php',
        'common/src/Tribe/Settings_Manager.php',
        'common/src/Common/Libraries/Harbor.php',
        'common/src/Common/Integrations/Harbor/PUE.php',
        'common/src/Common/Configuration/Configuration.php',
        'common/vendor/vendor-prefixed/lucatume/di52/src/Container.php',
        'src/Events/Category_Colors/CSS/Controller.php',
        'src/Events/Category_Colors/CSS/Generator.php',
        'src/Events/Category_Colors/Repositories/Category_Color_Dropdown_Provider.php',
        'src/Events/Custom_Tables/V1/Activation.php',
        'src/Events/Custom_Tables/V1/Models/Event.php',
        'src/Events/Custom_Tables/V1/Models/Occurrence.php',
        'src/Events/Custom_Tables/V1/Models/Builder.php',
        'src/Events/Custom_Tables/V1/Events/Occurrences/Occurrences_Generator.php',
        'src/Events/Custom_Tables/V1/Provider.php',
        'src/Events/Admin/Onboarding/Controller.php',
        'src/Events/Controller.php',
        'src/Events/QR/Routes.php',
        'src/Tribe/Aggregator.php',
        'src/Tribe/Aggregator/Record/Queue_Processor.php',
        'src/Tribe/Aggregator/Records.php',
        'src/Tribe/Capabilities.php',
        'src/Tribe/Deactivation.php',
        'src/Tribe/Event_Cleaner_Scheduler.php',
        'src/Tribe/Main.php',
        'src/Tribe/Rewrite.php',
        'src/Tribe/Updater.php',
        'src/Tribe/Views/V2/Customizer/Hooks.php',
        'src/Tribe/Views/V2/Customizer/Section/Events_Bar.php',
        'src/Tribe/Views/V2/Customizer/Section/Global_Elements.php',
        'src/Tribe/Views/V2/Customizer/Section/Month_View.php',
        'src/Tribe/Views/V2/Customizer/Section/Single_Event.php',
        'src/Tribe/Views/V2/Customizer/Service_Provider.php',
        'src/Tribe/Views/V2/Hooks.php',
        'src/Tribe/Views/V2/Kitchen_Sink.php',
        'src/Tribe/Views/V2/Manager.php',
        'src/Tribe/Views/V2/View_Register.php',
        'src/Tribe/Views/V2/Service_Provider.php',
        'src/Tribe/Views/V2/Widgets/Service_Provider.php',
        'src/Tribe/Views/V2/Widgets/Widget_Abstract.php',
        'src/Tribe/Views/V2/Widgets/Widget_List.php',
        'src/Tribe/Views/V2/Widgets/Widget_QR_Code.php',
    ],
    array_keys($optionHookFixture['tec_service_sources']['6.17.3'] ?? []),
    'the source fixture binds every exact native CSS/cache, custom-table, and lifecycle service'
);
foreach ([
    'tec-root',
    'tec-version',
    'common/src/Tribe/Cache_Listener.php',
    'common/src/Tribe/Customizer.php',
    'common/src/Tribe/Customizer/Section.php',
    'wp-includes/blocks/legacy-widget.php',
    'wp-includes/blocks/legacy-widget/block.json',
    'src/Tribe/Views/V2/Widgets/Service_Provider.php',
    'common/src/Tribe/Settings_Manager.php',
    'common/src/Common/Integrations/Harbor/PUE.php',
    'tribe()->make( Occurrences_Generator::class )',
    'tribe( Configuration::class )',
    "'pue_install_key_event_aggregator'",
    "'WPLANG'",
    'maybe_fallback_get_option',
    'tribe_events_pro_customizer',
    'tec_option_literal_return_map',
    'events.views.v2.customizer.global-elements',
    'events.views.v2.customizer.month-view',
    'events.views.v2.customizer.events-bar',
    'events.views.v2.customizer.single-event',
    'targetOwnedResidue',
    'enable_widget_copy_paste',
    'enable_saving_widget_copied',
    'enable_rendering_widget_copied',
    'is_safe_widget_instance',
    'allowed_classes',
    'register_activation_hook',
    'register_deactivation_hook',
    'clear_ct1_activation_state',
    'maybe_delayed_flush_rewrite_rules',
    'maybe_redirect_to_guided_setup_on_activation',
    'runtime/request-consumed',
    'lifecycle schema-version reset drifted',
    'tribe_schedule_transient_purge',
    'tribe_aggregator_single_process_insert_records',
    'WP_UNINSTALL_PLUGIN guard only; no state mutation',
    'source-derived TEC lifecycle boundary',
] as $serviceVerifierEvidence) {
    duo_check(
        str_contains($optionHookVerifier, $serviceVerifierEvidence),
        "the exact-source verifier binds native service evidence $serviceVerifierEvidence"
    );
}
$lifecycleBoundary = $optionHookFixture['lifecycle_boundary'] ?? null;
duo_check(is_array($lifecycleBoundary), 'the exact fixture carries the TEC lifecycle boundary');
duo_check_same(
    [
        '_tribe_events_delayed_flush_rewrite_rules' => [
            'ownership' => 'runtime/request-consumed',
            'consumer' => 'Tribe__Events__Rewrite::maybe_delayed_flush_rewrite_rules',
            'hook' => 'wp_loaded',
            'terminal' => 'delete transient before flush_rewrite_rules on the next loaded request',
            'stable_portable_receipt' => false,
        ],
        '_tribe_events_activation_redirect' => [
            'ownership' => 'runtime/request-consumed',
            'consumer' => 'TEC\\Events\\Admin\\Onboarding\\Controller::maybe_redirect_to_guided_setup_on_activation',
            'hook' => 'tec_admin_headers_about_to_be_sent',
            'terminal' => 'delete on bulk activation or the next eligible admin-header request; otherwise expire after 30 seconds',
            'stable_portable_receipt' => false,
        ],
    ],
    $lifecycleBoundary['activation']['transient_ownership'] ?? null,
    'activation transients are exact request-consumed runtime rather than portable lifecycle receipts'
);
$lifecyclePolicy = Policy::load(null, ['core', 'the-events-calendar']);
foreach ([
    '_transient__tribe_events_delayed_flush_rewrite_rules',
    '_transient_timeout__tribe_events_delayed_flush_rewrite_rules',
    '_transient__tribe_events_activation_redirect',
    '_transient_timeout__tribe_events_activation_redirect',
] as $activationTransientRow) {
    duo_check_same(
        'derived',
        $lifecyclePolicy->option_rule($activationTransientRow)['class'] ?? null,
        "$activationTransientRow remains excluded by the core transient policy"
    );
}
duo_check_same(
    [
        'tribe_schedule_transient_purge',
        'tribe_trash_event_cron',
        'tribe_del_event_cron',
        'tribe_aggregator_process_insert_records',
    ],
    $lifecycleBoundary['deactivation']['clears_cron_hooks'] ?? null,
    'deactivation classifies every exact native recurring cron cleanup'
);
duo_check_same(
    ['tribe_aggregator_single_process_insert_records'],
    $lifecycleBoundary['deactivation']['retains_cron_hooks'] ?? null,
    'the native one-shot Aggregator queue is explicit target-owned deactivation residue'
);
duo_check_same(
    '5.16.0',
    $lifecycleBoundary['deactivation']['schema_version_reset'] ?? null,
    'deactivation records the exact env-owned mixed-option schema-version transition'
);
duo_check_same(
    false,
    $lifecycleBoundary['deactivation']['custom_table_clean_registered'] ?? null,
    'native deactivation leaves the derived custom tables intact instead of invoking the dormant clean method'
);
duo_check_same(
    [
        'bytes' => 60,
        'sha256' => '767dc6e504b10dc655a44396e7e91c9726379edd302621eacd439c446e5e183d',
        'behavior' => 'WP_UNINSTALL_PLUGIN guard only; no state mutation',
    ],
    $lifecycleBoundary['uninstall'] ?? null,
    'both exact artifacts bind the identical guard-only uninstall surface'
);
duo_check_same(
    $optionHookFixture['tec_service_sources']['6.17.2']['uninstall.php'] ?? null,
    $optionHookFixture['tec_service_sources']['6.17.3']['uninstall.php'] ?? null,
    'the two exact uninstall files are byte-identical'
);
$legacyWidgetBoundary = $optionHookFixture['legacy_widget_boundary'] ?? null;
duo_check(is_array($legacyWidgetBoundary), 'the exact fixture carries the TEC legacy-widget state boundary');
duo_check_same(
    ['id', 'idBase', 'instance'],
    $legacyWidgetBoundary['attributes'] ?? null,
    'the exact WordPress schema exposes both stored-id and embedded-instance legacy-widget forms'
);
duo_check_same(
    ['sidebars_widgets', 'widget_<idBase>'],
    $legacyWidgetBoundary['stored_id_form']['storage'] ?? null,
    'the stored-id form is explicitly bound to SidebarState physical storage'
);
duo_check_same(
    ['tribe-widget-events-list', 'tribe-widget-events-qr-code'],
    $legacyWidgetBoundary['embedded_form']['id_bases'] ?? null,
    'the exact free plugin registers only the two reviewed legacy widget id bases'
);
duo_check_same(
    [
        'serialized_bytes' => 16384,
        'encoded_bytes' => 21848,
        'string_bytes' => 4096,
        'depth' => 6,
        'nodes' => 64,
    ],
    $legacyWidgetBoundary['embedded_form']['limits'] ?? null,
    'the embedded codec frontier is bounded independently of PHP and request memory limits'
);
duo_check_same(
    [
        'tribe-widget-events-list' => [
            'settings' => ['title', 'limit', 'no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget'],
            'limit' => [1, 10],
            'booleans' => ['no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget'],
        ],
        'tribe-widget-events-qr-code' => [
            'settings' => ['widget_title', 'qr_code_size', 'redirection', 'event_id', 'series_id'],
            'qr_code_size' => ['4', '8', '12', '16', '20', '24', '28'],
            'redirection' => ['current', 'upcoming', 'specific'],
            'event_ref' => 'post:tribe_events',
            'series_ref' => 'free-plugin-absence',
        ],
    ],
    $legacyWidgetBoundary['widget_types'] ?? null,
    'the source fixture closes both free TEC widget setting grammars and licensed-series boundary'
);
duo_check_same(
    [
        'rest_pre_dispatch' => 'enable_widget_copy_paste',
        'rest_dispatch_request' => 'enable_saving_widget_copied',
        'render_block_data' => 'enable_rendering_widget_copied',
    ],
    $legacyWidgetBoundary['callbacks'] ?? null,
    'the state-bearing copy/save/render callback topology is closed'
);
duo_check_same(
    true,
    $legacyWidgetBoundary['duo_status']['portable'] ?? null,
    'the source audit promotes legacy-widget only with the shipped bounded target-rebinding codec'
);
$coreManifest = json_decode(
    (string) file_get_contents($root . '/manifests/core.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$legacyWidgetRules = $coreManifest['block_attrs']['core/legacy-widget'] ?? [];
duo_check(
    array_column($legacyWidgetRules, 'path') === ['id', 'idBase', 'instance']
        && count(array_filter(
            $legacyWidgetRules,
            static fn(array $rule): bool => is_string($rule['unsupported'] ?? null)
                && $rule['unsupported'] !== ''
        )) === 3,
    'the core-only grammar still loudly refuses all three legacy-widget attributes outside the TEC adapter'
);
$activeLegacyWidgetRules = $policy->block_attr_rules()['core/legacy-widget'] ?? [];
duo_check_same(
    [
        ['codec' => 'the-events-calendar', 'path' => 'id'],
        ['codec' => 'the-events-calendar', 'path' => 'idBase'],
        ['codec' => 'the-events-calendar', 'path' => 'instance'],
    ],
    $activeLegacyWidgetRules,
    'the active TEC policy assigns the three interdependent legacy-widget attributes to one manifest-bound codec'
);
duo_check_same(
    [
        'tribe-widget-events-list' => [
            'settings' => [
                'title' => ['class' => 'authored'],
                'limit' => ['class' => 'authored'],
                'no_upcoming_events' => ['class' => 'authored'],
                'featured_events_only' => ['class' => 'authored'],
                'jsonld_enable' => ['class' => 'authored'],
                'tribe_is_list_widget' => ['class' => 'authored'],
            ],
        ],
        'tribe-widget-events-qr-code' => [
            'settings' => [
                'widget_title' => ['class' => 'authored'],
                'qr_code_size' => ['class' => 'authored'],
                'redirection' => ['class' => 'authored'],
                'event_id' => ['class' => 'authored', 'ref' => 'post'],
                'series_id' => ['class' => 'authored'],
            ],
        ],
    ],
    array_intersect_key(
        $policy->widget_types(),
        array_flip(['tribe-widget-events-list', 'tribe-widget-events-qr-code'])
    ),
    'SidebarState declares every exact native TEC widget setting and the QR event reference'
);
$expectedCustomizerFallback = [
    'canonical_option' => 'tribe_customizer',
    'legacy_option' => 'tribe_events_pro_customizer',
    'panel_id_filter' => 'tribe_customizer_panel_id',
    'activation_filter' => 'tribe_customizer_is_active',
    'fallback_hook' => 'default_option_tribe_customizer',
    'callback_class' => 'Tribe__Customizer',
    'callback_method' => 'maybe_fallback_get_option',
    'value_filters' => [
        'tribe_events_pro_customizer_pre_get_option',
        'tribe_customizer_pre_get_option',
        'tribe_customizer_get_option',
    ],
    'precedence' => [
        'canonical_present' => 'canonical',
        'canonical_present_empty' => 'canonical',
        'canonical_absent_legacy_present' => 'legacy',
        'canonical_absent_legacy_absent' => 'empty',
    ],
];
duo_check_same(
    $expectedCustomizerFallback,
    $optionHookFixture['customizer_fallback'] ?? null,
    'the exact source fixture binds the canonical Customizer row, legacy compatibility input, callback, filters, and precedence'
);
$effectiveCustomizer = static function (
    bool $canonicalPresent,
    mixed $canonical,
    bool $legacyPresent,
    mixed $legacy
): mixed {
    // WordPress applies default_option_tribe_customizer only for an absent
    // canonical row; Tribe__Customizer then reads the legacy row or [].
    if ($canonicalPresent) {
        return $canonical;
    }
    return $legacyPresent ? $legacy : [];
};
foreach ([
    'canonical populated beats legacy' => [true, ['month_view' => ['event_date_time_color' => '#112233']], true, ['legacy'], ['month_view' => ['event_date_time_color' => '#112233']]],
    'canonical empty beats populated legacy' => [true, [], true, ['legacy'], []],
    'absent canonical reads legacy' => [false, null, true, ['global_elements' => ['background_color_choice' => 'custom']], ['global_elements' => ['background_color_choice' => 'custom']]],
    'both absent resolve to empty' => [false, null, false, null, []],
] as $label => [$canonicalPresent, $canonical, $legacyPresent, $legacy, $expected]) {
    duo_check_same(
        $expected,
        $effectiveCustomizer($canonicalPresent, $canonical, $legacyPresent, $legacy),
        "exact Customizer fallback semantics preserve $label"
    );
}
$customizerSourceDigest = $optionHookFixture['tec_service_sources']['6.17.3']['common/src/Tribe/Customizer.php'] ?? null;
duo_check_same(
    '83ba4277bb122d476daf5782cd0c2bfc643ad1f875747aa39beba2782aa014c1',
    $customizerSourceDigest,
    'both admitted artifacts bind the exact native Customizer callback source bytes'
);
$customizerSections = $optionHookFixture['customizer_sections'] ?? null;
duo_check(is_array($customizerSections), 'the exact source fixture carries a Customizer section registry');
duo_check_same(
    [
        'events.views.v2.customizer.global-elements',
        'events.views.v2.customizer.month-view',
        'events.views.v2.customizer.events-bar',
        'events.views.v2.customizer.single-event',
    ],
    array_keys($customizerSections['sections'] ?? []),
    'the exact free service topology exposes only the four reviewed Views V2 Customizer sections'
);
duo_check_same(
    ['sanitize_callback', 'sanitize_js_callback', 'transport'],
    $customizerSections['setting_tuple'] ?? null,
    'the source fixture preserves every native setting callback/transport field'
);
duo_check_same(
    [
        'canonical_option' => 'tribe_customizer',
        'defaults' => 'read-time-only',
        'empty_map' => 'valid',
        'setting_name_template' => 'tribe_customizer[%s][%s]',
        'setting_type' => 'option',
        'shape' => 'sparse-section-map',
    ],
    $customizerSections['storage'] ?? null,
    'the reviewed storage contract distinguishes sparse persisted bytes from native read-time defaults'
);
$customizerSettingCount = 0;
foreach (($customizerSections['sections'] ?? []) as $service => $section) {
    $defaults = (array) ($section['defaults'] ?? []);
    $settings = (array) ($section['settings'] ?? []);
    $defaultKeys = array_keys($defaults);
    $settingKeys = array_keys($settings);
    sort($defaultKeys, SORT_STRING);
    sort($settingKeys, SORT_STRING);
    duo_check_same(
        $defaultKeys,
        $settingKeys,
        "the exact $service defaults and server-side setting registry have one closed keyset"
    );
    foreach ($settings as $setting => $tuple) {
        duo_check(
            is_array($tuple)
                && count($tuple) === 3
                && in_array($tuple[0] ?? null, ['sanitize_key', 'sanitize_hex_color'], true)
                && in_array($tuple[1] ?? null, ['sanitize_key', 'maybe_hash_hex_color'], true)
                && ($tuple[2] ?? null) === 'postMessage',
            "the exact $service.$setting setting has one reviewed native sanitizer tuple"
        );
    }
    $customizerSettingCount += count($settings);
}
duo_check_same(31, $customizerSettingCount, 'the four exact free sections register 31 server-owned settings');
duo_check_same(
    [
        'tec_events_bar' => [
            'view_selector_background_color',
            'view_selector_background_color_choice',
        ],
    ],
    $customizerSections['target_owned_residue'] ?? null,
    'the two JavaScript-era Events Bar keys are known target-owned residue rather than inferred authored settings'
);

$customizerDeclaredSubKeys = [];
foreach (($customizerSections['sections'] ?? []) as $section) {
    $customizerDeclaredSubKeys[(string) ($section['id'] ?? '')] = [
        'class' => 'authored',
        'plain_data' => true,
    ];
}
$customizerRule = $policy->option_rule('tribe_customizer');
duo_check_same('env', $customizerRule['class'] ?? null, 'the canonical Customizer parent stays mixed/target-owned');
duo_check_same(true, $customizerRule['closed_sub_keys'] ?? null, 'the four-section Customizer registry is closed');
duo_check_same(
    'auto-on',
    $customizerRule['absent_autoload'] ?? null,
    'an absent canonical row uses the exact pinned-core first-save autoload wire'
);
duo_check_same(
    $customizerDeclaredSubKeys,
    $customizerRule['sub_keys'] ?? null,
    'the manifest and exact source fixture declare the same four Customizer section carriers'
);
$GLOBALS['tec_readiness_customizer'] = new Tribe__Customizer();
$normalizeCustomizerSparseMap = static function (mixed $raw) use (
    $customizerDeclaredSubKeys,
    $interpreter
): array {
    return $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        is_array($raw) ? $raw : [],
        $customizerDeclaredSubKeys,
        ['tribe_customizer' => serialize($raw)]
    );
};
foreach (($customizerSections['sections'] ?? []) as $service => $section) {
    $sectionId = (string) ($section['id'] ?? '');
    $rawSettings = [];
    $expectedSettings = [];
    foreach ((array) ($section['settings'] ?? []) as $setting => $tuple) {
        $rawSettings[(string) $setting] = ($tuple[0] ?? null) === 'sanitize_key'
            ? 'VALUE !!'
            : '#A1b2C3';
        $expectedSettings[(string) $setting] = ($tuple[0] ?? null) === 'sanitize_key'
            ? 'value'
            : '#A1b2C3';
    }
    duo_check_same(
        [$sectionId => $expectedSettings],
        $normalizeCustomizerSparseMap([$sectionId => $rawSettings]),
        "the shipped interpreter implements every exact native sanitizer in $service"
    );
}
$legacyCustomizer = [
    'global_elements' => ['background_color_choice' => 'CUSTOM !!'],
    'single_event' => ['post_title_color' => '#A1b2C3'],
];
duo_check_same(
    [
        'global_elements' => ['background_color_choice' => 'custom'],
        'single_event' => ['post_title_color' => '#A1b2C3'],
    ],
    $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        [],
        $customizerDeclaredSubKeys,
        ['tribe_events_pro_customizer' => serialize($legacyCustomizer)]
    ),
    'an absent canonical row captures the exact legacy compatibility map through the same raw snapshot'
);
$canonicalCustomizer = ['month_view' => ['grid_lines_color' => '#445566']];
duo_check_same(
    $canonicalCustomizer,
    $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        $canonicalCustomizer,
        $customizerDeclaredSubKeys,
        [
            'tribe_customizer' => serialize($canonicalCustomizer),
            'tribe_events_pro_customizer' => serialize($legacyCustomizer),
        ]
    ),
    'a populated canonical Customizer row wins over conflicting legacy compatibility bytes'
);
duo_check_same(
    [],
    $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        [],
        $customizerDeclaredSubKeys,
        [
            'tribe_customizer' => serialize([]),
            'tribe_events_pro_customizer' => serialize($legacyCustomizer),
        ]
    ),
    'a persisted empty canonical Customizer row wins over populated legacy compatibility storage'
);
duo_check_same(
    [],
    $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        [],
        $customizerDeclaredSubKeys,
        []
    ),
    'absent canonical and legacy Customizer rows remain one sparse empty map without manufactured defaults'
);
duo_check_same(
    ['toggle_blocks_editor' => '1'],
    $interpreter->normalize_captured_option_sub_keys(
        'tribe_events_calendar_options',
        ['toggle_blocks_editor' => '1'],
        [],
        []
    ),
    'the Customizer hook leaves every non-Canonical parent option byte-for-byte outside its adapter-local scope'
);
foreach ([
    'canonical snapshot value drift' => [
        ['month_view' => ['grid_lines_color' => '#112233']],
        ['tribe_customizer' => serialize(['month_view' => ['grid_lines_color' => '#445566']])],
    ],
    'canonical presence drift' => [
        ['month_view' => []],
        [],
    ],
] as $label => [$rawAuthored, $snapshot]) {
    duo_check_throws(
        static fn(): array => $interpreter->normalize_captured_option_sub_keys(
            'tribe_customizer',
            $rawAuthored,
            $customizerDeclaredSubKeys,
            $snapshot
        ),
        RuntimeException::class,
        "the Customizer snapshot binding refuses $label instead of selecting a hybrid canonical/legacy view",
        'snapshot disagrees'
    );
}
foreach ([
    'missing section declaration' => array_diff_key($customizerDeclaredSubKeys, ['month_view' => true]),
    'extension section declaration' => array_replace(
        $customizerDeclaredSubKeys,
        ['future_extension' => ['class' => 'authored', 'plain_data' => true]]
    ),
    'non-plain section declaration' => array_replace(
        $customizerDeclaredSubKeys,
        ['month_view' => ['class' => 'authored']]
    ),
    'target-owned section declaration' => array_replace(
        $customizerDeclaredSubKeys,
        ['month_view' => ['class' => 'env', 'plain_data' => true]]
    ),
] as $label => $declaration) {
    duo_check_throws(
        static fn(): array => $interpreter->normalize_captured_option_sub_keys(
            'tribe_customizer',
            [],
            $declaration,
            ['tribe_customizer' => serialize([])]
        ),
        RuntimeException::class,
        "the Customizer interpreter refuses $label before reading plugin storage",
        'Customizer'
    );
}
foreach ([
    'empty top-level map' => [[], []],
    'one empty section' => [['month_view' => []], ['month_view' => []]],
    'one native-normalized key setting' => [
        ['global_elements' => ['background_color_choice' => 'TRANSPARENT !!']],
        ['global_elements' => ['background_color_choice' => 'transparent']],
    ],
    'multiple sparse sections and empty native color' => [
        [
            'global_elements' => ['background_color' => ''],
            'single_event' => ['post_title_color' => '#A1b2C3'],
        ],
        [
            'global_elements' => ['background_color' => ''],
            'single_event' => ['post_title_color' => '#A1b2C3'],
        ],
    ],
    'known JavaScript-era residue excluded without default completion' => [
        [
            'tec_events_bar' => [
                'view_selector_background_color' => '#abcdef',
                'view_selector_background_color_choice' => 'custom',
                'events_bar_text_color' => '#123456',
            ],
        ],
        ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
    ],
] as $label => [$raw, $expected]) {
    duo_check_same(
        $expected,
        $normalizeCustomizerSparseMap($raw),
        "the reviewed sparse Customizer grammar preserves $label"
    );
}
foreach ([
    'scalar top-level storage' => 'not-a-map',
    'list-shaped top-level storage' => [['global_elements']],
    'unknown empty section' => ['future_add_on_section' => []],
    'scalar section' => ['month_view' => 'not-a-map'],
    'list-shaped section' => ['month_view' => ['#112233']],
    'unknown empty setting' => ['month_view' => ['future_setting' => '']],
    'nested setting value' => ['month_view' => ['grid_lines_color' => ['#112233']]],
    'object setting value' => ['month_view' => ['grid_lines_color' => new stdClass()]],
    'nested target-owned residue' => ['tec_events_bar' => ['view_selector_background_color' => []]],
    'invalid native color' => ['month_view' => ['grid_lines_color' => 'red']],
    'invalid UTF-8 setting' => ['global_elements' => ['font_family' => "\xC3\x28"]],
    'oversized setting' => ['global_elements' => ['font_family' => str_repeat('a', 4097)]],
] as $label => $raw) {
    duo_check_throws(
        static fn(): array => $normalizeCustomizerSparseMap($raw),
        RuntimeException::class,
        "the reviewed sparse Customizer grammar refuses $label",
        'Customizer'
    );
}
$customizerCredential = 'AKIAABCDEFGHIJKLMNOP';
try {
    $normalizeCustomizerSparseMap([
        'global_elements' => ['font_family' => $customizerCredential],
    ]);
    duo_check(false, 'a credential-shaped Customizer setting refuses before native sanitizer normalization');
} catch (RuntimeException $e) {
    duo_check(
        str_contains($e->getMessage(), 'credential-shaped')
            && !str_contains($e->getMessage(), $customizerCredential)
            && strlen($e->getMessage()) < 256,
        'a credential-shaped Customizer setting refuses with one bounded diagnostic and no source bytes'
    );
}
$referencedColor = '#112233';
$referencedCustomizer = [
    'month_view' => [
        'grid_lines_color' => &$referencedColor,
        'grid_hover_color' => &$referencedColor,
    ],
];
foreach ([
    'non-string row bytes' => [],
    'noncanonical surrounding whitespace' => ' a:0:{}',
    'trailing serialized payload' => 'a:0:{} trailing',
    'duplicate serialized section keys' => 'a:2:{s:10:"month_view";a:0:{}s:10:"month_view";a:0:{}}',
    'serialized object payload' => serialize(new stdClass()),
    'serialized shared reference payload' => serialize($referencedCustomizer),
    'oversized raw row' => str_repeat('x', 65537),
] as $label => $rawStorage) {
    duo_check_throws(
        static fn(): array => $interpreter->normalize_captured_option_sub_keys(
            'tribe_customizer',
            [],
            $customizerDeclaredSubKeys,
            ['tribe_customizer' => $rawStorage]
        ),
        RuntimeException::class,
        "the exact Customizer storage boundary refuses $label",
        'Customizer'
    );
}
duo_check_throws(
    static fn(): array => $interpreter->normalize_captured_option_sub_keys(
        'tribe_customizer',
        [],
        $customizerDeclaredSubKeys,
        ['tribe_events_pro_customizer' => 'a:0:{} trailing']
    ),
    RuntimeException::class,
    'the legacy compatibility input crosses the same canonical serialized-data boundary as the current row',
    'legacy compatibility Customizer'
);
$oversizedCustomizer = [];
foreach (($customizerSections['sections'] ?? []) as $section) {
    $sectionId = (string) ($section['id'] ?? '');
    foreach ((array) ($section['settings'] ?? []) as $setting => $_tuple) {
        $oversizedCustomizer[$sectionId][(string) $setting] = str_repeat('a', 4096);
    }
}
duo_check_throws(
    static fn(): array => $normalizeCustomizerSparseMap($oversizedCustomizer),
    RuntimeException::class,
    'the reviewed sparse Customizer grammar refuses its bounded raw or aggregate option frontier before sanitizer dispatch',
    'Customizer'
);
$tecRegeneratorSource = (string) file_get_contents(
    $root . '/manifests/regenerators/the-events-calendar.php'
);
$lastSaveHookTopology = [];
foreach (($optionHookFixture['last_save_paths'] ?? []) as $pathHooks) {
    foreach ($pathHooks as $pathHook) {
        $lastSaveHookTopology[$pathHook] = $pathHook;
    }
}
foreach ($lastSaveHookTopology as $lastSaveHook) {
    duo_check(
        str_contains($tecRegeneratorSource, "'$lastSaveHook'"),
        "the derived-state regenerator preflights native marker hook $lastSaveHook"
    );
}
$nativeRewriteEffectSource = (string) file_get_contents(
    $root . '/agent/src/Rebuild/NativeRewriteEffects.php'
);
foreach (array_intersect_key($wooSourceHashes, array_fill_keys([
    'includes/class-woocommerce.php',
    'src/Internal/Features/FeaturesController.php',
    'src/Internal/DataStores/Orders/DataSynchronizer.php',
    'src/Internal/DataStores/Orders/CustomOrdersTableController.php',
], true)) as $wooSourceHash) {
    duo_check(
        is_string($wooSourceHash) && str_contains($nativeRewriteEffectSource, $wooSourceHash),
        'the shipped rewrite boundary cites each exact Woo service/bootstrap source hash it admits'
    );
}
foreach ([
    'tribe_last_generate_rewrite_rules',
    'tribe_last_updated_option',
    'tribe_last_save_post',
] as $markerName) {
    foreach ($lastSaveHookTopology as $lastSaveHook) {
        $markerHook = str_replace('tribe_last_save_post', $markerName, $lastSaveHook);
        $dynamicPrefix = str_replace($markerName, '', $markerHook);
        duo_check(
            str_contains($nativeRewriteEffectSource, "'$markerHook'")
                || ($dynamicPrefix !== $markerHook
                    && str_contains($nativeRewriteEffectSource, "'$dynamicPrefix'"))
                || in_array($markerHook, [
                    'pre_option',
                    'pre_wp_load_alloptions',
                    'pre_cache_alloptions',
                    'alloptions',
                    'pre_update_option',
                    'update_option',
                    'wp_autoload_values_to_autoload',
                    'wp_default_autoload_value',
                    'wp_max_autoloaded_option_size',
                    'updated_option',
                    'add_option',
                    'added_option',
                ], true),
            "the native rewrite boundary generalizes the pinned option branch to $markerHook"
        );
    }
}
$rewriteEffect = null;
foreach (($manifest['actions'][0]['effects'] ?? []) as $effect) {
    if (($effect['id'] ?? null) === 'tec-rewrite-generation-runtime') {
        $rewriteEffect = $effect;
        break;
    }
}
$coreRewriteEffect = null;
foreach (($coreManifest['actions'][0]['effects'] ?? []) as $effect) {
    if (($effect['id'] ?? null) === 'core-rewrite-generation-runtime') {
        $coreRewriteEffect = $effect;
        break;
    }
}
duo_check_same(
    $optionHookFixture['rewrite_generation']['exact_hooks'] ?? null,
    $rewriteEffect['selector']['members']['exact'] ?? null,
    'the irreversible rewrite aggregate names every exact pinned core and shipped-plugin generation hook'
);
duo_check_same(
    $rewriteEffect['selector'] ?? null,
    $coreRewriteEffect['selector'] ?? null,
    'core and TEC bind the same closed cross-adapter rewrite interpreter'
);
duo_check_same(
    [$optionHookFixture['rewrite_generation']['dynamic_hook_template'] ?? null],
    $rewriteEffect['selector']['members']['templates'] ?? null,
    'the rewrite aggregate admits only the bounded registered-permastruct hook template'
);
$declaredRewriteHooks = [];
foreach (($manifest['actions'][0]['effects'] ?? []) as $effect) {
    if (($effect['selector']['type'] ?? null) === 'hook') {
        $declaredRewriteHooks[(string) ($effect['selector']['value'] ?? '')] = true;
    }
}
foreach ($lastSaveHookTopology as $lastSaveHook) {
    $rewriteHook = str_replace('tribe_last_save_post', 'rewrite_rules', $lastSaveHook);
    duo_check(
        isset($declaredRewriteHooks[$rewriteHook]),
        "the native action inventory includes the pinned rewrite_rules branch hook $rewriteHook"
    );
}
foreach (($optionHookFixture['rewrite_generation']['soft_flush_excludes'] ?? []) as $hardHook) {
    duo_check(
        !isset($declaredRewriteHooks[$hardHook]),
        "the soft native action does not claim unreachable hard-flush hook $hardHook"
    );
}
$cssOptionHookTopology = [];
foreach (($optionHookFixture['shared_paths'] ?? []) as $pathHooks) {
    foreach ($pathHooks as $pathHook) {
        $cssOptionHookTopology[$pathHook] = $pathHook;
    }
}
$cssOptionHookTopology = array_values($cssOptionHookTopology);
foreach ($cssOptionHookTopology as $cssOptionHook) {
    duo_check(
        in_array($cssOptionHook, $GLOBALS['tec_readiness_has_filter_calls'], true),
        "the provider preflights exact WordPress CSS option hook $cssOptionHook"
    );
}
foreach (($optionHookFixture['proved_absent'] ?? []) as $absentOptionHook) {
    duo_check(
        !in_array($absentOptionHook, $GLOBALS['tec_readiness_has_filter_calls'], true),
        "the exact pinned-core fixture does not invent unreachable CSS option hook $absentOptionHook"
    );
}
$hookRefusalControllerCalls = $GLOBALS['tec_readiness_color_controller_calls'];
$hookRefusalCacheBusts = $GLOBALS['tec_readiness_cache_busts'];
foreach (['existing update row' => true, 'absent add row' => false] as $optionBranch => $optionExists) {
    if ($optionExists) {
        tec_readiness_seed_color_options();
    } else {
        $colorDb->seedTable($colorDb->options, []);
    }
    foreach ($cssOptionHookTopology as $cssOptionHook) {
        $GLOBALS['tec_readiness_filters'] = [$cssOptionHook];
        duo_check_throws(
            static fn() => $colorProvider->invoke('regenerate_css', []),
            RuntimeException::class,
            "an active CSS option hook $cssOptionHook refuses on the $optionBranch before side effects",
            'does not admit filter'
        );
    }
}
$GLOBALS['tec_readiness_filters'] = [];
tec_readiness_seed_color_options();
duo_check_same(
    $hookRefusalControllerCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'every CSS option hook topology refusal happens before native controller execution'
);
duo_check_same(
    $hookRefusalCacheBusts,
    $GLOBALS['tec_readiness_cache_busts'],
    'every CSS option hook topology refusal happens before dropdown-cache mutation'
);
duo_check_same(0, $firstColorReceipt['after']['css_selector_mismatch_count'] ?? null, 'readback rejects missing or orphan native selectors');
duo_check_same(0, $firstColorReceipt['after']['css_value_mismatch_count'] ?? null, 'readback carries every native selector color value');
duo_check_same(
    false,
    $firstColorReceipt['after']['dropdown_cache_present'] ?? null,
    'the immediate native postcondition is an absent dropdown cache after the reviewed bust'
);
duo_check_same(
    1,
    $firstColorReceipt['after']['dropdown_expected_count'] ?? null,
    'the durable receipt binds the canonical future dropdown projection without populating it'
);
duo_check_same(
    0,
    $GLOBALS['tec_readiness_dropdown_get_calls'],
    'verification never calls the cache-populating native dropdown getter'
);
duo_check_same(
    0,
    $GLOBALS['tec_readiness_cache_sets'],
    'verification never publishes a dropdown cache value of its own'
);
duo_check_same(1, $firstColorReceipt['after']['colored_category_count'] ?? null, 'the receipt is bounded to counts and digests, not authored payload');
duo_check(
    !str_contains(json_encode($firstColorReceipt, JSON_UNESCAPED_SLASHES), 'readiness')
        && !str_contains(json_encode($firstColorReceipt, JSON_UNESCAPED_SLASHES), '#123'),
    'the provider receipt contains no authored slug or color payload'
);
duo_check(
    ($firstColorReceipt['before']['css_sha256'] ?? null) !== ($firstColorReceipt['after']['css_sha256'] ?? null),
    'a hostile stale generated option visibly converges in the receipt'
);
$secondColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(
    $firstColorReceipt['after']['css_sha256'] ?? null,
    $secondColorReceipt['after']['css_sha256'] ?? null,
    'a retry is idempotent at the generated CSS projection'
);

tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#000000}');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_color_controller_mode'] = 'throw_after_css';
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a failure after the native CSS option write surfaces without certifying the stale dropdown cache',
    'injected native Category Colors failure after CSS write'
);
duo_check_same(
    $GLOBALS['tec_readiness_generated_css'],
    get_option('tec_events_category_color_css'),
    'the first native failure witness contains the completed CSS write'
);
duo_check_same(
    '#000000',
    $GLOBALS['tec_readiness_dropdown_rows'][0]['primary'] ?? null,
    'the first native failure witness retains the stale pre-bust dropdown cache'
);
duo_check_same(
    2,
    $GLOBALS['tec_readiness_cache_busts'],
    'a failure before the native dropdown-cache bust does not claim that effect'
);
$crashCacheWitness = hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows']));
$crashDropdownGets = $GLOBALS['tec_readiness_dropdown_get_calls'];
$crashCacheSets = $GLOBALS['tec_readiness_cache_sets'];
$crashGeneratorCalls = $GLOBALS['tec_readiness_color_controller_calls'];
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped(
        'regenerate_css',
        [],
        ['format' => 'duo-provider-operation/v1', 'id' => 'tec-crash-between-services']
    ),
    RuntimeException::class,
    'recovery refuses a stale populated dropdown cache after a crash between the two native services',
    'dropdown cache readback is stale or malformed'
);
duo_check_same(
    $crashCacheWitness,
    hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows'])),
    'crash recovery observes the stale cache without mutating it'
);
duo_check_same(
    $crashDropdownGets,
    $GLOBALS['tec_readiness_dropdown_get_calls'],
    'crash recovery never invokes the cache-populating dropdown getter'
);
duo_check_same(
    $crashCacheSets,
    $GLOBALS['tec_readiness_cache_sets'],
    'crash recovery never publishes cache bytes'
);
duo_check_same(
    $crashGeneratorCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'crash recovery never replays the native CSS generator'
);
$afterCssRetry = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $afterCssRetry['verified'] ?? null, 'same-process retry after the partial CSS write converges');
duo_check_same(
    false,
    $GLOBALS['tec_readiness_dropdown_rows'],
    'same-process retry after the partial CSS write completes the native dropdown-cache bust'
);

tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#000000}');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_color_controller_mode'] = 'throw_after_cache_bust';
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a failure after both native writes surfaces before Duo can issue a verified receipt',
    'injected native Category Colors failure after cache bust'
);
duo_check_same(
    $GLOBALS['tec_readiness_generated_css'],
    get_option('tec_events_category_color_css'),
    'the second native failure witness contains the completed CSS write'
);
duo_check_same(
    false,
    $GLOBALS['tec_readiness_dropdown_rows'],
    'the second native failure witness contains the completed dropdown-cache bust'
);
$afterCacheBustRetry = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $afterCacheBustRetry['verified'] ?? null, 'same-process retry after both native writes remains idempotent');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'readiness',
    'name' => 'Readiness',
    'priority' => 99,
    'primary' => '#000000',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_color_controller_mode'] = 'cache_delete_noop';
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a native cache delete no-op cannot earn a verified provider receipt',
    'cache remains populated after the native bust'
);
duo_check_same(
    '#000000',
    $GLOBALS['tec_readiness_dropdown_rows'][0]['primary'] ?? null,
    'the cache-delete failure retains one exact stale-cache witness'
);
$afterCacheDeleteRetry = $colorProvider->invoke('regenerate_css', []);
duo_check_same(
    false,
    $afterCacheDeleteRetry['after']['dropdown_cache_present'] ?? null,
    'same-process retry after cache-delete failure proves the native key absent'
);
$cacheBustsAfterPartialRetries = $GLOBALS['tec_readiness_cache_busts'];
duo_check_same(7, $cacheBustsAfterPartialRetries, 'every attempted native cache bust is counted across all partial failures and retries');
duo_check_same(8, $GLOBALS['tec_readiness_color_controller_calls'], 'all partial attempts and retries crossed the exact native generator boundary');

$operation = ['format' => 'duo-provider-operation/v1', 'id' => 'tec-offline-reconcile'];
$reconciled = $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
duo_check_same($operation, $reconciled['operation'] ?? null, 'reconciliation binds its caller-supplied operation envelope');
duo_check_same(true, $reconciled['verified'] ?? null, 'reconciliation verifies without replaying the native write');
$settingsOptionRow = static fn(int $id, string $name, string $value): array => [
    'option_id' => $id,
    'option_name' => $name,
    'option_value' => $value,
    'autoload' => 'on',
];
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => false,
    ])),
]);
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'the exact bounded serialized main settings row agrees with native show-hidden behavior'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([])),
    $settingsOptionRow(3, 'tribe_events_calendar_options', serialize([])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a duplicate physical main settings identity refuses before native category traversal',
    'identity is duplicated'
);
tec_readiness_seed_color_options();
$colorDb->returnNextGetResultsAs([[
    'option_id' => '2',
    'option_name' => 'TRIBE_EVENTS_CALENDAR_OPTIONS',
    'value_bytes' => '6',
    'value_prefix' => 'a:0:{}',
]]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a collation-equivalent but byte-aliased main settings identity refuses',
    'malformed or aliased'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(
        2,
        'tribe_events_calendar_options',
        "not-serialized-AKIAABCDEFGHIJKLMNOP\0" . str_repeat('x', 256)
    ),
]);
$malformedSettingsRefusal = '';
try {
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
} catch (RuntimeException $e) {
    $malformedSettingsRefusal = $e->getMessage();
}
duo_check(
    str_contains($malformedSettingsRefusal, 'must be canonical PHP-serialized plain data')
        && !str_contains($malformedSettingsRefusal, 'AKIA')
        && strlen($malformedSettingsRefusal) < 300,
    'malformed main settings bytes refuse with one bounded secret-safe diagnostic'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', str_repeat('x', 1048577)),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized main settings blob refuses after only a bounded prefix transfer',
    'exceeds the bounded byte frontier'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => '1',
    ])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a non-boolean stored show-hidden setting refuses before native truthiness can drift',
    'not a native boolean'
);
tec_readiness_seed_color_options([
    $settingsOptionRow(2, 'tribe_events_calendar_options', serialize([
        'category-color-show-hidden-categories' => true,
    ])),
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a stale native settings cache refuses even when the bounded raw row is valid',
    'disagrees with bounded raw storage'
);
tec_readiness_seed_color_options();
$colorDb->returnNextGetResultsAs(false);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a false main settings read from a non-core compatible driver refuses before native traversal',
    'main settings option value is unreadable'
);
$cacheBustsBeforeFilterRefusal = $GLOBALS['tec_readiness_cache_busts'];
$GLOBALS['tec_readiness_filters'] = ['tec_events_category_color_generator_final_css'];
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'a native final-CSS output filter refuses before controller or dropdown-cache mutation',
    'does not admit filter'
);
duo_check_same(
    $cacheBustsBeforeFilterRefusal,
    $GLOBALS['tec_readiness_cache_busts'],
    'output-filter refusal happens before every native Category Colors side effect'
);
$GLOBALS['tec_readiness_filters'] = [];
tec_readiness_seed_color_options();
tec_readiness_set_css('.tribe_events_cat-readiness{--tec-color-category-primary:#123abc}');
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses stale partial native CSS rather than certifying an ambiguous effect',
    'recovery_required'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '';
tec_readiness_sync_color_db();
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a stale recognized property removed from live category metadata',
    'value mismatch'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-secondary'] = '#fedcba';
tec_readiness_sync_color_db();
tec_readiness_set_css(str_replace(
    '--tec-color-category-secondary:',
    '--tec-color-category-primary:#123abc;--tec-color-category-secondary:',
    $GLOBALS['tec_readiness_generated_css']
));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a duplicate recognized property even when both values are current',
    'value mismatch'
);
tec_readiness_set_css(str_replace(
    '}',
    ';background-color:transparent}',
    $GLOBALS['tec_readiness_generated_css']
));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a filter-injected declaration outside the exact native CSS bytes',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css('/* injected */' . $GLOBALS['tec_readiness_generated_css']);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses a filter-injected CSS comment before the native selector stream',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.foreign-selector{display:block}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an arbitrary extra selector outside the native output grammar',
    'exact-byte grammar mismatch'
);
tec_readiness_set_css(
    '.tribe_events_cat-readiness{'
        . '--tec-color-category-secondary:#fedcba;'
        . '--tec-color-category-primary:#123abc;'
        . '--tec-color-category-text:#ffffff}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses reordered properties that the exact native generator cannot emit',
    'value mismatch'
);
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.tribe_events_cat-orphan{--tec-color-category-primary:#111111}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses an orphan generated CSS selector',
    'selector-set mismatch'
);
tec_readiness_set_css(str_repeat('x', 8388609));
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized CSS option refuses after a bounded single-statement prefix read',
    'CSS option exceeds the bounded byte frontier'
);
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
$GLOBALS['tec_readiness_term_meta'][72] = [
    'tec-events-cat-colors-primary' => '#ABCDEF',
    'tec-events-cat-colors-priority' => '5',
    'tec-events-cat-colors-hidden' => '0',
];
$GLOBALS['tec_readiness_dropdown_rows'] = [
    $GLOBALS['tec_readiness_generated_dropdown_rows'][0],
    [
        'slug' => 'plain-category',
        'name' => 'Plain Category',
        'priority' => 5,
        'primary' => '#ABCDEF',
        'hidden' => false,
    ],
];
tec_readiness_sync_color_db();
tec_readiness_set_css(
    $GLOBALS['tec_readiness_generated_css']
        . '.tribe_events_cat-plain-category{--tec-color-category-primary:#abcdef}'
);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'reconciliation refuses selectors emitted against native ascending priority order',
    'priority-order mismatch'
);
tec_readiness_set_css(
    '.tribe_events_cat-plain-category{--tec-color-category-primary:#abcdef}'
        . $GLOBALS['tec_readiness_generated_css']
);
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'multiple selectors verify only in native priority and per-property byte order'
);
$GLOBALS['tec_readiness_term_meta'][72] = [];
tec_readiness_sync_color_db();
tec_readiness_set_css($GLOBALS['tec_readiness_generated_css']);
$GLOBALS['tec_readiness_dropdown_rows'] = false;
$nativeDropdownGetsBeforePopulate = $GLOBALS['tec_readiness_dropdown_get_calls'];
$nativeCacheSetsBeforePopulate = $GLOBALS['tec_readiness_cache_sets'];
$nativeRepopulatedCache = $GLOBALS['tec_readiness_category_color_dropdown']->get_dropdown_categories();
duo_check_same(
    $GLOBALS['tec_readiness_generated_dropdown_rows'],
    $nativeRepopulatedCache,
    'the exact fake native getter demonstrates that a cache miss deliberately repopulates the dropdown entry'
);
duo_check_same(
    $nativeDropdownGetsBeforePopulate + 1,
    $GLOBALS['tec_readiness_dropdown_get_calls'],
    'the native cache-miss fixture crosses the dropdown getter exactly once'
);
duo_check_same(
    $nativeCacheSetsBeforePopulate + 1,
    $GLOBALS['tec_readiness_cache_sets'],
    'the native cache-miss fixture publishes exactly one dropdown entry before reconciliation'
);
$reconcileCacheReads = $GLOBALS['tec_readiness_cache_reads'];
$reconcileDropdownGets = $GLOBALS['tec_readiness_dropdown_get_calls'];
$reconcileCacheSets = $GLOBALS['tec_readiness_cache_sets'];
$reconcileGeneratorCalls = $GLOBALS['tec_readiness_color_controller_calls'];
foreach ([
    'native repopulated cache' => $nativeRepopulatedCache,
    'natural expiry' => false,
] as $cacheStateLabel => $cacheState) {
    $GLOBALS['tec_readiness_dropdown_rows'] = $cacheState;
    $cacheStateHash = hash('sha256', serialize($cacheState));
    $firstReconcile = $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
    $secondReconcile = $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
    duo_check_same(
        $reconciled['after'] ?? null,
        $firstReconcile['after'] ?? null,
        "read-only reconciliation accepts $cacheStateLabel while binding durable CSS and dropdown semantics"
    );
    duo_check_same(
        $firstReconcile['after'] ?? null,
        $secondReconcile['after'] ?? null,
        "repeated reconciliation remains idempotent across $cacheStateLabel"
    );
    duo_check_same(
        $cacheStateHash,
        hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows'])),
        "reconciliation does not mutate $cacheStateLabel"
    );
}
foreach ([
    'stale empty populated cache' => [],
    'stale populated cache' => [[
        'slug' => 'readiness',
        'name' => 'Readiness',
        'priority' => 99,
        'primary' => '#000000',
        'hidden' => false,
    ]],
    'orphan populated cache' => [[
        'slug' => 'orphan',
        'name' => 'Orphan',
        'priority' => 9,
        'primary' => '#111111',
        'hidden' => false,
    ]],
    'malformed populated cache' => 'hostile-cache-AKIAABCDEFGHIJKLMNOP',
    'oversized populated cache' => array_fill(
        0,
        10001,
        $GLOBALS['tec_readiness_generated_dropdown_rows'][0]
    ),
] as $cacheStateLabel => $cacheState) {
    $GLOBALS['tec_readiness_dropdown_rows'] = $cacheState;
    $cacheStateHash = hash('sha256', serialize($cacheState));
    duo_check_throws(
        static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
        RuntimeException::class,
        "read-only reconciliation refuses $cacheStateLabel rather than certifying an incomplete cache effect",
        $cacheStateLabel === 'oversized populated cache'
            ? 'exceeds the bounded row frontier'
            : 'dropdown cache readback is stale or malformed'
    );
    duo_check_same(
        $cacheStateHash,
        hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows'])),
        "cache refusal does not mutate $cacheStateLabel"
    );
}
$GLOBALS['tec_readiness_dropdown_rows'] = $nativeRepopulatedCache;
$cacheReadFailureWitness = hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows']));
$GLOBALS['tec_readiness_cache_read_mode'] = 'throw';
$cacheReadFailure = '';
try {
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
} catch (RuntimeException $e) {
    $cacheReadFailure = $e->getMessage();
}
duo_check(
    str_contains($cacheReadFailure, 'dropdown cache is unreadable')
        && !str_contains($cacheReadFailure, 'AKIA')
        && strlen($cacheReadFailure) < 300,
    'a native cache read failure refuses with a bounded secret-safe recovery diagnostic'
);
duo_check_same(
    $cacheReadFailureWitness,
    hash('sha256', serialize($GLOBALS['tec_readiness_dropdown_rows'])),
    'a native cache read failure cannot mutate the previously populated cache'
);
duo_check(
    $GLOBALS['tec_readiness_cache_reads'] > $reconcileCacheReads,
    'reconciliation uses only the exact read-only Tribe cache observation seam'
);
duo_check_same(
    $reconcileDropdownGets,
    $GLOBALS['tec_readiness_dropdown_get_calls'],
    'reconciliation never calls the native cache-populating dropdown provider'
);
duo_check_same(
    $reconcileCacheSets,
    $GLOBALS['tec_readiness_cache_sets'],
    'reconciliation never publishes a new dropdown cache value'
);
duo_check_same(
    $reconcileGeneratorCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'reconciliation never calls the native CSS generator'
);
$GLOBALS['tec_readiness_dropdown_rows'] = array_fill(
    0,
    10001,
    $GLOBALS['tec_readiness_generated_dropdown_rows'][0]
);
$cacheFrontierGeneratorCalls = $GLOBALS['tec_readiness_color_controller_calls'];
$cacheFrontierBusts = $GLOBALS['tec_readiness_cache_busts'];
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    'an oversized preexisting dropdown cache refuses at its hard row frontier before native mutation',
    'exceeds the bounded row frontier'
);
duo_check_same(
    $cacheFrontierGeneratorCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'the oversized cache refusal happens before the native generator'
);
duo_check_same(
    $cacheFrontierBusts,
    $GLOBALS['tec_readiness_cache_busts'],
    'the oversized cache refusal happens before the native cache bust'
);
$GLOBALS['tec_readiness_dropdown_rows'] = false;
duo_check_same(
    $cacheBustsAfterPartialRetries,
    $GLOBALS['tec_readiness_cache_busts'],
    'reconciliation probes never replay the native mutation'
);
duo_check_throws(
    static fn() => $colorProvider->invoke('invented_capability', []),
    RuntimeException::class,
    'the provider capability surface is closed',
    'does not implement capability'
);

$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-primary'] = str_repeat('a', 1025);
tec_readiness_sync_color_db();
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'an oversized relevant term-meta value refuses after a bounded prefix witness',
    'malformed or oversized'
);
$GLOBALS['tec_readiness_term_meta'][71]['tec-events-cat-colors-primary'] = '#123ABC';
tec_readiness_sync_color_db();
$colorDb->insert($colorDb->termmeta, [
    'term_id' => 71,
    'meta_key' => 'tec-events-cat-colors-primary',
    'meta_value' => '#123ABC',
]);
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'duplicate physical category-color metadata refuses before last-row-wins native behavior',
    'duplicated or ownerless'
);
tec_readiness_sync_color_db();
$colorDb->failNextQuery(
    'injected taxonomy read failure AKIAABCDEFGHIJKLMNOP',
    'FROM wp_term_taxonomy'
);
$categoryReadRefusal = '';
try {
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation);
} catch (RuntimeException $e) {
    $categoryReadRefusal = $e->getMessage();
}
duo_check(
    str_contains($categoryReadRefusal, 'taxonomy identities is unreadable')
        && !str_contains($categoryReadRefusal, 'AKIA')
        && strlen($categoryReadRefusal) < 300,
    'a category identity driver failure refuses with a bounded redacted diagnostic'
);
$taxonomyFlood = [];
for ($termId = 1; $termId <= 10001; ++$termId) {
    $taxonomyFlood[] = [
        'term_taxonomy_id' => $termId,
        'term_id' => $termId,
        'taxonomy' => 'tribe_events_cat',
    ];
}
$colorDb->seedTable($colorDb->term_taxonomy, $taxonomyFlood);
$colorDb->seedTable($colorDb->terms, []);
$colorDb->seedTable($colorDb->termmeta, []);
tec_readiness_seed_color_options();
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a hostile category taxonomy refuses before native get_terms or metadata traversal',
    'exceeds the bounded category frontier'
);
unset($taxonomyFlood);
tec_readiness_sync_color_db();
$generatorFrontierTerms = $GLOBALS['tec_readiness_terms'];
$generatorFrontierMeta = $GLOBALS['tec_readiness_term_meta'];
$generatorFrontierCss = $GLOBALS['tec_readiness_generated_css'];
$generatorFrontierDropdown = $GLOBALS['tec_readiness_generated_dropdown_rows'];
$GLOBALS['tec_readiness_terms'] = [];
$GLOBALS['tec_readiness_term_meta'] = [];
for ($categoryOffset = 0; $categoryOffset < 101; ++$categoryOffset) {
    $termId = 1000 + $categoryOffset;
    $GLOBALS['tec_readiness_terms'][] = (object) [
        'term_id' => $termId,
        'slug' => 'frontier-' . $categoryOffset,
        'name' => 'Frontier ' . $categoryOffset,
    ];
    $GLOBALS['tec_readiness_term_meta'][$termId] = [
        'tec-events-cat-colors-primary' => '#123ABC',
        'tec-events-cat-colors-secondary' => '#fedcba',
        'tec-events-cat-colors-text' => '#ffffff',
        'tec-events-cat-colors-priority' => (string) $categoryOffset,
        'tec-events-cat-colors-hidden' => '0',
    ];
}
$GLOBALS['tec_readiness_dropdown_rows'] = false;
tec_readiness_sync_color_db();
$generatorFrontierCalls = $GLOBALS['tec_readiness_color_controller_calls'];
$generatorFrontierBusts = $GLOBALS['tec_readiness_cache_busts'];
duo_check_throws(
    static fn() => $colorProvider->invoke('regenerate_css', []),
    RuntimeException::class,
    '501 relevant native category-meta rows refuse before the unordered Generator enters a second populated page',
    'safe one-page metadata frontier'
);
duo_check_same(
    $generatorFrontierCalls,
    $GLOBALS['tec_readiness_color_controller_calls'],
    'the unsafe second-page refusal happens before native CSS generation'
);
duo_check_same(
    $generatorFrontierBusts,
    $GLOBALS['tec_readiness_cache_busts'],
    'the unsafe second-page refusal happens before native cache mutation'
);
$GLOBALS['tec_readiness_terms'] = $generatorFrontierTerms;
$GLOBALS['tec_readiness_term_meta'] = $generatorFrontierMeta;
$GLOBALS['tec_readiness_generated_css'] = $generatorFrontierCss;
$GLOBALS['tec_readiness_generated_dropdown_rows'] = $generatorFrontierDropdown;
$GLOBALS['tec_readiness_dropdown_rows'] = false;
tec_readiness_sync_color_db();
tec_readiness_set_css($generatorFrontierCss);
$taxonomyReadCount = 0;
$colorDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$taxonomyReadCount): null {
    if ($method !== 'get_results' || !str_contains($sql, 'FROM wp_term_taxonomy')) {
        return null;
    }
    ++$taxonomyReadCount;
    if ($taxonomyReadCount !== 2) {
        return null;
    }
    $rows = $db->rows($db->termmeta);
    foreach ($rows as &$row) {
        if (($row['term_id'] ?? null) === 71
            && ($row['meta_key'] ?? null) === 'tec-events-cat-colors-primary') {
            $row['meta_value'] = '#654321';
        }
    }
    unset($row);
    $db->seedTable($db->termmeta, $rows);
    return null;
});
duo_check_throws(
    static fn() => $colorProvider->reconcile_scoped('regenerate_css', [], $operation),
    RuntimeException::class,
    'a concurrent category-color change between fresh witnesses refuses instead of blessing mixed generations',
    'changed during verification'
);
$colorDb->onQuery(null);
tec_readiness_sync_color_db();

$singleCategoryCss = $GLOBALS['tec_readiness_generated_css'];
$singleCategoryDropdown = $GLOBALS['tec_readiness_generated_dropdown_rows'];
$GLOBALS['tec_readiness_term_meta'][72] = [
    'tec-events-cat-colors-primary' => '#ABCDEF',
    'tec-events-cat-colors-priority' => '17',
    'tec-events-cat-colors-hidden' => '0',
];
$plainCategoryCss = '.tribe_events_cat-plain-category{--tec-color-category-primary:#abcdef}';
$plainCategoryDropdown = [
    'slug' => 'plain-category',
    'name' => 'Plain Category',
    'priority' => 17,
    'primary' => '#ABCDEF',
    'hidden' => false,
];
$GLOBALS['tec_readiness_generated_css'] = $singleCategoryCss . $plainCategoryCss;
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [
    $singleCategoryDropdown[0],
    $plainCategoryDropdown,
];
tec_readiness_sync_color_db();
$equalPriorityOperation = ['format' => 'duo-provider-operation/v1', 'id' => 'tec-equal-priority'];
$equalPriorityFirst = $colorProvider->invoke_scoped(
    'regenerate_css',
    [],
    $equalPriorityOperation
);
$equalPriorityFirstRaw = get_option('tec_events_category_color_css');
duo_check_same(
    true,
    $equalPriorityFirst['verified'] ?? null,
    'the first native equal-priority generation produces a verified semantic scoped receipt'
);
duo_check(
    !array_key_exists('css_sha256', $equalPriorityFirst['before'] ?? [])
        && !array_key_exists('css_sha256', $equalPriorityFirst['after'] ?? []),
    'scoped recovery evidence excludes order-unstable raw CSS bytes while retaining canonical projections'
);
$GLOBALS['tec_readiness_generated_css'] = $plainCategoryCss . $singleCategoryCss;
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [
    $plainCategoryDropdown,
    $singleCategoryDropdown[0],
];
$equalPrioritySecond = $colorProvider->invoke_scoped(
    'regenerate_css',
    [],
    $equalPriorityOperation
);
$equalPrioritySecondRaw = get_option('tec_events_category_color_css');
duo_check(
    is_string($equalPriorityFirstRaw)
        && is_string($equalPrioritySecondRaw)
        && !hash_equals($equalPriorityFirstRaw, $equalPrioritySecondRaw),
    'the fake native boundary exercises both byte permutations inside one equal-priority bucket'
);
duo_check_same(
    $equalPriorityFirst['after'] ?? null,
    $equalPrioritySecond['after'] ?? null,
    'equal-priority native byte permutations retain one exact semantic scoped postcondition'
);
duo_check_same(
    $equalPrioritySecond['after'] ?? null,
    $colorProvider->reconcile_scoped(
        'regenerate_css',
        [],
        $equalPriorityOperation
    )['after'] ?? null,
    'read-only recovery recognizes the second equal-priority native permutation without replaying it'
);
$cacheBustsAfterEqualPriority = $GLOBALS['tec_readiness_cache_busts'];
duo_check_same(
    $cacheBustsAfterPartialRetries + 2,
    $cacheBustsAfterEqualPriority,
    'only the two explicit equal-priority invocations crossed the native mutation boundary'
);
$GLOBALS['tec_readiness_term_meta'][72] = [];
$GLOBALS['tec_readiness_generated_css'] = $singleCategoryCss;
$GLOBALS['tec_readiness_generated_dropdown_rows'] = $singleCategoryDropdown;
$GLOBALS['tec_readiness_dropdown_rows'] = $singleCategoryDropdown;
tec_readiness_sync_color_db();
tec_readiness_set_css($singleCategoryCss);

$GLOBALS['tec_readiness_terms'] = [];
$GLOBALS['tec_readiness_term_meta'] = [];
tec_readiness_set_css('.tribe_events_cat-orphan{--tec-color-category-primary:#111111}');
$GLOBALS['tec_readiness_dropdown_rows'] = [[
    'slug' => 'orphan',
    'name' => 'Orphan',
    'priority' => 9,
    'primary' => '#111111',
    'hidden' => false,
]];
$GLOBALS['tec_readiness_generated_css'] = '';
$GLOBALS['tec_readiness_generated_dropdown_rows'] = [];
tec_readiness_sync_color_db();
$emptyColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $emptyColorReceipt['verified'] ?? null, 'zero event categories converge through the native empty CSS/dropdown path');
duo_check_same(0, $emptyColorReceipt['after']['colored_category_count'] ?? null, 'zero-category receipt stays bounded at zero colored categories');
duo_check_same(0, $emptyColorReceipt['after']['css_selector_count'] ?? null, 'zero categories store TEC native empty CSS without a synthetic selector');
duo_check_same(false, $emptyColorReceipt['after']['dropdown_cache_present'] ?? null, 'zero categories finish with the native dropdown cache absent');
duo_check_same(
    true,
    $colorProvider->reconcile_scoped('regenerate_css', [], $operation)['verified'] ?? null,
    'zero-category reconciliation verifies the native empty state without mutation'
);

$GLOBALS['tec_readiness_terms'] = [(object) ['term_id' => 72, 'slug' => 'plain-category', 'name' => 'Plain Category']];
$GLOBALS['tec_readiness_term_meta'] = [72 => []];
tec_readiness_sync_color_db();
$plainColorReceipt = $colorProvider->invoke('regenerate_css', []);
duo_check_same(true, $plainColorReceipt['verified'] ?? null, 'categories with no color metadata converge through native empty generated state');
duo_check_same(0, $plainColorReceipt['after']['colored_category_count'] ?? null, 'an uncolored native category is not invented as a colored selector');
duo_check_same(false, $plainColorReceipt['after']['dropdown_cache_present'] ?? null, 'an uncolored native category finishes with the dropdown cache absent');
duo_check_same(
    $cacheBustsAfterEqualPriority + 2,
    $GLOBALS['tec_readiness_cache_busts'],
    'only explicit provider invocations replayed native cache mutation'
);

/** @return ?array{state:string,autoload:string,value:array} */
function tec_readiness_capture_customizer_record(
    Policy $policy,
    bool $canonicalPresent,
    array $canonical,
    bool $legacyPresent,
    array $legacy,
    string $canonicalAutoload = 'auto-on'
): ?array {
    $savedDb = $GLOBALS['wpdb'] ?? null;
    $db = FakeWpdb::install();
    $rows = [];
    $rawOptionSnapshot = [];
    $optionId = 1;
    if ($canonicalPresent) {
        $row = [
            'option_id' => $optionId++,
            'option_name' => 'tribe_customizer',
            'option_value' => serialize($canonical),
            'autoload' => $canonicalAutoload,
        ];
        $rows[] = $row;
        $rawOptionSnapshot[$row['option_name']] = $row['option_value'];
    }
    if ($legacyPresent) {
        $row = [
            'option_id' => $optionId,
            'option_name' => 'tribe_events_pro_customizer',
            'option_value' => serialize($legacy),
            'autoload' => 'off',
        ];
        $rows[] = $row;
        $rawOptionSnapshot[$row['option_name']] = $row['option_value'];
    }
    $db->seedTable($db->options, $rows);
    $transactionStarted = false;
    try {
        \Duo\Db::start_read_only_consistent_snapshot('TEC Customizer capture fixture');
        $transactionStarted = true;
        $capture = new \Duo\OptionsCapture(
            $policy,
            new Tokens('https://source.example', 'https://source.example/uploads'),
            static function (): void {},
            static fn(): ?string => null,
            static fn(): bool => false
        );
        $rule = $policy->sub_keyed_options()['tribe_customizer'] ?? null;
        duo_check(is_array($rule), 'the product capture fixture resolves the declared Customizer mixed-option rule');
        $details = $policy->option_rule_details('tribe_customizer');
        $source = is_string($details['source'] ?? null) ? $details['source'] : null;
        $liveCanonicalNames = [];
        $out = [];
        $captureSubKeys = new ReflectionMethod($capture, 'capture_option_sub_keys');
        // The shared FakeWpdb deliberately has no SUM/COALESCE aggregate
        // grammar. Invoke the exact private product method with the complete
        // same-snapshot raw map rather than teaching this adapter suite a
        // bespoke SQL answer; OptionsCapture's namespace reader has its own
        // bounded-reader regressions.
        $captureSubKeys->invokeArgs($capture, [
            'tribe_customizer',
            $rule,
            $source,
            $rawOptionSnapshot,
            false,
            &$liveCanonicalNames,
            &$out,
        ]);
        return $out['tribe_customizer'] ?? null;
    } finally {
        if ($transactionStarted) {
            \Duo\Db::rollback('TEC Customizer capture fixture rollback');
        }
        $GLOBALS['wpdb'] = $savedDb;
    }
}

/**
 * @return array{
 *   row:?array{option_id:mixed,option_name:mixed,option_value:mixed,autoload:mixed},
 *   legacy_row:?array{option_id:mixed,option_name:mixed,option_value:mixed,autoload:mixed},
 *   failure:?Throwable,warnings:list<string>,settings_cache_present:bool,settings_cache:mixed,
 *   runtime_rows:array<string,array>,purge_flag_present:bool,purge_flag:mixed,tribe_var_writes:list<array>
 * }
 */
function tec_readiness_materialize_mixed_option(
    Policy $policy,
    string $name,
    array $desired,
    ?array $target,
    string $desiredAutoload = 'auto-on',
    string $targetAutoload = 'off',
    bool $settingsCachePresent = false,
    mixed $settingsCache = null,
    string $cacheDeleteMode = '',
    ?array $targetLegacy = null,
    string $targetLegacyAutoload = 'off',
    ?Closure $configureDb = null,
    bool $desiredPresent = true,
    array $targetRuntimeRows = [],
    bool $purgeFlagPresent = false,
    mixed $purgeFlag = null,
    array $timePlan = [],
    ?array $tribeVarFailure = null
): array {
    $savedDb = $GLOBALS['wpdb'] ?? null;
    $savedTribeVars = $GLOBALS['tec_readiness_tribe_vars'] ?? [];
    $savedWpCache = $GLOBALS['tec_readiness_wp_cache'] ?? [];
    $savedCacheDeletesPresent = array_key_exists('tec_readiness_wp_cache_deletes', $GLOBALS);
    $savedCacheDeletes = $GLOBALS['tec_readiness_wp_cache_deletes'] ?? 0;
    $savedCacheMode = $GLOBALS['tec_readiness_wp_cache_delete_mode'] ?? '';
    $savedTimePlan = $GLOBALS['tec_readiness_interpreter_time_plan'] ?? [];
    $savedVarFailure = $GLOBALS['tec_readiness_tribe_var_failure'] ?? null;
    $savedVarWrites = $GLOBALS['tec_readiness_tribe_var_writes'] ?? [];
    $db = new LockingFakeWpdb(new FakeWpdb());
    $GLOBALS['wpdb'] = $db;
    $db->addInnoDbTable($db->options)
        ->addIndex($db->options, 'option_name', 'option_name', true);
    $targetRows = [];
    $optionId = 1;
    if ($target !== null) {
        $targetRows[] = [
            'option_id' => $optionId++,
            'option_name' => $name,
            'option_value' => serialize($target),
            'autoload' => $targetAutoload,
        ];
    }
    if ($targetLegacy !== null) {
        $targetRows[] = [
            'option_id' => $optionId,
            'option_name' => 'tribe_events_pro_customizer',
            'option_value' => serialize($targetLegacy),
            'autoload' => $targetLegacyAutoload,
        ];
    }
    foreach ($targetRuntimeRows as $runtimeName => $runtimeRow) {
        $targetRows[] = [
            'option_id' => ++$optionId,
            'option_name' => $runtimeName,
            'option_value' => (string) ($runtimeRow['option_value'] ?? ''),
            'autoload' => (string) ($runtimeRow['autoload'] ?? ''),
        ];
    }
    $db->seedTable($db->options, $targetRows);
    if ($configureDb !== null) {
        $configureDb($db);
    }
    if ($settingsCachePresent) {
        tribe_set_var('Tribe__Settings_Manager:option_cache', $settingsCache);
    } else {
        tribe_unset_var('Tribe__Settings_Manager:option_cache');
    }
    if ($purgeFlagPresent) {
        tribe_set_var('should_delete_expired_transients', $purgeFlag);
    } else {
        tribe_unset_var('should_delete_expired_transients');
    }
    $GLOBALS['tec_readiness_interpreter_time_plan'] = $timePlan;
    $GLOBALS['tec_readiness_tribe_var_failure'] = $tribeVarFailure;
    $GLOBALS['tec_readiness_tribe_var_writes'] = [];
    $GLOBALS['tec_readiness_wp_cache_delete_mode'] = $cacheDeleteMode;
    $GLOBALS['tec_readiness_wp_cache_deletes'] = $savedCacheDeletes;
    $tokens = new Tokens('https://target.example', 'https://target.example/uploads');
    $fieldMaterializer = new \Duo\ApplyFieldMaterializer($policy, $tokens);
    $optionsMaterializer = new \Duo\OptionsMaterializer($policy, $tokens, $fieldMaterializer);
    $transactionStarted = false;
    $participantsStarted = false;
    $cacheStarted = false;
    $failure = null;
    $warnings = [];
    try {
        \Duo\Db::start_repeatable_read('TEC mixed-option fixture apply');
        $transactionStarted = true;
        $fieldMaterializer->begin_authored_transaction();
        $optionsMaterializer->begin_authored_transaction();
        $participantsStarted = true;
        \Duo\CacheInvalidationTransaction::begin();
        $cacheStarted = true;
        $desiredRecord = $desiredPresent
            ? \Duo\OptionState::present($desired, $desiredAutoload)
            : \Duo\OptionState::absent();
        $optionsMaterializer->apply_options(
            \Duo\OptionState::document([$name => $desiredRecord]),
            false,
            $warnings
        );
        \Duo\Db::commit('TEC mixed-option fixture commit');
        $transactionStarted = false;
        $optionsMaterializer->commit_authored_transaction();
        \Duo\CacheInvalidationTransaction::finish();
    } catch (Throwable $caught) {
        $failure = $caught;
        if ($transactionStarted) {
            try {
                if ($participantsStarted) {
                    try {
                        $optionsMaterializer->rollback_authored_transaction();
                    } catch (Throwable $rollbackFailure) {
                        $failure = $rollbackFailure;
                    }
                }
            } finally {
                \Duo\Db::rollback('TEC mixed-option fixture rollback');
                $transactionStarted = false;
                if ($cacheStarted) {
                    \Duo\CacheInvalidationTransaction::finish();
                }
            }
        }
    }
    $row = null;
    $legacyRow = null;
    $runtimeRows = [];
    foreach ($db->rows($db->options) as $candidate) {
        if (($candidate['option_name'] ?? null) === $name) {
            $row = $candidate;
        }
        if (($candidate['option_name'] ?? null) === 'tribe_events_pro_customizer') {
            $legacyRow = $candidate;
        }
        if (in_array(($candidate['option_name'] ?? null), [
            'tribe_last_updated_option',
            'tribe_last_save_post',
        ], true)) {
            $runtimeRows[(string) $candidate['option_name']] = $candidate;
        }
    }
    $settingsPresentAfter = tribe_isset_var('Tribe__Settings_Manager:option_cache');
    $settingsAfter = $settingsPresentAfter
        ? tribe_get_var('Tribe__Settings_Manager:option_cache')
        : null;
    $purgePresentAfter = tribe_isset_var('should_delete_expired_transients');
    $purgeAfter = $purgePresentAfter ? tribe_get_var('should_delete_expired_transients') : null;
    $tribeVarWrites = $GLOBALS['tec_readiness_tribe_var_writes'];
    if ($participantsStarted) {
        $optionsMaterializer->end_authored_transaction();
        $fieldMaterializer->end_authored_transaction();
    }
    if ($cacheStarted) {
        \Duo\CacheInvalidationTransaction::end();
    }
    $GLOBALS['wpdb'] = $savedDb;
    $GLOBALS['tec_readiness_tribe_vars'] = $savedTribeVars;
    $GLOBALS['tec_readiness_wp_cache'] = $savedWpCache;
    if ($savedCacheDeletesPresent) {
        $GLOBALS['tec_readiness_wp_cache_deletes'] = $savedCacheDeletes;
    } else {
        unset($GLOBALS['tec_readiness_wp_cache_deletes']);
    }
    $GLOBALS['tec_readiness_wp_cache_delete_mode'] = $savedCacheMode;
    $GLOBALS['tec_readiness_interpreter_time_plan'] = $savedTimePlan;
    $GLOBALS['tec_readiness_tribe_var_failure'] = $savedVarFailure;
    $GLOBALS['tec_readiness_tribe_var_writes'] = $savedVarWrites;
    return [
        'row' => $row,
        'legacy_row' => $legacyRow,
        'failure' => $failure,
        'warnings' => $warnings,
        'settings_cache_present' => $settingsPresentAfter,
        'settings_cache' => $settingsAfter,
        'runtime_rows' => $runtimeRows,
        'purge_flag_present' => $purgePresentAfter,
        'purge_flag' => $purgeAfter,
        'tribe_var_writes' => $tribeVarWrites,
    ];
}

/** @return array<string,mixed> */
$decodeMixedRow = static function (array $result): array {
    $wire = $result['row']['option_value'] ?? null;
    duo_check(is_string($wire), 'the native mixed-option fixture retained one string storage row');
    $decoded = is_string($wire) ? \Duo\PlainData::decode($wire, 'TEC mixed-option fixture') : null;
    duo_check(is_array($decoded), 'the native mixed-option fixture retained one array-shaped storage value');
    return is_array($decoded) ? $decoded : [];
};

$legacyFallback = ['global_elements' => ['background_color_choice' => 'CUSTOM !!']];
duo_check_same(
    [
        'state' => 'present',
        'autoload' => 'auto-on',
        'value' => ['global_elements' => ['background_color_choice' => 'custom']],
    ],
    tec_readiness_capture_customizer_record($policy, false, [], true, $legacyFallback),
    'the product capture path canonicalizes a legacy-only Customizer row into the current record'
);
duo_check_same(
    ['state' => 'present', 'autoload' => 'off', 'value' => []],
    tec_readiness_capture_customizer_record($policy, true, [], true, $legacyFallback, 'off'),
    'the product capture path gives a persisted empty current row precedence over populated legacy bytes'
);
duo_check_same(
    null,
    tec_readiness_capture_customizer_record($policy, false, [], false, []),
    'the product capture path preserves canonical absence when both current and legacy rows are absent'
);
duo_check_same(
    null,
    tec_readiness_capture_customizer_record($policy, false, [], true, []),
    'an empty legacy-only row remains canonical absence because its target-owned fallback is behaviorally empty'
);

$decodeLegacyCompanion = static function (array $result): ?array {
    $wire = $result['legacy_row']['option_value'] ?? null;
    if ($wire === null) {
        return null;
    }
    duo_check(is_string($wire), 'the legacy Customizer companion retains one string storage row');
    $decoded = is_string($wire) ? \Duo\PlainData::decode($wire, 'TEC legacy Customizer companion') : null;
    duo_check(is_array($decoded), 'the legacy Customizer companion remains array-shaped');
    return is_array($decoded) ? $decoded : null;
};

$GLOBALS['tec_readiness_settings_manager'] = Tribe__Settings_Manager::instance();
$GLOBALS['tec_readiness_cache_listener'] = Tribe__Cache_Listener::instance();
$GLOBALS['tec_readiness_deprecation'] = Tribe__Deprecation::instance();
$GLOBALS['tec_readiness_events_rewrite'] = Tribe__Events__Rewrite::instance();
$GLOBALS['tec_readiness_aggregator'] = Tribe__Events__Aggregator::instance();
$GLOBALS['tec_readiness_views_manager'] = new \Tribe\Events\Views\V2\Manager();
$GLOBALS['tec_readiness_views_hooks'] = new \Tribe\Events\Views\V2\Hooks();
$GLOBALS['tec_readiness_kitchen_sink'] = new \Tribe\Events\Views\V2\Kitchen_Sink();
$GLOBALS['tec_readiness_qr_routes'] = new \TEC\Events\QR\Routes();
$GLOBALS['tec_readiness_harbor_pue'] = new \TEC\Common\Integrations\Harbor\PUE();

$targetLegacy = ['month_view' => ['grid_lines_color' => '#445566']];
$absentAgainstLegacy = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: [],
    target: null,
    targetLegacy: $targetLegacy,
    desiredPresent: false
);
duo_check_same(null, $absentAgainstLegacy['failure'], 'absent canonical intent performs no Customizer write');
duo_check_same(null, $absentAgainstLegacy['row'], 'absent source intent does not shadow a target legacy fallback');
duo_check_same(
    $targetLegacy,
    $decodeLegacyCompanion($absentAgainstLegacy),
    'absent source intent preserves the exact target-owned legacy fallback row'
);

$explicitEmptyAgainstLegacy = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: [],
    target: null,
    targetLegacy: $targetLegacy
);
duo_check_same(
    null,
    $explicitEmptyAgainstLegacy['failure'],
    'explicit canonical empty intent safely shadows a populated target legacy fallback'
);
duo_check_same([], $decodeMixedRow($explicitEmptyAgainstLegacy), 'explicit empty intent persists a canonical empty row');
duo_check_same(
    $targetLegacy,
    $decodeLegacyCompanion($explicitEmptyAgainstLegacy),
    'explicit canonical empty intent does not mutate the target-owned legacy companion'
);

$legacySourceRecord = tec_readiness_capture_customizer_record($policy, false, [], true, $legacyFallback);
duo_check(is_array($legacySourceRecord), 'legacy-only capture emits one canonical present record');
$legacyOnlyApply = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: (array) ($legacySourceRecord['value'] ?? []),
    target: null,
    desiredAutoload: (string) ($legacySourceRecord['autoload'] ?? 'auto-on'),
    targetLegacy: $targetLegacy
);
duo_check_same(null, $legacyOnlyApply['failure'], 'legacy-only source state canonicalizes on apply');
duo_check_same(
    ['global_elements' => ['background_color_choice' => 'custom']],
    $decodeMixedRow($legacyOnlyApply),
    'legacy-only source state becomes the exact effective canonical value'
);
duo_check_same(
    $targetLegacy,
    $decodeLegacyCompanion($legacyOnlyApply),
    'legacy-only source canonicalization preserves divergent target legacy residue'
);

$canonicalSource = ['single_event' => ['post_title_color_choice' => 'CUSTOM']];
$canonicalPrecedenceRecord = tec_readiness_capture_customizer_record(
    $policy,
    true,
    $canonicalSource,
    true,
    $legacyFallback,
    'on'
);
duo_check_same(
    ['state' => 'present', 'autoload' => 'on', 'value' => ['single_event' => ['post_title_color_choice' => 'custom']]],
    $canonicalPrecedenceRecord,
    'a populated canonical source row wins over a conflicting populated legacy row'
);
$canonicalPrecedenceApply = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: (array) ($canonicalPrecedenceRecord['value'] ?? []),
    target: ['global_elements' => ['background_color_choice' => 'custom']],
    desiredAutoload: 'on',
    targetLegacy: $targetLegacy
);
duo_check_same(null, $canonicalPrecedenceApply['failure'], 'canonical source precedence applies transactionally');
duo_check_same(
    ['single_event' => ['post_title_color_choice' => 'custom']],
    $decodeMixedRow($canonicalPrecedenceApply),
    'canonical source precedence replaces a conflicting target canonical value'
);
duo_check_same(
    $targetLegacy,
    $decodeLegacyCompanion($canonicalPrecedenceApply),
    'canonical source precedence leaves its target legacy companion byte-exact'
);

$customizerResidue = [
    'view_selector_background_color' => '#abcdef',
    'view_selector_background_color_choice' => 'custom',
];
$residueCarrier = ['tec_events_bar' => $customizerResidue];
$customizerCases = [
    'absent desired and absent target' => [[], null, []],
    'absent desired with target residue carrier' => [[], $residueCarrier, $residueCarrier],
    'explicit empty desired with the same residue carrier' => [
        ['tec_events_bar' => []],
        $residueCarrier,
        $residueCarrier,
    ],
    'authored source with target residue' => [
        ['global_elements' => ['background_color' => '#112233']],
        $residueCarrier,
        [
            'global_elements' => ['background_color' => '#112233'],
            'tec_events_bar' => $customizerResidue,
        ],
    ],
    'authored source replaces dirty target and preserves residue' => [
        ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
        [
            'month_view' => ['grid_lines_color' => '#999999'],
            'tec_events_bar' => ['events_bar_text_color' => '#654321'] + $customizerResidue,
        ],
        ['tec_events_bar' => ['events_bar_text_color' => '#123456'] + $customizerResidue],
    ],
    'last authored inner deletion preserves the physical residue carrier' => [
        ['tec_events_bar' => []],
        ['tec_events_bar' => ['events_bar_text_color' => '#654321'] + $customizerResidue],
        $residueCarrier,
    ],
];
$customizerResults = [];
foreach ($customizerCases as $label => [$desired, $target, $expected]) {
    $result = tec_readiness_materialize_mixed_option($policy, 'tribe_customizer', $desired, $target);
    duo_check_same(null, $result['failure'], "Customizer $label succeeds through the native product materializer");
    duo_check_same([], $result['warnings'], "Customizer $label emits no generic absent-target fallback warning");
    duo_check_same($expected, $decodeMixedRow($result), "Customizer $label persists the exact sparse carrier semantics");
    $customizerResults[$label] = $result;
}
duo_check_same(
    $customizerResults['absent desired with target residue carrier']['row']['option_value'] ?? null,
    $customizerResults['explicit empty desired with the same residue carrier']['row']['option_value'] ?? null,
    'identical physical residue bytes verify as absent or explicit-empty only through the engine-owned desired-key roster'
);

$legacyCompanionReads = 0;
$driftingLegacy = ['month_view' => ['grid_lines_color' => '#111111']];
$driftedLegacy = ['month_view' => ['grid_lines_color' => '#222222']];
$legacyDrift = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: ['month_view' => ['grid_lines_color' => '#abcdef']],
    target: null,
    targetLegacy: $driftingLegacy,
    configureDb: static function (LockingFakeWpdb $db) use (&$legacyCompanionReads, $driftedLegacy): void {
        $db->inner()->onQuery(static function (string $sql, string $method, FakeWpdb $inner) use (
            &$legacyCompanionReads,
            $driftedLegacy
        ): null {
            if ($method !== 'get_results'
                || !str_contains($sql, "option_name = 'tribe_events_pro_customizer'")) {
                return null;
            }
            ++$legacyCompanionReads;
            if ($legacyCompanionReads !== 4) {
                return null;
            }
            $rows = $inner->rows($inner->options);
            foreach ($rows as &$row) {
                if (($row['option_name'] ?? null) === 'tribe_events_pro_customizer') {
                    $row['option_value'] = serialize($driftedLegacy);
                }
            }
            unset($row);
            $inner->seedTable($inner->options, $rows);
            $inner->onQuery(null);
            return null;
        });
    }
);
duo_check(
    $legacyDrift['failure'] instanceof RuntimeException
        && str_contains($legacyDrift['failure']->getMessage(), 'changed a locked companion option'),
    'same-length legacy companion drift between initial lock and post-hook verification refuses the apply'
);
duo_check_same(null, $legacyDrift['row'], 'legacy companion drift rolls canonical insertion back to exact absence');
duo_check_same(
    $driftingLegacy,
    $decodeLegacyCompanion($legacyDrift),
    'legacy companion drift rollback restores its exact transaction preimage'
);
$legacyDriftRetry = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_customizer',
    desired: ['month_view' => ['grid_lines_color' => '#abcdef']],
    target: null,
    targetLegacy: $driftingLegacy
);
duo_check_same(null, $legacyDriftRetry['failure'], 'same-process retry converges after legacy companion drift stops');
duo_check_same(
    $driftingLegacy,
    $decodeLegacyCompanion($legacyDriftRetry),
    'the converged retry preserves the exact target legacy companion'
);

foreach ([
    'associative roster' => ['tec_events_bar' => 'tec_events_bar'],
    'duplicate roster' => ['tec_events_bar', 'tec_events_bar'],
    'non-string roster' => [17],
    'undeclared roster' => ['future_section'],
] as $label => $malformedDesiredSections) {
    try {
        $interpreter->project_materialized_option_sub_keys(
            'tribe_customizer',
            ['tec_events_bar' => []],
            $customizerDeclaredSubKeys,
            $malformedDesiredSections
        );
        $malformedProjectionRefused = false;
    } catch (RuntimeException $failure) {
        $malformedProjectionRefused = str_contains($failure->getMessage(), 'desired-section roster')
            || str_contains($failure->getMessage(), 'malformed desired section');
    }
    duo_check(
        $malformedProjectionRefused,
        "the Customizer projection refuses a $label outside the engine-generated desired-key contract"
    );
}

$unknownCustomizerTarget = ['tec_events_bar' => ['future_extension_setting' => 'leave-me']];
$unknownCustomizer = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
    $unknownCustomizerTarget
);
duo_check(
    $unknownCustomizer['failure'] instanceof RuntimeException
        && str_contains($unknownCustomizer['failure']->getMessage(), 'undeclared setting'),
    'an unknown nested Customizer setting refuses through the product materializer before replacement'
);
duo_check_same(
    $unknownCustomizerTarget,
    $decodeMixedRow($unknownCustomizer),
    'unknown nested Customizer refusal preserves exact target bytes for same-process repair'
);
$unknownCustomizerRetry = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
    $residueCarrier
);
duo_check_same(null, $unknownCustomizerRetry['failure'], 'same-process retry after removing an unknown setting converges');

$hostileCustomizerFilter = static fn(mixed $value): mixed => $value;
add_filter('tribe_customizer_get_option', $hostileCustomizerFilter);
$hookRefusalTarget = ['month_view' => ['grid_lines_color' => '#999999']];
$hookRefusal = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    $hookRefusalTarget
);
remove_filter('tribe_customizer_get_option', $hostileCustomizerFilter);
duo_check(
    $hookRefusal['failure'] instanceof RuntimeException
        && str_contains($hookRefusal['failure']->getMessage(), 'hook topology is extended'),
    'an unsupported Customizer value callback refuses before adapter storage mutation'
);
duo_check_same(
    $hookRefusalTarget,
    $decodeMixedRow($hookRefusal),
    'unsupported Customizer callback refusal preserves the exact dirty target row'
);

$hostileOptionMutationCallback = static fn(mixed ...$values): mixed => $values[0] ?? null;
$optionMutationHooks = [
    'existing sanitize callback' => ['sanitize_option_tribe_customizer', $hookRefusalTarget],
    'existing specific pre-update callback' => ['pre_update_option_tribe_customizer', $hookRefusalTarget],
    'existing generic pre-update callback' => ['pre_update_option', $hookRefusalTarget],
    'existing specific update action' => ['update_option_tribe_customizer', $hookRefusalTarget],
    'existing generic update action' => ['update_option', $hookRefusalTarget],
    'existing generic updated action' => ['updated_option', $hookRefusalTarget],
    'existing autoload callback' => ['wp_autoload_values_to_autoload', $hookRefusalTarget],
    'absent specific pre-update callback' => ['pre_update_option_tribe_customizer', null],
    'absent generic pre-update callback' => ['pre_update_option', null],
    'absent generic add action' => ['add_option', null],
    'absent specific add action' => ['add_option_tribe_customizer', null],
    'absent generic added action' => ['added_option', null],
    'absent default-autoload callback' => ['wp_default_autoload_value', null],
    'absent autoload-size callback' => ['wp_max_autoloaded_option_size', null],
];
foreach ($optionMutationHooks as $label => [$hookName, $target]) {
    add_filter($hookName, $hostileOptionMutationCallback, 999, 10);
    $mutationHookRefusal = tec_readiness_materialize_mixed_option(
        $policy,
        'tribe_customizer',
        ['month_view' => ['grid_lines_color' => '#112233']],
        $target
    );
    remove_filter($hookName, $hostileOptionMutationCallback, 999);
    duo_check(
        $mutationHookRefusal['failure'] instanceof RuntimeException
            && str_contains($mutationHookRefusal['failure']->getMessage(), 'option mutation hook topology'),
        "a $label refuses before the raw Customizer writer can bypass it"
    );
    if ($target === null) {
        duo_check_same(null, $mutationHookRefusal['row'], "$label refusal preserves exact target absence");
    } else {
        duo_check_same($target, $decodeMixedRow($mutationHookRefusal), "$label refusal preserves exact target bytes");
    }
    $mutationHookRetry = tec_readiness_materialize_mixed_option(
        $policy,
        'tribe_customizer',
        ['month_view' => ['grid_lines_color' => '#112233']],
        $target
    );
    duo_check_same(null, $mutationHookRetry['failure'], "$label removal permits a same-process retry");
}

$substitutedSettingsManager = new Tribe__Settings_Manager();
$substitutedManagerRefusal = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    $hookRefusalTarget
);
remove_filter('updated_option', [$substitutedSettingsManager, 'update_options_cache'], 10);
duo_check(
    $substitutedManagerRefusal['failure'] instanceof RuntimeException
        && str_contains($substitutedManagerRefusal['failure']->getMessage(), 'option mutation hook topology'),
    'a same-class non-singleton Settings Manager callback refuses before Customizer storage mutation'
);
duo_check_same(
    $hookRefusalTarget,
    $decodeMixedRow($substitutedManagerRefusal),
    'same-class callback substitution preserves the exact Customizer preimage'
);

$foreignListener = (new ReflectionClass(Tribe__Cache_Listener::class))->newInstanceWithoutConstructor();
foreach (['update_last_updated_option', 'update_last_save_post'] as $method) {
    add_action('updated_option', [$foreignListener, $method], 10, 3);
    $foreignListenerRefusal = tec_readiness_materialize_mixed_option(
        $policy,
        'tribe_customizer',
        ['month_view' => ['grid_lines_color' => '#112233']],
        $hookRefusalTarget
    );
    remove_action('updated_option', [$foreignListener, $method], 10);
    duo_check(
        $foreignListenerRefusal['failure'] instanceof RuntimeException
            && str_contains($foreignListenerRefusal['failure']->getMessage(), 'option mutation hook topology'),
        "a same-class non-singleton CacheListener::$method callback refuses before storage"
    );
}

$foreignAggregator = (new ReflectionClass(Tribe__Events__Aggregator::class))->newInstanceWithoutConstructor();
add_action('updated_option', [$foreignAggregator, 'action_purge_transients'], 10, 1);
$foreignAggregatorRefusal = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    $hookRefusalTarget
);
remove_action('updated_option', [$foreignAggregator, 'action_purge_transients'], 10);
duo_check(
    $foreignAggregatorRefusal['failure'] instanceof RuntimeException
        && str_contains($foreignAggregatorRefusal['failure']->getMessage(), 'option mutation hook topology'),
    'a same-class non-singleton Aggregator callback refuses before storage'
);

$foreignViews = new \Tribe\Events\Views\V2\Hooks();
$foreignViewsRefusal = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    $hookRefusalTarget
);
remove_action('updated_option', [$foreignViews, 'action_save_wplang'], 10);
remove_action('tribe_events_pre_rewrite', [$foreignViews, 'on_tribe_events_pre_rewrite'], 10);
duo_check(
    $foreignViewsRefusal['failure'] instanceof RuntimeException
        && str_contains($foreignViewsRefusal['failure']->getMessage(), 'option mutation hook topology'),
    'a same-class non-container Views callback refuses before storage'
);

if (!function_exists('wp_filter_default_autoload_value_via_option_size')) {
    function wp_filter_default_autoload_value_via_option_size(
        mixed $autoload,
        string $option,
        mixed $value,
        mixed $serializedValue
    ): mixed {
        return $autoload;
    }
}
add_filter(
    'wp_default_autoload_value',
    'wp_filter_default_autoload_value_via_option_size',
    5,
    4
);
$coreAutoloadTopology = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    null
);
remove_filter(
    'wp_default_autoload_value',
    'wp_filter_default_autoload_value_via_option_size',
    5
);
duo_check_same(
    null,
    $coreAutoloadTopology['failure'],
    'the exact pinned WordPress default-autoload callback remains admitted on canonical insertion'
);

$rollbackTarget = ['tec_events_bar' => ['events_bar_text_color' => '#654321'] + $customizerResidue];
$rollbackCustomizer = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
    $rollbackTarget,
    'auto-on',
    'off',
    false,
    null,
    'one_throw'
);
duo_check($rollbackCustomizer['failure'] instanceof RuntimeException, 'an injected post-write cache failure aborts Customizer apply');
duo_check_same($rollbackTarget, $decodeMixedRow($rollbackCustomizer), 'Customizer rollback restores exact raw target bytes');
duo_check_same('off', $rollbackCustomizer['row']['autoload'] ?? null, 'Customizer rollback restores exact target autoload');
$rollbackCustomizerRetry = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['tec_events_bar' => ['events_bar_text_color' => '#123456']],
    $rollbackTarget
);
duo_check_same(null, $rollbackCustomizerRetry['failure'], 'same-process Customizer retry converges after rollback');

$mainTarget = ['eventsSlug' => 'dirty-events', 'debugEvents' => true];
$mainSettings = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_events_calendar_options',
    ['eventsSlug' => 'portable-events'],
    $mainTarget,
    'on',
    'off',
    true,
    ['eventsSlug' => 'stale-cache'],
    timePlan: [1000.125, 1000.875]
);
duo_check_same(null, $mainSettings['failure'], 'the closed main settings blob materializes through the exact interpreter owner');
duo_check_same(
    ['debugEvents' => true, 'eventsSlug' => 'portable-events'],
    $decodeMixedRow($mainSettings),
    'main settings replace authored siblings and preserve target-owned operational state'
);
duo_check_same(true, $mainSettings['settings_cache_present'], 'successful main-settings update publishes the native request cache');
duo_check_same(
    ['debugEvents' => true, 'eventsSlug' => 'portable-events'],
    $mainSettings['settings_cache'],
    'the native Settings Manager effect caches the exact newly stored main option value'
);
duo_check_same(
    ['tribe_last_updated_option', 'tribe_last_save_post'],
    array_keys($mainSettings['runtime_rows']),
    'the two CacheListener effects persist in their actual same-priority registration order'
);
duo_check_same(
    ['option_value' => '1000.125', 'autoload' => 'auto-on'],
    array_intersect_key(
        $mainSettings['runtime_rows']['tribe_last_updated_option'] ?? [],
        ['option_value' => true, 'autoload' => true]
    ),
    'the first exact microtime call creates the updated-option marker with pinned default autoload'
);
duo_check_same(
    ['option_value' => '1000.875', 'autoload' => 'auto-on'],
    array_intersect_key(
        $mainSettings['runtime_rows']['tribe_last_save_post'] ?? [],
        ['option_value' => true, 'autoload' => true]
    ),
    'the second exact microtime call creates the save-post marker independently'
);
duo_check_same(true, $mainSettings['purge_flag_present'], 'successful marker writes publish the native purge flag');
duo_check_same(true, $mainSettings['purge_flag'], 'the native transient-purge intent is the exact boolean true');

$mainInsert = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'inserted-events'],
    target: null,
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'target-local-cache'],
    purgeFlagPresent: true,
    purgeFlag: false,
    timePlan: [1100.1, 1100.2]
);
duo_check_same(null, $mainInsert['failure'], 'an absent main option follows the native add branch');
duo_check_same([], $mainInsert['runtime_rows'], 'the add branch fires no updated_option CacheListener effects');
duo_check_same(
    ['eventsSlug' => 'target-local-cache'],
    $mainInsert['settings_cache'],
    'the add branch does not invent a Settings Manager updated_option cache effect'
);
duo_check_same(false, $mainInsert['purge_flag'], 'the add branch preserves a preexisting false purge-flag value');

$mainNoopRuntime = [
    'tribe_last_updated_option' => ['option_value' => 'old-updated', 'autoload' => 'yes'],
    'tribe_last_save_post' => ['option_value' => 'old-save', 'autoload' => 'no'],
];
$mainNoop = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'same-events'],
    target: ['eventsSlug' => 'same-events'],
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'same-cache'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [1200.1, 1200.2]
);
duo_check_same(null, $mainNoop['failure'], 'an unchanged main value materializes without native update callbacks');
duo_check_same(
    ['eventsSlug' => 'same-cache'],
    $mainNoop['settings_cache'],
    'an unchanged main value preserves the existing Settings Manager cache preimage'
);
duo_check_same(
    $mainNoopRuntime,
    array_map(
        static fn(array $row): array => array_intersect_key($row, ['option_value' => true, 'autoload' => true]),
        $mainNoop['runtime_rows']
    ),
    'an unchanged main value leaves both native marker bytes and autoloads exact'
);
duo_check_same(false, $mainNoop['purge_flag_present'], 'an unchanged main value does not create purge intent');

$mainAutoloadEffects = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'autoload-events'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: [
        'tribe_last_updated_option' => ['option_value' => 'old-updated', 'autoload' => 'yes'],
        'tribe_last_save_post' => ['option_value' => 'old-save', 'autoload' => 'auto-off'],
    ],
    purgeFlagPresent: true,
    purgeFlag: false,
    timePlan: [1300.1, 1300.2]
);
duo_check_same(null, $mainAutoloadEffects['failure'], 'existing fixed/computed marker rows update transactionally');
duo_check_same(
    'yes',
    $mainAutoloadEffects['runtime_rows']['tribe_last_updated_option']['autoload'] ?? null,
    'a fixed native marker autoload spelling is preserved'
);
duo_check_same(
    'auto-on',
    $mainAutoloadEffects['runtime_rows']['tribe_last_save_post']['autoload'] ?? null,
    'a computed native marker autoload is recalculated through the pinned short-value outcome'
);
duo_check_same(true, $mainAutoloadEffects['purge_flag'], 'a successful update replaces false purge intent with true');

$mainPreexistingPurge = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'preexisting-purge-events'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $mainNoopRuntime,
    purgeFlagPresent: true,
    purgeFlag: true,
    timePlan: [1350.1, 1350.2]
);
duo_check_same(null, $mainPreexistingPurge['failure'], 'a main-settings update admits preexisting purge intent');
duo_check_same(true, $mainPreexistingPurge['purge_flag'], 'successful marker effects preserve an independently preexisting true purge flag');

$equalMarkerRows = [
    'tribe_last_updated_option' => ['option_value' => '1400.1', 'autoload' => 'on'],
    'tribe_last_save_post' => ['option_value' => '1400.2', 'autoload' => 'off'],
];
$equalMarkerEffects = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'equal-marker-events'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $equalMarkerRows,
    purgeFlagPresent: true,
    purgeFlag: false,
    timePlan: [1400.1, 1400.2]
);
duo_check_same(null, $equalMarkerEffects['failure'], 'equal generated marker values follow the native no-op path');
duo_check_same(
    $equalMarkerRows,
    array_map(
        static fn(array $row): array => array_intersect_key($row, ['option_value' => true, 'autoload' => true]),
        $equalMarkerEffects['runtime_rows']
    ),
    'equal generated marker values preserve exact storage/autoload without a raw writer call'
);
duo_check_same(false, $equalMarkerEffects['purge_flag'], 'two no-op marker updates preserve an existing false purge intent');

$listenerCacheProperty = new ReflectionProperty(Tribe__Cache_Listener::class, 'cache');
$nativeListenerCache = $listenerCacheProperty->getValue($GLOBALS['tec_readiness_cache_listener']);
$listenerCacheProperty->setValue(
    $GLOBALS['tec_readiness_cache_listener'],
    $GLOBALS['tec_readiness_category_color_cache']
);
$aliasedListenerCacheRefusal = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'aliased-listener-cache'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [1450.1, 1450.2]
);
$listenerCacheProperty->setValue($GLOBALS['tec_readiness_cache_listener'], $nativeListenerCache);
duo_check(
    $aliasedListenerCacheRefusal['failure'] instanceof RuntimeException
        && str_contains($aliasedListenerCacheRefusal['failure']->getMessage(), 'cache-listener/global cache identities'),
    'an aliased CacheListener/global cache service refuses before primary mutation'
);

$nativeGlobalCache = $GLOBALS['tec_readiness_category_color_cache'];
$GLOBALS['tec_readiness_category_color_cache'] = new Tribe__Cache();
$substitutedGlobalCacheRefusal = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'substituted-global-cache'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [1460.1, 1460.2]
);
$GLOBALS['tec_readiness_category_color_cache'] = $nativeGlobalCache;
duo_check(
    $substitutedGlobalCacheRefusal['failure'] instanceof RuntimeException
        && str_contains($substitutedGlobalCacheRefusal['failure']->getMessage(), 'cache-listener/global cache identities'),
    'a same-class global cache substitution refuses against the exact container singleton'
);

add_filter(
    'pre_option',
    [$GLOBALS['tec_readiness_harbor_pue'], 'filter_pre_get_option'],
    10,
    3
);
$nativeHarborTopology = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'harbor-events'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [1470.1, 1470.2]
);
remove_filter(
    'pre_option',
    [$GLOBALS['tec_readiness_harbor_pue'], 'filter_pre_get_option'],
    10
);
duo_check_same(
    null,
    $nativeHarborTopology['failure'],
    'the exact request-conditional Harbor pre_option singleton remains admitted'
);

$foreignHarbor = new \TEC\Common\Integrations\Harbor\PUE();
add_filter('pre_option', [$foreignHarbor, 'filter_pre_get_option'], 10, 3);
$foreignHarborRefusal = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'foreign-harbor-events'],
    target: ['eventsSlug' => 'old-events'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [1480.1, 1480.2]
);
remove_filter('pre_option', [$foreignHarbor, 'filter_pre_get_option'], 10);
duo_check(
    $foreignHarborRefusal['failure'] instanceof RuntimeException
        && str_contains($foreignHarborRefusal['failure']->getMessage(), 'option mutation hook topology'),
    'a same-class non-container Harbor pre_option callback refuses before storage'
);

$settingsCacheWrites = array_values(array_filter(
    $mainSettings['tribe_var_writes'],
    static fn(array $write): bool => ($write[0] ?? null) === 'set'
        && ($write[1] ?? null) === 'Tribe__Settings_Manager:option_cache'
));
duo_check_same(
    1,
    count($settingsCacheWrites),
    'recursive last-occurrence marker effects never update the main Settings Manager cache'
);

foreach ([
    'generic listener trigger' => 'tribe_cache_last_occurrence_option_triggers',
    'updated-option listener trigger' => 'tribe_cache_last_occurrence_option_triggers:updated_option',
    'save-post listener trigger' => 'tribe_cache_last_occurrence_option_triggers:save_post',
] as $label => $hookName) {
    add_filter($hookName, $hostileOptionMutationCallback, 999, 3);
    $listenerFilterRefusal = tec_readiness_materialize_mixed_option(
        policy: $policy,
        name: 'tribe_events_calendar_options',
        desired: ['eventsSlug' => 'filtered-events'],
        target: ['eventsSlug' => 'old-events'],
        targetRuntimeRows: $mainNoopRuntime,
        timePlan: [1500.1, 1500.2]
    );
    remove_filter($hookName, $hostileOptionMutationCallback, 999);
    duo_check(
        $listenerFilterRefusal['failure'] instanceof RuntimeException
            && str_contains($listenerFilterRefusal['failure']->getMessage(), 'cache-listener trigger topology'),
        "a $label extension refuses before primary or marker storage mutation"
    );
    duo_check_same(
        ['eventsSlug' => 'old-events'],
        $decodeMixedRow($listenerFilterRefusal),
        "$label refusal preserves the exact main target preimage"
    );
    duo_check_same(
        $mainNoopRuntime,
        array_map(
            static fn(array $row): array => array_intersect_key($row, ['option_value' => true, 'autoload' => true]),
            $listenerFilterRefusal['runtime_rows']
        ),
        "$label refusal preserves both marker preimages"
    );
}

$nestedMarkerHookCases = [
    'existing specific read filter' => ['option_tribe_last_updated_option', true],
    'existing specific sanitizer' => ['sanitize_option_tribe_last_updated_option', true],
    'existing specific pre-update filter' => ['pre_update_option_tribe_last_updated_option', true],
    'existing specific update action' => ['update_option_tribe_last_updated_option', true],
    'absent specific default filter' => ['default_option_tribe_last_save_post', false],
    'absent specific sanitizer' => ['sanitize_option_tribe_last_save_post', false],
    'absent specific add action' => ['add_option_tribe_last_save_post', false],
    'absent generic added action' => ['added_option', false],
    'nested default-autoload filter' => ['wp_default_autoload_value', false],
    'nested max-autoload-size filter' => ['wp_max_autoloaded_option_size', false],
];
foreach ($nestedMarkerHookCases as $label => [$hookName, $existing]) {
    $runtimePreimage = $existing ? $mainNoopRuntime : [];
    add_filter($hookName, $hostileOptionMutationCallback, 999, 10);
    $nestedHookRefusal = tec_readiness_materialize_mixed_option(
        policy: $policy,
        name: 'tribe_events_calendar_options',
        desired: ['eventsSlug' => 'nested-hook-events'],
        target: ['eventsSlug' => 'old-events'],
        targetRuntimeRows: $runtimePreimage,
        timePlan: [1600.1, 1600.2]
    );
    remove_filter($hookName, $hostileOptionMutationCallback, 999);
    duo_check(
        $nestedHookRefusal['failure'] instanceof RuntimeException
            && str_contains($nestedHookRefusal['failure']->getMessage(), 'option mutation hook topology'),
        "a $label refuses before the raw nested update_option effect"
    );
    duo_check_same(
        ['eventsSlug' => 'old-events'],
        $decodeMixedRow($nestedHookRefusal),
        "$label refusal preserves primary storage"
    );
}

foreach ([
    'first marker insert' => 'tribe_last_updated_option',
    'second marker insert' => 'tribe_last_save_post',
] as $label => $failedMarker) {
    $markerWriteFailure = tec_readiness_materialize_mixed_option(
        policy: $policy,
        name: 'tribe_events_calendar_options',
        desired: ['eventsSlug' => 'write-failure-events'],
        target: ['eventsSlug' => 'old-events'],
        settingsCachePresent: true,
        settingsCache: ['eventsSlug' => 'cache-preimage'],
        purgeFlagPresent: true,
        purgeFlag: false,
        timePlan: [1700.1, 1700.2],
        configureDb: static function (LockingFakeWpdb $db) use ($failedMarker): void {
            $db->inner()->onQuery(static function (string $sql, string $method) use ($failedMarker): ?string {
                if ($method === 'insert' && str_contains($sql, $failedMarker)) {
                    return 'injected marker write failure';
                }
                return null;
            });
        }
    );
    duo_check(
        $markerWriteFailure['failure'] instanceof RuntimeException,
        "an injected $label failure aborts the authored transaction"
    );
    duo_check_same(
        ['eventsSlug' => 'old-events'],
        $decodeMixedRow($markerWriteFailure),
        "$label failure restores exact primary storage"
    );
    duo_check_same([], $markerWriteFailure['runtime_rows'], "$label failure restores both initially absent marker gaps");
    duo_check_same(
        ['eventsSlug' => 'cache-preimage'],
        $markerWriteFailure['settings_cache'],
        "$label failure restores the exact Settings Manager cache preimage"
    );
    duo_check_same(false, $markerWriteFailure['purge_flag'], "$label failure restores the false purge-flag preimage");
}

$settingsEffectFailure = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'settings-effect-failure'],
    target: ['eventsSlug' => 'old-events'],
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'cache-preimage'],
    tribeVarFailure: [
        'operation' => 'set',
        'key' => 'Tribe__Settings_Manager:option_cache',
        'once' => true,
    ],
    timePlan: [1800.1, 1800.2]
);
duo_check($settingsEffectFailure['failure'] instanceof RuntimeException, 'an injected Settings Manager cache-set failure aborts apply');
duo_check_same(['eventsSlug' => 'old-events'], $decodeMixedRow($settingsEffectFailure), 'settings-cache failure restores primary bytes');
duo_check_same([], $settingsEffectFailure['runtime_rows'], 'settings-cache failure occurs before either marker effect');
duo_check_same(
    ['eventsSlug' => 'cache-preimage'],
    $settingsEffectFailure['settings_cache'],
    'settings-cache failure restores its exact request-local preimage'
);

$purgeEffectFailure = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'purge-effect-failure'],
    target: ['eventsSlug' => 'old-events'],
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'cache-preimage'],
    tribeVarFailure: [
        'operation' => 'set',
        'key' => 'should_delete_expired_transients',
        'once' => true,
    ],
    timePlan: [1900.1, 1900.2]
);
duo_check($purgeEffectFailure['failure'] instanceof RuntimeException, 'an injected purge-flag failure aborts after the first marker write');
duo_check_same(['eventsSlug' => 'old-events'], $decodeMixedRow($purgeEffectFailure), 'purge-flag failure restores primary bytes');
duo_check_same([], $purgeEffectFailure['runtime_rows'], 'purge-flag failure removes the partially inserted marker');
duo_check_same(false, $purgeEffectFailure['purge_flag_present'], 'purge-flag failure restores exact prior absence');

$preexistingPurgeRollback = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'preexisting-purge-rollback'],
    target: ['eventsSlug' => 'old-events'],
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'cache-preimage'],
    purgeFlagPresent: true,
    purgeFlag: true,
    timePlan: [1950.1, 1950.2],
    configureDb: static function (LockingFakeWpdb $db): void {
        $db->inner()->onQuery(static function (string $sql, string $method): ?string {
            if ($method === 'insert' && str_contains($sql, 'tribe_last_save_post')) {
                return 'injected marker write failure';
            }
            return null;
        });
    }
);
duo_check(
    $preexistingPurgeRollback['failure'] instanceof RuntimeException,
    'a second-marker failure aborts after observing preexisting purge intent'
);
duo_check_same(
    true,
    $preexistingPurgeRollback['purge_flag'],
    'rollback preserves an independently preexisting true purge flag'
);

foreach ([
    'main existing specific update callback' => ['update_option_tribe_events_calendar_options', $mainTarget],
    'main absent specific add callback' => ['add_option_tribe_events_calendar_options', null],
] as $label => [$hookName, $target]) {
    add_filter($hookName, $hostileOptionMutationCallback, 999, 10);
    $mainMutationRefusal = tec_readiness_materialize_mixed_option(
        $policy,
        'tribe_events_calendar_options',
        ['eventsSlug' => 'portable-events'],
        $target,
        'on'
    );
    remove_filter($hookName, $hostileOptionMutationCallback, 999);
    duo_check(
        $mainMutationRefusal['failure'] instanceof RuntimeException
            && str_contains($mainMutationRefusal['failure']->getMessage(), 'option mutation hook topology'),
        "$label refuses before raw main-settings storage"
    );
    if ($target === null) {
        duo_check_same(null, $mainMutationRefusal['row'], "$label preserves exact target absence");
    } else {
        duo_check_same($target, $decodeMixedRow($mainMutationRefusal), "$label preserves exact target bytes");
    }
    $mainMutationRetry = tec_readiness_materialize_mixed_option(
        $policy,
        'tribe_events_calendar_options',
        ['eventsSlug' => 'portable-events'],
        $target,
        'on'
    );
    duo_check_same(null, $mainMutationRetry['failure'], "$label removal permits a same-process retry");
}

$mainRollback = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_events_calendar_options',
    ['eventsSlug' => 'portable-events'],
    $mainTarget,
    'on',
    'off',
    true,
    ['eventsSlug' => 'exact-preimage'],
    'one_throw'
);
duo_check($mainRollback['failure'] instanceof RuntimeException, 'an injected main-settings cache failure aborts apply');
duo_check_same($mainTarget, $decodeMixedRow($mainRollback), 'main-settings rollback restores exact raw storage');
duo_check_same('off', $mainRollback['row']['autoload'] ?? null, 'main-settings rollback restores exact autoload');
duo_check_same(true, $mainRollback['settings_cache_present'], 'main-settings rollback restores cache presence');
duo_check_same(
    ['eventsSlug' => 'exact-preimage'],
    $mainRollback['settings_cache'],
    'main-settings rollback restores the exact process-local cache value'
);
duo_check_same([], $mainRollback['runtime_rows'], 'main-settings cache rollback removes both partially inserted marker rows');
duo_check_same(false, $mainRollback['purge_flag_present'], 'main-settings cache rollback restores absent purge intent');
$mainRollbackRetry = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'portable-events'],
    target: $mainTarget,
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'exact-preimage'],
    timePlan: [2000.1, 2000.2]
);
duo_check_same(null, $mainRollbackRetry['failure'], 'retry after full main/cache/marker rollback converges');
duo_check_same(
    ['tribe_last_updated_option', 'tribe_last_save_post'],
    array_keys($mainRollbackRetry['runtime_rows']),
    'the retry creates both exact CacheListener markers once'
);

$mainRestoreFailure = tec_readiness_materialize_mixed_option(
    policy: $policy,
    name: 'tribe_events_calendar_options',
    desired: ['eventsSlug' => 'restore-failure-events'],
    target: $mainTarget,
    settingsCachePresent: true,
    settingsCache: ['eventsSlug' => 'restore-cache-preimage'],
    targetRuntimeRows: $mainNoopRuntime,
    timePlan: [2100.1, 2100.2],
    configureDb: static function (LockingFakeWpdb $db): void {
        $db->inner()->onQuery(static function (string $sql, string $method): ?string {
            static $failed = false;
            if (!$failed && $method === 'update' && str_contains($sql, 'tribe_last_save_post')) {
                $failed = true;
                return 'injected second marker update failure';
            }
            return null;
        });
    },
    tribeVarFailure: [
        [
            'operation' => 'set',
            'key' => 'Tribe__Settings_Manager:option_cache',
            'skip' => 1,
            'once' => true,
        ],
        [
            'operation' => 'unset',
            'key' => 'should_delete_expired_transients',
            'once' => true,
        ],
    ]
);
$restoreFailureMessages = [];
for ($failure = $mainRestoreFailure['failure']; $failure instanceof Throwable; $failure = $failure->getPrevious()) {
    $restoreFailureMessages[] = $failure->getMessage();
}
duo_check(
    str_contains(implode(' | ', $restoreFailureMessages), 'runtime=')
        && str_contains(implode(' | ', $restoreFailureMessages), 'settings=')
        && str_contains(implode(' | ', $restoreFailureMessages), 'purge='),
    'both local runtime restoration failures are aggregated and retained by the participant failure'
);
duo_check_same(
    $mainNoopRuntime,
    array_map(
        static fn(array $row): array => array_intersect_key($row, ['option_value' => true, 'autoload' => true]),
        $mainRestoreFailure['runtime_rows']
    ),
    'a failed local cache restore still attempts and completes both marker restorations'
);
duo_check_same(true, $mainRestoreFailure['purge_flag'], 'the injected purge restoration failure leaves its mutated value observable');
duo_check(
    in_array(['unset', 'should_delete_expired_transients', null], $mainRestoreFailure['tribe_var_writes'], true),
    'a failed settings-cache restoration does not skip the independent purge restoration attempt'
);

duo_check(in_array('tribe_events', $policy->declared_post_types(), true), 'events are in adapter post scope');
duo_check(in_array('tribe_venue', $policy->declared_post_types(), true), 'venues are in adapter post scope');
duo_check(in_array('tribe_organizer', $policy->declared_post_types(), true), 'organizers are in adapter post scope');
duo_check_same('authored', $policy->taxonomy_rule_details('tribe_events_cat')['rule']['class'] ?? null, 'event categories are authored taxonomy state');
foreach (['primary', 'secondary', 'text', 'priority', 'hidden'] as $suffix) {
    duo_check_same('authored', $policy->term_meta_rule('tec-events-cat-colors-' . $suffix)['class'] ?? null, "category color $suffix is authored term metadata");
}
duo_check_same('derived', $policy->table_rule('tec_events')['class'] ?? null, 'tec_events is derived rather than duplicated authored state');
duo_check_same('derived', $policy->table_rule('tec_occurrences')['class'] ?? null, 'tec_occurrences is derived and regenerated');
duo_check_same('runtime', $policy->table_rule('tec_kv_cache')['class'] ?? null, 'tec_kv_cache remains target-runtime state');
foreach (['post:tribe_events', 'post:tribe_venue', 'post:tribe_organizer', 'term:tribe_events_cat'] as $selector) {
    duo_check_same(
        null,
        $policy->deletion_capability($selector),
        "$selector has no inferred deletion authority before its native cascades and reverse references are closed"
    );
}
$deletionSeed = (string) file_get_contents($root . '/sandbox/conformance/seeds/the-events-calendar.sh');
$deletionCheck = (string) file_get_contents($root . '/sandbox/conformance/checks/the-events-calendar.sh');
foreach ([
    'Duo Unsupported Delete Probe',
    'Duo Unsupported Delete Venue',
    'Duo Unsupported Delete Organizer',
    'duo-unsupported-delete-category',
] as $fixtureIdentity) {
    duo_check(
        str_contains($deletionSeed, $fixtureIdentity),
        "the exact live seed carries independent unreferenced deletion fixture $fixtureIdentity"
    );
}
foreach ([
    "tec_refuse_post_deletion tribe_events 'Duo Unsupported Delete Probe' post:tribe_events",
    "tec_refuse_post_deletion tribe_venue 'Duo Unsupported Delete Venue' post:tribe_venue",
    "tec_refuse_post_deletion tribe_organizer 'Duo Unsupported Delete Organizer' post:tribe_organizer",
    'tec_refuse_term_deletion tribe_events_cat duo-unsupported-delete-category term:tribe_events_cat',
] as $probe) {
    duo_check(str_contains($deletionCheck, $probe), "the exact live matrix executes $probe");
}
foreach (['postmeta', 'termmeta', 'term_relationships', 'tec_events', 'tec_occurrences', 'category_css'] as $witness) {
    duo_check(
        str_contains($deletionCheck, '"' . $witness . '"=>'),
        "deletion refusal fingerprints $witness before and after capture"
    );
}
foreach ([
    'tec_events_custom_tables_v1_event_data_from_post',
    'FILTER_DERIVED_BEFORE=$(tec_derived_hash)',
    'regen_pending:',
    'event-data filter refusal advanced applied_revision',
    'successful event-data filter retry retained its batch marker',
] as $filterRecoveryEvidence) {
    duo_check(
        str_contains($deletionCheck, $filterRecoveryEvidence),
        "the exact live matrix binds event-data filter recovery evidence $filterRecoveryEvidence"
    );
}
foreach ([
    'duo-equal-priority-alpha',
    'repeated reconciliation repopulated a naturally absent dropdown cache',
    'native_one_page_rows',
    '$onePageCount === 500',
    '$relevantCount() === 501',
    'safe one-page metadata frontier',
    'the exact 501-row refusal mutated CSS or the populated dropdown cache',
    'stale_between_services_refused',
    'recovery certified or mutated a stale cache after the exact first native service',
] as $categoryColorsBoundaryEvidence) {
    duo_check(
        str_contains($deletionCheck, $categoryColorsBoundaryEvidence),
        "the exact live matrix binds Category Colors boundary evidence $categoryColorsBoundaryEvidence"
    );
}
duo_check(
    strpos($deletionCheck, 'duo-equal-priority-alpha')
        < strpos($deletionCheck, 'if [ "${TEC_BOUNDARY_ONLY:-0}" = 1 ]'),
    'both exact TEC boundary artifacts execute Category Colors equal-priority/cache/pagination evidence'
);

$versionMatrix = (string) file_get_contents(
    $root . '/sandbox/tests/certify/certify_version_matrix.sh'
);
foreach ([
    'seed_the_events_calendar_content() {' => 'native seed helper',
    'postdeploy_the_events_calendar_content() {' => 'hostile target helper',
    'check_the_events_calendar_boundary_content() {' => 'product-path boundary helper',
    'for TEC_VERSION in 6.17.2 6.17.3; do' => 'exact supported-artifact loop',
] as $matrixNeedle => $matrixLabel) {
    duo_check_same(
        1,
        substr_count($versionMatrix, $matrixNeedle),
        "the exact matrix has one unshadowed TEC $matrixLabel"
    );
}
foreach ([
    'local TEC_BOUNDARY_ONLY=1',
    '"taxonomies": ["category", "post_tag", "tribe_events_cat"]',
    'TEC_UPGRADE_DEPLOY_OUT=$(wp2 duo deploy --repo=/siterepo 2>&1)',
    'wp2 duo deploy --repo=/siterepo --force-code-drift',
    'TEC_VERSION=6.17.3 check_the_events_calendar_boundary_content',
    'TEC_OUT_OF_RANGE_ARTIFACT=$(fetch_artifact the-events-calendar 6.17.1 cli1)',
] as $matrixEvidence) {
    duo_check(
        str_contains($versionMatrix, $matrixEvidence),
        "the single exact TEC matrix retains evidence $matrixEvidence"
    );
}

foreach ([
    'tec_deactivate_reactivate_cycle "${TEC_EXPECTED_VERSION:-6.17.3}"',
    'schema_version == "5.16.0"',
    'tribe_aggregator_single_process_insert_records',
    'duo_tec_lifecycle_neighbor_cron',
    '__duo_env_schema_version__',
    'LIFECYCLE_BEFORE=$(tec_target_storage_fingerprint)',
    'TEC deactivation mutated authored, derived, Customizer, settings, or Category Colors rows',
    'TEC deactivation did not write the exact env-owned schema-version transition',
    'TEC empty native uninstall mutated authored, derived, Customizer, settings, or Category Colors rows',
    'TEC guard-only uninstall mutated exact inactive runtime residue',
    'missing-code compatibility refusal mutated retained TEC rows',
    'missing-code compatibility refusal mutated canonical target state',
    'TEC_SHA=2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687',
    'TEC exact reinstall did not restore the native capability set',
    'TEC exact reinstall did not converge the exact mixed settings row',
    'diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-tec-final"',
] as $lifecycleEvidence) {
    duo_check(
        str_contains($deletionCheck, $lifecycleEvidence),
        "the standalone TEC lifecycle retains physical/canonical evidence $lifecycleEvidence"
    );
}
duo_check(
    str_contains($deletionCheck, '"tribe_events_pro_customizer"')
        && !str_contains($deletionCheck, '"tribe_events_customizer"'),
    'lifecycle retention fingerprints the exact legacy Customizer fallback row and no nonexistent alias'
);
foreach ([
    '$customizer = tribe(\'customizer\')',
    'get_class($customizer) !== \'Tribe__Customizer\'',
    "\$wp_filter['default_option_tribe_customizer']",
    "(\$fallback_callbacks[0]['function'][0] ?? null) !== \$customizer",
    "(\$fallback_callbacks[0]['function'][1] ?? null) !== 'maybe_fallback_get_option'",
    'TEC native Customizer fallback callback topology was extended or overridden',
    '$customizer_section_services = [',
    'events.views.v2.customizer.global-elements',
    'events.views.v2.customizer.month-view',
    'events.views.v2.customizer.events-bar',
    'events.views.v2.customizer.single-event',
    '$section->setup_defaults()',
    '$section->setup_content_settings()',
    'TEC native Customizer section service identity was overridden',
    'view_selector_background_color_choice',
    'TEC_CUSTOMIZER_SECTION_CONTRACT=',
    '246ad3241459d2208681900b6a39d032dc9daec306ed60bcd9ec404e4a4db493',
    '91736c6fb3dab5b87b1dc0d354469d0d4cf8c31ca9780cadf527a781f7652f83',
] as $customizerLiveEvidence) {
    duo_check(
        str_contains($deletionCheck, $customizerLiveEvidence),
        "both exact artifacts retain native Customizer callback evidence $customizerLiveEvidence"
    );
}
duo_check(
    strpos($deletionCheck, 'if [ "${TEC_BOUNDARY_ONLY:-0}" = 1 ]')
        < strpos($deletionCheck, 'LIFECYCLE_BEFORE=$(tec_target_storage_fingerprint)'),
    'patch-boundary runs stop before the one latest-artifact destructive lifecycle leg'
);
$multisiteRefusal = (string) file_get_contents(
    $root . '/sandbox/tests/live/regress_multisite_refusal.sh'
);
duo_check(
    str_contains($multisiteRefusal, 'wp1 core multisite-convert')
        && str_contains($multisiteRefusal, 'wp1 duo capture --repo=/siterepo')
        && str_contains($multisiteRefusal, 'duo_multisite_refusal_canary'),
    'the adapter-independent platform proof reaches a real network capture and checks zero authored mutation'
);
duo_check(
    in_array(
        'Duo v1 refuses multisite; TEC network/global behavior is outside this single-site adapter.',
        array_column($disposition['unsupported'] ?? [], 'reason'),
        true
    ),
    'TEC binds the platform-wide pre-policy network refusal instead of inventing adapter-local multisite behavior'
);
$tecMultisiteRefusal = (string) file_get_contents(
    $root . '/sandbox/tests/live/regress_the_events_calendar_multisite_refusal.sh'
);
duo_check_same(
    1,
    substr_count($tecMultisiteRefusal, 'for version in 6.17.2 6.17.3; do'),
    'the candidate-bound TEC network fixture has one exact dual-artifact loop'
);
foreach ([
    'DUO_EXPECTED_SOURCE_SHA must bind the exact lowercase 40-character candidate SHA',
    '. conformance/seeds/the-events-calendar.sh',
    'wp1 core multisite-convert',
    'tec_storage_fingerprint',
    'tribe_events_calendar_options',
    'tribe_customizer',
    'tribe_events_pro_customizer',
    'tec_events_category_color_css',
    'widget_tribe-widget-events-list',
    'SELECT * FROM {$wpdb->prefix}tec_events ORDER BY event_id',
    'SELECT * FROM {$wpdb->prefix}tec_occurrences ORDER BY occurrence_id',
] as $tecMultisiteEvidence) {
    duo_check(
        str_contains($tecMultisiteRefusal, $tecMultisiteEvidence),
        "the exact TEC network fixture retains populated refusal evidence $tecMultisiteEvidence"
    );
}
foreach (['capture', 'plan', 'deploy', 'apply'] as $command) {
    duo_check(
        str_contains($tecMultisiteRefusal, 'for command in capture plan deploy apply; do')
            && str_contains($tecMultisiteRefusal, 'wp1 duo "$command" --repo=/siterepo --format=json'),
        "the exact TEC network fixture drives the typed $command refusal through the product command path"
    );
}
duo_check(
    str_contains($tecMultisiteRefusal, '[ "$(tec_storage_fingerprint)" = "$baseline" ]')
        && str_contains($tecMultisiteRefusal, '[ ! -e "$CONF_REPO1/state" ]')
        && str_contains($tecMultisiteRefusal, 'wp1 plugin is-active the-events-calendar'),
    'every TEC network command rechecks physical adapter state, repository absence, and code activation'
);

$regenerator = new TheEventsCalendarRegenerator($policy);
$GLOBALS['tec_readiness_settings_manager'] = Tribe__Settings_Manager::instance();
duo_check_throws(
    static fn() => $regenerator->regenerate_batch([], [[
        'identity' => 'post:' . TEC_EVENT_UUID,
        'local_id' => 77,
        'post_type' => 'tribe_events',
    ]]),
    RuntimeException::class,
    'TEC deletion context refuses before plugin code or database mutation',
    'deletion regeneration is unsupported'
);

$tecEventId = 6100000001;
$GLOBALS['tec_readiness_post_meta'] = [
    $tecEventId => [
        '_EventStartDate' => '2026-11-02 18:30:00',
        '_EventEndDate' => '2026-11-02 21:00:00',
        '_EventStartDateUTC' => '2026-11-02 12:45:00',
        '_EventEndDateUTC' => '2026-11-02 15:15:00',
        '_EventTimezone' => 'Asia/Kathmandu',
        '_EventDuration' => '9000',
    ],
];
$tecDb = FakeWpdb::install();
$eventTable = $tecDb->prefix . 'tec_events';
$occurrenceTable = $tecDb->prefix . 'tec_occurrences';
$postsTable = $tecDb->posts;
$postMetaTable = $tecDb->postmeta;
$optionsTable = $tecDb->options;
$tecDb->seedTable($optionsTable, []);

// NativeActions runs rewrite generation in a fresh WordPress process. This
// fixture invokes that private child boundary directly so TEC's real hook
// graph, recursive marker writes, and request-local shutdown flag are proved
// without weakening the public parent/child receipt transport.
foreach ([
    'permalink_structure',
    'rewrite_rules',
    'tribe_last_generate_rewrite_rules',
    'tribe_last_updated_option',
    'tribe_last_save_post',
] as $name) {
    $tecDb->delete($optionsTable, ['option_name' => $name]);
}
$tecDb->insert($optionsTable, [
    'option_name' => 'permalink_structure',
    'option_value' => '/events-source/%postname%/',
    'autoload' => 'on',
]);
$tecDb->insert($optionsTable, [
    'option_name' => 'rewrite_rules',
    'option_value' => serialize(['^old-events/?$' => 'index.php?old=1']),
    'autoload' => 'on',
]);
$GLOBALS['tec_readiness_tribe_vars'] = [];
$GLOBALS['tec_readiness_expired_transient_deletes'] = 0;
$GLOBALS['tec_readiness_external_object_cache'] = false;
$GLOBALS['tec_readiness_wp_cache_gets'] = 0;
$GLOBALS['tec_readiness_wp_cache_sets'] = 0;
$GLOBALS['tec_readiness_wp_cache_deletes'] = 0;
$GLOBALS['tec_readiness_wp_cache'] = [];
$GLOBALS['wp_rewrite'] = new TecReadinessRewriteRuntime();
$nativeRewriteChild = new ReflectionMethod(\Duo\NativeActions::class, 'flush_rewrite_in_fresh_process');
$nativeRewriteReceipt = $nativeRewriteChild->invoke(null);
duo_check_same(true, $nativeRewriteReceipt['verified'] ?? null, 'TEC-active native rewrite returns only after checked child readback');
duo_check_same(1, $GLOBALS['wp_rewrite']->flushCalls, 'TEC-active native rewrite invokes the fresh soft flush exactly once');
duo_check(
    in_array(
        ['Tribe\\Events\\Views\\V2\\Kitchen_Sink::generate_rules', 'kitchen-sink'],
        $GLOBALS['tec_readiness_rewrite_calls'],
        true
    ),
    'the product rewrite path executes the exact container-resolved Kitchen Sink service'
);
$nativeMarkerRows = [];
foreach ($tecDb->rows($optionsTable) as $row) {
    $name = $row['option_name'] ?? null;
    if (in_array($name, [
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
    ], true)) {
        $nativeMarkerRows[(string) $name] = $row;
    }
}
duo_check_same(
    ['tribe_last_generate_rewrite_rules', 'tribe_last_save_post', 'tribe_last_updated_option'],
    array_keys(array_replace(array_fill_keys([
        'tribe_last_generate_rewrite_rules',
        'tribe_last_save_post',
        'tribe_last_updated_option',
    ], null), $nativeMarkerRows)),
    'the exact generate/update/save CacheListener marker roster is physically observable'
);
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'native rewrite restores an initially absent transient-purge flag before child shutdown');
tribe_cache()->maybe_delete_expired_transients();
duo_check_same(0, $GLOBALS['tec_readiness_expired_transient_deletes'], 'a Duo-created marker never schedules an unreceipted shutdown transient purge');

$beforeFailedRewrite = array_values(array_filter(
    $tecDb->rows($optionsTable),
    static fn(array $row): bool => in_array($row['option_name'] ?? null, [
        'rewrite_rules',
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
    ], true)
));
$GLOBALS['wp_rewrite']->malformedAfterGenerate = true;
$failedRewrite = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $failedRewrite = $failure;
}
duo_check(
    $failedRewrite instanceof RuntimeException
        && str_contains($failedRewrite->getMessage(), 'did not generate a valid rewrite runtime'),
    'a failure after generate+marker writes refuses the TEC-active rewrite receipt'
);
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'post-marker failure restores the local purge-flag preimage');
duo_check(
    $beforeFailedRewrite !== array_values(array_filter(
        $tecDb->rows($optionsTable),
        static fn(array $row): bool => in_array($row['option_name'] ?? null, [
            'rewrite_rules',
            'tribe_last_generate_rewrite_rules',
            'tribe_last_updated_option',
            'tribe_last_save_post',
        ], true)
    )),
    'post-marker failure leaves changed restorable rows visible to the declared database checkpoint'
);
$GLOBALS['wp_rewrite']->malformedAfterGenerate = false;
$retryRewrite = $nativeRewriteChild->invoke(null);
duo_check_same(true, $retryRewrite['verified'] ?? null, 'same-process retry after a partial TEC marker effect converges');
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'retry also restores the exact absent shutdown-flag preimage');

$hostileMarkerCallback = static fn(mixed $value): mixed => $value;
add_filter('update_option_tribe_last_generate_rewrite_rules', $hostileMarkerCallback, 999, 3);
$flushesBeforeHostileMarker = $GLOBALS['wp_rewrite']->flushCalls;
$hostileMarkerFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $hostileMarkerFailure = $failure;
}
remove_filter('update_option_tribe_last_generate_rewrite_rules', $hostileMarkerCallback, 999);
duo_check(
    $hostileMarkerFailure instanceof RuntimeException
        && str_contains($hostileMarkerFailure->getMessage(), 'extended marker option topology'),
    'a hostile marker-specific update callback refuses before native rewrite mutation'
);
duo_check_same($flushesBeforeHostileMarker, $GLOBALS['wp_rewrite']->flushCalls, 'marker-hook refusal executes no rewrite callback');
$postHookRetry = $nativeRewriteChild->invoke(null);
duo_check_same(true, $postHookRetry['verified'] ?? null, 'removing the hostile marker callback permits exact retry');

$triggerCallback = static fn(array $triggers): array => $triggers;
add_filter('tribe_cache_last_occurrence_option_triggers', $triggerCallback, 999, 3);
$flushesBeforeTrigger = $GLOBALS['wp_rewrite']->flushCalls;
$triggerFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $triggerFailure = $failure;
}
remove_filter('tribe_cache_last_occurrence_option_triggers', $triggerCallback, 999);
duo_check(
    $triggerFailure instanceof RuntimeException
        && str_contains($triggerFailure->getMessage(), 'cache-listener trigger filters'),
    'an extension of TEC recursive trigger filters refuses before rewrite mutation'
);
duo_check_same($flushesBeforeTrigger, $GLOBALS['wp_rewrite']->flushCalls, 'trigger-filter refusal executes no native callback');

// Load Woo only at this product boundary: PHP cannot unload functions/classes,
// and the earlier TEC-only cells intentionally prove the supported absent-
// integration topology before this exact normal 11.0.1 co-install is visible.
require_once __DIR__ . '/../../support/tec-woo-option-callbacks.php';
$wooServices = tec_readiness_install_woo_option_callbacks();
$GLOBALS['tec_readiness_woo_calls'] = [];

$wooCustomizerMaterialization = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    ['month_view' => ['grid_lines_color' => '#445566']]
);
duo_check_same(
    null,
    $wooCustomizerMaterialization['failure'],
    'the exact normal Woo callback union permits an existing TEC Customizer option replacement'
);
duo_check_same(
    ['month_view' => ['grid_lines_color' => '#112233']],
    $decodeMixedRow($wooCustomizerMaterialization),
    'the Woo co-install does not alter the exact materialized Customizer value'
);
$wooMainInsertion = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_events_calendar_options',
    ['eventsSlug' => 'woo-events'],
    null
);
duo_check_same(
    null,
    $wooMainInsertion['failure'],
    'the exact normal Woo callback union permits an absent TEC main-option insertion'
);
duo_check_same(
    ['eventsSlug' => 'woo-events'],
    $decodeMixedRow($wooMainInsertion),
    'the Woo co-install does not alter the exact materialized TEC main option'
);

$foreignOptionFeatures = new \Automattic\WooCommerce\Internal\Features\FeaturesController();
remove_action('updated_option', [$wooServices['features'], 'process_updated_option'], 999);
add_action('updated_option', [$foreignOptionFeatures, 'process_updated_option'], 999, 3);
$foreignOptionService = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    ['month_view' => ['grid_lines_color' => '#445566']]
);
remove_action('updated_option', [$foreignOptionFeatures, 'process_updated_option'], 999);
add_action('updated_option', [$wooServices['features'], 'process_updated_option'], 999, 3);
duo_check(
    $foreignOptionService['failure'] instanceof RuntimeException
        && str_contains($foreignOptionService['failure']->getMessage(), 'extended/substituted updated callback'),
    'a same-class foreign Woo service refuses the TEC option writer before storage'
);
duo_check_same(
    ['month_view' => ['grid_lines_color' => '#445566']],
    $decodeMixedRow($foreignOptionService),
    'the foreign Woo service refusal preserves the exact target Customizer bytes'
);

remove_action('added_option', [$wooServices['features'], 'process_added_option'], 999);
remove_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999);
$missingWooAddTopology = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    null
);
add_action('added_option', [$wooServices['features'], 'process_added_option'], 999, 3);
add_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999, 2);
duo_check(
    $missingWooAddTopology['failure'] instanceof RuntimeException
        && str_contains($missingWooAddTopology['failure']->getMessage(), 'incomplete WooCommerce added_option callbacks'),
    'missing Woo add callbacks refuse an absent TEC option before insertion'
);
duo_check_same(null, $missingWooAddTopology['row'], 'the incomplete Woo add topology preserves target absence');

foreach ([
    [$wooServices['features'], 'process_updated_option', 999],
    [$wooServices['synchronizer'], 'process_updated_option', 999],
    [$wooServices['custom_orders'], 'process_updated_option', 999],
    [$wooServices['custom_orders'], 'process_updated_option_fts_index', 999],
] as [$service, $method, $priority]) {
    remove_action('updated_option', [$service, $method], $priority);
}
remove_filter('pre_update_option', [$wooServices['custom_orders'], 'process_pre_update_option'], 999);
remove_action('added_option', [$wooServices['features'], 'process_added_option'], 999);
remove_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999);
$missingWooRuntimeTopology = tec_readiness_materialize_mixed_option(
    $policy,
    'tribe_customizer',
    ['month_view' => ['grid_lines_color' => '#112233']],
    ['month_view' => ['grid_lines_color' => '#445566']]
);
add_action('updated_option', [$wooServices['features'], 'process_updated_option'], 999, 3);
add_action('updated_option', [$wooServices['synchronizer'], 'process_updated_option'], 999, 3);
add_action('updated_option', [$wooServices['custom_orders'], 'process_updated_option'], 999, 3);
add_action('updated_option', [$wooServices['custom_orders'], 'process_updated_option_fts_index'], 999, 3);
add_filter('pre_update_option', [$wooServices['custom_orders'], 'process_pre_update_option'], 999, 3);
add_action('added_option', [$wooServices['features'], 'process_added_option'], 999, 3);
add_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999, 2);
duo_check(
    $missingWooRuntimeTopology['failure'] instanceof RuntimeException
        && str_contains($missingWooRuntimeTopology['failure']->getMessage(), 'incomplete WooCommerce'),
    'a loaded Woo runtime with every option callback removed refuses instead of masquerading as absence'
);
duo_check_same(
    ['month_view' => ['grid_lines_color' => '#445566']],
    $decodeMixedRow($missingWooRuntimeTopology),
    'the callback-free loaded Woo runtime preserves the exact target Customizer bytes'
);

$tecDb->update(
    $optionsTable,
    ['option_value' => serialize(['^woo-existing/?$' => 'index.php?woo=old'])],
    ['option_name' => 'rewrite_rules']
);
$wooUpdatedReceipt = $nativeRewriteChild->invoke(null);
duo_check_same(
    true,
    $wooUpdatedReceipt['verified'] ?? null,
    'the exact normal Woo callback union permits the TEC rewrite product path'
);
foreach ([
    'wc_fix_rewrite_rules',
    'Yoast_Dynamic_Rewrites::sanitize_rewrite_rules_option',
    'Yoast_Dynamic_Rewrites::filter_rewrite_rules_option',
    'PLL_Links_Directory::rewrite_rules',
    'Tribe__Events__Rewrite::filter_generate',
    'Tribe__Events__Rewrite::filter_rewrite_rules_array',
] as $rewriteMethod) {
    duo_check(
        array_filter(
            $GLOBALS['tec_readiness_rewrite_calls'],
            static fn(array $call): bool => ($call[0] ?? null) === $rewriteMethod
        ) !== [],
        "the exact normal co-install executes source-bound rewrite callback $rewriteMethod"
    );
}
duo_check(
    ($wooUpdatedReceipt['after']['rules_hash'] ?? null)
        !== ($wooUpdatedReceipt['after']['runtime_rules_hash'] ?? null),
    'Yoast dynamic rules remain absent from durable storage while the exact effective projection is receipted'
);

$hostilePllCalls = 0;
$hostilePllModify = static function (bool $modify) use (&$hostilePllCalls): bool {
    ++$hostilePllCalls;
    return $modify;
};
add_filter('pll_modify_rewrite_rule', $hostilePllModify, 999, 4);
$flushesBeforeHostilePll = $GLOBALS['wp_rewrite']->flushCalls;
$hostilePllFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $hostilePllFailure = $failure;
}
remove_filter('pll_modify_rewrite_rule', $hostilePllModify, 999);
duo_check(
    $hostilePllFailure instanceof RuntimeException
        && str_contains($hostilePllFailure->getMessage(), 'unsupported open Polylang rewrite filter'),
    'a third-party pll_modify_rewrite_rule callback refuses before rewrite generation'
);
duo_check_same(0, $hostilePllCalls, 'the refused open Polylang callback never executes');
duo_check_same(
    $flushesBeforeHostilePll,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the open Polylang callback refusal performs no native mutation'
);
duo_check_same(
    true,
    $nativeRewriteChild->invoke(null)['verified'] ?? null,
    'removing the third-party Polylang callback permits exact retry'
);

$hostileRosterCalls = 0;
$hostilePllRoster = static function (array $types) use (&$hostileRosterCalls): array {
    ++$hostileRosterCalls;
    return [...$types, 'foreign'];
};
add_filter('pll_rewrite_rules', $hostilePllRoster, 999, 1);
$flushesBeforeHostileRoster = $GLOBALS['wp_rewrite']->flushCalls;
$hostileRosterFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $hostileRosterFailure = $failure;
}
remove_filter('pll_rewrite_rules', $hostilePllRoster, 999);
duo_check(
    $hostileRosterFailure instanceof RuntimeException
        && str_contains($hostileRosterFailure->getMessage(), 'unsupported open Polylang rewrite filter'),
    'a third-party pll_rewrite_rules type extension refuses before roster evaluation'
);
duo_check_same(0, $hostileRosterCalls, 'the refused Polylang roster callback never executes');
duo_check_same(
    $flushesBeforeHostileRoster,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the open type-roster refusal performs no native mutation'
);

$customRuleCalls = 0;
$hostileCustomRule = static function (array $rules) use (&$customRuleCalls): array {
    ++$customRuleCalls;
    return $rules;
};
add_filter('tribe_events_rewrite_rules_custom', $hostileCustomRule, 999, 1);
$flushesBeforeCustomRule = $GLOBALS['wp_rewrite']->flushCalls;
$customRuleFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $customRuleFailure = $failure;
}
remove_filter('tribe_events_rewrite_rules_custom', $hostileCustomRule, 999);
duo_check(
    $customRuleFailure instanceof RuntimeException
        && str_contains(
            $customRuleFailure->getMessage(),
            "extended or substituted 'tribe_events_rewrite_rules_custom'"
        ),
    'an extension of TEC custom rewrite rules refuses before native generation'
);
duo_check_same(0, $customRuleCalls, 'the refused TEC custom-rule callback never executes');
duo_check_same(
    $flushesBeforeCustomRule,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the TEC custom-rule refusal performs no native mutation'
);

$extensionView = $GLOBALS['tec_readiness_views_manager']->register_view('extension-view');
$viewRouteCallsBefore = count(array_filter(
    $GLOBALS['tec_readiness_rewrite_calls'],
    static fn(array $call): bool => ($call[0] ?? null)
        === 'Tribe\\Events\\Views\\V2\\View_Register::filter_add_routes'
));
$flushesBeforeExtensionView = $GLOBALS['wp_rewrite']->flushCalls;
$extensionViewFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $extensionViewFailure = $failure;
}
remove_action('tribe_events_pre_rewrite', [$extensionView, 'filter_add_routes'], 5);
$GLOBALS['tec_readiness_views_manager']->unregister_view('extension-view');
duo_check(
    $extensionViewFailure instanceof RuntimeException
        && str_contains($extensionViewFailure->getMessage(), 'extended TEC view registry'),
    'an add-on TEC view registration refuses before native rewrite generation'
);
duo_check_same(
    $viewRouteCallsBefore,
    count(array_filter(
        $GLOBALS['tec_readiness_rewrite_calls'],
        static fn(array $call): bool => ($call[0] ?? null)
            === 'Tribe\\Events\\Views\\V2\\View_Register::filter_add_routes'
    )),
    'the refused add-on view route callback never executes'
);
duo_check_same(
    $flushesBeforeExtensionView,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the add-on view refusal performs no native mutation'
);

foreach ($exactTecInnerHooks as $innerHook) {
    $innerCalls = 0;
    $hostileInner = static function (mixed $value = null) use (&$innerCalls): mixed {
        ++$innerCalls;
        return $value;
    };
    add_filter($innerHook, $hostileInner, 999, 1);
    $flushesBeforeInner = $GLOBALS['wp_rewrite']->flushCalls;
    $innerFailure = null;
    try {
        $nativeRewriteChild->invoke(null);
    } catch (Throwable $failure) {
        $innerFailure = $failure;
    }
    remove_filter($innerHook, $hostileInner, 999);
    duo_check(
        $innerFailure instanceof RuntimeException
            && str_contains($innerFailure->getMessage(), "'$innerHook'"),
        "an extension on nested TEC rewrite hook $innerHook refuses before native generation"
    );
    duo_check_same(0, $innerCalls, "the refused nested $innerHook callback never executes");
    duo_check_same(
        $flushesBeforeInner,
        $GLOBALS['wp_rewrite']->flushCalls,
        "the nested $innerHook refusal performs no native mutation"
    );
}

$originalKitchenSink = $GLOBALS['tec_readiness_kitchen_sink'];
$GLOBALS['tec_readiness_kitchen_sink'] = new stdClass();
$flushesBeforeKitchenSink = $GLOBALS['wp_rewrite']->flushCalls;
$kitchenSinkFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $kitchenSinkFailure = $failure;
}
$GLOBALS['tec_readiness_kitchen_sink'] = $originalKitchenSink;
duo_check(
    $kitchenSinkFailure instanceof RuntimeException
        && str_contains($kitchenSinkFailure->getMessage(), 'substituted TEC rewrite service'),
    'a container-substituted Kitchen Sink refuses before its same-output route generator can execute'
);
duo_check_same(
    $flushesBeforeKitchenSink,
    $GLOBALS['wp_rewrite']->flushCalls,
    'Kitchen Sink substitution performs no native rewrite mutation'
);
duo_check_same(
    true,
    $nativeRewriteChild->invoke(null)['verified'] ?? null,
    'restoring the exact Kitchen Sink singleton permits same-process retry'
);

$qrBaseProperty = new ReflectionProperty(\TEC\Events\QR\Routes::class, 'route_base');
$originalQrBase = $qrBaseProperty->getValue($GLOBALS['tec_readiness_qr_routes']);
$qrBaseProperty->setValue($GLOBALS['tec_readiness_qr_routes'], 'foreign-route');
$flushesBeforeQrState = $GLOBALS['wp_rewrite']->flushCalls;
$qrStateFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $qrStateFailure = $failure;
}
$qrBaseProperty->setValue($GLOBALS['tec_readiness_qr_routes'], $originalQrBase);
duo_check(
    $qrStateFailure instanceof RuntimeException
        && str_contains($qrStateFailure->getMessage(), 'substituted TEC QR route state'),
    'a previously filtered non-default QR route refuses before rewrite generation'
);
duo_check_same(
    $flushesBeforeQrState,
    $GLOBALS['wp_rewrite']->flushCalls,
    'non-default cached QR route state performs no native mutation'
);

$hostileCoreRuleCalls = 0;
$hostileCoreRule = static function (array $rules) use (&$hostileCoreRuleCalls): array {
    ++$hostileCoreRuleCalls;
    return $rules;
};
add_filter('post_rewrite_rules', $hostileCoreRule, 999, 1);
$flushesBeforeCoreRule = $GLOBALS['wp_rewrite']->flushCalls;
$coreRuleFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $coreRuleFailure = $failure;
}
remove_filter('post_rewrite_rules', $hostileCoreRule, 999);
duo_check(
    $coreRuleFailure instanceof RuntimeException
        && str_contains($coreRuleFailure->getMessage(), "'post_rewrite_rules'"),
    'an unreviewed fixed WordPress rewrite callback refuses before generation'
);
duo_check_same(0, $hostileCoreRuleCalls, 'the refused fixed rewrite callback never executes');
duo_check_same(
    $flushesBeforeCoreRule,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the fixed rewrite callback refusal performs no native mutation'
);

$originalPermastructs = $GLOBALS['wp_rewrite']->extra_permastructs;
$GLOBALS['wp_rewrite']->extra_permastructs['foreign'] = [];
$dynamicRuleCalls = 0;
$hostileDynamicRule = static function (array $rules) use (&$dynamicRuleCalls): array {
    ++$dynamicRuleCalls;
    return $rules;
};
add_filter('foreign_rewrite_rules', $hostileDynamicRule, 999, 1);
$flushesBeforeDynamicRule = $GLOBALS['wp_rewrite']->flushCalls;
$dynamicRuleFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $dynamicRuleFailure = $failure;
}
remove_filter('foreign_rewrite_rules', $hostileDynamicRule, 999);
$GLOBALS['wp_rewrite']->extra_permastructs = $originalPermastructs;
duo_check(
    $dynamicRuleFailure instanceof RuntimeException
        && str_contains($dynamicRuleFailure->getMessage(), "'foreign_rewrite_rules'"),
    'an unreviewed callback on a bounded runtime permastruct refuses before generation'
);
duo_check_same(0, $dynamicRuleCalls, 'the refused dynamic permastruct callback never executes');
duo_check_same(
    $flushesBeforeDynamicRule,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the dynamic permastruct callback refusal performs no native mutation'
);

$GLOBALS['wp_rewrite']->extra_permastructs['product'] = ['nested' => []];
$malformedPermastructFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $malformedPermastructFailure = $failure;
}
$GLOBALS['wp_rewrite']->extra_permastructs = $originalPermastructs;
duo_check(
    $malformedPermastructFailure instanceof RuntimeException
        && str_contains($malformedPermastructFailure->getMessage(), 'malformed permastruct roster'),
    'a nested or executable permastruct definition refuses before native generation'
);

$driftedPermastructs = $originalPermastructs;
$driftedPermastructs['product'] = ['struct' => '/drifted/%product%/', 'ep_mask' => 1];
$GLOBALS['tec_readiness_rewrite_drift_permastructs'] = $driftedPermastructs;
$permastructDriftFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $permastructDriftFailure = $failure;
}
$GLOBALS['wp_rewrite']->extra_permastructs = $originalPermastructs;
duo_check(
    $permastructDriftFailure instanceof RuntimeException
        && str_contains($permastructDriftFailure->getMessage(), 'could not restore the proven shipped-plugin runtime')
        && $permastructDriftFailure->getPrevious() instanceof RuntimeException
        && str_contains($permastructDriftFailure->getPrevious()->getMessage(), 'service drift'),
    'same-roster permastruct value drift during native callbacks refuses the receipt'
);
duo_check_same(
    true,
    $nativeRewriteChild->invoke(null)['verified'] ?? null,
    'restoring the exact permastruct state permits same-process retry'
);

$polylangTypesProperty = new ReflectionProperty(PLL_Links_Directory::class, 'types');
$originalPolylangTypes = $polylangTypesProperty->getValue($wooServices['polylang_links']);
$polylangTypesProperty->setValue($wooServices['polylang_links'], ['bad/type']);
$flushesBeforeMalformedRoster = $GLOBALS['wp_rewrite']->flushCalls;
$malformedRosterFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $malformedRosterFailure = $failure;
}
$polylangTypesProperty->setValue($wooServices['polylang_links'], $originalPolylangTypes);
duo_check(
    $malformedRosterFailure instanceof RuntimeException
        && str_contains($malformedRosterFailure->getMessage(), 'malformed Polylang rewrite type roster'),
    'a malformed target-derived Polylang type refuses before generation'
);
duo_check_same(
    $flushesBeforeMalformedRoster,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the malformed Polylang roster performs no native mutation'
);
$polylangTypesProperty->setValue($wooServices['polylang_links'], ['date', 'date']);
$flushesBeforeDuplicateRoster = $GLOBALS['wp_rewrite']->flushCalls;
$duplicateRosterFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $duplicateRosterFailure = $failure;
}
$polylangTypesProperty->setValue($wooServices['polylang_links'], $originalPolylangTypes);
duo_check(
    $duplicateRosterFailure instanceof RuntimeException
        && str_contains($duplicateRosterFailure->getMessage(), 'duplicate Polylang rewrite type'),
    'a duplicate target-derived Polylang type refuses before generation'
);
duo_check_same(
    $flushesBeforeDuplicateRoster,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the duplicate Polylang roster performs no native mutation'
);

$yoastTopProperty = new ReflectionProperty(Yoast_Dynamic_Rewrites::class, 'extra_rules_top');
$originalYoastTop = $yoastTopProperty->getValue($wooServices['yoast']);
$yoastTopProperty->setValue($wooServices['yoast'], ['^large/?$' => str_repeat('x', 16385)]);
$flushesBeforeMalformedYoast = $GLOBALS['wp_rewrite']->flushCalls;
$malformedYoastFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $malformedYoastFailure = $failure;
}
$yoastTopProperty->setValue($wooServices['yoast'], $originalYoastTop);
duo_check(
    $malformedYoastFailure instanceof RuntimeException
        && str_contains($malformedYoastFailure->getMessage(), 'malformed Yoast rewrite state'),
    'an oversized individual Yoast rewrite value refuses before generation'
);
duo_check_same(
    $flushesBeforeMalformedYoast,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the malformed Yoast state performs no native mutation'
);
duo_check_same(
    true,
    $nativeRewriteChild->invoke(null)['verified'] ?? null,
    'restoring exact TEC, Polylang and Yoast rewrite state permits bounded retry'
);

$foreignLinks = new PLL_Links_Directory($wooServices['polylang_types']);
remove_filter('rewrite_rules_array', [$wooServices['polylang_links'], 'rewrite_rules'], 10);
add_filter('rewrite_rules_array', [$foreignLinks, 'rewrite_rules'], 10, 1);
$flushesBeforeForeignLinks = $GLOBALS['wp_rewrite']->flushCalls;
$foreignLinksFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $foreignLinksFailure = $failure;
}
remove_filter('rewrite_rules_array', [$foreignLinks, 'rewrite_rules'], 10);
add_filter('rewrite_rules_array', [$wooServices['polylang_links'], 'rewrite_rules'], 10, 1);
duo_check(
    $foreignLinksFailure instanceof RuntimeException
        && str_contains($foreignLinksFailure->getMessage(), "extended or substituted 'rewrite_rules_array'"),
    'a same-class foreign Polylang links callback refuses before rewrite generation'
);
duo_check_same(
    $flushesBeforeForeignLinks,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the substituted Polylang links callback performs no native mutation'
);

$missingType = $wooServices['polylang_types'][0];
remove_filter($missingType . '_rewrite_rules', [$wooServices['polylang_links'], 'rewrite_rules'], 10);
$flushesBeforeMissingType = $GLOBALS['wp_rewrite']->flushCalls;
$missingTypeFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $missingTypeFailure = $failure;
}
add_filter($missingType . '_rewrite_rules', [$wooServices['polylang_links'], 'rewrite_rules'], 10, 1);
duo_check(
    $missingTypeFailure instanceof RuntimeException
        && str_contains($missingTypeFailure->getMessage(), "incomplete '$missingType" . "_rewrite_rules'"),
    'a missing target-derived Polylang hook refuses before rewrite generation'
);
duo_check_same(
    $flushesBeforeMissingType,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the incomplete dynamic hook roster performs no native mutation'
);

$foreignYoast = (new ReflectionClass(Yoast_Dynamic_Rewrites::class))->newInstanceWithoutConstructor();
$foreignYoast->wp_rewrite = $GLOBALS['wp_rewrite'];
remove_filter('option_rewrite_rules', [$wooServices['yoast'], 'filter_rewrite_rules_option'], 10);
add_filter('option_rewrite_rules', [$foreignYoast, 'filter_rewrite_rules_option'], 10, 1);
$flushesBeforeForeignYoast = $GLOBALS['wp_rewrite']->flushCalls;
$foreignYoastFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $foreignYoastFailure = $failure;
}
remove_filter('option_rewrite_rules', [$foreignYoast, 'filter_rewrite_rules_option'], 10);
add_filter('option_rewrite_rules', [$wooServices['yoast'], 'filter_rewrite_rules_option'], 10, 1);
duo_check(
    $foreignYoastFailure instanceof RuntimeException
        && str_contains($foreignYoastFailure->getMessage(), 'substituted Yoast rewrite services'),
    'a same-class foreign Yoast option callback refuses before rewrite generation'
);
duo_check_same(
    $flushesBeforeForeignYoast,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the substituted Yoast callback performs no native mutation'
);

$foreignTecRewrite = (new ReflectionClass(Tribe__Events__Rewrite::class))->newInstanceWithoutConstructor();
remove_filter('generate_rewrite_rules', [$GLOBALS['tec_readiness_events_rewrite'], 'filter_generate'], 10);
add_filter('generate_rewrite_rules', [$foreignTecRewrite, 'filter_generate'], 10, 1);
$flushesBeforeForeignTec = $GLOBALS['wp_rewrite']->flushCalls;
$foreignTecFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $foreignTecFailure = $failure;
}
remove_filter('generate_rewrite_rules', [$foreignTecRewrite, 'filter_generate'], 10);
add_filter('generate_rewrite_rules', [$GLOBALS['tec_readiness_events_rewrite'], 'filter_generate'], 10, 1);
duo_check(
    $foreignTecFailure instanceof RuntimeException
        && str_contains($foreignTecFailure->getMessage(), 'substituted plugin rewrite service'),
    'a same-class foreign TEC rewrite singleton callback refuses before generation'
);
duo_check_same(
    $flushesBeforeForeignTec,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the substituted TEC rewrite singleton performs no native mutation'
);
duo_check_same(
    true,
    $nativeRewriteChild->invoke(null)['verified'] ?? null,
    'restoring every exact co-install rewrite callback permits a same-process retry'
);
$wooMarkerNames = [
    'tribe_last_generate_rewrite_rules',
    'tribe_last_updated_option',
    'tribe_last_save_post',
];
$wooUpdatedMethods = [
    \Automattic\WooCommerce\Internal\Features\FeaturesController::class
        . '::process_updated_option',
    \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
        . '::process_updated_option',
    \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
        . '::process_updated_option',
    \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
        . '::process_updated_option_fts_index',
];
foreach ($wooUpdatedMethods as $method) {
    foreach ($wooMarkerNames as $markerName) {
        duo_check(
            in_array([$method, $markerName], $GLOBALS['tec_readiness_woo_calls'], true),
            "the exact normal Woo callback $method executes its source-proven no-op for $markerName"
        );
    }
}
duo_check_same(
    false,
    tribe_isset_var('should_delete_expired_transients'),
    'the accepted Woo co-install does not weaken TEC local purge-flag restoration'
);

foreach ($wooMarkerNames as $markerName) {
    $tecDb->delete($optionsTable, ['option_name' => $markerName]);
}
$tecDb->update(
    $optionsTable,
    ['option_value' => serialize(['^woo-absent/?$' => 'index.php?woo=old'])],
    ['option_name' => 'rewrite_rules']
);
$GLOBALS['tec_readiness_woo_calls'] = [];
$wooAddedReceipt = $nativeRewriteChild->invoke(null);
duo_check_same(true, $wooAddedReceipt['verified'] ?? null, 'the Woo co-install permits absent marker add paths');
foreach ([
    \Automattic\WooCommerce\Internal\Features\FeaturesController::class
        . '::process_added_option',
    \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
        . '::process_added_option',
] as $method) {
    foreach ($wooMarkerNames as $markerName) {
        duo_check(
            in_array([$method, $markerName], $GLOBALS['tec_readiness_woo_calls'], true),
            "the exact normal Woo add callback $method executes its source-proven no-op for $markerName"
        );
    }
}

$foreignFeatures = new \Automattic\WooCommerce\Internal\Features\FeaturesController();
remove_action('updated_option', [$wooServices['features'], 'process_updated_option'], 999);
add_action('updated_option', [$foreignFeatures, 'process_updated_option'], 999, 3);
$flushesBeforeForeignWoo = $GLOBALS['wp_rewrite']->flushCalls;
$foreignWooFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $foreignWooFailure = $failure;
}
remove_action('updated_option', [$foreignFeatures, 'process_updated_option'], 999);
add_action('updated_option', [$wooServices['features'], 'process_updated_option'], 999, 3);
duo_check(
    $foreignWooFailure instanceof RuntimeException
        && str_contains($foreignWooFailure->getMessage(), 'extended or substituted updated-option callbacks'),
    'a same-class foreign Woo callback refuses before TEC rewrite mutation'
);
duo_check_same(
    $flushesBeforeForeignWoo,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the same-class Woo callback refusal executes no native rewrite callback'
);

remove_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999);
$flushesBeforeMissingWoo = $GLOBALS['wp_rewrite']->flushCalls;
$missingWooFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $missingWooFailure = $failure;
}
add_action('added_option', [$wooServices['synchronizer'], 'process_added_option'], 999, 2);
duo_check(
    $missingWooFailure instanceof RuntimeException
        && str_contains($missingWooFailure->getMessage(), 'incomplete WooCommerce added_option callbacks'),
    'an incomplete normal Woo add-option topology refuses before native mutation'
);
duo_check_same(
    $flushesBeforeMissingWoo,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the incomplete Woo topology does not start rewrite generation'
);

$settingsTracker = new WC_Settings_Tracking();
add_action('update_option', [$settingsTracker, 'track_setting_change'], 10, 3);
$flushesBeforeTracking = $GLOBALS['wp_rewrite']->flushCalls;
$trackingFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $trackingFailure = $failure;
}
remove_action('update_option', [$settingsTracker, 'track_setting_change'], 10);
duo_check(
    $trackingFailure instanceof RuntimeException
        && str_contains($trackingFailure->getMessage(), 'extended marker option topology'),
    'request-conditional Woo settings tracking remains unsupported and refuses before mutation'
);
duo_check_same(
    $flushesBeforeTracking,
    $GLOBALS['wp_rewrite']->flushCalls,
    'the request-conditional settings callback cannot execute rewrite or marker effects'
);
$trackingRetry = $nativeRewriteChild->invoke(null);
duo_check_same(true, $trackingRetry['verified'] ?? null, 'removing request-conditional tracking permits retry');

$canonicalWooContainer = $wooServices['container'];
$GLOBALS['tec_readiness_rewrite_drift_woo_container'] = new \Automattic\WooCommerce\Container([
    get_class($wooServices['features']) => $wooServices['features'],
    get_class($wooServices['synchronizer']) => $wooServices['synchronizer'],
    get_class($wooServices['custom_orders']) => $wooServices['custom_orders'],
]);
$wooDriftFailure = null;
try {
    $nativeRewriteChild->invoke(null);
} catch (Throwable $failure) {
    $wooDriftFailure = $failure;
}
$GLOBALS['wc_container'] = $canonicalWooContainer;
duo_check(
    $wooDriftFailure instanceof RuntimeException
        && str_contains($wooDriftFailure->getMessage(), 'could not restore the proven shipped-plugin runtime'),
    'Woo container drift after prepare refuses the post-effect receipt during exact restoration'
);
$wooDriftRetry = $nativeRewriteChild->invoke(null);
duo_check_same(true, $wooDriftRetry['verified'] ?? null, 'restoring the exact Woo container permits retry');

tec_readiness_remove_woo_option_callbacks($wooServices);

$tecEventColumns = [
    'event_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
    'post_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'start_date' => ['Type' => 'varchar(19)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'end_date' => ['Type' => 'varchar(19)', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
    'timezone' => ['Type' => 'varchar(30)', 'Null' => 'NO', 'Default' => 'UTC', 'Extra' => ''],
    'start_date_utc' => ['Type' => 'varchar(19)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'end_date_utc' => ['Type' => 'varchar(19)', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
    'duration' => ['Type' => 'mediumint(30)', 'Null' => 'YES', 'Default' => '7200', 'Extra' => ''],
    'updated_at' => [
        'Type' => 'timestamp',
        'Null' => 'YES',
        'Default' => 'current_timestamp()',
        'Extra' => 'on update current_timestamp()',
    ],
    'hash' => ['Type' => 'varchar(40)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
];
$tecOccurrenceColumns = [
    'occurrence_id' => [
        'Type' => 'bigint(20) unsigned',
        'Null' => 'NO',
        'Default' => null,
        'Extra' => 'auto_increment',
    ],
    'event_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'post_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'start_date' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'start_date_utc' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'end_date' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'end_date_utc' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'duration' => ['Type' => 'mediumint(30)', 'Null' => 'YES', 'Default' => '7200', 'Extra' => ''],
    'hash' => ['Type' => 'varchar(40)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'updated_at' => [
        'Type' => 'timestamp',
        'Null' => 'YES',
        'Default' => 'current_timestamp()',
        'Extra' => 'on update current_timestamp()',
    ],
];
$tecEventIndexes = [
    [
        'Key_name' => 'PRIMARY',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'event_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
    [
        'Key_name' => 'post_id',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'post_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
];
$postsIndexes = [[
    'Key_name' => 'PRIMARY',
    'Non_unique' => 0,
    'Seq_in_index' => 1,
    'Column_name' => 'ID',
    'Sub_part' => null,
    'Index_type' => 'BTREE',
]];
$postMetaIndexes = [
    [
        'Key_name' => 'PRIMARY',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'meta_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
    [
        'Key_name' => 'post_id',
        'Non_unique' => 1,
        'Seq_in_index' => 1,
        'Column_name' => 'post_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
];
$optionColumns = [
    'option_id' => [
        'Type' => 'bigint(20) unsigned',
        'Null' => 'NO',
        'Default' => null,
        'Extra' => 'auto_increment',
    ],
    'option_name' => ['Type' => 'varchar(191)', 'Null' => 'NO', 'Default' => '', 'Extra' => ''],
    'option_value' => ['Type' => 'longtext', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
    'autoload' => ['Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => 'yes', 'Extra' => ''],
];
$optionIndexes = [
    [
        'Key_name' => 'PRIMARY',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'option_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
    [
        'Key_name' => 'option_name',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'option_name',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
];
$tecOccurrenceIndexes = [
    [
        'Key_name' => 'PRIMARY',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'occurrence_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
    [
        'Key_name' => 'event_id',
        'Non_unique' => 1,
        'Seq_in_index' => 1,
        'Column_name' => 'event_id',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
    [
        'Key_name' => 'hash',
        'Non_unique' => 0,
        'Seq_in_index' => 1,
        'Column_name' => 'hash',
        'Sub_part' => null,
        'Index_type' => 'BTREE',
    ],
];
foreach ([
    'idx_wp_tec_occurrences_post_id_dates' => ['post_id', 'end_date', 'start_date'],
    'idx_wp_tec_occurrences_post_id_dates_utc' => ['post_id', 'end_date_utc', 'start_date_utc'],
] as $indexName => $columns) {
    foreach ($columns as $offset => $column) {
        $tecOccurrenceIndexes[] = [
            'Key_name' => $indexName,
            'Non_unique' => 1,
            'Seq_in_index' => $offset + 1,
            'Column_name' => $column,
            'Sub_part' => null,
            'Index_type' => 'BTREE',
        ];
    }
}
$configureTecSchema = static function () use (
    $tecDb,
    $eventTable,
    $occurrenceTable,
    $postsTable,
    $postMetaTable,
    $optionsTable,
    $tecEventColumns,
    $tecOccurrenceColumns,
    $tecEventIndexes,
    $tecOccurrenceIndexes,
    $postsIndexes,
    $postMetaIndexes,
    $optionColumns,
    $optionIndexes
): void {
    $tecDb->setColumnDefinitions($eventTable, $tecEventColumns)
        ->setColumnDefinitions($occurrenceTable, $tecOccurrenceColumns)
        ->setIndexes($eventTable, $tecEventIndexes)
        ->setIndexes($occurrenceTable, $tecOccurrenceIndexes)
        ->setIndexes($postsTable, $postsIndexes)
        ->setIndexes($postMetaTable, $postMetaIndexes)
        ->setColumnDefinitions($optionsTable, $optionColumns)
        ->setIndexes($optionsTable, $optionIndexes)
        ->setTableEngine($eventTable, 'InnoDB')
        ->setTableEngine($occurrenceTable, 'InnoDB')
        ->setTableEngine($postsTable, 'InnoDB')
        ->setTableEngine($postMetaTable, 'InnoDB')
        ->setTableEngine($optionsTable, 'InnoDB');
};
$configureTecSchema();
$tecDb->setPrimaryKey($eventTable, 'event_id')
    ->setPrimaryKey($occurrenceTable, 'occurrence_id')
    ->setUniqueKey($optionsTable, ['option_name']);
$resetTecDerived = static function () use (
    $tecDb,
    $eventTable,
    $occurrenceTable,
    $postsTable,
    $postMetaTable,
    $optionsTable,
    $tecEventId
): void {
    $tecDb->setConnectionId(1)->setTransactionIsolation('REPEATABLE-READ');
    $tecDb->seedTable($postsTable, [
        ['ID' => $tecEventId, 'post_type' => 'tribe_events'],
        ['ID' => 6100000099, 'post_type' => 'tribe_events'],
    ]);
    $postMetaRows = [];
    $metaId = 9100000001;
    foreach ($GLOBALS['tec_readiness_post_meta'] as $postId => $byKey) {
        foreach ($byKey as $metaKey => $metaValue) {
            $postMetaRows[] = [
                'meta_id' => $metaId++,
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ];
        }
    }
    $tecDb->seedTable($postMetaTable, $postMetaRows);
    $tecDb->seedTable($optionsTable, [
        [
            'option_id' => 11,
            'option_name' => 'tribe_last_save_post',
            'option_value' => '1700000000.1234',
            'autoload' => 'yes',
        ],
        [
            'option_id' => 12,
            'option_name' => 'target_runtime_neighbor',
            'option_value' => 'preserve-me',
            'autoload' => 'no',
        ],
    ]);
    $tecDb->seedTable($eventTable, [
        [
            'event_id' => 7000000001,
            'post_id' => $tecEventId,
            'start_date' => '1999-01-01 00:00:00',
            'end_date' => '1999-01-01 00:30:00',
            'timezone' => 'UTC',
            'start_date_utc' => '1999-01-01 00:00:00',
            'end_date_utc' => '1999-01-01 00:30:00',
            'duration' => 1800,
            'updated_at' => '1999-01-01 00:00:00',
            'hash' => 'stale-event-hash',
        ],
        [
            'event_id' => 7000000099,
            'post_id' => 6100000099,
            'start_date' => '2028-01-01 00:00:00',
            'end_date' => '2028-01-01 01:00:00',
            'timezone' => 'UTC',
            'start_date_utc' => '2028-01-01 00:00:00',
            'end_date_utc' => '2028-01-01 01:00:00',
            'duration' => 3600,
            'updated_at' => '2028-01-01 00:00:00',
            'hash' => 'target-runtime-event',
        ],
    ]);
    $tecDb->seedTable($occurrenceTable, [
        [
            'occurrence_id' => 8000000001,
            'event_id' => 7000000001,
            'post_id' => $tecEventId,
            'start_date' => '1999-01-01 00:00:00',
            'end_date' => '1999-01-01 00:30:00',
            'start_date_utc' => '1999-01-01 00:00:00',
            'end_date_utc' => '1999-01-01 00:30:00',
            'duration' => 1800,
            'updated_at' => '1999-01-01 00:00:00',
            'hash' => 'stale-occurrence-hash',
        ],
        [
            'occurrence_id' => 8000000099,
            'event_id' => 7000000099,
            'post_id' => 6100000099,
            'start_date' => '2028-01-01 00:00:00',
            'end_date' => '2028-01-01 01:00:00',
            'start_date_utc' => '2028-01-01 00:00:00',
            'end_date_utc' => '2028-01-01 01:00:00',
            'duration' => 3600,
            'updated_at' => '2028-01-01 00:00:00',
            'hash' => 'target-runtime-occurrence',
        ],
    ]);
    $tecDb->onQuery(null)->clearTransactionOutcomes();
    $tecDb->resetLog();
    $GLOBALS['tec_readiness_external_object_cache'] = false;
    $GLOBALS['tec_readiness_container_bindings'] = [];
    $GLOBALS['tec_readiness_container_binding_signals'] = [];
    $GLOBALS['tec_readiness_configuration'] = [];
    $GLOBALS['tec_readiness_regen_native_db_reads'] = true;
    $GLOBALS['tec_readiness_regen_mode'] = 'ok';
    $GLOBALS['tec_readiness_regen_calls'] = [];
    $GLOBALS['tec_readiness_event_data_calls'] = [];
    $GLOBALS['tec_readiness_wp_cache'] = [];
    $GLOBALS['tec_readiness_wp_cache_gets'] = 0;
    $GLOBALS['tec_readiness_wp_cache_get_mode'] = '';
    $GLOBALS['tec_readiness_wp_cache_sets'] = 0;
    $GLOBALS['tec_readiness_wp_cache_deletes'] = 0;
    $GLOBALS['tec_readiness_wp_cache_delete_mode'] = '';
    $GLOBALS['tec_readiness_wp_cache_group_flushes'] = 0;
    $GLOBALS['tec_readiness_wp_cache_group_flush_mode'] = '';
    $GLOBALS['tec_readiness_wp_cache_group_support'] = true;
    $GLOBALS['tec_readiness_tribe_vars'] = [];
    $GLOBALS['tec_readiness_log_dispatches'] = 0;
    $GLOBALS['tec_readiness_expired_transient_deletes'] = 0;
    $GLOBALS['tec_readiness_native_disruption'] = null;
};
$tecFailure = static function (callable $call): string {
    try {
        $call();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    duo_check(false, 'expected TEC regenerator failure did not occur');
    return '';
};
$setTecSourceMeta = static function (string $key, string $value) use (
    $tecDb,
    $postMetaTable,
    $tecEventId
): void {
    $GLOBALS['tec_readiness_post_meta'][$tecEventId][$key] = $value;
    duo_check_same(
        1,
        $tecDb->update(
            $postMetaTable,
            ['meta_value' => $value],
            ['post_id' => $tecEventId, 'meta_key' => $key]
        ),
        "the physical source fixture updates exactly one $key row"
    );
    wp_cache_delete($tecEventId, 'post_meta');
};
$tecPhysicalState = static function () use (
    $tecDb,
    $eventTable,
    $occurrenceTable,
    $optionsTable
): array {
    return [
        'events' => $tecDb->rows($eventTable),
        'occurrences' => $tecDb->rows($occurrenceTable),
        'options' => $tecDb->rows($optionsTable),
    ];
};

$assertTecSchemaRefusal = static function (
    string $label,
    callable $mutateSchema,
    string $expectedMessage
) use (
    $tecDb,
    $regenerator,
    $tecEventId,
    $eventTable,
    $occurrenceTable,
    $configureTecSchema,
    $resetTecDerived,
    $tecFailure
): void {
    $tecDb->prefix = 'wp_';
    $configureTecSchema();
    $resetTecDerived();
    $before = [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)];
    $mutateSchema();
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    $tecDb->prefix = 'wp_';
    duo_check(
        str_contains($failure, $expectedMessage) && strlen($failure) < 300,
        "$label refuses with a bounded schema diagnostic"
    );
    duo_check_same(
        $before,
        [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)],
        "$label refuses without changing either native custom table"
    );
    duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], "$label refuses before native event data reads");
    duo_check_same([], $GLOBALS['tec_readiness_regen_calls'], "$label refuses before the native upsert boundary");
    $configureTecSchema();
};

$assertTecSchemaRefusal(
    'an unsafe table prefix',
    static function () use ($tecDb): void {
        $tecDb->prefix = "wp_bad`\n";
    },
    'requires one safe WordPress table prefix'
);
$assertTecSchemaRefusal(
    'a missing prefix-specific table',
    static function () use ($tecDb): void {
        $tecDb->prefix = 'wp_2_';
    },
    'rejected the tec_events table identity or engine'
);
$assertTecSchemaRefusal(
    'a non-transactional event table',
    static fn() => $tecDb->setTableEngine($eventTable, 'MyISAM'),
    'rejected the tec_events table identity or engine'
);
$missingEventColumns = $tecEventColumns;
unset($missingEventColumns['hash']);
$assertTecSchemaRefusal(
    'a missing event column',
    static fn() => $tecDb->setColumnDefinitions($eventTable, $missingEventColumns),
    'rejected the tec_events column count'
);
$extraEventColumns = $tecEventColumns + [
    'extension_payload' => ['Type' => 'longtext', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
];
$assertTecSchemaRefusal(
    'an extra event column',
    static fn() => $tecDb->setColumnDefinitions($eventTable, $extraEventColumns),
    'rejected the tec_events column count'
);
$retypedOccurrenceColumns = $tecOccurrenceColumns;
$retypedOccurrenceColumns['event_id']['Type'] = 'bigint(20)';
$assertTecSchemaRefusal(
    'a retyped occurrence linkage',
    static fn() => $tecDb->setColumnDefinitions($occurrenceTable, $retypedOccurrenceColumns),
    'rejected tec_occurrences column event_id'
);
$reorderedOccurrenceColumns = ['occurrence_id' => $tecOccurrenceColumns['occurrence_id']];
$reorderedOccurrenceColumns['post_id'] = $tecOccurrenceColumns['post_id'];
$reorderedOccurrenceColumns['event_id'] = $tecOccurrenceColumns['event_id'];
$reorderedOccurrenceColumns += array_diff_key(
    $tecOccurrenceColumns,
    ['occurrence_id' => true, 'event_id' => true, 'post_id' => true]
);
$assertTecSchemaRefusal(
    'reordered occurrence columns',
    static fn() => $tecDb->setColumnDefinitions($occurrenceTable, $reorderedOccurrenceColumns),
    'rejected tec_occurrences column event_id'
);
$eventIndexesWithoutPostId = array_values(array_filter(
    $tecEventIndexes,
    static fn(array $row): bool => $row['Key_name'] !== 'post_id'
));
$assertTecSchemaRefusal(
    'a missing unique event post identity',
    static fn() => $tecDb->setIndexes($eventTable, $eventIndexesWithoutPostId),
    'rejected required tec_events index post_id'
);
$occurrenceIndexesWithoutEventId = array_values(array_filter(
    $tecOccurrenceIndexes,
    static fn(array $row): bool => $row['Key_name'] !== 'event_id'
));
$assertTecSchemaRefusal(
    'a missing occurrence event-link index',
    static fn() => $tecDb->setIndexes($occurrenceTable, $occurrenceIndexesWithoutEventId),
    'rejected required tec_occurrences index event_id'
);
$nonuniqueOccurrenceHash = array_map(
    static function (array $row): array {
        if ($row['Key_name'] === 'hash') {
            $row['Non_unique'] = 1;
        }
        return $row;
    },
    $tecOccurrenceIndexes
);
$assertTecSchemaRefusal(
    'a nonunique occurrence hash',
    static fn() => $tecDb->setIndexes($occurrenceTable, $nonuniqueOccurrenceHash),
    'rejected required tec_occurrences index hash'
);
$occurrenceIndexesWithoutUtcRange = array_values(array_filter(
    $tecOccurrenceIndexes,
    static fn(array $row): bool => $row['Key_name'] !== 'idx_wp_tec_occurrences_post_id_dates_utc'
));
$assertTecSchemaRefusal(
    'a missing native UTC range index',
    static fn() => $tecDb->setIndexes($occurrenceTable, $occurrenceIndexesWithoutUtcRange),
    'rejected required tec_occurrences index idx_wp_tec_occurrences_post_id_dates_utc'
);
$unknownUniqueEventIndexes = $tecEventIndexes;
$unknownUniqueEventIndexes[] = [
    'Key_name' => 'extension_unique_hash',
    'Non_unique' => 0,
    'Seq_in_index' => 1,
    'Column_name' => 'hash',
    'Sub_part' => null,
    'Index_type' => 'BTREE',
];
$assertTecSchemaRefusal(
    'an unreviewed unique event constraint',
    static fn() => $tecDb->setIndexes($eventTable, $unknownUniqueEventIndexes),
    'rejected an unknown unique tec_events index'
);
foreach ([
    'status query failure' => 'SHOW TABLE STATUS',
    'column query failure' => 'SHOW FULL COLUMNS',
    'index query failure' => 'SHOW INDEX',
] as $label => $queryFragment) {
    $assertTecSchemaRefusal(
        $label,
        static fn() => $tecDb->failNextQuery(
            'hostile schema failure AKIAABCDEFGHIJKLMNOP',
            $queryFragment
        ),
        'schema preflight could not read'
    );
}
$assertTecSchemaRefusal(
    'a compatible driver null schema result',
    static fn() => $tecDb->returnNextGetResultsAs(null),
    'schema preflight could not read'
);
$assertTecSchemaRefusal(
    'a malformed table status result',
    static fn() => $tecDb->returnNextGetResultsAs([[
        'Name' => $eventTable,
        'Engine' => ['InnoDB'],
    ]]),
    'rejected the tec_events table identity or engine'
);

$resetTecDerived();
$tecDb->last_error = 'handled stale schema error';
$tecDb->setTableEngine('wp_tecXevents', 'MyISAM');
$regenerator->regenerate($tecEventId);
duo_check_same(
    '',
    $tecDb->last_error,
    'schema probes clear stale errors and escape LIKE wildcards away from alias table identities'
);

$resetTecDerived();
$beforeFilteredRegeneration = [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)];
$GLOBALS['tec_readiness_filters'] = ['tec_events_custom_tables_v1_event_data_from_post'];
duo_check_same(
    'duo: TEC free-plugin derived-state contract does not admit the event-data filter',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'the exact native event-data filter topology refuses before any derived write'
);
duo_check_same(
    $beforeFilteredRegeneration,
    [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)],
    'a malicious same-shape event-data filter cannot mutate either custom table before refusal'
);
duo_check_same([], $GLOBALS['tec_readiness_regen_calls'], 'event-data filter refusal never crosses the native upsert boundary');
$GLOBALS['tec_readiness_filters'] = [];

foreach ([
    'same physical value' => static fn(mixed $value): mixed => $value,
    'valid alternate value' => static fn(mixed $value): string => '2026-11-02 15:16:00',
] as $label => $metadataCallback) {
    $resetTecDerived();
    $beforeMetadataFilter = [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)];
    add_filter('get_post_metadata', $metadataCallback, $label === 'same physical value' ? 1 : 999, 5);
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    duo_check(
        str_contains($failure, 'does not admit callback hook get_post_metadata'),
        "a $label get_post_metadata callback refuses before cache-backed native source reads"
    );
    duo_check_same(
        $beforeMetadataFilter,
        [$tecDb->rows($eventTable), $tecDb->rows($occurrenceTable)],
        "the $label metadata callback cannot produce environment-dependent derived rows"
    );
    duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], "$label metadata refusal precedes plugin code");
    remove_filter('get_post_metadata', $metadataCallback, $label === 'same physical value' ? 1 : 999);
}

$nativeHookProbe = static fn(mixed $value = null): mixed => $value;
foreach ([
    'tec_custom_tables_tec_events_model_v1_extensions',
    'tec_custom_tables_tec_occurrences_model_v1_extensions',
    'tec_events_custom_tables_v1_occurrences_generator',
    'tec_custom_tables_v1_get_occurrence_match',
    'tec_events_custom_tables_v1_after_update_occurrences',
    'tec_events_custom_tables_v1_after_insert_occurrences',
    'tec_events_custom_tables_v1_after_save_occurrences',
    'tec_cache_listener_save_post_types',
    'tribe_cache_expiration',
] as $position => $nativeHook) {
    $resetTecDerived();
    $priority = [1, 10, 999][$position % 3];
    add_filter($nativeHook, $nativeHookProbe, $priority, 5);
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    duo_check(
        str_contains($failure, "does not admit callback hook $nativeHook"),
        "the whole-registry preflight refuses an injected $nativeHook callback at priority $priority"
    );
    duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], "$nativeHook refuses before native mutation");
    remove_filter($nativeHook, $nativeHookProbe, $priority);
}

$resetTecDerived();
$unknownOptionCallback = static function (): void {};
add_action('updated_option', $unknownOptionCallback, 999, 3);
duo_check_same(
    'duo: TEC derived-state option hook updated_option contains an unreviewed callback',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an unknown generic option callback refuses before the native cache marker can invoke it'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'option-hook refusal precedes plugin code');
remove_action('updated_option', $unknownOptionCallback, 999);

$resetTecDerived();
$defaultAutoloadCallback = static fn(mixed $autoload): mixed => $autoload;
add_filter('wp_default_autoload_value', $defaultAutoloadCallback, 1, 3);
duo_check_same(
    'duo: TEC free-plugin derived-state contract does not admit callback hook wp_default_autoload_value',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'the implicit native marker autoload path refuses a default-autoload callback before mutation'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'default-autoload hook refusal precedes plugin code');
remove_filter('wp_default_autoload_value', $defaultAutoloadCallback, 1);

$resetTecDerived();
$GLOBALS['tec_readiness_external_object_cache'] = ['malformed'];
duo_check_same(
    'duo: TEC derived-state regeneration rejected a malformed external object-cache signal',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a non-boolean external object-cache signal refuses before transaction or native service use'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'malformed object-cache topology precedes plugin code');

$resetTecDerived();
$nativeListener = Tribe__Cache_Listener::instance();
$listenerCacheProperty = new ReflectionProperty(Tribe__Cache_Listener::class, 'cache');
$nativeListenerCache = $listenerCacheProperty->getValue($nativeListener);
duo_check(
    $nativeListenerCache instanceof Tribe__Cache
        && $nativeListenerCache !== $GLOBALS['tec_readiness_category_color_cache'],
    'the exact source-pinned listener cache is a distinct second Tribe__Cache instance'
);
$resetTecDerived();
$foreignListener = (new ReflectionClass(Tribe__Cache_Listener::class))
    ->newInstanceWithoutConstructor();
duo_check(
    remove_action(
        'updated_option',
        [$nativeListener, 'update_last_save_post'],
        10
    ),
    'the same-class callback substitution fixture removes the exact listener callback'
);
add_action(
    'updated_option',
    [$foreignListener, 'update_last_save_post'],
    10,
    3
);
duo_check_same(
    'duo: TEC derived-state regeneration rejected required native hook updated_option',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a same-class foreign listener callback refuses before its private state can execute'
);
duo_check_same(
    [],
    $GLOBALS['tec_readiness_event_data_calls'],
    'same-class callback substitution refuses before native event reads'
);
remove_action(
    'updated_option',
    [$foreignListener, 'update_last_save_post'],
    10
);
add_action(
    'updated_option',
    [$nativeListener, 'update_last_save_post'],
    10,
    3
);
$resetTecDerived();
$nativeSettingsManager = Tribe__Settings_Manager::instance();
duo_check(
    remove_action(
        'updated_option',
        [$nativeSettingsManager, 'update_options_cache'],
        10
    ),
    'the allowed-callback identity fixture removes the exact settings singleton'
);
$foreignSettingsManager = new Tribe__Settings_Manager();
duo_check_same(
    'duo: TEC derived-state option hook updated_option contains an unreviewed callback',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a same-class foreign allowed option callback refuses before update_option can execute it'
);
duo_check_same(
    [],
    $GLOBALS['tec_readiness_event_data_calls'],
    'foreign allowed callback identity refuses before native event reads'
);
remove_action(
    'updated_option',
    [$foreignSettingsManager, 'update_options_cache'],
    10
);
add_action(
    'updated_option',
    [$nativeSettingsManager, 'update_options_cache'],
    10,
    3
);
$cacheKeysProperty = new ReflectionProperty(Tribe__Cache::class, 'non_persistent_keys');
$globalCacheKeys = ['global-preimage' => 'global-preimage'];
$listenerCacheKeys = ['listener-preimage' => 'listener-preimage'];
$cacheKeysProperty->setValue(
    $GLOBALS['tec_readiness_category_color_cache'],
    $globalCacheKeys
);
$cacheKeysProperty->setValue($nativeListenerCache, $listenerCacheKeys);
$GLOBALS['tec_readiness_native_disruption'] = [
    'boundary' => 'find',
    'action' => 'cache_registry_mutate',
];
$GLOBALS['tec_readiness_regen_mode'] = 'save_throw';
duo_check_same(
    'injected native occurrence save failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a later native failure executes after both exact cache registries acquire independent drift'
);
duo_check_same(
    [$globalCacheKeys, $listenerCacheKeys],
    [
        $cacheKeysProperty->getValue($GLOBALS['tec_readiness_category_color_cache']),
        $cacheKeysProperty->getValue($nativeListenerCache),
    ],
    'rollback restores the global and listener non-persistent registries to distinct exact preimages'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    [$globalCacheKeys, $listenerCacheKeys],
    [
        $cacheKeysProperty->getValue($GLOBALS['tec_readiness_category_color_cache']),
        $cacheKeysProperty->getValue($nativeListenerCache),
    ],
    'same-process retry preserves both exact cache registries without conflating their identities'
);

foreach ([
    \TEC\Common\Configuration\Configuration::class,
    \TEC\Events\Custom_Tables\V1\Events\Occurrences\Occurrences_Generator::class,
] as $overriddenNativeService) {
    $resetTecDerived();
    $GLOBALS['tec_readiness_container_bindings'][$overriddenNativeService] = new stdClass();
    duo_check_same(
        'duo: TEC derived-state regeneration rejected an overridden native service binding',
        $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
        "$overriddenNativeService container substitution refuses before native code"
    );
    duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], "$overriddenNativeService refusal precedes source reads");
    unset($GLOBALS['tec_readiness_container_bindings'][$overriddenNativeService]);
}

$resetTecDerived();
$GLOBALS['tec_readiness_container_binding_signals'][
    \TEC\Events\Custom_Tables\V1\Events\Occurrences\Occurrences_Generator::class
] = 'malformed';
duo_check_same(
    'duo: TEC derived-state regeneration rejected an overridden native service binding',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a non-boolean container binding signal refuses before service construction'
);

$resetTecDerived();
$GLOBALS['tec_readiness_configuration']['TEC_NO_MEMOIZE_CT1_MODELS'] = true;
duo_check_same(
    'duo: TEC derived-state regeneration rejected a non-default model memoization configuration',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a Pro-style or environment-supplied custom-table memoization branch refuses before native lookup'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'non-default model configuration precedes source reads');

$resetTecDerived();
$unknownLogger = static function (): void {};
add_action('tribe_log', $unknownLogger, 1, 3);
duo_check_same(
    'duo: TEC derived-state regeneration requires one exact free-plugin failure logger',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an injected failure logger refuses before it can receive authored model working data'
);
remove_action('tribe_log', $unknownLogger, 1);
duo_check_same(
    10,
    has_filter('tribe_log', [$GLOBALS['tec_readiness_log_provider'], 'dispatch_log']),
    'failure preflight leaves the exact built-in logger registered once'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([[
    'meta_id' => '9100000001',
    'meta_key_bytes' => '19',
    'meta_value_bytes' => '16777217',
]], 'SELECT meta_id, OCTET_LENGTH(meta_key)');
duo_check_same(
    'duo: TEC derived-state source metadata row 0 is unordered, malformed, or oversized',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'one oversized physical metadata value refuses from the locked length roster'
);
duo_check(
    count(array_filter(
        $tecDb->queries(),
        static fn(string $sql): bool => str_contains($sql, 'SHA2(BINARY meta_')
    )) === 0,
    'an oversized source value is refused before MySQL evaluates either SHA2 expression'
);

$resetTecDerived();
$aggregateOverflowRoster = [];
for ($position = 0; $position < 4097; ++$position) {
    $aggregateOverflowRoster[] = [
        'meta_id' => (string) (9200000000 + $position),
        'meta_key_bytes' => '16',
        'meta_value_bytes' => '16384',
    ];
}
$tecDb->returnNextGetResultsAs(
    $aggregateOverflowRoster,
    'SELECT meta_id, OCTET_LENGTH(meta_key)'
);
unset($aggregateOverflowRoster);
duo_check_same(
    'duo: TEC derived-state source metadata exceeds the bounded owner-byte frontier',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'many individually bounded metadata rows refuse at the aggregate owner-byte frontier'
);
duo_check(
    count(array_filter(
        $tecDb->queries(),
        static fn(string $sql): bool => str_contains($sql, 'SHA2(BINARY meta_')
    )) === 0,
    'aggregate source overflow is refused before MySQL hashes any admitted-length value'
);

$resetTecDerived();
$duplicateRequiredMeta = $tecDb->rows($postMetaTable);
$duplicateRequiredMeta[] = [
    'meta_id' => 9199999999,
    'post_id' => $tecEventId,
    'meta_key' => '_EventTimezone',
    'meta_value' => 'Asia/Kathmandu',
];
$tecDb->seedTable($postMetaTable, $duplicateRequiredMeta);
duo_check_same(
    'duo: TEC derived-state source locking requires one physical row for every required event key',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a duplicate required _Event row refuses before get_post_meta(single) can pick nondeterministically'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'duplicate required metadata refuses before native source reads');

$resetTecDerived();
$aliasedRequiredMeta = $tecDb->rows($postMetaTable);
foreach ($aliasedRequiredMeta as &$row) {
    if (($row['post_id'] ?? null) === $tecEventId && ($row['meta_key'] ?? null) === '_EventDuration') {
        // Both keys are fourteen bytes, so count/length-only witnesses cannot
        // distinguish this hostile alias replacement.
        $row['meta_key'] = '_EventTimezone';
        break;
    }
}
unset($row);
$tecDb->seedTable($postMetaTable, $aliasedRequiredMeta);
duo_check_same(
    'duo: TEC derived-state source locking requires one physical row for every required event key',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a same-count same-length required-key alias swap refuses from physical key hashes'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'same-length key alias refusal precedes plugin code');

$resetTecDerived();
$tecDb->delete($optionsTable, ['option_name' => 'tribe_last_save_post']);
$GLOBALS['tec_readiness_wp_cache']['options'] = [
    'tribe_last_save_post' => 'stale-cache-marker',
    'alloptions' => ['tribe_last_save_post' => 'stale-alloptions-marker'],
    'notoptions' => ['tribe_last_save_post' => true],
];
$regenerator->regenerate($tecEventId);
$absentMarkerRows = array_values(array_filter(
    $tecDb->rows($optionsTable),
    static fn(array $row): bool => ($row['option_name'] ?? null) === 'tribe_last_save_post'
));
duo_check(
    count($absentMarkerRows) === 1
        && in_array(
            $absentMarkerRows[0]['autoload'] ?? null,
            ['yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off'],
            true
        ),
    'an absent native marker gap is locked and converges through WordPress add_option semantics'
);
duo_check(
    !isset($GLOBALS['tec_readiness_wp_cache']['options']['tribe_last_save_post'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['alloptions'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['notoptions']),
    'absent-row materialization purges stale specific, alloptions, and notoptions bytes'
);

$resetTecDerived();
$tecDb->update(
    $optionsTable,
    ['option_value' => 'not-a-native-marker'],
    ['option_name' => 'tribe_last_save_post']
);
duo_check_same(
    'duo: TEC derived-state native save-post cache marker is malformed or oversized',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a malformed target marker refuses before update_option can deserialize or normalize it'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'malformed marker refusal precedes plugin code');

$resetTecDerived();
$tecDb->update(
    $optionsTable,
    ['option_value' => str_repeat('9', 257)],
    ['option_name' => 'tribe_last_save_post']
);
duo_check_same(
    'duo: TEC derived-state native save-post cache marker is malformed or oversized',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an oversized target marker refuses from a bounded LEFT witness before native option reads'
);

$resetTecDerived();
$duplicateMarkerRows = $tecDb->rows($optionsTable);
$duplicateMarkerRows[] = [
    'option_id' => 13,
    'option_name' => 'tribe_last_save_post',
    'option_value' => '1700000001.25',
    'autoload' => 'no',
];
$tecDb->seedTable($optionsTable, $duplicateMarkerRows);
duo_check_same(
    'duo: TEC derived-state native save-post cache marker returned a malformed row',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a duplicate native marker row refuses despite the expected unique-index contract'
);

$resetTecDerived();
$tecDb->failNextQuery(
    'hostile option read error AKIAABCDEFGHIJKLMNOP',
    'SELECT option_id, option_name, LEFT(option_value, 257)'
);
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'could not read native save-post cache marker')
        && !str_contains($failure, 'AKIA'),
    'a native marker query failure is explicit, bounded, and secret-safe'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'option read failure precedes plugin code');

$resetTecDerived();
$tecDb->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
duo_check_same(
    ['session' => 'REPEATABLE-READ', 'next' => 'READ-COMMITTED', 'active' => null],
    $tecDb->transactionIsolationState(),
    'the isolation fixture begins with a hidden one-shot READ COMMITTED override'
);
$tecDb->resetLog();
$observedOwnerLockIsolation = null;
$tecDb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (
    &$observedOwnerLockIsolation
): null {
    if ($observedOwnerLockIsolation === null && str_contains($sql, ' FOR UPDATE')) {
        $observedOwnerLockIsolation = $db->transactionIsolationState()['active'];
    }
    return null;
});
$regenerator->regenerate($tecEventId);
$isolationQueries = $tecDb->queries();
$overridePosition = array_search(
    'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
    $isolationQueries,
    true
);
$startPosition = array_search('START TRANSACTION', $isolationQueries, true);
duo_check(
    is_int($overridePosition)
        && is_int($startPosition)
        && $overridePosition < $startPosition,
    'regeneration replaces a hidden one-shot isolation override immediately before START'
);
duo_check_same(
    'REPEATABLE-READ',
    $observedOwnerLockIsolation,
    'the first physical owner-range lock runs under the regenerator-owned isolation'
);
duo_check_same(
    ['session' => 'REPEATABLE-READ', 'next' => null, 'active' => null],
    $tecDb->transactionIsolationState(),
    'a committed regeneration leaves no one-shot or active isolation state behind'
);

foreach (['before_false', 'before_throw', 'after_false', 'after_throw'] as $startOutcome) {
    $resetTecDerived();
    $beforeStartFailure = $tecPhysicalState();
    $tecDb->injectTransactionOutcome('START', $startOutcome);
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    duo_check(
        str_contains($failure, 'transaction start')
            && str_contains($failure, 'recovery_required')
            && strlen($failure) < 300,
        "START $startOutcome refuses with one bounded transaction diagnostic"
    );
    duo_check_same(
        $beforeStartFailure,
        $tecPhysicalState(),
        "START $startOutcome leaves exact event, occurrence, option, and autoload preimages"
    );
    duo_check_same('0', $tecDb->get_var('SELECT @@in_transaction'), "START $startOutcome leaves no transaction owner");
    duo_check_same(
        ['session' => 'REPEATABLE-READ', 'next' => null, 'active' => null],
        $tecDb->transactionIsolationState(),
        "START $startOutcome consumes every attempted one-shot isolation override"
    );
    duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], "START $startOutcome precedes native reads");
    $regenerator->regenerate($tecEventId);
    duo_check_same(
        '2026-11-02 15:15:00',
        $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
        "same-process retry converges after START $startOutcome"
    );
}

$resetTecDerived();
$beforeStartReconnect = $tecPhysicalState();
$tecDb->injectTransactionOutcome('START', 'after_reconnect');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'rollback/runtime cleanup requires recovery')
        && str_contains($failure, 'rollback')
        && strlen($failure) < 300,
    'a reconnect during START is recovery-required rather than accepted as rollback proof'
);
duo_check_same($beforeStartReconnect, $tecPhysicalState(), 'START reconnect drops and restores the uncommitted preimage');
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'START reconnect precedes native reads');

foreach ([
    'before_false',
    'before_throw',
    'inactive_false',
    'success_no_apply',
    'success_no_apply_probe_error',
] as $commitPreimageOutcome) {
    $resetTecDerived();
    $beforeCommitFailure = $tecPhysicalState();
    $tecDb->injectTransactionOutcome('COMMIT', $commitPreimageOutcome);
    duo_check_same(
        'duo: TEC derived-state transaction commit failed without server apply',
        $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
        "COMMIT $commitPreimageOutcome is classified as the exact inactive preimage"
    );
    duo_check_same(
        $beforeCommitFailure,
        $tecPhysicalState(),
        "COMMIT $commitPreimageOutcome restores exact derived, marker, autoload, and neighbor bytes"
    );
    duo_check_same('0', $tecDb->get_var('SELECT @@in_transaction'), "COMMIT $commitPreimageOutcome releases every lock");
    if ($commitPreimageOutcome === 'before_false') {
        duo_check_same(
            2,
            count(array_filter(
                $tecDb->queries(),
                static fn(string $sql): bool =>
                    $sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
            )),
            'ambiguous-COMMIT verification establishes its own one-shot isolation too'
        );
    }
    $regenerator->regenerate($tecEventId);
    duo_check_same(
        '2026-11-02 15:15:00',
        $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
        "same-process retry converges after COMMIT $commitPreimageOutcome"
    );
}

$resetTecDerived();
$beforeBetweenProbeReconnect = $tecPhysicalState();
$tecDb->injectTransactionOutcome('COMMIT', 'success_no_apply_reconnect_before_state');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
$betweenProbeQueries = $tecDb->queries();
duo_check(
    str_contains($failure, 'recovery_required')
        && str_contains($failure, 'rollback')
        && strlen($failure) < 300,
    'a reconnect before the post-COMMIT state witness cannot bless a truthy no-apply response'
);
duo_check_same(
    $beforeBetweenProbeReconnect,
    $tecPhysicalState(),
    'the reconnect-before-state case retains the exact transaction preimage'
);
$transactionStateQueries = array_values(array_filter(
    $betweenProbeQueries,
    static fn(string $sql): bool => str_contains(strtolower($sql), 'in_transaction')
));
duo_check(
    $transactionStateQueries !== []
        && count(array_filter(
            $transactionStateQueries,
            static fn(string $sql): bool =>
                $sql !== 'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction'
        )) === 0,
    'every product-path transaction state witness binds connection identity in the same SQL row'
);

foreach (['after_false', 'after_throw', 'success_probe_error'] as $commitAppliedOutcome) {
    $resetTecDerived();
    $tecDb->injectTransactionOutcome('COMMIT', $commitAppliedOutcome);
    $regenerator->regenerate($tecEventId);
    $appliedMarker = $tecDb->get_row(
        "SELECT option_value, autoload FROM `$optionsTable` WHERE option_name = 'tribe_last_save_post'",
        ARRAY_A
    );
    duo_check(
        ($appliedMarker['option_value'] ?? null) !== '1700000000.1234'
            && ($appliedMarker['autoload'] ?? null) === 'yes',
        "COMMIT $commitAppliedOutcome is accepted only after exact marker advancement and autoload proof"
    );
    duo_check_same(
        '2026-11-02 15:15:00',
        $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
        "COMMIT $commitAppliedOutcome retains the exact durable derived postcondition"
    );
    duo_check_same('0', $tecDb->get_var('SELECT @@in_transaction'), "COMMIT $commitAppliedOutcome releases verification locks");
}

$resetTecDerived();
$tecDb->injectTransactionOutcome('COMMIT', 'after_reconnect');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'rollback/runtime cleanup requires recovery')
        && str_contains($failure, 'derived_rows')
        && strlen($failure) < 300,
    'a truthy reconnect after server-applied COMMIT remains ambiguous and recovery-required'
);
duo_check_same(
    '2026-11-02 15:15:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'COMMIT reconnect cannot misreport the exact durable derived bytes as rolled back'
);

foreach (['before_false', 'before_throw', 'after_false', 'after_throw'] as $rollbackOutcome) {
    $resetTecDerived();
    $beforeRollbackFailure = $tecPhysicalState();
    $GLOBALS['tec_readiness_regen_mode'] = 'upsert_throw_after_write';
    $tecDb->injectTransactionOutcome('ROLLBACK', $rollbackOutcome);
    duo_check_same(
        'injected native event upsert failure after derived write',
        $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
        "ROLLBACK $rollbackOutcome is state-probed instead of trusting the driver response"
    );
    duo_check_same(
        $beforeRollbackFailure,
        $tecPhysicalState(),
        "ROLLBACK $rollbackOutcome restores exact derived, marker, autoload, and neighbor bytes"
    );
    duo_check_same('0', $tecDb->get_var('SELECT @@in_transaction'), "ROLLBACK $rollbackOutcome releases every lock");
    $GLOBALS['tec_readiness_regen_mode'] = 'ok';
    $regenerator->regenerate($tecEventId);
    duo_check_same(
        '2026-11-02 15:15:00',
        $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
        "same-process retry converges after ROLLBACK $rollbackOutcome"
    );
}

$resetTecDerived();
$beforeRollbackReconnect = $tecPhysicalState();
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_throw_after_write';
$tecDb->injectTransactionOutcome('ROLLBACK', 'after_reconnect');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'rollback/runtime cleanup requires recovery')
        && str_contains($failure, 'rollback')
        && strlen($failure) < 300,
    'a reconnect after server-applied ROLLBACK preserves the preimage but requires recovery'
);
duo_check_same(
    $beforeRollbackReconnect,
    $tecPhysicalState(),
    'ROLLBACK reconnect restores exact derived, marker, autoload, and neighbor bytes'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';

$nativeTransactionBoundaries = [
    'data_from_post',
    'upsert',
    'find',
    'occurrences',
    'save_occurrences',
];
foreach (['commit', 'rollback', 'reconnect'] as $nativeTransactionAction) {
    foreach ($nativeTransactionBoundaries as $nativeTransactionBoundary) {
        $resetTecDerived();
        $beforeNativeTransactionBreak = $tecPhysicalState();
        $GLOBALS['tec_readiness_native_disruption'] = [
            'boundary' => $nativeTransactionBoundary,
            'action' => $nativeTransactionAction,
        ];
        $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
        duo_check(
            str_contains($failure, 'recovery_required')
                && strlen($failure) < 300,
            "native $nativeTransactionAction at $nativeTransactionBoundary refuses with bounded recovery authority"
        );
        duo_check_same(
            '0',
            $tecDb->get_var('SELECT @@in_transaction'),
            "native $nativeTransactionAction at $nativeTransactionBoundary cannot leave a transaction owner"
        );
        if ($nativeTransactionAction !== 'commit') {
            duo_check_same(
                $beforeNativeTransactionBreak,
                $tecPhysicalState(),
                "native $nativeTransactionAction at $nativeTransactionBoundary restores the exact physical preimage"
            );
        }
    }
}

foreach ($nativeTransactionBoundaries as $sourceDriftBoundary) {
    $resetTecDerived();
    $beforeSourceDrift = $tecPhysicalState();
    $GLOBALS['tec_readiness_native_disruption'] = [
        'boundary' => $sourceDriftBoundary,
        'action' => 'source_same_length',
    ];
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    duo_check(
        str_contains($failure, 'source rows changed during')
            && str_contains($failure, 'recovery_required')
            && strlen($failure) < 300,
        "same-length source drift at $sourceDriftBoundary refuses from the aggregate physical witness"
    );
    duo_check_same(
        $beforeSourceDrift,
        $tecPhysicalState(),
        "same-length source drift at $sourceDriftBoundary rolls back source, derived, option, and autoload bytes"
    );
}

foreach (['source_insert', 'source_delete', 'source_id_swap'] as $sourceAbaAction) {
    $resetTecDerived();
    $beforeSourceAba = $tecPhysicalState();
    $GLOBALS['tec_readiness_native_disruption'] = [
        'boundary' => 'occurrences',
        'action' => $sourceAbaAction,
    ];
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    $sourceAbaDiagnostic = $sourceAbaAction === 'source_delete'
        ? 'requires one physical row for every required event key'
        : 'source rows changed during Event::occurrences()';
    duo_check(
        str_contains($failure, $sourceAbaDiagnostic)
            && ($sourceAbaAction === 'source_delete'
                || str_contains($failure, 'recovery_required'))
            && strlen($failure) < 300,
        "$sourceAbaAction refuses from row count, content hash, and physical-id ordering"
    );
    duo_check_same(
        $beforeSourceAba,
        $tecPhysicalState(),
        "$sourceAbaAction rolls the full source and derived transaction back"
    );
}

$resetTecDerived();
$beforeListenerSubstitution = $tecPhysicalState();
$GLOBALS['tec_readiness_native_disruption'] = [
    'boundary' => 'find',
    'action' => 'listener_cache_substitute',
];
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'runtime cleanup requires recovery')
        && str_contains($failure, 'cache_service')
        && strlen($failure) < 300,
    'same-class listener cache substitution at a native boundary is recovery-required'
);
$listenerCacheProperty->setValue($nativeListener, $nativeListenerCache);
duo_check_same(
    $beforeListenerSubstitution,
    $tecPhysicalState(),
    'listener cache substitution rolls exact derived, marker, and autoload bytes back'
);

$resetTecDerived();
$beforeGeneratorSubstitution = $tecPhysicalState();
$GLOBALS['tec_readiness_native_disruption'] = [
    'boundary' => 'occurrences',
    'action' => 'generator_binding',
];
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    str_contains($failure, 'runtime cleanup requires recovery')
        && str_contains($failure, 'container_service')
        && strlen($failure) < 300,
    'a same-output foreign occurrence-generator binding is detected at the native service boundary'
);
unset($GLOBALS['tec_readiness_container_bindings'][
    \TEC\Events\Custom_Tables\V1\Events\Occurrences\Occurrences_Generator::class
]);
duo_check_same(
    $beforeGeneratorSubstitution,
    $tecPhysicalState(),
    'foreign occurrence-generator substitution rolls exact derived and runtime option bytes back'
);

$resetTecDerived();
$GLOBALS['tec_readiness_wp_cache_group_support'] = 'malformed';
duo_check_same(
    'duo: TEC derived-state regeneration requires exact local object-cache group flushing',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a non-boolean cache capability response refuses before transaction or native mutation'
);
duo_check_same([], $GLOBALS['tec_readiness_event_data_calls'], 'malformed cache capability precedes plugin code');

foreach ([
    'one failed key deletion' => ['delete' => 'one_failure', 'get' => '', 'group' => ''],
    'non-boolean key deletion' => ['delete' => 'nonbool', 'get' => '', 'group' => ''],
    'cache read exception' => ['delete' => '', 'get' => 'throw', 'group' => ''],
    'non-boolean cache found flag' => ['delete' => '', 'get' => 'nonbool_found', 'group' => ''],
    'one failed group flush' => ['delete' => '', 'get' => '', 'group' => 'one_failure'],
    'non-boolean group flush' => ['delete' => '', 'get' => '', 'group' => 'nonbool'],
] as $cacheFailureLabel => $cacheFailureModes) {
    $resetTecDerived();
    $beforeCacheFailure = $tecPhysicalState();
    $GLOBALS['tec_readiness_wp_cache']['posts'][$tecEventId] = 'stale-source-cache';
    $GLOBALS['tec_readiness_wp_cache_delete_mode'] = $cacheFailureModes['delete'];
    $GLOBALS['tec_readiness_wp_cache_get_mode'] = $cacheFailureModes['get'];
    $GLOBALS['tec_readiness_wp_cache_group_flush_mode'] = $cacheFailureModes['group'];
    $failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
    duo_check(
        str_contains($failure, 'native cache effects could not be purged')
            || str_contains($failure, 'runtime cleanup requires recovery'),
        "$cacheFailureLabel refuses instead of trusting an ambiguous cache primitive outcome"
    );
    duo_check_same(
        $beforeCacheFailure,
        $tecPhysicalState(),
        "$cacheFailureLabel leaves exact database preimages before native mutation"
    );
    duo_check(
        $GLOBALS['tec_readiness_wp_cache_deletes'] >= 14
            && $GLOBALS['tec_readiness_wp_cache_group_flushes'] >= 4,
        "$cacheFailureLabel still visits every declared key and cache group on failure and rollback cleanup"
    );
    $GLOBALS['tec_readiness_wp_cache_delete_mode'] = '';
    $GLOBALS['tec_readiness_wp_cache_get_mode'] = '';
    $GLOBALS['tec_readiness_wp_cache_group_flush_mode'] = '';
}

$resetTecDerived();
$beforeOccurrenceCacheFailure = $tecPhysicalState();
$GLOBALS['tec_readiness_native_disruption'] = [
    'boundary' => 'occurrences',
    'action' => 'cache_delete_throw',
];
duo_check_same(
    'injected local object-cache delete failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'the exact native occurrence-match cache deletion failure aborts the derived transaction'
);
duo_check_same(
    $beforeOccurrenceCacheFailure,
    $tecPhysicalState(),
    'occurrence-match cache failure rolls event, occurrence, marker, and autoload bytes back atomically'
);
$regenerator->regenerate($tecEventId);
duo_check_same(
    '2026-11-02 15:15:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'same-process retry after occurrence-match cache failure converges the exact derived rows'
);

$resetTecDerived();
$tecDb->delete($optionsTable, ['option_name' => 'tribe_last_save_post']);
$beforeAbsentMarkerFailure = $tecDb->rows($optionsTable);
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_throw_after_write';
duo_check_same(
    'injected native event upsert failure after derived write',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an absent marker created by a partial native upsert remains inside the derived transaction'
);
duo_check_same(
    $beforeAbsentMarkerFailure,
    $tecDb->rows($optionsTable),
    'rollback of the absent-row path removes the uncommitted marker and preserves its neighbor'
);
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'absent-row rollback restores the initially absent global purge flag');
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    1,
    count(array_filter(
        $tecDb->rows($optionsTable),
        static fn(array $row): bool => ($row['option_name'] ?? null) === 'tribe_last_save_post'
    )),
    'same-process retry after absent-row rollback creates exactly one durable native marker'
);

foreach ([
    'yes' => 'yes',
    'no' => 'no',
    'on' => 'on',
    'off' => 'off',
    'auto' => 'auto-on',
    'auto-on' => 'auto-on',
    'auto-off' => 'auto-on',
] as $initialAutoload => $expectedAutoload) {
    $resetTecDerived();
    duo_check_same(
        $initialAutoload === 'yes' ? 0 : 1,
        $tecDb->update(
            $optionsTable,
            ['autoload' => $initialAutoload],
            ['option_name' => 'tribe_last_save_post']
        ),
        "the physical native marker fixture admits $initialAutoload for exact transition evidence"
    );
    $regenerator->regenerate($tecEventId);
    $autoloadRows = array_values(array_filter(
        $tecDb->rows($optionsTable),
        static fn(array $row): bool => ($row['option_name'] ?? null) === 'tribe_last_save_post'
    ));
    duo_check_same(
        $expectedAutoload,
        $autoloadRows[0]['autoload'] ?? null,
        "the pinned WordPress update path maps $initialAutoload to exact $expectedAutoload autoload state"
    );
}

$resetTecDerived();
duo_check_same(
    1,
    $tecDb->update(
        $optionsTable,
        ['autoload' => 'auto'],
        ['option_name' => 'tribe_last_save_post']
    ),
    'the rollback fixture begins with the legacy computed auto state'
);
$beforeComputedAutoloadRollback = $tecDb->rows($optionsTable);
$GLOBALS['tec_readiness_regen_mode'] = 'save_throw';
duo_check_same(
    'injected native occurrence save failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'the computed-autoload rollback fixture fails after both native marker writes'
);
duo_check_same(
    $beforeComputedAutoloadRollback,
    $tecDb->rows($optionsTable),
    'rollback restores the exact legacy computed autoload spelling rather than its recomputed successor'
);

$resetTecDerived();
tribe_set_var('should_delete_expired_transients', true);
$GLOBALS['tec_readiness_regen_mode'] = 'save_throw';
duo_check_same(
    'injected native occurrence save failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a later native occurrence failure still crosses both marker writes before rollback'
);
duo_check_same(true, tribe_get_var('should_delete_expired_transients'), 'rollback preserves an independently preexisting true transient-purge flag');
duo_check_same(0, $GLOBALS['tec_readiness_expired_transient_deletes'], 'adapter cleanup never consumes a preexisting transient-purge intent');
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(true, tribe_get_var('should_delete_expired_transients'), 'commit also preserves an independently preexisting true transient-purge flag');

$resetTecDerived();
$regenerator->regenerate($tecEventId);
$lastSaveRows = array_values(array_filter(
    $tecDb->rows($optionsTable),
    static fn(array $row): bool => ($row['option_name'] ?? null) === 'tribe_last_save_post'
));
duo_check(
    count($lastSaveRows) === 1
        && preg_match(
            '/^[1-9][0-9]*(?:\.[0-9]+)?(?:E[+-]?[0-9]+)?$/Di',
            (string) ($lastSaveRows[0]['option_value'] ?? '')
        ) === 1
        && ($lastSaveRows[0]['option_value'] ?? null) !== '1700000000.1234',
    'successful native regeneration advances exactly one bounded save-post marker row'
);
duo_check_same('yes', $lastSaveRows[0]['autoload'] ?? null, 'an existing native marker preserves its exact autoload bytes');
duo_check_same(
    'preserve-me',
    $tecDb->get_var("SELECT option_value FROM `$optionsTable` WHERE option_name = 'target_runtime_neighbor'"),
    'the locked native marker update preserves an unrelated target-runtime option'
);
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'commit restores an initially absent transient-purge flag');
duo_check_same(0, $GLOBALS['tec_readiness_expired_transient_deletes'], 'regeneration never runs the site-wide transient purge');
duo_check_same(0, $GLOBALS['tec_readiness_log_dispatches'], 'normal native regeneration emits no authored model payload to the logger');
duo_check_same(
    10,
    has_filter('tribe_log', [$GLOBALS['tec_readiness_log_provider'], 'dispatch_log']),
    'the exact built-in logger is restored after a committed regeneration'
);
duo_check(
    ($GLOBALS['tec_readiness_wp_cache']['tribe-events'] ?? []) === []
        && ($GLOBALS['tec_readiness_wp_cache']['tribe-events-non-persistent'] ?? []) === []
        && ($GLOBALS['tec_readiness_wp_cache']['tec_occurrence_matches'] ?? []) === []
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['tribe_last_save_post'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['alloptions'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['notoptions']),
    'commit purges every exact native model, occurrence, and option-cache effect'
);
duo_check(
    count(array_filter(
        $tecDb->queries(),
        static fn(string $sql): bool => str_contains($sql, 'FORCE INDEX (`option_name`)')
            && str_ends_with($sql, 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE')
    )) >= 3,
    'the native save-post option row or absent gap stays locked and value-checked across plugin calls'
);
$boundedVerificationQueries = array_values(array_filter(
    $tecDb->queries(),
    static fn(string $sql): bool => str_contains($sql, 'WHERE post_id = 6100000001 OR event_id = 7000000001')
));
duo_check(
    count($boundedVerificationQueries) === 2
        && str_ends_with($boundedVerificationQueries[0], 'ORDER BY event_id LIMIT 3')
        && str_ends_with($boundedVerificationQueries[1], 'ORDER BY occurrence_id LIMIT 3'),
    'both exact custom-table verification reads have a three-row proof limit before transfer'
);
$expectedOccurrenceHash = sha1(implode(':', [
    (string) $tecEventId,
    '2026-11-02 18:30:00',
    '2026-11-02 21:00:00',
    '2026-11-02 12:45:00',
    '2026-11-02 15:15:00',
    '9000',
]));
$nativeEventRow = $tecDb->get_row($tecDb->prepare(
    'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, timezone, duration, hash '
    . "FROM `$eventTable` WHERE post_id = %d",
    $tecEventId
), ARRAY_A);
duo_check_same([
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'timezone' => 'Asia/Kathmandu',
    'duration' => '9000',
    'hash' => '',
], $nativeEventRow, 'regeneration verifies every deterministic tec_events field on a huge post identity');
$nativeOccurrenceRow = $tecDb->get_row($tecDb->prepare(
    'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, duration, hash '
    . "FROM `$occurrenceTable` WHERE post_id = %d",
    $tecEventId
), ARRAY_A);
duo_check_same([
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'duration' => '9000',
    'hash' => $expectedOccurrenceHash,
], $nativeOccurrenceRow, 'regeneration verifies every deterministic occurrence field and event linkage');
duo_check_same(
    'target-runtime-event',
    $tecDb->get_var("SELECT hash FROM `$eventTable` WHERE post_id = 6100000099"),
    'event regeneration preserves unrelated target-derived rows'
);
duo_check_same(
    'target-runtime-occurrence',
    $tecDb->get_var("SELECT hash FROM `$occurrenceTable` WHERE post_id = 6100000099"),
    'occurrence regeneration preserves unrelated target-derived rows'
);
$beforeRetry = [$nativeEventRow, $nativeOccurrenceRow];
$regenerator->regenerate($tecEventId);
$afterRetry = [
    $tecDb->get_row($tecDb->prepare(
        'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, timezone, duration, hash '
        . "FROM `$eventTable` WHERE post_id = %d",
        $tecEventId
    ), ARRAY_A),
    $tecDb->get_row($tecDb->prepare(
        'SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, duration, hash '
        . "FROM `$occurrenceTable` WHERE post_id = %d",
        $tecEventId
    ), ARRAY_A),
];
duo_check_same($beforeRetry, $afterRetry, 'a repeated native regeneration is idempotent at every deterministic field');
$heartbeats = 0;
$regenerator->regenerate_batch(
    [$tecEventId, 0, $tecEventId, -1],
    [],
    static function () use (&$heartbeats): void {
        ++$heartbeats;
    }
);
duo_check_same(1, $heartbeats, 'batch regeneration deduplicates live IDs before heartbeat and native mutation');
duo_check_same([$tecEventId, $tecEventId, $tecEventId], $GLOBALS['tec_readiness_regen_calls'], 'batch retry invokes only the one canonical positive live ID');

$resetTecDerived();
$setTecSourceMeta('_EventDuration', '');
$regenerator->regenerate($tecEventId);
duo_check_same(
    ['9000', '9000'],
    [
        $tecDb->get_var($tecDb->prepare("SELECT duration FROM `$eventTable` WHERE post_id = %d", $tecEventId)),
        $tecDb->get_var($tecDb->prepare("SELECT duration FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    ],
    'empty native duration metadata derives exact seconds from UTC endpoints in both custom tables'
);
$setTecSourceMeta('_EventDuration', '9000');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'stale_driver_error';
$regenerator->regenerate($tecEventId);
duo_check_same('', $tecDb->last_error, 'handled stale native model errors cannot poison either exact verification read');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'non_array_data';
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(str_contains($failure, 'non-array') && !str_contains($failure, 'AKIA'), 'filtered non-array event data refuses with a bounded redacted diagnostic');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_false';
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(str_contains($failure, '2 model error(s)') && strlen($failure) < 200 && !str_contains($failure, 'AKIA'), 'native upsert errors are counted without leaking hostile plugin payloads');

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'find_missing';
duo_check(
    str_contains($tecFailure(static fn() => $regenerator->regenerate($tecEventId)), 'could not locate'),
    'a partial upsert whose native model cannot be read back refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_event_id';
duo_check(
    str_contains($tecFailure(static fn() => $regenerator->regenerate($tecEventId)), 'positive integer event_id'),
    'a native model with a non-integer generated identity refuses before occurrence mutation'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'malformed_event_timezone';
duo_check_same(
    'duo: TEC Event::data_from_post() returned a malformed timezone',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a malformed native event timezone refuses by fixed field name without authored values'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'unexpected_event_field';
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check(
    $failure === 'duo: TEC Event::data_from_post() returned an unexpected field set'
        && !str_contains($failure, 'AKIA'),
    'an unexpected native event-data field refuses without leaking its key or value'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_event_updated_at';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_events fields: updated_at',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an invalid generated event update timestamp refuses without treating it as authored state'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_occurrence_id';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: occurrence_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence without one positive generated identity refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'oversized_occurrence_id';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: occurrence_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence identity above the exact unsigned bigint frontier refuses'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'invalid_occurrence_updated_at';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: updated_at',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an invalid generated occurrence update timestamp refuses without copying it'
);

$validEventDriverRow = [
    'event_id' => '7000000001',
    'post_id' => (string) $tecEventId,
    'start_date' => '2026-11-02 18:30:00',
    'end_date' => '2026-11-02 21:00:00',
    'start_date_utc' => '2026-11-02 12:45:00',
    'end_date_utc' => '2026-11-02 15:15:00',
    'timezone' => 'Asia/Kathmandu',
    'duration' => '9000',
    'updated_at' => '2026-08-24 00:00:00',
    'hash' => '',
];
$resetTecDerived();
$tecDb->returnNextGetResultsAs(null, 'SELECT event_id, post_id, start_date');
duo_check_same(
    'duo: TEC derived-state verification query returned a non-array for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a null event read from a non-core wpdb-compatible driver refuses explicitly'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([7 => $validEventDriverRow], 'SELECT event_id, post_id, start_date');
duo_check_same(
    'duo: TEC derived-state verification query returned a non-list for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an associative compatible-driver result refuses before event value verification'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs(
    [array_diff_key($validEventDriverRow, ['hash' => true])],
    'SELECT event_id, post_id, start_date'
);
duo_check_same(
    'duo: TEC derived-state verification query returned a malformed driver row for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an incomplete event driver row refuses before missing fields can be conflated with empty values'
);

$resetTecDerived();
$nonStringEventDriverRow = $validEventDriverRow;
$nonStringEventDriverRow['event_id'] = 7000000001;
$tecDb->returnNextGetResultsAs([$nonStringEventDriverRow], 'SELECT event_id, post_id, start_date');
duo_check_same(
    'duo: TEC derived-state verification query returned a non-string driver value for tec_events',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native-integer compatible-driver value refuses outside mysqli text-protocol evidence'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs(false, 'SELECT occurrence_id, event_id, post_id, start_date');
duo_check_same(
    'duo: TEC derived-state verification query returned a non-array for tec_occurrences',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a false occurrence read from a non-core wpdb-compatible driver refuses explicitly'
);

$resetTecDerived();
$tecDb->returnNextGetResultsAs([
    9 => [
        'occurrence_id' => '8000000001',
        'event_id' => '7000000001',
        'post_id' => (string) $tecEventId,
        'start_date' => '2026-11-02 18:30:00',
        'end_date' => '2026-11-02 21:00:00',
        'start_date_utc' => '2026-11-02 12:45:00',
        'end_date_utc' => '2026-11-02 15:15:00',
        'duration' => '9000',
        'updated_at' => '2026-08-24 00:00:01',
        'hash' => $expectedOccurrenceHash,
    ],
], 'SELECT occurrence_id, event_id, post_id, start_date');
duo_check_same(
    'duo: TEC derived-state verification query returned a non-list for tec_occurrences',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an associative occurrence result refuses before generated-row verification'
);

$resetTecDerived();
$tecDb->failNextQuery('credential SQL failure AKIAABCDEFGHIJKLMNOP', 'SELECT event_id, post_id, start_date');
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check_same('duo: TEC derived-state verification query failed for tec_events', $failure, 'event verification query failure is explicit and redacted');

$resetTecDerived();
$tecDb->failNextQuery(
    'credential SQL failure AKIAABCDEFGHIJKLMNOP',
    'SELECT occurrence_id, event_id, post_id, start_date'
);
$failure = $tecFailure(static fn() => $regenerator->regenerate($tecEventId));
duo_check_same('duo: TEC derived-state verification query failed for tec_occurrences', $failure, 'occurrence verification query failure is explicit and redacted');

$resetTecDerived();
$beforeFailedNativeOptions = $tecDb->rows($optionsTable);
$GLOBALS['tec_readiness_wp_cache']['options'] = [
    'tribe_last_save_post' => 'stale-before-rollback',
    'alloptions' => ['tribe_last_save_post' => 'stale-before-rollback'],
    'notoptions' => [],
];
$GLOBALS['tec_readiness_wp_cache']['tribe-events'] = ['stale-model' => 'stale'];
$GLOBALS['tec_readiness_wp_cache']['tribe-events-non-persistent'] = ['stale-query' => 'stale'];
$GLOBALS['tec_readiness_wp_cache']['tec_occurrence_matches'][$tecEventId] = 'stale-occurrence';
$GLOBALS['tec_readiness_regen_mode'] = 'upsert_throw_after_write';
duo_check_same(
    'injected native event upsert failure after derived write',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native exception after the tec_events upsert surfaces before occurrence synthesis'
);
duo_check_same(
    'stale-event-hash',
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$eventTable` WHERE post_id = %d", $tecEventId)),
    'the event-upsert failure rolls its completed first derived write back atomically'
);
duo_check_same(
    'stale-occurrence-hash',
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the event-upsert failure witness leaves the second derived row untouched'
);
duo_check_same(
    $beforeFailedNativeOptions,
    $tecDb->rows($optionsTable),
    'rollback restores exact native marker value, autoload, identity, and neighboring option bytes'
);
duo_check_same(false, tribe_isset_var('should_delete_expired_transients'), 'rollback removes a transient-purge flag introduced only by the failed native call');
duo_check_same(0, $GLOBALS['tec_readiness_expired_transient_deletes'], 'rollback never schedules or runs the site-wide transient purge');
duo_check(
    ($GLOBALS['tec_readiness_wp_cache']['tribe-events'] ?? []) === []
        && ($GLOBALS['tec_readiness_wp_cache']['tribe-events-non-persistent'] ?? []) === []
        && ($GLOBALS['tec_readiness_wp_cache']['tec_occurrence_matches'] ?? []) === []
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['tribe_last_save_post'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['alloptions'])
        && !isset($GLOBALS['tec_readiness_wp_cache']['options']['notoptions']),
    'rollback purges stale and uncommitted native cache publications exhaustively'
);
duo_check_same(0, $GLOBALS['tec_readiness_log_dispatches'], 'a failed upsert cannot log authored working_data while the exact logger is suppressed');
duo_check_same(
    10,
    has_filter('tribe_log', [$GLOBALS['tec_readiness_log_provider'], 'dispatch_log']),
    'rollback restores the exact built-in logger for same-process retry'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    $expectedOccurrenceHash,
    $tecDb->get_var($tecDb->prepare("SELECT hash FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'same-process retry after the partial event upsert converges both native rows'
);
duo_check_same(
    ['target-runtime-event', 'target-runtime-occurrence'],
    [
        $tecDb->get_var("SELECT hash FROM `$eventTable` WHERE post_id = 6100000099"),
        $tecDb->get_var("SELECT hash FROM `$occurrenceTable` WHERE post_id = 6100000099"),
    ],
    'partial event-upsert failure and retry preserve unrelated target-derived rows'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'partial_occurrence';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: end_date_utc',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a partially written occurrence refuses on its missing deterministic postcondition'
);
duo_check_same(
    '1999-01-01 00:30:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the failed value-level verification rolls the partial occurrence back atomically'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    '2026-11-02 15:15:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'same-process retry after a value-level verification failure repairs the exact occurrence field'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'wrong_event_link';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: event_id',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an occurrence linked to the wrong native event row refuses'
);

$resetTecDerived();
$occurrenceFlood = $tecDb->rows($occurrenceTable);
$occurrencePrototype = $occurrenceFlood[0];
for ($index = 0; $index < 1000; ++$index) {
    $extraOccurrence = $occurrencePrototype;
    $extraOccurrence['occurrence_id'] = 8100000000 + $index;
    $extraOccurrence['event_id'] = 7000000001;
    $extraOccurrence['post_id'] = 6200000000 + $index;
    $occurrenceFlood[] = $extraOccurrence;
}
$tecDb->seedTable($occurrenceTable, $occurrenceFlood);
unset($occurrenceFlood, $occurrencePrototype, $extraOccurrence);
duo_check_same(
    'duo: TEC derived-state locking rejected a cross-linked occurrence row',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a hostile cross-linked occurrence flood refuses during the bounded owner-range lock'
);
duo_check(
    count(array_filter(
        $tecDb->queries(),
        static fn(string $sql): bool => str_contains($sql, 'FORCE INDEX (`event_id`)')
            && str_ends_with($sql, 'ORDER BY occurrence_id ASC LIMIT 3 FOR UPDATE')
    )) === 1,
    'the hostile occurrence flood transfers only the three-row proof witness'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'orphan_extra';
duo_check_same(
    'duo: TEC derived-state verification failed for tec_occurrences fields: row_count',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'an orphan occurrence sharing the native event identity refuses rather than earning success'
);

$resetTecDerived();
$GLOBALS['tec_readiness_regen_mode'] = 'save_throw';
duo_check_same(
    'injected native occurrence save failure',
    $tecFailure(static fn() => $regenerator->regenerate($tecEventId)),
    'a native occurrence exception surfaces and leaves retry authority to the engine'
);
duo_check_same(
    '1999-01-01 00:30:00',
    $tecDb->get_var($tecDb->prepare("SELECT end_date_utc FROM `$occurrenceTable` WHERE post_id = %d", $tecEventId)),
    'the native occurrence exception rolls the second derived write back atomically'
);
$GLOBALS['tec_readiness_regen_mode'] = 'ok';
$regenerator->regenerate($tecEventId);
duo_check_same(
    $expectedOccurrenceHash,
    $tecDb->get_var($tecDb->prepare(
        "SELECT hash FROM `$occurrenceTable` WHERE post_id = %d",
        $tecEventId
    )),
    'retry after a partial native occurrence failure converges to the exact deterministic row'
);

duo_check_summary('The Events Calendar production-readiness contract');
}
