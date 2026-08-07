<?php
header("Content-Type: application/json");
require 'cek-sesi.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Buat_Tiket', $akses_menu, true)) {
        echo json_encode(["success" => false, "message" => "Anda tidak memiliki akses untuk membuat tiket."]);
        exit;
    }
}

$conn = isset($conn) ? $conn : null;
if (!($conn instanceof mysqli)) {
    echo json_encode(["success" => false, "message" => "Koneksi billing tidak tersedia."]);
    exit;
}

$ticketSource = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
if (!in_array($ticketSource, ['tiket_manager', 'joblist'], true)) {
    $ticketSource = 'tiket_manager';
}

$tgl = $_POST['tgl'] ?? date("Y-m-d");
$status = 'BARU';
$tipe = strtoupper(trim((string)($_POST['tipe'] ?? 'MAINTENANCE')));
$project = trim((string)($_POST['BRAND'] ?? ''));

$NOWA = trim((string)($_POST['NOWA'] ?? ''));
$ALAMAT = trim((string)($_POST['ALAMAT'] ?? ''));
$EMAIL = trim((string)($_POST['EMAIL'] ?? ''));
$TIKOR = trim((string)($_POST['TIKOR'] ?? ''));
$ODP = trim((string)($_POST['ODP'] ?? ''));
$IDPEL = trim((string)($_POST['IDPEL'] ?? ''));
$NAMA = trim((string)($_POST['NAMA'] ?? ''));
$kendala = trim((string)($_POST['kendala'] ?? ''));

$datatiket = "===============\nTiket $tipe dari billing\n===============\nID PELANGGAN :$IDPEL\nNAMA PELANGGAN :$NAMA\nODP :$ODP\nEMAIL :$EMAIL\nALAMAT :$ALAMAT\nNO WHATSAPP : $NOWA\nKENDALA : $kendala\nTIKOR : $TIKOR";

$saved = false;
$saveError = '';

if ($ticketSource === 'joblist') {
    include "koneksidbabsensi.php";
    $conn = isset($conn) ? $conn : null;

    if (!($conn instanceof mysqli)) {
        echo json_encode(["success" => false, "message" => "Koneksi joblist tidak tersedia."]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO joblist (tgl, status, tipe, nowa, data, project, report, team) VALUES (?, 'BARU', ?, '', ?, ?, '', '')");
    if ($stmt) {
        $stmt->bind_param('ssss', $tgl, $tipe, $datatiket, $project);
        $saved = $stmt->execute();
        $saveError = $stmt->error;
        $stmt->close();
    } else {
        $saveError = $conn->error;
    }
} else {
    $ownerId = isset($USER_ID) ? (int)$USER_ID : 0;
    $creatorId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : $ownerId;

    $serverRow = null;
    $stmtSrv = $conn->prepare("SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? AND (PEMILIK = ? OR BRAND = ?) ORDER BY CASE WHEN PEMILIK = ? THEN 0 ELSE 1 END, id ASC LIMIT 1");
    if ($stmtSrv) {
        $stmtSrv->bind_param('isss', $ownerId, $project, $project, $project);
        $stmtSrv->execute();
        $resSrv = $stmtSrv->get_result();
        $serverRow = $resSrv ? $resSrv->fetch_assoc() : null;
        $stmtSrv->close();
    }

    if (!$serverRow) {
        $stmtSrvAny = $conn->prepare("SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        if ($stmtSrvAny) {
            $stmtSrvAny->bind_param('i', $ownerId);
            $stmtSrvAny->execute();
            $resSrvAny = $stmtSrvAny->get_result();
            $serverRow = $resSrvAny ? $resSrvAny->fetch_assoc() : null;
            $stmtSrvAny->close();
        }
    }

    if (!$serverRow) {
        echo json_encode(["success" => false, "message" => "Server untuk tiket manager tidak ditemukan."]);
        exit;
    }

    $serverId = (int)$serverRow['id'];
    $pemilik = (string)($serverRow['PEMILIK'] ?? $project);
    $brand = (string)($serverRow['BRAND'] ?? '');
    $area = (string)($serverRow['AREA'] ?? '');
    $projectName = trim($brand . ' - ' . $area);
    if ($projectName === '-' || $projectName === '') {
        $projectName = $pemilik;
    }
    $judul = trim("$tipe - $IDPEL - $NAMA");
    if ($judul === '') {
        $judul = "$tipe - Tiket Billing";
    }
    $report = '';
    $teknisiId = null;

    $stmtIns = $conn->prepare("INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'BARU', ?, ?)");
    if ($stmtIns) {
        $stmtIns->bind_param('ssissssssii', $judul, $datatiket, $serverId, $pemilik, $brand, $area, $projectName, $tipe, $report, $teknisiId, $creatorId);
        $saved = $stmtIns->execute();
        $saveError = $stmtIns->error;
        $stmtIns->close();
    } else {
        $saveError = $conn->error;
    }
}

if ($saved) {
    echo json_encode(["success" => true, "message" => "Tiket berhasil dibuat."]);

    $text = "*[NOTIF SYSTEM BOT QTS]*\n===================\nTIKET BARU MASUK DI APP\n===================\n\nPROJECT : $project \nTIPE : $tipe\nDATA :\n$datatiket\n\n===================\n";

    $file = "waapi.txt";
    $waapi = $namebot = $password = $sender = $grup = "";
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (substr($line, 0, 6) === "waapi=") $waapi = substr($line, 6);
            if (substr($line, 0, 8) === "namebot=") $namebot = substr($line, 8);
            if (substr($line, 0, 9) === "password=") $password = substr($line, 9);
            if (substr($line, 0, 5) === "grup=") $grup = substr($line, 5);
            if (substr($line, 0, 7) === "sender=") $sender = substr($line, 7);
        }
    }

    if ($waapi !== '' && $namebot !== '' && $password !== '' && $grup !== '') {
        $payload = [
            "phone" => $grup,
            "message" => $text,
            "sender" => $sender
        ];

        // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
        // device_id query di /send/message) = isi kolom sender apa adanya.
        $deviceId = trim((string)$sender);

        $url = "$waapi/send/message?session=" . urlencode($namebot);
        if ($deviceId !== '') {
            $url .= '&device_id=' . urlencode($deviceId);
        }
        $headers = ["Content-Type: application/json"];
        if ($deviceId !== '') {
            $headers[] = "X-Device-Id: $deviceId";
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "$namebot:$password");
        curl_exec($ch);
        curl_close($ch);
    }
    exit;
}

echo json_encode(["success" => false, "message" => "Gagal menyimpan tiket." . ($saveError !== '' ? (' ' . $saveError) : '')]);
