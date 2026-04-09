<?php

return [
    [
        'suite' => 'indexed-text',
        'category' => 'indexed-text',
        'name' => 'Indexed literal "function"',
        'pattern' => 'function',
        'fixed' => true,
    ],
    [
        'suite' => 'indexed-text',
        'category' => 'indexed-text',
        'name' => 'Indexed literal case insensitive',
        'pattern' => 'function',
        'fixed' => true,
        'case_insensitive' => true,
    ],
    [
        'suite' => 'indexed-text',
        'category' => 'indexed-text',
        'name' => 'Indexed literal whole word',
        'pattern' => 'function',
        'fixed' => true,
        'whole_word' => true,
    ],
    [
        'suite' => 'indexed-text',
        'category' => 'indexed-text',
        'name' => 'Indexed regex new instance',
        'pattern' => '\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)',
    ],
    [
        'suite' => 'indexed-text',
        'category' => 'indexed-text',
        'name' => 'Indexed regex array call',
        'pattern' => 'array\([^)]+\)',
    ],
];
