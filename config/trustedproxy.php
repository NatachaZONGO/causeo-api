<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confiance
    |--------------------------------------------------------------------------
    |
    | Adresses dont Laravel accepte les en-têtes X-Forwarded-* (IP du client, HTTPS,
    | hôte), séparées par des virgules, ou « * » pour le proxy qui appelle l'application.
    |
    | Sur le VPS, nginx parle directement à php-fpm et transmet l'IP du client et HTTPS
    | sans en-tête X-Forwarded : la valeur par défaut (la machine elle-même) suffit. À
    | changer seulement derrière un autre proxy (Cloudflare, répartiteur de charge…).
    | Lu par le middleware TrustProxies de Laravel.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
