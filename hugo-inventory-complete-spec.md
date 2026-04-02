# Hugo Inventory Management System — Complete Specification

**Version:** 0.1 (Draft)
**Date:** April 2, 2026
**Owner:** Hugo LLC

---

## Table of Contents

1. [Overview](#1-overview)
2. [Core Requirements](#2-core-requirements)
3. [Data Architecture](#3-data-architecture)
4. [WordPress Integration](#4-wordpress-integration)
5. [Barcode & QR Code System](#5-barcode--qr-code-system)
6. [Reporting & Export](#6-reporting--export)
7. [Technical Stack](#7-technical-stack)
8. [Hosting Environment — WP Engine Constraints](#8-hosting-environment--wp-engine-constraints)
9. [Phased Delivery](#9-phased-delivery)
10. [Phase 1 — Implementation Tasks](#10-phase-1--implementation-tasks)
11. [Resolved Decisions](#11-resolved-decisions)
12. [Open Questions](#12-open-questions)

---

## 1. Overview

A custom WordPress plugin providing a full-featured inventory and IT asset management system for Hugo LLC. The system supports barcode/QR scanning, check-in/check-out tracking, flexible asset types with custom fields, multi-organization asset ownership, role-based access, and comprehensive reporting.

Designed to manage ~5,000 IT assets at launch across multiple managed organizations, with 5 initial users, hosted on WP Engine.

---

## 2. Core Requirements

### 2.1 Asset Management

- **CRUD operations** on inventory items (create, read, update, delete/archive)
- **Flexible asset types** — IT assets as the primary use case, with the ability to define new entry types (e.g., furniture, vehicles, supplies) each with their own field schemas
- **Bulk operations** — import, export, update, and archive in batch

### 2.2 Standard Fields (Per Asset)

| Field | Type | Notes |
|---|---|---|
| Name / Description | text / textarea | Required |
| Serial Number | text | Unique, searchable |
| Asset Tag / ID | auto-generated | Internal unique identifier (e.g., `HUGO-000042`) |
| Barcode / QR Value | text | Linked to generated label; indexed for fast scan lookup |
| Location / Site | taxonomy | Multi-site support, optionally org-scoped |
| Organization | taxonomy | Which managed org this asset belongs to (required on add/scan) |
| Assigned User | WP user reference | Nullable (unassigned) |
| Purchase Date | date | — |
| Purchase Cost | currency | — |
| Warranty Expiration | date | Alert threshold configurable |
| Category / Type | taxonomy | Hierarchical |
| Status | enum | Available, Checked Out, In Repair, Retired, Lost |
| Custom Fields | dynamic | Per asset-type schema (see §3.3) |

### 2.3 Check-In / Check-Out

- Assign an asset to a WordPress user (or external contact record)
- Record check-out date, expected return date, and notes
- Record check-in date, condition notes
- Full history per asset (who had it, when, how long)
- Support scanning an asset barcode to initiate check-out/check-in flow
- Optional: require digital signature or acknowledgment on checkout

### 2.4 User Roles & Permissions

Leverage WordPress roles with custom capabilities:

| Role | Capabilities |
|---|---|
| Inventory Admin | Full CRUD, settings, types, reports, user management |
| Inventory Manager | CRUD, check-in/out, reports |
| Inventory User | View, self-service check-in/out of own assets |
| Auditor | Read-only, full reports and audit logs |

Users can be restricted to specific organizations via a user-to-org mapping table (Phase 2).

---

## 3. Data Architecture

### 3.1 Database Design

Custom tables using the `$wpdb` prefix. Not custom post types — purpose-built tables for performance at scale (thousands of records with relational queries).

**Primary Tables:**

- `{prefix}_inventory_assets` — core asset records (includes `organization_id` FK)
- `{prefix}_inventory_asset_meta` — EAV table for custom fields
- `{prefix}_inventory_organizations` — managed orgs/clients (id, name, slug, contact info, notes, active flag)
- `{prefix}_inventory_types` — asset type definitions
- `{prefix}_inventory_type_fields` — custom field schemas per type
- `{prefix}_inventory_locations` — location/site hierarchy (scoped to org)
- `{prefix}_inventory_categories` — category taxonomy
- `{prefix}_inventory_checkouts` — check-in/check-out transaction log
- `{prefix}_inventory_user_orgs` — user-to-organization access mapping (Phase 2)
- `{prefix}_inventory_audit_log` — all changes (who, what, when, old/new value)

### 3.2 Multi-Organization Model

Assets belong to a single organization. Organization is a **required field** when adding or scanning in a new asset.

- Organizations are a first-class entity with their own management page (add, edit, deactivate)
- All asset list views, reports, dashboards, and exports can be **filtered by organization**
- Locations can optionally be scoped to an organization (e.g., "Hugo Main Office" vs "Client X - Denver Site")
- Users can be granted access to all orgs or restricted to specific orgs via `inventory_user_orgs` mapping table (Phase 2)
- CSV import requires an org column for mapping
- Scan-to-add workflow: after scanning a barcode, the add-asset form pre-selects the currently active org filter (or prompts selection if none)

### 3.3 Custom Fields System

Each asset type can define its own set of additional fields:

- Supported field types: text, textarea, number, date, select/dropdown, checkbox, URL, file/attachment
- Field definitions stored in `inventory_type_fields`
- Field values stored in `inventory_asset_meta` (EAV pattern)
- Admin UI for managing field schemas per asset type (drag-and-drop ordering)

### 3.4 Indexing Strategy

- Composite indexes on frequently filtered columns: `(organization_id, status)`, `(organization_id, type_id)`, `(organization_id, location_id)`, `(organization_id, category_id)`
- Full-text index on `(name, description, serial_number)` for search
- Individual indexes on: `barcode_value`, `serial_number`, `asset_tag`, `assigned_user_id`
- Index on `organization_id` — nearly all queries will filter by org

### 3.5 Column Schemas

**`{prefix}_inventory_organizations`**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| name | VARCHAR(255) | Required |
| slug | VARCHAR(255) | Unique, URL-safe |
| contact_name | VARCHAR(255) | Nullable |
| contact_email | VARCHAR(255) | Nullable |
| notes | TEXT | Nullable |
| is_active | TINYINT(1) | Default 1 |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**`{prefix}_inventory_assets`**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| organization_id | BIGINT UNSIGNED | FK → organizations, required |
| asset_tag | VARCHAR(100) | Auto-generated, unique |
| name | VARCHAR(255) | Required |
| description | TEXT | Nullable |
| serial_number | VARCHAR(255) | Nullable |
| barcode_value | VARCHAR(255) | Nullable, indexed |
| type_id | BIGINT UNSIGNED | FK → types (default "IT Asset" for Phase 1) |
| category_id | BIGINT UNSIGNED | FK → categories, nullable |
| location_id | BIGINT UNSIGNED | FK → locations, nullable |
| assigned_user_id | BIGINT UNSIGNED | WP user ID, nullable |
| status | ENUM('available','checked_out','in_repair','retired','lost') | Default 'available' |
| purchase_date | DATE | Nullable |
| purchase_cost | DECIMAL(12,2) | Nullable |
| warranty_expiration | DATE | Nullable |
| created_by | BIGINT UNSIGNED | WP user ID |
| created_at | DATETIME | |
| updated_at | DATETIME | |

**`{prefix}_inventory_types`**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| name | VARCHAR(255) | |
| slug | VARCHAR(255) | Unique |
| description | TEXT | Nullable |
| is_default | TINYINT(1) | Only one row = 1 |
| created_at | DATETIME | |

**`{prefix}_inventory_categories`**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| name | VARCHAR(255) | |
| slug | VARCHAR(255) | Unique |
| parent_id | BIGINT UNSIGNED | Nullable, self-referencing FK |
| created_at | DATETIME | |

**`{prefix}_inventory_locations`**

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| name | VARCHAR(255) | |
| slug | VARCHAR(255) | Unique |
| organization_id | BIGINT UNSIGNED | Nullable (can be org-scoped or global) |
| parent_id | BIGINT UNSIGNED | Nullable, self-referencing FK |
| address | TEXT | Nullable |
| created_at | DATETIME | |

---

## 4. WordPress Integration

### 4.1 Admin Interface

> **Note:** Nick will design the admin UI separately. The plugin should provide clean data access layers, WordPress admin page registrations, and REST endpoints. The menu structure below defines the functional pages; visual design is handled outside this spec.

- Custom admin menu: top-level "Inventory" with sub-pages
  - Dashboard (overview/analytics)
  - All Assets (filterable/searchable list table)
  - Add New Asset
  - Organizations
  - Check-In / Check-Out
  - Asset Types & Custom Fields
  - Locations
  - Reports
  - Audit Log
  - Settings

### 4.2 WooCommerce Integration

- Optional module — only loads if WooCommerce is active
- Link inventory assets to WooCommerce products (track stock as saleable inventory)
- Sync stock counts between inventory system and WooCommerce
- Configurable: which asset types/categories sync to WooCommerce

### 4.3 User Integration

- Assets assigned to WordPress user accounts
- User profile tab showing currently checked-out assets
- Frontend self-service portal (shortcode or block) for users to view their assets

### 4.4 REST API

Full REST API under `/wp-json/hugo-inventory/v1/`:

**Core Endpoints:**

| Resource | Endpoints |
|---|---|
| Organizations | `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}` |
| Assets | `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}` |
| Assets (lookup) | `GET /assets/lookup?barcode={value}`, `GET /assets/lookup?serial={value}` |
| Categories | `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}` |
| Locations | `GET /`, `GET /{id}`, `GET /tree`, `POST /`, `PUT /{id}`, `DELETE /{id}` |
| Types | `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}` |
| Checkouts | `GET /`, `POST /checkout`, `POST /checkin` |
| Audit Log | `GET /` (filterable by user, date range, action type, asset) |
| Dashboard | `GET /stats`, `GET /recent`, `GET /warranty-alerts` |
| Labels | `GET /assets/{id}/qr`, `GET /assets/{id}/barcode`, `GET /labels?ids=...` |

- Authentication via WordPress application passwords or JWT
- All list endpoints support `?organization_id=` filter
- Assets list supports: `?status=`, `?category_id=`, `?location_id=`, `?type_id=`, `?search=`, `?assigned_user_id=`
- Pagination on all list endpoints (default 50 per page)

**Lookup Response Examples:**

Found:
```json
{
  "found": true,
  "asset": {
    "id": 42,
    "asset_tag": "HUGO-000042",
    "name": "Dell Latitude 5540",
    "organization": "Hugo LLC",
    "status": "available",
    "assigned_user": null,
    "location": "Hugo Main Office",
    "serial_number": "ABC123XYZ"
  }
}
```

Not found:
```json
{
  "found": false,
  "scanned_value": "ABC123XYZ",
  "create_url": "/wp-admin/admin.php?page=hugo-inventory-add&barcode=ABC123XYZ"
}
```

---

## 5. Barcode & QR Code System

### 5.1 Overview

The barcode system serves three functions:

1. **Input (Scanning)** — Read barcodes and QR codes to instantly look up or create assets. USB scanners act as keyboard devices; no drivers or special software required. A JavaScript listener on the admin pages detects scanner input by keystroke speed and triggers the appropriate action.

2. **Output (Generation)** — Produce QR codes and Code 128 barcodes for any asset in the system. Labels are generated server-side by pure PHP libraries (WP Engine compatible) and rendered into print-optimized HTML layouts for standard desktop printers.

3. **Workflow Integration** — Scanning is the primary interface for check-in/check-out operations. Scan an asset → the system checks its current status → presents the appropriate form (check-out if available, check-in if already assigned). Every action is logged to the audit trail.

The architecture extends to Bluetooth scanners (same keyboard-wedge approach, no code changes), camera-based scanning via browser APIs (future phase), and RFID tag reading (future phase, field already accommodated in the schema).

### 5.2 How USB Barcode Scanners Work

USB barcode scanners operate in **keyboard wedge mode** — the computer sees the scanner as a standard USB HID keyboard. When a barcode is scanned:

1. The scanner reads the barcode optically
2. It converts the encoded data into a string of characters
3. It "types" those characters as rapid keystroke events (USB HID keycodes)
4. It sends a terminator keystroke (typically Enter/Return)

From the browser's perspective, this is indistinguishable from a user typing very fast. The entire string arrives in the keyboard event buffer within 50–200ms depending on barcode length.

**No drivers, no browser extensions, no special APIs.** The scanner works anywhere a keyboard works. This is the key advantage of keyboard-wedge mode — the plugin only needs to listen for keyboard events in JavaScript.

### 5.3 Flow 1 — Scanning an Existing Asset (Lookup)

This is the primary day-to-day use case: scan a barcode on a piece of equipment and pull up its record.

**Sequence:**

1. **User scans barcode** — Scanner sends keystrokes to the browser
2. **JS listener captures input** — Detects rapid character arrival (< 50ms between keystrokes), identifies it as scanner input rather than human typing
3. **Barcode string extracted** — Listener concatenates all characters received before the Enter terminator
4. **REST API lookup fired** — `GET /wp-json/hugo-inventory/v1/assets/lookup?barcode={value}`
5. **Result handling:**
   - **Asset found** → Navigate to asset detail/edit page. Visual confirmation (green flash, optional beep).
   - **Asset not found** → Prompt: "Asset not found — create new?" → If yes, redirect to Add Asset form with barcode value pre-filled and org selector ready.

**Lookup Priority:**

When a scan comes in, the API searches in this order:
1. `barcode_value` — exact match (primary scan field)
2. `asset_tag` — exact match (fallback if barcode_value is the tag itself)
3. `serial_number` — exact match (catches manufacturer barcode scans)

This means if someone scans a manufacturer's existing barcode on a laptop (which encodes the serial number), the system will still find the asset without needing a custom Hugo label on it.

**JavaScript Listener — Technical Detail:**

The listener runs on all plugin admin pages where scanning is relevant (All Assets, Check-In/Check-Out, dedicated Scan page).

Detection logic:
- Maintain a keystroke buffer and a timestamp of the last keystroke
- On each `keydown` event:
  - If time since last keystroke > threshold (configurable, default 50ms), clear the buffer (new input sequence, probably human typing)
  - If time since last keystroke ≤ threshold, append character to buffer (rapid input, likely a scanner)
- On Enter key:
  - If buffer has ≥ 4 characters and the entire sequence arrived within the speed threshold → treat as scanner input
  - Fire the lookup API call
  - Clear the buffer
- The listener only activates on pages with a designated scan-ready class, and does not interfere with normal form inputs

### 5.4 Flow 2 — Generating Barcodes & QR Codes (Labels)

**QR Codes:**
- Generated server-side using a pure PHP library (e.g., `chillerlan/php-qrcode`)
- Output formats: PNG and SVG
- WP Engine compatible (no shell commands, no external binaries)

**Barcodes:**
- Generated server-side using `picqer/php-barcode-generator` (pure PHP)
- Default format: Code 128 (supports full ASCII, compact, widely scannable)
- Also supports Code 39, EAN-13, UPC-A if needed
- Output formats: PNG and SVG

**QR Payload (configurable in Settings):**
- **Asset tag only** — e.g., `HUGO-000042`. Simple, short, works with the plugin's internal lookup.
- **Full URL** — e.g., `https://yoursite.com/inventory/asset/HUGO-000042`. Scannable by any phone camera, opens the asset page directly in a browser. Useful for the future frontend self-service portal (Phase 4).

**Trigger Points:**

Single asset:
- Asset detail/edit page → "Generate QR Code" button and "Generate Barcode" button
- Produces a downloadable image or an inline preview with a print option

Bulk generation:
- Asset list table → Select multiple assets via checkboxes → Bulk action: "Print Labels"
- Generates a print-optimized HTML page containing a grid of labels

**Label Print Layout:**

Labels are rendered as a print-optimized HTML page designed for the browser's native print dialog. No dedicated label printer hardware required — standard desktop printer.

Each label contains: QR code or barcode image, asset tag, asset name, organization name.

Layout is configurable (Settings page): labels per row, label width and height (mm), which fields to include on each label, margin/padding.

Print workflow:
1. User selects assets and clicks "Print Labels"
2. Plugin generates HTML page with CSS `@media print` rules
3. Page opens in a new browser tab
4. Browser print dialog opens automatically (or user clicks print)
5. User selects their printer and prints

### 5.5 Flow 3 — Check-In / Check-Out via Scan (Phase 2)

The scan-to-action workflow for managing asset assignments.

**Sequence:**

1. User navigates to Check-In/Check-Out page (or scans from any scan-enabled page)
2. User scans asset barcode
3. API returns asset record including current status
4. Context-aware form appears:

**If status = "Available" → Check-Out Form:**
- Asset name, tag, and org displayed (read-only)
- Select user (WordPress user autocomplete search)
- Expected return date (date picker)
- Notes field
- Submit → status changes to "Checked Out", checkout record created, audit log entry written

**If status = "Checked Out" → Check-In Form:**
- Asset name, tag, and current assignee displayed (read-only)
- Condition notes (text field)
- Confirm check-in button
- Submit → status changes to "Available", checkout record closed, audit log entry written

**If status = "In Repair" / "Retired" / "Lost":**
- Asset detail displayed with current status
- Option to change status before proceeding

**Rapid Scanning Mode:**

For processing a batch of returns or assignments:
- After completing a check-in or check-out, the page resets to the scan-ready state
- User can immediately scan the next asset without navigating away
- Running tally of processed items displayed on-page
- Session summary available after the batch is complete

### 5.6 Supported Barcode Formats

| Format | Type | Use Case | Scanner Support |
|---|---|---|---|
| QR Code | 2D matrix | Primary — high data density, phone-scannable | All 2D scanners |
| Code 128 | 1D linear | Secondary — compact, full ASCII | All 1D/2D scanners |
| Code 39 | 1D linear | Legacy compatibility | All 1D/2D scanners |
| UPC-A / EAN-13 | 1D linear | Retail items if tracked | All 1D/2D scanners |

**Recommendation:** Use QR codes as the default. They hold more data (full URL if desired), are scannable by phone cameras, and are resilient to partial damage. Code 128 is available as a fallback for environments that prefer traditional linear barcodes.

### 5.7 Scanner Hardware Recommendations

Any USB barcode scanner that supports keyboard wedge (HID) mode will work. No specific brand or model is required.

**For desk/station use:**
- Any USB 2D barcode scanner ($30–80 range)
- Should support QR Code + Code 128 at minimum
- Hands-free stand is convenient for batch processing

**For mobile use (future Bluetooth phase):**
- Bluetooth 2D scanners pair with laptops/tablets and use the same keyboard wedge protocol
- No code changes needed — the JS listener works identically with Bluetooth HID input

**For phone/tablet scanning (future camera phase):**
- Browser-based using `getUserMedia` API + a JS decoding library (e.g., `html5-qrcode` or `quagga2`)
- Requires HTTPS (camera access mandates secure context)
- Will be implemented as an alternative scan input source that feeds the same lookup API

### 5.8 Barcode-Related Settings

| Setting | Default | Description |
|---|---|---|
| Scanner keystroke threshold | 50ms | Max time between keystrokes to qualify as scanner input |
| Minimum barcode length | 4 characters | Minimum characters to treat as a valid scan |
| QR payload format | Asset tag only | What data is encoded in generated QR codes (tag or full URL) |
| Default barcode format | QR Code | QR Code or Code 128 for new label generation |
| Label width | 50mm | Width of each printed label |
| Label height | 25mm | Height of each printed label |
| Labels per row | 4 | Number of labels per row in print layout |
| Label fields | Tag, Name, Org | Which fields appear on printed labels |
| Audio feedback | On | Beep on successful scan |
| Visual feedback | Green flash | CSS animation on successful scan |

---

## 6. Reporting & Export

### 6.1 Dashboard

- Total asset count by status, type, location
- Assets due for warranty expiration (upcoming 30/60/90 days)
- Recent check-in/check-out activity
- Assets overdue for return
- Value summary (total cost, depreciation if configured)
- All widgets filterable by organization

### 6.2 CSV Export

- Export all assets or filtered subsets
- Configurable columns
- Includes custom field data
- Export checkout history

### 6.3 PDF Reports

- Asset detail sheet (single asset, full history)
- Inventory summary report (by location, type, status)
- Checkout report (current assignments, overdue items)
- Audit trail report (date-range filtered)

### 6.4 Audit Log

- Every create, update, delete, check-in, check-out logged
- Captures: user, timestamp, action, asset ID, field changed, old value, new value
- Filterable by user, date range, action type, asset
- Retention policy configurable (auto-purge after N months, or keep indefinitely)

---

## 7. Technical Stack

| Layer | Technology |
|---|---|
| Platform | WordPress 6.x+ |
| Language | PHP 8.1+ |
| Database | MySQL 8.0+ / MariaDB 10.6+ (via `$wpdb`) |
| Frontend (admin) | Custom UI (designed separately by Nick); plugin provides page shells + REST API |
| Barcode generation | `picqer/php-barcode-generator` (Code 128) + `chillerlan/php-qrcode` (QR) — both pure PHP |
| Barcode scanning | JavaScript keyboard-wedge listener; future: `quagga2` or `html5-qrcode` |
| PDF generation | TCPDF or DOMPDF (pure PHP, no external binaries) |
| REST API | WordPress REST API framework |
| Build tooling | `@wordpress/scripts` (webpack under the hood) |

---

## 8. Hosting Environment — WP Engine Constraints

The target site is hosted on **WP Engine**, which imposes specific restrictions the plugin must respect:

- **No `exec()`, `shell_exec()`, `proc_open()`, `popen()`** — all processing must be pure PHP; no shelling out to system commands (affects PDF generation library choice — TCPDF preferred over wkhtmltopdf)
- **No direct MySQL access** — all queries must go through `$wpdb`; no raw `mysqli` connections
- **Aggressive page caching** — REST API endpoints and admin-ajax calls are typically excluded, but any frontend-facing inventory pages may need cache-busting headers or query string strategies
- **Object caching** — WP Engine provides object caching (Memcached/Redis); leverage `wp_cache_*` functions for frequently queried data (asset counts, location lists, category trees)
- **File system** — `/wp-content/uploads/` is writable; use this for generated labels, export files, and temp files; no writing outside the uploads directory
- **Cron** — `wp_cron` is available but not guaranteed to fire on exact schedule; for time-sensitive jobs (warranty alerts, overdue notifications), keep logic in cron callbacks but don't depend on sub-minute precision
- **PHP version** — WP Engine supports PHP 8.1+; confirmed compatible with spec requirements
- **Database** — MySQL 8.0 via WP Engine's managed infrastructure

**Impact on Technical Decisions:**

- PDF generation: use **TCPDF** or **DOMPDF** (pure PHP, no external binaries)
- Barcode generation: use **pure PHP libraries** (both selected libraries are confirmed WP Engine safe)
- Label printing: generate PDF or HTML print layouts for **standard desktop printer** via browser print dialog
- File exports: write to `wp-content/uploads/hugo-inventory/exports/` with nonce-protected download URLs and automatic cleanup via scheduled cron

---

## 9. Phased Delivery

### Phase 1 — Foundation (MVP)

- Plugin scaffolding, DB schema, activation/deactivation hooks
- Organization CRUD (add, edit, deactivate orgs)
- Asset CRUD (admin list table + add/edit forms)
- Standard fields, single default asset type ("IT Asset")
- Org selector required on asset creation (including scan-to-add flow)
- Location and category taxonomies
- Basic search and filtering (with org filter)
- USB barcode/QR scanning (keyboard-wedge listener)
- QR code generation per asset
- Basic dashboard widget (counts by status, filterable by org)

### Phase 2 — Check-In/Check-Out & Roles

- Full check-in/check-out workflow
- Checkout history per asset and per user
- Custom roles and capabilities
- Org-scoped user permissions (restrict users to specific organizations via user-org mapping)
- User profile integration (my assets tab)
- Audit log (all mutations)

### Phase 3 — Custom Types, Fields & Reporting

- Custom asset types with per-type field schemas
- Admin UI for managing types and fields
- CSV export (configurable)
- PDF report generation
- Dashboard analytics (charts, warranty alerts, overdue tracking)

### Phase 4 — Integrations & Advanced Features

- WooCommerce sync module
- REST API hardening and documentation
- Bulk import/export (CSV ingest; SolarWinds migration mapping if confirmed)
- Label printing (browser print dialog — standard printer layouts)
- Frontend self-service portal (shortcode/block)

### Phase 5 — Future

- Camera-based barcode scanning (mobile browser)
- Bluetooth scanner optimizations
- RFID tag field + reader integration
- Depreciation tracking
- Multi-site (WordPress Multisite) support
- Notification system (Slack, email) for warranty/overdue alerts

**Barcode Feature Delivery by Phase:**

| Feature | Phase |
|---|---|
| USB scanner keyboard-wedge listener | 1 |
| QR code generation (per asset) | 1 |
| Barcode generation (Code 128) | 1 |
| Label print layout (browser print dialog) | 1 |
| Bulk label generation | 1 |
| Scan-to-lookup (found → detail page) | 1 |
| Scan-to-create (not found → add form) | 1 |
| Scan-to-check-out / check-in | 2 |
| Rapid scanning mode (batch processing) | 2 |
| Bluetooth scanner support | 2 (no code changes expected) |
| Camera-based scanning (mobile browser) | 5 |
| RFID tag field + reader integration | 5 |

---

## 10. Phase 1 — Implementation Tasks

**Target:** A working inventory system with org management, asset CRUD, barcode scanning, QR generation, and a basic dashboard.

**Total: 20 tasks across 7 milestones.**

---

### Milestone 1: Plugin Scaffolding & Database

> Get the plugin loading, tables created, and the foundation wired up.

#### Task 1.1 — Plugin Bootstrap

- Create plugin directory structure:
  ```
  hugo-inventory/
  ├── hugo-inventory.php          (main plugin file)
  ├── uninstall.php               (clean uninstall handler)
  ├── includes/
  │   ├── class-plugin.php        (singleton, init hooks)
  │   ├── class-activator.php     (activation logic)
  │   ├── class-deactivator.php   (deactivation logic)
  │   └── class-loader.php        (hook/filter registration)
  ├── admin/
  │   ├── class-admin.php         (admin menu, page registration)
  │   ├── css/
  │   └── js/
  ├── includes/db/
  │   └── class-schema.php        (table creation/migration)
  ├── includes/models/
  ├── includes/api/
  └── languages/
  ```
- Register activation/deactivation hooks
- Version constant, DB version tracking in `wp_options` for future migrations
- Autoloader or manual `require` map

**Depends on:** Nothing
**Deliverable:** Plugin activates/deactivates cleanly with no errors

---

#### Task 1.2 — Database Schema Creation

Create all Phase 1 tables on activation via `dbDelta()`. Column schemas defined in §3.5.

- Seed default type "IT Asset" on activation
- Add composite indexes: `(organization_id, status)`, `(organization_id, type_id)`, `(organization_id, location_id)`, `(organization_id, category_id)`
- Add individual indexes: `barcode_value`, `serial_number`, `asset_tag`, `assigned_user_id`
- Add FULLTEXT index on `(name, description, serial_number)`

**Depends on:** Task 1.1
**Deliverable:** All tables created on activation, dropped cleanly on uninstall

---

#### Task 1.3 — DB Migration System

- Store current DB schema version in `wp_options` (`hugo_inventory_db_version`)
- On plugin load, compare stored version vs code version
- If mismatch, run sequential migration callbacks (e.g., `migration_1_0_1()`, `migration_1_0_2()`)
- Log migration results

**Depends on:** Task 1.2
**Deliverable:** Future schema changes can be applied automatically on plugin update

---

### Milestone 2: Organization Management

> Orgs are required before any assets can be created.

#### Task 2.1 — Organization Model

- Create `includes/models/class-organization.php`
- Methods: `create()`, `get()`, `get_all()`, `update()`, `deactivate()`, `reactivate()`, `get_by_slug()`
- Input validation and sanitization
- Return structured objects (not raw DB rows)

**Depends on:** Task 1.2
**Deliverable:** Programmatic CRUD for organizations

---

#### Task 2.2 — Organization Admin Pages

- Register admin menu page: Inventory → Organizations
- List table (extending `WP_List_Table`): name, contact, asset count, status, actions
- Add/Edit form: name, contact name, contact email, notes, active toggle
- Deactivate action (soft delete — don't remove, just flag inactive)
- Inline validation (unique name/slug)

**Depends on:** Task 2.1
**Deliverable:** Full org management in WP admin

---

#### Task 2.3 — Organization REST API

- Register routes under `/wp-json/hugo-inventory/v1/organizations`
- Endpoints: `GET /`, `GET /{id}`, `POST /`, `PUT /{id}`, `DELETE /{id}` (soft delete)
- Permission checks (Inventory Admin only for CUD, authenticated for R)
- JSON schema validation

**Depends on:** Task 2.1
**Deliverable:** Orgs fully manageable via REST API

---

### Milestone 3: Taxonomy Management (Categories & Locations)

#### Task 3.1 — Category & Location Models

- Create `class-category.php` and `class-location.php` models
- Both support hierarchical parent/child relationships
- Locations support optional `organization_id` scoping
- Methods: `create()`, `get()`, `get_tree()` (nested), `update()`, `delete()` (prevent if assets assigned)

**Depends on:** Task 1.2
**Deliverable:** Programmatic CRUD for categories and locations

---

#### Task 3.2 — Category & Location Admin Pages

- Register admin menu pages: Inventory → Locations, and a Categories sub-section
- List tables showing hierarchy (indented children)
- Add/Edit forms with parent selector dropdown
- Locations form includes optional org scoping dropdown
- Delete protection: warn if assets are assigned to this category/location

**Depends on:** Task 3.1
**Deliverable:** Category and location management in WP admin

---

#### Task 3.3 — Category & Location REST API

- Register routes for both under `/wp-json/hugo-inventory/v1/categories` and `/locations`
- Support `?organization_id=` filter on locations
- Tree endpoint: `GET /locations/tree` returns nested structure

**Depends on:** Task 3.1
**Deliverable:** Taxonomies available via REST API

---

### Milestone 4: Asset CRUD

> The core of the system — creating, viewing, editing, and listing assets.

#### Task 4.1 — Asset Model

- Create `includes/models/class-asset.php`
- Auto-generate `asset_tag` on creation (format configurable, e.g., `HUGO-000001`)
- Methods: `create()`, `get()`, `update()`, `archive()`, `get_by_barcode()`, `get_by_serial()`, `get_by_asset_tag()`
- Validate required fields: name, organization_id
- Validate FKs exist (org, category, location, type)
- Handle status transitions with validation rules

**Depends on:** Tasks 1.2, 2.1, 3.1
**Deliverable:** Full programmatic asset management

---

#### Task 4.2 — Asset List Table (Admin)

- Register admin page: Inventory → All Assets
- Extend `WP_List_Table` with:
  - Columns: asset tag, name, org, serial, location, category, status, assigned user, actions
  - Sortable columns: name, asset tag, status, purchase date, org
  - Bulk actions: change status, change org, delete/archive
  - **Filters (dropdowns above table):** organization, status, category, location, type
  - Per-page pagination (default 50, configurable)
- Row actions: Edit, View, Quick Status Change, Generate QR

**Depends on:** Task 4.1
**Deliverable:** Browsable, filterable, sortable asset list

---

#### Task 4.3 — Asset Add/Edit Form (Admin)

- Register admin page: Inventory → Add New Asset
- Form fields matching §2.2 standard fields
- **Organization selector** — required, pre-selects last-used org (stored in user meta)
- Category and location dropdowns (location list filters by selected org if org-scoped)
- Status dropdown
- Assigned user — WP user search/select (autocomplete)
- Purchase date picker, cost field, warranty date picker
- Validation and save handler
- Success redirect to asset list or "Add Another" action
- Edit page reuses the same form, pre-populated

**Depends on:** Tasks 4.1, 2.2, 3.2
**Deliverable:** Create and edit assets through WP admin forms

---

#### Task 4.4 — Asset REST API

- Register routes under `/wp-json/hugo-inventory/v1/assets`
- `GET /` — paginated list with filters: `organization_id`, `status`, `category_id`, `location_id`, `type_id`, `search`, `assigned_user_id`
- `GET /{id}` — single asset with related data (org name, location name, etc.)
- `POST /` — create with validation
- `PUT /{id}` — update
- `DELETE /{id}` — archive (soft delete)
- `GET /lookup?barcode={value}` — fast lookup by barcode/QR value
- `GET /lookup?serial={value}` — lookup by serial number

**Depends on:** Task 4.1
**Deliverable:** Full asset API for custom frontend consumption

---

#### Task 4.5 — Asset Search

- Implement search handler using FULLTEXT index on `(name, description, serial_number)`
- Fallback to `LIKE` for short queries (< 3 chars)
- Search endpoint in REST API: `GET /assets?search=query`
- Admin list table search box wired to the same logic
- Results ranked by relevance

**Depends on:** Task 4.4
**Deliverable:** Fast, relevant asset search across name, description, and serial

---

### Milestone 5: Barcode & QR Code

#### Task 5.1 — QR Code Generation

- Install PHP library via Composer (e.g., `chillerlan/php-qrcode` — pure PHP, WP Engine safe)
- Generate QR code containing asset tag ID (or configurable payload: asset tag + URL to asset detail page)
- Output as PNG and SVG
- Per-asset "Generate QR" action on list table and edit page
- Store generated QR as WP attachment linked to asset (or generate on-the-fly)

**Depends on:** Task 4.1
**Deliverable:** Every asset can have a QR code generated

---

#### Task 5.2 — Barcode Generation

- Install `picqer/php-barcode-generator` (pure PHP)
- Generate Code 128 barcode from asset tag or barcode_value field
- Output as PNG and SVG
- Same UI hooks as QR — available per asset

**Depends on:** Task 4.1
**Deliverable:** Every asset can have a barcode generated

---

#### Task 5.3 — Label Print Layout

- Generate a print-optimized HTML page with configurable grid of labels
- Each label contains: QR code (or barcode), asset tag, asset name, org name
- Configurable: labels per row, label dimensions, which fields to include
- Bulk action on asset list: select assets → "Print Labels"
- Opens print-friendly page in new tab → browser print dialog
- Support single-asset label print from asset detail/edit

**Depends on:** Tasks 5.1, 5.2
**Deliverable:** Printable label sheets from any standard printer

---

#### Task 5.4 — USB Barcode Scanner Input (Keyboard Wedge Listener)

- JavaScript module that detects rapid sequential keystrokes (< 50ms between characters) ending with Enter
- Distinguishes scanner input from normal typing by speed threshold
- Active on designated scan input fields (class-based targeting)
- On scan detection:
  1. Capture the full barcode string
  2. Hit `GET /assets/lookup?barcode={value}`
  3. If found → navigate to asset detail/edit page
  4. If not found → prompt "Asset not found — create new?" → pre-fill barcode value in add-asset form
- Configurable scan field on the asset list page and a dedicated "Scan" page
- Visual/audio feedback on successful scan (CSS flash, optional beep)

**Depends on:** Task 4.4
**Deliverable:** Plug in a USB scanner, scan a barcode, instantly pull up or create the asset

---

### Milestone 6: Dashboard

#### Task 6.1 — Dashboard Page

- Register admin page: Inventory → Dashboard (default landing page)
- Widgets/cards:
  - **Total assets** by status (available, checked out, in repair, retired, lost) — as count cards
  - **Assets by organization** — bar or table
  - **Assets by category** — bar or table
  - **Recently added** — last 10 assets
  - **Warranty expiring soon** — assets with warranty_expiration within 30/60/90 days
- **Organization filter** at top of dashboard — filters all widgets
- Data loaded via REST API calls (supports Nick's custom UI work later)

**Depends on:** Tasks 4.4, 2.3
**Deliverable:** At-a-glance overview of inventory status

---

#### Task 6.2 — Dashboard REST Endpoints

- `GET /dashboard/stats?organization_id=` — returns counts by status, type, category
- `GET /dashboard/recent?organization_id=&limit=10` — recently added assets
- `GET /dashboard/warranty-alerts?days=30&organization_id=` — upcoming warranty expirations
- All endpoints respect org filter

**Depends on:** Task 4.4
**Deliverable:** Dashboard data available for custom frontend consumption

---

### Milestone 7: Settings & Polish

#### Task 7.1 — Settings Page

- Register admin page: Inventory → Settings
- Settings:
  - Asset tag format/prefix (e.g., `HUGO-######`, `INV-######`)
  - Auto-increment start number
  - Default organization (pre-select on add form)
  - Scanner sensitivity (keystroke speed threshold in ms)
  - QR code payload format (asset tag only, or full URL)
  - Label layout defaults (labels per row, dimensions)
  - Items per page in list tables
- Store in `wp_options` under a single serialized key (`hugo_inventory_settings`)

**Depends on:** All previous milestones
**Deliverable:** Configurable plugin behavior

---

#### Task 7.2 — Admin Enqueue & Asset Loading

- Conditionally load CSS/JS only on plugin admin pages
- Enqueue scanner listener JS on relevant pages
- Minified production builds vs debug builds based on `SCRIPT_DEBUG`

**Depends on:** All previous milestones
**Deliverable:** Clean, performant asset loading

---

#### Task 7.3 — Error Handling & Notices

- Standardized admin notice system (success, warning, error)
- Form validation error display (inline field errors + top summary)
- REST API error responses follow WordPress conventions (`WP_Error` → JSON)
- PHP error logging to `debug.log` with plugin-specific prefix

**Depends on:** All previous milestones
**Deliverable:** Consistent error handling across admin and API

---

### Phase 1 Task Summary

| Milestone | Tasks | Description |
|---|---|---|
| 1. Scaffolding & DB | 1.1 – 1.3 | Plugin bootstrap, schema, migrations |
| 2. Organizations | 2.1 – 2.3 | Org model, admin pages, REST API |
| 3. Taxonomies | 3.1 – 3.3 | Categories, locations, admin, REST API |
| 4. Asset CRUD | 4.1 – 4.5 | Asset model, list table, forms, API, search |
| 5. Barcode & QR | 5.1 – 5.4 | QR/barcode generation, label printing, scanner input |
| 6. Dashboard | 6.1 – 6.2 | Overview page, stats endpoints |
| 7. Settings & Polish | 7.1 – 7.3 | Config page, asset loading, error handling |

### Suggested Build Order

The milestones are already sequenced by dependency. Within each milestone, tasks are numbered in suggested order. The critical path is:

```
1.1 → 1.2 → 1.3 → 2.1 → 2.2 → 3.1 → 3.2 → 4.1 → 4.2 → 4.3 → 5.4 → 6.1 → 7.1
```

REST API tasks (2.3, 3.3, 4.4, 6.2) can be built in parallel with their corresponding admin pages, or immediately after — they share the same model layer.

QR/barcode generation (5.1, 5.2, 5.3) can happen anytime after 4.1 and are independent of the scanner work (5.4).

---

## 11. Resolved Decisions

| Question | Decision |
|---|---|
| Hosting environment | WP Engine (managed WordPress) |
| Data migration | Potentially from **SolarWinds** via **CSV export**; design CSV import with field mapping to accommodate |
| Label printing | Standard desktop printer via browser print dialog; generate print-optimized PDF/HTML layouts |
| Initial user count | **5 users** at launch |
| Initial asset volume | **~5,000 assets** to start (DB tuning and pagination designed accordingly) |
| Admin UI design | Nick will design the UI separately; plugin should expose clean data layers, hooks, and REST endpoints to support custom frontend work |
| Multi-organization | Yes — org is a **required field** on every asset, selectable during add/scan workflow. See §3.2 for full multi-org model |
| Org-scoped user permissions | **Yes** — users can be restricted to specific orgs (Phase 2). Requires a user-to-org mapping table |
| Backup/DR | Standard WP Engine backups sufficient; no additional requirements |

---

## 12. Open Questions

All architectural questions resolved. One pending deliverable:

- [ ] SolarWinds CSV sample export — Nick will provide when available; needed to build the column mapper for import (Phase 4)

---

*This is a living document. Sections will be expanded as decisions are made on open questions.*
