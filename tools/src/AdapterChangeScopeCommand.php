<?php

declare(strict_types=1);

namespace Duo\Tooling;

require_once __DIR__ . '/AdapterChangeScopeDecision.php';

/** CLI parsing and stable rendering for tools/adapter-change-scope.php. */
final class AdapterChangeScopeCommand
{
    public const USAGE = 'usage: php tools/adapter-change-scope.php [--json] [--path=PATH ...] '
        . '[--rename-from=PATH --rename-to=PATH ...]';

    /**
     * @param list<string> $arguments arguments after the program name
     * @return array{status:int,stdout:string,stderr:string}
     */
    public static function execute(array $arguments): array
    {
        try {
            $parsed = self::parse($arguments);
        } catch (\InvalidArgumentException $invalid) {
            return [
                'status' => 2,
                'stdout' => '',
                'stderr' => 'adapter-change-scope: ' . $invalid->getMessage() . "\n" . self::USAGE . "\n",
            ];
        }

        if ($parsed['help']) {
            return ['status' => 0, 'stdout' => self::USAGE . "\n", 'stderr' => ''];
        }

        $decision = AdapterChangeScopeDecision::decide($parsed['changes']);
        $stdout = $parsed['json'] ? self::json($decision) : self::human($decision);
        return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    /**
     * @param list<string> $arguments
     * @return array{json:bool,help:bool,changes:list<string|array{from:string,to:string}>}
     */
    private static function parse(array $arguments): array
    {
        $json = false;
        $help = false;
        $changes = [];
        $pendingRename = null;

        foreach ($arguments as $argument) {
            if ($argument === '--json') {
                if ($json) {
                    throw new \InvalidArgumentException('--json may be specified only once');
                }
                $json = true;
                continue;
            }
            if ($argument === '--help' || $argument === '-h') {
                $help = true;
                continue;
            }
            if (str_starts_with($argument, '--path=')) {
                $changes[] = substr($argument, strlen('--path='));
                continue;
            }
            if (str_starts_with($argument, '--rename-from=')) {
                if ($pendingRename !== null) {
                    throw new \InvalidArgumentException('--rename-from requires its --rename-to before another rename');
                }
                $pendingRename = substr($argument, strlen('--rename-from='));
                continue;
            }
            if (str_starts_with($argument, '--rename-to=')) {
                if ($pendingRename === null) {
                    throw new \InvalidArgumentException('--rename-to requires a preceding --rename-from');
                }
                $changes[] = [
                    'from' => $pendingRename,
                    'to' => substr($argument, strlen('--rename-to=')),
                ];
                $pendingRename = null;
                continue;
            }

            throw new \InvalidArgumentException('unknown argument ' . self::render($argument));
        }

        if ($pendingRename !== null) {
            throw new \InvalidArgumentException('--rename-from requires a following --rename-to');
        }
        if ($help && ($changes !== [] || $json)) {
            throw new \InvalidArgumentException('--help cannot be combined with classification arguments');
        }

        return ['json' => $json, 'help' => $help, 'changes' => $changes];
    }

    /** @param array<string,mixed> $decision */
    private static function json(array $decision): string
    {
        $encoded = json_encode($decision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('adapter-change-scope: could not encode its decision');
        }
        return $encoded . "\n";
    }

    /** @param array{gate:string,adapter:?string,reason_code:string,classification:array<string,mixed>} $decision */
    private static function human(array $decision): string
    {
        $lines = [
            'adapter-change-scope: ' . $decision['gate'],
            'adapter: ' . ($decision['adapter'] ?? '-'),
            'reason: ' . $decision['reason_code'],
        ];
        $owners = $decision['classification']['owners'] ?? [];
        foreach ($owners as $owner) {
            if (is_array($owner)) {
                $lines[] = 'owner: ' . (string) ($owner['kind'] ?? 'full') . ' '
                    . (string) ($owner['root'] ?? 'unknown') . ' ' . (string) ($owner['path'] ?? '<unknown>');
            }
        }
        return implode("\n", $lines) . "\n";
    }

    private static function render(string $value): string
    {
        return var_export($value, true);
    }
}
