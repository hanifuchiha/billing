<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/api_hanif_periode_helper.php';

$apiUrl = "https://billing.broadbandairlink.com/web/keuangan/sistem-manajemen-keuangan/index.php/admin/site/pelanggan_api";

$apiKey = "9fa120bc7d157225c58238c91051c7f2baf37c5338d75f8c9c260082babe2de8";

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        "X-API-Key: {$apiKey}",
        "Accept: application/json"
    ]
]);

$response = curl_exec($ch);

$error = curl_error($ch);

$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

if ($error) {
    die("CURL ERROR : ".$error);
}

if ($httpcode != 200) {
    die("HTTP CODE : ".$httpcode."<br><pre>".$response."</pre>");
}

$data = json_decode($response, true);

if (!$data) {
    die("JSON ERROR<br><pre>".$response."</pre>");
}

$customers = [];

if (isset($data['customers']) && is_array($data['customers'])) {
    $customers = $data['customers'];
}
elseif (isset($data['rows']) && is_array($data['rows'])) {
    $customers = $data['rows'];
}

$areas = [];
$pakets = [];
$rts = [];
$rws = [];
$kelurahans = [];
$kecamatans = [];

foreach ($customers as $c) {

    if (!empty($c['AREA'])) {
        $areas[$c['AREA']] = $c['AREA'];
    }

    if (!empty($c['PAKET'])) {
        $pakets[$c['PAKET']] = $c['PAKET'];
    }

    if (!empty($c['rt'])) {
        $rts[$c['rt']] = $c['rt'];
    }

    if (!empty($c['rw'])) {
        $rws[$c['rw']] = $c['rw'];
    }

    if (!empty($c['kelurahan'])) {
        $kelurahans[$c['kelurahan']] = $c['kelurahan'];
    }

    if (!empty($c['kecamatan'])) {
        $kecamatans[$c['kecamatan']] = $c['kecamatan'];
    }
}

ksort($areas);
ksort($pakets);
ksort($rts);
ksort($rws);
ksort($kelurahans);
ksort($kecamatans);

/**
 * Mengubah nilai apapun (string aneh, null, kosong, dll)
 * menjadi float yang aman untuk number_format().
 * Mencegah warning "number_format() expects parameter 1 to be float".
 */
function safeNumber($val): float {
    if (is_numeric($val)) {
        return (float) $val;
    }
    // Coba ambil angka dari string seperti "165k", "6ratis", "Rp 165.000"
    if (is_string($val)) {
        $clean = preg_replace('/[^0-9.,-]/', '', $val);
        $clean = str_replace(['.', ','], ['', '.'], $clean);
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }
    return 0.0;
}

/**
 * Wrapper aman untuk number_format() agar tidak pernah error/warning
 * apapun input yang diberikan.
 */
function safeNumberFormat($val, $decimals = 0, $decPoint = ',', $thousandsSep = '.'): string {
    return number_format(safeNumber($val), $decimals, $decPoint, $thousandsSep);
}

// ============================================================================
// PERIODE / PENGGUNAAN
// ============================================================================
// API mengirim payment_history[] (punya transaksi_id + periode) dan
// transactions[] (data transaksi finansial, juga punya transaksi_id).
// Untuk ditampilkan di modal "Lihat Transaksi", setiap baris transaksi
// dicocokkan ke payment_history berdasarkan transaksi_id untuk mengambil
// periode-nya lewat resolvePeriodeFinal() (lihat periode_helper.php):
//
//   1. payment_history.periode TIDAK null -> dipakai apa adanya (sumber
//      utama, dihitung oleh sistem billing eksternal itu sendiri).
//   2. payment_history.periode NULL / tidak ketemu -> fallback dihitung
//      dari transaksi_tanggal + pengaturan tutup buku (jatuh_tempo,
//      tanggal_awal/akhir_tutup_buku) milik akun (username) PEMILIK
//      pelanggan ybs, dibaca dari notifbot/data/reminder-{username}.json.
//      Kalau file tidak ditemukan / pengaturan tidak lengkap, dipakai
//      fallback paling sederhana: nama bulan dari tanggal transaksi
//      (mengikuti TIPE_BAYAR: prabayar -> bulan depan, pascabayar -> bulan
//      berjalan).
//
// Fungsi-fungsi inti (getMonthNameIndo, getUsernameByPemilik,
// loadReminderSettings, resolvePeriodeFromTanggal,
// buildPeriodeMapFromPaymentHistory, resolvePeriodeFinal) ada di
// periode_helper.php supaya logikanya SAMA PERSIS dengan yang dipakai di
// sync_pelanggan_api.php saat insert ke tabel `transaksi` -- ini yang
// memperbaiki mismatch antara kolom "Periode/Penggunaan" yang tampil di
// modal ini dengan kolom PENGUNAAN yang benar-benar tersimpan di database.
// ============================================================================

// Cache pengaturan reminder per PEMILIK, supaya tidak baca file JSON
// berkali-kali untuk tiap baris pelanggan yang PEMILIK-nya sama.
$reminderSettingsCache = [];

/**
 * Resolve periode untuk satu item transaksi milik SATU baris pelanggan
 * ($customerRow), memakai cache pengaturan reminder per PEMILIK supaya
 * tidak baca file JSON berulang-ulang untuk ratusan pelanggan.
 */
function resolvePeriodeUntukBaris(array $t, array $periodeMap, array $customerRow, array &$reminderSettingsCache): string
{
    global $conn;

    $pemilik   = trim((string)($customerRow['PEMILIK'] ?? ''));
    $tipeTempo = trim((string)($customerRow['TIPE_TEMPO'] ?? ''));
    $tipeBayar = trim((string)($customerRow['TIPE_BAYAR'] ?? ''));

    if (!array_key_exists($pemilik, $reminderSettingsCache)) {
        $username = $pemilik !== '' ? getUsernameByPemilik($conn, $pemilik) : null;
        $reminderSettingsCache[$pemilik] = $username !== null ? loadReminderSettings($username) : null;
    }
    $settings = $reminderSettingsCache[$pemilik];

    return resolvePeriodeFinal($t, $periodeMap, $settings, $tipeTempo, $tipeBayar);
}

// ============================================================================
// Data pendukung untuk modal "Sinkron dari API" (dropdown Server, dihitung
// sekali di sini supaya tidak query berulang di tengah HTML).
//
// Catatan penyesuaian:
// - "Area (otomatis dari Server)" diisi otomatis dari data-area milik
//   option Server terpilih (sama seperti behaviour filter Area di tables.php),
//   bukan dari AREA API langsung -- supaya Area yang dipakai untuk sinkron
//   selalu konsisten dengan Area yang sudah didaftarkan di tabel `server`.
// - Tanggal Mulai Tagihan TIDAK ditampilkan lagi sebagai input manual,
//   karena diambil otomatis per-pelanggan dari kolom TANGGALPASANG di API.
// - Sales juga diambil otomatis per-pelanggan dari kolom `sales` di API;
//   dropdown Sales di Step 1 sekarang hanya berfungsi sebagai FALLBACK jika
//   API tidak mengirim sales untuk pelanggan tertentu.
// - Panel "Pengaturan Tutup Buku & Reminder" ditampilkan otomatis begitu
//   Server dipilih, membaca notifbot/data/reminder-{username}.json milik
//   akun PEMILIK server tsb (lihat get_reminder_settings.php), supaya admin
//   tahu aturan apa yang akan dipakai untuk menghitung kolom PENGUNAAN
//   sebelum menjalankan sinkron.
// ============================================================================
$syncServerOptions = [];
if (isset($current_user_id) && $current_user_id) {
    if (isset($AKSES) && $AKSES === 'ASSISTANT') {
        $querySyncServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
    } else {
        $querySyncServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = " . (int)$current_user_id);
    }
    if ($querySyncServer) {
        while ($rowSyncServer = mysqli_fetch_assoc($querySyncServer)) {
            $syncServerOptions[] = [
                'pemilik' => $rowSyncServer['PEMILIK'] ?? '',
                'brand'   => $rowSyncServer['BRAND'] ?? '',
                'area'    => $rowSyncServer['AREA'] ?? '',
            ];
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="utf-8">

<title>Data Pelanggan API</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

<link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

<style>

body{
    background:#f4f6f9;
}

.card{
    border:none;
}

.dataTables_filter{
    display:none;
}

.sync-secret-badge{
    font-size:10px;
    padding:3px 6px;
}

.sync-password-input{
    min-width:140px;
    font-size:12px;
}

.reminder-settings-box{
    font-size:13px;
}

.reminder-settings-box table{
    margin-bottom:0;
}

</style>

</head>

<body>

<div class="container-fluid mt-3">

<div class="card shadow">

<div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h5 class="mb-0">Data Pelanggan API</h5>
    <?php if (isset($AKSES) && $AKSES === 'ADMIN'): ?>
    <button type="button" class="btn btn-success btn-sm" id="btnOpenSyncApiModal">
        ?? Sinkron dari API
    </button>
    <?php endif; ?>
</div>

<div class="card-body">

<?php if(isset($data['ok']) && !$data['ok']){ ?>

<div class="alert alert-danger">
    <?= htmlspecialchars($data['message'] ?? '') ?>
</div>

<?php } ?>

<div class="row mb-3">

<div class="col-md-4">
<label>Cari Pelanggan</label>
<input type="text" id="filterSearch" class="form-control" placeholder="Nama / IDPEL / WA">
</div>

<div class="col-md-3">
<label>Filter Area</label>
<select id="filterArea" class="form-select">
<option value="">Semua Area</option>

<?php foreach($areas as $area){ ?>
<option value="<?= htmlspecialchars($area) ?>">
<?= htmlspecialchars($area) ?>
</option>
<?php } ?>

</select>
</div>

<div class="col-md-3">
<label>Filter Paket</label>
<select id="filterPaket" class="form-select">
<option value="">Semua Paket</option>

<?php foreach($pakets as $paket){ ?>
<option value="<?= htmlspecialchars($paket) ?>">
<?= htmlspecialchars($paket) ?>
</option>
<?php } ?>

</select>
</div>

<div class="col-md-2">
<label>Total</label>
<input type="text"
class="form-control bg-success text-white fw-bold"
value="<?= count($customers) ?> Pelanggan"
readonly>
</div>

</div>

<div class="row mb-3">

<div class="col-md-3">
<label>Filter RT</label>
<select id="filterRT" class="form-select">
<option value="">Semua RT</option>

<?php foreach($rts as $rt){ ?>
<option value="<?= htmlspecialchars($rt) ?>">
<?= htmlspecialchars($rt) ?>
</option>
<?php } ?>

</select>
</div>

<div class="col-md-3">
<label>Filter RW</label>
<select id="filterRW" class="form-select">
<option value="">Semua RW</option>

<?php foreach($rws as $rw){ ?>
<option value="<?= htmlspecialchars($rw) ?>">
<?= htmlspecialchars($rw) ?>
</option>
<?php } ?>

</select>
</div>

<div class="col-md-3">
<label>Filter Kelurahan</label>
<select id="filterKelurahan" class="form-select">
<option value="">Semua Kelurahan</option>

<?php foreach($kelurahans as $kelurahan){ ?>
<option value="<?= htmlspecialchars($kelurahan) ?>">
<?= htmlspecialchars($kelurahan) ?>
</option>
<?php } ?>

</select>
</div>

<div class="col-md-3">
<label>Filter Kecamatan</label>
<select id="filterKecamatan" class="form-select">
<option value="">Semua Kecamatan</option>

<?php foreach($kecamatans as $kecamatan){ ?>
<option value="<?= htmlspecialchars($kecamatan) ?>">
<?= htmlspecialchars($kecamatan) ?>
</option>
<?php } ?>

</select>
</div>

</div>

<div class="table-responsive">

<table id="tablePelanggan" class="table table-bordered table-striped table-hover">

<thead class="table-dark">
<tr>
<th>IDPEL</th>
<th>Nama</th>
<th>WA</th>
<th>Paket</th>
<th>Harga</th>
<th>Area</th>
<th>RT</th>
<th>RW</th>
<th>Kelurahan</th>
<th>Kecamatan</th>
<th>Alamat</th>
<th>Tempo</th>
<th>Total Bayar</th>
<th>Bayar Terakhir</th>
<th>Aksi</th>
</tr>
</thead>

<tbody>

<?php foreach($customers as $row){ ?>

<?php
    $transaksiListRaw = $row['transactions'] ?? [];
    if (!is_array($transaksiListRaw)) {
        $transaksiListRaw = [];
    }

    $paymentHistoryRaw = $row['payment_history'] ?? [];
    if (!is_array($paymentHistoryRaw)) {
        $paymentHistoryRaw = [];
    }
    $periodeMapRow = buildPeriodeMapFromPaymentHistory($paymentHistoryRaw);

    // Sisipkan field "periode" (hasil resolve) ke tiap baris transaksi
    // sebelum dikirim ke data-attribute, supaya JS tinggal menampilkan.
    // Memakai resolvePeriodeUntukBaris() supaya fallback (bila
    // payment_history.periode kosong) dihitung dari pengaturan tutup buku
    // milik PEMILIK pelanggan ini (reminder-{username}.json), BUKAN
    // sekadar nama bulan dari tanggalnya saja.
    $transaksiList = array_map(function ($t) use ($periodeMapRow, $row, &$reminderSettingsCache) {
        $t['periode'] = resolvePeriodeUntukBaris($t, $periodeMapRow, $row, $reminderSettingsCache);
        return $t;
    }, $transaksiListRaw);

    $transaksiJson = htmlspecialchars(json_encode($transaksiList), ENT_QUOTES, 'UTF-8');
    $namaPelangganAttr = htmlspecialchars($row['NAMA'] ?? '', ENT_QUOTES, 'UTF-8');
    $idpelAttr = htmlspecialchars($row['IDPEL'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<tr>

<td><?= htmlspecialchars($row['IDPEL'] ?? '') ?></td>

<td><?= htmlspecialchars($row['NAMA'] ?? '') ?></td>

<td><?= htmlspecialchars($row['NOWA'] ?? '') ?></td>

<td><?= htmlspecialchars($row['PAKET'] ?? '') ?></td>

<td data-order="<?= intval(safeNumber($row['HARGA'] ?? 0)) ?>">
Rp <?= safeNumberFormat($row['HARGA'] ?? 0) ?>
</td>

<td><?= htmlspecialchars($row['AREA'] ?? '') ?></td>

<td><?= htmlspecialchars($row['rt'] ?? '') ?></td>

<td><?= htmlspecialchars($row['rw'] ?? '') ?></td>

<td><?= htmlspecialchars($row['kelurahan'] ?? '') ?></td>

<td><?= htmlspecialchars($row['kecamatan'] ?? '') ?></td>

<td>
<?= htmlspecialchars(
($row['ALAMAT'] ?? '') .
' No ' .
($row['no_rumah'] ?? '')
) ?>
</td>

<td><?= htmlspecialchars($row['TEMPO'] ?? '') ?></td>

<td>
Rp <?= safeNumberFormat($row['payment_summary']['total_paid_amount'] ?? 0) ?>
</td>

<td>
<?= htmlspecialchars($row['payment_summary']['last_payment_date'] ?? '-') ?>
</td>

<td class="text-center">
<button type="button"
    class="btn btn-sm btn-outline-primary btn-lihat-transaksi"
    data-nama="<?= $namaPelangganAttr ?>"
    data-idpel="<?= $idpelAttr ?>"
    data-transaksi="<?= $transaksiJson ?>">
    <i class="bi"></i> Lihat Transaksi
</button>
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>

</div>

<!-- Modal Transaksi -->
<div class="modal fade" id="modalTransaksi" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          Riwayat Transaksi - <span id="modalNamaPelanggan"></span>
          <small class="d-block fw-normal" id="modalIdpelPelanggan"></small>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-sm" id="tableTransaksiModal">
            <thead class="table-dark">
              <tr>
                <th>Tanggal</th>
                <th>Jenis</th>
                <th>Kategori</th>
                <th>Nominal</th>
                <th>Bank</th>
                <th>Periode / Penggunaan</th>
                <th>Keterangan</th>
              </tr>
            </thead>
            <tbody id="bodyTransaksiModal">
            </tbody>
          </table>
        </div>
        <div id="emptyTransaksiModal" class="text-center text-muted py-4 d-none">
          Belum ada transaksi untuk pelanggan ini.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<?php if (isset($AKSES) && $AKSES === 'ADMIN'): ?>
<!-- ============================================================================
     MODAL: Sinkron Data Pelanggan dari API
     ============================================================================ -->
<div class="modal fade" id="modalSyncApi" tabindex="-1" aria-labelledby="modalSyncApiLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header bg-success text-white">
        <h5 class="modal-title text-white" id="modalSyncApiLabel">?? Sinkron Data Pelanggan dari API</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">

        <!-- ====== STEP 1: Form default untuk pelanggan baru ====== -->
        <div id="syncStep1">
            <div class="alert alert-info small mb-3">
                Pilih <b>Server / Area / ODP / Tipe Bayar / Tipe Tempo</b> yang akan digunakan
                sebagai nilai default untuk SEMUA pelanggan baru yang ditemukan dari API.
                <br>
                <b>Tanggal mulai tagihan</b> dan <b>Sales</b> akan diambil otomatis dari data API
                masing-masing pelanggan (kolom <code>TANGGALPASANG</code> &amp; <code>sales</code>).
                Pilihan Sales di bawah ini hanya dipakai sebagai cadangan jika API tidak
                mengirimkan data sales untuk pelanggan tertentu.
                <br>
                Preview hanya akan menampilkan pelanggan yang sesuai dengan filter
                <b>Area / Paket / RT / RW / Kelurahan / Kecamatan / Cari</b> yang sedang
                aktif di tabel utama di belakang modal ini. Atur filter tabel utama
                terlebih dahulu sebelum klik <b>Cek Data Baru</b> jika ingin sinkron
                sebagian data saja.
                <br>
                Kolom <b>PENGUNAAN</b> tiap transaksi akan diisi dari periode resmi API
                (<code>payment_history.periode</code>) jika tersedia; jika tidak, dihitung
                otomatis dari tanggal transaksi memakai pengaturan tutup buku pada
                <b>Pengaturan Tutup Buku &amp; Reminder</b> di bawah (muncul setelah Server
                dipilih).
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Server Area <span class="text-danger">*</span></label>
                    <select required class="form-select" id="syncServer">
                        <option value="">-- Pilih Server Area --</option>
                        <?php foreach ($syncServerOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['pemilik'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-area="<?= htmlspecialchars($opt['area'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-brand="<?= htmlspecialchars($opt['brand'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($opt['brand'], ENT_QUOTES, 'UTF-8') ?>-<?= htmlspecialchars($opt['area'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Area (otomatis dari Server)</label>
                    <input type="text" class="form-control" id="syncAreaDisplay" readonly>
                    <input type="hidden" id="syncArea">
                    <input type="hidden" id="syncBrand">
                </div>

                <div class="col-md-6">
                    <label class="form-label">ODP <span class="text-danger">*</span></label>
                    <select required class="form-select" id="syncOdp">
                        <option value="">-- Pilih Server dahulu --</option>
                    </select>
                    <small class="text-muted">ODP ini dipakai sebagai default untuk semua pelanggan baru hasil sinkron.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sales (fallback jika tidak ada di API)</label>
                    <select class="form-select" id="syncSales">
                        <option value="-">TANPA SALES</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Tipe Bayar (fallback jika tidak ada di API)</label>
                    <select class="form-select" id="syncTipeBayar">
                        <option value="pascabayar" selected>Pasca Bayar</option>
                        <option value="prabayar">Prabayar</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tipe Tempo (fallback jika tidak ada di API)</label>
                    <select class="form-select" id="syncTipeTempo">
                        <option value="mengikuti_tanggal_tempo">Fixed Due Date</option>
                        <option value="mengikuti_tanggal_bayar" selected>Rolling Due Date</option>
                        <option value="monthversary">Monthversary Due Date</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label mb-1">Pengaturan Tutup Buku &amp; Reminder</label>
                    <div id="syncReminderSettingsBox" class="alert alert-secondary reminder-settings-box py-2 mb-0">
                        Pilih Server terlebih dahulu untuk melihat pengaturan tutup buku &amp; reminder
                        (dari <code>reminder-&lt;username&gt;.json</code>) milik akun pemilik server tsb.
                    </div>
                </div>
            </div>

            <div class="text-end mt-3">
                <button type="button" class="btn btn-primary" id="btnSyncPreview">
                    <span class="btn-label">?? Cek Data Baru dari API</span>
                    <span class="btn-loading d-none">
                        <span class="spinner-border spinner-border-sm me-2"></span>Mengecek...
                    </span>
                </button>
            </div>
        </div>

        <!-- ====== STEP 2: Hasil preview + konfirmasi ====== -->
        <div id="syncStep2" class="d-none">
            <div id="syncSummaryBox" class="alert alert-secondary py-2 mb-3"></div>
            <div id="syncRouterWarningBox" class="alert alert-warning py-2 mb-3 d-none"></div>

            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <div>
                    <input type="checkbox" id="syncSelectAll" checked>
                    <label for="syncSelectAll" class="ms-1">Pilih Semua</label>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <select id="syncBulkAuthMode" class="form-select form-select-sm" style="width:auto;">
                        <option value="API MODE">API MODE (MikroTik)</option>
                        <option value="RADIUS MODE">RADIUS MODE</option>
                        <option value="MULTI MODE" selected>MULTI MODE (Keduanya)</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnApplyBulkAuthMode">Terapkan Mode ke Semua</button>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <input type="text" id="syncBulkPassword" class="form-control form-control-sm sync-password-input" placeholder="Password sama utk semua...">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnApplyBulkPassword">Terapkan ke Semua</button>
                </div>

                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBackToStep1">? Ubah Pengaturan</button>
            </div>

            <div class="small text-muted mb-2">
                Setiap pelanggan baru akan dibuatkan akun PPPoE sesuai <b>Mode Auth</b> yang
                dipilih per baris (default <b>MULTI MODE</b> = MikroTik DAN RADIUS), menggunakan
                password yang sama. Ganti Mode Auth satu per satu di kolom <b>Mode Auth</b>, atau
                pilih mode di pojok kanan atas lalu klik <b>Terapkan Mode ke Semua</b> untuk
                menerapkannya secara massal. Kolom <b>Password PPPoE</b> <b class="text-danger">WAJIB
                diisi untuk SEMUA baris yang dipilih</b>, apapun status MikroTik/RADIUS-nya --
                password ini ikut disimpan ke data pelanggan, jadi tidak boleh ada satupun yang
                kosong. Isi satu per satu, atau pakai "Password sama utk semua..." +
                <b>Terapkan ke Semua</b> untuk mengisi sekaligus. Sinkronisasi akan
                <b>ditolak</b> jika masih ada baris terpilih dengan password kosong. Kolom
                <b>Periode (Preview)</b> menunjukkan hasil perhitungan PENGUNAAN yang
                akan disimpan ke tabel transaksi untuk tiap pelanggan.
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle" id="syncPreviewTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:36px;"></th>
                            <th>IDPEL</th>
                            <th>Nama</th>
                            <th>WA</th>
                            <th>Paket</th>
                            <th>Area (API)</th>
                            <th>RT</th>
                            <th>RW</th>
                            <th>Kelurahan</th>
                            <th>Kecamatan</th>
                            <th>Alamat</th>
                            <th>Tgl Pasang</th>
                            <th>Sales</th>
                            <th>ODP</th>
                            <th>Periode (Preview)</th>
                            <th>Status MikroTik</th>
                            <th>Status RADIUS</th>
                            <th>Mode Auth</th>
                            <th>Password PPPoE</th>
                            <th>Jml Transaksi Masuk</th>
                            <th>Total Nominal</th>
                        </tr>
                    </thead>
                    <tbody id="syncPreviewBody">
                        <tr><td colspan="21" class="text-center text-muted">Belum ada data.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ====== STEP 3: Hasil eksekusi ====== -->
        <div id="syncResultBox" class="d-none mt-3"></div>

      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" class="btn btn-success d-none" id="btnSyncExecute">
            <span class="btn-label">? Simpan Pelanggan Terpilih ke Database</span>
            <span class="btn-loading d-none">
                <span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...
            </span>
        </button>
      </div>

    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>

var table = $('#tablePelanggan').DataTable({
    pageLength:25,
    order:[[1,'asc']],
    dom:'Bfrtip',
    columnDefs:[
        { orderable:false, targets:-1 }
    ],
    buttons:[
        'excel',
        'pdf',
        'print'
    ]
});

$('#filterSearch').keyup(function(){
    table.search($(this).val()).draw();
});

$('#filterArea').change(function(){
    table.column(5).search($(this).val()).draw();
});

$('#filterPaket').change(function(){
    table.column(3).search($(this).val()).draw();
});

$('#filterRT').change(function(){
    table.column(6).search($(this).val()).draw();
});

$('#filterRW').change(function(){
    table.column(7).search($(this).val()).draw();
});

$('#filterKelurahan').change(function(){
    table.column(8).search($(this).val()).draw();
});

$('#filterKecamatan').change(function(){
    table.column(9).search($(this).val()).draw();
});

// Format angka jadi Rupiah di sisi JS (untuk data transaksi yang sudah numerik dari API)
function formatRupiahJS(val){
    var n = parseFloat(val);
    if (isNaN(n)) { n = 0; }
    return 'Rp ' + n.toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:0});
}

// Buka modal transaksi saat tombol diklik
$(document).on('click', '.btn-lihat-transaksi', function(){

    var nama = $(this).data('nama') || '-';
    var idpel = $(this).data('idpel') || '-';
    var transaksiRaw = $(this).attr('data-transaksi');

    var transaksiList = [];
    try {
        transaksiList = JSON.parse(transaksiRaw);
        if (!Array.isArray(transaksiList)) {
            transaksiList = [];
        }
    } catch(e) {
        transaksiList = [];
    }

    $('#modalNamaPelanggan').text(nama);
    $('#modalIdpelPelanggan').text('IDPEL: ' + idpel);

    var $tbody = $('#bodyTransaksiModal');
    $tbody.empty();

    if (transaksiList.length === 0) {
        $('#tableTransaksiModal').addClass('d-none');
        $('#emptyTransaksiModal').removeClass('d-none');
    } else {
        $('#tableTransaksiModal').removeClass('d-none');
        $('#emptyTransaksiModal').addClass('d-none');

        transaksiList.forEach(function(t){

            var jenis = t.transaksi_jenis || '-';
            var jenisBadge = jenis === 'Pemasukan'
                ? '<span class="badge bg-success">'+jenis+'</span>'
                : (jenis === 'Pengeluaran'
                    ? '<span class="badge bg-danger">'+jenis+'</span>'
                    : '<span class="badge bg-secondary">'+jenis+'</span>');

            // Kolom periode/penggunaan: sudah dihitung server-side (PHP)
            // lewat resolvePeriodeFinal() di periode_helper.php, dari
            // payment_history.periode (kalau ada) atau fallback tutup-buku
            // berbasis tanggal (kalau tidak ada). Lihat index.php /
            // resolvePeriodeUntukBaris().
            var periode = t.periode || '-';

            var row = '<tr>' +
                '<td>' + (t.transaksi_tanggal || '-') + '</td>' +
                '<td>' + jenisBadge + '</td>' +
                '<td>' + (t.kategori_nama || '-') + '</td>' +
                '<td>' + formatRupiahJS(t.transaksi_nominal) + '</td>' +
                '<td>' + (t.bank_nama || '-') + '</td>' +
                '<td>' + periode + '</td>' +
                '<td>' + (t.transaksi_keterangan || '-') + '</td>' +
                '</tr>';

            $tbody.append(row);
        });
    }

    var modal = new bootstrap.Modal(document.getElementById('modalTransaksi'));
    modal.show();
});

</script>

<?php if (isset($AKSES) && $AKSES === 'ADMIN'): ?>
<!-- ============================================================================
     SCRIPT: Sinkron Data Pelanggan dari API
     ============================================================================ -->
<script>
(function() {
    let syncPreviewData = []; // hasil preview dari server, disimpan untuk dipakai saat execute
    let syncPasswordMap = {}; // {IDPEL: password} - diisi manual / massal di Step 2
    let syncAuthModeMap = {}; // {IDPEL: 'API MODE'|'RADIUS MODE'|'MULTI MODE'} - diisi manual / massal di Step 2, default MULTI MODE
    let syncActiveFilters = { area: '', paket: '', search: '', rt: '', rw: '', kelurahan: '', kecamatan: '' }; // filter tabel utama yang dipakai saat preview, dikirim ulang saat execute

    const modalEl = document.getElementById('modalSyncApi');
    if (!modalEl) return; // safety: jika modal tidak ada (misal AKSES bukan ADMIN), hentikan.

    const modalInstance = new bootstrap.Modal(modalEl);

    const step1 = document.getElementById('syncStep1');
    const step2 = document.getElementById('syncStep2');
    const resultBox = document.getElementById('syncResultBox');
    const btnExecute = document.getElementById('btnSyncExecute');
    const routerWarningBox = document.getElementById('syncRouterWarningBox');
    const reminderSettingsBox = document.getElementById('syncReminderSettingsBox');

    document.getElementById('btnOpenSyncApiModal').addEventListener('click', function() {
        resetSyncModal();
        modalInstance.show();
    });

    function resetSyncModal() {
        step1.classList.remove('d-none');
        step2.classList.add('d-none');
        resultBox.classList.add('d-none');
        resultBox.innerHTML = '';
        btnExecute.classList.add('d-none');
        routerWarningBox.classList.add('d-none');
        routerWarningBox.innerHTML = '';
        syncPreviewData = [];
        syncPasswordMap = {};
        syncAuthModeMap = {};
        syncActiveFilters = { area: '', paket: '', search: '', rt: '', rw: '', kelurahan: '', kecamatan: '' };
        document.getElementById('syncBulkPassword').value = '';
        document.getElementById('syncBulkAuthMode').value = 'MULTI MODE';
        reminderSettingsBox.className = 'alert alert-secondary reminder-settings-box py-2 mb-0';
        reminderSettingsBox.innerHTML = 'Pilih Server terlebih dahulu untuk melihat pengaturan tutup buku &amp; reminder ' +
            '(dari <code>reminder-&lt;username&gt;.json</code>) milik akun pemilik server tsb.';
    }

    // ------------------------------------------------------------------
    // Dropdown Server -> set Area/Brand, lalu load ODP, Sales, dan
    // Pengaturan Tutup Buku & Reminder (reminder-{username}.json).
    // ------------------------------------------------------------------
    document.getElementById('syncServer').addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const area = opt ? (opt.getAttribute('data-area') || '') : '';
        const brand = opt ? (opt.getAttribute('data-brand') || '') : '';

        // Area diisi otomatis dari data-area milik Server terpilih (sesuai
        // pasangan PEMILIK+AREA yang sudah terdaftar di tabel `server`).
        document.getElementById('syncArea').value = area;
        document.getElementById('syncAreaDisplay').value = area;
        document.getElementById('syncBrand').value = brand;

        loadSyncOdp(this.value, area);
        loadSyncSales(this.value);
        loadReminderSettingsBox(this.value);
    });

    function loadSyncOdp(server, area) {
        const odpSelect = document.getElementById('syncOdp');
        odpSelect.innerHTML = '<option value="">Loading...</option>';
        if (!server || !area) {
            odpSelect.innerHTML = '<option value="">-- Pilih Server dahulu --</option>';
            return;
        }
        fetch('get_odp.php?server=' + encodeURIComponent(server) + '&area=' + encodeURIComponent(area))
            .then(r => r.text())
            .then(html => { odpSelect.innerHTML = html; })
            .catch(() => { odpSelect.innerHTML = '<option value="">Gagal memuat ODP</option>'; });
    }

    function loadSyncSales(server) {
        const salesSelect = document.getElementById('syncSales');
        salesSelect.innerHTML = '<option value="-">TANPA SALES</option>';
        if (!server) return;
        // Endpoint ini mengikuti pola query mitra di addcustomerform.php (WHERE server=...)
        fetch('get_sales.php?server=' + encodeURIComponent(server))
            .then(r => r.text())
            .then(html => {
                if (html && html.trim() !== '') {
                    salesSelect.innerHTML = '<option value="-">TANPA SALES</option>' + html;
                }
            })
            .catch(() => { /* biarkan default TANPA SALES jika endpoint gagal */ });
    }

    // Ambil & tampilkan isi reminder-{username}.json milik akun PEMILIK
    // server terpilih (jatuh_tempo, tanggal_awal/akhir_tutup_buku,
    // hari_sebelum, tanggal_reminder, botname), supaya admin tahu aturan
    // yang akan dipakai untuk menghitung PENGUNAAN sebelum sinkron.
    function loadReminderSettingsBox(server) {
        if (!server) {
            reminderSettingsBox.className = 'alert alert-secondary reminder-settings-box py-2 mb-0';
            reminderSettingsBox.innerHTML = 'Pilih Server terlebih dahulu untuk melihat pengaturan tutup buku &amp; reminder.';
            return;
        }

        reminderSettingsBox.className = 'alert alert-secondary reminder-settings-box py-2 mb-0';
        reminderSettingsBox.innerHTML = 'Memuat pengaturan reminder...';

        fetch('api_hanif_get_reminder_settings.php?server=' + encodeURIComponent(server))
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    reminderSettingsBox.className = 'alert alert-danger reminder-settings-box py-2 mb-0';
                    reminderSettingsBox.innerHTML = escSyncHtml(res.message || 'Gagal memuat pengaturan reminder.');
                    return;
                }

                if (!res.found || !res.settings) {
                    reminderSettingsBox.className = 'alert alert-warning reminder-settings-box py-2 mb-0';
                    reminderSettingsBox.innerHTML = escSyncHtml(res.message || 'Pengaturan reminder tidak ditemukan.') +
                        (res.username ? ('<br><span class="text-muted">Username akun: <b>' + escSyncHtml(res.username) + '</b></span>') : '');
                    return;
                }

                const s = res.settings;
                reminderSettingsBox.className = 'alert alert-success reminder-settings-box py-2 mb-0';
                reminderSettingsBox.innerHTML =
                    '<div class="mb-2">Sumber: <code>reminder-' + escSyncHtml(res.username) + '.json</code></div>' +
                    '<table class="table table-sm table-bordered mb-0 bg-white">' +
                        '<tbody>' +
                            '<tr><th style="width:45%;">Jatuh Tempo (tanggal)</th><td>' + escSyncHtml(s.jatuh_tempo ?? '-') + '</td></tr>' +
                            '<tr><th>Tanggal Awal Tutup Buku</th><td>' + escSyncHtml(s.tanggal_awal_tutup_buku ?? '-') + '</td></tr>' +
                            '<tr><th>Tanggal Akhir Tutup Buku</th><td>' + escSyncHtml(s.tanggal_akhir_tutup_buku ?? '-') + '</td></tr>' +
                            '<tr><th>Hari Sebelum Reminder</th><td>' + escSyncHtml(s.hari_sebelum ?? '-') + '</td></tr>' +
                            '<tr><th>Tanggal Reminder</th><td>' + escSyncHtml(s.tanggal_reminder ?? '-') + '</td></tr>' +
                            '<tr><th>Bot WA (botname)</th><td>' + escSyncHtml(s.botname ?? '-') + '</td></tr>' +
                        '</tbody>' +
                    '</table>';
            })
            .catch(() => {
                reminderSettingsBox.className = 'alert alert-danger reminder-settings-box py-2 mb-0';
                reminderSettingsBox.innerHTML = 'Gagal memuat pengaturan reminder (koneksi/endpoint bermasalah).';
            });
    }

    // ------------------------------------------------------------------
    // Helper: ambil filter Area/Paket/RT/RW/Kelurahan/Kecamatan/Search yang
    // SEDANG AKTIF di tabel utama (#filterArea, #filterPaket, #filterRT,
    // #filterRW, #filterKelurahan, #filterKecamatan, #filterSearch), supaya
    // sinkron hanya memproses data yang sedang ditampilkan/difilter saat ini.
    // ------------------------------------------------------------------
    function getActiveTableFilters() {
        return {
            area:      (document.getElementById('filterArea') ? document.getElementById('filterArea').value : '').trim(),
            paket:     (document.getElementById('filterPaket') ? document.getElementById('filterPaket').value : '').trim(),
            search:    (document.getElementById('filterSearch') ? document.getElementById('filterSearch').value : '').trim(),
            rt:        (document.getElementById('filterRT') ? document.getElementById('filterRT').value : '').trim(),
            rw:        (document.getElementById('filterRW') ? document.getElementById('filterRW').value : '').trim(),
            kelurahan: (document.getElementById('filterKelurahan') ? document.getElementById('filterKelurahan').value : '').trim(),
            kecamatan: (document.getElementById('filterKecamatan') ? document.getElementById('filterKecamatan').value : '').trim()
        };
    }

    function describeActiveFilters(filters) {
        const parts = [];
        if (filters.area) parts.push('Area = "' + filters.area + '"');
        if (filters.paket) parts.push('Paket = "' + filters.paket + '"');
        if (filters.rt) parts.push('RT = "' + filters.rt + '"');
        if (filters.rw) parts.push('RW = "' + filters.rw + '"');
        if (filters.kelurahan) parts.push('Kelurahan = "' + filters.kelurahan + '"');
        if (filters.kecamatan) parts.push('Kecamatan = "' + filters.kecamatan + '"');
        if (filters.search) parts.push('Cari = "' + filters.search + '"');
        return parts.length ? parts.join(', ') : 'Tidak ada filter aktif (semua data)';
    }

    // ------------------------------------------------------------------
    // STEP 1 -> Preview
    // ------------------------------------------------------------------
    document.getElementById('btnSyncPreview').addEventListener('click', function() {
        const server = document.getElementById('syncServer').value;
        const area = document.getElementById('syncArea').value;
        const odp = document.getElementById('syncOdp').value;

        if (!server || !area) {
            alert('Pilih Server Area terlebih dahulu.');
            return;
        }
        if (!odp) {
            alert('Pilih ODP terlebih dahulu.');
            return;
        }

        const btn = this;
        const label = btn.querySelector('.btn-label');
        const loading = btn.querySelector('.btn-loading');
        btn.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        const activeFilters = getActiveTableFilters();

        const fd = new FormData();
        fd.append('action', 'preview');
        fd.append('pemilik', server);
        fd.append('area', area);
        fd.append('odp', odp);
        fd.append('tipe_bayar', document.getElementById('syncTipeBayar').value);
        fd.append('tipe_tempo', document.getElementById('syncTipeTempo').value);
        fd.append('filter_area', activeFilters.area);
        fd.append('filter_paket', activeFilters.paket);
        fd.append('filter_search', activeFilters.search);
        fd.append('filter_rt', activeFilters.rt);
        fd.append('filter_rw', activeFilters.rw);
        fd.append('filter_kelurahan', activeFilters.kelurahan);
        fd.append('filter_kecamatan', activeFilters.kecamatan);

        fetch('api_hanif_sync_pelanggan_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    throw new Error(res.message || 'Gagal mengambil preview.');
                }

                syncPreviewData = res.data || [];
                syncPasswordMap = {};
                syncAuthModeMap = {};
                syncActiveFilters = activeFilters;

                const summaryBox = document.getElementById('syncSummaryBox');
                summaryBox.className = syncPreviewData.length > 0 ? 'alert alert-success py-2 mb-3' : 'alert alert-warning py-2 mb-3';
                let reminderInfoLine = '';
                if (res.reminder_settings) {
                    reminderInfoLine = res.reminder_settings.found
                        ? `Periode fallback pakai pengaturan tutup buku <code>reminder-${escSyncHtml(res.reminder_settings.username)}.json</code>.<br>`
                        : `Pengaturan reminder tidak ditemukan untuk Server ini, periode fallback pakai mode sederhana (mengikuti tanggal bayar).<br>`;
                }
                summaryBox.innerHTML = reminderInfoLine +
                    `Filter tabel aktif: <b>${escSyncHtml(describeActiveFilters(activeFilters))}</b><br>` +
                    `Total data di API (sesuai filter): <b>${res.total_api_terfilter}</b> dari total keseluruhan API <b>${res.total_api}</b> | ` +
                    `Sudah ada di database: <b>${res.total_sudah_ada}</b> | ` +
                    `<span class="text-success">Pelanggan BARU yang bisa disinkron: <b>${res.total_baru}</b></span>`;

                if (res.router_connect_error) {
                    routerWarningBox.classList.remove('d-none');
                    routerWarningBox.innerHTML = '?? ' + res.router_connect_error +
                        ' Status "Secret Sudah Ada / Belum Ada" di bawah ini mungkin tidak akurat.';
                } else {
                    routerWarningBox.classList.add('d-none');
                    routerWarningBox.innerHTML = '';
                }

                renderSyncPreviewTable(syncPreviewData);

                step1.classList.add('d-none');
                step2.classList.remove('d-none');
                btnExecute.classList.toggle('d-none', syncPreviewData.length === 0);
            })
            .catch(err => {
                alert(err.message);
            })
            .finally(() => {
                btn.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });

    function escSyncHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function formatRupiahSync(val) {
        const n = parseFloat(val) || 0;
        return 'Rp ' + n.toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function statusBadge(status, labelExists, labelNotExists) {
        if (status === 'exists') {
            return '<span class="badge bg-success sync-secret-badge">' + labelExists + '</span>';
        }
        if (status === 'not_exists') {
            return '<span class="badge bg-warning text-dark sync-secret-badge">' + labelNotExists + '</span>';
        }
        return '<span class="badge bg-secondary sync-secret-badge">Tidak Diketahui</span>';
    }

    // Mode Auth per-baris: prioritas (1) pilihan manual/massal admin di
    // Step 2 (syncAuthModeMap), lalu (2) Mode Auth yang sudah ada di data
    // API billing eksternal (row.authmode_api, dari field `MODE`) supaya
    // field pilihan langsung sesuai saat preview pertama kali dimuat, dan
    // (3) fallback MULTI MODE kalau keduanya tidak ada.
    function rowAuthMode(row) {
        if (syncAuthModeMap[row.IDPEL]) {
            return syncAuthModeMap[row.IDPEL];
        }
        return row.authmode_api || 'MULTI MODE';
    }

    // Tampilkan ODP final yang akan dipakai, plus keterangan kecil sumbernya
    // (dari API langsung, atau fallback ke pilihan manual Step 1).
    function odpCellHtml(row) {
        const odpFinal = row.odp_final || '-';
        const sourceLabel = row.odp_source === 'api'
            ? '<span class="badge bg-info text-dark" style="font-size:9px;">dari API</span>'
            : '<span class="badge bg-secondary" style="font-size:9px;">manual</span>';
        return escSyncHtml(odpFinal) + ' ' + sourceLabel;
    }

    // Tampilkan ringkasan periode yang akan dipakai untuk transaksi
    // pemasukan pelanggan ini (hasil resolvePeriodeFinal() per transaksi,
    // dikirim server sebagai array unik preview_periode_list).
    function periodeCellHtml(row) {
        const list = row.preview_periode_list || [];
        if (!list.length) {
            return '<span class="text-muted">-</span>';
        }
        return list.map(p => '<span class="badge bg-primary" style="font-size:10px;">' + escSyncHtml(p) + '</span>').join(' ');
    }

    function renderSyncPreviewTable(data) {
        const tbody = document.getElementById('syncPreviewBody');
        if (!data.length) {
            tbody.innerHTML = '<tr><td colspan="21" class="text-center text-muted">Tidak ada pelanggan baru. Semua data API sudah ada di database.</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(function(row, idx) {
            const authModeValue = rowAuthMode(row);
            // Password PPPoE WAJIB diisi untuk semua baris (disimpan ke kolom
            // PASSWORD tabel pelanggan apapun status akunnya di MikroTik/RADIUS).
            const passwordPlaceholder = 'Wajib diisi...';
            const passwordValue = syncPasswordMap[row.IDPEL] || '';

            return '<tr>' +
                '<td><input type="checkbox" class="sync-row-cb" data-idx="' + idx + '" checked></td>' +
                '<td><strong>' + escSyncHtml(row.IDPEL) + '</strong></td>' +
                '<td>' + escSyncHtml(row.NAMA) + '</td>' +
                '<td>' + escSyncHtml(row.NOWA) + '</td>' +
                '<td>' + escSyncHtml(row.PAKET) + '</td>' +
                '<td>' + escSyncHtml(row.AREA || '-') + '</td>' +
                '<td>' + escSyncHtml(row.rt || '-') + '</td>' +
                '<td>' + escSyncHtml(row.rw || '-') + '</td>' +
                '<td>' + escSyncHtml(row.kelurahan || '-') + '</td>' +
                '<td>' + escSyncHtml(row.kecamatan || '-') + '</td>' +
                '<td>' + escSyncHtml((row.ALAMAT || '') + (row.no_rumah ? (' No ' + row.no_rumah) : '')) + '</td>' +
                '<td>' + escSyncHtml(row.TANGGALPASANG || '-') + '</td>' +
                '<td>' + escSyncHtml(row.sales || '-') + '</td>' +
                '<td>' + odpCellHtml(row) + '</td>' +
                '<td>' + periodeCellHtml(row) + '</td>' +
                '<td>' + statusBadge(row.secret_status, 'Sudah Ada', 'Belum Ada') + '</td>' +
                '<td>' + statusBadge(row.radius_status, 'Sudah Ada', 'Belum Ada') + '</td>' +
                '<td>' +
                    '<select class="form-select form-select-sm sync-row-authmode" data-idx="' + idx + '" data-idpel="' + escSyncHtml(row.IDPEL) + '">' +
                        '<option value="API MODE"' + (authModeValue === 'API MODE' ? ' selected' : '') + '>API MODE</option>' +
                        '<option value="RADIUS MODE"' + (authModeValue === 'RADIUS MODE' ? ' selected' : '') + '>RADIUS MODE</option>' +
                        '<option value="MULTI MODE"' + (authModeValue === 'MULTI MODE' ? ' selected' : '') + '>MULTI MODE</option>' +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<input type="text" class="form-control form-control-sm sync-password-input sync-row-password" ' +
                        'data-idpel="' + escSyncHtml(row.IDPEL) + '" ' +
                        'placeholder="' + escSyncHtml(passwordPlaceholder) + '" ' +
                        'value="' + escSyncHtml(passwordValue) + '">' +
                '</td>' +
                '<td class="text-center">' + (row.total_transaksi_pemasukan || 0) + '</td>' +
                '<td>' + formatRupiahSync(row.total_nominal_pemasukan || 0) + '</td>' +
                '</tr>';
        }).join('');
    }

    // Simpan setiap perubahan input password individual ke syncPasswordMap.
    document.getElementById('syncPreviewBody').addEventListener('input', function(e) {
        if (!e.target.classList.contains('sync-row-password')) return;
        const idpel = e.target.getAttribute('data-idpel');
        if (idpel) {
            syncPasswordMap[idpel] = e.target.value;
        }
    });

    // Simpan setiap perubahan Mode Auth individual ke syncAuthModeMap.
    document.getElementById('syncPreviewBody').addEventListener('change', function(e) {
        if (!e.target.classList.contains('sync-row-authmode')) return;
        const idx = parseInt(e.target.getAttribute('data-idx'), 10);
        const row = syncPreviewData[idx];
        if (!row) return;
        syncAuthModeMap[row.IDPEL] = e.target.value;
    });

    // Tombol "Terapkan Mode ke Semua": set Mode Auth yang sama untuk semua
    // baris preview sekaligus (mirip "Terapkan ke Semua" untuk password).
    document.getElementById('btnApplyBulkAuthMode').addEventListener('click', function() {
        const bulkMode = document.getElementById('syncBulkAuthMode').value;
        document.querySelectorAll('.sync-row-authmode').forEach(function(sel) {
            sel.value = bulkMode;
            const idx = parseInt(sel.getAttribute('data-idx'), 10);
            const row = syncPreviewData[idx];
            if (!row) return;
            syncAuthModeMap[row.IDPEL] = bulkMode;
        });
    });

    // Tombol "Terapkan ke Semua": isi password yang sama untuk semua baris
    // yang masih butuh password (secret belum ada), tanpa menimpa baris
    // yang secretnya sudah ada (tidak relevan dipakaikan password).
    document.getElementById('btnApplyBulkPassword').addEventListener('click', function() {
        const bulkVal = document.getElementById('syncBulkPassword').value;
        if (!bulkVal) {
            alert('Isi dulu password yang ingin diterapkan ke semua baris.');
            return;
        }

        document.querySelectorAll('.sync-row-password').forEach(function(input) {
            input.value = bulkVal;
            const idpel = input.getAttribute('data-idpel');
            if (idpel) {
                syncPasswordMap[idpel] = bulkVal;
            }
        });
    });

    document.getElementById('syncSelectAll').addEventListener('change', function() {
        const checked = this.checked;
        document.querySelectorAll('.sync-row-cb').forEach(cb => { cb.checked = checked; });
    });

    document.getElementById('btnBackToStep1').addEventListener('click', function() {
        step2.classList.add('d-none');
        step1.classList.remove('d-none');
        btnExecute.classList.add('d-none');
    });

    // ------------------------------------------------------------------
    // STEP 2 -> Execute (insert ke database)
    // ------------------------------------------------------------------
    btnExecute.addEventListener('click', function() {
        const selectedIdpel = [];
        const missingPasswordIdpel = [];
        // Mode Auth final tiap IDPEL terpilih, dikumpulkan langsung dari
        // rowAuthMode() (pilihan manual admin, atau default dari data API
        // kalau belum diubah) supaya tidak salah jatuh ke MULTI MODE.
        const authModePayload = {};

        document.querySelectorAll('.sync-row-cb:checked').forEach(function(cb) {
            const idx = parseInt(cb.getAttribute('data-idx'), 10);
            const row = syncPreviewData[idx];
            if (!row) return;

            selectedIdpel.push(row.IDPEL);
            authModePayload[row.IDPEL] = rowAuthMode(row);

            // Validasi: SETIAP baris yang dipilih wajib punya Password PPPoE
            // diisi, apapun status MikroTik/RADIUS-nya -- password ini juga
            // disimpan ke kolom PASSWORD tabel pelanggan, jadi tidak boleh
            // ada satupun yang kosong.
            const pwd = (syncPasswordMap[row.IDPEL] || '').trim();
            if (pwd === '') {
                missingPasswordIdpel.push(row.IDPEL);
            }
        });

        if (selectedIdpel.length === 0) {
            alert('Pilih minimal 1 pelanggan untuk disinkron.');
            return;
        }

        if (missingPasswordIdpel.length > 0) {
            alert('Password PPPoE WAJIB diisi untuk SEMUA pelanggan terpilih. Masih kosong untuk:\n\n' +
                missingPasswordIdpel.join(', ') +
                '\n\nIsi password (individual atau pakai "Terapkan ke Semua") sebelum melanjutkan. ' +
                'Sinkronisasi tidak bisa dilanjutkan selama masih ada password yang kosong.');
            return;
        }

        const confirmMsg = `Anda akan menambahkan ${selectedIdpel.length} pelanggan baru beserta riwayat transaksinya ke database.\n\nLanjutkan?`;
        if (!confirm(confirmMsg)) {
            return;
        }

        const btn = this;
        const label = btn.querySelector('.btn-label');
        const loading = btn.querySelector('.btn-loading');
        btn.disabled = true;
        label.classList.add('d-none');
        loading.classList.remove('d-none');

        // Kumpulkan hanya password milik IDPEL yang dipilih.
        const passwordPayload = {};
        selectedIdpel.forEach(function(idpel) {
            if (syncPasswordMap[idpel]) {
                passwordPayload[idpel] = syncPasswordMap[idpel];
            }
        });

        const fd = new FormData();
        fd.append('action', 'execute');
        fd.append('pemilik', document.getElementById('syncServer').value);
        fd.append('area', document.getElementById('syncArea').value);
        fd.append('brand', document.getElementById('syncBrand').value);
        fd.append('odp', document.getElementById('syncOdp').value);
        fd.append('sales', document.getElementById('syncSales').value);
        fd.append('tipe_bayar', document.getElementById('syncTipeBayar').value);
        fd.append('tipe_tempo', document.getElementById('syncTipeTempo').value);
        fd.append('idpel_list', JSON.stringify(selectedIdpel));
        fd.append('idpel_password', JSON.stringify(passwordPayload));
        fd.append('idpel_authmode', JSON.stringify(authModePayload));
        fd.append('filter_area', syncActiveFilters.area || '');
        fd.append('filter_paket', syncActiveFilters.paket || '');
        fd.append('filter_search', syncActiveFilters.search || '');
        fd.append('filter_rt', syncActiveFilters.rt || '');
        fd.append('filter_rw', syncActiveFilters.rw || '');
        fd.append('filter_kelurahan', syncActiveFilters.kelurahan || '');
        fd.append('filter_kecamatan', syncActiveFilters.kecamatan || '');

        fetch('api_hanif_sync_pelanggan_api.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    throw new Error(res.message || 'Sinkronisasi gagal.');
                }

                let html = '<div class="alert alert-success">';
                html += `? Sinkronisasi selesai. Berhasil: <b>${res.total_berhasil}</b>, Dilewati: <b>${res.total_dilewati}</b>, Error: <b>${res.total_error}</b>.`;
                html += '</div>';

                if (res.reminder_settings_info) {
                    html += '<div class="alert alert-secondary py-2">?? ' + escSyncHtml(res.reminder_settings_info) + '</div>';
                }

                if (res.total_berhasil > 0) {
                    html += '<div class="alert ' + (res.radius_restarted ? 'alert-success' : 'alert-secondary') + ' py-2">';
                    html += res.radius_restarted
                        ? '? FreeRADIUS sudah di-restart sekali untuk menerapkan akun baru.'
                        : '?? Tidak ada akun RADIUS baru yang ditambahkan pada batch ini, FreeRADIUS tidak perlu direstart.';
                    html += '</div>';
                }

                if (res.router_connect_error) {
                    html += '<div class="alert alert-warning">?? ' + escSyncHtml(res.router_connect_error) + '</div>';
                }

                if (res.notes && res.notes.length > 0) {
                    html += '<div class="alert alert-secondary py-2"><b>Detail per pelanggan (MikroTik / RADIUS):</b>';
                    html += '<div class="table-responsive mt-1"><table class="table table-sm table-bordered mb-0"><thead><tr><th>IDPEL</th><th>MikroTik</th><th>RADIUS</th></tr></thead><tbody>';
                    res.notes.forEach(n => {
                        html += `<tr><td>${escSyncHtml(n.idpel)}</td><td>${escSyncHtml(n.mikrotik)}</td><td>${escSyncHtml(n.radius)}</td></tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }

                if (res.errors && res.errors.length > 0) {
                    html += '<div class="alert alert-danger"><b>Detail Error:</b><ul class="mb-0">';
                    res.errors.forEach(e => {
                        html += `<li>${escSyncHtml(e.idpel)}: ${escSyncHtml(e.reason)}</li>`;
                    });
                    html += '</ul></div>';
                }

                if (res.skipped && res.skipped.length > 0) {
                    html += '<div class="alert alert-warning"><b>Dilewati:</b><ul class="mb-0">';
                    res.skipped.forEach(s => {
                        html += `<li>${escSyncHtml(s.idpel)}: ${escSyncHtml(s.reason)}</li>`;
                    });
                    html += '</ul></div>';
                }

                resultBox.innerHTML = html;
                resultBox.classList.remove('d-none');
                step2.classList.add('d-none');
                btnExecute.classList.add('d-none');

                // Reload halaman setelah 2.5 detik supaya tabel pelanggan utama ikut update.
                setTimeout(function() {
                    window.location.reload();
                }, 2500);
            })
            .catch(err => {
                alert(err.message);
            })
            .finally(() => {
                btn.disabled = false;
                label.classList.remove('d-none');
                loading.classList.add('d-none');
            });
    });
})();
</script>
<?php endif; ?>

</body>
</html>