<?php

declare(strict_types=1);

namespace Greph\Index;

final class TrigramExtractor
{
    /**
     * @return list<string>
     */
    public function extract(string $contents): array
    {
        $contents = strtolower($contents);
        $length = strlen($contents);

        if ($length < 3) {
            return [];
        }

        $seen = [];
        $trigrams = [];

        for ($offset = 0; $offset <= $length - 3; $offset++) {
            $trigram = substr($contents, $offset, 3);

            if (isset($seen[$trigram])) {
                continue;
            }

            $seen[$trigram] = true;
            $trigrams[] = $trigram;
        }

        sort($trigrams);

        return $trigrams;
    }
}
