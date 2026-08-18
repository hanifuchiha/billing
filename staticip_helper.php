<?php
/**
 * Helper terpusat untuk fitur Customer/Paket Static IP.
 *
 * Static IP BUKAN tipe koneksi baru -- tetap PPPoE auth biasa dengan MODE
 * fleksibel penuh (API/RADIUS/MULTI), persis Customer PPPoE biasa. Yang
 * membedakan murni: pelanggan dapat 1 IP TETAP dari kolom `pelanggan.IP_STATIC`
 * (bukan dari PPP Pool dinamis Mikrotik), dialirkan ke `remote-address`
 * (API MODE) / `Framed-IP-Address` (RADIUS MODE, lihat radius_sync_lib.php).
 *
 * Kolom TIPE_LAYANAN di `pelanggan` & `paket` murni penanda pisah listing
 * (menu Customer/Paket Static IP vs PPPoE biasa) -- default 'PPPOE' supaya
 * data lama otomatis tetap tampil di listing PPPoE biasa.
 */

if (!function_exists('staticipEnsureSchema')) {
    function staticipEnsureSchema(mysqli $conn): void
    {
        static $ensured = false;
        if ($ensured || !$conn) {
            return;
        }
        $ensured = true;

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'TIPE_LAYANAN'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN TIPE_LAYANAN VARCHAR(20) NOT NULL DEFAULT 'PPPOE'");
        }
        $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'IP_STATIC'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN IP_STATIC VARCHAR(45) NULL DEFAULT NULL");
        }

        $col = @mysqli_query($conn, "SHOW COLUMNS FROM paket LIKE 'TIPE_LAYANAN'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE paket ADD COLUMN TIPE_LAYANAN VARCHAR(20) NOT NULL DEFAULT 'PPPOE'");
        }

        // Kolom NIK/wilayah -- sama seperti pelanggan PPPoE biasa (proses/addcustomer.php,
        // proses/editcustomer.php), dibutuhkan supaya form Customer Static IP setara.
        $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE 'NIK'");
        if ($col && mysqli_num_rows($col) === 0) {
            @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN NIK VARCHAR(20) DEFAULT '' AFTER IDPEL");
        }
        foreach (['provinsi', 'kabupaten', 'kecamatan', 'kelurahan'] as $wilayahCol) {
            $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE '$wilayahCol'");
            if ($col && mysqli_num_rows($col) === 0) {
                @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN `$wilayahCol` VARCHAR(100) DEFAULT ''");
            }
        }
        foreach (['rw', 'rt'] as $rwRtCol) {
            $col = @mysqli_query($conn, "SHOW COLUMNS FROM pelanggan LIKE '$rwRtCol'");
            if ($col && mysqli_num_rows($col) === 0) {
                @mysqli_query($conn, "ALTER TABLE pelanggan ADD COLUMN `$rwRtCol` VARCHAR(10) DEFAULT ''");
            }
        }

        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pool_staticip (
            id INT AUTO_INCREMENT PRIMARY KEY,
            PEMILIK VARCHAR(100) NOT NULL,
            AREA VARCHAR(100) NOT NULL,
            ip_awal VARCHAR(45) NOT NULL,
            ip_akhir VARCHAR(45) NOT NULL,
            gateway VARCHAR(45) DEFAULT '',
            subnet VARCHAR(20) DEFAULT '',
            keterangan VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('staticipAreaFilterSql')) {
    /**
     * Pola scoping ASSISTANT-aware standar (lihat dashboard.php / memory
     * project_systemic_area_scoping_bug) -- $ceknama (PEMILIK) sudah cukup
     * utk scoping tenant, tambahan ini mempersempit ke AREA yang di-assign
     * kalau sesi ini ASSISTANT.
     */
    function staticipAreaFilterSql(string $columnName, ?string $AKSES, ?string $area_list): string
    {
        if ($AKSES === 'ASSISTANT' && !empty($area_list)) {
            return " AND `$columnName` IN ($area_list)";
        }
        return '';
    }
}

if (!function_exists('staticipIpToLong')) {
    function staticipIpToLong(string $ip)
    {
        $ip = trim($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        return ip2long($ip);
    }
}

if (!function_exists('staticipListAvailableIps')) {
    /**
     * Hitung IP yang belum kepakai dalam 1 baris pool_staticip: seluruh range
     * dikurangi pelanggan.IP_STATIC yang sudah terpakai (PEMILIK+AREA sama).
     * Dihitung on-the-fly (bukan disimpan), aman terhadap pelanggan yang
     * dihapus/diedit langsung dari luar. Dibatasi $limit supaya range besar
     * tidak membebani halaman.
     */
    function staticipListAvailableIps(mysqli $conn, array $poolRow, int $limit = 500): array
    {
        $start = staticipIpToLong((string) $poolRow['ip_awal']);
        $end   = staticipIpToLong((string) $poolRow['ip_akhir']);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }

        $used = [];
        $pemilikEsc = mysqli_real_escape_string($conn, (string) $poolRow['PEMILIK']);
        $areaEsc    = mysqli_real_escape_string($conn, (string) $poolRow['AREA']);
        $q = mysqli_query($conn, "SELECT IP_STATIC FROM pelanggan WHERE PEMILIK='$pemilikEsc' AND AREA='$areaEsc' AND IP_STATIC IS NOT NULL AND IP_STATIC != ''");
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                $used[trim((string) $r['IP_STATIC'])] = true;
            }
        }

        $available = [];
        for ($long = $start; $long <= $end; $long++) {
            $ip = long2ip($long);
            if (!isset($used[$ip])) {
                $available[] = $ip;
                if (count($available) >= $limit) {
                    break;
                }
            }
        }
        return $available;
    }
}
