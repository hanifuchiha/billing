<?php
// Halaman lama, sudah dead code -- tidak ada satu pun link/form di ../radius.php
// atau proses/radius.php yang mengarah kesini (semua form action-nya ke
// radiuscontrol/proses.php). Panel FreeRADIUS yang aktif sekarang ada di
// ../radius.php. Redirect (bukan hapus file) supaya bookmark/link lama tidak 404.
header('Location: ../radius.php');
exit;
