<?php

return [
    [
        'suite' => 'parallel-scaling',
        'category' => 'parallel',
        'name' => '1 worker',
        'pattern' => 'function',
        'fixed' => true,
        'jobs' => 1,
    ],
    [
        'suite' => 'parallel-scaling',
        'category' => 'parallel',
        'name' => '2 workers',
        'pattern' => 'function',
        'fixed' => true,
        'jobs' => 2,
    ],
    [
        'suite' => 'parallel-scaling',
        'category' => 'parallel',
        'name' => '4 workers',
        'pattern' => 'function',
        'fixed' => true,
        'jobs' => 4,
    ],
];
