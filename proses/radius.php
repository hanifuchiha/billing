<?php
// Ini dulu salinan hampir identik dari ../radius.php (dua dashboard FreeRADIUS
// terpisah dengan kode yang sama-sama menulis clients.conf/users/config
// FreeRADIUS secara independen -- rawan saling menimpa). Halaman ini juga
// sudah rusak duluan: baris require 'header.php' di versi lama memakai path
// relatif padahal header.php ada satu folder di atas (crm/billing/), bukan di
// proses/. Tidak ada link/menu yang mengarah kesini lagi -- panel FreeRADIUS
// yang aktif sekarang cuma satu, di ../radius.php. Redirect (bukan hapus
// file) supaya bookmark/link lama tidak 404.
header('Location: ../radius.php');
exit;
