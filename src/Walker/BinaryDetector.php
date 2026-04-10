<?php

declare(strict_types=1);

namespace Greph\Walker;

final class BinaryDetector
{
    public function __construct(
        private readonly int $bytesToRead = 512,
        private readonly float $suspiciousByteThreshold = 0.3,
    ) {
        if ($this->bytesToRead < 1) {
            throw new \InvalidArgumentException('Byte sample size must be greater than zero.');
        }
    }

    public function isBinaryFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            /** @var positive-int $bytesToRead */
            $bytesToRead = $this->bytesToRead;
            $sample = fread($handle, $bytesToRead);
        } finally {
            fclose($handle);
        }

        if ($sample === false || $sample === '') {
            return false;
        }

        if (str_contains($sample, "\0")) {
            return true;
        }

        $length = strlen($sample);
        $suspiciousBytes = 0;

        for ($index = 0; $index < $length; $index++) {
            $byte = ord($sample[$index]);

            if ($byte === 9 || $byte === 10 || $byte === 13) {
                continue;
            }

            if ($byte < 32 || $byte === 127) {
                $suspiciousBytes++;
            }
        }

        return ($suspiciousBytes / $length) >= $this->suspiciousByteThreshold;
    }
}
