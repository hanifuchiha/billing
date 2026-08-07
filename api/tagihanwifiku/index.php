<?php
require_once __DIR__ . '/common.php';

$base = '/crm/billing/api/tagihanwifiku';

$endpoints = [
    'POST ' . $base . '/login_otp_request.php',
    'POST ' . $base . '/login_otp_verify.php',
    'POST ' . $base . '/login_password.php',
    'GET  ' . $base . '/me.php',
    'GET  ' . $base . '/billing.php',
    'GET  ' . $base . '/payment_methods.php',
    'GET  ' . $base . '/payment_status.php',
    'POST ' . $base . '/payment_create.php',
    'POST ' . $base . '/payment_cancel.php',
    'GET  ' . $base . '/history.php',
    'GET  ' . $base . '/chat_messages.php',
    'POST ' . $base . '/chat_send.php',
    'GET  ' . $base . '/wifi_status.php',
    'POST ' . $base . '/wifi_save.php',
    'GET  ' . $base . '/complaint_history.php',
    'POST ' . $base . '/complaint_create.php',
    'POST ' . $base . '/profile_update.php',
    'POST ' . $base . '/logout.php'
];

twk_response(200, [
    'success' => true,
    'app' => 'Tagihan Wifiku API',
    'version' => '1.0.0',
    'endpoints' => $endpoints
]);
