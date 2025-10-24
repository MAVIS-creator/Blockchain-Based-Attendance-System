<?php
// Shared helper to resolve MAC address for an IP using server ARP/cache.
function get_mac_from_ip($ip)
{
    $mac = null;
    $ip = escapeshellcmd($ip);
    $regex = '/([0-9a-f]{2}[:\\-]){5}[0-9a-f]{2}/i';

    // Try Linux / BSD style first
    if (stripos(PHP_OS, 'WIN') === false) {
        $out = @shell_exec("ip neigh show $ip 2>&1");
        if ($out && preg_match($regex, $out, $m)) {
            $mac = $m[0];
        }
        if (!$mac) {
            $out = @shell_exec("arp -n $ip 2>&1");
            if ($out && preg_match($regex, $out, $m)) {
                $mac = $m[0];
            }
        }
    } else {
        // Windows
        $out = @shell_exec('arp -a');
        if ($out) {
            $lines = preg_split('/\\r?\\n/', $out);
            foreach ($lines as $line) {
                if (strpos($line, $ip) !== false) {
                    if (preg_match($regex, $line, $m)) {
                        $mac = $m[0];
                        break;
                    }
                }
            }
        }
    }

    return $mac ?: 'UNKNOWN';
}

?>
