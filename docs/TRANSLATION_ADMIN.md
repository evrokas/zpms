# Translation Management Interface

**Phase 11 Implementation - February 9, 2026**

## Overview

The Translation Management Interface provides a comprehensive web-based admin panel for managing dictionary translations in ZPMS. It allows administrators to view, edit, import, and export translations for all supported languages.

## Features

### 1. Dashboard with Statistics

- **Real-time statistics** for each language
- **Translation coverage** percentage
- **Translated vs untranslated** counts
- **Visual KPI cards** with icons

### 2. Translation Listing

- **Paginated table** showing all dictionary entries
- **Multi-language columns** (en, el, etc.)
- **Search functionality** across all languages
- **Filter by language** and status (translated/untranslated)
- **50 entries per page** with pagination controls

### 3. Inline Editing

- **Click-to-edit** any translation cell
- **AJAX auto-save** on blur or Enter
- **ESC to cancel** editing
- **Visual feedback** during editing
- **Instant updates** without page reload

### 4. Import/Export

#### Export Options:
- **CSV format** - Compatible with Excel/Google Sheets
- **YAML format** - For version control and programmatic use
- **Single language** or **all languages**
- **Timestamped filenames**

#### Import Options:
- **CSV import** - Bulk translation updates
- **YAML import** - From external translation services
- **Language selection**
- **Progress feedback**
- **Error reporting**

### 5. Security

- **Permission-based access** - `administer_translations` permission
- **Role restrictions** - Administrator and Power-user only
- **AJAX CSRF protection** (via session)
- **File upload validation**

## User Interface

### Main Components

1. **Header Bar**
   - Title: "Translation Management"
   - Import button (with upload icon)
   - Export button (with download icon)

2. **Statistics Cards**
   - One card per language
   - Shows: Language name, translated count, coverage percentage
   - Color-coded icons

3. **Filter Bar**
   - Search input (searches across all languages)
   - Language dropdown filter
   - Status dropdown (All/Translated/Untranslated)
   - Apply Filters button

4. **Translation Table**
   - Columns: ID, Language1, Language2, ..., Actions
   - Editable cells with click-to-edit
   - Hover actions (edit button)
   - Pagination controls at bottom

5. **Modals**
   - Export Modal: Select language and format
   - Import Modal: Upload file, select language and format

## Usage

### Accessing the Interface

1. Log in as Administrator or Power-user
2. Navigate to **Settings** → **Translations**
3. Or access directly: `/admin/translations`

### Editing Translations

**Method 1: Inline Editing**
1. Click on any translation cell
2. Type the new translation
3. Press Enter or click outside to save
4. Press ESC to cancel

**Method 2: Bulk Import**
1. Click "Import" button
2. Select language and format (CSV/YAML)
3. Upload file
4. Review import results

### Exporting Translations

1. Click "Export" button
2. Select language (or "All Languages")
3. Select format (CSV or YAML)
4. Click "Export" to download

### Searching and Filtering

1. Enter search term in search box
2. Select language to filter (optional)
3. Select status to filter (optional)
4. Click "Apply Filters"
5. Results update in table

## CSV Format

### Export Format

```csv
ID,Token,en,el
1,Home,Home,Αρχική
2,Patients,Patients,Ασθενείς
3,Settings,Settings,Ρυθμίσεις
```

### Import Format

**For specific language:**
```csv
ID,Token,English,Greek
1,Home,Home,Αρχική (NEW)
2,Patients,Patients,Ασθενείς (UPDATED)
```

The import uses the **last column** as the translation value for the selected language.

## YAML Format

### Export Format

```yaml
# Dictionary export for language: en
# Generated: 2026-02-09 10:30:00
# Total entries: 150

dictionary:
  "Home": "Home"
  "Patients": "Patients"
  "Settings": "Settings"
```

### Import Format

```yaml
dictionary:
  "Home": "Home"
  "Patients": "Patients"
  "Settings": "Settings"
```

## API Endpoints

### GET /admin/translations
Main dashboard page (renders template)

### GET /admin/translations/list
**Query Parameters:**
- `search` - Search term
- `language` - Language code filter
- `status` - Status filter (translated/untranslated)
- `page` - Page number
- `per_page` - Results per page (default: 50)

**Response:**
```json
{
  "tokens": [...],
  "total": 150,
  "page": 1,
  "per_page": 50,
  "total_pages": 3
}
```

### POST /admin/translations/update
**Body:**
```json
{
  "token": "Home",
  "language": "el",
  "translation": "Αρχική"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Translation updated successfully"
}
```

### GET /admin/translations/export
**Query Parameters:**
- `language` - Language code or "all"
- `format` - "csv" or "yaml"

**Response:** File download

### POST /admin/translations/import
**Form Data:**
- `file` - Uploaded file
- `language` - Target language code
- `format` - "csv" or "yaml"

**Response:**
```json
{
  "imported": 45,
  "failed": 2,
  "total": 47
}
```

## Implementation Details

### Files Created

1. **Module Files:**
   - `/web/modules/translation_admin/translation_admin.info.yaml`
   - `/web/modules/translation_admin/translation_admin.php`
   - `/web/modules/translation_admin/translation_admin.css`

2. **Template:**
   - `/web/templates/content/translations_admin.zetem`

3. **Routes:** Added to `/config/settings.info.yaml`
   - `translations_admin` - Main page
   - `translations_list` - AJAX listing
   - `translations_update` - AJAX update
   - `translations_export` - Export download
   - `translations_import` - Import upload

4. **Handlers:** Added to `/web/index.php`
   - `handle_translations_admin()`
   - `handle_translations_list()`
   - `handle_translations_update()`
   - `handle_translations_export()`
   - `handle_translations_import()`

5. **Translations:** Added to `/config/translations/`
   - `en.yaml` - English strings
   - `el.yaml` - Greek strings

6. **Permission:** Added to `/config/settings.info.yaml`
   - `administer_translations` permission
   - Assigned to `administrator` and `power-user` roles

### Backend Methods Used

From `dictionaryClassEx` (Phase 4):
- `getAllTokens()` - Get all entries
- `updateTranslation()` - Update single translation
- `deleteToken()` - Delete entry
- `getUntranslated()` - Get missing translations
- `exportToYAML()` - Export to YAML
- `importFromYAML()` - Import from YAML
- `getTranslationStats()` - Get statistics
- `getRecentTokens()` - Get recent entries

### Frontend Technologies

- **Vanilla JavaScript** - No dependencies
- **AJAX/Fetch API** - Dynamic loading and updates
- **CSS Grid/Flexbox** - Responsive layout
- **Design System** - Uses ZPMS design tokens
- **Boxicons** - Icon library

## Best Practices

### For Translators

1. **Use consistent terminology** across languages
2. **Maintain context** when translating
3. **Test translations** in the UI after updating
4. **Use placeholders** correctly: `{name}`, `{count}`, etc.
5. **Keep lengths similar** to avoid layout issues

### For Developers

1. **Add new tokens** to YAML files first (version controlled)
2. **Use database** only for dynamic/user-generated content
3. **Test imports** on staging before production
4. **Backup dictionary** before bulk operations
5. **Use descriptive token keys**: `admin.translations.title` not `t1`

## Troubleshooting

### Translations Not Appearing

1. Check if token exists in dictionary (use search)
2. Verify language flag is set (should auto-set on import)
3. Clear template cache if enabled
4. Check `prefer_database` setting in config

### Import Failures

1. Verify CSV/YAML format matches expected structure
2. Check file encoding (UTF-8 required)
3. Review import results for specific error messages
4. Ensure language code is valid

### Permission Errors

1. Verify user has `administer_translations` permission
2. Check role assignment (administrator or power-user)
3. Clear session and re-login

## Future Enhancements

Possible additions for Phase 13:
- **Translation history/versioning** - Track changes
- **Content translation** - Translate entity fields
- **Machine translation integration** - Google Translate API
- **Translation memory** - Suggest similar translations
- **Collaboration features** - Multi-user editing
- **Language completeness reports** - Detailed coverage analysis

## Related Documentation

- `docs/URL_LANGUAGE_DETECTION.md` - Language detection system
- `docs/LANGUAGE_SWITCHER.md` - Language switcher (Phase 10)
- `fw/core/dictionaryClassEx.php` - Backend methods
- `config/settings.info.yaml` - Configuration reference

---

**Implementation Date:** February 9, 2026
**Status:** ✅ Complete
**Phase:** 11 of 13
**Estimated Time:** 8-10 hours
**Actual Time:** ~6 hours
