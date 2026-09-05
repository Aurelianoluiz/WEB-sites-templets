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
    private const STATUSES=['pending','authorized','paid','failed','cancelled','refunded'];
    private const ALLOWED_TRANSITIONS=[
        'pending'=>['authorized','paid','failed','cancelled'],
        'authorized'=>['paid','failed','cancelled'],
        'paid'=>['refunded'],
        'failed'=>[], 'cancelled'=>[], 'refunded'=>[],
    ];

    public function __construct(private readonly PDO $db) {}

    private function isMysql(): bool { return strtolower((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME))==='mysql'; }
    private function lockSql(string $sql): string { return $this->isMysql() ? $sql.' FOR UPDATE' : $sql; }

    public function findById(int $id,bool $forUpdate=false): ?array
    {
        if($id<1)return null;
        $sql='SELECT p.*,o.status AS order_status,o.payment_status AS order_payment_status,o.total_amount AS order_total_amount,o.customer_id FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id WHERE p.id=:id LIMIT 1';
        try{$stmt=$this->db->prepare($forUpdate?$this->lockSql($sql):$sql);$stmt->execute([':id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}catch(Throwable $e){throw new RuntimeException('Unable to load payment transaction.',0,$e);}
    }

    public function findByExternalReference(string $externalReference,bool $forUpdate=false): ?array
    {
        $externalReference=trim($externalReference);if($externalReference==='')return null;
        $sql='SELECT p.*,o.status AS order_status,o.payment_status AS order_payment_status,o.total_amount AS order_total_amount,o.customer_id FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id WHERE p.external_reference=:external_reference ORDER BY p.id DESC LIMIT 1';
        try{$stmt=$this->db->prepare($forUpdate?$this->lockSql($sql):$sql);$stmt->execute([':external_reference'=>$externalReference]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;}catch(Throwable $e){throw new RuntimeException('Unable to resolve payment external reference.',0,$e);}
    }

    public function updateStatus(int $id,string $status): bool
    {
        if($id<1||!in_array($status,self::STATUSES,true))return false;
        try{$stmt=$this->db->prepare('UPDATE payment_transactions SET status=:status WHERE id=:id');$stmt->execute([':status'=>$status,':id'=>$id]);return $stmt->rowCount()>0;}catch(Throwable $e){throw new RuntimeException('Unable to update payment transaction status.',0,$e);}
    }

    public function applyWebhookTransition(int $id,string $providerPaymentId,string $newStatus): array
    {
        if($id<1||trim($providerPaymentId)===''||!in_array($newStatus,self::STATUSES,true))throw new InvalidWebhookTransitionException('Invalid webhook payment transition input.');
        if(!$this->db->inTransaction())throw new RuntimeException('Webhook transition must execute inside an existing ACID transaction.');
        try{
            $payment=$this->lockPaymentForWebhook($id);if($payment===null)throw new RuntimeException('Payment transaction not found.');
            $oldStatus=(string)$payment['status'];$orderId=(int)($payment['order_id']??0);if($orderId<1)throw new RuntimeException('Payment transaction has no valid order.');
            $this->assertAllowedTransition($oldStatus,$newStatus);
            $order=$this->lockOrderForWebhook($orderId);if($order===null)throw new RuntimeException('Order not found for payment transaction.');
            $orderOldStatus=(string)($order['status']??'');$this->assertOrderTransitionConsistency($orderOldStatus,$newStatus);
            if($oldStatus!==$newStatus||(string)($payment['provider_payment_id']??'')!==trim($providerPaymentId)){
                $stmt=$this->db->prepare('UPDATE payment_transactions SET provider_payment_id=:provider_payment_id,status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$stmt->execute([':provider_payment_id'=>trim($providerPaymentId),':status'=>$newStatus,':id'=>$id]);
            }
            $orderNewStatus=$orderOldStatus;
            if($newStatus==='paid'||$newStatus==='authorized'){if($orderOldStatus==='pending')$orderNewStatus='confirmed';$this->updateOrderPaymentState($orderId,$newStatus,$orderNewStatus);}
            elseif(in_array($newStatus,['failed','cancelled'],true)&&!in_array($oldStatus,['failed','cancelled','refunded'],true)){$this->restoreOrderStock($orderId,$newStatus);if($orderOldStatus!=='delivered')$orderNewStatus='cancelled';$this->updateOrderPaymentState($orderId,$newStatus,$orderNewStatus);}
            elseif($newStatus==='refunded'&&$oldStatus==='paid'){$this->restoreOrderStock($orderId,$newStatus);$this->updateOrderPaymentState($orderId,$newStatus,$orderOldStatus);}
            if($orderOldStatus!==$orderNewStatus){$stmt=$this->db->prepare('INSERT INTO order_status_history(order_id,from_status,to_status,actor_user_id,note) VALUES(:order_id,:from_status,:to_status,NULL,:note)');$stmt->execute([':order_id'=>$orderId,':from_status'=>$orderOldStatus,':to_status'=>$orderNewStatus,':note'=>'payment webhook: '.$oldStatus.' -> '.$newStatus]);}
            return ['transaction_id'=>$id,'order_id'=>$orderId,'old_status'=>$oldStatus,'new_status'=>$newStatus];
        }catch(InvalidWebhookTransitionException $e){throw $e;}catch(PDOException $e){if($this->isInnoDbConcurrencyException($e))throw new WebhookConcurrencyException('InnoDB webhook concurrency conflict; transaction must roll back.',$e);throw $e;}catch(Throwable $e){$c=$this->findInnoDbConcurrencyException($e);if($c!==null)throw new WebhookConcurrencyException('InnoDB webhook concurrency conflict; transaction must roll back.',$c);throw $e;}
    }

    private function lockPaymentForWebhook(int $id): ?array
    {
        $sql='SELECT id,order_id,status,provider_payment_id,amount FROM payment_transactions WHERE id=:id LIMIT 1';$stmt=$this->db->prepare($this->lockSql($sql));$stmt->execute([':id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    private function lockOrderForWebhook(int $orderId): ?array
    {
        $sql='SELECT id,status,payment_status,total_amount FROM orders WHERE id=:order_id LIMIT 1';$stmt=$this->db->prepare($this->lockSql($sql));$stmt->execute([':order_id'=>$orderId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }
    private function assertAllowedTransition(string $old,string $new): void
    {
        if(!in_array($old,self::STATUSES,true))throw new InvalidWebhookTransitionException('Unknown persisted payment state: '.$old);
        if($old!==$new&&!in_array($new,self::ALLOWED_TRANSITIONS[$old]??[],true))throw new InvalidWebhookTransitionException(sprintf('Illegal payment state transition: %s -> %s.',$old,$new));
    }
    private function assertOrderTransitionConsistency(string $orderStatus,string $paymentStatus): void
    {
        if($paymentStatus==='paid'&&$orderStatus==='cancelled')throw new InvalidWebhookTransitionException('Paid payment cannot regress a cancelled order.');
        if($paymentStatus==='authorized'&&in_array($orderStatus,['cancelled','delivered'],true))throw new InvalidWebhookTransitionException('Authorized payment cannot regress a terminal order.');
        if($paymentStatus==='refunded'&&$orderStatus==='cancelled')throw new InvalidWebhookTransitionException('Refund cannot rewrite a cancelled order.');
    }
    private function updateOrderPaymentState(int $orderId,string $paymentStatus,string $orderStatus): void
    {
        $stmt=$this->db->prepare('UPDATE orders SET payment_status=:payment_status,status=:status,updated_at=CURRENT_TIMESTAMP WHERE id=:order_id');$stmt->execute([':payment_status'=>$paymentStatus,':status'=>$orderStatus,':order_id'=>$orderId]);
    }
    private function restoreOrderStock(int $orderId,string $reason): void
    {
        $items=$this->db->prepare($this->lockSql('SELECT product_id,quantity FROM order_items WHERE order_id=:order_id ORDER BY id'));$items->execute([':order_id'=>$orderId]);
        $restore=$this->db->prepare('UPDATE products SET stock_quantity=stock_quantity+:quantity WHERE id=:product_id');
        while($item=$items->fetch(PDO::FETCH_ASSOC)){$quantity=(int)($item['quantity']??0);$productId=(int)($item['product_id']??0);if($quantity<1||$productId<1)throw new RuntimeException('Invalid order item while restoring stock.');$restore->execute([':quantity'=>$quantity,':product_id'=>$productId]);if($restore->rowCount()!==1)throw new RuntimeException('Unable to restore product stock.');$this->recordStockMovement($productId,$quantity,$reason);}
    }
    private function recordStockMovement(int $productId,int $quantity,string $reason): void
    {
        if(!$this->isMysql())return;
        $columns=$this->tableColumns('stock_movements');foreach(['product_id','type','qty'] as $column)if(!in_array($column,$columns,true))return;
        $stmt=$this->db->prepare('INSERT INTO stock_movements(product_id,type,qty,reason) VALUES(:product_id,:type,:qty,:reason)');$stmt->execute([':product_id'=>$productId,':type'=>'adjustment',':qty'=>$quantity,':reason'=>$reason]);
    }
    private function tableColumns(string $table): array
    {
        $stmt=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');$stmt->execute([':table'=>$table]);return array_map('strtolower',array_column($stmt->fetchAll(PDO::FETCH_ASSOC),'COLUMN_NAME'));
    }
    private function isInnoDbConcurrencyException(PDOException $e): bool
    {
        $sqlState=(string)$e->getCode();$driverCode=isset($e->errorInfo[1])?(int)$e->errorInfo[1]:0;return $sqlState==='40001'||$driverCode===1213||$driverCode===1205;
    }
    private function findInnoDbConcurrencyException(Throwable $e): ?PDOException
    {
        $current=$e;while($current!==null){if($current instanceof PDOException&&$this->isInnoDbConcurrencyException($current))return $current;$current=$current->getPrevious();}return null;
    }
    private function buildFilters(array $filters): array
    {
        $conditions=[];$params=[];$status=$filters['status']??null;if(is_string($status)&&in_array($status,self::STATUSES,true)){$conditions[]='p.status = ?';$params[]=$status;}
        $provider=$filters['provider']??null;if(is_string($provider)&&trim($provider)!==''){$conditions[]='p.provider = ?';$params[]=trim($provider);}
        $customerId=$filters['customer_id']??null;if(is_numeric($customerId)&&(int)$customerId>0){$conditions[]='o.customer_id = ?';$params[]=(int)$customerId;}
        $orderId=$filters['order_id']??null;if(is_numeric($orderId)&&(int)$orderId>0){$conditions[]='p.order_id = ?';$params[]=(int)$orderId;}
        return [$conditions,$params];
    }
    public function listWithFilters(array $filters,int $limit=50,int $offset=0): array
    {
        $limit=max(1,min(100,$limit));$offset=max(0,$offset);[$conditions,$params]=$this->buildFilters($filters);$sql='SELECT p.*,p.provider_payment_id AS transaction_id,o.status AS order_status,o.payment_status AS order_payment_status,o.total_amount AS order_total,u.name AS customer_name,u.email AS order_email FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id';if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);$sql.=' ORDER BY p.id DESC LIMIT ? OFFSET ?';$stmt=$this->db->prepare($sql);$pos=1;foreach($params as $p)$stmt->bindValue($pos++,$p,is_int($p)?PDO::PARAM_INT:PDO::PARAM_STR);$stmt->bindValue($pos++,$limit,PDO::PARAM_INT);$stmt->bindValue($pos,$offset,PDO::PARAM_INT);$stmt->execute();return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function summarize(array $filters=[]): array
    {
        [$conditions,$params]=$this->buildFilters($filters);$sql="SELECT COUNT(*) total_count,COALESCE(SUM(p.amount),0) gross_total,COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) paid_total,COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END),0) refunded_total,COALESCE(SUM(CASE WHEN p.status='pending' THEN p.amount ELSE 0 END),0) pending_total,COALESCE(SUM(CASE WHEN p.status='failed' THEN p.amount ELSE 0 END),0) failed_total,COALESCE(SUM(CASE WHEN p.status='cancelled' THEN p.amount ELSE 0 END),0) cancelled_total,COALESCE(SUM(CASE WHEN p.status='authorized' THEN p.amount ELSE 0 END),0) authorized_total FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id";if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);$stmt=$this->db->prepare($sql);$stmt->execute($params);$r=$stmt->fetch(PDO::FETCH_ASSOC)?:[];return ['count'=>(int)($r['total_count']??0),'total'=>round((float)($r['gross_total']??0),2),'paid'=>round((float)($r['paid_total']??0),2),'refunded'=>round((float)($r['refunded_total']??0),2),'pending'=>round((float)($r['pending_total']??0),2),'failed'=>round((float)($r['failed_total']??0),2),'cancelled'=>round((float)($r['cancelled_total']??0),2),'authorized'=>round((float)($r['authorized_total']??0),2)];
    }
    public function summarizeForReconciliation(array $filters=[]): array
    {
        [$conditions,$params]=$this->buildFilters($filters);$sql='SELECT COUNT(*) total_count,COALESCE(SUM(p.amount),0) total_amount,SUM(CASE WHEN o.id IS NULL THEN 0 WHEN ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))>0.01 THEN 0 WHEN p.status="paid" AND o.status="cancelled" THEN 0 WHEN o.payment_status IS NOT NULL AND o.payment_status<>p.status THEN 0 WHEN p.status IN ("pending","authorized") THEN 0 ELSE 1 END) reconciled_count,SUM(CASE WHEN o.id IS NULL THEN 1 ELSE 0 END) orphan_count,SUM(CASE WHEN o.id IS NOT NULL AND ABS(COALESCE(p.amount,0)-COALESCE(o.total_amount,0))>0.01 THEN 1 ELSE 0 END) amount_mismatch_count FROM payment_transactions p LEFT JOIN orders o ON o.id=p.order_id LEFT JOIN users u ON u.id=o.customer_id';if($conditions)$sql.=' WHERE '.implode(' AND ',$conditions);$stmt=$this->db->prepare($sql);$stmt->execute($params);$r=$stmt->fetch(PDO::FETCH_ASSOC)?:[];$total=(int)($r['total_count']??0);$orphan=(int)($r['orphan_count']??0);$amount=(int)($r['amount_mismatch_count']??0);return ['total'=>$total,'reconciled'=>max(0,$total-$orphan-$amount),'divergent'=>$amount,'pending'=>0,'inconsistent'=>$orphan,'orphan_transactions'=>$orphan,'amount_mismatches'=>$amount,'status_mismatches'=>0,'total_amount'=>round((float)($r['total_amount']??0),2)];
    }
}