<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * A staff member (or admin). Bookings hang off this model's calendar.
 *
 * @property string $timezone IANA identifier, e.g. "Europe/Lisbon".
 */
#[Fillable(['name', 'email', 'password', 'role', 'timezone', 'title', 'bio', 'is_bookable'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_bookable' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)
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
     * @return HasMany<AvailabilityRule, $this>
     */
    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    /**
     * @return HasMany<AvailabilityException, $this>
     */
    public function availabilityExceptions(): HasMany
    {
        return $this->hasMany(AvailabilityException::class);
    }

    /**
     * Staff members who can actually be booked.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function bookable(Builder $query): void
    {
        $query->where('is_bookable', true);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * Admins see every booking; staff see only their own.
     */
    public function canManageBooking(Booking $booking): bool
    {
        return $this->isAdmin() || $booking->user_id === $this->id;
    }

    /**
     * The per-staff price for a service, falling back to the service's own price.
     * Returns minor currency units.
     */
    public function priceFor(Service $service): int
    {
        $pivot = $this->loadMissing('services')
            ->services
            ->firstWhere('id', $service->id)
            ?->pivot;

        return $pivot?->price_amount ?? $service->price_amount;
    }

    /**
     * Weekly rules that apply on the given local date, in wall-clock order.
     *
     * @return Collection<int, AvailabilityRule>
     */
    public function rulesForDate(\DateTimeInterface $date): Collection
    {
        return $this->availabilityRules
            ->filter(fn (AvailabilityRule $rule) => $rule->appliesOn($date))
            ->sortBy('start_time')
            ->values();
    }
}
