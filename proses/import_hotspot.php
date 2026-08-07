<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../cek-sesi.php');
require_once(__DIR__ . '/../routeros_api.class.php');
require_once(__DIR__ . '/../libs/SimpleXLSX.php');

function redirectWith($status, $message) {
    $url = "../packageshotspot.php?statusnotif=" . urlencode($status) . "&text=" . urlencode($message);
    header("Location: " . $url);
    exit;
}

function normalizeNumericOrZero($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '0';
    }
    if (!is_numeric($value)) {
        throw new Exception('Nilai harus berupa angka');
    }
    return (string)$value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $fileTmp = $_FILES['excel_file']['tmp_name'];
    $fileName = $_FILES['excel_file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        redirectWith('gagal', 'File harus berformat .xlsx (hanya .xlsx yang didukung tanpa Composer)');
    }
    try {
        $xlsx = SimpleXLSX::parse($fileTmp);
        if (!$xlsx) {
            redirectWith('gagal', 'Gagal membaca file Excel: ' . SimpleXLSX::parseError());
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

            // Urutan baru: Nama Paket | Rate Limit | Uptime | Harga | Komisi | Brand | Area | Pemilik | komisi_nominal
            $profileName    = trim($row[0] ?? '');
            $ratelimit      = trim($row[1] ?? '');
            $uptime         = trim($row[2] ?? '');
            $harga          = trim($row[3] ?? '');
            $komisi         = trim($row[4] ?? '');
            $brand          = trim($row[5] ?? '');
            $area           = trim($row[6] ?? '');
            $pemilik_manual = trim($row[7] ?? '');
            $komisi_nominal = trim($row[8] ?? '');

            $missing = [];
            if ($profileName === '') $missing[] = 'Nama Paket';
            if ($ratelimit === '') $missing[] = 'Rate Limit';
            if ($uptime === '') $missing[] = 'Uptime';
            if ($harga === '') $missing[] = 'Harga';
            if ($area === '') $missing[] = 'Area';
            if ($pemilik_manual === '') $missing[] = 'Pemilik';
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
            if ($komisi !== '' && !is_numeric($komisi)) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Komisi harus angka.";
                continue;
            }
            if ($komisi_nominal !== '' && !is_numeric($komisi_nominal)) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": komisi_nominal harus angka.";
                continue;
            }

            try {
                $komisi = normalizeNumericOrZero($komisi);
                $komisi_nominal = normalizeNumericOrZero($komisi_nominal);
            } catch (Exception $e) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": " . $e->getMessage();
                continue;
            }

            // Cari server berdasarkan pemilik + area; brand dipakai sebagai tambahan filter jika diisi.
            $pemilikEsc = mysqli_real_escape_string($conn, $pemilik_manual);
            $areaEsc = mysqli_real_escape_string($conn, $area);
            $brandEsc = mysqli_real_escape_string($conn, $brand);

            if ($area == 'ALL') {
                $sql = "SELECT * FROM `server` WHERE `PEMILIK` = '$pemilikEsc'";
                if ($brand !== '') {
                    $sql .= " AND `BRAND` = '$brandEsc'";
                }
            } else {
                $sql = "SELECT * FROM `server` WHERE `PEMILIK` = '$pemilikEsc' AND `AREA` = '$areaEsc'";
                if ($brand !== '') {
                    $sql .= " AND `BRAND` = '$brandEsc'";
                }
            }
            $query = mysqli_query($conn, $sql);
            if (!$query) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Query server gagal: ".mysqli_error($conn);
                continue;
            }
            $foundServer = false;
            while ($data = mysqli_fetch_array($query)) {
                $foundServer = true;
                $username = $data['PEMILIK'];
                $host     = $data['IP'];
                $password = $data['PASSWORD'];
                $AREA     = $data['AREA'];
                $brand_db = $data['BRAND'];

                $dupSql = "SELECT id FROM paket_hotspot WHERE paket='".mysqli_real_escape_string($conn, $profileName)."' AND pemilik='".mysqli_real_escape_string($conn, $username)."' AND area='".mysqli_real_escape_string($conn, $AREA)."' LIMIT 1";
                $dupRes = mysqli_query($conn, $dupSql);
                if ($dupRes && mysqli_num_rows($dupRes) > 0) {
                    $failCount++;
                    $failMessages[] = "Baris ".($i+2).": Paket hotspot sudah ada untuk product/area ini.";
                    continue;
                }

                if ($API->connect($host, $username, $password)) {
                    $API->comm("/ip/hotspot/user/profile/add", [
                        "name" => $profileName,
                        "rate-limit" => $ratelimit,
                    ]);
                    $API->disconnect();
                } else {
                    $failCount++;
                    $failMessages[] = "Baris ".($i+2).": Gagal konek ke $host ($AREA)";
                    continue;
                }
                // Simpan ke database, tambahkan kolom komisi_nominal jika ada di tabel
                $sql2 = "INSERT INTO `paket_hotspot` (`paket`, `uptime`, `ratelimit`, `harga`, `komisi`, `area`, `pemilik`, `BRAND`, `komisi_nominal`) 
                  VALUES ('".mysqli_real_escape_string($conn, $profileName)."', '".mysqli_real_escape_string($conn, $uptime)."', '".mysqli_real_escape_string($conn, $ratelimit)."', '".mysqli_real_escape_string($conn, $harga)."', '".mysqli_real_escape_string($conn, $komisi)."', '".mysqli_real_escape_string($conn, $AREA)."', '".mysqli_real_escape_string($conn, $username)."','".mysqli_real_escape_string($conn, $brand_db)."', '".mysqli_real_escape_string($conn, $komisi_nominal)."')";
                if (mysqli_query($conn, $sql2)) {
                    $successCount++;
                } else {
                    $failCount++;
                    $failMessages[] = "Baris ".($i+2).": DB gagal: ".mysqli_error($conn);
                }
                $history_file = "../notifbot/data/history-$ceknama.json";
                $history = [];
                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }
                if (!is_array($history)) $history = [];
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil import paket hotspot '$profileName'";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }
            if (!$foundServer) {
                $failCount++;
                $failMessages[] = "Baris ".($i+2).": Server tidak ditemukan (cek pemilik/area/brand).";
            }
        }
        $msg = "Sukses: $successCount baris. Gagal: $failCount baris.";
        if ($failCount > 0) {
            $msg .= "\n".implode("\n", $failMessages);
        }
        if ($failCount == 0) {
            $url = "../packageshotspot.php?statusnotif=success&text=" . urlencode($msg);
        } else {
            $url = "../packageshotspot.php?statusnotif=failed&text=" . urlencode($msg);
        }
        header("Location: " . $url);
        exit;
    } catch (Exception $e) {
        redirectWith('gagal', 'Gagal membaca file: ' . $e->getMessage());
    }
} else {
    redirectWith('gagal', 'Tidak ada file yang diupload.');
}
