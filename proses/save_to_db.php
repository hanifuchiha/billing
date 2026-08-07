<?php
require '../cek-sesi.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get POST data
$botname = $_POST['botname'];
$area = $_POST['area'];
$server = $_POST['server'];

// Cek apakah botname sudah ada di database
$check_stmt = $conn->prepare("SELECT COUNT(*) FROM `botwa` WHERE `namebot` = ?");
$check_stmt->bind_param("s", $botname);
$check_stmt->execute();
$check_stmt->bind_result($count);
$check_stmt->fetch();
$check_stmt->close();

if ($count > 0) {
    echo "Botname sudah ada, tidak perlu ditambahkan.";
} else {
    // Bot yang dibuat oleh ASSISTANT ditandai created_by_assistant -- otomatis
    // jadi PRIVATE (lihat notifbot/bot_access_helper.php).
    require_once '../notifbot/bot_access_helper.php';
    botAccessEnsureColumns($conn);
    $botCreatedByAssistant = ($AKSES === 'ASSISTANT') ? (string)($asistant_name ?? '') : '';

    // Jika botname belum ada, lakukan INSERT
    $stmt = $conn->prepare("INSERT INTO `botwa` (`namebot`, `area`, `pemilik`, `created_by_assistant`) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $botname, $area, $server, $botCreatedByAssistant);

    if ($stmt->execute()) {
        echo "Data has been saved successfully!";
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
}

// Close connection
$conn->close();
