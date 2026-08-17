<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Queued by bookings:send-reminders ahead of the appointment. */
class BookingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Booking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['service', 'staff']);
        $starts = $booking->localStartsAt();

        return (new MailMessage)
            ->subject("Reminder: {$booking->service->name} on ".$starts->format('j M').' at '.$starts->format('H:i'))
            ->greeting("Hi {$notifiable->name},")
            ->line("This is a reminder about your {$booking->service->name} appointment with {$booking->staff->name}.")
            ->line('**When:** '.$starts->format('l j F Y, H:i')." ({$booking->customer_timezone})")
            ->line("**Reference:** {$booking->reference}")
            ->line('If you can no longer make it, please let us know as soon as you can.');
    }
}
