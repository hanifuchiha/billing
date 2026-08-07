<?php

if(isset($_POST['send']))
{
	
	$session="QTS";
	$to=$_POST['to'];
	$text=$_POST['text'];
    $waapi="http://quenbytekniksejahtera.com:415";
  $ch = curl_init();
  $skipper = "luxury assault recreational vehicle";
  $fields = array( 'session'=>$session, 'to'=>$to, 'text'=>$text);
  $postvars = '';
  foreach($fields as $key=>$value) {
    $postvars .= $key . "=" . $value . "&";
  }
ECHO  $url = "$waapi/send-message";
  curl_setopt($ch,CURLOPT_URL,$url);
  curl_setopt($ch,CURLOPT_POST, 1);                //0 for a get request
  curl_setopt($ch,CURLOPT_POSTFIELDS,$postvars);
  curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch,CURLOPT_CONNECTTIMEOUT ,3);
  curl_setopt($ch,CURLOPT_TIMEOUT, 20);
$response = curl_exec($ch);
 
 
 	if($response!="")
																		{
																			$json=json_decode($response,true);
																				if($json['data']['status']==1)
																				{
																		?>		
																					<div class="card text-center">
  <div class="card-header">
  NOTIFIKASI PEMBAYARAN
  </div>
  <div class="card-body">
    <h5 class="card-title"></h5>
    <p class="card-text"></p>
	  <textarea readonly width="100%" cols="80" rows="100"><?php echo $text?></textarea><br>
    <a type="button" class="btn btn-primary" href="tables.php">KEMBALI</A>
  </div>
  <div class="card-footer text-muted">
   TERKIRIM 
  </div>
</div>


																		<?php	

                                              header("location: tables.php");
																				}
																				else
																				{
																			?>		
																					<div class="card text-center">
  <div class="card-header">
  NOTIFIKASI PEMBAYARAN
  </div>
  <div class="card-body">
    <h5 class="card-title"></h5>
    <p class="card-text"></p>
	  <textarea readonly><?php echo $text?></textarea><br>
    <a type="button" class="btn btn-primary" href="../mybilling/server2.php">KEMBALI</A>
  </div>
  <div class="card-footer text-muted">
  TIDAK TERKIRIM 
  </div>
</div>
																			<?php
  header("location: tables.php");
																				}
																		}
																		else
																		{
																			header("location: tables.php");
																		}
																	
  ?>
  
  
  
  
  <?php
  curl_close ($ch);
}
?>