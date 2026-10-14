<?php

declare(strict_types=1);

namespace Greph\Ast;

use Greph\Ast\Parsers\ParserFactory;
use Greph\Ast\Parsers\ParserInterface;
use Greph\Exceptions\ParseException;
use PhpParser\Node;
use Greph\Walker\FileList;

final class AstRewriter
{
    private PatternParser $patternParser;

    private PatternMatcher $patternMatcher;

    private AstCandidateFinder $candidateFinder;

    private AstRootMatcher $rootMatcher;

    private ParserFactory $parserFactory;

    private CaptureTextRenderer $renderer;

    public function __construct(
        ?PatternParser $patternParser = null,
        ?PatternMatcher $patternMatcher = null,
        ?AstCandidateFinder $candidateFinder = null,
        ?AstRootMatcher $rootMatcher = null,
        ?ParserFactory $parserFactory = null,
    ) {
        if ($parserFactory === null && $patternParser === null) {
            $parserFactory = new ParserFactory();
        }

        $this->patternParser = $patternParser ?? new PatternParser($parserFactory);
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
        $this->candidateFinder = $candidateFinder ?? new AstCandidateFinder();
        $this->rootMatcher = $rootMatcher ?? new AstRootMatcher();
        $this->parserFactory = $parserFactory ?? new ParserFactory();
        $this->renderer = new CaptureTextRenderer();
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
        $bound = array_filter(
            CaptureTextRenderer::captureNames($search->root),
            static fn (string $name): bool => !MetaVariable::isNonCapturing($name),
        );
        $unbound = array_diff(CaptureTextRenderer::captureNames($rewrite->root), $bound);
        if ($unbound !== []) {
            throw new ParseException('Rewrite metavariable is not bound by the pattern: ' . implode(', ', $unbound));
        }
        $templateCode = MetaVariable::preprocess($rewritePattern);
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
            $sourceOffset = CaptureTextRenderer::prefixLength($source);

            foreach ($this->candidateFinder->iterate($statements, $search, $this->rootMatcher) as $candidate) {
                $lists = [];
                $captures = $this->patternMatcher->matchWithSourceRanges($search->root, $candidate, $lists);

                if ($captures === null) {
                    continue;
                }

                $matches[] = [
                    'start' => $candidate->getStartFilePos() - $sourceOffset,
                    'end' => $candidate->getEndFilePos() - $sourceOffset,
                    'captures' => $captures,
                    'lists' => $lists,
                    'node' => $candidate,
                ];
            }

            if ($matches === []) {
                $results[] = new RewriteResult($file, $source, $source, 0);
                continue;
            }

            usort(
                $matches,
                static fn (array $left, array $right): int => ($left['start'] <=> $right['start'])
                    ?: ($right['end'] <=> $left['end'])
            );

            $selected = [];
            $lastEnd = -1;
            foreach ($matches as $match) {
                if ($match['start'] > $lastEnd) {
                    $selected[] = $match;
                    $lastEnd = $match['end'];
                }
            }

            $rewritten = $source;
            $replacementCount = 0;
            $renderedMatches = [];
            $replacementNodes = [];

            foreach (array_reverse($selected) as $match) {
                /** @var Node $template */
                $template = $rewrite->root;
                /** @var array<string, mixed> $captures */
                $captures = $match['captures'];
                $replacementNode = $this->materializeNode($template, $captures);
                if ($rewrite->isExpression && !$replacementNode instanceof Node\Expr) {
                    throw new \RuntimeException('Expression rewrite produced a non-expression node.');
                }
                $replacement = '';
                $valid = false;
                foreach ([false, true] as $parenthesize) {
                    $replacement = $this->renderer->render(
                        $rewrite,
                        $templateCode,
                        $captures,
                        $match['lists'] ?? [],
                        $source,
                        $match['node'],
                        $parenthesize,
                    );
                    try {
                        $parsed = $rewrite->isExpression
                            ? $parser->parseExpression($replacement)
                            : ($parser->parseStatements($replacement)[0] ?? null);
                    } catch (ParseException) {
                        continue;
                    }
                    if (self::shape($parsed) === self::shape($replacementNode)) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    throw new ParseException('Cannot render the rewrite without changing its expression structure.');
                }

                $rewritten = substr_replace(
                    $rewritten,
                    $replacement,
                    $match['start'],
                    ($match['end'] - $match['start']) + 1,
                );
                $renderedMatches[] = ['start' => $match['start'], 'end' => $match['end'], 'text' => $replacement];
                $replacementNodes[spl_object_id($match['node'])] = $replacementNode;

                $replacementCount++;
            }

            $expected = self::shape($statements, $replacementNodes);
            $validShape = $this->hasShape($parser, $rewritten, $expected);
            if (!$validShape && $rewrite->isExpression) {
                $rewritten = $source;
                foreach ($renderedMatches as $match) {
                    $rewritten = substr_replace(
                        $rewritten,
                        '(' . $match['text'] . ')',
                        $match['start'],
                        $match['end'] - $match['start'] + 1,
                    );
                }
                $validShape = $this->hasShape($parser, $rewritten, $expected);
            }
            if (!$validShape) {
                throw new ParseException('Cannot preserve the rewrite structure in its surrounding source.');
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
        $clone->setAttributes([]);

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

    private function hasShape(ParserInterface $parser, string $source, mixed $expected): bool
    {
        try {
            return self::shape($parser->parseStatements($source)) === $expected;
        } catch (ParseException) {
            return false;
        }
    }

    /** @param array<int, Node> $replacements */
    private static function shape(mixed $value, array $replacements = []): mixed
    {
        if ($value instanceof Node) {
            if (isset($replacements[spl_object_id($value)])) {
                return self::shape($replacements[spl_object_id($value)]);
            }
            $fields = [$value::class];
            foreach ($value->getSubNodeNames() as $name) {
                /** @var mixed $child */
                $child = $value->$name;
                $fields[$name] = self::shape($child, $replacements);
            }
            return $fields;
        }
        return is_array($value)
            ? array_map(static fn (mixed $item): mixed => self::shape($item, $replacements), $value)
            : $value;
    }
}
