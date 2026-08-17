<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingConfirmedNotification extends Notification implements ShouldQueue
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

        // Always speak to the customer in the timezone they booked in, never the
        // server's and never the staff member's.
        $starts = $booking->localStartsAt();

        return (new MailMessage)
            ->subject("Your booking is confirmed — {$booking->reference}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your {$booking->service->name} appointment with {$booking->staff->name} is confirmed.")
            ->line('**When:** '.$starts->format('l j F Y, H:i').' ('.$booking->customer_timezone.')')
            ->line("**Duration:** {$booking->duration_minutes} minutes")
            ->line("**Reference:** {$booking->reference}")
            ->line('Keep the reference handy — you will need it to change or cancel this appointment.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'reference' => $this->booking->reference,
            'starts_at' => $this->booking->starts_at->toIso8601String(),
        ];
    }
}
