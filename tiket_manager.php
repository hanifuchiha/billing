<?php
require 'header.php';

if (!isset($conn) || !$conn) {
    require_once 'koneksidb.php';
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Koneksi database billing tidak tersedia.</div></div>';
    require 'footer.php';
    exit;
}

$session_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$owner_user_id = isset($USER_ID) ? (int)$USER_ID : 0;
$is_assistant = isset($AKSES) && strtoupper((string)$AKSES) === 'ASSISTANT';
$can_create_ticket = !$is_assistant || (isset($akses_menu) && is_array($akses_menu) && in_array('Ticket_create', $akses_menu, true));
$default_types = ['INSTALLASI', 'MAINTENANCE', 'MIGRASI', 'DISMANTLE'];
$project_setting_types = ['INSTALLASI', 'MAINTENANCE', 'MIGRASI', 'DISMANTLE'];

if ($is_assistant) {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Ticket_manager', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Tiket Manager.</div></div>';
        require 'footer.php';
        exit;
    }
}

$create_ticket_sql = "CREATE TABLE IF NOT EXISTS billing_tiket_manager (
    id INT AUTO_INCREMENT PRIMARY KEY,
    judul VARCHAR(255) NOT NULL,
    detail TEXT,
    server_id INT NOT NULL,
    pemilik VARCHAR(120) NOT NULL,
    brand VARCHAR(120) DEFAULT '',
    area VARCHAR(200) DEFAULT '',
    project_name VARCHAR(191) DEFAULT '',
    tipe VARCHAR(40) DEFAULT 'INSTALLASI',
    report MEDIUMTEXT,
    status ENUM('BARU','PENDING','DONE','CANCEL') DEFAULT 'BARU',
    teknisi_user_id INT DEFAULT NULL,
    created_by_user_id INT NOT NULL,
    done_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_server (server_id),
    INDEX idx_status (status),
    INDEX idx_teknisi (teknisi_user_id),
    INDEX idx_created_by (created_by_user_id),
    INDEX idx_tipe (tipe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_ticket_sql);

// Backward-compatible migration for existing installations.
function ensureBillingTicketManagerColumn($conn, $column_name, $definition_sql)
{
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }

    $check_sql = "SHOW COLUMNS FROM billing_tiket_manager LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'";
    $check_res = mysqli_query($conn, $check_sql);
    if ($check_res && mysqli_num_rows($check_res) > 0) {
        return;
    }

    mysqli_query($conn, "ALTER TABLE billing_tiket_manager ADD COLUMN " . $definition_sql);
}

ensureBillingTicketManagerColumn($conn, 'project_name', "project_name VARCHAR(191) DEFAULT ''");
ensureBillingTicketManagerColumn($conn, 'tipe', "tipe VARCHAR(40) DEFAULT 'INSTALLASI'");
ensureBillingTicketManagerColumn($conn, 'report', 'report MEDIUMTEXT');
ensureBillingTicketManagerColumn($conn, 'done_at', 'done_at DATETIME DEFAULT NULL');

$create_project_sql = "CREATE TABLE IF NOT EXISTS billing_tiket_project_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    server_id INT NOT NULL,
    project_name VARCHAR(191) DEFAULT '',
    provisioning_enabled TINYINT(1) NOT NULL DEFAULT 0,
    customer_signature_enabled TINYINT(1) NOT NULL DEFAULT 0,
    hapus_billing_dismantle TINYINT(1) NOT NULL DEFAULT 0,
    tipe_aktif TEXT,
    evidence_per_tipe LONGTEXT,
    evidence_required_per_tipe LONGTEXT,
    report_format_per_tipe LONGTEXT,
    updated_by_user_id INT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_server (server_id),
    INDEX idx_updated_by (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $create_project_sql);

function ensureBillingProjectSettingColumn($conn, $column_name, $definition_sql)
{
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }

    $check_sql = "SHOW COLUMNS FROM billing_tiket_project_settings LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'";
    $check_res = mysqli_query($conn, $check_sql);
    if ($check_res && mysqli_num_rows($check_res) > 0) {
        return;
    }

    mysqli_query($conn, 'ALTER TABLE billing_tiket_project_settings ADD COLUMN ' . $definition_sql);
}

ensureBillingProjectSettingColumn($conn, 'provisioning_enabled', 'provisioning_enabled TINYINT(1) NOT NULL DEFAULT 0');
ensureBillingProjectSettingColumn($conn, 'customer_signature_enabled', 'customer_signature_enabled TINYINT(1) NOT NULL DEFAULT 0');
ensureBillingProjectSettingColumn($conn, 'hapus_billing_dismantle', 'hapus_billing_dismantle TINYINT(1) NOT NULL DEFAULT 0');

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function buildFilterUrl($overrides = [])
{
    $params = [
        'status' => $_GET['status'] ?? 'ALL',
        'q' => $_GET['q'] ?? '',
        'server_id' => $_GET['server_id'] ?? '',
        'brand' => $_GET['brand'] ?? '',
        'area' => $_GET['area'] ?? '',
        'teknisi' => $_GET['teknisi'] ?? '',
        'tipe' => $_GET['tipe'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? ''
    ];

    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }

    $clean = [];
    foreach ($params as $k => $v) {
        if ($v !== '' && $v !== null) {
            $clean[$k] = $v;
        }
    }

    return '?' . http_build_query($clean);
}

function normalizeFilename($name)
{
    $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', (string)$name);
    if ($safe === '' || $safe === null) {
        $safe = 'file';
    }
    return $safe;
}

function normalizeEvidenceTitleKey($title)
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$title));
}

function ticketEvidenceHasEntriesForTitle($evidence_meta, $title)
{
    if (!is_array($evidence_meta)) {
        return false;
    }

    $title = (string)$title;
    if (isset($evidence_meta[$title]) && is_array($evidence_meta[$title]) && count($evidence_meta[$title]) > 0) {
        return true;
    }

    $target = normalizeEvidenceTitleKey($title);
    if ($target === '') {
        return false;
    }

    foreach ($evidence_meta as $meta_title => $entries) {
        if (!is_array($entries) || count($entries) <= 0) {
            continue;
        }
        if (normalizeEvidenceTitleKey((string)$meta_title) === $target) {
            return true;
        }
    }

    return false;
}

function isSignatureEvidenceTitle($title)
{
    $t = strtolower(trim((string)$title));
    return $t === 'tanda tangan pelanggan' || $t === 'tandatangan pelanggan' || $t === 'customer signature' || $t === 'signature';
}

function getAllowedServersForUser($conn, $owner_user_id, $session_user_id, $is_assistant)
{
    $allowed = [];

    if ($is_assistant) {
        $server_ids = [];
        $q = $conn->prepare('SELECT server FROM user WHERE id = ? LIMIT 1');
        if ($q) {
            $q->bind_param('i', $session_user_id);
            $q->execute();
            $res = $q->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $q->close();

            if ($row && !empty($row['server'])) {
                $decoded = json_decode((string)$row['server'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $sid) {
                        $server_ids[] = (int)$sid;
                    }
                } else {
                    $server_ids[] = (int)$row['server'];
                }
            }
        }

        $server_ids = array_values(array_unique(array_filter($server_ids)));
        if (empty($server_ids)) {
            return [];
        }

        $id_in = implode(',', array_map('intval', $server_ids));
        $sql = 'SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ' . (int)$owner_user_id . ' AND id IN (' . $id_in . ') ORDER BY BRAND, AREA';
        $r = mysqli_query($conn, $sql);
        if ($r) {
            while ($s = mysqli_fetch_assoc($r)) {
                $allowed[(int)$s['id']] = $s;
            }
        }

        return $allowed;
    }

    $sql = 'SELECT id, PEMILIK, BRAND, AREA FROM server WHERE user_id = ' . (int)$owner_user_id . ' ORDER BY BRAND, AREA';
    $r = mysqli_query($conn, $sql);
    if ($r) {
        while ($s = mysqli_fetch_assoc($r)) {
            $allowed[(int)$s['id']] = $s;
        }
    }
    return $allowed;
}

function parseJsonAssoc($raw, $fallback)
{
    if ($raw === null || $raw === '') {
        return $fallback;
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $fallback;
    }
    return $decoded;
}

function loadProjectSettingsMap($conn, $allowed_server_ids, $default_types)
{
    $map = [];
    $project_types = ['INSTALLASI', 'MAINTENANCE', 'MIGRASI', 'DISMANTLE'];
    if (empty($allowed_server_ids)) {
        return $map;
    }

    $id_in = implode(',', array_map('intval', $allowed_server_ids));
    $sql = 'SELECT * FROM billing_tiket_project_settings WHERE server_id IN (' . $id_in . ')';
    $res = mysqli_query($conn, $sql);
    if (!$res) {
        return $map;
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $server_id = (int)$row['server_id'];
        $tipe_aktif = parseJsonAssoc($row['tipe_aktif'] ?? '[]', []);
        $evidence_per_tipe = parseJsonAssoc($row['evidence_per_tipe'] ?? '{}', []);
        $evidence_required_per_tipe = parseJsonAssoc($row['evidence_required_per_tipe'] ?? '{}', []);
        $report_format_per_tipe = parseJsonAssoc($row['report_format_per_tipe'] ?? '{}', []);

        $normalized_types = [];
        foreach ($tipe_aktif as $t) {
            $t = strtoupper(trim((string)$t));
            if ($t !== '' && in_array($t, $project_types, true)) {
                $normalized_types[] = $t;
            }
        }
        if (empty($normalized_types)) {
            $normalized_types = ['INSTALLASI'];
        }

        $provisioning_enabled = (int)($row['provisioning_enabled'] ?? 0) === 1;
        $customer_signature_enabled = (int)($row['customer_signature_enabled'] ?? 0) === 1;
        $hapus_billing_dismantle = (int)($row['hapus_billing_dismantle'] ?? 0) === 1;

        $map[$server_id] = [
            'server_id' => $server_id,
            'project_name' => trim((string)($row['project_name'] ?? '')),
            'provisioning_enabled' => $provisioning_enabled,
            'customer_signature_enabled' => $customer_signature_enabled,
            'hapus_billing_dismantle' => $hapus_billing_dismantle,
            'tipe_aktif' => array_values(array_unique($normalized_types)),
            'evidence_per_tipe' => is_array($evidence_per_tipe) ? $evidence_per_tipe : [],
            'evidence_required_per_tipe' => is_array($evidence_required_per_tipe) ? $evidence_required_per_tipe : [],
            'report_format_per_tipe' => is_array($report_format_per_tipe) ? $report_format_per_tipe : []
        ];
    }

    return $map;
}

function getProjectNameForTicket($server_row, $setting)
{
    $configured = trim((string)($setting['project_name'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    $brand = trim((string)($server_row['BRAND'] ?? ''));
    $area = trim((string)($server_row['AREA'] ?? ''));
    if ($brand !== '' && $area !== '') {
        return $brand . ' - ' . $area;
    }
    if ($brand !== '') {
        return $brand;
    }
    if ($area !== '') {
        return $area;
    }
    return trim((string)($server_row['PEMILIK'] ?? 'PROJECT'));
}

function getEvidenceFieldsForType($setting, $type)
{
    $type = strtoupper(trim((string)$type));
    $fields = [];
    $evidence_per_tipe = isset($setting['evidence_per_tipe']) && is_array($setting['evidence_per_tipe']) ? $setting['evidence_per_tipe'] : [];
    $required_per_tipe = isset($setting['evidence_required_per_tipe']) && is_array($setting['evidence_required_per_tipe']) ? $setting['evidence_required_per_tipe'] : [];

    $items = isset($evidence_per_tipe[$type]) && is_array($evidence_per_tipe[$type]) ? $evidence_per_tipe[$type] : [];
    $required = isset($required_per_tipe[$type]) && is_array($required_per_tipe[$type]) ? $required_per_tipe[$type] : [];

    if (empty($items)) {
        $defaultEvidence = [
            'INSTALLASI' => [
                ['title' => 'foto ODP', 'required' => true],
                ['title' => 'foto ONT terpasang', 'required' => true],
                ['title' => 'speedtest', 'required' => true],
                ['title' => 'foto rumah pelanggan', 'required' => false]
            ],
            'MAINTENANCE' => [
                ['title' => 'foto before', 'required' => true],
                ['title' => 'foto after', 'required' => true],
                ['title' => 'hasil pengecekan', 'required' => false]
            ],
            'MIGRASI' => [
                ['title' => 'foto perangkat lama', 'required' => true],
                ['title' => 'foto perangkat baru', 'required' => true],
                ['title' => 'hasil speedtest', 'required' => false]
            ],
            'DISMANTLE' => [
                ['title' => 'foto perangkat dicabut', 'required' => true],
                ['title' => 'foto ODP final', 'required' => true],
                ['title' => 'foto bukti lokasi', 'required' => false]
            ],
            'PROVISIONING' => [
                ['title' => 'foto ODP', 'required' => true],
                ['title' => 'foto ONT', 'required' => true],
                ['title' => 'foto titik lokasi', 'required' => false]
            ]
        ];
        $defaultFields = isset($defaultEvidence[$type]) ? $defaultEvidence[$type] : [];
        if ($type === 'INSTALLASI' && !empty($setting['customer_signature_enabled'])) {
            $defaultFields[] = ['title' => 'Tanda tangan pelanggan', 'required' => true];
        }
        return $defaultFields;
    }

    foreach ($items as $idx => $item) {
        $title = trim((string)$item);
        if ($title === '') {
            continue;
        }
        $flag = (int)($required[$idx] ?? 0) === 1;
        $fields[] = ['title' => $title, 'required' => $flag];
    }

    if ($type === 'INSTALLASI' && !empty($setting['customer_signature_enabled'])) {
        $hasSignatureField = false;
        foreach ($fields as $f) {
            $ft = strtolower(trim((string)($f['title'] ?? '')));
            if ($ft === 'tanda tangan pelanggan' || $ft === 'tandatangan pelanggan' || $ft === 'customer signature' || $ft === 'signature') {
                $hasSignatureField = true;
                break;
            }
        }
        if (!$hasSignatureField) {
            $fields[] = ['title' => 'Tanda tangan pelanggan', 'required' => true];
        }
    }

    return $fields;
}

function getReportTemplateForType($setting, $type)
{
    $type = strtoupper(trim((string)$type));
    $map = isset($setting['report_format_per_tipe']) && is_array($setting['report_format_per_tipe']) ? $setting['report_format_per_tipe'] : [];
    $tpl = trim((string)($map[$type] ?? ''));
    if ($tpl !== '') {
        return $tpl;
    }

    $defaultTemplates = [
        'INSTALLASI' => "GIAT:\nPROJECT: {nama_project}\nTanggal Pelaksana: {tanggal}\nNama Teknisi: {nama}\nData Tiket:\n{data}\nStatus:",
        'MAINTENANCE' => "GIAT MAINTENANCE\nPROJECT: {nama_project}\nTanggal Pelaksana: {tanggal}\nNama Teknisi: {nama}\nKendala:\n{data}\nTindakan:\nHasil:",
        'MIGRASI' => "GIAT MIGRASI\nPROJECT: {nama_project}\nTanggal Pelaksana: {tanggal}\nNama Teknisi: {nama}\nData Tiket:\n{data}\nPerangkat Lama:\nPerangkat Baru:\nStatus:",
        'DISMANTLE' => "GIAT DISMANTLE\nPROJECT: {nama_project}\nTanggal Pelaksana: {tanggal}\nNama Teknisi: {nama}\nID Pelanggan:\nData Tiket:\n{data}\nStatus:",
        'PROVISIONING' => "GIAT PROVISIONING\nPROJECT: {nama_project}\nTanggal Pelaksana: {tanggal}\nNama Teknisi: {nama}\nNama Pelanggan:\nID Pelanggan:\nPaket:\nData Tiket:\n{data}\nStatus:"
    ];

    return isset($defaultTemplates[$type]) ? $defaultTemplates[$type] : '';
}

function getEvidenceMetaPath($ticket_id)
{
    return __DIR__ . '/../../dokumen/tiket/' . (int)$ticket_id . '/evidence_meta.json';
}

function loadTicketEvidenceMeta($ticket_id)
{
    $file = getEvidenceMetaPath($ticket_id);
    if (!is_file($file)) {
        return [];
    }

    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}

function saveTicketEvidenceMeta($ticket_id, $meta)
{
    $dir = __DIR__ . '/../../dokumen/tiket/' . (int)$ticket_id;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(getEvidenceMetaPath($ticket_id), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function tmParseTicketData($text)
{
    $result = [
        'idpel' => '',
        'nama' => '',
        'alamat' => '',
        'provinsi' => '',
        'kabupaten' => '',
        'kecamatan' => '',
        'kelurahan' => '',
        'rw' => '',
        'rt' => '',
        'nowa' => '',
        'email' => '',
        'tikor' => '',
        'paket' => '',
        'odp' => '',
        'sales' => ''
    ];

    $text = (string)$text;
    if (preg_match('/ID\s*PELANGGAN\s*:?\s*([A-Za-z0-9@._-]+)/i', $text, $m)) {
        $result['idpel'] = trim((string)$m[1]);
    }

    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ((array)$lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^Nama\s*:\s*(.+)/i', $line, $m)) {
            $result['nama'] = trim((string)$m[1]);
        } elseif (preg_match('/^Alamat\s*:\s*(.+)/i', $line, $m)) {
            $result['alamat'] = trim((string)$m[1]);
        } elseif (preg_match('/^Provinsi\s*:\s*(.+)/i', $line, $m)) {
            $result['provinsi'] = trim((string)$m[1]);
        } elseif (preg_match('/^Kabupaten\/?Kota\s*:\s*(.+)/i', $line, $m)) {
            $result['kabupaten'] = trim((string)$m[1]);
        } elseif (preg_match('/^Kecamatan\s*:\s*(.+)/i', $line, $m)) {
            $result['kecamatan'] = trim((string)$m[1]);
        } elseif (preg_match('/^Kelurahan\s*:\s*(.+)/i', $line, $m)) {
            $result['kelurahan'] = trim((string)$m[1]);
        } elseif (preg_match('/^RW\s*:\s*(.+)/i', $line, $m)) {
            $result['rw'] = trim((string)$m[1]);
        } elseif (preg_match('/^RT\s*:\s*(.+)/i', $line, $m)) {
            $result['rt'] = trim((string)$m[1]);
        } elseif (preg_match('/^(No\s*)?WA(\s*Whatsapp)?\s*:\s*(.+)/i', $line, $m) || preg_match('/^No\s*Whatsapp\s*:\s*(.+)/i', $line, $m)) {
            $result['nowa'] = trim((string)end($m));
        } elseif (preg_match('/^Email\s*:\s*(.+)/i', $line, $m)) {
            $result['email'] = trim((string)$m[1]);
        } elseif (preg_match('/^Tikor\s*:\s*(.+)/i', $line, $m)) {
            $result['tikor'] = trim((string)$m[1]);
        } elseif (preg_match('/^ODP\s*:\s*(.+)/i', $line, $m)) {
            $result['odp'] = trim((string)$m[1]);
        } elseif (preg_match('/^Paket\s*(?:langganan)?\s*:\s*(.+)/i', $line, $m)) {
            $result['paket'] = trim((string)$m[1]);
        } elseif (preg_match('/mendaftar\s+melalui\s+sales\s+(.+)/i', $line, $m)) {
            $result['sales'] = trim((string)$m[1]);
        }
    }

    return $result;
}

function tmNormalizeWhatsapp($value)
{
    $value = preg_replace('/[^0-9+]/', '', (string)$value);
    if ($value === null) {
        $value = '';
    }
    if ($value === '') {
        return '';
    }
    if (strpos($value, '+62') === 0) {
        return '62' . substr($value, 3);
    }
    if (strpos($value, '62') === 0) {
        return $value;
    }
    if (strpos($value, '0') === 0) {
        return '62' . substr($value, 1);
    }
    return $value;
}

function tmGenerateCustomerId($conn, $ownerPemilik)
{
    $ownerPemilik = trim((string)$ownerPemilik);
    if ($ownerPemilik === '') {
        return '';
    }

    $inisial = '';
    $ownerUsername = $ownerPemilik;

    $stmtUser = $conn->prepare('SELECT inisial, USERNAME FROM user WHERE USERNAME = ? LIMIT 1');
    if ($stmtUser) {
        $stmtUser->bind_param('s', $ownerPemilik);
        $stmtUser->execute();
        $resUser = $stmtUser->get_result();
        if ($resUser && ($rowUser = $resUser->fetch_assoc())) {
            $inisial = trim((string)($rowUser['inisial'] ?? ''));
            $ownerUsername = trim((string)($rowUser['USERNAME'] ?? $ownerPemilik));
        }
        $stmtUser->close();
    }

    if ($inisial === '') {
        $words = preg_split('/\s+/', strtoupper((string)$ownerUsername));
        $initials = '';
        foreach ((array)$words as $w) {
            $w = trim((string)$w);
            if ($w === '') {
                continue;
            }
            $initials .= substr($w, 0, 1);
            if (strlen($initials) >= 3) {
                break;
            }
        }
        if (strlen($initials) < 3) {
            $plain = strtoupper(str_replace(' ', '', (string)$ownerUsername));
            $initials .= substr($plain, strlen($initials), max(0, 3 - strlen($initials)));
        }
        $inisial = substr($initials, 0, 3);
    }

    if ($inisial === '') {
        return '';
    }

    $usedNumbers = [];
    $prefixLike = $inisial . '-%';

    $stmtPel = $conn->prepare('SELECT IDPEL FROM pelanggan WHERE IDPEL LIKE ?');
    if ($stmtPel) {
        $stmtPel->bind_param('s', $prefixLike);
        $stmtPel->execute();
        $resPel = $stmtPel->get_result();
        while ($resPel && ($rowPel = $resPel->fetch_assoc())) {
            if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', (string)$rowPel['IDPEL'], $m)) {
                $usedNumbers[(int)$m[1]] = true;
            }
        }
        $stmtPel->close();
    }

    $stmtProv = $conn->prepare("SELECT idpel FROM provisioning WHERE idpel LIKE ? AND status='PENDING'");
    if ($stmtProv) {
        $stmtProv->bind_param('s', $prefixLike);
        $stmtProv->execute();
        $resProv = $stmtProv->get_result();
        while ($resProv && ($rowProv = $resProv->fetch_assoc())) {
            if (preg_match('/^' . preg_quote($inisial, '/') . '-(\d{3})(?:@|$)/', (string)$rowProv['idpel'], $m)) {
                $usedNumbers[(int)$m[1]] = true;
            }
        }
        $stmtProv->close();
    }

    for ($i = 1; $i <= 999; $i++) {
        if (!isset($usedNumbers[$i])) {
            return $inisial . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '@' . date('dmy');
        }
    }

    return '';
}

function tmEnsureProvisioningTable($conn)
{
    $check = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
    if ($check && mysqli_num_rows($check) > 0) {
        return;
    }

    $createSql = "CREATE TABLE `provisioning` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `idpel` VARCHAR(100) NOT NULL,
        `password_pppoe` VARCHAR(100) NOT NULL,
        `nama` VARCHAR(200) NOT NULL,
        `alamat` TEXT,
        `provinsi` VARCHAR(100) DEFAULT '',
        `kabupaten` VARCHAR(100) DEFAULT '',
        `kecamatan` VARCHAR(100) DEFAULT '',
        `kelurahan` VARCHAR(100) DEFAULT '',
        `rw` VARCHAR(10) DEFAULT '',
        `rt` VARCHAR(10) DEFAULT '',
        `nowa` VARCHAR(50) DEFAULT '',
        `email` VARCHAR(200) DEFAULT '',
        `tikor` VARCHAR(100) DEFAULT '',
        `paket` VARCHAR(100) DEFAULT '',
        `harga` VARCHAR(50) DEFAULT '0',
        `server_pemilik` VARCHAR(100) NOT NULL,
        `server_brand` VARCHAR(100) DEFAULT '',
        `area` VARCHAR(100) DEFAULT '',
        `odp` VARCHAR(100) DEFAULT '',
        `auth_mode` VARCHAR(50) DEFAULT 'MULTI MODE',
        `tipe_bayar` VARCHAR(50) DEFAULT 'prabayar',
        `tipe_tempo` VARCHAR(100) DEFAULT 'mengikuti_tanggal_bayar',
        `sales` VARCHAR(100) DEFAULT '',
        `tanggal_pasang` DATE,
        `tiket_id` VARCHAR(100) DEFAULT '',
        `project_joblist` VARCHAR(100) DEFAULT '',
        `teknisi` VARCHAR(200) DEFAULT '',
        `status` ENUM('PENDING','APPROVED','REJECTED','EXPIRED') DEFAULT 'PENDING',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `expired_at` DATETIME,
        `approved_at` DATETIME DEFAULT NULL,
        `approved_by` VARCHAR(100) DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_server (server_pemilik),
        INDEX idx_expired (expired_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $createSql);
}

function tmGetTeknisiName($conn, $teknisiUserId)
{
    $teknisiUserId = (int)$teknisiUserId;
    if ($teknisiUserId <= 0) {
        return '';
    }
    $stmt = $conn->prepare('SELECT USERNAME FROM user WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param('i', $teknisiUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $name = '';
    if ($res && ($row = $res->fetch_assoc())) {
        $name = trim((string)($row['USERNAME'] ?? ''));
    }
    $stmt->close();
    return $name;
}

function tmQueueProvisioningFromTicket($conn, $ticketRow, $reportText, $detailText, $teknisiName, &$message)
{
    tmEnsureProvisioningTable($conn);

    $parsed = tmParseTicketData((string)$reportText . "\n" . (string)$detailText);
    $owner = trim((string)($ticketRow['pemilik'] ?? ''));
    $brand = trim((string)($ticketRow['brand'] ?? ''));
    $area = trim((string)($ticketRow['area'] ?? ''));
    $projectName = trim((string)($ticketRow['project_name'] ?? ''));
    $tiketId = (string)((int)($ticketRow['id'] ?? 0));

    $nama = trim((string)$parsed['nama']);
    if ($nama === '') {
        $message = 'Proses provisioning gagal: Nama pelanggan belum terdeteksi dari detail/report tiket.';
        return false;
    }

    $idpel = trim((string)$parsed['idpel']);
    if ($idpel !== '' && (strlen($idpel) > 64 || $idpel[0] === '#' || preg_match('/[\x00-\x20"\\\\\x7F]/', $idpel))) {
        // ID pelanggan hasil parsing teks bebas laporan/detail tiket bisa mengandung
        // karakter yang tidak aman untuk ditulis ke file users FreeRADIUS (spasi,
        // kutip, backslash, dst — lihat validasi sejenis di
        // crm/billing/proses_provisioning_action.php). Field idpel di provisioning
        // approval modal bersifat hidden/tidak bisa diedit, jadi daripada tiket
        // nyangkut gagal di langkah Approve tanpa cara memperbaiki, fallback ke
        // auto-generate seperti kalau idpel kosong.
        $idpel = '';
    }
    if ($idpel === '') {
        $idpel = tmGenerateCustomerId($conn, $owner);
    }
    if ($idpel === '') {
        $message = 'Proses provisioning gagal: ID pelanggan tidak ditemukan dan auto-generate gagal.';
        return false;
    }

    $stmtExistPel = $conn->prepare('SELECT ID FROM pelanggan WHERE IDPEL = ? LIMIT 1');
    if ($stmtExistPel) {
        $stmtExistPel->bind_param('s', $idpel);
        $stmtExistPel->execute();
        $resExistPel = $stmtExistPel->get_result();
        if ($resExistPel && $resExistPel->num_rows > 0) {
            $stmtExistPel->close();
            $message = 'IDPEL ' . $idpel . ' sudah ada di data pelanggan billing.';
            return true;
        }
        $stmtExistPel->close();
    }

    $stmtExistProv = $conn->prepare("SELECT id FROM provisioning WHERE idpel = ? AND status = 'PENDING' LIMIT 1");
    if ($stmtExistProv) {
        $stmtExistProv->bind_param('s', $idpel);
        $stmtExistProv->execute();
        $resExistProv = $stmtExistProv->get_result();
        if ($resExistProv && $resExistProv->num_rows > 0) {
            $stmtExistProv->close();
            $message = 'IDPEL ' . $idpel . ' sudah ada di antrian provisioning.';
            return true;
        }
        $stmtExistProv->close();
    }

    $paket = trim((string)$parsed['paket']);
    $odp = trim((string)$parsed['odp']);
    $alamat = trim((string)$parsed['alamat']);
    $nowa = tmNormalizeWhatsapp((string)$parsed['nowa']);
    $email = trim((string)$parsed['email']);
    $tikor = trim((string)$parsed['tikor']);
    $sales = trim((string)$parsed['sales']);
    $provinsi = trim((string)$parsed['provinsi']);
    $kabupaten = trim((string)$parsed['kabupaten']);
    $kecamatan = trim((string)$parsed['kecamatan']);
    $kelurahan = trim((string)$parsed['kelurahan']);
    $rw = trim((string)$parsed['rw']);
    $rt = trim((string)$parsed['rt']);
    $tanggalPasang = date('Y-m-d');
    $authMode = 'MULTI MODE';
    $tipeBayar = 'prabayar';
    $tipeTempo = 'mengikuti_tanggal_bayar';
    $harga = '0';

    if ($paket !== '' && $owner !== '') {
        $stmtHarga = $conn->prepare('SELECT HARGA FROM paket WHERE PAKET = ? AND PEMILIK = ? ORDER BY id DESC LIMIT 1');
        if ($stmtHarga) {
            $stmtHarga->bind_param('ss', $paket, $owner);
            $stmtHarga->execute();
            $resHarga = $stmtHarga->get_result();
            if ($resHarga && ($rowHarga = $resHarga->fetch_assoc())) {
                $harga = (string)($rowHarga['HARGA'] ?? '0');
            }
            $stmtHarga->close();
        }
    }

    $expiredAt = date('Y-m-d H:i:s', strtotime('+3 days'));
    $passwordPppoe = $idpel;

    $sqlInsert = "INSERT INTO provisioning (idpel, password_pppoe, nama, alamat, provinsi, kabupaten, kecamatan, kelurahan, rw, rt, nowa, email, tikor, paket, harga, server_pemilik, server_brand, area, odp, auth_mode, tipe_bayar, tipe_tempo, sales, tanggal_pasang, tiket_id, project_joblist, teknisi, status, expired_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', ?)";
    $stmtIns = $conn->prepare($sqlInsert);
    if (!$stmtIns) {
        $message = 'Proses provisioning gagal: ' . $conn->error;
        return false;
    }

    $stmtIns->bind_param(
        'ssssssssssssssssssssssssssss',
        $idpel,
        $passwordPppoe,
        $nama,
        $alamat,
        $provinsi,
        $kabupaten,
        $kecamatan,
        $kelurahan,
        $rw,
        $rt,
        $nowa,
        $email,
        $tikor,
        $paket,
        $harga,
        $owner,
        $brand,
        $area,
        $odp,
        $authMode,
        $tipeBayar,
        $tipeTempo,
        $sales,
        $tanggalPasang,
        $tiketId,
        $projectName,
        $teknisiName,
        $expiredAt
    );

    if (!$stmtIns->execute()) {
        $message = 'Proses provisioning gagal: ' . $stmtIns->error;
        $stmtIns->close();
        return false;
    }
    $stmtIns->close();

    $message = 'Provisioning masuk antrian billing (PENDING) untuk IDPEL ' . $idpel . '.';
    return true;
}

function tmExtractIdpelFromText($reportText, $detailText)
{
    $merged = (string)$reportText . "\n" . (string)$detailText;
    if (preg_match('/ID\s*PELANGGAN\s*:?\s*([A-Za-z0-9@._-]+)/i', $merged, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

function tmProcessDismantleFromTicket($conn, $ticketRow, $reportText, $detailText, &$message)
{
    $idpel = tmExtractIdpelFromText($reportText, $detailText);
    if ($idpel === '') {
        $message = 'Proses dismantle gagal: ID PELANGGAN tidak ditemukan di detail/report tiket.';
        return false;
    }

    $owner = trim((string)($ticketRow['pemilik'] ?? ''));
    $stmtPel = $conn->prepare('SELECT * FROM pelanggan WHERE IDPEL = ? AND PEMILIK = ? LIMIT 1');
    if (!$stmtPel) {
        $message = 'Proses dismantle gagal: ' . $conn->error;
        return false;
    }
    $stmtPel->bind_param('ss', $idpel, $owner);
    $stmtPel->execute();
    $resPel = $stmtPel->get_result();
    $pelanggan = $resPel ? $resPel->fetch_assoc() : null;
    $stmtPel->close();

    if (!$pelanggan) {
        $message = 'IDPEL ' . $idpel . ' tidak ditemukan di pelanggan billing. Laporan tetap disimpan.';
        return true;
    }

    $idpelBerhenti = (string)($pelanggan['IDPEL'] ?? '');
    $namaBerhenti = (string)($pelanggan['NAMA'] ?? '');
    $tempoBerhenti = (string)($pelanggan['TEMPO'] ?? '');
    $hargaBerhenti = (string)($pelanggan['HARGA'] ?? '');
    $pemilikBerhenti = (string)($pelanggan['PEMILIK'] ?? '');
    $alamatBerhenti = (string)($pelanggan['ALAMAT'] ?? '');
    $nowaBerhenti = (string)($pelanggan['NOWA'] ?? '');
    $paketBerhenti = (string)($pelanggan['PAKET'] ?? '');
    $alasanBerhenti = 'Dismantle';
    $tanggalBerhenti = date('Y-m-d');
    $keteranganBerhenti = 'Dismantle';

    $stmtCheckBerhenti = $conn->prepare('SELECT id FROM pelanggan_berhenti WHERE idpel = ? AND DATE(tanggal_berhenti) = ? LIMIT 1');
    $alreadyMoved = false;
    if ($stmtCheckBerhenti) {
        $stmtCheckBerhenti->bind_param('ss', $idpelBerhenti, $tanggalBerhenti);
        $stmtCheckBerhenti->execute();
        $resCheckBerhenti = $stmtCheckBerhenti->get_result();
        $alreadyMoved = $resCheckBerhenti && $resCheckBerhenti->num_rows > 0;
        $stmtCheckBerhenti->close();
    }

    if (!$alreadyMoved) {
        $stmtInsBerhenti = $conn->prepare('INSERT INTO pelanggan_berhenti (idpel, nama, tempo, harga, pemilik, alamat, nowa, paket, alasan, tanggal_berhenti, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmtInsBerhenti) {
            $message = 'Proses dismantle gagal: ' . $conn->error;
            return false;
        }
        $stmtInsBerhenti->bind_param(
            'sssssssssss',
            $idpelBerhenti,
            $namaBerhenti,
            $tempoBerhenti,
            $hargaBerhenti,
            $pemilikBerhenti,
            $alamatBerhenti,
            $nowaBerhenti,
            $paketBerhenti,
            $alasanBerhenti,
            $tanggalBerhenti,
            $keteranganBerhenti
        );
        if (!$stmtInsBerhenti->execute()) {
            $message = 'Proses dismantle gagal saat simpan pelanggan_berhenti: ' . $stmtInsBerhenti->error;
            $stmtInsBerhenti->close();
            return false;
        }
        $stmtInsBerhenti->close();
    }

    $stmtDelPel = $conn->prepare('DELETE FROM pelanggan WHERE IDPEL = ? AND PEMILIK = ? LIMIT 1');
    if (!$stmtDelPel) {
        $message = 'Proses dismantle gagal saat hapus pelanggan: ' . $conn->error;
        return false;
    }
    $stmtDelPel->bind_param('ss', $idpelBerhenti, $pemilikBerhenti);
    if (!$stmtDelPel->execute()) {
        $message = 'Proses dismantle gagal saat hapus pelanggan: ' . $stmtDelPel->error;
        $stmtDelPel->close();
        return false;
    }
    $stmtDelPel->close();

    $message = 'Pelanggan ' . $idpelBerhenti . ' berhasil dipindah ke pelanggan_berhenti dan dihapus dari pelanggan.';
    return true;
}

$allowed_servers = getAllowedServersForUser($conn, $owner_user_id, $session_user_id, $is_assistant);
$allowed_server_ids = array_map('intval', array_keys($allowed_servers));

if (empty($allowed_server_ids)) {
    echo '<div class="container-fluid py-4"><div class="alert alert-warning">Tidak ada server yang dapat diakses untuk Tiket Manager.</div></div>';
    require 'footer.php';
    exit;
}

$project_settings_map = loadProjectSettingsMap($conn, $allowed_server_ids, $default_types);
$notice = '';
$error = '';

if (isset($_POST['save_project_settings']) && !$is_assistant) {
    $server_id = (int)($_POST['settings_server_id'] ?? 0);
    if (!isset($allowed_servers[$server_id])) {
        $error = 'Server untuk setting project tidak valid.';
    } else {
        $project_name = trim((string)($_POST['project_name'] ?? ''));
        $tipe_aktif = isset($_POST['tipe_aktif']) && is_array($_POST['tipe_aktif']) ? $_POST['tipe_aktif'] : [];

        $provisioning_enabled = isset($_POST['provisioning_enabled']) ? 1 : 0;
        $customer_signature_enabled = isset($_POST['customer_signature_enabled']) ? 1 : 0;
        $hapus_billing_dismantle = isset($_POST['hapus_billing_dismantle']) ? 1 : 0;
        $normalized_types = [];
        foreach ($tipe_aktif as $t) {
            $t = strtoupper(trim((string)$t));
            if ($t !== '' && in_array($t, $project_setting_types, true)) {
                $normalized_types[] = $t;
            }
        }
        $normalized_types = array_values(array_unique($normalized_types));
        if (empty($normalized_types)) {
            $normalized_types = ['INSTALLASI'];
        }

        $evidence_per_tipe = [];
        $evidence_required_per_tipe = [];
        $report_format_per_tipe = [];

        foreach ($project_setting_types as $tp) {
            $titles_raw = trim((string)($_POST['evidence_titles'][$tp] ?? ''));
            $required_raw = trim((string)($_POST['evidence_required_titles'][$tp] ?? ''));
            $report_raw = trim((string)($_POST['report_format'][$tp] ?? ''));

            $titles = preg_split('/\r\n|\r|\n/', $titles_raw);
            $required_titles = preg_split('/\r\n|\r|\n/', $required_raw);

            $title_list = [];
            $required_flags = [];
            $required_lookup = [];

            foreach ((array)$required_titles as $rt) {
                $rt = trim((string)$rt);
                if ($rt !== '') {
                    $required_lookup[strtolower($rt)] = true;
                }
            }

            foreach ((array)$titles as $title) {
                $title = trim((string)$title);
                if ($title === '') {
                    continue;
                }
                $title_list[] = $title;
                $required_flags[] = isset($required_lookup[strtolower($title)]) ? 1 : 0;
            }

            $evidence_per_tipe[$tp] = $title_list;
            $evidence_required_per_tipe[$tp] = $required_flags;
            $report_format_per_tipe[$tp] = $report_raw;
        }

        $stmt_setting = $conn->prepare('INSERT INTO billing_tiket_project_settings (server_id, project_name, provisioning_enabled, customer_signature_enabled, hapus_billing_dismantle, tipe_aktif, evidence_per_tipe, evidence_required_per_tipe, report_format_per_tipe, updated_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE project_name = VALUES(project_name), provisioning_enabled = VALUES(provisioning_enabled), customer_signature_enabled = VALUES(customer_signature_enabled), hapus_billing_dismantle = VALUES(hapus_billing_dismantle), tipe_aktif = VALUES(tipe_aktif), evidence_per_tipe = VALUES(evidence_per_tipe), evidence_required_per_tipe = VALUES(evidence_required_per_tipe), report_format_per_tipe = VALUES(report_format_per_tipe), updated_by_user_id = VALUES(updated_by_user_id)');

        if ($stmt_setting) {
            $tipe_json = json_encode($normalized_types, JSON_UNESCAPED_UNICODE);
            $ev_json = json_encode($evidence_per_tipe, JSON_UNESCAPED_UNICODE);
            $ev_req_json = json_encode($evidence_required_per_tipe, JSON_UNESCAPED_UNICODE);
            $report_json = json_encode($report_format_per_tipe, JSON_UNESCAPED_UNICODE);
            $stmt_setting->bind_param('isiiissssi', $server_id, $project_name, $provisioning_enabled, $customer_signature_enabled, $hapus_billing_dismantle, $tipe_json, $ev_json, $ev_req_json, $report_json, $session_user_id);

            if ($stmt_setting->execute()) {
                $notice = 'Setting project billing berhasil disimpan.';
                $project_settings_map = loadProjectSettingsMap($conn, $allowed_server_ids, $default_types);
            } else {
                $error = 'Gagal menyimpan setting project: ' . $stmt_setting->error;
            }
            $stmt_setting->close();
        } else {
            $error = 'Gagal menyiapkan query setting project.';
        }
    }
}

if (isset($_POST['create_ticket'])) {
    if (!$can_create_ticket) {
        $error = 'Anda tidak memiliki izin untuk membuat tiket.';
    } else {
        $judul = trim((string)($_POST['judul'] ?? ''));
        $detail = trim((string)($_POST['detail'] ?? ''));
        $server_id = (int)($_POST['server_id'] ?? 0);
        $teknisi_user_id = (int)($_POST['teknisi_user_id'] ?? 0);
        $tipe = strtoupper(trim((string)($_POST['tipe'] ?? 'INSTALLASI')));

        if ($judul === '') {
            $error = 'Judul tiket wajib diisi.';
        } elseif (!$is_assistant && $teknisi_user_id <= 0) {
            $error = 'Assign Teknisi wajib dipilih.';
        } elseif (!isset($allowed_servers[$server_id])) {
            $error = 'Server tidak valid.';
        } else {
            $srv = $allowed_servers[$server_id];
            $setting = isset($project_settings_map[$server_id]) ? $project_settings_map[$server_id] : [];
            $active_types = isset($setting['tipe_aktif']) && is_array($setting['tipe_aktif']) && !empty($setting['tipe_aktif']) ? $setting['tipe_aktif'] : $default_types;
            if (!in_array($tipe, $active_types, true)) {
                $tipe = $active_types[0];
            }

            $project_name = getProjectNameForTicket($srv, $setting);
            $owner_pemilik = (string)$srv['PEMILIK'];
            $brand_val = (string)$srv['BRAND'];
            $area_val = (string)$srv['AREA'];
            $empty_report = '';

            $stmt_insert = $conn->prepare("INSERT INTO billing_tiket_manager (judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'BARU', ?, ?)");
            if ($stmt_insert) {
                $stmt_insert->bind_param('ssissssssii', $judul, $detail, $server_id, $owner_pemilik, $brand_val, $area_val, $project_name, $tipe, $empty_report, $teknisi_user_id, $session_user_id);

                if ($stmt_insert->execute()) {
                    $notice = 'Tiket baru berhasil dibuat.';
                } else {
                    $error = 'Gagal membuat tiket: ' . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                $error = 'Gagal menyiapkan query create tiket: ' . $conn->error;
            }
        }
    }
}

if (isset($_POST['bulk_action_submit'])) {
    if ($is_assistant) {
        $error = 'Anda tidak memiliki izin untuk aksi massal tiket.';
    } else {
        $bulk_action = strtoupper(trim((string)($_POST['bulk_action'] ?? '')));
        $bulk_teknisi_user_id = (int)($_POST['bulk_teknisi_user_id'] ?? 0);
        $selected_raw = $_POST['selected_ticket_ids'] ?? [];
        if (!is_array($selected_raw)) {
            $selected_raw = [];
        }

        $selected_ids = [];
        foreach ($selected_raw as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $selected_ids[$sid] = $sid;
            }
        }
        $selected_ids = array_values($selected_ids);

        if (empty($selected_ids)) {
            $error = 'Pilih minimal 1 tiket terlebih dahulu.';
        } elseif (!in_array($bulk_action, ['ASSIGN', 'DELETE'], true)) {
            $error = 'Aksi massal tidak valid.';
        } else {
            $ids_sql = implode(',', array_map('intval', $selected_ids));
            $servers_sql = implode(',', array_map('intval', $allowed_server_ids));

            if ($bulk_action === 'ASSIGN') {
                if ($bulk_teknisi_user_id <= 0) {
                    $error = 'Pilih teknisi tujuan untuk assign massal.';
                } else {
                    $sql_check_teknisi = 'SELECT id FROM user WHERE id = ' . $bulk_teknisi_user_id . ' AND STATUS = "ASSISTANT" AND grup = ' . (int)$owner_user_id . ' LIMIT 1';
                    $res_check_teknisi = mysqli_query($conn, $sql_check_teknisi);
                    if (!$res_check_teknisi || !mysqli_fetch_assoc($res_check_teknisi)) {
                        $error = 'Teknisi tujuan tidak valid.';
                    } else {
                        $sql_assign = 'UPDATE billing_tiket_manager SET teknisi_user_id = ' . $bulk_teknisi_user_id . ' WHERE id IN (' . $ids_sql . ') AND server_id IN (' . $servers_sql . ')';
                        if (mysqli_query($conn, $sql_assign)) {
                            $affected = (int)mysqli_affected_rows($conn);
                            $notice = 'Assign massal berhasil. Tiket terupdate: ' . $affected . '.';
                        } else {
                            $error = 'Assign massal gagal: ' . mysqli_error($conn);
                        }
                    }
                }
            } else {
                $sql_delete = 'DELETE FROM billing_tiket_manager WHERE id IN (' . $ids_sql . ') AND server_id IN (' . $servers_sql . ')';
                if (mysqli_query($conn, $sql_delete)) {
                    $affected = (int)mysqli_affected_rows($conn);
                    $notice = 'Hapus massal berhasil. Tiket terhapus: ' . $affected . '.';
                } else {
                    $error = 'Hapus massal gagal: ' . mysqli_error($conn);
                }
            }
        }
    }
}

if (isset($_POST['update_ticket']) || isset($_POST['process_submit'])) {
    $ticket_id = (int)($_POST['ticket_id'] ?? 0);
    $process_mode = strtoupper(trim((string)($_POST['process_submit'] ?? '')));
    $skip_dismantle_process = isset($_POST['skip_dismantle_process']) && (string)$_POST['skip_dismantle_process'] === '1';
    $new_status = strtoupper(trim((string)($_POST['status'] ?? '')));
    $new_teknisi = (int)($_POST['teknisi_user_id'] ?? 0);
    $new_tipe = strtoupper(trim((string)($_POST['tipe'] ?? 'INSTALLASI')));
    $report_input = trim((string)($_POST['report'] ?? ''));
    $alasan = trim((string)($_POST['alasan'] ?? ''));

    if ($process_mode === 'PROVISIONING' || $process_mode === 'DISMANTLE') {
        $new_status = 'DONE';
    }

    $valid_status = ['BARU', 'PENDING', 'DONE', 'CANCEL'];
    if (!in_array($new_status, $valid_status, true)) {
        $error = 'Status tiket tidak valid.';
    } elseif (!$is_assistant && $new_teknisi <= 0) {
        $error = 'Assign Teknisi wajib dipilih.';
    } elseif ($ticket_id <= 0) {
        $error = 'ID tiket tidak valid.';
    } else {
        $in_ids = implode(',', array_map('intval', $allowed_server_ids));
        $sql_t = 'SELECT * FROM billing_tiket_manager WHERE id = ' . $ticket_id . ' AND server_id IN (' . $in_ids . ') LIMIT 1';
        if ($is_assistant) {
            $sql_t = 'SELECT * FROM billing_tiket_manager WHERE id = ' . $ticket_id . ' AND server_id IN (' . $in_ids . ') AND teknisi_user_id = ' . $session_user_id . ' LIMIT 1';
        }
        $res_t = mysqli_query($conn, $sql_t);
        $row_t = $res_t ? mysqli_fetch_assoc($res_t) : null;

        if (!$row_t) {
            $error = 'Tiket tidak ditemukan atau tidak diizinkan.';
        } else {
            $server_id = (int)$row_t['server_id'];
            $setting = isset($project_settings_map[$server_id]) ? $project_settings_map[$server_id] : [];
            $active_types = isset($setting['tipe_aktif']) && is_array($setting['tipe_aktif']) && !empty($setting['tipe_aktif']) ? $setting['tipe_aktif'] : $default_types;
            if (!in_array($new_tipe, $active_types, true)) {
                $current_type = strtoupper((string)($row_t['tipe'] ?? ''));
                $new_tipe = in_array($current_type, $active_types, true) ? $current_type : $active_types[0];
            }

            $evidence_fields = getEvidenceFieldsForType($setting, $new_tipe);
            $evidence_meta = loadTicketEvidenceMeta($ticket_id);
            if (!is_array($evidence_meta)) {
                $evidence_meta = [];
            }

            $upload_errors = [];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $upload_dir_fs = __DIR__ . '/../../dokumen/tiket/' . $ticket_id . '/';
            $upload_dir_rel = '/dokumen/tiket/' . $ticket_id . '/';

            if (!is_dir($upload_dir_fs)) {
                @mkdir($upload_dir_fs, 0775, true);
            }

            foreach ($evidence_fields as $idx => $field) {
                $field_title = (string)($field['title'] ?? '');
                $is_signature = isSignatureEvidenceTitle($field_title);

                if ($is_signature) {
                    $sig_keys = [
                        'signature_data_' . $ticket_id . '_' . $idx,
                        'signature_data_' . $idx
                    ];
                    $sig_data_url = '';
                    foreach ($sig_keys as $sig_key) {
                        $sig_val = trim((string)($_POST[$sig_key] ?? ''));
                        if ($sig_val !== '') {
                            $sig_data_url = $sig_val;
                            break;
                        }
                    }

                    if ($sig_data_url === '') {
                        $pattern_sig = '/^signature_data_(?:\\d+_)?' . preg_quote((string)$idx, '/') . '$/';
                        foreach ($_POST as $post_key => $post_val) {
                            if (!is_string($post_key) || !preg_match($pattern_sig, $post_key)) {
                                continue;
                            }
                            $sig_val = trim((string)$post_val);
                            if ($sig_val !== '') {
                                $sig_data_url = $sig_val;
                                break;
                            }
                        }
                    }

                    if ($sig_data_url !== '') {
                        if (strpos($sig_data_url, 'data:image/png;base64,') !== 0) {
                            $upload_errors[] = 'Format tanda tangan pelanggan tidak valid.';
                            continue;
                        }

                        $sig_raw = substr($sig_data_url, strlen('data:image/png;base64,'));
                        $sig_raw = str_replace(' ', '+', $sig_raw);
                        $sig_bin = base64_decode($sig_raw, true);
                        if ($sig_bin === false || strlen($sig_bin) < 100) {
                            $upload_errors[] = 'Data tanda tangan pelanggan rusak atau kosong.';
                            continue;
                        }

                        $title_key = normalizeFilename($field_title);
                        $safe_name = $title_key . '_' . date('Ymd_His') . '_' . uniqid('', false) . '.png';
                        $target_fs = $upload_dir_fs . $safe_name;
                        $target_rel = $upload_dir_rel . $safe_name;
                        if (@file_put_contents($target_fs, $sig_bin) === false) {
                            $upload_errors[] = 'Gagal menyimpan tanda tangan pelanggan.';
                            continue;
                        }

                        if (!isset($evidence_meta[$field_title]) || !is_array($evidence_meta[$field_title])) {
                            $evidence_meta[$field_title] = [];
                        }

                        $evidence_meta[$field_title][] = [
                            'path' => $target_rel,
                            'uploaded_at' => date('Y-m-d H:i:s'),
                            'uploaded_by' => $session_user_id
                        ];
                    }

                    continue;
                }

                $candidate_keys = [
                    'evidence_file_' . $ticket_id . '_' . $idx,
                    'evidence_file_' . $idx
                ];
                $file_key = '';
                foreach ($candidate_keys as $candidate_key) {
                    if (isset($_FILES[$candidate_key]) && is_array($_FILES[$candidate_key])) {
                        $file_key = $candidate_key;
                        break;
                    }
                }

                if ($file_key === '') {
                    $pattern_file = '/^evidence_file_(?:\\d+_)?' . preg_quote((string)$idx, '/') . '$/';
                    foreach ($_FILES as $files_key => $files_val) {
                        if (!is_string($files_key) || !preg_match($pattern_file, $files_key)) {
                            continue;
                        }
                        if (is_array($files_val)) {
                            $file_key = $files_key;
                            break;
                        }
                    }
                }

                if ($file_key === '') {
                    continue;
                }

                if ((int)$_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if ((int)$_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
                    $upload_errors[] = 'Upload gagal untuk field ' . $field_title;
                    continue;
                }

                $orig_name = (string)($_FILES[$file_key]['name'] ?? 'evidence.jpg');
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext, true)) {
                    $upload_errors[] = 'Format evidence untuk ' . $field_title . ' harus jpg/jpeg/png/webp.';
                    continue;
                }

                $title_key = normalizeFilename($field_title);
                $safe_name = $title_key . '_' . date('Ymd_His') . '_' . uniqid('', false) . '.' . $ext;
                $target_fs = $upload_dir_fs . $safe_name;
                $target_rel = $upload_dir_rel . $safe_name;

                if (!move_uploaded_file((string)$_FILES[$file_key]['tmp_name'], $target_fs)) {
                    $upload_errors[] = 'Gagal menyimpan evidence untuk ' . $field_title;
                    continue;
                }

                if (!isset($evidence_meta[$field_title]) || !is_array($evidence_meta[$field_title])) {
                    $evidence_meta[$field_title] = [];
                }

                $evidence_meta[$field_title][] = [
                    'path' => $target_rel,
                    'uploaded_at' => date('Y-m-d H:i:s'),
                    'uploaded_by' => $session_user_id
                ];
            }

            if (!empty($upload_errors)) {
                $error = implode(' ', $upload_errors);
            } else {
                if ($new_status === 'DONE') {
                    $missing_required = [];
                    foreach ($evidence_fields as $field) {
                        if (empty($field['required'])) {
                            continue;
                        }
                        if (!ticketEvidenceHasEntriesForTitle($evidence_meta, (string)($field['title'] ?? ''))) {
                            $missing_required[] = $field['title'];
                        }
                    }
                    if (!empty($missing_required)) {
                        $error = 'Evidence wajib belum lengkap: ' . implode(', ', $missing_required);
                    }
                }
            }

            if ($error === '') {
                saveTicketEvidenceMeta($ticket_id, $evidence_meta);

                $template = getReportTemplateForType($setting, $new_tipe);
                $report_final = $report_input;
                if ($report_final === '' && $template !== '') {
                    $report_final = str_replace(
                        ['{nama_project}', '{tanggal}', '{nama}', '{data}', '{tipe}'],
                        [
                            (string)($row_t['project_name'] ?: $row_t['brand']),
                            date('Y-m-d'),
                            (string)($_SESSION['PEMILIK'] ?? ''),
                            (string)$row_t['detail'],
                            (string)$new_tipe
                        ],
                        $template
                    );
                }

                if ($alasan !== '') {
                    $report_final = trim($report_final . "\n\nCatatan Update:\n" . $alasan);
                }

                $process_note = '';
                if ($new_status === 'DONE') {
                    $effective_teknisi_id = $is_assistant ? $session_user_id : ($new_teknisi > 0 ? $new_teknisi : (int)($row_t['teknisi_user_id'] ?? 0));
                    $teknisi_name_for_process = tmGetTeknisiName($conn, $effective_teknisi_id);
                    $shouldProvision = ($new_tipe === 'INSTALLASI' && !empty($setting['provisioning_enabled']));

                    if ($shouldProvision) {
                        if (!tmQueueProvisioningFromTicket($conn, $row_t, $report_final, (string)($row_t['detail'] ?? ''), $teknisi_name_for_process, $process_note)) {
                            $error = $process_note;
                        }
                    } elseif ($new_tipe === 'DISMANTLE') {
                        if ($skip_dismantle_process) {
                            $process_note = 'Dismantle sudah diproses dari modal aksi.';
                        } elseif (!empty($setting['hapus_billing_dismantle'])) {
                            if (!tmProcessDismantleFromTicket($conn, $row_t, $report_final, (string)($row_t['detail'] ?? ''), $process_note)) {
                                $error = $process_note;
                            }
                        } else {
                            $process_note = 'Mode hapus pelanggan saat DISMANTLE belum aktif di Project Settings Billing.';
                        }
                    }
                }

                $done_at_value = $new_status === 'DONE' ? date('Y-m-d H:i:s') : null;

                if ($is_assistant) {
                    $stmt_upd = $conn->prepare('UPDATE billing_tiket_manager SET status = ?, tipe = ?, report = ?, done_at = ? WHERE id = ?');
                    if ($stmt_upd) {
                        $stmt_upd->bind_param('ssssi', $new_status, $new_tipe, $report_final, $done_at_value, $ticket_id);
                    }
                } else {
                    $stmt_upd = $conn->prepare('UPDATE billing_tiket_manager SET status = ?, teknisi_user_id = ?, tipe = ?, report = ?, done_at = ? WHERE id = ?');
                    if ($stmt_upd) {
                        $stmt_upd->bind_param('sisssi', $new_status, $new_teknisi, $new_tipe, $report_final, $done_at_value, $ticket_id);
                    }
                }

                if (!$stmt_upd) {
                    $error = 'Gagal menyiapkan query update tiket.';
                } elseif ($stmt_upd->execute()) {
                    $notice = 'Tiket berhasil diperbarui.';
                    if ($process_note !== '') {
                        $notice .= ' ' . $process_note;
                    }
                } else {
                    $error = 'Gagal update tiket: ' . $stmt_upd->error;
                }

                if ($stmt_upd) {
                    $stmt_upd->close();
                }
            }
        }
    }
}

$teknisi_list = [];
if (!$is_assistant) {
    $sql_teknisi = 'SELECT id, USERNAME, NOWA FROM user WHERE STATUS = "ASSISTANT" AND grup = ' . (int)$owner_user_id . ' ORDER BY USERNAME ASC';
    $res_teknisi = mysqli_query($conn, $sql_teknisi);
    if ($res_teknisi) {
        while ($t = mysqli_fetch_assoc($res_teknisi)) {
            $teknisi_list[(int)$t['id']] = $t;
        }
    }
} else {
    $sql_me = 'SELECT id, USERNAME, NOWA FROM user WHERE id = ' . $session_user_id . ' LIMIT 1';
    $res_me = mysqli_query($conn, $sql_me);
    if ($res_me && ($me = mysqli_fetch_assoc($res_me))) {
        $teknisi_list[(int)$me['id']] = $me;
    }
}

$filter_status = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : 'ALL';
$valid_filter = ['ALL', 'BARU', 'PENDING', 'DONE', 'CANCEL'];
if (!in_array($filter_status, $valid_filter, true)) {
    $filter_status = 'ALL';
}

$filter_q = trim((string)($_GET['q'] ?? ''));
$filter_server_id = (int)($_GET['server_id'] ?? 0);
$filter_brand = trim((string)($_GET['brand'] ?? ''));
$filter_area = trim((string)($_GET['area'] ?? ''));
$filter_teknisi = (int)($_GET['teknisi'] ?? 0);
$filter_tipe = strtoupper(trim((string)($_GET['tipe'] ?? '')));
$filter_date_from = trim((string)($_GET['date_from'] ?? ''));
$filter_date_to = trim((string)($_GET['date_to'] ?? ''));

if ($filter_server_id > 0 && !isset($allowed_servers[$filter_server_id])) {
    $filter_server_id = 0;
}
if ($filter_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
    $filter_date_from = '';
}
if ($filter_date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
    $filter_date_to = '';
}
if ($filter_tipe !== '' && !in_array($filter_tipe, $default_types, true)) {
    $filter_tipe = '';
}

$brand_options = [];
$area_options = [];
foreach ($allowed_servers as $srv) {
    $b = trim((string)($srv['BRAND'] ?? ''));
    $a = trim((string)($srv['AREA'] ?? ''));
    if ($b !== '') {
        $brand_options[$b] = $b;
    }
    if ($a !== '') {
        $area_options[$a] = $a;
    }
}
ksort($brand_options);
ksort($area_options);

$where = [];
$where[] = 't.server_id IN (' . implode(',', array_map('intval', $allowed_server_ids)) . ')';
if ($is_assistant) {
    $where[] = 't.teknisi_user_id = ' . $session_user_id;
}
if ($filter_status !== 'ALL') {
    $where[] = 't.status = "' . mysqli_real_escape_string($conn, $filter_status) . '"';
}
if ($filter_server_id > 0) {
    $where[] = 't.server_id = ' . (int)$filter_server_id;
}
if ($filter_brand !== '') {
    $where[] = 't.brand = "' . mysqli_real_escape_string($conn, $filter_brand) . '"';
}
if ($filter_area !== '') {
    $where[] = 't.area = "' . mysqli_real_escape_string($conn, $filter_area) . '"';
}
if (!$is_assistant && $filter_teknisi > 0) {
    $where[] = 't.teknisi_user_id = ' . (int)$filter_teknisi;
}
if ($filter_tipe !== '') {
    $where[] = 'UPPER(t.tipe) = "' . mysqli_real_escape_string($conn, $filter_tipe) . '"';
}
if ($filter_q !== '') {
    $q_esc = mysqli_real_escape_string($conn, $filter_q);
    $where[] = '(t.judul LIKE "%' . $q_esc . '%" OR t.detail LIKE "%' . $q_esc . '%" OR t.project_name LIKE "%' . $q_esc . '%")';
}
if ($filter_date_from !== '') {
    $where[] = 'DATE(t.created_at) >= "' . mysqli_real_escape_string($conn, $filter_date_from) . '"';
}
if ($filter_date_to !== '') {
    $where[] = 'DATE(t.created_at) <= "' . mysqli_real_escape_string($conn, $filter_date_to) . '"';
}
$where_sql = implode(' AND ', $where);

// Lazy-load: 20 tiket per fetch (self-GET ke halaman ini sendiri dengan filter
// yang sama + parameter tpage) supaya daftar tiket yang besar tidak dirender
// sekaligus. Filter di sini murni WHERE SQL (bukan status hasil hitungan
// seperti pelanggan_menunggak.php), jadi LIMIT/OFFSET aman dipakai langsung.
$ticketPageSize = 20;
$ticketPage = isset($_GET['tpage']) ? (int)$_GET['tpage'] : 1;
if ($ticketPage < 1) {
    $ticketPage = 1;
}
$res_ticket_count = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM billing_tiket_manager t WHERE ' . $where_sql);
$ticket_count_row = $res_ticket_count ? mysqli_fetch_assoc($res_ticket_count) : ['total' => 0];
$ticketTotalRows = (int)($ticket_count_row['total'] ?? 0);
$ticketTotalPages = max(1, (int)ceil($ticketTotalRows / $ticketPageSize));
$ticketPage = min($ticketPage, $ticketTotalPages);
$ticketOffset = ($ticketPage - 1) * $ticketPageSize;

$tickets = [];
$sql_ticket = 'SELECT t.*, s.PEMILIK AS s_pemilik, s.BRAND AS s_brand, s.AREA AS s_area, u.USERNAME AS teknisi_nama
               FROM billing_tiket_manager t
               LEFT JOIN server s ON t.server_id = s.id
               LEFT JOIN user u ON t.teknisi_user_id = u.id
               WHERE ' . $where_sql . '
               ORDER BY t.created_at DESC
               LIMIT ' . (int)$ticketPageSize . ' OFFSET ' . (int)$ticketOffset;
$res_ticket = mysqli_query($conn, $sql_ticket);
if ($res_ticket) {
    while ($row = mysqli_fetch_assoc($res_ticket)) {
        $row['evidence_meta'] = loadTicketEvidenceMeta((int)$row['id']);
        $tickets[] = $row;
    }
}

$settings_js_map = [];
foreach ($project_settings_map as $sid => $setting) {
    $settings_js_map[(string)$sid] = [
        'project_name' => (string)($setting['project_name'] ?? ''),
        'provisioning_enabled' => !empty($setting['provisioning_enabled']) ? 1 : 0,
        'customer_signature_enabled' => !empty($setting['customer_signature_enabled']) ? 1 : 0,
        'hapus_billing_dismantle' => !empty($setting['hapus_billing_dismantle']) ? 1 : 0,
        'tipe_aktif' => isset($setting['tipe_aktif']) && is_array($setting['tipe_aktif']) ? array_values($setting['tipe_aktif']) : ['INSTALLASI'],
        'evidence_per_tipe' => isset($setting['evidence_per_tipe']) ? $setting['evidence_per_tipe'] : new stdClass(),
        'evidence_required_per_tipe' => isset($setting['evidence_required_per_tipe']) ? $setting['evidence_required_per_tipe'] : new stdClass(),
        'report_format_per_tipe' => isset($setting['report_format_per_tipe']) ? $setting['report_format_per_tipe'] : new stdClass()
    ];
}

$default_report_templates = [];
$default_evidence_templates = [];
foreach ($default_types as $tp) {
    $default_report_templates[$tp] = getReportTemplateForType([], $tp);
    $evFields = getEvidenceFieldsForType([], $tp);
    $titles = [];
    $required = [];
    foreach ($evFields as $f) {
        $titles[] = (string)($f['title'] ?? '');
        $required[] = !empty($f['required']) ? 1 : 0;
    }
    $default_evidence_templates[$tp] = [
        'titles' => $titles,
        'required' => $required
    ];
}
?>

<style>
.tm-ui {
    --tm-primary: var(--bs-primary, #f68b1f);
    --tm-primary-dark: #d66f05;
    --tm-text-strong: #1e293b;
    --tm-border: #d9dee8;
}

.tm-ui .card {
    background: #ffffff;
    border: 1px solid #c8d2df;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
}

.tm-ui .card-header {
    background: #f8fafc;
    border-bottom: 1px solid #dbe4ef;
}

.tm-ui .card-header h6,
.tm-ui .card-header .mb-0,
.tm-ui .card-header .form-label {
    color: #0f172a !important;
    opacity: 1 !important;
    font-weight: 700;
}

.tm-ui .card-body,
.tm-ui .card-body p,
.tm-ui .card-body small,
.tm-ui .card-body span,
.tm-ui .card-body div,
.tm-ui .card-body label {
    color: #1e293b;
}

.tm-ui .text-muted {
    color: #475569 !important;
}

.tm-ui .modal-header.bg-primary,
.tm-ui .modal-header.bg-info {
    background: linear-gradient(135deg, var(--tm-primary) 0%, var(--tm-primary-dark) 100%) !important;
}

.tm-ui .modal-header .btn-close,
.tm-process-modal .modal-header .btn-close,
.project-settings-box .modal-header .btn-close {
    opacity: 1 !important;
    filter: brightness(0) invert(1) !important;
    background-color: rgba(15, 23, 42, 0.35);
    border: 1px solid rgba(255, 255, 255, 0.75);
    border-radius: 999px;
    padding: 0.55rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

.tm-ui .modal-header .btn-close:hover,
.tm-ui .modal-header .btn-close:focus,
.tm-process-modal .modal-header .btn-close:hover,
.tm-process-modal .modal-header .btn-close:focus,
.project-settings-box .modal-header .btn-close:hover,
.project-settings-box .modal-header .btn-close:focus {
    opacity: 1 !important;
    background-color: rgba(15, 23, 42, 0.5);
    border-color: #ffffff;
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.35);
}

.tm-ui .btn {
    border-radius: 10px;
    font-weight: 700;
    letter-spacing: 0.2px;
}

.tm-ui .btn-primary,
.tm-ui .btn-success {
    color: #fff !important;
}

.tm-ui .btn-primary {
    background: linear-gradient(135deg, var(--tm-primary) 0%, var(--tm-primary-dark) 100%) !important;
    border-color: var(--tm-primary-dark) !important;
}

.tm-ui .btn-primary:hover,
.tm-ui .btn-primary:focus {
    background: linear-gradient(135deg, var(--tm-primary-dark) 0%, #b95d03 100%) !important;
    border-color: #b95d03 !important;
}

.tm-ui .btn-outline-secondary,
.tm-ui .btn-outline-primary,
.tm-ui .btn-outline-warning,
.tm-ui .btn-outline-success,
.tm-ui .btn-outline-danger {
    background: #fff !important;
    border-width: 2px;
}

.tiket-toolbar .btn {
    margin-bottom: 0.35rem;
    min-height: 38px;
    padding: 0.45rem 0.9rem;
}

.tiket-toolbar .toolbar-status {
    color: #fff !important;
    text-shadow: 0 1px 1px rgba(0, 0, 0, 0.15);
}

.tiket-toolbar .toolbar-all,
.tiket-toolbar .toolbar-all.active,
.tiket-toolbar .toolbar-all:hover,
.tiket-toolbar .toolbar-all:focus {
    background: #334155 !important;
    border-color: #334155 !important;
}

.tiket-toolbar .toolbar-baru,
.tiket-toolbar .toolbar-baru.active,
.tiket-toolbar .toolbar-baru:hover,
.tiket-toolbar .toolbar-baru:focus {
    background: #1d4ed8 !important;
    border-color: #1d4ed8 !important;
}

.tiket-toolbar .toolbar-pending,
.tiket-toolbar .toolbar-pending.active,
.tiket-toolbar .toolbar-pending:hover,
.tiket-toolbar .toolbar-pending:focus {
    background: #b45309 !important;
    border-color: #b45309 !important;
}

.tiket-toolbar .toolbar-done,
.tiket-toolbar .toolbar-done.active,
.tiket-toolbar .toolbar-done:hover,
.tiket-toolbar .toolbar-done:focus {
    background: #15803d !important;
    border-color: #15803d !important;
}

.tiket-toolbar .toolbar-cancel,
.tiket-toolbar .toolbar-cancel.active,
.tiket-toolbar .toolbar-cancel:hover,
.tiket-toolbar .toolbar-cancel:focus {
    background: #b91c1c !important;
    border-color: #b91c1c !important;
}

.tm-ui .btn:focus-visible {
    outline: 2px solid #0f172a;
    outline-offset: 1px;
}

.ticket-actions {
    min-width: 170px;
    border-width: 2px;
}

.tm-ui .table td,
.tm-ui .table th {
    padding: 0.46rem 0.58rem;
    vertical-align: top;
}

.tm-ui .ticket-row-title {
    line-height: 1.2;
}

.tm-ui .ticket-row-detail {
    line-height: 1.2;
    max-width: 460px;
}

.tm-ui .bulk-action-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e5e9f2;
    padding: 0.58rem;
}

.tm-ui .bulk-action-bar .form-select,
.tm-ui .bulk-action-bar .btn {
    min-height: 34px;
}

.tm-ui .form-label {
    color: var(--tm-text-strong);
    font-weight: 600;
}

.tm-ui .form-control,
.tm-ui .form-select {
    border-color: #cfd7e3;
}

.tm-ui .form-check-input {
    width: 1.15rem;
    height: 1.15rem;
    margin-top: 0.15rem;
    border: 2px solid #334155;
    background-color: #ffffff;
    box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.06);
}

.tm-ui .form-check-input:hover {
    border-color: #0f172a;
}

.tm-ui .form-check-input:checked {
    background-color: #f68b1f;
    border-color: #c46a08;
}

.tm-ui .form-check-input:indeterminate {
    background-color: #f68b1f;
    border-color: #c46a08;
}

.tm-ui .form-check-input:focus {
    border-color: #c46a08;
    box-shadow: 0 0 0 0.2rem rgba(246, 139, 31, 0.3);
}

.tm-ui .form-control:focus,
.tm-ui .form-select:focus {
    border-color: var(--tm-primary);
    box-shadow: 0 0 0 0.18rem rgba(246, 139, 31, 0.2);
}

.tm-ui .table thead th {
    background: #f8fafc;
    border-bottom: 1px solid #e5e9f2;
}

.tm-ui .js-fill-template,
.tm-ui .js-fill-template-done {
    border-width: 2px;
    color: #fff !important;
}

.tm-ui .js-fill-template,
.tm-ui .js-fill-template:hover,
.tm-ui .js-fill-template:focus {
    background: #2563eb !important;
    border-color: #2563eb !important;
}

.tm-ui .js-fill-template-done,
.tm-ui .js-fill-template-done:hover,
.tm-ui .js-fill-template-done:focus {
    background: #15803d !important;
    border-color: #15803d !important;
}

.ticket-evidence-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(115px, 1fr)); gap: 12px; }
.ticket-evidence-gallery img { width: 100%; height: 95px; object-fit: cover; border-radius: 8px; border: 1px solid #e3e3e3; }
.tiket-badge-sla { font-size: 0.72rem; }
.project-settings-box textarea { min-height: 98px; }
.project-settings-box .modal-body { max-height: calc(100vh - 210px); overflow-y: auto; }
.project-settings-box .modal-footer { position: sticky; bottom: 0; background: #fff; z-index: 2; }
.project-settings-box .card { border: 1px solid #dfe3e8; }
.tm-ui .js-default-submit-row,
.tm-ui .js-default-submit-row.tm-hidden { display: none !important; }

.tm-process-modal {
    z-index: 1065;
}

.tm-process-modal + .modal-backdrop {
    z-index: 1064;
}

.tm-process-modal .modal-dialog {
    margin: 0;
    width: 100vw;
    max-width: 100vw;
    height: 100vh;
}

.tm-process-modal .modal-content {
    height: 100vh;
    border: 0;
    border-radius: 0;
}

.tm-process-modal .modal-header {
    border-radius: 0;
}

.tm-process-modal .modal-body {
    height: calc(100vh - 64px);
    overflow: hidden;
}

.tm-process-frame {
    width: 100%;
    height: 100%;
    border: 0;
}
@media (max-width: 767px) {
    .ticket-actions { min-width: auto; }
    .tiket-toolbar { display: grid; grid-template-columns: repeat(2, minmax(120px, 1fr)); gap: 0.45rem; }
    .tiket-toolbar .btn { width: 100%; margin-bottom: 0; }
    .tm-ui .bulk-action-bar { padding: 0.5rem; }
}
</style>

<div class="container-fluid py-4 tm-ui">
    <?php if ($notice !== ''): ?>
        <div class="alert alert-success"><?php echo h($notice); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0"><i class="fas fa-tasks me-2"></i>Tiket Manager Billing</h6>
            <div class="d-flex flex-wrap gap-2 tiket-toolbar">
                <?php if (!$is_assistant): ?>
                <button type="button" class="btn btn-sm btn-primary tm-btn-header" data-bs-toggle="modal" data-bs-target="#projectSettingsModal">
                    <i class="fas fa-cogs me-1"></i>Project Settings Billing
                </button>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-secondary toolbar-status toolbar-all <?php echo $filter_status === 'ALL' ? 'active' : ''; ?>" href="<?php echo h(buildFilterUrl(['status' => 'ALL'])); ?>">ALL</a>
                <a class="btn btn-sm btn-outline-primary toolbar-status toolbar-baru <?php echo $filter_status === 'BARU' ? 'active' : ''; ?>" href="<?php echo h(buildFilterUrl(['status' => 'BARU'])); ?>">BARU</a>
                <a class="btn btn-sm btn-outline-warning toolbar-status toolbar-pending <?php echo $filter_status === 'PENDING' ? 'active' : ''; ?>" href="<?php echo h(buildFilterUrl(['status' => 'PENDING'])); ?>">PENDING</a>
                <a class="btn btn-sm btn-outline-success toolbar-status toolbar-done <?php echo $filter_status === 'DONE' ? 'active' : ''; ?>" href="<?php echo h(buildFilterUrl(['status' => 'DONE'])); ?>">DONE</a>
                <a class="btn btn-sm btn-outline-danger toolbar-status toolbar-cancel <?php echo $filter_status === 'CANCEL' ? 'active' : ''; ?>" href="<?php echo h(buildFilterUrl(['status' => 'CANCEL'])); ?>">CANCEL</a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1">Cari</label>
                    <input type="text" class="form-control form-control-sm" name="q" placeholder="Judul / detail / project" value="<?php echo h($filter_q); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Project Server</label>
                    <select class="form-select form-select-sm" name="server_id">
                        <option value="0">Semua</option>
                        <?php foreach ($allowed_servers as $sid => $srv): ?>
                            <option value="<?php echo (int)$sid; ?>" <?php echo $filter_server_id === (int)$sid ? 'selected' : ''; ?>>
                                <?php echo h((string)$srv['BRAND']); ?> | <?php echo h((string)$srv['AREA']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Brand</label>
                    <select class="form-select form-select-sm" name="brand">
                        <option value="">Semua Brand</option>
                        <?php foreach ($brand_options as $b): ?>
                            <option value="<?php echo h($b); ?>" <?php echo $filter_brand === $b ? 'selected' : ''; ?>><?php echo h($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Area</label>
                    <select class="form-select form-select-sm" name="area">
                        <option value="">Semua Area</option>
                        <?php foreach ($area_options as $a): ?>
                            <option value="<?php echo h($a); ?>" <?php echo $filter_area === $a ? 'selected' : ''; ?>><?php echo h($a); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Tipe</label>
                    <select class="form-select form-select-sm" name="tipe">
                        <option value="">Semua Tipe</option>
                        <?php foreach ($default_types as $tp): ?>
                            <option value="<?php echo h($tp); ?>" <?php echo $filter_tipe === $tp ? 'selected' : ''; ?>><?php echo h($tp); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$is_assistant): ?>
                <div class="col-md-2">
                    <label class="form-label mb-1">Teknisi</label>
                    <select class="form-select form-select-sm" name="teknisi">
                        <option value="0">Semua Teknisi</option>
                        <?php foreach ($teknisi_list as $tid => $teknisi): ?>
                            <option value="<?php echo (int)$tid; ?>" <?php echo $filter_teknisi === (int)$tid ? 'selected' : ''; ?>><?php echo h($teknisi['USERNAME']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label class="form-label mb-1">Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="date_from" value="<?php echo h($filter_date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm" name="date_to" value="<?php echo h($filter_date_to); ?>">
                </div>
                <div class="col-md-2">
                    <input type="hidden" name="status" value="<?php echo h($filter_status); ?>">
                    <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                </div>
                <div class="col-md-1">
                    <a href="tiket_manager.php" class="btn btn-sm btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-body px-0 pt-0 pb-2">
                    <?php if (!$is_assistant): ?>
                    <form method="POST" id="bulkTicketActionForm">
                        <div class="bulk-action-bar">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label mb-1">Aksi Terpilih</label>
                                    <select class="form-select form-select-sm" name="bulk_action" id="bulkActionSelect" required>
                                        <option value="">Pilih Aksi</option>
                                        <option value="ASSIGN">Assign Teknisi</option>
                                        <option value="DELETE">Hapus Tiket</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label mb-1">Teknisi Tujuan</label>
                                    <select class="form-select form-select-sm" name="bulk_teknisi_user_id" id="bulkTeknisiSelect" disabled>
                                        <option value="0" selected>Pilih Teknisi</option>
                                        <?php foreach ($teknisi_list as $tid => $teknisi): ?>
                                            <option value="<?php echo (int)$tid; ?>"><?php echo h((string)$teknisi['USERNAME']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-sm btn-primary w-100" name="bulk_action_submit" id="bulkActionSubmitBtn" disabled>
                                        Proses Terpilih
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <small class="text-muted d-block">Pilih tiket dengan checkbox, lalu jalankan aksi massal.</small>
                                </div>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <?php if (!$is_assistant): ?>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3" style="width: 36px;">
                                        <input type="checkbox" class="form-check-input" id="bulkSelectAllTickets" title="Pilih semua">
                                    </th>
                                    <?php endif; ?>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Tiket</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Project/Tipe</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Teknisi</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="ticketTableBody">
                            <?php
                            // Lazy-load: baris + modal per tiket ditampung dulu (ob_start), bukan
                            // langsung di-echo, supaya modal (yang secara markup ada di dalam
                            // <tbody>) tidak ikut ke-foster-parenting keluar tbody saat browser
                            // mem-parsing response fetch dan malah hilang saat lazy-load meng-append
                            // hanya innerHTML tbody. Ditampung ke $ticketRowsHtml lalu di-echo di luar
                            // <table> lewat #ticketModalsContainer - sama seperti packages.php.
                            $ticketRowsHtml = [];
                            ?>
                            <?php if (count($tickets) > 0): ?>
                                <?php foreach ($tickets as $t): ?>
                                    <?php
                                    $badge = 'secondary';
                                    if ($t['status'] === 'BARU') {
                                        $badge = 'primary';
                                    }
                                    if ($t['status'] === 'PENDING') {
                                        $badge = 'warning text-dark';
                                    }
                                    if ($t['status'] === 'DONE') {
                                        $badge = 'success';
                                    }
                                    if ($t['status'] === 'CANCEL') {
                                        $badge = 'danger';
                                    }

                                    $cfg = isset($project_settings_map[(int)$t['server_id']]) ? $project_settings_map[(int)$t['server_id']] : [];
                                    $active_types = isset($cfg['tipe_aktif']) && is_array($cfg['tipe_aktif']) && !empty($cfg['tipe_aktif']) ? $cfg['tipe_aktif'] : $default_types;
                                    $cfg_by_type = [];
                                    foreach ($active_types as $tp) {
                                        $cfg_by_type[$tp] = getEvidenceFieldsForType($cfg, $tp);
                                    }
                                    $cfg_json = h(json_encode($cfg_by_type, JSON_UNESCAPED_UNICODE));
                                    $meta = isset($t['evidence_meta']) && is_array($t['evidence_meta']) ? $t['evidence_meta'] : [];
                                    $meta_json = h(json_encode($meta, JSON_UNESCAPED_UNICODE));
                                    $is_done_ticket = strtoupper((string)($t['status'] ?? '')) === 'DONE';
                                    $has_evidence_files = !empty($meta);
                                    ?>
                                    <tr>
                                        <?php if (!$is_assistant): ?>
                                        <td class="ps-3">
                                            <input type="checkbox" class="form-check-input js-bulk-ticket-item" value="<?php echo (int)$t['id']; ?>" data-ticket-id="<?php echo (int)$t['id']; ?>" title="Pilih tiket #<?php echo (int)$t['id']; ?>">
                                        </td>
                                        <?php endif; ?>
                                        <td class="ps-3">
                                            <div class="text-sm font-weight-bold mb-1 ticket-row-title">#<?php echo (int)$t['id']; ?> - <?php echo h($t['judul']); ?></div>
                                            <div class="text-xs text-muted ticket-row-detail"><?php echo nl2br(h((string)$t['detail'])); ?></div>
                                            <span class="badge bg-<?php echo $badge; ?> mt-1"><?php echo h($t['status']); ?></span>
                                        </td>
                                        <td>
                                            <div class="text-xs fw-bold"><?php echo h($t['project_name'] !== '' ? $t['project_name'] : ($t['s_brand'] ?: $t['brand'])); ?></div>
                                            <div class="text-xs text-muted">Tipe: <?php echo h((string)$t['tipe']); ?></div>
                                            <div class="text-xxs text-muted"><?php echo h((string)($t['s_brand'] ?: $t['brand'])); ?> | <?php echo h((string)($t['s_area'] ?: $t['area'])); ?></div>
                                            <div class="text-xxs text-muted"><?php echo h((string)($t['s_pemilik'] ?: $t['pemilik'])); ?></div>
                                        </td>
                                        <td>
                                            <span class="text-xs"><?php echo h((string)($t['teknisi_nama'] ?: '-')); ?></span>
                                        </td>
                                        <td>
                                            <div class="text-xxs text-muted">Create: <?php echo h((string)$t['created_at']); ?></div>
                                            <div class="text-xxs text-muted">Update: <?php echo h((string)$t['updated_at']); ?></div>
                                            <?php if (!empty($t['done_at'])): ?>
                                            <div class="text-xxs text-success">Done: <?php echo h((string)$t['done_at']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-success ticket-actions" data-bs-toggle="modal" data-bs-target="#ticketModal<?php echo (int)$t['id']; ?>">
                                                <i class="fas fa-cog me-1"></i>Action / Evidence
                                            </button>
                                        </td>
                                    </tr>

                                    <?php ob_start(); ?>
                                    <div class="modal fade" id="ticketModal<?php echo (int)$t['id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                            <div class="modal-content">
                                                <div class="modal-header bg-primary text-white">
                                                    <h6 class="modal-title mb-0">Tiket #<?php echo (int)$t['id']; ?> - <?php echo h($t['judul']); ?></h6>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row g-3">
                                                        <div class="<?php echo $is_done_ticket ? 'col-lg-6' : 'col-lg-12'; ?>">
                                                            <div class="card h-100">
                                                                <div class="card-header pb-0"><h6 class="mb-0">Detail Tiket</h6></div>
                                                                <div class="card-body">
                                                                    <p class="mb-2"><strong>Project:</strong> <?php echo h($t['project_name'] !== '' ? $t['project_name'] : ($t['s_brand'] ?: $t['brand'])); ?></p>
                                                                    <p class="mb-2"><strong>Tipe:</strong> <?php echo h((string)$t['tipe']); ?></p>
                                                                    <p class="mb-2"><strong>Status:</strong> <span class="badge bg-<?php echo $badge; ?>"><?php echo h((string)$t['status']); ?></span></p>
                                                                    <p class="mb-2"><strong>Teknisi:</strong> <?php echo h((string)($t['teknisi_nama'] ?: '-')); ?></p>
                                                                    <p class="mb-2"><strong>Data:</strong><br><?php echo nl2br(h((string)$t['detail'])); ?></p>
                                                                    <?php if (!empty($t['report'])): ?>
                                                                        <hr>
                                                                        <p class="mb-1"><strong>Report:</strong></p>
                                                                        <pre class="small bg-light p-2 rounded"><?php echo h((string)$t['report']); ?></pre>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php if ($is_done_ticket || $has_evidence_files): ?>
                                                        <div class="col-lg-6">
                                                            <div class="card h-100">
                                                                <div class="card-header pb-0"><h6 class="mb-0">Evidence</h6></div>
                                                                <div class="card-body">
                                                                    <?php if (!empty($meta)): ?>
                                                                        <?php foreach ($meta as $title => $entries): ?>
                                                                            <div class="mb-2"><strong><?php echo h((string)$title); ?></strong> (<?php echo (int)count((array)$entries); ?> file)</div>
                                                                            <div class="ticket-evidence-gallery mb-3">
                                                                                <?php foreach ((array)$entries as $entry): ?>
                                                                                    <?php $img_rel = (string)($entry['path'] ?? ''); ?>
                                                                                    <?php if ($img_rel !== ''): ?>
                                                                                        <a href="<?php echo h($img_rel); ?>" target="_blank">
                                                                                            <img src="<?php echo h($img_rel); ?>" alt="evidence">
                                                                                        </a>
                                                                                    <?php endif; ?>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    <?php else: ?>
                                                                        <p class="text-muted mb-0">Belum ada evidence.</p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <hr>
                                                    <form method="POST" action="tiket_manager_action.php" enctype="multipart/form-data" class="mt-2" id="ticketUpdateForm<?php echo (int)$t['id']; ?>">
                                                        <input type="hidden" name="ticket_id" value="<?php echo (int)$t['id']; ?>">
                                                        <div class="row g-2">
                                                            <div class="col-md-3">
                                                                <label class="form-label">Status</label>
                                                                <select class="form-select form-select-sm" name="status" required>
                                                                    <?php $status_update_options = ['PENDING', 'DONE', 'CANCEL']; ?>
                                                                    <?php foreach ($status_update_options as $st): ?>
                                                                        <option value="<?php echo h($st); ?>" <?php echo (strtoupper((string)$t['status']) === $st || (strtoupper((string)$t['status']) === 'BARU' && $st === 'PENDING')) ? 'selected' : ''; ?>><?php echo h($st); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <label class="form-label">Tipe</label>
                                                                <select class="form-select form-select-sm ticket-tipe-select"
                                                                        name="tipe"
                                                                        data-target="evidenceFields<?php echo (int)$t['id']; ?>"
                                                                        data-process-target="processFields<?php echo (int)$t['id']; ?>"
                                                                    data-ticket-id="<?php echo (int)$t['id']; ?>"
                                                                    data-server-id="<?php echo (int)$t['server_id']; ?>"
                                                                    data-provisioning-enabled="<?php echo !empty($cfg['provisioning_enabled']) ? '1' : '0'; ?>"
                                                                    data-dismantle-enabled="<?php echo !empty($cfg['hapus_billing_dismantle']) ? '1' : '0'; ?>"
                                                                        data-project-name="<?php echo h((string)($t['project_name'] !== '' ? $t['project_name'] : ($t['s_brand'] ?: $t['brand']))); ?>"
                                                                        data-evidence-meta="<?php echo $meta_json; ?>"
                                                                        data-config="<?php echo $cfg_json; ?>">
                                                                    <?php foreach ($active_types as $tp): ?>
                                                                        <option value="<?php echo h($tp); ?>" <?php echo strtoupper((string)$t['tipe']) === strtoupper((string)$tp) ? 'selected' : ''; ?>><?php echo h($tp); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <?php if (!$is_assistant): ?>
                                                            <div class="col-md-3">
                                                                <label class="form-label">Assign Teknisi</label>
                                                                <select class="form-select form-select-sm" name="teknisi_user_id" required>
                                                                    <option value="0" <?php echo (int)$t['teknisi_user_id'] <= 0 ? 'selected' : ''; ?> disabled>Pilih Teknisi</option>
                                                                    <?php foreach ($teknisi_list as $tid => $teknisi): ?>
                                                                        <option value="<?php echo (int)$tid; ?>" <?php echo (int)$t['teknisi_user_id'] === (int)$tid ? 'selected' : ''; ?>><?php echo h((string)$teknisi['USERNAME']); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <?php endif; ?>
                                                            <input type="hidden" class="ticket-detail-raw" value="<?php echo h((string)$t['detail']); ?>">
                                                            <div class="col-md-12" id="evidenceFields<?php echo (int)$t['id']; ?>"></div>
                                                            <div class="col-md-12">
                                                                <label class="form-label">Report</label>
                                                                <textarea class="form-control ticket-report-input" name="report" rows="7"><?php echo h((string)$t['report']); ?></textarea>
                                                            </div>
                                                            <div class="col-md-12">
                                                                <label class="form-label">Catatan Update</label>
                                                                <input type="text" class="form-control" name="alasan" placeholder="Opsional">
                                                            </div>
                                                            <div class="col-md-12" id="processFields<?php echo (int)$t['id']; ?>"></div>
                                                            <div class="col-md-12 d-flex justify-content-end js-default-submit-row" hidden>
                                                                <button type="submit" class="btn btn-primary" name="update_ticket">
                                                                    <i class="fas fa-save me-1"></i>Update Tiket
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php $ticketRowsHtml[] = ob_get_clean(); ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="<?php echo !$is_assistant ? '6' : '5'; ?>" class="text-center text-muted py-4">Belum ada tiket</td></tr>
                            <?php endif; ?>
                            <tr id="ticketLazySentinel" style="height:1px;">
                                <td colspan="6" style="padding:0;border:0;"></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="ticketLazyLoadWrap" class="text-center py-3 <?php echo $ticketPage >= $ticketTotalPages ? 'd-none' : ''; ?>">
                        <div id="ticketLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <span id="ticketLazyLoadStatusText" class="text-secondary text-xs"></span>
                    </div>
                    <div id="ticketModalsContainer"><?php echo implode('', $ticketRowsHtml); ?></div>
                    <div id="ticketLazyMeta" class="d-none" data-page="<?php echo (int)$ticketPage; ?>" data-total-pages="<?php echo (int)$ticketTotalPages; ?>"></div>
                    <script>
                    (function() {
                        var currentPage = <?php echo (int)$ticketPage; ?>;
                        var totalPages  = <?php echo (int)$ticketTotalPages; ?>;
                        var isLoading   = false;

                        var tableBody       = document.getElementById('ticketTableBody');
                        var modalsContainer = document.getElementById('ticketModalsContainer');
                        var sentinelRow      = document.getElementById('ticketLazySentinel');
                        var lazyWrap        = document.getElementById('ticketLazyLoadWrap');
                        var lazyIndicator   = document.getElementById('ticketLazyLoadIndicator');
                        var lazyStatusText  = document.getElementById('ticketLazyLoadStatusText');

                        if (!tableBody || !sentinelRow) return;

                        function updateStatusText() {
                            if (!lazyStatusText) return;
                            lazyStatusText.textContent = (currentPage >= totalPages)
                                ? 'Semua tiket sudah dimuat.'
                                : '';
                        }

                        function buildNextPageUrl(nextPage) {
                            var url = new URL(window.location.href);
                            url.searchParams.set('tpage', String(nextPage));
                            return url.toString();
                        }

                        function executeScripts(container) {
                            container.querySelectorAll('script').forEach(function(oldScript) {
                                var newScript = document.createElement('script');
                                Array.from(oldScript.attributes).forEach(function(attr) {
                                    newScript.setAttribute(attr.name, attr.value);
                                });
                                newScript.textContent = oldScript.textContent;
                                if (oldScript.parentNode) oldScript.parentNode.replaceChild(newScript, oldScript);
                            });
                        }

                        function appendNextPage() {
                            if (isLoading || currentPage >= totalPages) return Promise.resolve();
                            isLoading = true;
                            if (lazyWrap) lazyWrap.classList.remove('d-none');
                            if (lazyIndicator) lazyIndicator.classList.remove('d-none');

                            return fetch(buildNextPageUrl(currentPage + 1), {
                                method: 'GET',
                                credentials: 'same-origin'
                            })
                                .then(function(res) { return res.text(); })
                                .then(function(html) {
                                    var doc = new DOMParser().parseFromString(html, 'text/html');
                                    var newTableBody = doc.getElementById('ticketTableBody');
                                    var newModalsContainer = doc.getElementById('ticketModalsContainer');
                                    var newMeta = doc.getElementById('ticketLazyMeta');
                                    if (!newTableBody) throw new Error('Gagal memuat tiket');

                                    var newSentinel = newTableBody.querySelector('#ticketLazySentinel');
                                    if (newSentinel) newSentinel.remove();
                                    tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);
                                    // Pindahkan sentinel ke bawah lagi supaya tetap jadi baris paling akhir
                                    tableBody.appendChild(sentinelRow);

                                    if (modalsContainer && newModalsContainer) {
                                        modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                                        executeScripts(modalsContainer);
                                    }

                                    var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                                    currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);
                                    updateStatusText();
                                    if (currentPage >= totalPages && lazyWrap) {
                                        lazyWrap.classList.add('d-none');
                                    }
                                })
                                .catch(function(err) {
                                    console.error('Gagal lazy load tiket:', err);
                                })
                                .finally(function() {
                                    isLoading = false;
                                    if (lazyIndicator) lazyIndicator.classList.add('d-none');
                                });
                        }

                        // Dipakai tombol "Select All" supaya bisa memuat semua tiket
                        // yang belum ke-scroll dulu sebelum menandai semuanya terpilih.
                        window.ticketLoadAllRemaining = function() {
                            var chain = Promise.resolve();
                            function step() {
                                if (currentPage >= totalPages) return Promise.resolve();
                                return appendNextPage().then(step);
                            }
                            return chain.then(step);
                        };

                        var observer = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting) appendNextPage();
                            });
                        }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
                        observer.observe(sentinelRow);

                        window.addEventListener('scroll', function() {
                            if (isLoading || currentPage >= totalPages) return;
                            var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
                            if (nearBottom) appendNextPage();
                        }, { passive: true });

                        updateStatusText();
                    })();
                    </script>
                </div>
            </div>
        </div>

        <div class="col-lg-3">
            <?php if (!$is_assistant): ?>
            <div class="card mb-3">
                <div class="card-header pb-0"><h6><i class="fas fa-user-cog me-1"></i>Manajemen Teknisi</h6></div>
                <div class="card-body text-center">
                    <p class="text-muted mb-3">Kelola akun teknisi di menu User Settings.</p>
                    <a href="user.php" class="btn btn-success w-100"><i class="fas fa-arrow-right me-1"></i>Ke Management Teknisi</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($can_create_ticket): ?>
            <div class="card">
                <div class="card-header pb-0"><h6><i class="fas fa-plus-circle me-1"></i>Buat Tiket</h6></div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-2">
                            <label class="form-label">Judul Tiket</label>
                            <input type="text" name="judul" class="form-control" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Detail</label>
                            <textarea name="detail" rows="4" class="form-control"></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Project (Server/Brand/Area)</label>
                            <select class="form-select" name="server_id" id="createServerSelect" required>
                                <option value="">Pilih Project</option>
                                <?php foreach ($allowed_servers as $sid => $srv): ?>
                                    <option value="<?php echo (int)$sid; ?>"><?php echo h((string)$srv['PEMILIK']); ?> | <?php echo h((string)$srv['BRAND']); ?> | <?php echo h((string)$srv['AREA']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Tipe Pekerjaan</label>
                            <select class="form-select" name="tipe" id="createTypeSelect">
                                <?php foreach ($default_types as $tp): ?>
                                    <option value="<?php echo h($tp); ?>"><?php echo h($tp); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!$is_assistant): ?>
                        <div class="mb-3">
                            <label class="form-label">Assign Teknisi</label>
                            <select class="form-select" name="teknisi_user_id" required>
                                <option value="0" selected disabled>Pilih Teknisi</option>
                                <?php foreach ($teknisi_list as $tid => $teknisi): ?>
                                    <option value="<?php echo (int)$tid; ?>"><?php echo h((string)$teknisi['USERNAME']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" name="create_ticket" class="btn btn-primary w-100">Simpan Tiket</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$is_assistant): ?>
<div class="modal fade" id="projectSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content project-settings-box">
            <div class="modal-header bg-info text-white">
                <h6 class="modal-title mb-0"><i class="fas fa-cogs me-1"></i>Project Settings Billing (Per Server)</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="tiket_manager.php#project-settings">
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Pilih Server</label>
                            <select class="form-select" id="settingsServerSelect" name="settings_server_id" required>
                                <option value="">Pilih Server</option>
                                <?php foreach ($allowed_servers as $sid => $srv): ?>
                                    <option value="<?php echo (int)$sid; ?>"><?php echo h((string)$srv['PEMILIK']); ?> | <?php echo h((string)$srv['BRAND']); ?> | <?php echo h((string)$srv['AREA']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Nama Project Billing</label>
                            <input type="text" class="form-control" id="settingsProjectName" name="project_name" placeholder="Contoh: FIBERQ JATINEGARA">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tipe Aktif</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach ($project_setting_types as $tp): ?>
                                    <div class="form-check">
                                        <input class="form-check-input settings-type-checkbox" type="checkbox" name="tipe_aktif[]" value="<?php echo h($tp); ?>" id="tp<?php echo h($tp); ?>">
                                        <label class="form-check-label" for="tp<?php echo h($tp); ?>"><?php echo h($tp); ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Provisioning</label>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="settingsProvisioningEnabled" name="provisioning_enabled" value="1">
                                <label class="form-check-label" for="settingsProvisioningEnabled">Aktifkan Provisioning (pola Joblist: khusus proses INSTALLASI)</label>
                            </div>
                            <small class="text-muted d-block mt-1">Saat aktif, tiket INSTALLASI yang diubah ke DONE otomatis diproses ke antrian provisioning billing (sama pola Joblist).</small>

                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="settingsDismantleDeleteEnabled" name="hapus_billing_dismantle" value="1">
                                <label class="form-check-label" for="settingsDismantleDeleteEnabled">Aktifkan DISMANTLE untuk hapus data pelanggan billing</label>
                            </div>
                            <small class="text-muted d-block mt-1">Jika aktif, tiket DISMANTLE dengan status DONE akan memindahkan data ke pelanggan_berhenti lalu menghapus dari pelanggan.</small>

                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="settingsSignatureEnabled" name="customer_signature_enabled" value="1">
                                <label class="form-check-label" for="settingsSignatureEnabled">Aktifkan tanda tangan pelanggan (khusus INSTALLASI)</label>
                            </div>
                            <small class="text-muted d-block mt-1">Jika aktif, field evidence Tanda tangan pelanggan akan muncul dan wajib saat status DONE.</small>
                        </div>
                    </div>

                    <hr>
                    <p class="text-sm text-muted mb-3">Evidence format: isi judul evidence satu baris satu item. Kolom Evidence Wajib diisi dengan judul yang wajib, satu baris satu item.</p>
                    <div class="row g-3">
                        <?php foreach ($project_setting_types as $tp): ?>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header pb-0"><h6 class="mb-0"><?php echo h($tp); ?></h6></div>
                                <div class="card-body">
                                    <div class="mb-2">
                                        <label class="form-label">Daftar Evidence</label>
                                        <textarea class="form-control settings-evidence-titles" data-type="<?php echo h($tp); ?>" name="evidence_titles[<?php echo h($tp); ?>]" placeholder="contoh:\nfoto modem\nspeedtest\nfoto ODP"></textarea>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label">Evidence Wajib</label>
                                        <textarea class="form-control settings-evidence-required" data-type="<?php echo h($tp); ?>" name="evidence_required_titles[<?php echo h($tp); ?>]" placeholder="contoh:\nfoto modem\nspeedtest"></textarea>
                                    </div>
                                    <div>
                                        <label class="form-label">Template Report</label>
                                        <textarea class="form-control settings-report-template" data-type="<?php echo h($tp); ?>" name="report_format[<?php echo h($tp); ?>]" placeholder="Template report untuk tipe <?php echo h($tp); ?>"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" name="save_project_settings" class="btn btn-primary">Simpan Setting</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade tm-process-modal" id="processActionModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title mb-0" id="processActionModalTitle">Proses Tiket</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="processActionFrame" class="tm-process-frame" src="about:blank"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const settingsMap = <?php echo json_encode($settings_js_map, JSON_UNESCAPED_UNICODE); ?>;
    const defaultReportTemplates = <?php echo json_encode($default_report_templates, JSON_UNESCAPED_UNICODE); ?>;
    const defaultEvidenceTemplates = <?php echo json_encode($default_evidence_templates, JSON_UNESCAPED_UNICODE); ?>;
    const settingsSaveAttempted = <?php echo isset($_POST['save_project_settings']) ? 'true' : 'false'; ?>;
    const settingsServerPosted = <?php echo json_encode((string)($_POST['settings_server_id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;

    function splitLines(str) {
        if (!str) return [];
        return String(str).split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean);
    }

    function isSignatureTitle(title) {
        const t = String(title || '').trim().toLowerCase();
        return t === 'tanda tangan pelanggan' || t === 'tandatangan pelanggan' || t === 'customer signature' || t === 'signature';
    }

    function parseJsonSafe(raw, fallback) {
        try {
            const parsed = JSON.parse(String(raw || ''));
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function normalizeEvidenceTitle(title) {
        return String(title || '')
            .toLowerCase()
            .replace(/[^a-z0-9]/g, '');
    }

    function hasMetaEntriesForTitle(meta, title) {
        if (!meta || typeof meta !== 'object') return false;
        const exact = Array.isArray(meta[title]) ? meta[title] : [];
        if (exact.length > 0) return true;

        const normalizedTarget = normalizeEvidenceTitle(title);
        for (const key in meta) {
            if (!Object.prototype.hasOwnProperty.call(meta, key)) continue;
            if (normalizeEvidenceTitle(key) !== normalizedTarget) continue;
            const entries = Array.isArray(meta[key]) ? meta[key] : [];
            if (entries.length > 0) return true;
        }
        return false;
    }

    function getCurrentFieldsForType(selectEl) {
        const configRaw = parseJsonSafe(selectEl.getAttribute('data-config') || '{}', {});
        const tipe = String(selectEl.value || '').toUpperCase();
        let fields = Array.isArray(configRaw[tipe]) ? configRaw[tipe] : [];
        if (!fields.length && defaultEvidenceTemplates[tipe] && Array.isArray(defaultEvidenceTemplates[tipe].titles)) {
            fields = defaultEvidenceTemplates[tipe].titles.map((title, idx) => ({
                title: title,
                required: parseInt((defaultEvidenceTemplates[tipe].required || [])[idx] || 0, 10) === 1
            }));
        }
        return fields;
    }

    function getEvidenceRequirementState(selectEl) {
        let form = selectEl.closest('form');
        if (!form) {
            const ticketId = String(selectEl.getAttribute('data-ticket-id') || '').trim();
            if (ticketId !== '') {
                form = document.getElementById('ticketUpdateForm' + ticketId);
            }
        }

        const targetId = String(selectEl.getAttribute('data-target') || '').trim();
        const evidenceContainer = targetId !== '' ? document.getElementById(targetId) : null;
        if (!form && !evidenceContainer) {
            return { ready: false, requiredTitles: [], missingTitles: [] };
        }

        const meta = parseJsonSafe(selectEl.getAttribute('data-evidence-meta') || '{}', {});
        const fields = getCurrentFieldsForType(selectEl)
            .map((f, idx) => ({ idx, title: String(f.title || ''), required: !!f.required }))
            .filter(f => f.required);
        if (!fields.length) {
            return { ready: true, requiredTitles: [], missingTitles: [] };
        }

        const requiredTitles = [];
        const missingTitles = [];

        for (const field of fields) {
            const title = String(field.title || '');
            const idx = Number(field.idx);
            requiredTitles.push(title);

            if (hasMetaEntriesForTitle(meta, title)) {
                continue;
            }

            if (isSignatureTitle(title)) {
                const sigInput =
                    (evidenceContainer && evidenceContainer.querySelector('.js-signature-data[data-evidence-idx="' + idx + '"]'))
                    || (form && form.querySelector('.js-signature-data[data-evidence-idx="' + idx + '"]'));
                const sigVal = sigInput ? String(sigInput.value || '').trim() : '';
                if (sigVal !== '') {
                    continue;
                }
                missingTitles.push(title);
                continue;
            }

            const fileInput =
                (evidenceContainer && evidenceContainer.querySelector('.js-evidence-file[data-evidence-idx="' + idx + '"]'))
                || (form && form.querySelector('.js-evidence-file[data-evidence-idx="' + idx + '"]'));
            if (fileInput && fileInput.files && fileInput.files.length > 0) {
                continue;
            }
            missingTitles.push(title);
        }

        return {
            ready: missingTitles.length === 0,
            requiredTitles: requiredTitles,
            missingTitles: missingTitles
        };
    }

    function updateProcessActionState(selectEl) {
        const targetId = selectEl.getAttribute('data-process-target');
        const target = document.getElementById(targetId);
        if (!target) return;
        const reqState = getEvidenceRequirementState(selectEl);
        const ready = reqState.ready;
        const serverId = String(selectEl.getAttribute('data-server-id') || '').trim();
        let provisioningEnabled = parseInt(selectEl.getAttribute('data-provisioning-enabled') || '0', 10) === 1;
        if (!provisioningEnabled && serverId !== '' && settingsMap[serverId]) {
            provisioningEnabled = parseInt(settingsMap[serverId].provisioning_enabled || 0, 10) === 1;
        }
        const dismantleEnabled = parseInt(selectEl.getAttribute('data-dismantle-enabled') || '0', 10) === 1;
        const isDismantleMode = String(selectEl.value || '').toUpperCase() === 'DISMANTLE';
        target.querySelectorAll('.js-process-requires-evidence').forEach((btn) => {
            btn.disabled = !ready;
        });
        target.querySelectorAll('.js-process-requires-provisioning').forEach((btn) => {
            btn.disabled = btn.disabled || !provisioningEnabled;
        });
        target.querySelectorAll('.js-process-requires-dismantle').forEach((btn) => {
            btn.disabled = !dismantleEnabled;
        });
        const hintEl = target.querySelector('.js-process-hint');
        if (hintEl) {
            const mapTitle = (t) => isSignatureTitle(t) ? (t + ' (gambar canvas)') : t;
            const requiredText = reqState.requiredTitles.length > 0
                ? reqState.requiredTitles.map(mapTitle).join(', ')
                : '-';
            const missingText = reqState.missingTitles.length > 0
                ? reqState.missingTitles.map(mapTitle).join(', ')
                : '-';

            if (!isDismantleMode && !provisioningEnabled && target.querySelector('.js-process-requires-provisioning')) {
                hintEl.textContent = 'Fitur provisioning belum aktif di Project Settings server ini. Evidence wajib: ' + requiredText;
            } else if (isDismantleMode && !dismantleEnabled && target.querySelector('.js-process-requires-dismantle')) {
                hintEl.textContent = 'Mode hapus billing DISMANTLE belum aktif di Project Settings. Evidence wajib: ' + requiredText;
            } else {
                hintEl.textContent = ready
                    ? ('Evidence wajib lengkap: ' + requiredText + '. Proses bisa dijalankan.')
                    : ('Evidence wajib: ' + requiredText + '. Masih kurang: ' + missingText + '.');
            }
        }
    }

    function initSignatureCanvas(canvasEl, hiddenEl, clearBtnEl) {
        if (!canvasEl || !hiddenEl) return;
        const dpr = window.devicePixelRatio || 1;
        const setSize = () => {
            const width = Math.max(canvasEl.clientWidth || 320, 320);
            const height = 140;
            canvasEl.width = Math.floor(width * dpr);
            canvasEl.height = Math.floor(height * dpr);
            canvasEl.style.height = height + 'px';
            const ctx0 = canvasEl.getContext('2d');
            if (!ctx0) return;
            ctx0.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx0.lineWidth = 2;
            ctx0.lineCap = 'round';
            ctx0.lineJoin = 'round';
            ctx0.strokeStyle = '#111827';
            ctx0.fillStyle = '#ffffff';
            ctx0.fillRect(0, 0, width, height);
            hiddenEl.value = '';
        };

        setSize();
        let drawing = false;
        let hasStroke = false;

        const getXY = (ev) => {
            const rect = canvasEl.getBoundingClientRect();
            if (ev.touches && ev.touches[0]) {
                return { x: ev.touches[0].clientX - rect.left, y: ev.touches[0].clientY - rect.top };
            }
            return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
        };

        const start = (ev) => {
            ev.preventDefault();
            const ctx = canvasEl.getContext('2d');
            if (!ctx) return;
            const p = getXY(ev);
            drawing = true;
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
        };

        const move = (ev) => {
            if (!drawing) return;
            ev.preventDefault();
            const ctx = canvasEl.getContext('2d');
            if (!ctx) return;
            const p = getXY(ev);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            hasStroke = true;
        };

        const end = (ev) => {
            if (!drawing) return;
            ev.preventDefault();
            drawing = false;
            if (hasStroke) {
                hiddenEl.value = canvasEl.toDataURL('image/png');
                hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        canvasEl.addEventListener('mousedown', start);
        canvasEl.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);

        canvasEl.addEventListener('touchstart', start, { passive: false });
        canvasEl.addEventListener('touchmove', move, { passive: false });
        canvasEl.addEventListener('touchend', end, { passive: false });

        if (clearBtnEl) {
            clearBtnEl.addEventListener('click', function() {
                setSize();
                drawing = false;
                hasStroke = false;
                hiddenEl.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
    }

    function renderTicketEvidenceFields(selectEl) {
        const targetId = selectEl.getAttribute('data-target');
        const target = document.getElementById(targetId);
        if (!target) return;

        const tipe = String(selectEl.value || '').toUpperCase();
        const ticketId = String(selectEl.getAttribute('data-ticket-id') || '').trim();
        const fields = getCurrentFieldsForType(selectEl);

        if (!fields.length) {
            target.innerHTML = '<div class="alert alert-secondary py-2 mb-0">Tidak ada field evidence untuk tipe ini.</div>';
            return;
        }

        let html = '<div class="card mt-2"><div class="card-header py-2"><strong>Evidence Proses (' + tipe + ')</strong></div><div class="card-body py-2">';
        fields.forEach((f, idx) => {
            const required = !!f.required;
            const star = required ? ' <span class="text-danger">*</span>' : '';
            const isSignature = isSignatureTitle(f.title);
            const titleAttr = String(f.title).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            html += '<div class="mb-2">';
            html += '<label class="form-label mb-1">' + String(f.title) + star + '</label>';
            if (isSignature) {
                const sigName = ticketId !== '' ? ('signature_data_' + ticketId + '_' + idx) : ('signature_data_' + idx);
                const sigId = ticketId !== '' ? ('signature_canvas_' + ticketId + '_' + idx) : ('signature_canvas_' + idx);
                const clearId = ticketId !== '' ? ('signature_clear_' + ticketId + '_' + idx) : ('signature_clear_' + idx);
                html += '<div class="border rounded p-2 bg-white">';
                html += '<canvas id="' + sigId + '" class="w-100 js-signature-canvas" style="height:140px; border:1px solid #cbd5e1; border-radius:6px; touch-action:none;"></canvas>';
                html += '<input type="hidden" name="' + sigName + '" class="js-signature-data" data-title="' + titleAttr + '" data-evidence-idx="' + idx + '">';
                html += '<div class="d-flex justify-content-end mt-2">';
                html += '<button type="button" id="' + clearId + '" class="btn btn-sm btn-outline-secondary">Clear Tanda Tangan</button>';
                html += '</div>';
                html += '</div>';
            } else {
                const fileName = ticketId !== '' ? ('evidence_file_' + ticketId + '_' + idx) : ('evidence_file_' + idx);
                html += '<input type="file" class="form-control form-control-sm js-evidence-file" data-title="' + titleAttr + '" data-evidence-idx="' + idx + '" name="' + fileName + '" accept=".jpg,.jpeg,.png,.webp">';
            }
            html += '</div>';
        });
        html += '<small class="text-muted">Field bertanda * wajib ada saat status menjadi DONE. File lama tetap dihitung.</small>';
        html += '</div></div>';
        target.innerHTML = html;

        target.querySelectorAll('.js-signature-canvas').forEach((canvasEl) => {
            const wrapper = canvasEl.closest('.border');
            if (!wrapper) return;
            const hiddenEl = wrapper.querySelector('.js-signature-data');
            const clearBtnEl = wrapper.querySelector('button');
            initSignatureCanvas(canvasEl, hiddenEl, clearBtnEl);
        });

        target.addEventListener('change', function() {
            updateProcessActionState(selectEl);
        });
    }

    function fillReportTemplateForType(selectEl, setDone) {
        const form = selectEl.closest('form');
        if (!form) return;
        const type = String(selectEl.value || '').toUpperCase();
        const reportEl = form.querySelector('.ticket-report-input');
        if (!reportEl) return;

        const detailEl = form.querySelector('.ticket-detail-raw');
        const detailRaw = detailEl ? String(detailEl.value || '') : '';
        const projectName = String(selectEl.getAttribute('data-project-name') || '');
        const today = new Date().toISOString().slice(0, 10);

        let tpl = String(defaultReportTemplates[type] || '');
        if (!tpl) return;

        tpl = tpl
            .replace(/\{nama_project\}/g, projectName)
            .replace(/\{tanggal\}/g, today)
            .replace(/\{nama\}/g, 'TEKNISI')
            .replace(/\{data\}/g, detailRaw)
            .replace(/\{tipe\}/g, type);

        const existing = String(reportEl.value || '').trim();
        if (!existing) {
            reportEl.value = tpl;
        }

        if (setDone) {
            const statusEl = form.querySelector('select[name="status"]');
            if (statusEl) {
                statusEl.value = 'DONE';
            }
        }
    }

    function setDefaultSubmitVisibility(selectEl, visible) {
        const form = selectEl.closest('form');
        if (!form) return;
        const submitRow = form.querySelector('.js-default-submit-row');
        if (!submitRow) return;
        if (visible) {
            submitRow.classList.remove('tm-hidden');
            submitRow.removeAttribute('hidden');
            submitRow.style.display = '';
        } else {
            submitRow.classList.add('tm-hidden');
            submitRow.setAttribute('hidden', 'hidden');
            submitRow.style.display = 'none';
        }
    }

    function submitFromProcess(selectEl, mode) {
        const form = selectEl.closest('form');
        if (!form) return;

        const statusEl = form.querySelector('select[name="status"]');
        const modeUpper = String(mode || '').toUpperCase();

        if (modeUpper === 'PROVISIONING' || modeUpper === 'DISMANTLE') {
            const reqState = getEvidenceRequirementState(selectEl);
            if (!reqState.ready) {
                const missing = reqState.missingTitles.length > 0 ? reqState.missingTitles.join(', ') : '-';
                alert('Evidence wajib belum lengkap. Masih kurang: ' + missing);
                updateProcessActionState(selectEl);
                return;
            }
        }

        if (modeUpper === 'PROVISIONING') {
            fillReportTemplateForType(selectEl, true);
        } else if (modeUpper === 'DISMANTLE') {
            fillReportTemplateForType(selectEl, true);
            if (statusEl) statusEl.value = 'DONE';
        } else if (modeUpper === 'REPORT_ONLY') {
            fillReportTemplateForType(selectEl, false);
        }

        let processFlag = form.querySelector('input[name="process_submit"]');
        if (!processFlag) {
            processFlag = document.createElement('input');
            processFlag.type = 'hidden';
            processFlag.name = 'process_submit';
            form.appendChild(processFlag);
        }
        processFlag.value = modeUpper !== '' ? modeUpper : '1';

        let updateFlag = form.querySelector('input[name="update_ticket"]');
        if (!updateFlag) {
            updateFlag = document.createElement('input');
            updateFlag.type = 'hidden';
            updateFlag.name = 'update_ticket';
            form.appendChild(updateFlag);
        }
        updateFlag.value = '1';

        form.submit();
    }

    function renderTicketProcessFields(selectEl) {
        const targetId = selectEl.getAttribute('data-process-target');
        const target = document.getElementById(targetId);
        if (!target) return;
        const ticketId = String(selectEl.getAttribute('data-ticket-id') || '').trim();
        const serverId = String(selectEl.getAttribute('data-server-id') || '').trim();
        const provisioningUrl = ticketId !== '' ? ('tiket_manager_provisioning.php?ticket_id=' + encodeURIComponent(ticketId)) : '#';
        const dismantleUrl = ticketId !== '' ? ('tiket_manager_dismantle.php?ticket_id=' + encodeURIComponent(ticketId)) : '#';
        const formAttr = ticketId !== '' ? (' form="ticketUpdateForm' + ticketId + '"') : '';

        const tipe = String(selectEl.value || '').toUpperCase();
        let provisioningEnabled = parseInt(selectEl.getAttribute('data-provisioning-enabled') || '0', 10) === 1;
        let dismantleEnabled = parseInt(selectEl.getAttribute('data-dismantle-enabled') || '0', 10) === 1;
        if (serverId !== '' && settingsMap[serverId]) {
            if (!provisioningEnabled) {
                provisioningEnabled = parseInt(settingsMap[serverId].provisioning_enabled || 0, 10) === 1;
            }
            if (!dismantleEnabled) {
                dismantleEnabled = parseInt(settingsMap[serverId].hapus_billing_dismantle || 0, 10) === 1;
            }
        }
        const showProvisioning = (tipe === 'INSTALLASI');
        const showDismantle = tipe === 'DISMANTLE' && dismantleEnabled;

        if (!showProvisioning && !showDismantle) {
            target.innerHTML = ''
                + '<div class="card border-warning mt-2">'
                + '  <div class="card-header py-2"><strong>Proses Tiket</strong></div>'
                + '  <div class="card-body py-2">'
                + '    <button type="submit" class="btn btn-success btn-lg w-100" name="process_submit" value="REPORT_ONLY"' + formAttr + '>'
                + '      <i class="fas fa-save me-1"></i> SIMPAN DATA'
                + '      <span class="d-block small mt-1">Submit update tiket</span>'
                + '    </button>'
                + '  </div>'
                + '</div>';
            return;
        }

        setDefaultSubmitVisibility(selectEl, false);

        if (showProvisioning) {
            target.innerHTML = ''
                + '<div class="card border-warning mt-2">'
                + '  <div class="card-header py-2"><strong>Proses PROVISIONING</strong></div>'
                + '  <div class="card-body py-2">'
                + '    <button type="button" class="btn btn-primary btn-lg w-100 mb-2 js-open-process-link js-process-requires-evidence js-process-requires-provisioning" data-href="' + provisioningUrl + '">'
                + '      <i class="fas fa-server me-1"></i> PROVISIONING'
                + '      <span class="d-block small mt-1">Buka form provisioning (sama seperti Joblist)</span>'
                + '    </button>'
                + '    <button type="submit" class="btn btn-success btn-lg w-100" name="process_submit" value="REPORT_ONLY"' + formAttr + '>'
                + '      <i class="fas fa-save me-1"></i> SIMPAN LAPORAN SAJA'
                + '      <span class="d-block small mt-1">Isi template + submit tanpa paksa DONE</span>'
                + '    </button>'
                + '    <small class="text-muted d-block mt-2 js-process-hint"></small>'
                + '  </div>'
                + '</div>';
        } else if (showDismantle) {
            target.innerHTML = ''
                + '<div class="card border-warning mt-2">'
                + '  <div class="card-header py-2"><strong>Proses DISMANTLE</strong></div>'
                + '  <div class="card-body py-2">'
                + '    <button type="button" class="btn btn-danger btn-lg w-100 mb-2 js-open-process-link js-process-requires-evidence js-process-requires-dismantle" data-href="' + dismantleUrl + '">'
                + '      <i class="fas fa-exclamation-triangle me-1"></i> PROSES DISMANTLE'
                + '      <span class="d-block small mt-1">Buka proses dismantle (sama seperti Joblist)</span>'
                + '    </button>'
                + '    <small class="text-muted d-block mt-2 js-process-hint"></small>'
                + '  </div>'
                + '</div>';
        }

        const btnFill = target.querySelector('.js-fill-template');
        if (btnFill) {
            btnFill.addEventListener('click', function() {
                fillReportTemplateForType(selectEl, false);
            });
        }
        const btnFillDone = target.querySelector('.js-fill-template-done');
        if (btnFillDone) {
            btnFillDone.addEventListener('click', function() {
                fillReportTemplateForType(selectEl, true);
            });
        }

        updateProcessActionState(selectEl);
    }

    function bindTicketEvidenceFields() {
        document.querySelectorAll('.ticket-tipe-select').forEach((el) => {
            renderTicketEvidenceFields(el);
            renderTicketProcessFields(el);
            el.addEventListener('change', function() {
                renderTicketEvidenceFields(this);
                renderTicketProcessFields(this);
            });
        });
    }

    function refreshCreateTypeOptions() {
        const serverSelect = document.getElementById('createServerSelect');
        const typeSelect = document.getElementById('createTypeSelect');
        if (!serverSelect || !typeSelect) return;

        const sid = String(serverSelect.value || '');
        const setting = settingsMap[sid] || null;
        const types = setting && Array.isArray(setting.tipe_aktif) && setting.tipe_aktif.length
            ? setting.tipe_aktif.filter(tp => tp !== 'PROVISIONING')
            : ['INSTALLASI', 'MAINTENANCE', 'MIGRASI', 'DISMANTLE'];

        typeSelect.innerHTML = '';
        types.forEach(tp => {
            const opt = document.createElement('option');
            opt.value = tp;
            opt.textContent = tp;
            typeSelect.appendChild(opt);
        });
    }

    function applySettingsForm(sid) {
        const setting = settingsMap[String(sid)] || null;
        const projectName = document.getElementById('settingsProjectName');
        if (!projectName) return;

        document.querySelectorAll('.settings-type-checkbox').forEach(cb => {
            cb.checked = false;
        });

        document.querySelectorAll('.settings-evidence-titles').forEach(t => {
            t.value = '';
        });
        document.querySelectorAll('.settings-evidence-required').forEach(t => {
            t.value = '';
        });
        document.querySelectorAll('.settings-report-template').forEach(t => {
            t.value = '';
        });

        const provisioningToggle = document.getElementById('settingsProvisioningEnabled');
        const dismantleDeleteToggle = document.getElementById('settingsDismantleDeleteEnabled');
        const signatureToggle = document.getElementById('settingsSignatureEnabled');
        if (provisioningToggle) {
            provisioningToggle.checked = false;
        }
        if (dismantleDeleteToggle) {
            dismantleDeleteToggle.checked = false;
        }
        if (signatureToggle) {
            signatureToggle.checked = false;
        }

        if (!setting) {
            projectName.value = '';
            document.querySelectorAll('.settings-type-checkbox').forEach((cb, idx) => {
                cb.checked = true;
            });

            document.querySelectorAll('.settings-evidence-titles').forEach(t => {
                const tp = t.getAttribute('data-type');
                const lines = (defaultEvidenceTemplates[tp] && Array.isArray(defaultEvidenceTemplates[tp].titles)) ? defaultEvidenceTemplates[tp].titles : [];
                t.value = lines.join('\n');
            });

            document.querySelectorAll('.settings-evidence-required').forEach(t => {
                const tp = t.getAttribute('data-type');
                const cfg = defaultEvidenceTemplates[tp] || { titles: [], required: [] };
                const titles = Array.isArray(cfg.titles) ? cfg.titles : [];
                const flags = Array.isArray(cfg.required) ? cfg.required : [];
                const requiredTitles = [];
                titles.forEach((title, idx) => {
                    if (parseInt(flags[idx] || 0, 10) === 1) requiredTitles.push(title);
                });
                t.value = requiredTitles.join('\n');
            });

            document.querySelectorAll('.settings-report-template').forEach(t => {
                const tp = t.getAttribute('data-type');
                t.value = String(defaultReportTemplates[tp] || '');
            });
            return;
        }

        if (provisioningToggle) {
            provisioningToggle.checked = parseInt(setting.provisioning_enabled || 0, 10) === 1;
        }
        if (dismantleDeleteToggle) {
            dismantleDeleteToggle.checked = parseInt(setting.hapus_billing_dismantle || 0, 10) === 1;
        }
        if (signatureToggle) {
            signatureToggle.checked = parseInt(setting.customer_signature_enabled || 0, 10) === 1;
        }

        projectName.value = setting.project_name || '';

        const active = Array.isArray(setting.tipe_aktif) ? setting.tipe_aktif : [];
        document.querySelectorAll('.settings-type-checkbox').forEach(cb => {
            cb.checked = active.includes(cb.value);
        });

        const ev = setting.evidence_per_tipe || {};
        const req = setting.evidence_required_per_tipe || {};
        const report = setting.report_format_per_tipe || {};

        document.querySelectorAll('.settings-evidence-titles').forEach(t => {
            const tp = t.getAttribute('data-type');
            const lines = Array.isArray(ev[tp]) ? ev[tp] : [];
            if (lines.length > 0) {
                t.value = lines.join('\n');
            } else {
                const dlines = (defaultEvidenceTemplates[tp] && Array.isArray(defaultEvidenceTemplates[tp].titles)) ? defaultEvidenceTemplates[tp].titles : [];
                t.value = dlines.join('\n');
            }
        });

        document.querySelectorAll('.settings-evidence-required').forEach(t => {
            const tp = t.getAttribute('data-type');
            let titles = Array.isArray(ev[tp]) ? ev[tp] : [];
            let flags = Array.isArray(req[tp]) ? req[tp] : [];
            if (!titles.length && defaultEvidenceTemplates[tp]) {
                titles = Array.isArray(defaultEvidenceTemplates[tp].titles) ? defaultEvidenceTemplates[tp].titles : [];
                flags = Array.isArray(defaultEvidenceTemplates[tp].required) ? defaultEvidenceTemplates[tp].required : [];
            }
            const requiredTitles = [];
            titles.forEach((title, idx) => {
                if (parseInt(flags[idx] || 0, 10) === 1) requiredTitles.push(title);
            });
            t.value = requiredTitles.join('\n');
        });

        document.querySelectorAll('.settings-report-template').forEach(t => {
            const tp = t.getAttribute('data-type');
            const val = String(report[tp] || '');
            t.value = val !== '' ? val : String(defaultReportTemplates[tp] || '');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        bindTicketEvidenceFields();
        refreshCreateTypeOptions();

        const bulkForm = document.getElementById('bulkTicketActionForm');
        const bulkSelectAll = document.getElementById('bulkSelectAllTickets');
        const bulkActionSelect = document.getElementById('bulkActionSelect');
        const bulkTeknisiSelect = document.getElementById('bulkTeknisiSelect');
        const bulkSubmitBtn = document.getElementById('bulkActionSubmitBtn');

        function getBulkItems() {
            return Array.from(document.querySelectorAll('.js-bulk-ticket-item'));
        }

        function getSelectedBulkIds() {
            return getBulkItems()
                .filter((cb) => cb.checked)
                .map((cb) => parseInt(cb.getAttribute('data-ticket-id') || cb.value || '0', 10))
                .filter((id) => id > 0);
        }

        function updateBulkControls() {
            const selectedIds = getSelectedBulkIds();
            const hasSelection = selectedIds.length > 0;
            const actionVal = String((bulkActionSelect && bulkActionSelect.value) || '').toUpperCase();
            const needsTeknisi = actionVal === 'ASSIGN';

            if (bulkTeknisiSelect) {
                bulkTeknisiSelect.disabled = !needsTeknisi;
                bulkTeknisiSelect.required = needsTeknisi;
                if (!needsTeknisi) {
                    bulkTeknisiSelect.value = '0';
                }
            }

            if (bulkSubmitBtn) {
                const teknisiValid = !needsTeknisi || (bulkTeknisiSelect && parseInt(bulkTeknisiSelect.value || '0', 10) > 0);
                bulkSubmitBtn.disabled = !(hasSelection && actionVal !== '' && teknisiValid);
            }

            if (bulkSelectAll) {
                const items = getBulkItems();
                const checkedCount = items.filter((cb) => cb.checked).length;
                bulkSelectAll.checked = items.length > 0 && checkedCount === items.length;
                bulkSelectAll.indeterminate = checkedCount > 0 && checkedCount < items.length;
            }
        }

        if (bulkSelectAll) {
            bulkSelectAll.addEventListener('change', function() {
                const checked = !!this.checked;
                // Tiket yang belum ke-scroll (lazy-load) belum ada checkbox-nya di DOM -
                // muat semua dulu supaya "Select All" benar-benar mencakup seluruh tiket
                // yang cocok dengan filter, bukan cuma yang sudah tampil.
                const loadAll = (checked && typeof window.ticketLoadAllRemaining === 'function')
                    ? window.ticketLoadAllRemaining()
                    : Promise.resolve();
                loadAll.then(function() {
                    getBulkItems().forEach((cb) => {
                        cb.checked = checked;
                    });
                    if (bulkSelectAll) bulkSelectAll.checked = checked;
                    updateBulkControls();
                });
            });
        }

        document.addEventListener('change', function(ev) {
            const target = ev.target;
            if (!target) return;
            if (target.classList.contains('js-bulk-ticket-item') || target.id === 'bulkActionSelect' || target.id === 'bulkTeknisiSelect') {
                updateBulkControls();
            }
        });

        if (bulkForm) {
            bulkForm.addEventListener('submit', function(ev) {
                const selectedIds = getSelectedBulkIds();
                const actionVal = String((bulkActionSelect && bulkActionSelect.value) || '').toUpperCase();

                if (!selectedIds.length) {
                    ev.preventDefault();
                    return;
                }

                if (actionVal === 'DELETE') {
                    const ok = window.confirm('Hapus tiket terpilih? Data tiket yang dihapus tidak bisa dikembalikan.');
                    if (!ok) {
                        ev.preventDefault();
                        return;
                    }
                }

                bulkForm.querySelectorAll('.js-bulk-hidden-id').forEach((el) => el.remove());
                selectedIds.forEach((id) => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'selected_ticket_ids[]';
                    hidden.value = String(id);
                    hidden.className = 'js-bulk-hidden-id';
                    bulkForm.appendChild(hidden);
                });
            });
        }

        updateBulkControls();

        document.addEventListener('change', function(ev) {
            const target = ev.target;
            if (!target || !target.closest) return;
            if (!target.closest('.js-evidence-file') && !target.closest('.js-signature-data')) return;

            const form = target.closest('form');
            if (!form) return;
            const selectEl = form.querySelector('.ticket-tipe-select');
            if (!selectEl) return;
            updateProcessActionState(selectEl);
        });

        document.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.js-open-process-link');
            if (!btn) return;
            if (btn.disabled) {
                ev.preventDefault();
                return;
            }
            const href = String(btn.getAttribute('data-href') || '').trim();
            if (href === '' || href === '#') {
                ev.preventDefault();
                return;
            }

            const modalEl = document.getElementById('processActionModal');
            const frameEl = document.getElementById('processActionFrame');
            const titleEl = document.getElementById('processActionModalTitle');
            if (!modalEl || !frameEl) {
                window.location.href = href;
                return;
            }

            const label = String(btn.textContent || '').toUpperCase();
            if (titleEl) {
                titleEl.textContent = label.indexOf('DISMANTLE') !== -1 ? 'Proses DISMANTLE' : 'Proses PROVISIONING';
            }

            frameEl.src = href;
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modal.show();
            } else {
                window.location.href = href;
            }
        });

        const processModalEl = document.getElementById('processActionModal');
        if (processModalEl) {
            processModalEl.addEventListener('hidden.bs.modal', function() {
                const frameEl = document.getElementById('processActionFrame');
                if (frameEl) {
                    frameEl.src = 'about:blank';
                }
            });
        }

        const createServerSelect = document.getElementById('createServerSelect');
        if (createServerSelect) {
            createServerSelect.addEventListener('change', refreshCreateTypeOptions);
        }

        const settingsServerSelect = document.getElementById('settingsServerSelect');
        if (settingsServerSelect) {
            settingsServerSelect.addEventListener('change', function() {
                applySettingsForm(this.value);
            });

            if (settingsSaveAttempted) {
                if (settingsServerPosted !== '') {
                    settingsServerSelect.value = settingsServerPosted;
                    applySettingsForm(settingsServerPosted);
                }
                const settingsModalEl = document.getElementById('projectSettingsModal');
                if (settingsModalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(settingsModalEl);
                    modalInstance.show();
                }
            }
        }
    });
})();
</script>

<?php require 'footer.php'; ?>