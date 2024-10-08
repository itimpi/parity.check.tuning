#!/usr/bin/php -q
<?PHP
/* Copyright 2005-2022, Lime Technology
 * Copyright 2012-2022, Bergware International.
 * Copyright 2019-2023, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * This version patched by Dave Walker (itimpi) from the Limtech version to add:
 * - Handling of extended information in parity history log file added by plugin
 * - Elapsed Time and Increments columns added to displayed information
 * - Extended Operation type information when aailable
 */
?>
<?
require_once  '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';
parityTuningLogger("Parity History invoked");
extract(parse_plugin_cfg('dynamix',true));

// add translations
$_SERVER['REQUEST_URI'] = 'main';
$login_locale = _var($display,'locale');
require_once "$docroot/webGui/include/Translations.php";

$month = [' Jan '=>'-01-',' Feb '=>'-02-',' Mar '=>'-03-',' Apr '=>'-04-',' May '=>'-05-',' Jun '=>'-06-',' Jul '=>'-07-',' Aug '=>'-08-',' Sep '=>'-09-',' Oct '=>'-10-',' Nov '=>'-11-',' Dec '=>'-12-'];
$log   = "/boot/config/parity-checks.log";
$list  = [];

function this_plus($val, $word, $last) {
  return $val>0 ? (($val||$last)?($val.' '.$word.($last?'':', ')):'') : '';
}
function this_duration($time) {
  if (!$time) return 'Unavailable';
  $days = floor($time/86400);
  $hmss = $time-$days*86400;
  $hour = floor($hmss/3600);
  $mins = floor($hmss/60)%60;
  $secs = $hmss%60;
  return this_plus($days,_('day'),($hour|$mins|$secs)==0).this_plus($hour,_('hr'),($mins|$secs)==0).this_plus($mins,_('min'),$secs==0).this_plus($secs,_('sec'),true);
}

parityTuningLoggerTesting("Parity History: Checking for records in extended format");
$lines = @file($log);
$extended = false;
if ($lines != false) {
  foreach ($lines as $row) {
	if (count(explode('|',$row)) > 6) {
      $extended = true;
      break;
    }
  }
}
parityTuningLoggerTesting("Parity History: records with extended format present:$extended");
?>
<table style='margin-top:10px;background-color:inherit'>
<tr style='font-weight:bold'><td><?=_('Action')?></td><td><?=_('Date')?></td><td><?=_('Size')?></td><td><?=_('Duration')?></td><td><?=_('Speed')?></td><td><?=_('Status')?></td><td><?=_('Errors')?></td>
<?=($extended?"<td>"._('Elapsed Time')."</td><td>"._('Increments')."</td>":'')
		."</td></tr>"?></tr>
		
<?


if (file_exists($log)) {
  $handle = fopen($log, 'r');
  $lineNumber = 0;
  while (($row = fgets($handle))!==false) {
	$lineNumber ++;
	// workout what format the record it in and handle accordingly
	parityTuningLoggerTesting("Parity History Record $lineNumber: $row");
	$fieldCount = count(explode('|',$row));
	// Preset fields only present in extended formats for backwards compatibility
	$increments = $elapsed = $parityTuningType = $size = "";
	$action = _('check P');
	switch ($fieldCount) {
		case 4:	// very old Unraid format prior to 2018.
				// Has no year in date field  so ignore
			ParityTuningLoggerTesting("Line $lineNumber: Old Unraid format from 2018 or earlier - record ignored");
			continue 2;
		case 5: // Unraid format prior to 6.10 
			parityTuningLoggerTesting("... Unraid format before 6.10");
			[$date,$duration,$speed,$status,$error] = my_explode('|',$row,5);
			break;
		case 6: // Unraid format (adds operation type) from 6.10 onwards
			parityTuningLoggerTesting("... Unraid format from 6.10");
			[$date,$duration,$speed,$status,$error,$action] = my_explode('|',$row,6);
			break;
		case 7:	// Unraid format (adds size) from 6.11 onwards
			parityTuningLoggerTesting("... Unraid format from Unraid 6.11");
			[$date,$duration,$speed,$status,$error,$action,$size] = my_explode('|',$row,7);
			break;
		
		case 8: // plugin extended format (adds elapsed time, increments and type to 6.10 format)
			parityTuningLoggerTesting("... plugin format for Unraid < 6.10");
			[$date,$duration,$speed,$status,$error,$elapsed,$increments,$parityTuningType] = my_explode('|',$row,8);
			break;
		case 9: // plugin extended format (adds elapsed time, increments and type to 6.10 format)
			parityTuningLoggerTesting("... plugin format for Unraid 6.10");
			[$date,$duration,$speed,$status,$error,$action,$elapsed,$increments,$parityTuningType] = my_explode('|',$row,9);
			break;
		case 10:// plugin extended format (adds elapsed time, increments and type to 6.11 format)
			parityTuningLoggerTesting("... plugin format for Unraid 6.11+");
			[$date,$duration,$speed,$status,$error,$action,$size,$elapsed,$increments,$parityTuningType] = my_explode('|',$row,10);
			break;
		default:
			parityTuningLoggerDebug("ERROR:  Unexpected number of fields ($fieldCount) in parity-check.log on line $lineNumber:");
			//parityTuningLoggerTesting("        $row");
			//parityTuningLoggerTesting("        Assume legacy Unraid history format");
			// [$date,$duration,$speed,$status,$error,$action] = my_explode('|',$row,6);
			continue 2;		// Ignore this record
	}

	// Workaround for earlier bug where $action field not populated in history file.
	if ($action ==='') $action = 'check P';
	
    $action = preg_split('/\s+/',$action);
    switch ($action[0]) {
      case 'recon': $action = in_array($action[1],['P','Q']) ? _('Parity-Sync') : _('Data-Rebuild'); break;
      case 'check': $action = count($action)>1 ? _('Parity-Check') : _('Read-Check'); 
	  				$parityTuningAction = preg_split('/\s+/',$parityTuningType);
					// Determine trigger type
					switch (strtoupper($parityTuningAction[0])) {
						case 'MANUAL':     $parityTuningType = _('Manual'); 
									break;
						case 'AUTOMATIC':  $parityTuningType = _('Automatic');
									break;
						case 'SCHEDULED':   $parityTuningType = _('Scheduled'); 
									break;
						default: $parityTuningType = ''; 
									// ParityTuningLoggerTesting("Trigger type unknown");
									break;
					}
					// Add in Correcting/Non-Correcting if known
					if (count($parityTuningAction) > 1) {
						// Need to allow for cases before this was added to plugin extra info
						// parityTuningLoggerTesting("action: $action, plugAction: ".$parityTuningAction[1]);
						if (! ($action === $parityTuningAction[1])) {
							$parityTuningType .= ' '.$parityTuningAction[1];
						}
					}
					// Build full expanded action
					$action = $parityTuningType.' '.$action;
					break;
      case 'clear': $action = _('Disk-Clear'); break;
      default     : $action = '-';  break;
    }
	
    $date = str_replace(' ',', ',strtr(str_replace('  ',' 0',$date),$month));
    $date .= ' ('._(date('l',strtotime($date)),0).')';
    $size = $size ? my_scale($size*1024,$unit,-1)." $unit" : '-';
    $duration = this_duration($duration);
	$elapsed  = is_numeric($elapsed) ? this_duration($elapsed) : "&nbsp;";
	$increments = is_numeric($increments) ? $increments : "&nbsp;";
    // handle both old and new speed notation
    $speed = $speed ? (is_numeric($speed) ? my_scale($speed,$unit,1)." $unit/s" : $speed) : _('Unavailable');
    $status = $status==0 ? _('OK') : ($status==-4 ? _('Canceled') : $status);
    // $list[] = "<tr><td>$action</td><td>$date</td><td>$size</td><td>$duration</td><td>$speed</td><td>$status</td><td>$error</td></tr>";
	// $list[] = "<tr><td>$action</td><td>&nbsp;$date</td><td>&nbsp;$size</td><td>&nbsp;$duration</td><td>&nbsp;$speed</td><td>&nbsp;$status</td><td>&nbsp;$error</td>"
	//		  .($extended?"<td>&nbsp;$elapsed</td><td>&nbsp;$increments</td>":'')
	//		  ."</tr>";
    array_unshift($list, "<tr><td>$action</td><td>$date</td><td>$size</td><td>&nbsp;$duration</td><td>&nbsp;$speed</td><td>&nbsp;$status</td><td>&nbsp;$error</td>"
	.($extended?"<td>&nbsp;$elapsed</td><td>&nbsp;$increments</td>":'')."</tr>");

  }
  fclose($handle);
}
echo $list ? implode($list) : "<tr><td colspan='7' style='text-align:center;padding-top:12px'>"._('No parity check history present')."!</td></tr>";
?>
</table>

