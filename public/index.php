<?php

declare(strict_types=1);

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>botassistant · Lighter</title>
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
      --accent-2: #c45c26;
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
      margin-bottom: 1.5rem;
    }

    .brand {
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      font-weight: 700;
      letter-spacing: -0.04em;
      line-height: 1;
    }
    .brand span { color: var(--accent); }
    .subtitle {
      margin: 0.45rem 0 0;
      color: var(--muted);
      max-width: 34rem;
      font-size: 0.98rem;
    }

    .controls {
      display: grid;
      grid-template-columns: 1.3fr 0.55fr 0.55fr 0.55fr auto;
      gap: 0.75rem;
      padding: 1rem;
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      backdrop-filter: blur(10px);
      margin-bottom: 1.25rem;
    }

    label {
      display: grid;
      gap: 0.35rem;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--muted);
    }

    select, input, button {
      font: inherit;
    }

    select, input {
      width: 100%;
      height: 2.7rem;
      padding: 0 0.85rem;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      color: var(--ink);
      outline: none;
    }
    select:focus, input:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(15, 107, 76, 0.15);
    }

    button {
      height: 2.7rem;
      margin-top: auto;
      padding: 0 1.2rem;
      border: 0;
      border-radius: 12px;
      background: var(--accent);
      color: #fff;
      font-weight: 700;
      cursor: pointer;
      transition: transform .15s ease, background .15s ease;
    }
    button:hover { background: #0c563d; }
    button:active { transform: translateY(1px); }
    button:disabled {
      opacity: 0.55;
      cursor: wait;
    }

    .status {
      min-height: 1.4rem;
      margin: 0 0 1rem;
      color: var(--muted);
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.82rem;
    }
    .status.error { color: var(--ask); }

    .grid {
      display: grid;
      grid-template-columns: 1.1fr 1fr 1fr;
      gap: 1rem;
    }

    .panel {
      background: var(--panel-solid);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      min-height: 320px;
    }
    .panel h2 {
      margin: 0;
      padding: 0.95rem 1rem;
      border-bottom: 1px solid var(--line);
      font-size: 0.95rem;
      letter-spacing: 0.02em;
    }
    .panel-body { padding: 1rem; }

    .meta {
      display: grid;
      gap: 0.65rem;
    }
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
    th, td {
      padding: 0.38rem 0.2rem;
      text-align: right;
    }
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

    .panel.candles {
      grid-column: 1 / -1;
      min-height: 280px;
    }
    .panel.chart {
      grid-column: 1 / -1;
      min-height: 360px;
    }
    .panel.macd {
      grid-column: 1 / -1;
      min-height: 220px;
    }
    .panel-body.scroll {
      max-height: 420px;
      overflow: auto;
    }
    .chart-wrap {
      position: relative;
      width: 100%;
      height: 340px;
    }
    .chart-wrap.macd {
      height: 180px;
    }
    .chart-wrap canvas {
      width: 100%;
      height: 100%;
      display: block;
    }
    .macd-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 0.85rem;
      margin: 0 0 0.75rem;
      font-family: "IBM Plex Mono", monospace;
      font-size: 0.78rem;
      color: var(--muted);
    }
    .macd-legend b { font-weight: 500; }
    .macd-legend .up { color: var(--bid); }
    .macd-legend .down { color: var(--ask); }
    .macd-legend .macd-line { color: #1f4b7a; }
    .macd-legend .signal-line { color: #c45c26; }

    @media (max-width: 960px) {
      .controls { grid-template-columns: 1fr 1fr; }
      .controls button { grid-column: 1 / -1; }
      .grid { grid-template-columns: 1fr; }
      .panel.candles,
      .panel.chart,
      .panel.macd { grid-column: auto; }
    }
    @media (max-width: 560px) {
      .controls { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="brand">botassistant <span>· Lighter</span></div>
        <p class="subtitle">Выберите инструмент и загрузите стакан, сделки, свечи и дневную статистику из публичного API.</p>
      </div>
      <div class="nav" style="margin-top:.35rem"><a href="btc.php" style="color:var(--accent);font-weight:600;text-decoration:none">BTC #1 анализ →</a></div>
    </header>

    <form class="controls" id="controls">
      <label>
        Инструмент
        <select id="market" required>
          <option value="">Загрузка рынков…</option>
        </select>
      </label>
      <label>
        Тип
        <select id="filter">
          <option value="perp" selected>Perp</option>
          <option value="spot">Spot</option>
          <option value="all">All</option>
        </select>
      </label>
      <label>
        Сеть
        <select id="network">
          <option value="mainnet" selected>Mainnet</option>
          <option value="testnet">Testnet</option>
        </select>
      </label>
      <label>
        Свечи
        <select id="resolution">
          <option value="1m">1m</option>
          <option value="5m">5m</option>
          <option value="15m">15m</option>
          <option value="30m">30m</option>
          <option value="1h" selected>1h</option>
          <option value="4h">4h</option>
          <option value="12h">12h</option>
          <option value="1d">1d</option>
        </select>
      </label>
      <button type="submit" id="loadBtn">Получить данные</button>
    </form>

    <p class="status" id="status">Подключаюсь к Lighter API…</p>

    <div class="grid">
      <section class="panel">
        <h2>Инструмент</h2>
        <div class="panel-body" id="info">
          <div class="empty">Выберите рынок и нажмите «Получить данные»</div>
        </div>
      </section>

      <section class="panel">
        <h2>Стакан</h2>
        <div class="panel-body" id="book">
          <div class="empty">—</div>
        </div>
      </section>

      <section class="panel">
        <h2>Сделки</h2>
        <div class="panel-body" id="trades">
          <div class="empty">—</div>
        </div>
      </section>

      <section class="panel candles">
        <h2>Свечи <span class="muted" id="candlesTitle" style="font-weight:500"></span></h2>
        <div class="panel-body scroll" id="candles">
          <div class="empty">—</div>
        </div>
      </section>

      <section class="panel chart">
        <h2>График <span class="muted" id="chartTitle" style="font-weight:500"></span></h2>
        <div class="panel-body">
          <div class="chart-wrap">
            <canvas id="candleChart"></canvas>
          </div>
        </div>
      </section>

      <section class="panel macd">
        <h2>MACD <span class="muted" style="font-weight:500">· 12, 26, 9</span></h2>
        <div class="panel-body">
          <div class="macd-legend" id="macdLegend">
            <span>MACD: <b id="macdVal">—</b></span>
            <span>Signal: <b id="signalVal">—</b></span>
            <span>Hist: <b id="histVal">—</b></span>
          </div>
          <div class="chart-wrap macd">
            <canvas id="macdChart"></canvas>
          </div>
        </div>
      </section>
    </div>
  </div>

  <script src="lighter-api.js?v=1"></script>
  <script src="chart-crosshair.js?v=1"></script>
  <script>
    const els = {
      market: document.getElementById('market'),
      filter: document.getElementById('filter'),
      network: document.getElementById('network'),
      resolution: document.getElementById('resolution'),
      form: document.getElementById('controls'),
      loadBtn: document.getElementById('loadBtn'),
      status: document.getElementById('status'),
      info: document.getElementById('info'),
      book: document.getElementById('book'),
      trades: document.getElementById('trades'),
      candles: document.getElementById('candles'),
      candlesTitle: document.getElementById('candlesTitle'),
      chart: document.getElementById('candleChart'),
      chartTitle: document.getElementById('chartTitle'),
      macdChart: document.getElementById('macdChart'),
      macdVal: document.getElementById('macdVal'),
      signalVal: document.getElementById('signalVal'),
      histVal: document.getElementById('histVal'),
    };

    let marketsCache = [];

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
      // transaction_time may arrive in microseconds
      if (ms > 1e15) ms = Math.floor(ms / 1000);
      const d = new Date(ms);
      if (Number.isNaN(d.getTime())) return '—';
      return d.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      });
    }

    async function api(action, params = {}) {
      try {
        if (action === 'markets') return await LighterPublicApi.markets(params);
        if (action === 'market') return await LighterPublicApi.market(params);
      } catch (directError) {
        console.warn('Direct Lighter request failed; using local fallback.', directError);
      }

      const q = new URLSearchParams({ action, ...params });
      const res = await fetch('api.php?' + q.toString());
      const data = await res.json();
      if (!res.ok || !data.ok) {
        throw new Error(data.error || ('HTTP ' + res.status));
      }
      return data;
    }

    function fillMarkets(markets, preferredId = null) {
      marketsCache = markets;
      els.market.innerHTML = '';
      if (!markets.length) {
        els.market.innerHTML = '<option value="">Нет активных рынков</option>';
        return;
      }
      for (const m of markets) {
        const opt = document.createElement('option');
        opt.value = String(m.market_id);
        opt.textContent = `${m.symbol}  ·  #${m.market_id}`;
        els.market.appendChild(opt);
      }
      if (preferredId != null) {
        els.market.value = String(preferredId);
      }
      if (!els.market.value) {
        const eth = markets.find(m => m.symbol === 'ETH' || m.market_id === 0);
        els.market.value = String((eth || markets[0]).market_id);
      }
    }

    async function loadMarkets() {
      const prev = els.market.value;
      setStatus('Загружаю список инструментов…');
      els.loadBtn.disabled = true;
      try {
        const data = await api('markets', {
          filter: els.filter.value,
          network: els.network.value,
        });
        fillMarkets(data.markets, prev || null);
        setStatus(`${data.network} · ${data.markets.length} активных рынков · ${data.base_url}`);
      } catch (e) {
        fillMarkets([]);
        setStatus(e.message, true);
      } finally {
        els.loadBtn.disabled = false;
      }
    }

    function renderInfo(payload) {
      const m = payload.market || {};
      const s = payload.market_stats || {};
      const rows = [
        ['Символ', m.symbol ?? '—'],
        ['Market ID', m.market_id ?? '—'],
        ['Тип', m.market_type ?? '—'],
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
        const side = (t.is_maker_ask === true || t.type === 'buy') ? 'bid' : 'ask';
        return `<tr class="${side}"><td>${fmtTime(t.timestamp ?? t.transaction_time)}</td><td>${fmt(t.price, 6)}</td><td>${fmt(t.size, 6)}</td><td>${fmt(t.usd_amount, 2)}</td></tr>`;
      }).join('');
      els.trades.innerHTML = `
        <table>
          <thead><tr><th>Time</th><th>Price</th><th>Size</th><th>USD</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    }

    function renderCandles(payload) {
      const chrono = [...(payload?.items || [])]; // oldest -> newest for chart
      const items = [...chrono].reverse(); // newest first for table
      const resolution = payload?.resolution || els.resolution.value;
      els.candlesTitle.textContent = items.length ? `· ${resolution} · ${items.length}` : '';
      els.chartTitle.textContent = items.length ? `· ${resolution}` : '';

      if (!items.length) {
        els.candles.innerHTML = '<div class="empty">Нет свечей</div>';
        drawCandleChart([]);
        drawMacdChart([]);
        return;
      }
      const rows = items.map(c => {
        const up = Number(c.c) >= Number(c.o);
        const cls = up ? 'bid' : 'ask';
        return `<tr class="${cls}">
          <td>${fmtTime(c.t)}</td>
          <td>${fmt(c.o, 6)}</td>
          <td>${fmt(c.h, 6)}</td>
          <td>${fmt(c.l, 6)}</td>
          <td>${fmt(c.c, 6)}</td>
          <td>${fmt(c.v, 4)}</td>
          <td>${fmt(c.V, 2)}</td>
        </tr>`;
      }).join('');
      els.candles.innerHTML = `
        <table>
          <thead><tr><th>Time</th><th>O</th><th>H</th><th>L</th><th>C</th><th>Vol</th><th>Quote</th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;

      drawCandleChart(chrono);
      drawMacdChart(chrono);
    }

    let lastCandles = [];

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

    function drawCandleChart(candles) {
      lastCandles = candles;
      const canvas = els.chart;
      if (!canvas) return;
      const { ctx, cssW, cssH } = prepareCanvas(canvas);

      if (!candles.length) {
        ctx.fillStyle = '#5c6b61';
        ctx.font = '14px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нет данных для графика', cssW / 2, cssH / 2);
        return;
      }

      const pad = { top: 16, right: 64, bottom: 36, left: 12 };
      const plotW = cssW - pad.left - pad.right;
      const plotH = cssH - pad.top - pad.bottom;

      let min = Infinity;
      let max = -Infinity;
      for (const c of candles) {
        min = Math.min(min, Number(c.l));
        max = Math.max(max, Number(c.h));
      }
      const padY = (max - min) * 0.06 || max * 0.001 || 1;
      min -= padY;
      max += padY;

      const yScale = (price) => pad.top + ((max - price) / (max - min)) * plotH;
      const slot = plotW / candles.length;
      const bodyW = Math.max(2, Math.min(18, slot * 0.62));

      ctx.strokeStyle = '#d5ddd7';
      ctx.fillStyle = '#5c6b61';
      ctx.font = '11px "IBM Plex Mono", monospace';
      ctx.textAlign = 'left';
      const steps = 4;
      for (let i = 0; i <= steps; i++) {
        const price = max - ((max - min) * i) / steps;
        const y = yScale(price);
        ctx.beginPath();
        ctx.moveTo(pad.left, y);
        ctx.lineTo(cssW - pad.right, y);
        ctx.stroke();
        ctx.fillText(fmt(price, 2), cssW - pad.right + 8, y + 4);
      }

      const upColor = '#0f6b4c';
      const downColor = '#b42318';

      candles.forEach((c, i) => {
        const o = Number(c.o);
        const h = Number(c.h);
        const l = Number(c.l);
        const cl = Number(c.c);
        const up = cl >= o;
        const color = up ? upColor : downColor;
        const x = pad.left + slot * i + slot / 2;
        const yO = yScale(o);
        const yC = yScale(cl);
        const yH = yScale(h);
        const yL = yScale(l);
        const bodyTop = Math.min(yO, yC);
        const bodyH = Math.max(1, Math.abs(yC - yO));

        ctx.strokeStyle = color;
        ctx.fillStyle = color;
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(x, yH);
        ctx.lineTo(x, yL);
        ctx.stroke();
        ctx.fillRect(x - bodyW / 2, bodyTop, bodyW, bodyH);
      });

      ctx.fillStyle = '#5c6b61';
      ctx.font = '11px "IBM Plex Mono", monospace';
      ctx.textAlign = 'center';
      const labelIdx = [0, Math.floor((candles.length - 1) / 2), candles.length - 1];
      const seen = new Set();
      for (const i of labelIdx) {
        if (seen.has(i)) continue;
        seen.add(i);
        const x = pad.left + slot * i + slot / 2;
        const label = new Date(Number(candles[i].t)).toLocaleString('ru-RU', {
          day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
        });
        ctx.fillText(label, x, cssH - 12);
      }
      if (window.ChartCrosshair) {
        ChartCrosshair.mark(canvas, { pad, points: candles.length });
      }
    }

    function drawMacdChart(candles) {
      const canvas = els.macdChart;
      if (!canvas) return;
      const { ctx, cssW, cssH } = prepareCanvas(canvas);

      if (!candles.length) {
        els.macdVal.textContent = '—';
        els.signalVal.textContent = '—';
        els.histVal.textContent = '—';
        ctx.fillStyle = '#5c6b61';
        ctx.font = '14px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нет данных для MACD', cssW / 2, cssH / 2);
        return;
      }

      const series = calcMacd(candles);
      const pad = { top: 12, right: 64, bottom: 28, left: 12 };
      const plotW = cssW - pad.left - pad.right;
      const plotH = cssH - pad.top - pad.bottom;
      const slot = plotW / candles.length;
      const barW = Math.max(1, Math.min(10, slot * 0.55));

      const vals = [];
      for (let i = 0; i < candles.length; i++) {
        if (series.macd[i] != null) vals.push(series.macd[i]);
        if (series.signal[i] != null) vals.push(series.signal[i]);
        if (series.hist[i] != null) vals.push(series.hist[i]);
      }
      if (!vals.length) {
        els.macdVal.textContent = 'мало данных';
        els.signalVal.textContent = '—';
        els.histVal.textContent = '—';
        ctx.fillStyle = '#5c6b61';
        ctx.font = '14px Manrope, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Нужно больше свечей для MACD(12,26,9)', cssW / 2, cssH / 2);
        return;
      }

      let min = Math.min(...vals, 0);
      let max = Math.max(...vals, 0);
      const padY = (max - min) * 0.08 || 1;
      min -= padY;
      max += padY;
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
        ctx.fillText(fmt(v, 2), cssW - pad.right + 8, y + 4);
      });

      // histogram
      for (let i = 0; i < candles.length; i++) {
        const h = series.hist[i];
        if (h == null) continue;
        const x = pad.left + slot * i + slot / 2;
        const y = yScale(h);
        ctx.fillStyle = h >= 0 ? '#0f6b4c' : '#b42318';
        const top = Math.min(y, zeroY);
        const height = Math.max(1, Math.abs(y - zeroY));
        ctx.fillRect(x - barW / 2, top, barW, height);
      }

      function strokeLine(values, color) {
        ctx.strokeStyle = color;
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        let started = false;
        for (let i = 0; i < values.length; i++) {
          if (values[i] == null) continue;
          const x = pad.left + slot * i + slot / 2;
          const y = yScale(values[i]);
          if (!started) {
            ctx.moveTo(x, y);
            started = true;
          } else {
            ctx.lineTo(x, y);
          }
        }
        ctx.stroke();
      }

      strokeLine(series.macd, '#1f4b7a');
      strokeLine(series.signal, '#c45c26');

      // latest values
      let last = candles.length - 1;
      while (last >= 0 && series.macd[last] == null) last--;
      if (last >= 0) {
        const m = series.macd[last];
        const s = series.signal[last];
        const h = series.hist[last];
        els.macdVal.textContent = m == null ? '—' : fmt(m, 4);
        els.macdVal.className = m != null && m >= 0 ? 'up' : 'down';
        els.signalVal.textContent = s == null ? '—' : fmt(s, 4);
        els.signalVal.className = 'signal-line';
        els.histVal.textContent = h == null ? '—' : fmt(h, 4);
        els.histVal.className = h != null && h >= 0 ? 'up' : 'down';
      }
      if (window.ChartCrosshair) {
        ChartCrosshair.mark(canvas, { pad, points: candles.length });
      }
    }

    window.addEventListener('resize', () => {
      if (lastCandles.length) {
        drawCandleChart(lastCandles);
        drawMacdChart(lastCandles);
      }
    });

    async function loadMarket(event) {
      event?.preventDefault();
      const marketId = els.market.value;
      if (!marketId) {
        setStatus('Сначала выберите инструмент', true);
        return;
      }
      els.loadBtn.disabled = true;
      setStatus(`Загружаю данные market_id=${marketId}…`);
      try {
        const data = await api('market', {
          market_id: marketId,
          network: els.network.value,
          resolution: els.resolution.value,
          depth: 12,
          trades: 25,
          candle_count: 200,
        });
        renderInfo(data);
        renderBook(data.order_book || {});
        renderTrades(data.trades || []);
        renderCandles(data.candles || {});
        const sym = data.market?.symbol || marketId;
        const n = data.candles?.items?.length ?? 0;
        setStatus(`Готово · ${sym} · свечи ${data.candles?.resolution || ''} × ${n} · MACD(12,26,9) · 24h vol $${fmt(data.exchange?.daily_usd_volume, 0)}`);
      } catch (e) {
        setStatus(e.message, true);
      } finally {
        els.loadBtn.disabled = false;
      }
    }

    els.filter.addEventListener('change', loadMarkets);
    els.network.addEventListener('change', loadMarkets);
    els.resolution.addEventListener('change', () => {
      if (els.market.value) loadMarket();
    });
    els.form.addEventListener('submit', loadMarket);

    loadMarkets().then(() => {
      if (els.market.value) loadMarket();
    });
  </script>
</body>
</html>
