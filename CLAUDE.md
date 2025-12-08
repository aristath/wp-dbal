# CLAUDE.md - WP-DBAL Development Guide

This file provides guidance for AI assistants (like Claude) working on this codebase.

## Project Overview

WP-DBAL is a WordPress plugin that replaces the default `wpdb` class with a Doctrine DBAL-powered abstraction layer. This enables WordPress to work with multiple database backends (MySQL, PostgreSQL, SQLite, Cloudflare D1) instead of just MySQL.

## Architecture

```
WordPress ($wpdb->query)
        ↓
    db.php Drop-in (wp-content/db.php)
        ↓
    WP_DBAL_DB (extends wpdb)
        ↓
    QueryConverter (MySQL → Target SQL translation)
        ↓
    Doctrine DBAL Connection
        ↓
    Database Driver (MySQL | PostgreSQL | SQLite | D1)
```

### Key Components

| Component | Location | Purpose |
|-----------|----------|---------|
| `WP_DBAL_DB` | `includes/class-wp-dbal-db.php` | Main wpdb replacement, routes queries through DBAL |
| `QueryConverter` | `includes/Translator/QueryConverter.php` | Translates MySQL SQL to target dialect |
| `FunctionMapper` | `includes/Translator/FunctionMapper.php` | Maps MySQL functions to target equivalents |
| `D1/` | `includes/D1/` | Cloudflare D1 driver (REST API based) |
| `FileDB/` | `includes/FileDB/` | File-based storage driver (WIP) |

### Database Drivers

Each driver implements Doctrine DBAL interfaces:

- **Driver** (`Doctrine\DBAL\Driver`) - Entry point, creates connections
- **Connection** (`Doctrine\DBAL\Driver\Connection`) - Executes queries
- **Statement** (`Doctrine\DBAL\Driver\Statement`) - Prepared statements
- **Result** (`Doctrine\DBAL\Driver\Result`) - Query results

## Development Commands

```bash
# Install dependencies
composer install

# Check coding standards (PHPCS with WordPress rules)
composer check-cs

# Auto-fix coding standards
composer fix-cs

# Run PHPStan static analysis
composer phpstan

# Run all checks
composer check

# Run tests
composer test
composer test:unit
composer test:integration
```

## Coding Standards

- **PHP Version**: 8.1+
- **Style**: WordPress Coding Standards (WPCS) via PHPCS
- **Static Analysis**: PHPStan level defined in `phpstan.neon`
- **Namespacing**: `WP_DBAL\` prefix, PSR-4 autoloading from `includes/`

### Important Conventions

1. **Docblock types**: Use `integer` not `int`, `boolean` not `bool` in `@var` tags (WPCS requirement)
2. **Method names**: WordPress uses `snake_case` for wpdb methods; follow this for overrides
3. **Class names**: `WP_DBAL_DB` follows WordPress naming (not PSR, uses underscores)

## Configuration Constants

Define in `wp-config.php`:

```php
// Engine selection
define('DB_ENGINE', 'mysql');  // mysql, pgsql, sqlite, d1, filedb

// SQLite
define('DB_SQLITE_PATH', '/path/to/database.sqlite');

// PostgreSQL
define('DB_PORT', 5432);

// Cloudflare D1
define('DB_D1_ACCOUNT_ID', 'your-account-id');
define('DB_D1_DATABASE_ID', 'your-database-id');
define('DB_D1_API_TOKEN', 'your-api-token');

// FileDB (WIP)
define('DB_FILEDB_PATH', '/path/to/storage');
define('DB_FILEDB_FORMAT', 'json');  // or 'php'

// Advanced: Override all DBAL options
define('DB_DBAL_OPTIONS', [...]);
```

## Adding a New Database Driver

1. Create a new directory under `includes/` (e.g., `includes/NewDB/`)
2. Implement these classes:
   - `Driver.php` - Implements `Doctrine\DBAL\Driver`
   - `Connection.php` - Implements `Doctrine\DBAL\Driver\Connection`
   - `Statement.php` - Implements `Doctrine\DBAL\Driver\Statement`
   - `Result.php` - Implements `Doctrine\DBAL\Driver\Result`
3. Add a case in `WP_DBAL_DB::getConnectionParams()` for your engine
4. For SQLite-compatible databases, extend `AbstractSQLiteDriver`

## D1 Driver Notes

The D1 driver (`includes/D1/`) uses Cloudflare's REST API:

- **No transaction support**: Each query auto-commits
- **WordPress Playground compatible**: Uses `wp_safe_remote_post()` which Playground translates to `fetch()`
- **SQLite-based**: Extends `AbstractSQLiteDriver` for platform/exception handling

## Testing

Tests are in `tests/` with PHPUnit:

- `tests/Unit/` - Unit tests (no WordPress)
- `tests/bootstrap.php` - Test bootstrap

Run with:
```bash
composer test:unit
```

## File Structure

```
wp-dbal/
├── includes/
│   ├── class-wp-dbal-db.php    # Main wpdb replacement
│   ├── load.php                 # Loaded by db.php drop-in
│   ├── Translator/
│   │   ├── QueryConverter.php   # SQL translation
│   │   └── FunctionMapper.php   # Function mapping
│   ├── D1/                      # Cloudflare D1 driver
│   │   ├── Driver.php
│   │   ├── Connection.php
│   │   ├── Statement.php
│   │   ├── Result.php
│   │   └── HttpClient.php
│   └── FileDB/                  # File-based driver (WIP)
├── tests/
├── db.copy                      # Template for wp-content/db.php
├── wp-dbal.php                  # Plugin entry point
├── composer.json
├── phpcs.xml                    # PHPCS configuration
└── phpstan.neon                 # PHPStan configuration
```

## Common Tasks

### Checking a file for issues
```bash
composer check-cs -- path/to/file.php
composer phpstan -- --memory-limit=512M
```

### Adding SQL function translation
Edit `includes/Translator/FunctionMapper.php` to add mappings for new MySQL functions to target dialects.

### Debugging queries
Enable `WP_DEBUG` and `SAVEQUERIES` in `wp-config.php` to log translated queries.
