<?php

declare(strict_types=1);

$phgrepRoot = getenv('PHGREP_ROOT');

if (!is_string($phgrepRoot) || $phgrepRoot === '') {
    $phgrepRoot = dirname(__DIR__);
}

$phgrepRoot = rtrim($phgrepRoot, DIRECTORY_SEPARATOR);

require $phgrepRoot . '/vendor/autoload.php';
