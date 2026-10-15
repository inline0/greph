<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Support;

use Greph\Support\SourceWriter;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SourceWriterTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('atomic-source');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itPreservesPermissionsAndFollowsSourceSymlinks(): void
    {
        $file = Workspace::writeFile($this->workspace, 'source.php', 'old');
        chmod($file, 0751);
        $link = $this->workspace . '/link.php';
        symlink('source.php', $link);
        SourceWriter::replace($link, 'replacement');
        clearstatcache();
        self::assertSame('replacement', file_get_contents($file));
        self::assertTrue(is_link($link));
        self::assertSame(0751, fileperms($file) & 0777);
        self::assertSame([], glob($this->workspace . '/.greph-rewrite-*'));
        SourceWriter::replace($file, '');
        self::assertSame('', file_get_contents($file));
    }

    #[Test]
    public function itRejectsReadOnlySourcesWithoutReplacingThem(): void
    {
        $file = Workspace::writeFile($this->workspace, 'readonly.php', 'original');
        chmod($file, 0444);
        if (is_writable($file)) {
            self::markTestSkipped('Privileged user can write read-only files.');
        }
        try {
            SourceWriter::replace($file, 'replacement');
            self::fail('Read-only source accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Cannot rewrite file', $error->getMessage());
            self::assertSame('original', file_get_contents($file));
        } finally {
            chmod($file, 0644);
        }
    }

    #[Test]
    public function itPreservesSourceWhenTheReplacementWriteFails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Requires POSIX file-size resource limits.');
        }
        $file = Workspace::writeFile($this->workspace, 'limited.php', 'original');
        $script = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . 'Greph\\Support\\SourceWriter::replace(' . var_export($file, true) . ', "replacement");';
        $process = proc_open(
            ['/bin/sh', '-c', 'ulimit -f 0; exec "$@"', 'sh', PHP_BINARY, '-d', 'display_errors=0', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($process));
        self::assertSame('original', file_get_contents($file));
    }
}
