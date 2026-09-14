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

    public static function isKnownMethod(string $method): bool
    {
        return in_array($method, self::METHODS, true);
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

        $macdSig = array_fill(0, $n, 0);
        $rsiSig = array_fill(0, $n, 0);
        $smaSig = array_fill(0, $n, 0);
        $emaSig = array_fill(0, $n, 0);
        $bbSig = array_fill(0, $n, 0);
        $rocSig = array_fill(0, $n, 0);
        $momSig = array_fill(0, $n, 0);

        for ($i = 1; $i < $n; $i++) {
            // MACD cross
            if ($macd['macd'][$i] !== null && $macd['signal'][$i] !== null
                && $macd['macd'][$i - 1] !== null && $macd['signal'][$i - 1] !== null) {
                $prev = $macd['macd'][$i - 1] - $macd['signal'][$i - 1];
                $curr = $macd['macd'][$i] - $macd['signal'][$i];
                if ($prev <= 0 && $curr > 0) {
                    $macdSig[$i] = 1;
                } elseif ($prev >= 0 && $curr < 0) {
                    $macdSig[$i] = -1;
                }
            }

            // RSI mean reversion
            if ($rsi[$i] !== null && $rsi[$i - 1] !== null) {
                if ($rsi[$i - 1] < 30 && $rsi[$i] >= 30) {
                    $rsiSig[$i] = 1;
                } elseif ($rsi[$i - 1] > 70 && $rsi[$i] <= 70) {
                    $rsiSig[$i] = -1;
                }
            }

            // SMA 10/30 cross
            if ($smaFast[$i] !== null && $smaSlow[$i] !== null
                && $smaFast[$i - 1] !== null && $smaSlow[$i - 1] !== null) {
                $prev = $smaFast[$i - 1] - $smaSlow[$i - 1];
                $curr = $smaFast[$i] - $smaSlow[$i];
                if ($prev <= 0 && $curr > 0) {
                    $smaSig[$i] = 1;
                } elseif ($prev >= 0 && $curr < 0) {
                    $smaSig[$i] = -1;
                }
            }

            // EMA 12/26 cross
            if ($emaFast[$i] !== null && $emaSlow[$i] !== null
                && $emaFast[$i - 1] !== null && $emaSlow[$i - 1] !== null) {
                $prev = $emaFast[$i - 1] - $emaSlow[$i - 1];
                $curr = $emaFast[$i] - $emaSlow[$i];
                if ($prev <= 0 && $curr > 0) {
                    $emaSig[$i] = 1;
                } elseif ($prev >= 0 && $curr < 0) {
                    $emaSig[$i] = -1;
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

            // ROC zero cross
            if ($roc[$i] !== null && $roc[$i - 1] !== null) {
                if ($roc[$i - 1] <= 0 && $roc[$i] > 0) {
                    $rocSig[$i] = 1;
                } elseif ($roc[$i - 1] >= 0 && $roc[$i] < 0) {
                    $rocSig[$i] = -1;
                }
            }

            // Momentum: close vs close N bars ago
            if ($i >= 11) {
                $diffPrev = $closes[$i - 1] - $closes[$i - 11];
                $diffCurr = $closes[$i] - $closes[$i - 10];
                if ($diffPrev <= 0 && $diffCurr > 0) {
                    $momSig[$i] = 1;
                } elseif ($diffPrev >= 0 && $diffCurr < 0) {
                    $momSig[$i] = -1;
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
                return [
                    'lines' => [
                        ['name' => 'MACD', 'color' => '#1f4b7a', 'values' => $m['macd']],
                        ['name' => 'Signal', 'color' => '#c45c26', 'values' => $m['signal']],
                    ],
                    'hist' => $m['hist'],
                    'levels' => [0.0],
                ];
            })(),
            'RSI(14) 30/70' => [
                'lines' => [
                    ['name' => 'RSI', 'color' => '#1f4b7a', 'values' => self::rsi($closes, 14)],
                ],
                'levels' => [30.0, 70.0],
            ],
            'SMA(10/30) cross' => [
                'lines' => [
                    ['name' => 'SMA10', 'color' => '#1f4b7a', 'values' => self::sma($closes, 10)],
                    ['name' => 'SMA30', 'color' => '#c45c26', 'values' => self::sma($closes, 30)],
                ],
            ],
            'EMA(12/26) cross' => [
                'lines' => [
                    ['name' => 'EMA12', 'color' => '#1f4b7a', 'values' => self::ema($closes, 12)],
                    ['name' => 'EMA26', 'color' => '#c45c26', 'values' => self::ema($closes, 26)],
                ],
            ],
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
            'ROC(10) zero-cross' => [
                'lines' => [
                    ['name' => 'ROC10', 'color' => '#1f4b7a', 'values' => self::roc($closes, 10)],
                ],
                'levels' => [0.0],
            ],
            'Momentum(10) flip' => (static function () use ($closes): array {
                $n = count($closes);
                $mom = array_fill(0, $n, null);
                for ($i = 10; $i < $n; $i++) {
                    $mom[$i] = $closes[$i] - $closes[$i - 10];
                }
                return [
                    'lines' => [
                        ['name' => 'Mom10', 'color' => '#1f4b7a', 'values' => $mom],
                    ],
                    'levels' => [0.0],
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
}
