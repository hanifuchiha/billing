<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Voucher_Generator', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Voucher Generator.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once 'voucherhotspot/voucher_template_helper.php';

$owner = $ceknama;
$saved_templates = get_voucher_templates($owner);

$profile_logo_safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$owner);
$profile_logo_file = __DIR__ . "/../../dokumen/logo/profile-{$profile_logo_safe}.png";
$profile_logo_url = file_exists($profile_logo_file) ? "/dokumen/logo/profile-{$profile_logo_safe}.png" : '';
?>
<style>
.vtb-wrap { display: flex; gap: 16px; height: calc(100vh - 220px); min-height: 560px; }
.vtb-sidebar { width: 230px; flex: 0 0 230px; overflow-y: auto; background: #f8fafc; border-radius: 8px; padding: 12px; }
.vtb-canvas-area { flex: 1; overflow: auto; background: #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; padding: 20px; }
.vtb-props { width: 260px; flex: 0 0 260px; overflow-y: auto; background: #f8fafc; border-radius: 8px; padding: 12px; }
.vtb-add-btn { display: block; width: 100%; text-align: left; margin-bottom: 6px; }
#vtbCanvas { position: relative; background: #ffffff; box-shadow: 0 4px 16px rgba(0,0,0,.15); overflow: hidden; }
.tpl-el { position: absolute; box-sizing: border-box; cursor: move; user-select: none; display: flex; align-items: center; overflow: hidden; white-space: pre-wrap; word-break: break-word; }
.tpl-el.selected { outline: 2px dashed #0d6efd; }
.tpl-el img { width: 100%; height: 100%; object-fit: contain; pointer-events: none; }
.tpl-resize-handle { position: absolute; right: -5px; bottom: -5px; width: 12px; height: 12px; background: #0d6efd; border-radius: 50%; cursor: nwse-resize; }
.tpl-el .tpl-el-label { pointer-events: none; }
.vtb-prop-row { margin-bottom: 10px; }
.vtb-prop-row label { font-size: 12px; font-weight: 600; margin-bottom: 2px; display: block; }
</style>

<div class="container-fluid py-4">
  <div class="card shadow-sm mb-3">
    <div class="card-header pb-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h5 class="mb-0"><i class="fas fa-object-group me-1"></i>Editor Template Voucher (Drag &amp; Drop)</h5>
        <p class="text-sm text-muted mb-0">Susun sendiri tampilan voucher: geser (drag) elemen untuk pindah posisi, tarik titik biru di pojok kanan-bawah untuk ubah ukuran. Klik elemen untuk edit gaya di panel kanan.</p>
      </div>
      <a href="vouchergenerator.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Kembali ke Generator</a>
    </div>
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
          <label class="form-label small mb-0">Template Tersimpan</label>
          <select id="vtbLoadSelect" class="form-select form-select-sm" style="min-width:220px;">
            <option value="">-- Template Baru --</option>
            <?php foreach ($saved_templates as $tpl): ?>
              <option value="<?= htmlspecialchars($tpl['id']) ?>"><?= htmlspecialchars($tpl['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label small mb-0">Nama Template</label>
          <input type="text" id="vtbName" class="form-control form-control-sm" maxlength="60" placeholder="Contoh: Voucher Card Biru" value="Template Voucher Saya">
        </div>
        <div>
          <label class="form-label small mb-0">Lebar (px)</label>
          <input type="number" id="vtbCanvasW" class="form-control form-control-sm" style="width:90px;" min="120" max="1200" value="320">
        </div>
        <div>
          <label class="form-label small mb-0">Tinggi (px)</label>
          <input type="number" id="vtbCanvasH" class="form-control form-control-sm" style="width:90px;" min="80" max="1200" value="190">
        </div>
        <div>
          <label class="form-label small mb-0">Warna Latar</label>
          <input type="color" id="vtbCanvasBg" class="form-control form-control-sm form-control-color" value="#ffffff">
        </div>
        <button type="button" class="btn btn-success btn-sm" id="vtbSaveBtn"><i class="fas fa-save me-1"></i>Simpan Template</button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="vtbDeleteBtn" style="display:none;"><i class="fas fa-trash me-1"></i>Hapus Template Ini</button>
        <span id="vtbSaveStatus" class="text-sm ms-2"></span>
      </div>

      <div class="vtb-wrap">
        <div class="vtb-sidebar">
          <b class="d-block mb-2">Tambah Elemen</b>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="logo"><i class="fas fa-image me-1"></i>Logo</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="text"><i class="fas fa-font me-1"></i>Teks Bebas</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="username"><i class="fas fa-user me-1"></i>Username Voucher</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="password"><i class="fas fa-key me-1"></i>Password Voucher</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="paket"><i class="fas fa-box me-1"></i>Nama Paket</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="harga"><i class="fas fa-money-bill me-1"></i>Harga</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="uptime"><i class="fas fa-clock me-1"></i>Masa Aktif / Uptime</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="qrcode"><i class="fas fa-qrcode me-1"></i>QR Code Login</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="login"><i class="fas fa-link me-1"></i>URL Login</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="nocs"><i class="fas fa-headset me-1"></i>Nomor CS</button>
          <button type="button" class="btn btn-outline-primary btn-sm vtb-add-btn" data-add="shape"><i class="fas fa-square me-1"></i>Kotak / Shape</button>
          <hr>
          <small class="text-muted">Elemen data (Username/Password/dst) otomatis terisi dari voucher asli saat dicetak -- di sini cuma pratinjau nama field-nya.</small>
        </div>

        <div class="vtb-canvas-area">
          <div id="vtbCanvas"></div>
        </div>

        <div class="vtb-props" id="vtbPropsPanel">
          <b class="d-block mb-2">Properti Elemen</b>
          <p class="text-muted small" id="vtbNoSelection">Klik salah satu elemen di kanvas untuk mengatur posisi/gaya di sini.</p>
          <div id="vtbPropsForm" style="display:none;">
            <div class="vtb-prop-row" id="vtbPropTextWrap">
              <label>Isi Teks</label>
              <input type="text" id="vtbPropText" class="form-control form-control-sm" maxlength="200">
            </div>
            <div class="vtb-prop-row d-flex gap-2">
              <div style="flex:1"><label>X</label><input type="number" id="vtbPropX" class="form-control form-control-sm"></div>
              <div style="flex:1"><label>Y</label><input type="number" id="vtbPropY" class="form-control form-control-sm"></div>
            </div>
            <div class="vtb-prop-row d-flex gap-2">
              <div style="flex:1"><label>Lebar</label><input type="number" id="vtbPropW" class="form-control form-control-sm"></div>
              <div style="flex:1"><label>Tinggi</label><input type="number" id="vtbPropH" class="form-control form-control-sm"></div>
            </div>
            <div class="vtb-prop-row" id="vtbPropFontWrap">
              <label>Ukuran Font</label>
              <input type="number" id="vtbPropFontSize" class="form-control form-control-sm" min="6" max="96">
            </div>
            <div class="vtb-prop-row d-flex gap-2" id="vtbPropColorWrap">
              <div style="flex:1"><label>Warna Teks</label><input type="color" id="vtbPropColor" class="form-control form-control-sm form-control-color"></div>
              <div style="flex:1"><label>Warna Latar</label><input type="color" id="vtbPropBgColor" class="form-control form-control-sm form-control-color"></div>
            </div>
            <div class="vtb-prop-row form-check">
              <input type="checkbox" class="form-check-input" id="vtbPropBgTransparent">
              <label class="form-check-label small" for="vtbPropBgTransparent">Latar transparan (abaikan warna latar)</label>
            </div>
            <div class="vtb-prop-row d-flex gap-3" id="vtbPropStyleWrap">
              <div class="form-check"><input type="checkbox" class="form-check-input" id="vtbPropBold"><label class="form-check-label small" for="vtbPropBold">Bold</label></div>
              <div class="form-check"><input type="checkbox" class="form-check-input" id="vtbPropItalic"><label class="form-check-label small" for="vtbPropItalic">Italic</label></div>
            </div>
            <div class="vtb-prop-row" id="vtbPropAlignWrap">
              <label>Perataan Teks</label>
              <select id="vtbPropAlign" class="form-select form-select-sm">
                <option value="left">Kiri</option>
                <option value="center">Tengah</option>
                <option value="right">Kanan</option>
              </select>
            </div>
            <div class="vtb-prop-row">
              <label>Radius Sudut</label>
              <input type="number" id="vtbPropRadius" class="form-control form-control-sm" min="0" max="200">
            </div>
            <div class="vtb-prop-row">
              <label>Rotasi (derajat)</label>
              <input type="number" id="vtbPropRotate" class="form-control form-control-sm" min="-180" max="180">
            </div>
            <div class="vtb-prop-row">
              <label>Lapisan (z-index)</label>
              <input type="number" id="vtbPropZ" class="form-control form-control-sm" min="0" max="999">
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm w-100" id="vtbDeleteElBtn"><i class="fas fa-trash me-1"></i>Hapus Elemen Ini</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const VTB_TEMPLATES = <?= json_encode($saved_templates, JSON_UNESCAPED_UNICODE) ?>;
const VTB_OWNER_LOGO = <?= json_encode($profile_logo_url) ?>;
const VTB_DUMMY_QR = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=preview';

const VTB_LABELS = {
  logo: 'LOGO', text: '', username: '{{USERNAME}}', password: '{{PASSWORD}}',
  paket: '{{PAKET}}', harga: '{{HARGA}}', uptime: '{{MASA AKTIF}}',
  qrcode: '', login: '{{LOGIN URL}}', nocs: '{{NO CS}}', shape: ''
};

let vtbElements = [];
let vtbSelectedId = null;
let vtbIdCounter = 1;

function vtbNewId() { return 'el_' + (Date.now()) + '_' + (vtbIdCounter++); }

function vtbDefaultElement(type) {
  const base = { id: vtbNewId(), type: type, x: 20, y: 20, w: 120, h: 30, z: 1,
    text: type === 'text' ? 'Teks baru' : '', fontSize: 14, color: '#000000',
    bgColor: 'transparent', bold: false, italic: false, align: 'left', radius: 0, rotate: 0 };
  if (type === 'logo') { base.w = 60; base.h = 60; }
  if (type === 'qrcode') { base.w = 80; base.h = 80; }
  if (type === 'shape') { base.w = 100; base.h = 40; base.bgColor = '#0d6efd'; }
  if (type === 'username' || type === 'password') { base.fontSize = 20; base.bold = true; }
  return base;
}

function vtbElementDisplayContent(el) {
  if (el.type === 'text') return el.text || '';
  if (el.type === 'logo') return VTB_OWNER_LOGO ? '<img src="' + VTB_OWNER_LOGO + '">' : '<span class="tpl-el-label">LOGO</span>';
  if (el.type === 'qrcode') return '<img src="' + VTB_DUMMY_QR + '">';
  if (el.type === 'shape') return '';
  return '<span class="tpl-el-label">' + (VTB_LABELS[el.type] || '') + '</span>';
}

function vtbRenderCanvas() {
  const canvas = document.getElementById('vtbCanvas');
  const w = parseInt(document.getElementById('vtbCanvasW').value || 320, 10);
  const h = parseInt(document.getElementById('vtbCanvasH').value || 190, 10);
  const bg = document.getElementById('vtbCanvasBg').value || '#ffffff';
  canvas.style.width = w + 'px';
  canvas.style.height = h + 'px';
  canvas.style.background = bg;
  canvas.innerHTML = '';

  vtbElements.slice().sort((a, b) => (a.z || 0) - (b.z || 0)).forEach(function (el) {
    const div = document.createElement('div');
    div.className = 'tpl-el' + (el.id === vtbSelectedId ? ' selected' : '');
    div.dataset.id = el.id;
    div.style.left = el.x + 'px';
    div.style.top = el.y + 'px';
    div.style.width = el.w + 'px';
    div.style.height = el.h + 'px';
    div.style.zIndex = el.z || 1;
    div.style.fontSize = (el.fontSize || 14) + 'px';
    div.style.color = el.color || '#000';
    div.style.background = el.bgColor === 'transparent' ? 'transparent' : el.bgColor;
    div.style.fontWeight = el.bold ? 'bold' : 'normal';
    div.style.fontStyle = el.italic ? 'italic' : 'normal';
    div.style.justifyContent = el.align === 'center' ? 'center' : (el.align === 'right' ? 'flex-end' : 'flex-start');
    div.style.textAlign = el.align || 'left';
    div.style.borderRadius = (el.radius || 0) + 'px';
    div.style.transform = 'rotate(' + (el.rotate || 0) + 'deg)';
    div.innerHTML = vtbElementDisplayContent(el);

    const handle = document.createElement('div');
    handle.className = 'tpl-resize-handle';
    div.appendChild(handle);

    div.addEventListener('mousedown', function (ev) {
      if (ev.target === handle) return;
      ev.preventDefault();
      // Pilih elemen TANPA render ulang kanvas (rebuild DOM di tengah drag
      // bikin node "div" ini jadi node lama yg sudah lepas dari halaman --
      // makanya sebelumnya gerakan mouse tidak kelihatan sama sekali sampai
      // dilepas). Cukup toggle class .selected di DOM yang sudah ada.
      vtbSetSelectedLight(el.id, div);
      const startX = ev.clientX, startY = ev.clientY;
      const origX = el.x, origY = el.y;
      function onMove(e2) {
        el.x = Math.max(0, origX + (e2.clientX - startX));
        el.y = Math.max(0, origY + (e2.clientY - startY));
        div.style.left = el.x + 'px';
        div.style.top = el.y + 'px';
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        vtbUpdatePropsForm();
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });

    handle.addEventListener('mousedown', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      vtbSetSelectedLight(el.id, div);
      const startX = ev.clientX, startY = ev.clientY;
      const origW = el.w, origH = el.h;
      function onMove(e2) {
        el.w = Math.max(10, origW + (e2.clientX - startX));
        el.h = Math.max(10, origH + (e2.clientY - startY));
        div.style.width = el.w + 'px';
        div.style.height = el.h + 'px';
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
        vtbUpdatePropsForm();
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });

    canvas.appendChild(div);
  });
}

function vtbSelectElement(id) {
  vtbSelectedId = id;
  vtbRenderCanvas();
  vtbUpdatePropsForm();
}

// Versi ringan dipakai SAAT MULAI drag/resize -- cuma pindah class .selected
// di DOM yang sudah ada (tidak vtbRenderCanvas()/rebuild), supaya node yang
// sedang di-drag (variabel "div" di closure drag handler) tetap node yang
// SAMA/live di halaman selama mousemove berjalan, bukan node basi yang sudah
// diganti oleh render ulang.
function vtbSetSelectedLight(id, domEl) {
  if (vtbSelectedId === id) return; // sudah terpilih, tidak perlu apa2
  vtbSelectedId = id;
  document.querySelectorAll('#vtbCanvas .tpl-el.selected').forEach(function (e) {
    e.classList.remove('selected');
  });
  if (domEl) domEl.classList.add('selected');
  vtbUpdatePropsForm();
}

function vtbGetSelected() {
  return vtbElements.find(function (e) { return e.id === vtbSelectedId; });
}

function vtbUpdatePropsForm() {
  const el = vtbGetSelected();
  const form = document.getElementById('vtbPropsForm');
  const noSel = document.getElementById('vtbNoSelection');
  if (!el) {
    form.style.display = 'none';
    noSel.style.display = 'block';
    return;
  }
  form.style.display = 'block';
  noSel.style.display = 'none';

  document.getElementById('vtbPropTextWrap').style.display = (el.type === 'text') ? 'block' : 'none';
  document.getElementById('vtbPropText').value = el.text || '';
  document.getElementById('vtbPropX').value = Math.round(el.x);
  document.getElementById('vtbPropY').value = Math.round(el.y);
  document.getElementById('vtbPropW').value = Math.round(el.w);
  document.getElementById('vtbPropH').value = Math.round(el.h);
  document.getElementById('vtbPropFontSize').value = el.fontSize;
  document.getElementById('vtbPropColor').value = el.color;
  document.getElementById('vtbPropBgColor').value = (el.bgColor === 'transparent') ? '#ffffff' : el.bgColor;
  document.getElementById('vtbPropBgTransparent').checked = (el.bgColor === 'transparent');
  document.getElementById('vtbPropBold').checked = !!el.bold;
  document.getElementById('vtbPropItalic').checked = !!el.italic;
  document.getElementById('vtbPropAlign').value = el.align || 'left';
  document.getElementById('vtbPropRadius').value = el.radius || 0;
  document.getElementById('vtbPropRotate').value = el.rotate || 0;
  document.getElementById('vtbPropZ').value = el.z || 1;

  const isTextLike = ['text', 'username', 'password', 'paket', 'harga', 'uptime', 'login', 'nocs'].includes(el.type);
  document.getElementById('vtbPropFontWrap').style.display = isTextLike ? 'block' : 'none';
  document.getElementById('vtbPropStyleWrap').style.display = isTextLike ? 'flex' : 'none';
  document.getElementById('vtbPropAlignWrap').style.display = isTextLike ? 'block' : 'none';
}

function vtbBindPropInput(id, applyFn) {
  document.getElementById(id).addEventListener('input', function () {
    const el = vtbGetSelected();
    if (!el) return;
    applyFn(el, this);
    vtbRenderCanvas();
  });
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const el = vtbDefaultElement(btn.dataset.add);
      vtbElements.push(el);
      vtbSelectElement(el.id);
    });
  });

  ['vtbCanvasW', 'vtbCanvasH', 'vtbCanvasBg'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', vtbRenderCanvas);
  });

  vtbBindPropInput('vtbPropText', function (el, input) { el.text = input.value; });
  vtbBindPropInput('vtbPropX', function (el, input) { el.x = parseFloat(input.value) || 0; });
  vtbBindPropInput('vtbPropY', function (el, input) { el.y = parseFloat(input.value) || 0; });
  vtbBindPropInput('vtbPropW', function (el, input) { el.w = Math.max(10, parseFloat(input.value) || 10); });
  vtbBindPropInput('vtbPropH', function (el, input) { el.h = Math.max(10, parseFloat(input.value) || 10); });
  vtbBindPropInput('vtbPropFontSize', function (el, input) { el.fontSize = parseInt(input.value, 10) || 14; });
  vtbBindPropInput('vtbPropColor', function (el, input) { el.color = input.value; });
  vtbBindPropInput('vtbPropBgColor', function (el, input) { if (!document.getElementById('vtbPropBgTransparent').checked) el.bgColor = input.value; });
  vtbBindPropInput('vtbPropBgTransparent', function (el, input) { el.bgColor = input.checked ? 'transparent' : document.getElementById('vtbPropBgColor').value; });
  vtbBindPropInput('vtbPropBold', function (el, input) { el.bold = input.checked; });
  vtbBindPropInput('vtbPropItalic', function (el, input) { el.italic = input.checked; });
  vtbBindPropInput('vtbPropAlign', function (el, input) { el.align = input.value; });
  vtbBindPropInput('vtbPropRadius', function (el, input) { el.radius = parseInt(input.value, 10) || 0; });
  vtbBindPropInput('vtbPropRotate', function (el, input) { el.rotate = parseInt(input.value, 10) || 0; });
  vtbBindPropInput('vtbPropZ', function (el, input) { el.z = parseInt(input.value, 10) || 1; });

  document.getElementById('vtbDeleteElBtn').addEventListener('click', function () {
    if (!vtbSelectedId) return;
    if (!confirm('Hapus elemen ini dari kanvas?')) return;
    vtbElements = vtbElements.filter(function (e) { return e.id !== vtbSelectedId; });
    vtbSelectedId = null;
    vtbRenderCanvas();
    vtbUpdatePropsForm();
  });

  document.getElementById('vtbCanvas').addEventListener('mousedown', function (ev) {
    if (ev.target === this) {
      vtbSelectedId = null;
      vtbRenderCanvas();
      vtbUpdatePropsForm();
    }
  });

  let currentTemplateId = '';

  document.getElementById('vtbLoadSelect').addEventListener('change', function () {
    currentTemplateId = this.value;
    if (!currentTemplateId) {
      vtbElements = [];
      vtbSelectedId = null;
      document.getElementById('vtbName').value = 'Template Voucher Saya';
      document.getElementById('vtbCanvasW').value = 320;
      document.getElementById('vtbCanvasH').value = 190;
      document.getElementById('vtbCanvasBg').value = '#ffffff';
      document.getElementById('vtbDeleteBtn').style.display = 'none';
      vtbRenderCanvas();
      vtbUpdatePropsForm();
      return;
    }
    const tpl = VTB_TEMPLATES.find(function (t) { return t.id === currentTemplateId; });
    if (!tpl) return;
    document.getElementById('vtbName').value = tpl.name;
    document.getElementById('vtbCanvasW').value = tpl.canvas.w;
    document.getElementById('vtbCanvasH').value = tpl.canvas.h;
    document.getElementById('vtbCanvasBg').value = tpl.canvas.bg;
    vtbElements = JSON.parse(JSON.stringify(tpl.elements || []));
    vtbSelectedId = null;
    document.getElementById('vtbDeleteBtn').style.display = 'inline-block';
    vtbRenderCanvas();
    vtbUpdatePropsForm();
  });

  document.getElementById('vtbSaveBtn').addEventListener('click', function () {
    const payload = {
      id: currentTemplateId || '',
      name: document.getElementById('vtbName').value || 'Template Tanpa Nama',
      canvas: {
        w: parseInt(document.getElementById('vtbCanvasW').value, 10) || 320,
        h: parseInt(document.getElementById('vtbCanvasH').value, 10) || 190,
        bg: document.getElementById('vtbCanvasBg').value || '#ffffff'
      },
      elements: vtbElements
    };
    const statusEl = document.getElementById('vtbSaveStatus');
    statusEl.textContent = 'Menyimpan...';
    fetch('proses/save_voucher_template.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (res.success) {
        statusEl.textContent = '✅ Tersimpan.';
        statusEl.className = 'text-sm ms-2 text-success';
        setTimeout(function () { window.location.reload(); }, 700);
      } else {
        statusEl.textContent = '❌ ' + (res.message || 'Gagal menyimpan.');
        statusEl.className = 'text-sm ms-2 text-danger';
      }
    }).catch(function () {
      statusEl.textContent = '❌ Gagal menghubungi server.';
      statusEl.className = 'text-sm ms-2 text-danger';
    });
  });

  document.getElementById('vtbDeleteBtn').addEventListener('click', function () {
    if (!currentTemplateId) return;
    if (!confirm('Hapus template ini secara permanen?')) return;
    const fd = new FormData();
    fd.append('id', currentTemplateId);
    fetch('proses/delete_voucher_template.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          window.location.reload();
        } else {
          alert(res.message || 'Gagal menghapus template.');
        }
      });
  });

  vtbRenderCanvas();
});
</script>

<?php require 'footer.php'; ?>
