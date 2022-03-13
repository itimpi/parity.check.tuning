# Parity Check Tuning Information #

These design notes are intended to capture useful information used in relation to the Unraid Parity Check Tuning plugin and any resulting design decisions.

### Dynamix configuration entries
The file /boot/config/plugins/dynamix/dynamix.cfg has a [parity] section with the following entries

| Option     | Comment        
|------------|----------------
| mode       | 0 Disabled<br>1 Daily<br>2 Weekly<br>3 Monthly<br>4 Yearly<br>5 Custom
| dotm       | always appears to be 1?
| hour       | *hh mm* Start time
| day        | 1-7 Day of week (if relevant)
| month      | 1-12 Month of year (if relevant)
| write      | *empty*   Correcting check<br>NOCORRECT non-correcting check
| cumulative | 0 No<br>1 Yes<br>
| frequency  | empty<br>Not using increments<br>1 Daily<br>7 Weekly
| duration   | Number of Hours

The cumulative increments always start at the same time as the original check started


### mdcmd

Unraid has the *mdcmd* command located at */usr/local/sbin/mdcmd*

Options it is known to take are:

| Option                | Comment 
| ------                | ------- 
| status                | Provides a dump to stdout of the /proc/mdstat information
| check                 | Initiates a correcting parity check
| check CORRECT         | Initiates a correcting parity check
| check NOCORRECT       | Initiate a non-correcting parity check
| nocheck               | Cancels a running parity sync/check
| set md_write_method 1 | Enable Turbo write
| set md_num_stripes x  |
| set md_sync_window x  | 
| set md_sync_thresh x  |
| set nr_requests x     | Default is 1.  Max is 32
| set invalidslot x y   | where 0 is parity1, 29 is parity2, 1-28 correspond to disk1-disk28.  Often used after New Config to force disks to help with recovery  
| spinup x              |
| spindown x            |
| start                 | This seems to start (create) the /dev/md? devices.  Does NOT start the array. 
| stop                  | This seems to stop (remove) the /dev/md? devices. Does NOT stop the array.

The plugin replace the standard **mdcmd** command with a custom version that invokes the plugin support code so we know mdcmd was used but otherwise functions as normal

#### 6.9.0 beta

First, review: Here are the sync related commands:

```plaintext
"mdcmd check" - same as "mdcmd check CORRECT"
"mdcmd check CORRECT"
"mdcmd check NOCORRECT"
"mdcmd check RESUME"

"mdcmd nocheck" - same as "mdcmd nocheck CANCEL"
"mdcmd nocheck CANCEL"
"mdcmd nocheck PAUSE"
```
The operation that "check" performs is one of:

parity check - in this case NOCORRECT means read-only, CORRECT writes corrections to parity
parity sync - CORRECT/NOCORRECT ignored (always writes parity)
data rebuild - CORRECT/NOCORRECT ignored (always writes data)
data clear - CORRECT/NOCORRECT ignored (always writes zeros)
 

An optional starting 'offset' argument has been added to "mdcmd check" as follows:

```plaintext
"mdcmd check" - same as "mdcmd check CORRECT 0"
"mdcmd check CORRECT [ offset ]"
"mdcmd check NOCORRECT [ offset ]"
```

"offset" is in 512-byte units (sectors) but must be a multiple of 8.  If omitted, default is 0.

### mdstat

This is information gathered by reverse engineering the /proc/mdstat file in Unraid 6.7 that 
appears to provide information about parity checks

| sbSynced | sbSynced2 | mdState | mdResyncAction | mdResyncSize |mdResync | mdResyncPos | Comment
| -------- | --------- | ------- | -------------- | ------------ | ------- | ----------- | -------
| value    | value     | STARTED | check P        | 9766436812   | 0          | 0           | Completed (=Idle)
| value    | 0         | STARTED | check P        | 9766436812   | 9766436812 | 1422960     | Sync running
| value    | larger    | STARTED | check P        | 9766436812   | 0          | 1795652     | Sync paused
| value    | 0         | STARTED | check P        | 9766436812   | 9766436812 | 3034452     | Resumed
| value    | value     | STARTED | check P        | 9766436812   | 0          | 0           | Sync Cancelled (=idle)

**Analysis Results**


| Field  | Value | Meaning |
|----------|----------| -------|
| sbSynced | |  time last check started as Unix time
| sbSynced2      |              | time of last completion as Unix time.   duration = sbSynced2-sbSynced
| sbSyncErrs     |              | Error count from last operation
| sbSyncExit     |              | exit code of last operation.  0=success, -4=cancelled
|                |              |
| mdState        | STARTED      | array is started (either normal or maintenance mode
|                | STOPPED      | array is not started
|                | NEW_ARRAY    |
|                | DISABLE_DISK | Missing disk
|                | RECON_DISK   | Ready to rebuild disk
|                | SWAP_DSBL    |
|                | ERROR:?????? | Various error conditions
| mdNumDisks     |              |
| mdNumDisabled  |              | not sure when this gets reset as seen it set to 1 with all disks OK in GUI
| mdNumReplaced  |              |
| mdNumInvalid   |              | Not sure when this gets reset as seen it set to 2 with all disks OK in GUI
| mdNumWrong     |              |
| mdSwapP        |              |
| mdswapQ        |              |
| mdResyncAction | check        | read-only check of data disks
|                | check x      | read-only check of parity disk where 'x' can be 'P' or 'Q' (or both) indicating parity disk(s) involved
|                | recon x      | parity build/disk rebuild where 'x' can be any combination of P, Q, Dn (with n being a disk number)
|                | clear        | clear disk in progress 
| mdResyncSize   |              | Size of largest parity disk in K (sectors = 2 * value)
| mdResyncCorr   | 0            | read check only
|                | 1            | correcting check
| mdResync       | 0            | sync not running. 
|                | value        | total size to be synced.  Should be same as mdResyncSize
| mdResyncPos    | 0            | Sync not running 
|                | value        | position reached
| mdResyncDt     |              |
| mdResyncDb     |              |
|                |              |
| rdevStatus.?   | DISK_OK      | Normal - disk is fine
|                | DISK_INVALID | Disk is being emulated
|                | DISK_NP      | Disk not present


| Request   | Comment 
| -------   | -------
| Resume    | name="cmdCheck" value="Resume
| Pause     | name="cmdNoCheck" value="Pause"
| cancel    | name="cmdNoCheck" value="Cancel"


The file */etc/nginx/conf.d/emhttp-servers.conf* hs the following entries to pass certain pages through to emhttp:

    # proxy update.htm and logging.htm scripts to emhttpd listening on local socket
    #
    location = /update.htm {
        keepalive_timeout 0;
        proxy_read_timeout 180; # 3 minutes
        proxy_pass http://unix:/var/run/emhttpd.socket:/update.htm;
    }
    location = /logging.htm {
        proxy_read_timeout 864000; # 10 days(!)
        proxy_pass http://unix:/var/run/emhttpd.socket:/logging.htm;
    }


### Excerpt from ArrayOperations.page

    function stopParity(form,text) {
        $(form).append('<input type="hidden" name="cmdNoCheck" value="Cancel">');
        <?if ($confirm['stop']):?>
            swal({title:'Proceed?',text:'This will stop the running '+text+' operation',type:'warning',showCancelButton:true},function(p){if (p) form.submit(); else $('input[name="cmdNoCheck"]').remove();});
        <?else:?>
        form.submit();
        <?endif;?>
    }
    function pauseParity(form) {
        $(form).append('<input type="hidden" name="cmdNoCheck" value="Pause">');
        $('#pauseButton').val('Resume').prop('onclick',null).off('click').click(function(){resumeParity(form);});
        form.submit();
    }
    function resumeParity(form) {
        $(form).append('<input type="hidden" name="cmdCheck" value="Resume">');
        $('#pauseButton').val('Pause').prop('onclick',null).off('click').click(function(){pauseParity(form);});
        form.submit();
    }

** Cron Entry format **

Minutes hour * * * php -f /usr/local/emhttp/plugins/parity.check.tuning.php pause/resume

### Parity History File

Unraid stores parity history in the file */boot/config/parity-checks.log*

Entries include the following fields (| seperated):

| Name      | Comment
|-----------|--------
| $date     | When the operation was started
| $duration | The elapsed time.  For Unraid versions pior to 6.10.0 this only counted the last increment if pause/resume was used
| $speed    | The elapsed time.  For Unraid versions pior to 6.10.0 if pause/resume was used it assumed the whole check completed in thzt time which gave incorrect speed. 
| $status   |  Exit code<br>0 Success<br>-4 error/canceled<br>-5 Canceled (detected by plugin)
| $error    | Number or error found and/or corrected.  Cannot tell which unless plugin extented action field present.
| $type     | (New for Unraid 6.10.0) text field describing the operation type.

Unraid 6.10.0 started adding other operations other than parity checks to this file.

The plugin then adds some extra fields on the end. 

| Name        | Comment
|-------------|----------------|
| $elapsed    | The corrected elapsed time
| $increments | The number of increments (blank if unknown)
| $action     | text field added in later plugin versions describing the operation type that is is more detailed than the *$type* field used by Unraid as it includes how the operation was started and if a parity check was correcting or not.

To provide compatibility across different Unraid versions and different plugin versions the number of fields found are used to determine the format of the current record as follows:

| Number of fields | Comment      |
|----------------|----------------|
| 5 | The ofiginal Unriad f/rmzt trior to 6.10.0 
| 6 | format introduced with 6.10.0 that added $type field
| 7 | original plugin format (without $action field)
| 8 | plugin format when $action field added
| 9 | plugin format for Unraid 6.10.0 onwards




=




$date,$duration,$speed,$status,$error

### Syslog entries

You get entries in the syslog of the form:

```plaintext
kernel: md: recovery thread: P corrected, sector=677922720
```
Where P indicates parity1 and Q indicates parity2



