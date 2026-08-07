<?php
include '../../koneksidb.php';
require '../../radius_sync_lib.php';
require_once __DIR__ . '/tagihan_status_lib.php';

function convertToSeconds($uptime)
{
    if (is_numeric($uptime)) {
        return $uptime;
    }

    if (preg_match('/^(\d+)([a-zA-Z]+)$/', $uptime, $matches)) {
        $value = $matches[1];
        $unit = strtolower($matches[2]);

        switch ($unit) {
            case 'h':
                return $value * 3600;
            case 'd':
                return $value * 86400;
            case 'w':
                return $value * 604800;
            case 'm':
                return $value * 2592000;
            default:
                die("❌ Format waktu tidak dikenal!");
        }
    }

    die("❌ Format uptime tidak valid!");
}

echo "Memulai sinkronisasi FreeRADIUS untuk semua user...<br><br>";
echo "Mode: pengecekan selisih (diff) terhadap database pelanggan -- file users TIDAK dikosongkan.<br><br>";
echo "Status bayar/belum-bayar memakai logika yang SAMA dengan cek_tagihan_harian.php (tagihan_status_lib.php).<br><br>";

// $desired dikumpulkan dulu dari SEMUA owner/area, baru direkonsiliasi SEKALI
// di akhir. Ini penting: kalau dihitung per-owner lalu langsung ditulis, owner
// yang diproses belakangan bisa membuat owner yang sudah diproses duluan
// terlihat "tidak eligible lagi" dan malah terhapus.
$desired = [];

// Dimuat SEKALI untuk semua owner (bukan per-owner) -- sama seperti di awal
// cek_tagihan_harian.php. Dipakai untuk mengecualikan paket FREE/FASUM
// non-promo dari pengecekan tunggakan (pelanggan paket ini dianggap selalu
// "sudah bayar", sama seperti cek_tagihan_harian.php yang melewati mereka
// sepenuhnya dari enforcement).
[$hargaPaketMap, $fasumPaketList, $promoPaketIds] = tagihanLoadPaketMaps($conn);
$hari_ini_sync = date('Y-m-d');

// Pengaturan global panel FreeRADIUS (tab Default) -- dimuat SEKALI untuk
// seluruh run, bukan per-pelanggan. Dipakai bareng radiusBuildPppoeReplyAttrs()
// supaya atribut reply PPPoE (kalau paketnya diset RADIUS_LANGSUNG) identik
// dengan yang dihasilkan jalur manual (addcustomer.php, editcustomer.php, dst).
radiusEnsurePaketProfileSourceColumn($conn);
$radiusGlobalSettingsSync = radiusGetGlobalSettings($conn);
// Cache baris tabel `paket` per "PEMILIK|PAKET" supaya tidak query ulang untuk
// tiap pelanggan yang kebetulan satu paket yang sama.
$paketRowCacheSync = [];
function syncGetPaketRow($conn, string $pemilik, string $paket, array &$cache): array
{
    $key = $pemilik . '|' . $paket;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $q = mysqli_query($conn, "SELECT * FROM paket WHERE PAKET='" . mysqli_real_escape_string($conn, $paket) . "' AND PEMILIK='" . mysqli_real_escape_string($conn, $pemilik) . "' ORDER BY id DESC LIMIT 1");
    $row = ($q && mysqli_num_rows($q) > 0) ? mysqli_fetch_assoc($q) : ['PAKET' => $paket, 'KECEPATAN' => ''];
    $cache[$key] = $row;
    return $row;
}

// Loop semua user (owner)
$sql_all_users = "SELECT * FROM `user`";
$query_all_users = mysqli_query($conn, $sql_all_users);

while ($user_data = mysqli_fetch_array($query_all_users)) {
    $pemilik = $user_data['USERNAME'];
    $iduser = $user_data['id'];

    echo "Memproses user: $pemilik (ID: $iduser)<br>";

    // Path ke file JSON (nilai default SAMA dengan default di cek_tagihan_harian.php)
    $jsonFile = "../data/reminder-$pemilik.json";
    $jatuh_tempo = 25;

    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $data = json_decode($jsonData, true);

        if ($data !== null) {
            foreach ($data as $item) {
                if (isset($item['jatuh_tempo'])) $jatuh_tempo = (int) $item['jatuh_tempo'];
            }
            echo "Jatuh tempo dari JSON: $jatuh_tempo<br>";
        } else {
            echo "JSON tidak valid, menggunakan default jatuh tempo: $jatuh_tempo<br>";
        }
    } else {
        echo "File JSON tidak ditemukan, menggunakan default jatuh tempo: $jatuh_tempo<br>";
    }

    // Waktu tunggu (grace period) prabayar -- dipakai untuk mode monthversary,
    // sama seperti yang dimuat di cek_tagihan_harian.php.
    $prabayar_grace_period_sync = 2;
    $grace_period_file_sync = "../data/prabayar_grace_period-$pemilik.json";
    if (file_exists($grace_period_file_sync)) {
        $grace_data_sync = json_decode(file_get_contents($grace_period_file_sync), true);
        if (is_array($grace_data_sync) && isset($grace_data_sync['prabayar_grace_period'])) {
            $prabayar_grace_period_sync = (int) $grace_data_sync['prabayar_grace_period'];
        }
    }

    // Toggle "Monthversary ikut tanggal bayar terakhir" (Payment Setting) --
    // sama seperti yang dimuat di cek_tagihan_harian.php.
    $monthversary_follow_last_payment_sync = false;
    $monthversary_setting_file_sync = "../data/monthversary_setting-$pemilik.json";
    if (file_exists($monthversary_setting_file_sync)) {
        $monthversary_setting_data_sync = json_decode(file_get_contents($monthversary_setting_file_sync), true);
        if (is_array($monthversary_setting_data_sync) && isset($monthversary_setting_data_sync['follow_last_payment'])) {
            $monthversary_follow_last_payment_sync = !empty($monthversary_setting_data_sync['follow_last_payment']);
        }
    }

    echo "Sinkronisasi users FreeRADIUS untuk: $pemilik (Jatuh Tempo: $jatuh_tempo, Waktu Tunggu Prabayar: $prabayar_grace_period_sync hari)<br>";

    // Loop server langsung dari tabel server berdasarkan user_id
    $sql = "SELECT * FROM `server` WHERE `user_id` = '$iduser'";
    $query = mysqli_query($conn, $sql);
    $server_count = mysqli_num_rows($query);
    echo "Query server: $sql<br>";
    echo "Jumlah server ditemukan: $server_count<br>";

    if ($server_count == 0) {
        echo "Tidak ada server untuk pemilik $pemilik. Lewati.<br><br>";
        continue;
    }

    while ($data = mysqli_fetch_array($query)) {
        $AREA = $data['AREA'];
        $PEMILIK = $data['PEMILIK'];

        echo "<hr>Memproses server: $PEMILIK - $AREA<hr>";

        // Loop pelanggan -- dikumpulkan dulu ke array supaya status bayar bisa
        // dihitung secara bulk (getLastPaymentsBulk/getLastPaidUsageMapBulk),
        // sama seperti pola di cek_tagihan_harian.php.
        $sql1 = "SELECT * FROM `pelanggan` WHERE `PEMILIK` = '$PEMILIK' AND `AREA` = '$AREA'";
        $query1 = mysqli_query($conn, $sql1);
        $pelanggan_count = mysqli_num_rows($query1);
        echo "Query pelanggan: $sql1<br>";
        echo "Jumlah pelanggan di server $PEMILIK - $AREA: $pelanggan_count<br>";

        $pelangganServer = [];
        $customerIdsServer = [];
        while ($data1 = mysqli_fetch_array($query1)) {
            $pelangganServer[] = $data1;
            $customerIdsServer[] = (string) $data1['IDPEL'];
        }

        $lastPaymentMap = tagihanGetLastPaymentsBulk($conn, $customerIdsServer);
        $lastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, $customerIdsServer);

        foreach ($pelangganServer as $data1) {
            $IDPEL = $data1['IDPEL'];
            $NAMA = $data1['NAMA'];
            $PAKET = $data1['PAKET'];
            $HARGA = $data1['HARGA'];
            $MODE = $data1['MODE'];
            $TANGGALPASANG = $data1['TANGGALPASANG'];
            $TIPE_BAYAR = $data1['TIPE_BAYAR'];
            $TIPE_TEMPO = $data1['TIPE_TEMPO'];
            $TEMPO = $data1['TEMPO'];
            $PASSWORD = $data1['PASSWORD'];

            // Log lengkap per kolom (bukan digabung) supaya kelihatan pasti
            // kolom mana isinya apa langsung dari hasil query -- ini SUMBER
            // YANG SAMA PERSIS yang dipakai untuk keputusan valid/tidaknya mode,
            // jadi tidak ada ambiguitas dari tampilan grid phpMyAdmin yang lebar.
            echo "Memproses pelanggan: $IDPEL - $NAMA | PAKET='" . htmlspecialchars($PAKET) . "' | HARGA='" . htmlspecialchars($HARGA) . "' | MODE='" . htmlspecialchars($MODE) . "'<br>";

            // Validasi auth mode (hanya untuk keperluan sinkron ini, tidak ditulis ke DB).
            // Dibersihkan dulu dari spasi di awal/akhir, spasi ganda, dan
            // non-breaking space (\xC2\xA0, sering ikut kepencet saat copy-paste
            // dari Excel/Word/phpMyAdmin) SEBELUM dibandingkan -- supaya nilai
            // yang KELIHATANNYA benar di layar tidak gagal cocok gara-gara
            // karakter tak terlihat.
            $MODE_BERSIH = str_replace("\xC2\xA0", ' ', (string) $MODE);
            $MODE_BERSIH = trim(preg_replace('/\s+/', ' ', $MODE_BERSIH));
            $valid_modes = ['RADIUS MODE', 'API MODE', 'MULTI MODE'];
            $MODE_EFEKTIF = $MODE_BERSIH;
            if (!in_array($MODE_EFEKTIF, $valid_modes, true)) {
                echo "Mode tidak valid (raw='" . htmlspecialchars($MODE) . "', panjang=" . strlen((string) $MODE) . ", hex=" . bin2hex((string) $MODE) . "), dianggap sebagai: API MODE (tidak disinkron ke RADIUS)<br>";
                $MODE_EFEKTIF = 'API MODE';
            }

            if ($MODE_EFEKTIF !== 'RADIUS MODE' && $MODE_EFEKTIF !== 'MULTI MODE') {
                // Bukan mode RADIUS -> sengaja tidak dimasukkan ke $desired.
                // Kalau pelanggan ini sebelumnya RADIUS lalu MODE-nya diganti,
                // dia akan otomatis dihapus dari file users oleh reconcile
                // (karena hilang dari $desired), dan itu memang perilaku yang
                // benar (bereaksi terhadap perubahan database).
                continue;
            }

            // Paket FREE / FASUM non-promo: SAMA seperti cek_tagihan_harian.php,
            // pelanggan paket ini dilewati sepenuhnya dari enforcement tunggakan
            // (tidak pernah di-EXPIRED-kan karena belum bayar).
            $paketKeySync = strtolower(trim((string) $PAKET));
            $isFreePaket = (stripos($PAKET, 'FREE') !== false);
            $isFasumNonPromo = tagihanIsFasumNonPromo($paketKeySync, $fasumPaketList, $promoPaketIds);

            $ket = '';
            if ($isFreePaket || $isFasumNonPromo) {
                $sudah_bayar = true;
                echo "Paket FREE/FASUM non-promo ($PAKET), tidak dikenakan cek tunggakan -- dianggap sudah bayar.<br>";
            } else {
                $verdict = tagihanHitungStatus($conn, [
                    'IDPEL' => $IDPEL,
                    'PAKET' => $PAKET,
                    'TANGGALPASANG' => $TANGGALPASANG,
                    'TIPE_BAYAR' => $TIPE_BAYAR,
                    'TIPE_TEMPO' => $TIPE_TEMPO,
                    'TEMPO' => $TEMPO,
                    'TANGGAL_MONTHVERSARY' => $data1['TANGGAL_MONTHVERSARY'] ?? '',
                ], [
                    'hari_ini' => $hari_ini_sync,
                    'jatuh_tempo_hari' => $jatuh_tempo,
                    'lastPaymentMap' => $lastPaymentMap,
                    'lastPaidUsageMap' => $lastPaidUsageMap,
                    'prabayar_grace_period' => $prabayar_grace_period_sync,
                    'monthversary_follow_last_payment' => $monthversary_follow_last_payment_sync,
                ]);
                $sudah_bayar = $verdict['sudah_bayar'];
                $ket = $verdict['keterangan'] !== '' ? " ({$verdict['keterangan']})" : '';
            }
            echo "Status pembayaran untuk $IDPEL: " . ($sudah_bayar ? 'Sudah bayar' : 'Belum bayar') . $ket . "<br>";

            // PENTING: pelanggan yang eligible mode RADIUS/MULTI SELALU masuk
            // $desired, baik sudah bayar maupun belum. Bedanya hanya di grup:
            //   - sudah bayar   -> Mikrotik-Group = nama paket (akses normal)
            //   - belum bayar   -> Mikrotik-Group = "EXPIRED" (isolir, sama
            //     seperti konvensi yang sudah dipakai di proses/disablecustomer.php)
            // Ini menggantikan perilaku lama yang MENGHILANGKAN pelanggan dari
            // file users sama sekali kalau cek pembayaran meleset (mis. nama
            // bulan tidak cocok persis) -- yang jadi penyebab utama user RADIUS
            // "hilang tanpa sebab". Sekarang pelanggan tetap dikenal FreeRADIUS,
            // hanya profil-nya yang berubah sesuai status bayar.
            // Atribut reply dibangun terpusat lewat radiusBuildPppoeReplyAttrs()
            // (radius_sync_lib.php) -- kalau paket ini di-set RADIUS_PROFILE_SOURCE
            // = 'RADIUS_LANGSUNG' DAN toggle master di tab Default aktif, hasilnya
            // atribut lengkap (Service-Type, Framed-Protocol, Mikrotik-Rate-Limit,
            // Mikrotik-Address-List, Session-Timeout, Mikrotik-Group). Selain itu
            // (default), hasilnya identik dengan perilaku lama: cuma Mikrotik-Group.
            $paketRowSync = syncGetPaketRow($conn, $PEMILIK, $PAKET, $paketRowCacheSync);
            $desired[$IDPEL] = [
                'password' => $PASSWORD,
                'reply' => radiusBuildPppoeReplyAttrs($paketRowSync, $sudah_bayar, $radiusGlobalSettingsSync),
            ];
        }
    }

    // Sinkronisasi voucher hotspot yang masih ada timers
    $sql_voucher = "SELECT voucher, paket FROM voucher WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "'";
    $result_voucher = $conn->query($sql_voucher);
    while ($row_v = $result_voucher->fetch_assoc()) {
        $voucher = $row_v['voucher'];
        $paket_v = $row_v['paket'];
        $timer_file_v = "/etc/freeradius/user_timers/{$voucher}.json";
        if (!file_exists($timer_file_v)) {
            continue;
        }

        $timer_data = json_decode(file_get_contents($timer_file_v), true);
        if (!$timer_data || !isset($timer_data['session_timeout']) || !isset($timer_data['used_seconds'])) {
            echo "Timer file $timer_file_v invalid, skip<br>";
            continue;
        }

        $session_timeout = $timer_data['session_timeout'];
        $used_seconds = $timer_data['used_seconds'];
        if ($used_seconds >= $session_timeout) {
            echo "Voucher $voucher sudah expired, skip<br>";
            continue;
        }
        echo "Voucher $voucher masih aktif (used: $used_seconds, timeout: $session_timeout)<br>";

        $sql_paket_v = "SELECT ratelimit, uptime FROM paket_hotspot WHERE paket = '" . mysqli_real_escape_string($conn, $paket_v) . "'";
        $result_paket_v = $conn->query($sql_paket_v);
        if ($result_paket_v && $row_p = $result_paket_v->fetch_assoc()) {
            $ratelimit_v = $row_p['ratelimit'];
            $uptime_seconds_v = convertToSeconds($row_p['uptime']);
        } else {
            $ratelimit_v = '';
            $uptime_seconds_v = 0;
            echo "Paket $paket_v tidak ditemukan di paket_hotspot<br>";
        }

        $desired[$voucher] = [
            'password' => $voucher,
            'reply' => [
                'Mikrotik-Rate-Limit := "' . $ratelimit_v . '"',
                'Session-Timeout := ' . $uptime_seconds_v,
                'Mikrotik-Group := "' . $paket_v . '"',
                'Simultaneous-Use := 1',
            ],
        ];
    }

    echo "<br>";
}

echo "<hr>Total entri yang seharusnya ada di RADIUS saat ini: " . count($desired) . "<br>";
echo "Merekonsiliasi file users (tambah/update/hapus selisih saja, tanpa mengosongkan file)...<br>";

$result = radiusReconcileUsers($desired);

if (!empty($result['skipped_locked'])) {
    echo "⚠️ Dilewati: proses sync lain sedang berjalan (lock aktif). Coba lagi di siklus berikutnya.<br>";
} else {
    echo "Ditambah (pelanggan baru): " . count($result['added']) . " (" . htmlspecialchars(implode(', ', $result['added'])) . ")<br>";
    if (!empty($result['recovered'])) {
        echo "⚠️ DIPULIHKAN (seharusnya ada tapi sempat hilang dari file users): " . count($result['recovered']) . " (" . htmlspecialchars(implode(', ', $result['recovered'])) . ") -- cek /var/log/freeradius/sync-decisions.log untuk histori siapa saja yang pernah hilang.<br>";
    }
    echo "Diupdate: " . count($result['updated']) . " (" . htmlspecialchars(implode(', ', $result['updated'])) . ")<br>";
    echo "Dihapus: " . count($result['removed']) . " (" . htmlspecialchars(implode(', ', $result['removed'])) . ")<br>";

    if ($result['changed']) {
        radiusReloadIfChanged(true);
        echo "<br>✅ Ada perubahan, FreeRADIUS di-reload (bukan restart paksa, sesi yang sedang online tidak diputus).<br>";
    } else {
        echo "<br>Tidak ada perubahan, FreeRADIUS tidak perlu di-reload.<br>";
    }
}

echo "<br>Sinkronisasi selesai.<br>";
