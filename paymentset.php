<?php require 'header.php';

// Payment Setting HANYA boleh diakses akun utama (owner) -- bukan lewat
// checkbox "Hak Akses Menu" spt menu lain (assistant TIDAK BOLEH akses sama
// sekali, apapun isi $akses_menu-nya, krn halaman ini urus kredensial payment
// gateway/API key yang sensitif).
if ($AKSES === 'ASSISTANT') {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Halaman Payment Setting hanya bisa diakses oleh akun utama.</div></div>';
    require 'footer.php';
    exit;
}

// Simpan konfigurasi
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

// Global styles untuk konsistensi text size di seluruh halaman payment gateway
echo '<style>
.form-label { font-size: 0.85em !important; font-weight: 500; }
.form-control { font-size: 0.85em !important; }
.form-select { font-size: 0.85em !important; }
.table { font-size: 0.85em !important; }
table th { font-size: 0.80em !important; }
table td { font-size: 0.80em !important; }
.btn-sm { font-size: 0.85em !important; }
.btn { font-size: 0.85em !important; }
p { font-size: 0.85em !important; }
.text-sm { font-size: 0.85em !important; }
h6 { font-size: 0.95em !important; }
.alert { font-size: 0.85em !important; }
.mb-3 { margin-bottom: 0.75rem !important; }
textarea { font-size: 0.85em !important; }
</style>';

// Definisikan variabel username
$username = $ceknama;


// === Handle hapus Tripay ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tripay'])) {
    $id = intval($_POST['delete_tripay']);
    $sql = "DELETE FROM tripay WHERE id = $id";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Duitku ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_duitku'])) {
    $id = intval($_POST['delete_duitku']);
    $sql = "DELETE FROM duitku WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Duitku berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Duitku'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Midtrans ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_midtrans'])) {
    $id = intval($_POST['delete_midtrans']);
    $sql = "DELETE FROM midtrans WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Midtrans berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Midtrans'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Xendit ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_xendit'])) {
    $id = intval($_POST['delete_xendit']);
    $sql = "DELETE FROM xendit WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Xendit berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Xendit'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus iPaymu ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ipaymu'])) {
    $id = intval($_POST['delete_ipaymu']);
    $sql = "DELETE FROM ipaymu WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data iPaymu berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data iPaymu'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus DOKU ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doku'])) {
    $id = intval($_POST['delete_doku']);
    $sql = "DELETE FROM doku WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data DOKU berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data DOKU'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Faspay ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_faspay'])) {
    $id = intval($_POST['delete_faspay']);
    $sql = "DELETE FROM faspay WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Faspay berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Faspay'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus DompetX ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_dompetx'])) {
    $id = intval($_POST['delete_dompetx']);
    $sql = "DELETE FROM dompetx WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data DompetX berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data DompetX'); window.location.href=document.referrer;</script>";
    }
}


// === Inisialisasi variabel ===
$edit_id = $_GET['edit'] ?? null;

// === Handle form submit (Tambah atau Update) ===

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nama_bank'])) {
    // Ambil dan sanitasi input
    $nama_bank = isset($_POST['nama_bank']) ? mysqli_real_escape_string($conn, trim($_POST['nama_bank'])) : '';
    $nama_pemilik_bank = isset($_POST['nama_pemilik_bank']) ? mysqli_real_escape_string($conn, trim($_POST['nama_pemilik_bank'])) : '';
    $rekening_bank = isset($_POST['rekening_bank']) ? mysqli_real_escape_string($conn, trim($_POST['rekening_bank'])) : '';
    $server = isset($_POST['server']) ? mysqli_real_escape_string($conn, trim($_POST['server'])) : '';

    // Validasi dan default value
    $ppn = isset($_POST['ppn']) && is_numeric($_POST['ppn']) ? floatval($_POST['ppn']) : 11.0;
    // BHPS USO dihapus dari sistem (2026-08-02) -- selalu 0, kolom bhps_uso di
    // DB dibiarkan ada (tidak dihapus) supaya tidak perlu ubah skema.
    $bhps_uso = 0.0;

    if (!empty($_POST['id'])) {
        // Update data
        $id = intval($_POST['id']);
        $sql = "UPDATE manualbank SET 
                    nama_bank='$nama_bank',
                    nama_pemilik_bank='$nama_pemilik_bank',
                    rekening_bank='$rekening_bank',
                    server='$server',
                    ppn='$ppn',
                    bhps_uso='$bhps_uso'
                WHERE id=$id AND pemilik='".mysqli_real_escape_string($conn, $username)."'";
        if (mysqli_query($conn, $sql)) {
            echo "<script>alert('Data berhasil diperbarui'); window.location='paymentset.php';</script>";
        } else {
            echo "<script>alert('Gagal memperbarui data'); window.location='paymentset.php';</script>";
        }
    } else {
        // Tambah data baru
        $sql = "INSERT INTO manualbank (nama_bank, nama_pemilik_bank, rekening_bank, pemilik, server, ppn, bhps_uso)
                VALUES ('$nama_bank','$nama_pemilik_bank','$rekening_bank','$username','$server','$ppn','$bhps_uso')";
        if (mysqli_query($conn, $sql)) {
            echo "<script>alert('Data berhasil ditambahkan'); window.location='paymentset.php';</script>";
        } else {
            echo "<script>alert('Gagal menambah data'); window.location='paymentset.php';</script>";
        }
    }
}

// === Handle hapus ===
if (isset($_GET['hapus'])) {
    $id_hapus = intval($_GET['hapus']);
    $del = "DELETE FROM manualbank WHERE id = $id_hapus AND pemilik = '".mysqli_real_escape_string($conn, $username)."'";
    mysqli_query($conn, $del);
    echo "<script>alert('Data berhasil dihapus'); window.location='paymentset.php';</script>";
}

// === Handle hapus Duitku ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_duitku'])) {
    $id = intval($_POST['delete_duitku']);
    $sql = "DELETE FROM duitku WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Duitku berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Duitku'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Midtrans ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_midtrans'])) {
    $id = intval($_POST['delete_midtrans']);
    $sql = "DELETE FROM midtrans WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Midtrans berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Midtrans'); window.location.href=document.referrer;</script>";
    }
}

// === Handle hapus Xendit ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_xendit'])) {
    $id = intval($_POST['delete_xendit']);
    $sql = "DELETE FROM xendit WHERE id = $id AND pemilik = '".mysqli_real_escape_string($conn, $ceknama)."'";
    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Data Xendit berhasil dihapus'); window.location.href=document.referrer;</script>";
    } else {
        echo "<script>alert('Gagal menghapus data Xendit'); window.location.href=document.referrer;</script>";
    }
}

// === Ambil data untuk edit jika ada ===
$edit_data = null;
if ($edit_id) {
    $q = mysqli_query($conn, "SELECT * FROM manualbank WHERE id = ".intval($edit_id)." AND pemilik='".mysqli_real_escape_string($conn, $username)."'");
    $edit_data = mysqli_fetch_assoc($q);
    echo "<script>alert('Data berhasil edit'); window.location='paymentset.php';</script>";
}

// === Ambil semua data pemilik ini ===
$sql = "SELECT * FROM manualbank WHERE pemilik='".mysqli_real_escape_string($conn, $username)."'";
$result = mysqli_query($conn, $sql);

// === Pengaturan dipindah dari Notification: Fixed Due Date, Prabayar, Invoice Generator ===
// Fixed Due Date SEKARANG disimpan di tabel database `reminder_settings` (auto-install),
// BUKAN lagi file JSON -- lihat notifbot/reminder_settings_helper.php. File
// notifbot/data/reminder-<username>.json tetap di-generate ulang otomatis oleh helper
// itu tiap kali disimpan (cuma cache/mirror utk consumer lama, bukan sumber data lagi).
require_once __DIR__ . '/notifbot/reminder_settings_helper.php';

$paymentset_messages = [];
$directory = "notifbot/data";
if (!is_dir($directory)) {
    mkdir($directory, 0777, true);
}

$grace_period_config_file = "$directory/prabayar_grace_period-$username.json";
$invoice_config_file = "$directory/invoice_generator-$username.json";
$monthversary_config_file = "$directory/monthversary_setting-$username.json";

$reminder_table_rows = [];
$existing_jatuh_tempo = '';
$existing_tanggal_reminder = '';
$existing_waktu_reminder = '07:00';
$existing_botname = '';
$existing_tanggal_awal_tutup_buku = '';
$existing_tanggal_akhir_tutup_buku = '';
// Default TRUE (prorate tetap diberikan meski pelanggan telat bayar) supaya akun yang
// belum pernah menyentuh setting baru ini tidak tiba-tiba berubah perilaku tagihannya
// di portal bayar (backward-compatible dengan konfigurasi lama yang belum punya key ini).
$existing_prorate_telat = true;
// Default 'berjalan' supaya akun lama (belum pernah menyentuh setting ini) tidak
// berubah perilakunya -- 'berjalan' = PENGUNAAN yang tercatat sama dengan bulan
// tanggal jatuh tempo, persis behavior invoice_generator_penagihan.php selama ini.
$existing_periode_tercatat = 'berjalan';
// Default TRUE utk kedua filter reminder WA -- sama seperti perilaku selama ini
// (skip pelanggan yang sudah bayar & skip yang sudah dinotif hari ini). Admin bisa
// matikan salah satu/keduanya kalau mau reminder tetap terkirim meski pelanggan
// sudah bayar / sudah dinotif (mis. utk testing atau kebutuhan khusus).
$existing_filter_sudah_bayar_reminder = true;
$existing_filter_skip_notif_hari_ini = true;

if (reminderSettingsIsConfigured($conn, $username)) {
    $first = reminderSettingsGet($conn, $username);
    $reminder_table_rows = [$first];
    $existing_jatuh_tempo = $first['jatuh_tempo'];
    $existing_tanggal_reminder = $first['tanggal_reminder'];
    $existing_waktu_reminder = sprintf('%02d:%02d', (int)$first['jam_reminder'], (int)$first['menit_reminder']);
    $existing_botname = $first['botname'];
    $existing_tanggal_awal_tutup_buku = $first['tanggal_awal_tutup_buku'];
    $existing_tanggal_akhir_tutup_buku = $first['tanggal_akhir_tutup_buku'];
    $existing_prorate_telat = !empty($first['prorate_untuk_telat']);
    $existing_periode_tercatat = $first['periode_tercatat'] === 'berikutnya' ? 'berikutnya' : 'berjalan';
    $existing_filter_sudah_bayar_reminder = !empty($first['filter_sudah_bayar_reminder']);
    $existing_filter_skip_notif_hari_ini = !empty($first['filter_skip_notif_hari_ini']);
}

$prabayar_grace_period = 2;
if (file_exists($grace_period_config_file)) {
    $grace_data = json_decode(file_get_contents($grace_period_config_file), true);
    if (is_array($grace_data) && isset($grace_data['prabayar_grace_period'])) {
        $prabayar_grace_period = (int)$grace_data['prabayar_grace_period'];
    }
}

$invoice_generator_enabled = 0;
$invoice_generate_schedule = 'monthly_range';
$invoice_generate_start_day = 25;
$invoice_generate_hour = 7;
$invoice_generate_minute = 0;
// H-N hari sebelum jatuh tempo utk auto-generate invoice PENAGIHAN pelanggan
// Monthversary & Rolling (jatuh tempo per-pelanggan, tidak ikut start_day global).
$invoice_generate_days_before_due = 2;

if (file_exists($invoice_config_file)) {
    $invoice_cfg = json_decode(file_get_contents($invoice_config_file), true);
    if (is_array($invoice_cfg)) {
        $invoice_generator_enabled = !empty($invoice_cfg['enabled']) ? 1 : 0;
        $invoice_generate_schedule = (($invoice_cfg['schedule'] ?? 'monthly_range') === 'daily') ? 'daily' : 'monthly_range';
        $invoice_generate_start_day = isset($invoice_cfg['start_day']) ? (int)$invoice_cfg['start_day'] : 25;
        $invoice_generate_hour = isset($invoice_cfg['hour']) ? (int)$invoice_cfg['hour'] : 7;
        $invoice_generate_minute = isset($invoice_cfg['minute']) ? (int)$invoice_cfg['minute'] : 0;
        $invoice_generate_days_before_due = isset($invoice_cfg['days_before_due']) ? (int)$invoice_cfg['days_before_due'] : 2;
    }
}

$monthversary_follow_last_payment = false;
if (file_exists($monthversary_config_file)) {
    $monthversary_cfg = json_decode(file_get_contents($monthversary_config_file), true);
    if (is_array($monthversary_cfg) && isset($monthversary_cfg['follow_last_payment'])) {
        $monthversary_follow_last_payment = !empty($monthversary_cfg['follow_last_payment']);
    }
}

$invoice_generate_start_day = max(1, min(31, (int)$invoice_generate_start_day));
$invoice_generate_hour = max(0, min(23, (int)$invoice_generate_hour));
$invoice_generate_minute = max(0, min(59, (int)$invoice_generate_minute));
$invoice_generate_days_before_due = max(0, min(30, (int)$invoice_generate_days_before_due));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_reminder_fixed_due_date'])) {
    $jatuh_tempo = isset($_POST['jatuh_tempo']) ? (int)$_POST['jatuh_tempo'] : 0;
    $tanggal_reminder = isset($_POST['tanggal_reminder']) ? (int)$_POST['tanggal_reminder'] : 0;
    $waktu_reminder = isset($_POST['waktu_reminder']) ? trim((string)$_POST['waktu_reminder']) : '07:00';
    $botname = isset($_POST['botname']) ? trim((string)$_POST['botname']) : '';
    $tanggal_awal_tutup_buku = isset($_POST['tanggal_awal_tutup_buku']) ? (int)$_POST['tanggal_awal_tutup_buku'] : 1;
    $tanggal_akhir_tutup_buku = isset($_POST['tanggal_akhir_tutup_buku']) ? (int)$_POST['tanggal_akhir_tutup_buku'] : 1;
    // Checkbox: kalau tidak dicentang, browser tidak mengirim field ini sama sekali -
    // makanya default-nya harus dicek lewat isset(), bukan nilai kirimannya.
    $prorate_untuk_telat = isset($_POST['prorate_untuk_telat']);
    $periode_tercatat = (isset($_POST['periode_tercatat']) && $_POST['periode_tercatat'] === 'berikutnya') ? 'berikutnya' : 'berjalan';

    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $waktu_reminder, $matches_time)) {
        $matches_time = [null, '07', '00'];
    }
    $jam_reminder = (int)$matches_time[1];
    $menit_reminder = (int)$matches_time[2];

    if (
        $jatuh_tempo >= 1 && $jatuh_tempo <= 31 &&
        $tanggal_reminder >= 1 && $tanggal_reminder <= 31 &&
        $tanggal_awal_tutup_buku >= 1 && $tanggal_awal_tutup_buku <= 31 &&
        $tanggal_akhir_tutup_buku >= 1 && $tanggal_akhir_tutup_buku <= 31
    ) {
        $current_year_month = date('Y-m');
        $jatuh_tempo_timestamp = strtotime($current_year_month . '-' . $jatuh_tempo);
        $reminder_timestamp = strtotime($current_year_month . '-' . $tanggal_reminder);
        $hari_sebelum = (int)ceil(($jatuh_tempo_timestamp - $reminder_timestamp) / (60 * 60 * 24));

        // Simpan PARTIAL (cuma 10 kolom ini) via helper -- kolom filter_sudah_bayar_reminder/
        // filter_skip_notif_hari_ini SENGAJA TIDAK disebut di sini (diatur dari halaman
        // Notification Setting -> card Reminder Pembayaran) supaya reminderSettingsSave()
        // tidak menimpanya balik ke default (partial update, row lama dipertahankan).
        reminderSettingsSave($conn, $username, [
            'jatuh_tempo' => $jatuh_tempo,
            'hari_sebelum' => $hari_sebelum,
            'tanggal_reminder' => $tanggal_reminder,
            'jam_reminder' => $jam_reminder,
            'menit_reminder' => $menit_reminder,
            'botname' => $botname,
            'tanggal_awal_tutup_buku' => $tanggal_awal_tutup_buku,
            'tanggal_akhir_tutup_buku' => $tanggal_akhir_tutup_buku,
            'prorate_untuk_telat' => $prorate_untuk_telat,
            'periode_tercatat' => $periode_tercatat,
        ]);
        $reminder_table_rows = [reminderSettingsGet($conn, $username)];

        $existing_jatuh_tempo = $jatuh_tempo;
        $existing_tanggal_reminder = $tanggal_reminder;
        $existing_waktu_reminder = sprintf('%02d:%02d', $jam_reminder, $menit_reminder);
        $existing_botname = $botname;
        $existing_tanggal_awal_tutup_buku = $tanggal_awal_tutup_buku;
        $existing_tanggal_akhir_tutup_buku = $tanggal_akhir_tutup_buku;
        $existing_prorate_telat = $prorate_untuk_telat;
        $existing_periode_tercatat = $periode_tercatat;

        $domain_runtime = trim((string)($config['URL'] ?? ''));
        if ($domain_runtime !== '') {
            if (stripos($domain_runtime, 'http://') !== 0 && stripos($domain_runtime, 'https://') !== 0) {
                $domain_runtime = 'https://' . ltrim($domain_runtime, '/');
            }

            if (function_exists('shell_exec')) {
                $cronList = shell_exec('crontab -l 2>&1');
                if (!is_string($cronList) || stripos($cronList, 'no crontab for') !== false) {
                    $cronList = '';
                }

                $scriptRemainder = "notif_remainder_pembayaran_{$username}.php";
                $scriptIsolir = "non_aktif_tempo_{$username}.php";
                $scriptTagihanBaru = "matikan_client_baru_{$username}.php";

                $wasReminderActive = strpos($cronList, $scriptRemainder) !== false;
                $wasIsolirActive = strpos($cronList, $scriptIsolir) !== false;
                $wasTagihanBaruActive = strpos($cronList, $scriptTagihanBaru) !== false;

                $newLines = [];
                $lines = preg_split('/\r\n|\r|\n/', $cronList);
                foreach ($lines as $line) {
                    $trimmed = trim((string)$line);
                    if ($trimmed === '') {
                        continue;
                    }
                    if (
                        strpos($trimmed, $scriptRemainder) !== false ||
                        strpos($trimmed, $scriptIsolir) !== false ||
                        strpos($trimmed, $scriptTagihanBaru) !== false
                    ) {
                        continue;
                    }
                    $newLines[] = $trimmed;
                }

                $newCmdRemainder = "$menit_reminder $jam_reminder * * * curl $domain_runtime/crm/billing/notifbot/notifphp/$scriptRemainder > /dev/null 2>&1";
                $newCmdIsolir = "$menit_reminder $jam_reminder * * * curl $domain_runtime/crm/billing/notifbot/notifphp/$scriptIsolir > /dev/null 2>&1";
                $newCmdTagihanBaru = "$menit_reminder $jam_reminder * * * curl $domain_runtime/crm/billing/notifbot/notifphp/$scriptTagihanBaru > /dev/null 2>&1";

                if ($wasReminderActive) {
                    $newLines[] = $newCmdRemainder;
                }
                if ($wasIsolirActive) {
                    $newLines[] = $newCmdIsolir;
                }
                if ($wasTagihanBaruActive) {
                    $newLines[] = $newCmdTagihanBaru;
                }

                $newCron = implode("\n", $newLines);
                if ($newCron !== '') {
                    $newCron .= "\n";
                }

                $tmpCronFile = '/tmp/newcron_fixed_due_date.txt';
                file_put_contents($tmpCronFile, $newCron);
                shell_exec("crontab $tmpCronFile");
                @unlink($tmpCronFile);
            }
        }

        $paymentset_messages[] = "<div class='alert alert-success'>Konfigurasi Fixed Due Date berhasil disimpan.</div>";
    } else {
        $paymentset_messages[] = "<div class='alert alert-danger'>Input Fixed Due Date tidak valid. Gunakan rentang 1-31.</div>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_prabayar_grace_period'])) {
    $prabayar_grace_period_post = isset($_POST['prabayar_grace_period']) ? (int)$_POST['prabayar_grace_period'] : 2;
    $prabayar_grace_period_post = max(0, min(30, $prabayar_grace_period_post));
    $grace_data = [
        'prabayar_grace_period' => $prabayar_grace_period_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($grace_period_config_file, json_encode($grace_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $prabayar_grace_period = $prabayar_grace_period_post;
    $paymentset_messages[] = "<div class='alert alert-success'>Waktu tunggu prabayar berhasil disimpan: <b>{$prabayar_grace_period} hari</b></div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_monthversary_setting'])) {
    $monthversary_follow_last_payment_post = isset($_POST['monthversary_follow_last_payment']);
    $monthversary_data = [
        'follow_last_payment' => $monthversary_follow_last_payment_post,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents($monthversary_config_file, json_encode($monthversary_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $monthversary_follow_last_payment = $monthversary_follow_last_payment_post;
    $paymentset_messages[] = "<div class='alert alert-success'>Pengaturan Monthversary berhasil disimpan: <b>" .
        ($monthversary_follow_last_payment ? 'ON (ikut tanggal bayar terakhir)' : 'OFF (tetap anchor awal)') . "</b></div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_invoice_generator'])) {
    $invoice_generator_enabled = isset($_POST['invoice_generator_enabled']) ? 1 : 0;
    $invoice_generate_schedule = isset($_POST['invoice_generate_daily']) ? 'daily' : 'monthly_range';
    $invoice_generate_start_day = isset($_POST['invoice_generate_start_day']) ? (int)$_POST['invoice_generate_start_day'] : 25;
    $invoice_generate_hour = isset($_POST['invoice_generate_hour']) ? (int)$_POST['invoice_generate_hour'] : 7;
    $invoice_generate_minute = isset($_POST['invoice_generate_minute']) ? (int)$_POST['invoice_generate_minute'] : 0;
    $invoice_generate_days_before_due = isset($_POST['invoice_generate_days_before_due']) ? (int)$_POST['invoice_generate_days_before_due'] : 2;

    $invoice_generate_start_day = max(1, min(31, $invoice_generate_start_day));
    $invoice_generate_hour = max(0, min(23, $invoice_generate_hour));
    $invoice_generate_minute = max(0, min(59, $invoice_generate_minute));
    $invoice_generate_days_before_due = max(0, min(30, $invoice_generate_days_before_due));

    $invoice_payload = [
        'enabled' => $invoice_generator_enabled,
        'schedule' => $invoice_generate_schedule,
        'start_day' => $invoice_generate_start_day,
        'hour' => $invoice_generate_hour,
        'minute' => $invoice_generate_minute,
        'days_before_due' => $invoice_generate_days_before_due,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if (file_put_contents($invoice_config_file, json_encode($invoice_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $domain_runtime = trim((string)($config['URL'] ?? ''));
        if ($domain_runtime !== '' && stripos($domain_runtime, 'http://') !== 0 && stripos($domain_runtime, 'https://') !== 0) {
            $domain_runtime = 'https://' . ltrim($domain_runtime, '/');
        }

        if ($domain_runtime !== '' && function_exists('shell_exec')) {
            // Kedua cron (Fixed Due Date & Rolling/Monthversary) SELALU jalan
            // harian di crontab (jam/menit di atas) -- keputusan "apakah SUDAH
            // waktunya generate" sepenuhnya dihitung DI DALAM script masing-masing
            // via setting "days_before_due" (Terbit H- sblm jatuh tempo), SATU
            // setting yang sama dipakai kedua mode. Field jadwal kalender lama
            // (schedule/start_day, "Mode Jadwal"/"Mulai Tanggal") sudah tidak
            // dipakai lagi oleh invoice_generator_penagihan_*.php.
            $invoice_cron_job = "$invoice_generate_minute $invoice_generate_hour * * * curl $domain_runtime/crm/billing/notifbot/notifphp/invoice_generator_penagihan_$username.php > /dev/null 2>&1";
            $invoice_cron_job_rm = "$invoice_generate_minute $invoice_generate_hour * * * curl $domain_runtime/crm/billing/notifbot/notifphp/invoice_generator_rolling_monthversary_$username.php > /dev/null 2>&1";

            $current_crontab_wwwdata = shell_exec('crontab -u www-data -l 2>&1');
            if (!is_string($current_crontab_wwwdata) || stripos($current_crontab_wwwdata, 'no crontab for') !== false) {
                $current_crontab_wwwdata = '';
            }

            $lines = preg_split('/\r\n|\r|\n/', $current_crontab_wwwdata);
            $filtered = [];
            foreach ($lines as $line) {
                if (strpos((string)$line, "invoice_generator_penagihan_$username.php") !== false) {
                    continue;
                }
                if (strpos((string)$line, "invoice_generator_rolling_monthversary_$username.php") !== false) {
                    continue;
                }
                if (trim((string)$line) === '') {
                    continue;
                }
                $filtered[] = $line;
            }

            $base_crontab_wwwdata = implode("\n", $filtered);
            $base_crontab_wwwdata = $base_crontab_wwwdata !== '' ? $base_crontab_wwwdata . "\n" : '';

            file_put_contents('/tmp/crontab_wwwdata_invoice.txt', $base_crontab_wwwdata);
            shell_exec('crontab -u www-data /tmp/crontab_wwwdata_invoice.txt');
            @unlink('/tmp/crontab_wwwdata_invoice.txt');

            if ($invoice_generator_enabled) {
                $new_crontab_wwwdata = $base_crontab_wwwdata . $invoice_cron_job . "\n" . $invoice_cron_job_rm . "\n";
                file_put_contents('/tmp/crontab_wwwdata_invoice.txt', $new_crontab_wwwdata);
                shell_exec('crontab -u www-data /tmp/crontab_wwwdata_invoice.txt');
                @unlink('/tmp/crontab_wwwdata_invoice.txt');
            }
        }

        $paymentset_messages[] = "<div class='alert alert-success'>Pengaturan Invoice Generator berhasil disimpan.</div>";
    } else {
        $paymentset_messages[] = "<div class='alert alert-danger'>Gagal menyimpan pengaturan Invoice Generator.</div>";
    }
}

$bot_options = [];
$query_bot = "SELECT DISTINCT namebot FROM botwa WHERE pemilik='" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY namebot ASC";
$result_bot = mysqli_query($conn, $query_bot);
if ($result_bot) {
    while ($row_bot = mysqli_fetch_assoc($result_bot)) {
        if (!empty($row_bot['namebot'])) {
            $bot_options[] = $row_bot['namebot'];
        }
    }
}
?>

  <style>
/* === FULL CSS REWORK: NO FADED TEXT, MAXIMUM CONTRAST === */

/* Payment Method Card Layout */
.payment-method-row {
    display: flex;
    gap: 16px;
    margin-bottom: 24px;
}
.payment-method-card {
    flex: 1 1 0;
    min-width: 160px;
    border-radius: 12px;
    border: 2.5px solid #e5e7eb;
    background: #fff;
    color: #18181b;
    box-shadow: none;
    padding: 18px 0 12px 0;
    text-align: center;
    font-size: 1.1em;
    font-weight: 800;
    opacity: 1 !important;
    letter-spacing: 0.01em;
    transition: border 0.2s, background 0.2s, color 0.2s;
    position: relative;
}
.payment-method-card .pm-title {
    font-size: 1.2em;
    font-weight: 900;
    margin-bottom: 4px;
    color: #18181b;
    opacity: 1 !important;
}
.payment-method-card .pm-desc {
    font-size: 0.97em;
    font-weight: 700;
    margin-top: 2px;
    color: #18181b;
    opacity: 1 !important;
}
.payment-method-card.pm-default {
    border: 2.5px solid #22c55e;
    background: #22c55e;
    color: #fff;
}
.payment-method-card.pm-error {
    border: 2.5px solid #ef4444;
    background: #ef4444;
    color: #fff;
}
.payment-method-card.pm-disabled {
    background: #f1f5f9;
    color: #b0b0b0;
    border: 2px solid #e5e7eb;
    cursor: not-allowed;
    opacity: 1 !important;
}
.payment-method-card.pm-default .pm-title,
.payment-method-card.pm-error .pm-title,
.payment-method-card.pm-default .pm-desc,
.payment-method-card.pm-error .pm-desc {
    color: #fff !important;
    opacity: 1 !important;
}
.payment-method-card .pm-check {
    font-size: 1.1em;
    margin-right: 4px;
    color: #fff;
}
@media (max-width: 700px) {
    .payment-method-row { flex-direction: column; gap: 10px; }
}

/* Tab Styling */
.nav-tabs .nav-link {
    background: #fff;
    color: #18181b !important;
    border-radius: 10px 10px 0 0;
    margin-right: 5px;
    padding: 12px 20px;
    font-weight: 800;
    border: none;
    box-shadow: none;
    font-size: 13px;
    opacity: 1 !important;
}
.nav-tabs .nav-link.active {
    background: #22c55e !important;
    color: #fff !important;
    font-weight: 900;
    border-bottom: 4px solid #22c55e;
    font-size: 15px;
    opacity: 1 !important;
}
.nav-tabs .nav-link:hover {
    background: #e5e7eb;
    color: #18181b !important;
    opacity: 1 !important;
}

/* Card & Table Styling */
.card {
    border-radius: 15px;
    box-shadow: none;
    background-color: #fff;
}
table thead th {
    background-color: #e2e8f0 !important;
    color: #18181b !important;
    font-weight: 900 !important;
    opacity: 1 !important;
}
table tbody td {
    background-color: #fff !important;
    color: #18181b !important;
    font-weight: 700 !important;
    opacity: 1 !important;
}
table tbody tr:nth-child(even) td {
    background-color: #f1f5f9 !important;
}
table td *, table th * {
    color: #18181b !important;
    opacity: 1 !important;
}

/* Form & Label Styling */
.form-label, .form-text, label, input, select, textarea {
    color: #18181b !important;
    font-weight: 700 !important;
    opacity: 1 !important;
}

/* DARK THEME OVERRIDES: MAX CONTRAST */
[data-bs-theme="dark"] .payment-method-card,
body.dark .payment-method-card,
html[data-theme="dark"] .payment-method-card {
    background: #181f2a !important;
    color: #fff !important;
    border: 2.5px solid #22c55e;
    box-shadow: none;
}
[data-bs-theme="dark"] .payment-method-card.pm-default,
body.dark .payment-method-card.pm-default,
html[data-theme="dark"] .payment-method-card.pm-default {
    background: #22c55e !important;
    color: #fff !important;
    border: 2.5px solid #22c55e !important;
}
[data-bs-theme="dark"] .payment-method-card.pm-error,
body.dark .payment-method-card.pm-error,
html[data-theme="dark"] .payment-method-card.pm-error {
    background: #ef4444 !important;
    color: #fff !important;
    border: 2.5px solid #ef4444 !important;
}
[data-bs-theme="dark"] .payment-method-card.pm-disabled,
body.dark .payment-method-card.pm-disabled,
html[data-theme="dark"] .payment-method-card.pm-disabled {
    background: #232b3b !important;
    color: #6b7280 !important;
    border: 2px solid #232b3b !important;
}
[data-bs-theme="dark"] .payment-method-card .pm-title,
[data-bs-theme="dark"] .payment-method-card .pm-desc,
[data-bs-theme="dark"] .payment-method-card.pm-default .pm-title,
[data-bs-theme="dark"] .payment-method-card.pm-default .pm-desc,
[data-bs-theme="dark"] .payment-method-card.pm-error .pm-title,
[data-bs-theme="dark"] .payment-method-card.pm-error .pm-desc {
    color: #fff !important;
    opacity: 1 !important;
}
[data-bs-theme="dark"] .nav-tabs .nav-link,
body.dark .nav-tabs .nav-link,
html[data-theme="dark"] .nav-tabs .nav-link {
    background: #232b3b !important;
    color: #fff !important;
    font-weight: 800;
    opacity: 1 !important;
}
[data-bs-theme="dark"] .nav-tabs .nav-link.active,
body.dark .nav-tabs .nav-link.active,
html[data-theme="dark"] .nav-tabs .nav-link.active {
    background: #22c55e !important;
    color: #fff !important;
    font-weight: 900;
    border-bottom: 4px solid #22c55e !important;
    font-size: 15px;
    opacity: 1 !important;
}
[data-bs-theme="dark"] .nav-tabs .nav-link:hover,
body.dark .nav-tabs .nav-link:hover,
html[data-theme="dark"] .nav-tabs .nav-link:hover {
    background: #232b3b !important;
    color: #fff !important;
    opacity: 1 !important;
}
[data-bs-theme="dark"] .card,
body.dark .card,
html[data-theme="dark"] .card {
    background-color: #181f2a !important;
}
[data-bs-theme="dark"] table thead th,
body.dark table thead th,
html[data-theme="dark"] table thead th {
    background-color: #232b3b !important;
    color: #fff !important;
    font-weight: 900 !important;
    opacity: 1 !important;
}
[data-bs-theme="dark"] table tbody td,
body.dark table tbody td,
html[data-theme="dark"] table tbody td {
    background-color: #181f2a !important;
    color: #fff !important;
    font-weight: 700 !important;
    opacity: 1 !important;
}
[data-bs-theme="dark"] table tbody tr:nth-child(even) td,
body.dark table tbody tr:nth-child(even) td,
html[data-theme="dark"] table tbody tr:nth-child(even) td {
    background-color: #232b3b !important;
}
[data-bs-theme="dark"] table td *,
[data-bs-theme="dark"] table th * {
    color: #fff !important;
    opacity: 1 !important;
}
[data-bs-theme="dark"] .form-label,
[data-bs-theme="dark"] .form-text,
[data-bs-theme="dark"] label,
[data-bs-theme="dark"] input,
[data-bs-theme="dark"] select,
[data-bs-theme="dark"] textarea {
    color: #fff !important;
    font-weight: 700 !important;
    opacity: 1 !important;
}
/* --- PAYMENT METHOD CARD REWORK: HIGH CONTRAST FOR DARK MODE --- */
.payment-method-row {
    display: flex;
    gap: 16px;
    margin: 0 0 24px 0;
}
.payment-method-card {
    flex: 1 1 0;
    min-width: 160px;
    border-radius: 12px;
    border: 2px solid #3b4252;
    background: #fff;
    color: #222;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 18px 0 12px 0;
    text-align: center;
    font-size: 1.1em;
    font-weight: 700;
    opacity: 1 !important;
    transition: border 0.2s, background 0.2s, color 0.2s;
    position: relative;
}
.payment-method-card .pm-title {
    font-size: 1.2em;
    font-weight: 800;
    margin-bottom: 4px;
}
.payment-method-card .pm-desc {
    font-size: 0.95em;
    font-weight: 500;
    margin-top: 2px;
}
.payment-method-card.pm-default {
    border: 2.5px solid #22c55e;
    background: #22c55e;
    color: #fff;
    box-shadow: 0 4px 16px #22c55e44;
}
.payment-method-card.pm-error {
    border: 2.5px solid #ef4444;
    background: #ef4444;
    color: #fff;
}
.payment-method-card.pm-disabled {
    background: #f1f5f9;
    color: #b0b0b0;
    border: 2px solid #e5e7eb;
    cursor: not-allowed;
    opacity: 1 !important;
}
.payment-method-card.pm-default .pm-title,
.payment-method-card.pm-error .pm-title {
    color: #fff !important;
}
.payment-method-card.pm-default .pm-desc,
.payment-method-card.pm-error .pm-desc {
    color: #fff !important;
}
.payment-method-card .pm-desc {
    color: inherit;
}
.payment-method-card .pm-check {
    font-size: 1.1em;
    margin-right: 4px;
}
@media (max-width: 700px) {
    .payment-method-row { flex-direction: column; gap: 10px; }
}

/* DARK THEME OVERRIDES */
[data-bs-theme="dark"] .payment-method-card,
body.dark .payment-method-card,
html[data-theme="dark"] .payment-method-card {
    background: #232b3b;
    color: #fff;
    border: 2px solid #4c566a;
    box-shadow: 0 2px 8px #000a;
}
[data-bs-theme="dark"] .payment-method-card.pm-default,
body.dark .payment-method-card.pm-default,
html[data-theme="dark"] .payment-method-card.pm-default {
    background: #22c55e;
    color: #fff;
    border: 2.5px solid #22c55e;
    box-shadow: 0 4px 16px #22c55e44;
}
[data-bs-theme="dark"] .payment-method-card.pm-error,
body.dark .payment-method-card.pm-error,
html[data-theme="dark"] .payment-method-card.pm-error {
    background: #ef4444;
    color: #fff;
    border: 2.5px solid #ef4444;
}
[data-bs-theme="dark"] .payment-method-card.pm-disabled,
body.dark .payment-method-card.pm-disabled,
html[data-theme="dark"] .payment-method-card.pm-disabled {
    background: #181f2a;
    color: #6b7280;
    border: 2px solid #232b3b;
}
[data-bs-theme="dark"] .payment-method-card .pm-title,
[data-bs-theme="dark"] .payment-method-card .pm-desc,
[data-bs-theme="dark"] .payment-method-card.pm-default .pm-title,
[data-bs-theme="dark"] .payment-method-card.pm-default .pm-desc,
[data-bs-theme="dark"] .payment-method-card.pm-error .pm-title,
[data-bs-theme="dark"] .payment-method-card.pm-error .pm-desc {
    color: #fff !important;
}


        /* --- Light Theme (default) --- */
        :root {
            --paymentset-dark-text: #0f172a;
            --paymentset-light-bg: #fff;
            --paymentset-light-gray: #f1f5f9;
            --paymentset-light-table: #e2e8f0;
            --paymentset-light-green: #e0f2fe;
            --paymentset-light-orange: #f97316;
        }

        .nav-tabs .nav-link {
            background: linear-gradient(135deg, var(--orange, #f97316) 0%, #e08518 100%);
            color: #000;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
            padding: 12px 20px;
            font-weight: 700;
            transition: all 0.3s ease;
            text-shadow: none;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(247,148,29,0.3);
            font-size: 13px;
        }
        .nav-tabs .nav-link:hover {
            background: linear-gradient(135deg, #e08518 0%, #d97706 100%);
            color: #000;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(247,148,29,0.4);
            text-shadow: none;
        }
        .nav-tabs .nav-link.active,
        .nav-tabs .nav-link.active:hover {
            background: linear-gradient(135deg, var(--orange, #f97316) 0%, #e08518 100%);
            color: #000;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(247,148,29,0.5);
            border-bottom: 5px solid #e08518;
            transform: translateY(-2px);
            text-shadow: none;
            font-size: 15px;
            border-top: 2px solid rgba(255,255,255,0.3);
        }
        .tab-content > div {
            display: none;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }
        .tab-content > div.active {
            display: block;
            opacity: 1;
            transform: translateY(0);
            animation: fadeInUp 0.4s ease;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 24px rgba(14,149,137,0.12);
            background-color: var(--paymentset-light-bg);
        }
        table thead th {
            background-color: var(--paymentset-light-green) !important;
            color: #0f172a;
        }
        .payment-method-wrapper {
            background-color: var(--paymentset-light-gray) !important;
        }
        .payment-method-wrapper .form-label,
        .payment-method-wrapper .form-text,
        .payment-method-wrapper h5,
        .payment-method-wrapper p,
        .payment-method-wrapper small {
            color: #0f172a;
        }
        .payment-method-wrapper .form-text.text-muted {
            color: #64748b !important;
        }
        .payment-method-card {
            background-color: var(--paymentset-light-bg);
            color: #0f172a;
            border-color: #e5e7eb !important;
        }
        .payment-method-card,
        .payment-method-card i,
        .payment-method-card strong,
        .payment-method-card small {
            color: #0f172a !important;
        }
        .payment-method-card.bg-success,
        .payment-method-card.bg-success i,
        .payment-method-card.bg-success strong,
        .payment-method-card.bg-success small,
        .payment-method-card.bg-danger,
        .payment-method-card.bg-danger i,
        .payment-method-card.bg-danger strong,
        .payment-method-card.bg-danger small {
            color: #fff !important;
        }
        .tab-content,
        .tab-content h2,
        .tab-content h3,
        .tab-content h5,
        .tab-content h6,
        .tab-content p,
        .tab-content label,
        .tab-content span,
        .tab-content td,
        .tab-content th {
            color: #0f172a !important;
        }
        .tab-content .text-secondary,
        .tab-content .text-muted,
        .tab-content .opacity-7 {
            color: #0f172a !important;
            opacity: 1 !important;
        }
        .tab-content .table thead th,
        .tab-content table thead th {
            color: #0f172a !important;
            opacity: 1 !important;
        }
        .tab-content .table tbody td,
        .tab-content table tbody td {
            background-color: #fff;
            color: #0f172a !important;
        }
        .tab-content .table tbody tr:nth-child(even) td,
        .tab-content table tbody tr:nth-child(even) td {
            background-color: #f1f5f9;
        }
        .table-wrap th,
        .table-wrap td,
        .table-wrap td *,
        .table-wrap th * {
            color: #0f172a !important;
            opacity: 1 !important;
        }
        .tab-content .table td *,
        .tab-content .table th *,
        .tab-content table td *,
        .tab-content table th *,
        .tab-content .text-xs,
        .tab-content .text-sm,
        .tab-content .font-weight-bold {
            color: #0f172a !important;
            opacity: 1 !important;
        }
        .tab-content .btn,
        .tab-content .btn * {
            opacity: 1 !important;
        }
        .tab-content table,
        .tab-content table thead th,
        .tab-content table tbody td,
        .tab-content table tbody td span,
        .tab-content table tbody td h6,
        .tab-content table tbody td p,
        .tab-content table tbody td div,
        .tab-content table tbody td a,
        .tab-content .table-wrap table,
        .tab-content .table-wrap table thead th,
        .tab-content .table-wrap table tbody td,
        .tab-content .table-wrap table tbody td * {
            color: #0f172a !important;
            opacity: 1 !important;
        }
        .tab-content table thead th,
        .tab-content .table-wrap table thead th {
            background-color: #e2e8f0 !important;
        }
        .tab-content table .btn,
        .tab-content table .btn *,
        .tab-content .table-wrap table .btn,
        .tab-content .table-wrap table .btn * {
            color: #fff !important;
        }
        .tab-content table tbody tr:hover td,
        .tab-content .table-wrap table tbody tr:hover td {
            background-color: #dbeafe !important;
        }
        .manual-bank-table thead th {
            color: #0f172a !important;
            background-color: #e2e8f0 !important;
            opacity: 1 !important;
            font-weight: 700 !important;
        }
        .manual-bank-table tbody td {
            color: #0f172a !important;
            background-color: #fff !important;
            opacity: 1 !important;
            font-weight: 600 !important;
        }
        .manual-bank-table tbody tr:nth-child(even) td {
            background-color: #f1f5f9 !important;
        }

        /* --- Dark Theme Overrides --- */
        [data-bs-theme="dark"], body.dark, html[data-theme="dark"] {
            --paymentset-dark-text: #fff;
            --paymentset-light-bg: #181f2a;
            --paymentset-light-gray: #232b3b;
            --paymentset-light-table: #232b3b;
            --paymentset-light-green: #232b3b;
            --paymentset-light-orange: #f97316;
        }
        [data-bs-theme="dark"] .nav-tabs .nav-link,
        body.dark .nav-tabs .nav-link,
        html[data-theme="dark"] .nav-tabs .nav-link {
            background: linear-gradient(135deg, #232b3b 0%, #232b3b 100%) !important;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        [data-bs-theme="dark"] .nav-tabs .nav-link.active,
        [data-bs-theme="dark"] .nav-tabs .nav-link.active:hover,
        body.dark .nav-tabs .nav-link.active,
        html[data-theme="dark"] .nav-tabs .nav-link.active {
            background: linear-gradient(135deg, #232b3b 0%, #232b3b 100%) !important;
            color: #fff !important;
            border-bottom: 5px solid #f97316 !important;
            font-weight: 800;
        }
        [data-bs-theme="dark"] .tab-content,
        [data-bs-theme="dark"] .tab-content h2,
        [data-bs-theme="dark"] .tab-content h3,
        [data-bs-theme="dark"] .tab-content h5,
        [data-bs-theme="dark"] .tab-content h6,
        [data-bs-theme="dark"] .tab-content p,
        [data-bs-theme="dark"] .tab-content label,
        [data-bs-theme="dark"] .tab-content span,
        [data-bs-theme="dark"] .tab-content td,
        [data-bs-theme="dark"] .tab-content th,
        [data-bs-theme="dark"] .tab-content .form-label,
        [data-bs-theme="dark"] .tab-content .form-text,
        [data-bs-theme="dark"] .tab-content .form-control,
        [data-bs-theme="dark"] .tab-content .btn,
        [data-bs-theme="dark"] .tab-content .btn *,
        [data-bs-theme="dark"] .tab-content .table td *,
        [data-bs-theme="dark"] .tab-content .table th *,
        [data-bs-theme="dark"] .tab-content table td *,
        [data-bs-theme="dark"] .tab-content table th *,
        [data-bs-theme="dark"] .tab-content .text-xs,
        [data-bs-theme="dark"] .tab-content .text-sm,
        [data-bs-theme="dark"] .tab-content .font-weight-bold {
            color: #fff !important;
            opacity: 1 !important;
        }
        [data-bs-theme="dark"] .card,
        body.dark .card,
        html[data-theme="dark"] .card {
            background-color: #181f2a !important;
        }
        [data-bs-theme="dark"] .payment-method-wrapper,
        body.dark .payment-method-wrapper,
        html[data-theme="dark"] .payment-method-wrapper {
            background-color: #232b3b !important;
        }
        [data-bs-theme="dark"] .manual-bank-table thead th,
        body.dark .manual-bank-table thead th,
        html[data-theme="dark"] .manual-bank-table thead th {
            color: #fff !important;
            background-color: #232b3b !important;
        }
        [data-bs-theme="dark"] .manual-bank-table tbody td,
        body.dark .manual-bank-table tbody td,
        html[data-theme="dark"] .manual-bank-table tbody td {
            color: #fff !important;
            background-color: #181f2a !important;
        }
        [data-bs-theme="dark"] .manual-bank-table tbody tr:nth-child(even) td,
        body.dark .manual-bank-table tbody tr:nth-child(even) td,
        html[data-theme="dark"] .manual-bank-table tbody tr:nth-child(even) td {
            background-color: #232b3b !important;
        }
        [data-bs-theme="dark"] table thead th,
        body.dark table thead th,
        html[data-theme="dark"] table thead th {
            background-color: #232b3b !important;
            color: #fff !important;
        }
        [data-bs-theme="dark"] table tbody td,
        body.dark table tbody td,
        html[data-theme="dark"] table tbody td {
            background-color: #181f2a !important;
            color: #fff !important;
        }
        [data-bs-theme="dark"] table tbody tr:nth-child(even) td,
        body.dark table tbody tr:nth-child(even) td,
        html[data-theme="dark"] table tbody tr:nth-child(even) td {
            background-color: #232b3b !important;
        }
        [data-bs-theme="dark"] .tab-content table .btn,
        [data-bs-theme="dark"] .tab-content table .btn * {
            color: #fff !important;
        }

        /* Dark Theme Support for app-theme-dark class */
        body.app-theme-dark .form-control,
        body.app-theme-dark .form-select,
        body.app-theme-dark textarea {
            background-color: #1a233a !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
            color: #e2e8f0 !important;
        }

        body.app-theme-dark .form-control:focus,
        body.app-theme-dark .form-select:focus,
        body.app-theme-dark textarea:focus {
            background-color: #1a233a !important;
            border-color: #3b82f6 !important;
            color: #f1f5f9 !important;
        }

        body.app-theme-dark .form-label,
        body.app-theme-dark label {
            color: #e2e8f0 !important;
        }

        body.app-theme-dark .payment-method-card {
            background: #1a233a !important;
            color: #e2e8f0 !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
        }

        body.app-theme-dark .payment-method-card .pm-title,
        body.app-theme-dark .payment-method-card .pm-desc {
            color: #f1f5f9 !important;
        }

        body.app-theme-dark .nav-tabs {
            border-color: rgba(59, 130, 246, 0.2) !important;
        }

        body.app-theme-dark .nav-tabs .nav-link {
            background: #1a233a !important;
            color: #cbd5e1 !important;
        }

        body.app-theme-dark .nav-tabs .nav-link.active {
            background: rgba(59, 130, 246, 0.15) !important;
            border-color: #3b82f6 !important;
            color: #f1f5f9 !important;
        }

        body.app-theme-dark .tab-content {
            background: #0f172a !important;
            color: #e2e8f0 !important;
        }

        body.app-theme-dark .alert {
            background-color: #1a233a !important;
            border-color: rgba(59, 130, 246, 0.2) !important;
            color: #e2e8f0 !important;
        }

        body.app-theme-dark table {
            color: #e2e8f0 !important;
        }

        body.app-theme-dark table thead th {
            background-color: rgba(59, 130, 246, 0.15) !important;
            color: #f1f5f9 !important;
            border-color: rgba(59, 130, 246, 0.2) !important;
        }

        body.app-theme-dark table tbody td {
            background-color: #0f172a !important;
            border-color: rgba(59, 130, 246, 0.2) !important;
            color: #e2e8f0 !important;
        }

        body.app-theme-dark table tbody tr:hover td {
            background-color: rgba(59, 130, 246, 0.1) !important;
        }

        body.app-theme-dark .btn-light {
            background-color: #1a233a !important;
            border-color: rgba(59, 130, 246, 0.3) !important;
            color: #e2e8f0 !important;
        }

        body.app-theme-dark .btn-light:hover {
            background-color: rgba(59, 130, 246, 0.15) !important;
            color: #f1f5f9 !important;
        }
    </style>
        <div class="container">
            <div class="row">

<?php
// Handle form submit untuk default payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['default_payment'])) {
    $default_payment = mysqli_real_escape_string($conn, $_POST['default_payment']);
    $update_sql = "UPDATE user SET payment_default='$default_payment' WHERE username='".mysqli_real_escape_string($conn, $username)."'";
    if (mysqli_query($conn, $update_sql)) {
        echo "<script>alert('Default payment berhasil diupdate'); window.location='paymentset.php';</script>";
    } else {
        echo "<script>alert('Gagal mengupdate default payment');</script>";
    }
}

// Ambil setting default payment saat ini
$current_default = '';
$default_query = mysqli_query($conn, "SELECT payment_default FROM user WHERE username='".mysqli_real_escape_string($conn, $username)."'");
if ($default_row = mysqli_fetch_assoc($default_query)) {
    $current_default = $default_row['payment_default'] ?? 'manual_bank';
}

// Cek apakah ada data untuk setiap gateway
$has_tripay = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM tripay WHERE  pemilik='".mysqli_real_escape_string($conn, $ceknama)."' LIMIT 1")) > 0;
$has_duitku = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM duitku WHERE pemilik='".mysqli_real_escape_string($conn, $ceknama)."' LIMIT 1")) > 0;
$has_midtrans = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM midtrans WHERE pemilik='".mysqli_real_escape_string($conn, $ceknama)."' LIMIT 1")) > 0;
$has_xendit = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM xendit WHERE  pemilik='".mysqli_real_escape_string($conn, $ceknama)."' LIMIT 1")) > 0;

// ipaymu/doku/faspay tables are new (see migrations/2026-07-17_add_ipaymu_doku_faspay.sql).
// Guard against the migration not having run yet so this settings page doesn't fatal.
function payment_gateway_table_has_row($conn, $table, $owner) {
    $sql = "SELECT 1 FROM `$table` WHERE pemilik='" . mysqli_real_escape_string($conn, $owner) . "' LIMIT 1";
    try {
        $res = @mysqli_query($conn, $sql);
    } catch (\mysqli_sql_exception $e) {
        return false;
    }
    return $res ? mysqli_num_rows($res) > 0 : false;
}
$has_ipaymu = payment_gateway_table_has_row($conn, 'ipaymu', $ceknama);
$has_doku = payment_gateway_table_has_row($conn, 'doku', $ceknama);
$has_faspay = payment_gateway_table_has_row($conn, 'faspay', $ceknama);
$has_dompetx = payment_gateway_table_has_row($conn, 'dompetx', $ceknama);
?>

<div class="card shadow mb-4 payment-method-wrapper">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-cog"></i> Pengaturan Default Payment Method</h5>
    </div>
    <div class="card-body">
        <form method="POST" class="row align-items-center">
            <input type="hidden" name="default_payment" id="default_payment_hidden" value="<?php echo htmlspecialchars($current_default); ?>">
            <div class="col-12">
                <label class="form-label"><strong>Pilih Default Payment Method:</strong></label>
                <small class="form-text text-muted d-block">
                    Klik pada kartu di bawah untuk mengubah default payment method.
                </small>
            </div>
        </form>
        
        <div class="mt-3 p-3 bg-light rounded payment-method-wrapper">
            <div class="row text-center">
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card" style="height: 120px; cursor: pointer;" onclick="setDefault('manual_bank')">
                         <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Manual Bank</strong>
                        <?php echo ($current_default == 'manual_bank' ? '<br><small>✓ Default</small>' : ''); ?>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_tripay ? ($current_default == 'tripay' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_tripay ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_tripay ? 'onclick="setDefault(\'tripay\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Tripay</strong>
                        <br><small><?php echo ($current_default == 'tripay' ? '✓ Default' : ($has_tripay ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_duitku ? ($current_default == 'duitku' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_duitku ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_duitku ? 'onclick="setDefault(\'duitku\')"' : ''; ?>>
                          <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Duitku</strong>
                        <br><small><?php echo ($current_default == 'duitku' ? '✓ Default' : ($has_duitku ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_midtrans ? ($current_default == 'midtrans' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_midtrans ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_midtrans ? 'onclick="setDefault(\'midtrans\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Midtrans</strong>
                        <br><small><?php echo ($current_default == 'midtrans' ? '✓ Default' : ($has_midtrans ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_xendit ? ($current_default == 'xendit' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_xendit ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_xendit ? 'onclick="setDefault(\'xendit\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Xendit</strong>
                        <br><small><?php echo ($current_default == 'xendit' ? '✓ Default' : ($has_xendit ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_ipaymu ? ($current_default == 'ipaymu' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_ipaymu ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_ipaymu ? 'onclick="setDefault(\'ipaymu\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>iPaymu</strong>
                        <br><small><?php echo ($current_default == 'ipaymu' ? '✓ Default' : ($has_ipaymu ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_doku ? ($current_default == 'doku' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_doku ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_doku ? 'onclick="setDefault(\'doku\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>DOKU</strong>
                        <br><small><?php echo ($current_default == 'doku' ? '✓ Default' : ($has_doku ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_faspay ? ($current_default == 'faspay' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_faspay ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_faspay ? 'onclick="setDefault(\'faspay\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>Faspay</strong>
                        <br><small><?php echo ($current_default == 'faspay' ? '✓ Default' : ($has_faspay ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="border rounded p-2 d-flex flex-column align-items-center justify-content-center payment-method-card <?php echo $has_dompetx ? ($current_default == 'dompetx' ? 'border-success bg-success text-white' : 'border-secondary') : 'border-danger bg-danger text-white'; ?>" style="height: 120px; <?php echo $has_dompetx ? 'cursor: pointer;' : 'cursor: not-allowed; opacity: 0.6;'; ?>" <?php echo $has_dompetx ? 'onclick="setDefault(\'dompetx\')"' : ''; ?>>
                        <i class="fas fa-credit-card fa-2x mb-2"></i>
                        <strong>DompetX</strong>
                        <br><small><?php echo ($current_default == 'dompetx' ? '✓ Default' : ($has_dompetx ? '' : 'Tidak ada data apikey Merchant ')); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

 <div class="card shadow">
     <div class="card-header">
        <h6 class="mb-0" style="font-size: 1em;"><i class="fas fa-cog"></i> Pengaturan APIKEY Payment gateway </h6>
    </div>
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="paymentTabs" style="font-size: 0.90em;">
                <li class="nav-item">
                    <a class="nav-link active" data-target="#manualBank" style="font-size: 0.90em; font-weight: 500;">Pembayaran Manual Bank</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#tripayGateway" style="font-size: 0.90em; font-weight: 500;">Tripay Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#duitkuGateway" style="font-size: 0.90em; font-weight: 500;">Duitku Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#midtransGateway" style="font-size: 0.90em; font-weight: 500;">Midtrans Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#xenditGateway" style="font-size: 0.90em; font-weight: 500;">Xendit Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#ipaymuGateway" style="font-size: 0.90em; font-weight: 500;">iPaymu Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#dokuGateway" style="font-size: 0.90em; font-weight: 500;">DOKU Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#faspayGateway" style="font-size: 0.90em; font-weight: 500;">Faspay Payment Gateway</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-target="#dompetxGateway" style="font-size: 0.90em; font-weight: 500;">DompetX Payment Gateway</a>
                </li>
            </ul>
        </div>

        <?php
        // WAN IP server billing -- sama utk SEMUA payment gateway di bawah (bukan
        // per-gateway), krn ini IP outbound yg dipakai server saat curl ke API
        // gateway manapun. Ditampilkan supaya admin tinggal copy-paste ke kolom
        // IP Whitelist di dashboard masing-masing gateway (kalau gatewaynya
        // mewajibkan whitelist IP, mis. beberapa mode Xendit/Midtrans/Duitku).
        function paymentset_get_wan_ip(): string
        {
            $ch = curl_init('https://api.ipify.org');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            $ip = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            if ($error || !is_string($ip) || !filter_var(trim($ip), FILTER_VALIDATE_IP)) {
                return '';
            }
            return trim($ip);
        }
        $paymentset_wan_ip = paymentset_get_wan_ip();
        ?>
        <div class="card-body pb-0">
            <div class="d-flex align-items-center flex-wrap gap-2 mb-0 p-3" style="font-size: 0.95em; background-color: #fff3cd; border-left: 4px solid var(--orange); border-radius: 6px;">
                <i class="fas fa-network-wired" style="color: var(--orange);"></i>
                <span><strong>WAN IP server billing ini:</strong></span>
                <?php if ($paymentset_wan_ip !== ''): ?>
                    <code id="paymentsetWanIp" style="font-size: 1.15em; font-weight: 700; background: #212529; color: #fff; padding: 3px 10px; border-radius: 4px;"><?php echo htmlspecialchars($paymentset_wan_ip); ?></code>
                    <button type="button" class="btn btn-sm btn-dark" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($paymentset_wan_ip, ENT_QUOTES); ?>'); this.textContent='Tersalin!'; setTimeout(() => this.textContent='Salin', 1500);">Salin</button>
                    <span class="text-muted">-- pakai IP ini kalau payment gateway (Xendit/Midtrans/Duitku/dll) mewajibkan IP Whitelist di dashboard mereka.</span>
                <?php else: ?>
                    <span class="text-muted">Gagal mengambil WAN IP (server tidak bisa akses api.ipify.org). Coba muat ulang halaman.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-body tab-content">
            <div id="manualBank" class="active">
                <h6 style="font-size: 0.95em; font-weight: 600;">Pembayaran Manual Bank</h6>
              
                <style>
                    /* Form styling */
                    .mb-form { 
                        margin-top: 20px; 
                        display: grid; 
                        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
                        gap: 15px; 
                        padding: 20px;
                        background: var(--light-gray);
                        border-radius: 10px;
                    }
                    .mb-field {
                        display: flex;
                        flex-direction: column;
                        gap: 6px;
                    }
                    .mb-field-label {
                        font-size: 0.86em;
                        font-weight: 700;
                        color: #0f172a;
                        line-height: 1.2;
                    }
                    .mb-form input[type=text],
                    .mb-form input[type=number],
                    .mb-form input[type=hidden],
                    .mb-form select { 
                        padding: 12px 15px; 
                        border: 1.5px solid #cbd5e1;
                        border-radius: 8px; 
                        width: 100%; 
                        font-size: 14px;
                        transition: all 0.3s ease;
                        background-color: #ffffff;
                        color: #0f172a;
                        min-height: 44px;
                    }
                    .mb-form input::placeholder {
                        color: #64748b;
                        opacity: 1;
                    }
                    .mb-form input[type=text]:focus,
                    .mb-form input[type=number]:focus,
                    .mb-form select:focus { 
                        outline: none; 
                        border-color: #f97316;
                        box-shadow: 0 0 0 3px rgba(249,115,22,0.15);
                    }
                    [data-bs-theme="dark"] .mb-field-label,
                    body.dark .mb-field-label,
                    html[data-theme="dark"] .mb-field-label,
                    body.app-theme-dark .mb-field-label {
                        color: #e2e8f0;
                    }
                    [data-bs-theme="dark"] .mb-form input[type=text],
                    [data-bs-theme="dark"] .mb-form input[type=number],
                    [data-bs-theme="dark"] .mb-form select,
                    body.dark .mb-form input[type=text],
                    body.dark .mb-form input[type=number],
                    body.dark .mb-form select,
                    html[data-theme="dark"] .mb-form input[type=text],
                    html[data-theme="dark"] .mb-form input[type=number],
                    html[data-theme="dark"] .mb-form select,
                    body.app-theme-dark .mb-form input[type=text],
                    body.app-theme-dark .mb-form input[type=number],
                    body.app-theme-dark .mb-form select {
                        background-color: #0f172a !important;
                        border-color: #334155 !important;
                        color: #f8fafc !important;
                    }
                    [data-bs-theme="dark"] .mb-form input::placeholder,
                    body.dark .mb-form input::placeholder,
                    html[data-theme="dark"] .mb-form input::placeholder,
                    body.app-theme-dark .mb-form input::placeholder {
                        color: #94a3b8;
                        opacity: 1;
                    }
                    .mb-actions { 
                        display: flex; 
                        align-items: center; 
                        gap: 15px; 
                        grid-column: 1 / -1;
                        justify-content: center;
                        margin-top: 10px;
                    }
                    .mb-actions input[type=submit] { 
                        padding: 12px 24px; 
                        border: none; 
                        border-radius: 8px; 
                        background: linear-gradient(135deg, var(--green) 0%, var(--dark-green) 100%); 
                        color: var(--white); 
                        box-shadow: 0 4px 12px rgba(14,149,137,0.3); 
                        cursor: pointer; 
                        font-weight: 600;
                        transition: all 0.3s ease;
                    }
                    .mb-actions a { 
                        text-decoration: none; 
                        color: var(--white); 
                        padding: 8px 16px;
                        border: none;
                        border-radius: 8px;
                        transition: all 0.3s ease;
                        background-color: var(--orange);
                        box-shadow: 0 4px 12px rgba(247,148,29,0.3);
                        font-weight: 500;
                    }
                    .mb-actions a:hover {
                        background: #e08518;
                        color: var(--white);
                        box-shadow: 0 6px 18px rgba(247,148,29,0.4);
                        transform: translateY(-1px);
                    }
                    .mb-actions input[type=submit]:hover { 
                        background: linear-gradient(135deg, var(--dark-green) 0%, var(--green) 100%); 
                        box-shadow: 0 6px 18px rgba(14,149,137,0.4); 
                        transform: translateY(-2px);
                    }
                    
                    /* Table styling */
                    .table-wrap { 
                        margin-top: 30px; 
                        background: var(--white);
                        border-radius: 10px;
                        overflow: hidden;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    }
                    table { 
                        border-collapse: collapse; 
                        width: 100%; 
                        margin: 0;
                    }
                 
                    tr:nth-child(even){ 
                        background-color: var(--light-gray); 
                    }
                    tr:hover {
                        background-color: var(--light-green);
                        transition: all 0.3s ease;
                    }
                    td a { 
                        color: var(--white); 
                        text-decoration: none;
                        padding: 6px 12px;
                        border-radius: 4px;
                        transition: all 0.3s ease;
                        background-color: var(--orange);
                        margin: 0 2px;
                        display: inline-block;
                        font-size: 12px;
                        font-weight: 500;
                    }
                    td a:hover { 
                        background: #e08518;
                        color: var(--white);
                        transform: translateY(-1px);
                        box-shadow: 0 2px 8px rgba(247,148,29,0.3);
                    }
                    td a[style*="color: red"] {
                        background-color: #dc3545 !important;
                    }
                    td a[style*="color: red"]:hover {
                        background-color: #c82333 !important;
                    }
                    td a[style*="color: blue"] {
                        background-color: var(--green) !important;
                    }
                    td a[style*="color: blue"]:hover {
                        background-color: var(--dark-green) !important;
                    }
                    
                    /* Responsive */
                    @media (max-width: 768px) {
                        .mb-form { 
                            grid-template-columns: 1fr; 
                            padding: 15px;
                        }
                        .mb-actions { 
                            flex-direction: column;
                            align-items: stretch;
                        }
                        .mb-actions input[type=submit],
                        .mb-actions a {
                            width: 100%;
                            text-align: center;
                        }
                        table {
                            font-size: 12px;
                        }
                        th, td {
                            padding: 10px 8px;
                        }
                    }
                </style>
<h6 style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Informasi bank penerima pembayaran pelanggan <br>bank Pemilik: <?php echo htmlspecialchars($username); ?>  ( JIKA TIDAK MENGAKTIFKAN TRIPAY ) </h6>
               
                <div style="background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 10px 12px; margin-top: 10px; font-size: 0.85em; color: #856404;">
                    <strong style="font-size: 0.90em;">⚠ Pihak billing tidak bertanggung jawab atas kesalahan data informasi bank penerima, silakan isi dengan benar!</strong>
                </div>
                <!-- FORM INPUT / EDIT -->
      <form method="post" class="mb-form">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-field">
                        <label for="mb_nama_bank" class="mb-field-label">Nama Bank</label>
                        <input id="mb_nama_bank" type="text" name="nama_bank" placeholder="Contoh: BCA" required value="<?php echo $edit_data['nama_bank'] ?? ''; ?>">
                    </div>

                    <div class="mb-field">
                        <label for="mb_nama_pemilik_bank" class="mb-field-label">Nama Pemilik Bank</label>
                        <input id="mb_nama_pemilik_bank" type="text" name="nama_pemilik_bank" placeholder="Contoh: Quenby Teknik Sejahtera" required value="<?php echo $edit_data['nama_pemilik_bank'] ?? ''; ?>">
                    </div>

                    <div class="mb-field">
                        <label for="mb_rekening_bank" class="mb-field-label">Nomor Rekening</label>
                        <input id="mb_rekening_bank" type="text" name="rekening_bank" placeholder="Contoh: 1234567890" required value="<?php echo $edit_data['rekening_bank'] ?? ''; ?>">
                    </div>

                    <div class="mb-field">
                        <label for="mb_ppn" class="mb-field-label">PPN (%)</label>
                        <input id="mb_ppn" type="number" step="0.01" name="ppn" placeholder="Contoh: 11" value="<?php echo isset($edit_data['ppn']) ? $edit_data['ppn'] : '11'; ?>" required>
                    </div>

                    <div class="mb-field">
                        <label for="mb_server" class="mb-field-label">Server Area</label>
                        <select required class="form-control" id="mb_server" name="server" onchange="setAreaAddPackages()">
                            <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>








                     <div class="mb-actions">
                    <input type="submit" value="<?php echo $edit_data ? 'Update Data' : 'Tambah Data'; ?>">
                    <?php if ($edit_data): ?>
                             <a href="paymentset.php">Batal</a>
                    <?php endif; ?>
                     </div>
                </form>

                <!-- TABEL DATA -->
                <div class="table-wrap">
                <table class="manual-bank-table">
                    <thead>
                        <tr>
                            <th style="color:#0f172a !important;">ID</th>
                            <th style="color:#0f172a !important;">Nama Bank</th>
                            <th style="color:#0f172a !important;">Nama Pemilik Bank</th>
                            <th style="color:#0f172a !important;">Rekening Bank</th>
                            <th style="color:#0f172a !important;">Server</th>
                            <th style="color:#0f172a !important;">PPN (%)</th>
                            <th style="color:#0f172a !important;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (mysqli_num_rows($result) > 0) {
                            while ($row = mysqli_fetch_assoc($result)) {
                                echo "<tr>
                                    <td style='color:#0f172a !important; font-weight:600;'>{$row['id']}</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>{$row['nama_bank']}</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>{$row['nama_pemilik_bank']}</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>{$row['rekening_bank']}</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>{$row['server']}</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>" . (isset($row['ppn']) ? htmlspecialchars($row['ppn']) : '-') . "</td>
                                    <td style='color:#0f172a !important; font-weight:600;'>
                                        <a href='?edit={$row['id']}' style='color: blue;'>Edit</a> | 
                                        <a href='?hapus={$row['id']}' style='color: red;' onclick=\"return confirm('Yakin ingin menghapus data ini?')\">Hapus</a>
                                    </td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='8' style='color:#0f172a !important; font-weight:600;'>Tidak ada data apikey Merchant </td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
            </div>


            







            <div id="tripayGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">Tripay Payment Gateway</h6>
                <button type="button" class="btn btn-outline-dark btn-sm mb-2" onclick="openTestPaymentModal('tripay', 'Tripay')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <p style="font-size: 0.85em; color: #666;">Pilih metode pembayaran Tripay dan ikuti instruksi pembayaran.</p>
                <a href="https://tripay.co.id/merchant/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant Tripay</a>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan Tripay API</h6>
        <?php
        $domainreal = $config['URL']; // Mendapatkan nama domain
        // Ambil konfigurasi Tripay yang sudah ada (kalau ada) supaya form di
        // bawah ini pre-filled -- SEBELUMNYA form selalu kosong walau sudah
        // ada data tersimpan, jadi admin tidak bisa lihat/edit nilai yang
        // sudah diisi, cuma bisa timpa dari nol (dan wajib isi ulang SEMUA
        // field krn semuanya `required`).
        $existing_tripay = null;
        $stmt_existing_tripay = $conn->prepare("SELECT * FROM `tripay` WHERE `pemilik` = ? LIMIT 1");
        if ($stmt_existing_tripay) {
            $stmt_existing_tripay->bind_param('s', $ceknama);
            $stmt_existing_tripay->execute();
            $existing_tripay = $stmt_existing_tripay->get_result()->fetch_assoc();
            $stmt_existing_tripay->close();
        }
        ?>
        <div class="mb-3">
            <label for="payment_type" class="form-label">Collback default  ( Gunakan saat anda baru pengajuan mendaftar merchent tripay )</label>
            <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbacktripay/callback_tripay_$ceknama.php" ?></textarea>
        </div>

        <form action="proses/addtripay.php" method="POST">
            <div class="mb-3">
                <label for="api_key" class="form-label">PPN</label>
                <input type="number" class="form-control" id="merchant_code" name="ppn" placeholder="Masukkan Ppn Pajak (angka saja)" value="<?php echo htmlspecialchars($existing_tripay['pajak'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
            </div>
            <div class="mb-3">
                <label for="api_key" class="form-label">API Key</label>
                <input type="text" class="form-control" id="api_key" name="api_key" placeholder="Masukkan API Key" value="<?php echo htmlspecialchars($existing_tripay['apikey'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="merchant_code" class="form-label">Merchant Code</label>
                <input type="text" class="form-control" id="merchant_code" name="merchant_code" placeholder="Masukkan Merchant Code" value="<?php echo htmlspecialchars($existing_tripay['merchant'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="private_key" class="form-label">Private Key</label>
                <input type="text" class="form-control" id="private_key" name="private_key" placeholder="Masukkan Private Key" value="<?php echo htmlspecialchars($existing_tripay['privatekey'] ?? ''); ?>" required>
            </div>


            <div class="mb-3">
               <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="server" name="server" onchange="setAreaAddPackages()">
                          <option value="">-- Pilih Server Area --</option>
                        <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
            </div>


            <div class="mb-3">
                <label for="server" class="form-label">DEFAULT AUTH MODE </label>

                <select required class="form-control" id="radius" name="api_hotspot" onchange="loadArea()">
                    <option value="NO" <?php echo (($existing_tripay['api_hotspot'] ?? 'NO') === 'NO') ? 'selected' : ''; ?>>RADIUS MODE </option>
                    <option value="YES" <?php echo (($existing_tripay['api_hotspot'] ?? '') === 'YES') ? 'selected' : ''; ?>>MULTI MODE ( API dan RADIUS )</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan</button>
        </form>


        <style>
.table-custom {
  border: 1px solid #dee2e6 !important;
  border-collapse: collapse !important;
  border-radius: 8px;
  overflow: hidden;
  width: 100%;
}

.table-custom thead th {
  background: var(--light-green);
  text-align: center;
  font-size: 10px;
  text-transform: uppercase;
  font-weight: 700;
  color: var(--dark-green);
  border: 1px solid #dee2e6 !important;
}

.table-custom td {
  border: 1px solid #dee2e6 !important;
  vertical-align: middle !important;
  background-color: var(--white);
  color: var(--dark-gray);
}

.table-custom tbody tr:nth-child(even) td {
  background-color: var(--light-gray);
}

.table-custom tbody tr:hover td {
  background-color: var(--light-green);
  transition: 0.2s ease-in-out;
}
</style>


<div class="table-responsive">
   <table class="table table-bordered align-items-center mb-0" style="font-size: 10px; border: 1px solid #dee2e6;">
    <thead class="table-light">
        <tr>
            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server akun</th>
            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
           
        </tr>
    </thead>
    <tbody id="dataTable">
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tripay'])) {
            $id = intval($_POST['delete_tripay']);
            $sql = "DELETE FROM tripay WHERE id = $id";
            if (mysqli_query($conn, $sql)) {
                echo "<script>alert('Data berhasil dihapus'); window.location.href=document.referrer;</script>";
            } else {
                echo "<script>alert('Gagal menghapus data'); window.location.href=document.referrer;</script>";
            }
        }

        $sql = "SELECT * FROM `tripay` WHERE  `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";

        $query = mysqli_query($conn, $sql);

        while ($data = mysqli_fetch_array($query)) {
            $ip = $data['id'];
            setcookie('id', $ip);
        ?>
        <tr> <!-- ✅ Tambahkan <tr> di sini -->
            <td class="align-middle text-center text-sm">
                <span class="text-xs font-weight-bold"> <?php echo $pemilik = $data['pemilik']; ?> </span>
            </td>
            <td class="align-middle text-center text-sm">
                <div class="d-flex flex-column justify-content-center">
                    <h6 class="mb-0 text-sm">
                        Api key : <?php echo $data['apikey']; ?><br>
                        Private key : <?php echo $data['privatekey']; ?><br>
                        Merchant code : <?php echo $data['merchant']; ?><br>
                        PPN : <?php echo $data['pajak']; ?> %
                    </h6>
                </div>
            </td>
           
            <td class="align-middle text-center text-sm">
                <span class="text-xs font-weight-bold">
                <?php
                $pemilik = $data['pemilik'];
                $folder = 'callbacktripay/';
                $filename = "callback_tripay_$pemilik.php";
                $filepath = $folder . $filename;

                if (file_exists($filepath)) {
                ?>
                    <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo "$domainreal/crm/billing/callbacktripay/$filename"; ?>')">
                        COPY CALLBACK URL
                    </button>
                   

                    <?php $iframeCode = "<iframe src='$domainreal/crm/login'></iframe>"; ?>


                    <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                        <input type="hidden" name="delete_tripay" value="<?php echo $data['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                    </form>

                    <script>
                        function copyToClipboard(text) {
                            navigator.clipboard.writeText(text).then(() => alert("URL berhasil disalin!"));
                        }
                    </script>

                <?php
                } else {
                    $folder = 'callbacktripay/';
                    $allowFiles = ['callback_tripay.php'];
                    foreach ($allowFiles as $filename) {
                        $file = $folder . $filename;
                        if (!file_exists($file)) continue;
                        $baru = $folder . pathinfo($filename, PATHINFO_FILENAME) . "_$pemilik." . pathinfo($filename, PATHINFO_EXTENSION);
                        if (!file_exists($baru)) {
                            copy($file, $baru);
                            chmod($baru, 0777);
                            @chown($baru, 'qts');
                            @chgrp($baru, 'www-data');
                        }
                    }
                }
                ?>
                </span>
            </td>
        </tr>
        <?php } ?>
    </tbody>
</table>
   </div>










    </div>







    <div id="duitkuGateway">
    <h6 style="font-size: 0.95em; font-weight: 600;">Duitku Payment Gateway</h6>
    <a href="https://duitku.com/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant Duitku</a>
    <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('duitku', 'Duitku')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
    <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan Duitku API</h6>

         <?php
        // Ambil konfigurasi Duitku yang sudah ada supaya form pre-filled (lihat
        // catatan yang sama di section Tripay di atas).
        $existing_duitku = null;
        $stmt_existing_duitku = $conn->prepare("SELECT * FROM `duitku` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
        if ($stmt_existing_duitku) {
            $stmt_existing_duitku->bind_param('ss', $ceknama, $ceknama);
            $stmt_existing_duitku->execute();
            $existing_duitku = $stmt_existing_duitku->get_result()->fetch_assoc();
            $stmt_existing_duitku->close();
        }
        ?>
         <div class="mb-3">
            <label for="payment_type" class="form-label">Collback default  ( Gunakan saat anda baru pengajuan mendaftar merchent duitku )</label>
            <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackduitku/callback_duitku_$ceknama.php" ?></textarea>
        </div>
        <form action="proses/addduitku.php" method="POST">
        <div class="mb-3">
            <label for="dk_pajak" class="form-label">PPN</label>
            <input type="text" class="form-control" id="dk_pajak" name="dk_pajak" placeholder="Masukkan Ppn Pajak (angka saja)" value="<?php echo htmlspecialchars($existing_duitku['pajak'] ?? ''); ?>" required>
        </div>
        <div class="mb-3">
        </div>

            <div class="mb-3">
                <label for="dk_merchant_code" class="form-label">Merchant Code</label>
                <input type="text" class="form-control" id="dk_merchant_code" name="dk_merchant_code" placeholder="Masukkan Merchant Code" value="<?php echo htmlspecialchars($existing_duitku['merchant_code'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label for="dk_api_key" class="form-label">API Key</label>
                <input type="text" class="form-control" id="dk_api_key" name="dk_api_key" placeholder="Masukkan API Key" value="<?php echo htmlspecialchars($existing_duitku['api_key'] ?? ''); ?>" required>
            </div>

            <div class="mb-3">
               <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="dk_server" name="dk_server" onchange="setAreaAddPackages()">
                          <option value="">-- Pilih Server Area --</option>
                         <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
            </div>
            <div class="mb-3">
                <label for="dk_auth_mode" class="form-label">DEFAULT AUTH MODE</label>
                <select required class="form-control" id="dk_auth_mode" name="dk_auth_mode">
                    <option value="RADIUS MODE" <?php echo (($existing_duitku['default_auth_mode'] ?? 'RADIUS MODE') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                    <option value="MULTI MODE" <?php echo (($existing_duitku['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan Duitku</button>
        </form>
        
        <!-- Tabel Data Duitku -->
        <div class="table-responsive mt-4">
            <h6 class="mb-3" style="font-size: 0.90em; font-weight: 600;">Konfigurasi API Duitku yang Sudah Dibuat</h6>
            <table class="table align-items-center mb-0" style="font-size: 10px;">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                       
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Query untuk mengambil data Duitku berdasarkan pemilik
                    $sql_duitku = "SELECT * FROM `duitku` WHERE  `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                    $query_duitku = mysqli_query($conn, $sql_duitku);
                    
                    if (mysqli_num_rows($query_duitku) > 0) {
                        while ($data_duitku = mysqli_fetch_array($query_duitku)) {
                            $pemilik_duitku = $data_duitku['pemilik'];
                            $domainreal = $config['URL'];
                    ?>
                    <tr>
                        <td class="align-middle text-center text-sm">
                            <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_duitku); ?></span>
                        </td>
                        <td class="align-middle text-center text-sm">
                            <div class="d-flex flex-column justify-content-center">
                                <h6 class="mb-0 text-sm">
                                    Merchant Code: <?php echo htmlspecialchars($data_duitku['merchant_code']); ?><br>
                                    API Key: <?php echo htmlspecialchars($data_duitku['api_key']); ?><br>
                                    Return URL: <?php echo htmlspecialchars($data_duitku['return_url']); ?><br>
                                    PPN : <?php echo htmlspecialchars($data_duitku['pajak']); ?> %<br>
                                    Auth Mode: <?php echo htmlspecialchars($data_duitku['default_auth_mode'] ?? 'RADIUS MODE'); ?><br>
                                </h6>
                            </div>
                        </td>
                        <td class="align-middle text-center text-sm">
                            <span class="text-xs font-weight-bold">
                                <button class="btn btn-success mb-2" onclick="copyToClipboardDuitku('<?php echo $domainreal; ?>/crm/billing/callbackduitku/callback_duitku_<?php echo $pemilik_duitku; ?>.php')">
                                    COPY CALLBACK URL
                                </button>
                                <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                    <input type="hidden" name="delete_duitku" value="<?php echo $data_duitku['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </span>
                        </td>
                       
                    </tr>
                    <?php
                        }
                    } else {
                        echo "<tr><td colspan='4' class='text-center'>Tidak ada data apikey Merchant  konfigurasi Duitku</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>



            <div id="midtransGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">Midtrans Payment Gateway</h6>
                <a href="https://dashboard.midtrans.com/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant Midtrans</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('midtrans', 'Midtrans')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan Midtrans API</h6>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant Midtrans)</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackmidtrans/callback_midtrans_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi Midtrans yang sudah ada supaya form pre-filled.
                $existing_midtrans = null;
                $stmt_existing_midtrans = $conn->prepare("SELECT * FROM `midtrans` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_midtrans) {
                    $stmt_existing_midtrans->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_midtrans->execute();
                    $existing_midtrans = $stmt_existing_midtrans->get_result()->fetch_assoc();
                    $stmt_existing_midtrans->close();
                }
                ?>
                <form action="proses/addmidtrans.php" method="POST">
                    <div class="mb-3">
                        <label for="mt_pajak" class="form-label">PPN</label>
                        <input type="text" class="form-control" id="mt_pajak" name="mt_pajak" placeholder="Masukkan Ppn Pajak (angka saja)" value="<?php echo htmlspecialchars($existing_midtrans['pajak'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                    </div>

                    <div class="mb-3">
                        <label for="mt_merchant_id" class="form-label">Merchant ID</label>
                        <input type="text" class="form-control" id="mt_merchant_id" name="mt_merchant_id" placeholder="Masukkan Merchant ID" value="<?php echo htmlspecialchars($existing_midtrans['merchant_id'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="mt_server_key" class="form-label">Server Key</label>
                        <input type="text" class="form-control" id="mt_server_key" name="mt_server_key" placeholder="Masukkan Server Key" value="<?php echo htmlspecialchars($existing_midtrans['server_key'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="mt_client_key" class="form-label">Client Key</label>
                        <input type="text" class="form-control" id="mt_client_key" name="mt_client_key" placeholder="Masukkan Client Key" value="<?php echo htmlspecialchars($existing_midtrans['client_key'] ?? ''); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="mt_server" name="mt_server" onchange="setAreaAddPackages()">
                          <option value="">-- Pilih Server Area --</option>
                         <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="mt_auth_mode" class="form-label">DEFAULT AUTH MODE</label>
                        <select required class="form-control" id="mt_auth_mode" name="mt_auth_mode">
                              <option value="RADIUS MODE" <?php echo (($existing_midtrans['default_auth_mode'] ?? 'RADIUS MODE') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                    <option value="MULTI MODE" <?php echo (($existing_midtrans['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan Midtrans</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3" style="font-size: 0.90em; font-weight: 600;">Konfigurasi API Midtrans yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_mid = "SELECT * FROM `midtrans` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_mid = mysqli_query($conn, $sql_mid);
                            if (mysqli_num_rows($query_mid) > 0) {
                                while ($data_mid = mysqli_fetch_array($query_mid)) {
                                    $pemilik_mid = $data_mid['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_mid); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            Merchant ID: <?php echo htmlspecialchars($data_mid['merchant_id']); ?><br>
                                            Server Key: <?php echo htmlspecialchars($data_mid['server_key']); ?><br>
                                            Client Key: <?php echo htmlspecialchars($data_mid['client_key']); ?><br>
                                            PPN : <?php echo htmlspecialchars($data_mid['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_mid['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackmidtrans/callback_midtrans_<?php echo $pemilik_mid; ?>.php')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_midtrans" value="<?php echo $data_mid['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi Midtrans</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="xenditGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">Xendit Payment Gateway</h6>
                <a href="https://dashboard.xendit.co/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant Xendit</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('xendit', 'Xendit')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan Xendit API</h6>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant Xendit)</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackxendit/callback_xendit_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi Xendit yang sudah ada supaya form pre-filled.
                $existing_xendit = null;
                $stmt_existing_xendit = $conn->prepare("SELECT * FROM `xendit` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_xendit) {
                    $stmt_existing_xendit->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_xendit->execute();
                    $existing_xendit = $stmt_existing_xendit->get_result()->fetch_assoc();
                    $stmt_existing_xendit->close();
                }
                ?>
                <form action="proses/addxendit.php" method="POST">
                    <div class="mb-3">
                        <label for="xendit_merchant_id" class="form-label">Merchant ID</label>
                        <input type="text" class="form-control" id="xendit_merchant_id" name="xendit_merchant_id" placeholder="Masukkan Merchant ID" value="<?php echo htmlspecialchars($existing_xendit['merchant_id'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="xendit_server_key" class="form-label">Server Key</label>
                        <input type="text" class="form-control" id="xendit_server_key" name="xendit_server_key" placeholder="Masukkan Server Key" value="<?php echo htmlspecialchars($existing_xendit['server_key'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="xendit_client_key" class="form-label">Client Key</label>
                        <input type="text" class="form-control" id="xendit_client_key" name="xendit_client_key" placeholder="Masukkan Client Key" value="<?php echo htmlspecialchars($existing_xendit['client_key'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="xendit_pajak" class="form-label">PPN/Pajak</label>
                        <input type="text" class="form-control" id="xendit_pajak" name="xendit_pajak" placeholder="Masukkan Pajak (angka saja, opsional)" value="<?php echo htmlspecialchars($existing_xendit['pajak'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                    </div>
                    <div class="mb-3">
                        <label for="xendit_default_auth_mode" class="form-label">Default Auth Mode</label>
                        <select class="form-control" id="xendit_default_auth_mode" name="xendit_default_auth_mode" required>
                            <option value="API MODE" <?php echo (($existing_xendit['default_auth_mode'] ?? 'API MODE') === 'API MODE') ? 'selected' : ''; ?>>API MODE</option>
                            <option value="RADIUS MODE" <?php echo (($existing_xendit['default_auth_mode'] ?? '') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                      <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="xendit_server" name="xendit_server" onchange="setAreaAddPackages()">
                          <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                       
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan Xendit</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3">Konfigurasi API Xendit yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_xd = "SELECT * FROM `xendit` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_xd = mysqli_query($conn, $sql_xd);
                            if (mysqli_num_rows($query_xd) > 0) {
                                while ($data_xd = mysqli_fetch_array($query_xd)) {
                                    $pemilik_xd = $data_xd['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_xd); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            Merchant ID: <?php echo htmlspecialchars($data_xd['merchant_id']); ?><br>
                                            Server Key: <?php echo htmlspecialchars($data_xd['server_key']); ?><br>
                                            Client Key: <?php echo htmlspecialchars($data_xd['client_key']); ?><br>
                                            PPN : <?php echo htmlspecialchars($data_xd['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_xd['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>

                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackxendit/callback_xendit_<?php echo $pemilik_xd; ?>.php')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_xendit" value="<?php echo $data_xd['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi Xendit</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="ipaymuGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">iPaymu Payment Gateway</h6>
                <a href="https://ipaymu.com/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant iPaymu</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('ipaymu', 'iPaymu')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan iPaymu API</h6>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant iPaymu, isi di kolom "Notify URL")</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackipaymu/callback_ipaymu_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi iPaymu yang sudah ada supaya form pre-filled.
                $existing_ipaymu = null;
                $stmt_existing_ipaymu = $conn->prepare("SELECT * FROM `ipaymu` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_ipaymu) {
                    $stmt_existing_ipaymu->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_ipaymu->execute();
                    $existing_ipaymu = $stmt_existing_ipaymu->get_result()->fetch_assoc();
                    $stmt_existing_ipaymu->close();
                }
                ?>
                <form action="proses/addipaymu.php" method="POST">
                    <div class="mb-3">
                        <label for="ip_va" class="form-label">Nomor VA (Virtual Account)</label>
                        <input type="text" class="form-control" id="ip_va" name="ip_va" placeholder="Masukkan Nomor VA iPaymu" value="<?php echo htmlspecialchars($existing_ipaymu['va'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="ip_api_key" class="form-label">API Key</label>
                        <input type="text" class="form-control" id="ip_api_key" name="ip_api_key" placeholder="Masukkan API Key" value="<?php echo htmlspecialchars($existing_ipaymu['api_key'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="ip_pajak" class="form-label">PPN/Pajak</label>
                        <input type="text" class="form-control" id="ip_pajak" name="ip_pajak" placeholder="Masukkan Pajak (angka saja, opsional)" value="<?php echo htmlspecialchars($existing_ipaymu['pajak'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                    </div>
                    <div class="mb-3">
                        <label for="ip_default_auth_mode" class="form-label">Default Auth Mode</label>
                        <select class="form-control" id="ip_default_auth_mode" name="ip_default_auth_mode" required>
                            <option value="API MODE" <?php echo (($existing_ipaymu['default_auth_mode'] ?? 'API MODE') === 'API MODE') ? 'selected' : ''; ?>>API MODE</option>
                            <option value="RADIUS MODE" <?php echo (($existing_ipaymu['default_auth_mode'] ?? '') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                            <option value="MULTI MODE" <?php echo (($existing_ipaymu['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                      <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="ip_server" name="ip_server" onchange="setAreaAddPackages()">
                          <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan iPaymu</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3">Konfigurasi API iPaymu yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_ip = "SELECT * FROM `ipaymu` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_ip = @mysqli_query($conn, $sql_ip);
                            if ($query_ip && mysqli_num_rows($query_ip) > 0) {
                                while ($data_ip = mysqli_fetch_array($query_ip)) {
                                    $pemilik_ip = $data_ip['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_ip); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            VA: <?php echo htmlspecialchars($data_ip['va']); ?><br>
                                            API Key: <?php echo htmlspecialchars($data_ip['api_key']); ?><br>
                                            PPN : <?php echo htmlspecialchars($data_ip['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_ip['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackipaymu/callback_ipaymu_<?php echo $pemilik_ip; ?>.php')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_ipaymu" value="<?php echo $data_ip['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi iPaymu</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="dokuGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">DOKU Payment Gateway</h6>
                <a href="https://dashboard.doku.com/register" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant DOKU</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('doku', 'DOKU')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan DOKU API</h6>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant DOKU, isi di kolom "Notification URL")</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackdoku/callback_doku_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi DOKU yang sudah ada supaya form pre-filled.
                $existing_doku = null;
                $stmt_existing_doku = $conn->prepare("SELECT * FROM `doku` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_doku) {
                    $stmt_existing_doku->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_doku->execute();
                    $existing_doku = $stmt_existing_doku->get_result()->fetch_assoc();
                    $stmt_existing_doku->close();
                }
                ?>
                <form action="proses/addoku.php" method="POST">
                    <div class="mb-3">
                        <label for="dok_client_id" class="form-label">Client ID</label>
                        <input type="text" class="form-control" id="dok_client_id" name="dok_client_id" placeholder="Masukkan Client ID" value="<?php echo htmlspecialchars($existing_doku['client_id'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="dok_secret_key" class="form-label">Secret Key</label>
                        <input type="text" class="form-control" id="dok_secret_key" name="dok_secret_key" placeholder="Masukkan Secret Key" value="<?php echo htmlspecialchars($existing_doku['secret_key'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="dok_pajak" class="form-label">PPN/Pajak</label>
                        <input type="text" class="form-control" id="dok_pajak" name="dok_pajak" placeholder="Masukkan Pajak (angka saja, opsional)" value="<?php echo htmlspecialchars($existing_doku['pajak'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                    </div>
                    <div class="mb-3">
                        <label for="dok_default_auth_mode" class="form-label">Default Auth Mode</label>
                        <select class="form-control" id="dok_default_auth_mode" name="dok_default_auth_mode" required>
                            <option value="API MODE" <?php echo (($existing_doku['default_auth_mode'] ?? 'API MODE') === 'API MODE') ? 'selected' : ''; ?>>API MODE</option>
                            <option value="RADIUS MODE" <?php echo (($existing_doku['default_auth_mode'] ?? '') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                            <option value="MULTI MODE" <?php echo (($existing_doku['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                      <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="dok_server" name="dok_server" onchange="setAreaAddPackages()">
                          <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan DOKU</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3">Konfigurasi API DOKU yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_dk2 = "SELECT * FROM `doku` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_dk2 = @mysqli_query($conn, $sql_dk2);
                            if ($query_dk2 && mysqli_num_rows($query_dk2) > 0) {
                                while ($data_dk2 = mysqli_fetch_array($query_dk2)) {
                                    $pemilik_dk2 = $data_dk2['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_dk2); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            Client ID: <?php echo htmlspecialchars($data_dk2['client_id']); ?><br>
                                            Secret Key: <?php echo htmlspecialchars($data_dk2['secret_key']); ?><br>
                                            PPN : <?php echo htmlspecialchars($data_dk2['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_dk2['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackdoku/callback_doku_<?php echo $pemilik_dk2; ?>.php')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_doku" value="<?php echo $data_dk2['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi DOKU</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="faspayGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">Faspay Payment Gateway</h6>
                <a href="https://faspay.co.id" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant Faspay</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('faspay', 'Faspay')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan Faspay API</h6>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant Faspay, isi di kolom "URL Callback")</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackfaspay/callback_faspay_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi Faspay yang sudah ada supaya form pre-filled.
                $existing_faspay = null;
                $stmt_existing_faspay = $conn->prepare("SELECT * FROM `faspay` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_faspay) {
                    $stmt_existing_faspay->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_faspay->execute();
                    $existing_faspay = $stmt_existing_faspay->get_result()->fetch_assoc();
                    $stmt_existing_faspay->close();
                }
                ?>
                <form action="proses/addfaspay.php" method="POST">
                    <div class="mb-3">
                        <label for="fp_merchant_id" class="form-label">Merchant ID</label>
                        <input type="text" class="form-control" id="fp_merchant_id" name="fp_merchant_id" placeholder="Masukkan Merchant ID" value="<?php echo htmlspecialchars($existing_faspay['merchant_id'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="fp_user_id" class="form-label">User ID</label>
                        <input type="text" class="form-control" id="fp_user_id" name="fp_user_id" placeholder="Masukkan User ID" value="<?php echo htmlspecialchars($existing_faspay['user_id'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="fp_password" class="form-label">Password</label>
                        <input type="text" class="form-control" id="fp_password" name="fp_password" placeholder="Masukkan Password" value="<?php echo htmlspecialchars($existing_faspay['password'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="fp_pajak" class="form-label">PPN/Pajak</label>
                        <input type="text" class="form-control" id="fp_pajak" name="fp_pajak" placeholder="Masukkan Pajak (angka saja, opsional)" value="<?php echo htmlspecialchars($existing_faspay['pajak'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                    </div>
                    <div class="mb-3">
                        <label for="fp_default_auth_mode" class="form-label">Default Auth Mode</label>
                        <select class="form-control" id="fp_default_auth_mode" name="fp_default_auth_mode" required>
                            <option value="API MODE" <?php echo (($existing_faspay['default_auth_mode'] ?? 'API MODE') === 'API MODE') ? 'selected' : ''; ?>>API MODE</option>
                            <option value="RADIUS MODE" <?php echo (($existing_faspay['default_auth_mode'] ?? '') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                            <option value="MULTI MODE" <?php echo (($existing_faspay['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                      <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="fp_server" name="fp_server" onchange="setAreaAddPackages()">
                          <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan Faspay</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3">Konfigurasi API Faspay yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_fp = "SELECT * FROM `faspay` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_fp = @mysqli_query($conn, $sql_fp);
                            if ($query_fp && mysqli_num_rows($query_fp) > 0) {
                                while ($data_fp = mysqli_fetch_array($query_fp)) {
                                    $pemilik_fp = $data_fp['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_fp); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            Merchant ID: <?php echo htmlspecialchars($data_fp['merchant_id']); ?><br>
                                            User ID: <?php echo htmlspecialchars($data_fp['user_id']); ?><br>
                                            PPN : <?php echo htmlspecialchars($data_fp['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_fp['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackfaspay/callback_faspay_<?php echo $pemilik_fp; ?>.php')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_faspay" value="<?php echo $data_fp['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi Faspay</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="dompetxGateway">
                <h6 style="font-size: 0.95em; font-weight: 600;">DompetX Payment Gateway</h6>
                <a href="https://dompetx.com/" target="_blank" class="btn btn-warning btn-sm mb-3" style="font-size: 0.85em; font-weight: 600;"><i class="fas fa-user-plus me-1"></i> Daftar Merchant DompetX</a>
                <button type="button" class="btn btn-outline-dark btn-sm mb-3" onclick="openTestPaymentModal('dompetx', 'DompetX')"><i class="fas fa-vial me-1"></i>Test Transaksi</button>
                <h6 class="text-center" style="font-size: 0.90em; font-weight: 600; margin-top: 15px;">Pengaturan DompetX API</h6>
                <div class="alert alert-warning" style="font-size: 0.8em;">
                    <i class="fas fa-triangle-exclamation"></i> Dokumentasi resmi DompetX (docs.dompetx.com) memblokir akses otomatis saat integrasi ini dibuat, jadi skema signature callback/webhook belum bisa diverifikasi 100% dari sumber resminya. Cek riwayat transaksi setelah pembayaran pertama untuk memastikan status ter-update otomatis; kalau tidak, hubungi support DompetX untuk memastikan format callback-nya.
                </div>

                <div class="mb-3">
                    <label for="payment_type" class="form-label">Callback default (Gunakan saat mendaftar merchant DompetX, isi di kolom "Webhook URL")</label>
                    <textarea class="form-control"><?php echo "$domainreal/crm/billing/callbackdompetx/callback_dompetx_$ceknama.php" ?></textarea>
                </div>

                <?php
                // Ambil konfigurasi DompetX yang sudah ada supaya form pre-filled.
                $existing_dompetx = null;
                $stmt_existing_dompetx = $conn->prepare("SELECT * FROM `dompetx` WHERE `server` = ? AND `pemilik` = ? LIMIT 1");
                if ($stmt_existing_dompetx) {
                    $stmt_existing_dompetx->bind_param('ss', $ceknama, $ceknama);
                    $stmt_existing_dompetx->execute();
                    $existing_dompetx = $stmt_existing_dompetx->get_result()->fetch_assoc();
                    $stmt_existing_dompetx->close();
                }
                ?>
                <form action="proses/adddompetx.php" method="POST">
                    <div class="mb-3">
                        <label for="dx_api_key" class="form-label">API Key</label>
                        <input type="text" class="form-control" id="dx_api_key" name="dx_api_key" placeholder="Masukkan API Key DompetX" value="<?php echo htmlspecialchars($existing_dompetx['api_key'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="dx_secret_key" class="form-label">Secret Key (Opsional)</label>
                        <input type="text" class="form-control" id="dx_secret_key" name="dx_secret_key" placeholder="Kosongkan jika DompetX cuma kasih satu API Key" value="<?php echo htmlspecialchars($existing_dompetx['secret_key'] ?? ''); ?>">
                        <small class="text-muted">Kosongkan kalau di dashboard DompetX cuma ada satu "API Key" (dipakai juga sebagai secret signature). Isi kalau ada field "Secret Key" terpisah.</small>
                    </div>
                    <div class="mb-3">
                        <label for="dx_pajak" class="form-label">PPN/Pajak</label>
                        <input type="text" class="form-control" id="dx_pajak" name="dx_pajak" placeholder="Masukkan Pajak (angka saja, opsional)" value="<?php echo htmlspecialchars($existing_dompetx['pajak'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                    </div>
                    <div class="mb-3">
                        <label for="dx_default_auth_mode" class="form-label">Default Auth Mode</label>
                        <select class="form-control" id="dx_default_auth_mode" name="dx_default_auth_mode" required>
                            <option value="API MODE" <?php echo (($existing_dompetx['default_auth_mode'] ?? 'API MODE') === 'API MODE') ? 'selected' : ''; ?>>API MODE</option>
                            <option value="RADIUS MODE" <?php echo (($existing_dompetx['default_auth_mode'] ?? '') === 'RADIUS MODE') ? 'selected' : ''; ?>>RADIUS MODE</option>
                            <option value="MULTI MODE" <?php echo (($existing_dompetx['default_auth_mode'] ?? '') === 'MULTI MODE') ? 'selected' : ''; ?>>MULTI MODE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                      <label for="server" class="form-label">Server Area</label>
                        <select required class="form-control" id="dx_server" name="dx_server" onchange="setAreaAddPackages()">
                          <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan DompetX</button>
                </form>

                <div class="table-responsive mt-4">
                    <h6 class="mb-3">Konfigurasi API DompetX yang Sudah Dibuat</h6>
                    <table class="table align-items-center mb-0" style="font-size: 10px;">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Akun</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql_dx = "SELECT * FROM `dompetx` WHERE `pemilik`='".mysqli_real_escape_string($conn, $ceknama)."'";
                            $query_dx = @mysqli_query($conn, $sql_dx);
                            if ($query_dx && mysqli_num_rows($query_dx) > 0) {
                                while ($data_dx = mysqli_fetch_array($query_dx)) {
                                    $pemilik_dx = $data_dx['pemilik'];
                                    $domainreal = $config['URL'];
                            ?>
                            <tr>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($pemilik_dx); ?></span>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">
                                            API Key: <?php echo htmlspecialchars($data_dx['api_key']); ?><br>
                                            Secret Key: <?php echo $data_dx['secret_key'] ? htmlspecialchars($data_dx['secret_key']) : '(pakai API Key)'; ?><br>
                                            PPN : <?php echo htmlspecialchars($data_dx['pajak']); ?> %<br>
                                            Auth Mode: <?php echo htmlspecialchars($data_dx['default_auth_mode'] ?? 'API MODE'); ?><br>
                                        </h6>
                                    </div>
                                </td>
                                <td class="align-middle text-center text-sm">
                                    <span class="text-xs font-weight-bold">
                                        <?php
                                        // Auto-buat file callback per akun dari base callback_dompetx.php,
                                        // sama seperti pola yang sudah ada untuk Tripay -- tanpa file per akun
                                        // ini, $ownerbilling di callback_dompetx.php akan terbaca "dompetx"
                                        // (bukan nama akun), bukan akun pemilik yang sebenarnya.
                                        $folder_dx = 'callbackdompetx/';
                                        $filename_dx = "callback_dompetx_$pemilik_dx.php";
                                        $filepath_dx = $folder_dx . $filename_dx;
                                        if (!file_exists($filepath_dx)) {
                                            $base_dx = $folder_dx . 'callback_dompetx.php';
                                            if (file_exists($base_dx)) {
                                                copy($base_dx, $filepath_dx);
                                                chmod($filepath_dx, 0777);
                                                @chown($filepath_dx, 'qts');
                                                @chgrp($filepath_dx, 'www-data');
                                            }
                                        }
                                        ?>
                                        <button class="btn btn-success mb-2" onclick="copyToClipboard('<?php echo $domainreal; ?>/crm/billing/callbackdompetx/<?php echo $filename_dx; ?>')">
                                            COPY CALLBACK URL
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus data ini?');">
                                            <input type="hidden" name="delete_dompetx" value="<?php echo $data_dx['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </span>
                                </td>
                            </tr>
                            <?php
                                }
                            } else {
                                echo "<tr><td colspan='3' class='text-center'>Tidak ada data apikey Merchant  konfigurasi DompetX</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
 </div>





<?php
if (!empty($paymentset_messages)) {
    echo implode("\n", $paymentset_messages);
}
?>

<div class="card shadow mb-4 mt-4">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">Konfigurasi aktif (Fixed Due Date)</h6>
    </div>
    <div class="card-body">
        <?php if (!empty($reminder_table_rows)): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Jatuh Tempo</th>
                            <th>Hari Sebelum</th>
                            <th>Tanggal Reminder</th>
                            <th>Jam</th>
                            <th>BOT</th>
                            <th>Awal Tutup Buku</th>
                            <th>Akhir Tutup Buku</th>
                            <th>Prorate saat Telat</th>
                            <th>Periode Tercatat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reminder_table_rows as $index => $row): ?>
                            <?php $rowProrateTelat = !isset($row['prorate_untuk_telat']) || !empty($row['prorate_untuk_telat']); ?>
                            <?php $rowPeriodeTercatat = ($row['periode_tercatat'] ?? 'berjalan') === 'berikutnya' ? 'berikutnya' : 'berjalan'; ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($row['jatuh_tempo'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['hari_sebelum'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_reminder'] ?? '-'); ?></td>
                                <td><?php echo sprintf('%02d:%02d', (int)($row['jam_reminder'] ?? 0), (int)($row['menit_reminder'] ?? 0)); ?></td>
                                <td><?php echo htmlspecialchars($row['botname'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_awal_tutup_buku'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal_akhir_tutup_buku'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $rowProrateTelat ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $rowProrateTelat ? 'ON (tetap prorate)' : 'OFF (full 1 bulan)'; ?></span></td>
                                <td><span class="badge <?php echo $rowPeriodeTercatat === 'berikutnya' ? 'bg-info text-dark' : 'bg-secondary'; ?>"><?php echo $rowPeriodeTercatat === 'berikutnya' ? 'Periode Berikutnya' : 'Periode Berjalan'; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary">Belum ada konfigurasi Fixed Due Date.</div>
        <?php endif; ?>

        <form method="post" class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="fixed_jatuh_tempo">Tanggal Jatuh Tempo Isolir (1-31)</label>
                <input type="number" id="fixed_jatuh_tempo" class="form-control" name="jatuh_tempo" min="1" max="31" required value="<?php echo htmlspecialchars($existing_jatuh_tempo); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_tanggal_reminder">Tanggal Reminder (1-31)</label>
                <input type="number" id="fixed_tanggal_reminder" class="form-control" name="tanggal_reminder" min="1" max="31" required value="<?php echo htmlspecialchars($existing_tanggal_reminder); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_waktu_reminder">Waktu Reminder</label>
                <input type="time" id="fixed_waktu_reminder" class="form-control" name="waktu_reminder" required value="<?php echo htmlspecialchars($existing_waktu_reminder); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_botname">Pilih BOT</label>
                <select class="form-control" id="fixed_botname" name="botname" required>
                    <option value="">-- Pilih BOT --</option>
                    <option value="RANDOM" <?php echo $existing_botname === 'RANDOM' ? 'selected' : ''; ?>>RANDOM</option>
                    <option value="-" <?php echo $existing_botname === '-' ? 'selected' : ''; ?>>none</option>
                    <?php foreach ($bot_options as $bot_name): ?>
                        <option value="<?php echo htmlspecialchars($bot_name, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $existing_botname === $bot_name ? 'selected' : ''; ?>><?php echo htmlspecialchars($bot_name, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_awal_tutup_buku">Tanggal Awal Tutup Buku (1-31)</label>
                <input type="number" id="fixed_awal_tutup_buku" class="form-control" name="tanggal_awal_tutup_buku" min="1" max="31" required value="<?php echo htmlspecialchars($existing_tanggal_awal_tutup_buku); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_akhir_tutup_buku">Tanggal Akhir Tutup Buku (1-31)</label>
                <input type="number" id="fixed_akhir_tutup_buku" class="form-control" name="tanggal_akhir_tutup_buku" min="1" max="31" required value="<?php echo htmlspecialchars($existing_tanggal_akhir_tutup_buku); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fixed_periode_tercatat">Periode Tercatat</label>
                <select class="form-control" id="fixed_periode_tercatat" name="periode_tercatat">
                    <option value="berjalan" <?php echo $existing_periode_tercatat === 'berjalan' ? 'selected' : ''; ?>>Periode Berjalan</option>
                    <option value="berikutnya" <?php echo $existing_periode_tercatat === 'berikutnya' ? 'selected' : ''; ?>>Periode Berikutnya</option>
                </select>
                <div class="form-text">
                    Menentukan label periode penggunaan (PENGUNAAN) yang tercatat di transaksi -- baik saat invoice PENAGIHAN digenerate maupun saat ada pembayaran BERHASIL tanpa invoice yang sudah ada.
                    <b>Periode Berjalan</b> (default): periode tercatat = bulan yang sama dengan tanggal jatuh tempo (mis. jatuh tempo 25 Agustus 2026 -> periode "Agustus 2026").
                    <b>Periode Berikutnya</b>: periode tercatat = 1 bulan setelah bulan jatuh tempo (mis. jatuh tempo 25 Agustus 2026 -> periode "September 2026").
                </div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="prorate_untuk_telat" name="prorate_untuk_telat" value="1" <?php echo $existing_prorate_telat ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="prorate_untuk_telat">Tetap berikan prorate untuk pelanggan yang telat bayar</label>
                </div>
                <div class="form-text">
                    <b>ON</b> (default): pelanggan Fixed Due Date yang baru bayar setelah lewat tanggal jatuh tempo tetap dapat tagihan prorate (sesuai perilaku selama ini).
                    <b>OFF</b>: pelanggan yang telat (sudah lewat tanggal jatuh tempo &amp; belum bayar) langsung ditagih harga penuh 1 bulan di portal bayar, tanpa potongan prorate.
                </div>
            </div>
            <div class="col-12">
                <div class="form-text">
                    <i class="fas fa-info-circle me-1"></i>Filter "Skip pelanggan sudah bayar" &amp; "Skip sudah dinotif hari ini" sekarang diatur di menu <b>Notifikasi -> Reminder Pembayaran</b>.
                </div>
            </div>
            <div class="col-12">
                <button type="submit" name="simpan_reminder_fixed_due_date" class="btn btn-primary">Simpan Konfigurasi Fixed Due Date</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow mb-4 mt-4">
    <div class="card-header bg-info text-white">
        <h6 class="mb-0">Waktu Tunggu Prabayar (Hari)</h6>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="prabayar_grace_period">Waktu tunggu setelah pemasangan sebelum isolir otomatis</label>
                <input type="number" min="0" max="30" class="form-control" id="prabayar_grace_period" name="prabayar_grace_period" value="<?php echo (int)$prabayar_grace_period; ?>" required>
                <div class="form-text">Jika 0, isolir langsung tanpa menunggu. Default: 2 hari.</div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-info w-100" name="simpan_prabayar_grace_period">Simpan Waktu Tunggu</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow mb-4 mt-4">
    <div class="card-header bg-dark text-white">
        <h6 class="mb-0"><i class="fas fa-circle-info me-1"></i>Penjelasan Tipe Tempo</h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Tiga mode jatuh tempo yang tersedia di sistem ini (diatur per pelanggan lewat field <b>Tipe Tempo</b> di data pelanggan). Perbedaan paling penting: bagaimana jatuh tempo BERIKUTNYA berubah kalau pelanggan bayar lebih cepat atau lebih lambat dari jadwal.</p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle small mb-2">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:140px;">Tipe Tempo</th>
                        <th>Cara Kerja</th>
                        <th style="min-width:180px;">Bayar Lebih Cepat</th>
                        <th style="min-width:180px;">Bayar Lebih Lambat / Telat</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="badge bg-secondary">Fixed Due Date</span></td>
                        <td>SEMUA pelanggan pakai 1 tanggal jatuh tempo global yang sama (diatur di "Konfigurasi Fixed Due Date" di bawah), tidak peduli kapan pelanggan pasang atau bayar.</td>
                        <td class="text-success">Jatuh tempo bulan berikutnya <b>TETAP</b> di tanggal global yang sama.</td>
                        <td class="text-success">Jatuh tempo bulan berikutnya <b>TETAP</b> di tanggal global yang sama (pelanggan cuma berstatus nunggak sampai bayar).</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-info text-dark">Rolling Due Date</span></td>
                        <td>Siklus tepat 30 hari kalender, dihitung dari histori pembayaran BERHASIL pelanggan itu sendiri (per pelanggan masing-masing, tidak ada tanggal global).</td>
                        <td class="text-success">Jatuh tempo berikutnya <b>TIDAK ikut maju</b> -- jadwal cuma geser 1 siklus penuh (30 hari) dari rencana semula, tidak dihadiahi bayar cepat.</td>
                        <td class="text-warning">Jatuh tempo berikutnya <b>IKUT MAJU</b> -- dihitung ulang dari tanggal bayar (yang telat itu) + 30 hari.</td>
                    </tr>
                    <tr>
                        <td><span class="badge bg-primary">Monthversary</span></td>
                        <td>Jatuh tempo = "tanggal anchor" yang sama tiap bulan (diambil dari tanggal pasang/aktivasi awal pelanggan), per pelanggan masing-masing.</td>
                        <td class="text-success">Jatuh tempo berikutnya <b>TETAP</b> di hari anchor yang sama.</td>
                        <td class="text-success">Jatuh tempo berikutnya <b>TETAP</b> di hari anchor yang sama <span class="text-muted">(default/OFF)</span> -- <span class="text-warning">kecuali toggle "ikut tanggal bayar terakhir" di bawah di-ON-kan, baru IKUT MAJU mirip Rolling.</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="alert alert-warning small mb-0">
            <b><i class="fas fa-triangle-exclamation me-1"></i>Khusus Monthversary:</b> toggle <b>"Jatuh tempo Monthversary ikut tanggal bayar terakhir"</b> di card di bawah ini (kalau ON) berlaku ASIMETRIS sama persis seperti Rolling: bayar CEPAT/PAS tidak mengubah apa-apa, anchor cuma ikut geser kalau ada pembayaran yang TELAT. Kalau ada invoice yang sudah terlanjur digenerate SEBELUM anchor bergeser, tampilan "Jatuh tempo berikutnya" di halaman Customer PPPoE akan tetap menampilkan tanggal anchor ASLI-nya, dengan tanda merah "(mundur N hari, jadi tanggal ...)" di sebelahnya kalau memang sudah bergeser -- bukan diam-diam berubah tanpa keterangan.
        </div>
    </div>
</div>

<div class="card shadow mb-4 mt-4">
    <div class="card-header bg-info text-white">
        <h6 class="mb-0">Pengaturan Monthversary</h6>
    </div>
    <div class="card-body">
        <form method="post">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="monthversary_follow_last_payment" name="monthversary_follow_last_payment" value="1" <?php echo $monthversary_follow_last_payment ? 'checked' : ''; ?>>
                <label class="form-check-label" for="monthversary_follow_last_payment">Jatuh tempo Monthversary ikut tanggal bayar terakhir</label>
            </div>
            <div class="form-text mb-3">
                <b>OFF</b> (default): tanggal jatuh tempo pelanggan mode Monthversary TETAP terkunci ke tanggal pasang/aktivasi awal, apapun kapan pelanggan bayar (cepat maupun telat).<br>
                <b>ON</b>: ASIMETRIS mirip Rolling Due Date --
                <span class="text-success">bayar CEPAT/PAS (mis. anchor tgl 10, dibayar tgl 8) jatuh tempo TETAP tgl 10</span>, tapi
                <span class="text-warning">bayar TELAT (mis. anchor tgl 10, dibayar tgl 14) jatuh tempo berikutnya IKUT MAJU jadi tgl 14 bulan depan</span>.
                Berlaku untuk SEMUA pelanggan Monthversary di akun ini.
            </div>
            <button type="submit" class="btn btn-info" name="simpan_monthversary_setting">Simpan Pengaturan Monthversary</button>
        </form>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header bg-primary text-white">
        <h6 class="mb-0">Invoice Generator Otomatis (Status PENAGIHAN)</h6>
    </div>
    <div class="card-body">
        <form method="post">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Status Generator</label>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="paymentset_invoice_generator_enabled" name="invoice_generator_enabled" <?php echo $invoice_generator_enabled ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="paymentset_invoice_generator_enabled">Aktif</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="paymentset_invoice_generate_hour">Jam Cron Jalan</label>
                    <input type="number" min="0" max="23" class="form-control" id="paymentset_invoice_generate_hour" name="invoice_generate_hour" value="<?php echo (int)$invoice_generate_hour; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="paymentset_invoice_generate_minute">Menit Cron Jalan</label>
                    <input type="number" min="0" max="59" class="form-control" id="paymentset_invoice_generate_minute" name="invoice_generate_minute" value="<?php echo (int)$invoice_generate_minute; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="paymentset_invoice_generate_days_before_due">Terbit H- sblm jatuh tempo</label>
                    <input type="number" min="0" max="30" class="form-control" id="paymentset_invoice_generate_days_before_due" name="invoice_generate_days_before_due" value="<?php echo (int)$invoice_generate_days_before_due; ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100" name="simpan_invoice_generator">Simpan Invoice Generator</button>
                </div>
            </div>
            <div class="form-text mt-2">
                Cron jalan tiap hari jam:menit di atas, lalu cek per-pelanggan: invoice PENAGIHAN cuma diterbitkan begitu sisa hari ke tanggal jatuh tempo <= <b>"Terbit H- sblm jatuh tempo"</b>. Berlaku SAMA utk ketiga tipe tempo -- <b>Fixed Due Date</b> (jatuh tempo = tanggal "jatuh_tempo" di Konfigurasi Fixed Due Date, sama utk semua pelanggan) maupun <b>Monthversary</b> &amp; <b>Rolling</b> (jatuh tempo per-pelanggan masing-masing). Invoice yang kadung terbit lebih awal dari H- ini (sisa hari masih lebih banyak dari setting) otomatis dibersihkan oleh cron tanpa mengganggu tagihan yang sudah dekat/lewat jatuh tempo.
            </div>
        </form>
    </div>
</div>

<!-- Bootstrap JS & Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function setDefault(value) {
        document.getElementById('default_payment_hidden').value = value;
        document.querySelector('form[method="POST"]').submit();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('#paymentTabs .nav-link');
        const contents = document.querySelectorAll('.tab-content > div');

        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Hapus active dari semua tab dan konten
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => {
                    c.classList.remove('active');
                    c.style.display = 'none';
                });

                // Aktifkan tab yang diklik
                tab.classList.add('active');
                const targetId = tab.getAttribute('data-target');
                const target = document.querySelector(targetId);
                
                if (target) {
                    setTimeout(() => {
                        target.style.display = 'block';
                        setTimeout(() => {
                            target.classList.add('active');
                        }, 10);
                    }, 100);
                }
            });
        });

        // Pastikan tab pertama aktif saat load
        if (tabs.length > 0 && contents.length > 0) {
            tabs[0].classList.add('active');
            contents[0].style.display = 'block';
            setTimeout(() => {
                contents[0].classList.add('active');
            }, 10);
        }
    });
</script>

<!-- ==== Modal "Test Transaksi Simulasi" (satu modal generik dipakai semua gateway) ==== -->
<div class="modal fade" id="testPaymentGatewayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-vial me-1"></i>Test Transaksi -- <span id="testPaymentGatewayName">-</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 small">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Ini transaksi <strong>ASLI</strong> ke gateway sungguhan (kecuali Duitku yang memang selalu sandbox di
                    sistem ini) -- link/QR yang dihasilkan valid dan bisa dibuka/dibayar siapa saja yang pegang link-nya,
                    tapi <strong>TIDAK</strong> akan tercatat di data transaksi/laporan Anda. Pakai nominal sekecil mungkin.
                </div>
                <div id="testPaymentGatewayWarningExtra" class="alert alert-info py-2 small" style="display:none;"></div>

                <div class="mb-3">
                    <label class="form-label" for="testPaymentAmount">Nominal Test (Rp)</label>
                    <input type="number" class="form-control" id="testPaymentAmount" value="1000" min="100" step="1">
                    <div class="form-text">Default Rp 1.000. Sebagian gateway punya nominal minimum sendiri (mis. VA min Rp 10.000) -- kalau gagal krn nominal, coba naikkan.</div>
                </div>

                <div id="testPaymentMethodField" class="mb-3" style="display:none;">
                    <label class="form-label" id="testPaymentMethodLabel">Metode Pembayaran</label>
                    <select class="form-select" id="testPaymentMethodSelect" style="display:none;"></select>
                    <input type="text" class="form-control" id="testPaymentMethodInput" style="display:none;">
                </div>

                <button type="button" class="btn btn-primary" id="testPaymentSendBtn"><i class="fas fa-paper-plane me-1"></i>Kirim Test</button>

                <div id="testPaymentResultBox" class="mt-3" style="display:none;">
                    <div id="testPaymentResultAlert" class="alert mb-2"></div>
                    <div id="testPaymentLinks" class="mb-2"></div>
                    <div id="testPaymentQrWrap" class="mb-2" style="display:none;">
                        <img id="testPaymentQrImg" src="" alt="QR" style="max-width:220px;border:1px solid #ddd;border-radius:8px;">
                    </div>
                    <details>
                        <summary class="small text-muted" style="cursor:pointer;">Lihat raw request/response (debug)</summary>
                        <pre id="testPaymentRawJson" class="p-2 small" style="background:#111827;color:#e5e7eb;border-radius:8px;max-height:280px;overflow:auto;"></pre>
                    </details>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
var testPaymentGatewayModalEl = document.getElementById('testPaymentGatewayModal');
var testPaymentGatewayModal = new bootstrap.Modal(testPaymentGatewayModalEl);
var testPaymentCurrentGateway = '';

var FASPAY_TEST_CHANNELS = [
    { code: '201', name: 'BCA Virtual Account' },
    { code: '801', name: 'Mandiri Virtual Account' },
    { code: '901', name: 'BNI Virtual Account' },
    { code: '705', name: 'BRI Virtual Account' },
    { code: '501', name: 'Permata Virtual Account' },
    { code: '702', name: 'Indomaret' },
    { code: '703', name: 'Alfamart' },
    { code: '813', name: 'QRIS' },
];

function openTestPaymentModal(gateway, label) {
    testPaymentCurrentGateway = gateway;
    document.getElementById('testPaymentGatewayName').textContent = label;
    document.getElementById('testPaymentResultBox').style.display = 'none';
    document.getElementById('testPaymentResultAlert').className = 'alert mb-2';
    document.getElementById('testPaymentResultAlert').textContent = '';
    document.getElementById('testPaymentLinks').innerHTML = '';
    document.getElementById('testPaymentQrWrap').style.display = 'none';
    document.getElementById('testPaymentRawJson').textContent = '';
    document.getElementById('testPaymentAmount').value = 1000;

    var extraWarn = document.getElementById('testPaymentGatewayWarningExtra');
    extraWarn.style.display = 'none';
    extraWarn.textContent = '';
    if (gateway === 'faspay') {
        extraWarn.style.display = 'block';
        extraWarn.textContent = 'Catatan: endpoint Faspay di sistem ini masih placeholder (path gateway_id/channel contoh) -- kegagalan test bisa jadi krn path-nya, bukan krn kredensial salah.';
    } else if (gateway === 'dompetx') {
        extraWarn.style.display = 'block';
        extraWarn.textContent = 'Catatan: nama field nomor VA DompetX belum terverifikasi resmi -- kalau transaksi sukses tapi nomor VA tidak muncul, cek raw response manual.';
    } else if (gateway === 'xendit') {
        extraWarn.style.display = 'block';
        extraWarn.textContent = 'Catatan: Xendit tidak punya field callback per-request -- webhook (kalau sudah diatur) tetap ke URL yang dikonfigurasi di dashboard Xendit Anda.';
    }

    var methodField = document.getElementById('testPaymentMethodField');
    var methodSelect = document.getElementById('testPaymentMethodSelect');
    var methodInput = document.getElementById('testPaymentMethodInput');
    var methodLabel = document.getElementById('testPaymentMethodLabel');
    methodSelect.style.display = 'none';
    methodInput.style.display = 'none';
    methodSelect.innerHTML = '';
    methodInput.value = '';

    if (gateway === 'tripay') {
        methodField.style.display = 'block';
        methodLabel.textContent = 'Channel Pembayaran (wajib pilih)';
        methodSelect.style.display = 'block';
        methodSelect.innerHTML = '<option value="">Memuat daftar channel...</option>';
        var fd = new FormData();
        fd.append('gateway', 'tripay');
        fd.append('action', 'channels');
        fetch('proses/test_payment_gateway.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.channels) && data.channels.length > 0) {
                    methodSelect.innerHTML = '<option value="">Pilih channel...</option>' +
                        data.channels.map(function (c) { return '<option value="' + c.code + '">' + c.name + ' (' + c.code + ')</option>'; }).join('');
                } else {
                    methodSelect.innerHTML = '<option value="">Gagal memuat channel -- ' + (data.message || 'cek API key Tripay') + '</option>';
                }
            })
            .catch(function () { methodSelect.innerHTML = '<option value="">Gagal memuat channel (koneksi error)</option>'; });
    } else if (gateway === 'faspay') {
        methodField.style.display = 'block';
        methodLabel.textContent = 'Channel Pembayaran';
        methodSelect.style.display = 'block';
        methodSelect.innerHTML = FASPAY_TEST_CHANNELS.map(function (c) { return '<option value="' + c.code + '">' + c.name + ' (' + c.code + ')</option>'; }).join('');
    } else if (gateway === 'duitku') {
        methodField.style.display = 'block';
        methodLabel.textContent = 'Kode Metode Pembayaran Duitku (default VC)';
        methodInput.style.display = 'block';
        methodInput.value = 'VC';
    } else if (gateway === 'dompetx') {
        methodField.style.display = 'block';
        methodLabel.textContent = 'Metode DompetX (default QRIS)';
        methodInput.style.display = 'block';
        methodInput.value = 'QRIS';
    } else {
        methodField.style.display = 'none';
    }

    testPaymentGatewayModal.show();
}

document.getElementById('testPaymentSendBtn').addEventListener('click', function () {
    var btn = this;
    var gateway = testPaymentCurrentGateway;
    if (!gateway) return;

    var methodSelect = document.getElementById('testPaymentMethodSelect');
    var methodInput = document.getElementById('testPaymentMethodInput');
    var testMethod = '';
    if (methodSelect.style.display !== 'none') {
        testMethod = methodSelect.value;
    } else if (methodInput.style.display !== 'none') {
        testMethod = methodInput.value.trim();
    }
    if (gateway === 'tripay' && !testMethod) {
        alert('Pilih channel pembayaran Tripay dulu.');
        return;
    }

    var testAmount = parseInt(document.getElementById('testPaymentAmount').value, 10);
    if (!testAmount || testAmount < 100) {
        alert('Nominal test minimal Rp 100.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mengirim...';

    var fd = new FormData();
    fd.append('gateway', gateway);
    fd.append('action', 'test');
    fd.append('test_method', testMethod);
    fd.append('test_amount', testAmount);

    fetch('proses/test_payment_gateway.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var resultBox = document.getElementById('testPaymentResultBox');
            var resultAlert = document.getElementById('testPaymentResultAlert');
            var linksBox = document.getElementById('testPaymentLinks');
            var qrWrap = document.getElementById('testPaymentQrWrap');
            var qrImg = document.getElementById('testPaymentQrImg');
            var rawJson = document.getElementById('testPaymentRawJson');

            resultBox.style.display = 'block';
            resultAlert.className = 'alert mb-2 ' + (data.success ? 'alert-success' : 'alert-danger');
            resultAlert.textContent = data.message || (data.success ? 'Berhasil.' : 'Gagal.');

            linksBox.innerHTML = '';
            var link = data.checkout_url || data.pay_url || '';
            if (link) {
                var a = document.createElement('a');
                a.href = link;
                a.target = '_blank';
                a.rel = 'noopener';
                a.className = 'btn btn-outline-primary btn-sm me-2';
                a.innerHTML = '<i class="fas fa-arrow-up-right-from-square me-1"></i>Buka Link Pembayaran';
                linksBox.appendChild(a);
            }
            if (data.va_number) {
                var span = document.createElement('span');
                span.className = 'badge bg-secondary';
                span.textContent = 'VA: ' + data.va_number;
                linksBox.appendChild(span);
            }

            if (data.qr_url) {
                qrImg.src = data.qr_url;
                qrWrap.style.display = 'block';
            } else {
                qrWrap.style.display = 'none';
            }

            if (data.warning) {
                var warnDiv = document.createElement('div');
                warnDiv.className = 'small text-muted mt-1';
                warnDiv.textContent = data.warning;
                linksBox.appendChild(warnDiv);
            }

            rawJson.textContent = JSON.stringify({ request: data.raw_request, response: data.raw_response }, null, 2);
        })
        .catch(function () {
            var resultBox = document.getElementById('testPaymentResultBox');
            var resultAlert = document.getElementById('testPaymentResultAlert');
            resultBox.style.display = 'block';
            resultAlert.className = 'alert mb-2 alert-danger';
            resultAlert.textContent = 'Terjadi kesalahan koneksi.';
        })
        .finally(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Kirim Test';
        });
});
</script>
















<?php require 'footer.php'; ?>
