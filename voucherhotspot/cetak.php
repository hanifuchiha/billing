<?php
require '../cek-sesi.php';
$desain = $_POST['desain'] ?? 'default';
$nocs =$_POST['nocs'];
$packages =$_POST['packages'];
$harga =$_POST['harga'];
$login =$_POST['login'];
$printulang='1';
$domain=$config['domain'];
// ------------------ CONFIG ------------------
$uploadDir = __DIR__ . '/../../../dokumen/logo';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

// Logo
if (!empty($_FILES['logo']['name'])) {
    if ($_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg'])) {
            $logo_name = uniqid('logo_') . '.' . $ext;
            $dest_logo = $uploadDir . '/' . $logo_name;
            $logo_path ="https://$domain/dokumen/logo/" . $logo_name;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest_logo)) {
              
            } else echo "❌ Gagal menyimpan logo.\n";
        } else echo "❌ Format logo harus PNG/JPG.\n";
    } else echo "❌ Error saat upload logo: " . $_FILES['logo']['name'] . "\n";
}
else
{
$logo_path = $_POST['logo_path'] ?? 'default';
}
 
// Background
if (!empty($_FILES['bg']['name'])) {
    if ($_FILES['bg']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['bg']['name'], PATHINFO_EXTENSION));
        $bg_name = uniqid('bg_') . '.' . $ext;
        $dest_bg = $uploadDir . '/' . $bg_name;
          $bg_path ="https://$domain/dokumen/logo/" . $bg_name;
        if (move_uploaded_file($_FILES['bg']['tmp_name'], $dest_bg)) {
           
        } else echo "❌ Gagal menyimpan background.\n";
    } else echo "❌ Error saat upload background: " . $_FILES['bg']['error'] . "\n";
}
else
{
$bg_path = $_POST['bg_path'] ?? 'default';

}









// ------------------ PROCESS SELECTED VOUCHERS ------------------
if (!isset($_POST['selected']) || empty($_POST['selected'])) {
    die("❌ Tidak ada voucher yang dicentang.");
}

// Koneksi ke database
require_once '../koneksibilling.php';

$all_vouchers = [];
$first_item_data = null;

// --- FIX: Definisikan $ceknama dan $voucherDir ---
$ceknama = isset($_SESSION['username']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_SESSION['username']) : 'voucher';
$voucherDir = $uploadDir; // Simpan di folder uploads
if (!is_dir($voucherDir)) mkdir($voucherDir, 0777, true);

foreach ($_POST['selected'] as $i => $item_json) {
    $data = json_decode($item_json, true);
    if (!$data) $data = json_decode(stripslashes($item_json), true);
    if (!$data) continue;

    if (!$first_item_data) $first_item_data = $data;

    $output = [
        'desain' => $desain,
        'packages' => $data['packages'] ?? '',
        'radius' => $data['radius'] ?? 'yes',
        'logo_path' => $logo_path ,
        'bg_path' => $bg_path,
        'ip' => $data['ip'] ?? '127.0.0.1',
        'us' => $data['us'] ?? '',
        'ps' => $data['ps'] ?? '',
        'login' => $data['login'] ?? '',
        'jumlah' => $data['jumlah'] ?? 1,
        'uptime' => $data['uptime'] ?? '1d',
        'ratelimit' => $data['ratelimit'] ?? '4m/4m',
        'vouchers' => $data['vouchers'] ?? [],
        'timestamp' => date('Y-m-d H:i:s')
    ];


 // bawa logo_path dan bg_path
   

    // simpan file JSON terpisah
    $fileName = "{$ceknama}-item-{$i}.json";
    file_put_contents($voucherDir . '/' . $fileName, json_encode($output, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

    // gabungkan vouchers
    foreach ($data['vouchers'] as $v) {
        $all_vouchers[] = $v;
        // Update status_cetak voucher di database
        if (isset($v['user'])) {
            $voucher_user = mysqli_real_escape_string($conn, $v['user']);
            @mysqli_query($conn, "UPDATE voucher SET status_cetak=1 WHERE voucher='".$voucher_user."'");
        }
    }
}

// ------------------ COMBINED DATA ------------------
$data = [
    'ip' => $first_item_data['ip'] ?? '127.0.0.1',
    'us' => $first_item_data['us'] ?? '',
    'ps' => $first_item_data['ps'] ?? '',
    'login' => $first_item_data['login'] ?? '',
    'jumlah' => count($all_vouchers),
    'packages' => $first_item_data['packages'] ?? '',
    'uptime' => $first_item_data['uptime'] ?? '1d',
    'ratelimit' => $first_item_data['ratelimit'] ?? '4m/4m',
    'radius' => $first_item_data['radius'] ?? 'yes',

    'desain' => $desain,
    'vouchers' => $all_vouchers
];
 

// echo $bg_path ;
// echo "<br>" ;
// echo $logo_path ;





// ------------------ DISPLAY ------------------
?>

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
</style>
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

<style>
    .cetak-voucher-btn {
        background: linear-gradient(135deg, #4e73df, #1cc88a);
        color: white;
        border: none;
        padding: 0 25px;
        height: 50px;
        font-size: 16px;
        font-weight: bold;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        cursor: pointer;
        transition: 0.3s ease;
    }

    .cetak-voucher-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
    }

    .cetak-voucher-btn:active {
        transform: scale(0.98);
    }
</style>


<style>
    @media print {
        .no-print {
            display: none !important;
        }
    }

    @media print {
        body {
            -webkit-print-color-adjust: exact !important;
            /* Chrome */
            print-color-adjust: exact !important;
            /* CSS4 */
        }

        .cetak-voucher-btn {
            background: #4e73df !important;
            color: white !important;
        }
    }
</style>


<form method="POST" class="voucher-form no-print">
    <div class="form-group">
        <label class="form-label">Pilih Desain Voucher:</label>
        <div class="select-wrapper">
            <select name="desain" onchange="this.form.submit()" class="design-select">
                <?php
                $designs = [
                    'default'=>'Desain BladeNet',
                    'modern'=>'Desain Modern',
                    'dark-elegant'=>'dark-elegant',
                    'modern-gradient'=>'modern-gradient',
                    'minimal-light'=>'minimal-light',
                    'elegan'=>'Desain Elegan',
                    'fatirnet'=>'Desain FatirNet',
                    'bizfiber'=>'Desain BizFiber',
                    'bizfiber-bg'=>'Desain BizFiber + BG',
                    'bgimg'=>'Desain Background Gambar',
                    'bwstandard'=>'Hitam Putih Standard',
                    'thermal'=>'Thermal Printer',
                    'thermal2'=>'Thermal 2 (Minimal)',
                    'thermal-logo'=>'Thermal + Logo',
                    'simple-box'=>'Simple Box',
                    'simple-qr'=>'Simple QR',
                ];
                foreach($designs as $key => $label){
                    $selected_attr = (isset($_POST['desain']) && $_POST['desain'] == $key) ? 'selected' : '';
                    echo "<option value=\"$key\" $selected_attr>$label</option>";
                }

                // Template custom hasil drag-and-drop editor (voucher_template_builder.php),
                // ditandai prefix "custom_" pada value-nya supaya voucher-desain.php tahu
                // harus render dari JSON tersimpan, bukan template bawaan.
                require_once 'voucher_template_helper.php';
                $custom_templates = get_voucher_templates($ceknama ?? '');
                if (!empty($custom_templates)) {
                    echo '<optgroup label="Template Saya (Custom)">';
                    foreach ($custom_templates as $tpl) {
                        $tplKey = 'custom_' . $tpl['id'];
                        $selected_attr = (isset($_POST['desain']) && $_POST['desain'] == $tplKey) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($tplKey) . '" ' . $selected_attr . '>' . htmlspecialchars($tpl['name']) . '</option>';
                    }
                    echo '</optgroup>';
                }
                ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Padding Voucher (px):</label>
        <input type="number" id="voucher_padding" name="voucher_padding" min="0" max="40" value="<?= isset($_POST['voucher_padding']) ? intval($_POST['voucher_padding']) : 6 ?>" class="form-control" style="width:100px;display:inline-block;">
    </div>
    <div class="form-group">
        <label class="form-label">Margin Voucher (px):</label>
        <input type="number" id="voucher_margin" name="voucher_margin" min="0" max="40" value="<?= isset($_POST['voucher_margin']) ? intval($_POST['voucher_margin']) : 0 ?>" class="form-control" style="width:100px;display:inline-block;">
    </div>
    <div class="form-group">
        <label class="form-label">Jarak Antar Voucher (gap px):</label>
        <input type="number" id="voucher_gap" name="voucher_gap" min="0" max="40" value="<?= isset($_POST['voucher_gap']) ? intval($_POST['voucher_gap']) : 0 ?>" class="form-control" style="width:100px;display:inline-block;">
    </div>
<!-- HTML -->

   

<button onclick="window.print()" class="print-btn no-print">
     <span class="btn-icon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 9V2H18V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M6 18H4C2.89543 18 2 17.1046 2 16V11C2 9.89543 2.89543 9 4 9H20C21.1046 9 22 9.89543 22 11V16C22 17.1046 21.1046 18 20 18H18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M18 14H6V22H18V14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span> Cetak Voucher
</button>

   <?php
// bawa semua data lama kecuali desain, logo_path, bg_path
foreach($_POST as $k => $v){
    if(in_array($k, ['desain','voucher_padding','voucher_margin','voucher_gap'])) continue; // skip variabel desain & custom style
    if(is_array($v)){
        foreach($v as $val){
            echo '<input type="hidden" name="'.$k.'[]" value="'.htmlspecialchars($val).'">';
        }
    } else {
        echo '<input type="hidden" name="'.$k.'" value="'.htmlspecialchars($v).'">';
    }
    echo '<input type="hidden" name="packages" value="'.htmlspecialchars($data['packages'] ?? '').'">';
    echo '<input type="hidden" name="radius" value="'.htmlspecialchars($data['radius'] ?? '').'">';
    echo '<input type="hidden" name="logo_path" value="'.htmlspecialchars($logo_path ?? '').'">';
    echo '<input type="hidden" name="bg_path" value="'.htmlspecialchars($bg_path ?? '').'">';
    echo '<input type="hidden" name="ip" value="'.htmlspecialchars($data['ip'] ?? '').'">';
    echo '<input type="hidden" name="us" value="'.htmlspecialchars($data['us'] ?? '').'">';
    echo '<input type="hidden" name="ps" value="'.htmlspecialchars($data['ps'] ?? '').'">';
    echo '<input type="hidden" name="login" value="'.htmlspecialchars($data['login'] ?? '').'">';
    echo '<input type="hidden" name="jumlah" value="'.htmlspecialchars($data['jumlah'] ?? '').'">';
    echo '<input type="hidden" name="uptime" value="'.htmlspecialchars($data['uptime'] ?? '').'">';
    echo '<input type="hidden" name="ratelimit" value="'.htmlspecialchars($data['ratelimit'] ?? '').'">';
    echo '<input type="hidden" name="vouchers" value="'.htmlspecialchars(json_encode($data['vouchers'] ?? []), ENT_QUOTES, 'UTF-8').'">';
    echo '<input type="hidden" name="timestamp" value="'.htmlspecialchars(date('Y-m-d H:i:s') ?? '').'">';
}
// Hidden untuk custom style
echo '<input type="hidden" name="voucher_padding" value="'.htmlspecialchars($_POST['voucher_padding'] ?? 6).'">';
echo '<input type="hidden" name="voucher_margin" value="'.htmlspecialchars($_POST['voucher_margin'] ?? 0).'">';
echo '<input type="hidden" name="voucher_gap" value="'.htmlspecialchars($_POST['voucher_gap'] ?? 0).'">';
?>


</form>

<div class="voucher-grid">
    
<?php
if($packages!='')
{
foreach ($data['vouchers'] as $voucher) {



$_POST = $data;
$_POST['logo_path'] = $logo_path;
$_POST['bg_path']= $bg_path;
$_POST['nocs']=$nocs;
$_POST['packages'] =$packages;
$_POST['login']=$login;


    include 'voucher-desain.php'; // desain voucher pakai logo_path & bg_path
}
}

?>
</div>
