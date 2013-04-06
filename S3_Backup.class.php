<?php


class S3_Backup {
	private $archived_file_log = array();
	private $archived_db_log = array();

	/** @var AmazonS3 */
	private $s3 = NULL;
	private $bucket = '';
	private $archive_path = '';
	private $base_path = '';
	private $modified_since = 0;
	private $date = '';
	private $date_format = '';
	private $tar_exclude_patterns = array();
	private $completed_archive_files = array();

	private $verbose = false;

	public function __construct( $local_backup_dir, $base_path = '', $date_format = 'Y-m-d' ) {

		$this->archive_path = rtrim($local_backup_dir, '/') . '/';

		$this->base_path = rtrim($base_path, '/') . '/';

		$this->date_format = $date_format;

		$this->date = date($this->date_format);

	}

	public function init_s3( $bucket, $key, $secret ) {
		$this->output( 'Initializing Amazon...' );
		require_once './s3/sdk.class.php';
		$this->s3 = new AmazonS3( array(
			'key' => $key,
			'secret' => $secret,
		) );
		if ( !empty($bucket) ) {
			$this->set_bucket($bucket);
		}
	}

	public function set_bucket( $bucket ) {
		$this->bucket = $bucket;
	}

	public function set_verbosity( $verbosity = false ) {
		$this->verbose = ( $verbosity ) ? true : false;
	}

	public function output( $string ) {
		if ( $this->verbose ) {
			echo $string."\n";
		}
	}

	public function archive_files( $dir_paths, $patterns_to_exclude = array(), $modified_since = 0 ) {

		if ( !is_array($dir_paths) ) {
			$dir_paths = array($dir_paths);
		}
		if ( !is_array($patterns_to_exclude) ) {
			$this->tar_exclude_patterns = array($patterns_to_exclude);
		} else {
			$this->tar_exclude_patterns = $patterns_to_exclude;
		}
		$this->modified_since = (int)$modified_since;

		$this->output( "Backing up to: {$this->archive_path}" );

		if ( !empty($dir_paths) ) {
			if ( !is_dir($this->archive_path) ) {
				$this->output( "Creating {$this->archive_path}..." );
				exec("mkdir -p ".escapeshellarg($this->archive_path));
			}
			foreach ( $dir_paths as $path_to_archive ) {

				$path_to_archive = trim( $path_to_archive, '/');

				$full_path_to_archive = $this->base_path . $path_to_archive;

				if ( substr($full_path_to_archive, '-1') == '*' ) {
					$full_path_to_archive = substr($full_path_to_archive,0,-1);
					if ( !is_dir( $full_path_to_archive ) ) {
						$this->output( "Error: $full_path_to_archive is not a valid path." );
						continue;
					}
					$subdirs = glob( $full_path_to_archive . '*' , GLOB_ONLYDIR|GLOB_MARK );

					foreach ( $subdirs as $full_subpath_to_archive ) {
						$subpath_to_archive = substr($full_subpath_to_archive,strlen($this->base_path));
						if ( is_dir( $full_subpath_to_archive ) ) {
							$this->make_directory_archive($subpath_to_archive, TRUE);
						} else {
							$this->output( "Error: $full_subpath_to_archive is not a valid path." );
						}
					}
				} else {
					if ( is_dir( $full_path_to_archive ) ) {
						$this->make_directory_archive($path_to_archive);
					} else {
						$this->output( "Error: $full_path_to_archive is not a valid path." );
					}
				}
			}
		} else {
			$this->output( 'Error: no directories were specified.' );
		}
	}

	public function archive_database( $db_name, $db_user, $db_pwd, $db_host ) {
		// Optimize and Repair the db
		$this->output( "Optimizing and Repairing Database: $db_name" );
		exec("mysqlcheck --host=$db_host --user=$db_user --password=$db_pwd --auto-repair --optimize $db_name");

		// Dump database for backing up
		$db_archive_filename = 'backup-db-' . $db_name . '-' . $this->date . '.sql.gz';
		$db_archive = $this->archive_path . $db_archive_filename;

		// Dump
		$this->output( "Dumping Database: $db_name" );
		exec("mysqldump --opt --host=$db_host --user=$db_user --password=$db_pwd $db_name | gzip -9c > $db_archive");
		$db_archive_size = $this->byteConvert(filesize($db_archive));

		$this->archived_db_log[$db_archive_filename] = $db_archive_size;
		$this->completed_archive_files[$db_archive_filename] = $db_archive;
	}

	// Upload batch to S3
	public function send_to_s3() {
		$this->output( "Sending to S3..." );

		if ( !$this->s3 ) {
			$this->output( "Error: S3 could not be initialized." );
			return FALSE;
		}

		if ( empty($this->completed_archive_files) ) {
			$this->output( "Error: no files to upload");
		}

		$this->create_bucket($this->bucket);

		$upload_errors = FALSE;

		foreach ( $this->completed_archive_files as $filename => $absolute_path ) {
			$file_upload_response = $this->s3->create_mpu_object( $this->bucket, $filename, array('fileUpload' => $absolute_path, 'partSize' => 5242880) );
			if ( !$file_upload_response->isOK() ) {
				$this->output("Error: S3 upload failed for ".$filename);
				$upload_errors = TRUE;
			}
		}

		return !$upload_errors;
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

		if ( !is_dir( $this->base_path . $dir ) && !is_file( $this->base_path . $dir ) ) {
			$this->output( 'Error: {$this->base_path}$dir is not a valid path.' );
			return;
		}

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
		$flags = array();
		$flags[] = '--gzip';
		$flags[] = '--create';

		if ( !empty( $this->base_path ) ) {
			$flags[] = "-C {$this->base_path}";
		}

		if ( $this->modified_since ) {
			// find files modified since the given date
			$timestring = date('YmdHi', $this->modified_since);
			$find_command = "touch -t $timestring /tmp/s3_backup_timestamp; cd {$this->base_path}; find $dir -type f -newer /tmp/s3_backup_timestamp";
			if ( !empty($this->tar_exclude_patterns) ) {
				foreach ( $this->tar_exclude_patterns as $pattern ) {
					$find_command .= " | grep --invert-match '$pattern'";
				}
			}
			$find_command .= " > /tmp/s3_backup_filelist";

			exec($find_command);

			if ( file_exists( '/tmp/s3_backup_filelist' ) && filesize( '/tmp/s3_backup_filelist' ) > 0 ) {
				$this->output( "Incremenetal file backups found in: $dir" );
				$flags[] = '--files-from=/tmp/s3_backup_filelist';
				$tar_command = "tar " . join(' ',$flags) . " --file $asset_archive";
			}
		} else {
			$flags[] = $this->tar_exclude_string($this->tar_exclude_patterns);
			$tar_command = "tar " . join(' ',$flags) . " --file $asset_archive $dir";
		}

		if ( isset( $tar_command ) && !empty( $tar_command ) ) {

			$this->output( "Compressing: $dir..." );

			exec( $tar_command );

			$asset_archive_size = $this->byteConvert(filesize($asset_archive));

			$this->archived_file_log[$asset_archive_filename] = $asset_archive_size;
			$this->completed_archive_files[$asset_archive_filename] = $asset_archive;
		}
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