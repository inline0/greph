<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Index;

use Phgrep\Index\AstFactExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AstFactExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsStructuralFactsFromPhpSource(): void
    {
        $extractor = new AstFactExtractor();
        $facts = $extractor->extract(<<<'PHP'
<?php

class Example {}
interface Contract {}
trait SharedCode {}

$service = new Demo();
$zero = new ZeroArg();
$legacy = array(1, 2, 3);
helper();
$foo->run();
ExampleFactory::build();
PHP);

        $this->assertTrue($facts['zero_arg_new']);
        $this->assertTrue($facts['long_array']);
        $this->assertContains('helper', $facts['function_calls']);
        $this->assertContains('run', $facts['method_calls']);
        $this->assertContains('build', $facts['static_calls']);
        $this->assertContains('demo', $facts['new_targets']);
        $this->assertContains('zeroarg', $facts['new_targets']);
        $this->assertContains('example', $facts['classes']);
        $this->assertContains('contract', $facts['interfaces']);
        $this->assertContains('sharedcode', $facts['traits']);
    }

    #[Test]
    public function itCoversPrivateTokenHelperBranches(): void
    {
        $extractor = new AstFactExtractor();
        $tokens = token_get_all(<<<'PHP'
<?php

new Foo();
$value?->run();
ClassName::call();
function helper() {}
\Namespaced\callable();
PHP);

        $this->assertTrue($this->invokeMethod($extractor, 'hasOpeningParenthesis', ['(', ')'], 0));
        $this->assertFalse($this->invokeMethod($extractor, 'hasZeroArgumentNewExpression', [[T_NEW, 'new', 1]], 0));
        $this->assertFalse($this->invokeMethod($extractor, 'hasZeroArgumentNewExpression', [[T_NEW, 'new', 1], [T_WHITESPACE, ' ', 1]], 0));
        $this->assertTrue($this->invokeMethod($extractor, 'hasZeroArgumentNewExpression', token_get_all("<?php\nnew Foo /* comment */ ;\n"), 1));
        $this->assertFalse($this->invokeMethod($extractor, 'hasZeroArgumentNewExpression', token_get_all("<?php\nnew Foo"), 1));
        $this->assertNull($this->invokeMethod($extractor, 'newTargetName', [[T_NEW, 'new', 1], ';'], 0));
        $this->assertNull($this->invokeMethod($extractor, 'newTargetName', [[T_NEW, 'new', 1]], 0));
        $this->assertNull($this->invokeMethod($extractor, 'nameAt', $tokens, null));
        $this->assertNull($this->invokeMethod($extractor, 'nameAt', ['('], 0));
        $this->assertNull($this->invokeMethod($extractor, 'nextSignificantTokenIndex', [[T_WHITESPACE, ' ', 1]], 0));
        $this->assertNull($this->invokeMethod($extractor, 'previousSignificantTokenIndex', [[T_WHITESPACE, ' ', 1]], 0));
        $this->assertFalse($this->invokeMethod($extractor, 'isObjectOperator', '->'));
        $this->assertNull($this->invokeMethod($extractor, 'tokenId', 'token'));
        $this->assertTrue($this->invokeMethod($extractor, 'blocksFunctionCallClassification', [T_FUNCTION, 'function', 1]));
        $this->assertFalse($this->invokeMethod($extractor, 'blocksFunctionCallClassification', null));

        $facts = $extractor->extract(<<<'PHP'
<?php

class {}
new;
runner();
PHP);

        $this->assertTrue($facts['zero_arg_new']);
        $this->assertContains('runner', $facts['function_calls']);
        $this->assertSame([], $facts['classes']);
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
