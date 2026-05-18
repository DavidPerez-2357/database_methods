<?php

/**
 * DatabaseMethods.php
 *
 * Entry point for the DatabaseMethods library.
 * Simply require this file to load all classes:
 *
 *   require_once 'DatabaseMethods.php';
 *
 * Available classes:
 *   - SqlValidator         : Static utility for SQL identifier and expression validation
 *   - Query                : SQL query builder (SELECT / INSERT / UPDATE / DELETE)
 *   - Database             : PDO-based base class with querying, CRUD, transactions
 *   - PdoParameterBuilder  : Static utility for SQL fragments and PDO named parameters
 *   - Mysql                : MySQL / MariaDB driver
 *   - Postgres             : PostgreSQL driver
 *   - Sqlite               : SQLite driver
 *   - Sql                  : Microsoft SQL Server driver
 *
 * @author DavidPerez-2357
 * @link https://github.com/DavidPerez-2357/DatabaseMethods
 */

$__dm_base = __DIR__ . '/src/';

require_once $__dm_base . 'SqlValidator.php';
require_once $__dm_base . 'SqlDialect.php';
require_once $__dm_base . 'Query.php';
require_once $__dm_base . 'QueryRunner.php';
require_once $__dm_base . 'PdoParameterBuilder.php';
require_once $__dm_base . 'Database.php';
require_once $__dm_base . 'drivers/Mysql.php';
require_once $__dm_base . 'drivers/Postgres.php';
require_once $__dm_base . 'drivers/Sqlite.php';
require_once $__dm_base . 'drivers/Sql.php';

unset($__dm_base);
