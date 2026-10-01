# Lighter Perps MCP

MCP server that opens/closes Lighter perpetual positions (the same backend Telegram Wallet futures use).

## Tools

| Tool | Purpose |
|------|---------|
| `list_markets` | Perp markets + ids/decimals |
| `resolve_account_index` | Map L1 address → account_index |
| `get_account` | Collateral / balances / positions |
| `get_positions` | Open positions only |
| `get_active_orders` | Active orders for a market |
| `open_long` | Market long (`size` or `quote_usd`) |
| `open_short` | Market short |
| `close_position` | Reduce-only close |
| `place_limit_order` | GTT limit long/short |
| `place_take_profit` | TP on open position (market or limit) |
| `place_stop_loss` | SL on open position (market or limit) |
| `place_tp_sl` | Place TP + SL together |
| `cancel_order` | Cancel by order index |

Pass `dry_run=true` on open/close/limit/TP/SL tools to preview without sending a tx.

### TP / SL notes

- Side and size are taken from the open position (override with `size`).
- Default is market trigger (IOC). Set `limit=true` for GTT limit after trigger.
- `execution_price` is optional; otherwise trigger ± `max_slippage`.

## Setup

1. Create an API key in Lighter UI (indices 0–3 are reserved for app; use **2+**).
2. Copy env file and fill credentials:

```powershell
cd mcp-lighter
copy .env.example .env
```

```env
LIGHTER_BASE_URL=https://mainnet.zklighter.elliot.ai
LIGHTER_ACCOUNT_INDEX=12345
LIGHTER_API_KEY_INDEX=2
LIGHTER_API_PRIVATE_KEY=0x...
```

If you only know the L1 wallet address, leave `LIGHTER_ACCOUNT_INDEX` empty first, call `resolve_account_index`, then set it.

3. Install deps into a venv:

Linux:

```bash
cd mcp-lighter
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
```

Windows:

```powershell
cd mcp-lighter
py -3.12 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
```

4. Cursor MCP config (project `.cursor/mcp.json` is already prepared). Restart Cursor MCP / reload window after editing env.

## Telegram notifications

Push position / session status to your chat (agent ticks + `/status` command).

1. Create a bot with [@BotFather](https://t.me/BotFather) → copy token.
2. Send any message to the bot, then open  
   `https://api.telegram.org/bot<TOKEN>/getUpdates` and copy `chat.id`.
3. Add to `.env`:

```env
TELEGRAM_BOT_TOKEN=123456:ABC...
TELEGRAM_CHAT_ID=123456789
```

4. Test:

```powershell
.\.venv\Scripts\python.exe telegram_notify.py --status --dry-run
.\.venv\Scripts\python.exe telegram_notify.py --status
.\.venv\Scripts\python.exe telegram_notify.py "hello from LIT agent"
```

5. Optional always-on bot (commands → agent queue):

```powershell
.\.venv\Scripts\python.exe telegram_bot.py
```

- `/status` — live snapshot  
- `стоп` / `старт` / `стоп sma` / `закрыть` … → `_tg_queue.json` (agent on next tick)  
- Fast pause flags: `_tg_control.json`

## Safety

- Real money: start with `dry_run=true`, then tiny sizes on testnet if needed (`LIGHTER_BASE_URL=https://testnet.zklighter.elliot.ai`).
- Never commit `.env` or private keys.
- Market orders use limited slippage (`LIGHTER_MAX_SLIPPAGE`, default 0.5%).
