<?php

/**
 * Database.php
 *
 * Provides the Database base class with all common database operations:
 * querying, inserting, updating, deleting, counting, and transaction management.
 *
 * @author DavidPerez-2357
 * @link https://github.com/DavidPerez-2357/DatabaseMethods
 */

/**
 * Database class to handle database operations using PDO.
 * Provides methods for executing queries, inserting, updating, deleting, and selecting records.
 *
 * @package DatabaseMethods
 */
class Database
{
    private $properties; // Array with the initial properties of the class
    private $conn; // connection variable
    private $json_encode = false; // Default value for json_encode
    private $keywordCheckEnabled = true; // Default value for keyword checking
    private $validationEnabled = true; // Default value for validation checks
    private $dialect; // SQL dialect used for Query rendering

    /**
     * Associative array of supported JOIN types for this driver.
     *
     * Maps a human-readable join name to the SQL keyword used in queries.
     * Child drivers may override this property to restrict or extend the
     * list of supported join types for the underlying SQL engine.
     *
     * Example entry: 'INNER' => 'INNER JOIN'
     *
     * @var array
     */
    protected $supportedJoins = array(
        'INNER' => 'INNER JOIN',
        'LEFT'  => 'LEFT JOIN',
        'RIGHT' => 'RIGHT JOIN',
        'FULL'  => 'FULL JOIN',
    );

    public function __construct($ppt)
    {
        $this->properties = $ppt;
        $this->dialect = new DefaultSqlDialect();
    }

    public function __call($method, $args)
    {
        static $allowedMethods = ['select', 'selectone', 'insert', 'update', 'delete', 'deleteall', 'count'];
        static $canonicalNames = [
            'selectone' => 'selectOne',
            'deleteall' => 'deleteAll',
        ];
        $lower = strtolower($method);
        if (!in_array($lower, $allowedMethods, true)) {
            throw new BadMethodCallException("Method '{$method}' does not exist in " . get_class($this) . ".");
        }

        // Normalize to canonical camelCase method name
        $canonical = isset($canonicalNames[$lower]) ? $canonicalNames[$lower] : $lower;

        // Replace keywords in every array argument before dispatching
        if ($this->keywordCheckEnabled) {
            foreach ($args as $i => $arg) {
                if (is_array($arg)) {
                    $args[$i] = $this->replaceKeywordsInData($arg);
                }
            }
        }

        return call_user_func_array([$this, $canonical], $args);
    }

    protected function setConnection($conn)
    {
        $this->conn = $conn;
    }

    /**
     * Returns the first non-null value found in $ppt for the given ordered list of $keys,
     * or $default when none of the keys are present.
     *
     * Canonical connection-config keys and their accepted aliases:
     *  - serverName  : host      - hostname or IP address of the database server
     *  - username    : user      - database user
     *  - DB          : dbname    - database/schema identifier for server-based drivers
     *                             and the SQLite database file path when using the
     *                             SQLite driver (used as dsn "sqlite:{DB}")
     *  - password    : (none)    - user password
     *  - codification: (none)    - character encoding (e.g. "utf8", "utf8mb4")
     *
     * @param array $ppt     Configuration array passed to the driver constructor.
     * @param array $keys    Ordered list of key names to try (first match wins).
     * @param mixed $default Value to return when none of the keys are present.
     * @return mixed
     */
    protected function getConfigValue(array $ppt, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (isset($ppt[$key])) {
                return $ppt[$key];
            }
        }
        return $default;
    }

    /**
     * Enables or disables JSON encoding of query results.
     *
     * When enabled, methods such as select(), selectOne(), and plainSelect()
     * will return a JSON-encoded string
     * instead of a PHP array.
     * If JSON encoding fails (e.g. the result contains invalid UTF-8), an
     * empty array is returned instead.
     *
     * @param bool $bool True to enable JSON encoding, false to disable.
     * @return $this
     */
    public function setJsonEncode($bool)
    {
        $this->json_encode = (bool) $bool;
        return $this;
    }

    /**
     * Enables or disables keyword replacement in query data arrays.
     *
     * When enabled (the default), special placeholder strings such as
     * @currentDate, @currentDateTime, @randomInt, and @lastInsertId are
     * automatically replaced with their actual values for operations routed
     * through the magic __call() method (select, insert, update, delete,
     * deleteAll, count, selectOne). Methods such as runPlainQuery() and
     * plainSelect() are not affected and never perform keyword replacement.
     * Set to false to pass data values through unmodified.
     *
     * @param bool $bool True to enable keyword checking, false to disable.
     * @return $this
     */
    public function enableKeywordCkeck($bool)
    {
        $this->keywordCheckEnabled = (bool) $bool;
        return $this;
    }

    /**
     * Enables or disables Database-level validation checks.
     *
     * Validation is enabled by default.
     *
     * @param bool $enabled True to enable validation, false to disable.
     * @return $this
     * @throws InvalidArgumentException If $enabled is not a boolean.
     */
    public function validation($enabled = true)
    {
        if (!is_bool($enabled)) {
            throw new InvalidArgumentException(
                'Database::validation() expects a boolean argument.'
            );
        }

        $this->validationEnabled = $enabled;
        return $this;
    }

    /**
     * Returns whether Database-level validation checks are enabled.
     *
     * @return bool
     */
    public function isValidationEnabled()
    {
        return $this->validationEnabled;
    }

    /**
     * Returns the list of JOIN types supported by this driver.
     *
     * Each entry maps a human-readable join name to the SQL keyword.
     * Drivers that do not support certain join types (e.g. SQLite does not
     * support RIGHT JOIN or FULL JOIN) will return a restricted list.
     *
     * @return array Associative array of supported join types (e.g. ['INNER' => 'INNER JOIN', ...]).
     */
    public function getSupportedJoinTypes()
    {
        return $this->supportedJoins;
    }

    /**
     * Creates a blank Query instance linked to this database.
     *
     * Call one of the fluent factory methods on the returned object to configure
     * the query type, then chain additional setters and finally call `run()` to
     * execute it:
     *
     * ```php
     * $rows = $db->createQuery()->select(['id', 'name'])->from('users')->run();
     * $id   = $db->createQuery()->insert('users', ['name'])->run(['name' => 'Alice']);
     * $n    = $db->createQuery()->update('users', ['name'])->where('id = :id')->run(['name' => 'Bob', 'id' => 1]);
     * $n    = $db->createQuery()->delete('users')->where('id = :id')->run(['id' => 5]);
     * ```
     *
     * The returned Query is pre-configured with the driver's SQL dialect and holds
     * a reference back to this Database, so `run()` executes through this connection.
     *
     * @return Query
     */
    public function createQuery()
    {
        $query = new Query();
        $query->setDatabase($this);
        return $query;
    }

    /**
     * Returns the SQL dialect associated with this database driver.
     *
     * @return SqlDialect
     */
    public function getDialect()
    {
        return $this->dialect;
    }

    /**
     * Quotes a SQL identifier using this database's dialect.
     *
     * Delegates to the driver dialect's quoteIdentifier() method, so the
     * correct quoting character is used automatically (backticks for MySQL,
     * double-quotes for PostgreSQL, SQLite, and SQL Server).
     *
     * @param string $identifier A single identifier segment (no dots; quote each segment separately).
     * @return string The quoted identifier.
     * @throws InvalidArgumentException If $identifier is not a non-empty string or contains a dot.
     * @example
     * ```php
     * // With a MySQL connection
     * $db->quote('order')   // => '`order`'
     *
     * // With a PostgreSQL / SQLite / SQL Server connection
     * $db->quote('order')   // => '"order"'
     *
     * // Schema-qualified: quote each segment individually
     * $db->quote('public') . '.' . $db->quote('order')
     * ```
     */
    public function quote($identifier)
    {
        if (!is_string($identifier) || trim($identifier) === '') {
            throw new InvalidArgumentException('Database::quote() expects a non-empty string.');
        }

        if (strpos($identifier, '.') !== false) {
            throw new InvalidArgumentException(
                'Database::quote() expects a single identifier segment with no dots; quote each segment separately.'
            );
        }

        return $this->dialect->quoteIdentifier($identifier);
    }

    /**
     * Sets the SQL dialect associated with this database driver.
     * Protected because the dialect is determined by the driver at construction time
     * and should not be changed through the public API; use {@see getDialect()} to read it.
     *
     * @param SqlDialect $dialect
     * @return $this
     */
    protected function setDialect(SqlDialect $dialect)
    {
        $this->dialect = $dialect;
        return $this;
    }

    /**
     * Replaces keywords in the data array with actual values.
     * This method is used to replace placeholders like @lastInsertId, @currentDate, and @currentDateTime.
     * Supports flat associative arrays and multi-row arrays (where every element is an associative array,
     * e.g., for insertMany). Arbitrary deeply nested structures are not recursed into.
     * @param mixed $data The data array containing the placeholders, or a non-array value (returned as-is).
     * @return mixed The modified data with placeholders replaced, or the original value if not an array.
     */
    protected function replaceKeywordsInData($data)
    {
        if (empty($data) || !is_array($data)) {
            return $data;
        }

        // Detect a true multi-row array: the array must be a sequential list (0-indexed numeric
        // keys) and every element must be an array. Requiring sequential numeric keys avoids a
        // false positive for associative single-row payloads whose every value happens to be an
        // array (e.g. a JSON/metadata column), which would otherwise route them down the
        // recursive branch and skip scalar keyword replacement.
        // array_is_list() is PHP 8.1+; use the equivalent keys check for PHP 5.4 compatibility.
        $isList = (array_keys($data) === range(0, count($data) - 1));
        $allArrays = true;
        foreach ($data as $value) {
            if (!is_array($value)) {
                $allArrays = false;
                break;
            }
        }
        if ($isList && $allArrays) {
            foreach ($data as $key => $row) {
                $data[$key] = $this->replaceKeywordsInData($row);
            }
            return $data;
        }

        $keywords = [
            // Date and time
            '@currentDate' => date('Y-m-d'),
            '@currentDateTime' => date('Y-m-d H:i:s'),
            '@currentTime' => date('H:i:s'),
            '@currentTimestamp' => time(),
            '@currentYear' => date('Y'),
            '@currentMonth' => date('m'),
            '@currentDay' => date('d'),
            '@currentWeekday' => date('l'),

            // Random values
            '@randomString' => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8),
            '@randomInt' => rand(1, 9999),
            '@randomFloat' => rand(1, 9999) / 100,
            '@randomBoolean' => rand(0, 1) ? true : false,

            // Custom keywords can be added here
        ];

        // @lastInsertId is computed lazily: getLastInsertId() requires a connection and is
        // unnecessary when the keyword is not present in the data.
        if (in_array('@lastInsertId', $data, true)) {
            $keywords['@lastInsertId'] = $this->getLastInsertId();
        }

        // Replace values that are keywords like @lastInsertId, @currentDate, @currentDateTime
        foreach ($data as $key => $value) {
            if (is_string($value) && isset($keywords[$value])) {
                $data[$key] = $keywords[$value];
            }
        }

        return $data;
    }

    /**
     * Executes a plain SQL write statement and returns the affected row count.
     * Use plainSelect() to execute queries that return a result set.
     * @param string $query The SQL statement to execute.
     * @param array $data Optional parameters for the query.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The PDO-reported rowCount() (may be 0 for DDL statements).
     */
    public function runPlainQuery($query, $data = [])
    {
        $this->requireConnection();

        $stmt = $this->prepareAndExecute($query, $data);

        return $stmt->rowCount();
    }

    /**
     * Executes a plain SQL query and returns all result rows.
     * Use runPlainQuery() for write statements that do not return a result set.
     * @param string $query The SQL query to execute.
     * @param array $data Optional parameters for the query.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return array|string All rows as an associative array, or a JSON-encoded string when json_encode mode is enabled.
     */
    public function plainSelect($query, $data = [])
    {
        $this->requireConnection();

        $stmt = $this->prepareAndExecute($query, $data);

        return $this->formatResult($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Executes a SELECT query and returns a single row.
     * @param Query|string $query A Query object (limit(1) is applied automatically) or a raw SQL string.
     * @param array $data Optional parameters for the query.
     * @throws InvalidArgumentException if $query is neither a Query instance nor a string.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return array|string The result row as an associative array, or a JSON-encoded string when json_encode mode is enabled.
     */
    private function selectOne($query, $data = [])
    {
        if (!($query instanceof Query) && !is_string($query)) {
            throw new InvalidArgumentException(
                'selectOne() expects $query to be a Query instance or a string.'
            );
        }

        if ($query instanceof Query) {
            $query->setDialect($this->getDialect());
            $query->limit(1);
        }
        $sql = (string) $query;

        $this->requireConnection();

        $stmt = $this->prepareAndExecute($sql, $data);

        // Fetch a single row as an associative array
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->formatResult($row === false ? array() : $row);
    }

    /**
     * Executes a SELECT query and returns all results.
     * @param Query|string $query A Query object or a raw SQL string.
     * @param array $data Optional parameters for the query.
     * @throws InvalidArgumentException if $query is neither a Query instance nor a string.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return array|string The result set as an associative array, or a JSON-encoded string if json_encode is enabled.
     */
    private function select($query, $data = [])
    {
        if (!($query instanceof Query) && !is_string($query)) {
            throw new InvalidArgumentException(
                'select() expects $query to be a Query instance or a string.'
            );
        }

        if ($query instanceof Query) {
            $query->setDialect($this->getDialect());
        }
        $sql = (string) $query;

        $this->requireConnection();

        $stmt = $this->prepareAndExecute($sql, $data);

        // Fetch all results as an associative array
        return $this->formatResult($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Inserts records into the specified table using the Query class.
     * This method detects if the data is a single record or multiple records and calls the appropriate method.
     * @param string $table The name of the table to insert into.
     * @param array $dataToInsert An associative array of column names and values to insert,
     *                            or an array of such arrays for multiple records.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The ID of the last inserted row or the number of affected rows for multiple inserts.
     */
    private function insert($table, $dataToInsert)
    {
        // Detect if the data is a single record or multiple records
        if (isset($dataToInsert[0]) && is_array($dataToInsert[0])) {
            // Multiple records: keywords were already replaced by __call before dispatch.
            return $this->insertMany($table, $dataToInsert);
        } else {
            // Single record
            return $this->insertOne($table, $dataToInsert);
        }
    }

    /**
     * Inserts a single record into the specified table using the Query class.
     * @param string $table The name of the table to insert into.
     * @param array $fieldsToInsert An associative array of column names and values to insert.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The ID of the last inserted row.
     */
    private function insertOne($table, $fieldsToInsert)
    {
        $this->requireConnection();

        $query = new Query([
            'method' => 'INSERT',
            'table' => $table,
            'fields' => array_keys($fieldsToInsert),
            'values_to_insert' => 1
        ]);
        $query->setDialect($this->getDialect());

        $this->prepareAndExecute((string) $query, PdoParameterBuilder::buildInsertParams(array($fieldsToInsert)));
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Inserts multiple records into the specified table using the Query class.
     * @param string $table The name of the table to insert into.
     * @param array $rowsToInsert An array of associative arrays, each representing a row to insert.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The ID of the last inserted row.
     */
    private function insertMany($table, $rowsToInsert)
    {
        $this->requireConnection();

        if (empty($rowsToInsert) || !isset($rowsToInsert[0]) || !is_array($rowsToInsert[0])) {
            throw new InvalidArgumentException("Rows to insert must be a non-empty array of associative arrays.");
        }

        $expectedFields = array_keys($rowsToInsert[0]);
        foreach ($rowsToInsert as $i => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException("Row at index {$i} must be an associative array.");
            }
            foreach ($expectedFields as $field) {
                if (!array_key_exists($field, $row)) {
                    throw new InvalidArgumentException("Row at index {$i} is missing required field '{$field}'.");
                }
            }

            $unexpectedFields = array_diff(array_keys($row), $expectedFields);
            if (!empty($unexpectedFields)) {
                throw new InvalidArgumentException(
                    "Row at index {$i} contains unexpected field(s): '" . implode("', '", $unexpectedFields) . "'."
                );
            }
        }

        $query = new Query([
            'method' => 'INSERT',
            'table' => $table,
            'fields' => $expectedFields,
            'values_to_insert' => count($rowsToInsert)
        ]);
        $query->setDialect($this->getDialect());

        $this->prepareAndExecute((string) $query, PdoParameterBuilder::buildInsertParams($rowsToInsert));
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Prepares and executes a SQL statement with the given parameters.
     * PHP null values are explicitly bound as SQL NULL (PDO::PARAM_NULL) so that
     * they are stored as NULL rather than being cast to an empty string by some drivers.
     * For positional placeholders, bind values sequentially from 1..N to match
     * PDO execute([...]) semantics even when the input array has non-sequential
     * or non-zero-based numeric keys.
     * For named placeholders, normalize the key so that both ':name' and 'name'
     * forms work across all PDO drivers (add ':' prefix when missing).
     * Mixed positional and named keys are rejected to avoid silently mis-binding
     * parameters.
     * @param string $sql The SQL query to prepare and execute.
     * @param array $params Optional parameter bindings for the statement.
     * @throws InvalidArgumentException if $params is not an array or contains mixed/invalid keys.
     * @throws RuntimeException if preparation, binding, or execution fails.
     * @return PDOStatement The executed statement.
     */
    private function prepareAndExecute($sql, $params = [])
    {
        if (!is_array($params)) {
            throw new InvalidArgumentException('$params must be an array.');
        }

        $hasPositionalParams = false;
        $hasNamedParams      = false;
        foreach (array_keys($params) as $key) {
            if (is_int($key)) {
                $hasPositionalParams = true;
            } else {
                $hasNamedParams = true;
            }
        }

        if ($hasPositionalParams && $hasNamedParams) {
            throw new InvalidArgumentException('Mixed positional and named parameters are not supported.');
        }

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            $errorInfo = $this->conn->errorInfo();
            throw new RuntimeException(
                "Query preparation failed: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Unknown error')
            );
        }

        if ($hasPositionalParams) {
            $this->bindPositionalParams($stmt, $params);
        } else {
            $this->bindNamedParams($stmt, $params);
        }

        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            throw new RuntimeException(
                "Query execution failed: " . (isset($errorInfo[2]) ? $errorInfo[2] : 'Unknown error')
            );
        }

        return $stmt;
    }

    /**
     * Binds positional (?) parameters sequentially from 1..N.
     * @param PDOStatement $stmt
     * @param array $params Values to bind (re-indexed from 0 via array_values).
     * @throws RuntimeException if any bindValue call fails.
     */
    private function bindPositionalParams($stmt, $params)
    {
        foreach (array_values($params) as $position => $value) {
            $this->bindOneValue($stmt, $position + 1, $value);
        }
    }

    /**
     * Binds named (:name) parameters, accepting both 'name' and ':name' key forms.
     * Keys are validated against /^:[A-Za-z_][A-Za-z0-9_]*$/ after normalization,
     * multiple leading colons are rejected, and duplicate normalized keys are rejected.
     * @param PDOStatement $stmt
     * @param array $params Associative array of placeholder names to values.
     * @throws InvalidArgumentException if any key is not a valid, unique named placeholder.
     * @throws RuntimeException if any bindValue call fails.
     */
    private function bindNamedParams($stmt, $params)
    {
        if (!$this->validationEnabled) {
            $seenPlaceholders = array();

            foreach ($params as $key => $value) {
                $placeholder = $this->normalizeFastPathPlaceholder($key);

                if (isset($seenPlaceholders[$placeholder])) {
                    throw new InvalidArgumentException(
                        "Duplicate named placeholder '{$placeholder}' in Database fast validation path."
                    );
                }

                $seenPlaceholders[$placeholder] = true;
                $this->bindOneValue($stmt, $placeholder, $value);
            }
            return;
        }

        $normalizedParams = PdoParameterBuilder::normalizeNamedBindings($params);

        foreach ($normalizedParams as $normalizedKey => $value) {
            $this->bindOneValue($stmt, $normalizedKey, $value);
        }
    }

    /**
     * Normalizes a placeholder key for fast-path named binding when validation is disabled.
     *
     * @param mixed $placeholder
     * @return mixed
     */
    private function normalizeFastPathPlaceholder($placeholder)
    {
        if (is_string($placeholder) && $placeholder !== '' && $placeholder[0] !== ':') {
            $placeholder = ':' . $placeholder;
        }

        if (is_string($placeholder) && !preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $placeholder)) {
            throw new InvalidArgumentException(
                "Invalid named placeholder '{$placeholder}' in Database fast validation path."
            );
        }

        return $placeholder;
    }

    /**
     * Binds a single value to a statement placeholder, mapping PHP null to SQL NULL.
     * @param PDOStatement $stmt
     * @param int|string $param Placeholder index (1-based int) or name (':name').
     * @param mixed $value Value to bind.
     * @throws RuntimeException if bindValue fails.
     */
    private function bindOneValue($stmt, $param, $value)
    {
        if ($value === null) {
            $bound = $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $bound = $stmt->bindValue($param, $value);
        }

        if (!$bound) {
            $errorInfo    = $stmt->errorInfo();
            $driverDetail = isset($errorInfo[2]) && $errorInfo[2] !== '' ? ': ' . $errorInfo[2] : '';
            throw new RuntimeException("Parameter binding failed for placeholder " . $param . $driverDetail);
        }
    }

    /**
     * Normalizes an associative WHERE bindings array into PDO named-parameter form.
     * Adds a ':' prefix to keys that lack one, validates that each normalized key is a
     * legal PDO named-parameter name (matching /^:[A-Za-z_][A-Za-z0-9_]*$/), and detects
     * both intra-array duplicates and collisions against an already-built placeholder map.
     * @param array $whereData Associative array of placeholder names to values.
     * @param array $existingPlaceholders Already-built placeholder map to check for SET-vs-WHERE conflicts.
     * @throws InvalidArgumentException if a key is invalid, malformed, duplicated, or conflicts
     *                                   with $existingPlaceholders.
     * @return array Normalized placeholder array with ':'-prefixed keys.
     */
    private function normalizeNamedWhereBindings($whereData, $existingPlaceholders = [])
    {
        return PdoParameterBuilder::normalizeNamedWhereBindings($whereData, $existingPlaceholders);
    }

    /**
     * Throws a RuntimeException if the database connection has not been established.
     * @throws RuntimeException
     */
    private function requireConnection()
    {
        if (!$this->conn) {
            throw new RuntimeException("Database connection is not set.");
        }
    }

    /**
     * Detects whether $whereData uses named or positional placeholders, normalizes
     * accordingly, and returns the resolved bindings array.
     * Named placeholders (any string key) are forwarded to normalizeNamedWhereBindings();
     * positional placeholders (all integer keys) are returned as a 0-indexed list.
     * @param array $whereData
     * @return array
     */
    private function resolveWhereBindings($whereData)
    {
        return PdoParameterBuilder::resolveWhereBindings($whereData);
    }

    /**
     * Returns $result encoded as JSON when json_encode mode is enabled,
     * or returns the plain value otherwise.
     * On json_encode failure an empty array is returned.
     * @param mixed $result
     * @return mixed
     */
    private function formatResult($result)
    {
        if ($this->json_encode) {
            $json = json_encode($result);
            return $json === false ? array() : $json;
        }
        return $result;
    }

    /**
     * Updates records in the specified table using the Query class.
     * @param string $table The name of the table to update.
     * @param array $fieldsToUpdate An associative array of column names and values to update.
     * @param string $where The WHERE clause to specify which records to update.
     * @param array $whereData Optional associative array of bindings for the WHERE clause.
     *                         Keys must not overlap with the column names in $fieldsToUpdate.
     *                         Each key must be a valid PDO named-parameter name
     *                         (letters, digits, underscores; starting with a letter or underscore).
     * @param array $joins Optional joins for the query.
     * @throws InvalidArgumentException if $fieldsToUpdate or $whereData is invalid, or a binding key
     *                                   conflicts between $fieldsToUpdate and $whereData.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The number of affected rows.
     */
    private function update($table, $fieldsToUpdate, $where, $whereData = [], $joins = [])
    {
        $this->requireConnection();

        if (!is_array($whereData)) {
            throw new InvalidArgumentException(
                "\$whereData must be an associative array of placeholder names to values."
            );
        }

        if (empty($fieldsToUpdate) || !is_array($fieldsToUpdate)) {
            throw new InvalidArgumentException("Fields to update must be a non-empty associative array.");
        }

        // $whereData must be associative (string keys only); numeric/list-style arrays are not supported.
        foreach (array_keys($whereData) as $k) {
            if (!is_string($k)) {
                throw new InvalidArgumentException(
                    "\$whereData must be an associative array with string keys; " .
                    "numeric or list-style arrays are not supported."
                );
            }
        }

        // update() always generates named SET placeholders; positional '?' in $where is not supported.
        if (is_string($where) && strpos($where, '?') !== false) {
            throw new InvalidArgumentException(
                "Positional placeholders ('?') are not supported in \$where for update(); " .
                "use named placeholders (e.g. 'id = :id') and pass their values via \$whereData."
            );
        }

        $query = new Query([
            'method' => 'UPDATE',
            'table' => $table,
            'fields' => array_keys($fieldsToUpdate),
            'where' => $where,
            'joins' => $joins,
        ]);
        $query->setDialect($this->getDialect());

        $placeholders = PdoParameterBuilder::buildNamedParams($fieldsToUpdate);

        $placeholders = array_merge(
            $placeholders,
            $this->normalizeNamedWhereBindings($whereData, $placeholders)
        );

        $stmt = $this->prepareAndExecute((string) $query, $placeholders);
        return (int) $stmt->rowCount();
    }

    /**
     * Deletes records from the specified table using the Query class.
     * @param string $table The name of the table to delete from.
     * @param string $where The WHERE clause to specify which records to delete.
     * @param array $whereData Optional bindings for the WHERE clause.
     *                         For named placeholders (e.g. `id = :id`), pass an associative array;
     *                         keys are normalized to include a leading `:` if absent.
     *                         For positional placeholders (e.g. `id = ?`), pass a list-style array.
     * @param string $orderBy Optional ORDER BY clause.
     * @param int $limit Optional limit for the deletion.
     * @throws InvalidArgumentException if $whereData is not an array or contains invalid named keys.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The number of affected rows.
     */
    private function delete($table, $where, $whereData = [], $orderBy = "", $limit = 0)
    {
        $this->requireConnection();

        if (empty($table) || empty($where)) {
            throw new InvalidArgumentException('Table and where clause are required.');
        }

        if (!is_array($whereData)) {
            throw new InvalidArgumentException("\$whereData must be an array of bindings for the WHERE clause.");
        }

        $query = new Query([
            'method' => 'DELETE',
            'table' => $table,
            'where' => $where,
            'order_by' => $orderBy,
            'limit' => $limit
        ]);
        $query->setDialect($this->getDialect());

        $stmt = $this->prepareAndExecute((string) $query, $this->resolveWhereBindings($whereData));
        return (int) $stmt->rowCount();
    }

    /**
     * Deletes all records from the specified table using the Query class.
     * @param string $table The name of the table to delete from.
     * @param string $orderBy Optional ORDER BY clause.
     * @param int $limit Optional limit for the deletion.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The number of affected rows.
     */
    private function deleteAll($table, $orderBy = "", $limit = 0)
    {
        $this->requireConnection();

        if (empty($table)) {
            throw new InvalidArgumentException('Table is required.');
        }

        $query = new Query([
            'method' => 'DELETE',
            'table' => $table,
            'order_by' => $orderBy,
            'limit' => $limit
        ]);
        $query->setDialect($this->getDialect());

        $stmt = $this->prepareAndExecute((string) $query, []);
        return (int) $stmt->rowCount();
    }

    /**
     * Counts the number of records in the specified table using the Query class.
     * @param string $table The name of the table to count records from.
     * @param string $where Optional WHERE clause to filter the count.
     * @param array $whereData Optional bindings for the WHERE clause.
     *                         For named placeholders (e.g. `active = :active`), pass an associative array;
     *                         keys are normalized to include a leading `:` if absent.
     *                         For positional placeholders (e.g. `active = ?`), pass a list-style array.
     * @param array $joins Optional joins for the query.
     * @throws InvalidArgumentException if $table is not a valid SQL identifier, or if $whereData is not an array or contains invalid named keys.
     * @throws RuntimeException if the connection is not set or the query execution fails.
     * @return int The count of records.
     */
    private function count($table, $where = '', $whereData = [], $joins = [])
    {
        $this->requireConnection();

        SqlValidator::assertTable($table);

        if (!is_array($whereData)) {
            throw new InvalidArgumentException("\$whereData must be an array of bindings for the WHERE clause.");
        }

        $query = "SELECT COUNT(*) FROM {$table}";

        if (!empty($joins)) {
            foreach ($joins as $join) {
                $query .= " {$join}";
            }
        }

        if (!empty($where)) {
            $query .= " WHERE {$where}";
        }

        $stmt = $this->prepareAndExecute($query, $this->resolveWhereBindings($whereData));
        return (int) $stmt->fetchColumn();
    }

    /**
     * Executes a transaction with the provided callback.
     * @param callable $callback The callback function to execute within the transaction.
     * @throws RuntimeException if the connection is not set or the transaction fails.
     * @return mixed The result of the callback function.
     */
    public function executeTransaction($callback)
    {
        $this->requireConnection();

        try {
            $this->conn->beginTransaction();
            $result = $callback($this);
            $this->conn->commit();
            return $result;
        } catch (Exception $e) {
            try {
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
            } catch (Exception $rollbackEx) {
                // Ignore rollback errors to preserve the original exception
            }
            throw new RuntimeException("Transaction failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gets the last inserted ID from the database.
     * @throws RuntimeException if the connection is not set.
     * @return int The last inserted ID.
     */
    public function getLastInsertId()
    {
        $this->requireConnection();

        return (int) $this->conn->lastInsertId();
    }
}
