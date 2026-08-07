<?php
// Helper to get unique mitra names for filter
require_once 'koneksidb.php';
$q = $conn->query("SELECT DISTINCT nama FROM mitra ORDER BY nama");
$mitra = [];
while($row = $q->fetch_assoc()) $mitra[] = $row['nama'];
header('Content-Type: application/json');
echo json_encode($mitra);