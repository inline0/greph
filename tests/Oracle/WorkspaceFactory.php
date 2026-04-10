<?php

declare(strict_types=1);

namespace Greph\Tests\Oracle;

use Greph\Support\CommandRunner;
use Greph\Support\Filesystem;
use Greph\Tests\Support\Workspace;

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
                ['GREPH_ROOT' => $scenario->rootPath],
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
