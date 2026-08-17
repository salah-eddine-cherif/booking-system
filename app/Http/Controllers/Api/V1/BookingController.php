<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingService;
use App\Services\Booking\PendingBooking;
use App\Services\Payments\DepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer-facing booking flow. No authentication: a booking is identified
 * by its reference, and proved by the email address that made it.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly DepositService $deposits,
    ) {}

    /**
     * POST /api/v1/bookings
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $service = Service::active()->where('slug', $request->input('service'))->firstOrFail();
        $staff = User::bookable()->findOrFail($request->integer('staff_id'));
        $customer = Customer::findOrCreateByEmail($request->customerAttributes());

        $booking = $this->bookings->book(new PendingBooking(
            service: $service,
            staff: $staff,
            customer: $customer,
            startsAt: $request->startsAt(),
            customerTimezone: $request->timezone(),
            notes: $request->input('notes'),
        ));

        $booking->load(['service', 'staff', 'customer']);

        return response()->json([
            'data' => new BookingResource($booking),
            // Present only when a deposit is owed; the browser confirms the
            // PaymentIntent with this and the webhook does the rest.
            'payment' => $booking->deposit_amount > 0 ? [
                'client_secret' => $this->deposits->clientSecretFor($booking),
                'amount' => $booking->deposit_amount,
                'currency' => $booking->currency,
                'hold_expires_at' => $booking->hold_expires_at?->toIso8601String(),
            ] : null,
        ], 201);
    }

    /**
     * GET /api/v1/bookings/{reference}?email=…
     */
    public function show(Request $request, Booking $booking): JsonResponse
    {
        $booking->load(['service', 'staff', 'customer']);

        $this->assertOwnedByEmail($request, $booking);

        return response()->json(['data' => new BookingResource($booking)]);
    }

    /**
     * POST /api/v1/bookings/{reference}/cancel
     */
    public function cancel(CancelBookingRequest $request, Booking $booking): JsonResponse
    {
        $booking->load(['service', 'staff', 'customer']);

        $this->assertOwnedByEmail($request, $booking);

        if (! $booking->isCancellable()) {
            return response()->json([
                'message' => "A {$booking->status->value} booking cannot be cancelled.",
            ], 422);
        }

        if ($booking->isWithinCancellationWindow()) {
            return response()->json([
                'message' => 'This booking is inside its cancellation window. Please contact us directly.',
                'cancellation_notice_minutes' => $booking->service->cancellation_notice_minutes,
            ], 422);
        }

        $this->bookings->cancel(
            booking: $booking,
            cancelledBy: 'customer',
            reason: $request->input('reason'),
            // Cancelling in good time gets the deposit back.
            refundDeposit: true,
        );

        return response()->json(['data' => new BookingResource($booking->fresh())]);
    }

    /**
     * The reference is quotable and therefore guessable-ish; requiring the email
     * as well keeps one customer from reading or cancelling another's booking.
     */
    private function assertOwnedByEmail(Request $request, Booking $booking): void
    {
        $email = (string) $request->input('email');

        abort_unless(
            $email !== '' && hash_equals(
                strtolower($booking->customer->email),
                strtolower($email),
            ),
            403,
            'That email address does not match this booking.',
        );
    }
}
