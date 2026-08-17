<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Init/InitProtocol.php';
require_once __DIR__ . '/../Publication/Publish.php';

/** Strict first-publication and interrupted-init ownership checks. */
final class InitialCaptureBoundary {
    public static function assertNoInterruptedInit(string $repo): void {
        foreach ([InitProtocol::ATTEMPT_FILE, InitProtocol::ATTEMPT_NEXT_FILE] as $name) {
            $path = rtrim($repo, '/') . '/' . $name;
            if (file_exists($path) || is_link($path)) {
                throw new CommandRefusalException(
                    'interrupted_init_recovery_pending',
                    'capture refused while a sealed init recovery journal exists',
                    'run duo init for the same environment to verify or roll back that interrupted attempt, then capture again',
                    [],
                    'duo: capture refused while a sealed init recovery journal exists; '
                    . 'run duo init for the same environment to verify or roll back that attempt before capturing again'
                );
            }
        }
    }

    /** First init never recovers or adopts pre-existing protocol siblings. */
    public static function assertProtocolBoundaries(string $stateDir): void {
        foreach ([
            Publish::stage_dir($stateDir),
            Publish::backup_dir($stateDir),
            Publish::intent_path($stateDir),
            Publish::receipt_path($stateDir),
            Publish::intent_path($stateDir) . '.previous',
            Publish::intent_path($stateDir) . '.next',
            Publish::receipt_path($stateDir) . '.previous',
            Publish::receipt_path($stateDir) . '.next',
        ] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new InitialStateBoundaryException(
                    'duo: initial capture protocol namespace gained an unreviewed sibling'
                );
            }
        }
    }

    public static function assertConfigIdentity(string $path, string $expected): void {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new InitialStateBoundaryException('duo: confirmed init configuration changed type before capture');
        }
        $stat = @lstat($path);
        $bytes = Canon::read_file($path);
        if (!is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new InitialStateBoundaryException('duo: confirmed init configuration identity is unavailable');
        }
        $actual = 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
            'sha256' => hash('sha256', $bytes),
        ]));
        if (!hash_equals($expected, $actual)) {
            throw new InitialStateBoundaryException('duo: confirmed init configuration changed before capture');
        }
    }

    /** First init reserves one exact empty state directory before Capture. */
    public static function assertStateReservation(string $path, string $expected): void {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new InitialStateBoundaryException('duo: initial state boundary changed before publication');
        }
        $entries = @scandir($path);
        $stat = @lstat($path);
        if ($entries === false || !is_array($stat) || !isset($stat['dev'], $stat['ino'])) {
            throw new InitialStateBoundaryException('duo: initial state boundary could not be verified before publication');
        }
        $children = array_values(array_diff($entries, ['.', '..']));
        if ($children !== []) {
            throw new InitialStateBoundaryException('duo: initial state boundary gained unreviewed content before publication');
        }
        $inode = 'sha256:' . hash('sha256', Canon::encode([
            'dev' => (string) $stat['dev'],
            'ino' => (string) $stat['ino'],
        ]));
        $actual = 'sha256:' . hash('sha256', Canon::encode(['inode' => $inode, 'tree' => []]));
        if (!hash_equals($expected, $actual)) {
            throw new InitialStateBoundaryException('duo: initial state directory identity changed before publication');
        }
    }
}
