<?php
/**
 * DEBUG SCRIPT: Test Pelanggan Menunggak API
 * Purpose: Analyze why API returns empty data
 * Date: 2026-05-12
 */

header('Content-Type: application/json; charset=utf-8');

require_once 'koneksibilling.php';

echo "=== PELANGGAN MENUNGGAK API DEBUG ===" . PHP_EOL . PHP_EOL;

// Test credentials
$test_username = "admin";  // Change if needed
$test_password = "admin";  // Change if needed

echo "1. DATABASE CONNECTION TEST" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;
if ($conn && !mysqli_connect_error()) {
    echo "✓ Database connected successfully" . PHP_EOL;
    echo "  Host: " . $GLOBALS['servername'] . PHP_EOL;
    echo "  Database: " . $GLOBALS['database'] . PHP_EOL;
} else {
    echo "✗ Database connection failed: " . mysqli_connect_error() . PHP_EOL;
    exit;
}

echo PHP_EOL . "2. TEST USER AUTHENTICATION" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$stmt = $conn->prepare("SELECT id, USERNAME, STATUS, grup, server FROM user WHERE USERNAME = ? LIMIT 1");
$stmt->bind_param('s', $test_username);
$stmt->execute();
$user_res = $stmt->get_result();

if ($user_res->num_rows > 0) {
    $user_row = $user_res->fetch_assoc();
    echo "✓ User found: " . $test_username . PHP_EOL;
    echo "  ID: " . $user_row['id'] . PHP_EOL;
    echo "  STATUS: " . $user_row['STATUS'] . PHP_EOL;
    echo "  GROUP: " . $user_row['grup'] . PHP_EOL;
    echo "  SERVER JSON: " . ($user_row['server'] ? substr($user_row['server'], 0, 100) . "..." : "empty") . PHP_EOL;
    
    $user_id = (int)$user_row['id'];
    $user_status = strtoupper(trim((string)($user_row['STATUS'] ?? '')));
    $user_group = trim((string)($user_row['grup'] ?? ''));
    $user_server_json = (string)($user_row['server'] ?? '');
    $owner_username = $test_username;
} else {
    echo "✗ User not found: " . $test_username . PHP_EOL;
    echo "  Available users:" . PHP_EOL;
    $all_users = mysqli_query($conn, "SELECT USERNAME, STATUS FROM user LIMIT 10");
    while ($u = mysqli_fetch_assoc($all_users)) {
        echo "    - " . $u['USERNAME'] . " (" . $u['STATUS'] . ")" . PHP_EOL;
    }
    exit;
}

echo PHP_EOL . "3. SCOPE RESOLUTION (Mimic API logic)" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$pemilik_values = [];
$area_values = [];

// Check if ASSISTANT
if ($user_status === 'ASSISTANT') {
    echo "User is ASSISTANT role" . PHP_EOL;
    $server_ids = json_decode($user_server_json, true);
    $server_ids = is_array($server_ids) ? array_values(array_filter(array_map('intval', $server_ids))) : [];
    echo "  Parsed server IDs: " . json_encode($server_ids) . PHP_EOL;
    
    if (!empty($server_ids)) {
        $id_in = implode(',', $server_ids);
        $res = mysqli_query($conn, "SELECT AREA FROM server WHERE id IN ($id_in)");
        while ($r = mysqli_fetch_assoc($res)) {
            if (!empty($r['AREA'])) {
                $area_values[] = $r['AREA'];
            }
        }
    }
    echo "  AREAs found: " . json_encode($area_values) . PHP_EOL;
    
    if (!empty($area_values)) {
        $area_escaped = implode("','", array_map(function($a) use ($conn) {
            return mysqli_real_escape_string($conn, $a);
        }, $area_values));
        $res = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ('$area_escaped')");
        while ($r = mysqli_fetch_assoc($res)) {
            if (!empty($r['PEMILIK'])) {
                $pemilik_values[] = $r['PEMILIK'];
            }
        }
    }
    echo "  PEMILIKs found: " . json_encode($pemilik_values) . PHP_EOL;
} else {
    echo "User is NOT ASSISTANT (Status: $user_status)" . PHP_EOL;
}

// Fallback 1
if (empty($pemilik_values) || empty($area_values)) {
    echo "Fallback 1: Query server by user_id=$user_id OR PEMILIK=$owner_username" . PHP_EOL;
    $stmt = $conn->prepare("SELECT PEMILIK, AREA FROM server WHERE user_id=? OR PEMILIK=?");
    $stmt->bind_param('is', $user_id, $owner_username);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($sr = $res->fetch_assoc()) {
        if (!empty($sr['PEMILIK'])) $pemilik_values[] = $sr['PEMILIK'];
        if (!empty($sr['AREA'])) $area_values[] = $sr['AREA'];
        $count++;
    }
    echo "  Found $count server records" . PHP_EOL;
    echo "  PEMILIKs: " . json_encode(array_unique($pemilik_values)) . PHP_EOL;
    echo "  AREAs: " . json_encode(array_unique($area_values)) . PHP_EOL;
}

// Fallback 2
if (empty($pemilik_values)) {
    echo "Fallback 2: Query server by PEMILIK=$owner_username" . PHP_EOL;
    $stmt = $conn->prepare("SELECT PEMILIK, AREA FROM server WHERE PEMILIK=?");
    $stmt->bind_param('s', $owner_username);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($sr = $res->fetch_assoc()) {
        if (!empty($sr['PEMILIK'])) $pemilik_values[] = $sr['PEMILIK'];
        if (!empty($sr['AREA'])) $area_values[] = $sr['AREA'];
        $count++;
    }
    echo "  Found $count server records" . PHP_EOL;
    echo "  PEMILIKs: " . json_encode(array_unique($pemilik_values)) . PHP_EOL;
}

// Fallback 3
if (empty($area_values)) {
    echo "Fallback 3: Query pelanggan AREA by PEMILIK=$owner_username" . PHP_EOL;
    $stmt = $conn->prepare("SELECT DISTINCT AREA FROM pelanggan WHERE PEMILIK=?");
    $stmt->bind_param('s', $owner_username);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    while ($ar = $res->fetch_assoc()) {
        if (!empty($ar['AREA'])) $area_values[] = $ar['AREA'];
        $count++;
    }
    echo "  Found $count distinct areas" . PHP_EOL;
    echo "  AREAs: " . json_encode(array_unique($area_values)) . PHP_EOL;
}

// Final
if (empty($pemilik_values)) {
    echo "Final Fallback: Using ownerUsername as default PEMILIK" . PHP_EOL;
    $pemilik_values[] = $owner_username;
}

$pemilik_values = array_unique($pemilik_values);
$area_values = array_unique($area_values);

echo "FINAL SCOPE:" . PHP_EOL;
echo "  PEMILIKs: " . json_encode($pemilik_values) . PHP_EOL;
echo "  AREAs: " . json_encode($area_values) . PHP_EOL;

echo PHP_EOL . "4. DATABASE TABLE COUNTS" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$tables_to_check = ['pelanggan', 'transaksi', 'paket', 'user', 'server'];
foreach ($tables_to_check as $table) {
    $count_query = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM $table");
    $count_row = mysqli_fetch_assoc($count_query);
    echo "  $table: " . $count_row['cnt'] . " records" . PHP_EOL;
}

echo PHP_EOL . "5. PELANGGAN RECORDS FOR THIS PEMILIK" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$pemilik_list = implode("','", array_map(function($p) use ($conn) {
    return mysqli_real_escape_string($conn, $p);
}, $pemilik_values));

$query = "SELECT COUNT(*) as cnt FROM pelanggan WHERE PEMILIK IN ('$pemilik_list')";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_assoc($result);
echo "  Total pelanggan for this PEMILIK: " . $row['cnt'] . PHP_EOL;

echo PHP_EOL . "6. SAMPLE PELANGGAN DATA (First 5)" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$query = "SELECT IDPEL, NAMA, PAKET, STATUS, AREA, PEMILIK, TIPE_BAYAR, TIPE_TEMPO, TANGGALPASANG, HARGA FROM pelanggan WHERE PEMILIK IN ('$pemilik_list') LIMIT 5";
$result = mysqli_query($conn, $query);
$sample_count = 0;
while ($p = mysqli_fetch_assoc($result)) {
    $sample_count++;
    echo "  $sample_count. ID: " . $p['IDPEL'] . ", Nama: " . $p['NAMA'] . ", Paket: " . $p['PAKET'] . ", Status: " . $p['STATUS'] . PHP_EOL;
    echo "     Tipe Bayar: " . $p['TIPE_BAYAR'] . ", Tanggal Pasang: " . $p['TANGGALPASANG'] . ", Harga: " . $p['HARGA'] . PHP_EOL;
}

echo PHP_EOL . "7. PAKET HARGA DATA" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$paket_query = mysqli_query($conn, "SELECT PAKET, HARGA, BRAND, AREA FROM paket LIMIT 10");
$paket_count = 0;
while ($p = mysqli_fetch_assoc($paket_query)) {
    $paket_count++;
    echo "  $paket_count. Paket: " . $p['PAKET'] . ", Harga: " . $p['HARGA'] . ", Brand: " . ($p['BRAND'] ?? '-') . ", Area: " . ($p['AREA'] ?? '-') . PHP_EOL;
}

echo PHP_EOL . "8. TRANSAKSI DATA (Last 5)" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$trx_query = mysqli_query($conn, "SELECT IDPEL, TANGGALBAYAR, STATUS FROM transaksi WHERE STATUS='BERHASIL' ORDER BY TANGGALBAYAR DESC LIMIT 5");
$trx_count = 0;
while ($t = mysqli_fetch_assoc($trx_query)) {
    $trx_count++;
    echo "  $trx_count. IDPEL: " . $t['IDPEL'] . ", Tgl Bayar: " . $t['TANGGALBAYAR'] . ", Status: " . $t['STATUS'] . PHP_EOL;
}

echo PHP_EOL . "9. TEST API CALL (WITH NOFILTER)" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$api_url = "http://localhost/crm/billing/api/pelanggan_menunggak.php?username=" . urlencode($test_username) . "&password=" . urlencode($test_password) . "&nofilter=1";
echo "Testing with nofilter=1 (returns ALL customers in scope, no filtering):" . PHP_EOL;

$response = @file_get_contents($api_url);
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data) {
        echo "  Success: " . ($data['success'] ? 'true' : 'false') . PHP_EOL;
        if (isset($data['data'])) {
            echo "  Data count: " . count($data['data']) . PHP_EOL;
            if (count($data['data']) > 0) {
                echo "  First record: " . json_encode($data['data'][0], JSON_PRETTY_PRINT) . PHP_EOL;
            }
        }
        if (isset($data['summary'])) {
            echo "  Summary: " . json_encode($data['summary']) . PHP_EOL;
        }
        if (isset($data['error'])) {
            echo "  Error: " . $data['error'] . PHP_EOL;
        }
    } else {
        echo "  Invalid JSON response" . PHP_EOL;
        echo "  Response: " . substr($response, 0, 500) . PHP_EOL;
    }
} else {
    echo "  ✗ Could not fetch API (file_get_contents failed)" . PHP_EOL;
    echo "  Try accessing directly: $api_url" . PHP_EOL;
}

echo PHP_EOL . "10. TEST API CALL (NORMAL MODE)" . PHP_EOL;
echo "-----------------------------------" . PHP_EOL;

$api_url_normal = "http://localhost/crm/billing/api/pelanggan_menunggak.php?username=" . urlencode($test_username) . "&password=" . urlencode($test_password);
echo "Testing with normal filtering:" . PHP_EOL;

$response = @file_get_contents($api_url_normal);
if ($response !== false) {
    $data = json_decode($response, true);
    if ($data) {
        echo "  Success: " . ($data['success'] ? 'true' : 'false') . PHP_EOL;
        if (isset($data['data'])) {
            echo "  Data count: " . count($data['data']) . PHP_EOL;
            if (count($data['data']) == 0 && isset($data['_debug'])) {
                echo "  DEBUG INFO: " . json_encode($data['_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            }
        }
        if (isset($data['summary'])) {
            echo "  Summary: " . json_encode($data['summary']) . PHP_EOL;
        }
    } else {
        echo "  Invalid JSON response" . PHP_EOL;
    }
} else {
    echo "  ✗ Could not fetch API" . PHP_EOL;
}

echo PHP_EOL . "=== DEBUG COMPLETE ===" . PHP_EOL;

mysqli_close($conn);
?>
