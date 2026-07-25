<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A single row was too large to send to the target server given its current
 * max_allowed_packet, even after TableCopier halved the insert down to one
 * row. Carries enough detail for the UI to explain the problem in plain
 * language and offer raising the limit instead of surfacing MySQL's raw
 * "Got a packet bigger than..." error.
 */
class PacketTooLargeException extends RuntimeException
{
    public readonly int $suggestedMaxAllowedPacket;

    public function __construct(
        public readonly int $currentMaxAllowedPacket,
        public readonly int $neededAtLeast,
        ?Throwable $previous = null,
    ) {
        // Round up to a comfortably round step so the value is easy to
        // reason about (and to type into a config file) later: 16M, 32M, ...
        $step = 16 * 1024 * 1024;
        $target = max($this->currentMaxAllowedPacket * 2, (int) ($this->neededAtLeast * 1.5));
        $this->suggestedMaxAllowedPacket = (int) (ceil($target / $step) * $step);

        parent::__construct(sprintf(
            'A row is too large to send (needs at least %s, but the target server only allows %s per packet).',
            self::human($this->neededAtLeast),
            self::human($this->currentMaxAllowedPacket),
        ), previous: $previous);
    }

    public static function human(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1).' MB';
    }
}
