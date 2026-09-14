<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\Exception\ApiException;
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
    $client = $network === 'testnet' ? Client::testnet() : Client::mainnet();

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

            $books = $client->orderBooks(marketId: $marketId);
            $market = ($books['order_books'] ?? [])[0] ?? null;
            $details = $client->orderBookDetails(marketId: $marketId);
            $orderBook = $client->orderBookOrders($marketId, limit: $depth);
            $trades = $client->recentTrades($marketId, limit: $tradesLimit);
            $candles = $client->candles($marketId, $resolution, countBack: $candleCount);
            $stats = $client->exchangeStats();

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
                'details' => $details,
                'order_book' => $orderBook,
                'trades' => $trades['trades'] ?? [],
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
            $marketId = 1;
            $candleCount = filter_var($_GET['candle_count'] ?? 200, FILTER_VALIDATE_INT);
            $candleCount = $candleCount === false ? 200 : max(50, min(500, $candleCount));
            $depth = filter_var($_GET['depth'] ?? 15, FILTER_VALIDATE_INT);
            $tradesLimit = filter_var($_GET['trades'] ?? 25, FILTER_VALIDATE_INT);
            $depth = $depth === false ? 15 : max(1, min(100, $depth));
            $tradesLimit = $tradesLimit === false ? 25 : max(1, min(100, $tradesLimit));

            $resolutions = ['1d', '4h', '1h', '30m', '15m', '5m', '1m'];
            $books = $client->orderBooks(marketId: $marketId);
            $market = ($books['order_books'] ?? [])[0] ?? null;
            $details = $client->orderBookDetails(marketId: $marketId);
            $orderBook = $client->orderBookOrders($marketId, limit: $depth);
            $trades = $client->recentTrades($marketId, limit: $tradesLimit);
            $stats = $client->exchangeStats();

            $frames = [];
            foreach ($resolutions as $resolution) {
                $candles = $client->candles($marketId, $resolution, countBack: $candleCount);
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
                'details' => $details,
                'order_book' => $orderBook,
                'trades' => $trades['trades'] ?? [],
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
            if (!TechnicalAnalysis::isKnownMethod($method)) {
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

            $books = $client->orderBooks(marketId: $marketId);
            $market = ($books['order_books'] ?? [])[0] ?? null;
            $orderBook = $client->orderBookOrders($marketId, limit: $depth);
            $trades = $client->recentTrades($marketId, limit: $tradesLimit);
            $candlesResp = $client->candles($marketId, $resolution, countBack: $candleCount);
            $candles = $candlesResp['c'] ?? [];
            $stats = $client->exchangeStats();
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
                'order_book' => $orderBook,
                'trades' => $trades['trades'] ?? [],
                'candles' => $candles,
                'indicator' => TechnicalAnalysis::indicatorChartData($candles, $method),
                'market_stats' => $marketStats,
                'updated_at' => (int) floor(microtime(true) * 1000),
            ]);

        default:
            jsonOut(['ok' => false, 'error' => 'Unknown action. Use markets|market|btc_analyze|live'], 400);
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
