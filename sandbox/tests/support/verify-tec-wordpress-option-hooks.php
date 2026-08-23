<?php
declare(strict_types=1);

/**
 * Reproduce the TEC Category Colors WordPress option/cache hook fixture from
 * an exact extracted core tree. Whole-file hashes bind the artifact, while
 * tokenized function bodies prove each admitted call path and absence claim.
 */

/** @return never */
function tec_option_usage(string $message): void {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

/** Return one named top-level function body without comments. */
function tec_option_function_body(string $source, string $name): string {
    $state = 0;
    $depth = 0;
    $body = '';
    foreach (token_get_all($source) as $token) {
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        if ($state === 0) {
            if ($id === T_FUNCTION) {
                $state = 1;
            }
            continue;
        }
        if ($state === 1) {
            if ($id === T_STRING) {
                $state = strcasecmp($text, $name) === 0 ? 2 : 0;
            }
            continue;
        }
        if ($state === 2) {
            if ($text === '{') {
                $state = 3;
                $depth = 1;
            }
            continue;
        }
        if ($text === '{') {
            ++$depth;
        } elseif ($text === '}') {
            --$depth;
            if ($depth === 0) {
                return $body;
            }
        }
        if ($id !== T_COMMENT && $id !== T_DOC_COMMENT) {
            $body .= $text;
        }
    }
    tec_option_usage("WordPress source does not expose function $name");
}

function tec_option_require_call(string $body, string $needle, string $label): void {
    if (!str_contains($body, $needle)) {
        tec_option_usage("WordPress source lost $label");
    }
}

$args = getopt('', ['wordpress-root:', 'version:', 'fixture::']);
$wordpressRoot = rtrim((string) ($args['wordpress-root'] ?? ''), '/');
$version = (string) ($args['version'] ?? '');
$fixturePath = (string) ($args['fixture']
    ?? dirname(__DIR__) . '/fixtures/the-events-calendar-wordpress-option-hooks.json');
if ($wordpressRoot === '' || $version === '') {
    tec_option_usage(
        'usage: php verify-tec-wordpress-option-hooks.php '
        . '--wordpress-root=/path/to/wordpress --version=X.Y.Z [--fixture=/path/to/fixture.json]'
    );
}
$fixtureBytes = @file_get_contents($fixturePath);
if (!is_string($fixtureBytes)) {
    tec_option_usage('hook fixture is unreadable');
}
try {
    $fixture = json_decode($fixtureBytes, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    tec_option_usage('hook fixture is malformed JSON');
}
$pin = $fixture['source_files'][$version] ?? null;
if (!is_array($pin)) {
    tec_option_usage("WordPress version $version is not fixture-pinned");
}
$paths = [
    'option_php_sha256' => 'wp-includes/option.php',
    'formatting_php_sha256' => 'wp-includes/formatting.php',
    'cache_php_sha256' => 'wp-includes/cache.php',
    'object_cache_php_sha256' => 'wp-includes/class-wp-object-cache.php',
];
$sources = [];
foreach ($paths as $digestKey => $relative) {
    $path = "$wordpressRoot/$relative";
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)) {
        tec_option_usage("exact WordPress source is unreadable: $relative");
    }
    if (!is_string($pin[$digestKey] ?? null)
        || !hash_equals($pin[$digestKey], hash('sha256', $bytes))) {
        tec_option_usage("WordPress $version source digest disagrees for $relative");
    }
    $sources[$relative] = $bytes;
}

$option = $sources['wp-includes/option.php'];
$formatting = $sources['wp-includes/formatting.php'];
$get = tec_option_function_body($option, 'get_option');
$loadAll = tec_option_function_body($option, 'wp_load_alloptions');
$update = tec_option_function_body($option, 'update_option');
$add = tec_option_function_body($option, 'add_option');
$autoloadValues = tec_option_function_body($option, 'wp_autoload_values_to_autoload');
$determineAutoload = tec_option_function_body($option, 'wp_determine_option_autoload_value');
$sanitize = tec_option_function_body($formatting, 'sanitize_option');

foreach ([
    [$get, 'apply_filters( "pre_option_{$option}"', 'dynamic pre-option filter'],
    [$get, "apply_filters( 'pre_option'", 'global pre-option filter'],
    [$get, 'wp_load_alloptions()', 'alloptions preload path'],
    [$get, 'apply_filters( "default_option_{$option}"', 'dynamic default-option filter'],
    [$get, 'apply_filters( "option_{$option}"', 'dynamic option filter'],
    [$loadAll, "apply_filters( 'pre_wp_load_alloptions'", 'pre alloptions filter'],
    [$loadAll, "apply_filters( 'pre_cache_alloptions'", 'pre-cache alloptions filter'],
    [$loadAll, "apply_filters( 'alloptions'", 'alloptions result filter'],
    [$sanitize, 'apply_filters( "sanitize_option_{$option}"', 'dynamic sanitize-option filter'],
    [$update, 'apply_filters( "pre_update_option_{$option}"', 'dynamic pre-update filter'],
    [$update, "apply_filters( 'pre_update_option'", 'global pre-update filter'],
    [$update, "do_action( 'update_option'", 'global update action'],
    [$update, 'do_action( "update_option_{$option}"', 'dynamic update action'],
    [$update, "do_action( 'updated_option'", 'updated-option action'],
    [$update, 'return add_option( $option, $value, \'\', $autoload )', 'absent-row add delegation'],
    [$add, "do_action( 'add_option'", 'global add action'],
    [$add, 'do_action( "add_option_{$option}"', 'dynamic add action'],
    [$add, "do_action( 'added_option'", 'added-option action'],
    [$autoloadValues, "apply_filters( 'wp_autoload_values_to_autoload'", 'autoload-values filter'],
] as [$body, $needle, $label]) {
    tec_option_require_call($body, $needle, $label);
}

$derived = [
    'get_option' => [
        'pre_option_tec_events_category_color_css',
        'pre_option',
        'pre_wp_load_alloptions',
        'pre_cache_alloptions',
        'alloptions',
        'default_option_tec_events_category_color_css',
        'option_tec_events_category_color_css',
    ],
    'sanitize' => ['sanitize_option_tec_events_category_color_css'],
    'update_existing' => [
        'pre_update_option_tec_events_category_color_css',
        'pre_update_option',
        'update_option',
        'wp_autoload_values_to_autoload',
        'update_option_tec_events_category_color_css',
        'updated_option',
    ],
    'add_absent' => [
        'add_option',
        'wp_autoload_values_to_autoload',
        'add_option_tec_events_category_color_css',
        'added_option',
    ],
];
if (($fixture['shared_paths'] ?? null) !== $derived) {
    tec_option_usage('source-derived hook topology disagrees with the reviewed fixture');
}

$codeOnly = '';
foreach (token_get_all($option . "\n" . $formatting) as $token) {
    if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
        continue;
    }
    $codeOnly .= is_array($token) ? $token[1] : $token;
}
foreach ([
    "apply_filters( 'default_option'",
    'pre_add_option',
    'updated_option_{$option}',
    'added_option_{$option}',
] as $inventedCall) {
    if (str_contains($codeOnly, $inventedCall)) {
        tec_option_usage("exact source unexpectedly exposes refused hook $inventedCall");
    }
}
$booleanReturn = strpos($determineAutoload, 'if ( is_bool( $autoload ) )');
$defaultAutoloadFilter = strpos($determineAutoload, "apply_filters( 'wp_default_autoload_value'");
if ($booleanReturn === false || $defaultAutoloadFilter === false || $booleanReturn >= $defaultAutoloadFilter) {
    tec_option_usage('explicit autoload=true no longer bypasses default-autoload filters');
}
foreach (['wp_cache_get', 'wp_cache_set', 'wp_cache_delete'] as $function) {
    $body = tec_option_function_body($sources['wp-includes/cache.php'], $function);
    if (str_contains($body, 'apply_filters(') || str_contains($body, 'do_action(')) {
        tec_option_usage("stock WordPress cache wrapper $function gained callback topology");
    }
}

fwrite(STDOUT, json_encode([
    'format' => $fixture['format'] ?? null,
    'version' => $version,
    'source_files' => $pin,
    'shared_paths' => $derived,
    'proved_absent' => $fixture['proved_absent'] ?? null,
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
