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

// error_reporting(E_ALL);		 // This option shiuld only be enabled for testing purposes

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

// Some useful constants local to this file
define('PARITY_TUNING_CRON_FILE',      PARITY_TUNING_FILE_PREFIX . 'cron');	    // File created to hold current cron settings for this plugin
define('PARITY_TUNING_PROGRESS_FILE',  PARITY_TUNING_FILE_PREFIX . 'progress'); // Created when array operation active to hold increment info
define('PARITY_TUNING_SCHEDULED_FILE', PARITY_TUNING_FILE_PREFIX . 'scheduled');// Created when we detect an array operation started by cron
define('PARITY_TUNING_MANUAL_FILE',    PARITY_TUNING_FILE_PREFIX . 'manual');   // Created when we detect an array operation started manually
define('PARITY_TUNING_AUTOMATIC_FILE', PARITY_TUNING_FILE_PREFIX . 'automatic');// Created when we detect an array operation started automatically after unclean shutdown
define('PARITY_TUNING_HOT_FILE',       PARITY_TUNING_FILE_PREFIX . 'hot');	    // Created when paused because at least one drive fount do have reached 'hot' temperature
define('PARITY_TUNING_CRITICAL_FILE',  PARITY_TUNING_FILE_PREFIX . 'critical'); // Created when parused besause at least one drive found to reach critical temperature
define('PARITY_TUNING_RESTART_FILE',   PARITY_TUNING_FILE_PREFIX . 'restart');  // Created if arry stopped with array operation active to hold restart info
define('PARITY_TUNING_DISKS_FILE',     PARITY_TUNING_FILE_PREFIX . 'disks');    // Copy of disks.ini when restart info sved to check disk configuration
define('PARITY_TUNING_TIDY_FILE',      PARITY_TUNING_FILE_PREFIX . 'tidy');	    // Create when we think there was a tidy shutdown
define('PARITY_TUNING_UNCLEAN_FILE',   PARITY_TUNING_FILE_PREFIX . 'unclean');  // Create when we think unclean shutdown forces a parity chack being abandoned
define('PARITY_TUNING_SYNC_FILE',      '/boot/config/forcesync');		        // Presence of file used by Unraid to detect unclean Shutdown (we currently ignore)

define('PARITY_TUNING_DATE_FORMAT', 'Y M d H:i:s');

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

        reportStatusFiles();
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
						if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
							parityTuningLoggerTesting('analyze previous progress before starting new one');
							parityTuningProgressAnalyze();
                        }
                        parityTuningInactiveCleanup();
                        // Work out what type of trigger
                        if ($argv[2] == 'crond') {
							parityTuningLoggerDebug ('... ' . _('appears to be a regular scheduled check'));
							createMarkerFile(PARITY_TUNING_SCHEDULED_FILE);
							parityTuningProgressWrite ('SCHEDULED');
						} else {
							parityTuningLoggerDebug ('... ' . _('appears to be a manual check'));
							createMarkerFile(PARITY_TUNING_MANUAL_FILE);
							parityTuningProgressWrite ('MANUAL');
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
                        parityTuningInactiveCleanup();
                    }
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
        // This is also the place where we can detect manual checks have been started.
        // TODO: Check out if fe correctly detect automatic acti/ns such as a parity check after ans
        //		 unclean shutdown or non xcheck operatiNs such as Disk Clear znd parity sync/disk rebuild
        //
        // The monitor frequency varies according to whether temperatures are being checked
        // or partial parity checks zre active as then we do it more often.

		reportStatusFiles();
        if (! isArrayOperationActive()) {
        	parityTuningLoggerTesting('Monitor ignored as no array operation in progress');
        	break;
        }
		if (! isset($disks)) {
			parityTuningLoggerTesting('System appears to still be initialising - disk information not available');
			break;
		}

		// Handle monitoring of partial checks

		if (parityTuningPartial()) {
			if ($parityTuningActive) {
				if ($parityTuningPos < $parityProblemEndSector) {
					parityTuningLoggerTesting ("Partial check: sector reached:$parityTuningPos, end sector:$parityProblemEndSector");
					break;
				}
				parityTuningLoggerTesting('Stop partial check');

				parityTuningProgressWrite('PARTIAL STOP');
				parityTuningProgressSave;					// Helps with debugging
				parityTuningDeleteFile (PARITY_TUNING_PROGRESS_FILE);
				exec('mdcmd nocheck');
				loadVars(3);			// Need to confirm this is long enough!
			}
			suppressMonitorNotification();
			$runType = ($parityProblemCorrect == 0) ? _('Non-Correcting') : _('Correcting');
			sendNotification(_('Completed partial check ('). $runType . ')',
								parityTuningPartialRange() . ' ' . _('Errors') . ' ' . $parityTuningVar['sbSyncErrs'] );
			parityTuningDeleteFile (PARITY_TUNING_PARTIAL_FILE);
			updateCronEntries();
			break;
		}

		// See if array operation in progress so that we need to consider pause/resume

		if (! $parityTuningActive) {
			parityTuningLoggerTesting (_('No array operation currently in progress'));
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
			break;
		}

		if (! $parityTuningRunning) {
			parityTuningLoggerDebug (_('Parity check appears to be paused'));
		} elseif (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			// If we have not caught the start then assume an automatic array operation
			if (!file_exists(PARITY_TUNING_SCHEDULED_FILE)
			&&  !file_exists(PARITY_TUNING_AUTOMATIC_FILE)) {
					parityTuningLoggerDebug ('... ' . _('appears to be a manual array operation'));
					parityTuningProgressWrite('MANUAL');
					createMarkerFile (PARITY_TUNING_MANUAL_FILE);
			}
			$trigger = operationTriggerType();
			parityTuningLoggerDebug ($trigger . ' ' . actionDescription($parityTuningAction, $parityTuningCorrecting));
			parityTuningProgressWrite (strtoupper($trigger));
			parityTuningProgressWrite ("STARTED");		// TODO: check if this is superflous?
		}




        // Check for disk temperature changes we are monitoring

        if ((!$parityTuningHeat) && (! $parityTuningHeatShutdown)) {
            parityTuningLoggerTesting (_('Temperature monitoring switched off'));
            parityTuningDeleteFile (PARITY_TUNING_CRITICAL_FILE);
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
        parityTuningLoggerTesting (sprintf(_('plugin temperature settings: pause %s, resume %s'),$parityTuningHeatHigh, $parityTuningHeatLow)
                				   . ($parityTuningShutdown ? ', ' . sprintf(_('shutdown %s'), $parityTuningHeatCritical) : ''));
        foreach ($disks as $drive) {
            $name=$drive['name'];
            $temp = $drive['temp'];
            if ((!startsWith($drive['status'],'DISK_NP')) & ($name != 'flash')) {
                $driveCount++;
                $critical  = ($drive['maxTemp'] ?? $dynamixCfg['display']['max']) - $parityTuningHeatCritical;
                $hot  = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningHeatHigh;
                $cool = ($drive['hotTemp'] ?? $dynamixCfg['display']['hot']) - $parityTuningHeatLow;

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
					parityTuningLoggerTesting(sprintf('Drive %s%s appears to be critical', tempInDisplayUnit($temp), $parityTuningTempUnit));
					$criticalDrives[$name] = $temp;
					$status = 'critical';
				}
                parityTuningLoggerTesting (sprintf('%s temp=%s%s, status=%s (drive settings: hot=%s%s, cool=%s%s',$name,
                							tempInDisplayUnit($temp), $parityTuningTempUnit, $status,
                							tempInDisplayUnit($hot), $parityTuningTempUnit,
                							tempInDisplayUnit($cool), $parityTuningTempUnit)
                						   . ($parityTuningShutdown ? sprintf(', critical=%s%s',tempInDisplayUnit($critical), $parityTuningTempUnit) : ''). ')');
            }
        }

        // Handle at least 1 drive reaching shutdown threshold

        if ($parityTuningShutdown) {
			if (count($criticalDrives) > 0) {
				$drives=listDrives($criticalDrives);
				parityTuningLogger(_("Array being shutdown due to drive overheating"));
				file_put_contents (PARITY_TUNING_CRITICAL_FILE, "$drives\n");
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

		// Handle drives being paused/resumed due to temperature

        parityTuningLoggerDebug (sprintf('%s=%d, %s=%d, %s=%d, %s=%d', _('array drives'), $arrayCount, _('hot'), count($hotDrives), _('warm'), count($warmDrives), _('cool'), count($coolDrives)));
        if ($parityTuningRunning) {
        	// Check if we need to pause because at least one drive too hot
            if (count($hotDrives) == 0) {
                parityTuningLoggerDebug (sprintf('%s %s',actionDescription($parityTuningAction, $parityTuningCorrecting), _('with all drives below temperature threshold for a Pause')));
            } else {
                $drives = listDrives($hotDrives);
                file_put_contents (PARITY_TUNING_HOT_FILE, "$drives\n");
                $msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
                parityTuningLogger (sprintf('%s %s %s: %s',_('Paused'), actionDescription($parityTuningAction, $parityTuningCorrecting), parityTuningCompleted(), $msg ));
                parityTuningProgressWrite('PAUSE (HOT)');
                exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                sendTempNotification(_('Pause'),$msg);
            }
        } else {

        	// Check if we need to resume because drives cooled sufficiently

            if (! file_exists(PARITY_TUNING_HOT_FILE)) {
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
                		parityTuningDeleteFile (PARITY_TUNING_HOT_FILE);
                	}
				}
            }
        }
        break;

    // We now have cases that are likely to result in action needing taking against the array
    case 'resume':
        parityTuningLoggerDebug (_('Resume request'));
        reportStatusFiles();
        if (! isArrayOperationActive()) {
        	parityTuningLoggerTesting('Resume ignored as no array operation in progress');
        	break;
        }
		if (parityTuningPartial()) {
			parityTuningLoggerTesting('Resume ignored as partial check in progress');
			break;
		}
		manualIfUnknown();
		if ($parityTuningRunning) {
			parityTuningLoggerDebug(sprintf('... %s %s', actionDescription($parityTuningAction, $parityTuningCorrecting), _('already running')));
			if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) parityTuningProgressWrite('MANUAL');
			break;
		}
		if (configuredAction()) {
			parityTuningProgressWrite('RESUME');
			sendArrayNotification(isParityCheckActivePeriod() ? _('Scheduled resume') : _('Resumed'));
			exec('/usr/local/sbin/mdcmd "check" "RESUME"');
		}
        break;

    case 'pause':
        parityTuningLoggerDebug(_('Pause request'));
        reportStatusFiles();
        if (! isArrayOperationActive()) {
            parityTuningLoggerTesting('Pause ignored as no array operation in progress');
            break;
        }
		if (parityTuningPartial()) {
			parityTuningLoggerTesting('Pause ignored as partial check in progress');
			break;
		}
		if (! $parityTuningRunning) {
			parityTuningLoggerDebug(sprintf('... %s %s!', actionDescription($parityTuningAction, $parityTuningCorrecting), _('already paused')));
			break;
		}
		manualIfUnknown();
		if (configuredAction()) {
				// TODO May want to create a 'paused' file to indicate reason for pause?
RUN_PAUSE:	// Can jump here after doing a restart
			parityTuningProgressWrite('PAUSE');
			exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
			loadVars(5);
			parityTuningLoggerTesting("Errors so far:  $parityTuningErrors");
			sendArrayNotification ((isParityCheckActivePeriod() ? _('Scheduled pause') : _('Paused')) . ($parityTuningErrors > 0 ? "$parityTuningErrors " . _('errors') . ')' : ''));
		}
        break;

    case 'partial':
        reportStatusFiles();
		parityTuningLoggerTesting("sectors $parityProblemStartSector-$parityProblemEndSector, correct $parityProblemCorrect");
		createMarkerFile(PARITY_TUNING_PARTIAL_FILE);			// Create file to indicate partial check
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
        reportStatusFiles();
    	suppressMonitorNotification();
        if (file_exists(PARITY_TUNING_SYNC_FILE)) parityTuningLoggerTesting('forcesync file present');
        if (file_exists(PARITY_TUNING_TIDY_FILE)) parityTuningLoggerTesting('tidy shutdown file present');
    	parityTuningLoggerDebug (_('Array has not yet been started'));
    	if (file_exists(PARITY_TUNING_CRITICAL_FILE) && ($drives=file_get_contents(PARITY_TUNING_CRITICAL_FILE))) {
			$reason = _('Detected shutdown that was due to following drives reaching critical temperature');
			parityTuningLogger ("$reason\n$drives");
		}
		parityTuningDeleteFile(PARITY_TUNING_CRITICAL_FILE);

		if (file_exists(PARITY_TUNING_HOT_FILE) && $drives=file_get_contents(PARITY_TUNING_HOT_FILE)) {
			$reason = _('Detected that array had been paused due to following drives getting hot');
			parityTuningLogger ("$reason\n$drives");
	    }
		parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);

    	if (!file_exists(PARITY_TUNING_TIDY_FILE)) {
    		parityTuningLoggerTesting(_("Tidy shutdown file not present in $command event"));
			parityTuningLogger(_('Unclean shutdown detected'));
			suppressMonitorNotification();
			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			  if ($parityTuningRestartOK) {
			    sendNotification (_('Array operation will not be restarted'), _('Unclean shutdown detected'), 'alert');
				parityTuningProgressWrite('RESTART CANCELLED');
			  }
			}
			parityTuningProgressWrite('ABORTED');
			createMarkerFile(PARITY_TUNING_PROGRESS_FILE);		// set to indicate we detected unclean shutdown
			// goto end_array_started;
    	}
    	break;

	// runs with when system startup complete and array is fully started
    case 'started':
        parityTuningLoggerDebug (_('Array has just been started'));
		reportStatusFiles();

		// Sanity Checks on restart that mean restart will not be possible if they fail
		// (not sure these are all really necessary - but better safe than sorry!)
		if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			if (file_exists(PARITY_TUNING_RESTART_FILE)) {
				parityTuningLogger(_('Something wrong: restart information present without progress present'));
		    }
		    goto end_array_started;
		}
		if (! $parityTuningRestartOK) {
			parityTuningLogger (_('Unraid version too old to support restart of array operations'));
			parityTuningProgressWrite('ABORTED');
			goto end_array_started;
		}
		if (! file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLoggerTesting (_('No restart information present'));
			goto end_array_started;
		}
        if (! file_exists(PARITY_TUNING_DISKS_FILE)) {
        	parityTuningLogger(_('Something wrong: restart information present without desks file present'));
        	goto end_array_started;
        }

        // check if disk configuration has changed

		$disksCurrent = parse_ini_file ('/var/local/emhttp/disks.ini', true);
		$disksOld     = parse_ini_file (PARITY_TUNING_DISKS_FILE, true);
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

		$restart = parse_ini_file(PARITY_TUNING_RESTART_FILE);
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
		parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
		parityTuningLoggerTesting('restart command: ' . $cmd);
		exec ($cmd);
        loadVars(5);     // give time for any array operation to start running
        suppressMonitorNotification();
        parityTuningProgressWrite('RESTART');
        sendNotification(_('Array operation restarted'),  actionDescription($parityTuningAction, $parityTuningCorrecting) . parityTuningCompleted());
        parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
        if ($restart['mdResync'] == 0) {
           parityTuningLoggerTesting(_('Array operation was paused'));
        }
        if (file_exists(PARITY_TUNING_SCHEDULED_FILE)) {
        	parityTuningLoggerTesting ('Appears that scheduled pause/resume was active when array stopped');
            if (! isParityCheckActivePeriod()) {
				parityTuningLoggerDebug(_('Outside time slot for running scheduled parity checks'));
				loadVars(15);		// allow time for Unraid standard notificaton to be output before attempting pause (monitor runs every minute)
           		goto RUN_PAUSE;
           	}
        }
		// FALL-THRU
end_array_started:
  		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
  			parityTuningLogger(_('Restart will not be attempted'));
 			parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
 			parityTuningLoggerTesting(_("Deleted PARITY_TUNING_RESTART_FILE"));
 			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
 			    parityTuningProgressWrite('RESTART CANCELLED');
 			}
 		}
 		parityTuningDeleteFile(PARITY_TUNING_DISKS_FILE);
 		parityTuningDeleteFile(PARITY_TUNING_TIDY_FILE);
        parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
		if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			parityTuningLoggerTesting(_("appears to be an unclean shutdown"));
			if ($parityTuningNoParity) {
				parityTuningLoggerTesting(_("No parity present, so no automatic parity check"));
			} else {
				parityTuningProgressAnalyze();
				createMarkerFile (PARITY_TUNING_AUTOMATIC_FILE);
				sendNotification (_('Automatic Unraid parity check will be started'), _('Unclean shutdown detected'), 'warning');
				loadVars(5);
				suppressMonitorNotification();

				if (! $parityTuningAutomatic) {
					parityTuningLoggerTesting(_('Automatic parity check pause not configured'));
				} else {
					parityTuningLoggerTesting(_('Pausing Automatic parity check configured'));
					if (isParityCheckActivePeriod()) {
						parityTuningLoggerTesting(_("... but in active period so leave running"));
					} else {
						parityTuningLoggerTesting(_("... outside active period so needs pausing"));
						loadVars(60);		// always let run for short time before pausing.
						parityTuningLoggerTesting(_("Pausing automatic parity check"));
						goto RUN_PAUSE;
					}
				}
			}
		} else {
			parityTuningLoggerTesting(_("does not appear to be an unclean shutdown"));
		}
		parityTuningProgressAnalyze();
		parityTuningDeleteFile(PARITY_TUNING_PROGRESS_FILE);
		suppressMonitorNotification();
		break;

    case 'stopping':
        parityTuningLoggerDebug(_('Array stopping'));
        reportStatusFiles();
        parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
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
	    reportStatusFiles();
    	createMarkerFile (PARITY_TUNING_TIDY_FILE);
    	parityTuningLoggerDebug ("Created PARITY_TUNING_TIDY_FILE to indicate tidy shutdown");
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
        reportStatusFiles();
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
        reportStatusFiles();
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

//	If configuration option set then save the information to enable restarting an array operation

//       ~~~~~~~~~~~~~~~~~~~~~~
function saveRestartInformation() {
//       ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningActive, $parityTuningRestartOK, $parityTuningVar;
    parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
    parityTuningDeleteFile(PARITY_TUNING_DISKS_FILE);

    if ($parityTuningActive && $parityTuningRestartOK) {
        $restart = 'mdResync=' . $parityTuningVar['mdResync'] . "\n"
				   .'mdResyncPos=' . $parityTuningVar['mdResyncPos'] . "\n"
				   .'mdResyncSize=' . $parityTuningVar['mdResyncSize'] . "\n"
				   .'mdResyncAction=' . $parityTuningVar['mdResyncAction'] . "\n"
				   .'mdResyncCorr=' . $parityTuningVar['mdResyncCorr'] . "\n"
			       .'startMode=' . $parityTuningVar['startMode'] . "\n";
		file_put_contents (PARITY_TUNING_RESTART_FILE, $restart);
		parityTuningLoggerTesting(sprintf( _('Restart information saved to file %s'), PARITY_TUNING_RESTART_FILE));
		copy ('/var/local/emhttp/disks.ini', PARITY_TUNING_DISKS_FILE);
		parityTuningLoggerTesting(sprintf( _('Current disks information saved to file %s'), PARITY_TUNING_DISKS_FILE));
	}
}

// Remove a file and if TESTING logging active then log it has happened
// For marker files sanitize the name to a friendlier form

//       ~~~~~~~~~~~~~~~~~~~~~~
function parityTuningDeleteFile($name) {
//       ~~~~~~~~~~~~~~~~~~~~~~
 	if (file_exists($name)) {
		@unlink($name);
		if (startsWith($name, PARITY_TUNING_FILE_PREFIX)) $name = str_replace(PARITY_TUNING_FILE_PREFIX, '', $name) . 'marker file ';
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

// Convert an array of drive names/temperatures into a simple list for display

//       ~~~~~~~~~~
function listDrives($drives) {
//       ~~~~~~~~~~
	$msg = '';
    foreach ($drives as $key => $value) {
        $msg .= $key . '(' . tempInDisplayUnit($value) . $GLOBALS['parityTuningTempUnit'] . ') ';
    }
    return $msg;
}

// Get the temperature in display units from Celsius

//		 ~~~~~~~~~~~~~~~~~
function tempInDisplayUnit($temp) {
//		 ~~~~~~~~~~~~~~~~~
	if ($GLOBALS['parityTuningTempUnit'] == 'C') {
		return $temp;
	}
	return round(($temp * 1.8) + 32);
}

// Get the temperature in Celsius compensating
// if needed for fact dirplay in Fahrenheit

//		 ~~~~~~~~~~~~~~~~~~
function tempFromDisplayUnit($temp) {
//		 ~~~~~~~~~~~~~~~~~~
	if ($GLOBALS['parityTuningTempUnit'] == 'C') {
		return $temp;
	}
	return round(($temp-32)/1.8);
}

// is an array operation in progress

//       ~~~~~~~~~~~~~~~~~~~~~~
function isArrayOperationActive() {
//       ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningPos;
	if (file_exists(PARITY_TUNING_RESTART_FILE)) {
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
    $var		  	 = $GLOBALS['parityTuningVar'];

    // TODO: This may need revisiting if decided that partial checks should be recorded in History
    if (parityTuningPartial()) {
        parityTuningLoggerTesting(' ignoring progress file as was for partial check');
    	parityTuningDeleteFile(PARITY_TUNING_PROGRESS_FILE);
    }

    if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
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
    $lines = file(PARITY_TUNING_PROGRESS_FILE);

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
        $endType = file_exists(PARITY_TUNING_UNCLEAN_FILE) ? 'ABORTED' : 'COMPLETED';
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
        	case 'AUTOMATIC':
        	case 'MANUAL':
        	        $scheduled = false;
        	        break;
        	case 'UNKNOWN':
        	case 'type':    // TODO: This record type could probably be removed when debugging complete
        			break;

			CASE 'PARTIAL':
					$firstSector = $GLOBALS['parityTuningStartSector'];
					break;

            case 'STARTED': // TODO: Think can be ignored as only being documentation?
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
						parityTuningLoggerTesting("changing reachedSector from $reachedSector to $mdResyncSize");
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

    if (file_exists(PARITY_TUNING_UNCLEAN_FILE) && ($exitCode != -5)) {
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
	parityTuningLoggerTesting("ProgressFile start:" . date(PARITY_TUNING_DATE_FORMAT,$thisStart) . ", finish:" . date(PARITY_TUNING_DATE_FORMAT,$thisFinish) . ", $exitStatus, $completed");
	$unit='';
	if ($reachedSector == 0) $reachedSector = $mdResyncSize;		// position reverts to 0 at end
	$speed = my_scale(($reachedSector * (1024 / $duration)), $unit,1);
	$speed .= "$unit/s";
	parityTuningLoggerTesting("totalSectors: $mdResyncSize, duration: $duration, speed: $speed");

//  Keep a copy of the most recent progress file.
//  This will help with debugging any problem reports

	parityTuningDeleteFile(PARITY_TUNING_PROGRESS_FILE . ".save");
	rename (PARITY_TUNING_PROGRESS_FILE, PARITY_TUNING_PROGRESS_FILE . ".save");
	parityTuningLoggerDebug('Old progress file available as ' . PARITY_TUNING_PROGRESS_FILE . '$.save');

    parityTuningDeleteFile(PARITY_TUNING_PROGRESS_FILE);		// finished with Progress file so remove it

    if (! startsWith($mdResyncAction,'check')) {
        parityTuningLoggerDebug('array action was not Parity Check - it was '. actionDescription($mdResyncAction, $mdResyncCorr));
        parityTuningLoggerDebug('... so update to parity check history not appropriate');
        parityTuningDeleteFile($scheduledFile);   // should not exist but lets play safe!
        return;
    }

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
			// parityTuningLoggerTesting('history line ' . ($matchLine+1) . " $logstamp, logtime=$logtime=" . date(PARITY_TUNING_DATE_FORMAT,$logtime));
			if ($logtime > $thisStart) {
				parityTuningLoggerTesting ("looks like line " . ($matchLine +1) . " is the one to update, logtime=$logtime  . " . date(PARITY_TUNING_DATE_FORMAT,$logtime) . ')');
				parityTuningLoggerTesting ($line);
				if ($logtime <= $thisFinish) {
					parityTuningLoggerDebug ('update log entry on line ' . ($matchLine+1),", errors=$logerrors");
					$lastFinish = $logtime;
					$exitCode = $logexit;
					if ($logerrors > $corrected) $corrected = $logerrors;
					break;
				} else {
					parityTuningLoggerTesting ("... but logtime = $logtime ("
											. date(PARITY_TUNING_DATE_FORMAT,$logtime)
											. "), lastFinish = $lastFinish ("
											. date(PARITY_TUNING_DATE_FORMAT,$lastfinish)
											. "), thisFinish=$thisFinish ("
											. date(PARITY_TUNING_DATE_FORMAT,$thisFinish) . ')');
				}
			}
			$matchLine++;
		}
		if ($matchLine == count($lines))  parityTuningLoggerDebug('no match found in existing log so added a new record ' . ($matchLine + 1));
		$type = explode(' ',$desc);
		$gendate = date(PARITY_TUNING_DATE_FORMAT,$lastFinish);
		if ($gendate[9] == '0') $gendate[9] = ' ';  // change leading 0 to leading space

		$generatedRecord = "$gendate|$duration|$speed|$exitCode|$corrected|$elapsed|$increments|$type[0]\n";
		parityTuningLoggerDebug("log record generated from progress: $generatedRecord");    $lines[$matchLine] = $generatedRecord;
		$myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
		file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);  // Save for debug purposes
		file_put_contents($parityLogFile,$lines);
		// send Notification about data written to history file
		$msg  = sprintf(_('%s %s %s (%d errors)'),
						operationTriggerType(), actionDescription($mdResyncAction, $mdResyncCorr), $exitStatus, $corrected);
		$desc = sprintf(_('%s %s, %s %s, %s %d, %s %s'),
						 			_('Elapsed Time'),his_duration($elapsed),
									_('Runtime'), his_duration($duration),
									_('Increments'), $increments,
									_('Average Speed'),$speed);
		parityTuningLogger($msg);
		parityTuningLogger($desc);
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
    $progressFile   = PARITY_TUNING_PROGRESS_FILE;

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
    $line .= $msg . '|' . date($GLOBALS['PARITY_TUNING_DATE_FORMAT']) . '|' . time() . '|';
    foreach ($progressFields as $name) $line .= $var[$name] . '|';
    $line .= actionDescription($GLOBALS['parityTuningAction'], $GLOBALS['parityTuningCorrecting']) . "|\n";
    file_put_contents($progressFile, $line, FILE_APPEND | LOCK_EX);
    parityTuningLoggerTesting ('written ' . $msg . ' record to  ' . $progressFile);
}


// send a notification without checking if enabled in plugin settings
// (assuming even enabled at the system level)

//       ~~~~~~~~~~~~~~~~
function sendNotification($msg, $desc = '', $type = 'normal') {
//       ~~~~~~~~~~~~~~~~
    parityTuningLoggerTesting (_('Send notification') . ': ' . $msg . ': ' . $desc);
    if ($GLOBALS['dynamixCfg']['notify']['system'] == "" ) {
    	parityTuningLoggerTesting (_('... but suppressed as system notifications do not appear to be enabled'));
    	parityTuningLogger("$msg: $desc " . parityTuningCompleted());
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
        parityTuningLoggerTesting (_('... but suppressed as notifications do not appear to be enabled for pause/resume'));
        parityTuningLogger($op . ": " . actionDescription($GLOBALS['parityTuningAction'], $GLOBALS['parityTuningCorrecting'])
    							 	  . parityTuningCompleted());		// Simply log message if not notifying
        return;
    }
    sendNotificationWithCompletion($op);
}

// Send a notification if temperature related notifications enabled

//       ~~~~~~~~~~~~~~~~~~~~
function sendTempNotification ($op, $desc, $type = 'normal') {
//       ~~~~~~~~~~~~~~~~~~~~
    parityTuningLoggerTesting("Heat notification message: $op: $desc");
    if ($GLOBALS['parityTuningCfg']['parityTuningHeatNotify'] == '0') {
        parityTuningLogger($op);			// Simply log message if not notifying
        parityTuningLogger($desc);
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

//	log presence of any plugin files indicating status
//  useful when terting the plugin

//       ~~~~~~~~~~~~~~~~~
function reportStatusFiles() {
//       ~~~~~~~~~~~~~~~~~
	// Files that can (optionally) exist on flash drive to hold/indicate status
	$filesToCheck = array(PARITY_TUNING_SYNC_FILE,           PARITY_TUNING_TIDY_FILE,
						  	     PARITY_TUNING_PROGRESS_FILE,PARITY_TUNING_AUTOMATIC_FILE,
						         PARITY_TUNING_MANUAL_FILE,  PARITY_TUNING_SCHEDULED_FILE,
						         PARITY_TUNING_RESTART_FILE, $parityTuningProgressFile,
						         PARITY_TUNING_PARTIAL_FILE, PARITY_TUNING_DISKS_FILE,
						         PARITY_TUNING_HOT_FILE,     PARITY_TUNING_CRITICAL_FILE);
	foreach ($filesToCheck as $filename) {
		if (file_exists($filename)) {
			$tidyname = str_replace(PARITY_TUNING_FILE_PREFIX,'',$filename);
			parityTuningLoggerTesting("$tidyname marker file present");
		}
	}
}


// Remove the files (if present) that are used to indicate trigger type for array operation

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningInactiveCleanup() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	parityTuningDeleteFile(PARITY_TUNING_PARTIAL_FILE);
	parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
	parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
	parityTuningDeleteFile(PARITY_TUNING_AUTOMATIC_FILE);
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

// Confirm that a pause or resume is valid according to user settings
// and the type of array operation currently in progress

//       ~~~~~~~~~~~~~~~~
function configuredAction() {
//       ~~~~~~~~~~~~~~~~
	$action     = $GLOBALS['parityTuningAction'];
	$actionDescription = actionDescription($action, $GLOBALS['parityTuningCorrecting']);

	// check configured options zgainst array operation type in progress

    $actionTrigger = '';
    if (startsWith($action,'recon')) {
    	$result = $GLOBALS['parityTuningRecon'];
    } else if (startsWith($action,'clear')) {
    	$result = $GLOBALS['parityTuningClear'];
    } else if (! startsWith($action,'check')) {
    	parityTuningLoggerTesting("ERROR: unrecognized action type $action");
    	$result = false;
    } else {
		// Integrity checks included on how array parity check operation triggered
		// Can be considered for removal when confident no logic flaws exist in setting/detecting trigger type
		$scheduled = file_exists(PARITY_TUNING_SCHEDULED_FILE);
		$automatic = file_exists(PARITY_TUNING_AUTOMATIC_FILE);
		$manual    = file_exists(PARITY_TUNING_MANUAL_FILE);

		if ($scheduled) {
			$actionTrigger = _('scheduled');
			$result = $GLOBALS['parityTuningIncrements'];
			if ($manual)		parityTuningLogger("ERROR: marker file found for both scheduled and manual $actionDescription");
			if ($automatic)		parityTuningLogger("ERROR: marker file found for both scheduled and automatic $actionDescription");

		} else if ($automatic) {
			$actionTrigger = _('automatic');
			$result = $GLOBALS['parityTuningAutomatic'];
			if ($manual)		parityTuningLogger("ERROR: marker file found for both manual and automatic $actionDescription");
		} else {
			if (! $manual) {
				parityTuningLogger("No marker file found for trigger type of $actionDescription");
				parityTuningLogger("... so Manual (Unscheduled) check assumed");
				file_put_contents (PARITY_TUNING_MANUAL_FILE, date(PARITY_TUNING_DATE_FORMAT));
				parityTuningProgressWrite ('MANUAL');
			}
			$actionTrigger = _('manual');
			$result = $GLOBALS['parityTuningUnscheduled'];
		}
	}
	parityTuningLoggerTesting("...configured action for $actionTrigger $actionDescription: $result");
    return $result;
}

// get the type of a check according to which marker files exist
// (plus apply some consistency checks against scenarios that should not happen)
//		 ~~~~~~~~~~~~~~~~~~~~
function operationTriggerType() {
//		 ~~~~~~~~~~~~~~~~~~~~
	$action    = $GLOBALS['parityTuningAction'];
	$scheduled = file_exists(PARITY_TUNING_SCHEDULED_FILE);
	$automatic = file_exists(PARITY_TUNING_AUTOMATIC_FILE);
	$manual    = file_exists(PARITY_TUNING_MANUAL_FILE);
	if ($scheduled) {
		if ($manual)		parityTuningLogger("ERROR: marker file found for both scheduled and manual $actionDescription");
		if ($automatic)		parityTuningLogger("ERROR: marker file found for both scheduled and automatic $actionDescription");
		return 'scheduled';
	} else if ($automatic) {
		if ($manual)		parityTuningLogger("ERROR: marker file found for both manual and automatic $actionDescription");
		return 'automatic';
	} else if ($manual) {
		return 'manual';
	}
	return '';
}

// Determine if the current time is within a period where we expect this plugin to be active
// TODO: Work out an answer for custom schedules

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function isParityCheckActivePeriod() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
    $resumeTime = ($GLOBALS['parityTuningResumeHour'] * 60) + $GLOBALS['parityTuningResumeMinute'];
    $pauseTime  = ($GLOBALS['parityTuningPauseHour'] * 60) + $GLOBALS['parityTuningPauseMinute'];
    $currentTime = (date("H") * 60) + date("i");
    if ($pauseTime > $resumeTime) {         // We need to allow for times spanning midnight!
        return ($currentTime > $resumeTime) && ($currentTime < $pauseTime);
    } else {
        return ($currentTime > $resumeTime) && ($currentTime < $pauseTime);
    }
}

// Set marker file to remember some state information we have detected
// (put date/time into file so can tie it back to syslog if needed)

//       ~~~~~~~~~~~~~~~~
function createMarkerFile ($filename) {
//       ~~~~~~~~~~~~~~~~
	if (file_exists($filename)) {
		parityTuningLoggerTesting("$filename already exists");
	} else {
		file_put_contents ($filename, date(PARITY_TUNING_DATE_FORMAT));
		parityTuningLoggerDebug("$filename created to indicate how " . actionDescription($GLOBALS['parityTuningAction'], $GLOBALS['parityTuningCorrecting']) . " was started");
	}
}

// Set a marker file for an lnscheduled check if not already set

//		 ~~~~~~~~~~~~~~~~~~
function manualIfUnknown() {
//		 ~~~~~~~~~~~~~~~~~~
	if (file_exists(PARITY_TUNING_SCHEDULED_FILE)
	|| file_exists(PARITY_TUNING_MANUAL_FILE)
	|| file_exists(PARITY_TUNING_AUTOMATIC_FILE)) {
		return;
	}
	createMarkerFile (PARITY_TUNING_MANUAL_FILE);
}

//       ~~~~~~~~~~~~~~~~~
function updateCronEntries() {
//       ~~~~~~~~~~~~~~~~~
	parityTuningDeleteFile (PARITY_TUNING_CRON_FILE);
	$lines = [];
	$lines[] = "\n# Generated schedules for " . PARITY_TUNING_PLUGIN . "\n";

	if (parityTuningPartial()) {
		// Monitor every minutes during partial checks
		$frequency = "*/1";
		parityTuningLoggerDebug (_('created cron entry for monitoring partial parity checks'));
	} else {
		if ($GLOBALS['parityTuningIncrements'] || $GLOBALS['parityTuningUnscheduled']) {
			if ($GLOBALS['parityTuningFrequency']) {
				$resumetime = $GLOBALS['parityTuningResumeCustom'];
				$pausetime  = $GLOBALS['parityTuningPauseCustom'];
			} else {
				$resumetime = $GLOBALS['parityTuningResumeMinute'] . ' '
							. $GLOBALS['parityTuningResumeHour'] . ' * * *';
				$pausetime  = $GLOBALS['parityTuningPauseMinute'] . ' '
							. $GLOBALS['parityTuningPauseHour'] . ' * * *';
			}
			$lines[] = "$resumetime " . PARITY_TUNING_PHP_FILE . ' "resume" &> /dev/null' . "\n";
			$lines[] = "$pausetime " . PARITY_TUNING_PHP_FILE . ' "pause" &> /dev/null' . "\n";
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
	$lines[] = "$frequency * * * * " . PARITY_TUNING_PHP_FILE . ' "monitor" &>/dev/null' . "\n";
	file_put_contents(PARITY_TUNING_CRON_FILE, $lines);
	parityTuningLoggerTesting(sprintf(_('updated cron settings are in %s'),PARITY_TUNING_CRON_FILE));
	// Activate any changes
	exec("/usr/local/sbin/update_cron");
}


?>
