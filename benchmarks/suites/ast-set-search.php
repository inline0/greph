<?php

return [
    [
        'suite' => 'ast-set-search',
        'category' => 'ast-indexed-set',
        'name' => 'Set indexed new $CLASS()',
        'pattern' => 'new $CLASS()',
        'lang' => 'php',
        'corpora' => ['wordpress'],
    ],
    [
        'suite' => 'ast-set-search',
        'category' => 'ast-cached-set',
        'name' => 'Set cached array($$$ITEMS)',
        'pattern' => 'array($$$ITEMS)',
        'lang' => 'php',
        'corpora' => ['wordpress'],
    ],
];
