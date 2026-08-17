<?php

namespace App\Http\Requests;

use App\Support\TimeRange;
use App\Support\WallClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `from` and `to` are calendar dates in the *caller's* timezone, not instants.
 * "Show me Tuesday" means Tuesday where the customer is standing.
 */
class AvailabilityRequest extends FormRequest
{
    /** How many days of availability may be asked for in one request. */
    private const MAX_RANGE_DAYS = 62;

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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from',
                'before_or_equal:'.CarbonImmutable::parse($this->input('from', 'today'))
                    ->addDays(self::MAX_RANGE_DAYS)
                    ->format('Y-m-d'),
            ],
            'timezone' => ['nullable', 'string', 'timezone'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.before_or_equal' => 'Availability can only be requested '.self::MAX_RANGE_DAYS.' days at a time.',
        ];
    }

    public function timezone(): string
    {
        return $this->input('timezone') ?: 'UTC';
    }

    /**
     * The requested dates as a UTC instant range, inclusive of the whole `to` day.
     */
    public function window(): TimeRange
    {
        $timezone = $this->timezone();

        return new TimeRange(
            WallClock::wholeDay($this->input('from'), $timezone)->start,
            WallClock::wholeDay($this->input('to'), $timezone)->end,
        );
    }

    public function staffId(): ?int
    {
        return $this->filled('staff_id') ? (int) $this->input('staff_id') : null;
    }
}
