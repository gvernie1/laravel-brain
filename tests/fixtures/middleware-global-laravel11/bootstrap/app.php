<?php

use App\Http\Middleware\AfterOne;
use App\Http\Middleware\BeforeOne;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware
            ->append(Authenticate::class)
            ->prepend('App\\Http\\Middleware\\BeforeAll');
        $middleware->append([
            AfterOne::class,
            'App\\Http\\Middleware\\AfterTwo',
        ]);
        $middleware->prepend([
            BeforeOne::class,
            'App\\Http\\Middleware\\BeforeTwo',
        ]);
    })
    ->create();
