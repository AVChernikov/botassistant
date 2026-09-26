"""
Lighter perps MCP server for Cursor.

Telegram Wallet futures are Lighter-backed. This MCP opens/closes long/short
perp positions via the official lighter-sdk SignerClient.
"""

from __future__ import annotations

import json
import os
import time
from contextlib import asynccontextmanager
from decimal import Decimal, ROUND_DOWN
from typing import Any, Optional

from pathlib import Path

from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP

_ROOT = Path(__file__).resolve().parent
load_dotenv(_ROOT / ".env")
load_dotenv()  # also allow process env / cwd .env

MAINNET = "https://mainnet.zklighter.elliot.ai"
TESTNET = "https://testnet.zklighter.elliot.ai"


def _env(name: str, default: Optional[str] = None) -> Optional[str]:
    value = os.getenv(name, default)
    if value is None:
        return None
    value = value.strip()
    return value or None


def _base_url() -> str:
    return (_env("LIGHTER_BASE_URL") or MAINNET).rstrip("/")


def _json(data: Any) -> str:
    return json.dumps(data, ensure_ascii=False, indent=2, default=str)


def _to_plain(obj: Any) -> Any:
    if obj is None:
        return None
    if hasattr(obj, "model_dump"):
        return obj.model_dump()
    if hasattr(obj, "to_dict"):
        return obj.to_dict()
    if isinstance(obj, (list, tuple)):
        return [_to_plain(x) for x in obj]
    if isinstance(obj, dict):
        return {k: _to_plain(v) for k, v in obj.items()}
    return obj


def _require_trading_env() -> tuple[int, int, str]:
    account_index = _env("LIGHTER_ACCOUNT_INDEX")
    api_key_index = _env("LIGHTER_API_KEY_INDEX", "2")
    private_key = _env("LIGHTER_API_PRIVATE_KEY")
    missing = []
    if not account_index:
        missing.append("LIGHTER_ACCOUNT_INDEX")
    if not private_key:
        missing.append("LIGHTER_API_PRIVATE_KEY")
    if missing:
        raise RuntimeError(
            "Missing env: "
            + ", ".join(missing)
            + ". Set them in mcp-lighter/.env or Cursor MCP env. "
            "Use resolve_account_index to find account_index from L1 address."
        )
    return int(account_index), int(api_key_index or "2"), private_key  # type: ignore[arg-type]


def _client_order_index() -> int:
    # uint48-ish unique id
    return int(time.time() * 1000) % (1 << 47)


def _scale_amount(size: float | str, decimals: int) -> int:
    q = Decimal(str(size)) * (Decimal(10) ** decimals)
    scaled = int(q.to_integral_value(rounding=ROUND_DOWN))
    if scaled <= 0:
        raise ValueError(f"size too small after scaling with {decimals} decimals: {size}")
    return scaled


def _scale_price(price: float | str, decimals: int) -> int:
    q = Decimal(str(price)) * (Decimal(10) ** decimals)
    scaled = int(q.to_integral_value(rounding=ROUND_DOWN))
    if scaled <= 0:
        raise ValueError(f"price too small after scaling with {decimals} decimals: {price}")
    return scaled


class LighterRuntime:
    def __init__(self) -> None:
        self.api_client = None
        self.signer = None
        self.account_api = None
        self.order_api = None
        self._markets_cache: Optional[list[dict]] = None
        self._markets_cache_at = 0.0

    async def start(self) -> None:
        import lighter

        conf = lighter.Configuration(host=_base_url())
        self.api_client = lighter.ApiClient(conf)
        self.account_api = lighter.AccountApi(self.api_client)
        self.order_api = lighter.OrderApi(self.api_client)

        try:
            account_index, api_key_index, private_key = _require_trading_env()
            self.signer = lighter.SignerClient(
                url=_base_url(),
                api_private_keys={api_key_index: private_key},
                account_index=account_index,
            )
        except RuntimeError:
            # Read-only mode until credentials are set
            self.signer = None

    async def stop(self) -> None:
        if self.signer is not None:
            try:
                await self.signer.close()
            except Exception:
                pass
            self.signer = None
        if self.api_client is not None:
            try:
                await self.api_client.close()
            except Exception:
                pass
            self.api_client = None

    async def ensure_signer(self):
        if self.signer is not None:
            return self.signer
        await self.start()
        if self.signer is None:
            _require_trading_env()
            raise RuntimeError("SignerClient failed to initialize")
        return self.signer

    async def markets(self, force: bool = False) -> list[dict]:
        now = time.time()
        if (
            not force
            and self._markets_cache is not None
            and now - self._markets_cache_at < 30
        ):
            return self._markets_cache
        assert self.order_api is not None
        resp = await self.order_api.order_book_details(filter="perp")
        details = getattr(resp, "order_book_details", None) or []
        markets = [_to_plain(m) for m in details]
        self._markets_cache = markets
        self._markets_cache_at = now
        return markets

    async def find_market(
        self,
        market: Optional[str] = None,
        market_id: Optional[int] = None,
    ) -> dict:
        markets = await self.markets()
        if market_id is not None:
            for m in markets:
                if int(m.get("market_id")) == int(market_id):
                    return m
            raise ValueError(f"Unknown market_id={market_id}")
        if not market:
            raise ValueError("Provide market symbol (e.g. BTC, ETH) or market_id")
        needle = market.strip().upper().replace("-PERP", "").replace("USDT", "").replace("USD", "")
        exact = []
        prefix = []
        for m in markets:
            sym = str(m.get("symbol", "")).upper()
            if sym == needle:
                exact.append(m)
            elif sym.startswith(needle):
                prefix.append(m)
        if exact:
            return exact[0]
        if len(prefix) == 1:
            return prefix[0]
        if prefix:
            names = [str(m.get("symbol")) for m in prefix]
            raise ValueError(
                f"Ambiguous market {market!r} matches {names}. Use market_id (LIT=120)."
            )
        raise ValueError(f"Unknown market symbol={market!r}. Use list_markets.")


runtime = LighterRuntime()


@asynccontextmanager
async def lifespan(_mcp: FastMCP):
    await runtime.start()
    try:
        yield
    finally:
        await runtime.stop()


mcp = FastMCP("lighter-perps", lifespan=lifespan)


@mcp.tool()
async def list_markets(limit: int = 40, active_only: bool = True) -> str:
    """List Lighter perpetual markets (symbol, market_id, mark price, decimals)."""
    markets = await runtime.markets(force=True)
    if active_only:
        markets = [m for m in markets if str(m.get("status", "")).lower() == "active"]
    markets = sorted(markets, key=lambda m: (str(m.get("symbol") or ""), int(m.get("market_id") or 0)))
    rows = []
    for m in markets[: max(1, min(limit, 200))]:
        rows.append(
            {
                "market_id": m.get("market_id"),
                "symbol": m.get("symbol"),
                "status": m.get("status"),
                "mark_price": m.get("mark_price"),
                "last_trade_price": m.get("last_trade_price"),
                "min_base_amount": m.get("min_base_amount"),
                "supported_size_decimals": m.get("supported_size_decimals"),
                "supported_price_decimals": m.get("supported_price_decimals"),
            }
        )
    return _json({"base_url": _base_url(), "count": len(rows), "markets": rows})


@mcp.tool()
async def resolve_account_index(l1_address: Optional[str] = None) -> str:
    """Resolve Lighter account_index from an L1 (Ethereum) address."""
    address = l1_address or _env("LIGHTER_L1_ADDRESS")
    if not address:
        raise RuntimeError("Pass l1_address or set LIGHTER_L1_ADDRESS")
    assert runtime.account_api is not None
    resp = await runtime.account_api.accounts_by_l1_address(l1_address=address)
    plain = _to_plain(resp)
    sub = plain.get("sub_accounts") if isinstance(plain, dict) else None
    indexes = []
    if isinstance(sub, list):
        for item in sub:
            if isinstance(item, dict) and "index" in item:
                indexes.append(item["index"])
            elif isinstance(item, dict) and "account_index" in item:
                indexes.append(item["account_index"])
    return _json(
        {
            "l1_address": address,
            "account_indexes": indexes,
            "hint": "Set LIGHTER_ACCOUNT_INDEX to the index you want to trade (usually the first).",
            "raw": plain,
        }
    )


@mcp.tool()
async def get_account(active_only: bool = True) -> str:
    """Get account collateral, balances and positions for configured LIGHTER_ACCOUNT_INDEX."""
    account_index, _, _ = _require_trading_env()
    assert runtime.account_api is not None
    resp = await runtime.account_api.account(
        by="index",
        value=str(account_index),
        active_only=active_only,
    )
    return _json(_to_plain(resp))


@mcp.tool()
async def get_positions(active_only: bool = True) -> str:
    """List open perp positions (sign: 1=long, -1=short)."""
    account_index, _, _ = _require_trading_env()
    assert runtime.account_api is not None
    resp = await runtime.account_api.account(
        by="index",
        value=str(account_index),
        active_only=active_only,
    )
    accounts = getattr(resp, "accounts", None) or []
    positions = []
    for acc in accounts:
        for pos in getattr(acc, "positions", None) or []:
            plain = _to_plain(pos)
            try:
                size = float(plain.get("position") or 0)
            except (TypeError, ValueError):
                size = 0.0
            if size != 0:
                sign = int(plain.get("sign") or 0)
                plain["side"] = "long" if sign > 0 else "short" if sign < 0 else "flat"
                positions.append(plain)
    return _json({"account_index": account_index, "positions": positions})


@mcp.tool()
async def get_active_orders(market: Optional[str] = None, market_id: Optional[int] = None) -> str:
    """List active orders. Optionally filter by market symbol or market_id."""
    account_index, api_key_index, _ = _require_trading_env()
    signer = await runtime.ensure_signer()
    auth, err = signer.create_auth_token_with_expiry(
        deadline=3600,
        api_key_index=api_key_index,
    )
    if err:
        raise RuntimeError(f"auth token error: {err}")

    mid = market_id
    if mid is None and market:
        mid = int((await runtime.find_market(market=market))["market_id"])

    assert runtime.order_api is not None
    resp = await runtime.order_api.account_active_orders(
        authorization=auth,
        account_index=account_index,
        market_id=mid,
    )
    return _json(_to_plain(resp))


async def _open_market(
    *,
    side: str,
    market: Optional[str],
    market_id: Optional[int],
    size: Optional[float],
    quote_usd: Optional[float],
    max_slippage: Optional[float],
    reduce_only: bool,
    dry_run: bool,
) -> str:
    if (size is None) == (quote_usd is None):
        raise ValueError("Provide exactly one of size (base units) or quote_usd")

    m = await runtime.find_market(market=market, market_id=market_id)
    mid = int(m["market_id"])
    size_decimals = int(m.get("supported_size_decimals") or m.get("size_decimals") or 4)
    slippage = float(
        max_slippage
        if max_slippage is not None
        else (_env("LIGHTER_MAX_SLIPPAGE") or "0.005")
    )
    is_ask = side == "short"
    client_oid = _client_order_index()

    preview = {
        "side": side,
        "market_id": mid,
        "symbol": m.get("symbol"),
        "is_ask": is_ask,
        "reduce_only": reduce_only,
        "max_slippage": slippage,
        "client_order_index": client_oid,
        "mark_price": m.get("mark_price"),
        "dry_run": dry_run,
    }

    if dry_run:
        if size is not None:
            preview["size"] = size
            preview["base_amount"] = _scale_amount(size, size_decimals)
        else:
            preview["quote_usd"] = quote_usd
        return _json(preview)

    signer = await runtime.ensure_signer()

    if size is not None:
        base_amount = _scale_amount(size, size_decimals)
        preview["size"] = size
        preview["base_amount"] = base_amount
        tx, resp, err = await signer.create_market_order_limited_slippage(
            market_index=mid,
            client_order_index=client_oid,
            base_amount=base_amount,
            max_slippage=slippage,
            is_ask=is_ask,
            reduce_only=reduce_only,
        )
    else:
        preview["quote_usd"] = quote_usd
        tx, resp, err = await signer.create_market_order_quote_amount(
            market_index=mid,
            client_order_index=client_oid,
            quote_amount=float(quote_usd),  # type: ignore[arg-type]
            max_slippage=slippage,
            is_ask=is_ask,
            reduce_only=reduce_only,
        )

    if err:
        return _json({"ok": False, "error": err, "preview": preview})

    return _json(
        {
            "ok": True,
            "preview": preview,
            "tx": _to_plain(tx),
            "response": _to_plain(resp),
        }
    )


@mcp.tool()
async def open_long(
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    quote_usd: Optional[float] = None,
    max_slippage: Optional[float] = None,
    dry_run: bool = False,
) -> str:
    """Open a long (buy) perp. Pass size in base units OR quote_usd notional. Set dry_run=true to preview."""
    return await _open_market(
        side="long",
        market=market,
        market_id=market_id,
        size=size,
        quote_usd=quote_usd,
        max_slippage=max_slippage,
        reduce_only=False,
        dry_run=dry_run,
    )


@mcp.tool()
async def open_short(
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    quote_usd: Optional[float] = None,
    max_slippage: Optional[float] = None,
    dry_run: bool = False,
) -> str:
    """Open a short (sell) perp. Pass size in base units OR quote_usd notional. Set dry_run=true to preview."""
    return await _open_market(
        side="short",
        market=market,
        market_id=market_id,
        size=size,
        quote_usd=quote_usd,
        max_slippage=max_slippage,
        reduce_only=False,
        dry_run=dry_run,
    )


@mcp.tool()
async def close_position(
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    max_slippage: Optional[float] = None,
    dry_run: bool = False,
) -> str:
    """Close (or partially close) an open position with a reduce-only market order."""
    m = await runtime.find_market(market=market, market_id=market_id)
    mid = int(m["market_id"])

    target = await _find_position(mid)
    if target is None:
        return _json({"ok": False, "error": f"No open position on market_id={mid}"})

    pos_size = float(getattr(target, "position") or 0)
    close_size = float(size) if size is not None else pos_size
    if close_size <= 0:
        raise ValueError("close size must be > 0")
    if close_size > pos_size:
        close_size = pos_size

    # Long closes by selling (ask); short closes by buying (bid)
    side = "short" if _close_is_ask_from_position(target) else "long"
    return await _open_market(
        side=side,
        market=None,
        market_id=mid,
        size=close_size,
        quote_usd=None,
        max_slippage=max_slippage,
        reduce_only=True,
        dry_run=dry_run,
    )


async def _find_position(market_id: int) -> Any:
    account_index, _, _ = _require_trading_env()
    assert runtime.account_api is not None
    resp = await runtime.account_api.account(
        by="index", value=str(account_index), active_only=True
    )
    for acc in getattr(resp, "accounts", None) or []:
        for pos in getattr(acc, "positions", None) or []:
            if int(getattr(pos, "market_id", -1)) != market_id:
                continue
            try:
                pos_size = float(getattr(pos, "position") or 0)
            except (TypeError, ValueError):
                pos_size = 0.0
            if pos_size != 0:
                return pos
    return None


def _close_is_ask_from_position(pos: Any) -> bool:
    """Long closes with sell (ask=True); short closes with buy (ask=False)."""
    sign = int(getattr(pos, "sign") or 0)
    if sign > 0:
        return True
    if sign < 0:
        return False
    raise ValueError("Position sign is flat; cannot infer close side")


def _execution_price_from_trigger(
    trigger: float, is_ask: bool, max_slippage: float
) -> float:
    # Worst acceptable fill vs trigger when the stop/tp fires as IOC market.
    if is_ask:
        return trigger * (1.0 - max_slippage)
    return trigger * (1.0 + max_slippage)


async def _place_tp_sl(
    *,
    kind: str,
    trigger_price: float,
    market: Optional[str],
    market_id: Optional[int],
    size: Optional[float],
    execution_price: Optional[float],
    limit: bool,
    max_slippage: Optional[float],
    reduce_only: bool,
    dry_run: bool,
) -> str:
    """kind: take_profit | stop_loss"""
    m = await runtime.find_market(market=market, market_id=market_id)
    mid = int(m["market_id"])
    size_decimals = int(m.get("supported_size_decimals") or m.get("size_decimals") or 4)
    price_decimals = int(m.get("supported_price_decimals") or m.get("price_decimals") or 2)

    pos = await _find_position(mid)
    if pos is None:
        return _json({"ok": False, "error": f"No open position on market_id={mid}"})

    is_ask = _close_is_ask_from_position(pos)
    pos_side = "long" if is_ask else "short"
    pos_size = float(getattr(pos, "position") or 0)
    close_size = float(size) if size is not None else pos_size
    if close_size <= 0:
        raise ValueError("size must be > 0")
    if close_size > pos_size:
        close_size = pos_size

    slip = float(
        max_slippage
        if max_slippage is not None
        else (_env("LIGHTER_MAX_SLIPPAGE") or "0.005")
    )
    exec_px = (
        float(execution_price)
        if execution_price is not None
        else _execution_price_from_trigger(float(trigger_price), is_ask, slip)
    )

    base_amount = _scale_amount(close_size, size_decimals)
    trigger_i = _scale_price(trigger_price, price_decimals)
    price_i = _scale_price(exec_px, price_decimals)
    client_oid = _client_order_index()

    preview = {
        "kind": kind,
        "limit": limit,
        "position_side": pos_side,
        "close_side": "sell" if is_ask else "buy",
        "market_id": mid,
        "symbol": m.get("symbol"),
        "size": close_size,
        "trigger_price": trigger_price,
        "execution_price": exec_px,
        "base_amount": base_amount,
        "trigger_price_int": trigger_i,
        "price_int": price_i,
        "reduce_only": reduce_only,
        "max_slippage": slip,
        "client_order_index": client_oid,
        "avg_entry_price": getattr(pos, "avg_entry_price", None),
        "dry_run": dry_run,
    }
    if dry_run:
        return _json(preview)

    signer = await runtime.ensure_signer()
    if kind == "take_profit":
        fn = signer.create_tp_limit_order if limit else signer.create_tp_order
    elif kind == "stop_loss":
        fn = signer.create_sl_limit_order if limit else signer.create_sl_order
    else:
        raise ValueError(f"unknown kind={kind}")

    tx, resp, err = await fn(
        market_index=mid,
        client_order_index=client_oid,
        base_amount=base_amount,
        trigger_price=trigger_i,
        price=price_i,
        is_ask=is_ask,
        reduce_only=reduce_only,
    )
    if err:
        return _json({"ok": False, "error": err, "preview": preview})
    return _json(
        {"ok": True, "preview": preview, "tx": _to_plain(tx), "response": _to_plain(resp)}
    )


@mcp.tool()
async def place_take_profit(
    trigger_price: float,
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    execution_price: Optional[float] = None,
    limit: bool = False,
    max_slippage: Optional[float] = None,
    reduce_only: bool = True,
    dry_run: bool = False,
) -> str:
    """Place a take-profit on the open position. Uses full position size if size omitted.
    Default is market TP (IOC at trigger). Set limit=true for TP-limit (GTT).
    execution_price defaults to trigger +/- max_slippage."""
    return await _place_tp_sl(
        kind="take_profit",
        trigger_price=trigger_price,
        market=market,
        market_id=market_id,
        size=size,
        execution_price=execution_price,
        limit=limit,
        max_slippage=max_slippage,
        reduce_only=reduce_only,
        dry_run=dry_run,
    )


@mcp.tool()
async def place_stop_loss(
    trigger_price: float,
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    execution_price: Optional[float] = None,
    limit: bool = False,
    max_slippage: Optional[float] = None,
    reduce_only: bool = True,
    dry_run: bool = False,
) -> str:
    """Place a stop-loss on the open position. Uses full position size if size omitted.
    Default is market SL (IOC at trigger). Set limit=true for SL-limit (GTT).
    execution_price defaults to trigger +/- max_slippage."""
    return await _place_tp_sl(
        kind="stop_loss",
        trigger_price=trigger_price,
        market=market,
        market_id=market_id,
        size=size,
        execution_price=execution_price,
        limit=limit,
        max_slippage=max_slippage,
        reduce_only=reduce_only,
        dry_run=dry_run,
    )


@mcp.tool()
async def place_tp_sl(
    take_profit: float,
    stop_loss: float,
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    size: Optional[float] = None,
    limit: bool = False,
    max_slippage: Optional[float] = None,
    dry_run: bool = False,
) -> str:
    """Place both take-profit and stop-loss on the open position (two reduce-only orders)."""
    tp = await _place_tp_sl(
        kind="take_profit",
        trigger_price=take_profit,
        market=market,
        market_id=market_id,
        size=size,
        execution_price=None,
        limit=limit,
        max_slippage=max_slippage,
        reduce_only=True,
        dry_run=dry_run,
    )
    sl = await _place_tp_sl(
        kind="stop_loss",
        trigger_price=stop_loss,
        market=market,
        market_id=market_id,
        size=size,
        execution_price=None,
        limit=limit,
        max_slippage=max_slippage,
        reduce_only=True,
        dry_run=dry_run,
    )
    return _json({"take_profit": json.loads(tp), "stop_loss": json.loads(sl)})


@mcp.tool()
async def place_limit_order(
    side: str,
    price: float,
    size: float,
    market: Optional[str] = None,
    market_id: Optional[int] = None,
    reduce_only: bool = False,
    dry_run: bool = False,
) -> str:
    """Place a GTT limit order. side=long|short (or buy|sell)."""
    side_n = side.strip().lower()
    if side_n in ("long", "buy", "bid"):
        is_ask = False
        side_n = "long"
    elif side_n in ("short", "sell", "ask"):
        is_ask = True
        side_n = "short"
    else:
        raise ValueError("side must be long/short (or buy/sell)")

    m = await runtime.find_market(market=market, market_id=market_id)
    mid = int(m["market_id"])
    size_decimals = int(m.get("supported_size_decimals") or m.get("size_decimals") or 4)
    price_decimals = int(m.get("supported_price_decimals") or m.get("price_decimals") or 2)
    base_amount = _scale_amount(size, size_decimals)
    price_i = _scale_price(price, price_decimals)
    client_oid = _client_order_index()

    preview = {
        "side": side_n,
        "market_id": mid,
        "symbol": m.get("symbol"),
        "size": size,
        "price": price,
        "base_amount": base_amount,
        "price_int": price_i,
        "reduce_only": reduce_only,
        "client_order_index": client_oid,
        "dry_run": dry_run,
    }
    if dry_run:
        return _json(preview)

    signer = await runtime.ensure_signer()
    tx, resp, err = await signer.create_order(
        market_index=mid,
        client_order_index=client_oid,
        base_amount=base_amount,
        price=price_i,
        is_ask=is_ask,
        order_type=signer.ORDER_TYPE_LIMIT,
        time_in_force=signer.ORDER_TIME_IN_FORCE_GOOD_TILL_TIME,
        reduce_only=reduce_only,
        order_expiry=signer.DEFAULT_28_DAY_ORDER_EXPIRY,
    )
    if err:
        return _json({"ok": False, "error": err, "preview": preview})
    return _json({"ok": True, "preview": preview, "tx": _to_plain(tx), "response": _to_plain(resp)})


@mcp.tool()
async def cancel_order(
    order_index: int,
    market: Optional[str] = None,
    market_id: Optional[int] = None,
) -> str:
    """Cancel an order by order_index (or client_order_index) on a market."""
    m = await runtime.find_market(market=market, market_id=market_id)
    mid = int(m["market_id"])
    signer = await runtime.ensure_signer()
    tx, resp, err = await signer.cancel_order(market_index=mid, order_index=order_index)
    if err:
        return _json({"ok": False, "error": err, "market_id": mid, "order_index": order_index})
    return _json(
        {
            "ok": True,
            "market_id": mid,
            "order_index": order_index,
            "tx": _to_plain(tx),
            "response": _to_plain(resp),
        }
    )


def main() -> None:
    mcp.run(transport="stdio")


if __name__ == "__main__":
    main()
