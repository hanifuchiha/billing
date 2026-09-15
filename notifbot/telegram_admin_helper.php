<?php
/**
 * telegram_admin_helper.php
 *
 * "Bot Admin Billing" -- lapisan di atas notifbot/telegram_webhook.php.
 * Chat_id yang terdaftar di `bottelegram.admin_chat_ids` (atau semua chat kalau
 * `admin_allow_all=1`) boleh kirim perintah utk MENGECEK data billing.
 * Semua balasan dikirim TEKS POLOS (tanpa parse_mode) supaya tidak kena error
 * Telegram "can't parse entities" gara-gara karakter _ * [ ` di dalam data.
 *
 * Scoping: semua query dibatasi ke daftar PEMILIK milik owner bot.
 */

// Inti aksi APPROVE / REJECT provisioning (dipakai bareng versi web
// proses_provisioning_action.php). Dibutuhkan oleh command /provisioning dst.
require_once __DIR__ . '/../provisioning_action_lib.php';

if (!function_exists('telegramAdminEnsureColumns')) {
    function telegramAdminEnsureColumns($conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $tableCheck = @mysqli_query($conn, "SHOW TABLES LIKE 'bottelegram'");
        if (!$tableCheck || mysqli_num_rows($tableCheck) === 0) {
            return;
        }

        $cols = [
            'admin_enabled'         => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_allow_all'       => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_use_password'    => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_password'        => "VARCHAR(190) NULL",
            'admin_chat_ids'        => "TEXT NULL",
            'admin_perm_pelanggan'  => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_server'     => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_odp'        => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_tagihan'    => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_menunggak'  => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_transaksi'  => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_tiket'      => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_buat_tiket' => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_statistik'  => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_generate'   => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_livechat'   => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_expired'    => "TINYINT(1) NOT NULL DEFAULT 1",
            'admin_perm_aksi'       => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_perm_provisioning' => "TINYINT(1) NOT NULL DEFAULT 0",
            'admin_menu_custom'     => "TEXT NULL",
            'admin_welcome'         => "TEXT NULL",
            'admin_tpl_pelanggan'   => "TEXT NULL",
            'admin_tpl_tagihan'     => "TEXT NULL",
        ];
        foreach ($cols as $name => $ddl) {
            $c = @mysqli_query($conn, "SHOW COLUMNS FROM `bottelegram` LIKE '" . $name . "'");
            if ($c && mysqli_num_rows($c) === 0) {
                @mysqli_query($conn, "ALTER TABLE `bottelegram` ADD COLUMN `$name` $ddl");
            }
        }
    }
}

if (!function_exists('telegramAdminPermList')) {
    /** key kolom => label utk UI & menu. */
    function telegramAdminPermList(): array
    {
        return [
            'admin_perm_pelanggan' => 'Data & status pelanggan',
            'admin_perm_server'    => 'Data & status server',
            'admin_perm_odp'       => 'Data ODP + online/offline per ODP',
            'admin_perm_tagihan'   => 'Tagihan / riwayat bayar pelanggan',
            'admin_perm_menunggak' => 'Rekap tunggakan',
            'admin_perm_transaksi' => 'Rekap transaksi / omzet',
            'admin_perm_tiket'     => 'Tiket gangguan',
            'admin_perm_buat_tiket' => 'BUAT tiket gangguan (aksi tulis!)',
            'admin_perm_statistik' => 'Statistik ringkas',
            'admin_perm_generate'  => 'GENERATE INVOICE manual (aksi tulis!)',
            'admin_perm_livechat'  => 'Live chat & Catatan Kejadian',
            'admin_perm_expired'   => 'Cek EXPIRED & LOS',
            'admin_perm_aksi'      => 'MANUAL AKTIF & RESET KONEKSI (aksi tulis ke router!)',
            'admin_perm_provisioning' => 'Provisioning: PENDING, foto evidence, edit, APPROVE / REJECT (aksi tulis: pelanggan + invoice + router!)',
        ];
    }
}

if (!function_exists('telegramAdminTemplateList')) {
    /**
     * Template balasan yg bisa di-custom. key kolom => [judul, default, variabel[]].
     * {VAR} akan diganti nilainya; {VAR} yg tak dikenal jadi kosong.
     */
    function telegramAdminTemplateList(): array
    {
        return [
            'admin_tpl_pelanggan' => [
                'judul'   => 'Balasan per pelanggan (/pelanggan, /status, /odp detail)',
                'default' =>
                    "{NAMA} ({IDPEL})\n" .
                    "STATUS  : {STATUS}\n" .
                    "Koneksi : {ONLINE}\n" .
                    "Layanan : {LAYANAN}\n" .
                    "Bayar   : {TIPE_BAYAR} / {TIPE_TEMPO}\n" .
                    "Jatuh tempo   : {JATUH_TEMPO}\n" .
                    "Bayar terakhir: {BAYAR_TERAKHIR}\n" .
                    "Paket   : {PAKET}  |  Rp {HARGA}\n" .
                    "HP/WA   : {WHATSAPP}\n" .
                    "Area    : {AREA}\n" .
                    "ODP     : {ODP}\n" .
                    "Alamat  : {ALAMAT}\n" .
                    "Terakhir down : {LAST_DOWN}\n" .
                    "Pemakaian     : {PEMAKAIAN}\n" .
                    "Brand   : {PEMILIK}",
                'vars' => ['{IDPEL}', '{NAMA}', '{STATUS}', '{BAYAR_TERAKHIR}', '{ONLINE}', '{LAYANAN}',
                    '{TIPE_BAYAR}', '{TIPE_TEMPO}', '{PAKET}', '{HARGA}', '{WHATSAPP}', '{EMAIL}', '{AREA}',
                    '{ODP}', '{PPPOE}', '{ALAMAT}', '{JATUH_TEMPO}', '{TGL_PASANG}', '{LAST_DOWN}', '{UPTIME}',
                    '{PEMAKAIAN}', '{PROFIL}', '{PEMILIK}', '{TELEGRAM_CHAT_ID}'],
            ],
            'admin_tpl_tagihan' => [
                'judul'   => 'Balasan per baris tagihan (/tagihan)',
                'default' => "• {PERIODE} — {STATUS} — Rp {HARGA} ({TANGGALBAYAR}) {METODE}",
                'vars' => ['{IDPEL}', '{NAMA}', '{PERIODE}', '{STATUS}', '{HARGA}', '{TANGGALBAYAR}', '{METODE}'],
            ],
        ];
    }
}

if (!function_exists('tgAdminSan')) {
    /** Balasan dikirim teks polos -> tidak perlu buang karakter apa pun. */
    function tgAdminSan($v): string
    {
        return trim((string)$v);
    }
}

if (!function_exists('telegramAdminRenderTpl')) {
    function telegramAdminRenderTpl(string $tpl, array $vars): string
    {
        $out = strtr($tpl, $vars);
        // buang {VAR} sisa yg tak terisi
        $out = preg_replace('/\{[A-Z_]+\}/', '', $out);
        return $out;
    }
}

if (!function_exists('telegramAdminChatAllowed')) {
    function telegramAdminChatAllowed(array $botRow, string $chatId): bool
    {
        if ((int)($botRow['admin_enabled'] ?? 0) !== 1) {
            return false;
        }
        if ((int)($botRow['admin_allow_all'] ?? 0) === 1) {
            return true;
        }
        $raw = (string)($botRow['admin_chat_ids'] ?? '');
        if (trim($raw) === '') {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $id) {
            if (trim($id) === $chatId) {
                return true;
            }
        }
        return false;
    }
}

/* ----- Sesi login password bot admin (disimpan di file JSON) ----- */
if (!function_exists('telegramAdminAuthTtl')) {
    function telegramAdminAuthTtl(): int { return 7200; } // 2 jam
}
if (!function_exists('telegramAdminAuthPath')) {
    function telegramAdminAuthPath(): string { return __DIR__ . '/data/tg_admin_auth.json'; }
}
if (!function_exists('telegramAdminAuthRead')) {
    function telegramAdminAuthRead(): array
    {
        $f = telegramAdminAuthPath();
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }
}
if (!function_exists('telegramAdminAuthWrite')) {
    function telegramAdminAuthWrite(array $data): void
    {
        $f = telegramAdminAuthPath();
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
        // buang yg kedaluwarsa sekalian
        $now = time();
        foreach ($data as $k => $exp) {
            if ((int)$exp < $now) unset($data[$k]);
        }
        @file_put_contents($f, json_encode($data), LOCK_EX);
    }
}
if (!function_exists('telegramAdminIsAuthed')) {
    function telegramAdminIsAuthed($botId, string $chatId): bool
    {
        $d = telegramAdminAuthRead();
        $k = (int)$botId . ':' . $chatId;
        return isset($d[$k]) && (int)$d[$k] > time();
    }
}
if (!function_exists('telegramAdminSetAuth')) {
    function telegramAdminSetAuth($botId, string $chatId): void
    {
        $d = telegramAdminAuthRead();
        $d[(int)$botId . ':' . $chatId] = time() + telegramAdminAuthTtl();
        telegramAdminAuthWrite($d);
    }
}
if (!function_exists('telegramAdminClearAuth')) {
    function telegramAdminClearAuth($botId, string $chatId): void
    {
        $d = telegramAdminAuthRead();
        unset($d[(int)$botId . ':' . $chatId]);
        telegramAdminAuthWrite($d);
    }
}

/* ---- Wizard / sub-menu state: aksi tertunda per chat (TTL 5 menit). Dipakai
 *      supaya SEMUA filter dijalankan lewat langkah lanjutan / tombol, bukan
 *      parameter yang diketik di belakang perintah menu utama. ---- */
if (!function_exists('telegramAdminPendingPath')) {
    function telegramAdminPendingPath(): string { return __DIR__ . '/data/tg_admin_pending.json'; }
}
if (!function_exists('telegramAdminPendingRead')) {
    function telegramAdminPendingRead(): array
    {
        $f = telegramAdminPendingPath();
        if (!is_file($f)) return [];
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) ? $j : [];
    }
}
if (!function_exists('telegramAdminPendingSet')) {
    function telegramAdminPendingSet($botId, string $chatId, string $action): void
    {
        $d = telegramAdminPendingRead();
        $now = time();
        foreach ($d as $k => $v) { if ((int)($v['exp'] ?? 0) < $now) unset($d[$k]); }
        $d[(int)$botId . ':' . $chatId] = ['action' => $action, 'exp' => $now + 300];
        $f = telegramAdminPendingPath();
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
        @file_put_contents($f, json_encode($d), LOCK_EX);
    }
}
if (!function_exists('telegramAdminPendingGet')) {
    function telegramAdminPendingGet($botId, string $chatId): string
    {
        $d = telegramAdminPendingRead();
        $k = (int)$botId . ':' . $chatId;
        if (isset($d[$k]) && (int)($d[$k]['exp'] ?? 0) > time()) return (string)$d[$k]['action'];
        return '';
    }
}
if (!function_exists('telegramAdminPendingClear')) {
    function telegramAdminPendingClear($botId, string $chatId): void
    {
        $d = telegramAdminPendingRead();
        unset($d[(int)$botId . ':' . $chatId]);
        @file_put_contents(telegramAdminPendingPath(), json_encode($d), LOCK_EX);
    }
}
if (!function_exists('telegramAdminKb')) {
    /** $rows = [ [ ['Label','callback_data'], ... ], ... ] -> struktur inline_keyboard Telegram. */
    function telegramAdminKb(array $rows): array
    {
        $kb = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $btn) {
                $line[] = ['text' => (string)$btn[0], 'callback_data' => mb_substr((string)$btn[1], 0, 60)];
            }
            if ($line) $kb[] = $line;
        }
        return ['inline_keyboard' => $kb];
    }
}

if (!function_exists('telegramAdminPemilikScope')) {
    /** return [list<string>, sqlInList, ownerId]. */
    function telegramAdminPemilikScope($conn, string $ownerUsername): array
    {
        $ownerUsername = trim($ownerUsername);
        $list = $ownerUsername !== '' ? [$ownerUsername] : [];
        $ownerId = 0;
        $uEsc = mysqli_real_escape_string($conn, $ownerUsername);
        $qU = @mysqli_query($conn, "SELECT id FROM `user` WHERE USERNAME = '$uEsc' LIMIT 1");
        if ($qU && ($r = mysqli_fetch_assoc($qU))) {
            $ownerId = (int)$r['id'];
        }
        if ($ownerId > 0) {
            $qS = @mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM `server` WHERE user_id = $ownerId");
            if ($qS) {
                while ($r = mysqli_fetch_assoc($qS)) {
                    $p = trim((string)$r['PEMILIK']);
                    if ($p !== '') {
                        $list[] = $p;
                    }
                }
            }
        }
        $list = array_values(array_unique($list));
        if (empty($list)) {
            $list = [$ownerUsername];
        }
        $escaped = array_map(function ($p) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $p) . "'";
        }, $list);
        return [$list, implode(',', $escaped), $ownerId];
    }
}

if (!function_exists('telegramAdminPppoeCache')) {
    /** Baca seluruh cache status PPPoE (serverlog/pppoe_status_cache.json). Keyed lowercase IDPEL. */
    function telegramAdminPppoeCache(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = ['generated_at' => 0, 'data' => []];
        $f = __DIR__ . '/../serverlog/pppoe_status_cache.json';
        if (is_file($f)) {
            $raw = @file_get_contents($f);
            $j = $raw ? json_decode($raw, true) : null;
            if (is_array($j) && isset($j['data']) && is_array($j['data'])) {
                $cache = ['generated_at' => (int)($j['generated_at'] ?? 0), 'data' => $j['data']];
            }
        }
        return $cache;
    }
}

if (!function_exists('telegramAdminOnlineSet')) {
    /**
     * Set username/IDPEL yang SEDANG online -- SUMBER SAMA dengan halaman ODP
     * (getdata/getPelangganByODP.php): file serverlog/<nama>_online_client.txt
     * berisi {"onlineUsers":[...]}. Dicoba utk tiap kandidat nama:
     * daftar PEMILIK yg di-scope + USERNAME owner. Return lookup lowercase => true.
     * Fallback tabel `active_connections` dicek per-pelanggan (lihat telegramAdminIsOnline).
     */
    function telegramAdminOnlineSet(array $pemilikList, string $ownerUsername): array
    {
        static $memo = [];
        $key = md5(implode('|', $pemilikList) . '#' . $ownerUsername);
        if (isset($memo[$key])) {
            return $memo[$key];
        }
        $candidates = $pemilikList;
        if ($ownerUsername !== '') {
            $candidates[] = $ownerUsername;
        }
        $candidates = array_values(array_unique(array_filter(array_map('strval', $candidates))));

        $set = [];
        foreach ($candidates as $name) {
            $f = __DIR__ . '/../serverlog/' . $name . '_online_client.txt';
            if (!is_file($f)) {
                continue;
            }
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j) && isset($j['onlineUsers']) && is_array($j['onlineUsers'])) {
                foreach ($j['onlineUsers'] as $u) {
                    $k = strtolower(trim((string)$u));
                    if ($k !== '') {
                        $set[$k] = true;
                    }
                }
            }
        }
        $memo[$key] = $set;
        return $set;
    }
}

if (!function_exists('telegramAdminIsOnline')) {
    /**
     * Verdikat Online/Offline utk 1 pelanggan -- pola sama halaman ODP:
     * 1) file *_online_client.txt (by IDPEL atau USERNAME/PPPoE)
     * 2) fallback tabel active_connections
     * Return 'Online' | 'Offline'.
     */
    function telegramAdminIsOnline($conn, array $onlineSet, string $idpel, string $username = ''): string
    {
        $i = strtolower(trim($idpel));
        $u = strtolower(trim($username));
        if (($i !== '' && isset($onlineSet[$i])) || ($u !== '' && isset($onlineSet[$u]))) {
            return 'Online';
        }
        static $hasAC = null;
        if ($hasAC === null) {
            $c = @mysqli_query($conn, "SHOW TABLES LIKE 'active_connections'");
            $hasAC = ($c && mysqli_num_rows($c) > 0);
        }
        if ($hasAC) {
            $conds = [];
            foreach ([$idpel, $username] as $v) {
                $v = trim($v);
                if ($v === '') continue;
                $e = mysqli_real_escape_string($conn, $v);
                $conds[] = "username='$e'";
                $conds[] = "name='$e'";
                $conds[] = "idpel='$e'";
            }
            if ($conds) {
                $r = @mysqli_query($conn, "SELECT 1 FROM active_connections WHERE " . implode(' OR ', $conds) . " LIMIT 1");
                if ($r && mysqli_num_rows($r) > 0) {
                    return 'Online';
                }
            }
        }
        return 'Offline';
    }
}

if (!function_exists('telegramAdminOnline')) {
    /** Data KAYA (last down/uptime/pemakaian/profil) dari pppoe_status_cache.json. */
    function telegramAdminOnline(string $idpel): array
    {
        $c = telegramAdminPppoeCache();
        $e = $c['data'][strtolower(trim($idpel))] ?? null;
        if (!is_array($e)) {
            return ['status' => '-', 'last_down' => '-', 'uptime' => '-', 'pemakaian' => '-', 'profil' => '-'];
        }
        return [
            'status'    => (string)($e['status'] ?? '-'),
            'last_down'  => (string)($e['last_link_down'] ?? '-'),
            'uptime'    => (string)($e['uptime'] ?? '-'),
            'pemakaian' => (string)($e['pemakaian'] ?? '-'),
            'profil'    => (string)($e['cekexpired'] ?? '-'),
        ];
    }
}

if (!function_exists('telegramAdminPick')) {
    function telegramAdminPick(array $row, array $keys, string $default = '-'): string
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') {
                return trim((string)$row[$k]);
            }
        }
        return $default;
    }
}

if (!function_exists('telegramAdminHargaOf')) {
    /** HARGA kolom; kalau '-' / kosong, coba tarik angka dari string PAKET (mis "FIBER 159.000"). */
    function telegramAdminHargaOf(array $r): string
    {
        $h = trim((string)($r['HARGA'] ?? $r['harga'] ?? ''));
        $h = preg_replace('/[^0-9]/', '', $h);
        if ($h !== '' && (int)$h > 0) {
            return number_format((int)$h, 0, ',', '.');
        }
        $paket = (string)($r['PAKET'] ?? $r['paket'] ?? '');
        if (preg_match('/([0-9][0-9.\s]{2,})$/', trim($paket), $m)) {
            $n = (int)preg_replace('/[^0-9]/', '', $m[1]);
            if ($n > 0) return number_format($n, 0, ',', '.');
        }
        return '-';
    }
}

if (!function_exists('telegramAdminTempoText')) {
    /** Teks jatuh tempo: TANGGAL_MONTHVERSARY / TEMPO / turunan dari tgl bayar terakhir. */
    function telegramAdminTempoText(array $r, $conn = null, string $pemilikIn = ''): string
    {
        $tipe = strtolower((string)($r['TIPE_TEMPO'] ?? ''));
        $tempo = preg_replace('/[^0-9]/', '', (string)($r['TEMPO'] ?? ''));
        $mv = trim((string)($r['TANGGAL_MONTHVERSARY'] ?? ''));

        if ($mv !== '' && $mv !== '0000-00-00' && strtotime($mv) !== false) {
            return 'tiap tgl ' . date('j', strtotime($mv)) . ' (monthversary)';
        }
        if ($tempo !== '' && (int)$tempo >= 1 && (int)$tempo <= 31) {
            $lbl = $tipe === 'mengikuti_tanggal_bayar' ? ' (rolling, ikut tgl bayar)' : '';
            return 'tiap tgl ' . (int)$tempo . $lbl;
        }
        // Rolling/monthversary tanpa TEMPO -> turunkan dari tgl bayar terakhir
        if ($conn !== null && $pemilikIn !== '') {
            $e = mysqli_real_escape_string($conn, (string)($r['IDPEL'] ?? ''));
            $q = @mysqli_query($conn, "SELECT TANGGALBAYAR FROM `transaksi`
                    WHERE IDPEL='$e' AND PEMILIK IN ($pemilikIn)
                      AND TRIM(UPPER(COALESCE(STATUS,''))) IN ('BERHASIL','LUNAS','PAID','SUKSES','SETTLEMENT')
                    ORDER BY id DESC LIMIT 1");
            if ($q && ($x = mysqli_fetch_assoc($q))) {
                $tb = (string)$x['TANGGALBAYAR'];
                if (preg_match('/\b([12]?\d|3[01])\b/', $tb, $mm)) {
                    return 'tgl ' . (int)$mm[1] . ' tiap bln (dari bayar terakhir)';
                }
            }
        }
        return '-';
    }
}

if (!function_exists('telegramAdminBuildCtx')) {
    /** ctx utk tagihanHitung* -- sama pola manual_generate_invoice.php. Memo per IDPEL. */
    function telegramAdminBuildCtx($conn, array $pel, string $ownerUsername): ?array
    {
        static $memo = [];
        $idpel = (string)($pel['IDPEL'] ?? '');
        $key = $ownerUsername . '|' . $idpel;
        if (array_key_exists($key, $memo)) return $memo[$key];

        $lib = __DIR__ . '/notifphp/tagihan_status_lib.php';
        if (!is_file($lib)) return $memo[$key] = null;
        require_once $lib;
        if (!function_exists('tagihanHitungJatuhTempoBerikutnya')) return $memo[$key] = null;

        $jth = 25;
        $rf = __DIR__ . '/data/reminder-' . $ownerUsername . '.json';
        if (is_file($rf)) {
            $c = json_decode((string)@file_get_contents($rf), true);
            if (is_array($c) && isset($c[0]['jatuh_tempo'])) $jth = max(1, min(31, (int)$c[0]['jatuh_tempo']));
        }
        $mvFollow = false;
        $mf = __DIR__ . '/data/monthversary_setting-' . $ownerUsername . '.json';
        if (is_file($mf)) {
            $c = json_decode((string)@file_get_contents($mf), true);
            $mvFollow = is_array($c) && !empty($c['follow_last_payment']);
        }
        $ptMode = function_exists('tagihanLoadPeriodeTercatatMode') ? tagihanLoadPeriodeTercatatMode($rf) : 'berjalan';
        $lastPay = function_exists('tagihanGetLastPaymentsBulk') ? tagihanGetLastPaymentsBulk($conn, [$idpel]) : [];
        $lastUse = function_exists('tagihanGetLastPaidUsageMapBulk') ? tagihanGetLastPaidUsageMapBulk($conn, [$idpel]) : [];

        return $memo[$key] = [
            'hari_ini' => date('Y-m-d'),
            'jatuh_tempo_hari' => $jth,
            'lastPaymentMap' => $lastPay,
            'lastPaidUsageMap' => $lastUse,
            'periode_tercatat_mode' => $ptMode,
            'prabayar_grace_period' => 0,
            'monthversary_follow_last_payment' => $mvFollow,
        ];
    }
}

if (!function_exists('telegramAdminJatuhTempoCanon')) {
    /** Jatuh tempo BERIKUTNYA -- sama dgn halaman Customer Overview. Return "d M Y (pola)" / ''. */
    function telegramAdminJatuhTempoCanon($conn, array $pel, string $ownerUsername): string
    {
        if (telegramAdminIsFasumLifetime($conn, $pel)) {
            return 'Lifetime';
        }
        $ctx = telegramAdminBuildCtx($conn, $pel, $ownerUsername);
        if ($ctx === null) return '';
        try {
            $d = tagihanHitungJatuhTempoBerikutnya($conn, $pel, $ctx);
        } catch (\Throwable $e) {
            return '';
        }
        if ($d === '' || strtotime($d) === false) return '';
        $tipe = strtolower(trim((string)($pel['TIPE_TEMPO'] ?? '')));
        $pola = $tipe === 'mengikuti_tanggal_bayar' ? 'rolling' : ($tipe === 'monthversary' ? 'monthversary' : 'fixed');
        return date('d M Y', strtotime($d)) . ' (' . $pola . ')';
    }
}

if (!function_exists('telegramAdminIsFasumLifetime')) {
    /**
     * Paket FASUM/gratis (harga 0/kosong, bukan promo) -> dianggap Lifetime dan
     * tidak pernah ditagih -- SAMA PERSIS pengecekan tagihanIsFasumNonPromo() yg
     * dipakai Customer Overview (tables.php::tablesComputeJatuhTempoBerikutnya).
     * Tanpa ini, bot menghitung jatuh tempo seolah paket berbayar biasa dan bisa
     * salah menyimpulkan pelanggan paket gratis sebagai EXPIRED/nunggak.
     */
    function telegramAdminIsFasumLifetime($conn, array $pel): bool
    {
        static $maps = null;
        if (!function_exists('tagihanLoadPaketMaps') || !function_exists('tagihanIsFasumNonPromo')) {
            $lib = __DIR__ . '/notifphp/tagihan_status_lib.php';
            if (is_file($lib)) require_once $lib;
        }
        if (!function_exists('tagihanLoadPaketMaps') || !function_exists('tagihanIsFasumNonPromo')) return false;
        if ($maps === null) {
            $maps = tagihanLoadPaketMaps($conn);
        }
        [, $fasumPaketList, $promoPaketIds] = $maps;
        $paketKey = strtolower(trim((string)($pel['PAKET'] ?? $pel['paket'] ?? '')));
        return tagihanIsFasumNonPromo($paketKey, $fasumPaketList, $promoPaketIds);
    }
}

if (!function_exists('telegramAdminStatusCanon')) {
    /**
     * Status layanan AKTUAL -- pola sama Customer Overview: dari tagihanHitungStatus()
     * (sudah_bayar) + jatuh tempo + hari ini. Return "AKTIF" / "EXPIRED (JT ...)" /
     * "BELUM BAYAR (JT ...)" atau '' kalau lib tak tersedia.
     */
    function telegramAdminStatusCanon($conn, array $pel, string $ownerUsername): string
    {
        if (telegramAdminIsFasumLifetime($conn, $pel)) {
            return 'AKTIF (Lifetime / paket gratis)';
        }
        $ctx = telegramAdminBuildCtx($conn, $pel, $ownerUsername);
        if ($ctx === null || !function_exists('tagihanHitungStatus')) return '';
        try {
            $s = tagihanHitungStatus($conn, $pel, $ctx);
        } catch (\Throwable $e) {
            return '';
        }
        if (!empty($s['sudah_bayar'])) {
            return 'AKTIF (lunas periode berjalan)';
        }
        $due = trim((string)($s['jatuh_tempo'] ?? ''));
        if ($due === '') {
            $due2 = '';
            try { $due2 = tagihanHitungJatuhTempoBerikutnya($conn, $pel, $ctx); } catch (\Throwable $e) {}
            $due = $due2;
        }
        $today = date('Y-m-d');
        if ($due !== '' && strtotime($due) !== false) {
            $dtxt = date('d M Y', strtotime($due));
            return ($today > $due)
                ? "EXPIRED / nunggak — JT $dtxt terlewat"
                : "BELUM BAYAR — JT $dtxt";
        }
        return 'BELUM BAYAR';
    }
}

if (!function_exists('telegramAdminBillingStatus')) {
    /** Status tagihan dari baris transaksi TERBARU pelanggan ini. */
    function telegramAdminBillingStatus($conn, string $idpel, string $pemilikIn): string
    {
        if ($idpel === '') return '-';
        $e = mysqli_real_escape_string($conn, $idpel);
        $q = @mysqli_query($conn, "SELECT STATUS, PENGUNAAN FROM `transaksi`
                WHERE IDPEL='$e' AND PEMILIK IN ($pemilikIn) ORDER BY id DESC LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            return 'belum ada tagihan';
        }
        $r = mysqli_fetch_assoc($q);
        $st = strtoupper(trim((string)$r['STATUS']));
        $per = trim((string)$r['PENGUNAAN']);
        $lunas = in_array($st, ['BERHASIL', 'LUNAS', 'PAID', 'SUKSES', 'SUCCESS', 'SETTLEMENT'], true);
        return ($lunas ? 'LUNAS' : 'BELUM BAYAR') . ($per !== '' ? ' (' . $per . ')' : '');
    }
}

if (!function_exists('telegramAdminPelangganVars')) {
    /** Bangun map {VAR}=>nilai utk 1 baris pelanggan (skema pelanggan quenbyte: tanpa kolom STATUS). */
    function telegramAdminPelangganVars(array $r, array $onlineSet = [], $conn = null, string $pemilikIn = '', string $ownerUsername = ''): array
    {
        $jatuhTempo = '';
        if ($conn !== null && $ownerUsername !== '') {
            $jatuhTempo = telegramAdminJatuhTempoCanon($conn, $r, $ownerUsername);
        }
        if ($jatuhTempo === '') {
            $jatuhTempo = telegramAdminTempoText($r, $conn, $pemilikIn);
        }
        $idpel = telegramAdminPick($r, ['IDPEL', 'idpel']);
        $on = telegramAdminOnline($idpel);
        // IDPEL = username PPPoE di skema ini
        $onlineTxt = $conn !== null
            ? telegramAdminIsOnline($conn, $onlineSet, $idpel, '')
            : ($on['status'] === '-' ? '-' : $on['status']);
        $status = '';
        if ($conn !== null && $ownerUsername !== '') {
            $status = telegramAdminStatusCanon($conn, $r, $ownerUsername);
        }
        if ($status === '' && $conn !== null && $pemilikIn !== '') {
            $status = telegramAdminBillingStatus($conn, $idpel, $pemilikIn);
        }
        if ($status === '') $status = '-';
        $bayarTerakhir = '-';
        if ($conn !== null && $pemilikIn !== '') {
            $bayarTerakhir = telegramAdminBillingStatus($conn, $idpel, $pemilikIn);
        }
        return [
            '{IDPEL}'      => $idpel,
            '{NAMA}'       => telegramAdminPick($r, ['NAMA', 'nama']),
            '{STATUS}'     => $status,
            '{BAYAR_TERAKHIR}' => $bayarTerakhir,
            '{ONLINE}'     => $onlineTxt,
            '{LAYANAN}'    => $on['profil'] !== '-' ? $on['profil'] : telegramAdminPick($r, ['PAKET', 'paket']),
            '{TIPE_BAYAR}' => telegramAdminPick($r, ['TIPE_BAYAR'], '-'),
            '{TIPE_TEMPO}' => telegramAdminPick($r, ['TIPE_TEMPO'], '-'),
            '{PAKET}'      => telegramAdminPick($r, ['PAKET', 'paket']),
            '{HARGA}'      => telegramAdminHargaOf($r),
            '{WHATSAPP}'   => telegramAdminPick($r, ['NOWA', 'WHATSAPP', 'NO_HP', 'HP', 'NOHP']),
            '{EMAIL}'      => telegramAdminPick($r, ['EMAIL'], '-'),
            '{AREA}'       => telegramAdminPick($r, ['AREA', 'area']),
            '{ODP}'        => telegramAdminPick($r, ['ODP', 'odp']),
            '{PPPOE}'      => $idpel,
            '{ALAMAT}'     => telegramAdminPick($r, ['ALAMAT', 'alamat']),
            '{JATUH_TEMPO}' => $jatuhTempo,
            '{TGL_PASANG}' => telegramAdminPick($r, ['TANGGALPASANG'], '-'),
            '{LAST_DOWN}'  => $on['last_down'],
            '{UPTIME}'     => $on['uptime'],
            '{PEMAKAIAN}'  => $on['pemakaian'],
            '{PROFIL}'     => $on['profil'],
            '{PEMILIK}'    => telegramAdminPick($r, ['PEMILIK', 'pemilik', 'BRAND']),
            '{TELEGRAM_CHAT_ID}' => telegramAdminPick($r, ['TELEGRAM_CHAT_ID'], '-'),
        ];
    }
}

if (!function_exists('telegramAdminMenuText')) {
    function telegramAdminMenuText(array $botRow): string
    {
        $custom = trim((string)($botRow['admin_menu_custom'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $nama = tgAdminSan($botRow['namebot'] ?? 'Billing');
        $lines = [
            '=============================',
            '   BOT ADMIN BILLING - ' . $nama,
            '=============================',
            'Ketik salah satu perintah di bawah.',
            '',
        ];

        // Semua perintah TANPA parameter. Filter/isian dijalankan lewat langkah
        // lanjutan (bot menuntun) atau tombol sub-menu.
        $sections = [
            'PELANGGAN' => [
                'admin_perm_pelanggan' => [
                    '/pelanggan  - cari pelanggan (bot minta IDPEL / nama / no.WA)',
                    '/status     - status ringkas 1 pelanggan (bot minta IDPEL)',
                ],
                'admin_perm_aksi' => [
                    '/aktif      - MANUAL AKTIF pelanggan (dituntun: IDPEL -> metode -> kirim FOTO bukti utk cash/transfer/gagal PG)',
                    '/reset      - RESET KONEKSI PPPoE pelanggan (dituntun: IDPEL -> konfirmasi)',
                    '/batal      - batalkan langkah yang sedang berjalan',
                ],
            ],
            'JARINGAN' => [
                'admin_perm_server' => [
                    '/server     - daftar server + poller + jumlah online',
                ],
                'admin_perm_odp' => [
                    '/area       - daftar AREA -> balas nomor -> ODP -> balas nomor -> pelanggan',
                    '/odp        - sama seperti /area (mulai dari daftar AREA)',
                ],
                'admin_perm_expired' => [
                    '/expired    - pelanggan EXPIRED (online vs LOS)',
                    '/los        - pelanggan LOS (signal hilang) + ODP full-LOS',
                ],
            ],
            'KEUANGAN' => [
                'admin_perm_tagihan' => [
                    '/tagihan    - 6 riwayat bayar terakhir (bot minta IDPEL)',
                ],
                'admin_perm_menunggak' => [
                    '/menunggak  - rekap tunggakan + 15 terbaru',
                ],
                'admin_perm_transaksi' => [
                    '/transaksi  - omzet lunas (pilih rentang lewat tombol)',
                ],
                'admin_perm_generate' => [
                    '/generateinvoice - BUAT invoice PENAGIHAN massal (pilih periode lewat tombol)',
                ],
            ],
            'LIVE CHAT' => [
                'admin_perm_livechat' => [
                    '/livechat   - pesan belum dibaca (pilih rentang lewat tombol) + link balas',
                    '/kejadian   - catatan kejadian (live chat & wabot)',
                ],
            ],
            'LAIN-LAIN' => [
                'admin_perm_tiket' => [
                    '/tiket      - tiket gangguan terbuka (pilih tipe -> daftar -> detail)',
                ],
                'admin_perm_buat_tiket' => [
                    '/buattiket  - BUAT tiket baru (dituntun: cari pelanggan -> pilih tipe -> pilih kendala)',
                ],
                'admin_perm_provisioning' => [
                    '/provisioning - data provisioning PENDING (lihat foto evidence, EDIT, APPROVE / REJECT lewat tombol)',
                ],
                'admin_perm_statistik' => [
                    '/statistik  - laporan lengkap bulan berjalan (sama seperti widget dashboard)',
                ],
            ],
        ];

        foreach ($sections as $title => $perms) {
            $block = [];
            foreach ($perms as $perm => $cmds) {
                if ((int)($botRow[$perm] ?? 0) === 1) {
                    foreach ($cmds as $c) {
                        $block[] = $c;
                    }
                }
            }
            if ($block) {
                $lines[] = '-- ' . $title . ' --';
                foreach ($block as $b) {
                    $lines[] = $b;
                }
                $lines[] = '';
            }
        }

        $lines[] = '-- UMUM --';
        $lines[] = '/id                                 - tampilkan Chat ID Anda';
        $lines[] = '/menu                               - tampilkan menu ini';
        if ((int)($botRow['admin_use_password'] ?? 0) === 1) {
            $lines[] = '/login <password>                   - masuk (wajib dulu, berlaku 2 jam)';
            $lines[] = '/logout                             - keluar';
        }
        return rtrim(implode("\n", $lines));
    }
}

if (!function_exists('telegramAdminHandle')) {
    function telegramAdminHandle($conn, array $botRow, string $chatId, string $text, bool $fromCallback = false, string $photoFileId = ''): bool
    {
        $token = (string)($botRow['bottoken'] ?? '');
        $text = trim($text);
        if ($token === '') return false;
        if ($text === '' && $photoFileId === '') return false;

        if ($text !== '' && preg_match('~^/id(@\S+)?$~i', $text)) {
            sendTelegramMessage($token, $chatId, "Chat ID Anda: " . $chatId . "\n\nMinta owner menambahkan ID ini di menu Telegram Bot > Pengaturan Bot Admin Billing.", '');
            return true;
        }

        if (!telegramAdminChatAllowed($botRow, $chatId)) {
            return false;
        }

        $botIdN = (int)($botRow['id'] ?? 0);

        // ---- FOTO masuk: kalau ada langkah "aktif-bukti" tertunda -> jalankan
        //      Manual Aktif dgn lampiran foto (persis modal Manual Aktif). ----
        if ($photoFileId !== '') {
            $pa = telegramAdminPendingGet($botIdN, $chatId);
            if (strpos($pa, 'aktif-bukti ') === 0) {
                telegramAdminPendingClear($botIdN, $chatId);
                $pp = preg_split('/\s+/', trim(substr($pa, strlen('aktif-bukti '))), -1, PREG_SPLIT_NO_EMPTY);
                $bIdpel = $pp[0] ?? ''; $bMetode = strtolower($pp[1] ?? 'cash');
                if ($bIdpel === '') {
                    sendTelegramMessage($token, $chatId, "Sesi Manual Aktif tidak lengkap. Ulangi /aktif.", '');
                    return true;
                }
                [$pemL, $pemIn, ] = telegramAdminPemilikScope($conn, (string)($botRow['pemilik'] ?? ''));
                sendTelegramMessage($token, $chatId, "Foto diterima, memproses Manual Aktif untuk $bIdpel ...", '');
                $tmp = telegramAdminTgFileDownload($token, $photoFileId);
                if ($tmp === null) {
                    sendTelegramMessage($token, $chatId, "Gagal mengunduh foto dari Telegram. Kirim ulang fotonya.", '');
                    // pending sudah kebuang -> set lagi supaya user tinggal kirim ulang foto
                    telegramAdminPendingSet($botIdN, $chatId, "aktif-bukti $bIdpel $bMetode");
                    return true;
                }
                sendTelegramMessage($token, $chatId, telegramAdminAktif($conn, $botRow, $pemIn, $bIdpel, $bMetode, false, $tmp), '');
                return true;
            }
            sendTelegramMessage($token, $chatId, "Foto diterima, tapi tidak ada langkah yang menunggu foto. Mulai dengan /aktif dulu.", '');
            return true;
        }

        if ($text === '') return false;

        // Wizard / sub-menu: semua filter dijalankan lewat langkah lanjutan / tombol,
        // BUKAN parameter di belakang perintah. Kalau ada aksi tertunda utk chat ini
        // & pesan masuk bukan perintah, jadikan isi pesan sbg argumen aksi tsb.
        if ($text[0] !== '/' && !$fromCallback) {
            $pendAct = telegramAdminPendingGet($botIdN, $chatId);
            if ($pendAct !== '') {
                if (strpos($pendAct, 'aktif-bukti') === 0) {
                    sendTelegramMessage($token, $chatId, "Menunggu FOTO bukti pembayaran. Kirim sebagai foto/gambar, atau /batal.", '');
                    return true;
                }
                telegramAdminPendingClear($botIdN, $chatId);
                $text = '/' . $pendAct . ' ' . trim($text);
            }
        }

        if (!preg_match('~^/([a-z_]+)(@\S+)?(?:\s+(.*))?$~is', $text, $m)) {
            return false;
        }
        $cmd = strtolower($m[1]);
        $arg = trim((string)($m[3] ?? ''));

        $perm = function ($k) use ($botRow) {
            return (int)($botRow[$k] ?? 0) === 1;
        };
        $send = function ($msg) use ($token, $chatId) {
            sendTelegramMessage($token, $chatId, $msg, '');
            return true;
        };
        // kirim pesan + tombol sub-menu (inline keyboard)
        $sendKb = function ($msg, array $kb) use ($token, $chatId) {
            sendTelegramMessage($token, $chatId, $msg, '', telegramAdminKb($kb));
            return true;
        };
        // minta 1 input lanjutan (wizard): set aksi tertunda, kirim prompt
        $ask = function ($action, $prompt) use ($token, $chatId, $botRow) {
            telegramAdminPendingSet((int)($botRow['id'] ?? 0), $chatId, $action);
            sendTelegramMessage($token, $chatId, $prompt, '');
            return true;
        };

        // --- Gerbang password (opsional) ---
        $usePw = (int)($botRow['admin_use_password'] ?? 0) === 1;
        $botIdNum = (int)($botRow['id'] ?? 0);
        if ($usePw) {
            $pw = (string)($botRow['admin_password'] ?? '');
            if ($cmd === 'login' || $cmd === 'pass' || $cmd === 'password') {
                if ($pw !== '' && hash_equals($pw, $arg)) {
                    telegramAdminSetAuth($botIdNum, $chatId);
                    return $send("Login berhasil. Akses aktif " . (int)(telegramAdminAuthTtl() / 3600) . " jam.\nKirim /menu untuk mulai.");
                }
                return $send("Password salah.");
            }
            if ($cmd === 'logout') {
                telegramAdminClearAuth($botIdNum, $chatId);
                return $send("Anda sudah logout.");
            }
            if (!telegramAdminIsAuthed($botIdNum, $chatId)) {
                return $send("Bot ini dilindungi password.\nKirim: /login <password>");
            }
        }
        $deny = function () use ($send) {
            return $send("Perintah ini tidak diizinkan untuk bot ini. Owner bisa mengaktifkannya di Pengaturan Bot Admin Billing.");
        };

        [$pemilikList, $pemilikIn, $ownerId] = telegramAdminPemilikScope($conn, (string)($botRow['pemilik'] ?? ''));
        $onlineSet = telegramAdminOnlineSet($pemilikList, (string)($botRow['pemilik'] ?? ''));

        switch ($cmd) {
            case 'start':
            case 'menu':
            case 'help':
                return $send(telegramAdminMenuText($botRow));

            case 'batal':
            case 'cancel':
                telegramAdminPendingClear($botIdN, $chatId);
                return $send("Dibatalkan. Kirim /menu untuk daftar perintah.");

            case 'pelanggan':
            case 'cari':
            case 'cek':
                if (!$perm('admin_perm_pelanggan')) return $deny();
                if ($arg === '') return $ask('pelanggan', "CARI PELANGGAN\nBalas pesan ini dengan IDPEL / nama / no.WA yang dicari.");
                return $send(telegramAdminCariPelanggan($conn, $botRow, $pemilikIn, $arg, false, $onlineSet));

            case 'status':
                if (!$perm('admin_perm_pelanggan')) return $deny();
                if ($arg === '') return $ask('status', "STATUS 1 PELANGGAN\nBalas pesan ini dengan IDPEL pelanggan.");
                return $send(telegramAdminCariPelanggan($conn, $botRow, $pemilikIn, $arg, true, $onlineSet));

            case 'server':
                if (!$perm('admin_perm_server')) return $deny();
                return $send(telegramAdminServer($conn, $pemilikIn, $ownerId, '', $onlineSet));

            case 'area':
                if (!$perm('admin_perm_odp')) return $deny();
                telegramAdminPendingSet((int)($botRow['id'] ?? 0), $chatId, 'odp');
                return $send(telegramAdminAreaList($conn, $pemilikIn, $onlineSet) . "\n\n=> Balas dengan NOMOR area untuk lihat daftar ODP-nya.");

            case 'odp':
                if (!$perm('admin_perm_odp')) return $deny();
                $odpParts = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
                if (count($odpParts) === 0) {
                    telegramAdminPendingSet((int)($botRow['id'] ?? 0), $chatId, 'odp');
                    return $send(telegramAdminAreaList($conn, $pemilikIn, $onlineSet) . "\n\n=> Balas dengan NOMOR area.");
                }
                if (count($odpParts) === 1) {
                    telegramAdminPendingSet((int)($botRow['id'] ?? 0), $chatId, 'odp ' . $odpParts[0]);
                    return $send(telegramAdminOdpResolve($conn, $botRow, $pemilikIn, $arg, $onlineSet) . "\n\n=> Balas dengan NOMOR ODP untuk lihat pelanggannya.");
                }
                return $send(telegramAdminOdpResolve($conn, $botRow, $pemilikIn, $arg, $onlineSet));

            case 'tagihan':
                if (!$perm('admin_perm_tagihan')) return $deny();
                if ($arg === '') return $ask('tagihan', "RIWAYAT TAGIHAN\nBalas pesan ini dengan IDPEL pelanggan.");
                return $send(telegramAdminTagihan($conn, $botRow, $pemilikIn, $arg));

            case 'menunggak':
            case 'tunggakan':
                if (!$perm('admin_perm_menunggak')) return $deny();
                return $send(telegramAdminMenunggak($conn, $pemilikIn));

            case 'transaksi':
            case 'omzet':
                if (!$perm('admin_perm_transaksi')) return $deny();
                if ($arg === '') {
                    return $sendKb("OMZET LUNAS -- pilih rentang waktu:", [
                        [['1 hari', '/transaksi 1'], ['7 hari', '/transaksi 7']],
                        [['30 hari', '/transaksi 30'], ['90 hari', '/transaksi 90']],
                    ]);
                }
                $hari = (int)$arg;
                if ($hari <= 0) $hari = 7;
                if ($hari > 366) $hari = 366;
                return $send(telegramAdminTransaksi($conn, $pemilikIn, $hari));

            case 'tiket':
                if (!$perm('admin_perm_tiket')) return $deny();
                $tSrc = telegramAdminTicketSource($conn, (string)($botRow['pemilik'] ?? ''));
                $tl = telegramAdminTiketTypeList($conn, $pemilikIn, $pemilikList, $tSrc);
                return !empty($tl['kb']) ? $sendKb($tl['text'], $tl['kb']) : $send($tl['text']);

            case 'tiket_tipe':
                if (!$perm('admin_perm_tiket')) return $deny();
                if (trim($arg) === '') return $send("Format: /tiket_tipe <TIPE>");
                $tSrc2 = telegramAdminTicketSource($conn, (string)($botRow['pemilik'] ?? ''));
                $tbt = telegramAdminTiketByType($conn, $pemilikIn, $pemilikList, $tSrc2, trim($arg));
                return !empty($tbt['kb']) ? $sendKb($tbt['text'], $tbt['kb']) : $send($tbt['text']);

            case 'tiket_view':
                if (!$perm('admin_perm_tiket')) return $deny();
                $tvId = (int)trim($arg);
                if ($tvId <= 0) return $send("Format: /tiket_view <id>");
                $tSrc3 = telegramAdminTicketSource($conn, (string)($botRow['pemilik'] ?? ''));
                return $send(telegramAdminTiketDetail($conn, $pemilikIn, $pemilikList, $tSrc3, $tvId));

            // ===================== BUAT TIKET (wizard) =====================
            // Pilihan tipe & kendala SAMA PERSIS modal "Buat tiket" di tables.php,
            // simpan ke billing_tiket_manager sama seperti buat_tiket.php.
            case 'buattiket':
            case 'buat_tiket':
            case 'tiketbaru':
            case 'newtiket':
                if (!$perm('admin_perm_buat_tiket')) return $deny();
                if ($arg === '') {
                    return $ask('buattiket', "BUAT TIKET\nBalas pesan ini dengan IDPEL / nama / no.WA pelanggan yang mau dibuatkan tiket.");
                }
                {
                    $btQ = trim($arg);
                    $btQEsc = mysqli_real_escape_string($conn, $btQ);
                    $btLike = '%' . $btQEsc . '%';
                    $btDigits = preg_replace('/[^0-9]/', '', $btQ);
                    $btWaCond = '';
                    if (strlen($btDigits) >= 6) {
                        $btTail = mysqli_real_escape_string($conn, substr($btDigits, -8));
                        $btWaCond = " OR REPLACE(REPLACE(NOWA,'+',''),' ','') LIKE '%$btTail%'";
                    }
                    $btRes = @mysqli_query($conn, "SELECT IDPEL, NAMA, NOWA, AREA FROM `pelanggan`
                            WHERE PEMILIK IN ($pemilikIn)
                              AND (IDPEL = '$btQEsc' OR NAMA LIKE '$btLike' OR NOWA LIKE '$btLike'$btWaCond)
                            ORDER BY (IDPEL = '$btQEsc') DESC, NAMA ASC LIMIT 6");
                    if (!$btRes || mysqli_num_rows($btRes) === 0) {
                        return $ask('buattiket', "Pelanggan \"$btQ\" tidak ditemukan. Balas lagi dengan IDPEL / nama / no.WA yang benar, atau /batal.");
                    }
                    $btRows = [];
                    while ($rbt = mysqli_fetch_assoc($btRes)) { $btRows[] = $rbt; }
                    $btPick = null;
                    if (count($btRows) === 1) {
                        $btPick = $btRows[0];
                    } else {
                        foreach ($btRows as $rbt) {
                            if (strcasecmp(trim((string)$rbt['IDPEL']), $btQ) === 0) { $btPick = $rbt; break; }
                        }
                    }
                    if (!$btPick) {
                        $btList = ["Ada beberapa pelanggan cocok. Balas dengan IDPEL yang tepat:"];
                        foreach ($btRows as $i => $rbt) {
                            $btList[] = ($i + 1) . ". " . $rbt['NAMA'] . " (" . $rbt['IDPEL'] . ") - " . $rbt['AREA'];
                        }
                        return $ask('buattiket', implode("\n", $btList));
                    }
                    $btOpsi = telegramAdminTiketOpsi();
                    $btKb = []; $btLineKb = [];
                    foreach ($btOpsi['tipe'] as $ti => $tv) {
                        $btLineKb[] = [$tv, "/bt_tipe " . $btPick['IDPEL'] . "|" . $ti];
                        if (count($btLineKb) === 2) { $btKb[] = $btLineKb; $btLineKb = []; }
                    }
                    if ($btLineKb) $btKb[] = $btLineKb;
                    $btKb[] = [['Batal', '/menu']];
                    return $sendKb("BUAT TIKET utk " . $btPick['NAMA'] . " (" . $btPick['IDPEL'] . ")\nPilih TIPE pekerjaan:", $btKb);
                }

            case 'bt_tipe':
                if (!$perm('admin_perm_buat_tiket')) return $deny();
                {
                    $btP = explode('|', $arg);
                    $btId = trim($btP[0] ?? '');
                    $btTipeIdx = (int)($btP[1] ?? -1);
                    $btOpsi = telegramAdminTiketOpsi();
                    if ($btId === '' || !isset($btOpsi['tipe'][$btTipeIdx])) {
                        return $send("Pilihan tidak valid. Ulangi /buattiket.");
                    }
                    $btKb = [];
                    foreach ($btOpsi['kendala'] as $ki => $kv) {
                        $btKb[] = [[$kv, "/bt_kdl " . $btId . "|" . $btTipeIdx . "|" . $ki]];
                    }
                    $btKb[] = [['Batal', '/menu']];
                    return $sendKb("TIPE: " . $btOpsi['tipe'][$btTipeIdx] . "\nPilih KENDALA:", $btKb);
                }

            case 'bt_kdl':
                if (!$perm('admin_perm_buat_tiket')) return $deny();
                {
                    $btP = explode('|', $arg);
                    $btId = trim($btP[0] ?? '');
                    $btTipeIdx = (int)($btP[1] ?? -1);
                    $btKdlIdx = (int)($btP[2] ?? -1);
                    $btConfirmed = isset($btP[3]) && strtolower(trim($btP[3])) === 'ok';
                    $btOpsi = telegramAdminTiketOpsi();
                    if ($btId === '' || !isset($btOpsi['tipe'][$btTipeIdx]) || !isset($btOpsi['kendala'][$btKdlIdx])) {
                        return $send("Pilihan tidak valid. Ulangi /buattiket.");
                    }
                    $btTipe = $btOpsi['tipe'][$btTipeIdx];
                    $btKdl  = $btOpsi['kendala'][$btKdlIdx];
                    if (!$btConfirmed) {
                        return $sendKb("Konfirmasi BUAT TIKET\nIDPEL   : $btId\nTipe    : $btTipe\nKendala : $btKdl\nStatus  : BARU", [
                            [['Ya, buat tiket', "/bt_kdl " . $btId . "|" . $btTipeIdx . "|" . $btKdlIdx . "|ok"]],
                            [['Batal', '/menu']],
                        ]);
                    }
                    return $send(telegramAdminBuatTiket($conn, $botRow, $pemilikIn, (int)$ownerId, $btId, $btTipe, $btKdl));
                }

            case 'statistik':
            case 'stat':
                if (!$perm('admin_perm_statistik')) return $deny();
                return $send(telegramAdminStatistik($conn, $pemilikIn, $onlineSet, (string)($botRow['pemilik'] ?? ''), ''));

            case 'generateinvoice':
            case 'generate':
            case 'gentagihan':
            case 'buatinvoice':
                if (!$perm('admin_perm_generate')) return $deny();
                if ($arg === '') {
                    $blnSg = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    $giRows = []; $giLine = [];
                    for ($gi = 0; $gi < 6; $gi++) {
                        $gts = strtotime("first day of -$gi month");
                        $gb = (int)date('n', $gts); $gy = (int)date('Y', $gts);
                        $giLine[] = [$blnSg[$gb] . ' ' . $gy, "/generateinvoice $gb $gy"];
                        if (count($giLine) === 2) { $giRows[] = $giLine; $giLine = []; }
                    }
                    if ($giLine) $giRows[] = $giLine;
                    return $sendKb("BUAT INVOICE PENAGIHAN massal -- pilih periode:", $giRows);
                }
                if (!preg_match('/\bok\b/i', $arg) && preg_match('/(\d{1,2})\D+(\d{4})/', $arg, $giCf)) {
                    return $sendKb("Konfirmasi: buat invoice PENAGIHAN massal untuk {$giCf[1]}/{$giCf[2]}?", [
                        [['Ya, buat sekarang', "/generateinvoice {$giCf[1]} {$giCf[2]} ok"]],
                        [['Batal', '/menu']],
                    ]);
                }
                return $send(telegramAdminGenerateInvoice($botRow, $arg));

            case 'livechat':
            case 'chat':
                if (!$perm('admin_perm_livechat')) return $deny();
                if ($arg === '') {
                    return $sendKb("LIVE CHAT belum dibaca -- pilih rentang waktu:", [
                        [['1 hari', '/livechat 1'], ['3 hari', '/livechat 3']],
                        [['7 hari', '/livechat 7'], ['Semua', '/livechat all']],
                    ]);
                }
                $lcHari = 1;
                if (preg_match('/^\d+$/', trim($arg))) { $lcHari = max(1, min(90, (int)$arg)); }
                elseif (strtolower(trim($arg)) === 'all' || strtolower(trim($arg)) === 'semua') { $lcHari = 3650; }
                return $send(telegramAdminLiveChat($conn, (string)($botRow['pemilik'] ?? ''), $lcHari));

            case 'kejadian':
            case 'catatan':
                if (!$perm('admin_perm_livechat')) return $deny();
                return $send(telegramAdminKejadian($conn, (string)($botRow['pemilik'] ?? '')));

            case 'expired':
            case 'menungggak2':
                if (!$perm('admin_perm_expired')) return $deny();
                return $send(telegramAdminExpired($conn, $pemilikIn, (string)($botRow['pemilik'] ?? '')));

            case 'los':
                if (!$perm('admin_perm_expired')) return $deny();
                return $send(telegramAdminLos($conn, $pemilikIn, (string)($botRow['pemilik'] ?? '')));

            case 'aktif':
            case 'aktifkan':
            case 'manualaktif':
                if (!$perm('admin_perm_aksi')) return $deny();
                $ap = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
                if (count($ap) === 0) {
                    return $ask('aktif', "MANUAL AKTIF\nBalas pesan ini dengan IDPEL pelanggan yang mau diaktifkan.");
                }
                $apId = $ap[0];
                $apMode = strtolower($ap[1] ?? '');
                $apOk = in_array('ok', array_map('strtolower', $ap), true);
                if ($apMode === '') {
                    return $sendKb("MANUAL AKTIF: $apId\nPilih metode / mode:", [
                        [['Cash', "/aktif $apId cash"], ['Transfer', "/aktif $apId transfer"]],
                        [['Kompensasi Free', "/aktif $apId kompensasi_free"], ['Gagal PG', "/aktif $apId gagalpg"]],
                        [['Hanya aktifkan (tanpa transaksi)', "/aktif $apId only"]],
                    ]);
                }
                // Metode TANPA bukti (only-activate / kompensasi free) -> tombol konfirmasi lalu jalan.
                if ($apMode === 'only' || $apMode === 'kompensasi_free') {
                    if (!$apOk) {
                        $lbl = ($apMode === 'only') ? "HANYA AKTIFKAN (tanpa catat transaksi)" : "KOMPENSASI FREE (transaksi Rp 0, tanpa bukti)";
                        return $sendKb("Konfirmasi MANUAL AKTIF\nIDPEL: $apId\n$lbl", [
                            [['Ya, proses sekarang', "/aktif $apId $apMode ok"]],
                            [['Batal', '/menu']],
                        ]);
                    }
                    return $send(telegramAdminAktif($conn, $botRow, $pemilikIn, $apId, ($apMode === 'only' ? 'cash' : $apMode), ($apMode === 'only')));
                }
                // Metode cash / transfer / gagal PG -> WAJIB foto bukti, PERSIS modal Manual Aktif.
                if (in_array($apMode, ['cash', 'transfer', 'gagalpg'], true)) {
                    $mLbl = $apMode === 'gagalpg' ? 'gagal payment gateway' : $apMode;
                    telegramAdminPendingSet($botIdN, $chatId, "aktif-bukti $apId $apMode");
                    return $send("MANUAL AKTIF: $apId\nMetode: $mLbl  |  periode " . date('n') . "/" . date('Y') . "\n\nKirim FOTO bukti pembayaran sekarang (sebagai foto/gambar).\nFoto yang Anda kirim = konfirmasi & langsung diproses.\nKetik /batal untuk membatalkan. (berlaku 5 menit)");
                }
                return $send("Metode tidak dikenal. Ulangi /aktif.");

            case 'reset':
            case 'resetkoneksi':
                if (!$perm('admin_perm_aksi')) return $deny();
                $rp2 = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
                if (count($rp2) === 0) {
                    return $ask('reset', "RESET KONEKSI\nBalas pesan ini dengan IDPEL pelanggan.");
                }
                $rId = $rp2[0];
                if (!in_array('ok', array_map('strtolower', $rp2), true)) {
                    return $sendKb("Konfirmasi RESET KONEKSI\nIDPEL: $rId\nSesi PPPoE akan diputus (auto reconnect).", [
                        [['Ya, reset sekarang', "/reset $rId ok"]],
                        [['Batal', '/menu']],
                    ]);
                }
                return $send(telegramAdminResetKoneksi($conn, $botRow, $pemilikIn, $rId));

            // ===================== PROVISIONING =====================
            case 'provisioning':
            case 'prov':
            case 'provlist':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $pl = telegramAdminProvList($conn, $pemilikIn);
                return !empty($pl['kb']) ? $sendKb($pl['text'], $pl['kb']) : $send($pl['text']);

            case 'prov_view':
            case 'prov_detail':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $pvId = (int)trim($arg);
                if ($pvId <= 0) return $send("Format: /prov_view <id>");
                telegramAdminProvView($conn, $token, $chatId, $botIdN, $pemilikIn, $pvId);
                return true;

            case 'prov_edit':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $pe = preg_split('/\s+/', trim($arg), 3, PREG_SPLIT_NO_EMPTY);
                $peId = (int)($pe[0] ?? 0);
                $peField = strtolower(trim($pe[1] ?? ''));
                $peVal = trim($pe[2] ?? '');
                if ($peId <= 0 || !in_array($peField, provisioning_approve_editable_fields(), true)) {
                    return $send("Format: /prov_edit <id> <field>");
                }
                if ($peVal === '') {
                    if ($peField === 'tipe_bayar') {
                        return $sendKb("Pilih Tipe Bayar utk provisioning #$peId:", [
                            [['prabayar', "/prov_set $peId tipe_bayar prabayar"], ['pascabayar', "/prov_set $peId tipe_bayar pascabayar"]],
                            [['« Kembali', "/prov_view $peId"]],
                        ]);
                    }
                    if ($peField === 'tipe_tempo') {
                        return $sendKb("Pilih Tipe Tempo utk provisioning #$peId:", [
                            [['ikut tgl bayar', "/prov_set $peId tipe_tempo mengikuti_tanggal_bayar"]],
                            [['ikut tgl tempo', "/prov_set $peId tipe_tempo mengikuti_tanggal_tempo"]],
                            [['monthversary', "/prov_set $peId tipe_tempo monthversary"]],
                            [['« Kembali', "/prov_view $peId"]],
                        ]);
                    }
                    if ($peField === 'paket') {
                        $opts = telegramAdminProvPaketOptions($conn, $pemilikIn, $peId);
                        if (empty($opts)) return $send("Tidak ada paket terdaftar utk server provisioning ini.");
                        $rows = []; $line = [];
                        foreach ($opts as $op) {
                            $line[] = [mb_substr($op, 0, 40), "/prov_set $peId paket " . $op];
                            if (count($line) === 1) { $rows[] = $line; $line = []; }
                        }
                        if ($line) $rows[] = $line;
                        $rows[] = [['« Kembali', "/prov_view $peId"]];
                        return $sendKb("Pilih Paket utk provisioning #$peId:", $rows);
                    }
                    $labelMap = ['nama' => 'Nama', 'nowa' => 'No WhatsApp', 'email' => 'Email', 'alamat' => 'Alamat', 'tikor' => 'Titik Koordinat (lat,lng)', 'tanggal_pasang' => 'Tanggal Pasang (format YYYY-MM-DD)'];
                    return $ask("prov_edit $peId $peField", "EDIT " . ($labelMap[$peField] ?? $peField) . " -- provisioning #$peId\nBalas pesan ini dengan nilai baru. Ketik /batal untuk membatalkan.");
                }
                telegramAdminProvOvrSet($botIdN, $chatId, $peId, $peField, $peVal);
                $send("✏️ $peField di-set: $peVal");
                telegramAdminProvView($conn, $token, $chatId, $botIdN, $pemilikIn, $peId);
                return true;

            case 'prov_set':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $ps = preg_split('/\s+/', trim($arg), 3, PREG_SPLIT_NO_EMPTY);
                $psId = (int)($ps[0] ?? 0);
                $psField = strtolower(trim($ps[1] ?? ''));
                $psVal = trim($ps[2] ?? '');
                if ($psId <= 0 || !in_array($psField, provisioning_approve_editable_fields(), true) || $psVal === '') {
                    return $send("Format tidak valid.");
                }
                telegramAdminProvOvrSet($botIdN, $chatId, $psId, $psField, $psVal);
                $send("✏️ $psField di-set: $psVal");
                telegramAdminProvView($conn, $token, $chatId, $botIdN, $pemilikIn, $psId);
                return true;

            case 'prov_reset':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $prId = (int)trim($arg);
                if ($prId <= 0) return $send("Format: /prov_reset <id>");
                telegramAdminProvOvrClear($botIdN, $chatId, $prId);
                $send("↺ Semua editan utk provisioning #$prId dibatalkan.");
                telegramAdminProvView($conn, $token, $chatId, $botIdN, $pemilikIn, $prId);
                return true;

            case 'prov_approve':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $pa = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
                $paId = (int)($pa[0] ?? 0);
                if ($paId <= 0) return $send("Format: /prov_approve <id>");
                $ovrA = telegramAdminProvOvrClean(telegramAdminProvOvrGet($botIdN, $chatId, $paId));
                if (!in_array('ok', array_map('strtolower', $pa), true)) {
                    $ed = '(tanpa editan)';
                    if ($ovrA) {
                        $tmp = [];
                        foreach ($ovrA as $k => $v) { $tmp[] = "$k=$v"; }
                        $ed = implode(', ', $tmp);
                    }
                    return $sendKb("Konfirmasi APPROVE provisioning #$paId?\nEditan: $ed\n\nAksi ini: buat pelanggan aktif + invoice PENAGIHAN + set comment secret di router.", [
                        [['✅ Ya, APPROVE sekarang', "/prov_approve $paId ok"]],
                        [['« Kembali', "/prov_view $paId"]],
                    ]);
                }
                require_once __DIR__ . '/../provisioning_action_lib.php';
                $byA = mb_substr((string)($botRow['pemilik'] ?? 'TG') . ' (Telegram)', 0, 100);
                $resA = provisioning_do_approve($conn, $paId, "p.server_pemilik IN ($pemilikIn)", $ovrA, $byA);
                telegramAdminProvOvrClear($botIdN, $chatId, $paId);
                $exA = '';
                if (!empty($resA['notif_registrasi']['attempted'])) {
                    $exA = "\nNotif WA registrasi: " . (!empty($resA['notif_registrasi']['success']) ? 'terkirim' : ('gagal (' . ($resA['notif_registrasi']['message'] ?? '') . ')'));
                }
                return $send((!empty($resA['success']) ? '✅ ' : '❌ ') . ($resA['message'] ?? 'Tidak ada respons') . $exA);

            case 'prov_reject':
                if (!$perm('admin_perm_provisioning')) return $deny();
                $pj = preg_split('/\s+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
                $pjId = (int)($pj[0] ?? 0);
                if ($pjId <= 0) return $send("Format: /prov_reject <id>");
                if (!in_array('ok', array_map('strtolower', $pj), true)) {
                    return $sendKb("Konfirmasi TOLAK provisioning #$pjId?\nSecret PPPoE pelanggan ini akan dihapus dari router / RADIUS.", [
                        [['❌ Ya, TOLAK sekarang', "/prov_reject $pjId ok"]],
                        [['« Kembali', "/prov_view $pjId"]],
                    ]);
                }
                require_once __DIR__ . '/../provisioning_action_lib.php';
                $byJ = mb_substr((string)($botRow['pemilik'] ?? 'TG') . ' (Telegram)', 0, 100);
                telegramAdminProvOvrClear($botIdN, $chatId, $pjId);
                $resJ = provisioning_do_reject($conn, $pjId, "p.server_pemilik IN ($pemilikIn)", $byJ);
                return $send((!empty($resJ['success']) ? '✅ ' : '❌ ') . ($resJ['message'] ?? 'Tidak ada respons'));
        }
        return false;
    }
}

/* =======================================================================
 *  PROVISIONING via Bot Telegram Admin
 *  Lihat daftar PENDING, foto evidence, EDIT field, APPROVE / REJECT.
 *  Aksi inti dijalankan lewat provisioning_action_lib.php (dipakai bareng
 *  proses_provisioning_action.php versi web).
 * ===================================================================== */

if (!function_exists('telegramAdminProvOvrPath')) {
    function telegramAdminProvOvrPath(): string { return __DIR__ . '/data/tg_prov_overrides.json'; }

    function telegramAdminProvOvrRead(): array
    {
        $f = telegramAdminProvOvrPath();
        if (!is_file($f)) return [];
        $d = json_decode((string)@file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    function telegramAdminProvOvrWrite(array $data): void
    {
        $f = telegramAdminProvOvrPath();
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    function telegramAdminProvOvrKey($botId, string $chatId, int $id): string
    {
        return (int)$botId . ':' . preg_replace('/[^0-9\-]/', '', $chatId) . ':' . (int)$id;
    }

    /** @return array<string,string> field=>value (subset provisioning_approve_editable_fields) */
    function telegramAdminProvOvrGet($botId, string $chatId, int $id): array
    {
        $all = telegramAdminProvOvrRead();
        $k = telegramAdminProvOvrKey($botId, $chatId, $id);
        return isset($all[$k]) && is_array($all[$k]) ? $all[$k] : [];
    }

    function telegramAdminProvOvrSet($botId, string $chatId, int $id, string $field, string $value): void
    {
        if (!in_array($field, provisioning_approve_editable_fields(), true)) return;
        $all = telegramAdminProvOvrRead();
        $k = telegramAdminProvOvrKey($botId, $chatId, $id);
        if (!isset($all[$k]) || !is_array($all[$k])) $all[$k] = [];
        $all[$k][$field] = mb_substr($value, 0, 500);
        $all[$k]['_ts'] = time();
        // buang entri lebih tua dari 24 jam supaya file tidak membengkak
        foreach ($all as $kk => $vv) {
            if (is_array($vv) && isset($vv['_ts']) && (time() - (int)$vv['_ts']) > 86400) {
                unset($all[$kk]);
            }
        }
        telegramAdminProvOvrWrite($all);
    }

    function telegramAdminProvOvrClear($botId, string $chatId, int $id): void
    {
        $all = telegramAdminProvOvrRead();
        $k = telegramAdminProvOvrKey($botId, $chatId, $id);
        if (isset($all[$k])) { unset($all[$k]); telegramAdminProvOvrWrite($all); }
    }

    /** Buang key internal ('_ts') supaya siap dikirim ke provisioning_do_approve(). */
    function telegramAdminProvOvrClean(array $ovr): array
    {
        unset($ovr['_ts']);
        $out = [];
        foreach ($ovr as $k => $v) {
            if (in_array($k, provisioning_approve_editable_fields(), true)) {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }
}

if (!function_exists('telegramAdminProvList')) {
    /** @return array{text:string, kb:array} */
    function telegramAdminProvList($conn, string $pemilikIn): array
    {
        $pemilikIn = trim($pemilikIn) !== '' ? $pemilikIn : "''";
        $sql = "SELECT p.id, p.idpel, p.nama, p.paket, p.area, p.server_brand, p.tanggal_pasang, p.expired_at, p.created_at
                FROM provisioning p
                WHERE p.status = 'PENDING' AND p.server_pemilik IN ($pemilikIn)
                ORDER BY p.id DESC
                LIMIT 20";
        $res = @mysqli_query($conn, $sql);
        if (!$res || mysqli_num_rows($res) === 0) {
            return ['text' => "Tidak ada data provisioning berstatus PENDING.", 'kb' => []];
        }
        $lines = ["PROVISIONING PENDING (" . mysqli_num_rows($res) . ")", ""];
        $kb = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $lines[] = "#" . $r['id'] . "  " . tgAdminSan($r['idpel'])
                . "\n   " . tgAdminSan($r['nama'])
                . " | " . tgAdminSan($r['paket'])
                . "\n   " . tgAdminSan(($r['server_brand'] ?: '-')) . " - " . tgAdminSan(($r['area'] ?: '-'))
                . " | pasang " . tgAdminSan((string)$r['tanggal_pasang']);
            $kb[] = [['👁 Lihat #' . $r['id'] . ' (' . mb_substr((string)$r['idpel'], 0, 18) . ')', '/prov_view ' . (int)$r['id']]];
        }
        $kb[] = [['↻ Muat ulang', '/provisioning']];
        return ['text' => implode("\n", $lines), 'kb' => $kb];
    }
}

if (!function_exists('telegramAdminProvPaketOptions')) {
    function telegramAdminProvPaketOptions($conn, string $pemilikIn, int $id): array
    {
        require_once __DIR__ . '/../provisioning_action_lib.php';
        $rec = provisioning_get_record($conn, $id, "p.server_pemilik IN (" . (trim($pemilikIn) !== '' ? $pemilikIn : "''") . ")");
        if (!$rec) return [];
        return provisioning_get_paket_options($conn, (string)$rec['server_pemilik'], (string)($rec['area'] ?? ''));
    }
}

if (!function_exists('telegramAdminProvView')) {
    function telegramAdminProvView($conn, string $token, string $chatId, $botId, string $pemilikIn, int $id): bool
    {
        require_once __DIR__ . '/../provisioning_action_lib.php';
        $scope = "p.server_pemilik IN (" . (trim($pemilikIn) !== '' ? $pemilikIn : "''") . ")";
        $rec = provisioning_get_record($conn, $id, $scope);
        if (!$rec) {
            sendTelegramMessage($token, $chatId, "Provisioning #$id tidak ditemukan / bukan wewenang bot ini.", '');
            return true;
        }

        $ovr = telegramAdminProvOvrClean(telegramAdminProvOvrGet($botId, $chatId, $id));
        $val = static function ($field) use ($rec, $ovr) {
            return array_key_exists($field, $ovr) ? $ovr[$field] : ($rec[$field] ?? '');
        };

        $L = [];
        $L[] = "PROVISIONING #$id  -  status: " . tgAdminSan((string)($rec['status'] ?? '-'));
        $L[] = "IDPEL       : " . tgAdminSan((string)$rec['idpel']);
        $L[] = "Nama        : " . tgAdminSan((string)$val('nama'));
        $L[] = "No WhatsApp : " . tgAdminSan((string)$val('nowa'));
        $L[] = "Email       : " . tgAdminSan((string)$val('email'));
        $L[] = "Alamat      : " . tgAdminSan((string)$val('alamat'));
        $L[] = "Paket       : " . tgAdminSan((string)$val('paket'));
        $L[] = "Tgl Pasang  : " . tgAdminSan((string)$val('tanggal_pasang'));
        $L[] = "Tipe Bayar  : " . tgAdminSan((string)$val('tipe_bayar'));
        $L[] = "Tipe Tempo  : " . tgAdminSan((string)$val('tipe_tempo'));
        $L[] = "TIKOR       : " . tgAdminSan((string)$val('tikor'));
        $L[] = "Area/Brand  : " . tgAdminSan((string)($rec['area'] ?? '-')) . " / " . tgAdminSan((string)($rec['server_brand'] ?? '-'));
        $L[] = "Server      : " . tgAdminSan((string)($rec['server_pemilik'] ?? '-'));
        $L[] = "ODP         : " . tgAdminSan((string)($rec['odp'] ?? '-'));
        $L[] = "Auth Mode   : " . tgAdminSan((string)($rec['auth_mode'] ?? '-'));
        $L[] = "Expired     : " . tgAdminSan((string)($rec['expired_at'] ?? '-'));
        $L[] = "Tiket       : #" . (int)($rec['tiket_id'] ?? 0);
        if (!empty($ovr)) {
            $ed = [];
            foreach ($ovr as $k => $v) { $ed[] = "$k=$v"; }
            $L[] = "";
            $L[] = "✏️ EDITAN belum diterapkan: " . tgAdminSan(implode(', ', $ed));
        }
        sendTelegramMessage($token, $chatId, implode("\n", $L), '');

        // Foto evidence dari tiket joblist terkait
        $tiketId = (int)($rec['tiket_id'] ?? 0);
        if ($tiketId > 0) {
            $jd = provisioning_get_joblist_ticket_data($tiketId);
            $photos = $jd['evidence_photos'] ?? [];
            if (empty($photos)) {
                sendTelegramMessage($token, $chatId, "(tidak ada foto evidence di tiket #$tiketId)", '');
            } else {
                $n = 0;
                foreach ($photos as $ph) {
                    $n++;
                    $src = (!empty($ph['path']) && is_file($ph['path'])) ? $ph['path'] : (string)($ph['url'] ?? '');
                    if ($src === '') continue;
                    $r = sendTelegramPhoto($token, $chatId, $src, "Evidence #$n - " . ($ph['filename'] ?? ''));
                    if (empty($r['sent']) && !empty($ph['url'])) {
                        sendTelegramMessage($token, $chatId, "Foto evidence #$n (gagal kirim, buka manual): " . $ph['url'], '');
                    }
                    if ($n >= 10) break;
                }
            }
        }

        // Keyboard aksi
        $kb = [
            [['✏️ Nama', "/prov_edit $id nama"], ['✏️ No WA', "/prov_edit $id nowa"]],
            [['✏️ Email', "/prov_edit $id email"], ['✏️ Alamat', "/prov_edit $id alamat"]],
            [['✏️ Tgl Pasang', "/prov_edit $id tanggal_pasang"], ['✏️ TIKOR', "/prov_edit $id tikor"]],
            [['✏️ Paket', "/prov_edit $id paket"], ['✏️ Tipe Bayar', "/prov_edit $id tipe_bayar"]],
            [['✏️ Tipe Tempo', "/prov_edit $id tipe_tempo"]],
            [['↺ Reset editan', "/prov_reset $id"]],
            [['✅ APPROVE', "/prov_approve $id"], ['❌ REJECT', "/prov_reject $id"]],
            [['« Daftar', "/provisioning"]],
        ];
        sendTelegramMessage($token, $chatId, "Pilih aksi untuk provisioning #$id:", '', telegramAdminKb($kb));
        return true;
    }
}

if (!function_exists('telegramAdminGenerateInvoice')) {
    /**
     * Panggil proses/manual_generate_invoice.php (rutin generate invoice yg SAMA
     * dengan tombol di halaman Transaksi) via HTTP internal, pakai bot_token.
     * Format arg: "<bulan> <tahun>"  -> bulan = angka 1-12 atau nama Indonesia.
     */
    function telegramAdminGenerateInvoice(array $botRow, string $arg): string
    {
        $bulanID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $parts = preg_split('/[\s\/\-]+/', trim($arg), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 2) {
            return "Format: /generateinvoice <bulan> <tahun>\nContoh: /generateinvoice 9 2026  atau  /generateinvoice September 2026";
        }
        [$mRaw, $yRaw] = $parts;
        $tahun = (int)$yRaw;
        if ($tahun < 2000 || $tahun > 2100) {
            return "Tahun tidak valid: $yRaw";
        }
        $namaBulan = '';
        if (ctype_digit((string)$mRaw)) {
            $mi = (int)$mRaw;
            if ($mi >= 1 && $mi <= 12) $namaBulan = $bulanID[$mi - 1];
        } else {
            foreach ($bulanID as $b) {
                if (strcasecmp($b, $mRaw) === 0) { $namaBulan = $b; break; }
            }
        }
        if ($namaBulan === '') {
            return "Bulan tidak dikenal: $mRaw (pakai 1-12 atau nama: Januari..Desember)";
        }

        $cfg = json_decode((string)@file_get_contents(__DIR__ . '/../config.json'), true);
        $base = rtrim((string)($cfg['URL'] ?? ''), '/');
        if ($base === '') {
            return "URL sistem belum diset di config.json — tidak bisa generate.";
        }
        $url = $base . '/crm/billing/proses/manual_generate_invoice.php';

        if (!function_exists('curl_init')) {
            return "cURL tidak aktif di server.";
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'bot_token' => (string)$botRow['bottoken'],
            'generate_month' => $namaBulan,
            'generate_year' => $tahun,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return "Gagal memanggil generator: $err";
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            return "Respons generator tidak dimengerti (HTTP $code).";
        }
        if (empty($j['success'])) {
            return "Generate GAGAL: " . (string)($j['message'] ?? 'tidak diketahui');
        }
        $ins = (int)($j['inserted'] ?? 0);
        $reg = (int)($j['regenerated'] ?? 0);
        $skip = (int)($j['skipped'] ?? 0);
        $out = [
            "GENERATE INVOICE — $namaBulan $tahun  (SELESAI)",
            "Dibuat baru : $ins" . ($reg > 0 ? "  (termasuk regenerate: $reg)" : ""),
            "Dilewati    : $skip  (sudah ada / sudah bayar / harga 0)",
        ];
        if (!empty($j['rincian_periode']) && is_array($j['rincian_periode'])) {
            $out[] = "";
            foreach ($j['rincian_periode'] as $per => $d) {
                $out[] = "• $per : +" . (int)($d['inserted'] ?? 0) . " , skip " . (int)($d['skipped'] ?? 0);
            }
        }
        $out[] = "";
        $out[] = "Invoice berstatus PENAGIHAN. Cek di menu Transaksi.";
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminSysUrl')) {
    function telegramAdminSysUrl(): string
    {
        $cfg = json_decode((string)@file_get_contents(__DIR__ . '/../config.json'), true);
        return rtrim((string)($cfg['URL'] ?? ''), '/');
    }
}

if (!function_exists('telegramAdminPostInternal')) {
    /**
     * POST internal ke proses/<file> (endpoint punya cabang bot_token).
     * Kalau $filePath diisi -> kirim sebagai multipart/form-data dgn field
     * bernama $fileField (dipakai upload foto bukti ke activecustomer.php,
     * persis seperti modal Manual Aktif).
     */
    function telegramAdminPostInternal(string $file, array $fields, string $filePath = '', string $fileField = ''): array
    {
        $base = telegramAdminSysUrl();
        if ($base === '') return ['ok' => false, 'msg' => 'URL sistem belum diset di config.json.'];
        if (!function_exists('curl_init')) return ['ok' => false, 'msg' => 'cURL tidak aktif di server.'];
        $ch = curl_init($base . '/crm/billing/proses/' . $file);
        curl_setopt($ch, CURLOPT_POST, true);
        if ($filePath !== '' && $fileField !== '' && is_file($filePath) && class_exists('CURLFile')) {
            $mime = 'image/jpeg';
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if ($ext === 'png') $mime = 'image/png';
            elseif ($ext === 'webp') $mime = 'image/webp';
            $fields[$fileField] = new CURLFile($filePath, $mime, 'bukti.' . ($ext ?: 'jpg'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fields); // array -> multipart, cURL set boundary sendiri
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $err !== '') return ['ok' => false, 'msg' => "Gagal panggil $file: $err"];
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) return ['ok' => false, 'msg' => "Respons $file tidak dimengerti (HTTP $code): " . mb_substr((string)$raw, 0, 200)];
        return ['ok' => true, 'json' => $j, 'http' => $code];
    }
}

if (!function_exists('telegramAdminPelRowForAksi')) {
    /** Ambil kolom pelanggan yg dibutuhkan proses aktif/reset, di-scope owner. */
    function telegramAdminPelRowForAksi($conn, string $pemilikIn, string $idpel): ?array
    {
        $e = mysqli_real_escape_string($conn, trim($idpel));
        $q = @mysqli_query($conn, "SELECT id, IDPEL, NAMA, COALESCE(EMAIL,'') EMAIL, COALESCE(NOWA,'') NOWA,
                COALESCE(PAKET,'') PAKET, PEMILIK, COALESCE(AREA,'') AREA
            FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn) AND IDPEL='$e' LIMIT 1");
        if ($q && ($r = mysqli_fetch_assoc($q))) return $r;
        return null;
    }
}

if (!function_exists('telegramAdminResetKoneksi')) {
    function telegramAdminResetKoneksi($conn, array $botRow, string $pemilikIn, string $idpel): string
    {
        $pel = telegramAdminPelRowForAksi($conn, $pemilikIn, $idpel);
        if (!$pel) return "Pelanggan \"$idpel\" tidak ditemukan / bukan milik Anda.";
        $r = telegramAdminPostInternal('resetkoneksi.php', [
            'bot_token' => (string)($botRow['bottoken'] ?? ''),
            'IDPEL'     => $pel['IDPEL'],
            'PEMILIK'   => $pel['PEMILIK'],
            'AREA'      => $pel['AREA'],
            'NAMA'      => $pel['NAMA'],
            'NOWA'      => $pel['NOWA'],
        ]);
        if (!$r['ok']) return "RESET KONEKSI GAGAL\n" . $r['msg'];
        $j = $r['json'];
        $st = strtoupper((string)($j['status'] ?? ''));
        $msg = (string)($j['message'] ?? 'tidak ada pesan');
        $det = trim((string)($j['detail'] ?? ''));
        $head = ($st === 'SUCCESS') ? "RESET KONEKSI OK" : ($st === 'INFO' ? "RESET KONEKSI (info)" : "RESET KONEKSI: $st");
        return $head . "\n" . $pel['NAMA'] . " (" . $pel['IDPEL'] . ")\n" . $msg . ($det !== '' ? "\n" . $det : "");
    }
}

if (!function_exists('telegramAdminAktif')) {
    /**
     * Manual Aktif pelanggan -- POST ke proses/activecustomer.php (cabang bot_token).
     * $metode: cash | transfer | kompensasi_free | gagal payment gateway
     * $onlyActivate: true = hanya aktifkan di router, tanpa catat transaksi.
     */
    function telegramAdminAktif($conn, array $botRow, string $pemilikIn, string $idpel, string $metode, bool $onlyActivate, string $buktiPath = ''): string
    {
        $pel = telegramAdminPelRowForAksi($conn, $pemilikIn, $idpel);
        if (!$pel) return "Pelanggan \"$idpel\" tidak ditemukan / bukan milik Anda.";
        $metode = strtolower(trim($metode));
        if ($metode === 'gagalpg') $metode = 'gagal payment gateway';
        if (!in_array($metode, ['cash', 'transfer', 'kompensasi_free', 'gagal payment gateway'], true)) $metode = 'cash';

        $fields = [
            'bot_token'  => (string)($botRow['bottoken'] ?? ''),
            'id'         => (int)($pel['id'] ?? 0),
            'IDPEL'      => $pel['IDPEL'],
            'NAMA'       => $pel['NAMA'],
            'EMAIL'      => $pel['EMAIL'],
            'NOWA'       => $pel['NOWA'],
            'PAKET'      => $pel['PAKET'],
            'PEMILIK'    => $pel['PEMILIK'],
            'AREA'       => $pel['AREA'],
            'metode_bayar' => $metode,
            'harga_manual' => '', // kosong -> ikut harga paket (kompensasi_free tetap 0)
            'only_activate_without_transaksi' => $onlyActivate ? '1' : '0',
            'periode_month' => (string)(int)date('n'),
            'periode_year'  => (string)(int)date('Y'),
            'tanggal_bayar_manual' => date('Y-m-d'),
        ];
        // Upload foto bukti (multipart, field 'bukti_pembayaran') -- PERSIS seperti
        // modal Manual Aktif. activecustomer.php mewajibkan bukti utk metode
        // cash/transfer/gagal payment gateway (kecuali kompensasi_free / hanya-aktifkan).
        $r = telegramAdminPostInternal('activecustomer.php', $fields, $buktiPath, $buktiPath !== '' ? 'bukti_pembayaran' : '');
        if ($buktiPath !== '' && is_file($buktiPath)) @unlink($buktiPath);
        if (!$r['ok']) return "MANUAL AKTIF GAGAL\n" . $r['msg'];
        $j = $r['json'];
        $msg = (string)($j['message'] ?? 'tidak ada pesan');
        $ok  = !empty($j['success']) || stripos($msg, 'berhasil') !== false;
        $head = $ok ? "MANUAL AKTIF OK" : "MANUAL AKTIF";
        $modeTxt = $onlyActivate ? "hanya aktifkan (tanpa transaksi)" : ("metode: " . $metode . ", periode " . date('n') . "/" . date('Y'));
        $buktiTxt = $buktiPath !== '' ? "\nbukti: terlampir" : "";
        return $head . "\n" . $pel['NAMA'] . " (" . $pel['IDPEL'] . ")\n" . $modeTxt . $buktiTxt . "\n\n" . $msg;
    }
}

if (!function_exists('telegramAdminTgFileDownload')) {
    /** Unduh file Telegram (foto bukti) ke file tmp lokal. Return path atau null. */
    function telegramAdminTgFileDownload(string $token, string $fileId): ?string
    {
        if ($token === '' || $fileId === '' || !function_exists('curl_init')) return null;
        $get = function ($url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $out = curl_exec($ch);
            curl_close($ch);
            return $out;
        };
        $meta = json_decode((string)$get("https://api.telegram.org/bot" . $token . "/getFile?file_id=" . rawurlencode($fileId)), true);
        $fp = $meta['result']['file_path'] ?? '';
        if ($fp === '') return null;
        $bin = $get("https://api.telegram.org/file/bot" . $token . "/" . $fp);
        if ($bin === false || strlen((string)$bin) < 64) return null;
        $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) $ext = 'jpg';
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/tgbukti_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (@file_put_contents($tmp, $bin) === false) return null;
        return $tmp;
    }
}

if (!function_exists('telegramAdminLiveChat')) {
    function telegramAdminLiveChat($conn, string $owner, int $hari = 1): string
    {
        $e = mysqli_real_escape_string($conn, $owner);
        $h = max(1, (int)$hari);
        $base = telegramAdminSysUrl();

        // Hanya kontak yg pesan belum-dibaca TERAKHIR-nya <= $h hari (default 1 hari).
        $res = @mysqli_query($conn, "SELECT sender_id, COUNT(*) c, MAX(`timestamp`) last,
                SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY `timestamp` DESC SEPARATOR '\\n'),'\\n',1) lastmsg
                FROM `messages` WHERE receiver_id='$e' AND COALESCE(is_read,0)=0
                GROUP BY sender_id
                HAVING MAX(`timestamp`) >= (NOW() - INTERVAL $h DAY)
                ORDER BY last DESC LIMIT 15");

        // jumlah kontak dgn unread LEBIH LAMA dari window (info saja)
        $older = 0;
        $qo = @mysqli_query($conn, "SELECT COUNT(DISTINCT sender_id) c FROM `messages`
                WHERE receiver_id='$e' AND COALESCE(is_read,0)=0 AND `timestamp` < (NOW() - INTERVAL $h DAY)");
        if ($qo && ($ro = mysqli_fetch_assoc($qo))) $older = (int)$ro['c'];

        $winTxt = $h >= 3650 ? 'semua' : ($h == 1 ? '24 jam terakhir' : ($h . ' hari terakhir'));
        if (!$res || mysqli_num_rows($res) === 0) {
            $gl = $base !== '' ? ($base . '/crm/billing/tables.php') : '(menu Pelanggan)';
            $t = "LIVE CHAT ($winTxt)\nTidak ada pesan belum dibaca.";
            if ($older > 0) $t .= "\n\n(" . $older . " kontak punya pesan belum dibaca > $h hari — kirim /livechat " . ($h * 7) . " atau /livechat all)";
            return $t . "\n\nBuka: " . $gl;
        }
        $tot = 0; $out = ["LIVE CHAT — belum dibaca ($winTxt)", ""];
        while ($r = mysqli_fetch_assoc($res)) {
            $tot += (int)$r['c'];
            $sid = trim((string)$r['sender_id']);
            // sender_id sering "IDPEL ( Whatsapp : 628xxx )"
            $idpel = $sid; $nowa = '';
            if (preg_match('~^(\S+)\s*\(\s*Whatsapp\s*:\s*([0-9+]+)\s*\)~i', $sid, $mm)) {
                $idpel = $mm[1]; $nowa = $mm[2];
            }
            // ambil PEMILIK + NAMA + NOWA dari pelanggan
            $pemilik = $owner; $nama = '';
            $ie = mysqli_real_escape_string($conn, $idpel);
            $qp = @mysqli_query($conn, "SELECT PEMILIK, NAMA, NOWA FROM `pelanggan` WHERE IDPEL='$ie' LIMIT 1");
            if ($qp && ($p = mysqli_fetch_assoc($qp))) {
                $pemilik = (string)($p['PEMILIK'] ?: $owner);
                $nama = (string)$p['NAMA'];
                if ($nowa === '') $nowa = (string)$p['NOWA'];
            }
            $msg = trim(preg_replace('/\s+/', ' ', (string)$r['lastmsg']));
            if (mb_strlen($msg) > 90) $msg = mb_substr($msg, 0, 87) . '...';

            $out[] = "• " . ($nama !== '' ? $nama . " " : "") . "(" . $idpel . ")  — " . (int)$r['c'] . " pesan";
            if ($msg !== '') $out[] = "  \"" . $msg . "\"";
            if ($base !== '') {
                $out[] = "  Balas: " . $base . "/crm/chat/index.php?admin=" . rawurlencode($pemilik)
                    . "&pelanggan=" . rawurlencode($idpel) . "&nowa=" . rawurlencode($nowa);
            }
            $out[] = "";
        }
        $out[] = "Total $tot pesan dari " . mysqli_num_rows($res) . " kontak (" . $winTxt . ").";
        if ($older > 0) {
            $out[] = $older . " kontak lain punya pesan belum dibaca > $h hari — /livechat " . ($h * 7) . " atau /livechat all";
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminKejadian')) {
    function telegramAdminKejadian($conn, string $owner): string
    {
        $e = mysqli_real_escape_string($conn, $owner);
        $res = @mysqli_query($conn, "SELECT customer_name, identity, channel, message, created_at
                FROM `chat_event_log` WHERE pemilik='$e' ORDER BY created_at DESC LIMIT 15");
        if (!$res) {
            return "Tabel Catatan Kejadian belum ada di server ini.";
        }
        if (mysqli_num_rows($res) === 0) {
            return "CATATAN KEJADIAN\nBelum ada catatan.";
        }
        $out = ["CATATAN KEJADIAN — 15 terakhir"];
        while ($r = mysqli_fetch_assoc($res)) {
            $who = trim((string)$r['customer_name']) !== '' ? $r['customer_name'] : $r['identity'];
            $msg = trim(preg_replace('/\s+/', ' ', (string)$r['message']));
            if (mb_strlen($msg) > 160) $msg = mb_substr($msg, 0, 157) . '...';
            $out[] = "";
            $out[] = "[" . $r['created_at'] . "] " . strtoupper((string)$r['channel']) . " — " . $who;
            $out[] = $msg;
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminNetCache')) {
    /**
     * Baca cache status jaringan dashboard: serverlog/<owner>.txt
     * keys: expired_ids[], los_ids[], offline_non_expired_ids[], odp_all_los[],
     *       odp_summary[], updated_at. SUMBER SAMA dgn kartu "Expired Online/Los"
     *       & "Internet Los" di dashboard.php.
     */
    function telegramAdminNetCache(string $owner): ?array
    {
        static $memo = [];
        if (array_key_exists($owner, $memo)) return $memo[$owner];
        $f = __DIR__ . '/../serverlog/' . $owner . '.txt';
        if (!is_file($f)) return $memo[$owner] = null;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j)) return $memo[$owner] = null;
        $lc = function ($a) {
            $o = [];
            foreach ((array)$a as $v) { $k = strtolower(trim((string)$v)); if ($k !== '') $o[$k] = true; }
            return $o;
        };
        return $memo[$owner] = [
            'expired' => $lc($j['expired_ids'] ?? []),
            'los'     => $lc($j['los_ids'] ?? []),
            'off_ne'  => $lc($j['offline_non_expired_ids'] ?? []),
            'odp_all_los' => is_array($j['odp_all_los'] ?? null) ? $j['odp_all_los'] : [],
            'updated_at'  => (string)($j['updated_at'] ?? ''),
            // angka kartu ATAS dashboard (Total Users / Internet Online / Los /
            // Expired online / Expired los) + profil paket utk Estimate Income.
            'totals' => [
                'pelanggan'       => (int)($j['Total_pelanggan'] ?? 0),
                'online_paket'    => (int)($j['Total_online_paket'] ?? 0),
                'los_internet'    => (int)($j['Total_los_internet'] ?? 0),
                'online_expired'  => (int)($j['Total_online_expired'] ?? 0),
                'expired_offline' => (int)($j['Total_expired_offline'] ?? 0),
            ],
            'pppoe_profiles'    => is_array($j['pppoe_profiles'] ?? null) ? $j['pppoe_profiles'] : [],
            'hotspot_profiles'  => is_array($j['hotspot_profiles'] ?? null) ? $j['hotspot_profiles'] : [],
            'paket_harga_pppoe'   => is_array($j['paket_harga_pppoe'] ?? null) ? $j['paket_harga_pppoe'] : [],
            'paket_harga_hotspot' => is_array($j['paket_harga_hotspot'] ?? null) ? $j['paket_harga_hotspot'] : [],
        ];
    }
}

if (!function_exists('telegramAdminExpired')) {
    function telegramAdminExpired($conn, string $pemilikIn, string $owner): string
    {
        $c = telegramAdminNetCache($owner);
        if ($c === null) {
            return "Cache status jaringan belum tersedia (serverlog/" . $owner . ".txt). Pastikan cron scan router jalan.";
        }
        if (empty($c['expired'])) {
            return "EXPIRED\nTidak ada pelanggan expired." . ($c['updated_at'] ? "\n(data: " . $c['updated_at'] . ")" : "");
        }
        $ids = array_keys($c['expired']);
        $inList = "'" . implode("','", array_map(function ($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $ids)) . "'";
        $res = @mysqli_query($conn, "SELECT IDPEL, NAMA, AREA, ODP, NOWA FROM `pelanggan`
                WHERE PEMILIK IN ($pemilikIn) AND LOWER(IDPEL) IN (" . strtolower($inList) . ") ORDER BY AREA, NAMA");
        $onl = []; $lo = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $isLos = isset($c['los'][strtolower(trim((string)$r['IDPEL']))]);
                if ($isLos) { $lo[] = $r; } else { $onl[] = $r; }
            }
        }
        $out = ["EXPIRED (tagihan lewat)  — total " . count($ids)];
        if ($c['updated_at']) $out[] = "data: " . $c['updated_at'];
        $out[] = "";
        $out[] = "EXPIRED tapi ONLINE : " . count($onl);
        foreach (array_slice($onl, 0, 20) as $r) {
            $out[] = "• " . $r['NAMA'] . " (" . $r['IDPEL'] . ") — " . $r['AREA'] . " / " . $r['ODP'];
        }
        if (count($onl) > 20) $out[] = "  ...(+".(count($onl)-20).")";
        $out[] = "";
        $out[] = "EXPIRED + LOS : " . count($lo);
        foreach (array_slice($lo, 0, 20) as $r) {
            $out[] = "• " . $r['NAMA'] . " (" . $r['IDPEL'] . ") — " . $r['AREA'] . " / " . $r['ODP'];
        }
        if (count($lo) > 20) $out[] = "  ...(+".(count($lo)-20).")";
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminLos')) {
    function telegramAdminLos($conn, string $pemilikIn, string $owner): string
    {
        $c = telegramAdminNetCache($owner);
        if ($c === null) {
            return "Cache status jaringan belum tersedia (serverlog/" . $owner . ".txt).";
        }
        $out = ["LOS (signal hilang / ONU mati)"];
        if ($c['updated_at']) $out[] = "data: " . $c['updated_at'];

        if (!empty($c['odp_all_los'])) {
            $out[] = "";
            $out[] = "!! ODP SATU-ODP LOS (indikasi kabel/ODP putus) — prioritas:";
            foreach ($c['odp_all_los'] as $o) {
                if (is_array($o)) {
                    $out[] = "  - " . ($o['ODP'] ?? '?') . "  (" . ($o['AREA'] ?? '') . ", "
                        . (int)($o['TOTAL_LOS'] ?? $o['TOTAL_PELANGGAN'] ?? 0) . " plg LOS)";
                } else {
                    $out[] = "  - " . (string)$o;
                }
            }
        }
        if (empty($c['los'])) {
            $out[] = "";
            $out[] = "Tidak ada pelanggan LOS.";
            return implode("\n", $out);
        }
        $ids = array_keys($c['los']);
        $inList = "'" . implode("','", array_map(function ($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $ids)) . "'";
        $res = @mysqli_query($conn, "SELECT IDPEL, NAMA, AREA, ODP FROM `pelanggan`
                WHERE PEMILIK IN ($pemilikIn) AND LOWER(IDPEL) IN (" . strtolower($inList) . ") ORDER BY ODP, NAMA");
        $rows = [];
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        $out[] = "";
        $out[] = "Total pelanggan LOS : " . count($ids);
        $shown = 0;
        foreach ($rows as $r) {
            if ($shown++ >= 30) break;
            $exp = isset($c['expired'][strtolower(trim((string)$r['IDPEL']))]) ? " [EXPIRED]" : "";
            $out[] = "• " . $r['NAMA'] . " (" . $r['IDPEL'] . ") — " . $r['AREA'] . " / " . $r['ODP'] . $exp;
        }
        if (count($rows) > 30) $out[] = "  ...(+".(count($rows)-30).")";
        return implode("\n", $out);
    }
}

/* ------------------------- Query helpers (defensif, di-scope) ------------------------- */

if (!function_exists('telegramAdminCariPelanggan')) {
    function telegramAdminCariPelanggan($conn, array $botRow, string $pemilikIn, string $q, bool $single = false, array $onlineSet = []): string
    {
        $qEsc = mysqli_real_escape_string($conn, $q);
        $qDigits = preg_replace('/[^0-9]/', '', $q);
        $like = '%' . $qEsc . '%';
        $lim = $single ? 1 : 6;
        // no.WA sering diketik 08xxxx sedangkan DB simpan 628xxxx -> cocokkan digit belakang.
        $waCond = '';
        if (strlen($qDigits) >= 6) {
            $tail = mysqli_real_escape_string($conn, substr($qDigits, -8));
            $waCond = " OR REPLACE(REPLACE(NOWA,'+',''),' ','') LIKE '%$tail%'";
        }
        $sql = "SELECT * FROM `pelanggan`
                WHERE PEMILIK IN ($pemilikIn)
                  AND (IDPEL = '$qEsc' OR NAMA LIKE '$like' OR NOWA LIKE '$like'$waCond)
                ORDER BY (IDPEL = '$qEsc') DESC, NAMA ASC LIMIT $lim";
        $res = @mysqli_query($conn, $sql);
        if (!$res) {
            $sql = "SELECT * FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn)
                      AND (IDPEL = '$qEsc' OR NAMA LIKE '$like') ORDER BY NAMA ASC LIMIT $lim";
            $res = @mysqli_query($conn, $sql);
        }
        if (!$res || mysqli_num_rows($res) === 0) {
            return "Pelanggan \"$q\" tidak ditemukan.";
        }
        $tpl = trim((string)($botRow['admin_tpl_pelanggan'] ?? ''));
        if ($tpl === '') {
            $tpl = telegramAdminTemplateList()['admin_tpl_pelanggan']['default'];
        }
        $out = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $out[] = telegramAdminRenderTpl($tpl, telegramAdminPelangganVars($r, $onlineSet, $conn, $pemilikIn, (string)($botRow['pemilik'] ?? '')));
        }
        $extra = (!$single && mysqli_num_rows($res) >= 6) ? "\n\n(hasil dibatasi 6 — persempit kata kunci)" : '';
        return implode("\n\n", $out) . $extra;
    }
}

if (!function_exists('telegramAdminServerPollAge')) {
    /** Umur (detik) file *_online_client.txt paling baru utk sebuah PEMILIK. -1 kalau tak ada. */
    function telegramAdminServerPollAge(string $pemilik): int
    {
        $f = __DIR__ . '/../serverlog/' . $pemilik . '_online_client.txt';
        if (!is_file($f)) return -1;
        $m = @filemtime($f);
        return $m ? (time() - (int)$m) : -1;
    }
}

if (!function_exists('telegramAdminServer')) {
    function telegramAdminServer($conn, string $pemilikIn, int $ownerId, string $arg, array $onlineSet = []): string
    {
        $where = $ownerId > 0 ? "user_id = $ownerId" : "PEMILIK IN ($pemilikIn)";
        if ($arg !== '') {
            $a = mysqli_real_escape_string($conn, $arg);
            $where .= " AND (PEMILIK LIKE '%$a%' OR AREA LIKE '%$a%')";
        }
        $res = @mysqli_query($conn, "SELECT * FROM `server` WHERE $where ORDER BY PEMILIK, AREA LIMIT 40");
        if (!$res || mysqli_num_rows($res) === 0) {
            return "Tidak ada data server.";
        }

        // jumlah pelanggan + online per AREA (server = 1 baris per area)
        $perArea = [];
        $qp = @mysqli_query($conn, "SELECT AREA, IDPEL FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn)");
        if ($qp) {
            while ($r = mysqli_fetch_assoc($qp)) {
                $a = strtoupper(trim((string)$r['AREA']));
                if (!isset($perArea[$a])) $perArea[$a] = ['n' => 0, 'on' => 0];
                $perArea[$a]['n']++;
                if (isset($onlineSet[strtolower(trim((string)$r['IDPEL']))])) $perArea[$a]['on']++;
            }
        }

        $out = ["DAFTAR SERVER (" . mysqli_num_rows($res) . ")"];
        $no = 0;
        while ($r = mysqli_fetch_assoc($res)) {
            $no++;
            $pem = telegramAdminPick($r, ['PEMILIK']);
            $area = telegramAdminPick($r, ['AREA']);
            $line = "$no. " . $pem . " / " . $area;
            $ip = telegramAdminPick($r, ['IP', 'HOST', 'IP_MIKROTIK', 'ROUTER_IP', 'ip'], '');
            if ($ip !== '' && $ip !== '-') $line .= "  (" . $ip . ")";
            $out[] = $line;

            $st = telegramAdminPick($r, ['STATUS', 'status', 'KONEKSI', 'STATUS_SERVER', 'CONNECTION_MODE'], '');
            $age = telegramAdminServerPollAge($pem);
            if ($age < 0) {
                $pollTxt = "poller belum ada data";
            } elseif ($age <= 300) {
                $pollTxt = "poller AKTIF (" . $age . " dtk lalu)";
            } else {
                $pollTxt = "poller basi (" . round($age / 60) . " mnt lalu)";
            }
            $ak = strtoupper(trim($area));
            $onInfo = isset($perArea[$ak])
                ? ($perArea[$ak]['n'] . " plg  |  online " . $perArea[$ak]['on'])
                : "0 plg";
            $out[] = "   " . ($st !== '' && $st !== '-' ? $st . " | " : "") . $pollTxt . " | " . $onInfo;
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminOdpTree')) {
    /**
     * Struktur AREA -> ODP -> pelanggan, TER-URUT DETERMINISTIK supaya nomor
     * urut konsisten antar-panggilan (tanpa perlu simpan state).
     * return ['areas'=>[ 'NAMA_AREA'=>['odps'=>['NAMA_ODP'=>['n'=>,'on'=>,'rows'=>[...]]], 'n'=>,'on'=>] ], 'order'=>[nama area urut] ]
     */
    function telegramAdminOdpTree($conn, string $pemilikIn, array $onlineSet): array
    {
        $res = @mysqli_query($conn, "SELECT IDPEL, NAMA,
                    COALESCE(NULLIF(TRIM(AREA),''),'(tanpa area)') area,
                    COALESCE(NULLIF(TRIM(ODP),''),'(tanpa ODP)') odp
                FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn)");
        $areas = [];
        if ($res) {
            while ($r = mysqli_fetch_assoc($res)) {
                $a = $r['area']; $o = $r['odp'];
                if (!isset($areas[$a])) $areas[$a] = ['odps' => [], 'n' => 0, 'on' => 0];
                if (!isset($areas[$a]['odps'][$o])) $areas[$a]['odps'][$o] = ['n' => 0, 'on' => 0, 'rows' => []];
                $online = isset($onlineSet[strtolower(trim((string)$r['IDPEL']))]);
                $areas[$a]['n']++; $areas[$a]['odps'][$o]['n']++;
                if ($online) { $areas[$a]['on']++; $areas[$a]['odps'][$o]['on']++; }
                $areas[$a]['odps'][$o]['rows'][] = [
                    'idpel' => (string)$r['IDPEL'], 'nama' => (string)$r['NAMA'], 'online' => $online,
                ];
            }
        }
        uksort($areas, 'strnatcasecmp');
        foreach ($areas as $a => &$ad) {
            uksort($ad['odps'], 'strnatcasecmp');
            foreach ($ad['odps'] as &$od) {
                usort($od['rows'], function ($x, $y) { return strnatcasecmp($x['nama'], $y['nama']); });
            }
            unset($od);
        }
        unset($ad);
        return ['areas' => $areas, 'order' => array_keys($areas)];
    }
}

if (!function_exists('telegramAdminAreaList')) {
    /** /odp tanpa argumen -> daftar AREA BERNOMOR. */
    function telegramAdminAreaList($conn, string $pemilikIn, array $onlineSet = []): string
    {
        $t = telegramAdminOdpTree($conn, $pemilikIn, $onlineSet);
        if (empty($t['order'])) {
            return "Belum ada data pelanggan.";
        }
        $out = ["DAFTAR AREA (" . count($t['order']) . ")  — no. | ODP | pelanggan | online/offline"];
        $i = 0;
        foreach ($t['order'] as $a) {
            $i++;
            $v = $t['areas'][$a];
            $off = $v['n'] - $v['on'];
            $out[] = "$i. $a  —  " . count($v['odps']) . " ODP, {$v['n']} plg (on {$v['on']} / off $off)";
        }
        $out[] = "";
        $out[] = "Kirim: /odp <no>        -> daftar ODP di area itu";
        $out[] = "       /odp <no> <no>   -> pelanggan di ODP tsb";
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminOdpByAreaNo')) {
    /** /odp <no area> -> daftar ODP bernomor dalam area itu. */
    function telegramAdminOdpByAreaNo($conn, string $pemilikIn, int $areaNo, array $onlineSet = []): string
    {
        $t = telegramAdminOdpTree($conn, $pemilikIn, $onlineSet);
        if ($areaNo < 1 || $areaNo > count($t['order'])) {
            return "Nomor area $areaNo tidak ada. Kirim /odp untuk lihat daftar area.";
        }
        $a = $t['order'][$areaNo - 1];
        $v = $t['areas'][$a];
        $out = ["AREA $areaNo. $a", count($v['odps']) . " ODP  |  {$v['n']} pelanggan  |  online {$v['on']}  offline " . ($v['n'] - $v['on']), ""];
        $i = 0;
        foreach ($v['odps'] as $o => $od) {
            $i++;
            $off = $od['n'] - $od['on'];
            $out[] = "$i. $o  —  {$od['n']} plg (on {$od['on']} / off $off)";
        }
        $out[] = "";
        $out[] = "Kirim: /odp $areaNo <no>  -> daftar pelanggan di ODP itu";
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminOdpDetailNo')) {
    /** /odp <no area> <no odp> -> daftar pelanggan + online/offline. */
    function telegramAdminOdpDetailNo($conn, array $botRow, string $pemilikIn, int $areaNo, int $odpNo, array $onlineSet = []): string
    {
        $t = telegramAdminOdpTree($conn, $pemilikIn, $onlineSet);
        if ($areaNo < 1 || $areaNo > count($t['order'])) {
            return "Nomor area $areaNo tidak ada.";
        }
        $a = $t['order'][$areaNo - 1];
        $odpNames = array_keys($t['areas'][$a]['odps']);
        if ($odpNo < 1 || $odpNo > count($odpNames)) {
            return "Nomor ODP $odpNo tidak ada di area $areaNo. Kirim /odp $areaNo untuk lihat daftarnya.";
        }
        $o = $odpNames[$odpNo - 1];
        $od = $t['areas'][$a]['odps'][$o];
        $off = $od['n'] - $od['on'];
        $out = [
            "ODP $o",
            "Area $areaNo. $a",
            "{$od['n']} pelanggan  |  online {$od['on']}  offline $off",
            "",
        ];
        foreach ($od['rows'] as $row) {
            $out[] = ($row['online'] ? "[ON ] " : "[off] ") . $row['nama'] . " (" . $row['idpel'] . ")";
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminOdpResolve')) {
    /**
     * /odp <arg>:
     *  - "<no>"        -> daftar ODP area no itu
     *  - "<no> <no>"   -> pelanggan di ODP tsb
     *  - "<teks area>" -> daftar ODP area (match nama)
     *  - "<teks odp>"  -> pelanggan di ODP (match nama)
     */
    function telegramAdminOdpResolve($conn, array $botRow, string $pemilikIn, string $arg, array $onlineSet = []): string
    {
        $arg = trim($arg);
        if (preg_match('/^(\d+)\s+(\d+)$/', $arg, $m)) {
            return telegramAdminOdpDetailNo($conn, $botRow, $pemilikIn, (int)$m[1], (int)$m[2], $onlineSet);
        }
        if (preg_match('/^\d+$/', $arg)) {
            return telegramAdminOdpByAreaNo($conn, $pemilikIn, (int)$arg, $onlineSet);
        }
        // --- pencocokan berdasar teks ---
        $e = mysqli_real_escape_string($conn, $arg);
        $qo = @mysqli_query($conn, "SELECT COUNT(*) c FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn) AND (ODP = '$e' OR ODP LIKE '%$e%')");
        $odpMatch = ($qo && ($r = mysqli_fetch_assoc($qo))) ? (int)$r['c'] : 0;
        $qa = @mysqli_query($conn, "SELECT COUNT(*) c FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn) AND (AREA = '$e' OR AREA LIKE '%$e%')");
        $areaMatch = ($qa && ($r = mysqli_fetch_assoc($qa))) ? (int)$r['c'] : 0;

        if ($odpMatch > 0 && ($areaMatch === 0 || $odpMatch <= $areaMatch)) {
            return telegramAdminOdpDetailByName($conn, $pemilikIn, $arg, $onlineSet);
        }
        if ($areaMatch > 0) {
            // cari nomor area-nya lalu tampilkan bernomor
            $t = telegramAdminOdpTree($conn, $pemilikIn, $onlineSet);
            foreach ($t['order'] as $idx => $an) {
                if (strcasecmp($an, $arg) === 0 || stripos($an, $arg) !== false) {
                    return telegramAdminOdpByAreaNo($conn, $pemilikIn, $idx + 1, $onlineSet);
                }
            }
        }
        if ($odpMatch > 0) {
            return telegramAdminOdpDetailByName($conn, $pemilikIn, $arg, $onlineSet);
        }
        return "\"$arg\" tidak cocok dengan nomor/nama area maupun ODP. Kirim /odp untuk daftar.";
    }
}

if (!function_exists('telegramAdminOdpDetailByName')) {
    function telegramAdminOdpDetailByName($conn, string $pemilikIn, string $odp, array $onlineSet = []): string
    {
        $e = mysqli_real_escape_string($conn, $odp);
        $res = @mysqli_query($conn, "SELECT IDPEL, NAMA, AREA FROM `pelanggan`
                WHERE PEMILIK IN ($pemilikIn) AND (ODP = '$e' OR ODP LIKE '%$e%') ORDER BY NAMA ASC LIMIT 200");
        if (!$res || mysqli_num_rows($res) === 0) {
            return "Tidak ada pelanggan di ODP \"$odp\".";
        }
        $rows = []; $on = 0;
        while ($r = mysqli_fetch_assoc($res)) {
            $online = isset($onlineSet[strtolower(trim((string)$r['IDPEL']))]);
            if ($online) $on++;
            $rows[] = ($online ? "[ON ] " : "[off] ") . $r['NAMA'] . " (" . $r['IDPEL'] . ")";
        }
        $head = "ODP " . $odp . " — " . count($rows) . " pelanggan  |  online $on  |  offline " . (count($rows) - $on);
        return $head . "\n\n" . implode("\n", $rows);
    }
}

if (!function_exists('telegramAdminTagihan')) {
    function telegramAdminTagihan($conn, array $botRow, string $pemilikIn, string $idpel): string
    {
        $e = mysqli_real_escape_string($conn, $idpel);
        $res = @mysqli_query($conn, "SELECT * FROM `transaksi` WHERE IDPEL = '$e' AND PEMILIK IN ($pemilikIn) ORDER BY id DESC LIMIT 6");
        if (!$res) {
            return "Data transaksi tidak tersedia di server ini.";
        }
        if (mysqli_num_rows($res) === 0) {
            return "Belum ada transaksi untuk IDPEL \"$idpel\".";
        }
        $tpl = trim((string)($botRow['admin_tpl_tagihan'] ?? ''));
        if ($tpl === '') {
            $tpl = telegramAdminTemplateList()['admin_tpl_tagihan']['default'];
        }
        $out = ["RIWAYAT BAYAR — " . $idpel];
        while ($r = mysqli_fetch_assoc($res)) {
            $out[] = telegramAdminRenderTpl($tpl, [
                '{IDPEL}'       => telegramAdminPick($r, ['IDPEL']),
                '{NAMA}'        => telegramAdminPick($r, ['NAMA']),
                '{PERIODE}'     => telegramAdminPick($r, ['PENGUNAAN', 'PERIODE']),
                '{STATUS}'      => telegramAdminPick($r, ['STATUS']),
                '{HARGA}'       => telegramAdminPick($r, ['HARGA'], '0'),
                '{TANGGALBAYAR}' => telegramAdminPick($r, ['TANGGALBAYAR', 'waktu'], 'belum'),
                '{METODE}'      => telegramAdminPick($r, ['METODE_BAYAR', 'METODE'], ''),
            ]);
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminMenunggak')) {
    function telegramAdminMenunggak($conn, string $pemilikIn): string
    {
        $out = ["REKAP TUNGGAKAN"];
        $q1 = @mysqli_query($conn, "SELECT STATUS, COUNT(*) c FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn) GROUP BY STATUS ORDER BY c DESC");
        if ($q1 && mysqli_num_rows($q1) > 0) {
            $out[] = "";
            $out[] = "Pelanggan per status:";
            while ($r = mysqli_fetch_assoc($q1)) {
                $out[] = "• " . ($r['STATUS'] !== '' ? $r['STATUS'] : '(kosong)') . " : " . (int)$r['c'];
            }
        }
        $q2 = @mysqli_query($conn, "SELECT COUNT(*) c, COALESCE(SUM(HARGA),0) t FROM `transaksi`
                WHERE PEMILIK IN ($pemilikIn) AND UPPER(STATUS) NOT IN ('BERHASIL','LUNAS','PAID','SUKSES','SUCCESS','SETTLEMENT')");
        if ($q2 && ($r = mysqli_fetch_assoc($q2))) {
            $out[] = "";
            $out[] = "Transaksi belum lunas : " . (int)$r['c'] . " tagihan";
            $out[] = "Estimasi nilai        : Rp " . number_format((float)$r['t'], 0, ',', '.');
        }
        $q3 = @mysqli_query($conn, "SELECT IDPEL, NAMA, PENGUNAAN, HARGA FROM `transaksi`
                WHERE PEMILIK IN ($pemilikIn) AND UPPER(STATUS) NOT IN ('BERHASIL','LUNAS','PAID','SUKSES','SUCCESS','SETTLEMENT')
                ORDER BY id DESC LIMIT 15");
        if ($q3 && mysqli_num_rows($q3) > 0) {
            $out[] = "";
            $out[] = "15 terbaru:";
            while ($r = mysqli_fetch_assoc($q3)) {
                $out[] = "• " . $r['NAMA'] . " (" . $r['IDPEL'] . ") — " . $r['PENGUNAAN']
                    . " — Rp " . number_format((float)$r['HARGA'], 0, ',', '.');
            }
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminTransaksi')) {
    function telegramAdminTransaksi($conn, string $pemilikIn, int $hari): string
    {
        $sql = "SELECT COUNT(*) c, COALESCE(SUM(HARGA),0) t FROM `transaksi`
                WHERE PEMILIK IN ($pemilikIn)
                  AND UPPER(STATUS) IN ('BERHASIL','LUNAS','PAID','SUKSES','SUCCESS','SETTLEMENT')
                  AND waktu >= (NOW() - INTERVAL $hari DAY)";
        $res = @mysqli_query($conn, $sql);
        if (!$res || !($r = mysqli_fetch_assoc($res))) {
            return "Data transaksi tidak tersedia di server ini.";
        }
        $out = [
            "REKAP TRANSAKSI LUNAS — $hari hari terakhir",
            "Jumlah : " . (int)$r['c'] . " transaksi",
            "Total  : Rp " . number_format((float)$r['t'], 0, ',', '.'),
        ];
        $q2 = @mysqli_query($conn, "SELECT DATE(waktu) d, COUNT(*) c, COALESCE(SUM(HARGA),0) t
                FROM `transaksi` WHERE PEMILIK IN ($pemilikIn)
                  AND UPPER(STATUS) IN ('BERHASIL','LUNAS','PAID','SUKSES','SUCCESS','SETTLEMENT')
                  AND waktu >= (NOW() - INTERVAL $hari DAY)
                GROUP BY d ORDER BY d DESC LIMIT 10");
        if ($q2 && mysqli_num_rows($q2) > 0) {
            $out[] = "";
            while ($r = mysqli_fetch_assoc($q2)) {
                $out[] = "• " . $r['d'] . " : " . (int)$r['c'] . " — Rp " . number_format((float)$r['t'], 0, ',', '.');
            }
        }
        return implode("\n", $out);
    }
}

if (!function_exists('telegramAdminTiketTipeCanon')) {
    /**
     * Peta normalisasi ejaan tipe tiket -- data lama punya varian ejaan utk
     * tipe yg SEBENARNYA sama (mis. "Installasi"/"INSTALASI" salah ketik
     * lama, harusnya "INSTALLASI"). SAMA PERSIS peta yg dipakai
     * telegramAdminStatistik() ($tmap) -- WAJIB disinkronkan kalau salah
     * satu berubah. 4 tipe yg dicek user: INSTALLASI, MAINTENANCE,
     * DISMANTLE, MIGRASI (+ PROVISIONING dari wizard /buattiket bot).
     */
    function telegramAdminTiketTipeCanon(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        $map = [
            'INSTALLASI' => 'INSTALLASI',
            'INSTALASI'  => 'INSTALLASI',
            'MAINTENANCE' => 'MAINTENANCE',
            'DISMANTLE'   => 'DISMANTLE',
            'MIGRASI'     => 'MIGRASI',
            'PROVISIONING' => 'PROVISIONING',
        ];
        return $map[$raw] ?? ($raw !== '' ? $raw : '(TANPA TIPE)');
    }
}

if (!function_exists('telegramAdminTiketTipeVariants')) {
    /** Kebalikan dari telegramAdminTiketTipeCanon() -- semua ejaan mentah yg
     *  masuk ke 1 tipe kanonik, dipakai filter SQL WHERE ... IN (...). */
    function telegramAdminTiketTipeVariants(string $canon): array
    {
        $canon = strtoupper(trim($canon));
        $alias = [
            'INSTALLASI' => ['INSTALLASI', 'INSTALASI'],
        ];
        return $alias[$canon] ?? [$canon];
    }
}

if (!function_exists('telegramAdminTicketSource')) {
    /**
     * Owner boleh pilih SUMBER data tiket lewat pengaturan akun (kolom
     * user.ticket_management_source): 'tiket_manager' (tabel
     * billing_tiket_manager, default) ATAU 'joblist' (tabel `joblist` di DB
     * absensi TERPISAH). Dashboard (getdata/count_tiket.php) SUDAH cek ini --
     * bot HARUS ikut cek yg SAMA, kalau tidak /tiket bisa nampilin KOSONG /
     * beda dari yg dilihat owner di web (dialami nyata: tipe INSTALLASI ada
     * di web tapi tidak muncul di bot, krn datanya sebenarnya di joblist,
     * bukan billing_tiket_manager, utk owner yg pilih mode ini).
     */
    function telegramAdminTicketSource($conn, string $ownerUsername): string
    {
        $uEsc = mysqli_real_escape_string($conn, trim($ownerUsername));
        $q = @mysqli_query($conn, "SELECT ticket_management_source FROM `user` WHERE USERNAME = '$uEsc' LIMIT 1");
        $r = $q ? mysqli_fetch_assoc($q) : null;
        $src = strtolower(trim((string)($r['ticket_management_source'] ?? '')));
        return $src === 'joblist' ? 'joblist' : 'tiket_manager';
    }
}

if (!function_exists('telegramAdminJoblistConn')) {
    /**
     * Koneksi TERPISAH ke DB "absensi" (tempat tabel `joblist`) -- SAMA
     * PERSIS koneksidbabsensi.php, baca db_host_absensi dkk dari config.json
     * billing INI SENDIRI (bukan dari joblist/config.json spt
     * provisioning_get_joblist_ticket_data(), disengaja beda krn
     * count_tiket.php/dashboard jg pakai config.json billing utk ini).
     * Cache statis per-request supaya tidak connect berkali-kali kalau
     * beberapa fungsi tiket dipanggil di 1 request yg sama.
     */
    function telegramAdminJoblistConn(): ?mysqli
    {
        static $conn = null;
        static $tried = false;
        if ($tried) return $conn;
        $tried = true;
        $cfgFile = __DIR__ . '/../config.json';
        if (!is_file($cfgFile)) return null;
        $cfg = json_decode((string)file_get_contents($cfgFile), true);
        if (!is_array($cfg) || empty($cfg['db_host_absensi'])) return null;
        $c = @mysqli_connect($cfg['db_host_absensi'], $cfg['db_user_absensi'] ?? '', $cfg['db_pass_absensi'] ?? '', $cfg['db_name_absensi'] ?? '');
        $conn = $c instanceof mysqli ? $c : null;
        return $conn;
    }
}

if (!function_exists('telegramAdminTiketTypeList')) {
    /**
     * Submenu tipe tiket (/tiket) -- pengganti daftar rata dulu (semua tipe
     * campur jadi 1 list panjang). Kelompokkan dulu per TIPE KANONIK
     * (telegramAdminTiketTipeCanon(), gabungkan varian ejaan lama) + jumlahnya,
     * baru drill-down ke daftar per tipe (telegramAdminTiketByType()) lalu ke
     * detail 1 tiket (telegramAdminTiketDetail()) -- pola SAMA persis dgn
     * Provisioning List -> View. $source dari telegramAdminTicketSource().
     * @return array{text:string, kb:array}
     */
    function telegramAdminTiketTypeList($conn, string $pemilikIn, array $pemilikList, string $source): array
    {
        if ($source === 'joblist') {
            $jc = telegramAdminJoblistConn();
            if (!$jc) {
                return ['text' => "Sumber tiket (Joblist) sedang tidak bisa dihubungi.", 'kb' => []];
            }
            $projEsc = array_map(function ($p) use ($jc) {
                return "'" . mysqli_real_escape_string($jc, $p) . "'";
            }, $pemilikList);
            $res = @mysqli_query($jc, "SELECT tipe, COUNT(*) c FROM `joblist`
                    WHERE project IN (" . implode(',', $projEsc) . ") AND status IN ('BARU','PENDING')
                    GROUP BY tipe");
        } else {
            $res = @mysqli_query($conn, "SELECT tipe, COUNT(*) c FROM `billing_tiket_manager`
                    WHERE pemilik IN ($pemilikIn) AND UPPER(status) NOT IN ('SELESAI','CLOSED','DONE','BATAL','CANCEL')
                    GROUP BY tipe");
        }
        if (!$res || mysqli_num_rows($res) === 0) {
            return ['text' => "Tidak ada tiket gangguan terbuka.", 'kb' => []];
        }
        $grouped = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $canon = telegramAdminTiketTipeCanon((string)$r['tipe']);
            $grouped[$canon] = ($grouped[$canon] ?? 0) + (int)$r['c'];
        }
        arsort($grouped);
        $kb = [];
        $total = 0;
        foreach ($grouped as $tipeLabel => $c) {
            $total += $c;
            $kb[] = [[$tipeLabel . " ($c)", "/tiket_tipe " . $tipeLabel]];
        }
        $sumberLabel = $source === 'joblist' ? 'Joblist' : 'Tiket Manager';
        return ['text' => "TIKET GANGGUAN TERBUKA ($total)\nSumber: $sumberLabel\nPilih tipe utk lihat daftarnya:", 'kb' => $kb];
    }
}

if (!function_exists('telegramAdminTiketByType')) {
    /** @return array{text:string, kb:array} */
    function telegramAdminTiketByType($conn, string $pemilikIn, array $pemilikList, string $source, string $tipe): array
    {
        $variants = telegramAdminTiketTipeVariants($tipe);
        $backKb = [[['« Kembali ke tipe', '/tiket']]];

        if ($source === 'joblist') {
            $jc = telegramAdminJoblistConn();
            if (!$jc) {
                return ['text' => "Sumber tiket (Joblist) sedang tidak bisa dihubungi.", 'kb' => $backKb];
            }
            $variantsEsc = array_map(function ($v) use ($jc) {
                return "'" . mysqli_real_escape_string($jc, $v) . "'";
            }, $variants);
            $projEsc = array_map(function ($p) use ($jc) {
                return "'" . mysqli_real_escape_string($jc, $p) . "'";
            }, $pemilikList);
            $res = @mysqli_query($jc, "SELECT id, data, team, status, waktu, project FROM `joblist`
                    WHERE project IN (" . implode(',', $projEsc) . ") AND UPPER(TRIM(tipe)) IN (" . implode(',', $variantsEsc) . ")
                      AND status IN ('BARU','PENDING')
                    ORDER BY id DESC LIMIT 20");
            if (!$res || mysqli_num_rows($res) === 0) {
                return ['text' => "Tidak ada tiket terbuka utk tipe \"$tipe\".", 'kb' => $backKb];
            }
            $lines = ["TIKET GANGGUAN -- " . strtoupper($tipe) . " (Joblist)", ""];
            $kb = [];
            while ($r = mysqli_fetch_assoc($res)) {
                $judulSingkat = mb_substr(trim((string)$r['data']), 0, 60);
                $lines[] = "#" . $r['id'] . " (" . $r['status'] . ") " . tgAdminSan($judulSingkat) . "\n   " . tgAdminSan((string)$r['project']) . " — " . $r['waktu'];
                $kb[] = [['👁 Lihat #' . $r['id'], "/tiket_view " . (int)$r['id']]];
            }
            $kb[] = [['« Kembali ke tipe', '/tiket']];
            return ['text' => implode("\n", $lines), 'kb' => $kb];
        }

        $variantsEsc = array_map(function ($v) use ($conn) {
            return "'" . mysqli_real_escape_string($conn, $v) . "'";
        }, $variants);
        $inClause = implode(',', $variantsEsc);
        $res = @mysqli_query($conn, "SELECT id, judul, area, status, created_at FROM `billing_tiket_manager`
                WHERE pemilik IN ($pemilikIn) AND UPPER(TRIM(tipe)) IN ($inClause)
                  AND UPPER(status) NOT IN ('SELESAI','CLOSED','DONE','BATAL','CANCEL')
                ORDER BY id DESC LIMIT 20");
        if (!$res || mysqli_num_rows($res) === 0) {
            return ['text' => "Tidak ada tiket terbuka utk tipe \"$tipe\".", 'kb' => $backKb];
        }
        $lines = ["TIKET GANGGUAN -- " . strtoupper($tipe), ""];
        $kb = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $lines[] = "#" . $r['id'] . " (" . $r['status'] . ") " . tgAdminSan($r['judul']) . "\n   " . tgAdminSan($r['area']) . " — " . $r['created_at'];
            $kb[] = [['👁 Lihat #' . $r['id'], "/tiket_view " . (int)$r['id']]];
        }
        $kb[] = [['« Kembali ke tipe', '/tiket']];
        return ['text' => implode("\n", $lines), 'kb' => $kb];
    }
}

if (!function_exists('telegramAdminTiketDetail')) {
    /** Detail 1 tiket -- mode tiket_manager: judul, tipe, area, teknisi (join
     *  tabel user, sama spt tiket_manager_provisioning.php), isi lengkap
     *  (kolom detail), laporan teknisi (kolom report) kalau sudah ada. Mode
     *  joblist: field beda (data/team/waktu/project, TIDAK ada judul/teknisi
     *  terpisah -- skema tabel joblist beda dari billing_tiket_manager). */
    function telegramAdminTiketDetail($conn, string $pemilikIn, array $pemilikList, string $source, int $id): string
    {
        if ($source === 'joblist') {
            $jc = telegramAdminJoblistConn();
            if (!$jc) {
                return "Sumber tiket (Joblist) sedang tidak bisa dihubungi.";
            }
            $projEsc = array_map(function ($p) use ($jc) {
                return "'" . mysqli_real_escape_string($jc, $p) . "'";
            }, $pemilikList);
            $res = @mysqli_query($jc, "SELECT * FROM `joblist` WHERE id = " . (int)$id . " AND project IN (" . implode(',', $projEsc) . ") LIMIT 1");
            $r = $res ? mysqli_fetch_assoc($res) : null;
            if (!$r) {
                return "Tiket #$id tidak ditemukan / bukan wewenang Anda.";
            }
            $L = [];
            $L[] = "TIKET #$id -- " . tgAdminSan((string)($r['status'] ?? '-')) . " (Joblist)";
            $L[] = "Tipe    : " . tgAdminSan((string)($r['tipe'] ?? '-'));
            $L[] = "Project : " . tgAdminSan((string)($r['project'] ?? '-'));
            $L[] = "Team    : " . (trim((string)($r['team'] ?? '')) !== '' ? tgAdminSan((string)$r['team']) : '(belum ditugaskan)');
            $L[] = "Waktu   : " . tgAdminSan((string)($r['waktu'] ?? '-'));
            $L[] = "";
            $L[] = "-- Detail --";
            $L[] = tgAdminSan((string)($r['data'] ?? '-'));
            if (trim((string)($r['report'] ?? '')) !== '') {
                $L[] = "";
                $L[] = "-- Laporan --";
                $L[] = tgAdminSan((string)$r['report']);
            }
            return implode("\n", $L);
        }

        $res = @mysqli_query($conn, "SELECT t.*, u.USERNAME AS teknisi_nama FROM `billing_tiket_manager` t
                LEFT JOIN `user` u ON u.id = t.teknisi_user_id
                WHERE t.id = " . (int)$id . " AND t.pemilik IN ($pemilikIn) LIMIT 1");
        $r = $res ? mysqli_fetch_assoc($res) : null;
        if (!$r) {
            return "Tiket #$id tidak ditemukan / bukan wewenang Anda.";
        }
        $L = [];
        $L[] = "TIKET #$id -- " . tgAdminSan((string)($r['status'] ?? '-'));
        $L[] = "Judul   : " . tgAdminSan((string)$r['judul']);
        $L[] = "Tipe    : " . tgAdminSan((string)$r['tipe']);
        $L[] = "Area    : " . tgAdminSan((string)$r['area']);
        $L[] = "Project : " . tgAdminSan((string)($r['project_name'] ?? '-'));
        $L[] = "Teknisi : " . (trim((string)($r['teknisi_nama'] ?? '')) !== '' ? tgAdminSan((string)$r['teknisi_nama']) : '(belum ditugaskan)');
        $L[] = "Dibuat  : " . tgAdminSan((string)$r['created_at']);
        if (!empty($r['done_at'])) {
            $L[] = "Selesai : " . tgAdminSan((string)$r['done_at']);
        }
        $L[] = "";
        $L[] = "-- Detail --";
        $L[] = tgAdminSan((string)($r['detail'] ?? '-'));
        if (trim((string)($r['report'] ?? '')) !== '') {
            $L[] = "";
            $L[] = "-- Laporan Teknisi --";
            $L[] = tgAdminSan((string)$r['report']);
        }
        return implode("\n", $L);
    }
}

if (!function_exists('telegramAdminTiketOpsi')) {
    /**
     * Pilihan TIPE pekerjaan & KENDALA -- SAMA PERSIS dropdown modal "Buat tiket"
     * di tables.php (form #popupForm). Dipakai wizard /buattiket. Index dipakai
     * di callback_data tombol supaya string tetap pendek (batas 64 byte Telegram).
     */
    function telegramAdminTiketOpsi(): array
    {
        return [
            'tipe' => [
                'MAINTENANCE',
                'MIGRASI',
                'DISMANTLE',
                'PROVISIONING',
            ],
            'kendala' => [
                'Tidak ada pembayaran lanjutan',
                'Pindah rumah',
                'Pindah ke provider lain',
                'Tidak Bisa Connect',
                'WiFi Lemot',
                'Sinyal Lemah',
                'Tidak Ada Internet',
                'Sering Putus',
                'Modem Mati',
                'Kabel Lepas / Putus',
                'Lampu LOS / Merah',
                'IP Conflict / Tidak Dapat IP',
                'Lainnya',
            ],
        ];
    }
}

if (!function_exists('telegramAdminBuatTiketNotifWa')) {
    /**
     * Notifikasi grup WA saat tiket baru dibuat -- pola & payload SAMA seperti
     * blok di buat_tiket.php (baca waapi.txt di folder crm/billing). Diam saja
     * kalau konfigurasi belum lengkap / cURL mati.
     */
    function telegramAdminBuatTiketNotifWa(string $project, string $tipe, string $datatiket): void
    {
        $file = __DIR__ . '/../waapi.txt';
        if (!is_file($file) || !function_exists('curl_init')) {
            return;
        }
        $waapi = $namebot = $password = $sender = $grup = '';
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (substr($line, 0, 6) === 'waapi=') $waapi = substr($line, 6);
            if (substr($line, 0, 8) === 'namebot=') $namebot = substr($line, 8);
            if (substr($line, 0, 9) === 'password=') $password = substr($line, 9);
            if (substr($line, 0, 5) === 'grup=') $grup = substr($line, 5);
            if (substr($line, 0, 7) === 'sender=') $sender = substr($line, 7);
        }
        if ($waapi === '' || $namebot === '' || $password === '' || $grup === '') {
            return;
        }
        $text = "*[NOTIF SYSTEM BOT QTS]*\n===================\nTIKET BARU MASUK DI APP\n===================\n\nPROJECT : $project \nTIPE : $tipe\nDATA :\n$datatiket\n\n===================\n";
        $deviceId = trim($sender);
        $url = "$waapi/send/message?session=" . urlencode($namebot);
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['phone' => $grup, 'message' => $text, 'sender' => $sender]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, "$namebot:$password");
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        curl_close($ch);
    }
}

if (!function_exists('telegramAdminBuatTiket')) {
    /**
     * Simpan tiket baru ke billing_tiket_manager -- meniru cabang tiket_manager
     * di buat_tiket.php: data pelanggan diambil dari tabel pelanggan, server
     * target dipilih dari server milik owner (user_id) utk brand/pemilik itu,
     * fallback server mana pun milik owner.
     */
    function telegramAdminBuatTiket($conn, array $botRow, string $pemilikIn, int $ownerId, string $idpel, string $tipe, string $kendala): string
    {
        $idpel = trim($idpel);
        $tipe = strtoupper(trim($tipe));
        $idEsc = mysqli_real_escape_string($conn, $idpel);
        $res = @mysqli_query($conn, "SELECT IDPEL, NAMA, NOWA, ALAMAT, EMAIL, TIKOR, ODP, PEMILIK
                FROM `pelanggan` WHERE IDPEL = '$idEsc' AND PEMILIK IN ($pemilikIn) LIMIT 1");
        $c = $res ? mysqli_fetch_assoc($res) : null;
        if (!$c) {
            return "Pelanggan \"$idpel\" tidak ditemukan / di luar cakupan bot ini. Tiket tidak dibuat.";
        }

        $NAMA   = (string)($c['NAMA'] ?? '');
        $NOWA   = (string)($c['NOWA'] ?? '');
        $ALAMAT = (string)($c['ALAMAT'] ?? '');
        $EMAIL  = (string)($c['EMAIL'] ?? '');
        $TIKOR  = (string)($c['TIKOR'] ?? '');
        $ODP    = (string)($c['ODP'] ?? '');
        $project = (string)($c['PEMILIK'] ?? '');

        $datatiket = "===============\nTiket $tipe dari billing\n===============\nID PELANGGAN :$idpel\nNAMA PELANGGAN :$NAMA\nODP :$ODP\nEMAIL :$EMAIL\nALAMAT :$ALAMAT\nNO WHATSAPP : $NOWA\nKENDALA : $kendala\nTIKOR : $TIKOR";

        $serverRow = null;
        if ($ownerId > 0) {
            $stmtSrv = $conn->prepare("SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? AND (PEMILIK = ? OR BRAND = ?) ORDER BY CASE WHEN PEMILIK = ? THEN 0 ELSE 1 END, id ASC LIMIT 1");
            if ($stmtSrv) {
                $stmtSrv->bind_param('isss', $ownerId, $project, $project, $project);
                $stmtSrv->execute();
                $rs = $stmtSrv->get_result();
                $serverRow = $rs ? $rs->fetch_assoc() : null;
                $stmtSrv->close();
            }
            if (!$serverRow) {
                $stmtAny = $conn->prepare("SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ? ORDER BY id ASC LIMIT 1");
                if ($stmtAny) {
                    $stmtAny->bind_param('i', $ownerId);
                    $stmtAny->execute();
                    $rs = $stmtAny->get_result();
                    $serverRow = $rs ? $rs->fetch_assoc() : null;
                    $stmtAny->close();
                }
            }
        }
        if (!$serverRow) {
            return "Server untuk Tiket Manager tidak ditemukan untuk akun ini. Tiket tidak dibuat.";
        }

        $serverId = (int)$serverRow['id'];
        $pemilik  = (string)($serverRow['PEMILIK'] ?? $project);
        $brand    = (string)($serverRow['BRAND'] ?? '');
        $area     = (string)($serverRow['AREA'] ?? '');
        $projectName = trim($brand . ' - ' . $area);
        if ($projectName === '-' || $projectName === '') {
            $projectName = $pemilik;
        }

        $judul = trim("$tipe - $idpel - $NAMA");
        if ($judul === '') {
            $judul = "$tipe - Tiket Billing";
        }
        $report = '';
        $teknisiId = null;
        $creatorId = $ownerId;

        $stmtIns = $conn->prepare("INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'BARU', ?, ?)");
        if (!$stmtIns) {
            return "Gagal menyiapkan query tiket: " . mysqli_error($conn);
        }
        $stmtIns->bind_param('ssissssssii', $judul, $datatiket, $serverId, $pemilik, $brand, $area, $projectName, $tipe, $report, $teknisiId, $creatorId);
        $ok = $stmtIns->execute();
        $err = $stmtIns->error;
        $stmtIns->close();
        if (!$ok) {
            return "Gagal menyimpan tiket." . ($err !== '' ? (' ' . $err) : '');
        }

        telegramAdminBuatTiketNotifWa($project, $tipe, $datatiket);

        return "Tiket berhasil dibuat.\n\nJudul   : $judul\nTipe    : $tipe\nKendala : $kendala\nPelanggan: $NAMA ($idpel)\nArea    : $area\nProject : $projectName\nStatus  : BARU\n\nCek di menu Tiket Manager atau perintah /tiket.";
    }
}

if (!function_exists('telegramAdminStatistik')) {
    function telegramAdminStatistik($conn, string $pemilikIn, array $onlineSet = [], string $owner = '', string $arg = ''): string
    {
        // Mirror PERSIS widget "Statistik dan Laporan Pembayaran" di dashboard.php:
        //   - SQL Total pelanggan / Invoice terkirim / Sudah bayar / Belum bayar
        //     (transaksi.PENGUNAAN = '<Bulan Tahun>' AND HARGA != '0')
        //   - Lewat jatuh tempo & Nunggak 1 / 2+ dari cache serverlog/<owner>.txt
        //     (expired_ids) + libs/menunggak_payment_lookup.php  (SAMA dgn kartu
        //     "Lewat Jatuh Tempo" / "Nunggak 1 Bulan" / "Nunggak 2 Bulan+")
        //   - Pemasukan hari/minggu/bulan/tahun (transaksi BERHASIL, tgl bayar
        //     di-parse identik $tanggal_bayar_filter_sql dashboard)
        //   - Pengeluaran hari/minggu/bulan/tahun (tabel pengeluaran)
        $rp = function ($n) { return 'Rp ' . number_format((float)$n, 0, ',', '.'); };
        $blnID = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
            'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        /* ---------- periode: SELALU bulan + tahun berjalan (tanpa filter, sama spt default dashboard) ---------- */
        $m = (int)date('n'); $Y = (int)date('Y');
        $periodeStr = $blnID[$m] . ' ' . $Y;                 // == transaksi.PENGUNAAN
        $periodeEsc = mysqli_real_escape_string($conn, $periodeStr);
        $bulan_ini  = sprintf('%02d', $m);
        $tahun_ini  = $Y;
        $ownerEsc   = mysqli_real_escape_string($conn, $owner);

        // tanggal "berjalan" (real) -- SAMA dgn dashboard (pakai date(), bukan periode filter)
        $today           = date('Y-m-d');
        $senin_ini       = date('Y-m-d', strtotime('monday this week'));
        $minggu_ini      = date('Y-m-d', strtotime('sunday this week'));
        $awal_bulan_ini  = date('Y-m-01');
        $akhir_bulan_ini = date('Y-m-t');
        $awal_tahun_ini  = date('Y-01-01');
        $akhir_tahun_ini = date('Y-12-31');

        // ekspresi parse TANGGALBAYAR -- COPY PERSIS dari dashboard.php ($tanggal_bayar_filter_sql)
        $trxTgl = "COALESCE(
            DATE(t.TANGGALBAYAR),
            STR_TO_DATE(t.TANGGALBAYAR, '%Y-%m-%d'),
            STR_TO_DATE(
              CONCAT(
                TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                  SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),
                  'Januari', '01'
                ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12'))
              ),
              '%d %m %Y'
            )
        )";
        $one = function ($sql) use ($conn) {
            $q = @mysqli_query($conn, $sql);
            if (!$q) return 0;
            $r = mysqli_fetch_assoc($q);
            return ($r && array_key_exists('v', $r)) ? $r['v'] : 0;
        };

        // ---- status jaringan dari cache serverlog/<owner>.txt (utk kartu Active Internet / Internet Los / Expired Online / Expired Los -- KLASIFIKASI PERSIS dashboard.php baris 115-137) ----
        $netc = function_exists('telegramAdminNetCache') ? telegramAdminNetCache($owner) : null;
        $hasNetCache  = ($netc !== null);
        $netExpSet    = ($netc && !empty($netc['expired'])) ? $netc['expired'] : [];
        $netLosSet    = ($netc && !empty($netc['los']))     ? $netc['los']     : [];
        $netOffNeSet  = ($netc && !empty($netc['off_ne']))  ? $netc['off_ne']  : [];
        $netActive = 0; $netInetLos = 0; $netExpOnline = 0; $netExpLos = 0; $netExpTotal = 0; $netLosTotal = 0;

        /* ================= RINGKAS PELANGGAN ================= */
        $total = 0; $onlineN = 0; $pra = 0; $pas = 0; $baruBln = 0; $mrr = 0.0;
        $perArea = []; $perBrand = [];
        $ymPeriode = sprintf('%04d-%02d', $Y, $m);
        $qc = @mysqli_query($conn, "SELECT IDPEL, TIPE_BAYAR, TANGGALPASANG, PEMILIK, AREA, HARGA, PAKET FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn)");
        if ($qc) {
            while ($r = mysqli_fetch_assoc($qc)) {
                $total++;
                if (isset($onlineSet[strtolower(trim((string)$r['IDPEL']))])) $onlineN++;
                $tb = strtolower(trim((string)$r['TIPE_BAYAR']));
                if ($tb === 'prabayar') $pra++; elseif ($tb === 'pascabayar') $pas++;
                if (strpos((string)$r['TANGGALPASANG'], $ymPeriode) === 0) $baruBln++;
                $a = trim((string)$r['AREA']); if ($a !== '') $perArea[$a] = ($perArea[$a] ?? 0) + 1;
                $b = trim((string)$r['PEMILIK']); if ($b !== '') $perBrand[$b] = ($perBrand[$b] ?? 0) + 1;
                $hh = telegramAdminHargaOf($r);
                if ($hh !== '-') $mrr += (float)preg_replace('/[^0-9]/', '', $hh);

                if ($hasNetCache) {
                    $idl    = strtolower(trim((string)$r['IDPEL']));
                    $isExp  = isset($netExpSet[$idl]);
                    $isLosR = isset($netLosSet[$idl]);
                    $isOffN = isset($netOffNeSet[$idl]);
                    if (!$isExp && !$isLosR) $netActive++;      // Active Internet / Internet Online
                    if ($isOffN)             $netInetLos++;     // Internet Los
                    if ($isExp && !$isLosR)  $netExpOnline++;   // Expired Online
                    if ($isExp && $isLosR)   $netExpLos++;      // Expired Los
                    if ($isExp)              $netExpTotal++;
                    if ($isLosR)             $netLosTotal++;
                }
            }
        }
        $offN = max(0, $total - $onlineN);
        $rate = $total > 0 ? round($onlineN * 100 / $total) : 0;

        $berhenti = (int)$one("SELECT COUNT(*) v FROM `pelanggan_berhenti`
            WHERE pemilik IN ($pemilikIn) AND MONTH(tanggal_berhenti)='$bulan_ini' AND YEAR(tanggal_berhenti)='$tahun_ini'");

        /* ============ TAGIHAN PERIODE -- SQL PERSIS DASHBOARD (transaksi t JOIN pelanggan p) ============ */
        $joinP = "FROM `transaksi` t INNER JOIN `pelanggan` p ON t.IDPEL = p.IDPEL WHERE p.PEMILIK IN ($pemilikIn) AND t.HARGA != '0' AND t.PENGUNAAN = '$periodeEsc'";
        $invoiceN = (int)$one("SELECT COUNT(*) v $joinP");
        $sudahN   = (int)$one("SELECT COUNT(*) v $joinP AND t.STATUS='BERHASIL'");
        $belumN   = (int)$one("SELECT COUNT(*) v $joinP AND t.STATUS='PENAGIHAN'");
        $sudahRp  = (float)$one("SELECT COALESCE(SUM(t.HARGA),0) v $joinP AND t.STATUS='BERHASIL'");
        $belumRp  = (float)$one("SELECT COALESCE(SUM(t.HARGA),0) v $joinP AND t.STATUS='PENAGIHAN'");

        // bulan lalu (info tambahan)
        $lm = $m - 1; $lY = $Y; if ($lm < 1) { $lm = 12; $lY--; }
        $periodeLalu = mysqli_real_escape_string($conn, $blnID[$lm] . ' ' . $lY);
        $joinPL = "FROM `transaksi` t INNER JOIN `pelanggan` p ON t.IDPEL = p.IDPEL WHERE p.PEMILIK IN ($pemilikIn) AND t.HARGA != '0' AND t.PENGUNAAN = '$periodeLalu'";
        $lmSudah = (int)$one("SELECT COUNT(*) v $joinPL AND t.STATUS='BERHASIL'");
        $lmBelum = (int)$one("SELECT COUNT(*) v $joinPL AND t.STATUS='PENAGIHAN'");

        /* ====== LEWAT JATUH TEMPO / NUNGGAK -- sumber SAMA dashboard: cache expired_ids ====== */
        $jtTotal = null; $nunggak1 = 0; $nunggak2 = 0; $jtNote = '';
        $netc = function_exists('telegramAdminNetCache') ? telegramAdminNetCache($owner) : null;
        if ($netc === null) {
            $jtNote = 'cache jaringan (serverlog/' . $owner . '.txt) belum tersedia — pastikan cron scan router jalan';
        } elseif (empty($netc['expired'])) {
            $jtTotal = 0;
        } else {
            $expIds = array_keys($netc['expired']);
            $inList = "'" . implode("','", array_map(function ($x) use ($conn) { return strtolower(mysqli_real_escape_string($conn, (string)$x)); }, $expIds)) . "'";
            $rowsJt = [];
            $rq = @mysqli_query($conn, "SELECT IDPEL, NAMA, PAKET, HARGA, TIPE_BAYAR, TIPE_TEMPO, TEMPO, TANGGALPASANG, TANGGAL_MONTHVERSARY, PEMILIK, AREA
                FROM `pelanggan` WHERE PEMILIK IN ($pemilikIn) AND LOWER(IDPEL) IN ($inList)");
            if ($rq) {
                while ($r = mysqli_fetch_assoc($rq)) {
                    if (telegramAdminHargaOf($r) === '-') continue;  // FASUM / harga 0 -> tidak dihitung (spt dashboard)
                    $rowsJt[] = $r;
                }
            }
            $jtTotal = count($rowsJt);

            // split Nunggak 1 vs 2+ : hitung siklus (bulanan) tak-terbayar sejak
            // pembayaran terakhir (atau tgl pasang bila belum pernah bayar).
            $payIndex = [];
            $mnqLib = __DIR__ . '/../libs/menunggak_payment_lookup.php';
            if (is_file($mnqLib)) {
                require_once $mnqLib;
                if (function_exists('mnq_build_payment_index')) {
                    $trxNoAlias = str_replace('t.TANGGALBAYAR', 'TANGGALBAYAR', $trxTgl);
                    $payIndex = mnq_build_payment_index($conn, array_map(function ($x) { return (string)$x['IDPEL']; }, $rowsJt), $trxNoAlias);
                }
            }
            $todayTs = strtotime($today);
            foreach ($rowsJt as $r) {
                $idp = (string)$r['IDPEL'];
                $anchor = '';
                if ($payIndex && function_exists('mnq_get_last_paid')) {
                    $lp = mnq_get_last_paid($payIndex, $idp);
                    $anchor = trim((string)($lp['last_paid'] ?? ''));
                }
                if ($anchor === '' || strtotime($anchor) === false) {
                    $anchor = trim((string)($r['TANGGALPASANG'] ?? ''));
                }
                $bulanNunggak = 1;
                if ($anchor !== '' && strtotime($anchor) !== false) {
                    $cursor = strtotime($anchor); $unpaid = 0; $safety = 0;
                    while ($cursor <= $todayTs && $safety < 480) {
                        $safety++;
                        $next = strtotime('+1 month', $cursor);
                        $paid = ($payIndex && function_exists('mnq_has_payment_in_period'))
                            ? mnq_has_payment_in_period($payIndex, $idp, date('Y-m-d', $cursor), date('Y-m-d', $next))
                            : false;
                        if ($paid) { $unpaid = 0; } else { $unpaid++; }
                        $cursor = $next;
                    }
                    // $unpaid termasuk siklus berjalan; siklus yg SUDAH lewat = unpaid-1, minimal 1
                    $bulanNunggak = max(1, $unpaid > 1 ? $unpaid - 1 : 1);
                }
                if ($bulanNunggak >= 2) $nunggak2++; else $nunggak1++;
            }
        }

        /* ============ PEMASUKAN (transaksi BERHASIL) -- SQL PERSIS DASHBOARD ============ */
        $inQ = function ($cond) use ($one, $trxTgl, $pemilikIn) {
            return (float)$one("SELECT COALESCE(SUM(t.HARGA),0) v
                FROM `transaksi` t INNER JOIN `pelanggan` p ON t.IDPEL = p.IDPEL
                WHERE t.STATUS='BERHASIL' AND p.PEMILIK IN ($pemilikIn) AND $cond");
        };
        $inHari   = $inQ("$trxTgl = '$today'");
        $inMinggu = $inQ("$trxTgl >= '$senin_ini' AND $trxTgl <= '$minggu_ini'");
        $inBulan  = $inQ("$trxTgl >= '$awal_bulan_ini' AND $trxTgl <= '$akhir_bulan_ini'");
        $inTahun  = $inQ("$trxTgl >= '$awal_tahun_ini' AND $trxTgl <= '$akhir_tahun_ini'");

        /* ============ PENGELUARAN -- SQL PERSIS DASHBOARD (tabel pengeluaran, pemilik = owner) ============ */
        $outHari   = (float)$one("SELECT COALESCE(SUM(jumlah),0) v FROM `pengeluaran` WHERE pemilik='$ownerEsc' AND DATE(tanggal)='$today'");
        $outMinggu = (float)$one("SELECT COALESCE(SUM(jumlah),0) v FROM `pengeluaran` WHERE pemilik='$ownerEsc' AND DATE(tanggal) >= '$senin_ini' AND DATE(tanggal) <= '$minggu_ini'");
        $outBulan  = (float)$one("SELECT COALESCE(SUM(jumlah),0) v FROM `pengeluaran` WHERE pemilik='$ownerEsc' AND MONTH(tanggal)='$bulan_ini' AND YEAR(tanggal)='$tahun_ini'");
        $outTahun  = (float)$one("SELECT COALESCE(SUM(jumlah),0) v FROM `pengeluaran` WHERE pemilik='$ownerEsc' AND YEAR(tanggal)='$tahun_ini'");

        /* ============ INFRASTRUKTUR -- SQL PERSIS kartu "Infrastruktur" dashboard ============ */
        $infra = ['routers' => 0, 'area' => 0, 'olt' => 0, 'odp' => 0, 'hompas' => 0];
        $qi = @mysqli_query($conn, "SELECT
            (SELECT COUNT(*) FROM `server` WHERE `pemilik` IN ($pemilikIn)) rt,
            (SELECT COUNT(DISTINCT AREA) FROM `server` WHERE `pemilik` IN ($pemilikIn)) ar,
            (SELECT COUNT(DISTINCT AREA) FROM `olt` WHERE `pemilik` IN ($pemilikIn)) ol,
            (SELECT COUNT(*) FROM `odp` WHERE `pemilik` IN ($pemilikIn)) od,
            (SELECT COALESCE(SUM(CASE splitter
                WHEN '1:2' THEN 2 WHEN '1:4' THEN 4 WHEN '1:8' THEN 8
                WHEN '1:16' THEN 16 WHEN '1:32' THEN 32 ELSE 0 END), 0)
             FROM `odp` WHERE `pemilik` IN ($pemilikIn) AND Hirarki='ODP') hp");
        if ($qi && ($r = mysqli_fetch_assoc($qi))) {
            $infra = ['routers' => (int)$r['rt'], 'area' => (int)$r['ar'], 'olt' => (int)$r['ol'], 'odp' => (int)$r['od'], 'hompas' => (int)$r['hp']];
        }

        /* ============ SLA Server 30 hari -- rata-rata per-server (server_sla_logs) ============ */
        $slaPct = null;
        $slaAgg = @mysqli_query($conn, "SELECT
                SUM(CASE WHEN l.is_online=1 THEN 1 ELSE 0 END) up_c, COUNT(l.id) tot_c
            FROM `server` s LEFT JOIN `server_sla_logs` l
                ON l.server_id = s.id AND l.checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE s.`pemilik` IN ($pemilikIn) GROUP BY s.id");
        if ($slaAgg) {
            $slaSum = 0.0; $slaCnt = 0;
            while ($r = mysqli_fetch_assoc($slaAgg)) {
                $slaCnt++;
                $tc = (int)$r['tot_c'];
                $slaSum += $tc > 0 ? ((int)$r['up_c'] * 100.0 / $tc) : 0.0;
            }
            if ($slaCnt > 0) $slaPct = $slaSum / $slaCnt;
        }

        /* ============ TIKET per tipe -- SAMA getdata/count_tiket.php (status BARU/PENDING) ============ */
        $tk = ['INST' => 0, 'DST' => 0, 'MT' => 0, 'MGS' => 0];
        $tq = @mysqli_query($conn, "SELECT UPPER(TRIM(tipe)) tp, COUNT(*) c FROM `billing_tiket_manager`
            WHERE pemilik IN ($pemilikIn) AND UPPER(status) IN ('BARU','PENDING') GROUP BY UPPER(TRIM(tipe))");
        if ($tq) {
            $tmap = ['INSTALLASI' => 'INST', 'INSTALASI' => 'INST', 'DISMANTLE' => 'DST', 'MAINTENANCE' => 'MT', 'MIGRASI' => 'MGS'];
            while ($r = mysqli_fetch_assoc($tq)) {
                $k = $tmap[$r['tp']] ?? null;
                if ($k) $tk[$k] += (int)$r['c'];
            }
        }
        $tiketN = (int)$one("SELECT COUNT(*) v FROM `billing_tiket_manager`
            WHERE pemilik IN ($pemilikIn) AND UPPER(status) NOT IN ('SELESAI','CLOSED','DONE','BATAL','CANCEL')");

        /* ============ Instalasi bulan ini -- SQL PERSIS dashboard ============ */
        $instalasiBln = (int)$one("SELECT COUNT(*) v FROM `pelanggan`
            WHERE PEMILIK IN ($pemilikIn) AND MONTH(TANGGALPASANG)='$bulan_ini' AND YEAR(TANGGALPASANG)='$tahun_ini'");

        /* ============ PAKET & ESTIMASI PENDAPATAN -- dari cache profil (dashboard baris 3378-3395) ============ */
        $estPppoe = 0.0; $estHotspot = 0.0; $paketPppoeN = 0; $paketHotspotN = 0;
        if ($netc) {
            $hpP = $netc['paket_harga_pppoe'] ?? [];
            foreach (($netc['pppoe_profiles'] ?? []) as $prof => $cnt) {
                if (strtoupper((string)$prof) === 'EXPIRED') continue;
                $h = (float)preg_replace('/[^0-9.]/', '', (string)($hpP[$prof]['harga'] ?? 0));
                $estPppoe += $h * (int)$cnt;
                $paketPppoeN++;
            }
            $hpH = $netc['paket_harga_hotspot'] ?? [];
            foreach (($netc['hotspot_profiles'] ?? []) as $prof => $cnt) {
                $h = (float)preg_replace('/[^0-9.]/', '', (string)($hpH[$prof]['harga'] ?? 0));
                $estHotspot += $h * (int)$cnt;
                $paketHotspotN++;
            }
        }
        $estIncome = $estPppoe + $estHotspot;

        /* ============ STATUS INTERNET -- angka kartu ATAS dashboard (cache Total_*) ============ */
        $ct = $netc['totals'] ?? null;
        $cardTotalUsers = ($ct && (int)$ct['pelanggan'] > 0) ? (int)$ct['pelanggan'] : $total;
        $cardInetOnline = $ct ? (int)$ct['online_paket']    : $netActive;
        $cardInetLos    = $ct ? (int)$ct['los_internet']    : $netInetLos;
        $cardExpOnline  = $ct ? (int)$ct['online_expired']  : $netExpOnline;
        $cardExpLos     = $ct ? (int)$ct['expired_offline'] : $netExpLos;

        /* ============ RENDER: urut & label MENGIKUTI kartu dashboard (atas + widget) ============ */
        $pctTxt = ($slaPct === null) ? '-' : (number_format($slaPct, 2, '.', '') . '%');
        $o = [];
        $o[] = "STATISTIK BILLING (mengikuti dashboard)";
        $o[] = "Per " . date('d M Y H:i');
        $o[] = "Periode: $periodeStr";
        if (!empty($netc['updated_at'])) $o[] = "Data jaringan: " . $netc['updated_at'];

        $o[] = "";
        $o[] = "━━ INFRASTRUKTUR ━━";
        $o[] = "Routers / Area / OLT / ODP / Hompas : " . $infra['routers'] . " / " . $infra['area'] . " / " . $infra['olt'] . " / " . $infra['odp'] . " / " . $infra['hompas'];
        $o[] = "SLA Server (30 hari) : $pctTxt";

        $o[] = "";
        $o[] = "━━ TIKET (status BARU/PENDING) ━━";
        $o[] = "INST / DST / MT / MGS : " . $tk['INST'] . " / " . $tk['DST'] . " / " . $tk['MT'] . " / " . $tk['MGS'];
        $o[] = "Tiket gangguan terbuka : $tiketN";

        $o[] = "";
        $o[] = "━━ PELANGGAN ━━";
        $o[] = "Total Users            : $cardTotalUsers";
        $o[] = "Instalasi Bulan Ini   : $instalasiBln";
        $o[] = "Berhenti Bulan Ini    : $berhenti";
        $o[] = "Prabayar / Pascabayar : $pra / $pas";
        $o[] = "Baru periode ini      : $baruBln";

        $o[] = "";
        $o[] = "━━ STATUS INTERNET (kartu dashboard) ━━";
        if (!$hasNetCache) {
            $o[] = "(cache serverlog/$owner.txt belum ada — jalankan cron scan router)";
        } else {
            $o[] = "Internet Online : $cardInetOnline";
            $o[] = "Internet Los    : $cardInetLos";
            $o[] = "Expired Online  : $cardExpOnline";
            $o[] = "Expired Los     : $cardExpLos";
            $o[] = "(dari expired_ids/los_ids: Expired $netExpTotal, Los $netLosTotal)";
        }

        $o[] = "";
        $o[] = "━━ PAKET & ESTIMASI PENDAPATAN ━━";
        if (!$hasNetCache) {
            $o[] = "(butuh cache serverlog/$owner.txt)";
        } else {
            $o[] = "Total Paket PPPoE   : $paketPppoeN";
            $o[] = "Total Paket Hotspot : $paketHotspotN";
            $o[] = "Estimate Income     : " . $rp($estIncome);
        }

        $o[] = "";
        $o[] = "━━ TAGIHAN PERIODE: $periodeStr ━━";
        $o[] = "Invoice Terkirim  : $invoiceN";
        $o[] = "Sudah Bayar       : $sudahN  (" . $rp($sudahRp) . ")";
        $o[] = "Belum Bayar       : $belumN  (" . $rp($belumRp) . ")";
        if ($jtTotal === null) {
            $o[] = "Lewat Jatuh Tempo : -  ($jtNote)";
            $o[] = "  Nunggak 1 Bulan  : -";
            $o[] = "  Nunggak 2 Bulan+ : -";
        } else {
            $o[] = "Lewat Jatuh Tempo : $jtTotal";
            $o[] = "  Nunggak 1 Bulan  : $nunggak1";
            $o[] = "  Nunggak 2 Bulan+ : $nunggak2";
        }
        $o[] = "Bulan lalu ($blnID[$lm] $lY) : sudah $lmSudah / belum $lmBelum";

        $o[] = "";
        $o[] = "━━ PEMASUKAN (transaksi BERHASIL) ━━";
        $o[] = "Hari Ini   : " . $rp($inHari);
        $o[] = "Minggu Ini : " . $rp($inMinggu);
        $o[] = "Bulan Ini  : " . $rp($inBulan);
        $o[] = "Tahun Ini  : " . $rp($inTahun);

        $o[] = "";
        $o[] = "━━ PENGELUARAN ━━";
        $o[] = "Hari Ini   : " . $rp($outHari);
        $o[] = "Minggu Ini : " . $rp($outMinggu);
        $o[] = "Bulan Ini  : " . $rp($outBulan);
        $o[] = "Tahun Ini  : " . $rp($outTahun);

        $o[] = "";
        $o[] = "━━ SELISIH (Bulan Ini) ━━";
        $o[] = "Pemasukan - Pengeluaran : " . $rp($inBulan - $outBulan);

        $o[] = "";
        $o[] = "━━ INFO TAMBAHAN ━━";
        $o[] = "Potensi tagihan bulanan : " . $rp($mrr);
        $o[] = "Online (cache PPPoE)    : $onlineN / $offN  ($rate% online)";
        if (count($perBrand) > 1) {
            arsort($perBrand);
            $o[] = "Per brand : " . implode('  ', array_map(function ($k, $v) { return "$k=$v"; }, array_keys($perBrand), $perBrand));
        }

        $o[] = "";
        $o[] = "Sumber: kartu atas dashboard + widget \"Statistik dan Laporan Pembayaran\".";
        return implode("\n", $o);
    }
}
