<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'bio' => $this->bio,
            'timezone' => $this->timezone,
            'price' => $this->whenPivotLoaded('service_user', fn () => $this->pivot->price_amount),
        ];
    }
}
