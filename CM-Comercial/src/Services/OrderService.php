<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepositoryInterface;
use App\Repositories\ProductRepositoryInterface;
use PDO;
use RuntimeException;
use Throwable;

final class OrderService
{
    public function __construct(
        private readonly PDO $db,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly ProductRepositoryInterface $productRepo
    ) {
    }

    public function getOrderForCustomer(int $orderId, int $userId): ?array
    {
        if ($orderId < 1 || $userId < 1) {
            return null;
        }

        $order = $this->orderRepo->findByIdAndUser($orderId, $userId);
        if ($order === null) {
            return null;
        }

        $order['items'] = $this->orderRepo->findItemsByOrderId($orderId);
        return $order;
    }

    public function listCustomerOrders(int $userId, int $limit = 50, int $offset = 0): array
    {
        if ($userId < 1) {
            return [];
        }
        return $this->orderRepo->findByUserId($userId, $limit, $offset);
    }

    public function cancelByCustomer(int $orderId, int $userId): array
    {
        try {
            if ($orderId < 1 || $userId < 1) {
                throw new RuntimeException('Pedido inválido.');
            }

            $this->db->beginTransaction();
            $order = $this->orderRepo->findByIdAndUser($orderId, $userId, true);
            if ($order === null) {
                throw new RuntimeException('Pedido não encontrado.');
            }

            $status = (string)($order['status'] ?? '');
            $paymentStatus = (string)($order['payment_status'] ?? 'pending');
            if (!in_array($status, ['pending', 'confirmed'], true)) {
                throw new RuntimeException('Este pedido não pode mais ser cancelado pelo cliente.');
            }
            if ($paymentStatus === 'paid') {
                throw new RuntimeException('O pagamento já foi confirmado. Solicite o cancelamento ao atendimento.');
            }

            $this->restoreOrderStock($orderId, $userId, 'Estorno por cancelamento do pedido #');

            if (!$this->orderRepo->updateStatus($orderId, 'cancelled', 'cancelled')
                || !$this->orderRepo->recordStatusHistory($orderId, $status, 'cancelled', $userId, 'Cancelado pelo cliente')) {
                throw new RuntimeException('Não foi possível registrar o cancelamento.');
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'Pedido cancelado e estoque estornado com sucesso.'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Não foi possível cancelar o pedido.'];
        }
    }

    private function restoreOrderStock(int $orderId, int $userId, string $reasonPrefix): void
    {
        $items = $this->orderRepo->findItemsByOrderId($orderId);
        foreach ($items as $item) {
            $qty = (int)($item['qty'] ?? $item['quantity'] ?? 0);
            $productId = (int)($item['product_id'] ?? 0);
            if ($qty < 1 || $productId < 1) {
                continue;
            }
            if (!$this->productRepo->incrementStock($productId, $qty)) {
                throw new RuntimeException("Falha ao estornar estoque do produto #{$productId}.");
            }
            if (!$this->productRepo->recordStockMovement(
                $productId,
                'in',
                $qty,
                $reasonPrefix . $orderId,
                $userId
            )) {
                throw new RuntimeException("Falha ao registrar movimentação do produto #{$productId}.");
            }
        }
    }
}
