<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Guests have no session, so the booking reference alone must not be enough to
 * cancel: the matching email address is the second factor.
 */
class CancelBookingRequest extends FormRequest
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
            'email' => ['required', 'email:rfc'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
