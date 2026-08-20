<?php
/**
 * Offline regression for the child-theme parent mismatch guard. The
 * stylesheet may already be right while template is wrong; that state makes
 * theme-scoped content inert, so it must enter code_mismatch for lifecycle
 * reconciliation instead of disappearing from plan/apply truthfulness.
 */

namespace {
    final class DuoFakeTheme {
        public function __construct(private string $slug) {}
        public function exists(): bool { return in_array($this->slug, ['child', 'correct-parent'], true); }
        public function get(string $field): string { return $field === 'Version' ? '1.0.0' : ''; }
    }

    $duoTemplateFixtureOptions = [
        'stylesheet' => 'child',
        'template' => 'wrong-parent',
        'active_plugins' => [],
    ];

    function validate_plugin(string $plugin) { return null; }
    function get_plugins(): array { return []; }
    function is_wp_error($value): bool { return false; }
    function get_option(string $name) {
        global $duoTemplateFixtureOptions;
        return $duoTemplateFixtureOptions[$name] ?? null;
    }
    function wp_get_theme(string $slug): DuoFakeTheme { return new DuoFakeTheme($slug); }
}

namespace Duo {
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
