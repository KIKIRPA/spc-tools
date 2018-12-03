<?php

namespace Tools\Spctools;

// turn off all errors/warnings/notices
error_reporting(0);

require 'spc.php';

// command line argument: file to be evaluated
$path = $argv[1];

// separator between the x and y values
$separator = "\t";

// create spc object, and read the file
$spcFile = new Spc;
$spcFile->readFile($path);

// get the (x, y) coordinates
$couples = $spcFile->getXY();

foreach ($couples as $couple) {
    echo implode($separator, $couple) . "\r\n";
}

