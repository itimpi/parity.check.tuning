<?PHP
/*
 * Helper routines used by the parity.check.tuning plugin
 *
 * Copyright 2019-2022, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *oig
 * Limetech is given explicit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

// Setting a reasonably strict PHP retorting level helps pick up non-obvious errors
error_reporting(error_reporting() | E_STRICT | E_PARSE);  // Level at which we want normally want our code to be clean 
// error_reporting(E_ALL);		 // This level should only be enabled for testing purposes

// useful for testing outside Gui
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';

require_once "$docroot/webGui/include/Helpers.php";

$parityTuningCLI = isset($argv)?(basename($argv[0]) == 'parity.check'):false;

// Set up some useful constants used in multiple files
define('EMHTTP_DIR' ,               '/usr/local/emhttp');
define('CONFIG_DIR' ,               '/boot/config');
define('PLUGINS_DIR' ,              CONFIG_DIR . '/plugins');
define('PARITY_TUNING_PLUGIN',      'parity.check.tuning');
define('PARITY_TUNING_EMHTTP_DIR',  EMHTTP_DIR . '/plugins/' . PARITY_TUNING_PLUGIN);
define('PARITY_TUNING_PHP_FILE',    PARITY_TUNING_EMHTTP_DIR . '/' . PARITY_TUNING_PLUGIN . '.php');  
define('PARITY_TUNING_BOOT_DIR',    PLUGINS_DIR . '/' . PARITY_TUNING_PLUGIN);
define('PARITY_TUNING_FILE_PREFIX', PARITY_TUNING_BOOT_DIR . '/' . PARITY_TUNING_PLUGIN . '.');
define('PARITY_TUNING_VERSION_FILE',PARITY_TUNING_FILE_PREFIX . 'version');
define('PARITY_TUNING_DEFAULTS_FILE',PARITY_TUNING_EMHTTP_DIR.'/'.PARITY_TUNING_PLUGIN.'.defaults');
define('PARITY_TUNING_CFG_FILE',    PARITY_TUNING_FILE_PREFIX . 'cfg');
define('PARITY_TUNING_LOG_FILE',    PARITY_TUNING_FILE_PREFIX . 'log');
define('PARITY_TUNING_PARTIAL_FILE',PARITY_TUNING_FILE_PREFIX . 'partial');  // Create when partial check in progress (contains end sector value)
define('EMHTTP_VAR_DIR' ,           '/var/local/emhttp/');
define('PARITY_TUNING_EMHTTP_VAR_FILE',   EMHTTP_VAR_DIR . 'var.ini');
define('PARITY_TUNING_EMHTTP_DISKS_FILE', EMHTTP_VAR_DIR . 'disks.ini');
define('PARITY_TUNING_CABACKUP_FILE',PLUGINS_DIR . '/ca.backup2.plg'); 
define('PARITY_TUNING_RESTART_FILE',   PARITY_TUNING_FILE_PREFIX . 'restart');  // Created if array stopped with array operation active to hold restart info
define('PARITY_TUNING_SCHEDULED_FILE', PARITY_TUNING_FILE_PREFIX . 'scheduled');// Created when we detect an array operation started by cron
define('PARITY_TUNING_MANUAL_FILE',    PARITY_TUNING_FILE_PREFIX . 'manual');   // Created when we detect an array operation started manually
define('PARITY_TUNING_AUTOMATIC_FILE', PARITY_TUNING_FILE_PREFIX . 'automatic');// Created when we detect an array operation started automatically after unclean shutdown
define('PARITY_TUNING_DATE_FORMAT', 'Y M d H:i:s')
;

// Configuration information

$parityTuningCfg = parse_ini_file(PARITY_TUNING_DEFAULTS_FILE);
 if (file_exists(PARITY_TUNING_CFG_FILE)) {
	$parityTuningCfg = array_replace($parityTuningCfg,parse_ini_file(PARITY_TUNING_CFG_FILE));
}
// Handle migrating renamed options  (Remove in later release)
// Increments -> Scheduled
// if (array_key_exists('Increments', $parityTuningCfg)) {
//	parityTuningLoggerTesting ('Migrating setting Increments => Scheduled');
//	$parityTuningCfg['Scheduled'] = $parityTuningCfg['Increments'];
//	unset($parityTuningCfg['Increments']);
//}
// Unscheduled -> Manual
//if (array_key_exists('Unscheduled', $parityTuningCfg)) {
//	parityTuningLoggerTesting ('Migrating setting Unscheduled => Manual');
//	$parityTuningCfg['Manual'] = $parityTuningCfg['Unscheduled'];
//	unset($parityTuningCfg['Unscheduled']);
//}

$dynamixCfg = parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);

$parityTuningTempUnit      = $dynamixCfg['display']['unit'] ?? 'C'; // Use Celsius if not set

// Multi-Language support code enabler for non-GUI usage

$plugin = PARITY_TUNING_PLUGIN;
if (file_exists(EMHTTP_DIR . "/webGui/include/Translations.php")) {
	$login_locale = '';
	if (!isset($_SESSION['locale']) || ($_SESSION['locale']=='')) {
		parityTuningLoggerTesting("setting locale from dynamix setting");
		$_SESSION['locale'] = $login_locale = $dynamixCfg['display']['locale'];
	}	
	parityTuningLoggerTesting("Multi-Language support active, locale: " . $_SESSION['locale']);
	$_SERVER['REQUEST_URI'] = 'paritychecktuning';
	require_once "$docroot/webGui/include/Translations.php";
	parse_plugin('paritychecktuning');
} else {
	require_once EMHTTP_DIR . "/plugins/parity.check.tuning/Legacy.php";
	parityTuningLoggerTesting('Legacy Language support active');
}

if ($parityTuningCLI) parityTuningLoggerTesting("CLI Mode active");

$parityTuningVersion = _('Version').': '.(file_exists(PARITY_TUNING_VERSION_FILE) ? file_get_contents(PARITY_TUNING_VERSION_FILE) : '<'._('unknown').'>');

// Handle Unraid version dependencies
$parityTuningUnraidVersion = parse_ini_file("/etc/unraid-version");
$parityTuningVersionOK = (version_compare($parityTuningUnraidVersion['version'],'6.7','>') >= 0);
$parityTuningRestartOK = (version_compare($parityTuningUnraidVersion['version'],'6.8.3','>') > 0);

if (file_exists(PARITY_TUNING_EMHTTP_DISKS_FILE)) {
	$disks=parse_ini_file(PARITY_TUNING_EMHTTP_DISKS_FILE, true);
	$parityTuningNoParity = ($disks['parity']['status']=='DISK_NP_DSBL') && ($disks['parity2']['status']=='DISK_NP_DSBL');
}

// load some state information.
// (written as a function to facilitate reloads)
function loadVars($delay = 0) {
    if ($delay > 0) {
		parityTuningLoggerTesting ("loadVars($delay)");
		sleep($delay);
	}
	if (! file_exists(PARITY_TUNING_EMHTTP_VAR_FILE)) {		// Protection against running plugin while system initializing so this file not yet created
		parityTuningLoggerTesting(sprintf('Trying to populate before %s created so ignored',  PARITY_TUNING_EMHTTP_VAR_FILE));
		return;
	}

   	$vars = parse_ini_file(PARITY_TUNING_EMHTTP_VAR_FILE);
	$GLOBALS['parityTuningVar']        = $vars;
	$GLOBALS['parityTuningServer']     = strtoupper($vars['NAME']);
	$GLOBALS['parityTuningCsrf']       = $vars['csrf_token'];
    $GLOBALS['parityTuningPos']        = $vars['mdResyncPos'];
    $GLOBALS['parityTuningSize']       = $vars['mdResyncSize'];
    $GLOBALS['parityTuningAction']     = $vars['mdResyncAction'];
    $GLOBALS['parityTuningActive']     = ($vars['mdResyncPos'] > 0); // array action has been started
	$GLOBALS['parityTuningPaused']    = ($GLOBALS['parityTuningActive'] && ($vars['mdResync'] == 0)); // Array action is paused
    $GLOBALS['parityTuningRunning']    = ($GLOBALS['parityTuningActive'] && ($vars['mdResync']>0)); // Array action is running
    $GLOBALS['parityTuningCorrecting'] = $vars['mdResyncCorr'];
    $GLOBALS['parityTuningErrors']     = $vars['sbSyncErrs'];
}
loadVars();

// Load up default description to avoid redundant calls elsewhere
$parityTuningDescription = 	$parityTuningActive
							? actionDescription($parityTuningAction, $parityTuningCorrecting)
							:_('No array operation in progress');
if ($parityTuningActive) parityTuningLoggerDebug($parityTuningDescription.' '.($parityTuningRunning ? _('running') : _('paused')));

// Set marker file to remember some state information we have detected
// (put date/time into file so can tie it back to syslog if needed)

//       ~~~~~~~~~~~~~~~~
function createMarkerFile ($filename) {
//       ~~~~~~~~~~~~~~~~
	global $parityTuningAction, $parityTuningCorrecting;
	if (!file_exists($filename)) {
		file_put_contents ($filename, date(PARITY_TUNING_DATE_FORMAT));
		parityTuningLoggerTesting(parityTuningMarkerTidy($filename) ." created to indicate how " . actionDescription($parityTuningAction, $parityTuningCorrecting) . " was started");
	}
}

// get the type of a check according to which marker files exist
// (plus apply some consistency checks against scenarios that should not happen)
//		 ~~~~~~~~~~~~~~~~~~~~
function operationTriggerType() {
//		 ~~~~~~~~~~~~~~~~~~~~
	global $parityTuningAction;
	if (! startsWith($parityTuningAction, 'check')) {
		parityTuningLoggerTesting ('... ' . _('not a parity check so always treat it as an automatic operation'));
		createMarkerFile (PARITY_TUNING_AUTOMATIC_FILE);
		if (file_exists(PARITY_TUNING_SCHEDULED_FILE))	parityTuningLogger("ERROR: marker file found for both automatic and scheduled $parityTuningAction");
		if (file_exists(PARITY_TUNING_MANUAL_FILE))		parityTuningLogger("ERROR: marker file found for both automatic and manual $parityTuningAction");
		return 'AUTOMATIC';
	} else {
		// If we have not caught the start then assume an automatic parity check
		if (file_exists(PARITY_TUNING_SCHEDULED_FILE)) {
			parityTuningLoggerTesting ('... ' . _('appears to be marked as scheduled parity check'));
			if ($manual)		parityTuningLogger("ERROR: marker file found for both scheduled and manual $parityTuningAction");
			if ($automatic)		parityTuningLogger("ERROR: marker file found for both scheduled and automatic $$parityTuningAction");
			return 'SCHEDULED';
		} else if (file_exists(PARITY_TUNING_AUTOMATIC_FILE)) {
			parityTuningLoggerTesting ('... ' . _('appears to be marked as automatic parity check'));
			if ($manual)		parityTuningLogger("ERROR: marker file found for both automatic and manual $parityTuningAction");
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

// Get the long text description of an array operation

//       ~~~~~~~~~~~~~~~~
function actionDescription($action, $correcting, $trigger = null) {
//       ~~~~~~~~~~~~~~~~
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
						if (is_null($trigger)) $triggerType = operationTriggerType();

						switch (strtoupper($triggerType)) {
							case 'AUTOMATIC': 
								$triggerType =  _('Automatic');
								break;
							case 'MANUAL':	
								$triggerType =  _('Manual');
								break;
							case 'SCHEDULED':	
								$triggerType =  _('Scheduled');
								break;
							default:			
								$triggerType =  '';
								break;
	}
        				$ret = ($triggerType . ' ' .
								(count($act) == 1 ?  _('Read-Check') 
								: ($correcting == 0 
								  ? _('Non-Correcting')
								  : _('Correcting'))
							      . ' ' . _('Parity Check')));
						break;
        default:        $ret = sprintf('%s: %s',_('Unknown action'), $action);
						break;
    }

	parityTuningLoggerTesting("actionDescription($action, $correcting, $trigger) = $ret");
	return $ret;
}

//	get the display form of the trigger type in a manner that is compatible with multi-language support


//	test if partial parity check in progress
//       ~~~~~~~~~~~~~~~~~~~
function parityTuningPartial() {
//       ~~~~~~~~~~~~~~~~~~~
	return file_exists(PARITY_TUNING_PARTIAL_FILE);
}

// Logging functions

// Write message to syslog and also to console if in CLI mode
// Change source according to whether doing partial check or not

//       ~~~~~~~~~~~~~~~~
function parityTuningLogger($string) {  
//       ~~~~~~~~~~~~~~~~
  global $parityTuningCfg, $parityTuningServer;
  $logTarget = $parityTuningCfg['parityTuningLogTarget'];
  parityTuningLoggerCLI ($string);
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
  if ($parityTuningCfg['parityTuningLogging'] > 1) parityTuningLogger('TESTING:'.strtoupper($command)." $string");
}

//       ~~~~~~~~~~~~~~~~~~~~~
function parityTuningLoggerCLI($string) {
//       ~~~~~~~~~~~~~~~~~~~~~~~
	global $parityTuningCLI;
  	if ($parityTuningCLI) echo $string . "\n";
}

// Useful matching functions

//       ~~~~~~~~~~
function startsWith($haystack, $beginning, $caseInsensitivity = false){
//       ~~~~~~~~~~
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

// parityTuningLoggerTesting('PHP error_reporting() level set to '.errorLevelAsText());

//	Convert the bit level error reporting to text values for display
//       ~~~~~~~~~~~~~~~~
function errorLevelAsText() {
//       ~~~~~~~~~~~~~~~~~
	$lvls = array(
		'E_ERROR'=>E_ERROR,
		'E_WARNING'=>E_WARNING,
		'E_PARSE'=>E_PARSE,
		'E_NOTICE'=>E_NOTICE,
		'E_CORE_ERROR'=>E_CORE_ERROR,
		'E_CORE_WARNING'=>E_CORE_WARNING,
		'E_COMPILE_ERROR'=>E_COMPILE_ERROR,
		'E_COMPILE_WARNING'=>E_COMPILE_WARNING,
		'E_USER_ERROR'=>E_USER_ERROR,
		'E_USER_WARNING'=>E_USER_WARNING,
		'E_USER_NOTICE'=>E_USER_NOTICE,
		'E_STRICT'=>E_STRICT, 
		'E_RECOVERABLE_ERROR'=>E_RECOVERABLE_ERROR,
		'E_DEPRECATED'=>E_DEPRECATED,
		'E_USER_DEPRECATED'=>E_USER_DEPRECATED,
	); 
	$level = error_reporting();
	if (($level & E_ALL) == E_ALL) return 'E_ALL';
	
	$ret = '';
	foreach ($lvls as $key => $lvl) {
		if (($level & $lvl) == $lvl) {
			if ((strlen($ret)>0)) $ret.='|';
			$ret .= $key;
		}
	}
	return $ret;
}

?>
