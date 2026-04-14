<?php

declare(strict_types=1);

namespace Greph\Index;

use Greph\Support\Filesystem;

final class IndexSetLoader
{
    public const DEFAULT_FILE = '.greph-index-set.json';

    private TextIndexStore $textStore;

    private AstIndexStore $astIndexStore;

    private AstCacheStore $astCacheStore;

    public function __construct(
        ?TextIndexStore $textStore = null,
        ?AstIndexStore $astIndexStore = null,
        ?AstCacheStore $astCacheStore = null,
    ) {
        $this->textStore = $textStore ?? new TextIndexStore();
        $this->astIndexStore = $astIndexStore ?? new AstIndexStore();
        $this->astCacheStore = $astCacheStore ?? new AstCacheStore();
    }

    public function load(?string $manifestPath = null): IndexSet
    {
        $resolvedManifestPath = $this->resolveManifestPath($manifestPath);

        if (!is_file($resolvedManifestPath)) {
            throw new \RuntimeException(sprintf('Index set manifest does not exist: %s', $resolvedManifestPath));
        }

        $contents = @file_get_contents($resolvedManifestPath);

        if ($contents === false || $contents === '') {
            throw new \RuntimeException(sprintf('Failed to read index set manifest: %s', $resolvedManifestPath));
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('Index set manifest is invalid JSON: %s', $resolvedManifestPath), 0, $exception);
        }

        if (!is_array($payload) || !is_array($payload['indexes'] ?? null)) {
            throw new \RuntimeException(sprintf('Index set manifest is invalid: %s', $resolvedManifestPath));
        }

        $basePath = dirname($resolvedManifestPath);
        $manifestName = is_string($payload['name'] ?? null)
            ? $payload['name']
            : basename($resolvedManifestPath, '.json');
        $entries = [];
        $seenNames = [];

        foreach ($payload['indexes'] as $offset => $entryPayload) {
            if (!is_array($entryPayload)) {
                throw new \RuntimeException(sprintf('Index set manifest entry #%d is invalid: %s', $offset, $resolvedManifestPath));
            }

            $entryName = $entryPayload['name'] ?? null;
            $root = $entryPayload['root'] ?? null;
            $mode = $entryPayload['mode'] ?? null;

            if (!is_string($entryName) || $entryName === '') {
                throw new \RuntimeException(sprintf('Index set manifest entry #%d is missing a valid name: %s', $offset, $resolvedManifestPath));
            }

            if (isset($seenNames[$entryName])) {
                throw new \RuntimeException(sprintf('Index set manifest contains duplicate entry name "%s": %s', $entryName, $resolvedManifestPath));
            }

            if (!is_string($root) || $root === '') {
                throw new \RuntimeException(sprintf('Index set manifest entry "%s" is missing a valid root: %s', $entryName, $resolvedManifestPath));
            }

            $modeObject = IndexMode::tryFrom((string) $mode);

            if ($modeObject === null) {
                throw new \RuntimeException(sprintf('Index set manifest entry "%s" has unknown mode "%s": %s', $entryName, (string) $mode, $resolvedManifestPath));
            }

            $rootPath = $this->resolvePath($basePath, $root);
            $indexPath = isset($entryPayload['index_dir']) && is_string($entryPayload['index_dir']) && $entryPayload['index_dir'] !== ''
                ? $this->resolvePath($basePath, $entryPayload['index_dir'])
                : $this->defaultIndexPath($modeObject, $rootPath);
            $profile = isset($entryPayload['lifecycle']) && is_string($entryPayload['lifecycle'])
                ? IndexLifecycleProfile::tryFrom($entryPayload['lifecycle'])
                : null;

            if (isset($entryPayload['lifecycle']) && $profile === null) {
                throw new \RuntimeException(sprintf('Index set manifest entry "%s" has unknown lifecycle "%s": %s', $entryName, (string) $entryPayload['lifecycle'], $resolvedManifestPath));
            }

            $entries[] = new IndexSetEntry(
                name: $entryName,
                rootPath: $rootPath,
                indexPath: $indexPath,
                mode: $modeObject,
                lifecycle: new IndexLifecycle(
                    profile: $profile ?? IndexLifecycleProfile::ManualRefresh,
                    maxChangedFiles: max(0, (int) ($entryPayload['max_changed_files'] ?? IndexLifecycle::DEFAULT_MAX_CHANGED_FILES)),
                    maxChangedBytes: max(0, (int) ($entryPayload['max_changed_bytes'] ?? IndexLifecycle::DEFAULT_MAX_CHANGED_BYTES)),
                ),
                priority: (int) ($entryPayload['priority'] ?? 0),
                enabled: !array_key_exists('enabled', $entryPayload) || (bool) $entryPayload['enabled'],
            );
            $seenNames[$entryName] = true;
        }

        return new IndexSet(
            path: $resolvedManifestPath,
            basePath: $basePath,
            name: $manifestName,
            entries: $entries,
        );
    }

    public function resolveManifestPath(?string $manifestPath = null): string
    {
        if ($manifestPath === null || $manifestPath === '') {
            return Filesystem::normalizePath((getcwd() ?: '.') . '/' . self::DEFAULT_FILE);
        }

        if (str_starts_with($manifestPath, '/')) {
            return Filesystem::normalizePath($manifestPath);
        }

        return Filesystem::normalizePath((getcwd() ?: '.') . '/' . $manifestPath);
    }

    private function resolvePath(string $basePath, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return Filesystem::normalizePath($path);
        }

        return Filesystem::normalizePath($basePath . '/' . $path);
    }

    private function defaultIndexPath(IndexMode $mode, string $rootPath): string
    {
        return match ($mode) {
            IndexMode::Text => $this->textStore->defaultPath($rootPath),
            IndexMode::AstIndex => $this->astIndexStore->defaultPath($rootPath),
            IndexMode::AstCache => $this->astCacheStore->defaultPath($rootPath),
        };
    }
}
