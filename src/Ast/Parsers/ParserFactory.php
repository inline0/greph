<?php

declare(strict_types=1);

namespace Phgrep\Ast\Parsers;

final class ParserFactory
{
    /**
     * @var array<string, ParserInterface>
     */
    private array $parsers = [];

    public function forLanguage(string $language): ParserInterface
    {
        $language = strtolower($language);

        return $this->parsers[$language] ??= match ($language) {
            'php' => new PhpParser(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported language: %s', $language)),
        };
    }
}
