<?php

return [
    [
        'suite' => 'indexed-summary',
        'category' => 'indexed-summary',
        'name' => 'Indexed count "function"',
        'pattern' => 'function',
        'fixed' => true,
        'count_only' => true,
    ],
    [
        'suite' => 'indexed-summary',
        'category' => 'indexed-summary',
        'name' => 'Indexed files with "function"',
        'pattern' => 'function',
        'fixed' => true,
        'files_with_matches' => true,
    ],
    [
        'suite' => 'indexed-summary',
        'category' => 'indexed-summary',
        'name' => 'Indexed files without "function"',
        'pattern' => 'function',
        'fixed' => true,
        'files_without_matches' => true,
    ],
];
