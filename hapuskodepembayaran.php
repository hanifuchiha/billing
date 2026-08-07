<?php
require 'cek-sesi.php';


ECHO $idref=$_GET['idref'];

    $sql11="DELETE FROM `transaksi` WHERE `BUKTI`='$idref'";
    if ($conn->query($sql11) === TRUE) 
    {
        
		header("Location: tambahsaldo.php");
    }
    else
    {
     "Error: " . $sql11 . "<br>" . $conn->error;
    }




?>