<?php

/**
 * tests/DatabaseTest.php
 *
 * Integration test suite for the Database class.
 *
 * Covers: select, selectOne, insert (single + multiple), update, delete,
 * deleteAll, count, runPlainQuery, plainSelect, executeTransaction,
 * setJsonEncode, and getLastInsertId.
 *
 * A temporary SQLite database file is created automatically before the
 * tests run and deleted when teardown() is called. No configuration is
 * needed for SQLite.
 *
 * To test against MySQL, PostgreSQL, or SQL Server instead:
 *   1. Change DB_TEST_DRIVER to 'mysql', 'postgres', or 'sql'.
 *   2. Fill in DB_TEST_HOST, DB_TEST_USER, DB_TEST_PASS, and DB_TEST_NAME.
 *   3. Run: php tests/run.php
 *
 * @author DavidPerez-2357
 * @link   https://github.com/DavidPerez-2357/DatabaseMethods
 */

// ============================================================
// CONNECTION CONFIGURATION
// Adjust the values below before running the tests.
// SQLite requires no additional setup; a temporary file is used.
// ============================================================

/** Driver to use: 'sqlite', 'mysql', 'postgres', or 'sql'. */
define('DB_TEST_DRIVER', 'sqlite');

/** SQLite only: absolute path for a unique temporary database file. */
define(
    'DB_TEST_FILE',
    sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'dbmethods_test_'
    . getmypid()
    . '_'
    . uniqid('', true)
    . '.sqlite'
);

// MySQL / PostgreSQL / SQL Server - fill in your credentials:
define('DB_TEST_HOST', 'localhost');
define('DB_TEST_USER', 'root');
define('DB_TEST_PASS', '');
define(
    'DB_TEST_NAME',
    'test_dbmethods_'
    . getmypid()
    . '_'
    . str_replace('.', '', uniqid('', true))
);  // Database to CREATE / DROP (unique per run to avoid collisions)
define('DB_TEST_CODIFICATION', 'utf8');            // MySQL / PostgreSQL safe default

// ============================================================

class DatabaseTest
{
    /** @var Database */
    private $db;

    /** Table name used by all tests. */
    const TABLE = 'test_users';

    /** Table used by the NULL-value tests (has a nullable 'notes' column). */
    const NULLABLE_TABLE = 'test_nullable';

    public function __construct()
    {
        $this->db = $this->createDriver();
        $this->createTable();
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================

    /**
     * Creates and returns a driver instance for the configured DB_TEST_DRIVER.
     * SQLite uses the temporary file configured in DB_TEST_FILE. Server-based
     * drivers may require the target test database to exist already, depending
     * on the setup method used for the selected driver.
     */
    private function createDriver()
    {
        switch (DB_TEST_DRIVER) {
            case 'mysql':
                return $this->setupMysql();
            case 'postgres':
                return $this->setupPostgres();
            case 'sql':
                return $this->setupSql();
            default: // sqlite
                return new Sqlite(['DB' => DB_TEST_FILE]);
        }
    }

    private function setupMysql()
    {
        // Connect without a database first so we can CREATE the test database.
        $adminConn = new PDO(
            'mysql:host=' . DB_TEST_HOST . ';charset=' . DB_TEST_CODIFICATION,
            DB_TEST_USER,
            DB_TEST_PASS
        );
        $adminConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $adminConn->exec('CREATE DATABASE IF NOT EXISTS ' . DB_TEST_NAME);

        return new Mysql([
            'serverName'   => DB_TEST_HOST,
            'username'     => DB_TEST_USER,
            'password'     => DB_TEST_PASS,
            'DB'           => DB_TEST_NAME,
            'codification' => DB_TEST_CODIFICATION,
        ]);
    }

    private function setupPostgres()
    {
        // Connect to the default 'postgres' maintenance database to create the test database.
        $adminConn = new PDO(
            'pgsql:host=' . DB_TEST_HOST . ';dbname=postgres',
            DB_TEST_USER,
            DB_TEST_PASS
        );
        $adminConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $adminConn->exec('CREATE DATABASE ' . DB_TEST_NAME);
        } catch (PDOException $e) {
            // PostgreSQL does not support "CREATE DATABASE IF NOT EXISTS".
            // Ignore the duplicate_database error so test setup is idempotent.
            if ($e->getCode() !== '42P04') {
                throw $e;
            }
        }
        return new Postgres([
            'serverName'   => DB_TEST_HOST,
            'username'     => DB_TEST_USER,
            'password'     => DB_TEST_PASS,
            'DB'           => DB_TEST_NAME,
            'codification' => DB_TEST_CODIFICATION,
        ]);
    }

    private function setupSql()
    {
        return new Sql([
            'serverName' => DB_TEST_HOST,
            'username'   => DB_TEST_USER,
            'password'   => DB_TEST_PASS,
            'DB'         => DB_TEST_NAME,
        ]);
    }

    /**
     * Drops the test table and, where supported, removes the test database or
     * file. SQLite deletes DB_TEST_FILE; MySQL and PostgreSQL drop DB_TEST_NAME.
     * SQL Server only drops the test table - it does not create or drop a
     * database, so no extra cleanup is needed.
     * Must be called after all tests have run.
     */
    public function teardown()
    {
        try {
            $this->db->runPlainQuery($this->getDropTableSql());
        } catch (Exception $e) {
            // Best-effort; ignore if already gone.
        }

        switch (DB_TEST_DRIVER) {
            case 'sqlite':
                // Release the PDO connection before deleting the file; on
                // Windows an open handle prevents file deletion.
                $this->db = null;
                if (file_exists(DB_TEST_FILE)) {
                    @unlink(DB_TEST_FILE);
                }
                break;

            case 'mysql':
                try {
                    $conn = new PDO(
                        'mysql:host=' . DB_TEST_HOST . ';charset=' . DB_TEST_CODIFICATION,
                        DB_TEST_USER,
                        DB_TEST_PASS
                    );
                    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $conn->exec('DROP DATABASE IF EXISTS ' . DB_TEST_NAME);
                } catch (Exception $e) {
                    // Best-effort.
                }
                break;

            case 'postgres':
                try {
                    $conn = new PDO(
                        'pgsql:host=' . DB_TEST_HOST . ';dbname=postgres',
                        DB_TEST_USER,
                        DB_TEST_PASS
                    );
                    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    // Terminate any remaining connections to the test database before dropping it.
                    $conn->exec(
                        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity"
                        . " WHERE datname = '" . DB_TEST_NAME . "'"
                    );
                    $conn->exec('DROP DATABASE IF EXISTS ' . DB_TEST_NAME);
                } catch (Exception $e) {
                    // Best-effort.
                }
                break;
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** Returns the CREATE TABLE SQL appropriate for the configured driver. */
    private function getCreateTableSql()
    {
        switch (DB_TEST_DRIVER) {
            case 'mysql':
                return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'id INT AUTO_INCREMENT PRIMARY KEY, '
                    . 'name VARCHAR(255) NOT NULL, '
                    . 'email VARCHAR(255) NOT NULL, '
                    . 'active TINYINT NOT NULL DEFAULT 1)';

            case 'postgres':
                return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'id SERIAL PRIMARY KEY, '
                    . 'name VARCHAR(255) NOT NULL, '
                    . 'email VARCHAR(255) NOT NULL, '
                    . 'active SMALLINT NOT NULL DEFAULT 1)';

            case 'sql':
                return "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='" . self::TABLE . "' AND xtype='U') "
                    . 'CREATE TABLE ' . self::TABLE . ' ('
                    . 'id INT IDENTITY(1,1) PRIMARY KEY, '
                    . 'name NVARCHAR(255) NOT NULL, '
                    . 'email NVARCHAR(255) NOT NULL, '
                    . 'active TINYINT NOT NULL DEFAULT 1)';

            default: // sqlite
                return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                    . 'name TEXT NOT NULL, '
                    . 'email TEXT NOT NULL, '
                    . 'active INTEGER NOT NULL DEFAULT 1)';
        }
    }

    /** Returns the DROP TABLE SQL appropriate for the configured driver. */
    private function getDropTableSql()
    {
        if (DB_TEST_DRIVER === 'sql') {
            return "IF EXISTS (SELECT * FROM sysobjects WHERE name='" . self::TABLE . "' AND xtype='U') "
                . 'DROP TABLE ' . self::TABLE;
        }
        return 'DROP TABLE IF EXISTS ' . self::TABLE;
    }

    private function createTable()
    {
        $this->db->runPlainQuery($this->getCreateTableSql());
    }

    /**
     * Drops and recreates the test table, resetting all rows and the
     * auto-increment counter. Call at the start of each test.
     */
    private function resetTable()
    {
        $this->db->runPlainQuery($this->getDropTableSql());
        $this->db->runPlainQuery($this->getCreateTableSql());
    }

    // =========================================================================
    // Tests - insert
    // =========================================================================

    public function testInsertSingleRecordReturnsPositiveId()
    {
        $this->resetTable();
        $id = $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        if (DB_TEST_DRIVER === 'postgres') {
            // PDO::lastInsertId() requires a sequence name on PostgreSQL; without
            // it, the value may be 0. Only assert the return type is an integer.
            assert_true(is_int($id), 'insert() should return an integer on PostgreSQL.');
            return;
        }
        assert_true(is_int($id) && $id > 0, 'insert() should return a positive integer last-insert ID.');
    }

    public function testInsertSingleRecordIsPersisted()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Bob', 'email' => 'bob@example.com']);
        $rows = $this->db->select('SELECT * FROM ' . self::TABLE);
        assert_equals(1, count($rows));
        assert_equals('Bob', $rows[0]['name']);
        assert_equals('bob@example.com', $rows[0]['email']);
    }

    public function testInsertMultipleRecordsReturnsPositiveId()
    {
        $this->resetTable();
        $data = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob',   'email' => 'bob@example.com'],
            ['name' => 'Carol', 'email' => 'carol@example.com'],
        ];
        $lastId = $this->db->insert(self::TABLE, $data);

        assert_equals(3, $this->db->count(self::TABLE), 'Multi-row insert() should persist all rows.');

        if (DB_TEST_DRIVER === 'postgres') {
            assert_true(
                is_int($lastId),
                'Multi-row insert() should return an integer on PostgreSQL, '
                . 'even if PDO cannot provide a positive last-insert ID without a sequence name.'
            );
            return;
        }
        assert_true(
            is_int($lastId) && $lastId > 0,
            'Multi-row insert() should return a positive integer last-insert ID.'
        );
    }

    public function testInsertMultipleRecordsAreAllPersisted()
    {
        $this->resetTable();
        $data = [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob',   'email' => 'bob@example.com'],
        ];
        $this->db->insert(self::TABLE, $data);
        assert_equals(2, $this->db->count(self::TABLE));
    }

    public function testInsertManyWithExtraFieldInRowThrows()
    {
        $this->resetTable();
        assert_throws('InvalidArgumentException', function () {
            $this->db->insert(self::TABLE, [
                ['name' => 'Alice', 'email' => 'alice@example.com'],
                ['name' => 'Bob',   'email' => 'bob@example.com', 'extra' => 'x'],
            ]);
        });
    }

    // =========================================================================
    // Tests - select / selectOne
    // =========================================================================

    public function testSelectReturnsAllRows()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        $query = Query::select()->from(self::TABLE);
        $rows  = $this->db->select($query);
        assert_equals(2, count($rows));
    }

    public function testSelectReturnsAssociativeArray()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        $query = Query::select(['name', 'email'])->from(self::TABLE);
        $rows  = $this->db->select($query);
        assert_true(array_key_exists('name', $rows[0]));
        assert_true(array_key_exists('email', $rows[0]));
    }

    public function testSelectWithNamedWhereFiltersRows()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        $query = Query::select()->from(self::TABLE)->where('active = :active');
        $rows  = $this->db->select($query, ['active' => 1]);
        assert_equals(1, count($rows));
        assert_equals('Alice', $rows[0]['name']);
    }

    public function testSelectOneReturnsSingleRow()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        $query = Query::select()->from(self::TABLE)->where('name = :name');
        $row   = $this->db->selectOne($query, ['name' => 'Alice']);
        assert_equals('Alice', $row['name']);
    }

    public function testSelectOneReturnsEmptyArrayWhenNoMatch()
    {
        $this->resetTable();
        $query = Query::select()->from(self::TABLE)->where('name = :name');
        $row   = $this->db->selectOne($query, ['name' => 'Nobody']);
        assert_equals([], $row);
    }

    public function testSelectOneAcceptsRawSqlString()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        $row = $this->db->selectOne('SELECT * FROM ' . self::TABLE . ' WHERE name = :name', ['name' => 'Alice']);
        assert_equals('Alice', $row['name']);
    }

    public function testSelectOneThrowsOnNonQueryArgument()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->selectOne(42);
        });
    }

    // =========================================================================
    // Tests - update
    // =========================================================================

    public function testUpdateModifiesRecord()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        // Derive the id via SELECT to avoid relying on lastInsertId() (unreliable on PostgreSQL).
        $rows = $this->db->select(
            'SELECT id FROM ' . self::TABLE . ' WHERE email = :email',
            ['email' => 'alice@example.com']
        );
        assert_true(!empty($rows), 'Inserted row must be retrievable by email.');
        $id   = (int)$rows[0]['id'];

        $this->db->update(self::TABLE, ['name' => 'Alicia'], 'id = :row_id', ['row_id' => $id]);

        $query = Query::select()->from(self::TABLE)->where('id = :id');
        $row   = $this->db->selectOne($query, ['id' => $id]);
        assert_equals('Alicia', $row['name']);
    }

    public function testUpdateReturnsAffectedRowCount()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 1]);

        $affected = $this->db->update(self::TABLE, ['active' => 0], 'active = :a', ['a' => 1]);
        assert_equals(2, $affected);
    }

    public function testUpdateWithNoMatchReturnsZero()
    {
        $this->resetTable();
        $affected = $this->db->update(self::TABLE, ['name' => 'Ghost'], 'id = :id', ['id' => 9999]);
        assert_equals(0, $affected);
    }

    public function testInsertWithQuotedColumnNamePersistsRow()
    {
        // Use the database's own quoting to get the dialect-correct form.
        // SQLite and other ANSI dialects use "name"; MySQL uses `name`.
        $this->resetTable();
        $nameCol  = $this->db->quote('name');
        $emailCol = $this->db->quote('email');
        $id = $this->db->insert(self::TABLE, [$nameCol => 'Quoted', $emailCol => 'q@example.com']);
        assert_true($id > 0 || DB_TEST_DRIVER === 'postgres', 'insert() must return a positive id.');

        $rows = $this->db->select(
            'SELECT name, email FROM ' . self::TABLE . ' WHERE email = :email',
            ['email' => 'q@example.com']
        );
        assert_true(!empty($rows), 'Row inserted with quoted column names must be retrievable.');
        assert_equals('Quoted', $rows[0]['name']);
    }

    public function testUpdateWithQuotedColumnNameModifiesRow()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Before', 'email' => 'before@example.com']);

        $rows = $this->db->select(
            'SELECT id FROM ' . self::TABLE . ' WHERE email = :email',
            ['email' => 'before@example.com']
        );
        assert_true(!empty($rows), 'Inserted row must be retrievable by email.');
        $id = (int)$rows[0]['id'];

        $nameCol = $this->db->quote('name');
        $affected = $this->db->update(self::TABLE, [$nameCol => 'After'], 'id = :id', ['id' => $id]);
        assert_equals(1, $affected);

        $row = $this->db->selectOne(
            Query::select()->from(self::TABLE)->where('id = :id'),
            ['id' => $id]
        );
        assert_equals('After', $row['name']);
    }

    // =========================================================================
    // Tests - delete
    // =========================================================================

    public function testDeleteRemovesMatchingRow()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        // Derive the id via SELECT to avoid relying on lastInsertId() (unreliable on PostgreSQL).
        $rows = $this->db->select(
            'SELECT id FROM ' . self::TABLE . ' WHERE email = :email',
            ['email' => 'alice@example.com']
        );
        assert_true(!empty($rows), 'Inserted row must be retrievable by email.');
        $id   = (int)$rows[0]['id'];

        $this->db->delete(self::TABLE, 'id = :id', ['id' => $id]);
        assert_equals(1, $this->db->count(self::TABLE));
    }

    public function testDeleteReturnsAffectedRowCount()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 1]);

        $affected = $this->db->delete(self::TABLE, 'active = :a', ['a' => 1]);
        assert_equals(2, $affected);
        assert_equals(0, $this->db->count(self::TABLE));
    }

    public function testDeleteWithPositionalPlaceholder()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        // Derive the id via SELECT to avoid relying on lastInsertId() (unreliable on PostgreSQL).
        $rows     = $this->db->select(
            'SELECT id FROM ' . self::TABLE . ' WHERE email = :email',
            ['email' => 'alice@example.com']
        );
        assert_true(!empty($rows), 'Inserted row must be retrievable by email.');
        $id       = (int)$rows[0]['id'];
        $affected = $this->db->delete(self::TABLE, 'id = ?', [$id]);
        assert_equals(1, $affected);
        assert_equals(0, $this->db->count(self::TABLE));
    }

    // =========================================================================
    // Tests - deleteAll
    // =========================================================================

    public function testDeleteAllRemovesAllRows()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        $affected = $this->db->deleteAll(self::TABLE);
        assert_equals(2, $affected);
        assert_equals(0, $this->db->count(self::TABLE));
    }

    public function testDeleteAllOnEmptyTableReturnsZero()
    {
        $this->resetTable();
        assert_equals(0, $this->db->deleteAll(self::TABLE));
    }

    // =========================================================================
    // Tests - count
    // =========================================================================

    public function testCountReturnsTotal()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);
        assert_equals(2, $this->db->count(self::TABLE));
    }

    public function testCountOnEmptyTableReturnsZero()
    {
        $this->resetTable();
        assert_equals(0, $this->db->count(self::TABLE));
    }

    public function testCountWithNamedWhere()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        assert_equals(1, $this->db->count(self::TABLE, 'active = :a', ['a' => 1]));
        assert_equals(1, $this->db->count(self::TABLE, 'active = :a', ['a' => 0]));
    }

    public function testCountWithPositionalWhere()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        assert_equals(1, $this->db->count(self::TABLE, 'active = ?', [1]));
    }

    public function testCountWithSqlInjectionAttemptThrows()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->count('users; SELECT 1');
        });
    }

    public function testCountWithEmptyTableNameThrows()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->count('');
        });
    }

    public function testCountWithTableNameContainingSpacesThrows()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->count('my table');
        });
    }

    // =========================================================================
    // Tests - select (raw SQL string)
    // =========================================================================

    public function testSelectRawSqlReturnsAllRows()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);

        $rows = $this->db->select('SELECT * FROM ' . self::TABLE);
        assert_equals(2, count($rows));
    }

    public function testSelectRawSqlWithNamedBindings()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        $rows = $this->db->select(
            'SELECT * FROM ' . self::TABLE . ' WHERE active = :a',
            ['a' => 1]
        );
        assert_equals(1, count($rows));
        assert_equals('Alice', $rows[0]['name']);
    }

    public function testSelectInvalidArgumentThrows()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->select([]);
        });
    }

    // =========================================================================
    // Tests - runPlainQuery
    // =========================================================================

    public function testRunPlainQueryReturnsAffectedRowCountForWriteQuery()
    {
        $this->resetTable();
        $affected = $this->db->runPlainQuery(
            'INSERT INTO ' . self::TABLE . ' (name, email) VALUES (:name, :email)',
            [':name' => 'Alice', ':email' => 'alice@example.com']
        );
        assert_equals(1, $affected);
        assert_equals(1, $this->db->count(self::TABLE));
    }

    public function testRunPlainQueryUpdateReturnsAffectedCount()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 1]);

        $affected = $this->db->runPlainQuery(
            'UPDATE ' . self::TABLE . ' SET active = 0'
        );
        assert_equals(2, $affected);
    }

    // =========================================================================
    // Tests - plainSelect
    // =========================================================================

    public function testPlainSelectReturnsRowsForSelectQuery()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        $rows = $this->db->plainSelect('SELECT * FROM ' . self::TABLE . ' ORDER BY id ASC');
        assert_equals(2, count($rows));
        assert_equals('Alice', $rows[0]['name']);
    }

    public function testPlainSelectWithBindingsFiltersRows()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com', 'active' => 1]);
        $this->db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com',   'active' => 0]);

        $rows = $this->db->plainSelect(
            'SELECT * FROM ' . self::TABLE . ' WHERE active = :active',
            ['active' => 1]
        );
        assert_equals(1, count($rows));
        assert_equals('Alice', $rows[0]['name']);
    }

    public function testPlainSelectReturnsJsonStringWhenJsonEncodeEnabled()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->db->setJsonEncode(true);
        try {
            $result = $this->db->plainSelect('SELECT name, email FROM ' . self::TABLE);
        } finally {
            $this->db->setJsonEncode(false);
        }

        assert_true(is_string($result), 'plainSelect() should return a JSON string when setJsonEncode(true).');
        $decoded = json_decode($result, true);
        assert_true(is_array($decoded) && count($decoded) === 1);
        assert_equals('Alice', $decoded[0]['name']);
    }

    // =========================================================================
    // Tests - executeTransaction
    // =========================================================================

    public function testExecuteTransactionCommitsOnSuccess()
    {
        $this->resetTable();
        $this->db->executeTransaction(function ($db) {
            $db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
            $db->insert(self::TABLE, ['name' => 'Bob',   'email' => 'bob@example.com']);
        });
        assert_equals(2, $this->db->count(self::TABLE));
    }

    public function testExecuteTransactionRollsBackOnException()
    {
        $this->resetTable();
        try {
            $this->db->executeTransaction(function ($db) {
                $db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
                throw new RuntimeException('Simulated failure');
            });
        } catch (RuntimeException $e) {
            // Expected - the transaction should have been rolled back.
        }
        assert_equals(0, $this->db->count(self::TABLE), 'Rolled-back transaction must leave no rows.');
    }

    // =========================================================================
    // Tests - setJsonEncode
    // =========================================================================

    public function testSetJsonEncodeTrueReturnsJsonStringFromSelect()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->db->setJsonEncode(true);
        try {
            $result = $this->db->select(Query::select(['name', 'email'])->from(self::TABLE));
        } finally {
            $this->db->setJsonEncode(false);
        }

        assert_true(is_string($result), 'select() should return a JSON string when setJsonEncode(true).');
        $decoded = json_decode($result, true);
        assert_true(is_array($decoded) && count($decoded) === 1);
        assert_equals('Alice', $decoded[0]['name']);
    }

    public function testSetJsonEncodeFalseReturnsArray()
    {
        $this->resetTable();
        $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->db->setJsonEncode(false);
        $result = $this->db->select(Query::select()->from(self::TABLE));
        assert_true(is_array($result));
    }

    public function testSetJsonEncodeIsChainable()
    {
        $result = $this->db->setJsonEncode(false);
        assert_true($result === $this->db, 'setJsonEncode() must return $this for chaining.');
    }

    // =========================================================================
    // Tests - enableKeywordCkeck
    // =========================================================================

    public function testKeywordCheckEnabledByDefaultReplacesKeyword()
    {
        $this->resetTable();
        $expectedDate = date('Y-m-d');
        $this->db->insert(self::TABLE, ['name' => '@currentDate', 'email' => 'kw@example.com']);
        $query = Query::select()->from(self::TABLE)->where('email = :email');
        $rows  = $this->db->select($query, ['email' => 'kw@example.com']);
        assert_true(count($rows) === 1, 'Expected one inserted row.');
        assert_true($rows[0]['name'] !== '@currentDate', 'Keyword @currentDate should have been replaced.');
        assert_equals($expectedDate, $rows[0]['name'], 'Keyword @currentDate should equal today\'s date.');
    }

    public function testKeywordCheckDisabledStoresLiteralValue()
    {
        $this->resetTable();
        $this->db->enableKeywordCkeck(false);
        try {
            $this->db->insert(self::TABLE, ['name' => '@currentDate', 'email' => 'kw2@example.com']);
            $this->db->enableKeywordCkeck(true); // restore
        } catch (Exception $e) {
            $this->db->enableKeywordCkeck(true); // restore on failure
            throw $e;
        }
        $query = Query::select()->from(self::TABLE)->where('email = :email');
        $rows  = $this->db->select($query, ['email' => 'kw2@example.com']);
        assert_true(count($rows) === 1, 'Expected one inserted row.');
        assert_equals('@currentDate', $rows[0]['name'], 'Literal string @currentDate should be stored when keyword checking is disabled.');
    }

    public function testEnableKeywordCheckIsChainable()
    {
        $result = $this->db->enableKeywordCkeck(true);
        assert_true($result === $this->db, 'enableKeywordCkeck() must return $this for chaining.');
    }

    // =========================================================================
    // Tests - validation
    // =========================================================================

    public function testValidationEnabledByDefault()
    {
        assert_true($this->db->isValidationEnabled());
    }

    public function testValidationIsChainable()
    {
        $result = $this->db->validation(false);
        assert_true($result === $this->db, 'validation() must return $this for chaining.');
        $this->db->validation(true);
    }

    public function testValidationWithNonBooleanThrows()
    {
        assert_throws('InvalidArgumentException', function () {
            $this->db->validation('no');
        });
    }

    // =========================================================================
    // Tests - getLastInsertId
    // =========================================================================

    public function testGetLastInsertIdMatchesInsertReturnValue()
    {
        $this->resetTable();
        $insertedId = $this->db->insert(self::TABLE, ['name' => 'Alice', 'email' => 'alice@example.com']);
        $lastId     = $this->db->getLastInsertId();
        assert_equals($insertedId, $lastId);
    }

    // =========================================================================
    // Tests - NULL value handling
    // Requires a table with a nullable column. A dedicated table is created and
    // dropped within each test to keep them fully independent.
    // =========================================================================

    /** Returns the CREATE TABLE SQL for the nullable test table. */
    private function getNullableCreateTableSql()
    {
        switch (DB_TEST_DRIVER) {
            case 'mysql':
                return 'CREATE TABLE IF NOT EXISTS ' . self::NULLABLE_TABLE . ' ('
                    . 'id INT AUTO_INCREMENT PRIMARY KEY, '
                    . 'name VARCHAR(255) NOT NULL, '
                    . 'notes VARCHAR(255) NULL)';

            case 'postgres':
                return 'CREATE TABLE IF NOT EXISTS ' . self::NULLABLE_TABLE . ' ('
                    . 'id SERIAL PRIMARY KEY, '
                    . 'name VARCHAR(255) NOT NULL, '
                    . 'notes VARCHAR(255) NULL)';

            case 'sql':
                return "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='"
                    . self::NULLABLE_TABLE . "' AND xtype='U') "
                    . 'CREATE TABLE ' . self::NULLABLE_TABLE . ' ('
                    . 'id INT IDENTITY(1,1) PRIMARY KEY, '
                    . 'name NVARCHAR(255) NOT NULL, '
                    . 'notes NVARCHAR(255) NULL)';

            default: // sqlite
                return 'CREATE TABLE IF NOT EXISTS ' . self::NULLABLE_TABLE . ' ('
                    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
                    . 'name TEXT NOT NULL, '
                    . 'notes TEXT NULL)';
        }
    }

    /** Returns the DROP TABLE SQL for the nullable test table. */
    private function getNullableDropTableSql()
    {
        if (DB_TEST_DRIVER === 'sql') {
            return "IF EXISTS (SELECT * FROM sysobjects WHERE name='" . self::NULLABLE_TABLE . "' AND xtype='U') "
                . 'DROP TABLE ' . self::NULLABLE_TABLE;
        }
        return 'DROP TABLE IF EXISTS ' . self::NULLABLE_TABLE;
    }

    /** Drops and recreates the nullable test table. */
    private function resetNullableTable()
    {
        $this->db->runPlainQuery($this->getNullableDropTableSql());
        $this->db->runPlainQuery($this->getNullableCreateTableSql());
    }

    public function testInsertNullValueStoresNull()
    {
        $this->resetNullableTable();
        try {
            $this->db->insert(self::NULLABLE_TABLE, ['name' => 'Alice', 'notes' => null]);
            $rows = $this->db->select('SELECT * FROM ' . self::NULLABLE_TABLE);
            assert_equals(1, count($rows));
            assert_equals('Alice', $rows[0]['name']);
            assert_true($rows[0]['notes'] === null, 'NULL value must be stored as SQL NULL, not an empty string.');
        } finally {
            $this->db->runPlainQuery($this->getNullableDropTableSql());
        }
    }

    public function testInsertManyWithNullValueStoresNull()
    {
        $this->resetNullableTable();
        try {
            $this->db->insert(self::NULLABLE_TABLE, [
                ['name' => 'Alice', 'notes' => 'has notes'],
                ['name' => 'Bob',   'notes' => null],
            ]);
            $rows = $this->db->select('SELECT * FROM ' . self::NULLABLE_TABLE . ' ORDER BY name');
            assert_equals(2, count($rows));
            assert_equals('has notes', $rows[0]['notes']);
            assert_true($rows[1]['notes'] === null, 'NULL value in multi-row insert must be stored as SQL NULL.');
        } finally {
            $this->db->runPlainQuery($this->getNullableDropTableSql());
        }
    }

    public function testUpdateToNullValueStoresNull()
    {
        $this->resetNullableTable();
        try {
            $this->db->insert(self::NULLABLE_TABLE, ['name' => 'Alice', 'notes' => 'original']);
            $rows = $this->db->select(
                'SELECT id FROM ' . self::NULLABLE_TABLE . ' WHERE name = :name',
                ['name' => 'Alice']
            );
            assert_equals(1, count($rows), 'Inserted row must be retrievable by name.');
            $id   = (int)$rows[0]['id'];

            $this->db->update(self::NULLABLE_TABLE, ['notes' => null], 'id = :id', ['id' => $id]);

            $updated = $this->db->select(
                'SELECT notes FROM ' . self::NULLABLE_TABLE . ' WHERE id = :id',
                ['id' => $id]
            );
            assert_equals(1, count($updated));
            assert_true($updated[0]['notes'] === null, 'Updating a column to null must store SQL NULL.');
        } finally {
            $this->db->runPlainQuery($this->getNullableDropTableSql());
        }
    }

    public function testMixedPositionalAndNamedParamsThrows()
    {
        $caughtException = false;
        try {
            // A params array with both integer (positional) and string (named) keys
            // must be rejected with InvalidArgumentException before prepare() is called.
            $this->db->runPlainQuery('SELECT 1', [0 => 'val', 'name' => 'Alice']);
        } catch (InvalidArgumentException $e) {
            $caughtException = true;
        }
        assert_true($caughtException, 'Mixed positional and named params must throw InvalidArgumentException.');
    }

    // =========================================================================
    // Tests - query factory / dialect
    // =========================================================================

    public function testCreateQueryReturnsQueryInstance()
    {
        $query = $this->db->createQuery();
        assert_true($query instanceof Query, 'createQuery() must return a Query instance.');
    }

    public function testGetDialectReturnsSqlDialectInstance()
    {
        assert_true($this->db->getDialect() instanceof SqlDialect, 'getDialect() must return a SqlDialect instance.');
    }

    public function testQuoteDialectDependsOnDriver()
    {
        $quoted = $this->db->quote('order');
        if (DB_TEST_DRIVER === 'mysql') {
            assert_equals('`order`', $quoted);
        } else {
            assert_equals('"order"', $quoted);
        }
    }

    // =========================================================================
    // Tests - getSupportedJoinTypes
    // =========================================================================

    public function testGetSupportedJoinTypesReturnsArray()
    {
        $types = $this->db->getSupportedJoinTypes();
        assert_true(is_array($types), 'getSupportedJoinTypes() must return an array.');
    }

    public function testGetSupportedJoinTypesContainsInnerJoin()
    {
        $types = $this->db->getSupportedJoinTypes();
        assert_true(isset($types['INNER']), 'INNER JOIN must be listed as a supported join type.');
        assert_equals('INNER JOIN', $types['INNER']);
    }

    public function testGetSupportedJoinTypesContainsLeftJoin()
    {
        $types = $this->db->getSupportedJoinTypes();
        assert_true(isset($types['LEFT']), 'LEFT JOIN must be listed as a supported join type.');
        assert_equals('LEFT JOIN', $types['LEFT']);
    }

    public function testSqliteDriverSupportsRightJoin()
    {
        if (DB_TEST_DRIVER !== 'sqlite') {
            assert_true(true);
            return;
        }
        $types = $this->db->getSupportedJoinTypes();
        assert_true(isset($types['RIGHT']), 'SQLite 3.39+ must list RIGHT JOIN as supported.');
        assert_equals('RIGHT JOIN', $types['RIGHT']);
    }

    public function testSqliteDriverSupportsFullJoin()
    {
        if (DB_TEST_DRIVER !== 'sqlite') {
            assert_true(true);
            return;
        }
        $types = $this->db->getSupportedJoinTypes();
        assert_true(isset($types['FULL']), 'SQLite 3.39+ must list FULL JOIN as supported.');
        assert_equals('FULL JOIN', $types['FULL']);
    }

    public function testNonSqliteDriverSupportsRightJoin()
    {
        if (DB_TEST_DRIVER === 'sqlite') {
            assert_true(true);
            return;
        }
        $types = $this->db->getSupportedJoinTypes();
        assert_true(isset($types['RIGHT']), 'Driver must list RIGHT JOIN as supported.');
        assert_equals('RIGHT JOIN', $types['RIGHT']);
    }

    public function testNonSqliteDriverSupportsFullJoin()
    {
        if (DB_TEST_DRIVER === 'sqlite') {
            assert_true(true);
            return;
        }
        $types = $this->db->getSupportedJoinTypes();
        if (DB_TEST_DRIVER === 'mysql') {
            assert_true(!isset($types['FULL']), 'MySQL must not list FULL JOIN as supported.');
            return;
        }
        // postgres and sql (SQL Server) support FULL JOIN
        assert_true(isset($types['FULL']), 'Driver must list FULL JOIN as supported.');
        assert_equals('FULL JOIN', $types['FULL']);
    }
}
