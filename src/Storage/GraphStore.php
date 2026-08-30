<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Storage;

/**
 * Persistence backend for scan output.
 *
 * A scan produces one authoritative full graph, one manifest (the tab index),
 * and one presentation subgraph JSON blob per tab. Implementations decide
 * where those blobs live — the filesystem (default) or a database table.
 */
interface GraphStore
{
    /**
     * Prepare the backend so a scan can write to it (create the directory
     * or the database table when missing). A no-op when already set up.
     */
    public function ensureSchema(): void;

    public function hasManifest(): bool;

    public function getManifest(): ?string;

    public function putManifest(string $json): void;

    /** Canonical graph for machine consumers; never an ordinary UI tab. */
    public function getFullGraph(): ?string;

    public function putFullGraph(string $json): void;

    public function getSubgraph(string $tabId): ?string;

    public function putSubgraph(string $tabId, string $json): void;

    /**
     * Tab ids of every stored presentation subgraph (manifest and canonical
     * full graph excluded).
     *
     * @return list<string>
     */
    public function subgraphIds(): array;
}
