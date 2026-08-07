<?php
require 'header.php';

if (!isset($AKSES) || $AKSES !== 'ADMIN') {
    echo "<div class='container-fluid py-4'><div class='alert alert-danger'>Halaman ini hanya untuk ADMIN.</div></div>";
    require 'footer.php';
    exit;
}

$otp_signup_config_path = __DIR__ . '/otp_signup_config.json';
$otp_signup_waapi_path = __DIR__ . '/otp_signup_waapi.txt';
$legacy_waapi_path = __DIR__ . '/waapi.txt';

$otp_mode = 'otp';
$otp_length = 6;
$otp_expiry_seconds = 120;
$otp_message_template = "*[INI ADALAH PESAN OTOMATIS]*\nKode OTP Anda: *{otp}*\nBerlaku {expired_minutes} menit";
$otp_random_greeting_enabled = false;
$otp_random_greetings = [
    'Halo',
    'Hai',
    'Assalamualaikum'
];
$forgot_message_template = "Halo {name}, permintaan reset kata sandi diterima. Klik link berikut untuk reset password:\n{reset_link}\nToken berlaku 1 jam.";
$forgot_random_greeting_enabled = false;
$forgot_random_greetings = [
    'Halo',
    'Hai',
    'Assalamualaikum'
];
$waapi = '';
$namebot = '';
$passwordbot = '';
$feedback = '';
$feedback_class = 'success';

if (file_exists($otp_signup_config_path)) {
    $cfg = json_decode(file_get_contents($otp_signup_config_path), true);
    if (is_array($cfg)) {
        $otp_mode = ($cfg['otp_mode'] ?? 'otp') === 'bypass' ? 'bypass' : 'otp';
        $otp_length = (int)($cfg['otp_length'] ?? 6);
        $otp_expiry_seconds = (int)($cfg['otp_expiry_seconds'] ?? 120);
        $template_candidate = trim((string)($cfg['otp_message_template'] ?? ''));
        if ($template_candidate !== '') {
            $otp_message_template = $template_candidate;
        }

        $otp_random_greeting_enabled = !empty($cfg['otp_random_greeting_enabled']);
        if (isset($cfg['otp_random_greetings']) && is_array($cfg['otp_random_greetings'])) {
            $candidate_greetings = [];
            foreach ($cfg['otp_random_greetings'] as $greeting_item) {
                $greeting_item = trim((string)$greeting_item);
                if ($greeting_item !== '') {
                    $candidate_greetings[] = $greeting_item;
                }
            }
            if (!empty($candidate_greetings)) {
                $otp_random_greetings = $candidate_greetings;
            }
        }

        $forgot_template_candidate = trim((string)($cfg['forgot_message_template'] ?? ''));
        if ($forgot_template_candidate !== '') {
            $forgot_message_template = $forgot_template_candidate;
        }

        $forgot_random_greeting_enabled = !empty($cfg['forgot_random_greeting_enabled']);
        if (isset($cfg['forgot_random_greetings']) && is_array($cfg['forgot_random_greetings'])) {
            $candidate_forgot_greetings = [];
            foreach ($cfg['forgot_random_greetings'] as $greeting_item) {
                $greeting_item = trim((string)$greeting_item);
                if ($greeting_item !== '') {
                    $candidate_forgot_greetings[] = $greeting_item;
                }
            }
            if (!empty($candidate_forgot_greetings)) {
                $forgot_random_greetings = $candidate_forgot_greetings;
            }
        }
    }
}

$otp_length = max(4, min(8, $otp_length));
$otp_expiry_seconds = max(60, min(900, $otp_expiry_seconds));

$waapi_source_path = file_exists($otp_signup_waapi_path) ? $otp_signup_waapi_path : $legacy_waapi_path;
if (file_exists($waapi_source_path)) {
    $wa_lines = file($waapi_source_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($wa_lines as $wa_line) {
        if (strpos($wa_line, 'waapi=') === 0) {
            $waapi = trim(substr($wa_line, 6));
        }
        if (strpos($wa_line, 'namebot=') === 0) {
            $namebot = trim(substr($wa_line, 8));
        }
        if (strpos($wa_line, 'password=') === 0) {
            $passwordbot = trim(substr($wa_line, 9));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_otp_signup_settings'])) {
    $otp_mode_post = trim($_POST['otp_mode'] ?? 'otp');
    $otp_mode_post = in_array($otp_mode_post, ['otp', 'bypass'], true) ? $otp_mode_post : 'otp';

    $otp_length_post = (int)($_POST['otp_length'] ?? 6);
    $otp_length_post = max(4, min(8, $otp_length_post));

    $otp_expiry_post = (int)($_POST['otp_expiry_seconds'] ?? 120);
    $otp_expiry_post = max(60, min(900, $otp_expiry_post));

    $template_post = trim((string)($_POST['otp_message_template'] ?? ''));
    if ($template_post === '') {
        $template_post = "*[INI ADALAH PESAN OTOMATIS]*\nKode OTP Anda: *{otp}*\nBerlaku {expired_minutes} menit";
    }

    $otp_random_greeting_enabled_post = isset($_POST['otp_random_greeting_enabled']);
    $otp_random_greetings_text_post = trim((string)($_POST['otp_random_greetings'] ?? ''));
    $otp_random_greetings_post = [];
    if ($otp_random_greetings_text_post !== '') {
        $otp_random_greetings_lines = preg_split('/\r\n|\r|\n/', $otp_random_greetings_text_post);
        foreach ($otp_random_greetings_lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $otp_random_greetings_post[] = $line;
            }
        }
    }

    $forgot_template_post = trim((string)($_POST['forgot_message_template'] ?? ''));
    if ($forgot_template_post === '') {
        $forgot_template_post = "Halo {name}, permintaan reset kata sandi diterima. Klik link berikut untuk reset password:\n{reset_link}\nToken berlaku 1 jam.";
    }

    $forgot_random_greeting_enabled_post = isset($_POST['forgot_random_greeting_enabled']);
    $forgot_random_greetings_text_post = trim((string)($_POST['forgot_random_greetings'] ?? ''));
    $forgot_random_greetings_post = [];
    if ($forgot_random_greetings_text_post !== '') {
        $forgot_random_greetings_lines = preg_split('/\r\n|\r|\n/', $forgot_random_greetings_text_post);
        foreach ($forgot_random_greetings_lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $forgot_random_greetings_post[] = $line;
            }
        }
    }

    $waapi_post = trim((string)($_POST['otp_waapi'] ?? ''));
    $namebot_post = trim((string)($_POST['otp_namebot'] ?? ''));
    $passwordbot_post = trim((string)($_POST['otp_password'] ?? ''));

    if (strpos($template_post, '{otp}') === false) {
        $feedback = 'Template OTP wajib mengandung placeholder {otp}.';
        $feedback_class = 'danger';
    } elseif ($otp_random_greeting_enabled_post && empty($otp_random_greetings_post)) {
        $feedback = 'Saat salam acak diaktifkan, daftar salam tidak boleh kosong.';
        $feedback_class = 'danger';
    } elseif (strpos($forgot_template_post, '{reset_link}') === false) {
        $feedback = 'Template forgot password wajib mengandung placeholder {reset_link}.';
        $feedback_class = 'danger';
    } elseif ($forgot_random_greeting_enabled_post && empty($forgot_random_greetings_post)) {
        $feedback = 'Saat salam acak forgot password diaktifkan, daftar salam tidak boleh kosong.';
        $feedback_class = 'danger';
    } else {
        $payload = [
            'otp_mode' => $otp_mode_post,
            'otp_length' => $otp_length_post,
            'otp_expiry_seconds' => $otp_expiry_post,
            'otp_message_template' => $template_post,
            'otp_random_greeting_enabled' => $otp_random_greeting_enabled_post,
            'otp_random_greetings' => $otp_random_greetings_post,
            'forgot_message_template' => $forgot_template_post,
            'forgot_random_greeting_enabled' => $forgot_random_greeting_enabled_post,
            'forgot_random_greetings' => $forgot_random_greetings_post,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $config_saved = file_put_contents(
            $otp_signup_config_path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $waapi_content = "waapi={$waapi_post}\nnamebot={$namebot_post}\npassword={$passwordbot_post}\n";
        $waapi_saved = file_put_contents($otp_signup_waapi_path, $waapi_content);

        if ($config_saved !== false && $waapi_saved !== false) {
            $feedback = 'Pengaturan OTP Sign Up berhasil disimpan.';
            $feedback_class = 'success';

            $otp_mode = $otp_mode_post;
            $otp_length = $otp_length_post;
            $otp_expiry_seconds = $otp_expiry_post;
            $otp_message_template = $template_post;
            $otp_random_greeting_enabled = $otp_random_greeting_enabled_post;
            $otp_random_greetings = !empty($otp_random_greetings_post) ? $otp_random_greetings_post : $otp_random_greetings;
            $forgot_message_template = $forgot_template_post;
            $forgot_random_greeting_enabled = $forgot_random_greeting_enabled_post;
            $forgot_random_greetings = !empty($forgot_random_greetings_post) ? $forgot_random_greetings_post : $forgot_random_greetings;
            $waapi = $waapi_post;
            $namebot = $namebot_post;
            $passwordbot = $passwordbot_post;
        } else {
            $feedback = 'Gagal menyimpan pengaturan OTP Sign Up.';
            $feedback_class = 'danger';
        }
    }
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12 col-xl-9">
            <div class="card shadow-sm">
                <div class="card-header pb-0">
                    <h5 class="mb-0">Pengaturan OTP Sign Up Billing</h5>
                    <p class="text-sm text-muted mb-0">Kelola OTP Sign Up, kredensial WA API, dan template pesan untuk bot <code>sign-up.php</code> serta <code>forgot.php</code>.</p>
                </div>
                <div class="card-body">
                    <?php if ($feedback !== ''): ?>
                        <div class="alert alert-<?= htmlspecialchars($feedback_class) ?>"><?= htmlspecialchars($feedback) ?></div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="otp_mode" class="form-label">Mode Verifikasi</label>
                            <select class="form-select" id="otp_mode" name="otp_mode">
                                <option value="otp" <?= $otp_mode === 'otp' ? 'selected' : '' ?>>OTP Aktif (verifikasi via WhatsApp)</option>
                                <option value="bypass" <?= $otp_mode === 'bypass' ? 'selected' : '' ?>>Bypass OTP (langsung registrasi)</option>
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="otp_length" class="form-label">Panjang Kode OTP</label>
                                <input type="number" class="form-control" id="otp_length" name="otp_length" min="4" max="8" value="<?= (int)$otp_length ?>" required>
                                <div class="form-text">Rentang yang disarankan: 4 sampai 8 digit.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="otp_expiry_seconds" class="form-label">Masa Berlaku OTP (detik)</label>
                                <input type="number" class="form-control" id="otp_expiry_seconds" name="otp_expiry_seconds" min="60" max="900" value="<?= (int)$otp_expiry_seconds ?>" required>
                                <div class="form-text">Rentang yang disarankan: 60 sampai 900 detik.</div>
                            </div>
                        </div>

                        <div class="mt-3 mb-3">
                            <label for="otp_message_template" class="form-label">Template Pesan OTP</label>
                            <textarea class="form-control" id="otp_message_template" name="otp_message_template" rows="4" required><?= htmlspecialchars($otp_message_template) ?></textarea>
                            <div class="form-text">Wajib gunakan <code>{otp}</code>. Opsional gunakan <code>{expired_minutes}</code> untuk menampilkan durasi kedaluwarsa.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="otp_random_greeting_enabled" name="otp_random_greeting_enabled" <?= $otp_random_greeting_enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="otp_random_greeting_enabled">Aktifkan Salam Acak OTP</label>
                            <div class="form-text">Jika aktif, sistem memilih salam secara acak agar pesan OTP lebih bervariasi.</div>
                        </div>

                        <div class="mb-3">
                            <label for="otp_random_greetings" class="form-label">Daftar Salam Acak OTP</label>
                            <textarea class="form-control" id="otp_random_greetings" name="otp_random_greetings" rows="4" placeholder="Satu salam per baris"><?= htmlspecialchars(implode("\n", $otp_random_greetings)) ?></textarea>
                            <div class="form-text">Tulis satu salam per baris. Contoh: <code>Halo</code>, <code>Hai</code>, <code>Assalamualaikum</code>. Opsional gunakan placeholder <code>{greeting}</code> di template OTP untuk posisi salam.</div>
                        </div>

                        <div class="mb-3">
                            <label for="forgot_message_template" class="form-label">Template Pesan Forgot Password (WhatsApp)</label>
                            <textarea class="form-control" id="forgot_message_template" name="forgot_message_template" rows="4" required><?= htmlspecialchars($forgot_message_template) ?></textarea>
                            <div class="form-text">Wajib gunakan <code>{reset_link}</code>. Opsional gunakan <code>{name}</code> untuk nama pelanggan dan <code>{greeting}</code> untuk salam acak.</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="forgot_random_greeting_enabled" name="forgot_random_greeting_enabled" <?= $forgot_random_greeting_enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="forgot_random_greeting_enabled">Aktifkan Salam Acak Forgot Password</label>
                            <div class="form-text">Jika aktif, sistem memilih salam secara acak agar pesan forgot password lebih bervariasi.</div>
                        </div>

                        <div class="mb-3">
                            <label for="forgot_random_greetings" class="form-label">Daftar Salam Acak Forgot Password</label>
                            <textarea class="form-control" id="forgot_random_greetings" name="forgot_random_greetings" rows="4" placeholder="Satu salam per baris"><?= htmlspecialchars(implode("\n", $forgot_random_greetings)) ?></textarea>
                            <div class="form-text">Tulis satu salam per baris. Contoh: <code>Halo</code>, <code>Hai</code>, <code>Assalamualaikum</code>. Opsional gunakan placeholder <code>{greeting}</code> di template forgot password untuk posisi salam.</div>
                        </div>

                        <hr>
                        <h6 class="mb-3">Kredensial WA API (Dipakai Sign Up dan Forgot Password)</h6>

                        <div class="mb-3">
                            <label for="otp_waapi" class="form-label">URL WA API</label>
                            <input type="text" class="form-control" id="otp_waapi" name="otp_waapi" placeholder="Contoh: http://127.0.0.1:3000" value="<?= htmlspecialchars($waapi) ?>">
                        </div>

                        <div class="mb-3">
                            <label for="otp_namebot" class="form-label">Username Bot</label>
                            <input type="text" class="form-control" id="otp_namebot" name="otp_namebot" value="<?= htmlspecialchars($namebot) ?>">
                        </div>

                        <div class="mb-4">
                            <label for="otp_password" class="form-label">Password Bot</label>
                            <input type="text" class="form-control" id="otp_password" name="otp_password" value="<?= htmlspecialchars($passwordbot) ?>">
                        </div>

                        <button type="submit" class="btn btn-primary" name="save_otp_signup_settings">Simpan Pengaturan OTP dan Bot WA</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
