<?php

return [
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex new instance',
        'pattern' => '\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)',
    ],
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex array call',
        'pattern' => 'array\([^)]+\)',
    ],
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex prefix literal',
        'pattern' => '^function ',
    ],
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex suffix literal',
        'pattern' => '\);$',
    ],
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex exact line literal',
        'pattern' => '^\}$',
    ],
    [
        'suite' => 'text-regex',
        'category' => 'text',
        'name' => 'Regex literal collapse',
        'pattern' => 'function',
    ],
];
