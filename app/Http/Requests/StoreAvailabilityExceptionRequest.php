<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAvailabilityExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff instanceof User
            && ($this->user()?->can('manageSchedule', $staff) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'is_available' => ['required', 'boolean'],
            // Both null means the whole day; supplying one means supplying both.
            'start_time' => ['nullable', 'required_with:end_time', 'date_format:H:i,H:i:s'],
            'end_time' => ['nullable', 'required_with:start_time', 'date_format:H:i,H:i:s'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_time.required_with' => 'Give both a start and an end time, or neither for a whole day.',
            'end_time.required_with' => 'Give both a start and an end time, or neither for a whole day.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // A one-off *working* window has to say when; only blocks may be
            // whole-day, because "available all day" has no rules to draw on.
            if ($this->boolean('is_available') && ! $this->filled('start_time')) {
                $validator->errors()->add(
                    'start_time',
                    'An extra availability window needs a start and end time.',
                );
            }
        });
    }
}
