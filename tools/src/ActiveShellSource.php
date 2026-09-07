<?php

declare(strict_types=1);

namespace WPrism\Tooling;

/** Remove shell comments and heredoc bodies before static premise checks. */
final class ActiveShellSource
{
    public static function source(string $source): string
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $active = array_fill(0, count($lines), '');
        foreach (self::lines($source) as $line) {
            $active[$line['line'] - 1] = $line['code'];
        }
        return implode("\n", $active);
    }

    /** @return list<array{code:string,comment:string,line:int}> */
    public static function lines(string $source): array
    {
        return self::parsedLines($source);
    }

    /** @return list<array{code:string,comment:string,line:int}> */
    public static function commandLines(string $source): array
    {
        $commands = [];
        foreach (self::lines($source) as $line) {
            $state = ['quote' => null, 'substitutions' => []];
            [$code] = self::codeAndComment($line['code'], $state, true);
            $commands[] = ['code' => $code, 'comment' => $line['comment'], 'line' => $line['line']];
        }
        return $commands;
    }

    /** @return list<array{code:string,comment:string,line:int}> */
    private static function parsedLines(string $source): array
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $active = [];
        $heredocs = [];
        $shellState = ['quote' => null, 'substitutions' => []];
        for ($offset = 0, $count = count($lines); $offset < $count; $offset++) {
            $line = $lines[$offset];
            if ($heredocs !== []) {
                $expected = $heredocs[0];
                $candidate = $expected['tabs'] ? ltrim($line, "\t") : $line;
                if ($candidate === $expected['delimiter']) {
                    array_shift($heredocs);
                }
                continue;
            }

            $lineNumber = $offset + 1;
            while (self::hasLineContinuation($line, $shellState)) {
                if (!isset($lines[$offset + 1])) {
                    throw new \RuntimeException('Active shell source ends with an incomplete line continuation');
                }
                // POSIX shell removes only the backslash-newline pair. Any
                // separating whitespace must already exist in the source.
                $line = substr($line, 0, -1) . $lines[++$offset];
            }
            [$code, $comment] = self::codeAndComment($line, $shellState);
            $active[] = ['code' => $code, 'comment' => $comment, 'line' => $lineNumber];
            foreach (self::heredocDeclarations($code) as $declaration) {
                $heredocs[] = $declaration;
            }
        }
        if ($heredocs !== []) {
            throw new \RuntimeException('Active shell source contains an unterminated heredoc');
        }
        if ($shellState['quote'] !== null || $shellState['substitutions'] !== []) {
            throw new \RuntimeException('Active shell source contains an unterminated quote or command substitution');
        }
        return $active;
    }

    /** @return array{code:string,comment:string,line:int}|null */
    public static function statement(string $source, string $needle): ?array
    {
        $matches = self::statements($source, $needle);
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Census callers may accept repeated reachable commands; unique-premise
     * callers must keep statement()'s refusal of ambiguity. A fresh-pair loop
     * legitimately acquires once per lane, not once per source file.
     *
     * @return list<array{code:string,comment:string,line:int}>
     */
    public static function statements(string $source, string $needle): array
    {
        if ($needle === '') {
            return [];
        }
        $matches = [];
        $lines = self::lines($source);
        $functions = self::functionContexts($lines);
        foreach ($lines as $line) {
            $function = $functions['lines'][$line['line']] ?? null;
            if ($function !== null && !isset($functions['reachable'][$function])) {
                continue;
            }
            $code = ltrim($line['code']);
            if (!str_starts_with($code, $needle)) {
                continue;
            }
            $suffix = substr($code, strlen($needle));
            if ($suffix !== '' && preg_match('/^[ \t;&|]/', $suffix) !== 1) {
                continue;
            }
            $matches[] = $line;
        }
        return $matches;
    }

    /** @return list<string> */
    public static function manifestParticipants(string $source): array
    {
        $declaration = null;
        $lines = self::lines($source);
        $functionLines = self::functionContexts($lines)['lines'];
        foreach ($lines as $line) {
            if (isset($functionLines[$line['line']])) {
                continue;
            }
            $code = trim($line['code']);
            if (!str_contains($code, 'WPRISM_CERTIFICATION_MANIFESTS_JSON')) {
                continue;
            }
            if ($declaration !== null
                || preg_match(
                    "/^WPRISM_CERTIFICATION_MANIFESTS_JSON='(?<json>\\[[^'\\r\\n]*\\])'$/D",
                    $code,
                    $match
                ) !== 1) {
                throw new \RuntimeException(
                    'Active shell source contains a non-canonical certification manifest declaration'
                );
            }
            try {
                $decoded = json_decode($match['json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $failure) {
                throw new \RuntimeException(
                    'Active shell source contains malformed certification manifest JSON',
                    0,
                    $failure
                );
            }
            if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
                throw new \RuntimeException(
                    'Active shell source certification manifests must be a non-empty list'
                );
            }
            $participants = [];
            foreach ($decoded as $participant) {
                if (!is_string($participant)
                    || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $participant) !== 1
                    || isset($participants[$participant])) {
                    throw new \RuntimeException(
                        'Active shell source certification manifests are malformed or duplicated'
                    );
                }
                $participants[$participant] = true;
            }
            $declaration = array_keys($participants);
        }
        return $declaration ?? [];
    }

    /** @param array{quote:?string,substitutions:list<array{quote:?string,depth:int,mask:bool}>} $shellState */
    private static function hasLineContinuation(string $line, array $shellState): bool
    {
        if (!str_ends_with($line, '\\')) {
            return false;
        }
        $quote = $shellState['quote'];
        $substitutions = $shellState['substitutions'];
        $escaped = false;
        for ($offset = 0, $length = strlen($line); $offset < $length; $offset++) {
            $character = $line[$offset];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== "'" && $character === '\\') {
                if ($offset === $length - 1) {
                    return true;
                }
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($quote === '"' && $character === '`') {
                    throw new \RuntimeException(
                        'Active shell source contains unsupported legacy backtick command substitution'
                    );
                }
                if ($quote === '"'
                    && $character === '$'
                    && ($line[$offset + 1] ?? '') === '(') {
                    $substitutions[] = ['quote' => $quote, 'depth' => 1, 'mask' => false];
                    $quote = null;
                    $offset++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($substitutions !== [] && self::isCaseReservedWordAt($line, $offset)) {
                throw new \RuntimeException(
                    'Active shell source contains unsupported case grammar inside command substitution'
                );
            }
            if ($character === '`') {
                throw new \RuntimeException(
                    'Active shell source contains unsupported legacy backtick command substitution'
                );
            }
            if ($character === '$' && ($line[$offset + 1] ?? '') === '(') {
                $substitutions[] = ['quote' => null, 'depth' => 1, 'mask' => false];
                $offset++;
                continue;
            }
            if ($substitutions !== [] && $character === '(') {
                $substitutions[array_key_last($substitutions)]['depth']++;
                continue;
            }
            if ($substitutions !== [] && $character === ')') {
                $index = array_key_last($substitutions);
                $substitutions[$index]['depth']--;
                if ($substitutions[$index]['depth'] === 0) {
                    $quote = $substitutions[$index]['quote'];
                    array_pop($substitutions);
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '#') {
                $previous = $offset === 0 ? '' : $line[$offset - 1];
                if ($offset === 0 || ctype_space($previous) || str_contains(';|&()', $previous)) {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * @param array{quote:?string,substitutions:list<array{quote:?string,depth:int,mask:bool}>} $shellState
     * @return array{0:string,1:string}
     */
    private static function codeAndComment(
        string $line,
        array &$shellState,
        bool $maskInlineQuotes = false
    ): array
    {
        $quote = $shellState['quote'];
        $maskQuotedBytes = $quote !== null;
        $substitutions = $shellState['substitutions'];
        $escaped = false;
        $visible = '';
        for ($offset = 0, $length = strlen($line); $offset < $length; $offset++) {
            $character = $line[$offset];
            if ($escaped) {
                $escaped = false;
                $visible .= $maskQuotedBytes ? ' ' : $character;
                continue;
            }
            if ($quote !== "'" && $character === '\\') {
                $escaped = true;
                $visible .= $maskQuotedBytes ? ' ' : $character;
                continue;
            }
            if ($quote !== null) {
                if ($quote === '"' && $character === '`') {
                    throw new \RuntimeException(
                        'Active shell source contains unsupported legacy backtick command substitution'
                    );
                }
                if ($quote === '"'
                    && $character === '$'
                    && ($line[$offset + 1] ?? '') === '(') {
                    $substitutions[] = [
                        'quote' => $quote,
                        'depth' => 1,
                        'mask' => $maskQuotedBytes,
                    ];
                    $quote = null;
                    $visible .= $maskQuotedBytes ? '  ' : '$(';
                    $maskQuotedBytes = false;
                    $offset++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                    $visible .= $maskQuotedBytes ? ' ' : $character;
                    $maskQuotedBytes = false;
                } else {
                    $visible .= $maskQuotedBytes ? ' ' : $character;
                }
                continue;
            }
            if ($substitutions !== [] && self::isCaseReservedWordAt($line, $offset)) {
                throw new \RuntimeException(
                    'Active shell source contains unsupported case grammar inside command substitution'
                );
            }
            if ($character === '`') {
                throw new \RuntimeException(
                    'Active shell source contains unsupported legacy backtick command substitution'
                );
            }
            if ($character === '$' && ($line[$offset + 1] ?? '') === '(') {
                $substitutions[] = ['quote' => null, 'depth' => 1, 'mask' => $maskQuotedBytes];
                $visible .= $maskQuotedBytes ? '  ' : '$(';
                $maskQuotedBytes = false;
                $offset++;
                continue;
            }
            if ($substitutions !== [] && $character === '(') {
                $substitutions[array_key_last($substitutions)]['depth']++;
                $visible .= $maskQuotedBytes ? ' ' : $character;
                continue;
            }
            if ($substitutions !== [] && $character === ')') {
                $index = array_key_last($substitutions);
                $substitutions[$index]['depth']--;
                $visible .= $maskQuotedBytes ? ' ' : $character;
                if ($substitutions[$index]['depth'] === 0) {
                    $quote = $substitutions[$index]['quote'];
                    $maskQuotedBytes = $substitutions[$index]['mask'];
                    array_pop($substitutions);
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $maskQuotedBytes = $maskInlineQuotes;
                $visible .= $character;
                continue;
            }
            if ($character !== '#') {
                $visible .= $character;
                continue;
            }
            $previous = $offset === 0 ? '' : $line[$offset - 1];
            if ($offset !== 0 && !ctype_space($previous) && !str_contains(';|&()', $previous)) {
                $visible .= $character;
                continue;
            }
            $shellState = ['quote' => $quote, 'substitutions' => $substitutions];
            return [rtrim($visible), trim(substr($line, $offset + 1))];
        }
        $shellState = ['quote' => $quote, 'substitutions' => $substitutions];
        return [$visible, ''];
    }

    private static function hasShellWordAt(string $line, int $offset, string $word): bool
    {
        if (substr($line, $offset, strlen($word)) !== $word) {
            return false;
        }
        $before = $offset === 0 ? '' : $line[$offset - 1];
        $after = $line[$offset + strlen($word)] ?? '';
        return ($before === '' || preg_match('/[A-Za-z0-9_]/', $before) !== 1)
            && ($after === '' || preg_match('/[A-Za-z0-9_]/', $after) !== 1);
    }

    private static function isCaseReservedWordAt(string $line, int $offset): bool
    {
        if (!self::hasShellWordAt($line, $offset, 'case')) {
            return false;
        }
        $prefix = rtrim(substr($line, 0, $offset));
        if ($prefix === '') {
            return true;
        }
        $last = $prefix[strlen($prefix) - 1];
        if (str_contains(';|&(!', $last)) {
            return true;
        }
        if ($last === '{') {
            return !str_ends_with($prefix, '${');
        }
        return preg_match('/(?:^|[;&|(){}!])[ \t]*(?:then|do|else)[ \t]*$/D', $prefix) === 1;
    }

    /**
     * @param list<array{code:string,comment:string,line:int}> $lines
     * @return array{lines:array<int,string>,reachable:array<string,true>}
     */
    private static function functionContexts(array $lines): array
    {
        $contexts = [];
        $structuralLines = [];
        $functions = [];
        $current = null;
        $depth = 0;
        foreach ($lines as $line) {
            $state = ['quote' => null, 'substitutions' => []];
            [$structural] = self::codeAndComment($line['code'], $state, true);
            $structuralLines[$line['line']] = $structural;
            if ($current === null && preg_match(
                '/(?:^|[;&|])[ \t]*(?:function[ \t]+)?[A-Za-z_][A-Za-z0-9_]*'
                    . '(?:[ \t]*\([ \t]*\))?[ \t]*\{/',
                $structural,
                $declaration
            ) === 1 && preg_match(
                '/(?:^|[;&|])[ \t]*(?:function[ \t]+)?(?<name>[A-Za-z_][A-Za-z0-9_]*)/',
                $declaration[0],
                $name
            ) === 1) {
                $current = $name['name'];
                $functions[$current] = true;
            }
            if ($current !== null) {
                $contexts[$line['line']] = $current;
                $depth += self::structuralBraceDelta($structural);
                if ($depth < 0) {
                    throw new \RuntimeException('Active shell source has an unclassifiable function boundary');
                }
                if ($depth === 0) {
                    $current = null;
                }
            }
        }
        if ($depth !== 0) {
            throw new \RuntimeException('Active shell source has an unterminated function boundary');
        }
        // The reviewed version-matrix harness sources each capsule and invokes
        // this exact entry point (sandbox/tests/certify/certify_version_matrix.sh:207).
        $reachable = isset($functions['version_matrix_workflow'])
            ? ['version_matrix_workflow' => true]
            : [];
        $calls = [];
        foreach ($structuralLines as $lineNumber => $structural) {
            if (preg_match(
                '/(?:^|[;&|])[ \t]*(?:function[ \t]+)?[A-Za-z_][A-Za-z0-9_]*'
                    . '(?:[ \t]*\([ \t]*\))?[ \t]*\{/',
                $structural
            ) === 1) {
                continue;
            }
            $caller = $contexts[$lineNumber] ?? null;
            foreach (array_keys($functions) as $function) {
                if (preg_match(
                    '/(?:^|[;&|(){}!]|\b(?:if|then|elif|while|until|do|else|time|command|builtin)\b)'
                        . '[ \t]*(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t;&|()]+[ \t]+)*'
                        . preg_quote($function, '/') . '(?:[ \t;&|()]|$)/',
                    $structural
                ) !== 1) {
                    continue;
                }
                if ($caller === null) {
                    $reachable[$function] = true;
                } else {
                    $calls[$caller][$function] = true;
                }
            }
        }
        do {
            $added = false;
            foreach (array_keys($reachable) as $caller) {
                foreach (array_keys($calls[$caller] ?? []) as $callee) {
                    if (!isset($reachable[$callee])) {
                        $reachable[$callee] = true;
                        $added = true;
                    }
                }
            }
        } while ($added);
        return ['lines' => $contexts, 'reachable' => $reachable];
    }

    private static function structuralBraceDelta(string $code): int
    {
        $delta = 0;
        for ($offset = 0, $length = strlen($code); $offset < $length; $offset++) {
            $character = $code[$offset];
            if ($character !== '{' && $character !== '}') {
                continue;
            }
            $before = $offset === 0 ? '' : $code[$offset - 1];
            $after = $code[$offset + 1] ?? '';
            $beforeBoundary = $before === '' || ctype_space($before) || str_contains(';|&()', $before);
            $afterBoundary = $after === '' || ctype_space($after) || str_contains(';|&()', $after);
            if (!$beforeBoundary || !$afterBoundary) {
                continue;
            }
            $delta += $character === '{' ? 1 : -1;
        }
        return $delta;
    }

    /** @return list<array{delimiter:string,tabs:bool}> */
    private static function heredocDeclarations(string $code): array
    {
        $declarations = [];
        $quote = null;
        $escaped = false;
        $arithmeticDepth = 0;
        for ($offset = 0, $length = strlen($code); $offset < $length; $offset++) {
            $character = $code[$offset];
            if ($arithmeticDepth > 0) {
                if ($character === '(') {
                    $arithmeticDepth++;
                } elseif ($character === ')') {
                    $arithmeticDepth--;
                }
                continue;
            }
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== "'" && $character === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '$'
                && ($code[$offset + 1] ?? '') === '('
                && ($code[$offset + 2] ?? '') === '(') {
                $arithmeticDepth = 2;
                $offset += 2;
                continue;
            }
            if ($character === '('
                && ($code[$offset + 1] ?? '') === '('
                && ($offset === 0 || preg_match('/[\s;&|({]/', $code[$offset - 1]) === 1)) {
                $arithmeticDepth = 2;
                $offset++;
                continue;
            }
            if ($character !== '<'
                || ($code[$offset + 1] ?? '') !== '<'
                || ($offset > 0 && $code[$offset - 1] === '<')
                || ($code[$offset + 2] ?? '') === '<') {
                continue;
            }

            $cursor = $offset + 2;
            $tabs = ($code[$cursor] ?? '') === '-';
            if ($tabs) {
                $cursor++;
            }
            while (isset($code[$cursor]) && ($code[$cursor] === ' ' || $code[$cursor] === "\t")) {
                $cursor++;
            }
            if (($code[$cursor] ?? '') === '\\') {
                throw new \RuntimeException(
                    'Active shell source contains an unsupported escaped heredoc delimiter'
                );
            }
            $delimiterQuote = null;
            if (($code[$cursor] ?? '') === "'" || ($code[$cursor] ?? '') === '"') {
                $delimiterQuote = $code[$cursor++];
            }
            $delimiter = '';
            while (isset($code[$cursor])
                && preg_match('/^[A-Za-z0-9_]$/D', $code[$cursor]) === 1) {
                $delimiter .= $code[$cursor++];
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $delimiter) !== 1
                || ($delimiterQuote !== null && ($code[$cursor] ?? '') !== $delimiterQuote)) {
                throw new \RuntimeException('Active shell source contains unsupported heredoc syntax');
            }
            if ($delimiterQuote !== null) {
                $cursor++;
            }
            $next = $code[$cursor] ?? '';
            if ($next !== '' && preg_match('/[\s;&|<>)]/', $next) !== 1) {
                throw new \RuntimeException('Active shell source contains unsupported heredoc syntax');
            }
            $declarations[] = ['delimiter' => $delimiter, 'tabs' => $tabs];
            $offset = $cursor - 1;
        }
        return $declarations;
    }
}
