<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use Phgrep\Ast\Parsers\ParserFactory;
use Phgrep\Exceptions\ParseException;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Phgrep\Walker\FileList;

final class AstSearcher
{
    private PatternParser $patternParser;

    private PatternMatcher $patternMatcher;

    private ParserFactory $parserFactory;

    private Standard $printer;

    public function __construct(
        ?PatternParser $patternParser = null,
        ?PatternMatcher $patternMatcher = null,
        ?ParserFactory $parserFactory = null,
    ) {
        $this->patternParser = $patternParser ?? new PatternParser();
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
        $this->parserFactory = $parserFactory ?? new ParserFactory();
        $this->printer = new Standard();
    }

    /**
     * @return list<AstMatch>
     */
    public function searchFiles(FileList $files, string $pattern, AstSearchOptions $options): array
    {
        $parsedPattern = $this->patternParser->parse($pattern, $options->language);
        $parser = $this->parserFactory->forLanguage($options->language);
        $matches = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            try {
                $statements = $parser->parseStatements($source);
            } catch (ParseException $exception) {
                if ($options->skipParseErrors) {
                    continue;
                }

                throw $exception;
            }

            $this->visitStatements($statements, $parsedPattern, $source, $file, $matches);
        }

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        return $matches;
    }

    /**
     * @param list<Node> $nodes
     * @param list<AstMatch> $matches
     */
    private function visitStatements(array $nodes, Pattern $pattern, string $source, string $file, array &$matches): void
    {
        foreach ($nodes as $node) {
            $this->visitNode($node, $pattern, $source, $file, $matches);
        }
    }

    /**
     * @param list<AstMatch> $matches
     */
    private function visitNode(Node $node, Pattern $pattern, string $source, string $file, array &$matches): void
    {
        $captures = $this->patternMatcher->match($pattern->root, $node);

        if ($captures !== null) {
            $startFilePos = $node->getStartFilePos();
            $endFilePos = $node->getEndFilePos();
            $code = $endFilePos >= $startFilePos
                ? substr($source, $startFilePos, ($endFilePos - $startFilePos) + 1)
                : $this->renderNode($node);

            $matches[] = new AstMatch(
                file: $file,
                node: $node,
                captures: $captures,
                startLine: $node->getStartLine(),
                endLine: $node->getEndLine(),
                startFilePos: $startFilePos,
                endFilePos: $endFilePos,
                code: $code,
            );
        }

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $subNode */
            $subNode = $node->$subNodeName;

            if ($subNode instanceof Node) {
                $this->visitNode($subNode, $pattern, $source, $file, $matches);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $childNode) {
                    if ($childNode instanceof Node) {
                        $this->visitNode($childNode, $pattern, $source, $file, $matches);
                    }
                }
            }
        }
    }

    private function renderNode(Node $node): string
    {
        return $node instanceof Node\Expr
            ? $this->printer->prettyPrintExpr($node)
            : $this->printer->prettyPrint([$node]);
    }
}
