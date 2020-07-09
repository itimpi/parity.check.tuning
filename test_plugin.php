#!/usr/bin/php
<?PHP
/*
 * Script that is used during testing to help with testing a .plg plugin
 * file before it is committed to gitHub.
 *
 * It acts as a wrapper for the Unraid built-in 'plugin' command that
 * needs a full path to the .plg file when testing a local copy.  It also
 * carries out some rudimentary checks on the file being valid XML.
 *
 * It can also (optionally) check that an associated CA template is at least valid XML.
 * Assumpions:
 * - Plugin file is located in the current folder with a name of the form <plugin-name>.plg
 * - CA template (if being used) is also in current folder named as <plugin-name>.xml
 *
 * Copyright 2019, Dave Walker (itimpi).
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

$cwd = __DIR__;

function usage() {
    echo "Usage:\n";
    echo "  " . basename(__FILE__) . " method\n";
    echo "where method is one of:\n";
    echo "  validate   only do simple XML valdation of .plg and (if present) .xml files\n";
    echo "  install    normal install of plugin\n";
    echo "  forced     forced install of plugin\n";
    echo "  remove     remove plugin\n";
    echo "  check      check plugin\n";
    echo "  update     update plugin\n";
    echo "  template   install CA template for the plugin\n\n";
}

$mthd = $argv[1];
switch ($mthd) {
    case 'validate':
    case 'install':
    case 'remove':
    case 'update':
    case 'check':
    case 'template':
        $forced = '';
        break;

    case 'forced':
        $mthd = 'install';
        $forced = ' forced';
        break;

    default:
        usage();
        exit (2);

}

// Use .plg file to derive plugin name

$files = glob("$cwd/*.plg");
if (empty($files)) {
    echo "ERROR:  Unable to find any .plg files in current directory\n";
    exit(1);
} elseif (count($files) != 1 ) {
    echo "ERROR;  More than 1 .plg file in current directory\n";
    echo $files;
    exit(1);
} else {
    $pluginFile = basename($files[0]);
    $pluginName = preg_replace('/\\.[^.\\s]{3,4}$/', '', $pluginFile);;
    echo "\n$pluginName\n\n";

    // Do a simple validation of the .plg file contents
    $domplg = new DOMDocument;
    if (! $domplg->Load($cwd . '/' . $pluginFile)) {
      echo "ERROR: There are XML errors in your .plg file\n";
      exit (3);
    }
    echo "INFO: $pluginFile appears to be properly structured XML\n";
    // if (! $dom->Validate()) {
    //   echo "\nERROR: There are validation errors in your .plg file\n";
    //   exit (4);
    // }
}

// CA template checks
$files = glob("$cwd/*.xml");
if (empty($files)) {
    if ($argv[1] == "template") {
        echo "ERROR:  Unable to find any .xml files in current directory\n";
        exit(1);
     }
     $templateFile = "";
} elseif (count($files) != 1 ) {
    echo "ERROR;  More than 1 .xml file in current directory\n";
    echo $files;
    exit(1);
} else {
    $templateFile = basename($files[0]);
    // Do a simple validation of the .xml file contents
    $domtmp = new DOMDocument;
    if (! $domtmp->Load($cwd . '/' . $templateFile)) {
      echo "ERROR: There are XML errors in your .xml file\n";
      exit (3);
    }
    echo "INFO: $templateFile appears to be properly structured XML\n";
    // if (! $dom->Validate()) {
    //   echo "\nERROR: There are validation errors in your .plg file\n";
    //   exit (4);
    // }
}

// Carry out the requested action

switch ($argv[1]) {
    case "validate":
        break;

    case "template":
        @mkdir ("/boot/config/plugins/dockerMan/templates-user/");
        copy ("$cwd/templateFile", "/boot/config/plugins/dockerMan/templates-user/$templateFile");
        echo "INFO; CA Template installed so can be tested from Apps tab\n";
        break;

    default:
        $cmd = "plugin $mthd " . ($argv[1] == "install" ? "$cwd/" : "" ) . "$pluginName.plg$forced";
        echo "INFO:  $cmd\n";
        system ($cmd, $retval);
        echo "\nReturn value: $retval\n";
}

echo "\nINFO: $pluginName $argv[1]\n Completed\n\n";
exit(0);


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