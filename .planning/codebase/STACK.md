---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
# Technology Stack

**Analysis Date:** 2026-10-23

## Languages

**Primary:**
- PHP 8.2+ - Backend application logic, Laravel framework, services, controllers, models (`app/`, `config/`, `database/`, `routes/`, `tests/`)
- JavaScript (ESM) - Frontend build pipeline, Vite configuration (`vite.config.js`, `resources/js/app.js`, `resources/css/app.css`)

**Secondary:**
- SQL - Database migrations and seeders (`database/migrations/`, `database/seeders/`)
- Blade templating - Server-rendered views (`resources/views/`)
- CSS - Styling via TailwindCSS 4 (`resources/css/app.css`)

## Runtime

**Environment:**
- PHP 8.2 (required by `composer.json` line 9)
- XAMPP stack: Apache + MariaDB 10.4 (per README.md)
- Node.js 18+ (for Vite dev/build, per `package.json` devDependencies)

**Package Manager:**
- Composer 2.x - PHP dependencies (`composer.json`, `composer.lock`)
  - Lockfile: present (`composer.lock` 327KB)
- npm - Frontend dependencies (`package.json`)
  - Lockfile: not committed (no `package-lock.json` visible)

## Frameworks

**Core:**
- Laravel 12.x (`laravel/framework ^12.0` in `composer.json`) - Full-stack PHP framework
- Laravel Vite Plugin 2.x (`laravel-vite-plugin ^2.0.0`) - Vite integration for asset bundling
- TailwindCSS 4.x (`tailwindcss ^4.0.0`, `@tailwindcss/vite ^4.0.0`) - Utility-first CSS framework

**Testing:**
- PHPUnit 11.5 (`phpunit/phpunit ^11.5.50`) - Unit and feature testing (`phpunit.xml`)
- Pest PHP (`pestphp/pest-plugin` allowed in `composer.json` config) - Available but tests use PHPUnit syntax
- Faker (`fakerphp/faker ^1.23`) - Test data generation
- Mockery (`mockery/mockery ^1.6`) - Mocking framework
- Collision (`nunomaduro/collision ^8.6`) - Error reporting for tests

**Build/Dev:**
- Vite 7.x (`vite ^7.0.7`) - Frontend build tool and dev server
- Concurrently (`concurrently ^9.0.1`) - Runs multiple dev processes (PHP server, queue, logs, Vite)
- Laravel Pint (`laravel/pint ^1.24`) - Code style enforcement (PSR-12)
- Laravel Sail (`laravel/sail ^1.41`) - Docker development environment (optional)
- Laravel Pail (`laravel/pail ^1.2.2`) - Log tailing

**Key Libraries:**
- PhpSpreadsheet 5.x (`phpoffice/phpspreadsheet ^5.10`) - Excel (.xlsx) import/export (`app/Services/Import/`, `app/Services/Export/`)
- Axios (`axios ^1.11.0`) - HTTP client for frontend (available in `package.json`)
- Laravel Tinker (`laravel/tinker ^2.10.1`) - REPL for debugging

## Key Dependencies

**Critical:**
- `laravel/framework ^12.0` - Core framework providing routing, ORM, auth, validation, queues, cache, broadcasting
- `phpoffice/phpspreadsheet ^5.10` - Excel workbook parsing and generation for import/export features
- `php ^8.2` - Language runtime with typed properties, enums, readonly classes, DNF types

**Infrastructure:**
- `illuminate/database` - Eloquent ORM with MySQL/MariaDB driver
- `illuminate/queue` - Queue workers with database, Redis, SQS drivers
- `illuminate/cache` - Cache stores (database, Redis, file, array, DynamoDB)
- `illuminate/filesystem` - Storage abstraction (local, S3 via Flysystem)
- `illuminate/http` - HTTP client for SyncClient (`App\Services\Sync\SyncClient.php:9`)
- `illuminate/session` - Session handling (database driver default)

**Development:**
- `laravel/pint ^1.24` - Code style (runs on `composer dev` script)
- `nunomaduro/collision ^8.6` - Beautiful CLI error output for tests
- `fakerphp/faker ^1.23` - Fake data for seeders and tests

## Configuration

**Environment:**
- Primary config: `.env` (gitignored), template at `.env.example`
- Key environment variables (from `.env.example`):
  - `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`
  - `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  - `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT`
  - `CACHE_STORE`, `QUEUE_CONNECTION`
  - `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_CLIENT`
  - `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`
  - `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`
  - `INSTANCE_MODE`, `CENTRAL_SERVER_UUID`, `LOCAL_SERVER_UUID` (sync protocol)
  - `SYNC_PROTOCOL_VERSION`, `SYNC_SCHEMA_VERSION`

**Build:**
- `vite.config.js` - Vite configuration with Laravel plugin and TailwindCSS
- `composer.json` - PHP dependencies and scripts (`setup`, `dev`, `test`)
- `phpunit.xml` - Test configuration (SQLite in-memory, array cache/queue/mail)
- `.editorconfig` - Editor settings (4-space indent, LF endings, UTF-8)

## Platform Requirements

**Development:**
- PHP 8.2+ with extensions: pdo_mysql, mbstring, xml, ctype, json, bcmath, gd, zip, openssl
- Composer 2.x
- Node.js 18+ and npm
- XAMPP (Apache + MariaDB) or equivalent
- Git

**Production:**
- PHP 8.2+ (FPM recommended)
- Web server: Nginx or Apache with mod_php/FPM
- Database: MariaDB 10.4+ or MySQL 8.0+
- Redis 6+ (optional, for cache/queue/sessions)
- Supervisor (for queue workers)
- SSL certificate (HTTPS)
- File storage: Local filesystem or S3-compatible

---

*Stack analysis: 2026-10-23*
