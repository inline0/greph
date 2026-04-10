<?php

declare(strict_types=1);

$grephRoot = getenv('GREPH_ROOT');

if (!is_string($grephRoot) || $grephRoot === '') {
    $grephRoot = dirname(__DIR__);
}

$grephRoot = rtrim($grephRoot, DIRECTORY_SEPARATOR);

require $grephRoot . '/vendor/autoload.php';
