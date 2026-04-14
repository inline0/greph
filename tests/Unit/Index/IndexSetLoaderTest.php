<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Index\IndexLifecycleProfile;
use Greph\Index\IndexMode;
use Greph\Index\IndexSetLoader;
use Greph\Tests\Support\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IndexSetLoaderTest extends TestCase
{
    private string $workspace;

    private string $originalWorkingDirectory;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('index-set-loader');
        $this->originalWorkingDirectory = getcwd() ?: '.';
        chdir($this->workspace);
    }

    protected function tearDown(): void
    {
        chdir($this->originalWorkingDirectory);
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itLoadsNamedEntriesAndResolvesDefaultIndexLocations(): void
    {
        Workspace::writeFile($this->workspace, 'core/.keep', '');
        Workspace::writeFile($this->workspace, 'plugins/demo/.keep', '');
        Workspace::writeFile(
            $this->workspace,
            '.greph-index-set.json',
            (string) json_encode([
                'name' => 'wordpress-local',
                'indexes' => [
                    [
                        'name' => 'plugin-text',
                        'root' => 'plugins/demo',
                        'mode' => 'text',
                        'lifecycle' => 'opportunistic-refresh',
                        'max_changed_files' => 7,
                        'max_changed_bytes' => 2048,
                        'priority' => 20,
                    ],
                    [
                        'name' => 'core-ast',
                        'root' => 'core',
                        'mode' => 'ast-index',
                        'index_dir' => 'var/core-ast-index',
                        'lifecycle' => 'static',
                        'priority' => 10,
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $set = (new IndexSetLoader())->load();
        $entries = $set->entries();

        $this->assertSame('wordpress-local', $set->name);
        $this->assertSame(IndexMode::Text, $entries[0]->mode);
        $this->assertSame(IndexLifecycleProfile::OpportunisticRefresh, $entries[0]->lifecycle->profile);
        $this->assertSame(7, $entries[0]->lifecycle->maxChangedFiles);
        $this->assertSame(2048, $entries[0]->lifecycle->maxChangedBytes);
        $this->assertSame($this->workspace . '/plugins/demo', $entries[0]->rootPath);
        $this->assertSame($this->workspace . '/plugins/demo/.greph-index', $entries[0]->indexPath);
        $this->assertSame($this->workspace . '/var/core-ast-index', $entries[1]->indexPath);
        $this->assertSame(IndexMode::AstIndex, $entries[1]->mode);
    }

    #[Test]
    public function itRejectsInvalidModesAndDuplicateNames(): void
    {
        Workspace::writeFile(
            $this->workspace,
            'invalid.json',
            (string) json_encode([
                'indexes' => [
                    ['name' => 'dup', 'root' => 'core', 'mode' => 'text'],
                    ['name' => 'dup', 'root' => 'plugins/demo', 'mode' => 'bogus'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate entry name');

        (new IndexSetLoader())->load('invalid.json');
    }
}
