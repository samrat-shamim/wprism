<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/ControlRefusal.php';

/** One closed argv grammar shared by the non-root client and root authority. */
final class FirewallAuthorityArguments {
    /**
     * @param list<string> $arguments Arguments after the executable name.
     * @return array{action:string,options:array<string,string>}
     */
    public static function parse(array $arguments): array {
        if (!array_is_list($arguments)) {
            throw new ControlRefusal('firewall arguments are unavailable');
        }
        $action = array_shift($arguments);
        if (!is_string($action)
            || !in_array($action, [
                'bind', 'inspect', 'preflight', 'reconcile', 'unbind', 'worker-preflight',
            ], true)) {
            throw new ControlRefusal('firewall action is invalid');
        }
        $options = [];
        while ($arguments !== []) {
            $flag = array_shift($arguments);
            $value = array_shift($arguments);
            if (!is_string($flag) || preg_match('/\A--[a-z][a-z0-9-]*\z/D', $flag) !== 1
                || !is_string($value) || $value === '' || str_contains($value, "\0")) {
                throw new ControlRefusal('firewall options are malformed');
            }
            $name = substr($flag, 2);
            if (isset($options[$name])) {
                throw new ControlRefusal('firewall option is repeated');
            }
            $options[$name] = $value;
        }
        $expected = match ($action) {
            'bind' => [
                'bridge-name', 'config-sha256', 'gateway', 'network-id', 'network-name', 'subnet',
            ],
            'inspect', 'unbind' => ['config-sha256', 'network-name'],
            'preflight', 'reconcile' => [],
            'worker-preflight' => ['config-sha256'],
        };
        $actual = array_keys($options);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('firewall options are incomplete or unknown');
        }
        return ['action' => $action, 'options' => $options];
    }
}
