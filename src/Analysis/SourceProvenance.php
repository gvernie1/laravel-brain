<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Shared source provenance rules for graph facts.
 *
 * `sourceScope` on method-backed nodes remains the declaration scope. Callers
 * that need both sides should use the explicit receiver/declaring scope fields.
 */
final class SourceProvenance
{
    /** @return 'application'|'framework'|'vendor'|'runtime'|'unknown' */
    public static function scope(string $fqcn, ?string $file, string $projectRoot): string
    {
        $fqcn = ltrim($fqcn, '\\');

        if (self::isFrameworkFqcn($fqcn)) {
            return 'framework';
        }

        if (self::isInternal($fqcn)) {
            return 'runtime';
        }

        $normalizedFile = self::normalizePath($file);
        $normalizedRoot = self::normalizePath($projectRoot);
        if ($normalizedFile === null) {
            return 'unknown';
        }

        if ($normalizedRoot !== null && self::isWithin($normalizedFile, $normalizedRoot.'/vendor')) {
            return 'vendor';
        }
        if ($normalizedRoot !== null && self::isWithin($normalizedFile, $normalizedRoot)) {
            return 'application';
        }
        if (str_contains($normalizedFile, '/vendor/')) {
            return 'vendor';
        }

        return 'unknown';
    }

    public static function relativePath(string $file, string $projectRoot): ?string
    {
        $normalizedFile = self::normalizePath($file);
        $normalizedRoot = self::normalizePath($projectRoot);
        if ($normalizedFile === null || $normalizedRoot === null || ! self::isWithin($normalizedFile, $normalizedRoot)) {
            return null;
        }

        return substr($normalizedFile, strlen($normalizedRoot) + 1);
    }

    private static function isFrameworkFqcn(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'Illuminate\\')
            || str_starts_with($fqcn, 'Laravel\\')
            || str_starts_with($fqcn, 'Symfony\\');
    }

    private static function isInternal(string $fqcn): bool
    {
        try {
            if (! class_exists($fqcn, false) && ! interface_exists($fqcn, false)) {
                return false;
            }

            return (new \ReflectionClass($fqcn))->isInternal();
        } catch (\ReflectionException) {
            return false;
        }
    }

    private static function normalizePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $resolved = realpath($path);
        $normalized = str_replace('\\', '/', $resolved !== false ? $resolved : $path);

        return rtrim($normalized, '/');
    }

    private static function isWithin(string $path, string $root): bool
    {
        return $path !== $root && str_starts_with($path, rtrim($root, '/').'/');
    }
}
