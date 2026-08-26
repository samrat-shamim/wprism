<?php
declare(strict_types=1);

/**
 * Child-process fixture for Polylang's complete-uninstall widget residue.
 *
 * The adapter suite defines a deliberately tiny Policy for its provider and
 * interpreter. This process instead drives the real SidebarState SQL readers
 * against the shared row-backed FakeWpdb and reports the recovery boundary.
 */

namespace {
    $root = dirname(__DIR__, 3);
    require_once $root . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
}

namespace Duo {
    final class Policy {
        public function widget_types(): array {
            return [
                'polylang' => [
                    'settings' => [
                        'title' => ['class' => 'authored'],
                    ],
                ],
            ];
        }

        public static function assert_widget_grammar(string $type, array $rule): void {
            if ($type !== 'polylang' || !isset($rule['settings']['title'])) {
                throw new \RuntimeException('fixture widget declaration moved');
            }
        }

        public function option_rule(string $name): ?array {
            return null;
        }
    }

    final class Ledger {
        public const ID_KIND_WIDTH = 64;

        public static function uuid_for(int $local, string $kind): ?string {
            return null;
        }
    }

    final class Snapshot {
        public static function row_tables(Policy $policy): array {
            return [];
        }
    }

    final class Tokens {
        public function tokenize_text(string $text): string {
            return $text;
        }
    }

    final class Canon {
        public static function encode($value): string {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($encoded)) {
                throw new \RuntimeException('fixture canonical encoding failed');
            }
            return $encoded;
        }
    }

}

namespace {
    $root = dirname(__DIR__, 3);
    require_once $root . '/agent/src/Kernel/Uuid.php';
    require_once $root . '/agent/src/Repository/SidebarState.php';
    require_once $root . '/agent/src/Repository/Identity.php';

    $wpdb = \DuoTest\FakeWpdb::install();
    $wpdb->seedTable('options', [
        [
            'option_id' => 1,
            'option_name' => 'sidebars_widgets',
            'option_value' => serialize([
                'sidebar-1' => ['polylang-1', 'polylang-2'],
                'array_version' => 3,
            ]),
            'autoload' => 'yes',
        ],
    ]);

    $policy = new \Duo\Policy();
    $tokens = new \Duo\Tokens();
    $canonicalTree = [
        'sidebar/sidebar-1' => [
            'type' => \Duo\SidebarState::ENTITY_TYPE,
            'path' => 'sidebars/sidebar-1.json',
            'data' => [
                'widgets' => [[
                    'uuid' => '00000000-0000-4000-8000-000000000001',
                    'type' => 'polylang',
                    'settings' => ['title' => 'Portable language switcher'],
                ]],
            ],
        ],
    ];

    $recovered = \Duo\SidebarState::capture($policy, $tokens, false, false, false, null, $canonicalTree);
    $content = json_decode((string) ($recovered['entities'][0]['content'] ?? ''), true);

    $captureRefusal = '';
    try {
        \Duo\SidebarState::capture($policy, $tokens, false);
    } catch (\Throwable $failure) {
        $captureRefusal = $failure->getMessage();
    }

    $unownedRefusal = '';
    try {
        $wrongTree = $canonicalTree;
        $wrongTree['sidebar/sidebar-1']['path'] = 'sidebars/different.json';
        \Duo\SidebarState::capture($policy, $tokens, false, false, false, null, $wrongTree);
    } catch (\Throwable $failure) {
        $unownedRefusal = $failure->getMessage();
    }

    $identityUuid = '00000000-0000-4000-8000-000000000099';
    $wpdb->seedTable('posts', []);
    $wpdb->seedTable('postmeta', []);
    $wpdb->seedTable('terms', [
        ['term_id' => 19, 'name' => 'Replacement language', 'slug' => 'fr'],
    ]);
    $wpdb->seedTable('termmeta', [
        ['meta_id' => 1, 'term_id' => 5, 'meta_key' => '_duo_uuid', 'meta_value' => $identityUuid],
        ['meta_id' => 2, 'term_id' => 19, 'meta_key' => '_duo_uuid', 'meta_value' => $identityUuid],
    ]);
    $orphanIgnored = true;
    try {
        \Duo\Identity::assert_embedded_unique();
    } catch (\Throwable) {
        $orphanIgnored = false;
    }

    $wpdb->seedTable('terms', [
        ['term_id' => 19, 'name' => 'Replacement language', 'slug' => 'fr'],
        ['term_id' => 20, 'name' => 'Copied live language', 'slug' => 'fr-copy'],
    ]);
    $wpdb->seedTable('termmeta', [
        ['meta_id' => 1, 'term_id' => 5, 'meta_key' => '_duo_uuid', 'meta_value' => $identityUuid],
        ['meta_id' => 2, 'term_id' => 19, 'meta_key' => '_duo_uuid', 'meta_value' => $identityUuid],
        ['meta_id' => 3, 'term_id' => 20, 'meta_key' => '_duo_uuid', 'meta_value' => $identityUuid],
    ]);
    $liveDuplicateRefusal = '';
    try {
        \Duo\Identity::assert_embedded_unique();
    } catch (\Throwable $failure) {
        $liveDuplicateRefusal = $failure->getMessage();
    }

    echo json_encode([
        'capture_refusal' => $captureRefusal,
        'live_duplicate_refusal' => $liveDuplicateRefusal,
        'orphan_identity_ignored' => $orphanIgnored,
        'recovered_widgets' => $content['widgets'] ?? null,
        'recovery_warnings' => $recovered['warnings'] ?? null,
        'unowned_refusal' => $unownedRefusal,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
