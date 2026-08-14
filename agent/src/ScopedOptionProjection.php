<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/OptionState.php';
require_once __DIR__ . '/ScopeClosure.php';
require_once __DIR__ . '/ScopeContract.php';
require_once __DIR__ . '/ScopedStateOverlay.php';

/** Pure record-scoped options projection shared by capture and mutation. */
final class ScopedOptionProjection {
    /** @return array<string,true> */
    public static function selectedSet(array $contract): array {
        $selected = array_fill_keys(ScopedStateOverlay::selected_identities($contract), true);
        if (isset($selected['options/core'])) {
            foreach (array_keys($selected) as $identity) {
                if (ScopeClosure::is_option_root($identity)) {
                    unset($selected[$identity]);
                }
            }
        }
        return $selected;
    }

    public static function hasRecordScopedOptions(array $contract): bool {
        return ScopeContract::option_root_names($contract) !== []
            && !isset(self::selectedSet($contract)['options/core']);
    }

    /** @return list<string> */
    public static function optionRootNames(array $contract): array {
        return ScopeContract::option_root_names($contract);
    }

    public static function stateIdentity(string $name): string {
        if ($name === '' || str_contains($name, "\0")) {
            throw new \RuntimeException('duo: scoped option state identity has an invalid option name');
        }
        return 'o' . substr(hash('sha256', "duo:scoped-option-state/v1\0" . $name), 0, 63);
    }

    /** @return array<string,array<string,mixed>> */
    public static function selectedRecords(array $document, array $contract): array {
        $records = OptionState::records($document);
        $out = [];
        foreach (self::optionRootNames($contract) as $name) {
            if (!array_key_exists($name, $records)) {
                throw new \RuntimeException("duo: scoped option '$name' disappeared from the frozen carrier");
            }
            $out[$name] = $records[$name];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,string> */
    public static function stateHashes(array $document, array $contract, ?array $names = null): array {
        $allowed = array_fill_keys(self::optionRootNames($contract), true);
        $wanted = $names === null ? null : array_fill_keys(array_map('strval', $names), true);
        $records = OptionState::records($document);
        $out = [];
        foreach ($records as $name => $record) {
            if (!isset($allowed[$name])) {
                if ($wanted !== null) {
                    throw new \RuntimeException('duo: scoped option state row escaped its selected records');
                }
                continue;
            }
            if ($wanted === null || isset($wanted[$name])) {
                $out[self::stateIdentity($name)] = OptionState::record_hash($record);
            }
        }
        if ($wanted !== null) {
            foreach (array_keys($wanted) as $name) {
                if (!isset($allowed[$name]) || !array_key_exists($name, $records)) {
                    throw new \RuntimeException('duo: scoped option state row omitted a selected record');
                }
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
