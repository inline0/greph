<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Phgrep;
use Phgrep\Support\Filesystem;
use Phgrep\Support\Json;

final class ActualCapture
{
    private FlagParser $flagParser;

    private OutputNormalizer $normalizer;

    public function __construct(?FlagParser $flagParser = null, ?OutputNormalizer $normalizer = null)
    {
        $this->flagParser = $flagParser ?? new FlagParser();
        $this->normalizer = $normalizer ?? new OutputNormalizer();
    }

    /**
     * @return array{success: bool, outputs: array<string, mixed>, errors: list<string>}
     */
    public function captureFromWorkspace(Scenario $scenario, string $workspaceRoot): array
    {
        $outputs = [];
        $errors = [];

        try {
            Filesystem::ensureDirectory($scenario->actualDir());

            if ($scenario->mode() === 'rewrite') {
                $outputs = $this->captureRewrite($scenario, $workspaceRoot);
            } elseif ($scenario->mode() === 'ast') {
                $outputs = $this->captureAst($scenario, $workspaceRoot);
            } else {
                $outputs = $this->captureText($scenario, $workspaceRoot);
            }

            foreach ($outputs as $name => $output) {
                $path = $scenario->actualDir() . '/' . $name;

                if (is_array($output)) {
                    Json::encodeFile($path, $output);
                } else {
                    file_put_contents($path, (string) $output);
                }
            }
        } catch (\Throwable $throwable) {
            $errors[] = $throwable->getMessage();
        }

        return [
            'success' => $errors === [],
            'outputs' => $outputs,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureText(Scenario $scenario, string $workspaceRoot): array
    {
        $paths = array_map(static fn (string $path): string => $workspaceRoot . '/' . $path, $scenario->paths());
        $options = $this->flagParser->textOptions($scenario);
        $results = Phgrep::searchText($scenario->pattern(), $paths, $options);
        $outputs = $this->normalizer->textOutputs($results, $options, $workspaceRoot);

        return [
            'phgrep.txt' => $outputs['text'],
            'phgrep.json' => $outputs['json'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureAst(Scenario $scenario, string $workspaceRoot): array
    {
        $paths = array_map(static fn (string $path): string => $workspaceRoot . '/' . $path, $scenario->paths());
        $options = $this->flagParser->astOptions($scenario);
        $matches = Phgrep::searchAst($scenario->pattern(), $paths, $options);
        $outputs = $this->normalizer->astOutputs($matches, $workspaceRoot);

        return [
            'phgrep.txt' => $outputs['text'],
            'phgrep.json' => $outputs['json'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function captureRewrite(Scenario $scenario, string $workspaceRoot): array
    {
        $paths = array_map(static fn (string $path): string => $workspaceRoot . '/' . $path, $scenario->paths());
        $options = $this->flagParser->astOptions($scenario);
        $results = Phgrep::rewriteAst($scenario->pattern(), (string) $scenario->rewrite(), $paths, $options);
        $outputs = $this->normalizer->rewriteOutputs($results, $workspaceRoot);

        return [
            'phgrep.txt' => $outputs['text'],
            'phgrep.json' => $outputs['json'],
        ];
    }
}
