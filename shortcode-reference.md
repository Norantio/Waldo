# Hugo Inventory — Shortcode Reference

Use these shortcodes inside Oxygen Builder's **Shortcode** element.

---

## [hugo_inv_lookup]

Search/scan bar — type or scan a barcode, asset tag, or serial number and get a detailed result card.

**Attributes:**

| Attribute     | Default                                      | Description             |
|---------------|----------------------------------------------|-------------------------|
| `placeholder` | `Scan or type barcode / asset tag / serial…` | Custom placeholder text |

**Example:**

```
[hugo_inv_lookup]
[hugo_inv_lookup placeholder="Enter asset tag..."]
```

---

## [hugo_inv_assets]

Filterable, sortable asset table with a live count and optional Add Asset button.

**Attributes:**

| Attribute          | Default    | Description                                                                         |
|--------------------|------------|-------------------------------------------------------------------------------------|
| `organization_id`  | *(all)*    | Filter to a specific organization by ID                                             |
| `status`           | *(all)*    | Pre-filter by status: `available`, `checked_out`, `in_repair`, `retired`, `lost`    |
| `category_id`      | *(all)*    | Filter to a specific category by ID                                                 |
| `per_page`         | `50`       | Number of assets to load                                                            |
| `show_filters`     | `yes`      | Show search box + status dropdown (`yes`/`no`)                                      |
| `show_add_button`  | `auto`     | Show Add Asset button — `auto` = admins only, `yes` = always, `no` = never         |
| `add_url`          | admin page | Override the URL the Add Asset button points to                                     |

**Toolbar:**
- Displays a live asset count that updates as filters are applied
- Add Asset button links to the admin add-asset page by default

**Sorting:**
- Click any column header to sort ascending ▲ or descending ▼
- Sortable columns: Asset Tag, Name, Organization, Location, Status

**Examples:**

```
[hugo_inv_assets]
[hugo_inv_assets organization_id="2" per_page="100"]
[hugo_inv_assets status="available" show_filters="no"]
[hugo_inv_assets show_add_button="no"]
[hugo_inv_assets show_add_button="yes" add_url="/submit-asset/"]
```

---

## [hugo_inv_checkout]

Tabbed checkout / check-in form. Users scan or type an asset identifier, then check it out or return it. **Requires login.**

**Attributes:** None.

**Example:**

```
[hugo_inv_checkout]
```

**How it works:**

- **Check Out tab** — Scan/type asset → preview card appears → set optional return date and notes → submit
- **Check In tab** — Scan/type asset → submit to return it
- Asset status updates automatically (`available` ↔ `checked_out`)
- Shows a login notice if the user is not authenticated

---

## [hugo_inv_stats]

Status summary cards showing asset counts (Total, Available, Checked Out, In Repair, Retired, Lost).

**Attributes:**

| Attribute         | Default | Description                              |
|-------------------|---------|------------------------------------------|
| `organization_id` | *(all)* | Filter counts to a specific organization |

**Examples:**

```
[hugo_inv_stats]
[hugo_inv_stats organization_id="3"]
```

---

## [hugo_inv_my_assets]

Table of the logged-in user's currently checked-out assets. **Requires login.**

**Attributes:** None.

**Example:**

```
[hugo_inv_my_assets]
```

---

## [hugo_inv_dashboard]

Full inventory dashboard combining stat cards, recent activity, overdue returns, assets by organization, and alerts. **Requires login.**

Each section can be toggled independently via attributes.

**Attributes:**

| Attribute          | Default | Description                                                                                   |
|--------------------|---------|-----------------------------------------------------------------------------------------------|
| `organization_id`  | *(all)* | Filter all sections to a specific organization                                                |
| `show_stats`       | `yes`   | Stat cards row (total + per-status counts) (`yes`/`no`)                                       |
| `show_activity`    | `yes`   | Recent checkout/check-in activity feed (`yes`/`no`)                                           |
| `show_overdue`     | `yes`   | Overdue returns panel — assets past their expected return date (`yes`/`no`)                   |
| `show_by_org`      | `yes`   | Horizontal bar chart of asset counts by organization (`yes`/`no`)                             |
| `show_alerts`      | `yes`   | Alerts panel — at-risk assets (lost/in repair) and expiring warranties (`yes`/`no`)           |
| `activity_limit`   | `10`    | Max rows in the activity feed (1–50)                                                          |
| `overdue_limit`    | `10`    | Max rows in the overdue table (1–100)                                                         |
| `alert_days`       | `30`    | Warranty expiry window in days — assets expiring within this many days appear in Alerts (1–365) |

**Layout:**

- Row 1: Stat cards (total + each status)
- Row 2: Recent Activity | Overdue Returns
- Row 3: Assets by Organization | Alerts

Collapses to a single column on screens ≤ 768 px.

**Examples:**

```
[hugo_inv_dashboard]
[hugo_inv_dashboard organization_id="2"]
[hugo_inv_dashboard show_by_org="no" show_alerts="no"]
[hugo_inv_dashboard activity_limit="20" alert_days="60"]
[hugo_inv_dashboard show_stats="yes" show_activity="yes" show_overdue="no" show_by_org="no" show_alerts="no"]
```

---

## Oxygen Builder Usage

1. Open the page in Oxygen → click **Add** (+)
2. Search for **Shortcode** in the elements panel
3. Drop it where you want the component
4. Paste the shortcode into the content field
5. Wrap in a Section or Div for spacing/styling control

> **Tip:** Do not use a Code Block. Use the Shortcode element so Oxygen processes it automatically.
