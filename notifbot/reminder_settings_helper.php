<?php
/**
 * reminder_settings_helper.php
 *
 * Satu-satunya sumber kebenaran (single source of truth) utk pengaturan
 * "Fixed Due Date" / reminder tagihan per akun CRM -- dulu tersimpan MURNI di
 * file notifbot/data/reminder-<username>.json, ditulis dari 4 titik berbeda
 * (paymentset.php, notification_api.php x2 action, api/notification_settings_mobile.php)
 * dengan subset field yang beda-beda tiap titik.
 *
 * Sumber data SEKARANG adalah tabel database `reminder_settings` (auto-create,
 * lihat reminderSettingsEnsureTable()). File notifbot/data/reminder-<pemilik>.json
 * TETAP di-generate ulang otomatis stiap kali disimpan (lihat
 * reminderSettingsSyncJsonMirror()) -- BUKAN lagi sumber data, cuma cache/mirror
 * turunan, supaya puluhan file lain (cron notifbot/notifphp/*.php, callback
 * payment gateway yg proses transaksi sungguhan) yg masih baca file itu
 * langsung tetap jalan tanpa perlu diubah satu-satu -- migrasi total ke DB utk
 * SEMUA 80 file sekaligus terlalu berisiko tanpa PHP interpreter utk testing.
 *
 * WAJIB dipakai (bukan bikin cara baca/tulis baru) oleh: paymentset.php,
 * notification.php, notification_api.php, api/notification_settings_mobile.php.
 */

if (!function_exists('reminderSettingsKnownColumns')) {
    function reminderSettingsKnownColumns(): array
    {
        return [
            'jatuh_tempo', 'hari_sebelum', 'tanggal_reminder', 'jam_reminder',
            'menit_reminder', 'botname', 'tanggal_awal_tutup_buku',
            'tanggal_akhir_tutup_buku', 'prorate_untuk_telat', 'periode_tercatat',
            'filter_sudah_bayar_reminder', 'filter_skip_notif_hari_ini',
        ];
    }
}

if (!function_exists('reminderSettingsBoolColumns')) {
    function reminderSettingsBoolColumns(): array
    {
        return ['prorate_untuk_telat', 'filter_sudah_bayar_reminder', 'filter_skip_notif_hari_ini'];
    }
}

if (!function_exists('reminderSettingsStringColumns')) {
    function reminderSettingsStringColumns(): array
    {
        return ['botname', 'periode_tercatat'];
    }
}

if (!function_exists('reminderSettingsCoreColumns')) {
    /**
     * 8 field "inti" Fixed Due Date -- persis field yang dikelola bareng oleh
     * form paymentset.php DAN action notification_api.php `save_reminder_
     * fixed_due_date` DAN mobile API `save_schedule` (3 dari 4 titik simpan;
     * cuma `save_filter_reminder` yang TIDAK menyertakan field2 ini).
     *
     * PENTING: sebelum migrasi ke DB, beberapa consumer lama (non_aktif_tempo*.php,
     * notif_remainder_pembayaran*.php, validasi_isolir_harian.php) memakai
     * ADA/TIDAKNYA key 'jatuh_tempo' di file JSON sbg SATU-SATUNYA penanda
     * "akun ini sudah pernah setting Fixed Due Date". Kalau field ini SELALU
     * ada di mirror JSON (krn reminderSettingsGet() isi default), consumer2
     * itu akan salah kira SEMUA akun (termasuk yg cuma pernah simpan filter
     * checkbox doang) sudah mengaktifkan Fixed Due Date -- utk non_aktif_tempo*.php
     * ini BUG SERIUS: bisa mengisolir pelanggan yang tidak seharusnya diisolir.
     * Makanya field2 ini SENGAJA di-gate oleh kolom `fixed_due_date_configured`
     * (lihat reminderSettingsSyncJsonMirror()) -- HANYA muncul di mirror JSON
     * kalau memang pernah disave lewat salah satu dari 3 titik simpan di atas,
     * PERSIS spt perilaku file JSON asli sebelum migrasi ini.
     */
    function reminderSettingsCoreColumns(): array
    {
        return [
            'jatuh_tempo', 'hari_sebelum', 'tanggal_reminder', 'jam_reminder',
            'menit_reminder', 'botname', 'tanggal_awal_tutup_buku', 'tanggal_akhir_tutup_buku',
        ];
    }
}

if (!function_exists('reminderSettingsDefaults')) {
    function reminderSettingsDefaults(): array
    {
        return [
            'jatuh_tempo' => 25,
            'hari_sebelum' => 2,
            'tanggal_reminder' => 23,
            'jam_reminder' => 7,
            'menit_reminder' => 0,
            'botname' => '',
            'tanggal_awal_tutup_buku' => 1,
            'tanggal_akhir_tutup_buku' => 1,
            'prorate_untuk_telat' => 1,
            'periode_tercatat' => 'berjalan',
            'filter_sudah_bayar_reminder' => 1,
            'filter_skip_notif_hari_ini' => 1,
        ];
    }
}

if (!function_exists('reminderSettingsEnsureTable')) {
    /**
     * Auto-install tabel (CREATE TABLE IF NOT EXISTS) + auto-ALTER kolom yg
     * belum ada -- pola sama persis dgn notif_khusus di notification.php.
     * Dibungkus static flag supaya cuma benar2 query sekali per request
     * (dipanggil berulang dari banyak fungsi lain di file ini).
     */
    function reminderSettingsEnsureTable($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $conn->query("CREATE TABLE IF NOT EXISTS reminder_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pemilik VARCHAR(100) NOT NULL,
            jatuh_tempo INT DEFAULT 25,
            hari_sebelum INT DEFAULT 2,
            tanggal_reminder INT DEFAULT 23,
            jam_reminder INT DEFAULT 7,
            menit_reminder INT DEFAULT 0,
            botname VARCHAR(150) DEFAULT '',
            tanggal_awal_tutup_buku INT DEFAULT 1,
            tanggal_akhir_tutup_buku INT DEFAULT 1,
            prorate_untuk_telat TINYINT(1) DEFAULT 1,
            periode_tercatat VARCHAR(20) DEFAULT 'berjalan',
            filter_sudah_bayar_reminder TINYINT(1) DEFAULT 1,
            filter_skip_notif_hari_ini TINYINT(1) DEFAULT 1,
            fixed_due_date_configured TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pemilik (pemilik)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migrasi skema lama (kalau tabel sudah pernah ada tapi kolom belum lengkap)
        $columns = [];
        $res = $conn->query('SHOW COLUMNS FROM reminder_settings');
        while ($res && ($row = $res->fetch_assoc())) {
            $columns[] = (string)$row['Field'];
        }
        $colDefs = [
            'pemilik' => "VARCHAR(100) NOT NULL DEFAULT ''",
            'jatuh_tempo' => 'INT DEFAULT 25',
            'hari_sebelum' => 'INT DEFAULT 2',
            'tanggal_reminder' => 'INT DEFAULT 23',
            'jam_reminder' => 'INT DEFAULT 7',
            'menit_reminder' => 'INT DEFAULT 0',
            'botname' => "VARCHAR(150) DEFAULT ''",
            'tanggal_awal_tutup_buku' => 'INT DEFAULT 1',
            'tanggal_akhir_tutup_buku' => 'INT DEFAULT 1',
            'prorate_untuk_telat' => 'TINYINT(1) DEFAULT 1',
            'periode_tercatat' => "VARCHAR(20) DEFAULT 'berjalan'",
            'filter_sudah_bayar_reminder' => 'TINYINT(1) DEFAULT 1',
            'filter_skip_notif_hari_ini' => 'TINYINT(1) DEFAULT 1',
            'fixed_due_date_configured' => 'TINYINT(1) DEFAULT 0',
        ];
        foreach ($colDefs as $col => $def) {
            if (!in_array($col, $columns, true)) {
                $conn->query("ALTER TABLE reminder_settings ADD COLUMN `$col` $def");
            }
        }
    }
}

if (!function_exists('reminderSettingsFilePath')) {
    function reminderSettingsFilePath(string $pemilik): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $pemilik);
        return __DIR__ . '/data/reminder-' . $safe . '.json';
    }
}

if (!function_exists('reminderSettingsMigrateFromJsonIfNeeded')) {
    /**
     * Sekali jalan per akun: kalau baris DB belum ada TAPI file JSON lama ada
     * isinya, import ke DB supaya akun yang SUDAH pernah setting Fixed Due
     * Date sebelum migrasi ini tidak kehilangan settingnya (tidak perlu isi
     * ulang manual). Kalau baris DB sudah ada, fungsi ini tidak melakukan
     * apa-apa (DB sudah jadi sumber kebenaran).
     */
    function reminderSettingsMigrateFromJsonIfNeeded($conn, string $pemilik): void
    {
        if (!$conn || $pemilik === '') {
            return;
        }

        $stmtCheck = $conn->prepare('SELECT id FROM reminder_settings WHERE pemilik = ? LIMIT 1');
        if (!$stmtCheck) {
            return;
        }
        $stmtCheck->bind_param('s', $pemilik);
        $stmtCheck->execute();
        $stmtCheck->store_result();
        $alreadyExists = $stmtCheck->num_rows > 0;
        $stmtCheck->close();
        if ($alreadyExists) {
            return;
        }

        $file = reminderSettingsFilePath($pemilik);
        if (!file_exists($file)) {
            return;
        }
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json) || empty($json[0]) || !is_array($json[0])) {
            return;
        }
        $row = $json[0];
        $known = reminderSettingsKnownColumns();
        $boolCols = reminderSettingsBoolColumns();
        $strCols = reminderSettingsStringColumns();

        $cols = [];
        $vals = [];
        $types = '';
        foreach ($known as $col) {
            if (!array_key_exists($col, $row)) {
                continue;
            }
            $cols[] = $col;
            if (in_array($col, $boolCols, true)) {
                $vals[] = $row[$col] ? 1 : 0;
                $types .= 'i';
            } elseif (in_array($col, $strCols, true)) {
                $vals[] = (string)$row[$col];
                $types .= 's';
            } else {
                $vals[] = (int)$row[$col];
                $types .= 'i';
            }
        }
        if (empty($cols)) {
            return;
        }

        // Migrasi penanda fixed_due_date_configured dari kondisi file JSON lama:
        // key 'jatuh_tempo' ADA di file lama = akun ini pernah eksplisit setting
        // Fixed Due Date (lewat paymentset.php/notification_api.php/mobile API,
        // ketiganya SELALU menyertakan jatuh_tempo tiap kali overwrite file) --
        // key TIDAK ADA = file cuma pernah diisi dari save_filter_reminder (2
        // field doang). Ini mempertahankan PERSIS sentinel lama supaya consumer
        // yg mengandalkan isset($item['jatuh_tempo']) (non_aktif_tempo*.php dkk)
        // tetap berperilaku sama persis stlh migrasi.
        $cols[] = 'fixed_due_date_configured';
        $vals[] = array_key_exists('jatuh_tempo', $row) ? 1 : 0;
        $types .= 'i';

        $colsSql = '`' . implode('`, `', $cols) . '`';
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmtIns = $conn->prepare("INSERT IGNORE INTO reminder_settings (pemilik, $colsSql) VALUES (?, $placeholders)");
        if (!$stmtIns) {
            return;
        }
        $bindTypes = 's' . $types;
        $bindVals = array_merge([$pemilik], $vals);
        $refs = [];
        foreach ($bindVals as $k => $v) {
            $refs[] = &$bindVals[$k];
        }
        array_unshift($refs, $bindTypes);
        call_user_func_array([$stmtIns, 'bind_param'], $refs);
        @$stmtIns->execute();
        $stmtIns->close();
    }
}

if (!function_exists('reminderSettingsResolveOwnerUsername')) {
    /**
     * reminder_settings (dan seluruh sistem Fixed Due Date) SELALU dikunci per
     * USERNAME LOGIN pemilik akun (persis $ceknama), BUKAN per kolom PEMILIK di
     * tabel server/pelanggan -- dua hal ini SERING beda: server yang dibuat via
     * mode RADIUS_ONLY (lihat proses/addserver.php generateMikrotikCredentials())
     * dapat PEMILIK berupa kunci internal acak per brand/area (mis. "MEDIAQ_xxx"),
     * bukan username login. Kalau nilai $pemilikKey dipakai APA ADANYA ke
     * reminderSettingsGet()/GetRow(), baris DB tidak pernah ketemu -> selalu
     * jatuh ke default (mis. 25) walau admin sudah setting Fixed Due Date lewat
     * Payment Setting -- itulah gejala yang dilaporkan user 2026-08-05 (setting
     * 26 di Payment Setting, tapi tables.php tetap tampilkan 25).
     *
     * Fungsi ini selalu resolve dulu ke username login pemilik SEBENARNYA:
     * 1) fast path -- kalau $pemilikKey sendiri persis USERNAME di tabel user
     *    (server lama/mode API biasa umumnya begini), pakai langsung.
     * 2) fallback -- cari lewat server.PEMILIK = $pemilikKey -> server.user_id
     *    -> user.USERNAME (pemilik sesungguhnya akun itu).
     * Kalau tetap tidak ketemu (data yatim), balikin $pemilikKey apa adanya
     * (perilaku lama, tidak lebih buruk dari sebelumnya).
     */
    function reminderSettingsResolveOwnerUsername($conn, string $pemilikKey): string
    {
        static $cache = [];
        if ($pemilikKey === '' || !$conn) {
            return $pemilikKey;
        }
        if (array_key_exists($pemilikKey, $cache)) {
            return $cache[$pemilikKey];
        }

        $resolved = $pemilikKey;

        $stmt = $conn->prepare('SELECT id FROM user WHERE USERNAME = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $pemilikKey);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$found) {
                $stmt2 = $conn->prepare('SELECT u.USERNAME FROM server s JOIN user u ON u.id = s.user_id WHERE s.PEMILIK = ? LIMIT 1');
                if ($stmt2) {
                    $stmt2->bind_param('s', $pemilikKey);
                    $stmt2->execute();
                    $row2 = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();
                    if ($row2 && !empty($row2['USERNAME'])) {
                        $resolved = (string)$row2['USERNAME'];
                    }
                }
            }
        }

        return $cache[$pemilikKey] = $resolved;
    }
}

if (!function_exists('reminderSettingsGetRow')) {
    function reminderSettingsGetRow($conn, string $pemilik): ?array
    {
        if (!$conn || $pemilik === '') {
            return null;
        }
        reminderSettingsEnsureTable($conn);
        reminderSettingsMigrateFromJsonIfNeeded($conn, $pemilik);

        $stmt = $conn->prepare('SELECT * FROM reminder_settings WHERE pemilik = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('reminderSettingsExists')) {
    /**
     * Beda dgn reminderSettingsGet() yg SELALU return default kalau belum ada
     * -- fungsi ini utk consumer yg perlu tahu PASTI apakah akun ini sudah
     * pernah setting Fixed Due Date sendiri atau belum (mis. activecustomer.php
     * yg mewajibkan setting ada utk mode Fixed Due Date).
     */
    function reminderSettingsExists($conn, string $pemilik): bool
    {
        return reminderSettingsGetRow($conn, $pemilik) !== null;
    }
}

if (!function_exists('reminderSettingsIsConfigured')) {
    /**
     * Beda dgn reminderSettingsExists() (cek baris DB ada atau tidak) --
     * fungsi ini cek spesifik apakah akun ini PERNAH eksplisit menyimpan
     * field inti Fixed Due Date (bukan cuma filter checkbox). Dipakai UI
     * admin (paymentset.php) supaya tidak menampilkan tabel "Konfigurasi
     * aktif" seolah-olah sudah pernah disetting padahal baris DB itu cuma
     * lahir dari save_filter_reminder.
     */
    function reminderSettingsIsConfigured($conn, string $pemilik): bool
    {
        $row = reminderSettingsGetRow($conn, $pemilik);
        return $row !== null && !empty($row['fixed_due_date_configured']);
    }
}

if (!function_exists('reminderSettingsGet')) {
    /**
     * Return semua 12 field, field yg belum diisi/baris belum ada diisi
     * default (reminderSettingsDefaults()) -- pemanggil TIDAK perlu null-check.
     *
     * Tipe data DIPAKSA persis spt file JSON lama (int sbg int, bool sbg bool
     * PHP asli, string sbg string) -- BUKAN sekadar ambil apa adanya dari
     * mysqli, krn mysqli bisa balikin angka sbg string tergantung driver, dan
     * kolom TINYINT bukan bool asli. Ini PENTING krn hasil fungsi ini dipakai
     * reminderSettingsSyncJsonMirror() utk nulis ulang notifbot/data/reminder-
     * <pemilik>.json yg masih dibaca 76+ file lain (cron & callback payment
     * gateway) -- kalau tipe datanya beda dari file lama (mis. "28" bukan 28,
     * atau 1 bukan true), berisiko lolos tapi diam-diam nyasar di consumer yg
     * kebetulan pakai perbandingan strict (===) atau is_int()/is_bool().
     */
    function reminderSettingsGet($conn, string $pemilik): array
    {
        $row = reminderSettingsGetRow($conn, $pemilik);
        $defaults = reminderSettingsDefaults();
        $boolCols = reminderSettingsBoolColumns();
        $strCols = reminderSettingsStringColumns();

        $merged = [];
        foreach ($defaults as $key => $defVal) {
            $merged[$key] = ($row && array_key_exists($key, $row) && $row[$key] !== null) ? $row[$key] : $defVal;
        }

        $out = [];
        foreach ($merged as $key => $val) {
            if (in_array($key, $boolCols, true)) {
                $out[$key] = (bool)$val;
            } elseif (in_array($key, $strCols, true)) {
                $out[$key] = (string)$val;
            } else {
                $out[$key] = (int)$val;
            }
        }
        return $out;
    }
}

if (!function_exists('reminderSettingsSyncJsonMirror')) {
    /**
     * Regenerate notifbot/data/reminder-<pemilik>.json dari isi DB saat ini,
     * format array-of-1-object PERSIS spt file lama (consumer lama ambil
     * $data[0][...]) -- dipanggil otomatis di akhir reminderSettingsSave(),
     * TIDAK PERNAH perlu dipanggil manual dari luar helper ini.
     *
     * PENTING: 8 field inti (reminderSettingsCoreColumns()) HANYA disertakan
     * kalau `fixed_due_date_configured` = true di DB (akun ini pernah eksplisit
     * simpan Fixed Due Date, bukan cuma filter checkbox) -- kalau tidak, key2
     * itu DIHILANGKAN dari mirror sama sekali (bukan diisi default). Ini WAJIB
     * supaya consumer lama yang mengecek isset($item['jatuh_tempo']) sbg
     * penanda "sudah setting Fixed Due Date" (non_aktif_tempo*.php,
     * notif_remainder_pembayaran*.php, validasi_isolir_harian.php -- lihat
     * komentar reminderSettingsCoreColumns()) tetap berperilaku identik dgn
     * sebelum migrasi ke DB.
     */
    function reminderSettingsSyncJsonMirror($conn, string $pemilik): void
    {
        $row = reminderSettingsGetRow($conn, $pemilik);
        $isConfigured = $row && !empty($row['fixed_due_date_configured']);

        $settings = reminderSettingsGet($conn, $pemilik);
        if (!$isConfigured) {
            foreach (reminderSettingsCoreColumns() as $coreCol) {
                unset($settings[$coreCol]);
            }
        }

        $payload = [$settings];
        $dir = __DIR__ . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(reminderSettingsFilePath($pemilik), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('reminderSettingsSave')) {
    /**
     * Partial upsert -- $fields cukup berisi kolom yg MAU diubah (subset dari
     * reminderSettingsKnownColumns(), key selain itu diabaikan/whitelist).
     * Kolom yg tidak disebut TIDAK ikut berubah kalau baris sudah ada (row
     * lama dipertahankan) -- WAJIB partial krn ada 4 titik simpan berbeda dgn
     * subset field berbeda (paymentset.php=10 field, notification_api.php
     * save_reminder_fixed_due_date=8 field, save_filter_reminder=2 field,
     * mobile API=8 field) -- replace-seluruh-baris akan menghapus field yg
     * diisi dari titik simpan lain. Baris baru (INSERT pertama kali) diisi
     * default (reminderSettingsDefaults()) utk kolom yg tidak disebut.
     *
     * Selalu sync ulang mirror JSON di akhir (lihat reminderSettingsSyncJsonMirror())
     * supaya 76+ file lain yg masih baca file itu langsung dapat data terbaru.
     */
    function reminderSettingsSave($conn, string $pemilik, array $fields): bool
    {
        if (!$conn || $pemilik === '') {
            return false;
        }
        reminderSettingsEnsureTable($conn);

        $known = reminderSettingsKnownColumns();
        $boolCols = reminderSettingsBoolColumns();
        $strCols = reminderSettingsStringColumns();
        $coreCols = reminderSettingsCoreColumns();

        $clean = [];
        $touchesCore = false;
        foreach ($fields as $col => $val) {
            if (!in_array($col, $known, true)) {
                continue;
            }
            if (in_array($col, $coreCols, true)) {
                $touchesCore = true;
            }
            if (in_array($col, $boolCols, true)) {
                $clean[$col] = $val ? 1 : 0;
            } elseif (in_array($col, $strCols, true)) {
                $clean[$col] = (string)$val;
            } else {
                $clean[$col] = (int)$val;
            }
        }
        if (empty($clean)) {
            return false;
        }
        if ($touchesCore) {
            // Sticky flag: sekali akun ini simpan field inti (jatuh_tempo dkk,
            // artinya lewat paymentset.php/notification_api.php/mobile API),
            // tandai permanen "sudah pernah setting Fixed Due Date" -- dipakai
            // reminderSettingsSyncJsonMirror() utk gating (lihat komentar
            // reminderSettingsCoreColumns()). Save filter-only TIDAK menyentuh
            // flag ini (biar tidak keliru dianggap "sudah setting Fixed Due Date"
            // padahal cuma toggle filter checkbox).
            $clean['fixed_due_date_configured'] = 1;
        }

        $exists = reminderSettingsExists($conn, $pemilik);
        $ok = false;

        if ($exists) {
            $setParts = [];
            $vals = [];
            $types = '';
            foreach ($clean as $col => $val) {
                $setParts[] = "`$col` = ?";
                $vals[] = $val;
                $types .= in_array($col, $strCols, true) ? 's' : 'i';
            }
            $vals[] = $pemilik;
            $types .= 's';

            $stmt = $conn->prepare('UPDATE reminder_settings SET ' . implode(', ', $setParts) . ' WHERE pemilik = ?');
            if ($stmt) {
                $refs = [];
                foreach ($vals as $k => $v) {
                    $refs[] = &$vals[$k];
                }
                array_unshift($refs, $types);
                call_user_func_array([$stmt, 'bind_param'], $refs);
                $ok = $stmt->execute();
                $stmt->close();
            }
        } else {
            $insertData = reminderSettingsDefaults();
            foreach ($clean as $col => $val) {
                $insertData[$col] = $val;
            }
            $cols = array_keys($insertData);
            $vals = array_values($insertData);
            $types = '';
            foreach ($cols as $col) {
                $types .= in_array($col, $strCols, true) ? 's' : 'i';
            }
            $colsSql = '`' . implode('`, `', $cols) . '`';
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));

            $stmt = $conn->prepare("INSERT INTO reminder_settings (pemilik, $colsSql) VALUES (?, $placeholders)");
            if ($stmt) {
                $bindTypes = 's' . $types;
                $bindVals = array_merge([$pemilik], $vals);
                $refs = [];
                foreach ($bindVals as $k => $v) {
                    $refs[] = &$bindVals[$k];
                }
                array_unshift($refs, $bindTypes);
                call_user_func_array([$stmt, 'bind_param'], $refs);
                $ok = $stmt->execute();
                $stmt->close();
            }
        }

        if ($ok) {
            reminderSettingsSyncJsonMirror($conn, $pemilik);
        }
        return (bool)$ok;
    }
}
