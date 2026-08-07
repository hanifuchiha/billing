<?php
session_start();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

$cookie_names = ['username', 'id', 'iplogin', 'userlogin', 'passwordlogin', 'idip'];
foreach ($cookie_names as $cookie_name) {
	setcookie($cookie_name, '', time() - 3600, '/');
}

header('Location: index.php');
exit;
?>