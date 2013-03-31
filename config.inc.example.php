<?php

// General

$archive_path = '/path/'; // This is where we're going to put our local backups
$expire_after = 15; // How many days should Amazon hold on to a backup?
$notify_email = 'email@goes.here'; // Comma-separated list of email address to notify on successful backup
$notify_sitename = 'mysite.com'; // Name to use for email notification subject line
$date_format = "Y-m-d"; // Format for the date string in archive file names
$paths_to_archive = array('/path/to/archive/files/'); // The local paths we want to back up. Leave it empty if you don't want to back files up.
$archive_exclude_patterns = array('cache'); // Patterns to be excluded from the archive (e.g., to exclude cache files)
$incremental = false; // Set to true to only archive files modified since the last full backup


// Database

$db_host   = '';
$db_name   = '';
$db_user   = '';
$db_pwd    = '';

// Amazon

$amazon_key = '';
$amazon_secret = '';
$bucket = '';

