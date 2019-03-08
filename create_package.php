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

// Now update .plg file with package version and MD5 value and changes text

echo "\nPLG\n";

if (! file_exists("$plugin.plg")) {
    echo "INFO: Could not find $filename\n";
    return;
} 

$in = file("$plugin.plg");
$out = fopen("$plugin.plg.tmp", 'w');
$skipping = false;
foreach ($in as $inl)
{
    if (startsWith($inl,'<!ENTITY versn ')) {
        echo 'Updating VERSION to "' . $ver . "\"\n";
        fputs($out,substr($inl,0,16) . $ver . "\">\n");
    } elseif (startsWith($inl,'<!ENTITY md5 ')) {
        echo 'Updating MD5 to "' . strtok($md5, " ") . "\"\n";
        fputs($out,substr($inl,0, 13) . '"' . strtok ($md5, " ") . "\">\n");
    } elseif (! $skipping) {
        fputs ($out, $inl);
    }
}
copy ("$plugin.plg", "archives/$plugin-$ver.plg");
unlink ("$plugin.plg");
rename ("$plugin.plg.tmp", "$plugin.plg");


chdir ($cwd);
exit (0);


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