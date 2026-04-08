<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Support\CommandRunner;
use Phgrep\Support\Filesystem;
use Phgrep\Tests\Support\Workspace;

final class WorkspaceFactory
{
    private CommandRunner $commandRunner;

    public function __construct(?CommandRunner $commandRunner = null)
    {
        $this->commandRunner = $commandRunner ?? new CommandRunner();
    }

    public function create(Scenario $scenario, string $prefix): string
    {
        $workspaceRoot = Workspace::createDirectory($prefix);
        Filesystem::ensureDirectory($workspaceRoot);

        if (is_dir($scenario->setupDir())) {
            Workspace::copyDirectory($scenario->setupDir(), $workspaceRoot . '/setup');
        }

        if (is_file($scenario->setupScriptPath())) {
            $process = $this->commandRunner->run(
                ['bash', $scenario->setupScriptPath()],
                $workspaceRoot,
                ['PHGREP_ROOT' => $scenario->rootPath],
            );

            if (!$process->successful()) {
                throw new \RuntimeException(sprintf(
                    'Setup script failed for %s: %s',
                    $scenario->name,
                    $process->output(),
                ));
            }
        }

        $this->ensureGitMarkers($scenario, $workspaceRoot);

        return $workspaceRoot;
    }

    private function ensureGitMarkers(Scenario $scenario, string $workspaceRoot): void
    {
        foreach ($scenario->paths() as $path) {
            $searchRoot = $workspaceRoot . '/' . ltrim($path, '/');

            if (!is_dir($searchRoot)) {
                continue;
            }

            if (is_file($searchRoot . '/.gitignore') && !is_dir($searchRoot . '/.git')) {
                Filesystem::ensureDirectory($searchRoot . '/.git');
            }
        }
    }
}
