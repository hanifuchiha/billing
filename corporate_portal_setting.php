<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Portal_Setting', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pengaturan Portal Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';

// Sama pola dgn portal_setting.php: selalu milik akun OWNER (brand), bukan
// per-assistant -- $ceknama sudah diresolve ke USERNAME owner baik saat
// owner maupun assistant-nya yang login (lihat cek-sesi.php).
$portal_username = $ceknama;
$portal_links = corporatePortalGet($portal_username);

$feedback = '';
$feedback_class = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_corporate_portal'])) {
    // Nama Product & FAQ/dst sengaja dipisah jadi beberapa <form> beda (biar
    // "Simpan" satu form tidak menghapus isi form lain yang tidak ikut submit).
    $input = [];
    foreach (corporatePortalDefaults() as $field_key => $field_default) {
        $input[$field_key] = $_POST[$field_key] ?? $portal_links[$field_key];
    }

    if (corporatePortalSave($portal_username, $input)) {
        $feedback = 'Pengaturan Portal Corporate berhasil disimpan.';
        $feedback_class = 'success';
        $portal_links = corporatePortalGet($portal_username);
    } else {
        $feedback = 'Gagal menyimpan Pengaturan Portal Corporate. Periksa izin folder settings/.';
        $feedback_class = 'danger';
    }
}

// Logo halaman login Portal Corporate -- SAMA dgn logo akun CRM (dokumen/
// logo/profile-{username}.png), konsisten dgn portal_setting.php (satu logo
// brand dipakai di semua halaman publik akun ini).
$portal_logo_safe_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $portal_username);
$portal_logo_file = __DIR__ . "/../../dokumen/logo/profile-{$portal_logo_safe_username}.png";
$portal_logo_url = file_exists($portal_logo_file) ? "/dokumen/logo/profile-{$portal_logo_safe_username}.png" : '';

$portal_domain = trim((string) ($config['URL'] ?? ''));
if ($portal_domain !== '' && stripos($portal_domain, 'http://') !== 0 && stripos($portal_domain, 'https://') !== 0) {
    $portal_domain = 'https://' . ltrim($portal_domain, '/');
}
$corporate_portal_link = rtrim($portal_domain, '/') . '/crm/billing/corporate_portal/login.php?brand=' . rawurlencode($portal_username);
?>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="row">
        <div class="col-12">

            <?php if ($feedback !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback_class) ?>"><?= htmlspecialchars($feedback) ?></div>
            <?php endif; ?>

            <!-- Card: Link Portal Corporate -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Link Portal Corporate</h5>
                    <p class="text-sm text-muted mb-0">Link ini yang dibagikan ke Customer Corporate untuk login melihat data perusahaan, layanan, dan invoice mereka (sudah otomatis memakai logo &amp; identitas akun Anda). Kredensial login tiap perusahaan diatur di menu <a href="corporate.php">Customer Corporate</a>.</p>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <input type="text" class="form-control" id="corpPortalLink" value="<?= htmlspecialchars($corporate_portal_link) ?>" readonly onclick="this.select()">
                        <button class="btn btn-outline-secondary" type="button" onclick="corpPortalSettingCopyLink()"><i class="fas fa-copy me-1"></i>Salin</button>
                        <a href="<?= htmlspecialchars($corporate_portal_link) ?>" target="_blank" class="btn btn-primary"><i class="fas fa-arrow-up-right-from-square me-1"></i>Buka</a>
                    </div>
                </div>
            </div>

            <!-- Card: Identitas Halaman Login Portal Corporate -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Identitas Halaman Login Portal Corporate</h5>
                    <p class="text-sm text-muted mb-0">Nama product &amp; logo yang tampil di halaman login Portal Corporate (link di atas). Kalau nama product dikosongkan, sistem akan pakai nama perusahaan Anda (config) sebagai fallback.</p>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <form method="post" action="">
                                <label for="product_name" class="form-label">Nama Product yang Ditampilkan</label>
                                <input type="text" class="form-control" id="product_name" name="product_name" maxlength="100" placeholder="Contoh: FiberQ Business" value="<?= htmlspecialchars($portal_links['product_name']) ?>">
                                <div class="form-text">Ini yang dilihat PIC perusahaan sebagai nama brand di halaman login Portal Corporate.</div>
                                <div class="mt-3">
                                    <label for="tagline" class="form-label">Tagline / Slogan</label>
                                    <input type="text" class="form-control" id="tagline" name="tagline" maxlength="200" placeholder="Contoh: Solusi Internet Dedicated untuk Bisnis Anda" value="<?= htmlspecialchars($portal_links['tagline']) ?>">
                                    <div class="form-text">Tampil di bawah nama product di halaman login. Kosongkan untuk pakai tagline default sistem.</div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm mt-2" name="save_corporate_portal">Simpan</button>
                            </form>
                        </div>
                        <div class="col-md-6 text-center">
                            <label class="form-label d-block">Logo</label>
                            <?php if ($portal_logo_url !== ''): ?>
                                <img src="<?= htmlspecialchars($portal_logo_url) ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 100px;">
                            <?php else: ?>
                                <div class="text-muted small border rounded p-3">Belum ada logo</div>
                            <?php endif; ?>
                            <div class="mt-1 text-muted small">Logo sama dengan Pengaturan Halaman Pelanggan (menu <a href="portal_setting.php">Pengaturan Halaman Pelanggan</a>) -- ubah logo di sana, otomatis ikut dipakai di sini.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: 3 Kartu Fitur panel kiri halaman login -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">3 Kartu Fitur Halaman Login</h5>
                    <p class="text-sm text-muted mb-0">Judul &amp; deskripsi 3 kartu fitur yang tampil di panel kiri halaman login Portal Corporate. Kosongkan judul salah satu kartu untuk pakai teks default sistem.</p>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-building text-warning"></i> <b>Kartu 1</b></div>
                                    <label for="feature1_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature1_title" name="feature1_title" maxlength="40" placeholder="Data Perusahaan Terpusat" value="<?= htmlspecialchars($portal_links['feature1_title']) ?>">
                                    <label for="feature1_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature1_text" name="feature1_text" rows="3" maxlength="150" placeholder="Lihat data perusahaan, PIC, dan kontrak Anda dalam satu halaman."><?= htmlspecialchars($portal_links['feature1_text']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-network-wired text-info"></i> <b>Kartu 2</b></div>
                                    <label for="feature2_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature2_title" name="feature2_title" maxlength="40" placeholder="Pantau Layanan" value="<?= htmlspecialchars($portal_links['feature2_title']) ?>">
                                    <label for="feature2_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature2_text" name="feature2_text" rows="3" maxlength="150" placeholder="Cek status koneksi tiap layanan (Internet Dedicated, VPN, dst) secara real-time."><?= htmlspecialchars($portal_links['feature2_text']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-file-invoice-dollar text-success"></i> <b>Kartu 3</b></div>
                                    <label for="feature3_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature3_title" name="feature3_title" maxlength="40" placeholder="Invoice &amp; Pembayaran" value="<?= htmlspecialchars($portal_links['feature3_title']) ?>">
                                    <label for="feature3_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature3_text" name="feature3_text" rows="3" maxlength="150" placeholder="Lihat riwayat invoice, status pembayaran, dan cetak invoice langsung dari portal."><?= htmlspecialchars($portal_links['feature3_text']) ?></textarea>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-3" name="save_corporate_portal">Simpan 3 Kartu Fitur</button>
                    </form>
                </div>
            </div>

            <form method="post" action="">
                <!-- Card: Pengaturan Ketentuan -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">Pengaturan Ketentuan (FAQ, Refund Policy, Syarat &amp; Ketentuan, Kontak)</h5>
                        <p class="text-sm text-muted mb-0">Isi teks di bawah ini (bukan link) -- akan ditampilkan langsung di modal "Kebijakan &amp; Bantuan" pada halaman login Portal Corporate. Kosongkan yang tidak ingin ditampilkan; bagian itu otomatis disembunyikan.</p>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label for="faq_text" class="form-label">FAQ</label>
                            <textarea class="form-control" id="faq_text" name="faq_text" rows="6" placeholder="Contoh:&#10;Q: Bagaimana cara melihat invoice terbaru?&#10;A: Login ke portal ini, invoice ditampilkan lengkap dengan status pembayaran.&#10;&#10;Q: Bagaimana cara membayar invoice?&#10;A: Hubungi PIC Finance kami, pembayaran dicatat manual sesuai termin yang disepakati."><?= htmlspecialchars($portal_links['faq_text']) ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="refund_text" class="form-label">Refund Policy</label>
                            <textarea class="form-control" id="refund_text" name="refund_text" rows="6" placeholder="Contoh:&#10;Pengembalian dana korporat mengikuti ketentuan yang tercantum dalam kontrak kerja sama masing-masing perusahaan."><?= htmlspecialchars($portal_links['refund_text']) ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="terms_text" class="form-label">Syarat &amp; Ketentuan (Term &amp; Condition)</label>
                            <textarea class="form-control" id="terms_text" name="terms_text" rows="6" placeholder="Contoh:&#10;1. Pembayaran invoice mengikuti termin yang tercantum pada invoice (Net 7/14/30/60).&#10;2. Keterlambatan pembayaran dapat mempengaruhi kelanjutan layanan sesuai kesepakatan kontrak."><?= htmlspecialchars($portal_links['terms_text']) ?></textarea>
                        </div>
                        <div class="mb-0">
                            <label for="contact_text" class="form-label">Kontak</label>
                            <textarea class="form-control" id="contact_text" name="contact_text" rows="5" placeholder="Contoh:&#10;Account Manager: 0812-3456-7890&#10;Email Finance: finance@domainanda.com&#10;Jam Operasional: Senin-Jumat, 08.00 - 17.00 WIB"><?= htmlspecialchars($portal_links['contact_text']) ?></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" name="save_corporate_portal">Simpan Pengaturan Ketentuan</button>
            </form>

        </div>
    </div>
</div>

<script>
function corpPortalSettingCopyLink() {
    var el = document.getElementById('corpPortalLink');
    el.select();
    el.setSelectionRange(0, 99999);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value);
    } else {
        document.execCommand('copy');
    }
}
</script>

<?php require 'footer.php'; ?>
