<?php require 'header.php'; ?>

<?php
if (!isset($AKSES) || $AKSES !== 'ADMIN') {
    echo "<div class='container py-4'><div class='alert alert-danger'>Akses ditolak. Menu ini hanya untuk ADMIN.</div></div>";
    require 'footer.php';
    exit;
}

$config_dir = __DIR__ . '/notifdata';
$config_file = $config_dir . '/acs_parameter_display.json';

$default_params = [
    'InternetGatewayDevice.LANDevice.1.Hosts.Host.*.HostName',
    'InternetGatewayDevice.LANDevice.1.Hosts.Host.*.IPAddress',
    'InternetGatewayDevice.LANDevice.1.Hosts.Host.*.InterfaceType',
    'Device.LANDevice.1.Hosts.Host.*.HostName',
    'Device.LANDevice.1.Hosts.Host.*.IPAddress',
    'Device.LANDevice.1.Hosts.Host.*.InterfaceType',
    'VirtualParameters.pppoeUsername',
    'VirtualParameters.pppoeUsername2',
    'VirtualParameters.pppoeIP',
    'VirtualParameters.RXPower',
    'VirtualParameters.TXPower',
];

$saved_params = $default_params;
$updated_at = null;

if (file_exists($config_file)) {
    $raw = json_decode(file_get_contents($config_file), true);
    if (is_array($raw) && isset($raw['params']) && is_array($raw['params'])) {
        $tmp = [];
        foreach ($raw['params'] as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $tmp[] = $p;
            }
        }
        if (!empty($tmp)) {
            $saved_params = array_values(array_unique($tmp));
        }
    }
    $updated_at = $raw['updated_at'] ?? null;
}

if (!is_dir($config_dir)) {
    @mkdir($config_dir, 0777, true);
}

if (isset($_POST['save_acs_param_settings'])) {
    $input = (string)($_POST['acs_params'] ?? '');
    $lines = preg_split('/\r\n|\r|\n/', $input);
    $clean = [];
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $clean[] = $line;
            }
        }
    }
    $clean = array_values(array_unique($clean));

    $payload = [
        'params' => $clean,
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => $ceknama ?? 'ADMIN',
    ];

    if (file_put_contents($config_file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $saved_params = $clean;
        $updated_at = $payload['updated_at'];
        echo "<div class='container-fluid py-2'><div class='alert alert-success'>Pengaturan parameter ACS berhasil disimpan.</div></div>";
    } else {
        echo "<div class='container-fluid py-2'><div class='alert alert-danger'>Gagal menyimpan pengaturan parameter ACS.</div></div>";
    }
}

if (isset($_POST['reset_acs_param_settings'])) {
    $payload = [
        'params' => $default_params,
        'updated_at' => date('Y-m-d H:i:s'),
        'updated_by' => $ceknama ?? 'ADMIN',
    ];
    if (file_put_contents($config_file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
        $saved_params = $default_params;
        $updated_at = $payload['updated_at'];
        echo "<div class='container-fluid py-2'><div class='alert alert-info'>Pengaturan dikembalikan ke default.</div></div>";
    } else {
        echo "<div class='container-fluid py-2'><div class='alert alert-danger'>Gagal reset ke default.</div></div>";
    }
}

$params_text = implode("\n", $saved_params);

// Ambil contoh key dari cache agar admin mudah memilih
$sample_keys = [];
$cache_file = __DIR__ . '/notifdata/acs_devices_cache.json';
if (file_exists($cache_file)) {
    $cache_json = json_decode(file_get_contents($cache_file), true);
    if (is_array($cache_json) && !empty($cache_json['devices']) && is_array($cache_json['devices'])) {
        foreach ($cache_json['devices'] as $dev) {
            $params = $dev['params'] ?? [];
            if (!is_array($params)) {
                continue;
            }
            foreach (array_keys($params) as $k) {
                $sample_keys[$k] = true;
            }
            if (count($sample_keys) >= 300) {
                break;
            }
        }
    }
}
$sample_keys = array_keys($sample_keys);
sort($sample_keys);
?>

<div class="container-fluid py-3">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Pengaturan Parameter ACS (Data ACS Pelanggan)</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">Atur parameter mana saja yang ditampilkan pada card <b>Data ACS</b> di halaman pelanggan.</p>
                    <p class="mb-3 text-muted small">Satu parameter per baris. Bisa gunakan wildcard <code>*</code> untuk nomor index, contoh: <code>InternetGatewayDevice.LANDevice.1.Hosts.Host.*.HostName</code></p>

                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label"><b>Daftar Parameter Ditampilkan</b></label>
                            <textarea name="acs_params" class="form-control" rows="14" placeholder="Contoh:\nVirtualParameters.pppoeUsername\nInternetGatewayDevice.LANDevice.1.Hosts.Host.*.HostName"><?php echo htmlspecialchars($params_text, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="save_acs_param_settings" class="btn btn-primary">Simpan Pengaturan</button>
                            <button type="submit" name="reset_acs_param_settings" class="btn btn-outline-secondary" onclick="return confirm('Reset ke default?');">Reset Default</button>
                        </div>
                    </form>

                    <hr>
                    <div class="small text-muted mb-2">Terakhir diperbarui: <?php echo $updated_at ? htmlspecialchars($updated_at, ENT_QUOTES, 'UTF-8') : '-'; ?></div>

                    <h6 class="mt-3">Contoh Parameter dari Cache ACS</h6>
                    <?php if (!empty($sample_keys)) { ?>
                        <div style="max-height:280px; overflow:auto; border:1px solid #ddd; border-radius:6px; padding:10px; background:#fafafa;">
                            <?php foreach ($sample_keys as $k) { ?>
                                <div class="small" style="line-height:1.5;"><?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        <div class="alert alert-warning mb-0">Belum ada cache ACS. Jalankan ACS Auto-Sync dulu agar contoh parameter muncul.</div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
