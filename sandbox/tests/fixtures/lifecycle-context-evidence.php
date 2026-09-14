<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
require_once __DIR__ . '/../lib/check.php';
[$mode, $stem, $pair] = array_slice($argv, 1);
if (!preg_match('/^[a-z][a-z0-9]*$/D', $pair)) throw new RuntimeException('invalid lifecycle evidence pair');
$transport = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (Creating|Created) *$/D';
if ($mode === 'admit') {
    WPrismTest\PrivateCommandOutput::readBytes($stem, $transport);
    exit(0);
}
if ($mode !== 'final') throw new RuntimeException('invalid lifecycle evidence mode');
$read = static fn(string $name) => json_decode(WPrismTest\PrivateCommandOutput::readObject($stem . '/' . $name, $transport), true, 32, JSON_THROW_ON_ERROR);
$plugin = 'lifecycle-context-probe/context.php';
$before = $read('before');
wprism_check($before === ['admin' => false, 'hook_state' => false], 'fresh target has no lifecycle hook effects before deployment');
foreach (['activate' => [1, 0, [$plugin], []], 'repeat' => [1, 0, [], []],
    'retire' => [1, 1, [], [$plugin]], 'reactivate' => [2, 1, [$plugin], []]] as $phase => [$activations, $deactivations, $activated, $deactivated]) {
    $result = $read($phase . '-deploy');
    $observed = $read($phase . '-observe');
    wprism_check($result['activated'] === $activated && $result['deactivated'] === $deactivated && $result['warnings'] === [],
        'public deployment reports exact lifecycle work without warnings: ' . $phase);
    wprism_check($observed === ['admin' => false, 'hook_state' => ['activate' => $activations, 'deactivate' => $deactivations,
        'admin' => true, 'user' => 0, 'entry' => 'wprism-deploy.php']],
        'separate ordinary CLI observation proves native hooks ran in deployment without administrator impersonation: ' . $phase);
}
wprism_check_summary('native lifecycle command context');
