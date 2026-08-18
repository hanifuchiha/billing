<?php
/**
 * Helper terpusat untuk fitur Customer Corporate (B2B) -- fondasi fase 2
 * (lanjutan Menu Paket & Customer Static IP).
 *
 * Corporate SENGAJA tidak reuse tabel pelanggan/paket/transaksi (beda
 * dengan Static IP) -- billing model-nya invoice manual per kejadian
 * dengan termin pembayaran, bukan tagihan bulanan berbasis periode PPPoE,
 * dan layanan corporate span jauh melebihi PPPoE (Data Center, Colocation,
 * dst) sehingga tidak cocok dipaksakan ke skema pelanggan/paket. Fondasi
 * ini murni pencatatan perusahaan + kontak + kontrak + billing manual,
 * TIDAK ada provisioning Mikrotik/RADIUS sama sekali.
 */

if (!function_exists('corporateEnsureSchema')) {
    function corporateEnsureSchema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS corporate (
            id INT AUTO_INCREMENT PRIMARY KEY,
            PEMILIK VARCHAR(100) NOT NULL,
            AREA VARCHAR(100) DEFAULT '',
            NAMA_PERUSAHAAN VARCHAR(255) NOT NULL,
            PJ_NAMA VARCHAR(150) DEFAULT '',
            PJ_JABATAN VARCHAR(100) DEFAULT '',
            NPWP VARCHAR(50) DEFAULT '',
            NIB VARCHAR(50) DEFAULT '',
            SIUP VARCHAR(50) DEFAULT '',
            ALAMAT_KANTOR TEXT,
            EMAIL_FINANCE VARCHAR(150) DEFAULT '',
            EMAIL_IT VARCHAR(150) DEFAULT '',
            TELEPON VARCHAR(30) DEFAULT '',
            WHATSAPP VARCHAR(30) DEFAULT '',
            WEBSITE VARCHAR(150) DEFAULT '',
            CATATAN TEXT,
            LOGO VARCHAR(255) DEFAULT '',
            STATUS VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
            PORTAL_USERNAME VARCHAR(100) DEFAULT NULL,
            PORTAL_PASSWORD VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Portal Login Corporate (fase 4) -- kolom baru utk tabel corporate yg
        // sudah eksis dari fase 2 (CREATE TABLE IF NOT EXISTS di atas tidak
        // menyentuh tabel yang sudah ada, jadi wajib ALTER terpisah).
        $col = @mysqli_query($conn, "SHOW COLUMNS FROM corporate LIKE 'PORTAL_USERNAME'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE corporate ADD COLUMN PORTAL_USERNAME VARCHAR(100) DEFAULT NULL");
        }
        $col = @mysqli_query($conn, "SHOW COLUMNS FROM corporate LIKE 'PORTAL_PASSWORD'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE corporate ADD COLUMN PORTAL_PASSWORD VARCHAR(255) DEFAULT NULL");
        }
        // Unique index terpisah (bukan inline UNIQUE di CREATE TABLE) supaya
        // aman dicoba berkali-kali tanpa error kalau kolomnya baru ditambah
        // lewat ALTER di atas (banyak baris existing PORTAL_USERNAME NULL --
        // MySQL izinkan banyak NULL di kolom UNIQUE, jadi aman).
        $idxCheck = @mysqli_query($conn, "SHOW INDEX FROM corporate WHERE Key_name = 'uniq_portal_username'");
        if ($idxCheck && mysqli_num_rows($idxCheck) === 0) {
            @mysqli_query($conn, "ALTER TABLE corporate ADD UNIQUE KEY uniq_portal_username (PORTAL_USERNAME)");
        }

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS corporate_pic (
            id INT AUTO_INCREMENT PRIMARY KEY,
            corporate_id INT NOT NULL,
            nama VARCHAR(150) DEFAULT '',
            jabatan VARCHAR(100) DEFAULT '',
            email VARCHAR(150) DEFAULT '',
            whatsapp VARCHAR(30) DEFAULT '',
            telepon VARCHAR(30) DEFAULT '',
            INDEX idx_corporate_pic_cid (corporate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS corporate_kontrak (
            id INT AUTO_INCREMENT PRIMARY KEY,
            corporate_id INT NOT NULL,
            nomor_kontrak VARCHAR(100) DEFAULT '',
            tanggal_mulai DATE NULL,
            tanggal_berakhir DATE NULL,
            auto_reminder TINYINT(1) NOT NULL DEFAULT 0,
            hari_sebelum_reminder INT NOT NULL DEFAULT 30,
            file_pdf VARCHAR(255) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
            catatan TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_corporate_kontrak_cid (corporate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transaksi_corporate (
            id INT AUTO_INCREMENT PRIMARY KEY,
            corporate_id INT NOT NULL,
            PEMILIK VARCHAR(100) NOT NULL,
            nomor_invoice VARCHAR(100) NOT NULL,
            deskripsi TEXT,
            nomor_po VARCHAR(100) DEFAULT '',
            jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
            pajak_persen DECIMAL(5,2) NOT NULL DEFAULT 0,
            termin VARCHAR(20) NOT NULL DEFAULT 'NET30',
            tanggal_invoice DATE NULL,
            tanggal_jatuh_tempo DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'BELUM_BAYAR',
            catatan TEXT,
            dibuat_oleh VARCHAR(100) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_nomor_invoice (nomor_invoice),
            INDEX idx_transaksi_corporate_cid (corporate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS transaksi_corporate_pembayaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaksi_corporate_id INT NOT NULL,
            jumlah_bayar DECIMAL(15,2) NOT NULL DEFAULT 0,
            tanggal_bayar DATE NULL,
            metode_bayar VARCHAR(50) DEFAULT '',
            keterangan VARCHAR(255) DEFAULT '',
            dicatat_oleh VARCHAR(100) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trx_corp_pembayaran_tid (transaksi_corporate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Multi Layanan (fase 3) -- SENGAJA tidak reuse tabel pelanggan (lihat
        // corporateLayananProvision()): kalau layanan ini kena mesin billing
        // PPPoE reguler (cek_tagihan_harian.php dkk), akan bentrok dengan
        // billing manual/termin Corporate yang sudah ada di transaksi_corporate.
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS corporate_layanan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            corporate_id INT NOT NULL,
            PEMILIK VARCHAR(100) NOT NULL,
            jenis_layanan VARCHAR(50) NOT NULL DEFAULT 'Internet Dedicated',
            nama_layanan VARCHAR(150) DEFAULT '',
            server_id INT NULL,
            paket_id INT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            vlan_id INT NULL,
            olt_id INT NULL,
            provisioning_aktif TINYINT(1) NOT NULL DEFAULT 0,
            auth_mode VARCHAR(20) NOT NULL DEFAULT 'API MODE',
            pppoe_username VARCHAR(64) DEFAULT '',
            pppoe_password VARCHAR(100) DEFAULT '',
            status_koneksi VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
            status VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
            tanggal_aktif DATE NULL,
            catatan TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_corporate_layanan_cid (corporate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM transaksi_corporate LIKE 'corporate_layanan_id'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE transaksi_corporate ADD COLUMN corporate_layanan_id INT NULL DEFAULT NULL");
        }

        // Katalog Paket KHUSUS Corporate -- SENGAJA terpisah dari tabel `paket`
        // residential (PPPoE biasa/Static IP). User minta eksplisit paket
        // Corporate JANGAN diambil dari pool paket residential (beda
        // karakteristik harga/kecepatan, & campur pool bikin dropdown
        // berantakan/salah pilih). Lebih ramping dari `paket` residential --
        // tanpa komisi/DISKON_PERMANEN_*/RADIUS_PROFILE_SOURCE krn itu semua
        // spesifik mesin billing-otomatis residential yang Corporate tidak
        // pakai (billing Corporate manual/termin lewat transaksi_corporate).
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS paket_corporate (
            id INT AUTO_INCREMENT PRIMARY KEY,
            PEMILIK VARCHAR(100) NOT NULL,
            AREA VARCHAR(100) NOT NULL,
            PAKET VARCHAR(150) NOT NULL,
            KECEPATAN VARCHAR(50) DEFAULT '',
            HARGA DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_paket_corporate_pemilik_area (PEMILIK, AREA)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('corporateAreaFilterSql')) {
    /**
     * Pola scoping ASSISTANT-aware standar (lihat dashboard.php / memory
     * project_systemic_area_scoping_bug, dipakai juga di staticip_helper.php
     * fase 1) -- PEMILIK sudah cukup utk scoping tenant, tambahan ini
     * mempersempit ke AREA yang di-assign kalau sesi ini ASSISTANT.
     */
    function corporateAreaFilterSql(string $columnName, ?string $AKSES, ?string $area_list): string
    {
        if ($AKSES === 'ASSISTANT' && !empty($area_list)) {
            // Dukung nama kolom qualified (mis. "c.AREA" dari query JOIN) --
            // tiap segmen dibungkus backtick terpisah, bukan seluruh string
            // sekaligus (backtick tidak boleh berisi titik).
            $quoted = implode('.', array_map(static function ($part) {
                return '`' . $part . '`';
            }, explode('.', $columnName)));
            return " AND ($quoted IN ($area_list) OR $quoted = '' OR $quoted IS NULL)";
        }
        return '';
    }
}

if (!function_exists('corporateGenerateInvoiceNumber')) {
    function corporateGenerateInvoiceNumber(int $corporateId): string
    {
        return 'INV-CORP-' . $corporateId . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }
}

if (!function_exists('corporateTerminToDays')) {
    function corporateTerminToDays(string $termin): int
    {
        $map = ['NET7' => 7, 'NET14' => 14, 'NET30' => 30, 'NET60' => 60, 'CASH' => 0];
        return $map[$termin] ?? 30;
    }
}

if (!function_exists('corporateRecalcStatus')) {
    /**
     * Dipanggil setiap kali ada baris baru masuk transaksi_corporate_pembayaran
     * -- hitung ulang total dibayar vs jumlah tagihan, update status induk.
     * status yang disimpan cuma BELUM_BAYAR/PARTIAL/LUNAS -- badge OVERDUE
     * dihitung on-the-fly saat render (lihat transaksicorporate.php), tidak
     * disimpan sbg status, supaya tidak perlu cron.
     */
    function corporateRecalcStatus(mysqli $conn, int $transaksiId): void
    {
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT jumlah FROM transaksi_corporate WHERE id = $transaksiId LIMIT 1"));
        if (!$row) {
            return;
        }
        $jumlah = (float) $row['jumlah'];
        $sumRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM transaksi_corporate_pembayaran WHERE transaksi_corporate_id = $transaksiId"));
        $totalDibayar = $sumRow ? (float) $sumRow['total'] : 0;

        if ($totalDibayar <= 0) {
            $status = 'BELUM_BAYAR';
        } elseif ($totalDibayar >= $jumlah) {
            $status = 'LUNAS';
        } else {
            $status = 'PARTIAL';
        }

        mysqli_query($conn, "UPDATE transaksi_corporate SET status = '" . mysqli_real_escape_string($conn, $status) . "' WHERE id = $transaksiId");
    }
}

if (!function_exists('corporateTotalDibayar')) {
    function corporateTotalDibayar(mysqli $conn, int $transaksiId): float
    {
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(jumlah_bayar), 0) AS total FROM transaksi_corporate_pembayaran WHERE transaksi_corporate_id = $transaksiId"));
        return $row ? (float) $row['total'] : 0.0;
    }
}

if (!function_exists('corporateHandleFileUpload')) {
    /**
     * Upload generik dipakai 2 tempat: logo perusahaan (gambar, di-re-encode
     * PNG persis pola proses/upload_server_logo.php) & kontrak PDF (pola
     * proses/activecustomer.php bukti-bayar: ext whitelist + MIME + magic
     * bytes, move_uploaded_file dgn fallback rename/copy).
     *
     * $type = 'logo' | 'kontrak'. $namePrefix dipakai bagian depan nama file
     * (mis. corporate id). Return [] kalau tidak ada file diupload (bukan
     * error -- field opsional), atau ['error' => 'pesan'] kalau invalid,
     * atau ['relative_path' => 'logo/xxx.png'] kalau sukses (path RELATIF
     * terhadap folder dokumen/, utk disimpan ke DB & dipakai bareng
     * corporateDokumenUrl()).
     */
    function corporateHandleFileUpload(array $file, string $type, string $namePrefix): array
    {
        $uploadedError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadedError === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
            return [];
        }
        if ($uploadedError !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload gagal (kode error: ' . $uploadedError . ').'];
        }

        $maxSize = 5 * 1024 * 1024; // 5 MB
        if (($file['size'] ?? 0) > $maxSize) {
            return ['error' => 'File terlalu besar. Maksimum ' . ($maxSize / 1024 / 1024) . ' MB.'];
        }

        $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $namePrefix);
        $subDir = ($type === 'logo') ? 'logo' : 'kontrak';
        $target_dir = __DIR__ . '/../../dokumen/' . $subDir . '/';
        if (!is_dir($target_dir) && !@mkdir($target_dir, 0775, true) && !@mkdir($target_dir, 0777, true)) {
            return ['error' => 'Gagal membuat folder upload ' . $subDir . '.'];
        }
        if (!is_writable($target_dir)) {
            @chmod($target_dir, 0775);
        }
        if (!is_writable($target_dir)) {
            @chmod($target_dir, 0777);
        }
        if (!is_writable($target_dir)) {
            return ['error' => 'Folder upload ' . $subDir . ' tidak bisa ditulis server.'];
        }

        if ($type === 'logo') {
            $allowedExt = ['jpg', 'jpeg', 'png'];
            $allowedMime = ['image/jpeg', 'image/png', 'image/pjpeg', 'image/x-png'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                return ['error' => 'Hanya file JPG atau PNG yang diperbolehkan untuk logo.'];
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMime, true)) {
                return ['error' => 'Tipe file logo tidak valid (bukan JPG/PNG).'];
            }
            if (@getimagesize($file['tmp_name']) === false) {
                return ['error' => 'Bukan file gambar logo yang valid.'];
            }

            $filename = 'corp-logo-' . $safePrefix . '-' . bin2hex(random_bytes(8)) . '.png';
            $target_file = $target_dir . $filename;

            $moved = false;
            if (function_exists('imagecreatefromstring')) {
                $imageData = file_get_contents($file['tmp_name']);
                $img = @imagecreatefromstring($imageData);
                if ($img !== false) {
                    imagesavealpha($img, true);
                    $moved = imagepng($img, $target_file);
                    imagedestroy($img);
                }
            }
            if (!$moved) {
                $moved = @move_uploaded_file($file['tmp_name'], $target_file);
            }
            if (!$moved) {
                return ['error' => 'Gagal menyimpan file logo.'];
            }
            @chmod($target_file, 0644);
            return ['relative_path' => $subDir . '/' . $filename];
        }

        // Kontrak PDF.
        $allowedExt = ['pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            return ['error' => 'Hanya file PDF yang diperbolehkan untuk kontrak.'];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            return ['error' => 'Tipe file kontrak tidak valid (bukan PDF).'];
        }
        $magic = @file_get_contents($file['tmp_name'], false, null, 0, 5);
        if ($magic !== '%PDF-') {
            return ['error' => 'File kontrak bukan PDF yang valid.'];
        }

        $filename = 'kontrak_' . $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $target_file = $target_dir . $filename;

        $moved = @move_uploaded_file($file['tmp_name'], $target_file);
        if (!$moved && is_uploaded_file($file['tmp_name'])) {
            $moved = @rename($file['tmp_name'], $target_file);
        }
        if (!$moved && file_exists($file['tmp_name'])) {
            $moved = @copy($file['tmp_name'], $target_file);
            if ($moved) {
                @unlink($file['tmp_name']);
            }
        }
        if (!$moved) {
            return ['error' => 'Gagal menyimpan file kontrak.'];
        }
        @chmod($target_file, 0644);
        return ['relative_path' => $subDir . '/' . $filename];
    }
}

if (!function_exists('corporateDeleteDokumenFile')) {
    function corporateDeleteDokumenFile(string $relativePath): void
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return;
        }
        $full = __DIR__ . '/../../dokumen/' . ltrim($relativePath, '/');
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

if (!function_exists('corporateDokumenUrl')) {
    function corporateDokumenUrl(string $relativePath): string
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '') {
            return '';
        }
        return '/dokumen/' . ltrim($relativePath, '/');
    }
}

/**
 * ==== Multi Layanan (fase 3) -- provisioning helper ====
 *
 * PENTING: fungsi-fungsi di bawah TIDAK PERNAH menyentuh tabel `pelanggan`
 * (lihat catatan arsitektur di kepala file). Caller WAJIB sudah
 * require '../routeros_api.class.php' dan require_once radius_sync_lib.php
 * SEBELUM memanggil fungsi manapun di bawah ini (PHP resolve class/fungsi
 * saat dipanggil, bukan saat file ini di-require, jadi ini aman selama
 * urutan require di proses/*.php benar).
 */

if (!function_exists('corporateLayananValidateUsername')) {
    function corporateLayananValidateUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return 'Username PPPoE wajib diisi';
        }
        if (preg_match('/[\x00-\x20"\\\\\x7F]/', $username) || $username[0] === '#' || strlen($username) > 64) {
            return 'Username PPPoE tidak boleh mengandung spasi, tanda kutip dua ("), backslash (\\), tidak boleh diawali (#), dan maksimal 64 karakter';
        }
        return '';
    }
}

if (!function_exists('corporateLayananValidatePassword')) {
    function corporateLayananValidatePassword(string $password): string
    {
        if (trim($password) === '') {
            return 'Password PPPoE wajib diisi';
        }
        if (preg_match('/[\s"\\\\]/', $password)) {
            return 'Password PPPoE tidak boleh mengandung spasi, tanda kutip (") atau backslash (\\)';
        }
        return '';
    }
}

if (!function_exists('corporateLayananUsernameTaken')) {
    /**
     * Namespace username PPPoE bersifat global di router -- wajib dicek unik
     * lintas tabel pelanggan (Customer PPPoE/Static IP) DAN corporate_layanan
     * itu sendiri, bukan cuma di dalam 1 tabel.
     */
    function corporateLayananUsernameTaken(mysqli $conn, string $username, int $excludeLayananId = 0): bool
    {
        $usernameEsc = mysqli_real_escape_string($conn, $username);
        $cekPelanggan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$usernameEsc' LIMIT 1"));
        if ($cekPelanggan) {
            return true;
        }
        $excludeSql = $excludeLayananId > 0 ? " AND id != $excludeLayananId" : '';
        $cekLayanan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate_layanan WHERE pppoe_username = '$usernameEsc'" . $excludeSql . " LIMIT 1"));
        return (bool) $cekLayanan;
    }
}

if (!function_exists('corporateLayananProvision')) {
    /**
     * $server = baris tabel `server` (IP/PASSWORD/PEMILIK/CONNECTION_MODE).
     * $paket = baris tabel `paket` (PAKET/KECEPATAN).
     * Return ['success'=>bool, 'message'=>string].
     */
    function corporateLayananProvision(mysqli $conn, array $server, array $paket, string $authMode, string $username, string $password, string $ipAddress): array
    {
        if ($authMode === 'API MODE' || $authMode === 'MULTI MODE') {
            if (!class_exists('RouterosAPI')) {
                return ['success' => false, 'message' => 'RouterosAPI class belum dimuat'];
            }
            $API = new RouterosAPI();
            if (!$API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
                return ['success' => false, 'message' => "Gagal konek ke MikroTik {$server['PEMILIK']}"];
            }
            $existing = $API->comm('/ppp/secret/print', ['?name' => $username]);
            if (!empty($existing)) {
                $API->disconnect();
                return ['success' => false, 'message' => "Username $username sudah ada di server {$server['PEMILIK']}"];
            }
            $params = [
                'name' => $username,
                'password' => $password,
                'profile' => $paket['PAKET'] ?? '',
                'service' => 'any',
                'comment' => 'CORPORATE LAYANAN',
            ];
            if ($ipAddress !== '') {
                $params['remote-address'] = $ipAddress;
            }
            $API->comm('/ppp/secret/add', $params);
            $API->disconnect();
        }

        if ($authMode === 'RADIUS MODE' || $authMode === 'MULTI MODE') {
            if (!function_exists('radiusReadMergedBlocks') || !function_exists('radiusSyncSingleCustomerNow')) {
                return ['success' => false, 'message' => 'radius_sync_lib.php belum dimuat'];
            }
            $dup = false;
            foreach (radiusReadMergedBlocks() as $b) {
                if ($b['username'] === $username) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                return ['success' => false, 'message' => "Username $username sudah ada di Radius"];
            }
            radiusSyncSingleCustomerNow($username, $password, $paket, true, radiusGetGlobalSettings($conn), $ipAddress);
        }

        return ['success' => true, 'message' => ''];
    }
}

if (!function_exists('corporateLayananDeprovision')) {
    function corporateLayananDeprovision(array $server, string $authMode, string $username): void
    {
        if (($authMode === 'API MODE' || $authMode === 'MULTI MODE') && class_exists('RouterosAPI') && !empty($server)) {
            $API = new RouterosAPI();
            if ($API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
                $cari = $API->comm('/ppp/secret/getall', ['.proplist' => '.id', '?name' => $username]);
                if (!empty($cari) && isset($cari[0]['.id'])) {
                    $API->comm('/ppp/secret/remove', ['.id' => $cari[0]['.id']]);
                }
                $aktif = $API->comm('/ppp/active/getall', ['.proplist' => '.id', '?name' => $username]);
                if (!empty($aktif) && isset($aktif[0]['.id'])) {
                    $API->comm('/ppp/active/remove', ['.id' => $aktif[0]['.id']]);
                }
                $API->disconnect();
            }
        }
        if (($authMode === 'RADIUS MODE' || $authMode === 'MULTI MODE') && function_exists('radiusRemoveUsers')) {
            $result = radiusRemoveUsers([$username]);
            if (function_exists('radiusReloadIfChanged')) {
                radiusReloadIfChanged(!empty($result['changed']));
            }
        }
    }
}

if (!function_exists('corporateLayananSetIsolir')) {
    /**
     * $isolir=true -> putus koneksi (disable secret API MODE / Mikrotik-Group
     * jadi address_list_expired RADIUS MODE, lewat $sudahBayar=false di
     * radiusSyncSingleCustomerNow -- fungsi PERSIS yang dipakai cron isolir
     * pelanggan menunggak biasa, cuma dipicu manual di sini). $isolir=false
     * = kebalikannya (pulihkan). $password WAJIB nilai yang sudah tersimpan
     * (bukan string kosong) karena radiusUpsertUsers menulis ulang SELURUH
     * entry termasuk password.
     */
    function corporateLayananSetIsolir(mysqli $conn, array $server, array $paket, string $authMode, string $username, string $password, string $ipAddress, bool $isolir): array
    {
        if (($authMode === 'API MODE' || $authMode === 'MULTI MODE') && class_exists('RouterosAPI') && !empty($server)) {
            $API = new RouterosAPI();
            if ($API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
                $cari = $API->comm('/ppp/secret/getall', ['.proplist' => '.id', '?name' => $username]);
                if (!empty($cari) && isset($cari[0]['.id'])) {
                    $API->comm('/ppp/secret/set', ['.id' => $cari[0]['.id'], 'disabled' => $isolir ? 'yes' : 'no']);
                }
                if ($isolir) {
                    $aktif = $API->comm('/ppp/active/getall', ['.proplist' => '.id', '?name' => $username]);
                    if (!empty($aktif) && isset($aktif[0]['.id'])) {
                        $API->comm('/ppp/active/remove', ['.id' => $aktif[0]['.id']]);
                    }
                }
                $API->disconnect();
            } else {
                return ['success' => false, 'message' => "Gagal konek ke MikroTik {$server['PEMILIK']}"];
            }
        }
        if (($authMode === 'RADIUS MODE' || $authMode === 'MULTI MODE') && function_exists('radiusSyncSingleCustomerNow')) {
            radiusSyncSingleCustomerNow($username, $password, $paket, !$isolir, radiusGetGlobalSettings($conn), $ipAddress);
        }
        return ['success' => true, 'message' => ''];
    }
}

/**
 * ==== Pengaturan tampilan Portal Corporate (fase 5) ====
 *
 * Mirror PERSIS pola `portal_links_helper.php` (dipakai `broadband/
 * portallogin.php`/`portal_setting.php`) -- disimpan per akun OWNER
 * (username CRM, JSON di settings/) BUKAN di database, supaya konsisten
 * dgn konvensi existing utk pengaturan tampilan halaman publik.
 */

if (!function_exists('corporatePortalDefaults')) {
    function corporatePortalDefaults(): array
    {
        return [
            'product_name'   => '',
            'tagline'        => '',
            'feature1_title' => '',
            'feature1_text'  => '',
            'feature2_title' => '',
            'feature2_text'  => '',
            'feature3_title' => '',
            'feature3_text'  => '',
            'faq_text'       => '',
            'refund_text'    => '',
            'terms_text'     => '',
            'contact_text'   => '',
        ];
    }
}

if (!function_exists('corporatePortalSafeUsername')) {
    function corporatePortalSafeUsername(string $username): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $username);
    }
}

if (!function_exists('corporatePortalFilePath')) {
    function corporatePortalFilePath(string $username): ?string
    {
        $safe = corporatePortalSafeUsername($username);
        if ($safe === '') {
            return null;
        }
        return __DIR__ . '/settings/corporate-portal-' . $safe . '.json';
    }
}

if (!function_exists('corporatePortalGet')) {
    function corporatePortalGet(string $username): array
    {
        $defaults = corporatePortalDefaults();
        $file = corporatePortalFilePath($username);
        if ($file === null || !is_file($file)) {
            return $defaults;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded)) {
            return $defaults;
        }
        $result = $defaults;
        foreach ($defaults as $key => $default_val) {
            if (array_key_exists($key, $decoded)) {
                $result[$key] = (string) $decoded[$key];
            }
        }
        return $result;
    }
}

if (!function_exists('corporatePortalSave')) {
    function corporatePortalSave(string $username, array $input): bool
    {
        $file = corporatePortalFilePath($username);
        if ($file === null) {
            return false;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $shortFieldMaxLen = [
            'product_name'   => 100,
            'tagline'        => 200,
            'feature1_title' => 40,
            'feature1_text'  => 150,
            'feature2_title' => 40,
            'feature2_text'  => 150,
            'feature3_title' => 40,
            'feature3_text'  => 150,
        ];

        $defaults = corporatePortalDefaults();
        $normalized = $defaults;
        foreach ($defaults as $key => $default_val) {
            if (array_key_exists($key, $input)) {
                $value = trim((string) $input[$key]);
                $normalized[$key] = isset($shortFieldMaxLen[$key])
                    ? mb_substr($value, 0, $shortFieldMaxLen[$key])
                    : mb_substr($value, 0, 5000);
            }
        }

        return @file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }
}
