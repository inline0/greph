<?php

declare(strict_types=1);

namespace Greph\Walker;

final class GitignoreFilter
{
    private const IGNORE_FILES = ['.gitignore', '.grephignore'];

    private string $rootPath;

    private readonly string $searchRoot;

    private bool $hasRepository = false;

    private ?string $gitDirectory = null;

    /** @var array<string, list<array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string}>> */
    private array $rulesByDirectory = [];

    /** @var array<string, true> */
    private array $loadedDirectories = [];

    /** @var array<string, list<array{rule: array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string}, directory: string}>> */
    private array $effectiveRules = [];

    /** @var array<string, array{path: string, literal: bool}> */
    private array $rulePrefixes = [];

    /** @var array<string, true> */
    private array $hiddenBasenameRules = [];

    /** @var array<string, true> */
    private array $ignoreFreeDirectories = [];

    public function __construct(string $rootPath, private readonly bool $includeHidden = true)
    {
        $this->rootPath = self::normalizePath($rootPath);
        $this->searchRoot = $this->rootPath;
        $directory = $this->rootPath;
        while (true) {
            $git = $directory . '/.git';
            if (is_dir($git) || is_file($git)) {
                $this->rootPath = $directory;
                $this->hasRepository = true;
                $this->gitDirectory = $git;
                if (is_file($git)) {
                    $link = @file_get_contents($git);
                    if ($link !== false && preg_match('/^gitdir:\s*(.+)/', $link, $match) === 1) {
                        $target = trim($match[1]);
                        $this->gitDirectory = realpath($directory . '/' . $target) ?: (realpath($target) ?: null);
                    }
                }
                if ($this->gitDirectory !== null && is_file($this->gitDirectory . '/commondir')) {
                    $common = trim((string) file_get_contents($this->gitDirectory . '/commondir'));
                    $this->gitDirectory = realpath($this->gitDirectory . '/' . $common) ?: $this->gitDirectory;
                }
                break;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }
    }

    public function shouldIgnore(string $absolutePath, bool $isDirectory): bool
    {
        $absolutePath = self::normalizePath($absolutePath);
        if (isset($this->ignoreFreeDirectories[dirname($absolutePath)])) {
            return false;
        }

        if (!$this->isWithinRoot($absolutePath)) {
            return false;
        }

        $relativePath = $this->relativePath($absolutePath);

        if ($relativePath === '') {
            return false;
        }

        $ignored = false;

        $parent = dirname($relativePath);
        if (!isset($this->effectiveRules[$parent])) {
            $this->effectiveRules[$parent] = [];
            foreach ($this->directoriesForEvaluation($relativePath) as $directory) {
                $this->loadRulesForDirectory($directory);
                foreach ($this->rulesByDirectory[$directory] ?? [] as $rule) {
                    if (!$this->ruleCanReachSearchRoot($rule, $directory)) {
                        continue;
                    }
                    $this->effectiveRules[$parent][] = ['rule' => $rule, 'directory' => $directory];
                }
            }
        }
        if ($this->effectiveRules[$parent] === []) {
            $this->ignoreFreeDirectories[dirname($absolutePath)] = true;
            return false;
        }
        foreach ($this->effectiveRules[$parent] as $entry) {
            $rule = $entry['rule'];
            if ($this->matchesRule($rule, $relativePath, $isDirectory, $entry['directory'])) {
                $ignored = !$rule['negated'];
            }
        }

        return $ignored;
    }

    private function isWithinRoot(string $absolutePath): bool
    {
        return $absolutePath === $this->rootPath || str_starts_with($absolutePath, $this->rootPath . '/');
    }

    private function relativePath(string $absolutePath): string
    {
        if ($absolutePath === $this->rootPath) {
            return '';
        }

        return ltrim(substr($absolutePath, strlen($this->rootPath)), '/');
    }

    /**
     * @return list<string>
     */
    private function directoriesForEvaluation(string $relativePath): array
    {
        $directoryPath = dirname($relativePath);

        if ($directoryPath === '.') {
            return [''];
        }

        $parts = explode('/', $directoryPath);
        $directories = [''];
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $directories[] = $current;
        }

        return $directories;
    }

    private function loadRulesForDirectory(string $directory): void
    {
        if (isset($this->loadedDirectories[$directory])) {
            return;
        }

        $directoryPath = $directory === '' ? $this->rootPath : $this->rootPath . '/' . $directory;

        if ($directory === '') {
            if ($this->hasRepository) {
                $global = GitExcludeConfig::path();
                if ($global !== null && is_file($global)) {
                    $this->loadRulesFromFile($global, '');
                }
            }
            $excludePath = ($this->gitDirectory ?? $this->rootPath . '/.git') . '/info/exclude';
            if (is_file($excludePath)) {
                $this->loadRulesFromFile($excludePath, '');
            }
        }

        foreach (self::IGNORE_FILES as $ignoreFile) {
            $path = $directoryPath . '/' . $ignoreFile;

            if (is_file($path)) {
                $this->loadRulesFromFile($path, $directory);
            }
        }

        $this->loadedDirectories[$directory] = true;
    }

    private function loadRulesFromFile(string $path, string $directory): void
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $rule = $this->parseRuleLine($line);

            if ($rule === null) {
                continue;
            }

            $this->rulesByDirectory[$directory] ??= [];
            $this->rulesByDirectory[$directory][] = $rule;
        }
    }

    /**
     * @return array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string}|null
     */
    private function parseRuleLine(string $line): ?array
    {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            return null;
        }

        if ($line[0] === '\\' && isset($line[1]) && ($line[1] === '#' || $line[1] === '!')) {
            $line = substr($line, 1);
        } elseif ($line[0] === '#') {
            return null;
        }

        $negated = false;

        if ($line !== '' && $line[0] === '!') {
            $negated = true;
            $line = substr($line, 1);
        }

        if ($line === '') {
            return null;
        }

        $directoryOnly = str_ends_with($line, '/');

        if ($directoryOnly) {
            $line = rtrim($line, '/');
        }

        $anchored = str_starts_with($line, '/');

        if ($anchored) {
            $line = ltrim($line, '/');
        }

        if ($line === '') {
            return null;
        }

        $hasSlash = $anchored || str_contains($line, '/');

        $regex = $this->globToRegex($line);
        if (!$hasSlash && str_starts_with($line, '.')) {
            $this->hiddenBasenameRules[$regex] = true;
        }
        if ($hasSlash) {
            $special = strcspn($line, '*?[\\');
            $literal = $special === strlen($line);
            $prefix = substr($line, 0, $special);
            if (!$literal) {
                $slash = strrpos($prefix, '/');
                $prefix = $slash === false ? '' : substr($prefix, 0, $slash);
            }
            if ($prefix !== '') {
                $this->rulePrefixes[$regex] = ['path' => $prefix, 'literal' => $literal];
            }
        }

        return [
            'negated' => $negated,
            'directoryOnly' => $directoryOnly,
            'hasSlash' => $hasSlash,
            'regex' => $regex,
        ];
    }

    /** @param array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string} $rule */
    private function ruleCanReachSearchRoot(array $rule, string $directory): bool
    {
        if (!$this->includeHidden && isset($this->hiddenBasenameRules[$rule['regex']])) {
            return false;
        }
        $prefix = $this->rulePrefixes[$rule['regex']] ?? null;
        if ($prefix === null) {
            return true;
        }
        $absolute = $this->rootPath . ($directory !== '' ? '/' . $directory : '') . '/' . $prefix['path'];
        if (str_starts_with($absolute, $this->searchRoot . '/')) {
            return true;
        }
        if ($absolute === $this->searchRoot || str_starts_with($this->searchRoot, $absolute . '/')) {
            return !$prefix['literal'];
        }

        return false;
    }

    /**
     * @param array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string} $rule
     */
    private function matchesRule(array $rule, string $relativePath, bool $isDirectory, string $baseDirectory): bool
    {
        if ($baseDirectory === '') {
            $relativeToRuleDirectory = $relativePath;
        } elseif ($relativePath === $baseDirectory) {
            $relativeToRuleDirectory = '';
        } elseif (str_starts_with($relativePath, $baseDirectory . '/')) {
            $relativeToRuleDirectory = substr($relativePath, strlen($baseDirectory) + 1);
        } else {
            return false;
        }

        if ($relativeToRuleDirectory === '') {
            return false;
        }

        $ruleDirectory = $baseDirectory === '' ? $this->rootPath : $this->rootPath . '/' . $baseDirectory;
        $searchPrefix = str_starts_with($this->searchRoot, $ruleDirectory . '/')
            ? substr($this->searchRoot, strlen($ruleDirectory) + 1)
            : '';


        if ($rule['hasSlash']) {
            $candidates = $rule['directoryOnly']
                ? $this->directoryPrefixes($relativeToRuleDirectory, $isDirectory)
                : [$relativeToRuleDirectory];

            foreach ($candidates as $candidate) {
                // Explicit search roots bypass ignores on that root and its ancestors.
                if ($rule['directoryOnly'] && ($candidate === $searchPrefix || str_starts_with($searchPrefix, $candidate . '/'))) {
                    continue;
                }
                if (preg_match($rule['regex'], $candidate) === 1) {
                    return true;
                }
            }

            return false;
        }

        $components = explode('/', $relativeToRuleDirectory);
        if ($searchPrefix !== '') {
            $components = array_slice($components, count(explode('/', $searchPrefix)));
        }

        if ($rule['directoryOnly']) {
            $components = $isDirectory ? $components : array_slice($components, 0, -1);
        }

        foreach ($components as $component) {
            if (preg_match($rule['regex'], $component) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function directoryPrefixes(string $relativePath, bool $isDirectory): array
    {
        $parts = explode('/', $relativePath);

        if (!$isDirectory) {
            array_pop($parts);
        }

        $prefixes = [];
        $current = '';

        foreach ($parts as $part) {
            $current = $current === '' ? $part : $current . '/' . $part;
            $prefixes[] = $current;
        }

        return $prefixes;
    }

    private function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === '\\' && isset($pattern[$index + 1])) {
                $index++;
                $regex .= preg_quote($pattern[$index], '#');
                continue;
            }

            if ($character === '*') {
                $isDoubleStar = isset($pattern[$index + 1]) && $pattern[$index + 1] === '*';

                if ($isDoubleStar) {
                    $index++;

                    if (isset($pattern[$index + 1]) && $pattern[$index + 1] === '/') {
                        $index++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }

                    continue;
                }

                $regex .= '[^/]*';
                continue;
            }

            if ($character === '?') {
                $regex .= '[^/]';
                continue;
            }

            if ($character === '[') {
                $closingBracket = strpos($pattern, ']', $index + 1);

                if ($closingBracket === false) {
                    $regex .= '\[';
                    continue;
                }

                $class = substr($pattern, $index + 1, $closingBracket - $index - 1);
                $negated = '';

                if ($class !== '' && ($class[0] === '!' || $class[0] === '^')) {
                    $negated = '^';
                    $class = substr($class, 1);
                }

                $class = str_replace('\\', '\\\\', $class);
                $class = str_replace(']', '\]', $class);

                $regex .= '[' . $negated . $class . ']';
                $index = $closingBracket;

                continue;
            }

            $regex .= preg_quote($character, '#');
        }

        return '#^' . $regex . '$#';
    }

    private static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
