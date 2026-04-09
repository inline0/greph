<?php

declare(strict_types=1);

namespace Phgrep\Tests\Oracle;

use Phgrep\Ast\AstSearchOptions;
use Phgrep\Text\TextSearchOptions;
use Phgrep\Walker\FileTypeFilter;

final class FlagParser
{
    /** @var array<string, list<string>> */
    private const TYPE_MAP = [
        'css' => ['css', 'sass', 'scss'],
        'html' => ['htm', 'html', 'phtml'],
        'js' => ['cjs', 'js', 'mjs'],
        'json' => ['json'],
        'php' => ['inc', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phpt', 'phtml'],
        'ts' => ['ts', 'tsx'],
        'xml' => ['xml'],
        'yaml' => ['yaml', 'yml'],
    ];

    /**
     * @return array{
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
     *   glob: list<string>,
     *   dryRun: bool,
     *   interactive: bool,
     *   jobs: int,
     *   maxCount: ?int,
     *   beforeContext: int,
     *   afterContext: int,
     *   context: ?int,
     *   type: list<string>,
     *   typeNot: list<string>,
     *   lang: string
     * }
     */
    public function parse(Scenario $scenario): array
    {
        $arguments = $scenario->flags();
        $parsed = [
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
            'glob' => [],
            'dryRun' => false,
            'interactive' => false,
            'jobs' => 1,
            'maxCount' => null,
            'beforeContext' => 0,
            'afterContext' => 0,
            'context' => null,
            'type' => [],
            'typeNot' => [],
            'lang' => $scenario->language(),
        ];

        while ($arguments !== []) {
            /** @var string $argument */
            $argument = array_shift($arguments);

            $value = static function () use (&$arguments, $argument): string {
                $next = array_shift($arguments);

                if (!is_string($next)) {
                    throw new \InvalidArgumentException(sprintf('Missing flag value for %s.', $argument));
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
                case '--glob':
                    $parsed['glob'][] = $value();
                    break;
                case '--dry-run':
                    $parsed['dryRun'] = true;
                    break;
                case '--interactive':
                    $parsed['interactive'] = true;
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
            }
        }

        return $parsed;
    }

    public function textOptions(Scenario $scenario): TextSearchOptions
    {
        $flags = $this->parse($scenario);
        $context = $flags['context'];

        return new TextSearchOptions(
            fixedString: $flags['fixedString'],
            caseInsensitive: $flags['caseInsensitive'],
            wholeWord: $flags['wholeWord'],
            invertMatch: $flags['invertMatch'],
            maxCount: $flags['maxCount'],
            beforeContext: $context ?? $flags['beforeContext'],
            afterContext: $context ?? $flags['afterContext'],
            countOnly: $flags['countOnly'],
            filesWithMatches: $flags['filesWithMatches'],
            filesWithoutMatches: $flags['filesWithoutMatches'],
            jsonOutput: $flags['json'],
            jobs: $flags['jobs'],
            respectIgnore: !$flags['noIgnore'],
            includeHidden: $flags['hidden'],
            fileTypeFilter: $this->fileTypeFilter($flags['type'], $flags['typeNot']),
            globPatterns: $flags['glob'],
        );
    }

    public function astOptions(Scenario $scenario): AstSearchOptions
    {
        $flags = $this->parse($scenario);

        return new AstSearchOptions(
            language: $flags['lang'],
            jobs: $flags['jobs'],
            respectIgnore: !$flags['noIgnore'],
            includeHidden: $flags['hidden'],
            fileTypeFilter: $this->fileTypeFilter($flags['type'], $flags['typeNot']),
            globPatterns: $flags['glob'],
            dryRun: $flags['dryRun'],
            interactive: $flags['interactive'],
            jsonOutput: $flags['json'],
        );
    }

    /**
     * @param list<string> $includeTypes
     * @param list<string> $excludeTypes
     * @return list<string>
     */
    public function grepTypeGlobs(array $includeTypes, array $excludeTypes = []): array
    {
        $globs = [];

        foreach ($includeTypes as $type) {
            foreach ($this->extensionsForType($type) as $extension) {
                $globs[] = sprintf('--include=*.%s', $extension);
            }
        }

        foreach ($excludeTypes as $type) {
            foreach ($this->extensionsForType($type) as $extension) {
                $globs[] = sprintf('--exclude=*.%s', $extension);
            }
        }

        return $globs;
    }

    /**
     * @return list<string>
     */
    public function extensionsForType(string $type): array
    {
        $type = strtolower($type);

        return self::TYPE_MAP[$type] ?? [$type];
    }

    /**
     * @param list<string> $includeTypes
     * @param list<string> $excludeTypes
     */
    private function fileTypeFilter(array $includeTypes, array $excludeTypes): ?FileTypeFilter
    {
        if ($includeTypes === [] && $excludeTypes === []) {
            return null;
        }

        return new FileTypeFilter($includeTypes, $excludeTypes);
    }
}
