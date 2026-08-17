<?php

namespace App\Http\Requests;

class UpdateServiceRequest extends StoreServiceRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('service')) ?? false;
    }

    /**
     * Same shape as creation, but every field is optional on a PATCH.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules) => array_values(array_map(
                fn ($rule) => $rule === 'required' ? 'sometimes' : $rule,
                $rules,
            )))
            ->all();
    }
}
