<?PHP
/*
 * Helper routines used by the parity.check.tuning plugin
 *
 * Copyright 2019-2025, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * Limetech is given explicit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * Useful site for checking php sytax: https://phphub.net/linter/
 */

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/plugins/dynamix/include/Wrappers.php";

// Set up some useful constants used in multiple files
define('EMHTTP_DIR' ,               '/usr/local/emhttp');
define('CONFIG_DIR' ,               '/boot/config');
define('PLUGINS_DIR' ,              CONFIG_DIR . '/plugins');
define('PARITY_TUNING_PLUGIN',      'parity.check.tuning');
$plugin = PARITY_TUNING_PLUGIN;		// required by security guidelines
define('PARITY_TUNING_EMHTTP_DIR',  EMHTTP_DIR . '/plugins/' . $plugin);
define('PARITY_TUNING_VERSION_FILE',PARITY_TUNING_EMHTTP_DIR . "/$plugin.version");
define('PARITY_TUNING_PHP_FILE',    PARITY_TUNING_EMHTTP_DIR . '/' . PARITY_TUNING_PLUGIN . '.php');  
define('PARITY_TUNING_BOOT_DIR',    PLUGINS_DIR . '/' . PARITY_TUNING_PLUGIN);
define('PARITY_TUNING_FILE_PREFIX', PARITY_TUNING_BOOT_DIR . '/' . PARITY_TUNING_PLUGIN . '.');

define('PARITY_TUNING_CFG_FILE',    PARITY_TUNING_FILE_PREFIX . 'cfg');
define('PARITY_TUNING_LOG_FILE',    PARITY_TUNING_FILE_PREFIX . 'log');
define('PARITY_TUNING_PARTIAL_FILE',PARITY_TUNING_FILE_PREFIX . 'partial');  // Create when partial check in progress (contains end sector value)
define('EMHTTP_VAR_DIR' ,           '/var/local/emhttp/');
define('PARITY_TUNING_EMHTTP_VAR_FILE',  EMHTTP_VAR_DIR . 'var.ini');
define('PARITY_TUNING_EMHTTP_DISKS_FILE',EMHTTP_VAR_DIR . 'disks.ini');
define('PARITY_TUNING_CABACKUP2_FILE',   PLUGINS_DIR . '/ca.backup2.plg'); 
define('PARITY_TUNING_APPBACKUP_FILE',   PLUGINS_DIR . '/appdata.backup.plg'); 
define('PARITY_TUNING_RESTART_FILE',   PARITY_TUNING_FILE_PREFIX . 'restart');  // Created if array stopped with array operation active to hold restart info
define('PARITY_TUNING_SCHEDULED_FILE', PARITY_TUNING_FILE_PREFIX . 'scheduled');// Created when we detect an array operation started by cron
define('PARITY_TUNING_MANUAL_FILE',    PARITY_TUNING_FILE_PREFIX . 'manual');   // Created when we detect an array operation started manually
define('PARITY_TUNING_AUTOMATIC_FILE', PARITY_TUNING_FILE_PREFIX . 'automatic');// Created when we detect an array operation started automatically after unclean shutdown
define('PARITY_TUNING_DATE_FORMAT', 'Y M d H:i:s');
// Logging modes supported
define('PARITY_TUNING_LOGGING_BASIC' , '0');
define('PARITY_TUNING_LOGGING_DEBUG' , '1');
define('PARITY_TUNING_LOGGING_TESTING' ,'2');
// Targets for testing mode
define('PARITY_TUNING_LOGGING_SYSLOG' ,'0');
define('PARITY_TUNING_LOGGING_BOTH' ,'1');
define('PARITY_TUNING_LOGGING_FLASH' ,'2');

// Configuration information
// (automatically merge defauls with current settings)
$parityTuningCfg = parse_plugin_cfg($plugin);

// Only want this line active while debugging to help clear up all PHP errors.
if ($parityTuningCfg['parityTuningLogging'] > 1) {
	// parityTuningLoggerTesting("Set PHP reporting level for TESTING mode");
	error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
}

$dynamixCfg = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
$parityTuningTempUnit      = @$dynamixCfg['display']['unit'] ?? 'C'; // Use Celsius if not set
	
// Multi-Language support code enabler for non-GUI usage

$plugin = PARITY_TUNING_PLUGIN;
if (file_exists(EMHTTP_DIR . "/webGui/include/Translations.php")) {
	$login_locale = '';
	if (!isset($_SESSION['locale']) || ($_SESSION['locale']=='')) {
		if (isset($dynamixCfg['display']['locale'])) {
			parityTuningLoggerTesting("setting locale from dynamix setting");
			$login_locale = $dynamixCfg['display']['locale'];
		}
		$_SESSION['locale'] = $login_locale;
	}	
	// parityTuningLoggerTesting("Multi-Language support active, locale: " . $_SESSION['locale']);
	$_SERVER['REQUEST_URI'] = 'paritychecktuning';
	require_once "$docroot/webGui/include/Translations.php";
	parse_plugin('paritychecktuning');
} else {
	require_once EMHTTP_DIR . "/plugins/parity.check.tuning/Legacy.php";
	parityTuningLoggerTesting('Legacy Language support active');
}

// Unraid version dependencies

$parityTuningVersion = _('Version').': '.(file_exists(PARITY_TUNING_VERSION_FILE) ? file_get_contents(PARITY_TUNING_VERSION_FILE) : '<'._('unknown').'>');
$parityTuningUnraidVersion = parse_ini_file("/etc/unraid-version")['version'];
$parityTuningStartStop = version_compare($parityTuningUnraidVersion,'6.10.3','>');
$parityTuningSizeInHistory = version_compare($parityTuningUnraidVersion,'6.11.0','>');

// Determine if parity drive installed

if (file_exists(PARITY_TUNING_EMHTTP_DISKS_FILE)) {
	$disks=parse_ini_file(PARITY_TUNING_EMHTTP_DISKS_FILE, true);
	$parityTuningParity1=($disks['parity']['status']=='DISK_NP_DSBL')?? false;
	$parityTuningParity2=($disks['parity2']['status']=='DISK_NP_DSBL')?? false;
	// parityTuningLoggerTesting ("parityTuningParity1: $parityTuningParity1, parityTuningParity2 : $parityTuningParity2");
	$parityTuningNoParity =(($parityTuningParity1==false) && ($parityTuningParity2==false));
	if ($parityTuningNoParity) parityTuningLoggerTesting ("No Parity disk installed");
}


// Plugin cannot handle increments if standard Unbraid setting set to cumulative
$parityTuningCumulative = $dynamixCfg['parity']['cumulative'] ?? '0';  // Assume 0 if not set
if ($parityTuningCumulative) {
	parityTuningLoggerTesting ("Cumulative option set at Unraid level");
}

// Decide if backup plugin installed

$parityTuningNoBackup = !(file_exists(PARITY_TUNING_CABACKUP2_FILE)||file_exists(PARITY_TUNING_APPBACKUP_FILE));
if ($parityTuningNoBackup) {
	parityTuningLoggerTesting ("No appdata backupo plugin installed");
}

// Decide if Docker active
$parityTuningDockerEnabled = (parse_ini_file('/boot/config/docker.cfg')['DOCKER_ENABLED']??"no")=="yes";
if (!$parityTuningDockerEnabled) {
	parityTuningLoggerTesting ("Docker not enabled");
}

// Decide if experimental mode active (for developing features not yet released.

$parityTuningExperimental = file_exists(PARITY_TUNING_FILE_PREFIX . 'experimental');
if ($parityTuningExperimental) {
	parityTuningLoggerTesting ("Experimental mode active");
}


// load some state information into global variables for directly referencing elsewhere.
// (written as a function to facilitate reloads)
function loadVars($delay = 0) {
	global $var;
	global $parityTuningServer, $parityTuningStarted, $parityTuningPos, $parityTuningSize;
	global $parityTuningAction, $parityTuningActive, $parityTuningPaused;
	global $parityTuningCorrecting, $parityTuningErrors, $parityTuningDockerEnabled;
	
	if (! file_exists(PARITY_TUNING_EMHTTP_VAR_FILE)) {		// Protection against running plugin while system initializing so this file not yet created
		parityTuningLoggerTesting(sprintf('Trying to populate before %s created so ignored',  PARITY_TUNING_EMHTTP_VAR_FILE));
		return;
	}

    if ($delay > 0) {
		parityTuningLoggerTesting ("loadVars($delay)");
		sleep($delay);
	} 
	
  	$var = parse_ini_file(PARITY_TUNING_EMHTTP_VAR_FILE);
	$parityTuningServer     = strtoupper($var['NAME']);
	$parityTuningStarted	= $var['mdState'] == 'STARTED' ? 1 : 0; 
    $parityTuningPos        = $var['mdResyncPos'];
    $parityTuningSize       = $var['mdResyncSize'];
    $parityTuningAction     = $var['mdResyncAction'];
    $parityTuningActive     = ($var['mdResyncPos'] > 0 ? 1 : 0); // array action has been started
	$parityTuningPaused     = ($parityTuningActive && ($var['mdResync'] == 0)) ? 1 : 0; // Array action is paused
    $parityTuningCorrecting = $var['mdResyncCorr'];
    $parityTuningErrors     = $var['sbSyncErrs'];
}
loadVars();


// Load up default description to avoid redundant calls elsewhere
// TODO - decide if this really is worth doing?
if (isset($parityTuningActive) && $parityTuningActive) {
	$parityTuningDescription = actionDescription($parityTuningAction, $parityTuningCorrecting);
	parityTuningLoggerDebug($parityTuningDescription.' '.($parityTuningPaused ? _('paused') : _('running')));
} else {
	$parityTuningDescription = _('No array operation in progress');
}

// ----------------------- Support/Utility  Functions ----------------------------

// Tidy up the name of a marker file for logging purposes
//       ~~~~~~~~~~~~~~~~~~~~~~
function parityTuningMarkerTidy($name) {
//       ~~~~~~~~~~~~~~~~~~~~~~
	if (startsWith($name, PARITY_TUNING_FILE_PREFIX)) {
		$name = str_replace(PARITY_TUNING_FILE_PREFIX, '', $name) . ' marker file';
	}
	return $name;
}

// Set marker file to remember some state information we have detected
// (put date/time into file so can tie it back to syslog if needed)
//       ~~~~~~~~~~~~~~~~
function createMarkerFile ($filename) {
//       ~~~~~~~~~~~~~~~~
	global $parityTuningAction, $parityTuningCorrecting;
	if (!file_exists($filename)) {
		file_put_contents ($filename, date(PARITY_TUNING_DATE_FORMAT, LOCK_EX));
		parityTuningLoggerTesting(parityTuningMarkerTidy($filename) ." created"); 
//		parityTuningLoggerTesting(parityTuningMarkerTidy($filename)." created to indicate " . actionDescription($parityTuningAction, $parityTuningCorrecting) . " state");
	}
}

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


// get the type of a check according to which marker files exist
// (plus apply some consistency checks against scenarios that should not happen)
//		 ~~~~~~~~~~~~~~~~~~~~
function operationTriggerType($action = null, $active=null) {
//		 ~~~~~~~~~~~~~~~~~~~~
	global $parityTuningAction, $parityTuningActive;
	

	if (is_null($action)) $action = $parityTuningAction;
	if (is_null($active)) $active = $parityTuningActive;
	if (! file_exists(PARITY_TUNING_RESTART_FILE) ) {
		// parityTriggerType:tyTuningLoggerTesting ('... ' . _('no restart operation active'));
		if (!($active)) {
			// parityTuningLoggerTesting ('... ' . _('no array operation active so trigger type not relevant'));
			return '';
		}
	}
	if (! startsWith($action, 'check')) {
		parityTuningLoggerTesting ('... ' . _('not a parity check so always treat it as an automatic operation'));
		createMarkerFile (PARITY_TUNING_AUTOMATIC_FILE);
		if (file_exists(PARITY_TUNING_SCHEDULED_FILE)) {
			parityTuningLogger("WARNING: scheduled marker file found for $parityTuningAction");
			parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
		}
		if (file_exists(PARITY_TUNING_MANUAL_FILE))	{
			parityTuningLogger("WARNING: manual marker file found for $parityTuningAction");
			parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
		}
		return 'AUTOMATIC';
	} else {
		// If we have not caught the start then assume an automatic parity check
		if (file_exists(PARITY_TUNING_SCHEDULED_FILE)) {
			parityTuningLoggerTesting ('... ' . _('appears to be marked as scheduled parity check'));
			if (file_exists(PARITY_TUNING_MANUAL_FILE))	{
				parityTuningLogger("WARNING: marker file found for both scheduled and manual $parityTuningAction");
				parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
			}
			if (file_exists(PARITY_TUNING_AUTOMATIC_FILE)) {
				parityTuningLogger("WARNING: marker file found for both scheduled and automatic $$parityTuningAction");
				parityTuningDeleteFile(PARITY_TUNING_SCHEDULED_FILE);
			}
			return 'SCHEDULED';
		} else if (file_exists(PARITY_TUNING_AUTOMATIC_FILE)) {
			parityTuningLoggerTesting ('... ' . _('appears to be marked as automatic parity check'));
			if (file_exists(PARITY_TUNING_MANUAL_FILE))	{
				parityTuningLogger("WARNING: marker file found for both automatic and manual $parityTuningAction");
				parityTuningDeleteFile(PARITY_TUNING_MANUAL_FILE);
			}
			return 'AUTOMATIC';
		} else if (file_exists(PARITY_TUNING_MANUAL_FILE)) {
			parityTuningLoggerTesting ('... ' . _('appears to be manual parity check'));
			return 'MANUAL';
		} else {
			parityTuningLoggerTesting ('... ' . _('trigger unknown - assume manual'));
			createMarkerFile (PARITY_TUNING_MANUAL_FILE);
			return 'MANUAL';
		}
	}
}

// Get the long text description of an active array operation
// if $correcting is omitted, then the mdstat values will be assumed
// if $trigger is omitted, then the presenced of marked files will be used
// if $active is omitted or false, then the mdstat active state will be used

//       ~~~~~~~~~~~~~~~~
function actionDescription($action, $correcting = null, $trigger = null, $active = null) {
//       ~~~~~~~~~~~~~~~~
	global $parityTuningActive,$parityTuningCorrecting;
	
	if (is_null($action)) return '';		// This should never happen	
	
	if (is_null($correcting)) $correcting = $parityTuningCorrecting;
	if (is_null($active))     $active     = $parityTuningActive;
	if (is_null($trigger))    $trigger    = operationTriggerType($action,$active);
    
	$act = explode(' ', $action );

    switch (strtolower($act[0])) {
        case 'recon':	// TODO use extra array entries to decide if disk rebuild in progress or merely parity sync
        				$ret= _('Parity Sync') . '/' . _('Data Rebuild');
						break;
        case 'clear':   $ret = _('Disk Clear');
						break;
        case 'check':   $type = (count($act) == 1) 
								?
								: _('Parity Check');
						parityTuningLoggerTesting("triggerType: $trigger");
						switch (strtoupper($trigger)) {
							case 'AUTOMATIC': 
								$trigger =  _('Automatic');
								break;
							case 'MANUAL':	
								$trigger =  _('Manual');
								break;
							case 'SCHEDULED':	
								$trigger =  _('Scheduled');
								break;
							default:			
								$trigger =  '';
								break;
						}
        				$ret = ($trigger . ' ' .
								(count($act) == 1 ?  _('Read-Check') 
								: ($correcting == 0 
								  ? _('Non-Correcting')
								  : _('Correcting'))
							      . ' ' . _('Parity-Check')));
						break;
        default:        $ret = sprintf('%s: %s',_('Unknown action'), $action);
						break;
    }

	parityTuningLoggerTesting("actionDescription($action, $correcting, $trigger, $active) = $ret");
	return $ret;
}


//	test if partial parity check in progress
//       ~~~~~~~~~~~~~~~~~~~
function parityTuningPartial() {
//       ~~~~~~~~~~~~~~~~~~~
	return ( file_exists(PARITY_TUNING_PARTIAL_FILE));
}

//	Get a list of docker containers.
//  If the $status parameter is provided then the list is restricted ones with that status.
//	Expected values of $status to check for are:
//		running/up
//		paused
//  Returns:
//		false if none found
//		array of containers found
//
//	TODO:  Do we need to check if array started?

//       ~~~~~~~~~~~~~~~~~~~
function dockerContainerList($wantedStatus=null) {
//       ~~~~~~~~~~~~~~~~~~~
    global $parityTuningDockerEnabled;
	$dockerList = [];
	if ($parityTuningDockerEnabled) {
		parityTuningLoggerTesting("DockerContainerStatus($wantedStatus)");
			// Create list of dockers in json format
		$containersJson=null;
		$resultCode=null;
		exec('/usr/bin/docker ps -a --format \'json\'',$containersJson,$resultCode);
		if ($resultCode != 0) {
			parityTuningLoggerTesting("dockerContainerList(): failed to get docker list");
			return false;
		}
		file_put_contents (PARITY_TUNING_FILE_PREFIX . 'docker', print_r($containersJson, true));
		// parityTuningLoggerTesting("containersJson: ".print_r($containersJson,true));
		parityTuningLoggerTesting("docker count: ".count($containersJson));
		foreach ($containersJson as $containerJson) {
			parityTuningLoggerTesting("dockerJson: $containerJson");
			$container = json_decode($containerJson, true);
			$name=$container['Names'];
			$status=$container['Status'];
			parityTuningLoggerTesting("name: $name, Status:$status");
			if (is_null($wantedStatus) || $status == $wantedStatus) {
				// Add to list of dockers to be returned.
				array_push ($dockerList,$container);
				// parityTuningLoggerTesting("added docker $name to return value list");
			}
		}
		
	}
	return $dockerList;
}

// Logging functions

// Write message to syslog and also to console if in CLI mode
// Change source according to whether doing partial check or not

//       ~~~~~~~~~~~~~~~~
function parityTuningLogger($string) {  
//       ~~~~~~~~~~~~~~~~
  global $parityTuningCfg, $parityTuningServer;	
  $logTarget = $parityTuningCfg['parityTuningLogTarget'];
  $logName = parityTuningPartial() ? "Parity Problem Assistant" : "Parity Check Tuning";
  if ($logTarget > 0) {
	 $line = date(PARITY_TUNING_DATE_FORMAT) . ' ' . $parityTuningServer . " $logName: $string\n";
	 file_put_contents(PARITY_TUNING_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
  }
  $string = str_replace("'","",$string);
  if ($logTarget < 2)  {
	$cmd = 'logger -t "' . $logName . '" "' . $string . '"';
	shell_exec($cmd);
  }
}

// Write message to syslog if debug or testing logging active

//       ~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningLoggerDebug($string) {
//       ~~~~~~~~~~~~~~~~~~~~~~~
  global $parityTuningCfg;
  if ($parityTuningCfg['parityTuningLogging'] > 0) parityTuningLogger('DEBUG:   ' . $string);
}

// Write message to syslog if testing logging active

//       ~~~~~~~~~~~~~~~~~~~~~~~
function parityTuningLoggerTesting($string) {
//       ~~~~~~~~~~~~~~~~~~~~~~~
  global $parityTuningCfg, $command;
  if ($parityTuningCfg['parityTuningLogging'] > 1) {
	parityTuningLogger('TESTING:'.(is_null($command)?'':strtoupper($command))." $string");
  }
}



// Useful matching functions

//       ~~~~~~~~~~
function startsWith($haystack, $beginning, $caseInsensitivity = false){
//       ~~~~~~~~~~
	if (is_null($haystack)) {
		parityTuningLoggerTesting("haystack=null on call to startsWith()");
		return false;
	}
	if (is_null($beginning)) {
		parityTuningLoggerTesting("beginning=null on call to startsWith()");
		return false;
	}
    if ($caseInsensitivity)
        return strncasecmp($haystack, $beginning, strlen($beginning)) === 0;
    else
        return strncmp($haystack, $beginning, strlen($beginning)) === 0;
}

//       ~~~~~~~~
function endsWith($haystack, $ending, $caseInsensitivity = false){
//       ~~~~~~~~
    if ($caseInsensitivity)
        return strcasecmp(substr($haystack, strlen($haystack) - strlen($ending)), $haystack) === 0;
    else
        return strpos($haystack, $ending, strlen($haystack) - strlen($ending)) !== false;
}

?>
