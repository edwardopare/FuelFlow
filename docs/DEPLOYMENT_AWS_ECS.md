# FuelFlow FSMS on AWS ECS

The React web image is a standalone static Nginx service. It does not resolve or
connect to an ECS service named `api`, so it can start in any ECS task without
Docker Compose service discovery.

## Public routing

Keep the browser on one HTTPS origin. Configure the Application Load Balancer
listener with higher-priority rules that send `/api/*` and `/sanctum/*` to the
HTTP-capable Laravel target group, and send all other paths to the React target
group. The React application intentionally uses relative API URLs so Laravel
Sanctum cookies remain first-party.

The web target group health check should use `GET /healthz` on container port
`8080`. A successful response is `200` with body `ok`.

The standalone `apps/web/Dockerfile.production` image cannot serve Laravel API
requests. Do not route `/api/*` or `/sanctum/*` to it. The Laravel target must be
fronted by an HTTP server; `apps/api/Dockerfile.production` exposes PHP-FPM on
port 9000 and cannot be connected directly to an HTTP/HTTPS ALB target group.
Use an Nginx sidecar in the API task or a unified web/API image for that target.

## Build and deploy the React image

From the repository root, replace the placeholders with the AWS account,
region, repository, and release tag used by the deployment:

```bash
docker build \
  --file apps/web/Dockerfile.production \
  --tag <account>.dkr.ecr.<region>.amazonaws.com/<web-repository>:<release> \
  apps/web

docker push <account>.dkr.ecr.<region>.amazonaws.com/<web-repository>:<release>
```

Register a new ECS task-definition revision with the immutable release tag,
update the React service to that revision, and force a new deployment. Confirm
that the container stays healthy and that direct navigation to a React route
returns the application instead of a 404.

## Docker Compose compatibility

The single-host production Compose stack still uses the web container as an
Nginx/FastCGI gateway. `compose.production.yaml` explicitly builds that image
with `docker/nginx/default.gateway.conf`; this Compose-only configuration may
resolve the `api` service because both containers share its application network.
