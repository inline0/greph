<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Text;

use Greph\Text\LiteralExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LiteralExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsTheLongestLiteralSegmentFromRegexPatterns(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame('->save(', $extractor->extract('\$\w+->save\('));
        $this->assertSame('function', $extractor->extract('^function\s+[a-z_]+'));
        $this->assertNull($extractor->extract('^.*$'));
    }

    #[Test]
    public function itExtractsSortedUniqueLiteralSegments(): void
    {
        $extractor = new LiteralExtractor();
        $segments = array_values(array_filter(
            $extractor->extractSegments('\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)'),
            static fn (string $segment): bool => strlen($segment) >= 2,
        ));

        $this->assertSame(
            [' = new ', '()'],
            $segments,
        );
        $this->assertSame(
            ['array('],
            array_values(array_filter(
                $extractor->extractSegments('array\([^)]+\)'),
                static fn (string $segment): bool => strlen($segment) >= 3,
            )),
        );
    }

    #[Test]
    public function itOnlyExtractsLiteralsThatEveryMatchMustContain(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame([], $extractor->extractSegments('alpha|nothing'));
        $this->assertSame([], $extractor->extractSegments('needle|'));
        $this->assertNull($extractor->extract('alpha|nothing'));
        $this->assertSame(['function ', 'end'], $extractor->extractSegments('function (alpha|beta)end'));
        $this->assertSame(['tail'], $extractor->extractSegments('(?:alpha|beta)+tail'));
        $this->assertSame(['inner', 'pre', 'end'], $extractor->extractSegments('pre(?:inner)end'));
        $this->assertSame(['pre', 'end'], $extractor->extractSegments('pre(?:optional)?end'));
        $this->assertSame(['pre', 'end'], $extractor->extractSegments('pre(?<name>[a-z]+)end'));
        $this->assertSame(['pre', 'end'], $extractor->extractSegments('pre(?=look)end'));
        $this->assertSame(['pre', 'end'], $extractor->extractSegments('pre(?<!behind)end'));
        $this->assertSame(['colo', 'r'], $extractor->extractSegments('colou?r'));
        $this->assertSame(['colo', 'r'], $extractor->extractSegments('colou*r'));
        $this->assertSame(['colou', 'r'], $extractor->extractSegments('colou+r'));
        $this->assertSame(['colou', 'r'], $extractor->extractSegments('colou{2}r'));
        $this->assertSame(['colo', 'r'], $extractor->extractSegments('colou{0,2}r'));
        $this->assertSame(['colo', 'r'], $extractor->extractSegments('colou{,2}?r'));
        $this->assertSame(['caf'], $extractor->extractSegments('café?'));
        $this->assertSame(['café'], $extractor->extractSegments('café+'));
        $this->assertSame([], $extractor->extractSegments('\d{3}'));
        $this->assertSame(['x3'], $extractor->extractSegments('{x3}'));
        $this->assertSame(['end'], $extractor->extractSegments('[[:alpha:]]end'));
        $this->assertSame(['end'], $extractor->extractSegments('[]a]end'));
        $this->assertSame(['end'], $extractor->extractSegments('[\\]x]end'));
        $this->assertSame(['bcd', 'a'], $extractor->extractSegments('a{}bcd'));
        $this->assertSame(['bcd', 'x', 'a'], $extractor->extractSegments('a{x}bcd'));
        $this->assertSame(['a.b'], $extractor->extractSegments('a\.b'));
        $this->assertSame([], $extractor->extractSegments('(?i)needle'));
        $this->assertSame([], $extractor->extractSegments('pre(?i:needle)'));
        $this->assertSame([], $extractor->extractSegments('(*UTF)needle'));
        $this->assertSame([], $extractor->extractSegments('\Qneedle\E'));
        $this->assertSame([], $extractor->extractSegments('\x41needle'));
        $this->assertSame([], $extractor->extractSegments('(needle)\1'));
        $this->assertSame([], $extractor->extractSegments('needle)'));
        $this->assertSame([], $extractor->extractSegments('(needle'));
        $this->assertSame([], $extractor->extractSegments('[needle'));
        $this->assertSame([], $extractor->extractSegments('needle\\'));
        $this->assertSame([], $extractor->extractSegments('*needle'));
        $this->assertSame([], $extractor->extractSegments('{2}needle'));
        $this->assertSame([], $extractor->extractSegments('needle??+'));
        $this->assertSame([], $extractor->extractSegments("\\\u{e9}needle"));
    }

    #[Test]
    public function itDropsLiteralsThatAsciiCaseFoldingCannotPrefilter(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame(['kelvin', 'scale'], $extractor->extractSegments('kelvin\s+scale'));
        $this->assertSame([], $extractor->extractSegments('kelvin\s+scale', true));
        $this->assertSame(['function'], $extractor->extractSegments('function\s+émile', true));
        $this->assertNull($extractor->extract('émile', true));
        $this->assertTrue(LiteralExtractor::isAsciiCaseFoldSafe('function'));
        $this->assertFalse(LiteralExtractor::isAsciiCaseFoldSafe('Kelvin'));
        $this->assertFalse(LiteralExtractor::isAsciiCaseFoldSafe('café'));
    }

    #[Test]
    public function itExtractsConservativeRegexLiteralPlans(): void
    {
        $extractor = new LiteralExtractor();

        $this->assertSame(
            ['type' => 'literal', 'literal' => 'function'],
            $extractor->extractRegexLiteralPlan('function'),
        );
        $this->assertSame(
            ['type' => 'prefix', 'literal' => 'function '],
            $extractor->extractRegexLiteralPlan('^function '),
        );
        $this->assertSame(
            ['type' => 'suffix', 'literal' => ');'],
            $extractor->extractRegexLiteralPlan('\);$'),
        );
        $this->assertSame(
            ['type' => 'full-line', 'literal' => '}'],
            $extractor->extractRegexLiteralPlan('^\}$'),
        );
        $this->assertNull($extractor->extractRegexLiteralPlan('^function\s+[a-z_]+'));
        $this->assertNull($extractor->extractRegexLiteralPlan('foo|bar'));
        $this->assertNull($extractor->extractRegexLiteralPlan('\d+'));
    }
}
