<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Ast;

use Greph\Ast\PatternParser;
use Greph\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternParserTest extends TestCase
{
    #[Test]
    public function itParsesExpressionsAndStatements(): void
    {
        $parser = new PatternParser();

        $this->assertTrue($parser->parse('new $CLASS()')->isExpression);
        $this->assertFalse($parser->parse('function $NAME() {}')->isExpression);
    }

    #[Test]
    public function itRejectsPatternsThatExpandToMultipleStatements(): void
    {
        $this->expectException(ParseException::class);

        (new PatternParser())->parse('echo 1; echo 2;');
    }
}
