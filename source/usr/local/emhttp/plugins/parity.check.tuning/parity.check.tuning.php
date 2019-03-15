#!/usr/bin/php
<?PHP
/*
 * Script that is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file command; or from another script.
 *
 * It takes a single parameter descrbing the action required.   If no explicit
 * action is specified then it merely updates the cron jobs for this plugin.$vars
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

// Some useful variables
$parityTuningStateFile    = "$parityTuningBootDir/$parityTuningPlugin.state";
$parityTuningCronFile     = "$parityTuningBootDir/$parityTuningPlugin.cron";
$parityTuningProgressFile = "$parityTuningBootDir/$parityTuningPlugin.progress";
$parityTuningHistoryFile  = "$parityTuningBootDir/$parityTuningPlugin.history";

// Handle generating and activating/deactivating the cron jobs for this plugin
if (empty($argv)) {
  parityTuningLoggerDebug("ERROR: No action specified");
  exit(0);
}
/*
if (count($argv)) {
    parityTuningLOggerDebug("No option provided - forcing updatecron");
    $argv[1] = 'updatecron';
}
*/

// Check for valid argument action options
$command = trim($argv[1]);
switch ($command) {

    case 'tidy':
        // This code is used to 'housekeep' the plugin's folder on the flash drvie
        // removing any old stte files to avoid them building up forever.
        // TODO: complete this functionality
        
        exit (0);
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
        
        $dailyfile='/etc/cron.daily/parity.check.tuning.tidy';
        if ($parityTuningCfg['parityTuningActive'] == "no") {
        {
            // Clear out any cron settings for this plugin
            parityTuningLoggerDebug("plugin disabled");
            @unlink ($dailyfile);
            if (!file_exists("$parityTuningCronFile")) {
                parityTuningLoggerDebug('No cron present so no action required');
                exit(0);
            }
                @unlink ($parityTuningCronFile);
                parityTuningLoggerDebug('Removed cron settings for this plugin');
            }
        } else {
            // Create the cron settings for this plugin    
            $handle = fopen ($dailyfile, "w");
            fwrite($handle,"#!/bin/sh\n");
            fwrite($handle, "$parityTuningPhpFile \"tidy\"\n");
            fclose ($handle);
            @chmod($dailyfile,0755);
            
            $handle = fopen ($parityTuningCronFile, "w");
            fwrite($handle, "\n# Generated cron schedules for $parityTuningPlugin\n");
            fwrite($handle, $parityTuningCfg['parityTuningResumeMinute']  . " " . 
                            ($parityTuningCfg['parityTuningFrequency'] === 'hourly' ? '*' : $parityTuningCfg['parityTuningResumeHour']) 
                            . " * * * $parityTuningPhpFile \"resume\"\n");
            fwrite($handle, $parityTuningCfg['parityTuningPauseMinute'] . " " . 
                            ($parityTuningCfg['parityTuningFrequency'] === 'hourly' ? '*' : $parityTuningCfg['parityTuningPauseHour'])
                            . " * * * $parityTuningPhpFile \"pause\"\n");
            fclose($handle);
            parityTuningLoggerDebug("updated cron settings in $parityTuningCronFile");
        }
        // Activate any changes
        exec("/usr/local/sbin/update_cron");
        exit (0);

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to /mdcmd was made so that we can
        // detect if a parity check was started on a schedule or whether it was manually started.
        // TODO:  This functionality works but is likely to need more work before it fully complete
       
        parityTuningLoggerDebug('detected that mdadm had been called from ' . $argv['2'] . ' with command :');
        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug($cmd);
        switch ($argv[2]) {
        case 'crond':
            parityTuningLoggerDebug('...as a scheduled task');
            switch ($argv[3]) {
            case 'check':
                    if ($argv[4] == "RESUME") {
                        parityTuningLoggerDebug ("... to resume parity check");
                    } else {
                        parityTuningProgressWrite ("STARTED");
                    }
                    break;                    
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ("... to pause parity check");
                    } else {
                        parityTuningProgressWrite ("CANCELLED");
                        parityTuningProgressAnaylze();
                    }
                    break;
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
        // @TODO: This case is aimed at running a monitor task to work out if the array state has changed and 
        // action needs to be taken as a result.
        exit(0);
    
    // We now have cases that are likely to result in action needing taking aginst the array
    case 'resume':
        blankDebugLine();
        parityTuningLoggerDebug ('Resume requested');
        break;        
    case 'pause':
        blankDebugLine();
        parityTuningLoggerDebug('Pause requested');
        break;
        
    case 'cancel':
        blankDebugLine();
        parityTuningLoggerDebug('Cancel requested');
        break;    
    case 'started':
        blankDebugLine();
        parityTuningLoggerDebug('Array startied event detected');
        break;
    case 'stopping':
        blankDebugLine();
        parityTuningLoggerDebug('Array stopping event detected');
        break;
    
    // Finally the error case.   Hopefully we never get here in normal running
    default:
        parityTuningLoggerDebug ('');       // Blank line to help break up debug sequences
        parityTuningLoggerDebug ('Error: Unrecognised option \'' . $command . '\'');  
        parityTuningLoggerDebug ('Usage: parity.check.tuning.php <action>');
        parityTuningLoggerDebug ('currently recognised values for <action> are:');
        parityTuningLoggerDebug (' updatecron');
        parityTuningLoggerDebug (' mdcmd');
        parityTuningLoggerDebug (' monitor');
        parityTuningLoggerDebug (' tidy');
        parityTuningLoggerDebug (' pause');
        parityTuningLoggerDebug (' resume');
        parityTuningLoggerDebug (' stopping');
        parityTuningLoggerDebug (' started');
        parityTuningLoggerDebug ('Command Line was:');
        $cmd = ''; for ($i = 0; $i < count($argv) ; $i++) $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug($cmd);
        parityTuningSaveState('unknown');
        blankDebugLine();
        exit(0);
}

if (! $var['mdState'] === 'STARTED') {
    parityTuningLoggerDebug ('mdState=' . $var['mdState']);
    parityTuningLogger('Array not started so no action taken');
    exit(0);
}

// We now attempt to carry out any requests that might involve the array

$active = ($var['mdResync'] > 0);
$pos    = $var['mdResyncPos'];
$size   = $var['mdResyncSize'];
$action = $var['mdResyncAction'];
$completed = sprintf ("(%.1f%% completed)", ($pos/$size*100));

switch ($command) {
    case 'resume':
        if ($pos == 0) {
            parityTuningLogger('Resume requested - but no parity sync active so doing nothing');
            blankDebugLine();
            exit(0);
        }
        if ($active) {
            parityTuningLogger('Resume requested - but parity sync already running');
            blankDebugLine();
            exit(0);
        }  
        if (startsWith($action, 'check')) {
            parityTuningLoggerDebug ("Parity check resume request sent " . $completed );
            exec('/usr/local/sbin/mdcmd "check" "RESUME"');
//TODO            parityTuningProgressWrite("RESUME");
            blankDebugLine();
            exit(0);
        }
        parityTuningUnknownState ('pause');
        blankDebugLine();
        exit(0);
        
    case 'pause':
        if ($pos == 0) {
            parityTuningLogger("Pause requested - but no parity sync active so doing nothing");
            blankDebugLine();
            exit(0);
        }
        if (! $active) {
            parityTuningLogger('Pause requested - but parity sync already paused!');
            blankDebugLine();
            exit(0);
        }
        if (startswith($action, 'check')) {
            parityTuningLoggerDebug ("Parity check  pause request sent " . $completed);
            exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
//TODO            parityTuningProgessWrite("PAUSE");
            blankDebugLine();
            exit (0);
        }
        parityTuningUnknownState ('pause');
        blankDebugLine();
        exit(0);
        
    case 'cancel':
        // Not sure we should ever get here in normal operation as only Pause/Resume events expected
        // Included for completeness
        if ($pos == 0) {
            parityTuningLogger("Cancel requested - but no parity sync active so doing nothing");
            blankDebugLine();
            exit(0);
        }
        parityTuningLoggerDebug ('mdResyncAction=' . $var['mdResyncAction']);
        if (starteWith($action, 'check')) {
            parityTuningLoggerDebug ("Parity check cancel request sent " . $completed);
            exec('/usr/local/sbin/mdcmd "nocheck"');
            blankDebugLine();
            exit(0);
        }
        parityTuningUnknownState ('cancel');
        blankDebugLine();
        exit(0);
        
    case 'stopping':        
        if (file_exists($statefile)) {
            unlink($statefile);
            parityTuningLoggerDebug("Removed existing state file %statefile");
        }
        if ($pos == 0) {
            parityTuningLoggerDebug ("no check in progress so no state saved");
            blankDebugLine();
            exit(0);
        } 
        parityTuningLoggerDebug ('Array stopping while check was in progress ' . $completed); 
        
        # Save state information about the array aimed at later implementing handling pause/resume
        # working across array stop/start.  Not sure what we will need so at the moment guessing!;
        parityTuningSaveState();
        blankDebugLine();
        exit(0);
        
    case 'started' :
        if (!file_exists($statefile)) {
            parityTuningLoggerDebug("No state file found");
            parityTuningLoggerDebug("...so no further action to take");
            exit(0);
        } 
        parityTuningLoggerDebug ("Loading state file $statefile");
        $state = parse_ini_file ($statefile);
        parityTuningLoggerDebug ("... but no further action currently taken on started event");
        parityTuningLoggerDebug ("... until Limetech provide a way of (re)starting a parity check at a defined offset");
        blankDebugLine();
        exit(0);

    default:
        # Should not be possible to get to this point!
        parityTunninglogger ("Error: Program error|");
        exit(0);
}

// Should not be possible to reach this point!
echo "\nexiting\n";
exit(0);

function blankDebugLine() {
    parityTuningLoggerDebug ('');       // Blank line to help break up debug sequences
}

// Write an entry to the progress file that is used to track increments

function parityTuningProgressWrite($msg) {
    global $var;
    global $parityTuningProgressFile;
    
    $file = fopen($parityTuningProgessFile, 'a');
    fwrite ($file, date('Y.m.d:H.i.s') . '|' . $msg . '|' . $var['mdRescyncPos']); 
    fclose($file);
}

// Process the progress file (if present) to create a history record.
// Having done so remove it as its presence implies a check in progress.
function parityTuningProgressAnalyze() {
    global $parityTuningProgessFile;
    if (!file_exists($parityTuningProgressFile)) {
        return;
    }
    unlink ($parityTuningProgressFile);
}

// Save the array state
// If no 'op' is specified then save the file with no timestamp added.
// If 'op' IS specified then add the op and a timestamp to the name to help tie it to the syslog
function parityTuningSaveState($op = "") {
    global $parityTuningStateFile;
    $f = $parityTuningStateFile . ((empty($op)) ? ('') : '-' . $op . '-' . date("Y.m.d-H.i.s"));
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
