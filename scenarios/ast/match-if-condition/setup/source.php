<?php

if ($ready) {
    run();
}

if ($enabled && $ready) {
    run();
}

while ($ready) {
    run();
}
