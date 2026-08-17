<?php
/**
 * Offline characterization for pure effective table declaration resolution
 * (DUO-3348 slice 53).
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$resolverPath = "$root/agent/src/Grammar/TableDeclarationResolver.php";
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$child = proc_open(
    [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\TableDeclarationResolver::class, false) && !class_exists(\\Duo\\Policy::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) && !function_exists("get_option") ? "loaded\\n" : "broken\\n";', $resolverPath],
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
    'direct resolver load stays independent of Policy, RepositoryCompiler, and WordPress'
);

require_once $resolverPath;

use Duo\TableDeclarationResolver;

$rowFirst = ['class' => 'authored_snapshot', 'identity' => ['columns' => ['uuid']]];
$rowSecond = ['class' => 'authored_snapshot', 'identity' => ['columns' => ['slug']]];
$sidecar = ['class' => 'authored_snapshot_meta', 'attached_to' => ['table' => 'acme_rows', 'column' => 'meta']];
$siteRows = ['class' => 'authored_snapshot', 'identity' => ['columns' => ['site_uuid']]];
$manifests = [
    ['name' => 'first', 'tables' => ['zeta_rows' => $rowFirst, 'acme_rows' => $rowFirst, 'acme_rows_meta' => $sidecar]],
    ['name' => 'second', 'tables' => ['alpha_rows' => $rowFirst, 'acme_rows' => $rowSecond]],
];
$site = ['policy' => ['tables' => ['acme_rows' => $siteRows, 'site_rows' => $rowFirst]]];
$resolver = new TableDeclarationResolver($site, $manifests);
$check(
    array_keys($resolver->tables()) === ['zeta_rows', 'acme_rows', 'acme_rows_meta', 'alpha_rows', 'site_rows']
        && $resolver->tables()['acme_rows'] === $siteRows
        && $resolver->details('acme_rows') === ['rule' => $siteRows, 'source' => 'site.duo.json']
        && $resolver->details('alpha_rows') === ['rule' => $rowFirst, 'source' => 'second']
        && $resolver->details('absent') === ['rule' => null, 'source' => null]
        && $resolver->attached_meta_table_for_owner('acme_rows') === ['name' => 'acme_rows_meta', 'rule' => $sidecar]
        && $resolver->attached_meta_table_for_owner('absent') === null,
    'resolver preserves last declaration/site precedence, provenance, overwritten-key order, and first effective sidecar lookup'
);

require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Policy/Policy.php";

$policy = new Duo\Policy();
$policy->site = $site;
$policy->manifests = $manifests;
$check(
    $policy->declared_tables() === $resolver->tables()
        && $policy->declared_table_details('acme_rows') === $resolver->details('acme_rows')
        && $policy->table_rule('alpha_rows') === $rowFirst
        && $policy->attached_meta_table_for_owner('acme_rows') === $resolver->attached_meta_table_for_owner('acme_rows'),
    'Policy retains all public effective-table and attached-meta compatibility facades over the pure resolver'
);
$policy->site['policy']['tables']['acme_rows'] = $rowSecond;
$policy->manifests[1]['tables']['acme_rows_meta_late'] = [
    'class' => 'authored_snapshot_meta', 'attached_to' => ['table' => 'acme_rows', 'column' => 'late_meta'],
];
$check(
    $policy->declared_table_details('acme_rows') === ['rule' => $rowSecond, 'source' => 'site.duo.json']
        && $policy->attached_meta_table_for_owner('acme_rows') === ['name' => 'acme_rows_meta', 'rule' => $sidecar],
    'Policy builds a fresh resolver per call so mutable fixture declarations stay observable without changing first-sidecar order'
);

$policySource = (string) file_get_contents("$root/agent/src/Policy/Policy.php");
$oldDetailsLoop = '        foreach ($this->manifests as $m) {' . "\n"
    . "            if (isset(\$m['tables'][\$name])) {";
$oldTablesLoop = '        foreach ($this->manifests as $m) {' . "\n"
    . "            foreach (\$m['tables'] ?? [] as \$name => \$r) {";
$check(
    substr_count($policySource, "require_once __DIR__ . '/../Grammar/TableDeclarationResolver.php';") === 1
        && str_contains($policySource, 'return $this->table_declaration_resolver()->details($name);')
        && str_contains($policySource, 'return $this->table_declaration_resolver()->tables();')
        && str_contains($policySource, 'return $this->table_declaration_resolver()->attached_meta_table_for_owner($ownerTable);')
        && str_contains($policySource, 'new TableDeclarationResolver($this->site, $this->manifests)')
        && !str_contains($policySource, $oldDetailsLoop)
        && !str_contains($policySource, $oldTablesLoop),
    'Policy requires the resolver once, retains fresh facade delegation, and leaves no duplicate table precedence loops'
);

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}
echo "\nALL PASSED\n";
