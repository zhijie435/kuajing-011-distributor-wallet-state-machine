<?php

class MockStatement
{
    private $rows;
    private $cursor = 0;

    public function __construct($rows = [])
    {
        $this->rows = $rows;
    }

    public function execute($params = [])
    {
        return true;
    }

    public function fetch($fetchStyle = PDO::FETCH_ASSOC)
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        $row = $this->rows[$this->cursor];
        $this->cursor++;
        return $row;
    }

    public function fetchAll($fetchStyle = PDO::FETCH_ASSOC)
    {
        return $this->rows;
    }

    public function rowCount()
    {
        return count($this->rows);
    }

    public function columnCount()
    {
        if (empty($this->rows)) {
            return 0;
        }
        return count($this->rows[0]);
    }
}

class MockDatabase
{
    private static $instance = null;
    private $tables = [];
    private $autoIncrements = [];
    private $inTransaction = false;
    private $transactionSavepoint = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance()
    {
        self::$instance = new self();
    }

    public function getConnection()
    {
        return $this;
    }

    public function createTable($tableName, $columns)
    {
        if (!isset($this->tables[$tableName])) {
            $this->tables[$tableName] = [];
            $this->autoIncrements[$tableName] = 1;
        }
    }

    public function query($sql, $params = [])
    {
        $sql = trim($sql);
        
        if (stripos($sql, 'SELECT') === 0) {
            return $this->executeSelect($sql, $params);
        } elseif (stripos($sql, 'INSERT') === 0) {
            return $this->executeInsert($sql, $params);
        } elseif (stripos($sql, 'UPDATE') === 0) {
            return $this->executeUpdate($sql, $params);
        } elseif (stripos($sql, 'DELETE') === 0) {
            return $this->executeDelete($sql, $params);
        }
        
        return new MockStatement([]);
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        $rows = $stmt->fetchAll();
        return $rows[0] ?? false;
    }

    public function insert($table, $data)
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
            $this->autoIncrements[$table] = 1;
        }

        $data['id'] = $this->autoIncrements[$table]++;
        $this->tables[$table][] = $data;
        
        return $data['id'];
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        return 0;
    }

    public function beginTransaction()
    {
        if ($this->inTransaction) {
            return false;
        }
        $this->inTransaction = true;
        $this->transactionSavepoint = $this->deepCopy($this->tables);
        return true;
    }

    public function commit()
    {
        if (!$this->inTransaction) {
            return false;
        }
        $this->inTransaction = false;
        $this->transactionSavepoint = null;
        return true;
    }

    public function rollBack()
    {
        if (!$this->inTransaction) {
            return false;
        }
        $this->tables = $this->transactionSavepoint;
        $this->inTransaction = false;
        $this->transactionSavepoint = null;
        return true;
    }

    public function lastInsertId()
    {
        return 0;
    }

    private function deepCopy($data)
    {
        return unserialize(serialize($data));
    }

    private function executeSelect($sql, $params)
    {
        $rows = $this->parseAndExecuteSelect($sql, $params);
        return new MockStatement($rows);
    }

    private function parseAndExecuteSelect($sql, $params)
    {
        $fromMatches = [];
        if (!preg_match('/FROM\s+(\w+)/i', $sql, $fromMatches)) {
            return [];
        }
        $table = $fromMatches[1];
        
        if (!isset($this->tables[$table])) {
            return [];
        }
        
        $rows = $this->tables[$table];
        
        $joinMatches = [];
        if (preg_match_all('/(INNER|LEFT|RIGHT)?\s*JOIN\s+(\w+)\s+ON\s+([^\s]+(?:\s*=\s*[^\s]+)*)/i', $sql, $joinMatches, PREG_SET_ORDER)) {
            foreach ($joinMatches as $joinMatch) {
                $joinTable = $joinMatch[2];
                $onCondition = $joinMatch[3];
                $joinType = strtoupper($joinMatch[1] ?? 'INNER');
                
                if (isset($this->tables[$joinTable])) {
                    $rows = $this->joinTables($rows, $this->tables[$joinTable], $onCondition, $joinType);
                }
            }
        }
        
        $whereMatches = [];
        if (preg_match('/WHERE\s+(.+?)(?:ORDER|LIMIT|$)/is', $sql, $whereMatches)) {
            $whereClause = trim($whereMatches[1]);
            $rows = $this->applyWhere($rows, $whereClause, $params);
        }
        
        $orderMatches = [];
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:LIMIT|$)/is', $sql, $orderMatches)) {
            $orderBy = trim($orderMatches[1]);
            $rows = $this->applyOrderBy($rows, $orderBy);
        }
        
        $limitMatches = [];
        if (preg_match('/LIMIT\s+(\d+)(?:\s*,\s*(\d+))?/i', $sql, $limitMatches)) {
            $offset = isset($limitMatches[2]) ? (int)$limitMatches[1] : 0;
            $limit = isset($limitMatches[2]) ? (int)$limitMatches[2] : (int)$limitMatches[1];
            $rows = array_slice($rows, $offset, $limit);
        }
        
        $selectMatches = [];
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $selectMatches)) {
            $selectClause = trim($selectMatches[1]);
            if ($selectClause !== '*') {
                $rows = $this->applySelectColumns($rows, $selectClause);
            }
        }
        
        return $rows;
    }

    private function joinTables($leftRows, $rightRows, $onCondition, $joinType)
    {
        $result = [];
        $parts = explode('=', $onCondition);
        if (count($parts) !== 2) {
            return $leftRows;
        }
        
        $leftCol = trim($parts[0]);
        $rightCol = trim($parts[1]);
        
        if (strpos($leftCol, '.') !== false) {
            $leftCol = explode('.', $leftCol)[1];
        }
        if (strpos($rightCol, '.') !== false) {
            $rightCol = explode('.', $rightCol)[1];
        }
        
        foreach ($leftRows as $leftRow) {
            $found = false;
            foreach ($rightRows as $rightRow) {
                if (($leftRow[$leftCol] ?? null) == ($rightRow[$rightCol] ?? null)) {
                    $result[] = array_merge($leftRow, $rightRow);
                    $found = true;
                }
            }
            if (!$found && $joinType === 'LEFT') {
                $result[] = $leftRow;
            }
        }
        
        return $result;
    }

    private function applyWhere($rows, $whereClause, $params)
    {
        $paramIndex = 0;
        $conditions = $this->parseWhereConditions($whereClause);
        
        return array_filter($rows, function ($row) use ($conditions, &$paramIndex, $params) {
            foreach ($conditions as $condition) {
                if (!$this->evaluateCondition($row, $condition, $params, $paramIndex)) {
                    return false;
                }
            }
            return true;
        });
    }

    private function parseWhereConditions($whereClause)
    {
        $conditions = [];
        $parts = preg_split('/\s+AND\s+/i', $whereClause);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            if (preg_match('/(.+?)\s*(=|>=|<=|>|<|LIKE|IN)\s*(.+)/i', $part, $matches)) {
                $conditions[] = [
                    'column' => trim($matches[1]),
                    'operator' => strtoupper(trim($matches[2])),
                    'value' => trim($matches[3]),
                ];
            } elseif (preg_match('/(.+?)\s+IS\s+(NOT\s+)?NULL/i', $part, $matches)) {
                $conditions[] = [
                    'column' => trim($matches[1]),
                    'operator' => isset($matches[2]) ? 'IS NOT NULL' : 'IS NULL',
                    'value' => null,
                ];
            }
        }
        
        return $conditions;
    }

    private function evaluateCondition($row, $condition, $params, &$paramIndex)
    {
        $column = $condition['column'];
        if (strpos($column, '.') !== false) {
            $column = explode('.', $column)[1];
        }
        
        $operator = $condition['operator'];
        $value = $condition['value'];
        
        if ($value === '?') {
            if ($paramIndex < count($params)) {
                $value = $params[$paramIndex];
                $paramIndex++;
            }
        } elseif (preg_match("/^'(.+)'$/", $value, $matches)) {
            $value = $matches[1];
        } elseif (is_numeric($value)) {
            $value = $value + 0;
        }
        
        $rowValue = $row[$column] ?? null;
        
        switch ($operator) {
            case '=':
                return $rowValue == $value;
            case '>':
                return $rowValue > $value;
            case '<':
                return $rowValue < $value;
            case '>=':
                return $rowValue >= $value;
            case '<=':
                return $rowValue <= $value;
            case 'LIKE':
                $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                return preg_match("/^$pattern$/", $rowValue);
            case 'IN':
                if (is_string($value) && preg_match('/^\((.+)\)$/', $value, $matches)) {
                    $values = array_map('trim', explode(',', $matches[1]));
                    return in_array($rowValue, $values);
                }
                return false;
            case 'IS NULL':
                return $rowValue === null || $rowValue === '';
            case 'IS NOT NULL':
                return $rowValue !== null && $rowValue !== '';
            default:
                return true;
        }
    }

    private function applyOrderBy($rows, $orderBy)
    {
        $columns = array_map('trim', explode(',', $orderBy));
        $sortColumns = [];
        
        foreach ($columns as $col) {
            $parts = preg_split('/\s+/', trim($col));
            $column = $parts[0];
            $direction = strtoupper($parts[1] ?? 'ASC');
            
            if (strpos($column, '.') !== false) {
                $column = explode('.', $column)[1];
            }
            
            $sortColumns[] = [
                'column' => $column,
                'direction' => $direction,
            ];
        }
        
        usort($rows, function ($a, $b) use ($sortColumns) {
            foreach ($sortColumns as $sort) {
                $col = $sort['column'];
                $valA = $a[$col] ?? null;
                $valB = $b[$col] ?? null;
                
                $result = 0;
                if (is_numeric($valA) && is_numeric($valB)) {
                    $result = $valA - $valB;
                } else {
                    $result = strcmp((string)$valA, (string)$valB);
                }
                
                if ($sort['direction'] === 'DESC') {
                    $result = -$result;
                }
                
                if ($result != 0) {
                    return $result;
                }
            }
            return 0;
        });
        
        return $rows;
    }

    private function applySelectColumns($rows, $selectClause)
    {
        $columns = array_map('trim', explode(',', $selectClause));
        $selectedCols = [];
        
        foreach ($columns as $col) {
            if (strpos($col, '.') !== false) {
                $col = explode('.', $col)[1];
            }
            $col = trim($col);
            
            if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $col, $matches)) {
                $selectedCols[] = [
                    'expr' => trim($matches[1]),
                    'alias' => trim($matches[2]),
                ];
            } else {
                $selectedCols[] = [
                    'expr' => $col,
                    'alias' => $col,
                ];
            }
        }
        
        $result = [];
        foreach ($rows as $row) {
            $newRow = [];
            foreach ($selectedCols as $col) {
                $expr = $col['expr'];
                $alias = $col['alias'];
                
                if (strtolower($expr) === 'count(*)' || strtolower($expr) === 'count(1)') {
                    $newRow[$alias] = count($rows);
                } elseif (strpos($expr, 'COALESCE') === 0 || strpos($expr, 'coalesce') === 0) {
                    if (preg_match('/coalesce\s*\((.+?)\s*,\s*(.+)\)/i', $expr, $matches)) {
                        $colName = trim($matches[1]);
                        if (strpos($colName, '.') !== false) {
                            $colName = explode('.', $colName)[1];
                        }
                        $defaultVal = trim($matches[2]);
                        if (is_numeric($defaultVal)) {
                            $defaultVal = $defaultVal + 0;
                        }
                        $newRow[$alias] = $row[$colName] ?? $defaultVal;
                    }
                } elseif (strtolower($expr) === 'sum(wi.quantity)') {
                    $newRow[$alias] = $row['quantity'] ?? 0;
                } elseif (strtolower($expr) === 'sum(wi.available_quantity)') {
                    $newRow[$alias] = $row['available_quantity'] ?? 0;
                } else {
                    $colName = $expr;
                    if (strpos($colName, '(') !== false) {
                        continue;
                    }
                    if (strpos($colName, '.') !== false) {
                        $colName = explode('.', $colName)[1];
                    }
                    $newRow[$alias] = $row[$colName] ?? null;
                }
            }
            $result[] = $newRow;
        }
        
        return $result;
    }

    private function executeInsert($sql, $params)
    {
        return new MockStatement([]);
    }

    private function executeUpdate($sql, $params)
    {
        $tableMatches = [];
        if (!preg_match('/UPDATE\s+(\w+)/i', $sql, $tableMatches)) {
            return new MockStatement([]);
        }
        $table = $tableMatches[1];
        
        if (!isset($this->tables[$table])) {
            return new MockStatement([]);
        }
        
        $setMatches = [];
        if (!preg_match('/SET\s+(.+?)\s+WHERE/is', $sql, $setMatches)) {
            return new MockStatement([]);
        }
        $setClause = trim($setMatches[1]);
        
        $whereMatches = [];
        $whereClause = '';
        if (preg_match('/WHERE\s+(.+)$/is', $sql, $whereMatches)) {
            $whereClause = trim($whereMatches[1]);
        }
        
        $updates = $this->parseSetClause($setClause);
        
        $paramIndex = 0;
        $affectedCount = 0;
        
        foreach ($this->tables[$table] as &$row) {
            $match = true;
            if (!empty($whereClause)) {
                $conditions = $this->parseWhereConditions($whereClause);
                $tempIndex = $paramIndex;
                foreach ($conditions as $condition) {
                    if (!$this->evaluateCondition($row, $condition, $params, $tempIndex)) {
                        $match = false;
                        break;
                    }
                }
            }
            
            if ($match) {
                foreach ($updates as $update) {
                    $col = $update['column'];
                    $value = $update['value'];
                    
                    if ($value === '?') {
                        if ($paramIndex < count($params)) {
                            $value = $params[$paramIndex];
                            $paramIndex++;
                        }
                    } elseif (preg_match("/^'(.+)'$/", $value, $matches)) {
                        $value = $matches[1];
                    } elseif (is_numeric($value)) {
                        $value = $value + 0;
                    } elseif (strpos($value, '+') !== false) {
                        $parts = array_map('trim', explode('+', $value));
                        if (count($parts) === 2) {
                            $colName = trim($parts[0]);
                            $addVal = trim($parts[1]);
                            if ($addVal === '?') {
                                if ($paramIndex < count($params)) {
                                    $addVal = $params[$paramIndex];
                                    $paramIndex++;
                                }
                            }
                            if (isset($row[$colName]) && is_numeric($addVal)) {
                                $value = $row[$colName] + $addVal;
                            }
                        }
                    } elseif (strpos($value, '-') !== false && strpos($value, '=') === false) {
                        $parts = array_map('trim', explode('-', $value));
                        if (count($parts) === 2) {
                            $colName = trim($parts[0]);
                            $subVal = trim($parts[1]);
                            if ($subVal === '?') {
                                if ($paramIndex < count($params)) {
                                    $subVal = $params[$paramIndex];
                                    $paramIndex++;
                                }
                            }
                            if (isset($row[$colName]) && is_numeric($subVal)) {
                                $value = $row[$colName] - $subVal;
                            }
                        }
                    }
                    
                    $row[$col] = $value;
                }
                $affectedCount++;
            }
        }
        
        return new MockStatement(array_fill(0, $affectedCount, ['affected' => true]));
    }

    private function executeDelete($sql, $params)
    {
        return new MockStatement([]);
    }

    private function parseSetClause($setClause)
    {
        $updates = [];
        $parts = explode(',', $setClause);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            
            if (preg_match('/(\w+)\s*=\s*(.+)/', $part, $matches)) {
                $updates[] = [
                    'column' => trim($matches[1]),
                    'value' => trim($matches[2]),
                ];
            }
        }
        
        return $updates;
    }

    public function getTableData($table)
    {
        return $this->tables[$table] ?? [];
    }

    public function setTableData($table, $data)
    {
        $this->tables[$table] = $data;
        $this->autoIncrements[$table] = count($data) + 1;
    }

    public function addRow($table, $data)
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
            $this->autoIncrements[$table] = 1;
        }
        
        if (!isset($data['id'])) {
            $data['id'] = $this->autoIncrements[$table]++;
        }
        
        $this->tables[$table][] = $data;
        return $data['id'];
    }

    public function seed($table, $data)
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
            $this->autoIncrements[$table] = 1;
        }

        foreach ($data as $row) {
            if (!isset($row['id'])) {
                $row['id'] = $this->autoIncrements[$table]++;
            } else {
                if ($row['id'] >= $this->autoIncrements[$table]) {
                    $this->autoIncrements[$table] = $row['id'] + 1;
                }
            }
            $this->tables[$table][] = $row;
        }
    }

    public function seedDefaultData()
    {
        TestDataSeeder::seedDefaultData($this);
    }
}
