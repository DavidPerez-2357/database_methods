# DatabaseMethods

A lightweight PHP library that cuts database boilerplate down to its essentials: two focused classes that let you write less and do more.

> Compatible with PHP **5.4** and above. MySQL, PostgreSQL, SQLite, and SQL Server supported.

&emsp;

## Installation & Usage

Download or clone the repository and require the single entry-point file; it automatically loads every module:

```php
require_once 'DatabaseMethods.php';
```

No Composer, no external dependencies.

&emsp;

## What's inside

### [`Query`](docs/Query.md) - SQL builder

Build any SELECT, INSERT, UPDATE, or DELETE with a clean fluent API (or the classic array constructor):

```php
$query = Query::select(['id', 'name'])
    ->from('users')
    ->where('active = :active')
    ->orderBy('name ASC')
    ->limit(20);
```

→ `SELECT id, name FROM users WHERE active = :active ORDER BY name ASC LIMIT 20`

[Full Query documentation →](docs/Query.md)

---

### [`Database`](docs/Database.md) - PDO wrapper

Connect once, then select, insert, update, delete, count, and run transactions with no boilerplate:

```php
$db = new Mysql(['serverName' => 'localhost', 'username' => 'root', 'password' => '', 'DB' => 'mydb']);

$query = Query::select(['id', 'name'])->from('users')->where('active = :active');
$users = $db->select($query, ['active' => 1]);
$lastId = $db->insert('users', ['name' => 'Alice', 'email' => 'alice@example.com']);
$rows = $db->update('users', ['name' => 'Bob'], 'id = :id', ['id' => $lastId]);

$db->executeTransaction(function($db) {
    $db->delete('orders', 'user_id = :id', ['id' => 5]);
    $db->update('users', ['active' => 0], 'id = :id', ['id' => 5]);
});
```

[Full Database documentation →](docs/Database.md)

---

### Fluent query execution

`Database::createQuery()` returns a linked `Query`. Chain the query type, setters, and call `run()` to execute:

```php
// Retrieve rows
$rows = $db->createQuery()
    ->select(['id', 'name'])
    ->from('users')
    ->where('active = :active')
    ->run(['active' => 1]);
```

Use the same pattern to write data — `run()` returns the last-insert ID for single-row inserts:

```php
// Insert a row
$id = $db->createQuery()
    ->insert('users', ['name', 'email'])
    ->run(['name' => 'Alice', 'email' => 'alice@example.com']);
```

[Full documentation →](docs/query-database-integration.md)

---

### [`PdoParameterBuilder`](docs/PdoParameterBuilder.md) - PDO parameter helper

Static utility for building PDO named-parameter arrays and common SQL fragments. Useful when writing queries by hand or extending the library:

```php
// Build params for a multi-row INSERT
$params = PdoParameterBuilder::buildInsertParams([
    ['name' => 'Alice', 'age' => 30],
    ['name' => 'Bob',   'age' => 25],
]);
// => [':name_0' => 'Alice', ':age_0' => 30, ':name_1' => 'Bob', ':age_1' => 25]
```

[Full PdoParameterBuilder documentation →](docs/PdoParameterBuilder.md)

&emsp;

## Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup instructions, how to run the test suite, and the project's coding guidelines.

&emsp;

## License

MIT - see [LICENSE](LICENSE).
