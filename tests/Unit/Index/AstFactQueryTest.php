<?php

declare(strict_types=1);

namespace Greph\Tests\Unit\Index;

use Greph\Ast\PatternParser;
use Greph\Index\AstFactQuery;
use PhpParser\Node\Expr\Array_;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstFactQueryTest extends TestCase
{
    #[Test]
    public function itBuildsPredicatesForSupportedRootShapes(): void
    {
        $query = new AstFactQuery();
        $parser = new PatternParser();
        $factsByFileId = [
            1 => [
                'zero_arg_new' => true,
                'long_array' => true,
                'function_calls' => ['dispatch'],
                'method_calls' => ['send'],
                'static_calls' => ['make'],
                'new_targets' => ['foo'],
                'classes' => ['demo'],
                'interfaces' => ['contract'],
                'traits' => ['helpful'],
            ],
            2 => [
                'zero_arg_new' => false,
                'long_array' => false,
                'function_calls' => [],
                'method_calls' => [],
                'static_calls' => [],
                'new_targets' => [],
                'classes' => [],
                'interfaces' => [],
                'traits' => [],
            ],
        ];

        $cases = [
            'array($$$ITEMS)' => [1 => true],
            'new Foo()' => [1 => true],
            'dispatch($EVENT)' => [1 => true],
            '$CLIENT->send($MESSAGE)' => [1 => true],
            'Foo::make()' => [1 => true],
            'class Demo {}' => [1 => true],
            'interface Contract {}' => [1 => true],
            'trait Helpful {}' => [1 => true],
        ];

        foreach ($cases as $pattern => $expected) {
            $candidateIds = $query->candidateIds($factsByFileId, $parser->parse($pattern));
            $this->assertSame($expected, $candidateIds, $pattern);
        }
    }

    #[Test]
    public function itHandlesDynamicNewTargetsAndUnsupportedRoots(): void
    {
        $query = new AstFactQuery();
        $parser = new PatternParser();
        $factsByFileId = [
            1 => [
                'zero_arg_new' => true,
                'long_array' => false,
                'function_calls' => [],
                'method_calls' => [],
                'static_calls' => [],
                'new_targets' => [],
                'classes' => [],
                'interfaces' => [],
                'traits' => [],
            ],
        ];

        $dynamicNew = $query->candidateIds($factsByFileId, $parser->parse('new $CLASS()'));
        $unsupported = $query->candidateIds($factsByFileId, $parser->parse('if ($COND) { $A = 1; }'));

        $this->assertSame([1 => true], $dynamicNew);
        $this->assertNull($unsupported);
    }

    #[Test]
    public function itFallsBackToTheArrayKindPropertyWhenNoAttributeIsPresent(): void
    {
        $query = new AstFactQuery();
        $array = new class ([]) extends Array_ {
            public mixed $kind = null;
        };
        $array->setAttribute('kind', null);
        $array->kind = Array_::KIND_LONG;

        $this->assertTrue($this->invokeMethod($query, 'isLongArraySyntax', $array));
    }

    /**
     * @return mixed
     */
    private function invokeMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }
}
