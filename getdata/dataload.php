<?php
require('../koneksibilling.php');
require('../routeros_api.class.php');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pastikan path absolut
$base_dir = realpath(__DIR__ . '/../');
$serverlog_dir = $base_dir . '/serverlog';
$notifbot_data_dir = $base_dir . '/notifbot/data';

$API = new RouterosAPI();






$statusPelanggan = [];
// simpan semua username online
$allOnlineUsers = [];

$sql_area = "SELECT DISTINCT PEMILIK, AREA FROM pelanggan WHERE PEMILIK != '' AND AREA != ''";
$query_area = mysqli_query($conn, $sql_area);

if ($query_area) {
    while ($row_area = mysqli_fetch_assoc($query_area)) {
        $pemilik = $row_area['PEMILIK'];
        $area    = $row_area['AREA'];
        $sql_server = "SELECT * FROM server WHERE AREA='$area' AND PEMILIK='$pemilik' LIMIT 1";
        $result_server = mysqli_query($conn, $sql_server);

        if ($srv = mysqli_fetch_assoc($result_server)) {
            $ip       = $srv['IP'];
            $user     = $srv['PEMILIK'];
            $password = $srv['PASSWORD'];

            if ($API->connect($ip, $user, $password)) {
                $ARRAY      = $API->comm('/ppp/active/print');
                $onlineList = array_column($ARRAY, 'name');

                // gabung semua username online
                $allOnlineUsers = array_merge($allOnlineUsers, $onlineList);

                $sql_pel = "SELECT IDPEL FROM pelanggan WHERE PEMILIK='$pemilik' AND AREA='$area'";
                $query_pel = mysqli_query($conn, $sql_pel);

                while ($p = mysqli_fetch_assoc($query_pel)) {
                    $idpel = $p['IDPEL'];
                    $statusPelanggan[$idpel] = in_array($idpel, $onlineList) ? 'online' : 'los';
                }

                $API->disconnect();
                sleep(1);
            }
        }
    }
}




// --- Ambil semua user atau user spesifik ---
$target_username = isset($_GET['username']) ? trim($_GET['username']) : null;

if ($target_username) {
    // Proses hanya user tertentu
    $sqlUsers = "SELECT id, USERNAME, STATUS, grup, server FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $target_username) . "' LIMIT 1";
} else {
    // Proses semua user (fallback)
    $sqlUsers = "SELECT id, USERNAME, STATUS, grup, server FROM user WHERE 1";
}

$resUsers = mysqli_query($conn, $sqlUsers);
if (!$resUsers) {
    die("❌ Query users gagal: " . mysqli_error($conn));
}

while ($userRow = mysqli_fetch_assoc($resUsers)) {
    $user_id = $userRow['id'];
    $username = $userRow['USERNAME'];
    $user_status = $userRow['STATUS'];

    if ($user_status == 'ASSISTANT') {
        $asistant_name = $username;
    } else {
        $asistant_name = $username;
    }

    // Ambil semua server milik user ini (berdasarkan user_id di tabel server)
    if ($user_status == 'ASSISTANT') {
        // Untuk asisten, ambil server berdasarkan area dari server yang di-assign
        $server_json = isset($userRow['server']) ? $userRow['server'] : '';
        if (!empty($server_json)) {
            $server_ids = json_decode($server_json, true);
            if (is_array($server_ids)) {
                $id_in = implode(",", array_map('intval', $server_ids));
                $sql_area = "SELECT DISTINCT AREA FROM server WHERE id IN ($id_in)";
                $res_area = mysqli_query($conn, $sql_area);
                $areas = [];
                while ($row = mysqli_fetch_assoc($res_area)) {
                    $areas[] = $row['AREA'];
                }
                if (!empty($areas)) {
                    $area_in = "'" . implode("','", array_map('addslashes', $areas)) . "'";
                    $queryServer = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_in)");
                } else {
                    $queryServer = false;
                }
            } else {
                $queryServer = false;
            }
        } else {
            $queryServer = false;
        }
    } else {
        $queryServer = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = '" . mysqli_real_escape_string($conn, $user_id) . "'");
    }
    $servers = [];
    if ($queryServer) {
        while ($row = mysqli_fetch_assoc($queryServer)) {
            $servers[] = $row['PEMILIK'];
        }
    }
    $servers = array_unique(array_filter($servers));
    if (empty($servers)) {
        echo "⚠️ User $username tidak punya server<br>";
        continue;
    }

    // --- Query pelanggan, odp, dll langsung berdasarkan user_id ---
    $today = date('Y-m-d');
    // Ambil semua PEMILIK server milik user ini
    $servers_escaped = array_map(function($s) use ($conn) { return "'" . mysqli_real_escape_string($conn, $s) . "'"; }, $servers);
    $pemilik_in = count($servers_escaped) ? implode(",", $servers_escaped) : "''";

    $qTotalUsers = mysqli_query($conn, "SELECT COUNT(*) as total FROM pelanggan WHERE PEMILIK IN ($pemilik_in)");
    if ($qTotalUsers) {
        $rowTotal = mysqli_fetch_assoc($qTotalUsers);
        $totalUsers = $rowTotal ? $rowTotal['total'] : 0;
    } else {
        echo "<div style='color:red'>Query total users gagal: ".mysqli_error($conn)."</div>";
        $totalUsers = 0;
    }

    $sql_jatuh_tempo = "SELECT p.IDPEL, p.PAKET, p.BRAND, p.AREA FROM pelanggan p LEFT JOIN (SELECT IDPEL, MAX(DATE(waktu)) AS last_paid FROM transaksi WHERE STATUS = 'BERHASIL' GROUP BY IDPEL) t ON p.IDPEL = t.IDPEL WHERE p.PEMILIK IN ($pemilik_in) AND p.TEMPO <= '$today' AND (t.last_paid IS NULL OR t.last_paid < p.TEMPO)";
    $result_jt = mysqli_query($conn, $sql_jatuh_tempo);
    $expiredCount = 0;
    if ($result_jt) {
        $harga_paket_map_jt = [];
        $fasum_paket_list_jt = [];
        $q_paket_map_jt = mysqli_query($conn, "SELECT PAKET, HARGA, BRAND, AREA FROM paket");
        if ($q_paket_map_jt) {
            while ($r = mysqli_fetch_assoc($q_paket_map_jt)) {
                $paket_key = strtolower(trim($r['PAKET']));
                $brand_key = isset($r['BRAND']) ? strtolower(trim($r['BRAND'])) : '';
                $area_key = isset($r['AREA']) ? strtolower(trim($r['AREA'])) : '';
                $map_key = $paket_key . '|' . $brand_key . '|' . $area_key;
                $harga_paket_map_jt[$map_key] = $r['HARGA'];
                if ($r['HARGA'] === '' || $r['HARGA'] == 0) {
                    $fasum_paket_list_jt[] = $paket_key;
                }
            }
        } else {
            echo "<div style='color:red'>Query paket gagal: ".mysqli_error($conn)."</div>";
        }
        while ($row = mysqli_fetch_assoc($result_jt)) {
            $paket_pelanggan = isset($row['PAKET']) ? strtolower(trim($row['PAKET'])) : '';
            $brand_pelanggan = isset($row['BRAND']) ? strtolower(trim($row['BRAND'])) : '';
            $area_pelanggan = isset($row['AREA']) ? strtolower(trim($row['AREA'])) : '';
            $map_key = $paket_pelanggan . '|' . $brand_pelanggan . '|' . $area_pelanggan;
            $is_fasum = in_array($paket_pelanggan, $fasum_paket_list_jt, true);
            $harga_paket = null;
            if (isset($harga_paket_map_jt[$map_key])) {
                $harga_paket = $harga_paket_map_jt[$map_key];
            } elseif (isset($harga_paket_map_jt[$paket_pelanggan . '||' . $area_pelanggan])) {
                $harga_paket = $harga_paket_map_jt[$paket_pelanggan . '||' . $area_pelanggan];
            } elseif (isset($harga_paket_map_jt[$paket_pelanggan . '|' . $brand_pelanggan . '|'])) {
                $harga_paket = $harga_paket_map_jt[$paket_pelanggan . '|' . $brand_pelanggan . '|'];
            } elseif (isset($harga_paket_map_jt[$paket_pelanggan . '||'])) {
                $harga_paket = $harga_paket_map_jt[$paket_pelanggan . '||'];
            }
            if (!$is_fasum && $harga_paket !== null && $harga_paket > 0) {
                $expiredCount++;
            }
        }
    } else {
        echo "<div style='color:red'>Query jatuh tempo gagal: ".mysqli_error($conn)."</div>";
    }

    // === Bagian ODP ===
    $odpMarkers = [];
    $odpLookup  = [];
    $odpPelangganList = [];

    $odpQuery = mysqli_query($conn, "SELECT NAME, KODE, PORT, BRAND, AREA, TIKOR, FOTO FROM odp WHERE PEMILIK IN ($pemilik_in)");
    if ($odpQuery) {
        while ($row = mysqli_fetch_assoc($odpQuery)) {
            $tikor = str_replace(' ', '', $row['TIKOR']);
            if (strpos($tikor, ',') === false) continue;
            list($lat, $lng) = explode(',', $tikor);
            if (!is_numeric($lat) || !is_numeric($lng)) continue;

            $foto_html = '';
            if (!empty($row['FOTO'])) {
                $foto_html = '<br><img src="' . $row['FOTO'] . '" alt="Foto ODP" style="max-width:240px;max-height:240px;border-radius:8px;margin:4px 0;">';
            }

            $map_url = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($lat . ',' . $lng);
            $open_map_btn = '<br><a href="' . $map_url . '" target="_blank" class="btn btn-sm btn-info mt-1">Open Map</a>';

            $odpMarkers[] = [
                'lat'   => $lat,
                'lng'   => $lng,
                'kode'  => $row['KODE'],
                'name'  => $row['NAME'],
                'area'  => $row['AREA'],
                'popup' => "<b>ODP:</b> {$row['NAME']}<br>
                            <b>Kode:</b> {$row['KODE']}<br>
                            <b>Port:</b> {$row['PORT']}<br>
                            <b>Server Area:</b> {$row['BRAND']}<br>
                            <b>Area:</b> {$row['AREA']}<br>
                            <b>Koordinat:</b> $lat, $lng" . $foto_html . $open_map_btn
            ];
            $odpLookup[$row['KODE']] = ['lat' => $lat, 'lng' => $lng, 'foto' => $row['FOTO']];
            $odpPelangganList[$row['KODE']] = [];
        }
    } else {
        echo "<div style='color:red'>Query ODP gagal: ".mysqli_error($conn)."</div>";
    }

    // === Bagian Pelanggan ===
    $pelangganMarkers = [];
    $lines = [];

    $pelangganQuery = mysqli_query($conn, "SELECT NAMA, IDPEL, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG FROM pelanggan WHERE PEMILIK IN ($pemilik_in)");
    if ($pelangganQuery) {
        while ($row = mysqli_fetch_assoc($pelangganQuery)) {
            $tikor = str_replace(' ', '', $row['TIKOR']);
            if (strpos($tikor, ',') === false) continue;
            list($lat, $lng) = explode(',', $tikor);
            if (!is_numeric($lat) || !is_numeric($lng)) continue;

            $idpel   = $row['IDPEL'];
            $status  = $statusPelanggan[$idpel] ?? 'los';
            $odpKode = $row['ODP'];

            $statusButton = $status === 'online'
                ? "<span class='btn btn-success btn-sm py-0 px-2'>$status</span>"
                : "<span class='btn btn-danger btn-sm py-0 px-2'>$status</span>";


            $map_url_pel = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($lat . ',' . $lng);
            $open_map_btn_pel = '<br><a href="' . $map_url_pel . '" target="_blank" class="btn btn-sm btn-info mt-1">Open Map</a>';
            $pelangganMarkers[] = [
                'lat'    => $lat,
                'lng'    => $lng,
                'odp'    => $odpKode,
                'status' => $status,
                'popup'  => '
                    <form method=POST action=tables.php class=d-inline>
                        <input type=hidden name=action value=cari_id>
                        <input type=hidden name=cariid value=' . $row['IDPEL'] . '>
                        <button type=submit class="btn btn-warning btn-sm">
                            Open detail 
                        </button>
                    </form><br>
                    <b>Pelanggan:</b> ' . $row['NAMA'] . '<br>
                    <b>ID Pel:</b> ' . $row['IDPEL'] . '<br>
                    <b>Status:</b> ' . $statusButton . '<br>
                    <b>ODP:</b> ' . $odpKode . '<br>
                    <b>Koordinat:</b> ' . $lat . ', ' . $lng . '<br>
                    <b>Paket:</b> ' . $row['PAKET'] . '<br>
                    <b>Pasang:</b> ' . $row['TANGGALPASANG'] . '
                    ' . $open_map_btn_pel . '
                '
            ];


            if (isset($odpLookup[$odpKode])) {
                $lines[] = [
                    'odp'    => $odpKode,
                    'from'   => [$lat, $lng],
                    'to'     => [$odpLookup[$odpKode]['lat'], $odpLookup[$odpKode]['lng']],
                    'status' => $status
                ];

                $odpPelangganList[$odpKode][] = [
                    'nama'   => $row['NAMA'],
                    'idpel'  => $row['IDPEL'],
                    'paket'  => $row['PAKET'],
                    'tgl'    => $row['TANGGALPASANG'],
                    'status' => $status
                ];
            }
        }
    } else {
        echo "<div style='color:red'>Query pelanggan gagal: ".mysqli_error($conn)."</div>";
    }

    foreach ($odpMarkers as &$odp) {
        $kode = $odp['kode'];
        if (!empty($odpPelangganList[$kode])) {
            $odp['popup'] .= "<br><b>Daftar Pelanggan:</b><ol>";
            foreach ($odpPelangganList[$kode] as $p) {
                $badge = $p['status'] === 'online'
                    ? "<span class='text-success'>online</span>"
                    : "<span class='text-danger'>los</span>";
                $odp['popup'] .= "<li>{$p['nama']} ({$p['idpel']}) - {$p['paket']} - {$p['tgl']} - $badge</li>";
            }
            $odp['popup'] .= "</ol>";
        } else {
            $odp['popup'] .= "<br><i>Belum ada pelanggan</i>";
        }
    }
    unset($odp);

    if (!is_dir($serverlog_dir)) {
        mkdir($serverlog_dir, 0777, true);
    }

   ECHO $filename = $serverlog_dir . "/" . $asistant_name . "_online_client.txt";
    if (file_exists($filename)) {
        unlink($filename);
    }

    $dataSave = [
        'onlineUsers'      => $allOnlineUsers,
        'odpMarkers'       => $odpMarkers,
        'pelangganMarkers' => $pelangganMarkers,
        'lines'            => $lines,
        'totalUsers'       => $totalUsers,
        'expiredCount'     => $expiredCount,
        'timestamp'        => time()
    ];

    file_put_contents($filename, json_encode($dataSave, JSON_PRETTY_PRINT));

    // Set permissions to 777
    chmod($filename, 0777);

    // --- Catat log ke history ---
    $history_file = $notifbot_data_dir . "/history-$asistant_name.json";
    if (!is_dir($notifbot_data_dir)) {
        mkdir($notifbot_data_dir, 0777, true);
    }
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true) ?: [];
    }
    // $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Loading online client data for user $asistant_name";
    // file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    // // Set permissions to 777
    chmod($history_file, 0777);
}
