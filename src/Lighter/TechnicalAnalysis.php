<?php

declare(strict_types=1);

namespace Lighter;

/**
 * Technical analysis helpers and simple signal backtests.
 */
final class TechnicalAnalysis
{
    public const METHODS = [
        'MACD(12,26,9) cross',
        'RSI(14) 30/70',
        'SMA(10/30) cross',
        'EMA(12/26) cross',
        'Bollinger(20,2) bounce',
        'ROC(10) zero-cross',
        'Momentum(10) flip',
    ];

    /** Value metrics (no directional signal); listed in config/indicators.env */
    public const VOLATILITY_METHODS = [
        'ATR(14) pct',
        'RV(48) log',
        'Bollinger(20,2) width',
    ];

    /**
     * Inverse-vol deadzone in %-like units:
     * quiet (low median |x|) → ±0.30; busy → down to ±0.05.
     */
    public const EPS_LOW_VOL = 0.30;
    public const EPS_HIGH_VOL = 0.05;
    /** median |x| at/below → EPS_LOW_VOL */
    public const VOL_LO = 0.15;
    /** median |x| at/above → EPS_HIGH_VOL */
    public const VOL_HI = 0.80;
    /** @deprecated alias */
    public const ROC_EPS_LOW_VOL = self::EPS_LOW_VOL;
    /** @deprecated alias */
    public const ROC_EPS_HIGH_VOL = self::EPS_HIGH_VOL;
    /** @deprecated alias */
    public const ROC_VOL_LO = self::VOL_LO;
    /** @deprecated alias */
    public const ROC_VOL_HI = self::VOL_HI;
    /** @deprecated */
    public const ROC_ZERO_EPS_MIN = self::EPS_HIGH_VOL;
    /** @deprecated legacy proportional deadzone */
    public const ZERO_EPS_FRAC = 0.35;
    /**
     * Max fraction of baseEps moved from one side to the other by trend bias.
     * Uptrend: |lo| shrinks, hi grows by up to this × baseEps (width ≈ 2×baseEps).
     */
    public const DEADZONE_TREND_SKEW = 0.35;
    /** |(SMA_fast−SMA_slow)/price| at/above → bias ±1 */
    public const TREND_BIAS_FULL_AT = 0.004;

    public static function isKnownMethod(string $method): bool
    {
        return in_array($method, self::METHODS, true)
            || in_array($method, self::VOLATILITY_METHODS, true);
    }

    public static function isVolatilityMethod(string $method): bool
    {
        return in_array($method, self::VOLATILITY_METHODS, true);
    }

    /**
     * @param list<array<string, mixed>> $candles oldest -> newest
     * @return list<float>
     */
    public static function closes(array $candles): array
    {
        return array_map(static fn (array $c): float => (float) $c['c'], $candles);
    }

    /**
     * @param list<array<string, mixed>> $candles
     * @return list<float>
     */
    public static function highs(array $candles): array
    {
        return array_map(static fn (array $c): float => (float) ($c['h'] ?? $c['c']), $candles);
    }

    /**
     * @param list<array<string, mixed>> $candles
     * @return list<float>
     */
    public static function lows(array $candles): array
    {
        return array_map(static fn (array $c): float => (float) ($c['l'] ?? $c['c']), $candles);
    }

    /**
     * Candle open time in ms (best-effort).
     *
     * @param list<array<string, mixed>> $candles
     * @return list<int>
     */
    public static function timestamps(array $candles): array
    {
        $out = [];
        foreach ($candles as $c) {
            $t = (int) ($c['t'] ?? $c['timestamp'] ?? 0);
            if ($t > 0 && $t < 1_000_000_000_000) {
                $t *= 1000;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * True range series (index 0 = null).
     *
     * @param list<float> $highs
     * @param list<float> $lows
     * @param list<float> $closes
     * @return list<float|null>
     */
    public static function trueRange(array $highs, array $lows, array $closes): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            $out[$i] = max(
                $highs[$i] - $lows[$i],
                abs($highs[$i] - $closes[$i - 1]),
                abs($lows[$i] - $closes[$i - 1]),
            );
        }

        return $out;
    }

    /**
     * ATR as SMA of true range.
     *
     * @param list<array<string, mixed>> $candles
     * @return list<float|null>
     */
    public static function atr(array $candles, int $period = 14): array
    {
        $tr = self::trueRange(self::highs($candles), self::lows($candles), self::closes($candles));
        $vals = [];
        $map = [];
        foreach ($tr as $i => $v) {
            if ($v !== null) {
                $map[] = $i;
                $vals[] = $v;
            }
        }
        $sma = self::sma($vals, $period);
        $n = count($tr);
        $out = array_fill(0, $n, null);
        foreach ($map as $j => $idx) {
            $out[$idx] = $sma[$j];
        }

        return $out;
    }

    /**
     * ATR as % of close.
     *
     * @param list<array<string, mixed>> $candles
     * @return list<float|null>
     */
    public static function atrPct(array $candles, int $period = 14): array
    {
        $atr = self::atr($candles, $period);
        $closes = self::closes($candles);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($atr[$i] !== null && $closes[$i] != 0.0) {
                $out[$i] = ($atr[$i] / $closes[$i]) * 100.0;
            }
        }

        return $out;
    }

    /**
     * Rolling stdev of log returns (not annualized), in percent.
     *
     * @param list<float> $closes
     * @return list<float|null>
     */
    public static function realizedVolLog(array $closes, int $window = 48): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($window < 2 || $n < $window + 1) {
            return $out;
        }
        $logs = array_fill(0, $n, null);
        for ($i = 1; $i < $n; $i++) {
            if ($closes[$i - 1] > 0.0 && $closes[$i] > 0.0) {
                $logs[$i] = log($closes[$i] / $closes[$i - 1]);
            }
        }
        for ($i = $window; $i < $n; $i++) {
            $slice = [];
            for ($j = $i - $window + 1; $j <= $i; $j++) {
                if ($logs[$j] !== null) {
                    $slice[] = $logs[$j];
                }
            }
            if (count($slice) < max(2, (int) floor($window * 0.8))) {
                continue;
            }
            $mean = array_sum($slice) / count($slice);
            $var = 0.0;
            foreach ($slice as $v) {
                $var += ($v - $mean) ** 2;
            }
            $std = sqrt($var / count($slice));
            $out[$i] = $std * 100.0;
        }

        return $out;
    }

    /**
     * Bollinger band width = (upper-lower)/mid.
     *
     * @param list<float> $closes
     * @return list<float|null>
     */
    public static function bollingerWidth(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $bb = self::bollinger($closes, $period, $mult);
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            $mid = $bb['mid'][$i];
            $up = $bb['upper'][$i];
            $lo = $bb['lower'][$i];
            if ($mid !== null && $up !== null && $lo !== null && $mid != 0.0) {
                $out[$i] = ($up - $lo) / $mid;
            }
        }

        return $out;
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    public static function sma(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period) {
            return $out;
        }
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }
            if ($i >= $period - 1) {
                $out[$i] = $sum / $period;
            }
        }

        return $out;
    }

    /**
     * @param list<float> $values
     * @return list<float|null>
     */
    public static function ema(array $values, int $period): array
    {
        $n = count($values);
        $out = array_fill(0, $n, null);
        if ($n < $period) {
            return $out;
        }
        $k = 2 / ($period + 1);
        $sum = 0.0;
        for ($i = 0; $i < $period; $i++) {
            $sum += $values[$i];
        }
        $prev = $sum / $period;
        $out[$period - 1] = $prev;
        for ($i = $period; $i < $n; $i++) {
            $prev = $values[$i] * $k + $prev * (1 - $k);
            $out[$i] = $prev;
        }

        return $out;
    }

    /**
     * @param list<float> $closes
     * @return array{macd: list<float|null>, signal: list<float|null>, hist: list<float|null>}
     */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast = self::ema($closes, $fast);
        $emaSlow = self::ema($closes, $slow);
        $n = count($closes);
        $macd = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $macd[$i] = $emaFast[$i] - $emaSlow[$i];
            }
        }
        $macdVals = [];
        $map = [];
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] !== null) {
                $map[] = $i;
                $macdVals[] = $macd[$i];
            }
        }
        $signalSeed = self::ema($macdVals, $signal);
        $signalLine = array_fill(0, $n, null);
        foreach ($map as $j => $idx) {
            $signalLine[$idx] = $signalSeed[$j];
        }
        $hist = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($macd[$i] !== null && $signalLine[$i] !== null) {
                $hist[$i] = $macd[$i] - $signalLine[$i];
            }
        }

        return ['macd' => $macd, 'signal' => $signalLine, 'hist' => $hist];
    }

    /**
     * @param list<float> $closes
     * @return list<float|null>
     */
    public static function rsi(array $closes, int $period = 14): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        if ($n <= $period) {
            return $out;
        }
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i <= $period; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            if ($diff >= 0) {
                $gains += $diff;
            } else {
                $losses -= $diff;
            }
        }
        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $out[$period] = $avgLoss == 0.0 ? 100.0 : 100 - (100 / (1 + $avgGain / $avgLoss));
        for ($i = $period + 1; $i < $n; $i++) {
            $diff = $closes[$i] - $closes[$i - 1];
            $gain = $diff > 0 ? $diff : 0.0;
            $loss = $diff < 0 ? -$diff : 0.0;
            $avgGain = (($avgGain * ($period - 1)) + $gain) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $loss) / $period;
            $out[$i] = $avgLoss == 0.0 ? 100.0 : 100 - (100 / (1 + $avgGain / $avgLoss));
        }

        return $out;
    }

    /**
     * @param list<float> $closes
     * @return array{mid: list<float|null>, upper: list<float|null>, lower: list<float|null>}
     */
    public static function bollinger(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $n = count($closes);
        $mid = self::sma($closes, $period);
        $upper = array_fill(0, $n, null);
        $lower = array_fill(0, $n, null);
        for ($i = $period - 1; $i < $n; $i++) {
            $slice = array_slice($closes, $i - $period + 1, $period);
            $mean = $mid[$i];
            if ($mean === null) {
                continue;
            }
            $var = 0.0;
            foreach ($slice as $v) {
                $var += ($v - $mean) ** 2;
            }
            $std = sqrt($var / $period);
            $upper[$i] = $mean + $mult * $std;
            $lower[$i] = $mean - $mult * $std;
        }

        return ['mid' => $mid, 'upper' => $upper, 'lower' => $lower];
    }

    /**
     * @param list<float> $closes
     * @return list<float|null>
     */
    public static function roc(array $closes, int $period = 10): array
    {
        $n = count($closes);
        $out = array_fill(0, $n, null);
        for ($i = $period; $i < $n; $i++) {
            if ($closes[$i - $period] == 0.0) {
                continue;
            }
            $out[$i] = (($closes[$i] - $closes[$i - $period]) / $closes[$i - $period]) * 100;
        }

        return $out;
    }

    /**
     * Signals: 1 = long bias, -1 = short bias, 0 = flat/no change.
     * Evaluates directional accuracy over $horizon bars after each non-zero signal.
     *
     * @param list<array<string, mixed>> $candles
     * @param list<int> $signals same length as candles
     * @return array{
     *   signals: int,
     *   correct: int,
     *   accuracy: float|null,
     *   accuracy_last5: float|null,
     *   correct_last5: int,
     *   signals_last5: int,
     *   avg_move_pct: float|null,
     *   strategy_return_pct: float,
     *   profit_factor: float|null,
     *   wins: int,
     *   losses: int
     * }
     */
    public static function evaluate(array $candles, array $signals, int $horizon = 1): array
    {
        $closes = self::closes($candles);
        $n = count($closes);
        $correct = 0;
        $total = 0;
        $moveSum = 0.0;
        $wins = 0;
        $losses = 0;
        $grossWin = 0.0;
        $grossLoss = 0.0;
        $equity = 1.0;
        $position = 0;
        /** @var list<bool> $outcomes chronological signal outcomes (true = correct) */
        $outcomes = [];

        for ($i = 0; $i < $n; $i++) {
            $sig = $signals[$i] ?? 0;
            if ($sig !== 0 && $i + $horizon < $n) {
                $move = $closes[$i + $horizon] - $closes[$i];
                $movePct = $closes[$i] != 0.0 ? ($move / $closes[$i]) * 100 : 0.0;
                $predictedUp = $sig > 0;
                $actualUp = $move > 0;
                $ok = $predictedUp === $actualUp && $move != 0.0;
                if ($move == 0.0) {
                    // ignore flat outcomes
                } else {
                    $total++;
                    $moveSum += $predictedUp ? $movePct : -$movePct;
                    $outcomes[] = $ok;
                    if ($ok) {
                        $correct++;
                    }
                }
            }

            // simple strategy: flip to signal direction on signal bars
            if ($sig !== 0) {
                $position = $sig > 0 ? 1 : -1;
            }
            if ($i > 0 && $position !== 0 && $closes[$i - 1] != 0.0) {
                $ret = (($closes[$i] - $closes[$i - 1]) / $closes[$i - 1]) * $position;
                $equity *= (1 + $ret);
                if ($ret > 0) {
                    $wins++;
                    $grossWin += $ret;
                } elseif ($ret < 0) {
                    $losses++;
                    $grossLoss += abs($ret);
                }
            }
        }

        $lastOutcomes = array_slice($outcomes, -5);
        $signalsLast5 = count($lastOutcomes);
        $correctLast5 = count(array_filter($lastOutcomes));

        return [
            'signals' => $total,
            'correct' => $correct,
            'accuracy' => $total > 0 ? $correct / $total : null,
            'accuracy_last5' => $signalsLast5 > 0 ? $correctLast5 / $signalsLast5 : null,
            'correct_last5' => $correctLast5,
            'signals_last5' => $signalsLast5,
            'avg_move_pct' => $total > 0 ? $moveSum / $total : null,
            'strategy_return_pct' => ($equity - 1) * 100,
            'profit_factor' => $grossLoss > 0 ? $grossWin / $grossLoss : ($grossWin > 0 ? null : null),
            'wins' => $wins,
            'losses' => $losses,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candles
     * @return array<string, list<int>>
     */
    public static function generateAllSignals(array $candles): array
    {
        $closes = self::closes($candles);
        $n = count($closes);

        $macd = self::macd($closes);
        $rsi = self::rsi($closes, 14);
        $smaFast = self::sma($closes, 10);
        $smaSlow = self::sma($closes, 30);
        $emaFast = self::ema($closes, 12);
        $emaSlow = self::ema($closes, 26);
        $bb = self::bollinger($closes, 20, 2.0);
        $roc = self::roc($closes, 10);

        $macdHist = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($macd['macd'][$i] !== null && $macd['signal'][$i] !== null) {
                $macdHist[$i] = (float) $macd['macd'][$i] - (float) $macd['signal'][$i];
            }
        }
        $smaDiff = array_fill(0, $n, null);
        $emaDiff = array_fill(0, $n, null);
        $mom = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($smaFast[$i] !== null && $smaSlow[$i] !== null) {
                $smaDiff[$i] = (float) $smaFast[$i] - (float) $smaSlow[$i];
            }
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null) {
                $emaDiff[$i] = (float) $emaFast[$i] - (float) $emaSlow[$i];
            }
            if ($i >= 10) {
                $mom[$i] = $closes[$i] - $closes[$i - 10];
            }
        }
        $lastPx = (float) ($closes[$n - 1] ?? 0);
        $rocLv = self::deadzoneLevelsByTrend($roc, $closes, null, true);
        $macdLv = self::deadzoneLevelsByTrend($macdHist, $closes, self::priceDiffDeadzone($macdHist, $lastPx));
        $smaLv = self::deadzoneLevelsByTrend($smaDiff, $closes, self::priceDiffDeadzone($smaDiff, $lastPx));
        $emaLv = self::deadzoneLevelsByTrend($emaDiff, $closes, self::priceDiffDeadzone($emaDiff, $lastPx));
        $momLv = self::deadzoneLevelsByTrend($mom, $closes, self::priceDiffDeadzone($mom, $lastPx));
        $macdSig = self::deadzoneBoundarySignals($macdHist, $macdLv['lo'], $macdLv['hi']);
        $smaSig = self::deadzoneBoundarySignals($smaDiff, $smaLv['lo'], $smaLv['hi']);
        $emaSig = self::deadzoneBoundarySignals($emaDiff, $emaLv['lo'], $emaLv['hi']);
        $rocSig = self::deadzoneBoundarySignals($roc, $rocLv['lo'], $rocLv['hi']);
        $momSig = self::deadzoneBoundarySignals($mom, $momLv['lo'], $momLv['hi']);

        $rsiSig = array_fill(0, $n, 0);
        $bbSig = array_fill(0, $n, 0);
        for ($i = 1; $i < $n; $i++) {
            // RSI mean reversion
            if ($rsi[$i] !== null && $rsi[$i - 1] !== null) {
                if ($rsi[$i - 1] < 30 && $rsi[$i] >= 30) {
                    $rsiSig[$i] = 1;
                } elseif ($rsi[$i - 1] > 70 && $rsi[$i] <= 70) {
                    $rsiSig[$i] = -1;
                }
            }

            // Bollinger bounce
            if ($bb['lower'][$i] !== null && $bb['upper'][$i] !== null
                && $bb['lower'][$i - 1] !== null && $bb['upper'][$i - 1] !== null) {
                if ($closes[$i - 1] <= $bb['lower'][$i - 1] && $closes[$i] > $bb['lower'][$i]) {
                    $bbSig[$i] = 1;
                } elseif ($closes[$i - 1] >= $bb['upper'][$i - 1] && $closes[$i] < $bb['upper'][$i]) {
                    $bbSig[$i] = -1;
                }
            }
        }

        return [
            'MACD(12,26,9) cross' => $macdSig,
            'RSI(14) 30/70' => $rsiSig,
            'SMA(10/30) cross' => $smaSig,
            'EMA(12/26) cross' => $emaSig,
            'Bollinger(20,2) bounce' => $bbSig,
            'ROC(10) zero-cross' => $rocSig,
            'Momentum(10) flip' => $momSig,
        ];
    }

    /**
     * Deadzone around zero: max(minEps, frac × median |values|).
     * Legacy helper; prefer volInverseDeadzone / priceDiffDeadzone.
     *
     * @param list<float|null> $values
     */
    public static function seriesDeadzone(array $values, float $minEps, float $frac = self::ZERO_EPS_FRAC): float
    {
        $med = self::seriesAbsMedian($values);
        if ($med === null) {
            return max(0.0, $minEps);
        }

        return max($minEps, $med * $frac);
    }

    /**
     * Inverse-vol deadzone for %-like series (ROC, or price-diff as % of price).
     * Low vol → ±0.30; high vol → ±0.05 (linear in between).
     *
     * @param list<float|null> $values
     */
    public static function volInverseDeadzone(array $values): float
    {
        $med = self::seriesAbsMedian($values);
        $hi = self::EPS_LOW_VOL;
        $lo = self::EPS_HIGH_VOL;
        if ($med === null) {
            return $hi;
        }
        if ($med <= self::VOL_LO) {
            return $hi;
        }
        if ($med >= self::VOL_HI) {
            return $lo;
        }
        $t = ($med - self::VOL_LO) / (self::VOL_HI - self::VOL_LO);

        return $hi + $t * ($lo - $hi);
    }

    /** ROC % deadzone — same inverse-vol scheme. */
    public static function rocDeadzone(array $roc): float
    {
        return self::volInverseDeadzone($roc);
    }

    /**
     * Deadzone for price-unit spreads (MACD hist, SMA/EMA diff, momentum).
     * Same vol scheme in % of $price, returned in price units.
     *
     * @param list<float|null> $diff
     */
    public static function priceDiffDeadzone(array $diff, float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }
        $pct = [];
        foreach ($diff as $v) {
            $pct[] = $v === null ? null : ((float) $v / $price) * 100.0;
        }

        return (self::volInverseDeadzone($pct) / 100.0) * $price;
    }

    /**
     * Price trend from SMA fast/slow: raw relative gap + bias in [-1, 1].
     * Positive = uptrend (fast above slow).
     *
     * @param list<float> $closes
     * @return array{trend: float, bias: float, sma_fast: ?float, sma_slow: ?float, price: ?float}
     */
    public static function trendBiasFromCloses(array $closes, int $fast = 10, int $slow = 30): array
    {
        $n = count($closes);
        $empty = [
            'trend' => 0.0,
            'bias' => 0.0,
            'sma_fast' => null,
            'sma_slow' => null,
            'price' => null,
        ];
        if ($n < $slow || $fast < 1 || $slow <= $fast) {
            return $empty;
        }
        $sf = self::sma($closes, $fast);
        $ss = self::sma($closes, $slow);
        $i = $n - 1;
        if ($sf[$i] === null || $ss[$i] === null) {
            return $empty;
        }
        $price = (float) $closes[$i];
        if ($price <= 0) {
            return $empty;
        }
        $trend = ((float) $sf[$i] - (float) $ss[$i]) / $price;
        $full = self::TREND_BIAS_FULL_AT;
        $bias = $full > 0 ? max(-1.0, min(1.0, $trend / $full)) : 0.0;

        return [
            'trend' => $trend,
            'bias' => $bias,
            'sma_fast' => (float) $sf[$i],
            'sma_slow' => (float) $ss[$i],
            'price' => $price,
        ];
    }

    /**
     * Deadzone levels: width from vol (baseEps), asymmetry from price trend.
     * Default: uptrend → |lo| smaller / hi larger (long easier).
     * $invertTrend: flip skew (ROC) — uptrend → long harder / short easier.
     * Total span stays ≈ 2 × baseEps.
     *
     * @param list<float|null> $values indicator series for vol width (e.g. ROC)
     * @param list<float> $closes price closes for SMA trend
     * @return array{lo: float, hi: float, base_eps: float, trend: float, bias: float, invert: bool}
     */
    public static function deadzoneLevelsByTrend(
        array $values,
        array $closes,
        ?float $baseEps = null,
        bool $invertTrend = false,
    ): array {
        $base = $baseEps !== null ? max(0.0, (float) $baseEps) : self::volInverseDeadzone($values);
        $tb = self::trendBiasFromCloses($closes);
        $bias = (float) ($tb['bias'] ?? 0.0);
        if ($invertTrend) {
            $bias = -$bias;
        }
        $skew = self::DEADZONE_TREND_SKEW * $base * $bias;
        // lo more negative when bias < 0; hi larger when bias > 0
        $lo = -($base - $skew);
        $hi = $base + $skew;
        // Keep lo < 0 < hi even at extreme skew
        $minHalf = max(1e-12, $base * (1.0 - self::DEADZONE_TREND_SKEW));
        if ($lo >= 0) {
            $lo = -$minHalf;
        }
        if ($hi <= 0) {
            $hi = $minHalf;
        }

        return [
            'lo' => $lo,
            'hi' => $hi,
            'base_eps' => $base,
            'trend' => (float) ($tb['trend'] ?? 0.0),
            'bias' => $bias,
            'invert' => $invertTrend,
        ];
    }

    /**
     * Raw zero-cross series + eps for a method (for logs / charts).
     *
     * @param list<array<string, mixed>> $candles
     * @return array{series: list<float|null>, eps: float, value: float|null, unit: string}
     */
    public static function methodZeroSeries(array $candles, string $method): array
    {
        $closes = self::closes($candles);
        $n = count($closes);
        $price = (float) ($closes[$n - 1] ?? 0);
        $series = array_fill(0, $n, null);
        $eps = 0.0;
        $unit = 'abs';

        if ($method === 'ROC(10) zero-cross') {
            $series = self::roc($closes, 10);
            $eps = self::rocDeadzone($series);
            $unit = 'ROC%';
        } elseif ($method === 'MACD(12,26,9) cross') {
            $m = self::macd($closes);
            for ($i = 0; $i < $n; $i++) {
                if ($m['macd'][$i] !== null && $m['signal'][$i] !== null) {
                    $series[$i] = (float) $m['macd'][$i] - (float) $m['signal'][$i];
                }
            }
            $eps = self::priceDiffDeadzone($series, $price);
            $unit = 'price';
        } elseif ($method === 'SMA(10/30) cross') {
            $f = self::sma($closes, 10);
            $s = self::sma($closes, 30);
            for ($i = 0; $i < $n; $i++) {
                if ($f[$i] !== null && $s[$i] !== null) {
                    $series[$i] = (float) $f[$i] - (float) $s[$i];
                }
            }
            $eps = self::priceDiffDeadzone($series, $price);
            $unit = 'price';
        } elseif ($method === 'EMA(12/26) cross') {
            $f = self::ema($closes, 12);
            $s = self::ema($closes, 26);
            for ($i = 0; $i < $n; $i++) {
                if ($f[$i] !== null && $s[$i] !== null) {
                    $series[$i] = (float) $f[$i] - (float) $s[$i];
                }
            }
            $eps = self::priceDiffDeadzone($series, $price);
            $unit = 'price';
        } elseif ($method === 'Momentum(10) flip') {
            for ($i = 10; $i < $n; $i++) {
                $series[$i] = $closes[$i] - $closes[$i - 10];
            }
            $eps = self::priceDiffDeadzone($series, $price);
            $unit = 'price';
        }

        $levels = $eps > 0
            ? self::deadzoneLevelsByTrend(
                $series,
                $closes,
                $eps,
                $method === 'ROC(10) zero-cross',
            )
            : ['lo' => 0.0, 'hi' => 0.0, 'base_eps' => 0.0, 'trend' => 0.0, 'bias' => 0.0, 'invert' => false];

        $closed = max(0, $n - 2);
        $value = $series[$closed] ?? null;

        return [
            'series' => $series,
            'eps' => $eps,
            'lo' => (float) $levels['lo'],
            'hi' => (float) $levels['hi'],
            'bias' => (float) $levels['bias'],
            'trend' => (float) $levels['trend'],
            'value' => $value !== null ? (float) $value : null,
            'unit' => $unit,
            'bar' => $closed,
        ];
    }

    /**
     * Chart meta for a zero-line deadzone band (optional asymmetric lo/hi from trend).
     *
     * @param list<float|null> $series
     * @return array{eps: float, med: ?float, levels: list<float>, bands: list<array<string, mixed>>, deadzone_eps: float, vol_median: ?float, lo: float, hi: float, bias: ?float}
     */
    public static function deadzoneChartExtras(
        array $series,
        float $eps,
        string $volLabel = 'vol',
        ?float $lo = null,
        ?float $hi = null,
        ?float $bias = null,
    ): array {
        $med = self::seriesAbsMedian($series);
        $loBound = $lo !== null ? (float) $lo : -$eps;
        $hiBound = $hi !== null ? (float) $hi : $eps;
        if ($loBound > $hiBound) {
            [$loBound, $hiBound] = [$hiBound, $loBound];
        }
        $asym = abs($loBound + $hiBound) > 1e-12 || ($bias !== null && abs($bias) > 1e-6);
        $labelCore = $asym
            ? sprintf('мёртвая зона [%.4g … %.4g]', $loBound, $hiBound)
            : sprintf('мёртвая зона ±%.4g', $eps);
        if ($bias !== null && abs($bias) > 1e-6) {
            $labelCore .= sprintf(' bias%+.2f', $bias);
        }
        if ($med !== null) {
            $labelCore .= sprintf(' (%s=%.4g)', $volLabel, $med);
        }

        return [
            'eps' => $eps,
            'lo' => $loBound,
            'hi' => $hiBound,
            'bias' => $bias,
            'med' => $med,
            'levels' => [0.0, $hiBound, $loBound],
            'bands' => [
                [
                    'lo' => $loBound,
                    'hi' => $hiBound,
                    'color' => 'rgba(196, 92, 38, 0.22)',
                    'border' => '#c45c26',
                    'label' => $labelCore,
                ],
            ],
            'deadzone_eps' => $eps,
            'vol_median' => $med,
        ];
    }

    /** Median |values| (vol proxy); null if too few points. */
    public static function seriesAbsMedian(array $values): ?float
    {
        $abs = [];
        foreach ($values as $v) {
            if ($v === null) {
                continue;
            }
            $abs[] = abs((float) $v);
        }
        if (count($abs) < 8) {
            return null;
        }
        sort($abs);

        return $abs[(int) floor(count($abs) / 2)];
    }

    /**
     * Deadzone boundary crosses (not zero-cross):
     * +1 long  — cross lower bound (lo, default −eps) from below upward
     * -1 short — cross upper bound (hi, default +eps) from above downward
     *
     * @param list<float|null> $values
     * @param float $epsOrLo symmetric eps, or lower bound when $hi is set
     */
    public static function deadzoneBoundarySignals(array $values, float $epsOrLo, ?float $hi = null): array
    {
        $n = count($values);
        $sig = array_fill(0, $n, 0);
        if ($hi === null) {
            $eps = max(0.0, $epsOrLo);
            $loBound = -$eps;
            $hiBound = $eps;
        } else {
            $loBound = (float) $epsOrLo;
            $hiBound = (float) $hi;
            if ($loBound > $hiBound) {
                [$loBound, $hiBound] = [$hiBound, $loBound];
            }
        }
        for ($i = 1; $i < $n; $i++) {
            if ($values[$i] === null || $values[$i - 1] === null) {
                continue;
            }
            $prev = (float) $values[$i - 1];
            $curr = (float) $values[$i];
            if ($prev < $loBound && $curr >= $loBound) {
                $sig[$i] = 1;
            } elseif ($prev > $hiBound && $curr <= $hiBound) {
                $sig[$i] = -1;
            }
        }

        return $sig;
    }

    /** @deprecated use deadzoneBoundarySignals */
    public static function hysteresisZeroSignals(array $values, float $eps): array
    {
        return self::deadzoneBoundarySignals($values, $eps);
    }

    /**
     * Series for plotting the indicator of a ranked method.
     *
     * @param list<array<string, mixed>> $candles
     * @return array{lines: list<array{name: string, color: string, values: list<float|null>}>, bands?: list<array{name: string, color: string, values: list<float|null>}> , hist?: list<float|null>, levels?: list<float>}
     */
    public static function indicatorChartData(array $candles, string $method): array
    {
        $closes = self::closes($candles);

        return match ($method) {
            'MACD(12,26,9) cross' => (static function () use ($closes): array {
                $m = self::macd($closes);
                $n = count($closes);
                $hist = $m['hist'];
                $price = (float) ($closes[$n - 1] ?? 0);
                $eps = self::priceDiffDeadzone($hist, $price);
                $lv = self::deadzoneLevelsByTrend($hist, $closes, $eps);
                $extra = self::deadzoneChartExtras($hist, $eps, 'med|hist|', $lv['lo'], $lv['hi'], $lv['bias']);
                return [
                    'lines' => [
                        ['name' => 'MACD', 'color' => '#1f4b7a', 'values' => $m['macd']],
                        ['name' => 'Signal', 'color' => '#c45c26', 'values' => $m['signal']],
                    ],
                    'hist' => $hist,
                    'levels' => $extra['levels'],
                    'bands' => $extra['bands'],
                    'deadzone_eps' => $extra['deadzone_eps'],
                    'vol_median' => $extra['vol_median'],
                ];
            })(),
            'RSI(14) 30/70' => [
                'lines' => [
                    ['name' => 'RSI', 'color' => '#1f4b7a', 'values' => self::rsi($closes, 14)],
                ],
                'levels' => [30.0, 70.0],
            ],
            'SMA(10/30) cross' => (static function () use ($closes): array {
                $f = self::sma($closes, 10);
                $s = self::sma($closes, 30);
                $n = count($closes);
                $diff = array_fill(0, $n, null);
                for ($i = 0; $i < $n; $i++) {
                    if ($f[$i] !== null && $s[$i] !== null) {
                        $diff[$i] = (float) $f[$i] - (float) $s[$i];
                    }
                }
                $price = (float) ($closes[$n - 1] ?? 0);
                $eps = self::priceDiffDeadzone($diff, $price);
                $lv = self::deadzoneLevelsByTrend($diff, $closes, $eps);
                $extra = self::deadzoneChartExtras($diff, $eps, 'med|diff|', $lv['lo'], $lv['hi'], $lv['bias']);
                return [
                    'lines' => [
                        ['name' => 'SMA10−SMA30', 'color' => '#1f4b7a', 'values' => $diff],
                    ],
                    'levels' => $extra['levels'],
                    'bands' => $extra['bands'],
                    'deadzone_eps' => $extra['deadzone_eps'],
                    'vol_median' => $extra['vol_median'],
                ];
            })(),
            'EMA(12/26) cross' => (static function () use ($closes): array {
                $f = self::ema($closes, 12);
                $s = self::ema($closes, 26);
                $n = count($closes);
                $diff = array_fill(0, $n, null);
                for ($i = 0; $i < $n; $i++) {
                    if ($f[$i] !== null && $s[$i] !== null) {
                        $diff[$i] = (float) $f[$i] - (float) $s[$i];
                    }
                }
                $price = (float) ($closes[$n - 1] ?? 0);
                $eps = self::priceDiffDeadzone($diff, $price);
                $lv = self::deadzoneLevelsByTrend($diff, $closes, $eps);
                $extra = self::deadzoneChartExtras($diff, $eps, 'med|diff|', $lv['lo'], $lv['hi'], $lv['bias']);
                return [
                    'lines' => [
                        ['name' => 'EMA12−EMA26', 'color' => '#1f4b7a', 'values' => $diff],
                    ],
                    'levels' => $extra['levels'],
                    'bands' => $extra['bands'],
                    'deadzone_eps' => $extra['deadzone_eps'],
                    'vol_median' => $extra['vol_median'],
                ];
            })(),
            'Bollinger(20,2) bounce' => (static function () use ($closes): array {
                $bb = self::bollinger($closes, 20, 2.0);
                return [
                    'lines' => [
                        ['name' => 'Mid', 'color' => '#1f4b7a', 'values' => $bb['mid']],
                        ['name' => 'Upper', 'color' => '#0f6b4c', 'values' => $bb['upper']],
                        ['name' => 'Lower', 'color' => '#b42318', 'values' => $bb['lower']],
                        ['name' => 'Close', 'color' => '#5c6b61', 'values' => array_map(static fn ($v) => (float) $v, $closes)],
                    ],
                ];
            })(),
            'ROC(10) zero-cross' => (static function () use ($closes): array {
                $roc = self::roc($closes, 10);
                $eps = self::rocDeadzone($roc);
                $lv = self::deadzoneLevelsByTrend($roc, $closes, $eps, true);
                $extra = self::deadzoneChartExtras($roc, $eps, 'med|ROC|', $lv['lo'], $lv['hi'], $lv['bias']);
                return [
                    'lines' => [
                        ['name' => 'ROC10', 'color' => '#1f4b7a', 'values' => $roc],
                    ],
                    'levels' => $extra['levels'],
                    'bands' => $extra['bands'],
                    'deadzone_eps' => $extra['deadzone_eps'],
                    'vol_median' => $extra['vol_median'],
                ];
            })(),
            'Momentum(10) flip' => (static function () use ($closes): array {
                $n = count($closes);
                $mom = array_fill(0, $n, null);
                for ($i = 10; $i < $n; $i++) {
                    $mom[$i] = $closes[$i] - $closes[$i - 10];
                }
                $price = (float) ($closes[$n - 1] ?? 0);
                $eps = self::priceDiffDeadzone($mom, $price);
                $lv = self::deadzoneLevelsByTrend($mom, $closes, $eps);
                $extra = self::deadzoneChartExtras($mom, $eps, 'med|mom|', $lv['lo'], $lv['hi'], $lv['bias']);
                return [
                    'lines' => [
                        ['name' => 'Mom10', 'color' => '#1f4b7a', 'values' => $mom],
                    ],
                    'levels' => $extra['levels'],
                    'bands' => $extra['bands'],
                    'deadzone_eps' => $extra['deadzone_eps'],
                    'vol_median' => $extra['vol_median'],
                ];
            })(),
            default => [
                'lines' => [
                    ['name' => 'Close', 'color' => '#1f4b7a', 'values' => array_map(static fn ($v) => (float) $v, $closes)],
                ],
            ],
        };
    }

    /**
     * Top-N ranked entries with candles + indicator series for charts.
     *
     * @param array<string, list<array<string, mixed>>> $framesByResolution
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    public static function topChartBundles(array $framesByResolution, array $results, int $limit = 5): array
    {
        $bundles = [];
        foreach (array_slice($results, 0, $limit) as $i => $row) {
            $tf = (string) $row['resolution'];
            $method = (string) $row['method'];
            $candles = $framesByResolution[$tf] ?? [];
            $bundles[] = [
                'rank' => $i + 1,
                'method' => $method,
                'resolution' => $tf,
                'accuracy' => $row['accuracy'],
                'score' => $row['score'],
                'signals' => $row['signals'],
                'candles' => $candles,
                'indicator' => self::indicatorChartData($candles, $method),
            ];
        }

        return $bundles;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $framesByResolution
     * @return array{
     *   results: list<array<string, mixed>>,
     *   best: ?array<string, mixed>,
     *   by_method: array<string, array<string, mixed>>,
     *   by_timeframe: array<string, array<string, mixed>>
     * }
     */
    public static function runReport(array $framesByResolution, int $horizon = 1): array
    {
        $results = [];
        foreach ($framesByResolution as $resolution => $candles) {
            if (count($candles) < 40) {
                continue;
            }
            $all = self::generateAllSignals($candles);
            foreach ($all as $method => $signals) {
                $stats = self::evaluate($candles, $signals, $horizon);
                if (($stats['signals'] ?? 0) < 3) {
                    continue;
                }
                $score = self::score($stats);
                $results[] = [
                    'method' => $method,
                    'resolution' => $resolution,
                    'candles' => count($candles),
                    'signals' => $stats['signals'],
                    'correct' => $stats['correct'],
                    'accuracy' => $stats['accuracy'],
                    'accuracy_last5' => $stats['accuracy_last5'],
                    'correct_last5' => $stats['correct_last5'],
                    'signals_last5' => $stats['signals_last5'],
                    'avg_move_pct' => $stats['avg_move_pct'],
                    'strategy_return_pct' => $stats['strategy_return_pct'],
                    'profit_factor' => $stats['profit_factor'],
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'score' => $score,
                ];
            }
        }

        usort($results, static function (array $a, array $b): int {
            return ($b['score'] <=> $a['score']) ?: (($b['accuracy'] ?? 0) <=> ($a['accuracy'] ?? 0));
        });

        $byMethod = [];
        $byTf = [];
        foreach ($results as $row) {
            $m = $row['method'];
            $tf = $row['resolution'];
            if (!isset($byMethod[$m]) || $row['score'] > $byMethod[$m]['score']) {
                $byMethod[$m] = $row;
            }
            if (!isset($byTf[$tf]) || $row['score'] > $byTf[$tf]['score']) {
                $byTf[$tf] = $row;
            }
        }

        return [
            'results' => $results,
            'best' => $results[0] ?? null,
            'by_method' => $byMethod,
            'by_timeframe' => $byTf,
        ];
    }

    /**
     * Composite score: accuracy weighted with return quality and sample size.
     *
     * @param array<string, mixed> $stats
     */
    public static function score(array $stats): float
    {
        $acc = (float) ($stats['accuracy'] ?? 0);
        $signals = (int) ($stats['signals'] ?? 0);
        $ret = (float) ($stats['strategy_return_pct'] ?? 0);
        $sampleBonus = min(1.0, $signals / 20) * 0.08;
        $returnComponent = tanh($ret / 20) * 0.15;

        return $acc + $sampleBonus + $returnComponent;
    }

    /**
     * Pearson correlation; null if fewer than 3 paired points or zero variance.
     *
     * @param list<float> $xs
     * @param list<float> $ys
     */
    public static function pearson(array $xs, array $ys): ?float
    {
        $n = min(count($xs), count($ys));
        if ($n < 3) {
            return null;
        }
        $sx = 0.0;
        $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sx += $xs[$i];
            $sy += $ys[$i];
        }
        $mx = $sx / $n;
        $my = $sy / $n;
        $num = 0.0;
        $dx = 0.0;
        $dy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $a = $xs[$i] - $mx;
            $b = $ys[$i] - $my;
            $num += $a * $b;
            $dx += $a * $a;
            $dy += $b * $b;
        }
        if ($dx <= 0.0 || $dy <= 0.0) {
            return null;
        }

        return $num / sqrt($dx * $dy);
    }

    /**
     * Mean of non-null floats.
     *
     * @param list<float|null> $series
     */
    public static function seriesMean(array $series): ?float
    {
        $sum = 0.0;
        $n = 0;
        foreach ($series as $v) {
            if ($v === null) {
                continue;
            }
            $sum += (float) $v;
            $n++;
        }

        return $n > 0 ? $sum / $n : null;
    }

    /**
     * Volatility + directional accuracy + corr(vol, hit) per method × timeframe.
     *
     * Pipeline: (1) vol series  (2) signal accuracy  (3) Pearson vol↔outcome.
     *
     * @param array<string, list<array<string, mixed>>> $framesByResolution
     * @return array{
     *   volatility: array<string, array<string, mixed>>,
     *   results: list<array<string, mixed>>,
     *   by_method: array<string, list<array<string, mixed>>>,
     *   matrix: array<string, array<string, array<string, mixed>>>,
     *   vol_metric: string,
     *   horizon: int
     * }
     */
    public static function runVolAccuracyReport(
        array $framesByResolution,
        int $horizon = 1,
        string $volMetric = 'ATR(14) pct',
    ): array {
        $volMetric = in_array($volMetric, self::VOLATILITY_METHODS, true)
            ? $volMetric
            : 'ATR(14) pct';

        $volatility = [];
        $results = [];
        $byMethod = [];
        $matrix = [];

        foreach ($framesByResolution as $resolution => $candles) {
            if (count($candles) < 40) {
                continue;
            }
            $closes = self::closes($candles);
            $atrPct = self::atrPct($candles, 14);
            $rv = self::realizedVolLog($closes, 48);
            $bbw = self::bollingerWidth($closes, 20, 2.0);
            $primary = match ($volMetric) {
                'RV(48) log' => $rv,
                'Bollinger(20,2) width' => $bbw,
                default => $atrPct,
            };

            $volatility[(string) $resolution] = [
                'resolution' => (string) $resolution,
                'candles' => count($candles),
                'atr_pct_mean' => self::seriesMean($atrPct),
                'rv_log_mean' => self::seriesMean($rv),
                'bb_width_mean' => self::seriesMean($bbw),
                'primary_metric' => $volMetric,
                'primary_mean' => self::seriesMean($primary),
            ];

            $all = self::generateAllSignals($candles);
            foreach ($all as $method => $signals) {
                $stats = self::evaluate($candles, $signals, $horizon);
                if (($stats['signals'] ?? 0) < 3) {
                    continue;
                }

                $vols = [];
                $hits = [];
                $n = count($closes);
                for ($i = 0; $i < $n; $i++) {
                    $sig = $signals[$i] ?? 0;
                    if ($sig === 0 || $i + $horizon >= $n) {
                        continue;
                    }
                    $vol = $primary[$i] ?? null;
                    if ($vol === null) {
                        continue;
                    }
                    $move = $closes[$i + $horizon] - $closes[$i];
                    if ($move == 0.0) {
                        continue;
                    }
                    $ok = (($sig > 0) === ($move > 0)) ? 1.0 : 0.0;
                    $vols[] = (float) $vol;
                    $hits[] = $ok;
                }

                $corr = self::pearson($vols, $hits);
                $buckets = self::accuracyByVolTercile($vols, $hits);

                $row = [
                    'method' => $method,
                    'resolution' => (string) $resolution,
                    'candles' => count($candles),
                    'signals' => $stats['signals'],
                    'correct' => $stats['correct'],
                    'accuracy' => $stats['accuracy'],
                    'accuracy_last5' => $stats['accuracy_last5'],
                    'strategy_return_pct' => $stats['strategy_return_pct'],
                    'profit_factor' => $stats['profit_factor'],
                    'score' => self::score($stats),
                    'vol_metric' => $volMetric,
                    'vol_mean_at_signals' => count($vols) > 0 ? array_sum($vols) / count($vols) : null,
                    'vol_pairs' => count($vols),
                    'corr_vol_accuracy' => $corr,
                    'accuracy_vol_low' => $buckets['low'],
                    'accuracy_vol_mid' => $buckets['mid'],
                    'accuracy_vol_high' => $buckets['high'],
                    'n_vol_low' => $buckets['n_low'],
                    'n_vol_mid' => $buckets['n_mid'],
                    'n_vol_high' => $buckets['n_high'],
                    'tf_vol_mean' => $volatility[(string) $resolution]['primary_mean'],
                ];
                $results[] = $row;
                $byMethod[$method][] = $row;
                $matrix[$method][(string) $resolution] = [
                    'accuracy' => $row['accuracy'],
                    'corr' => $row['corr_vol_accuracy'],
                    'signals' => $row['signals'],
                    'vol_mean' => $row['vol_mean_at_signals'],
                ];
            }
        }

        usort($results, static function (array $a, array $b): int {
            $ca = $a['corr_vol_accuracy'];
            $cb = $b['corr_vol_accuracy'];
            if ($ca === null && $cb === null) {
                return ($b['accuracy'] ?? 0) <=> ($a['accuracy'] ?? 0);
            }
            if ($ca === null) {
                return 1;
            }
            if ($cb === null) {
                return -1;
            }
            // strongest |corr| first
            return (abs((float) $cb) <=> abs((float) $ca))
                ?: (($b['accuracy'] ?? 0) <=> ($a['accuracy'] ?? 0));
        });

        return [
            'volatility' => $volatility,
            'results' => $results,
            'by_method' => $byMethod,
            'matrix' => $matrix,
            'vol_metric' => $volMetric,
            'horizon' => $horizon,
        ];
    }

    /**
     * Accuracy in low / mid / high volatility terciles (by signal-time vol).
     *
     * @param list<float> $vols
     * @param list<float> $hits 0 or 1
     * @return array{low:?float,mid:?float,high:?float,n_low:int,n_mid:int,n_high:int}
     */
    private static function accuracyByVolTercile(array $vols, array $hits): array
    {
        $n = min(count($vols), count($hits));
        if ($n < 3) {
            return [
                'low' => null, 'mid' => null, 'high' => null,
                'n_low' => 0, 'n_mid' => 0, 'n_high' => 0,
            ];
        }
        $idx = range(0, $n - 1);
        usort($idx, static fn (int $i, int $j): int => $vols[$i] <=> $vols[$j]);
        $t1 = (int) floor($n / 3);
        $t2 = (int) floor(2 * $n / 3);
        $groups = ['low' => [], 'mid' => [], 'high' => []];
        foreach ($idx as $rank => $i) {
            if ($rank < $t1) {
                $groups['low'][] = $hits[$i];
            } elseif ($rank < $t2) {
                $groups['mid'][] = $hits[$i];
            } else {
                $groups['high'][] = $hits[$i];
            }
        }
        $avg = static function (array $xs): ?float {
            if ($xs === []) {
                return null;
            }

            return array_sum($xs) / count($xs);
        };

        return [
            'low' => $avg($groups['low']),
            'mid' => $avg($groups['mid']),
            'high' => $avg($groups['high']),
            'n_low' => count($groups['low']),
            'n_mid' => count($groups['mid']),
            'n_high' => count($groups['high']),
        ];
    }
}
