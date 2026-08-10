# Deploying eFileManager

Target: **Hostinger Cloud Startup**, serving `https://efilemanager.bongabong.gov.ph`.

This is a government domain handling personal data under RA 10173. Everything
below assumes that, and none of it is optional.

---

## Before the first deploy

1. **Point the subdomain** at the hosting account and let the SSL certificate
   issue before doing anything else.
2. **Force HTTPS.** A sign-in form served over plain HTTP on a `gov.ph` domain
   is a finding waiting to happen.
3. **Create the database** and a user with a password that is not reused
   anywhere else.

## The document store is not web-reachable

`storage/app/documents/` holds official records and must never be served
directly. Two rules:

- Do **not** run `php artisan storage:link` for it. The `documents` disk is
  declared with `'serve' => false` in `config/filesystems.php` and is absent
  from the `links` array on purpose.
- The web root is `public/`. If the host is serving the project root instead,
  fix that first — `.env` sits one level above `public/`.

Verify after every deploy:

```bash
curl -I https://efilemanager.bongabong.gov.ph/storage/app/documents/
# expect 403 or 404 — anything else means stop and fix the document root
```

## Deploy

```bash
cd ~/domains/efilemanager.bongabong.gov.ph
git pull origin main

composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`--force` is required because migrations refuse to run unprompted in
production. Read what is pending before typing it.

### After changing `.env`

`config:cache` freezes the environment into a compiled file. If `.env` changes,
the application will not notice until the cache is rebuilt:

```bash
php artisan config:clear && php artisan config:cache
```

### Never rotate `APP_KEY` casually

`APP_KEY` signs the QR codes on every routing slip. Rotating it invalidates
every slip already printed and circulating in the building — staff would scan a
code and be told the link is invalid, with no way to tell why. If it must be
rotated (a leak), plan to reprint the slips for every open document, and warn
the records office before doing it.

---

## Cron

Two entries. Both are required; neither is a nice-to-have.

```cron
* * * * * cd ~/domains/efilemanager.bongabong.gov.ph && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd ~/domains/efilemanager.bongabong.gov.ph && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

**`schedule:run`** drives everything time-based. It must run every minute —
Laravel decides internally what is actually due. Currently it runs the morning
desk digest at 07:30 Manila on weekdays.

**`queue:work --stop-when-empty --max-time=55`** is the shared-hosting pattern:
no persistent process, so a worker starts each minute, drains the queue, and
exits before the next one begins. `--max-time=55` guarantees it does. Without
this entry, notifications are written to the queue and never sent, silently.

Check both are alive:

```bash
php artisan schedule:list
php artisan queue:monitor default --max=100
```

### Mail

The digest sends over whatever `MAIL_MAILER` is configured. Until SMTP
credentials are set, leave it on `log` — the in-app Alerts list works without a
mail server, so nothing is lost while that is being sorted out. Do not point it
at a personal Gmail account; use the LGU's own mail.

---

## Verifying a deploy

Not a substitute for the test suite, which runs before the branch is merged.
This is the "did it land correctly" pass:

```bash
php artisan about                      # environment, cache states, driver versions
php artisan migrate:status             # nothing pending
php artisan schedule:list              # digest listed, next run in Manila time
```

Then by hand, signed in as a real account:

1. Register a document — a tracking number is issued.
2. Open its routing slip and print one. The QR square must be crisp.
3. Scan it with a phone on mobile data, **not** the office wifi. It should ask
   you to sign in, then land on the document. This is the step that catches a
   wrong `APP_URL` or a proxy stripping the query string.
4. Receive it. The timestamp shown must be Philippine time, not UTC.
5. Confirm `/storage/app/documents/` is still not reachable.

---

## Backups

Hostinger's daily backup covers the database and the files, and that is the
baseline, not the plan. Before the system holds anything the office depends on,
confirm two things:

- a database dump can actually be **restored** into a scratch database;
- `storage/app/documents/` is included in the backup.

An untested backup is a belief, not a backup.

---

## Rolling back

```bash
git log --oneline -5
git checkout <previous-commit>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Migrations are a separate decision. **Do not run `migrate:rollback` on a
database holding real documents** without reading the `down()` of everything it
would undo — several drop tables that hold the transmittal ledger and the audit
trail, which are the two things this system exists to preserve. Restore from a
backup instead.
