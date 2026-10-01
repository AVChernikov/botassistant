<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Fetch candles, compute indicators, append/upsert OHLCV + indicator bars per TF.
 * Full candle window is still loaded for warmup; DB write is incremental.
 */
final class IndicatorTick
{
    /**
     * @param list<int>|null $onlyMarketIds
     * @return array{
     *   ok: bool,
     *   network: string,
     *   mode: string,
     *   indicators: list<string>,
     *   frames: list<array<string, mixed>>,
     *   rows: int,
     *   candles: int,
     *   rows_computed: int,
     *   stats_rows: int,
     *   saved: array<string, mixed>,
     *   error?: string|null
     * }
     */
    public static function run(Client $client, IndicatorConfig $cfg, ?array $onlyMarketIds = null, string $mode = 'append'): array
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['append', 'replace'], true)) {
            $mode = 'append';
        }

        $marketIds = $onlyMarketIds ?? $cfg->marketIds();
        $resolutions = $cfg->resolutions();
        $candleCount = $cfg->candleCount();
        $enabled = $cfg->indicators();
        $dbPath = $cfg->dbPath();

        $wm = $mode === 'append' ? IndicatorHistoryStore::watermarkMaps($dbPath) : ['snap' => [], 'candle' => []];
        $snapWm = $wm['snap'];
        $candleWm = $wm['candle'];

        $allCandles = [];
        $allRows = [];
        $allStats = [];
        $replaceScopes = [];
        $frames = [];
        $computedRows = 0;

        foreach ($marketIds as $marketId) {
            $marketId = (int) $marketId;
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

                if ($mode === 'replace') {
                    $replaceScopes[] = ['market_id' => $marketId, 'resolution' => $resolution];
                }

                $key = $marketId . '|' . $resolution;
                // Separate watermarks: empty candles table → bootstrap full window; snaps already filled → only new bars.
                $candleSince = ($mode === 'append' && isset($candleWm[$key])) ? (int) $candleWm[$key] : 0;
                $snapSince = ($mode === 'append' && isset($snapWm[$key])) ? (int) $snapWm[$key] : 0;

                $candlesKept = 0;
                foreach ($candles as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $barTs = (int) ($c['t'] ?? $c['timestamp'] ?? 0);
                    if ($barTs > 0 && $barTs < 1_000_000_000_000) {
                        $barTs *= 1000;
                    }
                    if ($barTs <= 0) {
                        continue;
                    }
                    if ($candleSince > 0 && $barTs < $candleSince) {
                        continue;
                    }
                    $allCandles[] = [
                        'market_id' => $marketId,
                        'symbol' => $symbol,
                        'resolution' => $resolution,
                        'bar_ts' => $barTs,
                        'open' => isset($c['o']) ? (float) $c['o'] : null,
                        'high' => isset($c['h']) ? (float) $c['h'] : null,
                        'low' => isset($c['l']) ? (float) $c['l'] : null,
                        'close' => isset($c['c']) ? (float) $c['c'] : null,
                        'volume' => isset($c['v']) ? (float) $c['v'] : null,
                    ];
                    $candlesKept++;
                }

                $snap = IndicatorSnapshot::compute($candles, $enabled);
                $computedRows += count($snap);

                $kept = 0;
                foreach ($snap as $row) {
                    $barTs = (int) $row['bar_ts'];
                    if ($snapSince > 0 && $barTs < $snapSince) {
                        continue;
                    }
                    $allRows[] = [
                        'market_id' => $marketId,
                        'symbol' => $symbol,
                        'resolution' => $resolution,
                        'bar_ts' => $barTs,
                        'indicator' => $row['indicator'],
                        'value' => $row['value'],
                        'signal' => $row['signal'],
                        'close' => $row['close'],
                    ];
                    $kept++;
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
                    'candles_write' => $candlesKept,
                    'indicator_names' => count($enabled),
                    'rows_computed' => count($snap),
                    'rows_write' => $kept,
                    'stats' => count($stats),
                    'candle_since_bar_ts' => $candleSince > 0 ? $candleSince : null,
                    'snap_since_bar_ts' => $snapSince > 0 ? $snapSince : null,
                    'skipped' => false,
                ];
            }
        }

        $save = IndicatorHistoryStore::saveRows($dbPath, $allRows, $replaceScopes, $allStats, $mode, $allCandles);

        return [
            'ok' => (bool) ($save['ok'] ?? false),
            'network' => $cfg->network(),
            'mode' => $mode === 'replace'
                ? 'replace_candles+indicators+stats'
                : 'append_candles+indicators+upsert_stats',
            'indicators' => $enabled,
            'frames' => $frames,
            'candles' => count($allCandles),
            'rows' => count($allRows),
            'rows_computed' => $computedRows,
            'stats_rows' => count($allStats),
            'saved' => $save,
            'error' => $save['error'] ?? null,
        ];
    }
}
