# POS

Simple PHP/MySQL point-of-sale system.

## Local Setup

1. Create a MySQL database named `pos_system`.
2. Import `database.sql`.
3. For an existing installation, apply pending migrations with `php migrate.php` as a deploy step. Do not run migrations through web requests.
4. Copy `.env.example` to `.env` and set your database credentials.
5. Serve the project from XAMPP/Apache and visit `index.php`.

Default admin account from `database.sql`:

```text
username: admin
password: admin123
```

Change the default password before using real data.

## Security Notes

- Forms and AJAX sale completion use CSRF tokens.
- Destructive actions use POST requests instead of delete links.
- User uploads under `assets/profile_photos` deny PHP execution.
- Debug files, `.env`, SQL dumps, and logs are blocked by `.htaccess`.
- Schema changes belong in SQL files under `scripts/`, not in page requests.

## Docker Build & Test

Build the image locally and run a container:

```bash
docker build -t pos-app .
docker run -p 8080:80 --rm pos-app
```

To inspect enabled Apache modules inside the running container:

```bash
docker run --rm pos-app bash -c "ls /etc/apache2/mods-enabled && apachectl -M | grep mpm || true"
```

## Mail / Password reset

This project uses Brevo (formerly Sendinblue) or Resend for transactional emails.

- Preferred: Brevo HTTP API using an API key.
- Fallback: Resend HTTP API (if you have a Resend account).

Environment variables (copy from `.env.example`):

- `BREVO_API_KEY` — your Brevo API key (required to send reset emails via Brevo).
- `BREVO_FROM_ADDRESS` — sender email address used by Brevo (e.g. no-reply@example.com).
- `BREVO_FROM_NAME` — sender name used by Brevo (e.g. "POS System").
- `RESEND_API_KEY` — optional Resend API key if you prefer Resend as a provider.

Notes:

- SMTP is not used by default. The app sends password reset emails through Brevo's HTTP API (`/v3/smtp/email`).
- Ensure `BREVO_API_KEY` is set in your environment or in `.env` before attempting password resets.
- Logging for mail activity can be enabled by setting `MAIL_DEBUG=true` in `.env`.

### Railway deployment

Railway does not receive your local `.env` file (it is intentionally ignored by Git). In the
Railway service **Variables** tab, set these values and redeploy:

- `BREVO_API_KEY` — a Brevo v3 API key with transactional-email access.
- `BREVO_FROM_ADDRESS` — an email address or domain verified in Brevo.
- `BREVO_FROM_NAME` — the sender display name.
- `APP_URL` — your public Railway URL, for example `https://your-app.up.railway.app`.

Set `MAIL_DEBUG=true` temporarily when diagnosing delivery. Trigger a reset, then inspect the
Railway deployment logs for the Brevo HTTP response. Do not leave debug logging enabled longer
than needed. The Docker image includes PHP cURL because the Brevo HTTP API requires it.
