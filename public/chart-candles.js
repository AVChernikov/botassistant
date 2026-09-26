(function () {
  'use strict';

  function fmtPrice(n, digits = 2) {
    const num = Number(n);
    if (!Number.isFinite(num)) return '—';
    return num.toLocaleString('en-US', { maximumFractionDigits: digits });
  }

  function fmtTime(ts) {
    let ms = Number(ts);
    if (!Number.isFinite(ms)) return '—';
    if (ms > 1e15) ms = Math.floor(ms / 1000);
    const d = new Date(ms);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('ru-RU', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
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

  /**
   * Candle chart with volume pane and time labels on X.
   * Expects candles oldest -> newest with o,h,l,c,t and optional v/V.
   */
  function draw(canvas, candles, options = {}) {
    const { ctx, cssW, cssH } = prepareCanvas(canvas);
    if (!candles || !candles.length) {
      ctx.fillStyle = '#5c6b61';
      ctx.font = '14px Manrope, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(options.emptyText || 'Нет данных', cssW / 2, cssH / 2);
      return null;
    }

    const pad = {
      top: options.padTop ?? 14,
      right: options.padRight ?? 60,
      bottom: options.padBottom ?? 28,
      left: options.padLeft ?? 10,
    };
    const plotW = cssW - pad.left - pad.right;
    const innerH = cssH - pad.top - pad.bottom;
    const volRatio = options.volumeRatio ?? 0.22;
    const gap = 6;
    const volH = Math.max(28, Math.floor(innerH * volRatio));
    const priceH = Math.max(40, innerH - volH - gap);
    const priceTop = pad.top;
    const volTop = pad.top + priceH + gap;
    const volBottom = volTop + volH;

    let min = Infinity;
    let max = -Infinity;
    let maxVol = 0;
    for (const c of candles) {
      min = Math.min(min, Number(c.l));
      max = Math.max(max, Number(c.h));
      const vol = Number(c.v ?? c.V ?? 0);
      if (Number.isFinite(vol)) maxVol = Math.max(maxVol, vol);
    }
    const padY = (max - min) * 0.06 || max * 0.001 || 1;
    min -= padY;
    max += padY;
    if (maxVol <= 0) maxVol = 1;

    const yScale = (p) => priceTop + ((max - p) / (max - min)) * priceH;
    const slot = plotW / candles.length;
    const bodyW = Math.max(1.5, Math.min(options.maxBody ?? 16, slot * 0.62));
    const barW = Math.max(1, Math.min(bodyW, slot * 0.7));

    // Price grid
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
      ctx.fillText(fmtPrice(price, 2), cssW - pad.right + 6, y + 4);
    }

    // Separator between price and volume
    ctx.strokeStyle = '#c7d2cb';
    ctx.beginPath();
    ctx.moveTo(pad.left, volTop - gap / 2);
    ctx.lineTo(cssW - pad.right, volTop - gap / 2);
    ctx.stroke();

    // Candles + volume
    candles.forEach((c, i) => {
      const o = Number(c.o);
      const h = Number(c.h);
      const l = Number(c.l);
      const cl = Number(c.c);
      const up = cl >= o;
      const color = up ? '#0f6b4c' : '#b42318';
      const x = pad.left + slot * i + slot / 2;

      ctx.strokeStyle = color;
      ctx.fillStyle = color;
      ctx.lineWidth = 1.3;
      ctx.beginPath();
      ctx.moveTo(x, yScale(h));
      ctx.lineTo(x, yScale(l));
      ctx.stroke();
      const yO = yScale(o);
      const yC = yScale(cl);
      ctx.fillRect(x - bodyW / 2, Math.min(yO, yC), bodyW, Math.max(1, Math.abs(yC - yO)));

      const vol = Number(c.v ?? c.V ?? 0);
      if (Number.isFinite(vol) && vol > 0) {
        const vh = Math.max(1, (vol / maxVol) * (volH - 2));
        ctx.globalAlpha = 0.45;
        ctx.fillStyle = color;
        ctx.fillRect(x - barW / 2, volBottom - vh, barW, vh);
        ctx.globalAlpha = 1;
      }
    });

    // Volume axis label
    ctx.fillStyle = '#5c6b61';
    ctx.font = '10px "IBM Plex Mono", monospace';
    ctx.textAlign = 'left';
    ctx.fillText('vol', cssW - pad.right + 6, volTop + 10);
    ctx.fillText(fmtPrice(maxVol, maxVol >= 1000 ? 0 : 2), cssW - pad.right + 6, volBottom);

    // Time labels on X
    ctx.fillStyle = '#5c6b61';
    ctx.textAlign = 'center';
    ctx.font = '11px "IBM Plex Mono", monospace';
    const labelCount = Math.min(6, Math.max(3, Math.floor(plotW / 110)));
    const idxs = [];
    if (candles.length === 1) {
      idxs.push(0);
    } else {
      for (let i = 0; i < labelCount; i++) {
        idxs.push(Math.round((i * (candles.length - 1)) / (labelCount - 1)));
      }
    }
    const seen = new Set();
    for (const i of idxs) {
      if (seen.has(i)) continue;
      seen.add(i);
      const x = pad.left + slot * i + slot / 2;
      ctx.fillText(fmtTime(candles[i].t), x, cssH - 8);
    }

    const layout = { pad, points: candles.length, priceH, volH, volTop };
    if (window.ChartCrosshair) {
      ChartCrosshair.mark(canvas, { pad, points: candles.length });
    }
    return layout;
  }

  window.ChartCandles = { draw, prepareCanvas, fmtTime, fmtPrice };
})();
