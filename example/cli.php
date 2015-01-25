<?php

require __DIR__ . '/../vendor/autoload.php';

$cli = new crodas\cli\Cli(".tmp");
//$cli->addDirectory(__DIR__ . '/../vendor');
$cli->addDirectory(__DIR__ . '/apps');
$cli->main();
