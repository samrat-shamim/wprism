<?php
declare(strict_types=1);

namespace Duo\Capability;

require_once dirname(__DIR__) . '/Canon.php';

/**
 * Compatibility verdict for the historical capability serializer.
 *
 * This value intentionally does not expose a generic `ready` flag. A caller
 * must ask whether the legacy capability verdict is certified, and the
 * projection retains blockers/reasons as the evidence-bearing explanation.
 */
final class LegacyCapabilityVerdict {
    private const STATUSES = [
        'blocked', 'candidate', 'certified', 'excluded', 'experimental', 'unreviewed', 'unsupported',
    ];

    /** @param list<array<string,mixed>> $reasons */
    private function __construct(
        private readonly string $status,
        private readonly array $reasons
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self {
        $verdict = is_array($row['verdict'] ?? null) ? $row['verdict'] : [];
        $status = is_string($verdict['status'] ?? null)
            ? $verdict['status']
            : (is_string($row['status'] ?? null) ? $row['status'] : 'unsupported');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'unsupported';
        }
        $reasons = [];
        if (is_array($verdict['reasons'] ?? null) && array_is_list($verdict['reasons'])) {
            foreach ($verdict['reasons'] as $reason) {
                if (is_array($reason) && !array_is_list($reason)) {
                    $reasons[] = $reason;
                }
            }
        }
        return new self($status, $reasons);
    }

    public function status(): string {
        return $this->status;
    }

    /** @return list<array<string,mixed>> */
    public function reasons(): array {
        return $this->reasons;
    }

    public function isLegacyCertified(): bool {
        return $this->status === 'certified';
    }

    /** @return array{status:string,reasons:list<array<string,mixed>>} */
    public function data(): array {
        return ['status' => $this->status, 'reasons' => $this->reasons];
    }
}
