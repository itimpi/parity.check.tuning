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

require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';
require_once '/usr/local/emhttp/webGui/include/Helpers.php';

if (empty($argv)) {
  parityTuningLoggerDebug("ERROR: No action specified");
  exit(0);
}

// Some useful variables
$parityTuningStateFile     = "$parityTuningBootDir/$parityTuningPlugin.state";
$parityTuningCronFile      = "$parityTuningBootDir/$parityTuningPlugin.cron";
$parityTuningProgressFile  = "$parityTuningBootDir/$parityTuningPlugin.progress";
$parityTuningScheduledFile = "$parityTuningBootDir/$parityTuningPlugin.scheduled";

// List of fields we save ofr progress.   
// Might not all be needed but better to have more information than necessary
$progressfields = array('sbSynced','sbSynced2','sbSyncErrs','sbSyncExit', 
                       'mdState','mdResync','mdResyncPos','mdResyncSize','mdResyncCorr','mdResyncAction' );


$var = parse_ini_file('/var/local/emhttp/var.ini');

$pos    = $var['mdResyncPos'];
$size   = $var['mdResyncSize'];
$action = $var['mdResyncAction'];

$percent = sprintf ("%.1f%%", ($pos/$size*100));
$completed = sprintf (" (%s completed) ", $percent);
$dateformat = 'Y M d H:i:s';
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
spacerDebugLine(true);
switch ($command) {

    case 'updatecron':
        // This is called any time that the user has updated the settings for this plugin to reset any cron schedules.
        @unlink ($parityTuningCronFile);
        if (($parityTuningCfg['parityTuningIncrements'] == "no") && ($parityTuningCfg['parityTuningHeat'] == 'no')) {
            parityTuningLoggerDebug("No cron events for this plugin are needed");
        } else {
            $lines = [];
            $lines[] = "\n# Generated schedules for $parityTuningPlugin\n";
            if ($parityTuningCfg['parityTuningIncrements'] == "yes") {
                if ($parityTuningCfg['parityTuningFrequency'] == 'custom') {
                    $resumetime = $parityTuningCfg['parityTuningResumeCustom'];
                    $pausetime  = $parityTuningCfg['parityTuningPauseCustom'];
                } else {
                    $resumetime = $parityTuningCfg['parityTuningResumeMinute'] . ' '
                                . $parityTuningCfg['parityTuningResumeHour'] . ' * * *';
                    $pausetime  = $parityTuningCfg['parityTuningPauseMinute'] . ' '
                                . $parityTuningCfg['parityTuningPauseHour'] . ' * * *';
                }
                $lines[] = $resumetime . " $parityTuningPhpFile \"resume\" &> /dev/null\n";
                $lines[] = $pausetime  . " $parityTuningPhpFile \"pause\" &> /dev/null\n";
                parityTuningLoggerDebug ('created cron entries for running increments');
            }
            if ($parityTuningCfg['parityTuningHeat'] == 'yes') {
                $lines[] = "*/5 * * * * $parityTuningPhpFile \"monitor\" &>/dev/null\n";
                paritytuningLoggerDebug ('created cron entry for monitoring disk temperatures');
            }
            file_put_contents($parityTuningCronFile, $lines);
            parityTuningLoggerDebug("updated cron settings are in $parityTuningCronFile");
        }
        // Activate any changes
        exec("/usr/local/sbin/update_cron");
        spacerDebugLine(false);
        exit (0);

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to 'mdcmd' was made so that we can
        // detect if a parity check was started on a schedule or whether it was manually started.

        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug('detected that mdcmd had been called from ' . $argv['2'] . ' with command :' . $cmd);
        switch ($argv[2]) {
        case 'crond':
            switch ($argv[3]) {
            case 'check':
                    if ($argv[4] == "RESUME") {
                        parityTuningLoggerDebug ('... to resume ' . actionDescription());
                    } else {
                        // @TODO need to check if a delay is needed here to allow check to have started properly!
                        if (file_exists(parityTuningProgressFile)) {
                            parityTuningLoggerDebug('analyze previous progress before starting new one');
                            parityTuningProgressAnanlyze();
                        }
                        parityTuningLoggerDebug ('...appears to be a regular scheduled check');
                        parityTuningProgressWrite ("STARTED");
                        file_put_contents($parityTuningScheduledFile,"SCHEDULED");
                    }
                    exit (0);;
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ('... to pause ' . actionDescription());
                    } else {
                        // Not sure this even a possible operation but we allow for it anyway!
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
            break;
        } // End of 'action' switch
        spacerDebugLine(false);
        exit (0);

    case 'monitor':
        // This is invoked at regular intervals to try and detect some sort of relevant status change
        // that we need to take some action on.  In particular disks overheating (or cooling back down.

        $testingTemps = true;           // Set to false to suppress too frequent debug log messages
        if (! $active) {
            if ($testingTemps) parityTuningLoggerDebug ("Monitor: No array operation currently in progress");
            if (file_exists($parityTuningProgressFile)) {
                parityTuningLoggerDebug('analyze progress from previous array operation');
                parityTuningProgressAnanlyze();
            }
            spacerDebugLine(false);
            exit (0);
        }
        if (! $running) {
            if ($testingTemps) parityTuningLoggerDebug ('Monitor:  Parity check appears to be paused');
        } elseif (! file_exists($parityTuningProgressFile)) {
            parityTuningLoggerDebug ('Monitor:  Unscheduled array operation in progress');
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
            if ((startswith($name, 'parity')) || (startsWith($name,'disk'))) {
                $drivecount++;
                $temp = $drive['temp'];
                $hot  = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningCfg['parityTuningHeatHigh'];
                $cool = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningCfg['parityTuningHeatLow'];
                if (($temp == "*" ) || ($temp <= $cool)) $cooldrives[$name] = $temp;
                elseif ($temp >= $hot) $hotdrives[$name] = temp;
                else $warmdrives[$name] = temp;
            }
        }
        $parityTuningHotFile   = "$parityTuningBootDir/$parityTuningPlugin.hot";
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
                parityTuningProgressWrite('RESUME (COOL)');
                exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                sendTempNotification('Resume', 'Drives cooled down');
            }
        }
        exit (0);

    // We now have cases that are likely to result in action needing taking aginst the array
    case 'resume':
        parityTuningLoggerDebug ('Resume request');
        if ($pos == 0) {
            parityTuningLoggerDebug('... no array operation active so doing nothing');
            parityTuningProgressAnalyze();
         } else {
            if (configuredAction()) {
                if ($running) {
                    parityTuningLoggerDebug('... ' . actionDescription() . ' already running');
                    if (! file_exists($parityTuningProgressFile)) parityTuningProgressWrite('MANUAL');
                } else {
                    exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                    sleep (5);            // give time for resume to restart
                    sendArrayNotification('Scheduled resume');
                    parityTuningLoggerDebug ('Resume of ' . actionDescription() . ' ' . $completed );
                    parityTuningProgressWrite('RESUME');            // We want state aftter resune has started
                }
            }
        }
        spacerDebugLine(false);
        exit(0);

    case 'pause':
        parityTuningLoggerDebug('Pause request');
        if ($pos == 0) {
            parityTuningProgressAnalyze();
            parityTuningLoggerDebug('... no array operation active so doing nothing');
        } else {
            if (configuredAction()) {
                if (! $running) {
                    parityTuningLoggerDebug('... ' . actionDescription() . ' already paused!');
                } else {
                    parityTuningProgressWrite('PAUSE');         // We want state before pause occurs
                    exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                    sendArrayNotification ('Scheduled pause');
                    parityTuningLoggerDebug ('Pause of ' . actionDescription() . " " . $completed);
                }
            }
        }
        spacerDebugLine(false);
        exit(0);

    case 'cancel':
        parityTuningLoggerDebug('Cancel request');
        // Not sure we should ever get here in normal operation as only Pause/Resume events expected
        // Included for completeness and to help with testing
        if ($pos == 0) {
            parityTuningLogger("Cancel requested - but no parity sync active so doing nothing");
            parityTuningProgressAnalyze();
        } else {
            parityTuningLoggerDebug ('mdResyncAction=' . $action);
            if (validAction(false)) {
                parityTuningLoggerDebug (actionDescription() . " cancel request sent " . $completed);
                parityTuningProgressWrite('CANCELLED');
                sendArrayNotification('Cancelled');
                exec('/usr/local/sbin/mdcmd "nocheck"');
            }
        }
        spacerDebugLine();
        exit(0);

    case 'started':
        parityTuningLoggerDebug ("Detected that array has just been started");
        if (!file_exists($parityTuningProgressFile)) {
            parityTuningLoggerDebug("...but no parity check was in progress when array stopped");
            parityTuningLoggerDebug("...so no further action to take");
            exit(0);
        } else {        
            // One day we may think of restarting here!
            parityTuningProgressWrite('ABORTED');
            // parityTuningLoggerDebug ("Loading progress file $parityTuningProgressFile");
            // parityTuningLoggerDebug ("Parity Check was in progress when array stopped at "
            //                        . sprintf("%.2f%%", $state['mdResyncPos'] / $state['mdResyncSize'] * 100));
            parityTuningLoggerDebug ("... but no action currently taken on started event");
            parityTuningLoggerDebug ("... until Limetech provide a way of (re)starting a parity check at a defined offset");
        }
        sleep (15);     // give time for any array operation to start running
        parityTuningProgressAnalyze();  
        spacerDebugLine();
        exit(0);

    case 'stopping':
        parityTuningLoggerDebug('Array stopping');
        if (file_exists($statefile)) {
            unlink($statefile);
            parityTuningLoggerDebug("Removed existing state file %statefile");
        }
        if ($pos == 0) {
            parityTuningLoggerDebug ("no check in progress so no state saved");
            parityTuningProgressAnalyze();
            spacerDebugLine(false);
            exit(0);
        }
        parityTuningLoggerDebug ('Array stopping while ' . actionDescription() . ' was in progress ' .  $completed);
        sendNotification('array stopping - progress will be lost');

        parityTuningProgressWrite ('STOPPING');
        parityTuningProgressAnalyze();
        spacerDebugLine(false);
        exit(0);
        
    case 'analyze':     // Special case for debugging - can be removed when debugging completed
        parityTuningProgressAnalyze();
        spacerDebugLine(false);
        exit(0);

    // Finally the error case.   Hopefully we never get here in normal running
    default:
        parityTuningLoggerDebug ('');       // Blank line to help break up debug sequences
        parityTuningLoggerDebug ('ERROR: Unrecognised option \'' . $command . '\'');
        parityTuningLoggerDebug ('Usage: parity.check.tuning.php <action>');
        parityTuningLoggerDebug ('Command Line was:');
        $cmd = ''; for ($i = 0; $i < count($argv) ; $i++) $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug($cmd);
        parityTuningProgressWrite('UNKNOWN');
        spacerDebugLine(false);
        exit(0);

} // End of $command switch


# Should not be possible to get to this point!
parityTunninglogger ("Error: Program error|");
exit(0);

// Helps break debug information into blocks to identify entrie for a given entry point
function spacerDebugLine($start = true) {
    global $command;
    parityTuningLoggerDebug ('-----------' . strtoupper($command) . (($start == true) ? ' start' : ' end-') . '------');       // Blank line to help break up debug sequences
}

//  Function that looks to see if a previously running array operation has finished.
//  If it has analyze the progress file to create a history record.
//  We then update the standard Unraid file.  If needed we patch an existing record.

function parityTuningProgressAnalyze() {
    global $parityTuningProgressFile, $parityTuningScheduledFile;
    global $parityTuningCfg;
    global $var, $action;
    global $dateformat;
    
    if (! file_exists($parityTuningProgressFile)) {
        parityTuningLoggerDebug(' no progress file to anaylse');
        return;
    }    
    if ($var['mdResyncPos'] != 0) {
        parityTuningLoggerDebug(' array operation still running - so not time to analyze progess');
        return;
    }
    parityTuningLoggerDebug('Previous array operation finished - analyzing progress information to create history record');
    // Work out history record values
    $lines = file($parityTuningProgressFile);

    if ($lines == false){
        parityTuningLoggerDebug('failure reading Progress file - analysis abandoned');
        return;        // Cannot analyze a file that cannot be read!
    }
    // Check if file was completed 
    // TODO:  Consider removing this check when anaylyze fully debugged
    if (count($lines) < 2) {
        parityTuningLoggerDebug('Progress file appears to be incomplete');
        return;
    }
    $line = $lines[count($lines) - 1];
    if ((! startsWith($line,'COMPLETED')) && (!startsWith($line,'CANCELLED'))) {
        parityTuningLoggerDebug('missing completion line in Progress file - add it and restart analyze');
        parityTuningProgressWrite('COMPLETED');
        parityTuningProgressAnalyze();
        return;
    }
    $duration = $elapsed = $increments = $corrected=0;
    $lastFinish = $exitcode = 0;
    $mdResyncAction = '';
    foreach ($lines as $line) {
        list($op,$stamp,$timestamp,$sbSynced,$sbSynced2, $sbSynceErrs, $sbSyncExit, $mdState,
             $mdResync, $mdResyncPos, $mdResyncSize, $mdResyncCorr, $mdResyncAction, $desc) = explode ('|',$line);
        switch ($op) {
            case 'STARTED': // TODO: Think can be ignored as only being documentation? 
            case 'MANUAL':  // TODO: Think can be ignored as only being documentation?
            case 'type':    // TODO: This record type could probably be removed when debugging complete
                    break;
                    
             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
            case 'RESUME': 
            case 'RESUME (COOL)':
                    $thisFinish = ($sbSynced2 ==0) ? $timestamp : $sbSynced2;    
                    $thiselapsed = ($lastFinish == 0) ? 0 : ($timestamp - $lastFinish);
                    $elapsed += $thiselapsed;
                    $lastFinish = $thisFinish;
                    break;
                   
             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
            case 'PAUSE':  
            case 'PAUSE (HOT)':
            case 'ABORTED':        
            case 'COMPLETED': 
            case 'CANCELLED':
            case 'STOPPING':
                    $increments++;
                    $corrected += $sybSyncErrs;
                    $thisStart = $sbSynced;
                    $thisFinish = ($sbSynced2 == 0) ? $timestamp : $sbSynced2;
                    $thisduration = $thisFinish - $thisStart;
                    $duration += $thisduration;
                    $elapsed += $thisduration;
                    $lastFinish = $thisFinish;
                    $exitcode = $sbSyncExit;
                    break;
            
            // TODO:  Included for completeness although could possibly be removed when debugging complete?
            default :
                    parityTuningLoggerDebug ("unexpected progress record type: $op");
                    break;
        } // end switch
    }  // end foreach
    $unit='';
    $speed = my_scale($mdResyncSize * 1024 / $duration,$unit,1) . " $unit/s";
    $generatedRecord = date($dateformat, $lastFinish) . '|'. $duration .'|'. $speed . '|' . $exitcode .'|'. $corrected .'|'. $elapsed .'|'. $increments . "\n";
    parityTuningLoggerDebug("log record generated from progress: $generatedRecord"); 
    
    // Next few lines help with debugging - can be safely removed when no longer wanted.
    @unlink("$parityTuningProgressfile.save");
    rename ($parityTuningProgressFile, "$parityTuningProgressFile.save");
    parityTuningLoggerDebug("Old progress file available as $parityTuningProgressFile.save");
    $myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
    file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);
    
    @unlink ($parityTuningProgressFile);
    if (! startsWith($action,'check')) {
        parityTuningLoggerDebug('array action was not Parity Check - it was ', actionDescription());
        parityTuningLoggerDebug('... so update to parity check history not appropriate');
        @unlink ($parityTuningScheduledFile);   // should not exist but lets play safe!
        return;
    }
    if (! file_exists($parityTuningScheduledFile)) {
        if (! $parityTuningCfg['parityTuningUnscheduled'] == 'yes') {
            parityTuningLoggerDebug ('pause/resume not activated for' . 
                                    (startsWith($action,'check') ? ' manual ' : ' ') 
                                    . actionDescription());
            parityTuningLoggerDebug ('... so do not attempt to update system parity-check.log file');
            return;
        } else {
            parityTuningLoggerDebug ('pause/resume was activated for' .
                                    (startsWith($action,'check') ? ' manual ' : ' ') 
                                    . actionDescription());
        }
    }
    @unlink ($parityTuningScheduledFile);
    
    // Now patch the entry in the standard parity log file
    $parityLogFile = '/boot/config/parity-checks.log';
    $lines = file($parityLogFile, FILE_SKIP_EMPTY_LINES);
    $matchLine = 0;
    while ($matchLine < count($lines)) {
        $line = $lines[$matchLine];
        list($logstamp,$logduration, $logspeed,$logexit, $logerrors) = explode('|',$line);
        $logtime = strtotime(substr($logstamp, 9, 3) . substr($logstamp,4,4) . substr($logstamp,0,5) . substr($logstamp,12));
        if (abs($logtime - $lastFinish) < 5) {
            parityTuningLoggerDebug ('update log entry on line ' . ($matchLine+1));
            break;
        }
        $matchLine++;
    }
    if ($matchLine == count($lines))  parityTuningLoggerDebug('no match found in existing log so added a new record ' . ($matchLine + 1));
    $lines[$matchLine] = $generatedRecord;
    file_put_contents($parityLogFile,$lines);
}

// Write an entry to the progress file that is used to track increments  
// This file is created (or added to) any time we detect a running array operation
// It is removed any time we detect there is no active operation so it contents track the operation progress.

function parityTuningProgressWrite($msg) {
    global $var;
    global $parityTuningProgressFile;
    global $dateformat, $progressfields;
    // Not strictly needed to have header but a useful reminder of the fields saved
    $line='';
    if (! file_exists($parityTuningProgressFile)) {
        $line .= 'type|date|time|' . time() .'|';
        foreach ($progressfields as $name) $line .= $name . '|';
        $line .= "Description\n";
    }
    $line .= $msg . '|' . date($dateformat) . '|' . time() . '|';
    foreach ($progressfields as $name) $line .= $var[$name] . '|';
    $line .= actionDescription() . "|\n";
    file_put_contents($parityTuningProgressFile, $line, FILE_APPEND | LOCK_EX);
    parityTuningLoggerDebug ('written ' . $msg . ' record to  ' . $parityTuningProgressFile);
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
        parityTuningLoggerDebug('Array notifications disabled so ' . $op . ' message not sent');
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

// Confirm that action is valid according to user settings
function configuredAction() {
    global $action, $parityTuningCfg,$parityTuningScheduledFile;
    if (startsWith($action,'recon') && ($parityTuningCfg['parityTuningRrecon'] == 'yes')) {
        parityTuningLoggerDebug('...configured action for ' . actionDescription());
        return true;
    }
    if (startsWith($action,'clear') && ($parityTuningCfg['parityTuningClear'] == 'yes')) {
        parityTuningLoggerDebug('...configured action for ' . actionDescription());
        return true;
    }
    if (startsWith($action,'check')) {
        if (file_exists($parityTuningScheduledFile)) {
            parityTuningLoggerDebug('...configured scheduled action for ' . actionDescription());
            return true;
        }
        if ($parityTuningCfg['parityTuningUnscheduled'] == 'yes') {
            parityTuningLoggerDebug('...configured ununscheduled action for ' . actionDescription());
            return true;
        } 
    }
    parityTuningLoggerDebug('...action not configured for' 
                            . (startsWith($action,'check') ? ' manual ' : ' ') 
                            . actionDescription(). ' ' . $action);
    return false;
}

function actionDescription() {
    global $action;
    if (startsWith($action,'check')) return 'Parity Check';
    if (startsWith($action,'recon')) return 'Parity-Sync / Data Rebuild';
    if (startsWith($action,'clear')) return 'Disk Clear';
    return 'unknown action: ' . $action;
}
?>
