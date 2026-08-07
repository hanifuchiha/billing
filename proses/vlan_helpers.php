<?php
if (!function_exists('deriveVlanGatewayIp')) {
    /**
     * Hitung IP gateway (format CIDR, mis. 10.10.10.1/24) untuk VLAN dari data pool
     * (ipawal/ipakhir/iplocal). Dipakai bareng oleh add_vlan.php & edit_vlan.php.
     * Return string kosong jika gagal ditentukan.
     */
    function deriveVlanGatewayIp(string $ipAwal, string $ipAkhir, string $ipLocal): string
    {
        $ipGateway = trim($ipLocal);

        // Normalisasi iplocal yang kadang berisi range/text, ambil IP pertama jika ada
        if ($ipGateway !== '' && !filter_var($ipGateway, FILTER_VALIDATE_IP) && strpos($ipGateway, '/') === false) {
            if (preg_match('/((?:\d{1,3}\.){3}\d{1,3})/', $ipGateway, $m)) {
                $ipGateway = $m[1];
            }
        }

        // Tentukan prefix terbaik dari data pool
        $derivedPrefix = null;
        if (strpos($ipAwal, '/') !== false) {
            $prefixRaw = substr($ipAwal, strpos($ipAwal, '/') + 1);
            if (ctype_digit($prefixRaw)) {
                $p = (int)$prefixRaw;
                if ($p >= 1 && $p <= 32) {
                    $derivedPrefix = $p;
                }
            }
        }
        if ($derivedPrefix === null && filter_var($ipAwal, FILTER_VALIDATE_IP) && filter_var($ipAkhir, FILTER_VALIDATE_IP)) {
            $start = ip2long($ipAwal);
            $end = ip2long($ipAkhir);
            if ($start !== false && $end !== false && $start <= $end) {
                $xor = $start ^ $end;
                $p = 32;
                while ($xor > 0) {
                    $p--;
                    $xor = $xor >> 1;
                }
                if ($p >= 1 && $p <= 32) {
                    $derivedPrefix = $p;
                }
            }
        }
        if ($derivedPrefix === null) {
            $derivedPrefix = 24;
        }

        // Jika iplocal kosong, fallback ke ipawal (atau network+1 bila ipawal berupa CIDR)
        if ($ipGateway === '') {
            if (strpos($ipAwal, '/') !== false) {
                $networkIp = substr($ipAwal, 0, strpos($ipAwal, '/'));
                if (filter_var($networkIp, FILTER_VALIDATE_IP)) {
                    $long = ip2long($networkIp);
                    if ($long !== false) {
                        $ipGateway = long2ip($long + 1);
                    }
                }
            } elseif (filter_var($ipAwal, FILTER_VALIDATE_IP)) {
                $ipGateway = $ipAwal;
            }
        }

        // Pastikan format akhir CIDR
        if ($ipGateway !== '' && strpos($ipGateway, '/') === false && filter_var($ipGateway, FILTER_VALIDATE_IP)) {
            $ipGateway = $ipGateway . '/' . $derivedPrefix;
        }

        if ($ipGateway === '' || strpos($ipGateway, '/') === false) {
            return '';
        }

        return $ipGateway;
    }
}

if (!function_exists('resolveVlanRouterEndpoint')) {
    /**
     * Pecah alamat server (IP polos, "ip:port", atau URL) jadi [host, port].
     * Dipakai bareng oleh add_vlan.php & edit_vlan.php.
     */
    function resolveVlanRouterEndpoint(string $routerEndpoint): array
    {
        $routerHost = $routerEndpoint;
        $routerPort = 8728;

        if (preg_match('/^[a-z]+:\/\//i', $routerEndpoint)) {
            $parsed = parse_url($routerEndpoint);
            if (!empty($parsed['host'])) {
                $routerHost = $parsed['host'];
            }
            if (!empty($parsed['port'])) {
                $routerPort = (int)$parsed['port'];
            }
        } else {
            $pos = strrpos($routerEndpoint, ':');
            if ($pos !== false) {
                $hostPart = substr($routerEndpoint, 0, $pos);
                $portPart = substr($routerEndpoint, $pos + 1);
                if ($hostPart !== '' && ctype_digit($portPart)) {
                    $routerHost = $hostPart;
                    $routerPort = (int)$portPart;
                }
            }
        }

        return [$routerHost, $routerPort];
    }
}
