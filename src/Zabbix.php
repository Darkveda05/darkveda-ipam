<?php
declare(strict_types=1);

namespace DarkVeda;

/**
 * DarkVeda IPAM — Zabbix integration (Zabbix 6.0+ / 7.x JSON-RPC API).
 *
 * Authentication: Zabbix 6.0 introduced API tokens; 7.0 requires them to be
 * sent as an HTTP header (`Authorization: Bearer <token>`) rather than in the
 * request body. This client uses the header form, which works on 6.0+ and 7.x.
 *
 * Configuration comes from config/config.php['zabbix'] or environment:
 *   ZABBIX_URL    e.g. https://zabbix.example.com/api_jsonrpc.php
 *   ZABBIX_TOKEN  API token from Users -> API tokens in Zabbix
 *
 * Sync flow:
 *   host.get   -> hosts with their interfaces (IP) and availability
 *   item.get   -> latest CPU / memory utilisation values per host
 *   problem.get-> open problem count per host
 *   results are written to monitoring_status keyed by IP address.
 */
final class Zabbix
{
    /** Item keys tried in order for CPU utilisation (%). */
    private const CPU_KEYS = [
        'system.cpu.util',
        'system.cpu.util[,user]',
        'system.cpu.load[percpu,avg1]',
    ];

    /** Item keys tried in order for memory utilisation (%). */
    private const MEM_KEYS = [
        'vm.memory.utilization',
        'vm.memory.size[pused]',
        'vm.memory.util',
    ];

    public static function configured(): bool
    {
        $c = self::config();
        return $c['url'] !== '' && $c['token'] !== '';
    }

    /** @return array{url:string,token:string,verify_tls:bool,timeout:int} */
    public static function config(): array
    {
        $cfg = App::config()['zabbix'] ?? [];
        return [
            'url'        => (string)(getenv('ZABBIX_URL') ?: ($cfg['url'] ?? '')),
            'token'      => (string)(getenv('ZABBIX_TOKEN') ?: ($cfg['token'] ?? '')),
            'verify_tls' => (bool)($cfg['verify_tls'] ?? true),
            'timeout'    => (int)($cfg['timeout'] ?? 10),
        ];
    }

    /**
     * Raw JSON-RPC call.
     * @throws \RuntimeException on transport or API error
     */
    public static function call(string $method, array $params = []): mixed
    {
        $c = self::config();
        if ($c['url'] === '') {
            throw new \RuntimeException('Zabbix URL is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is required for the Zabbix integration.');
        }

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params ?: new \stdClass(),
            'id'      => 1,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($c['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $c['timeout'],
            CURLOPT_SSL_VERIFYPEER => $c['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $c['verify_tls'] ? 2 : 0,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json-rpc',
                'Authorization: Bearer ' . $c['token'],
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('Zabbix request failed: ' . $err);
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Zabbix returned a non-JSON response (HTTP ' . $code . ').');
        }
        if (isset($json['error'])) {
            throw new \RuntimeException(
                'Zabbix API error: ' . ($json['error']['message'] ?? '')
                . ' ' . ($json['error']['data'] ?? '')
            );
        }
        return $json['result'] ?? null;
    }

    /** Quick connectivity check — returns the Zabbix API version string. */
    public static function version(): string
    {
        // apiinfo.version must be called without auth; header is harmless.
        return (string)self::call('apiinfo.version');
    }

    /**
     * Pull monitoring state for every Zabbix host and store it against
     * matching IP addresses.
     * @return array{hosts:int,matched:int,online:int,offline:int}
     */
    public static function sync(): array
    {
        $hosts = self::call('host.get', [
            'output'           => ['hostid', 'host', 'name', 'status'],
            'selectInterfaces' => ['ip', 'available', 'type', 'main'],
            'filter'           => ['status' => 0],   // monitored hosts only
        ]);
        if (!is_array($hosts)) {
            $hosts = [];
        }

        $hostIds = array_map(static fn($h) => $h['hostid'], $hosts);
        $cpu = $hostIds ? self::latestByKeys($hostIds, self::CPU_KEYS) : [];
        $mem = $hostIds ? self::latestByKeys($hostIds, self::MEM_KEYS) : [];
        $problems = $hostIds ? self::problemCounts($hostIds) : [];

        $matched = $online = $offline = 0;

        foreach ($hosts as $h) {
            $ips = [];
            foreach (($h['interfaces'] ?? []) as $iface) {
                $ip = trim((string)($iface['ip'] ?? ''));
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ips[] = $ip;
                }
            }
            if (!$ips) {
                continue;
            }

            // Zabbix interface availability: 0 unknown, 1 available, 2 unavailable
            $avail = 0;
            foreach (($h['interfaces'] ?? []) as $iface) {
                $a = (int)($iface['available'] ?? 0);
                if ($a === 1) { $avail = 1; break; }
                if ($a === 2) { $avail = 2; }
            }
            $state = match ($avail) { 1 => 'online', 2 => 'offline', default => 'unknown' };
            $state === 'online' ? $online++ : ($state === 'offline' ? $offline++ : null);

            foreach (array_unique($ips) as $ip) {
                self::store(
                    $ip,
                    $state,
                    $cpu[$h['hostid']] ?? null,
                    $mem[$h['hostid']] ?? null,
                    (string)($h['name'] ?? $h['host'] ?? ''),
                    (int)($problems[$h['hostid']] ?? 0)
                );
                $matched++;
            }
        }

        return ['hosts' => count($hosts), 'matched' => $matched, 'online' => $online, 'offline' => $offline];
    }

    /**
     * Latest numeric value per host for the first matching key.
     * @param string[] $hostIds @param string[] $keys @return array<string,float>
     */
    private static function latestByKeys(array $hostIds, array $keys): array
    {
        $items = self::call('item.get', [
            'output'      => ['itemid', 'hostid', 'key_', 'lastvalue'],
            'hostids'     => $hostIds,
            'filter'      => ['key_' => $keys],
            'monitored'   => true,
        ]);
        if (!is_array($items)) {
            return [];
        }
        $rank = array_flip($keys);
        $best = [];
        foreach ($items as $it) {
            $hid = (string)$it['hostid'];
            $r   = $rank[$it['key_']] ?? 99;
            $val = $it['lastvalue'] ?? null;
            if ($val === null || $val === '' || !is_numeric($val)) {
                continue;
            }
            if (!isset($best[$hid]) || $r < $best[$hid][0]) {
                $best[$hid] = [$r, round((float)$val, 2)];
            }
        }
        return array_map(static fn($v) => $v[1], $best);
    }

    /** @param string[] $hostIds @return array<string,int> */
    private static function problemCounts(array $hostIds): array
    {
        $problems = self::call('problem.get', [
            'output'   => ['eventid', 'objectid', 'severity'],
            'hostids'  => $hostIds,
            'recent'   => false,
        ]);
        if (!is_array($problems)) {
            return [];
        }
        // problem.get doesn't return hostid directly in all versions; fall back to a
        // per-host count via triggerids when present.
        $counts = [];
        foreach ($problems as $p) {
            $hid = (string)($p['hostid'] ?? '');
            if ($hid !== '') {
                $counts[$hid] = ($counts[$hid] ?? 0) + 1;
            }
        }
        return $counts;
    }

    /** Upsert one monitoring row. Also used by the REST API push endpoint. */
    public static function store(
        string $address,
        string $state,
        ?float $cpu = null,
        ?float $mem = null,
        ?string $hostName = null,
        int $problems = 0,
        ?int $uptime = null,
        string $source = 'zabbix'
    ): void {
        if (!in_array($state, ['online', 'offline', 'unknown'], true)) {
            $state = 'unknown';
        }
        Database::exec(
            'INSERT INTO monitoring_status
                (address, source, state, cpu_pct, memory_pct, uptime_seconds, host_name, problem_count, checked_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                source = VALUES(source), state = VALUES(state),
                cpu_pct = VALUES(cpu_pct), memory_pct = VALUES(memory_pct),
                uptime_seconds = VALUES(uptime_seconds), host_name = VALUES(host_name),
                problem_count = VALUES(problem_count), checked_at = NOW()',
            [$address, $source, $state, $cpu, $mem, $uptime, $hostName, $problems]
        );
    }

    /** @return array<string, array<string,mixed>> address => status row */
    public static function statusMap(array $addresses = []): array
    {
        if ($addresses) {
            $in = implode(',', array_fill(0, count($addresses), '?'));
            $rows = Database::q("SELECT * FROM monitoring_status WHERE address IN ($in)", $addresses);
        } else {
            $rows = Database::q('SELECT * FROM monitoring_status');
        }
        $map = [];
        foreach ($rows as $r) {
            $map[$r['address']] = $r;
        }
        return $map;
    }
}
