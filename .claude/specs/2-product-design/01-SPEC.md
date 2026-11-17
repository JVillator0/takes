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
| `demo_account_locked` (shared demo login, settings page; D-072) | "Shared demo accounts can't change their name, email or password. Register your own account to do that." |

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

UI chrome strings added with the UI foundation (PR #99). Keys are the full `lang/en/<file>.php` paths; `:name` is a server-filled placeholder. The estimate panel never states the margin (D-023): "You pay the exact amount when the take finishes." replaces the P04 sheet's "+ 25%" wording. `takes.estimate.max_not_average` is softened from the P05-A sheet ("…so a take can never leave you below zero") because 3-03 allows an actual charge above the hold, which is what the negative-balance banner is for.

| Key | Where | Copy |
|---|---|---|
| `takes.filter.label` | P04 grid filter | "Filter takes" |
| `takes.filter.all` | P04 grid filter | "All" |
| `takes.filter.done` | P04 grid filter | "Done" |
| `takes.filter.failed` | P04 grid filter | "Failed" |
| `takes.actions.prompt_copied` | P04 card, P06 drawer actions | "Prompt copied" |
| `takes.actions.hide_comparison` | P04 card, P06 drawer actions | "Hide comparison" |
| `takes.sizes.1024x1024` | P04 composer size | "Square" |
| `takes.sizes.1536x1024` | P04 composer size | "Landscape" |
| `takes.sizes.1024x1536` | P04 composer size | "Portrait" |
| `takes.quality.low` | P04 composer quality | "Low" |
| `takes.quality.medium` | P04 composer quality | "Medium" |
| `takes.quality.high` | P04 composer quality | "High" |
| `takes.card.title` | P04 take card | "Take #:id" |
| `takes.card.open` | P04 take card | "Open take #:id" |
| `takes.card.announcement` | P04 take card | "Take #:id: :status" |
| `takes.card.no_image` | P04 take card | "No image" |
| `takes.card.remix_of` | P04 take card | "Remix of #:id" |
| `takes.card.exact_amount` | P04 take card | "Exact amount :amount" |
| `takes.composer.title` | P04, P05, P10 composer | "New take" |
| `takes.composer.prompt` | P04, P05, P10 composer | "Prompt" |
| `takes.composer.prompt_placeholder` | P04, P05, P10 composer | "Describe your take…" |
| `takes.composer.prompt_tip` | P04, P05, P10 composer | "Tip: mention light, lens and mood" |
| `takes.composer.prompt_count` | P04, P05, P10 composer | ":count / :max" |
| `takes.composer.prompt_too_long` | P04, P05, P10 composer | "Prompt is too long: :count / :max characters." |
| `takes.composer.size` | P04, P05, P10 composer | "Size" |
| `takes.composer.quality` | P04, P05, P10 composer | "Quality" |
| `takes.composer.references` | P04, P05, P10 composer | "Reference images (optional, up to :max)" |
| `takes.composer.reference_url` | P04, P05, P10 composer | "Reference image :number URL" |
| `takes.composer.add_reference` | P04, P05, P10 composer | "Add reference URL" |
| `takes.composer.remove_reference` | P04, P05, P10 composer | "Remove reference image :number" |
| `takes.composer.reference_invalid` | P04, P05, P10 composer | "Reference images must be http(s) URLs." |
| `takes.composer.remixing` | P04, P05, P10 composer | "Remixing #:id" |
| `takes.composer.cancel_remix` | P04, P05, P10 composer | "Stop remixing #:id" |
| `takes.composer.submit_with_max` | P04, P05, P10 composer | "Generate take · up to :amount" |
| `takes.composer.submitting` | P04, P05, P10 composer | "Generating…" |
| `takes.composer.shortcut` | P04, P05, P10 composer | "Press :keys to generate" |
| `takes.estimate.title` | P04, P05-A estimate panel (no margin, D-023) | "Estimated price" |
| `takes.estimate.range` | P04, P05-A estimate panel (no margin, D-023) | ":min – :max" |
| `takes.estimate.updates` | P04, P05-A estimate panel (no margin, D-023) | "updates as you type" |
| `takes.estimate.idle` | P04, P05-A estimate panel (no margin, D-023) | "Describe your take to see its price." |
| `takes.estimate.loading` | P04, P05-A estimate panel (no margin, D-023) | "Updating the price…" |
| `takes.estimate.pay_exact` | P04, P05-A estimate panel (no margin, D-023) | "You pay the exact amount when the take finishes." |
| `takes.estimate.failed_free` | P04, P05-A estimate panel (no margin, D-023) | "Failed takes are free." |
| `takes.estimate.hold_note` | P04, P05-A estimate panel (no margin, D-023) | "We hold up to :amount from your balance while it runs." |
| `takes.estimate.insufficient` | P04, P05-A estimate panel (no margin, D-023) | "This take can cost up to :max. You have :available available." |
| `takes.estimate.max_not_average` | P04, P05-A estimate panel (no margin, D-023) | "We check the maximum, not the average, before a take starts." |
| `takes.estimate.error` | P04, P05-A estimate panel (no margin, D-023) | "We couldn't update the price. Keep typing or try again in a moment." |
| `takes.scenarios.title` | P04 Try a scenario chips (D-044) | "Try a scenario" |
| `takes.scenarios.add` | P04 Try a scenario chips (D-044) | "Add :keyword to the prompt" |
| `takes.scenarios.fail` | P04 Try a scenario chips (D-044) | "Fails while rendering. You are not charged." |
| `takes.scenarios.slow` | P04 Try a scenario chips (D-044) | "Takes 60 to 90 seconds instead of 5 to 30." |
| `takes.scenarios.duplicate` | P04 Try a scenario chips (D-044) | "Every status update arrives twice. You are charged once." |
| `takes.scenarios.blocked` | P04 Try a scenario chips (D-044) | "Declined by the content policy. You are not charged." |
| `takes.drawer.timeline` | P06 take drawer | "Timeline" |
| `takes.drawer.price` | P06 take drawer | "Price" |
| `takes.drawer.versions` | P06 take drawer | "Versions" |
| `takes.drawer.this_take` | P06 take drawer | "This take" |
| `takes.drawer.parent` | P06 take drawer | "Parent #:id" |
| `takes.drawer.loading` | P06 take drawer | "Loading take…" |
| `takes.drawer.estimate_was` | P06 take drawer | "estimate was :range" |
| `takes.drawer.hold_released` | P06 take drawer | "Up to :amount was held while it ran; the rest went back to your balance." |
| `takes.drawer.hold_voided` | P06 take drawer | "Up to :amount was held while it ran. All of it went back to your balance." |
| `takes.drawer.on_hold` | P06 take drawer | "on hold" |
| `takes.drawer.charged` | P06 take drawer | "charged" |
| `takes.drawer.not_charged` | P06 take drawer | "not charged" |
| `takes.timeline.created` | P06 timeline | "Created · up to :amount held" |
| `takes.timeline.queued` | P06 timeline | "Queued" |
| `takes.timeline.processing` | P06 timeline | "Processing" |
| `takes.timeline.done` | P06 timeline | "Done · charged :amount · rest of the hold released" |
| `takes.timeline.failed` | P06 timeline | "Failed · not charged · hold released" |
| `billing.available` | balance pill and Buy credits (P03, P04, P10) | "Available" |
| `billing.on_hold` | balance pill and Buy credits (P03, P04, P10) | "on hold" |
| `billing.balance_label` | balance pill and Buy credits (P03, P04, P10) | "Available :available, on hold :on_hold" |
| `billing.balance_exact` | balance pill tooltip (exact amounts, D-042) | "Exact: available :available, on hold :on_hold" |
| `landing.meta_title` | P01 landing | "Describe it. Take it. Remix it." |
| `landing.nav.label` | P01 landing | "Sections" |
| `landing.nav.how_it_works` | P01 landing | "How it works" |
| `landing.nav.pricing` | P01 landing | "Pricing" |
| `landing.nav.sign_in` | P01 landing | "Sign in" |
| `landing.nav.start_free` | P01 landing | "Start free" |
| `landing.nav.open_app` | P01 landing | "Open Takes" |
| `landing.hero.line_1` | P01 landing | "Describe it." |
| `landing.hero.line_2` | P01 landing | "Take it." |
| `landing.hero.line_3` | P01 landing | "Remix it." |
| `landing.hero.lead` | P01 landing | "Generate images, iterate on them as versions, and always see the price before you press Generate." |
| `landing.hero.start` | P01 landing | "Start with :amount free" |
| `landing.hero.demo` | P01 landing | "Try a demo account" |
| `landing.hero.no_card` | P01 landing | "No card. Credits are simulated for this demo." |
| `landing.example.label` | P01 landing | "Example project with five takes" |
| `landing.example.project` | P01 landing | "Lighthouse series" |
| `landing.example.count` | P01 landing | ":count takes" |
| `landing.example.take` | P01 landing | "take #:id" |
| `landing.example.take_warmer` | P01 landing | "take #:id · warmer" |
| `landing.example.take_portrait` | P01 landing | "take #:id · portrait" |
| `landing.steps.title` | P01 landing | "How it works" |
| `landing.steps.describe_title` | P01 landing | "Describe" |
| `landing.steps.describe` | P01 landing | "Write a prompt, pick size and quality. The price range shows before you start." |
| `landing.steps.take_title` | P01 landing | "Take" |
| `landing.steps.take` | P01 landing | "Every generation is a take, saved in a project, with its exact cost." |
| `landing.steps.remix_title` | P01 landing | "Remix" |
| `landing.steps.remix` | P01 landing | "Branch any take, or describe a change (“warmer, portrait”) and review it before it runs." |
| `landing.pricing.title` | P01 landing | "Clear pricing" |
| `landing.pricing.body` | P01 landing | "Pay per take from prepaid credits. See the price range before you generate, the exact amount after." |
| `landing.pricing.welcome` | P01 landing | "New accounts start with :amount of credits." |
| `landing.pricing.credit_rate` | P01 landing | "1 credit = $1.00." |
| `landing.pricing.caption` | P01 landing | "Example prices per take" |
| `landing.pricing.example_take` | P01 landing | "Example take" |
| `landing.pricing.price_range` | P01 landing | "Price range" |
| `landing.pricing.square_medium_short` | P01 landing | "Square · medium · short prompt" |
| `landing.pricing.landscape_high_two_refs` | P01 landing | "Landscape · high · 2 references" |
| `landing.footer` | P01 landing | "Takes is a take-home project. Payments are simulated." |
| `landing.shell.skip_to_content` | app shell skip link | "Skip to content" |

Describe a change (P07) chrome added with spec 8. Prices reuse `takes.money.on_hold`, `takes.estimate.range` and `takes.composer.submit_with_max`; Buy credits reuses `billing.buy_credits`; the 2-01 AI strings above are `suggestions.failed`, `suggestions.unavailable`, `suggestions.no_change`, `suggestions.charge_disclosure`, `suggestions.already_pending` and `suggestions.chips.*`.

| Key | Where | Copy |
|---|---|---|
| `suggestions.label` | P07 ask | "What should change?" |
| `suggestions.count` | P07 ask | ":count / :max" |
| `suggestions.quote` | P07 ask, price before action | "AI suggestion · up to :amount on hold" |
| `suggestions.quote_note` | P07 ask | "Charged even if you discard the suggestion." |
| `suggestions.suggest` | P07 ask button | "Suggest change" |
| `suggestions.thinking` | P07 thinking | "Thinking…" |
| `suggestions.review` | P07 proposal | "Review the proposal" |
| `suggestions.diff.prompt` | P07 proposal | "Prompt" |
| `suggestions.diff.setting` | P07 proposal table | "Setting" |
| `suggestions.diff.before` | P07 proposal table | "Before" |
| `suggestions.diff.after` | P07 proposal table | "After" |
| `suggestions.diff.size` | P07 proposal table | "Size" |
| `suggestions.diff.quality` | P07 proposal table | "Quality" |
| `suggestions.diff.references` | P07 proposal table | "References" |
| `suggestions.diff.references_with_image` | P07 proposal table | ":count + take image" |
| `suggestions.new_take` | P07 proposal | "New take: :range" |
| `suggestions.discard` | P07 proposal | "Discard" |
| `suggestions.discarded` | P07 result | "Suggestion discarded." |
| `suggestions.applied` | P07 result | "Your new take has started." |
| `suggestions.base_not_ready` | P07, take not finished | "Describe a change works on finished takes." |
| `suggestions.apply_unavailable` | P07, apply before spec 4 | "Generating a take from a suggestion is not available yet. Your suggestion is saved." |
| `suggestions.not_proposed` | P07, apply or discard from a stale tab (no charge claim: it may have been charged) | "This suggestion can no longer be used. Describe a new change." |
| `suggestions.request_key_reused` | P07, a request key replayed for another take | "This request was already sent for another take. Try again." |
