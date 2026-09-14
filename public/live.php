<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Lighter\TechnicalAnalysis;

$method = (string) ($_GET['method'] ?? 'Bollinger(20,2) bounce');
$resolution = (string) ($_GET['resolution'] ?? '5m');
$marketId = filter_var($_GET['market_id'] ?? 1, FILTER_VALIDATE_INT);
$marketId = $marketId === false ? 1 : $marketId;

if (!TechnicalAnalysis::isKnownMethod($method)) {
    $method = TechnicalAnalysis::METHODS[0];
}
if (!in_array($resolution, ['1m', '5m', '15m', '30m', '1h', '4h', '12h', '1d'], true)) {
    $resolution = '1h';
}

$titleMethod = htmlspecialchars($method, ENT_QUOTES);
$titleTf = htmlspecialchars($resolution, ENT_QUOTES);

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $titleMethod ?> · <?= $titleTf ?> · live</title>
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
      --bid: #0f6b4c;
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
    header {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 1rem;
      align-items: end;
      margin-bottom: 1rem;
    }
    .brand {
      font-size: clamp(1.5rem, 3vw, 2.1rem);
      font-weight: 700;
      letter-spacing: -0.04em;
    }
    .brand span { color: var(--accent); }
    .subtitle { margin: 0.4rem 0 0; color: var(--muted); font-size: 0.92rem; }
    .nav a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .status {
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.8rem;
      color: var(--muted);
      margin: 0 0 1rem;
    }
    .status.error { color: var(--ask); }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: end;
      margin: 0 0 1rem;
      padding: 0.85rem 1rem;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .toolbar label {
      display: grid;
      gap: 0.3rem;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .zoom-switch {
      display: flex;
      flex-wrap: wrap;
      gap: 0.35rem;
    }
    .zoom-switch button {
      font: inherit;
      height: 2.4rem;
      min-width: 3.4rem;
      padding: 0 0.7rem;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: #fff;
      color: var(--ink);
      font-weight: 600;
      cursor: pointer;
    }
    .zoom-switch button.active {
      background: var(--accent);
      border-color: var(--accent);
      color: #fff;
    }
    .toolbar .hint {
      margin-left: auto;
      color: var(--muted);
      font-size: 0.82rem;
      align-self: center;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-bottom: 1rem;
    }
    .panel h2 {
      margin: 0;
      padding: 0.9rem 1rem;
      border-bottom: 1px solid var(--line);
      font-size: 0.95rem;
    }
    .panel-body { padding: 1rem; }
    .chart-wrap { width: 100%; height: 300px; }
    .chart-wrap.indicator { height: 180px; }
    .chart-wrap canvas { width: 100%; height: 100%; display: block; }
    .grid {
      display: grid;
      grid-template-columns: 1.1fr 1fr 1fr;
      gap: 1rem;
    }
    .meta { display: grid; gap: 0.65rem; }
    .meta-row {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      padding-bottom: 0.55rem;
      border-bottom: 1px dashed var(--line);
      font-size: 0.92rem;
    }
    .meta-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .meta-row span { color: var(--muted); }
    .meta-row strong {
      font-family: "IBM Plex Mono", monospace;
      font-weight: 500;
      text-align: right;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.8rem;
    }
    th, td { padding: 0.38rem 0.2rem; text-align: right; }
    th:first-child, td:first-child { text-align: left; }
    th { color: var(--muted); font-weight: 500; border-bottom: 1px solid var(--line); }
    .ask { color: var(--ask); }
    .bid { color: var(--bid); }
    .muted { color: var(--muted); }
    .empty { color: var(--muted); text-align: center; padding: 1.2rem 0; }
    @media (max-width: 960px) { .grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="brand">Live <span>· <?= $titleMethod ?></span></div>
        <p class="subtitle">BTC #<?= (int) $marketId ?> perp · <?= $titleTf ?> · автообновление каждые 30 сек</p>
      </div>
      <div class="nav">
        <a href="math-report.php">← отчёт</a>
      </div>
    </header>

    <p class="status" id="status">Загрузка…</p>

    <div class="toolbar">
      <label>
        Масштаб графика
        <div class="zoom-switch" id="zoomSwitch" role="group" aria-label="Количество свечей">
          <button type="button" data-count="50">50</button>
          <button type="button" data-count="100">100</button>
          <button type="button" data-count="150">150</button>
          <button type="button" data-count="200" class="active">200</button>
        </div>
      </label>
      <div class="hint">Меньше свечей — крупнее график (последние N)</div>
    </div>

    <section class="panel">
      <h2>График · <?= $titleTf ?></h2>
      <div class="panel-body">
        <div class="chart-wrap"><canvas id="priceChart"></canvas></div>
      </div>
    </section>

    <section class="panel">
      <h2>Индикатор · <?= $titleMethod ?></h2>
      <div class="panel-body">
        <div class="chart-wrap indicator"><canvas id="indChart"></canvas></div>
      </div>
    </section>

    <div class="grid">
      <section class="panel">
        <h2>Инструмент</h2>
        <div class="panel-body" id="info"><div class="empty">—</div></div>
      </section>
      <section class="panel">
        <h2>Стакан</h2>
        <div class="panel-body" id="book"><div class="empty">—</div></div>
      </section>
      <section class="panel">
        <h2>Сделки</h2>
        <div class="panel-body" id="trades"><div class="empty">—</div></div>
      </section>
    </div>
  </div>

  <script>
    const CONFIG = {
      method: <?= json_encode($method, JSON_UNESCAPED_UNICODE) ?>,
      resolution: <?= json_encode($resolution) ?>,
      marketId: <?= (int) $marketId ?>,
      refreshMs: 30000,
      fetchCandles: 200,
    };

    const els = {
      status: document.getElementById('status'),
      info: document.getElementById('info'),
      book: document.getElementById('book'),
      trades: document.getElementById('trades'),
      priceChart: document.getElementById('priceChart'),
      indChart: document.getElementById('indChart'),
      zoomSwitch: document.getElementById('zoomSwitch'),
    };

    let lastPayload = null;
    let visibleCount = 200;

    function setVisibleCount(count) {
      visibleCount = count;
      for (const btn of els.zoomSwitch.querySelectorAll('button')) {
        btn.classList.toggle('active', Number(btn.dataset.count) === count);
      }
      if (lastPayload) renderCharts(lastPayload);
      try {
        localStorage.setItem('live_candle_zoom', String(count));
      } catch (_) {}
    }

    function sliceIndicator(indicator, n) {
      if (!indicator) return {};
      const sliceVals = (arr) => Array.isArray(arr) ? arr.slice(-n) : arr;
      return {
        ...indicator,
        lines: (indicator.lines || []).map((line) => ({
          ...line,
          values: sliceVals(line.values),
        })),
        hist: indicator.hist ? sliceVals(indicator.hist) : null,
        levels: indicator.levels || [],
      };
    }

    function renderCharts(data) {
      const candles = (data.candles || []).slice(-visibleCount);
      const indicator = sliceIndicator(data.indicator || {}, visibleCount);
      drawCandles(els.priceChart, candles);
      drawIndicator(els.indChart, indicator);
    }

    function fmt(n, digits = 4) {
      if (n === null || n === undefined || n === '') return '—';
      const num = Number(n);
      if (!Number.isFinite(num)) return String(n);
      return num.toLocaleString('en-US', { maximumFractionDigits: digits });
    }

    function fmtTime(ts) {
      let ms = Number(ts);
      if (!Number.isFinite(ms)) return '—';
      if (ms > 1e15) ms = Math.floor(ms / 1000);
      const d = new Date(ms);
      if (Number.isNaN(d.getTime())) return '—';
      return d.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
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
      if (!candles.length) {
        ctx.fillStyle = '#5c6b61';
        ctx.font = '14px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нет свечей', cssW / 2, cssH / 2);
        return;
      }
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
    }

    function drawIndicator(canvas, indicator) {
      const { ctx, cssW, cssH } = prepareCanvas(canvas);
      const lines = indicator?.lines || [];
      const hist = indicator?.hist || null;
      const levels = indicator?.levels || [];
      const values = [];
      for (const line of lines) for (const v of line.values || []) if (v != null) values.push(Number(v));
      if (hist) for (const v of hist) if (v != null) values.push(Number(v));
      if (!values.length) {
        ctx.fillStyle = '#5c6b61';
        ctx.font = '13px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нет данных индикатора', cssW / 2, cssH / 2);
        return;
      }
      const n = Math.max(...lines.map(l => (l.values || []).length), hist ? hist.length : 0, 1);
      const pad = { top: 10, right: 58, bottom: 22, left: 8 };
      const plotW = cssW - pad.left - pad.right;
      const plotH = cssH - pad.top - pad.bottom;
      let min = Math.min(...values);
      let max = Math.max(...values);
      for (const lv of levels) { min = Math.min(min, lv); max = Math.max(max, lv); }
      if (hist) { min = Math.min(min, 0); max = Math.max(max, 0); }
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
    }

    function orderUsd(o) {
      const price = Number(o.price);
      const size = Number(o.remaining_base_amount);
      if (!Number.isFinite(price) || !Number.isFinite(size)) return null;
      return price * size;
    }

    function renderInfo(data) {
      const m = data.market || {};
      const s = data.market_stats || {};
      const rows = [
        ['Символ', m.symbol ?? 'BTC'],
        ['Market ID', m.market_id ?? CONFIG.marketId],
        ['Метод', CONFIG.method],
        ['ТФ', data.resolution || CONFIG.resolution],
        ['Last trade', s.last_trade_price != null ? fmt(s.last_trade_price, 6) : '—'],
        ['24h change %', s.daily_price_change != null ? fmt(s.daily_price_change, 4) : '—'],
        ['24h volume', s.daily_quote_token_volume != null ? fmt(s.daily_quote_token_volume, 2) : '—'],
      ];
      els.info.innerHTML = `<div class="meta">${rows.map(([k, v]) =>
        `<div class="meta-row"><span>${k}</span><strong>${v}</strong></div>`
      ).join('')}</div>`;
    }

    function renderBook(orderBook) {
      const asks = [...(orderBook.asks || [])].reverse();
      const bids = orderBook.bids || [];
      if (!asks.length && !bids.length) {
        els.book.innerHTML = '<div class="empty">Стакан пуст</div>';
        return;
      }
      const askRows = asks.map(o =>
        `<tr class="ask"><td>${fmt(o.price, 6)}</td><td>${fmt(o.remaining_base_amount, 6)}</td><td>${fmt(orderUsd(o), 2)}</td></tr>`
      ).join('');
      const bidRows = bids.map(o =>
        `<tr class="bid"><td>${fmt(o.price, 6)}</td><td>${fmt(o.remaining_base_amount, 6)}</td><td>${fmt(orderUsd(o), 2)}</td></tr>`
      ).join('');
      els.book.innerHTML = `
        <table>
          <thead><tr><th>Price</th><th>Size</th><th>USD</th></tr></thead>
          <tbody>
            ${askRows}
            <tr><td class="muted" colspan="3" style="text-align:center;padding:.55rem 0">spread</td></tr>
            ${bidRows}
          </tbody>
        </table>`;
    }

    function renderTrades(trades) {
      if (!trades.length) {
        els.trades.innerHTML = '<div class="empty">Нет сделок</div>';
        return;
      }
      const rows = trades.map(t => {
        const side = t.is_maker_ask === true ? 'bid' : 'ask';
        return `<tr class="${side}"><td>${fmtTime(t.timestamp ?? t.transaction_time)}</td><td>${fmt(t.price, 6)}</td><td>${fmt(t.size, 6)}</td><td>${fmt(t.usd_amount, 2)}</td></tr>`;
      }).join('');
      els.trades.innerHTML = `
        <table>
          <thead><tr><th>Time</th><th>Price</th><th>Size</th><th>USD</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    }

    function renderAll(data) {
      lastPayload = data;
      renderCharts(data);
      renderInfo(data);
      renderBook(data.order_book || {});
      renderTrades(data.trades || []);
    }

    async function refresh() {
      els.status.classList.remove('error');
      els.status.textContent = 'Обновляю…';
      try {
        const q = new URLSearchParams({
          action: 'live',
          method: CONFIG.method,
          resolution: CONFIG.resolution,
          market_id: String(CONFIG.marketId),
          candle_count: String(CONFIG.fetchCandles),
          depth: '12',
          trades: '25',
          network: 'mainnet',
        });
        const res = await fetch('api.php?' + q.toString());
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
        renderAll(data);
        const t = new Date(data.updated_at || Date.now()).toLocaleTimeString('ru-RU');
        els.status.textContent = `Обновлено ${t} · показ ${visibleCount}/${(data.candles || []).length} свечей · следующий через 30 сек · ${CONFIG.method} · ${data.resolution}`;
      } catch (e) {
        els.status.textContent = e.message;
        els.status.classList.add('error');
      }
    }

    window.addEventListener('resize', () => {
      if (!lastPayload) return;
      renderCharts(lastPayload);
    });

    els.zoomSwitch.addEventListener('click', (event) => {
      const btn = event.target.closest('button[data-count]');
      if (!btn) return;
      setVisibleCount(Number(btn.dataset.count));
      if (lastPayload) {
        const total = (lastPayload.candles || []).length;
        els.status.textContent = `Масштаб: последние ${visibleCount} из ${total} · автообновление 30 сек · ${CONFIG.method} · ${CONFIG.resolution}`;
      }
    });

    try {
      const saved = Number(localStorage.getItem('live_candle_zoom'));
      if ([50, 100, 150, 200].includes(saved)) {
        setVisibleCount(saved);
      }
    } catch (_) {}

    refresh();
    setInterval(refresh, CONFIG.refreshMs);
  </script>
</body>
</html>
