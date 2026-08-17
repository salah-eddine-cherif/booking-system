<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Notifications\BookingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Sends each confirmed booking its reminder once it enters the service's lead
 * window. Runs often; `reminder_sent_at` is what makes it idempotent, so a
 * double run — or an overlapping one — cannot double-send.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Queue reminder emails for upcoming confirmed bookings';

    public function handle(): int
    {
        $now = CarbonImmutable::now();

        // Reminder lead time varies per service, so it cannot be a single SQL
        // predicate. Narrow to the widest lead time in use, then decide per row.
        $widestLead = (int) Service::max('reminder_hours_before');

        $sent = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours($widestLead))
            ->with(['service', 'customer', 'staff'])
            ->chunkById(200, function (Collection $bookings) use ($now, &$sent) {
                foreach ($bookings as $booking) {
                    /** @var Booking $booking */
                    $dueAt = $booking->starts_at->subHours($booking->service->reminder_hours_before);

                    if ($dueAt->isAfter($now)) {
                        continue;
                    }

                    $booking->customer->notify(new BookingReminderNotification($booking));

                    // Stamped immediately, before the queued mail is delivered: the
                    // stamp guards against re-queueing, not against delivery failure.
                    $booking->forceFill(['reminder_sent_at' => $now])->save();

                    $sent++;
                }
            });

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
