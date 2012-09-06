<?php

// General

$archive_path = '/path/'; // This is where we're going to put our local backups
$expire_after = 15; // How many days should Amazon hold on to a backup?
$notify_email = 'email@goes.here'; // Comma-separated list of email address to notify on successful backup
$notify_sitename = 'mysite.com'; // Name to use for email notification subject line
$date = date("Y-m-d");
$path_to_archive = '/path/to/archive/files/'; // The local path we want to back up. Leave it empty if you don't want to back files up.

// Database

$db_host   = '';
$db_name   = '';
$db_user   = '';
$db_pwd    = '';

// Amazon

$amazon_key = '';
$amazon_secret = '';
$bucket = '';

?>