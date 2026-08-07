<?php
require '../cek-sesi.php';
  // Ambil hanya ODP yang server-nya dimiliki oleh user yang sedang login
          if($AKSES !='ASSISTANT') {
            // Ambil semua server yang user_id-nya sama dengan current_user_id
            $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
            $userServerIds = [];
            while($row = mysqli_fetch_assoc($queryServerId)) {
              $userServerIds[] = "'".$row['PEMILIK']."'";
            }
            $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
            $sql = "SELECT * FROM odp WHERE `pemilik` IN ($userServerList)";
          } else {
            // Untuk ASSISTANT, batasi ke PEMILIK server yang ada di area yang diizinkan
            $queryServerId = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
            $userServerIds = [];
            while($row = mysqli_fetch_assoc($queryServerId)) {
              $userServerIds[] = "'".$row['PEMILIK']."'";
            }
            $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
            $sql = "SELECT * FROM odp WHERE `pemilik` IN ($userServerList) and `AREA` IN ($area_list)";
          }

$result = mysqli_query($conn, $sql);
if (!$result) {
    die('DB error: ' . mysqli_error($conn));
}

$kml = [];
$kml[] = '<?xml version="1.0" encoding="UTF-8"?>';
$kml[] = '<kml xmlns="http://www.opengis.net/kml/2.2">';
$kml[] = '<Document>';
$kml[] = '<name>ODP Export</name>';

while ($row = mysqli_fetch_assoc($result)) {
    $id = htmlspecialchars($row['id']);
    $kode = htmlspecialchars($row['KODE']);
    $name = htmlspecialchars($row['NAME']);
    $tikor = trim($row['TIKOR']);
    $area = htmlspecialchars($row['AREA']);
    $BRAND = htmlspecialchars($row['BRAND']);

    // expected tikor format: lat,lng
    $coords = explode(',', $tikor);
    if (count($coords) >= 2) {
        $lat = trim($coords[0]);
        $lng = trim($coords[1]);
        // KML uses lon,lat,alt
        $kml[] = '<Placemark>';
        $kml[] = "<name>" . $kode . " - " . $name . "</name>";
        $kml[] = '<description><![CDATA[' . "Area: $area<br>Owner: $BRAND" . ']]></description>';
        $kml[] = '<Point>';
        $kml[] = "<coordinates>$lng,$lat,0</coordinates>";
        $kml[] = '</Point>';
        $kml[] = '</Placemark>';
    }
}

$kml[] = '</Document>';
$kml[] = '</kml>';

$kmlText = implode("\n", $kml);

$filename = 'odp_export_' . date('Ymd_His') . '.kml';
header('Content-Type: application/vnd.google-earth.kml+xml; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// log history
$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }
$history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Export ODP KML";
file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

echo $kmlText;
exit;
