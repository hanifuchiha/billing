<?php
/**
 * Bot Selector Helper Function
 * Digunakan untuk memilih bot secara random atau bot spesifik
 * untuk menghindari pemblokiran spam
 */

/**
 * Fungsi untuk mendapatkan bot yang akan digunakan
 * Jika selectedBotSystem adalah "RANDOM", akan memilih bot secara random dari daftar aktif
 * Jika selectedBotSystem adalah nama bot spesifik, akan menggunakan bot tersebut
 * 
 * @param mysqli $conn Koneksi database mysqli
 * @param string $pemilik Username pemilik bot
 * @param string $selectedBotSystem Nama bot yang dipilih atau "RANDOM" untuk random
 * @return array Array berhasil berisi data bot: ['success' => true, 'namebot' => '', 'addressbot' => '', 'password' => '', 'penerima' => '']
 *               atau gagal: ['success' => false, 'message' => '']
 */
function selectBotForNotification($conn, $pemilik, $selectedBotSystem) {
    // Validasi input
    if (empty($conn) || empty($pemilik)) {
        return [
            'success' => false,
            'message' => 'Koneksi database atau pemilik tidak valid'
        ];
    }
    
    // Escape the pemilik for safety
    $pemilik_escaped = mysqli_real_escape_string($conn, $pemilik);
    
    // Jika dipilih RANDOM, ambil bot secara random dari semua bot yang tersedia
    if (strtoupper(trim($selectedBotSystem)) === 'RANDOM') {
        echo "[BOT SELECTOR] Mode RANDOM dipilih<br>";
        
        // Ambil semua bot yang tersedia untuk pemilik ini
        $sql_all_bots = "SELECT id, namebot, addressbot, password, penerima, sender FROM `botwa`
                         WHERE `pemilik` = '$pemilik_escaped' 
                         ORDER BY RAND() LIMIT 1";
        $query = mysqli_query($conn, $sql_all_bots);
        
        if (!$query || mysqli_num_rows($query) === 0) {
            return [
                'success' => false,
                'message' => 'Tidak ada bot tersedia untuk mode RANDOM'
            ];
        }
        
        $bot_data = mysqli_fetch_array($query);
        echo "[BOT SELECTOR] Bot yang dipilih secara random: " . $bot_data['namebot'] . "<br>";
        
        return [
            'success' => true,
            'namebot' => $bot_data['namebot'],
            'addressbot' => $bot_data['addressbot'],
            'password' => $bot_data['password'],
            'penerima' => $bot_data['penerima'],
            'sender' => $bot_data['sender'] ?? '',
            'id' => $bot_data['id'],
            'is_random' => true
        ];
    }
    
    // Jika dipilih bot spesifik
    if (!empty($selectedBotSystem)) {
        $selectedBotSystem_escaped = mysqli_real_escape_string($conn, $selectedBotSystem);
        $sql = "SELECT id, namebot, addressbot, password, penerima, sender FROM `botwa`
                WHERE `pemilik` = '$pemilik_escaped' AND `namebot` = '$selectedBotSystem_escaped' 
                LIMIT 1";
        $query = mysqli_query($conn, $sql);
        
        if ($query && mysqli_num_rows($query) > 0) {
            $bot_data = mysqli_fetch_array($query);
            echo "[BOT SELECTOR] Bot spesifik dipilih: " . $bot_data['namebot'] . "<br>";
            
            return [
                'success' => true,
                'namebot' => $bot_data['namebot'],
                'addressbot' => $bot_data['addressbot'],
                'password' => $bot_data['password'],
                'penerima' => $bot_data['penerima'],
                'sender' => $bot_data['sender'] ?? '',
                'id' => $bot_data['id'],
                'is_random' => false
            ];
        }
    }

    // Fallback: Ambil bot pertama yang tersedia
    $sql_fallback = "SELECT id, namebot, addressbot, password, penerima, sender FROM `botwa`
                     WHERE `pemilik` = '$pemilik_escaped' 
                     LIMIT 1";
    $query = mysqli_query($conn, $sql_fallback);
    
    if ($query && mysqli_num_rows($query) > 0) {
        $bot_data = mysqli_fetch_array($query);
        echo "[BOT SELECTOR] Fallback: Bot pertama digunakan: " . $bot_data['namebot'] . "<br>";
        
        return [
            'success' => true,
            'namebot' => $bot_data['namebot'],
            'addressbot' => $bot_data['addressbot'],
            'password' => $bot_data['password'],
            'penerima' => $bot_data['penerima'],
            'sender' => $bot_data['sender'] ?? '',
            'id' => $bot_data['id'],
            'is_random' => false
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Tidak ada bot tersedia untuk pemilik: ' . $pemilik
    ];
}

/**
 * Fungsi untuk mendapatkan bot dengan field penerima yang spesifik
 * Mirip dengan selectBotForNotification namun dengan dukungan field penerima yang berbeda
 * 
 * @param mysqli $conn Koneksi database mysqli
 * @param string $pemilik Username pemilik bot
 * @param string $selectedBotSystem Nama bot yang dipilih atau "RANDOM" untuk random
 * @param string $penerima_field Field penerima yang diinginkan (penerima, penerima_server, dll)
 * @return array Array hasil pemilihan bot
 */
function selectBotForNotificationWithField($conn, $pemilik, $selectedBotSystem, $penerima_field = 'penerima') {
    // Validasi input
    if (empty($conn) || empty($pemilik)) {
        return [
            'success' => false,
            'message' => 'Koneksi database atau pemilik tidak valid'
        ];
    }
    
    // Whitelist field penerima yang diizinkan (keamanan)
    $allowed_fields = ['penerima', 'penerima_server', 'penerima_livechat', 'penerima_system_notif', 'penerima_manual_active', 'penerima_odp_los', 'penerima_provisioning'];
    if (!in_array($penerima_field, $allowed_fields)) {
        $penerima_field = 'penerima';
    }
    
    // Escape the pemilik for safety
    $pemilik_escaped = mysqli_real_escape_string($conn, $pemilik);
    
    // Jika dipilih RANDOM, ambil bot secara random
    if (strtoupper(trim($selectedBotSystem)) === 'RANDOM') {
        echo "[BOT SELECTOR] Mode RANDOM dipilih untuk field: $penerima_field<br>";
        
        $sql_all_bots = "SELECT id, namebot, addressbot, password, sender, $penerima_field as penerima_target FROM `botwa`
                         WHERE `pemilik` = '$pemilik_escaped' 
                         ORDER BY RAND() LIMIT 1";
        $query = mysqli_query($conn, $sql_all_bots);
        
        if (!$query || mysqli_num_rows($query) === 0) {
            return [
                'success' => false,
                'message' => 'Tidak ada bot tersedia untuk mode RANDOM'
            ];
        }
        
        $bot_data = mysqli_fetch_array($query);
        echo "[BOT SELECTOR] Bot random dipilih: " . $bot_data['namebot'] . " dengan field: $penerima_field<br>";
        
        return [
            'success' => true,
            'namebot' => $bot_data['namebot'],
            'addressbot' => $bot_data['addressbot'],
            'password' => $bot_data['password'],
            'penerima' => $bot_data['penerima_target'],
            'sender' => $bot_data['sender'] ?? '',
            'id' => $bot_data['id'],
            'is_random' => true,
            'penerima_field' => $penerima_field
        ];
    }
    
    // Jika dipilih bot spesifik
    if (!empty($selectedBotSystem)) {
        $selectedBotSystem_escaped = mysqli_real_escape_string($conn, $selectedBotSystem);
        $sql = "SELECT id, namebot, addressbot, password, sender, $penerima_field as penerima_target FROM `botwa`
                WHERE `pemilik` = '$pemilik_escaped' AND `namebot` = '$selectedBotSystem_escaped' 
                LIMIT 1";
        $query = mysqli_query($conn, $sql);
        
        if ($query && mysqli_num_rows($query) > 0) {
            $bot_data = mysqli_fetch_array($query);
            echo "[BOT SELECTOR] Bot spesifik dipilih: " . $bot_data['namebot'] . "<br>";
            
            return [
                'success' => true,
                'namebot' => $bot_data['namebot'],
                'addressbot' => $bot_data['addressbot'],
                'password' => $bot_data['password'],
                'penerima' => $bot_data['penerima_target'],
            'sender' => $bot_data['sender'] ?? '',
                'id' => $bot_data['id'],
                'is_random' => false,
                'penerima_field' => $penerima_field
            ];
        }
    }
    
    // Fallback: Ambil bot pertama yang tersedia
    $sql_fallback = "SELECT id, namebot, addressbot, password, sender, $penerima_field as penerima_target FROM `botwa`
                     WHERE `pemilik` = '$pemilik_escaped' 
                     LIMIT 1";
    $query = mysqli_query($conn, $sql_fallback);
    
    if ($query && mysqli_num_rows($query) > 0) {
        $bot_data = mysqli_fetch_array($query);
        echo "[BOT SELECTOR] Fallback - Bot pertama digunakan: " . $bot_data['namebot'] . "<br>";
        
        return [
            'success' => true,
            'namebot' => $bot_data['namebot'],
            'addressbot' => $bot_data['addressbot'],
            'password' => $bot_data['password'],
            'penerima' => $bot_data['penerima_target'],
            'sender' => $bot_data['sender'] ?? '',
            'id' => $bot_data['id'],
            'is_random' => false,
            'penerima_field' => $penerima_field
        ];
    }
    
    return [
        'success' => false,
        'message' => "Tidak ada bot tersedia untuk pemilik: $pemilik dengan field: $penerima_field"
    ];
}

/**
 * Mendapatkan owner key untuk konfigurasi salam dinamis.
 *
 * @param string|null $ownerKey
 * @return string
 */
function resolveDynamicGreetingOwnerKey($ownerKey = null) {
    $resolvedOwner = trim((string)$ownerKey);

    if ($resolvedOwner === '') {
        $globalCandidates = ['ownerbilling', 'serverarea', 'ceknama', 'username'];
        foreach ($globalCandidates as $globalKey) {
            if (isset($GLOBALS[$globalKey]) && trim((string)$GLOBALS[$globalKey]) !== '') {
                $resolvedOwner = trim((string)$GLOBALS[$globalKey]);
                break;
            }
        }
    }

    if ($resolvedOwner === '' && isset($_SESSION['nama']) && trim((string)$_SESSION['nama']) !== '') {
        $resolvedOwner = trim((string)$_SESSION['nama']);
    }

    if ($resolvedOwner === '') {
        $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $nameOnly = pathinfo($script, PATHINFO_FILENAME);
        if ($nameOnly !== '') {
            $parts = explode('_', $nameOnly);
            if (count($parts) > 1) {
                $candidate = trim((string)end($parts));
                $invalid = ['tripay', 'xendit', 'midtrans', 'duitku', 'pronpay', 'callback'];
                if ($candidate !== '' && !in_array(strtolower($candidate), $invalid, true)) {
                    $resolvedOwner = $candidate;
                }
            }
        }
    }

    return $resolvedOwner;
}

/**
 * Mengambil path konfigurasi salam dinamis per owner.
 *
 * @param string|null $ownerKey
 * @return string
 */
function getDynamicGreetingConfigPath($ownerKey = null) {
    $resolvedOwner = resolveDynamicGreetingOwnerKey($ownerKey);
    if ($resolvedOwner === '') {
        return '';
    }

    $safeOwner = preg_replace('/[^A-Za-z0-9_.-]/', '_', $resolvedOwner);
    return __DIR__ . '/data/dynamic_greeting-' . $safeOwner . '.json';
}

/**
 * Mengambil konfigurasi salam dinamis dari file JSON.
 *
 * @param string|null $ownerKey
 * @return array|null
 */
function getDynamicGreetingConfig($ownerKey = null) {
    $configPath = getDynamicGreetingConfigPath($ownerKey);
    if ($configPath === '' || !file_exists($configPath)) {
        return null;
    }

    $cfg = json_decode((string)file_get_contents($configPath), true);
    if (!is_array($cfg)) {
        return null;
    }

    return $cfg;
}

/**
 * Daftar default salam dinamis jika belum ada custom list.
 *
 * @return array
 */
function getDefaultGreetingList() {
    return [
        // Salam pembuka Bahasa Indonesia
        "Assalamualaikum",
        "Halo",
        "Hai",
        "Salam Pagi",
        "Salam Sore",
        "Salam Malam",
        "Salam Hormat",
        "Kepada Yth.",
        "Dengan hormat kami informasikan",
        "Kami ingin memberitahukan",
        "Berikut kami sampaikan informasi penting",
        "Pemberitahuan penting untuk Anda",
        "Informasi terkait layanan Anda",
        "Pembaruan status layanan Anda",
        "Pesan penting dari kami",
        "Salam hangat",
        "Kami hadir menyapa Anda",
        "Semoga Anda dalam keadaan baik",
        "Selamat beraktivitas",
        "Salam sejahtera",
        "Selamat pagi",
        "Selamat siang",
        "Selamat sore",
        "Selamat malam",
        "Berdasarkan catatan kami",
        "Berdasarkan sistem kami",
        "Kami berharap dengan baik",
        "Sebagai pemberitahuan",
        "Untuk informasi Anda",
        "Mohon perhatian Anda",
        "Bapak/Ibu yang terhormat",
        "Yth. Pelanggan setia kami",
        "Kami ucapkan terima kasih",
        "Kami sampaikan informasi",
        "Kami ingin menginformasikan",
        "Kami berikan informasi berikut",
        "Adapun informasi yang kami sampaikan",
        "Sesuai dengan jadwal yang telah ditentukan",
        "Berdasarkan data sistem kami",
        "Dari sistem informasi kami",
        "Menurut catatan terakhir kami",
        "Sesuai dengan riwayat transaksi Anda"
    ];
}

/**
 * Fungsi untuk mendapatkan salam pembuka yang dinamis/random.
 * Jika tersedia daftar custom dari menu Notification, daftar tersebut akan dipakai.
 *
 * @param string|null $ownerKey
 * @return string Salam pembuka yang dipilih secara random
 */
function getRandomGreeting($ownerKey = null) {
    $greetings = getDefaultGreetingList();

    $cfg = getDynamicGreetingConfig($ownerKey);
    if (is_array($cfg) && isset($cfg['greetings']) && is_array($cfg['greetings'])) {
        $customGreetings = [];
        foreach ($cfg['greetings'] as $item) {
            $line = trim((string)$item);
            if ($line !== '') {
                $customGreetings[] = $line;
            }
        }

        $customGreetings = array_values(array_unique($customGreetings));
        if (!empty($customGreetings)) {
            $greetings = $customGreetings;
        }
    }

    return $greetings[array_rand($greetings)];
}

/**
 * Mengecek apakah salam dinamis diaktifkan dari menu Notification.
 * Config disimpan per owner di notifbot/data/dynamic_greeting-<owner>.json
 *
 * @param string|null $ownerKey Owner spesifik (opsional)
 * @param bool $default Nilai default jika config belum ada
 * @return bool
 */
function isDynamicGreetingEnabled($ownerKey = null, $default = true) {
    $cfg = getDynamicGreetingConfig($ownerKey);
    if (!is_array($cfg) || !array_key_exists('enabled', $cfg)) {
        return (bool)$default;
    }

    return !empty($cfg['enabled']);
}

/**
 * Fungsi untuk menambahkan salam pembuka dinamis ke pesan
 * Menggabungkan salam random dengan isi pesan
 * 
 * @param string $message Isi pesan utama
 * @param bool $usePunctuation Tambahkan tanda baca setelah salam (default: true)
 * @return string Pesan lengkap dengan salam pembuka
 */
function prependDynamicGreeting($message, $usePunctuation = true, $ownerKey = null) {
    if (!isDynamicGreetingEnabled($ownerKey, true)) {
        return $message;
    }

    if (trim((string)$message) === '') {
        return $message;
    }

    $greeting = getRandomGreeting($ownerKey);
    $punctuation = $usePunctuation ? "\n\n" : " ";
    return $greeting . $punctuation . $message;
}
?>
