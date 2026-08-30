<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;
use LaraMint\LaravelBrain\Storage\DatabaseGraphStore;
use LaraMint\LaravelBrain\Storage\GraphStoreWriter;

beforeEach(function () {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
});

it('stores the canonical graph under a private identity excluded from tabs', function () {
    $store = new DatabaseGraphStore('brain_graph_contract');
    $full = new Graph;
    $full->addNode(new Node('canonical', 'service', 'Canonical'));
    $tab = new Graph;
    $tab->addNode(new Node('tab', 'route', 'Tab'));
    $manifest = json_encode([
        'graphFormatVersion' => 2,
        'canonicalGraph' => ['available' => true, 'identity' => 'full'],
        'totalNodes' => 1,
        'totalEdges' => 0,
        'tabs' => [],
    ], JSON_THROW_ON_ERROR);

    GraphStoreWriter::persist($store, $full, $manifest, ['route' => $tab]);

    expect($store->getFullGraph())->toBe($full->toJson())
        ->and($store->getManifest())->toBe($manifest)
        ->and($store->subgraphIds())->toBe(['route']);
});
