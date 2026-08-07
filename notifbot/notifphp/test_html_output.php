<?php
/**
 * Test file untuk verifikasi HTML output
 */

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html lang='id'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Test HTML Output</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    <style>
        body { background-color: #f5f5f5; padding: 30px; }
        .test-box { background-color: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 30px; max-width: 800px; margin: auto; }
        h1 { color: #0d6efd; margin-bottom: 20px; }
        .alert-success { margin-top: 20px; }
    </style>
</head>
<body>
<div class='test-box'>
    <h1>✅ HTML Output Bekerja!</h1>
    <p>Jika Anda melihat pesan ini dengan styling Bootstrap, berarti HTML output sudah bekerja dengan benar.</p>
    <div class='alert alert-success'>
        <strong>PHP SAPI:</strong> " . PHP_SAPI . "
    </div>
    <div class='alert alert-info'>
        <strong>Server Time:</strong> " . date('Y-m-d H:i:s') . "
    </div>
</div>
</body>
</html>";
?>
