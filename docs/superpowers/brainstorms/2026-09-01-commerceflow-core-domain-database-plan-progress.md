# Plan Progress: CommerceFlow Core Domain & Database

**Date Started:** 2026-09-01
**Status:** Done
**Current Phase:** finalizing
**Source Spec:** docs/superpowers/specs/2026-09-01-commerceflow-core-domain-database-design.md
**Based On:**
**Final Plan:** docs/superpowers/plans/2026-09-01-commerceflow-core-domain-database.md
**Last Updated:** 2026-09-01 21:55

## Plan Writing Status

- [x] Initial draft complete (time: 2026-09-01 21:38)
- [x] Round 1 revision (time: 2026-09-01 21:44)
- [x] Round 2 revision (time: 2026-09-01 21:52)
- [ ] Round 3 revision
- [x] Final sign-off (time: 2026-09-01 21:55)

## Sample Matching (Step 3)

**Samples selected (0):** No semantically relevant samples in `samples/plans/INDEX.md` — the library only contains superpowers tooling/skill-workflow plans (document review, visual brainstorming, zero-dep server, codex compatibility, worktree rototill), nothing in the Laravel/PostgreSQL domain-model space. Multi-reviewer runs with **6 fixed reviewers** (architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer), **0 exemplar-matchers**.

## Review Progress

> Plan tasks must include **Acceptance Criteria:** (Gherkin) per writing-plans Step 4.

### Round 1 [⏳ in progress]

**Dispatched reviewers (6):** architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer

**Receipt Status:** architect ✓ | red-team ✓ | edge-cases ✓ | yagni-gatekeeper ✓ | bdd-reviewer ✓ | tdd-reviewer ✓

**Round metadata:** dispatched_count: 6 | successful_receipt_count: 6 | excluded_roles: none

**Findings:**

| ID | Sev | Location | Reviewer | Problem | Arbiter | Status |
|----|-----|----------|----------|---------|---------|--------|
| F1.1 | B | Task 1/4 | red-team | Harness DB env mismatch (sqlite test vs pgsql workers) | KEEP | ✓ FIXED |
| F1.2 | B | Task 6-7 | red-team+edge-cases+bdd+tdd | Reservation TTL expiry job missing | KEEP | ✓ FIXED |
| F1.3 | B | Task 6/8 | red-team+tdd | Cancel/refund release paths untested | KEEP | ✓ FIXED |
| F1.4 | B | Task 3 | tdd-reviewer | Ledger coverage incomplete (6 movement types) | KEEP | ✓ FIXED |
| F1.5 | I | Task 4/7 | architect | Reservation lifecycle ownership scattered | KEEP | ✓ FIXED |
| F1.6 | I | Task 6 | architect | CheckoutService duplicates reserve logic | KEEP | ✓ FIXED |
| F1.7 | I | Task 6 | red-team+edge-cases | cart_id NULLS NOT DISTINCT forbids manual orders | KEEP | ✓ FIXED |
| F1.8 | I | Task 10/3 | red-team | F2.6 downward adjustment not implemented | KEEP | ✓ FIXED |
| F1.9 | I | Task 6 | edge-cases | totals trigger stale on DELETE | KEEP | ✓ FIXED |
| F1.10 | I | Task 7 | edge-cases | Already-PAID order + different key not rejected | KEEP | ✓ FIXED |
| F1.11 | I | Task 6/7 | edge-cases | Lock order checkout vs webhook unspecified | KEEP | ✓ FIXED |
| F1.12 | I | Task 1 | yagni | schema.sql stub remove | KEEP | ✓ FIXED |
| F1.13 | I | Task 4 | tdd-reviewer | RefreshDatabase vs subprocess seed isolation | KEEP | ✓ FIXED |

**Arbiter Output:**
- counts: raw=28 → dedup=22 → after_filter=13 (B=4, I=9, N=9)
- degradation_check: N/A
- convergence_status: CONTINUE
- arbiter_rationale: Four reviewers converged on missing expiry task, cancel/refund impl, harness DB mismatch; cart_id conflict arbitrated toward plain UNIQUE per spec §6.3. 13 revision instructions warrant Round 2.

### Appendix (NITs) — Round 1

- F1.14: Task 6/7 — define OrderTransitions::advance minimal contract.
- F1.15: Task 4 — distinct exit codes (409 vs 500/503) + max_connections.
- F1.16: Task 6/7 — totals trigger COALESCE for zero-item orders.
- F1.17: Task 2 — email uniqueness via lower(email) index.
- F1.18: Task 11 — idempotent seeders.
- F1.19: Task 8 — remove shipments tracking/at columns.
- F1.20: Task 5 — remove cart_items.price_cents.
- F1.21: Task 8 — split combined Gherkin step.
- F1.22: Task 11 — anchor seeder scenarios to spec §7 P9/§9.

### Round 2 [⏳ in progress]

**Dispatched reviewers (6):** architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer

**Receipt Status:** architect ✓ | red-team ✓ | edge-cases ✓ | yagni-gatekeeper ✓ | bdd-reviewer ✓ | tdd-reviewer ✓

**Round metadata:** dispatched_count: 6 | successful_receipt_count: 6 | excluded_roles: none

**Findings:**

| ID | Sev | Location | Reviewer | Problem | Arbiter | Status |
|----|-----|----------|----------|---------|---------|--------|
| F2.1 | B | Task 3/4 | architect+red-team | orders FK before orders table; harness order | KEEP | ✓ FIXED |
| F2.8 | B | Task 7 | red-team+edge-cases | Webhook rejects only EXPIRED, not RELEASED/CANCELLED | KEEP | ✓ FIXED |
| F2.14 | B | Task 4 | edge-cases | Harness false-green (<=50) | KEEP | ✓ FIXED |
| F2.2 | I | Task 3 | architect | Movement math owned by no unit | KEEP | ✓ FIXED |
| F2.3 | I | Task 10 | architect+red-team | Per-reservation release for adjustment | KEEP | ✓ FIXED |
| F2.9 | I | Task 11 | red-team+edge-cases | Expiry batch aborts on one raced order | KEEP | ✓ FIXED |
| F2.10 | I | Task 5 | red-team | cart_items.price_cents dropped vs spec | KEEP | ✓ FIXED |
| F2.16 | I | Task 6 | edge-cases | Refund idempotency on CONSUMED | KEEP | ✓ FIXED |
| F2.17 | I | Task 10 | edge-cases | Adjustment negative delta guard | KEEP | ✓ FIXED |
| F2.21 | I | Task 12 | yagni | Remove DemoCatalogSeeder | KEEP | ✓ FIXED |
| F2.24 | I | Task 6 | bdd-reviewer | Missing forward-transition + invalid ACs | KEEP | ✓ FIXED |
| F2.25 | I | Task 6 | bdd-reviewer+tdd | Missing SHIPPED/COMPLETED→REFUNDED AC | KEEP | ✓ FIXED |
| F2.26 | I | Task 6 | bdd-reviewer | Missing SC 5.8 checked_out AC | KEEP | ✓ FIXED |

**Arbiter Output:**
- counts: raw=30 → dedup=24 → after_filter=13 (B=3, I=10, N=10)
- degradation_check: FAILED
- convergence_status: STOP_DEGENERATE
- arbiter_rationale: Round 2 effective (13) equals round 1 (13); no convergence. Dedups pure overlap; cart_id/price_cents source-of-truth disputes retained. User arbitration: I1 — Fix all, skip round 3. All findings FIXED in final plan.

### Appendix (NITs) — Round 2

- F2.4: Task 2/9 — centralize permission resolution (User::permissionNames()).
- F2.5: Task 6 — CheckoutService only acquires locks ascending; reserve() sole availability gate.
- F2.11: Task 7 — payments row created (PENDING) at checkout.
- F2.13: Task 4 — record Redis-lock stub as arbitrated scope cut (API plan follow-up).
- F2.18: Task 6 — order_items unit/line CHECK >= 0.
- F2.19: Task 4 — document connection ceiling vs max_connections.
- F2.22: Task 3 — products.deleted_at removed.
- F2.23: Task 7 — defer provider_reference/raw_payload.
- F2.29: Task 1 — rename "Run-to-fail" step to "Run-to-pass".
- F2.30: Spec §8 — schema.sql smoke: note deliberately out of scope (migrations + psql checks replace it).

### Appendix (NITs)

### Round 2 [...]

---

## User Intervention Decisions

### I1 [✓ decided]
**Triggered in round:** Round 2 (STOP_DEGENERATE)
**Related finding:** F2.1–F2.26
**Reason for intervention:** Loop degenerated (Round 2 effective = 13, same as Round 1 = 13); arbiter escalated unresolved BLOCKING/IMPORTANT findings to the user.
**Options Presented:**
- A: Fix all + round 3
- B: Fix all, skip round 3
- C: Arbitrate individually
**User Decision:** B — Fix all, skip round 3
**Rationale:** User confirmed all 13 findings are valid; fix them all and finalize the plan for sign-off without an additional review round.
**Timestamp:** 2026-09-01 21:50

---

## Context Reference

### Source Spec Summary
> CommerceFlow core domain & database spec (approved). Goals: complete business domain set (identity/RBAC, catalog, inventory, cart, order, payment, shipment, audit); PostgreSQL schema as source of truth; Physical/Reserved/Available stock with no-oversell invariant; TTL-based reservations with stock-movement traceability; controlled order lifecycle incl. cancel/expire/refund; payment webhook idempotency; RBAC + hierarchical dynamic menus; concurrency-testable foundation (100 concurrent checkouts, stock=50, oversold=0). Non-Goals: no variants, single global inventory, no payment-provider integration, no multi-currency, no address modeling.

### User's Launch Instruction
> "sudah oke, bisakah kita mulai implementasi file?" → "Yes, start planning (Recommended)"