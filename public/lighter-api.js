(function () {
  'use strict';

  const BASE_URLS = {
    mainnet: 'https://mainnet.zklighter.elliot.ai',
    testnet: 'https://testnet.zklighter.elliot.ai',
  };

  const RESOLUTION_MS = {
    '1m': 60_000,
    '5m': 300_000,
    '15m': 900_000,
    '30m': 1_800_000,
    '1h': 3_600_000,
    '4h': 14_400_000,
    '12h': 43_200_000,
    '1d': 86_400_000,
  };

  function baseUrl(network) {
    return BASE_URLS[network] || BASE_URLS.mainnet;
  }

  async function request(path, params = {}, network = 'mainnet') {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== null && value !== undefined && value !== '') {
        query.set(key, String(value));
      }
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15_000);
    try {
      const suffix = query.size ? `?${query}` : '';
      const response = await fetch(baseUrl(network) + path + suffix, {
        headers: { Accept: 'application/json' },
        mode: 'cors',
        cache: 'no-store',
        signal: controller.signal,
      });
      const data = await response.json();
      const code = Number(data?.code ?? response.status);
      if (!response.ok || code >= 400) {
        throw new Error(data?.message || `Lighter HTTP ${response.status}`);
      }
      return data;
    } catch (error) {
      if (error?.name === 'AbortError') {
        throw new Error('Lighter API: превышено время ожидания');
      }
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  function candleParams(marketId, resolution, countBack) {
    const end = Date.now();
    const count = Math.max(1, Math.min(500, Number(countBack) || 100));
    const step = RESOLUTION_MS[resolution];
    if (!step) throw new Error(`Неверный timeframe: ${resolution}`);

    return {
      market_id: marketId,
      resolution,
      start_timestamp: end - step * count,
      end_timestamp: end,
      count_back: count,
    };
  }

  function marketStat(stats, symbol) {
    return (stats?.order_book_stats || []).find(row => row.symbol === symbol) || null;
  }

  async function markets(params = {}) {
    const network = params.network === 'testnet' ? 'testnet' : 'mainnet';
    const filter = ['all', 'spot', 'perp'].includes(params.filter) ? params.filter : 'perp';
    const data = await request('/api/v1/orderBooks', { filter }, network);
    const items = (data.order_books || [])
      .filter(market => market.status === 'active')
      .sort((a, b) => String(a.symbol).localeCompare(String(b.symbol)));

    return {
      ok: true,
      network,
      base_url: baseUrl(network),
      markets: items,
    };
  }

  async function market(params = {}) {
    const network = params.network === 'testnet' ? 'testnet' : 'mainnet';
    const marketId = Number(params.market_id);
    const depth = Math.max(1, Math.min(100, Number(params.depth) || 15));
    const tradesLimit = Math.max(1, Math.min(100, Number(params.trades) || 20));
    const countBack = Math.max(1, Math.min(500, Number(params.candle_count) || 48));
    const resolution = RESOLUTION_MS[params.resolution] ? params.resolution : '1h';

    const [details, orderBook, trades, candles, stats] = await Promise.all([
      request('/api/v1/orderBookDetails', { market_id: marketId }, network),
      request('/api/v1/orderBookOrders', { market_id: marketId, limit: depth }, network),
      request('/api/v1/recentTrades', { market_id: marketId, limit: tradesLimit }, network),
      request('/api/v1/candles', candleParams(marketId, resolution, countBack), network),
      request('/api/v1/exchangeStats', {}, network),
    ]);

    const instrument = details.order_book_details?.[0] || null;
    return {
      ok: true,
      network,
      market: instrument,
      details,
      order_book: orderBook,
      trades: trades.trades || [],
      candles: {
        resolution: candles.r || resolution,
        items: candles.c || [],
      },
      market_stats: marketStat(stats, instrument?.symbol),
      exchange: {
        daily_usd_volume: stats.daily_usd_volume ?? null,
        daily_trades_count: stats.daily_trades_count ?? null,
      },
    };
  }

  async function btcAnalyze(params = {}) {
    const marketId = 1;
    const network = 'mainnet';
    const depth = Math.max(1, Math.min(100, Number(params.depth) || 15));
    const tradesLimit = Math.max(1, Math.min(100, Number(params.trades) || 25));
    const countBack = Math.max(50, Math.min(500, Number(params.candle_count) || 200));
    const resolutions = ['1d', '4h', '1h', '30m', '15m', '5m', '1m'];

    const [details, orderBook, trades, stats, ...candleResponses] = await Promise.all([
      request('/api/v1/orderBookDetails', { market_id: marketId }, network),
      request('/api/v1/orderBookOrders', { market_id: marketId, limit: depth }, network),
      request('/api/v1/recentTrades', { market_id: marketId, limit: tradesLimit }, network),
      request('/api/v1/exchangeStats', {}, network),
      ...resolutions.map(resolution =>
        request('/api/v1/candles', candleParams(marketId, resolution, countBack), network)
      ),
    ]);

    const instrument = details.order_book_details?.[0] || null;
    return {
      ok: true,
      network,
      market: instrument,
      details,
      order_book: orderBook,
      trades: trades.trades || [],
      frames: resolutions.map((resolution, index) => ({
        resolution: candleResponses[index]?.r || resolution,
        items: candleResponses[index]?.c || [],
      })),
      market_stats: marketStat(stats, instrument?.symbol || 'BTC'),
      exchange: {
        daily_usd_volume: stats.daily_usd_volume ?? null,
        daily_trades_count: stats.daily_trades_count ?? null,
      },
    };
  }

  window.LighterPublicApi = { markets, market, btcAnalyze };
})();
