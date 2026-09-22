<?php

/**
 * Retry pengiriman WhatsApp yang gagal.
 *
 * Backoff berdasarkan jumlah percobaan yang sudah tercatat:
 *   attempt 1 -> 5 menit, attempt 2 -> 15 menit,
 *   attempt 3 -> 30 menit, attempt 4 -> 120 menit.
 * Maksimal 5 percobaan total (pengiriman awal + 4 retry).
 *
 * Hanya payment reminder yang diproses. Sebelum retry, worker memastikan masih
 * ada invoice PENAGIHAN untuk IDPEL + periode agar pelanggan yang sudah membayar
 * tidak menerima pesan lama.
 */

require_once __DIR__ . '/../../koneksidb.php';
require_once __DIR__ . '/whatsapp_notification_log_helper.php';

date_default_timezone_set('Asia/Jakarta');

const WA_RETRY_MAX_ATTEMPTS = 5;
const WA_RETRY_BATCH_SIZE = 50;
const WA_RETRY_ACTIVATED_AT = '2026-09-22 11:29:00';

if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    fwrite(STDERR, "Koneksi database gagal\n");
    exit(1);
}

$lockPath = sys_get_temp_dir() . '/billing_whatsapp_notification_retry.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Worker retry masih berjalan; proses ini dilewati.\n";
    exit(0);
}

waNotifEnsureSchema($conn);
$dryRun = getenv('WA_RETRY_DRY_RUN') === '1';
$summary = [
    'eligible' => 0,
    'claimed' => 0,
    'sent' => 0,
    'failed' => 0,
    'skipped_paid' => 0,
    'skipped_permanent' => 0,
    'skipped_bot' => 0,
];

// INVALID_JID bukan gangguan sementara: nomor memang tidak terdaftar di
// WhatsApp. Jangan menghabiskan seluruh jatah retry untuk error permanen ini.
if (!$dryRun) {
    $permanentResult = $conn->query("UPDATE whatsapp_notification_log
        SET status='skipped', skip_reason='permanent_invalid_whatsapp_number', updated_at=NOW()
        WHERE status='failed'
          AND UPPER(COALESCE(response_message,'')) LIKE '%INVALID_JID%'");
    if ($permanentResult) {
        $summary['skipped_permanent'] += (int)$conn->affected_rows;
    }
    // Jangan mengirim ulang backlog historis saat worker pertama kali dipasang.
    // Retry otomatis hanya berlaku untuk kegagalan baru setelah waktu aktivasi.
    $legacyCutoff = WA_RETRY_ACTIVATED_AT;
    $legacyStmt = $conn->prepare("UPDATE whatsapp_notification_log
        SET status='skipped', skip_reason='legacy_before_retry_worker', updated_at=NOW()
        WHERE status='failed' AND created_at < ?");
    $legacyStmt->bind_param('s', $legacyCutoff);
    $legacyStmt->execute();
    $summary['skipped_permanent'] += (int)$legacyStmt->affected_rows;
    $legacyStmt->close();
}

$candidateSql = "SELECT id, pemilik, idpel, nomor_wa, periode,
                        jenis_notifikasi, message, bot_name, attempts
                 FROM whatsapp_notification_log
                 WHERE status='failed'
                   AND created_at >= ?
                   AND attempts < ?
                   AND jenis_notifikasi LIKE 'payment_reminder%'
                   AND updated_at <= DATE_SUB(NOW(), INTERVAL
                       CASE attempts
                           WHEN 1 THEN 5
                           WHEN 2 THEN 15
                           WHEN 3 THEN 30
                           ELSE 120
                       END MINUTE)
                 ORDER BY updated_at ASC, id ASC
                 LIMIT " . WA_RETRY_BATCH_SIZE;
$candidateStmt = $conn->prepare($candidateSql);
$maxAttempts = WA_RETRY_MAX_ATTEMPTS;
$activatedAt = WA_RETRY_ACTIVATED_AT;
$candidateStmt->bind_param('si', $activatedAt, $maxAttempts);
$candidateStmt->execute();
$candidateResult = $candidateStmt->get_result();
$candidates = [];
while ($row = $candidateResult->fetch_assoc()) {
    $candidates[] = $row;
}
$candidateStmt->close();

$pendingStmt = $conn->prepare("SELECT 1
    FROM transaksi
    WHERE IDPEL=?
      AND TRIM(UPPER(COALESCE(PENGUNAAN,'')))=TRIM(UPPER(?))
      AND TRIM(UPPER(COALESCE(STATUS,'')))='PENAGIHAN'
    LIMIT 1");
$skipStmt = $conn->prepare("UPDATE whatsapp_notification_log
    SET status='skipped', skip_reason=?, updated_at=NOW()
    WHERE id=? AND status='failed'");
$claimStmt = $conn->prepare("UPDATE whatsapp_notification_log
    SET status='sending', attempts=attempts+1, http_code=NULL,
        response_message=NULL, skip_reason=NULL, updated_at=NOW()
    WHERE id=? AND status='failed' AND attempts < ?");
$botsStmt = $conn->prepare("SELECT namebot, addressbot, password, COALESCE(sender,'') AS sender
    FROM botwa
    WHERE pemilik=?
      AND TRIM(COALESCE(namebot,''))<>''
      AND TRIM(COALESCE(addressbot,''))<>''
      AND TRIM(COALESCE(password,''))<>''
    ORDER BY CASE WHEN namebot=? THEN 0 ELSE 1 END, id ASC");

foreach ($candidates as $job) {
    $summary['eligible']++;
    $id = (int)$job['id'];
    $idpel = trim((string)$job['idpel']);
    $periode = trim((string)$job['periode']);

    if ($dryRun) {
        echo "DRY-RUN eligible id={$id} IDPEL={$idpel} periode={$periode} attempts=" . (int)$job['attempts'] . "\n";
        continue;
    }

    // Jangan kirim tagihan lama setelah invoice lunas/dihapus.
    $pendingStmt->bind_param('ss', $idpel, $periode);
    $pendingStmt->execute();
    $pendingStmt->store_result();
    $stillPending = $pendingStmt->num_rows > 0;
    $pendingStmt->free_result();
    if (!$stillPending) {
        $reason = 'invoice_not_pending_or_already_paid';
        $skipStmt->bind_param('si', $reason, $id);
        $skipStmt->execute();
        $summary['skipped_paid']++;
        continue;
    }

    $pemilik = trim((string)$job['pemilik']);
    $savedBot = trim((string)$job['bot_name']);
    $botsStmt->bind_param('ss', $pemilik, $savedBot);
    $botsStmt->execute();
    $botsResult = $botsStmt->get_result();
    $bots = [];
    while ($bot = $botsResult->fetch_assoc()) {
        $bots[] = $bot;
    }
    if (empty($bots)) {
        // Tetap failed agar dapat dicoba lagi setelah konfigurasi bot diperbaiki.
        $summary['skipped_bot']++;
        continue;
    }

    // Putar bot pada retry berikutnya; bot tersimpan tetap menjadi pilihan awal.
    $botIndex = max(0, ((int)$job['attempts'] - 1)) % count($bots);
    $bot = $bots[$botIndex];

    $claimStmt->bind_param('ii', $id, $maxAttempts);
    $claimStmt->execute();
    if ($claimStmt->affected_rows !== 1) {
        continue;
    }
    $summary['claimed']++;

    $phone = trim((string)$job['nomor_wa']) . '@s.whatsapp.net';
    $session = trim((string)$bot['namebot']);
    $url = rtrim((string)$bot['addressbot'], '/') . '/send/message?session=' . urlencode($session);
    $deviceId = trim((string)$bot['sender']);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = ['Content-Type: application/json'];
    if ($deviceId !== '') {
        $headers[] = 'X-Device-Id: ' . $deviceId;
    }
    $payload = json_encode([
        'phone' => $phone,
        'message' => (string)$job['message'],
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, $session . ':' . (string)$bot['password']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $success = $curlError === '' && $httpCode >= 200 && $httpCode < 300;
    $responseText = $curlError !== ''
        ? 'cURL: ' . $curlError . ' | ' . (string)$response
        : (string)$response;
    waNotifFinish($conn, $id, $success, $httpCode, $responseText);
    $summary[$success ? 'sent' : 'failed']++;
    echo ($success ? 'SENT' : 'FAILED') . " id={$id} IDPEL={$idpel} bot={$session} HTTP={$httpCode}\n";
}

$pendingStmt->close();
$skipStmt->close();
$claimStmt->close();
$botsStmt->close();

echo 'Ringkasan retry: ' . json_encode($summary, JSON_UNESCAPED_SLASHES) . "\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
