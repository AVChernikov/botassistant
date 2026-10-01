/**
 * sim-1m chart: LIT 1m candles + leading method overlay.
 * Depends on ChartCandles (optional) + fetch api.php?action=live
 */
(function () {
  'use strict';

  const DEFAULTS = {
    marketId: 120,
    resolution: '1m',
    method: 'ROC(10) zero-cross',
    candleCount: 120,
    visible: 80,
  };

  let state = {
    method: DEFAULTS.method,
    resolution: DEFAULTS.resolution,
    timer: null,
    loading: false,
    pending: null,
    lastCandles: [],
    /** @type {{price:number,color:string,dash?:number[],label?:string,width?:number}[]} */
    levels: [],
  };

  function $(id) {
    return document.getElementById(id);
  }

  function fmt(n, d = 2) {
    const num = Number(n);
    if (!Number.isFinite(num)) return '—';
    return num.toLocaleString('en-US', { maximumFractionDigits: d });
  }

  function prepareCanvas(canvas) {
    const wrap = canvas.parentElement;
    const dpr = window.devicePixelRatio || 1;
    const cssW = wrap.clientWidth || 800;
    const cssH = wrap.clientHeight || 160;
    canvas.width = Math.floor(cssW * dpr);
    canvas.height = Math.floor(cssH * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    return { ctx, cssW, cssH };
  }

  function sliceTail(arr, n) {
    if (!Array.isArray(arr)) return [];
    return arr.length > n ? arr.slice(arr.length - n) : arr.slice();
  }

  function sliceIndicator(indicator, n) {
    if (!indicator) return { lines: [], levels: [], hist: null };
    return {
      ...indicator,
      lines: (indicator.lines || []).map((line) => ({
        ...line,
        values: sliceTail(line.values || [], n),
      })),
      hist: indicator.hist ? sliceTail(indicator.hist, n) : null,
      levels: indicator.levels || [],
    };
  }

  function drawPrice(canvas, candles, resolution) {
    const wrap = canvas && canvas.parentElement;
    const baseH = 260;
    // If SL/TP stretch the Y-range, grow chart height so candles stay readable.
    let heightPx = baseH;
    if (wrap && candles && candles.length && state.levels && state.levels.length) {
      let cMin = Infinity;
      let cMax = -Infinity;
      for (const c of candles) {
        cMin = Math.min(cMin, Number(c.l));
        cMax = Math.max(cMax, Number(c.h));
      }
      let lMin = cMin;
      let lMax = cMax;
      for (const lv of state.levels) {
        const p = Number(lv && lv.price);
        if (Number.isFinite(p)) {
          lMin = Math.min(lMin, p);
          lMax = Math.max(lMax, p);
        }
      }
      const candleSpan = Math.max(cMax - cMin, 1e-9);
      const fullSpan = Math.max(lMax - lMin, candleSpan);
      const ratio = fullSpan / candleSpan;
      // Keep candle visual size ~constant: height grows with span ratio (cap 4×).
      heightPx = Math.round(baseH * Math.min(4, Math.max(1, ratio)));
      wrap.style.height = heightPx + 'px';
    } else if (wrap) {
      wrap.style.height = baseH + 'px';
    }

    if (window.ChartCandles) {
      ChartCandles.draw(canvas, candles, {
        padTop: 12,
        padRight: 58,
        padBottom: 28,
        padLeft: 8,
        maxBody: 12,
        emptyText: 'Нет свечей ' + (resolution || ''),
        levels: state.levels || [],
      });
      return;
    }
    const { ctx, cssW, cssH } = prepareCanvas(canvas);
    ctx.fillStyle = '#5c6b61';
    ctx.font = '13px Manrope, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('ChartCandles missing', cssW / 2, cssH / 2);
  }

  /**
   * Horizontal SL/TP lines on price chart.
   * @param {{price:number,color?:string,dash?:number[],label?:string,width?:number}[]|null} levels
   */
  function setLevels(levels) {
    state.levels = Array.isArray(levels) ? levels.filter((lv) => Number.isFinite(Number(lv && lv.price))) : [];
    const priceCanvas = $('simPriceChart');
    if (priceCanvas && state.lastCandles && state.lastCandles.length) {
      drawPrice(priceCanvas, state.lastCandles, state.resolution);
    }
  }

  function drawMethod(canvas, indicator) {
    const { ctx, cssW, cssH } = prepareCanvas(canvas);
    const lines = indicator?.lines || [];
    const hist = indicator?.hist || null;
    const levels = indicator?.levels || [];
    const values = [];
    for (const line of lines) {
      for (const v of line.values || []) if (v != null) values.push(Number(v));
    }
    if (hist) for (const v of hist) if (v != null) values.push(Number(v));
    if (!values.length) {
      ctx.fillStyle = '#5c6b61';
      ctx.font = '13px Manrope, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText('Нет данных метода', cssW / 2, cssH / 2);
      return;
    }
    const n = Math.max(
      ...lines.map((l) => (l.values || []).length),
      hist ? hist.length : 0,
      1,
    );
    const pad = { top: 10, right: 58, bottom: 18, left: 8 };
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
    min -= padY;
    max += padY;
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
      const vals = line.values || [];
      ctx.strokeStyle = line.color || '#1f4b7a';
      ctx.lineWidth = 1.6;
      ctx.beginPath();
      let started = false;
      for (let i = 0; i < vals.length; i++) {
        if (vals[i] == null) {
          started = false;
          continue;
        }
        const x = pad.left + slot * i + slot / 2;
        const y = yScale(Number(vals[i]));
        if (!started) {
          ctx.moveTo(x, y);
          started = true;
        } else {
          ctx.lineTo(x, y);
        }
      }
      ctx.stroke();
    }
  }

  function setMeta(text, isError) {
    const el = $('simChartMeta');
    if (!el) return;
    el.textContent = text;
    el.classList.toggle('error', !!isError);
  }

  function setLeadTitle(method, resolution) {
    const el = $('simChartMethod');
    if (!el) return;
    const res = resolution || state.resolution || DEFAULTS.resolution;
    el.textContent = (method || DEFAULTS.method) + ' · ' + res;
  }

  function setLead(method, resolution, opts = {}) {
    if (!method) return;
    const force = !!opts.force;
    const res = resolution || state.resolution || DEFAULTS.resolution;
    if (!force && method === state.method && state.resolution === res) {
      setLeadTitle(method, res);
      return;
    }
    const changed = method !== state.method || state.resolution !== res;
    state.method = method;
    state.resolution = res;
    setLeadTitle(method, res);
    if (changed || force) {
      if (state.loading) {
        state.pending = { method, resolution: res };
        return;
      }
      load({ method, resolution: res });
    }
  }

  /** @deprecated use setLead */
  function setMethod(method, opts = {}) {
    setLead(method, state.resolution || DEFAULTS.resolution, opts);
  }

  async function load(opts = {}) {
    if (state.loading) {
      if (opts.method || opts.resolution) {
        state.pending = {
          method: opts.method || state.method,
          resolution: opts.resolution || state.resolution,
        };
      }
      return;
    }
    const method = opts.method || state.method || DEFAULTS.method;
    const resolution = opts.resolution || state.resolution || DEFAULTS.resolution;
    state.method = method;
    state.resolution = resolution;
    state.loading = true;
    setLeadTitle(method, resolution);
    setMeta('загрузка ' + resolution + '…');
    try {
      const q = new URLSearchParams({
        action: 'live',
        market_id: String(opts.marketId || DEFAULTS.marketId),
        resolution,
        method,
        candle_count: String(opts.candleCount || DEFAULTS.candleCount),
        network: 'mainnet',
      });
      const res = await fetch('api.php?' + q.toString(), { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));

      if (state.method !== method || state.resolution !== resolution) {
        return;
      }

      const visible = opts.visible || DEFAULTS.visible;
      const candles = sliceTail(data.candles || [], visible);
      const indicator = sliceIndicator(data.indicator || {}, visible);
      state.lastCandles = candles;
      const priceCanvas = $('simPriceChart');
      const indCanvas = $('simMethodChart');
      if (priceCanvas) drawPrice(priceCanvas, candles, resolution);
      if (indCanvas) drawMethod(indCanvas, indicator);

      const last = candles[candles.length - 1];
      const px = last ? fmt(last.c, 4) : '—';
      setMeta(`${resolution} · ${method} · px ${px} · bars ${candles.length} · ${new Date().toLocaleTimeString('ru-RU')}`);
    } catch (e) {
      setMeta(String(e.message || e), true);
    } finally {
      state.loading = false;
      if (state.pending) {
        const next = state.pending;
        state.pending = null;
        if (next.method !== state.method || next.resolution !== state.resolution) {
          setLead(next.method, next.resolution, { force: true });
        }
      }
    }
  }

  function start(opts = {}) {
    if (opts.method) state.method = opts.method;
    if (opts.resolution) state.resolution = opts.resolution;
    load({
      method: state.method,
      resolution: state.resolution,
      marketId: opts.marketId,
      candleCount: opts.candleCount,
      visible: opts.visible,
    });
    if (state.timer) clearInterval(state.timer);
    const sec = Math.max(15, Number(opts.refreshSec || 30));
    state.timer = setInterval(
      () => load({ method: state.method, resolution: state.resolution }),
      sec * 1000,
    );
    window.addEventListener('resize', () => load({ method: state.method, resolution: state.resolution }));
  }

  function currentMethod() {
    return state.method;
  }

  function currentResolution() {
    return state.resolution;
  }

  window.Sim1mChart = { start, load, setMethod, setLead, setLevels, currentMethod, currentResolution };
})();
