# URL-Based Language Detection

## Overview

ZPMS now supports automatic language detection from URL path prefixes, allowing clean multilingual URLs like:
- `/en/admin` - English admin page
- `/el/login` - Greek login page
- `/admin` - Language-agnostic (uses fallback detection)

## How It Works

### URL Structure

**With Language Prefix:**
```
/[lang]/[path]
```

**Examples:**
- `/en/patients` - Patients page in English
- `/el/patients/list` - Patient list in Greek
- `/en/appointment/123/edit` - Edit appointment in English

**Without Language Prefix:**
```
/[path]
```

**Examples:**
- `/patients` - Uses fallback detection (session, cookie, browser, etc.)
- `/admin` - Uses fallback detection
- `/login` - Uses fallback detection

### Detection Priority

Language is detected in the following order:

1. **URL Prefix** (`/en/page`) - Highest priority
2. **Session** (`$_SESSION['CURRENT_LANGUAGE']`)
3. **Cookie** (`user_lang` cookie)
4. **User Profile** (Database-stored preference for authenticated users)
5. **Query Parameter** (`?lang=en`)
6. **Browser** (`HTTP_ACCEPT_LANGUAGE` header)
7. **Default** (Configured default language: `el`)

### How URLs Are Processed

1. **Request arrives:** `/en/admin`
2. **Language extracted:** `en` detected and set in session
3. **URL rewritten:** `/admin` (prefix removed)
4. **Router matches:** Route for `/admin` is matched normally
5. **Content rendered:** In English (from session)

## Usage Examples

### Navigation Links

**Static HTML:**
```html
<!-- English version -->
<a href="/en/patients">Patients</a>
<a href="/en/appointments">Appointments</a>

<!-- Greek version -->
<a href="/el/patients">Ασθενείς</a>
<a href="/el/appointments">Ραντεβού</a>

<!-- Language-agnostic (uses current language) -->
<a href="/patients">Patients/Ασθενείς</a>
```

**ZETEM Templates:**
```zetem
{# Generate language-specific URLs #}
<a href="/{{ $currentLang }}/patients">{{ 'nav.patients' | t }}</a>

{# Or use language-agnostic URLs #}
<a href="/patients">{{ 'nav.patients' | t }}</a>
```

### Language Switcher

**HTML Example:**
```html
<div class="language-switcher">
    <a href="/en{{ $currentPath }}">English</a>
    <a href="/el{{ $currentPath }}">Ελληνικά</a>
</div>
```

**ZETEM Example:**
```zetem
<div class="language-switcher">
    {% foreach(['en', 'el'] as $lang): %}
        <a href="/{{ $lang }}{{ $_SERVER['REQUEST_URI'] }}">
            {{ $lang | upper }}
        </a>
    {% endforeach %}
</div>
```

### Forms and POST Requests

Forms work automatically - the language is maintained in the session:

```html
<!-- Form at /en/patient/new -->
<form action="/patient/new" method="post">
    <!-- Language is 'en' from session, no need for prefix -->
    <input name="first_name" placeholder="First Name">
    <button type="submit">Save</button>
</form>
```

## Backward Compatibility

✅ **All existing URLs continue to work!**

- `/patients` - Works (uses fallback detection)
- `?lang=en` - Still works (query parameter detection)
- No changes required to existing code

## URL Generation Helpers

### PHP Functions

```php
// Get current language
$lang = $kernel->getCurrentLanguage(); // 'en' or 'el'

// Generate language-specific URL
$url = '/' . $lang . '/patients';

// Remove language from URL
$detector = $kernel->getLanguageDetector();
$cleanPath = $detector->removeLanguageFromPath('/en/patients'); // '/patients'

// Add language to URL
$langPath = $detector->addLanguageToPath('/patients', 'en'); // '/en/patients'
```

### ZETEM Templates

```zetem
{# Get current language #}
{% set $lang = $kernel->getCurrentLanguage() %}

{# Build language-specific link #}
<a href="/{{ $lang }}/admin">{{ 'nav.admin' | t }}</a>
```

## Configuration

### Enable URL Detection

In `config/settings.info.yaml`:

```yaml
language_detection:
  default: el
  priority:
    - url       # ← URL prefix detection (highest priority)
    - session
    - cookie
    - user
    - query
    - browser
    - default
```

### Supported Languages

```yaml
languages:
  en:
    name: English
    native_name: English
    direction: ltr
    locale: en_US
    enabled: true
  el:
    name: Greek
    native_name: Ελληνικά
    direction: ltr
    locale: el_GR
    enabled: true
```

## SEO Considerations

### Canonical URLs

Use canonical tags to indicate the primary version:

```html
<link rel="canonical" href="https://example.com/en/patients">
```

### Hreflang Tags

Indicate alternate language versions:

```html
<link rel="alternate" hreflang="en" href="https://example.com/en/patients">
<link rel="alternate" hreflang="el" href="https://example.com/el/patients">
<link rel="alternate" hreflang="x-default" href="https://example.com/patients">
```

### Sitemaps

Include all language versions in sitemap.xml:

```xml
<url>
  <loc>https://example.com/en/patients</loc>
  <xhtml:link rel="alternate" hreflang="el" href="https://example.com/el/patients"/>
</url>
<url>
  <loc>https://example.com/el/patients</loc>
  <xhtml:link rel="alternate" hreflang="en" href="https://example.com/en/patients"/>
</url>
```

## Testing

### Test URLs

Try these URLs to verify functionality:

1. **English:** `http://your-domain/en/patients`
2. **Greek:** `http://your-domain/el/patients`
3. **No prefix:** `http://your-domain/patients`
4. **With query param:** `http://your-domain/patients?lang=en`

### Expected Behavior

- `/en/patients` → Displays in English, URL shows `/en/patients`
- `/el/patients` → Displays in Greek, URL shows `/el/patients`
- `/patients` → Displays in detected language (session/cookie/browser)
- Language persists across page navigation within same session

## Troubleshooting

### Issue: Language Not Detected

**Check:**
1. Language code is valid (2 letters: `en`, `el`)
2. Language is enabled in configuration
3. URL format is correct: `/{lang}/{path}`

### Issue: Routes Not Matching

**Solution:**
The language prefix is automatically removed before route matching. If routes aren't matching, check:
1. Route definitions don't include language prefix
2. Routes are defined without leading language code

### Issue: URL Keeps Redirecting

**Solution:**
Don't set language in URL if it's already set. Check if you're adding language prefix twice.

## Best Practices

1. **Use language-specific URLs for public pages** (better for SEO)
2. **Use language-agnostic URLs for authenticated pages** (simpler)
3. **Maintain language across navigation** (include language in links)
4. **Provide language switcher** (let users choose)
5. **Use hreflang tags** (help search engines)

## Migration Path

Existing sites can adopt URL-based detection gradually:

1. **Phase 1:** Enable URL detection, keep existing behavior
2. **Phase 2:** Add language-specific links for new pages
3. **Phase 3:** Update existing links to include language prefix
4. **Phase 4:** Add SEO meta tags (hreflang, canonical)

No breaking changes - all existing URLs continue to work!
