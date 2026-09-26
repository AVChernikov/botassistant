<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Full-window indicator snapshot: one row per candle × indicator.
 * Cron replaces previous snapshot for each market+resolution.
 */
final class IndicatorSnapshot
{
    /**
     * @param list<array<string, mixed>> $candles oldest -> newest
     * @param list<string> $enabled
     * @return list<array{indicator: string, value: float|null, signal: int|null, bar_ts: int, close: float|null}>
     */
    public static function compute(array $candles, array $enabled): array
    {
        if ($candles === [] || $enabled === []) {
            return [];
        }

        $closes = TechnicalAnalysis::closes($candles);
        $times = TechnicalAnalysis::timestamps($candles);
        $n = count($candles);
        $signals = TechnicalAnalysis::generateAllSignals($candles);
        $rows = [];

        foreach ($enabled as $name) {
            $values = self::valueSeries($name, $candles, $closes);
            $sigSeries = $signals[$name] ?? null;

            for ($i = 0; $i < $n; $i++) {
                $barTs = (int) ($times[$i] ?? 0);
                if ($barTs <= 0) {
                    continue;
                }
                $rows[] = [
                    'indicator' => $name,
                    'value' => $values[$i] ?? null,
                    'signal' => $sigSeries !== null ? (int) ($sigSeries[$i] ?? 0) : null,
                    'bar_ts' => $barTs,
                    'close' => $closes[$i] ?? null,
                ];
            }
        }

        return $rows;
    }

/**
     * Per-method backtest metrics on the same candle window (signal methods only).
     *
     * @param list<array<string, mixed>> $candles
     * @param list<string> $enabled
     * @return list<array{
     *   indicator: string,
     *   strategy_return_pct: float|null,
     *   profit_factor: float|null,
     *   accuracy: float|null,
     *   signals: int,
     *   wins: int,
     *   losses: int,
     *   last_signal: int|null,
     *   last_value: float|null,
     *   bar_ts: int
     * }>
     */
    public static function computeStats(array $candles, array $enabled): array
    {
        if ($candles === [] || $enabled === []) {
            return [];
        }

        $times = TechnicalAnalysis::timestamps($candles);
        $last = count($candles) - 1;
        $barTs = (int) ($times[$last] ?? 0);
        $allSignals = TechnicalAnalysis::generateAllSignals($candles);
        $out = [];

        foreach ($enabled as $name) {
            if (TechnicalAnalysis::isVolatilityMethod($name) || !isset($allSignals[$name])) {
                $series = self::valueSeries($name, $candles, TechnicalAnalysis::closes($candles));
                $out[] = [
                    'indicator' => $name,
                    'strategy_return_pct' => null,
                    'profit_factor' => null,
                    'accuracy' => null,
                    'signals' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'last_signal' => null,
                    'last_value' => $series[$last] ?? null,
                    'bar_ts' => $barTs,
                ];
                continue;
            }

            $sig = $allSignals[$name];
            $ev = TechnicalAnalysis::evaluate($candles, $sig, 1);
            $values = self::valueSeries($name, $candles, TechnicalAnalysis::closes($candles));
            $out[] = [
                'indicator' => $name,
                'strategy_return_pct' => isset($ev['strategy_return_pct']) ? (float) $ev['strategy_return_pct'] : null,
                'profit_factor' => $ev['profit_factor'] !== null ? (float) $ev['profit_factor'] : null,
                'accuracy' => $ev['accuracy'] !== null ? (float) $ev['accuracy'] : null,
                'signals' => (int) ($ev['signals'] ?? 0),
                'wins' => (int) ($ev['wins'] ?? 0),
                'losses' => (int) ($ev['losses'] ?? 0),
                'last_signal' => (int) ($sig[$last] ?? 0),
                'last_value' => $values[$last] ?? null,
                'bar_ts' => $barTs,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $candles
     * @param list<float> $closes
     * @return list<float|null>
     */
    private static function valueSeries(string $name, array $candles, array $closes): array
    {
        $n = count($closes);

        if ($name === 'ATR(14) pct') {
            return TechnicalAnalysis::atrPct($candles, 14);
        }
        if ($name === 'RV(48) log') {
            return TechnicalAnalysis::realizedVolLog($closes, 48);
        }
        if ($name === 'Bollinger(20,2) width') {
            return TechnicalAnalysis::bollingerWidth($closes, 20, 2.0);
        }

        return match ($name) {
            'MACD(12,26,9) cross' => TechnicalAnalysis::macd($closes)['hist'],
            'RSI(14) 30/70' => TechnicalAnalysis::rsi($closes, 14),
            'SMA(10/30) cross' => (static function () use ($closes, $n): array {
                $f = TechnicalAnalysis::sma($closes, 10);
                $s = TechnicalAnalysis::sma($closes, 30);
                $out = array_fill(0, $n, null);
                for ($i = 0; $i < $n; $i++) {
                    if ($f[$i] !== null && $s[$i] !== null) {
                        $out[$i] = $f[$i] - $s[$i];
                    }
                }

                return $out;
            })(),
            'EMA(12/26) cross' => (static function () use ($closes, $n): array {
                $f = TechnicalAnalysis::ema($closes, 12);
                $s = TechnicalAnalysis::ema($closes, 26);
                $out = array_fill(0, $n, null);
                for ($i = 0; $i < $n; $i++) {
                    if ($f[$i] !== null && $s[$i] !== null) {
                        $out[$i] = $f[$i] - $s[$i];
                    }
                }

                return $out;
            })(),
            'Bollinger(20,2) bounce' => (static function () use ($closes, $n): array {
                $bb = TechnicalAnalysis::bollinger($closes, 20, 2.0);
                $out = array_fill(0, $n, null);
                for ($i = 0; $i < $n; $i++) {
                    $mid = $bb['mid'][$i];
                    if ($mid !== null && $mid != 0.0) {
                        $out[$i] = ($closes[$i] - $mid) / $mid;
                    }
                }

                return $out;
            })(),
            'ROC(10) zero-cross' => TechnicalAnalysis::roc($closes, 10),
            'Momentum(10) flip' => (static function () use ($closes, $n): array {
                $out = array_fill(0, $n, null);
                for ($i = 10; $i < $n; $i++) {
                    $out[$i] = $closes[$i] - $closes[$i - 10];
                }

                return $out;
            })(),
            default => array_map(static fn ($v) => (float) $v, $closes),
        };
    }
}
