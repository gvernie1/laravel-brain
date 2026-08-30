<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Storage;

/**
 * Stores scan output as `.graph-*.json` files under a directory
 * (default: storage/app/laravel-brain).
 */
final class FileGraphStore implements GraphStore
{
    public function __construct(private readonly string $dir) {}

    public function ensureSchema(): void
    {
        $this->ensureDir();
    }

    public function hasManifest(): bool
    {
        return file_exists($this->path('manifest'));
    }

    public function getManifest(): ?string
    {
        return $this->read('manifest');
    }

    public function putManifest(string $json): void
    {
        $this->ensureDir();

        // The old .graph-all.json had different legacy semantics. The canonical
        // graph introduced for format v2 uses the unambiguous `full` identity.
        $stale = $this->dir.'/.graph-all.json';
        if (file_exists($stale)) {
            @unlink($stale);
        }

        file_put_contents($this->path('manifest'), $json);
    }

    public function getFullGraph(): ?string
    {
        return $this->read('full');
    }

    public function putFullGraph(string $json): void
    {
        $this->ensureDir();
        file_put_contents($this->path('full'), $json);
    }

    public function getSubgraph(string $tabId): ?string
    {
        return $this->read($tabId);
    }

    public function putSubgraph(string $tabId, string $json): void
    {
        $this->ensureDir();
        file_put_contents($this->path($tabId), $json);
    }

    public function subgraphIds(): array
    {
        $ids = [];

        foreach (glob($this->dir.'/.graph-*.json') ?: [] as $file) {
            $base = basename($file);
            if (in_array($base, ['.graph-manifest.json', '.graph-full.json', '.graph-all.json'], true)) {
                continue;
            }
            $ids[] = substr($base, strlen('.graph-'), -strlen('.json'));
        }

        return $ids;
    }

    private function path(string $tabId): string
    {
        return $this->dir.'/.graph-'.$tabId.'.json';
    }

    private function read(string $tabId): ?string
    {
        $path = $this->path($tabId);

        return file_exists($path) ? (string) file_get_contents($path) : null;
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }
}
