<?php

/*
    ThermoGalactic SPC file format evaluator

    limitations
     - only reads versions new LSB (0x4b = 75) and old (0x4d = 77)
     - does not read data stored as txyxy (x values contained in the subfiles), used in MS
     - only reads one subfile (1st)
     - does not read alternative axis names from the comments field
     - only reads x and y data (no z, w planes)  
*/


// turn off all errors/warnings/notices
error_reporting(0);

// command line argument
$file = $argv[1];

// read spc file
$filesize = filesize($file);
$handle = fopen($file, 'rb');



/*******************
       HEADER
  ******************/

// the first 2 bytes contain spc version
$sectionParts = 'c1ftflg/'   // flags, 1 byte
              . 'c1fversn';  // version, 1 byte --> 75 (0x4b new LSB 1st), 76 (0x4c new MSB 1st), 77 (0x4d old format)             
$header = unpack($sectionParts, fread($handle, 2));

// flags
$flags['tsprec'] = ($header['ftflg'] &   1) ? 1 : 0;    /* Single precision (16 bit) Y data if set. */
$flags['tcgram'] = ($header['ftflg'] &   2) ? 1 : 0;    /* Enables fexper in older software (CGM if fexper=0) */
$flags['tmulti'] = ($header['ftflg'] &   4) ? 1 : 0;    /* Multiple traces format (set if more than one subfile) */
$flags['trandm'] = ($header['ftflg'] &   8) ? 1 : 0;    /* If TMULTI and TRANDM=1 then arbitrary time (Z) values */
$flags['tordrd'] = ($header['ftflg'] &  16) ? 1 : 0;    /* If TMULTI abd TORDRD=1 then ordered but uneven subtimes */
$flags['talabs'] = ($header['ftflg'] &  32) ? 1 : 0;    /* Set if should use fcatxt axis labels, not fxtype etc.  */
$flags['txyxys'] = ($header['ftflg'] &  64) ? 1 : 0;    /* If TXVALS and multifile, then each subfile has own X's */
$flags['txvals'] = ($header['ftflg'] & 128) ? 1 : 0;    /* Floating X value array preceeds Y's  (New format only) */
$header['ftflg'] = $flags;

switch ($header["fversn"]) {
    case 75:
        $summary['version'] = "new LSB, 0x4b (75)";
    
        $sectionParts = 'c1fexper/'     /* Instrument technique code */
                      . 'c1fexp/'       /* Fraction scaling exponent integer (80h=>float) */
                      . 'i1fnpts/'      /* Integer number of points (or TXYXYS directory position) */
                      . 'e1ffirst/'     /* Floating X coordinate of first point */
                      . 'e1flast/'      /* Floating X coordinate of last point */
                      . 'i1fnsub/'      /* Integer number of subfiles (1 if not TMULTI) */
                      . 'c1fxtype/'     /* Type of X axis units */
                      . 'c1fytype/'     /* Type of Y axis units (see definitions below) */
                      . 'c1fztype/'     /* Type of Z axis units (see definitions below) */
                      . 'c1fpost/'      /* Posting disposition */
                      . 'i1fdate/'      /* Date/Time LSB: min=6b,hour=5b,day=5b,month=4b,year=12b */
                      . 'A9fres/'       /* Resolution description text (null terminated) */
                      . 'A9fsource/'    /* Source instrument description text (null terminated) */
                      . 's1fpeakpt/'    /* Peak point number for interferograms (0=not known) */
                      . 'A32fspare/'    /* Used for Array Basic storage */
                      . 'A130fcmnt/'    /* Null terminated comment ASCII text string */
                      . 'A30fcatxt/'    /* X,Y,Z axis label strings if ftflgs=TALABS */
                      . 'i1flogoff/'    /* File offset to log block or 0 */
                      . 'i1fmods/'      /* File Modification Flags (see below: 1=A,2=B,4=C,8=D..) */
                      . 'c1fprocs/'     /* Processing code (see GRAMSDDE.H) */
                      . 'c1flevel/'     /* Calibration level plus one (1 = not calibration data) */
                      . 'h1fsampin/'    /* Sub-method sample injection number (1 = first or only ) */
                      . 'g1ffactor/'    /* Floating data multiplier concentration factor (IEEE-32) */
                      . 'A48fmethod/'   /* Method/program/data filename w/extensions comma list */
                      . 'g1fzinc/'      /* Z subfile increment (0 = use 1st subnext-subfirst) */
                      . 'i1fwplanes/'   /* Number of planes for 4D with W dimension (0=normal) */
                      . 'g1fwinc/'      /* W plane increment (only if fwplanes is not 0) */
                      . 'c1fwtype/'     /* Type of W axis units */
                      . 'A187freserv';  /* Reserved (must be set to zero) */
        $header += unpack($sectionParts, fread($handle, 510)); // newSPC header: 512bytes (minus tflg+versn)

        // fix date
        $d = $header['fdate'];
        $date['year']  =  $d >> 20;             // 12bit 123456789012                           $date = year << 20
        $date['month'] = ($d >> 16) % (2**4);   // 4bit              1234                       $date += month << 16
        $date['day']   = ($d >> 11) % (2**5);   // 5bit                  12345                  $date += day << 5
        $date['hour']  = ($d >> 6) % (2**5);    // 5bit                       12345             $date += hour << 5
        $date['min']   = $d % (2**6);           // 6bit                            123456       $date += min
        $header['fdate'] = $date;

        // summary
        $summary['multi'] = ($header['fnsub'] > 1) ? 1 : 0;
        
        if     ($header['ftflg']['txyxys']) $summary['format'] = '-xy';  //each subfile contains its own x-values
        elseif ($header['ftflg']['txvals']) $summary['format'] = 'x-y';  //global x-values (preceding subheader)
        else                                $summary['format'] = 'gx-y'; //no x values are given, but they can be generated

        $summary['yfloat'] = ($header['fexp'] == -128) ? 1 : 0;
        break;

    case 76:
        $summary['version'] = "new MSB, 0x4c (76) -- UNSUPPORTED!";
        break;

    case 77:
        $summary['version'] = "old, 0x4d (77)";

        $sectionParts = 's1oexp/'       /* Word rather than byte */
                      . 'g1onpts/'      /* Floating number of points */
                      . 'g1ofirst/'     /* Floating X coordinate of first pnt (SP rather than DP) */
                      . 'g1olast/'      /* Floating X coordinate of last point (SP rather than DP) */
                      . 'c1oxtype/'     /* Type of X units */
                      . 'c1oytype/'     /* Type of Y units */
                      . 's1oyear/'      /* Year collected (0=no date/time) - MSB 4 bits are Z type */
                      . 'c1omonth/'     /* Month collected (1=Jan) */
                      . 'c1oday/'       /* Day of month (1=1st) */
                      . 'c1ohour/'      /* Hour of day (13=1PM) */
                      . 'c1ominute/'    /* Minute of hour */
                      . 'A8ores/'       /* Resolution text (null terminated unless 8 bytes used) */
                      . 's1opeakpt/'
                      . 's1onscans/'
                      . 'A28ospare/'
                      . 'A130ocmnt/'
                      . 'A30ocatxt';
                     // . 'A32osubh1';    /* Header for first (or main) subfile included in main header */
        $header += unpack($sectionParts, fread($handle, 222)); // oldSPC header: 256bytes (minus tflg+versn), but also includes first subheader, which we don't load now
        
        // summary
        $summary['multi'] = "unknown";
        $summary['format'] = 'gx-y'; //assumingly
        $summary['yfloat'] = ($header['oexp'] == 128) ? 1 : 0;

        
        break;

    default:
        $summary['version'] = "UNKNOWN - UNSUPPORTED!";
        break;
}



/*******************
        X AXIS
  ******************/

// x-values array stored before the subfiles (x-y), or generated x-values (gx-y)
if ($summary['format'] == 'x-y') {
    // x-values are stored as floats, 4bytes each
    $length = 4 * $header['fnpts'];
    $xValues = unpack('g*', fread($handle, $length));
} elseif (($header["fversn"] == 75) and ($summary['format'] == 'gx-y')) {
    $step = ($header['flast'] - $header['ffirst']) / ($header['fnpts'] - 1);
    $xValues = range($header['ffirst'], $header['flast'], $step);
} elseif (($header["fversn"] == 77) and ($summary['format'] == 'gx-y')) {
    $step = ($header['olast'] - $header['ofirst']) / ($header['onpts'] - 1);
    $xValues = range($header['ofirst'], $header['olast'], $step);
}

// bug in range: sometimes the last x-value is not added
if ($summary['format'] == 'gx-y') {
    $lastx = ($header["fversn"] == 75) ? $header['flast'] : $header['olast'];
    if ( (count($xValues) == $header['fnpts'] - 1) and (end($xValues) != $lastx) ) {
        $xValues[] = $lastx;
    }
}




/*******************
     1st SUBFILE
  ******************/

// no support for '-xy' format (mainly used for MS)
// no support for multiple files; we'll only read the first subfile for now
if ($summary['format'] != '-xy') {
    // read subheader, 32bytes (cchfffiif4s)
    $sectionParts = 'c1subflgs/'	/* Flags */
                  . 'c1subexp/'	    /* Exponent for sub-file's Y values (80h=>float) */
                  . 's1subindx/'	/* Integer index number of trace subfile (0=first) */
                  . 'g1subtime/'	/* Floating time for trace (Z axis corrdinate) */
                  . 'g1subnext/'	/* Floating time for next trace (May be same as beg) */
                  . 'g1subnois/'	/* Floating peak pick noise level if high byte nonzero */
                  . 'i1subnpts/'	/* Integer number of subfile points for TXYXYS type */
                  . 'i1subscan/'	/* Integer number of co-added scans or 0 (for collect) */
                  . 'g1subwlevel/'	/* Floating W axis value (if fwplanes non-zero) */
                  . 'c4subresv';	/* Reserved area (must be set to zero) */
    $subheader = unpack($sectionParts, fread($handle, 32));

    // split up subflgs
    $subflags['subchgd'] = ($subheader['subflgs'] &   1) ? 1 : 0;    /* Subflgs bit if subfile changed */
    $subflags['subnopt'] = ($subheader['subflgs'] &   8) ? 1 : 0;    /* Subflgs bit if peak table file should not be used */
    $subflags['submodf'] = ($subheader['subflgs'] & 128) ? 1 : 0;    /* Subflgs bit if subfile modified by arithmetic */
    $subheader['subflgs'] = $subflags;


    // read y-values
    $pnts = (isset($header['fnpts']) ? $header['fnpts'] : $header['onpts']);
    if ($summary['yfloat']) {
        // floating y-values (4 bytes)
        $length = 4 * $pnts;
        $yValues = unpack("g*", fread($handle, $length));
    } elseif ($header['ftflg']['tsprec'] and ($header["fversn"] == 75)) {
        // integer y-values (2 bytes) stored with exponent, only in new format?
        $length = 2 * $pnts;
        $yValuesInt = unpack("s*", fread($handle, $length));
        foreach ($yValuesInt as $y) $yValues[] = (2**($header['fexp'] - 16)) * $y;
    } elseif ($header["fversn"] == 75) {
        // integer y-values (4 bytes) stored with exponent, new format
        $length = 4 * $pnts;
        $yValuesInt = unpack("i*", fread($handle, $length));
        foreach ($yValuesInt as $y) $yValues[] = (2**($header['fexp'] - 32)) * $y;
    } else {
        // strangly formatted integer y-values (4 bytes) stored with exponent, old format
        // read as 4 unsigned chars, then swap byts 1&2 and 3&4
        $length = 4 * $pnts;
        $yValuesInt = unpack("C*", fread($handle, $length));
        $yValuesInt = array_reverse($yValuesInt);  //pop is much faster than shift
        for ($i = 0; $i <  $pnts; $i++) {
            $y =  array_pop($yValuesInt) * (256 ** 2);
            $y += array_pop($yValuesInt) * (256 ** 3);
            $y += array_pop($yValuesInt);
            $y += array_pop($yValuesInt) * 256;
            $yValues[] = (int)$y / (2**(32 - $header['oexp'])); // why the division, not a multiplication???
        }
    }
} 



/*******************
    MORE SUBFILES
  ******************/

$currentPosition = ftell($handle);
$remainingBytes = $filesize - $currentPosition;
if ($header['flogoff']) {
    $skippedSubfileBytes = $currentPosition - $header['flogoff'];
} else {
    $skippedSubfileBytes = $remainingBytes;
}



/*******************
       LOGBOOK
  ******************/

// only in the new format when flogoff is non-zero
if ($header['flogoff']) {
    // set pointer to the start of the logheader
    // fseek outputs 0 on success, -1 on failure
    if (fseek($handle, $header['flogoff']) == 0) {
        // read logheader, 32bytes (iiiii44s)
        $sectionParts = 'i1logsizd/'	/* byte size of disk block */
                      . 'i1logsizm/'	/* byte size of memory block */
                      . 'i1logtxto/'	/* byte offset to text */
                      . 'i1logbins/'	/* byte size of binary area (immediately after logstc) */
                      . 'i1logdsks/'	/* byte size of disk area (immediately after logbins) */
                      . 'A44logspar';	/* reserved (must be zero) */
        $logheader = unpack($sectionParts, fread($handle, 64));
    
        // checks
        $logBytes = $logheader['logsizd'] - $logheader['logtxto'];
        $currentPosition = ftell($handle);
        $remainingBytes = $filesize - $currentPosition;
        $skippedLogBytes = $remainingBytes - $logBytes;
        
        // read log
        //if ($skippedLogBytes >= 0) {
            // if the remaining bytes match or is larger than the log size, read the log
        //    $logcontent = fread($handle, $logBytes);
        //} else {
            // the remaining bytes are smaller than the indicated log size: read until EOF
            $logcontent = fread($handle, $remainingBytes);
        //}
    }
}






echo "\n ***************"
   . "\n     SUMMARY"
   . "\n ***************\n";

print_r($summary);

echo "\n ***************"
   . "\n      HEADER"
   . "\n ***************\n";

print_r($header);

if ($subheader) {
    echo "\n ***************"
    . "\n    SUBHEADER"
    . "\n ***************\n";
    print_r($subheader);
}

if ($xValues) {
    echo "\n ***************"
       . "\n     X VALUES"
       . "\n ***************\n";
    echo reset($xValues) . ", " . next($xValues) . ", " . next($xValues) . ", ..., " . end($xValues) . " (" . count($xValues) . " values)\n";
    //echo implode("; ", $xValues) . "\n";
} 

if ($yValues) {
    echo "\n ***************"
       . "\n     Y VALUES"
       . "\n ***************\n";
    if ($yValuesInt) {
        echo "INT: " . reset($yValuesInt) . ", " . next($yValuesInt) . ", " . next($yValuesInt) . ", ..., " . end($yValuesInt) . " (" . count($yValuesInt) . " values)\n";
    }
    echo "FLOAT: " . reset($yValues) . ", " . next($yValues) . ", " . next($yValues) . ", ..., " . end($yValues) . " (" . count($yValues) . " values)\n";
    //echo implode("; ", $yValues) . "\n";
} 

if ($logheader) {
    echo "\n ***************"
       . "\n    LOGHEADER"
       . "\n ***************\n";
       print_r($logheader);
}


if ($logcontent) {
    echo "\n ***************"
       . "\n    LOGHEADER"
       . "\n ***************\n";
       echo $logcontent . "\n";
}


echo "\n ***************"
   . "\n    STATISTICS"
   . "\n ***************\n";
echo "File size:    " . $filesize . " bytes\n";
echo "Skipped data: " . $skippedSubfileBytes . " bytes (unread subfiles)\n";
if ($logheader)
    echo "Skipped logs: " . $skippedLogBytes . " bytes\n";

echo "\n";


fclose($handle);

die();