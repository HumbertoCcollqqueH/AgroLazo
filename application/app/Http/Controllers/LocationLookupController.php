<?php

namespace App\Http\Controllers;

use App\Services\LocationLookup;
use App\Services\RuralPlaces;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocationLookupController extends Controller
{
    public function __invoke(Request $request, LocationLookup $lookup, RuralPlaces $rural): JsonResponse
    {
        abort_unless($request->user()->hasRole('producer') || $request->user()->hasPermission('view-buyer-area') || $request->user()->hasPermission('manage-producers') || $request->user()->hasPermission('manage-commerce'), 403);
        $data = $request->validate(['latitude' => 'required|numeric|between:-90,90', 'longitude' => 'required|numeric|between:-180,180', 'rural_query' => 'sometimes|nullable|string|max:100']);
        $nearby = $rural->nearby((float) $data['latitude'], (float) $data['longitude'], $data['rural_query'] ?? '');
        $result = $lookup->normalize([]);
        $lookupStatus = 'local_search';
        try {
            if (! array_key_exists('rural_query', $data)) {
                $result = $lookup->reverse((float) $data['latitude'], (float) $data['longitude']);
                $lookupStatus = 'available';
            }
        } catch (ConnectionException|HttpException $exception) {
            if ($exception instanceof HttpException && ! in_array($exception->getStatusCode(), [429, 503], true)) {
                throw $exception;
            }
            $lookupStatus = 'unavailable';
        }

        return response()->json([...$result, 'nearby_places' => $nearby, 'lookup_status' => $lookupStatus])->header('Cache-Control', 'private, no-store');
    }
}
