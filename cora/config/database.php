<?php
/**
 * Configuração do Banco de Dados
 * Sistema de Gestão de Boletos - Tray Sistemas
 */

class Database {
    private $host = 'localhost';
    private $database = 'traysist_cora';
    private $username = 'traysist_cora';
    private $password = 'F23QUyGSJxZAADg';
    private $charset = 'utf8mb4';
    private $connection = null;

    public function __construct() {
        $this->connect();
    }

    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];

            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            $this->logError('Database Connection Error: ' . $e->getMessage());
            throw new Exception('Erro na conexão com o banco de dados.');
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError('Database Query Error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new Exception('Erro na execução da consulta.');
        }
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function execute($sql, $params = []) {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollback() {
        return $this->connection->rollBack();
    }

    private function logError($message) {
        $logFile = __DIR__ . '/../logs/database_errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Criar diretório de logs se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Classe para configurações do sistema
class Config {
    private static $db = null;
    private static $configs = [];

    private static function getDb() {
        if (self::$db === null) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function get($key, $default = null) {
        if (!isset(self::$configs[$key])) {
            $db = self::getDb();
            $result = $db->fetch('SELECT valor FROM configuracoes WHERE chave = ?', [$key]);
            self::$configs[$key] = $result ? $result['valor'] : $default;
        }
        return self::$configs[$key];
    }

    public static function set($key, $value, $descricao = null) {
        $db = self::getDb();
        
        $sql = "INSERT INTO configuracoes (chave, valor, descricao) VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE valor = VALUES(valor), descricao = COALESCE(VALUES(descricao), descricao)";
        
        $db->execute($sql, [$key, $value, $descricao]);
        self::$configs[$key] = $value;
    }

    public static function getAll() {
        $db = self::getDb();
        $configs = $db->fetchAll('SELECT * FROM configuracoes ORDER BY chave');
        
        $result = [];
        foreach ($configs as $config) {
            $result[$config['chave']] = $config['valor'];
            self::$configs[$config['chave']] = $config['valor'];
        }
        
        return $result;
    }
}

// Classe para logs do sistema
class Logger {
    private static $db = null;

    private static function getDb() {
        if (self::$db === null) {
            self::$db = new Database();
        }
        return self::$db;
    }

    public static function log($tipo, $modulo, $mensagem, $dados_extras = null) {
        try {
            $db = self::getDb();
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
            
            $sql = "INSERT INTO logs (tipo, modulo, mensagem, dados_extras, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)";
            $db->execute($sql, [$tipo, $modulo, $mensagem, json_encode($dados_extras), $ip, $user_agent]);
            
        } catch (Exception $e) {
            error_log("Erro ao registrar log: " . $e->getMessage());
        }
    }

    public static function info($modulo, $mensagem, $dados_extras = null) {
        self::log('info', $modulo, $mensagem, $dados_extras);
    }

    public static function warning($modulo, $mensagem, $dados_extras = null) {
        self::log('warning', $modulo, $mensagem, $dados_extras);
    }

    public static function error($modulo, $mensagem, $dados_extras = null) {
        self::log('error', $modulo, $mensagem, $dados_extras);
    }

    public static function success($modulo, $mensagem, $dados_extras = null) {
        self::log('success', $modulo, $mensagem, $dados_extras);
    }
}