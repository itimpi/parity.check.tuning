#!/usr/bin/php
<?PHP
/*
 * Script that is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file command; or from another script.
 *
 * It takes a parameter descrbing the action required.
 *
 * In can also be called via CLI as the command 'parity.check' to expose functionality
 * that relates to parity checking.
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

$testing = 0;		// set to 0 when not testing

if (empty($argv)) {
  parityTuningLoggerDebug("ERROR: No action specified");
  exit(0);
}

// Some useful variables
$parityTuningStateFile     = "$parityTuningBootDir/$parityTuningPlugin.state";
$parityTuningCronFile      = "$parityTuningBootDir/$parityTuningPlugin.cron";
$parityTuningProgressFile  = "$parityTuningBootDir/$parityTuningPlugin.progress";
$parityTuningScheduledFile = "$parityTuningBootDir/$parityTuningPlugin.scheduled";
$dateformat = 'Y M d H:i:s';

// List of fields we save ofr progress.
// Might not all be needed but better to have more information than necessary
$progressfields = array('sbSynced','sbSynced2','sbSyncErrs','sbSyncExit',
                       'mdState','mdResync','mdResyncPos','mdResyncSize','mdResyncCorr','mdResyncAction' );

// load some state information.
// written as a function to facilitate reloads
function loadVars($delay = 0) {
    if ($delay > 0) sleep($delay);
    
	global $var, $pos, $size, $action; 
    global $percent, $completed,$active, $running, $correcting;
    
    $var= parse_ini_file('/var/local/emhttp/var.ini');

    $pos    = $var['mdResyncPos'];
    $size   = $var['mdResyncSize'];
    $action = $var['mdResyncAction'];

    $percent = sprintf ("%.1f%%", ($pos/$size*100));
    $completed = sprintf (" (%s completed) ", $percent);
    $active = ($pos > 0);                       // If array action is active (paused or running)
    $running = ($var['mdResync'] > 0);       // If array action is running (i.e. not paused)
    $correcting = $var['mdResyncCorr'];
}

loadVars();

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
        break;

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to 'mdcmd' was made so that we can
        // detect if a parity check was start ed on a schedule or whether it was manually started.

        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug('detected that mdcmd had been called from ' . $argv['2'] . ' with command:' . $cmd);
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
                            parityTuningProgressAnalyze();
                        }
                        parityTuningLoggerDebug ('...appears to be a regular scheduled check');
                        parityTuningProgressWrite ("STARTED");
                        file_put_contents($parityTuningScheduledFile,"SCHEDULED");
                    }
                    break;
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ('... to pause ' . actionDescription());
                    } else {
                        // Not sure this even a possible operation but we allow for it anyway!
                        parityTuningProgressWrite ('CANCELLED');
                        parityTuningProgressAnaylze();
                    }
                    break;
            default:
                    parityTuningLoggerDebug('option not currently recognised');
                    break;
            }  // end of 'crond' switch
            break;
        default:
            break;
        } // End of 'action' switch
        break;

    case 'monitor':
        // This is invoked at regular intervals to try and detect some sort of relevant status change
        // that we need to take some action on.  In particular disks overheating (or cooling back down.

        $testingTemps = true;           // Set to false to suppress too frequent debug log messages
        if (! $active) {
            if ($testingTemps) parityTuningLoggerDebug ("Monitor: No array operation currently in progress");
            if (file_exists($parityTuningProgressFile)) parityTuningProgressAnalyze();
            break;
        }
        if (! $running) {
            if ($testingTemps) parityTuningLoggerDebug ('Monitor:  Parity check appears to be paused');
        } elseif (! file_exists($parityTuningProgressFile)) {
            parityTuningProgressWrite ("STARTED");
            parityTuningLoggerDebug ('Monitor:  Unscheduled array operation in progress');
        }

        // Check for disk temperature changes we are monitoring

        if ($parityTuningCfg['parityTuningHeat'] != "yes" ) {
            parityTuningLoggerDebug ('Temperature monitoring switched off');
            break;
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

                parityTuningLoggerDebug ('Paused ' . actionDescription() . " " . $completed . ': ' . $msg );
                parityTuningProgressWrite('PAUSE (HOT)');
                exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                sendTempNotification('Pause',$msg);
            }
        } else {
            if (! file_exists($parityTuningHotFile)) {
                parityTuningLoggerDebug ('Array operation paused but not for temperature related reason');
            } else {
                parityTuningLoggerDebug ('Resumed ' . actionDescription() . ' ' . $completed . ' as drives now cooled down');
                parityTuningProgressWrite('RESUME (COOL)');
                exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                sendTempNotification('Resume', 'Drives cooled down');
            }
        }
        break;

    // We now have cases that are likely to result in action needing taking aginst the array
    case 'resume':
        parityTuningLoggerDebug ('Resume request');
        if (isArrayOperationActive()) {
            if (configuredAction()) {
                if ($running) {
                    parityTuningLoggerDebug('... ' . actionDescription() . ' already running');
                    if (! file_exists($parityTuningProgressFile)) parityTuningProgressWrite('MANUAL');
                } else {
                    exec('/usr/local/sbin/mdcmd "check" "RESUME"'); 
                    loadVars(5);         // give time for resume
                    sendArrayNotification('Scheduled resume');
                    parityTuningLoggerDebug ('Resumed ' . actionDescription() . ' ' . $completed );
                    parityTuningProgressWrite('RESUME');            // We want state after resune has started
                }
            }
        }
        break;

    case 'pause':
        parityTuningLoggerDebug('Pause request');
        if (isArrayOperationActive()) {
            if (configuredAction()) {
                if (! $running) {
                    parityTuningLoggerDebug('... ' . actionDescription() . ' already paused!');
                } else {
                    parityTuningProgressWrite('PAUSE');         // We want state before pause occurs
                    exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"'); 
                    loadVars(5);         // give time for pause
                    sendArrayNotification ('Scheduled pause');
                    parityTuningLoggerDebug ('Pause of ' . actionDescription() . " " . $completed);
                }
            }
        }
        break;

    case 'started':
        parityTuningLoggerDebug ("Detected that array has just been started");
        if (!file_exists($parityTuningProgressFile)) {
            parityTuningLoggerDebug("...but no parity check was in progress when array stopped");
            parityTuningLoggerDebug("...so no further action to take");
            break;
        } else {
            // One day we may think of restarting here!
            parityTuningProgressWrite('ABORTED');
            // parityTuningLoggerDebug ("Loading progress file $parityTuningProgressFile");
            // parityTuningLoggerDebug ("Parity Check was in progress when array stopped at "
            //                        . sprintf("%.2f%%", $state['mdResyncPos'] / $state['mdResyncSize'] * 100));
            parityTuningLoggerDebug ("... but no action currently taken on started event");
            parityTuningLoggerDebug ("... until Limetech provide a way of (re)starting a parity check at a defined offset");
        }
        loadVars(5);     // give time for any array operation to start running
        parityTuningProgressAnalyze();
        break;

    case 'stopping':
        parityTuningLoggerDebug('Array stopping');
        if (file_exists($statefile)) {
            unlink($statefile);
            parityTuningLoggerDebug("Removed existing state file %statefile");
        }
        if ($pos == 0) {
            parityTuningLoggerDebug ("no check in progress so no state saved");
            parityTuningProgressAnalyze();
            break;
        }
        parityTuningLoggerDebug ('Array stopping while ' . actionDescription() . ' was in progress ' .  $completed);
        sendNotification('array stopping - progress will be lost');

        parityTuningProgressWrite ('STOPPING');
        parityTuningProgressAnalyze();
        break;

    case 'analyze':     // Special case for debugging - can be removed when debugging completed
        parityTuningProgressAnalyze();
        break;

    // Options that are only currently for CLI use

    case 'status':
    	if (isArrayOperationActive()) parityTuningLogger(actionDescription() . ($running ? '' : ' PAUSED ') .  $completed);
    	break;

    case 'check':
	    $dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $setting = strtolower($dynamixCfg['parity']['write']);
        $command= 'correct';
        if ($setting == '' ) $command = 'nocorrect';
        parityTuningLoggerDebug("using scheduled mode of '$command'");
        // fallthru now we know the mode to use
    case 'correct':
    case 'nocorrect':
        if (isArrayOperationActive()) {
            parityTuningLogger('Not allowed as ' . actionDescription() . ' already running');
            break;
        }
        $correcting =($command == 'correct') ? true : false;
		exec("/usr/local/sbin/mdcmd check $command");
        loadVars(2);
	    parityTuningLogger(actionDescription() . ' Started');
        if ($action == 'check' && ( $command == 'correct')) {
            parityTuningLogger('Only able to start a Read-Check due to number of disabled drives');
        }
	    break;

    case 'cancel':
        parityTuningLoggerDebug('Cancel request');
        if (isArrayOperationActive()) {
            parityTuningLoggerDebug ('mdResyncAction=' . $action);
			parityTuningProgressWrite('CANCELLED');
			exec('/usr/local/sbin/mdcmd "nocheck"');
            parityTuningLoggerDebug (actionDescription() . " cancel request sent " . $completed);
            loadVars();
            parityTuningLogger(actionDescription() . " Cancelled");
        }
        break;
        
    case 'stop':
    case 'start':
        parityTuningLogger("'$command' option not currently implemented");
        // fallthru to usage section

    // Finally the error/usage case.   Hopefully we never get here in normal running
    case 'help':
    case '--help':
    default:
        parityTuningLogger ('');       // Blank line to help break up debug sequences
        parityTuningLogger ('ERROR: Unrecognised option \'' . $command . '\'');
        parityTuningLogger ('Usage: ' . basename($argv[0]) . ' <action>');
		parityTuningLogger ("where action is one of");
		parityTuningLogger ("  pause            Pause a rumnning parity check");
		parityTuningLogger ("  resume           Resume a paused parity check");
		if (parityTuningCLI()) {
			parityTuningLogger ("  check            Start a parity check (as Settings->Scheduler)");
			parityTuningLogger ("  correct          Start a correcting parity check");
			parityTuningLogger ("  nocorrect        Start a non-correcting parity check");
			parityTuningLogger ("  status           Show the status of a running parity check");
			parityTuningLogger ("  cancel           Cancel a running parity check");
        } else {
        	parityTuningLogger ('Command Line was:');
        	$cmd = ''; for ($i = 0; $i < count($argv) ; $i++) $cmd .= $argv[$i] . ' ';
        	parityTuningLoggerDebug($cmd);
        	parityTuningProgressWrite('UNKNOWN');
        }
        break;

} // End of $command switch
spacerDebugLine(false);
exit(0);

// Determine in invoked via CLI
function parityTuningCLI() {
	global $argv;
	return (basename($argv[0]) == 'parity.check');
}

// Helps break debug information into blocks to identify entrie for a given entry point
function spacerDebugLine($start = true) {
    global $command, $argv;
    parityTuningLoggerDebug ('-----------' . strtoupper($command) . (($start == true) ? ' start' : ' end-') . '------');
}
// is an array operation in progress
function isArrayOperationActive($msg = true) {
	global $pos;
	if ($pos == 0) {
		if (parityTuningCLI()) {
			if ($msg) parityTuningLogger("no array operation active so doing nothing\n");
		} else {
			parityTuningLoggerDebug('no array operation active so doing nothing');
			parityTuningProgressAnalyze();
		}
		return false;
	}
	return true;
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
    $thisStart = $thisFinish = $thisElapsed = $thisDuration = $thisOffset = 0;
    $lastFinish = $exitcode = 0;
    $mdResyncAction = '';
    foreach ($lines as $line) {
    	parityTuningLoggerTesting("$line");
        list($op,$stamp,$timestamp,$sbSynced,$sbSynced2,$sbSyncErrs, $sbSyncExit, $mdState,
             $mdResync, $mdResyncPos, $mdResyncSize, $mdResyncCorr, $mdResyncAction, $desc) = explode ('|',$line);
		// A progress pile can have a time offset which we can deirmine by  comaparing text and binary timestamps
		// This will only be selevant when testing files submitted as part of a problem report
        if (! $increments) {
        	$temp = strtotime(substr($stamp, 9, 3) . substr($stamp,4,4) . substr($stamp,0,5) . substr($stamp,12));
			if ($temp) {		// ignore any heading line
				// parityTuningLoggerTesting ("Progress temp = $temp, timestamp=$timestamp");
				$thisOffset = $temp - $timestamp;
				// parityTuningLoggerTesting ("Progress time offset = $thisOffset seconds");
			}
        }
        switch ($op) {
        	case 'UNKNOWN':
        	case 'type':    // TODO: This record type could probably be removed when debugging complete
        			break;

            case 'STARTED': // TODO: Think can be ignored as only being documentation?
            case 'MANUAL':  // TODO: Think can be ignored as only being documentation?
            		if ($timestamp) $thisStart =  $thisFinish = $lastFinish = ($timestamp  + $thisOffset);
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitcode=$exitcode");
                    break;

             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
            case 'RESUME':
            case 'RESUME (COOL)':
            		if (! $thisStart) $thisStart = $timestamp + $thisOffset;
                    $thisFinish = (($sbSynced2 ==0) ? $timestamp : $sbSynced2) + $thisOffset;
                    $thisElapsed = ($lastFinish == 0) ? 0 : ($timestamp + $thisOffset - $lastFinish);
                    parityTuningLoggerTesting("Resume: elapsed paused time $thisElapsed seconds");
                    $thisDuration = 0;
                    $elapsed += $thisElapsed;
                    $lastFinish = $thisFinish;
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
                    						  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitcode=$exitcode");
                    break;

             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
            case 'PAUSE':
            case 'PAUSE (HOT)':
            case 'ABORTED':
            case 'COMPLETED':
            case 'CANCELLED':
            case 'STOPPING':
                    $increments++;
                    if ($sbSyncErrs) $corrected = $sbSyncErrs;
                    // parityTuningLoggerTesting("increment $increments, corrected $corrected ");
                    $thisStart = $sbSynced + $thisOffset;
                    $thisFinish = (($sbSynced2 == 0) ? $timestamp : $sbSynced2) + $thisOffset;
                    $thisDuration = $thisFinish - $thisStart;
                    parityTuningLoggerTesting("increment duration = $thisDuration seconds");
                    $duration += $thisDuration;
                    $elapsed += $thisDuration;
                    parityTuningLoggerTesting("new duration: $duration seconds, elapsed: $elapsed seconds");
                    $lastFinish = $thisFinish;
                    $exitcode = $sbSyncExit;
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitcode=$exitcode");
                    break;

            // TODO:  Included for completeness although could possibly be removed when debugging complete?
            default :
                    parityTuningLoggerDebug ("unexpected progress record type: $op");
                    break;
        } // end switch
    }  // end foreach
	parityTuningLoggerTesting("ProgressFile start=" . date($dateformat,$thisStart) . ", finish=" . date($dateformat,$thisFinish));

    // Next few lines help with debugging - could be safely removed when no longer wanted.
    // Keep a copy of the most recent progress file.
    // This will help with debugging any problem reports
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
            parityTuningLoggerDebug ('appears that pause/resume not activated for' .
                                    (startsWith($action,'check') ? ' manual ' : ' ')
                                    . actionDescription());
            parityTuningLoggerDebug ('... so do not attempt to update system parity-check.log file');
            return;
        } else {
            parityTuningLoggerDebug ('appears that pause/resume was activated for' .
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
        // parityTuningLoggerTesting('history line ' . ($matchLine+1) . " $logstamp, logtime=$logtime=" . date($dateformat,$logtime));
        if ($logtime > $thisStart) {
        	parityTuningLoggerTesting ("looks like line " . ($matchLine +1) . " is the one to update, logtime = $logtime = " . date($dateformat,$logtime));
        	parityTuningLoggerTesting ($line);
        	if ($logtime <= $thisFinish) {
				parityTuningLoggerDebug ('update log entry on line ' . ($matchLine+1),", errors=$logerrors");
				$lastFinish = $logtime;
				$exitcode = $logexit;
				if ($logerrors > $corrected) $corrected = $logerrors;
				break;
			} else {
			    parityTuningLoggerTesting ("... but logtime = $logtime (" . date($dateformat,$logtime) . "), lastFinish = $lastFinish (" . date($dateformat,$lastfinish) . "), thisFinish=$thisFinish (" . date($dateformat,$thisFinish . ')'));
			}
        }
        $matchLine++;
    }
    if ($matchLine == count($lines))  parityTuningLoggerDebug('no match found in existing log so added a new record ' . ($matchLine + 1));

	$unit='';
	parityTuningLoggerTesting("mdResyncSize = $mdResyncSize, duration = $duration");
	$speed = my_scale($mdResyncSize * 1024 / $duration,$unit,1) . " $unit/s";
    $type = explode(' ',$desc);  
    $generatedRecord = date($dateformat, $lastFinish) . "|$duration|$speed|$exitcode|$corrected|$elapsed|$increments|$type[0]\n";
    parityTuningLoggerDebug("log record generated from progress: $generatedRecord");    $lines[$matchLine] = $generatedRecord;
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
        $line .= 'type|date|time|';
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

// Send a notification if increment notifications enabled
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
    if (startsWith($action,'recon') && ($parityTuningCfg['parityTuningRecon'] == 'yes')) {
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

// Get the long text description of the current runnng array operation
function actionDescription() {
    global $action;
    switch ($action) {
        case 'recon':   return 'Parity-Sync / Data Rebuild';
        case 'clear':   return 'Disk Clear';
        case 'check':   return 'Read-Check';
        case 'check P': return (($correcting == 0) ? 'Non-' : '') . 'Correcting Parity Check';
        default:        return 'unknown action: ' . $action;
    }
}

# Write message to syslog and also to console if in CLI mode
function parityTuningLogger($string) {
  global $argv;
  if (parityTuningCLI()) echo $string . "\n";
  $string = str_replace("'","",$string);
  $cmd = 'logger -t "' . basename($argv[0]) . '" "' . $string . '"';
  shell_exec($cmd);
}

# Write message to syslog if debug logging active
function parityTuningLoggerDebug($string) {
  global $parityTuningCfg, $argv;
  $string = str_replace("'","",$string);
  if ($parityTuningCfg['parityTuningDebug'] === "yes") {
  	$cmd = 'logger -t "' . basename($argv[0]) . '" "DEBUG: '  . $string . '"';
   	shell_exec($cmd);
  }
}

# Write message to syslog if testing logging active
function parityTuningLoggerTesting($string) {
  global $testing, $argv;
  $string = str_replace("'","",$string);
  if ($testing) {
  	$cmd = 'logger -t "' . basename($argv[0]) . '" "TESTING: '  . $string . '"';
  	shell_exec($cmd);
  }
}

// Determine if the current time is within a period where we expect this plugin to be active
function isParityCheckActivePeriod() {
    global $parityTuningCfg;
    $resumeTime = ($parityTuningCfg['parityTuningResumeHour'] * 60) + $parityTuningCfg['parityTuningResumeMinute'];
    $pauseTime  = ($parityTuningCfg['parityTuningPauseHour'] * 60) + $parityTuningCfg['parityTuningPauseMinute'];
    $currentTime = (date("H") * 60) + date("i");
    if ($pauseTime > $resumeTime) {         // We need to allow for times panning midnight!
        return ($currentTime > $resumeTime) && ($currentTime < $pauseTime);
    } else {
        return ($currentTime > $resumeTime) && ($currentTime < $pauseTime);
    }
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
?>
