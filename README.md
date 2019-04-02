####Parity Check Tuning###
Fine-tune the execution of long-running array operations such as Parity Checks, Parity-Sync/Data Rebuild, and Disk Clear so they are automatically run in increments at convenient times rather than having to run to completion uninterrupted.  Also allows for such operations to be automatically paused (and later resumed) if disk temperatures exceed specified thresholds.

This will be of particular use to those who have large parity drives so that a full scheduled parity check can take a day or more to complete.   By using this plugin the parity check can be run in smaller increments that are scheduled to run when the array would otherwise be idle.

More details can be found in the plugins [Support thread](https://forums.unraid.net/topic/78394-plugin-parity-check-tuning/) in the Unraid forums.

A template to allow this plugin to be installed via the Unraid Community Applications (CA) plugin is available in my Unraid-CA-Templates repository