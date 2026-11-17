# Demo dataset (D-037)

Single source for every amount in the wireframes and for `DemoDataSeeder`. Moment: **2026-11-17 12:02 UTC**. All charges computed with Pixelforge rates (per token: text 5,000 · image 10,000 · output 40,000 · reasoning 12,500 nanodollars) and Haiku 5.5 (input 100 · output 500), charge = cost × 125/100.

## Customer "Ana López" (self-registered Nov 16), project "Lighthouse series"
| Take | When (UTC) | Params | Tokens (text/image/output/reasoning) | Cost (nano) | Charge (nano) | Status | Lineage |
|---|---|---|---|---|---|---|---|
| #6 | Nov 16 18:31 | 1024×1536 medium, 0 refs, "Portrait crop, fog" | 5 / 0 / 1650 / 1200 | 81,025,000 | 101,281,250 | done | original |
| #7 | Nov 16 18:40 | 1024×1024 medium, 0 refs, "Black and white version" | 6 / 0 / 1100 / 900 | 55,280,000 | 69,100,000 | done | original |
| #8 | Nov 16 18:52 | 1536×1024 medium, 1 ref, "Warmer light, low sun" | 6 / 765 / 1650 / 1000 | 86,180,000 | 107,725,000 | done | remix of #6 |
| #9 | Nov 17 11:40 | 1024×1024 low, "Lighthouse [fail]" | — | 0 | 0 | failed (not charged) | original |
| AI suggestion on #8 | Nov 17 11:58 | Haiku 5.5 | 1,500 in / 200 out | 250,000 | 312,500 | proposed → applied as #10 | — |
| #10 | Nov 17 12:01 | 1024×1024 medium, 1 ref, 64-char prompt | 16 / 765 / 1100 / 600 | 59,230,000 | 74,037,500 | done | AI change from #8 |
| #11 | Nov 17 12:01 | 1024×1024 medium, 24-char prompt "Night version with stars" | — | — | hold 86,293,750 | queued | remix of #10 |
| #12 | Nov 17 12:01 | 1024×1536 medium, 37-char prompt | — | — | hold 113,825,000 | processing | remix of #10 |
| #13 | Nov 17 12:02 | 1536×1024 medium, 50-char prompt | — | — | hold 113,843,750 | pending (waiting for capacity) | original |


## Ana's ledger (money only, D-024)
| When | Type | Reference | Amount | Balance after |
|---|---|---|---|---|
| Nov 16 18:20 | promo_grant | welcome credit | +1.000000000 | 1.000000000 |
| Nov 16 18:31 | charge | take #6 | −0.101281250 | 0.898718750 |
| Nov 16 18:40 | charge | take #7 | −0.069100000 | 0.829618750 |
| Nov 16 18:52 | charge | take #8 | −0.107725000 | 0.721893750 |
| Nov 17 10:02 | purchase | INV-2026-000004 | +10.000000000 | 10.721893750 |
| Nov 17 11:58 | charge | AI suggestion on #8 | −0.000312500 | 10.721581250 |
| Nov 17 12:01 | charge | take #10 | −0.074037500 | 10.647543750 |

At 12:02: **balance $10.64754375**, **on hold $0.3139625** (#11 + #12 + #13), **available $10.33358125** (pill: $10.3336).
Spent in November: images $0.35214375, AI suggestions $0.0003125, total **$0.35245625**. Profit generated (admin): $0.07049125. Takes: 8 (4 done, 1 failed, 3 in progress). Project spend shown on cards: $0.3525.

## Demo accounts (seeded)
| Account | Starting money |
|---|---|
| Demo customer | promo_grant $10.00 |
| Low balance | promo_grant $0.01 |
| New customer | none ($0.00) |
| Demo auditor (admin) | — |

## Platform aggregates used by admin sheets (A02, A03, A11)
| Day (UTC) | Takes | Failed | AI calls (1,500 in / 200 out each) | Pixelforge cost | Anthropic cost | Customer spend | Profit |
|---|---|---|---|---|---|---|---|
| Nov 17 | 41 | 2 | 9 | $1.640200 | $0.002250 | $2.0530625 | $0.4106125 |
| Nov 16 | 77 | 4 | 15 | $3.071200 | $0.003750 | $3.8436875 | $0.7687375 |
| Nov 15 | 64 | 1 | 4 | $2.391100 | $0.001000 | $2.990125 | $0.598025 |
| Total | 182 | 7 | 28 | $7.102500 | $0.007000 | $8.886875 | $1.777375 |
Nov 17 is still open (3 takes in flight) so reconciliation shows it as Pending, not Match.
