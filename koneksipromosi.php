<?php


// Simpan konfigurasi
$config_file = __DIR__ . '/config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];




$servername = $config['db_host_promosi'];
$username_db = $config['db_user_promosi'];
$password_db = $config['db_pass_promosi'];
$database = $config['db_name_promosi'];



// Create connection

$conn = mysqli_connect($servername, $username_db, $password_db, $database);
