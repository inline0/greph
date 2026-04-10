<?php

declare(strict_types=1);

namespace Greph\Text;

final class TextResultCodec
{
    /**
     * @param list<TextFileResult> $results
     * @return list<array{f: string, c: int, m?: list<array{l: int, c: int, t: string, m?: string, p?: array<int|string, string>, b?: list<array{line: int, content: string}>, a?: list<array{line: int, content: string}>}>}>
     */
    public function encode(array $results): array
    {
        $encoded = [];

        foreach ($results as $result) {
            $entry = [
                'f' => $result->file,
                'c' => $result->matchCount(),
            ];

            if ($result->matches !== []) {
                $entry['m'] = [];

                foreach ($result->matches as $match) {
                    $encodedMatch = [
                        'l' => $match->line,
                        'c' => $match->column,
                        't' => $match->content,
                    ];

                    if ($match->matchedText !== '') {
                        $encodedMatch['m'] = $match->matchedText;
                    }

                    if ($match->captures !== []) {
                        $encodedMatch['p'] = $match->captures;
                    }

                    if ($match->beforeContext !== []) {
                        $encodedMatch['b'] = $match->beforeContext;
                    }

                    if ($match->afterContext !== []) {
                        $encodedMatch['a'] = $match->afterContext;
                    }

                    $entry['m'][] = $encodedMatch;
                }
            }

            $encoded[] = $entry;
        }

        return $encoded;
    }

    /**
     * @param mixed $payload
     * @return list<TextFileResult>
     */
    public function decode(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \RuntimeException('Worker returned invalid text payload.');
        }

        $results = [];

        foreach ($payload as $entry) {
            if (!is_array($entry) || !isset($entry['f'], $entry['c']) || !is_string($entry['f']) || !is_int($entry['c'])) {
                throw new \RuntimeException('Worker returned invalid text payload entry.');
            }

            $file = $entry['f'];
            $matches = [];

            foreach ($entry['m'] ?? [] as $matchEntry) {
                if (!is_array($matchEntry) || !isset($matchEntry['l'], $matchEntry['c'], $matchEntry['t'])) {
                    throw new \RuntimeException('Worker returned invalid text match payload.');
                }

                $matches[] = new TextMatch(
                    file: $file,
                    line: $matchEntry['l'],
                    column: $matchEntry['c'],
                    content: $matchEntry['t'],
                    matchedText: is_string($matchEntry['m'] ?? null) ? $matchEntry['m'] : '',
                    captures: is_array($matchEntry['p'] ?? null) ? $matchEntry['p'] : [],
                    beforeContext: $this->decodeContext($matchEntry['b'] ?? []),
                    afterContext: $this->decodeContext($matchEntry['a'] ?? []),
                );
            }

            $results[] = new TextFileResult($file, $matches, $entry['c']);
        }

        return $results;
    }

    /**
     * @param mixed $context
     * @return list<array{line: int, content: string}>
     */
    private function decodeContext(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $decoded = [];

        foreach ($context as $entry) {
            if (!is_array($entry) || !isset($entry['line'], $entry['content']) || !is_int($entry['line']) || !is_string($entry['content'])) {
                continue;
            }

            $decoded[] = [
                'line' => $entry['line'],
                'content' => $entry['content'],
            ];
        }

        return $decoded;
    }
}
