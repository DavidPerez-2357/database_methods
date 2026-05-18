# Project Structure and Architecture

## Project Structure

```
DatabaseMethods/
├── DatabaseMethods.php          # Single entry-point: auto-loads all classes below
├── src/
│   ├── Database.php             # PDO wrapper base class (select, insert, update, delete, count, transactions)
│   ├── Query.php                # SQL builder (fluent API + array constructor)
│   ├── QueryRunner.php          # Executes Query::run() operations against Database
│   ├── PdoParameterBuilder.php  # Static helper for building named PDO parameter arrays
│   ├── SqlDialect.php           # Dialect-aware quoting/pagination (Default/MySQL/SQL Server)
│   ├── SqlValidator.php         # Centralized SQL identifier/expression validation utility
│   └── drivers/
│       ├── Mysql.php            # MySQL driver (extends Database)
│       ├── Postgres.php         # PostgreSQL driver (extends Database)
│       ├── Sql.php              # SQL Server driver (extends Database)
│       └── Sqlite.php           # SQLite driver (extends Database)
├── tests/
│   ├── run.php                  # Custom test runner (no PHPUnit)
│   ├── SqlValidatorTests.php    # Unit tests for SqlValidator
│   ├── SqlDialectTests.php      # Unit tests for SQL dialect pagination/quoting
│   ├── QueryTests.php           # Unit tests for Query
│   ├── PdoParameterBuilderTests.php  # Unit tests for PdoParameterBuilder
│   ├── DatabaseTest.php         # Integration tests for Database (uses SQLite)
│   └── QueryRunTests.php        # Focused integration tests for Query::run + QueryRunner
├── docs/
│   ├── Query.md                 # Full Query API documentation
│   ├── Database.md              # Full Database API documentation
│   ├── PdoParameterBuilder.md   # Full PdoParameterBuilder API documentation
│   └── query-database-integration.md  # Query::run() and createQuery() integration guide
├── README.md
├── CONTRIBUTING.md
└── LICENSE
```

## Layered Architecture

Follow this strict layering when adding code:

1. **`SqlValidator`** (`src/SqlValidator.php`): Centralized SQL identifier/expression validation. All identifier validation rules live here.
2. **`SqlDialect`** (`src/SqlDialect.php`): SQL dialect helpers (identifier quoting + pagination SQL compilation).
3. **`Query`** (`src/Query.php`): SQL-string builder only. No PDO, no I/O. Holds query state and builds SQL lazily.
4. **`QueryRunner`** (`src/QueryRunner.php`): Executes `Query::run()` against a linked `Database`; contains run-time validation/normalization flow.
5. **`PdoParameterBuilder`** (`src/PdoParameterBuilder.php`): Static utility. Builds named-parameter arrays and common SQL fragments (equality, set clauses, insert placeholders). No PDO execution.
6. **`Database`** (`src/Database.php`): Base PDO wrapper. Executes SQL using `Query` objects or raw strings. Handles connection, result formatting, and keyword replacement for CRUD sugar. Driver-agnostic logic lives here.
7. **Drivers** (`src/drivers/`): Thin subclasses of `Database`. Each driver sets up the PDO DSN and may override `$supportedJoins` or connection-specific behaviour. Keep driver classes minimal.

## Entry Point

`DatabaseMethods.php` uses `require_once` to load all classes. Any new source file added to `src/` (or `src/drivers/`) **must** be added to this loader.

## Query Builder: Key Invariants

- The `getQuery()` method builds and caches the SQL string; it is called by `__toString()`.
- Whenever a fluent setter changes state, set `$this->query = null` to invalidate the cache.
- All column/table identifiers that are interpolated into SQL must be validated.
- Both the fluent API (static factory + chained setters) and the array constructor must produce identical SQL for the same logical query.
- `run(array $data = [])` delegates execution to `QueryRunner` and requires a linked `Database`.

## Database: Key Invariants

- Public CRUD methods (`select`, `selectOne`, `insert`, `update`, `delete`, `deleteAll`, `count`) are routed through `__call()`, which applies keyword replacement before delegating to the private implementation.
- Parameterised values are always bound via `bindNamedParams()`; never concatenated.
- `executeTransaction($callback)` wraps a callback in a PDO transaction with automatic rollback on exception.
- `createQuery()` returns a blank `Query` already linked to the current `Database` and current driver dialect.
