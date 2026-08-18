<?php
// Shared logic for the Reseller assistant type: schema self-heal, package
// allow-list/price-filter lookups, and the bandwidth billing burden used on
// the reseller dashboard card. See migrations/2026-07-22_add_reseller.sql
// for the schema this mirrors.

if (!function_exists('reseller_bootstrap_schema')) {
    function reseller_bootstrap_schema($conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $columns = [
            'assistant_role' => "ALTER TABLE `user` ADD COLUMN `assistant_role` VARCHAR(30) NOT NULL DEFAULT 'assistant'",
            'reseller_price_filter_enabled' => "ALTER TABLE `user` ADD COLUMN `reseller_price_filter_enabled` TINYINT(1) NOT NULL DEFAULT 0",
            'reseller_bw_cost' => "ALTER TABLE `user` ADD COLUMN `reseller_bw_cost` DECIMAL(15,2) NOT NULL DEFAULT 0",
            'reseller_bw_ppn_percent' => "ALTER TABLE `user` ADD COLUMN `reseller_bw_ppn_percent` DECIMAL(5,2) NOT NULL DEFAULT 11",
            'reseller_bw_bhp_uso' => "ALTER TABLE `user` ADD COLUMN `reseller_bw_bhp_uso` DECIMAL(15,2) NOT NULL DEFAULT 0",
            // Skema beban reseller: 'bandwidth' (nominal tetap, pola lama) atau
            // 'omset_percent' (persentase dari omset kotor bulan berjalan).
            'reseller_cost_scheme' => "ALTER TABLE `user` ADD COLUMN `reseller_cost_scheme` VARCHAR(20) NOT NULL DEFAULT 'bandwidth'",
            'reseller_omset_percent' => "ALTER TABLE `user` ADD COLUMN `reseller_omset_percent` DECIMAL(5,2) NOT NULL DEFAULT 0",
        ];
        foreach ($columns as $col => $alterSql) {
            $check = @mysqli_query($conn, "SHOW COLUMNS FROM `user` LIKE '$col'");
            if ($check && mysqli_num_rows($check) === 0) {
                @mysqli_query($conn, $alterSql);
            }
        }

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `reseller_paket_price` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `reseller_user_id` INT NOT NULL,
            `paket_type` ENUM('broadband','hotspot') NOT NULL,
            `paket_id` INT NOT NULL,
            `paket_nama` VARCHAR(255) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `custom_harga` DECIMAL(15,2) DEFAULT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_reseller_paket` (`reseller_user_id`,`paket_type`,`paket_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
    }
}

if (!function_exists('reseller_get_settings')) {
    function reseller_get_settings($conn, $user_id)
    {
        $user_id = (int)$user_id;
        $defaults = [
            'assistant_role' => 'assistant',
            'price_filter_enabled' => 0,
            'bw_cost' => 0.0,
            'bw_ppn_percent' => 11.0,
            'bw_bhp_uso' => 0.0,
            'cost_scheme' => 'bandwidth',
            'omset_percent' => 0.0,
        ];

        $q = mysqli_query($conn, "SELECT assistant_role, reseller_price_filter_enabled, reseller_bw_cost, reseller_bw_ppn_percent, reseller_bw_bhp_uso, reseller_cost_scheme, reseller_omset_percent FROM `user` WHERE id = $user_id LIMIT 1");
        if (!$q || mysqli_num_rows($q) === 0) {
            return $defaults;
        }
        $row = mysqli_fetch_assoc($q);
        $scheme = $row['reseller_cost_scheme'] ?? 'bandwidth';
        if (!in_array($scheme, ['bandwidth', 'omset_percent'], true)) {
            $scheme = 'bandwidth';
        }
        return [
            'assistant_role' => $row['assistant_role'] !== null && $row['assistant_role'] !== '' ? $row['assistant_role'] : 'assistant',
            'price_filter_enabled' => (int)$row['reseller_price_filter_enabled'],
            'bw_cost' => (float)$row['reseller_bw_cost'],
            'bw_ppn_percent' => (float)$row['reseller_bw_ppn_percent'],
            'bw_bhp_uso' => (float)$row['reseller_bw_bhp_uso'],
            'cost_scheme' => $scheme,
            'omset_percent' => (float)($row['reseller_omset_percent'] ?? 0),
        ];
    }
}

if (!function_exists('reseller_bandwidth_burden')) {
    // Beban = (biaya bandwidth + BHP USO) dikenakan PPN di atasnya.
    // Dipakai langsung kalau skema = 'bandwidth' (pola lama, nominal tetap).
    function reseller_bandwidth_burden($settings)
    {
        $cost = (float)($settings['bw_cost'] ?? 0);
        $bhpUso = (float)($settings['bw_bhp_uso'] ?? 0);
        $ppnPercent = (float)($settings['bw_ppn_percent'] ?? 0);
        return ($cost + $bhpUso) * (1 + ($ppnPercent / 100));
    }
}

if (!function_exists('reseller_cost_burden')) {
    // Beban reseller sesuai skema yang dipilih:
    // - 'bandwidth'     : nominal tetap, lihat reseller_bandwidth_burden().
    // - 'omset_percent' : persentase dari $omset (omset kotor periode berjalan,
    //                     sudah di-scope AREA milik reseller tsb -- lihat caller).
    function reseller_cost_burden($settings, $omset = 0.0)
    {
        $scheme = $settings['cost_scheme'] ?? 'bandwidth';
        if ($scheme === 'omset_percent') {
            $percent = (float)($settings['omset_percent'] ?? 0);
            return (float)$omset * ($percent / 100);
        }
        return reseller_bandwidth_burden($settings);
    }
}

if (!function_exists('reseller_omset_bulan_ini')) {
    // Omset kotor bulan berjalan (transaksi BERHASIL) utk pelanggan di AREA yang
    // di-assign lewat $server_json (kolom `server` milik akun reseller). Dipakai
    // skema beban 'omset_percent' -- dipanggil dari list ASSISTANT di user.php.
    function reseller_omset_bulan_ini($conn, $server_json)
    {
        $area_ids = json_decode((string)$server_json, true);
        if (!is_array($area_ids) || count($area_ids) === 0) {
            return 0.0;
        }
        $id_in = implode(",", array_map('intval', $area_ids));
        $arealist = [];
        $query_area = mysqli_query($conn, "SELECT AREA FROM server WHERE id IN ($id_in)");
        if ($query_area) {
            while ($row_area = mysqli_fetch_assoc($query_area)) {
                if (!empty($row_area['AREA'])) {
                    $arealist[] = $row_area['AREA'];
                }
            }
        }
        $arealist = array_unique(array_filter($arealist));
        if (count($arealist) === 0) {
            return 0.0;
        }
        $area_list = "'" . implode("','", array_map('addslashes', $arealist)) . "'";

        $pemilik_ids = [];
        $query_pemilik = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($query_pemilik) {
            while ($row_pemilik = mysqli_fetch_assoc($query_pemilik)) {
                $pemilik_ids[] = "'" . $row_pemilik['PEMILIK'] . "'";
            }
        }
        $pemilik_list = count($pemilik_ids) > 0 ? implode(",", $pemilik_ids) : "''";

        $awal_bulan_ini = date('Y-m-01');
        $akhir_bulan_ini = date('Y-m-t');
        $sql = "SELECT SUM(t.HARGA) as total FROM transaksi t
            INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
            WHERE t.STATUS = 'BERHASIL'
              AND DATE(t.TANGGALBAYAR) >= '$awal_bulan_ini' AND DATE(t.TANGGALBAYAR) <= '$akhir_bulan_ini'
              AND p.PEMILIK IN ($pemilik_list) AND p.AREA IN ($area_list)";
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return 0.0;
        }
        $row = mysqli_fetch_assoc($result);
        return (float)($row['total'] ?? 0);
    }
}

if (!function_exists('reseller_table_columns')) {
    function reseller_table_columns($type)
    {
        if ($type === 'hotspot') {
            return ['table' => 'paket_hotspot', 'paket' => 'paket', 'pemilik' => 'pemilik', 'harga' => 'harga', 'area' => 'area'];
        }
        return ['table' => 'paket', 'paket' => 'PAKET', 'pemilik' => 'PEMILIK', 'harga' => 'HARGA', 'area' => 'AREA'];
    }
}

if (!function_exists('reseller_resolve_area_names')) {
    // Sama seperti pembangunan $arealist di cek-sesi.php: dari daftar id server
    // (JSON kolom `server` milik akun assistant/reseller) menjadi nama AREA.
    // Dipakai supaya modal filter harga hanya menampilkan paket yang benar-benar
    // bisa dilihat reseller ini (packages.php memfilter berdasarkan AREA, bukan
    // seluruh server milik akun utama).
    function reseller_resolve_area_names($conn, $server_ids_json)
    {
        $areas = [];
        $server_ids = json_decode((string)$server_ids_json, true);
        if (is_array($server_ids) && count($server_ids) > 0) {
            $idIn = implode(',', array_map('intval', $server_ids));
            $q = mysqli_query($conn, "SELECT AREA FROM server WHERE id IN ($idIn)");
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    if (!empty($r['AREA'])) {
                        $areas[] = $r['AREA'];
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($areas)));
    }
}

if (!function_exists('reseller_get_paket_rows')) {
    // Paket yang ada di area-area yang sudah diizinkan (Product Diizinkan) untuk
    // reseller ini, digabung dengan status enabled/custom_harga yang sudah
    // tersimpan. Sengaja dibatasi ke area yang sama dengan yang dipakai
    // packages.php saat reseller login, supaya tidak ada paket yang bisa
    // di-enable di modal tapi tetap tidak muncul karena di luar area akses reseller.
    function reseller_get_paket_rows($conn, $area_names, $reseller_user_id, $type)
    {
        $reseller_user_id = (int)$reseller_user_id;
        $cols = reseller_table_columns($type);

        $rows = [];
        if (!empty($area_names)) {
            $areaList = "'" . implode("','", array_map(function ($a) use ($conn) {
                return mysqli_real_escape_string($conn, $a);
            }, $area_names)) . "'";

            $q2 = mysqli_query($conn, "SELECT * FROM `{$cols['table']}` WHERE `{$cols['area']}` IN ($areaList) ORDER BY `{$cols['paket']}`");
            if ($q2) {
                while ($row = mysqli_fetch_assoc($q2)) {
                    $rows[(int)$row['id']] = [
                        'id' => (int)$row['id'],
                        'nama' => $row[$cols['paket']],
                        'area' => $row[$cols['area']] ?? '',
                        'harga' => $row[$cols['harga']],
                        'enabled' => 0,
                        'custom_harga' => '',
                    ];
                }
            }
        }

        $typeEsc = mysqli_real_escape_string($conn, $type);
        $q3 = mysqli_query($conn, "SELECT paket_id, enabled, custom_harga FROM reseller_paket_price WHERE reseller_user_id=$reseller_user_id AND paket_type='$typeEsc'");
        if ($q3) {
            while ($r = mysqli_fetch_assoc($q3)) {
                $pid = (int)$r['paket_id'];
                if (isset($rows[$pid])) {
                    $rows[$pid]['enabled'] = (int)$r['enabled'];
                    $rows[$pid]['custom_harga'] = $r['custom_harga'];
                }
            }
        }

        return array_values($rows);
    }
}

if (!function_exists('reseller_filter_rows')) {
    // Menyaring array baris paket (hasil mysqli_fetch_assoc) agar hanya
    // menyisakan paket yang di-allow-list untuk reseller yang sedang login,
    // sekaligus menimpa kolom harga dengan custom_harga bila di-set.
    // Baris dikembalikan apa adanya jika sesi ini bukan reseller dengan
    // filter harga aktif.
    function reseller_filter_rows($conn, $rows, $type)
    {
        global $is_reseller, $reseller_price_filter_enabled, $reseller_id;

        if (empty($is_reseller) || empty($reseller_price_filter_enabled) || empty($reseller_id)) {
            return $rows;
        }

        $cols = reseller_table_columns($type);
        $rid = (int)$reseller_id;
        $typeEsc = mysqli_real_escape_string($conn, $type);

        $overrides = [];
        $q = mysqli_query($conn, "SELECT paket_id, enabled, custom_harga FROM reseller_paket_price WHERE reseller_user_id=$rid AND paket_type='$typeEsc'");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $overrides[(int)$r['paket_id']] = $r;
            }
        }

        $filtered = [];
        foreach ($rows as $row) {
            $pid = (int)($row['id'] ?? 0);
            if (!isset($overrides[$pid]) || (int)$overrides[$pid]['enabled'] !== 1) {
                continue;
            }
            if ($overrides[$pid]['custom_harga'] !== null && $overrides[$pid]['custom_harga'] !== '') {
                $row[$cols['harga']] = $overrides[$pid]['custom_harga'];
            }
            $filtered[] = $row;
        }
        return $filtered;
    }
}

if (!function_exists('reseller_collect_rows')) {
    function reseller_collect_rows($result)
    {
        $rows = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

if (!function_exists('paketDiskonPermanenEnsureColumns')) {
    /**
     * Bootstrap kolom `paket.DISKON_PERMANEN_TYPE` / `DISKON_PERMANEN_NILAI`:
     * diskon permanen yang melekat ke PAKET itu sendiri (bukan per-pelanggan
     * dan tanpa batas periode seperti `diskon_pelanggan`) -- otomatis memotong
     * HARGA setiap kali invoice dibuat untuk pelanggan yang memakai paket ini,
     * selama masih memakai paket tsb. Default '' / 0 supaya paket lama tidak
     * berubah perilaku sampai admin sengaja mengisi diskonnya.
     */
    function paketDiskonPermanenEnsureColumns($conn): void
    {
        static $checkedThisRequest = false;
        if ($checkedThisRequest || !($conn instanceof mysqli)) {
            return;
        }
        $checkedThisRequest = true;

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM paket LIKE 'DISKON_PERMANEN_TYPE'");
        if (!$col || mysqli_num_rows($col) === 0) {
            mysqli_query($conn, "ALTER TABLE paket ADD COLUMN DISKON_PERMANEN_TYPE ENUM('','nominal','persentase') NOT NULL DEFAULT ''");
        }
        $col2 = @mysqli_query($conn, "SHOW COLUMNS FROM paket LIKE 'DISKON_PERMANEN_NILAI'");
        if (!$col2 || mysqli_num_rows($col2) === 0) {
            mysqli_query($conn, "ALTER TABLE paket ADD COLUMN DISKON_PERMANEN_NILAI DECIMAL(18,2) NOT NULL DEFAULT 0");
        }
    }
}

if (!function_exists('paketDiskonPermanenTerapkan')) {
    // Potong $harga sesuai DISKON_PERMANEN_TYPE/NILAI paket ini. Persentase
    // di-clamp 0-100, hasil akhir tidak pernah negatif.
    function paketDiskonPermanenTerapkan(float $harga, ?string $tipe, $nilai): float
    {
        $nilai = (float)$nilai;
        if ($tipe === 'nominal' && $nilai > 0) {
            return max(0.0, $harga - $nilai);
        }
        if ($tipe === 'persentase' && $nilai > 0) {
            $persen = min(100.0, $nilai);
            return max(0.0, $harga - ($harga * $persen / 100));
        }
        return $harga;
    }
}

if (!function_exists('reseller_effective_harga')) {
    // Sama seperti lookup "SELECT HARGA FROM paket WHERE PAKET=... AND PEMILIK=..."
    // yang dipakai di banyak titik generate invoice/transaksi, ditambah
    // override harga khusus reseller bila sesi ini reseller dengan filter aktif,
    // dan diskon permanen per paket (DISKON_PERMANEN_TYPE/NILAI) di titik akhir
    // -- supaya berlaku otomatis ke SEMUA invoice generator tanpa perlu ubah
    // satu-satu (create_invoice_pelanggan.php, manual_generate_invoice.php,
    // invoice_generator_penagihan*.php).
    function reseller_effective_harga($conn, $paket_nama, $pemilik, $type = 'broadband')
    {
        $cols = reseller_table_columns($type);
        $namaEsc = mysqli_real_escape_string($conn, $paket_nama);
        $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

        if ($type === 'broadband') {
            paketDiskonPermanenEnsureColumns($conn);
        }
        $diskonCols = $type === 'broadband' ? ", DISKON_PERMANEN_TYPE, DISKON_PERMANEN_NILAI" : '';

        $base = 0.0;
        $paket_id = 0;
        $diskonType = '';
        $diskonNilai = 0.0;
        $q = mysqli_query($conn, "SELECT id, CAST(COALESCE(NULLIF(`{$cols['harga']}`, ''), '0') AS DECIMAL(18,2)) AS HARGA$diskonCols FROM `{$cols['table']}` WHERE `{$cols['paket']}`='$namaEsc' AND `{$cols['pemilik']}`='$pemilikEsc' ORDER BY id DESC LIMIT 1");
        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $base = (float)$row['HARGA'];
            $paket_id = (int)$row['id'];
            $diskonType = (string)($row['DISKON_PERMANEN_TYPE'] ?? '');
            $diskonNilai = (float)($row['DISKON_PERMANEN_NILAI'] ?? 0);
        }

        $harga = $base;

        global $is_reseller, $reseller_price_filter_enabled, $reseller_id;
        if (!empty($is_reseller) && !empty($reseller_price_filter_enabled) && !empty($reseller_id) && $paket_id > 0) {
            $rid = (int)$reseller_id;
            $typeEsc = mysqli_real_escape_string($conn, $type);
            $ov = mysqli_query($conn, "SELECT custom_harga FROM reseller_paket_price WHERE reseller_user_id=$rid AND paket_type='$typeEsc' AND paket_id=$paket_id AND enabled=1 LIMIT 1");
            if ($ov && mysqli_num_rows($ov) > 0) {
                $ovRow = mysqli_fetch_assoc($ov);
                if ($ovRow['custom_harga'] !== null && $ovRow['custom_harga'] !== '') {
                    $harga = (float)$ovRow['custom_harga'];
                }
            }
        }

        return paketDiskonPermanenTerapkan($harga, $diskonType, $diskonNilai);
    }
}
