<?php
include 'cek_sesi.php';

$idpel_value = '';
if (isset($pelanggan['IDPEL']) && $pelanggan['IDPEL'] !== '') {
    $idpel_value = (string)$pelanggan['IDPEL'];
} elseif (isset($idpel) && $idpel !== '') {
    $idpel_value = (string)$idpel;
} else {
    $idpel_value = (string)($_GET['cari'] ?? '');
}

$idpel_value = trim($idpel_value);

$portalAcsTokenSecret = hash('sha256', (string)($config['db_pass'] ?? '') . '|' . (string)($config['domain'] ?? '') . '|portal-acs');
$portalAcsToken = hash_hmac('sha256', strtolower($idpel_value) . '|' . date('YmdH'), $portalAcsTokenSecret);

// $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WiFi Saya - <?= htmlspecialchars($idpel_value, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #0d6efd;
            --dark-green: #0a58ca;
            --orange: #f7941d;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--white);
            color: #333;
            padding-bottom: 90px;
        }

        .top-wrap {
            max-width: 520px;
            margin: 0 auto;
            padding: 20px 14px;
        }

        .hero {
            background-color: var(--light-gray);
            color: #333;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 14px;
        }

        .hero small {
            opacity: 0.9;
            display: block;
            margin-bottom: 4px;
        }

        .hero h1 {
            font-size: 22px;
            margin: 0;
            font-weight: 700;
            color: var(--primary-green);
        }

        .logo-dynamic {
            max-height: 72px;
            display: block;
            margin: 0 auto 10px;
        }

        .router-icon-wrap {
            text-align: center;
            margin: 2px 0 10px;
        }

        .router-icon {
            width: 150px;
            height: 150px;
            object-fit: contain;
            display: inline-block;
        }

        .hero-title-wrap {
            text-align: center;
        }

        .portal-status {
            text-align: center;
            margin-bottom: 10px;
        }

        .portal-local-summary {
            text-align: center;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
        }

        .ssid-local-summary {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 700;
            color: #4b5563;
        }

        .acs-local-hosts-wrap {
            margin-top: 8px;
            border-top: 1px dashed var(--border-color);
            padding-top: 8px;
        }

        .acs-local-hosts-title {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .acs-local-hosts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }

        .acs-local-host-card {
            background: var(--white);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px;
            font-size: 12px;
            color: #374151;
        }

        .acs-local-host-name {
            font-weight: 700;
            margin-bottom: 4px;
        }

        .acs-local-host-item {
            margin-bottom: 2px;
            word-break: break-word;
        }

        .portal-status .btn-status-online-working {
            background: linear-gradient(180deg, var(--primary-green), var(--dark-green));
            border: 1px solid var(--dark-green);
            color: #fff;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
        }

        .portal-status .btn-status-expired {
            background: linear-gradient(180deg, var(--orange), #d77b00);
            border: 1px solid #c66b00;
            color: #fff;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
        }

        .portal-status .btn-status-online-dhcp {
            background: linear-gradient(180deg, var(--orange), #d77b00);
            border: 1px solid #c66b00;
            color: #fff;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
        }

        .portal-status .btn-status-offline {
            background: #6c757d;
            border: 1px solid #5a6268;
            color: #fff;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
        }

        .acs-card {
            background: var(--light-gray);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .acs-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .acs-meta-item {
            background: var(--white);
            border-radius: 10px;
            padding: 9px 10px;
            min-height: 62px;
            border: 1px solid var(--border-color);
        }

        .acs-meta-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }

        .acs-meta-value {
            font-size: 15px;
            font-weight: 700;
            word-break: break-word;
        }

        .ssid-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .ssid-item {
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 10px;
            background: var(--white);
        }

        .ssid-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }

        .ssid-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--dark-green);
        }

        .ssid-value {
            font-size: 14px;
            margin-bottom: 4px;
            word-break: break-word;
        }

        .ssid-pass {
            font-size: 12px;
            color: #6b7280;
        }

        .device-action-row {
            margin-top: 10px;
            display: flex;
            justify-content: center;
        }

        .page-note {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
            background: var(--light-gray);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px;
        }

        .navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--primary-green);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 100;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.07);
            border-top: 2px solid var(--dark-green);
            min-height: 70px;
            align-items: center;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            color: #fff;
            font-size: 12px;
            border-radius: 8px;
            padding: 5px 8px;
            min-width: 60px;
            outline: none;
            transition: all 0.3s ease;
        }

        .nav-item:hover,
        .nav-item:focus {
            background-color: var(--orange);
            color: #fff;
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0, 0, 0, 0.1);
            border: 2px solid var(--orange);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--orange) 60%, var(--primary-green) 100%);
            border: 2px solid var(--orange);
            font-weight: 700;
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            z-index: 3;
        }

        .nav-icon {
            font-size: 18px;
            margin-bottom: 4px;
        }

        @media (max-width: 640px) {
            .acs-meta-grid,
            .ssid-grid,
            .acs-local-hosts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="top-wrap">
        <div class="hero">
            <img src="<?= htmlspecialchars($logo_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= time(); ?>" class="img-fluid logo-dynamic" id="logoDynamic" alt="Logo">
            <div class="router-icon-wrap">
                <img src="icons8-router-100.png" class="router-icon" alt="Router">
            </div>
            <div id="portalStatusTop" class="portal-status"></div>

            <div class="hero-title-wrap">
                <h1>WiFi Saya</h1>
                <div class="mt-2">ID Pelanggan: <strong id="idpelText"><?= htmlspecialchars($idpel_value, ENT_QUOTES, 'UTF-8'); ?></strong></div>
            </div>
        </div>

        <div id="wifiStatusWrap">
            <div class="acs-card text-center py-4">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2">Memuat data ONT / ROUTER Wifi anda ...</div>
            </div>
        </div>

        <div class="page-note">Setelah mengganti, tunggu 10 menit atau bisa lebih untuk efeknya.</div>
    </div>

    <div class="modal fade" id="editSsidModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit WiFi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editDeviceIdx" value="">
                    <input type="hidden" id="editSsidIndex" value="">
                    <input type="hidden" id="editSsidPath" value="">
                    <input type="hidden" id="editPassPath" value="">
                    <input type="hidden" id="editEnablePath" value="">

                    <label class="form-label" for="editSsidValue">Nama SSID</label>
                    <input type="text" class="form-control" id="editSsidValue" placeholder="Nama SSID">

                    <label class="form-label mt-3" for="editPassValue">Password WiFi</label>
                    <input type="password" class="form-control" id="editPassValue" minlength="8" autocomplete="new-password" placeholder="Kosongkan jika tidak diubah">
                    <div class="form-text">Jika diisi, minimal 8 karakter.</div>

                    <label class="form-label mt-3" for="editEnableSwitch">Status SSID</label>
                    <select class="form-select" id="editEnableSwitch">
                        <option value="1">Enable (Aktif)</option>
                        <option value="0">Disable (Nonaktif)</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="btnSaveWifi">Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="wifiToast" style="display:none;position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:9999;min-width:280px;max-width:90vw;padding:14px 20px;border-radius:10px;font-size:14px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,0.18);text-align:center;transition:opacity 0.3s;"></div>

    <div class="navbar">
        <a href="portal_baru.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
            <div>Beranda</div>
        </a>
        <a href="portal_bayar.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
            <div>Bayar</div>
        </a>
        <a href="portal_chat.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
            <div>Chat</div>
        </a>
        <a href="portal_mywifi.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item active">
            <div class="nav-icon"><i class="bi bi-wifi"></i></div>
            <div>WiFi Saya</div>
        </a>
        <a href="portal_riwayat.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
            <div>Riwayat</div>
        </a>
        <a href="portal_profile.php?cari=<?= urlencode($idpel_value); ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
            <div>Profile</div>
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function extractLogoColors(img) {
            if (!img || !img.complete) return;
            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                canvas.width = img.naturalWidth || 120;
                canvas.height = img.naturalHeight || 120;
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                var colorCounts = {};

                for (var i = 0; i < data.length; i += 12) {
                    var r = data[i];
                    var g = data[i + 1];
                    var b = data[i + 2];
                    var a = data[i + 3];
                    if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
                    var color = r + ',' + g + ',' + b;
                    colorCounts[color] = (colorCounts[color] || 0) + 1;
                }

                var sorted = Object.entries(colorCounts).sort(function(a, b) { return b[1] - a[1]; });
                if (!sorted.length) return;

                var primary = sorted[0][0];
                var secondary = sorted[1] ? sorted[1][0] : primary;
                var accent = sorted[2] ? sorted[2][0] : primary;

                document.documentElement.style.setProperty('--primary-green', 'rgb(' + primary + ')');
                document.documentElement.style.setProperty('--orange', 'rgb(' + secondary + ')');
                document.documentElement.style.setProperty('--dark-green', 'rgb(' + accent + ')');
            } catch (e) {
                console.log('Logo color extraction failed', e);
            }
        }

        var logoEl = document.getElementById('logoDynamic');
        if (logoEl && logoEl.complete) {
            extractLogoColors(logoEl);
        } else if (logoEl) {
            logoEl.onload = function() { extractLogoColors(logoEl); };
        }

        var idpel = <?= json_encode($idpel_value, JSON_UNESCAPED_UNICODE); ?>;
        var portalAcsToken = <?= json_encode($portalAcsToken, JSON_UNESCAPED_UNICODE); ?>;
        var portalStatusUrl = 'fetch_data2.php?idserver=<?= urlencode((string)($idserver ?? '')); ?>&kodeodp=<?= urlencode((string)($pelanggan['ODP'] ?? '')); ?>&idpel=<?= urlencode((string)$idpel_value); ?>';
        var acsDevices = [];
        var portalStatusType = 'offline';

        function escHtml(v) {
            return String(v == null ? '' : v)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function normalizePath(path) {
            return String(path || '').trim();
        }

        function normalizeSsidRefPath(refValue) {
            var ref = String(refValue == null ? '' : refValue).trim();
            if (ref === '') return '';
            ref = ref.replace(/\.$/, '');
            if (/^Device\./i.test(ref) || /^InternetGatewayDevice\./i.test(ref)) return ref;
            if (/^WiFi\./i.test(ref)) return 'Device.' + ref;
            return ref;
        }

        function isAcsMetaParam(path) {
            return /(_writable|\.writable|_object|\.object)$/i.test(String(path || ''));
        }

        function extractSsidIndexFromPath(path, fallbackIndex) {
            var textPath = String(path || '');
            var tr098Match = textPath.match(/\.WLANConfiguration\.(\d+)\.SSID$/i);
            if (tr098Match) return tr098Match[1];
            var tr181Match = textPath.match(/\.SSID\.(\d+)\.SSID$/i);
            if (tr181Match) return tr181Match[1];
            return String(fallbackIndex || '');
        }

        function parseEnableState(rawValue) {
            var v = String(rawValue == null ? '' : rawValue).trim().toLowerCase();
            if (v === '') return null;
            if (v === '1' || v === 'true' || v === 'yes' || v === 'on' || v === 'enable' || v === 'enabled' || v === 'aktif') {
                return true;
            }
            if (v === '0' || v === 'false' || v === 'no' || v === 'off' || v === 'disable' || v === 'disabled' || v === 'nonaktif') {
                return false;
            }
            return null;
        }

        function resolveEnableState(device, ssidIdx, enablePath) {
            var direct = parseEnableState(device['ssid_enable_' + ssidIdx]);
            if (direct !== null) return direct;

            var params = (device && device.all_params) ? device.all_params : {};
            if (enablePath && Object.prototype.hasOwnProperty.call(params, enablePath)) {
                var fromPath = parseEnableState(params[enablePath]);
                if (fromPath !== null) return fromPath;
            }
            return null;
        }

        function buildPassCandidatesFromSsidPath(path, params) {
            var base = String(path || '');
            if (!base) return [];
            var candidates = [
                base.replace(/\.SSID$/i, '.PreSharedKey.1.PreSharedKey'),
                base.replace(/\.SSID$/i, '.PreSharedKey.1.KeyPassphrase'),
                base.replace(/\.SSID$/i, '.PreSharedKey.1.Passphrase'),
                base.replace(/\.SSID$/i, '.KeyPassphrase'),
                base.replace(/\.SSID$/i, '.Passphrase'),
                base.replace(/\.SSID$/i, '.PreSharedKey'),
                base.replace(/\.SSID$/i, '.X_CT-COM_KeyPassphrase')
            ];

            var tr181Match = base.match(/^Device\.WiFi\.SSID\.(\d+)\.SSID$/i);
            if (tr181Match) {
                var ssidRef = 'Device.WiFi.SSID.' + tr181Match[1];
                Object.keys(params || {}).forEach(function(key) {
                    var apRefMatch = key.match(/^Device\.WiFi\.AccessPoint\.(\d+)\.SSIDReference$/i);
                    if (!apRefMatch) return;
                    var normalizedRef = normalizeSsidRefPath(params[key]);
                    if (normalizedRef !== ssidRef) return;
                    var apIdx = apRefMatch[1];
                    candidates.push('Device.WiFi.AccessPoint.' + apIdx + '.Security.KeyPassphrase');
                    candidates.push('Device.WiFi.AccessPoint.' + apIdx + '.Security.Passphrase');
                    candidates.push('Device.WiFi.AccessPoint.' + apIdx + '.Security.PreSharedKey');
                    candidates.push('Device.WiFi.AccessPoint.' + apIdx + '.Security.X_CT-COM_KeyPassphrase');
                });

                candidates.push('Device.WiFi.AccessPoint.' + tr181Match[1] + '.Security.KeyPassphrase');
                candidates.push('Device.WiFi.AccessPoint.' + tr181Match[1] + '.Security.Passphrase');
                candidates.push('Device.WiFi.AccessPoint.' + tr181Match[1] + '.Security.PreSharedKey');
                candidates.push('Device.WiFi.AccessPoint.' + tr181Match[1] + '.Security.X_CT-COM_KeyPassphrase');
            }

            var dedup = [];
            var seen = {};
            candidates.forEach(function(x) {
                var key = String(x || '').trim();
                if (!key || seen[key]) return;
                seen[key] = true;
                dedup.push(key);
            });

            return dedup;
        }

        function buildSsidEntries(device, params) {
            var entries = [];
            var seen = {};

            function pushEntry(rawEntry) {
                var ssidValue = rawEntry && rawEntry.value != null ? String(rawEntry.value) : '';
                if (ssidValue === '') return;

                var paramPath = rawEntry && rawEntry.paramPath ? String(rawEntry.paramPath) : '';
                var uniqueKey = paramPath !== '' ? paramPath : String(rawEntry.ssidKey || '');
                if (uniqueKey !== '' && seen[uniqueKey]) return;
                if (uniqueKey !== '') seen[uniqueKey] = true;

                entries.push({
                    ssidKey: String(rawEntry.ssidKey || ('ssid_' + (entries.length + 1))),
                    labelIndex: String(rawEntry.labelIndex || ''),
                    value: ssidValue,
                    paramPath: paramPath,
                    passPath: rawEntry && rawEntry.passPath ? String(rawEntry.passPath) : '',
                    enablePath: rawEntry && rawEntry.enablePath ? String(rawEntry.enablePath) : '',
                    passValue: rawEntry && rawEntry.passValue != null ? String(rawEntry.passValue) : '',
                    enableValue: rawEntry && rawEntry.enableValue != null ? String(rawEntry.enableValue) : ''
                });
            }

            for (var slot = 1; slot <= 12; slot += 1) {
                var valueKey = 'ssid_' + slot;
                if (!Object.prototype.hasOwnProperty.call(device || {}, valueKey)) continue;
                var ssidValue = device[valueKey] == null ? '' : String(device[valueKey]);
                if (ssidValue === '') continue;

                var paramPath = device['ssid_param_' + slot] == null ? '' : String(device['ssid_param_' + slot]);
                pushEntry({
                    ssidKey: valueKey,
                    labelIndex: extractSsidIndexFromPath(paramPath, slot),
                    value: ssidValue,
                    paramPath: paramPath,
                    passPath: device['ssid_pass_param_' + slot],
                    enablePath: device['ssid_enable_param_' + slot],
                    passValue: device['ssid_pass_' + slot],
                    enableValue: device['ssid_enable_' + slot]
                });
            }

            Object.keys(params || {}).forEach(function(path) {
                if (isAcsMetaParam(path)) return;
                var match = path.match(/^InternetGatewayDevice\.LANDevice\.\d+\.WLANConfiguration\.(\d+)\.SSID$/i);
                if (!match) {
                    match = path.match(/^Device\.WiFi\.SSID\.(\d+)\.SSID$/i);
                }
                if (!match) return;

                var value = params[path];
                if (value == null || value === '') return;

                var passCandidates = buildPassCandidatesFromSsidPath(path, params);
                var passPath = '';
                var passValue = '';
                for (var i = 0; i < passCandidates.length; i += 1) {
                    if (Object.prototype.hasOwnProperty.call(params, passCandidates[i])) {
                        passPath = passCandidates[i];
                        passValue = params[passPath] == null ? '' : String(params[passPath]);
                        break;
                    }
                }

                var enablePath = path.replace(/\.SSID$/i, '.Enable');
                var enableValue = Object.prototype.hasOwnProperty.call(params, enablePath)
                    ? (params[enablePath] == null ? '' : String(params[enablePath]))
                    : '';

                pushEntry({
                    ssidKey: 'ssid_idx_' + match[1],
                    labelIndex: match[1],
                    value: String(value),
                    paramPath: path,
                    passPath: passPath,
                    enablePath: enablePath,
                    passValue: passValue,
                    enableValue: enableValue
                });
            });

            entries.sort(function(a, b) {
                return parseInt(a.labelIndex || '0', 10) - parseInt(b.labelIndex || '0', 10);
            });

            return entries;
        }

        function detectRedamanFromParams(params) {
            var p = params || {};
            var keys = [
                'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
                'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RxPower',
                'InternetGatewayDevice.WANDevice.1.X_HUAWEI_WANPONInterfaceConfig.RXPower',
                'Device.Optical.Interface.1.RXPower',
                'Device.Fiber.Interface.1.RXPower'
            ];
            for (var i = 0; i < keys.length; i += 1) {
                if (p[keys[i]] != null && String(p[keys[i]]).trim() !== '') {
                    return String(p[keys[i]]);
                }
            }
            return '';
        }

        function detectLocalConnectedClients(device) {
            var params = (device && device.all_params) ? device.all_params : {};
            var hosts = extractLocalHostsFromParams(params);
            var hostKeys = Object.keys(hosts);
            if (hostKeys.length > 0) {
                return String(hostKeys.length);
            }

            var directKeys = [
                'Device.LAN.Hosts.HostNumberOfEntries',
                'InternetGatewayDevice.LANDevice.1.Hosts.HostNumberOfEntries',
                'Device.WiFi.AccessPoint.1.AssociatedDeviceNumberOfEntries',
                'Device.WiFi.AccessPoint.2.AssociatedDeviceNumberOfEntries',
                'Device.WiFi.AccessPoint.3.AssociatedDeviceNumberOfEntries',
                'Device.WiFi.AccessPoint.4.AssociatedDeviceNumberOfEntries'
            ];

            for (var i = 0; i < directKeys.length; i += 1) {
                var raw = params[directKeys[i]];
                if (raw == null) continue;
                var n = parseInt(String(raw).trim(), 10);
                if (!isNaN(n) && n >= 0) return String(n);
            }

            var apCount = 0;
            Object.keys(params).forEach(function(key) {
                if (!/^Device\.WiFi\.AccessPoint\.\d+\.AssociatedDevice\.\d+\./i.test(key)) return;
                var v = String(params[key] || '').trim().toLowerCase();
                if (v === '1' || v === 'true' || v === 'yes' || v === 'on') {
                    apCount += 1;
                }
            });
            if (apCount > 0) return String(apCount);

            return '-';
        }

        function extractLocalHostsFromParams(params) {
            var hosts = {};
            Object.keys(params || {}).forEach(function(key) {
                var match = String(key).match(/Hosts\.Host\.(\d+)\.(HostName|IPAddress|InterfaceType)$/);
                if (!match) return;
                var hostNum = match[1];
                var attr = match[2];
                if (!hosts[hostNum]) hosts[hostNum] = {};
                hosts[hostNum][attr] = params[key];
            });
            return hosts;
        }

        function buildLocalHostsSection(device) {
            var params = (device && device.all_params) ? device.all_params : {};
            var hosts = extractLocalHostsFromParams(params);
            var hostKeys = Object.keys(hosts).sort(function(a, b) { return parseInt(a, 10) - parseInt(b, 10); });
            if (!hostKeys.length) {
                return '<div class="ssid-local-summary">Lokal Terhubung: -</div>';
            }

            var html = '<div class="acs-local-hosts-wrap">';
            html += '<div class="acs-local-hosts-title">Local Terhubung (' + hostKeys.length + ')</div>';
            html += '<div class="acs-local-hosts-grid">';
            hostKeys.forEach(function(hostNum) {
                var host = hosts[hostNum] || {};
                html += '<div class="acs-local-host-card">';
                html += '<div class="acs-local-host-name">Device ' + escHtml(hostNum) + '</div>';
                if (host.HostName) html += '<div class="acs-local-host-item"><strong>Host:</strong> ' + escHtml(host.HostName) + '</div>';
                if (host.IPAddress) html += '<div class="acs-local-host-item"><strong>IP:</strong> ' + escHtml(host.IPAddress) + '</div>';
                if (host.InterfaceType) html += '<div class="acs-local-host-item"><strong>Interface:</strong> ' + escHtml(host.InterfaceType) + '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
            return html;
        }

        function buildPortalStatus() {
            if (portalStatusType === 'online-working') {
                return '<button type="button" class="btn btn-status-online-working" disabled>ONLINE WORKING</button>';
            }
            if (portalStatusType === 'online-dhcp') {
                return '<button type="button" class="btn btn-status-online-dhcp" disabled>ONLINE DHCP</button>';
            }
            if (portalStatusType === 'expired') {
                return '<button type="button" class="btn btn-status-expired" disabled>EXPIRED</button>';
            }
            return '<button type="button" class="btn btn-status-offline" disabled>OFFLINE / LOS</button>';
        }

        function renderTopPortalStatus() {
            var topStatus = document.getElementById('portalStatusTop');
            if (!topStatus) return;
            topStatus.innerHTML = buildPortalStatus();
        }

        function loadPortalStatus() {
            if (!portalStatusUrl || !idpel) return Promise.resolve();
            return fetch(portalStatusUrl, { credentials: 'same-origin' })
                .then(function(res) { return res.text(); })
                .then(function(text) {
                    var up = String(text || '').toUpperCase();
                    if (up.indexOf('ONLINE WORKING') !== -1) {
                        portalStatusType = 'online-working';
                        renderTopPortalStatus();
                        return;
                    }
                    if (up.indexOf('ONLINE DHCP') !== -1) {
                        portalStatusType = 'online-dhcp';
                        renderTopPortalStatus();
                        return;
                    }
                    if (up.indexOf('EXPIRED') !== -1) {
                        portalStatusType = 'expired';
                        renderTopPortalStatus();
                        return;
                    }
                    portalStatusType = 'offline';
                    renderTopPortalStatus();
                })
                .catch(function() {
                    portalStatusType = 'offline';
                    renderTopPortalStatus();
                });
        }

        function buildSsidRows(device, deviceIdx) {
            var rows = [];
            var params = (device && device.all_params) ? device.all_params : {};
            var entries = buildSsidEntries(device, params);
            entries.forEach(function(entry, entryIdx) {
                var ssidVal = String(entry.value || '').trim();
                var ssidPath = normalizePath(entry.paramPath || '');
                if (!ssidVal || !ssidPath) return;
                var passPath = normalizePath(entry.passPath || '');
                var enablePath = normalizePath(entry.enablePath || '');
                var ssidNumber = parseInt(String(entry.labelIndex || ''), 10);
                var isEnabled = resolveEnableState(device, isNaN(ssidNumber) ? (entryIdx + 1) : ssidNumber, enablePath);
                var enableBadge = '';
                if (enablePath !== '') {
                    if (isEnabled === true) {
                        enableBadge = '<span class="badge bg-success ms-1" style="font-size:10px;">ON</span>';
                    } else if (isEnabled === false) {
                        enableBadge = '<span class="badge bg-danger ms-1" style="font-size:10px;">OFF</span>';
                    } else {
                        enableBadge = '<span class="badge bg-secondary ms-1" style="font-size:10px;">OFF</span>';
                    }
                }
                rows.push(
                    '<div class="ssid-item">' +
                        '<div class="ssid-head">' +
                            '<div class="ssid-name">SSID ' + escHtml(entry.labelIndex || (entryIdx + 1)) + ' ' + enableBadge + '</div>' +
                            '<button class="btn btn-sm btn-outline-primary btn-edit-ssid" data-device-idx="' + deviceIdx + '" data-entry-idx="' + entryIdx + '">Edit</button>' +
                        '</div>' +
                        '<div class="ssid-value">' + escHtml(ssidVal) + '</div>' +
                        '<div class="ssid-pass">Password: ******* </div>' +
                        '<div class="d-none" data-ssid-path>' + escHtml(ssidPath) + '</div>' +
                        '<div class="d-none" data-pass-path>' + escHtml(passPath) + '</div>' +
                        '<div class="d-none" data-enable-path>' + escHtml(enablePath) + '</div>' +
                    '</div>'
                );
            });
            if (!rows.length) {
                return '<div class="text-muted small">SSID tidak ditemukan di data ACS.</div>';
            }
            return '<div class="ssid-grid">' + rows.join('') + '</div>';
        }

        function renderDevices(devices, info) {
            var wrap = document.getElementById('wifiStatusWrap');
            if (!wrap) return;

            if (!devices || !devices.length) {
                wrap.innerHTML = '<div class="acs-card"><div class="fw-bold mb-1">Modem belum di konfigurasi melalui ACS oleh teknisi</div><div class="text-muted small">Modem belum bisa diakses melalui portal pelanggan. Untuk bisa mengganti Nama wifi SSID atau Password melalui portal, silakan hubungi teknisi.</div></div>';
                return;
            }

            var html = '';
            devices.forEach(function(d, idx) {
                var redaman = String(d.rx_power || '').trim();
                if (!redaman) {
                    redaman = detectRedamanFromParams(d.all_params || {});
                }
                var tx = String(d.tx_power || '').trim() || '-';
                var localConnected = detectLocalConnectedClients(d);

                html += '<div class="acs-card">';
                html += '<div class="acs-meta-grid">';
                html += '<div class="acs-meta-item"><div class="acs-meta-label">Serial</div><div class="acs-meta-value">' + escHtml(d.serial_raw || '-') + '</div></div>';
                html += '<div class="acs-meta-item"><div class="acs-meta-label">Last Inform</div><div class="acs-meta-value">' + escHtml(d.last_inform || '-') + '</div></div>';
                html += '<div class="acs-meta-item"><div class="acs-meta-label">Redaman (RX)</div><div class="acs-meta-value">' + escHtml(redaman || '-') + '</div></div>';
                html += '<div class="acs-meta-item"><div class="acs-meta-label">TX Power</div><div class="acs-meta-value">' + escHtml(tx) + '</div></div>';
                html += '</div>';

                html += '<div class="mt-3"><div class="fw-bold mb-2">SSID / Nama wifi </div>' + buildSsidRows(d, idx) + buildLocalHostsSection(d) + '</div>';
                html += '<div class="device-action-row"><button type="button" class="btn btn-sm btn-danger btn-reboot-device" data-device-idx="' + idx + '"><i class="bi bi-arrow-clockwise me-1"></i> Restart / Reboot Perangkat</button></div>';
                html += '</div>';
            });
            wrap.innerHTML = html;
        }

        function rebootDevice(deviceIdx, clickedButton) {
            var device = acsDevices[deviceIdx] || null;
            if (!device || !device.server_id || !device.serial_raw) {
                showToast('\u274C Device ACS tidak valid.', false);
                return;
            }

            if (!confirm('Yakin ingin restart/reboot perangkat ini?')) {
                return;
            }

            var btn = clickedButton || null;
            var oldHtml = '';
            if (btn) {
                oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Memproses...';
            }

            var payload = {
                server_id: device.server_id,
                serial_raw: device.serial_raw,
                idpel: idpel,
                acs_token: portalAcsToken
            };

            fetch('../getdata/acs_reboot_device.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
                .then(function(res) { return res.text(); })
                .then(function(raw) {
                    var json;
                    try {
                        json = JSON.parse(raw);
                    } catch (e) {
                        showToast('\u274C Response server tidak valid: ' + String(raw || '').slice(0, 120), false);
                        return;
                    }
                    if (!json || !json.success) {
                        var errorMessage = (json && json.message) ? json.message : 'Gagal reboot perangkat';
                        if (json && json.error) errorMessage += ' | ' + String(json.error);
                        if (json && json.http_code) errorMessage += ' (HTTP ' + String(json.http_code) + ')';
                        showToast('\u274C ' + errorMessage, false);
                        return;
                    }
                    var taskInfo = json.task_id ? ' [Task: ' + String(json.task_id).slice(-8) + ']' : '';
                    showToast('\u2705 Task reboot berhasil dikirim ke ACS.' + taskInfo + ' Tunggu 10 menit atau bisa lebih.', true);
                    setTimeout(loadAcs, 1000);
                })
                .catch(function() {
                    showToast('\u274C Koneksi ke server gagal saat reboot perangkat.', false);
                })
                .finally(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = oldHtml;
                    }
                });
        }

        function loadAcs() {
            if (!idpel) {
                renderDevices([], {});
                return;
            }
            Promise.all([
                loadPortalStatus(),
                fetch('../getdata/acs_cache_data.php?idpel=' + encodeURIComponent(idpel) + '&acs_token=' + encodeURIComponent(portalAcsToken), { credentials: 'same-origin' })
                    .then(function(res) { return res.json(); })
            ])
                .then(function(results) {
                    var json = results[1] || {};
                    if (json && json.error) {
                        var wrapErr = document.getElementById('wifiStatusWrap');
                        if (wrapErr) {
                            wrapErr.innerHTML = '<div class="acs-card"><div class="fw-bold mb-1">Data ACS tidak tersedia</div><div class="text-muted small">' + escHtml(String(json.error)) + '</div></div>';
                        }
                        return;
                    }
                    acsDevices = Array.isArray(json.devices) ? json.devices : [];
                    renderDevices(acsDevices, json || {});
                })
                .catch(function() {
                    var wrap = document.getElementById('wifiStatusWrap');
                    if (wrap) {
                        wrap.innerHTML = '<div class="acs-card"><div class="fw-bold mb-1">Gagal memuat data ACS</div><div class="text-muted small">Coba refresh beberapa saat lagi.</div></div>';
                    }
                });
        }

        function openEditModal(deviceIdx, entryIdx) {
            var device = acsDevices[deviceIdx] || null;
            if (!device) return;

            var params = (device && device.all_params) ? device.all_params : {};
            var entries = buildSsidEntries(device, params);
            var entry = entries[entryIdx] || null;
            if (!entry) return;

            var ssidNumber = parseInt(String(entry.labelIndex || ''), 10);
            var ssidValue = String(entry.value || '');
            var ssidPath = normalizePath(entry.paramPath || '');
            var passPath = normalizePath(entry.passPath || '');
            var enablePath = normalizePath(entry.enablePath || '');
            var enableState = resolveEnableState(device, isNaN(ssidNumber) ? (entryIdx + 1) : ssidNumber, enablePath);
            if (!ssidPath) return;

            document.getElementById('editDeviceIdx').value = String(deviceIdx);
            document.getElementById('editSsidIndex').value = String(entryIdx);
            document.getElementById('editSsidValue').value = ssidValue;
            document.getElementById('editPassValue').value = '';
            document.getElementById('editSsidPath').value = ssidPath;
            document.getElementById('editPassPath').value = passPath;
            document.getElementById('editEnablePath').value = enablePath;
            document.getElementById('editEnableSwitch').value = (enableState !== false) ? '1' : '0';

            var modalEl = document.getElementById('editSsidModal');
            var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            instance.show();
        }

        function showToast(message, isSuccess) {
            var toast = document.getElementById('wifiToast');
            if (!toast) return;
            toast.textContent = message;
            toast.style.background = isSuccess ? '#16a34a' : '#dc2626';
            toast.style.color = '#fff';
            toast.style.display = 'block';
            toast.style.opacity = '1';
            clearTimeout(toast._hideTimer);
            toast._hideTimer = setTimeout(function() {
                toast.style.opacity = '0';
                setTimeout(function() { toast.style.display = 'none'; toast.style.opacity = '1'; }, 350);
            }, 4000);
        }

        function saveSsid() {
            var btn = document.getElementById('btnSaveWifi');
            var deviceIdx = parseInt(document.getElementById('editDeviceIdx').value, 10);
            var ssidIdx = parseInt(document.getElementById('editSsidIndex').value, 10);
            var ssidValue = String(document.getElementById('editSsidValue').value || '').trim();
            var passValue = String(document.getElementById('editPassValue').value || '').trim();
            var ssidPath = normalizePath(document.getElementById('editSsidPath').value || '');
            var passPath = normalizePath(document.getElementById('editPassPath').value || '');
            var enablePath = normalizePath(document.getElementById('editEnablePath').value || '');
            var enableSwitchNode = document.getElementById('editEnableSwitch');
            var enableValue = enableSwitchNode ? String(enableSwitchNode.value || '1') : '1';

            var device = acsDevices[deviceIdx] || null;
            if (!device || !device.server_id || !device.serial_raw) {
                showToast('\u274C Device ACS tidak valid.', false);
                return;
            }
            if (!ssidPath || !ssidValue) {
                showToast('\u274C SSID tidak boleh kosong.', false);
                return;
            }
            if (passValue !== '' && passValue.length < 8) {
                showToast('\u274C Password minimal 8 karakter.', false);
                return;
            }

            if (!passPath && passValue !== '') {
                var selectedParams = (device && device.all_params) ? device.all_params : {};
                var passCandidates = buildPassCandidatesFromSsidPath(ssidPath, selectedParams);
                // Pick the first candidate that actually exists in cached params
                for (var pi = 0; pi < passCandidates.length; pi++) {
                    if (Object.prototype.hasOwnProperty.call(selectedParams, passCandidates[pi])) {
                        passPath = passCandidates[pi];
                        break;
                    }
                }
                // Last resort fallback: PreSharedKey.1.PreSharedKey (standard TR-098 path)
                if (!passPath) {
                    passPath = ssidPath.replace(/\.SSID$/i, '.PreSharedKey.1.PreSharedKey');
                }
            }

            var payload = {
                server_id: device.server_id,
                serial_raw: device.serial_raw,
                param_path: ssidPath,
                ssid_value: ssidValue,
                idpel: idpel,
                acs_token: portalAcsToken
            };
            if (passValue !== '' && passPath !== '') {
                payload.password_value = passValue;
                payload.password_param_path = passPath;
            }
            if (enablePath !== '') {
                payload.enable_param_path = enablePath;
                payload.enable_value = enableValue;
            }

            btn.disabled = true;
            btn.textContent = 'Menyimpan...';

            fetch('../getdata/acs_update_ssid.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
                .then(function(res) { return res.text(); })
                .then(function(raw) {
                    var json;
                    try {
                        json = JSON.parse(raw);
                    } catch (e) {
                        showToast('\u274C Response server tidak valid: ' + String(raw || '').slice(0, 120), false);
                        return;
                    }
                    if (!json || !json.success) {
                        var errorMessage = (json && json.message) ? json.message : 'Gagal update WiFi';
                        if (json && json.error) errorMessage += ' | ' + String(json.error);
                        if (json && json.http_code) errorMessage += ' (HTTP ' + String(json.http_code) + ')';
                        showToast('\u274C ' + errorMessage, false);
                        return;
                    }
                    var taskInfo = json.task_id ? ' [Task: ' + String(json.task_id).slice(-8) + ']' : '';
                    showToast('\u2705 Berhasil! WiFi dikirim ke ACS.' + taskInfo + ' Tunggu 10 menit atau bisa lebih.', true);
                    var modalEl = document.getElementById('editSsidModal');
                    var instance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                    instance.hide();
                    setTimeout(loadAcs, 1000);
                })
                .catch(function() {
                    showToast('\u274C Koneksi ke server gagal saat update ACS.', false);
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = 'Simpan';
                });
        }

        document.addEventListener('click', function(ev) {
            var rebootBtn = ev.target.closest('.btn-reboot-device');
            if (rebootBtn) {
                var rebootDeviceIdx = parseInt(rebootBtn.getAttribute('data-device-idx') || '', 10);
                if (!isNaN(rebootDeviceIdx)) {
                    rebootDevice(rebootDeviceIdx, rebootBtn);
                }
                return;
            }

            var btn = ev.target.closest('.btn-edit-ssid');
            if (!btn) return;
            var deviceIdx = parseInt(btn.getAttribute('data-device-idx') || '', 10);
            var entryIdx = parseInt(btn.getAttribute('data-entry-idx') || '', 10);
            if (isNaN(deviceIdx) || isNaN(entryIdx)) return;
            openEditModal(deviceIdx, entryIdx);
        });

        document.getElementById('btnSaveWifi').addEventListener('click', saveSsid);

        renderTopPortalStatus();
        loadAcs();
        setInterval(loadAcs, 30000);
    </script>
</body>
</html>
