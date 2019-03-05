#!/usr/bin/php
<?PHP
/*
 * Script that is run to build the plugin package.
 *
 * Copyright 22019, Dave Walker (itimpi).
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
 
$plugin="parity.check.tuning";

$cwd = getcwd();
chdir ("/boot/$plugin/source");
$ver = date("Y.m.d");
$pkg = "$plugin-$ver";

echo "\nCreating package $pkg\n\n";

@unlink ("../$pkg.pkg");
// @TODO  Might want to conider supressing makepkg output?
exec ("makepkg --chown y ../$pkg.tgz");
chdir ("/boot/$plugin");
$md5 = exec ("md5sum $pkg.tgze");
echo "MD5: $md5\n";
$handle = fopen ("$pkg.md5", 'w');
fwrite ($handle, strtok($md5," "));
fclose ($handle);
copy("$pkg.tgz", "archives/$pkg.tgz");
copy("$pkg.md5", "archives/$pkg.md5");
echo "\nPackage $pkg created\n\n";

// Now update .plg and .xml files with package version and MD5 information
echo "\nPLG file\n\n";
insertChanges("$plugin.plg");
echo "\nXML file\n";
insertChanges("$plugin.xml");
chdir ($cwd);
exit (0);

// Insert change.txt file into .plg or .xml files (if they exist at all)
function insertChanges($filename) {

    if (! file_exists($filename)) {
        echo "INFO: Could not find $filename\n";
        return;
    } 
    
    if (! file_exists('changes')) {
        echo "INFO: Could not find \'changes\' file\n";
        return;
    }
    $in = file($filename);
    $out = fopen("$filename.tmp", 'w');
    $skipping = false;
    foreach ($in as $inl)
    {
        echo "LINE: $inl";
        sleep(1);
        if (0 == strcasecmp($inl, "\<changes/\>"));   $skipping=false;
        if ($skipping) echo "SKIPPING: $inl";
        if (! $skipping) fputs ($out, $inl);
        if (0 == strcasecmp($inl, "\<changes\>")) {
            echo "CHANGES found: stripos=" . stripos($inl, "changes\>") . "\n";
            fputs ($out,$inl);
            $skipping = true;
            $changes=file("changes");
            foreach($changes as $chg ) 
            {
                echo "CHANGE: $chg";
                fputs($out, $chg);
                sleep(1);
            }
            echo "CHANGES completed\n";
        }
    }
}



