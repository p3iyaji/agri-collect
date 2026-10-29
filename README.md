# DigitCapture

Multi-tenant, **offline-first** field data collection platform for agricultural
research in West African communities — in the spirit of KoboCollect.

Multiple organizations (workspaces) each own their forms, submissions,
dashboards, and exports. Field enumerators fill forms on phones that may be
hours from a connection; everything is stored locally first and synced
idempotently when a connection appears.

## Architecture

```
┌────────────────────────────┐     ┌────────────────────────────┐
│  Web SPA (Vue 3 + Vite)    │     │  Mobile app (Ionic/Capacitor│
│  - Form builder            │     │  - same Vue components      │
│  - Dashboards + Chart.js   │     │  - camera / GPS / offline   │
│  - PWA (vite-plugin-pwa)   │     │                             │
│  - IndexedDB outbox        │     │  - IndexedDB outbox         │
└──────────────┬─────────────┘     └──────────────┬──────────────┘
               │        JSON:API over REST        │
               │   Bearer JWT (access + refresh)  │
               ▼                                  ▼
        ┌─────────────────────────────────────────────┐
        │        Laravel 13 API (stateless)           │
        │  - JWT carries account_id + role + perms    │
        │  - BelongsToAccount global scope per model  │
        │  - Idempotent sync (local_uuid + receipts)  │
        │  - Streaming CSV export                     │
        └─────────────────────────────────────────────┘
               │
        MySQL / PostgreSQL (SQLite for dev/tests)
```

**No Inertia.** The backend is a pure API; the frontend is a standalone Vue 3
SPA (Vue Router, Pinia, Axios). The same SPA code is packaged as the mobile
app with Capacitor, so form logic and UI are written exactly once.

### Key decisions

- **Stateless tenancy via JWT.** The access token embeds `account_id`, the
  user's role in that workspace, and the role's permissions. Tenant and RBAC
  resolution therefore never query the database; switching workspace is just a
  token re-issue (`POST /api/auth/switch-account`). The `BelongsToAccount`
  trait globally scopes all tenant models to the active workspace.
- **Idempotent offline sync.** Every submission carries a device-generated
  `local_uuid`. The server keeps a `sync_receipts` row per UUID and enforces
  `UNIQUE (account_id, local_uuid)` on submissions — a retried batch after a
  lost response returns `duplicate` instead of creating a copy.
- **One write path.** Both the web outbox and the mobile app submit through
  `POST /api/sync/submissions`; photos go through `POST /api/sync/attachments`
  (multipart, idempotent per field).

### Note on the original package list

The brief referenced `fernandoguiao/laravel-stateless-tenancy` and
`kaungsithu883/laravel-offline` (and Laravel Sanctum for JWTs). None of those
packages exist in this project's dependency set, so the same capabilities are
implemented as small first-party components with **zero added composer
dependencies**:

| Capability                | Implemented by                                        |
| ------------------------- | ----------------------------------------------------- |
| Stateless tenant in token | `app/Services/JwtService.php` (self-contained HS256 JWT) + `app/Auth/JwtGuard.php` |
| Tenant scoping            | `app/Traits/BelongsToAccount.php` + `app/Support/Tenancy.php` + middleware |
| Offline sync receipts     | `sync_receipts` table + `app/Http/Controllers/Api/SyncController.php` |

Sanctum issues opaque, database-backed tokens and cannot carry claims, which is
why the JWT is a compact custom implementation (HMAC-SHA256) instead.

## Repository layout

```
├── app/
│   ├── Auth/JwtGuard.php              # stateless "jwt" auth driver
│   ├── Http/
│   │   ├── Controllers/Api/           # Auth, Form, Submission, Sync, Dashboard, Export
│   │   ├── Middleware/                # jwt.auth, account.resolve/required/match, permission
│   │   ├── Requests/                  # validation
│   │   └── Resources/FormResource.php # JSON:API-style serialization
│   ├── Models/                        # Account, Role, Membership, Form, Submission, ...
│   ├── Services/JwtService.php        # HS256 JWT issue/verify
│   ├── Support/Tenancy.php            # per-request active workspace
│   └── Traits/BelongsToAccount.php    # global tenant scope
├── config/tenancy.php                 # JWT TTLs + default roles/permissions
├── database/migrations/               # accounts, roles, account_user, forms,
│                                      # submissions, attachments, sync_receipts
├── database/seeders/DemoSeeder.php    # demo workspace, users, crop-health form
├── routes/api.php                     # all API routes
├── tests/Feature/MultiTenantSyncTest.php
└── frontend/                          # standalone Vue 3 SPA (also the Capacitor app)
    ├── capacitor.config.json
    ├── vite.config.js                 # + vite-plugin-pwa, Tailwind, /api proxy
    └── src/
        ├── services/                  # api (Axios + 401 refresh), idb, image
        ├── stores/auth.js             # session + tenant state (Pinia)
        ├── composables/
        │   ├── useOfflineQueue.js     # IndexedDB outbox, batch POST, backoff
        │   └── useMobileSync.js       # connectivity, GPS, camera, form cache
        ├── views/                     # Login, Dashboard, Forms, FormBuilder,
        │                              # Submissions, FormFill
        └── components/                # DynamicField, WorkspaceSwitcher, SyncBadge
```

## Backend

```bash
# from the repository root (requires PHP 8.3+ and Composer)
composer install
cp .env.example .env          # .env already exists in this workspace
php artisan key:generate
php artisan migrate
php artisan db:seed           # demo workspace + users + form
php artisan storage:link      # serve uploaded photos
php artisan serve             # API on http://127.0.0.1:8000
```

Demo logins (from `DemoSeeder`):

| Email               | Password   | Role in "AgriResearch West Africa" |
| ------------------- | ---------- | ---------------------------------- |
| `admin@example.org` | `password` | Owner                              |
| `field@example.org` | `password` | Enumerator                         |

Add `TENANCY_JWT_SECRET` to `.env` in production (falls back to `APP_KEY`).

### API overview

All responses are JSON:API-style: `{ "data": { "type", "id", "attributes" } }`
or `{ "data": [...], "meta": { "total", "page", "per_page" } }`.

| Method | Route | Description |
| ------ | ----- | ----------- |
| POST | `/api/auth/login` | Credentials → access + refresh JWT, active workspace, all memberships |
| POST | `/api/auth/refresh` | Refresh token → new pair (used by clients on 401) |
| POST | `/api/auth/switch-account` | Re-issue the pair with a different `account_id` (tenant switch) |
| GET | `/api/auth/me` | Current user + workspace memberships |
| GET | `/api/forms?status=` | Workspace forms (full JSON schema) |
| POST/PUT/DELETE | `/api/forms[/{form}]` | Builder CRUD (`forms.manage`); DELETE archives |
| GET | `/api/submissions` | Paginated, filterable (`form_id`, `from`, `to`, `q`) |
| GET | `/api/submissions/{id}` | One submission + attachments |
| POST | `/api/sync/submissions` | **Idempotent batch upsert** (offline outbox, web + mobile) |
| POST | `/api/sync/attachments` | Multipart photo upload for a synced submission |
| POST | `/api/workspaces/{account}/imports/submissions` | **CSV import** into a form (`submissions.write`): multipart `form_id` + `csv`, idempotent per row, per-row errors |
| GET | `/api/dashboard/summary` | Totals, by-form, by-day (30 days) |
| GET | `/api/dashboard/field-stats?form_id=&field=` | avg/min/max/count or value counts |
| GET | `/api/workspaces/{account}/exports/submissions?format=csv` | Streaming CSV export (tenant-scoped) |

### Administration

| Method | Route | Permission |
| ------ | ----- | ---------- |
| GET | `/api/workspaces` | — (lists workspaces the user belongs to, with counts) |
| POST | `/api/workspaces` | — (self-serve create; creator becomes Owner, default roles provisioned) |
| PATCH | `/api/workspaces/{account}` | `accounts.manage` (rename / settings) |
| DELETE | `/api/workspaces/{account}?confirm=true` | `accounts.manage` (cascades everything) |
| GET/POST | `/api/workspaces/{account}/members` | `members.manage` (invite: existing user attached, new user created with a one-time password in `meta.temp_password`) |
| PATCH/DELETE | `/api/workspaces/{account}/members/{user}` | `members.manage` (reassign role, suspend/reactivate, remove) |
| GET/POST | `/api/workspaces/{account}/roles` | `roles.manage` (index includes the permission catalog in `meta.permissions`) |
| PATCH/DELETE | `/api/workspaces/{account}/roles/{role}` | `roles.manage` (delete refused while active members hold it) |
| GET | `/api/workspaces/{account}/fields` | `forms.manage` (flattened cross-form field overview) |
| PATCH | `/api/workspaces/{account}` (settings) | `accounts.manage` (merges `organization` / `field` blocks; internal keys like `logo_path` survive) |
| POST | `/api/workspaces/{account}/logo` | `accounts.manage` (multipart upload, public disk, replaces previous) |
| DELETE | `/api/workspaces/{account}/logo` | `accounts.manage` |

#### Workspace branding & branded login

Every workspace stores a `settings` profile:

```json
{
  "logo_path": "workspaces/7/logo.png",          // set by the logo endpoint
  "organization": { "name": "…", "contact_person": "…", "phone": "…",
                    "email": "…", "address": "…", "website": "…" },
  "field": { "project_name": "…", "region": "…", "notes": "…" }
}
```

- **Public profile** — `GET /api/workspaces/{slug}/public-profile` (no auth) returns only presentation data (name, logo URL, organization, field) and powers the **workspace-branded login page**: the SPA route `/login/{slug}` shows the organization's logo and name and sends `account_id` with the login, so members land **straight in that workspace's dashboard** for field work. Non-members get a clear 403 message.
- **Per-organization app builds** — set `VITE_WORKSPACE_SLUG` when building the SPA/Capacitor app for one organization; `/login` then always lands on that organization's branded page.
- The workspace logo also appears in the app header, workspace switcher, and the form-fill banner (which shows the organization's field instructions to enumerators).
- Login responses include `logo_url` on `active_account` and each account entry.

#### CSV import

`POST /api/workspaces/{account}/imports/submissions` (multipart: `form_id`,
`csv`, max 5 MB) imports rows into a form of the workspace:

- The header row maps to form fields by **field name or label** (case-
  insensitive); unmatched columns are ignored.
- Reserved columns: `local_uuid` (idempotency key) and `created_at` (any
  date Carbon parses). When `local_uuid` is absent, a deterministic
  UUID-shaped key is derived from form + row content — **re-importing the
  identical file creates no copies**.
- Rows are written through the same `SubmissionWriter` service as the sync
  path (receipt + `UNIQUE (account_id, local_uuid)`), so exactly-once
  semantics hold for imports too.
- Per-row validation: required fields, numeric fields, select options.
  Response: `data.attributes` = `{ imported, duplicates, errors, total_rows,
  column_mapping }` and `meta.error_rows` = up to 100 `{ row, error }`
  entries (row 1 is the header).
- Hard cap of 5,000 rows per upload.

In the UI, the Submissions page has an **Import CSV** panel: pick the target
form, download a ready-made template, drop in the file — a mapping preview
shows matched columns, missing required fields, and ignored columns before
you submit.

Safety rails: a workspace must always keep at least one active administrator
(a role granting `accounts.manage`) — the last one cannot be demoted,
suspended, removed, or stripped of the permission. All explicit
`/workspaces/{account}/…` routes additionally require the URL workspace to
equal the token's workspace (`EnsureWorkspaceMatches`), so you can only
administer a workspace you are a member of.

Permission strings (closed catalog in `config/tenancy.php`'s
`permission_catalog`): `forms.manage`, `submissions.read`,
`submissions.write`, `dashboard.read`, `exports.read`, `members.manage`,
`roles.manage`, `accounts.manage` — grouped into default roles (`Owner`,
`Manager`, `Enumerator`, `Viewer`) that are created per workspace.

## Frontend (web + mobile)

```bash
cd frontend
npm install
npm run dev          # http://localhost:5173, /api proxied to :8000
npm run build        # production bundle + PWA service worker
```

Set `VITE_API_URL` when the SPA is hosted away from the API origin (required
for the Capacitor build, which has no dev proxy). Set
`VITE_WORKSPACE_SLUG` (e.g. `agri-wa`) to build a per-organization app whose
login page is that workspace's branded page.

Demo workspace login: `http://localhost:5173/login/agri-wa`.

### Offline flow

1. `FormFillView` loads the form from the API, falling back to the IndexedDB
   template cache (`form_templates` store, keyed per workspace).
2. Submitting writes a `submissions` outbox record (`local_uuid`,
   `sync_status`, `attempts`, `next_retry_at`) plus compressed photo blobs to
   IndexedDB — the local database is the source of truth.
3. `useOfflineQueue().flush()` batches due rows → `POST /api/sync/submissions`.
   Success marks rows `synced` and uploads their photos; network failure
   schedules retries with exponential backoff (2 s → 15 min, jitter).
4. `useMobileSync()` adds connectivity triggers (`online`/`offline`),
   Capacitor GPS (with web fallback), and the native camera (with
   `<input capture>` fallback). The `SyncBadge` in the header reflects queue
   state in every view.

### Packaging the mobile app

```bash
cd frontend
npm run build
npx cap add android        # and/or: npx cap add ios
npx cap sync
npm run cap:android        # opens Android Studio
```

Plugins already in `package.json`: `@capacitor/core`, `camera`, `geolocation`,
`filesystem`, `network`. Optional hardening: `@capacitor-community/sqlite` if
you prefer SQLite over IndexedDB on-device, and a tile-caching service worker
for offline maps.

## Tests

```bash
php artisan test
```

`tests/Feature/MultiTenantSyncTest.php` covers login, idempotent sync (retry
after lost response → `duplicate`, single row), per-item error reporting,
cross-tenant form references, dashboard aggregation scoping, workspace
switching, and CSV export scoping.

## Production notes

- MySQL/PostgreSQL are supported; field aggregation uses driver-aware JSON
  extraction (`JSON_EXTRACT`/`JSON_UNQUOTE` on MySQL, `json_extract` on
  SQLite).
- Photos are stored on the `public` disk (`php artisan storage:link`). For
  sensitive field data, consider signed URLs or a CDN with auth.
- Export streams via `cursor()` + `streamDownload()` — memory stays flat
  regardless of workspace size.
- CORS is configured in `config/cors.php` (`CORS_ALLOWED_ORIGINS`).
- For Excel with formatting, swap the CSV writer for OpenSpout
  (`openspout/openspout`) — the streaming cursor pattern stays identical.
