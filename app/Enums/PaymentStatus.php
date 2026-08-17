<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** No deposit is required for this booking. */
    case NotRequired = 'not_required';

    /** A PaymentIntent exists but has not succeeded yet. */
    case Pending = 'pending';

    /** Stripe requires 3DS / additional customer action. */
    case RequiresAction = 'requires_action';

    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'No deposit required',
            self::Pending => 'Payment pending',
            self::RequiresAction => 'Additional authentication required',
            self::Paid => 'Deposit paid',
            self::Failed => 'Payment failed',
            self::Refunded => 'Refunded',
        };
    }

    /** Whether the booking is financially settled enough to be confirmed. */
    public function isSettled(): bool
    {
        return in_array($this, [self::NotRequired, self::Paid], true);
    }
}
