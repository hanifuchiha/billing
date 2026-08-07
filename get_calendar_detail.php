<?php
// AJAX handler - suppress any HTML output from session/header includes
ob_start();
require 'cek-sesi.php';
ob_end_clean();

// Only respond to POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['day'], $_POST['bulan'], $_POST['tahun'], $_POST['tab_type'])) {
  http_response_code(400);
  echo '<tr><td colspan="5" class="text-center text-danger">Request tidak valid</td></tr>';
  exit;
}

$day      = (int)$_POST['day'];
$bulan    = str_pad((int)$_POST['bulan'], 2, '0', STR_PAD_LEFT);
$tahun    = (int)$_POST['tahun'];
$tab_type = $_POST['tab_type'];
$cal_metode = isset($_POST['cal_metode']) ? $_POST['cal_metode'] : '';

// Date expression using TANGGALBAYAR field (alias t)
$trx_tanggal_expr_t = "STR_TO_DATE(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),'Januari', '01'), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')), '%d %m %Y')";

// Same expression without table alias (for subqueries)
$trx_tanggal_expr_noalias = "STR_TO_DATE(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),'Januari', '01'), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')), '%d %m %Y')";

// Get user servers and areas
$userServers = [];
$userAreas   = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = " . (int)$current_user_id);
while ($row = mysqli_fetch_assoc($queryServer)) {
  $userServers[] = $row['PEMILIK'];
  if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
}

$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList   = count($userAreas)   > 0 ? "'" . implode("','", array_map('addslashes', $userAreas))   . "'" : "''";

if (isset($AKSES) && $AKSES == 'ASSISTANT' && isset($area_list)) {
  $queryPemilik = mysqli_query($conn, "SELECT DISTINCT PEMILIK FROM server WHERE AREA IN ($area_list)");
  $userServers  = [];
  while ($row = mysqli_fetch_assoc($queryPemilik)) {
    $userServers[] = $row['PEMILIK'];
  }
  $userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
  $userAreaList   = $area_list;
}

$metode_filter = $cal_metode ? "AND p.TIPE_BAYAR = '" . mysqli_real_escape_string($conn, $cal_metode) . "'" : '';
$filtered_rows = [];

if ($tab_type === 'sudah_bayar') {
  $tanggal_target = "$tahun-$bulan-" . str_pad($day, 2, '0', STR_PAD_LEFT);

  $sql = "SELECT 
      t.IDPEL,
      p.NAMA,
      t.PAKET,
      t.HARGA,
      p.TIPE_BAYAR,
      p.PEMILIK,
      p.AREA
    FROM transaksi t
    INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
    WHERE t.STATUS = 'BERHASIL'
      AND DATE($trx_tanggal_expr_t) = '$tanggal_target'
      AND p.PEMILIK IN ($userServerList)
      AND p.AREA IN ($userAreaList)
      $metode_filter
    ORDER BY p.NAMA ASC";

  $result = mysqli_query($conn, $sql);
  if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
      $filtered_rows[] = $row;
    }
  }

} else {
  // Tab akan_bayar: mirror the same source used by the yellow calendar cells.
  $sql = "SELECT 
      p.IDPEL,
      p.NAMA,
      p.PAKET,
      p.harga_paket as HARGA,
      p.TIPE_BAYAR,
      p.PEMILIK,
      p.AREA,
      p.TIPE_TEMPO,
      p.TANGGALPASANG,
      p.TANGGAL_MONTHVERSARY,
      t.last_paid as reference_date
    FROM pelanggan p
    INNER JOIN (
      SELECT IDPEL, MAX($trx_tanggal_expr_noalias) as last_paid
      FROM transaksi
      WHERE STATUS = 'BERHASIL'
      GROUP BY IDPEL
    ) t ON p.IDPEL = t.IDPEL
    WHERE p.PEMILIK IN ($userServerList)
      AND p.AREA IN ($userAreaList)
      AND p.STATUS != 'suspend'
      $metode_filter
    ORDER BY p.NAMA ASC";

  $result = mysqli_query($conn, $sql);

  // Get fixed due date from reminder JSON file (same logic as statistics.php)
  $fixedDueDateDay = 28;
  $safeUsername = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($ceknama ?? $username ?? ''));
  $reminderFile  = __DIR__ . '/notifbot/data/reminder-' . $safeUsername . '.json';
  if (is_file($reminderFile)) {
    $json = @file_get_contents($reminderFile);
    $cfg  = json_decode((string)$json, true);
    if (is_array($cfg) && !empty($cfg) && isset($cfg[0]['jatuh_tempo'])) {
      $d = (int)$cfg[0]['jatuh_tempo'];
      if ($d >= 1 && $d <= 31) {
        $fixedDueDateDay = $d;
      }
    }
  }

  if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
      $tempo_day = null;
      $tipe_tempo = strtolower(trim((string)($row['TIPE_TEMPO'] ?? '')));

      if ($tipe_tempo === 'mengikuti_tanggal_tempo') {
        $tempo_day = $fixedDueDateDay;
      } elseif ($tipe_tempo === 'monthversary') {
        // Hari jatuh tempo tetap milik pelanggan itu sendiri (anchor), bukan
        // dihitung dari reference_date seperti mode "Rolling Due Date".
        $anchor_date = $row['TANGGAL_MONTHVERSARY'] ?? '';
        if (empty($anchor_date) || strtotime($anchor_date) === false) {
          $anchor_date = $row['TANGGALPASANG'] ?? '';
        }
        if (!empty($anchor_date) && strtotime($anchor_date) !== false) {
          $tempo_day = (int)date('j', strtotime($anchor_date));
        }
      } else {
        $reference_date = $row['reference_date'] ?? '';
        if (!empty($reference_date)) {
          $next_due  = date('d', strtotime($reference_date . ' + 1 month'));
          $tempo_day = (int)$next_due;
        }
      }

      if ($tempo_day !== null && $tempo_day === $day) {
        $filtered_rows[] = $row;
      }
    }
  }
}

// Output only <tr> rows for AJAX
if (count($filtered_rows) > 0) {
  foreach ($filtered_rows as $row) {
    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars((string)$row['IDPEL']) . '</strong></td>';
    echo '<td>' . htmlspecialchars((string)$row['NAMA']) . '</td>';
    echo '<td>' . htmlspecialchars((string)$row['PAKET']) . '<br><small class="text-muted">Rp. ' . number_format((float)$row['HARGA'], 0, ',', '.') . '</small></td>';
    $tipe  = strtolower($row['TIPE_BAYAR'] ?? '');
    $badge = ($tipe === 'pascabayar') ? 'warning text-dark' : 'info';
    echo '<td><span class="badge bg-' . $badge . '">' . htmlspecialchars((string)($row['TIPE_BAYAR'] ?? '-')) . '</span></td>';
    echo '<td>' . htmlspecialchars((string)$row['PEMILIK']) . '<br><small class="text-muted">' . htmlspecialchars((string)$row['AREA']) . '</small></td>';
    echo '</tr>';
  }
} else {
  echo '<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada data untuk tanggal ini</td></tr>';
}
?>
