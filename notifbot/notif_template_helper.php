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
            'pesan_gangguan', 'pesan_gangguan_selesai',
        ];
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
