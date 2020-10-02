#!/usr/bin/php
<?PHP
/*
 * Script that is used during testing to help with testing a the multi-languade
 * support for this plugin. It is only thestinl the English languagu support.
 *
 * It zips up the English translation file so that you can install it using
 * Developer mode in the Tools->Languages section.
 *
 * Copyright 2020, Dave Walker (itimpi).
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

$zipfile = new ZipArchive();
$zipfile->open("$plugin.zip", ZIPARCHIVE::CREATE);
$zipfile->addFile("$plugin.txt");
$zipfile->close();
echo ("\nCreated $plugin.zip\n");
echo ("You can now use Developer mode in Tools->Language to load this into a running Unraid 6.9.0 (or later) system\n");
