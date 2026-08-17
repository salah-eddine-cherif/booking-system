<?php

namespace App\Http\Resources;

use App\Models\Service;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->currency,
                'formatted' => Money::format($this->price_amount, $this->currency),
            ],
            'deposit' => [
                'required' => $this->requiresDeposit(),
                'type' => $this->deposit_type->value,
                'amount' => $this->depositAmount(),
                'formatted' => Money::format($this->depositAmount(), $this->currency),
            ],
            'booking_window' => [
                'slot_increment_minutes' => $this->slot_increment_minutes,
                'min_notice_minutes' => $this->min_notice_minutes,
                'max_advance_days' => $this->max_advance_days,
                'cancellation_notice_minutes' => $this->cancellation_notice_minutes,
            ],
            'buffers' => [
                'before_minutes' => $this->buffer_before_minutes,
                'after_minutes' => $this->buffer_after_minutes,
            ],
            'staff' => StaffResource::collection($this->whenLoaded('staff')),
        ];
    }
}
