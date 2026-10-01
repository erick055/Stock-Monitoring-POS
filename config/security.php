<?php

return [
    // Only the hosting provider's actual proxy IPs/CIDRs, never a wildcard.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
