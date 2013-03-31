<?php


class S3_Backup {
	private $archived_file_log = array();
	private $archived_db_log = array();

	/** @var AmazonS3 */
	private $s3 = NULL;
	private $bucket = '';
	private $archive_path = '';
	private $modified_since = 0;
	private $date = '';
	private $date_format = 'Y-m-d';
	private $tar_exclude_patterns = array();


	public function __construct( $local_backup_dir ) {
		$this->archive_path = $local_backup_dir;
		$this->date = date($this->date_format);
	}

	public function init_s3( $bucket = '' ) {
		// S3 SDK uses credentials from the globals in config.inc.php
		$this->s3 = new AmazonS3();
		if ( !empty($bucket) ) {
			$this->set_bucket($bucket);
		}
	}

	public function set_bucket( $bucket ) {
		$this->bucket = $bucket;
	}

	public function set_date_format( $format = 'Y-m-d' ) {
		$this->date_format = $format;
		$this->date = date($this->date_format);
	}

	public function archive_files( $paths_to_archive, $patterns_to_exclude = array(), $modified_since = 0 ) {
		if ( !is_array($paths_to_archive) ) {
			$paths_to_archive = array($paths_to_archive);
		}
		if ( !is_array($patterns_to_exclude) ) {
			$this->tar_exclude_patterns = array($patterns_to_exclude);
		} else {
			$this->tar_exclude_patterns = $patterns_to_exclude;
		}
		$this->modified_since = (int)$modified_since;

		if ( !empty($paths_to_archive) ) {
			if ( !is_dir($this->archive_path) ) {
				exec("mkdir -p ".escapeshellarg($this->archive_path));
			}
			foreach ( $paths_to_archive as $path_to_archive ) {
				if ( substr($path_to_archive, '-1') == '*' ) {
					$path_to_archive = substr($path_to_archive,0,-1);
					if ( !is_dir($path_to_archive) ) {
						continue;
					}
					$subdirs = glob($path_to_archive . '*' , GLOB_ONLYDIR|GLOB_MARK);
					foreach ( $subdirs as $subpath_to_archive ) {
						if ( is_dir($subpath_to_archive) ) {
							$this->make_directory_archive($subpath_to_archive, TRUE);
						}
					}
				} else {
					if ( is_dir($path_to_archive) ) {
						$this->make_directory_archive($path_to_archive);
					}
				}
			}
		}
	}

	public function archive_database( $db_name, $db_user, $db_pwd, $db_host ) {
		// Optimize and Repair the db
		exec("mysqlcheck --host=$db_host --user=$db_user --password=$db_pwd --auto-repair --optimize $db_name");

		// Dump database for backing up
		$db_archive_filename = 'backup-db-' . $db_name . '-' . $this->date . '.sql.gz';
		$db_archive = $this->archive_path . $db_archive_filename;

		// Dump
		exec("mysqldump --opt --host=$db_host --user=$db_user --password=$db_pwd $db_name | gzip -9c > $db_archive");
		$db_archive_size = $this->byteConvert(filesize($db_archive));

		// Add to S3 upload batch
		if ( $this->s3 ) {
			$this->s3->batch()->create_object($this->bucket, $db_archive_filename, array('fileUpload' => $db_archive ));
		}

		$this->archived_db_log[$db_archive] = $db_archive_size;
	}

	// Upload batch to S3
	public function send_to_s3() {
		if ( !$this->s3 ) {
			return FALSE;
		}

		$this->create_bucket($this->bucket);

		/** @var $file_upload_response CFArray */
		$file_upload_response = $this->s3->batch()->send();

		// Success?

		if ($file_upload_response->areOK()) {
			return TRUE;
			// send notifications
		}
		return FALSE;
	}

	public function send_notification_email( $to, $title ) {
		$subject = "[$title] Nightly backup successful";
		$body = "The $title backup just ran, successfully:\n\n";
		if ( !empty($this->archived_file_log) ) {
			foreach ( $this->archived_file_log as $filename => $filesize ) {
				$body .= "Asset archive: $filename ($filesize)\n";
			}
		}
		if ( !empty($this->archived_db_log) ) {
			foreach ( $this->archived_db_log as $filename => $filesize ) {
				$body .= "Database archive: $filename ($filesize)\n";
			}
		}
		$body .= "\n";
		$body .= "Your backups have been saved on Amazon S3. You should be able to see them here: https://console.aws.amazon.com/s3/home\n\n";
		$body .= "You can rest easy. :)\n\n";
		$body .= "~ Your Faithful Server";
		mail($to, $subject, $body);
	}

	public function set_expiration_rules( $expire_after ) {
		if ( !$this->s3 ) {
			return;
		}

		$response = $this->s3->create_object_expiration_config($this->bucket, array(
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
			while (!$this->s3->get_object_expiration_config($this->bucket)->isOK())
			{
				sleep(1);
			}
		}

	}

	private function create_bucket( $bucket ) {
		if ( !$this->s3 ) {
			return;
		}
		// Give the bucket a moment to get created if it doesn't exist yet
		$exists = $this->s3->if_bucket_exists($bucket);
		while (!$exists) {
			// Not yet? Sleep for 1 second, then check again
			sleep(1);
			$exists = $this->s3->if_bucket_exists($bucket);
		}
	}

	private function make_directory_archive( $dir, $is_subdir = FALSE ) {
		// Zip directory for backing up
		$asset_archive_filename = basename($dir);
		if ( $is_subdir ) {
			$asset_archive_filename = basename(dirname($dir)).'-'.$asset_archive_filename;
		}
		if ( $this->modified_since ) {
			$asset_archive_filename .= '-diff';
		}
		$asset_archive_filename = 'backup-files-' . $asset_archive_filename . '-' . $this->date . '.tar.gz';
		$asset_archive = $this->archive_path . $asset_archive_filename;

		// Zip
		$flags = '--create --gzip';
		if ( $this->modified_since ) {
			// find files modified since the given date
			$timestring = date('YmdHi', $this->modified_since);
			$find_command = "touch -t $timestring /tmp/s3_backup_timestamp; find $dir -type f -newer /tmp/s3_backup_timestamp";
			if ( !empty($this->tar_exclude_patterns) ) {
				foreach ( $this->tar_exclude_patterns as $pattern ) {
					$find_command .= " | grep --invert-match '$pattern'";
				}
			}
			$find_command .= " > /tmp/s3_backup_filelist";
			exec($find_command);
			$flags .= ' --files-from=/tmp/s3_backup_filelist';
			exec("tar $flags --file $asset_archive");
		} else {
			$flags .= $this->tar_exclude_string($this->tar_exclude_patterns);
			exec("tar $flags --file $asset_archive $dir");
		}
		$asset_archive_size = $this->byteConvert(filesize($asset_archive));

		// Add to S3 upload batch
		if ( $this->s3 ) {
			$this->s3->batch()->create_object($this->bucket, $asset_archive_filename, array('fileUpload' => $asset_archive ));
		}

		$this->archived_file_log[$asset_archive_filename] = $asset_archive_size;
	}

	// Helper functions


	private function tar_exclude_string( $patterns ) {
		$exclude = '';
		if ( !empty($patterns) ) {
			foreach ( $patterns as $p ) {
				$exclude .= ' --exclude='.escapeshellarg($p);
			}
		}
		return $exclude;
	}

	// This just helps make the file sizes in our email more human-friendly.
	private function byteConvert( $bytes ){
		$b = (int)$bytes;
		$s = array('B', 'kB', 'MB', 'GB', 'TB');
		if($b < 0){ return "0 ".$s[0]; }
		$con = 1024;
		$e = (int)(log($b,$con));
		return number_format($b/pow($con,$e),2,'.','.').' '.$s[$e];
	}
}