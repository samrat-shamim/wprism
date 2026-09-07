<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseTablePresence.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Kernel/TableGraph.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Repository/Snapshot.php';

/** Same-snapshot reference projection without identity reconciliation authority. */
final class LifecycleReferenceView {
    /** @var array<string,array{string,string}> */
    private array $physicalKeys = [];
    /** @var array<string,array<int,true>> */
    private array $preserved = [];
    /** @var array<string,bool> */
    private array $presence = [];
    private ?DatabaseWorkAuthority $authority = null;

    public function __construct(private readonly Policy $policy, private readonly ?array $previousOptions) {
        $kinds = array_fill_keys(array_map(
            static fn(array $rule): string => (string) ($rule['id_kind'] ?? ''),
            $policy->option_name_ref_rules()
        ), true);
        if ($kinds === []) {
            return;
        }
        global $wpdb;
        foreach (Snapshot::row_tables($policy) as $table => $declaration) {
            $kind = (string) ($declaration['id_kind'] ?? '');
            if (isset($kinds[$kind]) && !TableGraph::is_composite_ref($declaration)) {
                $this->physicalKeys[$kind] = [$wpdb->prefix . $table, (string) $declaration['pk']];
            }
        }
    }

    /** @param array<array-key,string> $optionValues The producer's bounded, same-snapshot option namespace. */
    public function prepare(array $optionValues, ?DatabaseWorkAuthority $authority): void {
        if ($authority === null || $authority === $this->authority) {
            throw new \LogicException('wprism: lifecycle references require a fresh bound observation');
        }
        $this->authority = null;
        $this->preserved = [];
        $this->presence = [];
        DatabaseQueryIsolation::work_unit($authority, static function (): void {});
        // Canonical numeric option names become PHP integer map keys;
        // their original decimal spelling still belongs to the namespace.
        $names = array_map('strval', array_keys($optionValues));
        $preserved = Snapshot::option_name_ref_preserved_ids(
            $this->policy,
            $this->previousOptions,
            $names,
            static function (\Closure $observe) use ($authority): void {
                // The namespace is a roster, not one database work item.
                // Each canonical name owns its complete identity witness;
                // enclosing native callback budgets still cannot be reset.
                DatabaseQueryIsolation::work_unit($authority, $observe);
            }
        );
        foreach ($preserved as $kind => $ids) {
            $this->preserved[$kind] = array_fill_keys($ids, true);
        }
        $this->authority = $authority;
    }

    public function uuidFor(int $localId, string $kind): ?string {
        if ($this->authority === null) {
            throw new \LogicException('wprism: lifecycle references have no prepared observation');
        }
        return DatabaseQueryIsolation::work_unit($this->authority, function () use ($localId, $kind): ?string {
            $physical = $this->physicalKeys[$kind] ?? null;
            if ($physical === null) {
                return Ledger::capture_reference_uuid_for($localId, $kind);
            }
            if (isset($this->preserved[$kind][$localId])) {
                // A live/canonical option name may outlive its owner row; it
                // must retain identity until the full guard/deletion planner
                // can reconcile both. This does not authorize a new binding.
                return Ledger::uuid_for($localId, $kind);
            }
            $table = $physical[0];
            if (!array_key_exists($table, $this->presence)) {
                $this->presence[$table] = DatabaseTablePresence::base_table_exists($table);
            }
            if (!$this->presence[$table]) {
                // Activation may create an absent table after this handoff.
                // Exact presence metadata never grants reads of that table's
                // rows; a table appearing outside the bound profile refuses.
                return Ledger::uuid_for($localId, $kind);
            }
            return Ledger::capture_reference_uuid_for($localId, $kind, $physical);
        });
    }
}
