<?php
declare(strict_types=1);

// These are native source bytes, not proof of target WPForms execution. The
// actual package policy, PostCapture and immutable compiler are exercised;
// target replay below tests the shared body codec without claiming apply.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Capture/PostCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';

use WPrism\AdapterLibrary;
use WPrism\BodyRefGrammar;
use WPrism\Canon;
use WPrism\EntityMetaCapture;
use WPrism\MediaCapture;
use WPrism\Policy;
use WPrism\PostCapture;
use WPrism\RepositoryAuthorizationException;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

wprism_test_define_agent_versions();
WpStore::reset();
$wpdb = new FakeWpdb();
$library = AdapterLibrary::fromSourcePackage($root, 'wpforms-lite');
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: $library);
$fixtureBytes = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-authoring.json');
$fixture = json_decode($fixtureBytes, true, 512, JSON_THROW_ON_ERROR);
wprism_check_same('wprism-wpforms-native-fixtures/v1', $fixture['format'], 'native fixture framing is explicit');
wprism_check_same(11, count($fixture['cases']), 'all retained native body variants are exercised');
$builderFixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-builder-qr-authoring.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same('wprism-wpforms-builder-qr-fixtures/v1', $builderFixture['format'], 'browser-observed SQL body fixtures have separate exploratory provenance');
wprism_check_same([$fixture['version'], $fixture['artifact_sha256']], [$builderFixture['version'], $builderFixture['artifact_sha256']], 'browser body bytes use the same locked native release');
wprism_check_same(['builder-qr-baseline', 'builder-qr-stale-page', 'builder-qr-stale-url', 'builder-qr-generated-url', 'builder-qr-none'],
    array_keys($builderFixture['cases']), 'every measured Builder QR transition is retained');
$builderExpectedQr = [
    'builder-qr-baseline' => ['none', 0, '', ''],
    'builder-qr-stale-page' => ['page', 5, '', 'http://localhost:9544/wprism-qr-page-a/'],
    'builder-qr-stale-url' => ['url', 5, 'http://localhost:9544/wprism-qr-page-b/?label=701', 'http://localhost:9544/wprism-qr-page-a/'],
    'builder-qr-generated-url' => ['url', 5, 'http://localhost:9544/wprism-qr-page-b/?label=701', 'http://localhost:9544/wprism-qr-page-b/?label=701'],
    'builder-qr-none' => ['none', 0, '', ''],
];
$builderNonQr = null;
foreach ($builderFixture['cases'] as $name => $case) {
    wprism_check_same($case['body_sha256'], hash('sha256', $case['body']), "$name retains the measured complete native post_content bytes");
    $doc = json_decode($case['body'], false, 512, JSON_THROW_ON_ERROR);
    $settings = $doc->settings;
    wprism_check_same($builderExpectedQr[$name], [$settings->qr_code, $settings->qr_code_page_id, $settings->qr_code_url, $settings->qr_code_generated],
        "$name binds the selected identity, inactive value and independent generated snapshot with native scalar types");
    wprism_check_same(['6', 2, '1', 'wpforms', 0, []], [$doc->id, $doc->field_id, $doc->fields->{'1'}->id,
        $settings->qr_code_logo, $settings->qr_code_logo_id, $settings->form_tags], "$name preserves local field identity and legal untagged Lite logo state");
    foreach (['qr_code', 'qr_code_page_id', 'qr_code_url', 'qr_code_logo', 'qr_code_generated', 'qr_code_logo_id'] as $key) unset($settings->$key);
    $nonQr = json_encode($doc, JSON_THROW_ON_ERROR);
    $builderNonQr ??= $nonQr;
    wprism_check_same($builderNonQr, $nonQr, "$name retains the entire non-QR form, including nested theme JSON and provider controls");
}
wprism_check_same($builderFixture['cases']['builder-qr-baseline']['body'], $builderFixture['cases']['builder-qr-none']['body'],
    'native None returns the complete stored body to its first Builder-saved baseline');
$targetHome = 'https://target.example.test';
$uuidFor = static fn(int $id): string => '019200cc-0000-7000-8000-' . sprintf('%012d', $id);
$post = static fn(array $case, string $slug, string $body, int $id): object => (object) [
    'ID' => $id, 'post_type' => $case['post_type'], 'post_password' => '', 'post_parent' => 0,
    'post_author' => 0, 'post_name' => $slug, 'post_title' => 'Native capsule fixture', 'post_status' => 'publish',
    'post_date' => '2026-09-07 00:00:00', 'post_date_gmt' => '2026-09-07 00:00:00',
    'post_modified' => '2026-09-07 00:00:00', 'post_modified_gmt' => '2026-09-07 00:00:00',
    'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_excerpt' => '',
    'post_mime_type' => '', 'post_content' => $body,
];
$capture = static fn(Tokens $tokens): PostCapture => new PostCapture($policy, $tokens,
    new EntityMetaCapture($policy, $tokens, static function (): void {}, static function (): void {}, static function (): void {}),
    new MediaCapture());
$seedMap = static function (array $ids, int $offset = 0) use ($wpdb, $uuidFor): void {
    $rows = [];
    foreach (array_values(array_unique($ids)) as $i => $id) {
        $rows[] = ['id' => $i + 1, 'uuid' => $uuidFor($id), 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id + $offset];
    }
    $wpdb->seedTable('wp_wprism_map', $rows)->seedTable('wp_postmeta', []);
};
// Independent expected-value transform for only the measured fixture paths.
// It deliberately does not call a reference codec, JsonRefs or token encoder.
$expectedBody = static function (string $raw, array $case, bool $canonical) use ($uuidFor, $targetHome): string {
    $doc = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
    $reference = static fn(int $id): string|int => $canonical ? '{{post:' . $uuidFor($id) . '}}' : $id + 1000;
    if (isset($doc->id)) {
        $type = get_debug_type($doc->id);
        $id = (int) $doc->id;
        $doc->id = $canonical ? (object) ['format' => 'wprism-typed-reference/v1', 'type' => $type, 'ref' => $reference($id)]
            : ($type === 'string' ? (string) $reference($id) : $reference($id));
    }
    foreach ($doc->settings->confirmations ?? [] as $confirmation) {
        if (isset($confirmation->page) && $confirmation->page !== 'previous_page' && $confirmation->page !== '') {
            $confirmation->page = (string) $reference((int) $confirmation->page);
        }
    }
    foreach (['qr_code_page_id', 'qr_code_logo_id'] as $key) {
        if (!empty($doc->settings->$key)) {
            $doc->settings->$key = $reference((int) $doc->settings->$key);
        }
    }
    $text = static function (mixed $value) use (&$text, $case, $canonical, $targetHome, $reference): mixed {
        if (is_object($value) || is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_object($value)) {
                    $value->$key = $text($child);
                } else {
                    $value[$key] = $text($child);
                }
            }
        } elseif (is_string($value) && str_contains($value, $case['source_home'])) {
            $value = str_replace($case['source_home'], $canonical ? '{{home}}' : $targetHome, $value);
            if (isset($case['page_id'])) {
                foreach (['?p=', '?page_id='] as $parameter) {
                    $value = str_replace($parameter . $case['page_id'], $parameter . $reference($case['page_id']), $value);
                }
            }
        }
        return $value;
    };
    return json_encode($text($doc), JSON_THROW_ON_ERROR);
};

// The browser archive supplies complete stored bodies, not admitted HTTP or
// physical-preservation evidence. Replay stays the existing product codec;
// it does not turn this exploratory source run into Capture/Apply evidence.
foreach ($fixture['cases'] + $builderFixture['cases'] as $name => $case) {
    $ids = array_merge([$case['post_id']], $case['page_ids'] ?? []);
    foreach (['page_id', 'attachment_id'] as $key) {
        if (isset($case[$key])) {
            $ids[] = $case[$key];
        }
    }
    $seedMap($ids);
    $sourceTokens = new Tokens($case['source_home'], $case['source_home'] . '/wp-content/uploads');
    $sourceTokens->policy = $policy;
    $native = $case['body'];
    wprism_check_same($native, json_encode(json_decode($native, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR), "$name retains native JSON framing");
    $entity = $capture($sourceTokens)->capture($post($case, $name, $native, $case['post_id']), $uuidFor($case['post_id']), []);
    [, $canonical] = Canon::parse_post_file($entity['entity']['content']);
    wprism_check_same($expectedBody($native, $case, true), $canonical, "$name exact product capture rewrites only the declared IDs and owned URLs");
    wprism_check_same([], $sourceTokens->warnings, "$name captures without dangling refs or a warning fallback");
    $seedMap($ids, 1000);
    $targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
    $targetTokens->policy = $policy;
    $target = BodyRefGrammar::apply($canonical, $policy->body_ref_rule($case['post_type']),
        $targetTokens->token_to_id(...), $name, $targetTokens->detokenize_text(...));
    wprism_check_same($expectedBody($native, $case, false), $target, "$name exact target codec preserves local field IDs, sentinels and native scalar types");
    $recaptured = $capture($targetTokens)->capture($post($case, $name, $target, $case['post_id'] + 1000), $uuidFor($case['post_id']), []);
    wprism_check_same($entity['entity']['content'], $recaptured['entity']['content'], "$name complete canonical entity is a fixed point after divergent-ID replay");
    wprism_check_same([], $targetTokens->warnings, "$name target codec and recapture emit no warnings");
    if ($name === 'literal-notification') {
        $notificationEntity = $entity['entity'];
        $notificationPost = $post($case, $name, $native, $case['post_id']);
        $notificationCase = $case;
    }
}

// Locked settings-qr-code.min.js changes the selected destination without
// replacing its last-generated URL until onGenerate() succeeds. These two
// synthetic states pin that codec contract, not a native Builder/browser run.
$qrCase = $fixture['cases']['qr-page'];
foreach (['page', 'url'] as $destination) {
    $qrDoc = json_decode($qrCase['body'], false, 512, JSON_THROW_ON_ERROR);
    $qrDoc->settings->qr_code = $destination;
    $qrDoc->settings->qr_code_page_id = 41;
    $qrDoc->settings->qr_code_url = $qrCase['source_home'] . '/different-qr-destination/?label=701';
    $qrNative = json_encode($qrDoc, JSON_THROW_ON_ERROR);
    $seedMap([$qrCase['post_id'], $qrCase['page_id'], 41]);
    $sourceTokens = new Tokens($qrCase['source_home'], $qrCase['source_home'] . '/wp-content/uploads');
    $sourceTokens->policy = $policy;
    $qrEntity = $capture($sourceTokens)->capture($post($qrCase, 'qr-stale-' . $destination, $qrNative, $qrCase['post_id']),
        $uuidFor($qrCase['post_id']), []);
    [, $qrCanonical] = Canon::parse_post_file($qrEntity['entity']['content']);
    wprism_check_same($expectedBody($qrNative, $qrCase, true), $qrCanonical,
        "stale QR $destination captures the independent page ID and generated URL without normalization");
    $seedMap([$qrCase['post_id'], $qrCase['page_id'], 41], 1000);
    $targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
    $targetTokens->policy = $policy;
    $qrTarget = BodyRefGrammar::apply($qrCanonical, $policy->body_ref_rule('wpforms'),
        $targetTokens->token_to_id(...), 'qr-stale-' . $destination, $targetTokens->detokenize_text(...));
    wprism_check_same($expectedBody($qrNative, $qrCase, false), $qrTarget,
        "stale QR $destination remaps only declared identities and URLs across the complete body");
    $targetSettings = json_decode($qrTarget, true, 512, JSON_THROW_ON_ERROR)['settings'];
    wprism_check_same([1041, $targetHome . '/wprism-native-qr-destination/', $targetHome . '/different-qr-destination/?label=701'],
        [$targetSettings['qr_code_page_id'], $targetSettings['qr_code_generated'], $targetSettings['qr_code_url']],
        "stale QR $destination preserves the old generated destination, inactive input and non-reference numeric query");
    $qrRecaptured = $capture($targetTokens)->capture($post($qrCase, 'qr-stale-' . $destination, $qrTarget, $qrCase['post_id'] + 1000),
        $uuidFor($qrCase['post_id']), []);
    wprism_check_same($qrEntity['entity']['content'], $qrRecaptured['entity']['content'],
        "stale QR $destination complete canonical entity is a divergent-ID codec fixed point");
    wprism_check_same([[], []], [$sourceTokens->warnings, $targetTokens->warnings],
        "stale QR $destination needs no warning or fallback");
}

$compileRoot = sys_get_temp_dir() . '/wprism-wpforms-package-' . bin2hex(random_bytes(6));
mkdir($compileRoot . '/state/posts/wpforms', 0700, true);
register_shutdown_function(static function () use ($compileRoot): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($compileRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($compileRoot);
});
$site = ['spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'wpforms-lite'],
    'policy' => ['post_types' => ['wpforms', 'wpforms-template'], 'taxonomies' => []]];
Canon::write_file($compileRoot . '/site.wprism.json', Canon::encode($site));
$policy = Policy::load($compileRoot, ['core', 'wpforms-lite'], adapterLibrary: $library);
$path = $compileRoot . '/state/' . $notificationEntity['path'];
Canon::write_file($path, $notificationEntity['content']);
RepositoryCompiler::compile_staged($compileRoot . '/state', $compileRoot, $policy);
wprism_check_same($notificationEntity['content'], file_get_contents($path), 'actual immutable compiler admits native literal notification routing without altering source');
$seedMap([$notificationCase['post_id']]);
$foreignPost = $post($notificationCase, 'foreign-collision', 'Foreign local content', $notificationCase['post_id'] + 1000);
$wpdb->seedTable('wp_posts', [(array) $notificationPost, (array) $foreignPost]);
$tokens = new Tokens($notificationCase['source_home'], $notificationCase['source_home'] . '/wp-content/uploads');
$tokens->policy = $policy;
$positiveDoc = json_decode($notificationCase['body'], true, 512, JSON_THROW_ON_ERROR);
[$front, $canonical] = Canon::parse_post_file($notificationEntity['content']);
$canonicalDoc = json_decode($canonical, true, 512, JSON_THROW_ON_ERROR);
$hostile = [
    'default-pii' => static function (array $doc): array { $doc['fields'][1]['default_value'] = 'visitor@example.test'; return $doc; },
    'sibling-pii' => static function (array $doc): array { $doc['settings']['notifications'][1]['customer_email'] = 'visitor@example.test'; return $doc; },
    'reviewed-container' => static function (array $doc): array { $doc['settings']['notifications'][1]['email'] = ['value' => 'visitor@example.test']; return $doc; },
    'reviewed-key' => static function (array $doc): array { $doc['settings']['notifications']['visitor@example.test'] = ['email' => 'operations@example.test']; return $doc; },
    'secret-leaf' => static function (array $doc): array { $doc['settings']['notifications'][1]['email'] = 'sk_live_CAPSULESECRET1234567890'; return $doc; },
];
foreach ($hostile as $name => $mutate) {
    $badPost = clone $notificationPost;
    $badPost->post_content = json_encode($mutate($positiveDoc), JSON_THROW_ON_ERROR);
    $before = [$wpdb->rows('wp_posts'), $wpdb->rows('wp_postmeta'), $wpdb->rows('wp_wprism_map')];
    wprism_check_throws(static fn() => $capture($tokens)->capture($badPost, $uuidFor($notificationCase['post_id']), []), RuntimeException::class,
        "$name refuses through actual PostCapture", 'refusing to capture json authored configuration');
    $badCanonical = Canon::post_file($front, json_encode($mutate($canonicalDoc), JSON_THROW_ON_ERROR));
    Canon::write_file($path, $badCanonical);
    try {
        RepositoryCompiler::compile_staged($compileRoot . '/state', $compileRoot, $policy);
        wprism_check(false, "$name compiler must refuse independently authored hostile canonical input");
    } catch (RepositoryAuthorizationException $failure) {
        wprism_check_same([$name === 'secret-leaf' ? 'repository_secret_not_allowed' : 'repository_pii_not_allowed'],
            array_column($failure->diagnostics, 'code'), "$name compiler preserves the exact privacy/secret refusal boundary");
    }
    wprism_check_same($badCanonical, file_get_contents($path), "$name compiler refusal preserves full canonical bytes");
    wprism_check_same($before, [$wpdb->rows('wp_posts'), $wpdb->rows('wp_postmeta'), $wpdb->rows('wp_wprism_map')], "$name read-only refusals preserve all fixture rows");
}
wprism_check_same(Canon::encode($site), file_get_contents($compileRoot . '/site.wprism.json'), 'all compiler cases preserve source scope and package pins');
wprism_check_same($fixtureBytes, file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-authoring.json'), 'fixture source bytes stay unchanged');
wprism_check_summary('regress_wpforms_lite_native_bodies');
