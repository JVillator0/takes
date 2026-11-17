# 2-01 · Product principles, information architecture and copy

Status: **Approved** · Milestone: **M2 Product design** · Depends on: — · Unblocks: 4, 6, 7, 8 · Parallel: n/a (design)
Reviews: product ☑ · technical ☑

## Who it is for
1. **The customer**: wants a good image fast, wants to iterate, never wants a surprise on the bill.
2. **The operator** (admin): needs to know the business is making exactly 25% and that nothing is stuck or double-charged.
3. **The evaluator**: needs to verify the required behaviors in minutes (self-registration with a $1.00 welcome credit, demo accounts, test keywords, reconciliation).

## Product principles
1. **Price before action.** Every action that costs money shows its maximum price on the button itself ("Generate take · up to $0.0960").
2. **Explain every state in words.** No silent spinner: pending says *why* (capacity, retrying), failure says *what happened* and *"not charged"*.
3. **Never lose a version.** Takes are immutable; iterating creates new takes linked to their parent (remix, AI change). Versions in the strip are clickable and the drawer offers "Compare with parent" (side by side).
4. **AI proposes, the human disposes.** The AI returns a diff with a price; nothing runs until the user confirms.
5. **Same numbers everywhere.** Card, toast, take detail, billing page, invoice and admin report read the same ledger. All wireframe amounts come from **one demo dataset**, also used by the seeder (D-037).
6. **Prices, not costs.** Customers see estimate ranges and what they were charged, split by feature (images, AI suggestions). Provider cost, tokens, provider names and margin are admin-only (D-023).
7. **Defaults first.** Starter-kit auth pages and Filament built-ins are used unchanged (D-017).

## Information architecture
**Public (customer guard `auth:customer`, D-032)**
- `/` landing (P01) · `/login`, `/register` starter kit + demo panel (P02). Registration has no email verification step (D-030), so there is no "verify your email" screen.
- `/projects` index (P03; empty state: example prompt) · `/projects/{project}` workspace (P04, P05) · take detail drawer `/projects/{project}?take={id}` (P06, partial reload) · describe a change in the drawer (P07)
- `/billing` available / on hold, spend by feature, activity, invoices (P08) · `/billing/invoices/{invoice}` printable (P09). No monthly statements (D-029).
- `/settings/*` starter kit (profile, password, appearance)
- Mobile: same routes, stacked layout (P10)

**Cross-screen states (one line of copy each, in the lang files)**
| State | Where | Copy |
|---|---|---|
| Empty grid | P04 | "No takes yet. Describe an image above and press Generate take." |
| Low balance | P04 banner, P05 | "Your available credits are below the smallest take ($x.xxxx). Buy credits to keep generating." Banner shows when available < smallest possible max; the Buy credits link also sits on the disabled button. |
| Reconnecting | P04 indicator | "Reconnecting… Updates may be a few seconds late." (client polls automatically, D-028); "Live" when the socket is connected. |
| Take not found | `?take=`, `/takes/{take}` or `/takes/{take}/image` of another customer or an unknown id | 404 page, identical for both cases (no existence leak; never 403). |
| Retry a failed take | P06 on Failed | "Try again" creates a new take with the same parameters and a new idempotency key (D-033). Only for failures where a retry can help; hidden for `content_policy` and `internal` failures. |
| Cancel a waiting take | P04 card, P06 | "Cancel" on **Waiting for capacity** only (pending with `last_submit_error_kind` = capacity): voids the hold, status Failed (cancelled), "Not charged". Never on Submitting. |

## Copy rules
- Product nouns: *take*, *project*, *remix*, *describe a change*, *credits*, *on hold*. **1 credit = $1.00**; every amount is shown in dollars.
- Money (D-042): **money pages show exact amounts** (take detail incl. estimate min/max and charged amount, billing page, invoices; trailing zeros trimmed, all significant decimals) so sums foot. **Lists, cards, toasts and the balance pill show `$` + 4 decimals**, exact value on hover. The 2-decimals ban is about **rounded display** on lists, cards, toasts and the pill (always 4). Exact amounts are trimmed of trailing zeros but **never shown with fewer than 2 decimals** (e.g. `$5.00`, `$0.0735`, `$0.0960`). Exact everywhere in the admin.
- Rounding (D-021): estimate min down, max up, charges half-up; "up to" for holds; "Not charged" for failed takes; past tense ("charged") after settlement.
- Status labels: Submitting · Waiting for capacity · Queued · Processing · Done · Failed. The grid filter groups the first four as **In progress**.
- Error messages say what happened, whether money moved, and the next step. Never name providers in the public app (D-023).
- No em dashes in UI copy; sentence case buttons ("Generate take", "Buy credits").

## Take state table (single copy source for the lang files, D-057)
Specs 3-01, 3-03, 4, 5, 6 and 8 reference these tables and never restate strings (the status table in spec 4 is deleted; it references this one). A string changes here only. Use "were not" (not "weren't") in "You were not charged." The Reconnecting row above is the only source for the connection indicator and toast.
| Status | Label | Money line | Explanation | Actions |
|---|---|---|---|---|
| `pending`, first attempt | Submitting | "Up to $x on hold" | "Sending your take…" | none |
| `pending`, retry after 429 concurrency / 503 (`last_submit_error_kind` = capacity) | Waiting for capacity | "Up to $x on hold. Not charged yet." | "We allow 3 at a time. Yours starts when one finishes." / "The image service is busy. Retrying automatically." | Cancel |
| `pending`, retry after timeout / 5xx (ambiguous, D-025; `last_submit_error_kind` = ambiguous) | Submitting | "Up to $x on hold" | "Taking longer than usual. Retrying automatically." | none |
| `queued` | Queued | "Up to $x on hold" | "In line to start." | none |
| `processing` | Processing | "Up to $x on hold" | "Rendering your image." | none |
| `succeeded` | Done | "Charged $x" | Image + prompt | Remix, Describe a change, Download, Copy prompt |
| `failed` | Failed | "Not charged" | Reason from the table below | Try again (hidden for `content_policy` and `internal`), Remix |

## Failure reasons (exact sentences; terminal failures end with "You were not charged."; the two automatic-retry rows are not failures and show the pending money line from the state table instead)
| Reason | Sentence | Money | Retry |
|---|---|---|---|
| Content policy (HTTP 400 `content_policy_violation`; `failure_code` = `content_policy`) | "This prompt was declined by the content policy. Change it and try again. You were not charged." | Not charged | No (edit the prompt) |
| Validation (HTTP 422; `failure_code` = `validation`) | "We couldn't accept these settings. Change them and try again. You were not charged." | Not charged | Yes, after editing (Try again opens the composer pre-filled) |
| Concurrency 429 | "Waiting for capacity" state, not a failure. | Not charged yet | automatic |
| Rate limit 429 | "The image service is busy. We'll retry automatically." | Not charged yet | automatic |
| Daily limit 429 `daily_limit_exceeded` | "Daily limit reached. Try again after 00:00 UTC. You were not charged." | Not charged | Fails immediately; manual later |
| 503 / outage | "The image service is unavailable. We'll retry automatically." | Not charged yet | automatic (30 min window) |
| Failed after accept (provider outcome `generation_failed`) | "The image could not be generated. You were not charged." | Not charged | Try again |
| `provider_busy`: 30 min of 429/503, definite non-creation (D-025, D-049) | "The image service is busy, please try again. You were not charged." | Not charged | Try again |
| `submit_timeout`: 30 min of ambiguous timeout/5xx, orphan success possible (D-049) | "We couldn't reach the image service. You were not charged." | Not charged | Try again |
| `cancelled` (customer cancelled while Waiting for capacity) | "Cancelled. You were not charged." | Not charged | Try again |
| `internal`: HTTP 401/409/404, revoked key (D-062) | "Something went wrong on our side. You were not charged." | Not charged | No (Try again hidden); logged for admin (Needs attention) |
| `internal_error`: provider outcome (docs: "retry usually works") | "The image service had a temporary problem. You were not charged." | Not charged | Yes (Try again shown) |

## Denial reasons (`DenialReason`, D-050; shown inline on the composer/AI button, never as a failure)
| Reason | Copy |
|---|---|
| `insufficient_credits` | "Your available credits are below the max price. Buy credits to continue." (Buy credits link on the disabled button) |
| `account_restricted` | "Your account is restricted. Email {support_email} for help." |
| `daily_take_cap` | "You reached today's take limit. It resets at 00:00 UTC." |
| `demo_quota_reached` (today's global take cap reached, any customer; 3-01) | "Today's shared capacity is used up. It resets at 00:00 UTC." |
| `rate_limited` | "You are going too fast. Try again in a moment." |
| `ai_paused` | "Describe a change is paused right now. You can still generate takes." |
| `ai_daily_limit` | "You reached today's limit for Describe a change. It resets at 00:00 UTC." |

## Billing, take and AI strings (single source)
| Key | Copy |
|---|---|
| Purchase success toast | "Credits added" |
| Balance footnote | "Balance excludes amounts on hold" |
| Purchase activity line | "Takes credits (simulated purchase)" |
| Negative balance banner | "Your balance is below zero by $X. Buy credits to continue." |
| Image unavailable | "This image is no longer available" |
| Reconnecting indicator | Only the "Reconnecting…" row above (spec 5 uses it verbatim; no "updating every N s" variant) |
| AI suggestion failed | "Suggestion failed. You were not charged." |
| AI unavailable | "Describe a change is unavailable right now. You were not charged." |
| AI no change | "Nothing to change for that request. Try describing a different change. The suggestion cost is charged." A proposal with no change is a successful call, so it is charged and disclosed up front. |
| AI charge disclosure (Proposed state, P07) | "Suggestion cost $0.xxxx. Charged even if you discard." |
| AI suggestion already pending | "A suggestion for this take is already in progress. Wait for it to finish." |
| AI suggestion chips (fill the field only) | "Warmer light" · "More detail" · "Make it portrait" · "Black and white" |
| AI states | Ask, Thinking (skeleton), Proposal (diff + rationale + new estimate), Confirm; copy keys live in the lang files and are listed here when added. |

## Visual direction (for the build, not the wireframes)
- Starter kit shadcn-vue components and tokens; neutral palette; one accent for primary actions; green = charged/success, red = failed, amber = waiting.
- Dark mode via starter kit appearance setting.
- Status changes announced through an `aria-live="polite"` region.

## Acceptance criteria
- [ ] Every wireframe sheet maps to a route and a spec section.
- [ ] Money, status, denial and AI copy in the build comes from the tables above (lang files, single source, D-057) and matches the wireframes.
- [ ] All wireframe amounts for each demo customer tie to the single demo dataset (D-037).
- [ ] Lists, cards, toasts and the balance pill show 4 decimals; take detail, billing page and invoices show exact amounts that foot (D-042); no rounded customer amount (list, card, toast, pill) shows 2 decimals; exact amounts show at least 2 (`$5.00`).

## Notes
- The $1.00 welcome credit (D-019) is deliberate: the heaviest take holds about $0.47, so an evaluator can reach the insufficient-balance state, and Buy credits, with a single account.

## Additional strings (single source, D-057)
| Key | Where | Copy |
|---|---|---|
| `service.not_configured` | banner on the workspace when the image key is missing (local setups) | "Image generation isn't configured on this server yet. You can browse, but Generate is disabled." |
| `takes.empty_filter` | grid with a status filter that matches nothing | "No takes match this filter." |
| `billing.simulated_notice` | Buy credits modal | "This is a simulated payment for the take-home. No card, nothing is charged." |
| `billing.pay` | modal button | "Pay $X (simulated)" |
| `billing.view_invoice` | purchase success toast | "View invoice" |
| `billing.spent_this_month` | billing card label | "Spent this month" |
| `billing.purchase_line` | invoice line / activity | "Takes credits (simulated purchase)" |
