<?php
declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $dsn = trim((string)(getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=cm_comercial;charset=utf8mb4'));
        $user = (string)(getenv('DB_USER') ?: 'cm_comercial');
        $password = (string)(getenv('DB_PASSWORD') ?: '');

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            $lockWaitTimeout = trim((string)(getenv('CM_MYSQL_LOCK_WAIT_TIMEOUT') ?: ''));
            if ($lockWaitTimeout !== '' && ctype_digit($lockWaitTimeout)) {
                $seconds = (int)$lockWaitTimeout;
                if ($seconds >= 1 && $seconds <= 50) {
                    $this->pdo->exec('SET SESSION innodb_lock_wait_timeout=' . $seconds);
                }
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    private function __clone()
    {
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('Database singleton cannot be unserialized.');
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @template T */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
