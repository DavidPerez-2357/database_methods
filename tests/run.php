<?php

/**
 * tests/run.php
 *
 * Minimal test runner - no external libraries required.
 * Runs SqlValidatorTests (unit tests), SqlDialectTests (unit tests), QueryTests (unit tests),
 * PdoParameterBuilderTests (unit tests), DatabaseTest (integration tests),
 * and QueryRunTests (integration tests).
 *
 * Usage:
 *   php tests/run.php
 *
 * Exit code 0 when all tests pass, 1 when any test fails.
 *
 * @author DavidPerez-2357
 * @link   https://github.com/DavidPerez-2357/DatabaseMethods
 */

// Load all library classes (Query, Database, Mysql, Postgres, Sqlite, Sql).
require_once __DIR__ . '/../DatabaseMethods.php';
require_once __DIR__ . '/SqlValidatorTests.php';
require_once __DIR__ . '/SqlDialectTests.php';
require_once __DIR__ . '/QueryTests.php';
require_once __DIR__ . '/PdoParameterBuilderTests.php';
require_once __DIR__ . '/DatabaseTest.php';
require_once __DIR__ . '/QueryRunTests.php';

// ---------------------------------------------------------------------------
// Custom exception used by the assertion helpers below
// ---------------------------------------------------------------------------

class TestAssertionException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Assertion helpers
// ---------------------------------------------------------------------------

/**
 * Asserts strict equality between $expected and $actual.
 *
 * @throws TestAssertionException on failure.
 */
function assert_equals($expected, $actual, $msg = '')
{
    if ($expected !== $actual) {
        throw new TestAssertionException(
            $msg ?: sprintf(
                "Expected:\n  %s\nActual:\n  %s",
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

/**
 * Asserts that $haystack contains the substring $needle.
 *
 * @throws TestAssertionException on failure.
 */
function assert_contains($needle, $haystack, $msg = '')
{
    if (strpos($haystack, $needle) === false) {
        throw new TestAssertionException(
            $msg ?: sprintf(
                "Expected string to contain:\n  %s\nbut got:\n  %s",
                var_export($needle, true),
                var_export($haystack, true)
            )
        );
    }
}

/**
 * Asserts that $haystack does NOT contain the substring $needle.
 *
 * @throws TestAssertionException on failure.
 */
function assert_not_contains($needle, $haystack, $msg = '')
{
    if (strpos($haystack, $needle) !== false) {
        throw new TestAssertionException(
            $msg ?: sprintf(
                "Expected string NOT to contain:\n  %s\nbut it does:\n  %s",
                var_export($needle, true),
                var_export($haystack, true)
            )
        );
    }
}

/**
 * Asserts that $value is strictly true.
 *
 * @throws TestAssertionException on failure.
 */
function assert_true($value, $msg = '')
{
    if ($value !== true) {
        throw new TestAssertionException(
            $msg ?: sprintf("Expected true but got: %s", var_export($value, true))
        );
    }
}

/**
 * Asserts that calling $fn throws an exception of exactly class $expectedClass
 * (or a subclass of it).
 *
 * @param string   $expectedClass Fully-qualified exception class name.
 * @param callable $fn
 * @param string   $msg           Optional failure message.
 *
 * @throws TestAssertionException on failure.
 */
function assert_throws($expectedClass, $fn, $msg = '')
{
    try {
        call_user_func($fn);
    } catch (Exception $e) {
        if (!($e instanceof $expectedClass)) {
            throw new TestAssertionException(
                $msg ?: sprintf(
                    "Expected %s but got %s: %s",
                    $expectedClass,
                    get_class($e),
                    $e->getMessage()
                )
            );
        }
        return; // Expected exception was thrown - test passes
    }

    throw new TestAssertionException(
        $msg ?: "Expected $expectedClass to be thrown but no exception was thrown"
    );
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

/**
 * Discovers and runs all test*() methods on $suite, prints per-test results,
 * prints a summary line, and returns the number of failed tests.
 *
 * @param  object        $suite    The test suite instance.
 * @param  string        $label    Human-readable label shown in the summary.
 * @param  callable|null $teardown Optional callback invoked after all tests run.
 * @return int Number of failed tests.
 */
function run_suite($suite, $label, $teardown = null)
{
    $methods = get_class_methods($suite);
    sort($methods);

    $passed  = 0;
    $failed  = 0;
    $results = [];

    foreach ($methods as $method) {
        if (strncmp($method, 'test', 4) !== 0) {
            continue;
        }
        try {
            $suite->$method();
            $results[] = '[PASS] ' . $method;
            $passed++;
        } catch (TestAssertionException $e) {
            $results[] = '[FAIL] ' . $method . "\n"
                . '       ' . str_replace("\n", "\n       ", $e->getMessage());
            $failed++;
        } catch (Exception $e) {
            $results[] = '[FAIL] ' . $method . "\n"
                . '       Unexpected ' . get_class($e) . ': ' . $e->getMessage();
            $failed++;
        }
    }

    if ($teardown !== null) {
        try {
            call_user_func($teardown);
        } catch (Exception $e) {
            $results[] = '[FAIL] teardown' . "\n"
                . '       Unexpected ' . get_class($e) . ': ' . $e->getMessage();
            $failed++;
        }
    }

    foreach ($results as $line) {
        echo $line . "\n";
    }

    $total = $passed + $failed;
    echo "\n" . str_repeat('-', 55) . "\n";
    printf("%s: %d/%d tests passed", $label, $passed, $total);
    if ($failed > 0) {
        printf(", %d FAILED", $failed);
    }
    echo "\n" . str_repeat('-', 55) . "\n\n";

    return $failed;
}

// ---------------------------------------------------------------------------
// Run suites
// ---------------------------------------------------------------------------

$totalFailed = 0;

// Unit tests - SqlValidator utility
$totalFailed += run_suite(new SqlValidatorTests(), 'SqlValidator');

// Unit tests - SqlDialect implementations
$totalFailed += run_suite(new SqlDialectTests(), 'SqlDialect');

// Unit tests - Query class
$totalFailed += run_suite(new QueryTests(), 'Query');

// Unit tests - PdoParameterBuilder utility
$totalFailed += run_suite(new PdoParameterBuilderTests(), 'PdoParameterBuilder');

// Integration tests - Database class (SQLite by default; configure in DatabaseTest.php)
$dbSuite = null;
try {
    $dbSuite = new DatabaseTest();
} catch (Exception $e) {
    echo "[ERROR] DatabaseTest setup failed: " . $e->getMessage() . "\n";
    echo "        Integration tests were skipped and counted as failed.\n\n";
    $totalFailed++;
}

if ($dbSuite !== null) {
    $totalFailed += run_suite(
        $dbSuite,
        'Database',
        function () use ($dbSuite) {
            $dbSuite->teardown();
        }
    );
}

// Focused integration tests - Query::run behavior
$queryRunSuite = null;
try {
    $queryRunSuite = new QueryRunTests();
} catch (Exception $e) {
    echo "[ERROR] QueryRunTests setup failed: " . $e->getMessage() . "\n";
    echo "        Integration tests were skipped and counted as failed.\n\n";
    $totalFailed++;
}

if ($queryRunSuite !== null) {
    $totalFailed += run_suite(
        $queryRunSuite,
        'QueryRun',
        function () use ($queryRunSuite) {
            $queryRunSuite->teardown();
        }
    );
}

exit($totalFailed > 0 ? 1 : 0);
