<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('broadcast_info', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Broadcast Info.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>


    <?php
    $ok = $_GET["statusnotif"];
    if ($ok == "terkirim") {
    ?>
        <div class="alert alert-success" role="alert">
            Success , Notifikasi terkirim ke pelanggan !
        </div>
    <?php
    }
    ?>

    <?php
    // Bangun daftar bot milik user untuk checkbox group
    $bot_options = ['RANDOM'];
    if (!empty($ceknama)) {
        // Bot yang bisa dipilih ASSISTANT dibatasi ke bot yang di-assign owner
        // ATAU bot buatan sendiri assistant ini -- lihat notifbot/bot_access_helper.php.
        $bot_option_query = mysqli_query($conn, "SELECT DISTINCT namebot FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
        if ($bot_option_query) {
            while ($bot_option_row = mysqli_fetch_assoc($bot_option_query)) {
                $bot_name_opt = trim((string)($bot_option_row['namebot'] ?? ''));
                if ($bot_name_opt !== '') {
                    $bot_options[] = $bot_name_opt;
                }
            }
        }
    }

    // Daftar bot Telegram milik user untuk checkbox group kanal kedua (opsional, opt-in).
    $telegram_bot_options = [];
    if (!empty($ceknama) && function_exists('telegramBotAccessWhereClause')) {
        $telegram_bot_option_query = mysqli_query($conn, "SELECT DISTINCT namebot FROM bottelegram WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . telegramBotAccessWhereClause($conn, $AKSES, $assigned_telegram_bot_ids ?? [], $asistant_name ?? '') . " ORDER BY namebot ASC");
        if ($telegram_bot_option_query) {
            while ($telegram_bot_option_row = mysqli_fetch_assoc($telegram_bot_option_query)) {
                $telegram_bot_name_opt = trim((string)($telegram_bot_option_row['namebot'] ?? ''));
                if ($telegram_bot_name_opt !== '') {
                    $telegram_bot_options[] = $telegram_bot_name_opt;
                }
            }
        }
    }
    ?>

    <div class="container-fluid py-4 px-3 px-md-4" id="div5">

        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
        <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

        <style>
            #hidden_div {
                display: none;
            }

            #div5 .card {
                background: var(--bs-body-bg, #ffffff);
                color: var(--bs-body-color, #1f2937);
                border: 1px solid var(--bs-border-color, #d1d5db);
            }

            #div5 .card-header,
            #div5 .form-label,
            #div5 .small,
            #div5 #progressMeta,
            #div5 #processText {
                color: var(--bs-body-color, #1f2937) !important;
            }

            #div5 .text-muted {
                color: color-mix(in srgb, var(--bs-body-color, #1f2937) 72%, #6b7280 28%) !important;
            }

            #div5 .form-control,
            #div5 .form-select,
            #div5 textarea {
                background: var(--bs-body-bg, #ffffff);
                color: var(--bs-body-color, #111827);
                border: 1px solid var(--bs-border-color, #cbd5e1);
            }

            #div5 .form-control::placeholder,
            #div5 textarea::placeholder {
                color: color-mix(in srgb, var(--bs-body-color, #1f2937) 55%, #9ca3af 45%);
                opacity: 1;
            }

            #div5 .choices {
                width: 100%;
                margin-bottom: 0;
            }

            #div5 .choices__inner {
                min-height: 38px;
                border-radius: 0.5rem;
                border: 1px solid var(--bs-border-color, #cbd5e1);
                background: var(--bs-body-bg, #ffffff);
                color: var(--bs-body-color, #111827);
            }

            #div5 .choices__list--single,
            #div5 .choices__list--single .choices__item,
            #div5 .choices__input,
            #div5 .choices__input::placeholder,
            #div5 .choices__placeholder {
                color: var(--bs-body-color, #111827) !important;
                opacity: 1 !important;
            }

            #div5 .choices__list--dropdown,
            #div5 .choices__list[aria-expanded] {
                background: var(--bs-body-bg, #ffffff);
                color: var(--bs-body-color, #111827);
                border: 1px solid var(--bs-border-color, #cbd5e1);
                box-shadow: 0 8px 20px rgba(0, 0, 0, 0.22);
            }

            #div5 .choices__list--dropdown .choices__item,
            #div5 .choices__list[aria-expanded] .choices__item {
                color: var(--bs-body-color, #111827);
            }

            #div5 .choices__list--dropdown .choices__item--selectable.is-highlighted,
            #div5 .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
                background: color-mix(in srgb, var(--bs-primary, #2563eb) 18%, var(--bs-body-bg, #ffffff) 82%);
                color: var(--bs-body-color, #111827);
            }

            #div5 .choices.is-focused .choices__inner,
            #div5 .choices.is-open .choices__inner,
            #div5 .form-control:focus,
            #div5 .form-select:focus,
            #div5 textarea:focus {
                border-color: var(--bs-primary, #2563eb);
                box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.2);
            }

            /* Bot checkbox group (sama seperti notification.php) */
            #div5 .bot-checkbox-group {
                max-height: 220px;
                overflow-y: auto;
                background: var(--bs-body-bg, #ffffff);
                border: 1px solid var(--bs-border-color, #cbd5e1);
                border-radius: 0.5rem;
                padding: 0.5rem;
            }
        </style>
        <script>
            function showDiv(divId, element) {
                document.getElementById(divId).style.display = element.value == "pesan" ? 'block' : 'none';
            }

            // ==== Sinkronisasi checkbox BOT -> hidden input (sama aturan seperti encodeBotSelection() PHP) ====
            function updateBotSelection(groupId, hiddenInputId) {
                const group = document.getElementById(groupId);
                const hidden = document.getElementById(hiddenInputId);
                if (!group || !hidden) return;
                const randomCb = group.querySelector('.bot-random-toggle');
                const specificCbs = group.querySelectorAll('.bot-specific-checkbox');
                const selected = Array.from(specificCbs).filter(cb => cb.checked).map(cb => cb.value);

                if (!randomCb.checked && selected.length > 0) {
                    hidden.value = selected.length === 1 ? selected[0] : selected.join(',');
                } else {
                    hidden.value = 'RANDOM';
                }
            }

            function initBotCheckboxGroups() {
                document.querySelectorAll('.bot-checkbox-group').forEach(function (group) {
                    const randomCb = group.querySelector('.bot-random-toggle');
                    const specificCb = group.querySelectorAll('.bot-specific-checkbox');
                    const hiddenId = group.getAttribute('data-hidden-target');

                    function sync() { updateBotSelection(group.id, hiddenId); }

                    randomCb.addEventListener('change', function () {
                        if (this.checked) specificCb.forEach(cb => cb.checked = false);
                        sync();
                    });

                    specificCb.forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            if (this.checked) randomCb.checked = false;
                            const anyChecked = Array.prototype.some.call(specificCb, c => c.checked);
                            if (!anyChecked) randomCb.checked = true; // fallback ke RANDOM
                            sync();
                        });
                    });

                    sync(); // set nilai awal hidden input
                });
            }
            document.addEventListener('DOMContentLoaded', initBotCheckboxGroups);
        </script>
        <div class="card shadow p-4">
            <h5 class="card-header">BROADCAST PESAN VIA BOT</h5>
            <div class="card-body">
                <form action="notifbot/notifphp/notif_gangguan.php" method="GET">
                    <div class="mb-3">
                        <label class="form-label">Pilih BOT</label>
                        <div class="bot-checkbox-group" id="grp_botname" data-hidden-target="botname">
                            <div class="form-check">
                                <input class="form-check-input bot-random-toggle" type="checkbox" id="botname_RANDOM" value="RANDOM" checked>
                                <label class="form-check-label fw-bold" for="botname_RANDOM">ACAK dari SEMUA BOT</label>
                            </div>
                            <hr class="my-2">
                            <?php foreach ($bot_options as $bot_opt):
                                if ($bot_opt === 'RANDOM') continue;
                                $bot_opt_safe = htmlspecialchars($bot_opt, ENT_QUOTES, 'UTF-8');
                            ?>
                            <div class="form-check">
                                <input class="form-check-input bot-specific-checkbox" type="checkbox" id="botname_<?= $bot_opt_safe ?>" value="<?= $bot_opt_safe ?>">
                                <label class="form-check-label" for="botname_<?= $bot_opt_safe ?>"><?= $bot_opt_safe ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Centang <b>ACAK dari SEMUA BOT</b> untuk random dari semua bot. Centang satu bot saja untuk selalu memakai bot itu. Centang beberapa bot untuk random hanya dari bot yang dicentang.</div>
                        <!-- Hidden input inilah yang benar-benar dikirim sebagai field "botname" -->
                        <input type="hidden" id="botname" name="botname" value="RANDOM">
                    </div>
                    <?php if (!empty($telegram_bot_options)): ?>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="telegram_channel_enable" onchange="document.getElementById('telegram_channel_group').style.display = this.checked ? 'block' : 'none';">
                            <label class="form-check-label fw-bold" for="telegram_channel_enable"><i class="fab fa-telegram"></i> Kirim juga via Telegram</label>
                        </div>
                        <div id="telegram_channel_group" style="display:none;" class="mt-2">
                            <label class="form-label">Pilih BOT Telegram</label>
                            <div class="bot-checkbox-group" id="grp_botname_telegram" data-hidden-target="botname_telegram">
                                <div class="form-check">
                                    <input class="form-check-input bot-random-toggle" type="checkbox" id="botname_telegram_RANDOM" value="RANDOM" checked>
                                    <label class="form-check-label fw-bold" for="botname_telegram_RANDOM">ACAK dari SEMUA BOT TELEGRAM</label>
                                </div>
                                <hr class="my-2">
                                <?php foreach ($telegram_bot_options as $tg_bot_opt):
                                    $tg_bot_opt_safe = htmlspecialchars($tg_bot_opt, ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input bot-specific-checkbox" type="checkbox" id="botname_telegram_<?= $tg_bot_opt_safe ?>" value="<?= $tg_bot_opt_safe ?>">
                                    <label class="form-check-label" for="botname_telegram_<?= $tg_bot_opt_safe ?>"><?= $tg_bot_opt_safe ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Pelanggan yang menerima pesan Telegram hanya yang sudah "/start" bot (chat ID terhubung). Nomor WA tetap dikirim seperti biasa jika ada.</div>
                        </div>
                        <!-- Hidden input ini kosong kalau kanal Telegram tidak dicentang -> backend anggap tidak dipilih. -->
                        <input type="hidden" id="botname_telegram" name="botname_telegram" value="">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="email_channel_enable">
                            <label class="form-check-label fw-bold" for="email_channel_enable"><i class="fas fa-envelope"></i> Kirim juga via Email</label>
                        </div>
                        <div class="form-text">Dikirim ke email pelanggan (kolom EMAIL) kalau ada. Atur SMTP dulu di menu <a href="email_setting.php" target="_blank" rel="noopener">Email SMTP</a>.</div>
                        <!-- Hidden input ini kosong kalau kanal Email tidak dicentang -> backend anggap tidak dipilih. -->
                        <input type="hidden" id="send_email" name="send_email" value="">
                    </div>
                    <div class="form-row">



                        <div class="form-group col-md-6">
                            <label for="inputPassword4">Jenis info</label>
                            <select class="form-control" name="mode" onchange="showDiv('hidden_div', this)">
                                <option value="info">Notif Gangguan</option>
                                <option value="selesai">Notif Selesai Gangguan</option>
                                <option value="tagihan">Ingatkan tagihan </option>
                                <option value="pesan">Pesan Manual</option>
                            </select>
                        </div>
                        <div id="hidden_div" class="form-group col-md-6">
                            <label for="inputPassword4">Pesan manual</label>

                            <textarea rows="10" class="form-control" width="100%" type="text" id="inlineFormInput" name="pesan" placeholder="Pesan notif"></textarea>
                        </div>

                       <div class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <label for="server" class="form-label mb-0">Server Area</label>
        <small class="text-muted">
            Jika belum ada SERVER silahkan buat dulu 
            <a href="server.php" target="_parent" class="text-primary text-decoration-none fw-semibold">disini</a>
        </small>
    </div>
    <select required class="form-select mt-1" id="server" name="server" onchange="setAreaAddCustomer()">
        <option value="">-- Pilih Server Area --</option>
        <?php
        // Ambil server milik user yang login -- WAJIB pakai $AKSES/$area_list
        // dari cek-sesi.php (BUKAN override $current_user_id dari
        // $_SESSION['id'] langsung spt sebelumnya): utk ASSISTANT,
        // $_SESSION['id'] adalah id baris assistant itu sendiri, sedangkan
        // server terdaftar di bawah user_id akun OWNER -- jadi versi lama ini
        // bisa salah satu dari dua arah (dropdown kosong sama sekali utk
        // assistant, ATAU kalau id assistant kebetulan valid tapi beda akun,
        // bocor server milik akun lain).
        if ($AKSES === 'ASSISTANT') {
            $queryServer = (isset($area_list) && trim((string)$area_list) !== '')
                ? mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)")
                : mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE 1=0");
        } else {
            $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
        }
        while ($rowServer = mysqli_fetch_assoc($queryServer)) {
            $selected = (isset($data) && $rowServer['PEMILIK'] == $data['PEMILIK']) ? "selected" : "";
            $area = htmlspecialchars($rowServer['AREA']);
            echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" '.$selected.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
        }
        ?>
    </select>
</div>

<div hidden class="mb-3">
    <label for="area" class="form-label">AREA</label>
    <input type="hidden" id="area" name="area">
    <input type="text" class="form-control" id="area_display" readonly>
</div>
<script>
function setAreaAddCustomer() {
    let serverSelect = document.getElementById('server');
    let areaInput = document.getElementById('area');
    let areaDisplay = document.getElementById('area_display');
    let selected = serverSelect.options[serverSelect.selectedIndex];
    let area = selected ? selected.getAttribute('data-area') : '';
    areaInput.value = area;
    areaDisplay.value = area;
}
// Set area on page load if server is preselected
document.addEventListener('DOMContentLoaded', function() {
    setAreaAddCustomer();
});
</script>


<div class="mb-3">
    <label class="form-label d-block">Target Broadcast</label>
    <div class="btn-group" role="group" aria-label="Target broadcast">
        <input type="radio" class="btn-check" name="target_type" id="target_type_odp" value="odp" checked onchange="toggleTargetType()">
        <label class="btn btn-outline-primary" for="target_type_odp">Berdasarkan ODP</label>

        <input type="radio" class="btn-check" name="target_type" id="target_type_odc" value="odc" onchange="toggleTargetType()">
        <label class="btn btn-outline-primary" for="target_type_odc">Berdasarkan ODC (semua ODP di dalamnya)</label>
    </div>
    <div class="form-text">Pilih <b>ODC</b> untuk broadcast otomatis ke semua pelanggan di seluruh ODP yang terhubung ke ODC tersebut.</div>
</div>

<div class="mb-3" id="odp_wrap">
    <div class="d-flex justify-content-between align-items-center">
        <label for="odp" class="form-label mb-0">ODP</label>
        <small class="text-muted">
            Jika belum ada ODP silahkan buat dulu
            <a href="odp.php"  target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
        </small>
    </div>
    <select required class="form-select mt-1" id="odp" name="odp" onchange="loadPackages()">
        <option disabled selected value="">-- Pilih ODP --</option>
    </select>
</div>

<div class="mb-3" id="odc_wrap" style="display:none;">
    <div class="d-flex justify-content-between align-items-center">
        <label for="odc" class="form-label mb-0">ODC</label>
        <small class="text-muted">
            Jika belum ada ODC silahkan buat dulu
            <a href="odp.php"  target='_parent' class="text-primary text-decoration-none fw-semibold">disini</a>
        </small>
    </div>
    <select class="form-select mt-1" id="odc" name="odc" disabled>
        <option disabled selected value="">-- Pilih ODC --</option>
    </select>
</div>


<script>
// ODP, ODC, dan Packages dinamis berdasarkan server dan area
let serverChoices = null;
let odpChoices = null;
let odcChoices = null;

function createSearchableSelect(selectEl, placeholderText) {
    if (!selectEl || !window.Choices) return null;
    return new Choices(selectEl, {
        searchEnabled: true,
        shouldSort: false,
        itemSelectText: '',
        placeholder: true,
        placeholderValue: placeholderText || 'Cari...',
        noResultsText: 'Tidak ditemukan',
        noChoicesText: 'Tidak ada pilihan'
    });
}

function rebuildOdpChoices() {
    const odpEl = document.getElementById('odp');
    if (!odpEl) return;
    if (odpChoices) {
        odpChoices.destroy();
        odpChoices = null;
    }
    odpChoices = createSearchableSelect(odpEl, 'Cari ODP...');
}

function rebuildOdcChoices() {
    const odcEl = document.getElementById('odc');
    if (!odcEl) return;
    if (odcChoices) {
        odcChoices.destroy();
        odcChoices = null;
    }
    odcChoices = createSearchableSelect(odcEl, 'Cari ODC...');
}

function getTargetType() {
    const checked = document.querySelector('input[name="target_type"]:checked');
    return checked ? checked.value : 'odp';
}

function toggleTargetType() {
    const type = getTargetType();
    const odpWrap = document.getElementById('odp_wrap');
    const odcWrap = document.getElementById('odc_wrap');
    const odpSelect = document.getElementById('odp');
    const odcSelect = document.getElementById('odc');

    if (type === 'odc') {
        odpWrap.style.display = 'none';
        odcWrap.style.display = '';
        odpSelect.disabled = true;
        odpSelect.required = false;
        odcSelect.disabled = false;
        odcSelect.required = true;
        loadODC();
    } else {
        odcWrap.style.display = 'none';
        odpWrap.style.display = '';
        odcSelect.disabled = true;
        odcSelect.required = false;
        odpSelect.disabled = false;
        odpSelect.required = true;
        loadODP();
    }
}

function loadODP() {
    let selectedServer = document.getElementById("server").value;
    let selectedArea = document.getElementById("area").value;
    let odpDropdown = document.getElementById("odp");
    odpDropdown.innerHTML = '<option value="">� Sedang memuat ODP...</option>';
    if (selectedServer !== "" && selectedArea !== "") {
        let xhr = new XMLHttpRequest();
        xhr.open("GET", "getdata/get_odp.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                odpDropdown.innerHTML = xhr.responseText;
                rebuildOdpChoices();
            }
        };
        xhr.send();
    }
}

function loadODC() {
    let selectedServer = document.getElementById("server").value;
    let selectedArea = document.getElementById("area").value;
    let odcDropdown = document.getElementById("odc");
    odcDropdown.innerHTML = '<option value="">� Sedang memuat ODC...</option>';
    if (selectedServer !== "" && selectedArea !== "") {
        let xhr = new XMLHttpRequest();
        xhr.open("GET", "getdata/get_odc.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                odcDropdown.innerHTML = xhr.responseText;
                rebuildOdcChoices();
            }
        };
        xhr.send();
    }
}
function loadPackages() {
    let selectedServer = document.getElementById("server").value;
    let selectedArea = document.getElementById("area").value;
    let packageDropdown = document.getElementById("packages");
    packageDropdown.innerHTML = '<option value="">� Sedang memuat Paket...</option>';
    if (selectedServer !== "" && selectedArea !== "") {
        let xhr = new XMLHttpRequest();
        xhr.open("GET", "getdata/get_packages.php?area=" + encodeURIComponent(selectedArea) + "&server=" + encodeURIComponent(selectedServer), true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                packageDropdown.innerHTML = xhr.responseText;
            }
        };
        xhr.send();
    }
}
function loadTargetDropdown() {
    if (getTargetType() === 'odc') {
        loadODC();
    } else {
        loadODP();
    }
}
// Trigger load ODP/ODC setiap kali server/area berubah, sesuai target yang aktif
document.getElementById('server').addEventListener('change', function() {
    setAreaAddCustomer();
    loadTargetDropdown();
});
document.getElementById('area').addEventListener('change', function() {
    loadTargetDropdown();
});
// Initial load jika sudah ada value
document.addEventListener('DOMContentLoaded', function() {
    serverChoices = createSearchableSelect(document.getElementById('server'), 'Cari Server Area...');
    rebuildOdpChoices();
    rebuildOdcChoices();
    if(document.getElementById('server').value && document.getElementById('area').value) {
        loadTargetDropdown();
    }
});
</script>
                      
                    </div>
                    <!-- <button type="submit" class="btn btn-primary mb-2">Kirim </button> -->
                    <button type="button" id="sendBtn" onclick="submitForm()" class="btn btn-primary mb-2">Kirim</button>
                    <button type="button" id="stopBtn" class="btn btn-danger mb-2 ms-2" style="display:none;" onclick="stopProcess()">Stop</button>

                    <div id="processStatus" class="alert alert-info align-items-center mt-3" style="display:none;">
                        <div id="processSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                        <span id="processText">Sedang memproses pengiriman...</span>
                    </div>

                    <div id="progressWrap" class="mt-2" style="display:none;">
                        <div class="progress" style="height: 20px;">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <div id="progressMeta" class="small text-muted mt-1">0/0 • Berhasil: 0 • Gagal: 0</div>
                    </div>

                    <div id="finalSummary" class="alert mt-3" style="display:none;"></div>

                    <div id="debugContainer" class="mt-3" style="display:none;">
                        <label class="form-label">Debug Kirim Pesan</label>
                        <pre id="debugOutput" class="p-3" style="background:#111827;color:#e5e7eb;border-radius:8px;max-height:320px;overflow:auto;"></pre>
                    </div>
                </form>
            </div>
        </div>
        <script>
            let activeController = null;
            let isSubmitting = false;

            function setProcessState(isProcessing, message, statusType = 'info') {
                const sendBtn = document.getElementById('sendBtn');
                const stopBtn = document.getElementById('stopBtn');
                const processStatus = document.getElementById('processStatus');
                const processText = document.getElementById('processText');
                const processSpinner = document.getElementById('processSpinner');

                processStatus.className = 'alert align-items-center mt-3 alert-' + statusType;
                processText.textContent = message;

                if (isProcessing) {
                    processStatus.style.display = 'flex';
                    sendBtn.disabled = true;
                    stopBtn.style.display = '';
                    processSpinner.style.display = '';
                } else {
                    processStatus.style.display = 'flex';
                    sendBtn.disabled = false;
                    stopBtn.style.display = 'none';
                    processSpinner.style.display = 'none';
                }
            }

            function resetProgressUI() {
                const progressWrap = document.getElementById('progressWrap');
                const progressBar = document.getElementById('progressBar');
                const progressMeta = document.getElementById('progressMeta');

                progressWrap.style.display = 'none';
                progressBar.style.width = '0%';
                progressBar.textContent = '0%';
                progressBar.classList.add('progress-bar-animated');
                progressMeta.textContent = '0/0 • Berhasil: 0 • Gagal: 0';
            }

            function updateProgressUI(payload) {
                const progressWrap = document.getElementById('progressWrap');
                const progressBar = document.getElementById('progressBar');
                const progressMeta = document.getElementById('progressMeta');

                const processed = Number(payload.processed || 0);
                const total = Number(payload.total || payload.total_target || 0);
                const successCount = Number(payload.success_count || 0);
                const failedCount = Number(payload.failed_count || 0);
                const percent = total > 0 ? Math.round((processed / total) * 100) : 0;

                progressWrap.style.display = 'block';
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
                progressMeta.textContent = processed + '/' + total + ' • Berhasil: ' + successCount + ' • Gagal: ' + failedCount;

                if (percent >= 100) {
                    progressBar.classList.remove('progress-bar-animated');
                }
            }

            function hideFinalSummary() {
                const finalSummary = document.getElementById('finalSummary');
                finalSummary.style.display = 'none';
                finalSummary.textContent = '';
                finalSummary.className = 'alert mt-3';
            }

            function showFinalSummary(statusType, message) {
                const finalSummary = document.getElementById('finalSummary');
                finalSummary.className = 'alert mt-3 alert-' + statusType;
                finalSummary.textContent = message;
                finalSummary.style.display = 'block';
            }

            function parseStreamLine(line, onEvent) {
                const safeLine = String(line || '').trim();
                if (!safeLine) return;

                const separatorIndex = safeLine.indexOf(':');
                if (separatorIndex < 0) return;

                const eventName = safeLine.slice(0, separatorIndex).toUpperCase();
                const body = safeLine.slice(separatorIndex + 1);
                let payload = {};

                try {
                    payload = body ? JSON.parse(body) : {};
                } catch (e) {
                    payload = { raw: body };
                }

                onEvent(eventName, payload, safeLine);
            }

            async function consumeStreamResponse(response, onEvent) {
                if (!response.body || !response.body.getReader) {
                    const text = await response.text();
                    const lines = text.split(/\r?\n/);
                    lines.forEach(line => parseStreamLine(line, onEvent));
                    return text;
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let allText = '';

                while (true) {
                    const readResult = await reader.read();
                    if (readResult.done) break;

                    const chunk = decoder.decode(readResult.value, { stream: true });
                    allText += chunk;
                    buffer += chunk;

                    const lines = buffer.split(/\r?\n/);
                    buffer = lines.pop() || '';

                    lines.forEach(line => parseStreamLine(line, onEvent));
                }

                const rest = decoder.decode();
                if (rest) {
                    allText += rest;
                    buffer += rest;
                }

                if (buffer.trim() !== '') {
                    parseStreamLine(buffer, onEvent);
                }

                return allText;
            }

            function stopProcess() {
                if (activeController) {
                    activeController.abort();
                }
            }

            async function submitForm() {
                if (isSubmitting) return;

                const form = document.querySelector('form');
                const mode = (form.querySelector('[name="mode"]')?.value || '').toLowerCase();
                const debugContainer = document.getElementById('debugContainer');
                const debugOutput = document.getElementById('debugOutput');
                let finalSummary = null;
                let streamErrorMessage = '';

                // Pastikan hidden input "botname" sudah sinkron dengan checkbox sebelum dikirim
                updateBotSelection('grp_botname', 'botname');

                // Kanal Telegram opt-in: hidden "botname_telegram" cuma diisi kalau
                // toggle "Kirim juga via Telegram" benar2 dicentang, kalau tidak
                // dipaksa kosong (initBotCheckboxGroups() sync awal bisa saja
                // sudah mengisi 'RANDOM' walau grup-nya masih disembunyikan).
                const telegramEnableCb = document.getElementById('telegram_channel_enable');
                if (telegramEnableCb && telegramEnableCb.checked) {
                    updateBotSelection('grp_botname_telegram', 'botname_telegram');
                } else {
                    const tgHidden = document.getElementById('botname_telegram');
                    if (tgHidden) tgHidden.value = '';
                }

                // Kanal Email opt-in: hidden "send_email" cuma diisi '1' kalau
                // checkbox "Kirim juga via Email" dicentang.
                const emailEnableCb = document.getElementById('email_channel_enable');
                const emailHidden = document.getElementById('send_email');
                if (emailHidden) emailHidden.value = (emailEnableCb && emailEnableCb.checked) ? '1' : '';

                debugContainer.style.display = 'none';
                debugOutput.textContent = '';
                resetProgressUI();
                hideFinalSummary();
                isSubmitting = true;
                setProcessState(true, 'Sedang mengirim notifikasi... mohon tunggu', 'info');

                // Ambil semua data dari form
                const formData = new FormData(form);
                formData.set('debug', '1');
                formData.set('stream', '1');
                const payload = new URLSearchParams(formData);
                activeController = new AbortController();

                const endpoint = mode === 'pesan' ? "proses/notif_manual.php" : "proses/notif_gangguan.php";

                try {
                    const response = await fetch(endpoint, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
                        },
                        body: payload.toString(),
                        signal: activeController.signal,
                        cache: "no-store"
                    });

                    const responseText = await consumeStreamResponse(response, function(eventName, payload, rawLine) {
                        // Tampilkan debug untuk semua mode
                        debugContainer.style.display = 'block';
                        debugOutput.textContent += (rawLine + '\n');

                        if (eventName === 'START') {
                            updateProgressUI({
                                processed: 0,
                                total: payload.total_target || 0,
                                success_count: 0,
                                failed_count: 0
                            });
                            setProcessState(true, 'Proses pengiriman dimulai...', 'info');
                        } else if (eventName === 'PROGRESS') {
                            updateProgressUI(payload);

                            const nama = payload.nama ? ' - ' + payload.nama : '';
                            const statusText = payload.status_text ? ' (' + payload.status_text + ')' : '';
                            setProcessState(true, 'Mengirim ke ' + (payload.idpel || '-') + nama + statusText, 'info');
                        } else if (eventName === 'DONE') {
                            finalSummary = payload.summary || null;
                            if (payload.summary) {
                                updateProgressUI({
                                    processed: payload.summary.total_target || 0,
                                    total: payload.summary.total_target || 0,
                                    success_count: payload.summary.success_count || 0,
                                    failed_count: payload.summary.failed_count || 0
                                });
                            }
                        } else if (eventName === 'ERROR') {
                            streamErrorMessage = payload.message || 'Terjadi kesalahan saat proses broadcast.';
                        }
                    });

                    if (mode === 'pesan' && responseText && debugOutput.textContent.trim() === '') {
                        debugContainer.style.display = 'block';
                        debugOutput.textContent = responseText;
                    }

                    if (!response.ok) {
                        const fallbackText = responseText ? String(responseText).trim().split(/\r?\n/)[0] : '';
                        const msg = streamErrorMessage || fallbackText || ('Proses selesai dengan status HTTP ' + response.status + '.');
                        debugContainer.style.display = 'block';
                        if (!debugOutput.textContent && responseText) {
                            debugOutput.textContent = responseText;
                        }
                        setProcessState(false, msg, 'warning');
                        showFinalSummary('warning', 'Broadcast selesai dengan kendala: ' + msg);
                        alert(msg);
                    } else if (streamErrorMessage) {
                        debugContainer.style.display = 'block';
                        if (!debugOutput.textContent && responseText) {
                            debugOutput.textContent = responseText;
                        }
                        setProcessState(false, streamErrorMessage, 'warning');
                        showFinalSummary('warning', 'Broadcast selesai dengan kendala: ' + streamErrorMessage);
                        alert(streamErrorMessage);
                    } else {
                        const successCount = finalSummary ? Number(finalSummary.success_count || 0) : 0;
                        const failedCount = finalSummary ? Number(finalSummary.failed_count || 0) : 0;
                        const totalTarget = finalSummary ? Number(finalSummary.total_target || 0) : 0;
                        const finishedAt = new Date().toLocaleString('id-ID');

                        setProcessState(false, 'Notifikasi selesai. Total: ' + totalTarget + ' | Berhasil: ' + successCount + ' | Gagal: ' + failedCount, 'success');
                        showFinalSummary('success', 'Selesai pada ' + finishedAt + '. Total: ' + totalTarget + ' | Berhasil: ' + successCount + ' | Gagal: ' + failedCount);
                        alert('Notifikasi selesai diproses.');
                    }
                } catch (error) {
                    if (error.name === 'AbortError') {
                        setProcessState(false, 'Proses dihentikan dari browser.', 'warning');
                        showFinalSummary('warning', 'Proses broadcast dihentikan dari browser.');
                        alert('Proses dihentikan.');
                    } else {
                        debugContainer.style.display = 'block';
                        debugOutput.textContent = 'Fetch error: ' + error.message;
                        setProcessState(false, 'Gagal mengirim request ke server.', 'danger');
                        showFinalSummary('danger', 'Broadcast gagal: ' + error.message);
                        alert("Gagal mengirim request. Cek debug.");
                    }
                } finally {
                    activeController = null;
                    isSubmitting = false;
                }
            }
        </script>
   
       

        









  
<?php require 'footer.php'; ?>