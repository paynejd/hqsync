<?php
/**
 * CommCare HQ sync script - to keep a mysql database in sync with HQ
 *
 * @author	Jonathan Payne <paynejd@gmail.com>
 * @updated	02-09-2011
 */

set_time_limit(0);
ini_set('display_errors',1);
error_reporting(E_ALL|E_STRICT);

require_once('HqSync.inc.php');


/* 
 * Initialize
 */
	$root_path          =  '/Users/paynejd/Sites/hqsync/data/';	// must be blank or end in forward slash

	$default_debug      =  false;
	$default_host       =  'localhost';
	$default_uid        =  'uid';
	$default_pwd        =  'pwd';
	$default_dbname     =  'hqsync';
	$default_domain     =  null;
	$default_form_name  =  null;

/*
 * Include any csv filenames that should not be imported here (e.g. '#.#.#export_tag.#.csv')
 */
	$arr_csv_ignore_list = array(
			'#|#export_tag|#.csv',
			'#|location_|#.csv'
		);


/**********************************************************************************************
**  Handle command line arguments:
**				--help		Help
**		-h		--host		Host (default=localhost)
**		-u		--user		user
**		-p		--password	pwd
**		-s		--schema	db schema name (default=hqsync)
**		-d		--domain	Domain (default=*)
**		-f		--form		Form name (default=*)
**		-v					Verbose
**********************************************************************************************/

	// Parse the args
	$args = HqSyncHelp::parseArgs($_SERVER['argv']);

	// If --help option, then display help and exit
	$display_help = HqSyncHelp::getArg($args, 'help', HQSYNC_ARGS_VALUE_NOT_ALLOWED, false, "sync.php: illegal option. expected --help\n");
	if ($display_help) {
		HqSyncHelp::displayHelp();
		die();
	}

	// set additional parameters (NOTE: Defaults are also set here)
	$debug     = HqSyncHelp::getArg($args, 'v', HQSYNC_ARGS_VALUE_NOT_ALLOWED, $default_debug, "sync.php: illegal option. expected -v\n");
	$db_host   = HqSyncHelp::getArg($args, array('h', 'host'), HQSYNC_ARGS_VALUE_REQUIRED, $default_host, "sync.php: illegal option. expected -h=[host] or --host=[host]\n");
	$db_uid    = HqSyncHelp::getArg($args, array('u', 'user'), HQSYNC_ARGS_VALUE_REQUIRED, $default_uid, "sync.php: illegal option. expected -u=[username] or --user=[username]\n");
	$db_pwd    = HqSyncHelp::getArg($args, array('p', 'password'), HQSYNC_ARGS_VALUE_REQUIRED, $default_pwd, "sync.php: illegal option. expected -p=[password] or --password=[password]\n");
	$db_name   = HqSyncHelp::getArg($args, array('s', 'schema'), HQSYNC_ARGS_VALUE_REQUIRED, $default_dbname, "sync.php: illegal option. expected -s=[schema] or --schema=[schema]\n");
	$domain    = HqSyncHelp::getArg($args, array('d', 'domain'), HQSYNC_ARGS_VALUE_REQUIRED, $default_domain, null, "sync.php: illegal option. expected -d=[domain] or --domain=[domain]\n");
	$form_name = HqSyncHelp::getArg($args, array('f', 'form'), HQSYNC_ARGS_VALUE_REQUIRED, $default_form_name, "sync.php: illegal option. expected -f=[form_name] or --form=[form_name]\n");


/**********************************************************************************************
**  Connect to DB and Load list of forms to sync
**********************************************************************************************/

/*
 * Display initial log output
 */
	echo gmdate('Y-m-d H:i:s') . ' GMT -- ' . implode(' ', $_SERVER['argv']) . "\n";
	if ($debug) echo "Displaying verbose output...\n";


/*
 * Connect to db
 */
	if (!($conn = mysql_connect($db_host, $db_uid, $db_pwd))) {
		die('could not connect to database');
	}
	mysql_query("SET sql_mode='ANSI_QUOTES'", $conn);
	mysql_select_db($db_name);


/*
 * Load the list of files to retrieve from HQ
 */
	$hqs_factory = new HqSyncFactory($conn, $db_name, $root_path);
	$hqs_factory->debug = $debug;
	$hqs_factory->setCsvIgnoreList($arr_csv_ignore_list);
	$arr_hqs = $hqs_factory->loadHqSyncList($domain, $form_name);
	if (!$arr_hqs) {
		echo "No forms to sync. Exiting.\n";
		exit();
	}


/**********************************************************************************************
**  Perform the sync
**********************************************************************************************/

/*
 * Iterate through files, perform the HQ export and the D-tree import
 */
	foreach ($arr_hqs as $hqsync_id => $hqs)
	{
		$result = null;
		$error = false;
		$error_type = null;
		$err_msg = '';

		if ($debug) echo "\nHqSync: " . $hqs->domain . '.' . $hqs->form_name . ' (' . $hqs->url . ")\n";

		/*
		 * TODO: Initiate database transaction
		 */
		mysql_query('start transaction', $conn);

		try 
		{
			/* 
			 * Make sure that the database exists for the current domain (fail if not; this should be 
			 * created by db admin)
			 */
			if (!$hqs_factory->doesDatabaseExist($hqs->dbname)) {
				throw new Exception('Database \'' . $hqs->dbname . '\' does not exist for domain \'' .
					$hqs->domain . '\' or permission denied for the current database user. Databases ' .
					'must be created before they can be used by HQSync.');
			}

			/*
			 * Fetch export files (zipped data file and header file; also unzips the csv files)
			 *
			 * NOTE: If files are already downloaded (ie. during local testing), it is only necessary
			 * to process the export files. Comment out the fetchExportFromServer statement below 
			 * and use this line instead:
			 *
			 *				$hqs_factory->processExportFiles($hqs);
			 */
			if (!$hqs_factory->fetchExportFromServer($hqs)) {
				throw new Exception('Error retrieving data from CommCareHQ server on ' . $hqs->domain . '.' . 
						$hqs->form_name, HQSYNC_ERROR_BAD_FETCH);
			}

			/*
			 * If purge_before_import is true, then truncate the database tables. 
			 */
			if ($hqs->purge_before_import) {
				$hqs_factory->truncateTables($hqs);
			}

			/*
			 * If there are files to import, then get on with it. If not, stop the import and 
			 * log the empty set.
			 */
			if (!$hqs->isEmptyExport()) 
			{					

				/*
				 * Get the diff on the current export. Determine if there are any column mismatch results.
				 */
				$arr_diff = $hqs_factory->getExportDiff($hqs);
				$is_any_column_mismatch = false;
				foreach ($arr_diff as $diff_result) {
					if ($diff_result == HQSYNC_DIFF_COLUMN_MISMATCH) {
						$is_any_column_mismatch = true;
						break;
					}
				}

				/*
				 * Reconcile the database to match the structure in the CSV files. If there are any 
				 * column mismatches (HQSYNC_DIFF_COLUMN_MISMATCH) in the diff, then purge the entire 
				 * dataset (both in the domain and corresponding records in hqsync_table). If all CSV 
				 * files are either new (HQSYNC_DIFF_MISSING_TABLE) or match the database 
				 * (HQSYNC_DIFF_NONE), then create missing tables.
				 */
				if ($is_any_column_mismatch) 
				{
					// Setting HqSync::ignore_input_token = true causes fetchExportFromServer to retreive
					// the entire dataset from CommCare HQ.
					$hqs->ignore_input_token = true;
					if (!$hqs_factory->fetchExportFromServer($hqs)) {
						trigger_error('Error retrieving data from CommCareHQ server for domain \'' . 
							$hqs->domain . '\' and form \'' . $hqs->form_name . '\'', E_USER_ERROR);
					}
		
					$hqs_factory->purgeDataset($hqs);
					$hqs_factory->createDatabaseStructure($hqs);
				}
				else {
					$hqs_factory->createMissingTables($hqs, $arr_diff);
				}

				/*
				 * Finally, perform the import into the reconciled database structure.
				 */
				$hqs_factory->importData($hqs);

				/*
				 * save logs - successful import
				 */
				$hqs_factory->saveImportLog($hqs, HQSYNC_STATUS_SUCCESSFUL_IMPORT);
			} 
			else 
			{
				/*
				 * save logs - empty export
				 */
				$hqs_factory->saveImportLog($hqs, HQSYNC_STATUS_EMPTY_EXPORT);	
			}

			/*
			 * Commit changes
			 */
			mysql_query('commit', $conn);

		}

		catch (Exception $e)
		{
			/*
			 * Rollback changes
			 */
			mysql_query('rollback', $conn);

			/*
			 * Log the error
			 */
			$hqs_factory->saveImportLog($hqs, HQSYNC_STATUS_ERROR, $e);
		}
	}


/**********************************************************************************************
**  Clean up
**********************************************************************************************/

/*
 * TODO: Clean up files from this import - zip up and rename directory by date
 */


?>