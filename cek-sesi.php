<?php
// Start PHP session (must be first to access $_SESSION)
session_start();

// Define today's date for use across the application
$today = date('Y-m-d');

// --- Load configuration (if any) ---
$config_file = __DIR__ . '/config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];


$userlogin = isset($_SESSION['PEMILIK']) ? $_SESSION['PEMILIK'] : '';


// Database connection
require 'koneksidb.php';

$ticket_management_source = 'tiket_manager';
$ticket_management_allowed = ['tiket_manager', 'joblist'];

$checkTicketSourceCol = @mysqli_query($conn, "SHOW COLUMNS FROM user LIKE 'ticket_management_source'");
if ($checkTicketSourceCol && mysqli_num_rows($checkTicketSourceCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE user ADD COLUMN ticket_management_source VARCHAR(20) NOT NULL DEFAULT 'tiket_manager'");
}

// Kolom untuk mengatur paket mana yang tampil di select form pendaftaran publik (pendaftaran_bot.php)
$checkTampilDaftarCol = @mysqli_query($conn, "SHOW COLUMNS FROM paket LIKE 'TAMPIL_DAFTAR'");
if ($checkTampilDaftarCol && mysqli_num_rows($checkTampilDaftarCol) === 0) {
    @mysqli_query($conn, "ALTER TABLE paket ADD COLUMN TAMPIL_DAFTAR TINYINT(1) NOT NULL DEFAULT 1");
}

require_once 'reseller_helper.php';
reseller_bootstrap_schema($conn);
require_once 'notifbot/bot_access_helper.php';
botAccessEnsureColumns($conn);
$assigned_bot_ids = [];
require_once 'notifbot/telegram_bot_access_helper.php';
telegramBotAccessEnsureColumns($conn);
require_once 'notifbot/telegram_send_helper.php';
telegramEnsurePelangganColumn($conn);
$assigned_telegram_bot_ids = [];
require_once 'livechat_access_helper.php';
livechatAccessEnsureColumn($conn);
require_once 'paket_visibility_helper.php';
paketVisibilityBootstrapSchema($conn);
$assigned_livechat_areas = [];
$assistant_role = null;
$is_reseller = false;
$reseller_id = null;
$reseller_price_filter_enabled = false;
$reseller_bandwidth_burden = 0.0;

$billing_path = str_replace('\\', '/', __DIR__);
$document_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')) : '';
$base_url = ($document_root && strpos($billing_path, $document_root) === 0) ? substr($billing_path, strlen($document_root)) : '';
$login_redirect = ($base_url ?: '/crm/billing') . '/index.php?pesan=belum_login';

// --- Session & access checks ---
if (!isset($_SESSION['status']) || $_SESSION['status'] != 'login') {
    header('Location:' . $login_redirect);
    exit;
}
if (empty($_SESSION['PEMILIK'])) {
    header('Location:' . $login_redirect);
    exit;
}

// Guard untuk endpoint aksi di folder proses: jika dibuka langsung tanpa data, kembalikan ke halaman sebelumnya.
$scriptPath = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
if (strpos($scriptPath, '/proses/') !== false) {
    $scriptName = basename($scriptPath);
    $isActionEndpoint = (bool)preg_match(
        '/^(add|edit|delete|disable|active|save|apply|clear|check|manual|broadcast|buat|konfirmasi|sendinvoice|simpannotif|verify|hapus|import|aktifkan|belivpn)/i',
        $scriptName
    );

    $isGetNoData = (
        (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'GET') &&
        empty($_GET) &&
        empty($_POST) &&
        empty($_FILES)
    );

    if ($isActionEndpoint && $isGetNoData) {
        $ref = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
        $self = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($ref === '' || $ref === $self) {
            $ref = '../index.php';
        }
        header('Location: ' . $ref);
        exit;
    }
}

// validasi role ceknama
$sql_cek_role = "SELECT * FROM `user` WHERE `USERNAME`='" . mysqli_real_escape_string($conn, $userlogin) . "'";
$query_cek_role = mysqli_query($conn, $sql_cek_role);
// Loop jika ditemukan (harusnya 1 row). Pastikan variabel diinisialisasi.
while ($hasil_cek_role = mysqli_fetch_array($query_cek_role)) {
	
    $id = $hasil_cek_role['id'];
	$STATUS = $hasil_cek_role['STATUS'];
    $raw_ticket_source = strtolower(trim((string)($hasil_cek_role['ticket_management_source'] ?? '')));
    if (!in_array($raw_ticket_source, $ticket_management_allowed, true)) {
        $raw_ticket_source = 'tiket_manager';
    }
    $ticket_management_source = $raw_ticket_source;
	// mencari data pemilik akun utama / superadmin
    if ($STATUS != 'ASSISTANT') {
        $sql_hasil_data_owner = "SELECT * FROM `user` WHERE `id`='$id' ";
        $query_data_owner = mysqli_query($conn, $sql_hasil_data_owner);
        while ($data_owner = mysqli_fetch_array($query_data_owner)) {
			$ceknama = $data_owner['USERNAME'];
			$current_user_id = $data_owner['id'];
			$USER_ID = $data_owner['id'];
			$saldo = $data_owner['saldo'];
			$expired_at = $data_owner['expired_at'];
			$username = $data_owner['USERNAME'];
			$password = $data_owner['PASWORD'];
			$nowa = $data_owner['NOWA'];
			$AKSES = $data_owner['STATUS'];
			$domain2 = $data_owner['domain'];
			$inisial = $data_owner['inisial'];
			$username = $data_owner['USERNAME'];
            $saldo = $data_owner['saldo'];
        	}
    	} 

		// mencari data asistant berdasarkan akun superadmin
	if ($STATUS == 'ASSISTANT') {

			$asistant_name = $hasil_cek_role['USERNAME'];
			 $password = $hasil_cek_role['PASWORD'];
		    $domain2 = $hasil_cek_role['domain'];
            $nowa = $hasil_cek_role['NOWA'];

			// Role reseller/mitra ISP: pengaturan disimpan di baris akun assistant itu sendiri.
			// Mitra ISP memakai rules yang persis sama dengan reseller (filter harga paket + beban bandwidth).
			$assistant_role = $hasil_cek_role['assistant_role'] ?? 'assistant';
			$is_reseller = in_array($assistant_role, ['reseller', 'mitra_isp'], true);
			if ($is_reseller) {
				$reseller_id = (int)$id;
				$reseller_settings = reseller_get_settings($conn, $reseller_id);
				$reseller_price_filter_enabled = (bool)$reseller_settings['price_filter_enabled'];
				// $reseller_bandwidth_burden dihitung final di bawah, SETELAH $area_list
				// terbentuk (baris ~198) -- skema 'omset_percent' butuh angka omset yang
				// scoped ke AREA milik reseller ini.
			}

			$id_grup_assistant = $hasil_cek_role['grup'];
			if (isset($id_grup_assistant)) {
					$cek_pemilik_utama = "SELECT * FROM `user` WHERE `id`='$id_grup_assistant' ";
					$query_pemilik_utama = mysqli_query($conn, $cek_pemilik_utama);

					while ($pemilik_utama_akun = mysqli_fetch_array($query_pemilik_utama)) {
						$ceknama = $pemilik_utama_akun['USERNAME'];
						$current_user_id = $pemilik_utama_akun['id'];
						$USER_ID = $pemilik_utama_akun['id'];
						$saldo = $pemilik_utama_akun['saldo'];
						$expired_at = $pemilik_utama_akun['expired_at'];
						$username = $pemilik_utama_akun['USERNAME'];
						$saldo = $pemilik_utama_akun['saldo'];
						
						$AKSES = 'ASSISTANT';
                        $owner_ticket_source = strtolower(trim((string)($pemilik_utama_akun['ticket_management_source'] ?? 'tiket_manager')));
                        if (!in_array($owner_ticket_source, $ticket_management_allowed, true)) {
                            $owner_ticket_source = 'tiket_manager';
                        }
                        $ticket_management_source = $owner_ticket_source;
						
						$inisial = $pemilik_utama_akun['inisial'];
						$username = $pemilik_utama_akun['USERNAME'];
					}
			}

			// Dekode hak akses/menu dari JSON
			$akses_json = $hasil_cek_role['akses'];
			$akses_menu = json_decode($akses_json, true);

			// --- Bangun daftar area dari ID server ---
			$area_list_JSON = $hasil_cek_role['server'];
			
			$arealist = [];
			if ($area_list_JSON) {
				$area_ids = json_decode($area_list_JSON, true);
				if (is_array($area_ids) && count($area_ids) > 0) {
					$id_in = implode(",", array_map('intval', $area_ids));
					$sql_area = "SELECT id, AREA FROM server WHERE id IN ($id_in)";
					$query_area = mysqli_query($conn, $sql_area);
					while ($row_area = mysqli_fetch_assoc($query_area)) {
						if (!empty($row_area['AREA'])) {
							$arealist[] = $row_area['AREA'];
						}
					}
				}
			}
			// Buang duplikat & sort area
			$arealist = array_unique(array_filter($arealist));
			sort($arealist);
			if (!empty($arealist)) {
			   $area_list = "'" . implode("','", array_map('addslashes', $arealist)) . "'"; // dipakai di query WHERE IN
			} else {
			   // Assistant/reseller tanpa satupun server/area di-assign -- $area_list
			   // HARUS tetap terdefinisi (string yang tak mungkin match AREA manapun),
			   // supaya query "AREA IN ($area_list)" di banyak file lain tidak jadi
			   // "AREA IN ()" (syntax error SQL) begitu variabel ini undefined.
			   $area_list = "''";
			}

			// Beban reseller final: skema 'bandwidth' (nominal tetap) tidak butuh
			// omset, skema 'omset_percent' butuh omset kotor BULAN BERJALAN yang
			// di-scope ke server/AREA milik reseller ini (pola sama seperti dashboard.php).
			if ($is_reseller) {
				$reseller_omset_bulan_ini = 0.0;
				if (($reseller_settings['cost_scheme'] ?? 'bandwidth') === 'omset_percent') {
					$reseller_pemilik_ids = [];
					$queryServerReseller = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
					if ($queryServerReseller) {
						while ($rowServerReseller = mysqli_fetch_assoc($queryServerReseller)) {
							$reseller_pemilik_ids[] = "'" . $rowServerReseller['PEMILIK'] . "'";
						}
					}
					$reseller_pemilik_list = count($reseller_pemilik_ids) > 0 ? implode(",", $reseller_pemilik_ids) : "''";

					$reseller_tanggal_bayar_filter_sql = "COALESCE(
						DATE(t.TANGGALBAYAR),
						STR_TO_DATE(t.TANGGALBAYAR, '%Y-%m-%d'),
						STR_TO_DATE(
							CONCAT(
								TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
									SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),
									'Januari', '01'
								), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12'))
							),
							'%d %m %Y'
						)
					)";
					$reseller_awal_bulan_ini = date('Y-m-01');
					$reseller_akhir_bulan_ini = date('Y-m-t');
					$sql_omset_reseller = "SELECT SUM(t.HARGA) as total FROM transaksi t
						INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
						WHERE t.STATUS = 'BERHASIL'
						  AND $reseller_tanggal_bayar_filter_sql >= '$reseller_awal_bulan_ini'
						  AND $reseller_tanggal_bayar_filter_sql <= '$reseller_akhir_bulan_ini'
						  AND p.PEMILIK IN ($reseller_pemilik_list) AND p.AREA IN ($area_list)";
					$result_omset_reseller = mysqli_query($conn, $sql_omset_reseller);
					if ($result_omset_reseller) {
						$row_omset_reseller = mysqli_fetch_assoc($result_omset_reseller);
						$reseller_omset_bulan_ini = (float)($row_omset_reseller['total'] ?? 0);
					}
				}
				$reseller_bandwidth_burden = reseller_cost_burden($reseller_settings, $reseller_omset_bulan_ini);
			}

			// Key file logo billing ("profile-{key}.png" di dokumen/logo/): assistant
			// yang sudah punya logo sendiri pakai username-nya sendiri, selain itu
			// (assistant tanpa logo sendiri, atau owner) pakai username owner --
			// sama seperti pola $ui_settings_username di header.php.
			$logo_owner_key = ($AKSES === 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;

			// --- Bangun daftar bot WA yang di-assign khusus ke assistant ini ---
			// Kolom assigned_bots ada di BARIS ASSISTANT itu sendiri ($hasil_cek_role),
			// bukan baris owner -- pola sama persis dgn kolom `server` di atas.
			// Dipakai bareng $asistant_name (utk bot yang dibuat sendiri oleh
			// assistant ini) oleh botAccessWhereClause()/botAccessCanManage()
			// di notifbot/bot_access_helper.php.
			$assigned_bots_JSON = $hasil_cek_role['assigned_bots'] ?? null;
			if ($assigned_bots_JSON) {
				$assigned_bot_ids_decoded = json_decode($assigned_bots_JSON, true);
				if (is_array($assigned_bot_ids_decoded)) {
					$assigned_bot_ids = array_values(array_unique(array_map('intval', $assigned_bot_ids_decoded)));
				}
			}

			// --- Sama persis di atas, tapi utk bot Telegram (tabel bottelegram) ---
			$assigned_telegram_bots_JSON = $hasil_cek_role['assigned_telegram_bots'] ?? null;
			if ($assigned_telegram_bots_JSON) {
				$assigned_telegram_bot_ids_decoded = json_decode($assigned_telegram_bots_JSON, true);
				if (is_array($assigned_telegram_bot_ids_decoded)) {
					$assigned_telegram_bot_ids = array_values(array_unique(array_map('intval', $assigned_telegram_bot_ids_decoded)));
				}
			}

			// --- Sama persis di atas, tapi utk AREA yang di-assign khusus ke
			// assistant ini utk menu Live Chat (livechat_access_helper.php) ---
			$assigned_livechat_areas_JSON = $hasil_cek_role['assigned_livechat_areas'] ?? null;
			if ($assigned_livechat_areas_JSON) {
				$assigned_livechat_areas_decoded = json_decode($assigned_livechat_areas_JSON, true);
				if (is_array($assigned_livechat_areas_decoded)) {
					$assigned_livechat_areas = array_values(array_unique(array_filter(array_map('strval', $assigned_livechat_areas_decoded))));
				}
			}

    }

    

// jika belum ada inisial buat otomatis dari nama 
    if ($inisial == '') {
        // Buat inisial 3 huruf dari username (maks 3 huruf)
        $words = explode(' ', strtoupper($username));
        $initials = "";
        foreach ($words as $word) {
            $initials .= substr($word, 0, 1);
            if (strlen($initials) >= 3) break;
        }
        // Jika kurang dari 3 huruf, tambahkan dari huruf berikutnya
        if (strlen($initials) < 3) {
            $initials .= strtoupper(substr(str_replace(" ", "", $username), strlen($initials), 3 - strlen($initials)));
        }
        $inisial = substr($initials, 0, 3);
    }





}

// Hak Akses Paket (Katalog): daftar nama paket yang di-hide dari
// dropdown/katalog untuk akun ASSISTANT ini (by id akun assistant itu
// SENDIRI -- $id, BUKAN $current_user_id yang menunjuk ke owner). Kosong []
// untuk ADMIN/owner (tidak pernah difilter). Dihitung SEKALI di sini supaya
// tersedia global di hampir semua halaman (require cek-sesi.php/header.php),
// dipakai lewat paketVisibilityFilterRows()/paketVisibilityIsHidden().
$assistant_hidden_paket_broadband = [];
$assistant_hidden_paket_hotspot = [];
$assistant_hidden_paket_corporate = [];
if (($AKSES ?? '') === 'ASSISTANT' && !empty($id)) {
    $assistant_hidden_paket_broadband = paketVisibilityGetHiddenNames($conn, $id, 'broadband');
    $assistant_hidden_paket_hotspot = paketVisibilityGetHiddenNames($conn, $id, 'hotspot');
    $assistant_hidden_paket_corporate = paketVisibilityGetHiddenNames($conn, $id, 'corporate');
}

if (!in_array($ticket_management_source, $ticket_management_allowed, true)) {
    $ticket_management_source = 'tiket_manager';
}








// Nama layanan yang digunakan di halaman ini
$produk = 'VPNQ';





// Jika bukan admin, cek masa berlaku (expired)
if ($AKSES != 'ADMIN') {
    $query = "SELECT * FROM user WHERE USERNAME = '" . mysqli_real_escape_string($conn, $ceknama) . "'";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $expired_at = $user['expired_at'];
        $created_at = $user['created_at'];
        $today = date('Y-m-d');

        // Jika tidak ada expired atau sudah lewat tanggal sekarang, tampilkan notifikasi pembayaran
        if ($expired_at == null || !$expired_at || date('Y-m-d', strtotime($expired_at)) < $today) {
            ?>
            <!-- Bootstrap Bundle with Popper -->
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

            <!-- Bootstrap 5 Notification Card -->
            <div class="container mt-5">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>PEMBERITAHUAN PEMBAYARAN</strong>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Layanan</span>
                                <span class="fw-bold">Sewa aplikasi CRM-billing</span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Periode</span>
                                <span class="fw-bold">
                                    <?= date('j F', strtotime($created_at)) ?> -
                                    <?= $expired_at ? date('j F Y', strtotime($expired_at)) : 'Belum ditentukan' ?>
                                </span>
                            </div>
                        </div>

                        <div class="bg-light p-3 rounded-3 text-center mb-4">
                            <div class="text-muted small">Total Pembayaran</div>
                            <div class="fs-3 fw-bold text-primary">Rp 250.000</div>
                        </div>

                        <div class="alert alert-warning d-flex align-items-center mb-4">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div>Jatuh tempo: <?= $expired_at ? date('j F Y', strtotime($expired_at)) : 'Belum ditentukan' ?></div>
                        </div>

                        <div class="d-grid gap-2 d-md-flex">
                            <a href="topup.php" class="btn btn-primary me-md-2 px-4">
                                <i class="fas fa-credit-card me-2"></i>Bayar Sekarang
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            exit; // Hentikan eksekusi agar user melakukan pembayaran terlebih dahulu
        }
    }
}
// Tutup session setelah semua pengubahan selesai
session_write_close();
?>
