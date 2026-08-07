<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Portal_setting', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pengaturan Halaman Pelanggan.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once 'portal_links_helper.php';

// Isi Pengaturan Halaman Pelanggan (nama product, logo, FAQ/Refund/S&K/Kontak)
// selalu milik akun OWNER (brand), bukan per-assistant -- $ceknama sudah
// diresolve ke USERNAME owner baik saat owner maupun assistant-nya yang login
// (lihat cek-sesi.php).
$portal_username = $ceknama;
$portal_links = get_portal_links($portal_username);

$feedback = '';
$feedback_class = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_portal_links'])) {
    // Nama Product & FAQ/dst sengaja dipisah jadi 2 <form> beda di halaman (biar
    // "Simpan Nama Product" tidak perlu submit textarea FAQ yang panjang2 juga).
    // Karena itu, field yang TIDAK ikut ter-submit (bukan bagian form yang
    // ditekan) fallback ke nilai TERSIMPAN saat ini ($portal_links), BUKAN string
    // kosong -- supaya submit salah satu form tidak menghapus isi form yang lain.
    $input = [];
    foreach (portal_links_defaults() as $field_key => $field_default) {
        $input[$field_key] = $_POST[$field_key] ?? $portal_links[$field_key];
    }

    if (save_portal_links($portal_username, $input)) {
        $feedback = 'Pengaturan Halaman Pelanggan berhasil disimpan.';
        $feedback_class = 'success';
        $portal_links = get_portal_links($portal_username);
    } else {
        $feedback = 'Gagal menyimpan Pengaturan Halaman Pelanggan. Periksa izin folder settings/.';
        $feedback_class = 'danger';
    }
}

// Logo halaman login pelanggan -- file SAMA dengan logo akun CRM (dokumen/logo/
// profile-{username}.png), supaya konsisten dgn yg sudah dibaca broadband/
// portallogin.php di mode brand. Upload/hapus lewat form yg sama dgn user.php
// (upload.php / proses/hapus_logo.php), redirect balik ke sini.
$portal_logo_safe_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $portal_username);
$portal_logo_file = __DIR__ . "/../../dokumen/logo/profile-{$portal_logo_safe_username}.png";
$portal_logo_url = file_exists($portal_logo_file) ? "/dokumen/logo/profile-{$portal_logo_safe_username}.png" : '';

$portal_domain = trim((string)($config['URL'] ?? ''));
if ($portal_domain !== '' && stripos($portal_domain, 'http://') !== 0 && stripos($portal_domain, 'https://') !== 0) {
    $portal_domain = 'https://' . ltrim($portal_domain, '/');
}
$portal_bayar_link = rtrim($portal_domain, '/') . '/crm/billing/broadband/portallogin.php?brand=' . rawurlencode($portal_username);
?>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="row">
        <div class="col-12">

            <?php if ($feedback !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback_class) ?>"><?= htmlspecialchars($feedback) ?></div>
            <?php endif; ?>

            <!-- Card: Link Portal Bayar -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Link Portal Bayar Pelanggan</h5>
                    <p class="text-sm text-muted mb-0">Link ini yang dibagikan ke pelanggan untuk login ke portal tagihan/pembayaran mereka (sudah otomatis memakai logo &amp; identitas akun Anda).</p>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <input type="text" class="form-control" id="portalBayarLink" value="<?= htmlspecialchars($portal_bayar_link) ?>" readonly onclick="this.select()">
                        <button class="btn btn-outline-secondary" type="button" onclick="portalSettingCopyLink()"><i class="fas fa-copy me-1"></i>Salin</button>
                        <a href="<?= htmlspecialchars($portal_bayar_link) ?>" target="_blank" class="btn btn-primary"><i class="fas fa-arrow-up-right-from-square me-1"></i>Buka</a>
                    </div>
                </div>
            </div>

            <!-- Card: Identitas Halaman Login Pelanggan (nama product + logo) -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Identitas Halaman Login Pelanggan</h5>
                    <p class="text-sm text-muted mb-0">Nama product &amp; logo yang tampil di halaman login portal bayar pelanggan Anda (link di atas). Kalau nama product dikosongkan, sistem akan pakai username akun Anda apa adanya sebagai fallback.</p>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <form method="post" action="">
                                <label for="product_name" class="form-label">Nama Product yang Ditampilkan</label>
                                <input type="text" class="form-control" id="product_name" name="product_name" maxlength="100" placeholder="Contoh: FiberQ Internet" value="<?= htmlspecialchars($portal_links['product_name']) ?>">
                                <div class="form-text">Ini yang dilihat pelanggan sebagai nama brand di halaman login (bukan nama teknis/username akun CRM Anda).</div>
                                <div class="mt-3">
                                    <label for="tagline" class="form-label">Tagline / Slogan</label>
                                    <input type="text" class="form-control" id="tagline" name="tagline" maxlength="200" placeholder="Contoh: Internet cepat, stabil, harga bersahabat" value="<?= htmlspecialchars($portal_links['tagline']) ?>">
                                    <div class="form-text">Tampil di bawah nama product di halaman login. Kosongkan untuk pakai tagline default sistem.</div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm mt-2" name="save_portal_links">Simpan</button>
                            </form>
                        </div>
                        <div class="col-md-6 text-center">
                            <label class="form-label d-block">Logo</label>
                            <?php if ($AKSES != 'ASSISTANT'): ?>
                                <div data-perm="btn_portal_logo">
                                <label for="portalLogoFileInput" style="cursor: pointer;">
                                    <?php if ($portal_logo_url !== ''): ?>
                                        <img src="<?= htmlspecialchars($portal_logo_url) ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 100px;">
                                    <?php else: ?>
                                        <div class="text-muted small border rounded p-3">Belum ada logo</div>
                                    <?php endif; ?>
                                    <div class="mt-1 text-muted small">📷 Klik untuk upload/ganti logo (PNG/JPG)</div>
                                </label>
                                <form action="upload.php" method="post" enctype="multipart/form-data" style="display: none;">
                                    <input type="hidden" name="redirect_to" value="portal_setting.php">
                                    <input type="file" name="profile_picture" id="portalLogoFileInput" accept="image/png,image/jpeg" onchange="this.form.submit()">
                                </form>
                                <?php if ($portal_logo_url !== ''): ?>
                                    <form action="proses/hapus_logo.php" method="post" class="mt-2">
                                        <input type="hidden" name="redirect_to" value="../portal_setting.php">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>Hapus Logo</button>
                                    </form>
                                <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php if ($portal_logo_url !== ''): ?>
                                    <img src="<?= htmlspecialchars($portal_logo_url) ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 100px;">
                                <?php else: ?>
                                    <div class="text-muted small border rounded p-3">Belum ada logo</div>
                                <?php endif; ?>
                                <div class="mt-1 text-muted small">Cuma akun utama yang bisa ubah logo.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: 3 Kartu Fitur di panel kiri halaman login -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">3 Kartu Fitur Halaman Login</h5>
                    <p class="text-sm text-muted mb-0">Judul &amp; deskripsi 3 kartu fitur yang tampil di panel kiri halaman login pelanggan Anda (ikon tetap, tidak bisa diubah). Kosongkan judul salah satu kartu untuk pakai teks default sistem.</p>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-bolt text-warning"></i> <b>Kartu 1</b></div>
                                    <label for="feature1_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature1_title" name="feature1_title" maxlength="40" placeholder="Login Cepat" value="<?= htmlspecialchars($portal_links['feature1_title']) ?>">
                                    <label for="feature1_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature1_text" name="feature1_text" rows="3" maxlength="150" placeholder="Pilih login dengan OTP WhatsApp atau password pelanggan tanpa alur yang rumit."><?= htmlspecialchars($portal_links['feature1_text']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-receipt text-info"></i> <b>Kartu 2</b></div>
                                    <label for="feature2_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature2_title" name="feature2_title" maxlength="40" placeholder="Akses Tagihan" value="<?= htmlspecialchars($portal_links['feature2_title']) ?>">
                                    <label for="feature2_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature2_text" name="feature2_text" rows="3" maxlength="150" placeholder="Masuk ke portal pelanggan untuk melihat status layanan dan informasi pembayaran terbaru."><?= htmlspecialchars($portal_links['feature2_text']) ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded p-3 h-100">
                                    <div class="mb-2"><i class="fas fa-headset text-success"></i> <b>Kartu 3</b></div>
                                    <label for="feature3_title" class="form-label small">Judul</label>
                                    <input type="text" class="form-control form-control-sm mb-2" id="feature3_title" name="feature3_title" maxlength="40" placeholder="Bantuan Admin" value="<?= htmlspecialchars($portal_links['feature3_title']) ?>">
                                    <label for="feature3_text" class="form-label small">Deskripsi</label>
                                    <textarea class="form-control form-control-sm" id="feature3_text" name="feature3_text" rows="3" maxlength="150" placeholder="Hubungi customer service langsung dari halaman ini jika mengalami kendala saat login."><?= htmlspecialchars($portal_links['feature3_text']) ?></textarea>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-3" name="save_portal_links">Simpan 3 Kartu Fitur</button>
                    </form>
                </div>
            </div>

            <form method="post" action="">
                <!-- Card: Pengaturan Ketentuan -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">Pengaturan Ketentuan (FAQ, Refund Policy, Syarat &amp; Ketentuan, Kontak)</h5>
                        <p class="text-sm text-muted mb-0">Isi teks di bawah ini (bukan link) -- akan ditampilkan langsung di modal "Kebijakan &amp; Bantuan" pada halaman login portal bayar pelanggan Anda (link di atas). Kosongkan yang tidak ingin ditampilkan; bagian itu otomatis disembunyikan.</p>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label for="faq_text" class="form-label">FAQ</label>
                            <textarea class="form-control" id="faq_text" name="faq_text" rows="6" placeholder="Contoh:&#10;Q: Bagaimana cara membayar tagihan?&#10;A: Login ke portal ini, lalu buka menu Tagihan dan pilih metode pembayaran (transfer bank/QRIS/e-wallet).&#10;&#10;Q: Kenapa internet saya tiba-tiba mati?&#10;A: Biasanya karena tagihan belum dibayar (isolir otomatis) atau ada gangguan jaringan. Cek status di portal atau hubungi CS kami."><?= htmlspecialchars($portal_links['faq_text']) ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="refund_text" class="form-label">Refund Policy</label>
                            <textarea class="form-control" id="refund_text" name="refund_text" rows="6" placeholder="Contoh:&#10;Pengembalian dana (refund) dapat diajukan maksimal 7 hari kalender setelah pembayaran, dengan syarat layanan belum diaktifkan/digunakan. Proses refund akan dilakukan maksimal 3x24 jam kerja ke rekening/e-wallet yang sama dengan sumber pembayaran. Biaya admin payment gateway (jika ada) tidak dapat dikembalikan."><?= htmlspecialchars($portal_links['refund_text']) ?></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="terms_text" class="form-label">Syarat &amp; Ketentuan (Term &amp; Condition)</label>
                            <textarea class="form-control" id="terms_text" name="terms_text" rows="6" placeholder="Contoh:&#10;1. Pelanggan wajib membayar tagihan paling lambat pada tanggal jatuh tempo yang tertera di portal.&#10;2. Keterlambatan pembayaran dapat mengakibatkan pemutusan sementara layanan (isolir) tanpa pemberitahuan lebih lanjut.&#10;3. Perubahan paket/harga akan diinformasikan melalui WhatsApp/portal pelanggan minimal 7 hari sebelum berlaku."><?= htmlspecialchars($portal_links['terms_text']) ?></textarea>
                        </div>
                        <div class="mb-0">
                            <label for="contact_text" class="form-label">Kontak</label>
                            <textarea class="form-control" id="contact_text" name="contact_text" rows="5" placeholder="Contoh:&#10;WhatsApp CS: 0812-3456-7890&#10;Email: cs@domainanda.com&#10;Alamat Kantor: Jl. Merdeka No. 1, Kota Anda&#10;Jam Operasional: Senin-Sabtu, 08.00 - 20.00 WIB"><?= htmlspecialchars($portal_links['contact_text']) ?></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" name="save_portal_links">Simpan Pengaturan Ketentuan</button>
            </form>

        </div>
    </div>
</div>

<script>
function portalSettingCopyLink() {
    var el = document.getElementById('portalBayarLink');
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
