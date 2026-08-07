<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar
// Ambil brand dan area dari GET
$pemilik = isset($_GET['server']) ? mysqli_real_escape_string($conn, $_GET['server']) : '';
$area = isset($_GET['area']) ? mysqli_real_escape_string($conn, $_GET['area']) : '';

// Pastikan tabel relasi odp_server ada (multi-product per ODP)
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'odp_server'");
$has_odp_server_table = ($tbl_check && mysqli_num_rows($tbl_check) > 0);

if ($pemilik && $area) {
    // Build query defensively: hierarchy columns may not exist in older schema.
    $has_hirarki = false;
    $has_parent = false;
    $col_hirarki = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'Hirarki'");
    $col_parent = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'hirarki_parent'");
    if ($col_hirarki && mysqli_num_rows($col_hirarki) > 0) $has_hirarki = true;
    if ($col_parent && mysqli_num_rows($col_parent) > 0) $has_parent = true;

    $select_cols = "o.KODE, o.NAME"
        . ($has_hirarki ? ", COALESCE(o.Hirarki, 'ODP') AS HIRARKI" : ", 'ODP' AS HIRARKI")
        . ($has_parent ? ", COALESCE(o.hirarki_parent, '') AS PARENT_KODE" : ", '' AS PARENT_KODE");

    // Match ODP ke product baik lewat relasi multi (odp_server) maupun kolom lama (PEMILIK/AREA di tabel odp),
    // supaya ODP lama yang belum dimigrasikan ke odp_server tetap muncul, dan ODP baru yang
    // di-assign ke beberapa product (via checkbox) ikut muncul di tiap product terkait.
    if ($has_odp_server_table) {
        $query = "SELECT DISTINCT $select_cols
                  FROM odp o
                  LEFT JOIN odp_server os ON os.odp_kode = o.KODE
                  WHERE
                    (os.pemilik = '$pemilik' AND os.area = '$area')
                    OR (
                        o.PEMILIK = '$pemilik' AND o.AREA = '$area'
                        AND NOT EXISTS (SELECT 1 FROM odp_server os2 WHERE os2.odp_kode = o.KODE)
                    )
                  ORDER BY o.KODE ASC";
    } else {
        $query = "SELECT $select_cols
                  FROM odp o
                  WHERE o.PEMILIK = '$pemilik' AND o.AREA = '$area'
                  ORDER BY o.KODE ASC";
    }

    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $group_odc_map = [];
        $group_jumper = [];
        $group_rasio = [];
        $group_lain = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $hirarki = strtoupper(trim((string)($row['HIRARKI'] ?? 'ODP')));
            $has_parent_odc = trim((string)($row['PARENT_KODE'] ?? '')) !== '';
            if ($hirarki === 'ODP-JUMPER') {
                $group_jumper[] = $row;
            } elseif ($hirarki === 'ODP-RASIO') {
                $group_rasio[] = $row;
            } elseif ($hirarki === 'ODP' && $has_parent_odc) {
                $parent = trim((string)($row['PARENT_KODE'] ?? ''));
                if ($parent === '') {
                    $parent = 'TANPA ODC';
                }
                if (!isset($group_odc_map[$parent])) {
                    $group_odc_map[$parent] = [];
                }
                $group_odc_map[$parent][] = $row;
            } else {
                $group_lain[] = $row;
            }
        }
        $render_group = function($label, $rows) {
            if (empty($rows)) {
                return;
            }
            echo '<optgroup label="' . htmlspecialchars($label) . '">';
            foreach ($rows as $row) {
                echo '<option value="' . htmlspecialchars($row['KODE']) . '">' . htmlspecialchars($row['KODE']) . ' ( ' . htmlspecialchars($row['NAME']) . ' )</option>';
            }
            echo '</optgroup>';
        };
        echo '<option value="">-- Pilih ODP --</option>';
        if (!empty($group_odc_map)) {
            ksort($group_odc_map, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($group_odc_map as $parent_odc => $rows_odc) {
                $render_group($parent_odc, $rows_odc);
            }
        }
        $render_group('ODP-JUMPER', $group_jumper);
        $render_group('ODP-RASIO', $group_rasio);
        $render_group('LAIN NYA', $group_lain);
        echo "<option  value='SEMUA'>ALL ODP</option>";
    } else {
        echo "<option disabled value=''>Tidak ada ODP $pemilik $area tersedia</option>";
    }
} else {
    echo "<option disabled value=''>Anda belum memiliki odp </option>";
}
exit;