<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Ast;

use Greph\Ast\Parsers\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserFactoryTest extends TestCase
{
    #[Test]
    public function itReusesParserInstancesPerLanguage(): void
    {
        $factory = new ParserFactory();

        $first = $factory->forLanguage('php');
        $second = $factory->forLanguage('PHP');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function itRejectsUnsupportedLanguages(): void
    {
        $factory = new ParserFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported language: js');

        $factory->forLanguage('js');
    }
}
