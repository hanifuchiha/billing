<?php
header('Content-Type: application/json');
require_once '../koneksibilling.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $raw = (string)file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

function normalize_wa_digits($raw) {
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '') return '';
    if (strpos($digits, '62') === 0) return $digits;
    if (strpos($digits, '0') === 0) return '62' . substr($digits, 1);
    return '62' . $digits;
}

function auth_broadband_user($conn, $username, $password) {
    $stmt = $conn->prepare('SELECT USERNAME, PASWORD FROM user WHERE USERNAME=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if (password_verify($password, (string)$row['PASWORD']) || (string)$password === (string)$row['PASWORD']) {
            return true;
        }
    }
    return false;
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

    $username = (string)($input['username'] ?? ($_GET['username'] ?? ''));
    $password = (string)($input['password'] ?? ($_GET['password'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? 'otp_login'))));

    if (!auth_broadband_user($conn, $username, $password)) {
        echo json_encode(['success' => false, 'error' => 'Autentikasi gagal']);
        exit;
    }

    if ($method === 'POST' && $action === 'otp_login') {
        $idSelect = (string)($input['idselect'] ?? '');
        $wa62 = normalize_wa_digits($idSelect);
        if ($wa62 === '') {
            echo json_encode(['success' => false, 'error' => 'Nomor WhatsApp tidak valid']);
            exit;
        }

        $wa0 = '0' . substr($wa62, 2);
        $waPlus = '+' . $wa62;

        $stmt = $conn->prepare('SELECT IDPEL, NOWA FROM pelanggan WHERE NOWA IN (?, ?, ?) LIMIT 1');
        $stmt->bind_param('sss', $wa62, $wa0, $waPlus);
        $stmt->execute();
        $res = $stmt->get_result();

        $idpel = '';
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $idpel = (string)($row['IDPEL'] ?? '');
        }

        if ($idpel === '') {
            $stmt2 = $conn->prepare("SELECT IDPEL FROM pelanggan WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOWA,'+',''),' ',''),'-',''),'(',''),')','') IN (?, ?, ?) LIMIT 1");
            $digits = preg_replace('/\D+/', '', $idSelect);
            $stmt2->bind_param('sss', $wa62, $wa0, $digits);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            if ($res2 && $res2->num_rows > 0) {
                $row2 = $res2->fetch_assoc();
                $idpel = (string)($row2['IDPEL'] ?? '');
            }
        }

        if ($idpel === '') {
            echo json_encode(['success' => false, 'error' => 'Nomor WhatsApp tidak terdaftar']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'idpel' => $idpel,
                'portal_url' => 'https://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=' . urlencode($idpel)
            ]
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'password_login') {
        $customerId = trim((string)($input['customer_id'] ?? ''));
        $passwordInput = trim((string)($input['portal_password'] ?? ''));

        if ($customerId === '' || $passwordInput === '') {
            echo json_encode(['success' => false, 'error' => 'ID pelanggan dan password wajib diisi']);
            exit;
        }

        $stmt = $conn->prepare('SELECT IDPEL, NOWA, PASSWORD FROM pelanggan WHERE IDPEL=? LIMIT 1');
        $stmt->bind_param('s', $customerId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res || $res->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'ID pelanggan tidak ditemukan']);
            exit;
        }

        $row = $res->fetch_assoc();
        $dbNowa = (string)($row['NOWA'] ?? '');
        $dbPassword = (string)($row['PASSWORD'] ?? '');

        $pwOk = false;
        $submittedDigits = preg_replace('/\D+/', '', $passwordInput);
        if ($dbNowa !== '') {
            $dbNowaDigits = preg_replace('/\D+/', '', $dbNowa);
            if ($submittedDigits !== '' && $submittedDigits === $dbNowaDigits) {
                $pwOk = true;
            }
        }

        if (!$pwOk && $dbPassword !== '') {
            if (password_verify($passwordInput, $dbPassword) || $passwordInput === $dbPassword) {
                $pwOk = true;
            } elseif ($submittedDigits !== '' && $submittedDigits === preg_replace('/\D+/', '', $dbPassword)) {
                $pwOk = true;
            }
        }

        if (!$pwOk) {
            echo json_encode(['success' => false, 'error' => 'ID pelanggan atau password salah']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'idpel' => (string)($row['IDPEL'] ?? ''),
                'portal_url' => 'https://quenbytekniksejahtera.com/crm/billing/broadband/portal_baru.php?cari=' . urlencode((string)($row['IDPEL'] ?? ''))
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Action tidak didukung']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
