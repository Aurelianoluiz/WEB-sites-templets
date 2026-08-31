<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

final class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        try {
            $sql = 'SELECT * FROM products WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function decrementStock(int $id, int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
            );
            $stmt->execute([$quantity, $id, $quantity]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function incrementStock(int $id, int $quantity): bool
    {
        if ($quantity < 1) {
            return false;
        }

        try {
            $stmt = $this->db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
            $stmt->execute([$quantity, $id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function recordStockMovement(
        int $productId,
        string $type,
        int $qty,
        string $reason,
        ?int $userId = null
    ): bool {
        if ($qty < 1 || trim($type) === '' || trim($reason) === '') {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO stock_movements (product_id, type, qty, reason, user_id) VALUES (?, ?, ?, ?, ?)'
            );
            return $stmt->execute([$productId, $type, $qty, $reason, $userId]);
        } catch (Throwable) {
            return false;
        }
    }
}
