<?php

namespace App\Http\Resources;

use App\Models\AvailabilityException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvailabilityException */
class AvailabilityExceptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'date' => $this->date->toDateString(),
            'is_available' => $this->is_available,
            'whole_day' => $this->coversWholeDay(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'reason' => $this->reason,
        ];
    }
}
