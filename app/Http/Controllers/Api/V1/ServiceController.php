<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The public catalogue.
 */
class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $services = Service::active()
            ->with(['staff' => fn ($query) => $query->where('is_bookable', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ServiceResource::collection($services);
    }

    public function show(Service $service): ServiceResource
    {
        abort_unless($service->is_active, 404);

        return new ServiceResource(
            $service->load(['staff' => fn ($query) => $query->where('is_bookable', true)])
        );
    }
}
