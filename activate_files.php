#!/usr/bin/php
<?PHP
# Script to copy package files into their final position in the unRAID runtime path.
# This can be run if the plugin is already installed without having to build the
# package or commit any changes to gitHub.   Make testing increments easier.
# (useful during testing)
#
# Copyright 2019-2021, Dave Walker (itimpi).
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License version 2,
# as published by the Free Software Foundation.
#
# Limetech is given expliit permission to use this code in any way they like.
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#

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

// Carry out PHP syntax check on all .php and .page files

$failcount = syntaxCheckDirectory($cwd);
$failcount += syntaxCheckDirectory($cwd . "/source/usr/local/emhttp/plugins/$plugin");
echo "\nINFO: $failcount files failed syntax check\n";
if ($failcount != 0) {
	echo "ERROR: Fix syntax errors before trying again\n";
	exit -5;
}

// Check that the plugin has actually been installed

if (!is_dir("/boot/config/plugins/$plugin")) {
    echo "ERROR: $plugin is not currently installed\n";
    exit (-1);
}

echo "\nINFO: Copying files from 'source' to runtime position";
system ("cp -v -r -u source/* /");
system ("chown -R root /usr/local/emhttp/plugins/$plugin");
system ("chgrp -R root /usr/local/emhttp/plugins/$plugin");
system ("chmod -R 755 /usr/local/emhttp/plugins/$plugin");

// set up files for English multi-landuage support
$dir="/usr/local/emhttp/languages/en_US";
if (file_exists($dir)) {
    system ("cp -v -r -u *.txt $dir");		// Copy across new translations file
    system ("chmod -c 644 $dir/*.txt");		// set required permissions
    system ("rm -vf $dir/*.dot");			// remove .dot file to activate re-read of translations file
}
// Update flash if necessary
$ver = date("Y.m.d");
$pkg = "$plugin-$ver";
if (file_exists("archives/$pkg.txz")) {
	echo "\nINFO: Updating flash drive\n";
    system ("rm -vf /boot/config/plugins/$plugin/*.tgz");
    system ("cp -v -r -u archives/$pkg.txz /boot/config/plugins/$plugin");
    system ("cp -v -r -u archives/$pkg.plg /boot/config/plugins/$plugin.plg");
}
echo "\n";
system ("date");
echo "INFO: Files copied\n\n";

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

?>
