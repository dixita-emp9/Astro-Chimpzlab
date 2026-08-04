# MicroCRM

A small, fast PHP CRM for capturing leads from multiple websites and managing
them in one place. Built on [FlightPHP](https://flightphp.com/) + SQLite.

## Features

- **Per-site lead capture** - each site you connect gets its own API key, so
  every lead is tagged with its source and can be individually disabled.
- **Two embed options** - a drop-in JS widget (`<script>` tag, strongest spam
  protection) or a plain HTML `<form>` fallback for no-JS sites.
- **Spam protection**, all server-side, no external services required:
  - honeypot field
  - per-IP rate limiting
  - signed time-trap token (rejects submissions that are too fast or replayed
    stale tokens) - JS widget only
  - disposable-email-domain blocklist
  - admin-editable keyword / email-domain / regex rules, each set to
    "flag" (kept, marked spam) or "block" (still logged, but hidden by default)
- **Lead management backend** - filter by site/status/date/search, change
  lead status, delete, and export filtered results to CSV.

## Requirements

- PHP 8.1+
- Composer
- `pdo_sqlite` extension (bundled with most PHP installs)

## Setup

```bash
composer install
cp .env.example .env
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # paste the output into APP_KEY in .env
php bin/install.php                                  # creates the DB schema, an admin user, and default spam rules
php -S localhost:8080 -t public
```

Visit `http://localhost:8080/login` and sign in with the admin account you
just created.

Re-run `php bin/install.php` any time to reset an admin password (schema and
spam-rule seeding are skipped if they already exist).

## Adding a site

1. Log in, go to **Sites → Add site**, give it a name.
2. MicroCRM generates an API key and shows you two embed snippets:
   - a `<script>` tag (recommended) that renders a styled, spam-hardened form
   - a plain `<form>` you can paste directly into a static HTML page
3. Paste the snippet into the target site. Submissions show up under
   **Leads**, tagged with that site.

## Spam rules

**Spam Rules** in the admin lets you add/remove keyword, email-domain, or
regex rules against name/email/message, each set to *flag* (kept, marked
spam for review) or *block* (same, but excluded from the default "new"
leads view). A starter set of disposable-email domains and spammy keywords
is seeded by `bin/install.php`.

## Notes on scaling

SQLite is plenty for small-to-medium lead volume and keeps deployment to
"copy files, point PHP at them." If you outgrow it, swap `DB_PATH` handling
in `src/helpers.php` (`db()`) for a MySQL PDO DSN - the rest of the app only
talks to the database through plain PDO/SQL, so the change is contained to
that one function plus the few `datetime('now', ...)` SQLite-isms in
`src/SpamFilter.php` and the leads-filter query in `src/routes/admin.php`.
