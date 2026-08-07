<?php
include 'koneksidb.php';

$radutmp_file = "/var/log/freeradius/radutmp";

// Cek apakah file radutmp tersedia dan bisa dibaca
echo "<pre>";
if (!file_exists($radutmp_file)) {
    echo "❌ File radutmp tidak ditemukan: $radutmp_file\n";
} elseif (!is_readable($radutmp_file)) {
    echo "❌ Tidak bisa membaca file radutmp: $radutmp_file\n";
    $perms = substr(sprintf('%o', fileperms($radutmp_file)), -4);
    echo "🔐 Permission saat ini: $perms\n";
    echo "👤 Owner: " . fileowner($radutmp_file) . "\n";
    echo "👥 Group: " . filegroup($radutmp_file) . "\n";
} else {
    echo "✅ File radutmp ditemukan dan bisa dibaca\n";
}
echo "</pre>";
$radius_dir = "/etc/freeradius/3.0";
$users_file = "$radius_dir/users";
$dir = "/etc/freeradius/user_timers";
$now = time();

// Ambil user online
$cmd = "sudo /usr/bin/radwho -d $radius_dir -F $radutmp_file 2>&1";
$online_output = shell_exec($cmd);
echo "📦 CMD: $cmd\n";
echo "<br>";
echo "📦 Output:\n$online_output";
echo "<br>";
echo "=======================================";
echo "<br>";
echo "USER";
echo "<br>";
echo "=======================================";
echo "<br>";
$online_usernames = [];
foreach (explode("\n", $online_output) as $line) {
    if (preg_match('/^(\S+)/', $line, $matches)) {
        $online_usernames[] = $matches[1];
    }
}

// Fungsi hapus user dari file users
function hapus_user($username)
{
    global $users_file;

    $content = shell_exec("sudo cat $users_file");
    if (!$content) {
        echo "❌ Gagal membaca file users\n";
        return false;
    }

    $pattern = "/^" . preg_quote($username, '/') . "\s+Cleartext-Password\s*:=.*?(?=^\S|\Z)/ms";
    $new_content = preg_replace($pattern, "", $content);

    $tmp = tempnam(sys_get_temp_dir(), 'usr');
    file_put_contents($tmp, $new_content);

    shell_exec("sudo tee $users_file < $tmp > /dev/null");
    unlink($tmp);

    echo "🗑️ $username dihapus dari file users\n";




    include 'koneksidb.php';
    $sql = "SELECT `IDPEL`, `PEMILIK` FROM `pelanggan` WHERE `IDPEL`='$username' ";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $IDPEL = $row['IDPEL'];
        $PEMILIK = $row['PEMILIK'];


        // Cek apakah sudah pernah dikirim
        $history_file = "notifbot/data/history-$PEMILIK.json";
        $history = [];

        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }

        // Pastikan format history adalah array
        if (!is_array($history)) {
            $history = [];
        }


        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Pelanggan $IDPEL dihapus dari RADIUS karena sesi pemakaian sudah habis waktu (timer session_timeout tercapai)";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }







    echo "<br>";
    return true;
}

// Proses timer user
foreach (glob("$dir/*.json") as $file) {
    $data = json_decode(file_get_contents($file), true);
    $username = $data['username'] ?? null;
    if (!$username) continue;

    $last_check = $data['last_check'] ?? $now;
    $used = $data['used_seconds'] ?? 0;
    $timeout = $data['session_timeout'] ?? null;

    if (in_array($username, $online_usernames)) {
        $elapsed = $now - $last_check;
        $used += $elapsed;
        $data['used_seconds'] = $used;
        echo "🟢 $username online, +$elapsed detik (total: $used)\n";



        $sql = "SELECT `IDPEL`, `PEMILIK` FROM `pelanggan` WHERE `IDPEL`='$username' ";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $IDPEL = $row['IDPEL'];
            $PEMILIK = $row['PEMILIK'];


            // Cek apakah sudah pernah dikirim
            $history_file = "notifbot/data/history-$PEMILIK.json";
            $history = [];

            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }

            // Pastikan format history adalah array
            if (!is_array($history)) {
                $history = [];
            }


            $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Pelanggan $IDPEL terdeteksi online via RADIUS, waktu pemakaian mulai dihitung";
            // Simpan ke file history
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        }








        echo "<br>";
    } else {
        echo "⚪ $username offline, tidak tambah waktu\n";

        echo "<br>";
    }

    $data['last_check'] = $now;

    if ($timeout !== null && $used >= $timeout) {
        echo "⛔ $username habis waktunya! Menghapus user...\n";

        if (hapus_user($username)) {
            unlink($file);
            echo "🧹 File timer $username.json dihapus\n";
            exec("sudo systemctl restart freeradius", $out, $code);
            echo $code === 0 ? "🔁 FreeRADIUS direstart\n" : "⚠️ Gagal restart FreeRADIUS\n";




            $sql = "SELECT `IDPEL`, `PEMILIK` FROM `pelanggan` WHERE `IDPEL`='$username' ";
            $result = $conn->query($sql);

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $IDPEL = $row['IDPEL'];
                $PEMILIK = $row['PEMILIK'];


                // Cek apakah sudah pernah dikirim
                $history_file = "notifbot/data/history-$PEMILIK.json";
                $history = [];

                if (file_exists($history_file)) {
                    $history = json_decode(file_get_contents($history_file), true);
                }

                // Pastikan format history adalah array
                if (!is_array($history)) {
                    $history = [];
                }


                $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Pelanggan $IDPEL dihapus dari RADIUS dan FreeRADIUS di-restart karena waktu pemakaian sudah habis";
                // Simpan ke file history
                file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            }





            echo "<br>";
        }
    } else {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}
