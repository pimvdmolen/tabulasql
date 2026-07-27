<?php

namespace App\Support;

/**
 * Human-readable byte sizes without the intl PHP extension.
 *
 * Laravel's Number::fileSize() requires intl, which the Windows static PHP
 * build historically omitted; this keeps the grid/record UI working there.
 */
final class Bytes
{
    /**
     * @param  int|float  $bytes
     */
    public static function format(int|float $bytes, int $precision = 0): string
    {
        $bytes = max(0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $precision).' '.$units[$power];
    }
}
