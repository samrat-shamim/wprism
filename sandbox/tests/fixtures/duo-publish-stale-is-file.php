<?php
namespace Duo;

/** Test-only model of a stale negative path predicate from a bind mount. */
function is_file(string $path): bool {
    $stalePath = getenv('DUO_TEST_STALE_IS_FILE_PATH');
    if (is_string($stalePath) && $stalePath !== '' && $path === $stalePath) {
        return false;
    }
    $stalePrefix = getenv('DUO_TEST_STALE_IS_FILE_PREFIX');
    if (is_string($stalePrefix) && $stalePrefix !== '' && str_starts_with($path, $stalePrefix)) {
        return false;
    }
    return \is_file($path);
}
