<?php

namespace Tools\Spctools;

// turn off all errors/warnings/notices
//error_reporting(0);

require 'spc.php';

// command line argument: file to be evaluated
$path = $argv[1];

// create spc object, and read the file
$spcFile = new Spc;
$spcFile->readFile($path);


echo "\n ***************"
   . "\n     FORMAT"
   . "\n ***************\n";

echo "SPC version:   " . $spcFile->spcVersion . "\n";
echo "SPC structure: " . $spcFile->spcStructure . "\n";
echo "Multifile:     " . ($spcFile->spcMulti ? 1 : 0) . "\n";
echo "Y format:      " . $spcFile->spcYFormat . "\n";


echo "\n ***************"
   . "\n      FLAGS"
   . "\n ***************\n";

print_r($spcFile->spcFlags);


echo "\n ***************"
   . "\n      HEADER"
   . "\n ***************\n";

print_r($spcFile->spcHeader);


echo "\n ***************"
   . "\n    OLDHEADER"
   . "\n ***************\n";

print_r($spcFile->spcOldHeader);


echo "\n ***************"
   . "\n    X VALUES"
   . "\n ***************\n";

$count = count($spcFile->xValues);
echo "Stored values: " . $count . "\n";
if ($count > 0) {
   echo "First:         " . reset($spcFile->xValues) . "\n";
   echo "Last:          " . end($spcFile->xValues) . "\n";
}
//echo implode(';', $spcFile->xValues) . "\n";


echo "\n ***************"
   . "\n    SUBHEADER"
   . "\n ***************\n";

print_r($spcFile->spcSubHeader);


echo "\n ***************"
   . "\n    Y VALUES"
   . "\n ***************\n";

$count = count($spcFile->yValues);
echo "Stored values: " . $count . "\n";
if ($count > 0) {
   $yFirst = reset($spcFile->yValues);
   $yMax   = max($spcFile->yValues);
   $yMin   = min($spcFile->yValues);
   $yLast  = end($spcFile->yValues);

   echo "First (float): " . $yFirst . "\n";
   echo "Max   (float): " . $yMax . "\n";
   echo "Min   (float): " . $yMin . "\n";
   echo "Last  (float): " . $yLast . "\n";

   // if the y values are stored as integers, recalculate the int values (useful for debugging)
   if ($spcFile->spcYFormat != 'g') {
      if (($spcFile->spcYFormat == 'i') and ($spcFile->spcVersion == 77)) {




         
      } else {







      }
   }
}


//echo implode(';', $spcFile->yValues) . "\n";


echo "\n ***************"
   . "\n    LOGHEADER"
   . "\n ***************\n";

print_r($spcFile->spcLogHeader);


echo "\n ***************"
   . "\n     LOGTEXT"
   . "\n ***************\n";

echo $spcFile->spcLogText . "\n";


echo "\n ***************"
   . "\n    STATISTICS"
   . "\n ***************\n";

print_r($spcFile->stats);

echo "\nFinished\n\n";