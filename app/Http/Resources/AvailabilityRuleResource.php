<?php

namespace App\Http\Resources;

use App\Models\AvailabilityRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AvailabilityRule */
class AvailabilityRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'day_of_week' => $this->day_of_week,
            'day' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$this->day_of_week],
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
        ];
    }
}
