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

    /*
    |--------------------------------------------------------------------------
    | Deploy via SSH (build and update on server)
    |--------------------------------------------------------------------------
    | If set, php artisan deploy will after git push run update on server via SSH:
    | git pull, composer install, migrate, frontend build, cache clear.
    | DEPLOY_SSH: e.g. root@89.169.39.244
    | DEPLOY_SSH_PATH: project path on server, e.g. /var/www/auto.siteaccess.ru
    */
    'ssh_host' => env('DEPLOY_SSH', ''),
    'ssh_path' => env('DEPLOY_SSH_PATH', ''),
];
