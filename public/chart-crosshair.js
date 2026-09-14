(function () {
  'use strict';

  const STYLE_ID = 'chart-crosshair-style';
  const LINE_CLASS = 'chart-crosshair-line';
  const ACTIVE_CLASS = 'is-crosshair-active';
  const DEFAULT_PAD = { left: 10, right: 60 };

  let bound = false;
  let activeWrap = null;

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

  function plotMetrics(wrap) {
    const rect = wrap.getBoundingClientRect();
    const pad = readPad(wrap);
    const plotW = Math.max(1, rect.width - pad.left - pad.right);
    return { rect, pad, plotW };
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

  function applyRatio(wrap, ratio, isSource) {
    const line = ensureLine(wrap);
    const { pad, plotW } = plotMetrics(wrap);
    const x = pad.left + ratio * plotW;
    line.style.left = `${x}px`;
    line.style.display = 'block';
    wrap.classList.toggle(ACTIVE_CLASS, !!isSource);
  }

  function hideAll(root) {
    for (const wrap of wraps(root)) {
      const line = wrap.querySelector('.' + LINE_CLASS);
      if (line) line.style.display = 'none';
      wrap.classList.remove(ACTIVE_CLASS);
    }
    activeWrap = null;
  }

  function sync(sourceWrap, clientX, root) {
    const ratio = ratioFromClientX(sourceWrap, clientX);
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
    // Leave the whole chart area only when pointer is outside every chart.
    const toWrap = related?.closest?.('.chart-wrap');
    if (!toWrap) hideAll(document);
  }

  function bind() {
    ensureStyle();
    for (const wrap of wraps(document)) ensureLine(wrap);
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
    for (const wrap of wraps(root || document)) ensureLine(wrap);
  }

  function mark(canvasOrWrap, options = {}) {
    const wrap = canvasOrWrap?.classList?.contains('chart-wrap')
      ? canvasOrWrap
      : canvasOrWrap?.closest?.('.chart-wrap');
    if (!wrap) return;
    ensureStyle();
    ensureLine(wrap);
    if (options.pad) setPad(wrap, options.pad);
    if (options.points != null) setPoints(wrap, options.points);
  }

  window.ChartCrosshair = { bind, refresh, mark, hideAll };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
