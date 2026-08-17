<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the staff member their own calendar changed. */
class StaffBookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $change = 'booked',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['service', 'customer']);

        // Staff read their calendar in their own zone, not the customer's.
        $starts = $booking->starts_at->setTimezone($notifiable->timezone);

        $verb = $this->change === 'cancelled' ? 'cancelled' : 'booked';

        return (new MailMessage)
            ->subject(ucfirst($verb).": {$booking->service->name} — ".$starts->format('j M, H:i'))
            ->greeting("Hi {$notifiable->name},")
            ->line("{$booking->customer->name} {$verb} {$booking->service->name}.")
            ->line('**When:** '.$starts->format('l j F Y, H:i')." ({$notifiable->timezone})")
            ->line("**Reference:** {$booking->reference}")
            ->lineIf((bool) $booking->customer_notes, "**Customer notes:** {$booking->customer_notes}");
    }
}
