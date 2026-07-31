<?php

namespace App\Support;

class ConnectionColor
{
    /** @var string[] */
    private const PALETTE = [
        '#0ea5e9',
        '#8b5cf6',
        '#10b981',
        '#f59e0b',
        '#ef4444',
        '#ec4899',
        '#14b8a6',
        '#6366f1',
    ];

    public static function resolve(?string $color, string $name = ''): string
    {
        if (is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }

        if ($name === '') {
            return self::PALETTE[0];
        }

        return self::PALETTE[crc32($name) % count(self::PALETTE)];
    }
}
