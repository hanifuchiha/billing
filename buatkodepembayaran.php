<?php


require 'cek-sesi.php';

$ptanggal = $_GET['tgl'];
$codmete = $_GET['codmete'];
$totalbayar = $_GET['totalbayar'];
$idselect = $_GET['idselect'];
$nama = $_GET['nama'];
$email = $_GET['emailcek'];
$namapaket = $_GET['namapaket'];
$nohpcek = $_GET['nohpcek'];
$pemilik = $_GET['pemilik'];
$apiKey = 'p9jLAOOXmpcKIMAbIIKody7EdgmEVo9j2zy6kZj3';
$privateKey   = 'NmGm1-zG1Fd-UoeR4-Dqy9q-8LiRc';



$sql11 = "DELETE FROM `transaksi` WHERE `IDPEL`='$idselect' AND `STATUS`='PERMINTAAN KODE' ";
if ($conn->query($sql11) === TRUE) {
} else {
}

$sql11 = "SELECT * FROM `transaksi` WHERE IDPEL='$idselect' AND STATUS='PERMINTAAN KODE'";
if ($conn->query($sql11) === TRUE) {
	header("Location: scriptsetting.php?idselect=$idselect&pesan='SUDAH ADA'");
} else {


	///////////////////////permintaan code bayar//////////////////////////////	



	$merchantCode = 'T23436';
	$merchantRef  = $idselect;
	$amount       = $totalbayar;

	$signature = hash_hmac('sha256', $merchantCode . $merchantRef . $amount, $privateKey);


	// result
	// 9f167eba844d1fcb369404e2bda53702e2f78f7aa12e91da6715414e65b8c86a


	$data = [
		'method'         => $codmete,
		'merchant_ref'   => $idselect,
		'amount'         => $totalbayar,
		'customer_name'  => $idselect,
		'customer_email' => 'qts@quenbytekniksejahtera.com',
		'customer_phone' => $nowa,
		'order_items'    => [

			[
				'sku'         => $idselect,
				'name'        => $namapaket,
				'price'       => $totalbayar,
				'quantity'    => 1,
				'product_url' => 'https://quenbytekniksejahtera.com',
				'image_url'   => 'https://quenbytekniksejahtera.com',
			]
		],
		'return_url'   => "https://quenbytekniksejahtera.com/mybilling/portal.php?idselect='$idselect'",
		'expired_time' => (time() + (24 * 60 * 60)), // 24 jam
		'signature'    => hash_hmac('sha256', $merchantCode . $merchantRef . $amount, $privateKey)
	];

	$curl = curl_init();

	curl_setopt_array($curl, [
		CURLOPT_FRESH_CONNECT  => true,
		CURLOPT_URL            => "https://tripay.co.id/api/transaction/create",
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => false,
		CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
		CURLOPT_FAILONERROR    => false,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => http_build_query($data),
		CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
	]);

	$response = curl_exec($curl);
	$error = curl_error($curl);

	curl_close($curl);

	empty($error) ? $response : $error;


	$arrbayar = json_decode($response, true);

	$namapembayaran = $arrbayar['data']['payment_name'];
	$reference = $arrbayar['data']['reference'];
	$pay_code = $arrbayar['data']['pay_code'];
	$status = $arrbayar['data']['status'];
	$expired_time = date('Y-m-d H:i:s', $arrbayar['data']['expired_time']);
	$reference = $ref = $arrbayar['data']['reference'];
	$harusbayar = $arrbayar['data']['amount'];
	$harusbayar1 = number_format($harusbayar, 0, ",", ".");
	$pesan = $arrbayar['message'];



	$session = $user;
	$to = $nowa;
	$text = "Hai bpk/ibu $username \nanda telah telah membuat kode pembayaran TOP UP dengan detail :\n\n\nNana Pembayaran : $namapembayaran\nRef : $reference\n Kode bayar : $pay_code\nStatus pembayaran : $status\nExpired kode : $expired_time\nNominal pembayaran : Rp $harusbayar1  \n\n\nInformasi lanjut, hubungi Customer Service VPN-Q by QUENBY TEKNIK SEJAHTERA di  +62 878-6432-7337 , email: \nqts@quenbytekniksejahtera.com, whatsapp maira di +62 878-6432-7337.\n\n Terima kasih telah mempercayai kami dalam kebutuhan internet anda \n salam $produk \n";
	$waapi = $waapi;
	$ch = curl_init();
	$skipper = "luxury assault recreational vehicle";
	$fields = array('session' => $session, 'to' => $to, 'text' => $text);
	$postvars = '';
	foreach ($fields as $key => $value) {
		$postvars .= $key . "=" . $value . "&";
	}
	$url = "$waapi/send-message";
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, 1);                //0 for a get request
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
	curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	$response = curl_exec($ch);



	$sql11 = "INSERT INTO `transaksi`( `TANGGALBAYAR`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `STATUS`, `BUKTI`,`PEMILIK`,`CEK`) VALUES ('$ptanggal','$idselect','$nama','TOPUP','$totalbayar','PERMINTAAN KODE','$ref','FIBERQ','PERMINTAAN')";
	if ($conn->query($sql11) === TRUE) {

		header("Location: tambahsaldo.php?idselect=$idselect&ref=$ref&pesan=$pesan");
	} else {
		"Error: " . $sql11 . "<br>" . $conn->error;
	}
}
