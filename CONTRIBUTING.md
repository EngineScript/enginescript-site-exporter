# Contributing to EngineScript Site Exporter

Thank you for considering a contribution to EngineScript Site Exporter. This guide keeps development, code quality, and review expectations aligned with this plugin.

## Code of Conduct

This project follows the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/). By participating, you are expected to uphold this code.

## Development Environment

### Requirements

- **PHP**: 8.2 or higher, 64-bit, with ZipArchive and gzip (zlib) support
- **WordPress**: 7.0 or higher
- **WP-CLI**: Required for database exports when testing on a WordPress site
- **Composer**: For dependency management and quality tools
- **Node.js**: 22.22.2 or higher, for the JavaScript tests and linters only
- **Git**: For version control

### Setup

1. Fork the repository on GitHub.
2. Clone your fork locally:

   ```bash
   git clone https://github.com/YOUR-USERNAME/enginescript-site-exporter.git
   cd enginescript-site-exporter
   ```

3. Install dependencies:

   ```bash
   composer install
   npm install
   ```

4. Create a feature branch:

   ```bash
   git checkout -b feature/your-feature-name
   ```

## Coding Standards

This project follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) for PHP, JavaScript, CSS, HTML, and accessibility.

Project-owned JavaScript should use modern ES2025+ syntax for the WordPress 7.0+ browser support baseline. Prefer native DOM APIs, `const`/`let`, arrow functions, optional chaining, nullish coalescing, template literals, and modules where appropriate. Use legacy JavaScript patterns only when a WordPress integration requires them.

### Key Principles

1. **Security First**: Validate and sanitize input, escape output, protect forms with nonces, check capabilities before privileged actions, and keep file paths inside the plugin's own export directory.
2. **Performance**: Use WordPress caching mechanisms, avoid unnecessary database work, and enqueue assets only where needed.
3. **Internationalization**: Mark user-facing strings with WordPress i18n functions and use the `enginescript-site-exporter` text domain.
4. **Accessibility**: Use semantic markup and preserve keyboard and screen reader behavior in admin UI changes.

## Code Quality Tools

Run the main checks before submitting a pull request:

```bash
composer run phpcs
composer run phpstan
composer run phpmd
composer run psalm
composer test
npm test
```

`composer run check-all` runs the PHP checks in one go.

### Test Layers

- **Unit tests** (`tests/unit`, run by `composer test`): PHPUnit tests that run without WordPress. `tests/bootstrap.php` defines stand-ins for the WordPress functions the plugin calls, and `tests/SseTestCase.php` is the base class of the unit tests. Code that needs a real database, a real WordPress filesystem object, scheduled events, or a child process is left to the integration tests.
- **WordPress integration tests**: the compatibility workflow writes this suite into `tests/integration` on the runner and runs it against real WordPress versions. It is not kept in the repository.
- **JavaScript tests** (`tests/js`, run by `npm test`): Node.js test runner tests for the admin script. `tests/js/render-admin-page.php` builds the page markup with the plugin's own PHP, and the real `js/admin.js` runs inside jsdom. PHP must be on the `PATH`.

## File Structure

```text
enginescript-site-exporter/
|-- enginescript-site-exporter.php      # Main plugin file
|-- includes/                           # Plugin PHP
|   |-- admin-page.php
|   |-- archive.php
|   |-- cleanup.php
|   |-- download.php
|   |-- export.php
|   |-- helpers.php
|   |-- lease.php
|   |-- process.php
|   |-- security.php
|   `-- tar.php
|-- css/                                # Admin CSS
|-- js/                                 # Admin JavaScript
|-- languages/                          # Translation template
|   `-- enginescript-site-exporter.pot
|-- tests/
|   |-- bootstrap.php                   # WordPress stand-ins for the unit and JavaScript tests
|   |-- SseTestCase.php                 # Base class of the unit tests
|   |-- unit/                           # PHPUnit unit tests
|   `-- js/                             # JavaScript tests
|-- stubs/                              # Static-analysis stubs
|-- README.md                           # Project documentation
|-- readme.txt                          # Plugin readme; the only readme in the release package
|-- CHANGELOG.md                        # Version history
|-- CONTRIBUTING.md                     # This file
|-- SECURITY.md                         # Security policy
|-- LICENSE                             # GPL license
|-- composer.json                       # PHP dependencies and check scripts
|-- package.json                        # JavaScript test and linter dependencies
|-- phpcs.xml                           # PHPCS configuration
|-- phpstan.neon                        # PHPStan configuration
|-- phpmd.xml                           # PHPMD configuration
|-- psalm.xml                           # Psalm configuration
|-- phpunit.xml                         # PHPUnit configuration
|-- .distignore                         # Files left out of the release package
`-- .github/                            # GitHub workflows and templates
```

The release package holds the main plugin file, `readme.txt`, `LICENSE`, `includes/`, `css/`, `js/`, and `languages/`. When you add a top-level file or directory, add it to `.distignore` and `.gitattributes` unless it must ship; `.github/scripts/check-plugin-package.py` fails when the package lists disagree.

## Making Changes

### Before You Start

1. Check existing [issues](https://github.com/EngineScript/enginescript-site-exporter/issues) and [pull requests](https://github.com/EngineScript/enginescript-site-exporter/pulls).
2. Create an issue for significant behavior changes.
3. Follow the existing code patterns in the relevant `includes/` file.

### Code Requirements

- **Input validation**: Validate all user input before use.
- **Output escaping**: Use `esc_html()`, `esc_attr()`, `esc_url()`, or another context-appropriate escaping function.
- **Sanitization**: Use WordPress sanitizers such as `sanitize_text_field()`, `sanitize_textarea_field()`, and `absint()`.
- **Nonce verification**: Use WordPress nonces for forms and state-changing requests.
- **Capability checks**: Verify permissions with `current_user_can()`.
- **Prefixes**: Start new PHP functions, global variables, and constants with `esse_` or `ESSE_`.
- **PHPDoc**: Add `@param`, `@return`, and `@since` tags for new public functions and methods.

### Example Function

```php
/**
 * Example function with proper documentation.
 *
 * @since 2.1.3
 * @param string $input User input to process.
 * @return string Sanitized output.
 */
function esse_example_function( string $input ): string {
    if ( ! current_user_can( 'manage_options' ) ) {
        return '';
    }

    $sanitized = sanitize_text_field( $input );

    return esc_html( $sanitized );
}
```

### Testing

1. **Manual testing**:

   - Test with WordPress 7.0 or higher.
   - Test with PHP 8.2 or higher.
   - Verify the export screen at **Tools > Site Exporter**, and at **Settings > Site Exporter** in Network Admin on multisite.
   - Verify creating, downloading, and deleting an export, including the database export through WP-CLI.
   - Verify that scheduled cleanup removes an export, and that deactivation removes every export archive and scheduled event.

2. **Automated testing**:

   - Run PHPCS for coding standards.
   - Run PHPStan and Psalm for static analysis.
   - Run PHPMD for code quality checks.
   - Run the PHPUnit unit tests with `composer test`.
   - Run the JavaScript tests with `npm test`.
   - Run the JavaScript, Markdown and CSS linters with `npm run lint:js`, `npm run lint:md` and `npm run lint:css`.
   - The lint job also runs yamllint, actionlint, zizmor, Ruff and mypy on the workflows and the scripts in `.github`; `.github/lint-requirements.txt` pins their versions.
   - The compatibility workflow on GitHub runs all of these, and the WordPress integration tests, on every push and pull request.

## Submitting Changes

### Pull Request Process

1. Create a feature branch:

   ```bash
   git checkout -b feature/description-of-change
   ```

2. Make your changes:

   - Follow WordPress coding standards.
   - Add or update tests where useful.
   - Update documentation when behavior changes.

3. Run checks:

   ```bash
   composer run check-all
   ```

4. Commit your changes:

   ```bash
   git add .
   git commit -m "fix: keep export cleanup on schedule"
   ```

5. Push and open a pull request:

   ```bash
   git push origin feature/description-of-change
   ```

### Commit Message Format

Use [Conventional Commits](https://conventionalcommits.org/):

- `feat:` New features
- `fix:` Bug fixes
- `docs:` Documentation changes
- `style:` Code style changes
- `refactor:` Code refactoring
- `test:` Test additions or changes
- `chore:` Maintenance tasks

## Pull Request Checklist

- [ ] Code follows WordPress coding standards
- [ ] Public functions and methods have useful PHPDoc
- [ ] Security best practices are implemented
- [ ] PHPCS, PHPStan, PHPMD, Psalm, PHPUnit, and JavaScript test checks pass
- [ ] Manual testing is complete
- [ ] Documentation is updated if needed
- [ ] CHANGELOG.md and readme.txt are updated if behavior changes

## Version Management

When releasing new versions, update these files:

- `enginescript-site-exporter.php` plugin header and the `ESSE_VERSION` constant
- `includes/` version-dependent UI copy if needed
- `README.md`, including the version badge and its download link
- `readme.txt` stable tag and changelog
- `CHANGELOG.md`
- `languages/enginescript-site-exporter.pot`

This project follows [Semantic Versioning](https://semver.org/):

- **MAJOR**: Breaking changes
- **MINOR**: New features
- **PATCH**: Bug fixes

## Translation Template

Use the literal `enginescript-site-exporter` text domain for user-facing strings. Add translator comments for placeholders, use numbered placeholders when there is more than one, and escape translated text for its output context.

The translation template, `languages/enginescript-site-exporter.pot`, is generated locally and committed; no workflow regenerates it. Before committing a change to a shipped PHP or JavaScript file, run this from the repository root with WP-CLI's `i18n` command available:

```bash
composer make-pot
```

The script scans the shipped PHP and JavaScript files and names the GitHub issue tracker as the template's bug report address. When only the creation date would change, it puts the committed template back, so it is safe to run every time. Review message text, source references, translator notes, and plural forms in the generated diff, and commit the template with the change. A POT is a translation template; it does not provide a translated interface.

## Support Channels

- **Issues**: [GitHub Issues](https://github.com/EngineScript/enginescript-site-exporter/issues)
- **Security**: Report security issues privately through the [vulnerability report form](https://github.com/EngineScript/enginescript-site-exporter/security/advisories/new); see [SECURITY.md](SECURITY.md)

## Resources

- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [WordPress Security Guidelines](https://developer.wordpress.org/plugins/security/)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer)
- [PHPStan](https://phpstan.org/)
- [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/)

## License

By contributing to EngineScript Site Exporter, you agree that your contributions will be licensed under [GPL-3.0-or-later](LICENSE).
