<?php
/**
 * Page Access Logger
 * Mencatat setiap akses halaman ke database
 */

function log_page_access($conn, $user_id, $username) {
    try {
        // Ambil informasi request
        $page_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $page_name = basename($_SERVER['PHP_SELF']);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $timestamp = date('Y-m-d H:i:s');
        
        // Query string (parameter URL)
        $query_string = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        if (!empty($query_string)) {
            $full_url = $page_url;
        } else {
            $full_url = $page_url;
        }
        
        // Escape data untuk keamanan
        $page_url = mysqli_real_escape_string($conn, $page_url);
        $page_name = mysqli_real_escape_string($conn, $page_name);
        $ip_address = mysqli_real_escape_string($conn, $ip_address);
        $user_agent = mysqli_real_escape_string($conn, substr($user_agent, 0, 500)); // Batasi panjang
        $referer = mysqli_real_escape_string($conn, $referer);
        $method = mysqli_real_escape_string($conn, $method);
        $username = mysqli_real_escape_string($conn, $username);
        
        // Insert ke database
        $sql = "INSERT INTO page_access_log 
                (user_id, username, page_url, page_name, ip_address, user_agent, referer, method, access_time) 
                VALUES 
                ('$user_id', '$username', '$page_url', '$page_name', '$ip_address', '$user_agent', '$referer', '$method', '$timestamp')";
        
        mysqli_query($conn, $sql);
        
        // Tidak perlu throw error jika gagal, agar tidak mengganggu jalannya aplikasi
        // Logging bersifat opsional dan tidak boleh menghentikan aplikasi
        
    } catch (Exception $e) {
        // Silent fail - logging tidak boleh mengganggu aplikasi utama
        // Opsional: bisa log ke file jika diperlukan
        // error_log("Page logger error: " . $e->getMessage());
    }
}

// Panggil fungsi logging jika semua variable tersedia
if (isset($conn) && isset($current_user_id) && isset($ceknama)) {
    log_page_access($conn, $current_user_id, $ceknama);
}
?>
