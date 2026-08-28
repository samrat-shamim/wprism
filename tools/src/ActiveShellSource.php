<?php

declare(strict_types=1);

namespace Duo\Tooling;

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
        $lines = preg_split('/\R/', $source) ?: [];
        $active = [];
        $heredocs = [];
        foreach ($lines as $offset => $line) {
            if ($heredocs !== []) {
                $expected = $heredocs[0];
                $candidate = $expected['tabs'] ? ltrim($line, "\t") : $line;
                if ($candidate === $expected['delimiter']) {
                    array_shift($heredocs);
                }
                continue;
            }

            [$code, $comment] = self::codeAndComment($line);
            $active[] = ['code' => $code, 'comment' => $comment, 'line' => $offset + 1];
            foreach (self::heredocDeclarations($code) as $declaration) {
                $heredocs[] = $declaration;
            }
        }
        return $active;
    }

    /** @return array{0:string,1:string} */
    private static function codeAndComment(string $line): array
    {
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = strlen($line); $offset < $length; $offset++) {
            $character = $line[$offset];
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
            if ($character !== '#') {
                continue;
            }
            $previous = $offset === 0 ? '' : $line[$offset - 1];
            if ($offset !== 0 && !ctype_space($previous) && !str_contains(';|&()', $previous)) {
                continue;
            }
            return [rtrim(substr($line, 0, $offset)), trim(substr($line, $offset + 1))];
        }
        return [$line, ''];
    }

    /** @return list<array{delimiter:string,tabs:bool}> */
    private static function heredocDeclarations(string $code): array
    {
        $declarations = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = strlen($code); $offset < $length; $offset++) {
            $character = $code[$offset];
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
                continue;
            }
            $declarations[] = ['delimiter' => $delimiter, 'tabs' => $tabs];
            $offset = $cursor;
        }
        return $declarations;
    }
}
