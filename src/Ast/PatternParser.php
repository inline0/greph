<?php

declare(strict_types=1);

namespace Phgrep\Ast;

use Phgrep\Ast\Parsers\ParserFactory;
use Phgrep\Exceptions\ParseException;

final class PatternParser
{
    private ParserFactory $parserFactory;

    public function __construct(?ParserFactory $parserFactory = null)
    {
        $this->parserFactory = $parserFactory ?? new ParserFactory();
    }

    public function parse(string $pattern, string $language = 'php'): Pattern
    {
        $parser = $this->parserFactory->forLanguage($language);
        $pattern = MetaVariable::preprocess($pattern);

        try {
            return new Pattern($parser->parseExpression($pattern), true);
        } catch (ParseException) {
            $statements = $parser->parseStatements($pattern);

            if (count($statements) !== 1) {
                throw new ParseException('Pattern must parse to exactly one expression or statement.');
            }

            return new Pattern($statements[0], false);
        }
    }
}
