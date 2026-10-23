<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocationController extends Controller
{
    /**
     * Search the BARMM PSGC reference data for the address combobox.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $term = trim((string) $request->query('q', ''));

        $results = Location::query()->search($term, 20)->map(fn (Location $location) => [
            'psgc_code' => $location->psgc_code,
            'barangay' => $location->barangay,
            'city_municipality' => $location->city_municipality,
            'province' => $location->province,
            'full_address' => $location->full_address,
        ])->values();

        return response()->json(['results' => $results]);
    }
}
