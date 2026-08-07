<?php
/**
 * getdata/adjust_tanggal_tempo_excel.php
 * ----------------------------------------------------------------------
 * Backend untuk halaman adjust_tanggal_tempo_excel.php: penyesuaian massal
 * "tanggal bayar" & "tanggal jatuh tempo berikutnya" lewat upload Excel,
 * KHUSUS pelanggan mode tempo Monthversary & Rolling Due Date.
 *
 * Dipakai untuk migrasi data transaksi dari Excel lama: admin upload file,
 * hasil parse ditampilkan di tabel preview yang BISA DIEDIT LANGSUNG &
 * DIFILTER di halaman (lihat adjust_tanggal_tempo_excel.php), baru setelah
 * dikonfirmasi baris-baris yang dipilih dieksekusi.
 *
 * Pencocokan SELALU berdasarkan IDPEL. Baris yang di file/preview-nya TIDAK
 * diisi (kedua kolom tanggal kosong) TIDAK disentuh sama sekali -- data yang
 * sudah ada di database dibiarkan apa adanya, TIDAK ada yang dihapus.
 *
 * "Tanggal bayar" TIDAK membuat transaksi baru kalau pelanggan itu sudah
 * punya riwayat transaksi BERHASIL -- yang diubah adalah TRANSAKSI BERHASIL
 * TERAKHIR pelanggan itu saja (UPDATE tanggal/nominal/metode bayarnya).
 * Transaksi baru cuma dibuat kalau pelanggan tsb memang belum pernah punya
 * transaksi BERHASIL sama sekali.
 *
 * "Tanggal jatuh tempo berikutnya" logikanya SAMA PERSIS dengan
 * proses/update_tanggal_monthversary.php (tombol pensil "Ubah Jatuh Tempo"
 * di tables.php) supaya perilakunya konsisten dgn edit satuan.
 * ----------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

header('Content-Type: application/json; charset=utf-8');

// ----------------------------------------------------------------------
// Guard akses: ADMIN & ASSISTANT boleh (ASSISTANT dibatasi $area_list),
// sama seperti import_excel_pelanggan.php.
// ----------------------------------------------------------------------
if (!isset($AKSES) || !in_array($AKSES, ['ADMIN', 'ASSISTANT'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit;
}

function adjTempoAreaAllowed(string $area): bool
{
    global $AKSES, $area_list;
    if ($AKSES !== 'ASSISTANT') {
        return true;
    }
    if (empty($area_list)) {
        return false;
    }
    $allowed = array_map(function ($s) {
        return trim($s, " '");
    }, explode(',', $area_list));
    return in_array($area, $allowed, true);
}

/**
 * Daftar PEMILIK yang dimiliki akun saat ini (server.user_id = akun utama),
 * dipakai untuk membatasi pelanggan yang boleh disentuh lewat tool ini.
 */
function adjTempoOwnedPemilikList($conn, $current_user_id): array
{
    $list = [];
    $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE user_id = " . (int) $current_user_id);
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $list[] = (string) $row['PEMILIK'];
        }
    }
    return $list;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ========================================================================
// Helper tanggal & angka
// ========================================================================

function adjSafeNumber($val): float
{
    if (is_numeric($val)) {
        return (float) $val;
    }
    if (is_string($val)) {
        $clean = preg_replace('/[^0-9.,-]/', '', $val);
        $clean = str_replace(['.', ','], ['', '.'], $clean);
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }
    return 0.0;
}

function periodeIndonesiaAdj(string $tanggal): string
{
    $bulanIndonesia = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $ts = strtotime($tanggal);
    if ($ts === false) {
        $ts = time();
    }
    return $bulanIndonesia[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Parse tanggal dari cell Excel (serial number ATAU teks dgn pemisah "/"
 * ambigu D/M/Y vs M/D/Y). Return timestamp Unix, atau false kalau tidak
 * bisa diparse. Dipakai HANYA saat parse file upload (preview) -- nilai
 * yang benar-benar dieksekusi datang dari input <input type="date"> di
 * browser (selalu YYYY-MM-DD), jadi divalidasi terpisah lewat adjValidYmd().
 */
function adjParseTanggal(string $raw)
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }

    if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
        $serial = (float) $raw;
        if ($serial > 25569 && $serial < 73050) {
            return (int) round(($serial - 25569) * 86400);
        }
    }

    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $year = (int) $m[3];

        if ($a > 12 && $b <= 12) {
            $day = $a; $month = $b;
        } elseif ($b > 12 && $a <= 12) {
            $month = $a; $day = $b;
        } elseif ($a <= 12 && $b <= 12) {
            $day = $a; $month = $b;
        } else {
            return false;
        }

        return checkdate($month, $day, $year) ? mktime(0, 0, 0, $month, $day, $year) : false;
    }

    return strtotime($raw);
}

/**
 * Validasi ketat format 'YYYY-MM-DD' (bentuk yang dikirim <input type="date">
 * dari browser). Return 'Y-m-d' ternormalisasi, atau '' kalau kosong/invalid.
 */
function adjValidYmd(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return '';
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $raw : '';
}

/**
 * Format 'Y-m-d' -> teks Indonesia lengkap "Senin, 27 Juli 2026", supaya
 * konsisten dgn kolom TANGGALBAYAR di tabel `transaksi`.
 */
function adjTanggalBayarIndo(string $tanggalYmd): string
{
    $hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($tanggalYmd);
    if ($ts === false) {
        return $tanggalYmd;
    }
    return $hari[(int) date('N', $ts)] . ', ' . (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

// ========================================================================
// XLSX reader hand-rolled (server tidak punya PhpSpreadsheet).
// ========================================================================

function adjExcelColumnToIndex($column): int
{
    $column = strtoupper(trim((string) $column));
    $index = 0;
    $len = strlen($column);
    for ($i = 0; $i < $len; $i++) {
        $charCode = ord($column[$i]);
        if ($charCode < 65 || $charCode > 90) {
            continue;
        }
        $index = $index * 26 + ($charCode - 64);
    }
    return max(0, $index - 1);
}

function adjLoadSharedStrings(ZipArchive $zip): array
{
    $sharedStrings = [];
    $xmlRaw = $zip->getFromName('xl/sharedStrings.xml');
    if (!$xmlRaw) {
        return $sharedStrings;
    }
    $xml = @simplexml_load_string($xmlRaw);
    if (!$xml) {
        return $sharedStrings;
    }
    foreach ($xml->si as $si) {
        if (isset($si->t)) {
            $sharedStrings[] = (string) $si->t;
        } else {
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string) $r->t;
            }
            $sharedStrings[] = $text;
        }
    }
    return $sharedStrings;
}

function adjLoadSheetNameMap(ZipArchive $zip): array
{
    $map = [];

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!$workbookXml || !$relsXml) {
        return $map;
    }

    $workbook = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$workbook || !$rels) {
        return $map;
    }

    $ridToTarget = [];
    foreach ($rels->Relationship as $rel) {
        $id = (string) $rel['Id'];
        $target = (string) $rel['Target'];
        if ($target !== '' && $target[0] === '/') {
            $target = ltrim($target, '/');
        } else {
            $target = 'xl/' . $target;
        }
        $ridToTarget[$id] = $target;
    }

    $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    foreach ($workbook->sheets->sheet as $sheet) {
        $name = (string) $sheet['name'];
        $rAttrs = $sheet->attributes($rNs);
        $rid = isset($rAttrs['id']) ? (string) $rAttrs['id'] : '';
        if ($name !== '' && $rid !== '' && isset($ridToTarget[$rid])) {
            $map[$name] = $ridToTarget[$rid];
        }
    }

    return $map;
}

function adjParseWorksheetRows(ZipArchive $zip, string $path, array $sharedStrings): array
{
    $worksheetXml = $zip->getFromName($path);
    if (!$worksheetXml) {
        return [];
    }
    $xml = @simplexml_load_string($worksheetXml);
    if (!$xml || !$xml->sheetData) {
        return [];
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $cell) {
            $cellRef = (string) $cell['r'];
            $cellRefClean = preg_replace('/\d+/', '', $cellRef);
            if ($cellRefClean === '') {
                continue;
            }
            $col = adjExcelColumnToIndex($cellRefClean);

            if (isset($cell['t']) && (string) $cell['t'] === 's') {
                $stringIndex = (int) $cell->v;
                $rowData[$col] = $sharedStrings[$stringIndex] ?? '';
            } elseif (isset($cell->is)) {
                $rowData[$col] = (string) $cell->is->t;
            } else {
                $rowData[$col] = (string) $cell->v;
            }
        }
        $rows[] = $rowData;
    }
    return $rows;
}

function adjRowsToAssoc(array $rawRows, array $requiredHeaders): array
{
    if (empty($rawRows)) {
        return ['error' => null, 'rows' => []];
    }

    $headerRow = $rawRows[0];
    $colMap = [];
    foreach ($headerRow as $i => $h) {
        $normalized = strtoupper(trim((string) $h));
        if ($normalized !== '') {
            $colMap[$normalized] = $i;
        }
    }

    $missing = [];
    foreach ($requiredHeaders as $req) {
        if (!isset($colMap[strtoupper($req)])) {
            $missing[] = $req;
        }
    }
    if (!empty($missing)) {
        return ['error' => 'Kolom wajib tidak ditemukan di header: ' . implode(', ', $missing), 'rows' => []];
    }

    $rows = [];
    for ($i = 1; $i < count($rawRows); $i++) {
        $raw = $rawRows[$i];
        if (empty(array_filter($raw, function ($v) {
            return trim((string) $v) !== '';
        }))) {
            continue;
        }
        $assoc = ['_row_number' => $i + 1];
        foreach ($colMap as $name => $idx) {
            $assoc[$name] = isset($raw[$idx]) ? trim((string) $raw[$idx]) : '';
        }
        $rows[] = $assoc;
    }

    return ['error' => null, 'rows' => $rows];
}

// ========================================================================
// Resolver preview: dari baris mentah (parsed Excel) -> data siap
// ditampilkan & diedit di preview. HANYA dipakai oleh action=preview.
// ========================================================================

const ADJ_TIPE_TEMPO_ALLOWED = ['monthversary', 'mengikuti_tanggal_bayar'];

function adjLookupPelangganForAdjust($conn, string $idpel, array $ownedPemilikMap, string $AKSES): array
{
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $pelQ = mysqli_query($conn, "SELECT IDPEL, PEMILIK, AREA, PAKET, PASSWORD, MODE, NAMA, NOWA, ODP, TIPE_TEMPO, HARGA FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1");
    $pel = $pelQ ? mysqli_fetch_assoc($pelQ) : null;

    if (!$pel) {
        return [null, 'IDPEL tidak ditemukan di database.'];
    }
    if (!isset($ownedPemilikMap[(string) $pel['PEMILIK']])) {
        return [null, 'Pelanggan bukan milik akun Anda.'];
    }
    if ($AKSES === 'ASSISTANT' && !adjTempoAreaAllowed((string) $pel['AREA'])) {
        return [null, 'Area pelanggan di luar cakupan akun Anda.'];
    }
    $tipeTempo = strtolower(trim((string) $pel['TIPE_TEMPO']));
    if (!in_array($tipeTempo, ADJ_TIPE_TEMPO_ALLOWED, true)) {
        return [null, 'Mode tempo pelanggan ini Fixed Due Date (jatuh tempo sama untuk semua pelanggan, diatur global di Payment Setting) -- tidak bisa diedit di sini.'];
    }

    return [$pel, ''];
}

function adjResolveRows($conn, array $rawRows, array $ownedPemilikList, string $AKSES): array
{
    $data = [];
    $blockedRows = [];
    $seenInFile = [];

    $ownedPemilikMap = [];
    foreach ($ownedPemilikList as $p) {
        $ownedPemilikMap[$p] = true;
    }

    foreach ($rawRows as $r) {
        $rowNumber = $r['_row_number'] ?? 0;
        $idpel = trim((string) ($r['IDPEL'] ?? ''));

        if ($idpel === '') {
            continue; // baris kosong/spacer, lewati diam-diam
        }
        if (stripos($idpel, 'CONTOH') !== false) {
            continue; // baris contoh dari template, lewati diam-diam (aman kalau lupa dihapus)
        }
        if (isset($seenInFile[$idpel])) {
            $blockedRows[] = ['row' => $rowNumber, 'idpel' => $idpel, 'nama' => $r['NAMA'] ?? '', 'reason' => 'Duplikat IDPEL di dalam file, baris pertama yang dipakai.'];
            continue;
        }
        $seenInFile[$idpel] = true;

        [$pel, $err] = adjLookupPelangganForAdjust($conn, $idpel, $ownedPemilikMap, $AKSES);
        if ($pel === null) {
            $blockedRows[] = ['row' => $rowNumber, 'idpel' => $idpel, 'nama' => $r['NAMA'] ?? '', 'reason' => $err];
            continue;
        }
        $tipeTempo = strtolower(trim((string) $pel['TIPE_TEMPO']));

        $warnings = [];

        $tglBayarRaw = trim((string) ($r['TANGGAL_BAYAR_BARU'] ?? ''));
        $tanggalBayarBaru = '';
        if ($tglBayarRaw !== '') {
            $parsed = adjParseTanggal($tglBayarRaw);
            if ($parsed === false) {
                $blockedRows[] = ['row' => $rowNumber, 'idpel' => $idpel, 'nama' => $pel['NAMA'], 'reason' => "Format TANGGAL_BAYAR_BARU tidak valid ('$tglBayarRaw')."];
                continue;
            }
            $tanggalBayarBaru = date('Y-m-d', $parsed);
        }

        $tglTempoRaw = trim((string) ($r['TANGGAL_JATUH_TEMPO_BARU'] ?? ''));
        $tanggalTempoBaru = '';
        if ($tglTempoRaw !== '') {
            $parsed = adjParseTanggal($tglTempoRaw);
            if ($parsed === false) {
                $blockedRows[] = ['row' => $rowNumber, 'idpel' => $idpel, 'nama' => $pel['NAMA'], 'reason' => "Format TANGGAL_JATUH_TEMPO_BARU tidak valid ('$tglTempoRaw')."];
                continue;
            }
            $tanggalTempoBaru = date('Y-m-d', $parsed);
        }

        if ($tanggalBayarBaru === '' && $tanggalTempoBaru === '') {
            continue; // baris referensi yang tidak diisi/diubah admin, lewati diam-diam
        }

        $nominal = 0.0;
        if ($tanggalBayarBaru !== '') {
            $nominal = adjSafeNumber($r['NOMINAL_BAYAR_BARU'] ?? '');
            if ($nominal <= 0) {
                $nominal = (float) $pel['HARGA'];
                if ($nominal <= 0) {
                    $warnings[] = 'NOMINAL_BAYAR_BARU kosong dan harga paket pelanggan juga 0, transaksi akan tercatat Rp 0.';
                } else {
                    $warnings[] = 'NOMINAL_BAYAR_BARU kosong, otomatis pakai harga paket pelanggan saat ini.';
                }
            }
        }

        $data[] = [
            'row' => $rowNumber,
            'IDPEL' => $idpel,
            'NAMA' => (string) $pel['NAMA'],
            'AREA' => (string) $pel['AREA'],
            'TIPE_TEMPO' => $tipeTempo,
            'tanggal_bayar_baru' => $tanggalBayarBaru,
            'nominal_bayar_baru' => $nominal,
            'metode_bayar_baru' => trim((string) ($r['METODE_BAYAR_BARU'] ?? '')),
            'tanggal_jatuh_tempo_baru' => $tanggalTempoBaru,
            'keterangan' => trim((string) ($r['KETERANGAN'] ?? '')),
            'warnings' => $warnings,
        ];
    }

    return ['data' => $data, 'blocked_rows' => $blockedRows];
}

/**
 * Pulihkan 1 pelanggan dari profile EXPIRED (MikroTik) / grup EXPIRED
 * (RADIUS) ke profile paket normalnya -- salinan PERSIS dari
 * proses/update_tanggal_monthversary.php::restoreCustomerFromExpired()
 * supaya perilakunya konsisten dgn edit satuan tombol pensil di tables.php.
 */
function adjRestoreCustomerFromExpired($conn, array $pel): array
{
    $idpel   = (string) $pel['IDPEL'];
    $pemilik = (string) ($pel['PEMILIK'] ?? '');
    $area    = (string) ($pel['AREA'] ?? '');
    $paket   = (string) ($pel['PAKET'] ?? '');
    $mode    = strtoupper(trim((string) ($pel['MODE'] ?? '')));
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
                    $profilSaatIni = strtoupper(trim((string) ($secrets[0]['profile'] ?? '')));
                    if ($profilSaatIni !== 'EXPIRED') {
                        $log[] = "MikroTik: profile saat ini '$profilSaatIni' (bukan EXPIRED), tidak diubah.";
                    } else {
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

                        $comment = 'AKTIF ' . $pel['NAMA'] . '-' . $pel['NOWA'] . '-' . date('Y-m-d') . ' (RESTORE VIA MIGRASI EXCEL)';
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

        $result = radiusSyncSingleCustomerNow($idpel, (string) $pel['PASSWORD'], $paketRow, true, radiusGetGlobalSettings($conn));
        if (!empty($result['changed'])) {
            $adaPerubahan = true;
            $log[] = 'RADIUS: grup dipulihkan ke profile paket normal.';
        } else {
            $log[] = 'RADIUS: sudah sesuai profile paket normal, tidak ada perubahan.';
        }
    }

    return ['ada_perubahan' => $adaPerubahan, 'log' => $log];
}

/**
 * Cari transaksi BERHASIL TERAKHIR milik 1 IDPEL (dipakai supaya migrasi
 * "tanggal bayar" MENGUBAH transaksi terakhir yang sudah ada, bukan selalu
 * menambah baris baru). Return row assoc (id, TANGGALBAYAR, HARGA,
 * METODE_BAYAR, CEK) atau null kalau belum pernah ada transaksi BERHASIL.
 */
function adjFindLastSuccessfulTransaction($conn, string $idpel): ?array
{
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $trxDateExpr = tagihanBuildTrxDateExpr();
    $sql = "SELECT id, TANGGALBAYAR, HARGA, METODE_BAYAR, CEK
            FROM transaksi
            WHERE IDPEL = '$idpelEsc' AND STATUS = 'BERHASIL'
            ORDER BY $trxDateExpr DESC, id DESC
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res) : null;
}

// ----------------------------------------------------------------------
// ACTION: preview
// ----------------------------------------------------------------------
if ($action === 'preview') {
    if (!isset($_FILES['excel_file'])) {
        echo json_encode(['success' => false, 'message' => 'File Excel wajib diupload.']);
        exit;
    }

    $fileError = $_FILES['excel_file']['error'];
    $fileSize  = $_FILES['excel_file']['size'];
    $fileName  = $_FILES['excel_file']['name'];
    $tmpName   = $_FILES['excel_file']['tmp_name'];

    if ($fileError !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Error saat upload file.']);
        exit;
    }
    if ($fileSize > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Ukuran file terlalu besar! Maksimal 10MB.']);
        exit;
    }
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        echo json_encode(['success' => false, 'message' => 'Format file tidak didukung! Gunakan .xlsx atau .xls.']);
        exit;
    }
    if (!class_exists('ZipArchive')) {
        echo json_encode(['success' => false, 'message' => 'ZipArchive tidak tersedia. Server tidak support Excel import.']);
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpName) !== true) {
        echo json_encode(['success' => false, 'message' => 'Tidak dapat membuka file Excel. Pastikan file valid.']);
        exit;
    }

    $sharedStrings = adjLoadSharedStrings($zip);
    $sheetMap = adjLoadSheetNameMap($zip);

    if (!isset($sheetMap['Penyesuaian Tanggal'])) {
        $zip->close();
        echo json_encode(['success' => false, 'message' => "Sheet 'Penyesuaian Tanggal' tidak ditemukan di file. Gunakan template terbaru (tombol Download Template)."]);
        exit;
    }

    $rawRows = adjParseWorksheetRows($zip, $sheetMap['Penyesuaian Tanggal'], $sharedStrings);
    $zip->close();

    $parsed = adjRowsToAssoc($rawRows, ['IDPEL']);
    if ($parsed['error']) {
        echo json_encode(['success' => false, 'message' => "Sheet 'Penyesuaian Tanggal': " . $parsed['error']]);
        exit;
    }

    $ownedPemilikList = adjTempoOwnedPemilikList($conn, $current_user_id);
    $resolved = adjResolveRows($conn, $parsed['rows'], $ownedPemilikList, $AKSES);

    echo json_encode(array_merge(
        ['success' => true],
        $resolved,
        ['total_rows' => count($resolved['data'])]
    ));
    exit;
}

// ----------------------------------------------------------------------
// ACTION: execute
// Menerima payload baris LANGSUNG dari preview di browser (yang mungkin
// sudah diedit/difilter admin di UI), BUKAN re-parse file Excel lagi --
// supaya editan manual di preview benar-benar dipakai. Semua field yang
// menentukan hak akses (pemilik/area/tipe tempo) tetap SELALU diambil ulang
// dari database per IDPEL di sini (tidak pernah dipercaya dari klien).
// ----------------------------------------------------------------------
if ($action === 'execute') {
    $rowsInput = json_decode($_POST['rows'] ?? '[]', true);
    if (!is_array($rowsInput) || count($rowsInput) === 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada baris yang dipilih untuk diproses.']);
        exit;
    }

    $ownedPemilikList = adjTempoOwnedPemilikList($conn, $current_user_id);
    $ownedPemilikMap = [];
    foreach ($ownedPemilikList as $p) {
        $ownedPemilikMap[$p] = true;
    }

    $totalTxUpdated = 0;
    $totalTxInserted = 0;
    $totalDueDateUpdated = 0;
    $totalDilewati = 0;
    $totalError = 0;
    $errors = [];
    $skipped = [];
    $notes = [];
    $historyByPemilik = [];

    $todayYmd = date('Y-m-d');
    $seenIdpel = [];

    foreach ($rowsInput as $rowInput) {
        if (!is_array($rowInput)) {
            continue;
        }
        $idpel = trim((string) ($rowInput['idpel'] ?? ''));
        if ($idpel === '') {
            continue;
        }
        if (isset($seenIdpel[$idpel])) {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Duplikat IDPEL di data yang dikirim, baris pertama yang dipakai.'];
            continue;
        }
        $seenIdpel[$idpel] = true;

        [$pel, $err] = adjLookupPelangganForAdjust($conn, $idpel, $ownedPemilikMap, $AKSES);
        if ($pel === null) {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => $err];
            continue;
        }

        $tanggalBayarBaru = adjValidYmd((string) ($rowInput['tanggal_bayar_baru'] ?? ''));
        $tanggalTempoBaru = adjValidYmd((string) ($rowInput['tanggal_jatuh_tempo_baru'] ?? ''));
        $nominalInput = adjSafeNumber($rowInput['nominal_bayar_baru'] ?? '');
        $metodeBayarBaru = trim((string) ($rowInput['metode_bayar_baru'] ?? ''));
        $keterangan = trim((string) ($rowInput['keterangan'] ?? ''));

        if ($tanggalBayarBaru === '' && $tanggalTempoBaru === '') {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Tanggal bayar baru & tanggal jatuh tempo baru sama-sama kosong, tidak ada yang diubah.'];
            continue;
        }

        $rowNotes = [];

        // 1. Migrasi tanggal bayar -> UPDATE transaksi BERHASIL TERAKHIR
        //    pelanggan ini kalau sudah ada, INSERT baru hanya kalau pelanggan
        //    ybs memang belum pernah punya transaksi BERHASIL sama sekali.
        if ($tanggalBayarBaru !== '') {
            $tanggalBayarDb = adjTanggalBayarIndo($tanggalBayarBaru);
            $pengunaan = periodeIndonesiaAdj($tanggalBayarBaru);
            $keteranganSuffix = $keterangan !== '' ? ' - ' . $keterangan : '';

            $lastTx = adjFindLastSuccessfulTransaction($conn, $idpel);

            if ($lastTx !== null) {
                $nominalFinal = $nominalInput > 0 ? $nominalInput : (float) $lastTx['HARGA'];
                $metodeFinal = $metodeBayarBaru !== '' ? $metodeBayarBaru : (string) $lastTx['METODE_BAYAR'];
                $cekFinal = trim((string) $lastTx['CEK']) . ' | DIPERBAIKI VIA MIGRASI EXCEL' . $keteranganSuffix;

                $sqlUpdateTx = "UPDATE `transaksi` SET
                    `TANGGALBAYAR` = '" . mysqli_real_escape_string($conn, $tanggalBayarDb) . "',
                    `PENGUNAAN` = '" . mysqli_real_escape_string($conn, $pengunaan) . "',
                    `HARGA` = " . (float) $nominalFinal . ",
                    `METODE_BAYAR` = '" . mysqli_real_escape_string($conn, $metodeFinal) . "',
                    `CEK` = '" . mysqli_real_escape_string($conn, $cekFinal) . "'
                    WHERE id = " . (int) $lastTx['id'];

                if (mysqli_query($conn, $sqlUpdateTx)) {
                    $totalTxUpdated++;
                    $rowNotes[] = "Transaksi terakhir (id {$lastTx['id']}) diubah jadi tanggal bayar $tanggalBayarBaru, nominal Rp " . number_format($nominalFinal, 0, ',', '.') . ".";
                } else {
                    $totalError++;
                    $errors[] = ['idpel' => $idpel, 'reason' => 'Gagal update transaksi terakhir: ' . mysqli_error($conn)];
                }
            } else {
                $nominalFinal = $nominalInput > 0 ? $nominalInput : (float) $pel['HARGA'];
                $bukti = 'MIGRASI-XLS-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . date('YmdHis') . '-' . mt_rand(100, 999);
                $cekKet = 'MIGRASI DATA TRANSAKSI DARI EXCEL' . $keteranganSuffix;

                $sqlInsertTransaksi = "INSERT INTO `transaksi`
                    (`TANGGALBAYAR`, `PENGUNAAN`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`, `METODE_BAYAR`)
                    VALUES
                    ('" . mysqli_real_escape_string($conn, $tanggalBayarDb) . "',
                     '" . mysqli_real_escape_string($conn, $pengunaan) . "',
                     'BERHASIL',
                     '" . mysqli_real_escape_string($conn, $idpel) . "',
                     '" . mysqli_real_escape_string($conn, $pel['NAMA']) . "',
                     '" . mysqli_real_escape_string($conn, $pel['PAKET']) . "',
                     " . (float) $nominalFinal . ",
                     '" . mysqli_real_escape_string($conn, $bukti) . "',
                     '" . mysqli_real_escape_string($conn, $cekKet) . "',
                     '" . mysqli_real_escape_string($conn, $pel['PEMILIK']) . "',
                     '" . mysqli_real_escape_string($conn, $metodeBayarBaru) . "')";

                if (mysqli_query($conn, $sqlInsertTransaksi)) {
                    $totalTxInserted++;
                    $rowNotes[] = "Belum pernah ada transaksi BERHASIL sebelumnya, dibuat transaksi baru (tanggal bayar $tanggalBayarBaru, nominal Rp " . number_format($nominalFinal, 0, ',', '.') . ").";
                } else {
                    $totalError++;
                    $errors[] = ['idpel' => $idpel, 'reason' => 'Gagal insert transaksi: ' . mysqli_error($conn)];
                }
            }
        }

        // 2. Set tanggal jatuh tempo berikutnya (kolom TANGGAL_MONTHVERSARY),
        //    logika SAMA PERSIS dgn proses/update_tanggal_monthversary.php.
        if ($tanggalTempoBaru !== '') {
            $idpelEsc = mysqli_real_escape_string($conn, $idpel);
            $tglEsc = mysqli_real_escape_string($conn, $tanggalTempoBaru);
            $upd = mysqli_query($conn, "UPDATE pelanggan SET TANGGAL_MONTHVERSARY = '$tglEsc' WHERE IDPEL = '$idpelEsc'");
            if ($upd) {
                $totalDueDateUpdated++;
                $rowNotes[] = ($pel['TIPE_TEMPO'] === 'mengikuti_tanggal_bayar')
                    ? "Override jatuh tempo Rolling diset ke $tanggalTempoBaru."
                    : "Anchor jatuh tempo Monthversary diset ke $tanggalTempoBaru.";

                if (strtotime($tanggalTempoBaru) >= strtotime($todayYmd)) {
                    try {
                        $restoreResult = adjRestoreCustomerFromExpired($conn, $pel);
                        if (!empty($restoreResult['log'])) {
                            $rowNotes[] = 'Restore: ' . implode(' ', $restoreResult['log']);
                        }
                    } catch (Throwable $e) {
                        $rowNotes[] = 'Gagal memulihkan MikroTik/RADIUS: ' . $e->getMessage();
                    }
                }
            } else {
                $totalError++;
                $errors[] = ['idpel' => $idpel, 'reason' => 'Gagal update jatuh tempo: ' . mysqli_error($conn)];
            }
        }

        if (!empty($rowNotes)) {
            $notes[] = ['idpel' => $idpel, 'nama' => $pel['NAMA'], 'log' => $rowNotes];

            $pemilik = (string) ($pel['PEMILIK'] ?? '');
            if ($pemilik !== '') {
                $display_name = !empty($asistant_name) ? $asistant_name : $ceknama;
                $historyByPemilik[$pemilik][] = "[ $display_name - " . date('Y-m-d H:i:s') . " ] Migrasi Excel (Penyesuaian Tanggal) $idpel: " . implode(' | ', $rowNotes);
            }
        }
    }

    foreach ($historyByPemilik as $pemilik => $lines) {
        $history_file = "../notifbot/data/history-$pemilik.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) {
            $history = [];
        }
        foreach ($lines as $line) {
            $history[] = $line;
        }
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    echo json_encode([
        'success' => true,
        'total_tx_updated' => $totalTxUpdated,
        'total_tx_inserted' => $totalTxInserted,
        'total_due_date_updated' => $totalDueDateUpdated,
        'total_dilewati' => $totalDilewati,
        'total_error' => $totalError,
        'errors' => $errors,
        'skipped' => $skipped,
        'notes' => $notes,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
