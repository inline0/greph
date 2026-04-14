<?php

return [
    [
        'suite' => 'indexed-text-many',
        'category' => 'indexed-text-many',
        'name' => 'Multi-index literal "function"',
        'pattern' => 'function',
        'fixed' => true,
        'corpora' => ['wordpress'],
    ],
    [
        'suite' => 'indexed-text-many',
        'category' => 'indexed-text-many',
        'name' => 'Multi-index regex new instance',
        'pattern' => '\$[A-Za-z_][A-Za-z0-9_]* = new [A-Za-z_][A-Za-z0-9_]*\(\)',
        'corpora' => ['wordpress'],
    ],
];
