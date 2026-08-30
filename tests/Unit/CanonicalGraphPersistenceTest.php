<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Ai\MergedGraph;
use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;
use LaraMint\LaravelBrain\Storage\FileGraphStore;
use LaraMint\LaravelBrain\Storage\GraphStoreWriter;

function canonicalGraphStoreDirectory(): string
{
    $directory = sys_get_temp_dir().'/brain-canonical-'.uniqid('', true);
    mkdir($directory, 0o777, true);

    return $directory;
}

function removeCanonicalGraphStoreDirectory(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            unlink($directory.'/'.$entry);
        }
    }
    rmdir($directory);
}

/** @return array{full: Graph, tab: Graph, manifest: string} */
function canonicalGraphFixture(): array
{
    $full = new Graph;
    $full->setMeta(['project' => 'canonical', 'analyzedAt' => '2026-08-30T00:00:00Z']);
    $full->addNode(new Node('route::GET::/', 'route', 'GET /'));
    $full->addNode(new Node('service::reachable', 'service', 'Reachable'));
    $full->addNode(new Node('service::canonical-only', 'service', 'Canonical only'));
    $full->addEdge(new Edge('edge-1', 'route::GET::/', 'service::reachable', 'calls', 'route-to-service'));
    $full->addEdge(new Edge('edge-2', 'service::reachable', 'service::canonical-only', 'calls', 'service-to-service'));

    $tab = new Graph;
    $tab->setMeta(['project' => 'canonical', 'analyzedAt' => '2026-08-30T00:00:00Z']);
    $tab->addNode($full->getNode('route::GET::/'));
    $tab->addNode($full->getNode('service::reachable'));
    $tab->addEdge($full->edges()[0]);

    $manifest = json_encode([
        'project' => 'canonical',
        'analyzedAt' => '2026-08-30T00:00:00Z',
        'graphFormatVersion' => 2,
        'canonicalGraph' => ['available' => true, 'identity' => 'full'],
        'totalNodes' => $full->nodeCount(),
        'totalEdges' => $full->edgeCount(),
        'tabs' => [['id' => 'route', 'file' => '.graph-route.json']],
    ], JSON_THROW_ON_ERROR);

    return compact('full', 'tab', 'manifest');
}

it('persists the authoritative full graph separately from presentation tabs', function () {
    $directory = canonicalGraphStoreDirectory();
    try {
        $fixture = canonicalGraphFixture();
        $store = new FileGraphStore($directory);
        file_put_contents($directory.'/.graph-all.json', '{}');

        GraphStoreWriter::persist($store, $fixture['full'], $fixture['manifest'], ['route' => $fixture['tab']]);

        expect($store->getFullGraph())->toBe($fixture['full']->toJson())
            ->and(file_exists($directory.'/.graph-full.json'))->toBeTrue()
            ->and(file_exists($directory.'/.graph-all.json'))->toBeFalse()
            ->and($store->subgraphIds())->toBe(['route']);

        $canonical = json_decode((string) $store->getFullGraph(), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode((string) $store->getManifest(), true, flags: JSON_THROW_ON_ERROR);
        expect($canonical['meta']['nodeCount'])->toBe($manifest['totalNodes'])
            ->and($canonical['meta']['edgeCount'])->toBe($manifest['totalEdges']);

        $ids = array_fill_keys(array_column($canonical['nodes'], 'id'), true);
        foreach ($canonical['edges'] as $edge) {
            expect(isset($ids[$edge['source']]))->toBeTrue()
                ->and(isset($ids[$edge['target']]))->toBeTrue();
        }
    } finally {
        removeCanonicalGraphStoreDirectory($directory);
    }
});

it('uses the canonical graph for machine consumers instead of reconstructing tabs', function () {
    $directory = canonicalGraphStoreDirectory();
    try {
        $fixture = canonicalGraphFixture();
        $store = new FileGraphStore($directory);
        GraphStoreWriter::persist($store, $fixture['full'], $fixture['manifest'], ['route' => $fixture['tab']]);

        $loaded = MergedGraph::load($store);
        expect(array_column($loaded['nodes'], 'id'))->toContain('service::canonical-only')
            ->and($loaded['nodes'])->toHaveCount(3)
            ->and($loaded['edges'])->toHaveCount(2);
    } finally {
        removeCanonicalGraphStoreDirectory($directory);
    }
});

it('refuses to publish a canonical graph that disagrees with its manifest', function () {
    $directory = canonicalGraphStoreDirectory();
    try {
        $fixture = canonicalGraphFixture();
        $manifest = json_decode($fixture['manifest'], true, flags: JSON_THROW_ON_ERROR);
        $manifest['totalNodes']++;

        GraphStoreWriter::persist(
            new FileGraphStore($directory),
            $fixture['full'],
            json_encode($manifest, JSON_THROW_ON_ERROR),
            ['route' => $fixture['tab']],
        );
    } finally {
        removeCanonicalGraphStoreDirectory($directory);
    }
})->throws(LogicException::class, 'Canonical graph counts do not match the manifest');

it('replaces the canonical snapshot on a later full or scoped rescan', function () {
    $directory = canonicalGraphStoreDirectory();
    try {
        $fixture = canonicalGraphFixture();
        $store = new FileGraphStore($directory);
        GraphStoreWriter::persist($store, $fixture['full'], $fixture['manifest'], ['route' => $fixture['tab']]);

        $replacement = new Graph;
        $replacement->addNode(new Node('service::replacement', 'service', 'Replacement'));
        $replacementManifest = json_encode([
            'graphFormatVersion' => 2,
            'canonicalGraph' => ['available' => true, 'identity' => 'full'],
            'totalNodes' => 1,
            'totalEdges' => 0,
            'tabs' => [],
        ], JSON_THROW_ON_ERROR);
        GraphStoreWriter::persist($store, $replacement, $replacementManifest, []);

        $canonical = json_decode((string) $store->getFullGraph(), true, flags: JSON_THROW_ON_ERROR);
        expect(array_column($canonical['nodes'], 'id'))->toBe(['service::replacement'])
            ->not->toContain('service::canonical-only');
    } finally {
        removeCanonicalGraphStoreDirectory($directory);
    }
});
