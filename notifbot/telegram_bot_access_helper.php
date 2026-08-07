<?php
/**
 * telegram_bot_access_helper.php
 *
 * Kontrol akses bot Telegram (tabel `bottelegram`) tingkat per-assistant --
 * SALINAN 1:1 pola `bot_access_helper.php` (bot WA), cuma ganti nama
 * tabel/kolom:
 *   - `botwa` -> `bottelegram`
 *   - `user.assigned_bots` -> `user.assigned_telegram_bots`
 * Semua penjelasan/alasan desain SAMA PERSIS dengan bot_access_helper.php,
 * lihat file itu untuk detail.
 *
 * WAJIB dipakai (bukan filter manual sendiri2) oleh SEMUA halaman yang query
 * tabel `bottelegram` berdasarkan `pemilik`: telegrambot.php, dan titik kirim
 * notifikasi yang menawarkan kanal Telegram (proses/notif_gangguan.php,
 * proses/notif_manual.php, proses/notif_menunggak_manual.php).
 */

if (!function_exists('telegramBotAccessEnsureColumns')) {
    function telegramBotAccessEnsureColumns($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $colUser = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'assigned_telegram_bots'");
        if ($colUser && mysqli_num_rows($colUser) === 0) {
            @mysqli_query($conn, "ALTER TABLE user ADD COLUMN assigned_telegram_bots TEXT DEFAULT NULL");
        }

        $tableCheck = @mysqli_query($conn, "SHOW TABLES LIKE 'bottelegram'");
        if ($tableCheck && mysqli_num_rows($tableCheck) === 0) {
            @mysqli_query($conn, "CREATE TABLE `bottelegram` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `namebot` VARCHAR(150) NOT NULL,
                `botusername` VARCHAR(100) DEFAULT NULL,
                `bottoken` VARCHAR(255) NOT NULL,
                `pemilik` VARCHAR(100) NOT NULL,
                `penerima` VARCHAR(100) DEFAULT NULL,
                `penerima_server` VARCHAR(100) DEFAULT NULL,
                `penerima_livechat` VARCHAR(100) DEFAULT NULL,
                `penerima_system_notif` VARCHAR(100) DEFAULT NULL,
                `penerima_manual_active` VARCHAR(100) DEFAULT NULL,
                `penerima_odp_los` VARCHAR(100) DEFAULT NULL,
                `penerima_provisioning` VARCHAR(100) DEFAULT NULL,
                `created_by_assistant` VARCHAR(100) DEFAULT NULL,
                `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        } else {
            $colCreatedBy = @mysqli_query($conn, "SHOW COLUMNS FROM bottelegram LIKE 'created_by_assistant'");
            if ($colCreatedBy && mysqli_num_rows($colCreatedBy) === 0) {
                @mysqli_query($conn, "ALTER TABLE bottelegram ADD COLUMN created_by_assistant VARCHAR(100) DEFAULT NULL");
            }
        }
    }
}

if (!function_exists('telegramBotAccessIsRestricted')) {
    // true kalau sesi ini perlu dibatasi (ASSISTANT) -- owner/admin selalu
    // unrestricted (perilaku lama, lihat SEMUA bot pemilik = $ceknama).
    function telegramBotAccessIsRestricted(string $AKSES): bool
    {
        return $AKSES === 'ASSISTANT';
    }
}

if (!function_exists('telegramBotAccessWhereClause')) {
    /**
     * Bangun potongan SQL " AND (...)" utk ditempel ke query yang SUDAH ada
     * `WHERE pemilik = ?`. Return string KOSONG kalau sesi tidak perlu
     * dibatasi (owner/admin -- TIDAK ada perubahan perilaku).
     *
     * $assignedBotIds: array int, hasil decode kolom user.assigned_telegram_bots
     * milik BARIS ASSISTANT yang login ($assigned_telegram_bot_ids di cek-sesi.php).
     * $asistantName: username assistant yang login ($asistant_name).
     */
    function telegramBotAccessWhereClause($conn, string $AKSES, array $assignedBotIds, string $asistantName): string
    {
        if (!telegramBotAccessIsRestricted($AKSES)) {
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

if (!function_exists('telegramBotAccessCanManage')) {
    /**
     * Cek apakah sesi ASSISTANT ini boleh EDIT/HAPUS/ubah setting 1 baris bot
     * Telegram spesifik ($botRow, hasil SELECT * FROM bottelegram WHERE id=...).
     */
    function telegramBotAccessCanManage(string $AKSES, array $assignedBotIds, string $asistantName, array $botRow): bool
    {
        if (!telegramBotAccessIsRestricted($AKSES)) {
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
