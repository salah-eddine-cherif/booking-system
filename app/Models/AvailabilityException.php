<?php

namespace App\Models;

use Database\Factories\AvailabilityExceptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date-specific override: a day off, a long lunch, or a one-off extra shift.
 *
 * Null start_time/end_time means the row covers the whole day.
 */
#[Fillable(['user_id', 'date', 'is_available', 'start_time', 'end_time', 'reason'])]
class AvailabilityException extends Model
{
    /** @use HasFactory<AvailabilityExceptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_available' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coversWholeDay(): bool
    {
        return $this->start_time === null || $this->end_time === null;
    }
}
