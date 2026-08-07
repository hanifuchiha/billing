<?php
/**
 * getdata/sync_pelanggan_api.php
 * ----------------------------------------------------------------------
 * Backend untuk modal "Sinkron dari API" di tables.php / api_hanif.php.
 *
 * action=preview
 *   - Ambil data dari API billing (sama seperti api_hanif.php)
 *   - Bandingkan dengan tabel `pelanggan` (by IDPEL) -> pisahkan mana yg BARU
 *   - Untuk tiap IDPEL baru, cek ke MikroTik (RouterOS API) apakah PPPoE
 *     secret-nya SUDAH ADA di server yang dipilih. Info ini dikirim ke
 *     frontend supaya bisa menentukan perlu generate password atau tidak.
 *
 * action=execute
 *   - Terima daftar IDPEL terpilih (idpel_list) + password per-IDPEL
 *     (idpel_password, format JSON: {IDPEL: password}).
 *   - Insert ke tabel `pelanggan`.
 *   - Jika PPPoE secret BELUM ada di MikroTik -> buatkan secretnya.
 *     Jika SUDAH ada -> jangan dibuat ulang, cukup disimpan ke DB.
 *   - Insert seluruh riwayat transaksi (transactions[]) milik pelanggan
 *     tersebut ke tabel `transaksi` dengan STATUS = 'BERHASIL'.
 * ----------------------------------------------------------------------
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // jangan tampilkan warning ke output JSON

require_once __DIR__ . '/../cek-sesi.php';
require_once __DIR__ . '/../routeros_api.class.php';

header('Content-Type: application/json; charset=utf-8');

// ----------------------------------------------------------------------
// Guard akses: hanya ADMIN yang boleh menjalankan sinkron ini.
// ----------------------------------------------------------------------
if (!isset($AKSES) || $AKSES !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya ADMIN yang dapat melakukan sinkron.']);
    exit;
}

$action = $_POST['action'] ?? '';

// ----------------------------------------------------------------------
// Helper umum
// ----------------------------------------------------------------------

/**
 * Ambil seluruh data pelanggan dari API billing eksternal.
 * Mengembalikan array of customers (raw dari API) atau melempar Exception.
 */
function fetchPelangganApi(): array
{
    $apiUrl = "https://billing.broadbandairlink.com/web/keuangan/sistem-manajemen-keuangan/index.php/admin/site/pelanggan_api";
    $apiKey = "9fa120bc7d157225c58238c91051c7f2baf37c5338d75f8c9c260082babe2de8";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            "X-API-Key: {$apiKey}",
            "Accept: application/json"
        ]
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        throw new Exception("CURL ERROR: " . $error);
    }
    if ($httpcode != 200) {
        throw new Exception("HTTP CODE: " . $httpcode);
    }

    $data = json_decode($response, true);
    if (!$data) {
        throw new Exception("JSON tidak valid dari API.");
    }

    $customers = [];
    if (isset($data['customers']) && is_array($data['customers'])) {
        $customers = $data['customers'];
    } elseif (isset($data['rows']) && is_array($data['rows'])) {
        $customers = $data['rows'];
    }

    return $customers;
}

/**
 * Sama seperti safeNumber() di api_hanif.php - aman convert ke float.
 */
function safeNumberSync($val): float
{
    if (is_numeric($val)) {
        return (float) $val;
    }
    if (is_string($val)) {
        $clean = preg_replace('/[^0-9.,-]/', '', $val);
        $clean = str_replace(['.', ','], ['', '.'], $clean);
        if (is_numeric($clean)) {
            return (float) $clean;
        }
    }
    return 0.0;
}

// Mode Auth yang valid untuk kolom `pelanggan`.`MODE` (sama seperti opsi
// "Auth Mode" di addcustomerform.php).
const SYNC_ALLOWED_AUTH_MODES = ['API MODE', 'RADIUS MODE', 'MULTI MODE'];

/**
 * Cek apakah field `MODE` milik customer di API billing eksternal sudah
 * berisi Mode Auth yang valid ('API MODE' / 'RADIUS MODE' / 'MULTI MODE').
 * Dipakai supaya preview & default eksekusi mengikuti Mode Auth yang
 * sudah ditentukan di sistem billing eksternal (kalau ada), bukan selalu
 * MULTI MODE. Field ini sering kosong / '-' di banyak pelanggan lama --
 * dalam kasus itu return null (caller fallback ke MULTI MODE).
 */
function resolveApiAuthMode($rawMode): ?string
{
    $normalized = strtoupper(trim((string)$rawMode));
    foreach (SYNC_ALLOWED_AUTH_MODES as $mode) {
        if ($normalized === $mode) {
            return $mode;
        }
    }
    return null;
}

/**
 * Normalisasi nomor whatsapp ke format 62xxxxxxxxxx,
 * mengikuti pola yang sudah dipakai di proses/addcustomer.php.
 */
function normalizeWa(string $whatsapp): string
{
    $whatsapp = trim($whatsapp);
    $whatsappedit = $whatsapp;

    if (!preg_match('/[^+0-9]/', $whatsapp)) {
        if (substr($whatsapp, 0, 2) == '62') {
            $whatsappedit = $whatsapp;
        } elseif (substr($whatsapp, 0, 3) == '+62') {
            $whatsappedit = '62' . substr($whatsapp, 1);
        } elseif (substr($whatsapp, 0, 1) == '0') {
            $whatsappedit = '62' . substr($whatsapp, 1);
        } elseif (substr($whatsapp, 0, 1) == '-') {
            $whatsappedit = substr($whatsapp, 1);
        } elseif (substr($whatsapp, 0, 1) == ' ') {
            $whatsappedit = substr($whatsapp, 1);
        }
    }

    return $whatsappedit;
}

/**
 * Konversi nama bulan periode dari format API (jika ada) atau dari tanggal
 * transaksi ke format "NamaBulan Tahun" (Indonesia), dipakai untuk kolom
 * PENGUNAAN di tabel transaksi.
 */
function periodeIndonesia(string $tanggal): string
{
    $bulanIndonesia = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $ts = strtotime($tanggal);
    if ($ts === false) {
        $ts = time();
    }
    return $bulanIndonesia[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/**
 * Ambil kredensial server (PEMILIK) dari tabel `server` berdasarkan
 * PEMILIK + AREA. Dipakai untuk koneksi RouterOS API.
 */
function getServerCreds($conn, string $pemilik, string $area): ?array
{
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc    = mysqli_real_escape_string($conn, $area);

    $q = mysqli_query(
        $conn,
        "SELECT IP, PASSWORD, TEMPO FROM `server` WHERE `PEMILIK` = '$pemilikEsc' AND `AREA` = '$areaEsc' LIMIT 1"
    );
    if ($q && mysqli_num_rows($q) > 0) {
        $row = mysqli_fetch_assoc($q);
        return [
            'ip'       => $row['IP'],
            'user'     => $pemilik, // pola di addcustomer.php: $user = $data['PEMILIK']
            'password' => $row['PASSWORD'],
            'tempo'    => $row['TEMPO'] ?? '',
        ];
    }
    return null;
}

/**
 * Cek apakah PPPoE secret dengan nama $idpel sudah ada di MikroTik.
 * Return true jika ADA, false jika TIDAK ADA / gagal konek (anggap belum ada,
 * tapi beri tanda error supaya operator tahu).
 */
function checkSecretExists(RouterosAPI $api, string $idpel): bool
{
    $existing = $api->comm("/ppp/secret/print", ["?name" => $idpel]);
    return !empty($existing);
}

// ----------------------------------------------------------------------
// Helper RADIUS (FreeRADIUS) - mengikuti pola yang sama dengan
// proses/addcustomer.php, supaya pelanggan hasil sinkron dengan
// authmode MULTI MODE benar-benar punya akun di MikroTik DAN RADIUS,
// bukan cuma label teks "MULTI MODE" di kolom MODE tabel pelanggan.
// ----------------------------------------------------------------------
const RADIUS_USERS_FILE = "/etc/freeradius/3.0/users";

/**
 * Cek apakah username sudah terdaftar di file users FreeRADIUS.
 */
function radiusUserExists(string $idpel): bool
{
    if (!file_exists(RADIUS_USERS_FILE)) {
        return false;
    }
    $fileContent = @file_get_contents(RADIUS_USERS_FILE);
    if ($fileContent === false) {
        return false;
    }
    return strpos($fileContent, "$idpel Cleartext-Password") !== false;
}

/**
 * Tambahkan satu entry user baru ke file users FreeRADIUS (append saja,
 * TIDAK restart FreeRADIUS di sini -- restart dilakukan sekali di akhir
 * batch lewat radiusRestartIfNeeded(), supaya sinkron banyak pelanggan
 * tidak men-restart service berkali-kali).
 */
function radiusAppendUser(string $idpel, string $password, string $paket): bool
{
    $entry  = "$idpel Cleartext-Password := \"$password\"\n";
    $entry .= "\tMikrotik-Group := \"$paket\"\n\n";
    $written = @file_put_contents(RADIUS_USERS_FILE, $entry, FILE_APPEND);
    return $written !== false;
}

/**
 * Ambil PID proses freeradius yang sedang berjalan, dipakai untuk restart
 * (identik dengan getFreeradiusPID() di proses/addcustomer.php).
 */
function getFreeradiusPIDSync(): int
{
    $pid = trim((string) @shell_exec("pidof freeradius"));
    if ($pid !== '') {
        return (int) $pid;
    }
    $output = (string) @shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int) $m[1];
    }
    return 0;
}

/**
 * Restart FreeRADIUS SATU KALI saja (dipanggil di akhir batch sinkron,
 * bukan per-pelanggan), identik dengan restartFreeradius() di
 * proses/addcustomer.php. Hanya dijalankan jika memang ada entry RADIUS
 * baru yang ditambahkan selama batch ini ($anyRadiusUserAdded = true).
 */
function radiusRestartIfNeeded(bool $anyRadiusUserAdded): void
{
    if (!$anyRadiusUserAdded) {
        return; // tidak ada perubahan -> tidak perlu restart, hindari downtime sia-sia
    }

    $debugFile = "/var/log/freeradius/debug-radius-web.log";

    $pid = getFreeradiusPIDSync();
    if ($pid > 0) {
        @shell_exec('sudo systemctl stop freeradius');
        @shell_exec("sudo kill -9 $pid");
    }

    if (file_exists($debugFile)) {
        @shell_exec("sudo rm -f $debugFile");
    }
    @shell_exec("sudo touch $debugFile");
    @shell_exec("sudo chmod 666 $debugFile");
    @shell_exec("sudo freeradius -X > $debugFile 2>&1 &");
}

/**
 * `payment_history` adalah array terpisah dari `transactions`, tapi
 * keduanya merepresentasikan transaksi yang sama dari sisi billing.
 * Penghubung resminya adalah field `transaksi_id`:
 *   - payment_history[].transaksi_id  <->  transactions[].transaksi_id
 * (dikonfirmasi dari contoh struktur API: keduanya membawa nilai yang
 * sama, mis. 100, untuk transaksi yang sama).
 *
 * `payment_history` membawa `bukti_url` (link bukti pembayaran asli di
 * server billing), sedangkan `transactions` membawa detail jenis/
 * kategori/keterangan yang dipakai saat insert ke tabel `transaksi`.
 *
 * Fungsi ini membangun index payment_history berdasarkan `transaksi_id`,
 * supaya saat loop `transactions` kita bisa langsung mengambil
 * `bukti_url` yang benar-benar berasal dari pasangan transaksi yang sama
 * (bukan tebakan berdasarkan tanggal/nominal).
 */
function buildPaymentHistoryIndex(array $paymentHistory): array
{
    $index = [];
    foreach ($paymentHistory as $p) {
        $transaksiId = $p['transaksi_id'] ?? null;
        $buktiUrl = (string)($p['bukti_url'] ?? '');

        if ($transaksiId === null || $buktiUrl === '') {
            continue;
        }

        $key = (string) $transaksiId;
        // Jika ada lebih dari satu payment_history dengan transaksi_id sama
        // (seharusnya tidak terjadi, tapi dijaga untuk keamanan), simpan
        // sebagai array supaya tidak saling menimpa.
        if (!isset($index[$key])) {
            $index[$key] = [];
        }
        $index[$key][] = $buktiUrl;
    }
    return $index;
}

/**
 * Ambil bukti_url yang cocok untuk satu item transaksi (dari transactions[])
 * berdasarkan transaksi_id miliknya, melalui index payment_history yang
 * sudah dibangun (buildPaymentHistoryIndex). Mengkonsumsi (shift) entri
 * yang dipakai supaya tidak terpakai dua kali jika ada transaksi_id ganda.
 */
function matchBuktiUrlByTransaksiId(array &$paymentHistoryIndex, $transaksiId): string
{
    if ($transaksiId === null) {
        return '';
    }
    $key = (string) $transaksiId;
    if (!empty($paymentHistoryIndex[$key])) {
        return array_shift($paymentHistoryIndex[$key]);
    }
    return '';
}

/**
 * Terapkan filter Area / Paket / RT / RW / Kelurahan / Kecamatan / Search
 * PERSIS seperti yang dilakukan DataTables di tabel utama api_hanif.php,
 * supaya data yang disinkron selalu konsisten dengan apa yang sedang
 * ditampilkan/difilter di tabel.
 *
 * - Area / Paket / RT / RW / Kelurahan / Kecamatan : exact match terhadap
 *   kolom masing-masing (sama seperti dropdown filter-nya, yang mengirim
 *   nilai utuh dari opsi <option>).
 * - Search: substring match (case-insensitive) terhadap kolom-kolom yang
 *           tampil di tabel: IDPEL, NAMA, NOWA, PAKET, AREA, RT, RW,
 *           Kelurahan, Kecamatan, ALAMAT, TEMPO (mengikuti perilaku global
 *           search DataTables yang men-scan semua kolom terlihat).
 */
function applyTableFilters(
    array $customers,
    string $filterArea,
    string $filterPaket,
    string $filterSearch,
    string $filterRt = '',
    string $filterRw = '',
    string $filterKelurahan = '',
    string $filterKecamatan = ''
): array {
    if (
        $filterArea === '' && $filterPaket === '' && $filterSearch === '' &&
        $filterRt === '' && $filterRw === '' && $filterKelurahan === '' && $filterKecamatan === ''
    ) {
        return $customers; // tidak ada filter aktif -> kembalikan semua data
    }

    $searchLower = mb_strtolower($filterSearch, 'UTF-8');

    return array_values(array_filter($customers, function ($c) use (
        $filterArea, $filterPaket, $searchLower,
        $filterRt, $filterRw, $filterKelurahan, $filterKecamatan
    ) {
        if ($filterArea !== '' && (string)($c['AREA'] ?? '') !== $filterArea) {
            return false;
        }
        if ($filterPaket !== '' && (string)($c['PAKET'] ?? '') !== $filterPaket) {
            return false;
        }
        if ($filterRt !== '' && (string)($c['rt'] ?? '') !== $filterRt) {
            return false;
        }
        if ($filterRw !== '' && (string)($c['rw'] ?? '') !== $filterRw) {
            return false;
        }
        if ($filterKelurahan !== '' && (string)($c['kelurahan'] ?? '') !== $filterKelurahan) {
            return false;
        }
        if ($filterKecamatan !== '' && (string)($c['kecamatan'] ?? '') !== $filterKecamatan) {
            return false;
        }
        if ($searchLower !== '') {
            $alamatGabung = trim((string)($c['ALAMAT'] ?? '') . ' No ' . (string)($c['no_rumah'] ?? ''));
            $haystackParts = [
                (string)($c['IDPEL'] ?? ''),
                (string)($c['NAMA'] ?? ''),
                (string)($c['NOWA'] ?? ''),
                (string)($c['PAKET'] ?? ''),
                (string)($c['AREA'] ?? ''),
                (string)($c['rt'] ?? ''),
                (string)($c['rw'] ?? ''),
                (string)($c['kelurahan'] ?? ''),
                (string)($c['kecamatan'] ?? ''),
                $alamatGabung,
                (string)($c['TEMPO'] ?? ''),
            ];
            $haystack = mb_strtolower(implode(' ', $haystackParts), 'UTF-8');
            if (mb_strpos($haystack, $searchLower) === false) {
                return false;
            }
        }
        return true;
    }));
}

// ----------------------------------------------------------------------
// ACTION: preview
// ----------------------------------------------------------------------
if ($action === 'preview') {
    try {
        $customers = fetchPelangganApi();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data API: ' . $e->getMessage()]);
        exit;
    }

    $totalApi = count($customers);

    // Filter Area/Paket/RT/RW/Kelurahan/Kecamatan/Search yang sedang aktif
    // di tabel utama, dikirim dari frontend supaya hasil sinkron konsisten
    // dengan apa yang sedang ditampilkan/difilter oleh admin saat ini.
    $filterArea      = trim($_POST['filter_area'] ?? '');
    $filterPaket     = trim($_POST['filter_paket'] ?? '');
    $filterSearch    = trim($_POST['filter_search'] ?? '');
    $filterRt        = trim($_POST['filter_rt'] ?? '');
    $filterRw        = trim($_POST['filter_rw'] ?? '');
    $filterKelurahan = trim($_POST['filter_kelurahan'] ?? '');
    $filterKecamatan = trim($_POST['filter_kecamatan'] ?? '');

    $customers = applyTableFilters(
        $customers,
        $filterArea,
        $filterPaket,
        $filterSearch,
        $filterRt,
        $filterRw,
        $filterKelurahan,
        $filterKecamatan
    );
    $totalApiTerfilter = count($customers);

    // Ambil semua IDPEL yang sudah ada di database (sekali query, lebih efisien
    // daripada query per-row).
    $existingIdpel = [];
    $resExisting = mysqli_query($conn, "SELECT IDPEL FROM `pelanggan`");
    if ($resExisting) {
        while ($rowEx = mysqli_fetch_assoc($resExisting)) {
            $existingIdpel[trim((string)$rowEx['IDPEL'])] = true;
        }
    }

    // Server & area yang dipilih di Step 1 (dipakai untuk cek MikroTik).
    $selectedServer = trim($_POST['pemilik'] ?? '');
    $selectedArea   = trim($_POST['area'] ?? '');

    // ODP fallback dari Step 1 (dipakai untuk preview "odp_final" supaya
    // admin tahu lebih dulu ODP apa yang sebenarnya akan dipakai saat
    // eksekusi -- sama seperti logika di action execute).
    $odpFallbackPreview = trim($_POST['odp'] ?? '');

    // Siapkan koneksi RouterOS sekali saja (reuse), supaya tidak connect
    // berkali-kali untuk setiap pelanggan baru.
    $routerApi = null;
    $routerConnectError = '';
    if ($selectedServer !== '' && $selectedArea !== '') {
        $creds = getServerCreds($conn, $selectedServer, $selectedArea);
        if ($creds) {
            try {
                $routerApi = new RouterosAPI();
                $connected = @$routerApi->connect($creds['ip'], $creds['user'], $creds['password']);
                if (!$connected) {
                    $routerApi = null;
                    $routerConnectError = 'Gagal konek ke MikroTik (' . $creds['ip'] . ').';
                }
            } catch (Throwable $t) {
                $routerApi = null;
                $routerConnectError = 'Error koneksi MikroTik: ' . $t->getMessage();
            }
        } else {
            $routerConnectError = 'Data server tidak ditemukan untuk PEMILIK/AREA terpilih.';
        }
    }

    $newCustomers = [];
    $totalSudahAda = 0;

    foreach ($customers as $c) {
        $idpel = trim((string)($c['IDPEL'] ?? ''));
        if ($idpel === '') {
            continue; // skip data tanpa IDPEL, tidak bisa diproses
        }

        if (isset($existingIdpel[$idpel])) {
            $totalSudahAda++;
            continue;
        }

        // Hitung ringkasan transaksi pemasukan untuk preview.
        $transaksiList = $c['transactions'] ?? [];
        if (!is_array($transaksiList)) {
            $transaksiList = [];
        }
        $totalPemasukan = 0;
        $totalNominalPemasukan = 0.0;
        foreach ($transaksiList as $t) {
            $jenis = $t['transaksi_jenis'] ?? '';
            if ($jenis === 'Pemasukan') {
                $totalPemasukan++;
                $totalNominalPemasukan += safeNumberSync($t['transaksi_nominal'] ?? 0);
            }
        }

        // Cek status secret PPPoE di MikroTik (jika koneksi tersedia).
        $secretStatus = 'unknown'; // unknown | exists | not_exists
        if ($routerApi !== null) {
            try {
                $exists = checkSecretExists($routerApi, $idpel);
                $secretStatus = $exists ? 'exists' : 'not_exists';
            } catch (Throwable $t) {
                $secretStatus = 'unknown';
            }
        }

        // Cek status user di RADIUS (file /etc/freeradius/3.0/users),
        // berlaku untuk authmode MULTI MODE supaya admin tahu sebelum
        // eksekusi apakah RADIUS perlu dibuatkan entry baru atau tidak.
        $radiusStatus = radiusUserExists($idpel) ? 'exists' : 'not_exists';

        // TANGGALPASANG & sales langsung diambil dari API (tidak diisi manual
        // lagi di Step 1, sesuai field yang sudah tersedia per-customer).
        $tanggalPasangApi = trim((string)($c['TANGGALPASANG'] ?? ''));
        $tglPasangParsed = $tanggalPasangApi !== '' ? strtotime($tanggalPasangApi) : false;
        $tanggalPasangNormalized = $tglPasangParsed !== false ? date('Y-m-d', $tglPasangParsed) : date('Y-m-d');

        $salesApi = trim((string)($c['sales'] ?? ''));

        // ODP: jika API mengirim ODP tidak kosong, itu yang akan dipakai
        // (apa adanya, tanpa validasi ke tabel odp). Jika kosong, fallback
        // ke ODP yang dipilih manual di Step 1. Ditampilkan di preview
        // supaya admin tahu lebih dulu ODP final yang akan disimpan.
        $odpApiPreview = trim((string)($c['ODP'] ?? ''));
        $odpFinalPreview = $odpApiPreview !== '' ? $odpApiPreview : $odpFallbackPreview;

        // Mode Auth dari API billing eksternal (field `MODE`), kalau sudah
        // diisi dengan nilai valid ('API MODE'/'RADIUS MODE'/'MULTI MODE')
        // -- dipakai sebagai pilihan default kolom Mode Auth saat preview
        // pertama kali dimuat, supaya admin tidak perlu ubah manual kalau
        // datanya sudah benar di sistem billing eksternal. Null kalau field
        // kosong / '-' / nilai tidak dikenal (fallback ke MULTI MODE di frontend).
        $authModeApiPreview = resolveApiAuthMode($c['MODE'] ?? '');

        $newCustomers[] = [
            'IDPEL'                        => $idpel,
            'NAMA'                         => $c['NAMA'] ?? '',
            'NOWA'                         => $c['NOWA'] ?? '',
            'PAKET'                        => $c['PAKET'] ?? '',
            'HARGA'                        => safeNumberSync($c['HARGA'] ?? 0),
            'AREA'                         => $c['AREA'] ?? '',
            'rt'                           => $c['rt'] ?? '',
            'rw'                           => $c['rw'] ?? '',
            'kelurahan'                    => $c['kelurahan'] ?? '',
            'kecamatan'                    => $c['kecamatan'] ?? '',
            'ALAMAT'                       => $c['ALAMAT'] ?? '',
            'no_rumah'                     => $c['no_rumah'] ?? '',
            'TEMPO'                        => $c['TEMPO'] ?? '',
            'TANGGALPASANG'                => $tanggalPasangNormalized,
            'sales'                        => $salesApi,
            'ODP'                          => $odpApiPreview,
            'odp_final'                    => $odpFinalPreview,
            'odp_source'                   => $odpApiPreview !== '' ? 'api' : 'manual',
            'total_transaksi_pemasukan'    => $totalPemasukan,
            'total_nominal_pemasukan'      => $totalNominalPemasukan,
            'secret_status'                => $secretStatus,
            'radius_status'                => $radiusStatus,
            'authmode_api'                 => $authModeApiPreview,
        ];
    }

    if ($routerApi !== null) {
        try { $routerApi->disconnect(); } catch (Throwable $t) { /* ignore */ }
    }

    echo json_encode([
        'success'              => true,
        'total_api'            => $totalApi,
        'total_api_terfilter'  => $totalApiTerfilter,
        'filter_applied'       => [
            'area'      => $filterArea,
            'paket'     => $filterPaket,
            'search'    => $filterSearch,
            'rt'        => $filterRt,
            'rw'        => $filterRw,
            'kelurahan' => $filterKelurahan,
            'kecamatan' => $filterKecamatan,
        ],
        'total_sudah_ada'      => $totalSudahAda,
        'total_baru'           => count($newCustomers),
        'data'                 => $newCustomers,
        'router_connect_error' => $routerConnectError,
    ]);
    exit;
}

// ----------------------------------------------------------------------
// ACTION: execute
// ----------------------------------------------------------------------
if ($action === 'execute') {
    $pemilik       = trim($_POST['pemilik'] ?? '');
    $area          = trim($_POST['area'] ?? '');
    $brand         = trim($_POST['brand'] ?? '');
    $odpFallback   = trim($_POST['odp'] ?? '');
    $salesFallback = trim($_POST['sales'] ?? '-'); // fallback jika API tidak mengirim sales utk customer ybs
    $tipeBayar     = trim($_POST['tipe_bayar'] ?? 'pascabayar');
    $tipeTempo     = trim($_POST['tipe_tempo'] ?? 'mengikuti_tanggal_bayar');
    $idpelListRaw    = $_POST['idpel_list'] ?? '[]';
    $idpelPassRaw     = $_POST['idpel_password'] ?? '{}'; // {IDPEL: password}
    $idpelAuthModeRaw = $_POST['idpel_authmode'] ?? '{}'; // {IDPEL: 'API MODE'|'RADIUS MODE'|'MULTI MODE'}

    $idpelList = json_decode($idpelListRaw, true);
    if (!is_array($idpelList) || count($idpelList) === 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada IDPEL yang dipilih untuk disinkron.']);
        exit;
    }

    $idpelPasswordMap = json_decode($idpelPassRaw, true);
    if (!is_array($idpelPasswordMap)) {
        $idpelPasswordMap = [];
    }

    $idpelAuthModeMap = json_decode($idpelAuthModeRaw, true);
    if (!is_array($idpelAuthModeMap)) {
        $idpelAuthModeMap = [];
    }

    if ($pemilik === '' || $area === '' || $odpFallback === '') {
        echo json_encode(['success' => false, 'message' => 'Server, Area, dan ODP wajib dipilih sebelum sinkron dijalankan.']);
        exit;
    }

    // Ambil ulang data dari API supaya datanya konsisten dan tidak bisa
    // dipalsukan dari sisi frontend (kecuali daftar pilihan IDPEL).
    try {
        $customers = fetchPelangganApi();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil data API: ' . $e->getMessage()]);
        exit;
    }

    // Terapkan ulang filter Area/Paket/RT/RW/Kelurahan/Kecamatan/Search yang
    // sama seperti saat preview. Ini bukan sekadar konsistensi tampilan, tapi
    // juga pengaman: IDPEL yang dikirim dari frontend hanya akan diproses
    // jika benar-benar termasuk dalam hasil filter yang sedang aktif di
    // tabel utama.
    $filterArea      = trim($_POST['filter_area'] ?? '');
    $filterPaket     = trim($_POST['filter_paket'] ?? '');
    $filterSearch    = trim($_POST['filter_search'] ?? '');
    $filterRt        = trim($_POST['filter_rt'] ?? '');
    $filterRw        = trim($_POST['filter_rw'] ?? '');
    $filterKelurahan = trim($_POST['filter_kelurahan'] ?? '');
    $filterKecamatan = trim($_POST['filter_kecamatan'] ?? '');

    $customers = applyTableFilters(
        $customers,
        $filterArea,
        $filterPaket,
        $filterSearch,
        $filterRt,
        $filterRw,
        $filterKelurahan,
        $filterKecamatan
    );

    // Index customers by IDPEL agar mudah dicari.
    $customersByIdpel = [];
    foreach ($customers as $c) {
        $idpelKey = trim((string)($c['IDPEL'] ?? ''));
        if ($idpelKey !== '') {
            $customersByIdpel[$idpelKey] = $c;
        }
    }

    // Siapkan koneksi RouterOS (kredensial dari tabel server).
    $creds = getServerCreds($conn, $pemilik, $area);
    $routerApi = null;
    $routerConnectError = '';
    if ($creds) {
        try {
            $routerApi = new RouterosAPI();
            $connected = @$routerApi->connect($creds['ip'], $creds['user'], $creds['password']);
            if (!$connected) {
                $routerApi = null;
                $routerConnectError = 'Gagal konek ke MikroTik (' . $creds['ip'] . '). Secret PPPoE TIDAK akan dibuat otomatis, tapi data tetap akan disimpan ke database.';
            }
        } catch (Throwable $t) {
            $routerApi = null;
            $routerConnectError = 'Error koneksi MikroTik: ' . $t->getMessage();
        }
    } else {
        $routerConnectError = 'Data server (IP/PASSWORD) tidak ditemukan untuk PEMILIK/AREA terpilih. Secret PPPoE TIDAK akan dibuat otomatis.';
    }

    $tempo = $creds['tempo'] ?? '';

    $totalBerhasil = 0;
    $totalDilewati  = 0;
    $totalError     = 0;
    $errors  = [];
    $skipped = [];
    $notes   = []; // info non-fatal per IDPEL, misal status MikroTik/RADIUS

    // Dipakai untuk menandai apakah ada minimal 1 entry RADIUS baru yang
    // ditambahkan selama batch ini -> menentukan perlu/tidaknya restart
    // FreeRADIUS sekali di akhir loop (bukan per-pelanggan).
    $anyRadiusUserAdded = false;

    // Cek tabel pelanggan sekali lagi di sini (defensif terhadap race
    // condition antara saat preview ditampilkan dan saat execute dijalankan).
    $existingIdpel = [];
    $resExisting = mysqli_query($conn, "SELECT IDPEL FROM `pelanggan`");
    if ($resExisting) {
        while ($rowEx = mysqli_fetch_assoc($resExisting)) {
            $existingIdpel[trim((string)$rowEx['IDPEL'])] = true;
        }
    }

    foreach ($idpelList as $idpel) {
        $idpel = trim((string)$idpel);
        if ($idpel === '') {
            continue;
        }

        if (isset($existingIdpel[$idpel])) {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Sudah ada di database (dilewati untuk menghindari duplikasi).'];
            continue;
        }

        if (!isset($customersByIdpel[$idpel])) {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Data tidak ditemukan lagi di API saat eksekusi (mungkin sudah berubah, atau tidak lagi sesuai filter tabel yang aktif).'];
            continue;
        }

        $c = $customersByIdpel[$idpel];

        // Mode Auth per-IDPEL (API MODE / RADIUS MODE / MULTI MODE), dipilih
        // manual / massal di Step 2 (frontend selalu mengirim nilai eksplisit
        // untuk tiap IDPEL terpilih). Kalau entry-nya tidak dikirim / nilainya
        // tidak dikenal, fallback ke Mode Auth dari field `MODE` API billing
        // eksternal (kalau valid), baru MULTI MODE sebagai fallback terakhir.
        $authModeRaw = trim((string)($idpelAuthModeMap[$idpel] ?? ''));
        $authMode = in_array($authModeRaw, SYNC_ALLOWED_AUTH_MODES, true)
            ? $authModeRaw
            : (resolveApiAuthMode($c['MODE'] ?? '') ?? 'MULTI MODE');

        $nama    = mysqli_real_escape_string($conn, (string)($c['NAMA'] ?? ''));
        $nowaRaw = (string)($c['NOWA'] ?? '');
        $nowa    = mysqli_real_escape_string($conn, normalizeWa($nowaRaw));
        $paket   = mysqli_real_escape_string($conn, (string)($c['PAKET'] ?? ''));
        $harga   = safeNumberSync($c['HARGA'] ?? 0);

        // Tanggal mulai tagihan diambil langsung dari field TANGGALPASANG
        // milik customer di API (bukan input manual Step 1 lagi).
        $tanggalPasangApi = trim((string)($c['TANGGALPASANG'] ?? ''));
        $tglPasangParsed = $tanggalPasangApi !== '' ? strtotime($tanggalPasangApi) : false;
        $tanggalPasang = $tglPasangParsed !== false ? date('Y-m-d', $tglPasangParsed) : date('Y-m-d');

        // Sales diambil dari field `sales` milik customer di API. Jika API
        // tidak mengirim sales untuk customer ini, fallback ke pilihan
        // Sales di Step 1 (atau "-" jika itu pun tidak diisi).
        $salesApi = trim((string)($c['sales'] ?? ''));
        $salesFinal = $salesApi !== '' ? $salesApi : $salesFallback;

        // ODP diambil dari field `ODP` milik customer di API jika tidak
        // kosong (dipakai apa adanya, tanpa validasi ke tabel `odp` --
        // sesuai keputusan: data API tetap dipercaya meski KODE-nya belum
        // terdaftar di tabel odp untuk Server/Area ini). Jika API mengirim
        // ODP kosong, baru fallback ke ODP yang dipilih manual di Step 1.
        $odpApi = trim((string)($c['ODP'] ?? ''));
        $odpFinal = $odpApi !== '' ? $odpApi : $odpFallback;

        // Alamat digabung dengan no_rumah, sesuai tampilan tabel pelanggan API.
        $alamatRaw  = (string)($c['ALAMAT'] ?? '');
        $noRumahRaw = (string)($c['no_rumah'] ?? '');
        $alamatGabung = trim($alamatRaw . ($noRumahRaw !== '' ? ' No ' . $noRumahRaw : ''));
        $alamat = mysqli_real_escape_string($conn, $alamatGabung);

        // Password: dari peta password yang dikirim frontend (per-IDPEL atau
        // hasil "isi sama untuk semua"). WAJIB diisi untuk SEMUA pelanggan --
        // frontend sudah memvalidasi ini, tapi dicek ulang di sini sebagai
        // pengaman terakhir supaya tidak ada satupun record pelanggan yang
        // tersimpan dengan PASSWORD kosong (misal kalau endpoint ini dipanggil
        // langsung tanpa lewat UI).
        $passwordPlain = trim((string)($idpelPasswordMap[$idpel] ?? ''));
        if ($passwordPlain === '') {
            $totalDilewati++;
            $skipped[] = ['idpel' => $idpel, 'reason' => 'Password PPPoE tidak diisi -- dilewati karena password wajib diisi untuk semua pelanggan.'];
            continue;
        }
        $password = mysqli_real_escape_string($conn, $passwordPlain);

        $nik = ''; // API tidak menyediakan NIK, dikosongkan dulu.

        // Index payment_history (untuk ambil bukti_url) khusus pelanggan ini.
        $paymentHistory = $c['payment_history'] ?? [];
        if (!is_array($paymentHistory)) {
            $paymentHistory = [];
        }
        $paymentHistoryIndex = buildPaymentHistoryIndex($paymentHistory);

        // --------------------------------------------------------------
        // 1. Cek & buat PPPoE secret di MikroTik bila belum ada -- hanya
        //    untuk Mode Auth API MODE / MULTI MODE.
        // --------------------------------------------------------------
        if ($authMode === 'API MODE' || $authMode === 'MULTI MODE') {
            $secretInfo = 'Tidak dicek (MikroTik tidak terhubung).';
            if ($routerApi !== null) {
                try {
                    $alreadyExists = checkSecretExists($routerApi, $idpel);
                    if ($alreadyExists) {
                        $secretInfo = 'Secret sudah ada di MikroTik, tidak dibuat ulang.';
                    } else {
                        if ($passwordPlain === '') {
                            // Tidak ada password yang diisi -> tidak bisa membuat
                            // secret baru yang aman, lewati pembuatan secret saja
                            // (data pelanggan tetap disimpan).
                            $secretInfo = 'Secret belum ada & password tidak diisi, secret TIDAK dibuat (lengkapi password lalu buat manual).';
                        } else {
                            $routerApi->comm("/ppp/secret/add", [
                                "name"     => $idpel,
                                "password" => $passwordPlain,
                                "profile"  => $paket,
                                "service"  => "any",
                                "comment"  => "SYNC API " . $nama . "-" . $nowa . "-" . $tanggalPasang
                            ]);
                            $secretInfo = 'Secret baru berhasil dibuat di MikroTik.';
                        }
                    }
                } catch (Throwable $t) {
                    $secretInfo = 'Gagal cek/buat secret: ' . $t->getMessage();
                }
            }
        } else {
            $secretInfo = 'Dilewati (Mode Auth = ' . $authMode . ', MikroTik tidak diproses).';
        }

        // --------------------------------------------------------------
        // 2. Cek & tambahkan user RADIUS (FreeRADIUS) bila belum ada --
        //    hanya untuk Mode Auth RADIUS MODE / MULTI MODE.
        //    Restart FreeRADIUS TIDAK dilakukan di sini -- hanya append ke
        //    file users; restart dijalankan sekali di akhir batch lewat
        //    radiusRestartIfNeeded(), supaya sinkron banyak pelanggan tidak
        //    me-restart service berkali-kali.
        // --------------------------------------------------------------
        if ($authMode === 'RADIUS MODE' || $authMode === 'MULTI MODE') {
            $radiusInfo = 'Tidak diproses.';
            try {
                if (radiusUserExists($idpel)) {
                    $radiusInfo = 'User sudah ada di RADIUS, tidak ditambahkan ulang.';
                } else {
                    if ($passwordPlain === '') {
                        $radiusInfo = 'User belum ada & password tidak diisi, user RADIUS TIDAK dibuat (lengkapi password lalu buat manual).';
                    } else {
                        $appended = radiusAppendUser($idpel, $passwordPlain, $paket);
                        if ($appended) {
                            $radiusInfo = 'User baru berhasil ditambahkan ke RADIUS (menunggu restart FreeRADIUS di akhir proses).';
                            $anyRadiusUserAdded = true;
                        } else {
                            $radiusInfo = 'Gagal menulis ke file users RADIUS (cek permission file/folder).';
                        }
                    }
                }
            } catch (Throwable $t) {
                $radiusInfo = 'Gagal cek/tambah user RADIUS: ' . $t->getMessage();
            }
        } else {
            $radiusInfo = 'Dilewati (Mode Auth = ' . $authMode . ', RADIUS tidak diproses).';
        }

        $notes[] = ['idpel' => $idpel, 'mikrotik' => $secretInfo, 'radius' => $radiusInfo];

        // --------------------------------------------------------------
        // 3. Insert ke tabel pelanggan.
        // --------------------------------------------------------------
        $sqlInsertPelanggan = "INSERT INTO `pelanggan`
            (`PASSWORD`, `IDPEL`, `NIK`, `NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`,
             `TANGGALPASANG`, `NOWA`, `EMAIL`, `ALAMAT`, `TEMPO`, `PEMILIK`, `MODE`, `ODP`, `AREA`,
             `TIKOR`, `sales`, `BRAND`)
            VALUES
            ('$password', '" . mysqli_real_escape_string($conn, $idpel) . "', '$nik', '$nama',
             '" . mysqli_real_escape_string($conn, $tipeBayar) . "',
             '" . mysqli_real_escape_string($conn, $tipeTempo) . "',
             '$paket', " . (float)$harga . ",
             '" . mysqli_real_escape_string($conn, $tanggalPasang) . "',
             '$nowa', '', '$alamat',
             '" . mysqli_real_escape_string($conn, $tempo) . "',
             '" . mysqli_real_escape_string($conn, $pemilik) . "',
             '" . mysqli_real_escape_string($conn, $authMode) . "',
             '" . mysqli_real_escape_string($conn, $odpFinal) . "',
             '" . mysqli_real_escape_string($conn, $area) . "',
             '', '" . mysqli_real_escape_string($conn, $salesFinal) . "',
             '" . mysqli_real_escape_string($conn, $brand) . "')";

        $insertOk = mysqli_query($conn, $sqlInsertPelanggan);

        if (!$insertOk) {
            $totalError++;
            $errors[] = ['idpel' => $idpel, 'reason' => 'Gagal insert pelanggan: ' . mysqli_error($conn)];
            continue;
        }

        // --------------------------------------------------------------
        // 4. Insert riwayat transaksi (semua transactions[] milik pelanggan
        //    ini) ke tabel transaksi, STATUS = BERHASIL (transaksi lama
        //    dianggap sudah lunas/selesai). Kolom BUKTI diisi dari
        //    bukti_url milik payment_history yang transaksi_id-nya sama;
        //    jika tidak ada yang cocok, fallback ke kode generate sendiri.
        // --------------------------------------------------------------
        $transaksiList = $c['transactions'] ?? [];
        if (!is_array($transaksiList)) {
            $transaksiList = [];
        }

        $transaksiInsertedCount = 0;
        foreach ($transaksiList as $t) {
            $tglRaw = (string)($t['transaksi_tanggal'] ?? date('Y-m-d'));
            $tglParsed = strtotime($tglRaw);
            $tanggalBayar = $tglParsed !== false ? date('Y-m-d', $tglParsed) : date('Y-m-d');
            $pengunaan = periodeIndonesia($tglRaw);

            $nominal = safeNumberSync($t['transaksi_nominal'] ?? 0);
            $bank = (string)($t['bank_nama'] ?? '');
            $keterangan = (string)($t['transaksi_keterangan'] ?? '');
            $jenis = (string)($t['transaksi_jenis'] ?? '');
            $kategori = (string)($t['kategori_nama'] ?? '');
            $transaksiIdApi = $t['transaksi_id'] ?? null;

            // Hanya transaksi "Pemasukan" yang relevan dicatat sebagai
            // pembayaran pelanggan di tabel transaksi.
            if ($jenis !== 'Pemasukan') {
                continue;
            }

            // Ambil bukti_url asli dari payment_history yang transaksi_id-nya
            // sama dengan item transactions ini. Jika tidak ketemu (misal
            // transaksi lama tanpa payment_history, atau bukan Pemasukan
            // yang tercatat di payment_history), fallback ke kode generate
            // sendiri supaya kolom BUKTI tidak kosong.
            $buktiUrl = matchBuktiUrlByTransaksiId($paymentHistoryIndex, $transaksiIdApi);
            if ($buktiUrl !== '') {
                $bukti = $buktiUrl;
            } else {
                $bukti = 'SYNC-API-' . preg_replace('/[^A-Za-z0-9_-]/', '', $idpel) . '-' . ($tglParsed !== false ? date('YmdHis', $tglParsed) : date('YmdHis')) . '-' . $transaksiInsertedCount;
            }

            $cekKet = trim('SYNC DARI API' . ($kategori !== '' ? ' - ' . $kategori : '') . ($keterangan !== '' ? ' (' . $keterangan . ')' : ''));

            $sqlInsertTransaksi = "INSERT INTO `transaksi`
                (`TANGGALBAYAR`, `PENGUNAAN`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`, `METODE_BAYAR`)
                VALUES
                ('" . mysqli_real_escape_string($conn, $tanggalBayar) . "',
                 '" . mysqli_real_escape_string($conn, $pengunaan) . "',
                 'BERHASIL',
                 '" . mysqli_real_escape_string($conn, $idpel) . "',
                 '$nama',
                 '$paket',
                 " . (float)$nominal . ",
                 '" . mysqli_real_escape_string($conn, $bukti) . "',
                 '" . mysqli_real_escape_string($conn, $cekKet) . "',
                 '" . mysqli_real_escape_string($conn, $pemilik) . "',
                 '" . mysqli_real_escape_string($conn, $bank) . "')";

            if (mysqli_query($conn, $sqlInsertTransaksi)) {
                $transaksiInsertedCount++;
            }
        }

        $existingIdpel[$idpel] = true; // tandai supaya tidak diproses dobel dalam loop ini
        $totalBerhasil++;
    }

    if ($routerApi !== null) {
        try { $routerApi->disconnect(); } catch (Throwable $t) { /* ignore */ }
    }

    // Restart FreeRADIUS HANYA SEKALI di sini, setelah semua entry user
    // baru selesai ditulis ke file users (bukan per-pelanggan), supaya
    // perubahan RADIUS untuk seluruh batch langsung aktif tanpa men-restart
    // service berkali-kali.
    $radiusRestarted = false;
    if ($anyRadiusUserAdded) {
        try {
            radiusRestartIfNeeded(true);
            $radiusRestarted = true;
        } catch (Throwable $t) {
            // Tidak fatal -- entry sudah tersimpan di file users, restart
            // gagal hanya berarti perlu restart manual supaya langsung aktif.
        }
    }

    echo json_encode([
        'success'              => true,
        'total_berhasil'       => $totalBerhasil,
        'total_dilewati'       => $totalDilewati,
        'total_error'          => $totalError,
        'errors'                => $errors,
        'skipped'               => $skipped,
        'notes'                 => $notes,
        'radius_restarted'      => $radiusRestarted,
        'router_connect_error' => $routerConnectError,
    ]);
    exit;
}

// ----------------------------------------------------------------------
echo json_encode(['success' => false, 'message' => 'Action tidak dikenal.']);