<?php
/**
 * Menyamakan transaksi BERHASIL dengan bulan pembayaran aktual.
 * Invoice PENAGIHAN tetap boleh memakai jadwal jatuh tempo, tetapi begitu
 * pembayaran berhasil PENGUNAAN harus mengikuti bulan TANGGALBAYAR.
 */
if (!function_exists('paymentPeriodParseDate')) {
    function paymentPeriodParseDate(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') return null;
        if (strpos($value, ',') !== false) {
            $parts = explode(',', $value);
            $value = trim((string)end($parts));
        }
        $map = [
            'januari'=>'january','februari'=>'february','maret'=>'march','april'=>'april',
            'mei'=>'may','juni'=>'june','juli'=>'july','agustus'=>'august',
            'september'=>'september','oktober'=>'october','november'=>'november','desember'=>'december',
        ];
        $ts = strtotime(str_ireplace(array_keys($map), array_values($map), $value));
        return $ts === false ? null : $ts;
    }
}

if (!function_exists('paymentPeriodLabel')) {
    function paymentPeriodLabel(int $timestamp): string
    {
        $months = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
        return $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }
}

if (!function_exists('normalizeSuccessfulPaymentPeriods')) {
    function normalizeSuccessfulPaymentPeriods(mysqli $conn, string $owner='airlink', int $days=120): array
    {
        // Pelanggan dengan siklus khusus/prepaid yang periodenya memang sengaja
        // dimajukan dan tidak boleh dinormalisasi mengikuti bulan pembayaran.
        $excludedIdpel = [
            '14120010923.0102.08.08@airlink.co.id', // Raden Ajeng Sri Laksmintowahini
        ];
        $excludedLookup = array_fill_keys(array_map('strtolower', $excludedIdpel), true);
        $conn->query('CREATE TABLE IF NOT EXISTS transaksi_periode_backup_20261001 LIKE transaksi');
        if ($conn->error) return ['checked'=>0,'changed'=>0,'failed'=>1,'error'=>$conn->error];

        $ownerEsc = $conn->real_escape_string($owner);
        $days = max(1, min(3660, $days));
        $sql = "SELECT DISTINCT t.id,t.IDPEL,t.TANGGALBAYAR,t.PENGUNAAN,t.METODE_BAYAR
                FROM transaksi t
                JOIN pelanggan p ON p.IDPEL=t.IDPEL
                JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA
                JOIN user u ON u.id=s.user_id
                WHERE u.USERNAME='$ownerEsc'
                  AND UPPER(TRIM(COALESCE(t.STATUS,'')))='BERHASIL'
                  AND t.waktu >= (NOW() - INTERVAL $days DAY)
                ORDER BY t.id";
        $q = $conn->query($sql);
        if (!$q) return ['checked'=>0,'changed'=>0,'failed'=>1,'error'=>$conn->error];

        $checked=0;$changed=0;$failed=0;
        // Tabel transaksi mempunyai generated column BUKTI_DEDUP_KEY. Kolom
        // generated tidak boleh diberi nilai eksplisit oleh INSERT ... SELECT,
        // jadi backup hanya menyebut kolom biasa dan membiarkan generated column
        // dihitung ulang oleh MariaDB.
        $backupColumns=[];$columnResult=$conn->query('SHOW COLUMNS FROM transaksi');
        while($columnResult&&$column=$columnResult->fetch_assoc()){
            if(stripos((string)$column['Extra'],'GENERATED')!==false)continue;
            $backupColumns[]='`'.str_replace('`','``',(string)$column['Field']).'`';
        }
        if(!$backupColumns)return ['checked'=>0,'changed'=>0,'failed'=>1,'error'=>'Kolom backup transaksi tidak ditemukan'];
        $columnList=implode(',',$backupColumns);
        $backup=$conn->prepare("INSERT IGNORE INTO transaksi_periode_backup_20261001 ($columnList) SELECT $columnList FROM transaksi WHERE id=?");
        $update=$conn->prepare('UPDATE transaksi SET PENGUNAAN=? WHERE id=? AND PENGUNAAN=?');
        if(!$backup||!$update)return ['checked'=>0,'changed'=>0,'failed'=>1,'error'=>$conn->error];
        while ($row=$q->fetch_assoc()) {
            $checked++;
            if(isset($excludedLookup[strtolower(trim((string)$row['IDPEL']))]))continue;
            // Kompensasi gratis dapat sengaja diberikan untuk periode lampau.
            // Hormati periode yang dipilih admin, bukan bulan saat data diinput.
            if(strtolower(trim((string)$row['METODE_BAYAR']))==='kompensasi_free')continue;
            $ts=paymentPeriodParseDate((string)$row['TANGGALBAYAR']);
            if ($ts===null) continue;
            $expected=paymentPeriodLabel($ts);
            $current=trim((string)$row['PENGUNAAN']);
            if (strcasecmp($current,$expected)===0) continue;
            $id=(int)$row['id'];
            $conn->begin_transaction();
            try {
                $backup->bind_param('i',$id);
                if(!$backup->execute()) throw new RuntimeException($backup->error);
                $update->bind_param('sis',$expected,$id,$current);
                if(!$update->execute()) throw new RuntimeException($update->error);
                $conn->commit();
                if($update->affected_rows===1){$changed++;echo '[PERIODE] id='.$id." '$current' -> '$expected'\n";}
            } catch(Throwable $e) {
                $conn->rollback();$failed++;
                echo '[PERIODE GAGAL] id='.$id.' '.$e->getMessage()."\n";
            }
        }
        $backup->close();$update->close();

        // Selaraskan checkpoint Fixed Due Date dengan periode pembayaran
        // terakhir: lunas September -> jatuh tempo berikutnya Oktober, dst.
        // Ini mencegah mekanisme lama (menghitung jumlah baris transaksi) maju
        // satu bulan terlalu jauh saat label periode sebelumnya keliru.
        $checkpointChanged=0;
        $dueDay=25;
        $dueRes=$conn->query("SELECT jatuh_tempo FROM reminder_settings WHERE pemilik='$ownerEsc' LIMIT 1");
        if($dueRes&&($dueRow=$dueRes->fetch_assoc()))$dueDay=max(1,min(28,(int)$dueRow['jatuh_tempo']));
        $fixed=$conn->query("SELECT p.IDPEL,p.MV_NEXT_DUE_CACHE,t.PENGUNAAN
            FROM pelanggan p
            JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA
            JOIN user u ON u.id=s.user_id
            JOIN transaksi t ON t.IDPEL=p.IDPEL AND UPPER(TRIM(t.STATUS))='BERHASIL'
            WHERE u.USERNAME='$ownerEsc'
              AND LOWER(TRIM(COALESCE(p.TIPE_TEMPO,''))) NOT IN ('monthversary','mengikuti_tanggal_bayar')");
        $latest=[];
        $monthNumbers=['januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12];
        while($fixed&&$row=$fixed->fetch_assoc()){
            if(isset($excludedLookup[strtolower(trim((string)$row['IDPEL']))]))continue;
            if(!preg_match('/^\s*([A-Za-z]+)\s+(\d{4})\s*$/u',(string)$row['PENGUNAAN'],$m))continue;
            $month=$monthNumbers[strtolower($m[1])]??0;if(!$month)continue;
            $serial=(int)$m[2]*12+$month;$id=(string)$row['IDPEL'];
            if(!isset($latest[$id])||$serial>$latest[$id]['serial'])$latest[$id]=['serial'=>$serial,'old'=>$row['MV_NEXT_DUE_CACHE']];
        }
        $conn->query('CREATE TABLE IF NOT EXISTS pelanggan_due_backup_20261001 LIKE pelanggan');
        $pelCols=[];$pelColRes=$conn->query('SHOW COLUMNS FROM pelanggan');while($pelColRes&&$c=$pelColRes->fetch_assoc()){if(stripos((string)$c['Extra'],'GENERATED')===false)$pelCols[]='`'.str_replace('`','``',(string)$c['Field']).'`';}
        $pelList=implode(',',$pelCols);
        $pelBackup=$conn->prepare("INSERT IGNORE INTO pelanggan_due_backup_20261001 ($pelList) SELECT $pelList FROM pelanggan WHERE IDPEL=?");
        $pelUpdate=$conn->prepare('UPDATE pelanggan SET MV_NEXT_DUE_CACHE=? WHERE IDPEL=? AND (MV_NEXT_DUE_CACHE <=> ?)');
        if($pelBackup&&$pelUpdate){foreach($latest as $id=>$data){$next=$data['serial']+1;$year=intdiv($next-1,12);$month=(($next-1)%12)+1;$new=sprintf('%04d-%02d-%02d',$year,$month,$dueDay);$old=$data['old'];if($new===$old)continue;$pelBackup->bind_param('s',$id);if(!$pelBackup->execute()){$failed++;continue;}$pelUpdate->bind_param('sss',$new,$id,$old);if($pelUpdate->execute()&&$pelUpdate->affected_rows===1)$checkpointChanged++;else $failed++;}$pelBackup->close();$pelUpdate->close();}

        return compact('checked','changed','checkpointChanged','failed');
    }
}
