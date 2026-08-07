<?php
/**
 * Ubah tanggal jatuh tempo berikutnya untuk 1 pelanggan, dari tables.php
 * (tombol pensil di baris "Jatuh tempo berikutnya").
 *
 * - Pelanggan mode Monthversary: geser TANGGAL_MONTHVERSARY sbg anchor
 *   permanen (hari-tetap-tiap-bulan), tetap Monthversary.
 * - Pelanggan mode Rolling (mengikuti_tanggal_bayar): kolom TANGGAL_MONTHVERSARY
 *   dipakai ulang sbg OVERRIDE jatuh tempo berikutnya (bukan anchor permanen) --
 *   TIPE_TEMPO TETAP Rolling, TIDAK dikonversi. Override cuma berlaku selama
 *   tanggalnya belum lewat (lihat tagihanGetRollingOverrideDueDate() di
 *   tagihan_status_lib.php); begitu pelanggan bayar sesuai/setelah siklus itu,
 *   perhitungan otomatis dari histori pembayaran berjalan lagi seperti biasa.
 * - Pelanggan mode Fixed Due Date: jatuh temponya SAMA utk SEMUA pelanggan
 *   mode ini (1 pengaturan global), jadi tidak relevan/tidak bisa diedit
 *   per-pelanggan di sini -- request DITOLAK.
 *
 * Kalau tanggal baru TIDAK lagi lewat hari ini (jatuh tempo digeser mundur),
 * dan profile pelanggan di MikroTik/RADIUS saat ini "EXPIRED" (sudah terlanjur
 * diisolir cron), pelanggan otomatis DIPULIHKAN ke profile paket normal --
 * cron cek_tagihan_harian.php TIDAK melakukan ini sendiri (pemulihan di sana
 * cuma jalan kalau ada transaksi BERHASIL di periode aktif, murni menggeser
 * tanggal tidak cukup), jadi restore manual dilakukan langsung di sini.
 */
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$idpel = trim((string)($_POST['idpel'] ?? ''));
$tanggal = trim((string)($_POST['tanggal_monthversary'] ?? ''));

if ($idpel === '') {
    echo json_encode(['success' => false, 'message' => 'IDPEL tidak boleh kosong']);
    exit;
}

if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid']);
    exit;
}

$idpelEsc = mysqli_real_escape_string($conn, $idpel);
$q = mysqli_query($conn, "SELECT IDPEL, PEMILIK, AREA, PAKET, PASSWORD, MODE, NAMA, NOWA, ODP, TIPE_TEMPO FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1");
$row = $q ? mysqli_fetch_assoc($q) : null;

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Pelanggan tidak ditemukan']);
    exit;
}

$tipeTempoLama = strtolower(trim((string)($row['TIPE_TEMPO'] ?? '')));

if ($tipeTempoLama === 'mengikuti_tanggal_tempo') {
    echo json_encode(['success' => false, 'message' => 'Jatuh tempo Fixed Due Date sama untuk semua pelanggan mode ini (1 pengaturan global) dan tidak bisa diedit per pelanggan. Ubah di Payment Setting.']);
    exit;
}

// Rolling & Monthversary sama-sama cukup update TANGGAL_MONTHVERSARY saja --
// TIPE_TEMPO TIDAK diubah/dikonversi (lihat komentar berkas ini).
$modeBerubah = false;
$tanggalEsc = mysqli_real_escape_string($conn, $tanggal);
$sqlUpdate = "UPDATE pelanggan SET TANGGAL_MONTHVERSARY = '$tanggalEsc' WHERE IDPEL = '$idpelEsc'";

if (!mysqli_query($conn, $sqlUpdate)) {
    echo json_encode(['success' => false, 'message' => 'Gagal update: ' . mysqli_error($conn)]);
    exit;
}

/**
 * Pulihkan 1 pelanggan dari profile EXPIRED (MikroTik) / grup EXPIRED (RADIUS)
 * ke profile paket normalnya. Dipanggil HANYA kalau tanggal jatuh tempo baru
 * tidak lagi lewat hari ini. Aman dipanggil berkali-kali (idempotent): kalau
 * profile saat ini bukan EXPIRED, tidak ada yang diubah.
 */
function restoreCustomerFromExpired($conn, array $pel): array
{
    $idpel   = (string)$pel['IDPEL'];
    $pemilik = (string)($pel['PEMILIK'] ?? '');
    $area    = (string)($pel['AREA'] ?? '');
    $paket   = (string)($pel['PAKET'] ?? '');
    $mode    = strtoupper(trim((string)($pel['MODE'] ?? '')));
    $log = [];
    $adaPerubahan = false;

    if ($mode === 'API MODE' || $mode === 'MULTI MODE') {
        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
        $areaEsc = mysqli_real_escape_string($conn, $area);
        $srvRes = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD FROM server WHERE PEMILIK = '$pemilikEsc' AND AREA = '$areaEsc' LIMIT 1");
        $srv = $srvRes ? mysqli_fetch_assoc($srvRes) : null;

        if (!$srv) {
            $log[] = 'MikroTik: data server tidak ditemukan, dilewati.';
        } else {
            $API = new RouterosAPI();
            $API->debug = false;
            if ($API->connect($srv['IP'], $srv['PEMILIK'], $srv['PASSWORD'])) {
                $secrets = $API->comm('/ppp/secret/print', ['?name' => $idpel]);
                if (empty($secrets) || !isset($secrets[0]['.id'])) {
                    $log[] = 'MikroTik: PPP secret tidak ditemukan.';
                } else {
                    $profilSaatIni = strtoupper(trim((string)($secrets[0]['profile'] ?? '')));
                    if ($profilSaatIni !== 'EXPIRED') {
                        $log[] = "MikroTik: profile saat ini '$profilSaatIni' (bukan EXPIRED), tidak diubah.";
                    } else {
                        // Cocokkan nama paket ke nama profile PERSIS seperti tersimpan di router
                        // (case-insensitive), supaya tidak salah ketik huruf besar/kecil.
                        $targetProfile = $paket;
                        $profiles = $API->comm('/ppp/profile/print');
                        if (is_array($profiles)) {
                            foreach ($profiles as $p) {
                                if (isset($p['name']) && strtolower(trim($p['name'])) === strtolower(trim($paket))) {
                                    $targetProfile = $p['name'];
                                    break;
                                }
                            }
                        }

                        $comment = 'AKTIF ' . $pel['NAMA'] . '-' . $pel['NOWA'] . '-' . date('Y-m-d') . ' (RESTORE VIA EDIT JATUH TEMPO)';
                        $API->comm('/ppp/secret/set', [
                            '.id' => $secrets[0]['.id'],
                            'profile' => $targetProfile,
                            'comment' => $comment,
                        ]);
                        $adaPerubahan = true;
                        $log[] = "MikroTik: profile secret dipulihkan dari EXPIRED ke '$targetProfile'.";
                    }
                }
                $API->disconnect();
            } else {
                $log[] = 'MikroTik: gagal konek ke server ' . $srv['IP'] . '.';
            }
        }
    }

    if ($mode === 'RADIUS MODE' || $mode === 'MULTI MODE') {
        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
        $paketEsc = mysqli_real_escape_string($conn, $paket);
        $paketRes = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET = '$paketEsc' AND PEMILIK = '$pemilikEsc' ORDER BY id DESC LIMIT 1");
        $paketRow = ($paketRes && mysqli_num_rows($paketRes) > 0) ? mysqli_fetch_assoc($paketRes) : ['PAKET' => $paket, 'KECEPATAN' => ''];

        $result = radiusSyncSingleCustomerNow($idpel, (string)$pel['PASSWORD'], $paketRow, true, radiusGetGlobalSettings($conn));
        if (!empty($result['changed'])) {
            $adaPerubahan = true;
            $log[] = 'RADIUS: grup dipulihkan ke profile paket normal.';
        } else {
            $log[] = 'RADIUS: sudah sesuai profile paket normal, tidak ada perubahan.';
        }
    }

    return ['ada_perubahan' => $adaPerubahan, 'log' => $log];
}

$restoreLog = [];
if (strtotime($tanggal) >= strtotime(date('Y-m-d'))) {
    try {
        $restoreResult = restoreCustomerFromExpired($conn, $row);
        $restoreLog = $restoreResult['log'];
    } catch (Throwable $e) {
        $restoreLog[] = 'Gagal memulihkan MikroTik/RADIUS: ' . $e->getMessage();
    }
}

// Log history (pola sama seperti proses/editodp.php dkk).
$pemilik = (string)($row['PEMILIK'] ?? '');
if ($pemilik !== '') {
    $history_file = "../notifbot/data/history-$pemilik.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) {
        $history = [];
    }
    $display_name = !empty($asistant_name) ? $asistant_name : $ceknama;
    $pesanLog = ($tipeTempoLama === 'mengikuti_tanggal_bayar')
        ? "[ $display_name - " . date('Y-m-d H:i:s') . " ] Set override jatuh tempo berikutnya (Rolling) $idpel jadi $tanggal"
        : "[ $display_name - " . date('Y-m-d H:i:s') . " ] Ubah anchor Monthversary $idpel jadi $tanggal";
    if (!empty($restoreLog)) {
        $pesanLog .= ' | Restore: ' . implode(' ', $restoreLog);
    }
    $history[] = $pesanLog;
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$pesanSukses = ($tipeTempoLama === 'mengikuti_tanggal_bayar')
    ? "Override jatuh tempo berikutnya berhasil diset ke $tanggal (mode tempo tetap Rolling)."
    : 'Tanggal jatuh tempo Monthversary berhasil diubah';
if (!empty($restoreLog)) {
    $pesanSukses .= ' ' . implode(' ', $restoreLog);
}

echo json_encode([
    'success' => true,
    'message' => $pesanSukses,
    'tanggal' => $tanggal,
    'mode_berubah' => $modeBerubah,
    'restore_log' => $restoreLog,
]);
