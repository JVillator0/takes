# 2-02 · Public app screens

Status: **Approved** · Milestone: **M2 Product design** · Depends on: 2-01 · Unblocks: 4, 6, 8
Reviews: product ☑ · technical ☑
Wireframes: `wireframes/p01…p10*.png` (open `wireframes/index.html`). Every amount on the sheets comes from the one demo dataset (D-037) and follows rounding rule D-021 and the money-format rule of 2-01 (D-042: exact on money pages, trailing zeros trimmed but at least 2 decimals; 4 decimals on lists/cards/pill).

**Balance model (D-024):** customers see only **Available** and **On hold**. Available = ledger balance (purchase, promo_grant, charge, adjustment) minus open holds. Holds are state on open usage records, never ledger rows or activity lines. "On hold" is a link listing the takes that hold it.

| Sheet | Screen | Built in spec | Key behaviors |
|---|---|---|---|
| P01 | Landing | 4 | One-line value prop, price ranges per example take (no provider cost or margin), CTA to sign up or demo. |
| P02 | Sign in / register | 3-01 (pages, seeded logins, panel shell); 4 (Try a demo account button, `POST /demo`, per-IP throttle: single owner) | Starter-kit pages unchanged + demo panel (only if `DEMO_LOGIN_ENABLED`): one **Try a demo account** button (fresh ephemeral customer with the welcome credit, throttled per IP, D-045) plus optional seeded logins (showcase customer; local/CI extras). Registration grants the $1.00 welcome credit, no email verification (D-019, D-030). |
| P03 | Projects | 4 | Cards with cover, take count, spend, in-progress badge; empty state with example prompt. |
| P04 | Workspace | 4, 5 | Composer (prompt, size, quality, refs ≤ 4, live estimate, max on button), takes grid with live statuses and money in words, filter counts, toast on settle, Live/Reconnecting indicator, low-balance banner, empty state. |
| P05 | Composer states | 4, 6 | Insufficient balance (hold-based, Buy credits on the disabled button), inline validation, content-policy rejection, daily limit (immediate fail), waiting for capacity, retry window exhausted. Copy from the 2-01 tables. |
| P06 | Take detail | 4 | Image, actions (Remix, Describe a change, Download, Copy prompt, Try again on failure), clickable version strip + "Compare with parent" toggle, timeline, estimate min/max and charged amount shown exact (D-042; no provider breakdown, D-023). Drawer URL `/projects/{project}?take={id}`. |
| P07 | Describe a change | 8 | Ask → skeleton loader → proposal diff with rationale and new estimate → confirm. States: suggestion failed, unavailable/paused, no change proposed (a successful call: the suggestion cost is still charged). Strings in 2-01 (AI rows). Suggestion cost shown with 4 decimals and disclosed up front, charged even if you discard. |
| P08 | Billing | 6 | Available and On hold (balance pill 4 decimals), exact amounts on the page (D-042), spend by feature, buy credits (simulated modal), activity (charges, purchases, welcome credit, adjustments; no hold rows), invoices list. Holds read "Up to $X on hold". |
| P09 | Invoice | 6 | Printable invoice per purchase. No monthly statement (D-029). |
| P10 | Mobile | 4 | Bottom-sheet composer; max price on the button at every width; same 4-decimal Available chip with On hold; Buy credits on the disabled button. |

## Interaction details not visible in the sheets
- Estimate requests are debounced 250 ms and cancelled when superseded; the button shows the last valid max; while recomputing it shows the previous value dimmed.
- Submitting disables the button until the server responds (Inertia `form.processing`). The composer generates one idempotency key per draft (uuid); a replayed POST returns the existing take and creates no second hold (D-033).
- Grid order: **creation order, newest first, never re-sorted live.** A running take keeps its position and shows a subtle ring; on completion the card animates in place (status to image). The **In progress** filter (Submitting + Waiting for capacity + Queued + Processing) is how users isolate running takes; the same definition drives the count and the badge on P03.
- "On hold" amount equals the sum of the max prices of the In progress takes on every sheet; clicking it opens the In progress filter.
- Waiting for capacity shows the cause (2-01 table) and a **Cancel** action that voids the hold. Cancel (`POST /takes/{take}/cancel`, failure_code `cancelled`) is offered only on Waiting for capacity (`last_submit_error_kind` = capacity), never on Submitting or while an attempt is in doubt (ambiguous, D-025). Retries continue for up to 30 min, then the take fails free.
- Take drawer is URL-addressable (`/projects/{project}?take={id}`, partial reload) so a reload or share keeps it open; another customer's or an unknown take id returns 404 (never 403).
- Remix pre-fills the composer and scrolls it into view; a chip "Remixing #10 ✕" cancels.
- Toasts only for transitions to a terminal state of the customer's own takes. A failed take toast says "Not charged"; hold release is not announced as a balance event.
- Keyboard: `⌘/Ctrl + Enter` submits the composer; `Esc` closes the drawer.

## Acceptance criteria
- [ ] Each screen is implemented per its sheet; differences are recorded in the PR with a reason.
- [ ] Lighthouse accessibility ≥ 95 on P03, P04, P08.
- [ ] P04, P08, P10 show the same Available and On hold for the same customer and state (one dataset).
- [ ] A completing take never changes grid position.
- [ ] Money pages (P06, P08, P09) show exact amounts (min 2 decimals); lists, cards and the pill show 4 decimals (D-042).
- [ ] Customer pages never show provider names, tokens or provider cost (D-023).

## Issues (scope moved)
- Statement view and statement printing removed from spec 6 scope (D-029).
- Cancel of a waiting take, Try again and Compare with parent are spec 4 build items (D-044; see 2-01). Try again is a client action posting a new create with a new key; Compare with parent is frontend only.
