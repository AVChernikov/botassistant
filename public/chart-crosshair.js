(function () {
  'use strict';

  const STYLE_ID = 'chart-crosshair-style';
  const LINE_CLASS = 'chart-crosshair-line';
  const HUD_CLASS = 'chart-crosshair-hud';
  const ACTIVE_CLASS = 'is-crosshair-active';
  const DEFAULT_PAD = { left: 10, right: 60 };

  /** @type {WeakMap<Element, {times?: number[], series?: {name:string,values:(number|null)[],digits?:number,color?:string}[]}>} */
  const seriesMap = new WeakMap();

  let bound = false;
  let activeWrap = null;
  let lastRatio = 0;

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      .chart-wrap {
        position: relative;
        --pad-l: 10px;
        --pad-r: 60px;
      }
      .${LINE_CLASS} {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 0;
        border-left: 1px dashed rgba(21, 32, 25, 0.55);
        pointer-events: none;
        display: none;
        z-index: 6;
        transform: translateX(-0.5px);
      }
      .chart-wrap.${ACTIVE_CLASS} .${LINE_CLASS} {
        border-left-color: rgba(15, 107, 76, 0.85);
      }
      .${HUD_CLASS} {
        position: absolute;
        top: 6px;
        z-index: 7;
        pointer-events: none;
        display: none;
        max-width: min(280px, 70%);
        padding: 4px 8px;
        border-radius: 6px;
        background: rgba(246, 248, 246, 0.94);
        border: 1px solid rgba(21, 32, 25, 0.18);
        box-shadow: 0 1px 4px rgba(21, 32, 25, 0.08);
        font: 11px/1.35 "IBM Plex Mono", ui-monospace, monospace;
        color: #152019;
        white-space: nowrap;
      }
      .${HUD_CLASS} .t { color: #5c6b61; margin-bottom: 1px; }
      .${HUD_CLASS} .row { display: flex; gap: 0.45rem; align-items: baseline; }
      .${HUD_CLASS} .k { color: #5c6b61; }
      .${HUD_CLASS} .v { font-weight: 600; }
    `;
    document.head.appendChild(style);
  }

  function wraps(root) {
    return Array.from((root || document).querySelectorAll('.chart-wrap'));
  }

  function ensureLine(wrap) {
    let line = wrap.querySelector('.' + LINE_CLASS);
    if (!line) {
      line = document.createElement('div');
      line.className = LINE_CLASS;
      wrap.appendChild(line);
    }
    return line;
  }

  function ensureHud(wrap) {
    let hud = wrap.querySelector('.' + HUD_CLASS);
    if (!hud) {
      hud = document.createElement('div');
      hud.className = HUD_CLASS;
      wrap.appendChild(hud);
    }
    return hud;
  }

  function readPad(wrap) {
    const style = getComputedStyle(wrap);
    const left = parseFloat(style.getPropertyValue('--pad-l'));
    const right = parseFloat(style.getPropertyValue('--pad-r'));
    return {
      left: Number.isFinite(left) ? left : DEFAULT_PAD.left,
      right: Number.isFinite(right) ? right : DEFAULT_PAD.right,
    };
  }

  function setPad(wrap, pad) {
    if (!pad) return;
    if (pad.left != null) wrap.style.setProperty('--pad-l', `${pad.left}px`);
    if (pad.right != null) wrap.style.setProperty('--pad-r', `${pad.right}px`);
  }

  function setPoints(wrap, points) {
    if (!wrap) return;
    const n = Number(points);
    if (Number.isFinite(n) && n > 0) {
      wrap.dataset.points = String(Math.floor(n));
    } else {
      delete wrap.dataset.points;
    }
  }

  function setSeries(wrap, data) {
    if (!wrap) return;
    if (!data) {
      seriesMap.delete(wrap);
      return;
    }
    seriesMap.set(wrap, {
      times: Array.isArray(data.times) ? data.times : undefined,
      series: Array.isArray(data.series) ? data.series : [],
    });
    if (data.points != null) setPoints(wrap, data.points);
    else if (data.times && data.times.length) setPoints(wrap, data.times.length);
    else if (data.series && data.series[0] && data.series[0].values) {
      setPoints(wrap, data.series[0].values.length);
    }
  }

  function plotMetrics(wrap) {
    const rect = wrap.getBoundingClientRect();
    const pad = readPad(wrap);
    const plotW = Math.max(1, rect.width - pad.left - pad.right);
    return { rect, pad, plotW };
  }

  function indexFromRatio(wrap, ratio) {
    const points = Number(wrap.dataset.points || 0);
    if (!(points > 0)) return -1;
    return Math.max(0, Math.min(points - 1, Math.floor(ratio * points)));
  }

  function ratioFromClientX(wrap, clientX) {
    const { rect, pad, plotW } = plotMetrics(wrap);
    let x = clientX - rect.left - pad.left;
    const points = Number(wrap.dataset.points || 0);
    if (points > 0) {
      const slot = plotW / points;
      const idx = Math.max(0, Math.min(points - 1, Math.floor(x / slot)));
      x = idx * slot + slot / 2;
    }
    return Math.max(0, Math.min(1, x / plotW));
  }

  function fmtNum(n, digits) {
    const num = Number(n);
    if (!Number.isFinite(num)) return '—';
    const d = Number.isFinite(digits) ? digits : (Math.abs(num) >= 100 ? 2 : 4);
    return num.toLocaleString('en-US', { maximumFractionDigits: d, minimumFractionDigits: 0 });
  }

  function fmtTime(ts) {
    let ms = Number(ts);
    if (!Number.isFinite(ms)) return '';
    if (ms > 0 && ms < 1e12) ms *= 1000;
    if (ms > 1e15) ms = Math.floor(ms / 1000);
    const d = new Date(ms);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  }

  function renderHud(wrap, ratio) {
    const hud = ensureHud(wrap);
    const data = seriesMap.get(wrap);
    const idx = indexFromRatio(wrap, ratio);
    if (!data || idx < 0) {
      hud.style.display = 'none';
      return;
    }

    const parts = [];
    const t = data.times && data.times[idx] != null ? fmtTime(data.times[idx]) : '';
    if (t) parts.push(`<div class="t">${t}</div>`);

    for (const s of data.series || []) {
      const vals = s.values || [];
      const raw = vals[idx];
      const name = s.name || '';
      const color = s.color ? ` style="color:${s.color}"` : '';
      parts.push(
        `<div class="row"><span class="k">${esc(name)}</span>` +
          `<span class="v"${color}>${fmtNum(raw, s.digits)}</span></div>`,
      );
    }

    if (!parts.length) {
      hud.style.display = 'none';
      return;
    }

    hud.innerHTML = parts.join('');
    const { pad, plotW } = plotMetrics(wrap);
    const x = pad.left + ratio * plotW;
    const hudW = Math.min(280, wrap.clientWidth * 0.7);
    let left = x + 10;
    if (left + hudW > wrap.clientWidth - 4) left = x - hudW - 10;
    left = Math.max(4, Math.min(left, wrap.clientWidth - hudW - 4));
    hud.style.left = `${left}px`;
    hud.style.display = 'block';
  }

  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function applyRatio(wrap, ratio, isSource) {
    const line = ensureLine(wrap);
    const { pad, plotW } = plotMetrics(wrap);
    const x = pad.left + ratio * plotW;
    line.style.left = `${x}px`;
    line.style.display = 'block';
    wrap.classList.toggle(ACTIVE_CLASS, !!isSource);
    renderHud(wrap, ratio);
  }

  function hideAll(root) {
    for (const wrap of wraps(root)) {
      const line = wrap.querySelector('.' + LINE_CLASS);
      if (line) line.style.display = 'none';
      const hud = wrap.querySelector('.' + HUD_CLASS);
      if (hud) hud.style.display = 'none';
      wrap.classList.remove(ACTIVE_CLASS);
    }
    activeWrap = null;
  }

  function sync(sourceWrap, clientX, root) {
    const ratio = ratioFromClientX(sourceWrap, clientX);
    lastRatio = ratio;
    for (const wrap of wraps(root)) {
      applyRatio(wrap, ratio, wrap === sourceWrap);
    }
    activeWrap = sourceWrap;
  }

  function onMove(event) {
    const wrap = event.target.closest?.('.chart-wrap');
    if (!wrap || !document.body.contains(wrap)) return;
    sync(wrap, event.clientX, document);
  }

  function onLeave(event) {
    const wrap = event.target.closest?.('.chart-wrap');
    if (!wrap) return;
    const related = event.relatedTarget;
    if (related && related.closest?.('.chart-wrap')) return;
    const toWrap = related?.closest?.('.chart-wrap');
    if (!toWrap) hideAll(document);
  }

  function bind() {
    ensureStyle();
    for (const wrap of wraps(document)) {
      ensureLine(wrap);
      ensureHud(wrap);
    }
    if (bound) return;
    document.addEventListener('pointermove', onMove, { passive: true });
    document.addEventListener('pointerleave', (event) => {
      if (event.target === document.documentElement || event.target === document.body) {
        hideAll(document);
      }
    }, true);
    document.addEventListener('pointerout', onLeave, true);
    window.addEventListener('blur', () => hideAll(document));
    bound = true;
  }

  function refresh(root) {
    ensureStyle();
    for (const wrap of wraps(root || document)) {
      ensureLine(wrap);
      ensureHud(wrap);
    }
    if (activeWrap && document.body.contains(activeWrap)) {
      for (const wrap of wraps(root || document)) {
        applyRatio(wrap, lastRatio, wrap === activeWrap);
      }
    }
  }

  function mark(canvasOrWrap, options = {}) {
    const wrap = canvasOrWrap?.classList?.contains('chart-wrap')
      ? canvasOrWrap
      : canvasOrWrap?.closest?.('.chart-wrap');
    if (!wrap) return;
    ensureStyle();
    ensureLine(wrap);
    ensureHud(wrap);
    if (options.pad) setPad(wrap, options.pad);
    if (options.points != null) setPoints(wrap, options.points);
    if (options.times || options.series) {
      setSeries(wrap, {
        times: options.times,
        series: options.series,
        points: options.points,
      });
    }
  }

  window.ChartCrosshair = { bind, refresh, mark, setSeries, hideAll };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
