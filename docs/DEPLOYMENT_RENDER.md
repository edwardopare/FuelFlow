# FuelFlow FSMS deployment on Render

This runbook deploys FuelFlow from the root `render.yaml` Blueprint. The public
web service contains the compiled React application, Nginx, and Laravel PHP-FPM
request handler. Email jobs run in a background worker, and Laravel's scheduler
runs once per minute as a Render cron job.

The Blueprint targets Render's Frankfurt region. Create or retain the Render
Key Value instance in Frankfurt as well. The Neon database can remain managed
by Neon and is supplied through its PostgreSQL connection string.

## 1. Prerequisites

- The repository's production branch contains `render.yaml`.
- A Neon PostgreSQL database and connection string with SSL enabled.
- A Render Key Value instance named `fuelflow-redis` in Frankfurt.
- A Gmail account with 2-Step Verification and a 16-character App Password.
- A valid Laravel `APP_KEY` beginning with `base64:`.

Use the Render Key Value **Internal URL**. It is reachable only by services in
the same Render workspace and region and should start with `redis://` or
`rediss://`.

## 2. Create the Blueprint

1. Open the Render Dashboard and select **New +** then **Blueprint**.
2. Connect the GitHub repository `edwardopare/FuelFlow`.
3. Select the production branch containing this file.
4. Keep the Blueprint path as `render.yaml`.
5. Enter the prompted secret values:

   - `APP_KEY`: the existing generated Laravel application key.
   - `DB_URL`: the complete Neon PostgreSQL connection string.
   - `REDIS_URL`: the Render Key Value Internal URL.
   - `MAIL_USERNAME`: the Gmail address that created the App Password.
   - `MAIL_PASSWORD`: the Gmail App Password without spaces.
   - `MAIL_FROM_ADDRESS`: the same Gmail address as `MAIL_USERNAME`.

6. Apply the Blueprint.

Render derives `APP_URL`, `FRONTEND_URL`, CORS, Sanctum, and cookie-domain
settings from the public `fuelflow` service automatically. Do not add URL
placeholders to the Render services.

The Blueprint creates these paid runtime resources:

- `fuelflow`: starter web service with a 1 GB receipt disk.
- `fuelflow-queue`: starter background worker.
- `fuelflow-scheduler`: cron job that runs every minute.

The existing Neon database and Render Key Value instance are not recreated.

## 3. Initial deployment

The web service runs `php artisan migrate --force` before each deployment. Its
health check is `/api/v1/health`. A deployment is ready only after that endpoint
returns HTTP 200.

After the first successful deployment, open the `fuelflow` service Shell and
create the vendor Super User:

```bash
php artisan fuelflow:bootstrap-vendor \
  --name="Vendor Super User" \
  --email="vendor-admin@your-domain.example"
```

The command prompts for a temporary password without printing it. Sign in at
`https://<render-host>/vendor/login` and change that password immediately.

## 4. Verification

1. Open `https://<render-host>/api/v1/health` and confirm HTTP 200.
2. Sign in through `/vendor/login` and onboard a controlled test company.
3. Confirm onboarding email delivery.
4. Complete a Station Manager -> Administrator -> Accountant PO flow.
5. Confirm the queue has no failed jobs from the `fuelflow` Shell:

   ```bash
   php artisan queue:failed
   ```

6. Confirm the scheduler is registered:

   ```bash
   php artisan schedule:list
   ```

## 5. Important storage behavior

Payment receipts are persisted on the web service's encrypted Render disk at:

```text
/var/www/html/storage/app/private/purchase-order-payment-receipts
```

The disk is attached to one service instance. Do not scale `fuelflow` above one
instance without first moving receipt storage to shared object storage.

## 6. Custom domain

The default configuration uses the assigned `onrender.com` hostname. Before
switching to a custom domain, override the following variables on all three
services and redeploy them:

```dotenv
APP_URL=https://fuel.your-domain.example
FRONTEND_URL=https://fuel.your-domain.example
CORS_ALLOWED_ORIGINS=https://fuel.your-domain.example
SANCTUM_STATEFUL_DOMAINS=fuel.your-domain.example
SESSION_DOMAIN=fuel.your-domain.example
```

Keep `APP_DEBUG=false`, use HTTPS, and never commit secret environment values.
