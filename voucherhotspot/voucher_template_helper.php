<?php
// Helper terpusat utk template voucher custom (hasil drag-and-drop editor di
// voucher_template_builder.php). Disimpan per akun OWNER (username CRM), 1
// file JSON berisi array semua template milik akun itu, di
// settings/voucher_templates/{username}.json -- pola sama dgn portal_links_helper.php.

function voucher_template_safe_username($username)
{
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $username);
}

function voucher_template_file_path($username)
{
    $safe = voucher_template_safe_username($username);
    if ($safe === '') {
        return null;
    }
    return __DIR__ . '/../settings/voucher_templates/' . $safe . '.json';
}

// Tipe elemen yang boleh ada di kanvas -- dibatasi (whitelist) supaya render
// di voucher-desain.php tidak perlu percaya isi JSON mentah dari client.
function voucher_template_allowed_element_types()
{
    return ['logo', 'text', 'username', 'password', 'paket', 'harga', 'uptime', 'qrcode', 'nocs', 'login', 'shape'];
}

function get_voucher_templates($username)
{
    $file = voucher_template_file_path($username);
    if ($file === null || !is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_voucher_template_by_id($username, $id)
{
    $templates = get_voucher_templates($username);
    foreach ($templates as $tpl) {
        if (($tpl['id'] ?? '') === $id) {
            return $tpl;
        }
    }
    return null;
}

// Sanitasi 1 elemen kanvas: hanya field yang dikenal, tipe & angka divalidasi.
function voucher_template_sanitize_element($el)
{
    if (!is_array($el)) return null;
    $type = (string)($el['type'] ?? '');
    if (!in_array($type, voucher_template_allowed_element_types(), true)) {
        return null;
    }
    $clampNum = function ($v, $default = 0, $min = -2000, $max = 4000) {
        $n = is_numeric($v) ? (float)$v : $default;
        return max($min, min($max, $n));
    };
    return [
        'id' => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($el['id'] ?? uniqid('el_'))),
        'type' => $type,
        'x' => $clampNum($el['x'] ?? 0),
        'y' => $clampNum($el['y'] ?? 0),
        'w' => $clampNum($el['w'] ?? 100, 100, 5, 4000),
        'h' => $clampNum($el['h'] ?? 30, 30, 5, 4000),
        'z' => (int)$clampNum($el['z'] ?? 1, 1, 0, 999),
        'text' => mb_substr(trim((string)($el['text'] ?? '')), 0, 200),
        'fontSize' => (int)$clampNum($el['fontSize'] ?? 14, 14, 6, 96),
        'color' => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($el['color'] ?? '')) ? $el['color'] : '#000000',
        'bgColor' => (isset($el['bgColor']) && ($el['bgColor'] === 'transparent' || preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)$el['bgColor']))) ? $el['bgColor'] : 'transparent',
        'bold' => !empty($el['bold']),
        'italic' => !empty($el['italic']),
        'align' => in_array(($el['align'] ?? 'left'), ['left', 'center', 'right'], true) ? $el['align'] : 'left',
        'radius' => (int)$clampNum($el['radius'] ?? 0, 0, 0, 200),
        'rotate' => (int)$clampNum($el['rotate'] ?? 0, 0, -180, 180),
    ];
}

function save_voucher_template($username, array $template)
{
    $file = voucher_template_file_path($username);
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

    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($template['id'] ?? '')) ?: uniqid('tpl_');
    $name = mb_substr(trim((string)($template['name'] ?? 'Template Tanpa Nama')), 0, 60);
    $canvasW = max(120, min(1200, (int)($template['canvas']['w'] ?? 320)));
    $canvasH = max(80, min(1200, (int)($template['canvas']['h'] ?? 190)));
    $canvasBg = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string)($template['canvas']['bg'] ?? '')) ? $template['canvas']['bg'] : '#ffffff';

    $elements = [];
    if (is_array($template['elements'] ?? null)) {
        foreach ($template['elements'] as $el) {
            $clean = voucher_template_sanitize_element($el);
            if ($clean !== null) {
                $elements[] = $clean;
            }
        }
    }
    // Batasi jumlah elemen per template supaya file JSON tidak bisa dibengkakkan.
    $elements = array_slice($elements, 0, 60);

    $cleanTemplate = [
        'id' => $id,
        'name' => $name,
        'canvas' => ['w' => $canvasW, 'h' => $canvasH, 'bg' => $canvasBg],
        'elements' => $elements,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $templates = get_voucher_templates($username);
    $found = false;
    foreach ($templates as $i => $tpl) {
        if (($tpl['id'] ?? '') === $id) {
            $templates[$i] = $cleanTemplate;
            $found = true;
            break;
        }
    }
    if (!$found) {
        // Batasi jumlah template per akun.
        if (count($templates) >= 30) {
            return false;
        }
        $templates[] = $cleanTemplate;
    }

    $ok = @file_put_contents($file, json_encode($templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    return $ok ? $id : false;
}

function delete_voucher_template($username, $id)
{
    $file = voucher_template_file_path($username);
    if ($file === null || !is_file($file)) {
        return false;
    }
    $templates = get_voucher_templates($username);
    $newList = array_values(array_filter($templates, function ($tpl) use ($id) {
        return ($tpl['id'] ?? '') !== $id;
    }));
    if (count($newList) === count($templates)) {
        return false; // tidak ketemu
    }
    return @file_put_contents($file, json_encode($newList, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}
