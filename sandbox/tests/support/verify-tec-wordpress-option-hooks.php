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
    [$legacyEncode, '$serialized_instance = serialize( $instance )', 'REST canonical instance serialization'],
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
    || ($legacyBoundary['embedded_form']['target_rebinding_required'] ?? null) !== true
    || ($legacyBoundary['embedded_form']['codec'] ?? null) !== 'the-events-calendar/v1'
    || ($legacyBoundary['embedded_form']['limits'] ?? null) !== [
        'serialized_bytes' => 16384,
        'encoded_bytes' => 21848,
        'string_bytes' => 4096,
        'depth' => 6,
        'nodes' => 64,
    ]
    || ($legacyBoundary['duo_status']['portable'] ?? null) !== true) {
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
    $compactPhp = static fn(string $source): string => (string) preg_replace('/\s+/', '', $source);

    $bootstrap = $compactPhp($tecSources['the-events-calendar.php'] ?? '');
    $main = $tecSources['src/Tribe/Main.php'] ?? '';
    $mainActivate = $compactPhp(tec_option_function_body($main, 'activate'));
    $mainDeactivate = $compactPhp(tec_option_function_body($main, 'deactivate'));
    $mainClearCt1 = $compactPhp(tec_option_function_body($main, 'clear_ct1_activation_state'));
    foreach ([
        [
            $bootstrap,
            "register_activation_hook(TRIBE_EVENTS_FILE,['Tribe__Events__Main','activate']);",
            'activation hook',
        ],
        [
            $bootstrap,
            "register_deactivation_hook(TRIBE_EVENTS_FILE,['Tribe__Events__Main','deactivate']);",
            'deactivation hook',
        ],
        [
            $mainActivate,
            "set_transient('_tribe_events_delayed_flush_rewrite_rules','yes',0);",
            'delayed rewrite activation transient',
        ],
        [
            $mainActivate,
            "set_transient('_tribe_events_activation_redirect',1,30);",
            'single-site activation redirect transient',
        ],
        [$mainActivate, 'self::clear_ct1_activation_state();', 'activation CT1 reset'],
        [$mainDeactivate, 'self::clear_ct1_activation_state();', 'deactivation CT1 reset'],
        [
            $mainDeactivate,
            "\$hook_name='tribe_schedule_transient_purge';",
            'deactivation transient-purge cron identity',
        ],
        [$mainDeactivate, 'wp_clear_scheduled_hook($hook_name);', 'deactivation transient-purge clear'],
        [
            $mainDeactivate,
            "add_action('shutdown',[\$deactivation,'deactivate']);",
            'shutdown deactivation dispatch',
        ],
        [
            $mainClearCt1,
            "\$transient_key='tec_custom_tables_v1_initialized';",
            'legacy CT1 transient identity',
        ],
        [$mainClearCt1, 'delete_transient($transient_key);', 'legacy CT1 transient deletion'],
        [$mainClearCt1, 'wp_cache_delete($transient_key);', 'legacy CT1 cache deletion'],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lifecycle source lost exact $label");
        }
    }

    $abstractDeactivation = $tecSources['common/src/Tribe/Abstract_Deactivation.php'] ?? '';
    $abstractDispatch = $compactPhp(tec_option_function_body($abstractDeactivation, 'deactivate'));
    $abstractRewrite = $compactPhp(tec_option_function_body($abstractDeactivation, 'flush_rewrite_rules'));
    $deactivation = $tecSources['src/Tribe/Deactivation.php'] ?? '';
    $setFlags = $compactPhp(tec_option_function_body($deactivation, 'set_flags'));
    $clearCapabilities = $compactPhp(tec_option_function_body($deactivation, 'clear_capabilities'));
    $blogDeactivate = $compactPhp(tec_option_function_body($deactivation, 'blog_deactivate'));
    foreach ([
        [$abstractDispatch, '$this->blog_deactivate();', 'single-blog deactivation dispatch'],
        [$abstractRewrite, "delete_option('rewrite_rules');", 'rewrite runtime deletion'],
        [$setFlags, '$updater->reset();', 'schema-version reset dispatch'],
        [$clearCapabilities, '$capabilities->remove_all_caps();', 'TEC capability removal'],
        [$blogDeactivate, '$this->set_flags();', 'single-blog update reset'],
        [$blogDeactivate, '$this->clear_capabilities();', 'single-blog capability cleanup'],
        [$blogDeactivate, '$this->flush_rewrite_rules();', 'single-blog rewrite cleanup'],
        [$blogDeactivate, "do_action('tribe_events_blog_deactivate');", 'single-blog runtime cleanup action'],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lifecycle source lost exact $label");
        }
    }

    $updater = $tecSources['src/Tribe/Updater.php'] ?? '';
    $updaterReset = $compactPhp(tec_option_function_body($updater, 'reset'));
    if (!str_contains($compactPhp($updater), "protected\$version_option='schema-version';")
        || !str_contains($compactPhp($updater), "protected\$reset_version='5.16.0';")
        || !str_contains($updaterReset, '$this->update_version_option($this->reset_version);')) {
        tec_option_usage("TEC $tecVersion lifecycle schema-version reset drifted");
    }

    $capabilities = $tecSources['src/Tribe/Capabilities.php'] ?? '';
    $capabilitiesCompact = $compactPhp($capabilities);
    $removeAllCaps = $compactPhp(tec_option_function_body($capabilities, 'remove_all_caps'));
    foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $role) {
        if (!str_contains($capabilitiesCompact, "'$role'")) {
            tec_option_usage("TEC $tecVersion lifecycle role registry lost $role");
        }
    }
    foreach ([
        'Tribe__Events__Main::POSTTYPE',
        'Tribe__Events__Main::VENUE_POST_TYPE',
        'Tribe__Events__Main::ORGANIZER_POST_TYPE',
        'Tribe__Events__Aggregator__Records::$post_type',
    ] as $postTypeReference) {
        if (!str_contains($removeAllCaps, "\$this->remove_post_type_caps($postTypeReference,\$role);")) {
            tec_option_usage("TEC $tecVersion lifecycle capability registry lost $postTypeReference");
        }
    }
    if (!str_contains(
        $compactPhp($tecSources['src/Tribe/Aggregator/Records.php'] ?? ''),
        "publicstatic\$post_type='tribe-ea-record';"
    )) {
        tec_option_usage("TEC $tecVersion lifecycle Aggregator post type drifted");
    }

    $cleaner = $tecSources['src/Tribe/Event_Cleaner_Scheduler.php'] ?? '';
    $cleanerHooks = $compactPhp(tec_option_function_body($cleaner, 'add_hooks'));
    $cleanerTrash = $compactPhp(tec_option_function_body($cleaner, 'trash_clear_scheduled_task'));
    $cleanerDelete = $compactPhp(tec_option_function_body($cleaner, 'delete_clear_scheduled_task'));
    foreach ([
        [$compactPhp($cleaner), "publicstatic\$del_cron_hook='tribe_del_event_cron';", 'delete cron identity'],
        [$compactPhp($cleaner), "publicstatic\$trash_cron_hook='tribe_trash_event_cron';", 'trash cron identity'],
        [
            $cleanerHooks,
            "add_action('tribe_events_blog_deactivate',[\$this,'trash_clear_scheduled_task']);",
            'trash-cron deactivation callback',
        ],
        [
            $cleanerHooks,
            "add_action('tribe_events_blog_deactivate',[\$this,'delete_clear_scheduled_task']);",
            'delete-cron deactivation callback',
        ],
        [$cleanerTrash, 'wp_clear_scheduled_hook(self::$trash_cron_hook);', 'trash cron clear'],
        [$cleanerDelete, 'wp_clear_scheduled_hook(self::$del_cron_hook);', 'delete cron clear'],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lifecycle source lost exact $label");
        }
    }

    $queue = $tecSources['src/Tribe/Aggregator/Record/Queue_Processor.php'] ?? '';
    $queueCompact = $compactPhp($queue);
    $queueManage = $compactPhp(tec_option_function_body($queue, 'manage_scheduled_task'));
    $queueClear = $compactPhp(tec_option_function_body($queue, 'clear_scheduled_task'));
    foreach ([
        [$queueCompact, "publicstatic\$scheduled_key='tribe_aggregator_process_insert_records';", 'Aggregator cron identity'],
        [
            $queueCompact,
            "publicstatic\$scheduled_single_key='tribe_aggregator_single_process_insert_records';",
            'Aggregator one-shot cron identity',
        ],
        [
            $queueManage,
            "add_action('tribe_events_blog_deactivate',[\$this,'clear_scheduled_task']);",
            'Aggregator deactivation callback',
        ],
        [$queueClear, 'wp_clear_scheduled_hook(self::$scheduled_key);', 'Aggregator recurring cron clear'],
    ] as [$body, $needle, $label]) {
        if (!str_contains($body, $needle)) {
            tec_option_usage("TEC $tecVersion lifecycle source lost exact $label");
        }
    }
    if (str_contains($queueClear, 'self::$scheduled_single_key')) {
        tec_option_usage("TEC $tecVersion unexpectedly clears the reviewed Aggregator one-shot residue");
    }

    $customTableActivation = $tecSources['src/Events/Custom_Tables/V1/Activation.php'] ?? '';
    $customTableDeactivate = $compactPhp(tec_option_function_body($customTableActivation, 'deactivate'));
    $customTableProvider = $compactPhp($tecSources['src/Events/Custom_Tables/V1/Provider.php'] ?? '');
    if (!str_contains($customTableDeactivate, '$services->make(Schema_Builder::class)->clean();')
        || !str_contains($customTableProvider, "add_action('init',[Activation::class,'init']);")
        || str_contains($customTableProvider, "[Activation::class,'deactivate']")) {
        tec_option_usage("TEC $tecVersion Custom Tables lifecycle registration drifted");
    }

    $uninstall = $tecSources['uninstall.php'] ?? '';
    $expectedUninstall = "<?php\n\nif ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {\n\tdie;\n}\n\n";
    if (!hash_equals(hash('sha256', $expectedUninstall), hash('sha256', $uninstall))
        || strlen($uninstall) !== 60) {
        tec_option_usage("TEC $tecVersion uninstall surface is not the exact guard-only file");
    }

    $lifecycleFixture = [
        'activation_hook' => 'Tribe__Events__Main::activate',
        'deactivation_hook' => 'Tribe__Events__Main::deactivate',
        'activation' => [
            'single_site_transients' => [
                '_tribe_events_delayed_flush_rewrite_rules' => ['yes', 0],
                '_tribe_events_activation_redirect' => [1, 30],
            ],
            'clears_legacy_ct1_transient' => 'tec_custom_tables_v1_initialized',
        ],
        'deactivation' => [
            'dispatch' => 'shutdown',
            'schema_version_reset' => '5.16.0',
            'clears_legacy_ct1_transient' => 'tec_custom_tables_v1_initialized',
            'clears_cron_hooks' => [
                'tribe_schedule_transient_purge',
                'tribe_trash_event_cron',
                'tribe_del_event_cron',
                'tribe_aggregator_process_insert_records',
            ],
            'retains_cron_hooks' => ['tribe_aggregator_single_process_insert_records'],
            'deletes_options' => ['rewrite_rules'],
            'removes_post_type_capabilities' => [
                'tribe_events',
                'tribe_venue',
                'tribe_organizer',
                'tribe-ea-record',
            ],
            'roles' => ['administrator', 'editor', 'author', 'contributor', 'subscriber'],
            'action' => 'tribe_events_blog_deactivate',
            'custom_table_clean_registered' => false,
        ],
        'uninstall' => [
            'bytes' => 60,
            'sha256' => '767dc6e504b10dc655a44396e7e91c9726379edd302621eacd439c446e5e183d',
            'behavior' => 'WP_UNINSTALL_PLUGIN guard only; no state mutation',
        ],
        'persistent_state' => [
            'authored event/venue/organizer/category graph',
            'tec_events and tec_occurrences derived rows',
            'tribe_customizer and tribe_events_pro_customizer',
            'tribe_events_calendar_options except env schema-version transition',
            'tec_events_category_color_css',
        ],
    ];
    if (($fixture['lifecycle_boundary'] ?? null) !== $lifecycleFixture) {
        tec_option_usage('source-derived TEC lifecycle boundary disagrees with the reviewed fixture');
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
    $listWidget = $tecSources['src/Tribe/Views/V2/Widgets/Widget_List.php'] ?? '';
    $qrWidget = $tecSources['src/Tribe/Views/V2/Widgets/Widget_QR_Code.php'] ?? '';
    $commonWidget = $tecSources['common/src/Tribe/Widget/Widget_Abstract.php'] ?? '';
    $compact = static fn(string $source): string => (string) preg_replace('/\s+/', '', $source);
    $listUpdate = $compact(tec_option_function_body($listWidget, 'update'));
    $listFields = $compact(tec_option_function_body($listWidget, 'setup_admin_fields'));
    foreach ([
        "\$updated_instance['title']=wp_strip_all_tags(\$new_instance['title']);",
        "\$updated_instance['limit']=\$new_instance['limit'];",
        "\$updated_instance['no_upcoming_events']=!empty(\$new_instance['no_upcoming_events']);",
        "\$updated_instance['featured_events_only']=!empty(\$new_instance['featured_events_only']);",
        "\$updated_instance['jsonld_enable']=!empty(\$new_instance['jsonld_enable']);",
        "\$updated_instance['tribe_is_list_widget']=!empty(\$new_instance['tribe_is_list_widget']);",
    ] as $needle) {
        if (!str_contains($listUpdate, $needle)) {
            tec_option_usage("TEC $tecVersion list-widget storage grammar drifted");
        }
    }
    foreach (["'min'=>1", "'max'=>10", "'step'=>1"] as $needle) {
        if (!str_contains($listFields, $needle)) {
            tec_option_usage("TEC $tecVersion list-widget limit frontier drifted");
        }
    }
    $qrUpdate = $compact(tec_option_function_body($qrWidget, 'update'));
    $qrFields = $compact(tec_option_function_body($qrWidget, 'setup_admin_fields'));
    foreach ([
        "\$updated_instance['widget_title']=wp_strip_all_tags(\$new_instance['widget_title']);",
        "\$updated_instance['qr_code_size']=sanitize_text_field(\$new_instance['qr_code_size']);",
        "\$updated_instance['redirection']=sanitize_text_field(\$new_instance['redirection']);",
        "\$updated_instance['event_id']=absint(\$new_instance['event_id']??0);",
        "\$updated_instance['series_id']=absint(\$new_instance['series_id']??0);",
    ] as $needle) {
        if (!str_contains($qrUpdate, $needle)) {
            tec_option_usage("TEC $tecVersion QR-widget storage grammar drifted");
        }
    }
    foreach (['4', '8', '12', '16', '20', '24', '28', 'current', 'upcoming', 'specific'] as $value) {
        if (!str_contains($qrFields, "'value'=>'$value'")) {
            tec_option_usage("TEC $tecVersion QR-widget native menu drifted");
        }
    }
    $filterUpdated = $compact(tec_option_function_body($commonWidget, 'filter_updated_instance'));
    foreach ([
        "apply_filters('tribe_widget_updated_instance',\$updated_instance,\$new_instance,\$this)",
        'apply_filters("tribe_widget_{$widget_slug}_updated_instance",$updated_instance,$new_instance,$this)',
        "apply_filters('tec_events_qr_widget_options',\$options)",
        "apply_filters('tec_events_qr_widget_fields',\$fields)",
    ] as $needle) {
        $haystack = str_starts_with($needle, "apply_filters('tec_events_qr_") ? $qrFields : $filterUpdated;
        if (!str_contains($haystack, $needle)) {
            tec_option_usage("TEC $tecVersion widget extension-refusal topology drifted");
        }
    }
    if (($legacyBoundary['widget_types'] ?? null) !== [
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
    ]) {
        tec_option_usage("TEC $tecVersion reviewed legacy-widget schema fixture drifted");
    }
    $customizer = $tecSources['common/src/Tribe/Customizer.php'] ?? '';
    $customizerConstructor = tec_option_function_body($customizer, '__construct');
    $customizerFallback = tec_option_function_body($customizer, 'maybe_fallback_get_option');
    $customizerGet = tec_option_function_body($customizer, 'get_option');
    $customizerActive = tec_option_function_body($customizer, 'is_active');
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
    'lifecycle_boundary' => $fixture['lifecycle_boundary'] ?? null,
    'customizer_fallback' => $fixture['customizer_fallback'] ?? null,
    'customizer_sections' => $fixture['customizer_sections'] ?? null,
    'tec_version' => $tecVersion === '' ? null : $tecVersion,
    'tec_service_sources' => $verifiedTecSources,
    'proved_absent' => $fixture['proved_absent'] ?? null,
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
