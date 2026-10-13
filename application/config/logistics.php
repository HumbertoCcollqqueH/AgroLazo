<?php

return [
    'geocoder_url' => env('LOCATION_GEOCODER_URL', 'https://photon.komoot.io/reverse'),
    'max_producer_distance_km' => (float) env('LOGISTICS_MAX_PRODUCER_DISTANCE_KM', 25),
    'require_coordinates' => (bool) env('LOGISTICS_REQUIRE_COORDINATES', true),
];
