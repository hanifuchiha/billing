<?php
/**
 * Satu-satunya sumber kebenaran utk deteksi PID/status proses FreeRADIUS --
 * SEBELUMNYA radius.php (getFreeradiusPID()) dan getdata/system_status.php
 * (isRadiusServiceActive(), dipakai card "Layanan Radius" di dashboard.php)
 * punya 2 implementasi TERPISAH yang bisa saling tidak sinkron (beda urutan
 * cek pidof/systemctl/pgrep, beda nama proses yang dicari). Sekarang
 * keduanya WAJIB pakai fungsi yang sama di sini.
 */

if (!function_exists('radiusGetFreeradiusPID')) {
    /**
     * Return PID (int > 0) kalau proses FreeRADIUS ketemu, 0 kalau tidak
     * ketemu (service mati), atau null kalau SEMUA metode deteksi gagal
     * dieksekusi (shell_exec disabled/permission error di server ini --
     * beda dari "service mati", ini "tidak bisa dicek sama sekali").
     */
    function radiusGetFreeradiusPID(): ?int
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $anyExecRan = false;

        $pid = @shell_exec('pidof freeradius 2>/dev/null');
        if ($pid !== null) {
            $anyExecRan = true;
            $pid = trim((string)$pid);
            if ($pid !== '') return (int)$pid;
        }

        $pid = @shell_exec('pidof radiusd 2>/dev/null');
        if ($pid !== null) {
            $anyExecRan = true;
            $pid = trim((string)$pid);
            if ($pid !== '') return (int)$pid;
        }

        $output = @shell_exec('systemctl show -p MainPID freeradius 2>/dev/null');
        if ($output !== null) {
            $anyExecRan = true;
            if (preg_match('/MainPID=(\d+)/', trim((string)$output), $m) && (int)$m[1] > 0) {
                return (int)$m[1];
            }
        }

        $pid = @shell_exec("pgrep -f 'freeradius -X' 2>/dev/null");
        if ($pid !== null) {
            $anyExecRan = true;
            $pid = trim((string)$pid);
            if ($pid !== '') return (int)$pid;
        }

        $pid = @shell_exec("pgrep -f 'radiusd|freeradius' 2>/dev/null");
        if ($pid !== null) {
            $anyExecRan = true;
            $pid = trim((string)$pid);
            if ($pid !== '') return (int)$pid;
        }

        // Semua percobaan shell_exec mengembalikan null (bukan string kosong)
        // -> shell_exec kemungkinan di-disable/diblokir di server ini, BUKAN
        // berarti FreeRADIUS-nya mati. Beda kondisi, jangan disamakan.
        return $anyExecRan ? 0 : null;
    }
}

if (!function_exists('radiusGetServiceStatus')) {
    /**
     * Return ['status' => 'active'|'inactive'|'error', 'pid' => int].
     * 'error' = deteksi PID gagal total (lihat radiusGetFreeradiusPID()),
     * BUKAN sama dengan 'inactive' (service benar-benar terkonfirmasi mati).
     */
    function radiusGetServiceStatus(): array
    {
        $pid = radiusGetFreeradiusPID();
        if ($pid === null) {
            return ['status' => 'error', 'pid' => 0];
        }
        return ['status' => $pid > 0 ? 'active' : 'inactive', 'pid' => $pid];
    }
}
