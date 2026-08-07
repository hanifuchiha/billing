<?php
// Lokasi file konfigurasi
$config_file = __DIR__ . '/../config.json';


$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];




$servername = $config['db_host'];
$username_db = $config['db_user'];
$password_db = $config['db_pass'];
$database = $config['db_name'];


// Create connection

$conn = mysqli_connect($servername, $username_db, $password_db, $database);


?>





 
 

