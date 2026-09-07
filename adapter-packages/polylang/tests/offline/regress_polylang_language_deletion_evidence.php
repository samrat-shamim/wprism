<?php
declare(strict_types=1);

if (($argv[1] ?? '') === 'native-control') {
    // Execute the actual capsule writer against narrow native-API doubles.
    // No SQL fake or native-library evidence claim is made by this lane.
    define('POLYLANG_VERSION', '3.8.6');
    $fault = $argv[3];
    $args = [$argv[2]];
    class PLL_Language {
        public function get_tax_props(string $field): array { return ['language' => 31, 'term_language' => 32]; }
    }
    class WP_Error {}
    $fixtureModel = new class($args[0], $fault) {
        public array $slugs;
        public bool $deleted = false;
        public function __construct(string $mode, public string $fault) { $this->slugs = $mode === 'add' ? ['ar', 'en', 'fr'] : ['ar', 'en', 'fr', 'nl']; }
        public function get_languages_list(array $args): array { return $this->slugs; }
        public function get_language(string $slug): mixed { return in_array($slug, $this->slugs, true) ? new PLL_Language() : false; }
        public function add_language(array $args): mixed {
            if ($args !== ['locale' => 'nl_NL', 'name' => 'Nederlands deletion witness', 'slug' => 'nl', 'rtl' => false, 'flag' => 'nl', 'no_default_cat' => true]) {
                throw new RuntimeException('native add omitted its reviewed no-content-creation premise');
            }
            if ($this->fault === 'add-error') return new WP_Error();
            $this->slugs[] = 'nl';
            return new PLL_Language();
        }
        public function delete_language(int $id): bool {
            if ($id !== 31) throw new RuntimeException('delete was not bound to the exact language identity');
            if ($this->fault === 'delete-false') return false;
            $this->deleted = true;
            if ($this->fault !== 'retained-roster') $this->slugs = ['ar', 'en', 'fr'];
            return true;
        }
    };
    function PLL(): object { return (object) ['model' => $GLOBALS['fixtureModel']]; }
    function get_option(string $name): array { return ['default_lang' => $GLOBALS['fixtureModel']->deleted && $GLOBALS['fault'] === 'default-change' ? 'ar' : 'fr']; }
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    function get_objects_in_term(int $id, string $taxonomy): array { return $GLOBALS['fault'] === 'used' ? [7] : []; }
    function get_term_meta(int $id, string $key, bool $single): string { return $GLOBALS['fault'] === 'unmapped' ? '' : ($id === 31 ? '10000000-0000-4000-8000-000000000001' : '20000000-0000-4000-8000-000000000002'); }
    function get_term(int $id, string $taxonomy): mixed { return $GLOBALS['fault'] === 'retained-term' ? new stdClass() : null; }
    require dirname(__DIR__, 2) . '/fixtures/polylang_language_deletion_native.php';
    exit(0);
}

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once dirname(__DIR__, 2) . '/fixtures/polylang_language_deletion_evidence.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;
use WPrism\PrivateRefusalEvidence;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\ShellProbe;

$scratch = sys_get_temp_dir() . '/wprism-polylang-deletion-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) unlink($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$write = static function (string $path, string $bytes): void { file_put_contents($path, $bytes); chmod($path, 0600); };
$transport = static function (string $stage, string|array $value, int $status = 0, string $stderr = '') use ($scratch, $write): void {
    $write("$scratch/$stage.stdout", is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR) . "\n");
    $write("$scratch/$stage.stderr", $stderr);
    $write("$scratch/$stage.exit", "$status\n");
};
$control = ['format' => 'polylang-language-deletion-control/v1', 'mode' => 'delete', 'identities' => [
    'language' => ['term_id' => 31, 'uuid' => '10000000-0000-4000-8000-000000000001'],
    'term_language' => ['term_id' => 32, 'uuid' => '20000000-0000-4000-8000-000000000002'],
], 'languages' => ['ar', 'en', 'fr'], 'default_lang' => 'fr'];
foreach (['add' => ['ready', 'add-error', 'used'], 'delete' => ['ready', 'used', 'unmapped', 'delete-false', 'retained-roster', 'retained-term', 'default-change']] as $mode => $faults) {
    foreach ($faults as $fault) {
        [$status, $output, $diagnostic] = ShellProbe::run('exec "$1" "$2" native-control "$3" "$4"', [PHP_BINARY, __FILE__, $mode, $fault], $root);
        wprism_check($fault === 'ready' ? $status === 0 && $diagnostic === '' : $status !== 0, "actual native writer classifies $mode $fault through its declared API");
        if ($fault === 'ready') {
            PolylangLanguageDeletionEvidence::assertControl(json_decode($output, true, 32, JSON_THROW_ON_ERROR), $mode);
            wprism_check(true, 'actual native writer receipt satisfies the host closed control grammar');
        }
    }
}
PolylangLanguageDeletionEvidence::assertControl($control, 'delete');
wprism_check_same('term:language', PolylangLanguageDeletionEvidence::selector($control), 'expected selector is bound to the pre-command compiled UUID order');
$reversed = $control;
$reversed['identities'] = ['language' => $control['identities']['term_language'], 'term_language' => $control['identities']['language']];
wprism_check_same('term:term_language', PolylangLanguageDeletionEvidence::selector($reversed), 'either genuine native taxonomy may own the first missing UUID, independently of the observed refusal');
foreach (['missing', 'wrong-mode', 'used-roster', 'default', 'zero-id', 'string-id', 'duplicate-id', 'invalid-uuid', 'array-uuid', 'duplicate-uuid'] as $fault) {
    $bad = $control;
    switch ($fault) {
        case 'missing': unset($bad['identities']['term_language']); break;
        case 'wrong-mode': $bad['mode'] = 'add'; break;
        case 'used-roster': $bad['languages'][] = 'nl'; break;
        case 'default': $bad['default_lang'] = 'nl'; break;
        case 'zero-id': $bad['identities']['language']['term_id'] = 0; break;
        case 'string-id': $bad['identities']['language']['term_id'] = '31'; break;
        case 'duplicate-id': $bad['identities']['term_language']['term_id'] = 31; break;
        case 'invalid-uuid': $bad['identities']['language']['uuid'] = 'unknown'; break;
        case 'array-uuid': $bad['identities']['language']['uuid'] = []; break;
        case 'duplicate-uuid': $bad['identities']['term_language']['uuid'] = $control['identities']['language']['uuid']; break;
    }
    wprism_check_throws(static fn() => PolylangLanguageDeletionEvidence::assertControl($bad, 'delete'), RuntimeException::class, "native deletion control refuses $fault");
}

// Synthetic evidence transport, not a native database/API pass. The live
// caller obtains these complete bytes from independent dump and tree readers.
$repo = "$scratch/repo";
mkdir("$repo/media", 0700, true);
$write("$repo/site.wprism.json", "private exact policy\n");
$write("$repo/media/fixture.bin", "binary\0\xff\r\n");
foreach ($control['identities'] as $taxonomy => $identity) {
    mkdir("$repo/state/terms/$taxonomy", 0700, true);
    $write("$repo/state/terms/$taxonomy/{$identity['uuid']}.json", Canon::encode(['uuid' => $identity['uuid'],
        'taxonomy' => $taxonomy, 'slug' => $taxonomy === 'language' ? 'nl' : 'pll_nl']));
}
$trees = [];
foreach (['state', 'media', 'site.wprism.json'] as $boundary) $trees[$boundary] = FilesystemTreeEvidence::capture($repo, $boundary);
$snapshot = ['format' => 'polylang-language-deletion-preservation/v1', 'control' => $control, 'trees' => $trees];
$tables = ['wp_commentmeta', 'wp_comments', 'wp_links', 'wp_options', 'wp_postmeta', 'wp_posts', 'wp_term_relationships',
    'wp_term_taxonomy', 'wp_termmeta', 'wp_terms', 'wp_usermeta', 'wp_users', 'wp_wprism_map', 'wp_wprism_state'];
$roster = implode('', array_map(static fn(string $table): string => "$table\tBASE TABLE\n", $tables));
$dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
foreach ($tables as $table) {
    $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n  `id` int NOT NULL\n);\n-- Dumping data for table `$table`\n";
    if ($table === 'wp_wprism_map') {
        foreach ($control['identities'] as $identity) $dump .= "INSERT INTO `$table` (`uuid`, `local_id`) VALUES ('{$identity['uuid']}',{$identity['term_id']});\n";
    } else $dump .= "INSERT INTO `$table` (`id`) VALUES (1);\n";
}
$dump .= "-- Dump completed\n";
$selector = PolylangLanguageDeletionEvidence::selector($control);
$reason = new CommandRefusalException('unsupported_deletion',
    "deletion intent for $selector is unsupported because no pinned adapter owns its destructive semantics",
    'restore the missing source entity, or pin a compatible adapter that declares the required reverse-reference guards and cascade effects before trying again',
    [['code' => 'unsupported_deletion', 'surface' => $selector, 'message' => 'no pinned adapter declares this deletion selector',
        'remediation' => 'restore the missing source entity or pin a compatible adapter with complete deletion guards and cascade effects']],
    "wprism: deletion intent for $selector is unsupported — no pinned adapter declares its reverse-reference checks and cascade effects");
$public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'capture', 'reason_code' => 'unsupported_deletion', ...$reason->payload()];
mkdir("$scratch/refusals", 0700);
$baseline = PrivateRefusalReceipt::diagnosticSnapshot("$scratch/refusals", 'capture');
$raw = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'capture', 'reason_code' => 'unsupported_deletion', ...PrivateRefusalEvidence::graph($reason)];
$write("$scratch/refusals/20260907-090001-capture-111111111111111111111111.json", json_encode($raw, JSON_THROW_ON_ERROR));
$diagnostic = PrivateRefusalReceipt::diagnosticNewRecords("$scratch/refusals", $baseline, 'capture');
$reset = static function () use ($transport, $snapshot, $roster, $dump, $public, $diagnostic): void {
    foreach (['before', 'after'] as $stage) {
        $transport($stage, $snapshot); $transport("$stage-tables", $roster); $transport("$stage-database", $dump);
    }
    $transport('command', $public, 1);
    $transport('private', $diagnostic);
};
$reset();
PolylangLanguageDeletionEvidence::verify($scratch, 'polyoffline');
wprism_check(true, 'complete synthetic retained streams pass the actual deletion evidence verifier');
foreach (['command-success', 'wrong-public', 'unknown-private', 'missing-stale-map', 'changed-database', 'changed-media',
    'changed-policy', 'changed-state', 'missing-captured-identity', 'partial-dump', 'empty-roster', 'database-stderr', 'database-exit',
    'private-exit', 'snapshot-stderr'] as $fault) {
    $reset();
    switch ($fault) {
        case 'command-success': $transport('command', $public); break;
        case 'wrong-public': $transport('command', array_replace($public, ['reason_code' => 'capture_failed']), 1); break;
        case 'unknown-private':
            $bad = json_decode($diagnostic, true, 32, JSON_THROW_ON_ERROR);
            $bytes = json_encode(array_replace($raw, PrivateRefusalEvidence::graph(new RuntimeException('orphaned term'))), JSON_THROW_ON_ERROR);
            $bad['records'][0]['contents_base64'] = base64_encode($bytes); $bad['records'][0]['bytes'] = strlen($bytes); $bad['records'][0]['sha256'] = hash('sha256', $bytes);
            $transport('private', $bad); break;
        case 'missing-stale-map':
            foreach (['before', 'after'] as $stage) $transport("$stage-database", str_replace($control['identities']['language']['uuid'], '30000000-0000-4000-8000-000000000003', $dump));
            break;
        case 'changed-database': $transport('after-database', str_replace('VALUES (1)', 'VALUES (2)', $dump)); break;
        case 'changed-media': case 'changed-policy': case 'changed-state':
            $bad = $snapshot;
            $boundary = ['changed-media' => 'media', 'changed-policy' => 'site.wprism.json', 'changed-state' => 'state'][$fault];
            $bad['trees'][$boundary]['files'][0]['mtime']++;
            $transport('after', $bad); break;
        case 'missing-captured-identity':
            $bad = $snapshot; $bad['trees']['state']['files'] = [];
            $transport('before', $bad); $transport('after', $bad); break;
        case 'partial-dump': $transport('before-database', substr($dump, 0, -18)); break;
        case 'empty-roster': $transport('before-tables', ''); break;
        case 'database-stderr': $transport('before-database', $dump, 0, "PHP Warning: fixture\n"); break;
        case 'database-exit': $transport('before-database', $dump, 1); break;
        case 'private-exit': $transport('private', $diagnostic, 1); break;
        case 'snapshot-stderr': $transport('before', $snapshot, 0, "PHP Warning: fixture\n"); break;
    }
    wprism_check_throws(static fn() => PolylangLanguageDeletionEvidence::verify($scratch, 'polyoffline'), RuntimeException::class, "actual retained deletion verifier refuses $fault");
}

// The snapshot caller also executes the real WordPress-free compiler, not
// only a fabricated transport record. A term_language has no term_group
// authority; a language does. Keep the two genuine canonical shapes distinct.
$compileRepo = "$scratch/compiled-repo";
foreach (['state/options', 'state/terms/language', 'state/terms/term_language', 'media'] as $directory) mkdir("$compileRepo/$directory", 0700, true);
$write("$compileRepo/site.wprism.json", Canon::encode(['spec_version' => WPRISM_SPEC_VERSION,
    'manifests' => ['core', 'polylang'], 'policy' => ['post_types' => [], 'taxonomies' => ['language', 'term_language']]]));
$options = [];
foreach (['active_plugins', 'blog_public', 'blogdescription', 'blogname', 'default_category', 'page_for_posts', 'page_on_front',
    'permalink_structure', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet', 'template', 'wp_page_for_privacy_policy', 'polylang'] as $name) {
    $options[$name] = \WPrism\OptionState::absent();
}
$options['polylang'] = \WPrism\OptionState::present(['browser' => false, 'default_lang' => 'fr', 'force_lang' => 1, 'hide_default' => true,
    'media_support' => false, 'nav_menus' => [], 'post_types' => [], 'redirect_lang' => false, 'rewrite' => true, 'sync' => [], 'taxonomies' => []], 'yes');
$write("$compileRepo/state/options/core.json", Canon::encode(\WPrism\OptionState::document($options)));
foreach (['ar' => ['ar', 'sa'], 'en' => ['en_US', 'us'], 'fr' => ['fr_FR', 'fr'], 'nl' => ['nl_NL', 'nl']] as $slug => [$locale, $flag]) {
    $uuid = $slug === 'nl' ? $control['identities']['language']['uuid'] : match ($slug) {
        'ar' => '30000000-0000-4000-8000-000000000003', 'en' => '40000000-0000-4000-8000-000000000004', 'fr' => '50000000-0000-4000-8000-000000000005',
    };
    $write("$compileRepo/state/terms/language/$uuid--$slug.json", Canon::encode(['uuid' => $uuid, 'taxonomy' => 'language',
        'name' => $slug, 'slug' => $slug, 'parent' => null, 'description' => serialize(['locale' => $locale, 'rtl' => $slug === 'ar', 'flag_code' => $flag]),
        'term_group' => 0, 'meta' => (object) [], 'relationships' => (object) []]));
}
$uuid = $control['identities']['term_language']['uuid'];
$write("$compileRepo/state/terms/term_language/$uuid--pll_nl.json", Canon::encode(['uuid' => $uuid, 'taxonomy' => 'term_language',
    'name' => 'nl', 'slug' => 'pll_nl', 'parent' => null, 'description' => '', 'meta' => (object) [], 'relationships' => (object) []]));
$proof = PolylangLanguageDeletionEvidence::snapshot($compileRepo, $control);
$transport('compiled', $proof);
wprism_check_same($proof, PolylangLanguageDeletionEvidence::readSnapshot("$scratch/compiled"), 'actual compiler-backed deletion snapshot survives retained re-admission');
$transport('delete', $control);
[$status, $output, $diagnostic] = ShellProbe::run('exec "$1" "$2" snapshot "$3" "$4" "$5"',
    [PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/polylang_language_deletion_evidence.php', 'compiled-repo', "$scratch/delete", 'polyoffline'], $scratch);
wprism_check($status === 0 && $diagnostic === '', 'the real standalone snapshot entrypoint compiles a caller-relative repository without WordPress');
wprism_check_same($proof, json_decode($output, true, 32, JSON_THROW_ON_ERROR), 'relative native-evidence caller retains the complete same compiled identity and private trees');
$badControl = $control;
$badControl['identities']['language']['uuid'] = '90000000-0000-4000-8000-000000000009';
wprism_check_throws(static fn() => PolylangLanguageDeletionEvidence::snapshot($compileRepo, $badControl), RuntimeException::class,
    'compiler-backed snapshot refuses a valid-looking but uncaptured native identity');

$shell = file_get_contents(dirname(__DIR__, 2) . '/fixtures/polylang-language-deletion.sh');
$start = strpos($shell, '  # Protected command');
$end = strpos($shell, "\n)", $start);
if ($start === false || $end === false) throw new RuntimeException('actual deletion command/collection block is missing');
$block = substr($shell, $start, $end - $start);
$probe = <<<'SH'
set -euo pipefail
root="$1" sink="$2" fault="$3" helper=fixture repo=fixture pair=polyoffline
. "$root/sandbox/tests/lib/private_command_capture.sh"
fail() { printf 'REFUSED\n'; exit 1; }
pass() { printf 'VERIFIED\n'; }
wp_conf1() {
  printf '%s\n' "$1-$2" >>"$sink/order"
  if [ "$1" = wprism ]; then [ "$fault" = command-success ] && return 0; return 1; fi
  [ "$fault" != database-failure ]
}
php() {
  printf '%s\n' "$2" >>"$sink/order"
  if [ "$2" = snapshot ]; then [ "$fault" != snapshot-failure ]; else [ "$fault" = ready ]; fi
}
conformance_private_command_native() { printf 'private\n' >>"$sink/order"; [ "$fault" != private-failure ]; }
SH;
foreach (['ready', 'command-success', 'snapshot-failure', 'database-failure', 'private-failure'] as $fault) {
    $lane = "$scratch/$fault";
    mkdir($lane, 0700);
    [$status, $output] = ShellProbe::run($probe . "\n" . $block, [$root, $lane, $fault], $root);
    $order = file_get_contents("$lane/order");
    wprism_check(str_starts_with($order, "wprism-capture\nsnapshot\ndb-query\ndb-export\nprivate\n"), "actual shell retains every postimage and private cause for $fault");
    wprism_check($fault === 'ready' ? $status === 0 && str_contains($output, 'VERIFIED') : $status !== 0 && !str_contains($output, 'VERIFIED'),
        "actual shell cannot report a deletion pass for $fault without silent successful verification");
}
$transportProbe = <<<'SH'
set -euo pipefail
. "$1/sandbox/tests/lib/wordpress_cron_window.sh"
. "$1/adapter-packages/polylang/fixtures/polylang-language-deletion.sh"
COMPOSE='fixture_compose -p polyoffline'
fixture_compose() {
  [ "$#" -eq 17 ] && [ "$*" = '-p polyoffline run --rm -T --no-deps --user root --workdir /var/www/html/wp-content/mu-plugins --entrypoint sh cli1 -s -- prepare 11111111111111111111111111111111' ]
}
polylang_language_deletion_mu -s -- prepare 11111111111111111111111111111111
SH;
[$transportStatus] = ShellProbe::run($transportProbe, [$root], $root);
wprism_check($transportStatus === 0, 'actual deletion guard callback binds shared root-owned MU transport to source cli1');
wprism_check_summary('Polylang language deletion evidence');
