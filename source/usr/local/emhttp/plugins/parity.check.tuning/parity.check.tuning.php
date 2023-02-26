#!/usr/bin/php
<?PHP
/*
 * Script tparityTuningRestartOKhat is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file command; or from another script.
 *
 * It takes a parameter describing the action required.
 *
 * In can also be called via CLI as the command 'parity.check' to expose functionality
 * that relates to parity checking.
 *
 * Copyright 2019-2023, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * Limetech is given explicit permission to use thfis code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

// Normnally cron triggers actions on minute boundaries and this can lead to two different invoations
// of this script running in parallel.  Adding a random delay is an attempt to stop simultaneous 
// calls interleaving although if they do things should still operate OK, but the logs look a lot 
// tidier and are easier to interpret when investigating possible problems.

if (isset($argv) && (strcasecmp(trim($argv[1]),'starting') != 0)) $randomSleep = rand(1,30);

require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';

// Some useful constants local to this file
// Marker files are used to try and inicate state type information
define('UNRAID_PARITY_SYNC_FILE',      '/boot/config/forcesync');		        // Presence of file used by Unraid to detect unclean Shutdown (we currently ignore)
define('UNRAID_PARITY_HISTORY_FILE',   '/boot/config/parity-checks.log');		// File that holds history of array operations  
define('PARITY_TUNING_CRON_FILE',      PARITY_TUNING_FILE_PREFIX . 'cron');	    // File created to hold current cron settings for this plugin
define('PARITY_TUNING_PROGRESS_FILE',  PARITY_TUNING_FILE_PREFIX . 'progress'); // Created when array operation active to hold increment info
define('PARITY_TUNING_PROGRESS_SAVE',  PARITY_TUNING_FILE_PREFIX . 'progress.save');// Created when analysis completed
define('PARITY_TUNING_MOVER_FILE',     PARITY_TUNING_FILE_PREFIX . 'mover');	    // Created when paused because mover is running
define('PARITY_TUNING_BACKUP_FILE',    PARITY_TUNING_FILE_PREFIX . 'backup');	    // Created when paused because CA Backup is running
define('PARITY_TUNING_HOT_FILE',       PARITY_TUNING_FILE_PREFIX . 'hot');	    // Created when paused because at least one drive found to have reached 'hot' temperature
define('PARITY_TUNING_CRITICAL_FILE',  PARITY_TUNING_FILE_PREFIX . 'critical'); // Created when parused besause at least one drive found to reach critical temperature
define('PARITY_TUNING_DISKS_FILE',     PARITY_TUNING_FILE_PREFIX . 'disks');    // Copy of disks.ini  info saved to allow check if disk configuration changed
define('PARITY_TUNING_TIDY_FILE',      PARITY_TUNING_FILE_PREFIX . 'tidy');	    // Create when we think there was a tidy shutdown
define('PARITY_TUNING_UNCLEAN_FILE',   PARITY_TUNING_FILE_PREFIX . 'unclean');  // Create when we think untidy shutdown happened (TODO is it really needed)
define('PARITY_TUNING_SPINUP_FILE',    'ParityTuningSpinup');					// Create when we think drive needs spinning up
define('PARITY_TUNING_HISTORY_FILE',   PARITY_TUNING_BOOT_DIR . '/parity-checks.log');


loadVars();

if (empty($argv)) {
  parityTuningLoggerDebug(_("ERROR") . ": " . _("No action specified"));
  exit(0);
}
$command = (count($argv) > 1) ? trim($argv[1]) : '?';

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

// check for presence of any plugin marker files that can
// (optionally) exist on flash drive indicating status (useful 
// to know when testing the plugin

$filesToCheck = array(UNRAID_PARITY_SYNC_FILE,
//   					  PARITY_TUNING_STARTING_FILE,
   					  PARITY_TUNING_TIDY_FILE,
					  PARITY_TUNING_PROGRESS_FILE,
					  PARITY_TUNING_PROGRESS_SAVE,
					  PARITY_TUNING_AUTOMATIC_FILE,
					  PARITY_TUNING_MANUAL_FILE,
					  PARITY_TUNING_SCHEDULED_FILE,
					  PARITY_TUNING_RESTART_FILE,
		  			  PARITY_TUNING_BACKUP_FILE,
					  PARITY_TUNING_PARTIAL_FILE, 	
					  PARITY_TUNING_DISKS_FILE,
					  PARITY_TUNING_HOT_FILE,
					  PARITY_TUNING_CRITICAL_FILE,
					  PARITY_TUNING_MOVER_FILE);
foreach ($filesToCheck as $filename) {
	if ($parityTuningCfg['parityTuningLogging'] > 1) {
		// For effeciency only do these checks in Testing logging mode
		if (file_exists($filename)) {
			$tidyname=str_replace(PARITY_TUNING_FILE_PREFIX,'',$filename);
			parityTuningLoggerTesting("$tidyname marker file present");
		}
	}
}

switch (strtolower($command)) {

	case 'defaults':
		// reset user configuration to the defaults issued with plugin
		@copy (PARITY_TUNING_DEFAULTS_FILE,PARITY_TUNING_CFG_FILE);
		parityTuningLogger(_('Settings reset to default values'));
		// FALLTHRU
	case 'updatecron':
		// set up cron entries based on current configuration values
		parityTuningLogger('Configuration: '.print_r($parityTuningCfg,true));
		updateCronEntries();
        break;

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to
		// 'mdcmd' was made so that we can detect if a parity check
		// was started on a schedule or whether it was manually
		// started.
        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerTesting(sprintf(_('detected that mdcmd had been called from %s with command %s'), $argv['2'], $cmd));
        switch (strtolower($argv[2])) {
        case 'crond':
        case 'sh':
            switch (strtolower($argv[3])) {
            case 'check':
                    loadVars(5);         // give time for start/resume
                    if (strtolower($argv[4]) === 'resume') {
                        parityTuningLoggerDebug ('... ' . _('Resume' . ' ' . $parityTuningDescription));
                        parityTuningProgressWrite('RESUME');		// We want state after resume has started
                    } else {
						if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
							parityTuningLoggerTesting('analyze previous progress before starting new one');
							parityTuningProgressAnalyze();
						}
						// Work out what type of trigger
						if (strtolower($argv[2]) === 'crond') {
							parityTuningLoggerTesting ('... ' . _('appears to be a regular scheduled check'));
							createMarkerFile(PARITY_TUNING_SCHEDULED_FILE);
							parityTuningProgressWrite ('SCHEDULED');
						} else {
							$triggerType = operationTriggerType();
							parityTuningLoggerTesting ('... ' . _("appears to be a $triggerType array operation"));
							parityTuningProgressWrite ($triggerType);
						}
                    }
                    break;
            case 'nocheck':
                    if (strtolower($argv[4]) === 'pause') {
						loadVars(5);         // give time for pause                       
					    parityTuningLoggerDebug ('...' . _('Pause' . ' ' . $parityTuningDescription));
                        parityTuningProgressWrite ("PAUSE");
                    } else {
                        // Not sure this even a possible operation but we allow for it anyway!
                        parityTuningProgressWrite ('CANCELLED');
                        parityTuningProgressAnalyze();
                        parityTuningInactiveCleanup();
                    }
                    updateCronEntries();
                    break;
			} // end of operation type switch
		case 'array_started':
				if ($argv[4] === 'pause') {
					parityTuningProgressWrite ('PAUSE');
				}
				break;
		case 'started':
				updateCronEntries();
				parityTuningLoggerTesting('Must be part of restart operation so nothing further to do!');
				break;
		default:
				parityTuningLoggerDebug(_('Option not currently recognized'));
				break;
		}  // end of 'crond' switch
		break;

    case 'monitor':
        // This is invoked at regular intervals to try and detect
		// some sort of relevant status change that we need to take
		// some action on.  In particular disks overheating 
		// (or cooling back down).
        //
        // This is also the place where we can detect manual checks
		// have been started.
        //
        // The monitor frequency varies according to whether
		// temperatures are being checked or partial parity checks
		// are active as then we do it more often.
		if (! file_exists(PARITY_TUNING_EMHTTP_DISKS_FILE)) {
			parityTuningLoggerTesting('System appears to still be initializing - disk information not available');
			break;
		}
		if ($parityTuningVar['mdState'] != 'STARTED') {
			parityTuningLoggerTesting('Array not yet started so nothing to monitor');
			break;
		}

		// Handle monitoring of partial checks

		if (parityTuningPartial()) {
			if (isArrayOperationActive()) {
				$parityTuningSector = $parityTuningPos * 2;
				if ($parityTuningSector < $parityProblemEndSector) {
					parityTuningLoggerTesting ("Partial check: sector reached:$parityTuningSector, end sector:$parityProblemEndSector");
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
			$runType = ($parityTuningCfg['parityProblemCorrect'] == 0) ? _('Non-Correcting') : _('Correcting');
			sendNotification(_('Completed partial check ('). $runType . ')',
			parityTuningPartialRange() . ' ' . _('Errors') . ' ' . $parityTuningVar['sbSyncErrs'] );
			parityTuningDeleteFile (PARITY_TUNING_PARTIAL_FILE);
			updateCronEntries();
			break;
		}

		// See if array operation is being restarted
		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLoggerTesting (_('appears restart in progress'));
			break;
		}
		// See if array operation in progress so that we need to consider pause/resume
		if (!isArrayOperationActive()) {
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
			break;
		}
		// Consistency check
		if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			parityTuningLoggerTesting (_('appears there is a running array operation but no Progress file yet created'));
			$trigger = operationTriggerType();
			parityTuningLoggerDebug ($parityTuningDescription);
			parityTuningProgressWrite ($trigger);
			updateCronEntries();    // ensure reasonably frequent monitor chechs
		}

		// Handle pause/resume around mover running
		
		if ($parityTuningCfg['parityCheckMover']) {
			parityTuningLoggerTesting ("mover " . (isMoverRunning() ? "" : "not ") 
									. "running, array operation " . ($parityTuningRunning ? "running" : "paused"));
			if (isMoverRunning()) {
				if ($parityTuningRunning) {
					$msg = _('Paused') . ": " . _('Mover running') . ($parityTuningErrors > 0 ? "$parityTuningErrors " . _('errors') . ')' : '');
					parityTuningLogger($msg);
					exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
					sendArrayNotification ();
				} else {
					parityTuningLoggerTesting ("... no action required");
				}					
				createMarkerFile(PARITY_TUNING_MOVER_FILE);  // may already exist at this point
			} else {
				if (file_exists(PARITY_TUNING_MOVER_FILE)) {
					$msg = _('Resumed') . ": " . _('Mover no longer running') . ($parityTuningErrors > 0 ? "$parityTuningErrors " . _('errors') . ')' : '');
					parityTuningLogger ($msg);
					parityTuningDeleteFile (PARITY_TUNING_MOVER_FILE);
					exec('/usr/local/sbin/mdcmd "check" "resume"');
					sendArrayNotification ($msg);
				} else {
					parityTuningLoggerTesting ("... no action required");
				}					
			}
		}

		// Handle pause/resume around CA Backup  running

		if ($parityTuningCfg['parityTuningCABackup']) {
			parityTuningLoggerTesting ("CA Backup "
									. (isCABackupRunning() ? (file_exists(PARITY_TUNING_RESTORE_FILE)?"":"restore") : "not ") 
									. "running, array operation " . ($parityTuningRunning ? "running" : "paused"));
			if (isCABackupRunning()) {
				if ($parityTuningRunning) {
					$msg = _('Paused') . ": " . _('CA Backup or Restore running') . ($parityTuningErrors > 0 ? "$parityTuningErrors " . _('errors') . ')' : '');
					parityTuningLogger($msg);
					exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
					sendArrayNotification ($msg);
				} else {
					parityTuningLoggerTesting ("... no action required");
				}					
				createMarkerFile(PARITY_TUNING_BACKUP_FILE);  // may already exist at this point
			} else {
				if (file_exists(PARITY_TUNING_BACKUP_FILE)) {
					$msg = _('Resumed') . ': ' . _('CA Backup or Restore no longer running') . ($parityTuningErrors > 0 ? "$parityTuningErrors " . _('errors') . ')' : '');
					parityTuningLogger($msg);
					parityTuningDeleteFile (PARITY_TUNING_BACKUP_FILE);
					exec('/usr/local/sbin/mdcmd "check" "resume"');
					sendArrayNotification ($msg);
				} else {
					parityTuningLoggerTesting ("... no action required");
				}					
			}
		}
		
        // Check for disk temperature changes we are monitoring

        if ((!$parityTuningCfg['parityTuningHeat']) && (! $parityTuningCfg['parityTuningHeatShutdown'])) {
            parityTuningLoggerTesting (_('Temperature monitoring switched off'));
            parityTuningDeleteFile (PARITY_TUNING_CRITICAL_FILE);
            break;
        }

        // Get disk temperature information
		//
		// It is slightly complicated by the fact that the
		// temperature of spundown disks cannot be read.
		// We also need to allow for fact that indivdual disks
		// can override the global settings

        // Merge SMART settings
        require_once "$docroot/webGui/include/CustomMerge.php";

        $dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);

		$criticalDrives = array();  // drives that exceed shutdown threshold
        $hotDrives = array();       // drives that exceed pause threshold
        $warmDrives = array();      // drives that are between pause and resume thresholds
        $coolDrives = array();      // drives that are cooler than resume threshold
		$spinDrives = array();  	// drives that ase spundown so need spinning up to read temperatures
		$idleDrives = array();		// drives no longes being used
        $driveCount = 0;
        $arrayCount = 0;
        $status = '';
		$globalWarning = ($dynamixCfg['display']['hot']??45);
		$globalCritical = ($dynamixCfg['display']['max']??55);
		parityTuningLoggerTesting (_('global temperature limits')
									. ': ' . _('Warning') . ': ' . 
									($globalWarning == 0?_('disabled'):$globalWarning)
									. ', ' . _('Critical') . ': ' . 
									($globalCritical == 0?_('disabled'):$globalCritical));
        parityTuningLoggerTesting (_('plugin temperature settings') 
									. ': ' . _('Pause') . ' ' .  $parityTuningCfg['parityTuningHeatHigh'] 
									. ', ' . _('Resume') . ' ' . $parityTuningCfg['parityTuningHeatLow']
                				   . ($parityTuningCfg['parityTuningShutdown'] ? (', ' . _('Shutdown') . ' ' . $parityTuningCfg['parityTuningHeatCritical']) . ')' : ''));
								   
		// gather temperature information from all array drives
        foreach ($disks as $drive) {
            $name=$drive['name'];
            $temp = $drive['temp'];
			// remove any lingering spinup marker file
			parityTuningDeleteFile ("/mnt/$name/".PARITY_TUNING_SPINUP_FILE);
            if ((!startsWith($drive['status'],'DISK_NP')) & (! $name == 'flash')) {
                $driveCount++;
				$driveWarning = ($drive['hotTemp'] ?? $globalWarning);
				$driveCritical= ($drive['maxTemp'] ?? $globalCritical);
				if (($driveWarning != $globalWarning)
				||  ($driveCritical != $globalCritical)) {
					parityTuningLoggerTesting (_('drive temperature limits')
									. ': ' . _('Warning') . ': ' . 
									($driveWarning== 0?_('disabled'):$driveWarning)
									. ', ' . _('Critical') . ': ' . 
									($driveCritical== 0?_('disabled'):$driveCritical));
				}
                $critical  = $driveCritical - $parityTuningCfg['parityTuningHeatCritical'];
                $hot  = $driveWarning - $parityTuningCfg['parityTuningHeatHigh'];
                $cool = $driveWarning - $parityTuningCfg['parityTuningHeatLow'];
				// Check array drives for other over-heating
				if ((startsWith($name, 'parity')) || (startsWith($name,'disk'))) {
					$arrayCount++;
					if ($temp == "*" ) { 	// spun down
						if ($drive['size'] > $parityTuningPos) {
							$spinDrives[] = $name;
						} else {
							$idleDrives[$name] = $temp;
							$status = _('past size');
						}
					} else {
						if ($driveWarning == 0) {
							$status = _('disabled');
						} else {
							if ($temp <= $cool) {
							  $coolDrives[$name] = $temp;
							  $status = _('cool');
							} elseif ($temp >= $hot) {
							  $hotDrives[$name] = $temp;
							  $status = _('hot');
							} else {
								$warmDrives[$name] = temp;
								$status = _('warm');
							}
						}
					}
					//  Check all array and cache drives for critical temperatures
					//  TODO: find way to include unassigned devices?
					if ($driveCritical == 0) {
						if ($driveWarning == 0) $status = _('disabled');
					} else {
						if ((($temp != "*" )) && ($temp >= $critical)) {
							parityTuningLoggerTesting(sprintf('Drive %s: %s%s appears to be critical (%s%s)', $name, tempInDisplayUnit($temp), $parityTuningTempUnit,
							$critical, $parityTuningTempUnit));
							$criticalDrives[$name] = $temp;
							$status = _('critical');
						}
					}
					parityTuningLoggerTesting (sprintf('%s temp=%s%s, status=%s (drive settings: hot=%s%s, cool=%s%s',$name,
												($temp=='*')?'*':tempInDisplayUnit($temp),
												($temp=='*')?'':$parityTuningTempUnit, $status,
												tempInDisplayUnit($hot), $parityTuningTempUnit,
												tempInDisplayUnit($cool), $parityTuningTempUnit)
											   . ($parityTuningCfg['parityTuningShutdown'] ? sprintf(', critical=%s%s',tempInDisplayUnit($critical), $parityTuningTempUnit) : ''). ')');
				}
			}
        }  // end of for loop gathering temperature information

        // Handle at least 1 drive reaching shutdown threshold
		// (deemed more important than simple overheating)
        if ($parityTuningCfg['parityTuningShutdown']) {
			if (count($criticalDrives) == 0) {
				if (count($spinDrives) == 0) {
					parityTuningLoggerDebug(_('No drives appear to have reached shutdown threshold'));
				}
			} else {
				$drives=listDrives($criticalDrives);
				parityTuningLogger(_("Array being shutdown due to drive overheating"));
				file_put_contents (PARITY_TUNING_CRITICAL_FILE, "$drives\n");
				$msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
				if (isArrayOperationActive()) {
					parityTuningLoggerTesting('array operation is active');
					$msg .= '<br>' . _('Abandoned ') . $parityTuningDescription . parityTuningCompleted();
				}
				sendNotification (_('Array shutdown'), $msg, 'alert');
				if ($parityTuningTesting) {
					parityTuningLoggerTesting (_('Shutdown not actioned as running in TESTING mode'));
					exit(0);
				} else {
					sleep (15);	// add a delay for notification to be actioned
					parityTuningLogger (_('Starting Shutdown'));
					exec('/sbin/shutdown -h -P now');
				}
				break;
			}
	    }

		// Handle drives being paused/resumed due to temperature

        parityTuningLoggerDebug (sprintf('%s=%d, %s=%d, %s=%d, %s=%d, %s=%d, %s=%d', _('array drives'), $arrayCount, _('hot'), count($hotDrives), _('warm'), count($warmDrives), _('cool'), count($coolDrives),_('spundown'),count($spinDrives),_('idle'),count($idleDrives)));
        if ($parityTuningRunning) {
			// PAUSE?
        	// Check if we need to pause because at least one drive too hot
            if (count($hotDrives) == 0) {
                parityTuningLoggerDebug (sprintf('%s',_('All array drives below temperature threshold for a Pause')));
            } else {
                $drives = listDrives($hotDrives);
                file_put_contents (PARITY_TUNING_HOT_FILE, "$drives\n");
                $msg = (sprintf('%s: ',_('Following drives overheated')) . $drives);
                parityTuningLogger (sprintf('%s %s %s: %s',_('Paused'), $parityTuningDescription, parityTuningCompleted(), $msg ));
                parityTuningProgressWrite('PAUSE (HOT)');
                exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
                sendTempNotification(opWithErrorCount(_('Pause')),$msg);
            }
        } else {
			// RESUME?
        	// Check if we need to resume because drives cooled sufficiently. 
            if (! file_exists(PARITY_TUNING_HOT_FILE)) {
				// No resume if temperature marker file not found
                parityTuningLoggerDebug (_('Array operation paused but not for temperature related reason'));
				break;
            } else {
             	if (count($hotDrives) != 0) {
					// No resume if hot drives still present
             		parityTuningLoggerDebug (_('Array operation paused with some drives still too hot to resume'));
					break;
                } else {
             		if (count($warmDrives) != 0) {
						// No resume if drives not sufficiently cooled
						parityTuningLoggerDebug (_('Array operation paused but drives not cooled enough to resume'));
						// Generate notification if Pause seemes to be excessive
						// (this may be due to resume threshold being too high)
						$waitMinutes = int((time() - filemtime(PARITY_TUNING_HOT_FILE)) / 60);
						$toLongMinutes = $parityTuningCfg['parityTuningHeatTooLong'];
						if (($waitMinutes > $toLongMinutes)
						&&  ($waitMinutes <= ($toLongMinutes + $parityTuningCfg['parityTuningMonitorHeat'])))
						{
							sendTempNotification(opWithErrorCount(_('Waiting')), sprintf(_('Drives been above resume temperature threshold for %s minutes'),$waitMinutes));
						}
						break;

					} else {
						parityTuningLoggerTesting ('Some drives spun down - assume they are cool');
											//
					// Code below was an attempt to let the plugin spin up the drives to check temperature.
                    // Appears to not work for some unknown reason so lets simply resume the check array
					// operation assuming this will trigger Unraid to spin up drives.  W¬we can then pause 
					// it again on next monitor point if any drives still hot.
					//
					//	if (count($spinDrives) != 0) {
					//		parityTuningLoggerDebug (_('Need to check temperatures of spundown drives'));
					//		foreach ($spinDrives as $spin) {
					//			parityTuningLoggerDebug(_('Spinning up').' '.$spin);
					//			$filename = '/mnt/$spin/'.PARITY_TUNING_SPINUP_FILE;
					//			parityTuningDeleteFile ($filename);
					//			file_put_contents ($filename, date(PARITY_TUNING_DATE_FORMAT, LOCK_EX));
					//			sleep(2);
					//		}
					//		break;
					//	}
						parityTuningLogger (sprintf ('%s %s %s %s',_('Resumed'),$parityTuningDescription, parityTuningCompleted(), _('Drives cooled down')));
						sendTempNotification(opWithErrorCount(_('Resume')), _('Drives cooled down'));
						parityTuningDeleteFile (PARITY_TUNING_HOT_FILE);
						// Decide if we are outside an increment window
						// so must not resume even though drives cooled
						if (! isActivePeriod()) {
							parityTuningLoggerDebug(_('Outside increment scheguled time'));
							if ((($parityTuningCfg['parityTuningScheduled'] && file_exists(PARITY_TUNING_SCHEDULED_FILE)))
							|| (($parityTuningCfg['parityTuningManual'] && file_exists(PARITY_TUNING_MANUAL_FILE)))
							|| (($parityTuningCfg['parityTuningAutomatic'] && file_exists(PARITY_TUNING_AUTOMATIC_FILE)))){
								parityTuningLogger(_('Outside times for increments so not resuming'));
								break;
							}
						}
						parityTuningProgressWrite('RESUME (COOL)');
						exec('/usr/local/sbin/mdcmd "check" "RESUME"');
                	}
				}
			}
        }
        break;

    // A resume of an array operation has been requested.
	// This could be via a scheduled cron task or a CLI command
	
    case 'resume':
        parityTuningLoggerDebug (_('Resume request'));
        if (! isArrayOperationActive()) {
        	parityTuningLoggerDebug('Resume ignored as no array operation in progress');
			parityTuningInactiveCleanup();			// tidy up any marker files
        	break;
        }
		if (parityTuningPartial()) {
			parityTuningLoggerResume('Resume ignored as partial check in progress');
			break;
		}
		if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			parityTuningProgressWrite(operationTriggerType());
		}
		if ($parityTuningRunning) {
			parityTuningLoggerDebug(sprintf('... %s %s', $parityTuningDescription, _('already running')));
			break;
		}
		if (file_exists(PARITY_TUNING_HOT_FILE)) {
			parityTuningLoggerDebug('Resume ignored as paused because disks too hot');
			break;
		}
		if (file_exists(PARITY_TUNING_MOVER_FILE)) {
			parityTuningLoggerDebug('Resume ignored as paused because mover running');
			break;
		}

		// Handle special cases where pause had been because of mover running or disks overheating
		// and we are now outside the window for a scheduled resume
		if (!isActivePeriod()) {
			parityTuningLoggerTesting('resume but not inside increments time window for operation so ignored');
			if (file_exists(PARITY_TUNING_HOT_FILE)) {
				parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);
			}
			if (file_exists(PARITY_TUNING_MOVER_FILE)) {
				parityTuningDeleteFile(PARITY_TUNING_MOVER_FILE);
			}
		}				
		if (configuredAction()) {
			parityTuningLogger(_('Resumed').': '.$parityTuningDescription);
			sendArrayNotification(opWithErrorCount(_('Resumed')));
			exec('/usr/local/sbin/mdcmd "check" "resume"');
		}
        break;

    // A pause of an array operation has been requested.
	// This could be via a scheduled cron task or a CLI command
	
    case 'pause':
	    parityTuningLoggerDebug (_('Pause request'));
        if (! isArrayOperationActive()) {
            parityTuningLoggerDebug('Pause ignored as no array operation in progress');
            break;
        }
		if (parityTuningPartial()) {
			parityTuningLoggerDebug('Pause ignored as partial check in progress');
			break;
		}
		if (isArrayOperationActive() && (! file_exists(PARITY_TUNING_PROGRESS_FILE))) {
			parityTuningProgressWrite(operationTriggerType());
		}
		if ($parityTuningPaused) {
			parityTuningLoggerDebug(sprintf('%s %s!', $parityTuningDescription, _('already paused')));
			break;
		}

		if (configuredAction()) {
				// TODO May want to create a 'paused' file to indicate reason for pause?
RUN_PAUSE:	// Can jump here after doing a restart
			parityTuningLogger(_('Paused').': '.$parityTuningDescription);
			exec('/usr/local/sbin/mdcmd "nocheck" "pause"');
			loadVars(5);
			parityTuningLoggerTesting("Errors so far:  $parityTuningErrors");
			sendArrayNotification (opWithErrorCount(_('Paused')));
		}
        break;	
		
	// Set up partial array parity checks for Parity Problems Assistant mode
	
    case 'partial':
		createMarkerFile(PARITY_TUNING_PARTIAL_FILE);	// Create file to indicate partial check
		parityTuningLoggerTesting('sectors '
				.$parityTuningCfg['parityProblemStartSector']
				.'-'
				.$parityTuningCfg['parityProblemEndSector']
				.', correct '
				.$parityTuningCfg['parityProblemCorrect']);
		updateCronEntries();
		$startSector = $parityTuningCfg['parityProblemStartSector'];
		$startSector -= ($startSector % 8);				// start Sector numbers must be multiples of 8
		$cmd = "mdcmd check " . ($parityTuningCfg['parityProblemCorrect'] == 0 ? 'no' : '') . "correct " . $startSector;
		parityTuningLoggerTesting("cmd:$cmd");
		exec ($cmd);
		loadVars(5);
		suppressMonitorNotification();
		parityTuningProgressWrite('PARTIAL');
		$runType = ($parityTuningCfg['parityProblemCorrect'] == 0) ? _('Non-Correcting') : _('Correcting');
		sendNotification(_("Partial parity check ($runType)"), parityTuningPartialRange());
    	break;

	// runs with 'md' devices valid and when array is about to be started
	// Other services dependent on array active are not yet started
	
    case 'array_started':
    	suppressMonitorNotification();
    	parityTuningLoggerDebug (_('Array is being started'));
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
			if (file_exists(PARITY_TUNING_PROGRESS_FILE)
			&&  file_exists(PARITY_TUNING_RESTART_FILE)) {
			    sendNotification (_('Array operation will not be restarted'), _('Unclean shutdown detected'), 'alert');
				parityTuningProgressWrite('RESTART CANCELLED');
				parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
			}
			parityTuningProgressWrite('ABORTED');
    	} 
    	break;

	// runs with when system startup complete and array is fully started
	
    case 'started':
        parityTuningLoggerDebug (_('Array has just been started'));

		// Sanity Checks on restart that mean restart will not be possible if they fail
		// (not sure these are all really necessary - but better safe than sorry!)
		if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			if (! file_exists(PARITY_TUNING_DISKS_FILE)) {
				parityTuningLogger(_('Something wrong: progress information present without disks file present'));
				goto end_started;
			}
        } else if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLogger(_('Something wrong: restart information present without progress present'));
		    goto end_started;
		}
		
		if (! file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLoggerTesting (_('No restart information present'));
			goto end_started;
		}
		
		// Get restart information
		
		$restart = parse_ini_file(PARITY_TUNING_RESTART_FILE);
		parityTuningLoggerTesting('restart information:');
		foreach ($restart as $key => $value) parityTuningLoggerTesting("$key=$value");
		// Not sure if next log point is even relevant?
		
		$restartPaused = ($restart['mdResync'] == 0);
		if ($restartPaused) {
		   parityTuningLoggerTesting(_('Array operation was paused when reboot initiatied'));
		}
		$restartPos = $restart['mdResyncPos'];
		$restartPos += $restartPos;				// convert from 1K units to 512-byte sectors
		$restartCorrect = $restart['mdResyncCorr'];
		$restartAction= $restart['mdResyncAction'];
		$adj = $restartPos % 8;					// Position must be mutiple of 8
		if ($adj != 0) {						// Not sure this can occur but better to play safe
			parityTuningLoggerTesting(sprintf('restartPos: %d, adjustment: %d', $restartPos, $adj));
			$restartPos -= $adj;
		}
		$restartDescription = actionDescription($restartAction, $restartCorrect);


		// Check the stored disk information against the current assignments
		//		0 (false)	Disks appear unchanged
		//		-1			New disk present (New Config used?)
		//		1			Disks changed in some other way

		$disksCurrent = parse_ini_file (PARITY_TUNING_EMHTTP_DISKS_FILE, true);
		$disksOld     = parse_ini_file (PARITY_TUNING_DISKS_FILE, true);
		$disksOK = 0;
		foreach ($disksCurrent as $drive) {
			$name=$drive['name'];
			if ((startsWith($name, 'parity')) || (startsWith($name,'disk'))) {
				if ($disksCurrent[$name]['status']  == 'DISK-NEW') {
					parityTuningLogger($name . ': ' . _('New'));
					$disksOK = -1;
				} else { 
					if (($disksCurrent[$name]['id']     != $disksOld[$name]['id'])
					||  ($disksCurrent[$name]['status'] != $disksOld[$name]['status'])
					||  ($disksCurrent[$name]['size']   != $disksOld[$name]['size'])) {
						if ($disksOK != 0) $disksOK = 1;
						parityTuningLogger($name . ': ' . _('Changed'));
					}
				}
			}
		}
		switch ($disksOK) {
			case 0:			
				parityTuningLoggerTesting ('Disk configuration appears to be unchanged');
				break;
			case 1:
				$msg = _('Detected a change to the disk configuration');
				parityTuningLogger ($msg);
				sendNotification ($restartDescription . ' ' . _('will not be restarted'), $msg, 'alert');
				goto end_norestart;
			case -1:
				$msg = _('Detected disk configuration reset');
				parityTuningLogger ($msg);
				sendNotification ($restartDescription . ' ' . _('will not be restarted'), $msg, 'alert');
				goto end_norestart;
		}

        // Handle restarting array operations

		parityTuningLoggerdebug('restart to be attempted');
		removeHistoryCancelEntry();		// Remove cancel entry from array shutdown 
		// Special case for resarting operations that are not parity checks and where Unraid
		// automatically starte the array operation again from the beginning so we need to cancel
		// it before restarting it at the offset previously reached.
		loadVars(1);
		if ($parityTuningActive) {
			parityTuningLoggerTesting("cancel automatically started array operation ($parityTuningAction) ready for restart");
			exec ('mdcmd nocheck');
			while ($parityTuningActive) loadVars(5);	// Wait while cancel is carried out
			parityTuningLoggerTesting('cancel completed');
			removeHistoryCancelEntry();	// Remove cancel entry from us cancelling operation
		} else {
			parityTuningLoggerTesting('no array operation currently active');
		}
		
		// set up restart command
		$cmd = 'mdcmd check ' . ($restartCorrect ? '' : 'NO') . 'CORRECT ' . $restartPos;
		suppressMonitorNotification();
		parityTuningLoggerTesting('restart command: ' . $cmd);
		exec ($cmd);
		loadVars(3);     // give time for any array operation to start running (TODO check time needed)
		// notification now array operation running
		suppressMonitorNotification();
		parityTuningProgressWrite('RESUME (RESTART)');
		$actionDescription =  $restartDescription . parityTuningCompleted();
		sendNotification(_('Array operation restarted'),  $actionDescription);
		if ($restartPaused) {
			parityTuningLoggerDebug(_('operation waas paused when reboot initiated so pause it immediately'));
			goto RUN_PAUSE;	
		}
		// NOTE:  Monitor process can pause it later as well if conditions for that are met.
		if (isRunInIncrements($restartAction) && (! isActivePeriod()) ) {
			parityTuningLoggerDebug(_('Outside time slot for running operation type'));
			// $actionDescription = $restartDescription;		// Update description due to restart
			goto RUN_PAUSE;
		}
		goto end_started;
		// FALL-THRU
end_norestart:
  		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			// This means we got here without attempting restart
  			parityTuningLogger(_('Restart will not be attempted'));
 			parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
 			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
 			    parityTuningProgressWrite('RESTART CANCELLED');
 			}
 		}
 		parityTuningDeleteFile(PARITY_TUNING_DISKS_FILE);
        parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
		parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
end_started:
		parityTuningProgressAnalyze();
		if (file_exists(PARITY_TUNING_TIDY_FILE)) {
			parityTuningLoggerTesting(_("does not appear to be an unclean shutdown"));
		} else {
			createMarkerFile (PARITY_TUNING_UNCLEAN_FILE);
			parityTuningLoggerTesting(_("appears to be an unclean shutdown"));
			if ($parityTuningNoParity) {
				parityTuningLoggerTesting(_("No parity present, so no automatic parity check"));
			} else {
				parityTuningInactiveCleanup();
				createMarkerFile (PARITY_TUNING_AUTOMATIC_FILE);
				sendNotification (sprintf('%s %s %s',
										_('Automatic unRaid'), 
										$restartDescription, 
										('will be started')), 
										_('Unclean shutdown detected'), 
										'warning');
				loadVars(5);
				suppressMonitorNotification();

				if (! $parityTuningCfg['parityTuningAutomatic']) {
					parityTuningLoggerTesting(_('Automatic parity check pause not configured'));
				} else {
					parityTuningLoggerTesting(_('Pausing Automatic parity check configured'));
					if (isActivePeriod()) {
						parityTuningLoggerTesting(_('... but in active period so leave running'));
					} else {
						parityTuningLoggerTesting(_('... outside active period so needs pausing'));
						loadVars(60);		// always let run for short time before pausing.
						parityTuningLoggerTesting(_('Pausing automatic parity check'));
						goto RUN_PAUSE;
					}
				}
			}
		}
		parityTuningDeleteFile(PARITY_TUNING_TIDY_FILE);
		parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
		// parityTuningDeleteFile(PARITY_TUNING_STARTING_FILE);
		if ($parityTuningAction == 'check') suppressMonitorNotification();
		break;

    case 'stopping':
        parityTuningLoggerDebug(_('Array stopping'));
        parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
        if (!isArrayOperationActive()) {
            parityTuningLoggerDebug (_('No array operation in progress so no restart information saved'));
            parityTuningProgressAnalyze();

        } else {
			parityTuningLoggerDebug (sprintf(_('Array stopping while %s was in progress %s'), $parityTuningDescription, parityTuningCompleted()));
		    parityTuningProgressWrite('STOPPING');
			sleep(1);
			if (! $parityTuningCfg['parityTuningRestart']) {
				parityTuningLoggerTesting('Restart option not set');
			} else {
				parityTuningLoggerTesting('Restart option set');
				parityTuningLoggerTesting("parityTuningAction=$parityTuningAction");
				parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
				$restart = 'mdResync=' . $parityTuningVar['mdResync'] . "\n"
						   .'mdResyncPos=' . $parityTuningVar['mdResyncPos'] . "\n"
						   .'mdResyncSize=' . $parityTuningVar['mdResyncSize'] . "\n"
						   .'mdResyncAction=' . $parityTuningVar['mdResyncAction'] . "\n"
						   .'mdResyncCorr=' . $parityTuningVar['mdResyncCorr'] . "\n"
						   .'startMode=' . $parityTuningVar['startMode'] . "\n"
						   .'triggerType=' . operationTriggerType() . "\n";
				file_put_contents (PARITY_TUNING_RESTART_FILE, $restart);
				parityTuningLoggerTesting('Restart information saved to ' . parityTuningMarkerTidy(PARITY_TUNING_RESTART_FILE));
				parityTuningProgressWrite('PAUSE (RESTART)');
				sendNotification(_('Array stopping: Restart will be attempted on next array start'), $parityTuningDescription
				.parityTuningCompleted());	
			}
			suppressMonitorNotification();
        }
        break;

	case 'stopping_array':
    	suppressMonitorNotification();
    	break;

	case 'stopped':
	    createMarkerFile (PARITY_TUNING_TIDY_FILE);
		sleep(1);  // Give some time for file operations to complete
		if (!file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
		}
    // Options that are only currently for CLI use

    case 'analyze':
        parityTuningProgressAnalyze();
        break;

    case 'status':
		parityTuningLogger(_('Status') . ': ' 
							. (isArrayOperationActive()
							   ? (ucfirst(strtolower(  operationTriggerType())) . ' ' . $parityTuningDescription . 
							    ($parityTuningRunning 
									? '' 
									: ' PAUSED ')
								. parityTuningCompleted())
							   : _('No array operation currently in progress')));
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
            parityTuningLogger(sprintf(_('Not allowed as %s already running'), $parityTuningDescription));
            break;
        }
        $parityTuningCorrecting =($command == 'correct') ? true : false;
		exec("/usr/local/sbin/mdcmd check $command");
        loadVars(2);
	    parityTuningLogger($parityTuningDescription . ' Started');
        if ($parityTuningAction == 'check' && ( $command == 'correct')) {
            if ($parityTuningNoParity) {
            	parityTuningLogger(_('Only able to start a Read-Check as no parity drive present'));
            } else {
            	parityTuningLogger(_('Only able to start a Read-Check due to number of disabled drives'));
            }
        }
	    break;

    case 'cancel':		// CLI Cancel request
        parityTuningLoggerDebug(_('Cancel request'));
        if (isArrayOperationActive()) {
            parityTuningLoggerTesting ('mdResyncAction=' . $parityTuningAction);
			exec('/usr/local/sbin/mdcmd "nocheck"');
            parityTuningLoggerDebug (sprintf(_('%s cancel request sent %s'), $parityTuningDescription, parityTuningCompleted()));
            loadVars(5);
            parityTuningProgressWrite('CANCELLED');
            parityTuningLogger(sprintf(_('%s Cancelled'),$parityTuningDescription));
            parityTuningProgressAnalyze();
        }

        break;

    case 'stop':
    case 'start':
        parityTuningLogger("$command " . _('option not currently implemented'));
        // fallthru to usage section


	// Potential unRaid event types on which no action is (currently) being taken by this plugin?
	// They are being caught at the moment so we can see when they actually occur.


	case 'starting':		// Sytem being started up (after initial load)
		// createMarkerFile(PARITY_TUNING_STARTING_FILE);
		suppressMonitorNotification();
		// consistency check
		if (file_exists(UNRAID_PARITY_SYNC_FILE)) {
			if (file_exists(PARITY_TUNING_TIDY_FILE) ) {
				parityTuningLoggerDebug('plugin and Unraid disagree on whether unclean shutdown');
				parityTuningDeleteFile (PARITY_TUNING_TIDY_FILE);
			} else {
				createMarkerFile(PARITY_TUNING_UNCLEAN_FILE);
			}
		}
	

		
	// The following could be commented out as not used by the plugin
	// (but still useful to see when these event fire)
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
    	break;

    case 'history':
		break;
		
    // Finally the error/usage case.   Hopefully we never get here in normal running when not using CLI
    case 'help':
    case '--help':
    default:
        parityTuningLogger ('');       // Blank line to help break up debug sequences
        parityTuningLogger (_('ERROR') . ': ' . _('Unrecognized option').' '.$command);
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
//		parityTuningLoggerCLI ('  history          ' . _('Display Parity History'));
		parityTuningLoggerCLI ($parityTuningVersion);
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

// -------------------------------- Support Functions  -------------------------------------

// Remove a file and if TESTING logging active then log it has happened
// For marker files sanitize the name to a friendlier form.
// Return true if file was deleted, and false if did not exist.

//       ~~~~~~~~~~~~~~~~~~~~~~
function parityTuningDeleteFile($name) {
//       ~~~~~~~~~~~~~~~~~~~~~~
 	if (file_exists($name)) {
		@unlink($name);
		parityTuningLoggerTesting('Deleted ' . parityTuningMarkerTidy($name));
		return true;
	}
	return false;
}

// Helps break debug information into blocks to identify entries for a given entry point

//       ~~~~~~~~~~~~~~~
function spacerDebugLine($start, $cmd) {
//       ~~~~~~~~~~~~~~~
    // Not sure if this should be active at DEBUG level of only at TESTING level?
    parityTuningLoggerTesting ('----------- ' . strtoupper($cmd) . ($start ? ' begin' : ' end') . ' ------');
}

// Convert an array of drive names/temperatures into a simple list
// for display.  If the drives to list are not supplied as a
// parameter attempt to get them from a saved file on the flash.
//       ~~~~~~~~~~
function listDrives($drives = null) {
//       ~~~~~~~~~~
	global $parityTuningTempUnit;
	$msg = '';
	if (is_null($drives)) {
		$msg = file_get_contents(PARITY_TUNING_HOT_FILE);
		if ($msg == false) $msg = '';
	} else {
		foreach ($drives as $key => $value) {
			$msg .= $key . '(' . ($value=='*'?'*':tempInDisplayUnit($value) . $parityTuningTempUnit) . ') ';
		}
	}
    return $msg;
}

// Get the temperature in display units from Celsius

//		 ~~~~~~~~~~~~~~~~~
function tempInDisplayUnit($temp) {
//		 ~~~~~~~~~~~~~~~~~
	global $parityTuningTempUnit;
	if ($parityTuningTempUnit == 'C') {
		return $temp;
	}
	return round(($temp * 1.8) + 32);
}

// Get the temperature in Celsius compensating
// if needed for fact display in Fahrenheit

//		 ~~~~~~~~~~~~~~~~~~
function tempFromDisplayUnit($temp) {
//		 ~~~~~~~~~~~~~~~~~~
	global $parityTuningTempUnit;
	if ($parityTuningTempUnit == 'C') {
		return $temp;
	}
	return round(($temp-32)/1.8);
}



// Write an entry to the progress file that is used to track increments
// This file is created (or added to) any time we detect a running array operation
// It is removed any time we detect there is no active operation so it contents track the operation progress.

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressWrite($msg, $filename=PARITY_TUNING_PROGRESS_FILE) {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningVar, $parityTuningDescription;
	global $parityTuningAction, $parityTuningCorrecting;
	
	static $fnLock = false;
	while ($fnLock) usleep(100000);
	$fnLock=true;
	
    parityTuningLoggerTesting ($msg . ' record to be written');
    // List of fields we save for progress.
	// Might not all be needed but better to have more information than necessary
	$progressFields = array('sbSynced','sbSynced2','sbSyncErrs','sbSyncExit',
	                       'mdState','mdResync','mdResyncPos','mdResyncSize','mdResyncCorr','mdResyncAction' );

    // Not strictly needed to have header but a useful reminder of the fields saved
    if (! file_exists($filename)) {
		copy (PARITY_TUNING_EMHTTP_DISKS_FILE, PARITY_TUNING_DISKS_FILE);
		parityTuningLoggerTesting('Current disks information saved to ' . parityTuningMarkerTidy(PARITY_TUNING_DISKS_FILE));
        $line = 'type|date|time|';
        foreach ($progressFields as $name) $line .= $name . '|';
        $line .= "Description\n";
		file_put_contents($filename, $line);
		parityTuningLoggerTesting ('written header record to  ' . parityTuningMarkerTidy($filename));
		$trigger = operationTriggerType();
		if ($trigger != $msg) {
			parityTuningLoggerTesting ("add $trigger record type to Progress file");
			$line = $trigger . '|' . date(PARITY_TUNING_DATE_FORMAT) . '|' . time() . '|';
			foreach ($progressFields as $name) $line .= $parityTuningVar[$name] . '|';
			$line .= "$parityTuningDescription|\n";
			file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
		}
    }
    $line = $msg . '|' . date(PARITY_TUNING_DATE_FORMAT) . '|' . time() . '|';
    foreach ($progressFields as $name) $line .= $parityTuningVar[$name] . '|';
    $line .= "$parityTuningDescription|\n";
	file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    parityTuningLoggerTesting ('written ' . $msg . ' record to  ' . parityTuningMarkerTidy($filename));
	$fnLock=false;
}

//  Function that looks to see if a previously running array operation has finished.
//  If it has analyze the progress file to create a history record.
//  We then update the standard unRaid history file.  
//	If needed we patch an existing record.

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressAnalyze() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $parityTuningVar;
	global $parityTuningSizeInHistory;

    // TODO: This may need revisiting if decided that partial checks should be recorded in History
    if (parityTuningPartial()) {
        parityTuningLoggerTesting(' ignoring progress file as was for partial check');
    	parityTuningDeleteFile(PARITY_TUNING_PROGRESS_FILE);
		return;
    }
	// This is safety check - in ideal world we would not get here
    if (file_exists(PARITY_TUNING_RESTART_FILE)) {
	    parityTuningLoggerTesting(' restart pending - so not time to analyze progress');
        return;
    }
    if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
        parityTuningLoggerTesting('No progress file to analyze');
		parityTuningDeleteFile(PARITY_TUNING_UNCLEAN_FILE);
        return;
    }
	// This is safety check - in ideal world we would not get here
    if ($parityTuningVar['mdResyncPos'] != 0) {
        parityTuningLoggerTesting(' array operation still running - so not time to analyze progress');
        return;;
    }
	static $fnLock = false;
	if ($fnLock) return;
	$fnLock=true;
    spacerDebugLine(true, 'PROGRESS_ANALYZE');
    parityTuningLoggerTesting('Previous array operation finished - analyzing progress information to create history record');

	if (rename (PARITY_TUNING_PROGRESS_FILE, PARITY_TUNING_PROGRESS_SAVE)) {
		parityTuningLoggerDebug('Old progress file available as ' . PARITY_TUNING_PROGRESS_SAVE);
	} else {
		// I think This should only happen if there is a race condition occuring?
		parityTuningLoggerDebug('rename of progress file failed, so abandon analyze' . PARITY_TUNING_PROGRESS_SAVE);
		goto exit_analyze;
	}
    // Work out history record values
    $lines = file(PARITY_TUNING_PROGRESS_SAVE);

    if ($lines == false){
        parityTuningLoggerDebug('failure reading Progress file - analysis abandoned');
        goto exit_analyze;;        // Cannot analyze a file that cannot be read!
    }
    // Check if file was completed
    // TODO:  Consider removing this check when analyze fully debugged
    if (count($lines) < 2) {
        parityTuningLoggerDebug('Progress file appears to be incomplete');
        goto exit_analyze;;
    }
    $line = $lines[count($lines) - 1];
    if ((! startsWith($line,'COMPLETED')) && (!startsWith($line,'CANCELLED')) && (!startsWith($line,'ABORTED'))) {
        $endType = file_exists(PARITY_TUNING_UNCLEAN_FILE) ? 'ABORTED' : 'COMPLETED';
        parityTuningLoggerDebug("missing completion line in Progress file - add $end$YPE and restart analyze");
        parityTuningProgressWrite($endType,PARITY_TUNING_PROGRESS_SAVE );
		$lines = file(PARITY_TUNING_PROGRESS_SAVE);	// Reload file
    }
    $duration = $elapsed = $increments = $corrected = $size = 0;
    $thisStart = $thisFinish = $thisElapsed = $thisDuration = $thisOffset = 0;
    $lastFinish = $exitCode = $firstSector = $reachedSector = 0;

	$triggerType = '';
    $mdResyncAction = '';
	$mdStartAction = '';		// This is to handle case where COMPLETE record has wrong type
    foreach ($lines as $line) {
    	parityTuningLoggerTesting("$line");
        list($op,$stamp,$timestamp,$sbSynced,$sbSynced2,$sbSyncErrs, $sbSyncExit, $mdState,
             $mdResync, $mdResyncPos, $mdResyncSize, $mdResyncCorr, $mdResyncAction, $desc) = explode ('|',$line);
		if ($op === 'type') {
			parityTuningLoggerTesting("ignore header record");
			continue;
		}
		// A progress file can have a time offset which we can determine by comparing text and binary timestamps
		// (This will only be relevant when testing Progress files submitted as part of a problem report)
        if (! $increments) {
        	$temp = strtotime(substr($stamp, 9, 3) . substr($stamp,4,4) . substr($stamp,0,5) . substr($stamp,12));
			if ($temp) {		// ignore any heading line
				// parityTuningLoggerTesting ("Progress temp = $temp, timestamp=$timestamp");
				$thisOffset = $temp - $timestamp;  // This allows for diagnostic files from a different timezone when debugging
				if ($thisOffset != 0) parityTuningLoggerTesting ("Progress time offset = $thisOffset seconds");
			}
        }
		if ($mdResyncSize > $size) {
			parityTuningLoggerTesting("Size reset from $size to $mdResyncSize");
			$size = $mdResyncSize;
		}
		if (! isset($correcting)) {
			parityTuningLoggerTesting("correcting set to $mdResyncCorr");
			$correcting = $mdResyncCorr;
		}
	
        switch ($op) {
        	case 'SCHEDULED':
        			$triggerType = $op;
					$startAction = $mdResyncAction;
        			break;
        	case 'AUTOMATIC':
			        $triggerType = $op;
					$startAction = $mdResyncAction;
					break;
        	case 'MANUAL':
        	        $triggerType = $op;
					$startAction = $mdResyncAction;
        	        break;
        	case 'UNKNOWN':
        	case 'type':    // TODO: This record type could probably be removed when debugging complete
        			break;

			CASE 'PARTIAL':
					$firstSector = $parityTuningCfg['parityTuningStartSector'];
					break;

            case 'STARTED': // TODO: Think can be ignored as only being documentation?
            		if ($timestamp) $thisStart =  $thisFinish = $lastFinish = ($timestamp  + $thisOffset);
            		$increments = 1;		// Must be first increment!
					parityTuningLoggerTesting("thisStart=$thisStart, thisFinish=$thisFinish, lastFinish=$lastFinish, thisDuration=$thisDuration"
											  . ",\n duration=$duration, elapsed=$elapsed, corrected=$corrected, exitCode=$exitCode");
                    break;

             // TODO:  Decide if we really need all these types if we treat them the same (although useful for debugging)!
			case 'RESUME (COOL)':	// should always be followed by standard RESUME so can ignore
					break;
            case 'RESUME':
            case 'RESUME (RESTART)':
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
			case 'PAUSE (HOT)':	// should always be followed by standard PAUSE so can ignore.
					break;
			case 'COMPLETED':
			case 'CANCELLED':
            case 'PAUSE':
            case 'PAUSE (RESTART)':

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
        					// (or unRaid version too old) so ignore this record
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
        case -4: $exitStatus = _("canceled");
                 break;
        case -5: $exitStatus = _("aborted");
        		 break;
        default: $exitStatus = _("exit code") . ": " . $exitCode;
        		 break;
    }
		
	if ($increments == 0) {
    	parityTuningLoggerTesting('no increments found so no need to patch history file');
    } else if ($exitCode == -5) {
		// TODO:	Not sure about this - may want to revisit
    	parityTuningLoggerTesting('aborted so no need to patch history file');
    } else {
	    $completed = ' ' . ($mdResyncSize ? sprintf ("%.1f%%", ($reachedSector - $firstSector/$mdResyncSize*100)) : '0') . _(' completed');
		parityTuningLoggerTesting("ProgressFile start:" . date(PARITY_TUNING_DATE_FORMAT,$thisStart) . ", finish:" . date(PARITY_TUNING_DATE_FORMAT,$thisFinish) . ", $exitStatus, $completed");
		$unit='';
		if ($reachedSector == 0) $reachedSector = $mdResyncSize;		// position reverts to 0 at end
		$speed = my_scale(($reachedSector * (1024 / $duration)), $unit,1);
		$speed .= " $unit/s";
		parityTuningLoggerTesting("totalSectors: $mdResyncSize, duration: $duration, speed: $speed");
		// send Notification about operation
		$actionType = actionDescription($startAction, $mdResyncCorr, $triggerType, true);
		$msg  = sprintf(_('%s %s (%d %s)'),
						$actionType, $exitStatus, $corrected, _('errors'));
		$desc = sprintf(_('%s %s, %s %s, %s %d, %s %s'),
						_('Elapsed Time'),this_duration($elapsed),
						_('Runtime'), this_duration($duration),
						_('Increments'), $increments,
						_('Average Speed'),$speed);
		parityTuningLogger($msg);
		parityTuningLogger($desc);
		sendNotification($msg, $desc, ($exitCode == 0 ? _('normal') : _('warning')));
		
		// Now we want to patch the entry in the standard parity history file
		suppressMonitorNotification();
		$lines = file(UNRAID_PARITY_HISTORY_FILE, FILE_SKIP_EMPTY_LINES);
		$matchLine = 0;
		$action = "";
		while ($matchLine < count($lines)) {
			$line = $lines[$matchLine];
			list($logstamp,$logduration, $logspeed,$logexit, $logerrors, $action) = explode('|',$line);
			$logtime = strtotime(substr($logstamp, 9, 3) . substr($logstamp,4,4) . substr($logstamp,0,5) . substr($logstamp,12));
			// parityTuningLoggerTesting('history line ' . ($matchLine+1) . " $logstamp, logtime=$logtime=" . date(PARITY_TUNING_DATE_FORMAT,$logtime));
			if ($logtime > $thisStart) {
				parityTuningLoggerTesting ("looks like line " . ($matchLine +1) . " is the one to update, logtime=$logtime  . " . date(PARITY_TUNING_DATE_FORMAT,$logtime) . ')');
				parityTuningLoggerTesting ($line);
				if ($logtime <= $thisFinish) {
					parityTuningLoggerTesting ('update log entry on line ' . ($matchLine+1),", errors=$logerrors");
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
		} // end while loop
		if ($matchLine == count($lines))  parityTuningLoggerTesting('no match found in existing log so added a new record ' . ($matchLine + 1));
		$type = explode(' ',$desc);
		$gendate = date(PARITY_TUNING_DATE_FORMAT,$lastFinish);
		if ($gendate[9] == '0') $gendate[9] = ' ';  // change leading 0 to leading space
		// generate replacement parity history record
		$generatedRecord = $gendate.'|'.$duration.'|'.$speed.'|'.$exitCode.'|'.$corrected.'|'.$action;
		// Extra field included as standard on Unraid 6.11 or later
		if ($parityTuningSizeInHistory) {
			parityTuningLoggerTesting('add size to history record: '. $size); 
			$generatedRecord .= '|'.$size;
		}
		$pluginExtra='|'.$elapsed.'|'.$increments.'|'.$actionType;
		parityTuningLoggerTesting('add plugin specific fields history record: '.$pluginExtra); 
		$generatedRecord .= $pluginExtra."\n";
		parityTuningLoggerTesting('log record generated from progress: '. $generatedRecord);    
		$lines[$matchLine] = $generatedRecord;
		$myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
		file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);  // Save for debug purposes
		file_put_contents(UNRAID_PARITY_HISTORY_FILE,$lines);
	}
	parityTuningDeleteFile(PARITY_TUNING_UNCLEAN_FILE);
	parityTuningDeleteFile(PARITY_TUNING_TIDY_FILE);
	updateCronEntries();
exit_analyze:
	spacerDebugLine(false, 'PROGRESS_ANALYZE');
	$fnLock = false;
}

//	Remove the last entry from the Parity History file that has been generated by a restart.

function removeHistoryCancelEntry() {
	$lines = file(UNRAID_PARITY_HISTORY_FILE);
	$lastline = count($lines) - 1;
	$line = $lines[$lastline];			// get last line
	parityTuningLoggerTesting ("Last History Line $lastline: $line");
	//  Remove it if it contains a cancellation error code entry
	if (strpos($line,'|-4|')) {
		unset($lines[$lastline]);
		file_put_contents(UNRAID_PARITY_HISTORY_FILE, $lines, LOCK_EX);
		parityTuningLoggerTesting ("Removed last (Cancelled) entry from History");
	} else {
		parityTuningLoggerTesting ("Last History entry is not a Cancelled one!");
	}
}

// /following 2 functions copied from parity history script

//       ~~~~~~~~
function this_plus($val, $word, $last) {
//       ~~~~~~~~
  return $val>0 ? (($val||$last)?($val.' '.$word.($last?'':', ')):'') : '';
}

//       ~~~~~~~~~~~~
function this_duration($time) {
//       ~~~~~~~~~~~~
  if (!$time) return 'Unavailable';
  $days = floor($time/86400);
  $hmss = $time-$days*86400;
  $hour = floor($hmss/3600);
  $mins = $hmss/60%60;
  $secs = $hmss%60;
  return this_plus($days,_('day'),($hour|$mins|$secs)==0).this_plus($hour,_('hr'),($mins|$secs)==0).this_plus($mins,_('min'),$secs==0).this_plus($secs,_('sec'),true);
}

function opWithErrorCount ($op) {
	global $parityTuningErrors;
	return ($op . ($parityTuningErrors > 0 ? " ($parityTuningErrors " . _('errors') . ')' : ''));
}

// send a notification without checking if enabled in plugin settings
// (assuming even enabled at the system level)

//       ~~~~~~~~~~~~~~~~
function sendNotification($msg, $desc = '', $type = 'normal') {
//       ~~~~~~~~~~~~~~~~
	global $dynamixCfg, $docroot;
	global $parityTuningServer;
    parityTuningLogger (_('Send notification') . ': ' . "$msg: $desc " . parityTuningCompleted());
    if ($dynamixCfg['notify']['system'] == "" ) {
    	parityTuningLogger (_('... but suppressed as system notifications do not appear to be enabled'));
    } else {
        $cmd = $docroot. '/webGui/scripts/notify'
        	 . ' -e ' . escapeshellarg(parityTuningPartial() ? "Parity Problem Assistant" : "Parity Check Tuning")
        	 . ' -i ' . escapeshellarg($type)
	    	 . ' -l ' . escapeshellarg("/Settings/Scheduler")
	    	 . ' -s ' . escapeshellarg('[' . $parityTuningServer . "] $msg")
	         . ($desc == '' ? '' : ' -d ' . escapeshellarg($desc));
    	parityTuningLoggerTesting (_('... using ') . $cmd);
    	exec ($cmd);
    }
}

// send a notification without checking if enabled.  Always add point reached.

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
function sendNotificationWithCompletion($op, $desc = '', $type = 'normal') {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningDescription;
    sendNotification ($op, $desc . (strlen($desc) > 0 ? '<br>' : '') . $parityTuningDescription . parityTuningCompleted(), $type);
}

// Send a notification if increment notifications enabled

//       ~~~~~~~~~~~~~~~~~~~~~
function sendArrayNotification ($op) {
//       ~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $parityTuningDescription;
    parityTuningLoggerTesting("Pause/Resume notification message: $op");
    if ($parityTuningCfg['parityTuningNotify'] == 0) {
        parityTuningLoggerTesting (_('... but suppressed as notifications do not appear to be enabled for pause/resume'));
        parityTuningLogger($op . ": " . $parityTuningDescription
    							 	  . parityTuningCompleted());		// Simply log message if not notifying
        return;
    }
    sendNotificationWithCompletion($op);
}

// Send a notification if temperature related notifications enabled

//       ~~~~~~~~~~~~~~~~~~~~
function sendTempNotification ($op, $desc, $type = 'normal') {
//       ~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;
    parityTuningLoggerTesting("Heat notification message: $op: $desc");
    if ($parityTuningCfg['parityTuningHeatNotify'] == '0') {
        parityTuningLogger($op);			// Simply log message if not notifying
        parityTuningLogger($desc);
        return;
    }
    sendNotificationWithCompletion($op, $desc, $type);
}

// Suppress notifications about array operations from monitor task
// (duplicate processing from task but without notification specific steps)
// Should also stop monitor from adding parity history entries
// TODO: Check for each unRaid release that there are not version dependent changes

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function suppressMonitorNotification() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
  global $parityTuningVar;

  $ram    = "/var/local/emhttp/monitor.ini";
  $rom    = "/boot/config/plugins/dynamix/monitor.ini";
  $saved  = @parse_ini_file($ram,true);
  $item = 'array';
  $name = 'parity';
  $last = $saved[$item][$name] ?? '';
  if ($parityTuningVar['mdResyncPos']) {
    if (!$last) {
      if (strstr($parityTuningVar['mdResyncAction'],"recon")) {
        $last = 'Parity sync / Data rebuild';
      } elseif (strstr($parityTuningVar['mdResyncAction'],"clear")) {
        $last = 'Disk clear';
      } elseif ($parityTuningVar['mdResyncAction']=="check") {
        $last = 'Read check';
      } elseif (strstr($parityTuningVar['mdResyncAction'],"check")) {
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


// Remove the files (if present) that are used to indicate trigger type for array operation

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningInactiveCleanup() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	$ret  = parityTuningDeleteFile(PARITY_TUNING_PARTIAL_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_AUTOMATIC_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_MOVER_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);
	if ($ret) updateCronEntries();
	return $ret;
}

//  give the range for a partial parity check (in sectors or percent as appropriate)

//       ~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningPartialRange() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;
	if ($parityTuningCfg['parityProblemType'] == "sector") {
		$range = _('Sectors') . ' ' . $parityTuningCfg['parityProblemStartSector'] . '-' .  $parityTuningCfg['parityProblemEndSector'];
	} else {
		$range = $parityTuningCfg['parityProblemStartPercent'] . '%-' . $parityTuningCfg['parityProblemEndPercent'] . '%';
	}
	return _('Range') . ': ' . $range;
}

//       ~~~~~~~~~~~~~~~~~~~~~
function parityTuningCompleted() {
//       ~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningSize, $parityTuningPos;

    $ret = ' ('
    	   . (($parityTuningSize > 0)
    				? sprintf ("%.1f", ($parityTuningPos/$parityTuningSize*100)) : '0' )
    	   . '% ' .  _('completed') . ')';
	parityTuningLoggerTesting("pos= parityTuningPos, size=$parityTuningSize, $ret");
	return $ret;
}

// Confirm that a pause or resume is valid according to user settings
// and the type of array operation currently in progress

//       ~~~~~~~~~~~~~~~~
function configuredAction() {
//       ~~~~~~~~~~~~~~~~
	global $parityTuningCfg;
	global $parityTuningAction, $parityTuningDescription;
	global $parityTuningCorrecting;

	// check configured options against array operation type in progress

    $triggerType = operationTriggerType();
    if (startsWith($$parityTuningAction,'recon')) {
    	$result = $parityTuningCfg['parityTuningRecon'];
    } else if (startsWith($parityTuningAction,'clear')) {
    	$result = $parityTuningCfg['parityTuningClear'];
    } else if (! startsWith($parityTuningAction,'check')) {
    	parityTuningLoggerTesting("ERROR: unrecognized action type $parityTuningAction");
    	$result = false;
    } else {
		switch ($triggerType) {
			case 'SCHEDULED':
					$result = $parityTuningCfg['parityTuningScheduled'];
					break;
			case 'AUTOMATIC':
					$result = $parityTuningCfg['parityTuningAutomatic'];
					break;
			case 'MANUAL':
					$result = $parityTuningCfg['parityTuningManual'];
					break;
			default:
					// Should not be possible to get here?
					parityTuningLoggerTesting("ERROR: unrecognized trigger type for $action");
					$result = false;
					break;
		}
	}
	parityTuningLoggerTesting("configuredAction()=$result ($parityTuningDescription)");
    return $result;
}
																																		
// Check if an array operation is currently in progress
// Returns true even if it is currently paused

//       ~~~~~~~~~~~~~~~~~~~~~~
function isArrayOperationActive() {
//       ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCLI;
	global $parityTuningActive;
	global $parityTuningPos;
	// if (file_exists(PARITY_TUNING_RESTART_FILE)) {
	// 	parityTuningLoggerTesting ('Restart file found - so treat as isArrayOperationActive=true');
	// 	return true;
	// }
	parityTuningLoggerTesting("parityTuningActive=$parityTuningActive, parityTuningPos=$parityTuningPos");
	if (file_exists(PARITY_TUNING_RESTART_FILE)) {
		parityTuningLoggerTesting('  restart pending so treat as array operation active');	
	} else if (!$parityTuningActive) {
		$msg = 'No action outstanding';
		if ($parityTuningCLI) {
			parityTuningLogger("$msg");
		} else {
			parityTuningLoggerTesting($msg);
			parityTuningProgressAnalyze();
		}
		return false;
	}
	return true;
}

// Determine if the current time is within a period specified for increments to be active

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function isActivePeriod() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;
	global $parityTuningActive;
	
	// If no array operation active or pending restart then no need to look further
	
	parityTuningLoggerTesting('Check if within increment active period');
	parityTuningLoggerTesting("... Active=$parityTuningActive, Action=$parityTuningAction, Restart=".file_exists(PARITY_TUNING_RESTART_FILE));
	if (! $parityTuningActive) {
		$inPeriod=false;
	} else {
		$resumeTime = ($parityTuningCfg['parityTuningResumeHour'] * 60) + $parityTuningCfg['parityTuningResumeMinute'];
		$pauseTime  = ($parityTuningCfg['parityTuningPauseHour'] * 60) + $parityTuningCfg['parityTuningPauseMinute'];
		$currentTime = (date("H") * 60) + date("i");
		parityTuningLoggerTesting(".. PauseTIme=$pauseTime, Resumetime=$resumeTime, currentTime=$currentTime");
		if ($pauseTime > $resumeTime) {         // We need to allow for times spanning midnight!
			$inPeriod =($currentTime < $resumeTime) && ($currentTime > $pauseTime);
		} else {
			$inPeriod =($currentTime > $resumeTime) && ($currentTime < $pauseTime);
		}
	}
	parityTuningLoggerTesting('isAcivePeriod()='.$inPeriod);
	return $inPeriod;
}

//	Determine if the given operation type is set to be run in run in increments.

//       ~~~~~~~~~~~~~~~~~
function isRunInIncrements($action) {
//       ~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;

	$trigger = operationTriggerType();
	switch (strtolower($action[0])) {
		case 'recon':	$ret = ($parityTuningCfg['parityTuningRecon'] === '1');
						break;
		case 'clear':   $ret = ($parityTuningCfg['parityTuningClear'] === '1');
						break;
		case 'check':   
						switch ($trigger) {
							case 'SCHEDULED': $ret=($parityTuningCfg['parityTuningScheduled'] === '1');
											  break;
							case 'MANUAL':	  $ret=($parityTuningCfg['parityTuningManual'] === '1');
											  break;
							case 'AUTOMATIC': $ret=($parityTuningCfg['parityTuningAutomatic'] === '1');
											  break;
						}
						break;
		default:
						$ret = false; 
						break;
	}
	parityTuningLoggerTesting("isRunInIncrements($action)=".$ret." (trigger type=$trigger)" );
	return $ret;
}

function isIncrementActive($action) {

	if (isRunInIncrements($action)) 	return false;
	return isActivePeriod();
}


//       ~~~~~~~~~~~~~~~~~
function updateCronEntries() {
//       ~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $parityTuningActive;
	
	parityTuningLoggerTesting("Creating required cron entries");
	$lines = [];
	$lines[] = "\n# Generated schedules for " . PARITY_TUNING_PLUGIN . "\n";


		if ($parityTuningCfg['parityTuningScheduled'] || $parityTuningCfg['parityTuningManual']) {
			switch ($parityTuningCfg['parityTuningFrequency']) {
				case 1: // custom
					$resumetime = $parityTuningCfg['parityTuningResumeCustom'];
					$pausetime  = $parityTuningCfg['parityTuningPauseCustom'];
					break;
				case 0: // daily
					$resumetime = $parityTuningCfg['parityTuningResumeMinute'] . ' '
								. $parityTuningCfg['parityTuningResumeHour'] . ' * * *';
					$pausetime  = $parityTuningCfg['parityTuningPauseMinute'] . ' '
								. $parityTuningCfg['parityTuningPauseHour'] . ' * * *';
					break;
				case 2: // weekly
					$resumetime = $parityTuningCfg['parityTuningResumeMinute'].' '
								. $parityTuningCfg['parityTuningResumeHour'].' * * '
								. $parityTuningCfg['parityTuningResumeDay'];
					$pausetime  = $parityTuningCfg['parityTuningPauseMinute'].' '
								. $parityTuningCfg['parityTuningPauseHour'].' * * '
								. $parityTuningCfg['parityTuningPauseDay'];
					break;
				default:  // Error?
					parityTuningLoggerDebug("Invalid frequency value: ".$parityTuningCfg['parityTuningFrequency']);
					break;
			}
			$lines[] = "$resumetime " . PARITY_TUNING_PHP_FILE . ' "resume" &> /dev/null' . "\n";
			$lines[] = "$pausetime " . PARITY_TUNING_PHP_FILE . ' "pause" &> /dev/null' . "\n";
			parityTuningLoggerDebug (sprintf(_('Created cron entry for %s'),_('scheduled pause and resume')));
		}
		// Decide on monitor frequency 

		if (parityTuningPartial()) {
			// Partial checks (default = 1 minute)
			$frequency=$parityTuningCfg['parityTuningMonitorPartial'];
		} else {
		if ($parityTuningActive) {
			// Array operation already active (default = 6 minutes)
			$frequency=$parityTuningCfg['parityTuningMonitorBusy'];
		} else if ($parityTuningCfg['parityTuningHeat'] 
			// check temperatures (default = 7 minutes)
			   || $parityTuningCfg['parityTuningShutdown']) {
			$frequency=$parityTuningCfg['parityTuningMonitorHeat'];
		} else {
			// Default if not monitoring more frequently for other 
			// reasons (default = 17 minutes)
			$frequency=$parityTuningCfg['parityTuningMonitorDefault'];
		}
	}
	parityTuningLoggerDebug (sprintf(_('Created cron entry for %s interval monitoring'), $frequency.' '._('minute')));
	$lines[] = "*/$frequency * * * * " . PARITY_TUNING_PHP_FILE . ' "monitor" &>/dev/null' . "\n";
	file_put_contents(PARITY_TUNING_CRON_FILE, $lines);
	parityTuningLoggerDebug(sprintf(_('updated cron settings are in %s'),PARITY_TUNING_CRON_FILE));
	// Activate any changes
	exec("/usr/local/sbin/update_cron");
}

//	Determine if mover currently active
function isMoverRunning() {
	unset ($output);
	exec ("ps -ef | grep mover", $output);
	return (count($output)> 2);
}

function isCABackupRunning() {
	return (file_exists('/tmp/ca.backup2/tempFiles/backupInProgress')|file_exists('/tmp/ca.backup2/tempFiles/restoreInProgress'));
}

?>
