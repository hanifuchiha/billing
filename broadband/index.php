<?php
// Redirect ke halaman portallogin.php, teruskan juga jika ada GET-nya
$query = http_build_query($_GET);
$location = 'portal_baru.php';
if (!empty($query)) {
	$location .= '?' . $query;
}
header("Location: $location");
exit; // selalu tambahkan exit setelah header redirect
