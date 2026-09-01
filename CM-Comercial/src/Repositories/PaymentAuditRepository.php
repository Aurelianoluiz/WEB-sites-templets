<?php
declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PaymentAuditRepository implements PaymentAuditRepositoryInterface
{
    private const MAX_PAGE_SIZE = 100;
    private const MAX_EVENT_TYPE_LENGTH = 100;
    private const MAX_ACTOR_LENGTH = 100;
    private const MAX_REASON_LENGTH = 500;

    /** @var array<string,true> */
    private const SENSITIVE_KEYS = [
        'access_token' => true,
        'authorization' => true,
        'api_key' => true,
        'apikey' => true,
        'secret' => true,
        'client_secret' => true,
        'webhook_secret' => true,
        'signature' => true,
        'pix_qr_code' => true,
        'pix_qr_code_base64' => true,
        'qr_code' => true,
        'qr_code_base64' => true,
        'gateway_payload' => true,
        'raw_payload' => true,
        'webhook_payload' => true,
        'credentials' => true,
        'password' => true,
        'token' => true,
    ];

    /** @var array<string,true> */
    private const SAFE_FILTER_KEYS = [
        'event_type' => true,
        'actor' => true,
        'transaction_id' => true,
        'order_id' => true,
        'idempotency_key' => true,
        'date_from' => true,
        'date_to' => true,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function logEvent(array $data): int
    {
        $transactionId = $this->extractPositiveInt($data, ['payment_transaction_id', 'transaction_id']);
        $eventType = $this->normalizeRequiredString($data['event_type'] ?? null, self::MAX_EVENT_TYPE_LENGTH, 'event_type');
        $actor = $this->normalizeRequiredString($data['actor'] ?? 'system', self::MAX_ACTOR_LENGTH, 'actor');
        $oldStatus = $this->normalizeNullableString($data['old_status'] ?? null, 30);
        $newStatus = $this->normalizeNullableString($data['new_status'] ?? null, 30);
        $reason = $this->normalizeNullableString($data['reason'] ?? null, self::MAX_REASON_LENGTH);
        $idempotencyKey = $this->normalizeNullableString($data['idempotency_key'] ?? null, 255);
        $payload = $this->sanitizePayload($data['payload'] ?? null);

        try {
            if ($idempotencyKey !== null) {
                $existing = $this->findIdByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return $existing;
                }
            }

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
            $stmt->bindValue(':payload', $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR), $payload === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue(':idempotency_key', $idempotencyKey, $idempotencyKey === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Audit event was inserted without a valid identifier.');
            }
            return $id;
        } catch (Throwable $e) {
            if ($this->isDuplicateKeyException($e) && $idempotencyKey !== null) {
                $existing = $this->findIdByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return $existing;
                }
            }
            throw new RuntimeException('Unable to persist payment audit event.', 0, $e);
        }
    }

    public function logResolution(
        int $transactionId,
        string $actor,
        string $oldStatus,
        string $newStatus,
        string $reason,
        ?string $idempotencyKey = null
    ): bool {
        $this->assertPositiveInt($transactionId, 'transactionId');
        $actor = $this->normalizeRequiredString($actor, self::MAX_ACTOR_LENGTH, 'actor');
        $oldStatus = $this->normalizeRequiredString($oldStatus, 30, 'oldStatus');
        $newStatus = $this->normalizeRequiredString($newStatus, 30, 'newStatus');
        $reason = $this->normalizeRequiredString($reason, self::MAX_REASON_LENGTH, 'reason');
        $idempotencyKey = $this->normalizeNullableString($idempotencyKey, 255);

        $id = $this->logEvent([
            'payment_transaction_id' => $transactionId,
            'event_type' => 'divergence_resolved',
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'actor' => $actor,
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'payload' => [
                'resolution_reason' => $reason,
            ],
        ]);

        return $id > 0;
    }

    public function getHistoryByTransactionId(int $transactionId): array
    {
        $this->assertPositiveInt($transactionId, 'transactionId');
        try {
            $stmt = $this->db->prepare(
                'SELECT id, payment_transaction_id, event_type, old_status, new_status, actor, payload, created_at
                 FROM payment_audit_log
                 WHERE payment_transaction_id = :transaction_id
                 ORDER BY created_at DESC, id DESC'
            );
            $stmt->bindValue(':transaction_id', $transactionId, PDO::PARAM_INT);
            $stmt->execute();
            return $this->sanitizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to load payment audit history.', 0, $e);
        }
    }

    public function getHistoryByOrderId(int $orderId): array
    {
        $this->assertPositiveInt($orderId, 'orderId');
        try {
            $stmt = $this->db->prepare(
                'SELECT al.id, al.payment_transaction_id, al.event_type, al.old_status, al.new_status,
                        al.actor, al.payload, al.created_at
                 FROM payment_audit_log al
                 INNER JOIN payment_transactions pt ON pt.id = al.payment_transaction_id
                 WHERE pt.order_id = :order_id
                 ORDER BY al.created_at DESC, al.id DESC'
            );
            $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $stmt->execute();
            return $this->sanitizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to load order payment audit history.', 0, $e);
        }
    }

    public function listAuditLogs(array $filters, int $limit, int $offset): array
    {
        [$limit, $offset] = $this->normalizePagination($limit, $offset);
        [$where, $params] = $this->buildFilterSql($filters);

        $sql = 'SELECT al.id, al.payment_transaction_id, pt.order_id,
                       al.event_type, al.old_status, al.new_status,
                       al.actor, al.payload, al.created_at
                FROM payment_audit_log al
                INNER JOIN payment_transactions pt ON pt.id = al.payment_transaction_id';
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY al.created_at DESC, al.id DESC LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $name => $value) {
                $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $this->sanitizeRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to list payment audit logs.', 0, $e);
        }
    }

    public function isEventProcessed(string $idempotencyKey): bool
    {
        $idempotencyKey = $this->normalizeRequiredString($idempotencyKey, 255, 'idempotencyKey');
        try {
            return $this->findIdByIdempotencyKey($idempotencyKey) !== null;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to verify payment audit idempotency.', 0, $e);
        }
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Audit transaction cannot start inside another transaction.');
        }

        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $data @param list<string> $keys */
    private function extractPositiveInt(array $data, array $keys): int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            if (!is_int($data[$key]) && !ctype_digit((string)$data[$key])) {
                break;
            }
            $value = (int)$data[$key];
            if ($value > 0) {
                return $value;
            }
            break;
        }
        throw new InvalidArgumentException('Payment transaction identifier must be a positive integer.');
    }

    private function assertPositiveInt(int $value, string $field): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$field} must be a positive integer.");
        }
    }

    private function normalizeRequiredString(mixed $value, int $maxLength, string $field): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a string.");
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        return $value;
    }

    private function normalizeNullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Audit value must be a string or null.');
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $maxLength);
    }

    /** @return array<string,mixed>|null */
    private function sanitizePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Audit payload must be an array or null.');
        }

        $clean = $this->sanitizeValue($payload);
        return is_array($clean) ? $clean : null;
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $normalizedKey = is_string($key) ? strtolower(trim($key)) : (string)$key;
                if (isset(self::SENSITIVE_KEYS[$normalizedKey])) {
                    continue;
                }
                $result[$key] = $this->sanitizeValue($child);
            }
            return $result;
        }
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        return null;
    }

    /** @param array<string,scalar|null> $filters @return array{0:string,1:array<string,scalar>} */
    private function buildFilterSql(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach ($filters as $key => $value) {
            if (!isset(self::SAFE_FILTER_KEYS[$key]) || $value === null || $value === '') {
                continue;
            }
            switch ($key) {
                case 'event_type':
                    if (!is_string($value)) throw new InvalidArgumentException('Invalid event_type filter.');
                    $conditions[] = 'al.event_type = :event_type';
                    $params[':event_type'] = substr(trim($value), 0, self::MAX_EVENT_TYPE_LENGTH);
                    break;
                case 'actor':
                    if (!is_string($value)) throw new InvalidArgumentException('Invalid actor filter.');
                    $conditions[] = 'al.actor = :actor';
                    $params[':actor'] = substr(trim($value), 0, self::MAX_ACTOR_LENGTH);
                    break;
                case 'transaction_id':
                    $id = $this->filterPositiveInt($value, 'transaction_id');
                    $conditions[] = 'al.payment_transaction_id = :transaction_id';
                    $params[':transaction_id'] = $id;
                    break;
                case 'order_id':
                    $id = $this->filterPositiveInt($value, 'order_id');
                    $conditions[] = 'pt.order_id = :order_id';
                    $params[':order_id'] = $id;
                    break;
                case 'idempotency_key':
                    if (!is_string($value)) throw new InvalidArgumentException('Invalid idempotency_key filter.');
                    $conditions[] = 'al.idempotency_key = :idempotency_key';
                    $params[':idempotency_key'] = substr(trim($value), 0, 255);
                    break;
                case 'date_from':
                case 'date_to':
                    if (!is_string($value) || !$this->isValidDate($value)) {
                        throw new InvalidArgumentException("Invalid {$key} filter. Expected Y-m-d.");
                    }
                    $operator = $key === 'date_from' ? '>=' : '<=';
                    $params[$key === 'date_from' ? ':date_from' : ':date_to'] = $key === 'date_from'
                        ? $value . ' 00:00:00'
                        : $value . ' 23:59:59.999999';
                    $conditions[] = "al.created_at {$operator} " . ($key === 'date_from' ? ':date_from' : ':date_to');
                    break;
            }
        }

        if (isset($filters['date_from'], $filters['date_to'])
            && is_string($filters['date_from'])
            && is_string($filters['date_to'])
            && $filters['date_from'] > $filters['date_to']) {
            throw new InvalidArgumentException('date_from cannot be greater than date_to.');
        }

        return [implode(' AND ', $conditions), $params];
    }

    private function filterPositiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Invalid {$field} filter.");
        }
        $id = (int)$value;
        $this->assertPositiveInt($id, $field);
        return $id;
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /** @return array{0:int,1:int} */
    private function normalizePagination(int $limit, int $offset): array
    {
        if ($limit < 1 || $offset < 0) {
            throw new InvalidArgumentException('Invalid pagination.');
        }
        return [min(self::MAX_PAGE_SIZE, $limit), $offset];
    }

    private function findIdByIdempotencyKey(string $key): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM payment_audit_log WHERE idempotency_key = :idempotency_key LIMIT 1'
        );
        $stmt->bindValue(':idempotency_key', $key, PDO::PARAM_STR);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function sanitizeRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists('payload', $row) && $row['payload'] !== null) {
                $decoded = is_string($row['payload']) ? json_decode($row['payload'], true) : $row['payload'];
                $row['payload'] = is_array($decoded) ? $this->sanitizePayload($decoded) : null;
            }
            unset($row['idempotency_key']);
            $result[] = $row;
        }
        return $result;
    }

    private function isDuplicateKeyException(Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        return (int)$e->errorInfo[1] === 1062 || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
