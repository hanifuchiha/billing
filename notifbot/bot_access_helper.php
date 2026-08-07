<?php
/**
 * bot_access_helper.php
 *
 * Kontrol akses bot WA (tabel `botwa`) tingkat per-assistant. Sebelumnya
 * SEMUA bot milik akun (`pemilik` = $ceknama, yang utk sesi ASSISTANT selalu
 * berisi username OWNER) otomatis terlihat/terpakai oleh SEMUA assistant akun
 * itu tanpa pembeda -- helper ini menambahkan 2 mekanisme:
 *   1. Owner bisa assign bot spesifik (existing) ke assistant tertentu lewat
 *      kolom `user.assigned_bots` (JSON array of botwa.id) -- pola SAMA
 *      PERSIS dgn `user.server` (assign AREA/product ke assistant), lihat
 *      user.php & cek-sesi.php.
 *   2. Assistant yang TIDAK diberi assignment apapun tetap bisa BUAT bot
 *      sendiri (ditandai kolom `botwa.created_by_assistant`), dan bot itu
 *      PRIVATE hanya utk assistant tsb -- assistant lain di akun yang sama
 *      TIDAK ikut melihatnya.
 *
 * WAJIB dipakai (bukan filter manual sendiri2) oleh SEMUA halaman yang query
 * tabel `botwa` berdasarkan `pemilik`: wabot.php, broadcast.php,
 * notification.php, livechat.php, sidebar.php (modal bot), tables.php (kirim
 * invoice), get_user_bots.php, get_wabot_list.php, dan proses simpan bot baru
 * (proses/addbot.php dkk, utk penandaan created_by_assistant).
 */

if (!function_exists('botAccessEnsureColumns')) {
    function botAccessEnsureColumns($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $colUser = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'assigned_bots'");
        if ($colUser && mysqli_num_rows($colUser) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN assigned_bots TEXT DEFAULT NULL");
        }
        $colBot = @mysqli_query($conn, "SHOW COLUMNS FROM botwa LIKE 'created_by_assistant'");
        if ($colBot && mysqli_num_rows($colBot) === 0) {
            @mysqli_query($conn, "ALTER TABLE botwa ADD COLUMN created_by_assistant VARCHAR(100) DEFAULT NULL");
        }
    }
}

if (!function_exists('botAccessIsRestricted')) {
    // true kalau sesi ini perlu dibatasi (ASSISTANT) -- owner/admin selalu
    // unrestricted (perilaku lama, lihat SEMUA bot pemilik = $ceknama).
    function botAccessIsRestricted(string $AKSES): bool
    {
        return $AKSES === 'ASSISTANT';
    }
}

if (!function_exists('botAccessWhereClause')) {
    /**
     * Bangun potongan SQL " AND (...)" utk ditempel ke query yang SUDAH ada
     * `WHERE pemilik = ?`. Return string KOSONG kalau sesi tidak perlu
     * dibatasi (owner/admin -- TIDAK ada perubahan perilaku).
     *
     * $assignedBotIds: array int, hasil decode kolom user.assigned_bots milik
     * BARIS ASSISTANT yang login (disiapkan di cek-sesi.php sbg $assigned_bot_ids).
     * $asistantName: username assistant yang login ($asistant_name).
     */
    function botAccessWhereClause($conn, string $AKSES, array $assignedBotIds, string $asistantName): string
    {
        if (!botAccessIsRestricted($AKSES)) {
            return '';
        }
        $parts = [];
        $idsClean = array_values(array_unique(array_filter(array_map('intval', $assignedBotIds))));
        if (!empty($idsClean)) {
            $parts[] = 'id IN (' . implode(',', $idsClean) . ')';
        }
        if ($asistantName !== '') {
            $parts[] = "created_by_assistant = '" . mysqli_real_escape_string($conn, $asistantName) . "'";
        }
        if (empty($parts)) {
            // Assistant belum di-assign apapun DAN belum pernah buat bot
            // sendiri -- jangan tampilkan bot apapun (bukan "0 kondisi = lolos semua").
            return ' AND 1=0';
        }
        return ' AND (' . implode(' OR ', $parts) . ')';
    }
}

if (!function_exists('botAccessCanManage')) {
    /**
     * Cek apakah sesi ASSISTANT ini boleh EDIT/HAPUS/ubah setting 1 baris bot
     * spesifik ($botRow, hasil SELECT * FROM botwa WHERE id=...). Dipakai utk
     * memperketat cek $isAdminAccess yang sudah ada di wabot.php (yang
     * sebelumnya cuma bandingkan $botOwner === $ceknama -- SELALU true utk
     * SEMUA assistant krn $ceknama = username OWNER, bukan assistant itu
     * sendiri, jadi tidak pernah benar-benar membedakan antar-assistant).
     */
    function botAccessCanManage(string $AKSES, array $assignedBotIds, string $asistantName, array $botRow): bool
    {
        if (!botAccessIsRestricted($AKSES)) {
            return true;
        }
        $botId = (int)($botRow['id'] ?? 0);
        if (in_array($botId, $assignedBotIds, true)) {
            return true;
        }
        $createdBy = trim((string)($botRow['created_by_assistant'] ?? ''));
        return $createdBy !== '' && $createdBy === $asistantName;
    }
}
