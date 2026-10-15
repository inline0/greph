<?php

declare(strict_types=1);

namespace Greph\Cli;

use Greph\Ast\AstSearchOptions;
use Greph\Ast\OutlineExtractor;
use Greph\Greph;
use Greph\Support\Filesystem;

/**
 * ast-grep's PHP outline command, backed by the native PHP parser.
 *
 * @phpstan-import-type Item from OutlineExtractor
 * @phpstan-import-type Member from OutlineExtractor
 */
final class OutlineApplication
{
    /**
     * @param resource $output
     * @param resource|null $input
     */
    public function __construct(private $output, private $input = null)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if (in_array('--help', $arguments, true) || in_array('-h', $arguments, true)) {
            fwrite($this->output, "Usage: sg outline [options] [path...]\n"
                . "  --lang php                   PHP source outlines; required with --stdin.\n"
                . "  --json[=pretty|compact|stream] Structured output.\n"
                . "  --view names|signatures|digest|expanded\n"
                . "  --items structure|exports|imports|all\n"
                . "  --type class,enum  --match REGEX  --pub-members\n");

            return 0;
        }
        $stdin = false;
        $languageProvided = false;
        $paths = [];
        $view = 'auto';
        $items = 'auto';
        $json = null;
        $types = [];
        $match = null;
        $publicOnly = false;
        $hidden = false;
        $ignore = true;
        $follow = false;
        $globs = [];
        $defaults = true;
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if ($argument === '--json' || str_starts_with($argument, '--json=')) {
                $json = $argument === '--json' ? 'pretty' : substr($argument, 7);
            } elseif (in_array($argument, ['--view', '--items', '--match', '--type', '--lang', '-l', '--color', '--globs', '--glob', '-j', '--threads'], true)) {
                $value = array_shift($arguments) ?? throw new \InvalidArgumentException('Missing value for ' . $argument);
                $languageProvided = $languageProvided || in_array($argument, ['--lang', '-l'], true);
                match ($argument) {
                    '--view' => $view = $value,
                    '--items' => $items = $value,
                    '--match' => $match = $value,
                    '--type' => $types = [...$types, ...explode(',', $value)],
                    '--glob', '--globs' => $globs[] = $value,
                    '--lang', '-l' => strtolower($value) === 'php' ?: throw new \InvalidArgumentException('Outline supports PHP.'),
                    default => null,
                };
            } elseif ($argument === '--stdin') {
                $stdin = true;
            } elseif ($argument === '--pub-members') {
                $publicOnly = true;
            } elseif ($argument === '--no-default-outline-rules') {
                $defaults = false;
            } elseif ($argument === '--follow') {
                $follow = true;
            } elseif ($argument === '--no-ignore') {
                $kind = array_shift($arguments) ?? throw new \InvalidArgumentException('Missing --no-ignore type.');
                $hidden = $hidden || $kind === 'hidden';
                $ignore = $kind === 'hidden' ? $ignore : false;
            } elseif (str_starts_with($argument, '-')) {
                throw new \InvalidArgumentException('Unsupported outline option: ' . $argument);
            } else {
                $paths[] = $argument;
            }
        }
        if (
            !in_array($view, ['auto', 'names', 'signatures', 'digest', 'expanded'], true)
            || !in_array($items, ['auto', 'structure', 'exports', 'imports', 'all'], true)
            || ($json !== null && !in_array($json, ['pretty', 'compact', 'stream'], true))
        ) {
            throw new \InvalidArgumentException('Invalid outline view, items, or JSON style.');
        }
        if ($match !== null && @preg_match('~' . str_replace('~', '\\~', $match) . '~u', '') === false) {
            throw new \InvalidArgumentException('Invalid outline match expression.');
        }
        if ($stdin && !$languageProvided) {
            throw new \InvalidArgumentException('--stdin requires --lang php.');
        }
        $paths = $stdin ? [] : ($paths ?: ['.']);
        $directoryInput = false;
        foreach ($paths as $path) {
            $directoryInput = $directoryInput || is_dir($path);
        }
        $view = $view === 'auto' ? ($directoryInput ? 'names' : 'digest') : $view;
        $items = $items === 'auto' ? ($directoryInput ? 'exports' : 'structure') : $items;
        $stdinSource = $stdin ? (string) stream_get_contents($this->input ?? STDIN) : '';
        $files = $stdin ? [['path' => 'STDIN', 'language' => 'Php', 'items' => (new OutlineExtractor())->extract(
            $stdinSource,
            $view === 'expanded',
        )]] : Greph::outline($paths, new AstSearchOptions(
            respectIgnore: $ignore,
            includeHidden: $hidden,
            followSymlinks: $follow,
            globPatterns: $globs,
        ), includeMemberSignatures: $view === 'expanded');
        $explicitFiles = $stdin ? ['STDIN'] : [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $resolved = realpath($path);
                if ($resolved !== false) {
                    $explicitFiles[] = $resolved;
                }
            }
        }
        // ast-grep's PHP language glob is *.php; explicit files may use any PHP extension.
        $files = array_values(array_filter($files, static fn (array $file): bool =>
            in_array($file['path'], $explicitFiles, true) || strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) === 'php'));
        $retainedFiles = [];
        foreach ($files as $file) {
            $explicit = in_array($file['path'], $explicitFiles, true);
            $display = Filesystem::relativePath(getcwd() ?: '.', $file['path']);
            foreach ($paths as $path) {
                $root = realpath($path);
                if ($root !== false && ($file['path'] === $root || str_starts_with($file['path'], $root . '/'))) {
                    $display = ($path === '/' ? '/' : rtrim($path, '/')) . substr($file['path'], strlen($root));
                    break;
                }
            }
            $file['path'] = $display;
            $filtered = [];
            foreach ($defaults ? $file['items'] : [] as $item) {
                if (
                    ($items === 'structure' && $item['isImport'])
                    || ($items === 'exports' && !$item['isExported'])
                    || ($items === 'imports' && !$item['isImport'])
                    || ($types !== [] && !in_array($item['symbolType'], $types, true))
                ) {
                    continue;
                }
                if ($match !== null) {
                    $result = 0;
                    foreach ([$item['name'], $item['signature']] as $subject) {
                        $matched = @preg_match('~' . str_replace('~', '\\~', $match) . '~u', $subject);
                        if ($matched === false) {
                            throw new \InvalidArgumentException('Invalid outline match expression.');
                        }
                        $result |= $matched;
                    }
                    if ($result === 0) {
                        continue;
                    }
                }
                if ($publicOnly && isset($item['members'])) {
                    $item['members'] = array_values(array_filter($item['members'], static fn (array $member): bool => $member['isPublic']));
                    if ($item['members'] === []) {
                        unset($item['members']);
                    }
                }
                if ($view === 'names') {
                    $item['signature'] = '';
                    unset($item['members']);
                } elseif ($view === 'signatures') {
                    unset($item['members']);
                }
                $filtered[] = $item;
            }
            $file['items'] = $filtered;
            if ($filtered !== [] || $explicit) {
                $retainedFiles[] = $file;
            }
        }
        $files = $retainedFiles;
        if ($json !== null) {
            if ($json === 'stream') {
                foreach ($files as $file) {
                    fwrite($this->output, json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                }
            } else {
                fwrite($this->output, json_encode($files, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($json === 'pretty' ? JSON_PRETTY_PRINT : 0)) . "\n");
            }
        } else {
            $texts = [];
            foreach ($files as $file) {
                $texts[] = $this->text($file['path'], $file['items'], $view);
            }
            fwrite($this->output, $texts === [] ? "nothing found\n" : implode("\n", $texts));
        }

        return 0;
    }

    /** @param list<Item> $items */
    private function text(string $path, array $items, string $view): string
    {
        $output = $path . "\n";
        if ($items === []) {
            return $output . "nothing found\n";
        }
        if ($view === 'names') {
            return $output . $this->groups($items);
        }
        $maxLine = 1;
        foreach ($items as $item) {
            $maxLine = max($maxLine, $item['range']['start']['line'] + 1);
            foreach ($item['members'] ?? [] as $member) {
                $maxLine = max($maxLine, $member['range']['start']['line'] + 1);
            }
        }
        $width = strlen((string) $maxLine);
        foreach ($items as $item) {
            $output .= str_pad((string) ($item['range']['start']['line'] + 1), $width, ' ', STR_PAD_LEFT) . ': ' . ($item['signature'] ?: $item['name']) . "\n";
            if ($view === 'digest') {
                $members = $item['members'] ?? [];
                $members = [...array_filter($members, static fn (array $member): bool => $member['isPublic']),
                    ...array_filter($members, static fn (array $member): bool => !$member['isPublic'])];
                $output .= $this->groups($members, str_repeat(' ', $width + 4));
            } elseif ($view === 'expanded') {
                foreach ($item['members'] ?? [] as $member) {
                    $output .= str_pad((string) ($member['range']['start']['line'] + 1), $width, ' ', STR_PAD_LEFT) . ':   ' . ($member['signature'] ?: $member['name']) . "\n";
                }
            }
        }

        return $output;
    }

    /** @param list<Item>|list<Member> $entries */
    private function groups(array $entries, string $indent = ''): string
    {
        $groups = [];
        foreach ($entries as $entry) {
            $groups[$entry['symbolType']][$entry['name']] = ($groups[$entry['symbolType']][$entry['name']] ?? 0) + 1;
        }
        $output = '';
        foreach (['module', 'class', 'enum', 'interface', 'function', 'method', 'property', 'enumMember'] as $type) {
            if (!isset($groups[$type])) {
                continue;
            }
            $names = [];
            foreach ($groups[$type] as $name => $count) {
                $names[] = $name . ($count > 1 ? ' ×' . $count : '');
            }
            $output .= $indent . $type . ': ' . implode(', ', $names) . "\n";
        }

        return $output;
    }
}
