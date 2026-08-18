<?php
/**
 * chat_activity_helper.php
 *
 * Log ringkas SEMUA pesan MASUK dari pelanggan (Live Chat + WA bot) per
 * tenant, dipakai fitur "Catatan Kejadian" (tab baru di modal Live Chat) --
 * ringkasan per jam dalam 24 jam terakhir dari keluhan/laporan/kejadian,
 * dibuat AI pakai config yang SAMA dengan Pengaturan AI Bot Live Chat
 * (provider/api key/model), TERLEPAS dari toggle AI Bot ON/OFF -- fitur ini
 * beda dari auto-reply, cukup butuh API key AI-nya sudah terisi.
 */

if (!function_exists('chatActivityEnsureTables')) {
    function chatActivityEnsureTables($conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS chat_event_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            pemilik VARCHAR(100) NOT NULL,
            identity VARCHAR(150) NOT NULL,
            customer_name VARCHAR(150) NOT NULL DEFAULT '',
            channel ENUM('livechat','wabot') NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pemilik_time (pemilik, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS chat_hourly_digest (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            pemilik VARCHAR(100) NOT NULL,
            hour_bucket DATETIME NOT NULL,
            message_count INT NOT NULL DEFAULT 0,
            summary_text TEXT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pemilik_hour (pemilik, hour_bucket)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('chatActivityLog')) {
    /**
     * Catat 1 pesan MASUK dari pelanggan. $channel = 'livechat' | 'wabot'.
     * Dipanggil dari chat_send.php (Live Chat) & botrespon.php (WA bot) --
     * HANYA pesan yang benar-benar datang dari pelanggan (bukan balasan
     * admin/bot), supaya "Catatan Kejadian" isinya murni hal yang perlu
     * ditindaklanjuti, bukan aktivitas admin sendiri.
     */
    function chatActivityLog($conn, $pemilik, $identity, $customerName, $channel, $message)
    {
        $pemilik = trim((string)$pemilik);
        $message = trim((string)$message);
        if ($pemilik === '' || $message === '') {
            return false;
        }

        chatActivityEnsureTables($conn);

        $stmt = mysqli_prepare($conn, "INSERT INTO chat_event_log (pemilik, identity, customer_name, channel, message) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $identity = (string)$identity;
        $customerName = (string)$customerName;
        mysqli_stmt_bind_param($stmt, 'sssss', $pemilik, $identity, $customerName, $channel, $message);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('chatActivitySummarizeMessages')) {
    /**
     * Ringkas sekumpulan pesan pelanggan (1 jam) jadi beberapa poin singkat,
     * pakai AI dgn config yang SAMA persis dgn Pengaturan AI Bot Live Chat
     * (livechatAiGetSettings()). Kalau API key AI belum diisi sama sekali,
     * atau panggilan AI gagal, fallback ke daftar pesan polos (tanpa AI) --
     * supaya tab tetap berguna walau AI belum/gagal dikonfigurasi.
     */
    function chatActivitySummarizeMessages($conn, $pemilik, array $rows)
    {
        if (empty($rows)) {
            return 'Tidak ada kejadian.';
        }

        $plainFallback = function () use ($rows) {
            $lines = [];
            foreach ($rows as $r) {
                $nama = trim((string)($r['customer_name'] ?? '')) ?: (string)($r['identity'] ?? '');
                $lines[] = '• ' . $nama . ': ' . mb_substr(trim((string)$r['message']), 0, 150, 'UTF-8');
            }
            return implode("\n", $lines);
        };

        require_once __DIR__ . '/livechat_ai_helper.php';
        require_once __DIR__ . '/../webhook/ai_provider_helper.php';

        $settings = livechatAiGetSettings($conn, $pemilik);
        if (trim((string)($settings['ai_api_key'] ?? '')) === '') {
            return $plainFallback();
        }

        $transcript = '';
        foreach ($rows as $r) {
            $nama = trim((string)($r['customer_name'] ?? '')) ?: (string)($r['identity'] ?? '');
            $channelLabel = ($r['channel'] ?? '') === 'wabot' ? 'WhatsApp Bot' : 'Live Chat';
            $transcript .= "[$channelLabel] $nama: " . trim((string)$r['message']) . "\n";
        }

        $systemPrompt = "Kamu adalah asisten yang meringkas pesan masuk dari pelanggan penyedia layanan internet dalam 1 jam terakhir. "
            . "Dari daftar pesan berikut, buat ringkasan SINGKAT berupa poin-poin (bullet, pakai tanda '•'), "
            . "fokus HANYA pada keluhan, laporan gangguan, atau kejadian penting yang perlu ditindaklanjuti admin. "
            . "Sebutkan nama pelanggan di tiap poin. Abaikan basa-basi/obrolan yang tidak penting. "
            . "Kalau tidak ada hal penting sama sekali, jawab persis: 'Tidak ada kejadian penting.' "
            . "Jawab dalam Bahasa Indonesia, singkat, maksimal 5 poin.";

        $summary = aiChatComplete($settings, $systemPrompt, $transcript);
        if ($summary === '' || strpos(trim($summary), '❌') === 0) {
            error_log('[chat_activity] Gagal ringkas AI utk ' . $pemilik . ': ' . $summary);
            return $plainFallback();
        }
        return trim($summary);
    }
}

if (!function_exists('chatActivityGetHourlyDigest')) {
    /**
     * Return array of ['hour_bucket', 'hour_label', 'date_label',
     * 'message_count', 'summary', 'is_current_hour'] utk $limitHours jam
     * terakhir, diurut dari jam terbaru ke terlama. Jam yang SUDAH LEWAT
     * di-cache di tabel chat_hourly_digest (tidak panggil AI ulang tiap tab
     * dibuka, KECUALI jumlah pesannya berubah dari cache -- jaga-jaga kalau
     * ada race/keterlambatan insert). Jam yang masih BERJALAN selalu
     * diringkas ulang (fresh) krn datanya masih terus bertambah.
     */
    function chatActivityGetHourlyDigest($conn, $pemilik, $limitHours = 24)
    {
        chatActivityEnsureTables($conn);
        $pemilik = trim((string)$pemilik);
        if ($pemilik === '') {
            return [];
        }

        $since = date('Y-m-d H:i:s', time() - ($limitHours * 3600));

        // LIVECHAT_ADMIN_PARAM (dipakai sidebar.php utk semua tab modal Live
        // Chat, termasuk tab ini) kirim literal string 'admin' utk akun
        // ber-STATUS ADMIN -- tapi chat_event_log SELALU nyimpen PEMILIK asli
        // tenant (mis. 'FIBERQ'), TIDAK PERNAH literal 'admin'. Kalau
        // dipaksa WHERE pemilik='admin', hasilnya SELALU kosong walau
        // datanya ada (bug asli yang dilaporkan). Utk akun ADMIN, tampilkan
        // gabungan SEMUA tenant (konsisten dgn pola akses ADMIN di
        // export_excel.php/statistics.php dkk yang juga lihat semua tenant).
        if ($pemilik === 'admin') {
            $stmt = mysqli_prepare($conn, "SELECT identity, customer_name, channel, message, created_at FROM chat_event_log WHERE created_at >= ? ORDER BY created_at ASC");
            if (!$stmt) {
                return [];
            }
            mysqli_stmt_bind_param($stmt, 's', $since);
        } else {
            $stmt = mysqli_prepare($conn, "SELECT identity, customer_name, channel, message, created_at FROM chat_event_log WHERE pemilik = ? AND created_at >= ? ORDER BY created_at ASC");
            if (!$stmt) {
                return [];
            }
            mysqli_stmt_bind_param($stmt, 'ss', $pemilik, $since);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $buckets = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $hourKey = date('Y-m-d H:00:00', strtotime($row['created_at']));
            $buckets[$hourKey][] = $row;
        }
        mysqli_stmt_close($stmt);

        $currentHourKey = date('Y-m-d H:00:00');
        $digest = [];

        foreach ($buckets as $hourKey => $rows) {
            $isCurrentHour = ($hourKey === $currentHourKey);
            $summary = null;

            if (!$isCurrentHour) {
                $stmtCache = mysqli_prepare($conn, "SELECT summary_text, message_count FROM chat_hourly_digest WHERE pemilik = ? AND hour_bucket = ? LIMIT 1");
                mysqli_stmt_bind_param($stmtCache, 'ss', $pemilik, $hourKey);
                mysqli_stmt_execute($stmtCache);
                $resCache = mysqli_stmt_get_result($stmtCache);
                $cacheRow = $resCache ? mysqli_fetch_assoc($resCache) : null;
                mysqli_stmt_close($stmtCache);

                if ($cacheRow && (int)$cacheRow['message_count'] === count($rows)) {
                    $summary = $cacheRow['summary_text'];
                }
            }

            if ($summary === null) {
                $summary = chatActivitySummarizeMessages($conn, $pemilik, $rows);
                if (!$isCurrentHour) {
                    $stmtSave = mysqli_prepare($conn, "INSERT INTO chat_hourly_digest (pemilik, hour_bucket, message_count, summary_text) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE message_count = VALUES(message_count), summary_text = VALUES(summary_text), generated_at = NOW()");
                    $cnt = count($rows);
                    mysqli_stmt_bind_param($stmtSave, 'ssis', $pemilik, $hourKey, $cnt, $summary);
                    mysqli_stmt_execute($stmtSave);
                    mysqli_stmt_close($stmtSave);
                }
            }

            $digest[] = [
                'hour_bucket' => $hourKey,
                'hour_label' => date('H:i', strtotime($hourKey)) . ' - ' . date('H:i', strtotime($hourKey) + 3599),
                'date_label' => date('d/m/Y', strtotime($hourKey)),
                'message_count' => count($rows),
                'summary' => $summary,
                'is_current_hour' => $isCurrentHour,
            ];
        }

        usort($digest, function ($a, $b) {
            return strcmp($b['hour_bucket'], $a['hour_bucket']);
        });

        return $digest;
    }
}
