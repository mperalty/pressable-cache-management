# Pressable Cache Management

**Plugin Name:** Pressable Cache Management
**Current Version:** 5.8.8
**Description:** Pressable cache management made easy from WordPress admin.
**Author:** Pressable Customer Success Team

---

## Overview

Pressable Cache Management gives Pressable-hosted WordPress sites a centralized cache operations panel with tools for:

- day-to-day cache controls (Batcache, Object Cache, Edge Cache),
- targeted and automatic purge workflows,
- cache diagnostics and trend reporting,
- privacy and permissions guardrails for advanced tooling.

The plugin does **not** replace Pressable platform infrastructure (Batcache/Edge stack); it provides safer controls and visibility from wp-admin.

---

## What this version can do

## Core cache controls

- Flush Object Cache globally.
- Flush Batcache for individual pages (including toolbar workflows).
- Purge Edge Cache globally or for a specific URL.
- Enable/disable Edge Cache from wp-admin.
- Exclude selected pages from Batcache/Edge cache behavior.
- Extend Batcache TTL behavior via plugin-managed hooks.

## Automatic purge and invalidation triggers

- Flush cache on plugin/theme updates.
- Flush cache on post/page edits.
- Flush cache on page/post deletes.
- Flush cache on comment deletes.
- WooCommerce-aware single-product cache handling.

## Admin UX and operations improvements

- Settings screen with card-based cache controls.
- Admin-bar cache actions and single-page flush helpers.
- Last-flushed timestamps and improved status feedback.
- Optional Pressable branding controls in admin experience.

## Localization and updates

- Translation support for English, Spanish, French, Dutch, Simplified Chinese, and Hindi.
- GitHub release-based plugin update checks (plugin-update-checker integration).

---

## 2026 Caching Suite modules included in this codebase

These modules are scaffolded/implemented in `includes/` and are designed for staged rollout via feature flags.

- **Cacheability Advisor**
  URL sampling, cacheability scans, finding storage, template scoring, and scan result endpoints.

- **Cache-Busting Detector**
  Detector framework for cookie/query/vary/no-cache/purge-pattern signals plus trend/top-source insights.

- **Object Cache Intelligence**
  Object-cache stats providers, health evaluation, snapshots, and trend endpoints.

- **PHP OPcache Awareness**
  OPcache snapshots, recommendation engine, and trend endpoints.

- **Redirect Assistant**
  Candidate discovery, rule simulation, import/export, and management endpoints for cache-friendly redirects.

- **Smart Purge Strategy**
  Event normalization, recommendation engine, and queue primitives for scoped/deferred purge behavior.

- **Observability & Reporting**
  Metric rollups, trends, exports, digest services, and optional WP-CLI integration.

- **Guided Remediation Playbooks**
  Markdown-backed playbook repository, rule-to-playbook lookup, and per-playbook progress/verification state.

- **Permissions, Safety, and Privacy baseline**
  Capability matrix, audit log service, retention/redaction settings, and privacy-first telemetry middleware.

> Note: most advanced suite modules are feature-gated so they can be enabled progressively per environment.

---

## Feature flags (advanced modules)

Examples of toggles available through WordPress filters:

- `pcm_enable_cacheability_advisor`
- `pcm_enable_cache_busters`
- `pcm_enable_object_cache_intelligence`
- `pcm_enable_opcache_awareness`
- `pcm_enable_redirect_assistant`
- `pcm_enable_smart_purge_strategy`
- `pcm_enable_observability_reporting`
- `pcm_enable_guided_playbooks`
- `pcm_enable_security_privacy`

### Durable Origin Microcache (`pcm_enable_durable_origin_microcache`)

This module is intentionally **opt-in** and **integration-driven**. Enabling it shows the dashboard card, but the counters stay at zero until code paths actually call the microcache API.

How it works today:

- `pcm_microcache_get_or_build( $key, $builder, $ttl, $tags )` is the core primitive.
- It only caches anonymous-safe requests (logged-in requests and unsafe cookie variants are rejected by default).
- On fresh entry it records a **hit**.
- On missing entry it records a **miss** and stores a new artifact.
- On expired-but-within-SWR-window entry it serves stale content, records **SWR**, and schedules async rebuild.
- Tag invalidation events are recorded when post/taxonomy/manual purge hooks run.

Why you may see all zeros:

- Module is disabled (`pcm_enable_durable_origin_microcache` false).
- You only tested while logged in / with personalization cookies.
- No frontend code is calling `pcm_microcache_get_or_build()` yet for your route.
- You have not hit the included public demo endpoint enough to produce traffic.

Quick validation checklist:

1. Enable **Durable Origin Microcache** in plugin settings.
2. Make two anonymous requests to:
   - `/wp-admin/admin-ajax.php?action=pcm_microcache_public_health`
3. Expected telemetry after second request:
   - misses >= 1
   - hits >= 1
4. Edit a post or term; confirm an invalidation event appears in the deep-dive card.

Production adoption guidance:

- Wrap expensive but deterministic anonymous payload builders with `pcm_microcache_get_or_build()`.
- Use stable keys and attach tags that map to content dependencies (`post_{id}`, `post_type_{type}`, `taxonomy_{tax}`).
- Keep personalized/session variants out unless you intentionally opt in via `pcm_microcache_allow_personalized_request`.

---

## Installation

1. Upload the plugin to `/wp-content/plugins/pressable-cache-management`, or install it from your internal distribution flow.
2. Activate the plugin in **Plugins**.
3. Open **Settings → Pressable Cache Management** (or the plugin’s Cache Management menu entry).
4. (Optional) Enable advanced modules via feature flags in environment-specific code.

---

## Documentation

- Product roadmap: `docs/CACHING_SUITE_ROADMAP.md`
- 2026 implementation specs: `docs/2026-dev/README.md`
- Release/setup notes: `SETUP.md`
- WordPress.org-style plugin readme: `readme.txt`

---

## Compatibility

- Requires at least WordPress 5.0
- Tested up to WordPress 6.7
- Requires PHP 7.4+

---

## License

GPL v2 or later
