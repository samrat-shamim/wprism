<?php
namespace Duo\Orchestrator;

/**
 * Turns the JSON from `wp duo plan --format=json` (agent/src/Apply.php
 * build_plan(): keys create/update/unchanged/drift/conflict/adopt/
 * collision/delete, each a list of {uuid,type,path?,blocked?,env_id?}) into
 * `duo status`'s human summary.
 */
final class PlanSummary {
    private const BUCKETS = ['create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision', 'delete'];

    /** @return array{lines: list<string>, ok: bool} */
    public static function render(array $plan): array {
        $lines = [];
        $counts = [];
        foreach (self::BUCKETS as $k) {
            $counts[$k] = count($plan[$k] ?? []);
        }
        $lines[] = 'plan: ' . implode(', ', array_map(fn($k) => "{$counts[$k]} $k", self::BUCKETS));

        if (!empty($plan['drift'])) {
            $lines[] = 'drift (environment changed since last capture/apply — capture first):';
            foreach ($plan['drift'] as $r) {
                $lines[] = '  - ' . self::label($r);
            }
        }

        $blocked = array_values(array_filter($plan['delete'] ?? [], fn($r) => isset($r['blocked'])));
        if ($blocked) {
            $lines[] = 'blocked deletes (referential guard):';
            foreach ($blocked as $r) {
                $lines[] = '  - ' . self::label($r) . ': ' . $r['blocked'];
            }
        }

        if (!empty($plan['conflict'])) {
            $lines[] = 'CONFLICT (repo and environment both changed since last sync):';
            foreach ($plan['conflict'] as $r) {
                $lines[] = '  - ' . self::label($r);
            }
        }

        if (!empty($plan['collision'])) {
            $lines[] = 'COLLISION (unmanaged env entity already has this slug — rerun with --adopt-by-slug or rename):';
            foreach ($plan['collision'] as $r) {
                $lines[] = '  - ' . self::label($r) . " (env id {$r['env_id']})";
            }
        }

        $ok = $counts['conflict'] === 0 && $counts['collision'] === 0;
        return ['lines' => $lines, 'ok' => $ok];
    }

    private static function label(array $r): string {
        return $r['path'] ?? (($r['type'] ?? '?') . ' ' . ($r['uuid'] ?? '?'));
    }
}
