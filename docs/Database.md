# Database

The `Database` class provides a PDO-based interface for selecting, inserting, updating, deleting, counting, and running transactions. Driver subclasses (`Mysql`, `Postgres`, `Sql`, `Sqlite`) extend it and handle the connection details.

&emsp;

## Creating a connection

Pass a configuration array to the driver constructor:

| Key | Alias | Description | Required by |
|---|---|---|---|
| `serverName` | `host` | Hostname or IP | Mysql, Postgres, Sql |
| `username` | `user` | Database user | Mysql, Postgres, Sql |
| `password` | *(none)* | Password (default `""`) | Mysql, Postgres, Sql |
| `DB` | `dbname` | Database name or file path | All |
| `codification` | *(none)* | Encoding (default `utf8mb4`/`utf8`) | Mysql, Postgres |

```php
$props = [
    'serverName'   => 'localhost',
    'username'     => 'root',
    'password'     => '',
    'DB'           => 'your_database',
    'codification' => 'utf8'
];

$mysql    = new Mysql($props);
$postgres = new Postgres($props);
$sql      = new Sql($props);
$sqlite   = new Sqlite(['DB' => '/path/to/database.sqlite']);
```

> [!NOTE]
> `Sqlite` only uses `DB` (path to the `.sqlite` file); all other keys are ignored.
>
> `Sql` (SQL Server) requires `serverName`, `username`, and `DB`; `password` defaults to `""`.

&emsp;

## JSON encode

To receive results as a JSON string instead of a PHP array:

```php
$mysql->setJsonEncode(true);   // enable
$mysql->setJsonEncode(false);  // disable (default)

// setJsonEncode() returns $this, so it can be chained:
$result = $mysql->setJsonEncode(true)->select(Query::select()->from('users'));
```

Affects `select`, `selectOne`, and `plainSelect`.

&emsp;

## Special keywords

Certain string values in any array argument passed to CRUD methods (`$dataToInsert`, `$fieldsToUpdate`, `$whereData`, `$data`, etc.) are replaced automatically before execution:

| Keyword | Value |
|---|---|
| `@lastInsertId` | Last auto-increment ID |
| `@currentDate` | `Y-m-d` |
| `@currentDateTime` | `Y-m-d H:i:s` |
| `@currentTime` | `H:i:s` |
| `@currentTimestamp` | Unix timestamp |
| `@currentYear` | `Y` |
| `@currentMonth` | `m` |
| `@currentDay` | `d` |
| `@currentWeekday` | e.g. `Monday` |
| `@randomString` | Random 8-char alphanumeric |
| `@randomInt` | Random integer 1–9999 |
| `@randomFloat` | Random float 0.01–99.99 |
| `@randomBoolean` | `true` or `false` |

Keywords work in all CRUD methods (including multi-row inserts) but **not** in `runPlainQuery()` or `plainSelect()`.

To add custom keywords, edit the `replaceKeywordsInData` method in `Database.php`.

```php
$database->update('users',
    ['name' => '@randomString', 'created_at' => '@currentDateTime'],
    'id = :id',
    ['id' => '@lastInsertId']
);
```

### Disabling keyword checking

Keyword replacement is enabled by default. Call `enableKeywordCkeck(false)` to pass data values through unmodified.

Disabling keyword checking reduces processing complexity on every query call, which improves speed when you know your data contains no `@`-prefixed keywords or when maximum throughput is required (e.g. high-volume batch inserts).

> [!TIP]
> If your application never uses special keywords like `@currentDate` or `@randomInt`, disable keyword checking right after creating the database instance. This avoids unnecessary processing on every query and gives you better performance for free.

```php
$database->enableKeywordCkeck(false);
// '@currentDate' is now stored as the literal string, not today's date
$database->insert('logs', ['event' => '@currentDate']);
$database->enableKeywordCkeck(true); // re-enable when done
```

`enableKeywordCkeck()` returns `$this` so it can be chained.

&emsp;

## Validation mode

`Database` validation is enabled by default.

Use `validation(false)` to disable Database-side validation checks, and `validation(true)` to enable them again:

```php
$database->validation(false);
// ... execute performance-sensitive operations
$database->validation(true);
```

`validation()` is chainable and throws `InvalidArgumentException` when the argument is not a boolean.

Use `isValidationEnabled()` to inspect the current state.

> [!NOTE]
> When a query is executed through `createQuery()->...->validation(false)->run(...)`,
> Database validation is disabled only for that run call and then automatically restored.

&emsp;

## Methods

### `runPlainQuery($query, $data = [])`

Execute any SQL write statement directly. Always returns the PDO-reported `rowCount()` as an integer (may be 0 for DDL statements). Use `plainSelect()` for queries that return a result set.

Throws on error.

```php
// Write query - returns affected row count
$affected = $database->runPlainQuery(
    'UPDATE users SET active = 0 WHERE id = :userId',
    ['userId' => 2]
);
// $affected === 1
```

---

### `plainSelect($query, $data = [])`

Execute a plain SQL query and return all result rows as an associative array, or a JSON-encoded string when json_encode mode is enabled. Use `runPlainQuery()` for write statements.

Throws on error.

```php
$rows = $database->plainSelect('SELECT * FROM users WHERE active = 1');
// $rows === [['id' => 1, 'name' => 'Alice', ...], ...]

$rows = $database->plainSelect(
    'SELECT * FROM users WHERE id = :id',
    ['id' => 1]
);
```

---

### `select($query, $data = [])` / `selectOne($query, $data = [])`

Execute a `Query` object or a raw SQL string. `select` returns all matching rows; `selectOne` returns only the first row.

```php
// Using a Query object
$query = Query::select(['id', 'name'])->from('users')->where('id = :userId');

$rows = $database->select($query, ['userId' => 2]);
$row  = $database->selectOne($query, ['userId' => 2]);

// Using a raw SQL string
$rows = $database->select('SELECT id, name FROM users WHERE id = :userId', ['userId' => 2]);
$row  = $database->selectOne('SELECT id, name FROM users WHERE id = :userId LIMIT 1', ['userId' => 2]);
```

---

### `createQuery()` / `getDialect()`

`createQuery()` returns a blank `Query` linked to this connection and pre-configured with the driver dialect. Call the method type, chain setters, then call `run()` to execute, or pass the object to `select()` / `selectOne()`.

```php
$rows = $database->createQuery()
    ->select(['id', 'name'])
    ->from('users')
    ->orderBy('created_at DESC')
    ->limit(10)
    ->run();
```

Use `getDialect()` when you need to apply the same dialect manually to an existing `Query` object.

---

### `insert($table, $dataToInsert)`

Insert one or more records. Auto-detects single (associative array) vs. multiple (array of associative arrays). Returns the last inserted auto-increment ID.

```php
// Single record
$lastId = $database->insert('users', ['name' => 'John', 'email' => 'john@email.com']);

// Multiple records
$lastId = $database->insert('users', [
    ['name' => 'Alice', 'email' => 'alice@email.com'],
    ['name' => 'Bob',   'email' => 'bob@email.com'],
]);
```

---

### `update($table, $fieldsToUpdate, $where, $whereData = [], $joins = [])`

Update records. Returns the number of affected rows.

> [!CAUTION]
> Keys in `$whereData` must not overlap with column names in `$fieldsToUpdate`. Positional placeholders (`?`) are not supported in `$where` - use named placeholders (e.g. `id = :id`).

```php
$affected = $database->update('users',
    ['name' => 'Michael', 'email' => 'michael@email.com'],
    'id = :id',
    ['id' => 5]
);
```

---

### `delete($table, $where, $whereData = [], $orderBy = "", $limit = 0)`

Delete records matching `$where`. Returns the number of affected rows. Both named and positional placeholders are supported.

```php
$deleted = $database->delete('users', 'id = :id', ['id' => 2]);
```

`$orderBy` must be a comma-separated list of plain column identifiers with optional `ASC`/`DESC` (e.g. `'created_at DESC'`); any other characters throw `InvalidArgumentException`.

---

### `deleteAll($table, $orderBy = "", $limit = 0)`

Delete all records from a table (no WHERE clause). Pass `$orderBy` and `$limit` as the 2nd and 3rd arguments when needed.

```php
$deleted = $database->deleteAll('users');
```

---

### `count($table, $where = '', $whereData = [], $joins = [])`

Return the number of records matching a condition. Both named and positional placeholders are supported.

```php
$total = $database->count('users', 'active = :active', ['active' => 1]);
```

---

### `executeTransaction(callable $callback)`

Run multiple operations inside a single transaction. Commits automatically on success; rolls back if any operation throws.

```php
$database->executeTransaction(function($db) {
    $db->update('users', ['active' => 0], 'id = :id', ['id' => 2]);
    $db->delete('orders', 'user_id = :user_id', ['user_id' => 2]);
});
```
