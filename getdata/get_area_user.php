<?php

require '../cek-sesi.php';

if (!isset($_POST['servers'])) {
    echo '<p class="text-danger">Tidak ada server dipilih.</p>';
    exit;
}

$servers = json_decode($_POST['servers'], true);
if (!is_array($servers) || empty($servers)) {
    echo '<p class="text-muted">Silakan pilih server.</p>';
    exit;
}

// Escape dan buat list SQL
$server_list_sql = implode("','", array_map([$conn, 'real_escape_string'], $servers));

// Ambil AREA dan PEMILIK
$query = "SELECT DISTINCT PEMILIK, AREA , id , BRAND FROM server WHERE PEMILIK IN ('$server_list_sql')";
$result = $conn->query($query);

if (!$result || $result->num_rows == 0) {
    echo '<p class="text-warning">Tidak ada AREA untuk server yang dipilih.</p>';
    exit;
}

echo '<div>';
while ($row = $result->fetch_assoc()) {
    $area_value = trim($row['AREA']);
    $pemilik_value = trim($row['PEMILIK']);
    $server_id = trim($row['id']);
     $brand_value = trim($row['BRAND']);
    if ($area_value && $pemilik_value) {
        $id = md5($pemilik_value . '_' . $area_value); // unik
        echo "
        <div class='form-check border rounded p-2 mb-2'>
          <input type='checkbox' class='form-check-input' name='areas[]' 
                 value='".htmlspecialchars($area_value)."' id='area_$id'>
          <label class='form-check-label fw-bold' for='area_$id'>".htmlspecialchars($brand_value . ' - ' . $area_value)."</label>
        </div>";
    }
}
echo '</div>';
?>
