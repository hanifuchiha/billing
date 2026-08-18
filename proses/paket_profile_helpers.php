<?php
/**
 * Helper upsert PPP Profile & IP Pool di MikroTik.
 *
 * LATAR BELAKANG (bug "profile jadi * / unknown" di API MODE):
 * Di RouterOS, kolom `profile` pada /ppp/secret menyimpan REFERENSI ke .id
 * item /ppp/profile, bukan salinan namanya. Kalau PPP Profile-nya dihapus
 * (walau langsung dibuat ulang dengan nama yang sama), item baru itu dapat
 * .id BARU -- sementara semua /ppp/secret masih menunjuk .id lama yang sudah
 * tidak ada. Referensi menggantung itulah yang tampil sebagai "*15"/"*0" di
 * Winbox dan ikut terbaca "*N"/unknown oleh /ppp/secret/print lewat API
 * (dipakai tables.php, pelanggan_menunggak.php, dan cron scan status).
 *
 * Karena itu profile/pool TIDAK BOLEH di-remove+add saat diedit. Ganti nama
 * pun cukup lewat `set name=` -- .id-nya lestari, jadi seluruh secret ikut
 * berpindah ke nama baru dengan sendirinya (persis perilaku rename di Winbox).
 */

require_once __DIR__ . '/../routeros_api.class.php';

if (!function_exists('mikrotikTrapMessage')) {
    /**
     * Ambil pesan error dari respons RouterosAPI::comm(). parseResponse()
     * menaruh balasan !trap/!fatal sebagai key khusus, BUKAN mengembalikan
     * false -- jadi cek `$resp === false` (pola lama di banyak file) tidak
     * pernah menangkap kegagalan apa pun.
     */
    function mikrotikTrapMessage($response): string
    {
        if (!is_array($response)) {
            return '';
        }
        foreach (['!trap', '!fatal'] as $key) {
            if (!isset($response[$key])) {
                continue;
            }
            if (isset($response[$key][0]['message'])) {
                return (string) $response[$key][0]['message'];
            }
            return ($key === '!trap') ? 'Mikrotik menolak perintah' : 'Koneksi Mikrotik terputus';
        }
        return '';
    }
}

if (!function_exists('mikrotikFindByName')) {
    /**
     * Cari satu item bernama $name di menu tertentu (mis. '/ip/pool').
     * Mengembalikan baris hasil print, atau null kalau tidak ada.
     */
    function mikrotikFindByName($API, string $menu, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $rows = $API->comm($menu . '/print', ['?name' => $name]);
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            // Lewati entri '!trap'/'!fatal' -- isinya array pesan, bukan baris data.
            if (!is_array($row) || !isset($row['.id'])) {
                continue;
            }
            if (isset($row['name']) && strcasecmp(trim((string) $row['name']), $name) !== 0) {
                continue;
            }
            return $row;
        }

        return null;
    }
}

if (!function_exists('mikrotikUpsertIpPool')) {
    /**
     * Pastikan IP Pool bernama $newName ada dengan ranges $ranges.
     *
     * Kalau pool lama ($oldName) masih ada, di-RENAME lewat `set name=` --
     * bukan remove+add -- supaya .id-nya lestari dan PPP Profile yang memakai
     * pool ini sebagai remote-address tidak berubah jadi referensi menggantung.
     *
     * @return array{ok:bool,error:string}
     */
    function mikrotikUpsertIpPool($API, string $oldName, string $newName, string $ranges): array
    {
        $existing = mikrotikFindByName($API, '/ip/pool', $newName);
        if (!$existing && $oldName !== '' && strcasecmp($oldName, $newName) !== 0) {
            $existing = mikrotikFindByName($API, '/ip/pool', $oldName);
        }

        if ($existing) {
            $params = ['.id' => $existing['.id'], 'ranges' => $ranges];
            if (strcasecmp(trim((string) ($existing['name'] ?? '')), $newName) !== 0) {
                $params['name'] = $newName;
            }
            $response = $API->comm('/ip/pool/set', $params);
        } else {
            $response = $API->comm('/ip/pool/add', ['name' => $newName, 'ranges' => $ranges]);
        }

        $error = mikrotikTrapMessage($response);
        return ['ok' => $error === '', 'error' => $error];
    }
}

if (!function_exists('mikrotikUpsertPppProfile')) {
    /**
     * Pastikan PPP Profile bernama $newName ada dengan properti yang diminta.
     * Sama seperti pool: profile lama di-rename di tempat, TIDAK PERNAH dihapus,
     * supaya /ppp/secret milik pelanggan tetap menunjuk .id yang valid.
     *
     * Panggil SETELAH mikrotikUpsertIpPool() -- $remoteAddress biasanya nama
     * pool tersebut, jadi pool-nya harus sudah ada saat profile di-set.
     *
     * @return array{ok:bool,error:string}
     */
    function mikrotikUpsertPppProfile(
        $API,
        string $oldName,
        string $newName,
        string $rateLimit,
        string $localAddress,
        string $remoteAddress
    ): array {
        $existing = mikrotikFindByName($API, '/ppp/profile', $newName);
        if (!$existing && $oldName !== '' && strcasecmp($oldName, $newName) !== 0) {
            $existing = mikrotikFindByName($API, '/ppp/profile', $oldName);
        }

        $props = [
            'rate-limit'     => $rateLimit,
            'local-address'  => $localAddress,
            'remote-address' => $remoteAddress,
        ];

        if ($existing) {
            $params = ['.id' => $existing['.id']] + $props;
            if (strcasecmp(trim((string) ($existing['name'] ?? '')), $newName) !== 0) {
                $params['name'] = $newName;
            }
            $response = $API->comm('/ppp/profile/set', $params);
        } else {
            $response = $API->comm('/ppp/profile/add', ['name' => $newName] + $props);
        }

        $error = mikrotikTrapMessage($response);
        return ['ok' => $error === '', 'error' => $error];
    }
}

if (!function_exists('pppoeUniqueServers')) {
    /**
     * Ambil semua kombinasi unik IP+PEMILIK+PASSWORD dari tabel `server`.
     * Satu kombinasi = satu Mikrotik fisik, dipakai supaya router yang sama
     * dipakai banyak AREA tidak ikut dipindai berkali-kali (pola sama dengan
     * getdata/cron_scan_pppoe_status.php).
     */
    function pppoeUniqueServers($conn): array
    {
        $servers = [];
        $seen = [];
        $result = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD FROM server WHERE IP IS NOT NULL AND IP <> '' AND PEMILIK IS NOT NULL AND PEMILIK <> ''");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $ip = trim((string) $row['IP']);
                $user = trim((string) $row['PEMILIK']);
                $pass = (string) $row['PASSWORD'];
                if ($ip === '' || $user === '') continue;

                $key = strtolower($ip . '|' . $user . '|' . $pass);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $servers[] = ['ip' => $ip, 'user' => $user, 'pass' => $pass];
            }
        }
        return $servers;
    }
}

if (!function_exists('pppoeRepairDanglingProfiles')) {
    /**
     * Pindai semua router unik, cari /ppp/secret yang kolom profile-nya
     * berupa referensi menggantung ("*15" dst, .id yang sudah tidak ada) atau
     * menunjuk nama profile yang tidak ada di router itu, lalu (kalau $apply
     * true) kembalikan ke profile yang benar.
     *
     * Target dipilih KONSERVATIF supaya tidak diam-diam membuka isolir:
     *   - comment secret diawali "EXPIRED" -> dikembalikan ke profile EXPIRED
     *   - selain itu                       -> dikembalikan ke pelanggan.PAKET
     * Kalau profile tujuan tidak ada di router itu, secret dilewati dan
     * dilaporkan (bukan ditebak).
     *
     * Dipakai bersama oleh getdata/repair_dangling_pppoe_profile.php (alat
     * manual, dry-run by default) dan getdata/cron_repair_dangling_pppoe_profile.php
     * (endpoint cron, SELALU apply -- itu untuk dijadwalkan otomatis).
     *
     * @param callable|null $log function(string $line): void, dipanggil per baris progres
     * @return array{servers_scanned:int,servers_failed:int,secrets_scanned:int,broken:int,fixed:int,skipped:int}
     */
    function pppoeRepairDanglingProfiles($conn, bool $apply, ?callable $log = null): array
    {
        $log = $log ?? function (string $line): void {
        };

        $paketByIdpel = [];
        $pelangganResult = mysqli_query($conn, "SELECT IDPEL, PAKET FROM pelanggan WHERE IDPEL IS NOT NULL AND IDPEL <> ''");
        if ($pelangganResult) {
            while ($row = mysqli_fetch_assoc($pelangganResult)) {
                $paketByIdpel[strtolower(trim((string) $row['IDPEL']))] = trim((string) $row['PAKET']);
            }
        }

        $servers = pppoeUniqueServers($conn);

        $summary = [
            'servers_scanned' => count($servers),
            'servers_failed' => 0,
            'secrets_scanned' => 0,
            'broken' => 0,
            'fixed' => 0,
            'skipped' => 0,
        ];

        foreach ($servers as $srv) {
            $api = new RouterosAPI();
            $api->timeout = 5;
            $api->attempts = 1;
            $api->delay = 0;

            if (!$api->connect($srv['ip'], $srv['user'], $srv['pass'])) {
                $summary['servers_failed']++;
                $log("[SKIP] {$srv['ip']} ({$srv['user']}): gagal konek.");
                continue;
            }

            // Daftar profile yang BENAR-BENAR ada di router ini.
            $profileNames = [];
            $profiles = $api->comm('/ppp/profile/print', ['.proplist' => 'name']);
            if (is_array($profiles)) {
                foreach ($profiles as $p) {
                    if (!is_array($p) || !isset($p['name'])) continue;
                    $name = trim((string) $p['name']);
                    if ($name === '') continue;
                    $profileNames[strtolower($name)] = $name;
                }
            }

            $secrets = $api->comm('/ppp/secret/print', ['.proplist' => '.id,name,profile,comment']);
            if (!is_array($secrets)) {
                $summary['servers_failed']++;
                $log("[SKIP] {$srv['ip']}: gagal baca /ppp/secret.");
                $api->disconnect();
                continue;
            }

            $brokenHere = 0;
            foreach ($secrets as $s) {
                if (!is_array($s) || !isset($s['.id'], $s['name'])) continue;
                $summary['secrets_scanned']++;

                $idpel = trim((string) $s['name']);
                $profileRaw = trim((string) ($s['profile'] ?? ''));
                $comment = trim((string) ($s['comment'] ?? ''));

                // Rusak = nilai profile berupa .id mentah ("*15") ATAU nama
                // yang tidak ada di /ppp/profile router ini.
                $isDanglingId = (bool) preg_match('/^\*[0-9A-Fa-f]+$/', $profileRaw);
                $isMissingName = ($profileRaw !== '' && !$isDanglingId && !isset($profileNames[strtolower($profileRaw)]));
                if (!$isDanglingId && !$isMissingName) {
                    continue;
                }

                $brokenHere++;
                $summary['broken']++;

                $isIsolir = (stripos($comment, 'EXPIRED') === 0);
                $targetWanted = $isIsolir ? 'EXPIRED' : ($paketByIdpel[strtolower($idpel)] ?? '');

                if ($targetWanted === '') {
                    $log("[LEWATI] {$srv['ip']} $idpel: profile '$profileRaw' rusak, pelanggan tidak ada di database.");
                    $summary['skipped']++;
                    continue;
                }

                if (!isset($profileNames[strtolower($targetWanted)])) {
                    $log("[LEWATI] {$srv['ip']} $idpel: profile '$profileRaw' rusak, profile tujuan '$targetWanted' tidak ada di router ini.");
                    $summary['skipped']++;
                    continue;
                }

                $target = $profileNames[strtolower($targetWanted)];

                if (!$apply) {
                    $log("[AKAN DIPERBAIKI] {$srv['ip']} $idpel: '$profileRaw' -> '$target'" . ($isIsolir ? ' (tetap isolir)' : ''));
                    continue;
                }

                $response = $api->comm('/ppp/secret/set', [
                    '.id' => $s['.id'],
                    'profile' => $target,
                ]);
                $error = mikrotikTrapMessage($response);
                if ($error !== '') {
                    $log("[GAGAL] {$srv['ip']} $idpel: '$profileRaw' -> '$target' ditolak router: $error");
                    $summary['skipped']++;
                    continue;
                }

                $summary['fixed']++;
                $log("[OK] {$srv['ip']} $idpel: '$profileRaw' -> '$target'" . ($isIsolir ? ' (tetap isolir)' : ''));
            }

            $log("--- {$srv['ip']} ({$srv['user']}): " . count($secrets) . " secret dipindai, $brokenHere rusak.");
            $api->disconnect();
        }

        return $summary;
    }
}

if (!function_exists('mikrotikCountSecretsUsingProfile')) {
    /**
     * Hitung berapa /ppp/secret yang masih memakai profile bernama $profileName.
     * Dipakai sebagai pagar sebelum menghapus profile: menghapus profile yang
     * masih direferensikan secret akan meninggalkan "*N" di kolom profile
     * secret-secret tersebut.
     */
    function mikrotikCountSecretsUsingProfile($API, string $profileName): int
    {
        $profileName = trim($profileName);
        if ($profileName === '') {
            return 0;
        }

        $rows = $API->comm('/ppp/secret/print', ['.proplist' => 'name,profile']);
        if (!is_array($rows)) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['profile'])) {
                continue;
            }
            if (strcasecmp(trim((string) $row['profile']), $profileName) === 0) {
                $count++;
            }
        }

        return $count;
    }
}
