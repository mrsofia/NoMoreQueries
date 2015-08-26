<?php
	
Class tableWrapper {

	private $db; 		// the already-instantiated database connection we're using
	private $table;		// the name of the table being used by this instance
	private $pri_key;	// the name of this table's PRIMARY_KEY column
	private $columns;	// an array containing the titles of all columns in this table
	
	// reference can be found in the comments at: http://php.net/manual/en/mysqli-result.fetch-fields.php
	const BOOL_ 				= 1;
	const INT_ 					= 3;
	const FLOAT_ 				= 4;
	const TIMESTAMP_ 			= 7;
	const DATETIME_ 			= 12;
	const LONGTEXT_OR_BLOB_ 	= 252;
	const VARCHAR_ 				= 253;
	
	// improve error handling eventually (simple: http://php.net/manual/en/mysqli.quickstart.prepared-statements.php)

	/**
	 *  @brief 			Constructor function. Sets all class properties.
	 *  
	 *  @param [in] $db    	An active MySQLi database connection.
	 *  @param [in] $table 	The name of the table (as a string) being instantiated.
	 *  @return 			void
	 *  
	 *  @details 		NOTE: Assumes that the first column in your table is the PRIMARY KEY for that table. Won't work otherwise. 
	 *  
	 */
	function __construct($db, $table) {
		$this->db = $db;
		$this->table = $table;
		self::setPrimaryKey();
		$this->columns = self::getColumnInfo("name");
	}
	
	/**
	 *  @brief 			Constructor helper method, sets the name of the PRIMARY_KEY field. 
	 *  
	 *  @return 		void
	 *  
	 *  @details 		Sets $this->pri_key to the PRIMARY_KEY field on the current table.
	 */
	private function setPrimaryKey() {
		$stmt = $this->db->prepare(
			"SHOW COLUMNS FROM " . $this->table);
		$stmt->execute();
		$tmp = $stmt->get_result();
		$results = $tmp->fetch_all();
		
		foreach ($results as $res)
		{
			if ($res[3] === 'PRI') {
				$this->pri_key = $res[0];
			}
		}
		if (!isset($this->pri_key)) {
			throw new Exception("Error: this->pri_key not set. This table must not have a primary_key");
		}
	}

	/**
	 *  @brief 			Retrieves information about columns in a table and returns them as an array. 
	 *  
	 *  @param [in] $desiredProperty 	The column property being requested. to see a list of available column properties 
	 *									try http://php.net/manual/en/mysqli-result.fetch-fields.php
	 *  @return 						An enumerated array containing the column info requested, if valid. 
	 *  
	 *  @details 		Common uses of this function include: getting the names of all columns, types of each column, and length of each column. 
	 *  	DO NOT ASK FOR "max_length". This is broken. Use "length" if you're asking for the maximum size of the value.
	 *		DOES NOT differentiate between BLOB and TEXT because PHP is stupid and gives them the same code. Handle your instances accordingly. 
     *
	 */
	function getColumnInfo($desiredProperty) {
		// initialize result set as empty array
		$result = [];

		// prepare mySQL statement - we'll be selecting the very first row of the table
		// ASSUMES there is an entry in your database with an XID = 1 (working on a better fix)
		$stmt = $this->db->prepare( 
					"SELECT * FROM " . $this->table . 
					" WHERE " . $this->pri_key . "=" . 1);
		$stmt->execute();
		$tmp = $stmt->get_result();
		// store column info into an array. Note that fetch_fields() returns an array
		$tmp_results = $tmp->fetch_fields();
		
		// loop through each column info value, appending each value to the $result array
		foreach ($tmp_results as $column) {
			// since PHP returns the type as a code rather than a string, we must decode type into a string
			// if the client code asks for it
			// NOTE that PHP returns the same code for TEXT or BLOB. Make sure that your client code
			// knows the difference, as it cannot be handled here. BLOB or TEXT will both return "text".
			if ($desiredProperty == "type") {
				switch ($column->$desiredProperty) {
					case self::BOOL_:
						array_push($result, "boolean");
						break;
					case self::INT_:
						array_push($result, "int");
						break;
					case self::FLOAT_:
						array_push($result, "float");
						break;
					case self::TIMESTAMP_:
						array_push($result, "timestamp");
						break;
					case self::DATETIME_:
						array_push($result, "datetime");
						break;
					case self::LONGTEXT_OR_BLOB_:
						// defaults to text. this will break if blobs are added to the database later
						array_push($result, "text");
						break;
					case self::VARCHAR_:
						array_push($result, "varchar");
						break;
					default:
						throw new Exception("Switch statement ends in default. Add proper data types as constants? ");
						break;
				}
			}
			else {
				array_push($result, $column->$desiredProperty);
			}
		}
		return $result;
	}
	/**
	 *  @brief 			Adds a new entry to the MySQL table while ensuring NOT_NULL values are set, 
	 *  				and ensuring that all given column names in $entry_vals are valid
	 *  
	 *  @param [in] $entry_vals 	An associative array where each key corresponds to a column name, and each value is the desired value for that column
	 *  @return 					True if attempt was successful, false otherwise
	 *  
	 */
	function addNewEntry ( $entry_vals ) {
		$nonNullColumns = $this->getNonNullColumns();
		
		// Check that all $entry_vals have valid column names
		foreach ( $entry_vals as $col_name => $col_value ) {
			if ( !in_array( $col_name, $this->columns )) { 							// "if this $col_name is not found within this table's columns"
				throw new Exception("<br> failed in valid column name check, " . $col_name . " given, but not found in this->columns");
			}
		}
		// Check that $entry_vals contains values for all NOT_NULL elements without default values
		// If no $entry_vals value is given for a field that is set to NOT_NULL, fail and return false
		$defaults = $this->getColumnsWithDefaults();
		foreach ( $nonNullColumns as $col_name => $position ) {
			if ( !in_array( $col_name, array_keys( $entry_vals )) && ( $defaults[$col_name] === null)) {
				throw new Exception("<br> failed in non-null check, " . $col_name . " is required, but value passed in is null");
			}
		}
		
		// If we've reached this point, we know that all required (NOT_NULL) values are included
		// 	in the $entry_vals array, and that all $entry_vals have valid column names. 
		
		$keys = " (" . implode(", ", array_keys($entry_vals)) . ")";
		$vals = " ('" . implode("', '", $entry_vals) . "')";
		// prepare statement
		$stmt = $this->db->prepare("INSERT INTO " . $this->table . $keys . " VALUES " . $vals);
		$stmt->execute();
		$stmt->close();
		
		if (!mysqli_errno($this->db)) {
			return true;
		}
		else {
			throw new Exception("<br> something went wrong; mysqli errno: " . mysqli_errno($this->db));
		}
	}
	
	/**
	 *  @brief 			Deletes an entry in the database.
	 *  
	 *  @param [in] $id 	The PRIMARY_KEY value for the entry in question. 
	 *  @return 			Returns True if the entry was deleted, throws an exception if something went wrong with the database. 
	 *  
	 *  @details 		This method is NOT RECOMMENDED. Only use if absolutely necessary. Better implementation would preserve the record, but transfer state to "inactive".
	 */
	function deleteEntryById( $id ) {
		// should this be able to delete whole entries, or should we add a field isDeactivated or isDeleted and make this turn it to true? 
		$stmt = $this->db->prepare("DELETE FROM " . $this->table . " WHERE " . $this->pri_key . "=" . $id);
		$stmt->execute();
		$stmt->close();
		
		if (!mysqli_errno($this->db)) {
			return true;
		}
		else {
			throw new Exception("<br> something went wrong; mysqli errno: " . mysqli_errno($this->db));
		}
		
	}
	
	/**
	 *  
	 *  @brief 		Fetches an array that denotes which columns have default values. Columns without defaults will be NULL.
	 *  @return 		Associative array where key = column name & value == NULL if no default is assigned. 
	 *  				If a default is assigned for a column, value = the default for that column.
	 *  
	 *  
	 */
	private function getColumnsWithDefaults() {
		$values = array();
	
		// prepare statement
		$stmt = $this->db->prepare("SHOW COLUMNS FROM " . $this->table);
		$stmt->execute();

		// get results, fetch them all into an array
		$tmp = $stmt->get_result();
		$tmp_results = $tmp->fetch_all();

		$stmt->close();

		// add each result to the "values" array
		foreach ($tmp_results as $field => $default) { 		// each '$default' is an array. 
			$values[$default[0]] = $default[4];  			// 'default[0]' is the name of the column, 'default[4]' is the default value
		}
		return $values;
	}
		
	/**
	 *  @brief 		Indicates which columns are NOT allowed to be null (fallback for non-strict SQL mode)
	 *  
	 *  @return 		An associative array where each KEY = name of the column and VALUE = the index of that column within the database. 
	 *  
	 *  @details 	Uses a neat bitwise algo to decode which columns have a NOT_NULL property. 
	 */
	private function getNonNullColumns () 
	{
		$result;
		
		$column_names = $this->getColumnInfo("name");
		$column_flags = $this->getColumnInfo("flags");
	
		foreach ( $column_flags as $pos => $flag ) 
		{
			$column_flags_array = $this->h_flags2txt($flag);
		
			$flagsString = strtolower(implode(", ", $column_flags_array));

			// store the return value of strpos
			$strpos = strpos($flagsString, "not_null");
			if ($strpos !== false) { $result [ $column_names[$pos]] = $pos; }
		}
		return $result;
	}
	
	/**
	 *  @brief 		Decodes php_mysqli column codes
	 *  
	 *  @param [in] $flags_num 		The number to be decoded
	 *  @return 					Enumerated array of all column types in String form
	 *  
	 *  @details 		Uses a bitwise algo and a library of all defined constants to decode php_mysqli column codes
	 */
	private function h_flags2txt ( $flags_num ) {
		$flags;

		if (!isset($flags)) 
		{
			$flags = array();
			$constants = get_defined_constants(true);
			foreach ($constants['mysqli'] as $c => $n) if (preg_match('/MYSQLI_(.*)_FLAG$/', $c, $m)) 
			{
				if (!array_key_exists($n, $flags)) { $flags[$n] = $m[1]; }
			}
		}

		$result = array();
		foreach ($flags as $n => $t) 
		{
			if ($flags_num & $n) { $result[] = $t; }
		}
		return $result;
	}

	/**
	 *  @brief 		Primary function handler for all "get" procedures. 
	 *  
	 *  @param [in] $id   	The PRIMARY_KEY of the MySQL table entry in question
	 *  @param [in] $keys 	An array of strings specifying the column names being requested
	 *  @return 			An associative array where each key = column name requested and value = column value for that entry
	 *  
	 *  @details 	This wraps all of the necessary SQL querying into one function, giving us a flexible method that will query the database for as many values 
	 *	as we specify, and return them all in a clearly labeled associative array for our later use. 
	 *	In this manner, "get" implies reading a value from the database - no editing is permitted. 
	 */
	function getValuesById($id, $keys) {
		$values = array();
		
		// split up $keys array into a string separated by commas for MySQL parsing
		$parsableKeys = implode(", ", $keys);
		
		// prepare statement
		$stmt = $this->db->prepare("SELECT " . $parsableKeys . " FROM " . $this->table . " WHERE " . $this->pri_key . "=" . $id);
		$stmt->execute();

		// get results, fetch them all into an array
		$tmp = $stmt->get_result();
		$tmp_results = $tmp->fetch_all();
		$stmt->close();

		// add each result to the "values" array
		foreach ($tmp_results[0] as $column) 
		{
			array_push($values, $column);
		}

		// we now have a $keys array and a $values array. 
		// combine the two to make a nice associate array and return it
		$result = array_combine($keys, $values);
		return $result;
	}
	
	/**
	 *  @brief 		Slimmer, more agile "get" method for one-off information requests. 
	 *  
	 *  @param [in] $id    		The PRIMARY_KEY of the MySQL table entry in question
	 *  @param [in] $field 		An array of strings specifying the column names being requested
	 *  @return 				An associative array where each key = column name requested and value = column value for that entry
	 *  
	 *  @details 	Only allows one column to be retrieved per call - don't use this to get multiple columns from one entry. Use getValuesById instead. 
	 */
	function getOneFieldById($id, $field) {
		// prepare statement
		$stmt = $this->db->prepare("SELECT " . $field . " FROM " . $this->table . " WHERE " . $this->pri_key . "=" . $id);
		$stmt->execute();

		// get results, fetch them all into an array
		$stmt->bind_result($result);
		$stmt->fetch();
		$stmt->close();
		
		if (!mysqli_errno($this->db)) {
			return $result;
		}
		else {
			throw new Exception("<br> something went wrong; mysqli errno: " . mysqli_errno($this->db));
		}
	}
	
	/**
	 *  @brief 		Primary function handler for all database "editing" procedures. 
	 *  
	 *  @param [in] $id       	The PRIMARY_KEY of the MySQL table entry in question.
	 *  @param [in] $new_vals 	A clearly-labeled associative array where the KEYS are the names of the columns you 
			wish to change, and where the VALUES are the values you wish to change them to. 
			The array must be clearly labeled, each key must be exactly equal to one of the columns in $this->table. 
	 *  @return 				If changes were successful, returns true
	 *  
	 *  @details 	This wraps all of the necessary SQL querying into one function, giving us a flexible method. The method accepts a clearly-labeled 
			associative array as a parameter, and will edit the values in the database according to the 
			values in the array. that will edit the database for as many values as we specify, and return 
			them all in a clearly labeled associative array for our later use. 
			In this manner, "get" implies reading a value from the database - no editing is permitted. 
	 */
	function editValuesById($id, $new_vals) {
		$values = array();
		
		$defaults = $this->getColumnsWithDefaults();
		$nonNullColumns = $this->getNonNullColumns();

		foreach ( $new_vals as $col_name => $new_val ) 
		{
			// Long expression, but it says: if current col is one of the non-null columns, and there is no default value 
			// to fall back on, then the value you put in can NOT be null. It also cannot be empty.
			if ( ( in_array($col_name, array_keys($nonNullColumns)) ) && ( $defaults[$col_name] === null ) && ( empty($new_val) )) 
			{
				throw new Exception("<br> failed in non-null check , " . $col_name . " can't be null or empty, but empty value was passed in");
			}
		}
		// create array wherein each element equals the proper SET name=value notation
		// this is prep work for our MySQL statement. Later we'll implode the array into a
		// SQL-parsable string
		foreach ($new_vals as $element) 
		{
			array_push($values, key($new_vals) . "='" . $element . "'");
			next($new_vals);
		}
	
		// convert values array into a MySQL-parsable string
		$values = implode(", ", $values);
		
		// prepare statement
		$stmt = $this->db->prepare("UPDATE " . $this->table . " SET " . $values . " WHERE " . $this->pri_key . "=" . $id);
		$stmt->execute();
		$stmt->close();
		
		$result = $this->verifyChanges($id, $new_vals);
		return $result;
	}

	/**
	 *  @brief 		Slimmer, more agile "get" method for one-off information requests. 
	 *  
	 *  @param [in] $id     	The PRIMARY_KEY of the MySQL table entry in question
	 *  @param [in] $field  	An array of strings specifying the column names being requested
	 *  @param [in] $newVal 	An associative array where each key = column name requested and value = column value for that entry
	 *  @return 				Returns true if edit did not cause any MySQLi errors
	 *  
	 *  @details 	Only allows one column to be retrieved per call - don't use this to get multiple columns from one entry. Use getValuesById instead. 
	 */
	function editOneFieldById( $id, $field, $newVal) {
		$defaults = $this->getColumnsWithDefaults();
		$nonNullColumns = $this->getNonNullColumns();

		// Long expression, but it says: if current col is one of the non-null columns, and there is no default value 
		// to fall back on, then the value you put in can NOT be null. It also cannot be empty.
		if ( ( in_array($field, array_keys($nonNullColumns)) ) && ( $defaults[$field] === null ) && ( empty($newVal) )) 
		{
			throw new Exception("<br> failed in non-null check , " . $field. " can't be null or empty, but empty value was passed in");
		}
		
		var_dump($field);
		var_dump($newVal);
		// prepare statement
		$stmt = $this->db->prepare("UPDATE " . $this->table . " SET " . $field . " = '" . $newVal . "' WHERE " . $this->pri_key . "=" . $id);
		$stmt->execute();
		$stmt->close();

		if (!mysqli_errno($this->db)) {
			$test = [$field => $newVal];
			return $this->verifyChanges($id, $test);
		}
		else {
			throw new Exception("<br> something went wrong; mysqli errno: " . mysqli_errno($this->db));
		}
	}

	/**
	 *  @brief 		Checks if the values passed into edit() are the same as the values in the database. 
	 *  
	 *  @param [in] $id       	The id of the entry in question
	 *  @param [in] $new_vals 	The array describing the columns and values to be changed
	 *  @return 				Returns true if the desired changes === the values currently in the database
	 *  
	 *  @details 	DOES NOT necessarily mean that changes were properly committed, only checks if the current values
		in the database are equal to the changes that were originally desired. The idea being that if
		the update was unsuccessful, the desired values would not be found in the entry
	 */
	private function verifyChanges($id, $new_vals) {
		$resultFromGet = $this->getValuesById($id, array_keys($new_vals));
		
		if($resultFromGet == $new_vals) {
			return true;
		}
		else {
			return false;
		}
	}

}

?>