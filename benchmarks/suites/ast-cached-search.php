<?php

return [
    [
        'suite' => 'ast-cached-search',
        'category' => 'ast-cached',
        'name' => 'Cached new $CLASS()',
        'pattern' => 'new $CLASS()',
        'lang' => 'php',
    ],
    [
        'suite' => 'ast-cached-search',
        'category' => 'ast-cached',
        'name' => 'Cached array($$$ITEMS)',
        'pattern' => 'array($$$ITEMS)',
        'lang' => 'php',
    ],
];
