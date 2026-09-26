<?php

declare(strict_types=1);

/**
 * CLI one-shot: same work as api.php?action=indicators_tick (no HTTP).
 * Full snapshot per TF (all candles × indicators) + stats (return / profit factor).
 * Usage: php scripts/indicators_tick_cli.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
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
                'candles' => is_array($candles) ? count($candles) : 0,
                'skipped' => true,
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
            $allStats[] = [
                'market_id' => $marketId,
                'symbol' => $symbol,
                'resolution' => $resolution,
                'indicator' => $st['indicator'],
                'bar_ts' => $st['bar_ts'],
                'strategy_return_pct' => $st['strategy_return_pct'],
                'profit_factor' => $st['profit_factor'],
                'accuracy' => $st['accuracy'],
                'signals' => $st['signals'],
                'wins' => $st['wins'],
                'losses' => $st['losses'],
                'last_signal' => $st['last_signal'],
                'last_value' => $st['last_value'],
            ];
        }
        $frames[] = [
            'market_id' => $marketId,
            'symbol' => $symbol,
            'resolution' => $resolution,
            'candles' => count($candles),
            'indicator_names' => count($enabled),
            'rows' => count($snap),
            'stats' => count($stats),
            'skipped' => false,
        ];
    }
}

$save = IndicatorHistoryStore::saveRows($dbPath, $allRows, $replaceScopes, $allStats);
echo json_encode([
    'ok' => (bool) $save['ok'],
    'network' => $network,
    'mode' => 'replace_snapshot_all_candles+stats',
    'indicators' => $enabled,
    'frames' => $frames,
    'rows' => count($allRows),
    'stats_rows' => count($allStats),
    'saved' => $save,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit(!empty($save['ok']) ? 0 : 1);
