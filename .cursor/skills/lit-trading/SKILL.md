---
name: lit-trading
description: >-
  Trade LIT perpetual on Lighter via the lighter-perps MCP and the botassistant
  LIT analysis pages. Default: 15m + SMA(10/30) cross, $50 lot ($100 after SL
  re-entry), SL 30% / TP 50%, flip on opposite cross, stop session if net PnL
  (incl. fees) hits +$100 or -$50. Use when trading LIT or LIT analysis before
  a trade.
---

# LIT trading (Lighter)

## Constants

| Item | Value |
|------|--------|
| Symbol | `LIT` |
| Market ID | `120` |
| Timeframe | `15m` |
| Indicator | `SMA(10/30) cross` |
| Base lot | `quote_usd=50` |
| Re-entry lot (after SL) | `quote_usd=100` |
| Stop loss | `30%` from entry |
| Take profit | `50%` from entry |
| Session stop | net PnL (incl. fees) `≥ +$100` or `≤ -$50` |
| MCP | `user-lighter-perps` / `lighter-perps` |
| Creds | `mcp-lighter/.env` |

Analysis UI:

- Hub: `public/lit.php`
- Math: `public/math-report.php?market_id=120`
- Live: `public/live.php?market_id=120&method=SMA(10/30)%20cross&resolution=15m`

## Strategy rules (authoritative)

Unless the user overrides in the message:

1. **TF / indicator**: `15m` + `SMA(10/30) cross`.
2. **Cross up** → **long**. **Cross down** → **short**.
3. **Flip**:
   - Cross up while short → close then long.
   - Cross down while long → close then short.
   - Same-side already open → do not add.
4. **Lot size**:
   - Normal / signal / flip opens: **`$50`**.
   - After **stop-loss** re-entry (rule 7): **`$100`**.
   - After a **take-profit** or a **fresh indicator signal** open: reset to **`$50`**.
5. **After every new open**: `place_tp_sl` from entry `E`:
   - Long: TP=`E*1.50`, SL=`E*0.70`
   - Short: TP=`E*0.50`, SL=`E*1.30`
6. Prefer **closed** 15m bar for crosses; say if using forming bar.

### 7. Stop-loss re-entry

When an open LIT position is closed by **stop loss** (not by TP, not by flip/manual close):

1. Remember **side** that was stopped (long or short).
2. Wait **1 minute**.
3. Re-check SMA(10/30) on 15m:
   - If a **new opposite or same-direction cross signal** appeared that would dictate a trade → follow the **signal rules** (size **`$50`**), do **not** apply this re-entry.
   - If the indicator **gave no new signal** → reopen the **same side** with **`quote_usd=100`**, then set TP/SL from new entry (still 50% / 30%).
4. Track in-session that current lot is `$100` until TP, flip-on-signal, or user reset.

### 8. Session kill-switch (PnL)

Track **session net PnL in USD**, including trading fees/commission (realized PnL ± fees; use `get_account` / position realized fields when available).

- If **суммарный доход с учётом комиссии ≥ +$100** → **stop trading**: no new opens, no SL-reentries, no flips. Close LIT only if the user asks.
- If **суммарный убыток с учётом комиссии ≤ -$50** (loss greater than $50) → same: **stop trading**.
- Announce clearly: `SESSION STOP: net PnL = …` and do not resume until the user explicitly restarts the session.

Check this **before every** open / flip / SL-reentry and after every close.

## Workflow

1. Signal + `get_positions` (+ note last exit reason if known: SL / TP / flip).
2. Update session net PnL (incl. fees); if |limit| hit → **stop** (rule 8).
3. Decide: open / flip / SL-reentry / skip.
4. Wait 60s when doing SL-reentry.
5. Execute; then `place_tp_sl`.
6. Verify; report side, lot, entry, TP, SL, **session PnL**.
7. Never print API keys.

## MCP tools

Always `market="LIT"` or `market_id=120`.

| Action | Tool |
|--------|------|
| Open | `open_long` / `open_short` (`quote_usd` 50 or 100) |
| Close | `close_position` |
| TP+SL | `place_tp_sl` |
| State | `get_positions`, `get_active_orders`, `get_account` |

## Telegram

If `TELEGRAM_BOT_TOKEN` + `TELEGRAM_CHAT_ID` are set in `mcp-lighter/.env`:

- **Every 2 min**: Telegram queue via Task Scheduler `botassistant-tg-queue-2m` (inbox `_tg_agent_inbox.json`).
- **Every 30 min**: position report to Telegram (`botassistant-pos-status-30m` / `--position-report`).
- **Every 3 h**: indicator ranking 24h+12h (`botassistant-ind-accuracy-3h`).
- **Do not** post tick protocols / hold / skip status into the Cursor chat.
- Cursor chat: only when you **open / close / flip / SESSION STOP** or finish a Telegram queue command (hold ticks: one short status line).
- Open / flip / close / SESSION STOP: also push a detailed Russian line to Telegram.
- Position / status lines follow `.cursor/rules/position-status-format.mdc`:
  `{ASSET} ${VOLUME} {SIDE} {POS_PNL} / sess {SESSION_PNL} | {INDICATOR}`
- Full snapshot: `telegram_notify.py --status`

### Command queue (Telegram → agent)

Bot (`telegram_bot.py`) writes to `_tg_queue.json`. On **every SMA tick**, before trading:

1. `python tg_queue.py --pending`
2. Process each pending command; then `python tg_queue.py --ack ID --result "..."`
3. Reply via `telegram_notify.py "..."` (Telegram only)
4. Check pause: `python tg_queue.py --paused sma` (exit 0 = paused → skip opens)

Commands:

| Text | Effect |
|------|--------|
| `стоп` / `stop` | pause all (`_tg_control.json`) |
| `старт` / `продолжить` | resume all |
| `стоп sma` / `старт sma` | pause/resume SMA only |
| `закрыть` / `close` | `close_position` LIT if owner none\|sma |
| other text | reply in Telegram; follow if clearly a trade instruction |

Fast stop/start is applied by the bot immediately; agent still acks the queue item.

## Multi-strategy isolation

When `lit-trading-roc` (or another LIT skill) runs in parallel on the same account:

- This skill owns only **SMA 15m** session state (lot, SL-reentry, session PnL).
- Track position owner in-session: `sma` | `roc` | `none`.
- **Open / flip / SL-reentry only if** owner is `none` or `sma`. If owner is `roc`, skip (do not close or flip the ROC trade).
- On open/flip by this skill → set owner=`sma`. On flat (TP/SL/manual by this skill) → owner=`none`.
- Never mix ROC zero-cross into this skill’s decisions.
- Report as `SMA:` so ticks stay distinct from ROC.

## Safety

- Real money; default $50, escalate to $100 only after SL re-entry rule.
- Use Lighter API keys only.
- If MCP unavailable: reload or `mcp-lighter/.venv` + `server`.

## Examples

**Normal signal long**

1. `open_long(..., quote_usd=50)` → TP/SL at 50%/30%

**SL hit, no new cross within/after 1 minute**

1. Detect SL exit on previous long
2. Sleep ~60s; confirm no new SMA cross signal
3. `open_long(..., quote_usd=100)` → TP/SL again

**SL hit, but cross down appeared**

1. Do **not** re-enter long at $100
2. Follow cross down → short at **`$50`**

**Session PnL +$100 or −$50 (with fees)**

1. Do not open / flip / re-enter
2. Report `SESSION STOP` and wait for user to restart
