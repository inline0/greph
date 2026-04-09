<?php

return [
    [
        'suite' => 'ast-parse',
        'category' => 'ast-parse',
        'name' => 'new $CLASS() parse-only',
        'pattern' => 'new $CLASS()',
        'lang' => 'php',
    ],
    [
        'suite' => 'ast-parse',
        'category' => 'ast-parse',
        'name' => 'array($$$ITEMS) parse-only',
        'pattern' => 'array($$$ITEMS)',
        'lang' => 'php',
    ],
];
