<?php
// Helper terpusat untuk pengaturan struk transaksi: kop struk (nama usaha, alamat,
// no CS, dll) dan toggle Print langsung / Download PDF. Disimpan per akun pemilik
// (kolom PEMILIK di tabel transaksi = username akun) di
// settings/receipt-settings-{username}.json, mengikuti pola yang sama dengan
// settings/dashboard-cards-{username}.json.

function struk_settings_defaults()
{
    return [
        'kop_nama_usaha'  => 'FiberQ WiFi',
        'kop_tagline'     => 'Terpercaya & Stabil',
        'kop_alamat'      => '',
        'kop_no_cs'       => '',
        'kop_footer_note' => "Terima kasih telah bertransaksi!\nSimpan struk ini sebagai bukti pembayaran.",
        'print_enabled'   => true,
        'pdf_enabled'     => true,
        'paper_size'      => '58mm', // 58mm | 80mm | a4
        'logo_enabled'    => true,
    ];
}

function struk_settings_safe_username($username)
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $username);
}

function struk_settings_file_path($username)
{
    $safe = struk_settings_safe_username($username);
    if ($safe === '') {
        return null;
    }
    return __DIR__ . '/settings/receipt-settings-' . $safe . '.json';
}

function get_struk_settings($username)
{
    $defaults = struk_settings_defaults();
    $file = struk_settings_file_path($username);
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
        if (!array_key_exists($key, $decoded)) {
            continue;
        }
        $result[$key] = is_bool($default_val) ? (bool) $decoded[$key] : (string) $decoded[$key];
    }
    if (!in_array($result['paper_size'], ['58mm', '80mm', 'a4'], true)) {
        $result['paper_size'] = '58mm';
    }
    return $result;
}

function save_struk_settings($username, array $input)
{
    $file = struk_settings_file_path($username);
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

    $defaults = struk_settings_defaults();
    $normalized = $defaults;
    foreach ($defaults as $key => $default_val) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $normalized[$key] = is_bool($default_val) ? (bool) $input[$key] : trim((string) $input[$key]);
    }
    if (!in_array($normalized['paper_size'], ['58mm', '80mm', 'a4'], true)) {
        $normalized['paper_size'] = '58mm';
    }

    return @file_put_contents($file, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

// Bangun potongan HTML isi struk (dipakai bareng oleh print_card.php/PDF dan
// print_struk.php/print langsung, supaya tampilan struk selalu konsisten).
function render_struk_body_html(array $data, array $settings)
{
    $rows = [
        ['Nama', $data['NAMA'] ?? ''],
        ['ID', $data['IDPEL'] ?? ''],
        ['Paket', $data['PAKET'] ?? ''],
        ['Harga', 'Rp ' . number_format((float) ($data['HARGA'] ?? 0), 0, ',', '.')],
        ['Ref Tripay', $data['BUKTI'] ?? ''],
        ['Tanggal', $data['TANGGALBAYAR'] ?? ''],
        ['Penggunaan', $data['PENGUNAAN'] ?? ''],
        ['Produk', $data['PEMILIK'] ?? ''],
    ];

    $logo_enabled = !array_key_exists('logo_enabled', $settings) || $settings['logo_enabled'];
    $imgFile = __DIR__ . '/../../dokumen/logo/profile-' . ($data['PEMILIK'] ?? '') . '.png';
    $imgData = ($logo_enabled && file_exists($imgFile)) ? base64_encode(file_get_contents($imgFile)) : '';
    $imgSrc = $imgData ? 'data:image/png;base64,' . $imgData : '';

    $nama_usaha = trim((string) ($settings['kop_nama_usaha'] ?? '')) !== ''
        ? $settings['kop_nama_usaha']
        : (string) ($data['PEMILIK'] ?? '');
    $tagline = trim((string) ($settings['kop_tagline'] ?? ''));
    $alamat = trim((string) ($settings['kop_alamat'] ?? ''));
    $no_cs = trim((string) ($settings['kop_no_cs'] ?? ''));
    $footer_note = trim((string) ($settings['kop_footer_note'] ?? ''));

    $rows_html = '';
    foreach ($rows as $r) {
        $rows_html .= '<tr><td class="text-left">' . htmlspecialchars((string) $r[0]) . '</td><td class="text-right">' . htmlspecialchars((string) $r[1]) . '</td></tr>';
    }

    $status_html = (($data['STATUS'] ?? '') === 'BERHASIL') ? '&#10003; BERHASIL' : '&#8987; PENDING';

    $html = '<div class="struk">';
    if ($imgSrc !== '') {
        $html .= '<img src="' . $imgSrc . '" class="logo"><br>';
    }
    $html .= '<div class="title">' . htmlspecialchars($nama_usaha) . '</div>';
    if ($tagline !== '') {
        $html .= '<small>' . htmlspecialchars($tagline) . '</small><br>';
    }
    if ($alamat !== '') {
        $html .= '<small>' . htmlspecialchars($alamat) . '</small><br>';
    }
    if ($no_cs !== '') {
        $html .= '<small>CS: ' . htmlspecialchars($no_cs) . '</small>';
    }
    $html .= '<div class="line"></div>';
    $html .= '<table>' . $rows_html . '</table>';
    $html .= '<div class="line"></div>';
    $html .= '<strong>Status: ' . $status_html . '</strong>';
    $html .= '<div class="footer">';
    if ($footer_note !== '') {
        $html .= nl2br(htmlspecialchars($footer_note)) . '<br>';
    }
    $html .= '~ ' . htmlspecialchars($nama_usaha) . ' ~';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

// CSS bersama untuk tampilan struk (dipakai di dalam <style> pada print_card.php
// dan print_struk.php). $width adalah lebar body/kertas struk (unit CSS bebas,
// mis. "220pt" untuk dompdf atau "58mm" untuk browser).
function struk_shared_css($width)
{
    return '
    * { margin:0; padding:0; box-sizing:border-box; }
    .struk-paper {
        font-family: monospace;
        font-size: 11px;
        width: ' . $width . ';
        color: #000;
        padding: 8px;
        height: auto;
    }
    .struk { width: 100%; text-align: center; }
    .logo { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-bottom: 3px; }
    .title { font-size: 13px; font-weight: bold; }
    .line { border-top: 1px dashed #000; margin: 4px 0; }
    .struk table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 5px; }
    .struk td { padding: 1px 2px; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; }
    .text-left { text-align: left; }
    .text-right { text-align: right; padding-right: 5pt; }
    .footer { font-size: 10px; border-top: 1px dashed #000; margin-top: 6px; padding-top: 4px; }
    ';
}

function struk_paper_width_pt($paper_size)
{
    if ($paper_size === '80mm') {
        return 300;
    }
    if ($paper_size === 'a4') {
        return 480;
    }
    return 220; // 58mm
}

function struk_paper_width_css($paper_size)
{
    if ($paper_size === '80mm') {
        return '80mm';
    }
    if ($paper_size === 'a4') {
        return '190mm';
    }
    return '58mm';
}
