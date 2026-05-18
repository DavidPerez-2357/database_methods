<?php

/**
 * QueryRunner.php
 *
 * Executes Query instances against a Database connection.
 *
 * @author DavidPerez-2357
 * @link https://github.com/DavidPerez-2357/DatabaseMethods
 */
class QueryRunner
{
    /**
     * @var Database
     */
    private $database;

    /**
     * @param Database $database
     */
    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * Executes a SELECT query and returns all result rows.
     *
     * @param Query $query The linked query to execute.
     * @param array $data Named or positional parameter bindings.
     * @return array|string
     */
    public function runSelect(Query $query, array $data = array())
    {
        return $this->database->plainSelect((string) $query, $data);
    }

    /**
     * Executes an INSERT query and returns the last insert ID (single row)
     * or 0 for multi-row batches.
     *
     * @param Query $query The linked INSERT query.
     * @param array $data Row data (single associative array or list of associative arrays).
     * @return int Last insert ID for single rows; 0 for multi-row batches.
     * @throws InvalidArgumentException If the query has no table set.
     */
    public function runInsert(Query $query, array $data = array())
    {
        if (empty($query->getTable())) {
            throw new InvalidArgumentException('INSERT query requires a table.');
        }
        list($rows, $fields, $isMultiRow) = $this->prepareInsertPayload($query, $data);
        $query->fields($fields)->valuesCount(count($rows));
        $this->database->runPlainQuery((string) $query, PdoParameterBuilder::buildInsertParams($rows));

        return $isMultiRow ? 0 : $this->database->getLastInsertId();
    }

    /**
     * Executes an UPDATE query and returns the number of affected rows.
     *
     * @param Query $query The linked UPDATE query.
     * @param array $data Combined SET + WHERE bindings.
     * @return int Number of affected rows.
     */
    public function runUpdate(Query $query, array $data = array())
    {
        $validationEnabled = $query->isValidationEnabled();
        $fields = $query->getFields();
        if ($validationEnabled && empty($fields)) {
            throw new InvalidArgumentException('UPDATE query requires at least one field.');
        }

        // Derive the key set used to split $data into SET/WHERE.
        $fieldKeys = $validationEnabled ? $this->normalizeIdentifiers($fields) : $fields;
        $fieldLookup = array_flip($fieldKeys);

        $fieldsToUpdate = array_intersect_key($data, $fieldLookup);
        if ($validationEnabled && empty($fieldsToUpdate)) {
            throw new InvalidArgumentException(
                'UPDATE operation: no data bindings match the specified fields.'
            );
        }
        $whereData = array_diff_key($data, $fieldLookup);

        $placeholders = PdoParameterBuilder::buildNamedParams($fieldsToUpdate);
        $placeholders = array_merge(
            $placeholders,
            PdoParameterBuilder::normalizeNamedWhereBindings($whereData, $placeholders)
        );

        return (int) $this->database->runPlainQuery((string) $query, $placeholders);
    }

    /**
     * Executes a DELETE query and returns the number of affected rows.
     *
     * @param Query $query The linked DELETE query.
     * @param array $data WHERE clause bindings (named or positional).
     * @return int Number of affected rows.
     */
    public function runDelete(Query $query, array $data = array())
    {
        return (int) $this->database->runPlainQuery((string) $query, $data);
    }

    /**
     * @param array $identifiers
     * @return array
     */
    private function normalizeIdentifiers(array $identifiers)
    {
        $normalized = array();
        foreach ($identifiers as $identifier) {
            $normalized[] = $this->normalizeIdentifier($identifier);
        }
        return $normalized;
    }

    /**
     * @param mixed $identifier
     * @return string
     */
    private function normalizeIdentifier($identifier)
    {
        if (!is_string($identifier)) {
            throw new InvalidArgumentException('Field identifiers must be strings.');
        }
        SqlValidator::assertColumnIdentifier($identifier, 'INSERT/UPDATE field');

        $first = substr($identifier, 0, 1);
        if ($first === '"' || $first === '`') {
            return substr($identifier, 1, -1);
        }

        return $identifier;
    }

    /**
     * @param array      $row
     * @param array      $expectedKeys
     * @param int|null   $rowIndex
     * @throws InvalidArgumentException
     */
    private function assertInsertRowMatchesFields(array $row, array $expectedKeys, $rowIndex = null)
    {
        $actualKeys = $this->normalizeIdentifiers(array_keys($row));

        $missing = array_diff($expectedKeys, $actualKeys);
        $extra = array_diff($actualKeys, $expectedKeys);
        if (empty($missing) && empty($extra)) {
            return;
        }

        $prefix = 'INSERT data keys do not match query fields';
        if ($rowIndex !== null) {
            $prefix .= ' for row ' . ($rowIndex + 1);
        }
        $message = $prefix . '.';

        if (!empty($missing)) {
            $message .= ' Missing: ' . implode(', ', $missing) . '.';
        }
        if (!empty($extra)) {
            $message .= ' Extra: ' . implode(', ', $extra) . '.';
        }

        throw new InvalidArgumentException($message);
    }

    /**
     * @param Query $query
     * @param array $data
     * @return array
     */
    private function prepareInsertPayload(Query $query, array $data)
    {
        $validationEnabled = $query->isValidationEnabled();
        $isMultiRow = $this->isSequentialListOfArrays($data);
        if (!$isMultiRow && $validationEnabled && empty($data)) {
            throw new InvalidArgumentException('INSERT operation requires non-empty row data.');
        }

        $rows = $isMultiRow ? array_values($data) : array($data);
        $fields = $this->resolveInsertFields($query->getFields(), $rows);

        if ($validationEnabled && !empty($query->getFields())) {
            if ($isMultiRow) {
                $rows = $this->normalizeInsertRowsToFields($rows, $fields);
            } else {
                $rows[0] = $this->normalizeInsertRowAgainstFields($rows[0], $fields);
            }
        }

        return array($rows, $fields, $isMultiRow);
    }

    /**
     * @param array $fields
     * @param array $rows
     * @return array
     */
    private function resolveInsertFields(array $fields, array $rows)
    {
        if (!empty($fields)) {
            return $fields;
        }

        return array_keys($rows[0]);
    }

    /**
     * Re-keys each row to the raw field names declared in the query so
     * validation and parameter binding use a consistent key set.
     *
     * @param array $rows
     * @param array $fields
     * @return array
     */
    private function normalizeInsertRowsToFields(array $rows, array $fields)
    {
        $normalizedFields = $this->normalizeIdentifiers($fields);
        $normalizedRows = array();

        foreach ($rows as $rowIndex => $row) {
            $this->assertInsertRowMatchesFields($row, $normalizedFields, $rowIndex);
            $normalizedRows[] = $this->normalizeInsertRowToFields(
                $row,
                $fields,
                $normalizedFields,
                $rowIndex
            );
        }

        return $normalizedRows;
    }

    /**
     * @param array $row
     * @param array $fields
     * @return array
     */
    private function normalizeInsertRowAgainstFields(array $row, array $fields)
    {
        $normalizedFields = $this->normalizeIdentifiers($fields);
        $this->assertInsertRowMatchesFields($row, $normalizedFields);

        return $this->normalizeInsertRowToFields($row, $fields, $normalizedFields, 0);
    }

    /**
     * @param array $row
     * @param array $fields
     * @param array $normalizedFields
     * @param int   $rowIndex
     * @return array
     */
    private function normalizeInsertRowToFields(array $row, array $fields, array $normalizedFields, $rowIndex)
    {
        $normalizedToRaw = array();
        foreach ($row as $rawKey => $value) {
            $normalizedKey = $this->normalizeIdentifier($rawKey);

            if (isset($normalizedToRaw[$normalizedKey])) {
                throw new InvalidArgumentException(
                    'INSERT row ' . ($rowIndex + 1)
                    . " contains duplicate normalized field key '{$normalizedKey}'."
                );
            }
            $normalizedToRaw[$normalizedKey] = $rawKey;
        }

        $normalizedRow = array();
        foreach ($normalizedFields as $index => $normalizedField) {
            $normalizedRow[$fields[$index]] = $row[$normalizedToRaw[$normalizedField]];
        }

        return $normalizedRow;
    }

    /**
     * @param array $data
     * @return bool
     */
    private function isSequentialListOfArrays(array $data)
    {
        if (empty($data) || array_keys($data) !== range(0, count($data) - 1)) {
            return false;
        }

        foreach ($data as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }
}
