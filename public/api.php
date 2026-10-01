<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

@ini_set('max_execution_time', '120');
@set_time_limit(120);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\PythonBin;
use Lighter\DeepSeekClient;
use Lighter\DeepSeekQueryStore;
use Lighter\Exception\ApiException;
use Lighter\IndicatorAnalyzer;
use Lighter\IndicatorCompact;
use Lighter\IndicatorConfig;
use Lighter\IndicatorHistoryStore;
use Lighter\IndicatorReportStore;
use Lighter\IndicatorTick;
use Lighter\Sim1mEngine;
use Lighter\Live1mEngine;
use Lighter\TechnicalAnalysis;

function jsonOut(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['action'] ?? '';
$network = ($_GET['network'] ?? 'mainnet') === 'testnet' ? 'testnet' : 'mainnet';

try {
    $client = $network === 'testnet' ? Client::testnet(45) : Client::mainnet(45);

    switch ($action) {
        case 'markets':
            $filter = $_GET['filter'] ?? 'perp';
            if (!in_array($filter, ['all', 'spot', 'perp'], true)) {
                $filter = 'perp';
            }
            $data = $client->orderBooks(filter: $filter);
            $markets = array_values(array_filter(
                $data['order_books'] ?? [],
                static fn (array $m): bool => ($m['status'] ?? '') === 'active',
            ));
            usort(
                $markets,
                static fn (array $a, array $b): int => strcmp((string) $a['symbol'], (string) $b['symbol']),
            );
            jsonOut([
                'ok' => true,
                'network' => $network,
                'base_url' => $client->getBaseUrl(),
                'markets' => $markets,
            ]);

        case 'market':
            $marketId = filter_var($_GET['market_id'] ?? null, FILTER_VALIDATE_INT);
            if ($marketId === false) {
                jsonOut(['ok' => false, 'error' => 'market_id обязателен'], 400);
            }
            $depth = filter_var($_GET['depth'] ?? 15, FILTER_VALIDATE_INT);
            $tradesLimit = filter_var($_GET['trades'] ?? 20, FILTER_VALIDATE_INT);
            $candleCount = filter_var($_GET['candle_count'] ?? 48, FILTER_VALIDATE_INT);
            $depth = $depth === false ? 15 : max(1, min(100, $depth));
            $tradesLimit = $tradesLimit === false ? 20 : max(1, min(100, $tradesLimit));
            $candleCount = $candleCount === false ? 48 : max(1, min(500, $candleCount));
            $resolution = $_GET['resolution'] ?? '1h';
            if (!in_array($resolution, ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'], true)) {
                $resolution = '1h';
            }

            $bundle = $client->getMany([
                'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
                'order_book' => ['/api/v1/orderBookOrders', ['market_id' => $marketId, 'limit' => $depth]],
                'trades' => ['/api/v1/recentTrades', ['market_id' => $marketId, 'limit' => $tradesLimit]],
                'candles' => $client->candlesRequest($marketId, $resolution, countBack: $candleCount),
                'stats' => ['/api/v1/exchangeStats', []],
            ]);

            $market = ($bundle['details']['order_book_details'] ?? [])[0] ?? null;
            $candles = $bundle['candles'];
            $stats = $bundle['stats'];

            $symbol = $market['symbol'] ?? null;
            $marketStats = null;
            if ($symbol !== null) {
                foreach ($stats['order_book_stats'] ?? [] as $row) {
                    if (($row['symbol'] ?? null) === $symbol) {
                        $marketStats = $row;
                        break;
                    }
                }
            }

            jsonOut([
                'ok' => true,
                'network' => $network,
                'market' => $market,
                'details' => $bundle['details'],
                'order_book' => $bundle['order_book'],
                'trades' => $bundle['trades']['trades'] ?? [],
                'candles' => [
                    'resolution' => $candles['r'] ?? $resolution,
                    'items' => $candles['c'] ?? [],
                ],
                'market_stats' => $marketStats,
                'exchange' => [
                    'daily_usd_volume' => $stats['daily_usd_volume'] ?? null,
                    'daily_trades_count' => $stats['daily_trades_count'] ?? null,
                ],
            ]);

        case 'btc_analyze':
        case 'market_analyze':
            $marketId = filter_var($_GET['market_id'] ?? 1, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 1 : $marketId;
            $candleCount = filter_var($_GET['candle_count'] ?? 200, FILTER_VALIDATE_INT);
            $candleCount = $candleCount === false ? 200 : max(50, min(500, $candleCount));
            $depth = filter_var($_GET['depth'] ?? 15, FILTER_VALIDATE_INT);
            $tradesLimit = filter_var($_GET['trades'] ?? 25, FILTER_VALIDATE_INT);
            $depth = $depth === false ? 15 : max(1, min(100, $depth));
            $tradesLimit = $tradesLimit === false ? 25 : max(1, min(100, $tradesLimit));

            $resolutions = ['1d', '4h', '1h', '30m', '15m', '5m', '1m'];
            $requests = [
                'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
                'order_book' => ['/api/v1/orderBookOrders', ['market_id' => $marketId, 'limit' => $depth]],
                'trades' => ['/api/v1/recentTrades', ['market_id' => $marketId, 'limit' => $tradesLimit]],
                'stats' => ['/api/v1/exchangeStats', []],
            ];
            foreach ($resolutions as $resolution) {
                $requests['c_' . $resolution] = $client->candlesRequest($marketId, $resolution, countBack: $candleCount);
            }
            $bundle = $client->getMany($requests);

            $market = ($bundle['details']['order_book_details'] ?? [])[0] ?? null;
            $stats = $bundle['stats'];
            $frames = [];
            foreach ($resolutions as $resolution) {
                $candles = $bundle['c_' . $resolution];
                $frames[] = [
                    'resolution' => $candles['r'] ?? $resolution,
                    'items' => $candles['c'] ?? [],
                ];
            }

            $symbol = $market['symbol'] ?? 'BTC';
            $marketStats = null;
            foreach ($stats['order_book_stats'] ?? [] as $row) {
                if (($row['symbol'] ?? null) === $symbol) {
                    $marketStats = $row;
                    break;
                }
            }

            jsonOut([
                'ok' => true,
                'network' => 'mainnet',
                'market' => $market,
                'details' => $bundle['details'],
                'order_book' => $bundle['order_book'],
                'trades' => $bundle['trades']['trades'] ?? [],
                'frames' => $frames,
                'market_stats' => $marketStats,
                'exchange' => [
                    'daily_usd_volume' => $stats['daily_usd_volume'] ?? null,
                    'daily_trades_count' => $stats['daily_trades_count'] ?? null,
                ],
            ]);

        case 'live':
            $marketId = filter_var($_GET['market_id'] ?? 1, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 1 : $marketId;
            $method = (string) ($_GET['method'] ?? '');
            $resolution = (string) ($_GET['resolution'] ?? '1h');
            if (!in_array($method, TechnicalAnalysis::METHODS, true)) {
                jsonOut(['ok' => false, 'error' => 'Неизвестный метод индикатора'], 400);
            }
            if (!in_array($resolution, ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'], true)) {
                jsonOut(['ok' => false, 'error' => 'Неверный timeframe'], 400);
            }
            $candleCount = filter_var($_GET['candle_count'] ?? 200, FILTER_VALIDATE_INT);
            $candleCount = $candleCount === false ? 200 : max(50, min(500, $candleCount));
            $depth = filter_var($_GET['depth'] ?? 15, FILTER_VALIDATE_INT);
            $tradesLimit = filter_var($_GET['trades'] ?? 25, FILTER_VALIDATE_INT);
            $depth = $depth === false ? 15 : max(1, min(100, $depth));
            $tradesLimit = $tradesLimit === false ? 25 : max(1, min(100, $tradesLimit));

            $bundle = $client->getMany([
                'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
                'order_book' => ['/api/v1/orderBookOrders', ['market_id' => $marketId, 'limit' => $depth]],
                'trades' => ['/api/v1/recentTrades', ['market_id' => $marketId, 'limit' => $tradesLimit]],
                'candles' => $client->candlesRequest($marketId, $resolution, countBack: $candleCount),
                'stats' => ['/api/v1/exchangeStats', []],
            ]);

            $market = ($bundle['details']['order_book_details'] ?? [])[0] ?? null;
            $candlesResp = $bundle['candles'];
            $candles = $candlesResp['c'] ?? [];
            $stats = $bundle['stats'];
            $symbol = $market['symbol'] ?? null;
            $marketStats = null;
            if ($symbol !== null) {
                foreach ($stats['order_book_stats'] ?? [] as $row) {
                    if (($row['symbol'] ?? null) === $symbol) {
                        $marketStats = $row;
                        break;
                    }
                }
            }

            jsonOut([
                'ok' => true,
                'network' => $network,
                'method' => $method,
                'resolution' => $candlesResp['r'] ?? $resolution,
                'market' => $market,
                'order_book' => $bundle['order_book'],
                'trades' => $bundle['trades']['trades'] ?? [],
                'candles' => $candles,
                'indicator' => TechnicalAnalysis::indicatorChartData($candles, $method),
                'market_stats' => $marketStats,
                'updated_at' => (int) floor(microtime(true) * 1000),
            ]);

        case 'indicators_tick':
            $cfg = IndicatorConfig::load();
            $secret = (string) ($_GET['secret'] ?? $_POST['secret'] ?? '');
            $expected = $cfg->cronSecret();
            if ($expected !== '' && !hash_equals($expected, $secret)) {
                jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
            }

            $marketIds = $cfg->marketIds();
            $qMarket = filter_var($_GET['market_id'] ?? null, FILTER_VALIDATE_INT);
            if ($qMarket !== false && $qMarket !== null) {
                $marketIds = [(int) $qMarket];
            }
            $mode = strtolower((string) ($_GET['mode'] ?? $_POST['mode'] ?? 'append'));
            if (!in_array($mode, ['append', 'replace'], true)) {
                $mode = 'append';
            }

            $tickClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $result = IndicatorTick::run($tickClient, $cfg, $marketIds, $mode);
            $result['updated_at'] = (int) floor(microtime(true) * 1000);
            jsonOut($result, !empty($result['ok']) ? 200 : 500);

        case 'indicators_history':
            $cfg = IndicatorConfig::load();
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
            $limit = $limit === false ? 50 : max(1, min(500, $limit));
            $marketId = filter_var($_GET['market_id'] ?? null, FILTER_VALIDATE_INT);
            $resolution = isset($_GET['resolution']) ? (string) $_GET['resolution'] : null;
            $rows = IndicatorHistoryStore::recent(
                $cfg->dbPath(),
                $limit,
                $marketId === false ? null : $marketId,
                $resolution,
            );
            jsonOut([
                'ok' => true,
                'db_path' => $cfg->dbPath(),
                'indicators' => $cfg->indicators(),
                'rows' => $rows,
            ]);

        case 'indicators_config':
            $cfg = IndicatorConfig::load();
            jsonOut([
                'ok' => true,
                'market_ids' => $cfg->marketIds(),
                'resolutions' => $cfg->resolutions(),
                'candle_count' => $cfg->candleCount(),
                'indicators' => $cfg->indicators(),
                'db_path' => $cfg->dbPath(),
                'endpoint_url' => $cfg->endpointUrl(),
                'cron_interval_sec' => $cfg->cronIntervalSec(),
            ]);

        case 'indicators_compact':
            // Compressed snapshot for LLM / DeepSeek agent
            $cfg = IndicatorConfig::load();
            $marketId = filter_var($_GET['market_id'] ?? 120, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 120 : $marketId;
            $events = filter_var($_GET['events'] ?? 40, FILTER_VALIDATE_INT);
            $events = $events === false ? 40 : max(5, min(200, $events));
            $candles = filter_var($_GET['candles'] ?? 24, FILTER_VALIDATE_INT);
            $candles = $candles === false ? 24 : max(5, min(120, $candles));
            $compact = IndicatorCompact::build($cfg->dbPath(), $marketId, $events, $candles);
            if (!empty($_GET['gzip'])) {
                $json = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                jsonOut([
                    'ok' => true,
                    'encoding' => 'gzip+base64',
                    'bytes_raw' => strlen($json ?: ''),
                    'payload_b64' => base64_encode(gzencode($json ?: '{}', 6) ?: ''),
                ]);
            }
            jsonOut($compact);

        case 'indicators_analyze':
            // DeepSeek: find patterns from compact snapshot (save defaults to 1)
            $cfg = IndicatorConfig::load();
            $secret = (string) ($_GET['secret'] ?? $_POST['secret'] ?? '');
            $expected = $cfg->cronSecret();
            if ($expected !== '' && !hash_equals($expected, $secret)) {
                jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
            }
            $marketId = filter_var($_GET['market_id'] ?? $_POST['market_id'] ?? 120, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 120 : $marketId;
            $events = filter_var($_GET['events'] ?? 40, FILTER_VALIDATE_INT);
            $events = $events === false ? 40 : max(5, min(200, $events));
            $save = (string) ($_GET['save'] ?? $_POST['save'] ?? '1') !== '0';
            jsonOut(IndicatorAnalyzer::run($cfg->dbPath(), $marketId, $events, $save));

        case 'indicators_pipeline':
            // Full cron: snapshot → DeepSeek → report (runs CLI subprocess)
            @set_time_limit(300);
            $cfg = IndicatorConfig::load();
            $secret = (string) ($_GET['secret'] ?? $_POST['secret'] ?? '');
            $expected = $cfg->cronSecret();
            if ($expected !== '' && !hash_equals($expected, $secret)) {
                jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
            }
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'indicators_pipeline_cli.php';
            $out = [];
            $code = 0;
            exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
            $text = trim(implode("\n", $out));
            $start = strpos($text, '{');
            $decoded = $start === false ? null : json_decode(substr($text, $start), true);
            if (!is_array($decoded)) {
                jsonOut(['ok' => false, 'error' => 'pipeline failed', 'raw' => substr($text, 0, 500)], 500);
            }
            jsonOut($decoded, !empty($decoded['ok']) ? 200 : 500);

        case 'indicators_report':
            // Separate endpoint: save or read AI reports
            $cfg = IndicatorConfig::load();
            $secret = (string) ($_GET['secret'] ?? $_POST['secret'] ?? '');
            $expected = $cfg->cronSecret();
            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

            if ($method === 'POST') {
                if ($expected !== '' && !hash_equals($expected, $secret)) {
                    jsonOut(['ok' => false, 'error' => 'forbidden'], 403);
                }
                $raw = file_get_contents('php://input') ?: '';
                $body = json_decode($raw, true);
                if (!is_array($body)) {
                    $body = $_POST;
                }
                // clarify follow-up → deepseek_queries purpose=clarify
                if (($body['op'] ?? '') === 'clarify' || isset($body['question'])) {
                    $reportId = (int) ($body['report_id'] ?? 0);
                    $question = trim((string) ($body['question'] ?? ''));
                    if ($reportId <= 0 || $question === '') {
                        jsonOut(['ok' => false, 'error' => 'report_id and question required'], 400);
                    }
                    $marketId = isset($body['market_id']) ? (int) $body['market_id'] : 120;
                    $parent = isset($body['parent_query_id']) ? (int) $body['parent_query_id'] : null;
                    jsonOut(IndicatorAnalyzer::clarify(
                        $cfg->dbPath(),
                        $question,
                        $reportId,
                        $parent,
                        $marketId,
                    ));
                }
                if ($body === []) {
                    jsonOut(['ok' => false, 'error' => 'JSON body required'], 400);
                }
                $stored = IndicatorReportStore::save($cfg->dbPath(), [
                    'market_id' => isset($body['market_id']) ? (int) $body['market_id'] : 120,
                    'symbol' => (string) ($body['symbol'] ?? 'LIT'),
                    'model' => (string) ($body['model'] ?? 'external'),
                    'title' => (string) ($body['title'] ?? 'report'),
                    'summary' => (string) ($body['summary'] ?? ''),
                    'findings' => $body['findings'] ?? $body['report'] ?? $body,
                    'compact_bytes' => isset($body['compact_bytes']) ? (int) $body['compact_bytes'] : null,
                    'raw_response' => isset($body['raw_response']) ? (string) $body['raw_response'] : null,
                ]);
                jsonOut($stored, !empty($stored['ok']) ? 200 : 500);
            }

            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id !== false && $id !== null) {
                jsonOut(IndicatorReportStore::get($cfg->dbPath(), (int) $id));
            }
            $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT);
            $limit = $limit === false ? 20 : max(1, min(100, $limit));
            $marketId = filter_var($_GET['market_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(IndicatorReportStore::list(
                $cfg->dbPath(),
                $limit,
                $marketId === false ? null : $marketId,
            ));

        case 'deepseek_queries':
            // History of DeepSeek requests (analyze / clarify)
            $cfg = IndicatorConfig::load();
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id !== false && $id !== null) {
                jsonOut(DeepSeekQueryStore::get($cfg->dbPath(), (int) $id));
            }
            $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
            $limit = $limit === false ? 50 : max(1, min(200, $limit));
            $purpose = isset($_GET['purpose']) ? (string) $_GET['purpose'] : null;
            jsonOut(DeepSeekQueryStore::list($cfg->dbPath(), $limit, $purpose));

        case 'trading_status':
            // Position + all indicators (latest bar) + pos/session PnL
            @set_time_limit(120);
            $cfg = IndicatorConfig::load();
            $marketId = filter_var($_GET['market_id'] ?? 120, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 120 : $marketId;
            $root = dirname(__DIR__);
            $py = PythonBin::path($root);
            $script = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . 'trading_status.py';
            if (!is_file($py)) {
                $py = 'python';
            }
            $cmd = escapeshellarg($py) . ' ' . escapeshellarg($script)
                . ' --market-id ' . (int) $marketId
                . ' --db ' . escapeshellarg($cfg->dbPath());
            $out = [];
            $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            $text = trim(implode("\n", $out));
            $start = strpos($text, '{');
            $decoded = $start === false ? null : json_decode(substr($text, $start), true);
            if (!is_array($decoded)) {
                jsonOut(['ok' => false, 'error' => 'trading_status failed', 'raw' => substr($text, 0, 500)], 500);
            }
            jsonOut($decoded, !empty($decoded['ok']) ? 200 : 500);

        case 'vol_accuracy_report':
            // Same pipeline as public/vol-accuracy-report.php → slim JSON for DeepSeek
            @ini_set('max_execution_time', '180');
            @set_time_limit(180);
            $marketId = filter_var($_GET['market_id'] ?? 120, FILTER_VALIDATE_INT);
            $marketId = $marketId === false ? 120 : $marketId;
            $candleCount = filter_var($_GET['candles'] ?? 400, FILTER_VALIDATE_INT);
            if ($candleCount === false || !in_array($candleCount, [200, 400, 600], true)) {
                $candleCount = 400;
            }
            $volMetric = (string) ($_GET['vol'] ?? 'ATR(14) pct');
            if (!in_array($volMetric, TechnicalAnalysis::VOLATILITY_METHODS, true)) {
                $volMetric = 'ATR(14) pct';
            }
            $force = (string) ($_GET['force'] ?? '') === '1';
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
                    jsonOut($cached);
                }
            }
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
            // Slim payload for the trader (keep tokens modest)
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
            jsonOut($out);

        case 'sim_1m_start':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $opts = [
                'market_id' => (int) ($_GET['market_id'] ?? $_POST['market_id'] ?? 120),
                'lot_usd' => (float) ($_GET['lot_usd'] ?? $_POST['lot_usd'] ?? 200),
                'tick_sec' => (int) ($_GET['tick_sec'] ?? $_POST['tick_sec'] ?? 30),
                'method' => (string) ($_GET['method'] ?? $_POST['method'] ?? 'ROC(10) zero-cross'),
                'tp_levels' => json_decode((string) ($_GET['tp_levels'] ?? $_POST['tp_levels'] ?? '[50]'), true) ?: [50],
                'sl_levels' => json_decode((string) ($_GET['sl_levels'] ?? $_POST['sl_levels'] ?? '[30]'), true) ?: [30],
            ];
            jsonOut(Sim1mEngine::start($simClient, $cfg, $opts));

        case 'sim_1m_stop':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Sim1mEngine::stop($simClient, $cfg, $sid === false ? null : $sid));

        case 'sim_1m_resume':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Sim1mEngine::resume($cfg, $sid === false ? null : $sid));

        case 'sim_1m_candidates':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Sim1mEngine::candidatePositions($simClient, $cfg, $sid === false ? null : $sid));

        case 'sim_1m_adopt':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $raw = $_GET['selected'] ?? $_POST['selected'] ?? '[]';
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $selected = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)));
            } elseif (is_array($raw)) {
                $selected = $raw;
            } else {
                $selected = [];
            }
            jsonOut(Sim1mEngine::adoptPositions(
                $simClient,
                $cfg,
                $selected,
                $sid === false ? null : $sid,
            ));

        case 'sim_1m_tick':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Sim1mEngine::tick($simClient, $cfg, $sid === false ? null : $sid));

        case 'sim_1m_status':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Sim1mEngine::status($cfg, $sid === false ? null : $sid));

        case 'sim_1m_set_method':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $method = (string) ($_GET['method'] ?? $_POST['method'] ?? '');
            $out = Sim1mEngine::setMethod($cfg, $method, $sid === false ? null : $sid);
            jsonOut($out, !empty($out['ok']) ? 200 : 400);

        case 'sim_1m_set_levels':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $tpRaw = $_GET['tp_levels'] ?? $_POST['tp_levels'] ?? null;
            $slRaw = $_GET['sl_levels'] ?? $_POST['sl_levels'] ?? null;
            $opts = [
                'tp_levels' => is_string($tpRaw) ? (json_decode($tpRaw, true) ?: null) : $tpRaw,
                'sl_levels' => is_string($slRaw) ? (json_decode($slRaw, true) ?: null) : $slRaw,
                'lot_usd' => isset($_GET['lot_usd']) || isset($_POST['lot_usd'])
                    ? (float) ($_GET['lot_usd'] ?? $_POST['lot_usd'])
                    : null,
                'resize_lot' => filter_var($_GET['resize_lot'] ?? $_POST['resize_lot'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'mark_price' => isset($_GET['mark_price']) || isset($_POST['mark_price'])
                    ? (float) ($_GET['mark_price'] ?? $_POST['mark_price'])
                    : 0.0,
            ];
            $out = Sim1mEngine::setLevels($cfg, $opts, $sid === false ? null : $sid);
            jsonOut($out, !empty($out['ok']) ? 200 : 400);

        case 'live_1m_start':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $opts = [
                'market_id' => (int) ($_GET['market_id'] ?? $_POST['market_id'] ?? 120),
                'lot_usd' => (float) ($_GET['lot_usd'] ?? $_POST['lot_usd'] ?? 200),
                'tick_sec' => (int) ($_GET['tick_sec'] ?? $_POST['tick_sec'] ?? 30),
                'method' => (string) ($_GET['method'] ?? $_POST['method'] ?? 'ROC(10) zero-cross'),
                'tp_levels' => json_decode((string) ($_GET['tp_levels'] ?? $_POST['tp_levels'] ?? '[50]'), true) ?: [50],
                'sl_levels' => json_decode((string) ($_GET['sl_levels'] ?? $_POST['sl_levels'] ?? '[30]'), true) ?: [30],
            ];
            jsonOut(Live1mEngine::start($simClient, $cfg, $opts));

        case 'live_1m_stop':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Live1mEngine::stop($simClient, $cfg, $sid === false ? null : $sid));

        case 'live_1m_resume':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Live1mEngine::resume($cfg, $sid === false ? null : $sid));

        case 'live_1m_candidates':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Live1mEngine::candidatePositions($simClient, $cfg, $sid === false ? null : $sid));

        case 'live_1m_adopt':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $raw = $_GET['selected'] ?? $_POST['selected'] ?? '[]';
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $selected = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)));
            } elseif (is_array($raw)) {
                $selected = $raw;
            } else {
                $selected = [];
            }
            jsonOut(Live1mEngine::adoptPositions(
                $simClient,
                $cfg,
                $selected,
                $sid === false ? null : $sid,
            ));

        case 'live_1m_tick':
            $cfg = IndicatorConfig::load();
            $simClient = $cfg->network() === 'testnet' ? Client::testnet(45) : Client::mainnet(45);
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Live1mEngine::tick($simClient, $cfg, $sid === false ? null : $sid));

        case 'live_1m_status':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? null, FILTER_VALIDATE_INT);
            jsonOut(Live1mEngine::status($cfg, $sid === false ? null : $sid));

        case 'live_1m_set_method':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $method = (string) ($_GET['method'] ?? $_POST['method'] ?? '');
            $out = Live1mEngine::setMethod($cfg, $method, $sid === false ? null : $sid);
            jsonOut($out, !empty($out['ok']) ? 200 : 400);

        case 'live_1m_set_levels':
            $cfg = IndicatorConfig::load();
            $sid = filter_var($_GET['session_id'] ?? $_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
            $tpRaw = $_GET['tp_levels'] ?? $_POST['tp_levels'] ?? null;
            $slRaw = $_GET['sl_levels'] ?? $_POST['sl_levels'] ?? null;
            $opts = [
                'tp_levels' => is_string($tpRaw) ? (json_decode($tpRaw, true) ?: null) : $tpRaw,
                'sl_levels' => is_string($slRaw) ? (json_decode($slRaw, true) ?: null) : $slRaw,
                'lot_usd' => isset($_GET['lot_usd']) || isset($_POST['lot_usd'])
                    ? (float) ($_GET['lot_usd'] ?? $_POST['lot_usd'])
                    : null,
                'resize_lot' => filter_var($_GET['resize_lot'] ?? $_POST['resize_lot'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'mark_price' => isset($_GET['mark_price']) || isset($_POST['mark_price'])
                    ? (float) ($_GET['mark_price'] ?? $_POST['mark_price'])
                    : 0.0,
            ];
            $out = Live1mEngine::setLevels($cfg, $opts, $sid === false ? null : $sid);
            jsonOut($out, !empty($out['ok']) ? 200 : 400);

        default:
            jsonOut([
                'ok' => false,
                'error' => 'Unknown action. Use …|sim_1m_*|live_1m_start|live_1m_stop|live_1m_resume|live_1m_candidates|live_1m_adopt|live_1m_tick|live_1m_status|live_1m_set_method|live_1m_set_levels|sim_1m_set_levels',
            ], 400);
    }
} catch (ApiException $e) {
    jsonOut([
        'ok' => false,
        'error' => $e->getMessage(),
        'response' => $e->response,
    ], $e->httpStatus >= 400 ? $e->httpStatus : 502);
} catch (Throwable $e) {
    jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
}
