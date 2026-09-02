# Brainstorming: CommerceFlow E-Commerce Platform

**Date Started:** 2026-09-01
**Status:** Done
**Current Phase:** finalizing
**Based On:**
**Final Spec:** docs/superpowers/specs/2026-09-01-commerceflow-core-domain-database-design.md
**Last Updated:** 2026-09-01 21:35

## Original User Request

> (Full CommerceFlow requirement document — a monorepo E-Commerce + Inventory + Order Management platform.
> Customer storefront + Backoffice; Laravel core backend; Node.js realtime gateway; Vue 3 frontend;
> Redis; PostgreSQL; JWT auth; RBAC + permission; dynamic menu; REST API; Swagger; WebSocket; Docker; CI/CD;
> automated testing; architecture & business-flow documentation. Detailed in the user's message.)

---

## Phase A: Alignment Decision Log

### Q0: Session gate / worktree & scope
**Options Presented:**
- A: No, work in place
- B: Yes, create isolated worktree
- (scope) Brainstorm whole project first / backend core only / requirements-ERD only
**Decision:** Create a new git branch `dev` and work in place; brainstorm the whole project first.
**Rationale:** User wants a dedicated `dev` branch; full-project brainstorm first, then decompose into plans.
**Timestamp:** 2026-09-01 21:23

### Q1: Scope deliverable / spec granularity
**Options Presented:**
- A: Split into multiple specs (Core Domain & ERD, Laravel API + Auth/RBAC, Realtime Gateway, Vue Frontend)
- B: One mega-spec for everything
- C: Backend-first split only
**Decision:** A — split into multiple specs
**Rationale:** Whole project brainstorm first; decompose into separate brainstorm+spec+plan cycles per subsystem.
**Timestamp:** 2026-09-01 21:24

### Q2: Which subsystem first
**Options Presented:**
- A: Core Domain & Database/ERD
- B: Laravel API + Auth/RBAC
- C: Monorepo setup first
**Decision:** A — Core Domain & Database/ERD
**Rationale:** Phase 1–5 from the roadmap; the domain model & ERD is the foundation all other subsystems build on.
**Timestamp:** 2026-09-01 21:24

### Q3: Product/variant model
**Options Presented:**
- A: Products with variants
- B: Flat products only
**Decision:** B — Flat products only
**Rationale:** No variants; stock/price tracked directly per product. Simpler, matches SKU-level inventory.
**Timestamp:** 2026-09-01 21:24

### Q4: Inventory location
**Options Presented:**
- A: Single inventory (global)
- B: Multi-warehouse (per location)
**Decision:** A — Single inventory
**Rationale:** One global stock source; Available = Physical - Reserved; stock movements still recorded. Matches requirement formula.
**Timestamp:** 2026-09-01 21:24

### Q5: Reservation timing
**Options Presented:**
- A: Reserve at order creation (same transaction)
- B: Separate reserve step
**Decision:** A — Reserve at order creation
**Rationale:** Matches requirement flow: BEGIN TRANSACTION → Lock Stock → Check → Create Reservation → Update Stock → Create Order → COMMIT.
**Timestamp:** 2026-09-01 21:24

### Q6: Reservation expiry
**Options Presented:**
- A: TTL-based expiry (configurable, e.g. 15-30 min) via scheduled job
- B: No auto-expiry
- C: Custom
**Decision:** A — TTL-based expiry
**Rationale:** Reserved stock released via scheduled job when payment not made in time; configurable TTL. Adds reserved_until + expiry job.
**Timestamp:** 2026-09-01 21:24

### Q7: Concurrency locking
**Options Presented:**
- A: DB row lock (SELECT ... FOR UPDATE)
- B: Redis distributed lock
- C: Both DB + Redis
**Decision:** C — Both DB + Redis
**Rationale:** Redis pessimistic lock per-product as primary gate + DB FOR UPDATE transaction as final guard; double layer against oversell under high concurrency.
**Timestamp:** 2026-09-01 21:24

### Q8: Cart persistence
**Options Presented:**
- A: DB-persisted cart (carts + cart_items)
- B: Frontend-only cart
- C: Hybrid (guest + user cart)
**Decision:** A — DB-persisted cart
**Rationale:** Cart persisted in PostgreSQL linked to customer; consistent across devices; order built from stored cart.
**Timestamp:** 2026-09-01 21:24

### Q9: Customer identity / actor model
**Options Presented:**
- A: Single users table (customer = role 'customer')
- B: Separate users + customers
- C: Separate but both login
**Decision:** A — Single users table
**Rationale:** One auth model for all actors; role determines access; customer-specific fields on users or a profile table.
**Timestamp:** 2026-09-01 21:24

### Q10: Menu model
**Options Presented:**
- A: Hierarchical menus (parent_id self-reference + menu_permissions)
- B: Flat menus
**Decision:** A — Hierarchical menus
**Rationale:** menus table with parent_id for hierarchy; menu_permissions links menu → required permission; backend filters by user permission.
**Timestamp:** 2026-09-01 21:24

### Q11: Audit logging scope
**Options Presented:**
- A: Selective critical actions
- B: Full CRUD audit
**Decision:** A — Selective critical actions
**Rationale:** audit_logs record actor, action, entity, before/after JSON, IP, UA, timestamp for sensitive operations (auth, product, inventory, order, payment).
**Timestamp:** 2026-09-01 21:24

---

### Phase A → B Transition Confirmation [2026-09-01 21:25]
**Alignment Summary (compiled by ds):**
- Decision 1: Work on new git branch `dev`; whole-project brainstorm first; split into multiple specs.
- Decision 2: First spec = Core Domain & Database/ERD.
- Decision 3: Flat products only (no variants); stock/price per product.
- Decision 4: Single global inventory (Available = Physical - Reserved).
- Decision 5: Reserve stock at order creation, same transaction.
- Decision 6: TTL-based reservation expiry via scheduled job; configurable.
- Decision 7: Concurrency via both Redis pessimistic lock (per-product) + DB FOR UPDATE in transaction.
- Decision 8: DB-persisted cart (carts + cart_items).
- Decision 9: Single users table; customer = role 'customer'.
- Decision 10: Hierarchical menus (parent_id self-reference) + menu_permissions.
- Decision 11: Audit log covers selective critical actions only.

**User Confirmation:** ✓ Confirmed

---

## Phase B: Spec Writing Status

- [x] Initial draft complete (time: 2026-09-01 21:26)
- [ ] Round 1 revision
- [ ] Round 2 revision
- [ ] Round 3 revision
- [ ] Final sign-off

## Sample Matching (Step 5)

**Samples selected (0):** No semantically relevant samples in `samples/specs/INDEX.md` — the library only contains superpowers tooling/skill-workflow specs (document review, visual brainstorming, zero-dep server, codex compatibility, worktree rototill), nothing in the e-commerce / domain-model / ERD domain. Multi-reviewer runs with **6 fixed reviewers** (architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer), **0 exemplar-matchers**.

## Phase B Review Progress

> Spec drafts must include ## Acceptance Scenarios (Gherkin) after Design Principles.

### Round 1 [✓ complete]

**Dispatched reviewers (6):** architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer

**Receipt Status:** architect ✓ | red-team ✓ | edge-cases ✓ | yagni-gatekeeper ✓ | bdd-reviewer ✓ | tdd-reviewer ✓

**Round metadata:** dispatched_count: 6 | successful_receipt_count: 6 | excluded_roles: none

**Findings:**

| ID | Sev | Location | Reviewer | Problem | Arbiter | Status |
|----|-----|----------|----------|---------|---------|--------|
| F1.8 | B | §6.4 | red-team | Payment vs expiry race → oversell/unretryable | KEEP | ✓ FIXED |
| F1.9 | B | §6.2 | red-team | RELEASE semantics contradict SC 5.3 | KEEP | ✓ FIXED |
| F1.16 | B | §6.2 | red-team | No RESERVED→CANCELLED stock release | KEEP | ✓ FIXED |
| F1.30 | B | §5 | bdd-reviewer | Missing scenarios: cart/shipment/audit/menu | KEEP | ✓ FIXED |
| F1.32 | B | §8 | tdd-reviewer | No concrete verification commands | KEEP | ✓ FIXED |
| F1.1 | I | §6.4 | architect | order_id FK before order created | KEEP | ✓ FIXED |
| F1.2 | I | §6.2 | architect | State machines not cross-linked | KEEP | ✓ FIXED |
| F1.3 | I | §6.3 | architect | stock_movements dual product/inventory refs | KEEP | ✓ FIXED |
| F1.4 | I | §6.3 | architect | Polymorphic reference not FK-enforceable | KEEP | ✓ FIXED |
| F1.11 | I | §6.5 | red-team | Idempotency insert + update not atomic | KEEP | ✓ FIXED |
| F1.12 | I | §6.3/6.5 | red-team | event_id NULL defeats dedup | KEEP | ✓ FIXED |
| F1.17 | I | §6.4 | edge-cases | Redis lock no TTL/release | KEEP | ✓ FIXED |
| F1.18 | I | §6.2/6.4 | edge-cases | No inventory row / zero-row handling | KEEP | ✓ FIXED |
| F1.19 | I | §6.3/6.4 | edge-cases | Cart double-checkout | KEEP | ✓ FIXED |
| F1.23 | I | §6.2/6.3/5.6 | yagni | Remove TRANSFER | KEEP | ✓ FIXED |
| F1.24 | I | §6.3 | yagni | Remove inventories.version | KEEP | ✓ FIXED |
| F1.25 | I | §6.3 | yagni | Remove orders.customer_address | KEEP | ✓ FIXED |
| F1.26 | I | §6.3 | yagni | Remove payments.user_id | KEEP | ✓ FIXED |
| F1.31 | I | §5.2 | bdd-reviewer | No failing-checkout rejected scenario | KEEP | ✓ FIXED |
| F1.33 | I | §8 | tdd-reviewer | No test-first ordering | KEEP | ✓ FIXED |
| F1.10 | - | §6.2 | red-team | Cancel release (dup of F1.16) | DEDUP | - |
| F1.14 | - | §6.2 | red-team | Order EXPIRED trigger (dup of F1.2) | DEDUP | - |
| F1.15 | - | §6.4 | edge-cases | Payment vs expiry race (dup of F1.8) | DEDUP | - |
| F1.21 | - | §6.3 | edge-cases | version column (dup of F1.24) | DEDUP | - |

**Arbiter Output:**
- counts: raw=34 → dedup=30 → after_filter=20 (B=5, I=15, N=10)
- degradation_check: N/A
- convergence_status: CONTINUE
- arbiter_rationale: All 34 findings evidence-backed; four deduped (cancel path, order EXPIRED trigger, payment-vs-expiry race, version column). Five BLOCKING share a theme — expiry/cancel/payment interleavings can corrupt stock & state machines — must resolve before concurrency design is sound. Continue to round 2.

### Appendix (NITs)

- F1.5: §6.6 — tie orders.total_cents to order_items via generated column/trigger or view.
- F1.6: §6.2 — state role_user only, drop "(or role_id)" hedge.
- F1.7: §6.3 — enumerate payment_transactions.status (PENDING/SUCCEEDED/FAILED).
- F1.13: §6.4 — clarify order created in RESERVED state or add explicit CREATED→RESERVED step.
- F1.20: §6.3 — use BIGINT for price/total columns to avoid overflow.
- F1.22: §6.3/6.4 — reject empty-cart checkout / guard ≥1 order item.
- F1.27: §6.2 — remove menus.icon (presentational).
- F1.28: §6.3 — remove orders.currency (single currency).
- F1.29: §6.3 — remove permissions.guard (framework boilerplate).
- F1.34: §8 — annotate testing-strategy bullets with scenario refs 5.1–5.7.

### Round 2 [✓ complete]

**Dispatched reviewers (6):** architect | red-team | edge-cases | yagni-gatekeeper | bdd-reviewer | tdd-reviewer

**Receipt Status:** architect ✓ | red-team ✓ | edge-cases ✓ | yagni-gatekeeper ✓ | bdd-reviewer ✓ | tdd-reviewer ✓

**Round metadata:** dispatched_count: 6 | successful_receipt_count: 6 | excluded_roles: none

**Findings:**

| ID | Sev | Location | Reviewer | Problem | Arbiter | Status |
|----|-----|----------|----------|---------|---------|--------|
| F2.1 | B | §6.4 | red-team+edge-cases | Multi-reservation order expiry/consume not atomic → stock leak | KEEP | ✓ FIXED |
| F2.2 | I | §6.4 | architect | Multi-item checkout lock order unspecified | KEEP | ✓ FIXED |
| F2.3 | I | §6.2/6.3 | architect | State machine enforcement owner undeclared | KEEP | ✓ FIXED |
| F2.4 | I | §6.6 | architect | total_cents mechanism dangling "appendix F1.5" | KEEP | ✓ FIXED |
| F2.5 | I | §6.4 | red-team+edge-cases | Expiry vs consumption inverted lock order deadlock | KEEP | ✓ FIXED |
| F2.6 | I | §6.2/6.3 | red-team | Downward ADJUSTMENT blocked by reserved<=physical | KEEP | ✓ FIXED |
| F2.7 | I | §6.2/6.4 | red-team+edge-cases | Payment amount not verified vs order total | KEEP | ✓ FIXED |
| F2.8 | I | §6.2 | red-team | SHIPPED/COMPLETED→REFUNDED missing | KEEP | ✓ FIXED |
| F2.9 | I | §6.5 | edge-cases | Rejected webhook rolls back idempotency insert | KEEP | ✓ FIXED |
| F2.10 | I | §6.3 | yagni | Remove users.phone | KEEP | ✓ FIXED |
| F2.11 | I | §6.3 | yagni | Remove payments.payment_method | KEEP | ✓ FIXED |
| F2.12 | I | §6.3 | yagni | Remove carts.status 'abandoned' | KEEP | ✓ FIXED |
| F2.13 | I | §6.3 | yagni | Remove order_items.product_name_snapshot | KEEP | ✓ FIXED |
| F2.14 | I | §6.3 | yagni | Remove shipments.carrier | KEEP | ✓ FIXED |
| F2.15 | I | §8 | tdd-reviewer | SC 5.6 ledger test not mapped | KEEP | ✓ FIXED |
| F2.29 | - | §6.4 | edge-cases | Multi-reservation leak (dup of F2.1) | DEDUP | - |
| F2.30 | - | §6.4 | edge-cases | Lock-order deadlock (dup of F2.5) | DEDUP | - |
| F2.31 | - | §6.2/6.4 | edge-cases | Amount mismatch (dup of F2.7) | DEDUP | - |
| F2.32 | - | §6.2 | edge-cases | Payment transition matrix (dup of F2.20) | DEDUP | - |
| F2.33 | - | §6.3 | red-team | carts 'abandoned' (dup of F2.12) | DEDUP | - |

**Arbiter Output:**
- counts: raw=33 → dedup=28 → after_filter=15 (B=1, I=14, N=13)
- degradation_check: FAILED
- convergence_status: STOP_DEGENERATE
- arbiter_rationale: 15 effective findings remain; round-2 effective (15) > 50% of round-1 (10), so reviewers are re-reporting core defects (multi-line atomicity, lock ordering, amount verification) rather than converging. Escalate to user arbitration.

### Appendix (NITs) — Round 2

- F2.16: §6.3 — decide `orders.cart_id` uniqueness semantics; reserve `?` for optionality.
- F2.17: §6.3 — keep order_id only for non-reservation movements or document dual ref as intentional.
- F2.18: §6.3 — audit_logs: apply anti-polymorphism or document exemption.
- F2.19: §6.4 — Redis lock: store unique token, release via compare-and-delete.
- F2.20: §6.2 — define explicit payment transition rules incl. FAILED→PAID.
- F2.21: §6.4 — state Redis lock-acquisition timeout outcome.
- F2.22: §6.3 — stock_movements quantity sign convention + per-type CHECKs.
- F2.23: §6.3 — remove audit_logs.ip / user_agent.
- F2.24: §5 — SC 5.4: split multi-When into one-When-per-scenario.
- F2.25: §5 — SC 5.9: assert intermediate states or split scenarios.
- F2.26: §5 — SC 5.6: make Given concrete or Scenario Outline.
- F2.27: §8 — add pure-SQL smoke verification (psql).
- F2.28: §8 — add phase→first-test mapping.

### Round 3 [pending]

---

## Phase B User Intervention Decisions

### I1 [✓ decided]
**Triggered in round:** Round 2 (STOP_DEGENERATE)
**Related finding:** F2.1–F2.15
**Reason for intervention:** Loop degenerated (effective count 15 > 50% of round-1 20); arbiter escalated unresolved BLOCKING/IMPORTANT findings to the user.
**Options Presented:**
- A: Accept & fix all (all findings are valid & improve the spec)
- B: Fix all + run round 3
- C: Arbitrate individually
**User Decision:** A — Accept & fix all
**Rationale:** User confirmed all 15 findings are valid; fix them all, then finalize the spec for sign-off.
**Timestamp:** 2026-09-01 21:30


