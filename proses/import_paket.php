<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once(__DIR__ . '/../cek-sesi.php');
require_once(__DIR__ . '/../routeros_api.class.php');
require_once(__DIR__ . '/../libs/SimpleXLSX.php');

function redirectWith($status, $message) {
    $url = "../packages.php?statusnotif=" . urlencode($status) . "&text=" . urlencode($message);
    header("Location: " . $url);
    exit;
}

function normalizeKomisi($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '0';
    }
    if (!is_numeric($value)) {
        throw new Exception('Komisi harus angka');
    }
    return (string)$value;
}

function normalizeServerKey($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $value);
}

function findServersForImport($conn, $pemilikRaw, $areaRaw, $brandRaw) {
    $pemilik = normalizeServerKey($pemilikRaw);
    $area = normalizeServerKey($areaRaw);
    $brand = normalizeServerKey($brandRaw);

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    $brandEsc = mysqli_real_escape_string($conn, $brand);

    $queries = [];

    // 1) Format standar: PEMILIK + AREA (atau ALL area)
    if (strtoupper($area) === 'ALL') {
        $queries[] = "SELECT * FROM `server` WHERE LOWER(TRIM(`PEMILIK`)) = LOWER(TRIM('$pemilikEsc'))";
    } else {
        $queries[] = "SELECT * FROM `server` WHERE LOWER(TRIM(`PEMILIK`)) = LOWER(TRIM('$pemilikEsc')) AND LOWER(TRIM(`AREA`)) = LOWER(TRIM('$areaEsc'))";
    }

    // 2) Jika kolom PEMILIK diisi label Product: BRAND-AREA
    if (strpos($pemilik, '-') !== false) {
        $labelParts = explode('-', $pemilik, 2);
        $labelBrand = trim($labelParts[0]);
        $labelArea = trim($labelParts[1] ?? '');
        if ($labelBrand !== '' && $labelArea !== '') {
            $labelBrandEsc = mysqli_real_escape_string($conn, $labelBrand);
            $labelAreaEsc = mysqli_real_escape_string($conn, $labelArea);
            $queries[] = "SELECT * FROM `server` WHERE LOWER(TRIM(`BRAND`)) = LOWER(TRIM('$labelBrandEsc')) AND LOWER(TRIM(`AREA`)) = LOWER(TRIM('$labelAreaEsc'))";
        }
    }

    // 3) Jika brand diisi, pakai BRAND + AREA
    if ($brand !== '') {
        if (strtoupper($area) === 'ALL') {
            $queries[] = "SELECT * FROM `server` WHERE LOWER(TRIM(`BRAND`)) = LOWER(TRIM('$brandEsc'))";
        } else {
            $queries[] = "SELECT * FROM `server` WHERE LOWER(TRIM(`BRAND`)) = LOWER(TRIM('$brandEsc')) AND LOWER(TRIM(`AREA`)) = LOWER(TRIM('$areaEsc'))";
        }
    }

    // 4) Jika PEMILIK ternyata diisi IP server
    if (filter_var($pemilik, FILTER_VALIDATE_IP)) {
        if (strtoupper($area) === 'ALL') {
            $queries[] = "SELECT * FROM `server` WHERE TRIM(`IP`) = '$pemilikEsc'";
        } else {
            $queries[] = "SELECT * FROM `server` WHERE TRIM(`IP`) = '$pemilikEsc' AND LOWER(TRIM(`AREA`)) = LOWER(TRIM('$areaEsc'))";
        }
    }

    foreach ($queries as $sql) {
        $res = mysqli_query($conn, $sql);
        if ($res && mysqli_num_rows($res) > 0) {
            $rows = [];
            while ($serverRow = mysqli_fetch_assoc($res)) {
                $rows[] = $serverRow;
            }
            return $rows;
        }
    }

    return [];
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_FILES['excel_file']) &&
    isset($_FILES['excel_file']['tmp_name']) &&
    is_uploaded_file($_FILES['excel_file']['tmp_name']) &&
    $_FILES['excel_file']['error'] === UPLOAD_ERR_OK
) {
    $fileTmp = $_FILES['excel_file']['tmp_name'];
    $fileName = $_FILES['excel_file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        redirectWith('failed', 'File harus berformat .xlsx');
    }
    try {
        $xlsx = SimpleXLSX::parse($fileTmp);
        if (!$xlsx) {
            redirectWith('failed', 'Gagal membaca file Excel: ' . SimpleXLSX::parseError());
        }
        $rows = $xlsx->rows();
        $skipFirst = isset($_POST['skip_first_row']);
        if ($skipFirst) array_shift($rows);
        $API = new RouterosAPI();
        $successCount = 0;
        $failCount = 0;
        $failMessages = [];
        foreach ($rows as $i => $row) {
            if (empty(array_filter($row, function ($v) { return trim((string)$v) !== ''; }))) {
                continue;
            }

            $profileName = trim($row[0] ?? '');
            $ratelimit   = trim($row[1] ?? '');
            $harga       = trim($row[2] ?? '');
            $local       = trim($row[3] ?? '');
            $remot       = trim($row[4] ?? '');
            $pemilik     = trim($row[5] ?? '');
            $area        = trim($row[6] ?? '');
            $komisi      = trim($row[7] ?? '0');
            $brand       = trim($row[8] ?? '');

            $missing = [];
            if ($profileName === '') $missing[] = 'Nama Paket';
            if ($ratelimit === '') $missing[] = 'Kecepatan';
            if ($harga === '') $missing[] = 'Harga';
            if ($local === '') $missing[] = 'Local';
            if ($remot === '') $missing[] = 'Remote';
            if ($pemilik === '') $missing[] = 'PEMILIK';
            if ($area === '') $missing[] = 'Area';
            if (!empty($missing)) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Field kosong: ".implode(', ', $missing);
                continue;
            }

            if (!is_numeric($harga)) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Harga harus angka.";
                continue;
            }

            try {
                $komisi = normalizeKomisi($komisi);
            } catch (Exception $e) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Komisi harus angka.";
                continue;
            }

            // Cari server dengan beberapa format input (PEMILIK, BRAND-AREA, IP)
            $serverRows = findServersForImport($conn, $pemilik, $area, $brand);
            $foundServer = false;
            foreach ($serverRows as $data) {
                $foundServer = true;
                $usernameip = $data['PEMILIK'];
                $hostip     = $data['IP'];
                $passwordip = $data['PASSWORD'];
                $area2      = $data['AREA'];

                $brandServer = trim($data['BRAND'] ?? '');
                $brandFinal = $brand !== '' ? $brand : $brandServer;

                // Skip jika paket yang sama sudah ada
                $dupSql = "SELECT id FROM paket WHERE PAKET='".mysqli_real_escape_string($conn, $profileName)."' AND PEMILIK='".mysqli_real_escape_string($conn, $usernameip)."' AND AREA='".mysqli_real_escape_string($conn, $area2)."' LIMIT 1";
                $dupRes = mysqli_query($conn, $dupSql);
                if ($dupRes && mysqli_num_rows($dupRes) > 0) {
                    $failCount++;
                    $failMessages[] = "Baris ".($i+2).": Paket sudah ada untuk product/area ini.";
                    continue;
                }

                if ($API->connect($hostip, $usernameip, $passwordip)) {
                    $API->comm("/ip/pool/add", [
                        "name"   => $profileName,
                        "ranges" => $remot
                    ]);
                    $API->comm("/ppp/profile/add", [
                        "name"           => $profileName,
                        "rate-limit"     => $ratelimit,
                        "local-address"  => $local,
                        "remote-address" => $profileName
                    ]);

                    $query2 = "INSERT INTO `paket`( `PAKET`, `KODE`, `KECEPATAN`, `LOCAL`, `REMOTE`, `HARGA`, `komisi`, `AREA`, `PEMILIK`, `BRAND`) VALUES ('".mysqli_real_escape_string($conn, $profileName)."','-', '".mysqli_real_escape_string($conn, $ratelimit)."','".mysqli_real_escape_string($conn, $local)."','".mysqli_real_escape_string($conn, $remot)."','".mysqli_real_escape_string($conn, $harga)."','".mysqli_real_escape_string($conn, $komisi)."','".mysqli_real_escape_string($conn, $area2)."','".mysqli_real_escape_string($conn, $usernameip)."','".mysqli_real_escape_string($conn, $brandFinal)."')";
                    if (mysqli_query($conn, $query2)) {
                        $successCount++;
                        $history_file = "../notifbot/data/history-$ceknama.json";
                        $history = [];
                        if (file_exists($history_file)) {
                            $history = json_decode(file_get_contents($history_file), true);
                        }
                        if (!is_array($history)) $history = [];
                        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan paket pppoe '$profileName'";
                        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                    } else {
                        $failCount++;
                        $failMessages[] = "Baris ".($i+2).": DB gagal: ".mysqli_error($conn);
                    }

                    $API->disconnect();
                } else {
                    $failCount++;
                    $failMessages[] = "Baris ".($i+2).": Gagal konek ke $hostip ($area)";
                }
            }
            if (!$foundServer) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Server tidak ditemukan (PEMILIK/Server Area='$pemilik', AREA='$area', BRAND='$brand').";
            }
        }
        $msg = "Sukses: $successCount baris. Gagal: $failCount baris.";
        if ($failCount > 0) {
            $msg .= " ".implode(" ", $failMessages);
        }
        if ($successCount > 0) {
            // Ada yang berhasil → tampilkan success (catatan gagal tetap ditampilkan di pesan)
            $url = "../packages.php?statusnotif=success&text=" . urlencode($msg);
        } else {
            // Semua gagal → tampilkan error
            $url = "../packages.php?statusnotif=failed&text=" . urlencode($msg);
        }
        header("Location: " . $url);
        exit;
    } catch (Exception $e) {
        redirectWith('failed', 'Gagal membaca file: ' . $e->getMessage());
    }
} else {
    redirectWith('failed', 'Tidak ada file yang diupload atau terjadi kesalahan upload.');
}
