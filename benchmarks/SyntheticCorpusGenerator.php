<?php

declare(strict_types=1);

namespace Phgrep\Benchmarks;

use Phgrep\Support\Filesystem;

final class SyntheticCorpusGenerator
{
    public function __construct(private readonly string $rootPath)
    {
    }

    public function ensure(): void
    {
        $this->generateManyFiles($this->rootPath . '/1k-files', 1000);
        $this->generateManyFiles($this->rootPath . '/10k-files', 10000);
        $this->generateSingleLargeFile($this->rootPath . '/100k-lines-single', 100000);
    }

    private function generateManyFiles(string $directory, int $fileCount): void
    {
        if (is_file($directory . '/000/file-00000.php')) {
            return;
        }

        Filesystem::remove($directory);
        Filesystem::ensureDirectory($directory);

        for ($index = 0; $index < $fileCount; $index++) {
            $bucket = sprintf('%03d', (int) floor($index / 100));
            $path = sprintf('%s/%s/file-%05d.php', $directory, $bucket, $index);
            Filesystem::ensureDirectory(dirname($path));
            $contents = <<<PHP
<?php

function synthetic_{$index}(): void
{
    \$item = array({$index}, {$index} + 1, {$index} + 2);
    \$service = new SyntheticService{$index}();
    foo(\$item, \$service);
}

PHP;
            file_put_contents($path, $contents);
        }
    }

    private function generateSingleLargeFile(string $directory, int $lineCount): void
    {
        $path = $directory . '/large.php';

        if (is_file($path)) {
            return;
        }

        Filesystem::remove($directory);
        Filesystem::ensureDirectory($directory);

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to create corpus file: %s', $path));
        }

        fwrite($handle, "<?php\n\n");

        for ($index = 0; $index < $lineCount; $index++) {
            fwrite($handle, sprintf("\$values[%d] = array(%d, %d, %d);\n", $index, $index, $index + 1, $index + 2));
        }

        fclose($handle);
    }
}
