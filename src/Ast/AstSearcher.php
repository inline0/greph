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

    private AstPatternPrefilter $patternPrefilter;

    private AstCandidateFinder $candidateFinder;

    private AstRootMatcher $rootMatcher;

    private ParserFactory $parserFactory;

    private Standard $printer;

    public function __construct(
        ?PatternParser $patternParser = null,
        ?PatternMatcher $patternMatcher = null,
        ?AstPatternPrefilter $patternPrefilter = null,
        ?AstCandidateFinder $candidateFinder = null,
        ?AstRootMatcher $rootMatcher = null,
        ?ParserFactory $parserFactory = null,
    ) {
        $this->patternParser = $patternParser ?? new PatternParser();
        $this->patternMatcher = $patternMatcher ?? new PatternMatcher();
        $this->patternPrefilter = $patternPrefilter ?? new AstPatternPrefilter();
        $this->candidateFinder = $candidateFinder ?? new AstCandidateFinder();
        $this->rootMatcher = $rootMatcher ?? new AstRootMatcher();
        $this->parserFactory = $parserFactory ?? new ParserFactory();
        $this->printer = new Standard();
    }

    /**
     * @return list<AstMatch>
     */
    public function searchFiles(FileList $files, string $pattern, AstSearchOptions $options): array
    {
        $matches = [];

        $this->scanFiles(
            $files,
            $pattern,
            $options,
            function (Node $candidate, array $captures, string $source, string $file) use (&$matches): void {
                $matches[] = $this->createMatch($candidate, $captures, $source, $file);
            },
        );

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        return $matches;
    }

    public function countFiles(FileList $files, string $pattern, AstSearchOptions $options): int
    {
        $count = 0;

        $this->scanFiles(
            $files,
            $pattern,
            $options,
            static function () use (&$count): void {
                $count++;
            },
        );

        return $count;
    }

    /**
     * @param callable(Node, array<string, mixed>, string, string): void $onMatch
     */
    private function scanFiles(FileList $files, string $pattern, AstSearchOptions $options, callable $onMatch): void
    {
        $parsedPattern = $this->patternParser->parse($pattern, $options->language);
        $prefilterTokens = $this->patternPrefilter->extract($parsedPattern->root);
        $parser = $this->parserFactory->forLanguage($options->language);

        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false) {
                continue;
            }

            if (
                !$this->patternPrefilter->mayMatch($prefilterTokens, $source)
                || !$this->patternPrefilter->mayMatchPattern($parsedPattern->root, $source)
            ) {
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

            foreach ($this->candidateFinder->iterate($statements, $parsedPattern, $this->rootMatcher) as $candidate) {
                $captures = $this->patternMatcher->match($parsedPattern->root, $candidate);

                if ($captures === null) {
                    continue;
                }

                $onMatch($candidate, $captures, $source, $file);
            }
        }
    }

    /**
     * @param array<string, mixed> $captures
     */
    private function createMatch(Node $node, array $captures, string $source, string $file): AstMatch
    {
        $startFilePos = $node->getStartFilePos();
        $endFilePos = $node->getEndFilePos();
        $code = $endFilePos >= $startFilePos
            ? substr($source, $startFilePos, ($endFilePos - $startFilePos) + 1)
            : $this->renderNode($node);

        return new AstMatch(
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

    private function renderNode(Node $node): string
    {
        return $node instanceof Node\Expr
            ? $this->printer->prettyPrintExpr($node)
            : $this->printer->prettyPrint([$node]);
    }
}
