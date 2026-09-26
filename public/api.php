<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

@ini_set('max_execution_time', '120');
@set_time_limit(120);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\DeepSeekClient;
use Lighter\DeepSeekQueryStore;
use Lighter\Exception\ApiException;
use Lighter\IndicatorAnalyzer;
use Lighter\IndicatorCompact;
use Lighter\IndicatorConfig;
use Lighter\IndicatorHistoryStore;
use Lighter\IndicatorReportStore;
use Lighter\IndicatorSnapshot;
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
            $resolutions = $cfg->resolutions();
            $candleCount = $cfg->candleCount();
            $enabled = $cfg->indicators();
            $dbPath = $cfg->dbPath();

            $qMarket = filter_var($_GET['market_id'] ?? null, FILTER_VALIDATE_INT);
            if ($qMarket !== false && $qMarket !== null) {
                $marketIds = [(int) $qMarket];
            }

            $allRows = [];
            $allStats = [];
            $replaceScopes = [];
            $frames = [];
            foreach ($marketIds as $marketId) {
                $requests = [
                    'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
                ];
                foreach ($resolutions as $resolution) {
                    $requests['c_' . $resolution] = $client->candlesRequest(
                        $marketId,
                        $resolution,
                        countBack: $candleCount,
                    );
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
            jsonOut([
                'ok' => (bool) $save['ok'],
                'network' => $network,
                'mode' => 'replace_snapshot_all_candles+stats',
                'indicators' => $enabled,
                'frames' => $frames,
                'rows' => count($allRows),
                'stats_rows' => count($allStats),
                'saved' => $save,
                'updated_at' => (int) floor(microtime(true) * 1000),
                'error' => $save['error'] ?? null,
            ], !empty($save['ok']) ? 200 : 500);

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
            $compact = IndicatorCompact::build($cfg->dbPath(), $marketId, $events);
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
            $py = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
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

        default:
            jsonOut([
                'ok' => false,
                'error' => 'Unknown action. Use markets|market|btc_analyze|market_analyze|live|indicators_tick|indicators_history|indicators_config|indicators_compact|indicators_analyze|indicators_report|indicators_pipeline|deepseek_queries|trading_status',
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
