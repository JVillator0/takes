# QA test plan: Takes on production

This is the test plan the `qa-tester` agent ([agent](../agents/qa-tester.md)) runs against the **production deploy** at `TBD_APP_URL`. It runs after Gate D, once the finished stretch work is redeployed, and before the `v1.0.0` tag ([D-071](../specs/DECISIONS.md#L77), [README Gate E](../specs/README.md#L62)). The specs are the only oracle: every check below states its expected result and links the spec line it comes from. The agent never reads application code to decide what should happen. Contract: [spec 10 § QA validation](../specs/10-submission/SPEC.md#L24) (lines 24 to 39). Issue: [#95](https://github.com/JVillator0/takes/issues/95) and its comment: the showcase customer is only **read**; takes and AI suggestions are created only by the QA customer or a demo customer.

How to read a row: `ID` is stable (re-verification refers to it). `Account / viewport`: `ANON` signed out, `QA` QA customer, `DEMO` demo customer, `SHOW` showcase, `AUD` auditor; `D` desktop (about 1440 px), `M` 390 px. `Gen/AI` is the Pixelforge generations / AI suggestions the row consumes. A row marked `(ambiguous: …)` is run and the observation recorded; it is never filed as a defect (see § 8).

## 1. Scope

**In** ([D-080](../specs/DECISIONS.md#L83), lead decision on scope):

- Required: spec 3 (only what a browser or the public webhook endpoint can observe), 4, 5, 9.
- Stretch that shipped: 6 (billing page, purchases, invoices) and 8 (Describe a change).
- Spec 7, limited to: Filament foundation (login, MFA required for admins except the MFA-exempt read-only auditor, clusters, Shield); Studio (Takes list, take view A06 with **Reconcile now hidden for the auditor**, Projects); Providers › Webhook deliveries; Billing › Reports (A03); Providers › Pixelforge usage reconciliation (A04, issue #64); Customers resource with balances and Adjust credits (issue #68; the auditor sees no write action).
- Cross-cutting: 2-01 copy tables, disclosure (D-023), money formats (D-021, D-042), wireframes P01 to P10 and A03, A04, A06, scenario keywords, live status, accessibility smoke, console errors, brief-coverage matrix rows (D-044).

**Out of scope** (cut by [D-080](../specs/DECISIONS.md#L83) or not observable; do not file issues for these):

- Dashboard widgets and *Needs attention* (#67, wireframe A02).
- Ledger invariant widget and Ledger entries resource (#63 remainder).
- Users, Purchases and Invoices admin resources (#70).
- Anthropic usage and Pricing pages (#71).
- Spec 8 issue 5: the admin AI suggestions resource and the customer-facing *Edit in composer* action (#76).
- Anything only checkable by reading code or the database (locks, transactions, enum values, Pest-only cases such as out-of-order snapshots that cannot be produced from a browser).
- Needs provider dashboards or extra credentials: deleting a delivery in the Pixelforge dashboard ([5 Acceptance](../specs/5-webhooks-and-live-status/SPEC.md#L75)), the dashboard ping ([9 L51](../specs/9-deployment/SPEC.md#L51)), MFA enforcement for non-auditor admins (no operator login is provided), Adjust credits / Restrict as an operator (A05 modal; the auditor must not see them).
- Not reachable within budget on production: daily caps (`daily_take_cap` 40/customer, `demo_quota_reached` 300/day), `ai_paused`, `ai_daily_limit`, negative-balance banner, purchase failed/unconfirmed states, demo expiry after 24 h, `service.not_configured` banner in its shown state, 30-minute `provider_busy` / `submit_timeout` exhaustion.
- `php artisan billing:verify --usage` (run by the lead after the last re-verification, [10 Final checks](../specs/10-submission/SPEC.md#L48)).

**Viewports.** Desktop about 1440 × 900 for every row unless it says `M`; 390 × 844 for P10 and for the layout pass of every public screen ([2-02 P10](../specs/2-product-design/02-SPEC.md#L20), [2-01 L26](../specs/2-product-design/01-SPEC.md#L26), [10 L27](../specs/10-submission/SPEC.md#L27)).

## 2. Accounts

Credentials never appear in this plan, the run log, issues or screenshots. They come from the README "Review in 5 minutes" section and the runner's private notes.

| Role | How obtained | May do |
|---|---|---|
| QA customer | Registers itself on `/register` with a throwaway address; welcome credit, no email verification ([D-030](../specs/DECISIONS.md#L36)) | Create takes, remixes, AI suggestions and simulated purchases within the budget (§ 3) |
| Demo customer | "Try a demo account" button ([D-045](../specs/DECISIONS.md#L51), [D-065](../specs/DECISIONS.md#L71)) | Same as the QA customer; used for the insufficient-credits path and as "another customer" for 404 checks |
| Showcase | Seeded login listed in the README and on P02 | **Read only** ([D-072](../specs/DECISIONS.md#L78), #95 comment): open its projects, takes and billing; observe that create, buy and manage actions are refused. Never create anything |
| Auditor | Admin login given by the lead at run time (never written down) | Read `/admin` only; read-only and MFA-exempt ([D-013](../specs/DECISIONS.md#L19)); write actions must be invisible |

## 3. Budget

Hard caps from [spec 10 L35](../specs/10-submission/SPEC.md#L35):

- **First full pass:** at most **25 Pixelforge generations** and **5 AI suggestions**.
- **All re-verification runs together:** at most **10 generations** and **2 AI suggestions**.

A generation is any submitted take: remixes, Try again, applied AI suggestions, and cancelled or content-policy takes all count (conservative). Pixelforge limits are account-wide and shared with evaluators: 3 concurrent, 60 requests/min, 500/day ([specs README L20](../specs/README.md#L20)). "Waiting for capacity" under load is expected copy, not a defect by itself ([10 L12](../specs/10-submission/SPEC.md#L12)). Before each consuming row, check the tally; if it would exceed a cap, mark the row `skipped (budget)`. Never repeat a generation to "see if it works again" beyond what the row says.

**Allocation (first pass):**

| Check | Gen | AI |
|---|---|---|
| QK-01 normal | 1 | 0 |
| QK-02 `[succeed]` | 1 | 0 |
| QK-03 `[fail]` | 1 | 0 |
| QK-04 `[slow]` | 1 | 0 |
| QK-05 `[duplicate] [succeed]` | 1 | 0 |
| QK-06 `[blocked]` | 1 | 0 |
| QK-07 `[overloaded]` | 1 | 0 |
| QK-08 socket blocked | 1 | 0 |
| Q4-15 double-click idempotency | 1 | 0 |
| Q4-19 remix | 1 | 0 |
| Q4-21 Try again | 1 | 0 |
| Q4-23 cancel while waiting | 1 | 0 |
| Q4-31 insufficient credits (demo, 2 heavy takes) | 2 | 0 |
| Q4-34 mobile generate | 1 | 0 |
| QX-01 keyboard submit | 1 | 0 |
| Q8-02 propose (double-click) | 0 | 1 |
| Q8-03 apply suggestion | 1 | 0 |
| Q8-05 discard | 0 | 1 |
| Q8-06 no change | 0 | 1 |
| Q8-07 already pending | 0 | 1 |
| Q8-09 insufficient for new take (optional) | 0 | 1 |
| **Planned total** | **17** | **5** |
| Contingency (one retry per check after a runner error, noted in the log) | 8 | 0 |
| **Cap** | **25** | **5** |

Q8-09 runs only if no earlier AI row needed a retry; otherwise it is `skipped (budget)`.

**Running tally (copy into the run log):**

| # | UTC time | Check ID | Account | Gen used | AI used | Gen total | AI total | Note |
|---|---|---|---|---|---|---|---|---|
| 1 | | | | | | | | |

## 4. Redaction (public repo)

From [spec 10 L37](../specs/10-submission/SPEC.md#L37): no passwords, API keys, tokens, session cookies, signed or temporary URLs, or another person's personal data in issue text, console excerpts or screenshots. Crop or blur before uploading. Refer to accounts by role ("QA customer", "demo customer", "showcase", "auditor"). Take images and invoice "Billed to" blocks carry signed URLs or emails: crop them. Webhook payload modals (A06) can contain image URLs: crop them.

## 5. Issues

Format from [spec 10 L37](../specs/10-submission/SPEC.md#L37):

- Title `[QA] <screen>: <what is wrong>`.
- Labels `qa`, `type:fix`, `spec/<n>` and exactly one of `qa:blocker`, `qa:major`, `qa:minor`; milestone `M10 Submission`.
- Search open `qa` issues for duplicates first; add evidence as a comment instead of a new issue.
- Copy or layout nits are batched: one issue per screen.

**Severity guide:**

| Severity | When |
|---|---|
| `qa:blocker` | Money is wrong (charge, hold, balance, invoice total); a charge on a failed take; another customer's data visible; provider cost, tokens, provider names or margin shown to a customer; the app or a required flow is unusable |
| `qa:major` | A spec acceptance criterion or stated behaviour fails (wrong state, missing action, wrong format of an amount, live status not updating, write action visible to the auditor) |
| `qa:minor` | Copy differs from 2-01, layout differs from the wireframe without losing information, cosmetic issues |

**Body template:**

```markdown
**Check:** Q4-07 (plan: .claude/qa/plan.md)

**Steps to reproduce**
1. Signed out, open TBD_APP_URL/register and register a new customer (role: QA customer).
2. ...

**Expected**
> "<quoted spec line or 2-01 copy row>"
Source: https://github.com/JVillator0/takes/blob/main/.claude/specs/<file>#L<n>

**Actual**
...

**Environment**
- URL: TBD_APP_URL/<path>
- Viewport: 1440 px | 390 px
- Account role: QA customer | demo customer | showcase | auditor | signed out
- Browser: Chrome <version> (Claude in Chrome) | Playwright headless
- Time (UTC): YYYY-MM-DD HH:MM

**Screenshot**
(attached below, cropped: no signed URLs, emails or tokens)

**Console output**
(redacted, or "none")
```

## 6. Checks

### 6.1 Spec 3: domain foundation (browser-observable)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q3-01 | ANON / D | Open `/register`, register the QA customer, submit | Logged in straight away; no verify-email screen; pill shows Available $1.0000, on hold $0.0000 | [2-01 L22](../specs/2-product-design/01-SPEC.md#L22), [3-01 L16](../specs/3-domain-foundation/01-SPEC.md#L16), [4 L18](../specs/4-takes-and-generation/SPEC.md#L18), [D-019](../specs/DECISIONS.md#L25) | 0/0 |
| Q3-02 | ANON / D | Open `/login` | Starter-kit form plus a demo panel: one "Try a demo account" button and the seeded showcase login | [3-01 L71](../specs/3-domain-foundation/01-SPEC.md#L71), [2-02 L12](../specs/2-product-design/02-SPEC.md#L12), [9 L23](../specs/9-deployment/SPEC.md#L23) | 0/0 |
| Q3-03 | QA / D | With only the customer session, open `/admin` | Filament login form; the customer session does not authenticate the admin panel (separate `web` guard) | [3-01 L12](../specs/3-domain-foundation/01-SPEC.md#L12), [D-012](../specs/DECISIONS.md#L18) | 0/0 |
| Q3-04 | AUD / D | In a fresh profile with only the auditor session, open `/projects` | Redirected to the customer login; an admin session does not open customer routes | [3-01 L17](../specs/3-domain-foundation/01-SPEC.md#L17), [3-01 L80](../specs/3-domain-foundation/01-SPEC.md#L80) | 0/0 |
| Q3-05 | SHOW / D | Sign in as showcase; open `/projects`, its project and a take drawer; open `/billing` | Its own history renders (projects, takes, drawer, billing) | [3-01 L45](../specs/3-domain-foundation/01-SPEC.md#L45), [D-072](../specs/DECISIONS.md#L78) | 0/0 |
| Q3-06 | SHOW / D | In the workspace type a prompt; **do not press Generate** | Generate is denied inline on the button, never shown as a failure; copy is the `account_restricted` row "Your account is restricted. Email {support_email} for help." (ambiguous: 3-01 maps a missing `takes.create` to `account_restricted`; no showcase-specific copy exists) | [3-01 L31](../specs/3-domain-foundation/01-SPEC.md#L31), [3-01 L38](../specs/3-domain-foundation/01-SPEC.md#L38), [2-01 L74](../specs/2-product-design/01-SPEC.md#L74), [2-01 L78](../specs/2-product-design/01-SPEC.md#L78) | 0/0 |
| Q3-07 | SHOW / D | Look for New project, rename/archive, Buy credits, Describe a change; do not submit any | Create, manage, buy and AI actions are not offered or are denied | [3-01 L31](../specs/3-domain-foundation/01-SPEC.md#L31), [3-01 L45](../specs/3-domain-foundation/01-SPEC.md#L45) | 0/0 |
| Q3-08 | SHOW / D | Settings › Profile: submit the form with name and email **unchanged**; never touch the password form | Denied: "Shared demo accounts can't change their name, email or password. Register your own account to do that." | [2-01 L84](../specs/2-product-design/01-SPEC.md#L84), [3-01 L74](../specs/3-domain-foundation/01-SPEC.md#L74), [D-072](../specs/DECISIONS.md#L78) | 0/0 |
| Q3-09 | QA / D | In DevTools, POST `/broadcasting/auth` for `private-App.Models.Customer.{demo customer id}` and for the own id (ids from the page props). Skip as N/A if the socket id or CSRF token is not reachable | Other customer's channel 403; own channel 200 | [3-01 L20](../specs/3-domain-foundation/01-SPEC.md#L20), [5 L72](../specs/5-webhooks-and-live-status/SPEC.md#L72) | 0/0 |

### 6.2 Spec 4: takes and generation

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q4-01 | ANON / D | Open `/` | P01: hero "Describe it." "Take it." "Remix it.", lead line, "Start with $1.00 free", "Try a demo account", "No card. Credits are simulated for this demo.", How it works, Clear pricing with example price ranges, footer "Takes is a take-home project. Payments are simulated." | [2-01 L206](../specs/2-product-design/01-SPEC.md#L206) to [L243](../specs/2-product-design/01-SPEC.md#L243), [2-02 L11](../specs/2-product-design/02-SPEC.md#L11) | 0/0 |
| Q4-02 | ANON / D | Press "Try a demo account" | A fresh customer is signed in and lands in a new project; Available $1.0000 | [4 L33](../specs/4-takes-and-generation/SPEC.md#L33), [D-045](../specs/DECISIONS.md#L51) | 0/0 |
| Q4-03 | QA / D | Open `/projects` before creating one | Empty state with an example prompt | [2-01 L23](../specs/2-product-design/01-SPEC.md#L23), [2-02 L13](../specs/2-product-design/02-SPEC.md#L13) | 0/0 |
| Q4-04 | QA / D | Create a project; rename it; create a throwaway project and archive it | Create opens the workspace; rename persists after reload; the archived project leaves the index (ambiguous: the visible effect of archive is not specified) | [4 L20](../specs/4-takes-and-generation/SPEC.md#L20), [4 L22](../specs/4-takes-and-generation/SPEC.md#L22) | 0/0 |
| Q4-05 | QA / D | Open the new workspace | Empty grid "No takes yet. Describe an image above and press Generate take."; indicator "Live" | [2-01 L31](../specs/2-product-design/01-SPEC.md#L31), [2-01 L33](../specs/2-product-design/01-SPEC.md#L33) | 0/0 |
| Q4-06 | QA / D | Inspect the composer before typing | "New take", "Prompt", placeholder "Describe your take…", "Tip: mention light, lens and mood", sizes Square / Landscape / Portrait, quality Low / Medium / High, "Reference images (optional, up to 4)", estimate "Describe your take to see its price." | [2-01 L138](../specs/2-product-design/01-SPEC.md#L138) to [L158](../specs/2-product-design/01-SPEC.md#L158), [2-01 L171](../specs/2-product-design/01-SPEC.md#L171), [4 L48](../specs/4-takes-and-generation/SPEC.md#L48) | 0/0 |
| Q4-07 | QA / D | Type a prompt; watch Network | `GET /estimates/take` carries `chars`, `size`, `quality`, `references` only, never the prompt text; panel shows "Estimated price", ":min – :max", "updates as you type"; the button reads "Generate take · up to $x.xxxx" equal to the range max | [4 L39](../specs/4-takes-and-generation/SPEC.md#L39), [2-01 L12](../specs/2-product-design/01-SPEC.md#L12), [2-01 L165](../specs/2-product-design/01-SPEC.md#L165), [2-01 L168](../specs/2-product-design/01-SPEC.md#L168) | 0/0 |
| Q4-08 | QA / D | Prompt `café 🌅` | Estimate request has `chars=6`; counter "6 / 4000" | [4 L42](../specs/4-takes-and-generation/SPEC.md#L42), [2-01 L154](../specs/2-product-design/01-SPEC.md#L154) | 0/0 |
| Q4-09 | QA / D | Switch Square → Landscape, Low → High, add 2 reference URLs | Estimate updates each time; the max rises; the reference count is part of the estimate | [4 L39](../specs/4-takes-and-generation/SPEC.md#L39), [2-02 L23](../specs/2-product-design/02-SPEC.md#L23) | 0/0 |
| Q4-10 | QA / D | Paste a 4001-character prompt | "Prompt is too long: 4001 / 4000 characters." and the take cannot be submitted | [2-01 L155](../specs/2-product-design/01-SPEC.md#L155), [4 L48](../specs/4-takes-and-generation/SPEC.md#L48) | 0/0 |
| Q4-11 | QA / D | Enter `ftp://example.com/a.png` as a reference; try to add a 5th reference | "Reference images must be http(s) URLs."; no 5th reference field | [2-01 L162](../specs/2-product-design/01-SPEC.md#L162), [4 L48](../specs/4-takes-and-generation/SPEC.md#L48) | 0/0 |
| Q4-12 | QA / D | Inspect "Try a scenario"; click each chip | Exactly `[fail]`, `[slow]`, `[duplicate]`, `[blocked]` (no `[overloaded]` or `[succeed]` chip); a click appends the keyword ("Add :keyword to the prompt") and sends no create request; hints match 2-01 | [4 L120](../specs/4-takes-and-generation/SPEC.md#L120), [2-01 L179](../specs/2-product-design/01-SPEC.md#L179) to [L184](../specs/2-product-design/01-SPEC.md#L184), [D-044](../specs/DECISIONS.md#L50) | 0/0 |
| Q4-13 | QA / D | Inspect the estimate panel copy | "You pay the exact amount when the take finishes.", "Failed takes are free.", "We hold up to $x from your balance while it runs.", "We check the maximum, not the average, before a take starts." | [2-01 L173](../specs/2-product-design/01-SPEC.md#L173) to [L177](../specs/2-product-design/01-SPEC.md#L177) | 0/0 |
| Q4-14 | QA / D | Observe during QK-01 submit | Button disabled with "Generating…" until the server answers; new card on top; card money line "Up to $x on hold"; pill on hold rises by the button max and Available falls by the same | [2-02 L24](../specs/2-product-design/02-SPEC.md#L24), [2-01 L50](../specs/2-product-design/01-SPEC.md#L50), [2-01 L166](../specs/2-product-design/01-SPEC.md#L166), [D-024](../specs/DECISIONS.md#L30) | 0/0 |
| Q4-15 | QA / D | Fresh draft `QA idempotency [succeed]`; double-click Generate fast | Exactly one new card and one hold | [4 L50](../specs/4-takes-and-generation/SPEC.md#L50), [D-033](../specs/DECISIONS.md#L39), [2-02 L24](../specs/2-product-design/02-SPEC.md#L24) | 1/0 |
| Q4-16 | QA / D | Open a Done take card | URL `?take={id}`; image; actions Remix, Describe a change, Download, Copy prompt; Price section with estimate min/max and "Charged $x" exact; reload keeps the drawer open; Esc closes it | [2-02 L16](../specs/2-product-design/02-SPEC.md#L16), [2-02 L28](../specs/2-product-design/02-SPEC.md#L28), [2-02 L31](../specs/2-product-design/02-SPEC.md#L31), [4 L25](../specs/4-takes-and-generation/SPEC.md#L25), [2-01 L55](../specs/2-product-design/01-SPEC.md#L55) | 0/0 |
| Q4-17 | QA / D | Read the Done drawer's timeline and price notes | "Created · up to $x held", "Queued", "Processing", "Done · charged $y · rest of the hold released"; "Up to $x was held while it ran; the rest went back to your balance."; "estimate was :range" | [2-01 L191](../specs/2-product-design/01-SPEC.md#L191), [2-01 L192](../specs/2-product-design/01-SPEC.md#L192), [2-01 L197](../specs/2-product-design/01-SPEC.md#L197) to [L200](../specs/2-product-design/01-SPEC.md#L200) | 0/0 |
| Q4-18 | QA / D | Copy prompt; Download | "Prompt copied"; a PNG downloads | [2-01 L55](../specs/2-product-design/01-SPEC.md#L55), [2-01 L136](../specs/2-product-design/01-SPEC.md#L136) | 0/0 |
| Q4-19 | QA / D | Remix the QK-01 take, then submit | Composer pre-filled with the parent's prompt, size, quality and the parent image as a reference; scrolls into view; chip "Remixing #N" with "Stop remixing #N"; estimate counts the reference; new card "Remix of #N" | [4 L64](../specs/4-takes-and-generation/SPEC.md#L64), [2-02 L29](../specs/2-product-design/02-SPEC.md#L29), [2-01 L148](../specs/2-product-design/01-SPEC.md#L148), [2-01 L163](../specs/2-product-design/01-SPEC.md#L163) | 1/0 |
| Q4-20 | QA / D | In the remix drawer use the versions strip and Compare with parent | Versions clickable ("This take", "Parent #N"); compare shows parent and child side by side; toggle reads "Hide comparison" | [4 L91](../specs/4-takes-and-generation/SPEC.md#L91), [2-01 L14](../specs/2-product-design/01-SPEC.md#L14), [2-01 L137](../specs/2-product-design/01-SPEC.md#L137), [2-01 L187](../specs/2-product-design/01-SPEC.md#L187) | 0/0 |
| Q4-21 | QA / D | On the QK-03 `[fail]` take press Try again and submit | Composer opens with the same inputs; submit creates a **new** take (new card), not a replay | [4 L91](../specs/4-takes-and-generation/SPEC.md#L91), [2-01 L35](../specs/2-product-design/01-SPEC.md#L35), [D-064](../specs/DECISIONS.md#L70) | 1/0 |
| Q4-22 | QA / D | Open the QK-06 `[blocked]` drawer | Try again hidden; Remix shown | [2-01 L35](../specs/2-product-design/01-SPEC.md#L35), [2-01 L56](../specs/2-product-design/01-SPEC.md#L56), [D-064](../specs/DECISIONS.md#L70) | 0/0 |
| Q4-23 | QA / D | Submit a second `[overloaded]` take; while it shows "Waiting for capacity", press Cancel | Cancel offered only on Waiting for capacity, never on Submitting; after Cancel: Failed, "Cancelled. You were not charged.", hold released (ambiguous: depends on `[overloaded]` producing the capacity state, see QK-07; if it never does, record N/A) | [2-01 L36](../specs/2-product-design/01-SPEC.md#L36), [2-01 L70](../specs/2-product-design/01-SPEC.md#L70), [4 L88](../specs/4-takes-and-generation/SPEC.md#L88), [2-02 L27](../specs/2-product-design/02-SPEC.md#L27) | 1/0 |
| Q4-24 | QA / D | Use the grid filter; pick a filter with no match; click "on hold" in the header | All / In progress / Done / Failed with counts ("Filter takes"); "No takes match this filter."; "on hold" opens In progress | [2-01 L42](../specs/2-product-design/01-SPEC.md#L42), [2-01 L121](../specs/2-product-design/01-SPEC.md#L121), [2-01 L132](../specs/2-product-design/01-SPEC.md#L132), [2-02 L25](../specs/2-product-design/02-SPEC.md#L25), [2-02 L26](../specs/2-product-design/02-SPEC.md#L26) | 0/0 |
| Q4-25 | QA / D | Watch the grid during the QK-02 to QK-05 burst | Newest first; a completing take keeps its position and animates in place | [2-02 L25](../specs/2-product-design/02-SPEC.md#L25), [2-02 L37](../specs/2-product-design/02-SPEC.md#L37) | 0/0 |
| Q4-26 | QA / D | Watch toasts during the burst | Toasts only for terminal transitions of own takes; a failed take toast says "Not charged"; no toast for hold release | [2-02 L30](../specs/2-product-design/02-SPEC.md#L30) | 0/0 |
| Q4-27 | QA / D | After Q4-31: open `/projects/{own}?take=999999999`, `/takes/{demo take id}`, `/takes/{demo take id}/image`, `/takes/999999999` | Identical 404 page for unknown and other customer's ids; never 403 | [2-01 L34](../specs/2-product-design/01-SPEC.md#L34), [4 L25](../specs/4-takes-and-generation/SPEC.md#L25) to [L27](../specs/4-takes-and-generation/SPEC.md#L27) | 0/0 |
| Q4-28 | QA / D | Open the demo customer's project URL | The other customer's project and takes are never shown (ambiguous: status code for viewing another customer's project is not specified) | [3-01 L45](../specs/3-domain-foundation/01-SPEC.md#L45), [4 L57](../specs/4-takes-and-generation/SPEC.md#L57) | 0/0 |
| Q4-29 | QA / D | Open the Buy credits modal from the pill (do not pay yet) | Packs $5, $10, $25; "This is a simulated payment for the take-home. No card, nothing is charged."; "Pay $X (simulated)"; no card fields | [4 L36](../specs/4-takes-and-generation/SPEC.md#L36), [2-01 L122](../specs/2-product-design/01-SPEC.md#L122), [2-01 L123](../specs/2-product-design/01-SPEC.md#L123), [6 L24](../specs/6-billing-and-invoicing/SPEC.md#L24) | 0/0 |
| Q4-30 | QA / D | Look for the not-configured banner | Absent in production; Generate enabled | [2-01 L120](../specs/2-product-design/01-SPEC.md#L120), [4 L144](../specs/4-takes-and-generation/SPEC.md#L144) | 0/0 |
| Q4-31 | DEMO / D | Submit two `[slow]` High / Landscape takes; then set the composer to High / Landscape again | Third take denied inline: "Your available credits are below the max price. Buy credits to continue." with Buy credits on the disabled button; estimate line "This take can cost up to $max. You have $available available."; nothing is created | [2-01 L77](../specs/2-product-design/01-SPEC.md#L77), [2-01 L176](../specs/2-product-design/01-SPEC.md#L176), [4 L121](../specs/4-takes-and-generation/SPEC.md#L121), [2-02 L15](../specs/2-product-design/02-SPEC.md#L15), [2-01 L115](../specs/2-product-design/01-SPEC.md#L115) | 2/0 |
| Q4-32 | DEMO / D | Same state as Q4-31, if available is below the smallest possible max | Banner "Your available credits are below the smallest take ($x.xxxx). Buy credits to keep generating." (conditional; N/A if not reached) | [2-01 L32](../specs/2-product-design/01-SPEC.md#L32) | 0/0 |
| Q4-33 | DEMO / D | Press Buy credits on the disabled button; buy $5 | Modal opens; after paying, Available updates in place and Generate becomes enabled without a reload | [6 L25](../specs/6-billing-and-invoicing/SPEC.md#L25), [6 L50](../specs/6-billing-and-invoicing/SPEC.md#L50), [4 L121](../specs/4-takes-and-generation/SPEC.md#L121) | 0/0 |
| Q4-34 | QA / M | At 390 px, open the workspace, compose `QA mobile [succeed]` in the composer, submit | Bottom-sheet composer; max price on the button; Available chip with On hold, 4 decimals; take updates live to Done | [2-02 L20](../specs/2-product-design/02-SPEC.md#L20), [2-01 L26](../specs/2-product-design/01-SPEC.md#L26) | 1/0 |

### 6.3 Spec 5: webhook endpoint

Run with `curl -s -o /dev/null -D - -w '%{http_code} %{time_total}\n' -X POST TBD_APP_URL/webhooks/pixelforge -H 'Content-Type: application/json' -d '{"id":"evt_qa","type":"generation.succeeded"}'` plus the header named in the row. Never use or request the webhook secret.

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q5-01 | ANON / curl | No `Pixelforge-Signature` header | `401`; not a redirect (no `Location`); no session cookie set | [5 L10](../specs/5-webhooks-and-live-status/SPEC.md#L10), [5 L14](../specs/5-webhooks-and-live-status/SPEC.md#L14), [D-056](../specs/DECISIONS.md#L62), [9 L51](../specs/9-deployment/SPEC.md#L51) | 0/0 |
| Q5-02 | ANON / curl | `Pixelforge-Signature: t=<now>,v1=deadbeef` | `401` | [5 L13](../specs/5-webhooks-and-live-status/SPEC.md#L13), [5 L14](../specs/5-webhooks-and-live-status/SPEC.md#L14) | 0/0 |
| Q5-03 | ANON / curl | `t=<now>` with no `v1`; then `t=abc,v1=00` | `401` both | [5 L12](../specs/5-webhooks-and-live-status/SPEC.md#L12), [5 L60](../specs/5-webhooks-and-live-status/SPEC.md#L60) | 0/0 |
| Q5-04 | ANON / curl | `t=<now − 3600>,v1=00` | `401` | [5 L14](../specs/5-webhooks-and-live-status/SPEC.md#L14) | 0/0 |
| Q5-05 | ANON / curl | Repeat Q5-01 three times after one warm-up request | Each answer under 1 s (`time_total`); the first request after idle may be a cold start ([D-038](../specs/DECISIONS.md#L44)) | [5 L10](../specs/5-webhooks-and-live-status/SPEC.md#L10) | 0/0 |

### 6.4 Spec 6: billing and invoices

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q6-01 | QA / D | Open `/billing` right after Q3-01 | Title "Billing"; cards Available, On hold, Spent this month (Images, AI suggestions); buy buttons "Buy $5.00" etc.; "Balance excludes amounts on hold"; tabs Activity, Invoices; amounts exact ($1.00, never $1.0000) | [6 L34](../specs/6-billing-and-invoicing/SPEC.md#L34) to [L36](../specs/6-billing-and-invoicing/SPEC.md#L36), [2-01 L90](../specs/2-product-design/01-SPEC.md#L90), [2-01 L249](../specs/2-product-design/01-SPEC.md#L249) to [L257](../specs/2-product-design/01-SPEC.md#L257) | 0/0 |
| Q6-02 | QA / D | Read Activity and Invoices before any purchase | One "Welcome credit" line, +$1.00, balance $1.00; caption "Activity, newest first. Amounts are exact."; columns "Date (UTC)", "Description", "Status", "Amount", "Balance"; Invoices "No invoices yet. Each purchase creates one." plus the note "Only purchases are invoiced. …" | [6 L37](../specs/6-billing-and-invoicing/SPEC.md#L37), [2-01 L268](../specs/2-product-design/01-SPEC.md#L268) to [L283](../specs/2-product-design/01-SPEC.md#L283), [2-01 L290](../specs/2-product-design/01-SPEC.md#L290), [2-01 L297](../specs/2-product-design/01-SPEC.md#L297) | 0/0 |
| Q6-03 | QA / D | Buy $5 from the billing page; **double-click** Pay | Dialog "Buy $5.00 of credits", simulated notice, "Tax", "Total", "Pay $5.00 (simulated)", "Cancel"; Pay disabled with "Processing…"; toast "Credits added" with "View invoice"; Available rises by exactly $5.00; one purchase, one invoice, one activity line | [6 L21](../specs/6-billing-and-invoicing/SPEC.md#L21), [6 L25](../specs/6-billing-and-invoicing/SPEC.md#L25), [2-01 L89](../specs/2-product-design/01-SPEC.md#L89), [2-01 L122](../specs/2-product-design/01-SPEC.md#L122) to [L124](../specs/2-product-design/01-SPEC.md#L124), [2-01 L258](../specs/2-product-design/01-SPEC.md#L258) to [L262](../specs/2-product-design/01-SPEC.md#L262) | 0/0 |
| Q6-04 | QA / D | Reopen the modal; buy $10 | Second purchase and invoice (a new modal open means a new key); number format `INV-{YYYY}-{NNNNNN}`, higher than the first | [6 L23](../specs/6-billing-and-invoicing/SPEC.md#L23), [6 L29](../specs/6-billing-and-invoicing/SPEC.md#L29) | 0/0 |
| Q6-05 | QA / D | Invoices tab; open the $5 invoice | List newest first with "Number", "Issued", "Total", "Status" (Paid), "View"; invoice page: "Invoice", "Issued :date", "Billed to", "From" "Takes (demo)" "Simulated merchant", line "Takes credits (simulated purchase)", "Qty", "Unit", "Amount", "Tax (not applicable in demo)", "Total paid" $5.00, "Payment: simulated, reference SIM-…", "Credits do not expire.", "Print / Save as PDF" (opens the browser print dialog, no server PDF), "Back to billing" | [6 L28](../specs/6-billing-and-invoicing/SPEC.md#L28) to [L31](../specs/6-billing-and-invoicing/SPEC.md#L31), [2-01 L289](../specs/2-product-design/01-SPEC.md#L289) to [L314](../specs/2-product-design/01-SPEC.md#L314) | 0/0 |
| Q6-06 | DEMO / D | Open the QA customer's invoice URL | Not shown to a non-owner (ambiguous: 404 vs 403 not specified for invoices) | [6 L30](../specs/6-billing-and-invoicing/SPEC.md#L30) | 0/0 |
| Q6-07 | QA / D | After the QK rows, read Activity | One line per usage: "Image" Charged with the exact amount and "Open take"; failed takes "Not charged" with "up to $x was held"; running takes "Up to $x" with status "On hold"; purchases "Takes credits (simulated purchase)" with "Invoice :number"; no hold or release lines | [6 L37](../specs/6-billing-and-invoicing/SPEC.md#L37), [2-01 L275](../specs/2-product-design/01-SPEC.md#L275) to [L288](../specs/2-product-design/01-SPEC.md#L288), [2-02 L18](../specs/2-product-design/02-SPEC.md#L18) | 0/0 |
| Q6-08 | QA / D | Compare cards and Activity | Available card "Balance :balance, on hold :on_hold" and Available = balance − on hold exactly; running balance = Σ ledger rows up to that row, excluding holds | [6 L13](../specs/6-billing-and-invoicing/SPEC.md#L13), [6 L38](../specs/6-billing-and-invoicing/SPEC.md#L38), [2-01 L251](../specs/2-product-design/01-SPEC.md#L251), [D-024](../specs/DECISIONS.md#L30) | 0/0 |
| Q6-09 | QA / D | Read Spent this month | Label ":month, by settlement date (UTC)"; Images + AI suggestions; total equals the sum of this month's charged Activity lines exactly | [6 L35](../specs/6-billing-and-invoicing/SPEC.md#L35), [6 L39](../specs/6-billing-and-invoicing/SPEC.md#L39), [2-01 L253](../specs/2-product-design/01-SPEC.md#L253) to [L255](../specs/2-product-design/01-SPEC.md#L255) | 0/0 |
| Q6-10 | QA / D | While a take runs, read the On hold card | Hint "Up to this much is held for takes and suggestions in progress."; equals the sum of the max prices of In progress takes | [2-01 L252](../specs/2-product-design/01-SPEC.md#L252), [2-02 L26](../specs/2-product-design/02-SPEC.md#L26) | 0/0 |
| Q6-11 | QA / D, M | Compare pill (P04), billing (P08) and mobile chip (P10) in the same state | Same Available and On hold everywhere (4 decimals on pill/chip, exact on P08) | [2-02 L36](../specs/2-product-design/02-SPEC.md#L36) | 0/0 |

### 6.5 Spec 7: admin console (D-080 scope)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q7-01 | ANON / D | Open `/admin` | Filament built-in login; demo-access hint on `/admin/login` (demo login enabled) | [7 L9](../specs/7-admin-console/SPEC.md#L9), [7 L85](../specs/7-admin-console/SPEC.md#L85) | 0/0 |
| Q7-02 | AUD / D | Sign in as auditor | No MFA challenge or setup prompt; lands in the panel | [7 L9](../specs/7-admin-console/SPEC.md#L9), [7 L15](../specs/7-admin-console/SPEC.md#L15), [D-013](../specs/DECISIONS.md#L19) | 0/0 |
| Q7-03 | AUD / D | Inspect the chrome | Environment indicator PRODUCTION (red); no global search box; collapsible sidebar; breadcrumbs; times shown in UTC | [7 L11](../specs/7-admin-console/SPEC.md#L11), [7 L14](../specs/7-admin-console/SPEC.md#L14), [7 L17](../specs/7-admin-console/SPEC.md#L17) | 0/0 |
| Q7-04 | AUD / D | Read the sidebar and cluster tabs | Order Dashboard · Customers · Operations (Studio, Billing, Providers) · System (Users, Roles, Activity log) for the items shown; clusters use top sub-navigation. Items cut by D-080 may be absent | [7 L12](../specs/7-admin-console/SPEC.md#L12), [7 L13](../specs/7-admin-console/SPEC.md#L13), [7 L54](../specs/7-admin-console/SPEC.md#L54) | 0/0 |
| Q7-05 | AUD / D | On every admin page visited, look for Create, Edit, Delete, Adjust credits, Restrict, Reconcile now, bulk actions | None visible (hidden, not disabled) | [2-03 L55](../specs/2-product-design/03-SPEC.md#L55), [2-03 L64](../specs/2-product-design/03-SPEC.md#L64), [7 L66](../specs/7-admin-console/SPEC.md#L66) | 0/0 |
| Q7-06 | AUD / D | Studio › Takes | Lists takes of the QA, demo and showcase customers; list + view only | [7 L50](../specs/7-admin-console/SPEC.md#L50), [7 L66](../specs/7-admin-console/SPEC.md#L66) | 0/0 |
| Q7-07 | AUD / D | View the QK-01 take (A06) | Summary: take id, `gen_…` id, full idempotency key, status, source, parent link, parameters, customer and project links. Money exact: estimate min/max cost and charge, actual cost, charge, rates, tokens, usage record settled, billing day; ledger panel with one `charge` row. Charge = cost × 1.25 exactly (compute by hand) | [2-03 L45](../specs/2-product-design/03-SPEC.md#L45), [2-03 L46](../specs/2-product-design/03-SPEC.md#L46), [7 L69](../specs/7-admin-console/SPEC.md#L69), [2-03 L28](../specs/2-product-design/03-SPEC.md#L28) | 0/0 |
| Q7-08 | AUD / D | Same take, Timeline | Created, submitted, started, completed timestamps (UTC); `submit_attempts` | [2-03 L47](../specs/2-product-design/03-SPEC.md#L47), [7 L69](../specs/7-admin-console/SPEC.md#L69) | 0/0 |
| Q7-09 | AUD / D | View the QK-03 `[fail]` take | Usage record voided, charge 0, no `charge` ledger row; failure code and message shown | [2-03 L46](../specs/2-product-design/03-SPEC.md#L46), [2-03 L48](../specs/2-product-design/03-SPEC.md#L48) | 0/0 |
| Q7-10 | AUD / D | View the QK-05 `[duplicate] [succeed]` take | Each event delivered twice, the second with outcome `duplicate`; exactly one `charge` ledger row; payload modal opens (crop URLs) | [2-03 L49](../specs/2-product-design/03-SPEC.md#L49), [5 L17](../specs/5-webhooks-and-live-status/SPEC.md#L17), [7 L69](../specs/7-admin-console/SPEC.md#L69) | 0/0 |
| Q7-11 | AUD / D | Studio › Projects | Read-only list and view | [7 L50](../specs/7-admin-console/SPEC.md#L50), [7 L66](../specs/7-admin-console/SPEC.md#L66) | 0/0 |
| Q7-12 | AUD / D | Webhook deliveries page | Header stats: events 24 h, duplicates, `ignored_stale`, `unknown_take`, `orphan_success`; Attempt column; outcome filter lists the webhook outcomes; QK-05 events show duplicate deliveries (it lives under Providers, per spec 7 and D-080) | [7 L62](../specs/7-admin-console/SPEC.md#L62) | 0/0 |
| Q7-13 | AUD / D | Billing › Reports, By day, default range | Default last 30 days; columns Spend, Provider cost, Profit, Margin, Takes, Generations (charged), Failed, AI suggestions, Pixelforge / Anthropic cost, Promo/adjustment credits consumed; totals row; reconciled badge linking to Pixelforge usage; margin **20.0%** on every day with spend; days with no activity omitted | [7 L59](../specs/7-admin-console/SPEC.md#L59), [2-03 L10](../specs/2-product-design/03-SPEC.md#L10), [7 L26](../specs/7-admin-console/SPEC.md#L26), [9 L44](../specs/9-deployment/SPEC.md#L44) | 0/0 |
| Q7-14 | AUD / D | Toggle By customer; filter the QA customer; try the provider filter | By-customer columns incl. share of spend; the QA customer's spend equals the sum of its charges in range (billing day basis) | [7 L59](../specs/7-admin-console/SPEC.md#L59), [D-035](../specs/DECISIONS.md#L41), [6 L39](../specs/6-billing-and-invoicing/SPEC.md#L39) | 0/0 |
| Q7-15 | AUD / D | Pick a range with no data (e.g. a month a year ago) | "No settled usage in this range" | [7 L59](../specs/7-admin-console/SPEC.md#L59) | 0/0 |
| Q7-16 | AUD / D | If Export CSV is visible, download it | Grouped by day × customer; columns `billing_date, customer_id, customer_name, provider, generations_charged, spend, cost, profit, margin_bps, spend_nanos, cost_nanos, profit_nanos`; exact decimal strings (ambiguous: whether the auditor holds `export_reports` is not specified; N/A if hidden). Do not upload the CSV (customer names) | [7 L59](../specs/7-admin-console/SPEC.md#L59) | 0/0 |
| Q7-17 | AUD / D | Providers › Pixelforge usage, while QA takes are in flight and again after all are terminal | Per UTC day: our generations, cost (9 decimals) and four token sums vs `/v1/usage` with differences; **Match** on every past day with data; today shows "N in flight" / Pending, never Mismatch; summary "N / M days match"; range limit 92 days | [7 L61](../specs/7-admin-console/SPEC.md#L61), [2-03 L38](../specs/2-product-design/03-SPEC.md#L38), [2-03 L39](../specs/2-product-design/03-SPEC.md#L39), [2-03 L63](../specs/2-product-design/03-SPEC.md#L63), [D-034](../specs/DECISIONS.md#L40), [9 L44](../specs/9-deployment/SPEC.md#L44) | 0/0 |
| Q7-18 | AUD / D | Customers list; find the QA customer | Columns Balance, Held, Available, Lifetime spend, Last active; values equal the QA customer's `/billing` (exact) | [7 L70](../specs/7-admin-console/SPEC.md#L70), [2-03 L12](../specs/2-product-design/03-SPEC.md#L12) | 0/0 |
| Q7-19 | AUD / D | View the QA customer | Header stats Available, On hold, takes, spend; no Adjust credits or Restrict action | [2-03 L12](../specs/2-product-design/03-SPEC.md#L12), [7 L66](../specs/7-admin-console/SPEC.md#L66), [2-03 L64](../specs/2-product-design/03-SPEC.md#L64) | 0/0 |
| Q7-20 | AUD / D | Any money on admin pages | Exact amounts (no 4-decimal rounding) | [2-03 L54](../specs/2-product-design/03-SPEC.md#L54), [2-01 L40](../specs/2-product-design/01-SPEC.md#L40) | 0/0 |

### 6.6 Spec 8: Describe a change

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q8-01 | QA / D | Drawer of a Done take › Describe a change; click each chip | Instruction field (500 max); chips "Warmer light", "More detail", "Make it portrait", "Black and white"; a chip only fills the field and sends nothing | [8 L10](../specs/8-ai-describe-a-change/SPEC.md#L10), [2-01 L100](../specs/2-product-design/01-SPEC.md#L100), [D-064](../specs/DECISIONS.md#L70) | 0/0 |
| Q8-02 | QA / D | "Warmer light"; **double-click** Suggest change | Button disabled and skeleton while thinking; one suggestion only; on hold shows "Up to $X on hold" meanwhile; Proposal: prompt word diff, size, quality, references, rationale, "Suggestion cost $0.xxxx. Charged even if you discard." (4 decimals) and the new take's estimate | [8 L11](../specs/8-ai-describe-a-change/SPEC.md#L11) to [L16](../specs/8-ai-describe-a-change/SPEC.md#L16), [8 L22](../specs/8-ai-describe-a-change/SPEC.md#L22), [8 L23](../specs/8-ai-describe-a-change/SPEC.md#L23), [8 L46](../specs/8-ai-describe-a-change/SPEC.md#L46), [2-01 L98](../specs/2-product-design/01-SPEC.md#L98), [2-02 L17](../specs/2-product-design/02-SPEC.md#L17) | 0/1 |
| Q8-03 | QA / D | Press Generate take on the proposal twice quickly | One new take with the base take as parent (AI change source); estimate recomputed; billing Activity shows an "AI suggestion" line Charged (exact) | [8 L17](../specs/8-ai-describe-a-change/SPEC.md#L17), [6 L37](../specs/6-billing-and-invoicing/SPEC.md#L37), [2-01 L282](../specs/2-product-design/01-SPEC.md#L282) | 1/0 |
| Q8-04 | QA / D | Check money in the drawer and lists after Q8-03 | Suggestion charge exact in the take detail; 4 decimals in compact lists | [8 L32](../specs/8-ai-describe-a-change/SPEC.md#L32) | 0/0 |
| Q8-05 | QA / D | New suggestion "Make it portrait"; Discard | Proposal discarded; no take created; the suggestion is still charged | [8 L17](../specs/8-ai-describe-a-change/SPEC.md#L17), [2-01 L98](../specs/2-product-design/01-SPEC.md#L98) | 0/1 |
| Q8-06 | QA / D | Instruction "Keep everything exactly the same" | "Nothing to change for that request. Try describing a different change. The suggestion cost is charged." (ambiguous: the model may still propose a change; then record N/A) | [2-01 L97](../specs/2-product-design/01-SPEC.md#L97), [8 L24](../specs/8-ai-describe-a-change/SPEC.md#L24) | 0/1 |
| Q8-07 | QA / D | Same take open in two tabs; Suggest in tab A, then immediately in tab B | Tab B: "A suggestion for this take is already in progress. Wait for it to finish."; only tab A's suggestion is charged | [8 L12](../specs/8-ai-describe-a-change/SPEC.md#L12), [8 L30](../specs/8-ai-describe-a-change/SPEC.md#L30), [2-01 L99](../specs/2-product-design/01-SPEC.md#L99) | 0/1 |
| Q8-08 | SHOW / D | Open a take drawer; look at Describe a change; do not submit | Denied or not offered (no `ai.suggest`) | [3-01 L31](../specs/3-domain-foundation/01-SPEC.md#L31), [3-01 L44](../specs/3-domain-foundation/01-SPEC.md#L44) | 0/0 |
| Q8-09 | DEMO / D | Optional, in the Q4-31 low state before buying: suggest a change on a Done take | Proposal shown with **Buy credits** in place of Generate take | [8 L25](../specs/8-ai-describe-a-change/SPEC.md#L25) | 0/1 |

### 6.7 Spec 9: deployment (browser-observable)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| Q9-01 | ANON / D | Open `/`, `/register`, `/login` over HTTPS | All serve; landing, registration and "Try a demo account" work (Q3-01, Q4-02) | [9 L49](../specs/9-deployment/SPEC.md#L49) | 0/0 |
| Q9-02 | ANON / D | First request after the site was idle at least 15 min (run first) | First page load under 3 s; record the measured time | [9 L43](../specs/9-deployment/SPEC.md#L43) | 0/0 |
| Q9-03 | SHOW / D | Read the showcase project | One take per smoke prompt: normal, `[succeed]`, `[fail]`, `[duplicate] [succeed]`, `[slow]`, `[blocked]`, `[overloaded]`, plus one remix; `[fail]` and `[blocked]` Failed and Not charged; the rest Done and Charged | [10 L22](../specs/10-submission/SPEC.md#L22), [9 L42](../specs/9-deployment/SPEC.md#L42) | 0/0 |
| Q9-04 | SHOW / D | Read showcase takes and `/billing` | One take created by an AI change; one purchase with its invoice | [10 L22](../specs/10-submission/SPEC.md#L22), [9 L42](../specs/9-deployment/SPEC.md#L42) | 0/0 |
| Q9-05 | AUD / D | At the end of the run, Studio › Takes and Customers | No QA or demo take left non-terminal; QA customer Held $0.00 | [9 L54](../specs/9-deployment/SPEC.md#L54) | 0/0 |

### 6.8 Scenario keywords

All on `QA / D`, project "QA run", prompt `QA <name> <keyword>`, Low / Square unless stated. Submit QK-02 to QK-05 back to back as the burst. Before each, note Available and on hold.

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QK-01 | QA / D | Normal prompt, no keyword | Submitting → Queued → Processing → Done live; "Charged $x"; on hold back to its previous value; Available down by exactly the charge | [2-01 L50](../specs/2-product-design/01-SPEC.md#L50) to [L55](../specs/2-product-design/01-SPEC.md#L55), [5 L32](../specs/5-webhooks-and-live-status/SPEC.md#L32), [10 L33](../specs/10-submission/SPEC.md#L33) | 1/0 |
| QK-02 | QA / D | `[succeed]` | Done and charged as QK-01 (ambiguous: keyword semantics live in the provider docs; specs only list it as a charged smoke prompt) | [9 L42](../specs/9-deployment/SPEC.md#L42), [10 L33](../specs/10-submission/SPEC.md#L33) | 1/0 |
| QK-03 | QA / D | `[fail]` | Failed; "Not charged"; "The image could not be generated. You were not charged."; Try again and Remix; toast "Not charged"; drawer "Up to $x was held while it ran. All of it went back to your balance."; timeline "Failed · not charged · hold released"; Available and on hold exactly as before submit | [2-01 L56](../specs/2-product-design/01-SPEC.md#L56), [2-01 L67](../specs/2-product-design/01-SPEC.md#L67), [2-01 L181](../specs/2-product-design/01-SPEC.md#L181), [2-01 L193](../specs/2-product-design/01-SPEC.md#L193), [2-01 L201](../specs/2-product-design/01-SPEC.md#L201), [D-024](../specs/DECISIONS.md#L30) | 1/0 |
| QK-04 | QA / D | `[slow]`; after it reaches Processing close the tab for 3 min, then reopen | "Rendering your image." while processing (60 to 90 s); after reopening: Done, charged once | [2-01 L54](../specs/2-product-design/01-SPEC.md#L54), [2-01 L182](../specs/2-product-design/01-SPEC.md#L182), [9 L52](../specs/9-deployment/SPEC.md#L52) | 1/0 |
| QK-05 | QA / D | `[duplicate] [succeed]` | Done; Available falls by exactly one charge; one "Image" line in Activity; admin shows duplicate deliveries (Q7-10) | [2-01 L183](../specs/2-product-design/01-SPEC.md#L183), [9 L52](../specs/9-deployment/SPEC.md#L52), [5 L17](../specs/5-webhooks-and-live-status/SPEC.md#L17) | 1/0 |
| QK-06 | QA / D | `[blocked]` | Failed; "This prompt was declined by the content policy. Change it and try again. You were not charged."; Try again hidden; balance restored | [2-01 L61](../specs/2-product-design/01-SPEC.md#L61), [2-01 L184](../specs/2-product-design/01-SPEC.md#L184), [4 L80](../specs/4-takes-and-generation/SPEC.md#L80) | 1/0 |
| QK-07 | QA / D | `[overloaded]`; watch until terminal | While retried: "Waiting for capacity", "Up to $x on hold. Not charged yet.", explanation for the busy service, Cancel shown; then starts on its own and ends Done and charged (ambiguous: `[overloaded]` behaviour is only in the provider docs; 2-01 gives two 503 sentences, "The image service is busy. Retrying automatically." and "The image service is unavailable. We'll retry automatically.") | [3-04 L29](../specs/3-domain-foundation/04-SPEC.md#L29), [2-01 L51](../specs/2-product-design/01-SPEC.md#L51), [2-01 L66](../specs/2-product-design/01-SPEC.md#L66), [4 L77](../specs/4-takes-and-generation/SPEC.md#L77) | 1/0 |
| QK-08 | QA / D | Block the Reverb websocket host in DevTools (request blocking), reload, submit `QA polling [succeed]`, then unblock | Indicator "Reconnecting… Updates may be a few seconds late."; the card still reaches Done without a manual reload (partial reloads every ~4 s in Network); after unblocking: "Live" and polling stops | [5 L56](../specs/5-webhooks-and-live-status/SPEC.md#L56), [2-01 L33](../specs/2-product-design/01-SPEC.md#L33), [D-028](../specs/DECISIONS.md#L34), [9 L49](../specs/9-deployment/SPEC.md#L49) | 1/0 |
| QK-09 | QA / D | During the burst, compare the pill, In progress takes and `/billing` | on hold = Σ max of In progress takes = billing On hold card; Available = balance − on hold | [2-02 L26](../specs/2-product-design/02-SPEC.md#L26), [D-024](../specs/DECISIONS.md#L30), [4 L106](../specs/4-takes-and-generation/SPEC.md#L106) | 0/0 |
| QK-10 | QA / D | During the burst, count takes in Queued / Processing | At most 3 at once; the extra take waits and starts on its own (ambiguous: spec 4's in-flight gate does not say whether it shows "Submitting" or "Waiting for capacity") | [4 L71](../specs/4-takes-and-generation/SPEC.md#L71), [specs README L20](../specs/README.md#L20) | 0/0 |

### 6.9 Copy (2-01 tables)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QC-01 | QA / D | Every status seen in QK rows | Labels exactly Submitting, Waiting for capacity, Queued, Processing, Done, Failed with the state-table money line and explanation | [2-01 L42](../specs/2-product-design/01-SPEC.md#L42), [2-01 L48](../specs/2-product-design/01-SPEC.md#L48) to [L56](../specs/2-product-design/01-SPEC.md#L56) | 0/0 |
| QC-02 | QA / D | Every failure seen (fail, blocked, cancelled) | Sentence exactly as the failure table; ends with "You were not charged." ("were not", not "weren't") | [2-01 L47](../specs/2-product-design/01-SPEC.md#L47), [2-01 L58](../specs/2-product-design/01-SPEC.md#L58) to [L72](../specs/2-product-design/01-SPEC.md#L72) | 0/0 |
| QC-03 | QA, DEMO, SHOW / D | Every denial seen | Exact denial copy; shown inline on the composer or AI button, never as a failure | [2-01 L74](../specs/2-product-design/01-SPEC.md#L74) to [L84](../specs/2-product-design/01-SPEC.md#L84) | 0/0 |
| QC-04 | QA / D | P08, P09, purchase modal and toast | Billing strings exact | [2-01 L86](../specs/2-product-design/01-SPEC.md#L86) to [L92](../specs/2-product-design/01-SPEC.md#L92), [2-01 L122](../specs/2-product-design/01-SPEC.md#L122) to [L126](../specs/2-product-design/01-SPEC.md#L126), [2-01 L249](../specs/2-product-design/01-SPEC.md#L249) to [L314](../specs/2-product-design/01-SPEC.md#L314) | 0/0 |
| QC-05 | QA / D | AI drawer states | AI strings exact | [2-01 L95](../specs/2-product-design/01-SPEC.md#L95) to [L101](../specs/2-product-design/01-SPEC.md#L101) | 0/0 |
| QC-06 | QA / D | Composer, estimate, grid, card, drawer, timeline, pill | UI chrome strings exact | [2-01 L130](../specs/2-product-design/01-SPEC.md#L130) to [L205](../specs/2-product-design/01-SPEC.md#L205) | 0/0 |
| QC-07 | ANON / D | Landing | Landing strings exact | [2-01 L206](../specs/2-product-design/01-SPEC.md#L206) to [L243](../specs/2-product-design/01-SPEC.md#L243) | 0/0 |
| QC-08 | all customer screens | Read buttons and messages | No em dashes; sentence-case buttons ("Generate take", "Buy credits"); product nouns take, project, remix, describe a change, credits, on hold; errors say what happened, whether money moved, the next step | [2-01 L39](../specs/2-product-design/01-SPEC.md#L39), [2-01 L43](../specs/2-product-design/01-SPEC.md#L43), [2-01 L44](../specs/2-product-design/01-SPEC.md#L44) | 0/0 |

### 6.10 Disclosure (D-023)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QD-01 | QA, DEMO / D, M | Search the visible text of P01 to P10, toasts and errors for: Pixelforge, Anthropic, Claude, Haiku, token, provider, cost (as provider cost), margin, markup, 25% | None present; customers see prices, charges and spend by feature only | [D-023](../specs/DECISIONS.md#L29), [2-01 L17](../specs/2-product-design/01-SPEC.md#L17), [2-01 L43](../specs/2-product-design/01-SPEC.md#L43), [2-02 L39](../specs/2-product-design/02-SPEC.md#L39), [6 L9](../specs/6-billing-and-invoicing/SPEC.md#L9) | 0/0 |
| QD-02 | QA / D | Read the Inertia page JSON (`data-page`) and XHR responses on workspace, drawer, billing, invoice | No keys or values for provider cost, tokens, rates, provider names or margin; money fields are formatted strings | [3-01 L68](../specs/3-domain-foundation/01-SPEC.md#L68), [4 L25](../specs/4-takes-and-generation/SPEC.md#L25), [D-023](../specs/DECISIONS.md#L29) | 0/0 |
| QD-03 | QA / D | Open `/takes/{own done take}` | JSON with estimate min/max, charged amount and an image URL only; no cost, tokens, rates or provider names | [4 L25](../specs/4-takes-and-generation/SPEC.md#L25), [4 L26](../specs/4-takes-and-generation/SPEC.md#L26) | 0/0 |
| QD-04 | QA / D | DevTools › WS frames during QK-01 | Broadcast payload carries price fields and an available summary only | [5 L54](../specs/5-webhooks-and-live-status/SPEC.md#L54) | 0/0 |
| QD-05 | QA / D | Estimate panel | Never states the margin; uses "You pay the exact amount when the take finishes." | [2-01 L128](../specs/2-product-design/01-SPEC.md#L128), [2-01 L173](../specs/2-product-design/01-SPEC.md#L173) | 0/0 |

### 6.11 Money formats (D-021, D-042)

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QM-01 | QA / D, M | Read the pill; hover it | `$` + 4 decimals for Available and on hold, never 2; hover "Exact: available $…, on hold $…" with at least 2 decimals | [2-01 L40](../specs/2-product-design/01-SPEC.md#L40), [2-01 L202](../specs/2-product-design/01-SPEC.md#L202) to [L205](../specs/2-product-design/01-SPEC.md#L205), [D-042](../specs/DECISIONS.md#L48) | 0/0 |
| QM-02 | QA / D | Read card money lines; hover | 4 decimals; hover "Exact amount $…" | [2-01 L40](../specs/2-product-design/01-SPEC.md#L40), [2-01 L149](../specs/2-product-design/01-SPEC.md#L149) | 0/0 |
| QM-03 | QA / D | Read toasts | Amounts in toasts have 4 decimals | [2-01 L40](../specs/2-product-design/01-SPEC.md#L40) | 0/0 |
| QM-04 | QA / D | Read project cards on `/projects` | Spend with 4 decimals | [2-02 L13](../specs/2-product-design/02-SPEC.md#L13), [2-02 L38](../specs/2-product-design/02-SPEC.md#L38) | 0/0 |
| QM-05 | QA / D | Compare the composer range for QK-01 with the drawer's exact estimate | Composer min ≤ exact min (rounded down) and max ≥ exact max (rounded up), 4 decimals; charges half-up in lists | [D-021](../specs/DECISIONS.md#L27), [2-01 L41](../specs/2-product-design/01-SPEC.md#L41) | 0/0 |
| QM-06 | QA / D | Drawer amounts | Estimate min/max and charged amount exact: trailing zeros trimmed, never fewer than 2 decimals | [2-01 L40](../specs/2-product-design/01-SPEC.md#L40), [4 L122](../specs/4-takes-and-generation/SPEC.md#L122), [2-02 L38](../specs/2-product-design/02-SPEC.md#L38) | 0/0 |
| QM-07 | QA / D | Billing page and invoice amounts | Exact, at least 2 decimals (`$5.00`, never `$5` or `$5.0000`) | [6 L31](../specs/6-billing-and-invoicing/SPEC.md#L31), [6 L34](../specs/6-billing-and-invoicing/SPEC.md#L34), [2-01 L112](../specs/2-product-design/01-SPEC.md#L112) | 0/0 |
| QM-08 | QA / D | Add up Activity and Spent this month by hand | Exact amounts foot to the balance and the spend total | [2-01 L112](../specs/2-product-design/01-SPEC.md#L112), [6 L38](../specs/6-billing-and-invoicing/SPEC.md#L38), [specs README L28](../specs/README.md#L28) | 0/0 |

### 6.12 Wireframes

Normative sheets per [specs README L97](../specs/README.md#L97); compare layout, information shown and states at the stated viewport. Information missing or wrong is `qa:major`; a layout difference with all information present is `qa:minor` (differences may be recorded in the building PR, [2-02 L34](../specs/2-product-design/02-SPEC.md#L34)). A02 is out (D-080); the A05 Adjust credits modal is not reachable by the auditor.

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QW-P01 | ANON / D, M | Landing vs sheet | Matches [p01](../specs/2-product-design/wireframes/p01-landing.png) | [2-02 L11](../specs/2-product-design/02-SPEC.md#L11) | 0/0 |
| QW-P02 | ANON / D, M | Sign in / register vs sheet | Matches [p02](../specs/2-product-design/wireframes/p02-sign-in.png); demo panel present | [2-02 L12](../specs/2-product-design/02-SPEC.md#L12) | 0/0 |
| QW-P03 | QA / D, M | Projects vs sheet | Matches [p03](../specs/2-product-design/wireframes/p03-projects.png): cover, take count, spend, in-progress badge, empty state | [2-02 L13](../specs/2-product-design/02-SPEC.md#L13) | 0/0 |
| QW-P04 | QA / D | Workspace vs sheet | Matches [p04](../specs/2-product-design/wireframes/p04-workspace.png): composer, live estimate, max on button, grid, filter counts, Live indicator, toasts | [2-02 L14](../specs/2-product-design/02-SPEC.md#L14) | 0/0 |
| QW-P05 | QA, DEMO / D | Composer states vs sheet | Matches [p05](../specs/2-product-design/wireframes/p05-composer-states.png) for states reached (insufficient, validation, content policy, waiting for capacity) | [2-02 L15](../specs/2-product-design/02-SPEC.md#L15) | 0/0 |
| QW-P06 | QA / D | Take drawer vs sheet | Matches [p06](../specs/2-product-design/wireframes/p06-take-detail.png): actions, versions strip, compare, timeline, exact price, no provider breakdown | [2-02 L16](../specs/2-product-design/02-SPEC.md#L16) | 0/0 |
| QW-P07 | QA / D | Describe a change vs sheet | Matches [p07](../specs/2-product-design/wireframes/p07-describe-a-change.png): ask, skeleton, diff + rationale + estimate, confirm | [2-02 L17](../specs/2-product-design/02-SPEC.md#L17) | 0/0 |
| QW-P08 | QA / D, M | Billing vs sheet | Matches [p08](../specs/2-product-design/wireframes/p08-billing.png): cards, spend by feature, buy, activity, invoices | [2-02 L18](../specs/2-product-design/02-SPEC.md#L18) | 0/0 |
| QW-P09 | QA / D | Invoice vs sheet | Matches [p09](../specs/2-product-design/wireframes/p09-invoice.png) | [2-02 L19](../specs/2-product-design/02-SPEC.md#L19) | 0/0 |
| QW-P10 | QA, DEMO / M | Mobile vs sheet; scroll every public screen at 390 px | Matches [p10](../specs/2-product-design/wireframes/p10-mobile.png): bottom-sheet composer, max price on the button, 4-decimal Available chip with On hold, Buy credits on the disabled button (DEMO in Q4-31 state); stacked layout, no horizontal scroll | [2-02 L20](../specs/2-product-design/02-SPEC.md#L20), [2-01 L26](../specs/2-product-design/01-SPEC.md#L26) | 0/0 |
| QW-A03 | AUD / D | Reports vs sheet | Matches [a03](../specs/2-product-design/wireframes/a03-admin-billing-reports.png): toggle, filters, columns, badge, footnote on promo/adjustment credits | [2-03 L10](../specs/2-product-design/03-SPEC.md#L10) | 0/0 |
| QW-A04 | AUD / D | Pixelforge usage vs sheet | Matches [a04](../specs/2-product-design/wireframes/a04-admin-providers.png): per-day comparison, status chips, summary | [2-03 L11](../specs/2-product-design/03-SPEC.md#L11), [2-03 L37](../specs/2-product-design/03-SPEC.md#L37) to [L41](../specs/2-product-design/03-SPEC.md#L41) | 0/0 |
| QW-A06 | AUD / D | Take view vs sheet | Matches [a06](../specs/2-product-design/wireframes/a06-admin-take.png): Summary, Money, Timeline, Failure, Webhook deliveries; Reconcile now hidden for the auditor | [2-03 L13](../specs/2-product-design/03-SPEC.md#L13), [2-03 L44](../specs/2-product-design/03-SPEC.md#L44) to [L50](../specs/2-product-design/03-SPEC.md#L50) | 0/0 |

### 6.13 Live status

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QL-01 | QA / D | During QK-01 with the socket connected | Card advances in place without a reload; balance pill updates | [5 L55](../specs/5-webhooks-and-live-status/SPEC.md#L55), [10 L33](../specs/10-submission/SPEC.md#L33) | 0/0 |
| QL-02 | QA / D | Header indicator with the socket connected | "Live" | [2-01 L33](../specs/2-product-design/01-SPEC.md#L33) | 0/0 |
| QL-03 | QA / D | Socket blocked (QK-08) | "Reconnecting… Updates may be a few seconds late."; polling runs only while a take is non-terminal and the tab is visible (no partial reloads with no running take) | [5 L56](../specs/5-webhooks-and-live-status/SPEC.md#L56), [D-028](../specs/DECISIONS.md#L34) | 0/0 |
| QL-04 | QA / D | Unblock the socket (QK-08) | Polling stops; the list refreshes once on reconnect | [5 L55](../specs/5-webhooks-and-live-status/SPEC.md#L55), [5 L56](../specs/5-webhooks-and-live-status/SPEC.md#L56) | 0/0 |

### 6.14 Accessibility smoke

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QX-01 | QA / D | Keyboard only: Tab through prompt, size, quality, references, scenario chips, Generate; submit `QA keyboard [succeed]` with ⌘/Ctrl + Enter | Every control reachable and operable; the shortcut submits ("Press :keys to generate") | [2-02 L31](../specs/2-product-design/02-SPEC.md#L31), [2-01 L167](../specs/2-product-design/01-SPEC.md#L167), [10 L34](../specs/10-submission/SPEC.md#L34) | 1/0 |
| QX-02 | QA / D | Keyboard only: focus a card ("Open take #:id"), Enter, Tab through drawer actions, Esc | Drawer opens, actions reachable, Esc closes | [2-02 L31](../specs/2-product-design/02-SPEC.md#L31), [2-01 L145](../specs/2-product-design/01-SPEC.md#L145), [10 L34](../specs/10-submission/SPEC.md#L34) | 0/0 |
| QX-03 | QA / D | Tab through composer, drawer, billing and the purchase dialog | Focus is always visible | [10 L34](../specs/10-submission/SPEC.md#L34) | 0/0 |
| QX-04 | QA / D | Inspect statuses and money lines | Status never conveyed by colour alone: each badge has its text label | [10 L34](../specs/10-submission/SPEC.md#L34), [2-01 L104](../specs/2-product-design/01-SPEC.md#L104) | 0/0 |
| QX-05 | QA / D | Inspect the DOM during QK-01 | An `aria-live="polite"` region announces "Take #:id: :status" | [2-01 L106](../specs/2-product-design/01-SPEC.md#L106), [2-01 L146](../specs/2-product-design/01-SPEC.md#L146) | 0/0 |
| QX-06 | QA / D | First Tab on any app page | Skip link "Skip to content" | [2-01 L243](../specs/2-product-design/01-SPEC.md#L243) | 0/0 |
| QX-07 | QA / D | Lighthouse accessibility on P03, P04, P08 (if available in the runner) | Score ≥ 95 each | [2-02 L35](../specs/2-product-design/02-SPEC.md#L35) | 0/0 |

### 6.15 Console errors

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QE-01 | all / D, M | Read the console and failed requests on every customer screen visited | No uncaught errors; no 5xx; 4xx only where a row expects one (denials, 404 checks) | [10 L34](../specs/10-submission/SPEC.md#L34) | 0/0 |
| QE-02 | AUD / D | Same on every admin page visited | No uncaught errors, no 5xx | [10 L34](../specs/10-submission/SPEC.md#L34) | 0/0 |

### 6.16 Brief-coverage matrix rows (D-044)

Each row is satisfied by the checks named; also follow the README matrix's "How to see it live" column for that row and confirm it works as written.

| ID | Account / viewport | Steps | Expected | Source | Gen/AI |
|---|---|---|---|---|---|
| QB-01 | QA / D | Estimate shown before submit | Q4-07, Q4-14 pass | [10 L11](../specs/10-submission/SPEC.md#L11), [specs README L16](../specs/README.md#L16) | 0/0 |
| QB-02 | AUD / D | Cost + 25% | Q7-07 (charge = cost × 1.25) and Q7-13 (margin 20.0%) pass; customers never see the 25% (QD-01) | [10 L11](../specs/10-submission/SPEC.md#L11), [2-03 L28](../specs/2-product-design/03-SPEC.md#L28) | 0/0 |
| QB-03 | QA, AUD / D | Exact actual cost | Drawer charged amount = Activity amount = A06 charge, all exact | [10 L11](../specs/10-submission/SPEC.md#L11), [2-01 L16](../specs/2-product-design/01-SPEC.md#L16) | 0/0 |
| QB-04 | QA / D | Failed = free | QK-03, QK-06, Q4-23, Q7-09 pass: $0.00 charged, hold released, usage voided | [10 L11](../specs/10-submission/SPEC.md#L11), [10 L33](../specs/10-submission/SPEC.md#L33) | 0/0 |
| QB-05 | QA / D | Live history | QL-01, QK-08 pass | [10 L11](../specs/10-submission/SPEC.md#L11) | 0/0 |
| QB-06 | ANON, AUD / D | Signed, duplicate, out-of-order webhooks | Q5-01 to Q5-04, QK-05, Q7-10 pass; out-of-order is visible only if `ignored_stale` deliveries exist (Q7-12), otherwise recorded as "Pest only" | [10 L11](../specs/10-submission/SPEC.md#L11) | 0/0 |
| QB-07 | ANON / D | Public deployment | Q9-01 passes | [10 L11](../specs/10-submission/SPEC.md#L11) | 0/0 |
| QB-08 | AUD / D | Admin report matching `/v1/usage` | Q7-13, Q7-17 pass | [10 L11](../specs/10-submission/SPEC.md#L11) | 0/0 |
| QB-09 | QA, DEMO / D | Balances, fake Buy credits, blocking when unaffordable | Q6-03, Q4-31, Q4-33 pass | [10 L11](../specs/10-submission/SPEC.md#L11), [6 L50](../specs/6-billing-and-invoicing/SPEC.md#L50) | 0/0 |
| QB-10 | ANON / D | README "How to see it live" column, every row | Each instruction works as written on `TBD_APP_URL` | [10 L11](../specs/10-submission/SPEC.md#L11) | 0/0 |

## 7. Check counts

| Group | Rows |
|---|---|
| Spec 3 | 9 |
| Spec 4 | 34 |
| Spec 5 (webhook endpoint) | 5 |
| Spec 6 | 11 |
| Spec 7 (D-080 scope) | 20 |
| Spec 8 | 9 |
| Spec 9 | 5 |
| Scenario keywords | 10 |
| Copy | 8 |
| Disclosure | 5 |
| Money formats | 8 |
| Wireframes | 13 |
| Live status | 4 |
| Accessibility | 7 |
| Console | 2 |
| Brief matrix | 10 |
| **Total** | **160** |

## 8. Run protocol

**Order** (cheap read-only first, then consuming rows within budget):

1. Setup: open own tab, desktop viewport, console capture on; start the run log with the tally table; list open `qa` issues.
2. Q9-02 (cold start, first request), then ANON rows: Q4-01, Q3-02, Q7-01, Q5-01 to Q5-05, QW-P01, QW-P02, QC-07.
3. SHOW rows (read only): Q3-05 to Q3-08, Q8-08, Q9-03, Q9-04.
4. AUD baseline: Q7-02 to Q7-06, Q7-11 to Q7-17 (first look), Q3-04.
5. QA customer, zero-cost: Q3-01, Q3-03, Q6-01, Q6-02, Q4-03 to Q4-13, Q4-29, Q4-30, Q6-03 to Q6-05, Q8-01.
6. QA customer, consuming: QK-01 (with Q4-14, QL-01, QL-02, QX-05, QD-04), QK-02 to QK-05 burst (with QK-09, QK-10, Q4-25, Q4-26, Q6-10), QK-06, QK-07, Q4-23, QK-08 (with QL-03, QL-04), Q4-15, Q4-19, Q4-20, Q4-21, Q4-22, QX-01, then Q4-16 to Q4-18, QM rows, QD rows.
7. AI: Q8-02 to Q8-07.
8. Demo customer: Q4-02, Q4-31, Q4-32, Q8-09 (optional), Q4-33, Q6-06; then QA: Q4-27, Q4-28, Q3-09.
9. Mobile pass at 390 px: Q4-34, QW-P10, QW-P01 to QW-P08 at M, Q6-11, QM-01.
10. AUD after the run: Q7-07 to Q7-10, Q7-12 to Q7-20, QW-A03, QW-A04, QW-A06, Q9-05 (once every QA and demo take is terminal).
11. Remaining cross-cutting: QC, QX-02 to QX-07, QE, QB.

**Evidence per check:** in the run log, one line per check: ID, `pass | fail | skipped (budget) | blocked (spec ambiguity) | N/A`, UTC time, account role, viewport, and for a failure the local screenshot path (scratch directory outside the repo) and the issue number. Screenshots are never committed; redacted copies are attached to the issue.

**Ambiguous rows:** run them and record what was seen. If the observation matches the literal spec wording, mark `pass`; otherwise mark `blocked (spec ambiguity)` with both readings and links, and do not file a defect.

**Re-verification** (after a fix PR merges and production is redeployed): re-run only the check IDs named in the issue, within the shared re-verification cap (10 generations, 2 suggestions for all re-runs together; keep a separate tally). Pass: close the issue with evidence (check ID, deploy commit or tag, viewport, screenshot). Fail: comment with new evidence, leave it open.

**Exit criteria** ([10 L39](../specs/10-submission/SPEC.md#L39)): no open `qa:blocker` or `qa:major`; each `qa:minor` is fixed or explicitly deferred in the README trade-offs. After the last re-verification and redeploy the lead (not the QA agent) runs `php artisan billing:verify --usage` and records it ([10 L48](../specs/10-submission/SPEC.md#L48)).

## 9. Report template

Posted as a comment on the run issue [#96](https://github.com/JVillator0/takes/issues/96) and linked from the README "How this was built" section ([10 L39](../specs/10-submission/SPEC.md#L39)).

```markdown
## QA report: <full pass | re-verification N>

- Deploy: TBD_APP_URL · commit `<sha>` / tag `<tag>`
- Run finished (UTC): YYYY-MM-DD HH:MM
- Browser: Claude in Chrome <version> | Playwright headless · Viewports: 1440 px, 390 px
- Plan: .claude/qa/plan.md @ `<sha>`

| Group | Run | Pass | Fail | Skipped (budget) | Blocked (ambiguity) | N/A |
|---|---|---|---|---|---|---|
| Spec 3 | | | | | | |
| Spec 4 | | | | | | |
| Spec 5 | | | | | | |
| Spec 6 | | | | | | |
| Spec 7 | | | | | | |
| Spec 8 | | | | | | |
| Spec 9 | | | | | | |
| Scenario keywords | | | | | | |
| Copy | | | | | | |
| Disclosure | | | | | | |
| Money formats | | | | | | |
| Wireframes | | | | | | |
| Live status | | | | | | |
| Accessibility | | | | | | |
| Console | | | | | | |
| Brief matrix | | | | | | |
| **Total** | | | | | | |

**Budget:** generations <used> / 25 (first pass) · AI suggestions <used> / 5 · re-verification <used> / 10 and <used> / 2

**Issues opened:** #N `qa:<severity>` <title> · ...
**Issues closed (verified):** #N (check IDs) · ...
**Blocked by spec ambiguity:** <check ID>: <links to both readings>

**Exit criteria:** <met | not met: list open blocker/major>
```
