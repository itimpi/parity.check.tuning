# Parity Check Tuning  

Fine-tune the execution of long-running array operations on Unraid servers such as Parity Checks, Parity-Sync/Data Rebuild, and Disk Clear so they are automatically run in increments at convenient times rather than having to run to completion uninterrupted.

This will be of particular use to those who have large parity drives which can mean a full scheduled parity check can take a day or more to complete.   By using this plugin the parity check can be run in smaller increments that are scheduled to run when the array would otherwise be idle.  

The plugin records extra information in the parity check history such as the number of increments; the total elapsed time; and the type of check that was run.  Notifications about array operations sent initiated by the plugin will be more detailed than those that are built as standard into unRaid.

The plugin also allows for such operations to be automatically paused (and later resumed) if disk temperatures exceed specified thresholds (or alternatively for the server to be shutdown).

Starting with Unraid 6.9.0 it is also possible for parity checks to be restarted after an array stop/start or a shutdown/reboot.  In addition a Parity Problems Assistamt is introduced that allows for partial parity checks to be used to help with resolving parity check errors.
  
Tne plugin includes extensive help built into the GUI.  The help for individual settings can be accessed by clicking on the text of the setting or the Help icon <?> at the top right of the unRaid GUI can be used to toggle it all on/off.
  
More details can be found in the plugin's [Support thread](https://forums.unraid.net/topic/78394-plugin-parity-check-tuning/) in the Unraid forums.

A template to allow this plugin to be installed via the Unraid Community Applications (CA) plugin is available in my Unraid-CA-Templates repository.


