<?php
/**
 * cs_call_center_helper.php
 *
 * Inti fitur CS Call Center (WebRTC voice call antara portal pelanggan dan
 * agent/assistant). Menyimpan settings per-owner (WebRTC STUN/TURN, ringtone
 * agent, toggle) dan sesi call (routing/signaling) di tabel DB -- BUKAN file
 * JSON per-call spt crm/qchat -- supaya bisa di-query langsung ("cari agent
 * available di area X"), krn directory-scan ala qchat tidak scalable utk
 * kebutuhan routing multi-agent.
 *
 * Auto-install tabel: pola SHOW TABLES + CREATE, sama spt
 * provisioning_approval.php.
 */

require_once __DIR__ . '/callcenter_access_helper.php';

if (!function_exists('csCallCenterEnsureTables')) {
    function csCallCenterEnsureTables($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $check = mysqli_query($conn, "SHOW TABLES LIKE 'cs_call_center_settings'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "CREATE TABLE `cs_call_center_settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `owner_key` VARCHAR(100) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `webrtc_stun_url` VARCHAR(255) DEFAULT '',
                `webrtc_turn_url` VARCHAR(255) DEFAULT '',
                `webrtc_turn_username` VARCHAR(100) DEFAULT '',
                `webrtc_turn_credential` VARCHAR(100) DEFAULT '',
                `agent_ringtone` VARCHAR(50) DEFAULT 'bell1.mp3',
                `share_with_users` TINYINT(1) NOT NULL DEFAULT 0,
                `ring_timeout_seconds` INT NOT NULL DEFAULT 30,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `owner_key` (`owner_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            // Migrasi kolom baru utk instalasi yang tabelnya sudah ada duluan
            // (sebelum fitur ring timeout ini ditambahkan).
            $col = mysqli_query($conn, "SHOW COLUMNS FROM cs_call_center_settings LIKE 'ring_timeout_seconds'");
            if ($col && mysqli_num_rows($col) == 0) {
                mysqli_query($conn, "ALTER TABLE cs_call_center_settings ADD COLUMN `ring_timeout_seconds` INT NOT NULL DEFAULT 30");
            }
            // Toggle log debug on-screen (webrtc_turn_settings.php) -- lihat
            // project_cs_call_center_ice_race_fix.md follow-up #8/#9.
            $colDebug = mysqli_query($conn, "SHOW COLUMNS FROM cs_call_center_settings LIKE 'debug_mode'");
            if ($colDebug && mysqli_num_rows($colDebug) == 0) {
                mysqli_query($conn, "ALTER TABLE cs_call_center_settings ADD COLUMN `debug_mode` TINYINT(1) NOT NULL DEFAULT 0");
            }
        }

        $check = mysqli_query($conn, "SHOW TABLES LIKE 'cs_call_center_calls'");
        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "CREATE TABLE `cs_call_center_calls` (
                `id` VARCHAR(40) PRIMARY KEY,
                `owner_key` VARCHAR(100) NOT NULL,
                `customer_idpel` VARCHAR(100) NOT NULL,
                `customer_name` VARCHAR(200) DEFAULT '',
                `customer_area` VARCHAR(100) DEFAULT '',
                `agent_username` VARCHAR(100) DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'ringing',
                `offer_sdp` MEDIUMTEXT DEFAULT NULL,
                `answer_sdp` MEDIUMTEXT DEFAULT NULL,
                `ice_customer` MEDIUMTEXT DEFAULT NULL,
                `ice_agent` MEDIUMTEXT DEFAULT NULL,
                `attempted_agents` TEXT DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `answered_at` DATETIME DEFAULT NULL,
                `ended_at` DATETIME DEFAULT NULL,
                `ended_by` VARCHAR(20) DEFAULT NULL,
                INDEX `idx_agent_status` (`agent_username`, `status`),
                INDEX `idx_owner` (`owner_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    }
}

if (!function_exists('csCallCenterScopeKey')) {
    /** Key baris settings per-tenant -- 'admin' utk platform root, else username owner. */
    function csCallCenterScopeKey(string $AKSES, string $ceknama): string
    {
        return $AKSES === 'ADMIN' ? 'admin' : $ceknama;
    }
}

if (!function_exists('csCallCenterGetSettings')) {
    /** Ambil baris settings utk 1 owner_key, atau null kalau belum pernah disimpan. */
    function csCallCenterGetSettings($conn, string $ownerKey): ?array
    {
        csCallCenterEnsureTables($conn);
        $esc = mysqli_real_escape_string($conn, $ownerKey);
        $res = mysqli_query($conn, "SELECT * FROM cs_call_center_settings WHERE owner_key = '$esc' LIMIT 1");
        if ($res && mysqli_num_rows($res) > 0) {
            return mysqli_fetch_assoc($res);
        }
        return null;
    }
}

if (!function_exists('csCallCenterDefaultWebrtc')) {
    /** Default WebRTC yang mengikuti server yang sudah dipakai qchat. */
    function csCallCenterDefaultWebrtc(): array
    {
        return [
            'webrtc_stun_url' => 'stun:31.57.178.141:3478',
            'webrtc_turn_url' => 'turn:31.57.178.141:3478',
            'webrtc_turn_username' => 'qts',
            'webrtc_turn_credential' => 'qts',
        ];
    }
}

if (!function_exists('csCallCenterSaveSettings')) {
    /**
     * Simpan pengaturan CS Agent PER-OWNER (enabled/ringtone/ring timeout).
     * SENGAJA tidak lagi menyentuh kolom webrtc_stun_url dkk sama sekali
     * (dulu ikut di-overwrite jadi kosong tiap kali tab "Pengaturan CS
     * Agent" disimpan, krn form-nya sudah tidak kirim field itu lagi) --
     * lihat csCallCenterSaveGlobalWebrtcConfig() utk config TURN GLOBAL
     * (admin-only, terpisah total dari fungsi ini, 2026-08-15).
     */
    function csCallCenterSaveSettings($conn, string $ownerKey, array $data): bool
    {
        csCallCenterEnsureTables($conn);
        $ownerKeyEsc = mysqli_real_escape_string($conn, $ownerKey);
        $enabled = !empty($data['enabled']) ? 1 : 0;
        $ringtoneAllowed = ['bell1.mp3', 'bell2.mp3', 'bell3.mp3'];
        $ringtone = in_array($data['agent_ringtone'] ?? '', $ringtoneAllowed, true) ? $data['agent_ringtone'] : 'bell1.mp3';
        $share = !empty($data['share_with_users']) ? 1 : 0;
        // Dibatasi 5-120 detik -- terlalu pendek bikin agent tidak sempat
        // angkat, terlalu panjang bikin pelanggan nunggu lama sebelum
        // dialihkan ke agent lain.
        $ringTimeout = (int) ($data['ring_timeout_seconds'] ?? 30);
        if ($ringTimeout < 5) $ringTimeout = 5;
        if ($ringTimeout > 120) $ringTimeout = 120;

        $sql = "INSERT INTO cs_call_center_settings
                (owner_key, enabled, agent_ringtone, share_with_users, ring_timeout_seconds)
                VALUES ('$ownerKeyEsc', $enabled, '$ringtone', $share, $ringTimeout)
                ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                agent_ringtone = VALUES(agent_ringtone),
                share_with_users = VALUES(share_with_users),
                ring_timeout_seconds = VALUES(ring_timeout_seconds)";
        return (bool) mysqli_query($conn, $sql);
    }
}

if (!function_exists('csCallCenterSaveGlobalWebrtcConfig')) {
    /**
     * Simpan config TURN/STUN GLOBAL (berlaku utk SEMUA akun/owner tanpa
     * kecuali) -- disimpan di baris owner_key='admin' yang sama dgn baris
     * settings platform, tapi HANYA kolom webrtc_* yang disentuh (enabled/
     * ringtone/dkk milik baris 'admin' itu sendiri, kalau ada, TIDAK ikut
     * berubah). Dipanggil KHUSUS dari halaman admin-only
     * webrtc_turn_settings.php -- caller WAJIB sudah cek $AKSES==='ADMIN'
     * sebelum sampai sini (fungsi ini sendiri tidak cek role apapun).
     */
    function csCallCenterSaveGlobalWebrtcConfig($conn, array $data): bool
    {
        csCallCenterEnsureTables($conn);
        $stun = mysqli_real_escape_string($conn, (string)($data['webrtc_stun_url'] ?? ''));
        $turn = mysqli_real_escape_string($conn, (string)($data['webrtc_turn_url'] ?? ''));
        $turnUser = mysqli_real_escape_string($conn, (string)($data['webrtc_turn_username'] ?? ''));
        $turnCred = mysqli_real_escape_string($conn, (string)($data['webrtc_turn_credential'] ?? ''));
        $debugMode = !empty($data['debug_mode']) ? 1 : 0;

        $sql = "INSERT INTO cs_call_center_settings
                (owner_key, enabled, webrtc_stun_url, webrtc_turn_url, webrtc_turn_username, webrtc_turn_credential, debug_mode)
                VALUES ('admin', 0, '$stun', '$turn', '$turnUser', '$turnCred', $debugMode)
                ON DUPLICATE KEY UPDATE
                webrtc_stun_url = VALUES(webrtc_stun_url),
                webrtc_turn_url = VALUES(webrtc_turn_url),
                webrtc_turn_username = VALUES(webrtc_turn_username),
                webrtc_turn_credential = VALUES(webrtc_turn_credential),
                debug_mode = VALUES(debug_mode)";
        return (bool) mysqli_query($conn, $sql);
    }
}

if (!function_exists('csCallCenterGetGlobalWebrtcConfig')) {
    /**
     * Baca config TURN/STUN GLOBAL (baris owner_key='admin', kolom webrtc_*
     * + debug_mode) -- fallback ke csCallCenterDefaultWebrtc() (server
     * bawaan, sama dgn qchat/walkie-talkie) kalau baris 'admin' belum
     * pernah diisi sama sekali ATAU field-nya kosong. INI SATU-SATUNYA
     * sumber TURN/STUN yang dipakai runtime oleh SEMUA owner/akun -- lihat
     * csCallCenterResolveWebrtcConfig().
     */
    function csCallCenterGetGlobalWebrtcConfig($conn): array
    {
        $admin = csCallCenterGetSettings($conn, 'admin');
        $default = csCallCenterDefaultWebrtc();
        $debugMode = $admin ? !empty($admin['debug_mode']) : false;
        if ($admin && trim((string)($admin['webrtc_stun_url'] ?? '')) !== '') {
            return [
                'webrtc_stun_url' => $admin['webrtc_stun_url'],
                'webrtc_turn_url' => $admin['webrtc_turn_url'] ?: $default['webrtc_turn_url'],
                'webrtc_turn_username' => $admin['webrtc_turn_username'] ?: $default['webrtc_turn_username'],
                'webrtc_turn_credential' => $admin['webrtc_turn_credential'] ?: $default['webrtc_turn_credential'],
                'debug_mode' => $debugMode,
            ];
        }
        return $default + ['debug_mode' => $debugMode];
    }
}

if (!function_exists('csCallCenterBuildIceServersArray')) {
    /**
     * Bangun array `iceServers` standar dari config webrtc: STUN + TURN
     * (default UDP) + TAMBAHAN varian TURN transport=tcp. Banyak jaringan
     * kantor/seluler/hotspot memblokir UDP keluar tapi izinkan TCP -- tanpa
     * entri TCP terpisah, browser TIDAK PERNAH mencoba TCP walau STUN/TURN
     * URL-nya sudah benar, ICE bisa gagal total tanpa kandidat relay yang
     * bisa dipakai. Gejala persis: SDP exchange sukses ("Tersambung" +
     * timer jalan), tapi audio tidak pernah keluar, lalu call berakhir
     * sendiri begitu watchdog stuck-connecting (15 detik) menyerah --
     * laporan user 2026-08-15. Dipakai bareng oleh sisi agent
     * (cs_call_center_incall.php) & pelanggan (broadband/cs_call_center_api.php
     * action=agent_available) supaya konsisten, tidak duplikat logic.
     */
    function csCallCenterBuildIceServersArray(array $webrtc): array
    {
        $servers = [];
        if (!empty($webrtc['stun_url'])) {
            $servers[] = ['urls' => $webrtc['stun_url']];
        }
        if (!empty($webrtc['turn_url'])) {
            $servers[] = [
                'urls' => $webrtc['turn_url'],
                'username' => $webrtc['turn_username'] ?? '',
                'credential' => $webrtc['turn_credential'] ?? '',
            ];
            if (stripos($webrtc['turn_url'], 'turn:') === 0 && stripos($webrtc['turn_url'], 'transport=') === false) {
                $servers[] = [
                    'urls' => $webrtc['turn_url'] . '?transport=tcp',
                    'username' => $webrtc['turn_username'] ?? '',
                    'credential' => $webrtc['turn_credential'] ?? '',
                ];
            }
        }
        return $servers;
    }
}

if (!function_exists('csCallCenterResolveWebrtcConfig')) {
    /**
     * Resolusi config WebRTC yang AKTUAL dipakai runtime.
     *
     * ROMBAK TOTAL 2026-08-15 (permintaan eksplisit user: "saya mau semua
     * sistem call sesuai pola dari qchat", lalu diperhalus lagi jadi
     * "pengaturan server TURN buat semua akun, cuma ADMIN yang bisa edit,
     * lewat menu Administrator Panel"): STUN/TURN SEKARANG SATU CONFIG
     * GLOBAL berlaku utk SEMUA owner/akun (`csCallCenterGetGlobalWebrtcConfig()`,
     * baris owner_key='admin' di tabel yang sama, kolom webrtc_* saja) --
     * BUKAN LAGI per-owner. Cuma ADMIN yang bisa mengubahnya, lewat halaman
     * khusus `webrtc_turn_settings.php` (menu sidebar admin-only, terpisah
     * dari tab "Pengaturan CS Agent" yang sekarang TIDAK PUNYA field TURN
     * sama sekali). Kalau baris global belum pernah diisi ADMIN, fallback
     * ke `csCallCenterDefaultWebrtc()` (server bawaan, sama persis dgn
     * qchat/walkie-talkie).
     *
     * SEBELUMNYA (dua iterasi lalu) fungsi ini punya lapisan "tiap
     * owner/tenant bisa custom TURN server sendiri-sendiri" -- itu SATU-
     * SATUNYA beda arsitektur CS Call Center dari qchat/walkie-talkie, dan
     * jadi sumber SEMUA masalah "gagal tersambung lintas jaringan" yang
     * dilaporkan 2026-08-15: kalau baris owner kosong ATAU terisi tapi
     * nilainya SALAH/TYPO (bukan kosong, jadi tidak ke-fallback),
     * `RTCPeerConnection` diam-diam jalan tanpa STUN/TURN yang valid --
     * connect mulus di LAN (kandidat host cukup), PASTI gagal lintas
     * jaringan, TANPA jejak error di manapun.
     */
    function csCallCenterResolveWebrtcConfig($conn, string $ownerKey): array
    {
        $own = csCallCenterGetSettings($conn, $ownerKey);
        $global = csCallCenterGetGlobalWebrtcConfig($conn);
        return [
            'stun_url' => $global['webrtc_stun_url'],
            'turn_url' => $global['webrtc_turn_url'],
            'turn_username' => $global['webrtc_turn_username'],
            'turn_credential' => $global['webrtc_turn_credential'],
            'agent_ringtone' => ($own['agent_ringtone'] ?? null) ?: 'bell1.mp3',
            'debug_mode' => !empty($global['debug_mode']),
        ];
    }
}

if (!function_exists('csCallCenterIsFeatureEnabled')) {
    function csCallCenterIsFeatureEnabled($conn, string $ownerKey): bool
    {
        $own = csCallCenterGetSettings($conn, $ownerKey);
        return $own ? (bool)$own['enabled'] : false;
    }
}

if (!function_exists('csCallCenterGenId')) {
    function csCallCenterGenId(): string
    {
        return 'cscall-' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('csCallCenterResolveOwnerId')) {
    /**
     * owner_key (username tenant, ATAU literal 'admin') -> id baris user
     * pemilik akun tsb. PENTING: 'admin' di sini BUKAN username asli -- itu
     * kunci literal yang sama dgn nilai `pelanggan.PEMILIK` utk pelanggan
     * milik akun ADMIN (lihat komentar livechatAiScopeKey() di
     * livechat_ai_helper.php), jadi TIDAK BOLEH di-match ke kolom USERNAME.
     */
    function csCallCenterResolveOwnerId($conn, string $ownerKey): ?int
    {
        if ($ownerKey === 'admin') {
            $res = mysqli_query($conn, "SELECT id FROM user WHERE STATUS = 'ADMIN' LIMIT 1");
        } else {
            $esc = mysqli_real_escape_string($conn, $ownerKey);
            $res = mysqli_query($conn, "SELECT id FROM user WHERE USERNAME = '$esc' AND STATUS = 'USER' LIMIT 1");
        }
        if ($res && mysqli_num_rows($res) > 0) {
            return (int) mysqli_fetch_assoc($res)['id'];
        }
        return null;
    }
}

if (!function_exists('csCallCenterResolveOwnerFromServerPemilik')) {
    /**
     * `pelanggan.PEMILIK` BUKAN username akun ataupun 'admin' literal --
     * itu credential MikroTik/RADIUS yang di-generate PER SERVER lewat
     * generateMikrotikCredentials() (lihat proses/addserver.php), beda2
     * nilainya walau server-server itu milik akun yang SAMA. Satu2nya jalur
     * valid utk tau akun OWNER sesungguhnya dari sisi portal pelanggan
     * adalah lewat kolom `server`.`user_id` (FK asli ke `user`.`id`),
     * BUKAN string-matching PEMILIK ke USERNAME/'admin'.
     *
     * Return [ownerId, ownerKey] (ownerKey sudah dlm bentuk yg sama dgn
     * csCallCenterScopeKey(), siap dipakai ke csCallCenterGetSettings()
     * dkk), atau [null, null] kalau server/owner-nya tidak ketemu.
     */
    function csCallCenterResolveOwnerFromServerPemilik($conn, string $pemilikValue): array
    {
        if ($pemilikValue === '') {
            return [null, null];
        }
        $esc = mysqli_real_escape_string($conn, $pemilikValue);
        $res = mysqli_query($conn, "SELECT user_id FROM server WHERE PEMILIK = '$esc' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) {
            return [null, null];
        }
        $userId = (int) mysqli_fetch_assoc($res)['user_id'];
        if ($userId <= 0) {
            return [null, null];
        }

        $res2 = mysqli_query($conn, "SELECT USERNAME, STATUS FROM user WHERE id = $userId LIMIT 1");
        if (!$res2 || mysqli_num_rows($res2) === 0) {
            return [null, null];
        }
        $ownerRow = mysqli_fetch_assoc($res2);
        $ownerKey = $ownerRow['STATUS'] === 'ADMIN' ? 'admin' : $ownerRow['USERNAME'];

        return [$userId, $ownerKey];
    }
}

if (!function_exists('csCallCenterFindAvailableAgent')) {
    /**
     * Cari 1 agent yang: toggle ON, heartbeat masih segar (<45 detik), sedang
     * tidak dalam call, DAN area pelanggan termasuk area yang diizinkan utk
     * agent itu. Kandidat = SEMUA ASSISTANT di bawah owner tsb, DITAMBAH akun
     * utama (USER/ADMIN) itu sendiri kalau toggle-nya juga ON. Akun utama
     * default-nya unrestricted (semua AREA), TAPI kalau owner sengaja isi
     * assigned_callcenter_areas miliknya sendiri lewat modal Edit Area, dia
     * ikut dibatasi juga -- sama pola dgn ASSISTANT, cuma beda fallback saat
     * kosong (ASSISTANT jatuh ke area server-nya, owner jatuh ke unrestricted).
     * Exclude username yang ada di $excludeUsernames (agent yang baru
     * declined, saat re-route). Return array baris user, atau null kalau
     * tidak ada yang available.
     */
    function csCallCenterFindAvailableAgent($conn, int $ownerId, string $customerArea, array $excludeUsernames = []): ?array
    {
        $res = mysqli_query($conn, "SELECT * FROM user
            WHERE ((STATUS = 'ASSISTANT' AND grup = $ownerId) OR (id = $ownerId AND STATUS IN ('USER','ADMIN')))
              AND callcenter_agent_enabled = 1
              AND callcenter_current_call_id IS NULL
              AND callcenter_last_heartbeat IS NOT NULL
              AND callcenter_last_heartbeat > DATE_SUB(NOW(), INTERVAL 45 SECOND)
            ORDER BY callcenter_last_heartbeat ASC");
        if (!$res) {
            return null;
        }

        $excludeSet = array_flip($excludeUsernames);

        while ($agent = mysqli_fetch_assoc($res)) {
            if (isset($excludeSet[$agent['USERNAME']])) {
                continue;
            }

            if ($agent['STATUS'] !== 'ASSISTANT') {
                // Akun utama sendiri sbg agent -- default unrestricted, kecuali
                // dia sendiri isi assigned_callcenter_areas lewat modal Edit Area.
                $ownerAssignedJSON = $agent['assigned_callcenter_areas'] ?? null;
                $ownerAssignedAreas = [];
                if ($ownerAssignedJSON) {
                    $ownerDecoded = json_decode($ownerAssignedJSON, true);
                    if (is_array($ownerDecoded)) {
                        $ownerAssignedAreas = $ownerDecoded;
                    }
                }
                $ownerAssignedClean = array_values(array_unique(array_filter(array_map('strval', $ownerAssignedAreas), fn($a) => trim($a) !== '')));
                if (empty($ownerAssignedClean) || in_array($customerArea, $ownerAssignedClean, true)) {
                    return $agent;
                }
                continue;
            }

            $assignedAreas = [];
            $assignedJSON = $agent['assigned_callcenter_areas'] ?? null;
            if ($assignedJSON) {
                $decoded = json_decode($assignedJSON, true);
                if (is_array($decoded)) {
                    $assignedAreas = $decoded;
                }
            }

            // Fallback: area umum assistant ini (dari kolom `server` -> AREA),
            // sama logic dgn cek-sesi.php, dihitung on-demand di sini (bukan
            // dari session krn ini dieksekusi di request API terpisah).
            $fallbackAreas = [];
            $serverJSON = $agent['server'] ?? null;
            if ($serverJSON) {
                $serverIds = json_decode($serverJSON, true);
                if (is_array($serverIds) && count($serverIds) > 0) {
                    $idIn = implode(',', array_map('intval', $serverIds));
                    $resArea = mysqli_query($conn, "SELECT AREA FROM server WHERE id IN ($idIn)");
                    if ($resArea) {
                        while ($rowArea = mysqli_fetch_assoc($resArea)) {
                            if (!empty($rowArea['AREA'])) {
                                $fallbackAreas[] = $rowArea['AREA'];
                            }
                        }
                    }
                }
            }

            $allowedAreas = callcenterAccessResolveAreas('ASSISTANT', $assignedAreas, $fallbackAreas);
            if (in_array($customerArea, $allowedAreas, true)) {
                return $agent;
            }
        }

        return null;
    }
}

if (!function_exists('csCallCenterLockAgent')) {
    /**
     * Atomic busy-lock: return true kalau berhasil (agent memang masih free).
     * Sengaja tidak dibatasi STATUS='ASSISTANT' -- akun utama (USER/ADMIN)
     * yang jadi agent sendiri juga dikunci lewat fungsi yang sama.
     */
    function csCallCenterLockAgent($conn, string $agentUsername, string $callId): bool
    {
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        mysqli_query($conn, "UPDATE user SET callcenter_current_call_id = '$callIdEsc'
            WHERE USERNAME = '$usernameEsc' AND callcenter_current_call_id IS NULL");
        return mysqli_affected_rows($conn) > 0;
    }
}

if (!function_exists('csCallCenterReleaseAgent')) {
    function csCallCenterReleaseAgent($conn, string $agentUsername): void
    {
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        mysqli_query($conn, "UPDATE user SET callcenter_current_call_id = NULL
            WHERE USERNAME = '$usernameEsc'");
    }
}

if (!function_exists('csCallCenterGetRingTimeout')) {
    /** Durasi dering (detik) sblm dianggap timeout, dari settings owner -- fallback 30 detik. */
    function csCallCenterGetRingTimeout($conn, string $ownerKey): int
    {
        $settings = csCallCenterGetSettings($conn, $ownerKey);
        $timeout = (int) ($settings['ring_timeout_seconds'] ?? 0);
        return $timeout >= 5 ? $timeout : 30;
    }
}

if (!function_exists('csCallCenterExpireStale')) {
    /**
     * Lazy cleanup (dipanggil tiap kali sebuah call dibaca): call 'ringing'
     * yg sudah lewat `ring_timeout_seconds` (dari settings owner, default 30
     * detik) dianggap TIDAK DIJAWAB -- otomatis "ditolak" (sama spt agent
     * declined manual) dan di-reroute ke agent lain yg available; kalau
     * tidak ada lagi, baru jadi 'no_agent' (ended_by='timeout'). Call 'active'
     * >2 jam dianggap timeout juga -- sama pola TTL-on-read spt qchat, tidak
     * perlu cron.
     */
    function csCallCenterExpireStale($conn, array $call): array
    {
        if ($call['status'] === 'ringing') {
            $created = strtotime($call['created_at']);
            $timeoutSeconds = csCallCenterGetRingTimeout($conn, $call['owner_key']);
            if ($created !== false && (time() - $created) > $timeoutSeconds) {
                $call = csCallCenterRerouteAfterDecline($conn, $call, 'timeout');
            }
        } elseif ($call['status'] === 'active') {
            $answered = $call['answered_at'] ? strtotime($call['answered_at']) : null;
            if ($answered !== null && (time() - $answered) > 7200) {
                if (!empty($call['agent_username'])) {
                    csCallCenterReleaseAgent($conn, $call['agent_username']);
                }
                $idEsc = mysqli_real_escape_string($conn, $call['id']);
                mysqli_query($conn, "UPDATE cs_call_center_calls SET status='ended', ended_at=NOW(), ended_by='timeout' WHERE id='$idEsc'");
                $call['status'] = 'ended';
            }
        }
        return $call;
    }
}

if (!function_exists('csCallCenterStartCall')) {
    /**
     * Mulai call baru dari pelanggan: cari+lock agent available, insert baris
     * call status 'ringing'. Return array call row (sudah termasuk id), atau
     * array dgn status 'no_agent' kalau tidak ada agent available sama sekali.
     */
    function csCallCenterStartCall($conn, string $ownerUsername, string $customerIdpel, string $customerName, string $customerArea): array
    {
        csCallCenterEnsureTables($conn);
        $ownerId = csCallCenterResolveOwnerId($conn, $ownerUsername);
        $callId = csCallCenterGenId();

        $agent = $ownerId ? csCallCenterFindAvailableAgent($conn, $ownerId, $customerArea) : null;

        $agentUsername = null;
        $status = 'no_agent';
        if ($agent && csCallCenterLockAgent($conn, $agent['USERNAME'], $callId)) {
            $agentUsername = $agent['USERNAME'];
            $status = 'ringing';
        }

        $idEsc = mysqli_real_escape_string($conn, $callId);
        $ownerEsc = mysqli_real_escape_string($conn, $ownerUsername);
        $idpelEsc = mysqli_real_escape_string($conn, $customerIdpel);
        $nameEsc = mysqli_real_escape_string($conn, $customerName);
        $areaEsc = mysqli_real_escape_string($conn, $customerArea);
        $agentEsc = $agentUsername !== null ? "'" . mysqli_real_escape_string($conn, $agentUsername) . "'" : 'NULL';

        mysqli_query($conn, "INSERT INTO cs_call_center_calls
            (id, owner_key, customer_idpel, customer_name, customer_area, agent_username, status)
            VALUES ('$idEsc', '$ownerEsc', '$idpelEsc', '$nameEsc', '$areaEsc', $agentEsc, '$status')");

        return [
            'id' => $callId,
            'status' => $status,
            'agent_username' => $agentUsername,
        ];
    }
}

if (!function_exists('csCallCenterRerouteAfterDecline')) {
    /**
     * Agent declined (atau ring timeout, dianggap auto-decline) -- release
     * dia, cari agent lain (exclude yg sudah dicoba), assign atau habis
     * (no_agent). $reason dipakai sbg `ended_by` KALAU tidak ada lagi agent
     * yg bisa dicoba (mis. 'no_agent' utk manual decline, 'timeout' utk
     * ring timeout otomatis) -- biar riwayat telpon bisa bedain penyebabnya.
     */
    function csCallCenterRerouteAfterDecline($conn, array $call, string $reason = 'no_agent'): array
    {
        // PENTING: $call di sini bisa jadi snapshot BASI -- fungsi ini dipicu
        // dari csCallCenterExpireStale() yang dijalankan di SETIAP poll (baik
        // dari agent MAUPUN pelanggan). Kalau antara snapshot itu diambil dan
        // baris ini dieksekusi, call SUDAH keburu dijawab (status jadi
        // 'active' lewat action=answer di proses lain) -- reroute TIDAK BOLEH
        // menimpanya, kalau tidak: call yang sudah tersambung "direset" balik
        // jadi ringing/agent lain, agent yang sudah menjawab dilepas
        // busy-lock-nya padahal masih aktif di panggilan itu (bisa nyasar
        // dapat call lain), dan pelanggan nyangkut selamanya di layar
        // "ringing" walau agent sudah bilang "Tersambung". Jendela race ini
        // jauh lebih gampang kena di panggilan LINTAS JARINGAN (negosiasi
        // ICE/TURN jauh lebih lama drpd LAN, sehingga polling pelanggan lebih
        // sering "menyalip" agent yang masih proses menjawab). Makanya di
        // WHERE clause UPDATE di bawah SELALU ditambah `AND status='ringing'`
        // (guard atomic, re-verifikasi status TERKINI, bukan snapshot lama),
        // dan kalau ternyata 0 baris kena (sudah keburu berubah), batalkan
        // reroute ini total & balikan state TERBARU dari DB.
        $idEscGuard = mysqli_real_escape_string($conn, $call['id']);
        $freshCheck = mysqli_query($conn, "SELECT status FROM cs_call_center_calls WHERE id='$idEscGuard' LIMIT 1");
        $freshRow = $freshCheck ? mysqli_fetch_assoc($freshCheck) : null;
        if (!$freshRow || $freshRow['status'] !== 'ringing') {
            // Sudah tidak ringing lagi (sudah dijawab/diakhiri di tempat
            // lain) -- jangan sentuh apa-apa, kembalikan data TERBARU.
            $refetch = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$idEscGuard' LIMIT 1");
            $refetched = $refetch ? mysqli_fetch_assoc($refetch) : null;
            return $refetched ?: $call;
        }

        csCallCenterReleaseAgent($conn, $call['agent_username']);

        $attempted = [];
        if (!empty($call['attempted_agents'])) {
            $decoded = json_decode($call['attempted_agents'], true);
            if (is_array($decoded)) {
                $attempted = $decoded;
            }
        }
        $attempted[] = $call['agent_username'];

        $ownerId = csCallCenterResolveOwnerId($conn, $call['owner_key']);
        $agent = $ownerId ? csCallCenterFindAvailableAgent($conn, $ownerId, $call['customer_area'], $attempted) : null;

        $idEsc = mysqli_real_escape_string($conn, $call['id']);
        $attemptedJson = mysqli_real_escape_string($conn, json_encode($attempted));

        if ($agent && csCallCenterLockAgent($conn, $agent['USERNAME'], $call['id'])) {
            $agentEsc = mysqli_real_escape_string($conn, $agent['USERNAME']);
            // created_at di-reset ke NOW() supaya jendela TTL 45 detik
            // (csCallCenterExpireStale) mulai hitung ULANG utk agent baru --
            // tanpa ini, call yg sudah di-reroute (mis. declined agent 1 di
            // detik ke-40) bisa expired dlm hitungan detik di agent
            // berikutnya, sebelum sempat kejawab.
            // `AND status='ringing'` -- guard atomic kedua (defense in depth,
            // menutup sisa jendela race antara re-cek di atas dgn UPDATE ini)
            // supaya tidak menimpa call yg baru saja dijawab tepat di antara
            // keduanya.
            mysqli_query($conn, "UPDATE cs_call_center_calls SET agent_username='$agentEsc', status='ringing', attempted_agents='$attemptedJson', created_at=NOW() WHERE id='$idEsc' AND status='ringing'");
            if (mysqli_affected_rows($conn) === 0) {
                csCallCenterReleaseAgent($conn, $agent['USERNAME']);
                $refetch = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$idEsc' LIMIT 1");
                $refetched = $refetch ? mysqli_fetch_assoc($refetch) : null;
                return $refetched ?: $call;
            }
            $call['agent_username'] = $agent['USERNAME'];
            $call['status'] = 'ringing';
        } else {
            $reasonEsc = mysqli_real_escape_string($conn, $reason);
            mysqli_query($conn, "UPDATE cs_call_center_calls SET agent_username=NULL, status='no_agent', attempted_agents='$attemptedJson', ended_at=NOW(), ended_by='$reasonEsc' WHERE id='$idEsc' AND status='ringing'");
            if (mysqli_affected_rows($conn) === 0) {
                $refetch = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$idEsc' LIMIT 1");
                $refetched = $refetch ? mysqli_fetch_assoc($refetch) : null;
                return $refetched ?: $call;
            }
            $call['agent_username'] = null;
            $call['status'] = 'no_agent';
        }
        $call['attempted_agents'] = json_encode($attempted);

        return $call;
    }
}
