<?php
require_once __DIR__ . '/../koneksibilling.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/customer_sla_common.php';

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: text/plain; charset=utf-8');

if (!customerSlaEnsureTables($conn)) {
    http_response_code(500);
    echo "Gagal membuat table customer_sla_logs\n";
    exit;
}

$slot = customerSlaFloorSlot(time());
$checkedAt = date('Y-m-d H:i:s');

$sqlServers = "SELECT id, PEMILIK, AREA, IP, PASSWORD FROM server WHERE COALESCE(IP,'') <> '' ORDER BY PEMILIK ASC, AREA ASC, id ASC";
$resServers = mysqli_query($conn, $sqlServers);
if (!$resServers) {
    http_response_code(500);
    echo "Gagal mengambil server: " . mysqli_error($conn) . "\n";
    exit;
}

$totalServers = 0;
$totalCustomers = 0;
$onlineCustomers = 0;
$offlineCustomers = 0;

while ($server = mysqli_fetch_assoc($resServers)) {
    $totalServers++;

    $pemilik = customerSlaNormalizeText($server['PEMILIK'] ?? '');
    $area = customerSlaNormalizeText($server['AREA'] ?? '');
    $ip = customerSlaNormalizeText($server['IP'] ?? '');
    $password = (string)($server['PASSWORD'] ?? '');

    if ($pemilik === '' || $ip === '') {
        continue;
    }

    $activeNames = [];
    $responseMs = 0;

    $start = microtime(true);
    $api = new RouterosAPI();
    if ($api->connect($ip, $pemilik, $password)) {
        $pppActive = $api->comm('/ppp/active/print');
        if (is_array($pppActive)) {
            foreach ($pppActive as $row) {
                $name = customerSlaNormalizeText($row['name'] ?? '');
                if ($name !== '') {
                    $activeNames[$name] = true;
                }
            }
        }
        $api->disconnect();
    }
    $responseMs = max(1, (int)round((microtime(true) - $start) * 1000));

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    if ($area !== '') {
        $sqlCustomers = "SELECT IDPEL FROM pelanggan WHERE PEMILIK = '$pemilikEsc' AND AREA = '$areaEsc' AND COALESCE(IDPEL,'') <> '' ORDER BY IDPEL ASC";
    } else {
        $sqlCustomers = "SELECT IDPEL FROM pelanggan WHERE PEMILIK = '$pemilikEsc' AND COALESCE(IDPEL,'') <> '' ORDER BY IDPEL ASC";
    }

    $resCustomers = mysqli_query($conn, $sqlCustomers);
    if (!$resCustomers) {
        continue;
    }

    $rowsSql = [];
    while ($customer = mysqli_fetch_assoc($resCustomers)) {
        $idpel = customerSlaNormalizeText($customer['IDPEL'] ?? '');
        if ($idpel === '') {
            continue;
        }

        $totalCustomers++;
        $isOnline = isset($activeNames[$idpel]) ? 1 : 0;
        if ($isOnline === 1) {
            $onlineCustomers++;
        } else {
            $offlineCustomers++;
        }

        $idpelEsc = mysqli_real_escape_string($conn, $idpel);
        $checkedSlotEsc = mysqli_real_escape_string($conn, $slot);
        $checkedAtEsc = mysqli_real_escape_string($conn, $checkedAt);
        $methodEsc = mysqli_real_escape_string($conn, 'routeros-ppp');
        $responseSql = $responseMs > 0 ? (string)$responseMs : 'NULL';
        $rowsSql[] = "('$idpelEsc', '$pemilikEsc', '$areaEsc', '$checkedSlotEsc', '$checkedAtEsc', $isOnline, $responseSql, '$methodEsc')";
    }

    if (!empty($rowsSql)) {
        $sqlInsert = "INSERT INTO customer_sla_logs
            (idpel, pemilik, area, checked_slot, checked_at, is_online, response_ms, method)
            VALUES " . implode(',', $rowsSql) . "
            ON DUPLICATE KEY UPDATE
                checked_at = VALUES(checked_at),
                is_online = VALUES(is_online),
                response_ms = VALUES(response_ms),
                pemilik = VALUES(pemilik),
                area = VALUES(area),
                method = VALUES(method)";
        mysqli_query($conn, $sqlInsert);
    }
}

mysqli_query($conn, "DELETE FROM customer_sla_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 45 DAY)");

echo "SLA pelanggan selesai. Server: $totalServers, Total pelanggan: $totalCustomers, Online: $onlineCustomers, Offline: $offlineCustomers, Slot: $slot\n";
