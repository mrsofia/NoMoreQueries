<?php
	
	/*
		dbConsole functions as a console for the tableWrapper. 
			Use it for testing and development. 
	*/
	
	include "/tableWrapper.php";
	
	$db = new mysqli('your_host', 'your_username', 'your_password', 'your_database');
	$table = "your_table";
	
	$users = new tableWrapper($db, $table);

?>