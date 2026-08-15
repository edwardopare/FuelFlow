# Production secret files

Create these three files before starting the production stack:

- `postgres_password.txt`
- `redis_password.txt`
- `mail_password.txt`

Each file must contain one secret and a trailing newline is optional. Generate
strong, unique database and Redis passwords; put the mail provider's SMTP/API
credential in `mail_password.txt`. The files are excluded from Git; only this
README is kept.
