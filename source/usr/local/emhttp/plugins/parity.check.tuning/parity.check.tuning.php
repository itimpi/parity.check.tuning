#!/usr/bin/php
<?PHP
/*
 * Script that is run to carry out support tasks for the parity.check.tuning plugin.
 *
 * It can be triggered in a variety of ways such as an Unraid event; a cron job;
 * a page file; or from another script.
 *
 * It takes a single parameter descrbing the action required.   If no explicit
 * action is specified then it merely updates the cron jobs for this plugin.
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

require_once "/usr/local/emhttp/plugins/parity.check.tuning/parity.check.tuning.helpers.php";

// Handle generating and activating/deactivating the cron jobs for this plugin
if (empty($argv)) {
  parityTuningLoggerDebug("ERROR: No action specified");
  exit(0);
}

// Check for valid argument action options
$command = trim($argv[1]);
switch ($command) {
    case "cron":
        if ($parityTuningCfg['parityTuningActive'] == "no") {
        {
            parityTuningLoggerDebug("plugin disabled");
            if (!file_exists("$parityTuningConfigDir/$plugin.cron")) {
                parityTuningLoggerDebug("No cron present so no action required");
                exit(0);
            }
            @unlink ($cronfile);
            parityTuningLoggerDebug("Removed cron settings for this plugin");
            }
        } else {
            // Create the cron file for this plugin
            $handle = fopen ("$parityTuningConfigDir/$plugin.cron", "w");
            fwrite($handle, "\n# Generated cron schedules for $plugin\n");
            fwrite($handle, $parityTuningCfg['parityTuningResumeMinute'] . " " . $parityTuningCfg['parityTuningResumeHour']  . " * * * /usr/bin/php -f $pluginDir/$plugin.php \"resume\"\n");
            fwrite($handle, $parityTuningCfg['parityTuningPauseMinute'] . " " . $parityTuningCfg['parityTuningPauseHour']  . " * * * /usr/bin/php -f $pluginDir/$plugin.php \"pause\"\n\n");
            fclose($handle);
            parityTuningLoggerDebug("Updated cron settings for this plugin");
        }
        exec("/usr/local/sbin/update_cron");
        exit (0);

    case "resume":
        parityTuningLoggerDebug ("Resume requested");
        break;
    case "pause":
        parityTuningLoggerDebug("Pause requested");
        break;
    case "cancel":
        parityTuningLoggerDebug("Cancel requested");
        break;    
    case "started":
        parityTuningLoggerDebug("Array startied event detected");
        break;
    case "stopping":
        parityTuningLoggerDebug("Array stopping event detected");
        break;
    default:
        parityTuningLoggerDebug ("Error: Unrecognised option \'$command\'");  
        parityTuningLoggerDebug ("Usage: parity.check.tuning.php \<action\>");
        parityTuningLoggerDebug ("currently recognised values for \<action\> are:");
        parityTuningLoggerDebug (" cron");
        parityTuningLoggerDebug (" pause");
        parityTuningLoggerDebug (" resume");
        parityTuningLoggerDebug (" stopping");
        parityTuningLoggerDebug (" started");
        for ($i = 0; $i < count($argv) ; i++)  parityTuningLoggerDebug('argv[' . $i . '] = ' . $argv[$i]);
        exit();
}

if (empty($vars)) {
    parityTuningLoggerDebug ("reading array state");
    $vars = parse_ini_file ("/var/local/emhttp/var.ini");
}

if ($vars['mdState'] != "STARTED") {
    parityTuningLog("Array not started");
    exit();
}

$statefile = "$parityTuningConfigDir/$parityTuningPlugin.state";
switch ($command) {
    case 'resume':
        if (strstr ($vars['mdSyncAction'], "check") != "check") {
            parityTuningLoggerDebug ("Parity check resuming");
            parityTuningPerformOp ("cmdCheck", "Resume");
            parityTuningLogger ("Parity check resumed");
        }
        exit(0);
        
    case 'pause':
        if (! $vars['mdResyncPos']) {
            parityTuningLoggerDebug("Pause requested - but no parity sync active so doing nothing");
            exit(0);
        }
        parityTuningLoggerDebug ("Parity check being paused");
        parityTuningPerformOp ("cmdNoCheck", "Cancel");
        parityTuningLogger ("Parity check paused");        
        exit(0);
        
    case 'cancel':
        if (! $vars['mdResyncPos']) {
            parityTuningLoggerDebug("Cancel requested - but no parity sync active so doing nothing");
            exit(0);
        }
        parityTuningLoggerDebug ("Parity check being cancelled");
        parityTuningPerformOp ("cmdNoCheck", "Cancel");
        parityTuningLogger ("Parity check cancelled");        
        exit(0);
        
    case 'stopping':
        if (file_exists($statefile)) {
            unlink($statefile);
            parityTuningLoggerDebug("Removed existing state file %statefile");
        }
        if (! $vars['mdResyncPos']) {
            parityTuningLoggerDebug ("no check in progress so no state saved");
            exit(0);
        } 
        # Save state information about the array aimed at later implementing handling pause/resume
        # working across array stop/start.  Not sure what we will need so at the moment guessing!
        $_POST = parse_ini_file("/var/local/emhttp/var.ini");
        $_POST['#file'] = $statefile;
        parityTuningLoggerDebug("Saving array state to $statefile");
        include "/usr/local/emhttp/update.php";
        parityTuningLoggerDebug("Saved array state");
        exit(0);
        
    case 'started' :
        if (!file_exists($statefile)) {
            parityTuningLoggerDebug("No state file found");
            parityTuningLoggerDebug("...so no further action to take");
            exit(0);
        } 
        parityTuningLoggerDebug ("Loading state file $statefile");
        $state = parse_ini_file ($statefile);
        parityTuningLoggerDebug ("... but no further action currently taken on started event");
        parityTuningLoggerDebug ("... until Limetech provide a way of (re)starting a parity check at a defined offset");
        exit(0);

    default:
        # Should not be possible to get to this point!
        parityTunninglogger ("Error: Program error|");
        exit(1);

}

// Should not be possible to reach this point!
echo "\nexiting\n";
exit(0);

//  We now try and issue the commands to carry out the desired array operation

function parityTuningPerformOp($cmdName, $cmdValue) {
    parityTuningLoggerDebug("PerformOp requested: cmdName=$cmdName, cmdValue=$cmdValue");
    exit(0);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,"http://localhost/update.htm");
    curl_setopt($ch, CURLOPT_POST, 1);
//    curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query(array(
//          'name='', "$cmdName" => "$cmdValue")));
//          name="arrayOps"
//          method="POST"
//          action="/update.htm"
//      target="progressFrame">
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Receive server response ...
    $server_output = curl_exec($ch);
    echo "\nServer Output:\n$server_ouput\n\n";
    curl_close($ch);
}
?>
