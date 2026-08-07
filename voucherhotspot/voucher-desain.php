<?php
require '../cek-sesi.php';
$ceknama;






// Ambil data dari form (atau dummy jika belum dikirim)
$host = $_POST['ip'] ?? '';
$user = $_POST['us'] ?? 'user123';
$pass = $_POST['ps'] ?? 'pass123';
$login = $_POST['login'] ?? 'http://192.168.88.1/login';
$jumlah = intval($_POST['jumlah'] ?? 3);
$packages = $_POST['packages'] ?? 'Paket|1|desc|10000|2000|Unlimited';
$uptime = $_POST['uptime'] ?? '1d';
$ratelimit = $_POST['ratelimit'] ?? 'Unlimited';
$prefix = $_POST['prefix'] ?? '';
$radius = $_POST['radius'] ?? '';
$mode = $_POST['mode'] ?? 'number';
$panjang = intval($_POST['length'] ?? 8);
$desain = $_POST['desain'] ?? 'default';
$nocs = $_POST['nocs'];


$logo_path = $_POST['logo_path'];
$bg_path = $_POST['bg_path'];



$namapaket = $packages;



// Escape untuk keamanan
$nama_paket_esc = mysqli_real_escape_string($conn, $namapaket);

// Query untuk ambil harga berdasarkan nama paket
$sql = "SELECT harga FROM paket_hotspot WHERE paket = '$nama_paket_esc' LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $harga = $row['harga'];
   $harga_formatted = 'Rp ' . number_format($harga, 0, ',', '.');
}





$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($login);






function generate_random($length = 8, $mode = 'mixed')
{
    $chars = '';
    if ($mode === 'number') $chars = '0123456789';
    elseif ($mode === 'letter') $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'mixedbesar') $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'mixedkecil') $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
    elseif ($mode === 'mixedbesarkecil') $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'letterbesar') $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'letterkecil') $chars = 'abcdefghijklmnopqrstuvwxyz';
    elseif ($mode === 'letterbesarkecil') $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    $result = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }

    return $result;
}

function convertSecondsToReadable($seconds) {
    if (!is_numeric($seconds) || $seconds < 0) return "? Format waktu tidak valid!";

    $years = floor($seconds / (365 * 24 * 3600));
    $seconds %= 365 * 24 * 3600;

    $months = floor($seconds / (30 * 24 * 3600));
    $seconds %= 30 * 24 * 3600;

    $weeks = floor($seconds / (7 * 24 * 3600));
    $seconds %= 7 * 24 * 3600;

    $days = floor($seconds / (24 * 3600));
    $seconds %= 24 * 3600;

    $hours = floor($seconds / 3600);
    $seconds %= 3600;

    $minutes = floor($seconds / 60);
    $seconds %= 60;

    $parts = [];
    if ($years) $parts[] = "$years tahun";
    if ($months) $parts[] = "$months bulan";
    if ($weeks) $parts[] = "$weeks minggu";
    if ($days) $parts[] = "$days hari";
    if ($hours) $parts[] = "$hours jam";
    if ($minutes) $parts[] = "$minutes menit";
    if ($seconds) $parts[] = "$seconds detik";

    return $parts ? implode(' ', $parts) : "0 detik";
}



   $uptime = convertSecondsToReadable($uptime);









$list_voucher = [];


// echo "<pre>";
// print_r($_POST);
// echo "</pre>";


?>
   <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Optional: Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        /* Tambahan styling tombol */
        .btn-icon svg {
            transition: transform 0.3s ease;
        }
        .btn:hover .btn-icon svg {
            transform: scale(1.2);
        }
    </style>
<?php
$voucher_padding = isset($_POST['voucher_padding']) ? intval($_POST['voucher_padding']) : 6;
$voucher_margin = isset($_POST['voucher_margin']) ? intval($_POST['voucher_margin']) : 0;
$voucher_gap = isset($_POST['voucher_gap']) ? intval($_POST['voucher_gap']) : 0;
?>
<style>
    .voucher-grid {
        display: flex;
        flex-wrap: wrap;
        gap: <?= $voucher_gap ?>px;
        margin-top: 20px;
        justify-content: center;
    }
</style>



<div class="voucher-grid" id="voucherGrid">
    <?php
    // Tentukan list voucher
    if (isset($_POST['vouchers']) && is_array($_POST['vouchers'])) {
        $list_voucher = $_POST['vouchers'];
    } else {
        $list_voucher = [];
        for ($i = 0; $i < $jumlah; $i++) {


            // Buat voucher baru
            $usernamevc = generate_random($panjang, $mode);
            if ($prefix != '') $usernamevc = $prefix . $usernamevc;
            $password = $usernamevc;

            $list_voucher[] = [
                'user' => $usernamevc,
                'pass' => $password
            ];
        }
    }

    // Loop satu kali saja
    for ($i = 0; $i < count($list_voucher); $i++):
        $usernamevc = $list_voucher[$i]['user'] ?? 'user' . $i;
        $passwordvc = $list_voucher[$i]['pass'] ?? $usernamevc;
        $custom_style = "padding:{$voucher_padding}px;margin:{$voucher_margin}px;";
        $voucher_id = "voucher-" . $i;





        if (strpos((string)$desain, 'custom_') === 0):
            // ==================== TEMPLATE CUSTOM (drag-and-drop editor) ====================
            // Render dari layout JSON tersimpan (voucher_template_builder.php), bukan
            // template hardcode. Token elemen diisi dari variabel voucher yg SAMA
            // dipakai semua desain bawaan di bawah ($usernamevc/$passwordvc/$namapaket/
            // $harga_formatted/$uptime/$qr_url/$logo_path/$nocs/$login) supaya konsisten.
            require_once __DIR__ . '/voucher_template_helper.php';
            $custom_tpl_id = substr((string)$desain, strlen('custom_'));
            $custom_tpl = get_voucher_template_by_id($ceknama ?? '', $custom_tpl_id);
            if ($custom_tpl):
                $tc = $custom_tpl['canvas'];
        ?>
            <div style="position:relative;width:<?= (int)$tc['w'] ?>px;height:<?= (int)$tc['h'] ?>px;background:<?= htmlspecialchars($tc['bg']) ?>;overflow:hidden;box-sizing:border-box;<?= $custom_style ?>" data-voucher id="voucher-<?= $i ?>">
                <?php foreach ($custom_tpl['elements'] as $el):
                    $elStyle = 'position:absolute;left:' . (int)$el['x'] . 'px;top:' . (int)$el['y'] . 'px;width:' . (int)$el['w'] . 'px;height:' . (int)$el['h'] . 'px;'
                        . 'z-index:' . (int)$el['z'] . ';font-size:' . (int)$el['fontSize'] . 'px;color:' . htmlspecialchars($el['color']) . ';'
                        . 'background:' . ($el['bgColor'] === 'transparent' ? 'transparent' : htmlspecialchars($el['bgColor'])) . ';'
                        . 'font-weight:' . ($el['bold'] ? 'bold' : 'normal') . ';font-style:' . ($el['italic'] ? 'italic' : 'normal') . ';'
                        . 'text-align:' . htmlspecialchars($el['align']) . ';border-radius:' . (int)$el['radius'] . 'px;'
                        . 'transform:rotate(' . (int)$el['rotate'] . 'deg);display:flex;align-items:center;overflow:hidden;box-sizing:border-box;'
                        . 'justify-content:' . ($el['align'] === 'center' ? 'center' : ($el['align'] === 'right' ? 'flex-end' : 'flex-start')) . ';';
                    switch ($el['type']):
                        case 'logo': ?>
                            <div style="<?= $elStyle ?>"><img src="<?= htmlspecialchars($logo_path) ?>" style="width:100%;height:100%;object-fit:contain;"></div>
                        <?php break;
                        case 'qrcode': ?>
                            <div style="<?= $elStyle ?>"><img src="<?= $qr_url ?>" style="width:100%;height:100%;object-fit:contain;"></div>
                        <?php break;
                        case 'username': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($usernamevc) ?></div>
                        <?php break;
                        case 'password': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($passwordvc) ?></div>
                        <?php break;
                        case 'paket': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($namapaket) ?></div>
                        <?php break;
                        case 'harga': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($harga_formatted ?? '') ?></div>
                        <?php break;
                        case 'uptime': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($uptime) ?></div>
                        <?php break;
                        case 'nocs': ?>
                            <div style="<?= $elStyle ?>">CS: <?= htmlspecialchars(strtoupper((string)$nocs)) ?></div>
                        <?php break;
                        case 'login': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($login) ?></div>
                        <?php break;
                        case 'shape': ?>
                            <div style="<?= $elStyle ?>"></div>
                        <?php break;
                        case 'text': ?>
                            <div style="<?= $elStyle ?>"><?= htmlspecialchars($el['text']) ?></div>
                        <?php break;
                    endswitch;
                endforeach; ?>
            </div>
        <?php else: ?>
            <div style="padding:10px;border:1px dashed #f00;color:#f00;font-size:11px;">Template custom tidak ditemukan (mungkin sudah dihapus).</div>
        <?php endif; ?>
        <?php elseif ($desain == 'default'):
        ?>
            <!-- DESAIN DEFAULT -->
            <div style="width:180px;min-width:150px;max-width:98vw;height:120px;min-height:100px;max-height:180px;background-image:url('/assets/bg.png');background-size:cover;border:1px solid #444;border-radius:8px;display:flex;overflow:hidden;box-sizing:border-box;<?= $custom_style ?>" data-voucher id="voucher-<?= $i ?>">
                <div style="flex:1;padding:7px;color:#000;display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;">
                    <div>
                        <img src="<?= htmlspecialchars($logo_path) ?>" style="width:28px;max-width:40px;max-height:28px;object-fit:contain;">
                        <div style="font-weight:bold;font-size:10px;line-height:1.1;word-break:break-all;"><?= htmlspecialchars($namapaket) ?></div>
                        <div style="font-size:9px;">VOUCHER: <span style="background:#eee;padding:1px 3px;border-radius:3px;font-size:9px;word-break:break-all;"><?= $usernamevc ?></span></div>
                    </div>
                    <div style="display:flex;gap:5px;align-items:center;">
                        <img src="<?= $qr_url ?>" style="width:38px;height:38px;max-width:40px;max-height:40px;object-fit:contain;">
                        <div style="font-size:9px;line-height:1.1;">
                            <div>Masa Aktif: <?= $uptime ?></div>
                            <div>Durasi: <?= $ratelimit ?></div>
                            <div style="color:red;font-size:8px;">Unlimited!</div>
                            <div style="font-weight:bold;font-size:9px;">CS:<?= strtoupper($nocs) ?></div>
                        </div>
                    </div>
                </div>
                <div style="width:28px;background:orange;color:white;writing-mode:vertical-rl;text-orientation:mixed;text-align:center;font-weight:bold;font-size:10px;display:flex;align-items:center;justify-content:center;overflow:hidden;"> <?= $harga_formatted ?> </div>
            </div>

        <?php elseif ($desain == 'modern'): ?>
            <!-- DESAIN MODERN -->
            <div style="width:130px;min-width:110px;max-width:98vw;height:150px;min-height:120px;max-height:180px;background:linear-gradient(135deg,#6a11cb,#2575fc);color:white;border-radius:7px;padding:7px;box-shadow:0 0 6px rgba(0,0,0,0.12);display:flex;flex-direction:column;justify-content:space-between;box-sizing:border-box;<?= $custom_style ?>" data-voucher id="voucher-<?= $i ?>">
                <div>
                    <div style="margin:0;font-size:10px;font-weight:bold;word-break:break-all;"><?= $ceknama ?></div>
                    <div style="font-size:9px;margin-top:2px;word-break:break-all;"><?= htmlspecialchars($namapaket) ?> - <?= $harga_formatted ?></div>
                </div>
                <div style="margin-top:4px;font-size:9px;">
                    <div><strong>VOUCHER:</strong> <?= $usernamevc ?></div>
                    <div><strong>Durasi:</strong> <?= $ratelimit ?> | Aktif: <?= $uptime ?></div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:4px">
                    <img src="<?= $qr_url ?>" width="28" height="28" style="border-radius:4px;object-fit:contain;">
                    <div style="font-size:8px;text-align:right">
                        Scan login<br>
                        <strong>CS: <?= strtoupper($nocs) ?></strong><br>
                        <?= date('d/m/Y H:i') ?>
                    </div>
                </div>
            </div>
        <?php elseif ($desain == 'elegan'): ?>
            <style>
                .voucher-elegan {
                    width: 120px; min-width:100px; max-width:99vw; min-height:80px; max-height:140px; height:auto;
                    background: linear-gradient(135deg, #6A11CB 0%, #2575FC 100%);
                    color: white;
                    border-radius: 5px;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.10);
                    padding: 6px 5px 8px 6px;
                    font-family: 'Segoe UI', sans-serif;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-start;
                    box-sizing: border-box;
                    overflow: hidden;
                    gap: 2px;
                }
                .voucher-elegan * {box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;}
                .voucher-elegan .top {
                    display: flex;
                    align-items: center;
                    gap: 4px;
                    font-size: 8px;
                    font-weight: bold;
                    margin-bottom: 2px;
                    overflow: hidden;
                    white-space: nowrap;
                    text-overflow: ellipsis;
                }
                .voucher-elegan .top img {
                    width: 18px; max-width: 22px; max-height: 18px; object-fit: contain; flex-shrink: 0;
                }
                .voucher-elegan .paket {
                    font-size: 8px;
                    font-weight: 500;
                    margin-bottom: 2px;
                    overflow: hidden;
                    white-space: nowrap;
                    text-overflow: ellipsis;
                }
                .voucher-elegan .creds {
                    background: rgba(255,255,255,0.2);
                    border-radius: 3px;
                    padding: 2px 4px;
                    margin-bottom: 2px;
                    display: inline-block;
                    font-size: 8px;
                    font-weight: 600;
                    letter-spacing: 0.2px;
                    overflow: hidden;
                    max-width: 100%;
                }
                .voucher-elegan .bottom {
                    display: flex;
                    flex-direction: row;
                    align-items: flex-end;
                    justify-content: space-between;
                    width: 100%;
                    gap: 4px;
                    margin-top: auto;
                    min-height: 0;
                }
                .voucher-elegan .qr-info {
                    font-size: 7px;
                    line-height: 1.1;
                    max-width: 65px;
                    overflow: hidden;
                    word-break: break-word;
                    display: flex;
                    flex-direction: column;
                    gap: 1px;
                    min-height: 0;
                }
                .voucher-elegan .qr-info img {
                    border-radius: 2px;
                    margin-bottom: 2px;
                    width: 18px;
                    height: 18px;
                    object-fit: contain;
                    align-self: flex-start;
                }
                .voucher-elegan .harga {
                    font-weight: bold;
                    font-size: 8px;
                    background: rgba(255,255,255,0.15);
                    padding: 2px 4px;
                    border-radius: 3px;
                    color: #fff;
                    overflow: hidden;
                    max-width: 40px;
                    text-align: right;
                    align-self: flex-end;
                    min-height: 0;
                }
            </style>
            </style>
            <div class="voucher-elegan" data-voucher id="voucher-<?= $i ?>">
                <div class="top">
                    <img src="<?= htmlspecialchars($logo_path) ?>">
                    <span><?= $ceknama ?></span>
                </div>
                <div class="paket"><?= htmlspecialchars($namapaket) ?> - <?= $harga_formatted ?></div>
                <div class="creds">Voucher: <?= htmlspecialchars($usernamevc) ?></div>
                <div class="bottom">
                    <div class="qr-info">
                        <img src="<?= $qr_url ?>" alt="QR">
                        <span>Aktif: <?= $uptime ?></span>
                        <span>Durasi: <?= $ratelimit ?></span>
                        <span>CS: <?= strtoupper($nocs) ?></span>
                    </div>
                    <div class="harga">
                        <?= $harga_formatted ?>
                    </div>
                </div>
            </div>

        <?php elseif ($desain == 'fatirnet'): ?>
            <style>
                .voucher-fatir {
                    width: 120px;min-width:100px;max-width:99vw;height:100px;min-height:80px;max-height:140px;
                    border: 1px solid #333;
                    border-radius: 5px;
                    font-family: 'Segoe UI', sans-serif;
                    position: relative;
                    padding: 6px 5px 8px 6px;
                    box-shadow: 0 0 3px rgba(0,0,0,0.10);
                    background: #fff;
                    overflow: hidden;
                    box-sizing:border-box;
                }
                .voucher-fatir * {box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;}
                .voucher-fatir .top {display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;font-size:8px;overflow:hidden;gap:2px;}
                .voucher-fatir .logo img {width:18px;max-width:22px;max-height:18px;object-fit:contain;}
                .voucher-fatir .harga {font-weight:bold;font-size:8px;color:#2639f5;overflow:hidden;max-width:40px;text-align:right;}
                .voucher-fatir .kode {text-align:center;margin:4px 0 2px;font-size:8px;font-weight:bold;letter-spacing:0.5px;color:#666;overflow:hidden;}
                .voucher-fatir .kode-value {text-align:center;font-size:9px;font-weight:bold;color:#000;letter-spacing:1px;overflow:hidden;}
                .voucher-fatir .warning {text-align:center;font-size:7px;margin-top:2px;color:#222;overflow:hidden;}
                .voucher-fatir .bottom {display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;margin-top:2px;overflow:hidden;gap:2px;}
                .voucher-fatir .info {font-size:7px;line-height:1.1;max-width:65px;overflow:auto;word-break:break-word;}
                .voucher-fatir .qr img {border:1px solid #ddd;border-radius:2px;width:18px;height:18px;object-fit:contain;}
                .voucher-fatir .cp {position:absolute;bottom:0;left:0;width:100%;background:#2639f5;color:#fff;font-size:8px;font-weight:bold;padding:2px 4px;overflow:hidden;}
            </style>
            <div class="voucher-fatir" data-voucher id="voucher-<?= $i ?>">
                <div class="top">
                    <div class="logo"><img src="<?= htmlspecialchars($logo_path) ?>"></div>
                    <div class="harga"><?= $harga_formatted ?></div>
                </div>
                <div class="kode">KODE</div>
                <div class="kode-value"><?= htmlspecialchars($usernamevc) ?></div>
                <div class="bottom">
                    <div class="info">
                        MASA AKTIF: <?= $uptime ?><br>
                        Durasi: <?= $ratelimit ?>
                    </div>
                    <div class="qr">
                        <img src="<?= $qr_url ?>">
                    </div>
                </div>
                <div class="cp">CP: <?= strtoupper($nocs) ?></div>
            </div>
        <?php elseif ($desain == 'bizfiber'): ?>
            <style>
                .voucher-bizfiber {
                    width: 120px;min-width:100px;max-width:99vw;height:100px;min-height:80px;max-height:140px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    padding: 6px 5px 8px 6px;
                    font-family: 'Segoe UI', sans-serif;
                    background: #fff;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
                    position: relative;
                    box-sizing:border-box;
                    overflow: visible;
                }
                .voucher-bizfiber * {box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;}
                .voucher-bizfiber .header {display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;font-size:8px;margin-bottom:2px;overflow:hidden;gap:2px;}
                .voucher-bizfiber .header .logo img {width:18px;max-width:22px;max-height:18px;object-fit:contain;}
                .voucher-bizfiber .header .harga {font-weight:bold;color:#007BFF;font-size:8px;overflow:hidden;max-width:40px;text-align:right;}
                .voucher-bizfiber .label {font-weight:bold;font-size:7px;margin-top:2px;color:#333;overflow:hidden;}
                .voucher-bizfiber .kode {font-size:9px;font-weight:bold;color:#007BFF;margin:1px 0 3px;overflow:hidden;}
                .voucher-bizfiber .flexbox {display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-start;overflow:visible;gap:2px;}
                .voucher-bizfiber .info {font-size:7px;line-height:1.1;color:#333;max-width:65px;overflow:auto;word-break:break-word;}
                .voucher-bizfiber .qr img {border:1px solid #ccc;border-radius:2px;width:18px;height:18px;object-fit:contain;}
                .voucher-bizfiber .warning {background:#007BFF;color:white;font-weight:bold;font-size:7px;text-align:center;position:absolute;bottom:0;left:0;width:100%;padding:2px;border-bottom-left-radius:4px;border-bottom-right-radius:4px;overflow:hidden;}
            </style>
            <div class="voucher-bizfiber" data-voucher id="voucher-<?= $i ?>">
                <div class="header">
                    <div class="logo"><img src="<?= htmlspecialchars($logo_path) ?>"> </div>
                    <div class="harga"><?= $harga_formatted ?></div>
                </div>
                <div class="label">KODE VOUCHER</div>
                <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
                <div class="flexbox">
                    <div class="info">
                        Masa Aktif: <?= $uptime ?><br>
                        WA: <?= strtoupper($nocs) ?>
                    </div>
                    <div class="qr">
                        <img src="<?= $qr_url ?>">
                    </div>
                </div>
                <div class="warning">JANGAN DIBUANG SELAMA MASIH AKTIF</div>
            </div>
        <?php elseif ($desain == 'bizfiber-bg'): ?>
            <style>
                .voucher-bizfiber-bg {
                    width: 120px; min-width:100px; max-width:99vw; min-height:80px; max-height:140px; height:auto;
                    background: url('<?= $bg_path ?>') center center no-repeat;
                    background-size: cover;
                    border-radius: 4px;
                    position: relative;
                    overflow: hidden;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.13);
                    font-family: 'Segoe UI', sans-serif;
                    color: #fff;
                    box-sizing:border-box;
                }
                .voucher-bizfiber-bg * {box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;}
                .voucher-bizfiber-bg::before {content:'';position:absolute;inset:0;background:rgba(0,0,0,0.4);z-index:1;}
                .voucher-bizfiber-bg .content {
                    position:relative;z-index:2;padding:8px 8px 8px 8px;flex:1 1 auto;min-height:0;display:flex;flex-direction:column;justify-content:flex-start;overflow:hidden;gap:2px;
                }
                .voucher-bizfiber-bg .header {
                    display:flex;align-items:center;gap:6px;font-size:8px;font-weight:bold;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;
                }
                .voucher-bizfiber-bg .header img {width:18px;max-width:22px;max-height:18px;object-fit:contain;flex-shrink:0;}
                .voucher-bizfiber-bg .label {font-size:7px;margin-top:2px;color:#eee;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
                .voucher-bizfiber-bg .kode {font-size:8px;font-weight:bold;color:#00eaff;margin-bottom:2px;overflow:hidden;}
                .voucher-bizfiber-bg .info-qr-row {
                    display: flex;
                    flex-direction: row;
                    align-items: flex-end;
                    justify-content: space-between;
                    width: 100%;
                    gap: 4px;
                    margin-top: auto;
                }
                .voucher-bizfiber-bg .info {
                    font-size:7px;line-height:1.1;max-width:65px;overflow:auto;word-break:break-word;display:flex;flex-direction:column;gap:1px;
                }
                .voucher-bizfiber-bg .qr img {border:1px solid #fff;border-radius:2px;width:14px;height:14px;object-fit:contain;}
                .voucher-bizfiber-bg .warning {background:#00aaff;color:white;text-align:center;padding:2px 3px;font-size:7px;font-weight:bold;border-radius:3px;margin-top:2px;overflow:hidden;}
            </style>
            <div class="voucher-bizfiber-bg" data-voucher id="voucher-<?= $i ?>">
                <div class="content">
                    <div class="header">
                        <img src="<?= htmlspecialchars($logo_path) ?>">
                        <span><?= $harga_formatted ?></span>
                    </div>
                    <div class="label">KODE VOUCHER</div>
                    <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
                    <div class="info-qr-row">
                        <div class="info">
                            <span>Masa Aktif: <?= $uptime ?></span>
                            <span>WA: <?= strtoupper($nocs) ?></span>
                        </div>
                        <div class="qr">
                            <img src="<?= $qr_url ?>">
                        </div>
                    </div>
                    <div class="warning">JANGAN DIBUANG SELAMA MASIH AKTIF</div>
                </div>
            </div>
        <?php elseif ($desain == 'bgimg'): ?>
            <style>
                .voucher-bgimg {
                    width: 110px;min-width:90px;max-width:98vw;min-height:50px;max-height:90px;height:auto;
                    background: url('<?= $bg_path ?>') center center no-repeat;
                    background-size: cover;
                    border-radius: 4px;
                    font-family: 'Segoe UI', sans-serif;
                    color: #fff;
                    box-shadow: 0 2px 6px rgba(0,0,0,0.13);
                    position: relative;
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    box-sizing:border-box;
                }
                .voucher-bgimg::before {content:'';position:absolute;inset:0;background:rgba(0,0,0,0.55);}
                .voucher-bgimg .content {position:relative;z-index:2;padding:3px 5px;display:flex;flex-direction:column;justify-content:space-between;flex:1 1 auto;min-height:0;overflow:hidden;}
                .voucher-bgimg .top {display:flex;justify-content:space-between;align-items:flex-start;font-size:8px;font-weight:bold;}
                .voucher-bgimg .paket {font-size:7px;margin-top:1px;line-height:1.1;}
                .voucher-bgimg .kode {font-size:9px;font-weight:bold;margin:2px 0;letter-spacing:0.5px;background:rgba(255,255,255,0.2);padding:2px 3px;border-radius:2px;width:fit-content;}
                .voucher-bgimg .bottom {display:flex;justify-content:space-between;align-items:flex-end;}
                .voucher-bgimg .info {font-size:7px;line-height:1.1;}
                .voucher-bgimg .qr img {width:14px;height:14px;border:1px solid #fff;border-radius:2px;object-fit:contain;}
            </style>
            <div class="voucher-bgimg" data-voucher id="voucher-<?= $i ?>">
                <div class="content">
                    <div class="top">
                        <div><?= $ceknama ?></div>
                        <div><?= $harga_formatted ?></div>
                    </div>
                    <div class="paket"><?= htmlspecialchars($namapaket) ?></div>
                    <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
                    <div class="bottom">
                        <div class="info">
                            Aktif: <?= $uptime ?><br>
                            Limit: <?= $ratelimit ?><br>
                            CS: <?= strtoupper($nocs) ?>
                        </div>
                        <div class="qr">
                            <img src="<?= $qr_url ?>" alt="QR Code">
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($desain == 'bwstandard'): ?>
            <style>
                .voucher-bwstandard {
                    width: 120px; min-width:100px; max-width:99vw; height:100px; min-height:80px; max-height:140px;
                    border: 1px dashed #000;
                    padding: 8px 8px 8px 8px;
                    font-family: 'Arial', sans-serif;
                    color: #000;
                    background: #fff;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-start;
                    box-sizing: border-box;
                    overflow: hidden;
                    gap: 2px;
                }
                .voucher-bwstandard * {box-sizing:border-box;word-break:break-word;overflow-wrap:break-word;}
                .voucher-bwstandard .header {
                    font-weight:bold;font-size:8px;display:flex;align-items:center;gap:4px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;
                }
                .voucher-bwstandard .paket {font-size:7px;margin:1px 0;font-weight:normal;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}
                .voucher-bwstandard .kode {font-size:8px;font-weight:bold;text-align:center;border:1px solid #000;padding:2px;letter-spacing:0.5px;margin-bottom:2px;overflow:hidden;}
                .voucher-bwstandard .footer {
                    display:flex;flex-direction:row;align-items:flex-end;justify-content:space-between;font-size:7px;overflow:hidden;gap:4px;width:100%;margin-top:auto;
                }
                .voucher-bwstandard .info {
                    line-height:1.1;max-width:65px;overflow:auto;word-break:break-word;display:flex;flex-direction:column;gap:1px;
                }
                .voucher-bwstandard .qr img {border:1px solid #000;border-radius:1px;width:14px;height:14px;object-fit:contain;}
            </style>
            <div class="voucher-bwstandard" data-voucher id="voucher-<?= $i ?>">
                <div class="header">
                    <div><?= strtoupper($ceknama) ?></div>
                    <div><?= $harga_formatted ?></div>
                </div>
                <div class="paket"><?= htmlspecialchars($namapaket) ?></div>
                <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
                <div class="footer">
                    <div class="info">
                        Aktif: <?= $uptime ?><br>
                        Limit: <?= $ratelimit ?><br>
                        CS: <?= strtoupper($nocs) ?>
                    </div>
                    <div class="qr">
                        <img src="<?= $qr_url ?>" alt="QR">
                    </div>
                </div>
            </div>
        <?php elseif ($desain == 'thermal'): ?>
            <style>
                .voucher-thermal {
                    width: 110px;min-width:90px;max-width:98vw;
                    font-family: 'Courier New', monospace;
                    font-size:7px;color:#000;background:#fff;border:1px dashed #000;padding:3px;margin-bottom:3px;box-sizing:border-box;
                }
                .voucher-thermal .title {font-size:9px;font-weight:bold;text-align:center;margin-bottom:2px;}
                .voucher-thermal .line {border-top:1px dashed #000;margin:2px 0;}
                .voucher-thermal .label {font-weight:bold;}
                .voucher-thermal .qrcode {text-align:center;margin-top:2px;}
                .voucher-thermal .footer {text-align:center;margin-top:2px;font-size:7px;}
            </style>
            <div class="voucher-thermal" data-voucher id="voucher-<?= $i ?>">
                <div class="title"><?= strtoupper($ceknama) ?></div>
                <div style="text-align:center"><?= htmlspecialchars($namapaket) ?> - <?= $harga_formatted ?></div>
                <div class="line"></div>
                <div><span class="label">Kode:</span> <?= htmlspecialchars($usernamevc) ?></div>
                <div><span class="label">Masa Aktif:</span> <?= $uptime ?></div>
                <div><span class="label">Limit:</span> <?= $ratelimit ?></div>
                <div class="qrcode">
                    <img src="<?= $qr_url ?>" width="22" alt="QR Code" style="max-width:22px;max-height:22px;object-fit:contain;">
                </div>
                <div class="footer">
                    <?= strtoupper($nocs) ?><br>
                    <?= date('d/m/Y H:i') ?>
                </div>
            </div>
        <?php elseif ($desain == 'thermal2'): ?>
            <style>
                .voucher-thermal2 {
                    width: 110px;min-width:90px;max-width:98vw;padding:3px 5px;font-family:monospace;font-size:7px;background:#fff;color:#000;border:1px dotted #000;margin-bottom:3px;box-sizing:border-box;
                }
                .voucher-thermal2 .center {text-align:center;}
                .voucher-thermal2 .bold {font-weight:bold;}
                .voucher-thermal2 .qr {text-align:center;margin-top:2px;}
                .voucher-thermal2 .line {border-top:1px dashed #000;margin:2px 0;}
            </style>
            <div class="voucher-thermal2" data-voucher id="voucher-<?= $i ?>">
                <div class="center bold"><?= strtoupper($ceknama) ?></div>
                <div class="center"><?= htmlspecialchars($namapaket) ?></div>
                <div class="center"><?= $harga_formatted ?></div>
                <div class="line"></div>
                <div><b>User:</b> <?= htmlspecialchars($usernamevc) ?></div>
                <div><b>Pass:</b> <?= htmlspecialchars($usernamevc) ?></div>
                <div><b>Durasi:</b> <?= $ratelimit ?></div>
                <div><b>Masa Aktif:</b> <?= $uptime ?></div>
                <div class="qr">
                    <img src="<?= $qr_url ?>" width="18" height="18" alt="QR" style="max-width:18px;max-height:18px;object-fit:contain;">
                </div>
                <div class="center" style="margin-top:2px">
                    <?= strtoupper($nocs) ?><br>
                    <?= date('d/m/Y H:i') ?>
                </div>
            </div>
        <?php elseif ($desain == 'thermal-logo'): ?>
            <style>
                .voucher-thermal-logo {
                    width: 110px;min-width:90px;max-width:98vw;font-family:monospace;font-size:7px;background:#fff;color:#000;border:1px dashed #000;padding:3px;margin-bottom:3px;box-sizing:border-box;
                }
                .voucher-thermal-logo .center {text-align:center;}
                .voucher-thermal-logo .bold {font-weight:bold;}
                .voucher-thermal-logo img.logo {width:18px;margin-bottom:2px;max-width:22px;max-height:18px;object-fit:contain;}
                .voucher-thermal-logo .line {border-top:1px dashed #000;margin:2px 0;}
                .voucher-thermal-logo .qr {text-align:center;margin-top:2px;}
                .voucher-thermal-logo .footer {text-align:center;font-size:7px;margin-top:2px;}
            </style>
            <div class="voucher-thermal-logo" data-voucher id="voucher-<?= $i ?>">
                <div class="center">
                    <img src="<?= $logo_path ?>" class="logo" alt="Logo">
                    <div class="bold"><?= strtoupper($ceknama) ?></div>
                    <div><?= htmlspecialchars($namapaket) ?> - <?= $harga_formatted ?></div>
                </div>
                <div class="line"></div>
                <div><b>USER:</b> <?= htmlspecialchars($usernamevc) ?></div>
                <div><b>PASS:</b> <?= htmlspecialchars($usernamevc) ?></div>
                <div><b>MASA AKTIF:</b> <?= $uptime ?></div>
                <div><b>LIMIT:</b> <?= $ratelimit ?></div>
                <div class="qr">
                    <img src="<?= $qr_url ?>" width="18" height="18" alt="QR Code" style="max-width:18px;max-height:18px;object-fit:contain;">
                </div>
                <div class="footer">
                    <?= strtoupper($nocs) ?><br>
                    <?= date('d/m/Y H:i') ?>
                </div>
            </div>
      
<?php elseif ($desain == 'modern-gradient'): ?>
<style>
    .voucher-modern-gradient {
    width: 120px;
    min-height: 100px;
    max-height: 140px;
    height: auto;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 5px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.10);
    font-family: 'Segoe UI', 'Poppins', sans-serif;
    color: #fff;
    padding: 6px 5px 8px 6px;
    display: flex;
    flex-direction: column;
    }
    
    .voucher-modern-gradient::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(30deg);
        z-index: 1;
    }
    
    .voucher-modern-gradient .content {
    position: relative;
    z-index: 2;
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    }
    
    .voucher-modern-gradient .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
    margin-bottom: 4px;
    }
    
    .voucher-modern-gradient .logo {
    filter: brightness(0) invert(1);
    }
    
    .voucher-modern-gradient .price {
    font-size: 8px;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 4px;
    border-radius: 8px;
    }
    
    .voucher-modern-gradient .label {
    font-size: 7px;
    opacity: 0.8;
    margin-top: 2px;
    letter-spacing: 0.5px;
    }
    
    .voucher-modern-gradient .kode {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 0.5px;
    margin: 2px 0 4px;
    color: #fff;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
    }
    
    .voucher-modern-gradient .info-section {
    display: flex;
    justify-content: space-between;
    margin-top: auto;
    }
    
    .voucher-modern-gradient .info {
    font-size: 7px;
    line-height: 1.1;
    opacity: 0.9;
    }
    
    .voucher-modern-gradient .qr {
    background: white;
    padding: 2px;
    border-radius: 3px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }
    
    .voucher-modern-gradient .warning {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    text-align: center;
    padding: 2px;
    font-size: 7px;
    font-weight: 600;
    border-radius: 3px;
    margin-top: 4px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    }
</style>

<div class="voucher-modern-gradient" data-voucher id="voucher-<?= $i ?>">
    <div class="content">
        <div class="header">
            <div><img src="<?= $logo_path ?>" width="18" class="logo"></div>
            <div class="price"><?= $harga_formatted ?></div>
        </div>
        <div class="label">KODE VOUCHER</div>
        <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
        <div class="info-section">
            <div class="info">
                Masa Aktif: <?= $uptime ?><br>
                WA: <?= strtoupper($nocs) ?>
            </div>
            <div class="qr">
                <img src="<?= $qr_url ?>" width="18" height="18">
            </div>
        </div>
        <div class="warning">JANGAN DIBUANG SELAMA MASIH AKTIF</div>
    </div>
</div>

<?php elseif ($desain == 'dark-elegant'): ?>
<style>
    .voucher-dark-elegant {
    width: 120px;
    min-height: 100px;
    max-height: 140px;
    height: auto;
        background: #1a1a2e;
        border-radius: 5px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,0.10);
        font-family: 'Segoe UI', 'Inter', sans-serif;
        color: #fff;
        padding: 6px 5px 8px 6px;
        display: flex;
        flex-direction: column;
        border: 1px solid #2d3047;
    }
    
    .voucher-dark-elegant::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #ff6b6b, #48cae4, #4361ee);
    }
    
    .voucher-dark-elegant .content {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    }
    
    .voucher-dark-elegant .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    
    .voucher-dark-elegant .price {
        font-size: 8px;
        font-weight: 700;
        color: #48cae4;
    }
    
    .voucher-dark-elegant .label {
        font-size: 7px;
        color: #a0a0b0;
        margin-top: 2px;
        letter-spacing: 0.5px;
    }
    
    .voucher-dark-elegant .kode {
        font-size: 8px;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin: 2px 0 4px;
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
        padding: 2px 4px;
        border-radius: 3px;
        border: 1px solid #2d3047;
    }
    
    .voucher-dark-elegant .info-section {
        display: flex;
        justify-content: space-between;
        margin-top: auto;
    }
    
    .voucher-dark-elegant .info {
        font-size: 7px;
        line-height: 1.1;
        color: #c0c0d0;
    }
    
    .voucher-dark-elegant .qr {
        background: white;
        padding: 2px;
        border-radius: 3px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
    }
    
    .voucher-dark-elegant .warning {
        background: rgba(255, 77, 77, 0.15);
        color: #ff6b6b;
        text-align: center;
        padding: 2px;
        font-size: 7px;
        font-weight: 600;
        border-radius: 3px;
        margin-top: 4px;
        border: 1px solid rgba(255, 107, 107, 0.2);
    }
</style>

<div class="voucher-dark-elegant" data-voucher id="voucher-<?= $i ?>">
    <div class="content">
        <div class="header">
            <div><img src="<?= htmlspecialchars($logo_path) ?>" width="18"></div>
            <div class="price"><?= $harga_formatted ?></div>
        </div>
        <div class="label">KODE VOUCHER</div>
        <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
        <div class="info-section">
            <div class="info">
                Masa Aktif: <?= $uptime ?><br>
                WA: <?= strtoupper($nocs) ?>
            </div>
            <div class="qr">
                <img src="<?= $qr_url ?>" width="18" height="18">
            </div>
        </div>
        <div class="warning">JANGAN DIBUANG SELAMA MASIH AKTIF</div>
    </div>
</div>

        <?php elseif ($desain == 'simple-box'): ?>
        <style>
            .voucher-simple-box {
                width: 120px;
                min-height: 70px;
                max-width: 98vw;
                background: #fff;
                border: 1.5px solid #222;
                border-radius: 3px;
                font-family: 'Segoe UI', 'Arial', sans-serif;
                color: #222;
                padding: 6px 6px 4px 6px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                box-sizing: border-box;
                margin: 0;
            }
            .voucher-simple-box .top {
                font-size: 8px;
                font-weight: bold;
                margin-bottom: 2px;
            }
            .voucher-simple-box .kode {
                font-size: 11px;
                font-weight: bold;
                letter-spacing: 1px;
                text-align: center;
                margin: 2px 0 2px 0;
                border: 1px dashed #888;
                border-radius: 2px;
                padding: 2px 0;
                background: #f8f8f8;
            }
            .voucher-simple-box .info {
                font-size: 7px;
                color: #333;
                margin-bottom: 2px;
                text-align: left;
            }
            .voucher-simple-box .harga {
                font-size: 8px;
                font-weight: bold;
                color: #007BFF;
                text-align: right;
            }
        </style>
    <div class="voucher-simple-box" style="<?= $custom_style ?>" data-voucher style="" id="<?= $voucher_id ?>">
            <div class="top">RR Group Hotspot</div>
            <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
            <div class="info">
                Aktif: <?= date('H:i', time()) ?> | Durasi: <?= htmlspecialchars($uptime) ?><br>
                Login: <?= htmlspecialchars($login) ?>
            </div>
            <div class="harga"><?= $harga_formatted ?></div>
        </div>

        <?php elseif ($desain == 'simple-qr'): ?>
        <style>
            .voucher-simple-qr {
                width: 120px;
                min-height: 80px;
                max-width: 98vw;
                background: #fff;
                border: 1.5px solid #1a8c2c;
                border-radius: 3px;
                font-family: 'Segoe UI', 'Arial', sans-serif;
                color: #222;
                padding: 5px 5px 4px 5px;
                display: flex;
                flex-direction: column;
                align-items: center;
                box-sizing: border-box;
                margin: 0;
            }
            .voucher-simple-qr .header {
                font-size: 8px;
                font-weight: bold;
                margin-bottom: 2px;
                text-align: center;
            }
            .voucher-simple-qr .qr {
                margin: 2px 0 2px 0;
            }
            .voucher-simple-qr .qr img {
                width: 38px;
                height: 38px;
                border: 1px solid #ccc;
                border-radius: 2px;
                background: #fff;
            }
            .voucher-simple-qr .kode {
                font-size: 10px;
                font-weight: bold;
                letter-spacing: 1px;
                text-align: center;
                margin: 2px 0 2px 0;
                border: 1px dashed #1a8c2c;
                border-radius: 2px;
                padding: 2px 0;
                background: #f8fff8;
            }
            .voucher-simple-qr .info {
                font-size: 7px;
                color: #333;
                margin-bottom: 2px;
                text-align: center;
            }
            .voucher-simple-qr .harga {
                font-size: 8px;
                font-weight: bold;
                color: #1a8c2c;
                text-align: right;
            }
        </style>
    <div class="voucher-simple-qr" style="<?= $custom_style ?>" data-voucher style="" id="<?= $voucher_id ?>">
            <div class="header">RR GROUP</div>
            <div class="qr"><img src="<?= $qr_url ?>" alt="QR"></div>
            <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
            <div class="info">
                <?= htmlspecialchars($namapaket) ?> | <?= $harga_formatted ?><br>
                Masa Aktif: <?= htmlspecialchars($uptime) ?>
            </div>
            <div class="harga"><?= $harga_formatted ?></div>
        </div>

        <?php elseif ($desain == 'minimal-light'): ?>
<style>
    .voucher-minimal-light {
    width: 120px;
    min-height: 100px;
    max-height: 140px;
    height: auto;
        background: #ffffff;
        border-radius: 5px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,0.10);
        font-family: 'Segoe UI', 'Inter', sans-serif;
        color: #333;
        padding: 6px 5px 8px 6px;
        display: flex;
        flex-direction: column;
        border: 1px solid #f0f0f0;
    }
    
    .voucher-minimal-light .content {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    }
    
    .voucher-minimal-light .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 4px;
    }
    
    .voucher-minimal-light .price {
        font-size: 8px;
        font-weight: 700;
        color: #4361ee;
    }
    
    .voucher-minimal-light .label {
        font-size: 7px;
        color: #666;
        margin-top: 2px;
        letter-spacing: 0.5px;
    }
    
    .voucher-minimal-light .kode {
        font-size: 8px;
        font-weight: 800;
        letter-spacing: 0.5px;
        margin: 2px 0 4px;
        color: #333;
        background: #f8f9fa;
        padding: 2px 4px;
        border-radius: 3px;
        text-align: center;
        border: 1px dashed #ccc;
    }
    
    .voucher-minimal-light .info-section {
        display: flex;
        justify-content: space-between;
        margin-top: auto;
    }
    
    .voucher-minimal-light .info {
        font-size: 7px;
        line-height: 1.1;
        color: #666;
    }
    
    .voucher-minimal-light .qr {
        background: white;
        padding: 2px;
        border-radius: 3px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        border: 1px solid #eee;
    }
    
    .voucher-minimal-light .warning {
        background: #fff3cd;
        color: #856404;
        text-align: center;
        padding: 2px;
        font-size: 7px;
        font-weight: 600;
        border-radius: 3px;
        margin-top: 4px;
        border: 1px solid #ffeaa7;
    }
</style>

<div class="voucher-minimal-light">
    <div class="content">
        <div class="header">
            <div><img src="<?= htmlspecialchars($logo_path) ?>" width="18"></div>
            <div class="price"><?= $harga_formatted ?></div>
        </div>
        <div class="label">KODE VOUCHER</div>
        <div class="kode"><?= htmlspecialchars($usernamevc) ?></div>
        <div class="info-section">
            <div class="info">
                Masa Aktif: <?= $uptime ?><br>
                WA: <?= strtoupper($nocs) ?>
            </div>
            <div class="qr">
                <img src="<?= $qr_url ?>" width="18" height="18">
            </div>
        </div>
        <div class="warning">JANGAN DIBUANG SELAMA MASIH AKTIF</div>
    </div>
</div>






  <?php endif; ?>

    <?php
    endfor;
    ?>
</div>

<?php
if ($printulang=='') {
if (isset($_POST['cetak'])) {
    $data = $_POST;
    $data['timestamp'] = date('Y-m-d H:i:s');


    // Generate voucher
    for ($i = 1; $i <= $jumlah; $i++) {
    }

    $data['vouchers'] = $list_voucher;

    // Generate random number sequence for filename
    $random_seq = mt_rand(1000, 9999);

    // Nama file dengan urutan angka random dan nama yang dicek
    $namafile = "{$ceknama}-{$random_seq}-data-voucher.json";
    $filepath = "voucher/{$namafile}";

    // Simpan ke file
    if (!is_dir('voucher')) mkdir('voucher', 0777, true);
    file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));


echo "
<br><br>
<a href='cetak-voucher.php?file=$namafile' class='print-btn d-flex align-items-center gap-2'>
    <span class='btn-icon'>
        <svg width='18' height='18' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'>
            <path d='M6 9V2H18V9' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' />
            <path d='M6 18H4C2.89543 18 2 17.1046 2 16V11C2 9.89543 2.89543 9 4 9H20C21.1046 9 22 9.89543 22 11V16C22 17.1046 21.1046 18 20 18H18' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' />
            <path d='M18 14H6V22H18V14Z' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' />
        </svg>
    </span>
    <span class='btn-text'>AKTIFASI VOUCHER</span>
</a>
";
}
}

?> <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Optional: Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<style>
    .voucher-grid {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 18px;
        margin: 20px 10px;
    }

    @media print {
        body {
            margin: 0;
        }
    }
</style>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Optional: Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">



<style>
    .voucher-form {
        max-width: 400px;
        margin: 0 auto;
        padding: 25px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #2d3748;
        font-size: 14px;
    }

    .select-wrapper {
        position: relative;
    }

    .design-select {
        width: 100%;
        padding: 12px 16px;
        padding-right: 40px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background-color: #f8fafc;
        color: #1a202c;
        font-size: 14px;
        appearance: none;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .design-select:focus {
        outline: none;
        border-color: #4299e1;
        box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.2);
        background-color: #ffffff;
    }

    .select-arrow {
        position: absolute;
        top: 50%;
        right: 16px;
        transform: translateY(-50%);
        pointer-events: none;
    }

    .print-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 12px 20px;
        background-color: #4299e1;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 6px rgba(66, 153, 225, 0.2);
    }

    .print-btn:hover {
        background-color: #3182ce;
        transform: translateY(-1px);
        box-shadow: 0 6px 8px rgba(66, 153, 225, 0.3);
    }

    .print-btn:active {
        transform: translateY(0);
    }

    .btn-icon {
        margin-right: 10px;
        display: flex;
        align-items: center;
    }
        /* Live update JS */
</style>
<script>
window.addEventListener('DOMContentLoaded', function() {
    // Cari input di parent (karena input ada di cetak.php)
    function getInput(id) { return window.parent ? window.parent.document.getElementById(id) : document.getElementById(id); }
    // Fallback jika include di halaman sendiri
    const paddingInput = getInput('voucher_padding') || document.getElementById('voucher_padding');
    const marginInput = getInput('voucher_margin') || document.getElementById('voucher_margin');
    const gapInput = getInput('voucher_gap') || document.getElementById('voucher_gap');
    const grid = document.getElementById('voucherGrid');
    function updateStyle() {
        const pad = parseInt(paddingInput?.value || 6);
        const mar = parseInt(marginInput?.value || 0);
        const gap = parseInt(gapInput?.value || 0);
        if(grid) grid.style.gap = gap + 'px';
        // Semua voucher simple-box & simple-qr
        document.querySelectorAll('[data-voucher]').forEach(function(el) {
            el.style.padding = pad + 'px';
            el.style.margin = mar + 'px';
        });
    }
    if(paddingInput) paddingInput.addEventListener('input', updateStyle);
    if(marginInput) marginInput.addEventListener('input', updateStyle);
    if(gapInput) gapInput.addEventListener('input', updateStyle);
    // Inisialisasi awal
    setTimeout(updateStyle, 200);
});
</script>