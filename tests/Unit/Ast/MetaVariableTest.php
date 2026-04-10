<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Ast;

use Greph\Ast\MetaVariable;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetaVariableTest extends TestCase
{
    #[Test]
    public function itPreprocessesAndRecognizesMetaVariables(): void
    {
        $processed = MetaVariable::preprocess('function $Name($$$ARGS) {}');

        $this->assertStringContainsString(MetaVariable::IDENTIFIER_PREFIX . 'Name', $processed);
        $this->assertStringContainsString(MetaVariable::VARIADIC_PREFIX . 'ARGS', $processed);
        $this->assertSame('foo', MetaVariable::singleName(new Variable('foo')));
        $this->assertSame('Bar', MetaVariable::singleName(new Identifier(MetaVariable::IDENTIFIER_PREFIX . 'Bar')));
        $this->assertSame('Baz', MetaVariable::singleName(new Name(MetaVariable::IDENTIFIER_PREFIX . 'Baz')));
        $this->assertTrue(MetaVariable::isNonCapturing('_'));
        $this->assertNull(MetaVariable::singleName(new Variable(MetaVariable::VARIADIC_PREFIX . 'ARGS')));
    }

    #[Test]
    public function itRecognizesVariadicCapturesAcrossNodeTypes(): void
    {
        $arg = new Arg(new Variable(MetaVariable::VARIADIC_PREFIX . 'ARGS'), unpack: true);
        $item = new ArrayItem(new Variable(MetaVariable::VARIADIC_PREFIX . 'ITEMS'), unpack: true);
        $param = new Param(new Variable(MetaVariable::VARIADIC_PREFIX . 'PARAMS'), variadic: true);
        $plainArg = new Arg(new Variable('plain'), unpack: true);

        $this->assertSame('ARGS', MetaVariable::variadicName($arg));
        $this->assertSame('ITEMS', MetaVariable::variadicName($item));
        $this->assertSame('PARAMS', MetaVariable::variadicName($param));
        $this->assertNull(MetaVariable::variadicName(new Variable('plain')));
        $this->assertNull(MetaVariable::variadicName($plainArg));
    }
}
