<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Walker;

use Greph\Tests\Support\Workspace;
use Greph\Walker\GitignoreFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GitignoreFilterTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::createDirectory('gitignore-filter');
    }

    protected function tearDown(): void
    {
        Workspace::remove($this->workspace);
    }

    #[Test]
    public function itLoadsRootIgnoreFilesAndNegationRules(): void
    {
        Workspace::writeFile($this->workspace, '.gitignore', "*.log\n!important.log\nvendor/\n");
        Workspace::writeFile($this->workspace, '.grephignore', "cache/\n");
        Workspace::writeFile($this->workspace, '.git/info/exclude', "local.php\n");

        $filter = new GitignoreFilter($this->workspace);

        $this->assertTrue($filter->shouldIgnore($this->workspace . '/debug.log', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/important.log', false));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/vendor', true));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/vendor/lib.php', false));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/cache', true));
        $this->assertTrue($filter->shouldIgnore($this->workspace . '/local.php', false));
    }

    #[Test]
    public function itAppliesNestedIgnoreFilesRelativeToTheirDirectory(): void
    {
        Workspace::writeFile($this->workspace, 'src/.gitignore', "*.cache\n!keep.cache\n");

        $filter = new GitignoreFilter($this->workspace);

        $this->assertTrue($filter->shouldIgnore($this->workspace . '/src/data.cache', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/src/keep.cache', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace . '/other/data.cache', false));
    }

    #[Test]
    public function itExercisesRuleParsingMatchingAndGlobInternals(): void
    {
        Workspace::writeFile($this->workspace, 'mixed.ignore', "\n# comment\nvalid.php\n");

        $filter = new GitignoreFilter($this->workspace);

        $this->assertFalse($filter->shouldIgnore('/tmp/outside.txt', false));
        $this->assertFalse($filter->shouldIgnore($this->workspace, true));

        $this->invokePrivate($filter, 'loadRulesFromFile', $this->workspace . '/missing.ignore', '');
        $this->invokePrivate($filter, 'loadRulesFromFile', $this->workspace . '/mixed.ignore', '');

        /** @var array<string, list<array{negated: bool, directoryOnly: bool, hasSlash: bool, regex: string}>> $rules */
        $rules = $this->readPrivateProperty($filter, 'rulesByDirectory');

        $this->assertCount(1, $rules['']);
        $this->assertNull($this->invokePrivate($filter, 'parseRuleLine', ''));
        $this->assertNull($this->invokePrivate($filter, 'parseRuleLine', '# comment'));
        $this->assertNull($this->invokePrivate($filter, 'parseRuleLine', '!'));
        $this->assertNull($this->invokePrivate($filter, 'parseRuleLine', '/'));
        $this->assertSame(
            [
                'negated' => false,
                'directoryOnly' => false,
                'hasSlash' => false,
                'regex' => '#^\\#literal$#',
            ],
            $this->invokePrivate($filter, 'parseRuleLine', '\#literal'),
        );
        $this->assertSame(
            [
                'negated' => true,
                'directoryOnly' => false,
                'hasSlash' => false,
                'regex' => '#^literal$#',
            ],
            $this->invokePrivate($filter, 'parseRuleLine', '\!literal'),
        );

        $anchoredRule = $this->invokePrivateArray($filter, 'parseRuleLine', '/foo');
        $directoryRule = $this->invokePrivateArray($filter, 'parseRuleLine', 'build/');
        $nestedDirectoryRule = $this->invokePrivateArray($filter, 'parseRuleLine', 'src/build/');
        $slashRule = $this->invokePrivateArray($filter, 'parseRuleLine', 'nested/file?.[!o]');

        $this->assertTrue($anchoredRule['hasSlash']);
        $this->assertTrue($directoryRule['directoryOnly']);
        $this->assertTrue($this->invokePrivate($filter, 'matchesRule', $slashRule, 'nested/file1.c', false, ''));
        $this->assertFalse($this->invokePrivate($filter, 'matchesRule', $anchoredRule, 'src.php', false, 'src'));
        $this->assertFalse($this->invokePrivate($filter, 'matchesRule', $directoryRule, 'build', true, 'build'));
        $this->assertFalse($this->invokePrivate($filter, 'matchesRule', $nestedDirectoryRule, 'src/other/file.php', false, ''));
        $this->assertFalse($this->invokePrivate($filter, 'matchesRule', $directoryRule, 'src/file.php', false, ''));
        $this->assertTrue($this->invokePrivate($filter, 'matchesRule', $directoryRule, 'src/build/output.php', false, ''));
        $this->assertSame(['src', 'src/build'], $this->invokePrivate($filter, 'directoryPrefixes', 'src/build/output.php', false));
        $this->assertSame(['src', 'src/build'], $this->invokePrivate($filter, 'directoryPrefixes', 'src/build', true));

        $doubleStarDirectoryRegex = $this->invokePrivateString($filter, 'globToRegex', '**/file?.[!o]');
        $doubleStarRegex = $this->invokePrivateString($filter, 'globToRegex', 'foo**bar');
        $escapedRegex = $this->invokePrivateString($filter, 'globToRegex', '\*.txt');
        $unclosedRegex = $this->invokePrivateString($filter, 'globToRegex', 'file[');

        $this->assertSame(1, preg_match($doubleStarDirectoryRegex, 'src/file1.c'));
        $this->assertSame(1, preg_match($doubleStarRegex, 'foo/bar'));
        $this->assertSame(1, preg_match($escapedRegex, '*.txt'));
        $this->assertSame(1, preg_match($unclosedRegex, 'file['));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function invokePrivateArray(object $object, string $method, mixed ...$arguments): array
    {
        $result = $this->invokePrivate($object, $method, ...$arguments);
        self::assertIsArray($result);

        return $result;
    }

    private function invokePrivateString(object $object, string $method, mixed ...$arguments): string
    {
        $result = $this->invokePrivate($object, $method, ...$arguments);
        self::assertIsString($result);

        return $result;
    }

    private function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }
}
