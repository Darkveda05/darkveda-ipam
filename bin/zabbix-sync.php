#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DarkVeda IPAM — Zabbix monitoring sync (cron-friendly).
 *
 *   php bin/zabbix-sync.php
 *
 * Cron example (every 5 minutes):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/darkveda-ipam/bin/zabbix-sync.php
 */

require dirname(__DIR__) . '/src/App.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Zabbix.php';

use DarkVeda\Zabbix;

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

if (!Zabbix::configured()) {
    fwrite(STDERR, "Zabbix is not configured — set ZABBIX_URL and ZABBIX_TOKEN.\n");
    exit(1);
}

try {
    $r = Zabbix::sync();
    printf(
        "zabbix sync: %d hosts, %d addresses matched (%d online, %d offline)\n",
        $r['hosts'], $r['matched'], $r['online'], $r['offline']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'zabbix sync failed: ' . $e->getMessage() . "\n");
    exit(1);
}
