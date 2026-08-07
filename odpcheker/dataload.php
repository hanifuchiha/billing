<?php
require "../../employee/cek-sesi.php";
require "../../employee/koneksibilling.php";

$sql_area = "SELECT DISTINCT PEMILIK, AREA FROM pelanggan WHERE PEMILIK != '' AND AREA != ''";
$query_area = mysqli_query($conn, $sql_area);

if ($query_area) {
    while ($row_area = mysqli_fetch_assoc($query_area)) {
        $pemilik = $row_area['PEMILIK'];
        $area = $row_area['AREA'];
        $sql_server = "SELECT * FROM server WHERE AREA='$area' AND PEMILIK='$pemilik' LIMIT 1";
        $result_server = mysqli_query($conn, $sql_server);

        if ($srv = mysqli_fetch_assoc($result_server)) {
            $ip = $srv['IP'];
            $user = $srv['PEMILIK'];
            $password = $srv['PASSWORD'];

            if ($API->connect($ip, $user, $password)) {
                $ARRAY = $API->comm('/ppp/active/print');
                $onlineList = array_column($ARRAY, 'name');

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

$odpMarkers = [];
$odpLookup = [];
$odpQuery = mysqli_query($conn, "SELECT NAME, KODE, PORT, PEMILIK, AREA, TIKOR FROM odp");
while ($row = mysqli_fetch_assoc($odpQuery)) {
    $tikor = str_replace(' ', '', $row['TIKOR']);
    if (strpos($tikor, ',') === false) continue;
    list($lat, $lng) = explode(',', $tikor);
    if (!is_numeric($lat) || !is_numeric($lng)) continue;

    $odpMarkers[] = [
        'lat' => $lat,
        'lng' => $lng,
        'kode' => $row['KODE'],
        'name' => $row['NAME'],
        'area' => $row['AREA'], // ← INI WAJIB DITAMBAHKAN
        'popup' => "<b>ODP:</b> {$row['NAME']}<br><b>Kode:</b> {$row['KODE']}<br><b>Port:</b> {$row['PORT']}<br><b>Pemilik:</b> {$row['PEMILIK']}<br><b>Area:</b> {$row['AREA']}<br><b>Koordinat:</b> $lat, $lng"
    ];
    $odpLookup[$row['KODE']] = ['lat' => $lat, 'lng' => $lng];
}

$pelangganMarkers = [];
$lines = [];
$pelangganQuery = mysqli_query($conn, "SELECT NAMA, IDPEL, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG FROM pelanggan");
while ($row = mysqli_fetch_assoc($pelangganQuery)) {
    $tikor = str_replace(' ', '', $row['TIKOR']);
    if (strpos($tikor, ',') === false) continue;
    list($lat, $lng) = explode(',', $tikor);
    if (!is_numeric($lat) || !is_numeric($lng)) continue;

    $idpel = $row['IDPEL'];
    $status = $statusPelanggan[$idpel] ?? 'los';
    $odpKode = $row['ODP'];

    $statusButton = $status === 'online'
        ? "<span class='btn btn-success btn-sm py-0 px-2'>$status</span>"
        : "<span class='btn btn-danger btn-sm py-0 px-2'>$status</span>";

    $pelangganMarkers[] = [
        'lat' => $lat,
        'lng' => $lng,
        'odp' => $odpKode,
        'status' => $status,
        'popup' => "<b>Pelanggan:</b> {$row['NAMA']}<br><b>ID Pel:</b> {$row['IDPEL']}<br><b>Status:</b> $statusButton<br><b>ODP:</b> {$odpKode}<br><b>Koordinat:</b> $lat, $lng<br><b>Paket:</b> {$row['PAKET']}<br><b>Pasang:</b> {$row['TANGGALPASANG']}"
    ];

    if (isset($odpLookup[$odpKode])) {
        $lines[] = [
            'odp' => $odpKode,
            'from' => [$lat, $lng],
            'to' => [$odpLookup[$odpKode]['lat'], $odpLookup[$odpKode]['lng']],
            'status' => $status
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'odpMarkers' => $odpMarkers,
    'pelangganMarkers' => $pelangganMarkers,
    'lines' => $lines,
    'timestamp' => time()
]);
