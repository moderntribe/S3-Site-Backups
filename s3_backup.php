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

if ( !isset($archive_path) ) $archive_path = '/tmp';
if ( !isset($base_path) ) $base_path = '';
if ( !isset($date_format) ) $date_format = 'Y-m-d';

$backup = new S3_Backup( $archive_path, $base_path, $date_format );
$doing_s3 = FALSE;

if ( isset($verbose) ) $backup->set_verbosity( $verbose );

if ( !empty($amazon_key) && !empty($amazon_secret) && !empty($bucket) ) {
	// Set up the AmazonS3 class
	$backup->init_s3( $bucket, $amazon_key, $amazon_secret );
	$doing_s3 = TRUE;
}

if ( isset($dir_paths) && !empty($dir_paths) ) {

	$current_time = time();
	if ( !empty($incremental) && file_exists($archive_path . 'last-full-backup.log') ) {
		$modified_since = (int)file_get_contents($archive_path . 'last-full-backup.log');
	} else {
		$modified_since = 0;
	}

	if ( !isset( $archive_exclude_patterns ) ) {
		$archive_exclude_patterns = array();
	}

	$backup->archive_files($dir_paths, $archive_exclude_patterns, $modified_since);

	if ( empty($modified_since) ) {
		file_put_contents($archive_path . 'last-full-backup.log', $current_time);
	}
}

if ( !empty($db_host) && !empty($db_name) && !empty($db_user) && !empty($db_pwd) ) {
	$backup->archive_database( $db_name, $db_user, $db_pwd, $db_host );
}

if ( $doing_s3 ) {
	$successfully_sent = $backup->send_to_s3();

	if ( $successfully_sent && !empty($notify_email) ) {
		$backup->send_notification_email( $notify_email, $notify_sitename );
	}

	if ( !empty($expire_after) ) {
		$backup->set_expiration_rules($expire_after);
	}
}