<?php

namespace App\Http;

use App\Http\Middleware\LegacyAlias;
use App\Http\Middleware\LegacyFirst;
use App\Http\Middleware\LegacyWeb;

class Kernel
{
    protected $middleware = [
        LegacyFirst::class,
        'App\\Http\\Middleware\\LegacySecond',
    ];

    protected $middlewareGroups = [
        'web' => [LegacyWeb::class],
    ];

    protected $routeMiddleware = [
        'legacy' => LegacyAlias::class,
    ];
}
