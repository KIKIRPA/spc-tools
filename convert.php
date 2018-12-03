<?php

namespace Tools\Spctools;

// turn off all errors/warnings/notices
//error_reporting(0);

require 'spc.php';

// command line argument: files to be read and created
$inFile = $argv[1];
$outFile = $argv[2];

// separator between the x and y values
$separator = "\t";

// create spc object, and read the file
$spcFile = new Spc;
$spcFile->readFile($inFile);

// and save it again!
$handle = fopen($outFile, 'wb');
$spcFile->writeFile($handle);
fclose($handle);
