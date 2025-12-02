# WP-DBAL

Database abstraction layer for WordPress using Doctrine DBAL. Enables WordPress to work with multiple database backends including MySQL, PostgreSQL, SQLite, and custom storage backends.

## Features

- **Multiple Database Backends** - MySQL, PostgreSQL, SQLite support
- **Doctrine DBAL Integration** - Industry-standard database abstraction
- **Query Translation** - Automatic MySQL to target database translation using PHPMyAdmin/sql-parser
- **Drop-in Replacement** - Seamless replacement for WordPress's wpdb
- **Extensible** - Add custom database drivers for any storage backend

## Requirements

- PHP 8.1+
- WordPress 6.4+
- Composer

## Installation

1. Clone or download to `wp-content/plugins/wp-dbal`
2. Run `composer install`
3. Activate the plugin in WordPress admin
4. Install the db.php drop-in from Tools > WP-DBAL

## Configuration

Add to `wp-config.php`:

```php
// Database engine: mysql (default), pgsql, sqlite
define( 'DB_ENGINE', 'mysql' );

// For SQLite
define( 'DB_ENGINE', 'sqlite' );
define( 'DB_SQLITE_PATH', '/path/to/database.sqlite' );

// For PostgreSQL
define( 'DB_ENGINE', 'pgsql' );
define( 'DB_PORT', 5432 );

// Advanced: Custom DBAL options
define( 'DB_DBAL_OPTIONS', [
    'driver'   => 'pdo_pgsql',
    'host'     => 'localhost',
    'port'     => 5432,
    'dbname'   => 'wordpress',
    'user'     => 'root',
    'password' => '',
] );
```

## Architecture

```
WordPress ($wpdb->query)
        ↓
    db.php Drop-in
        ↓
    WP_DBAL_DB (extends wpdb)
        ↓
    SQL Parser (PHPMyAdmin/sql-parser)
        ↓
    Dialect Translator (MySQL → Target SQL)
        ↓
    Doctrine DBAL Connection
        ↓
    Database Driver (MySQL | PostgreSQL | SQLite)
```

## Development

### Testing

```bash
# Run all tests
composer test

# Run unit tests only
composer test:unit

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

### Code Quality

```bash
# Check PHP code style
composer check-cs

# Fix code style
composer fix-cs

# Run PHPStan
composer phpstan
```

## Supported SQL Translations

### SQLite

| MySQL | SQLite |
|-------|--------|
| `NOW()` | `datetime('now')` |
| `UNIX_TIMESTAMP()` | `strftime('%s', 'now')` |
| `CONCAT(a, b)` | `a \|\| b` |
| `IFNULL(a, b)` | `COALESCE(a, b)` |
| `RAND()` | `RANDOM()` |
| `IF(cond, t, f)` | `CASE WHEN cond THEN t ELSE f END` |
| `LIMIT 10, 20` | `LIMIT 20 OFFSET 10` |
| `INSERT IGNORE` | `INSERT OR IGNORE` |
| `REPLACE INTO` | `INSERT OR REPLACE INTO` |

### PostgreSQL

| MySQL | PostgreSQL |
|-------|------------|
| `NOW()` | `NOW()` |
| `UNIX_TIMESTAMP()` | `EXTRACT(EPOCH FROM NOW())::INTEGER` |
| `CONCAT(a, b)` | `CONCAT(a, b)` |
| `IFNULL(a, b)` | `COALESCE(a, b)` |
| `RAND()` | `RANDOM()` |
| `IF(cond, t, f)` | `CASE WHEN cond THEN t ELSE f END` |
| `AUTO_INCREMENT` | `SERIAL` / `BIGSERIAL` |
| `LIMIT 10, 20` | `LIMIT 20 OFFSET 10` |

## License

GPL-2.0-or-later

## Credits

- [Doctrine DBAL](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/)
- [PHPMyAdmin SQL Parser](https://github.com/phpmyadmin/sql-parser)
- [WordPress SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) (inspiration)
