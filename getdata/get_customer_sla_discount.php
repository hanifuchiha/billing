<?php
/**
 * ===================================================================
 * SLA DISCOUNT API ENDPOINT
 * ===================================================================
 * Purpose: JSON API to retrieve SLA discount data for customers
 * Usage: GET /crm/billing/getdata/get_customer_sla_discount.php?idpel=...&pemilik=...&amount=...
 * ===================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/sla_discount_helper.php';

$response = [
    'success' => false,
    'data' => null,
    'error' => null
];

try {
    // Validate required parameters
    $idpel = isset($_GET['idpel']) ? trim((string)$_GET['idpel']) : '';
    $pemilik = isset($_GET['pemilik']) ? trim((string)$_GET['pemilik']) : '';
    $amount = isset($_GET['amount']) ? (float)$_GET['amount'] : 0;

    if (empty($idpel) || empty($pemilik) || $amount <= 0) {
        throw new Exception('Missing required parameters: idpel, pemilik, amount');
    }

    // Check if feature is enabled
    if (!slaDiscountIsEnabled()) {
        $response['data'] = [
            'has_discount' => false,
            'reason' => 'SLA discount feature is disabled'
        ];
        $response['success'] = true;
        echo json_encode($response);
        exit;
    }

    // Calculate discount
    $breakdown = calculateInvoiceWithSlaDiscount($conn, $idpel, $pemilik, $amount);

    $response['data'] = $breakdown;
    $response['success'] = true;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
