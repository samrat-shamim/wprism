<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once __DIR__ . '/../../lib/' . $file;
require_once __DIR__ . '/../../support/wp-block-parser-stub.php';
require_once __DIR__ . '/../../support/wp-shortcode-stub.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Grammar/Blocks.php';
require_once $root . '/agent/src/Kernel/BlockContentGrammar.php';
require_once $root . '/agent/src/Kernel/BlockAttributeReader.php';
require_once $root . '/agent/src/Kernel/PersonalData.php';
wprism_test_define_agent_versions();

use WPrism\AdapterContractGrammar;
use WPrism\BlockAttributeReader;
use WPrism\BlockContentGrammar;
use WPrism\Blocks;
use WPrism\PersonalData;
use WPrism\Policy;
use WPrism\Secrets;
use WPrism\Tokens;

$manifest = ['name' => 'content-fixture', 'spec_version' => 3, 'interpreter' => 'content-fixture',
    'engine_features' => [BlockContentGrammar::FEATURE, 'spec-window/v1'],
    'options' => ['fixture-key' => ['class' => 'env', 'required' => true]],
    'block_attrs' => ['fixture/content' => [['path' => 'address', 'codec' => 'content-fixture']]],
    'block_content' => ['fixture/content' => ['codec' => 'content-fixture', 'env_options' => ['fixture-key'], 'public_text' => ['address']]]];
AdapterContractGrammar::validate_adapter_contract($manifest);
BlockContentGrammar::validate($manifest);
wprism_check_same($manifest['block_content'], BlockContentGrammar::project([$manifest]), 'negotiated leaf grammar projects exactly');
foreach (['v2', 'feature', 'key', 'owner', 'attribute-owner', 'missing-attrs', 'optional-env', 'authored-env', 'structured-env',
    'unknown-env', 'duplicate-env', 'wildcard-env', 'public-unknown', 'public-duplicate', 'public-secret'] as $fault) {
    $bad = $manifest;
    $rule = &$bad['block_content']['fixture/content'];
    if ($fault === 'v2') $bad['spec_version'] = 2;
    if ($fault === 'feature') $bad['engine_features'] = ['spec-window/v1'];
    if ($fault === 'key') $rule['extra'] = true;
    if ($fault === 'owner') $rule['codec'] = 'other';
    if ($fault === 'attribute-owner') $bad['block_attrs']['fixture/content'][0]['codec'] = 'other';
    if ($fault === 'missing-attrs') unset($bad['block_attrs']);
    if ($fault === 'optional-env') $bad['options']['fixture-key']['required'] = false;
    if ($fault === 'authored-env') $bad['options']['fixture-key']['class'] = 'authored';
    if ($fault === 'structured-env') $bad['options']['fixture-key']['sub_keys'] = ['x' => ['class' => 'env']];
    if ($fault === 'unknown-env') $rule['env_options'] = ['foreign-key'];
    if ($fault === 'duplicate-env') $rule['env_options'][] = 'fixture-key';
    if ($fault === 'wildcard-env') $rule['env_options'] = ['fixture-*'];
    if ($fault === 'public-unknown') $rule['public_text'] = ['unknown'];
    if ($fault === 'public-duplicate') $rule['public_text'][] = 'address';
    if ($fault === 'public-secret') {
        $bad['block_attrs']['fixture/content'][] = ['path' => 'apiKey', 'codec' => 'content-fixture'];
        $rule['public_text'] = ['apiKey'];
    }
    unset($rule);
    wprism_check_throws(static fn() => BlockContentGrammar::validate($bad), RuntimeException::class, "$fault refuses");
}
foreach (['block_attrs', 'block_values', 'block_content'] as $section) {
    $other = ['name' => 'other', $section => ['fixture/content' => [['path' => 'x', 'lint_ok' => true]]]];
    wprism_check_throws(static fn() => BlockContentGrammar::project([$manifest, $other]), RuntimeException::class, "$section foreign ownership refuses");
    wprism_check_throws(static fn() => BlockContentGrammar::project([$manifest], $other), RuntimeException::class, "$section site override refuses");
}
wprism_check_throws(static fn() => BlockContentGrammar::project([$manifest], ['options' => ['fixture-key' => ['class' => 'authored']]]), RuntimeException::class, 'env binding cannot be reclassified by site');

// The fake substitutes only an interpreter's return, not the engine dispatch,
// shape boundary, native parser, or legacy attribute return contract.
$codec = new class {
    public mixed $result = ['attrs' => ['address' => 'Public destination'], 'html' => '<p>canonical</p>'];
    public array $environment = [];
    public function capture_block_content(array $block, Tokens $tokens, bool $force, string $label): mixed { return $this->result; }
    public function apply_block_content(array $block, Tokens $tokens, array $environment): mixed { $this->environment = $environment; return $this->result; }
    public function capture_block_attributes(array $block, Tokens $tokens): array { return ['address' => 'Legacy']; }
    public function apply_block_attributes(array $block, Tokens $tokens): array { return ['address' => 'Legacy']; }
};
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$policy->manifests = [$manifest];
$policy->site = [];
(new ReflectionProperty(Policy::class, 'interpreterInstances'))->setValue($policy, ['content-fixture' => $codec]);
$tokens = new Tokens('https://source.test', 'https://source.test/uploads');
$body = '<!-- wp:fixture/content {"address":"Public destination"} --><p>source</p><!-- /wp:fixture/content -->';
$captured = Blocks::capture_rewrite($body, $policy, $tokens);
wprism_check(str_contains($captured, '<p>canonical</p>') && !str_contains($captured, '<p>source</p>'), 'content hook replaces saved HTML');
wprism_check_throws(static fn() => Blocks::apply_rewrite($captured, $policy, $tokens), RuntimeException::class, 'unbound target refuses');
$tokens->bind_block_environment_options(['fixture-key' => 'target-key', 'foreign-key' => 'not-granted']);
Blocks::apply_rewrite($captured, $policy, $tokens);
wprism_check_same(['fixture-key' => 'target-key'], $codec->environment, 'interpreter receives only its declared environment options');
foreach ([null, [], ['attrs' => [], 'html' => 42], ['attrs' => [], 'html' => '<p/>', 'extra' => true],
    ['attrs' => ['unknown' => 'x'], 'html' => '<p/>'], ['attrs' => ['list'], 'html' => '<p/>'],
    ['attrs' => [], 'html' => '<!-- wp:paragraph --><p/>'], ['attrs' => [], 'html' => str_repeat('x', 1048577)]] as $index => $result) {
    $codec->result = $result;
    wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $tokens), RuntimeException::class, "malformed result $index refuses");
}
$codec->result = ['attrs' => [], 'html' => '<p/>'];
foreach (['<!-- wp:fixture/content /-->', str_replace('<p>source</p>', '<!-- wp:paragraph --><p>child</p><!-- /wp:paragraph -->', $body)] as $index => $invalid) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($invalid, $policy, $tokens), RuntimeException::class, "nonleaf shape $index refuses");
}
unset($policy->manifests[0]['block_content']);
$legacy = Blocks::capture_rewrite($body, $policy, $tokens);
wprism_check(str_contains($legacy, 'Legacy') && str_contains($legacy, '<p>source</p>'), 'legacy codec keeps attributes-only behavior');
$rules = BlockContentGrammar::project([$manifest]);
wprism_check(PersonalData::match_deep('body', BlockAttributeReader::clearance_value($body)) !== null, 'undeclared address retains PII role');
wprism_check_same(null, PersonalData::match_deep('body', BlockAttributeReader::clearance_value($body, $rules)), 'reviewed public scalar removes only semantic key role');
foreach (['person@example.com', 'api_key=Abcdef1234567890-'] as $value) {
    $unsafe = str_replace('Public destination', $value, $body);
    $clearance = BlockAttributeReader::clearance_value($unsafe, $rules);
    wprism_check(PersonalData::match_deep('body', $clearance) !== null || Secrets::clearance_match_deep('body', $clearance) !== null, 'public text retains value-level safety scanning');
}
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Apply/BlockEnvironmentOptions.php';
$policy->manifests = [$manifest];
$scratch = sys_get_temp_dir() . '/wprism-block-env-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
register_shutdown_function(static function () use ($scratch): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($scratch);
});
$db = WPrismTest\FakeWpdb::install()->enableInformationSchema()
    ->seedTable('wp_options', [['option_id' => 1, 'option_name' => 'fixture-key', 'option_value' => 'target-key', 'autoload' => 'off']])
    ->setTableEngine('wp_options', 'InnoDB')
    ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
    ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
        'Non_unique' => 0, 'Sub_part' => null, 'Index_type' => 'BTREE']]);
$originalRows = $db->rows('wp_options');
WPrism\Db::start_repeatable_read('block environment fixture', new WPrism\NativeDatabaseProfile(['wp_options'], []));
WPrism\DeleteGuardEvaluator::begin_authored_transaction();
WPrism\CacheInvalidationTransaction::begin();
try {
    wprism_check_throws(static fn() => WPrism\BlockEnvironmentOptions::lock($policy, $scratch), RuntimeException::class,
        'live option without intended file refuses', 'target-local intent');
    WPrism\EnvironmentValues::set($scratch, 'fixture-key', 'target-key');
    wprism_check_same(['fixture-key' => 'target-key'], WPrism\BlockEnvironmentOptions::lock($policy, $scratch), 'exact locked native row matches intended bytes');
    WPrism\EnvironmentValues::set($scratch, 'fixture-key', 'rotated-key');
    wprism_check_throws(static fn() => WPrism\BlockEnvironmentOptions::lock($policy, $scratch), RuntimeException::class,
        'concurrent intended-value rotation refuses stale native row', 'target-local intent');
    wprism_check_same($originalRows, $db->rows('wp_options'), 'binding observation never writes target options');
    $locks = array_values(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, 'FOR UPDATE')));
    wprism_check(count($locks) >= 2 && str_contains($locks[0], "option_name = 'fixture-key'"), 'binding uses exact locking reads');
} finally {
    WPrism\Db::rollback('block environment fixture');
    WPrism\CacheInvalidationTransaction::end();
    WPrism\DeleteGuardEvaluator::end_authored_transaction();
}
wprism_check_throws(static fn() => WPrism\BlockEnvironmentOptions::lock($policy, $scratch), RuntimeException::class,
    'binding outside authored transaction refuses', 'outside the authored transaction');
wprism_check_summary('block content codecs');
