<?php
namespace Duo;

/**
 * Enumerates one compiler state partition without interpreting its contents.
 *
 * This deliberately differs from StateTreeWalker: compiler input comprises
 * every regular file below an arbitrary staged state root, including deletion
 * witnesses and unknown files that parsing must diagnose. Link findings are
 * reported through the compiler-owned aggregate callback so independent
 * parsing/schema failures remain visible in one canonical refusal.
 */
final class RepositoryStateFileCatalog {
    private string $stateDir;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(string $stateDir, \Closure $add) {
        $this->stateDir = $stateDir;
        $this->add = $add;
    }

    /** @return array<string,string> state-relative path => absolute path */
    public function files(): array {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                if ($file->isLink()) {
                    $rel = substr($file->getPathname(), strlen($this->stateDir) + 1);
                    ($this->add)('unsafe_repository_path', $rel, '', 'symbolic links are not valid canonical entities', null);
                }
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($this->stateDir) + 1));
            $out[$rel] = $file->getPathname();
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
