<?php
/**
 * Buat tagihan (baris PENAGIHAN) sendiri dari portal pelanggan, dipakai tombol
 * "Buat Tagihan Sekarang" di portal_bayar.php saat belum ada tagihan aktif
 * untuk periode berjalan ("Belum ada tagihan baru..").
 *
 * Mekanisme SAMA PERSIS dengan "Manual Generate Invoice" milik admin di menu
 * Transaksi Billing (proses/manual_generate_invoice.php) -- bedanya: (1) cuma
 * utk SATU pelanggan yang sedang login, (2) periode DIHITUNG OTOMATIS oleh
 * sistem (pakai variabel yang SUDAH dihitung cek_sesi.php: $isFixedDueDateTampilFokus,
 * $periodeBerjalanTampilFokus/$bulanAktualTampilFokus, $adaPenagihanTampil --
 * fungsi yang SAMA PERSIS dgn yang menentukan kapan "Belum ada tagihan baru"
 * ditampilkan), TIDAK ADA input periode dari pelanggan sama sekali -- supaya
 * tidak bisa disalahgunakan (pilih periode jauh ke depan / bikin tagihan dobel).
 */
// FIX (2026-09-19): cek_sesi.php SELALU echo tag <link>/<script> Bootstrap di
// baris paling atas (didesain utk halaman HTML biasa, bukan endpoint JSON) --
// dan utk kasus pelanggan tidak ditemukan, echo modal HTML LALU exit langsung.
// Tanpa output buffering, teks itu akan nempel SEBELUM/GANTIKAN JSON di bawah,
// bikin response.json() di fetch() sisi browser gagal parse. Buang isi buffer
// sebelum kita mulai output JSON sendiri.
ob_start();
include 'cek_sesi.php';
ob_end_clean();
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan.']);
    exit;
}

if (empty($idpel) || empty($pelanggan)) {
    echo json_encode(['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
    exit;
}

// Guard: akun yang sudah tidak aktif (nonaktif/dismantle/dst) tidak perlu tagihan baru.
$modeCekTagihanSendiri = strtoupper(trim((string)($pelanggan['MODE'] ?? '')));
if (in_array($modeCekTagihanSendiri, ['NONAKTIF', 'DISABLED', 'DISMANTLE', 'BERHENTI'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akun Anda saat ini tidak aktif.']);
    exit;
}

// Guard (diminta): paket gratis/Rp0 tidak perlu tombol/aksi buat tagihan sendiri.
if (empty($paketHarga) || (float)$paketHarga <= 0) {
    echo json_encode(['success' => false, 'message' => 'Paket Anda tidak memerlukan tagihan.']);
    exit;
}

// Guard utama: HANYA boleh kalau memang belum ada tagihan aktif utk periode
// ini -- $adaPenagihanTampil dihitung di cek_sesi.php dgn logika TIPE_TEMPO-aware
// yang SAMA PERSIS dgn yang menentukan apakah "Belum ada tagihan baru.."
// ditampilkan di portal_bayar.php.
if (!empty($adaPenagihanTampil)) {
    echo json_encode(['success' => false, 'message' => 'Tagihan untuk periode ini sudah ada.']);
    exit;
}

// Label periode: dari variabel yang SAMA PERSIS dipakai cek_sesi.php utk
// menentukan status SHOW/HIDE di atas (bukan hitung ulang sendiri di sini).
$periodeBaruTagihanSendiri = $isFixedDueDateTampilFokus
    ? (string)($periodeBerjalanTampilFokus ?? '')
    : (string)($bulanAktualTampilFokus ?? '');
$periodeBaruTagihanSendiri = trim($periodeBaruTagihanSendiri);
if ($periodeBaruTagihanSendiri === '') {
    echo json_encode(['success' => false, 'message' => 'Gagal menentukan periode tagihan.']);
    exit;
}
$periodeBaruNormalized = mb_strtoupper($periodeBaruTagihanSendiri, 'UTF-8');

// Cek ulang LANGSUNG ke DB sesaat sebelum insert (bukan cuma andalkan
// $adaPenagihanTampil yang dihitung di awal request) -- proteksi race
// condition kalau tombol diklik dobel/2 tab bersamaan. Pola sama dgn
// pengecekan anti-duplikat di manual_generate_invoice.php.
$trxDateExprTagihanSendiri = tagihanBuildTrxDateExpr();
$stmtCekUlang = $conn->prepare("SELECT id, TRIM(UPPER(COALESCE(STATUS, ''))) AS STATUS_NORM FROM transaksi WHERE IDPEL = ? AND TRIM(UPPER(COALESCE(PENGUNAAN, ''))) = ? AND TRIM(UPPER(COALESCE(STATUS, ''))) IN ('PENAGIHAN','PERMINTAAN KODE','KONFIRMASI','BERHASIL') LIMIT 1");
$stmtCekUlang->bind_param('ss', $idpel, $periodeBaruNormalized);
$stmtCekUlang->execute();
$rowCekUlang = $stmtCekUlang->get_result()->fetch_assoc();
$stmtCekUlang->close();
if ($rowCekUlang) {
    echo json_encode(['success' => false, 'message' => 'Tagihan untuk periode ini sudah ada.']);
    exit;
}

// TANGGALBAYAR: pakai tanggal HARI INI (invoice ini memang baru dibuat
// sekarang, atas inisiatif pelanggan sendiri) -- BUKAN tanggal jatuh tempo
// target spt di manual_generate_invoice.php (yang dipakai admin utk generate
// periode LAMPAU/masa depan tertentu). Kolom PENGUNAAN di atas sudah cukup
// membawa makna "periode yang mana" -- TANGGALBAYAR di sini cuma perlu jadi
// tanggal valid & sortable (dipakai ORDER BY di portal_bayar.php).
function tagihanSendiriFormatTanggalIndo(): string
{
    $hari_indonesia = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan_indonesia = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = time();
    return $hari_indonesia[(int)date('w', $ts)] . ', ' . date('d', $ts) . ' ' . $bulan_indonesia[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
$tanggalBayarTagihanSendiri = tagihanSendiriFormatTanggalIndo();

$bukti_ref_tagihan_sendiri = 'INV-PENAGIHAN-PORTAL-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . date('YmdHis');
$cek_tagihan_sendiri = 'BUAT TAGIHAN SENDIRI (PORTAL)';
$namaPelangganTagihanSendiri = (string)($pelanggan['NAMA'] ?? '');
$paketPelangganTagihanSendiri = (string)($pelanggan['PAKET'] ?? '');
$pemilikTagihanSendiri = (string)($pelanggan['PEMILIK'] ?? '');

$stmtInsertTagihanSendiri = $conn->prepare("INSERT INTO transaksi (TANGGALBAYAR, PENGUNAAN, STATUS, IDPEL, NAMA, PAKET, HARGA, BUKTI, CEK, PEMILIK) VALUES (?, ?, 'PENAGIHAN', ?, ?, ?, ?, ?, ?, ?)");
$hargaTagihanSendiri = (float)$paketHarga;
$stmtInsertTagihanSendiri->bind_param(
    'sssssdsss',
    $tanggalBayarTagihanSendiri,
    $periodeBaruTagihanSendiri,
    $idpel,
    $namaPelangganTagihanSendiri,
    $paketPelangganTagihanSendiri,
    $hargaTagihanSendiri,
    $bukti_ref_tagihan_sendiri,
    $cek_tagihan_sendiri,
    $pemilikTagihanSendiri
);

if ($stmtInsertTagihanSendiri->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Tagihan untuk periode ' . $periodeBaruTagihanSendiri . ' berhasil dibuat.',
        'periode' => $periodeBaruTagihanSendiri,
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal membuat tagihan: ' . $stmtInsertTagihanSendiri->error]);
}
$stmtInsertTagihanSendiri->close();
