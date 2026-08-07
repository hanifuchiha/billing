<?php
$NAMA=$_POST['name'];
$MAIL=$_POST['email'];
$SUBJECT=$_POST['subject'];
$MESSAGE=$_POST['message'];
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
$mail->SetFrom("$MAIL"); //set email pengirim
$mail->Subject = "Pesan dari website QTS"; //subyek email
$mail->AddAddress("deltaiman91@gmail.com","Pesan dari website QTS");  //tujuan email
$mail->MsgHTML(".$MESSAGE.");
if($mail->Send()) echo "Message has been sent";
else echo "Failed to sending message";







?>