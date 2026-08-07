<?php
require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/vlan_helpers.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$keterangan = trim($_POST['keterangan'] ?? '');
$withIp = isset($_POST['with_ip']); // centang "Aktifkan dengan IP Address" -> IP Pool wajib, kalau tidak -> VLAN aktif tanpa IP
$poolId = isset($_POST['pool_id']) ? (int)$_POST['pool_id'] : 0;

if (!$withIp) {
    $poolId = 0;
}

if ($id <= 0 || ($withIp && $poolId <= 0)) {
    header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('Input tidak lengkap atau tidak valid.'));
    exit;
}

// Ambil data VLAN lama + info server, pastikan milik user ini
$whereArea = '';
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $whereArea = " AND s.AREA IN ($area_list)";
    } else {
        header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('Akses area assistant tidak tersedia.'));
        exit;
    }
}

$oldSql = "SELECT v.id, v.vlan_id, v.pool_id, v.server_id, v.interface_name, v.ip_gateway, v.vlan_interface_name,
                  s.IP AS server_ip, s.PASSWORD AS server_password, s.PEMILIK AS server_user
           FROM vlan v
           LEFT JOIN server s ON s.id = v.server_id
           WHERE v.id = $id AND v.pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . $whereArea . "
           LIMIT 1";
$oldRes = mysqli_query($conn, $oldSql);
if (!$oldRes || mysqli_num_rows($oldRes) === 0) {
    header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('VLAN tidak ditemukan untuk akun ini.'));
    exit;
}
$old = mysqli_fetch_assoc($oldRes);

$oldPoolId = (int)($old['pool_id'] ?? 0);
$oldIpGateway = trim((string)($old['ip_gateway'] ?? ''));
$hadIp = $oldIpGateway !== '';
$vlanInterfaceName = $old['vlan_interface_name'] ?: ('vlan' . $old['vlan_id']);
$serverIp = $old['server_ip'];
$serverPassword = $old['server_password'];
$serverUser = $old['server_user'];

if (empty($serverIp) || empty($serverUser)) {
    header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('Data server untuk VLAN ini tidak ditemukan.'));
    exit;
}

// Ambil data pool baru (kalau VLAN diaktifkan dengan IP)
$ipAwal = '';
$ipAkhir = '';
$ipLocal = '';
if ($withIp) {
    $poolCheckSql = "SELECT ipawal, ipakhir, iplocal FROM pool WHERE id = $poolId AND pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
    $poolCheck = mysqli_query($conn, $poolCheckSql);
    if (!$poolCheck || mysqli_num_rows($poolCheck) === 0) {
        header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('IP Pool tidak ditemukan untuk akun ini.'));
        exit;
    }
    $poolData = mysqli_fetch_assoc($poolCheck);
    $ipAwal = $poolData['ipawal'];
    $ipAkhir = $poolData['ipakhir'];
    $ipLocal = trim((string)($poolData['iplocal'] ?? ''));
}

$poolChanged = ($poolId !== $oldPoolId);
$doRemoveOldIp = $hadIp && (!$withIp || $poolChanged);
$doAddNewIp = $withIp && (!$hadIp || $poolChanged);

$status = 'active';
$errorMsg = '';
$newIpGateway = $withIp ? $oldIpGateway : ''; // default: kalau pool tidak berubah, IP tetap sama

if ($doAddNewIp) {
    $newIpGateway = deriveVlanGatewayIp($ipAwal, $ipAkhir, $ipLocal);
    if ($newIpGateway === '') {
        header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('Format IP gateway tidak valid. Periksa data pool (ipawal/ipakhir/iplocal).'));
        exit;
    }
}

// Kalau ada perubahan yang perlu didorong ke MikroTik (tambah/hapus IP), konek dulu
if ($doRemoveOldIp || $doAddNewIp) {
    [$routerHost, $routerPort] = resolveVlanRouterEndpoint(trim((string)$serverIp));

    $API = new RouterosAPI();
    try {
        if ($routerPort > 0) {
            $API->port = $routerPort;
        }
        $connected = $API->connect($routerHost, $serverUser, $serverPassword);

        if ($connected) {
            if ($doRemoveOldIp) {
                $existingIps = $API->comm('/ip/address/print', ['?interface' => $vlanInterfaceName]);
                if (!empty($existingIps) && is_array($existingIps)) {
                    foreach ($existingIps as $ipEntry) {
                        if (isset($ipEntry['.id'])) {
                            $API->comm('/ip/address/remove', ['.id' => $ipEntry['.id']]);
                        }
                    }
                }
            }

            if ($doAddNewIp) {
                $addIpCmd = [
                    'address' => $newIpGateway,
                    'interface' => $vlanInterfaceName
                ];
                $ipResult = $API->comm('/ip/address/add', $addIpCmd);
                $ipFailed = (is_array($ipResult) && (isset($ipResult['!trap']) || isset($ipResult['!fatal'])));
                if ($ipFailed) {
                    $status = 'active_partial';
                    $trapMsg = '';
                    if (!empty($ipResult['!trap'][0]['message'])) {
                        $trapMsg = ' Detail: ' . $ipResult['!trap'][0]['message'];
                    }
                    $errorMsg = 'Gagal assign IP address baru.' . $trapMsg;
                    $newIpGateway = ''; // gagal assign, jangan simpan seolah-olah berhasil
                }
            }

            $API->disconnect();
        } else {
            $status = 'failed';
            $errorMsg = 'Gagal terhubung ke RouterOS API. Cek endpoint ' . $routerHost . ':' . $routerPort . ', username, password server.';
        }
    } catch (Exception $e) {
        $status = 'failed';
        $errorMsg = 'Error RouterOS: ' . $e->getMessage();
    }
}

if (!$withIp) {
    $newIpGateway = '';
}

// Simpan perubahan ke database
$ketEsc = mysqli_real_escape_string($conn, $keterangan);
$ipGatewayEsc = mysqli_real_escape_string($conn, $newIpGateway);
$statusEsc = mysqli_real_escape_string($conn, $status);
$errorMsgEsc = mysqli_real_escape_string($conn, $errorMsg);
$poolIdSql = $poolId > 0 ? (int)$poolId : 'NULL';

$updateSql = "UPDATE vlan
              SET keterangan = '$ketEsc', pool_id = $poolIdSql, ip_gateway = '$ipGatewayEsc',
                  status = '$statusEsc', error_message = '$errorMsgEsc', last_synced_at = NOW()
              WHERE id = $id
              LIMIT 1";
$updateRes = mysqli_query($conn, $updateSql);

if ($updateRes) {
    $historyFile = __DIR__ . '/../notifbot/data/history-' . $ceknama . '.json';
    $history = [];
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true);
    }
    if (!is_array($history)) {
        $history = [];
    }
    $actor = !empty($asistant_name) ? $asistant_name : $ceknama;
    $history[] = '[ ' . $actor . ' - ' . date('Y-m-d H:i:s') . ' ] Mengedit VLAN ' . $old['vlan_id'] . ' pada interface ' . $old['interface_name'];
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));

    if ($status === 'active') {
        header('Location: ../vlan.php?statusnotif=success_edit');
    } elseif ($status === 'active_partial') {
        header('Location: ../vlan.php?statusnotif=warning_edit&text=' . urlencode($errorMsg));
    } else {
        header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode($errorMsg));
    }
} else {
    header('Location: ../vlan.php?statusnotif=failed_edit&text=' . urlencode('Database error: ' . mysqli_error($conn)));
}
exit;
