<?php

return [
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed literal "function"',
        'pattern' => 'function',
        'fixed' => true,
    ],
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed literal case insensitive',
        'pattern' => 'function',
        'fixed' => true,
        'case_insensitive' => true,
    ],
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed literal short "wp"',
        'pattern' => 'wp',
        'fixed' => true,
    ],
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed literal whole word',
        'pattern' => 'function',
        'fixed' => true,
        'whole_word' => true,
    ],
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed regex new instance',
        'pattern' => '\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)',
    ],
    [
        'suite' => 'indexed-text-cold',
        'category' => 'indexed-text-cold',
        'name' => 'Cold indexed regex array call',
        'pattern' => 'array\([^)]+\)',
    ],
];
