<?php

namespace App\Services\Booking;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Everything needed to attempt a booking, resolved from the request before the
 * transaction opens.
 */
final readonly class PendingBooking
{
    public function __construct(
        public Service $service,
        public User $staff,
        public Customer $customer,
        public CarbonImmutable $startsAt,
        public string $customerTimezone = 'UTC',
        public ?string $notes = null,
    ) {}

    /**
     * The price this customer pays, honouring any per-staff override.
     */
    public function priceAmount(): int
    {
        return $this->staff->priceFor($this->service);
    }

    public function depositAmount(): int
    {
        return $this->service->depositAmount($this->priceAmount());
    }
}
