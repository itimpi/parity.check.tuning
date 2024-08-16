#!/usr/bin/php
<?PHP
/*
 * Script parity,check.tuning.php that is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file command; or from another script.
 *
 * It takes a parameter describing the action required.
 *
 * In can also be called via CLI as the command 'parity.check' to expose functionality
 * that relates to parity checking.
 *
 * Copyright 2019-2024, Dave Walker (itimpi).
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

require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';

// Work out what type of action triggered calling this script
if (empty($argv)) {
  parityTuningLoggerDebug(_("ERROR") . ": " . _("No action specified"));
  exit(0);
}

$command = (count($argv) > 1) ? trim($argv[1]) : '?';
spacerDebugLine(true, $command);

// Normally cron triggers actions on minute boundaries and this can lead to two different
// invocations of this script running in parallel.  Adding a random delay to the monitor 
// tasks which are not time critical is an attempt to stop simultaneous calls interleaving
// although if they do things should still operate OK, but the logs look a lot tidier 
// and are easier to interpret.

$parityTuningCLI = isset($argv)?(basename($argv[0]) == 'parity.check'):false;
if (isset($argv) && (strcasecmp(trim($argv[1]),'monitor') == 0) && !$parityTuningCLI) {
	sleep (rand(15,45));		// Desynchonize calls via cron
	loadVars();					// Allow for fact these might have changed while sleeping.
}

// Show the start of the action in the logs requested via the command line argument(s)
// Effectively each command line option is like an event type1

if ($parityTuningCLI) parityTuningLoggerTesting("CLI Mode active");

// Some useful constants local to this file
// Marker files are used to try and inicate state type information
define('UNRAID_PARITY_SYNC_FILE',      '/boot/config/forcesync');		        // Presence of file used by Unraid to detect unclean Shutdown (we currently ignore)
define('UNRAID_PARITY_HISTORY_FILE',   '/boot/config/parity-checks.log');		// File that holds history of array operations  
define('PARITY_TUNING_CRON_FILE',      PARITY_TUNING_FILE_PREFIX . 'cron');	    // File created to hold current cron settings for this plugin
define('PARITY_TUNING_PROGRESS_FILE',  PARITY_TUNING_FILE_PREFIX . 'progress'); // Created when array operation active to hold increment info
define('PARITY_TUNING_PROGRESS_SAVE',  PARITY_TUNING_FILE_PREFIX . 'progress.save');// Created when analysis completed
define('PARITY_TUNING_INCREMENT_FILE',  PARITY_TUNING_FILE_PREFIX . 'increment');// Present when within increment times
define('PARITY_TUNING_MOVER_FILE',     PARITY_TUNING_FILE_PREFIX . 'mover');	 // Present when paused because mover is running
define('PARITY_TUNING_BACKUP_FILE',    PARITY_TUNING_FILE_PREFIX . 'backup');	// Present when paused because CA Backup is running
define('PARITY_TUNING_HOT_FILE',       PARITY_TUNING_FILE_PREFIX . 'hot');	    // Present when paused because at least one drive found to have reached 'hot' temperature
define('PARITY_TUNING_CRITICAL_FILE',  PARITY_TUNING_FILE_PREFIX . 'critical'); // Created when parused besause at least one drive found to reach critical temperature
define('PARITY_TUNING_DISKS_FILE',     PARITY_TUNING_FILE_PREFIX . 'disks');    // Copy of disks.ini  info saved to allow check if disk configuration changed
define('PARITY_TUNING_TIDY_FILE',      PARITY_TUNING_FILE_PREFIX . 'tidy');	    // Create when we think there was a tidy shutdown
define('PARITY_TUNING_SHUTDOWN_FILE',  PARITY_TUNING_FILE_PREFIX . 'shutdown');	// Create when shutdown required after array operation
define('PARITY_TUNING_STOPPING_FILE',  PARITY_TUNING_FILE_PREFIX . 'stopping');	// Create when array stop is initiated
define('PARITY_TUNING_SPINUP_FILE',    'ParityTuningSpinup');					// Create when we think drive needs spinning up


// This plugin will never do anything if array is not started
// TODO Check if Maintenance mode has a different value for the state

// if (($var['mdState'] != 'STARTED' & (! $command == 'updatecron')) {
//     parityTuningLoggerTesting ('mdState=' . $var['mdState']);
//     parityTuningLoggerTesting(_('Array not started so no action taken'));
//     exit(0);
// }


// check for presence of any plugin marker files that can
// (optionally) exist on flash drive indicating status (useful 
// to know when testing the plugin

$filesToCheck = array(UNRAID_PARITY_SYNC_FILE,
   					  PARITY_TUNING_TIDY_FILE,
					  PARITY_TUNING_PROGRESS_FILE,
//					  PARITY_TUNING_PROGRESS_SAVE,
					  PARITY_TUNING_AUTOMATIC_FILE,
					  PARITY_TUNING_MANUAL_FILE,
					  PARITY_TUNING_SCHEDULED_FILE,
					  PARITY_TUNING_INCREMENT_FILE,
					  PARITY_TUNING_RESTART_FILE,
		  			  PARITY_TUNING_BACKUP_FILE,
					  PARITY_TUNING_PARTIAL_FILE, 	
					  PARITY_TUNING_DISKS_FILE,
					  PARITY_TUNING_HOT_FILE,
					  PARITY_TUNING_CRITICAL_FILE,
					  PARITY_TUNING_STOPPING_FILE,
					  PARITY_TUNING_SHUTDOWN_FILE, 
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

	//--------------------------------- PHP triggered ----------------------------
	//						Options triggered by PHP of shell scripts
	
	case 'defaults':
		// reset user configuration to the defaults issued with plugin
		@copy (PARITY_TUNING_DEFAULTS_FILE,PARITY_TUNING_CFG_FILE);
		parityTuningLogger(_('Settings reset to default values'));
		// FALLTHRU
	case 'config':
		parityTuningLogger (sprintf( _('Versions: Unraid %s, Plugin %s'),$parityTuningUnraidVersion,substr($parityTuningVersion,0,-1)));
		parityTuningLogger(_('Configuration'));
		parityTuningLogger(print_r($parityTuningCfg,true));
		// FALLTHRU
	case 'updatecron':
		// set up cron entries based on current configuration values
		updateCronEntries();
        break;

    case 'mdcmd':
        // This case is aimed at telling when a scheduled call to
		// 'mdcmd' was made so that we can detect if a parity check
		// was started on a schedule or whether it was manually
		// started.
        $cmd = 'mdcmd '; for ($i = 3; $i < count($argv) ; $i++)  $cmd .= $argv[$i] . ' ';
        parityTuningLoggerDebug(sprintf(_('detected that mdcmd had been called from %s with command %s'), $argv[2], $cmd));
        switch (strtolower($argv[2])) {
			case 'crond':
			case 'sh':
			case 'bash':
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
						loadVars(5);         // give time for pause/cancel  
						switch (strtolower($argv[4])) {
							case 'pause':                     
								parityTuningLoggerDebug ('...' . _('Pause' . ' ' . $parityTuningDescription));
								parityTuningProgressWrite ("PAUSE");
								break;
							case 'cancel':
							default:
								parityTuningProgressWrite ('CANCELLED');
								parityTuningProgressAnalyze();
								parityTuningInactiveCleanup();
								break;
						}
						updateCronEntries();
						suppressMonitorNotification();
						break;
				}  // end of 'crond/sh' switch
				break;
				
			// Not sure this can occur?	
			case 'array_started':
					if ($argv[4] === 'pause') {
						parityTuningProgressWrite ('PAUSE');
					}
					break;
					
			// Not sure this can occur?						
			case 'started':
					updateCronEntries();
					parityTuningLoggerTesting('Must be part of restart operation so nothing further to do!');
					break;
					
					
			case 'mover':
					parityTuningLoggerTesting('... ignore as generated by mover');
					break; 	
					
			default:
					parityTuningLoggerDebug(_('Option not currently recognized').': '.$argv[2]);
					break;
		}  // end of operation type switch
		break;

	//-------------------------------- cron options ------------------------------
	//				Options set up to be run via cron at specified intervals
	
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
		if (!$parityTuningStarted) {
			parityTuningLoggerTesting('Array not yet started so nothing to monitor');
			break;
		}
		// See if array operation is being restarted
		// Apply some consistency checks here as well for safety reasons
		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
				if ($parityTuningActive) {
					parityTuningLoggerTesting (_('no action taken as appears restart outstanding'));
				} else {
					loadVars(30);
					if (!$parityTuningActive) {
						parityTuningLoggerTesting (_('Restart requested - but appears to not be happening'));
						if (!$parityTuningActive) parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);	// Tidy up
					}
				}
				break;
			} else {
				// This condition should never happen if things are working as expected
				parityTuningLogger (_('Inconsisten state information - Restart requested but no progress file'));
				loadVars(30);			// Just in case STARTING is still running
				parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);	// Tidy up
			}
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
				parityTuningProgressSave();					// Helps with debugging
				parityTuningDeleteFile (PARITY_TUNING_PROGRESS_FILE);
				exec('mdcmd nocheck');
				loadVars(3);			// Need to confirm this is long enough!
			}
			suppressMonitorNotification();
			$runType = ($parityTuningCfg['parityProblemCorrect'] == 0) ? _('Non-Correcting') : _('Correcting');
			sendNotification(_('Completed partial check ('). $runType . ')',
			parityTuningPartialRange() . ' ' . _('Errors') . ' ' . $var['sbSyncErrs'] );
			parityTuningDeleteFile (PARITY_TUNING_PARTIAL_FILE);
			updateCronEntries();
			break;
		}
		
		backGroundTaskRunning();

		// See if no array operation in progress so that we no longer need to consider pause/resume
		if (!isArrayOperationActive()) {
			parityTuningLoggerTesting (_('no array operation in progress'));
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
			break;
		}
		// Consistency checks
		$trigger = operationTriggerType();
		if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			parityTuningLoggerTesting (_('appears there is a running array operation but no Progress file yet created'));
			parityTuningLogger ($parityTuningDescription.' '._('detected'));
			parityTuningProgressWrite ($trigger);
			updateCronEntries();    // ensure reasonably frequent monitor checks
		}
		// Add any missing entries to Progress file if manual pause/resume is detected.
		// TODO:  Is this really necessary?   Does it do any harm?
		
		if (file_exists(PARITY_TUNING_MANUAL_FILE)) {
			if (parityTuningProgressWrite($parityTuningPaused?'PAUSE (MANUAL)':'RESUME (MANUAL)')) {				
				ParityTuningLogger($parityTuningDescription.': '.($parityTuningPaused?_('Manually paused'):_('Manually resumed')));
			}
		}

		// Added Shutdown marker file if required
		if ($parityTuningCfg['parityTuningShutdown'] ) {
			if (! file_exists(PARITY_TUNING_SHUTDOWN_FILE)) {
				$msg=_('Server will be shutdown when array operation completes');
				parityTuningLogger ($msg);
				sendNotificationWithCompletion($msg);
				createMarkerFile (PARITY_TUNING_SHUTDOWN_FILE);
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
                				   . ($parityTuningCfg['parityTuningHeatShutdown'] ? (', ' . _('Shutdown') . ' ' . $parityTuningCfg['parityTuningHeatCritical']) . ')' : ''));
		// Get configuration settings
		$parityTuningHeatCritical = $parityTuningCfg['parityTuningHeatCritical'];
		$parityTuningHeatHigh     = $parityTuningCfg['parityTuningHeatHigh'];
		$parityTuningHeatLow      = $parityTuningCfg['parityTuningHeatLow'];	
		// gather temperature information from all drives
        foreach ($disks as $drive) {
            $name=$drive['name'];
            $temp = $drive['temp'];
			// remove any lingering spinup marker file
			parityTuningDeleteFile ("/mnt/$name/".PARITY_TUNING_SPINUP_FILE);
			// The flash drive is ignored
			// All other drives are potentially checked even if not part of the array
            // if ((!startsWith($drive['status'],'DISK_NP')) && (!$name =='flash')) {
			if ((!startsWith($drive['status'],'DISK_NP')) && (!($name === 'flash'))) {
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
                $critical  = $driveCritical - $parityTuningHeatCritical;
                $hot  = $driveWarning - $parityTuningHeatHigh;
                $cool = $driveWarning - $parityTuningHeatLow;
				
				// Restrict it to checking array drives for other over-heating
				// TODO: Revisit whether restricting it to array drives is desireable or whether
				//		 opool drives overheating during a parity check can also pause a check.
				//		 This could also need looking at when multiple array support available
				//		 Would be nice to also include Unassigned Drives if possible
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
								$warmDrives[$name] = $temp;
								$status = _('warm');
							}
						}
					}
					//  Check drives for critical temperatures
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
											   . ($parityTuningCfg['parityTuningHeatShutdown'] ? sprintf(', critical=%s%s',tempInDisplayUnit($critical), $parityTuningTempUnit) : ''). ')');
				}
			}
        }  // end of for loop gathering temperature information

        // Handle at least 1 drive reaching shutdown threshold
		// (deemed more important than simple overheating)
        if ($parityTuningCfg['parityTuningHeatShutdown']) {
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
				parityTuningShutdown ($msg);

				break;
			}
	    }

		// Handle drives being paused/resumed due to temperature

        parityTuningLoggerDebug (sprintf('%s=%d, %s=%d, %s=%d, %s=%d, %s=%d, %s=%d', _('array drives'), $arrayCount, _('hot'), count($hotDrives), _('warm'), count($warmDrives), _('cool'), count($coolDrives),_('spundown'),count($spinDrives),_('idle'),count($idleDrives)));
        if (!$parityTuningPaused) {
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
						$waitMinutes = (int)((time() - filemtime(PARITY_TUNING_HOT_FILE)) / 60);
						$tooLongMinutes = $parityTuningCfg['parityTuningHeatTooLong'];
						if (($waitMinutes > $tooLongMinutes)
						&&  ($waitMinutes <= ($tooLongMinutes + $parityTuningCfg['parityTuningMonitorHeat'])))
						{
							sendTempNotification(opWithErrorCount(_('Waiting')), sprintf(_('Drives been above resume temperature threshold for %s minutes'),$waitMinutes));
						}
						break;

					} else {
						parityTuningLoggerTesting ('Some drives spun down - assume they are cool');
					//
					// Code below was an attempt to let the plugin spin up the drives to check temperature.
                    // Appears to not work for some unknown reason so lets simply resume the check array
					// operation assuming this will trigger Unraid to spin up drives.  WÂ¬we can then pause 
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
						parityTuningResume();
                	}
				}
			}
        }
        break;

    // A resume of an array operation has been requested.
	// This could be via a scheduled cron task or a CLI command
		
    case 'resume':
		createMarkerFile(PARITY_TUNING_INCREMENT_FILE);
        parityTuningLoggerDebug (_('Resume request'));
		if (!$parityTuningStarted) {
			parityTuningLoggerTesting('Array not yet started so nothing to resume');
			break;
		}

		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLoggerTesting('Resume ignored as restart pending');
			break;
		}
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
		if (!$parityTuningPaused) {
			parityTuningLoggerDebug(sprintf('... %s %s', $parityTuningDescription, _('already running')));
			break;
		}
		if (file_exists(PARITY_TUNING_HOT_FILE)) {
			parityTuningLoggerDebug('Resume ignored as paused because disks too hot');
			break;
		}
		if (backGroundTaskRunning()) {
			parityTuningLoggerDebug(_('Resume ignored as paused because background task running'));
			break;
		}

		// Handle special cases where pause had been because of mover running or disks
		// overheating and we are now outside the window for a scheduled resume
		if (!isActivePeriod()) {
			parityTuningLoggerTesting('resume but not inside increments time window for operation so ignored');
			parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);
			parityTuningDeleteFile(PARITY_TUNING_MOVER_FILE);
			parityTuningDeleteFile(PARITY_TUNING_BACKUP_FILE);
		}				
		if ($parityTuningCLI || configuredAction()) {
			parityTuningResume();
		}
        break;

    // A pause of an array operation has been requested.
	// This could be via a scheduled cron task or a CLI command
	
    case 'pause':
	    parityTuningLoggerDebug (_('Pause request'));
		parityTuningDeleteFile(PARITY_TUNING_INCREMENT_FILE);
		if (!$parityTuningStarted) {
			parityTuningLoggerTesting('Array not yet started so nothing to pause');
			break;
		}
		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningLoggerTesting('Pause ignored as restart pending');
			break;
		}
        if (! isArrayOperationActive()) {
            parityTuningLoggerDebug('Pause ignored as no array operation in progress');
            break;
        }
		if (parityTuningPartial()) {
			parityTuningLoggerDebug('Pause ignored as partial check in progress');
			break;
		}
		// We expect a progress file to exists at this point, but lets play safe.
		if (! file_exists(PARITY_TUNING_PROGRESS_FILE)) {
			parityTuningProgressWrite(operationTriggerType());
		}
		
		// We only need to really issue the pause if not already paused
		if ($parityTuningPaused) {
			parityTuningLoggerDebug(sprintf('%s %s!', $parityTuningDescription, _('already paused')));
			break;
		}

		if ($parityTuningCLI || configuredAction()) {
			// Remove any files indicated we might have paused for some other reason
			parityTuningDeleteFile(PARITY_TUNING_MOVER_FILE);
			parityTuningDeleteFile(PARITY_TUNING_BACKUP_FILE);
			parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);
			parityTuningPause();
		}
        break;	

	// Set up partial array parity checks for Parity Problems Assistant mode
	
    case 'partial':
		createMarkerFile(PARITY_TUNING_PARTIAL_FILE);	// Create file to indicate partial check
		// TODO:  Consider accepting parameters to specify start and end (sector and/or %)
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

	//	------------------------------ Unraid Events --------------------------------------
	//							Options that are triggered by Unraid events


	case 'starting':		// Button to start the array has been used (or auto-start)
		parityTuningLoggerDebug (_('Array is being started'));
    	suppressMonitorNotification();
		
		// consistency check
		if (! file_exists(UNRAID_PARITY_SYNC_FILE)) {
			createMarkerFile(PARITY_TUNING_TIDY_FILE);
		} else {
			// Not sure thios condition can actually arise but lets play safe 
			if (file_exists(PARITY_TUNING_TIDY_FILE)) {
				parityTuningLoggerDebug('plugin and Unraid disagree on whether unclean shutdown');
				parityTuningDeleteFile (PARITY_TUNING_TIDY_FILE);
			}
			parityTuningLogger(_('Unclean shutdown detected'));
			sendNotification(_('Unclean shutdown detected'), 
			// Need to construct message to stop ** being converted to <b> by translation system which messes up email type alerts
			sprintf('%s **%s** %s',_('See'),('Troubleshooting'),_('section of the Unraid OS Manual in the online documetaion to get guidance on resolving this')),'alert','');
			// Remove any state files that applied before the Unclean shutdown.
			parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
			parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
		}
		break;


	// runs with 'md' devices valid and when array is about to be started
	// Other services dependent on array active are not yet started
	
    case 'array_started':
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
			suppressMonitorNotification();
			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
				if (file_exists(PARITY_TUNING_RESTART_FILE)) {
					sendNotification (_('Array operation will not be restarted'), _('Unclean shutdown detected'), 'warning');
					parityTuningProgressWrite('RESTART CANCELLED');
					parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
				}
			}
			parityTuningProgressWrite('ABORTED');
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
			createMarkerFile(PARITY_TUNING_AUTOMATIC_FILE);	// Expect automatic check 
    	} 
    	break;


	// runs with when system startup complete and array is fully started
	
	case 'started':
        parityTuningLoggerDebug (_('Array has just been started'));
		suppressMonitorNotification();
		parityTuningDeleteFile(PARITY_TUNING_STOPPING_FILE);

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
			parityTuningLogger (_('No restart information present'));
			goto end_started;
		}
	
		// Get restart information

		$restart = parse_ini_file(PARITY_TUNING_RESTART_FILE);
		parityTuningLoggerTesting('restart information:');
		foreach ($restart as $key => $value) parityTuningLoggerTesting("$key=$value");	
		$restartPos = $restart['mdResyncPos'];
		$restartPos += $restartPos;				// convert from 1K units to 512-byte sectors
		$restartCorrect = $restart['mdResyncCorr'];
		$restartAction= $restart['mdResyncAction'];
		$adj = $restartPos % 8;					// Position must be mutiple of 8
		$restartDescription = actionDescription($restartAction, $restartCorrect);	
		$restartPaused = ($restart['mdResync'] == 0);
		parityTuningLoggerTesting("restartPos: $restartPos, adjustment: $adj, paused: $restartPaused");
		if ($adj != 0) {			// Not sure this can occur but better to play safe
			$restartPos -= $adj;
		}
		switch (disksChanged()) {
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

		parityTuningLogger('restart to be attempted');
		removeHistoryCancelEntry();		// Remove cancel entry from array shutdown 
		// Special case for restarting operations that are not parity checks and where Unraid
		// automatically starte the array operation again from the beginning so we need to cancel
		// it before restarting it at the offset previously reached.
		loadVars(10);
		if ($parityTuningActive) {
			parityTuningLogger(_('Cancel automatically started array operation') 
								.' ('.$parityTuningAction
								. _('to get ready for restart').')');
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
		loadVars(10);     // give time for any array operation to start running (TODO check time needed)
		// notification now array operation running
		suppressMonitorNotification();
		parityTuningProgressWrite('RESUME (RESTART)');
		$actionDescription =  $restartDescription . parityTuningCompleted();
		sendNotification(_('Array operation restarted'),  $actionDescription, 'normal');
		
		if ($restartPaused) {
			parityTuningLogger(_('operation waas paused when reboot initiated so pause it immediately'));
			parityTuningPause($actionDescription);
			goto end_started;			
		}
		// NOTE:  Monitor process can pause it later as well if conditions for that are met.
		if (!isIncrementActive($restartAction)) {
			parityTuningLogger(_('Outside time slot for running operation type'));
			// $actionDescription = $restartDescription;		// Update description due to restart
			parityTuningPause($actionDescription);
		}
		goto end_started;

end_norestart:
  		if (file_exists(PARITY_TUNING_RESTART_FILE)) {
			// This means we got here without attempting restart
  			parityTuningLogger(_('Restart will not be attempted'));
 			parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
 			if (file_exists(PARITY_TUNING_PROGRESS_FILE)) {
 			    parityTuningProgressWrite('RESTART CANCELLED');
				parityTuningProgressWrite('ABORTED');
 			}
 		}
 		parityTuningDeleteFile(PARITY_TUNING_DISKS_FILE);
        parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
		parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
		parityTuningDeleteFile(PARITY_TUNING_AUTOMATIC_FILE);
end_started:
		parityTuningProgressAnalyze();
		if (file_exists(PARITY_TUNING_TIDY_FILE)) {
			parityTuningLoggerTesting(_("does not appear to be an unclean shutdown"));
			parityTuningDeleteFile(PARITY_TUNING_TIDY_FILE);
		} else {
			// Unclean shutdown processing
			parityTuningLoggerTesting(_("appears to be an unclean shutdown"));
			parityTuningInactiveCleanup();
			if ($parityTuningNoParity) {
				parityTuningLoggerTesting(_("No parity present, so no automatic parity check"));
			} else {	
				loadVars(30);				// give time for any array operation to start
				if (!isArrayOperationActive()) {
					parityTuningLoggerTesting("array operation not actually Started by Unraid");
				} else {
					parityTuningLoggerTesting("array operation started by Unraid ($parityTuningAction)");
					if (! $parityTuningAction = 'check') {
						parityTuningLoggerTesting(_("Not automatic check ($parityTuningAction)"));
					} else {
						parityTuningLoggerTesting("array operation appears to be automatic check");
						createMarkerFile (PARITY_TUNING_AUTOMATIC_FILE);
						if (! isset($restartDescription)) $restartDescription = _('Parity-Check');
//						sendNotification (sprintf('%s %s %s',
//										_('Automatic Unraid'), 
//										$restartDescription, 
//										('started')), 
//										_('Unclean shutdown detected'), 
//										'warning');
//						suppressMonitorNotification();	
						if (! $parityTuningCfg['parityTuningAutomatic']) {
							parityTuningLoggerTesting(_('Automatic parity check pause not configured'));
						} else {
							parityTuningLoggerTesting(_('Pausing Automatic parity check configured'));
							if (isActivePeriod()) {
								parityTuningLoggerTesting(_('... but in active period so leave running'));
							} else{
								parityTuningLoggerTesting(_('... outside active period so needs pausing'));
								parityTuningPause();
								parityTuningLoggerTesting(_('Pausing automatic parity check'));
							}
						}
					}
				}
			}
		}
		parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
		if ($parityTuningAction == 'check') suppressMonitorNotification();
		break;


	//	Runs when the Stop button is used to stop the array 
	//	OR when the shutdown/reboot buttons are used.
	
    case 'stopping':
        parityTuningLoggerDebug(_('Array stopping'));
		suppressMonitorNotification();
		createMarkerFile(PARITY_TUNING_STOPPING_FILE);
        if (!isArrayOperationActive()) {
			// We need to know if this was a second event after array stopped
			if (! file_exists(PARITY_TUNING_RESTART_FILE)) {
				parityTuningLoggerDebug (_('No array operation in progress and no restart information saved'));
				parityTuningProgressAnalyze();
			} else {
				parityTuningLoggerDebug (_('No array operation in progress but restart information saved'));
			}
        } else {
			parityTuningLoggerDebug (sprintf(_('Array stopping while %s was in progress %s'), $parityTuningDescription, parityTuningCompleted()));
		    parityTuningProgressWrite('STOPPING');
			sleep(1);
			if (! $parityTuningCfg['parityTuningRestart']) {
				parityTuningLoggerTesting('Restart option not set');
				parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
			} else {
				parityTuningLoggerTesting('Restart option set');
				parityTuningLoggerTesting("parityTuningAction=$parityTuningAction");
				$restart = 'mdResync=' . $var['mdResync'] . "\n"
						   .'mdResyncPos=' . $var['mdResyncPos'] . "\n"
						   .'mdResyncSize=' . $var['mdResyncSize'] . "\n"
						   .'mdResyncAction=' . $var['mdResyncAction'] . "\n"
						   .'mdResyncCorr=' . $var['mdResyncCorr'] . "\n"
						   .'startMode=' . $var['startMode'] . "\n"
						   .'triggerType=' . operationTriggerType() . "\n";
				file_put_contents (PARITY_TUNING_RESTART_FILE, $restart);
				parityTuningLoggerTesting('Restart information ($restart) saved to ' 
								. parityTuningMarkerTidy(PARITY_TUNING_RESTART_FILE));
				parityTuningProgressWrite('PAUSE (RESTART)');
				sendNotification(_('Array stopping: Restart will be attempted on next array start'), $parityTuningDescription
				.parityTuningCompleted());	
			}
			suppressMonitorNotification();
        }
    	break;


	//	Runs when system stopped.
	//	Occurs at end of cmdStop execution, or if cmdStart failed.
	//	The array has been stopped.
	
	case 'stopped':
	    createMarkerFile(PARITY_TUNING_TIDY_FILE);
		parityTuningDeleteFile(PARITY_TUNING_STOPPING_FILE);
		sleep(1);  // Give some time for file operations to complete
		if (!file_exists(PARITY_TUNING_RESTART_FILE)) {
			parityTuningProgressAnalyze();
			parityTuningInactiveCleanup();
		}
		suppressMonitorNotification();
		break;

//-------------------------------- CLI Options ---------------------------------------
// 					Options that are only currently for CLI use

    case 'analyze':
        parityTuningProgressAnalyze();
        break;

CLI_Status:
    case 'status':
		parityTuningLoggerCLI(_('Status') . ': ' 
							. (! isArrayOperationActive()
							   ?  _('No array operation currently in progress')
							   :  ' '  . actionDescription($parityTuningAction)
							      . ($parityTuningPaused 
									? ' ' . _('PAUSED').' ' 
									: ' ')
								  . parityTuningCompleted()),
							PARITY_TUNING_LOGGING_BASIC);
;
		break;

    case 'check':
	    $dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $setting = strtolower($dynamixCfg['parity']['write']);
        $command= 'correct';
        if ($setting == '' ) $command = 'nocorrect';
        parityTuningLoggerCLI(sprintf(_('using scheduled mode of %s'),$command),
								PARITY_TUNING_LOGGING_DEBUG);
        // fallthru now we know the mode to use
    case 'correct':
    case 'nocorrect':
        if (isArrayOperationActive()) {
            parityTuningLoggerCLI(sprintf(_('Not allowed as %s already running'),
									$parityTuningDescription),
									PARITY_TUNING_LOGGING_BASIC);
            break;
        }
        $parityTuningCorrecting =($command == 'correct') ? true : false;
		// TODO:  Consider supporting a parameter to specify start point (sector or %) 
		exec("/usr/local/sbin/mdcmd check $command");
        loadVars(10);		// Need to give time for the operation to actually start.
	    parityTuningLoggerCLI(actionDescription($parityTuningAction) . ' Started',
							PARITY_TUNING_LOGGING_BASIC);
        if ($parityTuningAction == 'check' && ( $command == 'correct')) {
            if ($parityTuningNoParity) {
            	parityTuningLoggerCLI(_('Only able to start a Read-Check as no parity drive present'),
									PARITY_TUNING_LOGGING_BASIC);
            } else {
            	parityTuningLoggerCLI(_('Only able to start a Read-Check due to number of disabled drives'),
									PARITY_TUNING_LOGGING_BASIC);
            }
        }
	    goto CLI_Status;

    case 'cancel':		// CLI Cancel request
        parityTuningLoggerCLI(_('Cancel request'),PARITY_TUNING_LOGGING_BASIC);
        if (isArrayOperationActive()) {
            parityTuningLoggerTesting ('mdResyncAction=' . $parityTuningAction);
			exec('/usr/local/sbin/mdcmd nocheck cancel');
            parityTuningLoggerCLI (sprintf(_('%s cancel request sent %s'), $parityTuningDescription, parityTuningCompleted()), PARITY_TUNING_LOGGING_DEBUG);
            loadVars(5);
            parityTuningProgressWrite('CANCELLED');
            parityTuningLoggerCLI(sprintf(_('%s Cancelled'),$parityTuningDescription),
								PARITY_TUNING_LOGGING_BASIC);
            parityTuningProgressAnalyze();
        }

        goto CLI_Status;

    case 'stop':
		if ($parityTuningStartStop) {
			parityTuningLoggerCLI(_('Stop array issued via Command Line'),PARITY_TUNING_LOGGING_BASIC);
			exec('emcmd cmdStop=Stop');
		} else {
			parityTuningLoggerCLI(_('Requires Unraid 6.10.3 or later'),PARITY_TUNING_LOGGING_BASIC);
		}
		break;
    case 'start':
		if ($parityTuningStartStop) {
			parityTuningLoggerCLI(_('Start array issued via Command Line'),PARITY_TUNING_LOGGING_BASIC);
			exec('emcmd cmdStart=Start');
		} else {
			parityTuningLoggerCLI(_('Requires Unraid 6.10.3 or later'),PARITY_TUNING_LOGGING_BASIC);
		}
		break;

	case 'history':			// Option being considered for adding to CLI support
		parityTuningLogger("History CLI option not yet implemented");
		break;

	// Potential Unraid event types on which no action is (currently) 
	// being taken by this plugin?
	// They are being caught at the moment so we can see when they actually occur.
	// The following could be commented out as not used by the plugin
	// (but still useful to see when these event fire)

	case 'driver_loaded':
	case 'disks_mounted':	// The disks and user shares (if enabled) are mounted.
	case 'svcs_restarted':	// Occurs as a result of changing/adding/deleting a share.
							//	The network services are started and may be 
							//	exporting different share(s).
	case 'docker_started':	// The docker service is enabled and started.
	case 'libvirt_started':	// The libvirt service is enabled and started.
	case 'stopping_array':	// The disks and user shares have been unmounted, 
							// about to stop the array.
    case 'stopping_svcs':	// About to stop network services
    case 'stopping_libvirt'://	About to stop libvirt
    case 'stopping_docker':	// About to stop docker
    case 'unmounting_disks'://	The network services have been stopped, about to unmount
							// the disks and user shares.  The disks have been spun up and
							// a "sync" executed, but no disks un-mounted yet
    	break;
			
    // Finally the error/usage case.   Hopefully we never get here in normal running when not using CLI
    case 'help':
    case '--help':
    default:
        parityTuningLoggerCLI ('');       // Blank line to help break up debug sequences
        parityTuningLoggerCLI (_('ERROR') . ': ' . _('Unrecognized option').' '.$command);
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
		parityTuningLoggerCLI ('  start            ' . _('Start the array'));
		parityTuningLoggerCLI ('  stop             ' . _('Stop the array'));
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

// Output a message to the console if running in CLI mode.
// An optional parameter specifies if it should also be written to the log file (and at what level)

//       ~~~~~~~~~~~~~~~~~~~~~
function parityTuningLoggerCLI($string, $logging=null) {
//       ~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCLI;
	switch ($logging) {
		case PARITY_TUNING_LOGGING_BASIC:
			parityTuningLogger($string); break;
		case PARITY_TUNING_LOGGING_DEBUG:
			parityTuningLoggerDebug($string); break;
		case PARITY_TUNING_LOGGING_TESTING:
			parityTuningLoggerTesting($string); break;
	}
	if (($parityTuningCLI)) echo $string . "\n";
}

// -------------------------------- Support Functions  -------------------------------------


// Helps break debug information into blocks to identify entries for a given entry point

//       ~~~~~~~~~~~~~~~
function spacerDebugLine($start, $cmd) {
//       ~~~~~~~~~~~~~~~
    // Not sure if this should be active at DEBUG level or only at TESTING level?
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
// 
// Return values
//    1 (true)  Write was OK
//    0 (false)	Write was ignored or failed

//       ~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressWrite($msg, $filename=PARITY_TUNING_PROGRESS_FILE) {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~
	global $var, $parityTuningDescription;
	global $parityTuningAction, $parityTuningCorrecting;
	
    parityTuningLoggerTesting ($msg . ' record to be written');
	loadVars();	// Ensure these are up-to-date;
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
    }
	//	It appears that under some conditions an attempt could be made to write a
	//  duplicate of the last entry.   If so ignore this attempt and return a failure.

	$lines = file($filename);
	$lineCount = count($lines);
	if (startswith($lines[$lineCount-1],$msg)) {
		parityTuningLoggerTesting("record $lineCount in progress file already $msg");
		return 0;
	}
	
	// Now get on with adding new entry
    $line = $msg . '|' . date(PARITY_TUNING_DATE_FORMAT) . '|' . time() . '|';
    foreach ($progressFields as $name) $line .= $var[$name] . '|';
    $line .= "$parityTuningDescription|\n";
	file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    parityTuningLoggerTesting ("written $msg as record $lineCount to " . parityTuningMarkerTidy($filename));
	return 1;
}

//  Function that looks to see if a previously running array operation has finished.
//  If it has analyze the progress file to create a history record.
//  We then update the standard Unraid history file.  
//	If needed we patch an existing record.

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningProgressAnalyze() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $var;
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
        return;
    }
	// This is safety check - in ideal world we would not get here
    if ($var['mdResyncPos'] != 0) {
        parityTuningLoggerTesting(' array operation still running - so not time to analyze progress');
        return;;
    }
    spacerDebugLine(true, 'PROGRESS_ANALYZE');
    parityTuningLoggerTesting('Previous array operation finished - analyzing progress information to create history record');

	// The following should always work unless race condition happens.
	if (! parityTuningProgressSave()) {
		parityTuningLoggerDebug('Abandon analyze');
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
        parityTuningLoggerDebug("missing completion line in Progress file - add end TYPE and restart analyze");
        parityTuningProgressWrite('COMPLETED',PARITY_TUNING_PROGRESS_SAVE);
		$lines = file(PARITY_TUNING_PROGRESS_SAVE);	// Reload file
    }
    $duration = $elapsed = $increments = $corrected = $size = 0;
    $thisStart = $thisFinish = $thisElapsed = $thisDuration = $thisOffset = 0;
    $lastFinish = $exitCode = $firstSector = $reachedSector = 0;

	$triggerType = '';
    $mdResyncAction = '';
	$startAction = '';		// This is to handle case where COMPLETE record has wrong type
    foreach ($lines as $line) {
    	parityTuningLoggerTesting("$line");
        list($op,$stamp,$timestamp,$sbSynced,$sbSynced2,$sbSyncErrs, $sbSyncExit, $mdState,
             $mdResync, $mdResyncPos, $mdResyncSize, $mdResyncCorr, $mdResyncAction, $desc) = explode ('|',$line);
		if ($op === 'type') {
			parityTuningLoggerTesting("ignore header record");
			continue;
		};
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
		if ($startAction == '') $startAction = $mdResyncAction;
	
        switch ($op) {
        	case 'SCHEDULED':
        			$triggerType = $op;
        			break;
        	case 'AUTOMATIC':
			        $triggerType = $op;
					break;
        	case 'MANUAL':
        	        $triggerType = $op;
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
			case 'RESUME (MANUAL)':
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
			case 'PAUSE (MANUAL)':
            case 'PAUSE (RESTART)':

            case 'STOPPING':
					if ($reachedSector != $mdResyncPos) {
						parityTuningLoggerTesting("changing reachedSector from $reachedSector to $mdResyncPos");
						$reachedSector = $mdResyncPos;
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
		global $display, $dynamixCfg;
		$display = $dynamixCfg['display'];
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
		sendNotification($msg, $desc, (($exitCode == 0 && $corrected == 0) ? _('normal') : _('alert')));
		
		// Now we want to patch the entry in the standard parity history file
		suppressMonitorNotification();
		$lines = file(UNRAID_PARITY_HISTORY_FILE, FILE_SKIP_EMPTY_LINES);
		$action = "";
		$matchLine = count($lines) > 5 ? count($lines) - 5 : 0;	// try to start near end
		while ($matchLine < count($lines)) {
			$line = $lines[$matchLine];
			$matchLine++;
			$fieldCount = count(explode('|',$line));
			if ($fieldCount < 5) {
				continue;		// Something wrong - so ignore this record.
			}
			list($logstamp,$logduration, $logspeed,$logexit,$logerrors) = explode('|',$line);
			$logtime = strtotime(substr($logstamp, 9, 3) . substr($logstamp,4,4) . substr($logstamp,0,5) . substr($logstamp,12));
			// parityTuningLoggerTesting('history line ' . ($matchLine) . " $logstamp, logtime=$logtime=" . date(PARITY_TUNING_DATE_FORMAT,$logtime));
			if ($logtime > $thisStart) {
				parityTuningLoggerTesting ("looks like line " . ($matchLine) . " is the one to update, logtime=$logtime  . " . date(PARITY_TUNING_DATE_FORMAT,$logtime) . ')');
				parityTuningLoggerTesting ($line);
				if ($logtime <= $thisFinish) {
					parityTuningLoggerTesting ('update log entry on line ' . ($matchLine),", errors=$logerrors");
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
		} // end while loop
		if ($matchLine > count($lines))  parityTuningLoggerTesting('no match found in existing log so added a new record ' . ($matchLine));
		$type = explode(' ',$desc);
		$gendate = date(PARITY_TUNING_DATE_FORMAT,$lastFinish);
		if ($gendate[9] == '0') $gendate[9] = ' ';  // change leading 0 to leading space
		// generate replacement parity history record
		$generatedRecord = $gendate.'|'.$duration.'|'.$speed.'|'.$exitCode.'|'.$corrected.'|'.$startAction;
		// Extra field included as standard on Unraid 6.11 or later
		if ($parityTuningSizeInHistory) {
			parityTuningLoggerTesting('add size to history record: '. $size); 
			$generatedRecord .= '|'.$size;
		}
		$pluginExtra='|'.$elapsed.'|'.$increments.'|'.$actionType;
		parityTuningLoggerTesting('add plugin specific fields history record: '.$pluginExtra); 
		$generatedRecord .= $pluginExtra."\n";
		parityTuningLoggerTesting('log record generated from progress: '. $generatedRecord);    
		$lines[$matchLine-1] = $generatedRecord;
		$myParityLogFile = '/boot/config/plugins/parity.check.tuning/parity-checks.log';
		file_put_contents($myParityLogFile, $generatedRecord, FILE_APPEND);  // Save for debug purposes
		file_put_contents(UNRAID_PARITY_HISTORY_FILE,$lines);
	}
	updateCronEntries();
exit_analyze:
	parityTuningShutdown(_('Array operation completed'));	// Shutdown server if set
	spacerDebugLine(false, 'PROGRESS_ANALYZE');
}

// This function renames the current progress file in preperation to analyzing it.
// It can also be useful for support purposes to have a recovrd of the actions 
// detected during the previous array operation.

//       ~~~~~~~~~~~~~~~~~~~~~~~~	
function parityTuningProgressSave() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~
	parityTuningDeleteFile(PARITY_TUNING_PROGRESS_SAVE);
	if (rename (PARITY_TUNING_PROGRESS_FILE, PARITY_TUNING_PROGRESS_SAVE)) {
		parityTuningLoggerDebug('Old progress file available as ' . PARITY_TUNING_PROGRESS_SAVE);
		return 1;
	} else {
		// I think This should only happen if there is a race condition occuring?
		parityTuningLoggerDebug('rename of progress file failed');
		return 0;
	}
}

//	Remove the last entry from the Parity History file that has been generated by a restart.

//       ~~~~~~~~~~~~~~~~~~~~~~~~
function removeHistoryCancelEntry() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~
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
  $mins = ((int)($hmss/60))%60;
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
function sendNotification($msg, $desc = '', $type = 'normal', $link='/Settings/Scheduler') {
//       ~~~~~~~~~~~~~~~~
	global $dynamixCfg, $docroot;
	global $parityTuningServer;
    parityTuningLogger (_('Send notification') . ': ' . "$msg: $desc (type=$type link=$link)");
    if ($dynamixCfg['notify']['system'] == "" ) {
    	parityTuningLogger (_('... but suppressed as system notifications do not appear to be enabled'));
    } else {
        $cmd = $docroot. '/webGui/scripts/notify'
        	 . ' -e ' . (escapeshellarg(parityTuningPartial() ? "Parity Problem Assistant" : "Parity Check Tuning"))
        	 . ' -i ' . escapeshellarg($type)
	    	 . ($link == '' ? '' : ' -l ' . escapeshellarg($link))
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
function sendArrayNotification ($op, $type='warning', $desc=null) {
//       ~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $parityTuningDescription;
    parityTuningLoggerTesting("Pause/Resume notification message: $op");
    if ($parityTuningCfg['parityTuningNotify'] == 0) {
        parityTuningLoggerTesting (_('... but suppressed as notifications do not appear to be enabled for pause/resume'));
        parityTuningLogger($op . ": " . $parityTuningDescription
    							 	  . parityTuningCompleted());		// Simply log message if not notifying
        return;
    }
    sendNotificationWithCompletion($op,'', $type);
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
// TODO: Check for each Unraid release that there are not version dependent changes

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function suppressMonitorNotification() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
  global $var;

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


// Remove the files (if present) that are used to indicate trigger type for array operation

//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningInactiveCleanup() {
//       ~~~~~~~~~~~~~~~~~~~~~~~~~~~
	$ret  = parityTuningDeleteFile(PARITY_TUNING_PARTIAL_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_AUTOMATIC_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_HOT_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_SHUTDOWN_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_TIDY_FILE);
	$ret |= parityTuningDeleteFile(PARITY_TUNING_RESTART_FILE);
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
    if (startsWith($parityTuningAction,'recon')) {
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
	parityTuningLoggerTesting("isArrayOperationActive - parityTuningActive:$parityTuningActive, parityTuningPos:$parityTuningPos");
	if (file_exists(PARITY_TUNING_RESTART_FILE)) {
		parityTuningLoggerTesting('  restart pending so treat as array operation active');	
	} else if (!$parityTuningActive) {
		parityTuningLoggerTesting(_('No action outstanding'));
		parityTuningProgressAnalyze();
		return false;
	}
	return true;
}

// Determine if the current time is within a period specified for increments to be active
// TODO:  This function does not work correctly when custom times set. THis needs looking at.

//       ~~~~~~~~~~~~~~
function isActivePeriod() {
//       ~~~~~~~~~~~~~~
	global $parityTuningCfg;
	global $parityTuningAction, $parityTuningActive;
	
	// If no array operation active or pending restart then no need to look further
	
	parityTuningLoggerTesting('Check if within increment active period');
	if (! isRunInIncrements($parityTuningAction)) {
		parityTuningLoggerTesting('Increments not being used for this operation so must be within Active period.');
		return 1;
	}
	parityTuningLoggerTesting("... Active=$parityTuningActive, Action=$parityTuningAction, Restart=".(file_exists(PARITY_TUNING_RESTART_FILE)?1:0).", Increment=".(file_exists(PARITY_TUNING_INCREMENT_FILE)?1:0));
	
	if (! $parityTuningActive) {
		$inPeriod = 0;
	// Simpler check to replace time check that works with custom scheduling
	} else if (file_exists(PARITY_TUNING_INCREMENT_FILE)){
		$inPeriod = 1;
	// More complex check for other caseswith custom scheduling
	} else {
		$resumeTime = ($parityTuningCfg['parityTuningResumeHour'] * 60) + $parityTuningCfg['parityTuningResumeMinute'];
		$pauseTime  = ($parityTuningCfg['parityTuningPauseHour'] * 60) + $parityTuningCfg['parityTuningPauseMinute'];
		$currentTime = (date("H") * 60) + date("i");
		parityTuningLoggerTesting(".. PauseTIme=$pauseTime, resumeTime=$resumeTime, currentTime=$currentTime");
		if ($pauseTime < $resumeTime) {         
			// Times span midnight!
			$inPeriod = (($currentTime > $resumeTime) || ($currentTime < $pauseTime))?1:0;
		} else {
			// All of increment on same day
			$inPeriod = (($currentTime >= $resumeTime) && ($currentTime < $pauseTime))?1:0;
		}
	}
	parityTuningLoggerTesting("isActivePeriod()=$inPeriod");
	return $inPeriod;
}

// Determine if the given operation type is set to be run in run in increments.

//       ~~~~~~~~~~~~~~~~~
function isRunInIncrements($action) {
//       ~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;

	$trigger = operationTriggerType();
	$action = strtok($action,' ');
	switch (strtolower($action)) {
		case 'recon':	$ret = ($parityTuningCfg['parityTuningRecon'] === '1') ? 1 : 0;
						break;
		case 'clear':   $ret = ($parityTuningCfg['parityTuningClear'] === '1') ? 1 : 0;
						break;
		case 'check':   
						switch (strtoupper($trigger)) {
							case 'SCHEDULED': 
								$ret=($parityTuningCfg['parityTuningScheduled'] === '1') ? 1 : 0;
								break;
							case 'MANUAL':	  
								$ret=($parityTuningCfg['parityTuningManual'] === '1') ? 1 : 0;
								break;
							case 'AUTOMATIC': 
								$ret=($parityTuningCfg['parityTuningAutomatic'] === '1') ? 1 : 0;
								break;
							default:
								parityTuningLoggerTesting("Unexpected trigger: $trigger");
								$ret = 0;
								break;
						}
						break;
		default:
						parityTuningLoggerTesting("Unexpected action: $action");
						$ret = 0; 
						break;
	}
	parityTuningLoggerTesting("isRunInIncrements($action)=".$ret." (trigger type=$trigger)" );
	return $ret;
}

// Check if the operation type is being run in increments and if so if we are in that period.

//		 ~~~~~~~~~~~~~~~~~
function isIncrementActive($action) {
//		 ~~~~~~~~~~~~~~~~~
	parityTuningLoggerTesting("isIncrementActive($action)");
	if (!isRunInIncrements($action)) return 1;	// If no increments used always assume true
	return isActivePeriod();
}


//       ~~~~~~~~~~~~~~~~~
function updateCronEntries() {
//       ~~~~~~~~~~~~~~~~~
	global $parityTuningCfg, $parityTuningActive;
	
	parityTuningLoggerTesting("Creating required cron entries");
	$lines = [];
	$lines[] = "# Generated schedules for " . PARITY_TUNING_PLUGIN . "\n";

//   TODO:  Think this can always be set as checks now made elsewhere as when to action
	// Handle pause/resume of any operation type that has been specified to use increments
	if ($parityTuningCfg['parityTuningScheduled'] 
	 || $parityTuningCfg['parityTuningManual']
	 || $parityTuningCfg['parityTuningAutomatic']
	 || $parityTuningCfg['parityTuningClear']
	 || $parityTuningCfg['parityTuningRecon']) {
//
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
		parityTuningLoggerTesting("Creating parity assistance cron entry");
		// Partial checks (default = 1 minute)
		$frequency=$parityTuningCfg['parityTuningMonitorPartial'];
	} else {
		if ($parityTuningActive) {
			// Array operation already active (default = 6 minutes)
			$frequency=$parityTuningCfg['parityTuningMonitorBusy'];
		} else if ($parityTuningCfg['parityTuningHeat'] 
				|| $parityTuningCfg['parityTuningHeatShutdown']) {
			// check temperatures (default = 7 minutes)
			$frequency=$parityTuningCfg['parityTuningMonitorHeat'];
		} else {
			// Default if not monitoring more frequently for other 
			// reasons (default = 17 minutes)
			$frequency=$parityTuningCfg['parityTuningMonitorDefault'];
		}
	}
	parityTuningLoggerDebug (sprintf(_('Created cron entry for %s interval monitoring'), $frequency.' '._('minute')));
	$lines[] = "*/$frequency * * * * " . PARITY_TUNING_PHP_FILE . ' "monitor" &>/dev/null' . "\n\n";
	file_put_contents(PARITY_TUNING_CRON_FILE, $lines);
	parityTuningLoggerDebug(sprintf(_('Updated cron settings are in %s'),PARITY_TUNING_CRON_FILE));
	// Activate any changes
	exec("/usr/local/sbin/update_cron");
}

//Determine if mover currently active
// Returns:
//		True	Mover is currently running
//		False	Mover is not currently running
// TODO:  Check if this works correcyy when Mover Tuning plugin installed

//		 ~~~~~~~~~~~~~~
function isMoverRunning() {
//		 ~~~~~~~~~~~~~~
	unset ($output);
	exec ("ps -ef | grep mover", $output);
	$ret = (count($output)> 2) ? 1 : 0;
	return $ret;
}

// Determine if CA Backup or appdata Backup currently active

// Returns:
//		True	Backup task is currently running
//		False	Backup task is not currently running

//		 ~~~~~~~~~~~~~~~
function isBackupRunning() {
//		 ~~~~~~~~~~~~~~~
	$ret = (file_exists('/tmp/appdata.backup/running')
		  ||file_exists('/tmp/ca.backup2/tempFiles/backupInProgress')
		  ||file_exists('/tmp/ca.backup2/tempFiles/restoreInProgress'));
	return ($ret ? 1 : 0);
}

// Function that handles pause/resume around background task being active.
//	At the moment the task we handle in this way are:
//		- mover
//		- CA Backup
// Returns:
//		0	Not paused with background activity active.
//		1	Background activity was active and pause applied


//		 ~~~~~~~~~~~~~~~~~~~~~~
function backGroundTaskHandling($configName, $appName, $markerName, $activityTest) {
//		 ~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg;
	global $parityTuningPaused, $parityTuningDescription;
	global $parityTuningAction, $parityTuningActive;
	
	parityTuningLoggerTesting ("backGroundTaskHandling: markerName=$markerName, configName=$configName, value=".$parityTuningCfg[$configName].", $activityTest=".$activityTest().", Array: Active=$parityTuningActive, Paused=$parityTuningPaused");
	$ret = 0;	// Preset to indicate not currently paused due to background activity
	
	if ($activityTest()) {
		// Background activity is running
		if (!$parityTuningActive) {
			parityTuningLoggerTesting ("... no action required as no array operation active");
		} else {
			// We do not generate a message if no array operation in progress
			if (!file_exists ($markerName)) {
				// We generate a notification if array operation active and configured
				createMarkerFile($markerName); 
				if ($parityTuningCfg['parityTuningBackground']) {
					sendNotification($appName.' '._('running'),' ');
				}
			}
			if (!$parityTuningCfg[$configName]) {
				parityTuningLoggerTesting ("... no action required as not configured to pause");
			} else {
				if ($parityTuningPaused) {	
					parityTuningLoggerTesting ("... no action required as array operation already paused");
				} else {
					// Pause if configured to do so for this background activity
					parityTuningPause();
				}
				$ret=1;		// Indicate paused due to background activity.
			}
		}
		

	} else {
		// Background activity not running

		// Always give a notification if previously given one for this background task running
		if (file_exists($markerName)) {
			parityTuningDeleteFile ($markerName);
			if ($parityTuningCfg['parityTuningBackground']) {
				sendNotification($appName.' '._('no longer running'), ' ');
			}
			if (!$parityTuningPaused) {
				parityTuningLoggerTesting ("... no action required as array operation not paused");
			} else {
				if (!$parityTuningCfg[$configName]) {
					parityTuningLoggerTesting ("... no action required as not configured for this task");
				} else {
					if (!isIncrementActive($parityTuningAction)) {
						sendArrayNotification (_('Array operation not resumed - outside increment window', 'normal'));
					} else {
						parityTuningResume();
					}
				}
			}
		}
	}
	parityTuningLoggerTesting ("backGroundTaskHandling: return value=$ret");
	return $ret;
}
	
//		 ~~~~~~~~~~~~~~~~~~~~~
function backGroundTaskRunning() {
//		 ~~~~~~~~~~~~~~~~~~~~~
	$ret = backGroundTaskHandling('parityTuningMover', _('mover'), PARITY_TUNING_MOVER_FILE, 'isMoverRunning')
		 + backGroundTaskHandling('parityTuningBackup', _('backup'), PARITY_TUNING_BACKUP_FILE, 'isBackupRunning');
	parityTuningLoggerTesting ("backGroundTaskRunning: return value=$ret");
	return $ret;

}

// Get severity for an alert to take into account if errors were found.

//		 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
function ParityTuningSeverity($count = null) {
//		 ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningErrors;
	if (is_null($count)) $count = $parityTuningErrors;
	return $parityTuningErrors == 0 ? 'normal' : 'alert';
}
//		 ~~~~~~~~~~~~~~~~~
function parityTuningPause() {
//		 ~~~~~~~~~~~~~~~~~
	global $parityTuningErrors;
	parityTuningLoggerTesting("Errors so far:  $parityTuningErrors");
	exec('/usr/local/sbin/mdcmd "nocheck" "PAUSE"');
	loadVars(10);
	sendArrayNotification( _('Paused'),'normal');
	suppressMonitorNotification();
}

//		 ~~~~~~~~~~~~~~~~~~
function parityTuningResume() {
//		 ~~~~~~~~~~~~~~~~~~
	exec('/usr/local/sbin/mdcmd "check" "resume"');
	loadVars(5);
	sendArrayNotification(_('Resumed'),'normal');
	parityTuningDeleteFile(PARITY_TUNING_MOVER_FILE);
	parityTuningDeleteFile(PARITY_TUNING_BACKUP_FILE);
	suppressMonitorNotification();

}

//	Function to initiate a tidy (hopefully) shutdown of the server
//  (as a safety check make sure option has not been uinset).

//		 ~~~~~~~~~~~~~~~~~~~~	
function parityTuningShutdown($msg) {
//		 ~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCfg,$parityTuningTesting;
	if (file_exists(PARITY_TUNING_SHUTDOWN_FILE)) {
		parityTuningInactiveCleanup();
		if ($parityTuningCfg['parityTuningShutdown']) {
			sendNotification (_('Array shutdown'), $msg, 'alert');		
			sleep (30);	// add a delay for notification to be actioned
			parityTuningLogger (_('Starting Shutdown'));
			if ($parityTuningTesting) {
				parityTuningLoggerTesting (_('Shutdown not actioned as running in TESTING mode'));
			} else {
				exec('/sbin/shutdown -h -P now');
			}
		} else {
			sendNotification (_('Shutdown aborted'), $msg, 'alert');
		}
	}
}

// Check the stored disk information against the current assignments
//		0 (false)	Disks appear unchanged
//		-1			New disk present (New Config used?)
//		1			Disks changed in some other way

function disksChanged() {
	$disksCurrent = parse_ini_file (PARITY_TUNING_EMHTTP_DISKS_FILE, true);
	$disksOld     = parse_ini_file (PARITY_TUNING_DISKS_FILE, true);
	$ret = 0;
	foreach ($disksCurrent as $drive) {
		$name=$drive['name'];
		if ((startsWith($name, 'parity')) || (startsWith($name,'disk'))) {
			if ($disksCurrent[$name]['status']  == 'DISK-NEW') {
				parityTuningLogger($name . ': ' . _('New'));
				$ret = -1;
			} else { 
				if (($disksCurrent[$name]['id']     != $disksOld[$name]['id'])
				||  ($disksCurrent[$name]['status'] != $disksOld[$name]['status'])
				||  ($disksCurrent[$name]['size']   != $disksOld[$name]['size'])) {
					if ($ret != 0) $ret = 1;
					parityTuningLogger($name . ': ' . _('Changed'));
				}
			}
		}
	}
	if ($ret) parityTuningDeleteFile(PARITY_TUNING_AUTOMATIC_FILE);
	return $ret;
}

