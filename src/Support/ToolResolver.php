<?php

declare(strict_types=1);

namespace Phgrep\Support;

final class ToolResolver
{
    /**
     * @return list<string>
     */
    public function grep(): array
    {
        return [$this->requireBinary(['grep'])];
    }

    /**
     * @return list<string>
     */
    public function ripgrep(): array
    {
        return [$this->requireBinary(['rg'])];
    }

    /**
     * @return list<string>
     */
    public function astGrep(): array
    {
        $binary = $this->findBinary(['sg', 'ast-grep']);

        if ($binary !== null) {
            return [$binary];
        }

        $npm = $this->findBinary(['npm']);

        if ($npm === null) {
            throw new \RuntimeException('Unable to find ast-grep. Install sg or npm.');
        }

        return [$npm, 'exec', '--yes', '--package=@ast-grep/cli', 'sg', '--'];
    }

    /**
     * @return list<string>
     */
    public function phpBinary(): array
    {
        return [PHP_BINARY];
    }

    /**
     * @return list<string>
     */
    public function phgrep(string $rootPath): array
    {
        return [PHP_BINARY, $rootPath . '/bin/phgrep'];
    }

    public function hasAstGrep(): bool
    {
        try {
            $this->astGrep();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * @param list<string> $candidates
     */
    private function requireBinary(array $candidates): string
    {
        $binary = $this->findBinary($candidates);

        if ($binary === null) {
            throw new \RuntimeException(sprintf('Unable to resolve required binary: %s', implode(', ', $candidates)));
        }

        return $binary;
    }

    /**
     * @param list<string> $candidates
     */
    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($candidate)));

            if (is_string($path)) {
                $path = trim($path);

                if ($path !== '') {
                    return $path;
                }
            }
        }

        return null;
    }
}
