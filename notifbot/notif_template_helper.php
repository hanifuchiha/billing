<?php
/**
 * notif_template_helper.php
 *
 * Satu-satunya sumber kebenaran (single source of truth) utk lokasi & isi file
 * template notifikasi WA (REGISTRASI/EXPIRED/REMAINDER) per akun, dipakai
 * bersama oleh notification.php (halaman edit admin), proses/simpannotif.php,
 * proses/addcustomer.php, dan semua cron notifbot/notifphp/*.php.
 *
 * Sebelum file ini ada, tiap file di atas punya salinan sendiri-sendiri utk
 * membangun path & meng-extract section pakai regex -- beberapa salinan itu
 * sempat beda (bug regex REMAINDER kepotong di baris kosong pertama, tersebar
 * di 6 file berbeda) krn tidak ada satu tempat yang jadi acuan. Sekarang semua
 * file itu WAJIB pakai fungsi-fungsi di sini, supaya path & cara baca section
 * selalu konsisten dan cukup diperbaiki di satu tempat kalau ada perubahan lagi.
 */

if (!function_exists('notifTemplateDir')) {
    /**
     * Folder tempat semua file template tersimpan: crm/billing/notifdata/
     * Dihitung dari lokasi file INI sendiri (crm/billing/notifbot/), bukan dari
     * direktori kerja pemanggil -- supaya hasilnya SAMA persis dari mana pun
     * file ini di-require (notification.php di crm/billing/, atau cron di
     * crm/billing/notifbot/notifphp/), tidak lagi bergantung pada '../' vs
     * '../../' yang gampang salah hitung tiap file.
     */
    function notifTemplateDir(): string
    {
        return dirname(__DIR__) . '/notifdata/';
    }
}

if (!function_exists('notifTemplateFilePath')) {
    /**
     * Path lengkap file template utk 1 akun. $pemilik = USERNAME akun (kolom
     * user.USERNAME) -- sama persis dgn $ceknama yang dipakai notification.php,
     * atau nama akhir hasil parse nama file cron (mis. notif_remainder_pembayaran_
     * FIBERQ.php -> "FIBERQ").
     */
    function notifTemplateFilePath(string $pemilik): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $pemilik);
        return notifTemplateDir() . $safe . '.txt';
    }
}

if (!function_exists('notifTemplateDefaults')) {
    /**
     * Template default (dipakai kalau akun belum pernah simpan pesan sendiri).
     * Placeholder $var TETAP berupa teks literal "$var" (bukan interpolasi PHP)
     * -- nanti diganti oleh replaceVariables()/preg_replace_callback di script
     * pengirim, sesuai variabel yang tersedia di konteks masing-masing (lihat
     * daftar variabel di halaman Notifikasi).
     *
     * Return ['REGISTRASI' => string, 'EXPIRED' => string, 'REMAINDER' => string].
     */
    function notifTemplateDefaults(): array
    {
        return [
            'REGISTRASI' => "🎉 *Pelanggan Baru Terdaftar!*\n\n"
                . "🔹 *ID Pelanggan*   : \$customerID\n"
                . "🔹 *Nama*           : \$customerName\n"
                . "🔹 *Paket Layanan*  : \$packages\n"
                . "🔹 *Tanggal Pasang* : \$tanggalpasang\n"
                . "🔹 *Password PPPoE* : \$passwordPPPOE\n\n"
                . "📞 *WhatsApp*       : \$whatsappedit\n"
                . "📧 *Email*          : \$email\n"
                . "🏠 *Alamat*         : \$address\n\n"
                . "🖥 *Server*         : \$BRAND\n"
                . "🧭 *ODP*            : \$odp\n"
                . "📍 *Area*           : \$area\n"
                . "🗺️ *Koordinat*      : \$coordinates\n\n"
                . "💳 *Link Pembayaran:* [Klik untuk Bayar]( \$URL/crm/billing/broadband/portal.php?cari=\$customerID )\n"
                . "✅ Registrasi berhasil dan siap digunakan!\n\n"
                . "👨‍💼 *Sales*         : \$sales",
            'EXPIRED' => "⏰ *Pengingat Pembayaran Layanan*\n\n"
                . "🔹 *ID Pelanggan*   : \$IDPEL\n"
                . "🔹 *Nama Pelanggan* : \$NAMA\n"
                . "🔹 *Paket Layanan*  : \$PAKET\n\n"
                . "📞 *WhatsApp*       : \$NOWA\n"
                . "📧 *Email*          : \$EMAIL\n"
                . "🏠 *Alamat*         : \$ALAMAT\n\n"
                . "🖥 *Server*         : \$BRAND\n\n"
                . "💡 Layanan sudah *expired*. Silakan perbarui paket Anda segera agar layanan tetap aktif.\n\n"
                . "🔗 *Link Pembayaran:* \$URL/crm/billing/broadband/portal.php?cari=\$IDPEL",
            'REMAINDER' => "⏰ *Pengingat Pembayaran Layanan*\n\n"
                . "🔹 *ID Pelanggan*   : \$IDPEL\n"
                . "🔹 *Nama Pelanggan* : \$NAMA\n"
                . "🔹 *Paket Layanan*  : \$PAKET\n"
                . "🔹 *Jatuh Tempo*    : \$jatuh_tempo\n\n"
                . "📞 *WhatsApp*       : \$NOWA\n"
                . "📧 *Email*          : \$EMAIL\n"
                . "🏠 *Alamat*         : \$ALAMAT\n"
                . "🖥 *Server*         : \$BRAND\n\n"
                . "💡 Mohon lakukan pembayaran sebelum tanggal jatuh tempo agar layanan tetap aktif.\n\n"
                . "🔗 *Link Pembayaran:* \$URL/crm/billing/broadband/portal.php?cari=\$IDPEL\n\n"
                . "✅ Terima kasih atas perhatian Anda!",
        ];
    }
}

if (!function_exists('notifTemplateBuildFileContent')) {
    /**
     * Susun ulang isi file dari 3 section, format SAMA PERSIS dgn yang dibaca
     * semua consumer: "REGISTRASI:\n...\n\nEXPIRED:\n...\n\nREMAINDER:\n...".
     * REMAINDER SELALU section TERAKHIR (tidak ada trailing content sesudahnya).
     */
    function notifTemplateBuildFileContent(string $registrasi, string $expired, string $remainder): string
    {
        return "REGISTRASI:\n" . $registrasi . "\n\n"
            . "EXPIRED:\n" . $expired . "\n\n"
            . "REMAINDER:\n" . $remainder;
    }
}

if (!function_exists('notifTemplateExtractSection')) {
    /**
     * Ambil isi 1 section dari keseluruhan isi file. REGISTRASI & EXPIRED
     * berhenti di header section BERIKUTNYA (aman utk konten multi-paragraf
     * krn TIDAK bergantung baris kosong sbg pembatas). REMAINDER SELALU section
     * TERAKHIR, jadi ambil semua sisa teks sampai akhir file -- INI PERBAIKAN
     * dari bug lama yang berhenti di baris kosong pertama (rusak utk template
     * multi-paragraf, penyebab WA API menolak "message: cannot be blank").
     */
    function notifTemplateExtractSection(string $isi, string $section): string
    {
        $section = strtoupper(trim($section));
        $normalized = str_replace(["\r\n", "\r"], "\n", $isi);

        $pattern = null;
        if ($section === 'REGISTRASI') {
            $pattern = '/REGISTRASI:\n(.*?)\n\nEXPIRED:/s';
        } elseif ($section === 'EXPIRED') {
            $pattern = '/EXPIRED:\n(.*?)\n\nREMAINDER:/s';
        } elseif ($section === 'REMAINDER') {
            $pattern = '/REMAINDER:\n(.*)/s';
        }

        if ($pattern === null) {
            return '';
        }

        if (preg_match($pattern, $normalized, $match)) {
            return $match[1] ?? '';
        }

        return '';
    }
}

if (!function_exists('NOTIF_KHUSUS_TEMPLATE_COLUMNS')) {
    /**
     * Semua kolom pesan_* dikenal di tabel notif_khusus (termasuk yg BUKAN
     * bagian REGISTRASI/EXPIRED/REMAINDER, mis. pesan_ketentuan/disable/dll).
     * Dipakai supaya INSERT baris baru selalu mengisi SEMUA kolom sekaligus --
     * beberapa kolom NOT NULL tanpa DEFAULT di server, jadi INSERT yang cuma
     * mengisi sebagian akan gagal ("Field 'x' doesn't have a default value").
     */
    function NOTIF_KHUSUS_TEMPLATE_COLUMNS(): array
    {
        return [
            'pesan_registrasi', 'pesan_expired', 'pesan_reminder', 'pesan_ketentuan',
            'pesan_disable', 'pesan_aktif_manual', 'pesan_remainder_manual', 'pesan_dismantle_manual',
            'pesan_gangguan', 'pesan_gangguan_selesai', 'pesan_pembayaran_berhasil',
        ];
    }
}

if (!function_exists('notifTemplateDefaultPembayaranBerhasil')) {
    /**
     * Default teks "PEMBAYARAN BERHASIL" -- SEBELUMNYA di-hardcode identik di
     * SEMUA 8 file callback gateway (tripay/duitku/midtrans/xendit/ipaymu/
     * doku/faspay/dompetx), sama sekali tidak bisa diubah admin lewat
     * Notification Setting. $linkBukti diisi si pemanggil (link download
     * bukti beda antara callback pertama & callback resume/regenerate).
     */
    function notifTemplateDefaultPembayaranBerhasil(): string
    {
        return "[INI ADALAH PESAN OTOMATIS]\n*PEMBAYARAN BERHASIL*\n\n"
            . "Hai bpk/ibu \$NAMAPELANGGAN \nPembayaran anda Telah kami terima.\n\n\n\n"
            . "Dengan detail :\n"
            . "- ID Pelanggan : \$USERNAMETRANASAKSI \n"
            . "- Nama Pelanggan : \$NAMAPELANGGAN \n"
            . "- Paket langganan : \$PAKETPELANGGAN \n"
            . "- No Whatsapp : \$WHATSAPPELANGGAN \n"
            . "- E-mail : \$EMAILPELANGGAN \n"
            . "- Alamat : \$ALAMATPELANGGAN \n\n\n"
            . "Data transaksi :\n"
            . "- Periode pengunaan : \$periode\n"
            . "- Tanggal bayar : \$tanggalbayar\n"
            . "- Status INTERNET : AKTIF\n"
            . "- Status Pembayaran : \$cekstatus \n"
            . "- Nominal Bayar : \$amount \n"
            . "- No Ref : \$invoiceref \n"
            . "- Id pelanggan : \$USERNAMETRANASAKSI \n"
            . "- Metode pembayaran : \$payment_method \n"
            . "- Kode metode : \$payment_method_code\n\n"
            . "\$linkBukti\n\n"
            . "Pastikan modem Anda dalam keadaan menyala normal dan tidak ada lampu indikator merah (LOS).\n\n"
            . "Jika dalam waktu 1 jam setelah notifikasi ini internet belum aktif,Silakan hubungi kami, atau cabut dan pasang kembali adaptor listrik modem Anda untuk mempercepat proses aktivasi.\n\n"
            . "Demikian yang dapat kami sampaikan, terima kasih \n\n"
            . "Terima kasih telah mempercayai kami dalam kebutuhan internet Anda\nSalam \$BRANDPELANGGAN";
    }
}

if (!function_exists('notifTemplateGetPembayaranBerhasil')) {
    /**
     * Ambil template "Pembayaran Berhasil" custom milik $pemilik, fallback ke
     * default (notifTemplateDefaultPembayaranBerhasil()) kalau belum pernah
     * di-set -- supaya akun yang belum sentuh setting baru ini TIDAK berubah
     * perilaku pesannya (sama persis dgn teks lama yang di-hardcode).
     */
    function notifTemplateGetPembayaranBerhasil(string $pemilik): string
    {
        $custom = notifTemplateGetKhususColumn($pemilik, 'pesan_pembayaran_berhasil');
        return $custom !== '' ? $custom : notifTemplateDefaultPembayaranBerhasil();
    }
}

if (!function_exists('notifTemplateEnsureRow')) {
    /**
     * Pastikan baris notif_khusus utk $pemilik ada (dibuat dgn template
     * default -- notifTemplateDefaults() -- kalau belum ada), lalu return
     * isi REGISTRASI/EXPIRED/REMAINDER dari baris itu. Sumber kebenaran
     * REGISTRASI/EXPIRED/REMAINDER SEKARANG database (tabel notif_khusus),
     * BUKAN lagi file notifdata/*.txt -- lihat histori di
     * notifTemplateGetContent()/notifTemplateSaveSections() di bawah.
     */
    function notifTemplateEnsureRow(string $pemilik): array
    {
        global $conn;
        $defaults = notifTemplateDefaults();
        $fallback = [
            'pesan_registrasi' => $defaults['REGISTRASI'],
            'pesan_expired' => $defaults['EXPIRED'],
            'pesan_reminder' => $defaults['REMAINDER'],
        ];

        if (!$conn) {
            return $fallback;
        }

        $stmt = $conn->prepare('SELECT pesan_registrasi, pesan_expired, pesan_reminder FROM notif_khusus WHERE pemilik = ? LIMIT 1');
        if (!$stmt) {
            return $fallback;
        }
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($row) {
            return [
                'pesan_registrasi' => (string)($row['pesan_registrasi'] ?? ''),
                'pesan_expired' => (string)($row['pesan_expired'] ?? ''),
                'pesan_reminder' => (string)($row['pesan_reminder'] ?? ''),
            ];
        }

        // Belum ada baris utk pemilik ini -- buat dgn default, isi SEMUA
        // kolom pesan_* dikenal skrg (bukan cuma 3) spy tidak gagal krn kolom
        // lain NOT NULL tanpa default.
        $knownCols = NOTIF_KHUSUS_TEMPLATE_COLUMNS();
        $values = [];
        foreach ($knownCols as $c) {
            if ($c === 'pesan_registrasi') $values[] = $fallback['pesan_registrasi'];
            elseif ($c === 'pesan_expired') $values[] = $fallback['pesan_expired'];
            elseif ($c === 'pesan_reminder') $values[] = $fallback['pesan_reminder'];
            else $values[] = '';
        }
        $colsSql = implode(', ', array_map(function ($c) { return "`$c`"; }, $knownCols));
        $placeholders = implode(', ', array_fill(0, count($knownCols), '?'));
        $stmtIns = $conn->prepare("INSERT INTO notif_khusus (pemilik, $colsSql) VALUES (?, $placeholders)");
        if ($stmtIns) {
            $types = 's' . str_repeat('s', count($knownCols));
            $stmtIns->bind_param($types, $pemilik, ...$values);
            @$stmtIns->execute();
            $stmtIns->close();
        }

        return $fallback;
    }
}

if (!function_exists('notifTemplateGetContent')) {
    /**
     * Ambil isi 3 section (REGISTRASI/EXPIRED/REMAINDER) utk 1 akun, disusun
     * dlm format gabungan yg sama spt dulu (lihat notifTemplateBuildFileContent())
     * spy semua pemanggil lama (cron notifphp/*.php, addcustomer.php, dll --
     * yg cuma panggil fungsi ini lalu notifTemplateExtractSection()) TETAP
     * jalan tanpa perlu diubah, walau sumber datanya skrg database, bukan
     * file notifdata/*.txt lagi.
     */
    function notifTemplateGetContent(string $pemilik): string
    {
        $row = notifTemplateEnsureRow($pemilik);
        return notifTemplateBuildFileContent($row['pesan_registrasi'], $row['pesan_expired'], $row['pesan_reminder']);
    }
}

if (!function_exists('notifTemplateSaveSections')) {
    /**
     * Simpan section REGISTRASI/EXPIRED/REMAINDER ke database utk $pemilik.
     * Parameter null = section itu TIDAK diubah (tetap pakai nilai yg sudah
     * tersimpan skrg) -- dipakai pemanggil yg cuma mau ubah 1 section (mis.
     * form "Pesan Reminder" saja) tanpa menimpa section lain.
     */
    function notifTemplateSaveSections(string $pemilik, ?string $registrasi = null, ?string $expired = null, ?string $remainder = null): bool
    {
        global $conn;
        if (!$conn) {
            return false;
        }

        // Pastikan baris ada dulu (auto-create dgn default kalau belum ada)
        // spy UPDATE di bawah selalu menemukan barisnya.
        $current = notifTemplateEnsureRow($pemilik);
        $newRegistrasi = $registrasi !== null ? $registrasi : $current['pesan_registrasi'];
        $newExpired = $expired !== null ? $expired : $current['pesan_expired'];
        $newReminder = $remainder !== null ? $remainder : $current['pesan_reminder'];

        $stmt = $conn->prepare('UPDATE notif_khusus SET pesan_registrasi = ?, pesan_expired = ?, pesan_reminder = ? WHERE pemilik = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ssss', $newRegistrasi, $newExpired, $newReminder, $pemilik);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('notifTemplateGetSection')) {
    /**
     * Kombinasi notifTemplateGetContent() + notifTemplateExtractSection() --
     * yang paling sering dipakai consumer (cron WA): cukup 1 panggilan utk
     * dapat isi section yang siap dipakai replaceVariables().
     */
    function notifTemplateGetSection(string $pemilik, string $section): string
    {
        return notifTemplateExtractSection(notifTemplateGetContent($pemilik), $section);
    }
}

if (!function_exists('notifTemplateGetKhususColumn')) {
    /**
     * Getter generik utk kolom pesan_* di notif_khusus DI LUAR REGISTRASI/
     * EXPIRED/REMAINDER (mis. pesan_dismantle_manual, pesan_gangguan) --
     * kolom2 ini tidak lewat notifTemplateEnsureRow()/notifTemplateGetSection()
     * krn bukan bagian dari 3 section utama itu. $column HARUS salah satu dari
     * NOTIF_KHUSUS_TEMPLATE_COLUMNS() di atas.
     */
    function notifTemplateGetKhususColumn(string $pemilik, string $column): string
    {
        global $conn;
        if (!$conn || !in_array($column, NOTIF_KHUSUS_TEMPLATE_COLUMNS(), true)) {
            return '';
        }

        // Auto-migrasi kolom kalau belum ada -- notification.php juga sudah
        // punya migrasi serupa, tapi itu cuma jalan kalau admin BUKA halaman
        // itu dulu. Pemanggil kolom baru (mis. callback gateway pembayaran)
        // bisa saja jalan DULUAN sebelum admin sempat buka Notification
        // Setting, jadi dijamin di sini juga (idempoten, static per-request).
        static $columnsChecked = [];
        if (!isset($columnsChecked[$column])) {
            $columnsChecked[$column] = true;
            $checkCol = $conn->query("SHOW COLUMNS FROM notif_khusus LIKE '$column'");
            if ($checkCol && $checkCol->num_rows === 0) {
                $conn->query("ALTER TABLE notif_khusus ADD COLUMN `$column` TEXT");
            }
        }

        $stmt = $conn->prepare("SELECT `$column` FROM notif_khusus WHERE pemilik = ? LIMIT 1");
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('s', $pemilik);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string)($row[$column] ?? ''));
    }
}

if (!function_exists('notifTemplateReplaceVars')) {
    /**
     * Replace placeholder "$namavar" literal di template dgn nilai dari $vars
     * (key TANPA prefix $). Sama persis dgn fungsi replace_vars() lokal di
     * proses/addcustomer.php, disatukan di sini supaya pemanggil lain (mis.
     * proses_provisioning_action.php, tiket_manager.php) bisa pakai tanpa
     * duplikasi regex.
     */
    function notifTemplateReplaceVars(string $template, array $vars): string
    {
        return preg_replace_callback('/\$([a-zA-Z0-9_]+)/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string)$vars[$key] : $m[0];
        }, $template);
    }
}

if (!function_exists('notifSendWhatsappViaBot')) {
    /**
     * Kirim WA lewat bot milik $pemilik (tabel botwa) -- pola SAMA PERSIS dgn
     * blok kirim WA di proses/addcustomer.php (curl langsung ke gateway gowa
     * dgn device_id/X-Device-Id multi-device), disatukan di sini supaya bisa
     * dipakai pemanggil lain tanpa duplikasi ~80 baris curl. $botCategory
     * (opsional) baca bot_receiver_config-<pemilik>.json utk kategori bot
     * tertentu (mis. "pendaftaran"), fallback ke bot default reminder-<pemilik>.
     */
    function notifSendWhatsappViaBot(mysqli $conn, string $pemilik, string $nowa, string $message, string $botCategory = ''): array
    {
        if (trim($nowa) === '' || trim($message) === '') {
            return ['success' => false, 'message' => 'Nomor WA atau pesan kosong.'];
        }

        $botname = '';
        if ($botCategory !== '') {
            $botCategoryFile = __DIR__ . "/data/bot_receiver_config-$pemilik.json";
            if (file_exists($botCategoryFile)) {
                $botCategoryData = json_decode(file_get_contents($botCategoryFile), true);
                if (is_array($botCategoryData) && !empty($botCategoryData[$botCategory])) {
                    $botname = trim((string)$botCategoryData[$botCategory]);
                }
            }
        }
        if ($botname === '') {
            $jsonFile = __DIR__ . "/data/reminder-$pemilik.json";
            if (file_exists($jsonFile)) {
                $data = json_decode(file_get_contents($jsonFile), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        if (!empty($item['botname'])) {
                            $botname = trim((string)$item['botname']);
                            break;
                        }
                    }
                }
            }
        }
        if ($botname === '') {
            return ['success' => false, 'message' => 'Bot WA belum dikonfigurasi utk akun ini.'];
        }

        $waapi = '';
        $passwordbot = '';
        $sender = '';
        $stmtBot = $conn->prepare('SELECT addressbot, password, sender FROM botwa WHERE namebot = ? AND pemilik = ? LIMIT 1');
        $stmtBot->bind_param('ss', $botname, $pemilik);
        $stmtBot->execute();
        $rowBot = $stmtBot->get_result()->fetch_assoc();
        $stmtBot->close();
        if (!$rowBot) {
            $stmtBot = $conn->prepare('SELECT addressbot, password, sender FROM botwa WHERE namebot = ? LIMIT 1');
            $stmtBot->bind_param('s', $botname);
            $stmtBot->execute();
            $rowBot = $stmtBot->get_result()->fetch_assoc();
            $stmtBot->close();
        }
        if ($rowBot) {
            $waapi = (string)($rowBot['addressbot'] ?? '');
            $passwordbot = (string)($rowBot['password'] ?? '');
            $sender = (string)($rowBot['sender'] ?? '');
        }
        if ($waapi === '') {
            return ['success' => false, 'message' => "Konfigurasi bot '$botname' tidak ditemukan."];
        }

        $phone = preg_replace('/[^0-9]/', '', $nowa) . '@s.whatsapp.net';
        $deviceId = trim($sender);
        $url = rtrim($waapi, '/') . '/send/message?session=' . urlencode($botname);
        if ($deviceId !== '') {
            $url .= '&device_id=' . urlencode($deviceId);
        }
        $headers = ['Content-Type: application/json'];
        if ($deviceId !== '') {
            $headers[] = "X-Device-Id: $deviceId";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone' => $phone, 'message' => $message, 'sender' => $sender]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "$botname:$passwordbot");
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['success' => false, 'message' => "Gagal menghubungi gateway WA: $curlError"];
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'message' => (string)$response,
        ];
    }
}
