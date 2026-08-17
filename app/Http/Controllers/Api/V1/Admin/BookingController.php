<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The staff-side calendar.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Booking::class);

        $user = $request->user();

        $bookings = Booking::query()
            ->with(['service', 'staff', 'customer'])
            // Staff only ever see their own column of the calendar.
            ->when(! $user->isAdmin(), fn ($query) => $query->forStaff($user))
            ->when($request->filled('staff_id'), fn ($query) => $query->forStaff($request->integer('staff_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($query) => $query->where('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('starts_at', '<=', $request->date('to')))
            ->orderBy('starts_at')
            ->paginate($request->integer('per_page', 25));

        return BookingResource::collection($bookings);
    }

    public function show(Booking $booking): BookingResource
    {
        $this->authorize('view', $booking);

        return new BookingResource($booking->load(['service', 'staff', 'customer']));
    }

    /**
     * Mark a booking completed, a no-show, or annotate it.
     */
    public function update(Request $request, Booking $booking): BookingResource
    {
        $this->authorize('update', $booking);

        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(BookingStatus::class)->only([
                BookingStatus::Confirmed,
                BookingStatus::Completed,
                BookingStatus::NoShow,
            ])],
            'staff_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['status'])) {
            $status = BookingStatus::from($validated['status']);

            // Confirming goes through the service so the confirmation email and
            // confirmed_at stamp happen exactly as they would after a payment.
            $status === BookingStatus::Confirmed
                ? $this->bookings->confirm($booking)
                : $booking->forceFill(['status' => $status])->save();
        }

        if (array_key_exists('staff_notes', $validated)) {
            $booking->forceFill(['staff_notes' => $validated['staff_notes']])->save();
        }

        return new BookingResource($booking->fresh(['service', 'staff', 'customer']));
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking): BookingResource
    {
        $this->authorize('reschedule', $booking);

        $staff = $request->filled('staff_id')
            ? User::bookable()->findOrFail($request->integer('staff_id'))
            : null;

        $this->bookings->reschedule($booking, $request->startsAt(), $staff);

        return new BookingResource($booking->fresh(['service', 'staff', 'customer']));
    }

    public function destroy(Request $request, Booking $booking): JsonResponse
    {
        $this->authorize('cancel', $booking);

        $this->bookings->cancel(
            booking: $booking,
            cancelledBy: 'staff',
            reason: $request->input('reason'),
            refundDeposit: $request->boolean('refund', true),
        );

        return response()->json(['data' => new BookingResource($booking->fresh())]);
    }
}
