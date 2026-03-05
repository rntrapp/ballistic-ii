<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fibonacci-scale effort estimate for an item.
 *
 * Backed integer enum — single source of truth for valid scores. Validation,
 * factories, and the frontend picker all derive their option set from here.
 */
enum EffortScore: int
{
    case Trivial = 1;
    case Small = 2;
    case Medium = 3;
    case Large = 5;
    case ExtraLarge = 8;

    public static function default(): self
    {
        return self::Trivial;
    }

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
