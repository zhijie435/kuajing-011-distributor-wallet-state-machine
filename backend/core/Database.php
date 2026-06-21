<?php

if (!class_exists("Database")) {

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $config = require __DIR__ . "/../config/config.php";
        $dbConfig = $config["db"];
        
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            $dbConfig["host"],
            $dbConfig["port"],
            $dbConfig["database"],
            $dbConfig["charset"]
        );
        
        $this->pdo = new PDO($dsn, $dbConfig["username"], $dbConfig["password"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    public function insert($table, $data)
    {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), "?");
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(", ", $fields),
            implode(", ", $placeholders)
        );
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $setParts = [];
        $params = [];
        foreach ($data as $field => $value) {
            $setParts[] = "$field = ?";
            $params[] = $value;
        }
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(", ", $setParts),
            $where
        );
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}

}
