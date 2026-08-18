<?php
/**
 * callcenter_access_helper.php
 *
 * Kontrol akses CS Call Center tingkat per-assistant, pola SAMA PERSIS dgn
 * livechat_access_helper.php -- assistant (agent) hanya boleh terima call
 * dari pelanggan yang AREA-nya masuk daftar area yang di-assign khusus utk
 * call center, fallback ke area umum assistant ($arealist dari cek-sesi.php)
 * kalau belum diassign apa-apa.
 *
 * Kolom tambahan di tabel `user` (semua di-ensure lewat
 * callcenterAccessEnsureColumn(), dipanggil sekali dari cek-sesi.php):
 *   - assigned_callcenter_areas   TEXT   (JSON array nama AREA)
 *   - callcenter_agent_enabled    TINYINT(1) DEFAULT 0   (toggle on/off milik assistant)
 *   - callcenter_last_heartbeat   TIMESTAMP NULL          (dipakai owner utk tentukan "aktif")
 *   - callcenter_current_call_id  VARCHAR(50) NULL        (busy-lock: non-NULL = sedang 1 call)
 */

if (!function_exists('callcenterAccessEnsureColumn')) {
    function callcenterAccessEnsureColumn($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'assigned_callcenter_areas'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN assigned_callcenter_areas TEXT DEFAULT NULL");
        }

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'callcenter_agent_enabled'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN callcenter_agent_enabled TINYINT(1) NOT NULL DEFAULT 0");
        }

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'callcenter_last_heartbeat'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN callcenter_last_heartbeat TIMESTAMP NULL DEFAULT NULL");
        }

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'callcenter_current_call_id'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN callcenter_current_call_id VARCHAR(50) DEFAULT NULL");
        }
    }
}

if (!function_exists('callcenterAccessResolveAreas')) {
    /**
     * Return array nama AREA yang boleh dilayani sesi/assistant ini di CS
     * Call Center. Array KOSONG berarti TIDAK DIBATASI (owner/admin).
     */
    function callcenterAccessResolveAreas(string $AKSES, array $assignedAreas, array $fallbackAreas): array
    {
        if ($AKSES !== 'ASSISTANT') {
            return [];
        }
        $assignedClean = array_values(array_unique(array_filter(array_map('strval', $assignedAreas), fn($a) => trim($a) !== '')));
        if (!empty($assignedClean)) {
            return $assignedClean;
        }
        return array_values(array_unique(array_filter(array_map('strval', $fallbackAreas), fn($a) => trim($a) !== '')));
    }
}
