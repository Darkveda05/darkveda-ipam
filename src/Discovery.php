<?php
declare(strict_types=1);

namespace DarkVeda;

use PDOException;

/**
 * DarkVeda IPAM — network discovery engine.
 *
 * Ping-sweeps an IPv4 subnet (parallel ICMP via the system `ping`
 * binary), harvests MAC addresses from the local ARP/neighbour table,
 * resolves hostnames via reverse DNS, then reconciles results against
 * the IPAM database:
 *
 *   - unknown responding hosts  -> discovered_hosts (status "new") + admin alert
 *   - known hosts               -> ip_addresses.last_seen updated,
 *                                  missing MAC / hostname auto-filled
 *   - MAC different from record -> status "changed" + admin alert
 *
 * When an SNMP credential is available (per-subnet binding or the default
 * profile), each responding host is also polled for sysName, sysDescr,
 * chassis serial, interface MACs and LLDP/CDP neighbours. Neighbour data
 * feeds the topology graph.
 */
final class Discovery
{
    public const MAX_HOSTS = 1024;   // /22 and larger only, per run
    public const PARALLEL  = 64;     // concurrent pings

    /**
     * Scan one subnet. Returns a summary array or throws \RuntimeException.
     * @return array{run_id:int,scanned:int,alive:int,new:int,changed:int,unreachable_known:int}
     */
    public static function scanSubnet(int $subnetId, ?int $userId = null): array
    {
        $subnet = Database::one('SELECT * FROM subnets WHERE id = ?', [$subnetId]);
        if (!$subnet) {
            throw new \RuntimeException('Subnet not found.');
        }
        if ((int)$subnet['ip_version'] !== 4) {
            throw new \RuntimeException('Discovery currently supports IPv4 subnets only.');
        }
        if ((int)$subnet['prefix_len'] < 22) {
            throw new \RuntimeException('Subnet too large — discovery is limited to /22 or smaller (max ' . self::MAX_HOSTS . ' hosts).');
        }
        if (!self::pingAvailable()) {
            throw new \RuntimeException('The `ping` binary is not available to PHP; install iputils-ping or run bin/discover.php from a host that has it.');
        }

        Database::exec(
            'INSERT INTO discovery_runs (subnet_id, triggered_by) VALUES (?,?)',
            [$subnetId, $userId]
        );
        $runId = (int)Database::lastId();

        $ips   = IpTools::enumerateV4($subnet['network_bin'], (int)$subnet['prefix_len'], self::MAX_HOSTS);
        $alive = self::pingSweep($ips, self::PARALLEL);
        usleep(500000);                          // let the kernel settle neighbour entries
        $arp   = self::arpTable();
        $names = self::resolveHostnames($alive); // PTR + NetBIOS + mDNS, batched
        $cred  = self::credentialFor($subnet);
        $snmp  = ($cred && Snmp::available()) ? self::pollSnmp($alive, $cred) : [];

        // Known records in this subnet, keyed by printable address
        $known = [];
        foreach (Database::q('SELECT * FROM ip_addresses WHERE subnet_id = ?', [$subnetId]) as $row) {
            $known[$row['address']] = $row;
        }

        $newCount = 0;
        $changedCount = 0;

        foreach ($alive as $ip) {
            $info     = $snmp[$ip] ?? null;
            $mac      = $arp[$ip] ?? ($info['macs'][0] ?? null);
            $hostname = $names[$ip] ?? ($info['sysname'] ?? null);

            if (isset($known[$ip])) {
                $rec = $known[$ip];
                $recordedMac = $rec['mac_address'] ? strtolower((string)$rec['mac_address']) : null;
                $seenMac     = $mac ? strtolower($mac) : null;

                // liveness + auto-fill missing details ("update device details when they change")
                Database::exec(
                    'UPDATE ip_addresses SET last_seen = NOW(),
                            mac_address      = COALESCE(mac_address, ?),
                            hostname         = COALESCE(hostname, ?),
                            serial_number    = COALESCE(serial_number, ?),
                            os               = COALESCE(os, ?),
                            software_version = COALESCE(software_version, ?)
                     WHERE id = ?',
                    [
                        $seenMac, $hostname,
                        $info['serial'] ?? null,
                        $info['os'] ?? null,
                        $info['version'] ?? null,
                        (int)$rec['id'],
                    ]
                );

                if ($recordedMac !== null && $seenMac !== null && $recordedMac !== $seenMac) {
                    // device swap / details changed
                    self::notifyAdmins("Discovery: {$ip} ({$subnet['cidr']}) now answers with MAC {$seenMac} (recorded: {$recordedMac})");
                    $changedCount++;
                    self::upsertDiscovered($subnetId, $ip, $seenMac, $hostname, 'changed', $runId);
                } else {
                    self::upsertDiscovered($subnetId, $ip, $seenMac, $hostname, 'known', $runId);
                }
            } else {
                // unknown device
                $isNew = self::upsertDiscovered($subnetId, $ip, $mac, $hostname, 'new', $runId);
                if ($isNew) {
                    $newCount++;
                    self::notifyAdmins("Discovery: unknown device {$ip}" . ($mac ? " ({$mac})" : '') . " appeared in {$subnet['cidr']}");
                }
            }
        }

        // Known + active records that did NOT respond: candidates for "unused"
        $aliveSet = array_flip($alive);
        $unreachableKnown = 0;
        foreach ($known as $addr => $rec) {
            if ($rec['status'] === 'active' && !isset($aliveSet[$addr])) {
                $unreachableKnown++;
            }
        }

        Database::exec(
            'UPDATE discovery_runs
             SET finished_at = NOW(), hosts_scanned = ?, hosts_alive = ?, new_hosts = ?, changed_hosts = ?
             WHERE id = ?',
            [count($ips), count($alive), $newCount, $changedCount, $runId]
        );

        $macsFound = count(array_intersect_key($arp, array_flip($alive)));
        $links     = $snmp ? self::storeNeighbours($snmp) : 0;

        return [
            'run_id'            => $runId,
            'scanned'           => count($ips),
            'alive'             => count($alive),
            'new'               => $newCount,
            'changed'           => $changedCount,
            'unreachable_known' => $unreachableKnown,
            'macs_found'        => $macsFound,
            'names_found'       => count($names),
            'snmp_found'        => count($snmp),
            'links_found'       => $links,
        ];
    }

    /** Insert/update a discovered_hosts row. Returns true when the host is brand new. */
    private static function upsertDiscovered(
        int $subnetId, string $ip, ?string $mac, ?string $hostname, string $status, int $runId
    ): bool {
        $bin = inet_pton($ip);
        $existing = Database::one(
            'SELECT id FROM discovered_hosts WHERE subnet_id = ? AND address_bin = ?',
            [$subnetId, $bin]
        );
        if ($existing) {
            Database::exec(
                'UPDATE discovered_hosts
                 SET last_seen = NOW(), last_run_id = ?, status = ?,
                     mac_address = COALESCE(?, mac_address),
                     hostname    = COALESCE(?, hostname)
                 WHERE id = ?',
                [$runId, $status, $mac, $hostname, (int)$existing['id']]
            );
            return false;
        }
        Database::exec(
            'INSERT INTO discovered_hosts (subnet_id, address, address_bin, mac_address, hostname, status, last_run_id)
             VALUES (?,?,?,?,?,?,?)',
            [$subnetId, $ip, $bin, $mac, $hostname, $status, $runId]
        );
        return true;
    }

    /** Parallel ICMP sweep. Returns the list of responding addresses. */
    public static function pingSweep(array $ips, int $parallel = self::PARALLEL, int $timeoutSec = 1): array
    {
        $alive  = [];
        $queue  = array_values($ips);
        $procs  = [];

        $spawn = function (string $ip) use ($timeoutSec) {
            $cmd = ['ping', '-n', '-q', '-c', '1', '-W', (string)$timeoutSec, $ip];
            $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            return $p ?: null;
        };

        while ($queue || $procs) {
            while ($queue && count($procs) < $parallel) {
                $ip = array_shift($queue);
                if ($h = $spawn($ip)) {
                    $procs[$ip] = $h;
                }
            }
            usleep(20000);
            foreach ($procs as $ip => $h) {
                $st = proc_get_status($h);
                if (!$st['running']) {
                    if ($st['exitcode'] === 0) {
                        $alive[] = $ip;
                    }
                    proc_close($h);
                    unset($procs[$ip]);
                }
            }
        }
        return $alive;
    }

    /**
     * ip -> MAC map merged from every available neighbour source.
     * NOTE: L2-limited — only hosts on the scanner's own segment appear.
     * Behind a bridged/NAT Docker network this map will be empty; run the
     * container with network_mode: host to see MACs.
     */
    public static function arpTable(): array
    {
        $map = [];

        $out = @shell_exec('ip neigh show 2>/dev/null') ?: '';
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^(\S+)\s.*lladdr\s+([0-9a-f:]{17})/i', $line, $m)) {
                $map[$m[1]] = strtolower($m[2]);
            }
        }

        if (is_readable('/proc/net/arp')) {
            foreach (array_slice(file('/proc/net/arp'), 1) as $line) {
                $cols = preg_split('/\s+/', trim($line));
                if (isset($cols[0], $cols[3]) && $cols[3] !== '00:00:00:00:00:00'
                    && preg_match('/^[0-9a-f:]{17}$/i', $cols[3])) {
                    $map[$cols[0]] ??= strtolower($cols[3]);
                }
            }
        }

        $out = @shell_exec('arp -an 2>/dev/null') ?: '';
        if (preg_match_all('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-f:]{17})/i', $out, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $map[$m[1]] ??= strtolower($m[2]);
            }
        }

        return $map;
    }

    /** Reverse DNS with sane failure mode. */
    public static function reverseDns(string $ip): ?string
    {
        $h = @gethostbyaddr($ip);
        return ($h && $h !== $ip) ? $h : null;
    }

    public static function pingAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $out = @shell_exec('command -v ping 2>/dev/null');
        return is_string($out) && trim($out) !== '';
    }


    /**
     * Resolve hostnames for a batch of IPs using three sources, in order
     * of preference: reverse DNS (PTR), NetBIOS (Windows/Samba, UDP 137),
     * and mDNS (Linux/macOS/IoT, UDP 5353). NetBIOS and mDNS queries are
     * sent in parallel over single sockets, so a full /24 resolves in a
     * couple of seconds even when nothing answers.
     *
     * @param string[] $ips  @return array<string,string> ip => hostname
     */
    public static function resolveHostnames(array $ips): array
    {
        if (!$ips) {
            return [];
        }
        $names = [];

        // 1. PTR — authoritative when the LAN has reverse zones (uses NSS, so
        //    /etc/hosts and the system resolver both count)
        foreach ($ips as $ip) {
            $h = @gethostbyaddr($ip);
            if ($h && $h !== $ip) {
                $names[$ip] = rtrim($h, '.');
            }
        }

        $remaining = array_values(array_diff($ips, array_keys($names)));

        // 2. NetBIOS node-status (NBSTAT) — Windows & Samba boxes
        foreach (self::netbiosNames($remaining) as $ip => $n) {
            $names[$ip] = $n;
        }

        $remaining = array_values(array_diff($ips, array_keys($names)));

        // 3. mDNS reverse lookup — Linux/macOS/printers/IoT
        foreach (self::mdnsNames($remaining) as $ip => $n) {
            $names[$ip] = $n;
        }

        return $names;
    }

    /** Batched NetBIOS NBSTAT queries (UDP 137). @return array<string,string> */
    public static function netbiosNames(array $ips, float $waitSec = 1.5): array
    {
        if (!$ips || !function_exists('socket_create')) {
            return [];
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            return [];
        }
        socket_set_nonblock($sock);

        // NBSTAT query for '*' (wildcard node status)
        $payload = "\xA5\xC4" . "\x00\x00" . "\x00\x01" . "\x00\x00" . "\x00\x00" . "\x00\x00"
                 . "\x20" . 'CKAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' . "\x00"
                 . "\x00\x21" . "\x00\x01";

        foreach ($ips as $ip) {
            @socket_sendto($sock, $payload, strlen($payload), 0, $ip, 137);
        }

        $names = [];
        $deadline = microtime(true) + $waitSec;
        while (microtime(true) < $deadline && count($names) < count($ips)) {
            $buf = ''; $from = ''; $port = 0;
            $n = @socket_recvfrom($sock, $buf, 4096, 0, $from, $port);
            if ($n === false || $n < 57) {
                usleep(20000);
                continue;
            }
            // answer: header(12) + name(34) + type/class(4) + ttl(4) + rdlen(2) = 56, then num_names(1)
            $num = ord($buf[56]);
            for ($i = 0; $i < $num; $i++) {
                $off = 57 + $i * 18;
                if ($off + 18 > strlen($buf)) {
                    break;
                }
                $name  = rtrim(substr($buf, $off, 15));
                $suffix = ord($buf[$off + 15]);
                $flags  = unpack('n', substr($buf, $off + 16, 2))[1];
                // suffix 0x00 + unique (not group) = workstation name
                if ($suffix === 0x00 && !($flags & 0x8000) && $name !== '') {
                    $names[$from] = strtolower($name);
                    break;
                }
            }
        }
        socket_close($sock);
        return $names;
    }

    /** Batched unicast mDNS reverse-PTR queries (UDP 5353). @return array<string,string> */
    public static function mdnsNames(array $ips, float $waitSec = 1.5): array
    {
        if (!$ips || !function_exists('socket_create')) {
            return [];
        }
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if (!$sock) {
            return [];
        }
        socket_set_nonblock($sock);

        foreach ($ips as $ip) {
            $qname = '';
            foreach (array_merge(array_reverse(explode('.', $ip)), ['in-addr', 'arpa']) as $label) {
                $qname .= chr(strlen($label)) . $label;
            }
            $qname .= "\x00";
            // header: id 0, flags 0, 1 question; QTYPE PTR, QCLASS IN with unicast-response bit
            $pkt = "\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00" . $qname . "\x00\x0C" . "\x80\x01";
            @socket_sendto($sock, $pkt, strlen($pkt), 0, $ip, 5353);
        }

        $names = [];
        $deadline = microtime(true) + $waitSec;
        while (microtime(true) < $deadline && count($names) < count($ips)) {
            $buf = ''; $from = ''; $port = 0;
            $n = @socket_recvfrom($sock, $buf, 4096, 0, $from, $port);
            if ($n === false || $n < 12) {
                usleep(20000);
                continue;
            }
            $host = self::extractFirstPtrTarget($buf);
            if ($host !== null) {
                $names[$from] = rtrim(preg_replace('/\.local$/i', '', $host), '.');
            }
        }
        socket_close($sock);
        return $names;
    }

    /** Pull the first PTR RDATA name out of a DNS/mDNS answer packet. */
    private static function extractFirstPtrTarget(string $buf): ?string
    {
        $an = unpack('n', substr($buf, 6, 2))[1] ?? 0;
        if ($an < 1) {
            return null;
        }
        $qd  = unpack('n', substr($buf, 4, 2))[1] ?? 0;
        $pos = 12;
        // skip questions
        for ($i = 0; $i < $qd; $i++) {
            $pos = self::skipDnsName($buf, $pos);
            $pos += 4;
        }
        // first answer
        $pos = self::skipDnsName($buf, $pos);
        if ($pos + 10 > strlen($buf)) {
            return null;
        }
        $type = unpack('n', substr($buf, $pos, 2))[1];
        $pos += 8;
        $rdlen = unpack('n', substr($buf, $pos, 2))[1];
        $pos += 2;
        if ($type !== 12 || $pos + $rdlen > strlen($buf)) {
            return null;
        }
        return self::readDnsName($buf, $pos);
    }

    private static function skipDnsName(string $buf, int $pos): int
    {
        while ($pos < strlen($buf)) {
            $len = ord($buf[$pos]);
            if ($len === 0) {
                return $pos + 1;
            }
            if (($len & 0xC0) === 0xC0) {
                return $pos + 2;
            }
            $pos += $len + 1;
        }
        return $pos;
    }

    private static function readDnsName(string $buf, int $pos, int $depth = 0): ?string
    {
        if ($depth > 5) {
            return null;
        }
        $labels = [];
        while ($pos < strlen($buf)) {
            $len = ord($buf[$pos]);
            if ($len === 0) {
                break;
            }
            if (($len & 0xC0) === 0xC0) {
                $ptr = ((($len & 0x3F) << 8) | ord($buf[$pos + 1]));
                $tail = self::readDnsName($buf, $ptr, $depth + 1);
                if ($tail !== null) {
                    $labels[] = $tail;
                }
                break;
            }
            $labels[] = substr($buf, $pos + 1, $len);
            $pos += $len + 1;
        }
        return $labels ? implode('.', $labels) : null;
    }


    /**
     * SNMP credential for a subnet: explicit binding first, then the profile
     * flagged as default. Returns null when SNMP is not configured at all.
     */
    public static function credentialFor(array $subnet): ?array
    {
        if (!empty($subnet['snmp_credential_id'])) {
            $c = Database::one('SELECT * FROM snmp_credentials WHERE id = ?', [(int)$subnet['snmp_credential_id']]);
            if ($c) {
                return $c;
            }
        }
        return Database::one('SELECT * FROM snmp_credentials WHERE is_default = 1 ORDER BY id LIMIT 1');
    }

    /**
     * Poll every responding host over SNMP (identity + neighbours).
     * @return array<string, array<string,mixed>> ip => info (with 'neighbours')
     */
    public static function pollSnmp(array $ips, array $cred): array
    {
        $out = [];
        foreach ($ips as $ip) {
            $info = Snmp::poll($ip, $cred);
            if ($info === null) {
                continue;
            }
            $info['neighbours'] = Snmp::neighbours($ip, $cred);
            $out[$ip] = $info;
        }
        return $out;
    }

    /**
     * Persist LLDP/CDP neighbour edges. Returns the number of edges seen.
     * @param array<string, array<string,mixed>> $snmp
     */
    public static function storeNeighbours(array $snmp): int
    {
        $count = 0;
        foreach ($snmp as $ip => $info) {
            foreach (($info['neighbours'] ?? []) as $n) {
                Database::exec(
                    'INSERT INTO topology_links
                        (protocol, local_ip, local_name, local_port, remote_name, remote_port, remote_ip, remote_descr)
                     VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        last_seen = NOW(), local_name = VALUES(local_name),
                        remote_ip = COALESCE(VALUES(remote_ip), remote_ip),
                        remote_descr = COALESCE(VALUES(remote_descr), remote_descr)',
                    [
                        $n['protocol'],
                        $ip,
                        $info['sysname'] ?? null,
                        $n['local_port'] ?? '',
                        $n['remote_name'] ?? '',
                        $n['remote_port'] ?? '',
                        $n['remote_ip'] ?? null,
                        $n['remote_descr'] ?? null,
                    ]
                );
                $count++;
            }
        }
        return $count;
    }

    /** Alert every active admin (role_id = 1). */
    private static function notifyAdmins(string $message): void
    {
        try {
            Database::exec(
                'INSERT INTO notifications (user_id, message)
                 SELECT id, ? FROM users WHERE role_id = 1 AND is_active = 1',
                [substr($message, 0, 255)]
            );
        } catch (PDOException) {
            // alerts must never break a scan
        }
    }

}
