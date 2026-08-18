<?php


// Bootstrap Library
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>';

$brand_param = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
if ($brand_param === '' && isset($_POST['brand'])) {
    $brand_param = trim((string)$_POST['brand']);
}
$brand_param = preg_replace('/[^a-zA-Z0-9_\-]/', '', $brand_param);
$portal_login_url = 'portallogin.php' . ($brand_param !== '' ? ('?brand=' . rawurlencode($brand_param)) : '');
$portal_login_url_js = htmlspecialchars($portal_login_url, ENT_QUOTES, 'UTF-8');

if (isset($_POST['logout'])) {
    setcookie("idselect", "", time() - 3600, "/"); 
    unset($_COOKIE['idselect']); 
    header("Location: " . $portal_login_url);
    exit;
}


// Simpan konfigurasi
$config_file = '../config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$domain=$config['domain'];



include '../koneksidb.php';

function getPelanggan($idpel)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE IDPEL = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $idpel);
    $stmt->execute();
    return ($res = $stmt->get_result()) ? $res->fetch_assoc() : null;
}

function getPelangganByNOWA($nowa)
{
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE NOWA = ?");
    if (!$stmt) return [];
    $stmt->bind_param('s', $nowa);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function getLastTransaction($idpel)
{
    global $conn;
    $query = "SELECT * FROM transaksi 
              WHERE IDPEL = '$idpel' AND STATUS = 'BERHASIL' 
              ORDER BY 
                RIGHT(PENGUNAAN, 4) DESC, 
                FIELD(LEFT(PENGUNAAN, LOCATE(' ', PENGUNAAN) - 1), 
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') DESC 
              LIMIT 1";
    $result = mysqli_query($conn, $query);
    return mysqli_fetch_assoc($result);
}

function getMonthName($month, $year)
{
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return $months[$month - 1] . ' ' . $year;
}

/**
 * Hitung nominal prorate.
 *
 * @param float  $harga        Harga paket penuh 1 bulan
 * @param int    $tempo        Tanggal jatuh tempo (mis. 28)
 * @param string $baseDate     Tanggal basis perhitungan (aktivasi pelanggan / tanggal mulai berlaku)
 * @return float
 */
function calculateProrate($harga, $tempo, $baseDate)
{
    $daysInMonth = date('t', strtotime($baseDate));
    $remainingDays = max(0, $tempo - date('d', strtotime($baseDate)));
    $prorate = ($harga / $daysInMonth) * $remainingDays;
    return round($prorate, 2);
}

$idpel = isset($_GET['idpel']) ? filter_var($_GET['idpel'], FILTER_SANITIZE_STRING) : 'FQ-014@0723';

if (!isset($_GET['cari']) || trim($_GET['cari']) === '') {
    echo "
    <div class='modal fade' id='errorModal' tabindex='-1' aria-labelledby='errorModalLabel' aria-hidden='true'>
      <div class='modal-dialog'>
        <div class='modal-content'>
          <div class='modal-header'>
            <h5 class='modal-title' id='errorModalLabel'>Warning</h5>
            <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
          </div>
          <div class='modal-body'>
            <p>Data pelanggan tidak ditemukan</p>
          </div>
                    <div class='modal-footer'>
                        <button type='button' class='btn btn-primary' onclick=\"document.cookie='idselect=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'; window.location='$portal_login_url_js'\">Keluar</button>
                    </div>
        </div>
      </div>
    </div>
    <script>
    var myModal = new bootstrap.Modal(document.getElementById('errorModal'));
    myModal.show();
    document.getElementById('errorModal').addEventListener('hidden.bs.modal', function () {
        window.location='$portal_login_url_js';
    });
    </script>
    ";
    exit;
}

if (isset($_GET['cari'])) {
    $cari = filter_var($_GET['cari'], FILTER_SANITIZE_STRING);

    // Fungsi format nomor HP
    if (!function_exists('formatNomorHP')) {
        function formatNomorHP($nomor)
        {
            $nomor = preg_replace('/[^\d+]/', '', $nomor);
            if (strpos($nomor, '+') === 0) {
                $nomor = substr($nomor, 1);
            }
            $nomor = str_replace(['-', ' '], '', $nomor);
            if (strpos($nomor, '0') === 0) {
                $nomor = '62' . substr($nomor, 1);
            } elseif (strpos($nomor, '62') !== 0) {
                $nomor = '62' . $nomor;
            }
            return $nomor;
        }
    }

    // Coba cari by IDPEL dulu
    $pelanggan = getPelanggan($cari);

    // Jika tidak ketemu, coba cari by NOWA
    if (empty($pelanggan['IDPEL'])) {
        $cariwa = formatNomorHP($cari);
        $pelangganByWaRows = getPelangganByNOWA($cariwa);
    }

    // Jika masih tidak ketemu via NOWA dan input bukan angka murni, coba format sebagai nomor juga
    if (empty($pelanggan['IDPEL']) && !isset($cariwa)) {
        if (preg_match('/^08\d{8,12}$/', $cari) || is_numeric($cari)) {
            $cariwa = formatNomorHP($cari);
            $pelangganByWaRows = getPelangganByNOWA($cariwa);
        }
    }

    // Kalau resolusi by-NOWA (bukan by-IDPEL) menemukan LEBIH dari satu akun
    // (nomor WA yang sama dipakai di beberapa akun pelanggan), JANGAN asal pilih
    // satu baris -- ini titik yang dulu menyebabkan tagihan tertukar antar akun.
    // Paksa login ulang lewat portallogin.php supaya pelanggan memilih akunnya
    // sendiri lewat popup pilih akun (lihat proseslogin-portal.php).
    if (empty($pelanggan['IDPEL']) && !empty($pelangganByWaRows)) {
        if (count($pelangganByWaRows) > 1) {
            header("Location: " . $portal_login_url);
            exit;
        }
        $pelanggan = $pelangganByWaRows[0];
    }
}

$idpel = $pelanggan['IDPEL'];
if (!isset($idpel) || trim($idpel) === '') {
    // Cek di tabel provisioning (pelanggan yang masih menunggu approval)
    $prov_found = false;
    $prov_nama = '';

    // Normalize nomor WA dari $cari jika $cariwa belum diset
    $prov_nowa_search = '';
    if (isset($cariwa) && !empty($cariwa)) {
        $prov_nowa_search = $cariwa;
    } elseif (isset($cari) && !empty($cari)) {
        // Coba normalize $cari sebagai nomor WA
        $cari_clean = preg_replace('/[^\d]/', '', $cari);
        if (strlen($cari_clean) >= 10) {
            if (substr($cari_clean, 0, 2) === '62') {
                $prov_nowa_search = $cari_clean;
            } elseif (substr($cari_clean, 0, 1) === '0') {
                $prov_nowa_search = '62' . substr($cari_clean, 1);
            }
        }
    }

    // Cari di provisioning: coba nowa dulu, lalu idpel
    if (!empty($prov_nowa_search)) {
        // Ambil bagian lokal nomor (tanpa prefix 62/0) untuk pencarian fleksibel
        $prov_nowa_local = $prov_nowa_search;
        if (substr($prov_nowa_local, 0, 2) === '62') {
            $prov_nowa_local = substr($prov_nowa_local, 2);
        } elseif (substr($prov_nowa_local, 0, 1) === '0') {
            $prov_nowa_local = substr($prov_nowa_local, 1);
        }
        $prov_nowa_like = '%' . $prov_nowa_local;
        $prov_nowa_alt = '0' . $prov_nowa_local;

        $stmt_prov = $conn->prepare("SELECT nama, idpel, status FROM provisioning WHERE nowa = ? OR nowa = ? OR nowa LIKE ? ORDER BY id DESC LIMIT 1");
        if ($stmt_prov) {
            $stmt_prov->bind_param('sss', $prov_nowa_search, $prov_nowa_alt, $prov_nowa_like);
            $stmt_prov->execute();
            $res_prov = $stmt_prov->get_result();
            if ($res_prov && $res_prov->num_rows > 0) {
                $row_prov = $res_prov->fetch_assoc();
                $prov_found = true;
                $prov_nama = $row_prov['nama'];
            }
            $stmt_prov->close();
        }
    }
    // Fallback: cari by idpel jika belum ketemu
    if (!$prov_found && isset($cari) && !empty($cari)) {
        $stmt_prov2 = $conn->prepare("SELECT nama, idpel, status FROM provisioning WHERE idpel = ? ORDER BY id DESC LIMIT 1");
        if ($stmt_prov2) {
            $stmt_prov2->bind_param('s', $cari);
            $stmt_prov2->execute();
            $res_prov2 = $stmt_prov2->get_result();
            if ($res_prov2 && $res_prov2->num_rows > 0) {
                $row_prov2 = $res_prov2->fetch_assoc();
                $prov_found = true;
                $prov_nama = $row_prov2['nama'];
            }
            $stmt_prov2->close();
        }
    }

    if ($prov_found) {
        echo "
        <div style='min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fa;'>
          <div class='card shadow' style='max-width:450px;width:100%;border:none;border-radius:15px;'>
            <div class='card-body text-center p-4'>
              <div style='font-size:50px;margin-bottom:15px;'>?</div>
              <h5 class='fw-bold mb-3' style='color:#333;'>Halo" . (!empty($prov_nama) ? ", " . htmlspecialchars($prov_nama) : "") . "!</h5>
              <p style='color:#555;font-size:15px;line-height:1.7;'>
                Saat ini Anda <strong>belum bisa</strong> untuk melihat tagihan atau masuk ke menu pelanggan karena data Anda masih dalam proses registrasi.
              </p>
              <hr style='border-color:#eee;'>
              <p style='color:#777;font-size:14px;'>
                Silahkan hubungi <strong>Live Chat</strong> atau <strong>Customer Service</strong> kami untuk informasi lebih lanjut.
              </p>
              <a href='$portal_login_url_js' class='btn btn-primary mt-3' style='border-radius:8px;padding:10px 30px;'>
                <i class='bi bi-arrow-left'></i> Kembali
              </a>
            </div>
          </div>
        </div>
        ";
        exit;
    }

    // Tidak ditemukan di database manapun (pelanggan & provisioning)
    echo "
    <div style='min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fa;'>
      <div class='card shadow' style='max-width:450px;width:100%;border:none;border-radius:15px;'>
        <div class='card-body text-center p-4'>
          <div style='font-size:50px;margin-bottom:15px;'>?</div>
          <h5 class='fw-bold mb-3' style='color:#dc3545;'>Data Pelanggan Tidak Ditemukan</h5>
          <p style='color:#555;font-size:15px;line-height:1.7;'>
            Maaf, data dengan pencarian <strong>" . htmlspecialchars($cari ?? '') . "</strong> tidak ditemukan di sistem kami.
          </p>
          <hr style='border-color:#eee;'>
          <p style='color:#777;font-size:14px;'>
            Silahkan hubungi <strong>Live Chat</strong> atau <strong>Customer Service</strong> kami untuk informasi lebih lanjut.
          </p>
          <a href='$portal_login_url_js' class='btn btn-primary mt-3' style='border-radius:8px;padding:10px 30px;'>
            <i class='bi bi-arrow-left'></i> Kembali
          </a>
        </div>
      </div>
    </div>
    ";
    exit;
}
 $BRAND = $pelanggan['BRAND'];
$paket = $pelanggan['PAKET'];
 $sql_paket = "SELECT * FROM `paket` WHERE `PAKET` LIKE '%$paket%' and `BRAND`='$BRAND'";
$query_paket = mysqli_query($conn, $sql_paket);
$paket_data = mysqli_fetch_array($query_paket);
 $paketHarga = $paket_data['HARGA'];

$pemilik = $pelanggan['PEMILIK'];
$brand_from_owner = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$pemilik);
if ($brand_param === '' && $brand_from_owner !== '') {
    $brand_param = $brand_from_owner;
    $portal_login_url = 'portallogin.php?brand=' . rawurlencode($brand_param);
    $portal_login_url_js = htmlspecialchars($portal_login_url, ENT_QUOTES, 'UTF-8');
}
$AREA = $pelanggan['AREA'];
$sql_user = "SELECT * FROM user WHERE id = (SELECT user_id FROM server WHERE PEMILIK = '$pemilik' LIMIT 1)";
$query_user = mysqli_query($conn, $sql_user);
$user_data = mysqli_fetch_array($query_user);

if (!$user_data) {
    echo "
    <div class='modal fade' id='errorModal' tabindex='-1' aria-labelledby='errorModalLabel' aria-hidden='true'>
      <div class='modal-dialog'>
        <div class='modal-content'>
          <div class='modal-header'>
            <h5 class='modal-title' id='errorModalLabel'>Warning</h5>
            <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
          </div>
          <div class='modal-body'>
            <p>Data pelanggan tidak ditemukan</p>
          </div>
                     <div class='modal-footer'>
                        <button type='button' class='btn btn-primary' onclick=\"document.cookie='idselect=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;'; window.location='$portal_login_url_js'\">Keluar</button>
                    </div>
        </div>
      </div>
    </div>
    <script>
    var myModal = new bootstrap.Modal(document.getElementById('errorModal'));
    myModal.show();
    document.getElementById('errorModal').addEventListener('hidden.bs.modal', function () {
        window.location='$portal_login_url_js';
    });
    </script>
    ";
    exit;
}

$useraccount = $user_data['USERNAME'];
$sql = "SELECT * FROM tripay WHERE pemilik='$useraccount'";
$query = mysqli_query($conn, $sql);
$data = mysqli_fetch_array($query);

if (!$data) {
   $default_payment = 'manual_bank'; // default fallback
}

$apiKey = $data['apikey'];
$privateKey = $data['privatekey'];
$merchantCode = $data['merchant'];

// Cek default payment method dari user table untuk menentukan pajak

if (isset($useraccount) && !empty($useraccount)) {
    $default_query = "SELECT payment_default FROM user WHERE USERNAME = ?";
    $stmt_default = $conn->prepare($default_query);
    $stmt_default->bind_param("s", $useraccount);
    $stmt_default->execute();
    $result_default = $stmt_default->get_result();
    if ($row_default = $result_default->fetch_assoc()) {
        $default_payment = $row_default['payment_default'] ?? 'manual_bank';
    }
    $stmt_default->close();
}

// BHPS USO dihapus dari sistem (2026-08-02) -- selalu 0, tidak dibaca dari
// tabel gateway manapun lagi. Kolom bhps_uso di DB dibiarkan ada (tidak
// dihapus) supaya tidak perlu ubah skema, tapi sudah tidak dipakai/ditampilkan.
$bhps_uso = 0;

// BUGFIX 2026-08-03: query pajak duitku/midtrans/xendit/ipaymu/doku/faspay/dompetx
// di bawah SEBELUMNYA pakai bind_param(.., $username, ..) utk kolom `server` --
// padahal $username baru DIDEFINISIKAN di baris ~502 (JAUH SETELAH switch ini),
// jadi di titik ini $username masih undefined/kosong. Query jadi otomatis "WHERE
// server = '' AND pemilik = ?" yang TIDAK PERNAH match, sehingga ke-7 gateway itu
// (KECUALI Tripay, yg jalur datanya beda -- $data dari query lain yg pakai
// $useraccount) selalu jatuh ke fallback pajak=11% terlepas dari setting admin
// yang sebenarnya. Diganti ke $useraccount (nilainya toh akan sama persis dgn
// $username -- lihat baris ~499-502, cuma re-fetch USERNAME dari tabel user
// WHERE USERNAME=$useraccount) yang SUDAH terdefinisi di titik ini.
// Set pajak berdasarkan payment method default
switch($default_payment) {
    case 'tripay':
        $pajak = $data['pajak']; // Gunakan pajak dari tabel tripay
        break;
    case 'duitku':
        $duitku_query = "SELECT pajak FROM duitku WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_duitku = $conn->prepare($duitku_query);
        $stmt_duitku->bind_param("ss", $useraccount, $useraccount);
        $stmt_duitku->execute();
        $result_duitku = $stmt_duitku->get_result();
        if ($row_duitku = $result_duitku->fetch_assoc()) {
            $pajak = $row_duitku['pajak'];
        } else {
            $pajak = 11; // Default jika tidak ada konfigurasi duitku
        }
        $stmt_duitku->close();
        break;
    case 'midtrans':
        $midtrans_query = "SELECT pajak FROM midtrans WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_midtrans = $conn->prepare($midtrans_query);
        $stmt_midtrans->bind_param("ss", $useraccount, $useraccount);
        $stmt_midtrans->execute();
        $result_midtrans = $stmt_midtrans->get_result();
        if ($row_midtrans = $result_midtrans->fetch_assoc()) {
            $pajak = $row_midtrans['pajak'];
        } else {
            $pajak = 11; // Default
        }
        $stmt_midtrans->close();
        break;
    case 'xendit':
        $xendit_query = "SELECT pajak FROM xendit WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_xendit = $conn->prepare($xendit_query);
        $stmt_xendit->bind_param("ss", $useraccount, $useraccount);
        $stmt_xendit->execute();
        $result_xendit = $stmt_xendit->get_result();
        if ($row_xendit = $result_xendit->fetch_assoc()) {
            $pajak = $row_xendit['pajak'];
        } else {
            $pajak = 11; // Default
        }
        $stmt_xendit->close();
        break;
    case 'ipaymu':
        $ipaymu_query = "SELECT pajak FROM ipaymu WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_ipaymu = $conn->prepare($ipaymu_query);
        if ($stmt_ipaymu) {
            $stmt_ipaymu->bind_param("ss", $useraccount, $useraccount);
            $stmt_ipaymu->execute();
            $result_ipaymu = $stmt_ipaymu->get_result();
            if ($row_ipaymu = $result_ipaymu->fetch_assoc()) {
                $pajak = $row_ipaymu['pajak'];
            } else {
                $pajak = 11;
            }
            $stmt_ipaymu->close();
        } else {
            $pajak = 11;
        }
        break;
    case 'doku':
        $doku_query = "SELECT pajak FROM doku WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_doku = $conn->prepare($doku_query);
        if ($stmt_doku) {
            $stmt_doku->bind_param("ss", $useraccount, $useraccount);
            $stmt_doku->execute();
            $result_doku = $stmt_doku->get_result();
            if ($row_doku = $result_doku->fetch_assoc()) {
                $pajak = $row_doku['pajak'];
            } else {
                $pajak = 11;
            }
            $stmt_doku->close();
        } else {
            $pajak = 11;
        }
        break;
    case 'faspay':
        $faspay_query = "SELECT pajak FROM faspay WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_faspay = $conn->prepare($faspay_query);
        if ($stmt_faspay) {
            $stmt_faspay->bind_param("ss", $useraccount, $useraccount);
            $stmt_faspay->execute();
            $result_faspay = $stmt_faspay->get_result();
            if ($row_faspay = $result_faspay->fetch_assoc()) {
                $pajak = $row_faspay['pajak'];
            } else {
                $pajak = 11;
            }
            $stmt_faspay->close();
        } else {
            $pajak = 11;
        }
        break;
    case 'dompetx':
        // SEBELUMNYA tidak ada case ini sama sekali -- jatuh ke default:
        // ($pajak hardcode 11), jadi kolom pajak di tabel dompetx (yg diisi
        // admin lewat paymentset.php) tidak pernah dipakai sama sekali.
        $dompetx_query_pajak = "SELECT pajak FROM dompetx WHERE server = ? AND pemilik = ? LIMIT 1";
        $stmt_dompetx_pajak = $conn->prepare($dompetx_query_pajak);
        if ($stmt_dompetx_pajak) {
            $stmt_dompetx_pajak->bind_param("ss", $useraccount, $useraccount);
            $stmt_dompetx_pajak->execute();
            $result_dompetx_pajak = $stmt_dompetx_pajak->get_result();
            if ($row_dompetx_pajak = $result_dompetx_pajak->fetch_assoc()) {
                $pajak = $row_dompetx_pajak['pajak'];
            } else {
                $pajak = 11;
            }
            $stmt_dompetx_pajak->close();
        } else {
            $pajak = 11;
        }
        break;
    case 'manual_bank':
        $pajak = 11; // Fixed 11% untuk manual bank
        break;
    default:
        $pajak = 11; // Fixed 11% untuk default
        break;
}

$sql99 = "SELECT * FROM `user` WHERE `USERNAME`='$useraccount' ";
$query99 = mysqli_query($conn, $sql99);
while ($data99 = mysqli_fetch_array($query99)) {
    $username = $data99['USERNAME'];
}

$directory = "../notifbot/data";
$jsonFile = "$directory/reminder-$username.json";

// Default TRUE (prorate tetap diberikan meski telat) - konsisten dengan default di
// paymentset.php supaya akun yang belum menyimpan setting baru ini (key belum ada di
// JSON lama) tidak berubah perilaku tagihannya.
$prorate_untuk_telat = true;
// Default 'berjalan' (periode tercatat = bulan yang sama dgn jatuh tempo) - konsisten
// dgn default di paymentset.php/tagihanLoadPeriodeTercatatMode() supaya akun lama tidak
// berubah perilaku billing-nya.
$periode_tercatat = 'berjalan';

if (file_exists($jsonFile)) {
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);
    if ($data !== null) {
        foreach ($data as $item) {
            $tempo = $item['jatuh_tempo'];
            $tanggal_awal_tutup_buku = $item['tanggal_awal_tutup_buku'];
            $tanggal_akhir_tutup_buku = $item['tanggal_akhir_tutup_buku'];
            $hari_sebelum = $item['hari_sebelum'];
            $tanggal_reminder = $item['tanggal_reminder'];
            $botname = $item['botname'];
            $prorate_untuk_telat = !isset($item['prorate_untuk_telat']) || !empty($item['prorate_untuk_telat']);
            $periode_tercatat = ($item['periode_tercatat'] ?? 'berjalan') === 'berikutnya' ? 'berikutnya' : 'berjalan';
        }
    }
}

// FIX: $jatuh_tempo dipakai di bawah (baris "LOGIKA PERIODE BULAN BERIKUT" dst) tapi
// SEBELUM baris ini tidak pernah diisi dari mana pun - PHP mengoper undefined variable
// itu sebagai 0, jadi kondisi "$jatuh_tempo <= $tanggal_akhir_tutup_buku" selalu true
// tanpa disadari. Nilai tanggal jatuh tempo yang benar ada di $tempo (dibaca dari JSON
// di atas) - disamakan di sini.
$jatuh_tempo = $tempo ?? 0;

$sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname'";
$query1 = mysqli_query($conn, $sql1);
$nomor = 1;
while ($data1 = mysqli_fetch_array($query1)) {
    $waapi = $data1['addressbot'];
    $botpass = $data1['password'];
}










$currentDate = date('d-m-Y');
$currentDay =  date('d');
$currentMonth = date('m');
$currentYear = date('Y');





// 1. Pastikan variabel waktu didefinisikan di awal
$tglskg = (int)date('d'); // Hari ini
$currentMonth = (int)date('m'); // Bulan ini
$currentYear = (int)date('Y'); // Tahun ini

// 2. Konversi variabel input (database) ke angka agar perbandingan IF akurat
$jatuh_tempo = (int)$jatuh_tempo;
$tanggal_awal_tutup_buku = (int)$tanggal_awal_tutup_buku;
$tanggal_akhir_tutup_buku = (int)$tanggal_akhir_tutup_buku;

// 3. Definisi Periode
$ptanggalskg = getMonthName($currentMonth, $currentYear);
$ptanggaberikut = getMonthName($currentMonth + 1, $currentYear);
$ptanggalsebelum = getMonthName($currentMonth - 1, $currentYear);

// =========================
// LOGIKA PERIODE BULAN BERIKUT
// =========================
if ($jatuh_tempo <= $tanggal_akhir_tutup_buku) {
    $periode_bulan_berikut = $ptanggaberikut; 
} else {
    $periode_bulan_berikut = getMonthName($currentMonth + 2, $currentYear);
}

// =========================
// LOGIKA UTAMA PERIODE
// =========================
if ($tglskg < $tanggal_awal_tutup_buku) {
    // Sebelum masa tutup buku
    $periode = $ptanggalskg;

} elseif ($tglskg >= $tanggal_awal_tutup_buku && $tglskg <= $tanggal_akhir_tutup_buku) {
    // MASA TUTUP BUKU -> HARUS BULAN DEPAN
    $periode = $periode_bulan_berikut;

} elseif ($tglskg > $tanggal_akhir_tutup_buku) {
    // Setelah tutup buku
    if ($jatuh_tempo <= $tanggal_akhir_tutup_buku) {
        $periode = $ptanggaberikut;
    } else {
        $periode = getMonthName($currentMonth + 2, $currentYear);
    }
}
















$lastTransaction = getLastTransaction($idpel);

$totalTagihan = 0;
$tagihanDetail = [];

$lastTransaction['PENGUNAAN'];
list($lastUsageMonthStr, $lastUsageYear) = explode(' ', $lastTransaction['PENGUNAAN']);
$lastUsageYear = (int)$lastUsageYear;

$months = ["Januari" => 1, "Februari" => 2, "Maret" => 3, "April" => 4, "Mei" => 5, "Juni" => 6, "Juli" => 7, "Agustus" => 8, "September" => 9, "Oktober" => 10, "November" => 11, "Desember" => 12];

$lastUsageMonth = $lastTransaction['PENGUNAAN'];
$lastUsageYear = (int)$lastUsageYear;
$nextmount = getMonthName($currentMonth + 1, $currentYear);

$TIPEBAYAR=$pelanggan['TIPE_BAYAR'];
$TIPEBAYAR=strtoupper($TIPEBAYAR);

// =====================================================================
// BLOK PRORATE UNTUK PELANGGAN TIPE_TEMPO = 'mengikuti_tanggal_tempo'
// =====================================================================
// FIX (dibanding versi sebelumnya):
// 1. Blok override "Set tagihan sederhana tanpa prorate" yang tadinya
//    SELALU dijalankan tanpa syarat di akhir (sehingga membuang hasil
//    prorate dan mengganti $totalTagihan/$tagihanDetail jadi harga
//    penuh) sudah DIHAPUS. Sekarang nilai hasil perhitungan prorate
//    di bawah ini benar-benar dipakai sebagai tagihan final.
// 2. Kondisi elseif duplikat (dua kondisi yang identik, sehingga
//    cabang kedua tidak pernah bisa dieksekusi / dead code) sudah
//    dirapikan menjadi 3 cabang yang jelas:
//      a) hari ini di antara awal-akhir tutup buku -> PRORATE bulan
//         berjalan + tagihan penuh bulan depan
//      b) hari ini setelah tutup buku -> tagihan penuh bulan depan saja
//      c) hari ini sebelum tutup buku -> tagihan penuh bulan berjalan
// =====================================================================
if($pelanggan['TIPE_TEMPO']=='mengikuti_tanggal_tempo')
{

// Pelanggan dianggap TELAT kalau hari ini sudah lewat tanggal jatuh tempo yang
// dikonfigurasi di Payment Setting ($jatuh_tempo) - dipakai untuk toggle
// "prorate_untuk_telat" (kalau OFF, pelanggan telat ditagih full 1 bulan tanpa
// prorate, bukan prorate + bulan depan seperti biasanya).
$isTelatBayarFixedDue = ($jatuh_tempo > 0 && $currentDay > $jatuh_tempo);

$totalTagihan = 0;
$tagihanDetail = [];
$lastTransaction['PENGUNAAN'];
list($lastUsageMonthStr, $lastUsageYear) = explode(' ', $lastTransaction['PENGUNAAN']);
$lastUsageYear = (int)$lastUsageYear;
$months = ["Januari" => 1, "Februari" => 2, "Maret" => 3, "April" => 4, "Mei" => 5, "Juni" => 6, "Juli" => 7, "Agustus" => 8, "September" => 9, "Oktober" => 10, "November" => 11, "Desember" => 12];
$lastUsageMonth = $lastTransaction['PENGUNAAN'];
$lastUsageYear = (int)$lastUsageYear;
$nextmount = getMonthName($currentMonth + 1, $currentYear);

if (empty($lastUsageMonth) || empty($lastUsageYear)) {
    // Pelanggan baru, belum ada riwayat pembayaran
    if ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku && $isTelatBayarFixedDue && !$prorate_untuk_telat) {
        // Telat bayar & toggle "prorate_untuk_telat" di-nonaktifkan: tagih full 1 bulan
        // (bulan berjalan) saja - tanpa potongan prorate, sebagai konsekuensi telat.
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Telat - Full)',
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;

    } elseif ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku) {
        // Masa tutup buku: PRORATE bulan ini + full bulan depan
        $prorate = calculateProrate($paketHarga, $tempo, $currentDate);
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate)',
            'harga' => $prorate
        ];
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += ($prorate + $paketHarga);

    } elseif ($currentDay > $tanggal_akhir_tutup_buku) {
        // Setelah tutup buku: tagih full bulan depan
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;

    } else {
        // Sebelum tutup buku: full bulan ini
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
    }
} elseif (
    ($lastUsageYear == $currentYear && $lastUsageMonth == $currentMonth + 1) ||
    ($currentMonth == 12 && $lastUsageMonth == 1 && $lastUsageYear == $currentYear + 1)
) {
    // Sudah bayar bulan depan (misal: sekarang Juni, sudah bayar Juli)
    $bulanBerikutnya = $currentMonth + 2;
    $tahunBerikutnya = $currentYear;
    if ($bulanBerikutnya > 12) {
        $bulanBerikutnya = 1;
        $tahunBerikutnya++;
    }
    $tagihanDetail[] = [
        'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($bulanBerikutnya, $tahunBerikutnya),
        'harga' => $paketHarga
    ];
    $totalTagihan += $paketHarga;

} else {
    // Sudah pernah bayar bulan lalu atau bulan ini
    if ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku && $isTelatBayarFixedDue && !$prorate_untuk_telat) {
        // Telat bayar & toggle "prorate_untuk_telat" di-nonaktifkan: tagih full 1 bulan
        // (bulan berjalan) saja - tanpa potongan prorate, sebagai konsekuensi telat.
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Telat - Full)',
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;

    } elseif ($currentDay >= $tanggal_awal_tutup_buku && $currentDay <= $tanggal_akhir_tutup_buku) {
        // Masa tutup buku: PRORATE bulan ini + full bulan depan
        $prorate = calculateProrate($paketHarga, $tempo, $currentDate);
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear) . ' (Prorate)',
            'harga' => $prorate
        ];
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += ($prorate + $paketHarga);

    } elseif ($currentDay > $tanggal_akhir_tutup_buku) {
        // Setelah tutup buku: hanya bulan depan
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth + 1, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;

    } else {
        // Sebelum tutup buku: full bulan ini
        $tagihanDetail[] = [
            'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' Bulan ' . getMonthName($currentMonth, $currentYear),
            'harga' => $paketHarga
        ];
        $totalTagihan += $paketHarga;
    }
}

// Catatan harga referensi paket (tidak lagi menimpa $totalTagihan/$tagihanDetail)
$HARGAPELANGGAN = $paketHarga;

}
if($pelanggan['TIPE_TEMPO']=='mengikuti_tanggal_bayar')
{

        if($pelanggan['TIPE_BAYAR']=='pascabayar')
        {
                $tagihanDetail[] = [
                    'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' periode Bulan ' . getMonthName($currentMonth, $currentYear),
                    'harga' => $paketHarga
                ];
                $totalTagihan += $paketHarga;

        }
       if($pelanggan['TIPE_BAYAR']=='prabayar')
        {
             $tagihanDetail[] = [
                    'keterangan' => 'Tagihan Penggunaan Bulan '.$TIPEBAYAR.' periode Bulan ' . getMonthName($currentMonth + 1, $currentYear),
                    'harga' => $paketHarga
                ];
                $totalTagihan += $paketHarga;

        }
}

// =====================================================================
// BLOK UNTUK PELANGGAN TIPE_TEMPO = 'monthversary'
// =====================================================================
// Tidak pakai prorate/tutup-buku global (itu khusus mode Fixed Due Date
// yang jatuh temponya sama untuk semua pelanggan). Monthversary jatuh
// temponya per-pelanggan (tanggal pasang/aktif masing-masing), jadi
// tagihan ditampilkan harga penuh, sama seperti mode Rolling Due Date.
if($pelanggan['TIPE_TEMPO']=='monthversary')
{
        if($pelanggan['TIPE_BAYAR']=='pascabayar')
        {
                $tagihanDetail[] = [
                    'keterangan' => 'Tagihan Penggunaan '.$TIPEBAYAR.' periode Bulan ' . getMonthName($currentMonth, $currentYear),
                    'harga' => $paketHarga
                ];
                $totalTagihan += $paketHarga;
        }
        if($pelanggan['TIPE_BAYAR']=='prabayar')
        {
                $tagihanDetail[] = [
                    'keterangan' => 'Tagihan Penggunaan Bulan '.$TIPEBAYAR.' periode Bulan ' . getMonthName($currentMonth + 1, $currentYear),
                    'harga' => $paketHarga
                ];
                $totalTagihan += $paketHarga;
        }
}













if (isset($_GET['action']) && $_GET['action'] == "hapus") {
    $ref = $_GET['ref'] ?? '';
    $idpel = $_GET['cari'] ?? '';
    if (!empty($ref)) {
        $query = "DELETE FROM `transaksi` WHERE `BUKTI`='$ref'";
        $result = mysqli_query($conn, $query);
        if (!$result) {
            echo "
            <div class='modal fade' id='errorModal' tabindex='-1' aria-labelledby='errorModalLabel' aria-hidden='true'>
              <div class='modal-dialog'>
                <div class='modal-content'>
                  <div class='modal-header'>
                    <h5 class='modal-title' id='errorModalLabel'>Error</h5>
                    <button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>
                  </div>
                  <div class='modal-body'>
                    <p>Error pada query: " . mysqli_error($conn) . "</p>
                  </div>
                  <div class='modal-footer'>
                    <button type='button' class='btn btn-primary' onclick=\"window.history.back()\">OK</button>
                  </div>
                </div>
              </div>
            </div>
            <script>
            var myModal = new bootstrap.Modal(document.getElementById('errorModal'));
            myModal.show();
            document.getElementById('errorModal').addEventListener('hidden.bs.modal', function () {
                window.history.back();
            });
            </script>
            ";
            exit;
        }
        header('Location: portal_baru.php?cari=' . $idpel);
        exit();
    }
}

$sql = "SELECT * FROM `server` WHERE `AREA`='$AREA' AND `PEMILIK`='$pemilik'";
$query = mysqli_query($conn, $sql);
$server_logo_file = '';
while ($data = mysqli_fetch_array($query)) {
    $idserver = $data['id'];
    if ($server_logo_file === '' && !empty($data['LOGO'])) {
        $server_logo_file = (string) $data['LOGO'];
    }
}

// Logo yang ditampilkan di portal pelanggan: prioritaskan logo milik SERVER
// (kolom server.LOGO, diisi admin lewat crm/billing/server.php sesuai server
// AREA+PEMILIK pelanggan ini -- lihat proses/upload_server_logo.php), baru
// fallback ke logo akun billing, baru fallback default. Dihitung SEKALI di
// sini (dipakai semua halaman portal_*.php lewat include cek_sesi.php) supaya
// pelanggan di area yang server-nya punya logo sendiri melihat logo yang
// sesuai, bukan logo akun tunggal yang sama untuk semua area.
$logo_path = "../../../dokumen/logo/profile-$useraccount.png";
if ($server_logo_file !== '' && file_exists('../../../dokumen/logo/' . basename($server_logo_file))) {
    $logo_path = '../../../dokumen/logo/' . basename($server_logo_file);
} elseif (!file_exists($logo_path)) {
    $logo_path = "../../../dokumen/logo/logo.png";
}

$history_file = "../notifbot/data/history-$pemilik.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) {
    $history = [];
}

if ($apiKey && $privateKey && $merchantCode) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://tripay.co.id/api/merchant/payment-channel');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $payment_channels = json_decode($response, true)['data'] ?? [];
}
####################################################################################################################











// ===================================================================
// STATUS TAGIHAN (SHOW/HIDE) -- diselaraskan dgn portal_bayar.php.
// SEBELUMNYA ada 3 heuristik kalender terpisah per TIPE_TEMPO di sini yang
// TIDAK konsultasi setting sungguhan sama sekali: Fixed Due Date cuma cek
// "tanggal hari ini > 15" (tidak peduli jatuh_tempo_hari beda per akun),
// Rolling hardcode "24 hari sejak transaksi terakhir" + "sisa 30 hari"
// (bukan simulasi siklus penuh yang menghindari bayar-cepat-memajukan-jadwal
// spt tagihanComputeRollingReferenceDate()), Monthversary cuma cek "sudah
// bayar bulan kalender ini" (tidak peduli anchor TANGGAL_MONTHVERSARY tiap
// pelanggan). Sekarang: LANGSUNG cek apakah ada baris PENAGIHAN (invoice
// belum dibayar) milik pelanggan ini -- sama seperti pola yang sudah dipakai
// portal_bayar.php (lihat komentar "CARI TAGIHAN PENAGIHAN..." di sana).
// Otomatis benar utk SEMUA TIPE_TEMPO krn baca ground truth invoice yang
// sudah ditulis cron generator (invoice_generator_penagihan.php /
// invoice_generator_rolling_monthversary.php), bukan menghitung ulang.
// ===================================================================
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';
$trxDateExprTampil = tagihanBuildTrxDateExpr();

// TIPE_TEMPO-aware, SAMA PERSIS dgn portal_bayar.php ("CARI TAGIHAN PENAGIHAN
// YANG SEDANG AKTIF") -- SEBELUMNYA satu query generik "ambil PENAGIHAN PALING
// LAMA" utk SEMUA tipe tempo, jadi kalau pelanggan Rolling/Monthversary punya
// tunggakan lama yg sudah lewat (mis. Juli) + tagihan periode berjalan (mis.
// Agustus) barengan, beranda pelanggan nampilin "Tagihan Juli 2026" terus
// padahal periode aktualnya sudah Agustus.
$tipeTempoTampilFokus = strtolower(trim((string)($pelanggan['TIPE_TEMPO'] ?? '')));
$isFixedDueDateTampilFokus = !in_array($tipeTempoTampilFokus, ['monthversary', 'mengikuti_tanggal_bayar'], true);

if ($isFixedDueDateTampilFokus) {
    $todayTsTampilFokus = strtotime(date('Y-m-d'));
    $dueMonthTsTampilFokus = ((int) date('j', $todayTsTampilFokus) <= (int) ($jatuh_tempo ?? 25))
        ? $todayTsTampilFokus
        : strtotime('+1 month', $todayTsTampilFokus);

    // SEBELUM Awal Tutup Buku: paksa mode 'berjalan' (tampilkan tagihan bulan
    // aktual/menunggak SEKARANG), TERLEPAS dari setting Periode Tercatat --
    // supaya beranda tidak "loncat" ke label periode berikutnya lebih awal
    // dari waktunya. Begitu masuk/lewat Awal Tutup Buku, baru ikut Periode
    // Tercatat asli sesuai Payment Setting. SAMA PERSIS dgn portal_bayar.php.
    $tglSkgTutupBukuTampilFokus = (int) date('j', $todayTsTampilFokus);
    $modePeriodeTampilFokus = ($tglSkgTutupBukuTampilFokus < (int) ($tanggal_awal_tutup_buku ?? 0))
        ? 'berjalan'
        : (string) ($periode_tercatat ?? 'berjalan');

    $periodeBerjalanTampilFokus = tagihanResolvePeriodeTercatat(
        (int) date('n', $dueMonthTsTampilFokus),
        (int) date('Y', $dueMonthTsTampilFokus),
        $modePeriodeTampilFokus
    );
    $stmtTampilPenagihan = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND UPPER(STATUS) = 'PENAGIHAN' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) ORDER BY id DESC LIMIT 1");
    $stmtTampilPenagihan->bind_param("ss", $idpel, $periodeBerjalanTampilFokus);
} else {
    // Rolling/Monthversary: SAMA PERSIS dgn portal_bayar.php -- cocokkan ke
    // BULAN AKTUAL (kalender hari ini) dulu, JANGAN ambil baris PENAGIHAN
    // paling baru tanpa syarat (bisa kebawa invoice bulan depan yg sudah
    // digenerate cron lebih awal, padahal belum waktunya ditampilkan).
    $bulanAktualTampilFokus = tagihanResolvePeriodeTercatat(
        (int) date('n'),
        (int) date('Y'),
        'berjalan'
    );
    $stmtTampilPenagihan = $conn->prepare("SELECT * FROM transaksi WHERE IDPEL = ? AND UPPER(STATUS) = 'PENAGIHAN' AND TRIM(UPPER(PENGUNAAN)) = TRIM(UPPER(?)) ORDER BY $trxDateExprTampil DESC, id DESC LIMIT 1");
    $stmtTampilPenagihan->bind_param("ss", $idpel, $bulanAktualTampilFokus);
}
$stmtTampilPenagihan->execute();
$rowTampilPenagihan = $stmtTampilPenagihan->get_result()->fetch_assoc();
$adaPenagihanTampil = $rowTampilPenagihan !== null;
$stmtTampilPenagihan->close();

if ($adaPenagihanTampil) {
    $tampiltagihan = "SHOW";
    $infotagihan = "Pembayaran dibuka";
    // Label periode ("Tagihan $periode") diselaraskan dgn portal_bayar.php --
    // ambil LANGSUNG dari PENGUNAAN baris PENAGIHAN yang ditemukan, BUKAN
    // tebakan heuristik tutup-buku generik lama ($periode dari "LOGIKA UTAMA
    // PERIODE" di atas, yang tidak konsultasi Periode Tercatat/TIPE_TEMPO).
    if (!empty($rowTampilPenagihan['PENGUNAAN'])) {
        $periode = $rowTampilPenagihan['PENGUNAAN'];
    }
} else {
    $tampiltagihan = "HIDE";
    $infotagihan = (!empty($lastTransaction['waktu'])) ? "Pembayaran ditutup" : "Menuggu pembayaran";
    // Tidak ada tagihan aktif -- supaya label "Tagihan $periode" tidak membingungkan
    // (mis. nunjuk bulan yang belum pernah ditagih sama sekali), tampilkan periode
    // dari transaksi TERAKHIR YANG BENAR-BENAR DIBAYAR (ground truth), bukan tebakan
    // heuristik tutup-buku lama ($periode dari "LOGIKA UTAMA PERIODE" di atas).
    if (!empty($lastTransaction['PENGUNAAN'])) {
        $periode = $lastTransaction['PENGUNAAN'];
    }
}








// Sisa hari aktif (dipakai tampilan Rolling di portal_baru.php) sekarang
// dihitung di sana dari tagihanHitungJatuhTempoBerikutnya() yang sudah
// benar (siklus penuh, bukan window 30 hari hardcode dari transaksi
// terakhir) -- lihat $sisaHariAktif di portal_baru.php.






$merchantRef = $pelanggan['IDPEL'];

if (!empty($merchantRef) && !empty($pemilik) && isset($periode) && trim((string)$periode) !== '') {
    $periode_diskon_target = trim((string)$periode);
        $odp_pelanggan = trim((string)($pelanggan['ODP'] ?? ''));
                $area_pelanggan = trim((string)($pelanggan['AREA'] ?? ''));
                $paket_pelanggan = trim((string)($pelanggan['PAKET'] ?? ''));

    $createDiskonTableSql = "CREATE TABLE IF NOT EXISTS diskon_pelanggan (
      id INT AUTO_INCREMENT PRIMARY KEY,
      MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
            GLOBAL_SCOPE ENUM('server','odp') NULL,
            SCOPE_VALUE VARCHAR(190) NULL,
            GLOBAL_AREA VARCHAR(120) NULL,
            GLOBAL_PAKET VARCHAR(150) NULL,
        NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal',
      IDPEL VARCHAR(120) NULL,
      PEMILIK VARCHAR(150) NOT NULL,
      PERIODE VARCHAR(40) NOT NULL,
      NOMINAL DECIMAL(18,2) NOT NULL DEFAULT 0,
      KETERANGAN TEXT NULL,
      ACTIVE TINYINT(1) NOT NULL DEFAULT 1,
      CREATED_BY VARCHAR(120) NULL,
      CREATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      UPDATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_diskon_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
                                                INDEX idx_diskon_scope (ACTIVE, MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, GLOBAL_PAKET, PERIODE),
      INDEX idx_diskon_periode (PERIODE)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $createDiskonTableSql);

    // Schema ALTER hanya dijalankan sekali (via flag file) � bukan setiap request.
    // Jika perlu reset schema, hapus file .diskon_schema.ok
    $_dsFlagFile = __DIR__ . '/../.diskon_schema.ok';
    if (!file_exists($_dsFlagFile)) {
        $existingDiskonColumns = [];
        $columnQuery = mysqli_query($conn, "SHOW COLUMNS FROM diskon_pelanggan");
        if ($columnQuery) {
            while ($col = mysqli_fetch_assoc($columnQuery)) {
                $existingDiskonColumns[] = (string)$col['Field'];
            }
        }
        if (!in_array('GLOBAL_SCOPE', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_SCOPE ENUM('server','odp') NULL AFTER MODE");
        if (!in_array('SCOPE_VALUE', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN SCOPE_VALUE VARCHAR(190) NULL AFTER GLOBAL_SCOPE");
        if (!in_array('GLOBAL_AREA', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER SCOPE_VALUE");
        if (!in_array('GLOBAL_PAKET', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
        if (!in_array('NOMINAL_TYPE', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
        // PERIODE_TYPE/PERIODE_MULAI/PERIODE_SELESAI -- SEBELUMNYA tidak di-ensure
        // di sini (cuma di proses/save_diskon_pelanggan.php), jadi kalau blok ini
        // jalan duluan di akun yang belum pernah nyimpen diskon, query di bawah
        // (yang sekarang baca kolom2 ini) akan error kolom tidak ada.
        if (!in_array('PERIODE_TYPE', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_TYPE ENUM('bulanan','rentang','permanen') NOT NULL DEFAULT 'bulanan' AFTER PERIODE");
        if (!in_array('PERIODE_MULAI', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_MULAI VARCHAR(40) NULL AFTER PERIODE_TYPE");
        if (!in_array('PERIODE_SELESAI', $existingDiskonColumns, true))
            mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_SELESAI VARCHAR(40) NULL AFTER PERIODE_MULAI");
        @file_put_contents($_dsFlagFile, '1');
    }

    // Skema tabel biaya_tambahan_pelanggan dipastikan ada di sini juga (dipakai
    // oleh tagihanTerapkanDiskonBiayaTambahan() di bawah -- fungsi itu sendiri
    // tidak melakukan CREATE TABLE/ALTER, supaya cek kolom tidak diulang di
    // tiap pemanggilan fungsi).
    $createBiayaTableSql = "CREATE TABLE IF NOT EXISTS biaya_tambahan_pelanggan (
      id INT AUTO_INCREMENT PRIMARY KEY,
      MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
      GLOBAL_AREA VARCHAR(120) NULL,
      GLOBAL_PAKET VARCHAR(150) NULL,
      NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal',
      IDPEL VARCHAR(120) NULL,
      PEMILIK VARCHAR(150) NOT NULL,
      PERIODE VARCHAR(40) NOT NULL,
      NOMINAL DECIMAL(18,2) NOT NULL DEFAULT 0,
      KETERANGAN TEXT NULL,
      ACTIVE TINYINT(1) NOT NULL DEFAULT 1,
      CREATED_BY VARCHAR(120) NULL,
      CREATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      UPDATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_biaya_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
      INDEX idx_biaya_scope (ACTIVE, MODE, PEMILIK, GLOBAL_AREA, GLOBAL_PAKET, PERIODE),
      INDEX idx_biaya_periode (PERIODE)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $createBiayaTableSql);

    $_bsFlagFile = __DIR__ . '/../.biaya_schema.ok';
    if (!file_exists($_bsFlagFile)) {
        $existingBiayaColumns = [];
        $biayaColumnQuery = mysqli_query($conn, "SHOW COLUMNS FROM biaya_tambahan_pelanggan");
        if ($biayaColumnQuery) {
            while ($col = mysqli_fetch_assoc($biayaColumnQuery)) {
                $existingBiayaColumns[] = (string)$col['Field'];
            }
        }
        if (!in_array('GLOBAL_AREA', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER MODE");
        if (!in_array('GLOBAL_PAKET', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
        if (!in_array('NOMINAL_TYPE', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
        if (!in_array('PERIODE_TYPE', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_TYPE ENUM('bulanan','rentang','permanen') NOT NULL DEFAULT 'bulanan' AFTER PERIODE");
        if (!in_array('PERIODE_MULAI', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_MULAI VARCHAR(40) NULL AFTER PERIODE_TYPE");
        if (!in_array('PERIODE_SELESAI', $existingBiayaColumns, true))
            mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_SELESAI VARCHAR(40) NULL AFTER PERIODE_MULAI");
        @file_put_contents($_bsFlagFile, '1');
    }

        // Query+terapkan diskon (& tambahan biaya di bawah) sekarang lewat fungsi
        // bersama tagihanTerapkanDiskonBiayaTambahan() di tagihan_status_lib.php --
        // supaya logika yang SAMA (termasuk fix 2026-08-02 utk PERIODE_TYPE
        // 'rentang'/'permanen', sebelumnya cuma match 'bulanan') bisa dipanggil
        // ulang di portal_bayar.php saat baris PENAGIHAN aktif menimpa total.
        $diskonBiayaResult = tagihanTerapkanDiskonBiayaTambahan(
            $conn, $pemilik, $merchantRef, $periode_diskon_target, $area_pelanggan, $paket_pelanggan, $odp_pelanggan, (float) $totalTagihan
        );
        $totalTagihan = $diskonBiayaResult['total'];
        foreach ($diskonBiayaResult['extra_detail'] as $d) {
            $tagihanDetail[] = $d;
        }
}

// Hitung PPN berdasarkan pajak yang telah ditentukan sesuai payment method
$ppn = $totalTagihan * $pajak / 100;
$totalTagihan += $ppn;
$totalTagihan = intval($totalTagihan);