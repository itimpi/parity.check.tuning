<?PHP
/*
 * Helper routines used by the parity.check.tining plugin
 *
 * Copyright 22019, Dave Walker (itimpi).
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
  
// Set up some useful variables
$emhttpDir             = '/usr/local/emhttp';
$parityTuningPlugin    = 'parity.check.tuning';
$parityTuningPluginDir = "$emhttpDir/plugins/$parityTuningPlugin";
$parityTuningConfigDir = "/boot/config/plugins/$parityTuningPlugin";
$parityTuningCfgFile   = "$parityTuningConfigDir/$parityTuningPlugin.cfg";
$parityTuningPhpFile   = "$parityTuningPluginDir/$parityTuningPlugin.php";

// useful for testing outside Gui
if (! function_exists("mk_option"))  require_once "/usr/local/emhttp/webGui/include/Helpers.php";
if (empty($var)) {
    // parityTuningLoggerDebug ("reading array state");
    $var = parse_ini_file ("/var/local/emhttp/var.ini");
}

// Read array status variable directly from /proc/mdstat
function get_mdstat_value ($key) {
    $cmd = "cat /proc/mdstat | grep \"$key=\"";
    return substr (exec ($cmd), strlen($key));
}

// Get configuration information
if (file_exists($parityTuningCfgFile)) {
    $parityTuningCfg = parse_ini_file("$parityTuningCfgFile");
}  else {
    // If no config file exists set up defailts
    $parityTuningCfg = array('ParityTuningDebug' => "yes");
    $parityTuningCfg['ParityTuningActive']       = "no";
    $parityTuningCfg['parityTuningFrequency']    = "daily";
    $parityTuningCfg['parityTuningmanual']       = "yes";
    $parityTuningCfg['parityTuningResumeHour']   = "0";
    $parityTuningCfg['parityTuningResumeMinute'] = "15";
    $parityTuningCfg['parityTuningPauseHour']    = "3";
    $parityTuningCfg['parityTuningPauseMinute']  = "30";    
    $parityTuningCfg['ParityTuningDebug']        = "yes";
}

# Write message to syslog
function parityTuningLogger($string) {
  $string = str_replace("'","",$string);
  $msg = "parity.check.tuning: $string";
  shell_exec("logger \'$msg\'");
}

# Write message to syslog if debug logging not switched off (or not defined)
function parityTuningLoggerDebug($string) {
  global $parityTuningCfg;
  if ($parityTuningCfg['parityTuningDebug'] === "yes") {
    parityTuningLogger("DEBUG: " . $string);
  };
}

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

# Setup any cron jobs required for this plugin according to user preferences
function parityTuningSetupCron() {
    parityTuningCancelCron();   // as a safety measure remove any existing jobs
}
# cancel Cron jobs for this plugin (if any)
function parityTuningCancelCron() {
}
?>