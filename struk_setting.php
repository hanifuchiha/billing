<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Struk_setting', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Pengaturan Struk.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once 'struk_helper.php';

$struk_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$struk_settings = get_struk_settings($struk_settings_username);

$feedback = '';
$feedback_class = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_struk_settings'])) {
    $input = [
        'kop_nama_usaha'  => $_POST['kop_nama_usaha'] ?? '',
        'kop_tagline'     => $_POST['kop_tagline'] ?? '',
        'kop_alamat'      => $_POST['kop_alamat'] ?? '',
        'kop_no_cs'       => $_POST['kop_no_cs'] ?? '',
        'kop_footer_note' => $_POST['kop_footer_note'] ?? '',
        'print_enabled'   => isset($_POST['print_enabled']),
        'pdf_enabled'     => isset($_POST['pdf_enabled']),
        'paper_size'      => $_POST['paper_size'] ?? '58mm',
        'logo_enabled'    => isset($_POST['logo_enabled']),
    ];

    if (trim($input['kop_nama_usaha']) === '') {
        $feedback = 'Nama usaha wajib diisi.';
        $feedback_class = 'danger';
    } else {
        $saved = save_struk_settings($struk_settings_username, $input);
        if ($saved) {
            $feedback = 'Pengaturan struk berhasil disimpan.';
            $feedback_class = 'success';
            $struk_settings = get_struk_settings($struk_settings_username);
        } else {
            $feedback = 'Gagal menyimpan pengaturan struk. Periksa izin folder settings/.';
            $feedback_class = 'danger';
        }
    }
}
?>

<div class="container-fluid py-4 px-3 px-md-4">
    <div class="row">
        <div class="col-12 col-xl-9">

            <?php if ($feedback !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($feedback_class) ?>"><?= htmlspecialchars($feedback) ?></div>
            <?php endif; ?>

            <?php
            $struk_logo_safe_username = struk_settings_safe_username($struk_settings_username);
            $struk_logo_file = 'uploads/profile-' . $struk_logo_safe_username . '.png';
            $struk_logo_exists = $struk_logo_safe_username !== '' && file_exists($struk_logo_file);
            ?>
            <!-- Card: Logo Usaha -->
            <div class="card shadow-sm mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Logo Usaha</h5>
                    <p class="text-sm text-muted mb-0">Logo ini otomatis tampil di kop struk, baik saat di-print langsung maupun di-download sebagai PDF.</p>
                </div>
                <div class="card-body text-center">
                    <?php if ($AKSES != 'ASSISTANT') { ?>
                        <label for="strukLogoInput" style="cursor: pointer;">
                            <?php if ($struk_logo_exists): ?>
                                <img src="<?= htmlspecialchars($struk_logo_file) ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 120px;">
                            <?php else: ?>
                                <div class="text-muted" style="font-size: 48px;"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                            <div class="mt-2 text-muted">📷 Klik untuk upload logo (PNG/JPG)</div>
                        </label>
                        <form action="upload.php" method="post" enctype="multipart/form-data" style="display: none;">
                            <input type="hidden" name="redirect_to" value="struk_setting.php">
                            <input type="file" name="profile_picture" id="strukLogoInput" accept="image/png,image/jpeg" onchange="this.form.submit()">
                        </form>
                        <?php if ($struk_logo_exists): ?>
                            <form action="proses/hapus_logo.php" method="post" class="mt-2">
                                <input type="hidden" name="redirect_to" value="../struk_setting.php">
                                <button type="submit" class="btn btn-danger btn-sm" data-perm="btn_struk_logo">
                                    <i class="fas fa-trash me-1"></i>Hapus Logo
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php } else { ?>
                        <?php if ($struk_logo_exists): ?>
                            <img src="<?= htmlspecialchars($struk_logo_file) ?>?v=<?= time() ?>" class="img-fluid" alt="Logo" style="max-height: 120px;">
                        <?php else: ?>
                            <div class="text-muted">Belum ada logo.</div>
                        <?php endif; ?>
                    <?php } ?>
                </div>
            </div>

            <form method="post" action="">

                <!-- Card: Tampilan Logo di Struk -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">Tampilan Logo di Struk</h5>
                        <p class="text-sm text-muted mb-0">Atur apakah logo usaha yang sudah diupload di atas ikut ditampilkan di kop struk.</p>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="logo_enabled" name="logo_enabled" <?= $struk_settings['logo_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="logo_enabled">Tampilkan Logo di Struk</label>
                            <div class="form-text">Jika dinonaktifkan, struk akan dicetak tanpa logo walaupun logo sudah diupload.</div>
                        </div>
                    </div>
                </div>

                <!-- Card: Kop Struk -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">Pengaturan Kop Struk</h5>
                        <p class="text-sm text-muted mb-0">Informasi ini tampil di bagian atas &amp; bawah struk transaksi, baik saat di-print langsung maupun di-download sebagai PDF.</p>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="kop_nama_usaha" class="form-label">Nama Usaha</label>
                                <input type="text" class="form-control" id="kop_nama_usaha" name="kop_nama_usaha" value="<?= htmlspecialchars($struk_settings['kop_nama_usaha']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="kop_tagline" class="form-label">Tagline (opsional)</label>
                                <input type="text" class="form-control" id="kop_tagline" name="kop_tagline" value="<?= htmlspecialchars($struk_settings['kop_tagline']) ?>" placeholder="Contoh: Terpercaya & Stabil">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label for="kop_alamat" class="form-label">Alamat Usaha</label>
                            <input type="text" class="form-control" id="kop_alamat" name="kop_alamat" value="<?= htmlspecialchars($struk_settings['kop_alamat']) ?>" placeholder="Contoh: Jl. Merdeka No. 1, Kota Anda">
                        </div>

                        <div class="mt-3">
                            <label for="kop_no_cs" class="form-label">No. CS / WhatsApp</label>
                            <input type="text" class="form-control" id="kop_no_cs" name="kop_no_cs" value="<?= htmlspecialchars($struk_settings['kop_no_cs']) ?>" placeholder="Contoh: 0812-3456-7890">
                        </div>

                        <div class="mt-3 mb-0">
                            <label for="kop_footer_note" class="form-label">Catatan Footer Struk</label>
                            <textarea class="form-control" id="kop_footer_note" name="kop_footer_note" rows="3"><?= htmlspecialchars($struk_settings['kop_footer_note']) ?></textarea>
                            <div class="form-text">Contoh: "Terima kasih telah bertransaksi! Simpan struk ini sebagai bukti pembayaran."</div>
                        </div>
                    </div>
                </div>

                <!-- Card: Print & Download PDF -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header pb-0">
                        <h5 class="mb-0">Pengaturan Print &amp; Download PDF Struk</h5>
                        <p class="text-sm text-muted mb-0">Atur tombol apa saja yang muncul di halaman Transaksi, dan ukuran kertas struk.</p>
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="print_enabled" name="print_enabled" <?= $struk_settings['print_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="print_enabled">Aktifkan Tombol "Print Struk" (print langsung dari browser)</label>
                            <div class="form-text">Membuka halaman struk dan otomatis memunculkan dialog print bawaan browser, langsung ke printer (thermal/biasa) tanpa perlu download file dulu.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="pdf_enabled" name="pdf_enabled" <?= $struk_settings['pdf_enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pdf_enabled">Aktifkan Tombol "Download PDF"</label>
                            <div class="form-text">Mengunduh struk transaksi dalam bentuk file PDF.</div>
                        </div>

                        <div class="mt-3 mb-0">
                            <label for="paper_size" class="form-label">Ukuran Kertas Struk</label>
                            <select class="form-select" id="paper_size" name="paper_size" style="max-width: 320px;">
                                <option value="58mm" <?= $struk_settings['paper_size'] === '58mm' ? 'selected' : '' ?>>Thermal 58mm</option>
                                <option value="80mm" <?= $struk_settings['paper_size'] === '80mm' ? 'selected' : '' ?>>Thermal 80mm</option>
                                <option value="a4" <?= $struk_settings['paper_size'] === 'a4' ? 'selected' : '' ?>>A4</option>
                            </select>
                            <div class="form-text">Dipakai untuk print langsung maupun PDF struk. Pilih sesuai printer yang dipakai (printer struk thermal umumnya 58mm atau 80mm).</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" name="save_struk_settings">Simpan Pengaturan Struk</button>
            </form>

        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
