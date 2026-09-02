# CommerceFlow — Core Domain & Database Design Spec

**Date:** 2026-09-01
**Status:** Approved (rev 3 — post Round 2 review & user arbitration; user sign-off 2026-09-01)
**Scope:** Core Domain Model + ERD/Database design (Phases 1–5 of the CommerceFlow roadmap)
**Related decision log:** `docs/superpowers/brainstorms/2026-09-01-commerceflow-ecommerce-platform-brainstorm.md`

## 1. Problem

CommerceFlow is an E-Commerce + Inventory + Order Management platform intended as a production-oriented portfolio project. The current requirements describe a rich domain (products, inventory with reserved stock, order lifecycle state machine, payments with idempotent webhooks, RBAC + dynamic menus, audit) but no concrete data model.

Before any code (Laravel API, Node.js gateway, Vue frontend) is written, the core domain must be defined precisely and translated into a PostgreSQL schema that is the single source of truth. Getting the domain model and ERD right first prevents rework across all downstream subsystems.

The hard part of this domain is **inventory concurrency**: preventing oversell (Available = Physical − Reserved must never go negative under concurrent checkouts) and tracking every stock change. This must be designed into the data model (locking, reservations, movements) rather than bolted on later.

## 2. Goals

- Define the complete set of business domains: identity/RBAC, catalog, inventory, cart, order, payment, shipment, audit.
- Produce a PostgreSQL schema (entities, relationships, key columns, constraints) that is the source of truth for all subsystems.
- Model **Physical / Reserved / Available** stock such that `Available = Physical − Reserved` holds invariantly and oversell is structurally prevented.
- Model **stock reservations** with TTL-based expiry and full traceability via stock movements.
- Model the **order lifecycle** as a controlled state machine (no arbitrary status transitions), with cancellation, expiry, and refund paths that correctly release stock.
- Model **payment** with idempotency for webhook replay.
- Model **RBAC** (users/roles/permissions) and **hierarchical dynamic menus** (menus/menu_permissions).
- Provide a foundation that supports concurrency tests (100 concurrent checkouts, stock=50, oversold=0).

## 3. Non-Goals

- No Laravel/Vue/Node implementation details, controllers, routes, or API contracts in this spec (separate specs).
- No variant model — flat products only (decided).
- No multi-warehouse/location stock — single global inventory (decided).
- No payment-provider integration specifics; only the domain record + idempotent webhook contract shape.
- No production infrastructure, CI/CD, or Docker in this spec.
- No frontend menu rendering / UI logic.
- No multi-currency support.

## 4. Design Principles

1. **Database is the source of truth.** All business invariants that can be enforced by the DB (constraints, unique keys, FK, check constraints) are enforced there, not only in application code.
2. **Single inventory.** `available = physical - reserved` computed/derived from base columns; never store a denormalized available that can drift.
3. **Traceability.** Every stock change writes a `stock_movements` row (immutable ledger). No silent stock mutation.
4. **Controlled transitions.** Orders, reservations, and payments transition only through valid states; invalid transitions are rejected.
5. **Concurrency safety first.** Oversell prevention is designed into the model (row locks + reservation ledger), not an afterthought.
6. **Minimal denormalization.** Store what is needed for integrity; derive aggregates (available stock, order totals) rather than caching where it risks inconsistency.
7. **Separation of identity.** One `users` table for all actors; authorization via role→permission, never hardcoded role checks.
8. **Pessimistic, layered locking.** Redis per-product lock reduces contention; DB `SELECT ... FOR UPDATE` inside the transaction is the final correctness guard.

## 5. Acceptance Scenarios

> Gherkin — these are the acceptance criteria the schema/domain model must satisfy.

### Scenario 5.1: Available stock formula holds
```
Feature: Inventory available stock
  Scenario: Available equals physical minus reserved
    Given a product with physical stock = 10 and no reservations
    When the available stock is computed
    Then available = 10

  Scenario: Reservation reduces available
    Given a product with physical stock = 10
    When a reservation of 7 is created
    Then reserved = 7
    And available = 3
```

### Scenario 5.2: No oversell under concurrency
```
Feature: Oversell prevention
  Scenario: Concurrent checkouts cannot oversell
    Given a product with physical stock = 50
    When 100 concurrent checkout requests each reserve 1 unit
    Then the number of successful reservations <= 50
    And oversold (reserved > physical) = 0
    And physical stock is never decremented below 0

  Scenario: Individual failing checkout is rejected
    Given a product with physical stock = 1
    When a checkout requests 2 units
    Then the reservation is rejected with a conflict
    And available stock remains 1
```

### Scenario 5.3: Reservation expiry releases stock
```
Feature: Reservation TTL expiry
  Scenario: Expired reservation releases reserved stock
    Given a product with reserved = 7 and reserved_until in the past
    When the expiry job runs
    Then the reservation is marked EXPIRED
    And the order is transitioned to EXPIRED
    And reserved decreases by 7
    And available increases by 7
    And physical stock is unchanged
```

### Scenario 5.4: Order lifecycle state transitions
```
Feature: Order state machine
  Scenario: Order is paid after reservation
    Given an order in state RESERVED
    When payment succeeds
    Then order becomes PAID

  Scenario: Order is packed after payment
    Given an order in state PAID
    When the order is packed
    Then order becomes PACKED

  Scenario: Order is shipped after packing
    Given an order in state PACKED
    When the order is shipped
    Then order becomes SHIPPED

  Scenario: Order is completed after delivery
    Given an order in state SHIPPED
    When the order is delivered
    Then order becomes COMPLETED

  Scenario: Invalid transition is rejected
    Given an order in state CREATED
    When an API attempts to set it to COMPLETED directly
    Then the transition is rejected

  Scenario: Reserved order can be cancelled and stock released
    Given an order in state RESERVED with active reservations
    When the order is cancelled
    Then the order becomes CANCELLED
    And each active reservation is released (RELEASED)
    And reserved stock is decremented
    And available stock is restored

  Scenario: Paid order can be refunded and stock released
    Given an order in state PAID with consumed reservations
    When the order is refunded
    Then the order becomes REFUNDED
    And physical stock is restored via a RETURN movement

  Scenario: Shipped order can be refunded after return
    Given an order in state SHIPPED
    When the order is refunded
    Then the order becomes REFUNDED
    And physical stock is restored via a RETURN movement
```

### Scenario 5.5: Payment webhook idempotency
```
Feature: Payment webhook idempotency
  Scenario: Duplicate payment event is not processed twice
    Given a payment with a unique NOT NULL event/transaction identifier
    When the same webhook event is delivered twice
    Then the first delivery processes the payment
    And the second delivery is detected as already processed
    And the payment is not processed twice

  Scenario: Payment arriving after reservation expiry is rejected cleanly
    Given an order whose reservation has already expired (EXPIRED)
    When a payment webhook for that order arrives
    Then the payment is rejected and the order remains EXPIRED (not PAID)
```

### Scenario 5.6: Stock movements ledger
```
Feature: Stock movement traceability
  Scenario: Reservation writes a movement row
    Given a product with physical stock = 10 and reserved stock = 0
    When a reservation of 3 is created
    Then a stock_movements row of type RESERVATION is created
    And reserved stock becomes 3

  Scenario: Adjustment writes a movement row
    Given a product with physical stock = 10
    When a stock adjustment of +5 is performed
    Then a stock_movements row of type ADJUSTMENT is created
    And physical stock becomes 15

  Scenario: No stock field mutates without a movement row
    Given a product
    When any stock change occurs
    Then the change writes a stock_movements row in the same transaction
```

### Scenario 5.7: RBAC permission model
```
Feature: Role-based access
  Scenario: User access is derived from role permissions
    Given a user with a role that has permission "products.view"
    When the user's permissions are queried
    Then "products.view" is present
    And the user cannot access actions requiring "products.delete"

  Scenario: Dynamic menu is filtered by permission
    Given a user lacking the permission required by a menu item
    When /me/menus is queried
    Then the menu item is not present in the returned tree
```

### Scenario 5.8: Cart checkout
```
Feature: DB-persisted cart checkout
  Scenario: Cart is checked out exactly once
    Given an active cart with a product
    When checkout reserves stock for the cart
    Then a single order is created
    And the cart transitions to checked_out
    And a second checkout attempt on the same cart is rejected
```

### Scenario 5.9: Shipment state machine
```
Feature: Shipment fulfillment
  Scenario: Shipment follows valid state transitions
    Given a paid order
    When a shipment is created
    Then the shipment is in state CREATED
    When the shipment is packed
    Then the shipment is in state PACKED
    When the shipment is shipped
    Then the shipment is in state SHIPPED
    When the shipment is delivered
    Then the shipment is in state DELIVERED
```

### Scenario 5.10: Audit of critical actions
```
Feature: Audit logging
  Scenario: Stock adjustment is audited
    Given an inventory adjustment is performed
    When the adjustment occurs
    Then an audit_logs row is created with before/after values
    And the acting user and timestamp are recorded
```

## 6. Design

### 6.1 Domain overview

Identified domains and their actors:

| Domain | Entities | Primary actors |
|--------|----------|----------------|
| Identity & RBAC | users, roles, permissions, role_permissions | all |
| Catalog | products | admin/warehouse/customer(view) |
| Inventory | inventories, stock_movements, stock_reservations | admin/warehouse |
| Cart | carts, cart_items | customer |
| Order | orders, order_items | customer, admin, warehouse |
| Payment | payments, payment_transactions | customer, admin |
| Shipment | shipments | warehouse |
| Menu | menus, menu_permissions | all (dynamic nav) |
| Audit | audit_logs | system |

### 6.2 Business rules

**Catalog (flat products):**
- A product has a name, SKU (unique), description, price (integer cents), status (draft/active/archived).
- Deleting a product with order history is forbidden → soft-delete / archive only.
- **Product creation also creates its inventory row** (application rule enforced at creation; the inventory row has physical=0, reserved=0).
- Menus reference a `route` and `name` only — no presentational icon column.

**Inventory (single global):**
- One inventory row per product.
- `physical_stock`, `reserved_stock`; `available = physical - reserved` (computed).
- Constraints: `physical_stock >= 0`, `reserved_stock >= 0`, `reserved_stock <= physical_stock`.
- Movement types: PURCHASE, SALE, RESERVATION, RELEASE, ADJUSTMENT, RETURN. **(TRANSFER removed — single inventory has no source/destination.)**
- Movement semantics:
  - **PURCHASE**: physical += qty.
  - **RESERVATION**: reserved += qty (physical unchanged).
  - **SALE** (consumption on payment): reserved -= qty AND physical -= qty.
  - **RELEASE** (expiry / cancellation): reserved -= qty, **physical unchanged** (restores available).
  - **RETURN** (refund): physical += qty.
  - **ADJUSTMENT**: physical ±= qty (manual, audited).
- Any stock mutation writes a `stock_movements` row (before/after physical & reserved) in the same transaction.

**Stock reservation:**
- A reservation references an **order** (single, explicit owner — no "cart-checkout" hedge), a product, an inventory row, a quantity, a state, and a `reserved_until`.
- **One ACTIVE reservation per (order, product) line** — enforced by a partial UNIQUE index `UNIQUE (order_id, product_id) WHERE state = 'ACTIVE'`.
- States: ACTIVE, EXPIRED, RELEASED, CONSUMED.
- `reserved_until = created_at + TTL` (configurable, e.g. 15–30 min).
- **Expiry and consumption are order-scoped and atomic.** Any job/handler that expires or consumes stock operates on **ALL** of an order's ACTIVE reservations in a **single transaction**: it transitions the order exactly once, decrements reserved once per reservation, and writes one RELEASE/SALE movement per reservation. Target-state transitions are idempotent (skip if the order is already in the target state) so a late sibling reservation can still release its stock.
- Expiry job: for each order with ACTIVE reservations past `reserved_until`, transition the order RESERVED→EXPIRED **exactly once** and mark all of its ACTIVE reservations EXPIRED with RELEASE movements (reserved only) in one transaction.
- On payment: all ACTIVE reservations become CONSUMED via a guarded update (`WHERE state = 'ACTIVE'`), reserved→physical conversion via SALE movements, in one transaction.
- Cancellation of a RESERVED order releases all of its ACTIVE reservations (→ RELEASED) via RELEASE movements in the same transaction.

**Concurrency (both layers, decided):**
- **Redis pessimistic lock per product** (`product:{id}:stock`):
  - Acquire with retry + timeout.
  - **TTL/lease ~10 s** (equal or above worst-case transaction duration).
  - **Release in a `finally` block** on every exit path (success, conflict, exception).
  - **Owner token**: the lock value stores a unique token (UUID/random); release uses compare-and-delete against that token so a slow holder cannot release another request's lease.
  - If a lock is found but expired-and-held (lease expired), the holder is treated as dead and the lock can be re-acquired safely.
  - **Lock-acquisition timeout outcome**: after retries expire, fall through to the DB-only path (`FOR UPDATE` remains the correctness guard) and surface a defined 503 to the client.
- **Multi-item checkout**: all cart products are locked in a **deterministic order** (ascending `inventory_id`) for both the Redis locks and the `SELECT ... FOR UPDATE` selects; one order spans all `cart_items` in a single transaction.
- **Single global lock order**: inventory rows are locked **before** reservation rows in **both** the expiry job and payment consumption (inventory → reservation), eliminating AB-BA deadlock; deadlock victims (SQLSTATE 40001) are retried with a bounded retry.
- Within a DB transaction: `SELECT ... FOR UPDATE` on each inventory row (in deterministic order) → re-check availability → insert reservations → update reserved_stock → create order → COMMIT.
- **Order is created as RESERVED** inside the checkout transaction (the reservation and order are born together); the standalone CREATED state is used only when an order is created without a reservation (rare; e.g. manual). Insufficient stock → rollback + HTTP 409 Conflict.
- If the reservation flow's `SELECT ... FOR UPDATE` returns **zero rows** (no inventory row), this is handled distinctly from "insufficient stock": it indicates a missing inventory row → treat as a 500/internal consistency error (a product should always have an inventory row), not a 409.

**Cart (DB-persisted):**
- `carts` owned by a user (customer); `cart_items` reference product + qty. Status: `active | checked_out` (no `abandoned` — cart abandonment is out of scope for this spec).
- **Checkout locks the cart row** and sets status `checked_out` inside the reserve transaction; a second checkout attempt on an already-checked-out cart is rejected.
- **Empty carts cannot be checked out** (reject when the cart has no items).
- Cart qty must not exceed available stock at add-time (checked again at checkout).

**Order lifecycle state machine (with full release paths):**
```
CREATED → RESERVED → PAID → PACKED → SHIPPED → COMPLETED
CREATED → CANCELLED                       (no reservation to release)
RESERVED → CANCELLED                      (release ACTIVE reservations → RELEASED)
RESERVED → EXPIRED                        (via expiry job, releases stock)
PAID → REFUNDED                           (restore physical via RETURN)
PACKED → REFUNDED                         (restore physical via RETURN)
SHIPPED → REFUNDED                        (restore physical via RETURN)
COMPLETED → REFUNDED                      (restore physical via RETURN)
```
- Allowed transitions are explicit; invalid transitions rejected.
- Order totals derived from order_items (unit_price × qty), stored as integer cents.

**State machine enforcement owner (F2.3):**
- Each state machine declares its enforcement owner:
  - **orders**: an application-layer state-machine service (in the API plan) is the enforcement owner; the `status` column stores the current state. The DB schema adds a CHECK on the allowed status values but transition legality is enforced by the service. (DB trigger/state_transitions table is a possible future hardening, not in this spec.)
  - **stock_reservations**: transitions guarded by `UPDATE ... WHERE state = 'ACTIVE'` (DB-level conditional update) plus application enforcement.
  - **payments**: application-layer enforcement with explicit transition set (below).
  - **shipments**: application-layer enforcement with explicit transition set (below).

**Payment:**
- Payments states: PENDING, PAID, FAILED, EXPIRED, REFUNDED.
- **Explicit payment transition set (F2.20):** `PENDING → PAID | FAILED | EXPIRED`; `FAILED → PAID | FAILED` (provider retry — the reservation stays ACTIVE through the failed attempt until TTL); `PENDING → EXPIRED` (together with the order expiring); `PAID → REFUNDED`. Enforced by the application state-machine service.
- Webhook idempotency: a **single NOT NULL unique idempotency key** (`idempotency_key` = provider event_id, or a deterministic digest e.g. `sha256(provider_reference + amount + status)`).
- **The idempotency insert and the payment-status update commit atomically in one transaction** (or the transaction row is inserted in an explicit PENDING state with a recoverable transition), so a crash cannot leave a permanently-skipped payment.
- **Rejected webhooks are still recorded (F2.9):** when a webhook is rejected (e.g. reservation already expired), the `payment_transactions` row is committed with status `REJECTED` (or `FAILED`) and the rejection reason **before/atomically alongside** the rejection, so redelivery dedups on the key and the rejection is traceable.
- **Amount verification (F2.7):** payment success requires `payment_transactions.amount_cents == orders.total_cents` (checked inside the consumption transaction). On mismatch, reject the webhook, record the discrepancy, and make no state change. (Full-payment model; no partial payments in scope. Optionally also a DB CHECK `payments.amount_cents = orders.total_cents`.)
- `payment_transactions` records provider-side attempts (status enumerated); `payments` is the domain record.
- **Payment consumption is conditional**: re-select the reservation `FOR UPDATE`; if the reservation is EXPIRED/RELEASED (TTL elapsed during in-flight payment), reject the payment, record the rejection, and keep the order EXPIRED (no SALE movement, no oversell).

**Inventory adjustment (F2.6):**
- A downward `ADJUSTMENT` that would violate `reserved_stock <= physical_stock` first cancels/expires the reservations it renders unsupported (RELEASE movements, reserved decrement) and then applies the ADJUSTMENT — all in one transaction — so a legitimate downward count correction is always possible while preserving the invariant.

**RBAC:**
- `users` (single table) → many `roles` via the `role_user` join table (single association — no role_id alternative) → many `permissions` via `role_permissions`.
- A permission string like `products.view`. Backend is the source of truth.
- `permissions` has no guard column (single auth context).

**Menus (hierarchical):**
- `menus` with `parent_id` self-reference, `name`, `route`, `sort`.
- `menu_permissions` maps a menu item to the permission(s) required to see it.
- `/me/menus` returns the menu tree filtered by the user's permissions.

**Audit (selective critical actions):**
- `audit_logs`: actor, action, entity_type, entity_id, before/after (JSON), timestamp.
- Recorded for auth, product, inventory, order, payment operations (e.g. stock adjustment always audited).

### 6.3 ERD (entity list with key columns)

```
users
  id PK, name, email UNIQUE, password_hash, created_at, updated_at

roles
  id PK, name UNIQUE, description?, created_at, updated_at

role_user
  role_id FK, user_id FK, PK(role_id, user_id)

permissions
  id PK, name UNIQUE, created_at, updated_at

role_permissions
  role_id FK, permission_id FK, PK(role_id, permission_id)

menus
  id PK, parent_id FK(nullable self), name, route?, sort INT, created_at, updated_at

menu_permissions
  menu_id FK, permission_id FK, PK(menu_id, permission_id)

products
  id PK, sku UNIQUE, name, description?, price_cents BIGINT CHECK(>=0), status ENUM(draft,active,archived),
  created_at, updated_at, deleted_at (soft delete)

inventories
  id PK, product_id FK UNIQUE, physical_stock INT CHECK(>=0), reserved_stock INT CHECK(>=0),
  CHECK(reserved_stock <= physical_stock), updated_at

stock_movements
  id PK, inventory_id FK, type ENUM(PURCHASE,SALE,RESERVATION,RELEASE,ADJUSTMENT,RETURN),
  quantity INT CHECK(quantity > 0), before_physical INT, after_physical INT, before_reserved INT, after_reserved INT,
  order_id FK?, reservation_id FK?, reason?, created_at,
  CHECK ( (order_id IS NOT NULL)::int + (reservation_id IS NOT NULL)::int <= 1 )
  -- sign convention: quantity is always positive; direction is implied by movement type (e.g. PURCHASE +, SALE −, ADJUSTMENT ± via before/after)

stock_reservations
  id PK, order_id FK, product_id FK, inventory_id FK, quantity INT CHECK(>0),
  state ENUM(ACTIVE,EXPIRED,RELEASED,CONSUMED), reserved_until TIMESTAMP, created_at, updated_at,
  UNIQUE (order_id, product_id) WHERE state = 'ACTIVE'

carts
  id PK, user_id FK, status ENUM(active,checked_out), created_at, updated_at

cart_items
  id PK, cart_id FK, product_id FK, quantity INT CHECK(>0), price_cents BIGINT, created_at, updated_at,
  UNIQUE(cart_id, product_id)

orders
  id PK, user_id FK(customer), cart_id FK UNIQUE NULLS NOT DISTINCT, order_number UNIQUE,
  status ENUM(CREATED,RESERVED,PAID,PACKED,SHIPPED,COMPLETED,CANCELLED,EXPIRED,REFUNDED),
  total_cents BIGINT GENERATED ALWAYS AS (/* see §6.6 */) STORED, created_at, updated_at

order_items
  id PK, order_id FK, product_id FK, unit_price_cents BIGINT, quantity INT CHECK(>0),
  line_total_cents BIGINT, created_at

payments
  id PK, order_id FK, amount_cents BIGINT CHECK(amount_cents = orders.total_cents /* F2.7 */),
  status ENUM(PENDING,PAID,FAILED,EXPIRED,REFUNDED), created_at, updated_at

payment_transactions
  id PK, payment_id FK, idempotency_key TEXT NOT NULL UNIQUE, provider_reference?,
  status ENUM(PENDING,SUCCEEDED,FAILED,REJECTED), amount_cents BIGINT, raw_payload JSONB, created_at

shipments
  id PK, order_id FK, tracking_number?, status ENUM(CREATED,PACKED,SHIPPED,DELIVERED), shipped_at?, delivered_at?, created_at, updated_at

audit_logs
  id PK, user_id FK?, action, entity_type, entity_id, before JSONB?, after JSONB?, created_at
```

Notes:
- `users.phone`, `payments.payment_method`, `payments.user_id`, `orders.customer_address`, `orders.currency`, `order_items.product_name_snapshot`, `shipments.carrier`, `menus.icon`, `permissions.guard`, `inventories.version`, `audit_logs.ip`/`user_agent` are **removed** — not consumed by any Goal/scenario/flow in scope.
- `orders.cart_id UNIQUE NULLS NOT DISTINCT` — the `?` marker is dropped: a cart can back at most one order; manual orders (no cart) are allowed (multiple NULL cart rows), which `NULLS NOT DISTINCT` semantics resolve deterministically. `?` in the ERD now means column optionality only.
- `stock_movements.product_id` removed — derived via `inventories.product_id`. `inventory_id` is the FK. `order_id`/`reservation_id` replace the polymorphic `reference_type/reference_id`; CHECK ensures at most one is set. Where a movement belongs to a reservation, the order is derivable via `stock_reservations.order_id`; `order_id` is used directly for non-reservation movements (SALE/RETURN).
- `audit_logs` uses `entity_type`/`entity_id` as an intentional, documented exemption to the anti-polymorphism rule (audit spans heterogeneous entities; a typed reference per entity would multiply columns with no enforcement gain).
- All money columns use `BIGINT`; `stock_movements.quantity` uses the always-positive sign convention.
- `orders.total_cents` is defined as a generated column enforcing `total_cents == SUM(order_items.line_total_cents)` (see §6.6).

### 6.4 Concurrency design (detailed)

**Checkout reserve flow (transaction, multi-item):**
1. Load the cart and its items. Sort items by `inventory_id` ascending (deterministic lock order).
2. Acquire Redis locks on `product:{id}:stock` **in ascending product_id order** (per-product pessimistic locks; retry with timeout; **TTL ~10 s**; owner token stored in each lock value).
3. Begin DB transaction.
4. `SELECT ... FOR UPDATE` on the **cart row**; if cart status != active → rollback (double-submit rejected).
5. If cart has no items → rollback (empty cart rejected).
6. `SELECT ... FOR UPDATE` on the **inventory rows** **in ascending inventory_id order** (matching the Redis lock order).
   - **Zero rows** for any product → rollback, internal consistency error (missing inventory row), not a 409.
7. Recompute available per product; if `available < requested` for any line → rollback, return conflict (409).
8. Create the **order** (state RESERVED) + order_items.
9. Insert `stock_reservations` (state ACTIVE, reserved_until = now + TTL, order_id = the new order) — one per order line.
10. Update `inventories.reserved_stock += qty` per product.
11. Insert `stock_movements` (type RESERVATION, before/after, order_id) per product.
12. Update cart status → checked_out.
13. Commit; **release all Redis locks in `finally`** (compare-and-delete by token).
- The DB `FOR UPDATE` is the final correctness guard; Redis lock reduces contention/retries. A product with no inventory row is impossible by rule (product creation creates the row).
- On lock-acquisition timeout after retries: fall through to the DB-only path (`FOR UPDATE` still guards correctness) and surface a defined 503.

**Reservation expiry job (scheduled, order-scoped):**
- Find orders with ACTIVE reservations where `reserved_until < now`.
- **For each order, one atomic transaction:**
  1. Lock the order's inventory rows `FOR UPDATE` (ascending inventory_id).
  2. Transition the order RESERVED→EXPIRED **exactly once** (idempotent: if already EXPIRED, skip the transition but still release remaining ACTIVE reservations).
  3. Mark **all** of the order's ACTIVE reservations EXPIRED.
  4. Decrement reserved_stock per reservation; write one RELEASE movement per reservation (reserved only, physical unchanged).
  5. Commit.
- Guarantee: no order can be left with a mix of ACTIVE and EXPIRED reservations after the job; multi-line orders expire atomically.

**Payment consumption (on payment success, order-scoped):**
- In a transaction:
  1. Verify `payment_transactions.amount_cents == orders.total_cents`; on mismatch → **reject the payment** (record discrepancy, no state change).
  2. Lock the order's inventory rows `FOR UPDATE` (ascending inventory_id).
  3. Re-select the order's reservations `FOR UPDATE`.
  4. If any reservation is EXPIRED/RELEASED (TTL elapsed in-flight) → **reject the payment**, keep order EXPIRED; no SALE movement; record the rejection. Return a defined error.
  5. If all ACTIVE → guarded `UPDATE stock_reservations SET state='CONSUMED' WHERE order_id=? AND state='ACTIVE'`; if 0 rows matched → abort (concurrent expiry won), same as step 4.
  6. Transition order RESERVED→PAID (exactly once).
  7. Decrement reserved AND physical via SALE movement(s) per reservation.
  8. Commit.
- **Global lock order (F2.5):** both expiry and consumption lock inventory rows **before** reservation rows. Deadlock victims (SQLSTATE 40001) are retried with a bounded retry.
- Because consumption is conditional on ACTIVE and both paths run under the same global lock order, a PAID order can never have an EXPIRED/RELEASED reservation and physical stock can never be decremented below reserved.

**Cancellation flow (RESERVED or CREATED order):**
- For a RESERVED order: in an order-scoped transaction with `FOR UPDATE` on inventory (ascending) → set each ACTIVE reservation RELEASED → decrement reserved_stock → write RELEASE movements → transition order RESERVED→CANCELLED (exactly once). Physical unchanged.
- For a CREATED order (no reservation): transition CREATED→CANCELLED directly.
- **Refund flow (PAID/PACKED/SHIPPED/COMPLETED → REFUNDED):** restore physical stock via RETURN movement(s) in an order-scoped transaction.

### 6.5 Idempotency design

- `payment_transactions.idempotency_key TEXT NOT NULL UNIQUE`.
- Webhook handler (single transaction):
  1. Begin transaction.
  2. Attempt to insert a `payment_transactions` row with the idempotency_key (initial status PENDING).
  3. On unique violation → the event was already processed → **rollback & skip** (idempotent; treat as already handled).
  4. On success → process: verify amount, consume reservations, update `payments.status`, transition order.
  5. **On rejection** (e.g. amount mismatch, reservation expired): commit the row with status `REJECTED` (or `FAILED`) plus the rejection reason **atomically** — so redelivery dedups on the key and the rejected attempt is traceable.
  6. On success: commit the row as `SUCCEEDED` with the status updates **atomically**.
- Because the insert and the status updates (including rejections) are in one transaction, a crash rolls back everything; redelivery retries cleanly (no permanently-skipped payment, no unbounded rejection loop).
- The idempotency key is **NOT NULL**: a provider that supplies no event_id gets a deterministic digest (e.g. `sha256(provider_reference + amount + status)`), so dedup always works.

### 6.6 Order totals integrity

- `total_cents` is enforced as a **generated column** (or trigger) on `orders` such that `total_cents == SUM(order_items.line_total_cents)`; order_items are immutable after creation, so the total cannot drift.
- `line_total_cents = unit_price_cents × quantity` (BIGINT avoids overflow).
- `price_cents` snapshot on order_items preserves historical price at order time.
- Alternative (if generated columns are impractical in the chosen migration tool): store `total_cents` with a trigger recomputing it on order_items insert, or remove the stored column and derive on read. The spec's default is the generated-column enforcement.

## 7. Implementation Phases

This spec covers **domain + database only**. Suggested build order for the data layer (each phase ships its first acceptance test before the migration is done — test-first):
- P1: Identity & RBAC tables (users, roles, permissions, role_user, role_permissions). **First test: SC 5.7 RBAC permission model.**
- P2: Catalog (products) + Inventory (inventories, stock_movements, stock_reservations) + constraints. **Concurrency harness (SC 5.2) is built in this phase** so the no-oversell invariant is verified before downstream phases. **First test: SC 5.2 no-oversell harness + SC 5.1 available formula.**
- P3: Cart (carts, cart_items). **First test: SC 5.8 cart checkout exactly once.**
- P4: Order (orders, order_items) + state machine (incl. cancel/expire/refund release paths). **First test: SC 5.4 state transition table.**
- P5: Payment (payments, payment_transactions) + idempotency. **First test: SC 5.5 idempotency.**
- P6: Shipment (shipments). **First test: SC 5.9 shipment state machine.**
- P7: Menu (menus, menu_permissions). **First test: SC 5.7 permission-filtered menu tree.**
- P8: Audit (audit_logs). **First test: SC 5.10 audit before/after.**
- P9: Migrations + seeders (roles, permissions, menus, demo data).

## 8. Testing Strategy

Each migration phase in Section 7 ships with its **acceptance tests written first (test-first)** before the schema/migration is considered done (see the phase→first-test mapping above).

**Verification commands (concrete):**
- Run full suite: `php artisan migrate:fresh --seed && php artisan test`
- **Pure-SQL smoke verification (schema standalone):** `psql -f apps/api/database/schema.sql` then assert via query: `reserved_stock <= physical_stock` and `physical_stock >= 0` across all rows; enum values valid. Makes each migration phase testable without Laravel wiring.
- Concurrency harness (SC 5.2): a test that seeds `physical_stock = 50`, fires 100 concurrent checkout requests each reserving 1 unit, and asserts `successful_reservations <= 50`, `oversold == 0`, `physical_stock >= 0`. Invoked via the test suite (e.g. `php artisan test --filter=ConcurrencyTest`).
- Constraint checks: `php artisan test --filter=InventoryConstraintTest` and/or the psql query above.

**Test categories (mapped to scenarios):**
- Unit: movement type → before/after math; available computation; order state transition table (SC 5.1, 5.4).
- **Ledger: every stock mutation (PURCHASE/SALE/RESERVATION/RELEASE/ADJUSTMENT/RETURN) writes a stock_movements row, and no stock field mutates without a corresponding movement row (SC 5.6).**
- Integration: reservation flow persists reservation + movement + reserved update atomically; cart checkout → checked_out (SC 5.8, 5.6).
- Concurrency: 100 concurrent checkouts on stock=50 → successful reservations ≤ 50, oversold = 0, physical never < 0 (SC 5.2); individual failing checkout → conflict (SC 5.2).
- Expiry: expired reservation releases stock exactly once, order → EXPIRED, physical unchanged; multi-line order expires atomically (SC 5.3).
- Cancellation/refund: RESERVED order cancel releases ACTIVE reservations; PAID order refund restores physical via RETURN; refund from SHIPPED/COMPLETED allowed (SC 5.4).
- Payment race: payment arriving after expiry is rejected cleanly, no oversell (SC 5.5).
- Idempotency: duplicate payment event processed once; crash-safe redelivery; rejected webhook recorded once (SC 5.5).
- Amount integrity: payment with wrong amount is rejected, no state change (SC 5.5).
- Shipment state machine (SC 5.9).
- Audit: stock adjustment writes audit_logs with before/after (SC 5.10).
- Menu: permission-filtered /me/menus (SC 5.7).
- Constraint tests: reserved_stock ≤ physical_stock; negative stock rejected (SC 5.1).

## 9. File Inventory

Specification (this spec + decision log):
- `docs/superpowers/specs/2026-09-01-commerceflow-core-domain-database-design.md`
- `docs/superpowers/brainstorms/2026-09-01-commerceflow-ecommerce-platform-brainstorm.md`

Database artifacts (to be created in the API implementation plan):
- `apps/api/database/migrations/*` (one per entity/constraint)
- `apps/api/database/seeders/*` (roles, permissions, menus, demo catalog)
- Domain model classes (Eloquent models) mapping the above entities

## 10. Out of Scope

- Laravel controllers/services/DTOs, API routes, OpenAPI.
- Node.js realtime gateway.
- Vue frontend.
- Docker/CI/CD.
- Actual payment provider integrations (only the domain + webhook contract shape).
- Variants, multi-warehouse, inventory transfer between warehouses.
- Multi-currency.
- Address/fulfillment-address modeling (shipment tracking only).
