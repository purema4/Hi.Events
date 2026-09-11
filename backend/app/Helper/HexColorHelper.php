<?php

declare(strict_types=1);

namespace HiEvents\Helper;

class HexColorHelper
{
    public static function toRgbHex(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = ltrim(trim($value), '#');

        if (! preg_match('/^[0-9a-fA-F]{3,8}$/', $candidate)) {
            return null;
        }

        $rgb = match (strlen($candidate)) {
            3, 4 => preg_replace('/(.)/', '$1$1', substr($candidate, 0, 3)),
            6, 8 => substr($candidate, 0, 6),
            default => null,
        };

        return $rgb === null ? null : '#'.strtolower($rgb);
    }
}
