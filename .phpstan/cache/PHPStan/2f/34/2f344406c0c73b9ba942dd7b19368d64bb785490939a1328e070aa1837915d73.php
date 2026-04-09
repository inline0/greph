<?php declare(strict_types = 1);

// odsl-/Users/dennis/Local Sites/fabrikat/inline0/phgrep/src/Cli/Application.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Phgrep\Cli\Application
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.4.5-302b0093dbd8ba453d59faea6086bc39af5c17053f1b379fb0ef82c0f6b2473d',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Phgrep\\Cli\\Application',
        'filename' => '/Users/dennis/Local Sites/fabrikat/inline0/phgrep/src/Cli/Application.php',
      ),
    ),
    'namespace' => 'Phgrep\\Cli',
    'name' => 'Phgrep\\Cli\\Application',
    'shortName' => 'Application',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 16,
    'endLine' => 619,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'grepFormatter' => 
      array (
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'name' => 'grepFormatter',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Phgrep\\Output\\GrepFormatter',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 18,
        'endLine' => 18,
        'startColumn' => 5,
        'endColumn' => 41,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'input' => 
      array (
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'name' => 'input',
        'modifiers' => 4,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * @var resource
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 23,
        'endLine' => 23,
        'startColumn' => 5,
        'endColumn' => 19,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'output' => 
      array (
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'name' => 'output',
        'modifiers' => 4,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * @var resource
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 28,
        'endLine' => 28,
        'startColumn' => 5,
        'endColumn' => 20,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'errorOutput' => 
      array (
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'name' => 'errorOutput',
        'modifiers' => 4,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * @var resource
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 33,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 25,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'grepFormatter' => 
          array (
            'name' => 'grepFormatter',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 41,
                'endLine' => 41,
                'startTokenPos' => 107,
                'startFilePos' => 752,
                'endTokenPos' => 107,
                'endFilePos' => 755,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
              'data' => 
              array (
                'types' => 
                array (
                  0 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'Phgrep\\Output\\GrepFormatter',
                      'isIdentifier' => false,
                    ),
                  ),
                  1 => 
                  array (
                    'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                    'data' => 
                    array (
                      'name' => 'null',
                      'isIdentifier' => true,
                    ),
                  ),
                ),
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 41,
            'endLine' => 41,
            'startColumn' => 9,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'input' => 
          array (
            'name' => 'input',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 42,
                'endLine' => 42,
                'startTokenPos' => 114,
                'startFilePos' => 775,
                'endTokenPos' => 114,
                'endFilePos' => 778,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 42,
            'endLine' => 42,
            'startColumn' => 9,
            'endColumn' => 21,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'output' => 
          array (
            'name' => 'output',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 43,
                'endLine' => 43,
                'startTokenPos' => 121,
                'startFilePos' => 799,
                'endTokenPos' => 121,
                'endFilePos' => 802,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 43,
            'endLine' => 43,
            'startColumn' => 9,
            'endColumn' => 22,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'errorOutput' => 
          array (
            'name' => 'errorOutput',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 44,
                'endLine' => 44,
                'startTokenPos' => 128,
                'startFilePos' => 828,
                'endTokenPos' => 128,
                'endFilePos' => 831,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 44,
            'endLine' => 44,
            'startColumn' => 9,
            'endColumn' => 27,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param resource|null $input
 * @param resource|null $output
 * @param resource|null $errorOutput
 */',
        'startLine' => 40,
        'endLine' => 50,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'run' => 
      array (
        'name' => 'run',
        'parameters' => 
        array (
          'argv' => 
          array (
            'name' => 'argv',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 25,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param list<string> $argv
 */',
        'startLine' => 55,
        'endLine' => 70,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'runText' => 
      array (
        'name' => 'runText',
        'parameters' => 
        array (
          'arguments' => 
          array (
            'name' => 'arguments',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 104,
            'endLine' => 104,
            'startColumn' => 30,
            'endColumn' => 45,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
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
 *   glob: list<string>,
 *   dryRun: bool,
 *   interactive: bool,
 *   showFileNames: ?bool,
 *   showLineNumbers: bool,
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
 */',
        'startLine' => 104,
        'endLine' => 177,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'runAst' => 
      array (
        'name' => 'runAst',
        'parameters' => 
        array (
          'arguments' => 
          array (
            'name' => 'arguments',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 211,
            'endLine' => 211,
            'startColumn' => 29,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
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
 *   glob: list<string>,
 *   dryRun: bool,
 *   interactive: bool,
 *   showFileNames: ?bool,
 *   showLineNumbers: bool,
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
 */',
        'startLine' => 211,
        'endLine' => 295,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'parseArguments' => 
      array (
        'name' => 'parseArguments',
        'parameters' => 
        array (
          'argv' => 
          array (
            'name' => 'argv',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 330,
            'endLine' => 330,
            'startColumn' => 37,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
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
 *   glob: list<string>,
 *   dryRun: bool,
 *   interactive: bool,
 *   showFileNames: ?bool,
 *   showLineNumbers: bool,
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
 */',
        'startLine' => 330,
        'endLine' => 494,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => true,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'createFileTypeFilter' => 
      array (
        'name' => 'createFileTypeFilter',
        'parameters' => 
        array (
          'include' => 
          array (
            'name' => 'include',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 500,
            'endLine' => 500,
            'startColumn' => 43,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'exclude' => 
          array (
            'name' => 'exclude',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 500,
            'endLine' => 500,
            'startColumn' => 59,
            'endColumn' => 72,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
          'data' => 
          array (
            'types' => 
            array (
              0 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'Phgrep\\Walker\\FileTypeFilter',
                  'isIdentifier' => false,
                ),
              ),
              1 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param list<string> $include
 * @param list<string> $exclude
 */',
        'startLine' => 500,
        'endLine' => 507,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'usage' => 
      array (
        'name' => 'usage',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 509,
        'endLine' => 547,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'shouldDisplayFileNames' => 
      array (
        'name' => 'shouldDisplayFileNames',
        'parameters' => 
        array (
          'arguments' => 
          array (
            'name' => 'arguments',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 555,
            'endLine' => 555,
            'startColumn' => 45,
            'endColumn' => 60,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param array{
 *   paths: list<string>,
 *   showFileNames: ?bool
 * } $arguments
 */',
        'startLine' => 555,
        'endLine' => 572,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'displayTextResults' => 
      array (
        'name' => 'displayTextResults',
        'parameters' => 
        array (
          'results' => 
          array (
            'name' => 'results',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 578,
            'endLine' => 578,
            'startColumn' => 41,
            'endColumn' => 54,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param list<TextFileResult> $results
 * @return list<TextFileResult>
 */',
        'startLine' => 578,
        'endLine' => 603,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'displayPath' => 
      array (
        'name' => 'displayPath',
        'parameters' => 
        array (
          'path' => 
          array (
            'name' => 'path',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 605,
            'endLine' => 605,
            'startColumn' => 34,
            'endColumn' => 45,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 605,
        'endLine' => 608,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'writeOutput' => 
      array (
        'name' => 'writeOutput',
        'parameters' => 
        array (
          'contents' => 
          array (
            'name' => 'contents',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 610,
            'endLine' => 610,
            'startColumn' => 34,
            'endColumn' => 49,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 610,
        'endLine' => 613,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
      'writeError' => 
      array (
        'name' => 'writeError',
        'parameters' => 
        array (
          'contents' => 
          array (
            'name' => 'contents',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 615,
            'endLine' => 615,
            'startColumn' => 33,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 615,
        'endLine' => 618,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Cli',
        'declaringClassName' => 'Phgrep\\Cli\\Application',
        'implementingClassName' => 'Phgrep\\Cli\\Application',
        'currentClassName' => 'Phgrep\\Cli\\Application',
        'aliasName' => NULL,
      ),
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));