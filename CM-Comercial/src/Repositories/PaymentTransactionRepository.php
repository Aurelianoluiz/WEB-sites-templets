<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
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

    public function applyWebhookTransition(int $id, string $providerPaymentId, string $newStatus): array
    {
        if ($id < 1 || $providerPaymentId === '' || !in_array($newStatus, self::STATUSES, true)) {
            throw new RuntimeException('Invalid webhook payment transition input.');
        }
        $payment = $this->findById($id, true);
        if ($payment === null) throw new RuntimeException('Payment transaction not found.');
        $oldStatus = (string)$payment['status'];
        $orderId = (int)($payment['order_id'] ?? 0);
        if ($orderId < 1) throw new RuntimeException('Payment transaction has no valid order.');
        if ($oldStatus !== $newStatus && !in_array($newStatus, self::ALLOWED_TRANSITIONS[$oldStatus] ?? [], true)) {
            throw new RuntimeException('Invalid payment state transition.');
        }

        if ($oldStatus !== $newStatus || (string)($payment['provider_payment_id'] ?? '') !== $providerPaymentId) {
            $stmt = $this->db->prepare(
                'UPDATE payment_transactions
                 SET provider_payment_id = :provider_payment_id, status = :status, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute([':provider_payment_id'=>$providerPaymentId, ':status'=>$newStatus, ':id'=>$id]);
        }

        if ($newStatus === 'paid' || $newStatus === 'authorized') {
            $stmt = $this->db->prepare(
                "UPDATE orders SET payment_status = :payment_status,
                    status = CASE WHEN status = 'pending' THEN 'confirmed' ELSE status END,
                    updated_at = CURRENT_TIMESTAMP WHERE id = :order_id"
            );
            $stmt->execute([':payment_status'=>$newStatus, ':order_id'=>$orderId]);
        } elseif (in_array($newStatus, ['failed','cancelled'], true)
            && !in_array($oldStatus, ['failed','cancelled','refunded'], true)) {
            $this->restoreOrderStock($orderId);
            $stmt = $this->db->prepare(
                "UPDATE orders SET payment_status = :payment_status,
                    status = CASE WHEN status <> 'delivered' THEN 'cancelled' ELSE status END,
                    updated_at = CURRENT_TIMESTAMP WHERE id = :order_id"
            );
            $stmt->execute([':payment_status'=>$newStatus, ':order_id'=>$orderId]);
        } elseif ($newStatus === 'refunded' && !in_array($oldStatus, ['cancelled','failed','refunded'], true)) {
            $this->restoreOrderStock($orderId);
            $stmt = $this->db->prepare(
                'UPDATE orders SET payment_status = :payment_status, updated_at = CURRENT_TIMESTAMP WHERE id = :order_id'
            );
            $stmt->execute([':payment_status'=>$newStatus, ':order_id'=>$orderId]);
        }

        return ['transaction_id'=>$id,'order_id'=>$orderId,'old_status'=>$oldStatus,'new_status'=>$newStatus];
    }

    private function restoreOrderStock(int $orderId): void
    {
        $items = $this->db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id');
        $items->execute([':order_id'=>$orderId]);
        $restore = $this->db->prepare('UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE id = :product_id');
        while ($item = $items->fetch(PDO::FETCH_ASSOC)) {
            $quantity = (int)($item['quantity'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            if ($quantity < 1 || $productId < 1) throw new RuntimeException('Invalid order item while restoring stock.');
            $restore->execute([':quantity'=>$quantity, ':product_id'=>$productId]);
            if ($restore->rowCount() !== 1) throw new RuntimeException('Unable to restore product stock.');
        }
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
