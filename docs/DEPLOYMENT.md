# FuelFlow FSMS production deployment

This runbook deploys the application as a single-host Docker Compose stack.
Only the Nginx gateway is published. PostgreSQL and Redis remain on an internal
Docker network, while PHP-FPM, the queue worker, and the scheduler run as
non-root processes with read-only container filesystems.

For a multi-node or high-availability installation, use the same production
images with managed PostgreSQL, managed Redis, shared durable receipt storage,
and the container platform's secret manager.

## 1. Server prerequisites

- A supported Linux server with Docker Engine and Docker Compose v2.
- A DNS name for the application.
- TLS termination through a load balancer or reverse proxy.
- Persistent backup storage outside this host.
- Outbound SMTP/API access for password reset and scheduled report email.

Keep port 8080 private. The example binds it to `127.0.0.1` so a reverse proxy
on the same host can reach it. If a separate load balancer must connect, set
`APP_BIND_ADDRESS=0.0.0.0` and restrict the port to the load balancer addresses
with the host or cloud firewall.

## 2. Create production configuration

From the repository root:

```bash
cp .env.production.example .env.production
mkdir -p secrets
openssl rand -base64 48 > secrets/postgres_password.txt
openssl rand -base64 48 > secrets/redis_password.txt
touch secrets/mail_password.txt
docker run --rm php:8.4-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Put the generated Laravel key in `APP_KEY` and replace every example domain,
database identity, and mail value in `.env.production`. The public URLs must use
HTTPS. Use a dedicated database username and unique passwords. Put the mail
provider credential into `secrets/mail_password.txt` using a secure editor.

For Gmail SMTP, enable 2-Step Verification and generate a Google App Password.
Use the Gmail address that generated the App Password for both the username and
sender address. Do not use the normal Google account password.

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-account@gmail.com
MAIL_FROM_ADDRESS=your-account@gmail.com
MAIL_FROM_NAME=FuelFlow FSMS
```

Put the 16-character App Password, without spaces, in
`secrets/mail_password.txt`. Port 587 uses `MAIL_SCHEME=smtp`; Symfony
negotiates STARTTLS automatically. Use `MAIL_SCHEME=smtps` only when switching
to implicit TLS on port 465. On Render or another managed platform, set the
secret directly as `MAIL_PASSWORD` instead of `MAIL_PASSWORD_FILE` unless the
platform mounts a secret file.

The queue worker sends onboarding, PO workflow, license, password-reset, and
report messages. The scheduler checks report schedules every minute and checks
licenses daily at 07:00 Africa/Accra. License alerts are deduplicated at 30,
14, 7, 3, 1, and 0 days before expiry. Both the queue and scheduler services
must remain continuously running.

Protect the configuration on Linux:

```bash
chmod 600 .env.production secrets/postgres_password.txt secrets/redis_password.txt secrets/mail_password.txt
```

The password files and `.env.production` are ignored by Git. Do not commit,
copy into images, or send them through chat/email. In a managed container
platform, supply equivalent values through its secret manager.

## 3. TLS and proxy requirements

Terminate TLS before the gateway and forward these headers:

- `Host`
- `X-Forwarded-For`
- `X-Forwarded-Proto: https`

Redirect all HTTP traffic to HTTPS at the outer proxy. The application gateway
adds HSTS, CSP, clickjacking, MIME-sniffing, referrer, and browser-permission
headers. Laravel trusts proxy headers because PHP-FPM is not externally
published and is reachable only through the application network.

## 4. Build and initialize

Every command below uses the same production file and environment file.

```bash
docker compose --env-file .env.production -f compose.production.yaml config --quiet
docker compose --env-file .env.production -f compose.production.yaml build --pull
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate
```

Create the first vendor Super User. This account is outside every customer
tenant and is the only account authorized to onboard companies, manage license
tenure, and suspend tenant access.
The command prompts for the temporary password without echoing it:

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate \
  php artisan fuelflow:bootstrap-vendor \
  --name="Vendor Super User" \
  --email="vendor-admin@your-vendor-domain.example"
```

The bootstrap command refuses to overwrite an existing vendor email and forces
a password change at first login. After startup, sign in at `/vendor/login` and
use **Onboard company** to create each customer company, its Head Office, its
first operating station, and at least one Administrator. The onboarding
transaction assigns organization-wide roles to Head Office and station-scoped
roles to the first station. Set the company license duration in months or years;
expiry and explicit license deactivation revoke all tenant access. Renewing the
license from the company workspace starts a new tenure immediately and restores
access. No demo accounts or demo operational data are created in production.

Build Laravel's runtime caches and start the services:

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan optimize
docker compose --env-file .env.production -f compose.production.yaml up -d
docker compose --env-file .env.production -f compose.production.yaml ps
curl --fail http://127.0.0.1:8080/api/v1/health
```

The health endpoint returns HTTP 200 only when Laravel can reach PostgreSQL.
The container health checks separately verify PHP-FPM, Redis, PostgreSQL, and
the complete gateway-to-API path.

## 5. Deployment verification

Before opening access to users, verify:

1. The public URL redirects HTTP to HTTPS and has a valid certificate.
2. `/api/v1/health` returns `{"status":"ok",...}` without database details.
3. The vendor Super User can log in, must replace the temporary password, and
   can onboard a controlled test company with a license tenure, edit that tenure,
   and deactivate or renew its license.
4. Password-reset and onboarding mail reach a controlled test mailbox, and no
   plaintext temporary password appears in an email.
5. The onboarded Administrator, Station Manager, Pump Attendant, Accountant, Owner, and
   Auditor accounts see only their assigned navigation and data scope.
6. A test PO follows Station Manager → Administrator → Accountant, with the
   request, approval/rejection, and payment emails reaching the correct roles;
   the payment receipt remains downloadable only by authorized users.
7. A license expiring at a configured threshold sends one alert per recipient,
   and a due report schedule sends its CSV attachment once per recipient.
8. Queue and scheduler logs show no repeated failures.

```bash
docker compose --env-file .env.production -f compose.production.yaml logs --since=15m api queue scheduler gateway
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan queue:failed
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan schedule:list
```

## 6. Upgrades and rollback

Always take a database and receipt-storage backup before an upgrade. Then:

```bash
git pull --ff-only
docker compose --env-file .env.production -f compose.production.yaml build --pull
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan optimize
docker compose --env-file .env.production -f compose.production.yaml up -d --remove-orphans
```

Run the health and role-flow checks again. Application rollback is performed by
checking out the previous tested release or image tag and recreating services.
Database rollback is not automatic: restore the pre-deployment database backup
when a migration is not backward compatible.

## 7. Backups

Back up both PostgreSQL and the `api_storage` volume. A database-only example:

```bash
docker compose --env-file .env.production -f compose.production.yaml exec -T postgres \
  pg_dump -U fuelflow_app -d fuelflow -Fc > fuelflow-$(date +%F-%H%M).dump
```

Use the actual `POSTGRES_USER` and `POSTGRES_DB`. Store backups encrypted and
off-host, define retention, and test restoration regularly. The `api_storage`
volume contains payment receipts and other private application files; it must
be backed up with the same recovery point as PostgreSQL.

## 8. Secret rotation

- Redis: replace its secret file and recreate Redis and the application
  services during a maintenance window.
- Database: change the role password inside PostgreSQL first, update the secret
  file, then recreate the application services. Changing only the init secret
  does not modify an existing PostgreSQL role.
- Mail: update `.env.production` (or the platform secret), recreate the API and
  workers, then test password reset.
- Laravel `APP_KEY`: do not rotate casually. It invalidates sessions and can
  make existing encrypted application data unreadable without a planned
  re-encryption migration.

After any non-database configuration change:

```bash
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan optimize:clear
docker compose --env-file .env.production -f compose.production.yaml --profile tools run --rm migrate php artisan optimize
docker compose --env-file .env.production -f compose.production.yaml up -d --force-recreate api queue scheduler gateway
```

## 9. Operational monitoring

Alert on container restarts, unhealthy services, HTTP 5xx rates, failed queue
jobs, low disk space, PostgreSQL/Redis memory pressure, and backup failures.
Forward container stdout/stderr to centralized, access-controlled log storage.
The built-in JSON log rotation prevents unbounded local container logs but is
not a substitute for retention and alerting.
