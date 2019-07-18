<?PHP
/*
 * Helper routines used by the parity.check.tining plugin
 *
 * Copyright 2019, Dave Walker (itimpi).
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
$emhttpDir              = '/usr/local/emhttp';
$parityTuningPlugin     = 'parity.check.tuning';
// $parityTuningPluginDir = "plugins/$parityTuningPlugin";
$parityTuningBootDir    = "/boot/config/plugins/$parityTuningPlugin";
$parityTuningCfgFile    = "$parityTuningBootDir/$parityTuningPlugin.cfg";
$parityTuningEmhttpDir  = "$emhttpDir/plugins/$parityTuningPlugin";
$parityTuningPhpFile    = "$parityTuningEmhttpDir/$parityTuningPlugin.php";

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

// Get configuration information
if (file_exists($parityTuningCfgFile)) {
    $parityTuningCfg = parse_ini_file("$parityTuningCfgFile");
    // Set defaults for those upgrading from an earlier release for new options
    if (! array_key_exists('parityTuningHeatHigh',$parityTuningCfg)) {
      $parityTuningCfg['parityTuningHeatHigh']     = 3;
    }
    if (! array_key_exists('parityTuningHeatLow',$parityTuningCfg)) {
      $parityTuningCfg['parityTuningHeatLow']      = 8;
    }
    if (! array_key_exists('parityTuningResumeCustom',$parityTuningCfg)) {
      $parityTuningCfg['parityTuningResumeCustom'] = '15 0 * * *';
    }
    if (! array_key_exists('parityTuningPauseCustom',$parityTuningCfg)) {
      $parityTuningCfg['parityTuningPauseCustom']  = '30 3 * * *';
    }
}  else {
    // If no config file exists set up defaults
    $parityTuningCfg = array('ParityTuningDebug' => "no");
    $parityTuningCfg['parityTuningIncrements']   = "no";
    $parityTuningCfg['parityTuningFrequency']    = "daily";
    $parityTuningCfg['parityTuningUnscheduled']  = "no";
    $parityTuningCfg['parityTuningRecon']        = "no";
    $parityTuningCfg['parityTuningClear']        = "no";
    $parityTuningCfg['parityTuningRestart']      = "no";
    $parityTuningCfg['parityTuningResumeHour']   = "0";
    $parityTuningCfg['parityTuningResumeMinute'] = "15";
    $parityTuningCfg['parityTuningPauseHour']    = "3";
    $parityTuningCfg['parityTuningPauseMinute']  = "30";

    $parityTuningCfg['parityTuningHeat']         = "no";
    $parityTuningCfg['parityTuningHeatHigh']     = "3";
    $parityTuningCfg['parityTuningHeatLow']      = "8";

    $parityTuningCfg['parityTuningDebug']        = "no";
}
?>