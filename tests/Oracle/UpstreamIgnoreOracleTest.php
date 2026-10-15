<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

use Greph\Support\CommandRunner;
use Greph\Support\ToolResolver;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpstreamIgnoreOracleTest extends TestCase
{
    #[Test]
    public function multipleRootsAndGlobalConfigMatchRipgrep152(): void
    {
        $workspace = Workspace::createDirectory('rg152-oracle');
        $runner = new CommandRunner();
        $reference = (new ToolResolver())->ripgrep();
        Workspace::writeFile($workspace, '.git/HEAD', "ref: refs/heads/main\n");
        Workspace::writeFile($workspace, 'global.ignore', "global.txt\n");
        Workspace::writeFile($workspace, 'gitconfig', "[core]\n excludesFile = {$workspace}/global.ignore\n");
        foreach (['a/keep.txt', 'a/drop.cache', 'a/global.txt', 'b/keep.txt', 'b/drop.cache'] as $file) {
            Workspace::writeFile($workspace, $file, "needle\n");
        }
        $environment = ['GIT_CONFIG_GLOBAL' => $workspace . '/gitconfig'];
        $actualCommand = [PHP_BINARY, dirname(__DIR__, 2) . '/bin/rg'];
        try {
            foreach (["a/\n", "*.cache\n", "a/\n*.cache\n", "/a/\n", "a/**/keep.txt\n"] as $rules) {
                Workspace::writeFile($workspace, '.gitignore', $rules);
                foreach ([['a'], ['a', 'b'], ['.']] as $roots) {
                    foreach ([[], ['--no-ignore']] as $flags) {
                        $arguments = ['--files', ...$flags, ...$roots];
                        $expected = $runner->run([...$reference, ...$arguments], $workspace, $environment);
                        $actual = $runner->run([...$actualCommand, ...$arguments], $workspace, $environment);
                        self::assertSame($expected->exitCode, $actual->exitCode, $actual->stderr);
                        $expectedLines = preg_split('/\r?\n/', trim($expected->stdout)) ?: [];
                        $actualLines = preg_split('/\r?\n/', trim($actual->stdout)) ?: [];
                        sort($expectedLines);
                        sort($actualLines);
                        self::assertSame($expectedLines, $actualLines, $rules . json_encode($arguments));
                    }
                }
            }
        } finally {
            Workspace::remove($workspace);
        }
    }
}
