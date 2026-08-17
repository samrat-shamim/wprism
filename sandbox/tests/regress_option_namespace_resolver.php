<?php
/** Offline characterization of Policy's option namespace ownership lookup (DUO-3348 slice 56). */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/Grammar/OptionNamespaceResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$throws = static function (callable $callback, string $expected) use ($check): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        $check($error->getMessage() === $expected, 'overlapping namespaces preserve the exact deterministic refusal');
        return;
    }
    $check(false, 'overlapping namespaces refuse instead of choosing by manifest order');
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\OptionNamespaceResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
$childOut = is_resource($child) ? stream_get_contents($pipes[1]) : '';
$childErr = is_resource($child) ? stream_get_contents($pipes[2]) : '';
if (is_resource($child)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $childExit = proc_close($child);
} else {
    $childExit = 1;
}
$check(
    $childExit === 0 && $childOut === "loaded\n" && $childErr === '',
    'direct namespace resolver load stays independent of Policy, RepositoryCompiler, and WordPress'
);

require_once $resolverPath;

use Duo\OptionNamespaceResolver;

$manifests = [
    ['name' => 'alpha', 'option_namespaces' => [['match' => '^alpha_'], ['match' => '^alpha_exact$']]],
    ['name' => 'bravo', 'option_namespaces' => [['match' => '^bravo_']]],
];
$resolver = new OptionNamespaceResolver($manifests);
$check(
    $resolver->owner_for('alpha_key') === ['owner' => 'alpha', 'match' => '^alpha_']
        && $resolver->owner_for('bravo_key') === ['owner' => 'bravo', 'match' => '^bravo_']
        && $resolver->owner_for('outside') === null,
    'first matching manifest namespace returns exact owner/match provenance while an unowned name remains null'
);
$throws(
    static fn() => $resolver->owner_for('alpha_exact'),
    "duo: option 'alpha_exact' is claimed by overlapping namespaces from alpha, alpha — discovery ownership must not depend on manifest load order"
);
$throws(
    static fn() => (new OptionNamespaceResolver([
        ['name' => 'bravo', 'option_namespaces' => [['match' => '^shared_']]],
        ['name' => 'alpha', 'option_namespaces' => [['match' => '^shared_']]],
    ]))->owner_for('shared_name'),
    "duo: option 'shared_name' is claimed by overlapping namespaces from bravo, alpha — discovery ownership must not depend on manifest load order"
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->manifests = $manifests;
$check(
    $policy->option_namespace('alpha_key') === $resolver->owner_for('alpha_key')
        && $policy->option_namespace('outside') === null,
    'Policy preserves its public option namespace facade'
);
$policy->manifests[1]['option_namespaces'][] = ['match' => '^late_'];
$check(
    $policy->option_namespace('late_key') === ['owner' => 'bravo', 'match' => '^late_'],
    'Policy constructs a fresh resolver so mutable fixture manifests remain observable'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldBody = "        \$matches = [];\n        foreach (\$this->manifests as \$m) {";
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/OptionNamespaceResolver.php';") === 1
        && substr_count($policySource, 'return $this->option_namespace_resolver()->owner_for($name);') === 1
        && substr_count($policySource, 'new OptionNamespaceResolver($this->manifests)') === 1
        && !str_contains($policySource, $oldBody),
    'Policy has one direct resolver factory/facade and retains no namespace matching body'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
