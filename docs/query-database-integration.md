# Query–Database Integration

`Database::createQuery()` returns a blank `Query` linked to the active connection. Call one of the four method types, chain setters, then call `run()` to execute directly.

## `Database::createQuery()`

Returns a blank `Query` linked to this connection and pre-configured with the driver dialect.

```php
$rows = $db->createQuery()->select(['id', 'name'])->from('users')->run();
```

---

## `Query::setDatabase(Database $db)`

Links an independently-built `Query` to a database. Only needed when constructing a `Query` outside of `createQuery()`.

```php
$query = Query::select(['id'])->from('users');
$query->setDatabase($db);
$rows = $query->run();
```

---

## `Query::run(array $data = [])`

Executes the query. `$data` contains the PDO bindings.

| Query type | Return value |
|------------|--------------|
| `SELECT` | `array` of rows (or JSON string when json_encode mode is on) |
| `INSERT` | `int` - last-insert ID (single row) or `0` (multi-row batch) |
| `UPDATE` | `int` - affected-row count |
| `DELETE` | `int` - affected-row count |

### UPDATE

Pass SET values and WHERE bindings in one flat array. Keys matching the field list go to `SET`; all other keys go to `WHERE`.

```php
$n = $db->createQuery()
    ->update('users', ['name'])
    ->where('name = :old_name')
    ->run(['name' => 'New Name', 'old_name' => 'Old Name']);
```

> [!NOTE]
> Quoted field names (e.g. `"order"`) must be passed as their unquoted form (e.g. `'order'`) in `$data`.

### INSERT multi-row

Pass a sequential list of associative arrays to insert multiple rows in a single statement:

```php
$db->createQuery()
    ->insert('users', ['name', 'email'])
    ->run([
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ]);
```

Multi-row inserts return `0` (last-insert ID is undefined for batch operations across all drivers).

---

## `Query::validation(bool $enabled = true)`

Controls whether `run()` validates and normalises its inputs before executing.

| `$enabled` | Behaviour |
|------------|-----------|
| `true` (default) | INSERT rejects empty data and mismatched field keys; UPDATE requires at least one field and at least one matching binding in `$data`. Multi-row INSERT rows are re-keyed to the declared field list. |
| `false` | All validation and normalisation steps are skipped. Field keys in `$data` are matched to the field list as-is, without identifier unquoting. Use this only when you are certain the inputs are already correct and you need maximum throughput. |

```php
// Validation enabled (default) — safe for general use
$db->createQuery()
    ->insert('logs', ['level', 'message'])
    ->run(['level' => 'info', 'message' => 'started']);

// Validation disabled — skips checks, faster for bulk operations
$db->createQuery()
    ->insert('events', ['type', 'payload'])
    ->validation(false)
    ->run(['type' => 'click', 'payload' => '{}']);
```

`validation()` is chainable and throws `InvalidArgumentException` when the argument is not a boolean.
When set to `false`, `run()` also disables `Database` validation for that execution only, then restores the previous Database validation state.

---

## Error handling

- `run()` throws `RuntimeException` when the query has no linked database.
- `run()` throws `InvalidArgumentException` when no method is set.
- `run()` executes through `plainSelect()` / `runPlainQuery()`, so Database keyword replacement
  (e.g. `@currentDate`, `@randomInt`) is not applied in this path.
- All PDO and validation errors propagate unchanged.
