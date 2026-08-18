<?php
/**
 * cs_call_center_api.php - Signaling & routing sisi PELANGGAN (portal) utk
 * CS Call Center. Sengaja file TERPISAH dari crm/billing/cs_call_center.php
 * (sisi agent/owner) krn beda sistem sesi -- di sini pakai session portal
 * (cek_sesi.php, resolve dari ?cari=<idpel|nowa>), bukan session admin CRM.
 *
 * cek_sesi.php echo HTML (link Bootstrap/jQuery) di baris paling atas --
 * dibungkus output buffering di sini supaya tidak ikut kecampur ke response
 * JSON.
 */

ob_start();
include 'cek_sesi.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

require_once '../callcenter_access_helper.php';
require_once '../cs_call_center_helper.php';
// Wajib dipanggil eksplisit di sini juga -- jangan asumsikan admin/agent
// sudah pernah buka cs_call_center.php duluan (yang men-trigger bootstrap
// ini lewat cek-sesi.php). Kalau pelanggan adalah yang PERTAMA kali hit
// fitur ini, kolom callcenter_* di tabel `user` harus tetap ke-buat di sini.
callcenterAccessEnsureColumn($conn);
csCallCenterEnsureTables($conn);

if (empty($idpel) || empty($pelanggan['IDPEL'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid, silakan login ulang.']);
    exit;
}

// PENTING: $pelanggan['PEMILIK'] BUKAN username akun / 'admin' literal --
// itu credential MikroTik/RADIUS per-server. Resolve owner akun sesungguhnya
// lewat server.user_id (lihat csCallCenterResolveOwnerFromServerPemilik()).
[$ownerId, $ownerKey] = csCallCenterResolveOwnerFromServerPemilik($conn, (string) ($pelanggan['PEMILIK'] ?? ''));
$customerArea = (string) ($pelanggan['AREA'] ?? '');
$customerName = (string) ($pelanggan['NAMA'] ?? '');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Semua action LAIN selain agent_available butuh $ownerKey non-null (dipakai
// sbg parameter string bertipe ketat di beberapa helper) -- kalau server
// pelanggan ini tidak ketemu/tidak ke-link ke akun manapun, tolak halus di
// sini SEBELUM masuk ke action manapun, drpd fatal error di tengah jalan.
if ($ownerId === null && $action !== 'agent_available') {
    echo json_encode(['success' => false, 'message' => 'Akun pemilik server pelanggan ini tidak ditemukan.']);
    exit;
}

if ($action === 'agent_available') {
    if ($ownerId === null) {
        echo json_encode(['success' => true, 'available' => false, 'ice_servers' => []]);
        exit;
    }
    $featureOn = csCallCenterIsFeatureEnabled($conn, $ownerKey);
    $agent = ($featureOn && $ownerId) ? csCallCenterFindAvailableAgent($conn, $ownerId, $customerArea) : null;
    $webrtc = csCallCenterResolveWebrtcConfig($conn, $ownerKey);
    echo json_encode([
        'success' => true,
        'available' => $agent !== null,
        'ice_servers' => csCallCenterBuildIceServersArray($webrtc),
        'debug_mode' => !empty($webrtc['debug_mode']),
    ]);
    exit;
}

if ($action === 'start_call') {
    // Cegah 1 pelanggan bikin banyak call ringing/active bertumpuk kalau
    // double-klik/tab dobel -- cek dulu ada call yg masih hidup miliknya.
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $resExisting = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE customer_idpel='$idpelEsc' AND status IN ('ringing','active') ORDER BY created_at DESC LIMIT 1");
    if ($resExisting && mysqli_num_rows($resExisting) > 0) {
        $existing = csCallCenterExpireStale($conn, mysqli_fetch_assoc($resExisting));
        if (in_array($existing['status'], ['ringing', 'active'], true)) {
            echo json_encode(['success' => true, 'call_id' => $existing['id'], 'status' => $existing['status']]);
            exit;
        }
    }

    $offerSdp = (string) ($_POST['offer_sdp'] ?? '');
    $result = csCallCenterStartCall($conn, $ownerKey, $idpel, $customerName, $customerArea);

    if ($result['status'] === 'no_agent') {
        echo json_encode(['success' => true, 'call_id' => $result['id'], 'status' => 'no_agent']);
        exit;
    }

    $callIdEsc = mysqli_real_escape_string($conn, $result['id']);
    $offerEsc = mysqli_real_escape_string($conn, $offerSdp);
    mysqli_query($conn, "UPDATE cs_call_center_calls SET offer_sdp='$offerEsc' WHERE id='$callIdEsc'");

    echo json_encode(['success' => true, 'call_id' => $result['id'], 'status' => $result['status']]);
    exit;
}

if ($action === 'get') {
    $callId = trim((string) ($_POST['call_id'] ?? $_GET['call_id'] ?? ''));
    $callIdEsc = mysqli_real_escape_string($conn, $callId);
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $res = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' AND customer_idpel='$idpelEsc' LIMIT 1");
    $call = $res ? mysqli_fetch_assoc($res) : null;
    if (!$call) {
        echo json_encode(['success' => false]);
        exit;
    }
    $call = csCallCenterExpireStale($conn, $call);
    echo json_encode([
        'success' => true,
        'status' => $call['status'],
        'agent_username' => $call['agent_username'],
        'answer_sdp' => $call['answer_sdp'],
        'ice_agent' => $call['ice_agent'] ?: '[]',
    ]);
    exit;
}

if ($action === 'ice') {
    // Ditulis dari sisi pelanggan selalu ke kolom ice_customer.
    $callId = trim((string) ($_POST['call_id'] ?? ''));
    $candidate = (string) ($_POST['candidate'] ?? '');
    $callIdEsc = mysqli_real_escape_string($conn, $callId);
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $decoded = json_decode($candidate, true);
    if ($decoded === null) {
        echo json_encode(['success' => false]);
        exit;
    }
    // Baca-lalu-tulis (SELECT->decode->append->UPDATE) race condition sama
    // seperti sisi agent (lihat cs_call_center.php action=ice) -- kandidat ICE
    // dikirim beberapa kali hampir bersamaan, request overlap saling menimpa
    // (lost update), kandidat TURN relay yang paling sering hilang. Ganti jadi
    // 1 pernyataan atomic (JSON_ARRAY_APPEND), pola sama dgn fix walkie-talkie
    // MyNVR (jsonStoreUpdate()).
    $candidateJsonEsc = mysqli_real_escape_string($conn, json_encode($decoded));
    mysqli_query($conn, "UPDATE cs_call_center_calls
        SET ice_customer = JSON_ARRAY_APPEND(COALESCE(ice_customer, '[]'), '$', CAST('$candidateJsonEsc' AS JSON))
        WHERE id='$callIdEsc' AND customer_idpel='$idpelEsc'");
    if (mysqli_affected_rows($conn) === 0) {
        echo json_encode(['success' => false]);
        exit;
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'end') {
    $callId = trim((string) ($_POST['call_id'] ?? ''));
    $callIdEsc = mysqli_real_escape_string($conn, $callId);
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $res = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' AND customer_idpel='$idpelEsc' LIMIT 1");
    $call = $res ? mysqli_fetch_assoc($res) : null;
    if ($call && $call['status'] !== 'ended') {
        if (!empty($call['agent_username'])) {
            csCallCenterReleaseAgent($conn, $call['agent_username']);
        }
        mysqli_query($conn, "UPDATE cs_call_center_calls SET status='ended', ended_at=NOW(), ended_by='customer' WHERE id='$callIdEsc'");
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
