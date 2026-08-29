<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;

function queuedJobEdgeSignatures(array $edges): array
{
    return array_map(
        static fn ($edge): string => "{$edge->callerFqcn}::{$edge->callerMethod}->{$edge->calleeFqcn}::{$edge->calleeMethod}:{$edge->type}",
        $edges,
    );
}

function queuedJobNodeFqcns(Graph $graph): array
{
    return array_values(array_filter(array_map(
        static fn ($node): ?string => is_string($node->data['fqcn'] ?? null) ? $node->data['fqcn'] : null,
        $graph->nodes(),
    )));
}

it('recursively traces controller-dispatched jobs through services models and nested jobs', function () {
    $root = fixture('queued-job-project');
    $edges = (new MethodTracer)->traceMethod(
        'App\\Http\\Controllers\\OrderController',
        'store',
        ['App' => [$root.'/app']],
        $root,
    );

    expect(queuedJobEdgeSignatures($edges))->toContain(
        'App\\Http\\Controllers\\OrderController::store->App\\Jobs\\FirstJob::handle:job',
        'App\\Jobs\\FirstJob::handle->App\\Services\\OrderWorkflow::run:service',
        'App\\Services\\OrderWorkflow::run->App\\Models\\Order::query:model',
        'App\\Services\\OrderWorkflow::run->App\\Jobs\\SecondJob::handle:job',
        'App\\Jobs\\SecondJob::handle->App\\Services\\FulfillmentService::run:service',
        'App\\Services\\FulfillmentService::run->App\\Models\\Order::where:model',
    );
});

it('traces a nested job cycle once without losing either job lifecycle', function () {
    $root = fixture('queued-job-project');
    $edges = (new MethodTracer)->traceMethod(
        'App\\Http\\Controllers\\OrderController',
        'store',
        ['App' => [$root.'/app']],
        $root,
    );
    $signatures = queuedJobEdgeSignatures($edges);

    expect($signatures)
        ->toContain('App\\Jobs\\SecondJob::handle->App\\Jobs\\FirstJob::handle:job')
        ->and(array_count_values($signatures)['App\\Jobs\\FirstJob::handle->App\\Services\\OrderWorkflow::run:service'] ?? 0)
        ->toBe(1)
        ->and($edges)->toHaveCount(7);
});

it('emits selectable job tabs with downstream lifecycles while route tabs retain them', function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app' => ['name' => 'Queued Job Test'],
    ]));

    try {
        $result = (new ProjectAnalyzer)
            ->analyze(fixture('queued-job-project'), static function (): void {});
    } finally {
        Container::setInstance(null);
    }

    $jobTabs = [];
    $routeTabs = [];
    foreach ($result->manifest as $entry) {
        if ($entry->category === 'Job') {
            $jobTabs[$entry->label] = $entry;
        } elseif ($entry->category === 'Route') {
            $routeTabs[$entry->label] = $entry;
        }
    }

    expect($jobTabs)->toHaveKeys(['FirstJob', 'SecondJob'])
        ->and($jobTabs['FirstJob']->category)->toBe('Job');

    $firstJobLifecycle = queuedJobNodeFqcns($result->subgraphs[$jobTabs['FirstJob']->id]);
    expect($firstJobLifecycle)->toContain(
        'App\\Jobs\\FirstJob',
        'App\\Services\\OrderWorkflow',
        'App\\Models\\Order',
        'App\\Jobs\\SecondJob',
        'App\\Services\\FulfillmentService',
    );

    foreach (['POST /orders', 'POST /queued-closure'] as $routeLabel) {
        expect($routeTabs)->toHaveKey($routeLabel);
        $routeLifecycle = queuedJobNodeFqcns($result->subgraphs[$routeTabs[$routeLabel]->id]);
        expect($routeLifecycle)->toContain(
            'App\\Jobs\\FirstJob',
            'App\\Services\\OrderWorkflow',
            'App\\Jobs\\SecondJob',
            'App\\Services\\FulfillmentService',
        );
    }

    $manifestTabs = json_decode($result->manifestJson, true)['tabs'];
    expect(array_values(array_filter(
        $manifestTabs,
        static fn (array $tab): bool => ($tab['category'] ?? null) === 'Job',
    )))->toHaveCount(2);
});
