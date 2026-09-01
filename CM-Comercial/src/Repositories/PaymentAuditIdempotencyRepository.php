<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\IdempotencyConflictException;
use PDO;
use PDOException;
use Throwable;

/**
 * Strict concurrency boundary for webhook audit persistence.
 *
 * The UNIQUE constraint remains the final arbiter. A pre-read is only a fast
 * path; correctness comes from catching SQLSTATE 23000 / MySQL 1062 from the
 * INSERT and converting it into a domain collision so the enclosing ACID
 * transaction can roll back immediately.
 */
final class PaymentAuditIdempotencyRepository implements PaymentAuditRepositoryInterface
{
    public function __construct(
        private readonly PDO $db,
        private readonly PaymentAuditRepositoryInterface $delegate
    ) {
    }

    public function logEvent(array $data): int
    {
        $idempotencyKey = isset($data['idempotency_key']) && is_string($data['idempotency_key'])
            ? trim($data['idempotency_key'])
            : null;

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $this->delegate->logEvent($data);
        }

        if ($this->delegate->isEventProcessed($idempotencyKey)) {
            throw new IdempotencyConflictException();
        }

        $transactionId = $this->positiveInt($data['payment_transaction_id'] ?? $data['transaction_id'] ?? null);
        $eventType = $this->requiredString($data['event_type'] ?? null, 100);
        $actor = $this->requiredString($data['actor'] ?? 'system', 100);
        $oldStatus = $this->nullableString($data['old_status'] ?? null, 30);
        $newStatus = $this->nullableString($data['new_status'] ?? null, 30);
        $payload = $this->sanitize($data['payload'] ?? null);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_audit_log
                    (payment_transaction_id, event_type, old_status, new_status, actor, payload, idempotency_key)
                 VALUES
                    (:transaction_id, :event_type, :old_status, :new_status, :actor, :payload, :idempotency_key)'
            );
            $stmt->bindValue(':transaction_id', $transactionId, PDO::PARAM_INT);
            $stmt->bindValue(':event_type', $eventType, PDO::PARAM_STR);
            $stmt->bindValue(':old_status', $oldStatus, $oldStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':new_status', $newStatus, $newStatus === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':actor', $actor, PDO::PARAM_STR);
            $json = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
            $stmt->bindValue(':payload', $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':idempotency_key', $idempotencyKey, PDO::PARAM_STR);
            $stmt->execute();

            $id = (int)$this->db->lastInsertId();
            if ($id < 1) {
                throw new \RuntimeException('Audit event was inserted without a valid identifier.');
            }
            return $id;
        } catch (PDOException $e) {
            if ($this->isMySqlDuplicateEntry($e)) {
                throw new IdempotencyConflictException(previous: $e);
            }
            throw $e;
        }
    }

    public function logResolution(int $transactionId, string $actor, string $oldStatus, string $newStatus, string $reason, ?string $idempotencyKey = null): bool
    {
        return $this->delegate->logResolution($transactionId, $actor, $oldStatus, $newStatus, $reason, $idempotencyKey);
    }

    public function getHistoryByTransactionId(int $transactionId): array
    {
        return $this->delegate->getHistoryByTransactionId($transactionId);
    }

    public function getHistoryByOrderId(int $orderId): array
    {
        return $this->delegate->getHistoryByOrderId($orderId);
    }

    public function listAuditLogs(array $filters, int $limit, int $offset): array
    {
        return $this->delegate->listAuditLogs($filters, $limit, $offset);
    }

    public function isEventProcessed(string $idempotencyKey): bool
    {
        return $this->delegate->isEventProcessed($idempotencyKey);
    }

    public function transaction(callable $operation): mixed
    {
        return $this->delegate->transaction($operation);
    }

    private function isMySqlDuplicateEntry(PDOException $e): bool
    {
        $sqlState = (string)$e->getCode();
        $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        return $sqlState === '23000' && $driverCode === 1062;
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('Payment transaction identifier must be a positive integer.');
        }
        $value = (int)$value;
        if ($value < 1) {
            throw new \InvalidArgumentException('Payment transaction identifier must be a positive integer.');
        }
        return $value;
    }

    private function requiredString(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Audit value must be a string.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new \InvalidArgumentException('Invalid audit string.');
        }
        return $value;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) return null;
        if (!is_string($value)) throw new \InvalidArgumentException('Audit value must be a string or null.');
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $max);
    }

    private function sanitize(mixed $value): mixed
    {
        $blocked = ['access_token','authorization','api_key','apikey','secret','client_secret','webhook_secret','signature','pix_qr_code','pix_qr_code_base64','qr_code','qr_code_base64','gateway_payload','raw_payload','webhook_payload','credentials','password','token'];
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array(strtolower(trim($key)), $blocked, true)) continue;
                $out[$key] = $this->sanitize($child);
            }
            return $out;
        }
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null ? $value : null;
    }
}
