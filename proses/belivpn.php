<?php
require '../cek-sesi.php';
    // PROSES BELI VPN
    require('routeros_api.class.php'); // pastikan file ini ada

    // ambil konfigurasi
    $config_file = '../config.json';
    $config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
    //server utama
    $router_ip = $config['router_ip'];
    $ippublicportapi=$config['ippublicportapi'];
    $router_user = $config['router_user'];
    $router_pass = $config['router_pass'];




    $ippublic = $config['ippublic'];
    $webiplocal = $config['webiplocal'];


    $API = new RouterosAPI();

    // Pastikan kolom KETERANGAN ada (dipakai untuk catat VPN ini buat apa)
    function ensure_column_exists($conn, $table, $column, $type) {
        $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$check || mysqli_num_rows($check) == 0) {
            $sql = "ALTER TABLE `$table` ADD `$column` $type NULL";
            mysqli_query($conn, $sql);
        }
    }
    ensure_column_exists($conn, 'pelanggan', 'KETERANGAN', 'TEXT');

    // Pastikan SSTP-server di router utama aktif DAN jalan di port 4433 (semua koneksi VPN
    // sekarang pakai SSTP saja - L2TP/PPTP tidak dipakai lagi). Kalau masih di port lain
    // (mis. default 443) atau mati, paksa nyalakan & pindah ke 4433 dulu sebelum bikin secret.
    function mikrotik_ensure_sstp_server($API, $port = '4433') {
        $server = $API->comm('/interface/sstp-server/server/print');
        $current_port = (is_array($server) && isset($server[0]['port'])) ? $server[0]['port'] : null;
        $current_enabled = (is_array($server) && isset($server[0]['enabled'])) ? $server[0]['enabled'] : null;

        // Field "enabled" bisa balik sebagai "yes"/"no" atau "true"/"false" tergantung versi RouterOS.
        if ($current_port === $port && in_array($current_enabled, ['true', 'yes'], true)) {
            echo '<pre>DEBUG: SSTP server router utama sudah aktif di port ' . htmlspecialchars($port) . '</pre>';
            return $port;
        }

        $API->comm('/interface/sstp-server/server/set', [
            'port' => $port,
            'enabled' => 'yes'
        ]);
        echo '<pre>DEBUG: SSTP server router utama di-set aktif di port ' . htmlspecialchars($port) . '</pre>';
        return $port;
    }


    // PASTIKAN HANYA TERIMA POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo '<pre>DEBUG: Mulai proses beli VPN</pre>';
        $usernameRaw = trim($_POST['username'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $usernameRaw)) {
            echo '<div class="alert alert-danger">❌ Gagal: Username VPN hanya boleh huruf, angka, "-", dan "_" (tanpa spasi/simbol lain).</div>';
            exit;
        }
        $usernamevpn = 'VPNQ-' . $usernameRaw;
        $passwordvpn =  $usernamevpn;
        $servicevpn = 'sstp'; // semua koneksi VPN sekarang pakai SSTP
        $paketvpn = $_POST['paket'];
        $tempo = $_POST['tempo'];
        $keterangan = mysqli_real_escape_string($conn, trim($_POST['keterangan'] ?? ''));

        // 4 port layanan standar + 1 port custom opsional (kebutuhan khusus, isi hanya jika perlu),
        // sesuai isian manual di form (kosong = tidak dipakai)
        $portInputs = [
            $_POST['port1'] ?? '',
            $_POST['port2'] ?? '',
            $_POST['port3'] ?? '',
            $_POST['port4'] ?? '',
            $_POST['port5'] ?? '',
        ];
        $portsVpn = [];
        foreach ($portInputs as $p) {
            $p = trim((string)$p);
            if ($p !== '' && ctype_digit($p) && !in_array($p, $portsVpn, true)) {
                $portsVpn[] = $p;
            }
        }

        $parts = explode("|", $paketvpn);
        $paket = $parts[0]; // "PAKET"
        $harga = $parts[1]; // "5000"
        echo '<pre>DEBUG: usernamevpn=' . htmlspecialchars($usernamevpn) . ', paket=' . htmlspecialchars($paket) . ', harga=' . htmlspecialchars($harga) . ', saldo=' . htmlspecialchars($saldo) . ', port_layanan=' . htmlspecialchars(implode(',', $portsVpn)) . '</pre>';

        if (empty($portsVpn)) {
            echo '<div class="alert alert-danger">❌ Gagal: Minimal isi 1 Port Layanan yang mau di-forward.</div>';
        } elseif ($saldo >= $harga) {
            echo '<pre>DEBUG: Saldo cukup, lanjut proses koneksi ke MikroTik</pre>';
            if ($API->connect($router_ip.":".$ippublicportapi, $router_user, $router_pass)) {
                echo '<pre>DEBUG: Berhasil konek ke MikroTik</pre>';

                // Pastikan SSTP server router utama aktif di port 4433
                mikrotik_ensure_sstp_server($API, '4433');

                // Cek user
                $check = $API->comm("/ppp/secret/print", ["?name" => $usernamevpn]);
                if (!empty($check)) {
                    echo '<div class="alert alert-warning">⚠️ User <strong>' . htmlspecialchars($usernamevpn) . '</strong> sudah ada!</div>';
                } else {
                    echo '<pre>DEBUG: User belum ada, lanjut proses NAT dan IP Pool</pre>';
                    // Ambil semua rule NAT
                    $natRules = $API->comm("/ip/firewall/nat/print");
                    $usedPorts = [];
                    // Loop untuk ambil dst-port dari setiap rule
                    foreach ($natRules as $rule) {
                        if (isset($rule['dst-port'])) {
                            $ports = explode(",", $rule['dst-port']); // bisa jadi port list
                            foreach ($ports as $p) {
                                if (strpos($p, '-') !== false) {
                                    [$start, $end] = explode("-", $p);
                                    for ($i = $start; $i <= $end; $i++) {
                                        $usedPorts[] = (int)$i;
                                    }
                                } else {
                                    $usedPorts[] = (int)$p;
                                }
                            }
                        }
                    }

                    // Tentukan rentang port yang mau dicek
                    $rangeStart = 4001;
                    $rangeEnd = 5000;

                    // Pilih 1 port publik unik (belum dipakai) untuk tiap port layanan yang diminta
                    $unusedPorts = [];
                    $portCursor = $rangeStart;
                    foreach ($portsVpn as $destPort) {
                        $found = null;
                        while ($portCursor <= $rangeEnd) {
                            if (!in_array($portCursor, $usedPorts)) {
                                $found = $portCursor;
                                $usedPorts[] = $portCursor; // langsung tandai dipakai supaya slot berikutnya tidak bentrok
                                $portCursor++;
                                break;
                            }
                            $portCursor++;
                        }
                        if ($found === null) {
                            $unusedPorts = null;
                            break;
                        }
                        $unusedPorts[$destPort] = $found;
                    }

                    if ($unusedPorts === null) {
                        echo "<div class='alert alert-danger'>VPN PORT FULL</div>";
                        exit;
                    }
                    echo '<pre>DEBUG: Mapping port publik => port tujuan: ' . htmlspecialchars(json_encode($unusedPorts)) . '</pre>';

                    // Cek registrasi
                    $cektanggal = date('Y-m-d');
                    // Ambil profile PPP
                    $profiles = $API->comm('/ppp/profile/print', [
                        "?name" => $paket
                    ]);
                    if (!empty($profiles)) {
                        $profile = $profiles[0];
                        $remoteAddress = $profile['remote-address'];
                        echo '<pre>DEBUG: Profile ditemukan, remote-address=' . htmlspecialchars($remoteAddress) . '</pre>';
                        // Ambil IP Pool
                        $ip_pool = $API->comm('/ip/pool/print', [
                            "?name" => $remoteAddress
                        ]);
                        if (empty($ip_pool)) {
                            echo "<div class='alert alert-danger'>IP Pool '$remoteAddress' tidak ditemukan.</div>";
                            exit;
                        }
                        $pool = $ip_pool[0];
                        $pool_ranges = explode(",", $pool['ranges']);
                        // Ambil IP yang sudah digunakan di pool
                        $used_ips = $API->comm('/ip/pool/used/print', [
                            "?pool" => $remoteAddress
                        ]);
                        $used_list = array_column($used_ips, 'address');
                        // Ambil semua remote-address dari PPP Secret
                        $secrets = $API->comm('/ppp/secret/print');
                        $remote_addresses = array_column($secrets, 'remote-address');
                        // Gabungkan semua IP yang sudah terpakai
                        $blocked_ips = array_merge($used_list, $remote_addresses);
                        $found_ip = null;
                        // Cari IP di pool yang belum dipakai dan belum jadi remote-address
                        foreach ($pool_ranges as $range) {
                            if (strpos($range, '-') !== false) {
                                [$start_ip, $end_ip] = explode("-", $range);
                            } else {
                                $start_ip = $end_ip = $range;
                            }
                            $start = ip2long(trim($start_ip));
                            $end = ip2long(trim($end_ip));
                            for ($ip = $start; $ip <= $end; $ip++) {
                                $current_ip = long2ip($ip);
                                if (!in_array($current_ip, $blocked_ips)) {
                                    $found_ip = $current_ip;
                                    break 2; // langsung keluar setelah ketemu
                                }
                            }
                        }
                        if ($found_ip) {
                            echo '<pre>DEBUG: IP pool tersedia: ' . htmlspecialchars($found_ip) . '</pre>';
                        } else {
                            echo "<div class='alert alert-danger'>VPN IP FULL</div>";
                            exit;
                        }
                    } else {
                        echo "<div class='alert alert-danger'>PPP Profile tidak ditemukan!</div>";
                        exit;
                    }

                    $add = $API->comm("/ppp/secret/add", [
                        "name" => $usernamevpn,
                        "password" => $usernamevpn,
                        "service" => 'sstp',
                        "profile" => "$paket",
                        "remote-address" => $found_ip,
                        "comment"      => "VPN-KONKSI-BILLLING-SERVER-$ceknama"
                    ]);
                    echo '<pre>DEBUG: PPP Secret add result: ' . htmlspecialchars(json_encode($add)) . '</pre>';

                    // Bikin 1 NAT dst-nat per port layanan (sesuai inputan manual form)
                    foreach ($unusedPorts as $destPort => $pubPort) {
                        $API->comm("/ip/firewall/nat/add", [
                            "chain"        => "dstnat",
                            "action"       => "dst-nat",
                            "protocol"     => "tcp",
                            "dst-address"  => $router_ip, // IP publik router
                            "dst-port"     => $pubPort,         // port dari luar
                            "to-addresses" => $found_ip,        // IP tujuan internal
                            "to-ports"     => $destPort,        // port tujuan internal (isian manual form)
                            "comment"      => "VPN-KONKSI-BILLLING-SERVER-$ceknama-$usernamevpn-TOPORT-$found_ip-P$destPort"
                        ]);
                    }

                    // Simpan semua mapping port publik=>tujuan dalam 1 kolom ALAMAT, dipisah koma:
                    // IP:pub1=>dst1,pub2=>dst2,...
                    $mappingPairs = [];
                    foreach ($unusedPorts as $destPort => $pubPort) {
                        $mappingPairs[] = $pubPort . '=>' . $destPort;
                    }
                    $alamat = $router_ip . ':' . implode(',', $mappingPairs);

                    $sql2 = "INSERT INTO `pelanggan` (`PASSWORD`,`IDPEL`,`NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`, `TANGGALPASANG`,`NOWA`,`EMAIL`,`ALAMAT`,`TEMPO`,`TIKOR`,`PEMILIK`,`MODE`,`ODP`,`AREA`,`BRAND`,`KETERANGAN`) VALUES ('$usernamevpn','$passwordvpn','$ceknama','pascabayar','mengikuti_tanggal_bayar','$paket','$harga','$cektanggal','$nowa','-','$alamat','$tempo','$found_ip','$router_user','$servicevpn','VPNQ','SERVER','VPN','$keterangan')";
                    echo '<pre>DEBUG: SQL pelanggan: ' . htmlspecialchars($sql2) . '</pre>';
                    if ($conn->query($sql2) === TRUE) {
                        if (isset($add[0]['!trap'])) {
                            echo '<div class="alert alert-danger">❌ Gagal: ' . htmlspecialchars($add[0]['!trap']['message']) . '</div>';
                        } else {
                            $updatesaldo = $saldo - $harga;
                            $sql22 = "UPDATE `user` SET `saldo` = '$updatesaldo' WHERE `USERNAME` = '$ceknama'";
                            echo '<pre>DEBUG: SQL update saldo: ' . htmlspecialchars($sql22) . '</pre>';
                            if ($conn->query($sql22) === TRUE) {
                                echo '<div class="alert alert-success">✅ Berhasil beli VPN!</div>';
                                // log history
                                $history_file = "../notifbot/data/history-$ceknama.json";
                                $history = [];
                                if (file_exists($history_file)) {
                                    $history = json_decode(file_get_contents($history_file), true);
                                }
                                if (!is_array($history)) { $history = []; }
                                $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil beli VPN $usernamevpn paket $paket";
                                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
                                // Cek jika paket adalah FREE VPN
                                if (strtoupper($paket) === 'FREE VPN' || strtoupper($paket) === 'FREEVPN') {
                                    header('Location: ../vpnadmin.php');
                                } else {
                                    header('Location: ../vpn.php');
                                }
                            } else {
                                echo '<div class="alert alert-danger">❌ Gagal update saldo!</div>';
                            }
                        }
                    } else {
                        echo '<div class="alert alert-danger">❌ Gagal insert pelanggan!<br>MySQL Error: ' . htmlspecialchars($conn->error) . '</div>';
                    }
                }
            } else {
                echo '<div class="alert alert-danger">❌ Gagal konek ke MikroTik!</div>';
            }
        } else {
            echo '<div class="alert alert-danger">❌ Gagal: Saldo kamu kurang ! tambah saldo dahulu </div>';
        }
    }
    ?>
