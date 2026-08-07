<?php
// Test logika periode untuk non_aktif_tempo_FIBERQ.php

// Simulasi data dari JSON
$jatuh_tempo = 28; // Contoh
$hari_sebelum = 2; // Contoh
$tanggal_awal_tutup_buku = 20; // Variabel baru: tanggal awal tutup buku (contoh)
$tanggal_akhir_tutup_buku = 28; // Tanggal akhir tutup buku = jatuh tempo

// Simulasi hari ini (ubah sesuai kebutuhan untuk testing)
$simulasi_hari_ini = '2025-12-19'; // Format: YYYY-MM-DD

$cektanggal = $simulasi_hari_ini;
$tglskg = date('d', strtotime($simulasi_hari_ini));

// Fungsi tanggal_indo2
function tanggal_indo2($tanggal, $cetak_hari = false, $penyesuaian_bulan = 0)
{
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

    $split = explode('-', $tanggal);
    $tahun = (int)$split[0];
    $bulan_index = (int)$split[1];

    // Penyesuaian bulan dan tahun
    $bulan_index += $penyesuaian_bulan;
    if ($bulan_index < 1) {
        $bulan_index += 12;
        $tahun -= 1;
    } elseif ($bulan_index > 12) {
        $bulan_index -= 12;
        $tahun += 1;
    }

    if ($cetak_hari) {
        $num_hari = date('N', strtotime($tanggal));
        return $hari[$num_hari] . ', ' . $split[2] . ' ' . $bulan[$bulan_index] . ' ' . $tahun;
    } else {
        return $bulan[$bulan_index] . ' ' . $tahun;
    }
}

$ptanggalskg = tanggal_indo2($cektanggal, false); // Bulan ini
$ptanggaberikut = tanggal_indo2($cektanggal, false, 1); // Bulan berikutnya
$ptanggalsebelum = tanggal_indo2($cektanggal, false, -1); // Bulan sebelumnya

// Logika periode
if ($jatuh_tempo <= $tanggal_awal_tutup_buku) {
  $periode_bulan_berikut = $ptanggalskg;
} elseif ($jatuh_tempo >= $tanggal_akhir_tutup_buku) {
  $periode_bulan_berikut = $ptanggaberikut;
} else {
  $periode_bulan_berikut = $ptanggaberikut;
}

if ($tglskg < $tanggal_awal_tutup_buku) {
  $periode = $ptanggalskg;
} elseif ($tglskg >= $tanggal_awal_tutup_buku && $tglskg <= $tanggal_akhir_tutup_buku) {
  $periode = $periode_bulan_berikut; // Periode bulan berikut berdasarkan jatuh_tempo
} elseif ($tglskg > $tanggal_akhir_tutup_buku) {
  if ($periode_bulan_berikut == $ptanggalskg) {
    $periode = $ptanggalskg;
  } else {
    $periode = tanggal_indo2($cektanggal, false, 2);
  }
}

echo "Tanggal bayar hari ini: $cektanggal ($tglskg)<br>";
echo "Jatuh tempo: $jatuh_tempo<br>";
echo "Tanggal awal tutup buku: $tanggal_awal_tutup_buku<br>";
echo "Hari sebelum: $hari_sebelum<br>";
echo "Periode yang digunakan: $periode<br>";
?>