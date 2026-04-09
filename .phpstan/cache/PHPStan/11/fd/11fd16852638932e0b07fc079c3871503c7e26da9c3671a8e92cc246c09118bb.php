<?php declare(strict_types = 1);

// osfsl-/Users/dennis/Local Sites/fabrikat/inline0/phgrep/vendor/composer/../nikic/php-parser/lib/PhpParser/ParserFactory.php-PHPStan\BetterReflection\Reflection\ReflectionClass-PhpParser\ParserFactory
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-d2b3816a6707af2bb918f553170b2b480849a76b367b48a41b39635752d135b6-8.4.5-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'PhpParser\\ParserFactory',
        'filename' => '/Users/dennis/Local Sites/fabrikat/inline0/phgrep/vendor/composer/../nikic/php-parser/lib/PhpParser/ParserFactory.php',
      ),
    ),
    'namespace' => 'PhpParser',
    'name' => 'PhpParser\\ParserFactory',
    'shortName' => 'ParserFactory',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 8,
    'endLine' => 42,
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
    ),
    'immediateMethods' => 
    array (
      'createForVersion' => 
      array (
        'name' => 'createForVersion',
        'parameters' => 
        array (
          'version' => 
          array (
            'name' => 'version',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'PhpParser\\PhpVersion',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 14,
            'endLine' => 14,
            'startColumn' => 38,
            'endColumn' => 56,
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
            'name' => 'PhpParser\\Parser',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a parser targeting the given version on a best-effort basis. The parser will generally
 * accept code for the newest supported version, but will try to accommodate code that becomes
 * invalid in newer versions or changes in interpretation.
 */',
        'startLine' => 14,
        'endLine' => 24,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'PhpParser',
        'declaringClassName' => 'PhpParser\\ParserFactory',
        'implementingClassName' => 'PhpParser\\ParserFactory',
        'currentClassName' => 'PhpParser\\ParserFactory',
        'aliasName' => NULL,
      ),
      'createForNewestSupportedVersion' => 
      array (
        'name' => 'createForNewestSupportedVersion',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'PhpParser\\Parser',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a parser targeting the newest version supported by this library. Code for older
 * versions will be accepted if there have been no relevant backwards-compatibility breaks in
 * PHP.
 */',
        'startLine' => 31,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'PhpParser',
        'declaringClassName' => 'PhpParser\\ParserFactory',
        'implementingClassName' => 'PhpParser\\ParserFactory',
        'currentClassName' => 'PhpParser\\ParserFactory',
        'aliasName' => NULL,
      ),
      'createForHostVersion' => 
      array (
        'name' => 'createForHostVersion',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'PhpParser\\Parser',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a parser targeting the host PHP version, that is the PHP version we\'re currently
 * running on. This parser will not use any token emulation.
 */',
        'startLine' => 39,
        'endLine' => 41,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'PhpParser',
        'declaringClassName' => 'PhpParser\\ParserFactory',
        'implementingClassName' => 'PhpParser\\ParserFactory',
        'currentClassName' => 'PhpParser\\ParserFactory',
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