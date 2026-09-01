<?php

if (!function_exists('waNotifEnsureSchema')) {
    function waNotifEnsureSchema(mysqli $conn): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `whatsapp_notification_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dedupe_key` CHAR(64) NOT NULL,
            `pemilik` VARCHAR(100) NOT NULL,
            `idpel` VARCHAR(255) NOT NULL,
            `nomor_wa` VARCHAR(40) NOT NULL,
            `periode` VARCHAR(40) NOT NULL,
            `jenis_notifikasi` VARCHAR(40) NOT NULL,
            `message_hash` CHAR(64) NOT NULL,
            `message` TEXT NOT NULL,
            `bot_name` VARCHAR(100) DEFAULT NULL,
            `status` ENUM('pending','sending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
            `http_code` SMALLINT UNSIGNED DEFAULT NULL,
            `response_message` TEXT DEFAULT NULL,
            `skip_reason` VARCHAR(255) DEFAULT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `scheduled_at` DATETIME DEFAULT NULL,
            `sent_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_whatsapp_notification_dedupe` (`dedupe_key`),
            KEY `idx_whatsapp_notification_status_schedule` (`status`, `scheduled_at`),
            KEY `idx_whatsapp_notification_customer_period` (`idpel`, `periode`),
            KEY `idx_whatsapp_notification_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$conn->query($sql)) {
            throw new RuntimeException('Gagal menyiapkan whatsapp_notification_log: ' . $conn->error);
        }
        $ready = true;
    }
}
if (!function_exists('waNotifQueueAndClaim')) {
    /**
     * Simpan pekerjaan lalu klaim secara atomik. Hanya satu proses boleh
     * mengirim kombinasi pemilik + IDPEL + periode + jenis notifikasi.
     */
    function waNotifQueueAndClaim(mysqli $conn, array $payload): array
    {
        waNotifEnsureSchema($conn);

        $pemilik = trim((string)($payload['pemilik'] ?? ''));
        $idpel = trim((string)($payload['idpel'] ?? ''));
        $nomorWa = trim((string)($payload['nomor_wa'] ?? ''));
        $periode = trim((string)($payload['periode'] ?? ''));
        $jenis = trim((string)($payload['jenis_notifikasi'] ?? 'reminder'));
        $message = (string)($payload['message'] ?? '');
        $botName = trim((string)($payload['bot_name'] ?? ''));

        if ($pemilik === '' || $idpel === '' || $periode === '' || $jenis === '') {
            throw new InvalidArgumentException('Identitas notifikasi WhatsApp tidak lengkap.');
        }

        $dedupeKey = hash('sha256', $pemilik . '|' . $idpel . '|' . $periode . '|' . $jenis);
        $messageHash = hash('sha256', $message);

        $sql = "INSERT INTO whatsapp_notification_log
                (dedupe_key,pemilik,idpel,nomor_wa,periode,jenis_notifikasi,message_hash,message,bot_name,status,scheduled_at)
                VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW())
                ON DUPLICATE KEY UPDATE
                    id=LAST_INSERT_ID(id),
                    nomor_wa=VALUES(nomor_wa),
                    message_hash=VALUES(message_hash),
                    message=VALUES(message),
                    bot_name=VALUES(bot_name),
                    status=CASE
                        WHEN status IN ('sent','sending') THEN status
                        ELSE 'pending'
                    END,
                    scheduled_at=CASE WHEN status='sent' THEN scheduled_at ELSE NOW() END,
                    skip_reason=CASE WHEN status='sent' THEN skip_reason ELSE NULL END";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan antrean WhatsApp: ' . $conn->error);
        }
        $stmt->bind_param('sssssssss', $dedupeKey, $pemilik, $idpel, $nomorWa, $periode, $jenis, $messageHash, $message, $botName);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Gagal menyimpan antrean WhatsApp: ' . $error);
        }
        $notificationId = (int)$conn->insert_id;
        $stmt->close();

        $claim = $conn->prepare("UPDATE whatsapp_notification_log
            SET status='sending', attempts=attempts+1, http_code=NULL,
                response_message=NULL, skip_reason=NULL, updated_at=NOW()
            WHERE id=? AND (
                status IN ('pending','failed','skipped')
                OR (status='sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE))
            )");
        $claim->bind_param('i', $notificationId);
        $claim->execute();
        $claimed = $claim->affected_rows === 1;
        $claim->close();

        if ($claimed) {
            return ['id' => $notificationId, 'claimed' => true, 'status' => 'sending'];
        }

        $status = 'unknown';
        $check = $conn->prepare('SELECT status FROM whatsapp_notification_log WHERE id=? LIMIT 1');
        $check->bind_param('i', $notificationId);
        $check->execute();
        $check->bind_result($statusDb);
        if ($check->fetch()) {
            $status = (string)$statusDb;
        }
        $check->close();
        return ['id' => $notificationId, 'claimed' => false, 'status' => $status];
    }
}

if (!function_exists('waNotifFinish')) {
    function waNotifFinish(mysqli $conn, int $notificationId, bool $success, int $httpCode, string $responseMessage): void
    {
        if ($notificationId <= 0) {
            return;
        }
        $status = $success ? 'sent' : 'failed';
        $responseMessage = mb_substr($responseMessage, 0, 4000, 'UTF-8');
        $stmt = $conn->prepare("UPDATE whatsapp_notification_log
            SET status=?, http_code=?, response_message=?,
                sent_at=CASE WHEN ?='sent' THEN NOW() ELSE sent_at END,
                updated_at=NOW()
            WHERE id=?");
        $stmt->bind_param('sissi', $status, $httpCode, $responseMessage, $status, $notificationId);
        $stmt->execute();
        $stmt->close();
    }
}
