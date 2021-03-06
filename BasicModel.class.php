<?php

namespace MBuscher;


abstract class BasicModel
{
	// ##### static variables ################################################################################
	
	/** @var array $db_fields defines the fieldnames and options of the object */
	protected static $db_fields = null;
	
	/** @var array $unique_indexes defines fieldnames, whose combinition build unique indexes to validate */
	protected static $unique_indexes = null;
	
	/** @var int $transaction_semaphor a count to start the transaction only once and end it on the last point */
	private static $transaction_semaphor = 0;
	
	
	// ##### instance variables ##############################################################################
	
	/** @var array $data_array holds all the data of the instance */
	private $data_array = null;
	
	protected $editable = true;
	
	
	// ##### error constants #################################################################################
	
	const ERROR_RECORD_NOT_FOUND		= -1;
	const ERROR_INSERT_FAILED			= -2;
	const ERROR_INSERT_FORBIDDEN		= -3;
	const ERROR_UPDATE_FAILED			= -4;
	const ERROR_UPDATE_FORBIDDEN		= -5;
	const ERROR_DELETE_FAILED			= -6;
	const ERROR_DELETE_FORBIDDEN		= -7;
	const ERROR_FIELD_UNKNOWN			= -8;
	const ERROR_FIELD_PROTECTED			= -9;
	const ERROR_MANDATORY_MISSING		= -10;
	const ERROR_UNIQUE_FIELD_DUPLICATE	= -11;
	
	
	
	// ##### magic methods ###################################################################################
	
	/**
	 * BasicModel constructor.
	 *
	 * @param   array $data_array Array of all the data, which the instance should take
	 * @access  protected
	 * @author  Markus Buscher
	 */
	protected function __construct(array $data_array = [])
	{
		static::getDbFields();
	
		$this->setDataArray($data_array);
	}
	
	
	/**
	 * Creates a 1:1 clone of used instance
	 *
	 * @param   void
	 * @access  protected
	 * @author  Markus Buscher
	 */
	protected function __clone()
	{
		
	}
	
	
	/**
	 * Returns the whole data as an array
	 *
	 * @param   void
	 * @return  array   Userdata
	 * @author  Markus Buscher
	 * @see     BasicModel::get()
	 */
	public function __get($field)
	{
		return $this->get($field);
	}
	
	
	/**
	 * Makes a update for the given field
	 *
	 * @param   string $field Fieldname
	 * @param   mixed $value The new value
	 * @return  bool    success
	 * @author  Markus Buscher
	 * @see     BasicModel::set()
	 */
	public function __set($field, $value)
	{
		return $this->set($field, $value);
	}
	
	
	/**
	 * Magic method which gets triggered on printing the instance
	 *
	 * @param   void
	 * @return  String
	 * @access  public
	 * @author  Markus Buscher
	 * @see     http://php.net/manual/de/language.oop5.magic.php#object.tostring
	 */
	public function __toString()
	{
		return get_called_class() . PHP_EOL . json_encode($this->getDataArray(), JSON_PRETTY_PRINT);
	}
	
	
	
	// ##### Instanzierungsmethoden ######################################################################################
	
	/**
	 * Loads a single instance by using the unique primary key field
	 *
	 * @param	string $id
	 * @return	\MBuscher\BasicModel
	 * @throws	\NotFoundException
	 */
	public static function load($id, array $options = [])
	{
		$id_field = static::getIdFieldname();
	
		if(!strlen($id_field))
		{
			trigger_error(get_called_class().' does not support direct loading by ID-Field', E_USER_ERROR);
		}
	
		return static::findOne($id_field, $id, $options);
	}
	
	
	/**
	 * finds a bunch of instances using the filter parameter
	 * 
	 * @param	array		$filter
	 * @param 	array		$sort
	 * @param 	array 		$limit
	 * @param 	array 		$options
	 * @return 	\MBuscher\BasicModel[]
	 */
	public static function find(array $filter, array $sort = null, array $limit = null, array $options = [])
	{
		$table = static::getDbTablename();
	
		$records = static::getDb()->select($table, [], $filter, $sort, $limit, $options);
	
		$instances = array();
		foreach($records as $record)
		{
			$instance = static::compose($record);
			$instance->editable = true;
			$instances[] = $instance;
		}
	
		return $instances;
	}
	
	
	/**
	 * Finds a single instance by a specific field
	 * 
	 * @param	string		$field
	 * @param	mixed		$value
	 * @param 	array		$options
	 * @return	\MBuscher\BasicModel
	 * @throws 	\Exception
	 */
	public static function findOne($field, $value, array $options = [])
	{
		$instances = static::find([$field => $value], null, [0,1], $options);
	
		if(count($instances) == 1)
		{
			return $instances[0];
		}
		else
		{
			throw new \Exception("An instance of ".get_called_class()." matching $field = $value could not be found", static::ERROR_RECORD_NOT_FOUND); // @todo use errorcodes
		}
	}
	
	
	/**
	 * Creates a instances without a connection to the database
	 * 
	 * @param	array	$data_array
	 * @return \MBuscher\BasicModel
	 */
	public static function compose($data_array)
	{
		$instance = new static($data_array);
		$instance->editable = false;
		
		return $instance;
	}
	
	
	/**
	 * Creates a new instances in the database
	 * 
	 * @param	arrray	$data_array
	 * @return	\MBuscher\BasicModel
	 */
	public static function create($data_array)
	{
		$instance = static::compose($data_array);
		$instance->editable = true;
		$instance->insert();
	
		return $instance;
	}
	
	
	/**
	 * Finds all instances in the database without any filtering
	 * 
	 * @param	array	$options
	 * @return 	\MBuscher\BasicModel[]
	 */
	public final static function all(array $options = [])
	{
		return static::find([], null, null, $options);
	}
	
	
	// ###### Datenbankinteraktion ##########################################################################################
	
	/**
	 * Writes the dataarray to the database
	 * 
	 * @return	boolean
	 * @throws 	\Exception
	 */
	protected function insert()
	{
		try
		{
			// check the insert right
			$right_granted = $this->isInsertAllowed();
			if ($right_granted == false)
			{
				throw new \Exception('Creation of a new resource is forbidden', static::ERROR_INSERT_FORBIDDEN);
			}
	
			$set_array = $this->data_array;
	
			// save the given set_array for afterInsert-Method
			$given_set_array = $set_array;
	
			// validate the set_array
			$valid = $this->validateInsert($set_array);
			if ($valid == false)
			{
				throw new \Exception('Insert validation failed', static::ERROR_INSERT_FAILED);
			}
	
			// activate the transaction
			$this->startTransaction();
	
			// do the insert
			$id = static::getDb()->insert(static::getDbTablename(), $set_array);
	
			if ($id > 0)
			{
				$new_instance = static::load($id, ['use_cache' => false]);
				$record_data = $new_instance->getDataArray();
				unset($new_instance);
	
				$this->setDataArray($record_data);
	
				static::setLastUpdate();
	
				// @todo Debugger::log('Insert ' . static::getTableName(), 'Insert into ' . static::getTableName() . ' ' . json_encode($set_array));
	
				$done = $this->afterInsert($given_set_array, $set_array);
	
				if ($done)
				{
					$this->commitTransaction();
	
					return true;
				}
			}
	
			throw new \Exception('Insert failed', static::ERROR_INSERT_FAILED);
		}
		catch (\Exception $e)
		{
			$this->rollbackTransaction();
	
			throw $e;
		}
	}
	
	/**
	 * Modifies an object in a the database
	 *
	 * @param   array $set_array Array of fields to modify
	 * @return  boolean
	 * @author  Markus Buscher
	 * @throws  \Exception
	 */
	public function update(array $set_array)
	{
		try
		{
			// check the update right
			$right_granted = $this->isUpdateAllowed();
			if ($right_granted == false)
			{
				throw new \Exception('Update of the resource is forbidden', static::ERROR_UPDATE_FORBIDDEN);
			}
	
			// save the given set_array for afterInsert-Method
			$given_set_array = $set_array;
	
			// validate the set_array
			$valid = $this->validateUpdate($set_array);
			if ($valid == false)
			{
				throw new \Exception('Update validation failed', static::ERROR_UPDATE_FAILED);
			}
	
			$old_data_array = $this->getDataArray();
	
			// activate the transaction
			$this->startTransaction();
	
			// remove all fields, which are not part of the database
			$update_array = array_intersect_key($set_array, static::getDbFields());
			
			// @todo only update fields, which are different
	
			$id_field = static::getIdFieldname();
			$result = static::getDb()->update(static::getDbTablename(), [$id_field => $this->id()], $update_array);
	
			if ($result == 1)
			{
				$this->reload();
	
				static::setLastUpdate();
	
				// @todo Debugger::log('Update ' . static::getTableName(), 'Update ' . static::getTableName() . ' #' . $this->get('_id') . ' ' . json_encode($set_array));
	
				$done = $this->afterUpdate($given_set_array, $set_array, $old_data_array);
	
				if ($done)
				{
					$this->commitTransaction();
	
					return true;
				}
			}
	
			throw new \Exception('Update failed', static::ERROR_UPDATE_FAILED);
		}
		catch (\Exception $e)
		{
			$this->rollbackTransaction();
	
			throw $e;
		}
	}
	
	
	/**
	 * Deletes an instance from the database
	 * 
	 * @return boolean
	 * @throws \Exception
	 */
	public function delete()
	{
		try
		{
			// check the update right
			$right_granted = $this->isDeleteAllowed();
			if ($right_granted == false)
			{
				throw new \Exception('Deletion of the instance is forbidden', static::ERROR_DELETE_FORBIDDEN);
			}
	
			// activate the transaction
			$this->startTransaction();
	
			$id_field = static::getIdFieldname();
			$result = static::getDb()->delete(static::getDbTablename(), [$id_field => $this->id()]);
	
			if ($result == 1)
			{
				static::setLastUpdate();
	
				// @todo Debugger::log('Delete ' . static::getTableName(), 'Delete ' . static::getTableName() . ' #' . $this->get('_id'));
	
				$done = $this->afterDelete();
	
				if ($done)
				{
					$this->commitTransaction();
					$this->editable = false;
					$this->data_array[$id_field] = '';
	
					return true;
				}
			}
	
			throw new Exception('Delete failed', static::ERROR_DELETE_FAILED);
		}
		catch (\Exception $e)
		{
			$this->rollbackTransaction();
	
			throw $e;
		}
	}
	
	
	/**
	 * Starts a transaction
	 *
	 * @param   void
	 * @return  void
	 * @author  Markus Buscher
	 */
	protected function startTransaction()
	{
		if (self::$transaction_semaphor == 0)
		{
			// @todo static::getDb()->startTransaction();
		}
	
		self::$transaction_semaphor++;
	}
	
	
	/**
	 * Commits a transaction
	 *
	 * @param   void
	 * @return  void
	 * @author  Markus Buscher
	 * @throws	\Exception
	 */
	protected function commitTransaction()
	{
		self::$transaction_semaphor--;
		
		if (self::$transaction_semaphor == 0)
		{
			$this->beforeCommitTransaction();
				
			// @todo static::getDb()->commitTransaction();
		}
	}
	
	
	/**
	 * Runs action which are held back until the commit should be startet
	 *
	 * @param   void
	 * @return  void
	 * @author  Markus Buscher
	 */
	protected function beforeCommitTransaction()
	{
	
	}
	
	
	/**
	 * Rolls a transaction back because of some error or exceptions
	 *
	 * @param   void
	 * @return  void
	 * @author  Markus Buscher
	 */
	protected function rollbackTransaction()
	{
		if (self::$transaction_semaphor > 0)
		{
			// @todo static::getDb()->rollbackTransaction();
		}
	
		self::$transaction_semaphor = 0;
	}
	
	
	// ##### Interaktionshandler ##########################################################################################
	
	
	/** 
	 * Is insert on this instance allowed
	 * 
	 * @return boolean
	 */
	protected function isInsertAllowed()
	{
		return $this->editable;
	}
	
	
	/**
	 * Validates the given set_array and removes all fields, which are not available in database
	 *
	 * @param   array $set_array array with all fields and values to set on insert
	 * @return  bool validation result, false stops the insert
	 * @author  Markus Buscher
	 * @throws  \Exception
	 */
	protected function validateInsert(array &$set_array)
	{
		$db_fields = static::getDbFields();
		
		// check the given fields
		foreach ($set_array as $key => $set_value)
		{
			$e_msg_unknown = "Field $key is unknown";
			$e_msg_protected = "Field $key is protected";
	
			if (!array_key_exists($key, $db_fields))
			{
				$result = $this->onInsertFieldUnknown($key, $set_value);
	
				if ($result == false)
				{
					throw new \Exception($e_msg_unknown, static::ERROR_FIELD_UNKNOWN);
				}
			}
			else if (static::getDbFieldConfig($key, 'insert') == false)
			{
				$result = $this->onInsertFieldProtected($key, $set_value);
	
				if ($result == false)
				{
					if (static::getDbFieldConfig($key, 'select') == true)
					{
						throw new \Exception($e_msg_protected, static::ERROR_FIELD_PROTECTED);
					}
					else
					{
						throw new \Exception($e_msg_unknown, static::ERROR_FIELD_UNKNOWN);
					}
				}
			}
		}
	
		$valid_set_array = array();
	
		foreach (static::$db_fields as $key => $options)
		{
			if (static::getDbFieldConfig($key, 'insert') == true)
			{
				if (isset($set_array[$key]))
				{
					if (!strlen($set_array[$key]) && static::getDbFieldConfig($key, 'mandatory'))
					{
						$result = $this->onInsertMandatoryFieldMissing($key);
	
						if ($result == false)
						{
							throw new \Exception("Missing mandatory field $key", static::ERROR_MANDATORY_MISSING);
						}
						else
						{
							$set_array[$key] = '';
						}
					}
	
					// typecast value
					$datatype = static::getDbFieldConfig($key, 'type');
					$value = static::typecastValue($set_array[$key], $datatype);
	
					// check unique field
					if (static::getDbFieldConfig($key, 'unique'))
					{
						$instances = static::find([$key => $value]);
						if (count($instances) > 0)
						{
							/** @var BasicModel[] $instances */
							$exception_message = "$key have to be unique. Another instance (ID: " .
							$instances[0]->get('_id') . ") already uses the given value \"" .
							$value . "\".";
							throw new \Exception($exception_message, static::ERROR_UNIQUE_FIELD_DUPLICATE);
						}
					}
	
					$valid_set_array[$key] = $value;
				}
				else if (static::getDbFieldConfig($key, 'mandatory'))
				{
					throw new \Exception("Missing mandatory field $key", static::ERROR_MANDATORY_MISSING);
				}
				else
				{
					$valid_set_array[$key] = static::getDbFieldConfig($key, 'default');
				}
			}
		}
	
		// Check unique indexes
		if (is_array(static::$unique_indexes))
		{
			foreach (static::$unique_indexes as $fields)
			{
				$filter = array();
				foreach ($fields as $field)
				{
					$filter[$field] = $valid_set_array[$field];
				}
	
				$instances = static::find($filter);
				if (count($instances) > 0)
				{
					/** @var BasicModel[] $instances */
					$exception_message = implode(' & ', $fields) . " have to be a unique combination. " .
							"Another instance (ID: " . $instances[0]->get('_id') . ") already uses the given " .
							"combination \"" . implode(' & ', array_values($filter)) . "\".";
					throw new \Exception($exception_message, static::ERROR_UNIQUE_FIELD_DUPLICATE);
				}
			}
		}
	
		$set_array = $valid_set_array;
	
		// set default values for special fields
		if (array_key_exists('insert_ts', static::$db_fields))
		{
			$set_array['insert_ts'] = gmdate('Y-m-d H:i:s');
		}
	
		if (array_key_exists('update_ts', static::$db_fields))
		{
			$set_array['update_ts'] = gmdate('Y-m-d H:i:s');
		}
	
		if (array_key_exists('deleted', static::$db_fields))
		{
			$set_array['deleted'] = 0;
		}
	
		if (array_key_exists('delete_ts', static::$db_fields))
		{
			$set_array['delete_ts'] = '0000-00-00 00:00:00';
		}
	
		return true;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on unknown fields within the
	 * insert process.
	 *
	 * @param   string $fieldname the name of the unknown field
	 * @param   mixed $set_value the value to write
	 * @return  bool    false => throw Exception, true everything is alright with this field
	 * @author  Markus Buscher
	 */
	protected function onInsertFieldUnknown($fieldname, $set_value)
	{
		return false;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on protected fields within the
	 * insert process.
	 *
	 * @param   string $fieldname the name of the protected field
	 * @param   mixed $set_value the value to write
	 * @return  bool    false => throw Exception, true everything is alright with this field
	 * @author  Markus Buscher
	 */
	protected function onInsertFieldProtected($fieldname, $set_value)
	{
		return false;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on empty or missing mandatory
	 * fields within the insert process.
	 *
	 * @param   string $fieldname the name of the protected field
	 * @return  bool    false => throw Exception, true everything is alright
	 * @author  Markus Buscher
	 */
	protected function onInsertMandatoryFieldMissing($fieldname)
	{
		return false;
	}
	
	
	/**
	 * Predefined afterInsert Method to overwrite within an inheriting child class
	 *
	 * @param   array $given_set_array the given array when calling the insert-method
	 * @param   array $executed_set_array the array that was used to make the database insert
	 * @return  bool    everything alright
	 * @author  Markus Buscher
	 */
	protected function afterInsert(array $given_set_array, array $executed_set_array)
	{
		return true;
	}
	
	
	/**
	 * Is updation of this instance is allowed
	 * 
	 * @return boolean
	 */
	protected function isUpdateAllowed()
	{
		return $this->editable;
	}
	
	
	/**
	 * Validates the given set_array and removes all fields, which are not available in database
	 *
	 * @param   array $set_array array with all fields and values to set on update
	 * @return  bool validation result, false stops the update
	 * @author  Markus Buscher
	 * @throws  \Exception
	 */
	protected function validateUpdate(array &$set_array)
	{
		$db_fields = static::getDbFields();
	
		// First check, whether the deleted flag should be set. If yes, then verify the right to delete
		if (isset($set_array['deleted']) && $set_array['deleted'] == 1)
		{
			$allowed = $this->isDeleteAllowed();
			if (!$allowed)
			{
				throw new \Exception('Deletion of the resource is forbidden', static::ERROR_DELETE_FORBIDDEN);
			}
		}
	
		// check the given fields
		foreach ($set_array as $key => $set_value)
		{
			$e_msg_unknown = "Field $key is unknown";
			$e_msg_protected = "Field $key is protected";
	
			if (!array_key_exists($key, $db_fields))
			{
				$result = $this->onUpdateFieldUnknown($key, $set_value);
	
				if ($result == false)
				{
					throw new \Exception($e_msg_unknown, static::ERROR_FIELD_UNKNOWN);
				}
			}
			else if (static::getDbFieldConfig($key, 'update') == false)
			{
				$result = $this->onUpdateFieldProtected($key, $set_value);
	
				if ($result == false)
				{
					if (static::getDbFieldConfig($key, 'select') == true)
					{
						throw new \Exception($e_msg_protected, static::ERROR_FIELD_PROTECTED);
					}
					else
					{
						throw new \Exception($e_msg_unknown, static::ERROR_FIELD_UNKNOWN);
					}
				}
			}
		}
	
		$valid_set_array = array();
	
		foreach (static::$db_fields as $key => $options)
		{
			if (isset($set_array[$key]) && $set_array[$key] != $this->get($key))
			{
				if (!strlen($set_array[$key]) && static::getDbFieldConfig($key, 'mandatory'))
				{
					$result = $this->onUpdateMandatoryFieldMissing($key);
					if (!$result)
					{
						throw new \Exception("Field $key is mandatory", static::ERROR_MANDATORY_MISSING);
					}
					else
					{
						$set_array[$key] = '';
					}
				}
	
				// Typecast values
				$datatype = static::getDbFieldConfig($key, 'type');
				$value = static::typecastValue($set_array[$key], $datatype);
	
				// check unique fields
				if (static::getDbFieldConfig($key, 'unique'))
				{
					$instances = static::find([$key => $value]);
					if (count($instances) > 0)
					{
						/** @var BasicModel[] $instances */
						$exception_message = "$key have to be unique. Another instance (ID: " .
						$instances[0]->get('_id') . ") already uses the given value \"" .
						$value . "\".";
						throw new \Exception($exception_message, static::ERROR_UNIQUE_FIELD_DUPLICATE);
					}
				}
	
				$valid_set_array[$key] = $value;
			}
		}
	
		// Check unique indexes
		if (is_array(static::$unique_indexes))
		{
			foreach (static::$unique_indexes as $fields)
			{
				$filter = array();
				$check = false;
	
				foreach ($fields as $field)
				{
					if (isset($valid_set_array[$field]))
					{
						$check = true;
						$filter[$field] = $valid_set_array[$field];
					}
					else
					{
						$filter[$field] = $this->get($field);
					}
				}
	
				if (!$check)
				{
					continue;
				}
	
				$instances = static::find($filter);
				if (count($instances) > 0)
				{
					/** @var BasicModel[] $instances */
					$exception_message = implode(' & ', $fields) . " have to be a unique combination. " .
							"Another instance (ID: " . $instances[0]->get('_id') . ") already uses the given " .
							"combination \"" . implode(' & ', array_values($filter)) . "\".";
					throw new \Exception($exception_message, static::ERROR_UNIQUE_FIELD_DUPLICATE);
				}
			}
		}
	
		// automatically set delete_ts then the deleted flag is set to 1
		if (isset($valid_set_array['deleted']) && $valid_set_array['deleted'] == 1)
		{
			$valid_set_array['delete_ts'] = gmdate('Y-m-d H:i:s');
		}
	
		$set_array = $valid_set_array;
	
		if (array_key_exists('update_ts', static::$db_fields))
		{
			$set_array['update_ts'] = gmdate('Y-m-d H:i:s');
		}
	
		return true;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on unknown fields within the
	 * update process.
	 *
	 * @param   string $fieldname the name of the unknown field
	 * @param   mixed $set_value the value to write
	 * @return  bool    false => throw Exception, true everything is alright with this field
	 * @author  Markus Buscher
	 */
	protected function onUpdateFieldUnknown($fieldname, $set_value)
	{
		return false;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on protected fields within the
	 * update process.
	 *
	 * @param   string $fieldname the name of the protected field
	 * @param   mixed $set_value the value to write
	 * @return  bool    false => throw Exception, true everything is alright with this field
	 * @author  Markus Buscher
	 */
	protected function onUpdateFieldProtected($fieldname, $set_value)
	{
		return false;
	}
	
	
	/**
	 * Predefined Method to overwrite within an inheriting child class to react on empty or missing mandatory
	 * fields within the update process.
	 *
	 * @param   string $fieldname the name of the protected field
	 * @return  bool    false => throw Exception, true everything is alright
	 * @author  Markus Buscher
	 */
	protected function onUpdateMandatoryFieldMissing($fieldname)
	{
		return false;
	}
	
	
	/**
	 * Predefined afterUpdate Method to overwrite within an inheriting child class
	 *
	 * @param   array $given_set_array the given array when calling the update-method
	 * @param   array $executed_set_array the array that was used to make the database update
	 * @param   array $old_data_array the data array of the instance before the update
	 * @return  bool    everything alright
	 * @author  Markus Buscher
	 */
	protected function afterUpdate(array $given_set_array, array $executed_set_array, array $old_data_array)
	{
		return true;
	}
	
	
	/**
	 * Is deletion of this instances allowed
	 * 
	 * @return boolean
	 */
	protected function isDeleteAllowed()
	{
		return $this->editable;
	}
	
	
	/**
	 * Predefined afterDelete Method to overwrite within an inheriting child class
	 *
	 * @return  boolean    everything alright
	 */
	protected function afterDelete()
	{
		return true;
	}
	
	
	
	// ##### Instanzfunktionen ############################################################################################
	
	/**
	 * Put the given data array into this instance
	 * 
	 * @param array $data_array
	 */
	protected function setDataArray(array $data_array)
	{
		$this->data_array = $data_array;
	}
	
	
	/**
	 * Returns the whole data as an array
	 *
	 * @param   bool $hide_internals only public fields
	 * @param   array $fields only the given fields, if array is empty, all fields are returned
	 * @return  array   data
	 * @author  Markus Buscher
	 */
	public function getDataArray($hide_internals = false, array $fields = [])
	{
		if ($hide_internals == true)
		{
			$fieldnames = array_keys(static::getPublicDbFields());
		}
		else
		{
			$fieldnames = array_keys(static::getDbFields());
		}
	
		$data_array = array();
	
		foreach ($fieldnames as $fieldname)
		{
			if (count($fields) > 0 && !in_array($fieldname, $fields))
			{
				continue;
			}
	
			$data_array[$fieldname] = $this->get($fieldname);
		}
	
		if (count($fields) > 0)
		{
			$already_done = array_keys($data_array);
	
			foreach ($fields as $field)
			{
				if (!in_array($field, $already_done))
				{
					$data_array[$field] = $this->get($field);
				}
			}
		}
	
		return $data_array;
	}


	/**
	 * Returns the whole data with deep data structure as an array. Have to be inherited by child class to
	 * build full functionality.
	 *
	 * @param   bool $hide_internals only public fields
	 * @param   array $fields only the given fields, if array is empty, all fields are returned
	 * @return  array   data
	 * @author  Markus Buscher
	 */
	public function getDataArrayDeep($hide_internals = false, array $fields = [])
	{
		return $this->getDataArray($hide_internals, $fields);
	}
	
	
	/**
	 * Returns the value of the given field
	 * 
	 * @param	string		$field
	 * @return	mixed|NULL
	 */
	public function get($field)
	{
		// this is a alias for getting the ID-Field
		if($field == '_id' && !array_key_exists('_id', $this->data_array))
		{
			return $this->id();
		}
		else if (isset($this->data_array[$field]))
		{
			$datatype = static::getDbFieldConfig($field, 'type');
			
			return static::typecastValue($this->data_array[$field], $datatype);
		}
		// or return the value of a simple instance variable
		else if (property_exists($this, $field))
		{
			return $this->$field;
		}
		else
		{
			return null;
		}
	}
	
	
	/**
	 * Returns the id of this instance
	 * 
	 * @return mixed
	 */
	public function id()
	{
		$id_field = static::getIdFieldname();
	
		if(!strlen($id_field))
		{
			trigger_error(get_called_class().' does not support an ID-Field', E_USER_ERROR);
		}
	
		return $this->get($id_field);
	}
	
	
	/**
	 * Sets the value of a given field
	 * 
	 *  @param	string	$field
	 *  @param	mixed	$value
	 *  @return	boolean success
	 */
	public function set($field, $value)
	{
		// if it is meant to set an database field, then make the update, ...
		if (array_key_exists($field, static::$db_fields))
		{
			return $this->update([$field => $value]);
		}
		// else write the value to a simple instance variable
		else
		{
			return $this->$field = $value;
		}
	}
	
	
	/**
	 * Reloads the data of this instance from database 
	 * 
	 * @return	void
	 * @throws 	Exception
	 */
	public function reload()
	{
		try
		{
			$id = $this->id();
			
			$reloaded_instance = static::load($id, ['use_cache' => false]);
			$this->setDataArray($reloaded_instance->getDataArray());
		}
		catch(\Exception $e)
		{
			if($e->getCode() === static::ERROR_RECORD_NOT_FOUND)
			{
				$classname = get_called_class();
				throw new \Exception("$classname #$id could not be found in database anymore", static::ERROR_RECORD_NOT_FOUND);
			}
			else 
			{
				throw $e;
			}
		}
	}
	
	
	
	
	// ##### statische Methoden ############################################################################################
	
	/**
	 * Returns an instance of MySqlDb to use within this class
	 * 
	 * @return	\MBuscher\MySqlDb
	 */
	protected static function getDb()
	{
		return MySqlDbStatic::getDbInstance();
	}
	
	
	/**
	 * Typecasts the given value to the given datatype
	 * 
	 * @param	mixed	$value
	 * @param	string	$datatype
	 * @return 	mixed
	 * @throws 	\Exception
	 */
	protected static function typecastValue($value, $datatype)
	{
		switch($datatype)
		{
			case 'varchar':
			case 'char':
			case 'text':
			case 'longtext':
				return (string) $value;
				
			case 'int':
			case 'smallint':
			case 'bigint':
				return (int) $value;
				
			default:
				trigger_error("Datatype $datatype is not supported", E_USER_ERROR);
		}
	}
	
	
	/**
	 * Returns a timestamp of the latest known update directly from the Cache-Handler
	 *
	 * @param   void
	 * @static
	 * @return  int     UNIX-Timestamp
	 * @author  Markus Buscher
	 */
	public static function getLastUpdate()
	{
		$last_update = Cache::get(get_called_class() . '_latest_update');
	
		if ($last_update === false)
		{
			$last_update = time();
			static::setLastUpdate($last_update);
		}
	
		return $last_update;
	}
	
	
	/**
	 * Sets the timestamp of the latest update to an instance of this class to the Cache-Handler
	 *
	 * @param   int $timestamp UNIX-Timestamp
	 * @static
	 * @return  void
	 * @author  Markus Buscher
	 */
	public static function setLastUpdate($timestamp = 0)
	{
		if ($timestamp == 0)
		{
			$timestamp = time();
		}
	
		Cache::set(get_called_class() . '_latest_update', $timestamp);
	}
	
	
	/**
	 * Converts an array of BasicModel-instances to an whole array representation
	 *
	 * @param   BasicModel[] $instances array of BasicModel instances
	 * @param   bool $hide_internals should the internal fields be returned
	 * @param   array $fields which fields should be returned, empty means all
	 * @return  array   data
	 * @static
	 * @author Markus Buscher
	 */
	public static function instancesToArray(array $instances, $hide_internals = false, array $fields = [])
	{
		$current_object_type = get_called_class();
	
		$data_array = array();
		foreach ($instances as $instance)
		{
			if (!is_a($instance, $current_object_type))
			{
				trigger_error('object is not of type ' . $current_object_type . ', given ' . gettype($instance), E_USER_WARNING);
				
				continue;
			}
	
			/** @var BasicModel $instance */
			$data_array[] = $instance->getDataArrayDeep($hide_internals, $fields);
		}
	
		return $data_array;
	}
	
	
	/**
	 * Sorts an array of BasicModel-instances by the given fieldname in the given direction.
	 *
	 * @param   BasicModel[] $instances Instances of BasicModel
	 * @param   string $fieldname Fieldname to sort
	 * @param   int $sort_direction Direction to sort
	 * @static
	 * @return  BasicModel[]   sorted instances
	 * @author  Markus Buscher
	 */
	public static function sortInstances(array $instances, $fieldname, $sort_direction = SORT_ASC)
	{
		// creates an anonymous function which requests the given fieldname from the two instances and makes
		// the check which one is greater
		$sort_function = create_function('$a, $b', '
            $a = strtolower($a->get("' . $fieldname . '"));
            $b = strtolower($b->get("' . $fieldname . '"));
	
            if ($a == $b)
            {
                return 0;
            }
	
            return ($a ' . ($sort_direction == SORT_DESC ? '>' : '<') . ' $b) ? -1 : 1;
        ');
	
		usort($instances, $sort_function);
	
		return $instances;
	}
	
	
	/**
	 * Returns an indexed array of only the ids of the given instances
	 *
	 * @param   BasicModel[] $instances The instances array
	 * @static
	 * @return  array ids of all instances
	 * @author  Markus Buscher
	 */
	public static function getIdArrayFromInstances(array $instances)
	{
		return static::getArrayOfSingleFieldFromInstances($instances, '_id');
	}
	
	
	/**
	 * Returns an indexed array of only the values of the field of the given instances
	 *
	 * @param   BasicModel[] $instances The instances array
	 * @param   string $fieldname The fieldname to extract the values
	 * @static
	 * @return  array
	 * @author  Markus Buscher
	 */
	public static function getArrayOfSingleFieldFromInstances(array $instances, $fieldname)
	{
		$current_object_type = get_called_class();
	
		$fieldvalues = array();
		foreach ($instances as $instance)
		{
			if (!is_a($instance, $current_object_type))
			{
				trigger_error('object is not of type ' . $current_object_type . ', given ' . gettype($instance), E_USER_WARNING);
				
				continue;
			}
	
			/** @var BasicModel $instance */
			$fieldvalues[] = $instance->get($fieldname);
		}
	
		return $fieldvalues;
	}
	
	
	/**
	 * Returns an array of references to the instances array by key matching the values of the fieldname
	 *
	 * @param   BasicModel[] $instances The array of BasicModel instances
	 * @param   string $fieldname The fieldname for the references
	 * @static
	 * @return  BasicModel[]
	 * @author  Markus Buscher
	 */
	public static function getReferencesArrayOfSingleFieldFromInstances(array &$instances, $fieldname)
	{
		$current_object_type = get_called_class();
	
		$references = array();
		foreach ($instances as &$instance)
		{
			if (!is_a($instance, $current_object_type))
			{
				trigger_error('object is not of type ' . $current_object_type . ', given ' . gettype($instance), E_USER_WARNING);
				
				continue;
			}
	
			if (!array_key_exists($instance->get($fieldname), $references))
			{
				$references[$instance->get($fieldname)] = &$instance;
			}
			else
			{
				// @todo what should we do if there are more then one instance having the same value
			}
		}
	
		return $references;
	}
	
	
	/**
	 * Returns the db_fields Definition-Array
	 *
	 * @param   void
	 * @static
	 * @return  array $db_fields
	 * @author  Markus Buscher
	 */
	public static function getDbFields()
	{
		if (!isset(static::$db_fields) || !is_array(static::$db_fields))
		{
			// @todo implement autoload
			
			trigger_error('Missing mandatory static var $db_fields in ' . get_called_class(), E_USER_ERROR);
		}
		
		return static::$db_fields;
	}
	
	
	/**
	 * Returns the config for a given database field.
	 *
	 * @param   string $field Fieldname
	 * @param   string $config Configuration-Name
	 * @static
	 * @return  mixed
	 * @author  Markus Buscher
	 */
	public static function getDbFieldConfig($field, $config)
	{
		$db_fields = static::getDbFields();
		
		if (isset($db_fields[$field][$config]))
		{
			return $db_fields[$field][$config];
		}
		else
		{
			switch ($config)
			{
				case 'type':
					return 'text';
	
				case 'insert':
				case 'update':
				case 'select':
					return true;
	
				case 'mandatory':
				case 'unique':
					return false;
	
				case 'default':
					return '';
	
				case 'description':
				default:
					return null;
			}
		}
	}
	
	
	/**
	 * Returns the db_fields Definition-Array limited to the public visible fields
	 *
	 * @param   array $exclude_fields exclude fields from return
	 * @static
	 * @return  array   public $db_fields
	 * @author Markus Buscher
	 */
	public static function getPublicDbFields(array $exclude_fields = [])
	{
		$db_fields = static::getDbFields();
	
		foreach ($db_fields as $fieldname => $options)
		{
			if (in_array($fieldname, $exclude_fields) || (static::getDbFieldConfig($fieldname, 'select') == false))
			{
				unset($db_fields[$fieldname]);
			}
		}
	
		return $db_fields;
	}
	
	
	/**
	 * Returns the names of the public visible database fields
	 *
	 * @param   array $exclude_fields exclude fields from return
	 * @static
	 * @return  array   public $db_fields
	 * @author Markus Buscher
	 */
	public static function getPublicDbFieldnames(array $exclude_fields = [])
	{
		return array_keys(static::getPublicDbFields($exclude_fields));
	}
	
	
	
	// ##### abstrakte Methoden ############################################################################################
	
	abstract public static function getIdFieldname();
	
	abstract public static function getDbTablename();
}