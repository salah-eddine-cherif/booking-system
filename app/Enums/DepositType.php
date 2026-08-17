<?php

namespace App\Enums;

enum DepositType: string
{
    case None = 'none';

    /** A flat amount, stored in the smallest currency unit. */
    case Fixed = 'fixed';

    /** A percentage of the service price. */
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No deposit',
            self::Fixed => 'Fixed amount',
            self::Percentage => 'Percentage of price',
        };
    }

    /**
     * Resolve the deposit due, in the smallest currency unit.
     *
     * @param  int  $depositValue  Minor units when Fixed, whole percent when Percentage.
     * @param  int  $priceAmount  Full service price in minor units.
     */
    public function resolveAmount(int $depositValue, int $priceAmount): int
    {
        $amount = match ($this) {
            self::None => 0,
            self::Fixed => $depositValue,
            self::Percentage => (int) round($priceAmount * $depositValue / 100),
        };

        // Never ask for more than the service actually costs.
        return max(0, min($amount, $priceAmount));
    }
}
