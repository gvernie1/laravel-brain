<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Storage;

use LaraMint\LaravelBrain\Graph\Graph;

/** Writes one complete scan snapshot through the storage abstraction. */
final class GraphStoreWriter
{
    /** @param array<string, Graph> $subgraphs */
    public static function persist(GraphStore $store, Graph $fullGraph, string $manifestJson, array $subgraphs): void
    {
        self::assertConsistentSnapshot($fullGraph, $manifestJson);
        $store->ensureSchema();

        // Publish the manifest last: it is the discovery/index contract and should
        // only advertise a snapshot after its canonical graph and tabs are present.
        $store->putFullGraph($fullGraph->toJson());
        foreach ($subgraphs as $tabId => $subgraph) {
            $store->putSubgraph((string) $tabId, $subgraph->toJson());
        }
        $store->putManifest($manifestJson);
    }

    private static function assertConsistentSnapshot(Graph $fullGraph, string $manifestJson): void
    {
        $manifest = json_decode($manifestJson, true);
        if (! is_array($manifest)
            || ($manifest['totalNodes'] ?? null) !== $fullGraph->nodeCount()
            || ($manifest['totalEdges'] ?? null) !== $fullGraph->edgeCount()) {
            throw new \LogicException('Canonical graph counts do not match the manifest.');
        }

        $nodeIds = [];
        foreach ($fullGraph->nodes() as $node) {
            $nodeIds[$node->id] = true;
        }
        foreach ($fullGraph->edges() as $edge) {
            if (! isset($nodeIds[$edge->source], $nodeIds[$edge->target])) {
                throw new \LogicException("Canonical edge {$edge->id} has a missing endpoint.");
            }
        }
    }
}
