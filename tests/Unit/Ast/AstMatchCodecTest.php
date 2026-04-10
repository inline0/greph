<?php

declare(strict_types=1);

namespace Phgrep\Tests\Unit\Ast;

use Phgrep\Ast\AstMatch;
use Phgrep\Ast\AstMatchCodec;
use Phgrep\Ast\PatternParser;
use Phgrep\Ast\StoredNode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;

final class AstMatchCodecTest extends TestCase
{
    #[Test]
    public function itRoundTripsMatchesWithNodeCaptures(): void
    {
        $codec = new AstMatchCodec();
        $pattern = (new PatternParser())->parse('dispatch($EVENT)');
        $matchNode = new FuncCall(
            new Name('dispatch'),
            [new Arg(new Variable('event'))],
            [
                'startLine' => 3,
                'endLine' => 3,
                'startFilePos' => 20,
                'endFilePos' => 36,
            ],
        );
        $captureNode = new Variable('event', [
            'startLine' => 3,
            'endLine' => 3,
            'startFilePos' => 29,
            'endFilePos' => 34,
        ]);
        $variadicItem = new ArrayItem(new Variable('job'), null, false, [
            'startLine' => 4,
            'endLine' => 4,
            'startFilePos' => 40,
            'endFilePos' => 45,
        ]);
        $matches = [
            new AstMatch(
                file: '/tmp/demo.php',
                node: $matchNode,
                captures: [
                    'EVENT' => $captureNode,
                    'ITEMS' => [$variadicItem],
                ],
                startLine: 3,
                endLine: 3,
                startFilePos: 20,
                endFilePos: 36,
                code: 'dispatch($event)',
            ),
        ];

        $decoded = $codec->decode($codec->encode($matches));

        $this->assertCount(1, $decoded);
        $this->assertInstanceOf(StoredNode::class, $decoded[0]->node);
        $this->assertSame($matchNode->getType(), $decoded[0]->node->getType());
        $this->assertSame('dispatch($event)', $decoded[0]->code);
        $this->assertInstanceOf(StoredNode::class, $decoded[0]->captures['EVENT']);
        $this->assertSame($captureNode->getType(), $decoded[0]->captures['EVENT']->getType());
        $this->assertIsArray($decoded[0]->captures['ITEMS']);
        $this->assertInstanceOf(StoredNode::class, $decoded[0]->captures['ITEMS'][0]);
        $this->assertSame($variadicItem->getType(), $decoded[0]->captures['ITEMS'][0]->getType());
        $this->assertSame($pattern->root->getType(), (new StoredNode($pattern->root->getType(), 1, 1, 0, 0))->getType());
    }

    #[Test]
    public function itRejectsInvalidPayloads(): void
    {
        $codec = new AstMatchCodec();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AST query cache is corrupt.');
        $codec->decode([['f' => '/tmp/demo.php']]);
    }
}
