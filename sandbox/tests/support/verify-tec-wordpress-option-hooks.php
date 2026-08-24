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

$args = getopt('', [
    'wordpress-root:',
    'version:',
    'fixture::',
    'tec-root::',
    'tec-version::',
]);
$wordpressRoot = rtrim((string) ($args['wordpress-root'] ?? ''), '/');
$version = (string) ($args['version'] ?? '');
$tecRoot = rtrim((string) ($args['tec-root'] ?? ''), '/');
$tecVersion = (string) ($args['tec-version'] ?? '');
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
$isLargeOption = tec_option_function_body($option, 'wp_filter_default_autoload_value_via_option_size');
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
$lastSaveDerived = [
    'get_option' => [
        'pre_option_tribe_last_save_post',
        'pre_option',
        'pre_wp_load_alloptions',
        'pre_cache_alloptions',
        'alloptions',
        'default_option_tribe_last_save_post',
        'option_tribe_last_save_post',
    ],
    'sanitize' => ['sanitize_option_tribe_last_save_post'],
    'update_existing_fixed_autoload' => [
        'pre_update_option_tribe_last_save_post',
        'pre_update_option',
        'update_option',
        'wp_autoload_values_to_autoload',
        'update_option_tribe_last_save_post',
        'updated_option',
    ],
    'update_existing_computed_autoload' => [
        'pre_update_option_tribe_last_save_post',
        'pre_update_option',
        'update_option',
        'wp_autoload_values_to_autoload',
        'wp_default_autoload_value',
        'wp_max_autoloaded_option_size',
        'update_option_tribe_last_save_post',
        'updated_option',
    ],
    'add_absent' => [
        'add_option',
        'wp_autoload_values_to_autoload',
        'wp_default_autoload_value',
        'wp_max_autoloaded_option_size',
        'add_option_tribe_last_save_post',
        'added_option',
    ],
];
if (($fixture['last_save_paths'] ?? null) !== $lastSaveDerived) {
    tec_option_usage('native save-post hook topology disagrees with the reviewed fixture');
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
tec_option_require_call(
    $isLargeOption,
    "apply_filters( 'wp_max_autoloaded_option_size'",
    'maximum autoloaded-option size filter'
);
foreach (['wp_cache_get', 'wp_cache_set', 'wp_cache_delete'] as $function) {
    $body = tec_option_function_body($sources['wp-includes/cache.php'], $function);
    if (str_contains($body, 'apply_filters(') || str_contains($body, 'do_action(')) {
        tec_option_usage("stock WordPress cache wrapper $function gained callback topology");
    }
}

$verifiedTecSources = null;
if ($tecRoot !== '' || $tecVersion !== '') {
    if ($tecRoot === '' || $tecVersion === '') {
        tec_option_usage('tec-root and tec-version must be supplied together');
    }
    $verifiedTecSources = $fixture['tec_service_sources'][$tecVersion] ?? null;
    if (!is_array($verifiedTecSources) || $verifiedTecSources === []) {
        tec_option_usage("TEC version $tecVersion is not service-source pinned");
    }
    $tecSources = [];
    foreach ($verifiedTecSources as $relative => $digest) {
        if (!is_string($relative)
            || !is_string($digest)
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            tec_option_usage('TEC service-source fixture is malformed');
        }
        $bytes = @file_get_contents("$tecRoot/$relative");
        if (!is_string($bytes) || !hash_equals($digest, hash('sha256', $bytes))) {
            tec_option_usage("TEC $tecVersion source digest disagrees for $relative");
        }
        $tecSources[$relative] = $bytes;
    }
    $customizer = $tecSources['common/src/Tribe/Customizer.php'] ?? '';
    $customizerConstructor = tec_option_function_body($customizer, '__construct');
    $customizerFallback = tec_option_function_body($customizer, 'maybe_fallback_get_option');
    $customizerGet = tec_option_function_body($customizer, 'get_option');
    $customizerActive = tec_option_function_body($customizer, 'is_active');
    $compactPhp = static fn(string $source): string => (string) preg_replace('/\s+/', '', $source);
    $constructorCompact = $compactPhp($customizerConstructor);
    $fallbackCompact = $compactPhp($customizerFallback);
    $getCompact = $compactPhp($customizerGet);
    $activeCompact = $compactPhp($customizerActive);
    foreach ([
        [$constructorCompact, "if(!\$this->is_active()){return;}", 'Customizer activation refusal'],
        [
            $constructorCompact,
            "\$this->ID=apply_filters('tribe_customizer_panel_id','tribe_customizer',\$this);",
            'canonical Customizer panel/option identity',
        ],
        [
            $constructorCompact,
            "add_filter(\"default_option_{\$this->ID}\",[\$this,'maybe_fallback_get_option']);",
            'canonical-row-absence fallback callback',
        ],
        [
            $fallbackCompact,
            "if(!empty(\$sections)){return\$sections;}returnget_option('tribe_events_pro_customizer',[]);",
            'legacy Customizer fallback precedence',
        ],
        [$getCompact, "\$sections=get_option(\$this->ID,\$default);", 'native canonical Customizer read'],
        [
            $getCompact,
            "apply_filters('tribe_events_pro_customizer_pre_get_option',\$sections,\$search)",
            'legacy Customizer value filter',
        ],
        [
            $getCompact,
            "apply_filters('tribe_customizer_pre_get_option',\$sections,\$search)",
            'canonical Customizer pre-value filter',
        ],
        [
            $getCompact,
            "apply_filters('tribe_customizer_get_option',\$option,\$search,\$sections)",
            'canonical Customizer result filter',
        ],
        [
            $activeCompact,
            "returnapply_filters('tribe_customizer_is_active',true);",
            'Customizer activation filter',
        ],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lost exact $label");
        }
    }
    if (!str_contains($customizer, 'final class Tribe__Customizer')
        || str_contains($customizer, 'tribe_events_customizer')) {
        tec_option_usage("TEC $tecVersion Customizer class/name topology disagrees with the reviewed boundary");
    }
    $customizerFixture = [
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
    if (($fixture['customizer_fallback'] ?? null) !== $customizerFixture) {
        tec_option_usage('source-derived Customizer fallback topology disagrees with the reviewed fixture');
    }
    foreach ([
        'common/src/Tribe/Cache.php' =>
            "update_option( 'tribe_last_' . \$action, (float) \$timestamp )",
        'common/src/Tribe/Cache_Listener.php' =>
            '$this->cache = new Tribe__Cache()',
        'common/src/Tribe/Settings_Manager.php' =>
            "if ( Tribe__Main::OPTIONNAME !== \$option ) { return; }",
        'common/src/Common/Libraries/Harbor.php' =>
            '$this->container->register( PUE::class )',
        'common/src/Common/Integrations/Harbor/PUE.php' =>
            "if ( ! str_starts_with( \$option, 'pue_install_key_' ) ) { return \$value; }",
        'src/Events/Custom_Tables/V1/Models/Occurrence.php' =>
            'tribe()->make( Occurrences_Generator::class )',
        'src/Events/Custom_Tables/V1/Models/Builder.php' =>
            'tribe( Configuration::class )',
        'src/Tribe/Aggregator.php' =>
            "if ( 'pue_install_key_event_aggregator' !== \$option ) { return false; }",
        'src/Tribe/Views/V2/Hooks.php' =>
            "if ( 'WPLANG' !== \$option ) { return; }",
        'src/Tribe/Views/V2/Service_Provider.php' =>
            '$this->container->singleton( Hooks::class, $hooks )',
    ] as $relative => $needle) {
        $source = preg_replace('/\s+/', ' ', $tecSources[$relative] ?? '');
        $needle = preg_replace('/\s+/', ' ', $needle);
        if (!is_string($source) || !is_string($needle) || !str_contains($source, $needle)) {
            tec_option_usage("TEC $tecVersion lost exact native effect $relative");
        }
    }
}

fwrite(STDOUT, json_encode([
    'format' => $fixture['format'] ?? null,
    'version' => $version,
    'source_files' => $pin,
    'shared_paths' => $derived,
    'last_save_paths' => $lastSaveDerived,
    'customizer_fallback' => $fixture['customizer_fallback'] ?? null,
    'tec_version' => $tecVersion === '' ? null : $tecVersion,
    'tec_service_sources' => $verifiedTecSources,
    'proved_absent' => $fixture['proved_absent'] ?? null,
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
