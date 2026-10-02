<?php ?><!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>live 1m | REAL</title>
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
    .brand span { color: var(--ask); }
    .pill.live { background: #f3e7e5; color: var(--ask); }
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
    .tf-switch {
      display: inline-flex; gap: 0.35rem; align-items: center;
      margin: 0 0 0.55rem;
    }
    .tf-switch .lab {
      font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em;
      text-transform: uppercase; color: var(--muted); margin-right: 0.15rem;
    }
    .tf-btn {
      border: 1px solid var(--line); background: rgba(255,255,255,0.7);
      border-radius: 999px; padding: 0.28rem 0.75rem;
      font: inherit; font-size: 0.82rem; font-weight: 650; cursor: pointer;
      color: var(--ink);
    }
    .tf-btn:hover { border-color: var(--accent); }
    .tf-btn.active {
      background: var(--accent); color: #fff; border-color: var(--accent);
    }
    .tf-btn:disabled { opacity: 0.55; cursor: wait; }
    .method-switch {
      display: flex; flex-wrap: wrap; gap: 0.35rem; align-items: center;
      margin: 0.65rem 0 0.85rem;
    }
    .method-switch .lab {
      font-size: 0.78rem; color: var(--muted); margin-right: 0.15rem; font-weight: 600;
    }
    .method-switch .tf-btn { font-size: 0.78rem; padding: 0.28rem 0.55rem; }
    .method-switch .tf-btn[data-mode="flash"].active {
      background: #1f4b7a; border-color: #1f4b7a; color: #fff;
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
        <div class="brand">live <span>LIT</span></div>
        <p class="subtitle">LIVE сессия 30с: стакан + сигнал по выбранному ТФ (1m/5m). ТФ — только вручную. Метод — Flash или ручной (блок live trading). Эмуляция: <a href="sim-1m.php" style="color:var(--accent);font-weight:600;text-decoration:none">sim-1m</a>.</p>
      </div>
      <div class="nav">
        <a href="index.php">← index</a>
        <span>·</span>
        <a href="sim-1m.php">эмуляция</a>
        <span>·</span>
        <a href="lit.php">LIT</a>
      </div>
    </header>

    <div class="panel" id="simChartPanel">
      <div class="tf-switch" id="tfSwitch" title="Сигналы и сделки по выбранному таймфрейму">
        <span class="lab">ТФ</span>
        <button type="button" class="tf-btn active" data-tf="1m">1m</button>
        <button type="button" class="tf-btn" data-tf="5m">5m</button>
      </div>
      <div class="chart-head">
        <div class="method">сигнал · <span id="simChartMethod">ROC(10) zero-cross · 1m</span></div>
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
      <p class="chart-sub">График = выбранный ТФ (вручную). Метод: Flash или ручной — в live trading.</p>
    </div>

    <div class="section-label">официально с Lighter</div>
    <div class="panel" id="exOfficialPanel">
      <div class="chart-head" style="margin-bottom:0.65rem">
        <div class="method">биржа · <span id="exOfficialTitle">LIT #120</span></div>
        <div class="chart-meta" id="exOfficialMeta">загрузка…</div>
      </div>
      <div class="row" style="margin-bottom:0.65rem">
        <button type="button" class="btn-stop" id="btnCloseAll" title="Закрыть все позиции и снять все ордера на аккаунте">Close all</button>
        <span class="muted" style="font-size:0.82rem">закрывает позиции и снимает ордера на Lighter (сессию не останавливает)</span>
      </div>
      <div class="stats" id="exPositionStats">
        <div class="stat"><div class="k">позиция</div><div class="v muted">—</div></div>
      </div>
      <h2 style="margin-top:1rem">Активные ордера</h2>
      <div style="overflow:auto;max-height:260px">
        <table>
          <thead>
            <tr>
              <th>тип</th><th>side</th><th>size</th><th>лот$</th>
              <th>trigger</th><th>price</th><th>status</th><th>idx</th>
            </tr>
          </thead>
          <tbody id="exOrdersBody"><tr><td colspan="8" class="muted">нет ордеров</td></tr></tbody>
        </table>
      </div>
      <p class="note" style="margin-top:0.65rem">Источник: Lighter account API (не paper-сессия). Обновляется вместе с тиком / при загрузке.</p>
    </div>

    <div class="section-label">live trading</div>
    <div class="panel">
      <div class="row">
        <button class="btn-go" id="btnStart">Запустить LIVE</button>
        <button class="btn-stop" id="btnStop" disabled>Стоп</button>
        <span class="pill live" id="modePill">режим: LIVE</span>
        <span class="pill" id="methodPill" title="метод: Flash или ручной">Flash · —</span>
        <span class="pill off" id="runPill">stopped</span>
        <span class="muted mono" id="metaLine">session —</span>
      </div>
      <div class="method-switch" id="methodSwitch" title="Flash сам меняет метод; ручной — фиксирует выбранный индикатор">
        <span class="lab">метод</span>
        <button type="button" class="tf-btn active" data-mode="flash" data-method="">Flash</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="ROC(10) zero-cross">ROC</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="SMA(10/30) cross">SMA</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="EMA(12/26) cross">EMA</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="MACD(12,26,9) cross">MACD</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="RSI(14) 30/70">RSI</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="Bollinger(20,2) bounce">BB</button>
        <button type="button" class="tf-btn" data-mode="manual" data-method="Momentum(10) flip">Mom</button>
      </div>
      <div class="method-switch" id="tradeModeSwitch" title="Normal — вход по импульсу индикатора; Inverse — против импульса (реальное LIVE)">
        <span class="lab">вход</span>
        <button type="button" class="tf-btn active" data-trade-mode="normal">Normal</button>
        <button type="button" class="tf-btn" data-trade-mode="inverse">Inverse</button>
      </div>
      <p class="note"><b style="color:var(--ask)">REAL ORDERS.</b> Метод: <b>Flash</b> или ручной индикатор. <b>Inverse</b> — LIVE открывает против импульса (+1→short, −1→long). Лот под графиком (50…400). DeepSeek Pro — отдельный лот. На одном аккаунте они <b>складываются</b> в net. <b>Стоп</b> закрывает все позиции на аккаунте, снимает все ордера и делает повторную проверку. Эмуляция: <a href="sim-1m.php">sim-1m</a>. Бумажный инверс под логом всегда против индикатора (сравнение).</p>
      <div class="stats" id="stats"></div>
    </div>

    <div class="grid2">
      <div class="panel">
        <h2>Тики</h2>
        <div style="overflow:auto;max-height:420px">
          <table>
            <thead>
              <tr><th>время</th><th>метод</th><th>px</th><th>sig</th><th>size</th><th>action</th><th>uPnL</th><th>sess</th></tr>
            </thead>
            <tbody id="ticksBody"><tr><td colspan="8" class="muted">нет данных</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="panel">
        <h2>Сделки (LIVE)</h2>
        <div style="overflow:auto;max-height:420px">
          <table>
            <thead>
              <tr><th>время</th><th>act</th><th>side</th><th>px</th><th>size</th><th>лот$</th><th>pnl</th></tr>
            </thead>
            <tbody id="tradesBody"><tr><td colspan="7" class="muted">нет сделок</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="panel">
      <h2>Лог</h2>
      <div id="logs" class="mono" style="font-size:0.82rem;max-height:160px;overflow:auto;color:var(--muted)">—</div>
    </div>

    <div class="section-label">inverse paper (против сигналов)</div>
    <div class="panel" id="invPanel">
      <p class="note" style="margin-top:0">Paper only: вход против <code>impulse_sig</code>, те же лот/TP/SL/fees. Биржу не трогает. Сброс при смене session.</p>
      <div class="stats" id="invStats"></div>
      <p class="muted" id="invCompare" style="margin:0.55rem 0 0.65rem;font-size:0.85rem">—</p>
      <div style="overflow:auto;max-height:220px">
        <table>
          <thead>
            <tr><th>время</th><th>act</th><th>side</th><th>px</th><th>лот$</th><th>pnl</th></tr>
          </thead>
          <tbody id="invTradesBody"><tr><td colspan="6" class="muted">нет сделок</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="modal-back" id="adoptModal" role="dialog" aria-modal="true">
    <div class="modal" style="width:min(640px,100%)">
      <h3>Позиции и ордера на Lighter</h3>
      <p>При старте / обновлении страницы — снимок с биржи. Отметь, что включить в LIVE-сессию. Согласие сохранит позицию и ордера (лот, TP/SL) в таблицу <code>live_ex_*</code>.</p>
      <div id="exchangeSummary" class="pos-meta" style="margin:0 0 .75rem;padding:.55rem .65rem;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.7)"></div>
      <div id="adoptList"></div>
      <div class="actions">
        <button type="button" class="btn-skip" id="adoptSkip">Пропустить</button>
        <button type="button" class="btn-ok" id="adoptOk">Принять выбранные</button>
      </div>
    </div>
  </div>

  <script src="chart-candles.js?v=2"></script>
  <script src="chart-crosshair.js?v=2"></script>
  <script src="sim-1m-chart.js?v=12"></script>
  <script>
    const API = 'api.php';
    const STATUS_ACTION = 'live_1m_status';
    const START_ACTION = 'live_1m_start';
    const SET_LEVELS_ACTION = 'live_1m_set_levels';
    const LS_KEY = 'live_1m_levels';
    const LOT_OPTS = [50, 100, 150, 200, 250, 300, 350, 400];
    const SL_OPTS = [0.5, 1, 2, 3, 5, 10, 15, 20, 25, 30];
    const TP_OPTS = [0.5, 1, 2, 3, 5, 10, 15, 20, 25, 30];
    const TF_OPTS = ['1m', '5m'];
    const METHOD_SHORT = {
      'ROC(10) zero-cross': 'ROC',
      'SMA(10/30) cross': 'SMA',
      'EMA(12/26) cross': 'EMA',
      'MACD(12,26,9) cross': 'MACD',
      'RSI(14) 30/70': 'RSI',
      'Bollinger(20,2) bounce': 'BB',
      'Momentum(10) flip': 'Mom',
    };
    const METHOD_OPTS = Object.keys(METHOD_SHORT);
    let sessionId = null;
    try {
      const saved = localStorage.getItem('live_1m_session_id');
      if (saved) sessionId = Number(saved) || null;
    } catch (_) {}
    let running = false;
    let timer = null;
    let tickSec = 30;
    let levelsState = { lot: 200, sl: 30, tp: 30 };
    let selectedTf = '1m';
    let selectedMethodMode = 'flash';
    let selectedMethod = 'ROC(10) zero-cross';
    let selectedTradeMode = 'normal';
    try {
      const tfSaved = localStorage.getItem('live_1m_tf');
      if (TF_OPTS.includes(tfSaved)) selectedTf = tfSaved;
      const mm = localStorage.getItem('live_1m_method_mode');
      if (mm === 'flash' || mm === 'manual') selectedMethodMode = mm;
      const mSaved = localStorage.getItem('live_1m_method');
      if (METHOD_OPTS.includes(mSaved)) selectedMethod = mSaved;
      const tm = localStorage.getItem('live_1m_trade_mode');
      if (tm === 'normal' || tm === 'inverse') selectedTradeMode = tm;
    } catch (_) {}
    let lastEntry = null;
    let lastSide = null;
    let lastMark = null;
    let lastTp = null;
    let lastSl = null;

    const INV_FEE_BPS = 2.0;
    const INV_KILL_LO = -50;
    const INV_KILL_HI = 100;

    function emptyInvState(sid) {
      return {
        session_id: sid || null,
        side: null,
        entry: null,
        lot: 200,
        tp: null,
        sl: null,
        realized: 0,
        fees: 0,
        trades: [],
        last_tick_id: 0,
        stopped: false,
      };
    }

    let invState = emptyInvState(null);

    function invLevels(price, side, tpPct, slPct) {
      const tp = side === 'long' ? price * (1 + tpPct / 100) : price * (1 - tpPct / 100);
      const sl = side === 'long' ? price * (1 - slPct / 100) : price * (1 + slPct / 100);
      return { tp, sl };
    }

    function invClose(price, reason, ts) {
      if (!invState.side || !invState.entry) return;
      const dir = invState.side === 'short' ? -1 : 1;
      const lot = Number(invState.lot) || 200;
      const pnlGross = ((price - invState.entry) / invState.entry) * lot * dir;
      const fee = lot * (INV_FEE_BPS / 10000);
      const pnl = pnlGross - fee;
      invState.fees += fee;
      invState.realized += pnl;
      invState.trades.push({
        t: ts,
        action: 'close',
        side: invState.side,
        price,
        lot,
        pnl,
        reason,
      });
      invState.side = null;
      invState.entry = null;
      invState.tp = null;
      invState.sl = null;
    }

    function invOpen(side, price, lot, tpPct, slPct, reason, ts) {
      const fee = lot * (INV_FEE_BPS / 10000);
      invState.fees += fee;
      invState.realized -= fee;
      const lv = invLevels(price, side, tpPct, slPct);
      invState.side = side;
      invState.entry = price;
      invState.lot = lot;
      invState.tp = lv.tp;
      invState.sl = lv.sl;
      invState.trades.push({
        t: ts,
        action: 'open',
        side,
        price,
        lot,
        pnl: -fee,
        reason,
      });
    }

    function invUPnl(mark) {
      if (!invState.side || !invState.entry || !(mark > 0)) return 0;
      const dir = invState.side === 'short' ? -1 : 1;
      const lot = Number(invState.lot) || 200;
      return ((mark - invState.entry) / invState.entry) * lot * dir;
    }

    function invSessionPnl(mark) {
      return invState.realized + invUPnl(mark);
    }

    function feedInverseTick(tick, opts) {
      if (!tick || invState.stopped) return;
      const tid = Number(tick.id || 0);
      if (tid && tid <= Number(invState.last_tick_id || 0)) return;
      const price = Number(tick.price);
      if (!(price > 0)) return;
      const ts = tick.created_at || tick.bar_ts || 0;
      const lot = Number(opts.lot) || 200;
      const tpPct = Number(opts.tpPct);
      const slPct = Number(opts.slPct);
      const payload = tick.payload && typeof tick.payload === 'object' ? tick.payload : {};
      let impulse = payload.impulse_sig != null ? Number(payload.impulse_sig) : Number(tick.method_sig);
      if (!Number.isFinite(impulse)) impulse = 0;

      // TP / SL on mark
      if (invState.side && invState.entry) {
        if (invState.side === 'long') {
          if (invState.tp != null && price >= invState.tp) invClose(price, 'tp', ts);
          else if (invState.sl != null && price <= invState.sl) invClose(price, 'sl', ts);
        } else if (invState.side === 'short') {
          if (invState.tp != null && price <= invState.tp) invClose(price, 'tp', ts);
          else if (invState.sl != null && price >= invState.sl) invClose(price, 'sl', ts);
        }
      }

      let sessPnl = invSessionPnl(price);
      if (!invState.stopped && (sessPnl <= INV_KILL_LO || sessPnl >= INV_KILL_HI)) {
        if (invState.side) invClose(price, sessPnl <= INV_KILL_LO ? 'kill_lo' : 'kill_hi', ts);
        invState.stopped = true;
        if (tid) invState.last_tick_id = tid;
        return;
      }

      // Opposite to live impulse: +1 → short, -1 → long
      if (impulse > 0) {
        const want = 'short';
        if (invState.side !== want) {
          if (invState.side) invClose(price, 'flip_to_short', ts);
          if (!invState.stopped) invOpen(want, price, lot, tpPct, slPct, 'inv impulse+1→short', ts);
        }
      } else if (impulse < 0) {
        const want = 'long';
        if (invState.side !== want) {
          if (invState.side) invClose(price, 'flip_to_long', ts);
          if (!invState.stopped) invOpen(want, price, lot, tpPct, slPct, 'inv impulse-1→long', ts);
        }
      }

      sessPnl = invSessionPnl(price);
      if (!invState.stopped && (sessPnl <= INV_KILL_LO || sessPnl >= INV_KILL_HI)) {
        if (invState.side) invClose(price, sessPnl <= INV_KILL_LO ? 'kill_lo' : 'kill_hi', ts);
        invState.stopped = true;
      }
      if (tid) invState.last_tick_id = tid;
    }

    function replayInverseFromTicks(ticks, sess) {
      const sid = sess && sess.id != null ? Number(sess.id) : null;
      if (!sid) {
        invState = emptyInvState(null);
        renderInverse(sess, null);
        return;
      }
      const lot = Number(sess.lot_usd != null ? sess.lot_usd : levelsState.lot) || 200;
      const tpPct = Number(levelsState.tp != null ? levelsState.tp : sess.tp_pct) || 30;
      const slPct = Number(levelsState.sl != null ? levelsState.sl : sess.sl_pct) || 30;
      // Full replay each refresh (status window may grow); keep stops across same session.
      invState = emptyInvState(sid);
      const chrono = (ticks || []).slice().reverse(); // API newest-first
      for (const t of chrono) {
        feedInverseTick(t, { lot, tpPct, slPct });
      }
      const mark = chrono.length ? Number(chrono[chrono.length - 1].price) : lastMark;
      renderInverse(sess, mark);
    }

    function renderInverse(sess, mark) {
      const stats = $('invStats');
      const body = $('invTradesBody');
      const cmp = $('invCompare');
      if (!stats || !body) return;
      if (!invState.session_id) {
        stats.innerHTML = '';
        body.innerHTML = '<tr><td colspan="6" class="muted">нет сессии</td></tr>';
        if (cmp) cmp.textContent = '—';
        return;
      }
      const m = mark != null && Number.isFinite(Number(mark)) ? Number(mark) : lastMark;
      const u = invUPnl(m);
      const sp = invSessionPnl(m);
      const pos = invState.side
        ? `${invState.side} @ ${fmt(invState.entry, 4)} · tp ${fmt(invState.tp, 4)} / sl ${fmt(invState.sl, 4)}`
        : (invState.stopped ? 'stopped flat' : 'flat');
      stats.innerHTML = `
        <div class="stat"><div class="k">inv session PnL</div><div class="v ${clsPnL(sp)}">${fmt(sp)}$</div></div>
        <div class="stat"><div class="k">inv realized</div><div class="v ${clsPnL(invState.realized)}">${fmt(invState.realized)}$</div></div>
        <div class="stat"><div class="k">inv fees</div><div class="v">${fmt(invState.fees)}$</div></div>
        <div class="stat"><div class="k">inv uPnL</div><div class="v ${clsPnL(u)}">${fmt(u)}$</div></div>
        <div class="stat"><div class="k">inv position</div><div class="v" style="font-size:0.9rem">${pos}</div></div>
        <div class="stat"><div class="k">inv trades</div><div class="v">${invState.trades.length}${invState.stopped ? ' · kill' : ''}</div></div>
      `;
      const rows = invState.trades.slice().reverse();
      body.innerHTML = rows.length
        ? rows.map((t) => `<tr>
            <td class="mono">${ts(t.t)}</td>
            <td>${t.action}<div class="muted">${t.reason || ''}</div></td>
            <td>${t.side || '—'}</td>
            <td class="mono">${fmt(t.price, 4)}</td>
            <td class="mono">${fmt(t.lot, 0)}</td>
            <td class="mono ${clsPnL(t.pnl)}">${fmt(t.pnl)}</td>
          </tr>`).join('')
        : '<tr><td colspan="6" class="muted">нет сделок (ждём impulse)</td></tr>';
      if (cmp) {
        const live = sess && sess.session_pnl != null ? Number(sess.session_pnl) : null;
        cmp.innerHTML = live == null
          ? `inverse PnL <b class="${clsPnL(sp)}">${fmt(sp)}$</b>`
          : `live PnL <b class="${clsPnL(live)}">${fmt(live)}$</b> · inverse <b class="${clsPnL(sp)}">${fmt(sp)}$</b> · Δ <b class="${clsPnL(sp - live)}">${fmt(sp - live)}$</b>`;
      }
    }

    const $ = (id) => document.getElementById(id);
    const fmt = (n, d = 2) => (n == null || Number.isNaN(Number(n))) ? '—' : Number(n).toFixed(d);

    function syncTfUi(tf) {
      if (!TF_OPTS.includes(tf)) return;
      selectedTf = tf;
      try { localStorage.setItem('live_1m_tf', tf); } catch (_) {}
      document.querySelectorAll('#tfSwitch .tf-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.getAttribute('data-tf') === tf);
      });
    }

    async function applyTf(tf) {
      if (!TF_OPTS.includes(tf) || tf === selectedTf) {
        syncTfUi(tf);
        return;
      }
      syncTfUi(tf);
      if (window.Sim1mChart) {
        const m = selectedMethodMode === 'manual'
          ? selectedMethod
          : (Sim1mChart.currentMethod ? Sim1mChart.currentMethod() : selectedMethod);
        Sim1mChart.setLead(m, tf, { force: true });
      }
      if (!sessionId) return;
      const btns = document.querySelectorAll('#tfSwitch .tf-btn');
      btns.forEach((b) => { b.disabled = true; });
      try {
        const res = await api('live_1m_set_resolution', {
          session_id: String(sessionId),
          resolution: tf,
        });
        if (!res.ok) {
          alert(res.error || 'не удалось сменить ТФ');
          return;
        }
        await refresh();
      } catch (e) {
        alert(String(e.message || e));
      } finally {
        btns.forEach((b) => { b.disabled = false; });
      }
    }

    function syncTradeModeUi(mode) {
      if (mode === 'normal' || mode === 'inverse') selectedTradeMode = mode;
      try { localStorage.setItem('live_1m_trade_mode', selectedTradeMode); } catch (_) {}
      document.querySelectorAll('#tradeModeSwitch .tf-btn').forEach((btn) => {
        btn.classList.toggle('active', btn.getAttribute('data-trade-mode') === selectedTradeMode);
      });
      const pill = $('modePill');
      if (pill) {
        pill.textContent = selectedTradeMode === 'inverse' ? 'режим: LIVE · INV' : 'режим: LIVE';
      }
    }

    async function applyTradeMode(mode) {
      if (mode !== 'normal' && mode !== 'inverse') return;
      syncTradeModeUi(mode);
      if (!sessionId) return;
      const btns = document.querySelectorAll('#tradeModeSwitch .tf-btn');
      btns.forEach((b) => { b.disabled = true; });
      try {
        const res = await api('live_1m_set_trade_mode', {
          session_id: String(sessionId),
          trade_mode: mode,
        });
        if (!res.ok) {
          alert(res.error || 'не удалось сменить режим входа');
          return;
        }
        await refresh();
      } catch (e) {
        alert(String(e.message || e));
      } finally {
        btns.forEach((b) => { b.disabled = false; });
      }
    }

    function syncMethodUi(mode, method) {
      if (mode === 'flash' || mode === 'manual') selectedMethodMode = mode;
      if (METHOD_OPTS.includes(method)) selectedMethod = method;
      try {
        localStorage.setItem('live_1m_method_mode', selectedMethodMode);
        localStorage.setItem('live_1m_method', selectedMethod);
      } catch (_) {}
      document.querySelectorAll('#methodSwitch .tf-btn').forEach((btn) => {
        const bMode = btn.getAttribute('data-mode');
        const bMethod = btn.getAttribute('data-method') || '';
        let on = false;
        if (selectedMethodMode === 'flash') on = bMode === 'flash';
        else on = bMode === 'manual' && bMethod === selectedMethod;
        btn.classList.toggle('active', on);
      });
    }

    async function applyMethod(mode, method) {
      if (mode !== 'flash' && mode !== 'manual') return;
      if (mode === 'manual' && !METHOD_OPTS.includes(method)) return;
      const nextMethod = mode === 'manual' ? method : (selectedMethod || 'ROC(10) zero-cross');
      syncMethodUi(mode, nextMethod);
      if (window.Sim1mChart && mode === 'manual') {
        Sim1mChart.setLead(nextMethod, selectedTf || '1m', { force: true });
      }
      if (!sessionId) return;
      const btns = document.querySelectorAll('#methodSwitch .tf-btn');
      btns.forEach((b) => { b.disabled = true; });
      try {
        const params = {
          session_id: String(sessionId),
          mode,
        };
        if (mode === 'manual') params.method = nextMethod;
        else params.method = '';
        const res = await api('live_1m_set_method', params);
        if (!res.ok) {
          alert(res.error || 'не удалось сменить метод');
          return;
        }
        if (res.method) selectedMethod = res.method;
        syncMethodUi(mode, selectedMethod);
        await refresh();
      } catch (e) {
        alert(String(e.message || e));
      } finally {
        btns.forEach((b) => { b.disabled = false; });
      }
    }

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
        // Apply currently selected lot to open position (no cycle).
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
      // In position: TP/SL from entry × selected %. Flat: preview from mark.
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
        if (sessionId) localStorage.setItem('live_1m_session_id', String(sessionId));
        else localStorage.removeItem('live_1m_session_id');
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
        replayInverseFromTicks([], null);
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
      if (!lastMark && ticks[0] && ticks[0].price != null) lastMark = Number(ticks[0].price);
      else if (ticks[0] && ticks[0].price != null) lastMark = Number(ticks[0].price);
      chartLevelsFromState();
      // Leading method from session / latest tick (Flash or manual).
      const leadingMethod = s.method || (ticks[0] && ticks[0].method) || selectedMethod || 'ROC(10) zero-cross';
      const leadingRes = s.resolution || (ticks[0] && ticks[0].resolution) || '1m';
      let cfg = {};
      try {
        cfg = typeof s.config_json === 'string' ? JSON.parse(s.config_json || '{}') : (s.config_json || {});
      } catch (_) { cfg = {}; }
      const modeFromSess = (cfg.method_mode === 'manual' || cfg.method_mode === 'flash')
        ? cfg.method_mode
        : selectedMethodMode;
      syncMethodUi(modeFromSess, leadingMethod);
      const tmFromSess = (cfg.trade_mode === 'inverse' || cfg.trade_mode === 'normal')
        ? cfg.trade_mode
        : selectedTradeMode;
      syncTradeModeUi(tmFromSess);
      const methodPill = $('methodPill');
      if (methodPill) {
        const short = METHOD_SHORT[leadingMethod] || leadingMethod;
        const prefix = modeFromSess === 'manual' ? 'Manual' : 'Flash';
        methodPill.textContent = prefix + ' · ' + short + ' @ ' + leadingRes;
        methodPill.title = (modeFromSess === 'manual' ? 'ручной' : 'DeepSeek Flash')
          + ' → ' + leadingMethod + ' @ ' + leadingRes;
      }
      if (window.Sim1mChart) {
        Sim1mChart.setLead(leadingMethod, leadingRes);
      }
      syncTfUi(leadingRes);

      const methodLabel = (name) => METHOD_SHORT[name] || name || '—';

      $('ticksBody').innerHTML = ticks.length
        ? ticks.map(t => {
            const m = t.method || leadingMethod;
            const r = t.resolution || leadingRes;
            const sig = t.method_sig;
            const sigTxt = sig == null ? '—' : (sig > 0 ? `+${sig}` : String(sig));
            const psz = t.position_size != null ? t.position_size
              : (t.payload && t.payload.position_size != null ? t.payload.position_size : null);
            return `<tr>
            <td class="mono">${ts(t.created_at)}</td>
            <td title="${m} @ ${r}">${methodLabel(m)} <span class="muted">${r}</span><div class="muted mono">sig ${sigTxt}</div></td>
            <td class="mono">${fmt(t.price, 4)}</td>
            <td class="mono">${sigTxt}</td>
            <td class="mono">${psz != null ? fmt(psz, 4) : (t.position_side || '—')}</td>
            <td>${t.action || '—'}<div class="muted">${t.reason || ''}</div></td>
            <td class="mono ${clsPnL(t.u_pnl)}">${fmt(t.u_pnl)}</td>
            <td class="mono ${clsPnL(t.session_pnl)}">${fmt(t.session_pnl)}</td>
          </tr>`;
          }).join('')
        : '<tr><td colspan="8" class="muted">нет тиков</td></tr>';

      const trades = data.trades || [];
      $('tradesBody').innerHTML = trades.length
        ? trades.map(t => `<tr>
            <td class="mono">${ts(t.created_at)}</td>
            <td>${t.action}</td>
            <td>${t.side || '—'}</td>
            <td class="mono">${fmt(t.price, 4)}</td>
            <td class="mono">${t.size != null ? fmt(t.size, 4) : '—'}</td>
            <td class="mono">${t.quote_usd != null ? fmt(t.quote_usd, 0) : '—'}</td>
            <td class="mono ${clsPnL(t.pnl)}">${fmt(t.pnl)}</td>
          </tr>`).join('')
        : '<tr><td colspan="7" class="muted">нет сделок</td></tr>';

      const logs = data.logs || [];
      $('logs').innerHTML = logs.length
        ? logs.map(l => `${ts(l.created_at)} [${l.level}] ${l.message}`).join('<br>')
        : '—';

      replayInverseFromTicks(ticks, s);
    }

    async function api(action, params = {}) {
      const q = new URLSearchParams({ action, ...params });
      const r = await fetch(`${API}?${q.toString()}`, { cache: 'no-store' });
      return r.json();
    }

    function renderExchangeOfficial(ex) {
      const meta = $('exOfficialMeta');
      const title = $('exOfficialTitle');
      const stats = $('exPositionStats');
      const body = $('exOrdersBody');
      if (!meta || !stats || !body) return;
      if (!ex || !ex.ok) {
        meta.textContent = (ex && ex.error) ? ('ошибка: ' + ex.error) : 'нет данных';
        meta.classList.add('error');
        return;
      }
      meta.classList.remove('error');
      const p = ex.position;
      const s = ex.summary || {};
      const mid = ex.market_id != null ? ex.market_id : 120;
      if (title) title.textContent = ((p && p.symbol) ? p.symbol : 'LIT') + ' #' + mid;
      const when = ex.fetched_at ? ts(ex.fetched_at) : 'сейчас';
      meta.textContent = `обновлено ${when} · ордеров ${s.orders_count ?? 0} · TP ${s.tp_count ?? 0}/SL ${s.sl_count ?? 0}`;

      if (!p) {
        stats.innerHTML = `
          <div class="stat"><div class="k">позиция</div><div class="v">flat</div></div>
          <div class="stat"><div class="k">лот позиции</div><div class="v">—</div></div>
          <div class="stat"><div class="k">uPnL</div><div class="v">—</div></div>
          <div class="stat"><div class="k">ордера (лот∑)</div><div class="v">$${fmt(s.orders_lot_usd, 2)}</div></div>
        `;
      } else {
        const up = p.unrealized_pnl != null ? p.unrealized_pnl : p.u_pnl;
        stats.innerHTML = `
          <div class="stat"><div class="k">позиция</div><div class="v">${p.side || '—'} · ${fmt(p.size, 4)}</div></div>
          <div class="stat"><div class="k">entry</div><div class="v mono">${fmt(p.entry_price, 4)}</div></div>
          <div class="stat"><div class="k">лот ≈$</div><div class="v">${fmt(p.lot_usd, 2)}</div></div>
          <div class="stat"><div class="k">uPnL</div><div class="v ${clsPnL(up)}">${fmt(up)}</div></div>
          <div class="stat"><div class="k">value</div><div class="v">${fmt(p.position_value, 2)}</div></div>
          <div class="stat"><div class="k">liq</div><div class="v mono">${fmt(p.liquidation_price, 4)}</div></div>
          <div class="stat"><div class="k">ордера</div><div class="v">${s.orders_count ?? 0} · ∑$${fmt(s.orders_lot_usd, 2)}</div></div>
        `;
      }

      const orders = ex.orders || [];
      body.innerHTML = orders.length
        ? orders.map((o) => {
            const trig = o.trigger_price != null ? o.trigger_price : null;
            const px = o.price != null ? o.price : null;
            return `<tr>
              <td>${o.order_type || '—'}</td>
              <td class="mono">${o.side || (o.is_ask ? 'ask' : 'bid')}</td>
              <td class="mono">${o.remaining_size != null ? fmt(o.remaining_size, 4) : fmt(o.size, 4)}</td>
              <td class="mono">${o.lot_usd != null ? fmt(o.lot_usd, 2) : '—'}</td>
              <td class="mono">${trig != null ? fmt(trig, 4) : '—'}</td>
              <td class="mono">${px != null ? fmt(px, 4) : '—'}</td>
              <td>${o.status || '—'}${o.reduce_only ? ' · RO' : ''}</td>
              <td class="mono" style="font-size:0.72rem">${o.order_index ?? '—'}</td>
            </tr>`;
          }).join('')
        : '<tr><td colspan="8" class="muted">нет активных ордеров</td></tr>';
    }

    async function refreshExchangeOfficial() {
      try {
        const ex = await api('live_1m_exchange', { market_id: '120' });
        renderExchangeOfficial(ex);
      } catch (e) {
        renderExchangeOfficial({ ok: false, error: String(e) });
      }
    }

    async function refresh() {
      const data = await api('live_1m_status', sessionId ? { session_id: String(sessionId), ticks: '200' } : { ticks: '200' });
      if (data.ok) renderStatus(data);
      await refreshExchangeOfficial();
    }

    async function doTick() {
      if (!running) return;
      try {
        // Background live_1m_loop.php places orders. Browser only polls status
        // when the loop is alive — avoids double flip (2× size on Lighter).
        const st = await api('live_1m_status', sessionId ? { session_id: String(sessionId), ticks: '200' } : { ticks: '200' });
        if (st.ok) renderStatus(st);
        const loopAlive = !!(st.loop && st.loop.alive);
        if (!loopAlive) {
          await api('live_1m_tick', sessionId ? { session_id: String(sessionId) } : {});
          await refresh();
        } else {
          await refreshExchangeOfficial();
        }
      } catch (e) {
        console.error(e);
      }
    }

    function armTimer() {
      if (timer) clearInterval(timer);
      // Live cadence: page refresh + signal = 30s; Flash pick ≈ every 5 min
      const sec = Math.max(30, Number(tickSec) || 30);
      tickSec = sec;
      timer = setInterval(doTick, sec * 1000);
    }

    $('btnStart').onclick = async () => {
      $('btnStart').disabled = true;
      $('methodPill').textContent = selectedMethodMode === 'flash' ? 'Flash · выбирает…' : ('Manual · ' + (METHOD_SHORT[selectedMethod] || selectedMethod));
      try {
        const res = await api(START_ACTION, {
          market_id: '120',
          lot_usd: String(levelsState.lot),
          tick_sec: '30',
          resolution: selectedTf || '1m',
          method: selectedMethod || 'ROC(10) zero-cross',
          method_mode: selectedMethodMode || 'flash',
          trade_mode: selectedTradeMode || 'normal',
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
        if (m && window.Sim1mChart) {
          const tf = res.resolution || selectedTf || '1m';
          syncTfUi(tf);
          Sim1mChart.setLead(m, tf, { force: true });
        }
        // Show exchange positions/orders for explicit adopt into live_ex_* tables
        const cand = await api('live_1m_candidates', { session_id: String(sessionId) });
        const list = (cand && cand.candidates) || [];
        const ex = (cand && cand.exchange) || null;
        if (list.length || (ex && (ex.position || (ex.orders || []).length))) {
          const chosen = await askAdopt(list, ex);
          if (chosen !== null) {
            const adopted = await api('live_1m_adopt', {
              session_id: String(sessionId),
              selected: JSON.stringify(chosen),
            });
            if (adopted.ok) renderStatus(adopted);
          }
        }
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
        const res = await api('live_1m_stop', sessionId ? { session_id: String(sessionId) } : {});
        if (timer) clearInterval(timer);
        timer = null;
        setRunUi(false);
        const v = res.verify || {};
        if (v.flat) {
          console.info('stop flatten OK', res.flatten);
        } else {
          alert('Стоп: на бирже ещё осталось — позиция или ордера. Проверь блок «официально с Lighter».');
          console.warn('stop not flat', res.flatten, v);
        }
        await refresh();
      } catch (e) {
        alert(String(e));
        $('btnStop').disabled = false;
      }
    };

    $('btnCloseAll').onclick = async () => {
      if (!confirm('Close all: закрыть все позиции и снять все ордера на Lighter?')) return;
      const btn = $('btnCloseAll');
      btn.disabled = true;
      const meta = $('exOfficialMeta');
      if (meta) meta.textContent = 'Close all…';
      try {
        const res = await api('live_1m_close_all', {
          market_id: '120',
          ...(sessionId ? { session_id: String(sessionId) } : {}),
        });
        if (!res.ok) {
          alert(res.error || 'close all failed');
          return;
        }
        if (!res.flat) {
          alert('Close all: на бирже ещё осталось — позиция или ордера. Обнови блок и проверь.');
          console.warn('close_all not flat', res.flatten, res.verify);
        }
        await refresh();
        await refreshExchangeOfficial();
      } catch (e) {
        alert(String(e.message || e));
      } finally {
        btn.disabled = false;
      }
    };

    async function boot() {
      const params = sessionId ? { session_id: String(sessionId) } : {};
      let data = await api('live_1m_resume', params);
      if (!data.ok || (!data.resumed && sessionId)) {
        data = await api('live_1m_resume', {});
      }
      if (data.ok) {
        renderStatus(data);
        if (data.resumed || (data.session && data.session.status === 'running')) {
          persistSession(data.session_id || data.session.id);
        }
      } else {
        await refresh();
      }

      const cand = await api('live_1m_candidates', sessionId ? { session_id: String(sessionId) } : {});
      const list = (cand && cand.candidates) || [];
      const ex = (cand && cand.exchange) || null;
      if (list.length || (ex && (ex.position || (ex.orders || []).length))) {
        const chosen = await askAdopt(list, ex);
        if (chosen !== null) {
          const adopted = await api('live_1m_adopt', {
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

    function orderLines(orders) {
      if (!orders || !orders.length) return '<div class="pos-meta">ордеров нет</div>';
      return orders.map((o) => {
        const trig = o.trigger_price != null ? o.trigger_price : o.price;
        return `<div class="pos-meta mono" style="margin-top:.25rem">
          ${o.order_type || '?'} · idx ${o.order_index ?? '—'}
          · rem ${o.remaining_size != null ? fmt(o.remaining_size, 4) : '—'}
          · trig/px ${trig != null ? fmt(trig, 4) : '—'}
          · лот≈$${o.lot_usd != null ? fmt(o.lot_usd, 2) : '—'}
          · ${o.status || ''} ${o.reduce_only ? 'RO' : ''}
        </div>`;
      }).join('');
    }

    function askAdopt(candidates, exchange) {
      return new Promise((resolve) => {
        const back = $('adoptModal');
        const box = $('adoptList');
        const sum = $('exchangeSummary');
        if (sum) {
          if (exchange && exchange.ok) {
            const p = exchange.position;
            const s = exchange.summary || {};
            sum.innerHTML = p
              ? `<b>Биржа</b>: ${p.side} ${p.symbol || ''} size=${fmt(p.size, 4)} entry=${fmt(p.entry_price, 4)}
                 лот≈$${fmt(p.lot_usd, 2)} uPnL=${fmt(p.u_pnl ?? p.unrealized_pnl, 2)}
                 · ордеров ${s.orders_count ?? 0} (TP ${s.tp_count ?? 0}/SL ${s.sl_count ?? 0})
                 · sum ордеров ≈$${fmt(s.orders_lot_usd, 2)}`
              : `<b>Биржа</b>: позиций нет · ордеров ${s.orders_count ?? 0}`;
          } else {
            sum.textContent = 'снимок биржи недоступен';
          }
        }
        box.innerHTML = (candidates || []).map((c) => {
          const id = String(c.id).replace(/"/g, '');
          const checked = c.default_checked ? 'checked' : '';
          const meta = [
            c.source,
            c.side,
            c.symbol || '',
            c.size != null ? 'size ' + fmt(c.size, 4) : '',
            c.lot_usd != null ? 'лот≈$' + fmt(c.lot_usd, 2) : '',
            c.entry != null ? 'entry ' + fmt(c.entry, 4) : '',
            c.u_pnl != null ? 'uPnL ' + fmt(c.u_pnl, 2) : '',
            c.on_exchange === false ? 'нет на Lighter' : '',
          ].filter(Boolean).join(' · ');
          return `<label class="pos"><input type="checkbox" value="${id}" ${checked}>
            <span><b>${c.label || id}</b><div class="pos-meta">${meta}</div>${orderLines(c.orders)}</span></label>`;
        }).join('') || '<div class="muted">кандидатов в сессии нет — только снимок биржи выше</div>';
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
    syncTfUi(selectedTf);
    syncMethodUi(selectedMethodMode, selectedMethod);
    syncTradeModeUi(selectedTradeMode);
    document.querySelectorAll('#tfSwitch .tf-btn').forEach((btn) => {
      btn.addEventListener('click', () => applyTf(btn.getAttribute('data-tf')));
    });
    document.querySelectorAll('#methodSwitch .tf-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyMethod(btn.getAttribute('data-mode'), btn.getAttribute('data-method') || '');
      });
    });
    document.querySelectorAll('#tradeModeSwitch .tf-btn').forEach((btn) => {
      btn.addEventListener('click', () => applyTradeMode(btn.getAttribute('data-trade-mode')));
    });
    refreshExchangeOfficial();
    boot();
    if (window.Sim1mChart) {
      Sim1mChart.start({
        method: selectedMethodMode === 'manual' ? selectedMethod : 'ROC(10) zero-cross',
        resolution: selectedTf || '1m',
        refreshSec: 30,
      });
    }
  </script>
</body>
</html>

