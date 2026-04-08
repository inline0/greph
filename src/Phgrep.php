<?php

declare(strict_types=1);

namespace Phgrep;

use Phgrep\Walker\FileList;
use Phgrep\Walker\FileWalker;
use Phgrep\Walker\WalkOptions;

final class Phgrep
{
    /**
     * @param string|list<string> $paths
     */
    public static function walk(string|array $paths, ?WalkOptions $options = null): FileList
    {
        return (new FileWalker())->walk($paths, $options);
    }
}
