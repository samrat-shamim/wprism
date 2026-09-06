<?php
/**
 * Offline regression for the child-theme parent mismatch guard. The
 * stylesheet may already be right while template is wrong; that state makes
 * theme-scoped content inert, so it must enter code_mismatch for lifecycle
 * reconciliation instead of disappearing from plan/apply truthfulness.
 */

namespace {
    $root = dirname(__DIR__, 4);
    $target = sys_get_temp_dir() . '/wprism-template-mismatch-' . bin2hex(random_bytes(6));
    define('ABSPATH', $target . '/');
    define('WP_CONTENT_DIR', $target . '/wp-content');
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
    require_once "$root/sandbox/tests/lib/wp_stubs.php";
    require_once "$root/sandbox/tests/lib/FakeWpdb.php";

    foreach (['child', 'wrong-parent', 'correct-parent'] as $theme) {
        mkdir(WP_CONTENT_DIR . "/themes/$theme", 0777, true);
        file_put_contents(
            WP_CONTENT_DIR . "/themes/$theme/style.css",
            "/*\nTheme Name: $theme\nVersion: 1.0.0\n*/\n"
        );
    }
    $GLOBALS['wp_theme_directories'] = [];
    $GLOBALS['wpdb'] = \WPrismTest\FakeWpdb::install()
        ->seedTable('wp_options', [
            ['option_name' => 'active_plugins', 'option_value' => 'a:0:{}', 'autoload' => 'yes'],
            ['option_name' => 'stylesheet', 'option_value' => 'child', 'autoload' => 'yes'],
            ['option_name' => 'template', 'option_value' => 'wrong-parent', 'autoload' => 'yes'],
        ])
        ->setColumns('wp_options', [
            'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)',
        ]);
    register_shutdown_function(static function () use ($target): void {
        if (!is_dir($target)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($target);
    });
}

namespace WPrism {
    final class Policy {
        public function version_ranges(): array { return []; }
        public function theme_ranges(): array { return []; }
    }

    require_once __DIR__ . '/../../../../agent/src/Promotion/Deploy.php';

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        if (!$ok) {
            $failures[] = $message;
        }
    };

    $rows = Deploy::code_mismatch(new Policy(), [
        'stylesheet' => 'child',
        'template' => 'correct-parent',
    ]);
    $templateRows = array_values(array_filter(
        $rows,
        static fn(array $row): bool => ($row['issue'] ?? null) === 'template_mismatch'
    ));

    $check(count($templateRows) === 1, 'matching stylesheet with mismatched template must produce template_mismatch');
    $row = $templateRows[0] ?? [];
    $check(($row['theme'] ?? null) === 'child', 'template mismatch must name the active stylesheet');
    $check(($row['template'] ?? null) === 'correct-parent', 'template mismatch must name the canonical parent');
    $check(($row['environment_template'] ?? null) === 'wrong-parent', 'template mismatch must preserve the live parent');
    $check(str_contains((string) ($row['message'] ?? ''), 'switch_theme()'), 'template mismatch must direct lifecycle reconciliation');

    if ($failures) {
        fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
        exit(1);
    }

    echo "ok: matching stylesheet with wrong template is a visible lifecycle mismatch\n";
}
