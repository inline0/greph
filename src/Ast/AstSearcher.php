<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use Phgrep\Ast\Parsers\ParserFactory;
use Phgrep\Exceptions\ParseException;
use PhpParser\Node;
use Phgrep\Walker\FileList;

final class AstSearcher
{
    private PatternParser $patternParser;

    private PatternMatcher $patternMatcher;

    private AstPatternPrefilter $patternPrefilter;

    private AstCandidateFinder $candidateFinder;

    private AstRootMatcher $rootMatcher;

    private ParserFactory $parserFactory;

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
    }

    /**
     * @return list<AstMatch>
     */
    public function searchFiles(FileList $files, string $pattern, AstSearchOptions $options): array
    {
        $parsedPattern = $this->patternParser->parse($pattern, $options->language);
        $prefilterTokens = $this->patternPrefilter->extract($parsedPattern->root);
        $parser = $this->parserFactory->forLanguage($options->language);
        $matches = [];

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

            $sourceBuffer = new AstSourceBuffer($source);

            foreach ($this->candidateFinder->iterate($statements, $parsedPattern, $this->rootMatcher) as $candidate) {
                $captures = $this->patternMatcher->match($parsedPattern->root, $candidate);

                if ($captures === null) {
                    continue;
                }

                $matches[] = $this->createMatch($candidate, $captures, $sourceBuffer, $file);
            }
        }

        usort(
            $matches,
            static fn (AstMatch $left, AstMatch $right): int => [$left->file, $left->startFilePos] <=> [$right->file, $right->startFilePos]
        );

        return $matches;
    }

    /**
     * @param array<string, mixed> $captures
     */
    private function createMatch(Node $node, array $captures, AstSourceBuffer $sourceBuffer, string $file): AstMatch
    {
        $startFilePos = $node->getStartFilePos();
        $endFilePos = $node->getEndFilePos();

        return new AstMatch(
            file: $file,
            node: $node,
            captures: $captures,
            startLine: $node->getStartLine(),
            endLine: $node->getEndLine(),
            startFilePos: $startFilePos,
            endFilePos: $endFilePos,
            sourceBuffer: $sourceBuffer,
        );
    }
}
