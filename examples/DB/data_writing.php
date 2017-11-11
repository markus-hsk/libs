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

$db = new MySqlDb('localhost', '3306', 'root', '', 'test');


// Do an insert
echo "Inserted A ".$db->insert('test', ['data' => 'Insert', 'unique_field' => time()]) . PHP_EOL;
echo "Inserted B ".$db->insert('test', ['data' => 'Insert Ignore', 'unique_field' => 2], ['ignore_duplicate' => true]) . PHP_EOL;
echo "Inserted C ".$db->insert('test', ['data' => 'Insert Ignore', 'unique_field' => 2], ['ignore_duplicate' => true]) . PHP_EOL;
echo "Replaced ".$db->replace('test', ['data' => 'Replace', 'unique_field' => 1]) . PHP_EOL;


// Do updates
echo "Updated ".$db->update('test', ['data' => 'Insert'], ['data' => 'Updated']) . PHP_EOL;


// Do deletes
echo "Deleted ".$db->delete('test', []) . PHP_EOL;


var_dump($db->select('test', [], null, null, null));