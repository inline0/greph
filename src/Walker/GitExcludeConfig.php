<?php

declare(strict_types=1);

namespace Greph\Walker;

/** Global ignore discovery, following ripgrep 15.2's ignore crate. */
final class GitExcludeConfig
{
    public static function path(): ?string
    {
        $home = getenv('HOME');
        $xdg = getenv('XDG_CONFIG_HOME');
        $xdg = $xdg !== false && $xdg !== '' ? $xdg : ($home !== false ? $home . '/.config' : null);
        $global = getenv('GIT_CONFIG_GLOBAL');
        $system = getenv('GIT_CONFIG_SYSTEM');
        $paths = [
            $global !== false && $global !== '' ? $global : null,
            $home !== false ? $home . '/.gitconfig' : null,
            $xdg !== null ? $xdg . '/git/config' : null,
            $system !== false && $system !== '' ? $system : '/etc/gitconfig',
        ];
        return self::fromFiles($paths, $home !== false ? $home : null, $xdg !== null ? $xdg . '/git/ignore' : null);
    }

    /**
     * Read config files in precedence order, as the ignore crate does.
     *
     * @param list<string|null> $paths
     */
    public static function fromFiles(array $paths, ?string $home, ?string $fallback): ?string
    {
        foreach ($paths as $path) {
            if ($path === null) {
                continue;
            }
            $contents = @file_get_contents($path);
            if ($contents === false || preg_match('/^\s*excludesfile\s*=\s*"?\s*(\S+?)\s*"?\s*$/im', $contents, $match) !== 1) {
                continue;
            }

            return $home !== null ? str_replace('~', $home, $match[1]) : $match[1];
        }

        return $fallback;
    }
}
