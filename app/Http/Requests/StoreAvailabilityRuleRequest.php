<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreAvailabilityRuleRequest extends FormRequest
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
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i,H:i:s'],
            // Equal to or before the start means an overnight shift, so no
            // after:start_time rule here — see AvailabilityCalculator::localWindow.
            'end_time' => ['required', 'date_format:H:i,H:i:s'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ];
    }
}
