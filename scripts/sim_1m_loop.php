<?php

declare(strict_types=1);

/**
 * Background loop for sim 1m emulation (no browser tab required).
 * Usage: php scripts/sim_1m_loop.php [--session-id=N]
 * Stops when session status != running.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\IndicatorConfig;
use Lighter\Sim1mEngine;
use Lighter\Sim1mStore;

$sessionId = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--session-id=')) {
        $sessionId = (int) substr($a, strlen('--session-id='));
    }
}

$cfg = IndicatorConfig::load();
$client = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
$pidFile = dirname(__DIR__) . '/mcp-lighter/_sim_1m_loop.pid';
file_put_contents($pidFile, (string) getmypid());

fwrite(STDOUT, "sim_1m_loop start pid=" . getmypid() . " session=" . ($sessionId ?? 'auto') . PHP_EOL);

while (true) {
    $wrap = Sim1mStore::session($cfg->dbPath(), $sessionId);
    $sess = $wrap['session'] ?? null;
    if (!is_array($sess) || ($sess['status'] ?? '') !== 'running') {
        fwrite(STDOUT, "no running session — exit\n");
        break;
    }
    $sid = (int) $sess['id'];
    $sessionId = $sid;
    $tickSec = max(10, (int) ($sess['tick_sec'] ?? 30));

    try {
        $r = Sim1mEngine::tick($client, $cfg, $sid);
        $msg = ($r['skipped'] ?? false)
            ? ('skip ' . ($r['reason'] ?? ''))
            : ('tick action=' . ($r['tick']['action'] ?? '?') . ' method=' . ($r['tick']['method'] ?? '?'));
        fwrite(STDOUT, date('H:i:s') . ' ' . $msg . PHP_EOL);
    } catch (Throwable $e) {
        fwrite(STDERR, date('H:i:s') . ' ERROR ' . $e->getMessage() . PHP_EOL);
    }

    sleep($tickSec);
}

@unlink($pidFile);
