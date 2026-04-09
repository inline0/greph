<?php

declare(strict_types=1);

namespace Phgrep\Cli;

final class AstGrepApplication
{
    private Application $application;

    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $errorOutput;

    /**
     * @param resource|null $output
     * @param resource|null $errorOutput
     */
    public function __construct(?Application $application = null, $output = null, $errorOutput = null)
    {
        $this->output = $output ?? STDOUT;
        $this->errorOutput = $errorOutput ?? STDERR;
        $this->application = $application ?? new Application(output: $this->output, errorOutput: $this->errorOutput);
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);

        if ($arguments === []) {
            fwrite($this->errorOutput, "Missing AST pattern.\n");

            return 2;
        }

        if (in_array('--help', $arguments, true)) {
            fwrite($this->output, $this->usage());

            return 0;
        }

        return $this->application->run($this->translateArguments($argv));
    }

    /**
     * @param list<string> $argv
     * @return list<string>
     */
    private function translateArguments(array $argv): array
    {
        $arguments = array_slice($argv, 1);
        $translated = [$argv[0] ?? 'sg'];
        $hasPattern = false;

        if ($arguments !== [] && in_array($arguments[0], ['scan', 'run', 'rewrite'], true)) {
            array_shift($arguments);
        }

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                foreach ($arguments as $value) {
                    $translated[] = $value;
                }

                break;
            }

            if ($argument === '--pattern') {
                $translated[] = '-p';
                $translated[] = $this->shiftValue($arguments, $argument);
                $hasPattern = true;
                continue;
            }

            if ($argument === '--rewrite') {
                $translated[] = '-r';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--threads') {
                $translated[] = '-j';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '-p') {
                $translated[] = '-p';
                $translated[] = $this->shiftValue($arguments, $argument);
                $hasPattern = true;
                continue;
            }

            if ($argument === '-r') {
                $translated[] = '-r';
                $translated[] = $this->shiftValue($arguments, $argument);
                continue;
            }

            if ($argument === '--lang' || $argument === '--json' || $argument === '--no-ignore' || $argument === '--hidden' || $argument === '--glob' || $argument === '--type' || $argument === '--type-not' || $argument === '--dry-run' || $argument === '--interactive' || $argument === '-j') {
                $translated[] = $argument;

                if (in_array($argument, ['--lang', '--glob', '--type', '--type-not', '-j'], true)) {
                    $translated[] = $this->shiftValue($arguments, $argument);
                }

                continue;
            }

            if (!$hasPattern && ($argument === '' || $argument[0] !== '-')) {
                $translated[] = '-p';
                $translated[] = $argument;
                $hasPattern = true;
                continue;
            }

            $translated[] = $argument;
        }

        return $translated;
    }

    /**
     * @param list<string> $arguments
     */
    private function shiftValue(array &$arguments, string $flag): string
    {
        $value = array_shift($arguments);

        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Missing value for %s.', $flag));
        }

        return $value;
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  sg -p PATTERN [options] [path...]
  sg scan -p PATTERN [options] [path...]
  sg rewrite -p PATTERN -r TEMPLATE [options] [path...]

Supported Options:
  -p, --pattern PATTERN     AST pattern.
  -r, --rewrite TEMPLATE    Rewrite template.
  -j, --threads N           Use N workers.
  --lang NAME               AST language. Default: php.
  --json                    Emit JSON output.
  --no-ignore               Ignore .gitignore and .phgrepignore rules.
  --hidden                  Include hidden files.
  --glob GLOB               Include only files whose paths match GLOB.
  --type NAME               Include a file type.
  --type-not NAME           Exclude a file type.
  --dry-run                 Print rewrites without writing files.
  --interactive             Confirm each rewritten file.
  --help                    Show this help.

TEXT;
    }
}
