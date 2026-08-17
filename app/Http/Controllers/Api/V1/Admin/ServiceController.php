<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Service::class);

        return ServiceResource::collection(
            Service::with('staff')->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = Service::create($request->safe()->except('staff_ids'));

        $service->staff()->sync($request->input('staff_ids', []));

        return response()->json(
            ['data' => new ServiceResource($service->load('staff'))],
            201,
        );
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->safe()->except('staff_ids'));

        if ($request->has('staff_ids')) {
            $service->staff()->sync($request->input('staff_ids', []));
        }

        return new ServiceResource($service->fresh('staff'));
    }

    /**
     * Services are never hard-deleted once booked; deactivating keeps historic
     * bookings readable and the restrictOnDelete foreign key intact.
     */
    public function destroy(Service $service): JsonResponse
    {
        $this->authorize('delete', $service);

        if ($service->bookings()->exists()) {
            $service->update(['is_active' => false]);

            return response()->json([
                'message' => 'Service has existing bookings, so it was deactivated rather than deleted.',
                'data' => new ServiceResource($service),
            ]);
        }

        $service->delete();

        return response()->json(null, 204);
    }
}
