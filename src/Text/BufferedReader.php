<?php

declare(strict_types=1);

namespace Phgrep\Text;

final class BufferedReader
{
    public function __construct(private readonly int $bufferSize = 65536)
    {
        if ($this->bufferSize < 1) {
            throw new \InvalidArgumentException('Buffer size must be greater than zero.');
        }
    }

    /**
     * @return \Generator<int, BufferedLine>
     */
    public function readLines(string $path): \Generator
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        $buffer = '';
        $lineNumber = 1;

        try {
            while (!feof($handle)) {
                /** @var positive-int $bufferSize */
                $bufferSize = $this->bufferSize;
                $chunk = fread($handle, $bufferSize);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $buffer .= $chunk;

                while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePosition);
                    $buffer = substr($buffer, $newlinePosition + 1);

                    yield new BufferedLine($lineNumber, rtrim($line, "\r"));
                    $lineNumber++;
                }
            }

            if ($buffer !== '') {
                yield new BufferedLine($lineNumber, rtrim($buffer, "\r"));
            }
        } finally {
            fclose($handle);
        }
    }
}
