<?php
require 'cek-sesi.php';
require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';

// Toggle "btn_trx_lihat_bukti" (Pengaturan User > Tombol Individual ASSISTANT, halaman
// Transaction) -- file ini dimuat sebagai <iframe> tanpa require header.php, jadi
// window.billingUiVisibility (JS) tidak pernah ter-inject di sini dan pengecekan
// harus diulang manual server-side, baca file JSON yang sama persis dengan yang
// dipakai load_ui_visibility_settings() di header.php.
$ctBisaLihatBukti = true;
if ($AKSES === 'ASSISTANT') {
    $ctBuktiSettingsUsername = !empty($asistant_name) ? $asistant_name : $ceknama;
    $ctBuktiSafeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ctBuktiSettingsUsername);
    $ctBisaLihatBukti = false; // default ASSISTANT: sembunyikan, kecuali toggle diaktifkan
    if ($ctBuktiSafeUsername !== '') {
        $ctBuktiSettingsFile = __DIR__ . '/settings/dashboard-cards-' . $ctBuktiSafeUsername . '.json';
        if (is_file($ctBuktiSettingsFile)) {
            $ctBuktiDecoded = json_decode((string)@file_get_contents($ctBuktiSettingsFile), true);
            if (is_array($ctBuktiDecoded) && array_key_exists('btn_trx_lihat_bukti', $ctBuktiDecoded)) {
                $ctBisaLihatBukti = (bool)$ctBuktiDecoded['btn_trx_lihat_bukti'];
            } else {
                $ctBisaLihatBukti = true; // key belum pernah disimpan -> default true, sama seperti $defaults di header.php
            }
        } else {
            $ctBisaLihatBukti = true; // belum pernah ada file settings -> default true
        }
    }
}

$idpel = isset($_GET['idpel']) ? mysqli_real_escape_string($conn, $_GET['idpel']) : '';

// Scoping kepemilikan server (pola sama dgn dashboard.php) -- WAJIB, sebelum
// fix ini file ini bisa dipanggil dgn ?idpel= manapun (termasuk milik
// owner/area lain) dan menampilkan seluruh riwayat transaksinya tanpa cek
// apapun.
$ctOwnedPemilik = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $qCtOwn = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($qCtOwn) {
            while ($rCtOwn = mysqli_fetch_assoc($qCtOwn)) {
                $pCtOwn = trim((string)($rCtOwn['PEMILIK'] ?? ''));
                if ($pCtOwn !== '') {
                    $ctOwnedPemilik[] = "'" . mysqli_real_escape_string($conn, $pCtOwn) . "'";
                }
            }
        }
    }
} elseif ($AKSES !== 'ADMIN') {
    $qCtOwn = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)($current_user_id ?? 0));
    if ($qCtOwn) {
        while ($rCtOwn = mysqli_fetch_assoc($qCtOwn)) {
            $pCtOwn = trim((string)($rCtOwn['PEMILIK'] ?? ''));
            if ($pCtOwn !== '') {
                $ctOwnedPemilik[] = "'" . mysqli_real_escape_string($conn, $pCtOwn) . "'";
            }
        }
    }
}
$ctOwnedPemilikList = count($ctOwnedPemilik) > 0 ? implode(',', $ctOwnedPemilik) : "''";
$ctPemilikScopeSql = ($AKSES === 'ADMIN') ? '1=1' : "PEMILIK IN ($ctOwnedPemilikList)";

$sql1 = "SELECT * FROM `transaksi` WHERE `IDPEL` = '$idpel' AND $ctPemilikScopeSql
  ORDER BY
    CAST(SUBSTRING_INDEX(TRIM(PENGUNAAN), ' ', -1) AS UNSIGNED) DESC,
    FIELD(
      TRIM(SUBSTRING_INDEX(TRIM(PENGUNAAN), ' ', 1)),
      'Januari','Februari','Maret','April','Mei','Juni',
      'Juli','Agustus','September','Oktober','November','Desember'
    ) DESC,
    id DESC";
$query1 = mysqli_query($conn, $sql1);

// Info mode tempo pelanggan diambil SEKALI, tapi jatuh tempo yang ditampilkan
// dihitung PER TRANSAKSI (pakai tanggal bayar transaksi itu sendiri sbg acuan) --
// BUKAN "jatuh tempo berikutnya" global yang sama di semua baris.
$customertransTipeTempo = '';
$customertransAnchorDay = 25;
$customertransTanggalPasang = '';
if ($idpel !== '') {
    $pelCtQ = mysqli_query($conn, "SELECT TIPE_TEMPO, TANGGALPASANG, TANGGAL_MONTHVERSARY FROM pelanggan WHERE IDPEL = '$idpel' LIMIT 1");
    $pelCt = $pelCtQ ? mysqli_fetch_assoc($pelCtQ) : null;
    if ($pelCt) {
        $customertransTipeTempo = strtolower(trim((string)($pelCt['TIPE_TEMPO'] ?? '')));
        $customertransTanggalPasang = (string)($pelCt['TANGGALPASANG'] ?? '');
        if ($customertransTipeTempo === 'monthversary') {
            $anchorDateCt = (string)($pelCt['TANGGAL_MONTHVERSARY'] ?? '');
            if ($anchorDateCt === '' || strtotime($anchorDateCt) === false) {
                $anchorDateCt = (string)($pelCt['TANGGALPASANG'] ?? '');
            }
            if ($anchorDateCt !== '' && strtotime($anchorDateCt) !== false) {
                $customertransAnchorDay = (int)date('j', strtotime($anchorDateCt));
            }
        }
    }
}

// Jatuh tempo yang DIPENUHI oleh transaksi ini sendiri (BUKAN jatuh tempo
// berikutnya setelah bayar). Fixed & Monthversary: hari (jatuh_tempo_hari/anchor)
// dipasang ke BULAN pembayaran transaksi ini sendiri (bukan bulan PENGUNAAN, yang
// cuma label periode dan bisa beda). Rolling: hasil simulasi siklus 30 hari dari
// histori pembayaran SEBELUM baris ini (supaya aturan bayar cepat/telat tetap
// dihormati -- BUKAN asal tanggal bayar transaksi ini + 30 hari, itu jatuh tempo
// SIKLUS BERIKUTNYA, bukan yang dipenuhi transaksi ini).
function customertransHitungJatuhTempoBaris($conn, $tipeTempo, $anchorDay, $jatuhTempoHariFixed, $idpelRow, $tanggalPasangRow, $waktuRow, $rowId, $statusRow)
{
    if (strtoupper(trim((string)$statusRow)) === 'PENAGIHAN') {
        return '';
    }
    if (empty($waktuRow) || strtotime($waktuRow) === false) {
        return '';
    }
    $tsPay = strtotime($waktuRow);
    if ($tipeTempo === 'mengikuti_tanggal_tempo') {
        $due = tagihanBuildMonthlyDate((int)date('Y', $tsPay), (int)date('n', $tsPay), $jatuhTempoHariFixed);
        return $due ? date('d-m-Y', strtotime($due)) : '';
    }
    if ($tipeTempo === 'monthversary') {
        $due = tagihanBuildMonthlyDate((int)date('Y', $tsPay), (int)date('n', $tsPay), $anchorDay);
        return $due ? date('d-m-Y', strtotime($due)) : '';
    }
    if ($tipeTempo === 'mengikuti_tanggal_bayar') {
        $due = tagihanGetRollingDueDateForRow($conn, (string)$idpelRow, (string)$tanggalPasangRow, (int)$rowId);
        return $due ? date('d-m-Y', strtotime($due)) : '';
    }
    return '';
}

// Label tipe tempo pelanggan, ditampilkan apa adanya di tiap kartu.
$customertransTipeTempoLabelMap = [
    'mengikuti_tanggal_tempo' => 'Fixed Due Date',
    'mengikuti_tanggal_bayar' => 'Rolling',
    'monthversary' => 'Monthversary',
];
$customertransTipeTempoLabel = $customertransTipeTempoLabelMap[$customertransTipeTempo] ?? '-';

// Setting jatuh_tempo (Payment Setting -> Konfigurasi Fixed Due Date) dicache per
// PEMILIK, dipakai kalau tipe tempo pelanggan ini Fixed Due Date.
$customertransJatuhTempoHariCache = [];
function customertransGetJatuhTempoHari($pemilik, &$cache)
{
    if (isset($cache[$pemilik])) {
        return $cache[$pemilik];
    }
    $val = 25;
    $file = __DIR__ . '/notifbot/data/reminder-' . $pemilik . '.json';
    if (file_exists($file)) {
        $cfg = json_decode(file_get_contents($file), true);
        if (is_array($cfg) && isset($cfg[0]['jatuh_tempo'])) {
            $val = (int)$cfg[0]['jatuh_tempo'];
        }
    }
    $cache[$pemilik] = max(1, min(31, $val));
    return $cache[$pemilik];
}

$configPath = __DIR__ . '/config.json';
$config = [];
if (file_exists($configPath)) {
    $rawConfig = file_get_contents($configPath);
    $decodedConfig = json_decode($rawConfig, true);
    if (is_array($decodedConfig)) {
        $config = $decodedConfig;
    }
}

$primaryColor = $config['extracted_primary_color'] ?? '#1f3c88';
$secondaryColor = $config['extracted_secondary_color'] ?? '#2f5bcc';
$themeBgColor = $config['theme_color'] ?? '#f4f7fb';

if (!is_string($primaryColor) || !preg_match('/^#[a-fA-F0-9]{6}$/', $primaryColor)) {
    $primaryColor = '#1f3c88';
}
if (!is_string($secondaryColor) || !preg_match('/^#[a-fA-F0-9]{6}$/', $secondaryColor)) {
    $secondaryColor = '#2f5bcc';
}
if (!is_string($themeBgColor) || !preg_match('/^#[a-fA-F0-9]{6}$/', $themeBgColor)) {
    $themeBgColor = '#f4f7fb';
}
?>

<style>
    :root {
        --ct-brand-primary: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8'); ?>;
        --ct-brand-secondary: <?= htmlspecialchars($secondaryColor, ENT_QUOTES, 'UTF-8'); ?>;
        --ct-bg: <?= htmlspecialchars($themeBgColor, ENT_QUOTES, 'UTF-8'); ?>;
        --ct-surface: #ffffff;
        --ct-text: #1f2937;
        --ct-muted: #64748b;
        --ct-border: #e5edf7;
    }

    html.theme-dark,
    body.theme-dark,
    html.app-theme-dark,
    body.app-theme-dark {
        --ct-bg: #0b1220;
        --ct-surface: #111827;
        --ct-text: #e5e7eb;
        --ct-muted: #cbd5e1;
        --ct-border: rgba(255, 255, 255, 0.14);
    }

    body {
        margin: 0;
        padding: 0;
        background: var(--ct-bg);
        font-size: 13px;
        color: var(--ct-text);
    }

    .page-wrap {
        width: 100%;
        padding: 0;
        box-sizing: border-box;
    }

    .card-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 14px 18px;
        background: linear-gradient(135deg, var(--ct-brand-primary), var(--ct-brand-secondary));
        color: #fff;
    }

    .card-head h5 {
        margin: 0;
        font-size: 13px;
        font-weight: 700;
        color: #fff;
    }

    .card-head .pill {
        font-size: 11px;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 10px;
        border-radius: 999px;
    }

    .ct-card {
        background: var(--ct-surface);
        border-left: 4px solid #ff9800;
        border-top: 1px solid var(--ct-border);
        border-right: 1px solid var(--ct-border);
        border-bottom: 1px solid var(--ct-border);
        border-radius: 6px;
        margin: 10px 10px 0 10px;
        padding: 12px 14px;
    }

    .ct-card:last-child {
        margin-bottom: 10px;
    }

    .ct-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 6px;
    }

    .ct-badges {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }

    .ct-timestamp {
        font-size: 11px;
        color: var(--ct-muted);
        white-space: nowrap;
    }

    .ct-badge {
        font-size: 10px;
        border-radius: 999px;
        padding: 3px 9px;
        font-weight: 600;
        display: inline-block;
    }

    .ct-badge-trx    { background: #ff9800; color: #fff; }
    .ct-badge-ok     { background: #16a34a; color: #fff; }
    .ct-badge-tagih  { background: #f59e0b; color: #111; }
    .ct-badge-other  { background: #64748b; color: #fff; }

    .ct-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 8px 14px;
        margin-bottom: 8px;
    }

    .ct-field small {
        display: block;
        font-size: 10px;
        color: var(--ct-muted);
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .ct-field strong {
        font-size: 12px;
        color: var(--ct-text);
    }

    .ct-harga  { color: #0f766e; }
    .ct-gross  { color: #0ea5e9; }
    .ct-fee    { color: #ef4444; }

    .ct-bukti-img {
        display: block;
        margin-top: 8px;
        width: 64px;
        height: 64px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid var(--ct-border);
    }

    .ct-empty {
        text-align: center;
        color: var(--ct-muted);
        padding: 24px;
        font-size: 12px;
    }
</style>

<div class="page-wrap">
    <div class="card-head">
        <h5>Riwayat Transaksi Pelanggan</h5>
        <div style="display:flex;gap:6px;align-items:center;">
            <span class="pill">Total: <?= mysqli_num_rows($query1); ?> transaksi</span>
            <span class="pill">Reload: <span id="reloadCountdown">30</span>s</span>
        </div>
    </div>

    <?php if (mysqli_num_rows($query1) > 0): ?>
        <?php while ($data1 = mysqli_fetch_array($query1)): ?>
            <?php
            $statusText = strtoupper(trim((string)($data1['STATUS'] ?? '')));
            $statusLabel = $statusText;
            if ($statusText === 'BERHASIL') {
                $statusBadgeClass = 'ct-badge-ok';
            } elseif ($statusText === 'PENAGIHAN') {
                $statusBadgeClass = 'ct-badge-tagih';
                $statusLabel = 'PENAGIHAN (menunggu bayar)';
            } else {
                $statusBadgeClass = 'ct-badge-other';
            }

            $metodeRaw = strtolower(trim((string)($data1['METODE_BAYAR'] ?? '')));
            if ($metodeRaw === 'cash') $metodeLabel = 'Cash';
            elseif ($metodeRaw === 'transfer') $metodeLabel = 'Transfer';
            else $metodeLabel = 'Payment gateway';

            $buktiRaw = trim((string)($data1['BUKTI'] ?? ''));
            $buktiExt = strtolower(pathinfo($buktiRaw, PATHINFO_EXTENSION));
            $isImageBukti = in_array($buktiExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
            $buktiUrl = '';
            if ($isImageBukti && $buktiRaw !== '') {
                if (strpos($buktiRaw, 'http://') === 0 || strpos($buktiRaw, 'https://') === 0) {
                    $buktiUrl = $buktiRaw;
                } elseif (strpos($buktiRaw, 'uploads/') === 0) {
                    $buktiUrl = $buktiRaw;
                } elseif (strpos($buktiRaw, 'buktibon/') === 0) {
                    $buktiUrl = '/dokumen/' . $buktiRaw;
                } else {
                    $buktiUrl = 'uploads/' . ltrim($buktiRaw, '/');
                }
            }

            // Dihitung per baris (pakai tanggal bayar transaksi INI), bukan status
            // "jatuh tempo berikutnya" global -- supaya tiap kartu menampilkan jatuh
            // tempo yang relevan utk transaksi itu sendiri. Berlaku utk semua status
            // non-PENAGIHAN, termasuk BERHASIL.
            // PENTING: jatuh_tempo disimpan per AKUN CRM (reminder-{$ceknama}.json),
            // BUKAN per PEMILIK server/router transaksi ini (bisa beda -- lihat
            // komentar $row_struk_username di riwayatTransaction.php utk kasus yang
            // sama). Halaman ini butuh sesi login (cek-sesi.php), jadi $ceknama
            // sudah pasti akun pemilik yang benar.
            $ctJatuhTempoHariFixed = ($customertransTipeTempo === 'mengikuti_tanggal_tempo')
                ? customertransGetJatuhTempoHari((string)($ceknama ?? ''), $customertransJatuhTempoHariCache)
                : 25;
            $ctJatuhTempoBaris = customertransHitungJatuhTempoBaris($conn, $customertransTipeTempo, $customertransAnchorDay, $ctJatuhTempoHariFixed, $data1['IDPEL'] ?? $idpel, $customertransTanggalPasang, $data1['waktu'] ?? '', $data1['id'] ?? 0, $data1['STATUS'] ?? '');

            $hasExtra = !empty($data1['payment_method']) || !empty($data1['harga_gross']);

            // Tampilan harga ikut menimpa dengan custom_harga reseller/mitra (kalau filter
            // harga aktif), sama seperti yang dipakai saat generate invoice. Fallback ke nilai
            // tersimpan kalau paket tidak ketemu (mis. sudah diganti nama/dihapus).
            $displayHarga1 = (float)$data1['HARGA'];
            if (!empty($is_reseller) && !empty($reseller_price_filter_enabled)) {
                $effectiveHarga1 = reseller_effective_harga($conn, $data1['PAKET'], $data1['PEMILIK']);
                if ($effectiveHarga1 > 0) {
                    $displayHarga1 = $effectiveHarga1;
                }
            }
            ?>
            <div class="ct-card">
                <!-- Header -->
                <div class="ct-header">
                    <div class="ct-badges">
                        <span class="ct-badge ct-badge-trx">TRANSAKSI</span>
                        <span class="ct-badge <?= $statusBadgeClass; ?>"><?= htmlspecialchars($statusLabel ?: '-'); ?></span>
                    </div>
                    <span class="ct-timestamp"><?= htmlspecialchars((string)$data1['TANGGALBAYAR']); ?></span>
                </div>

                <!-- Row 1: Tanggal, Pengunaan, IDPEL, Nama -->
                <div class="ct-row">
                    <div class="ct-field">
                        <small><?= $statusText === 'PENAGIHAN' ? 'Jatuh Tempo' : 'Tanggal Bayar'; ?></small>
                        <strong><?= htmlspecialchars((string)$data1['TANGGALBAYAR']); ?></strong>
                        <?php if ($ctJatuhTempoBaris !== '') { ?>
                            <span class="ct-badge" style="background:#0dcaf0;color:#000;display:inline-block;margin-top:4px;">Jatuh tempo: <?= htmlspecialchars($ctJatuhTempoBaris); ?></span>
                        <?php } ?>
                        <span class="ct-badge" style="background:#64748b;color:#fff;display:inline-block;margin-top:4px;">Tipe Tempo: <?= htmlspecialchars($customertransTipeTempoLabel); ?></span>
                    </div>
                    <div class="ct-field">
                        <small>Pengunaan</small>
                        <strong><?= htmlspecialchars((string)$data1['PENGUNAAN']); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>IDPEL</small>
                        <strong><?= htmlspecialchars((string)$data1['IDPEL']); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Nama</small>
                        <strong><?= htmlspecialchars((string)$data1['NAMA']); ?></strong>
                    </div>
                </div>

                <!-- Row 2: Paket, Harga, Pemilik -->
                <div class="ct-row">
                    <div class="ct-field">
                        <small>Paket</small>
                        <strong><?= htmlspecialchars((string)$data1['PAKET']); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Harga</small>
                        <strong class="ct-harga">Rp <?= number_format($displayHarga1, 0, ',', '.'); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Pemilik</small>
                        <strong><?= htmlspecialchars((string)$data1['PEMILIK']); ?></strong>
                    </div>
                </div>

                <!-- Row 3: Metode Bayar, Bukti, CEK -->
                <div class="ct-row">
                    <div class="ct-field">
                        <small>Metode Bayar</small>
                        <strong><?= htmlspecialchars($metodeLabel); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Bukti / REF</small>
                        <strong><?= htmlspecialchars($buktiRaw ?: '-'); ?></strong>
                        <?php if ($buktiUrl !== '' && $ctBisaLihatBukti): ?>
                            <a href="<?= htmlspecialchars($buktiUrl); ?>" target="_blank" rel="noopener">
                                <img src="<?= htmlspecialchars($buktiUrl); ?>" alt="Bukti" class="ct-bukti-img" onerror="this.style.display='none'">
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="ct-field">
                        <small>CEK</small>
                        <strong><?= htmlspecialchars((string)($data1['CEK'] ?? '-')); ?></strong>
                    </div>
                </div>

                <!-- Row 4: Fee & Payment Method (jika ada) -->
                <?php if ($hasExtra): ?>
                <div class="ct-row">
                    <div class="ct-field">
                        <small>Payment Method</small>
                        <strong><?= htmlspecialchars((string)($data1['payment_method'] ?? '-')); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Harga Gross</small>
                        <strong class="ct-gross">Rp <?= number_format((float)($data1['harga_gross'] ?? 0), 0, ',', '.'); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Fee Merchant</small>
                        <strong class="ct-fee">Rp <?= number_format((float)($data1['fee_merchant'] ?? 0), 0, ',', '.'); ?></strong>
                    </div>
                    <div class="ct-field">
                        <small>Fee Customer</small>
                        <strong class="ct-fee">Rp <?= number_format((float)($data1['fee_customer'] ?? 0), 0, ',', '.'); ?></strong>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="ct-empty">Belum ada riwayat transaksi untuk pelanggan ini.</div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.2.1.slim.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.12.9/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script>
    (function () {
        try {
            var parentHtml = window.parent && window.parent.document ? window.parent.document.documentElement : null;
            if (!parentHtml) return;

            var html = document.documentElement;
            var body = document.body;
            var dark = parentHtml.classList.contains('app-theme-dark') || parentHtml.classList.contains('theme-dark');
            var terang = parentHtml.classList.contains('app-theme-terang') || parentHtml.classList.contains('theme-terang');

            if (dark) {
                html.classList.add('app-theme-dark');
                body.classList.add('app-theme-dark');
            } else if (terang) {
                html.classList.add('app-theme-terang');
                body.classList.add('app-theme-terang');
            }
        } catch (e) {
            /* abaikan jika parent tidak bisa diakses */
        }

        var reloadIn = 30;
        var countdownEl = document.getElementById('reloadCountdown');

        setInterval(function () {
            reloadIn -= 1;
            if (countdownEl) {
                countdownEl.textContent = String(Math.max(reloadIn, 0));
            }

            if (reloadIn <= 0) {
                window.location.reload();
            }
        }, 1000);
    })();
</script>
