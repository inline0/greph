<?php

declare(strict_types=1);

namespace Greph\Support;

/** Replace rewritten source only after the entire replacement is on disk. */
final class SourceWriter
{
    public static function replace(string $path, string $contents): void
    {
        $target = realpath($path);
        if ($target === false || !is_file($target)) {
            throw new \RuntimeException(sprintf('Cannot rewrite file %s: source does not exist.', $path));
        }
        // Opening without truncation checks the same write permission as upstream.
        $source = @fopen($target, 'cb');
        if ($source === false) {
            throw new \RuntimeException(sprintf('Cannot rewrite file %s: source is not writable.', $path));
        }
        $stat = fstat($source);
        fclose($source);
        if ($stat === false) {
            throw new \RuntimeException(sprintf('Cannot rewrite file %s: cannot read permissions.', $path));
        }

        $temporary = @tempnam(dirname($target), '.greph-rewrite-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Cannot rewrite file %s: cannot create replacement.', $path));
        }
        try {
            if (dirname($temporary) !== dirname($target)) {
                throw new \RuntimeException(sprintf('Cannot rewrite file %s: cannot create replacement beside source.', $path));
            }
            $stream = @fopen($temporary, 'wb');
            if ($stream === false) {
                throw new \RuntimeException(sprintf('Cannot rewrite file %s: cannot open replacement.', $path));
            }
            try {
                $offset = 0;
                $length = strlen($contents);
                while ($offset < $length) {
                    $written = @fwrite($stream, substr($contents, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException(sprintf('Cannot rewrite file %s: replacement write failed.', $path));
                    }
                    $offset += $written;
                }
                if (!fflush($stream)) {
                    throw new \RuntimeException(sprintf('Cannot rewrite file %s: replacement flush failed.', $path));
                }
            } finally {
                fclose($stream);
            }
            if (!@chmod($temporary, $stat['mode'] & 07777) || !@rename($temporary, $target)) {
                throw new \RuntimeException(sprintf('Cannot rewrite file %s: replacement failed.', $path));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
