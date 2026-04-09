<?php

declare(strict_types=1);

namespace Phgrep\Parallel;

final class ResultCollector
{
    /**
     * @param list<array{pid: int, socket: mixed, tempPath?: string}> $workers
     * @return list<mixed>
     */
    public function collect(array $workers): array
    {
        $results = [];

        foreach ($workers as $worker) {
            $results[] = $this->collectWorker($worker);
        }

        return $results;
    }

    /**
     * @param array{pid: int, socket: mixed, tempPath?: string} $worker
     */
    public function collectWorker(array $worker, bool $waitForExit = true): mixed
    {
        try {
            $metadata = stream_get_meta_data($worker['socket']);
            $seekable = $metadata['seekable'] === true;

            if ($waitForExit && $seekable) {
                pcntl_waitpid($worker['pid'], $status);
                rewind($worker['socket']);
            }

            $data = stream_get_contents($worker['socket']);
            fclose($worker['socket']);

            if ($waitForExit && !$seekable) {
                pcntl_waitpid($worker['pid'], $status);
            }

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

            return $payload['result'] ?? null;
        } finally {
            if (isset($worker['tempPath'])) {
                @unlink($worker['tempPath']);
            }
        }
    }
}
