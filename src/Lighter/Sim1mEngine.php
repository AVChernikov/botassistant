<?php

declare(strict_types=1);

namespace Lighter;

/**
 * 1m paper emulation: fetch book+candles → TA → rule fills.
 * Does NOT place live orders; does NOT touch live crons/state files.
 */
final class Sim1mEngine
{
    private const FEE_BPS = 2.0; // ~0.02% per side (open + close); net session PnL includes both
    /** Ask Flash to re-pick leading method+TF every N ticks (~2m at 30s). */
    private const METHOD_PICK_EVERY_TICKS = 4;
    /** Signal TF: only 1m (DeepSeek picks method on this frame). */
    private const SIGNAL_TFS = ['1m'];

    /**
     * Net session PnL = realized (already net of paid fees) + unrealized mark.
     * Open fee is booked into realized on fill; close fee on exit.
     */
    private static function sessionNetPnl(float $realized, float $uPnl): float
    {
        return $realized + $uPnl;
    }

    private const METHOD_PICK_PROMPT = <<<'PROMPT'
Ты DeepSeek Flash. Выбери ОДИН ведущий метод для paper-сигналов LIT на таймфрейме 1m.
Ответь СТРОГО JSON без markdown:
{"method":"ROC(10) zero-cross","resolution":"1m","why":"кратко почему","confidence":0.0}
method — только из candidates.methods; resolution всегда "1m".
PROMPT;

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public static function start(Client $client, IndicatorConfig $cfg, array $opts = []): array
    {
        $marketId = (int) ($opts['market_id'] ?? 120);
        $details = $client->getMany([
            'd' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
        ]);
        $sym = (string) (($details['d']['order_book_details'][0]['symbol'] ?? null) ?: 'LIT');

        $start = Sim1mStore::start($cfg->dbPath(), [
            'market_id' => $marketId,
            'symbol' => $sym,
            'resolution' => '1m',
            // Placeholder until Flash picks method on first tick
            'method' => 'ROC(10) zero-cross',
            'lot_usd' => (float) ($opts['lot_usd'] ?? 200),
            'tick_sec' => (int) ($opts['tick_sec'] ?? 30),
            'tp_pct' => (float) ($opts['tp_pct'] ?? 50),
            'sl_pct' => (float) ($opts['sl_pct'] ?? 30),
            'tp_levels' => $opts['tp_levels'] ?? [50],
            'sl_levels' => $opts['sl_levels'] ?? [30],
            'kill_lo' => (float) ($opts['kill_lo'] ?? -50),
            'kill_hi' => (float) ($opts['kill_hi'] ?? 100),
            'method_source' => 'deepseek-flash',
        ]);
        if (empty($start['ok'])) {
            return $start;
        }

        // first tick immediately (includes Flash method pick)
        $tick = self::tick($client, $cfg, (int) $start['session_id']);
        $loop = self::spawnLoop((int) $start['session_id']);

        return [
            'ok' => true,
            'session_id' => $start['session_id'],
            'started_at' => $start['started_at'] ?? null,
            'first_tick' => $tick,
            'method' => $tick['tick']['method'] ?? null,
            'resolution' => $tick['tick']['resolution'] ?? null,
            'method_pick' => $tick['method_pick'] ?? null,
            'loop' => $loop,
        ];
    }

    /**
     * Stop session: close open paper position at mark, then mark stopped + kill loop.
     *
     * @return array<string, mixed>
     */
    public static function stop(Client $client, IndicatorConfig $cfg, ?int $sessionId = null): array
    {
        $db = $cfg->dbPath();
        $wrap = Sim1mStore::session($db, $sessionId);
        $sess = $wrap['session'] ?? null;
        $closed = null;

        if (is_array($sess) && ($sess['status'] ?? '') === 'running' && !empty($sess['position_side'])) {
            $closed = self::closeOpenPositionAtMark($client, $cfg, $sess, 'stop');
        }

        $out = Sim1mStore::stop($db, $sessionId);
        $out['loop'] = self::stopLoop();
        if ($closed !== null) {
            $out['closed_position'] = $closed;
        }

        return $out;
    }

    /**
     * Resume UI after reload: attach to running session and ensure background loop.
     *
     * @return array<string, mixed>
     */
    public static function resume(IndicatorConfig $cfg, ?int $sessionId = null): array
    {
        $status = Sim1mStore::status($cfg->dbPath(), $sessionId);
        $sess = $status['session'] ?? null;
        if (!is_array($sess) || ($sess['status'] ?? '') !== 'running') {
            return [
                'ok' => true,
                'resumed' => false,
                'reason' => 'no running session',
                'session' => $sess,
                'ticks' => $status['ticks'] ?? [],
                'trades' => $status['trades'] ?? [],
                'logs' => $status['logs'] ?? [],
            ];
        }
        $sid = (int) $sess['id'];
        $loop = self::ensureLoop($sid);

        return [
            'ok' => true,
            'resumed' => true,
            'session_id' => $sid,
            'session' => $sess,
            'ticks' => $status['ticks'] ?? [],
            'trades' => $status['trades'] ?? [],
            'logs' => $status['logs'] ?? [],
            'loop' => $loop,
        ];
    }

    /**
     * Open positions to optionally include in paper session after reload.
     *
     * @return array<string, mixed>
     */
    public static function candidatePositions(Client $client, IndicatorConfig $cfg, ?int $sessionId = null): array
    {
        $candidates = [];
        $wrap = Sim1mStore::session($cfg->dbPath(), $sessionId);
        $sess = $wrap['session'] ?? null;

        $liveErr = null;
        $liveByMarket = [];
        try {
            $idx = self::lighterAccountIndex();
            if ($idx !== null) {
                $acc = $client->account($idx);
                $accounts = $acc['accounts'] ?? [];
                if (!is_array($accounts) || $accounts === []) {
                    // some responses nest differently
                    $accounts = isset($acc['positions']) ? [$acc] : [];
                }
                foreach ($accounts as $a) {
                    $positions = $a['positions'] ?? [];
                    if (!is_array($positions)) {
                        continue;
                    }
                    foreach ($positions as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $size = (float) ($p['position'] ?? 0);
                        $mid = (int) ($p['market_id'] ?? 0);
                        $liveByMarket[$mid] = abs($size);
                        if (abs($size) < 1e-12) {
                            continue;
                        }
                        $sign = (int) ($p['sign'] ?? 0);
                        $side = $sign > 0 ? 'long' : ($sign < 0 ? 'short' : (string) ($p['side'] ?? ''));
                        if ($side !== 'long' && $side !== 'short') {
                            continue;
                        }
                        $sym = (string) ($p['symbol'] ?? ('m' . $mid));
                        $entry = isset($p['avg_entry_price']) ? (float) $p['avg_entry_price'] : null;
                        $candidates[] = [
                            'id' => 'live:' . $mid,
                            'source' => 'live',
                            'market_id' => $mid,
                            'symbol' => $sym,
                            'side' => $side,
                            'size' => abs($size),
                            'entry' => $entry,
                            'u_pnl' => isset($p['unrealized_pnl']) ? (float) $p['unrealized_pnl'] : null,
                            'on_exchange' => true,
                            'label' => sprintf(
                                'live · %s %s size=%s entry=%s uPnL=%s',
                                $side,
                                $sym,
                                $size,
                                $entry ?? '—',
                                $p['unrealized_pnl'] ?? '—',
                            ),
                            // Prefer not auto-claiming exchange pos into paper (DeepSeek may own it).
                            'default_checked' => false,
                        ];
                    }
                }
            } else {
                $liveErr = 'LIGHTER_ACCOUNT_INDEX not set';
            }
        } catch (\Throwable $e) {
            $liveErr = $e->getMessage();
        }

        if (is_array($sess) && ($sess['status'] ?? '') === 'running' && !empty($sess['position_side'])) {
            $mid = (int) ($sess['market_id'] ?? 120);
            $onEx = ($liveByMarket[$mid] ?? 0.0) >= 1e-12;
            $candidates[] = [
                'id' => 'paper:' . (int) $sess['id'],
                'source' => 'paper',
                'session_id' => (int) $sess['id'],
                'market_id' => $mid,
                'symbol' => (string) ($sess['symbol'] ?? 'LIT'),
                'side' => (string) $sess['position_side'],
                'size' => isset($sess['position_size']) ? (float) $sess['position_size'] : null,
                'entry' => isset($sess['entry_price']) ? (float) $sess['entry_price'] : null,
                'u_pnl' => null,
                'on_exchange' => $onEx,
                'label' => sprintf(
                    'paper #%d · %s %s @ %s%s',
                    (int) $sess['id'],
                    $sess['position_side'],
                    $sess['symbol'] ?? 'LIT',
                    $sess['entry_price'] ?? '—',
                    $onEx ? '' : ' · нет на Lighter',
                ),
                'default_checked' => $onEx,
            ];
        }

        return [
            'ok' => true,
            'candidates' => $candidates,
            'session' => is_array($sess) ? [
                'id' => (int) ($sess['id'] ?? 0),
                'status' => $sess['status'] ?? null,
            ] : null,
            'live_error' => $liveErr,
        ];
    }

    /**
     * Apply user's choice: which open positions to keep/mirror in paper session.
     * Live exchange positions are never closed — only mirrored into paper or ignored.
     *
     * @param list<string> $selectedIds
     * @return array<string, mixed>
     */
    public static function adoptPositions(
        Client $client,
        IndicatorConfig $cfg,
        array $selectedIds,
        ?int $sessionId = null,
        array $startOpts = [],
    ): array {
        $selected = array_values(array_unique(array_map('strval', $selectedIds)));
        $cand = self::candidatePositions($client, $cfg, $sessionId);
        $byId = [];
        foreach ($cand['candidates'] ?? [] as $c) {
            if (is_array($c) && isset($c['id'])) {
                $byId[(string) $c['id']] = $c;
            }
        }

        $actions = [];
        $wrap = Sim1mStore::session($cfg->dbPath(), $sessionId);
        $sess = $wrap['session'] ?? null;
        $needSession = false;
        foreach ($selected as $id) {
            if (str_starts_with($id, 'live:')) {
                $needSession = true;
                break;
            }
        }
        if ($needSession && (!is_array($sess) || ($sess['status'] ?? '') !== 'running')) {
            $started = self::start($client, $cfg, $startOpts ?: ['market_id' => 120, 'lot_usd' => 200, 'tick_sec' => 30]);
            if (empty($started['ok'])) {
                return $started;
            }
            $sessionId = (int) $started['session_id'];
            $actions[] = ['start' => $started];
            $wrap = Sim1mStore::session($cfg->dbPath(), $sessionId);
            $sess = $wrap['session'] ?? null;
        }

        if (!is_array($sess) || ($sess['status'] ?? '') !== 'running') {
            // nothing to adopt into
            return [
                'ok' => true,
                'adopted' => false,
                'reason' => 'no running session and no live selection',
                'actions' => $actions,
                'candidates' => $cand['candidates'] ?? [],
            ];
        }
        $sid = (int) $sess['id'];

        $paperId = 'paper:' . $sid;
        $paperSelected = in_array($paperId, $selected, true);
        $liveSelected = null;
        foreach ($selected as $id) {
            if (str_starts_with($id, 'live:') && isset($byId[$id])) {
                $liveSelected = $byId[$id];
                break; // one paper slot
            }
        }

        // Prefer explicit live mirror over keeping old paper if both checked
        if ($liveSelected !== null) {
            $side = (string) $liveSelected['side'];
            $entry = (float) ($liveSelected['entry'] ?? 0);
            $size = (float) ($liveSelected['size'] ?? 0);
            $lot = (float) ($sess['lot_usd'] ?? 200);
            $tpPct = (float) ($sess['tp_pct'] ?? 50);
            $slPct = (float) ($sess['sl_pct'] ?? 30);
            $tp = $entry > 0 ? ($side === 'long' ? $entry * (1 + $tpPct / 100) : $entry * (1 - $tpPct / 100)) : null;
            $sl = $entry > 0 ? ($side === 'long' ? $entry * (1 - $slPct / 100) : $entry * (1 + $slPct / 100)) : null;
            $set = Sim1mStore::setPosition($cfg->dbPath(), [
                'side' => $side,
                'size' => $size,
                'entry' => $entry,
                'tp' => $tp,
                'sl' => $sl,
                'reason' => 'adopt live ' . ($liveSelected['symbol'] ?? ''),
            ], $sid);
            $actions[] = ['adopt_live' => $set, 'from' => $liveSelected['id']];
        } elseif (!empty($sess['position_side']) && !$paperSelected) {
            // user declined paper — flatten paper at mark (does not touch live)
            $closed = self::closeOpenPositionAtMark($client, $cfg, $sess, 'adopt_skip_paper');
            $actions[] = ['close_paper' => $closed];
        } elseif ($paperSelected) {
            $actions[] = ['keep_paper' => true, 'id' => $paperId];
        } elseif (empty($sess['position_side'])) {
            $actions[] = ['flat' => true];
        }

        self::ensureLoop($sid);
        $status = Sim1mStore::status($cfg->dbPath(), $sid);

        return [
            'ok' => true,
            'adopted' => true,
            'session_id' => $sid,
            'selected' => $selected,
            'actions' => $actions,
            'session' => $status['session'] ?? null,
            'ticks' => $status['ticks'] ?? [],
            'trades' => $status['trades'] ?? [],
            'logs' => $status['logs'] ?? [],
        ];
    }

    private static function lighterAccountIndex(): ?int
    {
        $root = dirname(__DIR__, 2);
        $envPath = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($envPath)) {
            return null;
        }
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#') || !str_contains($trim, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $trim, 2);
            if (trim($k) === 'LIGHTER_ACCOUNT_INDEX') {
                $n = (int) trim($v, " \t\"'");

                return $n > 0 ? $n : null;
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, pid?: int|null, spawned?: bool, alive?: bool}
     */
    public static function ensureLoop(int $sessionId): array
    {
        $root = dirname(__DIR__, 2);
        $pidFile = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '_sim_1m_loop.pid';
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0 && self::isPidAlive($pid)) {
                return ['ok' => true, 'pid' => $pid, 'spawned' => false, 'alive' => true];
            }
            @unlink($pidFile);
        }
        $spawned = self::spawnLoop($sessionId);

        return [
            'ok' => (bool) ($spawned['ok'] ?? false),
            'pid' => $spawned['pid'] ?? null,
            'spawned' => true,
            'alive' => !empty($spawned['pid']),
            'error' => $spawned['error'] ?? null,
        ];
    }

    private static function isPidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $out = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
            $text = implode(' ', $out);

            return str_contains($text, (string) $pid);
        }

        return function_exists('posix_kill') ? @posix_kill($pid, 0) : false;
    }

    /**
     * Flatten open paper position at mark price, persist trade+session update.
     *
     * @param array<string, mixed> $sess
     * @return array<string, mixed>
     */
    private static function closeOpenPositionAtMark(
        Client $client,
        IndicatorConfig $cfg,
        array $sess,
        string $reason,
    ): array {
        $marketId = (int) ($sess['market_id'] ?? 120);
        $bundle = $client->getMany([
            'book' => ['/api/v1/orderBookOrders', ['market_id' => $marketId, 'limit' => 5]],
            'sig' => $client->candlesRequest($marketId, '1m', countBack: 2),
        ]);
        $bid = isset($bundle['book']['bids'][0]['price']) ? (float) $bundle['book']['bids'][0]['price'] : null;
        $ask = isset($bundle['book']['asks'][0]['price']) ? (float) $bundle['book']['asks'][0]['price'] : null;
        $candles = $bundle['sig']['c'] ?? [];
        $lastC = is_array($candles) && $candles !== [] ? (float) ($candles[count($candles) - 1]['c'] ?? 0) : 0.0;
        $side = (string) $sess['position_side'];
        $price = $lastC;
        if ($side === 'long' && $bid) {
            $price = $bid;
        } elseif ($side === 'short' && $ask) {
            $price = $ask;
        } elseif ($bid && $ask) {
            $price = ($bid + $ask) / 2.0;
        }
        if ($price <= 0) {
            return ['ok' => false, 'error' => 'no mark price to close'];
        }

        $lot = (float) ($sess['lot_usd'] ?? 200);
        $size = $sess['position_size'] !== null ? (float) $sess['position_size'] : 0.0;
        $entry = $sess['entry_price'] !== null ? (float) $sess['entry_price'] : $price;
        $realized = (float) ($sess['realized_pnl'] ?? 0);
        $fees = (float) ($sess['fees'] ?? 0);
        $tradesCount = (int) ($sess['trades_count'] ?? 0);
        $barTs = (int) ($sess['last_bar_ts'] ?? (time() * 1000));
        $now = time();

        [$realized, $fees, $tradesCount, $tr] = self::closePaper(
            $side,
            $price,
            $size,
            $entry,
            $lot,
            $realized,
            $fees,
            $barTs,
            $reason,
            $tradesCount,
        );

        $saved = Sim1mStore::saveTick($cfg->dbPath(), [
            'session' => [
                'id' => (int) $sess['id'],
                'session_pnl' => round($realized, 4),
                'realized_pnl' => round($realized, 4),
                'fees' => round($fees, 4),
                'position_side' => null,
                'position_size' => null,
                'entry_price' => null,
                'entry_ts' => null,
                'tp_price' => null,
                'sl_price' => null,
                'ticks_count' => (int) ($sess['ticks_count'] ?? 0) + 1,
                'trades_count' => $tradesCount,
                'last_bar_ts' => $barTs,
                'last_action' => 'stop_close',
                'last_reason' => $reason,
                'status' => 'running',
                'stopped_at' => null,
            ],
            'tick' => [
                'bar_ts' => $barTs,
                'price' => $price,
                'bid' => $bid,
                'ask' => $ask,
                'method' => $sess['method'] ?? null,
                'resolution' => $sess['resolution'] ?? '1m',
                'action' => 'stop_close',
                'reason' => $reason,
                'position_side' => null,
                'u_pnl' => 0.0,
                'session_pnl' => round($realized, 4),
                'payload' => ['emulation' => true, 'stop_close' => true],
            ],
            'trades' => [$tr],
            'logs' => [[
                'level' => 'info',
                'message' => sprintf('stop: закрыта paper %s @ %.6f pnl=%.4f', $side, $price, $tr['pnl'] ?? 0),
            ]],
        ]);

        return [
            'ok' => (bool) ($saved['ok'] ?? false),
            'side' => $side,
            'price' => $price,
            'pnl' => $tr['pnl'] ?? null,
            'trade' => $tr,
            'saved' => $saved,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(IndicatorConfig $cfg, ?int $sessionId = null): array
    {
        return Sim1mStore::status($cfg->dbPath(), $sessionId);
    }

    /**
     * Switch leading method for running emulation; next ticks use it.
     *
     * @return array<string, mixed>
     */
    public static function setMethod(IndicatorConfig $cfg, string $method, ?int $sessionId = null): array
    {
        if (!in_array($method, TechnicalAnalysis::METHODS, true)) {
            return ['ok' => false, 'error' => 'unknown method'];
        }

        return Sim1mStore::setMethod($cfg->dbPath(), $method, $sessionId);
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public static function setLevels(IndicatorConfig $cfg, array $opts, ?int $sessionId = null): array
    {
        $db = $cfg->dbPath();
        $wrap = Sim1mStore::session($db, $sessionId);
        $sess = $wrap['session'] ?? null;

        $payload = [
            'tp_levels' => $opts['tp_levels'] ?? null,
            'sl_levels' => $opts['sl_levels'] ?? null,
            'lot_usd' => $opts['lot_usd'] ?? null,
            'tp_price' => $opts['tp_price'] ?? null,
            'sl_price' => $opts['sl_price'] ?? null,
        ];

        $inPos = is_array($sess)
            && ($sess['status'] ?? '') === 'running'
            && !empty($sess['position_side'])
            && (float) ($sess['entry_price'] ?? 0) > 0;

        $touchTpsl = array_key_exists('tp_levels', $opts) || array_key_exists('sl_levels', $opts);

        if ($inPos && $touchTpsl) {
            $side = (string) $sess['position_side'];
            $entry = (float) $sess['entry_price'];
            $tmp = $sess;
            $cfgJson = [];
            $raw = $sess['config_json'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $cfgJson = $decoded;
                }
            } elseif (is_array($raw)) {
                $cfgJson = $raw;
            }
            if (isset($opts['tp_levels'])) {
                $cfgJson['tp_levels'] = $opts['tp_levels'];
            }
            if (isset($opts['sl_levels'])) {
                $cfgJson['sl_levels'] = $opts['sl_levels'];
            }
            $tmp['config_json'] = $cfgJson;
            [$tpLevels, $slLevels] = self::levelsFromSession($tmp);
            $prices = self::levelPrices($side, $entry, $tpLevels, $slLevels);
            $payload['tp_price'] = $prices['tp'][0] ?? null;
            $payload['sl_price'] = $prices['sl'][0] ?? null;
            $payload['tp_levels'] = $tpLevels;
            $payload['sl_levels'] = $slLevels;
        }

        $out = Sim1mStore::setLevels($db, $payload, $sessionId ?? (is_array($sess) ? (int) $sess['id'] : null));
        if (!empty($out['ok']) && $inPos && $touchTpsl) {
            $out['tp_price'] = $payload['tp_price'];
            $out['sl_price'] = $payload['sl_price'];
            $out['applied_to_position'] = true;
        }

        if (!empty($opts['resize_lot']) && !empty($out['ok'])) {
            $mark = isset($opts['mark_price']) ? (float) $opts['mark_price'] : 0.0;
            $resized = self::resizeOpenLot($cfg, $sessionId ?? (is_array($sess) ? (int) $sess['id'] : null), $mark);
            $out['resize'] = $resized;
            if (!empty($resized['ok'])) {
                $out['lot_usd'] = $resized['lot_usd'] ?? null;
                $out['position_size'] = $resized['position_size'] ?? null;
            }
        }

        return $out;
    }

    /**
     * Paper resize open size to lot_usd at mark.
     *
     * @return array<string, mixed>
     */
    public static function resizeOpenLot(IndicatorConfig $cfg, ?int $sessionId, float $markPrice = 0.0): array
    {
        $db = $cfg->dbPath();
        $wrap = Sim1mStore::session($db, $sessionId);
        $sess = $wrap['session'] ?? null;
        if (!is_array($sess) || ($sess['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'no running session'];
        }
        if (empty($sess['position_side']) || (float) ($sess['entry_price'] ?? 0) <= 0) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'flat — lot saved for next entry'];
        }
        $side = (string) $sess['position_side'];
        $entry = (float) $sess['entry_price'];
        $curSize = (float) ($sess['position_size'] ?? 0);
        $lot = (float) ($sess['lot_usd'] ?? 0);
        if ($lot <= 0 || $curSize <= 0) {
            return ['ok' => false, 'error' => 'invalid lot/size'];
        }
        $mark = $markPrice > 0 ? $markPrice : $entry;
        $targetSize = $lot / $mark;
        $delta = $targetSize - $curSize;
        $deltaUsd = abs($delta) * $mark;
        if ($deltaUsd < 1.0) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'already near target lot',
                'lot_usd' => $lot,
                'position_size' => $curSize,
            ];
        }
        $fees = (float) ($sess['fees'] ?? 0);
        $realized = (float) ($sess['realized_pnl'] ?? 0);
        if ($delta > 0) {
            $fee = $deltaUsd * (self::FEE_BPS / 10000.0);
            $fees += $fee;
            $realized -= $fee;
            $newSize = $curSize + $delta;
        } else {
            $closeSize = abs($delta);
            $dir = $side === 'short' ? -1.0 : 1.0;
            $pnl = $entry > 0 ? (($mark - $entry) / $entry) * ($closeSize * $entry) * $dir : 0.0;
            $fee = ($closeSize * $entry) * (self::FEE_BPS / 10000.0);
            $fees += $fee;
            $realized += ($pnl - $fee);
            $newSize = max(0.0, $curSize - $closeSize);
        }
        [$tpLevels, $slLevels] = self::levelsFromSession($sess);
        $prices = self::levelPrices($side, $entry, $tpLevels, $slLevels);
        $tp = $prices['tp'][0] ?? (isset($sess['tp_price']) ? (float) $sess['tp_price'] : null);
        $sl = $prices['sl'][0] ?? (isset($sess['sl_price']) ? (float) $sess['sl_price'] : null);
        Sim1mStore::setPosition($db, [
            'side' => $side,
            'size' => $newSize,
            'entry' => $entry,
            'tp' => $tp,
            'sl' => $sl,
            'reason' => sprintf('resize lot → $%s @ mark %.4f', (string) (int) $lot, $mark),
        ], (int) $sess['id']);
        Sim1mStore::setLevels($db, [
            'lot_usd' => $lot,
            'tp_levels' => $tpLevels,
            'sl_levels' => $slLevels,
            'tp_price' => $tp,
            'sl_price' => $sl,
        ], (int) $sess['id']);

        return [
            'ok' => true,
            'lot_usd' => $lot,
            'position_size' => $newSize,
            'delta_usd' => round($delta > 0 ? $deltaUsd : -$deltaUsd, 4),
            'mark' => $mark,
            'fees' => round($fees, 4),
            'realized_pnl' => round($realized, 4),
        ];
    }

    /**
     * @param array<string, mixed> $sess
     * @return array{0: list<float>, 1: list<float>}
     */
    private static function levelsFromSession(array $sess): array
    {
        $cfg = [];
        $raw = $sess['config_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        } elseif (is_array($raw)) {
            $cfg = $raw;
        }
        $tp = self::normalizePctList($cfg['tp_levels'] ?? null, [(float) ($sess['tp_pct'] ?? 50)]);
        $sl = self::normalizePctList($cfg['sl_levels'] ?? null, [(float) ($sess['sl_pct'] ?? 30)]);

        return [$tp, $sl];
    }

    /**
     * @param mixed $raw
     * @param list<float> $fallback
     * @return list<float>
     */
    private static function normalizePctList(mixed $raw, array $fallback): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return $fallback;
        }
        $out = [];
        foreach ($raw as $x) {
            $v = (float) $x;
            if ($v >= 0.5 && $v <= 500 && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        sort($out);

        return $out !== [] ? $out : $fallback;
    }

    /**
     * @param list<float> $tpLevels
     * @param list<float> $slLevels
     * @return array{tp: list<float>, sl: list<float>}
     */
    private static function levelPrices(string $side, float $entry, array $tpLevels, array $slLevels): array
    {
        $tps = [];
        $sls = [];
        foreach ($tpLevels as $pct) {
            $frac = ((float) $pct) / 100.0;
            $tps[] = $side === 'long' ? $entry * (1 + $frac) : $entry * (1 - $frac);
        }
        foreach ($slLevels as $pct) {
            $frac = ((float) $pct) / 100.0;
            $sls[] = $side === 'long' ? $entry * (1 - $frac) : $entry * (1 + $frac);
        }
        if ($side === 'long') {
            sort($tps);
            rsort($sls);
        } else {
            rsort($tps);
            sort($sls);
        }

        return ['tp' => $tps, 'sl' => $sls];
    }

    /**
     * One emulation tick. Safe to call from UI every ~tick_sec.
     *
     * @return array<string, mixed>
     */
    public static function tick(Client $client, IndicatorConfig $cfg, ?int $sessionId = null): array
    {
        $db = $cfg->dbPath();
        $wrap = Sim1mStore::session($db, $sessionId);
        $sess = $wrap['session'] ?? null;
        if (!is_array($sess) || ($sess['status'] ?? '') !== 'running') {
            return ['ok' => false, 'error' => 'no running emulation session', 'skipped' => true];
        }

        $tickSec = max(10, (int) ($sess['tick_sec'] ?? 30));
        $lastTick = (int) ($sess['last_tick_at'] ?? 0);
        $now = time();
        if ($lastTick > 0 && ($now - $lastTick) < max(8, (int) floor($tickSec * 0.6))) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'tick too soon',
                'session_id' => (int) $sess['id'],
                'wait_sec' => max(0, $tickSec - ($now - $lastTick)),
            ];
        }

        $marketId = (int) ($sess['market_id'] ?? 120);
        $method = (string) ($sess['method'] ?? 'ROC(10) zero-cross');
        $resolution = (string) ($sess['resolution'] ?? '1m');
        if (!in_array($resolution, self::SIGNAL_TFS, true)) {
            $resolution = '1m';
        }
        $lot = (float) ($sess['lot_usd'] ?? 200);
        [$tpLevels, $slLevels] = self::levelsFromSession($sess);
        $tpPct = (float) min($tpLevels);
        $slPct = (float) min($slLevels);
        $killLo = (float) ($sess['kill_lo'] ?? -50);
        $killHi = (float) ($sess['kill_hi'] ?? 100);

        $ticksCountPreview = (int) ($sess['ticks_count'] ?? 0) + 1;
        $needPick = ($ticksCountPreview === 1)
            || ($ticksCountPreview % self::METHOD_PICK_EVERY_TICKS === 1);

        $t0 = microtime(true);
        $requests = [
            'details' => ['/api/v1/orderBookDetails', ['market_id' => $marketId]],
            'book' => ['/api/v1/orderBookOrders', ['market_id' => $marketId, 'limit' => 15]],
            'sig' => $client->candlesRequest($marketId, $resolution, countBack: 400),
        ];
        if ($needPick) {
            foreach (self::SIGNAL_TFS as $tf) {
                $requests['c_' . $tf] = $client->candlesRequest($marketId, $tf, countBack: 400);
            }
        }
        $bundle = $client->getMany($requests);
        $fetchMs = (microtime(true) - $t0) * 1000;

        $trades = [];
        $logs = [];
        $methodPick = null;
        if ($needPick) {
            $frames = [];
            foreach (self::SIGNAL_TFS as $tf) {
                $c = $bundle['c_' . $tf]['c'] ?? [];
                if (is_array($c) && count($c) >= 40) {
                    $frames[$tf] = $c;
                }
            }
            $methodPick = self::pickLeadWithFlash($cfg->dbPath(), $marketId, $frames, $method, $resolution);
            if (!empty($methodPick['ok']) && !empty($methodPick['method'])) {
                $pickedMethod = (string) $methodPick['method'];
                $pickedTf = (string) ($methodPick['resolution'] ?? $resolution);
                if (!in_array($pickedTf, self::SIGNAL_TFS, true)) {
                    $pickedTf = $resolution;
                }
                $changed = ($pickedMethod !== $method) || ($pickedTf !== $resolution);
                Sim1mStore::setLead($db, $pickedMethod, $pickedTf, (int) $sess['id']);
                $method = $pickedMethod;
                $resolution = $pickedTf;
                $logs[] = [
                    'level' => 'info',
                    'message' => ($changed ? 'DeepSeek Flash выбрал' : 'DeepSeek Flash подтвердил')
                        . ': ' . $method . ' @ ' . $resolution
                        . (!empty($methodPick['why']) ? (' — ' . $methodPick['why']) : ''),
                ];
                if (isset($frames[$resolution])) {
                    $bundle['sig'] = ['c' => $frames[$resolution], 'r' => $resolution];
                }
            } elseif (!empty($methodPick['error'])) {
                $logs[] = [
                    'level' => 'warn',
                    'message' => 'Flash lead pick failed: ' . $methodPick['error']
                        . ' — keep ' . $method . ' @ ' . $resolution,
                ];
            }
        }

        $candles = $bundle['sig']['c'] ?? [];
        if ((!is_array($candles) || count($candles) < 40) && isset($bundle['c_' . $resolution]['c'])) {
            $candles = $bundle['c_' . $resolution]['c'];
        }
        if (!is_array($candles) || count($candles) < 40) {
            return [
                'ok' => false,
                'error' => 'not enough candles for ' . $resolution,
                'session_id' => (int) $sess['id'],
            ];
        }

        $book = $bundle['book'] ?? [];
        $bid = isset($book['bids'][0]['price']) ? (float) $book['bids'][0]['price'] : null;
        $ask = isset($book['asks'][0]['price']) ? (float) $book['asks'][0]['price'] : null;
        $spreadBps = ($bid && $ask && $bid > 0) ? (($ask - $bid) / $bid) * 10000.0 : null;

        $all = TechnicalAnalysis::generateAllSignals($candles);
        $roc = $all['ROC(10) zero-cross'] ?? [];
        $sma = $all['SMA(10/30) cross'] ?? [];
        $meth = $all[$method] ?? $roc;
        $n = count($candles);
        $i = $n - 1;
        $prev = $n - 2;
        $rocSig = (int) ($roc[$i] ?? 0);
        $smaSig = (int) ($sma[$i] ?? 0);
        $methodSig = (int) ($meth[$i] ?? 0);
        $prevMethod = (int) ($meth[$prev] ?? 0);

        $last = $candles[$i];
        $price = (float) ($last['c'] ?? 0);
        $barTs = (int) ($last['t'] ?? 0);
        if ($barTs > 0 && $barTs < 1_000_000_000_000) {
            $barTs *= 1000;
        }

        $side = $sess['position_side'] ?? null;
        $size = $sess['position_size'] !== null ? (float) $sess['position_size'] : null;
        $entry = $sess['entry_price'] !== null ? (float) $sess['entry_price'] : null;
        $entryTs = $sess['entry_ts'] !== null ? (int) $sess['entry_ts'] : null;
        $tp = $sess['tp_price'] !== null ? (float) $sess['tp_price'] : null;
        $sl = $sess['sl_price'] !== null ? (float) $sess['sl_price'] : null;
        $realized = (float) ($sess['realized_pnl'] ?? 0);
        $fees = (float) ($sess['fees'] ?? 0);
        $ticksCount = $ticksCountPreview;
        $tradesCount = (int) ($sess['trades_count'] ?? 0);
        $status = 'running';
        $stoppedAt = null;

        $uPnl = 0.0;
        if ($side && $entry && $size && $price > 0) {
            $dir = $side === 'short' ? -1.0 : 1.0;
            $uPnl = ($price - $entry) / $entry * $lot * $dir;
        }
        $sessionPnl = self::sessionNetPnl($realized, $uPnl);

        $action = 'hold';
        $reason = 'no signal change';

        // Kill switch
        if ($sessionPnl <= $killLo || $sessionPnl >= $killHi) {
            if ($side) {
                [$realized, $fees, $tradesCount, $tr] = self::closePaper(
                    $side,
                    $price,
                    $size ?? 0.0,
                    $entry ?? $price,
                    $lot,
                    $realized,
                    $fees,
                    $barTs,
                    $sessionPnl <= $killLo ? 'kill_lo' : 'kill_hi',
                    $tradesCount,
                );
                $trades[] = $tr;
                $side = null;
                $size = null;
                $entry = null;
                $entryTs = null;
                $tp = null;
                $sl = null;
                $uPnl = 0.0;
                $sessionPnl = $realized;
            }
            $action = 'stop_kill';
            $reason = sprintf('session kill pnl=%.2f', $sessionPnl);
            $status = 'stopped';
            $stoppedAt = $now;
            $logs[] = ['level' => 'warn', 'message' => $reason];
        } elseif ($side && $tp !== null && $sl !== null) {
            // Soft exit uses TP/SL frozen at open (not pending next-entry levels)
            $hitTp = ($side === 'long' && $price >= $tp) || ($side === 'short' && $price <= $tp);
            $hitSl = ($side === 'long' && $price <= $sl) || ($side === 'short' && $price >= $sl);
            if ($hitTp || $hitSl) {
                $why = $hitTp ? 'tp' : 'sl';
                [$realized, $fees, $tradesCount, $tr] = self::closePaper(
                    $side,
                    $price,
                    $size ?? 0.0,
                    $entry ?? $price,
                    $lot,
                    $realized,
                    $fees,
                    $barTs,
                    $why,
                    $tradesCount,
                );
                $trades[] = $tr;
                $side = null;
                $size = null;
                $entry = null;
                $entryTs = null;
                $tp = null;
                $sl = null;
                $uPnl = 0.0;
                $sessionPnl = $realized;
                $action = $why;
                $reason = $why === 'tp' ? 'take profit hit' : 'stop loss hit';
            }
        }

        // Signal cross on method (only if still flat or need flip / hold)
        if ($status === 'running' && $action === 'hold') {
            $crossLong = $prevMethod <= 0 && $methodSig > 0;
            $crossShort = $prevMethod >= 0 && $methodSig < 0;
            $wideSpread = $spreadBps !== null && $spreadBps > 25.0;

            if ($wideSpread) {
                $action = 'skip_spread';
                $reason = sprintf('spread %.1f bps too wide', $spreadBps);
            } elseif ($crossLong) {
                if ($side === 'short') {
                    [$realized, $fees, $tradesCount, $tr] = self::closePaper(
                        $side, $price, $size ?? 0.0, $entry ?? $price, $lot, $realized, $fees, $barTs, 'flip_to_long', $tradesCount
                    );
                    $trades[] = $tr;
                }
                if ($side !== 'long') {
                    [$side, $size, $entry, $entryTs, $tp, $sl, $fees, $tradesCount, $tr] = self::openPaper(
                        'long', $price, $lot, $tpPct, $slPct, $fees, $barTs, $now, 'roc/method cross +1', $tradesCount
                    );
                    $trades[] = $tr;
                    $realized += (float) ($tr['pnl'] ?? 0); // includes −open fee
                    $uPnl = 0.0;
                    $sessionPnl = $realized;
                    $action = 'open_long';
                    $reason = 'method cross to long';
                }
            } elseif ($crossShort) {
                if ($side === 'long') {
                    [$realized, $fees, $tradesCount, $tr] = self::closePaper(
                        $side, $price, $size ?? 0.0, $entry ?? $price, $lot, $realized, $fees, $barTs, 'flip_to_short', $tradesCount
                    );
                    $trades[] = $tr;
                }
                if ($side !== 'short') {
                    [$side, $size, $entry, $entryTs, $tp, $sl, $fees, $tradesCount, $tr] = self::openPaper(
                        'short', $price, $lot, $tpPct, $slPct, $fees, $barTs, $now, 'roc/method cross -1', $tradesCount
                    );
                    $trades[] = $tr;
                    $realized += (float) ($tr['pnl'] ?? 0); // includes −open fee
                    $uPnl = 0.0;
                    $sessionPnl = $realized;
                    $action = 'open_short';
                    $reason = 'method cross to short';
                }
            } else {
                $action = $side ? 'hold_pos' : 'flat';
                $reason = $side ? 'in position, no exit' : 'no new cross';
            }
        }

        if ($side && $entry && $size && $price > 0) {
            $dir = $side === 'short' ? -1.0 : 1.0;
            $uPnl = ($price - $entry) / $entry * $lot * $dir;
            $sessionPnl = self::sessionNetPnl($realized, $uPnl);
        }

        $sessOut = [
            'id' => (int) $sess['id'],
            'method' => $method,
            'resolution' => $resolution,
            'session_pnl' => round($sessionPnl, 4),
            'realized_pnl' => round($realized, 4),
            'fees' => round($fees, 4),
            'position_side' => $side,
            'position_size' => $size,
            'entry_price' => $entry,
            'entry_ts' => $entryTs,
            'tp_price' => $tp,
            'sl_price' => $sl,
            'ticks_count' => $ticksCount,
            'trades_count' => $tradesCount,
            'last_bar_ts' => $barTs,
            'last_action' => $action,
            'last_reason' => $reason,
            'status' => $status,
            'stopped_at' => $stoppedAt,
        ];

        $tick = [
            'bar_ts' => $barTs,
            'price' => $price,
            'bid' => $bid,
            'ask' => $ask,
            'spread_bps' => $spreadBps !== null ? round($spreadBps, 2) : null,
            'method' => $method,
            'resolution' => $resolution,
            'roc_sig' => $rocSig,
            'sma_sig' => $smaSig,
            'method_sig' => $methodSig,
            'action' => $action,
            'reason' => $reason,
            'position_side' => $side,
            'u_pnl' => round($uPnl, 4),
            'session_pnl' => round($sessionPnl, 4),
            'payload' => [
                'fetch_ms' => round($fetchMs, 1),
                'method' => $method,
                'resolution' => $resolution,
                'method_source' => 'deepseek-flash',
                'method_pick' => $methodPick,
                'prev_method_sig' => $prevMethod,
                'candles' => count($candles),
                'emulation' => true,
            ],
        ];

        $saved = Sim1mStore::saveTick($db, [
            'session' => $sessOut,
            'tick' => $tick,
            'trades' => $trades,
            'logs' => $logs,
        ]);

        return [
            'ok' => (bool) ($saved['ok'] ?? false),
            'skipped' => false,
            'session_id' => (int) $sess['id'],
            'session' => $sessOut,
            'tick' => $tick,
            'trades' => $trades,
            'method_pick' => $methodPick,
            'saved' => $saved,
            'error' => $saved['error'] ?? null,
        ];
    }

    /**
     * @return array{ok: bool, pid?: int|null, error?: string}
     */
    public static function spawnLoop(int $sessionId): array
    {
        self::stopLoop();
        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'sim_1m_loop.php';
        $log = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '_sim_1m_loop.log';
        if (!is_file($script)) {
            return ['ok' => false, 'error' => 'sim_1m_loop.php missing'];
        }
        $php = PHP_BINARY ?: 'php';
        // `--` required so PHP CLI does not treat --session-id as its own option.
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' -f ' . escapeshellarg($script)
                . ' -- --session-id=' . (int) $sessionId
                . ' >> ' . escapeshellarg($log) . ' 2>&1';
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = escapeshellarg($php) . ' -f ' . escapeshellarg($script)
                . ' -- --session-id=' . (int) $sessionId
                . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
            exec($cmd);
        }
        usleep(300000);
        $pidFile = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '_sim_1m_loop.pid';
        $pid = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : null;

        return ['ok' => true, 'pid' => $pid ?: null];
    }

    /**
     * @return array{ok: bool, stopped?: bool}
     */
    public static function stopLoop(): array
    {
        $root = dirname(__DIR__, 2);
        $pidFile = $root . DIRECTORY_SEPARATOR . 'mcp-lighter' . DIRECTORY_SEPARATOR . '_sim_1m_loop.pid';
        if (!is_file($pidFile)) {
            return ['ok' => true, 'stopped' => false];
        }
        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid > 0) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec('taskkill /PID ' . $pid . ' /F 2>NUL');
            } else {
                posix_kill($pid, 15);
            }
        }
        @unlink($pidFile);

        return ['ok' => true, 'stopped' => true, 'pid' => $pid];
    }

    /**
     * Ask DeepSeek Flash to pick leading method + TF (≤30m).
     *
     * @param array<string, list<array<string, mixed>>> $framesByTf
     * @return array<string, mixed>
     */
    private static function pickLeadWithFlash(
        string $dbPath,
        int $marketId,
        array $framesByTf,
        string $currentMethod,
        string $currentTf,
    ): array {
        $rank = [];
        foreach ($framesByTf as $tf => $candles) {
            $stats = IndicatorSnapshot::computeStats($candles, TechnicalAnalysis::METHODS);
            foreach ($stats as $st) {
                $name = (string) ($st['indicator'] ?? '');
                if ($name === '' || !in_array($name, TechnicalAnalysis::METHODS, true)) {
                    continue;
                }
                $rank[] = [
                    'resolution' => $tf,
                    'method' => $name,
                    'r' => $st['strategy_return_pct'] ?? null,
                    'pf' => $st['profit_factor'] ?? null,
                    'a' => $st['accuracy'] ?? null,
                    'n' => $st['signals'] ?? 0,
                    'ls' => $st['last_signal'] ?? null,
                ];
            }
        }
        usort($rank, static function ($x, $y) {
            $ax = $x['a'];
            $ay = $y['a'];
            if ($ax === $ay) {
                $rx = $x['r'];
                $ry = $y['r'];
                if ($rx === null && $ry === null) {
                    return 0;
                }
                if ($rx === null) {
                    return 1;
                }
                if ($ry === null) {
                    return -1;
                }

                return $ry <=> $rx;
            }
            if ($ax === null) {
                return 1;
            }
            if ($ay === null) {
                return -1;
            }

            return $ay <=> $ax;
        });

        $payload = [
            'market_id' => $marketId,
            'current' => ['method' => $currentMethod, 'resolution' => $currentTf],
            'candidates' => [
                'methods' => TechnicalAnalysis::METHODS,
                'resolutions' => self::SIGNAL_TFS,
            ],
            'rank_top' => array_slice($rank, 0, 12),
        ];

        try {
            $ds = new DeepSeekClient(DeepSeekClient::modelFor('analyze'), $dbPath, [
                'purpose' => 'sim_1m_lead_pick',
                'market_id' => $marketId,
                'meta' => ['current_method' => $currentMethod, 'current_tf' => $currentTf],
            ]);
            $reply = $ds->chat([
                ['role' => 'system', 'content' => self::METHOD_PICK_PROMPT],
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'],
            ], 500, true);
            $content = trim((string) ($reply['content'] ?? ''));
            $parsed = json_decode($content, true);
            if (!is_array($parsed) && preg_match('/\{.*\}/s', $content, $m)) {
                $parsed = json_decode($m[0], true);
            }
            if (!is_array($parsed)) {
                return ['ok' => false, 'error' => 'bad flash json', 'raw' => mb_substr($content, 0, 200)];
            }
            $method = (string) ($parsed['method'] ?? '');
            $resolution = (string) ($parsed['resolution'] ?? '');
            if (!in_array($method, TechnicalAnalysis::METHODS, true)) {
                return ['ok' => false, 'error' => 'flash method not allowed: ' . $method, 'raw' => $parsed];
            }
            if (!in_array($resolution, self::SIGNAL_TFS, true)) {
                return ['ok' => false, 'error' => 'flash resolution not allowed: ' . $resolution, 'raw' => $parsed];
            }

            return [
                'ok' => true,
                'method' => $method,
                'resolution' => $resolution,
                'why' => (string) ($parsed['why'] ?? ''),
                'confidence' => isset($parsed['confidence']) ? (float) $parsed['confidence'] : null,
                'model' => $reply['model'] ?? null,
                'query_id' => $reply['query_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{0:string,1:float,2:float,3:int,4:float,5:float,6:float,7:int,8:array<string,mixed>}
     */
    private static function openPaper(
        string $side,
        float $price,
        float $lot,
        float $tpPct,
        float $slPct,
        float $fees,
        int $barTs,
        int $now,
        string $reason,
        int $tradesCount,
    ): array {
        $size = $price > 0 ? $lot / $price : 0.0;
        $fee = $lot * (self::FEE_BPS / 10000.0);
        $fees += $fee;
        $tpFrac = $tpPct / 100.0;
        $slFrac = $slPct / 100.0;
        if ($side === 'long') {
            $tp = $price * (1 + $tpFrac);
            $sl = $price * (1 - $slFrac);
        } else {
            $tp = $price * (1 - $tpFrac);
            $sl = $price * (1 + $slFrac);
        }
        $tr = [
            'bar_ts' => $barTs,
            'side' => $side,
            'action' => 'open',
            'price' => $price,
            'size' => $size,
            'quote_usd' => $lot,
            // Open: fee hits net PnL immediately (session = realized + uPnL).
            'pnl' => round(-$fee, 4),
            'fees' => $fee,
            'reason' => $reason,
        ];

        return [$side, $size, $price, $barTs > 0 ? $barTs : $now * 1000, $tp, $sl, $fees, $tradesCount + 1, $tr];
    }

    /**
     * @return array{0:float,1:float,2:int,3:array<string,mixed>}
     */
    private static function closePaper(
        string $side,
        float $price,
        float $size,
        float $entry,
        float $lot,
        float $realized,
        float $fees,
        int $barTs,
        string $reason,
        int $tradesCount,
    ): array {
        $dir = $side === 'short' ? -1.0 : 1.0;
        $pnl = $entry > 0 ? (($price - $entry) / $entry) * $lot * $dir : 0.0;
        $fee = $lot * (self::FEE_BPS / 10000.0);
        $fees += $fee;
        $realized += ($pnl - $fee);
        $tr = [
            'bar_ts' => $barTs,
            'side' => $side,
            'action' => 'close',
            'price' => $price,
            'size' => $size,
            'quote_usd' => $lot,
            'pnl' => round($pnl - $fee, 4),
            'fees' => $fee,
            'reason' => $reason,
        ];

        return [$realized, $fees, $tradesCount + 1, $tr];
    }
}
