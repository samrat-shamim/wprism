<?php
namespace Duo;

require_once __DIR__ . '/PolicyRuleResolver.php';

/**
 * Pure projection of the policy's exact option declarations.
 *
 * Exact option names may be declared by a pinned manifest or site policy;
 * their winning classification remains PolicyRuleResolver's responsibility.
 * This collaborator owns the shared enumeration and class/sub-key views so
 * callers cannot accidentally reimplement either collection or ordering.
 */
final class ExactOptionResolver {
    /**
     * @param array<string,mixed> $site
     * @param list<array<string,mixed>> $manifests
     */
    public function __construct(
        private array $site,
        private array $manifests,
        private PolicyRuleResolver $rules
    ) {}

    /** @return array<string,array> */
    public function all(): array {
        $names = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['options'] ?? [] as $name => $_rule) {
                $names[(string) $name] = true;
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $_rule) {
            $names[(string) $name] = true;
        }
        $out = [];
        foreach (array_keys($names) as $name) {
            $rule = $this->rules->details('options', $name)['rule'];
            if ($rule !== null) {
                $out[$name] = $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,array> */
    public function authored(): array {
        return $this->by_class('authored');
    }

    /** @return array<string,array> */
    public function env(): array {
        return $this->by_class('env');
    }

    /** @return array<string,array> */
    public function sub_keyed(): array {
        $out = [];
        foreach ($this->all() as $name => $rule) {
            if (!empty($rule['sub_keys'])) {
                $out[$name] = $rule;
            }
        }
        return $out;
    }

    /** @return array<string,array> */
    private function by_class(string $class): array {
        $out = [];
        foreach ($this->all() as $name => $rule) {
            if (($rule['class'] ?? '') === $class) {
                $out[$name] = $rule;
            }
        }
        return $out;
    }
}
