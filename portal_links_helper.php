<?php
// Helper terpusat untuk isi Pengaturan Ketentuan portal pelanggan (teks FAQ,
// Kebijakan Refund, Syarat & Ketentuan, Kontak -- bukan link URL, tapi isi
// tekstual yang langsung ditampilkan di modal portallogin.php). Disimpan per
// akun OWNER (username CRM, sama seperti pola settings/receipt-settings-{username}.json)
// di settings/portal-ketentuan-{username}.json.

function portal_links_defaults()
{
    return [
        'product_name'   => '',
        'tagline'        => '',
        'feature1_title' => '',
        'feature1_text'  => '',
        'feature2_title' => '',
        'feature2_text'  => '',
        'feature3_title' => '',
        'feature3_text'  => '',
        'faq_text'       => '',
        'refund_text'    => '',
        'terms_text'     => '',
        'contact_text'   => '',
    ];
}

function portal_links_safe_username($username)
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $username);
}

function portal_links_file_path($username)
{
    $safe = portal_links_safe_username($username);
    if ($safe === '') {
        return null;
    }
    return __DIR__ . '/settings/portal-ketentuan-' . $safe . '.json';
}

function get_portal_links($username)
{
    $defaults = portal_links_defaults();
    $file = portal_links_file_path($username);
    if ($file === null || !is_file($file)) {
        return $defaults;
    }

    $raw = @file_get_contents($file);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $result = $defaults;
    foreach ($defaults as $key => $default_val) {
        if (array_key_exists($key, $decoded)) {
            $result[$key] = (string) $decoded[$key];
        }
    }
    return $result;
}

// Batasi panjang teks (bukan validasi format, karena ini teks bebas, bukan
// URL) supaya tidak ada input tak wajar yang membengkakkan file JSON.
function portal_links_sanitize_text($value)
{
    $value = trim((string) $value);
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, 5000);
    } else {
        $value = substr($value, 0, 5000);
    }
    return $value;
}

function save_portal_links($username, array $input)
{
    $file = portal_links_file_path($username);
    if ($file === null) {
        return false;
    }

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    // Batas panjang khusus utk field pendek (bukan teks bebas panjang spt FAQ
    // dkk yg pakai portal_links_sanitize_text() / limit 5000 default).
    $shortFieldMaxLen = [
        'product_name'   => 100,
        'tagline'        => 200,
        'feature1_title' => 40,
        'feature1_text'  => 150,
        'feature2_title' => 40,
        'feature2_text'  => 150,
        'feature3_title' => 40,
        'feature3_text'  => 150,
    ];

    $defaults = portal_links_defaults();
    $normalized = $defaults;
    foreach ($defaults as $key => $default_val) {
        if (array_key_exists($key, $input)) {
            $normalized[$key] = isset($shortFieldMaxLen[$key])
                ? mb_substr(trim((string) $input[$key]), 0, $shortFieldMaxLen[$key])
                : portal_links_sanitize_text($input[$key]);
        }
    }

    return @file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}
