<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification implements ShouldQueue
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
        $booking = $this->booking->loadMissing('service');
        $starts = $booking->localStartsAt();

        $message = (new MailMessage)
            ->subject("Booking cancelled — {$booking->reference}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your {$booking->service->name} appointment on ".$starts->format('l j F Y, H:i').' has been cancelled.');

        if ($booking->cancellation_reason) {
            $message->line("**Reason:** {$booking->cancellation_reason}");
        }

        return $message->line('You are welcome to book another time whenever suits you.');
    }
}
