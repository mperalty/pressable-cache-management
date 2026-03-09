# PHP 8+ Upgrade Guide — Pressable Cache Management

**Current minimum:** PHP 7.4
**Target minimum:** PHP 8.0+ (with optional PHP 8.1+ enhancements)
**Estimated scope:** ~50+ files, ~150+ individual changes

---

## Phase 1: Update Version Requirements

### 1.1 Update plugin header
**File:** `pressable-cache-management.php`
```php
// Change:
* Requires PHP: 7.4
// To:
* Requires PHP: 8.0
```

### 1.2 Update readme.txt
**File:** `readme.txt`
```php
// Change:
Requires PHP: 7.4
// To:
Requires PHP: 8.0
```

### 1.3 Add a PHP version check (optional safeguard)
**File:** `pressable-cache-management.php` — near the top
```php
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'Pressable Cache Management requires PHP 8.0 or higher.';
        echo '</p></div>';
    } );
    return;
}
```

---

## Phase 2: Replace `strpos()` with PHP 8 String Functions

Replace `strpos() !== false` / `strpos() === false` / `strpos() === 0` patterns with `str_contains()`, `str_starts_with()`, and `str_ends_with()`.

~15+ instances across the codebase.

### Files & changes

| File | Current | Replacement |
|------|---------|-------------|
| `admin/settings-page.php` | `strpos( $val, 'batcache' ) !== false` | `str_contains( $val, 'batcache' )` |
| `includes/cache-busters/detector-framework.php` | `false === strpos( $cache_control, $directive )` | `! str_contains( $cache_control, $directive )` |
| `includes/cacheability-advisor/storage.php` | `0 === stripos( $trimmed, 'HTTP/' )` | `str_starts_with( strtoupper( $trimmed ), 'HTTP/' )` |
| `includes/cacheability-advisor/storage.php` | `false !== stripos( ..., 'miss' )` | `str_contains( strtolower( ... ), 'miss' )` |
| `includes/durable-origin-microcache/microcache.php` | `0 === strpos( ... )` | `str_starts_with( ... )` |
| `includes/object-cache-intelligence/intelligence.php` | `strpos()` checks | `str_contains()` / `str_starts_with()` |
| `includes/redirect-assistant/assistant.php` | Multiple `strpos()` calls | `str_contains()` / `str_starts_with()` |
| `remove-old-mu-plugins.php` | `strpos( ... ) !== false` | `str_contains( ... )` |
| `scripts/security-privacy-check.php` | `strpos() !== false` / `=== false` | `str_contains()` / `! str_contains()` |

### Example

```php
// Before
if ( strpos( $val, 'batcache' ) !== false ) {

// After
if ( str_contains( $val, 'batcache' ) ) {
```

```php
// Before
if ( 0 === strpos( $cookie_name, $prefix ) ) {

// After
if ( str_starts_with( $cookie_name, $prefix ) ) {
```

---

## Phase 3: Null Coalescing Operator (`??`)

Replace `isset($x) ? $x : default` with `$x ?? default`.

~40+ instances, concentrated in `admin/settings-callbacks.php`.

### Files & changes

**`admin/settings-callbacks.php`** — highest density (~30 instances)
```php
// Before
$id      = isset( $args['id'] ) ? $args['id'] : '';
$label   = isset( $args['label'] ) ? $args['label'] : '';
$checked = isset( $options[$id] ) ? checked( $options[$id], 1, false ) : '';

// After
$id      = $args['id'] ?? '';
$label   = $args['label'] ?? '';
$checked = isset( $options[$id] ) ? checked( $options[$id], 1, false ) : '';
// Note: The $checked line calls a function, so it stays as-is or uses a different pattern
```

**`admin/settings-page.php`** — ~4 instances
**`admin/custom-functions/turn_on_off_edge_cache.php`** — ~2 instances
**`admin/custom-functions/flush_batcache_for_particular_page.php`** — ~3 instances
**`admin/custom-functions/exclude_pages_from_batcache_mu_plugin.php`** — ~1 instance
**`admin/custom-functions/flush_cache_on_theme_plugin_update.php`** — ~1 instance

### Pattern

```php
// Before
$value = isset( $_POST['key'] ) ? intval( wp_unslash( $_POST['key'] ) ) : 0;

// After
$value = (int) ( wp_unslash( $_POST['key'] ?? 0 ) );
```

---

## Phase 4: Add Type Declarations

### 4.1 Function parameter and return types

Add types to all custom functions and class methods. ~50+ functions across the codebase.

**`includes/helpers.php`**
```php
// Before
function pcm_format_flush_timestamp() {
    return gmdate( 'j M Y, g:ia' ) . ' UTC';
}

// After
function pcm_format_flush_timestamp(): string {
    return gmdate( 'j M Y, g:ia' ) . ' UTC';
}
```

```php
// Before
function pcm_get_options() {

// After
function pcm_get_options(): array|false {
```

```php
// Before
function pcm_verify_request( $nonce_name, $action, $method = 'POST', $capability = 'manage_options' ) {

// After
function pcm_verify_request( string $nonce_name, string $action, string $method = 'POST', string $capability = 'manage_options' ): bool {
```

### 4.2 Typed class properties

Add property types to all classes. ~30+ properties.

**`includes/cache-busters/detector-framework.php`**
```php
// Before
class PCM_Cache_Buster_Event {
    public $category;
    public $signature;
    public $confidence;
    public $count;
    public $likely_source;
    public $affected_urls;
    public $evidence_samples;
}

// After
class PCM_Cache_Buster_Event {
    public string $category = '';
    public string $signature = '';
    public float $confidence = 0.0;
    public int $count = 0;
    public string $likely_source = '';
    public array $affected_urls = [];
    public array $evidence_samples = [];
}
```

**Other classes needing typed properties:**
- `admin/custom-functions/pcm_batcache_manager.php` — `Batcache_Manager`
- `includes/smart-purge-strategy/strategy.php` — multiple classes
- `includes/object-cache-intelligence/intelligence.php` — intelligence classes
- `includes/observability-reporting/reporting.php` — `PCM_Metric_Registry`
- `includes/cacheability-advisor/storage.php` — `PCM_Cacheability_Advisor`

---

## Phase 5: Constructor Property Promotion

Simplify constructors that assign parameters to properties. ~5+ classes.

**`includes/cache-busters/detector-framework.php`**
```php
// Before
class PCM_Cache_Buster_Detector_Runner {
    protected $registry;
    protected $snapshot_provider;

    public function __construct( $registry = null, $snapshot_provider = null ) {
        $this->registry          = $registry;
        $this->snapshot_provider = $snapshot_provider;
    }
}

// After
class PCM_Cache_Buster_Detector_Runner {
    public function __construct(
        protected ?PCM_Cache_Buster_Registry $registry = null,
        protected ?callable $snapshot_provider = null,
    ) {}
}
```

**Other candidates:**
- `includes/cacheability-advisor/storage.php` — `PCM_Cacheability_Advisor`
- `includes/object-cache-intelligence/intelligence.php` — `PCM_Object_Cache_Intelligence`
- `includes/observability-reporting/reporting.php` — `PCM_Metric_Registry`
- `includes/smart-purge-strategy/strategy.php` — multiple classes

---

## Phase 6: Match Expressions

Replace if/elseif chains that map conditions to values.

**`admin/settings-page.php`** — Batcache status detection
```php
// Before
if ( strpos( $val, 'batcache' ) !== false ) {
    $cached = 'hit';
} elseif ( strpos( $val, 'miss' ) !== false ) {
    $cached = 'miss';
} else {
    $cached = 'broken';
}

// After
$cached = match ( true ) {
    str_contains( $val, 'batcache' ) => 'hit',
    str_contains( $val, 'miss' )     => 'miss',
    default                          => 'broken',
};
```

**`admin/custom-functions/turn_on_off_edge_cache.php`** — Edge cache status
**`admin/custom-functions/flush_batcache_for_woo_individual_page.php`** — Notice state

---

## Phase 7: Nullsafe Operator (`?->`)

Replace ternary null-checks on object chains.

**`admin/custom-functions/flush_cache_on_page_edit.php`**
```php
// Before
$post_type_obj  = get_post_type_object( $post->post_type );
$post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;

// After
$post_type_name = get_post_type_object( $post->post_type )?->labels->singular_name
    ?? $post->post_type;
```

---

## Phase 8: Remove Empty Constructors

Remove constructors that have no parameters and an empty body.

**Files:**
- `admin/custom-functions/flush_batcache_for_particular_page.php` — `FlushObjectCachePageColumn::__construct()`
- `admin/custom-functions/flush_single_page_toolbar.php` — `PcmFlushCacheAdminbar::__construct()`

```php
// Before
public function __construct() {}

// After — just delete the method entirely
```

---

## Phase 9 (Optional — PHP 8.1+): Enums and Readonly

Only if you bump the minimum to PHP 8.1.

### 9.1 Enums for constant groups

**`includes/constants.php`** — `PCM_Options` contains string constants that could become a backed enum:
```php
// Before
class PCM_Options {
    const MAIN_OPTIONS     = 'pressable_cache_management';
    const FLUSH_TIMESTAMP  = 'pcm_last_flush_timestamp';
    // ...
}

// After (PHP 8.1+)
enum PCM_Option: string {
    case MainOptions    = 'pressable_cache_management';
    case FlushTimestamp = 'pcm_last_flush_timestamp';
    // ...
}
```

> **Note:** This is a large refactor since `PCM_Options::MAIN_OPTIONS` is referenced everywhere. Consider whether the benefit justifies the effort.

### 9.2 Readonly properties

Mark properties that are set once and never modified:

```php
// PHP 8.1+
class PCM_Cache_Buster_Event {
    public function __construct(
        public readonly string $category,
        public readonly string $signature,
        public readonly float $confidence,
        public readonly int $count,
    ) {}
}
```

### 9.3 Intersection types (PHP 8.1+)

If any interfaces or base classes overlap:
```php
function process( PCM_Cache_Buster_Detector_Interface&Countable $detector ): void {
```

### 9.4 Fibers (PHP 8.1+)

Not recommended unless the plugin needs async HTTP calls (e.g., edge cache purge fan-out). Current synchronous approach is fine for WordPress.

---

## Phase 10: Named Arguments (Optional)

Improve readability for functions with multiple optional params.

```php
// Before
pcm_verify_request( 'nonce', $nonce_action, 'GET', 'edit_posts' );

// After
pcm_verify_request(
    nonce_name: 'nonce',
    action: $nonce_action,
    method: 'GET',
    capability: 'edit_posts',
);
```

> **Warning:** Named arguments break if parameter names change. Only use where parameter names are stable.

---

## Checklist

- [x] **Phase 1** — Update version requirements in plugin header & readme.txt
- [x] **Phase 2** — Replace `strpos()` → `str_contains()` / `str_starts_with()` / `str_ends_with()` (~15 instances)
- [x] **Phase 3** — Replace `isset() ? :` → `??` (~40 instances)
- [x] **Phase 4** — Add parameter types, return types, and property types (~80+ declarations)
- [x] **Phase 5** — Constructor property promotion (~5 classes)
- [x] **Phase 6** — Match expressions (~3–5 locations)
- [x] **Phase 7** — Nullsafe operator (~2–3 locations)
- [x] **Phase 8** — Remove empty constructors (2 files)
- [x] **Phase 9** — (PHP 8.1+) Enums, readonly properties (intersection types N/A)
- [x] **Phase 10** — Named arguments for multi-param functions
- [ ] Run full test suite after each phase
- [ ] Test on PHP 8.1, 8.2, and 8.3

---

## Files Index (by change density)

| File | Phases | Est. Changes |
|------|--------|-------------|
| `admin/settings-callbacks.php` | 3, 4 | ~35 |
| `admin/settings-page.php` | 2, 3, 4, 6 | ~10 |
| `includes/cache-busters/detector-framework.php` | 2, 4, 5 | ~15 |
| `includes/cacheability-advisor/storage.php` | 2, 4, 5 | ~12 |
| `includes/smart-purge-strategy/strategy.php` | 3, 4, 5 | ~10 |
| `includes/object-cache-intelligence/intelligence.php` | 2, 4, 5 | ~10 |
| `includes/helpers.php` | 4 | ~6 |
| `admin/custom-functions/turn_on_off_edge_cache.php` | 3, 6 | ~5 |
| `admin/custom-functions/flush_batcache_for_particular_page.php` | 3, 8 | ~5 |
| `includes/redirect-assistant/assistant.php` | 2, 4 | ~5 |
| `includes/observability-reporting/reporting.php` | 4, 5 | ~4 |
| `includes/durable-origin-microcache/microcache.php` | 2, 4 | ~3 |
| `admin/custom-functions/flush_cache_on_page_edit.php` | 7 | ~2 |
| `admin/custom-functions/flush_single_page_toolbar.php` | 8 | ~1 |
| All other PHP files | 4 | ~1–3 each |

> **Do not touch** `plugin-update-checker/` — it's a third-party library.
