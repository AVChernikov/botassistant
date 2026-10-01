<?php

declare(strict_types=1);

/**
 * Full cron pipeline:
 * 1) append/upsert indicator bars from Lighter → MySQL
 * 2) compact → DeepSeek agent
 * 3) save AI report to DB
 *
 * Usage: php scripts/indicators_pipeline_cli.php [--replace]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\IndicatorAnalyzer;
use Lighter\IndicatorConfig;
use Lighter\IndicatorTick;

$cfg = IndicatorConfig::load();
$network = $cfg->network();
$client = $network === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
$mode = in_array('--replace', $argv, true) ? 'replace' : 'append';

$tSnap = microtime(true);
$snap = IndicatorTick::run($client, $cfg, null, $mode);
$snapSec = round(microtime(true) - $tSnap, 2);

if (empty($snap['ok'])) {
    echo json_encode([
        'ok' => false,
        'step' => 'snapshot',
        'snapshot' => $snap,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$marketIds = $cfg->marketIds();
$dbPath = $cfg->dbPath();
$reports = [];
foreach ($marketIds as $marketId) {
    $reports[] = IndicatorAnalyzer::run($dbPath, $marketId, 40, true);
}

echo json_encode([
    'ok' => true,
    'pipeline' => 'append → deepseek → report',
    'snapshot' => array_merge($snap, ['sec' => $snapSec]),
    'reports' => $reports,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$okReports = true;
foreach ($reports as $r) {
    if (empty($r['ok']) || empty($r['saved']['ok'])) {
        $okReports = false;
    }
}
exit($okReports ? 0 : 1);
