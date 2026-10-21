<?php

/*
| CORS — le frontend appelle l'API avec credentials: 'include' (cookies Sanctum SPA).
| Les navigateurs refusent alors l'origine « * » : on autorise explicitement FRONTEND_URL.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
