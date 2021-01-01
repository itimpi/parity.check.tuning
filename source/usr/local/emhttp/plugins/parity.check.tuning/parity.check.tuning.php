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
 *ieg
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

require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';
require_once '/usr/local/emhttp/webGui/include/Helpers.php';

// Multi-language support

$plugin = 'parity.check.tuning';
$translations = file_exists("$docroot/webGui/include/Translations.php");
if ($translations) {
  // add translations
  $_SERVER['REQUEST_URI'] = 'paritychecktuning';
  require_once "$docroot/webGui/include/Translations.php";
} else {
  // legacy support (without javascript)
  $noscript = true;
  require_once "$docroot/plugins/$plugin/Legacy.php";
}

// Some useful variables
$parityTuningCronFile      = "$parityTuningBootDir/$parityTuningPlugin.cron";	// File created to hold current cron settings for this plugin
$parityTuningProgressFile  = "$parityTuningBootDir/$parityTuningPlugin.progress";// Created when arry operation active to hold increment info
$parityTuningScheduledFile = "$parityTuningBootDir/$parityTuningPlugin.scheduled";// Created when we detect an array operation started by cron
$parityTuningHotFile       = "$parityTuningBootDir/$parityTuningPlugin.hot";	 // Created when paused because at least one drive fount do have rezched 'hot' temperature
$parityTuningCriticalFile  = "$parityTuningBootDir/$parityTuningPlugin.critical";// Created when parused besause at least one drive found to reach critical temperature
$parityTuningRestartFile   = "$parityTuningBootDir/$parityTuningPlugin.restart"; // Created if arry stopped with array operation active to hold restart info
$parityTuningDisksFile     = "$parityTuningBootDir/$parityTuningPlugin.disks";   // Copy of disks.ini when restzrt info sved to check disk configuration
$parityTuningTidyFile      = "$parityTuningBootDir/$parityTuningPlugin.tidy";	 // Create when we think there was a tidy shutdown
$parityTuningUncleanFile   = "$parityTuningBootDir/$parityTuningPlugin.unclean"; // Create when we think unclean shutdown forces a parity chack shutdown
$parityTuningVarFile       = '/var/local/emhttp/var.ini';
$parityTuningSyncFile      = '/boot/config/forcesync';							 // Presence of file used by Unraid to detect unclean Shutdown (we currently ignore)

$dateformat = 'Y M d H:i:s';
$cfgIncrements  = ($parityTuningCfg['parityTuningIncrements'] === "yes");
$cfgUnscheduled = ($parityTuningCfg['parityTuningUnscheduled'] === "yes");
$cfgRecon       = ($parityTuningCfg['parityTuningRecon'] === "yes");
$cfgClear       = ($parityTuningCfg['parityTuningClear'] === "yes");
$cfgRestart     = ($parityTuningCfg['parityTuningRestart'] === "yes");
$cfgShutdown    = ($parityTuningCfg['parityTuningHeatShutdown'] === 'yes');
$cfgHeat        = ($parityTuningCfg['parityTuningHeat'] === 'yes');
$cfgTesting     = ($parityTuningCfg['parityTuningDebug'] === "test");
$cfgDebug       = ($parityTuningCfg['parityTuningDebug'] === "yes") || $cfgTesting;

// List of fields we save for progress.
// Might not all be needed but better to have more information than necessary
$progressfields = array('sbSynced','sbSynced2','sbSyncErrs','sbSyncExit',
                       'mdState','mdResync','mdResyncPos','mdResyncSize','mdResyncCorr','mdResyncAction' );

loadVars();

if (empty($argv)) {
  parityTuningLoggerDebug(_("ERROR") . ": " . _("No action specified"));
  exit(0);
}



// This plugin will never do anything if array is not started
// TODO Check if Maintenance mode has a different value for the state

if ($var['mdState'] != 'STARTED') {
    parityTuningLoggerTesting ('mdState=' . $var['mdState']);
    parityTuningLoggerTesting(_('Array not started so no action taken'));
    exit(0);
}


// Take the action requested via the command line argument(s)
// Effectively each command line option is an event type1

$command = trim($argv[1]);
spacerDebugLine(true, $command);
switch ($command) {

    case 'updatecron':
        // This is called any time that the user has updated the settings for this plugin to reset any cron schedules.
        ParityTuningDeleteFile ($parityTuningCronFile);
        if (($parityTuningCfg['parityTuningIncrements'] == "no") && (!cfgHeat)) {
            parityTuningLoggerDebug(_("No cron events for this plugin are needed"));
        } else {
            $lines = [];
            $lines[] = "\n# Generated schedules for $parityTuningPlugin\n";
            if ($cfgIncrements) {
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
                if (!$cfgHeat && !$cfgShutdown) {
                  // Once an hour for parity checks if not monitoring more frequently for temperature
                  $lines[] = "17 * * * * $parityTuningPhpFile \"monitor\" &>/dev/null\n";
                }
                parityTuningLoggerDebug (_('created cron entries for running increments'));
            }
            if ($cfgHeat || $cfgShutdown) {
                $lines[] = "*/7 * * * * $parityTuningPhpFile \"monitor\" &>/dev/null\n";	// Every 7 minutes for temperature
                parityTuningLoggerDebug (_('created cron entry for monitoring disk temperatures'));
            }
            file_put_contents($parityTuningCronFile, $lines);
            parityTuningLoggerTesting(sprintf(_('updated cron settings are in %s'),$parityTuningCronFile));
        }
        // Activate any changes
        exec("/usr/local/sbin/update_cron");
        break;

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to 'mdcmd' was made so that we can
        // detect if a parity check was started on a schedule or whether it was manually started.

        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug(sprintf(_('detected that mdcmd had been called from %s with command %s'), $argv['2'], $cmd));
        switch ($argv[2]) {
        case 'crond':
        case 'sh':
            switch ($argv[3]) {
            case 'check':
                    loadVars(5);         // give time for start/resume
                    if ($argv[4] == "RESUME") {
                        parityTuningLoggerDebug ('... ' . sprintf ('to resume %s', actionDescription()));
                        parityTuningProgressWrite('RESUME');            // We want state after resume has started
                    } else {

                        // @TODO work out what type of check (scheduled/unscheduled/unclean)
                        if (file_exists($parityTuningProgressFile)) {
                            parityTuningLoggerTesting('analyze previous progress before starting new one');
                            parityTuningProgressAnalyze();
                        }
                        if ($argv[2] == 'crond') parityTuningLoggerDebug ('... ' . sprintf(_('appears to be a regular scheduled check')));
                        parityTuningProgressWrite ("STARTED");

                    }
                    break;
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ('...' . sprintf ('to pause %s', actionDescription()));
                        loadVars(5);         // give time for pause
                        parityTuningProgressWrite ("PAUSE");
                    } else {
                        // Not sure this even a possible operation but we allow for it anyway!
                        parityTuningProgressWrite ('CANCELLED');
                        parityTuningProgressAnaylze();
                    }
                    break;
            case 'array_started':
            		parityTuningProgressWrite ('PAUSE (RESTART)');
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
        // that we need to take some action on.  In particular disks overheating (or cooling back down).
        //
        // The frequency varies according to whether temperatures are being checked as then we do it more often.


        // Check for disk temperature changes we are monitoring

        if ((!$cfgHeat) && (! $scfgSutdown)) {
            parityTuningLoggerDebug (_('Temperature monitoring switched off'));
            ParityTuningDeleteFile ($parityTuningCriticalFile);
            break;
        }

        // Get temperature information

        $disks = parse_ini_file ('/var/local/emhttp/disks.ini', true);

        // Merge SMART settings
        require_once "$docroot/webGui/include/CustomMerge.php";

        $dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);

		$criticalDrives = array();  // drives that exceed shutdown threshold
        $hotDrives = array();       // drives that exceed pause threshold
        $warmDrives = array();      // drives that are between pause and resume thresholds
        $coolDrives = array();      // drives that are cooler than resume threshold
        $driveCount = 0;
        $arrayCount = 0;
        $status = '';
		$cfgCritical = $parityTuningCfg['parityTuningHeatCritical'];
		$cfgHigh     = $parityTuningCfg['parityTuningHeatHigh'];
	    $cfgLow      = $parityTuningCfg['parityTuningHeatLow'];
        parityTuningLoggerTesting (sprintf(_('plugin temperature settings: pause %s, resume %s'),$cfgHigh, $cfgLow)
                				   . ($cfgShutdown ? ', ' . sprintf(_('shutdown %s'), $cfgCritical) : ''));
        foreach ($disks as $drive) {
            $name=$drive['name'];
            $temp = $drive['temp'];
            if ((!startsWith($drive['status'],'DISK_NP')) & ($name != 'flash')) {
                $driveCount++;
                $critical  = ($drive['maxTemp'] ?? $dynamixCfg['display']['max']) - $cfgCritical;
                $hot  = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $cfgHigh;
                $cool = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $cfgLow;

				// Check array drives for other over-heating
				if ((startsWith($name, 'parity')) || (startsWith($name,'disk'))) {
					$arrayCount++;
					if (($temp == "*" ) || ($temp <= $cool)) {
					  $coolDrives[$name] = $temp;
					  $status = 'cool';
					} elseif ($temp >= $hot) {
					  $hotDrives[$name] = $temp;
					  $status = 'hot';
					} else {
						$warmDrives[$name] = temp;
						$status = 'warm';
					}
                }
				//  Check all array and cache drives for critical temperatures
				//  TODO: find way to include unassigned devices?
				if ((($temp != "*" )) && ($temp >= $critical)) {
					parityTuningLoggerTesting(sprintf('Drive %s%s appears to be critical', $temp, $tempUnit));
					$criticalDrives[$name] = $temp;
					$status = 'critical';
				}
                parityTuningLoggerTesting (sprintf('%s temp=%s%s, status=%s (drive settings: hot=%s%s, cool=%s%s',$name, $temp, $tempUnit, $status, $hot, $tempUnit, $cool, $tempUnit)
                						   . ($cfgShutdown ? sprintf(', critical=%s%s',$critical, $tempUnit) : ''). ')');
            }
        }


        // Handle at least 1 drive reaching shutdown threshold

        if ($cfgShutdown) {
			if (count($criticalDrives) > 0) {
				$drives=listDrives($criticalDrives);
				parityTuningLogger(_("Array being shutdown due to drive overheating"));
				file_put_contents ($parityTuningCriticalFile, "$drives\n");
				$msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
				if ($active) {
					parityTuningLoggerTesting('array operationis active');
					$msg .= '<br>' . _('Abandoned ') . actionDescription() . $completed;
				}
				sendNotification (_('Array shutdown'), $msg, 'alert');
				if ($cfgTesting) {
					parityTuningLoggerTesting (_('Shutdown not actioned as running in TESTING mode'));
				} else {exit(0);
					sleep (15);	// add a delay for notification to be actioned
					parityTuningLogger (_('Starting Shutdown'));
					exec('/sbin/shutdown -h -P now');
				}
				break;
			} else {
				parityTuningLoggerDebug(_("No drives appear to have reached shutdown threshold"));
			}
	    }

		// See if temperatures are even relevant so that we need to consider pause/resume

		if (! $active) {
			parityTuningLoggerDebug (_('No array operation currently in progress'));
			ParityTuningDeleteFile($parityTuningScheduledFile);
			parityTuningProgressAnalyze();
			break;
		}
		if (! $running) {
			parityTuningLoggerDebug (_('Parity check appears to be paused'));
		} elseif (! file_exists($parityTuningProgressFile)) {
			parityTuningProgressWrite ("STARTED");
			parityTuningLoggerDebug ( _('Unscheduled array operation in progress'));
		}

		// Handle drives being paused/resumed due to temperature

        parityTuningLoggerDebug (sprintf('%s=%d, %s=%d, %s=%d, %s=%d', _('array drives'), $arrayCount, _('hot'), count($hotDrives), _('warm'), count($warmDrives), _('cool'), count($coolDrives)));
        if ($running) {
        	// Check if we need to pause because at least one drive too hot
            if (count($hotDrives) == 0) {
                parityTuningLoggerDebug (sprintf('%s %s',actionDescription(), _('with all drives below temperature threshold for a Pause')));
            } else {
                $drives = listDrives($hotDrives);
                file_put_contents ($parityTuningHotFile, "$drives\n");
                $msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
                parityTuningLogger (sprintf('%s %s %s: %s',_('Paused'), actionDescription(), $completed, $msg ));
                parityTuningProgressWrite('PAUSE (HOT)');
                exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                sendTempNotification(_('Pause'),$msg);
            }
        } else {

        	// Check if we need to resume because drives cooled sufficiently

            if (! file_exists($parityTuningHotFile)) {
                parityTuningLoggerDebug (_('Array operation paused but not for temperature related reason'));
            } else {
             	if (count($hotDrives) != 0) {
             		parityTuningLoggerDebug (_('Array operation paused with some drives still too hot to resume'));
                } else {
             		if (count($warmDrives) != 0) {
						parityTuningLoggerDebug (_('Array operation paused but drives not cooled enough to resume'));
                    } else {
                		parityTuningLogger (sprintf ('%s %s %s %s',_('Resumed'), actionDescription(), $completed, _('as drives now cooled down')));
                		parityTuningProgressWrite('RESUME (COOL)');
                		exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                		sendTempNotification(_('Resume'), _('Drives cooled down'));
                		ParityTuningDeleteFile ($parityTuningHotFile);
                	}
				}
            }
        }
        break;

    // We now have cases that are likely to result in action needing taking against the array
    case 'resume':
        parityTuningLoggerDebug (_('Resume request'));
        if (isArrayOperationActive()) {
            if (configuredAction()) {
                if ($running) {
                    parityTuningLoggerDebug(sprintf('... %s %s', actionDescription(), _('already running')));
                    if (! file_exists($parityTuningProgressFile)) parityTuningProgressWrite('MANUAL');
                } else {
                    sendArrayNotification(isParityCheckActivePeriod() ? _('Scheduled resume') : _('Resumed'));
                    exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                    file_put_contents($parityTuningScheduledFile,"RESUME");		// Set marker for scheduled resume
                }
            }
        }
        break;

    case 'pause':
        parityTuningLoggerDebug(_('Pause request'));
        if (isArrayOperationActive()) {
            if (configuredAction()) {
                if (! $running) {
                    parityTuningLoggerDebug(sprintf('... %s %s!', actionDescription(), _('already paused')));
                } else {
                	// TODO May want to create a 'paused' file to indicate reason for pause?
run_pause:
                    exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                    file_put_contents($parityTuningScheduledFile,"PAUSE");		// Set marker for shecduled pause
                    loadVars(5);
                    sendArrayNotification (isParityCheckActivePeriod() ? _('Scheduled pause') : _('Paused'));
                }
            }
        }
        break;

    case 'array_started':
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        if (file_exists($parityTuningTidyFile)) parityTuningLoggerTesting('tidy shutdown file present');
    	parityTuningLoggerDebug (_('Array has not yet been started'));
    	if (file_exists($parityTuningCriticalFile) && ($drives=file_get_contents($parityTuningCriticalFile))) {
			$reason = _('Detected shutdown that was due to following drives reaching critical temperature');
			parityTuningLogger ("$reason\n$drives");
		}
		ParityTuningDeleteFile($parityTuningCriticalFile);
		if (file_exists($parityTuningHotFile) && $drives=file_get_contents($parityTuningHotFile)) {
			$reason = _('Detected that array had been paused due to following drives getting hot');
			parityTuningLogger ("$reason\n$drives");
	    }
		ParityTuningDeleteFile($parityTuningHotFile);
    	if (!file_exists($parityTuningTidyFile)) {
    		parityTuningLoggerTesting(_("Tidy shutdown file not present in $command event"));
			parityTuningLogger(_('Unclean shutdown detected'));
			if (file_exists($parityTuningProgressFile) && $cfgRestartOK) {
			    sendNotification (_('Array operation will not be restarted'), _('Unclean shutdown detected'), 'alert');
			}
			exec ("touch $parityTuningUncleanFile");		// set to indicate we detected unclean shutdown
			goto end_array_started;
    	}
    	break;

    case 'started':
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        parityTuningLoggerDebug (_('Array has just been started'));

        if (file_exists($parityTuningDisksFile)) {
			$disksCurrent = parse_ini_file ('/var/local/emhttp/disks.ini', true);
			$disksOld     = parse_ini_file ($parityTuningDisksFile, true);
			$disksOK = true;
			foreach ($disksCurrent as $drive) {
				$name=$drive['name'];
				if ((startsWith($name, 'parity')) || (startsWith($name,'disk'))) {
					if (($disksCurrent[$name]['id']     != $disksOld[$name]['id'])
					||  ($disksCurrent[$name]['status'] != $disksOld[$name]['status'])
					||  ($disksCurrent[$name]['size']   != $disksOld[$name]['size'])) {
						$disksOK = false;
						parityTuningLogger($name . ': ' . _('Changed'));
					}
				}
			}
			ParityTuningDeleteFile($parityTuningDisksFile);
			if ($disksOK) {
				parityTuningLoggerTesting ('Disk configuration appears to be unchanged');
			} else {
				parityTuningLogger (_('Detected a change to the disk configuration'));
				sendNotification (_('Array operation will not be restarted'), _('Detected a change to the disk configuration'), 'alert');
				goto end_array_started;
			}
        }

		// Sanity Checks
		// (not sure these are really necessary - but better safe than sorry!)
		if (! file_exists($parityTuningRestartFile)) {
	        parityTuningLoggerTesting (_('No restart information present'));
            goto end_array_started;
		} else {
			if (!file_exists($parityTuningProgressFile)) {
		        parityTuningLogger(_('Something wrong: restart information present without progress present'));
		        goto end_array_started;
		    }
		    if (! $cfgRestartOK) {
			    parityTuningLogger (_('Unraid version too old to support restart of array operations'));
			    parityTuningProgressWrite('ABORTED');
				goto end_array_started;
            }
		}

        // Handle restarting array operations

		$restart = parse_ini_file($parityTuningRestartFile);

		parityTuningLoggerTesting('restart information:');
		foreach ($restart as $key => $value) parityTuningLoggerTesting("$key=$value");
		parityTuningLoggerTesting ('Mode at shutdown: ' . $restart['startMode'] . ', current mode: ' . $var['startMode']);
		if ($var['startMode'] != $restart['startMode']) {
	        parityTuningLogger (_('array started in different mode'));
	        parityTuningLoggerDebug (_('Restart not possible'));
		    goto end_array_started;
		}
		$restartPos = $restart['mdResyncPos'];
		$restartPos += $restartPos;				// convert from 1K units to 512-byte sectors
		$adj = $restartPos % 8;					// Position must be mutiple of 8
		if ($adj != 0) {						// Not sure this can occur but better to play safe
			parityTuningLoggerTesting(sprintf('restartPos: %d, adjustment: %d', $restartPos, $adj));
    		$restartPos -= $adj;
    	}
		$cmd = 'mdcmd check ' . ($restart['mdResyncCorr'] == 1 ? '' : 'NO') . 'CORRECT ' . $restartPos;
		ParityTuningDeleteFile($parityTuningRestartFile);
		parityTuningLoggerTesting('restart command: ' . $cmd);
		exec ($cmd);
        loadVars(5);     // give time for any array operation to start running
        parityTuningProgressWrite('RESTART');
        sendNotification(_('Array operation restarted'),  actionDescription() . $completed);
        ParityTuningDeleteFile($parityTuningRestartFile);
        if ($restart['mdResync'] == 0) {
           parityTuningLoggerTesting(_('Array operation was paused'));
        }
        if (file_exists($parityTuningScheduledFile)) {
        	parityTuningLoggerTesting ('Appears that scheduled pause/resume was active when array stopped');
            if (! isParityCheckActivePeriod()) {
				parityTuningLoggerDebug(_('Outside time slot for running scheduled parity checks'));
				loadVars(60);		// allow time for Unraid standard notificaton to be output before attempting pause (monitor runs every minute)
           		goto run_pause;
           	}
        }
		// FALL-THRU
end_array_started:
  		if (file_exists($parityTuningRestartFile)) {
  			parityTuningLogger(_('Restart will not be attempted'));
 			ParityTuningDeleteFile($parityTuningRestartFile);
 			parityTuningLoggerTesting(_("Deleted $parityTuningRestartFile"));
 			if (file_exists($parityTuningProgressFile)) {
 			    parityTuningProgressWrite('RESTART CANCELLED');
 			}
 		}
 		ParityTuningDeleteFile($parityTuningDisksFile);
 		ParityTuningDeleteFile($parityTuningTidyFile);
        ParityTuningDeleteFile($parityTuningScheduledFile);
		if (file_exists($parityTuningUncleanFile) && (! $noParity)) {
			sendNotification (_('Automatic Unraid parity check will be started'), _('Unclean shutdown detected'), 'warning');
		}
		parityTuningProgressAnalyze();
		ParityTuningDeleteFile($parityTuningUncleanFile);
		break;

    case 'stopping':
        parityTuningLoggerDebug(_('Array stopping'));
        ParityTuningDeleteFile($parityTuningRestartFile);
        if (!$active) {
            parityTuningLoggerDebug (_('no array operation in progress so no restart information saved'));
            parityTuningProgressAnalyze();

        } else {
            parityTuningProgressWrite('STOPPING');
			parityTuningLoggerDebug (sprintf(_('Array stopping while %s was in progress %s'), actionDescription(), $completed));
			if ($cfgRestartOK) {
			    sendNotification(_('Array stopping: Restart will be attempted on next array start'), actionDescription() . $completed,);
			    saveRestartInformation();
			} else {
				parityTuningLoggerDebug('Unraid version ' . $unraid['version'] . ' too old to support restart');
			}
        }
        break;

    case 'stopping_array':
    case 'stopped':
    	exec ("touch $parityTuningTidyFile");
    	parityTuningLoggerDebug ("Created $parityTuningTidyFile to indicate tidy shutdown");
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
        parityTuningLoggerDebug(sprintf(_('using scheduled mode of %s'),$command));
        // fallthru now we know the mode to use
    case 'correct':
    case 'nocorrect':
        if (isArrayOperationActive()) {
            parityTuningLogger(sprintf(_('Not allowed as %s already running'), actionDescription()));
            break;
        }
        $correcting =($command == 'correct') ? true : false;
		exec("/usr/local/sbin/mdcmd check $command");
        loadVars(2);
	    parityTuningLogger(actionDescription() . ' Started');
        if ($action == 'check' && ( $command == 'correct')) {
            parityTuningLogger(_('Only able to start a Read-Check due to number of disabled drives'));
        }
	    break;

    case 'cancel':
        parityTuningLoggerDebug(_('Cancel request'));
        if (isArrayOperationActive()) {
            parityTuningLoggerDebug ('mdResyncAction=' . $action);
			parityTuningProgressWrite('CANCELLED');
			exec('/usr/local/sbin/mdcmd "nocheck"');
            parityTuningLoggerDebug (sprintf(_('%s cancel request sent %s'), actionDescription(), $completed));
            loadVars();
            parityTuningLogger(sprintf(_('%s Cancelled'),actionDescription()));
        }
        break;

    case 'stop':
    case 'start':
        parityTuningLogger("'$command' option not currently implemented");
        // fallthru to usage section


	// Potential Unraid event types on which no action is (currently) being taken by this plugin?
	// They are being caught at the moment so we can see when they actually occur.

	case 'driver_loaded':
	case 'starting':
	case 'disks_mounted':
	case 'svcs_restarted':
	case 'docker_started':
	case 'libvirt_started':
    case 'stopping_svcs':
    case 'stopping_libvirt':
    case 'stopping_docker':
    case 'unmounting_disks':
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        if (file_exists($parityTuningTidyFile)) parityTuningLoggerTesting('tidy shutdown file present');
    	break;

    // Finally the error/usage case.   Hopefully we never get here in normal running
    case 'help':
    case '--help':
    default:
        parityTuningLogger ('');       // Blank line to help break up debug sequences
        parityTuningLogger (_('ERROR') . ': ' . sprintf(_('Unrecognised option %s'), $command));
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
spacerDebugLine(false, $command);
exit(0);

// Determine in invoked via CLI
function parityTuningCLI() {
	global $argv;
	return (basename($argv[0]) == 'parity.check');
}

function saveRestartInformation() {
	global $active, $cfgRestartOK, $var, $parityTuningRestartFile, $parityTuningDisksFile;
    ParityTuningDeleteFile($parityTuningRestartFile);
    ParityTuningDeleteFile($parityTuningDisksFile);

    if ($active && $cfgRestartOK) {
        $restart = 'mdResync=' . $var['mdResync'] . "\n"
				   .'mdResyncPos=' . $var['mdResyncPos'] . "\n"
				   .'mdResyncSize=' . $var['mdResyncSize'] . "\n"
				   .'mdResyncAction=' . $var['mdResyncAction'] . "\n"
				   .'mdResyncCorr=' . $var['mdResyncCorr'] . "\n"
			       .'startMode=' . $var['startMode'] . "\n";
		file_put_contents ($parityTuningRestartFile, $restart);
		parityTuningLoggerTesting(sprintf( _('Restart information saved to file %s'), $parityTuningRestartFile));
		copy ('/var/local/emhttp/disks.ini', $parityTuningDisksFile);
		parityTuningLoggerTesting(sprintf( _('Current disks information saved to file %s'), $parityTuningDisksFile));
	}
}

// Function to remove a file and is TESTING logging active then log it has happened

function ParityTuningDeleteFile($name) {
 	if (file_exists($name)) {
		@unlink($name);
		parityTuningLoggerTesting(_("Deleted $name"));
	}
}

// Helps break debug information into blocks to identify entrie for a given entry point
function spacerDebugLine($strt = true, $cmd) {
    // Not sure if this should be active at DEBUG level of only at TESTING level?
    parityTuningLoggerTesting ('----------- ' . strtoupper($cmd) . (($strt == true) ? ' begin' : ' end') . ' ------');
}

function listDrives($drives) {
	global $tempUnit;
	$msg = '';
    foreach ($drives as $key => $value) {
        $msg .= $key . '(' . $value . $tempUnit . ') ';
    }
    return $msg;
}

// is an array operation in progress
function isArrayOperationActive() {
	global $pos, $parityTuningRestartFile;
	if (file_exists($parityTuningRestartFile)) {
	   parityTuningLoggerTesting ('Restart file found - so treat as isArrayOperationActive=false');
	   return false;
	}
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
    global $parityTuningProgressFile, $parityTuningScheduledFile, $parityTuningRestartFile;
    global $parityTuningCfg;
    global $var, $action;
    global $dateformat;

    if (! file_exists($parityTuningProgressFile)) {
        parityTuningLoggerTesting(' no progress file to anaylse');
        return;
    }
    if (file_exists($parityTuningRestartFile)) {
	    parityTuningLoggerTesting(' restart pending - so not time to analyze progess');
	    return;
    }
    if ($var['mdResyncPos'] != 0) {
        parityTuningLoggerTesting(' array operation still running - so not time to analyze progess');
        return;
    }
    spacerDebugLine(true, 'ANALYSE PROGRESS');
    parityTuningLoggerTesting('Previous array operation finished - analyzing progress information to create history record');
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
				$thisOffset = $temp - $timestamp;  // This allows for diagnostic files from a different timezone when debugging
				if ($thisOffset != 0) parityTuningLoggerTesting ("Progress time offset = $thisOffset seconds");
			}
        }
        switch ($op) {
        	case 'UNKNOWN':
        	case 'ABORTED': // Indicates that a parity check has been aborted due to unclean shutdown, so ignore this record
        	case 'type':    // TODO: This record type could probably be removed when debugging complete
        			break;

            case 'STARTED': // TODO: Think can be ignored as only being documentation?
            case 'MANUAL':  // TODO: Think can be ignored as only being documentation?
            		if ($timestamp) $thisStart =  $thisFinish = $lastFinish = ($timestamp  + $thisOffset);
            		$increments = 1;		// Must be first increment!
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitcode=$exitcode");
                    break;

             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
            case 'RESUME':
            case 'RESUME (COOL)':
            case 'RESTART':
                    $increments++;		// Must be starting new increment
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
            case 'PAUSE (RESTART)':
            case 'COMPLETED':
            case 'STOPPING':
            case 'CANCELLED':
            case 'RESTART CANCELLED':
                    if ($increments == 0) $increments = 1;			// can only happen if we did not see start so assume first increment
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
    ParityTuningDeleteFile("$parityTuningProgressfile.save");
	rename ($parityTuningProgressFile, "$parityTuningProgressFile.save");
    parityTuningLoggerDebug("Old progress file available as $parityTuningProgressFile.save");
    ParityTuningDeleteFile($parityTuningProgressFile);		// finished with Progress file so remove it

    if (! startsWith($action,'check')) {
        parityTuningLoggerDebug('array action was not Parity Check - it was ', actionDescription());
        parityTuningLoggerDebug('... so update to parity check history not appropriate');
        ParityTuningDeleteFile($parityTuningScheduledFile);   // should not exist but lets play safe!
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
    ParityTuningDeleteFile($parityTuningScheduledFile);

	if ($increments == 0) {
    	parityTuningLoggerTesting('no increments found so no need to patch history file');
    } else {
		// Now we want to patch the entry in the standard parity log file
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
		$speed = my_scale($mdResyncSize * (1024 / $duration), $unit,1);
		$speed .= "$unit/s";
		$type = explode(' ',$desc);
		$gendate = date($dateformat, $lastFinish);
		if ($gendate[9] == '0') $gendate[9] = ' ';  // change leading 0 to leading space
		if (file_exists($parityTuningUncleanFile)) $exitCode = -5;		// Force error code for history
		$generatedRecord = "$gendate|$duration|$speed|$exitcode|$corrected|$elapsed|$increments|$type[0]\n";
		parityTuningLoggerDebug("log record generated from progress: $generatedRecord");    $lines[$matchLine] = $generatedRecord;

		$myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
		file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);  // Save for debug purposes
		file_put_contents($parityLogFile,$lines);
		// send Notification about data written to history file
		sendNotification(sprintf(_('%s %s (%d errors)'), actionDescription($mdResyncAction, $mdResyncCorr) ,
									(file_exists($parityTuningUncleanFile) ? _('aborted due to Unclean shutdown') : _('finished')), $corrected),
									sprintf(_('%s %s, %s %s, %s %d, %s %s'),_('Elapsed Time'),his_duration($elapsed),
																			_('Runtime'), his_duration($duration),
																			_('Increments'), $increments,
																			_('Average Speed'),$speed));
	}
	spacerDebugLine(false, 'ANALYSE PROGRESS');
}

// /following 2 functins copied from parity history script
function his_plus($val, $word, $last) {
  return $val>0 ? (($val||$last)?($val.' '.$word.($last?'':', ')):'') : '';
}
function his_duration($time) {
  if (!$time) return 'Unavailable';
  $days = floor($time/86400);
  $hmss = $time-$days*86400;
  $hour = floor($hmss/3600);
  $mins = $hmss/60%60;
  $secs = $hmss%60;
  return his_plus($days,_('day'),($hour|$mins|$secs)==0).his_plus($hour,_('hr'),($mins|$secs)==0).his_plus($mins,_('min'),$secs==0).his_plus($secs,_('sec'),true);
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
    parityTuningLoggerTesting ('written ' . $msg . ' record to  ' . $parityTuningProgressFile);
}

// send a notification without checking if enabled.

function sendNotification($msg, $desc = '', $type = 'normal') {
    global $emhttpDir;
    parityTuningLogger (_('Sent notification') . ': ' . $msg .': ' . $desc);
    $cmd = $emhttpDir . '/webGui/scripts/notify -e "Parity Check Tuning" -i ' . $type . (version_compare('6.8.3', $unraid, '>') ? ' -l "/Settings/Scheduler"' : '') . ' -s "'
                    . $msg . '"' . (($desc == '') ? '' : ' -d "' . $desc . '"' );
    parityTuningLoggerTesting ("... using $cmd");
    exec ($cmd);
}

// send a notification without checking if enabled.  Always add point reached.

function sendNotificationWithCompletion($op, $desc = '', $type = 'normal') {
    global $completed;
    sendNotification ($op, $desc .  (strlen($desc) > 0 ? '<br>' : '') . actionDescription() . $completed, $type);
}

// Send a notification if increment notifications enabled

function sendArrayNotification ($op) {
    global $parityTuningCfg;
    parityTuningLoggerTesting("Pause/Resume notification message: $op");
    if ($parityTuningCfg['parityTuningNotify'] == 'no') {
        parityTuningLoggerDebug('... Array notifications disabled so ' . $op . ' message not sent');
        return;
    }
    sendNotificationWithCompletion($op);
}

// Send a notification if temperature related notifications enabled


function sendTempNotification ($op, $desc, $type = 'normal') {
    global $parityTuningCfg;
    parityTuningLoggerTesting("Heat notification message: $op: $desc");
    if ($parityTuningCfg['parityTuningHeatNotify'] == 'no') {
        parityTuningLoggerTesting('... Heat notifications disabled so not sent');
        return;
    }
    sendNotificationWithCompletion($op, $desc, $type);
}

// Confirm that action is valid according to user settings

function configuredAction() {
    global $action, $parityTuningScheduledFile, $cfgRecon, $cfgClear, $cfgUnscheduled;
    if (startsWith($action,'recon') && $cfgRecon) {
        parityTuningLoggerTesting('...configured action for ' . actionDescription());
        return true;
    }
    if (startsWith($action,'clear') && $cfgClear) {
        parityTuningLoggerTesting('...configured action for ' . actionDescription());
        return true;
    }
    if (startsWith($action,'check')) {
        if (file_exists($parityTuningScheduledFile)) {
            parityTuningLoggerTesting('...configured action for scheduled ' . actionDescription());
            return true;
        }
        if ($cfgUnscheduled) {
            parityTuningLoggerTesting('...configured action for unscheduled ' . actionDescription());
            return true;
        }
    }
    parityTuningLoggerDebug('...action not configured for'
                            . (startsWith($action,'check') ? ' manual ' : ' ')
                            . actionDescription(). ' (' . $action . ')');
    return false;
}

// Get the long text description of an array operation

function actionDescription() {
	global $action, $correcting;
    $act = explode(' ', $action );
    switch ($act[0]) {
        case 'recon':	// TODO use extra array entries to decide if disk rebuild in progress or merely parity sync
        				return _('Parity Sync') . '/' . _('Data Rebuild');
        case 'clear':   return _('Disk Clear');
        case 'check':   if (count($act) == 1) return _('Read Check');
        				return (($correcting == 0) ? _('Non-Correcting') : _('Correcting')) . ' ' . _('Parity Check');
        default:        return sprintf('%s: %s',_('Unknown action'), $action);
    }
}

// Determine if the current time is within a period where we expect this plugin to be active
// TODO: Work out an answer for custom schedules

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

// load some state information.
// (written as a function to facilitate reloads)
function loadVars($delay = 0) {
    if ($delay > 0) sleep($delay);

	global $var, $pos, $size, $action, $parityTuningVarFile;
    global $completed, $active, $running, $correcting;

	if (! file_exists($parityTuningVarFile)) {		// Protection against running plugin while system initialising so this file not yet created
		// parityTuningLoggerTesting("Trying to populate \$vars before $parityTuningVarFile created so ignored");
		return;
	}

   	$var = parse_ini_file($parityTuningVarFile);

    $pos    = $var['mdResyncPos'];
    $size   = $var['mdResyncSize'];
    $action = $var['mdResyncAction'];
    $completed = sprintf(" (%s %s) ", (($size > 0) ? sprintf ("%.1f%%", ($pos/$size*100)) : '0%' ), _('completed'));
    $active = ($pos > 0);                       // If array action is active (paused or running)
    $running = ($var['mdResync'] > 0);        // If array action is running (i.e. not paused)
    $correcting = $var['mdResyncCorr'];
}

// Write message to syslog and also to console if in CLI mode
function parityTuningLogger($string) {
  global $argv;
  if (parityTuningCLI()) echo $string . "\n";
  $string = str_replace("'","",$string);
  $cmd = 'logger -t "' . basename($argv[0]) . '" "' . $string . '"';
  shell_exec($cmd);
}

// Write message to syslog if debug or testing logging active
function parityTuningLoggerDebug($string) {
  global $cfgDebug;
  if ($cfgDebug) {
  	parityTuningLogger('DEBUG: ' . $string);
  }
}

// Write message to syslog if testing logging active
function parityTuningLoggerTesting($string) {
  global $cfgTesting;
  if ($cfgTesting) {
  	parityTuningLogger('TESTING: ' . $string);
  }
}


?>
