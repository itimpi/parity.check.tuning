<?PHP
/*
 * Helper routinesieg used by the parity.check.tining plugin
 *
 * Copyright 2019-2021, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *oig
 * Limetech is given expliit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$parityTuningNotify = "$docroot/webGui/scripts/notify";

// Set up some useful variables
$emhttpDir                 = '/usr/local/emhttp';
$parityTuningPlugin        = 'parity.check.tuning';
$parityTuningPluginDir     = "$emhttpDir/plugins/$parityTuningPlugin";
$parityTuningBootDir       = "/boot/config/plugins/$parityTuningPlugin";
$parityTuningCfgFile       = "$parityTuningBootDir/$parityTuningPlugin.cfg";
$parityTuningEmhttpDir     = "$emhttpDir/plugins/$parityTuningPlugin";
$parityTuningPhpFile       = "$parityTuningEmhttpDir/$parityTuningPlugin.php";
$parityTuningVarFile       = '/var/local/emhttp/var.ini';
$parityTuningCronFile      = "$parityTuningBootDir/$parityTuningPlugin.cron";	// File created to hold current cron settings for this plugin
$parityTuningProgressFile  = "$parityTuningBootDir/$parityTuningPlugin.progress";// Created when arry operation active to hold increment info
$parityTuningScheduledFile = "$parityTuningBootDir/$parityTuningPlugin.scheduled";// Created when we detect an array operation started by cron
$parityTuningHotFile       = "$parityTuningBootDir/$parityTuningPlugin.hot";	 // Created when paused because at least one drive fount do have rezched 'hot' temperature
$parityTuningCriticalFile  = "$parityTuningBootDir/$parityTuningPlugin.critical";// Created when parused besause at least one drive found to reach critical temperature
$parityTuningRestartFile   = "$parityTuningBootDir/$parityTuningPlugin.restart"; // Created if arry stopped with array operation active to hold restart info
$parityTuningDisksFile     = "$parityTuningBootDir/$parityTuningPlugin.disks";   // Copy of disks.ini when restart info sved to check disk configuration
$parityTuningTidyFile      = "$parityTuningBootDir/$parityTuningPlugin.tidy";	 // Create when we think there was a tidy shutdown
$parityTuningUncleanFile   = "$parityTuningBootDir/$parityTuningPlugin.unclean"; // Create when we think unclean shutdown forces a parity chack being abandoned
$parityTuningPartialFile   = "$parityTuningBootDir/$parityTuningPlugin.partial"; // Create when partial chesk in progress (contains end sector value)
$parityTuningSyncFile      = '/boot/config/forcesync';							 // Presence of file used by Unraid to detect unclean Shutdown (we currently ignore)
$parityTuningCLI 		   = (basename($argv[0]) == 'parity.check');
$dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
$tempUnit = $dynamixCfg['display']['unit'];

// Handle Unraid version dependencies
$parityTuningUnraidVersion = parse_ini_file("/etc/unraid-version");
$parityTuningVersionOK = (version_compare($parityTuningUnraidVersion['version'],'6.7','>') >= 0);
$parityTuningRestartOK = (version_compare($parityTuningUnraidVersion['version'],'6.9.0-rc1','>') > 0);

// Configuration information

if (file_exists($parityTuningCfgFile)) {
	$parityTuningCfg = parse_ini_file($parityTuningCfgFile);
} else {
	$parityTuningCfg = array();
}
// Set defaults for any missing/new values
setCfgValue('parityTuningLogging', '0');
setCfgValue('parityTuningIncrements', '0');
setCfgValue('parityTuningFrequency', '0');
setCfgValue('parityTuningUnscheduled', '0');
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
	if (! array_key_exists($key,$GLOBALS['parityTuningCfg'])) {
		$GLOBALS['parityTuningCfg'][$key] = $value;
	} else {
		// Next 2 lines handle migration of settings to new values - will be removed in future release.
		if ($GLOBALS['parityTuningCfg'][$key] == "no") $GLOBALS['parityTuningCfg'][$key] = 0;
		if ($GLOBALS['parityTuningCfg'][$key] == "yes") $GLOBALS['parityTuningCfg'][$key] = 1;
	}
	$GLOBALS[$key] = $GLOBALS['parityTuningCfg'][$key];
}

if (file_exists('/var/local/emhttp/disks.ini')) {
	$disks=parse_ini_file('/var/local/emhttp/disks.ini', true);
	$parityTuningNoParity = ($disks['parity']['status']=='DISK_NP_DSBL') && ($disks['parity2']['status']=='DISK_NP_DSBL');
}

// load some state information.
// (written as a function to facilitate reloads)
function loadVars($delay = 0) {
    parityTuningLoggerTesting ("loadVars($delay)");
    if ($delay > 0) sleep($delay);

    $varFile = $GLOBALS['parityTuningVarFile'];
	if (! file_exists($varFile)) {		// Protection against running plugin while system initialising so this file not yet created
		parityTuningLoggerTesting(sprintf('Trying to populate before %s created so ignored',  $varFile));
		return;
	}

   	$vars = parse_ini_file($varFile);
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

// Logging functions

// Write message to syslog and also to console if in CLI mode
function parityTuningLogger($string) {
  parityTuningLoggerCLI ($string);
  $string = str_replace("'","",$string);
  $cmd = 'logger -t "' . basename($GLOBALS['argv'][0]) . '" "' . $string . '"';
  shell_exec($cmd);
}

// Write message to syslog if debug or testing logging active
function parityTuningLoggerDebug($string) {
  if ($GLOBALS['parityTuningLogging'] > 0) parityTuningLogger('DEBUG: ' . $string);
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
