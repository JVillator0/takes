# 8 · AI: Describe a change

Status: **Approved** · Milestone: **M8 AI** (our extra) · Depends on: 3, 4, 5 (sweeper, `ApplyTakeSnapshotAction` for the new take, Reverb; 6 only for the billing UI row) · Worktree: **b** · Parallel with: 6 · **Scope cut (D-080):** issue 5 (admin AI suggestions resource and *Edit in composer*, #76) is deferred; its acceptance criteria are out of the QA plan.
Reviews: product ☑ · technical ☑ · Wireframes: P06, P07, A09, A11 · Decisions: D-007, D-008, D-009, D-023, D-024, D-032, D-033, D-036, D-037, D-042, D-050, D-052, D-055, D-057

## Goal
Turn a sentence about an existing take into a reviewed parameter diff with a new price, billed exactly through the same ledger as images. The AI never spends Pixelforge money by itself. Customers see only prices and "AI suggestions" as a feature, never tokens or provider names (D-023).

## Flow
1. In the take drawer: *Describe a change* → instruction (≤ 500 chars) + suggestion chips (2-01 *AI suggestion chips*). **Chips only fill the field**; the user presses *Suggest change* (no spend on a chip click).
2. `POST /takes/{take}/suggestions {instruction, request_key}` (`auth:customer`, D-032) → `SuggestionPolicy::create` → `CreateSuggestionAction`, in **two transactions**, the model call outside any lock:
   - **T1:** lock customer, replay check on `(customer_id, request_key)` (returns the existing suggestion, no second call, D-033 pattern), reject if the same base take (`base_take_id`) already has a `pending` suggestion, insert `suggestions` (`pending`; table and columns defined only in 3-02, D-052), `OpenUsageAction(provider=anthropic, maxCharge)` (the hold is state, D-024). Commit.
   - **Call** the agent synchronously, no locks held (timeout 20 s, single call).
   - **T2:** success ⇒ store proposal, `SettleUsageAction(units = input/output tokens, rates snapshot)`, status `proposed`. Error/refusal/invalid output ⇒ `VoidUsageAction`, status `failed`, copy from 2-01 (not charged)
   - **Expiry:** a suggestion `pending` for more than 2 min is voided (usage voided, status `failed`, reason `timeout`) by the opportunistic sweeper of spec 5 (D-036), whose `SweepStaleTakesAction` also voids pending suggestions older than 2 min; triggers are drawer load and the admin Needs attention page (spec 5 owns this behaviour). Test: kill after T1, sweeper voids and frees the hold.
3. The drawer shows the diff (prompt word diff, size, quality, references), the rationale, the suggestion price and the new take's estimate (see States).
4. *Generate take* ⇒ apply action: lock order **suggestion → customer** (usage is re-read under the customer lock, never locked separately); require `status = proposed`; **recompute the estimate** at apply time; create the take through `CreateTakeAction` (`source = ai_change`, `parent_take_id`, `suggestion_id`) inside the same transaction (spec 4 allows the call from an outer transaction) and set `applied`. `CreateTakeAction` requires `idempotency_key` (D-033): it is derived deterministically as uuid v5 of the suggestion id, so a double apply replays the existing take instead of creating a second one. Partial unique index on `takes(suggestion_id)` where not null (created in 3-02) makes a double apply impossible. *Edit in composer* pre-fills the composer (applied on submit; cut first if time is short). *Discard* ⇒ `discarded`.

## States (P07 variants)
| State | Copy / behaviour |
|---|---|
| Loading | Button disabled; copy from 2-01. All state copy lives in 2-01 / `lang/en/*.php` (D-057); this table lists behaviour only. |
| Proposed | Diff + rationale + the 2-01 *AI charge disclosure* + new take estimate. |
| No change | A proposal with no change is a successful call: the suggestion cost IS charged (disclosed up front); the 2-01 line says so. |
| Insufficient credits for the new take | Proposal shown, *Generate take* replaced by **Buy credits**. |
| `ai_paused` (daily budget or kill switch) | Denied with this `DenialReason`; copy from 2-01. |
| `ai_daily_limit` (30/customer/day) | Denied by `SuggestionPolicy` with this `DenialReason` (D-050); copy from 2-01. |
| Failed | Not charged (void); copy from 2-01. |
| Pending too long | Treated as failed after the sweeper voids it. |
| Same take already pending | Rejected; 2-01 *AI suggestion already pending*. |

**References** are a diff row: a references row (e.g. 1 → 2, +$0.0096 est.) when `add_base_image_as_reference` is true, using the same base-image URL logic as remix (spec 4). The hold and prices are computed server-side. Money amounts in the drawer follow D-042 (the take detail's suggestion charge is exact; compact lists use 4 decimals). The hold is shown with the 2-01 on-hold wording.

## Agent (`laravel/ai`)
- **30-minute spike first** (issue 1). If the SDK does not expose exact input/output token usage or clean structured output, call the Anthropic Messages API through the Laravel `Http` client (~30 lines, exact `usage`, fakeable with `Http::fake`). The choice must be recorded in `DECISIONS.md`.
- Intended: `App\Ai\Agents\TakeChangeAgent implements Agent, HasStructuredOutput`, `#[Provider(Lab::Anthropic)]`, `#[Model('claude-haiku-5-5')]`, `#[MaxTokens(600)]`, `#[Timeout(20)]`. No tools, no memory. Attribute and fake API names are verified against the `laravel/ai` docs during the spike.
- Instructions: role, allowed values (sizes, qualities, prompt ≤ 4000 chars), "change only what the instruction asks", "keep the composition otherwise", output schema. No caching claims.
- Input: base take `{prompt, size, quality, reference_count}` + instruction. **Reference URLs are never sent to the model.**
- Structured output: `prompt` (≤ 4000), `size` (enum), `quality` (enum), `keep_references` (bool), `add_base_image_as_reference` (bool), `rationale` (≤ 200). The server re-attaches the real reference URLs; the model cannot invent any.
- Validated server-side with the `StoreTakeRequest` rules; invalid ⇒ failed + void. Refusals (`stop_reason = refusal`) ⇒ failed + void.
- Usage kinds: `input_text` and `output` tokens (3-03 §2; columns `input_text_tokens`, `output_tokens`) from the response usage object only.

## Money (spec 3-03)
- Rates in `config/ai-pricing.php`: Haiku 5.5 input $0.10 / output $0.50 per 1M ⇒ 100 / 500 nanodollars per token. The file carries the **source URL and retrieval date**; numbers are checked against the Anthropic pricing page and one real call's usage before merge (an error here is a systematic money error). Charge = cost × 1.25.
- Example for every wireframe: 1,480 input + 212 output tokens ⇒ cost 254,000 ⇒ charge 317,500 nanodollars = $0.0003175 (admin only shows tokens).
- **Max hold is computed per request (no fixed bound, D-055; 3-03 defers to this formula)**: `input_bound = ceil(utf8_bytes(system prompt + schema + base prompt + instruction) / 2)` plus a fixed overhead constant, `output_bound = 600`; `max_cost = input_bound × 100 + 600 × 500`; hold = `max_cost × 1.25`. Shown as "Up to $X on hold". Illustrative only: a short prompt with the ~1.5k-token system prompt and schema holds roughly $0.0008 and a 4000-char prompt roughly $0.001; the formula decides, and the typical actual charge is far lower (the example above, $0.0003175).
- If actual input still exceeds the bound, the actual is charged (same rule as images).
- Budget counts `Σ(provider_cost, settled today) + Σ(max_cost, open)` so concurrent in-flight calls cannot overshoot.

## Guardrails (real money on Javier's Anthropic key)
- Anthropic workspace **promptedit-takes** with its own key and a monthly spend limit in the console (monthly limit $5, D-046).
- `AI_DAILY_BUDGET_USD` (default $1.00): budget as defined above ≥ limit ⇒ `ai_paused`.
- `AI_SUGGESTIONS_ENABLED` kill switch; throttle 6/min per customer. Per-customer daily cap 30 suggestions/day (`takes.ai.daily_limit_per_customer`, denial `ai_daily_limit`) and a global $1/day budget (`ai_paused`) per D-050; denial reasons use the shared `DenialReason` enum. The customer's balance also bounds spend.
- Prompt-injection stance: the instruction is user data inside a fixed schema; the output changes only the closed fields, is re-validated, and nothing executes from model text.

## `suggestions` table
**`SuggestionStatus`: `pending`, `proposed`, `applied`, `discarded`, `failed`** (timeout is a `failure_reason`, not a status). 3-02 copies this list into its CHECK.
Defined only in 3-02 (D-052): includes `project_id`, `base_take_id` (the base take), `resulting_take_id` (the take created on apply; `takes.parent_take_id` is only the new take's lineage), `request_key` with unique(`customer_id`, `request_key`), `rationale`, `model`, `failure_reason`, `expires_at`. This spec adds behaviour only and uses those names.

## Tests
- Fake agent (exact API from the spike) returning valid, invalid and refusal outputs.
- Charge = exact tokens × rates × 1.25; failure voids; kill switch; budget counts open holds; throttle.
- Adversarially long input still has hold ≥ actual.
- A second apply of the same suggestion replays (deterministic key) and creates no second take.
- Replay with the same `request_key` (unique with `customer_id`) makes no second call and no second hold; a second request while one is pending is rejected.
- Kill after T1 ⇒ sweeper voids and releases the hold.
- Apply creates a take with lineage and `ai_change` source; two concurrent applies create one take; the estimate is recomputed.
- Re-validation rejects an out-of-range size; the model output cannot carry reference URLs.
- Config contains exactly the usage kinds the extractor produces (`input_text`, `output`).
- One customer-route test under `auth:customer` (D-032).

## Issues
1. 30-min SDK spike + agent (record the SDK vs Http decision). Uses `config/ai-pricing.php` from 3-03 issue 4; this issue only verifies its numbers against one real call.
2. `suggestions` behaviour (table is in 3-02) + actions (create in two transactions, apply, discard, expiry via sweeper) + policy.
3. Drawer UI (ask, states, diff, confirm) per P07.
4. Guardrails (kill switch, budget, throttle).
5. Last, droppable: admin AI suggestions resource (single owner: this issue; spec 7 issue 5 must not build it) and *Edit in composer*. The Anthropic usage page is owned by spec 7 issue 9 only.
