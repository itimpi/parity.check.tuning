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
 * Copyright 2019-2021, Dave Walker (itimpi).
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
// error_reporting(E_ALL);

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

$parityTuningDateFormat = 'Y M d H:i:s';

loadVars();

if (empty($argv)) {
  parityTuningLoggerDebug(_("ERROR") . ": " . _("No action specified"));
  exit(0);
}

$command = trim($argv[1]);

// This plugin will never do anything if array is not started
// TODO Check if Maintenance mode has a different value for the state

// if (($parityTuningVar['mdState'] != 'STARTED' & (! $command == 'updatecron')) {
//     parityTuningLoggerTesting ('mdState=' . $parityTuningVar['mdState']);
//     parityTuningLoggerTesting(_('Array not started so no action taken'));
//     exit(0);
// }

// Take the action requested via the command line argument(s)
// Effectively each command line option is an event type1

spacerDebugLine(true, $command);
switch ($command) {

    case 'updatecron':
		updateCronEntries();
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
                        parityTuningLoggerDebug ('... ' . sprintf ('to resume %s', actionDescription($parityTuningAction, $parityTuningCorrecting)));
                        parityTuningProgressWrite('RESUME');            // We want state after resume has started
                    } else {
						if (file_exists($parityTuningProgressFile)) {
							parityTuningLoggerTesting('analyze previous progress before starting new one');
							parityTuningProgressAnalyze();
                        }
                        parityTuningDeleteFile($parityTuningScheduledFile);
                        parityTuningDeleteFile($parityTuningUnscheduledFile);
                        // @TODO work out what type of check (scheduled/unscheduled/unclean)
                        if ($argv[2] == 'crond') {
                        	parityTuningLoggerDebug ('... ' . sprintf(_('appears to be a regular scheduled check')));
        	 				exec ("touch $parityTuningScheduledFile");
        	 				 parityTuningProgressWrite ('SCHEDULED');
						} else {
                            parityTuningLoggerDebug ('... ' . sprintf(_('appears to be an unscheduled check')));
                            exec ("touch $parityTuningUnscheduled File");
                            parityTuningProgressWrite ('UNSCHEDULED');
                        }
                        parityTuningProgressWrite ("STARTED");
                    }
                    break;
            case 'nocheck':
                    if ($argv[4] == 'PAUSE') {
                        parityTuningLoggerDebug ('...' . sprintf ('to pause %s', actionDescription($parityTuningAction, $parityTuningCorrecting)));
                        loadVars(5);         // give time for pause
                        parityTuningProgressWrite ("PAUSE");
                    } else {
                        // Not sure this even a possible operation but we allow for it anyway!
                        parityTuningProgressWrite ('CANCELLED');
                        parityTuningProgressAnalyze();
                    }
                    parityTuningDeleteFile($parityTuningPartialFile);
                    updateCronEntries();
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

		if (! isset($disks)) {
			parityTuningLoggerTesting('System appears to still be initialising - disk information not available');
			break;
		}

		// Handle monitoring of partial checks

		if (parityTuningPartial()) {
			$startSector = $parityTuningCfg['parityProblemStartSector'];
			$endSector   = $parityTuningCfg['parityProblemEndSector'];
			if ($parityTuningActive) {
				if ($parityTuningPos < $endSector) {
					parityTuningLoggerTesting ("Partial check: sector reached:$parityTuningPos, end sector:$endSector");
					break;
				}
				parityTuningLoggerTesting('Stop partial check');

				parityTuningProgressWrite('PARTIAL STOP');
				parityTuningProgressSave;					// Helps with debugging
				parityTuningDeleteFile ($parityTuningProgressFile);
				exec('mdcmd nocheck');
				loadVars(3);			// Need to confirm this is long enough!
			}
			suppressMonitorNotification();
			$runType = ($parityProblemCorrect == 0) ? _('Non-Correcting') : _('Correcting');
			sendNotification(_('Completed partial check ('). $runType . ')',
								parityTuningPartialRange() . ' ' . _('Errors') . ' ' . $parityTuningVar['sbSyncErrs'] );
			parityTuningDeleteFile ($parityTuningPartialFile);
			updateCronEntries();
			break;
		}

        // Check for disk temperature changes we are monitoring

        if ((!$parityTuningHeat) && (! $scfgSutdown)) {
            parityTuningLoggerDebug (_('Temperature monitoring switched off'));
            parityTuningDeleteFile ($parityTuningCriticalFile);
            break;
        }

        // Get temperature information

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
                				   . ($parityTuningShutdown ? ', ' . sprintf(_('shutdown %s'), $cfgCritical) : ''));
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
                						   . ($parityTuningShutdown ? sprintf(', critical=%s%s',$critical, $tempUnit) : ''). ')');
            }
        }

        // Handle at least 1 drive reaching shutdown threshold

        if ($parityTuningShutdown) {
			if (count($criticalDrives) > 0) {
				$drives=listDrives($criticalDrives);
				parityTuningLogger(_("Array being shutdown due to drive overheating"));
				file_put_contents ($parityTuningCriticalFile, "$drives\n");
				$msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
				if ($parityTuningActive) {
					parityTuningLoggerTesting('array operationis active');
					$msg .= '<br>' . _('Abandoned ') . actionDescription($parityTuningAction, $parityTuningCorrecting) . parityTuningCompleted();
				}
				sendNotification (_('Array shutdown'), $msg, 'alert');
				if ($parityTuningTesting) {
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

		if (! $parityTuningActive) {
			parityTuningLoggerDebug (_('No array operation currently in progress'));
			parityTuningDeleteFile($parityTuningScheduledFile);
			parityTuningProgressAnalyze();
			break;
		}
		if (! $parityTuningRunning) {
			parityTuningLoggerDebug (_('Parity check appears to be paused'));
		} elseif (! file_exists($parityTuningProgressFile)) {
			parityTuningProgressWrite ("UNSCHEDULED");
			parityTuningProgressWrite ("STARTED");
			parityTuningLoggerDebug ( _('Unscheduled array operation in progress'));
		}

		// Handle drives being paused/resumed due to temperature

        parityTuningLoggerDebug (sprintf('%s=%d, %s=%d, %s=%d, %s=%d', _('array drives'), $arrayCount, _('hot'), count($hotDrives), _('warm'), count($warmDrives), _('cool'), count($coolDrives)));
        if ($parityTuningRunning) {
        	// Check if we need to pause because at least one drive too hot
            if (count($hotDrives) == 0) {
                parityTuningLoggerDebug (sprintf('%s %s',actionDescription($parityTuningAction, $parityTuningCorrecting), _('with all drives below temperature threshold for a Pause')));
            } else {
                $drives = listDrives($hotDrives);
                file_put_contents ($parityTuningHotFile, "$drives\n");
                $msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
                parityTuningLogger (sprintf('%s %s %s: %s',_('Paused'), actionDescription($parityTuningAction, $parityTuningCorrecting), parityTuningCompleted(), $msg ));
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
                		parityTuningLogger (sprintf ('%s %s %s %s',_('Resumed'), actionDescription($parityTuningAction, $parityTuningCorrecting), parityTuningCompleted(), _('as drives now cooled down')));
                		parityTuningProgressWrite('RESUME (COOL)');
                		exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                		sendTempNotification(_('Resume'), _('Drives cooled down'));
                		parityTuningDeleteFile ($parityTuningHotFile);
                	}
				}
            }
        }
        break;

    // We now have cases that are likely to result in action needing taking against the array
    case 'resume':
        parityTuningLoggerDebug (_('Resume request'));
        if (isArrayOperationActive()) {
            if (parityTuningPartial()) {
				parityTuningLoggerTesting('Resume ignored as partial check in progress');
				break;
            }
            if (configuredAction()) {
                if ($parityTuningRunning) {
                    parityTuningLoggerDebug(sprintf('... %s %s', actionDescription($parityTuningAction, $parityTuningCorrecting), _('already running')));
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
            if (parityTuningPartial()) {
            	parityTuningLoggerTesting('Pause ignored as partial check in progress');
            	break;
            }
            if (configuredAction()) {
                if (! $parityTuningRunning) {
                    parityTuningLoggerDebug(sprintf('... %s %s!', actionDescription($parityTuningAction, $parityTuningCorrecting), _('already paused')));
                } else {
                	// TODO May want to create a 'paused' file to indicate reason for pause?

run_pause:			// can jump here after a restart
                    exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                    file_put_contents($parityTuningScheduledFile,"PAUSE");		// Set marker for shecduled pause
                    loadVars(5);
                    sendArrayNotification (isParityCheckActivePeriod() ? _('Scheduled pause') : _('Paused'));
                }
            }
        }
        break;

    case 'partial':
		parityTuningLoggerTesting("sectors $parityProblemStartSector-$parityProblemEndSector, correct $parityProblemCorrect");
		exec("touch $parityTuningPartialFile");			// Create file to indicate partial check
		updateCronEntries();
		$startSector = $parityProblemStartSector;
		$startSector -= ($startSector % 8);				// start Sector numbers must be multiples of 8
		$cmd = "mdcmd check " . ($parityProblemCorrect == 0 ? 'no' : '') . "correct " . $startSector;
		parityTuningLoggerTesting("cmd:$cmd");
		exec ($cmd);
		loadVars(5);
		suppressMonitorNotification();
		parityTuningProgressWrite('PARTIAL');
		$runType = ($parityProblemCorrect == 0) ? _('Non-Correcting') : _('Correcting');
		sendNotification(_("Partial parity check ($runType)"), parityTuningPartialRange());
    	break;

	// runs with 'md' devices valid and when array is about to be started
    case 'array_started':
    	suppressMonitorNotification();
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        if (file_exists($parityTuningTidyFile)) parityTuningLoggerTesting('tidy shutdown file present');
    	parityTuningLoggerDebug (_('Array has not yet been started'));
    	if (file_exists($parityTuningCriticalFile) && ($drives=file_get_contents($parityTuningCriticalFile))) {
			$reason = _('Detected shutdown that was due to following drives reaching critical temperature');
			parityTuningLogger ("$reason\n$drives");
		}
		parityTuningDeleteFile($parityTuningCriticalFile);

		if (file_exists($parityTuningHotFile) && $drives=file_get_contents($parityTuningHotFile)) {
			$reason = _('Detected that array had been paused due to following drives getting hot');
			parityTuningLogger ("$reason\n$drives");
	    }
		parityTuningDeleteFile($parityTuningHotFile);

    	if (!file_exists($parityTuningTidyFile)) {
    		parityTuningLoggerTesting(_("Tidy shutdown file not present in $command event"));
			parityTuningLogger(_('Unclean shutdown detected'));
			if (file_exists($parityTuningProgressFile)) {
			  if ($parityTuningRestartOK) {
			    sendNotification (_('Array operation will not be restarted'), _('Unclean shutdown detected'), 'alert');
				parityTuningProgressWrite('RESTART CANCELLED');
			  }
			}
			parityTuningProgressWrite('ABORTED');
			exec ("touch $parityTuningUncleanFile");		// set to indicate we detected unclean shutdown
			goto end_array_started;
    	}
    	break;

	// runs with when system startup complete and array is started
    case 'started':
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        parityTuningLoggerDebug (_('Array has just been started'));

		// Sanity Checks on restart that mean restart will not be possible if they fail
		// (not sure these are all really necessary - but better safe than sorry!)
		if (! file_exists($parityTuningProgressFile)) {
			if (file_exists($parityTuningRestartFile)) {
				parityTuningLogger(_('Something wrong: restart information present without progress present'));
		    }
		    goto end_array_started;
		}
		if (! $parityTuningRestartOK) {
			parityTuningLogger (_('Unraid version too old to support restart of array operations'));
			parityTuningProgressWrite('ABORTED');
			goto end_array_started;
		}
		if (! file_exists($parityTuningRestartFile)) {
			parityTuningLoggerTesting (_('No restart information present'));
			goto end_array_started;
		}
        if (! file_exists($parityTuningDisksFile)) {
        	parityTuningLogger(_('Something wrong: restart information present without desks file present'));
        	goto end_array_started;
        }

        // check if disk configuration has changed

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
		if ($disksOK) {
			parityTuningLoggerTesting ('Disk configuration appears to be unchanged');
		} else {
			parityTuningLogger (_('Detected a change to the disk configuration'));
			sendNotification (_('Array operation will not be restarted'), _('Detected a change to the disk configuration'), 'alert');
			goto end_array_started;
		}

        // Handle restarting array operations

		$restart = parse_ini_file($parityTuningRestartFile);
		parityTuningLoggerTesting('restart information:');
		foreach ($restart as $key => $value) parityTuningLoggerTesting("$key=$value");
		parityTuningLoggerTesting ('Mode at shutdown: ' . $restart['startMode'] . ', current mode: ' . $parityTuningVar['startMode']);
		if ($parityTuningVar['startMode'] != $restart['startMode']) {
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
		suppressMonitorNotification();
		parityTuningDeleteFile($parityTuningRestartFile);
		parityTuningLoggerTesting('restart command: ' . $cmd);
		exec ($cmd);
        loadVars(5);     // give time for any array operation to start running
        suppressMonitorNotification();
        parityTuningProgressWrite('RESTART');
        sendNotification(_('Array operation restarted'),  actionDescription($parityTuningAction, $parityTuningCorrecting) . parityTuningCompleted());
        parityTuningDeleteFile($parityTuningRestartFile);
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
 			parityTuningDeleteFile($parityTuningRestartFile);
 			parityTuningLoggerTesting(_("Deleted $parityTuningRestartFile"));
 			if (file_exists($parityTuningProgressFile)) {
 			    parityTuningProgressWrite('RESTART CANCELLED');
 			}
 		}
 		parityTuningDeleteFile($parityTuningDisksFile);
 		parityTuningDeleteFile($parityTuningTidyFile);
        parityTuningDeleteFile($parityTuningScheduledFile);
		if (file_exists($parityTuningUncleanFile)) {
			if ($parityTuningNoParity) {
				parityTuningLoggerTesting(_("No parity present, so no automatic parity check"));
			} else {
				parityTuningProgressAnalyze();
				sendNotification (_('Automatic Unraid parity check will be started'), _('Unclean shutdown detected'), 'warning');
			}
			parityTuningDeleteFile($parityTuningUncleanFile);
		} else {
			parityTuningLoggerTesting(_("does not appear to be an unclean shutdown"));
		}
		parityTuningProgressAnalyze();
		parityTuningDeleteFile($parityTuningUncleanFile);
		suppressMonitorNotification();
		break;

    case 'stopping':
        parityTuningLoggerDebug(_('Array stopping'));
        parityTuningDeleteFile($parityTuningRestartFile);
        if (!$parityTuningActive) {
            parityTuningLoggerDebug (_('no array operation in progress so no restart information saved'));
            parityTuningProgressAnalyze();

        } else {
			parityTuningLoggerDebug (sprintf(_('Array stopping while %s was in progress %s'), actionDescription($parityTuningAction, $parityTuningCorrecting), parityTuningCompleted()));
		    parityTuningProgressWrite('STOPPING');
			if ($parityTuningRestartOK) {
			    sendNotification(_('Array stopping: Restart will be attempted on next array start'), actionDescription($parityTuningAction, $parityTuningCorrecting) . parityTuningCompleted(),);
			    saveRestartInformation();
			} else {
				parityTuningLoggerDebug('Unraid version ' . $parityTuningUnraidVersion['version'] . ' too old to support restart');
			}
			suppressMonitorNotification();
        }
        break;

	case 'stopping_array':
    	exec ("touch $parityTuningTidyFile");
    	parityTuningLoggerDebug ("Created $parityTuningTidyFile to indicate tidy shutdown");
    	suppressMonitorNotification();
    	break;

    // Options that are only currently for CLI use

    case 'analyze':
        parityTuningProgressAnalyze();
        break;

    case 'status':
    	if (isArrayOperationActive()) parityTuningLogger(actionDescription($parityTuningAction, $parityTuningCorrecting) . ($parityTuningRunning ? '' : ' PAUSED ') .  parityTuningCompleted());
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
            parityTuningLogger(sprintf(_('Not allowed as %s already running'), actionDescription($parityTuningAction, $parityTuningCorrecting)));
            break;
        }
        $parityTuningCorrecting =($command == 'correct') ? true : false;
		exec("/usr/local/sbin/mdcmd check $command");
        loadVars(2);
	    parityTuningLogger(actionDescription($parityTuningAction, $parityTuningCorrecting) . ' Started');
        if ($parityTuningAction == 'check' && ( $command == 'correct')) {
            if ($parityTuningNoParity) {
            	parityTuningLogger(_('Only able to start a Read-Check as no parity drive present'));
            } else {
            	parityTuningLogger(_('Only able to start a Read-Check due to number of disabled drives'));
            }
        }
	    break;

    case 'cancel':
        parityTuningLoggerDebug(_('Cancel request'));
        if (isArrayOperationActive()) {
            parityTuningLoggerDebug ('mdResyncAction=' . $parityTuningAction);
			exec('/usr/local/sbin/mdcmd "nocheck"');
            parityTuningLoggerDebug (sprintf(_('%s cancel request sent %s'), actionDescription($parityTuningAction, $parityTuningCorrecting), parityTuningCompleted()));
            loadVars(5);
            parityTuningProgressWrite('CANCELLED');
            parityTuningLogger(sprintf(_('%s Cancelled'),actionDescription($parityTuningAction, $parityTuningCorrecting)));
            parityTuningProgressAnalyze();
        }

        break;

    case 'stop':
    case 'start':
        parityTuningLogger("$command " . _('option not currently implemented'));
        // fallthru to usage section


	// Potential Unraid event types on which no action is (currently) being taken by this plugin?
	// They are being caught at the moment so we can see when they actually occur.

	case 'stopped':
	case 'starting':
		suppressMonitorNotification();
	case 'driver_loaded':
	case 'disks_mounted':
	case 'svcs_restarted':
	case 'docker_started':
	case 'libvirt_started':
	case 'stopped':
    case 'stopping_svcs':
    case 'stopping_libvirt':
    case 'stopping_docker':
    case 'unmounting_disks':
        if (file_exists($parityTuningSyncFile)) parityTuningLoggerTesting('forcesync file present');
        if (file_exists($parityTuningTidyFile)) parityTuningLoggerTesting('tidy shutdown file present');
    	break;

    // Finally the error/usage case.   Hopefully we never get here in normal running when not using CLI
    case 'help':
    case '--help':
    default:
        parityTuningLogger ('');       // Blank line to help break up debug sequences
        parityTuningLogger (_('ERROR') . ': ' . sprintf(_('Unrecognised option %s'), $command));
        parityTuningLoggerCLI (_('Usage') . ': ' . basename($argv[0]) . ' <' . _('action') . '>');
		parityTuningLoggerCLI (_('where action is one of'));
		parityTuningLoggerCLI ('  pause            ' . _('Pause a running array operation'));
		parityTuningLoggerCLI ('  resume           ' . _('Resume a paused array operation'));
		parityTuningLoggerCLI ('  analyze          ' . _('Analyze results from an array operation'));
		parityTuningLoggerCLI ('  check            ' . _('Start a parity check with scheduled settings'));
		parityTuningLoggerCLI ('  correct          ' . _('Start a correcting parity check'));
		parityTuningLoggerCLI ('  nocorrect        ' . _('Start a non-correcting parity check'));
		parityTuningLoggerCLI ('  status           ' . _('Show the status of a running parity check'));
		parityTuningLoggerCLI ('  cancel           ' . _('Cancel a running parity check'));
//	    parityTuningLoggerCLI ('  partial          ' . _('Start partial parity check'));
		if (! $parityTuningCLI) {
        	parityTuningLogger (_('Command Line was') . ':');
        	$cmd = ''; for ($i = 0; $i < count($argv) ; $i++) $cmd .= $argv[$i] . ' ';
        	parityTuningLoggerDebug($cmd);
        	parityTuningProgressWrite('UNKNOWN');
        }
        break;

} // End of $command switch
spacerDebugLine(false, $command);
exit(0);


//       ~~~~~~~~~~~~~~~~~~~~~~
function saveRestartInformation() {
//       ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningActive, $parityTuningRestartOK, $parityTuningVar, $parityTuningRestartFile, $parityTuningDisksFile;
    parityTuningDeleteFile($parityTuningRestartFile);
    parityTuningDeleteFile($parityTuningDisksFile);

    if ($parityTuningActive && $parityTuningRestartOK) {
        $restart = 'mdResync=' . $parityTuningVar['mdResync'] . "\n"
				   .'mdResyncPos=' . $parityTuningVar['mdResyncPos'] . "\n"
				   .'mdResyncSize=' . $parityTuningVar['mdResyncSize'] . "\n"
				   .'mdResyncAction=' . $parityTuningVar['mdResyncAction'] . "\n"
				   .'mdResyncCorr=' . $parityTuningVar['mdResyncCorr'] . "\n"
			       .'startMode=' . $parityTuningVar['startMode'] . "\n";
		file_put_contents ($parityTuningRestartFile, $restart);
		parityTuningLoggerTesting(sprintf( _('Restart information saved to file %s'), $parityTuningRestartFile));
		copy ('/var/local/emhttp/disks.ini', $parityTuningDisksFile);
		parityTuningLoggerTesting(sprintf( _('Current disks information saved to file %s'), $parityTuningDisksFile));
	}
}

// Remove a file and if TESTING logging active then log it has happened

//       ~~~~~~~~~~~~~~~~~~~~~~
function parityTuningDeleteFile($name) {
//       ~~~~~~~~~~~~~~~~~~~~~~
 	if (file_exists($name)) {
		@unlink($name);
		parityTuningLoggerTesting(_("Deleted $name"));
	}
}

// Helps break debug information into blocks to identify entrie for a given entry point

//       ~~~~~~~~~~~~~~~
function spacerDebugLine($strt = true, $cmd) {
//       ~~~~~~~~~~~~~~~
    // Not sure if this should be active at DEBUG level of only at TESTING level?
    parityTuningLoggerTesting ('----------- ' . strtoupper($cmd) . (($strt == true) ? ' begin' : ' end') . ' ------');
}

//       ~~~~~~~~~~
function listDrives($drives) {
//       ~~~~~~~~~~
	global $tempUnit;
	$msg = '';
    foreach ($drives as $key => $value) {
        $msg .= $key . '(' . $value . $tempUnit . ') ';
    }
    return $msg;
}

// is an array operation in progress

//       ~~~~~~~~~~~~~~~~~~~~~~
function isArrayOperationActive() {
//       ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningPos, $parityTuningRestartFile;
	if (file_exists($parityTuningRestartFile)) {
	   parityTuningLoggerTesting ('Restart file found - so treat as isArrayOperationActive=false');
	   return false;
	}
	if ($parityTuningPos == 0) {
		if ($parityTuningCLI) {
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

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressAnalyze() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// Load some globals into local variables for efficiency & clarity
	$dateFormat   	 = $GLOBALS['parityTuningDateFormat'];
	$cfg          	 = $GLOBALS['parityTuningCfg'];
    $var		  	 = $GLOBALS['parityTuningVar'];
	$progressFile 	 = $GLOBALS['parityTuningProgressFile'];
	$restartFile  	 = $GLOBALS['parityTuningRestartFile'];
	$scheduledFile   = $GLOBALS['parityTuningScheduledFile'];
	$unscheduledFile = $GLOBALS['parityTuningUnscheduledFile'];

    // TODO: This may need revisiting if decided that partial checks should be recorded in History
    if (parityTuningPartial()) {
        parityTuningLoggerTesting(' ignoring progress file as was for partial check');
    	parityTuningDeleteFile($progressFile);
    }

    if (! file_exists($progressFile)) {
        parityTuningLoggerTesting(' no progress file to anaylse');
        return;
    }

    if (file_exists($restartFile)) {
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
    $lines = file($progressFile);

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
    if ((! startsWith($line,'COMPLETED')) && (!startsWith($line,'CANCELLED')) && (!startsWith($line,'ABORTED'))) {
        $endType = file_exists($GLOBALS['parityTuningUncleanFile']) ? 'ABORTED' : 'COMPLETED';
        parityTuningLoggerDebug("missing completion line in Progress file - add $end$YPE and restart analyze");
        parityTuningProgressWrite($endType);
        parityTuningProgressAnalyze();
        return;
    }
    $duration = $elapsed = $increments = $corrected = 0;
    $thisStart = $thisFinish = $thisElapsed = $thisDuration = $thisOffset = 0;
    $lastFinish = $exitCode = $firstSector = $reachedSector = 0;
    $mdResyncAction = '';
    foreach ($lines as $line) {
    	parityTuningLoggerTesting("$line");
        list($op,$stamp,$timestamp,$sbSynced,$sbSynced2,$sbSyncErrs, $sbSyncExit, $mdState,
             $mdResync, $mdResyncPos, $mdResyncSize, $mdResyncCorr, $mdResyncAction, $desc) = explode ('|',$line);
		// A progress file can have a time offset which we can determine by comaparing text and binary timestamps
		// (This will only be relevant when testing Progress files submitted as part of a problem report)
        if (! $increments) {
        	$temp = strtotime(substr($stamp, 9, 3) . substr($stamp,4,4) . substr($stamp,0,5) . substr($stamp,12));
			if ($temp) {		// ignore any heading line
				// parityTuningLoggerTesting ("Progress temp = $temp, timestamp=$timestamp");
				$thisOffset = $temp - $timestamp;  // This allows for diagnostic files from a different timezone when debugging
				if ($thisOffset != 0) parityTuningLoggerTesting ("Progress time offset = $thisOffset seconds");
			}
        }
        switch ($op) {
        	case 'SCHEDULED':
        			$scheduled = true;
        			break;
        	case 'UNSCHEDULED':
        	        $scheduled = false;
        	        break;
        	case 'UNKNOWN':
        	case 'type':    // TODO: This record type could probably be removed when debugging complete
        			break;

			CASE 'PARTIAL':
					$firstSector = $GLOBALS['parityTuningStartSector'];
					break;

            case 'STARTED': // TODO: Think can be ignored as only being documentation?
            case 'MANUAL':  // TODO: Think can be ignored as only being documentation?
            		if ($timestamp) $thisStart =  $thisFinish = $lastFinish = ($timestamp  + $thisOffset);
            		$increments = 1;		// Must be first increment!
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitCode=$exitCode");
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
                    						  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitCode=$exitCode");
                    break;

             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
			case 'CANCELLED':
            case 'PAUSE':
            case 'PAUSE (HOT)':
            case 'PAUSE (RESTART)':
            case 'COMPLETED':
            case 'STOPPING':
					if ($reachedSector != $mdResyncPos) {
						$sector = ($mdResyncPos == 0 ? $mdResyncSize : $mdResyncPos);	// position reverts to 0 at end
						parityTuningLoggerTesting("changing reachedSector from $reachedSector to $sector");
						$reachedSector = $sector;
            		}
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
                    $exitCode = $sbSyncExit;
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitCode=$exitCode");
                    break;

            case 'RESTART CANCELLED':
        	case 'ABORTED': // Indicates that a parity check has been aborted due to unclean shutdown,
        					// (or Unraid version too old) so ignore this record
					$exitCode = -5;
					goto END_PROGRESS_FOR_LOOP;
            // TODO:  Included for completeness although could possibly be removed when debugging complete?
            default :
                    parityTuningLoggerDebug ("unexpected progress record type: $op");
                    break;
        } // end switch
    }  // end foreach
END_PROGRESS_FOR_LOOP:

    if (file_exists($GLOBALS['parityTuningUncleanFile']) && ($exitCode != -5)) {
        parityTuningLoggerTesting ("exitCode forced to -5 for unclean shutdown");
    	$exitCode = -5;		// Force error code for history
    }
    switch ($exitCode) {
    	case 0:  $exitStatus = _("finished");
    			 break;
        case -4: $exitStatus = _("cancelled");
                 break;
        case -5: $exitStatus = _("aborted");
        		 break;
        default: $exitStatus = _("exit code") . ": " . $exitCode;
        		 break;
    }
    $completed = ' ' . ($mdResyncSize ? sprintf ("%.1f%%", ($reachedSector - $firstSector/$mdResyncSize*100)) : '0') . _(' completed');
	parityTuningLoggerTesting("ProgressFile start:" . date($dateFormat,$thisStart) . ", finish:" . date($dateFormat,$thisFinish) . ", $exitStatus, $completed");
	$unit='';
	$speed = my_scale(($reachedSector * (1024 / $duration)), $unit,1);
	$speed .= "$unit/s";
	parityTuningLoggerTesting("totalSectors: $mdResyncSize, duration: $duration, speed: $speed");

	parityTuningProgressSave();					// Helps witl debugging
    parityTuningDeleteFile($progressFile);		// finished with Progress file so remove it

    if (! startsWith($mdResyncAction,'check')) {
        parityTuningLoggerDebug('array action was not Parity Check - it was ', actionDescription($mdResyncAction, $mdResyncCorr));
        parityTuningLoggerDebug('... so update to parity check history not appropriate');
        parityTuningDeleteFile($scheduledFile);   // should not exist but lets play safe!
        return;
    }
    if (! file_exists($scheduledFile)) {
        if (! $cfg['parityTuningUnscheduled']) {
            parityTuningLoggerDebug ('appears that pause/resume not activated for' .
                                    (startsWith($mdResyncAction,'check') ? ' manual ' : ' ')
                                    . actionDescription($mdResyncAction, $mdResyncCorr));
            parityTuningLoggerDebug ('... so do not attempt to update system parity-check.log file');
            return;
        } else {
            parityTuningLoggerDebug ('appears that pause/resume was activated for' .
                                    (startsWith($mdResyncAction,'check') ? ' manual ' : ' ')
                                    . actionDescription($mdResyncAction, $mdResyncCorr));
        }
    }
    parityTuningDeleteFile($scheduledFile);

	if ($increments == 0) {
    	parityTuningLoggerTesting('no increments found so no need to patch history file');
    } else if ($exitCode == -5) {
    	parityTuningLoggerTesting('aborted so no need to patch history file');
    } else {
		// Now we want to patch the entry in the standard parity log file
		suppressMonitorNotification();
		$parityLogFile = '/boot/config/parity-checks.log';
		$lines = file($parityLogFile, FILE_SKIP_EMPTY_LINES);
		$matchLine = 0;
		while ($matchLine < count($lines)) {
			$line = $lines[$matchLine];
			list($logstamp,$logduration, $logspeed,$logexit, $logerrors) = explode('|',$line);
			$logtime = strtotime(substr($logstamp, 9, 3) . substr($logstamp,4,4) . substr($logstamp,0,5) . substr($logstamp,12));
			// parityTuningLoggerTesting('history line ' . ($matchLine+1) . " $logstamp, logtime=$logtime=" . date($dateFormat,$logtime));
			if ($logtime > $thisStart) {
				parityTuningLoggerTesting ("looks like line " . ($matchLine +1) . " is the one to update, logtime=$logtime  . " . date($dateFormat,$logtime) . ')');
				parityTuningLoggerTesting ($line);
				if ($logtime <= $thisFinish) {
					parityTuningLoggerDebug ('update log entry on line ' . ($matchLine+1),", errors=$logerrors");
					$lastFinish = $logtime;
					$exitCode = $logexit;
					if ($logerrors > $corrected) $corrected = $logerrors;
					break;
				} else {
					parityTuningLoggerTesting ("... but logtime = $logtime ("
											. date($dateFormat,$logtime)
											. "), lastFinish = $lastFinish ("
											. date($dateFormat,$lastfinish)
											. "), thisFinish=$thisFinish ("
											. date($dateFormat,$thisFinish) . ')');
				}
			}
			$matchLine++;
		}
		if ($matchLine == count($lines))  parityTuningLoggerDebug('no match found in existing log so added a new record ' . ($matchLine + 1));
		$type = explode(' ',$desc);
		$gendate = date($dateFormat,$lastFinish);
		if ($gendate[9] == '0') $gendate[9] = ' ';  // change leading 0 to leading space

		$generatedRecord = "$gendate|$duration|$speed|$exitCode|$corrected|$elapsed|$increments|$type[0]\n";
		parityTuningLoggerDebug("log record generated from progress: $generatedRecord");    $lines[$matchLine] = $generatedRecord;
		$myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
		file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);  // Save for debug purposes
		file_put_contents($parityLogFile,$lines);
		// send Notification about data written to history file
		$msg  = sprintf(_('%s %s (%d errors)'),
						actionDescription($mdResyncAction, $mdResyncCorr), $exitStatus, $corrected);
		$desc = sprintf(_('%s %s, %s %s, %s %d, %s %s'),
						 			_('Elapsed Time'),his_duration($elapsed),
									_('Runtime'), his_duration($duration),
									_('Increments'), $increments,
									_('Average Speed'),$speed);
		sendNotification($msg, $desc, ($exitCode == 0 ? 'normal' : 'warning'));
	}
	spacerDebugLine(false, 'ANALYSE PROGRESS');
}

// /following 2 functions copied from parity history script

//       ~~~~~~~~
function his_plus($val, $word, $last) {
//       ~~~~~~~~
  return $val>0 ? (($val||$last)?($val.' '.$word.($last?'':', ')):'') : '';
}

//       ~~~~~~~~~~~~
function his_duration($time) {
//       ~~~~~~~~~~~~
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

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressWrite($msg) {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
    $var            = $GLOBALS['parityTuningVar'];
    $progressFile   = $GLOBALS['parityTuningProgressFile'];

    // List of fields we save for progress.
	// Might not all be needed but better to have more information than necessary
	$progressFields = array('sbSynced','sbSynced2','sbSyncErrs','sbSyncExit',
	                       'mdState','mdResync','mdResyncPos','mdResyncSize','mdResyncCorr','mdResyncAction' );

    // Not strictly needed to have header but a useful reminder of the fields saved
    $line='';
    if (! file_exists($progressFile)) {
        $line .= 'type|date|time|';
        foreach ($progressFields as $name) $line .= $name . '|';
        $line .= "Description\n";
    }
    $line .= $msg . '|' . date($GLOBALS['$parityTuningDateFormat']) . '|' . time() . '|';
    foreach ($progressFields as $name) $line .= $var[$name] . '|';
    $line .= actionDescription($GLOBALS['parityTuningAction'], $GLOBALS['parityTuningCorrecting']) . "|\n";
    file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);
    parityTuningLoggerTesting ('written ' . $msg . ' record to  ' . $progressFile);
}

// Keep a copy of the most recent progress file.
// This will help with debugging any problem reports

//		 ~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressSave() {
//		 ~~~~~~~~~~~~~~~~~~~~~~~~
	$progressfile = $GLOBALS['$parityTuningProgressfile'];
	if (file_exists($progressFile) {
		rename ($progressFile, "$progressFile.save");
	    parityTuningDeleteFile("$progressfile.save");
    	parityTuningLoggerDebug("Old progress file available as $progressFile.save");
    }
}

// send a notification without checking if enabled in plugin settings
// (assuming even enabled at the system level)

//       ~~~~~~~~~~~~~~~~
function sendNotification($msg, $desc = '', $type = 'normal') {
//       ~~~~~~~~~~~~~~~~
    parityTuningLoggerTesting (_('Send notification') . ': ' . $msg . ': ' . $desc);
    if ($GLOBALS['dynamixCfg']['notify']['system'] == "" ) {
    	parityTuningLoggerTesting (_('... but suppressed as system notifications do not appear to be enabled'));
    } else {
        $cmd = $GLOBALS['parityTuningNotify']
        	 . ' -e ' . escapeshellarg(parityTuningPartial() ? "Parity Problem Assistant" : "Parity Check Tuning")
        	 . ' -i ' . escapeshellarg($type)
	    	 . ($GLOBALS['parityTuningRestartOK']
	    	 							? ' -l ' . escapeshellarg("/Settings/Scheduler") : '')
	    	 . ' -s ' . escapeshellarg('[' . $GLOBALS['parityTuningServer'] . "] $msg")
	         . ($desc == '' ? '' : ' -d ' . escapeshellarg($desc));
    	parityTuningLoggerTesting (_('... using ') . $cmd);
    	exec ($cmd);
    }
}

// send a notification without checking if enabled.  Always add point reached.

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
function sendNotificationWithCompletion($op, $desc = '', $type = 'normal') {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
    sendNotification ($op, $desc .  (strlen($desc) > 0 ? '<br>' : '') . actionDescription($GLOBALS['parityTuningAction'], $GLOBALS['parityTuningCorrecting'])
    							 			. parityTuningCompleted(), $type);
}

// Send a notification if increment notifications enabled

//       ~~~~~~~~~~~~~~~~~~~~~
function sendArrayNotification ($op) {
//       ~~~~~~~~~~~~~~~~~~~~~
    parityTuningLoggerTesting("Pause/Resume notification message: $op");
    if ($GLOBALS['parityTuningCfg']['parityTuningNotify'] == '0') {
        parityTuningLoggerDebug('... Array notifications disabled so ' . $op . ' message not sent');
        return;
    }
    sendNotificationWithCompletion($op);
}

// Send a notification if temperature related notifications enabled

//       ~~~~~~~~~~~~~~~~~~~~
function sendTempNotification ($op, $desc, $type = 'normal') {
//       ~~~~~~~~~~~~~~~~~~~~
    parityTuningLoggerTesting("Heat notification message: $op: $desc");
    if ($GLOBALS['parityTuningCfg']['parityTuningHeatNotify'] == 'no') {
        parityTuningLoggerTesting('... Heat notifications disabled so not sent');
        return;
    }
    sendNotificationWithCompletion($op, $desc, $type);
}

// Suppress notifications about array operations from monitor task
// (dupicate processing from task but without notification specific steps)
// Should also stop monitor from adding parity history entries
// TODO: Check for each Unraid release that there are not version dependent changes

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function suppressMonitorNotification() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
  $var    = $GLOBALS['parityTuningVar'];
  $ram    = "/var/local/emhttp/monitor.ini";
  $rom    = "/boot/config/plugins/dynamix/monitor.ini";
  $saved  = @parse_ini_file($ram,true);
  $item = 'array';
  $name = 'parity';
  $last = $saved[$item][$name] ?? '';
  if ($var['mdResyncPos']) {
    if (!$last) {
      if (strstr($var['mdResyncAction'],"recon")) {
        $last = 'Parity sync / Data rebuild';
      } elseif (strstr($var['mdResyncAction'],"clear")) {
        $last = 'Disk clear';
      } elseif ($var['mdResyncAction']=="check") {
        $last = 'Read check';
      } elseif (strstr($var['mdResyncAction'],"check")) {
        $last = 'Parity check';
      }
      $saved[$item][$name] = $last;
    }
    parityTuningLoggerTesting (_("suppressed builtin $last started notification"));
  } else {
    if ($last) {
      parityTuningLoggerTesting (_("suppressed builtin $last finished notification"));
      unset($saved[$item][$name]);
    }
  }

  // save new status
  if ($saved) {
    $text = '';
    foreach ($saved as $item => $block) {
      if ($block) $text .= "[$item]\n";
      foreach ($block as $key => $value) $text .= "$key=\"$value\"\n";
    }
    if ($text) {
      if ($text != @file_get_contents($ram)) file_put_contents($ram, $text);
      if (!file_exists($rom) || exec("diff -q $ram $rom")) file_put_contents($rom, $text);
    } else {
      @unlink($ram);
      @unlink($rom);
    }
  }
}

//	test if partial parity check in progress
//       ~~~~~~~~~~~~~~~~~~~
function parityTuningPartial() {
//       ~~~~~~~~~~~~~~~~~~~
	return file_exists($GLOBALS['parityTuningPartialFile']);
}

//  give the range for a partial parity check (in sectors or percent as appropriate)
//       ~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningPartialRange() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~
	if ($GLOBALS['parityProblemType'] == "sector") {
		$range = _('Sectors') . ' ' . $GLOBALS['parityProblemStartSector'] . '-' .  $GLOBALS['parityProblemEndSector'];
	} else {
		$range = $GLOBALS['parityProblemStartPercent'] . '%-' . $GLOBALS['parityProblemEndPercent'] . '%';
	}
	return _('Range') . ': ' . $range;
}

//       ~~~~~~~~~~~~~~~~~~~~~
function parityTuningCompleted() {
//       ~~~~~~~~~~~~~~~~~~~~~
    return ' ('
    	   . (($GLOBALS['parityTuningSize'] > 0)
    				? sprintf ("%.1f%%", ($GLOBALS['parityTuningPos']/$GLOBALS['parityTuningSize']*100)) : '0' )
    	   . '% ' .  _('completed') . ')';
}

// Confirm that action is valid according to user settings

//       ~~~~~~~~~~~~~~~~
function configuredAction() {
//       ~~~~~~~~~~~~~~~~
	$action     = $GLOBALS['parityTuningAction'];
	$correcting = $GLOBALS['parityTuningCorrecting'];

    if (startsWith($action,'recon') && $GLOBALS['parityTuningRecon']) {
        parityTuningLoggerTesting('...configured action for ' . actionDescription($action, $correcting));
        return true;
    }
    if (startsWith($action,'clear') && GLOBALS['parityTuningClear']) {
        parityTuningLoggerTesting('...configured action for ' . actionDescription($action, $correcting));
        return true;
    }
    if (startsWith($action,'check')) {
        if (file_exists(GLOBALS['parityTuningScheduledFile'])) {
            parityTuningLoggerTesting('...configured action for scheduled ' . actionDescription($action, $correcting));
            return true;
        }
        if (GLOBALS['parityTuningUnscheduled']) {
            parityTuningLoggerTesting('...configured action for unscheduled ' . actionDescription($action, $correcting));
            return true;
        }
    }
    parityTuningLoggerDebug('...action not configured for'
                            . (startsWith($action,'check') ? ' manual ' : ' ')
                            . actionDescription($action,$correcting). ' (' . $action . ')');
    return false;
}


// Determine if the current time is within a period where we expect this plugin to be active
// TODO: Work out an answer for custom schedules

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function isParityCheckActivePeriod() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
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

//       ~~~~~~~~~~~~~~~~~
function updateCronEntries() {
//       ~~~~~~~~~~~~~~~~~
	// Load some globals for effeciency & clarity
	$phpFile	= $GLOBALS['parityTuningPhpFile'];
	$cronFile	= $GLOBALS['parityTuningCronFile'];

	parityTuningDeleteFile ($cronFile);
	$lines = [];
	$lines[] = "\n# Generated schedules for " . $GLOBALS['parityTuningPlugin'] . "\n";

	if (parityTuningPartial()) {
		// Monitor every minutes during partial checks
		$frequency = "*/1";
		parityTuningLoggerDebug (_('created cron entry for monitoring partial parity checks'));
	} else {
		if ($GLOBALS['parityTuningIncrements'] && (! parityTuningPartial())) {
			if ($GLOBALS['parityTuningFrequency']) {
				$resumetime = $GLOBALS['parityTuningResumeCustom'];
				$pausetime  = $GLOBALS['parityTuningPauseCustom'];
			} else {
				$resumetime = $GLOBALS['parityTuningResumeMinute'] . ' '
							. $GLOBALS['parityTuningResumeHour'] . ' * * *';
				$pausetime  = $GLOBALS['parityTuningPauseMinute'] . ' '
							. $GLOBALS['parityTuningPauseHour'] . ' * * *';
			}
			$lines[] = "$resumetime $phpFile \"resume\" &> /dev/null\n";
			$lines[] = "$pausetime $phpFile \"pause\" &> /dev/null\n";
			parityTuningLoggerDebug (_('created cron entry for scheduled pause and resume'));
		}
		if ($GLOBALS['parityTuningHeat'] || $GLOBALS['parityTuningShutdown']) {
			// Monitor every 7 minutes for temperature
			$frequency = "*/7";
			parityTuningLoggerDebug (_('created cron entry for monitoring disk temperatures'));
		} else {
			// Once an hour if not monitoring more frequently for temperature
			$frequency = "17";
			parityTuningLoggerDebug (_('created cron entry for default monitoring '));
		}
	}
	$lines[] = "$frequency * * * * $phpFile \"monitor\" &>/dev/null\n";
	file_put_contents($cronFile, $lines);
	parityTuningLoggerTesting(sprintf(_('updated cron settings are in %s'),$cronFile));
	// Activate any changes
	exec("/usr/local/sbin/update_cron");
}


?>
