<?php

namespace App\Models;

use App\Enums\DepositType;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bookable offering: the duration, buffers, pricing and booking-window rules
 * that shape every appointment made against it.
 */
#[RouteKey('slug')]
#[Fillable([
    'name', 'slug', 'description', 'duration_minutes', 'buffer_before_minutes',
    'buffer_after_minutes', 'slot_increment_minutes', 'min_notice_minutes',
    'max_advance_days', 'capacity', 'price_amount', 'currency', 'deposit_type',
    'deposit_value', 'payment_hold_minutes', 'reminder_hours_before',
    'cancellation_notice_minutes', 'is_active', 'sort_order',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults, so a freshly created model behaves the same as
     * one read back from the database without needing a refresh().
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'slot_increment_minutes' => 15,
        'min_notice_minutes' => 120,
        'max_advance_days' => 60,
        'capacity' => 1,
        'price_amount' => 0,
        'currency' => 'usd',
        'deposit_type' => DepositType::None->value,
        'deposit_value' => 0,
        'payment_hold_minutes' => 15,
        'reminder_hours_before' => 24,
        'cancellation_notice_minutes' => 1440,
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'slot_increment_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_advance_days' => 'integer',
            'capacity' => 'integer',
            'price_amount' => 'integer',
            'deposit_type' => DepositType::class,
            'deposit_value' => 'integer',
            'payment_hold_minutes' => 'integer',
            'reminder_hours_before' => 'integer',
            'cancellation_notice_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('price_amount')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @param  Builder<Service>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Total minutes this service occupies on the calendar, buffers included.
     */
    public function blockedDurationMinutes(): int
    {
        return $this->buffer_before_minutes
            + $this->duration_minutes
            + $this->buffer_after_minutes;
    }

    /**
     * The deposit due up front, in minor currency units.
     */
    public function depositAmount(?int $priceAmount = null): int
    {
        return $this->deposit_type->resolveAmount(
            $this->deposit_value,
            $priceAmount ?? $this->price_amount,
        );
    }

    public function requiresDeposit(): bool
    {
        return $this->depositAmount() > 0;
    }
}
