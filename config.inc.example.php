<?php
/**
 * S3 Backup Configuration File
 */

// Amazon account details
$amazon_key    = '';
$amazon_secret = '';
$bucket        = '';

// Database details
$db_host       = '';
$db_name       = '';
$db_user       = '';
$db_pwd        = '';

// Full path to the local directory where backups are staged
$archive_path = '/tmp/s3backups/';

// Number of days Amazon should hold on to a backup
$expire_after = 15;

// Comma-separated list of email addresses to notify on successful backup
$notify_email = 'email@goes.here';

// Name to use for email notification subject line
$notify_sitename = 'mysite.com';

// Format for the date string in archive file names
$date_format = "Y-m-d";

// Full path to the root directory of the website. Leave it empty if you don't want to back files up
$base_path = '/path/to/site/root/';

// Relative paths we want to back up. Leave it empty if you don't want to back files up
$dir_paths = array(
	'path/to/archive/folder1',
	'path/to/archive/folder2',
);

// Patterns to be excluded from the archive (e.g., to exclude cache files)
$archive_exclude_patterns = array('cache');

// Set to true to only archive files modified since the last full backup
$incremental = false;

// Set to true if you want to see output as the script proceeds.
$verbose = false;

?>