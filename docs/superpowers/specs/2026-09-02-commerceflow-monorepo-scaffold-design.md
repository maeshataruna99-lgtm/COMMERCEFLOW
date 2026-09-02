# CommerceFlow — Monorepo Scaffold Design Spec

**Date:** 2026-09-02
**Status:** Draft (rev 2 — post Round 1 review)
**Scope:** Monorepo root workspace + Docker Compose full-stack dev environment + minimal app skeletons (web, realtime-gateway) + shared packages (contracts, realtime-events) + nginx reverse proxy + Makefile.
**Related decision log:** `docs/superpowers/brainstorms/2026-09-02-commerceflow-monorepo-scaffold-brainstorm.md`
**Based On:** `docs/superpowers/brainstorms/2026-09-01-commerceflow-ecommerce-platform-brainstorm.md` (Done)

## 1. Problem

CommerceFlow is a monorepo with a Laravel API (already built and runnable at `apps/api/`), plus planned Vue web app, Node realtime gateway, and shared TS packages. Today there is **no monorepo structure**: no `pnpm-workspace.yaml`, no root `package.json`, no `docker-compose.yml`, no nginx, no Makefile, no `apps/web`, no `apps/realtime-gateway`, no `packages/`. The API can only run locally via `php artisan serve`.

The project goal is that `docker compose up` runs the **entire stack** (nginx + api + web + realtime-gateway + postgres + redis) as a production-like development environment. Without the monorepo scaffold and containerization, the team cannot run the platform as a cohesive unit, and the remaining apps have no home.

## 2. Goals

- Establish the **pnpm workspace monorepo structure** matching the approved CommerceFlow architecture (apps/api, apps/web, apps/realtime-gateway, packages/contracts, packages/realtime-events, infrastructure/, scripts/).
- Provide a **full-stack `docker compose up`** dev environment: nginx (reverse proxy on :8080), api (Laravel 12, artisan-served), web (Vue 3 + Vite), gateway (Socket.IO), postgres, redis — all 6 services up with one command.
- **Minimal runnable skeletons** for web and realtime-gateway (placeholders that boot and can be developed further in separate plans).
- **Minimal shared packages** `contracts` and `realtime-events` (TS types/event contracts placeholders) consumed by the JS/TS apps.
- **nginx path-based routing**: `/` → web, `/api/` → api, `/socket.io` → gateway (single entrypoint on :8080).
- **Makefile** with common commands (up, down, logs, test, seed, etc.).
- `.env`-driven configuration with dev defaults matching existing apps/api credentials (`commerceflow`/`commerceflow`); named volumes for postgres and redis persistence.

## 3. Non-Goals

- No actual web app features (the Vue storefront/backoffice UI) — skeleton only.
- No realtime business logic (room management, event broadcasting wiring) — skeleton only, connects via Socket.IO + Redis adapter placeholder.
- No API routes/controllers/auth — the Laravel API domain layer already exists; REST API is a separate plan.
- No production hardening (TLS, multi-node, secrets management) — dev-focused compose.
- No CI/CD — separate plan.
- No building the `packages` contents beyond minimal contracts/events placeholders.

## 4. Design Principles

1. **One command, full stack.** `docker compose up` boots every service; nothing requires a manual local install (beyond Docker itself).
2. **pnpm workspace is the JS/TS home.** `pnpm-workspace.yaml` at root covers apps/web, apps/realtime-gateway, packages/*. Laravel stays Composer-only (never forced into pnpm).
3. **nginx is the single entrypoint.** Services are not individually exposed; all traffic flows through nginx path routing on :8080.
4. **Service discovery by name.** Containers reach each other via compose service names (`postgres`, `redis`, `api`, `web`, `gateway`); the api container uses `postgres` host (not localhost). Canonical names: the realtime service is **`gateway`** (in compose, nginx upstream, and docs) to avoid a three-way name mismatch. `DB_HOST=postgres` is scoped strictly to the compose environment block — it must never be written into `apps/api/.env` or `.env.example`, so the local `php artisan serve` workflow keeps `DB_HOST=127.0.0.1`.
5. **Config via .env, safe defaults.** Credentials/ports read from a root `.env` (gitignored) with `.env.example` committed; dev defaults match the existing apps/api setup.
6. **Named volumes for state.** postgres and redis data persist across `down`/`up`.
7. **Minimal skeletons, real boot.** web/gateway skeletons compile and start; they are the seam where future plans add real features.

## 5. Acceptance Scenarios

> Gherkin — acceptance criteria the monorepo scaffold must satisfy.

### Scenario 5.1: Full stack boots with one command
```
Feature: docker compose up runs the entire stack
  Scenario: All six services start and become healthy
    Given Docker is available on the host
    When `docker compose up -d --build` is run at the repo root
    Then containers nginx, api, web, gateway, postgres, redis all start
    And each reaches a healthy/running state
    And `docker compose ps` shows all 6 services up
```

### Scenario 5.2: nginx routes by path
```
Feature: nginx path-based reverse proxy on :8080
  Scenario: / serves the web app
    Given the stack is running (WEB_PORT defaults to 8080)
    When an HTTP GET is issued to http://localhost:${WEB_PORT:-8080}/
    Then an HTTP 200 response is returned from the web service
  Scenario: /api/ proxies to the Laravel API
    Given the stack is running
    When an HTTP GET is issued to http://localhost:${WEB_PORT:-8080}/api/v1/health
    Then the request is proxied to the api service and returns its JSON response
  Scenario: /socket.io proxies to the realtime gateway
    Given the stack is running
    When a Socket.IO handshake is issued to http://localhost:${WEB_PORT:-8080}/socket.io/
    Then the connection reaches the gateway service
```

### Scenario 5.3: Workspace structure
```
Feature: pnpm monorepo structure
  Scenario: Workspace globs cover apps and packages
    Given the repo root
    When pnpm-workspace.yaml is read
    Then it includes apps/* and packages/*
  Scenario: Target layout is present
    Given the repo root
    Then it contains apps/, packages/, infrastructure/, and scripts/ directories
  Scenario: Shared packages are resolvable
    Given the workspace is installed (pnpm install)
    When apps/web and apps/realtime-gateway import from @commerceflow/contracts and @commerceflow/realtime-events
    Then the imports resolve to the workspace packages
```

### Scenario 5.4: Skeleton apps boot
```
Feature: web and realtime-gateway skeletons run
  Scenario: Web app serves a placeholder over HTTP
    Given the stack is running
    When an HTTP GET is issued to http://localhost:${WEB_PORT:-8080}/
    Then a 200 response containing the placeholder page is returned
  Scenario: Realtime gateway starts and accepts a connection
    Given the workspace is installed and redis is running
    When the gateway is started
    Then it listens and a Socket.IO client can connect
```

### Scenario 5.5: API connects to the compose postgres
```
Feature: API container uses compose postgres
  Scenario: Laravel migrates against the container database
    Given the stack is running
    When `docker compose exec api php artisan migrate:status` is run
    Then it connects to the postgres service and reports migration status
```

### Scenario 5.6: Makefile provides common commands
```
Feature: Makefile convenience commands
  Scenario: Standard targets exist
    Given the repo root Makefile
    When the targets are listed
    Then up, down, logs, ps, test, seed targets exist
```

## 6. Design

### 6.1 Repository layout (target)

```
commerceflow/
├── apps/
│   ├── api/                  (exists — Laravel 12)
│   ├── web/                  (NEW skeleton — Vue 3 + Vite + TS)
│   └── realtime-gateway/     (NEW skeleton — Node + Socket.IO + TS)
├── packages/
│   ├── contracts/            (NEW — shared API/type contracts, TS)
│   └── realtime-events/      (NEW — realtime event contracts, TS)
├── infrastructure/
│   ├── docker/               (Dockerfiles)
│   │   ├── api.Dockerfile
│   │   ├── web.Dockerfile
│   │   └── gateway.Dockerfile
│   └── nginx/
│       ├── nginx.conf
│       └── conf.d/default.conf
├── scripts/                  (helper scripts)
├── docs/                     (exists)
├── .github/workflows/        (future)
├── docker-compose.yml
├── pnpm-workspace.yaml
├── package.json
├── pnpm-lock.yaml            (generated)
├── Makefile
├── .env                      (gitignored) + .env.example
├── README.md
└── LICENSE
```

### 6.2 pnpm workspace

- `pnpm-workspace.yaml`:
  ```yaml
  packages:
    - 'apps/web'
    - 'apps/realtime-gateway'
    - 'packages/*'
  ```
- Root `package.json`: `"private": true`, workspaces via pnpm, scripts for `build`, `dev`, `typecheck`, `test` delegating to workspace apps.
- Shared packages named `@commerceflow/contracts` and `@commerceflow/realtime-events` (TypeScript, compiled to dist with tsup or tsc; consumed via workspace `*` dependency).
- Node 22 LTS + pnpm 9; consistent `engines` in root package.json.

### 6.3 apps/api container

- `infrastructure/docker/api.Dockerfile`: **base `php:8.3-cli`** (NOT `php:8.3-fpm` — the fpm SAPI cannot run `php -S`, which `artisan serve` spawns). Install pdo_pgsql/pgsql via `docker-php-ext-install`, Composer; copy `apps/api/`; run `composer install`; working dir `/var/www/html`.
- **Serve strategy (committed):** CMD runs `php artisan serve --host=0.0.0.0 --port=80`. nginx proxies to `api:80`. Accepted dev-only skeleton runtime (production php-fpm/fastcgi is the API plan's concern). `PHP_CLI_SERVER_WORKERS` may be set, but its effect on `artisan serve`'s spawned `php -S` is unverified — accept single-threaded as dev-only unless P9 confirms otherwise.
- **Migrate-on-boot:** entrypoint wrapper runs `php artisan migrate --force` with a **bounded retry loop (~30 tries × 2s backoff)** while postgres warms; on exhaustion, **exit with a distinct error message** (e.g. "postgres unreachable after N attempts — run `make clean`") so restart logs are actionable. The entrypoint must **`exec` the final `php artisan serve`** (PID1 = the server, so Docker signals work) and the api service sets **`init: true`** for child reaping.
- **APP_KEY / Laravel env provenance:** apps/api/.env is gitignored and never relied on inside the container. Compose `environment:` supplies `APP_ENV`, `APP_DEBUG`, `APP_KEY` (from root `.env` with `${APP_KEY:-}` default); the entrypoint runs `php artisan key:generate --force` only when `APP_KEY` is unset. A root **`.dockerignore` excludes `**/.env*`** from all build contexts so local secrets never bake into image layers.
- DB host inside compose: `postgres`. Credentials from root `.env` via compose `environment:` — never mutate `apps/api/.env`.
- Healthcheck: `curl`-based TCP check against the api port.
- `restart: unless-stopped` (self-healing; YAGNI note F2.20 flagged this as optional for a dev scaffold — retained for resilience, removable later).
- The api service is **not exposed** on the host — only nginx is.

### 6.4 apps/web skeleton

- Vue 3 + Vite + TypeScript minimal app (placeholder page).
- **Single dev loop (committed):** the default compose topology serves the **built SPA** via the web container's internal nginx on :80; nginx `/` proxies to `web:80`. A `docker-compose.dev.yml` override runs the **Vite dev server**:
  - dev.yml **overrides the web service `command`** to `pnpm dev --host 0.0.0.0` (Vite listens on :5173 inside the container; no second Dockerfile needed — the same node build stage image can run `pnpm dev`).
  - dev.yml **bind-mounts an alternate nginx conf** (`infrastructure/nginx/conf.d/dev.conf`) that proxies `/` → `web:5173`, `/@vite` → `web:5173`, and the HMR websocket (`location /` with `proxy_http_version 1.1` + `Upgrade`/`Connection` headers), plus the same `/api/` and `/socket.io/` locations as the default conf.
  - The default and dev topologies are mutually exclusive (run `docker compose -f docker-compose.yml -f docker-compose.dev.yml up` for dev; plain `docker compose up` for built-SPA).
- Web `package.json` imports `@commerceflow/contracts`.
- **Docker build contract (F2.9):** each JS/TS Dockerfile copies the **full workspace** (root manifests, `pnpm-workspace.yaml`, `apps/web`, `packages/*`) into the build stage and runs **`pnpm install --frozen-lockfile`** (the root `pnpm-lock.yaml` covers all importers). Workspace deps are built via root **`pnpm -r build`** (topological order — no manual package enumeration). **Before any `pnpm` invocation, add `RUN corepack enable && corepack prepare pnpm@9 --activate`** (node:22 ships no pnpm on PATH).
- `infrastructure/docker/web.Dockerfile`: multi-stage — `node:22` build stage (corepack, `pnpm install --frozen-lockfile`, `pnpm -r build`), `nginx:alpine` serve stage serving `apps/web/dist/` with **SPA fallback** (`try_files $uri $uri/ /index.html;`).
- Web does **not** `depends_on api` (static SPA never calls the api at runtime).

### 6.5 apps/realtime-gateway skeleton

- Node + TypeScript + Socket.IO minimal server.
- `realtime-events` package placeholder imported (event names/types).
- `infrastructure/docker/gateway.Dockerfile`: `node:22` based, **`RUN corepack enable && corepack prepare pnpm@9 --activate`**, copy full workspace, **`pnpm install --frozen-lockfile`**, **`pnpm -r build`** (builds packages then the gateway in topological order), `node dist/index.js`.
- Depends on `redis` (healthy). **Redis behavior (committed):** bounded retry (~10 tries, exponential to 30s) connecting the adapter; on exhaustion **keep the Socket.IO process alive without the adapter and log once** (does not crash the container).

### 6.6 packages

- `packages/contracts`: exports placeholder shared type contracts (e.g. `ApiResponse<T>`, `HealthResponse`), tsconfig, package.json (`@commerceflow/contracts`).
- `packages/realtime-events`: exports placeholder realtime event constants/types (e.g. `StockUpdatedEvent`), package.json (`@commerceflow/realtime-events`).
- Both are built with **tsc only** (no bundler — type-only packages).
- **Module strategy (committed, F2.6):** both packages AND the gateway use **`"type": "module"`** with **`module: "nodenext"`** in tsconfig, so `exports` map `"."` → `./dist/index.js` is parsed as ESM consistently. Each exports map includes a **`types` condition** and a **`"./package.json"`** entry. Consumed via `"@commerceflow/*": "workspace:*"`.

### 6.7 nginx

- `infrastructure/nginx/conf.d/default.conf`: server on :8080.
  - `location /api/` → **proxy_pass `http://api:80`; preserve URI** (no strip/rewrite). The health route is registered at `/api/v1/health` in apps/api so the full URI matches — this is the committed contract.
  - `location = /api` → `return 308 /api/;` (bare path does not fall through to the SPA).
  - `location /socket.io/` → `proxy_pass http://gateway:3000` with WebSocket upgrade: `proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; proxy_read_timeout 1d; proxy_send_timeout 1d;`. The trailing-slash form (`/socket.io/...`) is canonical (engine.io rejects the bare path); optionally `location = /socket.io { return 308 /socket.io/; }`.
  - `location /` → `proxy_pass http://web:80;` (web's internal nginx serves the built SPA with `try_files ... /index.html` fallback).
- **Ownership boundary (documented):** the outer nginx owns **path routing only**; the web container's internal nginx owns **static serving + SPA fallback**. Future routing additions go in the outer conf; SPA/asset rules go in the web container.
- `infrastructure/nginx/nginx.conf`: standard, includes `conf.d/*.conf`.

### 6.8 docker-compose.yml (dev)

Services:
- `postgres`: **pin `postgres:18-alpine`**, env from root `.env` (`POSTGRES_USER`/`POSTGRES_PASSWORD`/`POSTGRES_DB`, with `${VAR:-default}` interpolation), volume `postgres_data`, healthcheck `pg_isready`. **Single credential source:** the api service's `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` are wired directly from the same root vars (`DB_PASSWORD: ${POSTGRES_PASSWORD:-commerceflow}`, etc.) so postgres and api can never drift. **Changing credentials after first boot requires `make clean` (`down -v`)** to re-init the volume (documented in `.env.example`).
- `redis`: `redis:7-alpine`, volume `redis_data`, healthcheck `redis-cli ping`.
- `api`: build `infrastructure/docker/api.Dockerfile` (context: repo root), `restart: unless-stopped`, `init: true`, depends_on postgres (condition: service_healthy), env `DB_HOST=postgres` + `APP_ENV`/`APP_DEBUG`/`APP_KEY` + DB creds from root `.env` (compose `environment:` only), migrate-on-boot entrypoint (`exec` + bounded retry), ports: none exposed (nginx only).
- `web`: build `infrastructure/docker/web.Dockerfile`, static SPA (no depends_on api). **Healthcheck:** `wget -q -O- http://127.0.0.1:80/` (or `curl`) so nginx can `depends_on service_healthy`.
- `gateway`: build `infrastructure/docker/gateway.Dockerfile`, depends_on redis (healthy). **Healthcheck:** TCP probe against :3000 (e.g. `node -e` connect check) so nginx can `depends_on service_healthy`.
- `nginx`: build `infrastructure/docker/nginx.Dockerfile`, ports `${WEB_PORT:-8080}:80`, **depends_on api/web/gateway with `condition: service_healthy`** (closes the first-boot 502 window). **Healthcheck:** `wget -q -O- http://127.0.0.1:80/`.
- Named volumes: `postgres_data`, `redis_data`.
- Networking: default bridge; service-name DNS (`postgres`, `redis`, `api`, `web`, `gateway`).

### 6.9 Makefile

Targets: `preflight` (checks `docker info`/`docker compose version`; actionable guidance if Docker/WSL2 backend is absent), `setup` (copies `.env.example` → `.env` if missing), `up` (setup + `docker compose up -d --build`), `down`, `build`, `logs`, `ps`, `test` (compose exec api `php artisan test` + pnpm `test` in workspace), `seed` (compose exec api `php artisan migrate:fresh --seed`), `install` (pnpm install + composer install), `clean` (down -v).

### 6.10 scripts

- `scripts/` placeholder: e.g. `wait-for-it.sh` (or rely on depends_on conditions), maybe `scripts/dev.sh` convenience.

## 7. Implementation Phases

- P1: Root workspace files — `pnpm-workspace.yaml`, root `package.json`, `.gitignore` additions, `.env.example`, README stub, LICENSE.
- P2: Packages — `packages/contracts`, `packages/realtime-events` (package.json, tsconfig, src, exports; build with **tsc only** — no bundler for type-only packages).
- P3: Web skeleton — `apps/web` (Vue3+Vite+TS placeholder, imports contracts).
- P4: Realtime gateway skeleton — `apps/realtime-gateway` (Node+TS+Socket.IO placeholder, imports realtime-events).
- P5: Dockerfiles — api.Dockerfile, web.Dockerfile, gateway.Dockerfile, nginx.Dockerfile.
- P6: nginx config — nginx.conf + conf.d/default.conf (path routing + WS upgrade + `/api` exact redirect).
- P7: docker-compose.yml + root .env wiring + **`GET /api/v1/health` route added to apps/api** (explicit dependency of this phase so AC 5.2 is guaranteed).
- P8: Makefile (incl. `preflight`, `setup`/`.env` copy) + scripts.
- P9: Integration verification — `docker compose up`, healthchecks, migrate-on-boot (empty volume boot), migration status, Socket.IO connect, nginx routes (/, /api/v1/health, /socket.io).

## 8. Testing Strategy

**Verification commands (concrete; `WEB_PORT` defaults to 8080 and must match the port used for `up`):**
- `make preflight` → exits 0 and prints "Docker backend OK" when Docker present; exits non-zero with actionable guidance when absent.
- `make up` → copies `.env` from example if missing, then `docker compose up -d --build`; `docker compose ps` shows all 6 services healthy/running.
- `curl -s -o /dev/null -w "%{http_code}" http://localhost:${WEB_PORT:-8080}/` → `200` (web placeholder).
- `curl http://localhost:${WEB_PORT:-8080}/api/v1/health` → api health JSON (route added in P7).
- `curl -s -o /dev/null -w "%{http_code}" http://localhost:${WEB_PORT:-8080}/api` → `308` (redirect to `/api/`).
- Socket.IO client connect to `http://localhost:${WEB_PORT:-8080}/socket.io/` → handshake succeeds (e.g. `node -e` one-liner with the `socket.io-client` package).
- `docker compose exec api php artisan migrate:status` → connects to postgres, reports status with no connection errors (migrate-on-boot already applied schema on first boot).
- `docker compose exec api php artisan test` → existing Laravel suite green in-container (86 tests).
- `pnpm install --frozen-lockfile` at root → workspace resolves; `pnpm -r build` → packages+web+gateway build in topological order.
- Skeleton unit checks: web — `curl -s http://localhost:${WEB_PORT:-8080}/ | grep <placeholder>`; gateway — a one-line Socket.IO client connect script exits 0.
- Makefile targets: `make ps`, `make logs`, `make test`.

**Test categories:**
- Integration: full-stack boot incl. first boot on an empty `postgres_data` volume (migrate-on-boot proves usable); nginx routing (/, /api/v1/health, /socket.io incl. WS upgrade, bare `/api` → 308); cross-service DNS; healthcheck-gated depends_on (no 502 window).
- Unit (skeleton): web placeholder renders (curl+grep); gateway accepts a connection (client script); packages export expected symbols.
- Container: api migrates against compose postgres; existing 86 Laravel tests pass in-container.

## 9. File Inventory

Root (create):
- `pnpm-workspace.yaml`, `package.json`, `pnpm-lock.yaml` (generated), `Makefile`, `.env.example`, `.env` (gitignored, generated from example), `LICENSE`, `README.md` (update).
- `.gitignore` (update: node_modules, dist, .env).

Apps (create skeletons):
- `apps/web/**` (Vue3+Vite+TS placeholder), `apps/realtime-gateway/**` (Node+TS+Socket.IO placeholder).
- `apps/api` (modify): add minimal `GET /api/v1/health` route + controller for the health check (P7 dependency); ensure Dockerfile-friendly env (compose env overrides, local `.env` untouched).

Packages (create):
- `packages/contracts/**`, `packages/realtime-events/**` (tsc-built, type-only).

Infrastructure (create):
- `infrastructure/docker/api.Dockerfile`, `web.Dockerfile`, `gateway.Dockerfile`, `nginx.Dockerfile`
- `infrastructure/nginx/nginx.conf`, `infrastructure/nginx/conf.d/default.conf`

Scripts (create):
- `scripts/` helper(s).

## 10. Out of Scope

- Real web features, realtime business logic, API routes/auth (separate plans).
- Production TLS / secrets / multi-node.
- CI/CD.
- Prisma or any PHP-in-pnpm mixing.
- Building the packages beyond minimal placeholder contracts/events.