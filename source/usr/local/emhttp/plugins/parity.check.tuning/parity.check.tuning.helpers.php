<?PHP
/*
 * Helper routinesieg used by the parity.check.tining plugin
 *
 * Copyright 2019-2020, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * Limetech is given expliit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

if (file_exists('/var/local/emhttp/disks.ini')) {
	$diskp=parse_ini_file('/var/local/emhttp/disks.ini', true);
	$noParity = ($diskp['parity']['status']=='DISK_NP_DSBL') && ($diskp['parity2']['status']=='DISK_NP_DSBL');
} else {
	parityTuningLoggerTesting('System appears to still be initialising - exiting');
	exit(0);
}

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

// Set up some useful variables
$emhttpDir              = '/usr/local/emhttp';
$parityTuningPlugin     = 'parity.check.tuning';
// $parityTuningPluginDir = "plugins/$parityTuningPlugin";
$parityTuningBootDir    = "/boot/config/plugins/$parityTuningPlugin";
$parityTuningCfgFile    = "$parityTuningBootDir/$parityTuningPlugin.cfg";
$parityTuningEmhttpDir  = "$emhttpDir/plugins/$parityTuningPlugin";
$parityTuningPhpFile    = "$parityTuningEmhttpDir/$parityTuningPlugin.php";

$dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
$tempUnit = $dynamixCfg['display']['unit'];


// Handle Unraid version dependencies
$unraid = parse_ini_file("/etc/unraid-version");
$cfgVersionOK = (version_compare($unraid['version'],'6.7','>') >= 0);
$cfgRestartOK = (version_compare($unraid['version'],'6.9.0-rc1','>') > 0);

// Configuration information

if (file_exists("$parityTuningCfgFile")) {
	$parityTuningCfg = parse_ini_file("$parityTuningCfgFile");
} else {
	$parityTuningCfg = array();
}
// Set defaults for any missing/new values
setCfgValue('ParityTuningDebug', 'no');
setCfgValue('parityTuningIncrements', 'no');
setCfgValue('parityTuningFrequency', 'daily');
setCfgValue('parityTuningUnscheduled', 'no');
setCfgValue('parityTuningRecon', 'no');
setCfgValue('parityTuningClear', 'no');
setCfgValue('parityTuningRestart', 'no');
setCfgValue('parityTuningResumeHour', '0');
setCfgValue('parityTuningResumeMinute', '15');
setCfgValue('parityTuningPauseHour', '3');
setCfgValue('parityTuningPauseMinute', '30');
setCfgValue('parityTuningResumeCustom', '15 0 * * *');
setCfgValue('parityTuningPauseCustom', '30 3 * * *');
setCfgValue('parityTuningHeat', 'no');
setCfgValue('parityTuningHeatHigh','3');
setCfgValue('parityTuningHeatLow','8');
setCfgValue('parityTuningHeatShutdown', 'no');
setCfgValue('parityTuningHeatCritical', '1');

// Set a value if not already set
function setCfgValue ($key, $value) {
	global $parityTuningCfg;
	if (! array_key_exists($key,$parityTuningCfg)) {
	    $parityTuningCfg[$key] = $value;
	}
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
