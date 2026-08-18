<?php
/**
 * livechat_access_helper.php
 *
 * Kontrol akses Live Chat (crm/chat/) tingkat per-assistant, pola SAMA
 * PERSIS dgn notifbot/bot_access_helper.php (assigned_bots) -- bedanya
 * granularitas assignment di sini AREA (bukan bot), krn tabel `messages`
 * tidak py kolom bot/channel apapun utk difilter per-bot.
 *
 * Mekanisme:
 *   1. Owner bisa assign AREA spesifik (lewat kolom `user.assigned_livechat_areas`,
 *      JSON array nama AREA) ke assistant tertentu -- kalau kosong/tidak
 *      diassign, fallback ke area umum assistant tsb ($arealist dari
 *      cek-sesi.php, sumber yg sama dipakai halaman lain utk scoping area).
 *   2. crm/chat/ adalah app terpisah (koneksi DB & bootstrap sendiri) --
 *      TIDAK bisa baca session/helper crm/billing/ langsung, jadi hasil
 *      resolve area di sini dikirim via query string (`&areas=...`) ke
 *      iframe crm/chat/index.php, dibaca crm/chat/load_contacts.php.
 */

if (!function_exists('livechatAccessEnsureColumn')) {
    function livechatAccessEnsureColumn($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'assigned_livechat_areas'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN assigned_livechat_areas TEXT DEFAULT NULL");
        }
    }
}

if (!function_exists('livechatAccessResolveAreas')) {
    /**
     * Return array nama AREA yang boleh dilihat sesi ini di Live Chat.
     * Array KOSONG berarti TIDAK DIBATASI (owner/admin -- caller jangan
     * kirim parameter `areas` ke iframe sama sekali kalau hasilnya kosong
     * DAN $AKSES bukan ASSISTANT).
     */
    function livechatAccessResolveAreas(string $AKSES, array $assignedAreas, array $fallbackAreas): array
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
