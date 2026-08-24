<?php
declare(strict_types=1);

/**
 * Reproduce the TEC WordPress option/cache and Customizer registry fixture
 * from exact extracted core/plugin trees. Whole-file hashes bind each
 * artifact, while tokenized function bodies prove the admitted paths.
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

/** Decode the deliberately narrow single-quoted literals used by TEC registries. */
function tec_option_literal_string(string $token, string $label): string {
    if (preg_match("/^'(?:[^'\\\\]|\\\\['\\\\])*'$/Ds", $token) !== 1) {
        tec_option_usage("$label contains a non-literal string");
    }
    return (string) preg_replace_callback(
        "/\\\\(['\\\\])/",
        static fn(array $match): string => $match[1],
        substr($token, 1, -1)
    );
}

/**
 * Parse one method whose complete behavior is `return [literal => literal]`.
 * Nested literal maps are admitted; calls, constants, duplicate keys, and
 * trailing code refuse instead of being skipped by a permissive regex.
 *
 * @return array<string,mixed>
 */
function tec_option_literal_return_map(string $body, string $label): array {
    $tokens = [];
    foreach (token_get_all("<?php\n" . $body) as $token) {
        $id = is_array($token) ? $token[0] : null;
        if (in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $tokens[] = ['id' => $id, 'text' => is_array($token) ? $token[1] : $token];
    }
    if (($tokens[0]['id'] ?? null) !== T_RETURN) {
        tec_option_usage("$label is not one literal return map");
    }
    $position = 1;
    $parse = null;
    $parse = static function () use (&$parse, &$position, $tokens, $label): array {
        if (($tokens[$position]['text'] ?? null) !== '[') {
            tec_option_usage("$label does not use a literal array");
        }
        ++$position;
        $out = [];
        while (($tokens[$position]['text'] ?? null) !== ']') {
            $keyToken = $tokens[$position] ?? null;
            if (($keyToken['id'] ?? null) !== T_CONSTANT_ENCAPSED_STRING) {
                tec_option_usage("$label contains a non-literal key");
            }
            $key = tec_option_literal_string((string) $keyToken['text'], $label);
            if (array_key_exists($key, $out)) {
                tec_option_usage("$label contains a duplicate literal key");
            }
            ++$position;
            if (($tokens[$position]['id'] ?? null) !== T_DOUBLE_ARROW) {
                tec_option_usage("$label contains a key without a literal value");
            }
            ++$position;
            $valueToken = $tokens[$position] ?? null;
            if (($valueToken['text'] ?? null) === '[') {
                $value = $parse();
            } elseif (($valueToken['id'] ?? null) === T_CONSTANT_ENCAPSED_STRING) {
                $value = tec_option_literal_string((string) $valueToken['text'], $label);
                ++$position;
            } else {
                tec_option_usage("$label contains a non-literal value");
            }
            $out[$key] = $value;
            if (($tokens[$position]['text'] ?? null) === ',') {
                ++$position;
                continue;
            }
            if (($tokens[$position]['text'] ?? null) !== ']') {
                tec_option_usage("$label contains malformed literal-map punctuation");
            }
        }
        ++$position;
        return $out;
    };
    $out = $parse();
    if (($tokens[$position]['text'] ?? null) !== ';' || count($tokens) !== $position + 1) {
        tec_option_usage("$label contains behavior after its literal return map");
    }
    return $out;
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
    'legacy_widget_php_sha256' => 'wp-includes/blocks/legacy-widget.php',
    'legacy_widget_block_json_sha256' => 'wp-includes/blocks/legacy-widget/block.json',
    'rest_widget_types_php_sha256' => 'wp-includes/rest-api/endpoints/class-wp-rest-widget-types-controller.php',
    'rest_widgets_php_sha256' => 'wp-includes/rest-api/endpoints/class-wp-rest-widgets-controller.php',
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

$legacyBlockSchemaBytes = $sources['wp-includes/blocks/legacy-widget/block.json'];
try {
    $legacyBlockSchema = json_decode($legacyBlockSchemaBytes, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    tec_option_usage("WordPress $version legacy-widget block schema is malformed JSON");
}
$legacyAttributes = $legacyBlockSchema['attributes'] ?? null;
if (($legacyBlockSchema['name'] ?? null) !== 'core/legacy-widget'
    || !is_array($legacyAttributes)
    || array_keys($legacyAttributes) !== ['id', 'idBase', 'instance']
    || ($legacyAttributes['id'] ?? null) !== ['type' => 'string', 'default' => null]
    || ($legacyAttributes['idBase'] ?? null) !== ['type' => 'string', 'default' => null]
    || ($legacyAttributes['instance'] ?? null) !== ['type' => 'object', 'default' => null]) {
    tec_option_usage("WordPress $version legacy-widget attribute grammar drifted");
}
$legacyRender = tec_option_function_body(
    $sources['wp-includes/blocks/legacy-widget.php'],
    'render_block_core_legacy_widget'
);
foreach ([
    ['wp_find_widgets_sidebar( $attributes[\'id\'] )', 'stored widget sidebar lookup'],
    ['wp_render_widget( $attributes[\'id\'], $sidebar_id )', 'stored widget render'],
    ['$wp_widget_factory->get_widget_object( $id_base )', 'embedded widget type lookup'],
    ['base64_decode( $attributes[\'instance\'][\'encoded\'] )', 'embedded widget decode'],
    ['hash_equals( wp_hash( $serialized_instance )', 'environment-salted widget receipt'],
    ['unserialize( $serialized_instance )', 'embedded widget materialization'],
] as [$needle, $label]) {
    tec_option_require_call($legacyRender, $needle, "legacy-widget $label");
}
$legacyEncode = tec_option_function_body(
    $sources['wp-includes/rest-api/endpoints/class-wp-rest-widget-types-controller.php'],
    'encode_form_data'
);
$legacySave = tec_option_function_body(
    $sources['wp-includes/rest-api/endpoints/class-wp-rest-widgets-controller.php'],
    'save_widget'
);
foreach ([
    [$legacyEncode, 'base64_decode( $request[\'instance\'][\'encoded\'] )', 'REST preview decode'],
    [$legacyEncode, 'hash_equals( wp_hash( $serialized_instance )', 'REST preview source hash'],
    [$legacyEncode, '\'encoded\' => base64_encode( $serialized_instance )', 'REST preview encode'],
    [$legacyEncode, '\'hash\'    => wp_hash( $serialized_instance )', 'REST preview target hash'],
    [$legacySave, 'base64_decode( $request[\'instance\'][\'encoded\'] )', 'REST widget save decode'],
    [$legacySave, 'hash_equals( wp_hash( $serialized_instance )', 'REST widget save hash'],
    [$legacySave, '"widget-$id_base" => array(', 'widget_<idBase> settings write input'],
] as [$body, $needle, $label]) {
    tec_option_require_call($body, $needle, "legacy-widget $label");
}
$legacyBoundary = $fixture['legacy_widget_boundary'] ?? null;
if (!is_array($legacyBoundary)
    || ($legacyBoundary['block'] ?? null) !== 'core/legacy-widget'
    || ($legacyBoundary['attributes'] ?? null) !== ['id', 'idBase', 'instance']
    || ($legacyBoundary['stored_id_form']['storage'] ?? null) !== ['sidebars_widgets', 'widget_<idBase>']
    || ($legacyBoundary['embedded_form']['encoded'] ?? null) !== 'base64(PHP-serialized-instance)'
    || ($legacyBoundary['embedded_form']['hash'] ?? null) !== 'wp_hash(serialized-instance)'
    || ($legacyBoundary['embedded_form']['target_rebinding_required'] ?? null) !== true) {
    tec_option_usage('reviewed legacy-widget storage boundary is malformed');
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
    $widgetProvider = $tecSources['src/Tribe/Views/V2/Widgets/Service_Provider.php'] ?? '';
    $widgetRegisterCompatibility = (string) preg_replace(
        '/\s+/',
        '',
        tec_option_function_body($widgetProvider, 'register_compatibility')
    );
    $widgetRegister = (string) preg_replace(
        '/\s+/',
        '',
        tec_option_function_body($widgetProvider, 'register_widget')
    );
    foreach ([
        "add_filter('rest_pre_dispatch',[$" . "this,'enable_widget_copy_paste'],10,3);",
        "add_filter('rest_dispatch_request',[$" . "this,'enable_saving_widget_copied'],10,3);",
        "add_filter('render_block_data',[$" . "this,'enable_rendering_widget_copied']);",
    ] as $hookNeedle) {
        if (!str_contains($widgetRegisterCompatibility, $hookNeedle)) {
            tec_option_usage("TEC $tecVersion legacy-widget callback topology drifted");
        }
    }
    foreach ([
        '$widgets[Widget_List::get_widget_slug()]=Widget_List::class;',
        '$widgets[Widget_QR_Code::get_widget_slug()]=Widget_QR_Code::class;',
    ] as $registrationNeedle) {
        if (!str_contains($widgetRegister, $registrationNeedle)) {
            tec_option_usage("TEC $tecVersion legacy-widget registration topology drifted");
        }
    }
    $widgetCallbacks = [
        tec_option_function_body($widgetProvider, 'enable_widget_copy_paste'),
        tec_option_function_body($widgetProvider, 'enable_saving_widget_copied'),
        tec_option_function_body($widgetProvider, 'enable_rendering_widget_copied'),
    ];
    foreach ($widgetCallbacks as $callback) {
        if (!str_contains($callback, "'tribe-widget-'")
            || !str_contains($callback, 'base64_decode(')
            || !str_contains($callback, 'wp_hash( $serialized_instance )')) {
            tec_option_usage("TEC $tecVersion legacy-widget callback lost type/decode/re-sign behavior");
        }
    }
    $safeCalls = count(array_filter(
        $widgetCallbacks,
        static fn(string $callback): bool => str_contains($callback, 'is_safe_widget_instance')
    ));
    $hasSafeMethods = str_contains($widgetProvider, 'function is_safe_widget_instance')
        && str_contains($widgetProvider, '@unserialize( $' . "serialized, [ 'allowed_classes' => false ] )")
        && str_contains($widgetProvider, 'function contains_object')
        && str_contains($widgetProvider, 'if ( is_object( $data ) )')
        && str_contains($widgetProvider, '$this->contains_object( $value )');
    if (($tecVersion === '6.17.3' && ($safeCalls !== 3 || !$hasSafeMethods))
        || ($tecVersion === '6.17.2' && ($safeCalls !== 0 || $hasSafeMethods))) {
        tec_option_usage("TEC $tecVersion legacy-widget object-validation boundary drifted");
    }
    $widgetIdBases = [];
    foreach ([
        'src/Tribe/Views/V2/Widgets/Widget_List.php',
        'src/Tribe/Views/V2/Widgets/Widget_QR_Code.php',
    ] as $widgetSource) {
        if (preg_match(
            '/protected\s+static\s+\$widget_slug\s*=\s*\'([a-z0-9-]+)\'\s*;/D',
            $tecSources[$widgetSource] ?? '',
            $slugMatch
        ) !== 1) {
            tec_option_usage("TEC $tecVersion widget slug is not one bounded literal in $widgetSource");
        }
        $widgetIdBases[] = 'tribe-widget-' . $slugMatch[1];
    }
    if (($legacyBoundary['embedded_form']['id_bases'] ?? null) !== $widgetIdBases
        || ($legacyBoundary['callbacks'] ?? null) !== [
            'rest_pre_dispatch' => 'enable_widget_copy_paste',
            'rest_dispatch_request' => 'enable_saving_widget_copied',
            'render_block_data' => 'enable_rendering_widget_copied',
        ]) {
        tec_option_usage("TEC $tecVersion legacy-widget identity fixture drifted");
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
        [$constructorCompact, 'if(!$this->is_active()){return;}', 'Customizer activation refusal'],
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
        [$getCompact, '$sections=get_option($this->ID,$default);', 'native canonical Customizer read'],
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

    $sectionSpecs = [
        'events.views.v2.customizer.global-elements' => [
            'class' => 'Tribe\\Events\\Views\\V2\\Customizer\\Section\\Global_Elements',
            'short' => 'Global_Elements',
            'source' => 'src/Tribe/Views/V2/Customizer/Section/Global_Elements.php',
        ],
        'events.views.v2.customizer.month-view' => [
            'class' => 'Tribe\\Events\\Views\\V2\\Customizer\\Section\\Month_View',
            'short' => 'Month_View',
            'source' => 'src/Tribe/Views/V2/Customizer/Section/Month_View.php',
        ],
        'events.views.v2.customizer.events-bar' => [
            'class' => 'Tribe\\Events\\Views\\V2\\Customizer\\Section\\Events_Bar',
            'short' => 'Events_Bar',
            'source' => 'src/Tribe/Views/V2/Customizer/Section/Events_Bar.php',
        ],
        'events.views.v2.customizer.single-event' => [
            'class' => 'Tribe\\Events\\Views\\V2\\Customizer\\Section\\Single_Event',
            'short' => 'Single_Event',
            'source' => 'src/Tribe/Views/V2/Customizer/Section/Single_Event.php',
        ],
    ];
    $sectionProvider = $tecSources['src/Tribe/Views/V2/Customizer/Service_Provider.php'] ?? '';
    $sectionHooks = $tecSources['src/Tribe/Views/V2/Customizer/Hooks.php'] ?? '';
    $sectionBase = $tecSources['common/src/Tribe/Customizer/Section.php'] ?? '';
    $providerRegister = $compactPhp(tec_option_function_body($sectionProvider, 'register'));
    $hooksAddActions = $compactPhp(tec_option_function_body($sectionHooks, 'add_actions'));
    $hooksBoot = $compactPhp(tec_option_function_body($sectionHooks, 'boot'));
    if (!str_contains($hooksAddActions, "add_action('after_setup_theme',[\$this,'boot']);")) {
        tec_option_usage("TEC $tecVersion lost exact Customizer section boot action");
    }
    if (!str_contains(
        $providerRegister,
        "tribe_singleton('tec.customizer.global-elements',staticfunction(){returntribe("
            . "'events.views.v2.customizer.global-elements');});"
    )) {
        tec_option_usage("TEC $tecVersion lost exact legacy Global Elements service alias");
    }
    $settingFields = ['sanitize_callback', 'sanitize_js_callback', 'transport'];
    $derivedSections = [];
    $serverSettingsBySection = [];
    foreach ($sectionSpecs as $service => $spec) {
        $source = $tecSources[$spec['source']] ?? '';
        if (!str_contains($source, "final class {$spec['short']} extends \\Tribe__Customizer__Section")) {
            tec_option_usage("TEC $tecVersion Customizer section class drifted for $service");
        }
        if (preg_match('/public\s+\$ID\s*=\s*(\'[^\']*\')\s*;/D', $source, $idMatch) !== 1) {
            tec_option_usage("TEC $tecVersion Customizer section ID is not one literal for $service");
        }
        $sectionId = tec_option_literal_string($idMatch[1], "TEC $tecVersion Customizer section ID");
        $defaults = tec_option_literal_return_map(
            tec_option_function_body($source, 'setup_defaults'),
            "TEC $tecVersion $service defaults"
        );
        $settings = tec_option_literal_return_map(
            tec_option_function_body($source, 'setup_content_settings'),
            "TEC $tecVersion $service settings"
        );
        $defaultKeys = array_keys($defaults);
        $settingKeys = array_keys($settings);
        sort($defaultKeys, SORT_STRING);
        sort($settingKeys, SORT_STRING);
        if ($defaultKeys !== $settingKeys) {
            tec_option_usage("TEC $tecVersion Customizer defaults/settings keysets disagree for $service");
        }
        $settingTuples = [];
        foreach ($settings as $setting => $arguments) {
            if (!is_array($arguments)
                || array_keys($arguments) !== $settingFields
                || count(array_filter($arguments, 'is_string')) !== count($settingFields)) {
                tec_option_usage("TEC $tecVersion Customizer setting registry is malformed for $service");
            }
            $settingTuples[$setting] = array_values($arguments);
        }
        $registerNeedle = "tribe_singleton('$service',{$spec['short']}::class);";
        $bootNeedle = "tribe('$service');";
        if (!str_contains($providerRegister, $registerNeedle) || !str_contains($hooksBoot, $bootNeedle)) {
            tec_option_usage("TEC $tecVersion Customizer service/boot topology drifted for $service");
        }
        $derivedSections[$service] = [
            'class' => $spec['class'],
            'id' => $sectionId,
            'defaults' => $defaults,
            'settings' => $settingTuples,
        ];
        $serverSettingsBySection[$sectionId] = array_fill_keys(array_keys($settings), true);
    }

    $getSettingName = $compactPhp(tec_option_function_body($customizer, 'get_setting_name'));
    $addSetting = $compactPhp(tec_option_function_body($sectionBase, 'add_setting'));
    $hasOption = $compactPhp(tec_option_function_body($customizer, 'has_option'));
    foreach ([
        [$getSettingName, "\$name.='['.esc_attr(\$slug).']';", 'nested option setting name'],
        [$addSetting, "\$defaults=['default'=>\$this->get_default(\$key),'type'=>'option',];", 'option-backed setting type'],
        [$getCompact, '$sections[$section->ID]=wp_parse_args($settings,$defaults[$section->ID]);', 'read-time default merge'],
        [$hasOption, '$real_option=get_option($this->ID,[]);', 'raw sparse-storage observation'],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lost exact Customizer $label");
        }
    }

    $targetOwnedResidue = [];
    foreach ([
        $tecSources['build/js/customizer-views-v2-controls.js'] ?? '',
        $tecSources['build/js/customizer-views-v2-live-preview.js'] ?? '',
    ] as $javascript) {
        preg_match_all(
            '/tribe_customizer\[([A-Za-z0-9_-]+)\]\[([A-Za-z0-9_-]+)\]/D',
            $javascript,
            $settingMatches,
            PREG_SET_ORDER
        );
        foreach ($settingMatches as $settingMatch) {
            $sectionId = $settingMatch[1];
            $setting = $settingMatch[2];
            if (!isset($serverSettingsBySection[$sectionId])) {
                tec_option_usage("TEC $tecVersion JavaScript references an unknown Customizer section");
            }
            if (!isset($serverSettingsBySection[$sectionId][$setting])) {
                $targetOwnedResidue[$sectionId][$setting] = true;
            }
        }
    }
    ksort($targetOwnedResidue, SORT_STRING);
    foreach ($targetOwnedResidue as $sectionId => $settings) {
        $settings = array_keys($settings);
        sort($settings, SORT_STRING);
        $targetOwnedResidue[$sectionId] = $settings;
    }
    $customizerSectionsFixture = [
        'setting_tuple' => $settingFields,
        'storage' => [
            'canonical_option' => 'tribe_customizer',
            'defaults' => 'read-time-only',
            'empty_map' => 'valid',
            'setting_name_template' => 'tribe_customizer[%s][%s]',
            'setting_type' => 'option',
            'shape' => 'sparse-section-map',
        ],
        'sections' => $derivedSections,
        'target_owned_residue' => $targetOwnedResidue,
    ];
    if (($fixture['customizer_sections'] ?? null) !== $customizerSectionsFixture) {
        tec_option_usage('source-derived Customizer section registry disagrees with the reviewed fixture');
    }
    foreach ([
        'common/src/Tribe/Cache.php' =>
            "update_option( 'tribe_last_' . \$action, (float) \$timestamp )",
        'common/src/Tribe/Cache_Listener.php' =>
            '$this->cache = new Tribe__Cache()',
        'common/src/Tribe/Settings_Manager.php' =>
            'if ( Tribe__Main::OPTIONNAME !== $option ) { return; }',
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
    'legacy_widget_boundary' => $legacyBoundary,
    'customizer_fallback' => $fixture['customizer_fallback'] ?? null,
    'customizer_sections' => $fixture['customizer_sections'] ?? null,
    'tec_version' => $tecVersion === '' ? null : $tecVersion,
    'tec_service_sources' => $verifiedTecSources,
    'proved_absent' => $fixture['proved_absent'] ?? null,
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
