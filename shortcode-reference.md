# Hugo Inventory — Shortcode Reference

Use these shortcodes inside Oxygen Builder's **Shortcode** element.

---

## [hugo_inv_lookup]

Search/scan bar — type or scan a barcode, asset tag, or serial number and get a detailed result card.

**Attributes:**

| Attribute     | Default                                          | Description                  |
|---------------|--------------------------------------------------|------------------------------|
| `placeholder` | `Scan or type barcode / asset tag / serial…`     | Custom placeholder text      |

**Example:**

```
[hugo_inv_lookup]
[hugo_inv_lookup placeholder="Enter asset tag..."]
```

---

## [hugo_inv_assets]

Filterable asset table with search box and status dropdown.

**Attributes:**

| Attribute         | Default | Description                              |
|-------------------|---------|------------------------------------------|
| `organization_id` | *(all)* | Filter to a specific organization by ID  |
| `status`          | *(all)* | Filter to a status: `available`, `checked_out`, `in_repair`, `retired`, `lost` |
| `category_id`     | *(all)* | Filter to a specific category by ID      |
| `per_page`        | `50`    | Number of assets to display              |
| `show_filters`    | `yes`   | Show search/status filter bar (`yes`/`no`) |

**Examples:**

```
[hugo_inv_assets]
[hugo_inv_assets organization_id="2" per_page="100"]
[hugo_inv_assets status="available" show_filters="no"]
```

---

## [hugo_inv_checkout]

Tabbed checkout / check-in form. Users can scan or type an asset identifier, then check it out to themselves or return it. **Requires login.**

**Attributes:** None.

**Example:**

```
[hugo_inv_checkout]
```

**How it works:**

- **Check Out tab** — Scan/type asset → preview card appears → set optional return date and notes → submit
- **Check In tab** — Scan/type asset → submit to return it
- Asset status updates automatically (`available` ↔ `checked_out`)
- Shows login notice if the user is not authenticated

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

Shows the logged-in user's currently checked-out assets in a table. **Requires login.**

**Attributes:** None.

**Example:**

```
[hugo_inv_my_assets]
```

---

## Oxygen Builder Usage

1. Open the page in Oxygen → click **Add** (+)
2. Search for **Shortcode** in the elements panel
3. Drop it where you want the component
4. Paste the shortcode into the content field
5. Wrap in a Section or Div for spacing/styling control

> **Tip:** Do not use a Code Block. Use the Shortcode element so Oxygen processes it automatically.
