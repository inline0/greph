<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

final class ResultCollector
{
    /**
     * @param list<array{pid: int, socket: mixed}> $workers
     * @return list<mixed>
     */
    public function collect(array $workers): array
    {
        $results = [];

        foreach ($workers as $worker) {
            $data = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);

            if ($data === false || $data === '') {
                throw new \RuntimeException(sprintf('Worker %d produced no output.', $worker['pid']));
            }

            $payload = unserialize($data, ['allowed_classes' => true]);

            if (!is_array($payload)) {
                throw new \RuntimeException(sprintf('Worker %d returned invalid output.', $worker['pid']));
            }

            if (isset($payload['error'], $payload['message'])) {
                throw new \RuntimeException(sprintf(
                    'Worker %s failed with %s: %s',
                    (string) ($payload['worker'] ?? '?'),
                    $payload['error'],
                    $payload['message'],
                ));
            }

            $results[] = $payload['result'] ?? null;
        }

        return $results;
    }
}
