
<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Mitra_accounts', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Mitra Accounts.</div></div>';
        require 'footer.php';
        exit;
    }
}


// --- AUTO ALTER TABLE: Tambah field kecamatan, kelurahan, rw, rt, jabatan jika belum ada ---
function ensure_column_exists($conn, $table, $column, $type) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD `$column` $type DEFAULT NULL");
    }
}
ensure_column_exists($conn, 'mitra', 'kabupaten', 'VARCHAR(100)');
ensure_column_exists($conn, 'mitra', 'kecamatan', 'VARCHAR(100)');
ensure_column_exists($conn, 'mitra', 'kelurahan', 'VARCHAR(100)');
ensure_column_exists($conn, 'mitra', 'rw', 'VARCHAR(10)');
ensure_column_exists($conn, 'mitra', 'rt', 'VARCHAR(10)');
ensure_column_exists($conn, 'mitra', 'jabatan', "ENUM('sales_regular','sales_internal','kepala_camat','kepala_lurah','kepala_rw','kepala_rt')");
 ?>
    <div class="container">
        
   
 <div class="card shadow p-4">
        <!-- Tombol Tambah Mitra -->
        <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#dataModal">
            Add Sales
        </button>

        <!-- Search -->
    
<!-- Tambahkan CSS & JS Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h4 class="mb-2">📋 Daftar Sales</h4>
        <input type="text" id="searchInput" class="form-control w-50" placeholder="Cari sales...">
       
    </div>

    <div class="table-responsive shadow-sm rounded">
        <table class="table table-bordered table-striped align-middle" id="dataTable">
            <thead >
                <tr>
                    <th style="width: 35%">Informasi Sales</th>
                    <th>Jabatan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // --- Penanganan edit mitra ---
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mode']) && $_POST['mode'] == 'edit') {
                    $id       = intval($_POST['id']);
                    $namaInput = trim($_POST['nama']);
                    if (preg_match('/\s/', $namaInput)) {
                        echo "<script>alert('Username tidak boleh mengandung spasi'); history.back();</script>";
                        exit;
                    }
                    $nama      = mysqli_real_escape_string($conn, $namaInput);
                    $alamat    = mysqli_real_escape_string($conn, $_POST['alamat']);
                    $ktp       = mysqli_real_escape_string($conn, $_POST['ktp']);
                    $server    = mysqli_real_escape_string($conn, $_POST['server']);
                    $saldo     = mysqli_real_escape_string($conn, $_POST['saldo']);
                    $email     = mysqli_real_escape_string($conn, $_POST['email']);
                    $password  = mysqli_real_escape_string($conn, $_POST['password']);
                    $whatsapp  = mysqli_real_escape_string($conn, $_POST['whatsapp']);
                    $norek     = mysqli_real_escape_string($conn, $_POST['norek']);
                    $namabank  = mysqli_real_escape_string($conn, $_POST['namabank']);
                    $akunbank  = mysqli_real_escape_string($conn, $_POST['akunbank']);
                    $kabupaten = isset($_POST['kabupaten']) ? mysqli_real_escape_string($conn, $_POST['kabupaten']) : NULL;
                    $kecamatan = isset($_POST['kecamatan']) ? mysqli_real_escape_string($conn, $_POST['kecamatan']) : NULL;
                    $kelurahan = isset($_POST['kelurahan']) ? mysqli_real_escape_string($conn, $_POST['kelurahan']) : NULL;
                    $rw        = isset($_POST['rw']) ? mysqli_real_escape_string($conn, $_POST['rw']) : NULL;
                    $rt        = isset($_POST['rt']) ? mysqli_real_escape_string($conn, $_POST['rt']) : NULL;
                    $jabatan   = isset($_POST['jabatan']) ? mysqli_real_escape_string($conn, $_POST['jabatan']) : NULL;

                    // Ambil foto lama
                    $foto_name = '';
                    $getFoto = $conn->query("SELECT foto FROM mitra WHERE id = $id");
                    if ($getFoto && $getFoto->num_rows > 0) {
                        $foto_name = $getFoto->fetch_assoc()['foto'];
                    }
                    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                        $foto_tmp = $_FILES['foto']['tmp_name'];
                        $foto_size = $_FILES['foto']['size'];
                        $maxSize = 2 * 1024 * 1024;
                        $allowedExt = ['jpg','jpeg','png'];
                        $allowedMime = ['image/jpeg','image/png'];
                        $upload_dir = __DIR__ . '/../../dokumen/mitra/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        if ($foto_size > $maxSize) die("❌ File terlalu besar. Maks 2MB.");
                        $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowedExt)) die("❌ Hanya JPG/JPEG atau PNG yang diperbolehkan.");
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $foto_tmp);
                        finfo_close($finfo);
                        if (!in_array($mime, $allowedMime)) die("❌ File bukan gambar JPG/PNG asli.");
                        $imginfo = @getimagesize($foto_tmp);
                        if ($imginfo === false) die("❌ Bukan file gambar yang valid.");
                        $foto_name = '/dokumen/mitra/' . time() . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
                        $img = @imagecreatefromstring(file_get_contents($foto_tmp));
                        if ($img === false) die("❌ Gagal memproses gambar.");
                        if ($ext === 'png') {
                            imagesavealpha($img, true);
                            imagepng($img, $upload_dir . basename($foto_name));
                        } else {
                            $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
                            imagefill($bg, 0, 0, imagecolorallocate($bg, 255,255,255));
                            imagecopy($bg, $img, 0,0,0,0, imagesx($img), imagesy($img));
                            imagejpeg($bg, $upload_dir . basename($foto_name), 85);
                            imagedestroy($bg);
                        }
                        imagedestroy($img);
                        chmod($upload_dir . basename($foto_name), 0644);
                    }
                    $query = "UPDATE mitra SET
                        nama='$nama', alamat='$alamat', ktp='$ktp', server='$server', saldo='$saldo',
                        foto='$foto_name', email='$email', password='$password', whatsapp='$whatsapp',
                        norek='$norek', namabank='$namabank', akunbank='$akunbank',
                        kabupaten=" . ($kabupaten ? "'$kabupaten'" : 'NULL') . ",
                        kecamatan=" . ($kecamatan ? "'$kecamatan'" : 'NULL') . ",
                        kelurahan=" . ($kelurahan ? "'$kelurahan'" : 'NULL') . ",
                        rw=" . ($rw ? "'$rw'" : 'NULL') . ",
                        rt=" . ($rt ? "'$rt'" : 'NULL') . ",
                        jabatan=" . ($jabatan ? "'$jabatan'" : 'NULL') . "
                        WHERE id=$id";
                    if (mysqli_query($conn, $query)) {
                        echo "<script>alert('Data sales berhasil diupdate'); window.location.href='mitraadmin.php';</script>";
                        exit;
                    } else {
                        echo "<script>alert('Gagal update data: " . mysqli_error($conn) . "'); history.back();</script>";
                        exit;
                    }
                }

                // --- Penanganan delete ---
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] == 'delete') {
                    $id = intval($_POST['id']);
                    $get = $conn->query("SELECT foto FROM mitra WHERE id = $id");
                    if ($get && $get->num_rows > 0) {
                        $foto = $get->fetch_assoc()['foto'];
                        if ($foto && file_exists($foto)) unlink($foto);
                    }
                    $conn->query("DELETE FROM mitra WHERE id = $id");
                }

                // --- Penanganan tambah mitra ---
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mode']) && $_POST['mode'] == 'add') {
                    $namaInput = trim($_POST['nama']);
                    if (preg_match('/\s/', $namaInput)) {
                        echo "<script>alert('Username tidak boleh mengandung spasi'); history.back();</script>";
                        exit;
                    }
                    $nama      = mysqli_real_escape_string($conn, $namaInput);
                    $alamat    = mysqli_real_escape_string($conn, $_POST['alamat']);
                    $ktp       = mysqli_real_escape_string($conn, $_POST['ktp']);
                    $server    = mysqli_real_escape_string($conn, $_POST['server']);
                    $saldo     = mysqli_real_escape_string($conn, $_POST['saldo']);
                    $email     = mysqli_real_escape_string($conn, $_POST['email']);
                    $password  = mysqli_real_escape_string($conn, $_POST['password']);
                    $whatsapp  = mysqli_real_escape_string($conn, $_POST['whatsapp']);
                    $norek     = mysqli_real_escape_string($conn, $_POST['norek']);
                    $namabank  = mysqli_real_escape_string($conn, $_POST['namabank']);
                    $akunbank  = mysqli_real_escape_string($conn, $_POST['akunbank']);
                    $kabupaten = isset($_POST['kabupaten']) ? mysqli_real_escape_string($conn, $_POST['kabupaten']) : NULL;
                    $kecamatan = isset($_POST['kecamatan']) ? mysqli_real_escape_string($conn, $_POST['kecamatan']) : NULL;
                    $kelurahan = isset($_POST['kelurahan']) ? mysqli_real_escape_string($conn, $_POST['kelurahan']) : NULL;
                    $rw        = isset($_POST['rw']) ? mysqli_real_escape_string($conn, $_POST['rw']) : NULL;
                    $rt        = isset($_POST['rt']) ? mysqli_real_escape_string($conn, $_POST['rt']) : NULL;
                    $jabatan   = isset($_POST['jabatan']) ? mysqli_real_escape_string($conn, $_POST['jabatan']) : NULL;

                    $foto_name = '';
                 if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $foto_tmp = $_FILES['foto']['tmp_name'];
    $foto_size = $_FILES['foto']['size'];

    // ====== Konfigurasi ======
    $maxSize = 2 * 1024 * 1024; // 2MB
    $allowedExt = ['jpg','jpeg','png'];
    $allowedMime = ['image/jpeg','image/png'];
    $upload_dir = __DIR__ . '/../../dokumen/mitra/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    // ====== Cek ukuran ======
    if ($foto_size > $maxSize) {
        die("❌ File terlalu besar. Maks 2MB.");
    }

    // ====== Ambil ekstensi dan validasi ======
    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt)) {
        die("❌ Hanya JPG/JPEG atau PNG yang diperbolehkan.");
    }

    // ====== Cek MIME ======
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $foto_tmp);
    finfo_close($finfo);
    if (!in_array($mime, $allowedMime)) {
        die("❌ File bukan gambar JPG/PNG asli.");
    }

    // ====== Validasi image ======
    $imginfo = @getimagesize($foto_tmp);
    if ($imginfo === false) {
        die("❌ Bukan file gambar yang valid.");
    }

    // ====== Nama file acak ======
    $foto_name = time() . '_' . bin2hex(random_bytes(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);

    // ====== Re-encode gambar (bersihkan payload) ======
    $img = @imagecreatefromstring(file_get_contents($foto_tmp));
    if ($img === false) die("❌ Gagal memproses gambar.");

    if ($ext === 'png') {
        imagesavealpha($img, true);
        imagepng($img, $upload_dir . $foto_name);
    } else {
        $bg = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255,255,255));
        imagecopy($bg, $img, 0,0,0,0, imagesx($img), imagesy($img));
        imagejpeg($bg, $upload_dir . $foto_name, 85);
        imagedestroy($bg);
    }
    imagedestroy($img);

    // ====== Set permission aman ======
    chmod($upload_dir . $foto_name, 0644);

    echo "✅ Upload berhasil: " . $foto_name;
}


                    $query = "INSERT INTO mitra (`nama`,`alamat`,`ktp`,`server`,`saldo`,`foto`,`email`,`password`,`whatsapp`,`norek`,`namabank`,`akunbank`, `kabupaten`, `kecamatan`, `kelurahan`, `rw`, `rt`, `jabatan`) 
                              VALUES ('$nama','$alamat','$ktp','$server','$saldo','/dokumen/mitra/$foto_name','$email','$password','$whatsapp','$norek','$namabank','$akunbank',
                              " . ($kabupaten ? "'$kabupaten'" : 'NULL') . ",
                              " . ($kecamatan ? "'$kecamatan'" : 'NULL') . ",
                              " . ($kelurahan ? "'$kelurahan'" : 'NULL') . ",
                              " . ($rw ? "'$rw'" : 'NULL') . ",
                              " . ($rt ? "'$rt'" : 'NULL') . ",
                              " . ($jabatan ? "'$jabatan'" : 'NULL') . ")";
                    if (mysqli_query($conn, $query)) {
                        echo "<script>alert('Data sales berhasil disimpan'); window.location.href='mitraadmin.php';</script>";
                    } else {
                        echo "<script>alert('Gagal menyimpan data: " . mysqli_error($conn) . "'); history.back();</script>";
                    }
                }

                // --- Penanganan topup saldo ---
                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mode']) && $_POST['mode'] == 'topup') {
                    $id = intval($_POST['id']);
                    $jumlah = intval($_POST['jumlah']);
                    if ($jumlah <= 0) {
                        echo "<script>alert('Jumlah tidak valid!'); history.back();</script>";
                        exit;
                    }
                    $update = $conn->query("UPDATE mitra SET saldo = saldo + $jumlah WHERE id = $id");
                    if ($update) {
                        echo "<script>alert('Topup berhasil'); window.location.href='mitraadmin.php';</script>";
                    } else {
                        echo "<script>alert('Gagal topup: " . mysqli_error($conn) . "'); history.back();</script>";
                    }
                }

                // --- Tampilkan data mitra ---
                 $query = "SELECT * FROM mitra WHERE `server` = '$ceknama'";
                $result = $conn->query($query);
                while ($row = $result->fetch_assoc()) { ?>
                    <tr>
                        <td>
                            <?php
                            $defaultFoto = 'https://img.icons8.com/external-itim2101-flat-itim2101/64/external-salesman-insurance-itim2101-flat-itim2101.png';
                            $fotoSrc = $defaultFoto;
                            if (!empty($row['foto'])) {
                                if (preg_match('/^https?:\/\//i', $row['foto'])) {
                                    $fotoSrc = $row['foto'];
                                } else {
                                    $fotoPath = __DIR__ . '/' . ltrim(trim($row['foto']), '/\\');
                                    $ext = strtolower(pathinfo($fotoPath, PATHINFO_EXTENSION));
                                    $allowedImageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                                    if (is_file($fotoPath) && in_array($ext, $allowedImageExt, true)) {
                                        $fotoSrc = $row['foto'];
                                    }
                                }
                            }
                            ?>
                            <div class="d-flex align-items-center">
                               <img src="<?= htmlspecialchars($fotoSrc) ?>" onerror="this.onerror=null;this.src='https://img.icons8.com/external-itim2101-flat-itim2101/64/external-salesman-insurance-itim2101-flat-itim2101.png';"
     class="rounded me-3" width="60" height="60" style="object-fit:cover">
                                <div>
                                    <strong><?= htmlspecialchars($row['nama']) ?></strong><br>
                                    <small>ID: <?= $row['id'] ?> | <?= $row['email'] ?></small><br>
                                    <small>📍 <?= $row['alamat'] ?></small><br>
                                    <small>📞 <?= $row['whatsapp'] ?></small><br>
                                    <strong>Kecamatan:</strong> <?= htmlspecialchars($row['kecamatan'] ?? '-') ?>,
                                    <strong>Kelurahan:</strong> <?= htmlspecialchars($row['kelurahan'] ?? '-') ?>,
                                    <strong>RW:</strong> <?= htmlspecialchars($row['rw'] ?? '-') ?>,
                                    <strong>RT:</strong> <?= htmlspecialchars($row['rt'] ?? '-') ?><br>
                                    <strong>Saldo:</strong> Rp<?= number_format($row['saldo'], 0, ',', '.') ?><br>
                                    <strong>Bank:</strong> <?= $row['namabank'] ?><br>
                                    <strong>No. Rek:</strong> <?= $row['norek'] ?><br>
                                    <strong>Pemilik:</strong> <?= $row['akunbank'] ?><br><?= htmlspecialchars($row['server']) ?>
                                </div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($row['jabatan'] ?? '') ?></td>
                        <td class="text-center">
                            <!-- Tombol Edit -->
                            <button type="button" class="btn btn-warning btn-sm mb-1" data-bs-toggle="modal" data-bs-target="#editModal<?= $row['id'] ?>">✏️ Edit</button>
                            <!-- Modal Edit Mitra -->
                            <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $row['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <form method="POST" enctype="multipart/form-data" class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editModalLabel<?= $row['id'] ?>">Edit Sales</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body row g-3">
                                            <div class="col-md-6">
                                                <label>Username</label>
                                                <input type="text" name="nama" class="form-control" value="<?= htmlspecialchars($row['nama']) ?>" required pattern="^\S+$" title="Username tidak boleh mengandung spasi" oninput="this.value=this.value.replace(/\s/g,'')">
                                                <label>Alamat</label>
                                                <input type="text" name="alamat" class="form-control" value="<?= htmlspecialchars($row['alamat']) ?>" required>
                                                <label>KTP</label>
                                                <input type="text" name="ktp" class="form-control" value="<?= htmlspecialchars($row['ktp']) ?>" required>
                                                <label>Produk (Server)</label>
                                                <input type="text" class="form-control" name="server" value="<?= htmlspecialchars($row['server']) ?>" readonly>
                                                <div class="mb-2">
                                                    <label class="mb-1">Provinsi</label>
                                                    <select id="provinsi_edit_<?= $row['id'] ?>" class="form-control mb-2" required><option value="">Pilih Provinsi</option></select>
                                                    <label class="mb-1">Kabupaten/Kota</label>
                                                    <select name="kabupaten" id="kabupaten_edit_<?= $row['id'] ?>" class="form-control mb-2" required>
                                                        <option value="<?= htmlspecialchars($row['kabupaten'] ?? '') ?>" selected><?= htmlspecialchars($row['kabupaten'] ?: 'Pilih Kabupaten/Kota') ?></option>
                                                    </select>
                                                    <label class="mb-1">Kecamatan</label>
                                                    <select name="kecamatan" id="kecamatan_edit_<?= $row['id'] ?>" class="form-control mb-2" required>
                                                        <option value="<?= htmlspecialchars($row['kecamatan'] ?? '') ?>" selected><?= htmlspecialchars($row['kecamatan'] ?: 'Pilih Kecamatan') ?></option>
                                                    </select>
                                                    <label class="mb-1">Kelurahan</label>
                                                    <select name="kelurahan" id="kelurahan_edit_<?= $row['id'] ?>" class="form-control" required>
                                                        <option value="<?= htmlspecialchars($row['kelurahan'] ?? '') ?>" selected><?= htmlspecialchars($row['kelurahan'] ?: 'Pilih Kelurahan') ?></option>
                                                    </select>
                                                </div>
                                                <label>RW</label>
                                                <input type="text" name="rw" class="form-control" value="<?= htmlspecialchars($row['rw']) ?>" placeholder="RW" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
                                                <label>RT</label>
                                                <input type="text" name="rt" class="form-control" value="<?= htmlspecialchars($row['rt']) ?>" placeholder="RT" oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
<script>
(function () {
    const modalId = 'editModal<?= $row['id'] ?>';
    const provinsiSelect = document.getElementById('provinsi_edit_<?= $row['id'] ?>');
    const kabupatenSelect = document.getElementById('kabupaten_edit_<?= $row['id'] ?>');
    const kecamatanSelect = document.getElementById('kecamatan_edit_<?= $row['id'] ?>');
    const kelurahanSelect = document.getElementById('kelurahan_edit_<?= $row['id'] ?>');

    const kabupatenVal = <?= json_encode($row['kabupaten'] ?? '') ?>;
    const kecamatanVal = <?= json_encode($row['kecamatan'] ?? '') ?>;
    const kelurahanVal = <?= json_encode($row['kelurahan'] ?? '') ?>;

    let provinsiList = [];
    let kabupatenList = [];
    let kecamatanList = [];

    function normalizeName(value) {
        return (value || '').toString().trim().toLowerCase();
    }

    async function fetchJSON(url) {
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Gagal request: ' + url);
        }
        return response.json();
    }

    function setOptions(selectEl, placeholder, items, selectedName) {
        const options = ['<option value="">' + placeholder + '</option>'];
        items.forEach(item => {
            options.push('<option value="' + item.name + '">' + item.name + '</option>');
        });
        if (selectedName && !items.some(item => item.name === selectedName)) {
            options.push('<option value="' + selectedName + '">' + selectedName + '</option>');
        }
        selectEl.innerHTML = options.join('');
        if (selectedName) {
            selectEl.value = selectedName;
        }
    }

    async function loadKabupatenByProvinsiName(provinsiName, selectedKabupaten) {
        const prov = provinsiList.find(item => item.name === provinsiName);
        if (!prov) {
            setOptions(kabupatenSelect, 'Pilih Kabupaten/Kota', [], selectedKabupaten || '');
            setOptions(kecamatanSelect, 'Pilih Kecamatan', [], kecamatanVal || '');
            setOptions(kelurahanSelect, 'Pilih Kelurahan', [], kelurahanVal || '');
            return;
        }

        kabupatenList = await fetchJSON('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/' + prov.id + '.json');
        setOptions(kabupatenSelect, 'Pilih Kabupaten/Kota', kabupatenList, selectedKabupaten || '');
    }

    async function loadKecamatanByKabupatenName(kabupatenName, selectedKecamatan) {
        const kab = kabupatenList.find(item => item.name === kabupatenName);
        if (!kab) {
            setOptions(kecamatanSelect, 'Pilih Kecamatan', [], selectedKecamatan || '');
            setOptions(kelurahanSelect, 'Pilih Kelurahan', [], kelurahanVal || '');
            return;
        }

        kecamatanList = await fetchJSON('https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + kab.id + '.json');
        setOptions(kecamatanSelect, 'Pilih Kecamatan', kecamatanList, selectedKecamatan || '');
    }

    async function loadKelurahanByKecamatanName(kecamatanName, selectedKelurahan) {
        const kec = kecamatanList.find(item => item.name === kecamatanName);
        if (!kec) {
            setOptions(kelurahanSelect, 'Pilih Kelurahan', [], selectedKelurahan || '');
            return;
        }

        const kelurahanList = await fetchJSON('https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + kec.id + '.json');
        setOptions(kelurahanSelect, 'Pilih Kelurahan', kelurahanList, selectedKelurahan || '');
    }

    async function findProvinsiNameByKabupatenName(kabupatenName) {
        const targetName = normalizeName(kabupatenName);
        if (!targetName) {
            return '';
        }

        for (const provinsi of provinsiList) {
            const regencies = await fetchJSON('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/' + provinsi.id + '.json');
            const match = regencies.find(item => normalizeName(item.name) === targetName);
            if (match) {
                return provinsi.name;
            }
        }

        return '';
    }

    async function initWilayahEdit() {
        try {
            provinsiList = await fetchJSON('https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json');
            let provinsiName = await findProvinsiNameByKabupatenName(kabupatenVal);

            setOptions(provinsiSelect, 'Pilih Provinsi', provinsiList, provinsiName);
            await loadKabupatenByProvinsiName(provinsiName, kabupatenVal);
            await loadKecamatanByKabupatenName(kabupatenVal, kecamatanVal);
            await loadKelurahanByKecamatanName(kecamatanVal, kelurahanVal);
        } catch (error) {
            setOptions(provinsiSelect, 'Pilih Provinsi', [], '');
            setOptions(kabupatenSelect, 'Pilih Kabupaten/Kota', [], kabupatenVal || '');
            setOptions(kecamatanSelect, 'Pilih Kecamatan', [], kecamatanVal || '');
            setOptions(kelurahanSelect, 'Pilih Kelurahan', [], kelurahanVal || '');
        }
    }

    provinsiSelect.addEventListener('change', async function () {
        await loadKabupatenByProvinsiName(this.value, '');
        setOptions(kecamatanSelect, 'Pilih Kecamatan', [], '');
        setOptions(kelurahanSelect, 'Pilih Kelurahan', [], '');
    });

    kabupatenSelect.addEventListener('change', async function () {
        await loadKecamatanByKabupatenName(this.value, '');
        setOptions(kelurahanSelect, 'Pilih Kelurahan', [], '');
    });

    kecamatanSelect.addEventListener('change', async function () {
        await loadKelurahanByKecamatanName(this.value, '');
    });

    const modalEl = document.getElementById(modalId);
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            initWilayahEdit();
        });
    }
})();
</script>
                                                <label>Jabatan</label>
                                                <select name="jabatan" class="form-control">
                                                    <option value="">- Pilih Jabatan -</option>
                                                    <option value="sales_regular" <?= $row['jabatan']=='sales_regular'?'selected':'' ?>>Sales Regular</option>
                                                    <option value="sales_internal" <?= $row['jabatan']=='sales_internal'?'selected':'' ?>>Sales Internal</option>
                                                    <option value="kepala_camat" <?= $row['jabatan']=='kepala_camat'?'selected':'' ?>>Kepala Camat</option>
                                                    <option value="kepala_lurah" <?= $row['jabatan']=='kepala_lurah'?'selected':'' ?>>Kepala Lurah</option>
                                                    <option value="kepala_rw" <?= $row['jabatan']=='kepala_rw'?'selected':'' ?>>Kepala RW</option>
                                                    <option value="kepala_rt" <?= $row['jabatan']=='kepala_rt'?'selected':'' ?>>Kepala RT</option>
                                                </select>
                                                <label>Foto</label>
                                                <input type="file" name="foto" class="form-control">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Email</label>
                                                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($row['email']) ?>" required>
                                                <label>Password</label>
                                                <input type="password" name="password" class="form-control" value="<?= htmlspecialchars($row['password']) ?>" required>
                                                <label>WhatsApp</label>
                                                <input type="text" name="whatsapp" class="form-control" value="<?= htmlspecialchars($row['whatsapp']) ?>" required>
                                                <label>Saldo</label>
                                                <input type="number" name="saldo" class="form-control" value="<?= htmlspecialchars($row['saldo']) ?>" required>
                                                <label>No. Rekening</label>
                                                <input type="number" name="norek" class="form-control" value="<?= htmlspecialchars($row['norek']) ?>" required>
                                                <label>Nama Bank</label>
                                                <input type="text" name="namabank" class="form-control" value="<?= htmlspecialchars($row['namabank']) ?>" required>
                                                <label>Nama Akun Bank</label>
                                                <input type="text" name="akunbank" class="form-control" value="<?= htmlspecialchars($row['akunbank']) ?>" required>
                                                <input type="hidden" name="mode" value="edit">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" class="btn btn-success">Simpan Perubahan</button>
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <!-- Tombol Topup -->
                            <button type="button" class="btn btn-success btn-sm mb-1" data-bs-toggle="modal" data-bs-target="#topupModal<?= $row['id'] ?>">
                                💰 Topup
                            </button>
                            <!-- Modal Topup -->
                            <div class="modal fade" id="topupModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="topupModalLabel<?= $row['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Topup Saldo - <?= htmlspecialchars($row['nama']) ?></h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <input type="hidden" name="mode" value="topup">
                                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                <label class="form-label">Jumlah Topup (Rp)</label>
                                                <input type="number" name="jumlah" class="form-control" required min="1">
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-primary">Topup</button>
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <!-- Tombol Delete -->
                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus sales ini?')" class="d-inline">
                                <input type="hidden" name="mode" value="delete">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">🗑 Hapus</button>
                            </form>
                            <?php
                ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Script Filter/Search -->
<script>
document.getElementById("searchInput").addEventListener("keyup", function() {
    const filter = this.value.toLowerCase();
    const rows = document.querySelectorAll("#dataTable tbody tr");
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? "" : "none";
    });
});
</script>

        
    </div>
   




















    <!-- Modal Add Mitra -->
    <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" enctype="multipart/form-data" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dataModalLabel">Tambah Sales</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label>Username</label>
                        <input type="text" name="nama" class="form-control" required pattern="^\S+$" title="Username tidak boleh mengandung spasi" oninput="this.value=this.value.replace(/\s/g,'')">

                        <label>Alamat</label>
                        <input type="text" name="alamat" class="form-control" required>

                        <label>KTP</label>
                        <input type="text" name="ktp" class="form-control" required>

                        <label>Produk (Server)</label>
                        <select required class="form-control" id="server" name="server" onchange="loadArea()">
                            <option selected value="<?php echo $ceknama ?>"><?php echo $ceknama ?></option>
                        </select>

                                                <div class="mb-2">
                                                    <label class="mb-1">Provinsi</label>
                                                    <select id="provinsi_add" class="form-control mb-2" required><option value="">Pilih Provinsi</option></select>
                                                    <label class="mb-1">Kabupaten/Kota</label>
                                                    <select name="kabupaten" id="kabupaten_add" class="form-control mb-2" required></select>
                                                    <label class="mb-1">Kecamatan</label>
                                                    <select name="kecamatan" id="kecamatan_add" class="form-control mb-2" required></select>
                                                    <label class="mb-1">Kelurahan</label>
                                                    <select name="kelurahan" id="kelurahan_add" class="form-control" required></select>
                                                </div>
                        <label>RW</label>
                        <input type="number" name="rw" class="form-control" placeholder="RW" min="1" required oninput="this.value=this.value.replace(/^0+(?=\d)/,'')">
                        <label>RT</label>
                        <input type="number" name="rt" class="form-control" placeholder="RT" min="1" required oninput="this.value=this.value.replace(/^0+(?=\d)/,'')">
<!-- Wilayah API JS untuk Tambah Mitra (EMSIFA) -->
<script>
// Ambil provinsi (rapi, langsung ke select)
let provinsiList = [];
let kabupatenList = [];
let kecamatanList = [];
fetch('https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json')
    .then(r=>r.json())
    .then(provinsiData => {
        provinsiList = provinsiData;
        const provSelect = document.getElementById('provinsi_add');
        provSelect.innerHTML = '<option value="">Pilih Provinsi</option>' + provinsiData.map(p=>`<option value="${p.name}">${p.name}</option>`).join('');
    });

document.getElementById('provinsi_add').addEventListener('change', function() {
    const provName = this.value;
    const prov = provinsiList.find(p=>p.name===provName);
    if (!prov) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/'+prov.id+'.json')
        .then(r=>r.json())
        .then(data=>{
            kabupatenList = data;
            const kabupatenSelect = document.getElementById('kabupaten_add');
            kabupatenSelect.innerHTML = '<option value="">Pilih Kabupaten/Kota</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
            document.getElementById('kecamatan_add').innerHTML = '<option value="">Pilih Kecamatan</option>';
            document.getElementById('kelurahan_add').innerHTML = '<option value="">Pilih Kelurahan</option>';
        });
});

document.getElementById('kabupaten_add').addEventListener('change', function() {
    const kabName = this.value;
    const kab = kabupatenList.find(k=>k.name===kabName);
    if (!kab) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + kab.id + '.json')
        .then(r=>r.json())
        .then(data=>{
            kecamatanList = data;
            const kecSelect = document.getElementById('kecamatan_add');
            kecSelect.innerHTML = '<option value="">Pilih Kecamatan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
            document.getElementById('kelurahan_add').innerHTML = '<option value="">Pilih Kelurahan</option>';
        });
});

document.getElementById('kecamatan_add').addEventListener('change', function() {
    const kecName = this.value;
    const kec = kecamatanList.find(k=>k.name===kecName);
    if (!kec) return;
    fetch('https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + kec.id + '.json')
        .then(r=>r.json())
        .then(data=>{
            const kelSelect = document.getElementById('kelurahan_add');
            kelSelect.innerHTML = '<option value="">Pilih Kelurahan</option>' + data.map(k=>`<option value="${k.name}">${k.name}</option>`).join('');
        });
});
</script>

                        <label>Jabatan</label>
                        <select name="jabatan" class="form-control">
                            <option value="">- Pilih Jabatan -</option>
                            <option value="sales_regular">Sales Regular</option>
                            <option value="sales_internal">Sales Internal</option>
                            <option value="kepala_camat">Kepala Camat</option>
                            <option value="kepala_lurah">Kepala Lurah</option>
                            <option value="kepala_rw">Kepala RW</option>
                            <option value="kepala_rt">Kepala RT</option>
                        </select>

                        <label>Foto</label>
                        <input type="file" name="foto" class="form-control">
                    </div>

                    <div class="col-md-6">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" required>

                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>

                        <label>WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control" required>
                        <label>Saldo awal</label>
                        <input type="number" name="saldo" class="form-control" required>

                        <label>No. Rekening</label>
                        <input type="number" name="norek" class="form-control" required>

                        <label>Nama Bank</label>
                        <input type="text" name="namabank" class="form-control" required>

                        <label>Nama Akun Bank</label>
                        <input type="text" name="akunbank" class="form-control" required>

                        <input hidden type="text" name="mode" class="form-control" value="add">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Simpan</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                </div>
            </form>
        </div>
    </div>
    </div>
    </div>

    <!-- Script Search -->
    <script>
        document.getElementById("searchInput").addEventListener("keyup", function() {
            var input, filter, table, tr, td, i, j, txtValue;
            input = document.getElementById("searchInput");
            filter = input.value.toUpperCase();
            table = document.getElementById("dataTable");
            tr = table.getElementsByTagName("tr");

            for (i = 1; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td");
                let found = false;
                for (j = 0; j < td.length; j++) {
                    if (td[j]) {
                        txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                tr[i].style.display = found ? "" : "none";
            }
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    </div>
    </div>
    </div>
    </div>
    </div>

</div>







<?php require 'footer.php'; ?>
