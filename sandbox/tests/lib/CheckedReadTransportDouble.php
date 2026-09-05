<?php
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/FakeWpdb.php';

/**
 * Canonical query/session transport for existing semantic wpdb doubles.
 *
 * These fixtures own specialized Woo row models, not alternative database
 * transports. Reuse FakeWpdb's session/strict-mode model and installed hook
 * dispatch; each SQL entry must call checkedReadQuery() before its row model.
 * This does not turn an outer row model's errors into mysqli exceptions;
 * strict driver-error behavior is exercised by the kernel transport suites.
 */
trait CheckedReadTransportDouble {
    private ?FakeWpdb $checkedReadTransport = null;

    private function checkedReadTransport(): FakeWpdb {
        return $this->checkedReadTransport ??= new FakeWpdb();
    }

    private function checkedReadQuery(string $sql): string {
        return $this->remove_placeholder_escape(FakeWpdb::filterEngineQuery($this, $sql));
    }

    public function remove_placeholder_escape(string $sql): string {
        return $this->checkedReadTransport()->remove_placeholder_escape($sql);
    }

    public function wprism_test_set_strict_transport(bool $enabled): bool {
        return $this->checkedReadTransport()->wprism_test_set_strict_transport($enabled);
    }

    public function wprism_test_strict_transport(): bool {
        return $this->checkedReadTransport()->wprism_test_strict_transport();
    }

    public function wprism_test_database_session_state(): array {
        return $this->checkedReadTransport()->wprism_test_database_session_state();
    }

    public function wprism_test_restore_database_session_state(array $state): void {
        $this->checkedReadTransport()->wprism_test_restore_database_session_state($state);
    }
}
