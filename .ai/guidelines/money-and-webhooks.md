# Money and webhooks (summary; the checklists are `.claude/rules/money.md` and `.claude/rules/webhooks.md`)

- Amounts are integer nanodollars (`int`, `bigint`, `App\Support\Money\Nanodollars`); never floats, `round()` or `number_format()` in money code.
- Lock order: take | suggestion, then customer; the usage record is re-read under the customer lock, never locked separately.
- Take status changes only in `ApplyTakeSnapshotAction` and `FailTakeAction`; settle or void exactly once inside the terminal transition. Failed = free.
- No HTTP call inside a database transaction; no queue delay or backoff above 900 s.
- Holds are state (`max_charge_nanos` on open usage records), not ledger rows; `available = balance − held`.
- The server formats money: exact amounts keep at least 2 decimals; rounded amounts (lists, cards, toasts, pill) always show 4. Customers never see provider cost, tokens, provider names or margin.
- Webhooks: HMAC over the raw body; invalid, missing or stale signature returns 401 and writes nothing; `event_id` stored once in `webhook_events`, one `webhook_deliveries` row per verified delivery.
