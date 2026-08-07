<?php
include "classes/class.phpmailer.php";

$mail = new PHPMailer; 
$mail->IsSMTP();
$mail->SMTPSecure = ''; 
$mail->Host = "mail.quenbytekniksejahtera.com"; //host masing2 provider email
$mail->SMTPDebug = 2;
$mail->Port = 25;
$mail->SMTPAuth = false;
$mail->Username = "qts@quenbytekniksejahtera.com"; //user email
$mail->Password = "noc@qts"; //password email 
$mail->addAttachment("backups/database_backup_".$filename);
$mail->SetFrom("root"); //set email pengirim
$mail->Subject = "BACKUP DAILY DATABASE  QTS "; //subyek email
$mail->AddAddress("qts@quenbytekniksejahtera.com","Pesan system server");  //tujuan email
$mail->MsgHTML("Backup success");
if($mail->Send()) echo "Message has been sent";
else echo "Failed to sending message";

?>