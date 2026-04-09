<?php declare(strict_types = 1);

// odsl-/Users/dennis/Local Sites/fabrikat/inline0/phgrep/src/Walker/FileTypeFilter.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Phgrep\Walker\FileTypeFilter
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.4.5-b3d756e47e4eb4e92e04edc33b1b0ea44616507fb496b3d6cf96e886cb6f033c',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Phgrep\\Walker\\FileTypeFilter',
        'filename' => '/Users/dennis/Local Sites/fabrikat/inline0/phgrep/src/Walker/FileTypeFilter.php',
      ),
    ),
    'namespace' => 'Phgrep\\Walker',
    'name' => 'Phgrep\\Walker\\FileTypeFilter',
    'shortName' => 'FileTypeFilter',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 7,
    'endLine' => 80,
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
      'TYPE_MAP' => 
      array (
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'name' => 'TYPE_MAP',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '[\'css\' => [\'css\', \'sass\', \'scss\'], \'html\' => [\'htm\', \'html\', \'phtml\'], \'js\' => [\'cjs\', \'js\', \'mjs\'], \'json\' => [\'json\'], \'md\' => [\'markdown\', \'md\'], \'php\' => [\'inc\', \'php\', \'php3\', \'php4\', \'php5\', \'php7\', \'php8\', \'phpt\', \'phtml\'], \'txt\' => [\'txt\'], \'ts\' => [\'ts\', \'tsx\'], \'xml\' => [\'xml\'], \'yaml\' => [\'yaml\', \'yml\']]',
          'attributes' => 
          array (
            'startLine' => 10,
            'endLine' => 21,
            'startTokenPos' => 33,
            'startFilePos' => 161,
            'endTokenPos' => 176,
            'endFilePos' => 563,
          ),
        ),
        'docComment' => '/** @var array<string, list<string>> */',
        'attributes' => 
        array (
        ),
        'startLine' => 10,
        'endLine' => 21,
        'startColumn' => 5,
        'endColumn' => 6,
      ),
    ),
    'immediateProperties' => 
    array (
      'includedExtensions' => 
      array (
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'name' => 'includedExtensions',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => '/** @var list<string> */',
        'attributes' => 
        array (
        ),
        'startLine' => 24,
        'endLine' => 24,
        'startColumn' => 5,
        'endColumn' => 38,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'excludedExtensions' => 
      array (
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'name' => 'excludedExtensions',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => '/** @var list<string> */',
        'attributes' => 
        array (
        ),
        'startLine' => 27,
        'endLine' => 27,
        'startColumn' => 5,
        'endColumn' => 38,
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
          'includeTypes' => 
          array (
            'name' => 'includeTypes',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 33,
                'endLine' => 33,
                'startTokenPos' => 211,
                'startFilePos' => 857,
                'endTokenPos' => 212,
                'endFilePos' => 858,
              ),
            ),
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
            'startLine' => 33,
            'endLine' => 33,
            'startColumn' => 33,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'excludeTypes' => 
          array (
            'name' => 'excludeTypes',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 33,
                'endLine' => 33,
                'startTokenPos' => 221,
                'startFilePos' => 883,
                'endTokenPos' => 222,
                'endFilePos' => 884,
              ),
            ),
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
            'startLine' => 33,
            'endLine' => 33,
            'startColumn' => 59,
            'endColumn' => 82,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param list<string> $includeTypes
 * @param list<string> $excludeTypes
 */',
        'startLine' => 33,
        'endLine' => 37,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Phgrep\\Walker',
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'currentClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'aliasName' => NULL,
      ),
      'matches' => 
      array (
        'name' => 'matches',
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
            'startLine' => 39,
            'endLine' => 39,
            'startColumn' => 29,
            'endColumn' => 40,
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
        'docComment' => NULL,
        'startLine' => 39,
        'endLine' => 53,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Phgrep\\Walker',
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'currentClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'aliasName' => NULL,
      ),
      'expandTypes' => 
      array (
        'name' => 'expandTypes',
        'parameters' => 
        array (
          'types' => 
          array (
            'name' => 'types',
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
            'startLine' => 59,
            'endLine' => 59,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param list<string> $types
 * @return list<string>
 */',
        'startLine' => 59,
        'endLine' => 79,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Phgrep\\Walker',
        'declaringClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'implementingClassName' => 'Phgrep\\Walker\\FileTypeFilter',
        'currentClassName' => 'Phgrep\\Walker\\FileTypeFilter',
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