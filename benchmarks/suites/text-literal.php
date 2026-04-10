<?php

return [
    [
        'suite' => 'text-literal',
        'category' => 'text',
        'name' => 'Literal "function"',
        'pattern' => 'function',
        'fixed' => true,
    ],
    [
        'suite' => 'text-literal',
        'category' => 'text',
        'name' => 'Literal case insensitive',
        'pattern' => 'function',
        'fixed' => true,
        'case_insensitive' => true,
    ],
    [
        'suite' => 'text-literal',
        'category' => 'text',
        'name' => 'Literal whole word',
        'pattern' => 'function',
        'fixed' => true,
        'whole_word' => true,
    ],
];
