<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: tiket_manager.php');
    exit;
}

require __DIR__ . '/tiket_manager.php';
