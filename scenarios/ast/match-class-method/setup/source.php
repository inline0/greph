<?php

$client->send($message);
$mailer->send($welcome);
$mailer->queue($welcome);
