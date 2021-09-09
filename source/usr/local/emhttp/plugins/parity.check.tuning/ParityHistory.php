<?PHP
/* Copyright 2005-2020, Lime Technology
 * Copyright 2012-2020, Bergware International.
 * Amendments Copyright 2019-2021, Dave Walker (itimpi)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * This version patched by Dave Walker (itimpi) to add Elapsed Time and Increments columns to displayed information
 * (also modified with legacy support to work on unRaid versions prior to 6.9)
 */
?>
<?

$docroot = $docroot ?: $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php';

extract(parse_plugin_cfg('dynamix',true));

$month = [' Jan '=>'-01-',' Feb '=>'-02-',' Mar '=>'-03-',' Apr '=>'-04-',' May '=>'-05-',' Jun '=>'-06-',' Jul '=>'-07-',' Aug '=>'-08-',' Sep '=>'-09-',' Oct '=>'-10-',' Nov '=>'-11-',' Dec '=>'-12-'];

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
if ($lines != false) {
  foreach ($lines as $line) {
    list($date,$duration,$speed,$status,$error,$elapsed,$increments) = explode('|',$line);
    if (($elapsed != 0) || ($increments != 0)) {
      $extended = true;
      break;
    }
  }
}
?>
<table class='share_status'><thead><tr><td><?=_('Date')?></td><td><?=_('Duration')?></td><td><?=_('Speed')?></td><td><?=_('Status')?></td><td><?=_('Errors')?></td>
            <?=$extended?'<td>' . _('Elapsed Time') . ' </td><td>' . _('Increments') . '</td><td>Type</td>':''?>
			</tr></thead><tbody>
<?
$log = '/boot/config/parity-checks.log'; $list = []; $extended = false;
if (file_exists($log)) {
  $handle = fopen($log, 'r');
  while (($line = fgets($handle)) !== false) {
    [$date,$duration,$speed,$status,$error,$elapsed,$increments,$type] = explode('|',$line);
    if (($elapsed != 0) || ($increments != 0)) $extended = true;
    if ($speed==0) $speed = _('Unavailable');
    $date = str_replace(' ',', ',strtr(str_replace('  ',' 0',$date),$month));
    if ($duration>0||$status<>0) {
    	$list[] = '<tr align="center"><td align="center">'.$date.'</td><td> '.his_duration($duration).' </td><td> '.$speed.' </td><td> '
    			.($status==0?_('OK'):($status==-4?_('Canceled'):($status==-5?_('Aborted'):'' . $status))).'</td><td> '.$error . ' </td>'
                .($extended?('<td> '.($elapsed==0?'Unknown':his_duration($elapsed)).' </td>'
                            .'<td>&nbsp;'.($increments==0?_('Unavailable'):$increments).' </td>'):'')
                .'<td> '.$type.' </td></tr>';
    }
  }
  fclose($handle);
}
if ($list)
  for ($i=count($list); $i>=0; --$i) echo $list[$i];
else
  echo "<tr><td colspan='5' style='text-align:center;padding-top:12px'>"._('No parity check history present')."!</td></tr>";
?>
 
