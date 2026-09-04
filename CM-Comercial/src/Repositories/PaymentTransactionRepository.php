<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\InvalidWebhookTransitionException;
use App\Exceptions\WebhookConcurrencyException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PaymentTransactionRepository implements PaymentTransactionRepositoryInterface
{
    private const STATUSES = ['pending','authorized','paid','failed','cancelled','refunded'];
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['authorized','paid','failed','cancelled'],
        'authorized' => ['paid','failed','cancelled'],
        'paid' => ['refunded'],
        'failed' => [],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function __construct(private readonly PDO $db) {}

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        if ($id < 1) return null;
        $sql = 'SELECT p.*, o.status AS order_status, o.payment_status AS order_payment_status,
                       o.total_amount AS order_total_amount, o.customer_id
                FROM payment_transactions p
                LEFT JOIN orders o ON o.id = p.order_id
                WHERE p.id = :id LIMIT 1';
        if ($forUpdate && $this->isMysql()) $sql .= ' FOR UPDATE';
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to load payment transaction.', 0, $e);
        }
    }

    public function findByExternalReference(string $externalReference, bool $forUpdate = false): ?array
    {
        $externalReference = trim($externalReference);
        if ($externalReference === '') return null;
        $sql = 'SELECT p.*, o.status AS order_status, o.payment_status AS order_payment_status,
                       o.total_amount AS order_total_amount, o.customer_id
                FROM payment_transactions p
                LEFT JOIN orders o ON o.id = p.order_id
                WHERE p.external_reference = :external_reference
                ORDER BY p.id DESC LIMIT 1';
        if ($forUpdate && $this->isMysql()) $sql .= ' FOR UPDATE';
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':external_reference', $externalReference, PDO::PARAM_STR);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to resolve payment external reference.', 0, $e);
        }
    }

    public function updateStatus(int $id, string $status): bool
    {
        if ($id < 1 || !in_array($status, self::STATUSES, true)) return false;
        try {
            $stmt = $this->db->prepare('UPDATE payment_transactions SET status = :status WHERE id = :id');
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to update payment transaction status.', 0, $e);
        }
    }

    /**
     * Applies the complete webhook state transition inside the caller's ACID
     * transaction. Lock order is always payment_transactions -> orders.
     */
    public function applyWebhookTransition(int $id, string $providerPaymentId, string $newStatus): array
    {
        if ($id < 1 || trim($providerPaymentId) === '' || !in_array($newStatus, self::STATUSES, true)) {
            throw new InvalidWebhookTransitionException('Invalid webhook payment transition input.');
        }
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Webhook transition must execute inside an existing ACID transaction.');
        }

        try {
            // LOCK ORDER CONTRACT: payment row first, then its order row.
            $payment = $this->lockPaymentForWebhook($id);
            if ($payment === null) throw new RuntimeException('Payment transaction not found.');

            $oldStatus = (string)$payment['status'];
            $orderId = (int)($payment['order_id'] ?? 0);
            if ($orderId < 1) throw new RuntimeException('Payment transaction has no valid order.');

            $this->assertAllowedTransition($oldStatus, $newStatus);

            // The order is deliberately locked before ANY order or stock mutation.
            $order = $this->lockOrderForWebhook($orderId);
            if ($order === null) throw new RuntimeException('Order not found for payment transaction.');

            $orderOldStatus = (string)($order['status'] ?? '');
            $this->assertOrderTransitionConsistency($orderOldStatus, $newStatus);

            if ($oldStatus !== $newStatus || (string)($payment['provider_payment_id'] ?? '') !== trim($providerPaymentId)) {
                $stmt = $this->db->prepare(
                    'UPDATE payment_transactions
                     SET provider_payment_id = :provider_payment_id, status = :status, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':provider_payment_id' => trim($providerPaymentId),
                    ':status' => $newStatus,
                    ':id' => $id,
                ]);
            }

            $orderNewStatus = $orderOldStatus;
            if ($newStatus === 'paid' || $newStatus === 'authorized') {
                if ($orderOldStatus === 'pending') $orderNewStatus = 'confirmed';
                $this->updateOrderPaymentState($orderId, $newStatus, $orderNewStatus);
            } elseif (in_array($newStatus, ['failed','cancelled'], true)
                && !in_array($oldStatus, ['failed','cancelled','refunded'], true)) {
                $this->restoreOrderStock($orderId, $id, $newStatus);
                if ($orderOldStatus !== 'delivered') $orderNewStatus = 'cancelled';
                $this->updateOrderPaymentState($orderId, $newStatus, $orderNewStatus);
            } elseif ($newStatus === 'refunded' && $oldStatus === 'paid') {
                $this->restoreOrderStock($orderId, $id, $newStatus);
                $this->updateOrderPaymentState($orderId, $newStatus, $orderOldStatus);
            }

            if ($orderOldStatus !== $orderNewStatus) {
                $history = $this->db->prepare(
                    'INSERT INTO order_status_history (order_id, from_status, to_status, actor_user_id, note)
                     VALUES (:order_id, :from_status, :to_status, NULL, :note)'
                );
                $history->execute([
                    ':order_id' => $orderId,
                    ':from_status' => $orderOldStatus,
                    ':to_status' => $orderNewStatus,
                    ':note' => 'payment webhook: ' . $oldStatus . ' -> ' . $newStatus,
                ]);
            }

            return [
                'transaction_id' => $id,
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ];
        } catch (InvalidWebhookTransitionException $e) {
            throw $e;
        } catch (PDOException $e) {
            if ($this->isInnoDbConcurrencyException($e)) {
                // Do not retry here. The enclosing transaction boundary must roll back.
                throw new WebhookConcurrencyException('InnoDB webhook concurrency conflict; transaction must roll back.', $e);
            }
            throw $e;
        } catch (Throwable $e) {
            $concurrency = $this->findInnoDbConcurrencyException($e);
            if ($concurrency !== null) {
                throw new WebhookConcurrencyException('InnoDB webhook concurrency conflict; transaction must roll back.', $concurrency);
            }
            throw $e;
        }
    }

    private function lockPaymentForWebhook(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, order_id, status, provider_payment_id, amount
             FROM payment_transactions
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lockOrderForWebhook(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, status, payment_status, total_amount
             FROM orders
             WHERE id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([':order_id' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertAllowedTransition(string $oldStatus, string $newStatus): void
    {
        if (!in_array($oldStatus, self::STATUSES, true)) {
            throw new InvalidWebhookTransitionException('Unknown persisted payment state: ' . $oldStatus);
        }
        if ($oldStatus === $newStatus) return;
        if (!in_array($newStatus, self::ALLOWED_TRANSITIONS[$oldStatus] ?? [], true)) {
            throw new InvalidWebhookTransitionException(
                sprintf('Illegal payment state transition: %s -> %s.', $oldStatus, $newStatus)
            );
        }
    }

    private function assertOrderTransitionConsistency(string $orderStatus, string $paymentStatus): void
    {
        if ($paymentStatus === 'paid' && $orderStatus === 'cancelled') {
            throw new InvalidWebhookTransitionException('Paid payment cannot regress a cancelled order.');
        }
        if ($paymentStatus === 'authorized' && in_array($orderStatus, ['cancelled','delivered'], true)) {
            throw new InvalidWebhookTransitionException('Authorized payment cannot regress a terminal order.');
        }
        if ($paymentStatus === 'refunded' && $orderStatus === 'cancelled') {
            throw new InvalidWebhookTransitionException('Refund cannot rewrite a cancelled order.');
        }
    }

    private function updateOrderPaymentState(int $orderId, string $paymentStatus, string $orderStatus): void
    {
        $stmt = $this->db->prepare(
            'UPDATE orders
             SET payment_status = :payment_status, status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :order_id'
        );
        $stmt->execute([
            ':payment_status' => $paymentStatus,
            ':status' => $orderStatus,
            ':order_id' => $orderId,
        ]);
    }

    private function restoreOrderStock(int $orderId, int $paymentTransactionId, string $reason): void
    {
        $items = $this->db->prepare(
            'SELECT product_id, quantity FROM order_items WHERE order_id = :order_id ORDER BY id FOR UPDATE'
        );
        $items->execute([':order_id' => $orderId]);
        $restore = $this->db->prepare(
            'UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE id = :product_id'
        );

        while ($item = $items->fetch(PDO::FETCH_ASSOC)) {
            $quantity = (int)($item['quantity'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            if ($quantity < 1 || $productId < 1) {
                throw new RuntimeException('Invalid order item while restoring stock.');
            }
            $restore->execute([':quantity' => $quantity, ':product_id' => $productId]);
            if ($restore->rowCount() !== 1) {
                throw new RuntimeException('Unable to restore product stock.');
            }
            $this->recordStockMovementIfSchemaSupportsIt($orderId, $paymentTransactionId, $productId, $quantity, $reason);
        }
    }

    /**
     * Keeps stock_movements in the same transaction without assuming a single
     * deployment-specific schema. Only known canonical columns are populated.
     */
    private function recordStockMovementIfSchemaSupportsIt(int $orderId, int $paymentTransactionId, int $productId, int $quantity, string $reason): void
    {
        if (!$this->isMysql()) return;
        $columns = $this->tableColumns('stock_movements');
        $required = ['product_id', 'quantity'];
        foreach ($required as $column) {
            if (!in_array($column, $columns, true)) return;
        }

        $fields = ['product_id', 'quantity'];
        $values = [':product_id', ':quantity'];
        $params = [':product_id' => $productId, ':quantity' => $quantity];
        if (in_array('order_id', $columns, true)) { $fields[] = 'order_id'; $values[] = ':order_id'; $params[':order_id'] = $orderId; }
        if (in_array('payment_transaction_id', $columns, true)) { $fields[] = 'payment_transaction_id'; $values[] = ':payment_transaction_id'; $params[':payment_transaction_id'] = $paymentTransactionId; }
        if (in_array('type', $columns, true)) { $fields[] = 'type'; $values[] = ':type'; $params[':type'] = 'restore'; }
        if (in_array('movement_type', $columns, true)) { $fields[] = 'movement_type'; $values[] = ':movement_type'; $params[':movement_type'] = 'restore'; }
        if (in_array('reason', $columns, true)) { $fields[] = 'reason'; $values[] = ':reason'; $params[':reason'] = $reason; }
        if (in_array('reference', $columns, true)) { $fields[] = 'reference'; $values[] = ':reference'; $params[':reference'] = 'webhook:' . $paymentTransactionId . ':' . $productId . ':' . $reason; }

        $sql = 'INSERT INTO stock_movements (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function tableColumns(string $table): array
    {
        $stmt = $this->db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute([':table' => $table]);
        return array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
    }

    private function isInnoDbConcurrencyException(PDOException $e): bool
    {
        $sqlState = (string)$e->getCode();
        $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        return $sqlState === '40001' || $driverCode === 1213 || $driverCode === 1205;
    }

    private function findInnoDbConcurrencyException(Throwable $e): ?PDOException
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof PDOException && $this->isInnoDbConcurrencyException($current)) {
                return $current;
            }
            $current = $current->getPrevious();
        }
        return null;
    }

    private function buildFilters(array $filters): array
    {
        $conditions=[]; $params=[];
        $status=$filters['status']??null;
        if (is_string($status) && in_array($status,self::STATUSES,true)) { $conditions[]='p.status = ?'; $params[]=$status; }
        $provider=$filters['provider']??null;
        if (is_string($provider) && trim($provider)!=='') { $conditions[]='p.provider = ?'; $params[]=trim($provider); }
        $customerId=$filters['customer_id']??null;
        if (is_numeric($customerId) && (int)$customerId>0) { $conditions[]='o.customer_id = ?'; $params[]=(int)$customerId; }
        $orderId=$filters['order_id']??null;
        if (is_numeric($orderId) && (int)$orderId>0) { $conditions[]='p.order_id = ?'; $params[]=(int)$orderId; }
        $dateFrom=$this->normalizeDate($filters['date_from']??null,false);
        if ($dateFrom!==null) { $conditions[]='p.created_at >= ?'; $params[]=$dateFrom; }
        $dateTo=$this->normalizeDate($filters['date_to']??null,true);
        if ($dateTo!==null) { $conditions[]='p.created_at <= ?'; $params[]=$dateTo; }
        $search=$filters['search']??null;
        if (is_string($search) && trim($search)!=='') {
            $term='%'.trim($search).'%';
            $conditions[]='(CAST(p.id AS CHAR) LIKE ? OR CAST(p.order_id AS CHAR) LIKE ? OR p.provider_payment_id LIKE ? OR p.external_reference LIKE ? OR u.email LIKE ?)';
            array_push($params,$term,$term,$term,$term,$term);
        }
        return [$conditions,$params];
    }

    public function listWithFilters(array $filters, int $limit=50, int $offset=0): array
    {
        $limit=max(1,min(100,$limit)); $offset=max(0,$offset); [$conditions,$params]=$this->buildFilters($filters);
        $sql='SELECT p.*, p.provider_payment_id AS transaction_id, o.status AS order_status,
                     o.payment_status AS order_payment_status, o.total_amount AS order_total,
                     u.name AS customer_name, u.email AS order_email
              FROM payment_transactions p
              LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id';
        if ($conditions!==[]) $sql.=' WHERE '.implode(' AND ',$conditions);
        $sql.=' ORDER BY p.id DESC LIMIT ? OFFSET ?';
        try {
            $stmt=$this->db->prepare($sql); $position=1;
            foreach($params as $param) $stmt->bindValue($position++,$param,is_int($param)?PDO::PARAM_INT:PDO::PARAM_STR);
            $stmt->bindValue($position++,$limit,PDO::PARAM_INT); $stmt->bindValue($position,$offset,PDO::PARAM_INT); $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        } catch(Throwable $e) { throw new RuntimeException('Unable to list payment transactions.',0,$e); }
    }

    public function summarize(array $filters=[]): array
    {
        [$conditions,$params]=$this->buildFilters($filters);
        $sql="SELECT COUNT(*) AS total_count,
                     COALESCE(SUM(p.amount),0) AS gross_total,
                     COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) AS paid_total,
                     COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END),0) AS refunded_total,
                     COALESCE(SUM(CASE WHEN p.status='pending' THEN p.amount ELSE 0 END),0) AS pending_total,
                     COALESCE(SUM(CASE WHEN p.status='failed' THEN p.amount ELSE 0 END),0) AS failed_total,
                     COALESCE(SUM(CASE WHEN p.status='cancelled' THEN p.amount ELSE 0 END),0) AS cancelled_total,
                     COALESCE(SUM(CASE WHEN p.status='authorized' THEN p.amount ELSE 0 END),0) AS authorized_total
              FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id";
        if($conditions!==[]) $sql.=' WHERE '.implode(' AND ',$conditions);
        try {
            $stmt=$this->db->prepare($sql); foreach($params as $index=>$param) $stmt->bindValue($index+1,$param,is_int($param)?PDO::PARAM_INT:PDO::PARAM_STR); $stmt->execute(); $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
            return ['count'=>(int)($row['total_count']??0),'total'=>round((float)($row['gross_total']??0),2),'paid'=>round((float)($row['paid_total']??0),2),'refunded'=>round((float)($row['refunded_total']??0),2),'pending'=>round((float)($row['pending_total']??0),2),'failed'=>round((float)($row['failed_total']??0),2),'cancelled'=>round((float)($row['cancelled_total']??0),2),'authorized'=>round((float)($row['authorized_total']??0),2)];
        } catch(Throwable $e) { throw new RuntimeException('Unable to summarize payment transactions.',0,$e); }
    }

    public function summarizeForReconciliation(array $filters=[]): array
    {
        [$conditions,$params]=$this->buildFilters($filters);
        $sql="SELECT COUNT(*) AS total_count, COALESCE(SUM(p.amount),0) AS total_amount,
                    SUM(CASE WHEN o.id IS NULL THEN 0 WHEN ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))>0.01 THEN 0
                        WHEN p.status='paid' AND o.status='cancelled' THEN 0
                        WHEN o.payment_status IS NOT NULL AND o.payment_status<>p.status
                             AND NOT (p.status='authorized' AND o.payment_status IN ('authorized','pending'))
                             AND NOT (p.status='cancelled' AND o.payment_status IN ('cancelled','failed')) THEN 0
                        WHEN p.status IN ('pending','authorized') THEN 0 ELSE 1 END) AS reconciled_count,
                    SUM(CASE WHEN o.id IS NOT NULL AND ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))>0.01 THEN 1 ELSE 0 END) AS amount_mismatch_count,
                    SUM(CASE WHEN o.id IS NOT NULL AND ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))<=0.01 AND
                        ((p.status='paid' AND o.status='cancelled') OR (o.payment_status IS NOT NULL AND o.payment_status<>p.status
                         AND NOT (p.status='authorized' AND o.payment_status IN ('authorized','pending'))
                         AND NOT (p.status='cancelled' AND o.payment_status IN ('cancelled','failed')))) THEN 1 ELSE 0 END) AS status_mismatch_count,
                    SUM(CASE WHEN o.id IS NULL THEN 1 ELSE 0 END) AS orphan_count,
                    SUM(CASE WHEN o.id IS NOT NULL AND ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))<=0.01
                         AND o.payment_status IS NOT NULL AND p.status IN ('pending','authorized')
                         AND o.payment_status IN ('pending','authorized') THEN 1 ELSE 0 END) AS pending_count
              FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id";
        if($conditions!==[]) $sql.=' WHERE '.implode(' AND ',$conditions);
        try {
            $stmt=$this->db->prepare($sql); foreach($params as $index=>$param) $stmt->bindValue($index+1,$param,is_int($param)?PDO::PARAM_INT:PDO::PARAM_STR); $stmt->execute(); $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
            $total=(int)($row['total_count']??0); $orphan=(int)($row['orphan_count']??0); $amountMismatch=(int)($row['amount_mismatch_count']??0); $statusMismatch=(int)($row['status_mismatch_count']??0); $pending=(int)($row['pending_count']??0);
            return ['total'=>$total,'reconciled'=>max(0,$total-$orphan-$amountMismatch-$statusMismatch-$pending),'divergent'=>$amountMismatch+$statusMismatch,'pending'=>$pending,'inconsistent'=>$orphan,'orphan_transactions'=>$orphan,'amount_mismatches'=>$amountMismatch,'status_mismatches'=>$statusMismatch,'total_amount'=>round((float)($row['total_amount']??0),2)];
        } catch(Throwable $e) { throw new RuntimeException('Unable to summarize reconciliation candidates.',0,$e); }
    }

    private function normalizeDate(mixed $value,bool $endOfDay): ?string
    {
        if(!is_string($value)||trim($value)==='') return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',trim($value)); $errors=\DateTimeImmutable::getLastErrors();
        if($date===false||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))) return null;
        if($endOfDay) $date=$date->setTime(23,59,59); return $date->format('Y-m-d H:i:s');
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME))==='mysql';
    }
}
