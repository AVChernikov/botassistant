<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\Client;
use Lighter\Exception\ApiException;
use Lighter\TechnicalAnalysis;

$marketId = 1;
$resolutions = ['1d', '4h', '1h', '30m', '15m', '5m', '1m'];
$candleCount = 200;
$horizon = 1;
$error = null;
$report = null;
$loaded = [];
$frames = [];
$topCharts = [];

try {
    $client = Client::mainnet(45);
    $requests = [];
    foreach ($resolutions as $resolution) {
        $requests[$resolution] = $client->candlesRequest(
            $marketId,
            $resolution,
            countBack: $candleCount,
        );
    }
    $responses = $client->getMany($requests);
    foreach ($resolutions as $resolution) {
        $resp = $responses[$resolution];
        $items = $resp['c'] ?? [];
        $frames[$resolution] = $items;
        $loaded[$resolution] = count($items);
    }
    $report = TechnicalAnalysis::runReport($frames, $horizon);
    $topCharts = TechnicalAnalysis::topChartBundles($frames, $report['results'], 5);
} catch (ApiException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function pct(?float $v, int $digits = 2): string
{
    if ($v === null) {
        return '—';
    }

    return number_format($v * 100, $digits) . '%';
}

function num(?float $v, int $digits = 2): string
{
    if ($v === null) {
        return '—';
    }

    return number_format($v, $digits);
}

function methodLiveLink(string $method, string $resolution): string
{
    $href = 'live.php?' . http_build_query([
        'method' => $method,
        'resolution' => $resolution,
        'market_id' => 1,
    ]);

    return '<a class="method-link" href="' . htmlspecialchars($href, ENT_QUOTES)
        . '" target="_blank" rel="noopener noreferrer">'
        . htmlspecialchars($method, ENT_QUOTES) . '</a>';
}

$best = $report['best'] ?? null;
$results = $report['results'] ?? [];

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Математический анализ · BTC #1</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #eef2ef;
      --bg-2: #e4ebe5;
      --ink: #152019;
      --muted: #5c6b61;
      --line: #c7d2cb;
      --panel: #f7faf7;
      --accent: #0f6b4c;
      --ask: #b42318;
      --shadow: 0 18px 50px rgba(21, 32, 25, 0.08);
      --radius: 18px;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      color: var(--ink);
      font-family: Manrope, sans-serif;
      background:
        radial-gradient(1100px 520px at 8% -10%, #d9ebe0 0%, transparent 55%),
        radial-gradient(900px 480px at 100% 0%, #f3e2d4 0%, transparent 50%),
        linear-gradient(180deg, var(--bg), var(--bg-2));
    }
    .wrap {
      width: min(1180px, calc(100% - 2rem));
      margin: 0 auto;
      padding: 2rem 0 3rem;
    }
    .brand {
      font-size: clamp(1.7rem, 3.5vw, 2.4rem);
      font-weight: 700;
      letter-spacing: -0.04em;
    }
    .brand span { color: var(--accent); }
    .subtitle { color: var(--muted); margin: 0.45rem 0 1.25rem; }
    .nav a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1.1rem 1.2rem;
      margin-bottom: 1rem;
    }
    .panel h2 { margin: 0 0 0.75rem; font-size: 1.05rem; }
    .winner {
      border-color: #9ecbb8;
      background: linear-gradient(180deg, #f3faf6, #f7faf7);
    }
    .winner .big {
      font-size: 1.35rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      margin: 0.2rem 0 0.55rem;
    }
    .meta {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 0.7rem;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.82rem;
    }
    .meta div {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 12px;
      padding: 0.65rem 0.75rem;
    }
    .meta span { display: block; color: var(--muted); margin-bottom: 0.2rem; font-family: Manrope, sans-serif; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.78rem;
    }
    th, td {
      padding: 0.45rem 0.35rem;
      border-bottom: 1px solid var(--line);
      text-align: right;
    }
    th:first-child, td:first-child,
    th:nth-child(2), td:nth-child(2) { text-align: left; }
    th { color: var(--muted); font-weight: 500; }
    tr.top td { background: rgba(15, 107, 76, 0.08); font-weight: 500; }
    .good { color: var(--accent); }
    .bad { color: var(--ask); }
    .note { color: var(--muted); font-size: 0.9rem; line-height: 1.45; }
    .error { color: var(--ask); font-weight: 600; }
    .scroll { overflow: auto; }
    .method-link {
      color: var(--accent);
      font-weight: 600;
      text-decoration: none;
      border-bottom: 1px solid rgba(15, 107, 76, 0.35);
    }
    .method-link:hover { border-bottom-color: var(--accent); }
    .chart-block {
      margin-top: 0.85rem;
      padding-top: 0.85rem;
      border-top: 1px dashed var(--line);
    }
    .chart-block:first-child { margin-top: 0; padding-top: 0; border-top: 0; }
    .chart-block h3 {
      margin: 0 0 0.55rem;
      font-size: 0.98rem;
    }
    .chart-meta {
      color: var(--muted);
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.78rem;
      margin: 0 0 0.65rem;
    }
    .chart-wrap {
      position: relative;
      width: 100%;
      height: 260px;
    }
    .chart-wrap.indicator { height: 170px; margin-top: 0.55rem; }
    .chart-wrap canvas {
      width: 100%;
      height: 100%;
      display: block;
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:end">
      <div>
        <div class="brand">Проверка мат. анализа <span>· BTC #1</span></div>
        <p class="subtitle">Mainnet perp · все ТФ · сравнение методов по точности сигналов</p>
      </div>
      <div class="nav"><a href="btc.php">← BTC анализ</a></div>
    </div>

    <?php if ($error !== null): ?>
      <div class="panel"><p class="error">Ошибка: <?= htmlspecialchars($error, ENT_QUOTES) ?></p></div>
    <?php else: ?>
      <div class="panel">
        <h2>Данные</h2>
        <p class="note">
          Загружено свечей:
          <?php foreach ($loaded as $tf => $count): ?>
            <strong><?= htmlspecialchars($tf) ?></strong>=<?= (int) $count ?><?= $tf === array_key_last($loaded) ? '' : ',' ?>
          <?php endforeach; ?>
          · горизонт проверки = <?= (int) $horizon ?> бар после сигнала · комиссия/проскальзывание не учтены.
        </p>
      </div>

      <?php if ($best === null): ?>
        <div class="panel"><p class="error">Недостаточно сигналов для отчёта.</p></div>
      <?php else: ?>
        <div class="panel winner">
          <h2>Наиболее точный результат</h2>
          <div class="big">
            <?= methodLiveLink((string) $best['method'], (string) $best['resolution']) ?>
            на интервале
            <?= htmlspecialchars((string) $best['resolution']) ?>
          </div>
          <div class="meta">
            <div><span>Точность</span><strong class="good"><?= pct($best['accuracy']) ?></strong></div>
            <div><span>Сигналов</span><strong><?= (int) $best['signals'] ?></strong></div>
            <div><span>Верных</span><strong><?= (int) $best['correct'] ?></strong></div>
            <div><span>Score</span><strong><?= num($best['score'], 4) ?></strong></div>
            <div><span>Доходность стратегии</span><strong class="<?= ($best['strategy_return_pct'] ?? 0) >= 0 ? 'good' : 'bad' ?>"><?= num($best['strategy_return_pct']) ?>%</strong></div>
            <div><span>Profit factor</span><strong><?= $best['profit_factor'] === null ? '—' : num($best['profit_factor'], 3) ?></strong></div>
            <div><span>Avg move</span><strong><?= num($best['avg_move_pct'], 3) ?>%</strong></div>
            <div><span>Свечей</span><strong><?= (int) $best['candles'] ?></strong></div>
          </div>
        </div>

        <div class="panel">
          <h2>Методика</h2>
          <p class="note">
            Для каждого метода на каждом ТФ генерируются long/short сигналы.
            Точность = доля случаев, когда цена через <?= (int) $horizon ?> бар двинулась в сторону сигнала.
            «Точн. last5» — то же, но только по последним 5 сигналам.
            Score = accuracy + бонус за число сигналов + вклад доходности простой стратегии (удержание позиции после сигнала).
            Методы: MACD cross, RSI 30/70, SMA 10/30, EMA 12/26, Bollinger bounce, ROC zero-cross, Momentum flip.
          </p>
        </div>

        <div class="panel">
          <h2>Лучший метод на каждом ТФ</h2>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>ТФ</th>
                  <th>Метод</th>
                  <th>Точность</th>
                  <th>Сигналы</th>
                  <th>Return %</th>
                  <th>Score</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($resolutions as $tf): ?>
                  <?php $row = $report['by_timeframe'][$tf] ?? null; ?>
                  <?php if ($row === null) continue; ?>
                  <tr>
                    <td><?= htmlspecialchars($tf) ?></td>
                    <td><?= methodLiveLink((string) $row['method'], (string) $row['resolution']) ?></td>
                    <td class="good"><?= pct($row['accuracy']) ?></td>
                    <td><?= (int) $row['signals'] ?></td>
                    <td class="<?= ($row['strategy_return_pct'] ?? 0) >= 0 ? 'good' : 'bad' ?>"><?= num($row['strategy_return_pct']) ?></td>
                    <td><?= num($row['score'], 4) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <h2>Лучший ТФ для каждого метода</h2>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>Метод</th>
                  <th>ТФ</th>
                  <th>Точность</th>
                  <th>Сигналы</th>
                  <th>Return %</th>
                  <th>Score</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report['by_method'] as $row): ?>
                  <tr>
                    <td><?= methodLiveLink((string) $row['method'], (string) $row['resolution']) ?></td>
                    <td><?= htmlspecialchars((string) $row['resolution']) ?></td>
                    <td class="good"><?= pct($row['accuracy']) ?></td>
                    <td><?= (int) $row['signals'] ?></td>
                    <td class="<?= ($row['strategy_return_pct'] ?? 0) >= 0 ? 'good' : 'bad' ?>"><?= num($row['strategy_return_pct']) ?></td>
                    <td><?= num($row['score'], 4) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <h2>Полный рейтинг (метод × интервал)</h2>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Метод</th>
                  <th>ТФ</th>
                  <th>Точность</th>
                  <th>Точн. last5</th>
                  <th>Сигналы</th>
                  <th>Верных</th>
                  <th>Return %</th>
                  <th>PF</th>
                  <th>Score</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $i => $row): ?>
                  <tr class="<?= $i === 0 ? 'top' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= methodLiveLink((string) $row['method'], (string) $row['resolution']) ?></td>
                    <td><?= htmlspecialchars((string) $row['resolution']) ?></td>
                    <td class="good"><?= pct($row['accuracy']) ?></td>
                    <td class="<?= ($row['accuracy_last5'] ?? 0) >= 0.5 ? 'good' : 'bad' ?>">
                      <?= pct($row['accuracy_last5'] ?? null) ?>
                      <?php if (($row['signals_last5'] ?? 0) > 0): ?>
                        <span style="color:var(--muted);font-size:0.72rem">(<?= (int) $row['correct_last5'] ?>/<?= (int) $row['signals_last5'] ?>)</span>
                      <?php endif; ?>
                    </td>
                    <td><?= (int) $row['signals'] ?></td>
                    <td><?= (int) $row['correct'] ?></td>
                    <td class="<?= ($row['strategy_return_pct'] ?? 0) >= 0 ? 'good' : 'bad' ?>"><?= num($row['strategy_return_pct']) ?></td>
                    <td><?= $row['profit_factor'] === null ? '—' : num($row['profit_factor'], 3) ?></td>
                    <td><?= num($row['score'], 4) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php if ($topCharts !== []): ?>
          <div class="panel">
            <h2>Топ-5 рейтинга · графики</h2>
            <p class="note">Для каждой позиции: свечной график ТФ и график значений индикатора.</p>
            <div id="topCharts"></div>
          </div>
          <script src="chart-crosshair.js?v=1"></script>
          <script>
            const TOP_CHARTS = <?= json_encode($topCharts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            function fmt(n, digits = 4) {
              if (n === null || n === undefined || n === '') return '—';
              const num = Number(n);
              if (!Number.isFinite(num)) return String(n);
              return num.toLocaleString('en-US', { maximumFractionDigits: digits });
            }

            function prepareCanvas(canvas) {
              const wrap = canvas.parentElement;
              const dpr = window.devicePixelRatio || 1;
              const cssW = wrap.clientWidth || 800;
              const cssH = wrap.clientHeight || 180;
              canvas.width = Math.floor(cssW * dpr);
              canvas.height = Math.floor(cssH * dpr);
              const ctx = canvas.getContext('2d');
              ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
              ctx.clearRect(0, 0, cssW, cssH);
              return { ctx, cssW, cssH };
            }

            function drawCandles(canvas, candles) {
              const { ctx, cssW, cssH } = prepareCanvas(canvas);
              if (!candles.length) return;
              const pad = { top: 12, right: 58, bottom: 28, left: 8 };
              const plotW = cssW - pad.left - pad.right;
              const plotH = cssH - pad.top - pad.bottom;
              let min = Infinity, max = -Infinity;
              for (const c of candles) {
                min = Math.min(min, Number(c.l));
                max = Math.max(max, Number(c.h));
              }
              const padY = (max - min) * 0.06 || 1;
              min -= padY; max += padY;
              const yScale = (p) => pad.top + ((max - p) / (max - min)) * plotH;
              const slot = plotW / candles.length;
              const bodyW = Math.max(1.5, Math.min(14, slot * 0.6));

              ctx.strokeStyle = '#d5ddd7';
              ctx.fillStyle = '#5c6b61';
              ctx.font = '11px "IBM Plex Mono", monospace';
              ctx.textAlign = 'left';
              for (let i = 0; i <= 4; i++) {
                const price = max - ((max - min) * i) / 4;
                const y = yScale(price);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(cssW - pad.right, y);
                ctx.stroke();
                ctx.fillText(fmt(price, 2), cssW - pad.right + 6, y + 4);
              }

              candles.forEach((c, i) => {
                const o = Number(c.o), h = Number(c.h), l = Number(c.l), cl = Number(c.c);
                const color = cl >= o ? '#0f6b4c' : '#b42318';
                const x = pad.left + slot * i + slot / 2;
                ctx.strokeStyle = color;
                ctx.fillStyle = color;
                ctx.lineWidth = 1.3;
                ctx.beginPath();
                ctx.moveTo(x, yScale(h));
                ctx.lineTo(x, yScale(l));
                ctx.stroke();
                const yO = yScale(o), yC = yScale(cl);
                ctx.fillRect(x - bodyW / 2, Math.min(yO, yC), bodyW, Math.max(1, Math.abs(yC - yO)));
              });
              if (window.ChartCrosshair) {
                ChartCrosshair.mark(canvas, { pad, points: candles.length });
              }
            }

            function drawIndicator(canvas, indicator) {
              const { ctx, cssW, cssH } = prepareCanvas(canvas);
              const lines = indicator.lines || [];
              const hist = indicator.hist || null;
              const levels = indicator.levels || [];
              const values = [];
              for (const line of lines) {
                for (const v of line.values || []) if (v != null) values.push(Number(v));
              }
              if (hist) for (const v of hist) if (v != null) values.push(Number(v));
              if (!values.length) {
                ctx.fillStyle = '#5c6b61';
                ctx.font = '13px Manrope, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('Нет данных индикатора', cssW / 2, cssH / 2);
                return;
              }

              const n = Math.max(...lines.map(l => (l.values || []).length), hist ? hist.length : 0);
              const pad = { top: 10, right: 58, bottom: 22, left: 8 };
              const plotW = cssW - pad.left - pad.right;
              const plotH = cssH - pad.top - pad.bottom;
              let min = Math.min(...values);
              let max = Math.max(...values);
              for (const lv of levels) {
                min = Math.min(min, lv);
                max = Math.max(max, lv);
              }
              if (hist) {
                min = Math.min(min, 0);
                max = Math.max(max, 0);
              }
              const padY = (max - min) * 0.08 || 1;
              min -= padY; max += padY;
              const yScale = (v) => pad.top + ((max - v) / (max - min)) * plotH;
              const slot = plotW / n;

              ctx.strokeStyle = '#d5ddd7';
              ctx.fillStyle = '#5c6b61';
              ctx.font = '11px "IBM Plex Mono", monospace';
              ctx.textAlign = 'left';
              [max, (max + min) / 2, min].forEach((v) => {
                const y = yScale(v);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(cssW - pad.right, y);
                ctx.stroke();
                ctx.fillText(fmt(v, 2), cssW - pad.right + 6, y + 4);
              });

              for (const lv of levels) {
                const y = yScale(lv);
                ctx.strokeStyle = '#9aa89f';
                ctx.setLineDash([4, 4]);
                ctx.beginPath();
                ctx.moveTo(pad.left, y);
                ctx.lineTo(cssW - pad.right, y);
                ctx.stroke();
                ctx.setLineDash([]);
              }

              if (hist) {
                const zeroY = yScale(0);
                const barW = Math.max(1, Math.min(8, slot * 0.55));
                for (let i = 0; i < hist.length; i++) {
                  if (hist[i] == null) continue;
                  const h = Number(hist[i]);
                  const x = pad.left + slot * i + slot / 2;
                  const y = yScale(h);
                  ctx.fillStyle = h >= 0 ? '#0f6b4c' : '#b42318';
                  ctx.fillRect(x - barW / 2, Math.min(y, zeroY), barW, Math.max(1, Math.abs(y - zeroY)));
                }
              }

              for (const line of lines) {
                ctx.strokeStyle = line.color || '#1f4b7a';
                ctx.lineWidth = 1.5;
                ctx.beginPath();
                let started = false;
                (line.values || []).forEach((v, i) => {
                  if (v == null) return;
                  const x = pad.left + slot * i + slot / 2;
                  const y = yScale(Number(v));
                  if (!started) { ctx.moveTo(x, y); started = true; }
                  else ctx.lineTo(x, y);
                });
                ctx.stroke();
              }
              if (window.ChartCrosshair) {
                ChartCrosshair.mark(canvas, { pad, points: n });
              }
            }

            const root = document.getElementById('topCharts');
            const drawn = [];

            TOP_CHARTS.forEach((item) => {
              const block = document.createElement('div');
              block.className = 'chart-block';
              const acc = item.accuracy == null ? '—' : (item.accuracy * 100).toFixed(2) + '%';
              const liveUrl = 'live.php?' + new URLSearchParams({
                method: item.method,
                resolution: item.resolution,
                market_id: '1',
              }).toString();
              block.innerHTML = `
                <h3>#${item.rank} · <a class="method-link" href="${liveUrl}" target="_blank" rel="noopener noreferrer">${item.method}</a> · ${item.resolution}</h3>
                <div class="chart-meta">accuracy ${acc} · score ${Number(item.score).toFixed(4)} · signals ${item.signals} · candles ${item.candles.length}</div>
                <div class="chart-wrap"><canvas data-price></canvas></div>
                <div class="chart-wrap indicator"><canvas data-ind></canvas></div>`;
              root.appendChild(block);
              const priceCanvas = block.querySelector('[data-price]');
              const indCanvas = block.querySelector('[data-ind]');
              drawn.push({ priceCanvas, indCanvas, item });
              drawCandles(priceCanvas, item.candles || []);
              drawIndicator(indCanvas, item.indicator || {});
            });
            if (window.ChartCrosshair) ChartCrosshair.refresh();

            window.addEventListener('resize', () => {
              for (const d of drawn) {
                drawCandles(d.priceCanvas, d.item.candles || []);
                drawIndicator(d.indCanvas, d.item.indicator || {});
              }
            });
          </script>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
