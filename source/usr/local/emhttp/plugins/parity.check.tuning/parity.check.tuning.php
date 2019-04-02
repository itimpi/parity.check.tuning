#!/usr/bin/php
<?PHP
/*
 * Script that is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file command; or from another script.
 *
 * It takes a single parameter descrbing the action required.   If no explicit
 * action is specified then it merely updates the cron jobs for this plugin.
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

require_once "/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php";

if (empty($argv)) {
  parityTuningLoggerDebug("ERROR: No action specified");
  exit(0);
}

// Some useful variables
$parityTuningStateFile    = "$parityTuningBootDir/$parityTuningPlugin.state";
$parityTuningCronFile     = "$parityTuningBootDir/$parityTuningPlugin.cron";
$parityTuningProgressFile = "$parityTuningBootDir/$parityTuningPlugin.progress";
$parityTuningHistoryFile  = "$parityTuningBootDir/$parityTuningPlugin.history";
$parityTuningActiveFile   = "$parityTuningBootDir/$parityTuningPlugin.active";

$var = parse_ini_file('/var/local/emhttp/var.ini');

$pos    = $var['mdResyncPos'];
$size   = $var['mdResyncSize'];
$action = $var['mdResyncAction'];

$percent = sprintf ("%.1f%%", ($pos/$size*100));
$completed = sprintf (" (%s completed) ", $percent);
$dateformat = 'Y.m.d:H.i.s';
$active = ($pos > 0);                       // If array action is active (paused or running)
$running = ($var['mdResync'] > 0);       // If array action is running (i.e. not paused)

// This plugin will never do anything if array is not started
// TODO Check if Maintenance mode has a different value for the state

if ($var['mdState'] != 'STARTED') {
    parityTuningLoggerDebug ('mdState=' . $var['mdState']);
    parityTuningLogger('Array not started so no action taken');
    exit(0);
}


// Take the action requested via the command line argument(s)
// Effectively each command line option is an event type1

$command = trim($argv[1]);
switch ($command) {

    case 'tidy':
        // This code is used to 'housekeep' the plugin's folder on the flash drvie
        // removing any old stte files to avoid them building up forever.

        exit (0);
        // TODO: complete this functionality
        $dir = opendir($parityTuningBootDir);
        while (($file = readdir($dir)) != false) {
            if (startswith($file,$parityTuningStateFile)) {
                $modified = filemtime($file);
                $today = date();
                $diff = date_diff($today, $modified, TRUE);
                if ($diff['d'] > 7) {
                }
            }
        }
        closedir($dir);
        exit (0);

    case 'updatecron':
        // This is called any time that the user has updated the settings for this plugin to reset any cron schedules.

        // Create the 'tidy' cron settings for this plugin (we always want these to be present)
        $dailyfile='/etc/cron.daily/parity.check.tuning.tidy';
        if (!file_exists($dailyfile)) {
            $handle = fopen ($dailyfile, "w");
            fwrite($handle,"#!/bin/sh\n");
            fwrite($handle, "$parityTuningPhpFile \"tidy\" &> /dev/null\n");
            fclose ($handle);
            @chmod($dailyfile,0755);
        }
        @unlink ($parityTuningCronFile);
        if (($parityTuningCfg['parityTuningIncrements'] == "no") && ($parityTuningCfg['parityTuningHeat'] == 'no')) {
            parityTuningLoggerDebug("No cron events for this plugin are needed");
        } else {
            $handle = fopen ($parityTuningCronFile, "w");
            fwrite($handle, "\n# Generated schedules for $parityTuningPlugin\n");
            if ($parityTuningCfg['parityTuningIncrements'] == "yes") {
                fwrite($handle, $parityTuningCfg['parityTuningResumeMinute']  . " " .
                                ($parityTuningCfg['parityTuningFrequency'] === 'hourly' ? '*' : $parityTuningCfg['parityTuningResumeHour'])
                                . " * * * $parityTuningPhpFile \"resume\" &> /dev/null\n");
                fwrite($handle, $parityTuningCfg['parityTuningPauseMinute'] . " " .
                                ($parityTuningCfg['parityTuningFrequency'] === 'hourly' ? '*' : $parityTuningCfg['parityTuningPauseHour'])
                                . " * * * $parityTuningPhpFile \"pause\" &> /dev/null\n");
                parityTuningLoggerDebug ('created cron entries for running increments');
            }
            if ($parityTuningCfg['parityTuningHeat'] == 'yes') {
                fwrite($handle, "*/5 * * * * $parityTuningPhpFile \"monitor\" &>/dev/null\n");
                paritytuningLoggerDebug ('created cron entry for monitoring disk temperatures');
            }
            fwrite ($handle, "\n");
            fclose($handle);
            parityTuningLoggerDebug("updated cron settings are in $parityTuningCronFile");
        }
        // Activate any changes
        exec("/usr/local/sbin/update_cron");
        exit (0);

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to 'mdcmd' was made so that we can
        // detect if a parity check was started on a schedule or whether it was manually started.

        parityTuningLoggerDebug('detected that mdcmd had been called from ' . $argv['2'] . ' with command :');
        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug($cmd);
        switch ($argv[2]) {
        case 'crond':
            parityTuningLoggerDebug('...as a scheduled task');
            switch ($argv[3]) {
            case 'check':
                    if ($argv[4] == "RESUME") {
                        parityTuningLoggerDebug ('... to resume ' . actionDescription());
                    } else {
                        // @TODO need to check if a delay is needed here to allow check to have started properly!
                        parityTuningProgressWrite ("STARTED");
                        parityTuningActive("SCHEDULED");
                    }
                    exit (0);;
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ('... to pause ' . actionDescription());
                    } else {
                        parityTuningProgressWrite ('CANCELLED');
                        parityTuningProgressAnaylze();
                    }
                    exit (0);;
            default:
                    parityTuningLoggerDebug('option not currently recognised');
                    break;
            }  // end of 'crond' switch
            break;
        default:
            parityTuningLoggerDebug('...as an un-scheduled task');
            break;
        } // End of 'action' switch
        exit (0);

    case 'monitor':
        // This is invoked at regular intervals to try and detect some sort of relevant status change
        // that we need to take some action on.  In particular disks overheating (or cooling back down.

        $testingTemps = true;           // Set to false to suppress too frequent debug log messages
        if (! $active) {
            if (file_exists($parityTuningActiveFile)) {
                parityTuningLogger ('Monitor: Parity check appears to have finished');
                @unlink ($parityTuningActiveFile);
                parityTuningProgressWrite ('COMPLETED');
            }
            if ($testingTemps) parityTuningLoggerDebug ("Monitor: No array operation currently in progress");
            exit (0);
        }
        blankDebugLine();
        if (! $running) {
            if ($testingTemps) parityTuningLoggerDebug ('Monitor:  Parity check appears to be paused');
        } elseif (! file_exists($parityTuningActiveFile)) {
            parityTuningLoggerDebug ('Monitor:  Unscheduled array operation in progress');
            parityTuningActive ('MANUAL');      // Should suppress log message next time around
            parityTuningProgressWrite('MANUAL');
        }

        // Check for disk temperature changes we are monitoring

        if ($parityTuningCfg['parityTuningHeat'] != "yes" ) {
            parityTuningLoggerDebug ('Temperature monitoring switched off');
            exit (0);
        }

        // We only get here if there is a reason to check temperatures
        // so check if disk temperatures have changed appropriately
        $disks = parse_ini_file ('/var/local/emhttp/disks.ini', true);
        // Merge SMART settings
        require_once "$docroot/webGui/include/CustomMerge.php";
        $dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $hotdrives = array();       // drives that exceed pause threshold
        $warmdrives = array();      // drives that are between pause and resume thresholds
        $cooldrives = array();      // drives that are cooler than resume threshold
        $drivecount = 0;
        
        foreach ($disks as $drive) {
            $name=$drive['name'];
            if ((startswith($name, "parity")) || (startsWith($name,"disk"))) {
                $drivecount++;
                $temp = $drive['temp'];
                $hot  = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningCfg['parityTuningHeatHigh'];
                $cool = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningCfg['parityTuningHeatLow'];
                if (($temp == "*" ) || ($temp <= $cool)) $cooldrives[$name] = $temp;
                elseif ($temp >= $hot) $hotdrives[$name] = temp;
                else $warmdrives[$name] = temp;
            }
        }
        $parityTuningActiveFile   = "$parityTuningBootDir/$parityTuningPlugin.hot";
        parityTuningLoggerDebug ('drives=' . $drivecount . ', hot=' . count($hotdrives) . ', warm=' . count($warmdrives) . ', cool=' . count($cooldrives));
        if ($running) {
            if (count($hotdrives == 0)) {
                parityTuningLoggerDebug (actionDescription() . ' with all drives below temperature threshold for a Pause');
            } else {
                $msg = ('Following drives overheated: ');
                $handle = fopen($parityTuningHotFile, 'w');
                foreach ($hotdrives as $drive) {
                    $msg .= $drive . ' ';
                    fwrite ($handle, $drive . '=' . $drive);
                }
                fclose ($handle);

                parityTuningLoggerDebug ('Pause of ' . actionDescription() . " " . $completed . ': ' . $msg );
                parityTuningProgressWrite('PAUSE (HOT)');
                exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                sendTempNotification('Pause',$msg);
            }
        } else {
            if (! file_exists($parityTuningHotFile)) {
                parityTuningLoggerDebug ('Array operation paused but not for temperature related reason');
            } else {
                parityTuningLoggerDebug ('Resume of ' . actionDescription() . ' ' . $completed . ' as drives now cooled down');
                parityTuningProgressWrite($command);
                exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                parityTuningProgressWrite("RESUME (COOL)");
                sendTempNotification('Resume', 'Drives cooled down');
            }
        }
        exit (0);

    // We now have cases that are likely to result in action needing taking aginst the array
    case 'resume':
        blankDebugLine();
        parityTuningLoggerDebug ('Resume request');
        if ($pos == 0) {
            parityTuningLogger('Resume requested - but no array operation active so doing nothing');
            blankDebugLine();
            exit(0);
        }
        if ($running) {
            parityTuningLogger('Resume requested - but ' . actionDescription() . ' already running');
            blankDebugLine();
            exit(0);
        }
        if (validAction()) {
            parityTuningLoggerDebug ('Resume of ' . actionDescription() . ' ' . $completed );
            parityTuningProgressWrite($command);
            exec('/usr/local/sbin/mdcmd "check" "RESUME"');
            parityTuningProgressWrite("RESUME");
            sendArrayNotification('Scheduled resume');
            blankDebugLine();
            exit(0);
        }
        parityTuningUnknownState ('pause');
        blankDebugLine();
        exit(0);

    case 'pause':
        blankDebugLine();
        parityTuningLoggerDebug('Pause request');
        if ($pos == 0) {
            parityTuningLogger("Pause requested - but no array operation active so doing nothing");
            blankDebugLine();
            exit(0);
        }
        if (! $running) {
            parityTuningLogger('Pause requested - but ' . actionDescription() . ' already paused!');
            blankDebugLine();
            exit(0);
        }
        if (validAction()) {
            parityTuningLoggerDebug ('Pause of ' . actionDescription() . " " . $completed);
            parityTuningProgressWrite($command);
            exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
            sendArrayNotification ('Scheduled pause');
            blankDebugLine();
            exit (0);
        }
        parityTuningUnknownState ('pause');
        blankDebugLine();
        exit(0);

    case 'cancel':
        blankDebugLine();
        parityTuningLoggerDebug('Cancel request');
        // Not sure we should ever get here in normal operation as only Pause/Resume events expected
        // Included for completeness and to help with testing
        if ($pos == 0) {
            parityTuningLogger("Cancel requested - but no parity sync active so doing nothing");
            blankDebugLine();
            exit(0);
        }
        parityTuningLoggerDebug ('mdResyncAction=' . $action);
        if (validAction()) {
            parityTuningLoggerDebug (actionDescription() . " cancel request sent " . $completed);
            parityTuningProgressWrite('Cancelled');
            sendArrayNotification('Cancelled');
            exec('/usr/local/sbin/mdcmd "nocheck"');
            blankDebugLine();
            exit(0);
        }
        parityTuningUnknownState ('cancel');
        blankDebugLine();
        exit(0);

    case 'started':
        blankDebugLine();
        parityTuningLoggerDebug('Array started');
        blankDebugLine();
        parityTuningLoggerDebug ("Detected that array has just been started");
        if (!file_exists($statefile)) {
            parityTuningLoggerDebug("...but no parity check was in progress when array stopped");
            parityTuningLoggerDebug("...so no further action to take");
            exit(0);
        }
        parityTuningLoggerDebug ("Loading state file $statefile");
        $state = parse_ini_file ($statefile);
        parityTuningLoggerDebug ("Parity Check was in progress when array stopped at "
                                . sprintf("%.2f%%", $state['mdResyncPos'] / $state['mdResyncSize'] * 100));
        parityTuningLoggerDebug ("... but no action currently taken on started event");
        parityTuningLoggerDebug ("... until Limetech provide a way of (re)starting a parity check at a defined offset");
        blankDebugLine();
        exit(0);

    case 'stopping':
        blankDebugLine();
        parityTuningLoggerDebug('Array stopping');
        if (file_exists($statefile)) {
            unlink($statefile);
            parityTuningLoggerDebug("Removed existing state file %statefile");
        }
        if ($pos == 0) {
            parityTuningLoggerDebug ("no check in progress so no state saved");
            blankDebugLine();
            exit(0);
        }
        parityTuningLoggerDebug ('Array stopping while ' . actionDescription() . ' was in progress ' .  $completed);
        sendNotification('array stopping - progress will be lost');

        parityTuningProgressWrite ('Stopping');

        # Save state information about the array aimed at later implementing handling pause/resume
        # working across array stop/start.  Not sure what we will need so at the moment guessing!;
        parityTuningSaveState();
        blankDebugLine();
        exit(0);

    // Finally the error case.   Hopefully we never get here in normal running
    default:
        parityTuningLoggerDebug ('');       // Blank line to help break up debug sequences
        parityTuningLoggerDebug ('ERROR: Unrecognised option \'' . $command . '\'');
        parityTuningLoggerDebug ('Usage: parity.check.tuning.php <action>');
        parityTuningLoggerDebug ('Command Line was:');
        $cmd = ''; for ($i = 0; $i < count($argv) ; $i++) $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug($cmd);
        parityTuningSaveState('unknown');
        blankDebugLine();
        exit(0);

} // End of $command switch


# Should not be possible to get to this point!
parityTunninglogger ("Error: Program error|");
exit(0);

function blankDebugLine() {
    parityTuningLoggerDebug ('');       // Blank line to help break up debug sequences
}

// Write an entry to the progress file that is used to track increments

function parityTuningProgressWrite($msg) {
    global $var;
    global $parityTuningProgressFile;
    global $dateformat;
    global $pos, $size, $action;

    $handle = fopen($parityTuningProgressFile, 'a');
    if ($handle == null) {
        parityTuningLoggerDebug ('failed to open  ' . $parityTuningProgressFile);
        return;
    }
    fwrite ($handle, date($dateformat) . '|'
                    . $pos . '|' . $size . '|'
                    . $var['mdRescyncCorr'] . '|'
                    . $action . '|' . actionDescription() . '|'
                    . $msg . "\n");
    fclose($handle);
    parityTuningLoggerDebug ('written ' . $msg . ' record to  ' . $parityTuningProgressFile);
}

function validAction() {
    global $action;
    if (startswith($action, 'check') || startsWith($action,'recon') || startsWith($action, 'clear')) return true;
    parityTuningLoggerDebug ('Unrecognised action "' . $action . '"');
    return false;
}

// send a notification without checking if enabled.  Always add point reached.
function sendNotification($op, $desc = '') {
    global $completed, $emhttpDir;
    $msg = actionDescription() . $completed . ' ' . $op;
    parityTuningLoggerDebug ('Sent notification message: ' . $msg);
    exec ($emhttpDir . '/webGui/scripts/notify -e "Parity Tuning Operation" -i "normal" -d "'
                    . $msg . '"' . (($desc == '') ? '' : ' -m "' . $desc . '"') );
}

// Send a notification if increment notifies enabled
function sendArrayNotification ($op) {
    global $parityTuningCfg;
    if ($parityTuningCfg['parityTuningNotify'] == 'no') {
        parityTuningLoggerDebug('Array notifications disabled so ' . $op . ' not sent');
        return;
    }
    sendNotification($op);
}

// Send a notification if temperature related notifications enabled
function sendTempNotification ($op, $desc) {
    global $parityTuningCfg;
    if ($parityTuningCfg['parityTuningHeatNotify'] == 'no') {
        parityTuningLoggerDebug('Heat notifications disabled so ' . $op . ' ' . $desc . ' not sent');
        return;
    }
    sendNotification($op, $desc);
}

function actionDescription() {
    global $action;
    if (startsWith($action,'check')) return 'Parity Check';
    if (startsWith($action,'recon')) return 'Parity-Sync / Data Rebuild';
    if (startsWith($action,'clear')) return 'Disk Clear';
    return 'unknown action: ' . $action;
}

// If we detect that there is a parity check running then we create the 'active' file
// to record what that we have deteccted it is running and how we detected this.
function parityTuningActive ($msg) {
    global $pos;
    global $action;
    global $parityTuningActiveFile;
    if (file_exists($parityTuningActiveFile)) {
        // Not sure this should ever happen but lets allow for it and record it has happened
        parityTuningLoggerDebug ('Unexpectedly found ' . $parityTuningActiveFile);
        parityTuningLOggerDebug (' ... so removed it as we are about to create a new one');
        @unlink ($parityTuningActiveFile);
    }
    $handle = fopen ($parityTuningActiveFile, "w");
    if ($handle == null) {
        parityTuningLoggerDebug ('failed to open ' . $parityTuningActiveFile);
        return;
    }
    global $dateformat;
    fwrite ($handle, "Detected=$msg\n");
    fwrite ($handle, "Position=$pos\n");
    fwrite ($handle, "When=" . date($dateformat) . "\n");
    fclose ($handle);
   parityTuningLoggerDebug ('created ' . $parityTuningActiveFile . ' OK');
}

// Save the array state
// If no 'op' is specified then save the file with no timestamp added.
// If 'op' IS specified then add the op and a timestamp to the name to help tie it to the syslog
function parityTuningSaveState($op = "") {
    global $parityTuningStateFile;
    $f = $parityTuningStateFile . ((empty($op)) ? ('') : '-' . $op . '-' . date($dateformat));
    parityTuningLoggerDebug('Saving array state to ' . $f);
    $_POST = parse_ini_file("/var/local/emhttp/var.ini");
    $_POST['#file'] = $f;
    include "/usr/local/emhttp/update.php";
}

// A catcher for the cases where unexpected conditions arise.
function parityTuningUnknownState($op) {
    parityTuningLoggerDebug ('Array state not recognised');
    parityTuningSaveState ($op);
}
?>
