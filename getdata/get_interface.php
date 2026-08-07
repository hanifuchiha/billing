<?php
require '../routeros_api.class.php'; // Pastikan lokasi file benar
require '../cek-sesi.php'; // Pastikan sesi pengguna aman


header("Content-Type: application/json");

$response = ["status" => "error", "message" => "Invalid request"];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $server = $_POST['server'] ?? '';
    $area = $_POST['area'] ?? '';

    if (!$server || !$area) {
        $response["message"] = "Server dan area harus dipilih!";
        echo json_encode($response);
        exit;
    }

    // Ambil data server berdasarkan pemilik dan area
    $sql = "SELECT * FROM `server` WHERE PEMILIK = ? AND `AREA` = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Query error: " . $conn->error]);
        exit;
    }

    $stmt->bind_param("ss", $server, $area);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Tidak ada server yang ditemukan untuk area ini."]);
        exit;
    }

    $interfaces = [];
    $API = new RouterosAPI();

    while ($data = $result->fetch_assoc()) {
        $ip = $data['IP'];
        $pemilik = $data['PEMILIK'];
        $password = $data['PASSWORD'];

        if (!$API->connect($ip, $pemilik, $password)) {
            echo json_encode(["status" => "error", "message" => "Gagal terhubung ke MikroTik: $ip"]);
            exit;
        }

        $router_interfaces = $API->comm("/interface/ethernet/print");

        // Cek apakah ada error dari MikroTik API
        if (isset($router_interfaces['!trap'][0]['message'])) {
            echo json_encode(["status" => "error", "message" => "Error dari MikroTik: " . $router_interfaces['!trap'][0]['message']]);
            $API->disconnect();
            exit;
        }

        if (!$router_interfaces) {
            echo json_encode(["status" => "error", "message" => "Tidak dapat mengambil data interface dari MikroTik $ip"]);
            $API->disconnect();
            exit;
        }

        $API->disconnect();

        foreach ($router_interfaces as $interface) {
            $interfaces[] = [
                'name' => $interface['name'] ?? '',
                'default_name' => $interface['default-name'] ?? '',
            ];
        }
    }

    if (empty($interfaces)) {
        echo json_encode(["status" => "error", "message" => "Tidak ada interface yang tersedia untuk area ini."]);
    } else {
        echo json_encode(["status" => "success", "data" => $interfaces]);
    }
}
exit;
