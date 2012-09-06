<?php
/*
 * Archive and backup to Amazon S3 for Simply Recipes
 * by Jesse Gardner, 03.28.2012 - modified by Peter Chester, 09.04.2012
 */

	// Global variables

	if (file_exists('config.inc.php')) {
		include_once('config.inc.php');
	} else {
		die('Error: please copy config.inc.example.php to config.inc.php file with all the required vars.');
	}

	// Set up the AmazonS3 class
	require_once './s3/sdk.class.php';
	$s3 = new AmazonS3();

	if ( !empty($path_to_archive) && is_dir($path_to_archive) ) {

		$backup_files = true;

		// Zip directory for backing up
		$asset_archive_filename = 'backup-files-' . $date . '.tar.gz';
		$asset_archive = $archive_path . $asset_archive_filename;

		// Zip
		//exec("(tar -cvf $asset_archive $path_to_archive) &> /dev/null &");
		exec("tar -cvf $asset_archive $path_to_archive");
		$asset_archive_size = byteConvert(filesize($asset_archive));

		// Add to S3 upload batch
		$s3->batch()->create_object($bucket, $asset_archive_filename, array('fileUpload' => $asset_archive ));

	}

	if ( !empty($db_host) && !empty($db_name) && !empty($db_user) && !empty($db_pwd) ) {

		$backup_db = true;

		// Optimize and Repair the db
		exec("mysqlcheck --host=$db_host --user=$db_user --password=$db_pwd --auto-repair --optimize $db_name");

		// Dump database for backing up
		$db_archive_filename = 'backup-db-' . $db_name . '-' . $date . '.sql.gz';
		$db_archive = $archive_path . $db_archive_filename;

		// Dump
		//exec("(mysqldump --opt --host=$db_host --user=$db_user --password=$db_pwd $db_name | gzip -9c > $db_archive) &> /dev/null &");
		exec("mysqldump --opt --host=$db_host --user=$db_user --password=$db_pwd $db_name | gzip -9c > $db_archive");
		$db_archive_size = byteConvert(filesize($db_archive));

		// Add to S3 upload batch
		$s3->batch()->create_object($bucket, $db_archive_filename, array('fileUpload' => $db_archive ));
	}

	// Give the bucket a moment to get created if it doesn't exist yet
	$exists = $s3->if_bucket_exists($bucket);
	while (!$exists) {
		// Not yet? Sleep for 1 second, then check again
		sleep(1);
		$exists = $s3->if_bucket_exists($bucket);
	}

	// Upload batch to S3
	$file_upload_response = $s3->batch()->send();

	// Success?

	if ($file_upload_response->areOK()) {
		$to = $notify_email;
		$subject = "[$notify_sitename] Nightly backup successful";
		$body = "The $notify_sitename backup just ran, successfully:\n\n";
		if ($backup_files) {
			$body .= "Asset archive: $asset_archive_filename ($asset_archive_size)\n";
		}
		if ($backup_db) {
			$body .= "Database archive: $db_archive_filename ($db_archive_size)\n";
		}
		$body .= "\n";
		$body .= "Your backups have been saved on Amazon S3. You should be able to see them here: https://console.aws.amazon.com/s3/home\n\n";
		$body .= "You can rest easy. :)\n\n";
		$body .= "~ Your Faithful Server";
		mail($to, $subject, $body);
	}

	// Set expiration rules

	$response = $s3->create_object_expiration_config($bucket, array(
	    'rules' => array(
	        array(
	            'prefix' => '', // Empty prefix applies expiration rule to every file in the bucket
	            'expiration' => array(
	                'days' => $expire_after
	            )
	        )
	    )
	));

	if ($response->isOK())
	{
	    // Give the configuration a moment to take
	    while (!$s3->get_object_expiration_config($bucket)->isOK())
	    {
	        sleep(1);
	    }
	}

	// Helper functions

	// This just helps make the file sizes in our email more human-friendly.
	function byteConvert(&$bytes){
	    $b = (int)$bytes;
	    $s = array('B', 'kB', 'MB', 'GB', 'TB');
	    if($b < 0){ return "0 ".$s[0]; }
	    $con = 1024;
	    $e = (int)(log($b,$con));
	    return number_format($b/pow($con,$e),2,'.','.').' '.$s[$e];
	}


?>
