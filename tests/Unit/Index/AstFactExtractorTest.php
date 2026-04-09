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
}
