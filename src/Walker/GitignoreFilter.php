<?php

declare(strict_types=1);

namespace Greph\Walker;

final class GitignoreFilter
{
    private const IGNORE_FILES = ['.gitignore', '.grephignore'];

    private string $rootPath;

    /** @var array<string, list<array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string}>> */
    private array $rulesByDirectory = [];

    /** @var array<string, true> */
    private array $loadedDirectories = [];

    public function __construct(string $rootPath)
    {
        $this->rootPath = self::normalizePath($rootPath);
    }

    public function shouldIgnore(string $absolutePath, bool $isDirectory): bool
    {
        $absolutePath = self::normalizePath($absolutePath);

        if (!$this->isWithinRoot($absolutePath)) {
            return false;
        }

        $relativePath = $this->relativePath($absolutePath);

        if ($relativePath === '') {
            return false;
        }

        $ignored = false;

        foreach ($this->directoriesForEvaluation($relativePath) as $directory) {
            $this->loadRulesForDirectory($directory);

            foreach ($this->rulesByDirectory[$directory] ?? [] as $rule) {
                if ($this->matchesRule($rule, $relativePath, $isDirectory, $directory)) {
                    $ignored = !$rule['negated'];
                }
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

        foreach (self::IGNORE_FILES as $ignoreFile) {
            $path = $directoryPath . '/' . $ignoreFile;

            if (is_file($path)) {
                $this->loadRulesFromFile($path, $directory);
            }
        }

        if ($directory === '') {
            $excludePath = $this->rootPath . '/.git/info/exclude';

            if (is_file($excludePath)) {
                $this->loadRulesFromFile($excludePath, '');
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

        return [
            'negated' => $negated,
            'directoryOnly' => $directoryOnly,
            'hasSlash' => $hasSlash,
            'regex' => $this->globToRegex($line),
        ];
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

        if ($rule['hasSlash']) {
            $candidates = $rule['directoryOnly']
                ? $this->directoryPrefixes($relativeToRuleDirectory, $isDirectory)
                : [$relativeToRuleDirectory];

            foreach ($candidates as $candidate) {
                if (preg_match($rule['regex'], $candidate) === 1) {
                    return true;
                }
            }

            return false;
        }

        $components = explode('/', $relativeToRuleDirectory);

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
