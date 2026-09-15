<?php
declare(strict_types=1);

/**
 * Offline mechanism test for runtime/providers/contact-form-7-form-content.php:
 * the SHIPPED providers[] declaration, bound through the engine loader's own
 * contract join, driving the real ProviderSdk transaction over the shared
 * row-backed $wpdb.
 *
 * CF7 itself is a stub here. Its loader reads the same property rows the real
 * WPCF7_ContactForm::construct_properties() reads (current spelling first, then
 * the pre-3.3 name), and wpcf7_array_flatten() is CF7 6.1.7's
 * includes/functions.php:113 semantics. What this proves is the provider's
 * contract — batch, witness, fence, write, readback, receipt — not CF7's own
 * derivation; the live conformance check compares the target body with CF7's
 * real derivation of its applied properties.
 */

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Adapter/ProviderSdk.php';
require_once dirname(__DIR__, 2) . '/package/runtime/providers/contact-form-7-form-content.php';

use WPrism\Db;
use WPrism\DatabaseQueryIsolation;
use WPrism\Providers;
use WPrism\Providers\ContactForm7FormContent;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** CF7 6.1.7 includes/functions.php:113. */
function wpcf7_array_flatten($input) {
    if (!is_array($input)) {
        return [$input];
    }
    $output = [];
    foreach ($input as $value) {
        $output = array_merge($output, wpcf7_array_flatten($value));
    }
    return $output;
}

function clean_post_cache($post): void {
    $GLOBALS['cf7_provider_cleaned'][] = (int) $post;
}

final class WPCF7_ContactForm {
    /** @var null|callable(int):void runs between the provider's two derivation witnesses */
    public static $onLoad = null;

    private function __construct(private int $formId, private array $properties) {
    }

    public static function get_instance($post) {
        global $wpdb;
        $id = (int) $post;
        $row = $wpdb->get_row($wpdb->prepare("SELECT ID, post_type FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
        if (!is_array($row) || $row['post_type'] !== 'wpcf7_contact_form') {
            return null;
        }
        if (self::$onLoad !== null) {
            (self::$onLoad)($id);
        }
        $meta = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id), ARRAY_A) as $m) {
            $meta[$m['meta_key']] = $m['meta_value'];
        }
        $properties = [];
        foreach (['form', 'mail', 'mail_2', 'messages', 'additional_settings'] as $name) {
            $raw = $meta['_' . $name] ?? $meta[$name] ?? '';
            $value = @unserialize((string) $raw);
            $properties[$name] = ($value === false && $raw !== serialize(false)) ? (string) $raw : $value;
        }
        return new self($id, $properties);
    }

    public function id(): int {
        return $this->formId;
    }

    public function get_properties(): array {
        return $this->properties;
    }
}

const CF7_PROVIDER_MANIFEST = __DIR__ . '/../../package/manifest.json';

/** @return array<string,mixed> */
function cf7_provider_properties(string $tag, bool $legacy = false): array {
    $mail = ['subject' => "$tag subject", 'sender' => 'Site <ops@example.test>', 'body' => "$tag body",
        'recipient' => 'ops@example.test', 'additional_headers' => '', 'attachments' => '',
        'use_html' => false, 'exclude_blank' => true];
    $properties = [
        'form' => "  <label>$tag</label> [submit]",
        'mail' => $mail,
        'mail_2' => ['active' => false] + $mail,
        'messages' => ['mail_sent_ok' => "$tag sent"],
        'additional_settings' => "demo_mode: on\n",
    ];
    $meta = ['_hash' => str_repeat('a', 64), '_locale' => 'en_US'];
    foreach ($properties as $name => $value) {
        $meta[($legacy ? '' : '_') . $name] = $value;
    }
    return $meta;
}

/**
 * @param array<int,array{type?:string,content?:string,meta?:array<string,mixed>}> $forms
 * @return array{0:FakeWpdb,1:ContactForm7FormContent}
 */
function cf7_provider_fixture(array $forms): array {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['cf7_provider_cleaned'] = [];
    WPCF7_ContactForm::$onLoad = null;
    $posts = [];
    $meta = [];
    $metaId = 100;
    foreach ($forms as $id => $form) {
        $posts[] = ['ID' => $id, 'post_type' => $form['type'] ?? 'wpcf7_contact_form',
            'post_title' => "Form $id", 'post_name' => "form-$id", 'post_content' => $form['content'] ?? ''];
        foreach ($form['meta'] ?? [] as $key => $value) {
            $meta[] = ['meta_id' => ++$metaId, 'post_id' => $id, 'meta_key' => $key,
                'meta_value' => is_array($value) || is_bool($value) ? serialize($value) : (string) $value];
        }
    }
    $db = FakeWpdb::install()->enableInformationSchema()
        ->seedTable('posts', $posts)
        ->setColumns('posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)', 'post_title' => 'text',
            'post_name' => 'varchar(200)', 'post_content' => 'longtext'])
        ->setTableEngine('posts', 'InnoDB')
        ->seedTable('postmeta', $meta)
        ->setColumns('postmeta', ['meta_id' => 'bigint unsigned', 'post_id' => 'bigint unsigned',
            'meta_key' => 'varchar(255)', 'meta_value' => 'longtext'])
        ->setTableEngine('postmeta', 'InnoDB')
        ->seedTable('options', [])
        ->setColumns('options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
            'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setTableEngine('options', 'InnoDB');
    $manifest = json_decode((string) file_get_contents(CF7_PROVIDER_MANIFEST), true, 512, JSON_THROW_ON_ERROR);
    $runtime = new ContactForm7FormContent($manifest['providers'][0]);
    // The engine loader's own contract join, so ProviderSdk grants exactly the
    // shipped reads/writes and nothing a hand-written declaration could widen.
    (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
    return [$db, $runtime];
}

/** @param list<int> $ids @return array{entities:list<array{kind:string,id:int}>} */
function cf7_provider_batch(array $ids): array {
    return ['entities' => array_map(static fn(int $id): array => ['kind' => 'post:wpcf7_contact_form', 'id' => $id], $ids)];
}

/** @return list<string> */
function cf7_provider_post_writes(FakeWpdb $db): array {
    return array_values(array_filter(
        $db->queries(),
        static fn(string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b[^;]*\bwp_posts\b/i', $sql) === 1
    ));
}

/** @return list<string> */
function cf7_provider_meta_writes(FakeWpdb $db): array {
    return array_values(array_filter(
        $db->queries(),
        static fn(string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b[^;]*\bwp_postmeta\b/i', $sql) === 1
    ));
}

/** @return array<int,string> */
function cf7_provider_bodies(FakeWpdb $db): array {
    $out = [];
    foreach ($db->rows('posts') as $row) {
        $out[(int) $row['ID']] = (string) $row['post_content'];
    }
    return $out;
}

// CF7's derivation of cf7_provider_properties('Alpha'), spelled out by hand so a
// changed separator, a lost trim, or a reordered flatten is caught here rather
// than agreeing with itself: form, mail (8 leaves), mail_2 (9), messages (1),
// additional_settings; false renders as '' and true as '1'; the leading spaces of
// the form and the trailing newline of additional_settings are what trim() removes.
$alphaDerived = "<label>Alpha</label> [submit]\n"
    . "Alpha subject\nSite <ops@example.test>\nAlpha body\nops@example.test\n\n\n\n1\n"
    . "\nAlpha subject\nSite <ops@example.test>\nAlpha body\nops@example.test\n\n\n\n1\n"
    . "Alpha sent\n"
    . "demo_mode: on";

echo "\n== a form apply created carries '' until the provider derives it ==\n";
[$db, $runtime] = cf7_provider_fixture([5 => ['content' => '', 'meta' => cf7_provider_properties('Alpha')]]);
$db->resetLog();
$receipt = $runtime->invoke('rebuild_form_content', cf7_provider_batch([5]));
wprism_check_same($alphaDerived, cf7_provider_bodies($db)[5], 'the stored body is exactly CF7\'s trimmed newline flattening of the applied properties');
wprism_check_same(1, count(cf7_provider_post_writes($db)), 'exactly one posts write, for the one form whose body differed');
wprism_check_same([], cf7_provider_meta_writes($db), 'no property meta is written: the provider never calls save()');
wprism_check_same(
    [true, ['forms' => 1, 'derived' => 0], ['forms' => 1, 'derived' => 1]],
    [$receipt['verified'] ?? null,
        array_intersect_key($receipt['before'], ['forms' => 0, 'derived' => 0]),
        array_intersect_key($receipt['after'], ['forms' => 0, 'derived' => 0])],
    'the receipt is value-verified: zero of one derived before, one of one after'
);
wprism_check(
    !str_contains(json_encode($receipt, JSON_THROW_ON_ERROR), 'ops@example.test')
        && !str_contains(json_encode($receipt, JSON_THROW_ON_ERROR), 'Alpha'),
    'the receipt carries counts and digests, never body bytes or the addresses they hold'
);
wprism_check(in_array(5, $GLOBALS['cf7_provider_cleaned'], true), 'the post cache is cleared so CF7 and WP_Query see the applied rows');
wprism_check(!DatabaseQueryIsolation::is_active(), 'the transaction owner settles query isolation');

echo "\n== a form apply updated keeps a stale derivation until the provider rewrites it ==\n";
[$db, $runtime] = cf7_provider_fixture([
    8 => ['content' => 'stale derivation of the previous properties', 'meta' => cf7_provider_properties('Beta', true)],
    5 => ['content' => '', 'meta' => cf7_provider_properties('Alpha')],
]);
$runtime->invoke('rebuild_form_content', cf7_provider_batch([8, 5]));
$bodies = cf7_provider_bodies($db);
wprism_check_same($alphaDerived, $bodies[5], 'a batch rebuilds every form it names');
wprism_check_same(str_replace('Alpha', 'Beta', $alphaDerived), $bodies[8], 'a legacy-spelling form derives from its pre-3.3 property rows through CF7\'s loader');

echo "\n== an already-derived form is left physically untouched ==\n";
[$db, $runtime] = cf7_provider_fixture([5 => ['content' => $alphaDerived, 'meta' => cf7_provider_properties('Alpha')]]);
$db->resetLog();
$receipt = $runtime->invoke('rebuild_form_content', cf7_provider_batch([5]));
wprism_check_same([], cf7_provider_post_writes($db), 'no write when the stored body already equals the derivation');
wprism_check_same([1, 1], [$receipt['before']['derived'], $receipt['after']['derived']], 'and the receipt says it was already derived');

echo "\n== the scoped contract: reconcile observes, invoke_after reconcile re-observes ==\n";
// Through the runtime's public scoped entry points, which activate the bound
// contract; the protected handlers are unreachable any other way in the engine.
$operation = ['format' => 'wprism-scoped-effect-operation/v1', 'id' => 'fixture-operation'];
[$db, $runtime] = cf7_provider_fixture([5 => ['content' => 'stale', 'meta' => cf7_provider_properties('Alpha')]]);
$db->resetLog();
$observed = $runtime->reconcile_scoped('rebuild_form_content', cf7_provider_batch([5]), $operation);
wprism_check_same(
    [true, 1, 0, []],
    [$observed['verified'], $observed['after']['forms'], $observed['after']['derived'], cf7_provider_post_writes($db)],
    'scoped reconciliation reports the stale body and writes nothing'
);
$scoped = $runtime->invoke_scoped('rebuild_form_content', cf7_provider_batch([5]), $operation);
wprism_check_same(
    [$operation, 0, 1, $alphaDerived],
    [$scoped['operation'], $scoped['before']['derived'], $scoped['after']['derived'], cf7_provider_bodies($db)[5]],
    'a scoped invocation rebuilds, and its postimage is an independent re-observation, not the handler\'s own claim'
);

echo "\n== every malformed batch and moved input refuses before any posts write ==\n";
$refuses = static function (array $forms, array $args, string $label, string $message, ?callable $arm = null): void {
    [$db, $runtime] = cf7_provider_fixture($forms);
    $before = cf7_provider_bodies($db);
    if ($arm !== null) {
        $arm($db);
    }
    $db->resetLog();
    wprism_check_throws(static fn() => $runtime->invoke('rebuild_form_content', $args), RuntimeException::class, $label, $message);
    $db->onQuery(null);
    wprism_check_same([], cf7_provider_post_writes($db), "$label: no posts write");
    wprism_check_same($before, cf7_provider_bodies($db), "$label: every stored body survives");
    wprism_check(!DatabaseQueryIsolation::is_active(), "$label: isolation is settled");
};
$alpha = [5 => ['content' => '', 'meta' => cf7_provider_properties('Alpha')]];
$refuses($alpha, cf7_provider_batch([5]) + ['extra' => true], 'an undeclared argument', 'no other arguments');
$refuses($alpha, ['entities' => []], 'an empty batch', 'bounded engine entity batch');
$refuses($alpha, ['entities' => [['kind' => 'post:page', 'id' => 5]]], 'a foreign surface', 'distinct post:wpcf7_contact_form ids');
$refuses($alpha, ['entities' => [['kind' => 'post:wpcf7_contact_form', 'id' => '5']]], 'a string id', 'distinct post:wpcf7_contact_form ids');
$refuses($alpha, cf7_provider_batch([5, 5]), 'a duplicate id', 'distinct post:wpcf7_contact_form ids');
$refuses(
    $alpha + [9 => ['type' => 'page', 'content' => 'a page', 'meta' => cf7_provider_properties('Page')]],
    cf7_provider_batch([5, 9]),
    'an id that is not a CF7 form row',
    'could not load form 9'
);
$refuses($alpha, cf7_provider_batch([5]), 'a property row that moves while CF7 reads it', 'changed while CF7 read them',
    static function (FakeWpdb $db): void {
        WPCF7_ContactForm::$onLoad = static function (int $id) use ($db): void {
            $rows = $db->rows('postmeta');
            foreach ($rows as $i => $row) {
                if ((int) $row['post_id'] === $id && $row['meta_key'] === '_form') {
                    $rows[$i]['meta_value'] = 'moved by a concurrent save';
                }
            }
            $db->seedTable('postmeta', $rows);
            WPCF7_ContactForm::$onLoad = null;
        };
    }
);
$refuses($alpha, cf7_provider_batch([5]), 'a property row that moves between derivation and the write', 'changed between derivation and write',
    static function (FakeWpdb $db): void {
        $db->onQuery(static function (string $sql, string $method, FakeWpdb $db): ?bool {
            if (preg_match('/^\s*START TRANSACTION\b/i', $sql) !== 1) {
                return null;
            }
            $rows = $db->rows('postmeta');
            foreach ($rows as $i => $row) {
                if ($row['meta_key'] === '_mail') {
                    $rows[$i]['meta_value'] = serialize(['subject' => 'moved']);
                }
            }
            $db->seedTable('postmeta', $rows);
            $db->onQuery(null);
            return null;
        });
    }
);

wprism_check_summary('Contact Form 7 form content provider');
