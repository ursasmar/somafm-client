#!/usr/bin/env php
<?php

if (Phar::running()) {
	require 'phar://somafm-client.phar/vendor/autoload.php';
} else {
	require __DIR__ . '/vendor/autoload.php';
}

use Symfony\Component\Console\Application;
use App\Command\SomaFMCommand;
use App\Client\SomaFMClient;

$application = new Application();
$application->add(new SomaFMCommand(new SomaFMClient()));
try {
	$application->setDefaultCommand('soma:interactive', true);
	$application->run();
} catch (Exception $e) {
	echo "An error occurred: " . $e->getMessage();
}

__HALT_COMPILER();
