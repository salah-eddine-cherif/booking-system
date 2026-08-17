<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent while the slot is held and the deposit is still outstanding. */
class DepositRequestedNotification extends Notification implements ShouldQueue
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
        $amount = number_format($booking->deposit_amount / 100, 2);
        $currency = strtoupper($booking->currency);

        return (new MailMessage)
            ->subject("We're holding your slot — {$booking->reference}")
            ->greeting("Hi {$notifiable->name},")
            ->line("We've held your {$booking->service->name} slot on ".$starts->format('l j F Y, H:i').' ('.$booking->customer_timezone.').')
            ->line("To confirm it, please pay the {$currency} {$amount} deposit.")
            ->line('The hold expires '.$booking->hold_expires_at?->setTimezone($booking->customer_timezone)->format('H:i').', after which the slot goes back on sale.')
            ->line("**Reference:** {$booking->reference}");
    }
}
