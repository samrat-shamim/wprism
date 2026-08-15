<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

final class DeterministicArchive
{
    /**
     * @param list<array{path:string,bytes:string,mode:int}> $files
     */
    public static function encode(array $files): string
    {
        usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        $archive = '';
        $seen = [];
        foreach ($files as $file) {
            $path = $file['path'];
            if (isset($seen[$path]) || !self::validPath($path)) {
                throw new CatalogException("invalid or duplicate archive path: $path");
            }
            $seen[$path] = true;
            [$name, $prefix] = self::splitPath($path);
            $header = str_repeat("\0", 512);
            self::field($header, 0, 100, $name);
            self::field($header, 100, 8, sprintf('%07o', $file['mode']) . "\0");
            self::field($header, 108, 8, "0000000\0");
            self::field($header, 116, 8, "0000000\0");
            self::field($header, 124, 12, sprintf('%011o', strlen($file['bytes'])) . "\0");
            self::field($header, 136, 12, "00000000000\0");
            self::field($header, 148, 8, '        ');
            self::field($header, 156, 1, '0');
            self::field($header, 257, 6, "ustar\0");
            self::field($header, 263, 2, '00');
            self::field($header, 329, 8, "0000000\0");
            self::field($header, 337, 8, "0000000\0");
            self::field($header, 345, 155, $prefix);
            $octets = unpack('C*', $header);
            if (!is_array($octets)) {
                throw new CatalogException('cannot calculate archive header checksum');
            }
            $sum = array_sum($octets);
            self::field($header, 148, 8, sprintf('%06o', $sum) . "\0 ");
            $archive .= $header . $file['bytes'];
            $remainder = strlen($file['bytes']) % 512;
            if ($remainder !== 0) {
                $archive .= str_repeat("\0", 512 - $remainder);
            }
        }
        return $archive . str_repeat("\0", 1024);
    }

    /** @return list<array{path:string,bytes:string,mode:int}> */
    public static function decode(string $archive): array
    {
        if (strlen($archive) < 1024 || strlen($archive) % 512 !== 0) {
            throw new CatalogException('archive is truncated or misaligned');
        }
        $files = [];
        $offset = 0;
        $length = strlen($archive);
        while ($offset + 1024 <= $length) {
            $header = substr($archive, $offset, 512);
            if ($header === str_repeat("\0", 512)) {
                if (substr($archive, $offset) !== str_repeat("\0", $length - $offset)) {
                    throw new CatalogException('archive has data after its terminal blocks');
                }
                break;
            }
            if (substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00') {
                throw new CatalogException('archive member is not normalized ustar');
            }
            $expectedChecksum = self::parseOctal(substr($header, 148, 8), 'checksum');
            $checksumHeader = substr_replace($header, '        ', 148, 8);
            $octets = unpack('C*', $checksumHeader);
            if (!is_array($octets) || array_sum($octets) !== $expectedChecksum) {
                throw new CatalogException('archive member checksum is invalid');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $path = $prefix === '' ? $name : $prefix . '/' . $name;
            $mode = self::parseOctal(substr($header, 100, 8), 'mode');
            $size = self::parseOctal(substr($header, 124, 12), 'size');
            if (!self::validPath($path)
                || !in_array(substr($header, 156, 1), ["\0", '0'], true)
                || substr($header, 108, 8) !== "0000000\0"
                || substr($header, 116, 8) !== "0000000\0"
                || substr($header, 136, 12) !== "00000000000\0") {
                throw new CatalogException('archive member metadata is unsafe or not normalized');
            }
            $dataOffset = $offset + 512;
            if ($size < 0 || $dataOffset + $size > $length) {
                throw new CatalogException('archive member data is truncated');
            }
            $files[] = ['path' => $path, 'bytes' => substr($archive, $dataOffset, $size), 'mode' => $mode];
            $offset = $dataOffset + (int) (ceil($size / 512) * 512);
        }
        if ($files === [] || self::encode($files) !== $archive) {
            throw new CatalogException('archive bytes are not the deterministic canonical encoding');
        }
        return $files;
    }

    private static function parseOctal(string $field, string $label): int
    {
        $normalized = rtrim($field, "\0 ");
        if ($normalized === '' || preg_match('/^[0-7]+$/D', $normalized) !== 1) {
            throw new CatalogException("archive $label is malformed");
        }
        return intval($normalized, 8);
    }

    private static function validPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 255
            && !str_starts_with($path, '/')
            && !str_contains($path, "\0")
            && preg_match('~(?:^|/)\.\.(?:/|$)~D', $path) !== 1;
    }

    /** @return array{string,string} */
    private static function splitPath(string $path): array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }
        for ($position = strlen($path) - 1; $position > 0; --$position) {
            if ($path[$position] !== '/') {
                continue;
            }
            $prefix = substr($path, 0, $position);
            $name = substr($path, $position + 1);
            if (strlen($prefix) <= 155 && strlen($name) <= 100) {
                return [$name, $prefix];
            }
        }
        throw new CatalogException("archive path cannot be represented by ustar: $path");
    }

    private static function field(string &$header, int $offset, int $length, string $value): void
    {
        if (strlen($value) > $length) {
            throw new CatalogException('archive header field overflow');
        }
        $header = substr_replace($header, str_pad($value, $length, "\0"), $offset, $length);
    }
}
