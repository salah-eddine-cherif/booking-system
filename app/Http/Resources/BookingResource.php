<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // UTC instants are always present; the *_local pair is the same moment
        // rendered in the zone the customer booked in.
        $timezone = $this->customer_timezone;

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status->value,
            'payment_status_label' => $this->payment_status->label(),
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'starts_at_local' => $this->localStartsAt()->toIso8601String(),
            'ends_at_local' => $this->localEndsAt()->toIso8601String(),
            'timezone' => $timezone,
            'duration_minutes' => $this->duration_minutes,
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->currency,
                'formatted' => Money::format($this->price_amount, $this->currency),
            ],
            'deposit' => [
                'amount' => $this->deposit_amount,
                'formatted' => Money::format($this->deposit_amount, $this->currency),
            ],
            'customer_notes' => $this->customer_notes,
            'staff_notes' => $this->when($request->user() !== null, $this->staff_notes),
            'service' => new ServiceResource($this->whenLoaded('service')),
            'staff' => new StaffResource($this->whenLoaded('staff')),
            'customer' => $this->whenLoaded('customer', fn () => [
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
            ]),
        ];
    }
}
