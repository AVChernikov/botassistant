<?php

declare(strict_types=1);

/**
 * Full cron pipeline:
 * 1) snapshot from Lighter → SQLite
 * 2) compact → DeepSeek agent
 * 3) save AI report to DB
 *
 * Usage: php scripts/indicators_pipeline_cli.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\IndicatorAnalyzer;
use Lighter\IndicatorConfig;
use Lighter\IndicatorHistoryStore;
use Lighter\IndicatorSnapshot;

$cfg = IndicatorConfig::load();
$network = $cfg->network();
$client = $network === 'testnet' ? Client::testnet(45) : Client::mainnet(45);

$marketIds = $cfg->marketIds();
$resolutions = $cfg->resolutions();
$candleCount = $cfg->candleCount();
$enabled = $cfg->indicators();
$dbPath = $cfg->dbPath();

$tSnap = microtime(true);
$allRows = [];
$allStats = [];
$replaceScopes = [];
$frames = [];

foreach ($marketIds as $marketId) {
    $requests = [
        'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
    ];
    foreach ($resolutions as $resolution) {
        $requests['c_' . $resolution] = $client->candlesRequest($marketId, $resolution, countBack: $candleCount);
    }
    $bundle = $client->getMany($requests);
    $market = ($bundle['details']['order_book_details'] ?? [])[0] ?? null;
    $symbol = (string) ($market['symbol'] ?? ('ID:' . $marketId));

    foreach ($resolutions as $resolution) {
        $candles = $bundle['c_' . $resolution]['c'] ?? [];
        if (!is_array($candles) || count($candles) < 40) {
            $frames[] = [
                'market_id' => $marketId,
                'symbol' => $symbol,
                'resolution' => $resolution,
                'skipped' => true,
                'candles' => is_array($candles) ? count($candles) : 0,
            ];
            continue;
        }
        $replaceScopes[] = ['market_id' => $marketId, 'resolution' => $resolution];
        $snap = IndicatorSnapshot::compute($candles, $enabled);
        foreach ($snap as $row) {
            $allRows[] = [
                'market_id' => $marketId,
                'symbol' => $symbol,
                'resolution' => $resolution,
                'bar_ts' => $row['bar_ts'],
                'indicator' => $row['indicator'],
                'value' => $row['value'],
                'signal' => $row['signal'],
                'close' => $row['close'],
            ];
        }
        $stats = IndicatorSnapshot::computeStats($candles, $enabled);
        foreach ($stats as $st) {
            $allStats[] = array_merge($st, [
                'market_id' => $marketId,
                'symbol' => $symbol,
                'resolution' => $resolution,
            ]);
        }
        $frames[] = [
            'market_id' => $marketId,
            'symbol' => $symbol,
            'resolution' => $resolution,
            'candles' => count($candles),
            'rows' => count($snap),
            'stats' => count($stats),
            'skipped' => false,
        ];
    }
}

$save = IndicatorHistoryStore::saveRows($dbPath, $allRows, $replaceScopes, $allStats);
$snapSec = round(microtime(true) - $tSnap, 2);

if (empty($save['ok'])) {
    echo json_encode([
        'ok' => false,
        'step' => 'snapshot',
        'saved' => $save,
        'frames' => $frames,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$reports = [];
foreach ($marketIds as $marketId) {
    $reports[] = IndicatorAnalyzer::run($dbPath, $marketId, 40, true);
}

echo json_encode([
    'ok' => true,
    'pipeline' => 'snapshot → deepseek → report',
    'snapshot' => [
        'sec' => $snapSec,
        'rows' => count($allRows),
        'stats_rows' => count($allStats),
        'saved' => $save,
        'frames' => $frames,
    ],
    'reports' => $reports,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$okReports = true;
foreach ($reports as $r) {
    if (empty($r['ok']) || empty($r['saved']['ok'])) {
        $okReports = false;
    }
}
exit($okReports ? 0 : 1);
