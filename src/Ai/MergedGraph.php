<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Ai;

use LaraMint\LaravelBrain\Storage\GraphStore;

/**
 * Loads the canonical graph for machine consumers. Older format-v2 scans
 * without that artifact retain a compatibility fallback that merges tabs.
 */
final class MergedGraph
{
    /**
     * @return array{meta: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     *
     * @throws \RuntimeException when no scan data is present
     */
    public static function load(GraphStore $store): array
    {
        if (! $store->hasManifest()) {
            throw new \RuntimeException('No scan data found — run php artisan brain:scan first');
        }

        $canonical = $store->getFullGraph();
        if ($canonical !== null) {
            $data = json_decode($canonical, true);
            if (! is_array($data)
                || ! isset($data['meta'], $data['nodes'], $data['edges'])
                || ! is_array($data['meta'])
                || ! is_array($data['nodes'])
                || ! is_array($data['edges'])) {
                throw new \RuntimeException('Canonical graph data is invalid — run php artisan brain:scan again');
            }

            return $data;
        }

        // Legacy fallback: presentation tabs are partial forward slices and may
        // omit facts. New scans always persist the authoritative full graph.
        /** @var array<string, array<string, mixed>> $nodes */
        $nodes = [];
        /** @var array<string, array<string, mixed>> $edges */
        $edges = [];
        /** @var array<string, mixed> $meta */
        $meta = [];

        foreach ($store->subgraphIds() as $tabId) {
            $json = $store->getSubgraph($tabId);
            if ($json === null) {
                continue;
            }

            $data = json_decode($json, true);
            if (! is_array($data)) {
                continue;
            }

            if ($meta === [] && isset($data['meta']) && is_array($data['meta'])) {
                $meta = $data['meta'];
            }

            foreach ($data['nodes'] ?? [] as $node) {
                $id = (string) ($node['id'] ?? '');
                if ($id !== '') {
                    $nodes[$id] = $node;
                }
            }

            foreach ($data['edges'] ?? [] as $edge) {
                $eid = (string) ($edge['id'] ?? '');
                if ($eid === '') {
                    $eid = ($edge['source'] ?? '').'|'.($edge['target'] ?? '').'|'.($edge['type'] ?? '');
                }
                $edges[$eid] = $edge;
            }
        }

        if ($nodes === []) {
            throw new \RuntimeException('No scan data found — run php artisan brain:scan first');
        }

        // Per-subgraph counts are meaningless once merged; recompute.
        unset($meta['nodeCount'], $meta['edgeCount']);
        $meta['nodeCount'] = count($nodes);
        $meta['edgeCount'] = count($edges);

        return [
            'meta' => $meta,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];
    }
}
