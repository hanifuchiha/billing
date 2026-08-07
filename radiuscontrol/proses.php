<?php
// Guard akses: endpoint ini bisa reset SEMUA user RADIUS, timpa
// sites-available/default, dan restart FreeRADIUS berkali-kali -- sebelumnya
// TIDAK ADA pengecekan sesi/role sama sekali di sini, jadi siapa pun yang tahu
// URL-nya (tanpa login) bisa memicu semua aksi itu langsung lewat POST. Menu
// sidebar memang dibatasi $AKSES=="ADMIN", tapi itu cuma menyembunyikan link,
// bukan proteksi server-side untuk endpoint ini sendiri.
require_once __DIR__ . '/../cek-sesi.php';
if (($AKSES ?? '') !== 'ADMIN') {
    http_response_code(403);
    die('Akses ditolak: pengaturan FreeRADIUS khusus untuk ADMIN.');
}
require_once __DIR__ . '/../radius_sync_lib.php';

// ================= Config =================
$clients_file = '/etc/freeradius/3.0/clients.conf';
$users_file   = '/etc/freeradius/3.0/users';
$debug_file   = '/var/log/freeradius/debug-radius-web.log';

// ================= Fungsi =================
function getFreeradiusPID() {
    $pid = trim(shell_exec("pidof freeradius"));
    if($pid != '') return (int)$pid;
    $output = shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function restartFreeradius() {
    global $debug_file;
    $pid = getFreeradiusPID();
    if($pid>0){
        shell_exec('sudo systemctl stop freeradius');
        shell_exec("sudo kill -9 $pid");
    }
    if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    shell_exec("sudo touch $debug_file");
    shell_exec("sudo chmod 666 $debug_file");
    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
}

/**
 * Tulis file config FreeRADIUS SELALU lewat sudo (cp ke file sementara +
 * mv atomic), TIDAK PERNAH file_put_contents() langsung. Sebelumnya
 * beberapa handler di file ini menulis langsung dengan file_put_contents(),
 * yang GAGAL DIAM-DIAM (tanpa pesan error apa pun) kalau www-data tidak
 * punya izin tulis ke path di /etc/freeradius -- efeknya: perubahan yang
 * disimpan lewat UI kelihatan berhasil (redirect balik ke halaman), tapi isi
 * file di server tidak pernah benar-benar berubah, dan FreeRADIUS tetap
 * jalan dengan config lama. Ini pernah bikin tambahan blok
 * "Auth-Type MS-CHAP { mschap }" di file default tidak pernah aktif walau
 * sudah "disimpan" berkali-kali lewat Config Editor.
 *
 * Return true kalau isi file di disk benar-benar berubah sesuai $content
 * setelah ditulis (diverifikasi ulang, bukan cuma asumsi shell_exec sukses).
 */
function sudoWriteFile(string $path, string $content): bool
{
    $tmpLocal = tempnam(sys_get_temp_dir(), 'radcfg');
    file_put_contents($tmpLocal, $content);

    $tmpRemote = $path . '.new';
    $dir = dirname($path);
    shell_exec('sudo /bin/mkdir -p ' . escapeshellarg($dir) . ' 2>/dev/null');
    // Paket FreeRADIUS Debian/Ubuntu biasanya nge-set
    // /etc/freeradius/3.0/mods-config (dan mods-config/files di dalamnya) ke
    // mode 750 root:freerad, karena itu tempat file rahasia. chmod 644 di
    // file saja TIDAK CUKUP kalau folder induknya tidak bisa ditelusuri
    // (execute bit) oleh www-data -- "Permission denied" tetap muncul walau
    // file itu sendiri sudah world-readable.
    shell_exec('sudo /bin/chmod 755 ' . escapeshellarg($dir) . ' 2>&1');
    shell_exec('sudo /bin/chmod 755 ' . escapeshellarg(dirname($dir)) . ' 2>&1');
    shell_exec('sudo /bin/cp ' . escapeshellarg($tmpLocal) . ' ' . escapeshellarg($tmpRemote) . ' 2>&1');
    // tempnam() bikin $tmpLocal mode 0600, dan "sudo cp" (tanpa -p) tetap ikut
    // meniru mode izin sumbernya walau kepemilikan hasilnya jadi root -- tanpa
    // chmod ini, file config (mis. mods-config/files/authorize) berakhir jadi
    // 0600 milik root, tidak bisa dibaca proses freeradius (user "freerad"),
    // dan modul `files` gagal instantiate saat start ("Permission denied").
    shell_exec('sudo /bin/chmod 644 ' . escapeshellarg($tmpRemote) . ' 2>&1');
    shell_exec('sudo /bin/mv -f ' . escapeshellarg($tmpRemote) . ' ' . escapeshellarg($path) . ' 2>&1');
    @unlink($tmpLocal);

    // Verifikasi: baca ulang (lewat sudo cat kalau perlu) dan bandingkan.
    $verify = @file_get_contents($path);
    if ($verify === false) {
        $verify = (string) shell_exec('sudo /bin/cat ' . escapeshellarg($path) . ' 2>/dev/null');
    }

    return trim((string) $verify) === trim($content);
}

/**
 * Backup file lama lewat sudo cp (bukan PHP copy(), yang juga bisa gagal
 * diam-diam kalau www-data tidak punya izin baca/tulis langsung ke path itu).
 */
function sudoBackupFile(string $path): string
{
    $backupPath = $path . '.bak.' . time();
    shell_exec('sudo /bin/cp ' . escapeshellarg($path) . ' ' . escapeshellarg($backupPath) . ' 2>&1');
    return $backupPath;
}

function redirectWithMessage(string $text): void
{
    header('Location: ../radius.php?text=' . urlencode($text));
    exit;
}

/**
 * Tulis isi file users ke $users_file DAN ke RADIUS_USERS_FILE_MIRROR
 * (/etc/freeradius/3.0/mods-config/files/authorize) sekaligus -- lihat
 * komentar RADIUS_USERS_FILE_MIRROR di radius_sync_lib.php. Kita tidak tahu
 * pasti mana yang benar-benar dibaca modul `files` FreeRADIUS di server ini,
 * jadi ditulis ke keduanya supaya tidak ambigu.
 */
function writeUsersFileBoth(string $usersFilePath, string $content): bool
{
    $ok = sudoWriteFile($usersFilePath, $content);
    if (defined('RADIUS_USERS_FILE_MIRROR') && RADIUS_USERS_FILE_MIRROR !== $usersFilePath) {
        sudoWriteFile(RADIUS_USERS_FILE_MIRROR, $content);
    }
    return $ok;
}

// ================= Toggle FreeRADIUS =================
if(isset($_POST['freeradius_toggle'])){
    $pid = getFreeradiusPID();
    $status = ($pid>0)?'active':'inactive';
    if(isset($_POST['freeradius']) && $status!='active'){
        restartFreeradius();
    } elseif(!isset($_POST['freeradius']) && $status=='active'){
        shell_exec('sudo systemctl stop freeradius');
        $pid = getFreeradiusPID();
        if($pid>0) shell_exec("sudo kill -9 $pid");
        if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    }
    header("Location: ../radius.php"); exit;
}

// ================= Clear Debug Log =================
if(isset($_POST['clear_log'])){
    if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    restartFreeradius();
    header("Location: ../radius.php"); exit;
}

// ================= Add Client =================
if(isset($_POST['add_client'])){
    $name = trim($_POST['name']);
    $ip   = trim($_POST['ip']);
    $secret = trim($_POST['secret']);
    $entry = "\nclient $name {\n\tipaddr = $ip\n\tsecret = $secret\n}\n";

    $current = @file_get_contents($clients_file);
    if ($current === false) {
        $current = (string) shell_exec('sudo /bin/cat ' . escapeshellarg($clients_file) . ' 2>/dev/null');
    }
    $ok = sudoWriteFile($clients_file, $current . $entry);
    if (!$ok) {
        redirectWithMessage("❌ Gagal menyimpan client '$name': tidak bisa menulis ke $clients_file (cek izin sudo www-data).");
    }
    restartFreeradius();
    header("Location: ../radius.php"); exit;
}

// ================= Delete Client =================
if(isset($_POST['delete_client'])){
    $del = trim($_POST['delete_client']);
    $content = @file_get_contents($clients_file);
    if ($content === false) {
        $content = (string) shell_exec('sudo /bin/cat ' . escapeshellarg($clients_file) . ' 2>/dev/null');
    }
    $pattern = "/client\s+" . preg_quote($del, '/') . "\s*\{[^\}]+\}/s";
    $content = preg_replace($pattern,'',$content);
    $ok = sudoWriteFile($clients_file, $content);
    if (!$ok) {
        redirectWithMessage("❌ Gagal menghapus client '$del': tidak bisa menulis ke $clients_file (cek izin sudo www-data).");
    }
    restartFreeradius();
    header("Location: ../radius.php"); exit;
}

// ================= Add User =================
if(isset($_POST['add_user'])){
    $user = trim($_POST['user']);
    $pass = trim($_POST['pass']);
    $group = trim($_POST['group']);
    // Atribut lanjutan opsional (dikosongkan = tidak disertakan di reply) --
    // supaya user manual yang dibuat lewat panel ini bisa punya atribut
    // lengkap seperti profil PPPoE RADIUS-langsung (lihat contoh di
    // dokumentasi tab Default), bukan cuma Mikrotik-Group.
    $service_type = trim($_POST['service_type'] ?? '');
    $framed_protocol = trim($_POST['framed_protocol'] ?? '');
    $rate_limit = trim($_POST['rate_limit'] ?? '');
    $address_list = trim($_POST['address_list'] ?? '');
    $session_timeout = trim($_POST['session_timeout'] ?? '');

    // Cek duplikat lewat radiusReadMergedBlocks() (baca KEDUA path
    // users+mirror) -- bukan file_get_contents() satu file saja, supaya
    // user yang cuma ada di salah satu path tidak tertimpa tanpa sadar.
    $blocks = radiusReadMergedBlocks();
    foreach ($blocks as $b) {
        if ($b['username'] === $user) {
            redirectWithMessage("❌ User '$user' sudah ada di RADIUS.");
        }
    }

    $replyAttrs = [];
    if ($service_type !== '') $replyAttrs[] = "Service-Type := $service_type";
    if ($framed_protocol !== '') $replyAttrs[] = "Framed-Protocol := $framed_protocol";
    if ($rate_limit !== '') $replyAttrs[] = 'Mikrotik-Rate-Limit := "' . $rate_limit . '"';
    if ($address_list !== '') $replyAttrs[] = 'Mikrotik-Address-List := "' . $address_list . '"';
    if ($session_timeout !== '') $replyAttrs[] = 'Session-Timeout := ' . (int) $session_timeout;
    if ($group !== '') $replyAttrs[] = 'Mikrotik-Group := "' . $group . '"';

    $blocks[] = ['username' => $user, 'raw' => radiusBuildUserBlock($user, $pass, $replyAttrs)];
    radiusWriteBlocksAtomic($blocks);

    restartFreeradius();
    header("Location: ../radius.php"); exit;
}
// ================= DELETE USER =================
if (isset($_POST['delete_user'])) {
    $del = trim($_POST['delete_user']);

    // Baca lewat radiusReadMergedBlocks() (KEDUA path users+mirror) -- versi
    // lama cuma baca $users_file, jadi entry yang cuma ada di path mirror
    // tidak pernah ketemu ($found selalu false) dan salah jatuh ke cabang
    // "hapus dari clients.conf" di bawah.
    $blocks = radiusReadMergedBlocks();
    $found = false;
    $new_blocks = [];
    foreach ($blocks as $b) {
        if ($b['username'] === $del) {
            $found = true;
            continue;
        }
        $new_blocks[] = $b;
    }

    if ($found) {
        // Hapus file timer jika ada
        $timer_file = "/etc/freeradius/user_timers/{$del}.json";
        if (file_exists($timer_file)) unlink($timer_file);

        radiusWriteBlocksAtomic($new_blocks);
    } else {
        // ================= Hapus dari PPPoE (clients.conf) =================
        $content = @file_get_contents($clients_file);
        if ($content === false) {
            $content = (string) shell_exec('sudo /bin/cat ' . escapeshellarg($clients_file) . ' 2>/dev/null');
        }
        $pattern = "/client\s+" . preg_quote($del, '/') . "\s*\{[^\}]*\}/s";
        $content = preg_replace($pattern, '', $content);
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        $ok = sudoWriteFile($clients_file, trim($content) . "\n\n");
        if (!$ok) {
            redirectWithMessage("❌ Gagal menghapus '$del': tidak bisa menulis ke $clients_file (cek izin sudo www-data).");
        }
    }

    // Restart FreeRADIUS
    restartFreeradius();

    header("Location: ../radius.php");
    exit;
}

// ================= Reset Users =================
if(isset($_POST['reset_users'])){
    $ok = writeUsersFileBoth($users_file, '');
    if (!$ok) {
        redirectWithMessage("❌ Gagal mengosongkan $users_file (cek izin sudo www-data).");
    }
    restartFreeradius(); // restart FreeRADIUS setelah reset
    header("Location: ../radius.php"); exit;
}

// ================= Save Config =================
if(isset($_POST['save_config'])){
    $config_file = trim($_POST['config_file']);
    $config_content = trim($_POST['config_content']);
    $prevent_duplicate = isset($_POST['prevent_duplicate']) ? true : false;

    // Backup file lama (lewat sudo cp, bukan PHP copy()). Kalau file belum
    // ada sama sekali, sudo cp akan gagal harmless (tidak masalah).
    $backup_file = sudoBackupFile($config_file);

    // Simpan konten baru LEWAT SUDO (bukan file_put_contents() langsung --
    // itu gagal diam-diam kalau www-data tidak punya izin tulis ke file
    // config yang biasanya milik root/freerad).
    //
    // Kalau yang diedit adalah salah satu dari pasangan file "users"
    // (/etc/freeradius/3.0/users) ATAU file yang BENERAN dibaca modul `files`
    // (RADIUS_USERS_FILE_MIRROR, tab "authorize"), tulis ke KEDUANYA sekaligus
    // supaya dua-duanya selalu identik terlepas dari tab mana yang dipakai.
    // Tanpa ini, entri yang diedit lewat satu tab saja bisa cuma masuk ke satu
    // path dan modul `files` FreeRADIUS (yang baca mods-config/files/authorize)
    // tidak pernah melihatnya -- makanya user kelihatan "ada" tapi tetap
    // "[files] = noop" saat auth.
    $isUsersPair = defined('RADIUS_USERS_FILE_MIRROR')
        && in_array($config_file, [$users_file, RADIUS_USERS_FILE_MIRROR], true);
    if ($isUsersPair) {
        $ok = sudoWriteFile($users_file, $config_content);
        sudoWriteFile(RADIUS_USERS_FILE_MIRROR, $config_content);
    } else {
        $ok = sudoWriteFile($config_file, $config_content);
    }

    if (!$ok) {
        redirectWithMessage("❌ GAGAL menyimpan $config_file -- tidak bisa menulis (cek izin sudo www-data untuk /bin/cp dan /bin/mv). Isi file di server TIDAK berubah.");
    }

    // Kalau ini file "default" (virtual server), validasi dulu sebelum
    // restart supaya tidak restart FreeRADIUS dengan config yang rusak.
    if (strpos($config_file, 'sites-available/default') !== false || strpos($config_file, 'sites-enabled/default') !== false) {
        $validate_output = shell_exec('sudo freeradius -X -C 2>&1');
        if (strpos($validate_output, 'Duplicate virtual server') !== false) {
            redirectWithMessage("⚠️ Konfigurasi tersimpan tapi ada 'Duplicate virtual server' -- FreeRADIUS TIDAK di-restart. Cek file backup/duplikat di sites-enabled, lalu pakai tombol 'Clean Duplicate Files'.");
        }
        if (strpos($validate_output, 'Configuration appears to be OK') === false && strpos($validate_output, 'radiusd:') !== false) {
            redirectWithMessage("⚠️ Konfigurasi tersimpan tapi validasi menunjukkan kemungkinan error: " . substr(strip_tags($validate_output), 0, 300));
        }
    }

    // Jika prevent_duplicate, bersihkan backup lama
    if($prevent_duplicate && $backup_file !== ''){
        $backup_pattern = $config_file . '.bak.*';
        $backups = glob($backup_pattern);
        foreach($backups as $backup){
            if($backup !== $backup_file) {
                shell_exec('sudo /bin/rm -f ' . escapeshellarg($backup) . ' 2>&1');
            }
        }
    }

    // Restart FreeRADIUS
    restartFreeradius();

    redirectWithMessage("✅ Konfigurasi $config_file berhasil disimpan dan diverifikasi. FreeRADIUS di-restart.");
}

// ================= Simpan pengaturan tab "Default" =================
if (isset($_POST['save_radius_defaults'])) {
    $fields = [
        'pppoe_radius_langsung_master_enabled' => isset($_POST['pppoe_radius_langsung_master_enabled']) ? 1 : 0,
        'session_timeout_default' => (int) ($_POST['session_timeout_default'] ?? 86400),
        'address_list_active' => trim((string) ($_POST['address_list_active'] ?? 'Pelanggan')),
        'address_list_expired' => trim((string) ($_POST['address_list_expired'] ?? 'EXPIRED')),
    ];
    if ($fields['address_list_active'] === '') $fields['address_list_active'] = 'Pelanggan';
    if ($fields['address_list_expired'] === '') $fields['address_list_expired'] = 'EXPIRED';
    if ($fields['session_timeout_default'] < 0) $fields['session_timeout_default'] = 0;

    radiusSaveGlobalSettings($conn, $fields, (string) ($ceknama ?? $userlogin ?? 'admin'));
    redirectWithMessage('✅ Pengaturan Default RADIUS disimpan. Tidak perlu restart FreeRADIUS -- pengaturan ini dipakai saat sync/pembuatan entry berikutnya.');
}

// ================= Simpan pengaturan tab "Filter" =================
if (isset($_POST['save_radius_filter'])) {
    $allowedPresets = ['reject_unknown', 'permissive_logged_only', 'custom'];
    $preset = in_array($_POST['filter_preset'] ?? '', $allowedPresets, true) ? $_POST['filter_preset'] : 'reject_unknown';
    // Butuh DUA checkbox (toggle utama + konfirmasi eksplisit) sebelum mode
    // permisif ini benar-benar diaktifkan -- lihat peringatan di radius.php.
    $acceptAll = isset($_POST['accept_all_debug_enabled']) && isset($_POST['accept_all_debug_confirm']);

    radiusSaveGlobalSettings($conn, [
        'filter_preset' => $preset,
        'accept_all_debug_enabled' => $acceptAll ? 1 : 0,
    ], (string) ($ceknama ?? $userlogin ?? 'admin'));

    // Ini yang BENAR-BENAR mengubah perilaku FreeRADIUS (bukan cuma simpan
    // flag DB) -- menambah/menghapus block `DEFAULT Auth-Type := Accept` di
    // file users/authorize, lalu restart kalau berubah.
    radiusSetAcceptAllDebugMode($acceptAll);

    redirectWithMessage($acceptAll
        ? '⚠️ Mode "Terima Semua User/Password (Testing/Debug)" AKTIF -- autentikasi RADIUS di server ini DIMATIKAN sampai dinonaktifkan lagi. Tercatat di log sinkronisasi.'
        : '✅ Pengaturan Filter RADIUS disimpan. Mode "Terima Semua" nonaktif.');
}

// ================= Cek status PPP AAA "Use RADIUS" per router =================
// Read-only -- dipakai tab "MULTI MODE Checklist". TIDAK mengubah apa pun di
// router, cuma menampilkan status /ppp/aaa/print supaya admin bisa verifikasi
// syarat wajib fallback MULTI MODE (RouterOS baru fallback ke RADIUS kalau
// opsi "Use RADIUS" ini aktif di router yang bersangkutan).
if (isset($_POST['check_ppp_aaa'])) {
    $server_ip = trim((string) ($_POST['server_ip'] ?? ''));
    $q = mysqli_query($conn, "SELECT * FROM server WHERE IP='" . mysqli_real_escape_string($conn, $server_ip) . "' LIMIT 1");
    $srv = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : null;
    if (!$srv) {
        redirectWithMessage("❌ Router dengan IP '$server_ip' tidak ditemukan di data server.");
    }

    require_once __DIR__ . '/../routeros_api.class.php';
    $API = new RouterosAPI();
    $API->timeout = 5;
    $API->attempts = 2;
    if (!$API->connect($srv['IP'], $srv['PEMILIK'], $srv['PASSWORD'])) {
        redirectWithMessage("❌ Gagal konek ke router {$srv['IP']} ({$srv['AREA']}) -- cek kredensial/koneksi.");
    }
    $aaa = $API->comm('/ppp/aaa/print');
    $API->disconnect();

    $useRadius = '(tidak diketahui)';
    if (is_array($aaa) && isset($aaa[0])) {
        $useRadius = (isset($aaa[0]['use-radius']) && $aaa[0]['use-radius'] === 'true') ? 'AKTIF ✅' : 'NONAKTIF ❌';
    }
    redirectWithMessage("Status 'Use RADIUS' di router {$srv['IP']} ({$srv['AREA']}): $useRadius" .
        ($useRadius === 'NONAKTIF ❌' ? " -- fallback MULTI MODE TIDAK akan bekerja di router ini sampai opsi ini diaktifkan manual di PPP > AAA." : ''));
}
?>
