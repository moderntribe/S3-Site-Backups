<?php
/*
 * Archive and backup to Amazon S3 for Simply Recipes
 * by Jesse Gardner, 03.28.2012
 *  - modified by Peter Chester, 09.04.2012
 *  - modified by Jonathan Brinley, 03.30.2013
 */

// Global variables

if ( !empty($argv[1]) && file_exists($argv[1]) ) {
	include_once($argv[1]);
} elseif ( file_exists('config.inc.php') ) {
	include_once('config.inc.php');
} else {
	die('Error: please copy config.inc.example.php to config.inc.php file with all the required vars.');
}

require_once './S3_Backup.class.php';

$backup = new S3_Backup($archive_path);

if ( !empty($date_format) ) {
	$backup->set_date_format($date_format);
}

if ( !empty($amazon_key) && !empty($amazon_secret) && !empty($bucket) ) {
	// Set up the AmazonS3 class
	require_once './s3/sdk.class.php';
	$backup->init_s3($bucket);
}

if ( !empty($paths_to_archive) ) {
	$current_time = time();
	if ( !empty($incremental) && file_exists($archive_path . 'last-full-backup.log') ) {
		$modified_since = (int)file_get_contents($archive_path . 'last-full-backup.log');
	} else {
		$modified_since = 0;
	}
	$archive_exclude_patterns = empty($archive_exclude_patterns)?array():$archive_exclude_patterns;
	$backup->archive_files($paths_to_archive, $archive_exclude_patterns, $modified_since);
	if ( empty($modified_since) ) {
		file_put_contents($archive_path . 'last-full-backup.log', $current_time);
	}
}

if ( !empty($db_host) && !empty($db_name) && !empty($db_user) && !empty($db_pwd) ) {
	$backup->archive_database( $db_name, $db_user, $db_pwd, $db_host );
}

$successfully_sent = $backup->send_to_s3();

if ( $successfully_sent && !empty($notify_email) ) {
	$backup->send_notification_email( $notify_email, $notify_sitename );
}

if ( !empty($expire_after) ) {
	$backup->set_expiration_rules($expire_after);
}