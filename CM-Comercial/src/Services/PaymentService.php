<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Gateways\MercadoPagoGateway;
use InvalidArgumentException;
use RuntimeException;

final class PaymentService
{
    public function __construct(private readonly Database $database, private readonly MercadoPagoGateway $gateway)
    {
    }

    public function createPixOrder(?int $customerId, array $items, string $payerEmail, string $payerName, string $idempotencyKey, float $shippingAmount = 0.0): array
    {
        $this->validateInputs($items, $payerEmail, $idempotencyKey, $shippingAmount);
        $created = $this->database->transaction(function ($pdo) use ($customerId, $items, $payerEmail, $payerName, $idempotencyKey, $shippingAmount): array {
            $existing = $this->findOrderByIdempotency($pdo, $idempotencyKey);
            if ($existing !== null) return $this->hydrateExistingPayment($pdo, $existing['id']);

            $productRows = [];
            $subtotal = 0.0;
            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];
                $stmt = $pdo->prepare('SELECT id, sku, name, price, stock_quantity FROM products WHERE id=? AND active=1 FOR UPDATE');
                $stmt->execute([$productId]);
                $product = $stmt->fetch();
                if ($product === false) throw new RuntimeException('Product not found: ' . $productId);
                if ((int)$product['stock_quantity'] < $quantity) throw new RuntimeException('Insufficient stock for product ' . $product['sku'] . '.');
                $subtotal += (float)$product['price'] * $quantity;
                $productRows[] = [$product, $quantity];
            }

            $total = round($subtotal + $shippingAmount, 2);
            $orderStmt = $pdo->prepare('INSERT INTO orders (customer_id,status,payment_status,total_amount,currency,shipping_amount,idempotency_key) VALUES (?,\'pending\',\'pending\',?,\'BRL\',?,?)');
            $orderStmt->execute([$customerId, $total, $shippingAmount, $idempotencyKey]);
            $orderId = (int)$pdo->lastInsertId();

            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,sku,product_name,unit_price,quantity) VALUES (?,?,?,?,?,?)');
            $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity=stock_quantity-? WHERE id=? AND stock_quantity>=?');
            foreach ($productRows as [$product, $quantity]) {
                $itemStmt->execute([$orderId, (int)$product['id'], $product['sku'], $product['name'], (float)$product['price'], $quantity]);
                $stockStmt->execute([$quantity, (int)$product['id'], $quantity]);
                if ($stockStmt->rowCount() !== 1) throw new RuntimeException('Atomic stock reservation failed.');
            }

            $paymentStmt = $pdo->prepare('INSERT INTO payment_transactions (order_id,provider,external_reference,idempotency_key,status,amount,currency) VALUES (?,\'mercadopago\',?,?,\'pending\',?,\'BRL\')');
            $paymentStmt->execute([$orderId, (string)$orderId, $idempotencyKey, $total]);
            return ['order_id'=>$orderId,'payment_transaction_id'=>(int)$pdo->lastInsertId(),'amount'=>$total,'payer_email'=>$payerEmail,'payer_name'=>$payerName,'idempotency_key'=>$idempotencyKey];
        });

        if (isset($created['payment_status'])) return $created;
        try {
            $gatewayResult = $this->gateway->createPixCharge($created['order_id'], $created['amount'], $created['payer_email'], $created['payer_name'], $created['idempotency_key']);
        } catch (\Throwable $e) {
            $this->releaseReservedStockAndFailPayment($created['payment_transaction_id'], $created['order_id']);
            throw $e;
        }

        $this->database->transaction(function ($pdo) use ($created, $gatewayResult): void {
            $stmt = $pdo->prepare('UPDATE payment_transactions SET provider_payment_id=?,status=?,pix_qr_code=?,pix_qr_code_base64=?,pix_expires_at=?,gateway_payload=?,updated_at=CURRENT_TIMESTAMP(6) WHERE id=? AND status=\'pending\'');
            $stmt->execute([$gatewayResult['provider_payment_id'],$gatewayResult['status'],$gatewayResult['pix_qr_code'],$gatewayResult['pix_qr_code_base64'],$gatewayResult['pix_expires_at'],json_encode($gatewayResult['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),$created['payment_transaction_id']]);
            $orderStatus = $gatewayResult['status'] === 'paid' ? 'confirmed' : 'pending';
            $pdo->prepare('UPDATE orders SET status=?,payment_status=? WHERE id=?')->execute([$orderStatus,$gatewayResult['status'],$created['order_id']]);
            if (in_array($gatewayResult['status'], ['failed','cancelled'], true)) {
                $itemsStmt = $pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?');
                $itemsStmt->execute([$created['order_id']]);
                $restore = $pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?');
                while ($item = $itemsStmt->fetch()) $restore->execute([(int)$item['quantity'],(int)$item['product_id']]);
            }
        });

        return ['order_id'=>$created['order_id'],'payment_transaction_id'=>$created['payment_transaction_id'],'payment_status'=>$gatewayResult['status'],'provider_payment_id'=>$gatewayResult['provider_payment_id'],'pix_qr_code'=>$gatewayResult['pix_qr_code'],'pix_qr_code_base64'=>$gatewayResult['pix_qr_code_base64'],'pix_expires_at'=>$gatewayResult['pix_expires_at']];
    }

    public function orderStatus(int $customerId, int $orderId): array
    {
        if ($customerId < 1 || $orderId < 1) throw new InvalidArgumentException('Invalid customer/order id.');
        $stmt = $this->database->pdo()->prepare('SELECT pt.status,o.status AS order_status,pt.provider_payment_id,pt.pix_expires_at FROM orders o JOIN payment_transactions pt ON pt.order_id=o.id WHERE o.id=? AND o.customer_id=? ORDER BY pt.id DESC LIMIT 1');
        $stmt->execute([$orderId,$customerId]);
        $row = $stmt->fetch();
        if ($row === false) throw new RuntimeException('Order not found.');
        return ['status'=>(string)$row['status'],'order_status'=>(string)$row['order_status'],'provider_payment_id'=>$row['provider_payment_id'] !== null ? (string)$row['provider_payment_id'] : null,'pix_expires_at'=>$row['pix_expires_at'] !== null ? (string)$row['pix_expires_at'] : null];
    }

    private function validateInputs(array $items,string $email,string $idempotencyKey,float $shippingAmount): void
    {
        if ($items === []) throw new InvalidArgumentException('Cart is empty.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid payer email.');
        if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/',$idempotencyKey)) throw new InvalidArgumentException('Invalid idempotency key.');
        if (!is_finite($shippingAmount) || $shippingAmount < 0) throw new InvalidArgumentException('Invalid shipping amount.');
        foreach ($items as $item) if ((int)($item['product_id'] ?? 0) < 1 || (int)($item['quantity'] ?? 0) < 1) throw new InvalidArgumentException('Invalid cart item.');
    }

    private function findOrderByIdempotency($pdo,string $key): ?array
    {
        $stmt=$pdo->prepare('SELECT id FROM orders WHERE idempotency_key=? LIMIT 1'); $stmt->execute([$key]); $row=$stmt->fetch(); return $row===false?null:['id'=>(int)$row['id']];
    }

    private function hydrateExistingPayment($pdo,int $orderId): array
    {
        $stmt=$pdo->prepare('SELECT id,status,provider_payment_id,pix_qr_code,pix_qr_code_base64,pix_expires_at FROM payment_transactions WHERE order_id=? ORDER BY id DESC LIMIT 1'); $stmt->execute([$orderId]); $row=$stmt->fetch(); if($row===false) throw new RuntimeException('Existing order has no payment transaction.');
        return ['order_id'=>$orderId,'payment_transaction_id'=>(int)$row['id'],'payment_status'=>(string)$row['status'],'provider_payment_id'=>$row['provider_payment_id']!==null?(string)$row['provider_payment_id']:null,'pix_qr_code'=>(string)($row['pix_qr_code']??''),'pix_qr_code_base64'=>(string)($row['pix_qr_code_base64']??''),'pix_expires_at'=>$row['pix_expires_at']!==null?(string)$row['pix_expires_at']:null];
    }

    private function releaseReservedStockAndFailPayment(int $paymentTransactionId,int $orderId): void
    {
        $this->database->transaction(function($pdo) use($paymentTransactionId,$orderId): void {
            $updated=$pdo->prepare('UPDATE payment_transactions SET status=\'failed\' WHERE id=? AND status=\'pending\''); $updated->execute([$paymentTransactionId]);
            if($updated->rowCount()!==1) return;
            $items=$pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?'); $items->execute([$orderId]);
            $restore=$pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?');
            while($item=$items->fetch()) $restore->execute([(int)$item['quantity'],(int)$item['product_id']]);
            $pdo->prepare('UPDATE orders SET payment_status=\'failed\',status=\'cancelled\' WHERE id=? AND status=\'pending\'')->execute([$orderId]);
        });
    }
}
