<?php

namespace App\Notifications;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRescheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
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
        $booking = $this->booking->loadMissing('service');
        $timezone = $booking->customer_timezone;

        return (new MailMessage)
            ->subject("Your appointment has moved — {$booking->reference}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Your {$booking->service->name} appointment has been rescheduled.")
            ->line('**Was:** '.$this->previousStartsAt->setTimezone($timezone)->format('l j F Y, H:i'))
            ->line('**Now:** '.$booking->localStartsAt()->format('l j F Y, H:i')." ({$timezone})")
            ->line("**Reference:** {$booking->reference}");
    }
}
