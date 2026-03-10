# March 9 — Improvement Plan

## 1. Security Insights: Runbooks & Explanations for Diagnosis Sections

**Problem:** The Deep Dive route diagnosis shows "Why bypassed (Edge/Batcache)", "Poisoning cookies", "Poisoning headers", and "Route risk badges" as raw chip labels with evidence, but there are no runbooks, tooltips, or contextual explanations telling users what these mean or what to do about them.

**Goal:** Add inline insight panels beneath each diagnosis section that explain what the data means and what action to take when values are present.

### Changes

**File: `admin/public/js/deep-dive.js` (lines 277-290)**

For each of the 5 diagnosis sections, add a collapsible insight/runbook block that appears when the section has values (not "None"). Each insight block should:

- Appear directly below the chips, inside the same `pcm-diagnosis-section` div
- Use a subtle info-panel style (light background, left border accent) — class: `pcm-insight-panel`
- Contain a short "What this means" paragraph and a "What to do" action list
- Only render when there are actual values (skip if chips return `<em>None</em>`)

#### Insight Content

**Why bypassed (Edge):**
- **What this means:** These response headers are preventing Pressable's edge CDN from caching this route. Every request hits your origin server, increasing load and latency.
- **What to do:**
  - `cache_control_non_public` — A plugin or theme is sending `Cache-Control: private` or `no-store`. Check for caching plugins (WP Super Cache, W3TC) or security plugins overriding headers on public pages. Remove or adjust the header for publicly-visible content.
  - `vary_cookie` — The `Vary: Cookie` header tells the edge every unique cookie set creates a new cache variant, effectively disabling caching. Find the plugin adding this header (often WooCommerce, membership plugins) and restrict it to authenticated pages only.

**Why bypassed (Batcache):**
- **What this means:** Batcache (WordPress page cache) is skipping this route. The page is regenerated from PHP/MySQL on every anonymous hit.
- **What to do:**
  - `vary_cookie` — Same as edge: a `Vary: Cookie` header is present. Batcache cannot serve cached pages when responses vary by cookie.
  - `set_cookie_present` — A `Set-Cookie` header is being sent on an anonymous (logged-out) request. Batcache treats any response that sets cookies as uncacheable. Common sources: analytics plugins, consent banners, or session starters. Move cookie-setting to client-side JavaScript or restrict to authenticated users.

**Poisoning cookies:**
- **What this means:** These cookies are being set on anonymous (logged-out) responses. Each unique cookie value can fragment the cache, creating "cache poisoning" — where the CDN stores many near-identical variants, lowering hit rates and wasting memory.
- **What to do:** Identify the plugin or theme setting each cookie. If the cookie is not essential for server-side logic (e.g., analytics tracking, A/B test bucketing), move it to client-side JavaScript (`document.cookie`). If it must be server-side, ensure it's only set on authenticated/POST requests.

**Poisoning headers:**
- **What this means:** These response headers create unnecessary cache variation or explicitly block caching. They fragment the edge cache or prevent it entirely.
- **What to do:**
  - `vary` — Review what values the Vary header includes. `Vary: Cookie` on public pages is almost always wrong. `Vary: Accept-Encoding` is fine.
  - `cache-control` — Look for `no-store`, `private`, or `max-age=0` on pages that should be public.
  - `pragma` — Legacy header; `Pragma: no-cache` should be removed from public responses.
  - `x-forwarded-host` / `x-forwarded-proto` — These in Vary can cause origin-level cache fragmentation. Usually set by reverse proxies or misconfigured plugins.

**Route risk badges:**
- **What this means:** These badges flag routes that may cause performance or reliability problems.
- **What to do:**
  - `fragile` — Cacheability score is below 60, or bypass indicators were detected. This route is vulnerable to traffic spikes. Review bypass reasons above and fix them.
  - `expensive` — Response time exceeded 1.2 seconds. The origin is slow for this route. Consider query optimization, reducing plugin overhead, or enabling object caching for heavy database queries.
  - `cold` — The `x-cache` header indicates a cache miss. The route was served from origin, not cache. This is normal for the first hit after a purge, but if it persists, caching may be broken for this route.

### CSS Addition (`public/css/style.css`)

Add `.pcm-insight-panel` styles:
```css
.pcm-wrap .pcm-insight-panel {
    margin: 8px 0 4px;
    padding: 10px 14px;
    background: #f8fafc;
    border-left: 3px solid #6366f1;
    border-radius: 4px;
    font-size: 13px;
    line-height: 1.5;
    color: #374151;
}
.pcm-wrap .pcm-insight-panel strong {
    display: block;
    margin-bottom: 4px;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #6366f1;
}
.pcm-wrap .pcm-insight-panel ul {
    margin: 4px 0 0 16px;
    padding: 0;
    list-style: disc;
}
.pcm-wrap .pcm-insight-panel li {
    margin-bottom: 4px;
}
.pcm-wrap .pcm-insight-panel code {
    background: #eef2ff;
    padding: 1px 5px;
    border-radius: 3px;
    font-size: 12px;
}
```

---

## 2. Replace "PHP OPcache Awareness" with "Cache Insights"

**Problem:** The current OPcache section is a full diagnostic module with snapshots, trends, and recommendations. The user just wants to know if OPcache is enabled. We should replace this with a simpler "Cache Insights" block that shows:
1. Whether OPcache is enabled for user files (via `opcache_get_configuration()` or `phpinfo()`)
2. Any Batcache stats we can surface
3. Other basic, useful cache stats

**Goal:** Replace the OPcache card entirely with a "Cache Insights" card that shows a compact overview.

### Changes

**File: `admin/settings-page.php` (lines 565-593)**

Replace the entire OPcache card with a new "Cache Insights" card:

```php
<div class="pcm-card pcm-card-hover pcm-card-mb-scroll pcm-lazy-section" id="pcm-feature-cache-insights" data-section="cache-insights">
    <h3 class="pcm-card-title">
        <span class="dashicons dashicons-dashboard pcm-title-icon" aria-hidden="true"></span>
        Cache Insights
    </h3>
    <p class="pcm-text-muted-intro">Quick overview of your caching stack status.</p>
    <div class="pcm-lazy-skeleton pcm-skeleton-panel" aria-hidden="true"></div>
    <template class="pcm-lazy-template">
        <div id="pcm-cache-insights-content" class="pcm-cache-insights-grid"></div>
    </template>
</div>
```

**File: `admin/settings-page.php` — anchor nav (line 489)**

Change `OPcache` anchor to `Cache Insights` pointing to `#pcm-feature-cache-insights`.

**File: `admin/public/js/deep-dive.js` — new section replacing OPcache rendering**

Create a new AJAX endpoint `wp_ajax_pcm_cache_insights` that returns:

```json
{
  "opcache_enabled": true|false,
  "opcache_file_cache": "enabled"|"disabled"|"unknown",
  "batcache_status": "active"|"broken"|"unknown",
  "batcache_max_age": 300,
  "object_cache_type": "memcached"|"redis"|"unknown",
  "object_cache_connected": true|false,
  "object_cache_hit_ratio": 94.2,
  "page_cache_ttl": 300
}
```

**PHP backend** — add `pcm_ajax_cache_insights()` function:
- OPcache: call `opcache_get_configuration()` if available, check `opcache.enable` and `opcache.enable_cli`. Simple boolean result.
- Batcache: reuse `pcm_get_batcache_status()` transient + pull `$batcache['max_age']` from global if available.
- Object Cache: check `$wp_object_cache` for type detection, hit ratio from existing stats provider.

**JS rendering** — render a 2x2 or 3-column grid of stat cards:

| Card | Content |
|------|---------|
| OPcache | Green checkmark + "Enabled" or red X + "Disabled" |
| Batcache | Status badge (active/broken/unknown) + max_age if known |
| Object Cache | Type (Memcached/Redis) + connected status + hit ratio % |
| Page Cache TTL | TTL value if known |

Each card is a compact `pcm-cache-insight-card` with icon, label, and value. Minimal — no trends, no charts, no recommendations.

### CSS Addition

```css
.pcm-cache-insights-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
}
.pcm-cache-insight-card {
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    background: #fff;
}
.pcm-cache-insight-card .pcm-ci-label {
    font-size: 12px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 4px;
}
.pcm-cache-insight-card .pcm-ci-value {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
}
.pcm-cache-insight-card .pcm-ci-status-ok { color: #059669; }
.pcm-cache-insight-card .pcm-ci-status-warn { color: #d97706; }
.pcm-cache-insight-card .pcm-ci-status-bad { color: #dc2626; }
```

---

## 3. Redirect Assistant — Move to Its Own Tab

**Problem:** The Redirect Assistant is currently squeezed into a single card within the Deep Dive tab. It needs more space and a better-organized UI. Moving it to its own tab (between Deep Dive and Settings) gives it room and makes it a first-class feature.

**Goal:** Create a new top-level tab "Redirects" with a redesigned UI that matches the Object Cache tab's card-based layout pattern, but with controls appropriate for redirect management.

### Tab Registration

**File: `admin/settings-page.php` (lines 341-357)**

Add new tab between Deep Dive and Settings:

```php
<?php if ( $caching_suite_enabled ) : ?>
<a href="admin.php?page=pressable_cache_management&tab=redirects_tab"
   class="nav-tab <?php echo $is_redirects_tab ? 'nav-tab-active' : ''; ?>">Redirects</a>
<?php else : ?>
<a href="admin.php?page=pressable_cache_management&tab=redirects_tab"
   class="nav-tab nav-tab-disabled" aria-disabled="true">Redirects</a>
<?php endif; ?>
```

Add variable: `$is_redirects_tab = ('redirects_tab' === $tab);`

### Remove from Deep Dive

- Remove `Redirects` from the Deep Dive anchor nav (line 490)
- Remove the Redirect Assistant card block (lines 595-676)

### New Tab Content Layout

The Redirects tab should use a 2-column responsive grid like the Object Cache tab (`pcm-object-cache-grid` pattern), broken into focused cards:

#### Left Column

**Card 1: "Discover Candidates"**
- Icon: `dashicons-search`
- Description: "Paste URLs from your old site or analytics to auto-generate redirect rule suggestions."
- Textarea for URLs (placeholder with examples)
- "Discover" button
- Results area showing discovered candidates as a clean list with "Add to Rules" action per candidate

**Card 2: "Rule Builder"**
- Icon: `dashicons-editor-table`
- Description: "Create and manage your redirect rules."
- "Add Rule" button + "Load Saved Rules" button in header
- Clean table with columns: Source, Target, Type, Status Code, Enabled, Actions
- Per-row delete button with confirmation
- Below table: validation errors display
- "Save Rules" primary button
- Wildcard/regex confirmation checkbox (only visible when wildcards/regex detected)
- Collapsible "Advanced JSON" section (toggle button, hidden by default)

#### Right Column

**Card 3: "Dry-Run Simulator"**
- Icon: `dashicons-controls-play`
- Description: "Test URLs against your current rules without affecting production."
- Textarea for test URLs
- "Run Simulation" button
- Results panel: table showing Input URL → Matched Rule → Result URL → Status Code → Warnings
- Color-coded rows: green (ok), yellow (warning), red (loop detected)

**Card 4: "Export & Import"**
- Icon: `dashicons-download`
- Description: "Generate deployable redirect payloads or import rules from another site."
- Two sub-sections:
  - **Export:** "Build Export" button → generated content in read-only textarea → "Copy" and "Download custom-redirects.php" buttons
  - **Import:** "Import JSON" button → file input or paste area
- Status/output panel for results

### JavaScript Changes

**File: `admin/public/js/deep-dive.js`**

Move all Redirect Assistant JS (lines 1050-1464) into a new file: `admin/public/js/redirects-tab.js`

This file should:
- Initialize immediately (no lazy-loading needed since it's its own tab)
- Attach to the same AJAX endpoints (no backend changes needed)
- Use the same core functions (defaultRule, normalizeRule, renderRules, etc.)
- Split the discover results into their own panel with per-candidate "Add" buttons
- Improve dry-run rendering with color-coded status rows

**File: `admin/settings-page.php`**

Enqueue `redirects-tab.js` only when `$is_redirects_tab` is true, similar to how `deep-dive.js` is conditionally loaded.

### CSS Additions

Reuse existing card/grid patterns from Object Cache tab. Add redirect-specific styles:

```css
/* Redirect candidate list */
.pcm-ra-candidate-list { list-style: none; padding: 0; margin: 8px 0; }
.pcm-ra-candidate-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.pcm-ra-candidate-item:last-child { border-bottom: none; }
.pcm-ra-candidate-source { font-family: monospace; font-size: 13px; color: #374151; }
.pcm-ra-candidate-target { font-family: monospace; font-size: 13px; color: #6366f1; }

/* Dry-run result rows */
.pcm-ra-sim-ok { background: #f0fdf4; }
.pcm-ra-sim-warning { background: #fffbeb; }
.pcm-ra-sim-loop { background: #fef2f2; }

/* Status indicators */
.pcm-ra-status-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
}
.pcm-ra-status-dot-ok { background: #059669; }
.pcm-ra-status-dot-warn { background: #d97706; }
.pcm-ra-status-dot-loop { background: #dc2626; }
```

---

## Summary of Files to Modify

| File | Changes |
|------|---------|
| `admin/public/js/deep-dive.js` | Add insight panels to diagnosis sections; remove Redirect Assistant JS; replace OPcache section with Cache Insights |
| `admin/public/js/redirects-tab.js` | **NEW** — Redirect Assistant JS moved here with improved UI logic |
| `admin/settings-page.php` | Add Redirects tab; remove Redirect card from Deep Dive; replace OPcache card with Cache Insights; update anchor nav |
| `public/css/style.css` | Add insight panel, cache insights, and redirect tab styles |
| `includes/php-opcache-awareness/opcache-awareness.php` | Add `pcm_ajax_cache_insights()` endpoint (reuses existing collectors) |

## Implementation Order

1. **CSS first** — add all new styles so nothing looks broken during development
2. **Security insights** — add insight panels to deep-dive.js diagnosis rendering
3. **Cache Insights** — replace OPcache card in settings-page.php, add AJAX endpoint, add JS rendering
4. **Redirects tab** — add tab to settings-page.php, create redirects-tab.js, remove from Deep Dive, enqueue conditionally
