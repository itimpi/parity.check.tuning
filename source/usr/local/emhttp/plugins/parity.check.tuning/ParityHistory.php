<?PHP
/* Copyright 2005-2021, Lime Technology
 * Copyright 2012-2021, Bergware International.
 * Amendments Copyright 2019-2022, Dave Walker (itimpi)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * This version patched by Dave Walker (itimpi) to add:
 * - compatibility with revised parity history record format introduced with Unraid 6.10.0-rc3
 * - backwards compatibility with earlier releases.
 * - Elapsed Time and Increments columns added to displayed information
 * - Legacy language support to work on unRaid versions prior to 6.9
 */
?>
<?
// error_reporting(E_ALL);		 // This option should only be enabled for testing purposes

require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';

extract(parse_plugin_cfg('dynamix',true));

$month = [' Jan '=>'-01-',' Feb '=>'-02-',' Mar '=>'-03-',' Apr '=>'-04-',' May '=>'-05-',' Jun '=>'-06-',' Jul '=>'-07-',' Aug '=>'-08-',' Sep '=>'-09-',' Oct '=>'-10-',' Nov '=>'-11-',' Dec '=>'-12-'];

function this_plus($val, $word, $last) {
  return $val>0 ? (($val||$last)?($val.' '.$word.($last?'':', ')):'') : '';
}
function this_duration($time) {
  if (!$time) return 'Unavailable';
  $days = floor($time/86400);
  $hmss = $time-$days*86400;
  $hour = floor($hmss/3600);
  $mins = $hmss/60%60;
  $secs = $hmss%60;
  return this_plus($days,_('day'),($hour|$mins|$secs)==0).this_plus($hour,_('hr'),($mins|$secs)==0).this_plus($mins,_('min'),$secs==0).this_plus($secs,_('sec'),true);
}
?>
<!DOCTYPE html>
<html <?=$display['rtl']?>lang="<?=strtok($locale,'_')?:'en'?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta http-equiv="Content-Security-Policy" content="block-all-mixed-content">
<meta name="format-detection" content="telephone=no">
<meta name="viewport" content="width=1600">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-fonts.css")?>">
<link type="text/css" rel="stylesheet" href="<?autov("/webGui/styles/default-popup.css")?>">
</head>
<body>
<?
/*
 * Determine if any entries are in the parity.tuning.check plugin extended format.
 */
$log = '/boot/config/parity-checks.log'; $list = []; $extended = false;
$lines = @file($log);
$extended = false;
if ($lines != false) {
  foreach ($lines as $line) {
	if (count(explode('|',$line)) > 6) {
      $extended = true;
      break;
    }
  }
}
?>
<table class='share_status'><thead><tr><td><?=_('Action')?></td><td><?=_('Date')?></td><td><?=_('Duration')?></td><td><?=_('Speed')?></td><td><?=_('Status')?></td><td><?=_('Errors')?></td>
            <?=$extended?'<td>&nbsp;' . _('Elapsed Time') . '&nbsp;</td><td>&nbsp;' . _('Increments') . '&nbsp;</td></td>':''?>
			</tr></thead><tbody>
<?
$log = '/boot/config/parity-checks.log'; $list = [];
if (file_exists($log)) {
  $handle = fopen($log, 'r');
  while (($line = fgets($handle)) !== false) {
	$date=$duration=$speed=$status=$error=$action=$elapsed=$increments=$parityTuningType='';
	// workout what format the record it in and handle accordingly
	// parityTuningLoggerTesting("Parity History Record: $line");
	switch (count(explode('|',$line))) {
		case 5:	// legacy support
			    // parityTuningLoggerTesting("... original Unraid format");
				[$date,$duration,$speed,$status,$error] = explode('|',$line);
				$type = $increment = $elapsed = $parityTuningAction = "";
				$action = $parityTuningType = '-';
				break;
		case 6: // new standard format (adds operation type)
			    // parityTuningLoggerTesting("... new Unraid format");
				[$date,$duration,$speed,$status,$error,$action] = explode('|',$line);
				$action = explode(' ',$action);
				switch ($action[0]) {
					case 'recon': $action = in_array($action[1],['P','Q']) 
											? _('Parity-Sync') 
											: _('Data-Rebuild'); break;
					case 'check': $action = count($action)>1 
											? _('Parity-Check') 
											: _('Read-Check'); break;
					case 'clear': $action = _('Disk-Clear'); break;
					default     : $action = '-'; break;
				}
				$increment = $elapsed;
				$parityTuningAction = $action;
				break;
		case 7: // original plugin extended format (adds elapsed time and increments)
			    // parityTuningLoggerTesting("... original plugin format");
				[$date,$duration,$speed,$status,$error,$elapsed,$increments] = explode('|',$line);
				$action = "-";
				break;
		case 8: // legacy plugin extended format (adds elapsed time, increments and type)
			    // parityTuningLoggerTesting("... extended plugin format");
		        [$date,$duration,$speed,$status,$error,$elapsed,$increments,$parityTuningType] = explode('|',$line);
				$action = "-";
				break;
		case 9: // new plugin extended format (adds elapsed time, increments and type to new format)
				// parityTuningLoggerTesting("... new plugin format");
				[$date,$duration,$speed,$status,$error,$action,$elapsed,$increments,$parityTuningType] = explode('|',$line);
				$action = "-";
				break;
		default:
				ParityTuningLoggerTesting("unexpected number of fields in history record: $line");
		
	}
    if ($speed==0) $speed = _('Unavailable');
    $date = str_replace(' ',', ',strtr(str_replace('  ',' 0',$date),$month));
    if ($duration>0||$status<>0) {  // ignore dummy records
    	$list[] = '<tr><td>'.$parityTuningType.'</td><td>&nbsp;'.$date.'<&nbsp;/td><td>&nbsp;'.this_duration($duration).'&nbsp;</td><td>&nbsp;'.$speed.'&nbsp;</td><td>&nbsp;'
    			.($status==0?_('OK'):($status==-4?_('Canceled'):($status==-5?_('Aborted'):'' . $status))).'&nbsp;</td><td>&nbsp;'.$error . '&nbsp;</td>'
                .($extended?('<td>&nbsp;'.($elapsed==0?'Unknown':this_duration($elapsed)).'&nbsp;</td>'
                            .'<td>&nbsp;'.($increments==0?_('Unavailable'):$increments).'&nbsp;</td>')
						   :'')
                .'</tr>';
    }
  }
  fclose($handle);
}
if ($list)
  foreach (array_reverse($list) as $row) echo $row;
else
  echo "<tr><td colspan='5' style='text-align:center;padding-top:12px'>"._('No parity check history present')."!</td></tr>";
?>
 
