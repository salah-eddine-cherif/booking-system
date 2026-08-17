<?php

namespace App\Http\Requests;

use App\Enums\DepositType;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name') && ! $this->filled('slug')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $service = $this->route('service');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('services', 'slug')->ignore($service)],
            'description' => ['nullable', 'string', 'max:2000'],

            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'slot_increment_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],

            'min_notice_minutes' => ['nullable', 'integer', 'min:0'],
            'max_advance_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'cancellation_notice_minutes' => ['nullable', 'integer', 'min:0'],

            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],

            'price_amount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'deposit_type' => ['nullable', Rule::enum(DepositType::class)],
            // A percentage deposit is a whole percent; a fixed one is minor units.
            'deposit_value' => ['nullable', 'integer', 'min:0', Rule::when(
                $this->input('deposit_type') === DepositType::Percentage->value,
                ['max:100'],
            )],
            'payment_hold_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'reminder_hours_before' => ['nullable', 'integer', 'min:0', 'max:336'],

            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
