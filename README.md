# 🔍 HIMS ICD-11 Lookup

**Version:** 2.0.0  
**Author:** Dether / Zaheer S. Lagos — I.T. NGANI  
**License:** GPL-2.0+  
**Requires:** WordPress 5.8+ · PHP 7.4+ · Tested up to WP 6.7

---

A full-featured **WordPress plugin** that connects to the **WHO ICD-API v2**, bringing ICD-11 MMS (2025-01) medical coding directly into your WordPress site. Supports live search, chapter browsing, code info, postcoordination decoding, per-user search history, and CSV/JSON export — all via shortcodes.

---

## 📁 Project Structure

```
hims-icd11-lookup/
├── hims-icd11-lookup.php           # Main plugin entry point
├── readme.txt                      # WordPress plugin readme
├── includes/
│   ├── class-icd11-api.php         # WHO ICD-API v2 wrapper (OAuth2 + all endpoints)
│   ├── class-icd11-cache.php       # Transient-based cache layer
│   └── class-icd11-history.php     # Per-user search history (DB)
├── admin/
│   ├── class-icd11-admin.php       # Settings page & admin UI
│   ├── admin.css                   # Admin styles
│   └── admin.js                    # Admin interactions
└── public/
    ├── class-icd11-public.php      # Shortcodes & AJAX handlers
    ├── css/public.css              # Frontend styles
    └── js/public.js                # Frontend logic (search, browse, charts)
```

---

## ✨ Features

- **Live search with autocomplete** — powered by the WHO ICD-API in real time
- **Browse all 26 ICD-11 chapters** — drill down into the full classification tree
- **Code info & postcoordination decoder** — look up any ICD-11 code and decode extension codes
- **ICD-10 mapping** — retrieve ICD-10 equivalents for any entity
- **Per-user search history** — logged automatically, manageable per user
- **CSV & JSON export** — download search results or history
- **Transient-based caching** — reduces redundant API calls, configurable TTL
- **5 shortcodes** — flexible placement anywhere on your site
- **Settings page** — configure WHO API credentials, language, release, cache, and pagination

---

## 🔌 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[icd11_lookup]` | Full tool — search, browse, code info, history |
| `[icd11_search]` | Search bar only |
| `[icd11_code code="BA00"]` | Inline code badge for a specific code |
| `[icd11_chapters]` | Browse all 26 ICD-11 chapters |
| `[icd11_history]` | Current user's search history table |

---

## ⚙️ Installation

1. Upload the `hims-icd11-lookup` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins** in WordPress Admin
3. Go to **ICD-11 → Settings** and enter your WHO API credentials
4. Get free credentials at [https://icd.who.int/icdapi](https://icd.who.int/icdapi)
5. Place a shortcode on any page to start using it

---

## 🗄️ Database

The plugin creates one custom table on activation:

**`{prefix}_icd11_history`** — per-user search history
- `user_id`, `code`, `title`, `searched_at`
- Deduplicates automatically — re-searching a code updates its timestamp instead of creating a duplicate

---

## 🌐 WHO API Integration

The plugin communicates with two WHO endpoints:

| Endpoint | Purpose |
|----------|---------|
| `icdaccessmanagement.who.int` | OAuth2 token exchange (Client Credentials) |
| `id.who.int/icd` | ICD-11 API v2 — search, browse, entity info |

**Token handling:** OAuth2 access tokens are cached as WordPress transients and auto-refreshed before expiry.

### API Methods (`HIMS_ICD11_API`)

| Method | Description |
|--------|-------------|
| `get_token()` | Fetch & cache OAuth2 bearer token |
| `search()` | Full-text search across ICD-11 |
| `suggest()` | Autocomplete suggestions |
| `get_entity()` | Fetch a single ICD-11 entity by ID |
| `get_code_info()` | Decode a code string (incl. postcoordination) |
| `get_root()` | Fetch the ICD-11 classification root |
| `get_children()` | Get child entities of any node |
| `get_ancestors()` | Get ancestor chain of any entity |
| `get_foundation_entity()` | Fetch from the Foundation layer |
| `get_releases()` | List available ICD-11 releases |
| `get_icd10()` | Get ICD-10 mapping for an entity |
| `get_postcoordination_scale()` | Fetch postcoordination axis info |
| `test_connection()` | Validate credentials from the Settings page |

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 7.4+, WordPress Plugin API |
| External API | WHO ICD-API v2 (OAuth2 Client Credentials) |
| Caching | WordPress Transients API |
| Database | MySQL / MariaDB (custom history table) |
| AJAX | WordPress `wp_ajax_` hooks (logged-in & public) |
| Frontend | jQuery, custom CSS |

---

## 📋 Requirements

- WordPress **5.8** or higher
- PHP **7.4** or higher
- A free WHO ICD-API account — register at [https://icd.who.int/icdapi](https://icd.who.int/icdapi)

---

## 👤 Author

**Dether / Zaheer S. Lagos**  
I.T. NGANI  
GitHub: [itszaheerlgs](https://github.com/itszaheerlgs)
