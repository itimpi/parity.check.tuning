#!/usr/bin/php
<?PHP
/*
 * Script that is used during testing to help with testing a the multi-languade
 * support for this plugin. It is only thestinl the English languagu support.
 *
 * It zips up the English translation file to that you can install it using
 * Debeloper mode in the Languages section of the SA plugin
 *
 * Copyright 2020, Dave Walker (itimpi).
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * Limetech is given expliit permission to use this code in any way they like.
 *1l
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */

$name="paritychecktuning";
// current script directory
$cwd = dirname(__FILE__);
chdir ($cwd);
$zipfile = new ZipArchive();
$zipfile->open("$name.zip", ZIPARCHIVE::CREATE);
$zipfile->addFile("$name.txt");
$zipfile->addFile("javascript$name.txt");
$zipfile->close();
echo ("\nCreated $name.zip\n");
echo ("You can now use Developer mode in Tools->Language to load this into a running Unraid 6.9.0 system\n");
