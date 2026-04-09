<?php

return [
    [
        'suite' => 'ast-indexed-search',
        'category' => 'ast-indexed',
        'name' => 'Indexed new $CLASS()',
        'pattern' => 'new $CLASS()',
        'lang' => 'php',
    ],
    [
        'suite' => 'ast-indexed-search',
        'category' => 'ast-indexed',
        'name' => 'Indexed array($$$ITEMS)',
        'pattern' => 'array($$$ITEMS)',
        'lang' => 'php',
    ],
];
