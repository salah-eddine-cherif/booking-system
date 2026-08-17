<?php

namespace App\Models;

use Database\Factories\AvailabilityRuleFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recurring weekly window of availability, expressed in the staff member's
 * local wall-clock time (see the migration for why it is not stored as UTC).
 *
 * @property int $day_of_week 0 = Sunday .. 6 = Saturday
 * @property string $start_time "HH:MM:SS"
 * @property string $end_time "HH:MM:SS"
 */
#[Fillable(['user_id', 'day_of_week', 'start_time', 'end_time', 'effective_from', 'effective_until'])]
class AvailabilityRule extends Model
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Does this rule produce availability on the given local date?
     */
    public function appliesOn(DateTimeInterface $date): bool
    {
        if ((int) $date->format('w') !== $this->day_of_week) {
            return false;
        }

        $day = $date->format('Y-m-d');

        if ($this->effective_from && $day < $this->effective_from->format('Y-m-d')) {
            return false;
        }

        if ($this->effective_until && $day > $this->effective_until->format('Y-m-d')) {
            return false;
        }

        return true;
    }
}
