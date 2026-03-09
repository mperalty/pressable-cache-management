# Pressable Cache Management - Second Pass Improvements

**Date:** 2026-03-09
**Scope:** Performance, Accessibility, User Friendliness
**Status:** ✅ All 33 tasks complete

---

## Table of Contents

1. [Accessibility (A11y)](#1-accessibility-a11y)
2. [Performance](#2-performance)
3. [User Experience (UX)](#3-user-experience-ux)
4. [Code Quality & Maintainability](#4-code-quality--maintainability)
5. [Reliability & Edge Cases](#5-reliability--edge-cases)
6. [Priority Order](#6-priority-order)

---

## 1. Accessibility (A11y)

### 1.1 Add `prefers-reduced-motion` Support ✅ DONE

**Problem:** All CSS animations (`pcm-spin`, `pcm-pulse`, `pcm-badge-shimmer`, `pcm-skeleton-pulse`, `pcm-shimmer`, toggle slider transitions) play unconditionally. Users with vestibular disorders or motion sensitivity have no way to disable them. This is a WCAG 2.1 AA failure (Success Criterion 2.3.3).

**Files:** `public/css/style.css`, inline `<style>` in `admin/settings-page.php`

**User Story:** As a user with motion sensitivity, I want animations to be disabled or minimized when I've set my OS preference for reduced motion, so that I can use the plugin without discomfort.

**Acceptance Criteria:**
- [ ] A `@media (prefers-reduced-motion: reduce)` block is added that sets `animation: none` and `transition: none` for all animated elements
- [ ] The Batcache badge shimmer, skeleton loaders, spinner, toggle sliders, and pulse animation all respect this media query
- [ ] The base64-encoded GIF spinner in `toolbar.css` is replaced with a CSS spinner that can be disabled via the same media query, OR a static "Loading..." text fallback is shown
- [ ] Manual testing confirms: toggling "Reduce motion" in OS settings immediately stops all plugin animations

---

### 1.2 Modal Keyboard Navigation & ARIA Attributes ✅ DONE

**Problem:** The column.js modal (post/page flush confirmation) and the settings.js confirmation modal both lack proper ARIA attributes and keyboard support. Neither has `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, Escape key handling, or focus trapping. Screen reader users cannot identify these as modal dialogs, and keyboard-only users cannot dismiss them.

**Files:** `admin/public/js/column.js`, `admin/public/js/settings.js`

**User Story:** As a keyboard-only or screen reader user, I want modal dialogs to be announced properly, trappable with Tab, and dismissible with Escape, so that I can interact with confirmation prompts without a mouse.

**Acceptance Criteria:**
- [ ] Both modals have `role="dialog"` (or `role="alertdialog"` for confirmations) and `aria-modal="true"`
- [ ] Both modals have `aria-labelledby` pointing to the modal title element
- [ ] Pressing Escape closes the modal and returns focus to the triggering element
- [ ] Tab key cycles only within the modal while it's open (focus trap)
- [ ] On open, focus moves to the first interactive element (or the modal container)
- [ ] On close, focus returns to the button that opened the modal
- [ ] Screen reader announces modal title and purpose on open

---

### 1.3 ARIA Live Regions for Dynamic Content ✅ DONE

**Problem:** When AJAX operations complete (scan results, status changes, error messages, toast notifications), the updated content is rendered silently. Screen reader users are not informed of these changes. Specific locations: Deep Dive scan results, Batcache status badge updates, privacy settings save feedback, observability report loading, audit log refresh.

**Files:** `admin/settings-page.php`, `admin/public/js/deep-dive.js`, `admin/public/js/settings.js`, `admin/public/js/object-cache-tab.js`

**User Story:** As a screen reader user, I want to be notified when content updates dynamically (scan results, status changes, errors), so that I know when an action has completed or failed.

**Acceptance Criteria:**
- [ ] Status message containers (`#pcm-advisor-run-status`, `#pcm-oci-summary`, `#pcm-opcache-summary`, `#pcm-privacy-status`) have `aria-live="polite"` and `role="status"`
- [ ] Error message containers use `aria-live="assertive"` and `role="alert"`
- [ ] The toast notification element (`.pcm-toast`) has `role="status"` and `aria-live="polite"`
- [ ] The Batcache badge (`#pcm-bc-badge`) updates are announced via a visually-hidden live region
- [ ] After AJAX-rendered content (findings, template scores, audit log), focus is optionally moved to the updated region or an announcement is made
- [ ] The `pcm-flush-feedback` div already has `aria-live="polite"` (confirmed); verify all similar containers do too

---

### 1.4 Close Buttons Need Accessible Names ✅ DONE

**Problem:** Dismiss buttons on branded notices (settings saved, extend batcache, `pcm_branded_notice`) use the Unicode character `&#x2297;` (circled X) with no `aria-label`. Screen readers will announce "times" or the Unicode name, which is meaningless.

**Files:** `admin/settings-page.php` (lines 34, 67, 88)

**User Story:** As a screen reader user, I want close/dismiss buttons to be announced as "Dismiss notification" (or similar), so that I understand what the button does.

**Acceptance Criteria:**
- [ ] All close buttons in branded notices have `aria-label="Dismiss notification"`
- [ ] The pattern is applied consistently in `pcm_branded_notice()`, `pcm_branded_settings_saved_notice()`, and `pcm_extend_batcache_branded_notice()`
- [ ] The Batcache tooltip info button (`?`) retains its existing `aria-label="Batcache info"` (already done)

---

### 1.5 Toggle Switches Need ARIA Labels ✅ DONE

**Problem:** The `.switch` toggle inputs for feature flags, automated rules, and batcache/page rules lack `aria-label` or `aria-describedby` attributes. Screen readers announce them as unnamed checkboxes. The visual label text is in a sibling `<div>`, not in a `<label>` element wrapping the input.

**Files:** `admin/settings-page.php` (lines 374-391, 393-403, 950-963, 980-1048)

**User Story:** As a screen reader user, I want each toggle switch to announce its purpose (e.g., "Enable Caching Suite"), so that I know what I'm toggling on or off.

**Acceptance Criteria:**
- [ ] Every `<input type="checkbox">` inside a `.switch` label has an `aria-label` matching the toggle title (e.g., `aria-label="Enable Caching Suite"`)
- [ ] OR: The toggle title element has an `id` and the input references it via `aria-labelledby`
- [ ] Feature Flags toggles (Caching Suite, Durable Origin Microcache) are labeled
- [ ] Automated Rules toggles (Plugin/Theme Update, Post/Page Edit, Comment Delete) are labeled
- [ ] Batcache & Page Rules toggles (Extend Batcache, Individual Pages, Page/Post Delete, WooCommerce) are labeled
- [ ] Settings-callbacks.php checkbox rendering functions also apply the pattern

---

### 1.6 Color Contrast Fixes ✅ DONE

**Problem:** Two color combinations fail WCAG AA (4.5:1 minimum for normal text):
1. Active Batcache badge: `#028a6a` text on `rgba(3,252,194,0.15)` background resolves to approximately `#e6fef7` = ~3.1:1 contrast ratio
2. Tab hover border: `#94a3b8` on `#f0f2f5` background = ~3.2:1 for the border indicator (decorative, but the text color `#64748b` at ~4.5:1 is borderline)

**Files:** `public/css/style.css` (lines 496-500, 42-56)

**User Story:** As a user with low vision, I want all text and meaningful UI indicators to meet WCAG AA contrast requirements, so that I can read status information without straining.

**Acceptance Criteria:**
- [ ] Active badge text color darkened to `#047857` or similar (>= 4.5:1 on effective background)
- [ ] Active badge background opacity increased slightly OR text made bolder to compensate
- [ ] Tab hover text color adjusted if needed (current `#040024` on hover is fine; ensure `#64748b` idle state passes)
- [ ] Tested with browser DevTools contrast checker or axe/WAVE tool
- [ ] No other color combinations in the plugin fall below 4.5:1 for text or 3:1 for large text/UI components

---

### 1.7 Tooltip Keyboard Accessibility ✅ DONE

**Problem:** The Batcache info tooltip (`?` icon, lines 433-439) only appears on hover via CSS. Keyboard users who Tab to the element cannot see the tooltip because there's no `:focus` or `:focus-visible` trigger. The module description tooltips on feature flags (ⓘ) use `title` attribute which is inconsistently supported by screen readers and has no keyboard reveal.

**Files:** `admin/settings-page.php`, `public/css/style.css`

**User Story:** As a keyboard-only user, I want tooltips to appear when I focus on their trigger element, so that I can read helpful information without using a mouse.

**Acceptance Criteria:**
- [ ] The Batcache `?` tooltip shows on `:focus-visible` in addition to `:hover` (CSS change)
- [ ] The `?` element has `tabindex="0"` and `role="button"` so it's focusable and announced
- [ ] Feature flag module descriptions (ⓘ) either use a proper tooltip component with `:focus-visible` support or use `aria-describedby` pointing to a visually-hidden description
- [ ] `title` attributes on ⓘ spans are supplemented with (not replaced by) an accessible mechanism

---

## 2. Performance

### 2.1 Cache `get_option()` Calls in a Request-Scoped Variable ✅ DONE

**Problem:** `admin/settings-page.php` calls `get_option()` 20+ times for various options during a single page render: metric values (4 calls in summary cards), Smart Purge settings (10 calls), timestamps, feature flags. While WordPress caches options in memory after the first DB hit, repeated lookups add overhead and the pattern is fragile. The `includes/smart-purge-strategy/strategy.php` has 10 separate getter functions that each call `get_option()` independently and may be called multiple times per request.

**Files:** `admin/settings-page.php`, `includes/smart-purge-strategy/strategy.php`

**User Story:** As a site administrator, I want the plugin settings pages to load as fast as possible, so that managing cache settings doesn't slow down my workflow.

**Acceptance Criteria:**
- [ ] Summary card metric values (`pcm_latest_object_cache_hit_ratio`, `pcm_latest_opcache_memory_pressure`, `pcm_latest_cacheability_score`, `pcm_latest_purge_activity`) are fetched once into local variables before the render loop
- [ ] Smart Purge getter functions use a static variable or singleton pattern to cache options on first call
- [ ] `get_option('pressable_cache_management_options')` is called once per request in custom-functions files (currently each file calls it independently)
- [ ] Verified with Query Monitor that option lookups don't result in extra DB queries

---

### 2.2 Fix Cache-Busting `time()` Version Parameters ✅ DONE

**Problem:** Two files use `time()` as the version parameter for `wp_enqueue_style()` and `wp_enqueue_script()`, which generates a unique URL on every page load and defeats browser caching entirely:
1. `admin/custom-functions/object_cache_admin_bar.php` (line ~95) - toolbar CSS
2. `admin/custom-functions/flush_single_page_toolbar.php` (lines ~122, ~138) - toolbar JS

**Files:** `admin/custom-functions/object_cache_admin_bar.php`, `admin/custom-functions/flush_single_page_toolbar.php`

**User Story:** As a site visitor or admin, I want static assets (CSS/JS) to be cached by my browser between page loads, so that pages load faster on repeat visits.

**Acceptance Criteria:**
- [ ] All `wp_enqueue_style()` and `wp_enqueue_script()` calls use `filemtime()` (or a static version string) instead of `time()`
- [ ] Pattern matches what `settings-page.php` already does correctly: `file_exists($path) ? (string) filemtime($path) : '3.0.0'`
- [ ] No remaining instances of `time()` as a version parameter anywhere in the codebase
- [ ] Browser DevTools Network tab confirms assets return `304 Not Modified` on reload (when file unchanged)

---

### 2.3 Replace `@import` with `<link>` for Google Fonts ✅ DONE

**Problem:** `public/css/style.css` line 6 uses `@import url('https://fonts.googleapis.com/css2?family=Inter...')` which is render-blocking. The browser must download and parse `style.css`, discover the `@import`, then make a second request for the font CSS before any text renders. Meanwhile, `settings-page.php` line 245 already enqueues the same Google Fonts URL via `wp_enqueue_style('pcm-google-fonts', ...)` - so the font is loaded twice.

**Files:** `public/css/style.css` (line 6), `admin/settings-page.php` (line 245)

**User Story:** As a site administrator, I want the plugin settings page to render text immediately without a flash of invisible text (FOIT), so that the page feels fast and responsive.

**Acceptance Criteria:**
- [ ] The `@import` line is removed from `style.css`
- [ ] Google Fonts continues to load via the existing `wp_enqueue_style('pcm-google-fonts', ...)` call in settings-page.php
- [ ] The enqueued URL includes `&display=swap` parameter for font-display swap behavior
- [ ] No duplicate font requests visible in Network tab
- [ ] Text renders immediately with system font fallback, then swaps to Inter when loaded

---

### 2.4 Consolidate Inline `<style>` Block into style.css ✅ DONE

**Problem:** `admin/settings-page.php` lines 1200-1354 contain a 154-line inline `<style>` block that duplicates several class definitions already present in `style.css` (`.pcm-card`, `.pcm-card-title`, `.pcm-toggle-row`, `.pcm-toggle-title`, `.pcm-ts-label`, `.pcm-ts-value`, `.pcm-ts-inline`). The duplicates use hardcoded colors instead of CSS custom properties and lack `.pcm-wrap` scoping. This:
- Doubles the CSS download for shared classes
- Creates specificity conflicts (inline `<style>` wins over external stylesheet)
- Makes the dark mode custom properties ineffective for duplicated classes
- Adds ~4KB to every settings page HTML response

**Files:** `admin/settings-page.php` (lines 1200-1354), `public/css/style.css`

**User Story:** As a developer maintaining this plugin, I want all styles in one place using CSS custom properties, so that dark mode works consistently and changes only need to be made once.

**Acceptance Criteria:**
- [ ] Classes already defined in `style.css` are removed from the inline `<style>` block: `.pcm-card`, `.pcm-card-title`, `.pcm-toggle-row`, `.pcm-toggle-title`, `.pcm-toggle-desc`, `.pcm-ts-label`, `.pcm-ts-value`, `.pcm-ts-inline`
- [ ] Classes unique to the inline block (`.pcm-anchor-nav`, `.pcm-summary-grid`, `.pcm-score-*`, `.pcm-severity-badge`, `.pcm-skeleton-*`, `.pcm-toast`, `.pcm-sp-*`, `.pcm-diagnosis-*`, `.pcm-timing-*`, `.pcm-batcache-status` overrides, responsive breakpoints) are moved to `style.css` with `.pcm-wrap` scoping and CSS custom properties where applicable
- [ ] The `#pcm-flush-btn.pcm-btn-loading` spinner styles are moved to `style.css`
- [ ] The inline `<style>` block is either empty (removed) or contains only truly dynamic styles that depend on PHP variables
- [ ] Dark mode (`body.admin-color-modern`) correctly applies to all moved classes
- [ ] Visual regression check: settings page looks identical before and after

---

### 2.5 Lazy-Load Deep Dive Tab Content ✅ DONE

**Problem:** When the Deep Dive tab is active, all 5 section cards (Cacheability Advisor, Object Cache Intelligence, OPcache, Redirect Assistant, Smart Purge) are rendered server-side with full HTML including queue summaries, prewarm logs, and rule tables - even though users typically interact with one section at a time. The Smart Purge card alone (lines 645-821) renders PHP loops over all jobs and outcomes on every page load.

**Files:** `admin/settings-page.php` (lines 447-829)

**User Story:** As an administrator using the Deep Dive tab, I want the page to load quickly by only rendering detailed content when I scroll to or click on a section, so that I'm not waiting for data I'm not looking at.

**Acceptance Criteria:**
- [ ] Summary cards and anchor nav render immediately (already lightweight)
- [ ] Section card bodies (below the title and description) load their detailed content on first visibility via IntersectionObserver or on anchor-nav click
- [ ] Smart Purge queue summary, outcomes, and prewarm logs load via AJAX instead of PHP loops on page render
- [ ] Skeleton loading states (already built) show while content loads
- [ ] Sections that have already been loaded don't re-fetch on subsequent visibility
- [ ] Page load time for Deep Dive tab measurably improves (target: < 500ms TTFB reduction)

---

### 2.6 Add Deactivation Hook to Remove MU-Plugins ✅ DONE

**Problem:** The plugin copies MU-plugin files (extend_batcache, exclude_pages_from_batcache, cache_wpp_cookie, exclude_gclid) into `wp-content/mu-plugins/` when features are enabled. These are removed on uninstall (via `uninstall.php` - excellent), but NOT on deactivation. If a user deactivates the plugin to troubleshoot an issue, the MU-plugins continue executing, potentially causing confusion or conflicts.

**Files:** `pressable-cache-management.php` (no deactivation hook exists), `admin/custom-functions/extend_batcache.php`, `admin/custom-functions/exclude_pages_from_batcache.php`

**User Story:** As a site administrator, I want MU-plugins created by this plugin to be removed when I deactivate it, so that deactivation fully stops the plugin's behavior without requiring uninstall.

**Acceptance Criteria:**
- [ ] A `register_deactivation_hook()` is added to the main plugin file
- [ ] The deactivation callback removes all PCM-created MU-plugin files using `WP_Filesystem`
- [ ] A list of managed MU-plugin filenames is maintained in one place (constant or method)
- [ ] MU-plugins are re-created on reactivation if the corresponding settings are still enabled
- [ ] Deactivation does NOT delete options or transients (those are preserved for re-activation)

---

### 2.7 Prevent setInterval Memory Leak in Object Cache Tab ✅ DONE

**Problem:** `object-cache-tab.js` line ~129 starts an auto-poll `setInterval()` that runs up to 5 times at 60-second intervals. However, if the user navigates to a different tab (Edge Cache, Deep Dive, Settings) within the same page load, the interval continues running and making AJAX requests in the background. There is no cleanup on tab change. Additionally, the `pcmPollTimer` is stored on `window` without cleanup on page unload.

**Files:** `admin/public/js/object-cache-tab.js`

**User Story:** As a site administrator, I want background polling to stop when I navigate away from the Object Cache tab, so that unnecessary network requests don't slow down my browser or create console errors.

**Acceptance Criteria:**
- [ ] `setInterval` is cleared when the user clicks a different tab in the nav
- [ ] A `beforeunload` or `visibilitychange` listener clears the interval on page leave
- [ ] The `pcmPollMax` limit (5 attempts) continues to work as a safety ceiling
- [ ] No orphaned timers or AJAX requests after tab switching
- [ ] Console shows no errors from stale AJAX responses landing after tab change

---

## 3. User Experience (UX)

### 3.1 Timeout Fallback for "Checking..." Batcache Status ✅ DONE

**Problem:** When the Batcache badge shows "Checking..." and the JavaScript probe fails silently (CORS block, network error, or fetch timeout), the badge stays in "Checking..." state indefinitely. The user has no indication that something went wrong. The browser-side detection relies on fetching the homepage with `cache: 'reload'` and reading the `x-nananana` header - this can fail if the site is behind a CDN that strips headers, or if the site is down.

**Files:** `admin/public/js/object-cache-tab.js`

**User Story:** As a site administrator, I want the Batcache status to show a clear error state if detection fails, so that I know to investigate rather than waiting indefinitely.

**Acceptance Criteria:**
- [ ] If the browser-side probe hasn't completed within 15 seconds, the badge transitions to an "Unknown" or "Check Failed" state with a distinct visual style
- [ ] The timeout state shows a "Retry" link/button
- [ ] The error state badge is visually distinct from "Active" and "Broken" (e.g., gray/amber)
- [ ] If the fetch throws a CORS or network error, the badge immediately transitions to the error state (not waiting for timeout)
- [ ] The "Checking..." shimmer animation stops on timeout/error

---

### 3.2 Disable Buttons During AJAX Operations ✅ DONE

**Problem:** Several buttons can be clicked multiple times during an in-flight AJAX request:
1. `column.js`: The flush button changes cursor to "wait" but is NOT disabled - users can queue multiple flush requests
2. `deep-dive.js`: "Rescan now" is properly disabled, but rapid double-clicks before the disable takes effect can fire two scans
3. `settings.js`: The Caching Suite toggle doesn't prevent multiple rapid toggles during a slow network response

**Files:** `admin/public/js/column.js`, `admin/public/js/deep-dive.js`, `admin/public/js/settings.js`

**User Story:** As a site administrator, I want buttons to be disabled immediately on click and re-enabled only after the operation completes, so that I don't accidentally trigger duplicate operations.

**Acceptance Criteria:**
- [ ] `column.js` flush button: `disabled` attribute set immediately on click, removed on success/error
- [ ] All "Rescan now", "Refresh diagnostics", "Refresh OPcache" buttons: use a synchronous `disabled = true` as the first line of the click handler (before any async work)
- [ ] Caching Suite toggle: `disabled = true` set before the AJAX call, restored on response
- [ ] "Save Privacy Settings", "Save Smart Purge Settings", "Save Feature Flags" buttons: disabled during submit
- [ ] Visual feedback (opacity change or spinner) accompanies the disabled state

---

### 3.3 Standardize Timestamp Formatting ✅ DONE

**Problem:** Timestamps are formatted inconsistently across the codebase:
- `flush_cache_on_page_edit.php`: `gmdate('j M Y, g:ia') . ' UTC'`
- `flush_cache_on_page_post_delete.php`: `date(' jS F Y  g:ia') . "\nUTC"` (uses `date()` not `gmdate()`, has leading space, extra spaces, newline before UTC)
- `flush_object_cache.php`: Different format again
- Other files: Various combinations

This means "Last flushed at" timestamps look different depending on which trigger fired. Some show local server time instead of UTC.

**Files:** `admin/custom-functions/flush_cache_on_page_edit.php`, `admin/custom-functions/flush_cache_on_page_post_delete.php`, `admin/custom-functions/flush_cache_on_comment_delete.php`, `admin/custom-functions/flush_cache_on_theme_plugin_update.php`, `admin/custom-functions/flush_object_cache.php`

**User Story:** As a site administrator, I want all "Last flushed at" timestamps to use the same format and timezone, so that I can easily compare when different cache operations occurred.

**Acceptance Criteria:**
- [ ] A shared helper function `pcm_format_flush_timestamp()` is created that returns a consistently formatted UTC timestamp
- [ ] Format: `j M Y, g:ia \U\T\C` (e.g., "9 Mar 2026, 3:45pm UTC") using `gmdate()`
- [ ] All 5+ files that set flush timestamps use this helper
- [ ] No instances of `date()` remain (all replaced with `gmdate()` or `wp_date()`)
- [ ] Timestamps display in the user's WordPress-configured timezone if `wp_date()` is used, OR consistently in UTC with clear "UTC" suffix

---

### 3.4 Show Disabled Deep Dive Tab Instead of Hiding It ✅ DONE

**Problem:** When "Caching Suite" is disabled in Feature Flags, the Deep Dive tab completely disappears from the nav (lines 338-341). Users who don't know about Caching Suite won't discover Deep Dive exists. Users who had it enabled and then disabled it might think the feature was removed in an update.

**Files:** `admin/settings-page.php` (lines 338-341)

**User Story:** As a site administrator, I want to see that a "Deep Dive" tab exists even when it's disabled, with a clear indication of how to enable it, so that I'm aware of the feature and can choose to activate it.

**Acceptance Criteria:**
- [ ] The Deep Dive tab is always rendered in the nav (not conditionally hidden)
- [ ] When Caching Suite is disabled, the tab has a `.nav-tab-disabled` style (muted color, `cursor: not-allowed`)
- [ ] Clicking the disabled tab either: (a) shows an inline message "Enable Caching Suite in Settings to use Deep Dive", or (b) navigates to the Settings tab and highlights the Caching Suite toggle
- [ ] When Caching Suite is enabled, the tab works as it does today
- [ ] The tab shows a small indicator (e.g., lock icon or "Pro" badge) when disabled

---

### 3.5 Improve Error Messages with Actionable Context ✅ DONE

**Problem:** Several error messages are generic and don't help the user resolve the issue:
- `pcm-post.js`: "Unable to reach the server. Check your connection." (could be a server-side error, not a connection issue)
- `pcm-post.js`: "Your session has expired. Please reload the page." (good, but could auto-refresh the nonce)
- `column.js`: "Request failed." (no detail about which request or why)
- `object-cache-tab.js`: "Request failed. Please try again." (no retry button)

**Files:** `admin/public/js/pcm-post.js`, `admin/public/js/column.js`, `admin/public/js/object-cache-tab.js`

**User Story:** As a site administrator, I want error messages to tell me what went wrong and what I can do about it, so that I can resolve issues without guessing.

**Acceptance Criteria:**
- [ ] Network errors include: "Could not connect to {site}. Check that your site is accessible."
- [ ] Timeout errors include: "The request took too long. [Try again]" with a clickable retry action
- [ ] 403/nonce errors include: "Your session expired." with an automatic nonce refresh attempt before showing the error (nonce refresh infrastructure already exists in pcm-post.js)
- [ ] 500 errors include: "Server error. Check your PHP error logs for details."
- [ ] column.js error includes the post title or ID: "Failed to flush cache for '{post title}'."
- [ ] All error messages render in the `.pcm-inline-error` styled container (already exists)

---

### 3.6 Add Confirmation Before Destructive Actions ✅ DONE

**Problem:** The "Purge Edge Cache" button and the "Flush Cache for all Pages" button trigger immediately on click with no confirmation. Purging edge cache temporarily slows the site for all visitors. A global object cache flush can cause a brief spike in database load. These are high-impact operations that should require confirmation, especially since the purge button is red (danger styling) but still fires instantly.

**Files:** `admin/settings-page.php`, `admin/public/js/object-cache-tab.js`

**User Story:** As a site administrator, I want destructive cache operations to ask for confirmation before executing, so that I don't accidentally purge caches during peak traffic.

**Acceptance Criteria:**
- [ ] "Purge Edge Cache" shows a confirmation dialog: "This will temporarily slow your site while the cache rebuilds. Continue?"
- [ ] "Flush Cache for all Pages" shows a confirmation: "This will flush the entire object cache. Continue?"
- [ ] The confirmation dialog uses the accessible modal pattern from task 1.2
- [ ] Single-page flush operations do NOT require confirmation (low impact)
- [ ] The confirmation can be bypassed with a "Don't ask again" checkbox that stores preference in localStorage

---

### 3.7 Toast Notification Auto-Dismiss with Progress ✅ DONE

**Problem:** The `.pcm-toast` notification system exists but there's no auto-dismiss timer. Toasts appear and stay until... it's unclear. There's no visual progress indicator showing how long the toast will remain, and no way to dismiss it manually.

**Files:** Inline `<style>` in `admin/settings-page.php` (toast CSS), `admin/public/js/settings.js` or wherever toast is triggered

**User Story:** As a site administrator, I want toast notifications to automatically dismiss after a few seconds with a visible countdown, and I want to be able to dismiss them early by clicking, so that notifications don't pile up or obscure content.

**Acceptance Criteria:**
- [ ] Toasts auto-dismiss after 5 seconds (configurable)
- [ ] A thin progress bar at the bottom of the toast shrinks over the duration
- [ ] Hovering over a toast pauses the auto-dismiss timer
- [ ] Clicking the toast dismisses it immediately
- [ ] Success toasts auto-dismiss; error toasts persist until manually dismissed
- [ ] Multiple toasts stack vertically with newest on top
- [ ] Dismiss animation is smooth (opacity + translateY)

---

### 3.8 Chip Input UX Improvements ✅ DONE

**Problem:** The exclusion chip input (`#pcm-exempt-input`) for cache exclusions accepts URLs but has several UX gaps:
- Pasting a comma-separated list creates chips but with no debounce, causing performance issues with large pastes
- No validation that the entered value looks like a URL path
- No visual feedback when a chip is added or removed
- Duplicate values can be added
- No "clear all" option for users who want to start fresh

**Files:** `admin/public/js/object-cache-tab.js` (chip logic), `admin/settings-page.php` (chip HTML)

**User Story:** As a site administrator managing cache exclusions, I want the chip input to validate entries, prevent duplicates, and provide clear feedback, so that I can confidently manage my exclusion list.

**Acceptance Criteria:**
- [ ] Pasting a comma-separated list is debounced (100ms) and processes entries sequentially
- [ ] Duplicate values are rejected with a brief inline message: "Already excluded"
- [ ] Values that don't start with `/` get auto-prefixed with `/`
- [ ] Adding a chip shows a brief green flash on the new chip
- [ ] Removing a chip shows a fade-out animation
- [ ] A "Clear all" link appears when 2+ chips exist
- [ ] Empty/whitespace-only values are silently rejected

---

## 4. Code Quality & Maintainability

### 4.1 Move Inline Styles from PHP to CSS Classes ✅ DONE

**Problem:** 10+ PHP files contain heavy inline `style="..."` attributes on HTML elements. This makes the design impossible to maintain centrally, breaks CSS custom property theming (dark mode), prevents responsive overrides, and increases HTML payload size. Major offenders:
- `settings-page.php`: ~50 inline style attributes on the wrapper, grid containers, headers, and layout elements
- `turn_on_off_edge_cache.php`: Inline styles on notice wrappers
- `object_cache_admin_bar.php`: Modal overlay styling
- `settings-callbacks.php`: Form element styling

**Files:** `admin/settings-page.php`, `admin/custom-functions/turn_on_off_edge_cache.php`, `admin/custom-functions/object_cache_admin_bar.php`, `admin/settings-callbacks.php`

**User Story:** As a developer, I want all visual styling defined in CSS files with semantic class names, so that I can maintain the design system in one place and ensure dark mode works everywhere.

**Acceptance Criteria:**
- [ ] Layout containers use semantic classes (e.g., `.pcm-page-wrap`, `.pcm-page-inner`, `.pcm-grid-2col`, `.pcm-section-header`) instead of inline styles
- [ ] The branded notice helper `pcm_branded_notice()` uses CSS classes instead of inline `style` attributes
- [ ] The settings-saved notice and extend-batcache notice use CSS classes
- [ ] Object cache tab grid layout uses CSS classes
- [ ] Edge cache card layout uses CSS classes
- [ ] All new classes use CSS custom properties for colors
- [ ] Total inline `style` attribute count reduced by 80%+
- [ ] Dark mode verified working on all migrated elements

---

### 4.2 Create Shared Option Constants ✅ DONE

**Problem:** Option names like `'flush-obj-cache-time-stamp'`, `'edge-cache-purge-time-stamp'`, `'pressable_cache_management_options'`, `'pcm_enable_caching_suite_features'` are string literals scattered across 30+ files. A typo in any one location creates a silent bug. No IDE can find all references to an option name via string search alone.

**Files:** All PHP files across the plugin

**User Story:** As a developer, I want option names defined as constants in one file, so that typos are caught at compile time and I can find all usages via IDE "find references."

**Acceptance Criteria:**
- [ ] A new file `includes/constants.php` (or similar) defines all option name constants in a class or interface
- [ ] Example: `const FLUSH_OBJ_CACHE_TIMESTAMP = 'flush-obj-cache-time-stamp';`
- [ ] All files reference the constant instead of the string literal
- [ ] The constants file is `require_once`d in the main plugin file before any other includes
- [ ] `uninstall.php` also uses the constants for cleanup
- [ ] No raw option name strings remain (except in the constants definition itself)

---

### 4.3 Consolidate Duplicate Nonce Verification Patterns ✅ DONE

**Problem:** The nonce verification pattern is copy-pasted across 10+ files with slight variations:
```php
if ( ! isset( $_POST['nonce_name'] ) ) return;
if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce_name'] ) ), 'action_name' ) ) return;
if ( ! current_user_can( 'manage_options' ) ) return;
```

Some files check POST, some check GET. Some use `wp_die()`, some use `return`, some use `wp_send_json_error()`. The inconsistency makes security audits harder.

**Files:** All files in `admin/custom-functions/`, `admin/settings-page.php`

**User Story:** As a developer performing a security audit, I want nonce and capability checks to follow one consistent pattern, so that I can quickly verify all endpoints are properly secured.

**Acceptance Criteria:**
- [ ] A helper function `pcm_verify_request( $nonce_name, $action, $method = 'POST' )` is created
- [ ] The helper handles: field existence check, `sanitize_text_field( wp_unslash() )`, `wp_verify_nonce()`, `current_user_can( 'manage_options' )`
- [ ] Returns `true`/`false` for non-AJAX contexts; calls `wp_send_json_error()` for AJAX contexts
- [ ] All nonce checks across the plugin use this helper
- [ ] Security behavior is identical before and after refactor (test each endpoint)

---

### 4.4 Reduce `!important` Usage in Button and Tab CSS ✅ DONE

**Problem:** The unified button system (`.pcm-btn-primary` etc.) and tab navigation in `style.css` use `!important` on nearly every property (40+ instances). This was necessary to override WordPress admin CSS, but the `.pcm-wrap` scoping already provides sufficient specificity for most properties. The excessive `!important` makes it impossible to create contextual overrides (e.g., smaller buttons in a compact layout) without even more `!important`.

**Files:** `public/css/style.css` (lines 79-188, 31-69)

**User Story:** As a developer extending this plugin's UI, I want CSS that uses specificity instead of `!important`, so that I can create contextual style overrides without fighting the cascade.

**Acceptance Criteria:**
- [ ] Audit each `!important` in the button and tab sections
- [ ] Remove `!important` where `.pcm-wrap .pcm-btn-primary` specificity alone overrides WP admin styles
- [ ] Keep `!important` only where WordPress admin CSS uses it on the same property (verified by testing)
- [ ] Add a CSS comment explaining each remaining `!important`: `/* !important: overrides wp-admin .button */`
- [ ] Test in WordPress 6.7 admin that all buttons and tabs render correctly without visual regression
- [ ] Target: reduce `!important` count by 50%+

---

### 4.5 Namespace JavaScript with IIFE or Module Pattern ✅ DONE

**Problem:** While all JS files use the `window.pcm*` prefix (good), several files attach 10+ properties to `window`. `object-cache-tab.js` alone creates: `pcmBatcacheNonce`, `pcmSiteUrl`, `pcmProbeInProgress`, `pcmPollTimer`, `pcmPollCount`, `pcmPollMax`, `pcmRefreshBatcacheStatus`. The `column.js` file creates global `curronload_1` and `newonload_1` without any prefix.

**Files:** All files in `admin/public/js/`

**User Story:** As a developer, I want the plugin's JavaScript to be properly namespaced to prevent conflicts with other plugins, and to make the code easier to reason about.

**Acceptance Criteria:**
- [ ] Each JS file wraps its code in an IIFE: `(function(window, document) { ... })(window, document);`
- [ ] Only intentionally public APIs are exposed on `window` (e.g., `window.pcmRefreshBatcacheStatus` for the onclick handler)
- [ ] Internal state variables (`pcmProbeInProgress`, `pcmPollTimer`, `pcmPollCount`) are local to the IIFE
- [ ] `column.js` globals `curronload_1` / `newonload_1` are refactored to use `DOMContentLoaded` or `addEventListener` instead of `window.onload` chaining
- [ ] No new globals introduced; existing public API surface documented in a comment at top of each file

---

## 5. Reliability & Edge Cases

### 5.1 Capability Check Consistency for Admin Bar Actions ✅ DONE

**Problem:** `object_cache_admin_bar.php` uses a permissive capability check that allows editors and WooCommerce shop managers to flush the entire site cache:
```php
if ( ! current_user_can('administrator') && ! current_user_can('editor') && ! current_user_can('manage_woocommerce') )
```
Meanwhile, all settings-page AJAX handlers correctly use `current_user_can('manage_options')`. An editor flushing the global object cache could cause a traffic spike on a high-traffic site.

**Files:** `admin/custom-functions/object_cache_admin_bar.php`

**User Story:** As a site owner, I want only administrators to flush the site-wide cache, while editors can only flush cache for their own content, so that high-impact operations are restricted to users who understand the consequences.

**Acceptance Criteria:**
- [ ] Global cache flush (object cache + edge cache) requires `manage_options` capability
- [ ] Single-page cache flush remains available to editors (lower impact)
- [ ] The combined flush button ("Flush All Caches") requires `manage_options`
- [ ] The admin bar menu items are conditionally shown based on the user's capability
- [ ] A filter `pcm_flush_cache_capability` allows site owners to customize the required capability
- [ ] The custom capabilities from the security-privacy module (`pcm_run_scans`, etc.) are checked where relevant

---

### 5.2 Handle Comment Hard Deletes ✅ DONE

**Problem:** `flush_cache_on_comment_delete.php` hooks into `trash_comment` to flush cache when comments are deleted. However, WordPress also has a `delete_comment` hook that fires when comments are permanently deleted (bypassing trash). If an admin empties the comment trash or force-deletes comments, the cache is not flushed.

**Files:** `admin/custom-functions/flush_cache_on_comment_delete.php`

**User Story:** As a site administrator, I want cache to be flushed when comments are permanently deleted (not just trashed), so that deleted comment content doesn't remain in cached pages.

**Acceptance Criteria:**
- [ ] The flush callback is hooked to both `trash_comment` AND `delete_comment`
- [ ] Duplicate flushes are prevented if both hooks fire for the same comment (e.g., trash then delete)
- [ ] The timestamp is updated for both operations
- [ ] Tested: permanently deleting a comment from trash triggers cache flush

---

### 5.3 SVG Chart Responsive Sizing ✅ DONE

**Problem:** `deep-dive.js` renders SVG sparkline charts with hardcoded dimensions: `width="520"` and `height="140"`. On mobile or tablet viewports, these charts overflow their container. The CSS class `.pcm-trend-chart svg { width: 100%; height: auto; }` attempts to fix this but `width: 100%` on an SVG with a fixed `width` attribute doesn't shrink properly in all browsers without a `viewBox`.

**Files:** `admin/public/js/deep-dive.js`

**User Story:** As a site administrator viewing Deep Dive on a tablet or phone, I want charts to resize to fit my screen, so that I can read trend data without horizontal scrolling.

**Acceptance Criteria:**
- [ ] SVG elements use `viewBox="0 0 520 140"` instead of (or in addition to) `width`/`height` attributes
- [ ] SVG elements have `preserveAspectRatio="xMidYMid meet"`
- [ ] The container CSS sets `max-width: 100%` and the SVG fills available width
- [ ] Charts remain readable at 320px viewport width
- [ ] No horizontal scrollbar appears on the Deep Dive tab at any viewport width

---

### 5.4 Deep Dive Event Listener Accumulation ✅ DONE (already correct - uses event delegation)

**Problem:** `deep-dive.js` attaches click event listeners to dynamically rendered elements (findings list, playbook container, score items) inside the AJAX response handler. Each time "Rescan now" is clicked and results re-render, NEW listeners are added without removing the old ones. Over multiple scans in a single page session, this causes:
- Duplicate event handlers firing
- Memory growth proportional to number of scans
- Unexpected behavior (e.g., clicking a finding triggers the handler multiple times)

**Files:** `admin/public/js/deep-dive.js`

**User Story:** As a site administrator running multiple scans in a session, I want each scan's results to behave correctly without duplicate click responses or memory issues.

**Acceptance Criteria:**
- [ ] Event listeners use event delegation on a stable parent container (e.g., the card element) instead of attaching to dynamically created children
- [ ] OR: Previous listeners are explicitly removed (via `removeEventListener` or `replaceChildren()`) before new content is rendered
- [ ] Running "Rescan now" 5 times in a row does not create duplicate handler calls
- [ ] Memory profiling shows stable heap size across multiple scans
- [ ] All interactive elements (finding items, diagnosis links, playbook triggers) continue to work after re-render

---

### 5.5 Graceful Degradation When Caching Suite Features Are Missing ✅ DONE

**Problem:** Deep Dive sections check for feature functions with `function_exists()` (e.g., `pcm_cacheability_advisor_is_enabled()`). If a feature module file fails to load (PHP error, file missing, filter disabling the include), the section silently disappears. The user sees an empty Deep Dive page with just summary cards and nav links that point to non-existent sections.

**Files:** `admin/settings-page.php` (lines 500, 528, 550, 571, 645)

**User Story:** As a site administrator, I want to see a helpful message when a Deep Dive section is unavailable, so that I understand why it's missing and can take action.

**Acceptance Criteria:**
- [ ] When `function_exists()` returns false for a feature, a placeholder card is rendered with the section title and a message: "This module is not available. It may be disabled by a filter or failed to load."
- [ ] The anchor nav links still point to the placeholder cards (so scroll-to works)
- [ ] Summary cards for unavailable modules show a "N/A" or "Unavailable" value instead of stale data
- [ ] The placeholder card includes a link to the Settings tab's Feature Flags section
- [ ] No PHP warnings or errors if a module is completely absent

---

### 5.6 Edge Cache Status Race Condition on Enable/Disable ✅ DONE

**Problem:** `turn_on_off_edge_cache.php` enables or disables edge cache via form POST, then immediately reads the status to update the UI. If the edge cache platform takes a few seconds to propagate the change, the status read may return the old state, confusing the user. The `pcm_ec_status_cache` transient is deleted on toggle, but the next status check may still see the old state from the platform API.

**Files:** `admin/custom-functions/turn_on_off_edge_cache.php`

**User Story:** As a site administrator enabling or disabling edge cache, I want the status to accurately reflect the change I just made, even if propagation takes a few seconds.

**Acceptance Criteria:**
- [ ] After toggling edge cache, the UI shows a "Propagating..." state for 5-10 seconds before re-checking
- [ ] The re-check polls every 2 seconds up to 5 times until the status matches the expected state
- [ ] If propagation doesn't complete within the polling window, the UI shows: "Change submitted. Status may take a minute to update."
- [ ] The transient is not re-set until the status is confirmed
- [ ] The enable/disable button is disabled during propagation

---

## 6. Priority Order

### Tier 1: High Impact, Quick Wins (Do First)

| # | Task | Category | Effort |
|---|------|----------|--------|
| 1.1 | `prefers-reduced-motion` | A11y | Small |
| 2.2 | Fix `time()` cache-busting | Perf | Small |
| 2.3 | Remove duplicate `@import` | Perf | Small |
| 1.4 | Close button accessible names | A11y | Small |
| 3.3 | Standardize timestamps | UX | Small |
| 5.2 | Comment hard delete hook | Reliability | Small |

### Tier 2: High Impact, Medium Effort

| # | Task | Category | Effort |
|---|------|----------|--------|
| 1.2 | Modal keyboard nav + ARIA | A11y | Medium |
| 1.3 | ARIA live regions | A11y | Medium |
| 1.5 | Toggle switch ARIA labels | A11y | Medium |
| 3.1 | Batcache "Checking..." timeout | UX | Medium |
| 3.2 | Disable buttons during AJAX | UX | Medium |
| 2.4 | Consolidate inline styles | Perf | Medium |
| 5.4 | Event listener accumulation | Reliability | Medium |

### Tier 3: Significant Improvement, Larger Effort

| # | Task | Category | Effort |
|---|------|----------|--------|
| 2.1 | Cache get_option() calls | Perf | Medium |
| 2.6 | Deactivation hook for MU-plugins | Perf | Medium |
| 3.4 | Show disabled Deep Dive tab | UX | Medium |
| 3.5 | Actionable error messages | UX | Medium |
| 3.6 | Confirmation for destructive actions | UX | Medium |
| 4.1 | Move inline styles to CSS | Code Quality | Large |
| 4.3 | Shared nonce verification helper | Code Quality | Medium |
| 5.1 | Capability check consistency | Reliability | Medium |

### Tier 4: Polish & Long-term Maintenance

| # | Task | Category | Effort |
|---|------|----------|--------|
| 1.6 | Color contrast fixes | A11y | Small |
| 1.7 | Tooltip keyboard accessibility | A11y | Medium |
| 2.5 | Lazy-load Deep Dive content | Perf | Large |
| 2.7 | setInterval cleanup | Perf | Small |
| 3.7 | Toast auto-dismiss + progress | UX | Medium |
| 3.8 | Chip input improvements | UX | Medium |
| 4.2 | Shared option constants | Code Quality | Large |
| 4.4 | Reduce `!important` usage | Code Quality | Medium |
| 4.5 | JS IIFE namespacing | Code Quality | Medium |
| 5.3 | SVG chart responsive sizing | Reliability | Small |
| 5.5 | Graceful degradation messages | Reliability | Medium |
| 5.6 | Edge cache status race condition | Reliability | Medium |

---

**Total: 33 tasks across 5 categories**
- Accessibility: 7 tasks
- Performance: 7 tasks
- User Experience: 8 tasks
- Code Quality: 5 tasks
- Reliability: 6 tasks
