<?php
require '../cek-sesi.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

$id = (int) ($_POST['id'] ?? 0);
$corporateId = (int) ($_POST['corporate_id'] ?? 0);

function redirectDeleteKontrak($corporateId) {
    header("Location: ../corporate_kontrak.php?corporate_id=" . (int) $corporateId . "&deleted=1");
    exit;
}

if ($id <= 0 || $corporateId <= 0) {
    redirectDeleteKontrak($corporateId);
}

// Pastikan kontrak ini benar milik perusahaan tenant yang login (+ batas
// AREA kalau ASSISTANT).
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    redirectDeleteKontrak($corporateId);
}

$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_pdf FROM corporate_kontrak WHERE id = $id AND corporate_id = $corporateId LIMIT 1"));
if ($row) {
    corporateDeleteDokumenFile((string) ($row['file_pdf'] ?? ''));
    mysqli_query($conn, "DELETE FROM corporate_kontrak WHERE id = $id AND corporate_id = $corporateId");

    $history_file = "../notifbot/data/history-$ceknama.json";
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) { $history = []; }
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus kontrak id=$id corporate_id=$corporateId";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

redirectDeleteKontrak($corporateId);
