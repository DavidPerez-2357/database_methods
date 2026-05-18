# Coding Conventions and Testing

## Naming

- **Classes**: `PascalCase` (e.g., `Database`, `PdoParameterBuilder`, `Mysql`).
- **Methods and variables**: `camelCase` (e.g., `getQuery()`, `$serverName`).
- **Constants**: `UPPER_SNAKE_CASE` (e.g., `IDENTIFIER`).
- **Files**: Match the class name exactly (e.g., `Database.php`, `Sqlite.php`).

## PHP Style

- Opening brace for classes and methods on the **same line**; follow the existing file style.
- Both `array()` and short array syntax `[]` are compatible with PHP 5.4+; prefer consistency with the existing file.
- Use `isset()` / `array_key_exists()` for optional keys rather than `??`.
- DocBlocks (`/** ... */`) for all public methods; inline comments with `//` for non-obvious logic.

## Language

- **Code is written in English**: all identifiers (variables, methods, classes, files) must use English.
- **Comments may be written in Spanish or English**: follow the style already present in the file you are editing.

## No External Dependencies

Never add Composer packages or any external library. The entire codebase must be loadable with a single `require_once 'DatabaseMethods.php'`.

## Testing

- The test suite lives in `tests/` and is run with:

  ```bash
  php tests/run.php
  ```

- **`QueryTests.php`**: unit tests for `Query`.
- **`DatabaseTest.php`**: integration tests for `Database` (uses SQLite in-memory).
- **`PdoParameterBuilderTests.php`**: unit tests for `PdoParameterBuilder`.
- **`SqlValidatorTests.php`**: unit tests for `SqlValidator`.
- **`SqlDialectTests.php`**: unit tests for `SqlDialect`.
- **`QueryRunTests.php`**: focused integration tests for `Query::run()` / `QueryRunner`.
- The runner exits with code `0` on success and `1` on failure.
- **Every new public method or behaviour change must be accompanied by a corresponding test.**
- Do not use PHPUnit or any external testing framework.
