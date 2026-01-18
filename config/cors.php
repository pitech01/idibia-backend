<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

<<<<<<< HEAD
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173', 'https://idibia-frontend.vercel.app'],
=======
    'allowed_origins' => ['http://localhost:5173', 'http://127.0.0.1:5173', 'https://idbia.vercel.app'],
>>>>>>> 5bf585264ec3b8b08d07202f5f0343f0d1d723a0

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
