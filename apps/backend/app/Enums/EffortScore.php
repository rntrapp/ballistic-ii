<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fibonacci-scale effort scoring for items.
 *
 * Backing values are the integer points used by the velocity forecaster.
 * Defaults to Trivial (1 point) when unassigned, matching the database
 * column default.
 */
enum EffortScore: int
{
    case Trivial = 1;
    case Minor = 2;
    case Moderate = 3;
    case Major = 5;
    case Epic = 8;

    /**
     * The value stored when a user does not explicitly score an item.
     */
    public static function default(): self
    {
        return self::Trivial;
    }

    /**
     * All valid integer backing values, ordered ascending.
     *
     * @return list<int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Largest legal effort score — used for clamping and chart scaling.
     */
    public static function max(): int
    {
        $values = self::values();

        return $values[array_key_last($values)];
    }
}
