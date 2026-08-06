# eFileManager

Internal document management and tracking system for the **Municipality of Bongabong, Oriental Mindoro**.

Production: `https://efilemanager.bongabong.gov.ph`

## What this is

Three things in one internal system:

1. **Document tracking (DTS)** — register a memorandum, route it to another office, formally *receive* it, act on it, close it. Every hand-off is recorded with who, when, and from where. This is the core; everything else supports it.
2. **File storage** — per-department folders, versioning, search, permission-checked access.
3. **Building map** — navigate the municipal hall spatially: floors, rooms, offices. Doors light up when an office has documents waiting.

Plus a **public portal** carrying announcements and the DILG Full Disclosure Policy board.

## Guiding principles

**The building is a view, not the data model.** Underneath it is `departments → folders → files` with a routing engine on top. The map renders that data spatially and always has a sidebar-tree and search equivalent. Delete the map and the system still works.

**Receiving is append-only.** `document_routes.received_at` is written exactly once and never updated. A correction adds a new row with a remark; it does not rewrite history. This is what makes the log defensible if it is ever questioned.

**A document has exactly one current holder** at any moment. Forwarding closes the current leg and opens the next inside a single transaction.

**Timestamps are stored in UTC, displayed in Philippine time.** Render with `ph_datetime()` / `ph_date()` (`app/Support/helpers.php`), never with raw `->format()` on a model attribute. See the note in `config/app.php`.

**Documents are never web-reachable.** They live on the `documents` disk at `storage/app/documents`, outside `public/`, with `'serve' => false`. Every read goes through a controller that authorizes first, then streams. Never add that disk to `config/filesystems.php`'s `links` array, and never run `storage:link` against it.

## Stack

| | |
|---|---|
| Framework | Laravel 13 (PHP 8.3) |
| Frontend | Livewire 4 + Tailwind 4 + Alpine.js, built with Vite |
| Database | MariaDB / MySQL |
| Roles | spatie/laravel-permission 8 |
| Tests | PHPUnit 12 |

No websockets — the production host has no persistent process, so live door badges use `wire:poll`. Queues and the scheduler run from cron.

## Local setup

Requires PHP 8.3+, Composer, Node, and MySQL or MariaDB (XAMPP is fine).

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Create both databases — the test suite deliberately uses a real database engine, not SQLite:

```sql
CREATE DATABASE efilemanager      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE efilemanager_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then:

```bash
php artisan migrate
composer dev     # serve + queue + logs + vite together
```

If MySQL is not running under XAMPP, start it from the XAMPP Control Panel, or:

```
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone
```

## Tests

```bash
php artisan test
```

The suite runs against `efilemanager_test` on MySQL/MariaDB, **not** SQLite in-memory. This is deliberate: the routing engine depends on transactional row locking (`lockForUpdate`) for tracking-number uniqueness and single-holder guarantees, and SQLite does not share those semantics. `tests/Feature/DatabaseEngineTest.php` fails loudly if someone points the suite back at SQLite.

## Compliance

This runs on a live `gov.ph` domain and handles personal data, so the following are requirements rather than polish:

- **RA 10173 (Data Privacy Act)** — role-based access, immutable audit logs, confidentiality enforced at the query layer via policies, short session lifetime, encrypted sessions. The LGU must have a registered Data Protection Officer.
- **RA 9470 (National Archives Act)** — `document_types.retention_years` drives archival and disposal reporting. The system reports; it never auto-deletes.
- **DILG Full Disclosure Policy** — the public board is a compliance deliverable, not decoration.
- HTTPS is forced in production, with secure cookies and rate-limited login.

Audit logs are append-only. No update or delete route exists for them at any role.
