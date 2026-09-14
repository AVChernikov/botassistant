<?php

declare(strict_types=1);

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>BTC #1 · анализ · botassistant</title>
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
      --panel: rgba(255, 255, 255, 0.78);
      --panel-solid: #f7faf7;
      --accent: #0f6b4c;
      --bid: #0f6b4c;
      --ask: #b42318;
      --shadow: 0 18px 50px rgba(21, 32, 25, 0.08);
      --radius: 18px;
    }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; }
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
      margin-bottom: 1.25rem;
    }
    .brand {
      font-size: clamp(1.7rem, 3.5vw, 2.4rem);
      font-weight: 700;
      letter-spacing: -0.04em;
      line-height: 1;
    }
    .brand span { color: var(--accent); }
    .subtitle {
      margin: 0.45rem 0 0;
      color: var(--muted);
      font-size: 0.95rem;
    }
    .nav a {
      color: var(--accent);
      font-weight: 600;
      text-decoration: none;
    }
    .nav a:hover { text-decoration: underline; }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 1rem;
    }
    button {
      font: inherit;
      height: 2.6rem;
      padding: 0 1.15rem;
      border: 0;
      border-radius: 12px;
      background: var(--accent);
      color: #fff;
      font-weight: 700;
      cursor: pointer;
    }
    button:disabled { opacity: 0.55; cursor: wait; }
    .status {
      min-height: 1.35rem;
      margin: 0 0 1.1rem;
      color: var(--muted);
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.82rem;
    }
    .status.error { color: var(--ask); }

    .frame {
      background: var(--panel-solid);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      margin-bottom: 1rem;
      overflow: hidden;
    }
    .frame h2 {
      margin: 0;
      padding: 0.9rem 1rem;
      border-bottom: 1px solid var(--line);
      font-size: 1rem;
    }
    .frame-body { padding: 0.9rem 1rem 1.1rem; }
    .chart-wrap {
      position: relative;
      width: 100%;
      height: 280px;
    }
    .chart-wrap.macd { height: 150px; margin-top: 0.75rem; }
    .chart-wrap canvas {
      width: 100%;
      height: 100%;
      display: block;
    }
    .macd-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
      margin: 0.65rem 0 0.35rem;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.76rem;
      color: var(--muted);
    }
    .macd-legend b { font-weight: 500; }
    .up { color: var(--bid); }
    .down { color: var(--ask); }

    .grid {
      display: grid;
      grid-template-columns: 1.1fr 1fr 1fr;
      gap: 1rem;
      margin-top: 1.25rem;
    }
    .panel {
      background: var(--panel-solid);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      min-height: 300px;
    }
    .panel h2 {
      margin: 0;
      padding: 0.95rem 1rem;
      border-bottom: 1px solid var(--line);
      font-size: 0.95rem;
    }
    .panel-body { padding: 1rem; }
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
    th {
      color: var(--muted);
      font-weight: 500;
      border-bottom: 1px solid var(--line);
    }
    .ask { color: var(--ask); }
    .bid { color: var(--bid); }
    .muted { color: var(--muted); }
    .empty {
      color: var(--muted);
      font-size: 0.92rem;
      padding: 1.5rem 0;
      text-align: center;
    }
    @media (max-width: 960px) {
      .grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="brand">BTC <span>#1 perp</span></div>
        <p class="subtitle">Mainnet · графики 1d → 1m с MACD, затем инструмент, стакан и сделки.</p>
      </div>
      <div class="nav"><a href="index.php">← общая страница</a></div>
    </header>

    <div class="toolbar">
      <button type="button" id="reloadBtn">Обновить анализ</button>
    </div>
    <p class="status" id="status">Загружаю BTC #1…</p>

    <div id="frames"></div>

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

    <div class="toolbar" style="margin-top:1.5rem;justify-content:center">
      <a
        id="mathBtn"
        href="math-report.php"
        target="_blank"
        rel="noopener noreferrer"
        style="display:inline-flex;align-items:center;justify-content:center;min-width:280px;height:3rem;padding:0 1.15rem;border-radius:12px;background:var(--accent);color:#fff;font-weight:700;font-size:1rem;text-decoration:none"
      >
        Проверка мат. анализа
      </a>
    </div>
  </div>

  <script src="lighter-api.js?v=1"></script>
  <script src="chart-crosshair.js?v=1"></script>
  <script>
    const MARKET_ID = 1;
    const RESOLUTIONS = ['1d', '4h', '1h', '30m', '15m', '5m', '1m'];

    const els = {
      status: document.getElementById('status'),
      frames: document.getElementById('frames'),
      info: document.getElementById('info'),
      book: document.getElementById('book'),
      trades: document.getElementById('trades'),
      reloadBtn: document.getElementById('reloadBtn'),
    };

    const chartStore = [];

    function setStatus(text, isError = false) {
      els.status.textContent = text;
      els.status.classList.toggle('error', isError);
    }

    function fmt(n, digits = 4) {
      if (n === null || n === undefined || n === '') return '—';
      const num = Number(n);
      if (!Number.isFinite(num)) return String(n);
      return num.toLocaleString('en-US', { maximumFractionDigits: digits });
    }

    function fmtTime(ts) {
      if (ts === null || ts === undefined || ts === '') return '—';
      let ms = Number(ts);
      if (!Number.isFinite(ms)) return '—';
      if (ms > 1e15) ms = Math.floor(ms / 1000);
      const d = new Date(ms);
      if (Number.isNaN(d.getTime())) return '—';
      return d.toLocaleString('ru-RU', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
      });
    }

    async function api(action, params = {}) {
      try {
        if (action === 'btc_analyze') return await LighterPublicApi.btcAnalyze(params);
      } catch (directError) {
        console.warn('Direct Lighter request failed; using local fallback.', directError);
      }

      const q = new URLSearchParams({ action, ...params });
      const res = await fetch('api.php?' + q.toString());
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
      return data;
    }

    function emaSeries(values, period) {
      const out = new Array(values.length).fill(null);
      if (values.length < period) return out;
      const k = 2 / (period + 1);
      let sum = 0;
      for (let i = 0; i < period; i++) sum += values[i];
      let prev = sum / period;
      out[period - 1] = prev;
      for (let i = period; i < values.length; i++) {
        prev = values[i] * k + prev * (1 - k);
        out[i] = prev;
      }
      return out;
    }

    function calcMacd(candles, fast = 12, slow = 26, signal = 9) {
      const closes = candles.map(c => Number(c.c));
      const emaFast = emaSeries(closes, fast);
      const emaSlow = emaSeries(closes, slow);
      const macd = closes.map((_, i) =>
        emaFast[i] == null || emaSlow[i] == null ? null : emaFast[i] - emaSlow[i],
      );
      const macdValues = macd.filter(v => v != null);
      const signalSeed = emaSeries(macdValues, signal);
      const signalLine = new Array(macd.length).fill(null);
      let si = 0;
      for (let i = 0; i < macd.length; i++) {
        if (macd[i] == null) continue;
        signalLine[i] = signalSeed[si++];
      }
      const hist = macd.map((v, i) =>
        v == null || signalLine[i] == null ? null : v - signalLine[i],
      );
      return { macd, signal: signalLine, hist };
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

    function drawCandleChart(canvas, candles) {
      const { ctx, cssW, cssH } = prepareCanvas(canvas);
      if (!candles.length) {
        ctx.fillStyle = '#5c6b61';
        ctx.font = '14px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нет данных', cssW / 2, cssH / 2);
        return;
      }

      const pad = { top: 14, right: 60, bottom: 30, left: 10 };
      const plotW = cssW - pad.left - pad.right;
      const plotH = cssH - pad.top - pad.bottom;
      let min = Infinity, max = -Infinity;
      for (const c of candles) {
        min = Math.min(min, Number(c.l));
        max = Math.max(max, Number(c.h));
      }
      const padY = (max - min) * 0.06 || max * 0.001 || 1;
      min -= padY; max += padY;
      const yScale = (p) => pad.top + ((max - p) / (max - min)) * plotH;
      const slot = plotW / candles.length;
      const bodyW = Math.max(2, Math.min(16, slot * 0.62));

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
        const yO = yScale(o), yC = yScale(cl), yH = yScale(h), yL = yScale(l);
        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = 1.4;
        ctx.beginPath();
        ctx.moveTo(x, yH);
        ctx.lineTo(x, yL);
        ctx.stroke();
        ctx.fillRect(x - bodyW / 2, Math.min(yO, yC), bodyW, Math.max(1, Math.abs(yC - yO)));
      });

      ctx.fillStyle = '#5c6b61';
      ctx.textAlign = 'center';
      const idxs = [0, Math.floor((candles.length - 1) / 2), candles.length - 1];
      const seen = new Set();
      for (const i of idxs) {
        if (seen.has(i)) continue;
        seen.add(i);
        const x = pad.left + slot * i + slot / 2;
        const label = new Date(Number(candles[i].t)).toLocaleString('ru-RU', {
          day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
        });
        ctx.fillText(label, x, cssH - 10);
      }
      if (window.ChartCrosshair) {
        ChartCrosshair.mark(canvas, { pad, points: candles.length });
      }
    }

    function drawMacdChart(canvas, candles, legendEls) {
      const { ctx, cssW, cssH } = prepareCanvas(canvas);
      const series = calcMacd(candles);
      const vals = [];
      for (let i = 0; i < candles.length; i++) {
        if (series.macd[i] != null) vals.push(series.macd[i]);
        if (series.signal[i] != null) vals.push(series.signal[i]);
        if (series.hist[i] != null) vals.push(series.hist[i]);
      }
      if (!vals.length) {
        legendEls.macd.textContent = 'мало данных';
        legendEls.signal.textContent = '—';
        legendEls.hist.textContent = '—';
        ctx.fillStyle = '#5c6b61';
        ctx.font = '13px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нужно больше свечей для MACD', cssW / 2, cssH / 2);
        return;
      }

      const pad = { top: 10, right: 60, bottom: 22, left: 10 };
      const plotW = cssW - pad.left - pad.right;
      const plotH = cssH - pad.top - pad.bottom;
      const slot = plotW / candles.length;
      const barW = Math.max(1, Math.min(9, slot * 0.55));
      let min = Math.min(...vals, 0);
      let max = Math.max(...vals, 0);
      const padY = (max - min) * 0.08 || 1;
      min -= padY; max += padY;
      const yScale = (v) => pad.top + ((max - v) / (max - min)) * plotH;
      const zeroY = yScale(0);

      ctx.strokeStyle = '#d5ddd7';
      ctx.fillStyle = '#5c6b61';
      ctx.font = '11px "IBM Plex Mono", monospace';
      ctx.textAlign = 'left';
      [max, 0, min].forEach((v) => {
        const y = yScale(v);
        ctx.beginPath();
        ctx.moveTo(pad.left, y);
        ctx.lineTo(cssW - pad.right, y);
        ctx.stroke();
        ctx.fillText(fmt(v, 2), cssW - pad.right + 6, y + 4);
      });

      for (let i = 0; i < candles.length; i++) {
        const h = series.hist[i];
        if (h == null) continue;
        const x = pad.left + slot * i + slot / 2;
        const y = yScale(h);
        ctx.fillStyle = h >= 0 ? '#0f6b4c' : '#b42318';
        ctx.fillRect(x - barW / 2, Math.min(y, zeroY), barW, Math.max(1, Math.abs(y - zeroY)));
      }

      function strokeLine(values, color) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        let started = false;
        for (let i = 0; i < values.length; i++) {
          if (values[i] == null) continue;
          const x = pad.left + slot * i + slot / 2;
          const y = yScale(values[i]);
          if (!started) { ctx.moveTo(x, y); started = true; }
          else ctx.lineTo(x, y);
        }
        ctx.stroke();
      }
      strokeLine(series.macd, '#1f4b7a');
      strokeLine(series.signal, '#c45c26');

      let last = candles.length - 1;
      while (last >= 0 && series.macd[last] == null) last--;
      if (last >= 0) {
        const m = series.macd[last], s = series.signal[last], h = series.hist[last];
        legendEls.macd.textContent = m == null ? '—' : fmt(m, 4);
        legendEls.macd.className = m != null && m >= 0 ? 'up' : 'down';
        legendEls.signal.textContent = s == null ? '—' : fmt(s, 4);
        legendEls.hist.textContent = h == null ? '—' : fmt(h, 4);
        legendEls.hist.className = h != null && h >= 0 ? 'up' : 'down';
      }
      if (window.ChartCrosshair) {
        ChartCrosshair.mark(canvas, { pad, points: candles.length });
      }
    }

    function renderFrames(frames) {
      els.frames.innerHTML = '';
      chartStore.length = 0;

      for (const frame of frames) {
        const resolution = frame.resolution || '';
        const candles = frame.items || [];
        const block = document.createElement('section');
        block.className = 'frame';
        block.innerHTML = `
          <h2>График ${resolution} <span class="muted">· ${candles.length} свечей</span></h2>
          <div class="frame-body">
            <div class="chart-wrap"><canvas></canvas></div>
            <div class="macd-legend">
              <span>MACD ${resolution}: <b data-macd>—</b></span>
              <span>Signal: <b data-signal>—</b></span>
              <span>Hist: <b data-hist>—</b></span>
            </div>
            <div class="chart-wrap macd"><canvas></canvas></div>
          </div>`;
        els.frames.appendChild(block);

        const canvases = block.querySelectorAll('canvas');
        const legendEls = {
          macd: block.querySelector('[data-macd]'),
          signal: block.querySelector('[data-signal]'),
          hist: block.querySelector('[data-hist]'),
        };
        chartStore.push({ price: canvases[0], macd: canvases[1], candles, legendEls });
        drawCandleChart(canvases[0], candles);
        drawMacdChart(canvases[1], candles, legendEls);
      }
      if (window.ChartCrosshair) ChartCrosshair.refresh();
    }

    function renderInfo(payload) {
      const m = payload.market || {};
      const s = payload.market_stats || {};
      const rows = [
        ['Символ', m.symbol ?? 'BTC'],
        ['Market ID', m.market_id ?? MARKET_ID],
        ['Тип', m.market_type ?? 'perp'],
        ['Сеть', 'mainnet'],
        ['Статус', m.status ?? '—'],
        ['Min base', m.min_base_amount ?? '—'],
        ['Min quote', m.min_quote_amount ?? '—'],
        ['Size decimals', m.supported_size_decimals ?? '—'],
        ['Price decimals', m.supported_price_decimals ?? '—'],
        ['Last trade', s.last_trade_price != null ? fmt(s.last_trade_price, 6) : '—'],
        ['24h change %', s.daily_price_change != null ? fmt(s.daily_price_change, 4) : '—'],
        ['24h volume (quote)', s.daily_quote_token_volume != null ? fmt(s.daily_quote_token_volume, 2) : '—'],
        ['24h trades', s.daily_trades_count ?? '—'],
      ];
      els.info.innerHTML = `<div class="meta">${rows.map(([k, v]) =>
        `<div class="meta-row"><span>${k}</span><strong>${v}</strong></div>`
      ).join('')}</div>`;
    }

    function orderUsd(o) {
      const price = Number(o.price);
      const size = Number(o.remaining_base_amount);
      if (!Number.isFinite(price) || !Number.isFinite(size)) return null;
      return price * size;
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

    async function loadAnalysis() {
      els.reloadBtn.disabled = true;
      setStatus('Загружаю BTC #1 · mainnet · 7 таймфреймов…');
      try {
        const data = await api('btc_analyze', {
          candle_count: 200,
          depth: 12,
          trades: 25,
        });
        const frames = data.frames || [];
        // ensure order 1d → 1m
        frames.sort((a, b) => RESOLUTIONS.indexOf(a.resolution) - RESOLUTIONS.indexOf(b.resolution));
        renderFrames(frames);
        renderInfo(data);
        renderBook(data.order_book || {});
        renderTrades(data.trades || []);
        setStatus(`Готово · BTC #${MARKET_ID} perp mainnet · ${frames.map(f => f.resolution).join(' · ')}`);
      } catch (e) {
        setStatus(e.message, true);
      } finally {
        els.reloadBtn.disabled = false;
      }
    }

    window.addEventListener('resize', () => {
      for (const item of chartStore) {
        drawCandleChart(item.price, item.candles);
        drawMacdChart(item.macd, item.candles, item.legendEls);
      }
    });

    els.reloadBtn.addEventListener('click', loadAnalysis);
    loadAnalysis();
  </script>
</body>
</html>
