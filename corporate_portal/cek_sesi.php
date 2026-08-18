<?php
/**
 * Bootstrap sesi Portal Corporate -- TERPISAH TOTAL dari cek-sesi.php admin
 * CRM (folder ini publik/customer-facing, sama sekali tidak boleh require
 * cek-sesi.php admin). Pola auth pakai PHP $_SESSION murni (bukan cookie
 * `idselect` ala broadband/, karena Corporate tidak punya konsep NOWA/OTP
 * yang mendasari pola itu) -- setiap halaman portal ini wajib
 * require __DIR__.'/cek_sesi.php' di baris paling atas.
 */

session_start();

require_once __DIR__ . '/../koneksidb.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

if (empty($_SESSION['corp_portal_id'])) {
    header('Location: login.php');
    exit;
}

$corporateId = (int) $_SESSION['corp_portal_id'];
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM corporate WHERE id = $corporateId LIMIT 1"));

if (!$corp) {
    // Data terhapus setelah sesi dibuat -- paksa login ulang.
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($corp['STATUS'] !== 'AKTIF') {
    // Perusahaan dinonaktifkan admin -- tolak akses portal, tapi jangan hancurkan
    // sesi supaya pesannya bisa ditampilkan (login.php akan minta login ulang lain kali).
    session_destroy();
    header('Location: login.php?suspended=1');
    exit;
}
