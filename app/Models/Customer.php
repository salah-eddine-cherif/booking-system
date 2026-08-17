<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

/**
 * The person making the booking. Billable so deposits can be charged against a
 * durable Stripe customer rather than a one-off guest charge.
 */
#[Fillable(['name', 'email', 'phone', 'timezone', 'notes', 'user_id'])]
class Customer extends Model
{
    use Billable;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The optional login account attached to this customer.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }

    public function stripeEmail(): ?string
    {
        return $this->email;
    }

    public function stripePhone(): ?string
    {
        return $this->phone;
    }

    /**
     * Look up an existing customer by email or create one, refreshing the details
     * they supplied this time round.
     *
     * @param  array{name: string, email: string, phone?: string|null, timezone?: string|null}  $attributes
     */
    public static function findOrCreateByEmail(array $attributes): self
    {
        $customer = static::firstOrNew(['email' => $attributes['email']]);

        $customer->fill(array_filter([
            'name' => $attributes['name'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'timezone' => $attributes['timezone'] ?? null,
        ], fn ($value) => $value !== null));

        $customer->email = $attributes['email'];
        $customer->save();

        return $customer;
    }
}
