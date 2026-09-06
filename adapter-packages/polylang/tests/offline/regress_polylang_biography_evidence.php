<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once dirname(__DIR__, 2) . '/fixtures/polylang_biography_evidence.php';

use WPrism\Canon;
use WPrism\UserMetaState;
use WPrismTest\EvidenceSizeProfile;
use WPrismTest\FilesystemTreeEvidence;

// Admission controls only. Differential oracle answers below are deliberately
// fabricated records, not a WordPress KSES implementation or native evidence.
$native = static function (string $home, string $id): array {
    $values = PolylangBiographyValues::authored($home);
    $meta = [];
    $api = [];
    foreach ($values as $key => $value) {
        $meta[] = ['meta_id' => (string) (count($meta) + 1), 'meta_key' => $key, 'meta_value' => $value];
        $api[$key] = [$value];
    }
    $meta[] = ['meta_id' => '4', 'meta_key' => 'runtime-neighbor', 'meta_value' => 'complete native preimage'];
    $oracle = [];
    foreach (PolylangBiographyValues::hostile() as $name => $value) $oracle[$name] = ['input' => $value, 'sanitized' => 'test-only differential'];
    return [
        'format' => 'polylang-biography-native/v1', 'home' => $home, 'admin_id' => $id,
        'users' => [[
            'ID' => $id, 'user_login' => 'admin', 'user_pass' => 'private test fixture only', 'user_nicename' => 'admin',
            'user_email' => 'admin@example.test', 'user_url' => '', 'user_registered' => '2026-09-06 00:00:00',
            'user_activation_key' => '', 'user_status' => '0', 'display_name' => 'Admin',
        ]],
        'metadata' => [['user_id' => $id, 'rows' => $meta]], 'biographies' => $api,
        'default_shadow' => [], 'orphan_rows' => '0', 'hostile_oracle' => $oracle,
    ];
};
$source = $native('http://localhost:9400', '1001');
PolylangBiographyEvidence::assertNative($source);
PolylangBiographyEvidence::assertNative($native('http://localhost:9401', '9001'));
wprism_check(!function_exists('wp_kses'), 'biography evidence admission and compiler dependencies remain WordPress-free');
wprism_check_same(['description', 'description_fr', 'description_ar'], array_keys(PolylangBiographyValues::authored('{{home}}')),
    'fixture follows native default and nondefault biography key ownership');
foreach (['missing-users', 'empty-users', 'duplicate-user', 'overflow-user', 'wrong-login', 'foreign-field', 'missing-owner',
    'wrong-owner', 'duplicate-meta', 'overflow-meta', 'null-meta', 'wrong-native-value', 'missing-native-value', 'wrong-api',
    'raw-shadow', 'api-shadow', 'missing-oracle', 'equal-oracle', 'altered-oracle', 'orphan', 'omitted-orphan', 'wrong-home'] as $fault) {
    $bad = $source;
    switch ($fault) {
        case 'missing-users': unset($bad['users']); break;
        case 'empty-users': $bad['users'] = []; break;
        case 'duplicate-user': $bad['users'][] = $bad['users'][0]; $bad['metadata'][] = $bad['metadata'][0]; break;
        case 'overflow-user': $bad['users'][0]['ID'] = '99999999999999999999999999'; break;
        case 'wrong-login': $bad['users'][0]['user_login'] = 'Admin'; break;
        case 'foreign-field': $bad['users'][0]['ignored'] = 'not allowed'; break;
        case 'missing-owner': $bad['metadata'] = []; break;
        case 'wrong-owner': $bad['metadata'][0]['user_id'] = '1002'; break;
        case 'duplicate-meta': $bad['metadata'][0]['rows'][] = $bad['metadata'][0]['rows'][0]; break;
        case 'overflow-meta': $bad['metadata'][0]['rows'][0]['meta_id'] = '99999999999999999999999999'; break;
        case 'null-meta': $bad['metadata'][0]['rows'][0]['meta_value'] = null; break;
        case 'wrong-native-value': $bad['metadata'][0]['rows'][0]['meta_value'] = 'stale target'; break;
        case 'missing-native-value': array_shift($bad['metadata'][0]['rows']); break;
        case 'wrong-api': $bad['biographies']['description_fr'] = ['different']; break;
        case 'raw-shadow': $bad['metadata'][0]['rows'][] = ['meta_id' => '5', 'meta_key' => 'description_en', 'meta_value' => 'shadow']; break;
        case 'api-shadow': $bad['default_shadow'] = ['shadow']; break;
        case 'missing-oracle': unset($bad['hostile_oracle']['data-uri']); break;
        case 'equal-oracle': $bad['hostile_oracle']['script']['sanitized'] = $bad['hostile_oracle']['script']['input']; break;
        case 'altered-oracle': $bad['hostile_oracle']['script']['input'] = 'not the declared control'; break;
        case 'orphan': $bad['orphan_rows'] = '1'; break;
        case 'omitted-orphan': unset($bad['orphan_rows']); break;
        case 'wrong-home': $bad['home'] = 'http://localhost'; break;
    }
    wprism_check_throws(static fn() => PolylangBiographyEvidence::assertNative($bad), Throwable::class, "native biography admission refuses $fault");
}

$scratch = sys_get_temp_dir() . '/wprism-polylang-biography-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/user-meta', 0700, true);
$path = $scratch . '/state/' . UserMetaState::path('admin');
$body = $scratch . '/state/long-body.md';
register_shutdown_function(static function () use ($path, $body, $scratch): void {
    unlink($path); unlink($body); rmdir($scratch . '/state/user-meta'); rmdir($scratch . '/state'); rmdir($scratch);
});
$document = UserMetaState::document('admin', PolylangBiographyValues::authored('{{home}}'));
file_put_contents($path, Canon::encode($document));
file_put_contents($body, str_repeat('x', 310800));
$tree = FilesystemTreeEvidence::capture($scratch, 'state', EvidenceSizeProfile::CONFORMANCE_TREE);
$record = ['format' => 'polylang-biography-evidence/v1', 'native' => $source, 'tree' => $tree, 'compiled' => Canon::decode(Canon::encode($document))];
PolylangBiographyEvidence::assertRecord($record);
wprism_check_same(310800, $tree['files'][0]['bytes'], 'complete evidence retains the existing conformance-sized body, not just the selected biography');
foreach (['bad-bytes', 'no-sidecar', 'wrong-token', 'wrong-compiled', 'extra-envelope', 'wrong-format'] as $fault) {
    $bad = $record;
    switch ($fault) {
        case 'bad-bytes': $bad['tree']['files'][0]['contents_base64'] = base64_encode('changed'); break;
        case 'no-sidecar': array_pop($bad['tree']['files']); break;
        case 'wrong-token':
            $content = str_replace('{{home}}', 'http://localhost:9400', base64_decode($bad['tree']['files'][1]['contents_base64'], true));
            $bad['tree']['files'][1]['contents_base64'] = base64_encode($content);
            $bad['tree']['files'][1]['bytes'] = strlen($content);
            $bad['tree']['files'][1]['sha256'] = hash('sha256', $content);
            break;
        case 'wrong-compiled': $bad['compiled']['meta']['description'] = 'changed'; break;
        case 'extra-envelope': $bad['verified'] = true; break;
        case 'wrong-format': $bad['format'] = 'polylang-biography-evidence/v2'; break;
    }
    wprism_check_throws(static fn() => PolylangBiographyEvidence::assertRecord($bad), Throwable::class, "retained biography evidence refuses $fault");
}
$seedProbe = <<<'SH'
set -euo pipefail
umask 077
root="$1" WPRISM_ARTIFACT_LIBRARY_ROOT="$1" CONF_REPO1="$2" CONF_REPO2="$2" CONF_PAIR=biographytest
side="$3" answer="$4" diagnostic="$5" status="$6"
if [ "$7" = matrix-source ]; then unset CONF_PAIR; PAIR=biographytest; fi
fail() { printf '%s\n' "$*" >&2; exit 1; }
mktemp() {
  [ "$#" -eq 2 ] && [ "$1" = -d ] && [ "$2" = "$root/sandbox/tmp/polylang-biography-seed.biographytest.XXXXXX" ] || return 58
  command mktemp -d "$CONF_REPO1/private.XXXXXX"
}
. "$root/sandbox/conformance/asserts.sh"
. "$root/adapter-packages/polylang/fixtures/polylang-biography.sh"
fixture_wp() {
  [ "$#" -eq 3 ] && [ "$1" = eval-file ] && [ "$2" = /siterepo/.tmp-polylang-biography/polylang_biography_native.php ] \
    && [ "$3" = "seed-$side" ] || return 57
  printf '%s' "$answer"
  printf '%s' "$diagnostic" >&2
  return "$status"
}
wp_conf1() { fixture_wp "$@"; }
wp_conf2() { fixture_wp "$@"; }
polylang_biography_seed "$side"
printf 'SEED_READY\n'
SH;
foreach (['source', 'target', 'matrix-source', 'nonzero', 'empty', 'stdout-warning', 'stderr-warning', 'wrong-keys', 'wrong-mode', 'two-json', 'occupied', 'symlink', 'bad-side'] as $fault) {
    $probeRoot = $scratch . '/probe-' . $fault;
    mkdir($probeRoot, 0700);
    $staged = $probeRoot . '/.tmp-polylang-biography';
    $side = $fault === 'target' ? 'target' : ($fault === 'bad-side' ? 'foreign' : 'source');
    $answer = ['format' => 'polylang-biography-seed/v1', 'mode' => 'seed-' . $side, 'keys' => PolylangBiographyValues::KEYS];
    if ($fault === 'wrong-keys') $answer['keys'][0] = 'description_en';
    if ($fault === 'wrong-mode') $answer['mode'] = 'seed-target';
    $stdout = json_encode($answer, JSON_THROW_ON_ERROR) . "\n";
    if ($fault === 'empty') $stdout = '';
    if ($fault === 'stdout-warning') $stdout = "PHP Warning: fixture\n" . $stdout;
    if ($fault === 'two-json') $stdout .= $stdout;
    if ($fault === 'occupied') mkdir($staged, 0700);
    if ($fault === 'symlink') symlink($scratch . '/state', $staged);
    try {
        [$status, $output] = \WPrismTest\ShellProbe::run($seedProbe,
            [$root, $probeRoot, $side, $stdout, $fault === 'stderr-warning' ? "PHP Warning: fixture\n" : '', $fault === 'nonzero' ? '7' : '0', $fault], $root . '/sandbox');
        $positive = in_array($fault, ['source', 'target', 'matrix-source'], true);
        wprism_check($positive ? $status === 0 && str_contains($output, "SEED_READY\n") : $status !== 0 && !str_contains($output, 'SEED_READY'),
            "actual biography seed command/acceptance classifies $fault");
        if ($positive) {
            clearstatcache();
            wprism_check_same(0755, fileperms($staged) & 0777, 'fixture staging remains traversable across the WordPress uid boundary');
            wprism_check_same(0644, fileperms($staged . '/polylang_biography_native.php') & 0777, 'fixture code remains readable under a private caller umask');
        }
    } finally {
        if (is_link($staged)) unlink($staged);
        elseif (is_dir($staged)) {
            foreach (['polylang_biography_values.php', 'polylang_biography_native.php'] as $file) {
                if (is_file($staged . '/' . $file)) unlink($staged . '/' . $file);
            }
            rmdir($staged);
        }
        foreach (new FilesystemIterator($probeRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isDir() || $entry->isLink() || preg_match('/^private\.[a-zA-Z0-9]{6}$/D', $entry->getFilename()) !== 1) {
                throw new RuntimeException('biography probe left an unexpected scratch node');
            }
            foreach (['native', 'admission'] as $stage) {
                foreach (['stdout', 'stderr', 'exit'] as $suffix) {
                    $file = $entry->getPathname() . '/' . $stage . '.' . $suffix;
                    if (is_file($file) && !is_link($file)) unlink($file);
                }
            }
            rmdir($entry->getPathname());
        }
        rmdir($probeRoot);
    }
}
wprism_check_summary('Polylang biography evidence admission');
