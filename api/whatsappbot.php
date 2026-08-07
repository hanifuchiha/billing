<?php
// Endpoint WhatsApp Bot untuk Android Qbilling V2
require_once '../config.php';
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $sql = "SELECT * FROM botwa ORDER BY id DESC LIMIT 100";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) $data[] = $row;
        echo json_encode(['success'=>true,'data'=>$data]);
        break;
    case 'add':
        $fields = ['message','to','created_at'];
        $values = [];
        foreach($fields as $f) $values[$f] = mysqli_real_escape_string($conn, $_POST[$f] ?? '');
        $sql = "INSERT INTO botwa (".implode(',',array_keys($values)).") VALUES ('".implode("','",array_values($values))."')";
        if(mysqli_query($conn,$sql)) echo json_encode(['success'=>true]);
        else echo json_encode(['success'=>false,'error'=>mysqli_error($conn)]);
        break;
    default:
        echo json_encode(['success'=>false,'error'=>'Invalid action']);
}