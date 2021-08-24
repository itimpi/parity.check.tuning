<?PHP
/*
 * Script that is run to display any error parity check related entries from syslog
 *
 * Copyright 2019-2021, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * Limetech is given explicit permission to use this code in any way they like.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?

// multi language support

$plugin = "parity.check.tuning";
$docroot = $docroot ?: $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
$translations = file_exists("$docroot/webGui/include/Translations.php");
if ($translations) {
  // add translations
  $_SERVER['REQUEST_URI'] = 'paritychecktuning';
  require_once "$docroot/webGui/include/Translations.php";
  // read translations
  parse_plugin('paritychecktuning');
} else {
  // legacy support (without javascript)
  $noscript = true;
  require_once "$docroot/plugins/parity.check.tuning/Legacy.php";
}

require_once '/usr/local/emhttp/webGui/include/Helpers.php';
require_once '/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.jelpers.php';

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

<table class='share_status'><tbody>
<?
	// Build a list of entries from syslog that relate to parity checks
	//
	// Example syslog messages
	// ~~~~~~~~~~~~~~~~~~~~~~~
	// kernel: mdcmd (36): check nocorrect
	// kernel: md: recovery thread: check P Q
	// kernel: md: recovery thread: P corrected, sector=496
	// kernel: md: recovery thread: P incorrect, sector=432
	// kernel: md: sync done. time=105689sec
	// kernel: md: recovery thread: exit status: 0
	$results = array();
	$resultCode = 0;
	parityTuningLoggerTesting('Scanning syslog for parity check entries');
	$cmd = 'cat /var/log/syslog | fgrep "kernel: md: recovery thread: "';
	parityTuningLoggerTesting('... using command: ' . $cmd);
	exec ($cmd, $results, $resultCode);	
	$entryCount = 0;
	if (count($results) > 0) {
		// Note: Can be none zero if TESTING mode active - need to allow for this
		foreach ($results as $line) {
			if (! strpos($line,'TESTING')) {
				echo "<tr><td>$line</td></tr>";
			}
		}
	}
	if ($entryCount == 0) {
		echo "<tr><td colspan='5' style='text-align:center;padding-top:12px'>"._('No parity check entries found in syslog')."!</td></tr>";
	}
	parityTuningLoggerTesting("resultCode: $resultCode, " . count($results) . ' parity check related entries found in syslog');
?>
</tbody></table>
<div style="text-align:center;margin-top:12px"><input type="button" value="<?=_('Done')?>" onclick="window.close()"></div>
</body>
</html>
