<?php
/**
 * radius_sync_lib.php
 *
 * Satu-satunya tempat yang boleh menulis ke file kredensial FreeRADIUS
 * (/etc/freeradius/3.0/users). Dipakai oleh:
 *   - notifbot/notifphp/sync_freeradius_users.php (cron sync massal tiap 30 menit)
 *   - proses/activecustomer.php (aktivasi manual per pelanggan)
 *   - proses/disablecustomer.php (nonaktifkan/isolir manual per pelanggan)
 *
 * Prinsip utama (supaya user RADIUS tidak pernah "hilang tanpa sebab"):
 *   1. TIDAK PERNAH mengosongkan/menimpa seluruh file di awal proses.
 *   2. Selalu baca isi file yang sekarang, hitung SELISIH (tambah/update/hapus)
 *      hanya untuk entri yang memang dikelola sistem ini, baru tulis ulang.
 *   3. Entri yang bukan dikelola sistem ini (mis. ditambah manual lewat UI
 *      radius.php, atau voucher hotspot yang tidak ikut proses ini) tidak
 *      pernah disentuh.
 *   4. Penulisan ke file dilakukan atomic (tulis ke file sementara lalu
 *      di-mv), supaya FreeRADIUS tidak pernah membaca file kosong/parsial.
 *   5. Ada file lock supaya dua proses sync tidak boleh menulis bersamaan.
 *   6. Setiap tambah/update/hapus dicatat ke log supaya bisa ditelusuri kalau
 *      ada laporan "user hilang" lagi di kemudian hari.
 */

if (!defined('RADIUS_USERS_FILE')) {
    define('RADIUS_USERS_FILE', '/etc/freeradius/3.0/users');
}
if (!defined('RADIUS_USERS_FILE_MIRROR')) {
    // Di instalasi FreeRADIUS 3 standar, /etc/freeradius/3.0/users HARUSNYA
    // cuma symlink ke file ini (yang benar-benar dibaca modul `files`). Kalau
    // symlink itu tidak ada/rusak di server, isi yang ditulis ke
    // RADIUS_USERS_FILE tidak pernah benar-benar dipakai untuk autentikasi
    // ("[files] = noop" di debug log walau user ada di /etc/freeradius/3.0/users).
    // Untuk menghindari ambiguitas itu tanpa perlu akses SSH untuk memastikan,
    // kita tulis isi yang SAMA ke DUA path ini sekaligus.
    define('RADIUS_USERS_FILE_MIRROR', '/etc/freeradius/3.0/mods-config/files/authorize');
}
if (!defined('RADIUS_MANAGED_STATE_FILE')) {
    define('RADIUS_MANAGED_STATE_FILE', '/etc/freeradius/managed_pelanggan.json');
}
if (!defined('RADIUS_SYNC_LOG_FILE')) {
    define('RADIUS_SYNC_LOG_FILE', '/var/log/freeradius/sync-decisions.log');
}
if (!defined('RADIUS_SYNC_LOCK_FILE')) {
    define('RADIUS_SYNC_LOCK_FILE', sys_get_temp_dir() . '/freeradius_sync.lock');
}

if (!function_exists('radiusSyncLog')) {
    function radiusSyncLog(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        $tmp = tempnam(sys_get_temp_dir(), 'rlog');
        file_put_contents($tmp, $line);
        shell_exec('sudo /bin/mkdir -p ' . escapeshellarg(dirname(RADIUS_SYNC_LOG_FILE)) . ' 2>/dev/null');
        shell_exec('sudo /usr/bin/tee -a ' . escapeshellarg(RADIUS_SYNC_LOG_FILE) . ' < ' . escapeshellarg($tmp) . ' > /dev/null 2>&1');
        @unlink($tmp);
    }
}

if (!function_exists('radiusReadManagedState')) {
    function radiusReadManagedState(): array
    {
        if (!file_exists(RADIUS_MANAGED_STATE_FILE)) {
            return [];
        }
        $raw = @file_get_contents(RADIUS_MANAGED_STATE_FILE);
        $data = $raw !== false ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('radiusWriteManagedState')) {
    function radiusWriteManagedState(array $state): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rstate');
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        shell_exec('sudo /bin/mkdir -p ' . escapeshellarg(dirname(RADIUS_MANAGED_STATE_FILE)) . ' 2>/dev/null');
        shell_exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg(RADIUS_MANAGED_STATE_FILE) . ' 2>&1');
        // tempnam() bikin file sementara mode 0600, dan "sudo cp" ikut meniru
        // mode itu ke file tujuan (walau kepemilikan jadi root) -- tanpa chmod
        // ini, radiusReadManagedState() (yang baca lewat file_get_contents()
        // biasa dari www-data, TANPA fallback sudo) akan gagal diam-diam kapan
        // pun, dianggap state kosong, dan bikin keputusan tambah/hapus user di
        // reconcile berikutnya salah.
        shell_exec('sudo /bin/chmod 644 ' . escapeshellarg(RADIUS_MANAGED_STATE_FILE) . ' 2>&1');
        @unlink($tmp);
    }
}

if (!function_exists('radiusParseUsersFile')) {
    /**
     * Pecah isi file users FreeRADIUS jadi daftar block terurut.
     * Block yang baris pertamanya cocok pola "username Cleartext-Password := ..."
     * dikenali by username; block lain tetap disimpan mentah (tidak pernah
     * diubah oleh reconcile, kecuali username-nya memang ada di $desired).
     */
    function radiusParseUsersFile(string $content): array
    {
        $blocks = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $current = [];

        $flush = function () use (&$blocks, &$current) {
            if (empty($current)) {
                return;
            }
            $raw = implode("\n", $current);
            $username = null;
            if (preg_match('/^(\S+)\s+Cleartext-Password\s*:=/', $current[0], $m)) {
                $username = $m[1];
            }
            $blocks[] = ['username' => $username, 'raw' => $raw];
            $current = [];
        };

        foreach ($lines as $line) {
            if (trim($line) === '') {
                $flush();
                continue;
            }
            $current[] = $line;
        }
        $flush();

        return $blocks;
    }
}

if (!function_exists('radiusSanitizeAttrValue')) {
    /**
     * Bersihkan nilai teks yang akan disisipkan DI DALAM tanda kutip pada
     * baris atribut reply RADIUS, mis. Mikrotik-Group := "<nilai>". Beda
     * dari validasi username/password (yang menolak/skip seluruh entri
     * kalau formatnya tidak aman), nilai di sini (nama paket, nama
     * address-list) boleh mengandung spasi/karakter lain yang wajar --
     * jadi cukup BUANG karakter yang secara sintaks bisa memutus string
     * literalnya (kutip, backslash, baris baru/CR), bukan tolak semua.
     * Sumbernya (tabel `paket`, pengaturan global panel FreeRADIUS) hanya
     * diisi admin, tapi tetap disaring di sini karena nilainya ikut ditulis
     * ke file users yang dipakai bersama SEMUA pelanggan.
     */
    function radiusSanitizeAttrValue(string $value): string
    {
        return str_replace(['"', '\\', "\r", "\n"], '', $value);
    }
}

if (!function_exists('radiusBuildUserBlock')) {
    /**
     * $replyAttrs = daftar baris atribut reply sudah terformat, mis.
     * 'Mikrotik-Group := "PAKET_10MBPS"'. Otomatis dipisah koma kecuali baris
     * terakhir (sesuai sintaks file "users" FreeRADIUS).
     */
    function radiusBuildUserBlock(string $username, string $password, array $replyAttrs): string
    {
        $out = "$username Cleartext-Password := \"$password\"";
        if (!empty($replyAttrs)) {
            $out .= "\n\t" . implode(",\n\t", $replyAttrs);
        }
        return $out;
    }
}

if (!function_exists('radiusReadMergedBlocks')) {
    /**
     * Baca dari KEDUA path (RADIUS_USERS_FILE dan RADIUS_USERS_FILE_MIRROR)
     * dan gabungkan blok-nya. Perlu, karena kita tidak tahu pasti mana yang
     * benar-benar dibaca modul `files` FreeRADIUS di server ini -- kalau
     * ternyata dua path itu sempat tidak sinkron, ini mencegah entri yang
     * cuma ada di salah satu sisi hilang begitu kita mulai menulis ke
     * keduanya.
     */
    function radiusReadMergedBlocks(): array
    {
        $contentPrimary = @file_get_contents(RADIUS_USERS_FILE);
        if ($contentPrimary === false) {
            $contentPrimary = (string) shell_exec('sudo /bin/cat ' . escapeshellarg(RADIUS_USERS_FILE) . ' 2>/dev/null');
        }
        $contentMirror = @file_get_contents(RADIUS_USERS_FILE_MIRROR);
        if ($contentMirror === false) {
            $contentMirror = (string) shell_exec('sudo /bin/cat ' . escapeshellarg(RADIUS_USERS_FILE_MIRROR) . ' 2>/dev/null');
        }

        $blocksMirror = radiusParseUsersFile($contentMirror);
        $blocksPrimary = radiusParseUsersFile($contentPrimary);

        // Mulai dari isi mirror (kemungkinan besar file yang benar-benar
        // dibaca modul `files`), lalu tambahkan blok dari primary yang belum
        // ada di mirror -- supaya tidak ada entri yang hilang dari sisi mana pun.
        $blocks = $blocksMirror;
        $seenUsernames = [];
        foreach ($blocks as $b) {
            if ($b['username'] !== null) {
                $seenUsernames[$b['username']] = true;
            }
        }
        foreach ($blocksPrimary as $b) {
            if ($b['username'] !== null) {
                if (isset($seenUsernames[$b['username']])) {
                    continue;
                }
                $seenUsernames[$b['username']] = true;
            } else {
                $duplicate = false;
                foreach ($blocks as $existing) {
                    if ($existing['username'] === null && trim($existing['raw']) === trim($b['raw'])) {
                        $duplicate = true;
                        break;
                    }
                }
                if ($duplicate) {
                    continue;
                }
            }
            $blocks[] = $b;
        }

        return $blocks;
    }
}

if (!function_exists('radiusWriteBlocksAtomic')) {
    /**
     * Tulis array block (hasil radiusReadMergedBlocks yang sudah diubah) ke
     * KEDUA path sekaligus, masing-masing lewat file sementara + mv atomic
     * (same-filesystem), supaya FreeRADIUS tidak pernah membaca file yang
     * kosong/setengah tertulis.
     */
    function radiusWriteBlocksAtomic(array $blocks): void
    {
        $newContent = '';
        foreach ($blocks as $b) {
            $newContent .= $b['raw'] . "\n\n";
        }

        // tempnam() SELALU membuat file sementara dengan mode 0600. "sudo cp"
        // (tanpa -p) tidak meniru kepemilikan sumbernya, TAPI tetap meniru mode
        // izinnya -- jadi tanpa chmod eksplisit di bawah, hasil akhirnya adalah
        // file 0600 milik root: bisa ditulis lagi oleh proses ini (root, lewat
        // sudo), tapi TIDAK BISA DIBACA oleh proses freeradius (biasanya jalan
        // sebagai user "freerad", bukan root). Modul `files` lalu gagal
        // instantiate ("Permission denied" / "Instantiation failed for module
        // files") dan FreeRADIUS gagal start SAMA SEKALI -- jauh lebih parah
        // daripada sekadar satu user gagal auth, karena mematikan auth untuk
        // SEMUA pelanggan.
        $tmpLocal = tempnam(sys_get_temp_dir(), 'radusers');
        file_put_contents($tmpLocal, $newContent);

        foreach ([RADIUS_USERS_FILE, RADIUS_USERS_FILE_MIRROR] as $targetPath) {
            $tmpRemote = $targetPath . '.new';
            $dir = dirname($targetPath);
            shell_exec('sudo /bin/mkdir -p ' . escapeshellarg($dir) . ' 2>/dev/null');
            // Paket FreeRADIUS Debian/Ubuntu biasanya nge-set
            // /etc/freeradius/3.0/mods-config (dan mods-config/files di
            // dalamnya) ke mode 750 root:freerad, karena itu tempat file
            // rahasia. chmod 644 di file saja TIDAK CUKUP kalau folder
            // induknya tidak bisa ditelusuri (execute bit) oleh www-data --
            // "Permission denied" tetap muncul walau file itu sendiri sudah
            // world-readable. Paksa folder-folder ini bisa ditelusuri semua
            // user supaya www-data (PHP) dan freerad (daemon) dua-duanya bisa
            // baca, sama seperti file users di root /etc/freeradius/3.0 yang
            // sudah di-chmod manual (lihat komentar di radius.php).
            shell_exec('sudo /bin/chmod 755 ' . escapeshellarg($dir) . ' 2>&1');
            shell_exec('sudo /bin/chmod 755 ' . escapeshellarg(dirname($dir)) . ' 2>&1');
            shell_exec('sudo /bin/cp ' . escapeshellarg($tmpLocal) . ' ' . escapeshellarg($tmpRemote) . ' 2>&1');
            shell_exec('sudo /bin/chmod 644 ' . escapeshellarg($tmpRemote) . ' 2>&1');
            shell_exec('sudo /bin/mv -f ' . escapeshellarg($tmpRemote) . ' ' . escapeshellarg($targetPath) . ' 2>&1');
        }
        @unlink($tmpLocal);
    }
}

if (!function_exists('radiusReconcileUsers')) {
    /**
     * $desired = [ username => ['password'=>string, 'reply'=>string[]] ]
     *
     * $removeMissing = true (dipakai HANYA oleh cron sync_freeradius_users.php):
     *   $desired HARUS berisi SEMUA username yang berhak ada saat ini dari
     *   SELURUH owner/area dalam satu kali panggilan, karena penghapusan
     *   dihitung dari selisih terhadap state yang dikelola sistem ini pada
     *   run sebelumnya -- username lama yang tidak ikut disebutkan akan
     *   dianggap "sudah tidak berhak" dan dihapus.
     *
     * $removeMissing = false (dipakai oleh aksi manual per satu pelanggan,
     *   mis. activecustomer.php/disablecustomer.php): HANYA menambah/
     *   mengupdate username yang disebutkan di $desired, tidak pernah
     *   menghapus username lain yang sedang dikelola. Aman dipanggil dengan
     *   $desired berisi satu pelanggan saja.
     *
     * Return: ['changed'=>bool,'added'=>[],'updated'=>[],'removed'=>[],'skipped_locked'=>bool]
     */
    function radiusReconcileUsers(array $desired, bool $removeMissing = true): array
    {
        $lockHandle = fopen(RADIUS_SYNC_LOCK_FILE, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            radiusSyncLog('SKIP: proses sync lain sedang berjalan (lock aktif), run ini dibatalkan supaya tidak tabrakan/menulis bersamaan.');
            if ($lockHandle) {
                fclose($lockHandle);
            }
            return ['changed' => false, 'added' => [], 'recovered' => [], 'updated' => [], 'removed' => [], 'skipped_locked' => true];
        }

        try {
            $blocks = radiusReadMergedBlocks();
            $managedPrev = radiusReadManagedState();

            $blockIndexByUsername = [];
            foreach ($blocks as $i => $b) {
                if ($b['username'] !== null) {
                    $blockIndexByUsername[$b['username']] = $i;
                }
            }

            $added = [];
            $recovered = [];
            $updated = [];
            $removed = [];
            $newManagedState = [];

            // 1) Tambah/update entri yang seharusnya ADA sekarang. Kalau
            //    pelanggan eligible (mode RADIUS/MULTI, lolos cek bayar) tapi
            //    entrinya TIDAK ada di file (mis. terhapus manual lewat UI,
            //    terhapus proses lain, atau memang hilang tanpa sebab), dia
            //    otomatis dibuat ulang di sini -- sama seperti pelanggan baru,
            //    bedanya cuma label log ("PULIHKAN" vs "TAMBAH") supaya kelihatan
            //    jelas kalau ini kasus "harusnya ada tapi sempat hilang".
            foreach ($desired as $username => $spec) {
                $username = (string) $username;
                $password = (string) ($spec['password'] ?? '');
                if ($password === '') {
                    radiusSyncLog("LEWATI user=$username: password kosong di database, tidak ditulis ke RADIUS supaya tidak membuat entri auth yang tidak aman.");
                    continue;
                }

                // Lapis pertahanan TERAKHIR (bukan cuma di form tambah/edit
                // pelanggan): fungsi ini dipanggil juga oleh cron sinkron massal
                // (sync_freeradius_users.php, baca username/password APA ADANYA
                // dari tabel pelanggan/voucher tanpa validasi apa pun) dan
                // activecustomer.php/disablecustomer.php. Kalau satu baris data
                // di database sempat "kotor" (mis. hasil import massal, edit
                // langsung lewat phpMyAdmin, atau bug di caller manapun) --
                // username berspasi/baris-baru atau password bertanda-kutip/
                // backslash TETAP tidak boleh sampai ditulis ke file users,
                // karena itu merusak sintaksnya dan mematikan auth utk SEMUA
                // pelanggan lain, bukan cuma satu entri kotor ini.
                if ($username === '' || strlen($username) > 64 || $username[0] === '#' || preg_match('/[\x00-\x20"\\\\\x7F]/', $username)) {
                    radiusSyncLog("LEWATI user=$username: format username tidak aman untuk file RADIUS (tidak boleh kosong/spasi/kutip-dua/backslash/diawali '#', maks 64 karakter) -- entri ini TIDAK ditulis/diupdate supaya tidak merusak file users.");
                    continue;
                }
                if (preg_match('/[\s"\\\\]/', $password)) {
                    radiusSyncLog("LEWATI user=$username: password mengandung spasi/tanda kutip/backslash, tidak aman ditulis ke file users RADIUS -- entri ini TIDAK ditulis/diupdate.");
                    continue;
                }

                $newBlock = radiusBuildUserBlock($username, $password, $spec['reply'] ?? []);
                $newManagedState[$username] = md5($newBlock);

                if (isset($blockIndexByUsername[$username])) {
                    $idx = $blockIndexByUsername[$username];
                    if (trim($blocks[$idx]['raw']) !== trim($newBlock)) {
                        $blocks[$idx]['raw'] = $newBlock;
                        $updated[] = $username;
                    }
                } elseif (isset($managedPrev[$username])) {
                    // Dulu dikelola sistem ini (seharusnya ada), tapi sekarang
                    // tidak ditemukan di file -- pulihkan.
                    $blocks[] = ['username' => $username, 'raw' => $newBlock];
                    $recovered[] = $username;
                } else {
                    $blocks[] = ['username' => $username, 'raw' => $newBlock];
                    $added[] = $username;
                }
            }

            // 2) Hapus HANYA entri yang dulu dikelola sistem ini tapi sekarang
            //    memang sudah tidak berhak lagi (dihapus dari DB, atau MODE-nya
            //    berubah dari RADIUS/MULTI). Entri lain tidak pernah disentuh.
            //    Dilewati sepenuhnya untuk pemanggilan partial ($removeMissing
            //    = false), supaya aksi manual 1 pelanggan tidak pernah menghapus
            //    pelanggan lain yang sedang dikelola.
            if ($removeMissing) {
                foreach ($managedPrev as $username => $prevHash) {
                    if (!array_key_exists($username, $desired) && isset($blockIndexByUsername[$username])) {
                        $idx = $blockIndexByUsername[$username];
                        unset($blocks[$idx]);
                        $removed[] = $username;
                    }
                }
            } else {
                // Gabungkan (bukan timpa) state yang dikelola: entri lama yang
                // tidak disebutkan di $desired tetap dipertahankan apa adanya.
                $newManagedState = array_merge($managedPrev, $newManagedState);
            }

            $changed = !empty($added) || !empty($recovered) || !empty($updated) || !empty($removed);

            if ($changed) {
                radiusWriteBlocksAtomic($blocks);

                radiusWriteManagedState($newManagedState);

                foreach ($added as $u) {
                    radiusSyncLog("TAMBAH user=$u (pelanggan baru masuk RADIUS)");
                }
                foreach ($recovered as $u) {
                    radiusSyncLog("PULIHKAN user=$u (masih pelanggan aktif mode RADIUS/MULTI di database, tapi entrinya TIDAK ditemukan di file users -- kemungkinan terhapus manual/proses lain. Dibuat ulang otomatis.)");
                }
                foreach ($updated as $u) {
                    radiusSyncLog("UPDATE user=$u (password/profile berubah di database)");
                }
                foreach ($removed as $u) {
                    radiusSyncLog("HAPUS user=$u (sudah tidak eligible: dihapus dari pelanggan atau MODE bukan RADIUS/MULTI lagi)");
                }
            } elseif ($newManagedState !== $managedPrev) {
                // Isi file tidak berubah tapi daftar yang dikelola berubah
                // (mis. hash sama tapi urutan key beda) -- tetap simpan state.
                radiusWriteManagedState($newManagedState);
            }

            return ['changed' => $changed, 'added' => $added, 'recovered' => $recovered, 'updated' => $updated, 'removed' => $removed, 'skipped_locked' => false];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

if (!function_exists('radiusUpsertUsers')) {
    /**
     * Helper untuk aksi manual per pelanggan (activecustomer.php,
     * disablecustomer.php, dll): tambah/update entri yang disebutkan TANPA
     * pernah menghapus pelanggan lain. Aman dipanggil dengan satu username.
     */
    function radiusUpsertUsers(array $desired): array
    {
        return radiusReconcileUsers($desired, false);
    }
}

if (!function_exists('radiusRemoveUsers')) {
    /**
     * Hapus entri username tertentu secara eksplisit (dipakai saat memang ada
     * alasan pasti untuk menghapus, mis. ganti username PPPoE saat edit
     * pelanggan -- entri lama harus dihapus, bukan dibiarkan jadi entri
     * "hantu"). Berbeda dari penghapusan implisit di radiusReconcileUsers
     * (yang menghapus berdasarkan selisih $desired vs state terkelola), fungsi
     * ini menghapus persis username yang diminta, terlepas dari status
     * "dikelola" sebelumnya.
     */
    function radiusRemoveUsers(array $usernames): array
    {
        $lockHandle = fopen(RADIUS_SYNC_LOCK_FILE, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            radiusSyncLog('SKIP (radiusRemoveUsers): proses sync lain sedang berjalan (lock aktif).');
            if ($lockHandle) {
                fclose($lockHandle);
            }
            return ['changed' => false, 'removed' => [], 'skipped_locked' => true];
        }

        try {
            $blocks = radiusReadMergedBlocks();
            $managedPrev = radiusReadManagedState();
            $removed = [];

            foreach ($blocks as $i => $b) {
                if ($b['username'] !== null && in_array($b['username'], $usernames, true)) {
                    unset($blocks[$i]);
                    $removed[] = $b['username'];
                }
            }

            $changed = !empty($removed);
            if ($changed) {
                radiusWriteBlocksAtomic($blocks);

                foreach ($removed as $u) {
                    unset($managedPrev[$u]);
                }
                radiusWriteManagedState($managedPrev);

                foreach ($removed as $u) {
                    radiusSyncLog("HAPUS user=$u (diminta eksplisit, mis. ganti username saat edit pelanggan)");
                }
            }

            return ['changed' => $changed, 'removed' => $removed, 'skipped_locked' => false];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

if (!function_exists('radiusReloadIfChanged')) {
    /**
     * TERBUKTI dari log lapangan: SIGHUP / `systemctl reload` TIDAK membuat
     * modul `files` FreeRADIUS 3 membaca ulang isi users/authorize -- modul
     * itu memuat daftar user-nya SEKALI SAJA saat proses start, bukan setiap
     * reload (beda dengan FreeRADIUS 2.x lama). Ini kebalikan dari asumsi
     * awal saya ("reload lembut supaya sesi tidak putus") -- di FreeRADIUS 3,
     * satu-satunya cara isi file baru benar-benar dipakai adalah RESTART
     * PENUH proses-nya, dan itu jugalah yang sudah dilakukan SEMUA fungsi
     * restartFreeradius() lain di aplikasi ini (radius.php, proses.php,
     * activecustomer.php, editcustomer.php, dst): stop service systemd, kill
     * proses lama, lalu jalankan ulang `freeradius -X` (mode debug, untuk
     * fitur Debug Terminal). Fungsi ini disamakan dengan pola itu supaya
     * konsisten dan benar-benar efektif, bukan cuma "kelihatan" reload.
     *
     * Konsekuensinya: sesi PPP yang sedang online BISA ikut terputus sesaat
     * (klien PPPoE biasanya otomatis reconnect) -- tapi ini price yang harus
     * dibayar karena FreeRADIUS 3 memang tidak punya hot-reload untuk modul
     * files. Auth yang gagal total (files=noop) jauh lebih buruk daripada
     * reconnect sebentar.
     */
    function radiusReloadIfChanged(bool $changed): ?string
    {
        if (!$changed) {
            return null;
        }

        $debugFile = '/var/log/freeradius/debug-radius-web.log';
        $pid = (int) trim((string) shell_exec('pidof freeradius'));
        if ($pid > 0) {
            shell_exec('sudo systemctl stop freeradius 2>&1');
            shell_exec('sudo kill -9 ' . $pid . ' 2>&1');
        }
        shell_exec('sudo /bin/rm -f ' . escapeshellarg($debugFile) . ' 2>&1');
        shell_exec('sudo /bin/touch ' . escapeshellarg($debugFile) . ' 2>&1');
        shell_exec('sudo /bin/chmod 666 ' . escapeshellarg($debugFile) . ' 2>&1');
        shell_exec('sudo freeradius -X > ' . escapeshellarg($debugFile) . ' 2>&1 &');

        $newPid = (int) trim((string) shell_exec('pidof freeradius'));
        $out = "restart penuh (PID lama=$pid, PID baru=$newPid)";
        radiusSyncLog("RESTART FreeRADIUS: $out");
        return $out;
    }
}

// =====================================================================
// Bagian di bawah ini ditambahkan untuk Panel Kontrol FreeRADIUS terpadu:
// pengaturan global (tab "Default"/"Filter") dan builder atribut reply
// PPPoE "RADIUS langsung" (Service-Type, Framed-Protocol, Mikrotik-Rate-Limit,
// Mikrotik-Address-List, Session-Timeout). Semua fungsi lama di atas TIDAK
// diubah signature-nya -- bagian ini murni tambahan.
// =====================================================================

if (!function_exists('radiusEnsureGlobalSettingsTable')) {
    /**
     * Bootstrap tabel `radius_global_settings` (satu baris, id=1) kalau belum
     * ada -- mengikuti pola migrasi inline yang sudah dipakai app ini (lihat
     * cek-sesi.php: SHOW COLUMNS/ALTER TABLE saat halaman diakses, bukan file
     * migration terpisah). Satu FreeRADIUS server melayani semua tenant di
     * app ini, jadi setting ini sengaja GLOBAL, bukan per-PEMILIK.
     */
    function radiusEnsureGlobalSettingsTable($conn): void
    {
        static $checkedThisRequest = false;
        if ($checkedThisRequest || !($conn instanceof mysqli)) {
            return;
        }
        $checkedThisRequest = true;

        $exists = @mysqli_query($conn, "SHOW TABLES LIKE 'radius_global_settings'");
        if ($exists && mysqli_num_rows($exists) > 0) {
            return;
        }

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS radius_global_settings (
            id TINYINT(1) UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            pppoe_radius_langsung_master_enabled TINYINT(1) NOT NULL DEFAULT 0,
            session_timeout_default INT UNSIGNED NOT NULL DEFAULT 86400,
            address_list_active VARCHAR(64) NOT NULL DEFAULT 'Pelanggan',
            address_list_expired VARCHAR(64) NOT NULL DEFAULT 'EXPIRED',
            filter_preset ENUM('reject_unknown','permissive_logged_only','custom') NOT NULL DEFAULT 'reject_unknown',
            accept_all_debug_enabled TINYINT(1) NOT NULL DEFAULT 0,
            accept_all_debug_enabled_at DATETIME NULL,
            accept_all_debug_enabled_by VARCHAR(100) NULL,
            accept_all_debug_disabled_at DATETIME NULL,
            accept_all_debug_disabled_by VARCHAR(100) NULL,
            updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) NULL
        ) ENGINE=InnoDB");
        mysqli_query($conn, "INSERT IGNORE INTO radius_global_settings (id) VALUES (1)");
    }
}

if (!function_exists('radiusEnsurePaketProfileSourceColumn')) {
    /**
     * Bootstrap kolom `paket.RADIUS_PROFILE_SOURCE` (opt-in per paket PPPoE:
     * 'MIKROTIK' = perilaku lama/default, bandwidth diatur PPP Profile di
     * router; 'RADIUS_LANGSUNG' = reply RADIUS memuat atribut lengkap dari
     * radiusBuildPppoeReplyAttrs()). Default 'MIKROTIK' supaya paket lama
     * tidak berubah perilaku sampai admin sengaja mengubahnya.
     */
    function radiusEnsurePaketProfileSourceColumn($conn): void
    {
        static $checkedThisRequest = false;
        if ($checkedThisRequest || !($conn instanceof mysqli)) {
            return;
        }
        $checkedThisRequest = true;

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM paket LIKE 'RADIUS_PROFILE_SOURCE'");
        if ($col && mysqli_num_rows($col) > 0) {
            return;
        }
        mysqli_query($conn, "ALTER TABLE paket ADD COLUMN RADIUS_PROFILE_SOURCE ENUM('MIKROTIK','RADIUS_LANGSUNG') NOT NULL DEFAULT 'MIKROTIK'");
    }
}

if (!function_exists('radiusGetGlobalSettings')) {
    /**
     * Baca pengaturan global tab Default/Filter. Selalu balikin array
     * asosiatif lengkap dengan fallback default kalau tabel/koneksi DB
     * bermasalah -- supaya caller (cron, addcustomer, dst) tetap dapat nilai
     * yang aman dipakai walau DB sedang bermasalah, bukan fatal error.
     */
    function radiusGetGlobalSettings($conn): array
    {
        $defaults = [
            'pppoe_radius_langsung_master_enabled' => 0,
            'session_timeout_default' => 86400,
            'address_list_active' => 'Pelanggan',
            'address_list_expired' => 'EXPIRED',
            'filter_preset' => 'reject_unknown',
            'accept_all_debug_enabled' => 0,
        ];

        if (!($conn instanceof mysqli)) {
            return $defaults;
        }

        radiusEnsureGlobalSettingsTable($conn);

        $res = @mysqli_query($conn, "SELECT * FROM radius_global_settings WHERE id = 1 LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!is_array($row)) {
            return $defaults;
        }

        return array_merge($defaults, $row);
    }
}

if (!function_exists('radiusSaveGlobalSettings')) {
    /**
     * Simpan pengaturan tab Default/Filter. $fields hanya perlu berisi
     * key yang mau diubah (partial update). accept_all_debug_enabled
     * ditangani khusus dengan jejak audit (kapan/siapa) karena opsi itu pada
     * dasarnya mematikan autentikasi RADIUS (auth bypass) di server sendiri.
     */
    function radiusSaveGlobalSettings($conn, array $fields, string $byUser): bool
    {
        if (!($conn instanceof mysqli)) {
            return false;
        }
        radiusEnsureGlobalSettingsTable($conn);

        $sets = [];

        if (array_key_exists('pppoe_radius_langsung_master_enabled', $fields)) {
            $sets[] = 'pppoe_radius_langsung_master_enabled = ' . ((int) (bool) $fields['pppoe_radius_langsung_master_enabled']);
        }
        if (array_key_exists('session_timeout_default', $fields)) {
            $timeout = max(0, (int) $fields['session_timeout_default']);
            $sets[] = "session_timeout_default = $timeout";
        }
        if (array_key_exists('address_list_active', $fields)) {
            $sets[] = "address_list_active = '" . mysqli_real_escape_string($conn, (string) $fields['address_list_active']) . "'";
        }
        if (array_key_exists('address_list_expired', $fields)) {
            $sets[] = "address_list_expired = '" . mysqli_real_escape_string($conn, (string) $fields['address_list_expired']) . "'";
        }
        if (array_key_exists('filter_preset', $fields)) {
            $allowedPresets = ['reject_unknown', 'permissive_logged_only', 'custom'];
            $preset = in_array($fields['filter_preset'], $allowedPresets, true) ? $fields['filter_preset'] : 'reject_unknown';
            $sets[] = "filter_preset = '" . $preset . "'";
        }

        // accept_all_debug_enabled: opsi permisif "terima semua user/password"
        // -- dicatat kapan/siapa yang mengubahnya (nyala maupun mati), dan
        // selalu diteruskan ke radiusSyncLog() supaya kelihatan di histori
        // sync yang sama dengan aktivitas RADIUS lainnya.
        if (array_key_exists('accept_all_debug_enabled', $fields)) {
            $enabled = (int) (bool) $fields['accept_all_debug_enabled'];
            $safeBy = mysqli_real_escape_string($conn, $byUser);
            $sets[] = "accept_all_debug_enabled = $enabled";
            if ($enabled) {
                $sets[] = "accept_all_debug_enabled_at = NOW()";
                $sets[] = "accept_all_debug_enabled_by = '$safeBy'";
            } else {
                $sets[] = "accept_all_debug_disabled_at = NOW()";
                $sets[] = "accept_all_debug_disabled_by = '$safeBy'";
            }
            radiusSyncLog(
                ($enabled ? 'AKTIFKAN' : 'NONAKTIFKAN') .
                " filter permisif 'Terima Semua User/Password (Testing/Debug)' oleh $byUser -- " .
                ($enabled
                    ? 'opsi ini MEMATIKAN autentikasi RADIUS (auth bypass) di server ini.'
                    : 'autentikasi normal RADIUS kembali berlaku.')
            );
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = "updated_by = '" . mysqli_real_escape_string($conn, $byUser) . "'";
        $sql = 'UPDATE radius_global_settings SET ' . implode(', ', $sets) . ' WHERE id = 1';
        return (bool) mysqli_query($conn, $sql);
    }
}

if (!function_exists('radiusBuildPppoeReplyAttrs')) {
    /**
     * Builder TUNGGAL untuk atribut reply RADIUS pelanggan PPPoE, dipakai
     * SEMUA caller (cron sync_freeradius_users.php, addcustomer.php,
     * editcustomer.php, customer_pppoe.php, script isolir) supaya hasilnya
     * selalu konsisten satu sama lain.
     *
     * $paketRow  = baris tabel `paket` (butuh PAKET, KECEPATAN,
     *              RADIUS_PROFILE_SOURCE -- key yang tidak ada dianggap
     *              default aman, jadi aman dipanggil walau kolom
     *              RADIUS_PROFILE_SOURCE belum sempat dibuat).
     * $sudahBayar = status tagihan pelanggan saat ini.
     * $globalSettings = hasil radiusGetGlobalSettings().
     *
     * Default (RADIUS_PROFILE_SOURCE='MIKROTIK' atau master toggle mati):
     * PERILAKU LAMA, tidak berubah -- cuma Mikrotik-Group, bandwidth diatur
     * PPP Profile di Mikrotik. Ini SENGAJA supaya paket existing tidak
     * terdampak sampai admin sengaja opt-in.
     */
    function radiusBuildPppoeReplyAttrs(array $paketRow, bool $sudahBayar, array $globalSettings, string $staticIp = ''): array
    {
        $paketNama = radiusSanitizeAttrValue((string) ($paketRow['PAKET'] ?? ''));
        // Framed-IP-Address (Customer Static IP) -- atribut RADIUS standar yang
        // dipahami Mikrotik utk paksa IP tetap ke sesi PPP walau auth via RADIUS
        // (setara "remote-address" di /ppp/secret API MODE). Dikirim SELALU kalau
        // $staticIp diisi, tidak peduli sudah/belum bayar, karena ini identitas IP
        // pelanggan bukan kontrol akses (kontrol akses tetap lewat Mikrotik-Group/
        // Mikrotik-Address-List seperti biasa).
        $staticIpTrim = trim($staticIp);
        $framedIpAttr = ($staticIpTrim !== '') ? ['Framed-IP-Address := ' . $staticIpTrim] : [];
        $addrListExpired = radiusSanitizeAttrValue((string) ($globalSettings['address_list_expired'] ?? 'EXPIRED'));
        $groupValue = $sudahBayar ? $paketNama : $addrListExpired;

        $profileSource = strtoupper((string) ($paketRow['RADIUS_PROFILE_SOURCE'] ?? 'MIKROTIK'));
        $masterEnabled = !empty($globalSettings['pppoe_radius_langsung_master_enabled']);

        if ($profileSource === 'RADIUS_LANGSUNG' && $masterEnabled) {
            $addrListActive = radiusSanitizeAttrValue((string) ($globalSettings['address_list_active'] ?? 'Pelanggan'));
            $sessionTimeout = max(0, (int) ($globalSettings['session_timeout_default'] ?? 86400));
            $addrList = $sudahBayar ? $addrListActive : $addrListExpired;

            $attrs = [
                'Service-Type := Framed-User',
                'Framed-Protocol := PPP',
            ];

            // Mikrotik-Rate-Limit HANYA dikirim kalau sudah bayar. Saat
            // menunggak, atribut ini SENGAJA DIHILANGKAN (bukan diisi angka
            // kecil) -- kalau tetap dikirim dengan kecepatan penuh paket,
            // pelanggan yang di-EXPIRED tetap dapat bandwidth normal karena
            // untuk profil RADIUS_LANGSUNG tidak ada objek PPP Profile
            // terpisah di router seperti pada profil MIKROTIK (yang kontrol
            // bandwidth-nya memang ada di profile "EXPIRED" itu sendiri).
            // Dengan rate-limit dihilangkan, pembatasan saat isolir jadi
            // tanggung jawab aturan firewall/queue di router yang mencocokkan
            // Mikrotik-Address-List=EXPIRED -- konsisten dengan cara kerja
            // profil MIKROTIK (kontrol ada di identitas "EXPIRED", bukan di
            // angka rate per-request).
            if ($sudahBayar) {
                $rate = trim((string) ($paketRow['KECEPATAN'] ?? ''));
                if ($rate !== '' && preg_match('/^\d+[kKmMgG]?\/\d+[kKmMgG]?$/', $rate)) {
                    $attrs[] = 'Mikrotik-Rate-Limit := "' . $rate . '"';
                }
                // Rate yang formatnya tidak dikenali (bukan "angka/angka")
                // sengaja DILEWATI (bukan dikirim mentah) -- reply attribute
                // dengan nilai aneh bisa bikin FreeRADIUS/Mikrotik menolak
                // seluruh reply, bukan cuma atribut itu saja.
            }

            $attrs[] = 'Mikrotik-Address-List := "' . $addrList . '"';
            $attrs[] = 'Session-Timeout := ' . $sessionTimeout;
            // Mikrotik-Group tetap disertakan juga -- getdata/getpackagefromradius.php
            // dan halaman lain membaca atribut ini untuk menampilkan "paket aktif",
            // jadi harus tetap ada walau bandwidth sekarang datang dari
            // Mikrotik-Rate-Limit, bukan dari profil Mikrotik bernama sama.
            $attrs[] = 'Mikrotik-Group := "' . $groupValue . '"';

            return array_merge($attrs, $framedIpAttr);
        }

        // Fallback: perilaku lama, tidak berubah (kecuali Framed-IP-Address
        // kalau $staticIp diisi -- default '' membuat ini backward-compatible
        // penuh untuk semua caller lama).
        return array_merge(['Mikrotik-Group := "' . $groupValue . '"'], $framedIpAttr);
    }
}

if (!function_exists('radiusSyncSingleCustomerNow')) {
    /**
     * Helper dipakai path manual (addcustomer.php, editcustomer.php,
     * customer_pppoe.php) supaya entry RADIUS pelanggan langsung fresh saat
     * itu juga (bukan menunggu cron 30 menit) -- terutama penting untuk
     * MULTI MODE: fallback Mikrotik->RADIUS bawaan RouterOS cuma bisa
     * bekerja kalau entry RADIUS-nya memang sudah ada & benar saat secret
     * lokal hilang.
     */
    function radiusSyncSingleCustomerNow(string $idpel, string $password, array $paketRow, bool $sudahBayar, array $globalSettings, string $staticIp = ''): array
    {
        $reply = radiusBuildPppoeReplyAttrs($paketRow, $sudahBayar, $globalSettings, $staticIp);
        $result = radiusUpsertUsers([$idpel => ['password' => $password, 'reply' => $reply]]);
        radiusReloadIfChanged(!empty($result['changed']));
        return $result;
    }
}

if (!defined('RADIUS_ACCEPT_ALL_MARKER')) {
    define('RADIUS_ACCEPT_ALL_MARKER', 'TESTING/DEBUG MODE -- dikelola Panel Kontrol FreeRADIUS, jangan edit manual');
}

if (!function_exists('radiusSetAcceptAllDebugMode')) {
    /**
     * Toggle mode "Terima Semua User/Password (Testing/Debug)" -- BUKAN
     * cuma flag database, tapi benar-benar menambah/menghapus satu block
     * `DEFAULT` di file users/authorize. FreeRADIUS modul `files` mencocokkan
     * block `DEFAULT` untuk username APA PUN yang tidak match block manapun
     * di atasnya, dan `Auth-Type := Accept` melewati pengecekan password sama
     * sekali -- jadi ini SECARA HARFIAH mematikan autentikasi RADIUS di
     * server ini selama aktif. Peringatan tegas WAJIB ditampilkan di UI
     * sebelum memanggil fungsi ini dengan $enabled=true.
     *
     * Aman dipanggil berkali-kali (idempotent): kalau block dengan marker
     * yang sama sudah ada, tidak ditambah dobel; kalau tidak ada, penghapusan
     * jadi no-op.
     */
    function radiusSetAcceptAllDebugMode(bool $enabled): array
    {
        $blocks = radiusReadMergedBlocks();
        $withoutMarker = [];
        $hadMarker = false;
        foreach ($blocks as $b) {
            if ($b['username'] === null && strpos($b['raw'], RADIUS_ACCEPT_ALL_MARKER) !== false) {
                $hadMarker = true;
                continue;
            }
            $withoutMarker[] = $b;
        }

        if ($enabled) {
            $defaultBlock = "DEFAULT Auth-Type := Accept\n"
                . "\tReply-Message := \"" . RADIUS_ACCEPT_ALL_MARKER . "\"";
            $withoutMarker[] = ['username' => null, 'raw' => $defaultBlock];
            $changed = true;
        } else {
            $changed = $hadMarker;
        }

        if ($changed) {
            radiusWriteBlocksAtomic($withoutMarker);
            radiusSyncLog($enabled
                ? 'AKTIFKAN block DEFAULT Auth-Type:=Accept (terima semua user/password) -- AUTH BYPASS aktif di server ini.'
                : 'HAPUS block DEFAULT Auth-Type:=Accept -- autentikasi normal RADIUS kembali berlaku.');
            radiusReloadIfChanged(true);
        }

        return ['changed' => $changed];
    }
}

if (!defined('RADIUS_CLIENTS_FILE')) {
    define('RADIUS_CLIENTS_FILE', '/etc/freeradius/3.0/clients.conf');
}

if (!function_exists('radiusReadClientsFileRaw')) {
    function radiusReadClientsFileRaw(): string
    {
        $content = @file_get_contents(RADIUS_CLIENTS_FILE);
        if ($content === false) {
            $content = (string) shell_exec('sudo /bin/cat ' . escapeshellarg(RADIUS_CLIENTS_FILE) . ' 2>/dev/null');
        }
        return (string) $content;
    }
}

if (!function_exists('radiusWriteClientsFileRaw')) {
    function radiusWriteClientsFileRaw(string $content): bool
    {
        $tmpLocal = tempnam(sys_get_temp_dir(), 'radclients');
        file_put_contents($tmpLocal, $content);
        $tmpRemote = RADIUS_CLIENTS_FILE . '.new';
        shell_exec('sudo /bin/cp ' . escapeshellarg($tmpLocal) . ' ' . escapeshellarg($tmpRemote) . ' 2>&1');
        shell_exec('sudo /bin/chmod 644 ' . escapeshellarg($tmpRemote) . ' 2>&1');
        shell_exec('sudo /bin/mv -f ' . escapeshellarg($tmpRemote) . ' ' . escapeshellarg(RADIUS_CLIENTS_FILE) . ' 2>&1');
        @unlink($tmpLocal);

        $verify = radiusReadClientsFileRaw();
        return trim($verify) === trim($content);
    }
}

if (!function_exists('radiusUpsertNasClient')) {
    /**
     * Tambah/perbarui satu block `client { ... }` di clients.conf berdasarkan
     * NAMA client (bukan IP -- supaya kalau IP router berubah, cukup panggil
     * ini lagi dengan nama yang sama untuk update, tidak bikin entry dobel).
     * Dipakai saat server baru disimpan dengan mode RADIUS_ONLY (lihat
     * proses/addserver.php & proses/editserver.php).
     */
    function radiusUpsertNasClient(string $name, string $ip, string $secret): bool
    {
        $content = radiusReadClientsFileRaw();
        $pattern = '/\n?client\s+' . preg_quote($name, '/') . '\s*\{[^}]*\}\n?/s';
        $content = (string) preg_replace($pattern, '', $content);

        $entry = "\nclient $name {\n\tipaddr = $ip\n\tsecret = $secret\n}\n";
        $newContent = rtrim($content) . "\n" . $entry;

        $ok = radiusWriteClientsFileRaw($newContent);
        if ($ok) {
            radiusSyncLog("NAS CLIENT: '$name' ($ip) ditambahkan/diperbarui di clients.conf (mode RADIUS_ONLY).");
        }
        return $ok;
    }
}

if (!function_exists('radiusRemoveNasClient')) {
    /**
     * Hapus block `client { ... }` berdasarkan nama -- dipakai saat server
     * dihapus atau modenya diganti kembali ke API (NAS client RADIUS_ONLY
     * tidak relevan lagi).
     */
    function radiusRemoveNasClient(string $name): bool
    {
        $content = radiusReadClientsFileRaw();
        $pattern = '/\n?client\s+' . preg_quote($name, '/') . '\s*\{[^}]*\}\n?/s';
        $newContent = (string) preg_replace($pattern, "\n", $content);
        if (trim($newContent) === trim($content)) {
            return true; // tidak ada perubahan, tidak perlu tulis ulang
        }
        $ok = radiusWriteClientsFileRaw($newContent);
        if ($ok) {
            radiusSyncLog("NAS CLIENT: '$name' dihapus dari clients.conf.");
        }
        return $ok;
    }
}

if (!function_exists('radiusGenerateSecret')) {
    /**
     * Generator secret RADIUS -- charset dibatasi alfanumerik supaya aman
     * di-copy-paste langsung ke terminal MikroTik tanpa perlu quoting
     * khusus (beda dari generateRandomPassword() di proses/addserver.php
     * yang sengaja pakai simbol untuk password admin Mikrotik).
     */
    function radiusGenerateSecret(int $length = 24): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }
}

if (!function_exists('radiusGenerateMikrotikScript')) {
    /**
     * Bangun skrip MikroTik copy-paste untuk mode "RADIUS SAJA" (tanpa
     * koneksi API sama sekali dari billing ke router ini). Mencakup PPPoE
     * dan Hotspot -- lihat dokumentasi tab "Default" di radius.php untuk
     * penjelasan atribut yang dikirim FreeRADIUS ke router lewat mode ini.
     */
    function radiusGenerateMikrotikScript(string $radiusServerIp, string $secret, string $area, string $brand): string
    {
        $lines = [];
        $lines[] = "# ============================================================";
        $lines[] = "# Skrip Konfigurasi RADIUS SAJA untuk MikroTik";
        $lines[] = "# Router: $brand - $area";
        $lines[] = "# Paste di New Terminal (WinBox/WebFig) sebagai admin router ini.";
        $lines[] = "# ============================================================";
        $lines[] = "";
        $lines[] = "# 1. Daftarkan server RADIUS billing sebagai sumber autentikasi";
        $lines[] = ":if ([:len [/radius find address=$radiusServerIp]] = 0) do={";
        $lines[] = "    /radius add service=ppp,hotspot,login address=$radiusServerIp secret=\"$secret\" disabled=no";
        $lines[] = "} else={";
        $lines[] = "    /radius set [find address=$radiusServerIp] service=ppp,hotspot,login secret=\"$secret\" disabled=no";
        $lines[] = "}";
        $lines[] = "";
        $lines[] = "# 2. Aktifkan RADIUS untuk PPP (PPPoE) -- WAJIB, ini yang membuat";
        $lines[] = "#    router memvalidasi PPPoE lewat RADIUS karena TIDAK ADA PPP";
        $lines[] = "#    secret lokal yang dibuat dari billing pada mode ini.";
        $lines[] = "/ppp aaa set use-radius=yes accounting=yes interim-update=00:05:00";
        $lines[] = "";
        $lines[] = "# 3. Aktifkan RADIUS untuk Hotspot (lewati baris ini kalau router";
        $lines[] = "#    ini tidak melayani hotspot voucher)";
        $lines[] = "/ip hotspot profile set [find] use-radius=yes radius-accounting=yes";
        $lines[] = "";
        $lines[] = "# ============================================================";
        $lines[] = "# Catatan penting:";
        $lines[] = "# - Pastikan router bisa menjangkau $radiusServerIp di port 1812";
        $lines[] = "#   (auth) dan 1813 (accounting) -- cek firewall antar jaringan.";
        $lines[] = "# - TIDAK ADA koneksi API dari billing ke router ini setelah skrip";
        $lines[] = "#   ini dijalankan -- semua kontrol paket/status pelanggan lewat";
        $lines[] = "#   atribut RADIUS (lihat Panel Kontrol FreeRADIUS > tab Default).";
        $lines[] = "# - SEMUA pelanggan di router ini WAJIB didaftarkan dengan Mode";
        $lines[] = "#   Autentikasi = \"RADIUS MODE\" (bukan API MODE/MULTI MODE) --";
        $lines[] = "#   tidak ada API untuk membuat PPP secret lokal di router ini.";
        $lines[] = "# - Kalau mau kontrol bandwidth per-paket lewat RADIUS langsung";
        $lines[] = "#   (bukan cuma nama grup), aktifkan \"Profil RADIUS Langsung\"";
        $lines[] = "#   di halaman Kelola Paket PPPoE untuk paket-paket router ini.";
        return implode("\n", $lines);
    }
}
