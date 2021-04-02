#!/usr/bin/php
<?PHP
/*
 * Script that is run to build the plugin package.
 *
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
    echo "\nPLUGIN: $plugin\n";
}

// current script directory
$cwd = dirname(__FILE__);
chdir ($cwd);

// Carry out PHP syntax check on all .php and .page files in the plugin

$failcount = syntaxCheckDirectory($cwd);
$failcount += syntaxCheckDirectory($cwd . "/source/usr/local/emhttp/plugins/$plugin");
echo "\nINFO: $failcount files failed syntax check\n";
if ($failcount != 0) {
	echo "ERROR: Fix syntax errors before trying again\n";
	exit -5;
}

// Ensure permissions are correct for runtime use
exec ("chown -R root *");
exec ("chgrp -R root *");
chdir ("$cwd/source");
$ver = date("Y.m.d");
$pkg = "$plugin-$ver";

// @TODO:  Process any arguments
//  Ideas:  -v string   Suffix to be added to the version to easily support multiple versions on the same date
//          -g          Automatically push the package related files to gitHub as part of building the package

echo "\nCreating package $pkg\n\n";

@unlink ("../$pkg.pkg");
exec ("chown -R root *");
exec ("chgrp -R root *");
$output=null;
$retval=null;
// @TODO  Might want to consider supressing makepkg output?
exec ("makepkg --chown y ../$pkg.txz", $output, $retval);
if ($retval != 0) {
	echo "\nERROR: Package creation failed (return value $retval)\n";
	exit -5;
}
chdir ("$cwd");
$md5 = exec ("md5sum $pkg.txz");
echo "\nMD5: $md5\n";
$handle = fopen ("$pkg.md5", 'w');
fwrite ($handle, strtok($md5," "));
fclose ($handle);
if ( !is_dir("archives" )) mkdir("archives" );
copy("$pkg.txz", "archives/$pkg.txz");
copy("$pkg.md5", "archives/$pkg.md5");
unlink("$pkg.txz");
unlink("$pkg.md5");
echo "\nPackage $pkg created\n";
chdir ($cwd);
// Now update .plg file with package version and MD5 value and changes text

echo "\nPLG\n";

if (! file_exists("$plugin.plg")) {
    echo "INFO: Could not find $plugin.plg\n";
    return;
}

$in = file("$plugin.plg");
$out = fopen("$plugin.plg.tmp", 'w');
$skipping = false;
foreach ($in as $inl)
{
    if (startsWith($inl,'<!ENTITY version ')) {
        echo 'Updating VERSION to "' . $ver . "\"\n";
        fputs($out,substr($inl,0,18) . $ver . "\">\n");
    } elseif (startsWith($inl,'<!ENTITY md5 ')) {
        echo "Updating MD5 to \"" . strtok($md5, " ") . "\"\n";
        fputs($out,substr($inl,0, 13) . '"' . strtok ($md5, " ") . "\">\n");
    } elseif (! $skipping) {
        fputs ($out, $inl);
    }
}
copy ("$plugin.plg", "archives/$plugin-$ver.plg");
unlink ("$plugin.plg");
rename ("$plugin.plg.tmp", "$plugin.plg");

// Ensure permissions are OK for public network access
exec ("chown -R nobody *");
exec ("chgrp -R users *");
exec ("chmod -R 755 *");

chdir ($cwd);
exit (0);

// Look through the supplied directory for any .php or .page files to syntax check

function syntaxCheckDirectory($path) {
	$failcount = 0;
	// echo "checking directory $path\n";
	$fileList = glob("$path/*.page");
	foreach($fileList as $filename){
		if (! syntaxCheckFile($filename)) $failcount++;
	}
	$fileList = glob("$path/*.php");
	foreach($fileList as $filename){
		if (! syntaxCheckFile($filename)) $failcount++;
	}
	return $failcount;
}

// Run a PHP syntax check on the supplied file

function syntaxCheckFile($filename) {
	// echo "checking file $filename\n";
	$output=null;
	$retval=null;
	exec("php -l $filename", $output, $retval);
	if ($retval == 0) {
		return true;
	} else {
		// echo "Returned with status $retval and output:\n";
		// print_r($output);
		echo "\nINFO: " . basename($filename);
		passthru("php -l $filename");
		return false;
	}
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

