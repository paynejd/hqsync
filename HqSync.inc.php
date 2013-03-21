<?php

/**
 * Cause all errors to throw exceptions
 */
function exception_error_handler($errno, $errstr, $errfile, $errline ) {
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("exception_error_handler");


/**
 * CURL constants for HqSyncFactory::fetchExportFromServer()
 */
define('HQSYNC_CURL_SUCCESS', 1);


/**
 * Constants for the outcome of a sync operation
 */
define('HQSYNC_STATUS_SUCCESSFUL_IMPORT', 100);
define('HQSYNC_STATUS_EMPTY_EXPORT', 200);
define('HQSYNC_STATUS_ERROR', -100);


/**
 * Return values for HqSyncFactory::getExportDiff()
 */
define('HQSYNC_DIFF_NONE', 0);				// no difference between csv columns and db columns
define('HQSYNC_DIFF_COLUMN_MISMATCH', 1);	// column names in csv do not match column names in db
define('HQSYNC_DIFF_TABLE_MISSING', 2);		// db table does not exist


/**
 * Command line argument states.
 */
define('HQSYNC_ARGS_VALUE_REQUIRED', 1);
define('HQSYNC_ARGS_VALUE_OPTIONAL', 2);
define('HQSYNC_ARGS_VALUE_NOT_ALLOWED', 3);


/**
 * Zip error constant. For some reason, these have to be defined manually. 
 */
define('ZIP_ER_NOZIP', 19);



/**
 * class HqSyncTable
 *
 * @author	Jonathan Payne <paynejd@gmail.com>
 * @updated	31-Aug-2011
 * @version	1.0
 */
class HqSyncTable 
{
	public $hqsync_table_id;
	public $hqsync_id;
	public $filename;
	public $tablename;
	public $include_in_import;

	private $arr_field = array();


	/**
	 * Constructor
	 */
	public function __construct($hqsync_table_id=null, $hqsync_id=null,
			$filename='', $tablename='', $include_in_import=null)
	{
		$this->hqsync_table_id   = $hqsync_table_id;
		$this->hqsync_id         = $hqsync_id;
		$this->filename          = $filename;
		$this->tablename         = $tablename;
		$this->include_in_import = $include_in_import;
	}

	/**
	 * Set the array of fields names stored in this CSV file
	 *
	 * @param	array		Array of field names
	 * @return	none
	 */
	public function setFields(array $arr_field) {
		$this->arr_field = $arr_field;
	}

	/**
	 * Get array of field names stored in this CSV file
	 * 
	 * @return	array		Array of field names
	 */
	public function getFields() {
		return $this->arr_field;
	}
}


/**
 * class HqSyncCsvFile
 *
 * @author	Jonathan Payne <paynejd@gmail.com>
 * @updated	31-Aug-2011
 * @version	1.0
 */
class HqSyncCsvFile 
{
	/** 
	 * Full pathname of the csv file
	 * @type string
	 */
	public $pathname;

	/**
	 * Filename of the csv file
	 * @type string
	 */
	public $filename;

	/**
	 * Array of field names extracted from the first line of the csv file.
	 * @type array
	 */
	private $arr_field = array();


	/**
	 * Constructor
	 * 
	 * @param  string  $pathname
	 * @param  string  $filename
	 */
	public function __construct($pathname, $filename) {
		$this->pathname = $pathname;
		$this->filename = $filename;
	}

	/**
	 * Set the array of fields names stored in this CSV file
	 *
	 * @param	array		Array of field names
	 * @return	none
	 */
	public function setFields(array $arr_field) {
		$this->arr_field = $arr_field;
	}

	/**
	 * Get array of field names stored in this CSV file
	 * 
	 * @return	array		Array of field names
	 */
	public function getFields() {
		return $this->arr_field;
	}
}


/**
 * class HqSync
 *
 * @author	Jonathan Payne <paynejd@gmail.com>
 * @updated	31-Aug-2011
 * @version	1.0
 */
class HqSync 
{
	/**
	 * Variables that correspond with database fields in HqSync
	 */
	public $hqsync_id;
	public $domain;
	public $dbname;
	public $url;
	public $form_name;
	public $curl_uid;
	public $curl_pwd;
	public $use_token;

	/**
	 * If true, all database tables related to the HQSync object are truncated before doing the import.
	 * This is useful for pulling report data rather than sequential submissions.
	 */
	public $purge_before_import;

	/**
	 * The token of the most import. Retrieved from the hqsync_log table. Not loaded and 
	 * not applicable if HqSync::use_token set to false. If HqSync::ignore_input_token is
	 * true, then the input token is still loaded. See description for 
	 * HqSync::ignore_input_token for details.
	 * @type  string
	 */
	public $input_token;

	/** 
	 * Whether the input token should be used during a fetch operation. Turning this off
	 * causes HqSyncFactory to fetch the entire dataset, instead of just new submissions.
	 * This is used when the underlying data model has changed and the table is dropped and
	 * rebuilt.
	 * @type  bool
	 */
	public $ignore_input_token = false;


	public $curl_url;
	public $curl_result;
	public $output_token;
	public $header;
	public $filename_data;
	public $filename_header;
	public $curl_info;

	/**
	 * Indicates whether the zip file retrieved from the server is empty. Null if no file retrieved.
	 * False if retrieved file contains records. True if retrieved file is empty.
	 */
	private $is_empty_export = null;

	private $arr_hqsync_table = array();
	private $arr_csv_file = array();

	/**
	 * Array of textstrings that describe events that have taken place on this object.
	 * @type  array
	 */
	private $arr_log = array();


	/**
	 * Constructor
	 */
	public function __construct($hqsync_id, $root_path, $domain, $dbname, 
			$url, $form_name, $curl_uid, $curl_pwd, $use_token, $purge_before_import, 
			$input_token='') 
	{
		$this->hqsync_id    =  $hqsync_id;
		$this->root_path    =  $root_path;
		$this->domain       =  $domain;
		$this->dbname       =  $dbname;
		$this->url          =  $url;
		$this->form_name    =  $form_name;
		$this->curl_uid     =  $curl_uid;
		$this->curl_pwd     =  $curl_pwd;
		$this->use_token    =  $use_token;
		$this->input_token  =  $input_token;
		$this->purge_before_import = $purge_before_import;

		$this->generateFilename();
	}

	/**
	 * Generates the filenames for this object based on root_path, domain, and form_name. This is
	 * called automatically in the constructor, but should be called again if any of the above
	 * variables are updated.
	 *
	 * @return	none
	 */
	public function generateFilename() 
	{
		$this->filename_data = $this->root_path . $this->domain . '/' . $this->domain . '.' . 
				$this->form_name . '.data.zip';
		$this->filename_header = $this->root_path . $this->domain . '/' . $this->domain . '.' . 
				$this->form_name . '.header.txt';
	}

	/**
	 * Returns the client url to use to fetch data from CommCare HQ. If an input_token is set and
	 * ignore_input_token is false (default), then the token will be used in the request
	 * parameters to limit the data retrieved to submissions received after the token.
	 *
	 * @return	string		Client url used to fetch data from CommCare HQ
	 */
	public function getCurlUrl() {
		$curl_url = $this->url;
		if ($this->use_token && $this->input_token && !$this->ignore_input_token) {
			$curl_url .= '&previous_export=' . $this->input_token;
		}
		return $curl_url;
	}

	/**
	 * True if this is a new import, false if a previous import has occurred for this form. If
	 * a previous import has occurred, HqObject::input_token will have a value.
	 *
	 * @return	bool
	 */
	public function isNewImport() {
		if ($this->input_token) return false;
		return true;
	}


	/**
	 * Adds the HqSyncTable to this HqSync object
	 *
	 * @param	HqSyncTable		$hqs_table
	 * @return	none
	 */
	public function addHqSyncTable(HqSyncTable $hqs_table) {
		$this->arr_hqsync_table[$hqs_table->hqsync_table_id] = $hqs_table;
	}

	/**
	 * Returns an array of HqSyncTable objects associated with this HqSync object.
	 *
	 * @param	bool	$include_excluded_files		(Optional) Whether to include in the return array 
	 * 												files that have been explicitly excluded at the
	 *												database level (hqsync_table.include_in_import == 0).
	 * @return	array
	 */
	public function getHqSyncTableArray($include_excluded_files = false) 
	{
		/*
		 * If excluded files should also be returned, just return the entire array.
		 */
		if ($include_excluded_files) {
			return $this->arr_hqsync_table;
		}
		
		/* 
		 * Else, create a new array without the excluded files first and return.
		 */
		else {		
			$arr_return = array();
			foreach ($this->arr_hqsync_table as $hqs_table) {
				if ($hqs_table->include_in_import == 1) {
					$arr_return[] = $hqs_table;
				}
			}
			return $arr_return;
		}
	}

	/**
	 * Clears the HqSyncTable objects from this HqSync object.
	 *
	 * @return	none
	 */
	public function clearHqSyncTables() {
		$this->arr_hqsync_table = array();
	}

	/**
	 * Returns the HqSyncTable object that matches the specified filename or null if it does
	 * not exist. Set include_excluded_files to true to match tables that have been explicitly
	 * excluded at the database level (hqsync_table.include_in_import == 0).
	 *
	 * @param	string	$filename					The filename to match
	 * @param	bool	$include_excluded_files		(Optional) Whether to include in the return array 
	 * 												files that have been explicitly excluded at the
	 *												database level (hqsync_table.include_in_import == 0).
	 * @return	HqSyncTable
	 */
	public function getHqSyncTableByFilename($filename, $include_excluded_files = false) 
	{
		foreach ($this->arr_hqsync_table as $hqs_table) {
			if ($hqs_table->filename == $filename &&
				($hqs_table->include_in_import || $include_excluded_files)) 
			{
				return $hqs_table;
			}
		}
		return null;
	}

	/**
	 * Returns the HqSyncTable object that matches the specified tablename or null if it does
	 * not exist. Set include_excluded_files to true to match tables that have been explicitly
	 * excluded at the database level (hqsync_table.include_in_import == 0).
	 *
	 * @param	string	$tablename					The table name to match
	 * @param	bool	$include_excluded_files		(Optional) Whether to include in the return array 
	 * 												files that have been explicitly excluded at the
	 *												database level (hqsync_table.include_in_import == 0).
	 * @return	HqSyncTable
	 */
	public function getHqSyncTableByTablename($tablename, $include_excluded_files = false) 
	{
		foreach ($this->arr_hqsync_table as $hqs_table) {
			if ($hqs_table->tablename == $tablename &&
				($hqs_table->include_in_import || $include_excluded_files)) 
			{
				return $hqs_table;
			}
		}
		return null;
	}


	/**
	 * Add the HqSyncCsvFile to this object.
	 *
	 * @param	HqSyncCsvFile	$hqs_csv	HqSyncCsvFile to add
	 * @return	none
	 */
	public function addCsvFile(HqSyncCsvFile $hqs_csv)  {
		$this->arr_csv_file[$hqs_csv->filename] = $hqs_csv;
	}

	/**
	 * Get array of HqSyncCsvFile objects
	 *
	 * @return	array
	 */
	public function getCsvFileArray() {
		return $this->arr_csv_file;
	}

	/**
	 * Get the count of HqSyncCsvFile objects
	 *
	 * @return	int
	 */
	public function getCsvFileCount() {
		return count($this->arr_csv_file);
	}

	/**
	 * Get the HqSyncCsvFile object that matches the specified filename or null if it does not exist.
	 *
	 * @param	string		$filename
	 * @return	HqSyncCsvFile
	 */
	public function getCsvFileByFilename($filename) {
		foreach ($this->arr_csv_file as $hqs_csv) {
			if ($hqs_csv->filename == $filename) return $hqs_csv;
		}
		return null;
	}

	/**
	 * Returns true if zip file retrieved from the server contains no records.
	 * 
	 * @return  bool
	 */
	public function isEmptyExport() {
		return (bool)$this->is_empty_export;
	}

	/**
	 * Sets the is_empty_export flag to the value of is_empty (default=true).
	 *
	 * @param   bool    $is_empty
	 */
	public function setEmptyExport($is_empty = true) {
		$this->is_empty_export = $is_empty;
	}

	/**
	 * Add text describing an event that took place on this HqSync object to the log.
	 */
	public function log($event_desc) {
		$this->arr_log[] = $event_desc;
	}

	/**
	 * Returns array of strings of logged events that took place on this HqSync object.
	 * @return  array
	 */
	public function getLogs() {
		return $this->arr_log;
	}
}



/**
 * class HqSyncFactory
 * 
 * Scripts					[current_directory]
 * Data zip & header		[root_path/][domain]/
 * Unzipped CSV files		[root_path/][domain]/[form_name]/
 * Logs						???
 *
 * @author	Jonathan Payne <paynejd@gmail.com>
 * @updated	31-Aug-2011
 * @version	1.0
 */
class HqSyncFactory
{
	/**
	 * Name of the primary hqsync database (location of hqsync tables).
	 * @type	string
	 */
	private $hqsync_dbname;

	/**
	 * Pathname to prepend to all other filenames and pathnames used by methods in this class.
	 * Must be empty or end in a forward slash.
	 * @type	string
	 */
	private $root_path;

	/**
	 * Database connection resource
	 * @type	resource
	 */
	private $conn = null;

	/**
	 * Whether to display debug info
	 * @type	bool
	 */
	public $debug = false;

	/**
	 * Array of csv filenames to ignore
	 * @type	array
	 */
	private $arr_csv_ignore_list = array();


	/**
	 * Constructor
	 *
	 * @param	resource	$conn				Database connection object
	 * @param	string		$hqsync_dbname		Name of the database that contains the HQSync database tables
	 * @param	string		$root_path			Pathname that is prepended to all other filenames and pathnames 
	 * 											used by methods in this class. Must be empty or end in a forward slash.
	 */
	public function __construct($conn, $hqsync_dbname, $root_path)
	{
		$this->conn = $conn;
		$this->hqsync_dbname = $hqsync_dbname;
		$this->root_path = $root_path;
	}


	/**
	 * Set the connection object
	 *
	 * @pararm	resource	$conn
	 * @return 	none
	 */
	public function setConnection($conn) {
		$this->conn = $conn;
	}


	/**
	 * Get the connection object
	 *
	 * @return 	resource		Connection resource
	 */
	public function getConnection() {
		return $this->conn;
	}

	
	/**
	 * Clears the connection object
	 *
	 * @return 	none
	 */
	public function clearConnection() {
		$this->conn = null;
	}


	/**
	 * Set the list of CSV filenames to ignore during import.
	 *
	 * @param	array	$arr_csv_ignore_list
	 * @return	none
	 */
	public function setCsvIgnoreList(array $arr_csv_ignore_list) {
		$this->arr_csv_ignore_list = $arr_csv_ignore_list;
	}


	/**
	 * Returns list of files to sync from the database using the connection set in this object.
	 * 
	 * @return	array 
	 */
	public function loadHqSyncList($domain = null, $form_name = null)
	{
		if ($this->debug) echo "\nLoading sync list...\n";

 
		/*
		 * Load hqsync and token from most recent import
		 */
		$sql =  'select s.*, ' . 
				'(' .
					'select output_token ' . 
					'from "' . mysql_escape_string($this->hqsync_dbname) . '".hqsync_log l ' . 
					'where l.hqsync_id = s.hqsync_id ' . 
					'and ( sync_status = ' . HQSYNC_STATUS_SUCCESSFUL_IMPORT . 
						' or sync_status = ' . HQSYNC_STATUS_EMPTY_EXPORT . ' ) ' . 
					'order by sync_time desc limit 1' .
				') last_token ' . 
				'from "' . mysql_escape_string($this->hqsync_dbname) . '".hqsync s ' . 
				'where s.active = 1';
		if ($domain) $sql .= " and domain='" . mysql_escape_string($domain) . "'";
		if ($form_name) $sql .= " and form_name='" . mysql_escape_string($form_name) . "'";
		if (!($rsc_hqsync = $this->execute_query($sql))) {
			trigger_error('Could not query db: ' . mysql_error($this->conn), E_USER_ERROR);
		}
		$arr_hqs = array();
		$hqs = null;
		$str_hqsync_id = '';
		while ($s = mysql_fetch_assoc($rsc_hqsync)) {
			$hqs = new HqSync($s['hqsync_id'], $this->root_path, $s['domain'], $s['dbname'], 
					$s['url'], $s['form_name'], $s['uid'], $s['pwd'], $s['use_token'], 
					$s['purge_before_import'], $s['last_token']);
			$arr_hqs[$s['hqsync_id']] = $hqs;
			if ($str_hqsync_id) $str_hqsync_id .= ',';
			$str_hqsync_id .= $s['hqsync_id'];
		}


		/*
		 * Load hqsync_table, create HqSyncTable objects, and add to HqSync objects
		 */
		if ($arr_hqs) {
			$sql = 'select * ' . 
					'from "' . mysql_escape_string($this->hqsync_dbname) . '".hqsync_table ' . 
					'where hqsync_id in (' . $str_hqsync_id . ')';
			if (!($rsc_hqsync_table = $this->execute_query($sql))) {
				trigger_error('Could not query db: ' . mysql_error($this->conn), E_USER_ERROR);
			}
			while ($row = mysql_fetch_assoc($rsc_hqsync_table)) 
			{
				if (!isset($arr_hqs[$row['hqsync_id']])) continue;
				$hqs = $arr_hqs[$row['hqsync_id']];
	
				// Create the object			
				$hqs_table = new HqSyncTable($row['hqsync_table_id'], $row['hqsync_id'],
						$row['filename'], $row['tablename'], $row['include_in_import']);
				
				// Load field names for this table from the db and store in the HqSyncTable object
				// NOTE: It is possible that this table does not exist, so the field list is empty. This
				//		 should be caught by HqSyncFactory::getExportDiff
				$sql_fields = 'select column_name from information_schema.columns ' . 
						'where table_schema=\'' . mysql_escape_string($hqs->dbname) . '\' ' .
						'and table_name=\'' . mysql_escape_string($hqs_table->tablename) . '\'';
				$rsc_fields = $this->execute_query($sql_fields);
				$arr_db_field = array();
				while ($row = mysql_fetch_row($rsc_fields)) {
					$arr_db_field[] = $row[0];
				}
				$hqs_table->setFields($arr_db_field);
	
				// Add HqSyncTable to the HqSync object
				$hqs->addHqSyncTable($hqs_table);
			}
		}

		return $arr_hqs;
	}


	/**
	 * Setups and executes the curl statement to retrieve the export file from CommCareHQ.
	 * Saves the results of the data download and the header in separate files (filenames are 
	 * automatically set in the HqSync object). The output token is extracted from the 
	 * header and saved in the HqSync object.
	 *
	 * @param	HqSync	$hqs	
	 * @param	bool	$decompress			If true (default), the data file is unzipped
	 * @param	bool	$use_input_token	If true (default), the input token is used to limit
	 * 										the data that is exported from the server. If false, 
	 * 										all data is exported.
	 * @return 	bool						TRUE on success; FALSE if an error occurs
	 */
	public function fetchExportFromServer(HqSync $hqs, $process_export_files = true)
	{
		if ($this->debug) echo "\nFetching export from server...\n";

		/* 
		 * Create domain directory in local file system if not exists
		 * TODO: Should CSV target directory be set here or in the HqSync object?
		 */
		$csv_target_directory = $this->root_path . $hqs->domain . '/' . $hqs->form_name;
		if (!is_dir($csv_target_directory)) {
			if (!mkdir($csv_target_directory, 0777, true)) {
				trigger_error('Unable to create directory: \'' . $csv_target_directory . '\'', E_USER_ERROR);
			}
		}

		/*
		 * Initialize file handlers
		 */
		$fp_data = fopen($hqs->filename_data, 'w');
		$fp_header = fopen($hqs->filename_header, 'w');
		if (!$fp_data || !$fp_header) {
			trigger_error('Unable to create header and data files. Exiting...', E_USER_ERROR);
		}

		/* 
		 * Initialize curl
		 */
		$hqs->curl_url = $hqs->getCurlUrl();
		$ch = curl_init($hqs->curl_url);
		curl_setopt($ch, CURLOPT_FILE, $fp_data);
		curl_setopt($ch, CURLOPT_WRITEHEADER, $fp_header);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, $hqs->curl_uid . ':' . $hqs->curl_pwd);

		if ($this->debug) { 
			echo "\nInput token: " . $hqs->input_token . "\n";
			echo "Curl url: " . $hqs->curl_url . "\n";
		}

		/*
		 * Execute curl and get additional info.
		 */
		$hqs->curl_result = curl_exec($ch);
		if (!$hqs->curl_result) {
			trigger_error('Unable to query server', E_USER_ERROR);
		}
		$hqs->curl_info = curl_getinfo($ch);

		/*
		 * Cleanup
		 */
		curl_close($ch);
		fclose($fp_data);
		fclose($fp_header);

		/*
		 * Extract token from the header and decompress the data zip file.
		 */
		if ($process_export_files) {
			$this->processExportFiles($hqs);
		}

		return $hqs->curl_result;
	}

	/**
	 * Processes the export files after they have been downloaded. This includes extracting the
	 * token from the header text file and unzipping the csv files.
	 * 
	 * @param	HqSync	$hqs
	 * @return	none
	 */
	public function processExportFiles(HqSync $hqs)
	{
		/*
		 * Extract the token from the header file. The token represents the last record that
		 * was retrieved from the server so that we only need to fetch new data in the next export.
		 */
		if ($hqs->use_token) {
			$hqs->output_token = $this->extractOutputTokenFromHeader($hqs);
		}

		/*
		 * Unzip data file
		 */
		$this->unzipHqDataExport($hqs);
	}

	/**
	 * Extracts the token from the header output of the curl request.
	 * 
	 * @param	HqSync	$hqs
	 * @return	none
	 */
	public function extractOutputTokenFromHeader(HqSync $hqs)
	{		 
		$matches = array();
		if (!($hqs->header = file_get_contents($hqs->filename_header))) {
			trigger_error('Could not read from file \'' . $hqs->filename_header . '\'', E_USER_ERROR);
		}
		if (preg_match('/X-CommCareHQ-Export-Token: (\w+)/', $hqs->header, $matches)) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Unzips the data file created by HqSyncFactory::performCurl() and adds the corresponding 
	 * HqSyncCsvFile objects to the HqSync object.
	 *
	 * TODO: Assumes that there are no directories in the zip file, and will not find files in
	 * subdirectories if they exist. Need error checking and logging of results for each file
	 * that is unzipped.
	 *
	 * @param	HqSync	$hqs
	 * @param	none
	 */
	public function unzipHqDataExport(HqSync &$hqs) 
	{
		if ($this->debug) echo "\nDecompressing export files...\n";

		$csv_target_directory = $this->root_path . $hqs->domain . '/' . $hqs->form_name;
		$zip = zip_open($hqs->filename_data);
		if (is_resource($zip)) 
		{
			while ($zip_entry = zip_read($zip)) 
			{
				/*
				 * Get current filename in zip
				 */
				$current_filename_in_zip = zip_entry_name($zip_entry);

				/* 
				 * Skip file if in the ignore list
				 */
				if (array_search($current_filename_in_zip, $this->arr_csv_ignore_list) !== false) {
					if ($this->debug) {
						echo "Skipping '$current_filename_in_zip' for " . $hqs->domain . 
							"." . $hqs->form_name . "\n";
					}
					continue;
				}

				/*
				 * Unzip the file
				 */
				$csv_target_pathname = $csv_target_directory . '/' . $current_filename_in_zip;
				$fp_extract = fopen($csv_target_pathname, 'w');
				if ($is_file_unzip_success = zip_entry_open($zip, $zip_entry, 'r')) {
					// Do the extraction
					$buf = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
					$is_file_unzip_success = (bool)fwrite($fp_extract, "$buf");
					zip_entry_close($zip_entry);
					fclose($fp_extract);
				}
				if (!$is_file_unzip_success) {
					trigger_error('Unable to unzip file \'' . $current_filename_in_zip . '\' from zip file \'' .
							$hqs->filename_data . '\'', E_USER_ERROR);
				}

				/* 
				 * Load column names from the csv file
				 */
				$fp_csv = fopen($csv_target_pathname, 'r');
				if (!$fp_csv) {
					trigger_error('Could not open CSV file \'' . $pathname . '\'', E_USER_ERROR);
				}
				$arr_csv_field = fgetcsv($fp_csv);
				fclose($fp_csv);

				/*
				 * Create the HqSyncCsvFile object and add to HqSync
				 */
				$hqs_csv = new HqSyncCsvFile($csv_target_pathname, $current_filename_in_zip);
				$hqs_csv->setFields($arr_csv_field);
				$hqs->addCsvFile($hqs_csv);
			}
			zip_close($zip);
			return $hqs->getCsvFileCount();
		}
	
		/*
		 * Could not open zip file, so handle the error here. ZIP_ER_NOZIP usually means
		 * empty file. Anything else, then throw an error.
		 */
		else {
			if ($zip == ZIP_ER_NOZIP) {
				$hqs->setEmptyExport();
				$hqs->log('Empty export');
				if ($this->debug) echo "Empty zip file. No results returned from HQ.\n";
			} else {
				trigger_error('Zip error #' . $zip . ' occurred. Look at the documentation for details.', E_USER_ERROR);
			}
		}

		return 0;
	}


	/**
	 * Returns whether the columns in the csv file match the columns in the corresponding 
	 * database table. Checks both field names and order.
	 *
	 * @param	HqSyncCsvFile	$hqs_csv
	 * @param	HqSyncTable		$hqs_table
	 * @param	bool						TRUE if columns in csv file match columns in database 
	 *										table; FALSE if they do not match.
	 */
	public function doColumnsMatch(HqSyncCsvFile &$hqs_csv, HqSyncTable &$hqs_table)
	{
		/*
		 * Compare the 2 lists (must be exact, including the order); return FALSE if not identical.
		 * NOTE: array_diff_assoc returns an empty array if array1 and array2 are identical
		 */
		if (array_diff_assoc($hqs_csv->getFields(), $hqs_table->getFields())) {
			return false;
		}

		return true;
	}


	/** 
	 * Returns an array of differences between each CSV file returned
	 * in the HQ export and the corresponding existing database structure for the given HqSync
	 * object. Possible return values for each csv file are:
	 * 		HQSYNC_DIFF_NONE - no difference between csv structure and the existing db structure
	 * 		HQSYNC_DIFF_COLUMN_MISMATCH - columns in the csv file do not match columns in the
	 *			corresponding database table
	 * 		HQSYNC_DIFF_TABLE_MISSING - there is no corresponding database table for this csv file
	 * 
	 * @access	public
	 * @param	HqSync		$hqs
	 * @return	array
	 */
	public function getExportDiff(HqSync &$hqs)
	{
		$arr_diff = array();

		// Iterate through csv files in the zip file
		foreach ($hqs->getCsvFileArray() as $hqs_csv) 
		{
			// Skip the file if in the ignore list
			if (array_search($hqs_csv->filename, $this->arr_csv_ignore_list) !== false) {
				if ($this->debug) echo "Filename '" . $hqs_csv->filename . "' in the ignore list. Skipping...\n";
				continue;
			}

			// If CSV file has matching entry in hqsync_table
			if ($hqs_table = $hqs->getHqSyncTableByFilename($hqs_csv->filename)) 
			{

				// If columns in csv file match columns in db
				if ($this->doColumnsMatch($hqs_csv, $hqs_table)) 
				{
					// indicate in changeset that this file is a complete match
					$arr_diff[$hqs_csv->filename] = HQSYNC_DIFF_NONE;
				}

				// else if columns in csv file do NOT match columns in db
				else 
				{
					// indicate in changeset that purge is required
					$arr_diff[$hqs_csv->filename] = HQSYNC_DIFF_COLUMN_MISMATCH;
				}
			}
			
			// Else CSV file does NOT have matching entry in hqsync_table, so create the table
			else {
				// indicate in changeset to create new table
				$arr_diff[$hqs_csv->filename] = HQSYNC_DIFF_TABLE_MISSING;
			}	
		}

		return $arr_diff;
	}
	

	/**
	 * Return whether the specified database exists in this connection (for the hqsync user)
	 *
	 * @param 	string		$db		Database name
	 * @return	bool				Whether db exists
	 */
	public function doesDatabaseExist($db) 
	{
		$sql_db_exists = "select count(*) from information_schema.schemata where schema_name='" .
			mysql_escape_string($db) . "'";
		if (!($rsc = $this->execute_query($sql_db_exists))) {
			trigger_error('Could not query db: ' . mysql_error($this->conn));
		}
		return (bool)mysql_result($rsc, 0);
	}


	/**
	 * Returns whether the specified table exists.
	 *
	 * @param 	string		$db		Database name
	 * @param	string		$table	Table name
	 * @return	bool				Whether table exists
	 */
	public function doesDatabaseTableExist($db, $table) 
	{
		$sql_table_exists = "select count(*) from information_schema.tables where table_schema='" . 
			mysql_escape_string($db) . "' and table_name='" . mysql_escape_string($table) . "'";
		if (!($rsc = $this->execute_query($sql_table_exists))) {
			trigger_error('Could not query db: ' . mysql_error($this->conn));
		}
		return (bool)mysql_result($rsc, 0);
	}


	/**
	 * Create database structure.
	 *
	 * @param	HqSync	$hqs	HqSync object containing information about the database structure to create
	 * @param	bool			TRUE if database structure created successfully; FALSE on error
	 */
	public function createDatabaseStructure(HqSync &$hqs)
	{
		/* 
		 * Make sure that the database exists for the current domain (fail if not; this should be 
		 * created by db admin)
		 */
		if (!$this->doesDatabaseExist($hqs->dbname)) {
			trigger_error('Database \'' . $hqs->dbname . '\' does not exist for domain \'' . 
				$hqs->domain . '\' or permission denied for the current database user. Databases ' . 
				'must be created before they can be used by HQSync.', E_USER_ERROR);
		}

		/*
		 * Throw an error if table already exists for any of the files - should be purged first
		 */
		foreach ($hqs->getCsvFileArray() as $hqs_csv) 
		{
			// Set table name for this csv
			// TODO: Need better method for generating table name (should do it in a CsvFile Object)
			$table_name = $hqs->form_name . '.' . $hqs_csv->filename;

			// Throw an error if the table already exists
			if ($this->doesDatabaseTableExist($hqs->dbname, $table_name)) {
				trigger_error('Table "' . $hqs->dbname . '"."' . $table_name . '" already exists. ' .
						'Database must be purged before a call to HqSyncFactory::createDatabaseStructure', E_USER_ERROR);
			}
		}

		/*
		 * Iterate through csv files and create db table
		 */
		foreach ($hqs->getCsvFileArray() as $hqs_csv) {
			$this->createTableFromCsv($hqs, $hqs_csv);
		}
		

		$hqs->log('Created full database structure');
		return true;
	}


	/**
	 * Prepares the database for a "start from scratch" import by dropping all database tables
	 * associated with the HqSync object and any records in hqsync_table. WARNING: This assumes 
	 * that HqSync has COMPLETE control over the database for this domain. Linked tables should
	 * be stored in a separate database.
	 *
	 * @param 	HqSync		$hqs
	 * @return	bool		TRUE on success; FALSE on error
	 */
	public function purgeDataset(HqSync &$hqs) 
	{
		/*
		 * Drop tables for anything in hqsync_table associated with this hqe object and delete 
		 * corresponding record in hqsync_table. 
		 */
		foreach ($hqs->getHqSyncTableArray() as $hqs_table) 
		{
			$sql = 'drop table if exists "' . mysql_escape_string($hqs->dbname) . '"."' . 
					mysql_escape_string($hqs_table->tablename) . '"';
			if (!$this->execute_query($sql)) {
				trigger_error('Unable to drop table "' . $hqs->dbname . '"."' . $hqs_table->tablename . 
						'". Drop tables manually and try again.', E_USER_ERROR);
			}
		}

		/*
		 * Also drop tables (if they exist) that will be used based on the new export. This is
		 * based on the csv files.
		 */
		foreach ($hqs->getCsvFileArray() as $hqs_csv) 
		{
			$table_name = $hqs->form_name . '.' . $hqs_csv->filename;
			$sql = 'drop table if exists "' . mysql_escape_string($hqs->dbname) . '"."' . 
					mysql_escape_string($table_name) . '"';
			if (!$this->execute_query($sql)) {
				trigger_error('Unable to drop table "' . $hqs->dbname . '"."' . $table_name .
						'". Drop tables manually and try again.', E_USER_ERROR);
			}
		}

		/*
		 * Delete records from hqsync_table associated with this hqe object
		 */
		$sql = 'delete from "' . mysql_escape_string($this->hqsync_dbname) . '".hqsync_table ' .
				'where hqsync_id = ' . $hqs->hqsync_id;
		if (!$this->execute_query($sql)) {
			trigger_error('Could not delete records from "' . $this->hqsync_dbname . '"."hqsync_table" ' .
					'in order to purge the dataset for domain \'' . $hqs->domain . '\' and form \'' . 
					$hqs->form_name . '\'', E_USER_ERROR);
		}
		$hqs->clearHqSyncTables();


		$hqs->log('Database purged');
	}

	/**
	 * Truncate all the database tables associated with the HqSync object.
	 * 
	 * @param  HqSync		$hqs
	 * @return none
	 */
	public function truncateTables(HqSync $hqs) 
	{
		$truncated_tables = '';
		foreach ($hqs->getHqSyncTableArray() as $hqs_table) {
			$sql = 'truncate table "' . mysql_escape_string($hqs->dbname) . '"."' . 
					mysql_escape_string($hqs_table->tablename) . '"';
			if (!$this->execute_query($sql)) {
				trigger_error('Could not truncate table "' . $this->dbname . '"."' .
						$hqs_table->tablename . '"', E_USER_ERROR);
			}
			if ($truncated_tables) $truncated_tables .= ', ';
			$truncated_tables .= $hqs_table->tablename;
		}
		if ($truncated_tables) $hqs->log('Truncated: ' . $truncated_tables);
	}

	/**
	 * Creates the database table that corresponds with the passed HqSync context and
	 * CSV file. Inserts a record into hqsync_table.
	 *
	 * @param	HqSync			$hqs
	 * @param	HqSyncCsvFile	$hqs_csv
	 * @return 	none
	 */
	public function createTableFromCsv(HqSync &$hqs, HqSyncCsvFile &$hqs_csv) 
	{
		/*
		 * Set table name
		 * TODO: This needs a more reliable method for setting filenames.
		 */
		$table_name = $hqs->form_name . '.' . $hqs_csv->filename;


		/*
		 * TODO: Automatically determine column types for each header 
		 * NOTE: Currently defaulting to VARCHAR(250). Can be modified database level and it will 
		 * not cause any issues with the next import, unless the data is incompatible with the 
		 * new datatype.
		 */


		/*
		 * Create the database table
		 */
		$sql_create_table = '';
		foreach ($hqs_csv->getFields() as $field) {
			if ($sql_create_table) $sql_create_table .= ', ';
			$sql_create_table .= "\"" . mysql_escape_string($field) . "\" VARCHAR(250)";
		}
		$sql_create_table = 'create table "' . mysql_escape_string($hqs->dbname) . '"."' . 
				mysql_escape_string($table_name) . '" (' . $sql_create_table . ')';
		if (!$this->execute_query($sql_create_table)) {
			trigger_error('Could not create table "' . $hqs->dbname . '"."' . $table_name . '". ' .
				'Complete sql: ' . $sql_create_table, E_USER_ERROR);
		}


		/*
		 * Insert record into hqsync_table
		 */
		$table_name = $hqs->form_name . '.' . $hqs_csv->filename;
		$hqs_table = new HqSyncTable(null, $hqs->hqsync_id, $hqs_csv->filename, $table_name, 1);
		$sql = 'insert into "' . mysql_escape_string($this->hqsync_dbname) . '"."hqsync_table" ' . 
				'(hqsync_id, filename, tablename) values (' .
				$hqs_table->hqsync_id . ', ' .
				"'" . mysql_escape_string($hqs_table->filename) . "', " .
				"'" . mysql_escape_string($hqs_table->tablename) . "'" .
				')';
		$this->execute_query($sql);
		$hqs_table->hqsync_table_id = mysql_insert_id($this->conn);
		$hqs->addHqSyncTable($hqs_table);
	}


	/**
	 * Creates the database structure for any CSV files indicated as missing in the diff array.
	 *
	 * @param	HqSync		$hqs
	 * @param 	array		$arr_diff		Results of a call to HqSyncFactory::getExportDiff()
	 * @return	none
	 */
	public function createMissingTables(HqSync &$hqs, array $arr_diff) 
	{
		$missing_table_names = '';
		foreach ($arr_diff as $diff_filename => $diff_result) 
		{
			// Only create the table if missing according to the diff
			if ($diff_result == HQSYNC_DIFF_TABLE_MISSING)
			{
				$hqs_csv = $hqs->getCsvFileByFilename($diff_filename);
				$this->createTableFromCsv($hqs, $hqs_csv);
				if ($missing_table_names) $missing_table_names .= ', ';
				$missing_table_names .= $diff_filename;
			}
		}

		if ($missing_table_names) $hqs->log('Created missing table(s): ' . $missing_table_names);
	}


	/**
	 * Imports data from all csv files in the HqSync object.
	 *
	 * @param	HqSync	$hqs
	 * @return	none
	 */
	public function importData(HqSync &$hqs) 
	{
		// Iterate through unzipped files and import
		foreach ($hqs->getCsvFileArray() as $hqs_csv) 
		{
			$database  = mysql_escape_string($hqs->dbname);
			$tablename = mysql_escape_string($hqs->form_name . '.' . $hqs_csv->filename);
			$sql = 'load data infile \'' . mysql_escape_string($hqs_csv->pathname) . '\' ' . 
					'into table "' . $database . '"."' . $tablename . '" ' . 
					'fields terminated by \',\' ' . 
					'optionally enclosed by \'"\' ' .
					'escaped by \'\\\\\' ' . 
					'ignore 1 lines';
			if (!$this->execute_query($sql)) {
				trigger_error('Could not import data for \'' . $database . '\'.\'' . $tablename . 
						'\' from ' . $hqs_csv->pathname, E_USER_ERROR);
			}
			$num_imported_rows = mysql_affected_rows($this->conn);
			$hqs->log($tablename . ': ' . $num_imported_rows . ' rows imported');
		}
	}


	/**
	 * Insert a record into hqsync_log information about the results of this synchronization.
	 *
	 * NOTE: Date is returned in UTC. Timezone of the server must be set accurately.
	 * 
	 * TODO: Right now this only logs a successful import. All other outcomes result in an error
	 * and execution halts. There are other events that should be logged as well, such as db
	 * purges, table creation, errors, etc. that should be logged instead of halting execution
	 * so that this can run in the background.
	 *
	 * @access	public
	 * @param	HqSync		$hqs
	 * @param   int			$status_code
	 * @param   Exception   $exception		Optional exception object thrown when status is an ERROR.
	 * @return	bool
	 */
	public function saveImportLog($hqs, $status_code, Exception $exception = null) 
	{
		$msg = implode('; ', $hqs->getLogs());
		$input_token = '';
		if ($status_code === HQSYNC_STATUS_SUCCESSFUL_IMPORT) {
			if (!$hqs->use_token || $hqs->ignore_input_token) $input_token = '';
			else $input_token = $hqs->input_token;
		} else if ($status_code === HQSYNC_STATUS_EMPTY_EXPORT) {
			if (!$hqs->use_token || $hqs->ignore_input_token) $input_token = '';
			else $input_token = $hqs->input_token;
			// NOTE: Empty export has a blank output_token
		} else if ($status_code === HQSYNC_STATUS_ERROR) {
			if ($msg) $msg .= '; ';
			$msg .= $exception->getMessage();
		} else {
			throw new Exception('Invalid status code (' . $status_code . ') in HqSyncFactory::saveImportLog');
		}

		$sql = 'insert into "' . $this->hqsync_dbname . '"."hqsync_log" ' . 
				'(hqsync_id, input_token, output_token, sync_time, sync_status, message) values (' .
				$hqs->hqsync_id . ', ' .
				"'" . $input_token . "', " .
				"'" . $hqs->output_token . "', " .
				"'" . gmdate('Y-m-d H:i:s') . "', " .
				$status_code . ', ' .
				"'" . mysql_escape_string(substr($msg, 0, 1000)) . "'" .
				')';
		$this->execute_query($sql);
	}


	/**
	 * Executes the mysql_query function using the connection stored in this object. Displays 
	 * debug information if HqSyncFactory::debug is true.
	 *
	 * @param	string		$sql	SQL to execute.
	 * @return	mixed				The return value of mysql_query
	 */
	private function execute_query($sql) 
	{
		if ($this->debug) echo 'SQL: ' . $sql . "\n";
		$result = mysql_query($sql, $this->conn);
		if ($this->debug) {
			if ($str_info = mysql_info($this->conn)) {
				echo $str_info;
			} else if (is_resource($result)) {
				echo mysql_num_rows($result) . ' rows returned.';
			}
			echo "\n";
		}
		return $result;
	}
}


/**
 * HqSyncHelp class
 */
class HqSyncHelp 
{
    /**
     * PARSE ARGUMENTS
     * 
     * [pfisher ~]$ echo "<?php
     * >     include('CommandLine.php');
     * >     \$args = CommandLine::parseArgs(\$_SERVER['argv']);
     * >     echo "\n", '\$out = '; var_dump(\$args); echo "\n";
     * > ?>" > test.php
     * 
     * [pfisher ~]$ php sync.php plain-arg --foo --bar=baz --funny="spam=eggs" --alsofunny=spam=eggs \
     * > 'plain arg 2' -abc -k=value "plain arg 3" --s="original" --s='overwrite' --s
     * 
     * $out = array(12) {
     *   [0]                => string(9) "plain-arg"
     *   ["foo"]            => bool(true)
     *   ["bar"]            => string(3) "baz"
     *   ["funny"]          => string(9) "spam=eggs"
     *   ["alsofunny"]      => string(9) "spam=eggs"
     *   [1]                => string(11) "plain arg 2"
     *   ["a"]              => bool(true)
     *   ["b"]              => bool(true)
     *   ["c"]              => bool(true)
     *   ["k"]              => string(5) "value"
     *   [2]                => string(11) "plain arg 3"
     *   ["s"]              => string(9) "overwrite"
     * }
     *
     * @author              Patrick Fisher <patrick@pwfisher.com>
     * @since               August 21, 2009
     * @see                 http://www.php.net/manual/en/features.commandline.php
     *                      #81042 function arguments($argv) by technorati at gmail dot com, 12-Feb-2008
     *                      #78651 function getArgs($args) by B Crawford, 22-Oct-2007
     * @usage               $args = CommandLine::parseArgs($_SERVER['argv']);
     */
    public static function parseArgs($argv){
    
        array_shift($argv);
        $out                            = array();
        
        foreach ($argv as $arg){
        
            // --foo --bar=baz
            if (substr($arg,0,2) == '--'){
                $eqPos                  = strpos($arg,'=');
                
                // --foo
                if ($eqPos === false){
                    $key                = substr($arg,2);
                    $value              = isset($out[$key]) ? $out[$key] : true;
                    $out[$key]          = $value;
                }
                // --bar=baz
                else {
                    $key                = substr($arg,2,$eqPos-2);
                    $value              = substr($arg,$eqPos+1);
                    $out[$key]          = $value;
                }
            }
            // -k=value -abc
            else if (substr($arg,0,1) == '-'){
            
                // -k=value
                if (substr($arg,2,1) == '='){
                    $key                = substr($arg,1,1);
                    $value              = substr($arg,3);
                    $out[$key]          = $value;
                }
                // -abc
                else {
                    $chars              = str_split(substr($arg,1));
                    foreach ($chars as $char){
                        $key            = $char;
                        $value          = isset($out[$key]) ? $out[$key] : true;
                        $out[$key]      = $value;
                    }
                }
            }
            // plain-arg
            else {
                $value                  = $arg;
                $out[]                  = $value;
            }
        }
        return $out;
    }


	static function displayUsage() {
		echo "Usage: php hqsync.php [options]\n";
	}
	static function displayHelp() {
		HqSyncHelp::displayUsage();
		echo <<<END
Options:
    -d    --domain    Limits sync to the specified domain name. Default displays all.
    -f    --form      Limits sync to the specified form name. Default displays all.
          --help      Display this help screen
    -h    --host      Database host name (default=localhost)
    -p    --password  Database password (default=hqsync)
    -s    --schema    Database schema name (default=hqsync)
    -u    --user      Database username (default=hqsync)
    -v                Verbose (default=false)


END;
	}

	static function getArg(array $args, $params, $value_state, $default_value, $error_message, 
			$display_help_on_error = true, $exit_on_error = true)
	{
		if (!is_array($params)) $params = array($params);

		$return_value = $default_value;
		foreach ($params as $param_name) 
		{
			if (isset($args[$param_name])) {
				if ($value_state === HQSYNC_ARGS_VALUE_REQUIRED) {
					if (is_bool($args[$param_name])) {
						echo $error_message;
						if ($display_help_on_error) HqSyncHelp::displayHelp();
						if ($exit_on_error) die();
					}
					$return_value = $args[$param_name];
				} else if ($value_state === HQSYNC_ARGS_VALUE_OPTIONAL) {
					$return_value = $args[$param_name];
				} else if ($value_state === HQSYNC_ARGS_VALUE_NOT_ALLOWED) {
					if ($args[$param_name] !== true) {
						echo $error_message;
						if ($display_help_on_error) HqSyncHelp::displayHelp();
						if ($exit_on_error) die();
					}
					$return_value = $args[$param_name];
				} else {
					trigger_error('Invalid value state in HqSyncHelp::getArg (' . $value_state . ')', E_USER_ERROR);
				}
			}
		}
		return $return_value;
	}

}


?>