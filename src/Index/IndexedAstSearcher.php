<?php

declare(strict_types=1);

namespace Phgrep\Index;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstSearchOptions;
use Phgrep\Ast\AstSearcher;
use Phgrep\Ast\Pattern;
use Phgrep\Ast\PatternParser;
use Phgrep\Support\Filesystem;
use Phgrep\Walker\FileList;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;

final class IndexedAstSearcher
{
    private AstIndexStore $store;

    private AstSearcher $astSearcher;

    private PatternParser $patternParser;

    public function __construct(
        ?AstIndexStore $store = null,
        ?AstSearcher $astSearcher = null,
        ?PatternParser $patternParser = null,
    ) {
        $this->store = $store ?? new AstIndexStore();
        $this->astSearcher = $astSearcher ?? new AstSearcher();
        $this->patternParser = $patternParser ?? new PatternParser();
    }

    /**
     * @param string|list<string> $paths
     * @return list<AstMatch>
     */
    public function search(string $pattern, string|array $paths, AstSearchOptions $options, ?string $indexPath = null): array
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $resolvedPaths = $this->resolvePaths($paths);
        $indexPath = $this->resolveIndexPath($resolvedPaths, $indexPath);

        if ($indexPath === null) {
            throw new \RuntimeException('No AST index found for the requested paths. Build one first.');
        }

        $index = $this->store->load($indexPath);
        $parsedPattern = $this->patternParser->parse($pattern, $options->language);
        $selection = $this->buildSelection($resolvedPaths, $index->rootPath);
        $selectedPaths = [];
        $selectedPathSet = [];
        $fallbackPaths = [];
        $explicitSelections = [];

        foreach ($resolvedPaths as $path) {
            if ($this->isWithinRoot($path, $index->rootPath)) {
                if (is_file($path)) {
                    $explicitSelections[$path] = true;
                }

                continue;
            }

            $fallbackPaths[] = $path;
        }

        foreach ($index->files as $file) {
            $absolutePath = $index->rootPath . '/' . $file['p'];

            if (!$this->matchesSelection($absolutePath, $selection)) {
                continue;
            }

            if (
                !isset($explicitSelections[$absolutePath])
                && !$this->matchesQueryFilters($file, $absolutePath, $index->rootPath, $options)
            ) {
                continue;
            }

            $selectedPaths[] = $absolutePath;
            $selectedPathSet[$absolutePath] = true;
        }

        foreach (array_keys($explicitSelections) as $explicitPath) {
            if (!isset($selectedPathSet[$explicitPath])) {
                $fallbackPaths[] = $explicitPath;
            }
        }

        $candidateIds = $this->candidateIds($index, $parsedPattern);
        $candidatePaths = [];

        if ($candidateIds === null) {
            $candidatePaths = $selectedPaths;
        } else {
            foreach ($index->files as $file) {
                $absolutePath = $index->rootPath . '/' . $file['p'];

                if (!isset($selectedPathSet[$absolutePath]) || !isset($candidateIds[$file['id']])) {
                    continue;
                }

                $candidatePaths[] = $absolutePath;
            }
        }

        $matches = [];

        foreach ($this->astSearcher->searchFiles(new FileList($candidatePaths), $pattern, $options) as $match) {
            $matches[] = $match;
        }

        if ($fallbackPaths !== []) {
            foreach ($this->astSearcher->searchFiles(new FileList($fallbackPaths), $pattern, $options) as $match) {
                $matches[] = $match;
            }
        }

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        return $matches;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolvePaths(array $paths): array
    {
        $resolvedPaths = [];

        foreach ($paths as $path) {
            $resolvedPath = realpath($path);

            if ($resolvedPath === false) {
                throw new \RuntimeException(sprintf('Path does not exist: %s', $path));
            }

            $resolvedPaths[] = Filesystem::normalizePath($resolvedPath);
        }

        return $resolvedPaths;
    }

    /**
     * @param list<string> $resolvedPaths
     */
    private function resolveIndexPath(array $resolvedPaths, ?string $indexPath): ?string
    {
        if ($indexPath !== null && $indexPath !== '') {
            return Filesystem::normalizePath($indexPath);
        }

        foreach ($resolvedPaths as $path) {
            $located = $this->store->locateFrom($path);

            if ($located !== null) {
                return $located;
            }
        }

        return null;
    }

    /**
     * @param array{files: array<string, true>, directories: list<string>} $selection
     */
    private function matchesSelection(string $absolutePath, array $selection): bool
    {
        if (isset($selection['files'][$absolutePath])) {
            return true;
        }

        foreach ($selection['directories'] as $path) {
            if ($absolutePath === $path || str_starts_with($absolutePath, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $resolvedPaths
     * @return array{files: array<string, true>, directories: list<string>}
     */
    private function buildSelection(array $resolvedPaths, string $rootPath): array
    {
        $selection = [
            'files' => [],
            'directories' => [],
        ];

        foreach ($resolvedPaths as $path) {
            if (!$this->isWithinRoot($path, $rootPath)) {
                continue;
            }

            if (is_file($path)) {
                $selection['files'][$path] = true;
                continue;
            }

            $selection['directories'][] = $path;
        }

        return $selection;
    }

    private function isWithinRoot(string $path, string $rootPath): bool
    {
        return $path === $rootPath || str_starts_with($path, $rootPath . '/');
    }

    /**
     * @param array{id: int, p: string, s: int, m: int, h: bool, g: bool, o: int} $file
     */
    private function matchesQueryFilters(array $file, string $absolutePath, string $rootPath, AstSearchOptions $options): bool
    {
        if (!$options->includeHidden && $file['h']) {
            return false;
        }

        if ($options->respectIgnore && $file['g']) {
            return false;
        }

        if ($options->maxFileSizeBytes > 0 && $file['s'] > $options->maxFileSizeBytes) {
            return false;
        }

        if ($options->fileTypeFilter !== null && !$options->fileTypeFilter->matches($absolutePath)) {
            return false;
        }

        return $this->matchesGlobPatterns($absolutePath, $rootPath, $options->globPatterns);
    }

    /**
     * @param list<string> $globPatterns
     */
    private function matchesGlobPatterns(string $path, string $rootPath, array $globPatterns): bool
    {
        if ($globPatterns === []) {
            return true;
        }

        $path = Filesystem::normalizePath($path);
        $rootPath = Filesystem::normalizePath($rootPath);
        $relativePath = $path;

        if (str_starts_with($path, $rootPath . '/')) {
            $relativePath = substr($path, strlen($rootPath) + 1);
        } elseif ($path === $rootPath) {
            $relativePath = basename($path);
        }

        $basename = basename($path);

        foreach ($globPatterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);

            if (
                fnmatch($pattern, $basename)
                || fnmatch($pattern, $relativePath, FNM_PATHNAME)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, true>|null
     */
    private function candidateIds(AstIndex $index, Pattern $pattern): ?array
    {
        $predicate = $this->factPredicate($pattern->root);

        if ($predicate === null) {
            return null;
        }

        $candidateIds = [];

        foreach ($index->facts as $fileId => $facts) {
            if ($predicate($facts)) {
                $candidateIds[$fileId] = true;
            }
        }

        return $candidateIds;
    }

    /**
     * @return (callable(array{
     *   zero_arg_new: bool,
     *   long_array: bool,
     *   function_calls: list<string>,
     *   method_calls: list<string>,
     *   static_calls: list<string>,
     *   new_targets: list<string>,
     *   classes: list<string>,
     *   interfaces: list<string>,
     *   traits: list<string>
     * }): bool)|null
     */
    private function factPredicate(Node $root): ?callable
    {
        if ($root instanceof Expr\Array_ && $this->isLongArraySyntax($root)) {
            return static fn (array $facts): bool => $facts['long_array'];
        }

        if ($root instanceof Expr\New_ && $root->args === []) {
            $targetName = $root->class instanceof Name ? strtolower($root->class->toString()) : null;

            return static function (array $facts) use ($targetName): bool {
                if (!$facts['zero_arg_new']) {
                    return false;
                }

                if ($targetName === null) {
                    return true;
                }

                return in_array($targetName, $facts['new_targets'], true);
            };
        }

        if ($root instanceof Expr\FuncCall && $root->name instanceof Name) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['function_calls'], true);
        }

        if (($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['method_calls'], true);
        }

        if ($root instanceof Expr\StaticCall && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['static_calls'], true);
        }

        if ($root instanceof Stmt\Class_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['classes'], true);
        }

        if ($root instanceof Stmt\Interface_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['interfaces'], true);
        }

        if ($root instanceof Stmt\Trait_ && $root->name instanceof Identifier) {
            $name = strtolower($root->name->toString());

            return static fn (array $facts): bool => in_array($name, $facts['traits'], true);
        }

        return null;
    }

    private function isLongArraySyntax(Expr\Array_ $node): bool
    {
        $kind = $node->getAttribute('kind');

        if (is_int($kind)) {
            return $kind === Expr\Array_::KIND_LONG;
        }

        return property_exists($node, 'kind') && $node->kind === Expr\Array_::KIND_LONG;
    }
}
