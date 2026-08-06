# Chambre Rose PHP API

Production-oriented PHP 8.2+ API for [Chambre Rose](https://www.chambre-rose.com). This repository is the source-controlled version of the PHP backend currently used by the Angular storefront, with account email, password recovery and newsletter support added.

## Features

- JWT authentication and role-based administrator routes
- Customer registration with establishment photo upload and manual account review
- English registration-received email with a 24-hour review window
- Approval or rejection email sent after the admin decision
- Secure, single-use password reset links with a configurable expiry
- Idempotent newsletter subscription and English confirmation email
- Product CRUD, product images, categories and purchase counters
- VIP user administration
- MySQL and PostgreSQL/Supabase-compatible migrations
- CORS, security headers, upload validation and non-enumerating recovery responses

## Requirements

- PHP 8.2 or newer
- PDO MySQL or PDO PostgreSQL
- Fileinfo
- Composer when SMTP/PHPMailer is used
- Apache with `mod_rewrite`, or PHP's development server

## Local setup

```bash
cp .env.example .env
composer install
php -S 127.0.0.1:8080 -t public public/router.php
```

Generate a strong `JWT_SECRET`, then configure either `DATABASE_URL` or the individual `DB_*` variables in `.env`. Never expose the database password, JWT secret, Supabase secret key or SMTP password in the Angular application.

The Angular development server already proxies `/api` to `http://localhost:8080`.

### Docker

```bash
docker build -t chambre-rose-php .
docker run --rm -p 8080:80 --env-file .env chambre-rose-php
```

## Email configuration

Messages are intentionally English-only.

Easyhost can use its server-side PHP mail transport:

```dotenv
MAIL_TRANSPORT=mail
MAIL_FROM_ADDRESS=no-reply@chambre-rose.com
MAIL_FROM_NAME=Chambre Rose
APP_FRONTEND_URL=https://www.chambre-rose.com
```

For authenticated SMTP, set `MAIL_TRANSPORT=smtp` and configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME` and `SMTP_PASSWORD`. The real values belong only in `.env` on the server.

## API overview

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/api/health` | Health check |
| `POST` | `/api/auth/register` | Register with multipart `establishmentPhoto` |
| `POST` | `/api/auth/login` | Sign in |
| `POST` | `/api/auth/forgot-password` | Request a reset link |
| `POST` | `/api/auth/reset-password` | Consume a reset token |
| `GET/PUT` | `/api/auth/me` | Read or update the current profile |
| `POST` | `/api/newsletter/subscribe` | Subscribe an email address |
| `PATCH` | `/api/admin/users/{id}/status` | Approve or reject a pending account (admin) |
| `GET` | `/api/products` | List products |
| `GET` | `/api/products/{id}` | Product details |
| `POST/PUT/DELETE` | `/api/products...` | Administrator product management |
| `GET/PATCH` | `/api/admin/users...` | Administrator user/VIP management |

Password reset tokens are random, stored only as SHA-256 hashes, expire after 30 minutes by default and are invalidated after use. The forgot-password response is identical for known and unknown addresses.

## Database migrations

Migrations run automatically by default (`APP_AUTO_MIGRATE=true`) and are protected by a database advisory lock. Both engines are supported:

- MySQL: `database/schema.mysql.sql` and `database/migrations/*.mysql.sql`
- PostgreSQL/Supabase: `database/schema.sql` and `database/migrations/*.pgsql.sql`

## Deployment notes

Point the web root to `public/`, keep `.env` outside version control, make `storage/establishment-photos` writable by the PHP user, and run `composer install --no-dev --optimize-autoloader` when SMTP is enabled. The Angular production config uses the same-origin `/api` path.

## Security

- `.env`, `vendor/`, uploads and logs are ignored by Git.
- Public Supabase keys may be used by browser applications, but the Supabase secret/service key must remain server-side.
- Rotate any credential that has been shared outside the server's secret store.
