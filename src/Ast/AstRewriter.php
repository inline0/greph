<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use Phgrep\Ast\Parsers\ParserFactory;
use Phgrep\Exceptions\ParseException;
use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;
use Phgrep\Walker\FileList;

final class AstRewriter
{
    private PatternParser $patternParser;

    private PatternMatcher $patternMatcher;

    private AstCandidateFinder $candidateFinder;

    private AstRootMatcher $rootMatcher;

    private ParserFactory $parserFactory;

    private Standard $printer;

    public function __construct(
        ?PatternParser $patternParser = null,
        ?PatternMatcher $patternMatcher = null,
        ?AstCandidateFinder $candidateFinder = null,
        ?AstRootMatcher $rootMatcher = null,
        ?ParserFactory $parserFactory = null,
    ) {
        $this->patternParser = $patternParser ?? new PatternParser();
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
        $this->candidateFinder = $candidateFinder ?? new AstCandidateFinder();
        $this->rootMatcher = $rootMatcher ?? new AstRootMatcher();
        $this->parserFactory = $parserFactory ?? new ParserFactory();
        $this->printer = new Standard();
    }

    /**
     * @return list<RewriteResult>
     */
    public function rewriteFiles(
        FileList $files,
        string $searchPattern,
        string $rewritePattern,
        AstSearchOptions $options,
    ): array {
        $search = $this->patternParser->parse($searchPattern, $options->language);
        $rewrite = $this->patternParser->parse($rewritePattern, $options->language);
        $parser = $this->parserFactory->forLanguage($options->language);
        $results = [];

        foreach ($files as $file) {
            $source = @file_get_contents($file);

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

            $matches = [];

            foreach ($this->candidateFinder->find($statements, $search) as $candidate) {
                $captures = $this->rootMatcher->mayMatch($search->root, $candidate)
                    ? $this->patternMatcher->match($search->root, $candidate)
                    : null;

                if ($captures === null) {
                    continue;
                }

                $matches[] = [
                    'start' => $candidate->getStartFilePos(),
                    'end' => $candidate->getEndFilePos(),
                    'captures' => $captures,
                ];
            }

            if ($matches === []) {
                $results[] = new RewriteResult($file, $source, $source, 0);
                continue;
            }

            usort(
                $matches,
                static fn (array $left, array $right): int => $right['start'] <=> $left['start']
            );

            $rewritten = $source;
            $replacementCount = 0;
            $lastRangeStart = PHP_INT_MAX;

            foreach ($matches as $match) {
                if ($match['end'] >= $lastRangeStart) {
                    continue;
                }

                /** @var Node $template */
                $template = $rewrite->root;
                /** @var array<string, mixed> $captures */
                $captures = $match['captures'];
                $replacementNode = $this->materializeNode($template, $captures);

                if ($rewrite->isExpression) {
                    if (!$replacementNode instanceof Node\Expr) {
                        throw new \RuntimeException('Expression rewrite produced a non-expression node.');
                    }

                    $replacement = $this->printer->prettyPrintExpr($replacementNode);
                } else {
                    $replacement = $this->printer->prettyPrint([$replacementNode]);
                }

                $rewritten = substr_replace(
                    $rewritten,
                    $replacement,
                    $match['start'],
                    ($match['end'] - $match['start']) + 1,
                );

                $replacementCount++;
                $lastRangeStart = $match['start'];
            }

            $results[] = new RewriteResult($file, $source, $rewritten, $replacementCount);
        }

        return $results;
    }
    /**
     * @param array<string, mixed> $captures
     */
    private function materializeNode(Node $template, array $captures): Node
    {
        $metaName = MetaVariable::singleName($template);

        if ($metaName !== null && array_key_exists($metaName, $captures) && $captures[$metaName] instanceof Node) {
            /** @var Node $capturedNode */
            $capturedNode = $captures[$metaName];

            return clone $capturedNode;
        }

        $clone = clone $template;
        $this->clearAttributes($clone);

        foreach ($clone->getSubNodeNames() as $subNodeName) {
            /** @var mixed $subNode */
            $subNode = $clone->$subNodeName;
            $clone->$subNodeName = $this->materializeValue($subNode, $captures);
        }

        return $clone;
    }

    /**
     * @param array<string, mixed> $captures
     */
    private function materializeValue(mixed $value, array $captures): mixed
    {
        if ($value instanceof Node) {
            return $this->materializeNode($value, $captures);
        }

        if (is_array($value)) {
            $materialized = [];

            foreach ($value as $item) {
                $variadicName = MetaVariable::variadicName($item);

                if ($variadicName !== null && array_key_exists($variadicName, $captures) && is_array($captures[$variadicName])) {
                    foreach ($captures[$variadicName] as $capturedItem) {
                        $materialized[] = $capturedItem instanceof Node ? clone $capturedItem : $capturedItem;
                    }

                    continue;
                }

                $materialized[] = $this->materializeValue($item, $captures);
            }

            return $materialized;
        }

        return $value;
    }

    private function clearAttributes(Node $node): void
    {
        $node->setAttributes([]);

        foreach ($node->getSubNodeNames() as $subNodeName) {
            /** @var mixed $subNode */
            $subNode = $node->$subNodeName;

            if ($subNode instanceof Node) {
                $this->clearAttributes($subNode);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $childNode) {
                    if ($childNode instanceof Node) {
                        $this->clearAttributes($childNode);
                    }
                }
            }
        }
    }
}
