<?php

declare(strict_types=1);

/**
 * CLI: vol × accuracy dashboard JSON (same as api.php?action=vol_accuracy_report).
 *
 *   php scripts/vol_accuracy_report_cli.php [--market-id=120] [--candles=400] [--vol=ATR(14) pct] [--force]
 */

@ini_set('max_execution_time', '180');
@set_time_limit(180);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\TechnicalAnalysis;

$opts = getopt('', ['market-id::', 'candles::', 'vol::', 'force']);
$marketId = isset($opts['market-id']) ? (int) $opts['market-id'] : 120;
$candleCount = isset($opts['candles']) ? (int) $opts['candles'] : 400;
if (!in_array($candleCount, [200, 400, 600], true)) {
    $candleCount = 400;
}
$volMetric = isset($opts['vol']) ? (string) $opts['vol'] : 'ATR(14) pct';
if (!in_array($volMetric, TechnicalAnalysis::VOLATILITY_METHODS, true)) {
    $volMetric = 'ATR(14) pct';
}
$force = array_key_exists('force', $opts);

$cacheDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}
$cacheKey = sprintf('vol_acc_%d_%d_%s.json', $marketId, $candleCount, md5($volMetric));
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey;
$cacheTtl = 20 * 60;

if (!$force && is_file($cachePath) && (time() - (int) filemtime($cachePath)) < $cacheTtl) {
    $cached = json_decode((string) file_get_contents($cachePath), true);
    if (is_array($cached) && !empty($cached['ok'])) {
        $cached['cached'] = true;
        $cached['cache_age_sec'] = time() - (int) filemtime($cachePath);
        echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
}

try {
    $client = Client::mainnet(60);
    $resolutions = ['1d', '12h', '4h', '1h', '30m', '15m'];
    $frames = [];
    $loaded = [];
    $requests = [];
    foreach ($resolutions as $resolution) {
        foreach ($client->candlesHistoryRequests($marketId, $resolution, $candleCount) as $pageKey => $req) {
            $requests[$resolution . '__' . $pageKey] = $req;
        }
    }
    $responses = $client->getMany($requests);
    foreach ($resolutions as $resolution) {
        $pages = [];
        foreach ($responses as $key => $payload) {
            if (str_starts_with((string) $key, $resolution . '__')) {
                $pages[] = $payload;
            }
        }
        $items = Client::mergeCandlePages($pages, $candleCount);
        $frames[$resolution] = $items;
        $loaded[$resolution] = count($items);
    }
    $full = TechnicalAnalysis::runVolAccuracyReport($frames, 1, $volMetric);
    $top = [];
    foreach (array_slice($full['results'] ?? [], 0, 15) as $row) {
        $top[] = [
            'method' => $row['method'],
            'resolution' => $row['resolution'],
            'accuracy' => $row['accuracy'],
            'signals' => $row['signals'],
            'corr_vol_accuracy' => $row['corr_vol_accuracy'],
            'accuracy_vol_low' => $row['accuracy_vol_low'],
            'accuracy_vol_high' => $row['accuracy_vol_high'],
            'profit_factor' => $row['profit_factor'],
            'score' => $row['score'],
        ];
    }
    $byMethodSlim = [];
    foreach (($full['by_method'] ?? []) as $method => $rows) {
        $byMethodSlim[$method] = [];
        foreach ($rows as $row) {
            $byMethodSlim[$method][] = [
                'resolution' => $row['resolution'],
                'accuracy' => $row['accuracy'],
                'signals' => $row['signals'],
                'corr_vol_accuracy' => $row['corr_vol_accuracy'],
                'accuracy_vol_low' => $row['accuracy_vol_low'],
                'accuracy_vol_high' => $row['accuracy_vol_high'],
                'score' => $row['score'],
            ];
        }
    }
    $out = [
        'ok' => true,
        'dashboard' => 'vol-accuracy-report.php',
        'market_id' => $marketId,
        'candles' => $candleCount,
        'vol_metric' => $volMetric,
        'loaded' => $loaded,
        'volatility' => $full['volatility'] ?? [],
        'top_by_abs_corr' => $top,
        'by_method' => $byMethodSlim,
        'cached' => false,
        'ts' => time(),
    ];
    @file_put_contents($cachePath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
