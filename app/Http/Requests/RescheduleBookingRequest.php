<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'string', 'date'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->input('starts_at'),
            $this->input('timezone') ?: 'UTC',
        )->utc();
    }
}
