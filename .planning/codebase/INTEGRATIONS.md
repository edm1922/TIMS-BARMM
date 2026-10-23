---
last_mapped_commit: 4a2a41257534aaecfd2249163155617f6d9a4bc1
last_mapped_at: 2026-10-23
---
# External Integrations

**Analysis Date:** 2026-10-23

## APIs & External Services

**Sync Protocol (Custom):**
- Central ↔ Local server synchronization for offline/online operation
- Protocol version: `SYNC_PROTOCOL_VERSION` (default 1, configurable in `config/sync.php:29`)
- Schema version: `SYNC_SCHEMA_VERSION` (default 1, `config/sync.php:33`)
- Endpoints under `/api/sync/v1/` (`routes/api.php:11-47`):
  - `GET /compatibility` - Version negotiation
  - `POST /enroll` - Local server enrollment with central
  - `POST /heartbeat` - Health check
  - `GET /snapshot` - Full data snapshot download
  - `GET /snapshot/{uuid}/beneficiaries` - Paginated beneficiary data
  - `GET /snapshot/{uuid}/reference` - Reference data (locations, trainings)
  - `GET /changes` - Incremental changes since cursor
  - `POST /operations` - Upload local operations
  - `POST /ack` - Acknowledge applied revisions
  - `GET /conflicts` - Fetch pending conflicts
  - `POST /conflicts/{uuid}/resolve` - Resolve conflict
- Authentication: Server token via `X-TIMS-Server-Token` header (`config/sync.php:47`, `app/Services/Sync/SyncClient.php:112`)
- Protocol headers: `X-TIMS-Protocol-Version`, `X-TIMS-Schema-Version` (`config/sync.php:49-51`, `app/Services/Sync/SyncClient.php:104-105`)
- Client implementation: `App\Services\Sync\SyncClient.php` using Laravel HTTP Client (`Illuminate\Http\Client`)

**Email Delivery:**
- Configured mailers in `config/mail.php:38-98`:
  - `smtp` - Standard SMTP (host/port/username/password from env)
  - `ses` - AWS SES (uses `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`)
  - `postmark` - Postmark API (key from `POSTMARK_API_KEY` in `config/services.php:18`)
  - `resend` - Resend API (key from `RESEND_API_KEY` in `config/services.php:22`)
  - `sendmail` - Local sendmail binary
  - `log` - Log to Laravel log (default in `.env.example:70`)
  - `array` - Testing only
  - `failover` / `roundrobin` - Fallback chains
- Default: `MAIL_MAILER=log` (`.env.example:70`)

**Slack Notifications:**
- Configured in `config/services.php:31-36`
- Bot token: `SLACK_BOT_USER_OAUTH_TOKEN`
- Default channel: `SLACK_BOT_USER_DEFAULT_CHANNEL`
- Not actively used in codebase (no Slack client imports found)

## Data Storage

**Databases:**
- Primary: MariaDB/MySQL via PDO (`config/database.php:47-65`, `.env.example:35-40`)
  - Connection: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`
  - Database: `tims_barmm` (created manually per README)
  - Auth: `DB_USERNAME=root`, `DB_PASSWORD=` (empty default for XAMPP)
  - Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`
- Testing: SQLite in-memory (`phpunit.xml:26-28`)
  - `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`
- Redis: Configured for cache, queue, sessions (`config/database.php:146-182`)
  - Client: `phpredis` (`.env.example:65`)
  - Host: `127.0.0.1:6379` (`.env.example:66-68`)
  - Databases: `0` (default), `1` (cache)
- Supported but not configured: PostgreSQL, SQL Server (`config/database.php:87-115`)

**File Storage:**
- Local filesystem (default): `FILESYSTEM_DISK=local` (`.env.example:57`, `config/filesystems.php:16`)
  - Private disk: `storage/app/private` (`config/filesystems.php:33-39`)
  - Public disk: `storage/app/public` linked to `public/storage` (`config/filesystems.php:41-48`, `config/filesystems.php:77`)
- S3-compatible (configured, optional): `config/filesystems.php:50-61`
  - Credentials: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`
  - Region: `AWS_DEFAULT_REGION`
  - Bucket: `AWS_BUCKET`
  - Path-style endpoint: `AWS_USE_PATH_STYLE_ENDPOINT` (for MinIO/etc)
- Import workflow: Uploaded .xlsx files stored temporarily in `imports/` on local disk, deleted after apply/cancel (`app/Http/Controllers/ImportController.php:89-116`, `app/Http/Controllers/ImportController.php:323-343`)

**Caching:**
- Default: Database (`CACHE_STORE=database`, `.env.example:60`, `config/cache.php:18`)
  - Table: `cache` (migration creates it)
  - Lock table: configurable via `DB_CACHE_LOCK_TABLE`
- Redis: Available as `redis` store (`config/cache.php:75-79`)
- Memcached: Configured (`config/cache.php:56-73`), env vars `MEMCACHED_HOST`, `MEMCACHED_PORT`
- DynamoDB: Configured (`config/cache.php:81-88`), uses AWS credentials
- Fallback: `failover` store (database → array)
- Prefix: `tims-barmm-cache-` (`config/cache.php:115`)

**Queue:**
- Default: Database (`QUEUE_CONNECTION=database`, `.env.example:58`, `config/queue.php:16`)
  - Table: `jobs` (default, `config/queue.php:41`)
  - Retry after: 90s (`config/queue.php:43`)
  - Failed jobs: `database-uuids` driver, table `failed_jobs` (`config/queue.php:124-127`)
- Redis: Available (`config/queue.php:67-74`)
- SQS: Configured (`config/queue.php:56-65`), uses AWS credentials
- Sync/Deferred/Background: For testing/local dev (`config/queue.php:34-78`)
- Failover: Database → Deferred (`config/queue.php:84-90`)

## Authentication & Identity

**Application Auth:**
- Laravel built-in authentication (session-based)
- Login: `App\Http\Controllers\Auth\LoginController.php` (`routes/web.php:23-26`)
- Password hashing: Bcrypt with `BCRYPT_ROUNDS=12` (`.env.example:25`)
- Session: Database driver (`SESSION_DRIVER=database`, `.env.example:50`)
  - Lifetime: 120 minutes (`.env.example:51`)
  - Encryption: Disabled by default (`SESSION_ENCRYPT=false`, `.env.example:52`)
- Remember token: Standard Laravel remember me
- Role-based access: Admin/Staff roles (middleware `role:admin`, `role:admin,staff` in `routes/web.php:41,68,93`)

**Sync Identity (Server-to-Server):**
- Instance modes: `online` (central) or `offline` (local) (`config/sync.php:17`, `.env.example:10`)
- Central server UUID: `CENTRAL_SERVER_UUID` (`.env.example:11`, `config/sync.php:19`)
- Local server UUID: `LOCAL_SERVER_UUID` (`.env.example:12`, `config/sync.php:21`)
- Enrollment tokens: TTL 1440 min (`config/sync.php:43`), prefix `tims_sync_` (`config/sync.php:45`)
- Server token: Stored encrypted in `SyncSetting` model, sent via `X-TIMS-Server-Token` header

## Monitoring & Observability

**Error Tracking:**
- Laravel's built-in exception handling (`app/Exceptions/Handler.php` implied)
- Collision for test output (`nunomaduro/collision`)
- No external error tracking service (Sentry, Bugsnag) detected

**Logs:**
- Channel: `stack` (`.env.example:27`), stack of `single` (`.env.example:28`)
- Level: `debug` (`.env.example:30`)
- Deprecations: Separate channel (`LOG_DEPRECATIONS_CHANNEL=null`, `.env.example:29`)
- Pail: `laravel/pail` for real-time log tailing in dev (`composer.json:18`, `composer.json:46`)

**Audit Trail:**
- Custom `AuditLogger` service (`app/Services/AuditLogger.php`)
- Logs: Logins, beneficiary CRUD, import lifecycle, duplicate decisions, exports, card printing
- Model: `AuditLog` (implied, referenced in `AuditLogController.php`)
- Access: Admin only (`routes/web.php:101`)

## CI/CD & Deployment

**Hosting:**
- Target: XAMPP on Windows (development), traditional LAMP/LEMP (production)
- No containerization required (Sail available but optional)
- No PaaS (Forge, Vapor, Heroku) configuration detected

**CI Pipeline:**
- No GitHub Actions, GitLab CI, or similar CI config files detected
- Local test command: `php artisan test` (runs PHPUnit with SQLite)

**Build/Deploy Scripts:**
- `composer setup` - Full install (Composer, .env, key, migrate, npm, build)
- `composer dev` - Concurrent dev servers (PHP, queue, logs, Vite)
- `composer test` - Clear config cache, run tests
- `npm run build` - Vite production build
- `npm run dev` - Vite dev server with HMR

## Environment Configuration

**Required env vars (from `.env.example`):**
| Variable | Purpose | Default |
|----------|---------|---------|
| `APP_KEY` | Encryption key | Required (generate via `artisan key:generate`) |
| `DB_DATABASE` | Database name | `tims_barmm` |
| `DB_USERNAME` / `DB_PASSWORD` | DB credentials | `root` / empty |
| `ADMIN_PASSWORD` | Initial admin password | Auto-generated if empty |
| `CENTRAL_SERVER_UUID` | Sync central identity | Required for online mode |
| `LOCAL_SERVER_UUID` | Sync local identity | Required for offline mode |

**Optional but important:**
- `MAIL_*` - Email delivery config
- `AWS_*` - S3, SES, SQS, DynamoDB
- `REDIS_*` - Redis connection
- `SYNC_*` - Sync protocol tuning

**Secrets location:**
- `.env` file (gitignored, per `.gitignore:3`)
- Never committed; `.env.example` is the template
- Encrypted sync token stored in database via `SyncSetting::getEncrypted()` (`app/Services/Sync/SyncClient.php:109`)

## Webhooks & Callbacks

**Incoming:**
- Sync protocol endpoints (see Sync Protocol above) - receives enrollment, heartbeats, operations, conflict resolutions from local servers
- No generic webhook receiver detected

**Outgoing:**
- Sync client calls to central server (`App\Services\Sync\SyncClient.php`):
  - All endpoints under central's `/api/sync/v1/`
  - Timeout: 30 seconds (`SyncClient.php:102`)
- Email notifications via configured mailer
- No other outbound webhooks detected

---

*Integration audit: 2026-10-23*
