<?php

return [
    'trusted_proxy_cidrs' => array_values(array_filter(explode(',', (string) env('TRUSTED_PROXY_CIDRS', '')))),
    'write_limit' => 120,
    'webhook_limit' => 300,
];
