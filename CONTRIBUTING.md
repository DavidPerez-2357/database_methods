# Contributing to DatabaseMethods

Thank you for your interest in contributing! This guide explains how to set up a local development environment, run the test suite, and follow the project's coding guidelines.

&emsp;

## Local Development Setup

1. **Clone the repository**

   ```bash
   git clone https://github.com/DavidPerez-2357/DatabaseMethods.git
   cd DatabaseMethods
   ```

2. **Requirements**

   - PHP **5.4** or higher (no Composer, no external dependencies).
   - The SQLite PDO driver (`php-sqlite3` / `pdo_sqlite`) for the integration tests.

3. **Verify your PHP installation**

   ```bash
   php -v
   php -m | grep -i sqlite
   ```

&emsp;

## Running Tests

The project ships with a custom, dependency-free test runner. From the **repository root**, run:

```bash
php tests/run.php
```

- **`tests/QueryTests.php`** – unit tests for the `Query` SQL builder.
- **`tests/DatabaseTest.php`** – integration tests for the `Database` PDO wrapper (uses SQLite by default).
- **`tests/SqlValidatorTests.php`** – unit tests for SQL identifier/expression validation.
- **`tests/SqlDialectTests.php`** – unit tests for dialect quoting/pagination behavior.
- **`tests/QueryRunTests.php`** – focused integration tests for `Query::run()` execution flow.

The runner exits with code `0` when every test passes and `1` when any test fails.

&emsp;

## Coding Guidelines

- **PHP 5.4+ compatibility** – avoid language features introduced after PHP 5.4 (e.g., variadic functions / argument unpacking, scalar type declarations, `Throwable`, return-type hints).
- **No external dependencies** – the library must work with a plain `require_once` and zero Composer packages.
- **Fluent API style** – new `Query` setter methods should mutate the current instance, return `$this`, and invalidate the cached SQL string by setting `$this->query = null` so the query is rebuilt lazily on the next call to `getQuery()` / `__toString()`.
- **Validate identifiers** – any table name or column name that is interpolated directly into SQL must pass through `validateIdentifier()` or `validateUnqualifiedIdentifier()` to prevent SQL injection.
- **Follow existing file structure** – driver classes live in `src/drivers/`, core classes in `src/`. The single entry-point `DatabaseMethods.php` loads everything automatically.
- **Add or update tests** – every new public method or behaviour change should be accompanied by a test in `tests/QueryTests.php` (for `Query`) or `tests/DatabaseTest.php` (for `Database`).

&emsp;

## Submitting a Pull Request

1. Fork the repository and create a feature branch.
2. Make your changes following the guidelines above.
3. Run `php tests/run.php` and confirm all tests pass.
4. Open a pull request with a clear description of what you changed and why.
