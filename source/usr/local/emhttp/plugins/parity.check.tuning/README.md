####Parity Check Tuning###
Fine-tune the execution of long-running array operations such as Parity Checks, Parity-Sync/Data Rebuild, and Disk Clear so they are automatically run in increments at convenient times rather than having to run to completion uninterrupted. 

This will be of particular use to those who have large parity drives so that a full scheduled parity check can take a day or more to complete.   By using this plugin the parity check can be run in smaller increments that are scheduled to run when the array would otherwise be idle.  If this plugin is installed then in addition to the current fields the parity history will start showing the number of increments; the total elapsed time; and the type of check that was run for checks where the plugin is active.

Also allows for such operations to be automatically paused (and later resumed) if disk temperatures exceed specified thresholds (or alternatively for the server to be shutdown).
 
Starting with Unraid 6.9.0 it will also be possible for array operations to be restarted after an array stop/start or a shutdown/reboot.  In addition a Parity Problems Assistamt is introduced that allows for partial parity checks to be used to help with resolvinl parity check errors.


