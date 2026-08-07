<?php
// Endpoint data hotspot untuk Android Qbilling V2
require_once '../config.php';
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $where = [];
        if (!empty($_GET['server'])) $where[] = "pemilik='".mysqli_real_escape_string($conn, $_GET['server'])."'";
        if (!empty($_GET['area'])) $where[] = "area='".mysqli_real_escape_string($conn, $_GET['area'])."'";
        $sql = "SELECT * FROM paket_hotspot" . (count($where) ? " WHERE ".implode(' AND ', $where) : '');
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);
        break;
    case 'add':
        $fields = ['paket','harga','komisi','ratelimit','uptime','area','pemilik'];
        $values = [];
        foreach($fields as $f) $values[$f] = mysqli_real_escape_string($conn, $_POST[$f] ?? '');
        $sql = "INSERT INTO paket_hotspot (".implode(',',array_keys($values)).") VALUES ('".implode("','",array_values($values))."')";
        if(mysqli_query($conn,$sql)) echo json_encode(['success'=>true]);
        else echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
        break;
    case 'edit':
        $id = $_POST['id'] ?? '';
        if($id===''){echo json_encode(['success'=>false,'error'=>'id required']);break;}
        $fields = ['paket','harga','komisi','ratelimit','uptime','area','pemilik'];
        $sets = [];
        foreach($fields as $f) if(isset($_POST[$f])) $sets[] = "$f='".mysqli_real_escape_string($conn,$_POST[$f])."'";
        $sql = "UPDATE paket_hotspot SET ".implode(',', $sets)." WHERE id='".mysqli_real_escape_string($conn,$id)."'";
        if(mysqli_query($conn,$sql)) echo json_encode(['success'=>true]);
        else echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
        break;
    case 'delete':
        $id = $_POST['id'] ?? '';
        if($id===''){echo json_encode(['success'=>false,'error'=>'id required']);break;}
        $sql = "DELETE FROM paket_hotspot WHERE id='".mysqli_real_escape_string($conn,$id)."'";
        if(mysqli_query($conn,$sql)) echo json_encode(['success'=>true]);
        else echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
        break;
    default:
        echo json_encode(['success'=>false,'error'=>'Invalid action']);
}