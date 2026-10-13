<?php

return [
    'enabled' => env('MARKET_PRICING_ENABLED', true),
    'max_age_days' => 7,
    'cache_key' => 'market-prices:agromercado:quinua:v1',
    'source_url' => 'https://www.agromercado.gob.pe/observatorio/data/quinua.json',
    'source_page' => 'https://www.agromercado.gob.pe/observatorio/quinua/',
    'series' => [
        'quinua_blanca' => ['code' => 'blanca', 'label' => 'Blanca'],
        'quinua_negra' => ['code' => 'negra', 'label' => 'Negra'],
        'quinua_roja' => ['code' => 'roja', 'label' => 'Roja'],
    ],
];
