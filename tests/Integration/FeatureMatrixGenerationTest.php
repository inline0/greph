<?php

declare(strict_types=1);

namespace Phgrep\Tests\Integration;

use Phgrep\FeatureMatrix\FeatureMatrixGenerator;
use Phgrep\Support\ToolResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FeatureMatrixGenerationTest extends TestCase
{
    #[Test]
    public function itGeneratesProbeDrivenMarkdownAndRawResults(): void
    {
        $generator = new FeatureMatrixGenerator(
            dirname(__DIR__, 2),
            toolResolver: new ToolResolver(static fn (string $candidate): ?string => null),
        );

        $report = $generator->generate();
        $markdown = $generator->renderMarkdown($report);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Generated from live command probes', $markdown);
        $this->assertStringContainsString('## rg Compatibility Surface', $markdown);
        $this->assertStringContainsString('## sg Compatibility Surface', $markdown);
        $this->assertStringContainsString('## sg Wrapper-only Surface (bin/sg only)', $markdown);
        $this->assertStringContainsString('## Native phgrep Surface', $markdown);
        $this->assertStringContainsString('## Indexed phgrep Surface', $markdown);
        $this->assertStringContainsString('## Indexed AST Library Surface', $markdown);
        $this->assertStringContainsString('| Fixed-string search | Unavailable', $markdown);
        $this->assertStringContainsString('| Fixed-string search | Unavailable<br><sub>Provider command was not available in this environment.</sub> |', $markdown);
        $this->assertStringContainsString('Provider command was not available in this environment.', $markdown);
        $this->assertStringContainsString('"bin/rg"', $json);
        $this->assertStringContainsString('"bin/sg"', $json);
        $this->assertStringContainsString('"bin/phgrep-index"', $json);
        $this->assertStringContainsString('"php/lib"', $json);
        $this->assertStringContainsString('"status": "Unavailable"', $json);
        $this->assertStringContainsString('"status": "Pass"', $json);
    }
}
