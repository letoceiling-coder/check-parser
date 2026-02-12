<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deploy webhook token
    |--------------------------------------------------------------------------
    | Token for POST /api/deploy. Set in .env as DEPLOY_TOKEN.
    | When config is cached (php artisan optimize), this value is read from cache.
    */
    'token' => env('DEPLOY_TOKEN', ''),
];
