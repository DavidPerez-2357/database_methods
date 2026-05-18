<?php

/**
 * Query.php
 *
 * Provides the Query class for building SQL queries from a data array.
 * Supports SELECT, INSERT (PDO-style), UPDATE (PDO-style), and DELETE methods.
 *
 * @author DavidPerez-2357
 * @link https://github.com/DavidPerez-2357/DatabaseMethods
 */

/**
 * Query class to build SQL queries based on provided data.
 * Supports SELECT, INSERT, UPDATE, and DELETE methods.
 *
 * Queries can be built either by passing a full configuration array to the
 * constructor, or via the static factory methods combined with the fluent
 * setter API:
 *
 * ```php
 * // Array constructor (original API - still fully supported)
 * $query = new Query(['method' => 'SELECT', 'fields' => ['id'], 'table' => 'users']);
 *
 * // Fluent API
 * $query = Query::select(['id', 'name'])->from('users')->where('active = 1');
 * $query = Query::insert('users', ['name', 'email'])->valuesCount(3);
 * $query = Query::update('users', ['name', 'email'])->where('id = :id');
 * $query = Query::delete('users')->where('id = :id')->limit(10);
 * ```
 *
 * @package DatabaseMethods
 */
class Query
{
    private $data;
    private $query;
    private $dialect;
    /** @var Database|null */
    private $database;
    /** @var QueryRunner|null */
    private $queryRunner;
    /** @var bool */
    private $validationEnabled;

    /**
     * Creates a Query instance.
     *
     * When $queryData is a non-empty array the query is built immediately
     * (original behavior). When called with an empty array - as the static
     * factory methods do - building is deferred until the query string is
     * first needed.
     *
     * @param array $queryData Configuration array (optional when using factory methods).
     * @throws InvalidArgumentException if $queryData is not an array.
     */
    public function __construct($queryData = [])
    {
        if (!is_array($queryData)) {
            throw new InvalidArgumentException('Query constructor expects an array.');
        }

        $this->dialect = new DefaultSqlDialect();
        $this->database = null;
        $this->queryRunner = null;
        $this->validationEnabled = true;
        $this->data = $queryData;
        if (!empty($queryData)) {
            $this->query = $this->buildQuery();
        }
    }

    public function __toString()
    {
        try {
            if ($this->query === null) {
                $this->query = $this->buildQuery();
            }
            return (string) $this->query;
        } catch (Exception $e) {
            // Throwing from __toString() is fatal in PHP 5.4–6.x, so we emit a warning instead.
            // Note: `Throwable` (which also covers `Error`) was introduced in PHP 7.0 and cannot
            // be used here without breaking the library's PHP 5.4+ compatibility guarantee.
            // In practice, buildQuery() only throws InvalidArgumentException (extends Exception),
            // so this catch is sufficient for all real-world error paths.
            trigger_error(
                'Error building SQL query in ' . __METHOD__ . ': ' . $e->getMessage(),
                E_USER_WARNING
            );
            return '';
        }
    }

    /**
     * Returns the built SQL query string.
     *
     * Unlike casting to a string, this method lets any exception thrown
     * during query building propagate to the caller.
     *
     * @throws InvalidArgumentException if the query configuration is invalid.
     * @return string
     */
    public function getQuery()
    {
        if ($this->query === null) {
            $this->query = $this->buildQuery();
        }
        return $this->query;
    }

    // -------------------------------------------------------------------------
    // Factory methods - callable both as Query::select() (static) and $query->select() (instance)
    // -------------------------------------------------------------------------

    /**
     * Dispatches instance calls to `select()`, `insert()`, `update()`, or `delete()`.
     *
     * This magic method allows the factory methods to be called on an existing Query
     * instance (e.g. one returned by `Database::createQuery()`) so that the database
     * link and dialect are preserved:
     *
     * ```php
     * $rows = $db->createQuery()->select(['id', 'name'])->from('users')->run();
     * ```
     *
     * @param string $name Method name ('select', 'insert', 'update', or 'delete').
     * @param array  $args Arguments forwarded to the private implementation.
     * @return $this
     * @throws BadMethodCallException If $name is not one of the four supported methods.
     */
    public function __call($name, $args)
    {
        return $this->dispatchFactoryMethod($name, $args);
    }

    /**
     * Forwards static calls (`Query::select()`, `Query::insert()`, etc.) to a fresh
     * instance so the fluent factory pattern continues to work.
     *
     * @param string $name Method name ('select', 'insert', 'update', or 'delete').
     * @param array  $args Arguments forwarded to the instance method.
     * @return static
     * @throws BadMethodCallException If $name is not one of the four supported methods.
     */
    public static function __callStatic($name, $args)
    {
        $instance = new static();
        return $instance->dispatchFactoryMethod($name, $args, true);
    }

    /**
     * Sets this query to SELECT and (optionally) specifies the column list.
     *
     * Called via `Query::select()` (static) or `$query->select()` (instance) through
     * the `__callStatic` / `__call` magic methods.
     *
     * @param array|string|null $fields Column list. When omitted, null, or an empty array,
     *                                  defaults to ['*']. A string is normalized to a
     *                                  single-element array. An empty/whitespace-only string
     *                                  throws InvalidArgumentException.
     * @return $this
     * @throws InvalidArgumentException If $fields is an empty/whitespace-only string, or
     *                                  not an array, string, or null.
     */
    private function _select($fields = array())
    {
        $this->data['method'] = 'SELECT';
        $this->invalidateQuery();

        if ($fields === array() || $fields === null) {
            $this->data['fields'] = array('*');
        } elseif (is_string($fields)) {
            if (trim($fields) === '') {
                throw new InvalidArgumentException(
                    'select() expects $fields to be a non-empty string, '
                    . 'an array (empty defaults to ["*"]), or omitted.'
                );
            }
            $this->data['fields'] = array($fields);
        } elseif (is_array($fields)) {
            // Delegate to fields() so per-element validation runs consistently.
            $this->fields($fields);
        } else {
            throw new InvalidArgumentException(
                'select() expects $fields to be an array, string, or empty.'
            );
        }

        return $this;
    }

    /**
     * Sets this query to INSERT for the given table and optional column list.
     *
     * `$fields` can also be set later with `->fields([...])`.
     * The column list must be provided before the query string is generated.
     *
     * `$table` must be a plain or schema-qualified identifier (`'users'`, `'public.users'`).
     * Table aliases are not valid in INSERT statements and will cause an
     * `InvalidArgumentException` to be thrown at query-build time.
     *
     * @param string       $table  Target table name (plain or schema-qualified; no alias).
     * @param array|string $fields Columns to insert (optional; can be set later with ->fields()).
     *                             A string is normalized to a single-element array.
     * @return $this
     * @throws InvalidArgumentException If $fields is not an array or string.
     */
    private function _insert($table, $fields = array())
    {
        $this->data['method'] = 'INSERT';
        $this->data['table'] = $table;
        $this->invalidateQuery();
        if (!empty($fields)) {
            $this->data['fields'] = self::normalizeOptionalFields($fields, 'insert()');
        }
        return $this;
    }

    /**
     * Sets this query to UPDATE for the given table and optional column list.
     *
     * `$fields` can also be set later with `->fields([...])`.
     * The column list must be provided before the query string is generated.
     *
     * `$table` accepts plain, schema-qualified, or aliased forms:
     * `'users'`, `'public.users'`, `'users u'`, `'users AS u'`, `'public.users AS u'`.
     *
     * @param string       $table  Target table expression (plain, schema-qualified, or with alias).
     * @param array|string $fields Columns to update (optional; can be set later with ->fields()).
     *                             A string is normalized to a single-element array.
     * @return $this
     * @throws InvalidArgumentException If $fields is not an array or string.
     */
    private function _update($table, $fields = array())
    {
        $this->data['method'] = 'UPDATE';
        $this->data['table'] = $table;
        $this->invalidateQuery();
        if (!empty($fields)) {
            $this->data['fields'] = self::normalizeOptionalFields($fields, 'update()');
        }
        return $this;
    }

    /**
     * Sets this query to DELETE for the given table.
     *
     * `$table` accepts plain, schema-qualified, or aliased forms:
     * `'users'`, `'public.users'`, `'users u'`, `'users AS u'`, `'public.users AS u'`.
     *
     * @param string $table Target table expression (plain, schema-qualified, or with alias).
     * @return $this
     */
    private function _delete($table)
    {
        $this->data['method'] = 'DELETE';
        $this->data['table'] = $table;
        return $this->invalidateQuery();
    }

    /**
     * Quotes a SQL identifier using the given dialect, or ANSI double-quotes by default.
     *
     * Use this when a table or column name is a reserved word or contains special characters.
     * Pass the dialect of your driver to get the correct quoting style for your database.
     *
     * @param string          $identifier A single identifier segment (no dots; quote each segment separately).
     * @param SqlDialect|null $dialect    Dialect to use for quoting; defaults to ANSI double-quotes when null.
     * @return string The quoted identifier.
     * @throws InvalidArgumentException If $identifier is not a non-empty string, contains a dot,
     *                                  or if $dialect is not a SqlDialect instance (when non-null).
     * @example
     * ```php
     * // ANSI double-quotes (default - PostgreSQL, SQLite, SQL Server)
     * Query::quote('order')                        // => '"order"'
     *
     * // MySQL backticks
     * Query::quote('order', new MysqlSqlDialect()) // => '`order`'
     *
     * // Use the dialect of an existing Database connection
     * Query::quote('order', $db->getDialect())
     *
     * // Schema-qualified: quote each segment individually
     * Query::quote('public') . '.' . Query::quote('user')  // => '"public"."user"'
     * ```
     */
    public static function quote($identifier, $dialect = null)
    {
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new InvalidArgumentException('Query::quote() expects a non-empty string.');
        }

        if (strpos($identifier, '.') !== false) {
            throw new InvalidArgumentException(
                'Query::quote() expects a single identifier segment with no dots; quote each segment separately.'
            );
        }

        if ($dialect !== null && !($dialect instanceof SqlDialect)) {
            throw new InvalidArgumentException(
                'Query::quote() expects $dialect to be an instance of SqlDialect or null.'
            );
        }

        if ($dialect === null) {
            $dialect = new DefaultSqlDialect();
        }

        return $dialect->quoteIdentifier($identifier);
    }

    // -------------------------------------------------------------------------
    // Fluent setter methods
    // -------------------------------------------------------------------------

    /**
     * Sets the target table (alias of table()).
     *
     * Accepts plain, schema-qualified, or aliased forms:
     * `'users'`, `'public.users'`, `'users u'`, `'users AS u'`, `'public.users AS u'`.
     *
     * @param string $table Table expression (plain, schema-qualified, or with alias).
     * @return $this
     */
    public function from($table)
    {
        return $this->setDataValue('table', $table);
    }

    /**
     * Sets the target table (alias of from()).
     *
     * Accepts plain, schema-qualified, or aliased forms:
     * `'users'`, `'public.users'`, `'users u'`, `'users AS u'`, `'public.users AS u'`.
     *
     * @param string $table Table expression (plain, schema-qualified, or with alias).
     * @return $this
     */
    public function table($table)
    {
        return $this->from($table);
    }

    /**
     * Sets the column list.
     *
     * A string is normalized to a single-element array. For SELECT queries an empty
     * array defaults to ['*']. For INSERT and UPDATE queries a non-empty array is
     * required (an empty array will cause an exception when the query is built).
     *
     * @param array|string $fields Column names, or a single column name string.
     * @return $this
     * @throws InvalidArgumentException if $fields is not an array or string.
     */
    public function fields($fields)
    {
        if (is_string($fields)) {
            if (trim($fields) === '') {
                throw new InvalidArgumentException(
                    'Query::fields() expects a non-empty string column name or an array of column names.'
                );
            }
            $fields = [$fields];
        } elseif (!is_array($fields)) {
            throw new InvalidArgumentException(
                'Query::fields() expects an array of column names or a string column name.'
            );
        }

        // Validate every element before applying any default.
        foreach ($fields as $field) {
            if (!is_string($field) || trim($field) === '') {
                throw new InvalidArgumentException(
                    'Query::fields() expects every element to be a non-empty string column name.'
                );
            }
        }

        // Empty array on SELECT defaults to 'SELECT *'.
        if (
            empty($fields)
            && isset($this->data['method'])
            && strtoupper($this->data['method']) === 'SELECT'
        ) {
            $fields = ['*'];
        }

        $this->data['fields'] = $fields;
        return $this->invalidateQuery();
    }

    /**
     * Sets the WHERE clause.
     *
     * This is a raw SQL fragment. To prevent SQL injection, always pass user-supplied
     * values via PDO placeholders (e.g. `'id = :id'`) and bind them through Database.
     * Never interpolate untrusted strings directly into this value.
     *
     * @param string $where Raw SQL WHERE fragment (use named placeholders, e.g. `id = :id`).
     * @return $this
     */
    public function where($where)
    {
        return $this->setDataValue('where', $where);
    }

    /**
     * Appends a single JOIN clause.
     *
     * This is a raw SQL fragment. To prevent SQL injection, use only hard-coded or
     * pre-validated table/column names in JOIN expressions. Never interpolate untrusted
     * strings directly into the expression; any filter values belong in the WHERE clause
     * with PDO placeholders.
     *
     * @param string $join Full JOIN expression (e.g. `LEFT JOIN orders ON users.id = orders.user_id`).
     * @return $this
     * @throws InvalidArgumentException If $join is not a non-empty string.
     */
    public function join($join)
    {
        if (!is_string($join) || trim($join) === '') {
            throw new InvalidArgumentException(
                'join() expects a non-empty string JOIN expression.'
            );
        }
        if (!isset($this->data['joins'])) {
            $this->data['joins'] = [];
        } elseif (!is_array($this->data['joins'])) {
            $this->data['joins'] = [$this->data['joins']];
        }
        $this->data['joins'][] = $join;
        return $this->invalidateQuery();
    }

    /**
     * Appends an INNER JOIN clause.
     *
     * This is a convenience wrapper around join(). See join() for the SQL injection
     * warning that applies here as well.
     *
     * @param string $table     Table expression to join (e.g. `orders o`).
     * @param string $condition ON condition (e.g. `o.user_id = users.id`).
     * @return $this
     * @throws InvalidArgumentException If $table or $condition is not a non-empty string.
     */
    public function innerJoin($table, $condition)
    {
        return $this->buildTypedJoin('INNER JOIN', $table, $condition);
    }

    /**
     * Appends a LEFT JOIN clause.
     *
     * This is a convenience wrapper around join(). See join() for the SQL injection
     * warning that applies here as well.
     *
     * @param string $table     Table expression to join (e.g. `orders o`).
     * @param string $condition ON condition (e.g. `o.user_id = users.id`).
     * @return $this
     * @throws InvalidArgumentException If $table or $condition is not a non-empty string.
     */
    public function leftJoin($table, $condition)
    {
        return $this->buildTypedJoin('LEFT JOIN', $table, $condition);
    }

    /**
     * Appends a RIGHT JOIN clause.
     *
     * This is a convenience wrapper around join(). See join() for the SQL injection
     * warning that applies here as well.
     *
     * @param string $table     Table expression to join (e.g. `orders o`).
     * @param string $condition ON condition (e.g. `o.user_id = users.id`).
     * @return $this
     * @throws InvalidArgumentException If $table or $condition is not a non-empty string.
     */
    public function rightJoin($table, $condition)
    {
        return $this->buildTypedJoin('RIGHT JOIN', $table, $condition);
    }

    /**
     * Appends a FULL JOIN clause.
     *
     * This is a convenience wrapper around join(). See join() for the SQL injection
     * warning that applies here as well.
     *
     * @param string $table     Table expression to join (e.g. `orders o`).
     * @param string $condition ON condition (e.g. `o.user_id = users.id`).
     * @return $this
     * @throws InvalidArgumentException If $table or $condition is not a non-empty string.
     */
    public function fullJoin($table, $condition)
    {
        return $this->buildTypedJoin('FULL JOIN', $table, $condition);
    }

    /**
     * Replaces all JOIN clauses with the given value.
     *
     * Each JOIN expression is a raw SQL fragment - see join() for the SQL injection
     * warning that applies here as well.
     *
     * Accepts an array of JOIN expressions (each element must be a non-empty string),
     * a single JOIN string (normalized to a one-element array), or null (clears all joins).
     * Any other type throws an InvalidArgumentException.
     *
     * @param array|string|null $joins Array of JOIN expressions, a single JOIN string, or null.
     * @return $this
     * @throws InvalidArgumentException if $joins is not an array, string, or null, or if any
     *                                  array element is not a non-empty string.
     */
    public function joins($joins)
    {
        if ($joins === null) {
            $this->data['joins'] = [];
        } elseif (is_string($joins)) {
            if (trim($joins) === '') {
                throw new InvalidArgumentException(
                    'joins() expects a non-empty string JOIN expression.'
                );
            }
            $this->data['joins'] = [$joins];
        } elseif (is_array($joins)) {
            foreach ($joins as $join) {
                if (!is_string($join) || trim($join) === '') {
                    throw new InvalidArgumentException(
                        'joins() expects each element to be a non-empty string JOIN expression.'
                    );
                }
            }
            $this->data['joins'] = $joins;
        } else {
            throw new InvalidArgumentException(
                'joins() expects an array of JOIN expressions, a JOIN string, or null.'
            );
        }
        return $this->invalidateQuery();
    }

    /**
     * Sets the GROUP BY clause.
     *
     * The value is validated by SqlValidator::assertGroupBy() when the query is built.
     * Only plain column names (optionally table-qualified) are allowed, e.g.
     * `'users.id'` or `'id, name'`.
     *
     * @param string $groupBy Column(s) to group by, separated by commas.
     * @return $this
     */
    public function groupBy($groupBy)
    {
        return $this->setDataValue('group_by', $groupBy);
    }

    /**
     * Sets the HAVING clause.
     *
     * This is a raw SQL fragment. To prevent SQL injection, always pass user-supplied
     * values via PDO placeholders and bind them through Database.
     * Never interpolate untrusted strings directly into this value.
     *
     * @param string $having HAVING condition (use named placeholders for user data).
     * @return $this
     */
    public function having($having)
    {
        return $this->setDataValue('having', $having);
    }

    /**
     * Sets the ORDER BY clause.
     * The value is validated by SqlValidator::assertOrderBy() when the query is built.
     *
     * @param string $orderBy Column(s) with optional ASC/DESC (e.g. `name ASC, id DESC`).
     * @return $this
     */
    public function orderBy($orderBy)
    {
        return $this->setDataValue('order_by', $orderBy);
    }

    /**
     * Sets the LIMIT clause.
     *
     * @param int $limit Maximum number of rows (must be a non-negative integer).
     * @return $this
     * @throws InvalidArgumentException If $limit is not a non-negative integer value.
     */
    public function limit($limit)
    {
        return $this->setValidatedIntegerOption('limit', $limit, 0, 'limit() expects a non-negative integer.');
    }

    /**
     * Sets the OFFSET clause (SELECT only).
     *
     * @param int $offset Number of rows to skip (must be a non-negative integer).
     * @return $this
     * @throws InvalidArgumentException If $offset is not a non-negative integer value.
     */
    public function offset($offset)
    {
        return $this->setValidatedIntegerOption('offset', $offset, 0, 'offset() expects a non-negative integer.');
    }

    /**
     * Sets how many rows the INSERT query should prepare placeholders for.
     * Defaults to 1 when not called.
     *
     * @param int $count Number of rows to insert (must be a positive integer).
     * @return $this
     * @throws InvalidArgumentException If $count is not a positive integer value.
     */
    public function valuesCount($count)
    {
        return $this->setValidatedIntegerOption(
            'values_to_insert',
            $count,
            1,
            'valuesCount() expects a positive integer (>= 1).'
        );
    }

    /**
     * Sets the SQL dialect used to compile driver-specific SQL fragments.
     *
     * @param SqlDialect $dialect
     * @return $this
     */
    public function setDialect(SqlDialect $dialect)
    {
        if ($this->dialect === $dialect) {
            return $this;
        }
        $this->dialect = $dialect;
        return $this->invalidateQuery();
    }

    /**
     * Links this query to a Database instance so that `run()` can execute it directly.
     *
     * `Database::createQuery()` calls this automatically. You only need to call it
     * manually when constructing a Query outside of a Database context and you want
     * to execute it later with `run()`.
     *
     * @param Database $database The database instance to use for execution.
     * @return $this
     */
    public function setDatabase(Database $database)
    {
        $this->database = $database;
        // QueryRunner holds the Database instance in its constructor; clear cache when relinking.
        $this->queryRunner = null;
        $this->setDialect($database->getDialect());
        return $this;
    }

    /**
     * Enables/disables query validation and normalization behavior.
     *
     * This setting affects Query internals and QueryRunner execution behavior.
     * Use `validation(false)` for a faster execution path in Query::run().
     *
     * @param bool $enabled true to keep validation enabled; false to disable it.
     * @return $this
     * @throws InvalidArgumentException If $enabled is not boolean.
     */
    public function validation($enabled = true)
    {
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException(
                'validation() expects a boolean argument.'
            );
        }

        $this->validationEnabled = $enabled;
        return $this;
    }

    /**
     * @return bool
     */
    public function isValidationEnabled()
    {
        return $this->validationEnabled;
    }

    /**
     * Returns the target table name set on this query, or null when not set.
     *
     * @return string|null
     */
    public function getTable()
    {
        return isset($this->data['table']) ? $this->data['table'] : null;
    }

    /**
     * Returns the column list set on this query, or an empty array when not set.
     *
     * @return array
     */
    public function getFields()
    {
        return isset($this->data['fields']) ? $this->data['fields'] : array();
    }

    /**
     * Executes this query against the linked Database and returns the result.
     *
     * The Query must have been linked to a Database via `setDatabase()` or by
     * being obtained from `Database::createQuery()`.
     *
     * Return values by query type:
     *  - SELECT  → array of rows (associative arrays), or JSON string in json_encode mode.
     *  - INSERT  → int - the last inserted row ID (single row) or 0 for multi-row batches.
     *  - UPDATE  → int - number of affected rows.
     *  - DELETE  → int - number of affected rows.
     *
     * For UPDATE, `$data` must contain both the column values to SET and the WHERE
     * bindings in one flat array. The Query's field list determines which keys belong
     * to the SET clause; all remaining keys are treated as WHERE bindings. Field names
     * that were quoted (e.g. `"order"`) must be passed as their unquoted form (e.g.
     * `'order'`) in `$data`.
     *
     * For INSERT with multiple rows, pass an array of associative arrays:
     * `[['name' => 'Alice'], ['name' => 'Bob']]`. The field list set on the Query (if
     * any) is used to build the SQL; field names are derived from the first row's keys
     * when not pre-set.
     *
     * @param array $data Bindings / row data for the query (optional for SELECT/DELETE).
     * @return array|string|int Result rows for SELECT; last-insert-ID for INSERT;
     *                          affected-row count for UPDATE/DELETE.
     * @throws RuntimeException If the Query is not linked to a Database.
     * @throws InvalidArgumentException If the query method is not set or unsupported.
     * @example
     * ```php
     * // SELECT
     * $rows = $db->createQuery()->select(['id', 'name'])->from('users')->run();
     * $row  = $db->createQuery()->select(['id'])->from('users')->where('id = :id')->run([':id' => 1]);
     *
     * // INSERT
     * $id = $db->createQuery()->insert('users', ['name', 'email'])->run(['name' => 'Alice', 'email' => 'a@b.com']);
     *
     * // UPDATE
     * $n = $db->createQuery()->update('users', ['name'])->where('id = :id')->run(['name' => 'Bob', 'id' => 1]);
     *
     * // DELETE
     * $n = $db->createQuery()->delete('users')->where('id = :id')->run(['id' => 5]);
     * ```
     */
    public function run(array $data = array())
    {
        if ($this->database === null) {
            throw new RuntimeException(
                'Query::run() requires a linked Database. '
                . 'Obtain this Query via Database::createQuery() or call setDatabase() first.'
            );
        }

        if (empty($this->data['method'])) {
            throw new InvalidArgumentException('Query method is required before calling run().');
        }

        $method = strtoupper($this->data['method']);
        if ($this->queryRunner === null) {
            $this->queryRunner = new QueryRunner($this->database);
        }

        $restoreDatabaseValidation = $this->disableDatabaseValidationIfNeeded();

        try {
            $result = $this->runWithQueryRunner($method, $data);
        } catch (Exception $e) {
            $this->restoreDatabaseValidation($restoreDatabaseValidation);
            throw $e;
        }

        $this->restoreDatabaseValidation($restoreDatabaseValidation);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Query builders
    // -------------------------------------------------------------------------

    public function buildQuery()
    {
        if (empty($this->data['method'])) {
            throw new InvalidArgumentException("Query method is required.");
        }

        switch (strtoupper($this->data['method'])) {
            case 'SELECT':
                return $this->buildSelectQuery();
            case 'INSERT':
                return $this->buildPDOInsertQuery();
            case 'UPDATE':
                return $this->buildPDOUpdateQuery();
            case 'DELETE':
                return $this->buildDeleteQuery();
            default:
                throw new InvalidArgumentException("Unsupported query method: " . $this->data['method']);
        }
    }

    /**
     * Builds a SELECT SQL query based on the provided data.
     * @throws InvalidArgumentException if the method is not SELECT or required fields are missing.
     * @return string The constructed SQL SELECT query.
     * @example
     * ```php
     * $query = new Query([
     *    'method' => 'SELECT',
     *    'fields' => ['id', 'name'],
     *    'table' => 'users',
     *    'joins' => ['LEFT JOIN orders ON users.id = orders.user_id'],
     *    'where' => 'users.active = 1',
     *    'group_by' => 'users.id',
     *    'having' => 'COUNT(orders.id) > 0',
     *    'order_by' => 'users.name ASC',
     *    'limit' => 10,
     *    'offset' => 0
     * ]);
     *
     */
    public function buildSelectQuery()
    {
        $this->assertMethod('SELECT');
        $table = $this->requireTable();

        // Compute pagination values early so the dialect can affect the SELECT prefix.
        $limit = $this->getValidatedLimit();
        $limitVal = $limit > 0 ? $limit : null;

        $offsetVal = $this->getValidatedIntegerOption('offset', 0);

        $fields    = isset($this->data['fields']) ? $this->renderSelectFields($this->data['fields']) : "*";
        $selectTop = $this->dialect->compileSelectTop($limitVal, $offsetVal);
        $sql = "SELECT {$selectTop}{$fields} FROM {$table}";

        $this->appendJoinsToSql($sql);

        if (!empty($this->data['where'])) {
            $sql .= " WHERE {$this->data['where']}";
        }

        if (!empty($this->data['group_by'])) {
            $sql .= " GROUP BY " . $this->renderGroupBy($this->data['group_by']);
        }

        if (!empty($this->data['having'])) {
            $sql .= " HAVING {$this->data['having']}";
        }

        if (!empty($this->data['order_by'])) {
            $sql .= " ORDER BY " . $this->renderOrderBy($this->data['order_by']);
        }

        $hasOrderBy = !empty($this->data['order_by']);
        $sql .= $this->dialect->compilePagination($limitVal, $offsetVal, $hasOrderBy);

        return $sql;
    }

    /**
     * Builds an INSERT SQL query based on the provided data.
     * @throws InvalidArgumentException if the method is not INSERT or required fields are missing.
     * @return string The constructed SQL INSERT query.
     * @example
     * ```php
     * // new Query(['method'=>'INSERT','table'=>'users','fields'=>['name','email'],'values_to_insert'=>2])
     * // => "INSERT INTO users (name, email) VALUES (:name_0, :email_0), (:name_1, :email_1)"
     * ```
     */
    public function buildPDOInsertQuery()
    {
        $this->assertMethod('INSERT');
        $table = $this->requirePlainTable();
        $fields = $this->requireFields();

        $values = isset($this->data['values_to_insert']) ? (int) $this->data['values_to_insert'] : 1;
        if ($values < 1) {
            throw new InvalidArgumentException("Number of values to insert must be at least 1.");
        }

        // buildInsertPlaceholders() validates each field name and generates the row groups.
        $groups = PdoParameterBuilder::buildInsertPlaceholders($fields, $values);

        return "INSERT INTO {$table} (" . implode(', ', $fields) . ") VALUES " . implode(', ', $groups);
    }

    /**
     * Builds an UPDATE SQL query based on the provided data.
     * @throws InvalidArgumentException if the method is not UPDATE or required fields are missing.
     * @return string The constructed SQL UPDATE query.
     * @example
     * ```php
     * // new Query(['method'=>'UPDATE','table'=>'users','fields'=>['name','email'],'where'=>'id=:id'])
     * // => "UPDATE users SET name = :name, email = :email WHERE id=:id"
     * ```
     */
    public function buildPDOUpdateQuery()
    {
        $this->assertMethod('UPDATE');
        $table = $this->requireTable();
        $fields = $this->requireFields();

        $sql = "UPDATE {$table}";
        $this->appendJoinsToSql($sql);
        $sql .= " SET " . $this->buildSetClause($fields);

        if (!empty($this->data['where'])) {
            $sql .= " WHERE {$this->data['where']}";
        }

        return $sql;
    }

    /**
     * Builds a DELETE SQL query based on the provided data.
     * @throws InvalidArgumentException if the method is not DELETE or required fields are missing.
     * @return string The constructed SQL DELETE query.
     * @example
     * ```php
     * $query = new Query([
     *    'method' => 'DELETE',
     *    'table' => 'users',
     *    'where' => 'id = :id',
     *    'order_by' => 'created_at DESC',
     *    'limit' => 10
     * ]);
     * */
    public function buildDeleteQuery()
    {
        $this->assertMethod('DELETE');
        $table = $this->requireTable();

        $sql = "DELETE FROM {$table}";

        if (!empty($this->data['where'])) {
            $sql .= " WHERE {$this->data['where']}";
        }

        if (!empty($this->data['order_by'])) {
            $sql .= " ORDER BY " . $this->renderOrderBy($this->data['order_by']);
        }

        $limit = $this->getValidatedLimit();
        if ($limit > 0) {
            $sql .= " LIMIT " . $limit;
        }

        return $sql;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Asserts that $this->data['method'] matches the expected value.
     * @param string $expected Expected method name (e.g. 'SELECT').
     * @throws InvalidArgumentException
     */
    private function assertMethod($expected)
    {
        if (!isset($this->data['method']) || strtoupper($this->data['method']) !== $expected) {
            throw new InvalidArgumentException("Only {$expected} method is supported.");
        }
    }

    /**
     * @param string $name
     * @param array  $args
     * @param bool   $staticCall
     * @return $this
     */
    private function dispatchFactoryMethod($name, array $args, $staticCall = false)
    {
        $method = strtolower($name);
        if (!self::isFactoryMethod($method)) {
            $message = $staticCall
                ? "Static method '{$name}' does not exist in " . get_called_class() . '.'
                : "Method '{$name}' does not exist in " . get_class($this) . '.';
            throw new BadMethodCallException($message);
        }

        return call_user_func_array(array($this, '_' . $method), $args);
    }

    /**
     * @param string $method
     * @return bool
     */
    private static function isFactoryMethod($method)
    {
        static $supportedFactoryMethods = array('select', 'insert', 'update', 'delete');

        return in_array($method, $supportedFactoryMethods, true);
    }

    /**
     * Returns the table expression from data, throwing if it is missing or not valid.
     * See SqlValidator::assertAlias() for the exact accepted table-expression syntax.
     * @throws InvalidArgumentException
     * @return string
     */
    private function requireTable()
    {
        if (!isset($this->data['table'])) {
            throw new InvalidArgumentException("Table is required.");
        }
        SqlValidator::assertAlias($this->data['table']);
        return $this->data['table'];
    }

    /**
     * Returns the plain table name from data, throwing if it is missing or not a valid
     * plain identifier (schema-qualified identifiers are allowed; aliases are not).
     * Used for INSERT, where table aliases are not valid SQL.
     * See SqlValidator::assertTable() for the exact accepted syntax.
     * @throws InvalidArgumentException
     * @return string
     */
    private function requirePlainTable()
    {
        if (!isset($this->data['table'])) {
            throw new InvalidArgumentException("Table is required.");
        }
        SqlValidator::assertTable($this->data['table']);
        return $this->data['table'];
    }

    /**
     * Returns the fields array from data, throwing if it is missing or empty.
     * @throws InvalidArgumentException
     * @return array
     */
    private function requireFields()
    {
        if (!isset($this->data['fields']) || !is_array($this->data['fields']) || empty($this->data['fields'])) {
            throw new InvalidArgumentException("Fields must be a non-empty array.");
        }
        return $this->data['fields'];
    }

    /**
     * Appends any stored JOIN clauses to the SQL string.
     * Handles both array and legacy string values in $data['joins'].
     * @param string &$sql SQL string to append to.
     */
    private function appendJoinsToSql(&$sql)
    {
        if (empty($this->data['joins'])) {
            return;
        }
        $joins = is_array($this->data['joins']) ? $this->data['joins'] : array($this->data['joins']);
        foreach ($joins as $join) {
            $sql .= " {$join}";
        }
    }

    /**
     * Validates $table and $condition, builds a typed JOIN expression, and
     * delegates to join(). Used by innerJoin(), leftJoin(), rightJoin(), fullJoin().
     *
     * @param string $type      SQL join keyword (e.g. 'INNER JOIN').
     * @param string $table     Table expression (e.g. `orders o`).
     * @param string $condition ON condition (e.g. `o.user_id = users.id`).
     * @return $this
     * @throws InvalidArgumentException If $table or $condition is not a non-empty string.
     */
    private function buildTypedJoin($type, $table, $condition)
    {
        if (!is_string($table) || trim($table) === '') {
            throw new InvalidArgumentException(
                'typed join expects $table to be a non-empty string.'
            );
        }
        if (!is_string($condition) || trim($condition) === '') {
            throw new InvalidArgumentException(
                'typed join expects $condition to be a non-empty string.'
            );
        }
        return $this->join("{$type} {$table} ON {$condition}");
    }

    /**
     * Validates and returns the stored LIMIT value.
     * Returns 0 when no valid positive limit is set (meaning "no LIMIT clause").
     * @return int
     */
    private function getValidatedLimit()
    {
        $limit = $this->getValidatedIntegerOption('limit', 0);
        return ($limit !== null && $limit > 0) ? $limit : 0;
    }

    /**
     * Renders SELECT fields, validating that each element is a non-empty string.
     *
     * @param array $fields
     * @return string
     */
    private function renderSelectFields($fields)
    {
        if (!is_array($fields)) {
            throw new InvalidArgumentException('SELECT fields must be provided as an array.');
        }

        $rendered = array();
        foreach ($fields as $field) {
            if (!is_string($field)) {
                throw new InvalidArgumentException('Each SELECT field must be a string.');
            }

            if (trim($field) === '') {
                throw new InvalidArgumentException('Each SELECT field must be a non-empty string.');
            }

            $rendered[] = $field;
        }

        return implode(', ', $rendered);
    }

    /**
     * Validates and returns a GROUP BY expression.
     *
     * @param string $groupBy
     * @return string
     */
    private function renderGroupBy($groupBy)
    {
        return SqlValidator::assertGroupBy($groupBy);
    }

    /**
     * Validates and returns an ORDER BY expression.
     *
     * @param string $orderBy
     * @return string
     */
    private function renderOrderBy($orderBy)
    {
        return SqlValidator::assertOrderBy($orderBy);
    }

    /**
     * Builds an UPDATE SET clause.
     *
     * @param array $fields
     * @return string
     */
    private function buildSetClause($fields)
    {
        return PdoParameterBuilder::buildSetClause($fields);
    }

    /**
     * Normalizes the optional $fields argument accepted by insert() and update().
     * A string is wrapped in an array; an array is used as-is; anything else throws.
     * @param mixed  $fields  The value to normalize.
     * @param string $context Method name used in exception messages (e.g. 'Query::insert()').
     * @throws InvalidArgumentException
     * @return array
     */
    private static function normalizeOptionalFields($fields, $context)
    {
        if (is_string($fields)) {
            return array($fields);
        }
        if (is_array($fields)) {
            return $fields;
        }
        throw new InvalidArgumentException("{$context} expects \$fields to be an array or string.");
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return $this
     */
    private function setDataValue($key, $value)
    {
        $this->data[$key] = $value;
        return $this->invalidateQuery();
    }

    /**
     * @return $this
     */
    private function invalidateQuery()
    {
        $this->query = null;
        return $this;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $minValue
     * @param string $message
     * @return $this
     */
    private function setValidatedIntegerOption($key, $value, $minValue, $message)
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => $minValue))
        );
        if ($validated === false) {
            throw new InvalidArgumentException($message);
        }

        $this->data[$key] = (int) $validated;
        return $this->invalidateQuery();
    }

    /**
     * @param string $key
     * @param int    $minValue
     * @return int|null
     */
    private function getValidatedIntegerOption($key, $minValue)
    {
        $value = filter_var(
            isset($this->data[$key]) ? $this->data[$key] : null,
            FILTER_VALIDATE_INT,
            array('options' => array('min_range' => $minValue))
        );

        return $value === false ? null : (int) $value;
    }

    /**
     * @return bool
     */
    private function disableDatabaseValidationIfNeeded()
    {
        if ($this->isValidationEnabled() || !$this->database->isValidationEnabled()) {
            return false;
        }

        $this->database->validation(false);
        return true;
    }

    /**
     * @param bool $restore
     */
    private function restoreDatabaseValidation($restore)
    {
        if ($restore) {
            $this->database->validation(true);
        }
    }

    /**
     * @param string $method
     * @param array  $data
     * @return array|string|int
     */
    private function runWithQueryRunner($method, array $data)
    {
        switch ($method) {
            case 'SELECT':
                return $this->queryRunner->runSelect($this, $data);
            case 'INSERT':
                return $this->queryRunner->runInsert($this, $data);
            case 'UPDATE':
                return $this->queryRunner->runUpdate($this, $data);
            case 'DELETE':
                return $this->queryRunner->runDelete($this, $data);
        }

        throw new InvalidArgumentException(
            "run() does not support query method '{$this->data['method']}'."
        );
    }
}
