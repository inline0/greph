<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Text;

use Phgrep\Text\TextFileResult;
use Phgrep\Text\TextMatch;
use Phgrep\Text\TextResultCodec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextResultCodecTest extends TestCase
{
    #[Test]
    public function itRoundTripsDetailedAndCountOnlyResults(): void
    {
        $codec = new TextResultCodec();
        $results = [
            new TextFileResult('/tmp/a.php', [
                new TextMatch(
                    file: '/tmp/a.php',
                    line: 5,
                    column: 3,
                    content: 'function demo() {}',
                    matchedText: 'function',
                    captures: ['name' => 'demo'],
                    beforeContext: [['line' => 4, 'content' => '<?php']],
                    afterContext: [['line' => 6, 'content' => '}']],
                ),
            ]),
            new TextFileResult('/tmp/b.php', [], 7),
        ];

        $decoded = $codec->decode($codec->encode($results));

        $this->assertCount(2, $decoded);
        $this->assertSame('/tmp/a.php', $decoded[0]->file);
        $this->assertSame(1, $decoded[0]->matchCount());
        $this->assertSame('function', $decoded[0]->matches[0]->matchedText);
        $this->assertSame(['name' => 'demo'], $decoded[0]->matches[0]->captures);
        $this->assertSame('<?php', $decoded[0]->matches[0]->beforeContext[0]['content']);
        $this->assertSame('}', $decoded[0]->matches[0]->afterContext[0]['content']);
        $this->assertSame(7, $decoded[1]->matchCount());
        $this->assertSame([], $decoded[1]->matches);
    }

    #[Test]
    public function itRejectsInvalidPayloadEntries(): void
    {
        $codec = new TextResultCodec();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worker returned invalid text payload entry.');

        $codec->decode([['f' => '/tmp/a.php']]);
    }

    #[Test]
    public function itRejectsInvalidTopLevelAndMatchPayloadsAndSkipsInvalidContextRows(): void
    {
        $codec = new TextResultCodec();

        try {
            $codec->decode('bad');
            self::fail('Expected invalid top-level payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Worker returned invalid text payload.', $exception->getMessage());
        }

        try {
            $codec->decode([[
                'f' => '/tmp/a.php',
                'c' => 1,
                'm' => [['l' => 2, 'c' => 1]],
            ]]);
            self::fail('Expected invalid match payload to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Worker returned invalid text match payload.', $exception->getMessage());
        }

        $decoded = $codec->decode([[
            'f' => '/tmp/a.php',
            'c' => 1,
            'm' => [[
                'l' => 2,
                'c' => 1,
                't' => 'line',
                'b' => 'bad',
                'a' => [
                    ['line' => 'bad', 'content' => 'skip'],
                    ['line' => 3, 'content' => 'keep'],
                ],
            ]],
        ]]);

        $this->assertSame([], $decoded[0]->matches[0]->beforeContext);
        $this->assertSame([['line' => 3, 'content' => 'keep']], $decoded[0]->matches[0]->afterContext);
    }
}
