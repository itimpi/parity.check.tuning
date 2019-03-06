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

// @TODO:  Process any arguments
//  Ideas:  -v string   Suffix to be added to the version to easily support multiple versions on the same date
//          -g          Automatically push the package related files to gitHub as part of building the package

echo "\nCreating package $pkg\n\n";

@unlink ("../$pkg.pkg");
// @TODO  Might want to conider supressing makepkg output?
exec ("makepkg --chown y ../$pkg.txz");
chdir ("/boot/$plugin");
$md5 = exec ("md5sum $pkg.txz");
echo "\nMD5: $md5\n";
$handle = fopen ("$pkg.md5", 'w');
fwrite ($handle, strtok($md5," "));
fclose ($handle);
copy("$pkg.txz", "archives/$pkg.txz");
copy("$pkg.md5", "archives/$pkg.md5");
unlink("$pkg.txz");
unlink("$pkg.md5");
echo "\nPackage $pkg created\n";

// Now update .plg and .xml files with package version and MD5 information
insertChanges("$plugin.plg");
insertChanges("$plugin.xml");
chdir ($cwd);
exit (0);

// Insert change.txt file into .plg or .xml files (if they exist at all)
function insertChanges($filename) {
    global $md5;
    global $ver;
    $basename = substr($filename, 0, strlen($filename) - 3);
    $extname =  substr($filename, -3);
    echo "\n" . strtoupper($extname) . " file ($filename)\n";
    
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
        if (startsWith($inl,'<!ENTITY version ')) {
            echo 'Updating VERSION to "' . $ver . "\"\n";
            fputs($out,substr($inl,0,18) . $ver . "\">\n");
        } elseif (startsWith($inl,'<!ENTITY md5 ')) {
            echo 'Updating MD5 to "' . strtok($md5, " ") . "\"\n";
            fputs($out,substr($inl,0, 13) . '"' . strtok ($md5, " ") . "\">\n");
        } elseif (startsWith ($inl,'<CHANGES>',true)) {
            echo 'Updating ' . substr($inl, 1, 7) . " from 'changes' file\n"; 
            fputs($out, $inl);
            $changes=file("changes");
            foreach($changes as $chg)  fputs($out, $chg);
            $skipping = true;      
            fputs($out,'</' . substr($inl,1));
        } elseif (startsWith ($inl,'</CHANGES>', true)) {
            $skipping = false;
        } elseif (startsWith ($inl,'<Date>')) {
            echo "Updating Date to $ver\n";
            fputs($out, '<Date>' . $ver . "</Date>\n");
        } elseif (! $skipping) {
            fputs ($out, $inl);
        }
    }
    copy ($filename, "archives/$basename-$ver.$extname");
    unlink ($filename);
    rename ("$filename.tmp", $filename);
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