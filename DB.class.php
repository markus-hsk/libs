<?php

namespace MBuscher;

/**
 * Created by PhpStorm.
 * User: mbuscher
 * Date: 27.09.2016
 * Time: 14:01
 */


define('dbInt',		'int');
define('dbFloat',	'float');
define('dbText',	'text');
define('dbBool',	'bool');
define('dbDate',	'date');

final class MySqlDb
{
	protected $db_host		= '';
	protected $db_port		= '';
	protected $db_user		= '';
	protected $db_password	= '';
	protected $db_database  = '';

	/** @var string $table_prefix allows to define a prefix for the tables names to be automatically prepended on the given tables */
	protected $table_prefix = '';
	
	protected $use_cache = false;

	/** @var mysqli $db */
	protected $db = null;

	protected $result = null;


	// ##### Magic methods ############################################################################################
	
	/**
	 * Constructor, builds a new instance
	 * 
	 * @param	string		$host
	 * @param	int			$port
	 * @param	string		$user
	 * @param	string		$password
	 * @param	string		$database
	 * @param	array		$options
	 * @return	MySqlDb
	 */
	function __construct($host, $port, $user, $password, $database, array $options = [])
	{
		$this->db_host		= $host;
		$this->db_port		= $port;
		$this->db_user 		= $user;
		$this->db_password	= $password;
		$this->db_database	= $database;

		if(isset($options['use_cache']))
		{
			$this->useCache($options['use_cache']);
			unset($options['use_cache']);
		}
		
		if(isset($options['table_prefix']))
		{
			$this->tablePrefix($options['table_prefix']);
			unset($options['table_prefix']);
		}

		$this->connect();
	}
	
	
	
	// ##### Setters and Getters ######################################################################################

	/**
	 * Sets the connection and gets the connection state
	 * 
	 * @param	void
	 * @return 	boolean
	 */
	protected function connect()
	{
		if($this->db === null)
		{
			// @todo implement retries on failure
			$this->db = mysqli_connect($this->db_host, $this->db_user, $this->db_password, $this->db_database, $this->db_port);
			mysqli_set_charset($this->db, 'utf8');
		}
	
		return true;
	}
	

	/**
	 * Gets and sets the parameter for cache usage
	 *
	 * @param	boolean	$use_cache
	 * @return	boolean
	 */
	function useCache($use_cache = null)
	{
		if($use_cache !== null)
		{
			$this->use_cache = (bool) $use_cache;
		}

		return $this->use_cache;
	}
	
	
	/**
	 * Gets and sets the parameter for the table prefix
	 * 
	 * @param	string		$set_prefix
	 * @return	string
	 */
	function tablePrefix($set_prefix = null)
	{
		if(strlen($set_prefix) > 0)
		{
			$this->table_prefix = $set_prefix;
		}
		
		return $this->table_prefix;
	}

	
	
	
	// ###### Interacting #############################################################################################

	/**
	 * Select field data from the given database table by using filters, sorter and limitations
	 * 
	 * @param	string		$table
	 * @param	array		$fields			Array of fieldnames, empty array resulting in * (all possible fields)
	 * @param	array		$sort			Array of sorting definitions (["field1" => SORT_ASC, "field2" => SORT_DESC])
	 * @param	array		$limit			[0,10]
	 * @param	array		$options		Options
	 * @return	array	associative array of all selected records
	 * @throws	MySqlDbException
	 */
	public function select($table, array $fields = [], array $filter = null, array $sort = null, array $limit = null, array $options = [])
	{
		$use_cache = isset($options['use_cache']) ? (bool)$options['use_cache'] : $this->useCache();
		if($use_cache)
		{
			$cache_key = $this->getCacheKey('select', func_get_args());
			if(($cached = Cache::get($cache_key)) !== false)
			{
				return $cached;
			}
		}

		$sql = "SELECT ";

		// append the field list
		if(count($fields) == 0)
		{
			$sql .= "* ";
		}
		else
		{
			$sql .= "`" . implode("`, `", $fields) . "` ";
		}

		// append table name
		$table_prefix = isset($options['table_prefix']) ? $options['table_prefix'] : $this->tablePrefix();
		$sql .= "FROM `".$table_prefix . $table . "` ";

		$where = $this->buildWhere($filter);
		if(strlen($where))
		{
			$sql .= "WHERE $where ";
		}

		if(is_array($sort))
		{
			$order_by = "";

			foreach($sort as $field => $dir)
			{
				$order_by .= "`$field` ".($dir == SORT_DESC ? 'DESC' : 'ASC')." ";
			}

			if(strlen($order_by))
			{
				$sql .= "ORDER BY $order_by";
			}
		}

		if(is_array($limit))
		{
			list($skip, $amount) = $limit;
			$sql .= "LIMIT $skip,$amount";
		}

		if($result = mysqli_query($this->db, $sql))
		{
			$records = array();
			while($data = mysqli_fetch_assoc($result))
			{
				$records[] = $data;
			}
				
			if($use_cache)
			{
				Cache::set($cache_key, $records);
			}

			return $records;
		}
		else
		{
			$error_code = mysqli_errno($this->db);
			$error_message = mysqli_error($this->db);
				
			throw new MySqlDbException($error_message, $error_code);
		}
	}
		
	
	/**
	 * Inserts a new record in the given database table
	 *
	 * @param	string		$table
	 * @param	array		$set_array		Array of fields and their values
	 * @param	array		$options		Options
	 * @return	int			Inserted record id (should be >0)
	 * @throws	MySqlDbException
	 */
	public function insert($table, array $set_array, array $options = [])
	{
		if(!count($set_array))
		{
			throw new MySqlDbException('Insert need at minimum one field to be set', -1); // @todo define Error codes
		}
		
		if(isset($options['replace']) && $options['replace'] == true)
		{
			$sql = "REPLACE INTO ";
		}
		else
		{
			if(isset($options['ignore_duplicate']) && $options['ignore_duplicate'] == true)
			{
				$sql = "INSERT IGNORE INTO ";
			}
			else
			{
				$sql = "INSERT INTO ";
			}
		}
		
		$table_prefix = isset($options['table_prefix']) ? $options['table_prefix'] : $this->tablePrefix();
		$sql .= "`".$table_prefix . $table . "` ";
		
		$sql .= "SET ";
		
		$settings = array();
		foreach($set_array as $field => $value)
		{
			$settings[] = "`$field` = ".$this->toSql($value);
		}
		
		$sql .= implode(", ", $settings);
		
		$result = $this->db->query($sql);
		if($result && !mysqli_errno($this->db))
		{
			return $this->db->insert_id;
		}
		else
		{
			$error_code = mysqli_errno($this->db);
			$error_message = mysqli_error($this->db);
			
			throw new MySqlDbException($error_message, $error_code);
		}
	}
	
	
	/**
	 * Replaces a record in the given database table through a new one
	 *
	 * @param	string		$table
	 * @param	array		$set_array		Array of fields and their values
	 * @param	array		$options		Options
	 * @return	int			Inserted record count (should be 1)
	 * @throws	MySqlDbException
	 */
	public function replace($table, array $set_array, array $options = [])
	{
		$options = array_merge($options, ['replace' => true]);
		
		return $this->insert($table, $set_array, $options);
	}
	
	
	/**
	 * Updates records in the given database table with the given set_array
	 *
	 * @param	string		$table
	 * @param	array		$filter			Array of fieldnames, empty array resulting in * (all possible fields)
	 * @param	array		$set_array		Array of fields and their values
	 * @param	array		$options		Options
	 * @return	int			Updated records count (should be >0)
	 * @throws	MySqlDbException
	 */
	public function update($table, array $filter, array $set_array, array $options = [])
	{
		if(!count($set_array))
		{
			throw new MySqlDbException('Insert need at minimum one field to be set', -1); // @todo define Error codes
		}
		
		$sql = "UPDATE ";
		
		$table_prefix = isset($options['table_prefix']) ? $options['table_prefix'] : $this->tablePrefix();
		$sql .= "`".$table_prefix . $table . "` ";
		
		$settings = array();
		foreach($set_array as $field => $value)
		{
			$settings[] = "`$field` = ".$this->toSql($value);
		}
		
		$sql .= "SET " . implode(", ", $settings) . " ";
		
		$where = $this->buildWhere($filter);
		if(strlen($where))
		{
			$sql .= "WHERE $where ";
		}
		
		$result = $this->db->query($sql);
		if($result && !mysqli_errno($this->db))
		{
			return $this->db->affected_rows;
		}
		else
		{
			$error_code = mysqli_errno($this->db);
			$error_message = mysqli_error($this->db);
				
			throw new MySqlDbException($error_message, $error_code);
		}
	}
	
	
	/**
	 * Deletes records from the given database table
	 *
	 * @param	string		$table
	 * @param	array		$filter			Array of fieldnames, empty array resulting in * (all possible fields)
	 * @param	array		$options		Options
	 * @return	int			Deleted records count (should be >0)
	 * @throws	MySqlDbException
	 */
	public function delete($table, array $filter, array $options = [])
	{
		$sql = "DELETE ";
		
		// append table name
		$table_prefix = isset($options['table_prefix']) ? $options['table_prefix'] : $this->tablePrefix();
		$sql .= "FROM `".$table_prefix . $table . "` ";
		
		$where = $this->buildWhere($filter);
		if(strlen($where))
		{
			$sql .= "WHERE $where ";
		}
		
		$result = $this->db->query($sql);
		if($result && !mysqli_errno($this->db))
		{
			return $this->db->affected_rows;
		}
		else
		{
			$error_code = mysqli_errno($this->db);
			$error_message = mysqli_error($this->db);
		
			throw new MySqlDbException($error_message, $error_code);
		}
	}
	

	protected function buildWhere(array $filter = null)
	{
		if(is_array($filter) && count($filter) > 0)
		{
			$wheres = array();

			foreach($filter as $key => $value)
			{
				switch($key)
				{
					case '$like':
						$wheres[] = "`$key` LIKE '$value'";
						break;

					default:
						if(!is_numeric($value))
						{
							$wheres[] = "`$key` = ".$this->toSql($value, dbText);
						}
						else if(!is_int($value))
						{
							$wheres[] = "`$key` = ".$this->toSql($value, dbFloat);
						}
						else
						{
							$wheres[] = "`$key` = ".$this->toSql($value, dbInt);
						}
						break;
				}
			}

			return implode(' AND ', $wheres);
		}
		else
		{
			return '';
		}
	}

	
	protected function toSql($value, $value_type = '', $empty_as = '')
	{
		if($value === NULL)
		{
			if($empty_as === NULL)
				return 'NULL';
				else
					$value = $empty_as;
		}

		switch($value_type)
		{
			case dbInt:	// Wenn ganzzahlige Werte zur�ck gegeben werden sollen
				return (int)$value;
				break;

			case dbBool:	// Wenn Wahrheitswerte zur�ck gegeben werden sollen
				return $value ? 'TRUE' : 'FALSE';
				break;

			case dbFloat:		// Wenn Flie�kommazahlen zur�ck gegeben werden sollen
				$float = floatval(str_replace(',', '.', $value));
				if((String) $float == 'INF')
					return 0;
					else
						return $float;
						break;

			case 'unixts':		// Wenn ein Zeitstempel erwartet wird
			case dbDate:		// Wenn ein Datumswert erwartet wird
			case 'datetime':	// Wenn ein Datum mit Zeit erwartet wird
				if(is_int($value))
				{
					if($value > 0)
						return "'".date('Y-m-d H:i:s', $value)."'";
						else
							return "'0000-00-00 00:00:00'";
				}
				else {
					if (is_string($value) && strlen($value) == 0) {
						return "'0000-00-00 00:00:00'";
					}

					return "'".date('Y-m-d H:i:s', strtotime($value))."'";
				}

				/* Der Wert wird umgewandelt in einen Zeitstempel und dann als normaler Text weiter verarbeitet */
				break;

			case 'time':		// Wenn nur eines Uhrzeit erwartet wird
				if(is_int($value))
				{
					if($value > 0)
						return "'".date('H:i:s', $value)."'";
						else
							return "'00:00:00'";
				}
				else
					return "'".date('H:i:s', strtotime($value))."'";
					/* Der Wert wird umgewandelt in einen Zeitstempel und dann als normaler Text weiter verarbeitet */
					break;


			case '':
			case dbText:
			default:
				static::connect();
				return "'" . mysqli_real_escape_string($this->db, $value) . "'";
				break;
		}
	}

	
	protected function getCacheKey()
	{
		$args = serialize(func_get_args());

		return 'MySqlDb '.$this->db_host.':'.$this->db_port.' '.$this->db_database.' '.$this->table_prefix.' '.$args;
	}



}


final class MySqlDbStatic
{
	/** @var MySqlDb $db_instance */
	protected static $db_instance;


	private function __construct(){}
	private function __clone(){}


	/**
	 * @see	MySqlDb::__construct()
	 */
	static function connect($host, $port, $user, $password, $database, array $options = [])
	{
		static::$db_instance = new MySqlDb($host, $port, $user, $password, $database, $options);
	}


	/**
	 * @param	void
	 * @return 	MySqlDb
	 */
	static function getDbInstance()
	{
		if(static::$db_instance === null)
		{
			trigger_error('There is no static instance connected yet', E_USER_ERROR);
		}

		return static::$db_instance;
	}


	/**
	 * @see	MySqlDb::select()
	 */
	public static function select($table, array $fields = [], array $filter = null, array $sort = null, array $limit = null, array $options = [])
	{
		return static::getDbInstance()->select($table, $fields, $filter, $sort, $limit, $options);
	}
	
	
	public static function insert($table, array $set_array, array $options = [])
	{
		return static::getDbInstance()->insert($table, $set_array, $options);
	}
	
	public static function replace($table, array $set_array, array $options = [])
	{
		return static::getDbInstance()->replace($table, $set_array, $options);
	}

	public static function update($table, array $filter, array $set_array, array $options = [])
	{
		return static::getDbInstance()->update($table, $filter, $set_array, $options);
	}
	
	public static function delete($table, array $filter, array $options = [])
	{
		return static::getDbInstance()->delete($table, $filter, $options);
	}
}


final class MySqlDbException extends \Exception
{}