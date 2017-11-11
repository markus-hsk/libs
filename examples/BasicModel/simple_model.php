<?php

namespace MBuscher;

// define your database configuration here:

$host 	  = 'localhost';
$port 	  = 3306;
$user 	  = 'root';
$password = '';
$database = 'test';


// @todo create table


// load all classes
require __DIR__.'/../../autoload.php';

MySqlDbStatic::connect($host, $port, $user, $password, $database);


class ExampleUser	extends BasicModel
{
	protected static $db_fields = array(
			'user_id'	=> ['type' => 'int', 	'insert' => false],
			'name'		=> ['type' => 'varchar'],
			'age'		=> ['type' => 'int']
	);
	
	
	
	
	
	
	public static function getIdFieldname()
	{
		return 'user_id';
	}
	
	public static function getDbTablename()
	{
		return 'user';
	}
}





$example_user = ExampleUser::create(['name' => 'A User', 'age' => 23]);

$example_user->update(['name' => 'A special user', 'age' => 24]);

echo $example_user;

$example_user->delete();