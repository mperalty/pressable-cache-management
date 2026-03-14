=== Pressable Cache Management ===
Contributors: pressable, mperalty
Tags: WordPress, Pressable, Caching, Cache, Batcache, Object Cache
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 5.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.txt

Pressable cache management made easy.

== Description ==

Pressable Cache Management combines day-to-day cache controls with a focused Deep Dive toolkit for Cacheability Advisor, Route Diagnosis, Layered Probe Runner, and Durable Origin Microcache.

**Features:**

* Flush Object Cache (global)
* Flush Batcache for Individual Pages from the page preview toolbar
* Extend Batcache storage time by 24 hours
* Flush Cache automatically on Plugin/Theme Update
* Flush Cache automatically on Post/Page Edit
* Flush Cache automatically on Page Delete
* Flush Cache automatically on Comment Delete
* Flush Batcache for WooCommerce product pages
* Exclude specific pages from Batcache & Edge Cache
* Enable / Disable / Purge Edge Cache
* Deep Dive focused on Cacheability Advisor, Route Diagnosis, Layered Probe Runner, and Durable Origin Microcache
* Settings tab limited to Deep Dive option toggles
* Show or Hide Pressable Branding
* Available in English, Spanish, French, Dutch, Chinese (Simplified), and Hindi

== Installation ==

1. Upload the `pressable-cache-management` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Cache Management** in the admin sidebar

== Changelog ==

= Unreleased =
* Simplify Deep Dive scope to Cacheability Advisor, Route Diagnosis, Layered Probe Runner, and Durable Origin Microcache
* Remove legacy playbook/remediation wiring from the active Deep Dive experience
* Stop bootstrapping legacy diagnostics modules that are no longer part of the current scope
* Expand uninstall cleanup to remove plugin-owned tables, cron hooks, and newer diagnostics storage
* Refresh plugin documentation to match the focused Deep Dive feature set

Historical changelog entries below are preserved for release history and may mention modules that have since been retired from the current codebase.

= 5.9.0 =
* Add security insight runbook panels to Deep Dive diagnosis sections with actionable remediation steps
* Replace OPcache Awareness module with compact Cache Insights card (OPcache, Batcache, Object Cache status)
* Move Redirect Assistant to its own top-level tab with 4-card layout: Discover, Rule Builder, Dry-Run Simulator, Export & Import
* Add dark mode support for new UI components

= 5.8.9 =
* Fix Object Cache Intelligence timeout with 2s connection timeouts
* Add static caching to all feature flag functions to reduce redundant get_option() calls
* Batch rollup writes in daily cron (10 DB writes reduced to 1)
* Remove summary grid from Deep Dive tab
* Add smooth scroll-into-view when opening playbooks

= 5.2.3 =
* Redesigned UI with card layout and toggle switches
* Added GitHub auto-update support via plugin-update-checker
* Added translations: Spanish, French, Dutch, Chinese (Simplified), Hindi

= 5.2.2 =
* Bug fixes and stability improvements

= 5.2.1 =
* Initial redesign
