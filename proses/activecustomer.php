<?php

ob_start();
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../notifbot/notifphp/tagihan_status_lib.php';

function tanggal_indo2($tanggal, $cetak_hari = false)
{
    $hari = array(1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu');
    $bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');
    $split = explode('-', $tanggal);
    $tanggal_formatted = $split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
    if ($cetak_hari) {
        $num = date('N', strtotime($tanggal));
        return $hari[$num] . ', ' . $tanggal_formatted;
    } else {
        return $tanggal_formatted;
    }
}

session_start(); // Gunakan session
header("Content-Type: application/json");

/**
 * Fungsi pengganti blok curl_init()...curl_close() untuk kirim WA.
 * Jika $botAvailable = false, pengiriman WA otomatis DI-SKIP (tidak fatal),
 * proses aktivasi lain (mikrotik/transaksi/freeradius) TIDAK terganggu.
 *
 * @return array ['sent'=>bool, 'error'=>string|null, 'http_code'=>int|null, 'response'=>string|null]
 */
function kirimWA($botAvailable, $waapi, $botname, $passwordbot, $phone, $message, $sender = '')
{
    if (!$botAvailable || empty($waapi) || empty($botname)) {
        return ["sent" => false, "error" => "Bot WA tidak tersedia, notifikasi dilewati.", "http_code" => null, "response" => null];
    }

    $data = [
        "phone" => $phone,
        "message" => $message
    ];

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id
    // header / device_id query di /send/message) = isi kolom sender apa
    // adanya (nama device di server gowa, mis. "hanif").
    $deviceId = trim((string)$sender);

    $url = rtrim($waapi, '/') . '/send/message?session=' . urlencode($botname);
    if ($deviceId !== '') {
        $url .= '&device_id=' . urlencode($deviceId);
    }
    $headers = ["Content-Type: application/json"];
    if ($deviceId !== '') {
        $headers[] = "X-Device-Id: $deviceId";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERPWD, "$botname:$passwordbot");
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError) {
        return ["sent" => false, "error" => "CURL Error: " . $curlError, "http_code" => $httpCode, "response" => $response];
    }
    if ($httpCode != 200) {
        return ["sent" => false, "error" => "HTTP Error: $httpCode. Response: " . substr((string)$response, 0, 200), "http_code" => $httpCode, "response" => $response];
    }
    return ["sent" => true, "error" => null, "http_code" => $httpCode, "response" => $response];
}

// Ambil domain dari konfigurasi atau sesuaikan
$domain = "https://quenbytekniksejahtera.com";

$tanggalbayar = tanggal_indo2(date('Y-m-d'), true);


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Bersihkan output buffer sebelum mulai output JSON
    ob_clean();
  $id = $_POST["id"] ?? "";
    // Ambil data dari form
    $nama = $_POST["NAMA"] ?? "";
    $idpel = $_POST["IDPEL"] ?? "";
    $email = $_POST["EMAIL"] ?? "";
    $nowa = $_POST["NOWA"] ?? "";
    $paket = $_POST["PAKET"] ?? "";
    $PEMILIK = $_POST["PEMILIK"] ?? "";
    $AREA = $_POST["AREA"] ?? "";
    $metode_bayar = strtolower(trim($_POST["metode_bayar"] ?? 'cash'));
    if (!in_array($metode_bayar, ['cash', 'transfer', 'gagal payment gateway', 'kompensasi_free'], true)) {
        $metode_bayar = 'cash';
    }
    $is_kompensasi_free = ($metode_bayar === 'kompensasi_free');
    $only_activate_without_transaksi = (trim($_POST["only_activate_without_transaksi"] ?? '0') === '1');
    $periode_month_input = trim($_POST["periode_month"] ?? '');
    $periode_year_input = trim($_POST["periode_year"] ?? '');
    $periode_manual = '';

    if ($periode_month_input !== '' && $periode_year_input !== '') {
        $allowed_months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $periode_year_int = (int)$periode_year_input;

        if (in_array($periode_month_input, $allowed_months, true) && $periode_year_int >= 2000 && $periode_year_int <= 2100) {
            $periode_manual = $periode_month_input . ' ' . $periode_year_int;
        }
    }
    $bukti_existing = trim($_POST["bukti_existing"] ?? '');

    // Mode tempo pelanggan diambil ulang dari DB (bukan dari POST) supaya
    // otoritatif -- utk Rolling Due Date & Monthversary, periode penagihan
    // TIDAK lagi mengacu ke pilihan bulan/tahun (jatuh tempo mengikuti siklus
    // masing-masing pelanggan, bukan periode kalender), jadi periode DIPAKSA
    // bulan berjalan dan TANGGALBAYAR mengikuti tanggal yang dipilih admin.
    $tipe_tempo_aktif = '';
    if ($idpel !== '') {
        $idpel_tt_esc = mysqli_real_escape_string($conn, $idpel);
        $qTipeTempo = mysqli_query($conn, "SELECT TIPE_TEMPO FROM pelanggan WHERE IDPEL = '$idpel_tt_esc' LIMIT 1");
        if ($qTipeTempo && ($rowTipeTempo = mysqli_fetch_assoc($qTipeTempo))) {
            $tipe_tempo_aktif = strtolower(trim((string)($rowTipeTempo['TIPE_TEMPO'] ?? '')));
        }
    }
    $is_rolling_atau_monthversary = in_array($tipe_tempo_aktif, ['mengikuti_tanggal_bayar', 'monthversary'], true);

    $tanggal_bayar_manual_input = trim($_POST['tanggal_bayar_manual'] ?? '');
    $tanggal_bayar_manual_valid = false;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal_bayar_manual_input, $m_tbm) && checkdate((int)$m_tbm[2], (int)$m_tbm[3], (int)$m_tbm[1])) {
        $tanggal_bayar_manual_valid = true;
    }

    if ($is_rolling_atau_monthversary && !$only_activate_without_transaksi && !$tanggal_bayar_manual_valid) {
        echo json_encode(["error" => true, "message" => "Tanggal bayar/aktivasi tidak valid untuk mode Rolling/Monthversary."]);
        exit;
    }

    $manual_active_by = $_SESSION['username'] ?? (!empty($asistant_name) ? $asistant_name : $ceknama);
    $manual_active_session = session_id();

    $bukti_db_value = '';
    if (!$only_activate_without_transaksi && !$is_kompensasi_free) {
        $uploadedProofError = $_FILES['bukti_pembayaran']['error'] ?? UPLOAD_ERR_NO_FILE;
        $hasUploadedProof = isset($_FILES['bukti_pembayaran']) && $uploadedProofError === UPLOAD_ERR_OK;

        if ($hasUploadedProof) {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
            $bukti_ext = strtolower(pathinfo($_FILES['bukti_pembayaran']['name'], PATHINFO_EXTENSION));
            if (!in_array($bukti_ext, $allowed_ext, true)) {
                echo json_encode(["error" => true, "message" => "Format bukti pembayaran tidak didukung. Gunakan JPG/JPEG/PNG/WEBP."]);
                exit;
            }

            $upload_dir = __DIR__ . '/../../../dokumen/buktibon/';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0775, true) && !mkdir($upload_dir, 0777, true)) {
                echo json_encode(["error" => true, "message" => "Gagal membuat folder upload bukti pembayaran."]);
                exit;
            }
            if (!is_writable($upload_dir)) {
                @chmod($upload_dir, 0775);
            }
            if (!is_writable($upload_dir)) {
                @chmod($upload_dir, 0777);
            }
            if (!is_writable($upload_dir)) {
                echo json_encode(["error" => true, "message" => "Folder upload bukti pembayaran tidak bisa ditulis server: " . $upload_dir]);
                exit;
            }

            $safe_idpel = preg_replace('/[^A-Za-z0-9_-]/', '_', $idpel);
            $bukti_filename = 'manual_active_' . $safe_idpel . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $bukti_ext;
            $bukti_target = $upload_dir . $bukti_filename;

            $tmp_file = $_FILES['bukti_pembayaran']['tmp_name'];
            $moved = move_uploaded_file($tmp_file, $bukti_target);

            // Fallback untuk beberapa environment yang gagal di move_uploaded_file walau file valid.
            if (!$moved && is_uploaded_file($tmp_file)) {
                $moved = @rename($tmp_file, $bukti_target);
            }
            if (!$moved && file_exists($tmp_file)) {
                $moved = @copy($tmp_file, $bukti_target);
                if ($moved) {
                    @unlink($tmp_file);
                }
            }

            if (!$moved) {
                echo json_encode(["error" => true, "message" => "Gagal menyimpan foto bukti pembayaran ke folder upload."]);
                exit;
            }
            @chmod($bukti_target, 0644);

            $bukti_db_value = 'buktibon/' . $bukti_filename;
        } elseif ($uploadedProofError !== UPLOAD_ERR_NO_FILE) {
            $uploadErrMap = [
                UPLOAD_ERR_INI_SIZE => 'Ukuran foto bukti pembayaran melebihi batas upload server (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Ukuran foto bukti pembayaran melebihi batas form.',
                UPLOAD_ERR_PARTIAL => 'Upload foto bukti pembayaran terhenti sebelum selesai. Silakan ulangi.',
                UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload tidak tersedia di server.',
                UPLOAD_ERR_CANT_WRITE => 'Server gagal menyimpan file upload ke disk (temporary upload PHP). Cek ruang disk server, permission folder temporary, dan konfigurasi upload_tmp_dir.',
                UPLOAD_ERR_EXTENSION => 'Upload foto dihentikan oleh ekstensi PHP di server.'
            ];
            $errorMsg = $uploadErrMap[$uploadedProofError] ?? ('Upload foto bukti pembayaran gagal (kode error: ' . (int)$uploadedProofError . ').');
            echo json_encode(["error" => true, "message" => $errorMsg]);
            exit;
        } elseif ($bukti_existing !== '') {
            $bukti_db_value = basename($bukti_existing);
        } else {
            echo json_encode(["error" => true, "message" => "Foto bukti pembayaran wajib diupload."]);
            exit;
        }
    }

    if ($is_kompensasi_free) {
        $bukti_db_value = '';
    }

    $bukti_db_value_sql = mysqli_real_escape_string($conn, $bukti_db_value);
    $metode_bayar_sql = mysqli_real_escape_string($conn, $metode_bayar);
    $manual_active_by_sql = mysqli_real_escape_string($conn, $manual_active_by);
    $manual_active_session_sql = mysqli_real_escape_string($conn, $manual_active_session);



    # DISABLE USER, HAPUS KONEKSI, UBAH PROFIL DI MIKROTIK ===========================
    $berhasil = "Koneksi ke MikroTik dimulai!";
    // PEMILIK di sini adalah username RouterOS unik yang di-generate saat server
    // ditambahkan (lihat generateMikrotikCredentials() di proses/addserver.php),
    // BUKAN nama brand yang tampil di UI -- jadi harus dicocokkan ke kolom
    // `PEMILIK`, sama seperti proses/disablecustomer.php dan tables.php.
    // Sebelumnya dicocokkan ke kolom `BRAND` (nama tampilan, mis. "Broadband
    // AirLinK"), yang otomatis tidak akan pernah cocok dengan PEMILIK yang
    // sudah di-generate unik (mis. "Broadband_AirLinK_Home-12190_<hash>") --
    // itu sebabnya manual active selalu gagal "Server tidak ditemukan" untuk
    // pelanggan di server yang PEMILIK-nya beda dari BRAND-nya.
    $AREA_esc = mysqli_real_escape_string($conn, $AREA);
    $PEMILIK_esc = mysqli_real_escape_string($conn, $PEMILIK);
    $sql = "SELECT * FROM `server` WHERE `AREA` = '$AREA_esc' and `PEMILIK`= '$PEMILIK_esc' ";
    $query = mysqli_query($conn, $sql);
    if (!$query) {
        echo json_encode(["error" => true, "message" => "Query server gagal: " . mysqli_error($conn)]);
        exit;
    }
    $found_server = false;
    while ($data = mysqli_fetch_array($query)) {
        $found_server = true;




        // Nilai jatuh_tempo/tutup-buku dari reminder-<username>.json HANYA dipakai
        // utk hitung periode mode Fixed Due Date (lihat pemakaian $jatuh_tempo dkk
        // di bawah, dan override totalnya di blok $is_rolling_atau_monthversary
        // utk Rolling/Monthversary) -- jadi utk Rolling & Monthversary, file ini
        // TIDAK WAJIB ada sama sekali. Validasi wajib-ada di-skip utk kedua mode
        // itu supaya Manual Active tidak diblokir cuma krn Fixed Due Date belum
        // pernah diatur admin (menu Payment Setting -> Konfigurasi Fixed Due Date).
        $jatuh_tempo = '';
        $hari_sebelum = '';
        $tanggal_reminder = '';
        $botname = '';
        $tanggal_awal_tutup_buku = '';
        $tanggal_akhir_tutup_buku = '';
        $periode_tercatat_mode = 'berjalan';

        if (!$is_rolling_atau_monthversary) {
            // Path ke file JSON
            $jsonFile = "../notifbot/data/reminder-$username.json";

            // Cek apakah file ada
            if (file_exists($jsonFile)) {
                $jsonData = file_get_contents($jsonFile);
                $data3 = json_decode($jsonData, true);
                if ($data3 !== null) {
                    foreach ($data3 as $item) {
                        $jatuh_tempo = $item['jatuh_tempo'] ?? '';
                        $hari_sebelum = $item['hari_sebelum'] ?? '';
                        $tanggal_reminder = $item['tanggal_reminder'] ?? '';
                        $botname = $item['botname'] ?? '';
                        $tanggal_awal_tutup_buku = $item['tanggal_awal_tutup_buku'] ?? '';
                        $tanggal_akhir_tutup_buku = $item['tanggal_akhir_tutup_buku'] ?? '';
                    }
                } else {
                    echo json_encode(["error" => true, "message" => "Gagal mendecode JSON reminder-$username.json"]);
                    exit;
                }
            } else {
                echo json_encode(["error" => true, "message" => "File JSON reminder-$username.json tidak ditemukan"]);
                exit;
            }

            // Setting "Periode Tercatat" (Payment Setting -> Konfigurasi Fixed Due Date) --
            // dipakai supaya "periode berjalan" yg dihitung di bawah (utk auto-detect
            // $periode DAN utk keputusan sentuh/tidak koneksi Mikrotik) konsisten dgn
            // setting yang sama dipakai portal_bayar.php/invoice generator/callback gateway.
            $periode_tercatat_mode = tagihanLoadPeriodeTercatatMode($jsonFile);
        }


        // ====== FIX: bot tidak ditemukan di pengecekan awal TIDAK LAGI di-echo sebagai error ke output ======
        // (blok ini sifatnya informatif/legacy, $waapi hasilnya tidak dipakai untuk aktivasi;
        //  nilai final $waapi akan dihitung ulang di bagian notifikasi di bawah)
        $botnameNormalized = strtoupper(trim((string)$botname));
        if ($botnameNormalized === '' || $botnameNormalized === 'RANDOM') {
            $sql1 = "SELECT * FROM `botwa` WHERE `pemilik` = '$ceknama' ORDER BY RAND() LIMIT 1";
        } else {
            $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname' LIMIT 1";
        }
        $query1 = mysqli_query($conn, $sql1);
        $waapi = '';
        if ($query1) {
            while ($data1 = mysqli_fetch_array($query1)) {
                $waapi = $data1['addressbot'];
            }
        }
        /////////////////////////////////////////////////////////////////////////////////











        ///////////////////catatat di log////////////////////

        // Cek apakah sudah pernah dikirim
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];

        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }

        // Pastikan format history adalah array
        if (!is_array($history)) {
            $history = [];
        }


        $botnameNormalized = strtoupper(trim((string)$botname));
        if ($botnameNormalized === '' || $botnameNormalized === 'RANDOM') {
            $sql1 = "SELECT * FROM `botwa` WHERE `pemilik` = '$ceknama' ORDER BY RAND() LIMIT 1";
        } else {
            $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname' LIMIT 1";
        }
        $query1 = mysqli_query($conn, $sql1);

        // Nomor urut
        $nomor = 1;

        if ($query1) {
            while ($data1 = mysqli_fetch_array($query1)) {
                $waapi = $data1['addressbot'];
            }
        }
        /////////////////////////////////////////////////////////////////////////////////














        date_default_timezone_set("Asia/Jakarta");

        // Fungsi untuk mendapatkan nama bulan
        function getMonthName($month, $year)
        {
            $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
            if ($month > 12) {
                $month = 1;
                $year++;
            }
            return $months[$month - 1] . ' ' . $year;
        }

        // Fungsi untuk menghitung prorate (tidak digunakan jika hanya ingin periode)
        function calculateProrate($harga, $tempo, $currentDate)
        {
            $daysInMonth = date('t', strtotime($currentDate));
            $remainingDays = max(0, $tempo - date('d', strtotime($currentDate)));
            $prorate = ($harga / $daysInMonth) * $remainingDays;
            return round($prorate, 2);
        }

        // Variabel waktu sekarang
        $currentDate = date('Y-m-d');
        $currentDay = (int)date('d');
        $currentMonth = (int)date('m');
        $currentYear = (int)date('Y');



/////////////////////CEK TRANSAKSI TERKAHIR UNTUK PELANGGAN BULANAN  /////////////////////
function getLastTransaction($idpel)
{
    global $conn;

    $query = "SELECT * FROM transaksi 
              WHERE IDPEL = '$idpel' 
              ORDER BY 
                RIGHT(PENGUNAAN, 4) DESC, 
                FIELD(LEFT(PENGUNAAN, LOCATE(' ', PENGUNAAN) - 1), 
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') DESC 
              LIMIT 1";

    $result = mysqli_query($conn, $query);
    return mysqli_fetch_assoc($result);
}




        // Tentukan periode berdasarkan jatuh_tempo dan hari pembayaran
        $tglskg = date('d');
        $cektanggal = date('Y-m-d');
        $pbulanskg = getMonthName((int)date('m', strtotime($cektanggal)), (int)date('Y', strtotime($cektanggal))); // Bulan ini
        $pbulanberikut = getMonthName((int)date('m', strtotime('+1 month', strtotime($cektanggal))), (int)date('Y', strtotime('+1 month', strtotime($cektanggal)))); // Bulan depan
        $pbulanduatambah = getMonthName((int)date('m', strtotime('+2 month', strtotime($cektanggal))), (int)date('Y', strtotime('+2 month', strtotime($cektanggal)))); // Dua bulan ke depan

        // ==========================================
        // LOGIKA PERIODE -- forward-looking & TIPE_TEMPO-aware (formula sama dgn
        // tagihanFallbackPeriodeLabel() di tagihan_status_lib.php, dipakai jg oleh
        // semua callback payment gateway) supaya "periode berjalan" yang dipakai
        // utk keputusan sentuh/tidak koneksi Mikrotik SELALU konsisten dgn setting
        // Periode Tercatat & Fixed Due Date yang sudah diatur admin. SEBELUMNYA di
        // sini pakai heuristik tutup-buku generik ($tanggal_awal_tutup_buku/akhir)
        // yang tidak sadar Periode Tercatat sama sekali.
        // ==========================================
        if ($is_rolling_atau_monthversary) {
            // Rolling Due Date & Monthversary: periode SELALU bulan berjalan --
            // jatuh tempo pelanggan ini mengikuti siklus/anchor-nya sendiri,
            // bukan periode kalender.
            $periodeBerjalanAktif = $pbulanskg;
        } else {
            $jatuhTempoHariAktif = (int) $jatuh_tempo;
            if ($jatuhTempoHariAktif < 1 || $jatuhTempoHariAktif > 28) {
                $jatuhTempoHariAktif = 25;
            }
            $todayTsAktif = strtotime($cektanggal);
            $dueMonthTsAktif = ((int) date('j', $todayTsAktif) <= $jatuhTempoHariAktif)
                ? $todayTsAktif
                : strtotime('+1 month', $todayTsAktif);
            $periodeBerjalanAktif = tagihanResolvePeriodeTercatat(
                (int) date('n', $dueMonthTsAktif),
                (int) date('Y', $dueMonthTsAktif),
                $periode_tercatat_mode
            );
        }

        // Jika admin memilih periode manual dari modal, prioritaskan periode tersebut.
        // Kalau tidak, auto-detect ke periode berjalan aktif (TIPE_TEMPO-aware) di atas.
        $periode = ($periode_manual !== '') ? $periode_manual : $periodeBerjalanAktif;

                $user = $data['PEMILIK'];

                                // Untuk mode update transaksi, pastikan invoice PENAGIHAN periode terkait memang ada.
                                // Rolling Due Date & Monthversary DIKECUALIKAN dari syarat ini -- jatuh tempo
                                // mode ini dihitung dari histori pembayaran/anchor (tagihan_status_lib.php),
                                // bukan dari ada/tidaknya invoice PENAGIHAN, jadi manual active bisa langsung
                                // jalan tanpa perlu invoice penagihan dibuat lebih dulu.
                                if (!$only_activate_without_transaksi && !$is_rolling_atau_monthversary) {
                                        $cekPenagihanSql = "SELECT `id` FROM `transaksi`
                                                                                WHERE `IDPEL` = '$idpel'
                                                                                    AND `PENGUNAAN` = '$periode'
                                                                                    AND UPPER(TRIM(`STATUS`)) = 'PENAGIHAN'
                                                                                LIMIT 1";
                                        $cekPenagihanResult = $conn->query($cekPenagihanSql);

                                        if (!$cekPenagihanResult || $cekPenagihanResult->num_rows === 0) {
                                                echo json_encode(["error" => true, "message" => "tidak ada invoice penagihan di periode tersebut"]);
                                                exit;
                                        }
                                }

                // Manual Active SEKARANG disamakan dengan callback payment gateway
                // (callback_tripay.php dkk): begitu pembayaran dicatat BERHASIL, PASTI
                // langsung lanjut ke aktivasi Mikrotik/RADIUS di bawah -- TIDAK ADA lagi
                // syarat "periode yang diinput harus sama dengan periode berjalan hasil
                // hitung backend". $periode di sini murni LABEL kolom PENGUNAAN (sama
                // seperti di semua callback), bukan gerbang keputusan connect/tidak.
                //
                // Sebelumnya ada gate `$periode !== $periodeBerjalanAktif` yang men-skip
                // total aktivasi Mikrotik kalau periode mismatch -- dihapus karena
                // ternyata gampang trigger false-positive: modal Manual Active di
                // tables.php cuma bisa mem-prefill PERKIRAAN periode berjalan (bisa
                // meleset dari hitungan backend yang sebenarnya di momen submit), jadi
                // admin yang secara niat menginput "periode berjalan yang benar" malah
                // sering ke-skip diam-diam dan pelanggan tidak pernah ter-connect.













        $user = $data['PEMILIK'];
        $ip = $data['IP'];
        $password = $data['PASSWORD'];

        $API = new RouterosAPI();


        if ($API->connect($ip, $user, $password)) {



            $set_result = $API->comm("/ppp/secret/set", [
                ".id"     => $idpel,
                "profile" => $paket,
                "comment" => "LUNAS $nama - $nowa - $tanggalbayar ( BY ADMIN MANUAL )"
            ]);
          


            $enable_result = $API->comm("/ppp/secret/enable", ["numbers" => $idpel]);
          


            $cariurutan2 = $API->comm("/ppp/active/getall", [
                ".proplist" => ".id",
                "?name" => $idpel
            ]);
          

            if (!empty($cariurutan2) && isset($cariurutan2[0][".id"])) {

                $remove_result = $API->comm("/ppp/active/remove", [
                    ".id" => $cariurutan2[0][".id"]
                ]);
              
            }

            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] $idpel diaktifkan manual oleh $user";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

            // Mode only activate tetap lanjut ke blok notifikasi, namun tanpa update transaksi.





            if (!$only_activate_without_transaksi) {
            // Cek user dari tabel `user` berdasarkan `server`
            $sql_paket = "SELECT * FROM `paket` WHERE `PAKET` LIKE '%" . mysqli_real_escape_string($conn, $paket) . "%'";


            $query_paket = mysqli_query($conn, $sql_paket);
            $paket_data = mysqli_fetch_array($query_paket);

            // Tampilkan username
            $paketHarga = $is_kompensasi_free ? 0 : ($paket_data['HARGA'] ?? 0);






            // Gunakan formatter internal agar nama hari/bulan selalu bahasa Indonesia.
            // Rolling/Monthversary: pakai tanggal yang dipilih admin (jatuh tempo
            // berikutnya otomatis mengikuti tanggal pembayaran ini di siklus berikutnya).
            $tanggalbayar = ($is_rolling_atau_monthversary && $tanggal_bayar_manual_valid)
                ? tanggal_indo2($tanggal_bayar_manual_input, true)
                : tanggal_indo2(date('Y-m-d'), true);



          

                    
                    
            // Manual Active harus idempotent per periode. Satu pelanggan dapat
            // mempunyai beberapa invoice bulan berbeda yang dibayar pada hari
            // dan dengan paket yang sama, sehingga pencarian berdasarkan IDPEL
            // saja (atau penghapusan berdasarkan tanggal+paket) dapat menimpa
            // penanda pembayaran periode sebelumnya.
            $idpel_sql = mysqli_real_escape_string($conn, $idpel);
            $nama_sql = mysqli_real_escape_string($conn, $nama);
            $paket_sql = mysqli_real_escape_string($conn, $paket);
            $periode_sql = mysqli_real_escape_string($conn, $periode);
            $tanggalbayar_sql = mysqli_real_escape_string($conn, $tanggalbayar);
            $paketHarga_sql = mysqli_real_escape_string($conn, $paketHarga);
            $user_sql = mysqli_real_escape_string($conn, $user);
            $transaksi_id_tersimpan = 0;

            $queryCheck = "SELECT `id` FROM `transaksi`
                           WHERE `IDPEL` = '$idpel_sql'
                             AND `PENGUNAAN` = '$periode_sql'
                             AND UPPER(TRIM(`STATUS`)) IN ('KONFIRMASI', 'PENAGIHAN', 'BERHASIL')
                           ORDER BY FIELD(UPPER(TRIM(`STATUS`)), 'KONFIRMASI', 'PENAGIHAN', 'BERHASIL'), `id` DESC
                           LIMIT 1";
            $result = $conn->query($queryCheck);

            if ($result && $result->num_rows > 0) {
                // Ubah hanya invoice/transaksi milik periode yang dipilih.
                $row = $result->fetch_assoc();
                $transaksi_id = (int) $row['id'];
                $transaksi_id_tersimpan = $transaksi_id;

                $sql = "UPDATE `transaksi`
                        SET `TANGGALBAYAR` = '$tanggalbayar_sql',
                            `PENGUNAAN`   = '$periode_sql',
                            `STATUS`      = 'BERHASIL',
                            `IDPEL`       = '$idpel_sql',
                            `NAMA`        = '$nama_sql',
                            `PAKET`       = '$paket_sql',
                            `HARGA`       = '$paketHarga_sql',
                            `BUKTI`       = '$bukti_db_value_sql',
                            `CEK`         = 'Manual admin ($metode_bayar_sql)',
                            `PEMILIK`     = '$user_sql',
                            `METODE_BAYAR` = '$metode_bayar_sql',
                            `MANUAL_ACTIVE_BY` = '$manual_active_by_sql',
                            `MANUAL_ACTIVE_SESSION` = '$manual_active_session_sql'
                        WHERE `id` = $transaksi_id";
                $aksi = 'update';
            } else {
                // Jika tidak ada, lakukan INSERT baru
        $sql = "INSERT INTO `transaksi`(`TANGGALBAYAR`,`PENGUNAAN`,`STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK`, `METODE_BAYAR`, `MANUAL_ACTIVE_BY`, `MANUAL_ACTIVE_SESSION`)
                        VALUES ('$tanggalbayar_sql','$periode_sql','BERHASIL','$idpel_sql','$nama_sql','$paket_sql','$paketHarga_sql','$bukti_db_value_sql','Manual admin ($metode_bayar_sql)', '$user_sql', '$metode_bayar_sql', '$manual_active_by_sql', '$manual_active_session_sql')";
                $aksi = 'insert';
            }

if ($conn->query($sql) !== TRUE) {
    echo json_encode(["error" => true, "message" => "Gagal simpan transaksi manual active: " . $conn->error . ". Pastikan ALTER tabel transaksi sudah dijalankan."]);
    exit;
}

$sql_hapus_penagihan = "DELETE FROM `transaksi`
                        WHERE `IDPEL` = '$idpel_sql'
                          AND `PENGUNAAN` = '$periode_sql'
                          AND UPPER(TRIM(`STATUS`)) IN ('PENAGIHAN', 'KONFIRMASI')"
                        . ($transaksi_id_tersimpan > 0 ? " AND `id` <> $transaksi_id_tersimpan" : '');
$conn->query($sql_hapus_penagihan);

            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Mencatat transaksi manual untuk $idpel ($nama), paket $paket, Rp$paketHarga, metode: Manual admin ($metode_bayar), periode: $periode, tanggal bayar: $tanggalbayar, dicatat oleh: $manual_active_by";
            // Simpan ke file history
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            } else {
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Manual active ONLY untuk $idpel (tanpa update transaksi).";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }





            ////////////////////////////////////////////////////


                                $pesan_aktif_manual = '';
                               
                              
                                    $stmt = $conn->prepare("SELECT pesan_aktif_manual FROM notif_khusus WHERE pemilik = ? LIMIT 1");
                                    $stmt->bind_param('s',$ceknama);
                                    $stmt->execute();
                                    $stmt->bind_result($pesan_aktif_manual);
                                    $stmt->fetch();
                                    $stmt->close();
                              

                                // Fungsi untuk replace variabel di pesan ketentuan
                                function replace_vars($template, $vars) {
                                    return preg_replace_callback('/\\$([a-zA-Z0-9_]+)/', function($m) use ($vars) {
                                        $key = $m[1];
                                        return isset($vars[$key]) ? $vars[$key] : $m[0];
                                    }, $template);
                                }
                               











    // ====== BLOK NOTIFIKASI WA (FIX: kegagalan di sini TIDAK LAGI exit / menggagalkan proses) ======

    // Path ke file JSON reminder
    $jsonFile = "../notifbot/data/reminder-$ceknama.json";

    // Default
    $botname = "";
    if (file_exists($jsonFile)) {
        $jsonData = file_get_contents($jsonFile);
        $data = json_decode($jsonData, true);
        if (is_array($data)) {
            foreach ($data as $item) {
                $botname = $item['botname'];
                // hanya pakai botname (jika ada lebih dari 1 entri, gunakan yg terakhir)
            }
        }
    }

    // Override bot pengirim jika ada setting khusus manual active di notification.
    $botReceiverConfigPath = __DIR__ . "/../notifbot/data/bot_receiver_config-" . $ceknama . ".json";
    if (file_exists($botReceiverConfigPath)) {
        $botReceiverConfig = json_decode(file_get_contents($botReceiverConfigPath), true);
        if (is_array($botReceiverConfig)) {
            $manualActiveBot = trim((string)($botReceiverConfig['manual_active'] ?? ''));
            if ($manualActiveBot !== '') {
                $botname = $manualActiveBot;
            }
        }
    }

    // Ambil informasi bot
    $waapi = "";
    $passwordbot = "";
    $sender = "";
    $penerima_manual_active = "";
    $botAvailable = false;
    $botStatusMessage = '';

    $botnameNormalized = strtoupper(trim((string)$botname));
    if ($botnameNormalized === '' || $botnameNormalized === 'RANDOM') {
        $sql1 = "SELECT * FROM `botwa` WHERE `pemilik` = '$ceknama' ORDER BY RAND() LIMIT 1";
    } else {
        $sql1 = "SELECT * FROM `botwa` WHERE `namebot` = '$botname' LIMIT 1";
    }
    $query1 = mysqli_query($conn, $sql1);

    if (!$query1) {
        $botStatusMessage = "Query botwa gagal: " . mysqli_error($conn);
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] WARNING NOTIF: " . $botStatusMessage . " (notifikasi dilewati, aktivasi tetap dilanjutkan)";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        if ($data1 = mysqli_fetch_array($query1)) {
            $waapi = $data1['addressbot'];
            $passwordbot = $data1['password'];
            $botname = $data1['namebot']; // Timpa $botname dengan namebot dari database
            $sender = $data1['sender'] ?? '';
            $penerima_manual_active = $data1['penerima_manual_active'] ?? '';
        }

        if (!$waapi) {
            $botStatusMessage = "Bot WA tidak ditemukan untuk owner: $ceknama (botname: $botname)";
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] WARNING NOTIF: " . $botStatusMessage . " (notifikasi dilewati, aktivasi tetap dilanjutkan)";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            $botAvailable = true;
        }
    }

    $waapi = htmlspecialchars($waapi);
    $phone = "$nowa@s.whatsapp.net";

    // Ambil semua variabel terdefinisi di scope ini
    $vars = get_defined_vars();
    // Hapus variabel yang tidak perlu (opsional, agar tidak bocor variabel besar)
    unset($vars['isi'], $vars['match'], $vars['vars']);
    $message = replace_vars($pesan_aktif_manual, $vars);

    // Tambahkan label mode jika hanya aktifkan
    if ($only_activate_without_transaksi) {
        $message = "?? *HANYA AKTIFKAN KONEKSI*\n\n" . $message;
    }

    $botname = htmlspecialchars($botname);
    $passwordbot = htmlspecialchars($passwordbot);

    // ---- Kirim notifikasi ke pelanggan (di-skip otomatis jika bot tidak tersedia) ----
    $kirimPelanggan = kirimWA($botAvailable, $waapi, $botname, $passwordbot, $phone, $message, $sender);

    if (!$kirimPelanggan['sent']) {
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] NOTIF PELANGGAN DILEWATI/GAGAL: " . $kirimPelanggan['error'];
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil kirim notifikasi WhatsApp ke pelanggan $nowa";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }

    // ---- Kirim notifikasi ke owner (di-skip otomatis jika bot tidak tersedia atau penerima kosong) ----
    if (!empty($penerima_manual_active)) {
        $owner_mode_label = $only_activate_without_transaksi ? "[ONLY AKTIFKAN] " : "";
        $owner_manual_message = "?? *" . $owner_mode_label . "AKTIVITAS MANUAL ACTIVE TERDETEKSI*\n\n"
            . "?? *ID Pelanggan*   : $idpel\n"
            . "?? *Nama*           : $nama\n"
            . "?? *Paket*          : $paket\n"
            . "?? *No WhatsApp*    : $nowa\n"
            . "?? *Email*          : $email\n"
            . "?? *Area*           : $AREA\n"
            . "?? *Pemilik/Brand*  : $PEMILIK\n"
            . "?? *Metode Bayar*   : $metode_bayar\n"
            . "?? *Tanggal Bayar*  : $tanggalbayar\n"
            . "?? *Periode*        : $periode\n"
            . "?? *Diproses Oleh*  : $manual_active_by\n"
            . "?? *Session ID*     : $manual_active_session\n"
            . "?? *Bukti*          : $bukti_db_value\n\n"
            . "?? *Pesan ke pelanggan:*\n$message";

        $kirimOwner = kirimWA($botAvailable, $waapi, $botname, $passwordbot, $penerima_manual_active, $owner_manual_message, $sender);

        if (!$kirimOwner['sent']) {
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] NOTIF OWNER DILEWATI/GAGAL: " . $kirimOwner['error'];
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        } else {
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil kirim notifikasi WhatsApp ke pemilik/owner $penerima_manual_active";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
    // ====== AKHIR BLOK NOTIFIKASI WA � apapun hasilnya, eksekusi tetap lanjut ke bawah ======





       





            // ==================== UPDATE/TAMBAH USER DI FILE USERS FREERADIUS ====================
            // Pakai reconcile terpusat (radius_sync_lib.php) supaya path file dan
            // sumber password SAMA dengan yang dipakai cron sync_freeradius_users.php.
            // Sebelumnya di sini password RADIUS di-hardcode = $idpel (bukan dari
            // kolom pelanggan.PASSWORD), jadi begitu cron sync jalan 30 menit
            // kemudian, password ditimpa jadi nilai yang berbeda dan pelanggan yang
            // baru diaktifkan manual jadi gagal auth (kelihatan seperti "hilang").
            //
            // PENTING: bagian ini sengaja dipindah ke SEBELUM disconnect + sebelum kick
            // koneksi aktif kedua di bawah (sebelumnya ada di bawah $API->disconnect()).
            // Kick /ppp/secret/set + /ppp/active/remove di atas cuma me-refresh pelanggan
            // mode LOCAL (profile sudah diupdate lewat /ppp/secret/set sebelum kick itu).
            // Untuk pelanggan mode RADIUS, profile aktualnya ada di atribut Mikrotik-Group
            // pada file user FreeRADIUS (di-update di sini) -- kick pertama di atas terjadi
            // SEBELUM baris ini, jadi kalau session-nya redial persis saat itu, ia masih
            // dapat Mikrotik-Group LAMA. Makanya perlu kick KEDUA di bawah, SETELAH entri
            // RADIUS ini benar-benar diupdate, supaya redial berikutnya pasti pakai paket baru.
            $sql_pw = "SELECT `PASSWORD` FROM `pelanggan` WHERE `IDPEL` = '" . mysqli_real_escape_string($conn, $idpel) . "' LIMIT 1";
            $result_pw = $conn->query($sql_pw);
            $radius_password = ($result_pw && $row_pw = $result_pw->fetch_assoc()) ? $row_pw['PASSWORD'] : '';

            if ($radius_password === '') {
                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] WARNING RADIUS: pelanggan.PASSWORD kosong untuk $idpel, entri RADIUS tidak ditulis/diupdate.";
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            } else {
                $radius_result = radiusUpsertUsers([
                    $idpel => [
                        'password' => $radius_password,
                        'reply' => ['Mikrotik-Group := "' . $paket . '"'],
                    ],
                ]);
                radiusReloadIfChanged($radius_result['changed']);

                // Kick sesi aktif KEDUA (setelah Mikrotik-Group baru di atas benar-benar
                // tersimpan) supaya pelanggan RADIUS yang kebetulan masih online dengan
                // profile lama langsung diputus dan redial dengan paket baru - bukan cuma
                // menunggu sampai koneksinya putus sendiri lain waktu. Aman dilakukan juga
                // untuk pelanggan LOCAL (kalau memang masih ada sesi aktif tersisa dari
                // kick pertama, /ppp/active/getall di sini simpelnya tidak menemukan apa-apa).
                $cariAktifRadius = $API->comm("/ppp/active/getall", [
                    ".proplist" => ".id",
                    "?name" => $idpel
                ]);
                if (!empty($cariAktifRadius) && isset($cariAktifRadius[0][".id"])) {
                    $API->comm("/ppp/active/remove", [
                        ".id" => $cariAktifRadius[0][".id"]
                    ]);
                    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Sesi aktif $idpel diputus ulang setelah update RADIUS supaya paket baru langsung berlaku.";
                    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                }
            }

            $API->disconnect();

            // Kirim response dalam format JSON
            $notif_note = $botAvailable ? "" : " (Catatan: notifikasi WA dilewati karena bot tidak tersedia)";
            if ($only_activate_without_transaksi) {
                echo json_encode(["message" => "? Berhasil hanya aktifkan pelanggan id $idpel tanpa update transaksi." . $notif_note]);
            } else {
                echo json_encode(["message" => "? Berhasil aktif secara manual pelanggan id $idpel" . $notif_note]);
            }
            exit;
        } else {
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] $idpel GAGAL diaktifkan manual oleh $user";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            echo json_encode(["error" => true, "message" => "? Failed to connect MikroTik (IP: $ip, user: $user)" ]);
            exit;
        }
    }
    if (!$found_server) {
        echo json_encode(["error" => true, "message" => "Server tidak ditemukan untuk AREA: $AREA dan PEMILIK: $PEMILIK"]);
        exit;
    }
} else {
    ob_clean();
    echo json_encode(["error" => true, "message" => "? Tidak diizinkan, hanya POST yang diterima"]);
    exit;
}
