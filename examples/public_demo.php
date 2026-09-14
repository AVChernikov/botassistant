<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run: composer dump-autoload\n");
    exit(1);
}
require $autoload;

use Lighter\Client;
use Lighter\Exception\ApiException;

$client = Client::mainnet();

try {
    echo "Base URL: {$client->getBaseUrl()}\n\n";

    $books = $client->orderBooks(filter: 'perp');
    $markets = $books['order_books'] ?? [];
    $active = array_values(array_filter(
        $markets,
        static fn (array $m): bool => ($m['status'] ?? '') === 'active',
    ));

    echo 'Active perp markets: ' . count($active) . "\n";
    foreach (array_slice($active, 0, 5) as $market) {
        echo sprintf(
            "  #%d %s  size_decimals=%s price_decimals=%s\n",
            $market['market_id'],
            $market['symbol'],
            $market['supported_size_decimals'],
            $market['supported_price_decimals'],
        );
    }

    $ethMarketId = 0;
    $ob = $client->orderBookOrders($ethMarketId, limit: 3);
    echo "\nOrder book (market_id={$ethMarketId}):\n";
    echo '  asks: ' . ($ob['total_asks'] ?? count($ob['asks'] ?? [])) . "\n";
    echo '  bids: ' . ($ob['total_bids'] ?? count($ob['bids'] ?? [])) . "\n";
    if (!empty($ob['asks'][0])) {
        echo "  best ask: {$ob['asks'][0]['price']} x {$ob['asks'][0]['remaining_base_amount']}\n";
    }
    if (!empty($ob['bids'][0])) {
        echo "  best bid: {$ob['bids'][0]['price']} x {$ob['bids'][0]['remaining_base_amount']}\n";
    }

    $trades = $client->recentTrades($ethMarketId, limit: 3);
    echo "\nRecent trades:\n";
    foreach ($trades['trades'] ?? [] as $trade) {
        echo sprintf(
            "  %s @ %s  size=%s\n",
            $trade['type'] ?? 'trade',
            $trade['price'],
            $trade['size'],
        );
    }

    $stats = $client->exchangeStats();
    echo "\nExchange daily USD volume: " . ($stats['daily_usd_volume'] ?? 'n/a') . "\n";
    echo 'Daily trades: ' . ($stats['daily_trades_count'] ?? 'n/a') . "\n";
} catch (ApiException $e) {
    fwrite(STDERR, 'API error: ' . $e->getMessage() . "\n");
    if ($e->response !== null) {
        fwrite(STDERR, json_encode($e->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(1);
}
