<?php
/**
 * Configuration metadata for the lastseen plugin.
 */

$meta['update_interval']  = array('numeric', '_min' => 60);
$meta['show_never']       = array('onoff');
$meta['show_mail']        = array('onoff');
$meta['show_grps']        = array('onoff');
$meta['entries_per_page'] = array('numeric', '_min' => 0);
