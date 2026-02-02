# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ZPMS (Zeus Patient Management System) is a PHP web application for managing patient records, appointments, billing, and medical operations. It runs on the **Zeus Framework** (zeusfw), a custom MVC-style PHP CMS framework symlinked at `fw/` → `/var/www/html/apps/zeusfw`.

**Stack:** PHP 8.x, MySQL/MariaDB (PDO), ZETEM templating engine, vanilla JS, Boxicons

## Build & Development

There is no build system (no composer, npm, or Makefile). The app runs directly as a PHP application served by Apache with `.htaccess` URL rewriting.

- **Database credentials:** `config/db.php` (copy from `config/db.php.in`)
- **Database backup/restore:** `sql/msqldump.sh`, `sql/msql.sh`
- **No formal test framework** — test files in `web/test/` are manual HTML/PHP pages

## Architecture

### Request Flow

`web/index.php` is the single entry point:
1. Loads `config/db.php` and framework `fw/bootstrap.php`
2. Creates `Kernel` from YAML config in `config/`
3. `RouterClass` matches the URL against routes defined in `config/settings.info.yaml`
4. Matched route calls a handler function defined in `index.php`
5. Handler checks permissions via `SecurityClass::require()`, fetches data, returns `Renderer::render()` output
6. `Kernel::renderPage()` assembles regions (header, main_navigation, main_content, footer) and flushes output

### Key Directories

- `web/index.php` — entry point and all route handler functions
- `config/settings.info.yaml` — master config: routes, roles/permissions, menus, regions, CSS/JS libraries, module loading
- `config/site.info.yaml` — site metadata (title, timezone)
- `web/classes/` — auto-generated entity classes from YAML schemas in `web/classes/yaml/`
- `web/ClassesEx.php` — extended entity classes with custom query methods
- `web/modules/` — pluggable modules (each has `{name}.php`, `{name}.info.yaml`, `{name}.zetem`)
- `web/templates/` — ZETEM templates: `content/` for pages, `blocks/` for UI components, `modules/` for module templates
- `web/css/design/` — design system tokens, layout utilities, component styles
- `fw/core/` — framework: `kernel/`, `router/`, `db/dbal.php`, `templates/ZETEMTemplate.php`, `lib/Security.php`

### Configuration-Driven Design

Almost everything is configured in `config/settings.info.yaml`:
- **Routes:** URL patterns with `{id}` params, HTTP methods, handler function names, access permissions
- **Roles:** RBAC hierarchy — anonymous, authenticated, user, power-user, administrator (gets `all`)
- **Menus:** Nested menu items with multi-language text
- **Regions:** Page layout regions that modules render into
- **Libraries:** CSS/JS bundles loaded via `{% attach_library('name') %}` in templates
- **Modules:** List of modules to register from `web/modules/` and `fw/core/modules/`

### Database Layer

- `dbConnection` — PDO singleton (`fw/core/db/dbal.php`)
- `dbQuery` — fluent query builder with `.where()`, `.orderBy()`, `.limit()`, `.get()`
- `dbAbstractEntityClass` — base for all entities with `sgetById()`, `sgetAll()`, `sinsert()`, `supdate()`, `sdelete()`
- Entity classes are generated from YAML schemas in `web/classes/yaml/` with auto getters/setters
- Custom logic goes in `ClassEx` extensions (e.g., `patientsClassEx` with search methods)

### ZETEM Template Syntax

- Variables: `{{ $variable }}`, `{{ $obj->getField() }}`
- Filters: `{{ $date | date('Y-m-d') }}`
- Conditionals: `{% if $cond %}...{% elseif %}...{% else %}...{% endif %}`
- Loops: `{% for $item in $list %}...{% endfor %}` or `{% foreach($arr as $k => $v): %}...{% endforeach; %}`
- Include: `{% include 'template.zetem' %}`
- Library: `{% attach_library('library-name') %}`
- Set: `{% set $var = "value" %}`
- Comments: `{# comment #}`

### Module Pattern

Each module in `web/modules/{name}/` has three files:
- `{name}.info.yaml` — metadata
- `{name}.php` — class extending `moduleClass` with `render()` method, plus a `register_{name}_module()` function
- `{name}.zetem` — template

Modules are listed in `config/settings.info.yaml` under `modules.modules` and render into page regions.

### Adding a Route

1. Add route entry in `config/settings.info.yaml` with url, handler, method, access
2. Write the handler function in `web/index.php`
3. Create the template in `web/templates/content/`

### Security

- `SecurityClass::require('permission-name')` returns an error response if the user lacks the permission
- `SecurityClass::userLoggedIn()` checks authentication
- Permissions mapped to roles in `config/settings.info.yaml` under `roles:`

### Multi-Language

Default language is Greek (`gr`). Stored in `$_SESSION['CURRENT_LANGUAGE']`. Routes, menus, and content feeders support language keys (`en`, `gr`).

## Debug Helpers

- `echopre($var)` — formatted var_dump output
- Template cache in `web/cache/` — disabled by passing `false` to `Renderer::init()` in index.php
