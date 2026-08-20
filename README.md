# FuelFlow FSMS

FuelFlow is a fuel station management system based on the supplied Functional
Requirements Document. The sample dashboards inform visual styling only; user
roles and permissions come exclusively from the FRD. All monetary values are
stored and displayed in Ghana cedis (GHS).

## Current implementation

The application now includes a working operational vertical slice:

- Laravel 13 API with session authentication through Sanctum and CSRF
  protection.
- Organization and station isolation using ULID identifiers.
- A separate vendor Super User control plane for company onboarding, tenant
  license tenure and lifecycle control, Head Office/first-station provisioning,
  and initial role account creation.
- The six FRD roles: Administrator, Owner, Station Manager,
  Cashier / Pump Attendant, Accountant, and Auditor.
- Scoped role assignments and a permission catalogue covering the planned
  functional modules.
- User creation, update, activation, deactivation, deletion safeguards, and
  first-login password/PIN setup.
- Immutable audit events with actor, request, station, before/after state, and
  reason fields.
- React 19 + TypeScript application with accessible data-entry forms,
  FRD role-based navigation, GHS formatting, and live operational tables.
- Full Administrator user form for identity, contact details, one FRD role,
  one or more station assignments, temporary password, edit/reset,
  activate/deactivate, and eligible-account deletion.
- Administrator Stations workspace for station creation/editing, station
  numbers, registration/contact details, manager assignment, and a live roster
  synchronized from user-to-station assignments.
- Supplier profiles, protected bank details, performance metrics, product
  pricing endpoints, and deactivation controls.
- Effective-dated fuel product pricing; tank stock, safe levels, manual dips,
  movements, and transfers; pump/nozzle topology and maintenance state.
- Purchase order creation and separation-of-duties approval, dispatch,
  delivery dips/variance, and atomic stock confirmation.
- GHS sales and receipts, effective price snapshots, payment method,
  stock issue/reversal, shifts, meter readings, cash counts, and daily
  reconciliation/sign-off.
- Live role-scoped dashboards, seven operational reports with CSV export,
  recurring email delivery schedules, immutable audit history, and system
  tolerances.
- Queued email notifications for company/user onboarding, the complete PO
  approval/payment lifecycle, password resets, license-expiry thresholds, and
  scheduled CSV reports, with idempotent license/report dispatch tracking.
- PostgreSQL, Redis, API, queue worker, scheduler, and React development
  services in Docker Compose.
- Production PHP-FPM and compiled React/Nginx images, private data networking,
  non-root/read-only containers, secret files, health checks, and security
  headers.
- Laravel and React automated tests plus CI quality gates.

No navigation route is a placeholder. The full hardening sequence for advanced
integrations (ATG hardware, offline sales synchronization, secure document
storage, and queued PDF/XLSX delivery) remains tracked in:

- `docs/FSMS_IMPLEMENTATION_PLAN.md`
- `docs/FSMS_REQUIREMENTS_TRACEABILITY.md`

## Local start

Docker Desktop is the only prerequisite.

```bash
docker compose up --build
```

After the services become healthy:

- Web application: `http://localhost:5173`
- API health: `http://localhost:8000/api/v1/health`

The local-only demo administrator is:

- Email: `admin@fuelflow.local`
- Password: `ChangeMeNow1!`

The local-only vendor Super User is:

- Portal: `http://localhost:5173/vendor/login`
- Email: `vendor@fuelflow.local`
- Password: `ChangeMeNow1!`

Use the vendor portal to onboard additional companies. It creates a Head
Office, first operating station, and selected FRD role accounts in one
transaction. A license tenure in months or years is required; tenant access is
blocked automatically at expiry, and the vendor can renew or deactivate the
license from the company workspace. New tenant accounts must change their
temporary password on first login.

Copy `.env.example` to `.env` before starting if you want to replace the local
database or demo credentials. Demo data is only seeded when the Laravel
environment is `local` and `SEED_DEMO_DATA=true`.

### Local email testing

Local email defaults to `MAIL_MAILER=log`, which records rendered messages in
`apps/api/storage/logs/laravel.log` without delivering them. To test delivery
through Gmail, set the following values in the ignored root `.env` file. Use a
Google App Password rather than the Gmail account password.

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-account@gmail.com
MAIL_PASSWORD=your-16-character-app-password
MAIL_FROM_ADDRESS=your-account@gmail.com
MAIL_FROM_NAME="FuelFlow FSMS"
```

Recreate the Laravel processes after changing mail configuration:

```bash
docker compose up -d --force-recreate api queue scheduler
docker compose logs -f queue
```

The API, queue worker, and scheduler receive the same mail configuration. User
and company onboarding, purchase-order workflow, and license alerts use the
queue worker; scheduled report delivery uses both the scheduler and queue.
Never commit `.env` or a Gmail App Password.

## Production deployment

The production stack is intentionally separate from local development. It does
not expose PostgreSQL, Redis, or PHP-FPM; it does not run Vite or Laravel's
development server; and it never seeds demo users. Start with the complete
[production deployment runbook](docs/DEPLOYMENT.md).

For Render with Neon PostgreSQL and Render Key Value, use the root
[`render.yaml`](render.yaml) Blueprint and the dedicated
[`docs/DEPLOYMENT_RENDER.md`](docs/DEPLOYMENT_RENDER.md) runbook. The Blueprint
creates the public web service, queue worker, scheduler, and persistent receipt
storage without relying on Docker Compose secret files.

For separate AWS ECS services, use the
[`docs/DEPLOYMENT_AWS_ECS.md`](docs/DEPLOYMENT_AWS_ECS.md) runbook. The default
React production image is standalone and does not depend on Docker Compose DNS;
the Compose gateway build selects its internal FastCGI configuration explicitly.

```bash
cp .env.production.example .env.production
docker compose --env-file .env.production -f compose.production.yaml config --quiet
```

Replace the example domains, application key, database identity, secret files,
and mail settings before building. TLS termination and tested off-host backups
are required production infrastructure.

## Quality checks

Backend:

```bash
docker compose exec api php artisan test
docker compose exec api ./vendor/bin/pint --test
```

Frontend:

```bash
cd apps/web
pnpm run lint
pnpm run test:run
pnpm run build
```

Browser regression (isolated database, queue, and array mailer):

```bash
docker compose -p fuelflow-e2e -f compose.yaml -f compose.e2e.yaml up -d --build --wait postgres redis api web
cd apps/web
pnpm exec playwright install --no-shell chromium
pnpm run test:e2e
cd ../..
docker compose -p fuelflow-e2e -f compose.yaml -f compose.e2e.yaml down --volumes --remove-orphans
```

CI runs the same ten desktop/mobile workflows and blocks production-image
builds when a browser regression fails.

## Repository structure

```text
apps/api/    Laravel API
apps/web/    React + TypeScript web application
docs/        Delivery plan and requirements traceability
compose.yaml Local PostgreSQL/Redis/application stack
compose.production.yaml Hardened production stack
```
