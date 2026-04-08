<?php

declare(strict_types=1);

namespace Phgrep\Cli;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Output\GrepFormatter;
use Phgrep\Phgrep;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;

final class Application
{
    private GrepFormatter $grepFormatter;

    public function __construct(?GrepFormatter $grepFormatter = null)
    {
        $this->grepFormatter = $grepFormatter ?? new GrepFormatter();
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $arguments = $this->parseArguments($argv);

        if ($arguments['help']) {
            fwrite(STDOUT, $this->usage());

            return 0;
        }

        if ($arguments['astPattern'] !== null) {
            return $this->runAst($arguments);
        }

        return $this->runText($arguments);
    }

    /**
     * @param array{
     *   help: bool,
     *   fixedString: bool,
     *   caseInsensitive: bool,
     *   wholeWord: bool,
     *   invertMatch: bool,
     *   countOnly: bool,
     *   filesWithMatches: bool,
     *   filesWithoutMatches: bool,
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   dryRun: bool,
     *   interactive: bool,
     *   jobs: int,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   lang: string,
     *   astPattern: ?string,
     *   rewrite: ?string,
     *   pattern: ?string,
     *   paths: list<string>
     * } $arguments
     */
    private function runText(array $arguments): int
    {
        if ($arguments['pattern'] === null) {
            fwrite(STDERR, "Missing search pattern.\n");

            return 2;
        }

        $fileTypeFilter = $this->createFileTypeFilter($arguments['type'], $arguments['typeNot']);
        $beforeContext = $arguments['context'] ?? $arguments['beforeContext'];
        $afterContext = $arguments['context'] ?? $arguments['afterContext'];
        $options = new TextSearchOptions(
            fixedString: $arguments['fixedString'],
            caseInsensitive: $arguments['caseInsensitive'],
            wholeWord: $arguments['wholeWord'],
            invertMatch: $arguments['invertMatch'],
            maxCount: $arguments['maxCount'],
            beforeContext: $beforeContext,
            afterContext: $afterContext,
            countOnly: $arguments['countOnly'],
            filesWithMatches: $arguments['filesWithMatches'],
            filesWithoutMatches: $arguments['filesWithoutMatches'],
            jsonOutput: $arguments['json'],
            jobs: $arguments['jobs'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
        );

        $results = Phgrep::searchText($arguments['pattern'], $arguments['paths'], $options);

        if ($arguments['json']) {
            $payload = array_map(
                static fn ($result): array => [
                    'file' => $result->file,
                    'matches' => array_map(
                        static fn ($match): array => [
                            'line' => $match->line,
                            'column' => $match->column,
                            'content' => $match->content,
                            'matched_text' => $match->matchedText,
                            'captures' => $match->captures,
                        ],
                        $result->matches,
                    ),
                ],
                $results,
            );

            fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            fwrite(STDOUT, $this->grepFormatter->format($results, $options));
        }

        foreach ($results as $result) {
            if ($arguments['filesWithoutMatches']) {
                if (!$result->hasMatches()) {
                    return 0;
                }

                continue;
            }

            if ($result->hasMatches()) {
                return 0;
            }
        }

        return 1;
    }

    /**
     * @param array{
     *   help: bool,
     *   fixedString: bool,
     *   caseInsensitive: bool,
     *   wholeWord: bool,
     *   invertMatch: bool,
     *   countOnly: bool,
     *   filesWithMatches: bool,
     *   filesWithoutMatches: bool,
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   dryRun: bool,
     *   interactive: bool,
     *   jobs: int,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   lang: string,
     *   astPattern: ?string,
     *   rewrite: ?string,
     *   pattern: ?string,
     *   paths: list<string>
     * } $arguments
     */
    private function runAst(array $arguments): int
    {
        if ($arguments['astPattern'] === null) {
            fwrite(STDERR, "Missing AST pattern.\n");

            return 2;
        }

        $astPattern = $arguments['astPattern'];
        $fileTypeFilter = $this->createFileTypeFilter($arguments['type'], $arguments['typeNot']) ?? new FileTypeFilter(['php']);
        $options = new AstSearchOptions(
            language: $arguments['lang'],
            jobs: $arguments['jobs'],
            respectIgnore: !$arguments['noIgnore'],
            includeHidden: $arguments['hidden'],
            fileTypeFilter: $fileTypeFilter,
            dryRun: $arguments['dryRun'],
            interactive: $arguments['interactive'],
            jsonOutput: $arguments['json'],
        );

        if ($arguments['rewrite'] !== null) {
            $results = Phgrep::rewriteAst($astPattern, $arguments['rewrite'], $arguments['paths'], $options);
            $changed = false;

            foreach ($results as $result) {
                if (!$result->changed()) {
                    continue;
                }

                $changed = true;

                if ($arguments['dryRun']) {
                    fwrite(STDOUT, "=== {$result->file} ===\n");
                    fwrite(STDOUT, $result->rewrittenContents);

                    if (!str_ends_with($result->rewrittenContents, "\n")) {
                        fwrite(STDOUT, PHP_EOL);
                    }

                    continue;
                }

                if ($arguments['interactive']) {
                    fwrite(STDOUT, sprintf("Rewrite %s? [y/N] ", $result->file));
                    $answer = trim((string) fgets(STDIN));

                    if (!in_array(strtolower($answer), ['y', 'yes'], true)) {
                        continue;
                    }
                }

                file_put_contents($result->file, $result->rewrittenContents);
                fwrite(STDOUT, $result->file . PHP_EOL);
            }

            return $changed ? 0 : 1;
        }

        $matches = Phgrep::searchAst($astPattern, $arguments['paths'], $options);

        if ($arguments['json']) {
            $payload = array_map(
                static fn ($match): array => [
                    'file' => $match->file,
                    'start_line' => $match->startLine,
                    'end_line' => $match->endLine,
                    'start_file_pos' => $match->startFilePos,
                    'end_file_pos' => $match->endFilePos,
                    'code' => $match->code,
                ],
                $matches,
            );

            fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        } else {
            foreach ($matches as $match) {
                $code = trim(preg_replace('/\s+/', ' ', $match->code) ?? $match->code);
                fwrite(STDOUT, sprintf('%s:%d:%s', $match->file, $match->startLine, $code) . PHP_EOL);
            }
        }

        return $matches === [] ? 1 : 0;
    }

    /**
     * @param list<string> $argv
     * @return array{
     *   help: bool,
     *   fixedString: bool,
     *   caseInsensitive: bool,
     *   wholeWord: bool,
     *   invertMatch: bool,
     *   countOnly: bool,
     *   filesWithMatches: bool,
     *   filesWithoutMatches: bool,
     *   json: bool,
     *   noIgnore: bool,
     *   hidden: bool,
     *   dryRun: bool,
     *   interactive: bool,
     *   jobs: int,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   lang: string,
     *   astPattern: ?string,
     *   rewrite: ?string,
     *   pattern: ?string,
     *   paths: list<string>
     * }
     */
    private function parseArguments(array $argv): array
    {
        $arguments = array_slice($argv, 1);
        $parsed = [
            'help' => false,
            'fixedString' => false,
            'caseInsensitive' => false,
            'wholeWord' => false,
            'invertMatch' => false,
            'countOnly' => false,
            'filesWithMatches' => false,
            'filesWithoutMatches' => false,
            'json' => false,
            'noIgnore' => false,
            'hidden' => false,
            'dryRun' => false,
            'interactive' => false,
            'jobs' => 1,
            'maxCount' => null,
            'beforeContext' => 0,
            'afterContext' => 0,
            'context' => null,
            'type' => [],
            'typeNot' => [],
            'lang' => 'php',
            'astPattern' => null,
            'rewrite' => null,
            'pattern' => null,
            'paths' => [],
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            if ($argument === '--') {
                break;
            }

            if ($argument === '-h' || $argument === '--help') {
                $parsed['help'] = true;
                break;
            }

            if ($argument[0] !== '-') {
                if ($parsed['astPattern'] === null && $parsed['pattern'] === null) {
                    $parsed['pattern'] = $argument;
                } else {
                    $parsed['paths'][] = $argument;
                }

                continue;
            }

            $value = static function () use (&$arguments, $argument): string {
                $next = array_shift($arguments);

                if (!is_string($next)) {
                    throw new \InvalidArgumentException(sprintf('Missing value for %s.', $argument));
                }

                return $next;
            };

            switch ($argument) {
                case '-F':
                    $parsed['fixedString'] = true;
                    break;
                case '-i':
                    $parsed['caseInsensitive'] = true;
                    break;
                case '-w':
                    $parsed['wholeWord'] = true;
                    break;
                case '-v':
                    $parsed['invertMatch'] = true;
                    break;
                case '-c':
                    $parsed['countOnly'] = true;
                    break;
                case '-l':
                    $parsed['filesWithMatches'] = true;
                    break;
                case '-L':
                    $parsed['filesWithoutMatches'] = true;
                    break;
                case '--json':
                    $parsed['json'] = true;
                    break;
                case '--no-ignore':
                    $parsed['noIgnore'] = true;
                    break;
                case '--hidden':
                    $parsed['hidden'] = true;
                    break;
                case '--dry-run':
                    $parsed['dryRun'] = true;
                    break;
                case '--interactive':
                    $parsed['interactive'] = true;
                    break;
                case '-p':
                    $parsed['astPattern'] = $value();
                    break;
                case '-r':
                    $parsed['rewrite'] = $value();
                    break;
                case '-j':
                    $parsed['jobs'] = max(1, (int) $value());
                    break;
                case '-m':
                    $parsed['maxCount'] = max(1, (int) $value());
                    break;
                case '-A':
                    $parsed['afterContext'] = max(0, (int) $value());
                    break;
                case '-B':
                    $parsed['beforeContext'] = max(0, (int) $value());
                    break;
                case '-C':
                    $parsed['context'] = max(0, (int) $value());
                    break;
                case '--type':
                    $parsed['type'][] = $value();
                    break;
                case '--type-not':
                    $parsed['typeNot'][] = $value();
                    break;
                case '--lang':
                    $parsed['lang'] = $value();
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown argument: %s', $argument));
            }
        }

        foreach ($arguments as $argument) {
            if ($parsed['astPattern'] === null && $parsed['pattern'] === null) {
                $parsed['pattern'] = $argument;
            } else {
                $parsed['paths'][] = $argument;
            }
        }

        if ($parsed['paths'] === []) {
            $parsed['paths'] = ['.'];
        }

        return $parsed;
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    private function createFileTypeFilter(array $include, array $exclude): ?FileTypeFilter
    {
        if ($include === [] && $exclude === []) {
            return null;
        }

        return new FileTypeFilter($include, $exclude);
    }

    private function usage(): string
    {
        return <<<TEXT
Usage:
  phgrep [options] pattern [path...]
  phgrep -p pattern [options] [path...]
  phgrep -p pattern -r replacement [options] [path...]

Options:
  -F              Fixed-string search.
  -i              Case-insensitive search.
  -w              Whole-word search.
  -v              Invert matches.
  -c              Count matches per file.
  -l              List matching files.
  -L              List non-matching files.
  -A N            Show N lines after each match.
  -B N            Show N lines before each match.
  -C N            Show N lines of context before and after each match.
  -m N            Stop after N matches per file.
  -j N            Use N workers.
  -p PATTERN      AST search pattern.
  -r TEMPLATE     AST rewrite template when -p is active.
  --type NAME     Include a file type.
  --type-not NAME Exclude a file type.
  --lang NAME     AST language. Default: php.
  --json          Emit JSON output.
  --no-ignore     Ignore .gitignore and .phgrepignore rules.
  --hidden        Include hidden files.
  --dry-run       Print rewrites without writing files.
  --interactive   Confirm each rewritten file.
  -h, --help      Show this help.

TEXT;
    }
}
