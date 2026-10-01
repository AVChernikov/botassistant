<?php

declare(strict_types=1);

?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>sim 1m · эмуляция</title>
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
      --accent: #0f6b4c;
      --accent-2: #c45c26;
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
    .wrap { width: min(1100px, calc(100% - 2rem)); margin: 0 auto; padding: 2rem 0 3rem; }
    header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; align-items: end; margin-bottom: 1.25rem; }
    .brand { font-size: clamp(1.7rem, 3.5vw, 2.3rem); font-weight: 700; letter-spacing: -0.04em; line-height: 1; }
    .brand span { color: var(--accent); }
    .subtitle { margin: 0.4rem 0 0; color: var(--muted); max-width: 38rem; font-size: 0.95rem; }
    .nav a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .nav span { color: var(--muted); margin: 0 .35rem; }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 1rem 1.1rem;
      margin-bottom: 1rem;
    }
    .row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
    button {
      appearance: none; border: 0; border-radius: 999px; padding: 0.7rem 1.2rem;
      font: inherit; font-weight: 600; cursor: pointer;
    }
    .btn-go { background: var(--accent); color: #fff; }
    .btn-stop { background: var(--ask); color: #fff; }
    .btn-go:disabled, .btn-stop:disabled { opacity: 0.45; cursor: default; }
    .pill {
      display: inline-flex; align-items: center; gap: 0.35rem;
      padding: 0.25rem 0.65rem; border-radius: 999px; font-size: 0.82rem; font-weight: 600;
      background: #e7efe9; color: var(--accent);
    }
    .pill.off { background: #f3e7e5; color: var(--ask); }
    .pill.emu { background: #f3ebe2; color: var(--accent-2); }
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 0.75rem;
      margin-top: 0.85rem;
    }
    .stat {
      padding: 0.75rem 0.85rem;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(255,255,255,0.55);
    }
    .stat .k { color: var(--muted); font-size: 0.78rem; }
    .stat .v { font-family: "IBM Plex Mono", monospace; font-size: 1.05rem; margin-top: 0.2rem; font-weight: 500; }
    .pos { color: var(--accent); }
    .neg { color: var(--ask); }
    table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
    th, td { text-align: left; padding: 0.45rem 0.35rem; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { color: var(--muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
    .mono { font-family: "IBM Plex Mono", monospace; }
    .muted { color: var(--muted); }
    .grid2 { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 1rem; }
    @media (max-width: 860px) { .grid2 { grid-template-columns: 1fr; } }
    .note { font-size: 0.85rem; color: var(--muted); margin: 0.5rem 0 0; }
    h2 { font-size: 1rem; margin: 0 0 0.65rem; letter-spacing: -0.02em; }
    .section-label {
      font-size: 0.78rem; font-weight: 700; letter-spacing: 0.08em;
      text-transform: uppercase; color: var(--muted); margin: 0.25rem 0 0.55rem;
    }
    .chart-head {
      display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem;
      align-items: baseline; margin-bottom: 0.55rem;
    }
    .chart-head .method {
      font-weight: 700; letter-spacing: -0.02em;
    }
    .chart-head .method span { color: var(--accent); }
    .chart-meta {
      font-family: "IBM Plex Mono", monospace; font-size: 0.75rem; color: var(--muted);
    }
    .chart-meta.error { color: var(--ask); }
    .chart-wrap {
      width: 100%; height: 260px; border: 1px solid var(--line);
      border-radius: 14px; background: rgba(255,255,255,0.55); overflow: hidden;
    }
    .chart-wrap.method-pane { height: 150px; margin-top: 0.55rem; }
    .chart-wrap canvas { width: 100%; height: 100%; display: block; }
    .chart-sub {
      margin: 0.45rem 0 0; font-size: 0.78rem; color: var(--muted);
    }
    .levels-box {
      margin-top: 0.7rem;
      padding: 0.65rem 0.75rem;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(255,255,255,0.55);
      display: grid;
      gap: 0.55rem;
    }
    .levels-row {
      display: flex; flex-wrap: wrap; gap: 0.45rem 0.75rem; align-items: center;
    }
    .levels-row .lab {
      min-width: 2.6rem; font-size: 0.78rem; font-weight: 700;
      letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted);
    }
    .levels-row .lab.sl { color: var(--ask); }
    .levels-row .lab.tp { color: var(--accent); }
    .levels-row label.lv {
      display: inline-flex; align-items: center; gap: 0.28rem;
      font-family: "IBM Plex Mono", monospace; font-size: 0.8rem;
      cursor: pointer; user-select: none;
    }
    .levels-row label.lv input { accent-color: var(--accent); }
    .levels-row.sl label.lv input { accent-color: var(--ask); }
    .levels-row .btn-cycle {
      margin-left: auto;
      appearance: none; border: 1px solid var(--line);
      border-radius: 999px; padding: 0.28rem 0.7rem;
      font: inherit; font-size: 0.78rem; font-weight: 700;
      cursor: pointer; background: rgba(255,255,255,0.85);
      color: var(--ink); font-family: "IBM Plex Mono", monospace;
    }
    .levels-row.sl .btn-cycle { color: var(--ask); border-color: #e0b4af; }
    .levels-row.tp .btn-cycle { color: var(--accent); border-color: #b7d4c7; }
    .levels-row .btn-cycle:hover { background: #fff; }
    .levels-hint { font-size: 0.75rem; color: var(--muted); margin: 0; }
    .modal-back {
      position: fixed; inset: 0; background: rgba(21,32,25,0.45);
      display: none; align-items: center; justify-content: center; z-index: 40;
      padding: 1rem;
    }
    .modal-back.show { display: flex; }
    .modal {
      width: min(480px, 100%);
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 16px;
      box-shadow: var(--shadow);
      padding: 1.1rem 1.2rem;
    }
    .modal h3 { margin: 0 0 0.35rem; font-size: 1.1rem; }
    .modal p { margin: 0 0 0.85rem; color: var(--muted); font-size: 0.9rem; }
    .modal label.pos {
      display: flex; gap: 0.6rem; align-items: flex-start;
      padding: 0.55rem 0.65rem; border: 1px solid var(--line);
      border-radius: 12px; margin-bottom: 0.45rem; cursor: pointer;
      background: rgba(255,255,255,0.7);
    }
    .modal label.pos input { margin-top: 0.2rem; }
    .modal .pos-meta { font-size: 0.82rem; color: var(--muted); }
    .modal .actions { display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.9rem; }
    .modal .actions button { border-radius: 999px; padding: 0.55rem 1rem; font-weight: 600; border: 0; cursor: pointer; }
    .modal .btn-ok { background: var(--accent); color: #fff; }
    .modal .btn-skip { background: #e7efe9; color: var(--ink); }
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <div class="brand">sim <span>1m</span></div>
        <p class="subtitle">Эмуляция 30с: стакан + свечи 1m → TA (метод выбирает DeepSeek Flash) → paper fills. Live-торговля и crons не трогаются.</p>
      </div>
      <div class="nav">
        <a href="index.php">← index</a>
        <span>·</span>
        <a href="live-1m.php">LIVE</a>
        <span>·</span>
        <a href="lit.php">LIT</a>
      </div>
    </header>

    <div class="panel" id="simChartPanel">
      <div class="chart-head">
        <div class="method">1m · <span id="simChartMethod">ROC(10) zero-cross · 1m</span></div>
        <div class="chart-meta" id="simChartMeta">загрузка…</div>
      </div>
      <div class="chart-wrap"><canvas id="simPriceChart"></canvas></div>
      <div class="chart-wrap method-pane"><canvas id="simMethodChart"></canvas></div>
      <div class="levels-box" id="levelsBox">
        <div class="levels-row" id="lotRow">
          <span class="lab">лот</span>
        </div>
        <div class="levels-row sl" id="slRow">
          <span class="lab sl">SL</span>
        </div>
        <div class="levels-row tp" id="tpRow">
          <span class="lab tp">TP</span>
        </div>
        <p class="levels-hint">Radio лота — на <b>следующую</b> сделку. Кнопка «лот → поз.» — добор/сокращение <b>текущей</b> позиции до выбранного лота по mark. SL/TP → меняют текущие уровни.</p>
      </div>
      <p class="chart-sub">График 1m; метод выбирает DeepSeek Flash.</p>
    </div>

    <div class="section-label">эмуляция</div>
    <div class="panel">
      <div class="row">
        <button class="btn-go" id="btnStart">Запустить 1m эмуляцию</button>
        <button class="btn-stop" id="btnStop" disabled>Стоп</button>
        <span class="pill emu" id="modePill">режим: emulation</span>
        <span class="pill" id="methodPill" title="метод выбирает DeepSeek Flash">Flash · —</span>
        <span class="pill off" id="runPill">stopped</span>
        <span class="muted mono" id="metaLine">session —</span>
      </div>
      <p class="note">Тики в фоне; после F5 сессия подхватывается и спрашивает, какие открытые позиции (paper/live) включить. Stop закрывает paper. Live не трогаем. Метод 1m — Flash.</p>
      <div class="stats" id="stats"></div>
    </div>

    <div class="grid2">
      <div class="panel">
        <h2>Тики</h2>
        <div style="overflow:auto;max-height:420px">
          <table>
            <thead>
              <tr><th>время</th><th>метод</th><th>px</th><th>sig</th><th>action</th><th>uPnL</th><th>sess</th></tr>
            </thead>
            <tbody id="ticksBody"><tr><td colspan="7" class="muted">нет данных</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="panel">
        <h2>Сделки (paper)</h2>
        <div style="overflow:auto;max-height:420px">
          <table>
            <thead>
              <tr><th>время</th><th>act</th><th>side</th><th>px</th><th>pnl</th></tr>
            </thead>
            <tbody id="tradesBody"><tr><td colspan="5" class="muted">нет сделок</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Лог</h2>
      <div id="logs" class="mono" style="font-size:0.82rem;max-height:160px;overflow:auto;color:var(--muted)">—</div>
    </div>
  </div>

  <div class="modal-back" id="adoptModal" role="dialog" aria-modal="true">
    <div class="modal">
      <h3>Открытые позиции</h3>
      <p>Окно открыто снова. Какие позиции включить в paper-сессию? Live на бирже не закрываются.</p>
      <div id="adoptList"></div>
      <div class="actions">
        <button type="button" class="btn-skip" id="adoptSkip">Пропустить</button>
        <button type="button" class="btn-ok" id="adoptOk">Включить выбранные</button>
      </div>
    </div>
  </div>

  <script src="chart-candles.js?v=2"></script>
  <script src="sim-1m-chart.js?v=7"></script>
  <script>
    const API = 'api.php';
    const STATUS_ACTION = 'sim_1m_status';
    const START_ACTION = 'sim_1m_start';
    const SET_LEVELS_ACTION = 'sim_1m_set_levels';
    const LS_KEY = 'sim_1m_levels';
    const LOT_OPTS = [50, 100, 150, 200, 250, 300, 350, 400];
    const SL_OPTS = [0.5, 1, 2, 3, 5, 10, 15, 20, 25, 30];
    const TP_OPTS = [0.5, 1, 2, 3, 5, 10, 15, 20, 25, 30];
    let sessionId = null;
    try {
      const saved = localStorage.getItem('sim_1m_session_id');
      if (saved) sessionId = Number(saved) || null;
    } catch (_) {}
    let running = false;
    let timer = null;
    let tickSec = 30;
    let levelsState = { lot: 200, sl: 30, tp: 30 };
    let lastEntry = null;
    let lastSide = null;
    let lastMark = null;
    let lastTp = null;
    let lastSl = null;

    const $ = (id) => document.getElementById(id);
    const fmt = (n, d = 2) => (n == null || Number.isNaN(Number(n))) ? '—' : Number(n).toFixed(d);

    function pickOnePct(raw, opts, fallback) {
      if (Array.isArray(raw) && raw.length) {
        const hit = raw.map(Number).find((x) => opts.includes(x));
        if (hit != null) return hit;
      }
      const n = Number(raw);
      return opts.includes(n) ? n : fallback;
    }

    function loadLevelsLocal() {
      try {
        const raw = localStorage.getItem(LS_KEY);
        if (!raw) return;
        const o = JSON.parse(raw);
        if (o && typeof o === 'object') {
          if (LOT_OPTS.includes(Number(o.lot))) levelsState.lot = Number(o.lot);
          levelsState.sl = pickOnePct(o.sl, SL_OPTS, 30);
          levelsState.tp = pickOnePct(o.tp, TP_OPTS, 30);
        }
      } catch (_) {}
    }
    function saveLevelsLocal() {
      try { localStorage.setItem(LS_KEY, JSON.stringify(levelsState)); } catch (_) {}
    }

    function buildLevelsUi() {
      const lotRow = $('lotRow');
      const slRow = $('slRow');
      const tpRow = $('tpRow');
      LOT_OPTS.forEach((v) => {
        const lab = document.createElement('label');
        lab.className = 'lv';
        lab.innerHTML = `<input type="radio" name="lotLv" value="${v}"> $${v}`;
        lotRow.appendChild(lab);
      });
      SL_OPTS.forEach((v) => {
        const lab = document.createElement('label');
        lab.className = 'lv';
        lab.innerHTML = `<input type="radio" name="slLv" value="${v}"> ${v}%`;
        slRow.appendChild(lab);
      });
      TP_OPTS.forEach((v) => {
        const lab = document.createElement('label');
        lab.className = 'lv';
        lab.innerHTML = `<input type="radio" name="tpLv" value="${v}"> ${v}%`;
        tpRow.appendChild(lab);
      });
      lotRow.appendChild(makeCycleBtn('lot', 'лот → поз.'));
      slRow.appendChild(makeCycleBtn('sl', 'SL →'));
      tpRow.appendChild(makeCycleBtn('tp', 'TP →'));
      syncLevelsUi();
      lotRow.querySelectorAll('input').forEach((el) => el.addEventListener('change', () => onLevelsChange({ resizeLot: false })));
      slRow.querySelectorAll('input').forEach((el) => el.addEventListener('change', () => onLevelsChange({ resizeLot: false })));
      tpRow.querySelectorAll('input').forEach((el) => el.addEventListener('change', () => onLevelsChange({ resizeLot: false })));
    }

    function makeCycleBtn(kind, label) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn-cycle';
      btn.textContent = label;
      btn.title = kind === 'lot'
        ? 'Применить выбранный лот к текущей позиции (добор/сокращение по mark). Radio без кнопки — только на след. сделку.'
        : 'Сменить текущий уровень ' + kind.toUpperCase() + ' на следующий';
      btn.addEventListener('click', () => cycleLevel(kind));
      return btn;
    }

    function cycleLevel(kind) {
      if (kind === 'lot') {
        readLevelsFromUi();
        onLevelsChange({ resizeLot: true });
        return;
      }
      if (kind === 'sl') {
        const i = SL_OPTS.indexOf(Number(levelsState.sl));
        levelsState.sl = SL_OPTS[(i < 0 ? 0 : i + 1) % SL_OPTS.length];
      } else if (kind === 'tp') {
        const i = TP_OPTS.indexOf(Number(levelsState.tp));
        levelsState.tp = TP_OPTS[(i < 0 ? 0 : i + 1) % TP_OPTS.length];
      }
      syncLevelsUi();
      saveLevelsLocal();
      onLevelsChange({ resizeLot: false });
    }

    function syncLevelsUi() {
      document.querySelectorAll('input[name=lotLv]').forEach((el) => {
        el.checked = Number(el.value) === Number(levelsState.lot);
      });
      document.querySelectorAll('input[name=slLv]').forEach((el) => {
        el.checked = Number(el.value) === Number(levelsState.sl);
      });
      document.querySelectorAll('input[name=tpLv]').forEach((el) => {
        el.checked = Number(el.value) === Number(levelsState.tp);
      });
    }

    function readLevelsFromUi() {
      const lotEl = document.querySelector('input[name=lotLv]:checked');
      const slEl = document.querySelector('input[name=slLv]:checked');
      const tpEl = document.querySelector('input[name=tpLv]:checked');
      levelsState.lot = lotEl ? Number(lotEl.value) : 200;
      levelsState.sl = slEl ? Number(slEl.value) : 30;
      levelsState.tp = tpEl ? Number(tpEl.value) : 30;
      saveLevelsLocal();
    }

    function chartLevelsFromState() {
      const side = lastSide || 'long';
      const base = (lastSide && lastEntry > 0) ? lastEntry : lastMark;
      if (!base || !(base > 0)) {
        if (window.Sim1mChart) Sim1mChart.setLevels([]);
        return;
      }
      const levels = [];
      const tpPct = Number(levelsState.tp);
      const slPct = Number(levelsState.sl);
      if (Number.isFinite(tpPct)) {
        const frac = tpPct / 100;
        const price = side === 'long' ? base * (1 + frac) : base * (1 - frac);
        levels.push({ price, color: '#0f6b4c', dash: [6, 4], label: `TP ${tpPct}%`, width: 1.4 });
      }
      if (Number.isFinite(slPct)) {
        const frac = slPct / 100;
        const price = side === 'long' ? base * (1 - frac) : base * (1 + frac);
        levels.push({ price, color: '#b42318', dash: [6, 4], label: `SL ${slPct}%`, width: 1.4 });
      }
      if (window.Sim1mChart) Sim1mChart.setLevels(levels);
    }

    async function onLevelsChange(opts = {}) {
      readLevelsFromUi();
      chartLevelsFromState();
      if (!sessionId) return;
      const resizeLot = !!opts.resizeLot;
      try {
        const params = {
          session_id: String(sessionId),
          lot_usd: String(levelsState.lot),
          tp_levels: JSON.stringify([levelsState.tp]),
          sl_levels: JSON.stringify([levelsState.sl]),
        };
        if (resizeLot) {
          params.resize_lot = '1';
          if (lastMark) params.mark_price = String(lastMark);
        }
        const res = await api(SET_LEVELS_ACTION, params);
        if (res && res.tp_price != null) lastTp = Number(res.tp_price);
        if (res && res.sl_price != null) lastSl = Number(res.sl_price);
        await refresh();
      } catch (e) {
        console.error(e);
      }
    }

    function applyLevelsFromSession(s) {
      if (!s) return;
      let cfg = {};
      try {
        cfg = typeof s.config_json === 'string' ? JSON.parse(s.config_json || '{}') : (s.config_json || {});
      } catch (_) { cfg = {}; }
      if (LOT_OPTS.includes(Number(s.lot_usd))) levelsState.lot = Number(s.lot_usd);
      levelsState.sl = pickOnePct(cfg.sl_levels != null ? cfg.sl_levels : s.sl_pct, SL_OPTS, 30);
      levelsState.tp = pickOnePct(cfg.tp_levels != null ? cfg.tp_levels : s.tp_pct, TP_OPTS, 30);
      syncLevelsUi();
      saveLevelsLocal();
    }

    function persistSession(id) {
      sessionId = id || null;
      try {
        if (sessionId) localStorage.setItem('sim_1m_session_id', String(sessionId));
        else localStorage.removeItem('sim_1m_session_id');
      } catch (_) {}
    }
    const ts = (sec) => {
      if (!sec) return '—';
      const d = new Date((sec < 1e12 ? sec : sec / 1000) * 1000);
      return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };
    const clsPnL = (n) => (Number(n) >= 0 ? 'pos' : 'neg');

    function setRunUi(isRun) {
      running = isRun;
      $('btnStart').disabled = isRun;
      $('btnStop').disabled = !isRun;
      const pill = $('runPill');
      pill.textContent = isRun ? 'running' : 'stopped';
      pill.className = isRun ? 'pill' : 'pill off';
    }

    function renderStatus(data) {
      const s = data.session;
      if (!s) {
        $('stats').innerHTML = '';
        $('metaLine').textContent = 'session —';
        setRunUi(false);
        return;
      }
      sessionId = s.id;
      persistSession(s.id);
      tickSec = Number(s.tick_sec || 30);
      setRunUi(s.status === 'running');
      applyLevelsFromSession(s);
      lastSide = s.position_side || null;
      lastEntry = s.entry_price != null ? Number(s.entry_price) : null;
      lastTp = s.tp_price != null ? Number(s.tp_price) : null;
      lastSl = s.sl_price != null ? Number(s.sl_price) : null;
      $('metaLine').textContent = `session #${s.id} · ${s.symbol || 'LIT'} · ${s.method || 'ROC'} @ ${s.resolution || '1m'} · lot $${fmt(s.lot_usd, 0)}`;
      const pos = s.position_side
        ? `${s.position_side} @ ${fmt(s.entry_price, 4)} → tp ${fmt(s.tp_price, 4)} / sl ${fmt(s.sl_price, 4)}`
        : 'flat';
      $('stats').innerHTML = `
        <div class="stat"><div class="k">session PnL</div><div class="v ${clsPnL(s.session_pnl)}">${fmt(s.session_pnl)}$</div></div>
        <div class="stat"><div class="k">realized (net fees)</div><div class="v ${clsPnL(s.realized_pnl)}">${fmt(s.realized_pnl)}$</div></div>
        <div class="stat"><div class="k">fees paid</div><div class="v">${fmt(s.fees)}$</div></div>
        <div class="stat"><div class="k">position</div><div class="v" style="font-size:0.92rem">${pos}</div></div>
        <div class="stat"><div class="k">ticks / trades</div><div class="v">${s.ticks_count || 0} / ${s.trades_count || 0}</div></div>
        <div class="stat"><div class="k">last action</div><div class="v" style="font-size:0.9rem">${s.last_action || '—'}<div class="muted" style="font-size:0.75rem;font-weight:400">${s.last_reason || ''}</div></div></div>
      `;

      const ticks = data.ticks || [];
      if (ticks[0] && ticks[0].price != null) lastMark = Number(ticks[0].price);
      chartLevelsFromState();
      // Leading method from session / latest tick (set by DeepSeek Flash).
      const leadingMethod = s.method || (ticks[0] && ticks[0].method) || 'ROC(10) zero-cross';
      const leadingRes = s.resolution || (ticks[0] && ticks[0].resolution) || '1m';
      const methodPill = $('methodPill');
      if (methodPill) {
        const shortMap = {
          'ROC(10) zero-cross': 'ROC(10)',
          'SMA(10/30) cross': 'SMA(10/30)',
          'EMA(12/26) cross': 'EMA(12/26)',
          'MACD(12,26,9) cross': 'MACD',
          'RSI(14) 30/70': 'RSI(14)',
          'Bollinger(20,2) bounce': 'BB bounce',
          'Momentum(10) flip': 'Mom(10)',
        };
        methodPill.textContent = 'Flash · ' + (shortMap[leadingMethod] || leadingMethod) + ' @ ' + leadingRes;
        methodPill.title = 'DeepSeek Flash → ' + leadingMethod + ' @ ' + leadingRes;
      }
      if (window.Sim1mChart) {
        Sim1mChart.setLead(leadingMethod, '1m');
      }

      const methodLabel = (name) => {
        if (!name) return '—';
        const map = {
          'ROC(10) zero-cross': 'ROC(10)',
          'SMA(10/30) cross': 'SMA(10/30)',
          'EMA(12/26) cross': 'EMA(12/26)',
          'MACD(12,26,9) cross': 'MACD',
          'RSI(14) 30/70': 'RSI(14)',
          'Bollinger(20,2) bounce': 'BB bounce',
          'Momentum(10) flip': 'Mom(10)',
        };
        return map[name] || name;
      };

      $('ticksBody').innerHTML = ticks.length
        ? ticks.map(t => {
            const m = t.method || leadingMethod;
            const r = t.resolution || leadingRes;
            const sig = t.method_sig;
            const sigTxt = sig == null ? '—' : (sig > 0 ? `+${sig}` : String(sig));
            return `<tr>
            <td class="mono">${ts(t.created_at)}</td>
            <td title="${m} @ ${r}">${methodLabel(m)} <span class="muted">${r}</span><div class="muted mono">sig ${sigTxt}</div></td>
            <td class="mono">${fmt(t.price, 4)}</td>
            <td class="mono">${sigTxt}</td>
            <td>${t.action || '—'}<div class="muted">${t.reason || ''}</div></td>
            <td class="mono ${clsPnL(t.u_pnl)}">${fmt(t.u_pnl)}</td>
            <td class="mono ${clsPnL(t.session_pnl)}">${fmt(t.session_pnl)}</td>
          </tr>`;
          }).join('')
        : '<tr><td colspan="7" class="muted">нет тиков</td></tr>';

      const trades = data.trades || [];
      $('tradesBody').innerHTML = trades.length
        ? trades.map(t => `<tr>
            <td class="mono">${ts(t.created_at)}</td>
            <td>${t.action}</td>
            <td>${t.side || '—'}</td>
            <td class="mono">${fmt(t.price, 4)}</td>
            <td class="mono ${clsPnL(t.pnl)}">${fmt(t.pnl)}</td>
          </tr>`).join('')
        : '<tr><td colspan="5" class="muted">нет сделок</td></tr>';

      const logs = data.logs || [];
      $('logs').innerHTML = logs.length
        ? logs.map(l => `${ts(l.created_at)} [${l.level}] ${l.message}`).join('<br>')
        : '—';
    }

    async function api(action, params = {}) {
      const q = new URLSearchParams({ action, ...params });
      const r = await fetch(`${API}?${q.toString()}`, { cache: 'no-store' });
      return r.json();
    }

    async function refresh() {
      const data = await api('sim_1m_status', sessionId ? { session_id: String(sessionId) } : {});
      if (data.ok) renderStatus(data);
    }

    async function doTick() {
      if (!running) return;
      try {
        await api('sim_1m_tick', sessionId ? { session_id: String(sessionId) } : {});
        await refresh();
        // refresh() already syncs leading method+TF → chart via setLead
      } catch (e) {
        console.error(e);
      }
    }

    function armTimer() {
      if (timer) clearInterval(timer);
      timer = setInterval(doTick, Math.max(10000, tickSec * 1000));
    }

    $('btnStart').onclick = async () => {
      $('btnStart').disabled = true;
      $('methodPill').textContent = 'Flash · выбирает…';
      try {
        const res = await api(START_ACTION, {
          market_id: '120',
          lot_usd: String(levelsState.lot),
          tick_sec: '30',
          tp_levels: JSON.stringify([levelsState.tp]),
          sl_levels: JSON.stringify([levelsState.sl]),
        });
        if (!res.ok) {
          alert(res.error || 'start failed');
          $('btnStart').disabled = false;
          return;
        }
        sessionId = res.session_id;
        persistSession(sessionId);
        setRunUi(true);
        const m = res.method || res.first_tick?.tick?.method || res.method_pick?.method;
        if (m && window.Sim1mChart) Sim1mChart.setLead(m, '1m', { force: true });
        await refresh();
        armTimer();
      } catch (e) {
        alert(String(e));
        $('btnStart').disabled = false;
      }
    };

    $('btnStop').onclick = async () => {
      $('btnStop').disabled = true;
      try {
        const res = await api('sim_1m_stop', sessionId ? { session_id: String(sessionId) } : {});
        if (timer) clearInterval(timer);
        timer = null;
        setRunUi(false);
        if (res.closed_position && res.closed_position.ok) {
          console.info('closed paper', res.closed_position);
        }
        await refresh();
      } catch (e) {
        alert(String(e));
        $('btnStop').disabled = false;
      }
    };

    async function boot() {
      const params = sessionId ? { session_id: String(sessionId) } : {};
      let data = await api('sim_1m_resume', params);
      if (!data.ok || (!data.resumed && sessionId)) {
        data = await api('sim_1m_resume', {});
      }
      if (data.ok) {
        renderStatus(data);
        if (data.resumed || (data.session && data.session.status === 'running')) {
          persistSession(data.session_id || data.session.id);
        }
      } else {
        await refresh();
      }

      const cand = await api('sim_1m_candidates', sessionId ? { session_id: String(sessionId) } : {});
      const list = (cand && cand.candidates) || [];
      if (list.length) {
        const chosen = await askAdopt(list);
        if (chosen !== null) {
          const adopted = await api('sim_1m_adopt', {
            session_id: sessionId ? String(sessionId) : '',
            selected: JSON.stringify(chosen),
          });
          if (adopted.ok) {
            renderStatus(adopted);
            if (adopted.session_id) persistSession(adopted.session_id);
          }
        }
      }

      if (running) armTimer();
    }

    function askAdopt(candidates) {
      return new Promise((resolve) => {
        const back = $('adoptModal');
        const box = $('adoptList');
        box.innerHTML = candidates.map((c) => {
          const id = String(c.id).replace(/"/g, '');
          const checked = c.default_checked ? 'checked' : '';
          const meta = [
            c.source,
            c.side,
            c.symbol || '',
            c.entry != null ? 'entry ' + fmt(c.entry, 4) : '',
            c.u_pnl != null ? 'uPnL ' + fmt(c.u_pnl, 2) : '',
          ].filter(Boolean).join(' · ');
          return `<label class="pos"><input type="checkbox" value="${id}" ${checked}>
            <span><b>${c.label || id}</b><div class="pos-meta">${meta}</div></span></label>`;
        }).join('');
        back.classList.add('show');
        const done = (ids) => {
          back.classList.remove('show');
          $('adoptOk').onclick = null;
          $('adoptSkip').onclick = null;
          resolve(ids);
        };
        $('adoptOk').onclick = () => {
          const ids = [...box.querySelectorAll('input[type=checkbox]:checked')].map((el) => el.value);
          done(ids);
        };
        $('adoptSkip').onclick = () => done(null);
      });
    }

    loadLevelsLocal();
    buildLevelsUi();
    boot();
    if (window.Sim1mChart) {
      Sim1mChart.start({ method: 'ROC(10) zero-cross', resolution: '1m', refreshSec: 30 });
    }
  </script>
</body>
</html>
