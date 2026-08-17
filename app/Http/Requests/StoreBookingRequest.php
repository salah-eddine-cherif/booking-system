<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'exists:services,slug'],
            'staff_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'string', 'date'],
            'timezone' => ['nullable', 'string', 'timezone'],

            'customer.name' => ['required', 'string', 'max:255'],
            'customer.email' => ['required', 'email:rfc', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:32'],

            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function timezone(): string
    {
        return $this->input('timezone') ?: 'UTC';
    }

    /**
     * The requested start as an absolute instant.
     *
     * A value carrying its own offset ("2026-03-02T09:00:00+01:00") is already
     * unambiguous and is taken as-is. A bare local time is interpreted in the
     * caller's timezone — which is why `timezone` matters even though the API
     * accepts ISO 8601.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->input('starts_at'), $this->timezone())->utc();
    }

    /**
     * @return array{name: string, email: string, phone: string|null, timezone: string}
     */
    public function customerAttributes(): array
    {
        return [
            'name' => $this->input('customer.name'),
            'email' => $this->input('customer.email'),
            'phone' => $this->input('customer.phone'),
            'timezone' => $this->timezone(),
        ];
    }
}
