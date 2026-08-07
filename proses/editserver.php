<?php
require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../mikrotik_credentials_helper.php';

// Ambil konfigurasi lokal
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

// Sinkronkan entry RADIUS client + aktifkan use-radius di semua profil hotspot
// lewat satu koneksi API yang sudah terbuka. Dipakai baik untuk koneksi
// kredensial LAMA maupun kredensial BARU (setelah rotasi), supaya tidak
// duplikasi kode 2-3x seperti sebelumnya.
function syncRadiusAndHotspotProfiles($API, $config)
{
    $radius_address = $config['webiplocal'] ?? ($config['radius_ip'] ?? '');
    $existing_radius = $API->comm("/radius/print", ["?address" => $radius_address]);
    if (empty($existing_radius) || isset($existing_radius[0]["!trap"])) {
        $API->comm("/radius/add", [
            "service"  => "ppp,login,hotspot",
            "address"  => $radius_address,
            "secret"   => !empty($config['radius_password']) ? $config['radius_password'] : 'crmradius',
            "disabled" => "no"
        ]);
    }

    $profiles = $API->comm("/ip/hotspot/profile/print");
    if (!empty($profiles) && !isset($profiles[0]["!trap"])) {
        foreach ($profiles as $profile) {
            if (isset($profile[".id"])) {
                $API->comm("/ip/hotspot/profile/set", [
                    ".id"               => $profile[".id"],
                    "use-radius"        => "yes",
                    "radius-accounting" => "yes"
                ]);
            }
        }
    }
}

if (isset($_POST['edit']) && $_POST['edit'] == 'edit') {

    // ============================================================
    // 1. Ambil & sanitasi input
    // ============================================================
    $id         = intval($_POST['id']);
    $brand      = htmlspecialchars($_POST['brand']);
    $area       = htmlspecialchars($_POST['area']);
    $password   = htmlspecialchars($_POST['password']);   // password yang diketik admin di form
    $ipaddr     = htmlspecialchars(trim($_POST['ipaddr']));
    $portapi    = htmlspecialchars($_POST['portapi']);
    $portwebfig = htmlspecialchars($_POST['portwebfig'] ?? '80');
    $pemilik    = htmlspecialchars($_POST['pemilik']);    // username yang diketik admin di form
    $user_id    = $current_user_id;
    $connection_mode = ($_POST['connection_mode'] ?? 'API') === 'RADIUS_ONLY' ? 'RADIUS_ONLY' : 'API';
    $coordinates = trim((string)($_POST['coordinates'] ?? ''));
    if ($coordinates !== '' && !preg_match('/^-?\d{1,3}(\.\d+)?,-?\d{1,3}(\.\d+)?$/', $coordinates)) {
        $coordinates = '';
    }

    // ------------------------------------------------------------
    // Validasi IP address SEBELUM dipakai di exec() shell command
    // ------------------------------------------------------------
    if (!filter_var($ipaddr, FILTER_VALIDATE_IP)) {
        header("Location: ../server.php?status=gagal&msg=" . urlencode("? Format IP address tidak valid."));
        exit;
    }

    // Validasi port sebagai angka
    if (!ctype_digit((string)$portapi) || !ctype_digit((string)$portwebfig)) {
        header("Location: ../server.php?status=gagal&msg=" . urlencode("? Port API/Webfig harus berupa angka."));
        exit;
    }

    $ipPort = $ipaddr . ":" . $portapi;

    // ============================================================
    // 2. Ambil data LAMA dari DB sebelum apapun (termasuk LOGO supaya
    //    tidak hilang setelah delete+insert, dan TIKOR lama sebagai fallback)
    // ============================================================
    $stmt = mysqli_prepare($conn, "SELECT IP, PASSWORD, BRAND, AREA, PEMILIK, MIK80, CONNECTION_MODE, LOGO, TIKOR FROM server WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $old_data = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$old_data) {
        header("Location: ../server.php?status=gagal&msg=" . urlencode("? Data server dengan ID tersebut tidak ditemukan."));
        exit;
    }

    $old_ip       = $old_data['IP'];       // bisa berformat "ip:port"
    $old_password = $old_data['PASSWORD']; // password LAMA untuk koneksi MikroTik
    $old_brand    = $old_data['BRAND'];
    $old_area     = $old_data['AREA'];
    $old_pemilik  = $old_data['PEMILIK'];  // username LAMA untuk koneksi MikroTik
    $old_connection_mode = $old_data['CONNECTION_MODE'] ?? 'API';
    $old_logo     = $old_data['LOGO'] ?? null;

    // Kalau admin tidak menyentuh peta koordinat sama sekali (field dikirim
    // kosong), pertahankan TIKOR lama alih-alih menghapusnya.
    if ($coordinates === '') {
        $coordinates = $old_data['TIKOR'] ?? '';
    }

    // Parse IP lama jika ada port di dalamnya
    $old_ip_parts  = explode(':', $old_ip);
    $old_ip_only   = $old_ip_parts[0];
    $old_port_only = $old_ip_parts[1] ?? '8728';

    // ============================================================
    // 3. Tentukan apakah admin betul-betul minta ganti username/password
    //    Mikrotik (bukan sekadar IP/port/area/brand -- itu boleh langsung
    //    ke database tanpa urusan Mikrotik sama sekali).
    // ============================================================
    $credential_change_requested = (
        $connection_mode === 'API' &&
        $old_connection_mode === 'API' &&
        ($pemilik !== $old_pemilik || $password !== $old_password)
    );

    $mikrotik_ok        = false;
    $mikrotik_error     = '';
    $credential_rotated = false;
    $final_pemilik      = $pemilik;
    $final_password     = $password;

    if ($old_connection_mode === 'RADIUS_ONLY' || $connection_mode !== 'API') {
        // Tidak ada API lama yang valid untuk disinkron (RADIUS_ONLY lama
        // memakai PASSWORD sebagai secret RADIUS, bukan login Mikrotik asli),
        // atau mode baru bukan API -- lewati semua urusan Mikrotik.
        $mikrotik_ok = true;
    } elseif ($credential_change_requested) {
        // ========================================================
        // Admin minta ganti username/password Mikrotik.
        // ========================================================
        $isOnline = isMikrotikHostReachable($old_ip_only, (int) $old_port_only, 3);

        if ($isOnline) {
            // --- ONLINE: wajib verifikasi kredensial LAMA dulu, baru buat
            //     user BARU (auto-generate) di Mikrotik. Kalau gagal di titik
            //     manapun, SELURUH perubahan edit dibatalkan (tidak ada yang
            //     disimpan ke database).
            $API = new RouterosAPI();
            if (!$API->connect($old_ip, $old_pemilik, $old_password)) {
                header("Location: ../server.php?status=gagal&msg=" . urlencode("? Server terdeteksi ONLINE tapi gagal login ke MikroTik dengan kredensial LAMA ($old_pemilik). Kemungkinan password lama sudah tidak valid / sudah diubah manual di router. Seluruh perubahan DIBATALKAN, tidak ada yang disimpan."));
                exit;
            }

            syncRadiusAndHotspotProfiles($API, $config);

            $credGenBase = $brand . '_' . str_replace(' ', '_', $area);
            $new_credentials = generateMikrotikCredentials($credGenBase);
            $new_username = $new_credentials['username'];
            $new_password = $new_credentials['password'];
            $genAttempt = 0;
            while ($genAttempt < 10 && !validateUniqueOwner($conn, $new_username)) {
                $new_credentials = generateMikrotikCredentials($credGenBase);
                $new_username = $new_credentials['username'];
                $new_password = $new_credentials['password'];
                $genAttempt++;
            }
            if ($genAttempt >= 10) {
                $API->disconnect();
                header("Location: ../server.php?status=gagal&msg=" . urlencode("? Gagal generate username unik untuk kredensial baru. Seluruh perubahan DIBATALKAN."));
                exit;
            }

            try {
                createMikrotikSystemUser($API, $new_username, $new_password);
            } catch (Exception $e) {
                $API->disconnect();
                header("Location: ../server.php?status=gagal&msg=" . urlencode("? Konek ke MikroTik lama berhasil, tapi GAGAL membuat user baru di router: " . $e->getMessage() . ". Seluruh perubahan DIBATALKAN."));
                exit;
            }
            $API->disconnect();

            $testAPI = new RouterosAPI();
            if (!$testAPI->connect($ipPort, $new_username, $new_password)) {
                header("Location: ../server.php?status=gagal&msg=" . urlencode("? User baru berhasil dibuat di MikroTik tapi test login dengan kredensial baru GAGAL. Seluruh perubahan DIBATALKAN, silakan cek manual ke router."));
                exit;
            }
            syncRadiusAndHotspotProfiles($testAPI, $config);
            $testAPI->disconnect();

            $final_pemilik      = $new_username;
            $final_password     = $new_password;
            $credential_rotated = true;
            $mikrotik_ok        = true;
        } else {
            // --- OFFLINE: tidak ada cara verifikasi ke router, jadi langsung
            //     pakai persis username/password yang diketik admin di form,
            //     simpan ke database tanpa kontak ke Mikrotik sama sekali.
            $mikrotik_ok    = false;
            $mikrotik_error = "Server terdeteksi OFFLINE ($old_ip) -- kredensial baru langsung disimpan ke database tanpa verifikasi ke MikroTik.";
        }
    } else {
        // ========================================================
        // Tidak ada perubahan username/password Mikrotik yang diminta
        // (mungkin cuma ubah IP/port/area/brand) -- sinkron best-effort
        // pakai kredensial yang sama, TIDAK blocking kalau gagal.
        // ========================================================
        $API = new RouterosAPI();
        if ($API->connect($old_ip, $old_pemilik, $old_password)) {
            $mikrotik_ok = true;
            syncRadiusAndHotspotProfiles($API, $config);
            $API->disconnect();
        } else {
            $mikrotik_error = "Gagal koneksi ke MikroTik lama ($old_ip) dengan user $old_pemilik.";
        }
    }

    // ============================================================
    // 4. Hapus data lama dari database berdasarkan ID
    //    (baru sampai sini kalau tidak ada abort di atas)
    // ============================================================
    $stmt = mysqli_prepare($conn, "DELETE FROM server WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // ============================================================
    // 5. Hapus client lama dari FreeRADIUS
    // ============================================================
    exec("sudo sed -i '/client " . $old_ip_only . " {/,/}/d' /etc/freeradius/3.0/clients.conf");
    exec("sudo systemctl restart freeradius");

    // ============================================================
    // 6. Insert ulang data server baru ke database (prepared statement)
    //    Untuk RADIUS_ONLY, kolom IP disimpan TANPA port API.
    //    LOGO dibawa dari data lama (upload logo server ditangani terpisah
    //    lewat proses/upload_server_logo.php, bukan dari form ini).
    // ============================================================
    $ip_to_store = ($connection_mode === 'RADIUS_ONLY') ? $ipaddr : $ipPort;
    $stmt = mysqli_prepare($conn, "INSERT INTO `server`
            (`IP`, `PASSWORD`, `AREA`, `MIK80`, `PEMILIK`, `BRAND`, `user_id`, `CONNECTION_MODE`, `LOGO`, `TIKOR`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param(
        $stmt,
        "ssssssisss",
        $ip_to_store,
        $final_password,
        $area,
        $portwebfig,
        $final_pemilik,
        $brand,
        $user_id,
        $connection_mode,
        $old_logo,
        $coordinates
    );
    $insert_ok = mysqli_stmt_execute($stmt);
    $new_server_id = $insert_ok ? mysqli_insert_id($conn) : 0;
    mysqli_stmt_close($stmt);

    // ============================================================
    // 6b. Kelola NAS Client di FreeRADIUS untuk mode RADIUS_ONLY.
    // ============================================================
    if ($insert_ok && $connection_mode === 'RADIUS_ONLY') {
        radiusUpsertNasClient($ipaddr, $ipaddr, $final_password);
        radiusReloadIfChanged(true);
    }

    if ($insert_ok) {

        // ========================================================
        // 7. Update tabel relasi setelah insert berhasil -- pakai
        //    $final_pemilik (bukan $pemilik mentah dari form) supaya
        //    tetap konsisten kalau kredensial baru saja di-rotasi.
        // ========================================================
        $relasi_updates = [
            ["UPDATE odp SET brand=?, area=?, pemilik=? WHERE brand=? AND area=? AND pemilik=?", "ssssss"],
            ["UPDATE paket SET brand=?, area=?, pemilik=? WHERE brand=? AND area=? AND pemilik=?", "ssssss"],
            ["UPDATE paket_hotspot SET brand=?, area=?, pemilik=? WHERE brand=? AND area=? AND pemilik=?", "ssssss"],
            ["UPDATE pelanggan SET brand=?, area=?, pemilik=? WHERE brand=? AND area=? AND pemilik=?", "ssssss"],
        ];

        $relasi_gagal = [];

        foreach ($relasi_updates as $idx => $q) {
            list($query, $types) = $q;
            $stmt = mysqli_prepare($conn, $query);
            if (!$stmt) {
                $relasi_gagal[] = "query #$idx (" . mysqli_error($conn) . ")";
                continue;
            }
            mysqli_stmt_bind_param($stmt, $types, $brand, $area, $final_pemilik, $old_brand, $old_area, $old_pemilik);
            if (!mysqli_stmt_execute($stmt)) {
                $relasi_gagal[] = "query #$idx (" . mysqli_stmt_error($stmt) . ")";
            }
            mysqli_stmt_close($stmt);
        }

        // Tabel olt tidak punya kolom brand, query terpisah
        $stmt = mysqli_prepare($conn, "UPDATE olt SET area=?, pemilik=? WHERE area=? AND pemilik=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ssss", $area, $final_pemilik, $old_area, $old_pemilik);
            if (!mysqli_stmt_execute($stmt)) {
                $relasi_gagal[] = "olt (" . mysqli_stmt_error($stmt) . ")";
            }
            mysqli_stmt_close($stmt);
        } else {
            $relasi_gagal[] = "olt (" . mysqli_error($conn) . ")";
        }

        // ========================================================
        // 8. Log history
        // ========================================================
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) {
            $history = [];
        }

        $actor = (!empty($asistant_name) ? $asistant_name : $ceknama);

        if ($credential_rotated) {
            $status_text = "kredensial Mikrotik BARU berhasil di-generate & diverifikasi ke router (username baru: $final_pemilik)";
        } elseif ($credential_change_requested && !$mikrotik_ok) {
            $status_text = "server OFFLINE, kredensial baru langsung disimpan ke database TANPA verifikasi ke Mikrotik";
        } elseif ($mikrotik_ok) {
            $status_text = "lengkap (MikroTik tersinkron)";
        } else {
            $status_text = "sebagian (DB tersinkron, MikroTik gagal: $mikrotik_error)";
        }

        $history[] = "[ $actor - " . date('Y-m-d H:i:s') . " ] Edit server $final_pemilik area $area - status: $status_text";
        if (!empty($relasi_gagal)) {
            $history[] = "[ $actor - " . date('Y-m-d H:i:s') . " ] Peringatan: gagal update relasi: " . implode(', ', $relasi_gagal);
        }
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        // ========================================================
        // 9. Redirect sesuai hasil akhir
        // ========================================================
        if ($connection_mode === 'RADIUS_ONLY' && empty($relasi_gagal)) {
            header("Location: ../server.php?status=sukses&msg=" . urlencode("Server $brand ($ipaddr) berhasil diperbarui dengan mode RADIUS SAJA.") . "&radius_script_for=" . $new_server_id);
            exit;
        }
        if ($credential_rotated && empty($relasi_gagal)) {
            header("Location: ../server.php?status=sukses&msg=" . urlencode("? Server $brand ($ipaddr) berhasil diperbarui. Kredensial Mikrotik baru (auto-generate) berhasil dibuat & diverifikasi: $final_pemilik."));
            exit;
        }
        if ($credential_change_requested && !$mikrotik_ok && empty($relasi_gagal)) {
            header("Location: ../server.php?status=sukses&msg=" . urlencode("?? Server $brand ($ipaddr) berhasil diperbarui. Server OFFLINE -- kredensial baru langsung disimpan ke database TANPA verifikasi ke MikroTik. Pastikan router memakai username/password yang SAMA saat online kembali."));
            exit;
        }
        if ($mikrotik_ok && empty($relasi_gagal)) {
            header("Location: ../server.php?status=sukses&msg=" . urlencode("? Server $brand ($ipaddr) berhasil diperbarui & disinkronisasi ulang sepenuhnya."));
            exit;
        } elseif (!$mikrotik_ok) {
            header("Location: ../server.php?status=gagal&msg=" . urlencode("?? Data server & relasi (odp/paket/dll) sudah diperbarui di database, tapi GAGAL koneksi ke MikroTik ($old_ip). Pastikan MikroTik aktif dan credential masih valid."));
            exit;
        } else {
            header("Location: ../server.php?status=gagal&msg=" . urlencode("?? Server diperbarui & MikroTik tersinkron, namun ada relasi yang gagal diupdate: " . implode(', ', $relasi_gagal)));
            exit;
        }

    } else {
        // Gagal simpan ke database
        header("Location: ../server.php?status=gagal&msg=" . urlencode("? Gagal menyimpan data server ke database."));
        exit;
    }
}
