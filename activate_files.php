#!/usr/bin/php
<?PHP
# Script to copy package files into their final position in the unRAID runtime path.
# This can be run if the plugin is already installed without having to build the
# package or commit any changes to gitHub.   Make testing increments easier.
# (useful during testing)
#
# Copyright 2019, Dave Walker (itimpi).
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

echo "\nINFO: Copying files from 'source' to runtime position\n";
system ("cp -v -r -u source/* /");
system ("chown -R root /usr/local/emhttp/plugins/$plugin/*");
system ("chgrp -R root /usr/local/emhttp/plugins/$plugin/*");
system ("chmod -R 755 /usr/local/emhttp/plugins/$plugin/*");
// set up files for English multi-landuage support
$dir="/usr/local/emhttp/languages/en_US";
if (file_exists($dir)) {
    system ("cp -v -r -u *.txt $dir");
    system ("chmod -c 644 $dir/*.txt");
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
?>
