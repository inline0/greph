<?php

return [
    [
        'suite' => 'ast-internal',
        'category' => 'ast-internal',
        'name' => 'new $CLASS() count-only',
        'pattern' => 'new $CLASS()',
        'lang' => 'php',
    ],
    [
        'suite' => 'ast-internal',
        'category' => 'ast-internal',
        'name' => 'array($$$ITEMS) count-only',
        'pattern' => 'array($$$ITEMS)',
        'lang' => 'php',
    ],
];
