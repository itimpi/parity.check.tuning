<?PHP
/*
 * Helper routines used by the parity.check.tining plugin
 *
 * Copyright 2019-2021, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *oig
 * Limetech is given explicit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$parityTuningNotify = "$docroot/webGui/scripts/notify";

// Set up some useful constants used in multiple files
define('PARITY_TUNING_PLUGIN',      'parity.check.tuning');
define('EMHTTP_DIR' ,               '/usr/local/emhttp');
define('CONFIG_DIR' ,               '/boot/config');
define('PLUGINS_DIR' ,              CONFIG_DIR . '/plugins');
define('PARITY_TUNING_EMHTTP_DIR',  EMHTTP_DIR . '/plugins/' . PARITY_TUNING_PLUGIN);
define('PARITY_TUNING_PHP_FILE',    PARITY_TUNING_EMHTTP_DIR . '/' . PARITY_TUNING_PLUGIN . '.php');
define('PARITY_TUNING_BOOT_DIR',    PLUGINS_DIR . '/' . PARITY_TUNING_PLUGIN);
define('PARITY_TUNING_FILE_PREFIX', PARITY_TUNING_BOOT_DIR . '/' . PARITY_TUNING_PLUGIN . '.');
define('PARITY_TUNING_CFG_FILE',    PARITY_TUNING_FILE_PREFIX . 'cfg');
define('PARITY_TUNING_LOG_FILE',    PARITY_TUNING_FILE_PREFIX . 'log');
define('PARITY_TUNING_PARTIAL_FILE',PARITY_TUNING_FILE_PREFIX . 'partial');  // Create when partial check in progress (contains end sector value)

define('EMHTTP_VAR_DIR' ,           '/var/local/emhttp/');
define('PARITY_TUNING_EMHTTP_VAR_FILE',   EMHTTP_VAR_DIR . 'var.ini');
define('PARITY_TUNIN5_EMHTTP_DISKS_FILE', EMHTTP_VAR_DIR . 'disks.ini');

define('PARITY_TUNING_DATE_FORMAT', 'Y M d H:i:s');

$parityTuningCLI 		   = (basename($argv[0]) == 'parity.check');
$dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
$parityTuningTempUnit      = $dynamixCfg['display']['unit'] ?? 'C'; // Use Celsius if not set


// Handle Unraid version dependencies
$parityTuningUnraidVersion = parse_ini_file("/etc/unraid-version");
$parityTuningVersionOK = (version_compare($parityTuningUnraidVersion['version'],'6.7','>') >= 0);
$parityTuningRestartOK = (version_compare($parityTuningUnraidVersion['version'],'6.8.3','>') > 0);

// Configuration information

if (file_exists(PARITY_TUNING_CFG_FILE)) {
	$parityTuningCfg = parse_ini_file(PARITY_TUNING_CFG_FILE);
} else {
	$parityTuningCfg = array();
}

// Set defaults for any missing/new values
setCfgValue('parityTuningLogging', '0');
setCfgValue('parityTuningLogTarget', '0');
setCfgValue('parityTuningIncrements', '0');
setCfgValue('parityTuningFrequency', '0');
setCfgValue('parityTuningUnscheduled', '0');
setCfgValue('parityTuningAutomatic', '0');
setCfgValue('parityTuningRecon', '0');
setCfgValue('parityTuningClear', '0');
setCfgValue('parityTuningRestart', '0');
setCfgValue('parityTuningResumeHour', '0');
setCfgValue('parityTuningResumeMinute', '15');
setCfgValue('parityTuningPauseHour', '3');
setCfgValue('parityTuningPauseMinute', '30');
setCfgValue('parityTuningResumeCustom', '15 0 * * *');
setCfgValue('parityTuningPauseCustom', '30 3 * * *');
setCfgValue('parityTuningHeat', '0');
setCfgValue('parityTuningHeatHigh','3');
setCfgValue('parityTuningHeatLow','8');
setCfgValue('parityTuningHeatShutdown', '0');
setCfgValue('parityTuningHeatCritical', '1');
setCfgValue('parityProblemType', 'sector');
setCfgValue('parityProblemStartSector', 0);
setCfgValue('parityProblemStartPercent', 0);
setCfgValue('parityProblemEndSector', 100);
setCfgValue('parityProblemEndPercent', 0);
setCfgValue('parityProblemCorrect', 'no');


// Set a value if not already set for the configuration file
// ... and set a variable of the same name to the current value
function setCfgValue ($key, $value) {
	$cfgFile = $GLOBALS['parityTuningCfg'];
	if (! array_key_exists($key,$cfgFile)) {
		$cfgFile[$key] = $value;
	} else {
		if (empty($cfgFile[$key]) || $cfgFile[$key]== ' ' ) {
			$cfgFile[$key] = $value;
		}
		// Next 2 lines handle migration of settings to new values - will be removed in future release.
		if ($cfgFile[$key] == "no") $cfgFile[$key] = 0;
		if ($cfgFile[$key] == "yes") $cfgFile[$key] = 1;
		if ($cfgFile[$key] == "daily") $cfgFile[$key] = 0;
		if ($cfgFile[$key] == "custom") $cfgFile[$key] = 1;
	}
	$GLOBALS['parityTuningCfg'][$key] = $cfgFile[$key];		// TODO: Not sure thir is actually needed any more
	$GLOBALS[$key] = $cfgFile[$key];
}

if (file_exists(PARITY_TUNIN5_EMHTTP_DISKS_FILE)) {
	$disks=parse_ini_file(PARITY_TUNIN5_EMHTTP_DISKS_FILE, true);
	$parityTuningNoParity = ($disks['parity']['status']=='DISK_NP_DSBL') && ($disks['parity2']['status']=='DISK_NP_DSBL');
}

// load some state information.
// (written as a function to facilitate reloads)
function loadVars($delay = 0) {
    if ($delay > 0) {
		parityTuningLoggerTesting ("loadVars($delay)");
		sleep($delay);
	}
	if (! file_exists(PARITY_TUNING_EMHTTP_VAR_FILE)) {		// Protection against running plugin while system initializing so this file not yet created
		parityTuningLoggerTesting(sprintf('Trying to populate before %s created so ignored',  PARITY_TUNING_EMHTTP_VAR_FILE));
		return;
	}

   	$vars = parse_ini_file(PARITY_TUNING_EMHTTP_VAR_FILE);
    $size = $vars['mdResyncSize'];
	$pos  = $vars['mdResyncPos'];
	$GLOBALS['parityTuningVar']        = $vars;
	$GLOBALS['parityTuningServer']     = strtoupper($vars['NAME']);
    $GLOBALS['parityTuningPos']        = $pos;
    $GLOBALS['parityTuningSize']       = $size;
    $GLOBALS['parityTuningAction']     = $vars['mdResyncAction'];
    $GLOBALS['parityTuningActive']     = ($pos > 0);              // If array action is active (paused or running)
    $GLOBALS['parityTuningRunning']    = ($vars['mdResync'] > 0); // If array actimb on is running (i.e. not paused)
    $GLOBALS['parityTuningCorrecting'] = $vars['mdResyncCorr'];
    $GLOBALS['parityTuningErrors']     = $vars['sbSyncErrs'];
}


// Get the long text description of an array operation

function actionDescription($action, $correcting) {
    $act = explode(' ', $action );
    switch ($act[0]) {
        case 'recon':	// TODO use extra array entries to decide if disk rebuild in progress or merely parity sync
        				return _('Parity Sync') . '/' . _('Data Rebuild');
        case 'clear':   return _('Disk Clear');
        case 'check':   if (count($act) == 1) return _('Read-Check');
        				return (($correcting == 0) ? _('Non-Correcting') : _('Correcting')) . ' ' . _('Parity Check');
        default:        return sprintf('%s: %s',_('Unknown action'), $action);
    }
}

//	test if partial parity check in progress
//       ~~~~~~~~~~~~~~~~~~~
function parityTuningPartial() {
//       ~~~~~~~~~~~~~~~~~~~
	return file_exists(PARITY_TUNING_PARTIAL_FILE);
}

// Logging functions

// Write message to syslog and also to console if in CLI mode
// Change source according to whether doing partial check or not
function parityTuningLogger($string) {  
  $logTarget = $GLOBALS['parityTuningLogTarget'];
  parityTuningLoggerCLI ($string);
  $logName = parityTuningPartial() ? "Parity Problem Assistant" : "Parity Check Tuning";
  if ($logTarget > 0) {
	 $line = date(PARITY_TUNING_DATE_FORMAT) . ' ' . $GLOBALS['parityTuningServer'] . " $logName: $string\n";
	 file_put_contents(PARITY_TUNING_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
  }
  $string = str_replace("'","",$string);
  if ($logTarget < 2)  {
	$cmd = 'logger -t "' . $logName . '" "' . $string . '"';
	shell_exec($cmd);
  }
}

// Write message to syslog if debug or testing logging active
function parityTuningLoggerDebug($string) {
  if ($GLOBALS['parityTuningLogging'] > 0) parityTuningLogger('DEBUG:   ' . $string);
}

// Write message to syslog if testing logging active
function parityTuningLoggerTesting($string) {
  if ($GLOBALS['parityTuningLogging'] > 1) parityTuningLogger('TESTING: ' . $string);
}

function parityTuningLoggerCLI($string) {
  	if ($GLOBALS['parityTuningCLI']) echo $string . "\n";
}

// Useful matching functions

function startsWith($haystack, $beginning, $caseInsensitivity = false){
    if ($caseInsensitivity)
        return strncasecmp($haystack, $beginning, strlen($beginning)) === 0;
    else
        return strncmp($haystack, $beginning, strlen($beginning)) === 0;
}

function endsWith($haystack, $ending, $caseInsensitivity = false){
    if ($caseInsensitivity)
        return strcasecmp(substr($haystack, strlen($haystack) - strlen($ending)), $haystack) === 0;
    else
        return strpos($haystack, $ending, strlen($haystack) - strlen($ending)) !== false;
}

?>
