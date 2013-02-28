<?php

define('HQSYNC_CURL_SUCCESS', 1);

define('HQSYNC_MATCH_SUCCESS', 1);
define('HQSYNC_FILE_MISMATCH', -1);
define('HQSYNC_COLUMN_MISMATCH', -2);


/**
 * class HqExport
 */
class HqExport {
	var $hqsync_id;
	var $domain;
	var $xmlns;
	var $form_name;
	var $curl_uid;
	var $curl_pwd;
	var $input_token;

	var $curl_url;
	var $curl_result;
	var $output_token;
	var $header;
	var $filename_data;
	var $filename_header;
	var $curl_execution_time;
	var $http_code;

	var $arr_hqsync_table = array();
	var $arr_csv_file = array();

	function HqExport($root_path='', $domain='', $xmlns='', $form_name='', $curl_uid='', $curl_pwd='', $input_token='') {
		$this->root_path   = $root_path;
		$this->domain      = $domain;
		$this->xmlns       = $xmlns;
		$this->form_name   = $form_name;
		$this->curl_uid    = $curl_uid;
		$this->curl_pwd    = $curl_pwd;
		$this->input_token = $input_token;

		$this->generateFilename();
		$this->generateUrl();
	}
	function generateFilename() {
		$this->filename_data = $this->root_path . $this->domain . '/' . $this->domain . '.' . $this->form_name . '.data.zip';
		$this->filename_header = $this->root_path . $this->domain . '/' . $this->domain . '.' . $this->form_name . '.header.txt';
	}
	function generateUrl() {
		$this->curl_url = 'https://www.commcarehq.org/a/' . $this->domain . '/reports/export/' . 
			'?export_tag=%22' . $this->xmlns . '%22' .
			'&format=csv';
		if ($this->input_token) $this->curl_url .= '&previous_export=' . $this->input_token;
		return $this->curl_url;
	}

	/**
	 * True if this is a new import, false if a previous import has occurred for this form. If
	 * a previous import has occurred, HqObject::original_token will have a value.
	 */
	function isNewImport() {
		if ($this->original_token) return false;
		return true;
	}
	
	function addHqSyncTable($arr_hqsync_table) {
		$this->arr_hqsync_table[$arr_hqsync_table['hqsync_table_id']][] = $arr_hqsync_table;
	}
	function getHqSyncTableArray() {
		return $this->arr_hqsync_table;
	}
	
	function addCsvFile($full_pathname, $filename) {
		$this->arr_csv_file[] = array('pathname'=>$full_pathname, 'filename'=>$filename);
	}
	function getCsvFileArray() {
		return $this->arr_csv_file;
	}
	function getCsvFileCount() {
		return count($this->arr_csv_file);
	}
}



/**
 * class HqExportFactory
 * 
 * Scripts					[current_directory]
 * Data zip & header		[root_path/][domain]/
 * Unzipped CSV files		[root_path/][domain]/[form_name]/
 * Logs
 */
class HqExportFactory 
{
	var $root_path;
	var $conn;

	function HqExportFactory($conn, $root_path) {
		$this->conn = $conn;
		$this->root_path = $root_path;
	}

	/**
	 * Loads list of files to sync from the database using the connection set in this object.
	 */
	function loadSyncList() 
	{
		// Load hqsync and token from most recent import
		$sql =  'select s.*, ' . 
				'(select output_token from hqsync_log l where l.hqsync_id = s.hqsync_id order by sync_time desc limit 1) last_token ' . 
				'from hqsync s';
		if (!($rsc_hqsync = mysql_query($sql, $this->conn))) {
		  die ('could not query db: ' . mysql_error($this->conn));
		}
		$arr_hqe = array();
		$hqe = null;
		while ($s = mysql_fetch_assoc($rsc_hqsync)) {
			$hqe = new HqExport($this->root_path, $s['domain'], $s['xmlns'], $s['form_name'], $s['uid'], $s['pwd'], $s['last_token']);
			$arr_hqe[$s['hqsync_id']] = $hqe;
		}
		
		// Load hqsync_table and add to HqExport objects
		$sql = 'select * from hqsync_table';
		if (!($rsc_hqsync_table = mysql_query($sql, $this->conn))) {
		  die ('could not query db: ' . mysql_error($this->conn));
		}
		while ($row = mysql_fetch_assoc($rsc_hqsync_table)) {
			if (isset($arr_hqe[$row['hqsync_id']])) {
				$arr_hqe[$row['hqsync_id']]->addHqSyncTable($row);
			}
		}

		return $arr_hqe;
	}

	/**
	 * Setups and executes the curl statement to retrieve the export file from CommCareHQ.
	 * Saves the results of the data download and the header in separate files (filenames are 
	 * automatically set in the HqExport object). The output token is extracted from the 
	 * header and saved in the HqExport object.
	 *
	 * Returns true on success or false on failure.
	 */
	function performCurl($hqe)
	{ 
		// start the timer
		$time_start = microtime(true);

		// create domain directory if not exists
		$csv_target_directory = $this->root_path . $hqe->domain . '/' . $hqe->form_name;
		if (!is_dir($csv_target_directory)) {
			mkdir($csv_target_directory, 777, true);
		}

		// initialize curl and file handlers	
		$ch = curl_init($hqe->curl_url);
		$fp_data = fopen($hqe->filename_data, 'w');
		$fp_header = fopen($hqe->filename_header, 'w');

		// set curl options
		curl_setopt($ch, CURLOPT_FILE, $fp_data);
		curl_setopt($ch, CURLOPT_WRITEHEADER, $fp_header);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, 'paynejd@gmail.com:Gorilla1');
	
		// execute curl
		$hqe->curl_result = curl_exec($ch);
		$hqe->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		// TODO: Get additional curl info (filesize, etc.)

		// cleanup
		curl_close($ch);
		fclose($fp_data);
		fclose($fp_header);

		// get the token
		$matches = array();
		$hqe->header = file_get_contents($hqe->filename_header);
		if (preg_match('/X-CommCareHQ-Export-Token: (\w+)/', $hqe->header, $matches)) {
			$hqe->output_token = $matches[1];
		}

		// Stop and record the timer
		$time_stop = microtime(true);
		$hqe->curl_execution_time = $time_stop - $time_start;
		
		return $hqe->curl_result;
	}

	/**
	 * Unzips the data file created by HqExportFactory::performCurl().
	 *
	 * TODO: Assumes that there are no directories in the zip file, and will not find files in
	 * subdirectories if they exist. Need error checking and logging of results for each file
	 * that is unzipped.
	 */
	function unzipHqDataExport($hqe) 
	{
		$csv_target_directory = $this->root_path . $hqe->domain . '/' . $hqe->form_name;
		if ($zip = zip_open($hqe->filename_data)) 
		{
			while ($zip_entry = zip_read($zip)) 
			{
				$current_filename_in_zip = zip_entry_name($zip_entry);
				$csv_target_pathname = $csv_target_directory . '/' . $current_filename_in_zip;
				$fp_extract = fopen($csv_target_pathname, 'w');
				if (zip_entry_open($zip, $zip_entry, 'r')) {
					$buf = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
					fwrite($fp_extract, "$buf");
					zip_entry_close($zip_entry);
					fclose($fp_extract);
					$hqe->addCsvFile($csv_target_pathname, $current_filename_in_zip);
				}
			}
			zip_close($zip);
		}
		return $hqe->getCsvFileCount();
	}

	/**
	 * Return whether the new downloaded files and column headers match previously imported data.
	 */
	function isStructuralMatch($hqe)
	{
		// TODO: if form versions are the same, return true (no need to check)
			// NOTE: See http://www.rooftopsolutions.nl/blog/222 for string as stream example (to use fgetcsv)
		// TODO: Store the form version of the most recently retrieved form
			// NOTE: See http://stackoverflow.com/questions/1510141/read-last-line-from-file for php tail example
		// TODO: if form versions are not the same...
		
		// Assume form versions are different
		
		// check if file list is the same; if not, return HQSYNC_FILE_MISMATCH
		$arr_csv_file = $hqe->getCsvFileArray();
		foreach ($hqe->getHqSyncTableArray() as $arr_hqsync_table) {
			$search_result = array_search($arr_hqsync_table['filename'], $arr_csv_file);
			if ($search_result !== false) {
				unset($arr_csv_file[$search_result]);
			} else {
				return HQSYNC_FILE_MISMATCH;
			}
		}
		if (count($arr_csv_file)) return HQSYNC_FILE_MISMATCH;

		// iterate through csv files and compare column names
		foreach ($hqe->getHqSyncTableArray() as $arr_hqsync_table) 
		{
			// open file and read in column names
			$pathname = $this->root_path . $hqe->domain . '/' . $hqe->form_name . '/' . $arr_hqsync_table['filename'];
			$fp_csv = fopen($pathname, 'r');
			$arr_csv_field = fgetcsv($fp_csv);
			fclose($fp_csv);

			// get fields from database
			$sql = "select column_name from information_schema.columns where table_name='" . $arr_hqsync_table['tablename'] . "'";
			$rsc = mysql_query($sql, $this->conn);
			$arr_db_field = array();
			while ($row = mysql_fetch_row($rsc)) {
				$arr_db_field[] = $row[0];
			}

			// Compare the 2 lists (must be exact, including the order); return HQSYNC_MATCH_SUCCESS if not identical
			// array_diff_assoc returns an empty array if array1 and array2 are identical
			if (array_diff_assoc($arr_csv_field, $arr_db_field)) {
				return HQSYNC_COLUMN_MISMATCH;
			}
		}

		return HQSYNC_MATCH_SUCCESS;
	}

	/**
	 * Return whether the specified database exists in this connection (for the hqsync user)
	 */
	function doesDatabaseExist($db) 
	{
		$sql_db_exists = "select count(*) from information_schema.schemata where schema_name='" . $db . "'";
		if (!($rsc = mysql_query($sql_db_exists, $this->conn))) {
			die ('could not query db: ' . mysql_error($this->conn));
		}
		return (bool)mysql_result($rsc, 0);
	}

	/**
	 * Returns whether the specified table exists.
	 */
	function doesDatabaseTableExist($db, $table) 
	{
		$sql_table_exists = "select count(*) from information_schema.tables where table_schema = '" . $db . 
			"' and table_name = '" . $table . "'";
		if (!($rsc = mysql_query($sql_table_exists, $this->conn))) {
			die ('could not query db: ' . mysql_error($this->conn));
		}
		return (bool)mysql_result($rsc, 0);
	}

	/**
	 * Create database structure
	 */
	function createDatabaseStructure($hqe)
	{
		// make sure database exists (don't create this automatically)
		if (!$this->doesDatabaseExist($hqe->domain)) {
			echo 'Database "' . $hqe->domain . '" does not exist. Cannot create database structure for ' .
				$hqe->domain . '.' . $hqe->form_name;
			// TODO: Log
			return false;
		}
		mysql_select_db($hqe->domain);

		// throw an error if table already exists for any of the files - should be purged first
		for ($i = 1; $i <= count($hqe->getCsvFileCount()); $i++) {
			$table_name = $hqe->form_name;
			if ($i > 1) $table_name .= '_' . $i;
			if ($this->doesDatabaseTableExist($hqe->domain, $table_name)) {
				echo 'Table ' . $hqe->domain . '.' . $hqe->form_name . ' already exists. ' .
					'Database must be purged before creating database structure.';
				return false;
			}
		}

		// iterate through csv files, load column names, and create db table
		$i = 1;
		foreach ($hqe->getCsvFileArray() as $arr_csv_file) 
		{
			// set table name
			$table_name = $hqe->form_name;
			if ($i > 1) $table_name .= '_' . $i;

			// open csv file and read in the first row (the column names)
			$fp_csv = fopen($arr_csv_file['pathname'], 'r');
			$arr_field = fgetcsv($fp_csv);
			var_dump($arr_field);
			fclose($fp_csv);

			// TODO: set column types for each header (default to VARCHAR(250))

			// write create table sql
			$sql_create_table = 'creat table `' . mysql_escape_string($hqe->domain) . '`.`' . 
					mysql_escape_string($table_name) . '` (';
			foreach ($arr_field as $field) {
				$sql_create_table .= "'" . mysql_escape_string($field) . "' VARCHAR(250), ";
			}
			$sql_create_table = ')';

			// TODO: Create the table
			if (!mysql_query($sql_create_table, $this->conn)) {
				echo 'Could not create table `' . $hqe->domain . '`.`' . $hqe->table_name . '`. ' .
					'Complete sql: ' . $sql_create_table;
				return false;
			}

			// TODO: add row to ddc.hqsync_table
			$sql_hqsync_table = 'insert into ddc.hqsync_table values (null, ' . 
					$hqe->hqsync_id . ', ' .
					"'" . $arr_csv_file['filename'] . "', "
					"'" . $table_name . ')';
			if (!mysql_query($sql_hqsync_table, $this->conn)) {
				echo 'Could not access database to insert new hqsync_table record. Complete sql: ' . $sql_hqsync_table;
				return false;
			}

			$i++;
		}

		return true;
	}

	function importData($hqe) {
		// todo: import the data for the specified hqe
		// iterate through unzipped files and import
		foreach ($arr_csv_file as $csv_pathname) 
		{
			// compare column structure of file with the file in the database

			// import
			$sql = 'load data infile...';
		}
	}
	
	function saveLogs($hqe) {
		// todo: save log data contained in the hqe
	}
}


?>