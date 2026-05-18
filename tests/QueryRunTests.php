<?php

/**
 * tests/QueryRunTests.php
 *
 * Focused integration tests for Query::run(), kept intentionally minimal.
 */
class SqliteValidationSpy extends Sqlite
{
    /** @var array */
    public $validationTransitions = array();

    public function validation($enabled = true)
    {
        $this->validationTransitions[] = $enabled;
        return parent::validation($enabled);
    }
}

class QueryRunTests
{
    /** @var Database */
    private $db;

    /** @var string */
    private $dbFile;

    const TABLE = 'test_query_run';

    public function __construct()
    {
        $this->dbFile = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'dbmethods_query_run_'
            . getmypid()
            . '_'
            . uniqid('', true)
            . '.sqlite';

        $this->db = new SqliteValidationSpy(array('DB' => $this->dbFile));
        $this->db->runPlainQuery(
            'CREATE TABLE ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT
            )'
        );
    }

    public function teardown()
    {
        try {
            $this->db->runPlainQuery('DROP TABLE IF EXISTS ' . self::TABLE);
        } catch (Exception $e) {
            // Best effort cleanup.
        }
        $this->db = null;
        if (file_exists($this->dbFile)) {
            if (!unlink($this->dbFile)) {
                throw new RuntimeException(
                    'Teardown failed: could not remove temporary database file: ' . $this->dbFile
                );
            }
        }
    }

    public function testRunSelectWithSetDatabaseReturnsRows()
    {
        $this->db->insert(self::TABLE, array('name' => 'Alice', 'email' => 'alice@example.com'));

        $query = Query::select(array('name'))
            ->from(self::TABLE)
            ->where('name = :name');
        $query->setDatabase($this->db);

        $rows = $query->run(array('name' => 'Alice'));

        assert_equals(1, count($rows));
        assert_equals('Alice', $rows[0]['name']);
    }

    public function testRunWriteOperationsAndGuards()
    {
        $id = $this->db->createQuery()
            ->insert(self::TABLE, array('name', 'email'))
            ->run(array('name' => 'Bob', 'email' => 'bob@example.com'));
        assert_true(is_int($id));
        assert_true($id > 0);

        $updated = $this->db->createQuery()
            ->update(self::TABLE, array('name'))
            ->where('id = :id')
            ->run(array('name' => 'Bobby', 'id' => $id));
        assert_equals(1, $updated);

        $deleted = $this->db->createQuery()
            ->delete(self::TABLE)
            ->where('id = :id')
            ->run(array('id' => $id));
        assert_equals(1, $deleted);

        $batchInsertResult = $this->db->createQuery()
            ->insert(self::TABLE, array('name', 'email'))
            ->run(array(
                array('name' => 'Alice 1', 'email' => 'alice1@example.com'),
                array('name' => 'Alice 2', 'email' => 'alice2@example.com'),
            ));
        assert_equals(0, $batchInsertResult);
        $batchRows = $this->db->plainSelect(
            'SELECT id FROM ' . self::TABLE . ' WHERE name IN (:a, :b)',
            array('a' => 'Alice 1', 'b' => 'Alice 2')
        );
        assert_equals(2, count($batchRows));

        $quotedBatchInsertResult = $this->db->createQuery()
            ->insert(self::TABLE, array('"name"', 'email'))
            ->run(array(
                // Intentionally mix quoted/unquoted keys across rows; runner should normalize them.
                array('"name"' => 'Quoted 1', 'email' => 'quoted1@example.com'),
                array('name' => 'Quoted 2', 'email' => 'quoted2@example.com'),
            ));
        assert_equals(0, $quotedBatchInsertResult);
        $quotedBatchRows = $this->db->plainSelect(
            'SELECT id FROM ' . self::TABLE . ' WHERE name IN (:a, :b)',
            array('a' => 'Quoted 1', 'b' => 'Quoted 2')
        );
        assert_equals(2, count($quotedBatchRows));

        $fastInsertId = $this->db->createQuery()
            ->insert(self::TABLE, array('"name"', 'email'))
            ->validation(false)
            ->run(array('"name"' => 'Fast Quoted', 'email' => 'fast@example.com'));
        assert_true(is_int($fastInsertId));
        assert_true($fastInsertId > 0);
        assert_true($this->db->isValidationEnabled());
        $lastTransitions = array_slice($this->db->validationTransitions, -2);
        assert_equals(array(false, true), $lastTransitions);
        $fastRows = $this->db->plainSelect(
            'SELECT id FROM ' . self::TABLE . ' WHERE name = :name',
            array('name' => 'Fast Quoted')
        );
        assert_equals(1, count($fastRows));

        $toggleBackToValidatedResult = $this->db->createQuery()
            ->insert(self::TABLE, array('"name"', 'email'))
            ->validation(false)
            ->validation(true)
            ->run(array(
                array('"name"' => 'Toggle OnOff 1', 'email' => 'toggle1@example.com'),
                array('name' => 'Toggle OnOff 2', 'email' => 'toggle2@example.com'),
            ));
        assert_equals(0, $toggleBackToValidatedResult);
        $toggleRows = $this->db->plainSelect(
            'SELECT id FROM ' . self::TABLE . ' WHERE name IN (:a, :b)',
            array('a' => 'Toggle OnOff 1', 'b' => 'Toggle OnOff 2')
        );
        assert_equals(2, count($toggleRows));

        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()->insert(self::TABLE, array('name'))->run();
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()->insert(self::TABLE, array('name'))->run(array());
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('"name"', 'email'))
                    ->validation(false)
                    ->run(array(
                        array('"name"' => 'No Normalize 1', 'email' => 'no_norm1@example.com'),
                        array('name' => 'No Normalize 2', 'email' => 'no_norm2@example.com'),
                    ));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('name', 'email'))
                    ->run(array('name' => 'Missing Email'));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('name', 'email'))
                    ->run(array(
                        'name' => 'Extra Field',
                        'email' => 'extra@example.com',
                        'extra' => 'x',
                    ));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('"name"', 'email'))
                    ->run(array(
                        array('"name"' => 'Dup 1A', 'name' => 'Dup 1B', 'email' => 'dup1@example.com'),
                        array('name' => 'Dup 2', 'email' => 'dup2@example.com'),
                    ));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('`name`', 'email'))
                    ->run(array(
                        array('`name`' => 'Dup Tick 1A', 'name' => 'Dup Tick 1B', 'email' => 'dup_tick1@example.com'),
                        array('name' => 'Dup Tick 2', 'email' => 'dup_tick2@example.com'),
                    ));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('name', 'email'))
                    ->run(array(
                        array('name' => 'Row 1', 'email' => 'row1@example.com'),
                        array('name' => 'Row 2', 'email' => 'row2@example.com', 'extra' => 'x'),
                    ));
            }
        );
        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->insert(self::TABLE, array('name', 'email'))
                    ->run(array(
                        array('name' => 'Row 1', 'email' => 'row1@example.com'),
                        array('name' => 'Row 2'),
                    ));
            }
        );

        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->update(self::TABLE)
                    ->where('id = :id')
                    ->run(array('id' => 1));
            }
        );

        assert_throws(
            'InvalidArgumentException',
            function () {
                $this->db->createQuery()
                    ->update(self::TABLE, array('name'))
                    ->where('id = :id')
                    ->run(array('id' => 1));
            }
        );

        assert_throws(
            'RuntimeException',
            function () {
                Query::select(array('id'))->from(self::TABLE)->run();
            }
        );

        $emptyQuery = $this->db->createQuery();
        assert_throws(
            'InvalidArgumentException',
            function () use ($emptyQuery) {
                $emptyQuery->run();
            }
        );

        try {
            $this->db->createQuery()
                ->insert(self::TABLE, array('name'))
                ->validation('yes');
            throw new TestAssertionException(
                'Expected InvalidArgumentException when validation() is called with a non-boolean argument.'
            );
        } catch (InvalidArgumentException $e) {
            assert_contains('expects a boolean argument', $e->getMessage());
        }
    }
}
