<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\AvailabilityCalculator;
use App\Services\Availability\TimeSlot;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityCalculator $availability) {}

    /**
     * Bookable start times for a service, optionally narrowed to one staff member.
     *
     * GET /api/v1/services/{service}/availability?from=…&to=…&timezone=…&staff_id=…
     */
    public function index(AvailabilityRequest $request, Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);

        $staff = $service->staff()->bookable()
            ->when($request->staffId(), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $timezone = $request->timezone();
        $window = $request->window();

        $slots = $this->availability->slotsForService($service, $window, $staff);

        return response()->json([
            'data' => [
                'service' => $service->slug,
                'timezone' => $timezone,
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                // Grouped by local date so a calendar UI can render it directly.
                'days' => $slots
                    ->groupBy(fn (TimeSlot $slot) => $slot->startsAt->setTimezone($timezone)->format('Y-m-d'))
                    ->map(fn ($daySlots) => $daySlots->map(fn (TimeSlot $slot) => $slot->toArray($timezone))->values())
                    ->sortKeys(),
                'staff' => $staff->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'timezone' => $member->timezone,
                ])->values(),
            ],
            'meta' => [
                'slot_count' => $slots->count(),
            ],
        ]);
    }
}
