---
applyTo: '**'
---

# EngineScript Site Exporter — Development Standards

## Project Context

- **Plugin:** EngineScript Site Exporter (WordPress site export/backup plugin)
- **WordPress:** 6.6+ minimum
- **PHP:** 7.4+ minimum (use typed parameters, return types, short arrays `[]`, null coalescing `??=`)
- **License:** GPL-3.0-or-later
- **Text Domain:** `enginescript-site-exporter`
- **Function Prefix:** `sse_`
- **Constant Prefix:** `SSE_`
- **Work Environment:** Remote GitHub Codespaces only

Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) for PHP, JS, CSS, HTML, and accessibility.

## Security (Critical)

All input must be sanitized; all output must be escaped. No exceptions.

- **Input:** `sanitize_text_field()`, `sanitize_file_name()`, `absint()`, `wp_kses()`, `wp_verify_nonce()`
- **Output:** `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`
- **Forms:** `wp_nonce_field()` + `wp_verify_nonce()` for CSRF protection
- **Permissions:** `current_user_can( 'manage_options' )` before any sensitive operation
- **Database:** Use `$wpdb->prepare()` for any raw SQL (prefer WordPress APIs over raw queries)
- **File operations:** Validate paths with `realpath()`, prevent directory traversal (`..`), use WordPress Filesystem API
- **Prevent:** SQL injection, XSS, CSRF, LFI, path traversal, SSRF
- Auto-identify and fix security issues when found

## Code Quality

- Use WordPress APIs instead of raw PHP equivalents (e.g., `wp_mkdir_p()` not `mkdir()`)
- Use `add_action()` / `add_filter()` for all hook registrations
- Use `WP_Error` for error handling — log errors without exposing sensitive data
- Enqueue assets with `wp_enqueue_style()` / `wp_enqueue_script()` — no inline CSS or JS
- Internationalize all user-facing strings: `__()`, `_e()`, `esc_html__()`, `esc_attr__()`
- PHPDoc all functions with `@param`, `@return`, `@since` tags
- Remove dead code; keep functions focused and well-named

## Documentation & Versioning

**Changelogs:**
- Always update both CHANGELOG.md and readme.txt when making code changes
- Keep both changelogs in sync — use "Unreleased" section for ongoing changes

**Version Releases (only when explicitly instructed):**
- Follow semantic versioning (MAJOR.MINOR.PATCH)
- Update version in: plugin header, `ES_SITE_EXPORTER_VERSION` constant, README.md, readme.txt, CHANGELOG.md, GEMINI.md, `.pot` file header, and composer.json
- Move "Unreleased" entries to the new version section
- Never auto-update versions

**Internationalization:**
- Update `.pot` file when adding or modifying translatable strings
- Always use text domain `enginescript-site-exporter`

## Workflow

- Edit files in place — don't create duplicates
- Proceed automatically unless the action is destructive (data loss, deletion)
- Auto-identify and fix bugs when possible
- Create new files only when truly necessary
- Never create change-summary markdown files
- Keep responses concise and actionable