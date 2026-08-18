<?php
// livechat_ai_helper.php - Pengaturan & fungsi AI bot khusus Live Chat (crm/chat/),
// TERPISAH dari AI per-bot WhatsApp di wabot.php, tapi reuse mesin panggilan AI yang
// sama persis dari crm/webhook/ai_provider_helper.php (provider/apikey/model per
// tenant, bukan per WA-bot). Per-tenant (kolom `pemilik`), dipakai bareng oleh:
// - crm/billing/system_setting.php (form pengaturan)
// - crm/chat/index.php + crm/chat/toggle_ai.php (toggle on/off langsung di chat)
// - crm/billing/api/tagihanwifiku/chat_send.php (trigger auto-reply saat pelanggan kirim pesan)

// Resolve kunci scope AI Live Chat dari peran login: akun ADMIN (master admin
// pemilik web ini) pakai kunci literal 'admin' -- SAMA dgn nilai PEMILIK yang
// tersimpan di tabel `pelanggan` untuk pelanggan miliknya sendiri -- bukan
// username login mereka (mis. FIBERQ). Tenant lain (reseller/mitra) pakai
// username mereka sendiri, karena itu memang PEMILIK asli pelanggan mereka.
// HARUS konsisten dgn cara crm/chat/index.php resolve $currentName (livechat.php/
// sidebar.php/livechatadmin.php kirim admin=admin utk role ADMIN, admin=$ceknama
// utk lainnya) supaya toggle di System Setting & di halaman Live Chat, serta
// pengecekan saat chat_send.php terima pesan, selalu mengacu ke baris
// livechat_ai_settings yang SAMA.
function livechatAiScopeKey($akses, $ceknama)
{
    return ($akses === 'ADMIN') ? 'admin' : (string)$ceknama;
}

function livechatAiEnsureTable($conn)
{
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS livechat_ai_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pemilik VARCHAR(100) NOT NULL UNIQUE,
        ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
        ai_provider VARCHAR(30) NOT NULL DEFAULT 'cerebras',
        ai_api_key VARCHAR(255) NOT NULL DEFAULT '',
        ai_base_url VARCHAR(255) NOT NULL DEFAULT '',
        ai_models TEXT NULL,
        ai_system_prompt TEXT NULL,
        menu_text TEXT NULL,
        custom_keywords TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    // Self-heal utk instalasi lama yg tabelnya sudah ada sebelum menu_text/
    // custom_keywords ditambahkan -- pola sama seperti wabot.php (ALTER TABLE
    // dibungkus @ supaya error "duplicate column" diam-diam diabaikan).
    @mysqli_query($conn, "ALTER TABLE livechat_ai_settings ADD COLUMN menu_text TEXT NULL");
    @mysqli_query($conn, "ALTER TABLE livechat_ai_settings ADD COLUMN custom_keywords TEXT NULL");

    // Log aktivitas AI -- supaya kegagalan (mis. rate limit provider) kelihatan
    // langsung di halaman pengaturan, tanpa perlu akses error_log server.
    @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS livechat_ai_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pemilik VARCHAR(100) NOT NULL,
        customer_identity VARCHAR(191) NOT NULL,
        message_in TEXT NULL,
        response_type VARCHAR(30) NOT NULL DEFAULT 'ai_chat',
        status VARCHAR(10) NOT NULL DEFAULT 'ok',
        detail TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pemilik_time (pemilik, created_at)
    )");
}

// Catat satu aktivitas AI (berhasil ATAU gagal) supaya bisa ditelusuri dari UI
// (card "Log AI" di livechat_setting.php), bukan cuma dari error_log server.
// $type: 'ai_chat' | 'cek_data' | 'gangguan' | 'kode_bayar' | 'menu' | 'keyword'.
function livechatAiLog($conn, $pemilik, $customerIdentity, $messageIn, $type, $status, $detail = '')
{
    livechatAiEnsureTable($conn);
    $stmt = mysqli_prepare($conn, "INSERT INTO livechat_ai_log (pemilik, customer_identity, message_in, response_type, status, detail) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $messageIn = mb_substr((string)$messageIn, 0, 1000);
    $detail = mb_substr((string)$detail, 0, 2000);
    mysqli_stmt_bind_param($stmt, 'ssssss', $pemilik, $customerIdentity, $messageIn, $type, $status, $detail);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function livechatAiGetRecentLogs($conn, $pemilik, $limit = 50)
{
    livechatAiEnsureTable($conn);
    $limit = max(1, min(200, (int)$limit));
    $stmt = mysqli_prepare($conn, "SELECT customer_identity, message_in, response_type, status, detail, created_at FROM livechat_ai_log WHERE pemilik = ? ORDER BY id DESC LIMIT $limit");
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function livechatAiDefaults()
{
    return [
        'ai_enabled' => 0,
        'ai_provider' => 'cerebras',
        'ai_api_key' => '',
        'ai_base_url' => '',
        'ai_models' => '',
        'ai_system_prompt' => '',
        'menu_text' => '',
        'custom_keywords' => '',
    ];
}

function livechatAiGetSettings($conn, $pemilik)
{
    livechatAiEnsureTable($conn);
    $defaults = livechatAiDefaults();

    $stmt = mysqli_prepare($conn, "SELECT ai_enabled, ai_provider, ai_api_key, ai_base_url, ai_models, ai_system_prompt, menu_text, custom_keywords FROM livechat_ai_settings WHERE pemilik = ? LIMIT 1");
    if (!$stmt) {
        return $defaults;
    }
    mysqli_stmt_bind_param($stmt, 's', $pemilik);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return $row ?: $defaults;
}

// Parse "keyword => jawaban" satu baris per pasangan (format sama gayanya dgn
// textarea "Daftar Model" yg sudah ada) jadi array [['keyword'=>..,'response'=>..]].
function livechatAiParseKeywordPairs($raw)
{
    $pairs = [];
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=>') === false) {
            continue;
        }
        [$kw, $resp] = explode('=>', $line, 2);
        $kw = trim($kw);
        $resp = trim($resp);
        if ($kw === '' || $resp === '') {
            continue;
        }
        $pairs[] = ['keyword' => $kw, 'response' => $resp];
    }
    return $pairs;
}

// Cari keyword custom yang cocok (substring, case-insensitive -- sama pola dgn
// findKeywordResponse() di WA bot) di dalam pesan pelanggan.
function livechatAiFindKeywordResponse($settings, $message)
{
    $pairs = livechatAiParseKeywordPairs($settings['custom_keywords'] ?? '');
    if (empty($pairs)) {
        return null;
    }
    $messageLower = mb_strtolower(trim((string)$message));
    if ($messageLower === '') {
        return null;
    }
    foreach ($pairs as $pair) {
        if (mb_strpos($messageLower, mb_strtolower($pair['keyword'])) !== false) {
            return $pair['response'];
        }
    }
    return null;
}

// Dipakai oleh toggle di crm/chat/ -- cuma ubah on/off, tidak sentuh pengaturan lain.
function livechatAiSetEnabled($conn, $pemilik, $enabled)
{
    livechatAiEnsureTable($conn);
    $enabledInt = $enabled ? 1 : 0;
    $stmt = mysqli_prepare($conn, "INSERT INTO livechat_ai_settings (pemilik, ai_enabled) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE ai_enabled = VALUES(ai_enabled)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'si', $pemilik, $enabledInt);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($ok && $enabled) {
        // Trigger sinkron di chat_send.php ternyata tidak selalu jalan di server
        // ini (dilaporkan user) -- cron polling DIBANGUN ULANG sbg mekanisme utama,
        // otomatis didaftarkan begitu ada tenant manapun menyalakan AI Bot.
        // Idempoten, aman dipanggil berkali-kali.
        livechatAiEnsureCronRegistered();
    }

    return $ok;
}

function livechatAiIsCronRegistered()
{
    if (!function_exists('exec')) {
        return false;
    }
    exec('crontab -l 2>&1', $lines, $ret);
    foreach ($lines as $line) {
        if (strpos($line, 'livechat_ai_poll_cron') !== false) {
            return true;
        }
    }
    return false;
}

// Daftarkan cron polling global (SEKALI untuk semua tenant, bukan per-tenant)
// ke crontab server kalau belum ada. Dipanggil otomatis dari livechatAiSetEnabled()
// saat toggle dinyalakan, dan bisa juga dipanggil manual (tombol di halaman
// Pengaturan Live Chat) sbg fallback kalau exec() sempat gagal saat auto-register.
function livechatAiEnsureCronRegistered()
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'Fungsi exec() dinonaktifkan di server (disable_functions) - cron tidak bisa didaftarkan otomatis. Minta admin hosting mengaktifkan exec(), atau daftarkan manual di crontab server.'];
    }

    $configFile = __DIR__ . '/config.json';
    $config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
    $domain = trim((string)($config['domain'] ?? ''));
    if ($domain === '') {
        return ['ok' => false, 'message' => 'domain belum diatur di config.json, tidak bisa membangun URL cron.'];
    }

    $cronUrl = 'https://' . $domain . '/crm/billing/notifbot/notifphp/livechat_ai_poll_cron.php';
    $marker = 'livechat_ai_poll_cron';
    $cronLine = '* * * * * curl -s ' . escapeshellarg($cronUrl) . ' >/dev/null 2>&1 # ' . $marker;

    exec('crontab -l 2>&1', $lines, $ret);
    $text = implode("\n", $lines);
    $listOk = ($ret === 0) || (stripos($text, 'no crontab') !== false);
    if (!$listOk) {
        return ['ok' => false, 'message' => 'Gagal membaca crontab: ' . $text];
    }

    $currentLines = array_values(array_filter(array_map('rtrim', $lines), function ($l) {
        return $l !== '' && stripos($l, 'no crontab') === false;
    }));

    foreach ($currentLines as $line) {
        if (strpos($line, $marker) !== false) {
            return ['ok' => true, 'message' => 'Cron sudah terdaftar.'];
        }
    }

    $currentLines[] = $cronLine;
    $tmpFile = tempnam(sys_get_temp_dir(), 'cron_');
    file_put_contents($tmpFile, implode("\n", $currentLines) . "\n");
    exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $installLines, $installRet);
    @unlink($tmpFile);

    if ($installRet !== 0) {
        return ['ok' => false, 'message' => 'Gagal menyimpan crontab: ' . implode("\n", $installLines)];
    }
    return ['ok' => true, 'message' => 'Cron berhasil didaftarkan (jalan tiap 1 menit).'];
}

// Hapus entry crontab (kalau admin mau matikan cron secara manual).
function livechatAiRemoveCronRegistration()
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'message' => 'Fungsi exec() dinonaktifkan di server, tidak bisa hapus otomatis. Hapus manual baris yang mengandung "livechat_ai_poll_cron" di crontab server.'];
    }

    $marker = 'livechat_ai_poll_cron';
    exec('crontab -l 2>&1', $lines, $ret);
    $text = implode("\n", $lines);
    $listOk = ($ret === 0) || (stripos($text, 'no crontab') !== false);
    if (!$listOk) {
        return ['ok' => false, 'message' => 'Gagal membaca crontab: ' . $text];
    }

    $currentLines = array_values(array_filter(array_map('rtrim', $lines), function ($l) use ($marker) {
        return $l !== '' && stripos($l, 'no crontab') === false && strpos($l, $marker) === false;
    }));

    $tmpFile = tempnam(sys_get_temp_dir(), 'cron_');
    file_put_contents($tmpFile, implode("\n", $currentLines) . ($currentLines ? "\n" : ''));
    exec('crontab ' . escapeshellarg($tmpFile) . ' 2>&1', $installLines, $installRet);
    @unlink($tmpFile);

    if ($installRet !== 0) {
        return ['ok' => false, 'message' => 'Gagal menyimpan crontab: ' . implode("\n", $installLines)];
    }
    return ['ok' => true, 'message' => 'Cron polling berhasil dihapus.'];
}

// Dipakai oleh form lengkap di System Setting.
function livechatAiSaveSettings($conn, $pemilik, array $data)
{
    livechatAiEnsureTable($conn);

    $catalog = aiProviderCatalog();
    $provider = strtolower(trim((string)($data['ai_provider'] ?? 'cerebras')));
    if (!array_key_exists($provider, $catalog)) {
        $provider = 'cerebras';
    }
    $apiKey = trim((string)($data['ai_api_key'] ?? ''));
    $baseUrl = trim((string)($data['ai_base_url'] ?? ''));

    $modelsRaw = (string)($data['ai_models'] ?? '');
    $modelsArr = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $modelsRaw)), function ($m) {
        return $m !== '';
    }));
    $modelsJson = json_encode($modelsArr, JSON_UNESCAPED_UNICODE);

    $systemPrompt = trim((string)($data['ai_system_prompt'] ?? ''));

    // SENGAJA tidak menyentuh ai_enabled di sini -- itu tanggung jawab tunggal toggle
    // switch (livechatAiSetEnabled()). Form ini (provider/apikey/model/prompt) dan
    // toggle disubmit lewat request AJAX TERPISAH; kalau ai_enabled ikut di-INSERT
    // 0/default di sini, tiap kali admin simpan pengaturan provider, status ON dari
    // toggle bisa balik ke OFF tanpa sengaja. Baris pemilik dijamin sudah ada duluan
    // dari default INSERT ai_enabled=0 kalau memang belum pernah toggle sama sekali.
    $stmt = mysqli_prepare($conn, "INSERT INTO livechat_ai_settings (pemilik, ai_provider, ai_api_key, ai_base_url, ai_models, ai_system_prompt)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE ai_provider=VALUES(ai_provider), ai_api_key=VALUES(ai_api_key), ai_base_url=VALUES(ai_base_url), ai_models=VALUES(ai_models), ai_system_prompt=VALUES(ai_system_prompt)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ssssss', $pemilik, $provider, $apiKey, $baseUrl, $modelsJson, $systemPrompt);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

// Dipakai oleh form "Menu & Jawaban Kustom" -- TERPISAH dari form Provider AI di
// atas (form/action AJAX beda) supaya tiap card cuma mengurus bagiannya sendiri,
// sama seperti ai_enabled sengaja dipisah dari livechatAiSaveSettings().
function livechatAiSaveMenuSettings($conn, $pemilik, array $data)
{
    livechatAiEnsureTable($conn);

    $menuText = trim((string)($data['menu_text'] ?? ''));
    $customKeywords = trim((string)($data['custom_keywords'] ?? ''));

    $stmt = mysqli_prepare($conn, "INSERT INTO livechat_ai_settings (pemilik, menu_text, custom_keywords)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE menu_text=VALUES(menu_text), custom_keywords=VALUES(custom_keywords)");
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'sss', $pemilik, $menuText, $customKeywords);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

// Dipanggil dari chat_send.php setelah pesan pelanggan berhasil disimpan ke tabel
// `messages`. Kalau AI aktif utk tenant ($pemilik) ini, panggil AI lalu simpan
// balasannya sbg pesan dari admin (sender_id=$pemilik), supaya otomatis muncul di
// thread yang sama -- baik di widget chat pelanggan maupun di crm/chat/ sisi admin.
// Return: teks balasan AI kalau terkirim, null kalau AI nonaktif/gagal (silent, tidak
// menggagalkan pengiriman pesan pelanggan yang jadi tujuan utama endpoint ini).
function livechatAiMaybeReply($conn, $pemilik, $senderIdentity, $customerName, $userMessage)
{
    $settings = livechatAiGetSettings($conn, $pemilik);
    if (empty($settings['ai_enabled'])) {
        return null;
    }

    $userMessage = trim((string)$userMessage);
    if ($userMessage === '') {
        return null;
    }

    require_once __DIR__ . '/../webhook/ai_provider_helper.php';

    $systemPrompt = trim((string)($settings['ai_system_prompt'] ?? ''));
    if ($systemPrompt === '') {
        $namaTampil = trim((string)$customerName) !== '' ? trim((string)$customerName) : 'pelanggan';
        $systemPrompt = "Kamu adalah asisten customer service yang ramah dan membantu untuk penyedia layanan internet. "
            . "Sedang mengobrol dengan pelanggan bernama $namaTampil melalui Live Chat. "
            . "Jawab singkat, jelas, sopan, dan dalam Bahasa Indonesia. "
            . "Kalau pertanyaan butuh data akun/tagihan spesifik yang tidak kamu ketahui, sarankan pelanggan menunggu admin manusia.";
    }

    $reply = aiChatComplete($settings, $systemPrompt, $userMessage);
    if ($reply === '' || strpos(trim($reply), '❌') === 0) {
        // Gagal diam-diam sebelumnya (customer/admin sama sekali tidak tahu kenapa bot
        // tidak merespon). Catat ke error_log server DAN ke tabel log (kelihatan
        // langsung di card "Log AI" pada halaman pengaturan, tanpa perlu akses server).
        error_log('[livechat_ai] Gagal balas pelanggan ' . $pemilik . '/' . $senderIdentity . ': ' . $reply);
        livechatAiLog($conn, $pemilik, $senderIdentity, $userMessage, 'ai_chat', 'error', $reply);
        return null;
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read) VALUES (?, ?, ?, NOW(), 0)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sss', $pemilik, $senderIdentity, $reply);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    livechatAiLog($conn, $pemilik, $senderIdentity, $userMessage, 'ai_chat', 'ok', $reply);

    return $reply;
}

// ============================================================================
// AKSI KEYWORD/MENU (cek data pelanggan, tiket gangguan, kode bayar) -- pola
// SAMA seperti crm/webhook/botrespon.php: dicek dgn keyword/regex matching
// SEBELUM AI chat (bukan AI function-calling), dan dicek dulu di
// livechatAiHandleKeywordAction() sebelum livechatAiMaybeReply() dipanggil di
// chat_send.php. Bedanya dgn WA bot: di sini pelanggan SUDAH terautentikasi
// lewat token Bearer (session TagihanWifiku), jadi "cek data" & "tiket
// gangguan"/"kode bayar" reuse endpoint API mobile yang sudah ada
// (complaint_create.php/payment_methods.php/payment_create.php) via panggilan
// HTTP loopback memakai token yang sama -- bukan menduplikasi ulang logic
// gateway pembayaran WA bot yang lebih kompleks dan berbasis nomor HP.
// ============================================================================

// Panggil endpoint API mobile TagihanWifiku sendiri (loopback HTTP), pakai
// Bearer token yang sama yang sudah divalidasi chat_send.php/dkk.
function livechatAiCallInternalApi($domain, $path, $token, $method = 'GET', $bodyArr = null)
{
    $url = 'https://' . $domain . '/crm/billing/api/tagihanwifiku/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($bodyArr !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyArr, JSON_UNESCAPED_UNICODE));
            $headers[] = 'Content-Type: application/json';
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return ['success' => false, 'message' => 'Gagal menghubungi server: ' . $err];
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        return ['success' => false, 'message' => 'Respons server tidak valid.'];
    }
    return $json;
}

function livechatAiFormatCustomerInfo(array $pelanggan)
{
    $idpel = (string)($pelanggan['IDPEL'] ?? '');
    $nama = (string)($pelanggan['NAMA'] ?? '');
    $nowa = (string)($pelanggan['NOWA'] ?? '');
    $paket = (string)($pelanggan['PAKET'] ?? '');
    $harga = (float)($pelanggan['HARGA'] ?? 0);
    $alamat = trim((string)($pelanggan['ALAMAT'] ?? ''));
    $email = trim((string)($pelanggan['EMAIL'] ?? ''));
    $tempo = trim((string)($pelanggan['TEMPO'] ?? ''));
    $area = trim((string)($pelanggan['AREA'] ?? ''));
    $tanggalPasang = trim((string)($pelanggan['TANGGALPASANG'] ?? ''));

    $text = "📋 *Data Pelanggan Terdeteksi*\n\n";
    $text .= "IDPEL: *$idpel*\n";
    $text .= "Nama: *$nama*\n";
    if ($nowa !== '') $text .= "No WA: $nowa\n";
    if ($paket !== '') $text .= "Paket: *$paket*\n";
    if ($harga > 0) $text .= "Harga Paket: Rp " . number_format($harga, 0, ',', '.') . "\n";
    if ($area !== '') $text .= "Area: $area\n";
    if ($alamat !== '') $text .= "Alamat: $alamat\n";
    if ($email !== '') $text .= "Email: $email\n";
    if ($tanggalPasang !== '') $text .= "Tanggal Pasang: $tanggalPasang\n";
    if ($tempo !== '') $text .= "Jatuh Tempo: tanggal $tempo tiap bulan\n";

    return $text;
}

// File session sederhana per-pelanggan (pola sama dgn sessions_payment_code/
// milik WA bot di crm/webhook/, cuma di-key pakai IDPEL bukan nomor HP karena
// Live Chat sudah terautentikasi via token, bukan trust nomor HP).
function livechatAiPaymentSessionPath($idpel)
{
    $dir = __DIR__ . '/api/tagihanwifiku/sessions_livechat_payment';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $idpel);
    return $dir . '/' . $safe . '.json';
}

function livechatAiListPaymentMethods($domain, $token, $idpel)
{
    $result = livechatAiCallInternalApi($domain, 'payment_methods.php', $token, 'GET');
    if (empty($result['success'])) {
        return '❌ ' . ($result['message'] ?? 'Gagal mengambil daftar metode pembayaran.');
    }

    $gateways = $result['data']['gateways'] ?? [];
    $flatList = [];
    foreach ($gateways as $gw) {
        if (empty($gw['enabled'])) continue;
        $gwCode = (string)($gw['code'] ?? '');
        $methods = $gw['methods'] ?? [];
        if ($gwCode === 'manual_bank' || empty($methods)) {
            // Gateway tanpa daftar channel (mis. transfer manual) -- 1 entri saja.
            $flatList[] = ['gateway' => $gwCode, 'method_code' => '', 'label' => (string)($gw['name'] ?? $gwCode)];
            continue;
        }
        foreach ($methods as $m) {
            if (isset($m['active']) && !$m['active']) continue;
            $flatList[] = ['gateway' => $gwCode, 'method_code' => (string)($m['code'] ?? ''), 'label' => (string)($m['name'] ?? $m['code'] ?? '')];
        }
    }

    if (empty($flatList)) {
        return '❌ Belum ada metode pembayaran yang aktif dikonfigurasi. Silakan hubungi admin.';
    }

    // Simpan session supaya "#bayar N"/"bayar N" berikutnya tahu mau pilih yang mana.
    $session = [
        'idpel' => $idpel,
        'methods' => $flatList,
        'created_at' => time(),
    ];
    @file_put_contents(livechatAiPaymentSessionPath($idpel), json_encode($session, JSON_UNESCAPED_UNICODE));

    $customer = $result['data']['customer'] ?? [];
    $text = "📋 *Data Pelanggan Terdeteksi*\n\n";
    if (!empty($customer)) {
        $text .= "IDPEL: *" . ($customer['IDPEL'] ?? $idpel) . "*\n";
        $text .= "Nama: *" . ($customer['NAMA'] ?? '') . "*\n";
    }
    $text .= "\n💳 *Pilih Metode Bayar:*\n";
    $no = 1;
    foreach ($flatList as $item) {
        $text .= $no . ". " . $item['label'] . "\n";
        $no++;
    }
    $text .= "\nKetik: *bayar 1* atau angka saja *1* (ganti angka sesuai pilihan, boleh pakai # atau tidak)\n";
    $text .= "Ketik: *batalbayar* untuk membatalkan.";

    return $text;
}

function livechatAiChoosePaymentMethod($domain, $token, $idpel, $choiceNumber)
{
    $sessionPath = livechatAiPaymentSessionPath($idpel);
    if (!file_exists($sessionPath)) {
        return '❌ Belum ada daftar metode pembayaran aktif. Ketik *bayar* dulu untuk melihat pilihannya.';
    }
    $session = json_decode((string)file_get_contents($sessionPath), true);
    if (!is_array($session) || (time() - (int)($session['created_at'] ?? 0)) > 900) {
        @unlink($sessionPath);
        return '❌ Sesi pemilihan metode bayar sudah kedaluwarsa (lebih dari 15 menit). Ketik *bayar* lagi untuk mengulang.';
    }

    $methods = $session['methods'] ?? [];
    $idx = $choiceNumber - 1;
    if (!isset($methods[$idx])) {
        return '❌ Nomor pilihan tidak valid. Ketik *bayar* untuk melihat daftar metode lagi.';
    }

    $chosen = $methods[$idx];
    $result = livechatAiCallInternalApi($domain, 'payment_create.php', $token, 'POST', [
        'gateway' => $chosen['gateway'],
        'method_code' => $chosen['method_code'],
    ]);

    if (empty($result['success'])) {
        return '❌ ' . ($result['message'] ?? 'Gagal membuat kode pembayaran.');
    }

    @unlink($sessionPath);

    $p = $result['data']['payment'] ?? [];
    $amount = (float)($p['amount'] ?? 0);
    $text = "✅ *Kode Pembayaran Berhasil Dibuat*\n\n";
    $text .= "Metode: *" . ($p['payment_name'] ?? $chosen['label']) . "*\n";
    $text .= "Total: *Rp " . number_format($amount, 0, ',', '.') . "*\n";
    if (!empty($p['pay_code'])) $text .= "Kode Bayar/VA: *" . $p['pay_code'] . "*\n";
    if (!empty($p['expired_at'])) $text .= "Berlaku sampai: " . $p['expired_at'] . "\n";
    if (!empty($p['checkout_url'])) $text .= "\nLink pembayaran: " . $p['checkout_url'] . "\n";
    if (!empty($p['qr_url'])) $text .= "QR: " . $p['qr_url'] . "\n";
    if (!empty($p['instructions']) && is_array($p['instructions'])) {
        foreach ($p['instructions'] as $ins) {
            if (!empty($ins['title'])) $text .= "\n*" . $ins['title'] . "*\n";
            if (!empty($ins['steps']) && is_array($ins['steps'])) {
                foreach ($ins['steps'] as $i => $step) {
                    $text .= (($i) + 1) . ". " . $step . "\n";
                }
            }
        }
    }

    return $text;
}

function livechatAiCreateComplaint($domain, $token, $keluhan)
{
    $result = livechatAiCallInternalApi($domain, 'complaint_create.php', $token, 'POST', ['keluhan' => $keluhan]);
    if (empty($result['success'])) {
        return '❌ ' . ($result['message'] ?? 'Gagal mengirim tiket gangguan.');
    }
    return "✅ *Tiket Gangguan Berhasil Dibuat*\n\nKeluhan Anda sudah kami terima:\n\"" . $keluhan . "\"\n\nMohon tunggu, teknisi kami akan segera menindaklanjuti.";
}

// Dispatcher utama, dipanggil dari chat_send.php SEBELUM livechatAiMaybeReply().
// Return teks balasan (string) kalau keyword dikenali & sudah diproses, atau
// null kalau tidak ada keyword yang cocok (lanjut ke AI chat biasa).
// "#" di depan bayar/batal SENGAJA opsional (pelanggan yg lupa/tidak sengaja
// tanpa # tetap harus dianggap valid), sama seperti perbaikan di WA bot.
function livechatAiHandleKeywordAction($conn, $pemilik, $senderIdentity, $domain, $token, $pelanggan, $idpel, $message)
{
    $trimmed = trim((string)$message);
    $upper = strtoupper($trimmed);

    // Angka polos (mis. cuma "1") dianggap pilihan metode bayar KALAU ada sesi
    // pembayaran aktif yg nunggu pilihan -- dicek DULUAN, sebelum keyword lain,
    // sama seperti perbaikan di WA bot.
    if (preg_match('/^\d+$/', $trimmed) && file_exists(livechatAiPaymentSessionPath($idpel))) {
        $reply = livechatAiChoosePaymentMethod($domain, $token, $idpel, (int)$trimmed);
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'kode_bayar', (strpos($reply, '❌') === 0 ? 'error' : 'ok'), $reply);
        return $reply;
    }

    // Cek data pelanggan
    if (preg_match('/^#?cek\s*data$|^#?cekdata$/i', $trimmed) || $upper === 'CEK DATA' || $upper === 'CEKDATA') {
        $reply = livechatAiFormatCustomerInfo($pelanggan);
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'cek_data', 'ok', $reply);
        return $reply;
    }

    // Kode bayar - langkah 1: tampilkan daftar metode
    if (preg_match('/^#?(kode\s*)?bayar$/i', $trimmed) || $upper === 'BAYAR' || $upper === 'KODE BAYAR') {
        $reply = livechatAiListPaymentMethods($domain, $token, $idpel);
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'kode_bayar', (strpos($reply, '❌') === 0 ? 'error' : 'ok'), $reply);
        return $reply;
    }

    // Kode bayar - langkah 2: pilih metode ("bayar 1" / "#bayar 1")
    if (preg_match('/^#?bayar\s*(\d+)$/i', $trimmed, $m)) {
        $reply = livechatAiChoosePaymentMethod($domain, $token, $idpel, (int)$m[1]);
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'kode_bayar', (strpos($reply, '❌') === 0 ? 'error' : 'ok'), $reply);
        return $reply;
    }

    // Batalkan sesi pemilihan metode bayar
    if (preg_match('/^#?batal(?:\s*bayar)?$/i', $trimmed)) {
        @unlink(livechatAiPaymentSessionPath($idpel));
        $reply = '✅ Permintaan kode pembayaran dibatalkan.';
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'kode_bayar', 'ok', $reply);
        return $reply;
    }

    // Tiket gangguan: "gangguan <keluhan>" atau "lapor gangguan <keluhan>"
    if (preg_match('/^#?(?:lapor\s*)?gangguan[:\s]+(.+)$/is', $trimmed, $m)) {
        $keluhan = trim($m[1]);
        if ($keluhan === '') {
            $reply = '⚠️ Mohon sertakan detail keluhannya. Contoh: *gangguan internet putus-putus sejak pagi*';
            livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'gangguan', 'error', $reply);
            return $reply;
        }
        $reply = livechatAiCreateComplaint($domain, $token, $keluhan);
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'gangguan', (strpos($reply, '❌') === 0 ? 'error' : 'ok'), $reply);
        return $reply;
    }

    $settings = livechatAiGetSettings($conn, $pemilik);

    // Menu kustom (mis. "ketik MENU utk lihat layanan") -- teks diatur admin di
    // halaman Pengaturan Live Chat. Kalau belum diisi, pakai daftar perintah default.
    if ($upper === 'MENU' || $upper === '#MENU') {
        $menuText = trim((string)($settings['menu_text'] ?? ''));
        if ($menuText === '') {
            $menuText = "📌 *Menu Layanan*\n\n"
                . "• *cek data* - lihat data pelanggan Anda\n"
                . "• *gangguan <keluhan>* - laporkan gangguan\n"
                . "• *bayar* - buat kode pembayaran\n\n"
                . "Atau tanya apa saja, akan dijawab otomatis.";
        }
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'menu', 'ok', $menuText);
        return $menuText;
    }

    // Jawaban kustom (keyword => jawaban) yang diatur admin.
    $customReply = livechatAiFindKeywordResponse($settings, $trimmed);
    if ($customReply !== null) {
        livechatAiLog($conn, $pemilik, $senderIdentity, $trimmed, 'keyword', 'ok', $customReply);
        return $customReply;
    }

    return null;
}
