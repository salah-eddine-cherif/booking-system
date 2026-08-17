<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\StoreAvailabilityExceptionRequest;
use App\Http\Requests\StoreAvailabilityRuleRequest;
use App\Http\Resources\AvailabilityExceptionResource;
use App\Http\Resources\AvailabilityRuleResource;
use App\Models\AvailabilityException;
use App\Models\AvailabilityRule;
use App\Models\User;
use App\Services\Availability\AvailabilityCalculator;
use App\Support\TimeRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Editing a staff member's weekly pattern and their date-specific overrides.
 */
class ScheduleController extends Controller
{
    public function __construct(private readonly AvailabilityCalculator $availability) {}

    /**
     * The whole schedule for one staff member: rules, exceptions, and the
     * resolved working hours those two produce.
     */
    public function show(AvailabilityRequest $request, User $staff): JsonResponse
    {
        $this->authorize('manageSchedule', $staff);

        $working = $this->availability->workingHours($staff, $request->window());

        return response()->json([
            'data' => [
                'staff' => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                    'timezone' => $staff->timezone,
                ],
                'rules' => AvailabilityRuleResource::collection($staff->availabilityRules),
                'exceptions' => AvailabilityExceptionResource::collection($staff->availabilityExceptions),
                'working_hours' => collect($working->all())->map(
                    fn (TimeRange $range) => $range->withTimezone($request->timezone())
                ),
            ],
        ]);
    }

    public function storeRule(StoreAvailabilityRuleRequest $request, User $staff): JsonResponse
    {
        $rule = $staff->availabilityRules()->create($request->validated());

        return response()->json(['data' => new AvailabilityRuleResource($rule)], 201);
    }

    public function destroyRule(Request $request, User $staff, AvailabilityRule $rule): JsonResponse
    {
        $this->authorize('manageSchedule', $staff);
        abort_unless($rule->user_id === $staff->id, 404);

        $rule->delete();

        return response()->json(null, 204);
    }

    public function storeException(StoreAvailabilityExceptionRequest $request, User $staff): JsonResponse
    {
        $exception = $staff->availabilityExceptions()->create($request->validated());

        return response()->json(['data' => new AvailabilityExceptionResource($exception)], 201);
    }

    public function destroyException(Request $request, User $staff, AvailabilityException $exception): JsonResponse
    {
        $this->authorize('manageSchedule', $staff);
        abort_unless($exception->user_id === $staff->id, 404);

        $exception->delete();

        return response()->json(null, 204);
    }
}
