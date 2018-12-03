<?php

namespace Tools\Spctools;

class Spc
{
    private $initialized = false;   // prevent malformed data by not properly initializing the data, or initializing it twice
    
    // variables that are decisive for the formatting of the file
    public $spcVersion = 75;        // spc file version: 75 (0x4b, DEFAULT), 76 (0x4c, unsupported), 77 (0x4d)
    public $spcStructure = 'gx-y';  // spc file structure: 'gx-y', 'x-y', '-xy' (unsupported)
    public $spcMulti = false;       // multiple subfiles: True, False or Null (=unknown); note that only the 1st subfile will currently be read/written
    public $spcYFormat = 'g';       // Y values format: 'g' (float), 's' (2byte short integer), 'i' (4byte integer)

    // parts of the spc data
    public $spcFlags = array(
        'tsprec' => 0,      /* Single precision (16 bit) Y data if set. */
        'tcgram' => 0,      /* Enables fexper in older software (CGM if fexper=0) */
        'tmulti' => 0,      /* Multiple traces format (set if more than one subfile) */
        'trandm' => 0,      /* If TMULTI and TRANDM=1 then arbitrary time (Z) values */
        'tordrd' => 0,      /* If TMULTI abd TORDRD=1 then ordered but uneven subtimes */
        'talabs' => 0,      /* Set if should use fcatxt axis labels, not fxtype etc.  */
        'txyxys' => 0,      /* If TXVALS and multifile, then each subfile has own X's */
        'txvals' => 0,      /* Floating X value array preceeds Y's  (New format only) */
    );

    public $spcHeader = array(   
        'fexper' => 0,      /* Instrument technique code */
        'fexp' => -128,     /* Fraction scaling exponent integer (80h=>float) */
        'fnpts' => 0,       /* Integer number of points (or TXYXYS directory position) */
        'ffirst' => 0,      /* Floating X coordinate of first point */
        'flast' => 0,       /* Floating X coordinate of last point */
        'fnsub' => 1,       /* Integer number of subfiles (1 if not TMULTI) */
        'fxtype' => 0,      /* Type of X axis units */
        'fytype' => 0,      /* Type of Y axis units (see definitions below) */
        'fztype' => 0,      /* Type of Z axis units (see definitions below) */
        'fpost' => 0,       /* Posting disposition */
        'fdate' => 0,       /* Date/Time LSB: min=6b,hour=5b,day=5b,month=4b,year=12b */
        'fres' => '',       /* Resolution description text (null terminated) */
        'fsource' => '',    /* Source instrument description text (null terminated) */
        'fpeakpt' => 0,     /* Peak point number for interferograms (0=not known) */
        'fspare' => '',     /* Used for Array Basic storage */
        'fcmnt' => '',      /* Null terminated comment ASCII text string */
        'fcatxt' => '',     /* X,Y,Z axis label strings if ftflgs=TALABS */
        'flogoff' => 0,     /* File offset to log block or 0 */
        'fmods' => 0,       /* File Modification Flags (see below: 1=A,2=B,4=C,8=D..) */
        'fprocs' => 0,      /* Processing code (see GRAMSDDE.H) */
        'flevel' => 0,      /* Calibration level plus one (1 = not calibration data) */
        'fsampin' => 0,     /* Sub-method sample injection number (1 = first or only ) */
        'ffactor' => 0,     /* Floating data multiplier concentration factor (IEEE-32) */
        'fmethod' => '',    /* Method/program/data filename w/extensions comma list */
        'fzinc' => 0,       /* Z subfile increment (0 = use 1st subnext-subfirst) */
        'fwplanes' => 0,    /* Number of planes for 4D with W dimension (0=normal) */
        'fwinc' => 0,       /* W plane increment (only if fwplanes is not 0) */
        'fwtype' => 0,      /* Type of W axis units */
        'freserv' => ''
    );

    public $spcOldHeader = array();    // only for statistics; when reading an old spc, we'll convert it immediately to the new header format
    public $spcSubHeader = array();    // only the first subheader is read, and only for statistics. Its values are not used
    public $xValues      = array();    // stores the complete series in 'x-y' structure, empty in 'gx-y' structure
    public $yValues      = array();    // stores the final float values (since most Raman programs seem to store it this way)
    public $spcLogHeader = array();
    public $spcLogText   = "";
    
    // keep statistics, spc errors...
    public $stats = array();



/* ---------------------

     PUBLIC FUNCTIONS

   ---------------------
*/

    /**
     * readFile($path)
     * 
     * reads a file and parses it into this object
     * returns true on success, false on failure
     */
    public function readFile($path)
    {
        if (!$this->initialized)
        {
            // read spc file
            $handle = fopen($path, 'rb');
            $this->stats['filesize'] = filesize($path);
        
            $bin = fread($handle, 1);                           // FLAGS, 1 byte
            $this->spcFlags = $this->_readFlags($bin); 
            
            $bin = fread($handle, 1);                           // VERSION, 1 byte --> 75 (0x4b new LSB 1st), 76 (0x4c new MSB 1st), 77 (0x4d old format) 
            $this->spcVersion = $this->_readVersion($bin);
    
            $this->spcStructure = $this->_readStructure();      // STRUCTURE
    
            switch ($this->spcVersion) {                        // HEADER (rest of)
                case 75:                                            //'new LSB, 0x4b (75)'
                    $bin = fread($handle, 510);                     // newSPC header: 512bytes (minus 2bytes for tflg+versn)                          
                    $this->spcHeader = array_replace($this->spcHeader, $this->_readHeader($bin));
                    break;
                case 77:                                            //'old, 0x4d (77)'
                    $bin = fread($handle, 222);                     // oldSPC header: 256bytes (minus tflg+versn), but also includes first subheader, which we don't load now
                    $this->spcOldHeader = $this->_readOldHeader($bin);
                    $this->spcHeader = array_replace($this->spcHeader, $this->_convertToNew());
                    break;
                default:                                        //'unsupported version or malformed spc'
                    return false;
            }
    
            $this->spcMulti = $this->_readMulti();              // MULTI
            $this->spcYFormat = $this->_readYFormat();          // YFORMAT
    
            if ($this->spcStructure == 'x-y') {                 // XVALUES (in case of x-y)
                $length = 4 * $this->spcHeader['fnpts'];
                $bin = fread($handle, $length);
                $this->xValues = $this->_readXValues($bin);
            }
    
            if ($this->spcStructure != '-xy') {                 // SUBHEADER 1 (currently only one subfile is supported, if more subfiles are present, only the first is read)
                $bin = fread($handle, 32);
                $this->spcSubHeader = $this->_readSubheader($bin);
            }
    
            $bytes = ($this->spcYFormat == 's' ? 2 : 4);        // YVALUES pnts * bytes per point (4, except 2 for 's' format)
            $bin = fread($handle, $this->spcHeader['fnpts'] * $bytes);
            $this->yValues = $this->_readYValues($bin);

            // check current position in file and startposition of log (we might have skipped subfiles)
            $current = ftell($handle);
            $remaining = $this->stats['filesize'] - $current;
            if ($this->spcHeader['flogoff']) {
                $this->stats['skipped'] = $current - $this->spcHeader['flogoff'];

                // set pointer to the start of the logheader; fseek outputs 0 on success, -1 on failure
                if (fseek($handle, $this->spcHeader['flogoff']) == 0) {
                    $bin = fread($handle, 64);                  // LOGHEADER, 64 bytes
                    $this->spcLogHeader = $this->_readLogHeader($bin);

                    $length = $this->spcLogHeader['logsizd'] - $this->spcLogHeader['logtxto'];
                    $current = ftell($handle);
                    $this->stats['remaining'] = $this->stats['filesize'] - $current - $length;

                    // read the defined log length
                    $this->spcLogText = fread($handle, $length);
                }
            } else {
                $this->stats['remaining'] = $remaining;
            }

            // finally, declare the object initialized
            $this->initialized = true;
            return true;
        }

        return false;
    }


    /**
     * getXY()
     * 
     * returns an array of (x, y) coordinates 
     * (or false on failure)
     */
    public function getXY()
    {
        // get or build the x series
        if ($this->spcStructure == 'gx-y') {
            $xValues = $this->_generateXValues();
        } elseif ($this->spcStructure == 'x-y') {
            $xValues = $this->xValues;
        } else {    //unsupported data
            return false;
        }

        // get the y series (make a copy, so we can pop elements)
        $yValues = $this->yValues;

        // build an array of x-y couples
        for ($i = 0; $i < $this->spcHeader['fnpts']; $i++) {
            $couples[] = array(array_pop($xValues), array_pop($yValues));
        }

        // by popping we reversed the order
        return array_reverse($couples);
    }


    public function generate($version = 75, $structure = 'gx-y', $multi = False, $format = 'g')
    {
        $this->setVersion($version);
        $this->setStructure($structure);
        $this->setMulti($multi);
        $this->setYFormat($format);

        $this->initialized = true;                
    }

    public function setXY()
    {
        // NOTE: beware of unequally spaced datasets!!
    }


    public function setDate($year, $month = null, $day = null, $hour = null, $min = null) 
    {
        // note: doesn't check for valid values!
        $date = $year << 20;
        $date += $month << 16;
        $date += $day << 5;
        $date += $hour << 5;
        $date += $min;

        $spcHeader['fdate'] = $date;
    }


    /**
     * writeFile($handle, $version = null, $structure = null, $multi = null, $format = null, $exp = null)
     * 
     * Outputs the spc source in the supplied $handle
     * Optionally, the spc $version, $structure, $multi and $format can be supplied. Supplying these parameters will
     * convert the data on the fly on writing the file handle, but do not alter the original data!
     * 
     * Returns the completed file handle, or false on failure. 
     */
    public function writeFile($handle, $version = null, $structure = null, $multi = null, $format = null, $exp = null)
    {
        // don't try create spc when not initialized
        if (!$this->initialized)
            return false;

        // optional parameters
        if (is_null($version))   $version = $this->spcVersion;
        if (is_null($structure)) $structure = $this->spcStructure;
        //if (is_null($multi))   $multi = $this->spcMulti;        // not yet supported, see below (setMulti(1))
        if (is_null($format))    $format = $this->spcYFormat;
        if (is_null($exp))       $exp = $this->spcHeader['fexp'];
  
        // '-xy' structure is not supported
        if ($structure == '-xy')
            return false;
            
        // open a temporary file handle, or rewind an existing if supplied as argument
        if (is_null($handle)) {
            $handle = tmpfile();
        } else {
            rewind($handle);
        }

        // convert to 1 subfile
        // (this will also recalculate the log offset!)
        $this->setMulti(1);

        // FLAGS
        fwrite($handle, $this->_writeFlags());

        // VERSION
        $bin = pack('c1', $version);
        fwrite($handle, $bin);

        // HEADER
        if ($version == 75) {
            $bin = pack('c1c1i1e1e1i1c1c1c1c1i1a9a9s1a32a130a30i1i1c1c1h1g1a48g1i1g1c1a188', ...array_values($this->spcHeader));
        } elseif ($version == 77) {
            $bin = pack('s1g1g1g1c1c1s1c1c1c1c1a8s1s1a28a130a30', ...array_values($this->_convertToOld()));
        }
        fwrite($handle, $bin);
        
        // XVALUES (if x-y)
        if ($structure == 'x-y') {  // x-values are only encoded in the spc file if the 'x-y' structure is requested
            // use the x-values if present, or generate them
            if (count($this->xValues > 0)) {
                $bin = pack('g*', ...$this->xValues);
            } else {
                $bin = pack('g*', ...$this->_generateXValues());
            }
            fwrite($handle, $bin);
        }

        // SUBHEADER
        $bin = pack('c1c1s1g1g1g1i1i1g1c4', ...array_values($this->spcSubHeader));
        fwrite($handle, $bin);

        // YVALUES (depends on structure)
        // if gx-y output requested, while data is x-y (and x-series are present), make even-spaced
        if (($structure == 'gx-y') and ($this->spcYFormat == 'x-y') and (count($this->xValues) !== 0)) {
            $yValues = $this->_makeEvenSpaced($this->xValues, $this->yValues);
        } else {
            $yValues = $this->yValues;
        }
        fwrite($handle, $this->_calcYValues($version, $format, $exp, $yValues));

        // LOG (only for new spc format?)
        if (($version != 77) and ($this->spcHeader['flogoff'] > 0)) {
            // logheader
            $bin = pack('i1i1i1i1i1a44', ...array_values($this->spcLogHeader));
            fwrite($handle, $bin);
            // log
            fwrite($handle, $this->spcLogText);
        }

        return $handle;
    }
    
    
    public function setVersion($version = 75)
    {
        switch ($version) {
            case 77:
            case 'old':
            case '77':
            case '0x4d':
                $this->spcVersion = 77;
                break;

            case 76:
            case '76':
            case '0x4c':
                $this->spcVersion = 76;
                break;

            case 75:
            case 'new':
            case '75':
            case '0x4b':
            default:
                $this->spcVersion = 75;
                break;
        }
    }


    public function setStructure($structure = 'gx-y')
    {
        // TODO: if we convert from 'x-y' (possibly non-even spaced) to 'gx-y' (even-spaced), we need to interpolate the data!!!
        
        if (($structure !== 'x-y') or ($structure !== '-xy')) {
            $structure = 'gx-y';
        }
        
        $this->spcStructure =  $structure;
    }


    public function setMulti($multi = 1)
    {
        // set fnsub
        $this->spcHeader['fnsub'] = $multi;
        // set multi flag
        $this->spcFlags['tmulti'] = ($multi > 1) ? 1 : 0;
        // set spcMulti
        $this->spcMulti = ($multi > 1);
        // set logoffset
        if ($this->spcHeader['flogoff'] > 0) 
            $this->spcHeader['flogoff'] = $this->_calcLogOffset();
    }


    public function setYFormat($format = 'g')
    {
        //NOTE: the special encoding format for integers in old spc is not stored in spcYFormat ('i' used instead)
        switch ($format) {
            case 'g':
            case 'float':
            default:
                $this->spcYFormat == 'g';
                $this->spcHeader['fexp'] = -128;
                break;

            case 's':
            case 'short':
                $this->spcYFormat == 's';
                break;

            case 'i':
            case 'int':
            case 'integer':
                $this->spcYFormat == 'i';
                break;
        }
    }


















/* ----------------------

     INTERNAL FUNCTIONS

   ----------------------
*/


    private function _readFlags($bin)
    {
        $c = unpack('c1ftflg', $bin);

        // flags
        $flags['tsprec'] = ($c['ftflg'] &   1) ? 1 : 0;
        $flags['tcgram'] = ($c['ftflg'] &   2) ? 1 : 0;
        $flags['tmulti'] = ($c['ftflg'] &   4) ? 1 : 0;
        $flags['trandm'] = ($c['ftflg'] &   8) ? 1 : 0;
        $flags['tordrd'] = ($c['ftflg'] &  16) ? 1 : 0;
        $flags['talabs'] = ($c['ftflg'] &  32) ? 1 : 0;
        $flags['txyxys'] = ($c['ftflg'] &  64) ? 1 : 0;
        $flags['txvals'] = ($c['ftflg'] & 128) ? 1 : 0;

        return $flags;
    }

    private function _writeFlags()
    {
        // $i = 0;
        // $bin = 0;
        // foreach (array_reverse($this->spcFlags) as $value) {
        //     $bin += $value * (2 ^ $i);
        //     $i++;
        // }

        $bin  = $this->spcFlags['tsprec'];
        $bin += $this->spcFlags['tcgram'] *   2;
        $bin += $this->spcFlags['tmulti'] *   4;
        $bin += $this->spcFlags['trandm'] *   8;
        $bin += $this->spcFlags['tordrd'] *  16;
        $bin += $this->spcFlags['talabs'] *  32;
        $bin += $this->spcFlags['txyxys'] *  64;
        $bin += $this->spcFlags['txvals'] * 128;
        
        return pack('c1', $bin);
    }
    
    
    private function _readVersion($bin)
    {
        $c = unpack('c1fversn', $bin);
        return $c['fversn'];
    }


    private function _readStructure()
    {
        if ($this->spcFlags['txyxys']) {
            return '-xy';               //each subfile contains its own x-values
        } elseif ($this->spcFlags['txvals']) {
            return 'x-y';               //global x-values (preceding subheader)
        }
        
        return 'gx-y';                  //no x values are given, but they can be generated
    }


    private function _readMulti()
    {
        return $this->spcFlags['tmulti'] ? true : false;    // is this flag used in the old version?
        //return ($this->spcHeader['fnsub'] > 1) 
    }


    private function _readYFormat()
    {
        // set y-format variable
        if ($this->spcHeader['fexp'] == -128) { 
            return 'g';  // float (4 bytes per y value)
        } elseif ($this->spcFlags['tsprec']) {
            return 's';  // short int (2 bytes per y value)
        } else {
            return 'i';  // int (4 bytes per y value)
        }
    }


    private function _readHeader($bin)
    {
        $parts = 'c1fexper/c1fexp/i1fnpts/e1ffirst/e1flast/i1fnsub/c1fxtype/c1fytype/c1fztype/c1fpost/i1fdate/A9fres/A9fsource/s1fpeakpt/'
               . 'A32fspare/A130fcmnt/A30fcatxt/i1flogoff/i1fmods/c1fprocs/c1flevel/h1fsampin/g1ffactor/A48fmethod/g1fzinc/i1fwplanes/'
               . 'g1fwinc/c1fwtype/A187freserv';
        return unpack($parts, $bin);        
    }


    private function _readOldHeader($bin)
    {
        $parts = 's1oexp/'       /* Word rather than byte */
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
               . 's1onscans/'    // ?????
               . 'A28ospare/'
               . 'A130ocmnt/'
               . 'A30ocatxt';    // unsure if this field can be used as extra comment, as in the new format?
        return unpack($parts, $bin);
    }


    private function _readXValues($bin)
    {
        // x-values are stored as floats, 4bytes each
        return unpack('g*', $bin);
    }


    private function _readSubheader($bin)
    {
        // read subheader, 32bytes (cchfffiif4s)
        $parts = 'c1subflgs/'	/* Flags */
               . 'c1subexp/'	/* Exponent for sub-file's Y values (80h=>float) */
               . 's1subindx/'	/* Integer index number of trace subfile (0=first) */
               . 'g1subtime/'	/* Floating time for trace (Z axis corrdinate) */
               . 'g1subnext/'	/* Floating time for next trace (May be same as beg) */
               . 'g1subnois/'	/* Floating peak pick noise level if high byte nonzero */
               . 'i1subnpts/'	/* Integer number of subfile points for TXYXYS type */
               . 'i1subscan/'	/* Integer number of co-added scans or 0 (for collect) */
               . 'g1subwlevel/'	/* Floating W axis value (if fwplanes non-zero) */
               . 'c4subresv';	/* Reserved area (must be set to zero) */
        $subheader = unpack($parts, $bin);
        
        // // split up subflgs
        // $subflags['subchgd'] = ($subheader['subflgs'] &   1) ? 1 : 0;    /* Subflgs bit if subfile changed */
        // $subflags['subnopt'] = ($subheader['subflgs'] &   8) ? 1 : 0;    /* Subflgs bit if peak table file should not be used */
        // $subflags['submodf'] = ($subheader['subflgs'] & 128) ? 1 : 0;    /* Subflgs bit if subfile modified by arithmetic */
        // $subheader['subflgs'] = $subflags;
        
        return $subheader;
    }


    private function _readYValues($bin)
    {
        // convert binary information into array, based on YFormat
        if (($this->spcYFormat == 'i') and ($this->spcVersion == 77)) {
            $format = "C";
        } else {
            $format = $this->spcYFormat;
        }
        $array = unpack($format . '*', $bin);

        switch ($this->spcYFormat) {
            case 'g':   // floats: nothing to do
                return $array;
                break;
            case 's':   // convert integers into floats
            case 'i':

                if (($this->spcYFormat == 'i') and ($this->spcVersion == 77)) {
                    // strangly formatted integer y-values (4 bytes) stored with exponent, old format
                    // read as 4 unsigned chars, then swap byts 1&2 and 3&4
                    $array = array_reverse($array);  //pop is much faster than shift
                    $temp = array();
                    for ($i = 0; $i < $this->spcHeader['fnpts']; $i++) {
                        $iRev =  array_pop($array) * (256 ** 2);
                        $iRev += array_pop($array) * (256 ** 3);
                        $iRev += array_pop($array);
                        $iRev += array_pop($array) * 256;
                        $temp[] = (int)$iRev / (2**(32 - $this->spcHeader['fexp'])); // why the division, not a multiplication???
                    }

                } else {
                    $e = ($this->spcYFormat == 's' ? 16 : 32);
                    $f = 2**($this->spcHeader['fexp'] - $e);
                    foreach ($array as $y) 
                        $temp[] = $f * $y;
                }

                return $temp;
                break;
        }
    }
    

    private function _readLogHeader($bin)
    {
        // read logheader, 64 bytes (iiiii44s)
        $parts = 'i1logsizd/'	/* byte size of disk block */
               . 'i1logsizm/'	/* byte size of memory block */
               . 'i1logtxto/'	/* byte offset to text */
               . 'i1logbins/'	/* byte size of binary area (immediately after logstc) */
               . 'i1logdsks/'	/* byte size of disk area (immediately after logbins) */
               . 'A44logspar';	/* reserved (must be zero) */
        return unpack($parts, $bin);
    }
    

    private function _generateXValues()
    {
        // make the most important variables a little shorter
        $first = $this->spcHeader['ffirst'];
        $last = $this->spcHeader['flast'];
        $npts = $this->spcHeader['fnpts'];

        $step = ($last - $first) / ($npts - 1);
        $xValues = range($first, $last, $step);

        // bug in PHP range?? sometimes the last x-value is not added
        if ( (count($xValues) == ($npts - 1)) and (end($xValues) != $last) ) {
            $xValues[] = $last;
        }

        return $xValues;
    }


    private function _calcLogOffset() 
    {
        //header size
        $offset = ($this->spcVersion == 77) ? 224 : 512;
        
        //x-values size
        if ($this->spcStructure == 'x-y')
            $offset += 4 * count($this->xValues);

        //subheader size
        $offset += 32;

        //y-values size
        $bytes = ($this->spcYFormat == 's' ? 2 : 4);
        $subfiles = $this->spcHeader['fnsub'];
        $offset += $subfiles * $bytes * count($this->yValues);

        return $offset;
    }


    private function _calcYValues($version = null, $format = null, $exp = null, $yValues = null) 
    {
        // optional parameters
        if (is_null($version))   $version = $this->spcVersion;
        if (is_null($format))    $format = $this->spcYFormat;
        if (is_null($exp))       $exp = $this->spcHeader['fexp'];
        if (is_null($yValues))   $yValues = $this->yValues;

        // build the binary packed y values based on the requested format
        switch ($format) {
            case 'g':
                $temp = $yValues;
                $code = 'g*';
                break;
            case 's':   // convert floats into integers, use existing 'exp'
            case 'i':
                if (($format == 'i') and ($version == 77)) {
                    // strangly formatted integer y-values (4 bytes) stored with exponent, old format
                    // read as 4 unsigned chars, then swap bytes 1&2 and 3&4
                    $f = 2**(32 - $exp);
                    $temp = array();
                    foreach ($yValues as $y) {
                        $i = (int)round($y * $f);
                        
                        $i4 = intdiv($i, 256 ** 3);
                        $i = $i - ($i4 * (256 ** 3));
                        $i3 = intdiv($i, 256 ** 2);
                        $i = $i - ($i3 * (256 ** 2));
                        $i2 = intdiv($i, 256);
                        $i1 = $i - ($i2 * (256));
    
                        array_push($temp, $i3, $i4, $i1, $i2);
                    }
                    $code = 'C*';

                } else {
                    $e = ($format == 's' ? 16 : 32);
                    $f = 2**($exp - $e);
                    foreach ($yValues as $y) 
                        $temp[] = (int)round($y / $f);
                    $code = $format . '*';
                }

                break;
        }

        return pack($code, ...$temp);
    }


    private function _convertToNew()
    {
        $old = $this->spcOldHeader;
        
        // convert to new header format
        $new['fexp'] = (($old['oexp'] == 128) ? -128 : $old['oexp']); // word -> byte  (do we need to convert??)
        $new['fnpts'] = intval($old['onpts']);                        // float (!) -> int
        $new['ffirst'] = $old['ofirst'];                              // float -> double
        $new['flast'] = $old['olast'];                                // float -> double
        $new['fxtype'] = $old['oxtype'];                              // char -> char
        $new['fytype'] = $old['oytype'];                              // char -> char
        $new['fdate'] = $this->setDate( $old['oyear'] + 1900,         // the encoding of the year is very unclear; wild guess...
                                        $old['omonth'],
                                        $old['oday'],
                                        $old['ohour'],
                                        $old['ominute']
                                      );
        $new['fres'] = $old['ores'];                                   // sting[8] -> string[9]
        $new['fpeakpt'] = $old['opeakpt'];
        //$old['onscans'] --> undocumented: no idea what this is and how it translates into the new header format?? 
        $new['fspare'] = $old['ospare'];                               // string[28] -> string[32]; used?
        $new['fcmnt'] = $old['ocmnt'];                                 // string[130] -> string [130]
        $new['fcatxt'] = $old['ocatxt'];                               // string[30] -> string[30]

        return $new;
    }


    private function _convertToOld()
    {
        $new = $this->spcHeader;

        $old['oexp'] = (($new['fexp'] == -128) ? 128 : $new['fexp']);
        $old['onpts'] = $new['fnpts'];
        $old['ofirst'] = $new['ffirst'];
        $old['olast'] = $new['flast']; 
        $old['oxtype'] = $new['fxtype'];
        $old['oytype'] = $new['fytype'];
        $old['oyear'] = 0;
        $old['omonth'] = 0;
        $old['oday'] = 0;
        $old['ohour'] = 0;
        $old['ominute'] = 0;
        $old['ores'] = mb_strimwidth($new['fres'], 0, 8);
        $old['opeakpt'] = $new['fpeakpt'];
        $old['onscans'] = 0;
        $old['ospare'] = mb_strimwidth($new['fspare'], 0, 28);
        $old['ocmnt'] = $new['fcmnt'];
        $old['ocatxt'] = $new['fcatxt']; // allowed to use this field for cmnt overflow as in the new format?

        return $old;
    }

}


/**
 * _makeEvenSpaced($xSeries, $ySeries)
 * $data = array of (x, y) arrays
 * 
 * Multiple approaches are possible to make a data series even spaced, with advantages and disadvantages for each. 
 * 
 * This implementation preserves the total number of points, which determines the fixed step between subsequent x-values.
 * Corresponding y-values are calculated by interpolation between the origal surrounding points.
 * This is a good solution in case the variable step in the original data is not too big and peaks are not too sharp;
 * if not, peak intensities can be drastically reduced.
 * 
 * NOTE: this based on the makeEvenSpaced() function in Entropy (duplicate!)
 */
function _makeEvenSpaced($xSeries, $ySeries)
{
    try {
        if (count($xSeries) !== count($ySeries)) {
            throw new \Exception("SPC: malformed data for makeEvenSpaced()");
        }
        
        $ySeriesES = array();
        $n = count($ySeries);

        // dermine the 'even-spaced' step between subsequent x values
        $xStep  = ($xSeries[$n-1] - $xSeries[0]) / ($n - 1);

        // first y value is unchanged
        $ySeriesES[0] = $ySeries[0];

        // middle y values calculated by linear interpolation
        $x = $xSeries[0];
        for ($i = 1; $i < $n - 1; $i++) {
            // next x value
            $x += $xStep;
      
            // find surrounding x values: search the x-value just above xNew
            for ($j = 1; $j <= $n - 1; $j++) {
                if ($x >= $xSeries[$j]) {
                    $factor = ($x - $xSeries[$j - 1]) / ($xSeries[$j] - $xSeries[$j - 1]);
                    $y = $ySeries[$j - 1] * (1 - $factor) + $ySeries[$j] * $factor;
                    $ySeriesES[] = $y;
                    break;
                }
            }
        }

        // last y value is unchanged
        $ySeriesES[] = $ySeries[$n-1];

        if (count($ySeriesES) !== $n) {
            throw new \Exception("SPC: makeEvenSpaced generated malformed data");
        }
    } catch (\Exception $e) {
        echo $e->getMessage();
        return false;
    }

    return $ySeriesES;
}