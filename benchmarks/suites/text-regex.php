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
];
