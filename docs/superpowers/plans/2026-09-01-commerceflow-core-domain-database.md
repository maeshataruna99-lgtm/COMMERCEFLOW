# CommerceFlow Core Domain & Database Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-deepseek-v4:subagent-driven-development (recommended) or superpowers-deepseek-v4:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved CommerceFlow core-domain PostgreSQL schema, Eloquent models, constraints, services, and data-layer tests inside a Laravel app at `apps/api/`, satisfying the no-oversell, reservation-TTL, order-state-machine, payment-idempotency, RBAC, menu, shipment, and audit invariants.

**Architecture:** A Laravel 11 application under `apps/api/` owns the database layer. Migrations define the schema + all DB-enforced invariants (CHECK constraints, partial unique indexes, NOT NULL idempotency key, trigger-maintained `orders.total_cents`). Eloquent models + PHP enums cast domain states. A single `StockReservationService` owns every reservation-state mutation (reserve/consume/release/expire); `CheckoutService`, `PaymentWebhookService`, expiry job, and cancellation/refund/adjustment flows are thin orchestrators over it. Tests drive every task (TDD): unit tests for enum/relations/movement math, integration tests for reservation/ledger atomicity, a concurrency harness proving oversold=0 under 100 concurrent checkouts, and lifecycle tests for expiry/cancel/refund. All tests run against a **shared pgsql test database** (not sqlite). Monorepo root scaffolding (pnpm workspace, docker-compose) is a separate plan; this plan builds `apps/api/` as a standalone runnable Laravel app.

**Tech Stack:** PHP 8.3, Laravel 11, PostgreSQL 15+, Eloquent, PHPUnit, `symfony/process` (concurrency harness), pgsql driver.

**Spec:** `docs/superpowers/specs/2026-09-01-commerceflow-core-domain-database-design.md`

---

## File Map

| Task | Create / Modify | Path |
|------|-----------------|------|
| 1 | Create | `apps/api/` (full Laravel app skeleton) |
| 1 | Modify | `apps/api/.env`, `apps/api/.env.example`, `apps/api/config/database.php`, `apps/api/phpunit.xml` |
| 2 | Create | `apps/api/database/migrations/*_create_users_table.php`, `*_create_roles_table.php`, `*_create_permissions_table.php`, `*_create_role_user_table.php`, `*_create_role_permissions_table.php` |
| 2 | Create | `apps/api/app/Models/User.php`, `Role.php`, `Permission.php`; `apps/api/tests/Feature/RbacTest.php` |
| 3 | Create | `apps/api/app/Enums/ProductStatus.php`, `InventoryMovementType.php`, `ReservationState.php`, `OrderStatus.php`; `apps/api/database/migrations/*_create_products_table.php`, `*_create_inventories_table.php`, `*_create_orders_table.php`, `*_create_stock_reservations_table.php`, `*_create_stock_movements_table.php`, `*_create_order_totals_trigger.php` |
| 3 | Create | `apps/api/app/Models/Product.php`, `Inventory.php`, `Order.php`, `OrderItem.php`, `StockMovement.php`, `StockReservation.php`; `apps/api/app/Services/MovementLedger.php`; `apps/api/tests/Feature/InventoryTest.php`; `apps/api/tests/Unit/InventoryMovementMathTest.php` |
| 4 | Create | `apps/api/app/Services/StockReservationService.php`; `apps/api/app/Services/OrderTransitions.php`; `apps/api/app/Console/Commands/ReserveAttempt.php`; `apps/api/tests/Feature/StockConcurrencyTest.php`; `apps/api/tests/Unit/OrderTransitionsTest.php` |
| 5 | Create | `apps/api/database/migrations/*_create_carts_table.php`, `*_create_cart_items_table.php`; `apps/api/app/Models/Cart.php`, `CartItem.php`; `apps/api/tests/Feature/CartTest.php` |
| 6 | Create | `apps/api/database/migrations/*_add_cart_fk_to_orders_table.php`; `apps/api/app/Services/CheckoutService.php`; `apps/api/app/Services/OrderLifecycleService.php`; `apps/api/tests/Feature/OrderStateMachineTest.php`; `apps/api/tests/Feature/OrderCancellationRefundTest.php` |
| 7 | Create | `apps/api/app/Enums/PaymentStatus.php`, `PaymentTransactionStatus.php`; `apps/api/database/migrations/*_create_payments_table.php`, `*_create_payment_transactions_table.php`; `apps/api/app/Models/Payment.php`, `PaymentTransaction.php`; `apps/api/app/Services/PaymentWebhookService.php`; `apps/api/tests/Feature/PaymentIdempotencyTest.php` |
| 8 | Create | `apps/api/app/Enums/ShipmentStatus.php`; `apps/api/database/migrations/*_create_shipments_table.php`; `apps/api/app/Models/Shipment.php`; `apps/api/tests/Feature/ShipmentStateMachineTest.php` |
| 9 | Create | `apps/api/database/migrations/*_create_menus_table.php`, `*_create_menu_permissions_table.php`; `apps/api/app/Models/Menu.php`; `apps/api/tests/Feature/MenuPermissionTest.php` |
| 10 | Create | `apps/api/app/Enums/InventoryAdjustmentType.php`; `apps/api/app/Services/InventoryAdjustmentService.php`; `apps/api/database/migrations/*_create_audit_logs_table.php`; `apps/api/app/Models/AuditLog.php`; `apps/api/tests/Feature/InventoryAdjustmentTest.php`; `apps/api/tests/Feature/AuditLogTest.php` |
| 11 | Create | `apps/api/app/Console/Commands/ExpireReservations.php`; `apps/api/app/Services/ReservationExpiryService.php`; `apps/api/tests/Feature/ReservationExpiryTest.php` |
| 12 | Create | `apps/api/database/seeders/RolePermissionSeeder.php`, `MenuSeeder.php`, `DatabaseSeeder.php`; `apps/api/tests/Feature/SeederTest.php` |

---

## Task 1: Bootstrap Laravel app + PostgreSQL test database + baseline

**Files:**
- Create: `apps/api/` — Laravel 11 app via `composer create-project`
- Modify: `apps/api/.env`, `apps/api/.env.example`, `apps/api/config/database.php`, `apps/api/phpunit.xml`

**Acceptance Criteria:**

Feature: Laravel tests run green against a shared pgsql test database
  Scenario: Fresh app runs the default test suite on pgsql
    Given a new Laravel app with pgsql connections configured
    When `php artisan test` is run
    Then it passes (exit code 0)
    And the test suite uses the `pgsql` connection against `commerceflow_test`

- [ ] **Step 1: Create the Laravel app** — run `composer create-project laravel/laravel:^11.0 apps/api` from the repo root.
- [ ] **Step 2: Configure database connections** — in `apps/api/.env`: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=commerceflow`, `DB_USERNAME=commerceflow`, `DB_PASSWORD=commerceflow`; add `DB_TEST_DATABASE=commerceflow_test`. Mirror to `.env.example`.
- [ ] **Step 3: Configure phpunit.xml** — replace the default sqlite block with `DB_CONNECTION=pgsql`, `DB_DATABASE=commerceflow_test` (a real, shared pgsql test DB; NOT sqlite `:memory:`). Ensure `config/database.php` `pgsql` connection supports both `commerceflow` and `commerceflow_test` via env.
- [ ] **Step 4: Create the test database** — run `createdb commerceflow_test` (or `psql -c "CREATE DATABASE commerceflow_test"`).
- [ ] **Step 5: Run-to-fail** — run `php artisan test`; the shipped baseline tests must pass on pgsql. Verify the pgsql driver and that `commerceflow_test` is reachable.
- [ ] **Step 6: Commit** — `git add apps/api && git commit -m "chore(api): bootstrap Laravel 11 app with pgsql test database"

## Task 2: Identity & RBAC schema + models

**Files:**
- Create: `apps/api/database/migrations/*_create_users_table.php`, `*_create_roles_table.php`, `*_create_permissions_table.php`, `*_create_role_user_table.php`, `*_create_role_permissions_table.php`
- Create: `apps/api/app/Models/User.php`, `apps/api/app/Models/Role.php`, `apps/api/app/Models/Permission.php`
- Create: `apps/api/tests/Feature/RbacTest.php`

**Acceptance Criteria:**

Feature: RBAC permission model
  Scenario: User permissions derive from role permissions
    Given a role "admin" with permission "products.view"
    And a user assigned that role
    When the user's permissions are queried
    Then "products.view" is present
    And a permission the role lacks is not present

  Scenario: users.email is unique
    Given a user with email a@example.com
    When a second user with the same email is inserted
    Then the insert is rejected with a unique violation

- [ ] **Step 1: Write the failing test** — `tests/Feature/RbacTest.php` asserting role→permission derivation and email uniqueness. Use a migration-based reset (`RefreshDatabase` is fine here; no subprocesses).
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=RbacTest`; expect failures (no tables yet).
- [ ] **Step 3: Create migrations** — `users` (name, email, password_hash, timestamps) with a `UNIQUE INDEX` on `lower(email)` (case-insensitive uniqueness); `roles` (name UNIQUE, description nullable); `permissions` (name UNIQUE); `role_user` (PK role_id+user_id); `role_permissions` (PK role_id+permission_id). All FKs `onDelete('cascade')`; `users` has no `phone` column.
- [ ] **Step 4: Create models** — `User` with `belongsToMany(Role::class)`; `Role` with `belongsToMany(User::class)` and `belongsToMany(Permission::class)`; `Permission` with `belongsToMany(Role::class)`. Add `hasPermission(string)` helper on `Role` checking `permissions.name`.
- [ ] **Step 5: Run-to-pass** — `php artisan test --filter=RbacTest`; all green.
- [ ] **Step 6: Commit** — `git add apps/api/database/migrations apps/api/app/Models apps/api/tests/Feature/RbacTest.php && git commit -m "feat(api): identity and rbac schema + models"

## Task 3: Catalog + Inventory + Order base schema + models + constraints + ledger

**Files:**
- Create: `apps/api/app/Enums/ProductStatus.php`, `apps/api/app/Enums/InventoryMovementType.php`, `apps/api/app/Enums/ReservationState.php`, `apps/api/app/Enums/OrderStatus.php`
- Create: `apps/api/database/migrations/*_create_products_table.php`, `*_create_inventories_table.php`, `*_create_orders_table.php`, `*_create_stock_reservations_table.php`, `*_create_stock_movements_table.php`
- Create: `apps/api/app/Models/Product.php`, `apps/api/app/Models/Inventory.php`, `apps/api/app/Models/Order.php`, `apps/api/app/Models/OrderItem.php`, `apps/api/app/Models/StockMovement.php`, `apps/api/app/Models/StockReservation.php`
- Create: `apps/api/app/Services/MovementLedger.php`
- Create: `apps/api/tests/Feature/InventoryTest.php`, `apps/api/tests/Unit/InventoryMovementMathTest.php`

**Rationale (F2.1, F2.2):** `stock_reservations`/`stock_movements` have NOT NULL FKs to `orders`, so the `orders` table (id, user_id FK, order_number UNIQUE, total_cents, status + CHECK; **no cart FK yet**) is created in this batch **before** `stock_reservations` and `stock_movements` (in that order). `order_items`, the totals trigger, and the `cart_id` FK are added in Task 6 once `carts` exists. The `Order` model + `OrderStatus` enum exist here so Task 4's harness can construct orders. A single `MovementLedger` unit owns the before/after math for all six movement types (single source of truth consumed by every later service).

**Acceptance Criteria:**

Feature: Inventory available stock and ledger
  Scenario: Available equals physical minus reserved
    Given a product with physical stock = 10 and reserved stock = 0
    When the inventory is loaded
    Then available = 10

  Scenario: Every movement type writes a ledger row
    Given a product with an inventory row
    When any of PURCHASE, SALE, RESERVATION, RELEASE, ADJUSTMENT, RETURN occurs
    Then a stock_movements row of that type is created with before/after values

  Scenario: No stock field mutates without a movement row
    Given an inventory row
    When a stock field changes in a transaction
    Then a matching stock_movements row is written in the same transaction

  Scenario: Oversell is rejected by the DB
    Given a product with physical stock = 1
    When an attempt is made to reserve 2
    Then the attempt fails (conflict) and physical never goes negative

  Scenario: reserved can never exceed physical
    Given an inventory row with physical = 5
    When reserved is set to 6
    Then the DB CHECK constraint rejects the write

  Scenario: Order row can be created before carts exist
    Given the orders table exists in this migration batch
    When an order is inserted without a cart_id
    Then the insert succeeds (cart FK is added later)

- [ ] **Step 1: Write failing tests** — `tests/Unit/InventoryMovementMathTest.php` asserting `MovementLedger` type → before/after deltas for **all six types** (PURCHASE, SALE, RESERVATION, RELEASE, ADJUSTMENT, RETURN — no TRANSFER), and `tests/Feature/InventoryTest.php` asserting the available formula, a ledger row per type, the no-mutation-without-movement invariant, oversell conflict, reserved<=physical CHECK, and a bare `orders` insert without cart.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter='Inventory'`; expect failures (no tables/enums).
- [ ] **Step 3: Create enums** — `ProductStatus` (draft/active/archived), `InventoryMovementType` (PURCHASE/SALE/RESERVATION/RELEASE/ADJUSTMENT/RETURN), `ReservationState` (ACTIVE/EXPIRED/RELEASED/CONSUMED), `OrderStatus` (CREATED/RESERVED/PAID/PACKED/SHIPPED/COMPLETED/CANCELLED/EXPIRED/REFUNDED), backed PHP enums.
- [ ] **Step 4: Create migrations (dependency order matters)** —
  - `products`: sku UNIQUE, name, description nullable, `price_cents` BIGINT `CHECK(price_cents >= 0)`, status enum default draft, timestamps. (No `deleted_at` — no deletion workflow in scope.)
  - `inventories`: `product_id` FK UNIQUE, `physical_stock` INT `CHECK(physical_stock >= 0)`, `reserved_stock` INT `CHECK(reserved_stock >= 0)`, `CHECK(reserved_stock <= physical_stock)`, timestamps.
  - `orders` **(before reservations/movements)**: user_id FK, `order_number` UNIQUE, status enum default CREATED + `CHECK (status IN (...))`, `total_cents` BIGINT `NOT NULL DEFAULT 0`, timestamps. **No cart FK in this migration** (added via ALTER in Task 6).
  - `stock_reservations`: `order_id` FK, `product_id` FK, `inventory_id` FK, `quantity` INT `CHECK(quantity > 0)`, state enum default ACTIVE, `reserved_until` timestamp, timestamps, plus raw pgsql: `CREATE UNIQUE INDEX stock_reservations_order_product_active ON stock_reservations (order_id, product_id) WHERE state = 'ACTIVE'`.
  - `stock_movements`: `inventory_id` FK, type enum, `quantity` INT `CHECK(quantity > 0)`, before/after physical+reserved INTs, `order_id` FK nullable, `reservation_id` FK nullable, `CHECK (CASE WHEN order_id IS NULL THEN 0 ELSE 1 END + CASE WHEN reservation_id IS NULL THEN 0 ELSE 1 END <= 1)` (sqlite-safe boolean arithmetic — no `::int` casts), reason nullable, timestamps.
- [ ] **Step 5: Create MovementLedger** — `MovementLedger` with a single `apply(Inventory, InventoryMovementType, int $qty): array{beforePhysical, afterPhysical, beforeReserved, afterReserved}` computing the six type deltas (PURCHASE: physical+; SALE: reserved−/physical−; RESERVATION: reserved+; RELEASE: reserved−; RETURN: physical+; ADJUSTMENT: physical±). `Inventory::available()` and all later services read deltas from this one unit.
- [ ] **Step 6: Create models** — `Product` (belongsTo Inventory, hasMany StockReservations), `Inventory` (belongsTo Product, hasMany StockMovements; `available` attribute delegating to `MovementLedger` formula), `Order` (belongsTo User, hasMany OrderItems/Reservations; status cast; `totalCents` attribute), `OrderItem` (belongsTo Order/Product; `line_total_cents = unit_price_cents * quantity`; `CHECK (unit_price_cents >= 0)`, `CHECK (line_total_cents >= 0)`), `StockMovement` (belongsTo Inventory, optional belongsTo Order / StockReservation), `StockReservation` (belongsTo Order/Product/Inventory; state enum cast).
- [ ] **Step 7: Run-to-pass** — `php artisan test --filter='Inventory'`; all green.
- [ ] **Step 8: psql smoke check** — `psql -d commerceflow_test -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public'"` returns ≥5 tables; verify CHECKs via `\d inventories`.
- [ ] **Step 9: Commit** — `git add apps/api/app/Enums apps/api/database/migrations apps/api/app/Models apps/api/app/Services apps/api/tests && git commit -m "feat(api): catalog, inventory, order base schema with constraints and ledger"`

## Task 4: Stock reservation service + OrderTransitions + concurrency harness

**Files:**
- Create: `apps/api/app/Services/StockReservationService.php`
- Create: `apps/api/app/Services/OrderTransitions.php`
- Create: `apps/api/app/Console/Commands/ReserveAttempt.php`
- Create: `apps/api/tests/Feature/StockConcurrencyTest.php`, `apps/api/tests/Unit/OrderTransitionsTest.php`

**Acceptance Criteria:**

Feature: No oversell under concurrency
  Scenario: 100 concurrent checkouts on stock=50 all succeed exactly once each
    Given a product with physical stock = 50 in the shared pgsql test DB, seed committed and visible
    When 100 concurrent reserve attempts of 1 unit each are run
    Then exactly 50 reservations succeed (successful_reservations == 50)
    And oversold (reserved > physical) = 0
    And post-run reserved_stock = 50 and physical stock is never decremented below 0

  Scenario: Individual failing checkout is rejected
    Given a product with physical stock = 1
    When a checkout requests 2 units
    Then the reservation is rejected with a conflict
    And available stock remains 1

Feature: Order state transition matrix
  Scenario: Illegal direct transition is rejected
    Given an order in state CREATED
    When OrderTransitions::advance attempts to set COMPLETED directly
    Then the transition is rejected

- [ ] **Step 1: Write failing tests** — `tests/Feature/StockConcurrencyTest.php`. **Seeding path is non-transactional**: use `RefreshDatabase` with a trait override that commits migrations, then seed `physical_stock = 50` and **commit** it so spawned workers can see it. **Spawn barrier**: before firing workers, confirm the seeded row is visible to a fresh connection. Spawn 100 concurrent workers via `symfony/process`, **passing the same DB_* env (commerceflow_test)** into each `Process` via its `env` option. **Assert `successful_reservations == 50` (NOT just `<= 50`)** — this prevents false-green when workers observe the seed late (0 successes would fail). Also assert post-run `reserved_stock == 50`, `oversold == 0`, `physical >= 0`. Also assert the single-failing scenario (2-unit request on stock=1 → conflict, available unchanged). Write `tests/Unit/OrderTransitionsTest.php` asserting each illegal transition is rejected and each legal one advances.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter='StockConcurrencyTest|OrderTransitionsTest'`; fails (no service/command).
- [ ] **Step 3: Create OrderTransitions** — `OrderTransitions::advance(Order, OrderStatus)` as the shared enforcement contract (mirrors `OrderStatus::canTransitionTo()`); used by Task 4's harness, Task 6's lifecycle, Task 7's webhook, and the future API plan.
- [ ] **Step 4: Create the service** — `StockReservationService` becomes the **sole owner of reservation-state mutation**, exposing `reserve(Inventory, int $qty, Order $order, Carbon $reservedUntil): StockReservation`, `consume(Order)`, `release(Order)`, `expire(Order)`, `releaseReservation(StockReservation)`. `reserve()` implements spec §6.4: inside `DB::transaction`, `lockForUpdate` the inventory row, recompute available via `MovementLedger`, reject if insufficient (throws domain exception → caller maps to 409), insert reservation + movement, increment reserved. Redis lock acquisition is stubbed here — this is an **accepted scope cut recorded in the plan-progress decision log (finding F2.13)**: no Redis in this plan; the DB `FOR UPDATE` remains the correctness guard. The API plan (follow-up) wires and tests the Redis layer. **Reserve is safe to call once per order line within an outer transaction.** `consume()`/`release()`/`expire()` are **idempotent no-ops for non-ACTIVE reservations** and `releaseReservation()` handles a single reservation (used by downward adjustment).
- [ ] **Step 5: Add the artisan command** — `app/Console/Commands/ReserveAttempt.php` invoking `reserve()`. **Distinct exit codes**: 0 = success, 2 = insufficient stock (conflict), 3 = connection/DB error, so the harness can assert all losses were availability conflicts.
- [ ] **Step 6: Run-to-pass** — `php artisan test --filter='StockConcurrencyTest|OrderTransitionsTest'`; assert exactly-50 success under the 100-way race. Set `max_connections` (or cap workers) so connection exhaustion cannot masquerade as conflict; compute the connection ceiling (workers + harness) explicitly.
- [ ] **Step 7: Commit** — `git add apps/api/app/Services apps/api/app/Console apps/api/tests/Feature/StockConcurrencyTest.php apps/api/tests/Unit/OrderTransitionsTest.php && git commit -m "feat(api): stock reservation service with oversell-proof concurrency harness"`

## Task 5: Cart schema + models

**Files:**
- Create: `apps/api/database/migrations/*_create_carts_table.php`, `*_create_cart_items_table.php`
- Create: `apps/api/app/Models/Cart.php`, `apps/api/app/Models/CartItem.php`
- Create: `apps/api/tests/Feature/CartTest.php`

**Acceptance Criteria:**

Feature: DB-persisted cart
  Scenario: Cart stores items with unique product lines
    Given a cart owned by a user
    When two lines are added for the same product
    Then the second insert is rejected (UNIQUE cart_id, product_id)

  Scenario: Quantity is positive
    Given a cart
    When a line with quantity 0 is added
    Then the insert is rejected (CHECK quantity > 0)

- [ ] **Step 1: Write failing test** — `tests/Feature/CartTest.php` covering unique (cart_id, product_id) and positive-quantity CHECK.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=CartTest`; fails.
- [ ] **Step 3: Create migrations** — `carts` (user_id FK, status enum `active|checked_out` default active, timestamps), `cart_items` (cart_id FK, product_id FK, quantity INT CHECK(> 0), `price_cents` BIGINT `CHECK(price_cents >= 0)` **kept per spec §6.3** (price snapshot on the cart line), UNIQUE(cart_id, product_id), timestamps). No `abandoned` status value.
- [ ] **Step 4: Create models** — `Cart` (belongsTo User, hasMany CartItems, status enum cast), `CartItem` (belongsTo Cart/Product).
- [ ] **Step 5: Run-to-pass** — `php artisan test --filter=CartTest`; all green.
- [ ] **Step 6: Commit** — `git add apps/api/database/migrations apps/api/app/Models apps/api/tests/Feature/CartTest.php && git commit -m "feat(api): cart schema and models"

## Task 6: Order items + totals trigger + cart FK + checkout + cancel/refund lifecycle

**Files:**
- Create: `apps/api/database/migrations/*_create_order_items_table.php`, `*_create_order_totals_trigger.php`, `*_add_cart_fk_to_orders_table.php`
- Create: `apps/api/app/Services/CheckoutService.php`, `apps/api/app/Services/OrderLifecycleService.php`
- Create: `apps/api/tests/Feature/OrderStateMachineTest.php`, `apps/api/tests/Feature/OrderCancellationRefundTest.php`

**Rationale (F2.1):** the `orders` table was created in Task 3. This task adds `order_items`, the totals trigger, and the `cart_id` FK (via ALTER, now that `carts` exists in Task 5).

**Acceptance Criteria:**

Feature: Order state machine and totals
  Scenario: Invalid status value is rejected
    Given an orders table with a status CHECK over allowed values
    When a row is inserted with an out-of-set status
    Then the write is rejected

  Scenario: Order follows forward transitions
    Given an order in state RESERVED
    When it advances to PAID, then PACKED, then SHIPPED, then COMPLETED
    Then each transition is accepted and the order ends in COMPLETED

  Scenario: Illegal direct transition is rejected
    Given an order in state CREATED
    When OrderTransitions::advance attempts to set COMPLETED directly
    Then the transition is rejected

  Scenario: Order total equals sum of line totals
    Given an order with two lines (1000 and 2500 cents)
    When the order is reloaded
    Then total_cents = 3500

  Scenario: Order total is zero for a zero-item manual order
    Given a manual order with no items
    When the order is reloaded
    Then total_cents = 0 (not NULL)

  Scenario: Order total stays correct after a line is deleted
    Given an order with two lines (1000 and 2500 cents)
    When one line is deleted
    Then total_cents = 2500

  Scenario: Multiple manual orders with NULL cart_id are allowed
    Given the orders table
    When two manual orders with cart_id = NULL are inserted
    Then both inserts succeed

  Scenario: Cart can back at most one order
    Given a cart that created one order
    When a second order is inserted referencing the same cart
    Then the write is rejected (UNIQUE on cart_id)

  Scenario: Cart is checked out exactly once
    Given an active cart with a product and sufficient stock
    When checkout reserves stock and creates the order
    Then the order is created in state RESERVED
    And the cart becomes checked_out
    And a second checkout attempt on the same cart is rejected with only one order existing

Feature: Cancellation and refund release stock
  Scenario: Reserved order cancellation releases reservations
    Given an order in state RESERVED with active reservations
    When the order is cancelled
    Then the order becomes CANCELLED
    And each active reservation is released (RELEASED) with RELEASE movements
    And reserved stock is decremented and physical is unchanged

  Scenario: Paid order refund restores physical stock
    Given an order in state PAID with consumed reservations
    When the order is refunded
    Then the order becomes REFUNDED
    And physical stock is restored via RETURN movements

  Scenario: Shipped order refund restores physical stock after return
    Given an order in state SHIPPED with consumed reservations
    When the order is refunded
    Then the order becomes REFUNDED
    And physical stock is restored via RETURN movements exactly once

  Scenario: Completed order refund restores physical stock after return
    Given an order in state COMPLETED with consumed reservations
    When the order is refunded
    Then the order becomes REFUNDED
    And physical stock is restored via RETURN movements exactly once

  Scenario: Release runs exactly once
    Given an order being cancelled twice
    When the second cancellation is attempted
    Then no duplicate RELEASE movements occur and reserved is not double-decremented

  Scenario: Refund is idempotent
    Given an order in state REFUNDED
    When a second refund is attempted
    Then no duplicate RETURN movements occur and physical is not restored twice

- [ ] **Step 1: Write failing tests** — `tests/Feature/OrderStateMachineTest.php` (status CHECK, trigger total incl. zero-item + after-delete, cart_id uniqueness, multiple manual NULL-cart orders, forward-transition progression, illegal CREATED→COMPLETED rejected, checkout integration incl. checked_out + second-checkout-rejected) and `tests/Feature/OrderCancellationRefundTest.php` (RESERVED→CANCELLED releasing reservations, PAID/SHIPPED/COMPLETED→REFUNDED restoring physical via RETURN, release-exactly-once, refund idempotency).
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter='OrderStateMachineTest|OrderCancellationRefundTest'`; fails.
- [ ] **Step 3: Create migrations** —
  - `order_items`: order_id FK, product_id FK, `unit_price_cents` BIGINT `CHECK(unit_price_cents >= 0)`, `quantity` INT CHECK(> 0), `line_total_cents` BIGINT `CHECK(line_total_cents >= 0)`, timestamps. No `product_name_snapshot`.
  - `*_create_order_totals_trigger.php`: trigger on `order_items` **AFTER INSERT OR UPDATE OR DELETE** recomputing `orders.total_cents = COALESCE(SUM(line_total_cents), 0)` for the parent order (fires on DELETE so totals never go stale).
  - `*_add_cart_fk_to_orders_table.php`: add `cart_id` FK to `orders` **UNIQUE (plain, NULLS DISTINCT)** nullable, referencing `carts` (created in Task 5).
- [ ] **Step 4: Create CheckoutService (orchestrator)** — in one transaction: lock the cart row (`lockForUpdate`), reject empty/non-active carts, lock each inventory row ascending `inventory_id`, **delegate availability + reservation to `StockReservationService::reserve()`** (the sole mutator/availability gate), create the order (RESERVED) + items, set cart checked_out, commit. CheckoutService must NOT re-implement locking/availability decisions beyond acquiring inventory locks in global ascending order.
- [ ] **Step 5: Create OrderLifecycleService (cancel/refund)** — `cancel(Order)` and `refund(Order)` as order-scoped transactions: lock inventory rows ascending, guarded state updates via `OrderTransitions::advance`, delegate reservation release/consumption to `StockReservationService::release()`/`consume()`, write RELEASE/RETURN movements via `MovementLedger`, exactly-once order transition, idempotent on repeated calls (REFUNDED→refund is a no-op).
- [ ] **Step 6: Run-to-pass** — `php artisan test --filter='OrderStateMachineTest|OrderCancellationRefundTest'`; all green.
- [ ] **Step 7: Commit** — `git add apps/api/database/migrations apps/api/app/Services apps/api/tests/Feature/OrderStateMachineTest.php apps/api/tests/Feature/OrderCancellationRefundTest.php && git commit -m "feat(api): order items, totals trigger, checkout, cancellation and refund lifecycle"`

## Task 7: Payment schema + models + idempotent webhook

**Files:**
- Create: `apps/api/app/Enums/PaymentStatus.php`, `apps/api/app/Enums/PaymentTransactionStatus.php`
- Create: `apps/api/database/migrations/*_create_payments_table.php`, `*_create_payment_transactions_table.php`
- Create: `apps/api/app/Models/Payment.php`, `apps/api/app/Models/PaymentTransaction.php`
- Create: `apps/api/app/Services/PaymentWebhookService.php`
- Create: `apps/api/tests/Feature/PaymentIdempotencyTest.php`

**Acceptance Criteria:**

Feature: Payment webhook idempotency
  Scenario: Duplicate payment event is processed once
    Given a payment with idempotency_key K
    When a webhook with K is delivered twice
    Then the payment is processed exactly once
    And the second delivery is detected as already processed

  Scenario: Wrong payment amount is rejected
    Given an order with total_cents = 10000
    When a webhook SUCCEEDED arrives with amount_cents = 1000
    Then the payment is rejected and no state changes

  Scenario: Rejected webhook is recorded for traceability
    Given an order whose reservation expired
    When a webhook is delivered and rejected
    Then a payment_transactions row with status REJECTED is committed
    And redelivery of the same key is skipped

  Scenario: Already-paid order rejects a second successful webhook
    Given an order already in state PAID
    When a webhook SUCCEEDED arrives with a different idempotency_key
    Then the delivery is treated as already handled (no double-applied state)

- [ ] **Step 1: Write failing tests** — `tests/Feature/PaymentIdempotencyTest.php`: duplicate-key once-only, amount-mismatch rejection, rejected-webhook recording + dedup, and already-PAID-order guard with a different key.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=PaymentIdempotencyTest`; fails.
- [ ] **Step 3: Create enums** — `PaymentStatus` (PENDING/PAID/FAILED/EXPIRED/REFUNDED) with explicit transition set; `PaymentTransactionStatus` (PENDING/SUCCEEDED/FAILED/REJECTED).
- [ ] **Step 4: Create migrations** —
  - `payments`: order_id FK **UNIQUE** (one payment per order), `amount_cents` BIGINT `CHECK(amount_cents >= 0)`, status enum default PENDING + `CHECK (status IN (...))`, timestamps. (Cross-table `CHECK(amount = orders.total)` is impossible in PostgreSQL; amount equality is enforced in the app-layer consumption transaction.)
  - `payment_transactions`: payment_id FK, `idempotency_key` TEXT NOT NULL UNIQUE, `provider_reference` nullable, status enum default PENDING, `amount_cents` BIGINT, `raw_payload` JSONB nullable, timestamps.
- [ ] **Step 5: Create models** — `Payment` (belongsTo Order; status cast), `PaymentTransaction` (belongsTo Payment; idempotency_key unique; status cast).
- [ ] **Step 6: Create PaymentWebhookService** — single-transaction handler per spec §6.5. **The `payments` row is created (status PENDING) at checkout** (in CheckoutService, Task 6) so every `payment_transactions.payment_id` FK has a target even for rejected webhooks. Handler flow: insert payment_transactions with the key (unique-violation → already handled, skip); **if the order is already PAID → treat as already-handled regardless of key**; **if any of the order's reservations is non-ACTIVE (EXPIRED or RELEASED — including CANCELLED orders) → record REJECTED** and commit atomically (no stock effect); verify `amount_cents == order.total_cents` else record REJECTED; else **delegate reservation consumption to `StockReservationService::consume()`** (order-scoped; `consume()` surfaces a 0-ACTIVE-rows abort that is mapped to REJECTED, never success), transition order RESERVED→PAID via `OrderTransitions::advance`, set payment PAID, commit transaction row as SUCCEEDED atomically. **Lock order**: inventory rows are locked ascending `inventory_id` before any other row (same global order as checkout/expiry/cancel/refund) to avoid deadlock.
- [ ] **Step 7: Run-to-pass** — `php artisan test --filter=PaymentIdempotencyTest`; all green.
- [ ] **Step 8: Commit** — `git add apps/api/app/Enums apps/api/database/migrations apps/api/app/Models apps/api/app/Services apps/api/tests/Feature/PaymentIdempotencyTest.php && git commit -m "feat(api): payment schema, idempotent webhook, amount verification"

## Task 8: Shipment schema + models

**Files:**
- Create: `apps/api/app/Enums/ShipmentStatus.php`
- Create: `apps/api/database/migrations/*_create_shipments_table.php`
- Create: `apps/api/app/Models/Shipment.php`
- Create: `apps/api/tests/Feature/ShipmentStateMachineTest.php`

**Acceptance Criteria:**

Feature: Shipment state machine
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

- [ ] **Step 1: Write failing test** — `tests/Feature/ShipmentStateMachineTest.php` asserting the CREATED→PACKED→SHIPPED→DELIVERED progression via `canTransitionTo()`.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=ShipmentStateMachineTest`; fails.
- [ ] **Step 3: Create enum** — `ShipmentStatus` (CREATED/PACKED/SHIPPED/DELIVERED) with `canTransitionTo()`.
- [ ] **Step 4: Create migration** — `shipments`: order_id FK, status enum default CREATED + CHECK over values, timestamps. **No `tracking_number`/`shipped_at`/`delivered_at`/`carrier`** (no consumer in this plan; the state machine already encodes the milestones).
- [ ] **Step 5: Create model** — `Shipment` (belongsTo Order; status cast).
- [ ] **Step 6: Run-to-pass** — `php artisan test --filter=ShipmentStateMachineTest`; all green.
- [ ] **Step 7: Commit** — `git add apps/api/app/Enums apps/api/database/migrations apps/api/app/Models apps/api/tests/Feature/ShipmentStateMachineTest.php && git commit -m "feat(api): shipment schema and state machine"

## Task 9: Menu schema + models

**Files:**
- Create: `apps/api/database/migrations/*_create_menus_table.php`, `*_create_menu_permissions_table.php`
- Create: `apps/api/app/Models/Menu.php`
- Create: `apps/api/tests/Feature/MenuPermissionTest.php`

**Acceptance Criteria:**

Feature: Hierarchical dynamic menu filtered by permission
  Scenario: Menu tree supports parent/child hierarchy
    Given menus "Inventory" (parent) and "Stock" (child of Inventory)
    When the menu tree is loaded
    Then Stock is nested under Inventory

  Scenario: Menu item requires a permission
    Given a menu item linked to permission "products.view"
    When a user lacking that permission queries menus
    Then the menu item is not in the returned tree

- [ ] **Step 1: Write failing test** — `tests/Feature/MenuPermissionTest.php` covering hierarchy and permission filtering.
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=MenuPermissionTest`; fails.
- [ ] **Step 3: Create migrations** — `menus` (parent_id FK self nullable, name, route nullable, sort INT default 0, timestamps), `menu_permissions` (menu_id FK, permission_id FK, PK(menu_id, permission_id)). No `icon` column.
- [ ] **Step 4: Create model** — `Menu` (parent/children self-relations, belongsToMany Permission; `visibleTo(User)` returning whether the user holds any required permission).
- [ ] **Step 5: Run-to-pass** — `php artisan test --filter=MenuPermissionTest`; all green.
- [ ] **Step 6: Commit** — `git add apps/api/database/migrations apps/api/app/Models apps/api/tests/Feature/MenuPermissionTest.php && git commit -m "feat(api): hierarchical menu schema with permission filtering"

## Task 10: Inventory adjustment + audit schema + models

**Files:**
- Create: `apps/api/app/Enums/InventoryAdjustmentType.php`
- Create: `apps/api/app/Services/InventoryAdjustmentService.php`
- Create: `apps/api/database/migrations/*_create_audit_logs_table.php`
- Create: `apps/api/app/Models/AuditLog.php`
- Create: `apps/api/tests/Feature/InventoryAdjustmentTest.php`, `apps/api/tests/Feature/AuditLogTest.php`

**Acceptance Criteria:**

Feature: Downward adjustment preserves the reserved<=physical invariant
  Scenario: Downward adjustment releases reservations it renders unsupported
    Given an inventory row with physical = 5 and reserved = 5
    When a downward adjustment to physical = 3 is performed
    Then the reservations making reserved > 3 are released first (RELEASE movements)
    And the ADJUSTMENT movement is applied
    And reserved <= physical holds afterwards

  Scenario: Overshooting downward adjustment is rejected
    Given an inventory row with physical = 2 and reserved = 0
    When a downward adjustment of -10 is performed
    Then the adjustment is rejected with a domain error
    And physical stock is unchanged

Feature: Audit of critical actions
  Scenario: Stock adjustment is audited
    Given an inventory adjustment is performed
    When the adjustment occurs
    Then an audit_logs row is created with before/after values
    And the acting user and timestamp are recorded

- [ ] **Step 1: Write failing tests** — `tests/Feature/InventoryAdjustmentTest.php` (downward adjustment releases unsupported reservations then applies ADJUSTMENT in one transaction; overshooting delta rejected with a domain error, no state change) and `tests/Feature/AuditLogTest.php` (writes an `AuditLog::record(...)` and asserts before/after JSON, actor, timestamp).
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter='InventoryAdjustmentTest|AuditLogTest'`; fails.
- [ ] **Step 3: Create enum** — `InventoryAdjustmentType` (add/reduce).
- [ ] **Step 4: Create InventoryAdjustmentService** — `adjust(Inventory, int $delta, ?int $userId)` in one transaction (inventory row locked): **reject any delta where `physical_stock + delta < 0` with a domain exception** (mapped to 409/422) before mutating; if the target physical would make `reserved > physical`, **release reservations deterministically — ascending `reserved_until` — via `StockReservationService::releaseReservation(StockReservation)`** (single-reservation RELEASE, reserved decrement) until `reserved <= physical`; then apply the ADJUSTMENT movement via `MovementLedger`; write an `AuditLog::record` (before/after) in the same transaction.
- [ ] **Step 5: Create migration** — `audit_logs`: user_id FK nullable, action, entity_type, entity_id, `before`/`after` JSONB nullable, timestamps. No `ip`/`user_agent` columns.
- [ ] **Step 6: Create model** — `AuditLog` with `record(string $action, string $entityType, $entityId, ?array $before, ?array $after, ?int $userId)`.
- [ ] **Step 7: Run-to-pass** — `php artisan test --filter='InventoryAdjustmentTest|AuditLogTest'`; all green.
- [ ] **Step 8: Commit** — `git add apps/api/app/Enums apps/api/app/Services apps/api/database/migrations apps/api/app/Models apps/api/tests/Feature/InventoryAdjustmentTest.php apps/api/tests/Feature/AuditLogTest.php && git commit -m "feat(api): inventory adjustment service and audit schema"

## Task 11: Reservation expiry job

**Files:**
- Create: `apps/api/app/Services/ReservationExpiryService.php`
- Create: `apps/api/app/Console/Commands/ExpireReservations.php`
- Create: `apps/api/tests/Feature/ReservationExpiryTest.php`

**Acceptance Criteria:**

Feature: Reservation TTL expiry releases stock
  Scenario: Expired reservation releases reserved stock and transitions order
    Given an order with reserved = 7 and reserved_until in the past
    When the expiry job runs
    Then the reservation is marked EXPIRED
    And the order is transitioned to EXPIRED
    And reserved decreases by 7
    And available increases by 7
    And physical stock is unchanged

  Scenario: Multi-line order expires atomically
    Given an order with two ACTIVE reservations past reserved_until
    When the expiry job runs
    Then all reservations become EXPIRED in one transaction
    And the order is transitioned exactly once
    And reserved is decremented once per reservation

  Scenario: Expiry is idempotent across double runs
    Given an order already expired
    When the expiry job runs again
    Then no duplicate RELEASE movements occur
    And reserved is not decremented again

- [ ] **Step 1: Write failing tests** — `tests/Feature/ReservationExpiryTest.php` covering SC 5.3: expired reservation releases stock + order→EXPIRED; multi-line atomic expiry (all reservations, exactly-once transition); double-run idempotency; **one-order-failure isolation** (a raced order that is already PAID/CANCELLED does not abort the rest of the batch).
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=ReservationExpiryTest`; fails.
- [ ] **Step 3: Create ReservationExpiryService** — `expireAll()`: find orders with ACTIVE reservations where `reserved_until < now()`; for each order, **wrap in try/catch (log the failure and continue)** so one raced/failed order cannot abort the batch; in each order's one transaction (inventory rows locked ascending `inventory_id`): transition the order RESERVED→EXPIRED exactly once via `OrderTransitions::advance` (**skip when the order is already EXPIRED/PAID/CANCELLED** while still expiring any remaining ACTIVE reservations), delegate `StockReservationService::expire()` for **all** ACTIVE reservations, decrement reserved once per reservation, write one RELEASE movement per reservation, commit.
- [ ] **Step 4: Create command** — `app/Console/Commands/ExpireReservations.php` invoking `expireAll()` (schedulable via Laravel scheduler / cron).
- [ ] **Step 5: Run-to-pass** — `php artisan test --filter=ReservationExpiryTest`; all green.
- [ ] **Step 6: Commit** — `git add apps/api/app/Services apps/api/app/Console apps/api/tests/Feature/ReservationExpiryTest.php && git commit -m "feat(api): reservation ttl expiry job"

## Task 12: Seeders

**Files:**
- Create: `apps/api/database/seeders/RolePermissionSeeder.php`, `MenuSeeder.php`, `DatabaseSeeder.php`
- Create: `apps/api/tests/Feature/SeederTest.php`

**Acceptance Criteria:**

Feature: Seed data for roles, permissions, menus, demo catalog
  Scenario: Seeding produces admin/warehouse/customer roles with permissions
    Given the database is fresh
    When `php artisan db:seed` runs
    Then roles admin, warehouse, customer exist
    And admin has products.*, inventory.*, orders.*, users.manage permissions
    And the hierarchical menu tree (Dashboard, Products, Inventory>Stock/Reservations/Adjustments/Movements, Orders, Payments, Users, Roles, Permissions, Reports) exists

  Scenario: Seeding is idempotent
    Given the database has been seeded once
    When `php artisan db:seed` runs again
    Then no duplicate roles/permissions/menus are created

- [ ] **Step 1: Write failing test** — `tests/Feature/SeederTest.php` asserting seeded roles/permissions/menus, and idempotency on re-run. (No demo-catalog assertions — demo product data is out of scope per review F2.21; the concurrency harness seeds its own stock directly.)
- [ ] **Step 2: Run-to-fail** — `php artisan test --filter=SeederTest`; fails (seeders absent).
- [ ] **Step 3: Create seeders (idempotent via `updateOrCreate`/`upsert`)** — `RolePermissionSeeder` (roles admin/warehouse/customer + permission strings `products.view/create/update/delete`, `inventory.view/adjust/reserve`, `orders.view/create/cancel/fulfill`, `users.manage`, `roles.manage`, `permissions.manage`), `MenuSeeder` (hierarchical tree per spec), composed in `DatabaseSeeder`. (No `DemoCatalogSeeder` — removed per review F2.21.)
- [ ] **Step 4: Run-to-pass** — `php artisan test --filter=SeederTest`; all green.
- [ ] **Step 5: Commit** — `git add apps/api/database/seeders apps/api/tests/Feature/SeederTest.php && git commit -m "feat(api): seed roles, permissions, menus"`

---

## Final Verification

Run from `apps/api/`:
1. `php artisan migrate:fresh --seed` — must succeed (all CHECKs, indexes, trigger).
2. `php artisan test` — full suite green (RBAC, inventory+ledger, concurrency harness, cart, order state machine, cancel/refund, payment idempotency, shipment, menu, adjustment, audit, expiry, seeders).
3. `psql -d commerceflow_test -c "SELECT count(*) FROM stock_reservations WHERE state='ACTIVE';"` after the concurrency test — reserved never exceeds physical.