# Enhanced Language Switcher

**Phase 10 Implementation - February 9, 2026**

## Overview

The enhanced language switcher provides two modes for language selection:
1. **AJAX Mode** - Existing behavior with AJAX POST and page reload
2. **Query Parameter Mode** - NEW: URL-based switching with `?lang=` parameter

## Features

### Query Parameter Detection & Persistence

When a user accesses a URL with `?lang=en` or `?lang=el`, the language selection is automatically:
- Detected by `LanguageDetector`
- Stored in session (`$_SESSION['CURRENT_LANGUAGE']`)
- Persisted in cookie (`user_lang`, 1-year expiry)
- Applied immediately to the current page

### Dual-Mode Support

The language selector module now supports two configurable modes:

#### AJAX Mode (Backward Compatible)
- Click flag → AJAX POST to `/language_select` → Page reload
- Existing behavior preserved
- No URL changes

#### Query Parameter Mode (NEW)
- Click flag → Navigate to `current_page?lang=code`
- URL-based, shareable language selection
- Preserves current page path and existing query parameters
- Works without JavaScript (progressive enhancement)

## Configuration

### settings.info.yaml

```yaml
language_switcher:
  mode: query_param          # 'ajax' or 'query_param'
  preserve_page: true        # Keep current page path when switching
  show_in_header: true       # Display in header region
```

### Mode Selection

**Use `ajax` mode if:**
- You want the existing behavior
- You need to perform additional actions on language switch
- You prefer no URL changes

**Use `query_param` mode if:**
- You want shareable language-specific URLs
- You prefer clean, URL-based language selection
- You want to support users without JavaScript

## Implementation Details

### Modified Files

#### 1. `fw/core/lib/LanguageDetector.php`
**Change:** Enhanced `detectFromQueryParameter()` to persist selection

```php
private function detectFromQueryParameter() {
    $lang = $_GET['lang'] ?? null;

    if ($lang && $this->isValidLanguage($lang)) {
        // NEW: Persist to session and cookie
        $this->persistLanguageSelection($lang);
    }

    return $lang;
}

private function persistLanguageSelection($lang) {
    $_SESSION['CURRENT_LANGUAGE'] = $lang;
    $cookieExpiry = time() + (365 * 24 * 60 * 60);
    setcookie('user_lang', $lang, $cookieExpiry, '/', '', false, true);
}
```

#### 2. `fw/core/kernel/utils.php`
**New Functions:**

```php
/**
 * Get current URL with language parameter
 * Example: /patients → /patients?lang=en
 *          /patients?search=foo → /patients?search=foo&lang=en
 */
function get_current_url_with_lang(string $lang): string

/**
 * Get current URL without language parameter
 * Example: /patients?lang=en&search=foo → /patients?search=foo
 */
function get_current_url_without_lang(): string
```

#### 3. `fw/core/modules/language_selector/language_selector.php`
**Changes:**
- Read `language_switcher` configuration
- Pass `mode`, `urls`, and `preserve_page` to template
- Generate language-specific URLs for query_param mode

#### 4. `fw/core/templates/modules/language_selector.zetem`
**Changes:**
- Conditional rendering based on `$mode`
- In `query_param` mode: wrap flags in `<a>` tags with `href="{{ $urls[$lang] }}"`
- In `ajax` mode: keep existing flag-only structure

#### 5. `fw/core/modules/language_selector/js/language_selector.js`
**Changes:**
- Check `data-mode` attribute on `.language-selector`
- Only attach AJAX handlers in `ajax` mode
- In `query_param` mode: allow normal link behavior

## Usage Examples

### Template Usage

The language selector is typically rendered in the header:

```zetem
{# In your page template #}
<header>
  {% include 'modules/language_selector.zetem' %}
</header>
```

### Direct URL Access

Users can now share language-specific URLs:

```
https://example.com/patients?lang=en
https://example.com/patients/42?lang=el
https://example.com/reports?status=active&lang=en
```

### Programmatic Usage

Generate language-specific URLs in your code:

```php
// In a route handler or template
$englishUrl = get_current_url_with_lang('en');
$greekUrl = get_current_url_with_lang('el');
```

## Testing

### Test Cases

1. **Query Parameter Detection**
   - Access `/patients?lang=en` → Page displays in English
   - Access `/patients?lang=el` → Page displays in Greek
   - Invalid language (`?lang=xx`) → Fallback to default

2. **Persistence**
   - Set language via query param → Check session
   - Set language via query param → Check cookie
   - Reload page without param → Language persists

3. **Query Param Mode**
   - Click English flag → URL updates to `?lang=en`
   - Click Greek flag → URL updates to `?lang=el`
   - Page state preserved (search filters, pagination, etc.)

4. **AJAX Mode**
   - Click flag → AJAX request sent
   - Server responds → Page reloads
   - Language changed, no URL change

5. **URL Preservation**
   - On `/patients?search=john`, click flag → `/patients?search=john&lang=en`
   - Multiple params preserved: `/patients?search=john&status=active&lang=el`

## Migration from AJAX to Query Param Mode

To switch from AJAX mode to query parameter mode:

1. Update `config/settings.info.yaml`:
   ```yaml
   language_switcher:
     mode: query_param  # Changed from 'ajax'
   ```

2. Clear template cache (if enabled)

3. Test language switching on key pages

4. Update any custom JavaScript that depends on AJAX mode

## Benefits of Query Param Mode

1. **Shareable URLs** - Users can share language-specific links
2. **SEO Friendly** - Search engines can index language-specific pages
3. **Progressive Enhancement** - Works without JavaScript
4. **Browser History** - Back button respects language changes
5. **Cleaner Architecture** - No AJAX endpoint needed

## Backward Compatibility

- **Default mode is `ajax`** if not configured
- Existing installations continue to work without changes
- AJAX mode fully preserved for existing behavior
- No breaking changes to API or templates

## Future Enhancements

Possible additions in later phases:
- URL path prefix mode (`/en/page`, `/el/page`)
- Cookie-only mode (no session)
- Per-route language overrides
- Language-specific redirects

## Related Documentation

- `docs/URL_LANGUAGE_DETECTION.md` - Language detection system
- `docs/MULTILINGUAL_SYSTEM.md` - Translation system overview
- `config/settings.info.yaml` - Configuration reference

---

**Implementation Date:** February 9, 2026
**Status:** ✅ Complete
**Phase:** 10 of 13
