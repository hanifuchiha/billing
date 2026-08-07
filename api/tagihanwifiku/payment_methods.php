<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

$pemilik = (string)($pelanggan['PEMILIK'] ?? '');
$defaultPayment = twk_get_default_payment_method($conn, $pemilik);

$tripayCfg = twk_get_tripay_config($conn, $pemilik);
$tripayApiKey = twk_pick((array)$tripayCfg, ['apikey', 'api_key']);
$tripayPrivateKey = twk_pick((array)$tripayCfg, ['privatekey', 'private_key']);
$tripayMerchant = twk_pick((array)$tripayCfg, ['merchant', 'merchant_code']);
$tripayReady = ($tripayApiKey !== '' && $tripayPrivateKey !== '' && $tripayMerchant !== '');

$duitkuCfg = twk_get_duitku_config($conn, $pemilik);
$duitkuReady = (
    twk_pick((array)$duitkuCfg, ['merchant_code', 'merchant']) !== ''
    && twk_pick((array)$duitkuCfg, ['api_key', 'apikey']) !== ''
);

$tripayMethods = [];
if ($tripayReady) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://tripay.co.id/api/merchant/payment-channel',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $tripayApiKey
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if (!$error && !empty($raw)) {
        $json = json_decode($raw, true);
        $rows = $json['data'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $feeCustomerFlat = (float)($row['fee_customer']['flat'] ?? 0);
                $feeCustomerPercent = (float)($row['fee_customer']['percent'] ?? 0);
                $totalFeeFlat = (float)($row['total_fee']['flat'] ?? 0);
                $totalFeePercent = (float)($row['total_fee']['percent'] ?? 0);
                $tripayMethods[] = [
                    'code' => (string)($row['code'] ?? ''),
                    'name' => (string)($row['name'] ?? ''),
                    'group' => (string)($row['group'] ?? ''),
                    'active' => (bool)($row['active'] ?? false),
                    'fee_customer' => $feeCustomerFlat,
                    'fee_customer_flat' => $feeCustomerFlat,
                    'fee_customer_percent' => $feeCustomerPercent,
                    'fee_total_flat' => $totalFeeFlat,
                    'fee_total_percent' => $totalFeePercent,
                    'minimum_fee' => (float)($row['minimum_fee'] ?? 0),
                    'maximum_fee' => (float)($row['maximum_fee'] ?? 0)
                ];
            }
        }
    }
}

$duitkuMethods = [
    ['code' => 'VA', 'name' => 'Virtual Account'],
    ['code' => 'OV', 'name' => 'OVO'],
    ['code' => 'GP', 'name' => 'GoPay'],
    ['code' => 'DA', 'name' => 'DANA'],
    ['code' => 'SP', 'name' => 'ShopeePay'],
    ['code' => 'LK', 'name' => 'LinkAja'],
    ['code' => 'BC', 'name' => 'BCA Virtual Account'],
    ['code' => 'BN', 'name' => 'BNI Virtual Account'],
    ['code' => 'BR', 'name' => 'BRI Virtual Account'],
    ['code' => 'MD', 'name' => 'Mandiri Virtual Account'],
    ['code' => 'CC', 'name' => 'Credit Card'],
    ['code' => 'AL', 'name' => 'Alfamart'],
    ['code' => 'A1', 'name' => 'ATM Bersama']
];

$manualAccounts = twk_get_manual_accounts($conn, $pemilik);
$manualAccounts = array_values(array_filter($manualAccounts, static function ($row): bool {
    if (!is_array($row)) {
        return false;
    }
    $rekening = trim((string)($row['rekening'] ?? ''));
    $metode = trim((string)($row['metode'] ?? ''));
    return $rekening !== '' || $metode !== '';
}));
$manualEnabled = count($manualAccounts) > 0;

$gateways = [
    [
        'code' => 'tripay',
        'name' => 'Tripay Payment Gateway',
        'enabled' => $tripayReady,
        'config_label' => 'Tripay Config',
        'methods' => $tripayMethods
    ],
    [
        'code' => 'duitku',
        'name' => 'Duitku Payment Gateway',
        'enabled' => $duitkuReady,
        'config_label' => 'Duitku Config',
        'methods' => $duitkuReady ? $duitkuMethods : []
    ],
    [
        'code' => 'midtrans',
        'name' => 'Midtrans Payment Gateway',
        'enabled' => false,
        'config_label' => 'Midtrans Config',
        'methods' => []
    ],
    [
        'code' => 'xendit',
        'name' => 'Xendit Payment Gateway',
        'enabled' => false,
        'config_label' => 'Xendit Config',
        'methods' => []
    ],
    [
        'code' => 'manual_bank',
        'name' => 'Transfer Bank Manual',
        'enabled' => $manualEnabled,
        'config_label' => 'Fixed Rate',
        'methods' => []
    ]
];

$enabledGatewayCodes = array_map(
    static fn(array $gateway): string => (bool)($gateway['enabled'] ?? false) ? (string)($gateway['code'] ?? '') : '',
    $gateways
);
$enabledGatewayCodes = array_values(array_filter($enabledGatewayCodes, static fn(string $code): bool => $code !== ''));

if (!in_array($defaultPayment, $enabledGatewayCodes, true)) {
    $defaultPayment = $enabledGatewayCodes[0] ?? 'manual_bank';
}

twk_response(200, [
    'success' => true,
    'message' => 'Daftar metode pembayaran berhasil diambil.',
    'data' => [
        'customer' => twk_customer_payload($pelanggan),
        'default_method' => $defaultPayment,
        'gateways' => $gateways,
        'manual_accounts' => $manualAccounts
    ]
]);
