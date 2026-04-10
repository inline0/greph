#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

(new \Greph\Benchmarks\SyntheticCorpusGenerator(__DIR__))->ensure();

fwrite(STDOUT, "Synthetic corpora generated.\n");
