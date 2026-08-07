$targets = @(
    @{ File = 'd:\quenbytekniksejahtera.com\remote2\crm\billing\callbackxendit\callback_xendit.php'; OwnerVar = 'username'; PassField = 'botpass' },
    @{ File = 'd:\quenbytekniksejahtera.com\remote2\crm\billing\callbackmidtrans\callback_midtrans.php'; OwnerVar = 'username'; PassField = 'botpass' },
    @{ File = 'd:\quenbytekniksejahtera.com\remote2\crm\billing\callbackduitku\callback_duitku.php'; OwnerVar = 'username'; PassField = 'botpass' },
    @{ File = 'd:\quenbytekniksejahtera.com\remote2\crm\billing\callbacktripay\callback_tripay.php'; OwnerVar = 'ownerbilling'; PassField = 'password' },
    @{ File = 'd:\quenbytekniksejahtera.com\remote2\crm\billing\callbackpronpay\callback_pronpay.php'; OwnerVar = 'ownerbilling'; PassField = 'password' }
)

foreach ($t in $targets) {
    $file = $t.File
    $ownerVar = $t.OwnerVar
    $passField = $t.PassField

    $content = [System.IO.File]::ReadAllText($file, [System.Text.Encoding]::UTF8)
    $backup = "$file.bak_random_" + (Get-Date -Format 'yyyyMMddHHmmss')
    [System.IO.File]::WriteAllText($backup, $content, [System.Text.UTF8Encoding]::new($false))

    $pattern = @'
(?ms)\$sql1\s*=\s*"SELECT \* FROM `botwa` WHERE `namebot` = '\$botname'";(?:\r?\n)\$query1\s*=\s*mysqli_query\(\$conn, \$sql1\);(?:\r?\n)(?:(?:\r?\n))?(?:\s*//\s*Nomor urut(?:\r?\n)\s*\$nomor\s*=\s*1;(?:\r?\n))?(?:(?:\r?\n))?\s*while\s*\(\$data1\s*=\s*mysqli_fetch_array\(\$query1\)\)\s*\{(?:\r?\n)\s*\$waapi\s*=\s*\$data1\['addressbot'\];(?:\r?\n)\s*\$botpass\s*=\s*\$data1\['__PASSFIELD__'\];(?:\r?\n)\s*\}
'@
    $pattern = $pattern.Replace('__PASSFIELD__', $passField)

    $replacement = @"
// Cek apakah botname adalah 'random'
if (strtoupper(`$botname) == 'RANDOM') {
    `$sql1 = "SELECT * FROM ``botwa`` WHERE ``pemilik`` = '`$$ownerVar'";
    `$query1 = mysqli_query(`$conn, `$sql1);
    if (mysqli_num_rows(`$query1) > 0) {
        `$availableBots = [];
        while (`$data1 = mysqli_fetch_array(`$query1)) {
            `$availableBots[] = ['namebot' => `$data1['namebot'], 'addressbot' => `$data1['addressbot'], 'password' => `$data1['$passField']];
        }
        `$selectedBot = `$availableBots[array_rand(`$availableBots)];
        `$botname = `$selectedBot['namebot']; `$waapi = `$selectedBot['addressbot']; `$botpass = `$selectedBot['password'];
    } else { `$waapi = ''; `$botpass = ''; }
} else {
    `$sql1 = "SELECT * FROM ``botwa`` WHERE ``namebot`` = '`$botname'";
    `$query1 = mysqli_query(`$conn, `$sql1);
    if (mysqli_num_rows(`$query1) > 0) {
        while (`$data1 = mysqli_fetch_array(`$query1)) { `$waapi = `$data1['addressbot']; `$botpass = `$data1['$passField']; }
    } else { `$waapi = ''; `$botpass = ''; }
}
"@

    $count = ([regex]::Matches($content, $pattern)).Count
    if ($count -gt 0) {
        $newContent = [regex]::Replace($content, $pattern, $replacement)
        [System.IO.File]::WriteAllText($file, $newContent, [System.Text.UTF8Encoding]::new($false))
    }

    Write-Host ("{0} => replaced {1}" -f (Split-Path $file -Leaf), $count)
}
