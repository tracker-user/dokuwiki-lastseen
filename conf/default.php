<?php
/**
 * Default settings for the lastseen plugin.
 */

$conf['update_interval']  = 600;   // throttle: min seconds between writes per user
$conf['show_never']       = 1;     // list users never seen since install
$conf['show_mail']        = 1;     // show the Email column
$conf['show_grps']        = 1;     // show the Groups column
$conf['entries_per_page'] = 20;    // rows per page in the table; 0 == show all
