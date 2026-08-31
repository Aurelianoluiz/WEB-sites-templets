<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepositoryInterface;
use App\Repositories\ProductRepositoryInterface;
use PDO;
use RuntimeException;
use Throwable;

final class AdminOrderService
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly PDO $db,
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly ProductRepositoryInterface $productRepo
    ) {
    }

    public function listOrders(string $statusFilter = '', int $limit = 50, int $offset = 0): array
    {
        if ($statusFilter !== '' && !array_key_exists($statusFilter, self::TRANSITIONS)) {
            $statusFilter = '';
        }
        return $this->orderRepo->listAll($statusFilter, $limit, $offset);
    }

    public function isValidTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function changeOrderStatus(int $orderId, string $newStatus, int $actorAdminId, string $note = ''): array
    {
        try {
            if ($orderId < 1 || $actorAdminId < 1) {
                throw new RuntimeException('Dados de operação inválidos.');
            }
            if (!array_key_exists($newStatus, self::TRANSITIONS)) {
                throw new RuntimeException('Status de pedido inválido.');
            }

            $this->db->beginTransaction();
            $order = $this->orderRepo->findById($orderId, true);
            if ($order === null) {
                throw new RuntimeException('Pedido não encontrado.');
            }

            $currentStatus = (string)($order['status'] ?? '');
            if (!$this->isValidTransition($currentStatus, $newStatus)) {
                throw new RuntimeException('Transição de status inválida para o fluxo operacional.');
            }
            if ($currentStatus === $newStatus) {
                $this->db->commit();
                return ['success' => true, 'message' => 'O pedido já está neste status.'];
            }

            if ($newStatus === 'cancelled') {
                if ((string)($order['payment_status'] ?? 'pending') === 'paid') {
                    throw new RuntimeException('Pedidos pagos exigem estorno financeiro antes do cancelamento.');
                }
                $this->restoreOrderStock($orderId, $actorAdminId);
            }

            if (!$this->orderRepo->updateStatus($orderId, $newStatus, $newStatus === 'cancelled' ? 'cancelled' : null)) {
                throw new RuntimeException('Não foi possível atualizar o pedido.');
            }

            $historyNote = trim($note) !== '' ? trim($note) : 'Alteração manual via painel administrativo';
            if (!$this->orderRepo->recordStatusHistory($orderId, $currentStatus, $newStatus, $actorAdminId, $historyNote)) {
                throw new RuntimeException('Não foi possível registrar a auditoria da alteração.');
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'Status do pedido atualizado com sucesso.'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Não foi possível atualizar o pedido.'];
        }
    }

    public function cancelOrder(int $orderId, int $actorAdminId, string $note = 'Cancelamento administrativo'): array
    {
        return $this->changeOrderStatus($orderId, 'cancelled', $actorAdminId, $note);
    }

    private function restoreOrderStock(int $orderId, int $adminId): void
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
                "Estorno administrativo do pedido #{$orderId}",
                $adminId
            )) {
                throw new RuntimeException("Falha ao registrar movimentação do produto #{$productId}.");
            }
        }
    }
}
