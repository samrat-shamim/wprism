<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

final class Loader
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function check(string $dist): array
    {
        $budgets = $this->budgets();
        $sourceAgent = $this->root . '/agent';
        $distRoot = str_starts_with($dist, '/') ? $dist : $this->root . '/' . $dist;
        $distAgent = $distRoot . '/payload/agent';
        $layouts = [
            'source' => [$sourceAgent . '/duo-loader.php', $sourceAgent . '/duo.php', $sourceAgent . '/src'],
            'dist' => [$distAgent . '/duo-loader.php', $distAgent . '/duo/duo.php', $distAgent . '/duo/src'],
        ];
        $results = [];
        foreach ($layouts as $layout => [$topLoader, $agent, $sourceDirectory]) {
            $this->assertPhp80ParseSafe($topLoader);
            $this->assertLegacyMap($agent, $sourceDirectory);
            $ordinary = $this->ordinaryScenario($agent);
            $journal = $this->journalScenario($agent);
            $cli = $this->cliScenario($agent);
            $unsupported80 = $this->unsupportedScenario($topLoader, 80000);
            $unsupported81 = $this->unsupportedScenario($topLoader, 80100);
            if (($ordinary['engine_loaded_before_reference'] ?? null) !== false
                || ($ordinary['journal_loaded'] ?? null) !== false
                || ($ordinary['explicit_lazy_class'] ?? null) !== true
                || ($journal['hooks'] ?? null) !== [
                    ['filter', 'query', -2147483646],
                    ['action', 'shutdown', PHP_INT_MAX],
                ]
                || ($cli['command'] ?? null) !== 'duo'
                || ($cli['class'] ?? null) !== 'Duo\\Cli'
                || ($unsupported80['status'] ?? null) !== 'unsupported_php_8_0'
                || ($unsupported81['status'] ?? null) !== 'unsupported_php_8_1'
                || ($unsupported80['autoloaders'] ?? null) !== 0
                || ($unsupported81['autoloaders'] ?? null) !== 0) {
                throw new CatalogException(
                    "loader behavior disagrees in $layout layout: "
                    . json_encode([$ordinary, $journal, $cli, $unsupported80, $unsupported81], JSON_UNESCAPED_SLASHES),
                );
            }
            $this->assertBudgets($layout, $ordinary, $journal, $cli, $budgets);
            $results[$layout] = [
                'normal' => $ordinary,
                'journal' => $journal,
                'wp_cli' => $cli,
                'php_8_0' => $unsupported80,
                'php_8_1' => $unsupported81,
            ];
        }
        return [
            'format' => 'duo-loader-check/v1',
            'state' => 'pass',
            'candidate_sha' => $this->candidateSha(),
            'runtime_php' => PHP_VERSION,
            'certified_profile' => '>=8.3,<8.4',
            'php_8_2' => PHP_VERSION_ID >= 80200 ? 'source_syntax_and_loader_executed' : 'unavailable',
            'php_8_3' => PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 'certified_matrix_executed' : 'parse_contract_only',
            'php_8_4' => 'top_loader_parse_safe; certified_profile_refuses_max_exclusive',
            'budget_profile_sha256' => $this->fileDigest(__DIR__ . '/loader-profile.json'),
            'dependency_and_rss_budgets' => 'pass',
            'layouts' => $results,
        ];
    }

    private function assertPhp80ParseSafe(string $path): void
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read loader: $path");
        }
        if (!class_exists(\PhpParser\ParserFactory::class) || !class_exists(\PhpParser\PhpVersion::class)) {
            throw new CatalogException('nikic/php-parser is required for the PHP 8.0 loader syntax contract');
        }
        try {
            $parser = (new \PhpParser\ParserFactory())->createForVersion(\PhpParser\PhpVersion::fromString('8.0'));
            $parser->parse($bytes);
        } catch (\PhpParser\Error $exception) {
            throw new CatalogException("top-level loader is not PHP 8.0 parse-safe: {$exception->getMessage()}");
        }
        $guard = strpos($bytes, 'if (PHP_VERSION_ID < 80200)');
        $require = strpos($bytes, "require_once __DIR__ . '/duo/duo.php';");
        if ($guard === false || $require === false || $guard > $require) {
            throw new CatalogException('top-level loader does not refuse unsupported PHP before engine load');
        }
    }

    private function assertLegacyMap(string $agent, string $sourceDirectory): void
    {
        $bytes = file_get_contents($agent);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read agent bootstrap: $agent");
        }
        $begin = "    // BEGIN GENERATED LEGACY CLASSMAP\n";
        $end = "    // END GENERATED LEGACY CLASSMAP\n";
        $start = strpos($bytes, $begin);
        $finish = strpos($bytes, $end);
        if ($start === false || $finish === false || $finish <= $start) {
            throw new CatalogException('agent bootstrap has no generated legacy classmap markers');
        }
        $actual = substr($bytes, $start, $finish + strlen($end) - $start);
        $expected = $begin;
        foreach ($this->legacyMap($sourceDirectory) as $class => $file) {
            $expected .= "    '" . str_replace('\\', '\\\\', $class) . "' => '$file',\n";
        }
        $expected .= $end;
        if (!hash_equals($expected, $actual)) {
            throw new CatalogException('generated legacy classmap is stale');
        }
    }

    /** @return array<string,string> */
    private function legacyMap(string $directory): array
    {
        $files = glob($directory . '/*.php');
        if (!is_array($files)) {
            throw new CatalogException("cannot enumerate loader source: $directory");
        }
        sort($files, SORT_STRING);
        $map = [];
        foreach ($files as $file) {
            $bytes = file_get_contents($file);
            if (!is_string($bytes)) {
                throw new CatalogException("cannot read loader source: $file");
            }
            $namespace = '';
            $tokens = token_get_all($bytes);
            $count = count($tokens);
            for ($index = 0; $index < $count; ++$index) {
                $token = $tokens[$index];
                if (!is_array($token)) {
                    continue;
                }
                if ($token[0] === T_NAMESPACE) {
                    $namespace = $this->namespaceAt($tokens, $index + 1);
                    continue;
                }
                $classTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
                if (defined('T_ENUM')) {
                    $classTokens[] = T_ENUM;
                }
                if (!in_array($token[0], $classTokens, true) || $this->isClassConstantOrAnonymous($tokens, $index)) {
                    continue;
                }
                $name = $this->classNameAt($tokens, $index + 1);
                if ($name === null || $name === basename($file, '.php')) {
                    continue;
                }
                $class = $namespace === '' ? $name : $namespace . '\\' . $name;
                $map[$class] ??= basename($file);
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function namespaceAt(array $tokens, int $start): string
    {
        $namespace = '';
        for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_string($token) && ($token === ';' || $token === '{')) {
                break;
            }
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }
        return $namespace;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function classNameAt(array $tokens, int $start): ?string
    {
        for ($index = $start, $count = count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if (is_string($token) && $token === '{') {
                return null;
            }
        }
        return null;
    }

    /** @param list<array{int,string,int}|string> $tokens */
    private function isClassConstantOrAnonymous(array $tokens, int $index): bool
    {
        for (--$index; $index >= 0; --$index) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return is_array($token) && in_array($token[0], [T_NEW, T_DOUBLE_COLON], true);
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function ordinaryScenario(string $agent): array
    {
        return $this->runJson([
            PHP_BINARY,
            '-r',
            'define("ABSPATH", __DIR__ . "/"); require $argv[1];'
                . '$before=class_exists("Duo\\Apply",false)||class_exists("Duo\\Capture",false);'
                . '$journal=class_exists("Duo\\Journal",false);'
                . '$root=dirname($argv[1]);$count=static fn()=>count(array_filter(get_included_files(),static fn($f)=>str_starts_with($f,$root."/")));'
                . '$boot=$count();$lazy=class_exists("Duo\\Canon");$after=$count();'
                . 'echo json_encode(["engine_loaded_before_reference"=>$before,"journal_loaded"=>$journal,"explicit_lazy_class"=>$lazy,'
                . '"duo_files_at_boot"=>$boot,"duo_files_after_lazy_reference"=>$after,"peak_rss_kib"=>(int)(memory_get_peak_usage(true)/1024)]);',
            $agent,
        ]);
    }

    /** @return array<string,mixed> */
    private function journalScenario(string $agent): array
    {
        return $this->runJson([
            PHP_BINARY,
            '-r',
            '$hooks=[]; function get_option(){return null;}'
                . 'function add_filter($name,$callback,$priority=10){global $hooks;$hooks[]=["filter",$name,$priority];}'
                . 'function add_action($name,$callback,$priority=10){global $hooks;$hooks[]=["action",$name,$priority];}'
                . 'define("ABSPATH",__DIR__."/");define("DUO_JOURNAL",true);require $argv[1];'
                . '$root=dirname($argv[1]);$count=count(array_filter(get_included_files(),static fn($f)=>str_starts_with($f,$root."/")));'
                . 'echo json_encode(["hooks"=>$hooks,"duo_files_at_boot"=>$count,"peak_rss_kib"=>(int)(memory_get_peak_usage(true)/1024)]);',
            $agent,
        ]);
    }

    /** @return array<string,mixed> */
    private function cliScenario(string $agent): array
    {
        return $this->runJson([
            PHP_BINARY,
            '-r',
            'class WP_CLI{public static array $added=[];public static function add_command($name,$class){self::$added=[$name,$class];}}'
                . 'define("ABSPATH",__DIR__."/");define("WP_CLI",true);require $argv[1];'
                . '$root=dirname($argv[1]);$count=count(array_filter(get_included_files(),static fn($f)=>str_starts_with($f,$root."/")));'
                . 'echo json_encode(["command"=>WP_CLI::$added[0]??null,"class"=>WP_CLI::$added[1]??null,'
                . '"duo_files_at_boot"=>$count,"peak_rss_kib"=>(int)(memory_get_peak_usage(true)/1024)]);',
            $agent,
        ]);
    }

    /** @return array<string,mixed> */
    private function unsupportedScenario(string $loader, int $version): array
    {
        $bytes = file_get_contents($loader);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read unsupported-runtime loader: $loader");
        }
        $emulated = str_replace('PHP_VERSION_ID < 80200', "$version < 80200", $bytes, $count);
        if ($count !== 1) {
            throw new CatalogException('unsupported-runtime guard is ambiguous');
        }
        $label = $version === 80000 ? '8_0' : '8_1';
        $emulated = str_replace(
            "PHP_MAJOR_VERSION . '_' . PHP_MINOR_VERSION",
            "'$label'",
            $emulated,
            $labelCount,
        );
        if ($labelCount !== 1) {
            throw new CatalogException('unsupported-runtime status is ambiguous');
        }
        $temporary = sys_get_temp_dir() . '/duo-loader-' . bin2hex(random_bytes(8)) . '.php';
        if (file_put_contents($temporary, $emulated) !== strlen($emulated)) {
            throw new CatalogException('cannot create unsupported-runtime loader fixture');
        }
        try {
            return $this->runJson([
                PHP_BINARY,
                '-r',
                '$before=spl_autoload_functions()?:[];require $argv[1];$after=spl_autoload_functions()?:[];'
                    . 'echo json_encode(["status"=>defined("DUO_AGENT_RUNTIME_STATUS")?DUO_AGENT_RUNTIME_STATUS:null,"autoloaders"=>count($after)-count($before)]);',
                $temporary,
            ]);
        } finally {
            unlink($temporary);
        }
    }

    /**
     * @param list<string> $command
     * @return array<string,mixed>
     */
    private function runJson(array $command): array
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot start loader scenario');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new CatalogException('loader scenario failed: ' . trim((string) $stderr));
        }
        try {
            $decoded = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('loader scenario emitted invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new CatalogException('loader scenario did not emit a JSON object');
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new CatalogException('loader scenario result has a non-string key');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @return array<string,int> */
    private function budgets(): array
    {
        $bytes = file_get_contents(__DIR__ . '/loader-profile.json');
        if (!is_string($bytes)) {
            throw new CatalogException('loader budget profile is absent');
        }
        try {
            $document = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('loader budget profile is invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document) || array_is_list($document)
            || array_keys($document) !== ['format', 'maximums']
            || ($document['format'] ?? null) !== 'duo-loader-profile/v1'
            || !is_array($document['maximums'] ?? null)
            || array_keys($document['maximums']) !== [
                'journal_duo_files_at_boot', 'normal_duo_files_after_lazy_reference',
                'normal_duo_files_at_boot', 'peak_rss_kib', 'wp_cli_duo_files_at_boot',
            ]) {
            throw new CatalogException('loader budget profile is malformed');
        }
        $budgets = [];
        foreach ($document['maximums'] as $name => $value) {
            if (!is_string($name) || !is_int($value) || $value < 1) {
                throw new CatalogException('loader budget profile maximum is invalid');
            }
            $budgets[$name] = $value;
        }
        return $budgets;
    }

    /**
     * @param array<string,mixed> $ordinary
     * @param array<string,mixed> $journal
     * @param array<string,mixed> $cli
     * @param array<string,int> $budgets
     */
    private function assertBudgets(string $layout, array $ordinary, array $journal, array $cli, array $budgets): void
    {
        $measurements = [
            'normal_duo_files_at_boot' => $ordinary['duo_files_at_boot'] ?? null,
            'normal_duo_files_after_lazy_reference' => $ordinary['duo_files_after_lazy_reference'] ?? null,
            'journal_duo_files_at_boot' => $journal['duo_files_at_boot'] ?? null,
            'wp_cli_duo_files_at_boot' => $cli['duo_files_at_boot'] ?? null,
        ];
        foreach ($measurements as $name => $value) {
            if (!is_int($value) || $value > $budgets[$name]) {
                throw new CatalogException("loader dependency budget failed for $layout/$name");
            }
        }
        foreach ([$ordinary, $journal, $cli] as $scenario) {
            $rss = $scenario['peak_rss_kib'] ?? null;
            if (!is_int($rss) || $rss > $budgets['peak_rss_kib']) {
                throw new CatalogException("loader RSS budget failed for $layout");
            }
        }
    }

    private function fileDigest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new CatalogException('cannot digest loader input');
        }
        return 'sha256:' . $digest;
    }

    private function candidateSha(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot resolve loader candidate');
        }
        fclose($pipes[0]);
        $sha = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($sha) || preg_match('/^[a-f0-9]{40}\s*$/D', $sha) !== 1) {
            throw new CatalogException('loader candidate is not a full SHA');
        }
        return trim($sha);
    }
}
