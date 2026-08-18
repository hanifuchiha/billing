<?php
// Visibilitas KATALOG paket (broadband & hotspot) per akun ASSISTANT -- murni
// soal tampil/sembunyi paket sebagai PILIHAN di dropdown/katalog/filter, TIDAK
// menyentuh harga (beda dari reseller_paket_price di reseller_helper.php yang
// juga override custom_harga). Berlaku untuk SEMUA assistant_role (assistant,
// assistant_teknisi, reseller, mitra_isp) -- reseller_paket_price cuma untuk
// reseller/mitra_isp. Dua sistem ini SENGAJA independen, jangan digabung.

if (!function_exists('paketVisibilityBootstrapSchema')) {
    function paketVisibilityBootstrapSchema($conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $columns = [
            'hidden_paket_broadband' => "ALTER TABLE `user` ADD COLUMN `hidden_paket_broadband` TEXT NULL",
            'hidden_paket_hotspot' => "ALTER TABLE `user` ADD COLUMN `hidden_paket_hotspot` TEXT NULL",
            'hidden_paket_corporate' => "ALTER TABLE `user` ADD COLUMN `hidden_paket_corporate` TEXT NULL",
        ];
        foreach ($columns as $col => $alterSql) {
            $check = @mysqli_query($conn, "SHOW COLUMNS FROM `user` LIKE '$col'");
            if ($check && mysqli_num_rows($check) === 0) {
                @mysqli_query($conn, $alterSql);
            }
        }
    }
}

if (!function_exists('paketVisibilityTableColumns')) {
    // Sengaja diduplikasi dari reseller_table_columns() (reseller_helper.php),
    // bukan reuse -- modul ini independen dari fitur reseller.
    function paketVisibilityTableColumns($type)
    {
        if ($type === 'hotspot') {
            return ['table' => 'paket_hotspot', 'paket' => 'paket', 'pemilik' => 'pemilik'];
        }
        if ($type === 'corporate') {
            return ['table' => 'paket_corporate', 'paket' => 'PAKET', 'pemilik' => 'PEMILIK'];
        }
        // 'broadband' juga mencakup Customer Static IP -- satu tabel `paket` yang
        // sama, cuma dibedakan kolom TIPE_LAYANAN, jadi tidak perlu tipe terpisah.
        return ['table' => 'paket', 'paket' => 'PAKET', 'pemilik' => 'PEMILIK'];
    }
}

if (!function_exists('paketVisibilityAllNames')) {
    // Semua nama paket (broadband/hotspot) milik satu PEMILIK (owner) -- dipakai
    // di user.php untuk render grid checkbox & menghitung selisih (paket yang
    // TIDAK dicentang = hidden) saat form disimpan.
    function paketVisibilityAllNames($conn, $pemilikUsername, $type = 'broadband')
    {
        $cols = paketVisibilityTableColumns($type);
        $pemilikEsc = mysqli_real_escape_string($conn, (string)$pemilikUsername);
        $names = [];
        $q = mysqli_query($conn, "SELECT DISTINCT `{$cols['paket']}` AS nama FROM `{$cols['table']}` WHERE `{$cols['pemilik']}`='$pemilikEsc' ORDER BY `{$cols['paket']}` ASC");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                if (isset($r['nama']) && $r['nama'] !== '') {
                    $names[] = (string)$r['nama'];
                }
            }
        }
        return $names;
    }
}

if (!function_exists('paketVisibilityGetHiddenNames')) {
    // Daftar nama paket yang di-hide untuk SATU akun assistant (by user.id --
    // konsisten dengan $reseller_id di reseller_helper.php, BUKAN $current_user_id
    // yang untuk sesi ASSISTANT menunjuk ke id akun OWNER, bukan assistant itu
    // sendiri -- lihat memory project_systemic_area_scoping_bug).
    function paketVisibilityGetHiddenNames($conn, $assistantUserId, $type = 'broadband')
    {
        $col = 'hidden_paket_broadband';
        if ($type === 'hotspot') {
            $col = 'hidden_paket_hotspot';
        } elseif ($type === 'corporate') {
            $col = 'hidden_paket_corporate';
        }
        $id = (int)$assistantUserId;
        if ($id <= 0) {
            return [];
        }
        $q = mysqli_query($conn, "SELECT `$col` FROM `user` WHERE id=$id LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            return [];
        }
        $row = mysqli_fetch_assoc($q);
        $decoded = json_decode((string)($row[$col] ?? ''), true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}

if (!function_exists('paketVisibilityFilterRows')) {
    // Buang baris (hasil mysqli_fetch_assoc, dari SUMBER MANAPUN -- paket,
    // pelanggan, transaksi, dll -- asal ada kolom nama paketnya) yang namanya
    // ada di daftar hidden. No-op (return apa adanya) kalau $hiddenNames kosong
    // -- aman dipanggil dari mana saja tanpa if/else khusus, sama seperti pola
    // reseller_filter_rows().
    function paketVisibilityFilterRows(array $rows, array $hiddenNames, string $nameKey = 'PAKET'): array
    {
        if (count($hiddenNames) === 0) {
            return $rows;
        }
        return array_values(array_filter($rows, function ($row) use ($hiddenNames, $nameKey) {
            $nama = (string)($row[$nameKey] ?? '');
            return !in_array($nama, $hiddenNames, true);
        }));
    }
}

if (!function_exists('paketVisibilityIsHidden')) {
    function paketVisibilityIsHidden(string $paketNama, array $hiddenNames): bool
    {
        return in_array($paketNama, $hiddenNames, true);
    }
}
