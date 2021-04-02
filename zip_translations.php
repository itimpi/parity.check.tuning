#!/usr/bin/php
<?PHP
/*
 * Script that is used during testing to help with testing a the multi-languade
 * support for this plugin. It is only handles the English languague support.
 *
 * It starts by doing a simple syntax check on the contents of a translation file flagging 
 * obvious syntax errors.  If no error found then zips up the English translation file so 
 * you can install it using Developer mode in the Tools->Languages section.
 *
 * Copyright 2020-2021, Dave Walker (itimpi).
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

// Use .plg file to derive plugin name

$cwd = dirname(__FILE__);
chdir ($cwd);
$files = glob("$cwd/*.plg");
if (empty($files)) {
    echo "ERROR:  Unable to find any .plg files in current directory\n";
    exit(1);
} elseif (count($files) != 1 ) {
    echo "ERROR;  More than 1 .plg file in current directory\n";
    echo $files;
    exit(1);
} else {
    $plugin = preg_replace('/\\.[^.\\s]{3,4}$/', '', basename($files[0]));;
    // echo "\nPLUGIN: $plugin\n";
    echo "\n$plugin\n" . str_repeat('-', strlen($plugin)) . "\n";
}

// Tidy up name of translations file
$transName = str_replace(".","","$plugin");
$transName = str_replace("-","","$transName");
$transName = strtolower( $transName);

// carry out some simple validation of translations file for valid syntax

$lineCount=0;
$errorCount=0;

$transFile = fopen("$transName.txt", 'r');
if (!$transFile) {
	echo "\nERROR: Failed to open $transName\n";
	exit(3);
}

echo "\nINFO:  Validating syntax of translations file $transName.txt\n";

while (($line = fgets($transFile)) != false) {
	$lineCount++;
	$line = str_replace("\n", '', $line);
	// 	handle empty lines
	if (empty($line)) {
		continue;
	}
	// Handle comments
	if ($line{0} == ';') {
		continue;
	}
	
	// 	handle Help text
	if ($line{0} == ':') {
//		if ! (endsWith($line, 'plug:')) {
//			errorLine("Missing ':plug' at end of start line for help text");
//		}
		continue;
	}
	if ($line{0} == '>') continue;
	
	// handle standard text strings	
	if (strpos($line,'=') == false) {
		errorLine("No '=' character found to terminate key field"); 
		continue;
	}
	$key = substr($line,0,strpos($line,'='));
	$reservedCharacters = "?{}|&~![]()/:*^.\"\'";
	for ($x=0; $x < strlen($reservedCharacters); $x++) {
		if (strpos($key, $reservedCharacters{$x})) errorLine("reserved character '" . $reservedCharacters{$x} . "' found in key field"); 
	}
}

fclose ($transFile);
if ($errorCount > 0) {
	echo "\nERROR: $errorCount syntax errors found in $transname file\n";
	echo "INFO:  Fix these before trying again\n\n";
	exit (6);
}
echo "INFO:  No syntax errors detected in translations file $transName.txt\n";

// Create zip

echo "\nINFO: Creating zip file\n";
@unlink ("$transName.zip");
// passthru ("zip -v \"$transName.zip\" \"$transName.txt\"");
passthru ("zip -v \"$transName.zip\" *.txt");
echo ("\nCreated $transName.zip\n");
echo ("You can now use Developer mode in Tools->Language to load this into a running Unraid 6.9.0 (or later) system\n\n");

exit (0);

// Handle informing user about an error thzt has been detected

function errorLine ($reason) {
	$GLOBALS['errorCount']++;
	echo "\nLine " . $GLOBALS['lineCount'] . ": " . $GLOBALS['line'];
	echo "\nERROR:  $reason\n";
}

function startsWith($haystack, $beginning, $caseInsensitivity = false)
{
    if ($caseInsensitivity)
        return strncasecmp($haystack, $beginning, strlen($beginning)) === 0;
    else
        return strncmp($haystack, $beginning, strlen($beginning)) === 0;
}

function endsWith($haystack, $ending, $caseInsensitivity = false){
    if ($caseInsensitivity)
        return strcasecmp(substr($haystack, strlen($haystack) - strlen($ending)), $haystack) === 0;
    else
        return strpos($haystack, $ending, strlen($haystack) - strlen($ending)) !== false;
}

?>
