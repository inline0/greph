<?php

return [
    [
        'suite' => 'parallel-count-scaling',
        'category' => 'parallel',
        'name' => '1 worker',
        'pattern' => 'function\s+[A-Za-z_]',
        'fixed' => false,
        'count_only' => true,
        'jobs' => 1,
    ],
    [
        'suite' => 'parallel-count-scaling',
        'category' => 'parallel',
        'name' => '2 workers',
        'pattern' => 'function\s+[A-Za-z_]',
        'fixed' => false,
        'count_only' => true,
        'jobs' => 2,
    ],
    [
        'suite' => 'parallel-count-scaling',
        'category' => 'parallel',
        'name' => '4 workers',
        'pattern' => 'function\s+[A-Za-z_]',
        'fixed' => false,
        'count_only' => true,
        'jobs' => 4,
    ],
];
