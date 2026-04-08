<?php

declare(strict_types=1);

namespace Phgrep\Ast\Parsers;

final class ParserFactory
{
    public function forLanguage(string $language): ParserInterface
    {
        return match (strtolower($language)) {
            'php' => new PhpParser(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported language: %s', $language)),
        };
    }
}
