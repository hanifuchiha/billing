<?php
/**
 * getdata/import_excel_pelanggan.php
 * ----------------------------------------------------------------------
 * Backend untuk modal "Import dari Excel" di importcustomerpppoe.php.
 * Mengikuti pola yang sama dengan api_hanif_sync_pelanggan_api.php
 * (Step1 config -> Step2 preview -> Step3 execute), tapi sumber data
 * adalah file Excel yang diupload, bukan API billing eksternal.
 *
 * action=preview
 *   - Terima file Excel (sheet "Pelanggan" + "Transaksi") + pengaturan
 *     Step1 (Server Area/ODP fallback/Sales fallback/Tipe Bayar/Tipe
 *     Tempo fallback).
 *   - Parse & validasi baris, cocokkan PAKET ke tabel `paket` (untuk
 *     auto-isi HARGA), tandai IDPEL yang PAKET-nya belum terdaftar
 *     (missing_packages) supaya admin bisa membuatkan paketnya dulu.
 *   - Simpan hasil parse mentah ke file token sementara supaya bisa
 *     dipakai ulang oleh action=check_packages & action=execute tanpa
 *     perlu upload ulang file.
 *
 * action=check_packages
 *   - Re-resolve data yang sudah tersimpan di token (tanpa re-upload),
 *     dipakai setelah admin membuat paket yang tadinya belum terdaftar.
 *
 * action=execute
 *   - Insert pelanggan + transaksi baru ke database, provisioning
 *     PPPoE secret (MikroTik) & user RADIUS sesuai Mode Auth per baris.
 * ----------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // jangan tampilkan warning ke output JSON

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');

// ----------------------------------------------------------------------
// Guard akses: ADMIN & ASSISTANT boleh (ASSISTANT dibatasi $area_list).
// ----------------------------------------------------------------------
if (!isset($AKSES) || !in_array($AKSES, ['ADMIN', 'ASSISTANT'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit;
}

/**
 * Cek apakah AREA yang diminta ada dalam cakupan akun ASSISTANT ($area_list,
 * string CSV ter-quote yang dibangun cek-sesi.php). ADMIN selalu boleh.
 */
function importAreaAllowed(string $area): bool
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

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

// ----------------------------------------------------------------------
// Direktori token sementara (hasil parse Excel), dilindungi .htaccess
// supaya tidak bisa diakses langsung lewat browser (berisi data pribadi
// pelanggan seperti NIK/NOWA/ALAMAT sebelum sempat diimport).
// ----------------------------------------------------------------------
$importTmpDir = __DIR__ . '/cache/import_excel';
if (!is_dir($importTmpDir)) {
    @mkdir($importTmpDir, 0755, true);
}
$htaccessPath = $importTmpDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    @file_put_contents($htaccessPath, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
}

// Bersih-bersih token basi (>2 jam) setiap kali endpoint ini dipanggil,
// supaya tidak perlu cron terpisah untuk fitur yang jarang dipakai ini.
foreach (glob($importTmpDir . '/*.json') ?: [] as $staleFile) {
    if (time() - filemtime($staleFile) > 7200) {
        @unlink($staleFile);
    }
}

// ========================================================================
// Helper umum (diadaptasi dari api_hanif_sync_pelanggan_api.php)
// ========================================================================

function safeNumber($val): float
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

function normalizeWa(string $whatsapp): string
{
    $whatsapp = trim($whatsapp);
    $whatsappedit = $whatsapp;

    if (!preg_match('/[^+0-9]/', $whatsapp)) {
        if (substr($whatsapp, 0, 2) == '62') {
            $whatsappedit = $whatsapp;
        } elseif (substr($whatsapp, 0, 3) == '+62') {
            $whatsappedit = '62' . substr($whatsapp, 1);
        } elseif (substr($whatsapp, 0, 1) == '0') {
            $whatsappedit = '62' . substr($whatsapp, 1);
        }
    }

    return $whatsappedit;
}

function periodeIndonesia(string $tanggal): string
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
 * Parse tanggal dari kolom TANGGALPASANG/TANGGALBAYAR di file Excel yang
 * diupload user. Diperlukan penanganan khusus (bukan strtotime() polos)
 * karena dua sumber ambiguitas:
 *
 * 1. Kalau Excel otomatis mengenali input sbg tanggal (mis. user ketik
 *    "7/19/2026"), cell disimpan sbg SERIAL NUMBER, bukan teks. Parser
 *    XML hand-rolled di atas tidak baca styles.xml jadi tidak bisa
 *    bedakan angka biasa vs tanggal - dideteksi dari rentang nilai yang
 *    wajar untuk tanggal (kira-kira tahun 1970-2100).
 * 2. Kalau tersimpan sbg teks dgn pemisah "/", PHP strtotime() SELALU
 *    mengasumsikan format Amerika (m/d/Y) untuk pemisah slash - jadi
 *    "24/7/2026" (maksud user: 24 Juli) bakal salah diartikan sbg bulan
 *    ke-24 dan gagal/salah. Di sini bagian mana yg > 12 dianggap TANGGAL,
 *    apapun urutan penulisannya, supaya "7/19/2026" & "24/7/2026" sama2
 *    terbaca benar.
 *
 * Return timestamp Unix, atau false kalau benar-benar tidak bisa diparse.
 */
function parseTanggalExcelImport(string $raw)
{
    $raw = trim($raw);
    if ($raw === '') {
        return false;
    }

    // Serial number Excel murni (epoch 1899-12-30, termasuk bug leap-year 1900).
    if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
        $serial = (float) $raw;
        if ($serial > 25569 && $serial < 73050) { // ~tahun 1970 s/d 2100
            return (int) round(($serial - 25569) * 86400);
        }
    }

    // Format tanggal dgn pemisah slash: D/M/Y atau M/D/Y (ambigu).
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m)) {
        $a = (int) $m[1];
        $b = (int) $m[2];
        $year = (int) $m[3];

        if ($a > 12 && $b <= 12) {
            // mis. 24/7/2026 -> bagian pertama pasti tanggal.
            $day = $a; $month = $b;
        } elseif ($b > 12 && $a <= 12) {
            // mis. 7/19/2026 -> bagian kedua pasti tanggal.
            $month = $a; $day = $b;
        } elseif ($a <= 12 && $b <= 12) {
            // Ambigu (keduanya valid sbg bulan), default format Indonesia D/M/Y.
            $day = $a; $month = $b;
        } else {
            return false;
        }

        return checkdate($month, $day, $year) ? mktime(0, 0, 0, $month, $day, $year) : false;
    }

    return strtotime($raw);
}

/**
 * Format 'Y-m-d' -> teks Indonesia lengkap "Senin, 27 Juli 2026", supaya
 * konsisten dengan format TANGGALBAYAR yang dipakai transaksi normal di
 * seluruh aplikasi (lihat tanggal_indo2() di callbackxendit/callback_xendit.php
 * dkk) - kolom TANGGALBAYAR di tabel `transaksi` menyimpan teks Indonesia
 * ini, BUKAN 'Y-m-d' polos.
 */
function tanggalBayarIndoImport(string $tanggalYmd): string
{
    $hari = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($tanggalYmd);
    if ($ts === false) {
        return $tanggalYmd;
    }
    return $hari[(int) date('N', $ts)] . ', ' . (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

function getServerCreds($conn, string $pemilik, string $area): ?array
{
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc    = mysqli_real_escape_string($conn, $area);

    $q = mysqli_query(
        $conn,
        "SELECT IP, PASSWORD FROM `server` WHERE `PEMILIK` = '$pemilikEsc' AND `AREA` = '$areaEsc' LIMIT 1"
    );
    if ($q && mysqli_num_rows($q) > 0) {
        $row = mysqli_fetch_assoc($q);
        return [
            'ip'       => $row['IP'],
            'user'     => $pemilik,
            'password' => $row['PASSWORD'],
            // Kolom TEMPO berada pada data pelanggan, bukan tabel server pada
            // skema saat ini. Monthversary memakai TANGGAL_MONTHVERSARY dan
            // Fixed Due Date membaca konfigurasi reminder, jadi nilai kosong
            // adalah fallback yang aman untuk proses import pelanggan baru.
            'tempo'    => '',
        ];
    }
    return null;
}

function checkSecretExists(RouterosAPI $api, string $idpel): bool
{
    $existing = $api->comm("/ppp/secret/print", ["?name" => $idpel]);
    return !empty($existing);
}

const IMPORT_RADIUS_USERS_FILE = "/etc/freeradius/3.0/users";

function radiusUserExists(string $idpel): bool
{
    if (!file_exists(IMPORT_RADIUS_USERS_FILE)) {
        return false;
    }
    $fileContent = @file_get_contents(IMPORT_RADIUS_USERS_FILE);
    if ($fileContent === false) {
        return false;
    }
    return strpos($fileContent, "$idpel Cleartext-Password") !== false;
}

function radiusAppendUser(string $idpel, string $password, string $paket): bool
{
    $entry  = "$idpel Cleartext-Password := \"$password\"\n";
    $entry .= "\tMikrotik-Group := \"$paket\"\n\n";
    $written = @file_put_contents(IMPORT_RADIUS_USERS_FILE, $entry, FILE_APPEND);
    return $written !== false;
}

function getFreeradiusPIDImport(): int
{
    $pid = trim((string) @shell_exec("pidof freeradius"));
    if ($pid !== '') {
        return (int) $pid;
    }
    $output = (string) @shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int) $m[1];
    }
    return 0;
}

function radiusRestartIfNeededImport(bool $anyRadiusUserAdded): void
{
    if (!$anyRadiusUserAdded) {
        return;
    }

    $debugFile = "/var/log/freeradius/debug-radius-web.log";

    $pid = getFreeradiusPIDImport();
    if ($pid > 0) {
        @shell_exec('sudo systemctl stop freeradius');
        @shell_exec("sudo kill -9 $pid");
    }

    if (file_exists($debugFile)) {
        @shell_exec("sudo rm -f $debugFile");
    }
    @shell_exec("sudo touch $debugFile");
    @shell_exec("sudo chmod 666 $debugFile");
    @shell_exec("sudo freeradius -X > $debugFile 2>&1 &");
}

// ========================================================================
// XLSX helpers (hand-rolled OOXML reader - server tidak punya
// PhpSpreadsheet, konsisten dengan pola yang sudah dipakai di
// importcustomerpppoe.php versi lama & fungsi generateExcelTemplate()-nya).
// ========================================================================

function excelColumnToIndexImport($column): int
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

function loadSharedStringsImport(ZipArchive $zip): array
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

/**
 * Baca xl/workbook.xml + xl/_rels/workbook.xml.rels supaya bisa memetakan
 * NAMA sheet (mis. "Pelanggan") ke path worksheet XML yang benar di dalam
 * zip. TIDAK boleh asumsikan "sheet1.xml" = sheet pertama yang terdaftar,
 * karena Excel/LibreOffice bisa menulis ulang urutan/nama part internal
 * saat file di-save ulang oleh admin.
 */
function loadSheetNameMapImport(ZipArchive $zip): array
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

/**
 * Parse satu worksheet XML menjadi array baris (tiap baris = array
 * sparse colIndex => value string).
 */
function parseWorksheetRowsImport(ZipArchive $zip, string $path, array $sharedStrings): array
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
            $col = excelColumnToIndexImport($cellRefClean);

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

/**
 * Ubah baris mentah (array sparse colIndex => value, baris pertama =
 * header) menjadi list of assoc rows [HEADER_NAME => value, ...], plus
 * '_row_number' (nomor baris Excel asli, dipakai utk pesan error).
 * Return null jika salah satu $requiredHeaders tidak ditemukan di header.
 */
function rowsToAssocImport(array $rawRows, array $requiredHeaders): array
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
            continue; // baris kosong
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
// Token helpers (state hasil parse Excel, dipakai bersama preview /
// check_packages / execute supaya tidak perlu upload ulang file).
// ========================================================================

function importTokenPath(string $dir, string $token): string
{
    $safeToken = preg_replace('/[^a-f0-9]/', '', $token);
    return $dir . '/' . $safeToken . '.json';
}

function importSaveToken(string $path, array $data): void
{
    file_put_contents($path, json_encode($data));
}

function importLoadToken(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ========================================================================
// Resolusi paket (cocokkan nama PAKET dari file ke tabel `paket`, scoped
// ke PEMILIK+AREA yang dipilih di Step 1 - sama seperti packages.php
// mendaftar paket per PEMILIK+AREA).
// ========================================================================

function resolvePaketRow($conn, string $pemilik, string $area, string $paketName, array &$cache): ?array
{
    $key = strtolower(trim($paketName));
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $paketEsc = mysqli_real_escape_string($conn, trim($paketName));
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);

    $q = mysqli_query(
        $conn,
        "SELECT id, PAKET, HARGA FROM `paket`
         WHERE LOWER(TRIM(PAKET)) = LOWER('$paketEsc') AND PEMILIK = '$pemilikEsc' AND AREA = '$areaEsc'
         LIMIT 1"
    );
    $row = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : null;
    $cache[$key] = $row;
    return $row;
}

// ========================================================================
// Resolver inti: dari baris mentah (parsed Excel) + konteks Step1,
// hasilkan payload preview (data siap-import, baris terblokir, paket
// yang belum terdaftar, dsb). Dipakai oleh action=preview,
// action=check_packages, DAN action=execute (defensive re-resolve).
// ========================================================================

function resolveImportPreview($conn, array $pelangganRows, array $transaksiByIdpel, array $step1, ?RouterosAPI $routerApi): array
{
    $pemilik = $step1['pemilik'];
    $area = $step1['area'];

    $existingIdpel = [];
    $resExisting = mysqli_query($conn, "SELECT IDPEL FROM `pelanggan`");
    if ($resExisting) {
        while ($rowEx = mysqli_fetch_assoc($resExisting)) {
            $existingIdpel[trim((string) $rowEx['IDPEL'])] = true;
        }
    }

    $paketCache = [];
    $seenInFile = [];
    $data = [];
    $blockedRows = [];
    $missingPackagesMap = [];
    $duplicateInDbCount = 0;
    $duplicateInFile = [];

    foreach ($pelangganRows as $r) {
        $rowNumber = $r['_row_number'] ?? 0;
        $idpel = trim((string) ($r['IDPEL'] ?? ''));

        if ($idpel === '') {
            continue; // baris kosong, lewati diam-diam
        }
        // Lewati baris contoh dari template (sentinel sama seperti versi lama).
        if (stripos($idpel, 'PEL001') !== false || stripos((string) ($r['NAMA'] ?? ''), 'John Doe') !== false) {
            continue;
        }

        if (isset($seenInFile[$idpel])) {
            $duplicateInFile[] = $idpel;
            $blockedRows[] = [
                'row' => $rowNumber, 'idpel' => $idpel, 'nama' => $r['NAMA'] ?? '',
                'reason' => 'Duplikat IDPEL di dalam file, baris pertama yang dipakai.',
            ];
            continue;
        }
        $seenInFile[$idpel] = true;

        $reasons = [];
        if (preg_match('/\s/', $idpel)) {
            $reasons[] = 'IDPEL mengandung spasi';
        }

        $nama = trim((string) ($r['NAMA'] ?? ''));
        $paketName = trim((string) ($r['PAKET'] ?? ''));
        $alamat = trim((string) ($r['ALAMAT'] ?? ''));
        $nowa = trim((string) ($r['NOWA'] ?? ''));
        $tanggalPasangRaw = trim((string) ($r['TANGGALPASANG'] ?? ''));

        $missingFields = [];
        if ($nama === '') $missingFields[] = 'NAMA';
        if ($paketName === '') $missingFields[] = 'PAKET';
        if ($alamat === '') $missingFields[] = 'ALAMAT';
        if ($nowa === '') $missingFields[] = 'NOWA';
        if ($tanggalPasangRaw === '') $missingFields[] = 'TANGGALPASANG';
        if (!empty($missingFields)) {
            $reasons[] = 'Kolom wajib kosong: ' . implode(', ', $missingFields);
        }

        if (!empty($reasons)) {
            $blockedRows[] = ['row' => $rowNumber, 'idpel' => $idpel, 'nama' => $nama, 'reason' => implode('; ', $reasons)];
            continue;
        }

        if (isset($existingIdpel[$idpel])) {
            $duplicateInDbCount++;
            continue;
        }

        $paketRow = resolvePaketRow($conn, $pemilik, $area, $paketName, $paketCache);
        if ($paketRow === null) {
            $missingPackagesMap[$paketName][] = $idpel;
            continue;
        }
        $harga = (float) $paketRow['HARGA'];

        $warnings = [];

        $tipeBayar = trim((string) ($r['TIPE_BAYAR'] ?? ''));
        if (!in_array($tipeBayar, ['prabayar', 'pascabayar'], true)) {
            if ($tipeBayar !== '') {
                $warnings[] = 'TIPE_BAYAR tidak valid, pakai default Step 1';
            }
            $tipeBayar = $step1['tipe_bayar'];
        }

        $tipeTempo = trim((string) ($r['TIPE_TEMPO'] ?? ''));
        if (!in_array($tipeTempo, ['mengikuti_tanggal_bayar', 'mengikuti_tanggal_tempo', 'monthversary'], true)) {
            if ($tipeTempo !== '') {
                $warnings[] = 'TIPE_TEMPO tidak valid, pakai default Step 1';
            }
            $tipeTempo = $step1['tipe_tempo'];
        }

        $tglParsed = parseTanggalExcelImport($tanggalPasangRaw);
        if ($tglParsed === false) {
            $tglParsed = time();
            $warnings[] = 'TANGGALPASANG tidak valid, pakai tanggal hari ini';
        }
        $tanggalPasang = date('Y-m-d', $tglParsed);

        $odpRaw = trim((string) ($r['ODP'] ?? ''));
        $odpFinal = $odpRaw !== '' ? $odpRaw : $step1['odp'];
        $odpSource = $odpRaw !== '' ? 'file' : 'fallback';

        $salesRaw = trim((string) ($r['SALES'] ?? ''));
        $salesFinal = $salesRaw !== '' ? $salesRaw : $step1['sales'];

        $secretStatus = 'unknown';
        if ($routerApi !== null) {
            try {
                $secretStatus = checkSecretExists($routerApi, $idpel) ? 'exists' : 'not_exists';
            } catch (Throwable $t) {
                $secretStatus = 'unknown';
            }
        }
        $radiusStatus = radiusUserExists($idpel) ? 'exists' : 'not_exists';

        $txList = $transaksiByIdpel[$idpel] ?? [];
        $txCount = 0;
        $txTotal = 0.0;
        foreach ($txList as $t) {
            $txCount++;
            $txTotal += safeNumber($t['NOMINAL'] ?? 0);
        }

        $data[] = [
            'row' => $rowNumber,
            'IDPEL' => $idpel,
            'PASSWORD_FILE' => trim((string) ($r['PASSWORD'] ?? '')),
            'NIK' => trim((string) ($r['NIK'] ?? '')),
            'NAMA' => $nama,
            'TIPE_BAYAR' => $tipeBayar,
            'TIPE_TEMPO' => $tipeTempo,
            'PAKET' => $paketRow['PAKET'],
            'HARGA' => $harga,
            'ALAMAT' => $alamat,
            'NOWA' => $nowa,
            'TANGGALPASANG' => $tanggalPasang,
            'EMAIL' => trim((string) ($r['EMAIL'] ?? '')),
            'ODP' => $odpRaw,
            'odp_final' => $odpFinal,
            'odp_source' => $odpSource,
            'TIKOR' => trim((string) ($r['TIKOR'] ?? '')),
            'sales' => $salesRaw,
            'sales_final' => $salesFinal,
            'provinsi' => trim((string) ($r['PROVINSI'] ?? '')),
            'kabupaten' => trim((string) ($r['KABUPATEN'] ?? '')),
            'kecamatan' => trim((string) ($r['KECAMATAN'] ?? '')),
            'kelurahan' => trim((string) ($r['KELURAHAN'] ?? '')),
            'rw' => trim((string) ($r['RW'] ?? '')),
            'rt' => trim((string) ($r['RT'] ?? '')),
            'secret_status' => $secretStatus,
            'radius_status' => $radiusStatus,
            'total_transaksi' => $txCount,
            'total_nominal' => $txTotal,
            'warnings' => $warnings,
        ];
    }

    $missingPackages = [];
    foreach ($missingPackagesMap as $name => $idpels) {
        $missingPackages[] = ['paket' => $name, 'affected_idpel' => $idpels, 'count' => count($idpels)];
    }

    $orphanTransaksiIdpel = [];
    foreach ($transaksiByIdpel as $idpelTx => $rowsTx) {
        if (!isset($seenInFile[$idpelTx])) {
            $orphanTransaksiIdpel[] = $idpelTx;
        }
    }

    return [
        'data' => $data,
        'blocked_rows' => $blockedRows,
        'missing_packages' => $missingPackages,
        'duplicate_in_db_count' => $duplicateInDbCount,
        'duplicate_in_file' => array_values(array_unique($duplicateInFile)),
        'orphan_transaksi_idpel' => array_values(array_unique($orphanTransaksiIdpel)),
    ];
}

/**
 * Buka koneksi RouterOS sekali (reuse), sama seperti pola di
 * api_hanif_sync_pelanggan_api.php - dipanggil di preview/check_packages/execute.
 */
function connectRouterForImport($conn, string $pemilik, string $area): array
{
    $creds = getServerCreds($conn, $pemilik, $area);
    if (!$creds) {
        return [null, 'Data server (IP/PASSWORD) tidak ditemukan untuk PEMILIK/AREA terpilih.'];
    }
    try {
        $api = new RouterosAPI();
        $connected = @$api->connect($creds['ip'], $creds['user'], $creds['password']);
        if (!$connected) {
            return [null, 'Gagal konek ke MikroTik (' . $creds['ip'] . ').'];
        }
        return [$api, ''];
    } catch (Throwable $t) {
        return [null, 'Error koneksi MikroTik: ' . $t->getMessage()];
    }
}

// ----------------------------------------------------------------------
// ACTION: preview
// ----------------------------------------------------------------------
if ($action === 'preview') {
    if (!isset($_FILES['excel_file'])) {
        echo json_encode(['success' => false, 'message' => 'File Excel wajib diupload.']);
        exit;
    }

    $pemilik   = trim($_POST['pemilik'] ?? '');
    $area      = trim($_POST['area'] ?? '');
    $brand     = trim($_POST['brand'] ?? '');
    $odp       = trim($_POST['odp'] ?? '');
    $sales     = trim($_POST['sales'] ?? '-');
    $tipeBayar = trim($_POST['tipe_bayar'] ?? 'pascabayar');
    $tipeTempo = trim($_POST['tipe_tempo'] ?? 'mengikuti_tanggal_tempo');

    if ($pemilik === '' || $area === '' || $odp === '') {
        echo json_encode(['success' => false, 'message' => 'Server Area dan ODP wajib dipilih sebelum cek data.']);
        exit;
    }
    if (!importAreaAllowed($area)) {
        echo json_encode(['success' => false, 'message' => 'Area di luar cakupan akun Anda.']);
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

    $sharedStrings = loadSharedStringsImport($zip);
    $sheetMap = loadSheetNameMapImport($zip);

    if (!isset($sheetMap['Pelanggan'])) {
        $zip->close();
        echo json_encode(['success' => false, 'message' => "Sheet 'Pelanggan' tidak ditemukan di file. Gunakan template terbaru (tombol Download Template)."]);
        exit;
    }

    $pelangganRaw = parseWorksheetRowsImport($zip, $sheetMap['Pelanggan'], $sharedStrings);
    // Nama sheet transaksi berganti jadi "Transaksi Berhasil Terbaru" -- nama lama
    // "Transaksi" tetap didukung supaya template lama yang sudah terlanjur
    // diunduh pengguna masih bisa dipakai.
    $transaksiSheetName = isset($sheetMap['Transaksi Berhasil Terbaru']) ? 'Transaksi Berhasil Terbaru' : 'Transaksi';
    $transaksiRaw = isset($sheetMap[$transaksiSheetName]) ? parseWorksheetRowsImport($zip, $sheetMap[$transaksiSheetName], $sharedStrings) : [];
    $zip->close();

    $pelangganParsed = rowsToAssocImport($pelangganRaw, ['IDPEL', 'NAMA', 'PAKET', 'ALAMAT', 'NOWA', 'TANGGALPASANG']);
    if ($pelangganParsed['error']) {
        echo json_encode(['success' => false, 'message' => "Sheet 'Pelanggan': " . $pelangganParsed['error']]);
        exit;
    }

    $transaksiParsed = ['rows' => []];
    $txSkipped = [];
    if (!empty($transaksiRaw)) {
        $transaksiParsed = rowsToAssocImport($transaksiRaw, ['IDPEL', 'TANGGALBAYAR', 'NOMINAL']);
        if ($transaksiParsed['error']) {
            echo json_encode(['success' => false, 'message' => "Sheet '$transaksiSheetName': " . $transaksiParsed['error']]);
            exit;
        }
    }

    $transaksiByIdpel = [];
    foreach ($transaksiParsed['rows'] as $t) {
        $tIdpel = trim((string) ($t['IDPEL'] ?? ''));
        if ($tIdpel === '' || stripos($tIdpel, 'PEL001') !== false) {
            continue;
        }
        $tglOk = trim((string) ($t['TANGGALBAYAR'] ?? '')) !== '';
        $nomOk = trim((string) ($t['NOMINAL'] ?? '')) !== '';
        if (!$tglOk || !$nomOk) {
            $txSkipped[] = ['idpel' => $tIdpel, 'row' => $t['_row_number'], 'reason' => 'TANGGALBAYAR/NOMINAL kosong'];
            continue;
        }
        $transaksiByIdpel[$tIdpel][] = $t;
    }

    $step1 = [
        'pemilik' => $pemilik, 'area' => $area, 'brand' => $brand, 'odp' => $odp,
        'sales' => $sales, 'tipe_bayar' => $tipeBayar, 'tipe_tempo' => $tipeTempo,
    ];

    [$routerApi, $routerConnectError] = connectRouterForImport($conn, $pemilik, $area);
    $resolved = resolveImportPreview($conn, $pelangganParsed['rows'], $transaksiByIdpel, $step1, $routerApi);
    if ($routerApi !== null) {
        try { $routerApi->disconnect(); } catch (Throwable $t) { /* ignore */ }
    }

    $token = bin2hex(random_bytes(16));
    importSaveToken(importTokenPath($importTmpDir, $token), [
        'step1' => $step1,
        'pelanggan_rows_raw' => $pelangganParsed['rows'],
        'transaksi_by_idpel_raw' => $transaksiByIdpel,
        'admin_user_id' => $current_user_id ?? null,
        'akses' => $AKSES,
        'created_at' => time(),
    ]);

    echo json_encode(array_merge(
        ['success' => true, 'token' => $token, 'router_connect_error' => $routerConnectError, 'tx_skipped' => $txSkipped],
        $resolved,
        ['total_rows' => count($resolved['data'])]
    ));
    exit;
}

// ----------------------------------------------------------------------
// ACTION: check_packages (re-validasi tanpa upload ulang, dipakai setelah
// admin membuat paket yang tadinya belum terdaftar)
// ----------------------------------------------------------------------
if ($action === 'check_packages') {
    $token = trim($_POST['token'] ?? '');
    $stored = importLoadToken(importTokenPath($importTmpDir, $token));
    if (!$stored) {
        echo json_encode(['success' => false, 'message' => 'Sesi import sudah kadaluarsa, silakan upload ulang file.']);
        exit;
    }
    $step1 = $stored['step1'];
    if (!importAreaAllowed($step1['area'])) {
        echo json_encode(['success' => false, 'message' => 'Area di luar cakupan akun Anda.']);
        exit;
    }

    [$routerApi, $routerConnectError] = connectRouterForImport($conn, $step1['pemilik'], $step1['area']);
    $resolved = resolveImportPreview($conn, $stored['pelanggan_rows_raw'], $stored['transaksi_by_idpel_raw'], $step1, $routerApi);
    if ($routerApi !== null) {
        try { $routerApi->disconnect(); } catch (Throwable $t) { /* ignore */ }
    }

    echo json_encode(array_merge(
        ['success' => true, 'token' => $token, 'router_connect_error' => $routerConnectError],
        $resolved,
        ['total_rows' => count($resolved['data'])]
    ));
    exit;
}

// ----------------------------------------------------------------------
// ACTION: execute
// ----------------------------------------------------------------------
if ($action === 'execute') {
    $token = trim($_POST['token'] ?? '');
    $stored = importLoadToken(importTokenPath($importTmpDir, $token));
    if (!$stored) {
        echo json_encode(['success' => false, 'message' => 'Sesi import sudah kadaluarsa, silakan upload ulang file.']);
        exit;
    }
    $step1 = $stored['step1'];
    if (!importAreaAllowed($step1['area'])) {
        echo json_encode(['success' => false, 'message' => 'Area di luar cakupan akun Anda.']);
        exit;
    }

    $idpelList = json_decode($_POST['idpel_list'] ?? '[]', true);
    if (!is_array($idpelList) || count($idpelList) === 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada IDPEL yang dipilih untuk diimport.']);
        exit;
    }
    $idpelPasswordMap = json_decode($_POST['idpel_password'] ?? '{}', true);
    if (!is_array($idpelPasswordMap)) $idpelPasswordMap = [];
    $idpelAuthModeMap = json_decode($_POST['idpel_authmode'] ?? '{}', true);
    if (!is_array($idpelAuthModeMap)) $idpelAuthModeMap = [];

    [$routerApi, $routerConnectError] = connectRouterForImport($conn, $step1['pemilik'], $step1['area']);

    // Re-resolve defensif (bukan sekadar percaya data dari frontend) supaya
    // HARGA/ODP/status MikroTik/RADIUS yang dipakai saat insert adalah yang
    // paling baru, dan supaya IDPEL yang sudah keburu ada di DB / paketnya
    // dihapus di antara preview & execute otomatis dilewati.
    $resolved = resolveImportPreview($conn, $stored['pelanggan_rows_raw'], $stored['transaksi_by_idpel_raw'], $step1, $routerApi);
    $rowsByIdpel = [];
    foreach ($resolved['data'] as $r) {
        $rowsByIdpel[$r['IDPEL']] = $r;
    }

    $creds = getServerCreds($conn, $step1['pemilik'], $step1['area']);
    $tempo = $creds['tempo'] ?? '';
    $pemilik = $step1['pemilik'];
    $area = $step1['area'];
    $brand = $step1['brand'];

    $totalBerhasil = 0;
    $totalDilewati = 0;
    $totalError = 0;
    $errors = [];
    $skipped = [];
    $notes = [];
    $anyRadiusUserAdded = false;

    foreach ($idpelList as $idpel) {
        $idpel = trim((string) $idpel);
        if ($idpel === '') {
            continue;
        }

        if (!isset($rowsByIdpel[$idpel])) {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Data tidak lagi valid saat eksekusi (sudah ada di database, paket dihapus, atau tidak ditemukan di file).'];
            continue;
        }
        $row = $rowsByIdpel[$idpel];

        $authModeRaw = trim((string) ($idpelAuthModeMap[$idpel] ?? ''));
        $authMode = in_array($authModeRaw, ['API MODE', 'RADIUS MODE', 'MULTI MODE'], true) ? $authModeRaw : 'MULTI MODE';

        $passwordPlain = trim((string) ($idpelPasswordMap[$idpel] ?? ''));
        if ($passwordPlain === '') {
            $passwordPlain = $row['PASSWORD_FILE'];
        }
        if ($passwordPlain === '') {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Password PPPoE tidak diisi -- dilewati karena password wajib diisi untuk semua pelanggan.'];
            continue;
        }

        $nama = mysqli_real_escape_string($conn, $row['NAMA']);
        $nowa = mysqli_real_escape_string($conn, normalizeWa($row['NOWA']));
        $paket = mysqli_real_escape_string($conn, $row['PAKET']);
        $password = mysqli_real_escape_string($conn, $passwordPlain);

        // 1. MikroTik secret (API MODE / MULTI MODE)
        if ($authMode === 'API MODE' || $authMode === 'MULTI MODE') {
            $secretInfo = 'Tidak dicek (MikroTik tidak terhubung).';
            if ($routerApi !== null) {
                try {
                    if (checkSecretExists($routerApi, $idpel)) {
                        $secretInfo = 'Secret sudah ada di MikroTik, tidak dibuat ulang.';
                    } else {
                        $routerApi->comm("/ppp/secret/add", [
                            "name"     => $idpel,
                            "password" => $passwordPlain,
                            "profile"  => $row['PAKET'],
                            "service"  => "any",
                            "comment"  => "IMPORT EXCEL " . $row['NAMA'] . "-" . $row['NOWA'] . "-" . $row['TANGGALPASANG']
                        ]);
                        $secretInfo = 'Secret baru berhasil dibuat di MikroTik.';
                    }
                } catch (Throwable $t) {
                    $secretInfo = 'Gagal cek/buat secret: ' . $t->getMessage();
                }
            }
        } else {
            $secretInfo = 'Dilewati (Mode Auth = ' . $authMode . ').';
        }

        // 2. RADIUS user (RADIUS MODE / MULTI MODE)
        if ($authMode === 'RADIUS MODE' || $authMode === 'MULTI MODE') {
            $radiusInfo = 'Tidak diproses.';
            try {
                if (radiusUserExists($idpel)) {
                    $radiusInfo = 'User sudah ada di RADIUS, tidak ditambahkan ulang.';
                } else {
                    $appended = radiusAppendUser($idpel, $passwordPlain, $row['PAKET']);
                    if ($appended) {
                        $radiusInfo = 'User baru berhasil ditambahkan ke RADIUS (menunggu restart FreeRADIUS di akhir proses).';
                        $anyRadiusUserAdded = true;
                    } else {
                        $radiusInfo = 'Gagal menulis ke file users RADIUS (cek permission file/folder).';
                    }
                }
            } catch (Throwable $t) {
                $radiusInfo = 'Gagal cek/tambah user RADIUS: ' . $t->getMessage();
            }
        } else {
            $radiusInfo = 'Dilewati (Mode Auth = ' . $authMode . ').';
        }

        $notes[] = ['idpel' => $idpel, 'mikrotik' => $secretInfo, 'radius' => $radiusInfo];

        // Mode tempo "monthversary": anchor awal dikunci ke tanggal pasang.
        // Untuk prabayar, cek_tagihan_harian.php akan mengunci ulang anchor
        // ini ke tanggal transaksi BERHASIL pertama begitu pelanggan bayar
        // (mis. dari sheet "Transaksi Berhasil Terbaru" yang ikut diimport).
        $tanggalMonthversarySql = ($row['TIPE_TEMPO'] === 'monthversary')
            ? "'" . mysqli_real_escape_string($conn, $row['TANGGALPASANG']) . "'"
            : 'NULL';

        // 3. Insert pelanggan
        $sqlInsertPelanggan = "INSERT INTO `pelanggan`
            (`PASSWORD`, `IDPEL`, `NIK`, `NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`,
             `TANGGALPASANG`, `NOWA`, `EMAIL`, `ALAMAT`, `TEMPO`, `PEMILIK`, `MODE`, `ODP`, `AREA`,
             `TIKOR`, `sales`, `BRAND`, `provinsi`, `kabupaten`, `kecamatan`, `kelurahan`, `rw`, `rt`,
             `TANGGAL_MONTHVERSARY`)
            VALUES
            ('$password', '" . mysqli_real_escape_string($conn, $idpel) . "',
             '" . mysqli_real_escape_string($conn, $row['NIK']) . "', '$nama',
             '" . mysqli_real_escape_string($conn, $row['TIPE_BAYAR']) . "',
             '" . mysqli_real_escape_string($conn, $row['TIPE_TEMPO']) . "',
             '$paket', " . (float) $row['HARGA'] . ",
             '" . mysqli_real_escape_string($conn, $row['TANGGALPASANG']) . "',
             '$nowa', '" . mysqli_real_escape_string($conn, $row['EMAIL']) . "', '" . mysqli_real_escape_string($conn, $row['ALAMAT']) . "',
             '" . mysqli_real_escape_string($conn, $tempo) . "',
             '" . mysqli_real_escape_string($conn, $pemilik) . "',
             '" . mysqli_real_escape_string($conn, $authMode) . "',
             '" . mysqli_real_escape_string($conn, $row['odp_final']) . "',
             '" . mysqli_real_escape_string($conn, $area) . "',
             '" . mysqli_real_escape_string($conn, $row['TIKOR']) . "',
             '" . mysqli_real_escape_string($conn, $row['sales_final']) . "',
             '" . mysqli_real_escape_string($conn, $brand) . "',
             '" . mysqli_real_escape_string($conn, $row['provinsi']) . "',
             '" . mysqli_real_escape_string($conn, $row['kabupaten']) . "',
             '" . mysqli_real_escape_string($conn, $row['kecamatan']) . "',
             '" . mysqli_real_escape_string($conn, $row['kelurahan']) . "',
             '" . mysqli_real_escape_string($conn, $row['rw']) . "',
             '" . mysqli_real_escape_string($conn, $row['rt']) . "',
             $tanggalMonthversarySql)";

        if (!mysqli_query($conn, $sqlInsertPelanggan)) {
            $totalError++;
            $errors[] = ['idpel' => $idpel, 'reason' => 'Gagal insert pelanggan: ' . mysqli_error($conn)];
            continue;
        }

        // 4. Insert transaksi (Sheet "Transaksi" milik IDPEL ini)
        $txList = $stored['transaksi_by_idpel_raw'][$idpel] ?? [];
        $txInserted = 0;
        foreach ($txList as $t) {
            $tglRaw = trim((string) ($t['TANGGALBAYAR'] ?? date('Y-m-d')));
            $tglParsed = parseTanggalExcelImport($tglRaw);
            $tanggalBayar = $tglParsed !== false ? date('Y-m-d', $tglParsed) : date('Y-m-d');
            $pengunaanFile = trim((string) ($t['PERIODE'] ?? ''));
            $pengunaan = $pengunaanFile !== '' ? $pengunaanFile : periodeIndonesia($tanggalBayar);
            $nominal = safeNumber($t['NOMINAL'] ?? 0);
            $bank = (string) ($t['METODE_BAYAR'] ?? '');
            $keterangan = (string) ($t['KETERANGAN'] ?? '');
            $buktiFile = trim((string) ($t['BUKTI'] ?? ''));
            $bukti = $buktiFile !== '' ? $buktiFile : ('IMPORT-XLS-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . ($tglParsed !== false ? date('YmdHis', $tglParsed) : date('YmdHis')) . '-' . $txInserted);
            $cekKet = trim('IMPORT DARI EXCEL' . ($keterangan !== '' ? ' - ' . $keterangan : ''));

            $statusFile = strtoupper(trim((string) ($t['STATUS'] ?? '')));
            $statusFinal = in_array($statusFile, ['BERHASIL', 'PENAGIHAN'], true) ? $statusFile : 'BERHASIL';

            // Kolom TANGGALBAYAR di tabel `transaksi` menyimpan teks Indonesia
            // lengkap ("Senin, 27 Juli 2026"), bukan 'Y-m-d' - samakan dengan
            // transaksi normal (callback pembayaran, dll), bukan cuma dipakai
            // untuk sorting internal import ini saja.
            $tanggalBayarDb = tanggalBayarIndoImport($tanggalBayar);

            $sqlInsertTransaksi = "INSERT INTO `transaksi`
                (`TANGGALBAYAR`, `PENGUNAAN`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`, `METODE_BAYAR`)
                VALUES
                ('" . mysqli_real_escape_string($conn, $tanggalBayarDb) . "',
                 '" . mysqli_real_escape_string($conn, $pengunaan) . "',
                 '" . mysqli_real_escape_string($conn, $statusFinal) . "',
                 '" . mysqli_real_escape_string($conn, $idpel) . "',
                 '$nama', '$paket', " . (float) $nominal . ",
                 '" . mysqli_real_escape_string($conn, $bukti) . "',
                 '" . mysqli_real_escape_string($conn, $cekKet) . "',
                 '" . mysqli_real_escape_string($conn, $pemilik) . "',
                 '" . mysqli_real_escape_string($conn, $bank) . "')";

            if (mysqli_query($conn, $sqlInsertTransaksi)) {
                $txInserted++;
            }
        }

        $totalBerhasil++;
    }

    if ($routerApi !== null) {
        try { $routerApi->disconnect(); } catch (Throwable $t) { /* ignore */ }
    }

    $radiusRestarted = false;
    if ($anyRadiusUserAdded) {
        try {
            radiusRestartIfNeededImport(true);
            $radiusRestarted = true;
        } catch (Throwable $t) {
            // tidak fatal - entry sudah tersimpan di file users
        }
    }

    // Token bersifat sekali pakai - hapus setelah execute selesai.
    @unlink(importTokenPath($importTmpDir, $token));

    echo json_encode([
        'success' => true,
        'total_berhasil' => $totalBerhasil,
        'total_dilewati' => $totalDilewati,
        'total_error' => $totalError,
        'errors' => $errors,
        'skipped' => $skipped,
        'notes' => $notes,
        'radius_restarted' => $radiusRestarted,
        'router_connect_error' => $routerConnectError,
    ]);
    exit;
}

// ----------------------------------------------------------------------
echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);
