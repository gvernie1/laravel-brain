<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\MiddlewareAnalyzer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

it('extracts Laravel 11 global middleware with prepend and append ordering', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-global-laravel11'));

    expect($registry->global)->toBe([
        'App\\Http\\Middleware\\BeforeOne',
        'App\\Http\\Middleware\\BeforeTwo',
        'App\\Http\\Middleware\\BeforeAll',
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'App\\Http\\Middleware\\AfterOne',
        'App\\Http\\Middleware\\AfterTwo',
    ]);
});

it('applies Laravel 11 global middleware to every route in graph and security analysis', function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app' => ['name' => 'Global Middleware Test'],
    ]));

    try {
        $graph = (new ProjectAnalyzer)
            ->analyze(fixture('middleware-global-laravel11'), static function (): void {})
            ->fullGraph;
    } finally {
        Container::setInstance(null);
    }

    $routeNodes = array_values(array_filter(
        $graph->nodes(),
        static fn ($node): bool => $node->type === 'route',
    ));
    $guardedByAuthenticate = array_values(array_filter(
        $graph->edges(),
        static fn ($edge): bool => $edge->type === 'route-to-middleware'
            && $edge->target === 'middleware::class::Illuminate\\Auth\\Middleware\\Authenticate',
    ));
    $accountMiddleware = array_values(array_map(
        static fn ($edge): string => $edge->target,
        array_filter(
            $graph->edges(),
            static fn ($edge): bool => $edge->type === 'route-to-middleware'
                && $edge->source === 'route::POST::/account',
        ),
    ));

    expect($routeNodes)->toHaveCount(2)
        ->and($guardedByAuthenticate)->toHaveCount(2)
        ->and($accountMiddleware)->toBe([
            'middleware::class::App\\Http\\Middleware\\BeforeOne',
            'middleware::class::App\\Http\\Middleware\\BeforeTwo',
            'middleware::class::App\\Http\\Middleware\\BeforeAll',
            'middleware::class::Illuminate\\Auth\\Middleware\\Authenticate',
            'middleware::class::App\\Http\\Middleware\\AfterOne',
            'middleware::class::App\\Http\\Middleware\\AfterTwo',
            'middleware::alias::route-specific',
        ]);

    foreach ($routeNodes as $routeNode) {
        expect($routeNode->data['security']['exposure'])->toBe('authed');
    }
});

it('keeps Laravel 10 kernel middleware analysis intact', function () {
    $registry = (new MiddlewareAnalyzer)->analyze(fixture('middleware-global-laravel10'));

    expect($registry->global)->toBe([
        'App\\Http\\Middleware\\LegacyFirst',
        'App\\Http\\Middleware\\LegacySecond',
    ])->and($registry->groups)->toBe([
        'web' => ['App\\Http\\Middleware\\LegacyWeb'],
    ])->and($registry->aliases)->toBe([
        'legacy' => 'App\\Http\\Middleware\\LegacyAlias',
    ]);
});
