<?php
// Warna tema (--primary-color/--secondary-color) per akun, di-derive dari logo
// masing-masing akun (bukan config.json global lagi). Dihitung SEKALI di server
// (GD) saat logo di-upload lalu disimpan ke settings/logo-colors-{username}.json
// -- supaya selalu ada nilai pasti & konsisten (tidak bergantung ekstraksi
// canvas di browser tiap page-load, yang rawan gagal karena CORS/timing).

if (!function_exists('logoColorSettingsFile')) {
    function logoColorSettingsFile($username)
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
        if ($safe === '') {
            return null;
        }
        return __DIR__ . '/settings/logo-colors-' . $safe . '.json';
    }
}

if (!function_exists('logoColorGetSaved')) {
    function logoColorGetSaved($username)
    {
        $file = logoColorSettingsFile($username);
        if (!$file || !is_file($file)) {
            return null;
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded) || empty($decoded['primary']) || empty($decoded['secondary'])) {
            return null;
        }
        return ['primary' => (string)$decoded['primary'], 'secondary' => (string)$decoded['secondary']];
    }
}

if (!function_exists('logoColorSave')) {
    function logoColorSave($username, $primaryHex, $secondaryHex)
    {
        $file = logoColorSettingsFile($username);
        if (!$file || !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$primaryHex) || !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$secondaryHex)) {
            return false;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return @file_put_contents($file, json_encode(['primary' => $primaryHex, 'secondary' => $secondaryHex], JSON_PRETTY_PRINT)) !== false;
    }
}

if (!function_exists('logoColorExtractFromImage')) {
    // Sampling warna dominan dari file gambar pakai GD -- mirror algoritma
    // analyzeImageColors() di header.php (skip pixel nyaris putih/hitam/abu-abu,
    // ambil 2 warna paling sering muncul di antara pixel yang cukup saturasi).
    function logoColorExtractFromImage($imagePath)
    {
        if (!is_file($imagePath) || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $data = @file_get_contents($imagePath);
        if ($data === false) {
            return null;
        }
        $img = @imagecreatefromstring($data);
        if ($img === false) {
            return null;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        $sampleW = max(1, min($width, 100));
        $sampleH = max(1, min($height, 100));
        $resized = imagecreatetruecolor($sampleW, $sampleH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $sampleW, $sampleH, $width, $height);

        $colorCounts = [];
        for ($y = 0; $y < $sampleH; $y++) {
            for ($x = 0; $x < $sampleW; $x++) {
                $rgba = imagecolorat($resized, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F; // GD alpha: 0 (opaque) .. 127 (transparan penuh)
                if ($alpha > 63) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max > 240 && $min > 240) {
                    continue; // nyaris putih
                }
                if ($max < 15) {
                    continue; // nyaris hitam
                }
                if (($max - $min) <= 30) {
                    continue; // kurang saturasi (abu-abu)
                }
                $key = $r . ',' . $g . ',' . $b;
                $colorCounts[$key] = ($colorCounts[$key] ?? 0) + 1;
            }
        }
        imagedestroy($img);
        imagedestroy($resized);

        if (empty($colorCounts)) {
            return null;
        }
        arsort($colorCounts);
        $top = array_slice(array_keys($colorCounts), 0, 2);
        $hexes = array_map(function ($c) {
            $parts = array_map('intval', explode(',', $c));
            return sprintf('#%02x%02x%02x', $parts[0], $parts[1], $parts[2]);
        }, $top);

        $primary = $hexes[0] ?? null;
        $secondary = $hexes[1] ?? $primary;
        if (!$primary) {
            return null;
        }
        return ['primary' => $primary, 'secondary' => $secondary];
    }
}

if (!function_exists('logoColorExtractAndSave')) {
    // Helper gabungan: extract dari file logo lalu langsung simpan. Dipanggil
    // dari upload.php setelah logo baru berhasil disimpan.
    function logoColorExtractAndSave($username, $imagePath)
    {
        $colors = logoColorExtractFromImage($imagePath);
        if (!$colors) {
            return false;
        }
        return logoColorSave($username, $colors['primary'], $colors['secondary']);
    }
}
