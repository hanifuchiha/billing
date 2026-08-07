<?php
// api/komisi_paket_setting.php
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

switch ($method) {
    case 'GET':
        // Ambil setting komisi paket milik pemilik (hotspot/pppoe/all)
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $jenis = strtolower(trim((string)($_GET['jenis'] ?? 'all')));
        $q = trim((string)($_GET['q'] ?? ''));
        $qWhereHs = '';
        $qWherePpp = '';
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $qWhereHs = " AND (paket LIKE '%$qe%' OR AREA LIKE '%$qe%' OR PEMILIK LIKE '%$qe%' OR harga LIKE '%$qe%' OR komisi LIKE '%$qe%')";
            $qWherePpp = " AND (PAKET LIKE '%$qe%' OR AREA LIKE '%$qe%' OR PEMILIK LIKE '%$qe%' OR HARGA LIKE '%$qe%' OR komisi LIKE '%$qe%')";
        }
        $data = [];
        if ($jenis === 'hotspot' || $jenis === 'all') {
            $resultHs = mysqli_query($conn, "SELECT ID, paket AS NAMA, harga AS HARGA, komisi, PEMILIK, AREA FROM paket_hotspot WHERE PEMILIK = '$pem'$qWhereHs ORDER BY paket ASC LIMIT 500");
            while ($resultHs && ($row = mysqli_fetch_assoc($resultHs))) {
                $row['jenis'] = 'hotspot';
                $data[] = $row;
            }
        }
        if ($jenis === 'pppoe' || $jenis === 'all') {
            $resultPpp = mysqli_query($conn, "SELECT ID, PAKET AS NAMA, HARGA, komisi, PEMILIK, AREA FROM paket WHERE PEMILIK = '$pem'$qWherePpp ORDER BY PAKET ASC LIMIT 500");
            while ($resultPpp && ($row = mysqli_fetch_assoc($resultPpp))) {
                $row['jenis'] = 'pppoe';
                $data[] = $row;
            }
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;
    case 'POST':
        // Ubah komisi paket (hotspot/pppoe)
        $data = $input;
        $id = $data['id'] ?? '';
        $komisi = $data['komisi'] ?? '';
        $jenis = strtolower(trim((string)($data['jenis'] ?? 'hotspot')));
        if (!$id || $komisi === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $table = $jenis === 'pppoe' ? 'paket' : 'paket_hotspot';
        $stmt = $conn->prepare("UPDATE $table SET komisi=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sis', $komisi, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
